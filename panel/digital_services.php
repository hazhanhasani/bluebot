<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/../src/Services/DigitalServiceManager.php';

require_auth();

if (!BluebotDigitalServices::isAvailable($pdo)) {
    http_response_code(503);
    $pageTitle = 'فروش خدمات';
    $pageLede = 'مدیریت سرویس‌های دیجیتال';
    $activeNav = 'digital-services';
    include __DIR__ . '/inc/layout_head.php';
    ?>
    <div class="notice notice-warn">
        جدول‌های فروش خدمات هنوز ساخته نشده‌اند. یک‌بار <code>php table.php</code> را اجرا کنید.
    </div>
    <?php
    include __DIR__ . '/inc/layout_foot.php';
    exit;
}

function ds_panel_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM digital_service_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string) $value;
}

function ds_panel_set_setting(PDO $pdo, string $key, string $value, bool $secret = false): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO digital_service_settings (setting_key, setting_value, is_secret)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)"
    );
    $stmt->execute([$key, $value, $secret ? 1 : 0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_product') {
        $code = strtolower(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? ''));
        $provider = trim((string) ($_POST['provider'] ?? 'manual'));
        $price = max(0, (int) ($_POST['price'] ?? 0));
        $serviceValue = max(1, (int) ($_POST['service_value'] ?? 1));
        $providerCode = trim((string) ($_POST['provider_service_code'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        $allowedTypes = ['telegram_stars', 'telegram_premium', 'ozvinoo_service', 'custom'];
        $allowedProviders = ['manual', 'telegram_bot', 'tgtools', 'ozvinoo'];

        if ($provider === 'tgtools' && in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            $providerCode = '';
            if ($code === '') {
                $code = BluebotDigitalServices::generatedProviderProductCode($type, $serviceValue);
            }
            if ($name === '') {
                $name = BluebotDigitalServices::generatedProviderProductName($type, $serviceValue);
            }
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/', $code)
            || $name === ''
            || !in_array($type, $allowedTypes, true)
            || !in_array($provider, $allowedProviders, true)
            || $price <= 0) {
            flash('error', 'اطلاعات محصول کامل یا معتبر نیست.');
            header('Location: digital_services.php');
            exit;
        }

        if ($type === 'telegram_stars' && $provider === 'telegram_bot') {
            flash('error', 'ارسال مستقیم Stars با Bot API پشتیبانی نمی‌شود؛ Provider را TGTools، Manual یا OZVinoo انتخاب کنید.');
            header('Location: digital_services.php');
            exit;
        }

        if ($provider === 'tgtools' && !in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            flash('error', 'TGTools فقط برای Telegram Stars و Telegram Premium قابل استفاده است.');
            header('Location: digital_services.php');
            exit;
        }

        if ($type === 'telegram_premium' && in_array($provider, ['telegram_bot', 'tgtools'], true)
            && !in_array($serviceValue, [3, 6, 12], true)) {
            flash('error', 'Premium خودکار فقط برای ۳، ۶ یا ۱۲ ماه قابل ارسال است.');
            header('Location: digital_services.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO digital_service_products
                (code, name, type, provider, price, service_value, provider_service_code, description, active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
            );
            $stmt->execute([
                $code,
                $name,
                $type,
                $provider,
                $price,
                $serviceValue,
                $providerCode !== '' ? $providerCode : null,
                $description !== '' ? $description : null,
                $sortOrder,
            ]);
            flash('success', 'سرویس جدید اضافه شد.');
        } catch (Throwable $e) {
            flash('error', 'ذخیره سرویس انجام نشد؛ کد سرویس باید یکتا باشد.');
        }

        header('Location: digital_services.php');
        exit;
    }

    if ($action === 'toggle_product') {
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $check = $pdo->prepare("SELECT price, active FROM digital_service_products WHERE id = ? LIMIT 1");
        $check->execute([$id]);
        $currentProduct = $check->fetch(PDO::FETCH_ASSOC);
        if (is_array($currentProduct)
            && (int) ($currentProduct['active'] ?? 0) !== 1
            && (int) ($currentProduct['price'] ?? 0) <= 0) {
            flash('warning', 'قبل از فعال‌سازی، قیمت فروش سرویس را تعیین کنید.');
            header('Location: digital_services.php');
            exit;
        }

        $stmt = $pdo->prepare("UPDATE digital_service_products SET active = IF(active = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'وضعیت سرویس تغییر کرد.');
        header('Location: digital_services.php');
        exit;
    }

    if ($action === 'set_product_price') {
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $price = max(0, (int) ($_POST['price'] ?? 0));
        if ($id <= 0 || $price <= 0) {
            flash('error', 'قیمت فروش معتبر وارد کنید.');
            header('Location: digital_services.php');
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE digital_service_products SET price = ?, active = 1, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$price, $id]);
        flash('success', 'قیمت ذخیره شد و سرویس فعال شد.');
        header('Location: digital_services.php');
        exit;
    }

    if ($action === 'delete_product') {
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM digital_service_orders WHERE service_id = ?");
        $countStmt->execute([$id]);
        if ((int) $countStmt->fetchColumn() > 0) {
            flash('warning', 'این سرویس سفارش دارد و برای حفظ سوابق حذف نشد؛ آن را غیرفعال کنید.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM digital_service_products WHERE id = ?");
            $stmt->execute([$id]);
            flash('success', 'سرویس حذف شد.');
        }
        header('Location: digital_services.php');
        exit;
    }

    if ($action === 'save_tgtools') {
        $apiKey = trim((string) ($_POST['tgtools_api_key'] ?? ''));
        if ($apiKey !== '' && (strlen($apiKey) > 512 || preg_match('/[\r\n]/', $apiKey))) {
            flash('error', 'API Key واردشده معتبر نیست.');
            header('Location: digital_services.php#tgtools');
            exit;
        }

        $starsProfit = (float) ($_POST['tgtools_stars_profit_percent'] ?? 0);
        $premiumProfit = (float) ($_POST['tgtools_premium_profit_percent'] ?? 0);
        $tonRateToman = (float) ($_POST['tgtools_ton_toman_rate'] ?? 0);

        if ($starsProfit < 0 || $starsProfit > 1000 || $premiumProfit < 0 || $premiumProfit > 1000) {
            flash('error', 'درصد سود باید بین ۰ تا ۱۰۰۰ باشد.');
            header('Location: digital_services.php#tgtools');
            exit;
        }
        if ($tonRateToman < 0) {
            flash('error', 'نرخ TON معتبر نیست.');
            header('Location: digital_services.php#tgtools');
            exit;
        }

        ds_panel_set_setting($pdo, 'tgtools_base_url', 'https://api.tg-tools.shop');
        ds_panel_set_setting($pdo, 'tgtools_payment_method', 'ton');
        ds_panel_set_setting($pdo, 'tgtools_stars_profit_percent', (string) $starsProfit);
        ds_panel_set_setting($pdo, 'tgtools_premium_profit_percent', (string) $premiumProfit);
        ds_panel_set_setting($pdo, 'tgtools_ton_toman_rate', (string) $tonRateToman);
        if ($apiKey !== '') {
            ds_panel_set_setting($pdo, 'tgtools_api_key', $apiKey, true);
        }

        // Stars and Premium in BlueBot use TGTools as the delivery provider.
        $migrateProducts = $pdo->prepare(
            "UPDATE digital_service_products
             SET provider = 'tgtools', updated_at = NOW()
             WHERE type IN ('telegram_stars', 'telegram_premium')"
        );
        $migrateProducts->execute();

        $catalog = BluebotDigitalServices::ensureTgToolsCatalog($pdo);
        $catalogMessage = 'تنظیمات TGTools ذخیره شد. محصولات Stars/Premium همگام شدند: '
            . (int) ($catalog['created'] ?? 0) . ' جدید، '
            . (int) ($catalog['updated'] ?? 0) . ' بروزرسانی.';
        if (!empty($catalog['remote_ok'])) {
            flash('success', $catalogMessage . ' قیمت‌های زنده TGTools نیز دریافت شد.');
        } else {
            flash('warning', $catalogMessage . ' دریافت قیمت زنده موقتاً ممکن نبود و کاتالوگ جایگزین استفاده شد.');
        }
        header('Location: digital_services.php#tgtools');
        exit;
    }

    if ($action === 'sync_tgtools_catalog') {
        $catalog = BluebotDigitalServices::ensureTgToolsCatalog($pdo);
        if (!empty($catalog['ok'])) {
            $catalogMessage = 'محصولات Stars/Premium آماده شدند: '
                . (int) ($catalog['created'] ?? 0) . ' جدید، '
                . (int) ($catalog['updated'] ?? 0) . ' بروزرسانی.';
            if (!empty($catalog['remote_ok'])) {
                flash('success', $catalogMessage . ' قیمت‌های زنده TGTools دریافت شد.');
            } else {
                flash('warning', $catalogMessage . ' قیمت زنده در دسترس نبود؛ کاتالوگ جایگزین استفاده شد.');
            }
        } else {
            flash('error', 'همگام‌سازی محصولات انجام نشد.');
        }
        header('Location: digital_services.php#tgtools');
        exit;
    }

    if ($action === 'save_provider_catalog') {
        try {
            $provider = BluebotProviderCatalogService::saveProvider($pdo, [
                'provider_key' => $_POST['provider_key'] ?? '',
                'name' => $_POST['provider_name'] ?? '',
                'catalog_url' => $_POST['catalog_url'] ?? '',
                'api_key' => $_POST['provider_api_key'] ?? '',
                'auth_header' => $_POST['provider_auth_header'] ?? 'Authorization',
                'auth_prefix' => $_POST['provider_auth_prefix'] ?? 'Bearer',
                'products_path' => $_POST['products_path'] ?? 'data',
                'id_field' => $_POST['id_field'] ?? 'id',
                'name_field' => $_POST['name_field'] ?? 'name',
                'category_field' => $_POST['category_field'] ?? 'category',
                'price_field' => $_POST['price_field'] ?? 'price',
                'currency' => $_POST['provider_currency'] ?? 'toman',
                'exchange_rate_toman' => $_POST['exchange_rate_toman'] ?? 1,
                'profit_percent' => $_POST['profit_percent'] ?? 0,
                'sync_interval_minutes' => $_POST['sync_interval_minutes'] ?? 15,
            ]);

            $sync = BluebotProviderCatalogService::syncProvider($pdo, (string) ($provider['provider_key'] ?? ''));
            if (!empty($sync['ok'])) {
                flash(
                    'success',
                    'ارائه‌دهنده ذخیره و محصولات همگام شدند: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی.'
                );
            } else {
                flash('warning', 'ارائه‌دهنده ذخیره شد اما دریافت محصولات ناموفق بود: ' . (string) ($sync['message'] ?? 'خطای نامشخص'));
            }
        } catch (Throwable $e) {
            flash('error', 'ذخیره ارائه‌دهنده انجام نشد: ' . $e->getMessage());
        }

        header('Location: digital_services.php#providers');
        exit;
    }

    if ($action === 'sync_provider_catalog') {
        $providerKey = strtolower(trim((string) ($_POST['provider_key'] ?? '')));
        try {
            $sync = BluebotProviderCatalogService::syncProvider($pdo, $providerKey);
            if (!empty($sync['ok'])) {
                flash(
                    'success',
                    'کاتالوگ بروزرسانی شد: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال.'
                );
            } else {
                flash('error', 'همگام‌سازی ناموفق بود: ' . (string) ($sync['message'] ?? 'خطای نامشخص'));
            }
        } catch (Throwable $e) {
            flash('error', 'همگام‌سازی انجام نشد: ' . $e->getMessage());
        }
        header('Location: digital_services.php#providers');
        exit;
    }

    if ($action === 'toggle_provider_catalog') {
        $providerKey = strtolower(trim((string) ($_POST['provider_key'] ?? '')));
        $provider = BluebotProviderCatalogService::findProvider($pdo, $providerKey);
        if (is_array($provider)) {
            BluebotProviderCatalogService::setActive($pdo, $providerKey, (int) ($provider['active'] ?? 0) !== 1);
            flash('success', 'وضعیت ارائه‌دهنده تغییر کرد.');
        }
        header('Location: digital_services.php#providers');
        exit;
    }

    if ($action === 'delete_provider_catalog') {
        $providerKey = strtolower(trim((string) ($_POST['provider_key'] ?? '')));
        try {
            if (BluebotProviderCatalogService::deleteProvider($pdo, $providerKey)) {
                flash('success', 'ارائه‌دهنده و محصولات بدون سفارش آن حذف شدند.');
            } else {
                flash('warning', 'این ارائه‌دهنده سفارش ثبت‌شده دارد و حذف نشد؛ آن را غیرفعال کنید.');
            }
        } catch (Throwable $e) {
            flash('error', 'حذف ارائه‌دهنده انجام نشد: ' . $e->getMessage());
        }
        header('Location: digital_services.php#providers');
        exit;
    }

    if ($action === 'save_ozvinoo') {
        $orderPath = trim((string) ($_POST['ozvinoo_order_path'] ?? ''));
        $apiKey = trim((string) ($_POST['ozvinoo_api_key'] ?? ''));
        $authHeader = trim((string) ($_POST['ozvinoo_auth_header'] ?? 'Authorization'));
        $authPrefix = trim((string) ($_POST['ozvinoo_auth_prefix'] ?? 'Bearer'));

        if ($orderPath !== '' && !str_starts_with($orderPath, '/')) {
            $orderPath = '/' . $orderPath;
        }
        if ($orderPath !== '' && !preg_match('#^/[A-Za-z0-9_./{}-]+$#', $orderPath)) {
            flash('error', 'مسیر API معتبر نیست.');
            header('Location: digital_services.php#ozvinoo');
            exit;
        }
        if (!preg_match('/^[A-Za-z0-9-]{1,80}$/', $authHeader)) {
            flash('error', 'نام هدر احراز هویت معتبر نیست.');
            header('Location: digital_services.php#ozvinoo');
            exit;
        }

        ds_panel_set_setting($pdo, 'ozvinoo_base_url', 'https://api.ozvinoo.xyz');
        ds_panel_set_setting($pdo, 'ozvinoo_order_path', $orderPath);
        if ($apiKey !== '') {
            ds_panel_set_setting($pdo, 'ozvinoo_api_key', $apiKey, true);
        }
        ds_panel_set_setting($pdo, 'ozvinoo_auth_header', $authHeader);
        ds_panel_set_setting($pdo, 'ozvinoo_auth_prefix', $authPrefix);
        flash('success', 'تنظیمات OZVinoo ذخیره شد.');
        header('Location: digital_services.php#ozvinoo');
        exit;
    }

    if ($action === 'approve_order' || $action === 'reject_order') {
        $orderId = max(0, (int) ($_POST['order_id'] ?? 0));
        try {
            if ($action === 'approve_order') {
                $result = BluebotDigitalServices::approveAndDeliver(
                    $pdo,
                    $orderId,
                    (string) ($_SESSION['admin_user'] ?? 'panel')
                );
                if (empty($result['ok'])) {
                    flash('error', 'ارسال ناموفق بود: ' . (string) ($result['error'] ?? 'خطای Provider'));
                } elseif (!empty($result['pending'])) {
                    flash('success', 'سفارش به TGTools ارسال شد و در حال پردازش است.');
                } else {
                    flash('success', 'سفارش تأیید و ارسال شد.');
                }
            } else {
                BluebotDigitalServices::rejectAndRefund(
                    $pdo,
                    $orderId,
                    (string) ($_SESSION['admin_user'] ?? 'panel')
                );
                flash('success', 'سفارش رد شد و وجه به کیف پول برگشت.');
            }
        } catch (Throwable $e) {
            flash('error', 'عملیات سفارش انجام نشد: ' . $e->getMessage());
        }

        header('Location: digital_services.php');
        exit;
    }
}

$products = db_fetchAll($pdo, "SELECT * FROM digital_service_products ORDER BY sort_order ASC, id ASC");
$orders = db_fetchAll(
    $pdo,
    "SELECT * FROM digital_service_orders ORDER BY id DESC LIMIT 100"
);
$pendingCount = db_count(
    $pdo,
    "SELECT COUNT(*) FROM digital_service_orders WHERE status IN ('pending_approval', 'failed')"
);
$tgApiKey = ds_panel_setting($pdo, 'tgtools_api_key');
$tgStarsProfit = (float) ds_panel_setting($pdo, 'tgtools_stars_profit_percent', '0');
$tgPremiumProfit = (float) ds_panel_setting($pdo, 'tgtools_premium_profit_percent', '0');
$tgTonRateToman = (float) ds_panel_setting($pdo, 'tgtools_ton_toman_rate', '0');
$providerCatalogs = BluebotProviderCatalogService::listProviders($pdo);
$ozOrderPath = ds_panel_setting($pdo, 'ozvinoo_order_path');
$ozApiKey = ds_panel_setting($pdo, 'ozvinoo_api_key');
$ozAuthHeader = ds_panel_setting($pdo, 'ozvinoo_auth_header', 'Authorization');
$ozAuthPrefix = ds_panel_setting($pdo, 'ozvinoo_auth_prefix', 'Bearer');

$pageTitle = 'فروش خدمات';
$pageLede = 'فروش Stars و Telegram Premium با TGTools و تأیید دستی قبل از ارسال';
$activeNav = 'digital-services';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="two-col">
    <div class="card fade-up">
        <div class="card-head">
            <div>
                <div class="card-title">افزودن سرویس</div>
                <div class="card-subtitle">قیمت فروش و روش تحویل را تعریف کنید.</div>
            </div>
        </div>
        <form method="post" class="card-body" style="display:grid;gap:12px">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_product">

            <div class="field">
                <label>نام سرویس</label>
                <input class="input" name="name" maxlength="190" placeholder="برای TGTools می‌تواند خالی باشد">
                <small class="field-hint">برای Stars/Premium با TGTools نام به‌صورت خودکار ساخته می‌شود.</small>
            </div>
            <div class="field">
                <label>کد داخلی</label>
                <input class="input" name="code" maxlength="80" dir="ltr" placeholder="خودکار برای TGTools">
                <small class="field-hint">نیازی نیست کد محصولات Provider را بدانید؛ BlueBot کد داخلی یکتا می‌سازد.</small>
            </div>
            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>نوع</label>
                    <select class="select" name="type" required>
                        <option value="telegram_stars">Telegram Stars</option>
                        <option value="telegram_premium">Telegram Premium</option>
                        <option value="ozvinoo_service">OZVinoo Service</option>
                        <option value="custom">Custom / Manual</option>
                    </select>
                </div>
                <div class="field">
                    <label>Provider</label>
                    <select class="select" name="provider" required>
                        <option value="manual">Manual</option>
                        <option value="telegram_bot">Telegram Bot API</option>
                        <option value="tgtools">TGTools API</option>
                        <option value="ozvinoo">OZVinoo API</option>
                    </select>
                </div>
            </div>
            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>قیمت فروش (تومان)</label>
                    <input class="input" type="number" name="price" min="1" required>
                </div>
                <div class="field">
                    <label>مقدار سرویس</label>
                    <input class="input" type="number" name="service_value" min="1" value="1" required>
                    <small class="field-hint">Stars = تعداد ستاره، Premium = تعداد ماه</small>
                </div>
            </div>
            <div class="field">
                <label>Provider Service Code</label>
                <input class="input" name="provider_service_code" maxlength="190" dir="ltr" placeholder="فقط Providerهایی که واقعاً Service Code دارند">
                <small class="field-hint">برای TGTools/Stars/Premium خالی بگذارید؛ مقدار و مدت سرویس ملاک است.</small>
            </div>
            <div class="field">
                <label>توضیحات</label>
                <textarea class="textarea" name="description" rows="3" maxlength="2000"></textarea>
            </div>
            <div class="field">
                <label>ترتیب</label>
                <input class="input" type="number" name="sort_order" value="0">
            </div>
            <button class="btn btn-primary" type="submit"><?= icon('plus', 14) ?> افزودن سرویس</button>
        </form>
    </div>

    <div class="card fade-up d1" id="tgtools">
        <div class="card-head">
            <div>
                <div class="card-title">TGTools API</div>
                <div class="card-subtitle">ارسال Stars و Premium پس از تأیید دستی ادمین؛ وضعیت سفارش خودکار پیگیری می‌شود.</div>
            </div>
        </div>
        <form method="post" class="card-body" style="display:grid;gap:12px">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_tgtools">
            <div class="field">
                <label>Base URL</label>
                <input class="input" value="https://api.tg-tools.shop" disabled dir="ltr">
            </div>
            <div class="field">
                <label>API Key</label>
                <input class="input" type="password" name="tgtools_api_key" autocomplete="new-password"
                    placeholder="<?= $tgApiKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'tgt_...' ?>">
                <small class="field-hint">کلید از Settings → API Keys در TGTools ساخته می‌شود و در پیام‌های ربات نمایش داده نمی‌شود.</small>
            </div>
            <div class="notice notice-info">
                BlueBot بسته‌های Stars و قیمت‌های Premium را از <code>/api/purchase/prices</code> می‌خواند و خودکار همگام می‌کند.
                هیچ کد محصول خارجی لازم نیست؛ کد داخلی از نوع سرویس و مقدار آن ساخته می‌شود و Provider Service Code خالی می‌ماند.
            </div>
            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره TGTools</button>
        </form>
        <form method="post" class="card-body" style="padding-top:0">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="sync_tgtools_catalog">
            <button class="btn btn-ghost" type="submit">↻ ساخت/همگام‌سازی خودکار محصولات</button>
        </form>
    </div>

    <div class="card fade-up d1" id="ozvinoo">
        <div class="card-head">
            <div>
                <div class="card-title">OZVinoo API</div>
                <div class="card-subtitle">ارسال API نیز فقط پس از تأیید دستی ادمین انجام می‌شود.</div>
            </div>
        </div>
        <form method="post" class="card-body" style="display:grid;gap:12px">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ozvinoo">
            <div class="field">
                <label>Base URL</label>
                <input class="input" value="https://api.ozvinoo.xyz" disabled dir="ltr">
            </div>
            <div class="field">
                <label>Order endpoint path</label>
                <input class="input" name="ozvinoo_order_path" value="<?= htmlspecialchars($ozOrderPath) ?>" dir="ltr" placeholder="/api/...">
                <small class="field-hint">مسیر دقیق را مطابق مستندات حساب OZVinoo خود وارد کنید؛ Host برای امنیت ثابت است.</small>
            </div>
            <div class="field">
                <label>API Key</label>
                <input class="input" type="password" name="ozvinoo_api_key" autocomplete="new-password"
                    placeholder="<?= $ozApiKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'API key' ?>">
            </div>
            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>Auth header</label>
                    <input class="input" name="ozvinoo_auth_header" value="<?= htmlspecialchars($ozAuthHeader) ?>" dir="ltr">
                </div>
                <div class="field">
                    <label>Prefix</label>
                    <input class="input" name="ozvinoo_auth_prefix" value="<?= htmlspecialchars($ozAuthPrefix) ?>" dir="ltr">
                </div>
            </div>
            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره تنظیمات</button>
        </form>
    </div>
</div>

<div class="card fade-up d1" style="margin-top:16px">
    <div class="card-head">
        <div>
            <div class="card-title">سرویس‌ها</div>
            <div class="card-subtitle"><?= number_format(count($products)) ?> مورد تعریف شده</div>
        </div>
    </div>
    <div class="tbl-wrap">
        <table class="tbl-lg">
            <thead>
                <tr>
                    <th>سرویس</th>
                    <th>نوع</th>
                    <th>Provider</th>
                    <th>مقدار</th>
                    <th>قیمت</th>
                    <th>وضعیت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($products === []): ?>
                <tr><td colspan="7"><div class="empty"><p>هنوز سرویسی تعریف نشده است.</p></div></td></tr>
            <?php else: foreach ($products as $product): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($product['name']) ?></strong>
                        <div class="cell-mono" style="font-size:.72rem"><?= htmlspecialchars($product['code']) ?></div>
                    </td>
                    <td class="cell-mono"><?= htmlspecialchars($product['type']) ?></td>
                    <td class="cell-mono"><?= htmlspecialchars($product['provider']) ?></td>
                    <td><?= number_format((int) $product['service_value']) ?></td>
                    <td>
                        <?php if (($product['provider'] ?? '') === 'tgtools'): ?>
                            <form method="post" style="display:flex;gap:6px;align-items:center;min-width:210px">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_product_price">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <input class="input" style="min-width:120px" type="number" name="price" min="1"
                                    value="<?= (int) $product['price'] ?>" placeholder="قیمت فروش">
                                <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                            </form>
                            <?php
                            $tgMeta = json_decode((string) ($product['metadata'] ?? ''), true);
                            $tgWholesaleTon = is_array($tgMeta) ? ($tgMeta['wholesale_ton'] ?? null) : null;
                            $tgWholesaleUsd = is_array($tgMeta) ? ($tgMeta['wholesale_usd'] ?? null) : null;
                            ?>
                            <?php if (is_numeric($tgWholesaleTon)): ?>
                                <div class="field-hint" style="margin-top:4px">
                                    عمده TGTools: <?= htmlspecialchars((string) $tgWholesaleTon) ?> TON
                                    <?php if (is_numeric($tgWholesaleUsd)): ?>
                                        · $<?= htmlspecialchars((string) $tgWholesaleUsd) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= number_format((float) $product['price']) ?> تومان
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="tag <?= (int) $product['active'] === 1 ? 'tag-ok' : 'tag-plain' ?>">
                            <?= (int) $product['active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_product">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">تغییر وضعیت</button>
                            </form>
                            <form method="post" data-confirm="حذف شود؟">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <button class="btn btn-no btn-sm" type="submit"><?= icon('trash', 12) ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card fade-up d2" style="margin-top:16px">
    <div class="card-head">
        <div>
            <div class="card-title">سفارش‌ها</div>
            <div class="card-subtitle"><?= number_format($pendingCount) ?> سفارش نیازمند بررسی</div>
        </div>
    </div>
    <div class="tbl-wrap">
        <table class="tbl-xl">
            <thead>
                <tr>
                    <th>کد</th>
                    <th>کاربر</th>
                    <th>سرویس</th>
                    <th>مقصد</th>
                    <th>مبلغ</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($orders === []): ?>
                <tr><td colspan="8"><div class="empty"><p>سفارشی ثبت نشده است.</p></div></td></tr>
            <?php else: foreach ($orders as $order): ?>
                <?php
                $status = (string) $order['status'];
                $tag = match ($status) {
                    'delivered' => 'tag-ok',
                    'rejected' => 'tag-no',
                    'failed' => 'tag-warn',
                    'pending_approval' => 'tag-info',
                    default => 'tag-plain',
                };
                ?>
                <tr>
                    <td class="cell-mono"><?= htmlspecialchars($order['order_code']) ?></td>
                    <td class="cell-mono"><?= htmlspecialchars($order['user_id']) ?></td>
                    <td><?= htmlspecialchars($order['service_name']) ?></td>
                    <td class="cell-mono"><?= htmlspecialchars($order['target']) ?></td>
                    <td><?= number_format((float) $order['amount']) ?> تومان</td>
                    <td><span class="tag <?= $tag ?>"><?= htmlspecialchars($status) ?></span></td>
                    <td style="white-space:nowrap"><?= htmlspecialchars($order['created_at']) ?></td>
                    <td>
                        <?php if (in_array($status, ['pending_approval', 'failed'], true)): ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <form method="post">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="approve_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-ok btn-sm" type="submit">تأیید و ارسال</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reject_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-no btn-sm" type="submit">رد + برگشت وجه</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span style="color:var(--mute)">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
