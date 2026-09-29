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

  $categoryTableExists = false;
  try {
    $categoryTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'digital_service_categories'")->fetchColumn();
  } catch (Throwable $e) {
  }
  if (!$categoryTableExists) {
    flash('warning', 'جدول دسته‌بندی فروش خدمات هنوز ساخته نشده است؛ بروزرسانی دیتابیس را اجرا کنید.');
    header('Location: digital_services.php');
    exit;
  }

  BluebotDigitalServices::ensureManagedCategories($pdo);

  $digitalRedirect = static function (): never {
    header('Location: category.php?scope=digital');
    exit;
  };

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'digital_category_add') {
      $key = strtolower(trim((string) ($_POST['category_key'] ?? '')));
      $name = trim((string) ($_POST['name'] ?? ''));
      $emoji = trim((string) ($_POST['emoji'] ?? ''));
      $sort = (int) ($_POST['sort_order'] ?? 500);
      $active = !empty($_POST['active']) ? 1 : 0;

      if (!preg_match('/^[a-z0-9_-]{1,40}$/', $key) || $name === '') {
        flash('error', 'کلید یا نام دسته‌بندی معتبر نیست.');
        $digitalRedirect();
      }

      try {
        $stmt = $pdo->prepare(
          "INSERT INTO digital_service_categories
           (category_key, name, emoji, sort_order, active)
           VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$key, $name, mb_substr($emoji, 0, 32, 'UTF-8'), $sort, $active]);
        flash('success', 'دسته‌بندی فروش خدمات اضافه شد.');
      } catch (Throwable $e) {
        flash('error', 'ذخیره دسته‌بندی انجام نشد؛ کلید باید یکتا باشد.');
      }
      $digitalRedirect();
    }

    if ($action === 'digital_category_edit') {
      $id = max(0, (int) ($_POST['id'] ?? 0));
      $name = trim((string) ($_POST['name'] ?? ''));
      $emoji = trim((string) ($_POST['emoji'] ?? ''));
      $sort = (int) ($_POST['sort_order'] ?? 500);
      $active = !empty($_POST['active']) ? 1 : 0;

      if ($id <= 0 || $name === '') {
        flash('error', 'اطلاعات دسته‌بندی معتبر نیست.');
        $digitalRedirect();
      }

      $stmt = $pdo->prepare(
        "UPDATE digital_service_categories
         SET name = ?, emoji = ?, sort_order = ?, active = ?, updated_at = NOW()
         WHERE id = ?"
      );
      $stmt->execute([$name, mb_substr($emoji, 0, 32, 'UTF-8'), $sort, $active, $id]);
      flash('success', 'دسته‌بندی بروزرسانی شد.');
      $digitalRedirect();
    }

    if ($action === 'digital_category_delete') {
      $id = max(0, (int) ($_POST['id'] ?? 0));
      $stmt = $pdo->prepare("SELECT category_key FROM digital_service_categories WHERE id = ? LIMIT 1");
      $stmt->execute([$id]);
      $key = (string) ($stmt->fetchColumn() ?: '');

      $used = 0;
      if ($key !== '') {
        foreach (db_fetchAll($pdo, "SELECT * FROM digital_service_products") as $product) {
          if (BluebotDigitalServices::categoryForProduct($product) === $key) {
            $used++;
          }
        }
      }

      if ($used > 0) {
        flash('warning', 'این دسته‌بندی به ' . number_format($used) . ' سرویس متصل است؛ ابتدا سرویس‌ها را به دسته دیگری منتقل کنید.');
      } else {
        $delete = $pdo->prepare("DELETE FROM digital_service_categories WHERE id = ?");
        $delete->execute([$id]);
        flash('success', 'دسته‌بندی حذف شد.');
      }
      $digitalRedirect();
    }
  }

  $categories = BluebotDigitalServices::managedCategories($pdo);
  $allProducts = db_fetchAll($pdo, "SELECT * FROM digital_service_products");
  $usage = [];
  foreach ($allProducts as $product) {
    $key = BluebotDigitalServices::categoryForProduct($product);
    $usage[$key] = ($usage[$key] ?? 0) + 1;
  }

  $pageTitle = 'دسته‌بندی‌ها · فروش خدمات';
  $pageLede = 'ساختار دسته‌بندی ربات را از همین صفحه مدیریت کنید';
  $activeNav = 'category';
  include __DIR__ . '/inc/layout_head.php';
  ?>
  <div class="card fade-up" style="margin-bottom:16px">
    <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost" href="category.php">دسته‌بندی‌های اشتراک</a>
      <a class="btn btn-primary" href="category.php?scope=digital">فروش خدمات</a>
      <a class="btn btn-ghost" href="service.php?scope=digital">سرویس‌ها</a>
      <a class="btn btn-ghost" href="invoice.php?scope=digital">سفارش‌ها</a>
      <a class="btn btn-ghost" href="digital_services.php">API و سود</a>
    </div>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:10px;flex-wrap:wrap" class="fade-up">
    <div style="color:var(--mute);font-size:.84rem"><?= number_format(count($categories)) ?> دسته‌بندی</div>
    <button class="btn btn-primary" onclick="openModal('digitalCategoryAdd')"><?= icon('plus', 14) ?> دسته‌بندی جدید</button>
  </div>

  <div class="card fade-up d1">
    <div class="toolbar">
      <div class="toolbar-title">دسته‌بندی‌های فروش خدمات</div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="جستجو در دسته‌بندی‌ها" data-filter="digitalCategoryTable">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table id="digitalCategoryTable" class="tbl-xl">
        <thead><tr><th>#</th><th>نمایش در ربات</th><th>کلید</th><th>تعداد سرویس</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $category):
          $payload = [
            'id' => (int) $category['id'],
            'category_key' => (string) $category['category_key'],
            'name' => (string) $category['name'],
            'emoji' => (string) ($category['emoji'] ?? ''),
            'sort_order' => (int) $category['sort_order'],
            'active' => (int) $category['active'],
          ];
          $label = trim((string) ($category['emoji'] ?? '') . ' ' . (string) ($category['name'] ?? ''));
          $key = (string) $category['category_key'];
        ?>
          <tr>
            <td class="cf"><?= (int) $category['id'] ?></td>
            <td><strong><?= htmlspecialchars($label) ?></strong></td>
            <td><code><?= htmlspecialchars($key) ?></code></td>
            <td class="cn"><?= number_format((int) ($usage[$key] ?? 0)) ?></td>
            <td class="cn"><?= (int) $category['sort_order'] ?></td>
            <td><span class="tag <?= (int) $category['active'] === 1 ? 'tag-ok' : 'tag-plain' ?>"><?= (int) $category['active'] === 1 ? 'فعال' : 'مخفی' ?></span></td>
            <td>
              <div style="display:flex;gap:5px">
                <button class="btn btn-ghost btn-sm btn-icon" onclick='openDigitalCategoryEdit(<?= json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'><?= icon('edit', 13) ?></button>
                <form method="post" style="display:inline" data-confirm="این دسته‌بندی حذف شود؟">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="digital_category_delete">
                  <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                  <button class="btn btn-no btn-sm btn-icon" type="submit"><?= icon('trash', 13) ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="modal-veil" id="digitalCategoryAdd">
    <div class="modal">
      <div class="modal-head"><h3>دسته‌بندی جدید فروش خدمات</h3><button class="modal-x" onclick="closeModal('digitalCategoryAdd')"><?= icon('close', 14) ?></button></div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="digital_category_add">
          <div class="form-grid">
            <div class="field"><label>نام</label><input class="input" name="name" required placeholder="مثلاً شماره مجازی"></div>
            <div class="field"><label>ایموجی</label><input class="input" name="emoji" maxlength="32" placeholder="📱"></div>
            <div class="field"><label>کلید</label><input class="input" name="category_key" dir="ltr" required placeholder="virtual_number" pattern="[a-z0-9_-]{1,40}"></div>
            <div class="field"><label>ترتیب</label><input class="input" type="number" name="sort_order" value="500"></div>
            <div class="field full"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" checked> نمایش در ربات</label></div>
          </div>
        </div>
        <div class="modal-foot"><button class="btn btn-primary" type="submit">ذخیره</button><button class="btn btn-ghost" type="button" onclick="closeModal('digitalCategoryAdd')">انصراف</button></div>
      </form>
    </div>
  </div>

  <div class="modal-veil" id="digitalCategoryEdit">
    <div class="modal">
      <div class="modal-head"><h3>ویرایش دسته‌بندی فروش خدمات</h3><button class="modal-x" onclick="closeModal('digitalCategoryEdit')"><?= icon('close', 14) ?></button></div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="digital_category_edit">
          <input type="hidden" name="id" id="digital_category_id">
          <div class="form-grid">
            <div class="field"><label>نام</label><input class="input" name="name" id="digital_category_name" required></div>
            <div class="field"><label>ایموجی</label><input class="input" name="emoji" id="digital_category_emoji" maxlength="32"></div>
            <div class="field"><label>کلید ثابت</label><input class="input" id="digital_category_key" disabled dir="ltr"></div>
            <div class="field"><label>ترتیب</label><input class="input" type="number" name="sort_order" id="digital_category_sort"></div>
            <div class="field full"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" id="digital_category_active"> نمایش در ربات</label></div>
          </div>
        </div>
        <div class="modal-foot"><button class="btn btn-primary" type="submit">ذخیره تغییرات</button><button class="btn btn-ghost" type="button" onclick="closeModal('digitalCategoryEdit')">انصراف</button></div>
      </form>
    </div>
  </div>

  <script>
  window.openDigitalCategoryEdit = function (item) {
    document.getElementById('digital_category_id').value = item.id || '';
    document.getElementById('digital_category_name').value = item.name || '';
    document.getElementById('digital_category_emoji').value = item.emoji || '';
    document.getElementById('digital_category_key').value = item.category_key || '';
    document.getElementById('digital_category_sort').value = item.sort_order ?? 0;
    document.getElementById('digital_category_active').checked = String(item.active ?? '0') === '1';
    openModal('digitalCategoryEdit');
  };
  </script>
  <?php
  include __DIR__ . '/inc/layout_foot.php';
  exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  csrf_check_post();
  $remark = trim($_POST['remark'] ?? '');
  if ($remark === '') {
    flash('error', $textbotlang['panel']['categoryNameRequired']);
    header('Location: category.php');
    exit;
  }
  if (db_count($pdo, "SELECT COUNT(*) FROM category WHERE remark = ?", [$remark])) {
    flash('error', $textbotlang['panel']['categoryNameExists']);
    header('Location: category.php');
    exit;
  }
  try {
    db_query($pdo, "INSERT INTO category (remark) VALUES (?)", [$remark]);
    flash('success', $textbotlang['panel']['categoryAdded']);
  } catch (Exception $e) {
    flash('error', $textbotlang['panel']['productDbError'] . $e->getMessage());
  }
  header('Location: category.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  csrf_check_post();
  $cid = (int) ($_POST['edit_id'] ?? 0);
  $remark = trim($_POST['remark'] ?? '');
  if ($cid && $remark !== '') {
    try {
      db_query($pdo, "UPDATE category SET remark=? WHERE id=?", [$remark, $cid]);
      flash('success', $textbotlang['panel']['categoryEdited']);
    } catch (Exception $e) {
      flash('error', $textbotlang['panel']['productErrorPrefix'] . $e->getMessage());
    }
  }
  header('Location: category.php');
  exit;
}

if (isset($_GET['delete'])) {
  csrf_check_get();
  db_query($pdo, "DELETE FROM category WHERE id = ?", [(int) $_GET['delete']]);
  flash('success', $textbotlang['panel']['categoryDeleted']);
  header('Location: category.php');
  exit;
}

$categories = db_fetchAll($pdo, "SELECT * FROM category ORDER BY id");

$pageTitle = $textbotlang['panel']['categoryPageTitle'];
$pageLede = $textbotlang['panel']['categoryPageLede'];
$activeNav = 'category';
include __DIR__ . '/inc/layout_head.php';
?>
<div class="card fade-up" style="margin-bottom:16px"><div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-primary" href="category.php">دسته‌بندی‌های اشتراک</a><a class="btn btn-ghost" href="category.php?scope=digital">فروش خدمات</a></div></div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($categories) ?> <?= $textbotlang['panel']['categoryCount'] ?></div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> <?= $textbotlang['panel']['categoryCreateBtn'] ?></button>
</div>

<div class="card fade-up d1">
  <?php if (empty($categories)): ?>
    <div class="empty" style="padding:60px 20px">
      <svg class="ill" viewBox="0 0 200 160" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="40" y="30" width="120" height="100" rx="12" fill="var(--surface-3)" />
        <rect x="56" y="50" width="88" height="12" rx="6" fill="var(--border-strong)" />
        <rect x="56" y="72" width="60" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="90" width="72" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="108" width="44" height="8" rx="4" fill="var(--border)" />
        <circle cx="155" cy="125" r="22" fill="var(--accent-s)" stroke="var(--accent)" stroke-width="2" />
        <path d="M147 125h16M155 117v16" stroke="var(--accent)" stroke-width="2.5" stroke-linecap="round" />
      </svg>
      <p><?= $textbotlang['panel']['categoryEmpty'] ?></p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?>
        <?= $textbotlang['panel']['categoryCreateBtn'] ?></button>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title"><?= $textbotlang['panel']['categoryPageTitle'] ?> <small>(<?= count($categories) ?>)</small></div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="<?= htmlspecialchars($textbotlang['panel']['categorySearchPlaceholder']) ?>" data-filter="catTbl">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table id="catTbl" class="tbl-xl">
        <thead>
          <tr>
            <th style="width:70px">#</th>
            <th><?= $textbotlang['panel']['categoryColName'] ?></th>
            <th style="width:110px;text-align:left"><?= $textbotlang['panel']['categoryColActions'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($categories as $c): ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($c['remark'] ?? '') ?></td>
              <td>
                <div style="display:flex;gap:5px;justify-content:flex-end">
                  <button class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productEditBtn']) ?>"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="category.php?delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productDeleteBtn']) ?>"
                    data-confirm="<?= htmlspecialchars($textbotlang['panel']['categoryDeleteConfirm']) ?>">
                    <?= icon('trash', 13) ?>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="modal-veil" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['categoryCreateTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['categoryNameLabel'] ?></label>
            <input type="text" name="remark" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['categoryNamePlaceholder']) ?>" required>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> <?= $textbotlang['panel']['categorySaveBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['categoryEditTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['categoryNameLabel'] ?></label>
            <input type="text" name="remark" id="edit_remark" class="input" required>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $textbotlang['panel']['categorySaveChangeBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<script>
window.openEditModal = function(c) {
  document.getElementById('edit_id').value = c.id || '';
  document.getElementById('edit_remark').value = c.remark || '';
  openModal('editModal');
};
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
