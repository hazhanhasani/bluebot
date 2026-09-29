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

  BluebotDigitalServices::ensureManagedCategories($pdo);

  $digitalRedirect = static function (): never {
    header('Location: service.php?scope=digital');
    exit;
  };

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'digital_add' || $action === 'digital_edit') {
      $id = max(0, (int) ($_POST['id'] ?? 0));
      $code = strtolower(trim((string) ($_POST['code'] ?? '')));
      $name = trim((string) ($_POST['name'] ?? ''));
      $type = trim((string) ($_POST['type'] ?? 'custom'));
      $provider = strtolower(trim((string) ($_POST['provider'] ?? 'manual')));
      $price = max(0, (int) ($_POST['price'] ?? 0));
      $serviceValue = max(1, (int) ($_POST['service_value'] ?? 1));
      $providerCode = trim((string) ($_POST['provider_service_code'] ?? ''));
      $description = trim((string) ($_POST['description'] ?? ''));
      $category = strtolower(trim((string) ($_POST['category_key'] ?? 'other')));
      $sortOrder = (int) ($_POST['sort_order'] ?? 0);
      $active = !empty($_POST['active']) ? 1 : 0;

      $allowedTypes = ['telegram_stars', 'telegram_premium', 'virtual_number', 'ozvinoo_service', 'custom'];
      $providerKeys = ['manual', 'telegram_bot', 'tgtools', 'ozvinoo'];
      try {
        foreach (BluebotProviderCatalogService::listProviders($pdo) as $registeredProvider) {
          $key = strtolower(trim((string) ($registeredProvider['provider_key'] ?? '')));
          if ($key !== '') {
            $providerKeys[] = $key;
          }
        }
      } catch (Throwable $e) {
      }
      $providerKeys = array_values(array_unique($providerKeys));

      if ($code === '' && $action === 'digital_add') {
        $code = 'manual-' . strtolower(bin2hex(random_bytes(5)));
      }
      if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/', $code)
        || $name === ''
        || !in_array($type, $allowedTypes, true)
        || !in_array($provider, $providerKeys, true)
        || !preg_match('/^[a-z0-9_-]{1,40}$/', $category)
        || $price <= 0) {
        flash('error', 'اطلاعات سرویس کامل یا معتبر نیست.');
        $digitalRedirect();
      }

      if ($provider === 'tgtools' && !in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
        flash('error', 'TGTools فقط Stars و Premium را پشتیبانی می‌کند.');
        $digitalRedirect();
      }
      if ($provider === 'telegram_bot' && $type !== 'telegram_premium') {
        flash('error', 'Telegram Bot API در این بخش فقط برای Premium قابل استفاده است.');
        $digitalRedirect();
      }

      $metadata = [];
      if ($action === 'digital_edit' && $id > 0) {
        $existing = BluebotDigitalServices::findProduct($pdo, $id, false);
        if (!is_array($existing)) {
          flash('error', 'سرویس پیدا نشد.');
          $digitalRedirect();
        }
        $decoded = json_decode((string) ($existing['metadata'] ?? ''), true);
        $metadata = is_array($decoded) ? $decoded : [];
      }
      $metadata['category_key'] = $category;
      $metadata['category_label'] = BluebotDigitalServices::categoryLabel($category, $pdo);
      if ($action === 'digital_edit') {
        $metadata['admin_category_override'] = true;
      }
      if ($active === 0) {
        $metadata['admin_disabled'] = true;
      } else {
        unset($metadata['admin_disabled']);
      }
      $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      try {
        if ($action === 'digital_add') {
          $stmt = $pdo->prepare(
            "INSERT INTO digital_service_products
             (code, name, type, provider, price, service_value, provider_service_code, description, metadata, active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $stmt->execute([
            $code, $name, $type, $provider, $price, $serviceValue,
            $providerCode !== '' ? $providerCode : null,
            $description !== '' ? $description : null,
            is_string($metadataJson) ? $metadataJson : null,
            $active, $sortOrder
          ]);
          flash('success', 'سرویس فروش خدمات اضافه شد.');
        } else {
          $stmt = $pdo->prepare(
            "UPDATE digital_service_products
             SET code = ?, name = ?, type = ?, provider = ?, price = ?, service_value = ?,
                 provider_service_code = ?, description = ?, metadata = ?, active = ?, sort_order = ?, updated_at = NOW()
             WHERE id = ?"
          );
          $stmt->execute([
            $code, $name, $type, $provider, $price, $serviceValue,
            $providerCode !== '' ? $providerCode : null,
            $description !== '' ? $description : null,
            is_string($metadataJson) ? $metadataJson : null,
            $active, $sortOrder, $id
          ]);
          flash('success', 'سرویس بروزرسانی شد.');
        }
      } catch (Throwable $e) {
        flash('error', 'ذخیره سرویس انجام نشد؛ کد سرویس باید یکتا باشد.');
      }
      $digitalRedirect();
    }

    if ($action === 'digital_toggle') {
      $id = max(0, (int) ($_POST['id'] ?? 0));
      $product = BluebotDigitalServices::findProduct($pdo, $id, false);
      if (!is_array($product)) {
        flash('error', 'سرویس پیدا نشد.');
        $digitalRedirect();
      }

      $disable = (int) ($product['active'] ?? 0) === 1;
      if (!BluebotDigitalServices::setProductAdminDisabled($pdo, $id, $disable)) {
        flash('error', 'تغییر وضعیت سرویس ذخیره نشد.');
        $digitalRedirect();
      }

      flash(
        'success',
        $disable
          ? 'سرویس خاموش شد؛ همگام‌سازی Provider دیگر آن را خودکار روشن نمی‌کند.'
          : 'سرویس روشن شد و قفل خاموشی دستی برداشته شد.'
      );
      $digitalRedirect();
    }

    if ($action === 'digital_delete') {
      $id = max(0, (int) ($_POST['id'] ?? 0));
      $countStmt = $pdo->prepare("SELECT COUNT(*) FROM digital_service_orders WHERE service_id = ?");
      $countStmt->execute([$id]);
      if ((int) $countStmt->fetchColumn() > 0) {
        flash('warning', 'این سرویس سابقه سفارش دارد؛ برای حفظ سوابق حذف نشد. آن را غیرفعال کنید.');
      } else {
        $stmt = $pdo->prepare("DELETE FROM digital_service_products WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'سرویس حذف شد.');
      }
      $digitalRedirect();
    }
  }

  $q = trim((string) ($_GET['q'] ?? ''));
  $providerFilter = strtolower(trim((string) ($_GET['provider'] ?? '')));
  $typeFilter = trim((string) ($_GET['type'] ?? ''));
  $stateFilter = trim((string) ($_GET['state'] ?? ''));
  $page = max(1, (int) ($_GET['page'] ?? 1));
  $perPage = 50;
  $offset = ($page - 1) * $perPage;

  $where = [];
  $params = [];
  if ($q !== '') {
    $where[] = "(name LIKE ? OR code LIKE ? OR provider LIKE ? OR COALESCE(provider_service_code,'') LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
  }
  if ($providerFilter !== '') {
    $where[] = "provider = ?";
    $params[] = $providerFilter;
  }
  if ($typeFilter !== '') {
    $where[] = "type = ?";
    $params[] = $typeFilter;
  }
  if ($stateFilter === 'active') {
    $where[] = "active = 1";
  } elseif ($stateFilter === 'inactive') {
    $where[] = "active = 0";
  }
  $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

  $totalDigital = db_count($pdo, "SELECT COUNT(*) FROM digital_service_products $whereSql", $params);
  $digitalProducts = db_fetchAll(
    $pdo,
    "SELECT * FROM digital_service_products $whereSql ORDER BY sort_order ASC, id ASC LIMIT $perPage OFFSET $offset",
    $params
  );
  $totalPages = max(1, (int) ceil($totalDigital / $perPage));
  $digitalCategories = BluebotDigitalServices::managedCategories($pdo);
  $registeredProviders = ['manual', 'telegram_bot', 'tgtools', 'ozvinoo'];
  try {
    foreach (BluebotProviderCatalogService::listProviders($pdo) as $registeredProvider) {
      $key = strtolower(trim((string) ($registeredProvider['provider_key'] ?? '')));
      if ($key !== '') {
        $registeredProviders[] = $key;
      }
    }
  } catch (Throwable $e) {
  }
  $registeredProviders = array_values(array_unique($registeredProviders));

  $pageTitle = 'سرویس‌ها · فروش خدمات';
  $pageLede = 'مدیریت کامل سرویس‌های دیجیتال، قیمت، دسته‌بندی، Provider و وضعیت نمایش در ربات';
  $activeNav = 'service';
  include __DIR__ . '/inc/layout_head.php';
  ?>
  <div class="card fade-up" style="margin-bottom:16px">
    <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <a class="btn btn-ghost" href="service.php">سرویس‌های اشتراک</a>
      <a class="btn btn-primary" href="service.php?scope=digital">فروش خدمات</a>
      <a class="btn btn-ghost" href="invoice.php?scope=digital">سفارش‌های فروش خدمات</a>
      <a class="btn btn-ghost" href="category.php?scope=digital">دسته‌بندی‌ها</a>
      <a class="btn btn-ghost" href="digital_services.php">API و سود</a>
    </div>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:10px;flex-wrap:wrap" class="fade-up">
    <div style="color:var(--mute);font-size:.84rem">
      <?= number_format($totalDigital) ?> سرویس
      · <?= number_format((int) db_count($pdo, "SELECT COUNT(*) FROM digital_service_products WHERE active = 1")) ?> فعال
    </div>
    <button class="btn btn-primary" onclick="openModal('digitalAddModal')"><?= icon('plus', 14) ?> افزودن سرویس</button>
  </div>

  <div class="card fade-up d1">
    <div class="toolbar" style="gap:10px;flex-wrap:wrap">
      <div class="toolbar-title">سرویس‌های فروش خدمات</div>
      <form method="get" class="toolbar-end" style="gap:8px;flex-wrap:wrap">
        <input type="hidden" name="scope" value="digital">
        <select class="select" name="provider" style="width:auto">
          <option value="">همه Providerها</option>
          <?php foreach ($registeredProviders as $provider): ?>
            <option value="<?= htmlspecialchars($provider) ?>" <?= $providerFilter === $provider ? 'selected' : '' ?>><?= htmlspecialchars($provider) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" name="type" style="width:auto">
          <option value="">همه نوع‌ها</option>
          <?php foreach ([
            'telegram_stars' => 'Telegram Stars',
            'telegram_premium' => 'Telegram Premium',
            'virtual_number' => 'Virtual Number',
            'ozvinoo_service' => 'OZVinoo Service',
            'custom' => 'Custom',
          ] as $typeKey => $typeLabel): ?>
            <option value="<?= $typeKey ?>" <?= $typeFilter === $typeKey ? 'selected' : '' ?>><?= $typeLabel ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" name="state" style="width:auto">
          <option value="">همه وضعیت‌ها</option>
          <option value="active" <?= $stateFilter === 'active' ? 'selected' : '' ?>>فعال</option>
          <option value="inactive" <?= $stateFilter === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
        </select>
        <div class="search-box" style="min-width:220px">
          <?= icon('search', 14) ?>
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="نام، کد یا Provider">
          <button type="submit" class="search-btn">جستجو</button>
        </div>
      </form>
    </div>

    <div class="tbl-wrap">
      <table class="tbl-xl">
        <thead>
          <tr>
            <th>#</th><th>سرویس</th><th>دسته‌بندی</th><th>Provider</th><th>قیمت</th><th>مقدار</th><th>وضعیت</th><th>ترتیب</th><th>عملیات</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($digitalProducts === []): ?>
          <tr><td colspan="9"><div class="empty"><p>سرویسی با این فیلتر پیدا نشد.</p></div></td></tr>
        <?php else: ?>
          <?php foreach ($digitalProducts as $product):
            $metadata = json_decode((string) ($product['metadata'] ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $categoryKey = BluebotDigitalServices::categoryForProduct($product);
            $editPayload = [
              'id' => (int) $product['id'],
              'code' => (string) $product['code'],
              'name' => (string) $product['name'],
              'type' => (string) $product['type'],
              'provider' => (string) $product['provider'],
              'price' => (int) $product['price'],
              'service_value' => (int) $product['service_value'],
              'provider_service_code' => (string) ($product['provider_service_code'] ?? ''),
              'description' => (string) ($product['description'] ?? ''),
              'category_key' => $categoryKey,
              'sort_order' => (int) $product['sort_order'],
              'active' => (int) $product['active'],
              'auto_imported' => !empty($metadata['auto_imported']),
            ];
          ?>
            <tr>
              <td class="cf"><?= (int) $product['id'] ?></td>
              <td><strong><?= htmlspecialchars((string) $product['name']) ?></strong><br><small class="cm"><?= htmlspecialchars((string) $product['code']) ?></small></td>
              <td><span class="tag tag-info"><?= htmlspecialchars(BluebotDigitalServices::categoryLabel($categoryKey, $pdo)) ?></span></td>
              <td><code><?= htmlspecialchars((string) $product['provider']) ?></code></td>
              <td class="cn cs"><?= number_format((int) $product['price']) ?> تومان</td>
              <td class="cn"><?= number_format((int) $product['service_value']) ?></td>
              <td><span class="tag <?= (int) $product['active'] === 1 ? 'tag-ok' : 'tag-plain' ?>"><?= (int) $product['active'] === 1 ? 'فعال' : 'غیرفعال' ?></span></td>
              <td class="cn"><?= (int) $product['sort_order'] ?></td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <button class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                    onclick='openDigitalEdit(<?= json_encode($editPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'><?= icon('edit', 13) ?></button>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="digital_toggle">
                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $product['active'] === 1 ? 'خاموش' : 'روشن' ?></button>
                  </form>
                  <form method="post" style="display:inline" data-confirm="این سرویس حذف شود؟">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="digital_delete">
                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                    <button class="btn btn-no btn-sm btn-icon" type="submit"><?= icon('trash', 13) ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="tbl-foot">
      <span><?= number_format($totalDigital) ?> سرویس · صفحه <?= $page ?> از <?= $totalPages ?></span>
      <div class="pager">
        <?php
        $qs = static fn(int $p): string => '?scope=digital&q=' . urlencode($q)
          . '&provider=' . urlencode($providerFilter)
          . '&type=' . urlencode($typeFilter)
          . '&state=' . urlencode($stateFilter)
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
  $renderDigitalForm = static function (string $modalId, string $action, string $title, array $categories, array $providers): void {
  ?>
  <div class="modal-veil" id="<?= $modalId ?>">
    <div class="modal">
      <div class="modal-head">
        <h3><?= htmlspecialchars($title) ?></h3>
        <button class="modal-x" type="button" onclick="closeModal('<?= $modalId ?>')"><?= icon('close', 14) ?></button>
      </div>
      <form method="post" id="<?= $modalId ?>Form">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="<?= $action ?>">
          <input type="hidden" name="id" data-digital-field="id">
          <div class="form-grid">
            <div class="field full"><label>نام سرویس</label><input class="input" name="name" data-digital-field="name" required></div>
            <div class="field"><label>کد داخلی</label><input class="input" name="code" data-digital-field="code" dir="ltr" placeholder="manual-service"></div>
            <div class="field"><label>قیمت فروش (تومان)</label><input class="input" type="number" name="price" data-digital-field="price" min="1" required></div>
            <div class="field">
              <label>نوع</label>
              <select class="select" name="type" data-digital-field="type">
                <option value="telegram_stars">Telegram Stars</option>
                <option value="telegram_premium">Telegram Premium</option>
                <option value="virtual_number">Virtual Number</option>
                <option value="ozvinoo_service">OZVinoo Service</option>
                <option value="custom">Custom</option>
              </select>
            </div>
            <div class="field">
              <label>Provider</label>
              <select class="select" name="provider" data-digital-field="provider">
                <?php foreach ($providers as $provider): ?><option value="<?= htmlspecialchars($provider) ?>"><?= htmlspecialchars($provider) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>مقدار/تعداد</label><input class="input" type="number" name="service_value" data-digital-field="service_value" min="1" value="1"></div>
            <div class="field"><label>کد Provider</label><input class="input" name="provider_service_code" data-digital-field="provider_service_code" dir="ltr"></div>
            <div class="field">
              <label>دسته‌بندی</label>
              <select class="select" name="category_key" data-digital-field="category_key">
                <?php foreach ($categories as $category): ?>
                  <option value="<?= htmlspecialchars((string) $category['category_key']) ?>"><?= htmlspecialchars(trim((string) ($category['emoji'] ?? '') . ' ' . (string) ($category['name'] ?? ''))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>ترتیب</label><input class="input" type="number" name="sort_order" data-digital-field="sort_order" value="0"></div>
            <div class="field full"><label>توضیحات</label><textarea class="input" name="description" data-digital-field="description" rows="3"></textarea></div>
            <div class="field full"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="active" value="1" data-digital-field="active" checked> نمایش در ربات</label></div>
          </div>
          <div class="notice notice-info" data-auto-import-note style="display:none;margin-top:12px">
            این سرویس از Provider همگام شده است؛ نام/قیمت ممکن است در Sync بعدی براساس API و درصد سود بروزرسانی شود. دسته‌بندی، وضعیت نمایش و ترتیب قابل مدیریت هستند.
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn btn-primary" type="submit"><?= icon('check', 13) ?> ذخیره</button>
          <button class="btn btn-ghost" type="button" onclick="closeModal('<?= $modalId ?>')">انصراف</button>
        </div>
      </form>
    </div>
  </div>
  <?php
  };
  $renderDigitalForm('digitalAddModal', 'digital_add', 'افزودن سرویس فروش خدمات', $digitalCategories, $registeredProviders);
  $renderDigitalForm('digitalEditModal', 'digital_edit', 'ویرایش سرویس فروش خدمات', $digitalCategories, $registeredProviders);
  ?>
  <script>
  window.openDigitalEdit = function (item) {
    const modal = document.getElementById('digitalEditModal');
    if (!modal) return;
    modal.querySelectorAll('[data-digital-field]').forEach(function (field) {
      const key = field.getAttribute('data-digital-field');
      if (field.type === 'checkbox') {
        field.checked = String(item[key] ?? '0') === '1';
      } else {
        field.value = item[key] ?? '';
      }
    });
    const note = modal.querySelector('[data-auto-import-note]');
    if (note) note.style.display = item.auto_imported ? '' : 'none';
    openModal('digitalEditModal');
  };
  </script>
  <?php
  include __DIR__ . '/inc/layout_foot.php';
  exit;
}


$pageTitle = $textbotlang['panel']['servicesTitle'];
$pageLede = $textbotlang['panel']['servicesSubtitle'];
$activeNav = 'service_other';

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(id_user LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(type,'') LIKE ?)";
  $params = ["%$search%", "%$search%", "%$search%"];
}
if ($status !== '') {
  $where[] = "status = ?";
  $params[] = $status;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
  $total = db_count($pdo, "SELECT COUNT(*) FROM service_other $whereSQL", $params);
  $services = db_fetchAll($pdo, "SELECT * FROM service_other $whereSQL ORDER BY id DESC LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
  $total = 0;
  $services = [];
  error_log('service.php error: ' . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$typeMap = [
  'change_location' => $textbotlang['panel']['serviceChangeLocationLabel'],
  'extra_user' => $textbotlang['panel']['serviceExtraVolumeLabel'],
  'extra_time_user' => $textbotlang['panel']['serviceExtraTimeLabel'],
  'extends_not_user' => $textbotlang['panel']['serviceRenewLabel'],
  'extend_user' => $textbotlang['panel']['serviceRenewLabel2'],
  'transfertouser' => $textbotlang['panel']['serviceTransferOrderLabel']
];

$pageTitle = $textbotlang['panel']['servicesHeading'];
$pageLede = $textbotlang['panel']['servicesSubtitle2'];
$activeNav = 'service';
include __DIR__ . '/inc/layout_head.php';
?>
<div class="card fade-up" style="margin-bottom:16px"><div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-primary" href="service.php">سرویس‌های اشتراک</a><a class="btn btn-ghost" href="service.php?scope=digital">فروش خدمات</a></div></div>

<div class="card fade-up">
  <div class="toolbar">
    <div class="toolbar-title"><?= $textbotlang['panel']['servicesPageHeading'] ?> <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" id="srvForm" class="toolbar-end">
      <select name="status" class="select" style="width:auto" onchange="document.getElementById('srvForm').submit()">
        <option value=""><?= $textbotlang['panel']['serviceColUser'] ?></option>
        <option value="done" <?= $status === 'done' ? 'selected' : '' ?>><?= $textbotlang['panel']['serviceColType'] ?></option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>><?= $textbotlang['panel']['serviceColService'] ?></option>
        <option value="reject" <?= $status === 'reject' ? 'selected' : '' ?>><?= $textbotlang['panel']['serviceColStatus'] ?></option>
      </select>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" placeholder="<?= htmlspecialchars($textbotlang['panel']['serviceSearchServicePlaceholder']) ?>" value="<?= htmlspecialchars($search) ?>"
          autocomplete="off">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn"><?= $textbotlang['panel']['serviceColDate'] ?></button>
      </div>
      <?php if ($search || $status): ?>
        <a href="service.php" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['serviceColPanel'] ?></a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th><?= $textbotlang['panel']['serviceColProduct'] ?></th>
          <th><?= $textbotlang['panel']['serviceColAmount'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailTitle'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailUser'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailType'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailService'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailStatus'] ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($services)): ?>
          <tr>
            <td colspan="8">
              <div class="empty">
                <svg class="ill" viewBox="0 0 180 140" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="30" y="30" width="120" height="80" rx="10" fill="var(--sf3)" />
                  <rect x="50" y="50" width="40" height="40" rx="6" fill="var(--bds)" />
                  <rect x="100" y="55" width="35" height="8" rx="4" fill="var(--bd)" />
                  <rect x="100" y="70" width="25" height="8" rx="4" fill="var(--bd)" />
                  <rect x="100" y="85" width="30" height="8" rx="4" fill="var(--bd)" />
                  <path d="M60 65 l10 10 l20-20" stroke="var(--ac)" stroke-width="3" stroke-linecap="round" fill="none" />
                </svg>
                <p><?= $search ? $textbotlang['panel']['serviceNoServiceFound'] : $textbotlang['panel']['serviceNoManualServiceYet'] ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($services as $s):
            $stMap = [
              'done' => ['tag-ok', $textbotlang['panel']['serviceStatusDone']],
              'pending' => ['tag-warn', $textbotlang['panel']['serviceStatusWaiting']],
              'reject' => ['tag-no', $textbotlang['panel']['serviceStatusRejected']],
            ];
            [$cls, $lbl] = $stMap[$s['status'] ?? ''] ?? ['tag-plain', $s['status'] ?? '—'];
            $typeLabel = $typeMap[$s['type'] ?? ''] ?? ($s['type'] ?? '—');
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars($s['id_user'] ?? '—') ?></td>
              <td>
                <?= !empty($s['username']) ? '<span class="cm" style="color:var(--ac)">@' . htmlspecialchars(trunc($s['username'], 18)) . '</span>' : '<span class="cf">—</span>' ?>
              </td>
              <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($typeLabel) ?></td>
              <td class="cn" style="font-size:.82rem"><?= htmlspecialchars(trunc($s['value'] ?? '—', 20)) ?></td>
              <td class="cn cs"><?= number_format((int) ($s['price'] ?? 0)) ?> <span class="cf"><?= $textbotlang['panel']['serviceDetailDate'] ?></span></td>
              <td class="cf"><?= safe_date($s['time'] ?? null, 'Y/m/d') ?></td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span><?= number_format($total) ?> <?= $textbotlang['panel']['serviceDetailPanel'] ?> <?= $page ?> <?= $textbotlang['panel']['serviceCloseBtn'] ?> <?= $totalPages ?></span>
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