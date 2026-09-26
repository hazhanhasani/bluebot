<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$limit = (int) ($_GET['limit'] ?? 100);
if (!in_array($limit, [50, 100, 200, 500], true)) {
    $limit = 100;
}

$events = bluebotReadAuditLog($limit);

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
    <div style="display:flex;gap:8px;align-items:center">
      <form method="get">
        <select name="limit" class="select" onchange="this.form.submit()">
          <?php foreach ([50, 100, 200, 500] as $option): ?>
            <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a href="audit.php?limit=<?= $limit ?>" class="btn-link"><?= htmlspecialchars($textbotlang['panel']['auditRefresh']) ?></a>
    </div>
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
