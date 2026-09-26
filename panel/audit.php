<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$limit = (int) ($_GET['limit'] ?? 100);
if (!in_array($limit, [50, 100, 200, 500], true)) {
    $limit = 100;
}

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
$eventFilter = mb_substr(trim((string) ($_GET['event'] ?? '')), 0, 120, 'UTF-8');

$sourceEvents = bluebotReadAuditLog(($query !== '' || $eventFilter !== '') ? 500 : $limit);
$eventOptionsSource = bluebotReadAuditLog(500);
$eventOptions = array_values(array_unique(array_filter(array_map(
    static fn(array $event): string => trim((string) ($event['event'] ?? '')),
    $eventOptionsSource
))));
sort($eventOptions, SORT_NATURAL | SORT_FLAG_CASE);

$events = array_values(array_filter($sourceEvents, static function (array $event) use ($query, $eventFilter): bool {
    $eventName = (string) ($event['event'] ?? '');
    if ($eventFilter !== '' && $eventName !== $eventFilter) {
        return false;
    }

    if ($query === '') {
        return true;
    }

    $context = json_encode(
        $event['context'] ?? [],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '';

    return mb_stripos($eventName . ' ' . $context, $query, 0, 'UTF-8') !== false;
}));
$events = array_slice($events, 0, $limit);

$exportParams = [
    'q' => $query,
    'event' => $eventFilter,
    'limit' => $limit,
    'export' => 'csv',
];
$exportUrl = 'audit.php?' . http_build_query($exportParams);

if (($_GET['export'] ?? '') === 'csv') {
    bluebotAudit('admin.audit_export', [
        'admin' => (string) ($_SESSION['admin_user'] ?? 'unknown'),
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        'query' => $query,
        'event_filter' => $eventFilter,
        'limit' => $limit,
        'exported_rows' => count($events),
    ]);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bluebot-audit-' . date('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit;
    }

    // UTF-8 BOM keeps Persian/Russian/Chinese readable in spreadsheet apps.
    fwrite($output, "\xEF\xBB\xBF");

    $csvSafe = static function ($value): string {
        $value = (string) $value;
        if (preg_match('/^[=+\\-@]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    };

    fputcsv($output, ['time', 'event', 'context']);
    foreach ($events as $event) {
        $contextJson = json_encode(
            $event['context'] ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';

        fputcsv($output, [
            $csvSafe($event['time'] ?? ''),
            $csvSafe($event['event'] ?? ''),
            $csvSafe($contextJson),
        ]);
    }

    fclose($output);
    exit;
}

$pageTitle = $textbotlang['panel']['auditTitle'];
$pageLede = $textbotlang['panel']['auditSubtitle'];
$activeNav = 'audit';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
  <div class="card-head">
    <div>
      <div class="card-title"><?= htmlspecialchars($textbotlang['panel']['auditTitle']) ?></div>
      <div class="card-subtitle"><?= htmlspecialchars($textbotlang['panel']['auditLimit']) ?>: <?= number_format($limit) ?></div>
    </div>
    <form method="get" class="toolbar-end" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select name="event" class="select" style="width:auto">
        <option value=""><?= htmlspecialchars($textbotlang['panel']['auditAllEvents']) ?></option>
        <?php foreach ($eventOptions as $eventName): ?>
          <option value="<?= htmlspecialchars($eventName) ?>" <?= $eventFilter === $eventName ? 'selected' : '' ?>>
            <?= htmlspecialchars($eventName) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="search-box" style="min-width:220px">
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>"
          placeholder="<?= htmlspecialchars($textbotlang['panel']['auditSearchPlaceholder']) ?>">
      </div>
      <select name="limit" class="select" style="width:auto">
        <?php foreach ([50, 100, 200, 500] as $option): ?>
          <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="search-btn"><?= htmlspecialchars($textbotlang['panel']['auditFilter']) ?></button>
      <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-link"><?= htmlspecialchars($textbotlang['panel']['auditExport']) ?></a>
      <a href="audit.php?limit=<?= $limit ?>" class="btn-link"><?= htmlspecialchars($textbotlang['panel']['auditRefresh']) ?></a>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th><?= htmlspecialchars($textbotlang['panel']['auditTime']) ?></th>
          <th><?= htmlspecialchars($textbotlang['panel']['auditEvent']) ?></th>
          <th><?= htmlspecialchars($textbotlang['panel']['auditContext']) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($events === []): ?>
          <tr>
            <td colspan="3">
              <div class="empty">
                <div class="empty-mark">—</div>
                <p><?= htmlspecialchars($textbotlang['panel']['auditEmpty']) ?></p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($events as $event): ?>
            <?php
              $contextJson = json_encode(
                  $event['context'],
                  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
              ) ?: '{}';
            ?>
            <tr>
              <td class="cell-mono" style="white-space:nowrap"><?= htmlspecialchars($event['time']) ?></td>
              <td><span class="tag tag-plain"><?= htmlspecialchars($event['event']) ?></span></td>
              <td class="cell-mono" style="max-width:620px;white-space:normal;word-break:break-word">
                <?= htmlspecialchars($contextJson) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
