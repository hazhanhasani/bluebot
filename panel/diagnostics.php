<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../src/Support/Diagnostics.php';
require_auth();

$diagnostics = bluebotCollectDiagnostics($pdo, is_array($setting ?? null) ? $setting : (select("setting", "*") ?: []));

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
  <div class="stat <?= ((int) $diagnostics['delivery_errors']) > 0 ? 'no' : 'ok' ?>">
    <div class="stat-label"><?= htmlspecialchars($textbotlang['panel']['diagnosticsDeliveryErrors']) ?></div>
    <div class="stat-num"><?= number_format(max(0, (int) $diagnostics['delivery_errors'])) ?></div>
    <div class="stat-meta"><?= ((int) $diagnostics['delivery_errors']) > 0
      ? htmlspecialchars($textbotlang['panel']['diagnosticsProblem'])
      : htmlspecialchars($textbotlang['panel']['diagnosticsHealthy']) ?></div>
  </div>
</div>

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
              <span class="tag tag-plain"><?= number_format(max(0, (int) $diagnostics['delivery_reviewed'])) ?></span>
            </td>
          </tr>
          <tr>
            <td><?= htmlspecialchars($textbotlang['panel']['diagnosticsDeliveryErrors']) ?></td>
            <td style="text-align:end">
              <?php if ((int) $diagnostics['delivery_errors'] > 0): ?>
                <a class="tag tag-no" href="payment.php?status=delivery_error">
                  <?= number_format((int) $diagnostics['delivery_errors']) ?>
                </a>
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

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
