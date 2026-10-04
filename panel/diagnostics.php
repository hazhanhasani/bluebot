<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../src/Support/Diagnostics.php';
require_auth();

$generatedApiToken = null;
$apiTokenError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_api_token') {
    csrf_check_post();

    try {
        $_SESSION['diagnostics_api_token_once'] = bluebotGenerateDedicatedApiToken();
    } catch (Throwable $error) {
        bluebotLog('error', 'Web diagnostics API token generation failed', [
            'exception' => get_class($error),
            'reason' => $error->getMessage(),
        ]);
        $_SESSION['diagnostics_api_token_error'] = 'ساخت توکن مستقل API ناموفق بود. دسترسی نوشتن پوشه api را بررسی کنید.';
    }

    header('Location: diagnostics.php');
    exit;
}

if (!empty($_SESSION['diagnostics_api_token_once'])) {
    $generatedApiToken = (string) $_SESSION['diagnostics_api_token_once'];
    unset($_SESSION['diagnostics_api_token_once']);
}
if (!empty($_SESSION['diagnostics_api_token_error'])) {
    $apiTokenError = (string) $_SESSION['diagnostics_api_token_error'];
    unset($_SESSION['diagnostics_api_token_error']);
}

$diagnostics = bluebotCollectDiagnostics($pdo, is_array($setting ?? null) ? $setting : (select("setting", "*") ?: []));
bluebotRecordHealthSnapshot($diagnostics);
$healthHistory = bluebotReadHealthHistory(20);
$formatDeliveryCount = static fn(int $count): string => $count < 0
    ? htmlspecialchars((string) ($textbotlang['common']['labels']['unknown'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    : number_format($count);

$pageTitle = $textbotlang['panel']['diagnosticsTitle'];
$pageLede = $textbotlang['panel']['diagnosticsSubtitle'];
$activeNav = 'diagnostics';

$healthItems = [
    [
        'label' => $textbotlang['panel']['diagnosticsDatabase'],
        'value' => (bool) $diagnostics['database_ok'],
        'type' => 'bool',
    ],
    [
        'label' => $textbotlang['panel']['diagnosticsStorage'],
        'value' => (bool) $diagnostics['storage_writable'],
        'type' => 'bool',
    ],
    [
        'label' => $textbotlang['panel']['diagnosticsVendor'],
        'value' => (bool) $diagnostics['vendor_ready'],
        'type' => 'bool',
    ],
    [
        'label' => $textbotlang['panel']['diagnosticsInstaller'],
        'value' => (bool) $diagnostics['installer_removed'],
        'type' => 'bool',
    ],
    [
        'label' => $textbotlang['panel']['diagnosticsWebhook'],
        'value' => (bool) $diagnostics['webhook_protected'],
        'type' => 'bool',
    ],
    [
        'label' => $textbotlang['panel']['diagnosticsApiToken'],
        'value' => (bool) $diagnostics['api_token_configured'],
        'type' => 'bool',
    ],
];

include __DIR__ . '/inc/layout_head.php';
?>

<div class="stats fade-up" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:24px">
  <div class="stat ok">
    <div class="stat-label"><?= htmlspecialchars($textbotlang['panel']['diagnosticsVersion']) ?></div>
    <div class="stat-num" style="font-size:1.35rem"><?= htmlspecialchars((string) $diagnostics['version']) ?></div>
    <div class="stat-meta">BlueBot</div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= htmlspecialchars($textbotlang['panel']['diagnosticsMiniVersion']) ?></div>
    <div class="stat-num" style="font-size:1.35rem"><?= htmlspecialchars((string) $diagnostics['mini_version']) ?></div>
    <div class="stat-meta">Mini App</div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= htmlspecialchars($textbotlang['panel']['diagnosticsPhp']) ?></div>
    <div class="stat-num" style="font-size:1.35rem"><?= htmlspecialchars((string) $diagnostics['php_version']) ?></div>
    <div class="stat-meta">Runtime</div>
  </div>
  <div class="stat <?= ((int) $diagnostics['delivery_errors']) !== 0 ? 'no' : 'ok' ?>">
    <div class="stat-label"><?= htmlspecialchars($textbotlang['panel']['diagnosticsDeliveryErrors']) ?></div>
    <div class="stat-num"><?= $formatDeliveryCount((int) $diagnostics['delivery_errors']) ?></div>
    <div class="stat-meta"><?= ((int) $diagnostics['delivery_errors']) !== 0
      ? htmlspecialchars($textbotlang['panel']['diagnosticsProblem'])
      : htmlspecialchars($textbotlang['panel']['diagnosticsHealthy']) ?></div>
  </div>
</div>

<?php if ($generatedApiToken !== null): ?>
  <div class="card fade-up" style="margin-bottom:24px;border-color:rgba(52,211,153,.38)">
    <div class="card-head">
      <div>
        <div class="card-title">✅ توکن مستقل API ساخته شد</div>
        <div class="card-subtitle">این مقدار فقط همین یک‌بار نمایش داده می‌شود. آن را در محل امن ذخیره کنید.</div>
      </div>
    </div>
    <div class="cell-mono" dir="ltr" style="overflow-wrap:anywhere;padding:14px;border-radius:12px;background:rgba(15,23,42,.35)">
      <?= htmlspecialchars($generatedApiToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
  </div>
<?php elseif ($apiTokenError !== null): ?>
  <div class="card fade-up" style="margin-bottom:24px;border-color:rgba(248,113,113,.38)">
    <div class="card-title">❌ <?= htmlspecialchars($apiTokenError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  </div>
<?php endif; ?>

<div class="two-col">
  <div class="card fade-up d1">
    <div class="card-head">
      <div>
        <div class="card-title"><?= htmlspecialchars($textbotlang['panel']['diagnosticsTitle']) ?></div>
        <div class="card-subtitle"><?= htmlspecialchars($textbotlang['panel']['diagnosticsCheckedAt']) ?>:
          <?= htmlspecialchars((string) $diagnostics['time']) ?></div>
      </div>
      <a href="diagnostics.php" class="btn-link"><?= htmlspecialchars($textbotlang['panel']['diagnosticsRefresh']) ?></a>
    </div>

    <div class="tbl-wrap">
      <table class="tbl-sm">
        <tbody>
          <?php foreach ($healthItems as $item): ?>
            <?php $ok = (bool) $item['value']; ?>
            <tr>
              <td class="cell-strong"><?= htmlspecialchars((string) $item['label']) ?></td>
              <td style="text-align:end">
                <span class="tag <?= $ok ? 'tag-ok' : 'tag-no' ?>">
                  <?= htmlspecialchars($ok
                    ? $textbotlang['panel']['diagnosticsHealthy']
                    : $textbotlang['panel']['diagnosticsProblem']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!(bool) $diagnostics['api_token_configured']): ?>
      <div style="padding:16px 18px;border-top:1px solid rgba(148,163,184,.16)">
        <p style="margin:0 0 12px;line-height:1.9">
          توکن مستقل API تنظیم نشده است؛ به همین دلیل «امنیت» و «وضعیت کلی» نیاز به بررسی نشان داده می‌شوند.
        </p>
        <form method="post" action="diagnostics.php">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="generate_api_token">
          <button type="submit" class="btn-link">🔐 ایجاد توکن مستقل API</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="card fade-up d2">
    <div class="card-head">
      <div>
        <div class="card-title"><?= htmlspecialchars($textbotlang['panel']['diagnosticsBotStatus']) ?></div>
        <div class="card-subtitle"><?= htmlspecialchars($textbotlang['panel']['diagnosticsSubtitle']) ?></div>
      </div>
    </div>

    <div class="tbl-wrap">
      <table class="tbl-sm">
        <tbody>
          <tr>
            <td><?= htmlspecialchars($textbotlang['panel']['diagnosticsBotStatus']) ?></td>
            <td class="cell-mono" style="text-align:end"><?= htmlspecialchars((string) $diagnostics['bot_status']) ?></td>
          </tr>
          <tr>
            <td><?= htmlspecialchars($textbotlang['panel']['diagnosticsFreeDisk']) ?></td>
            <td class="cell-mono" style="text-align:end"><?= htmlspecialchars((string) $diagnostics['free_disk']) ?></td>
          </tr>
          <tr>
            <td><?= htmlspecialchars($textbotlang['panel']['diagnosticsReviewed']) ?></td>
            <td style="text-align:end">
              <span class="tag tag-plain"><?= $formatDeliveryCount((int) $diagnostics['delivery_reviewed']) ?></span>
            </td>
          </tr>
          <tr>
            <td><?= htmlspecialchars($textbotlang['panel']['diagnosticsDeliveryErrors']) ?></td>
            <td style="text-align:end">
              <?php if ((int) $diagnostics['delivery_errors'] > 0): ?>
                <a class="tag tag-no" href="payment.php?status=delivery_error">
                  <?= number_format((int) $diagnostics['delivery_errors']) ?>
                </a>
              <?php elseif ((int) $diagnostics['delivery_errors'] < 0): ?>
                <span class="tag tag-warn"><?= $formatDeliveryCount((int) $diagnostics['delivery_errors']) ?></span>
              <?php else: ?>
                <span class="tag tag-ok">0</span>
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card fade-up" style="margin-top:24px">
  <div class="card-head">
    <div>
      <div class="card-title"><?= htmlspecialchars($textbotlang['panel']['diagnosticsHistory']) ?></div>
      <div class="card-subtitle"><?= htmlspecialchars($textbotlang['panel']['diagnosticsHistorySubtitle']) ?></div>
    </div>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th><?= htmlspecialchars($textbotlang['panel']['diagnosticsCheckedAt']) ?></th>
          <th><?= htmlspecialchars($textbotlang['panel']['diagnosticsOverall']) ?></th>
          <th><?= htmlspecialchars($textbotlang['panel']['diagnosticsDatabase']) ?></th>
          <th><?= htmlspecialchars($textbotlang['panel']['diagnosticsSecurity']) ?></th>
          <th><?= htmlspecialchars($textbotlang['panel']['diagnosticsDeliveryErrors']) ?></th>
          <th><?= htmlspecialchars($textbotlang['panel']['diagnosticsVersion']) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($healthHistory === []): ?>
          <tr>
            <td colspan="6">
              <div class="empty">
                <div class="empty-mark">—</div>
                <p><?= htmlspecialchars($textbotlang['panel']['diagnosticsNoHistory']) ?></p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($healthHistory as $snapshot): ?>
            <tr>
              <td class="cell-mono" style="white-space:nowrap"><?= htmlspecialchars((string) $snapshot['time']) ?></td>
              <td>
                <span class="tag <?= $snapshot['overall_healthy'] ? 'tag-ok' : 'tag-warn' ?>">
                  <?= htmlspecialchars($snapshot['overall_healthy']
                    ? $textbotlang['panel']['diagnosticsHealthy']
                    : $textbotlang['panel']['diagnosticsProblem']) ?>
                </span>
              </td>
              <td>
                <span class="tag <?= $snapshot['database_ok'] ? 'tag-ok' : 'tag-no' ?>">
                  <?= htmlspecialchars($snapshot['database_ok']
                    ? $textbotlang['panel']['diagnosticsHealthy']
                    : $textbotlang['panel']['diagnosticsProblem']) ?>
                </span>
              </td>
              <td>
                <span class="tag <?= $snapshot['security_healthy'] ? 'tag-ok' : 'tag-warn' ?>">
                  <?= htmlspecialchars($snapshot['security_healthy']
                    ? $textbotlang['panel']['diagnosticsHealthy']
                    : $textbotlang['panel']['diagnosticsProblem']) ?>
                </span>
              </td>
              <td>
                <span class="tag <?= ((int) $snapshot['delivery_errors']) !== 0 ? 'tag-no' : 'tag-ok' ?>">
                  <?= $formatDeliveryCount((int) $snapshot['delivery_errors']) ?>
                </span>
              </td>
              <td class="cell-mono"><?= htmlspecialchars((string) $snapshot['version']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
