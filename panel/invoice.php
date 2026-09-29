<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../src/Services/DigitalServiceManager.php';
require_auth();

$scope = ($_GET['scope'] ?? '') === 'digital' ? 'digital' : 'subscriptions';

if ($scope === 'digital') {
  if (!BluebotDigitalServices::isAvailable($pdo)) {
    flash('error', 'ماژول فروش خدمات هنوز روی دیتابیس نصب نشده است.');
    header('Location: digital_services.php');
    exit;
  }

  $digitalRedirect = static function (): never {
    header('Location: invoice.php?scope=digital');
    exit;
  };

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = trim((string) ($_POST['action'] ?? ''));
    $orderId = max(0, (int) ($_POST['order_id'] ?? 0));

    if (in_array($action, ['digital_approve', 'digital_reject'], true) && $orderId > 0) {
      try {
        if ($action === 'digital_approve') {
          $result = BluebotDigitalServices::approveAndDeliver(
            $pdo,
            $orderId,
            (string) ($_SESSION['admin_user'] ?? 'panel')
          );
          if (empty($result['ok'])) {
            $message = (string) ($result['error'] ?? 'خطای Provider');
            if (!empty($result['retryable'])) {
              flash('warning', 'ارسال انجام نشد و قابل تلاش مجدد است: ' . $message);
            } elseif (!empty($result['refunded'])) {
              flash('warning', 'ارسال ناموفق بود و مبلغ به کیف پول کاربر برگشت: ' . $message);
            } else {
              flash('error', 'ارسال ناموفق بود: ' . $message);
            }
          } elseif (!empty($result['pending'])) {
            flash('success', 'سفارش به Provider ارسال شد و در حال پردازش است.');
          } else {
            flash('success', 'سفارش با موفقیت تحویل شد.');
          }
        } else {
          BluebotDigitalServices::rejectAndRefund(
            $pdo,
            $orderId,
            (string) ($_SESSION['admin_user'] ?? 'panel')
          );
          flash('success', 'سفارش رد شد و مبلغ به کیف پول کاربر برگشت.');
        }
      } catch (Throwable $e) {
        flash('error', 'عملیات سفارش انجام نشد: ' . $e->getMessage());
      }
      $digitalRedirect();
    }
  }

  $search = trim((string) ($_GET['q'] ?? ''));
  $status = trim((string) ($_GET['status'] ?? ''));
  $provider = strtolower(trim((string) ($_GET['provider'] ?? '')));
  $page = max(1, (int) ($_GET['page'] ?? 1));
  $perPage = 50;
  $offset = ($page - 1) * $perPage;

  $where = [];
  $params = [];
  if ($search !== '') {
    $where[] = "(order_code LIKE ? OR user_id LIKE ? OR service_name LIKE ? OR target LIKE ? OR COALESCE(provider_reference,'') LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%", "%$search%");
  }
  if ($status !== '') {
    $where[] = "status = ?";
    $params[] = $status;
  }
  if ($provider !== '') {
    $where[] = "provider = ?";
    $params[] = $provider;
  }
  $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

  $total = db_count($pdo, "SELECT COUNT(*) FROM digital_service_orders $whereSql", $params);
  $orders = db_fetchAll(
    $pdo,
    "SELECT * FROM digital_service_orders $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset",
    $params
  );
  $totalPages = max(1, (int) ceil($total / $perPage));
  $providers = db_fetchAll(
    $pdo,
    "SELECT DISTINCT provider FROM digital_service_orders WHERE provider <> '' ORDER BY provider"
  );

  $statusMap = [
    'pending_approval' => ['tag-warn', 'در انتظار تأیید'],
    'processing' => ['tag-info', 'در حال پردازش'],
    'delivered' => ['tag-ok', 'تحویل‌شده'],
    'failed' => ['tag-no', 'ناموفق'],
    'rejected' => ['tag-plain', 'رد و مستردشده'],
  ];

  $pageTitle = 'سفارش‌ها · فروش خدمات';
  $pageLede = 'مدیریت سفارش‌های Stars، Premium، شماره مجازی و سرویس‌های دیجیتال';
  $activeNav = 'invoice';
  include __DIR__ . '/inc/layout_head.php';
  ?>
  <div class="card fade-up" style="margin-bottom:16px">
    <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost" href="invoice.php">سفارش‌های اشتراک</a>
      <a class="btn btn-primary" href="invoice.php?scope=digital">فروش خدمات</a>
      <a class="btn btn-ghost" href="service.php?scope=digital">سرویس‌ها</a>
      <a class="btn btn-ghost" href="category.php?scope=digital">دسته‌بندی‌ها</a>
      <a class="btn btn-ghost" href="digital_services.php">API و سود</a>
    </div>
  </div>

  <?php
  $pendingCount = (int) db_count(
    $pdo,
    "SELECT COUNT(*) FROM digital_service_orders WHERE status IN ('pending_approval','failed') AND refunded = 0"
  );
  $processingCount = (int) db_count($pdo, "SELECT COUNT(*) FROM digital_service_orders WHERE status = 'processing'");
  $deliveredCount = (int) db_count($pdo, "SELECT COUNT(*) FROM digital_service_orders WHERE status = 'delivered'");
  ?>
  <div class="stats-grid fade-up" style="margin-bottom:16px">
    <div class="stat-card"><div class="stat-label">نیازمند اقدام</div><div class="stat-value"><?= number_format($pendingCount) ?></div></div>
    <div class="stat-card"><div class="stat-label">در حال پردازش</div><div class="stat-value"><?= number_format($processingCount) ?></div></div>
    <div class="stat-card"><div class="stat-label">تحویل‌شده</div><div class="stat-value"><?= number_format($deliveredCount) ?></div></div>
  </div>

  <div class="card fade-up d1">
    <div class="toolbar" style="gap:8px;flex-wrap:wrap">
      <div class="toolbar-title">سفارش‌های فروش خدمات <small>(<?= number_format($total) ?>)</small></div>
      <form method="get" class="toolbar-end" style="gap:8px;flex-wrap:wrap">
        <input type="hidden" name="scope" value="digital">
        <select name="status" class="select" style="width:auto">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach ($statusMap as $key => [$_cls, $label]): ?>
            <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <select name="provider" class="select" style="width:auto">
          <option value="">همه Providerها</option>
          <?php foreach ($providers as $providerRow):
            $providerName = (string) ($providerRow['provider'] ?? '');
          ?>
            <option value="<?= htmlspecialchars($providerName) ?>" <?= $provider === $providerName ? 'selected' : '' ?>><?= htmlspecialchars($providerName) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="search-box" style="min-width:240px">
          <?= icon('search', 14) ?>
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="کد سفارش، کاربر، سرویس یا مقصد">
          <button type="submit" class="search-btn">جستجو</button>
        </div>
      </form>
    </div>

    <div class="tbl-wrap">
      <table class="tbl-xl">
        <thead>
          <tr>
            <th>#</th><th>کد سفارش</th><th>کاربر</th><th>سرویس</th><th>مقصد</th><th>Provider</th><th>مبلغ</th><th>وضعیت</th><th>پیگیری</th><th>تاریخ</th><th>عملیات</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($orders === []): ?>
          <tr><td colspan="11"><div class="empty"><p>سفارشی با این فیلتر پیدا نشد.</p></div></td></tr>
        <?php else: ?>
          <?php foreach ($orders as $order):
            $orderStatus = (string) ($order['status'] ?? '');
            [$statusClass, $statusLabel] = $statusMap[$orderStatus] ?? ['tag-plain', $orderStatus ?: '—'];
          ?>
            <tr>
              <td class="cf"><?= (int) $order['id'] ?></td>
              <td><code><?= htmlspecialchars((string) $order['order_code']) ?></code></td>
              <td class="cm"><?= htmlspecialchars((string) $order['user_id']) ?></td>
              <td><strong><?= htmlspecialchars((string) $order['service_name']) ?></strong></td>
              <td class="cm"><?= htmlspecialchars(trunc((string) $order['target'], 24)) ?></td>
              <td><code><?= htmlspecialchars((string) $order['provider']) ?></code></td>
              <td class="cn cs"><?= number_format((int) $order['amount']) ?> تومان</td>
              <td><span class="tag <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span><?php if ((int) ($order['refunded'] ?? 0) === 1): ?><br><small class="cm">مسترد شده</small><?php endif; ?></td>
              <td class="cm"><?= htmlspecialchars(trunc((string) ($order['provider_reference'] ?? '—'), 18)) ?></td>
              <td class="cf"><?= safe_date($order['created_at'] ?? null, 'Y/m/d H:i') ?></td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                <?php if (in_array($orderStatus, ['pending_approval', 'failed'], true) && (int) ($order['refunded'] ?? 0) !== 1): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="digital_approve">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <button class="btn btn-ok btn-sm" type="submit"><?= $orderStatus === 'failed' ? 'تلاش مجدد' : 'تأیید و ارسال' ?></button>
                  </form>
                  <form method="post" style="display:inline" data-confirm="سفارش رد و مبلغ به کیف پول کاربر برگردد؟">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="digital_reject">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <button class="btn btn-no btn-sm" type="submit">رد + بازگشت وجه</button>
                  </form>
                <?php elseif ($orderStatus === 'processing'): ?>
                  <span class="cf">پیگیری خودکار</span>
                <?php else: ?>
                  <span class="cf">—</span>
                <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="tbl-foot">
      <span><?= number_format($total) ?> سفارش · صفحه <?= $page ?> از <?= $totalPages ?></span>
      <div class="pager">
        <?php
        $qs = static fn(int $p): string => '?scope=digital&q=' . urlencode($search)
          . '&status=' . urlencode($status)
          . '&provider=' . urlencode($provider)
          . '&page=' . $p;
        ?>
        <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
      </div>
    </div>
  </div>
  <?php
  include __DIR__ . '/inc/layout_foot.php';
  exit;
}


$search = trim($_GET['q'] ?? '');

$status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(id_user LIKE ? OR COALESCE(name_product,'') LIKE ? OR COALESCE(username,'') LIKE ?)";
  $params = ["%$search%", "%$search%", "%$search%"];
}
if ($status !== '') {

  $where[] = "Status = ?";
  $params[] = $status;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
  $total = db_count($pdo, "SELECT COUNT(*) FROM invoice $whereSQL", $params);
  $invoices = db_fetchAll($pdo, "SELECT * FROM invoice $whereSQL ORDER BY time_sell DESC LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
  $total = 0;
  $invoices = [];
  flash('error', $textbotlang['panel']['invoiceDbError'] . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$statusMap = [
  'active' => ['tag-ok', $textbotlang['panel']['invoiceStatusActive']],
  'end_of_time' => ['tag-warn', $textbotlang['panel']['invoiceNotifTimeExpire']],
  'end_of_volume' => ['tag-no', $textbotlang['panel']['invoiceNotifVolumeExpire']],
  'sendedwarn' => ['tag-warn', $textbotlang['panel']['invoiceNotifAllSent']],
  'send_on_hold' => ['tag-plain', $textbotlang['panel']['invoiceNotifNotConnectedSent']],
  'unpaid' => ['tag-plain', $textbotlang['panel']['invoiceStatusUnpaid']],
  'Unsuccessful' => ['tag-plain', $textbotlang['panel']['invoiceDataFetchError']],
];

$pageTitle = $textbotlang['panel']['invoiceOrdersTitle'];
$pageLede = $textbotlang['panel']['invoiceOrdersSubtitle'];
$activeNav = 'invoice';
include __DIR__ . '/inc/layout_head.php';
?>
<div class="card fade-up" style="margin-bottom:16px"><div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-primary" href="invoice.php">سفارش‌های اشتراک</a><a class="btn btn-ghost" href="invoice.php?scope=digital">فروش خدمات</a></div></div>

<div class="card fade-up">
  <div class="toolbar">
    <div class="toolbar-title"><?= $textbotlang['panel']['invoiceOrdersHeading'] ?> <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" id="invoiceForm" class="toolbar-end">
      <select name="status" class="select" style="width:auto"
        onchange="document.getElementById('invoiceForm').submit()">
        <option value=""><?= $textbotlang['panel']['invoiceAllStatuses'] ?></option>
        <?php foreach ($statusMap as $k => [$_, $lbl]): ?>
          <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" placeholder="<?= htmlspecialchars($textbotlang['panel']['invoiceSearchOrderPlaceholder']) ?>" value="<?= htmlspecialchars($search) ?>"
          autocomplete="off">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn"><?= $textbotlang['panel']['invoiceSearchBtn'] ?></button>
      </div>
      <?php if ($search || $status): ?>
        <a href="invoice.php" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['invoiceClearBtn'] ?></a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <tr>
          <th>#</th>
          <th><?= $textbotlang['panel']['invoiceColUser'] ?></th>
          <th><?= $textbotlang['panel']['invoiceColProduct'] ?></th>
          <th><?= $textbotlang['panel']['invoiceColPrice'] ?></th>
          <th><?= $textbotlang['panel']['invoiceColStatus'] ?></th>
          <th><?= $textbotlang['panel']['invoiceColDate'] ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($invoices)): ?>
          <tr>
            <td colspan="6">
              <div class="empty">
                <svg class="ill" viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="30" y="15" width="100" height="90" rx="8" fill="var(--sf3)" />
                  <rect x="45" y="35" width="70" height="8" rx="4" fill="var(--bds)" />
                  <rect x="45" y="52" width="50" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="66" width="60" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="80" width="35" height="6" rx="3" fill="var(--bd)" />
                </svg>
                <p><?= $search ? $textbotlang['panel']['invoiceNoOrderFound'] : $textbotlang['panel']['invoiceNoOrderYet'] ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($invoices as $inv):
            $st = $inv['Status'] ?? '';
            [$cls, $lbl] = $statusMap[$st] ?? ['tag-plain', $st ?: '—'];
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars($inv['id_user'] ?? '—') ?></td>
              <td class="cs"><?= htmlspecialchars(trunc($inv['name_product'] ?? '—', 28)) ?></td>
              <td class="cn cs"><?= number_format((int) ($inv['price_product'] ?? 0)) ?> <span class="cf"><?= $textbotlang['panel']['invoiceColTrackingCode'] ?></span></td>
              <td class="cf"><?= safe_date($inv['time_sell'] ?? null, 'Y/m/d') ?></td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span><?= number_format($total) ?> <?= $textbotlang['panel']['invoiceColService'] ?> <?= $page ?> <?= $textbotlang['panel']['invoiceColPanel'] ?> <?= $totalPages ?></span>
    <div class="pager">
      <?php $qs = fn($p) => '?q=' . urlencode($search) . '&status=' . urlencode($status) . '&page=' . $p; ?>
      <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>