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

        $allowedTypes = ['telegram_stars', 'telegram_premium', 'virtual_number', 'ozvinoo_service', 'custom'];
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
                'products_path' => $_POST['products_path'] ?? 'auto',
                'id_field' => $_POST['id_field'] ?? 'auto',
                'name_field' => $_POST['name_field'] ?? 'auto',
                'category_field' => $_POST['category_field'] ?? 'auto',
                'price_field' => $_POST['price_field'] ?? 'auto',
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
        $apiKey = trim((string) ($_POST['ozvinoo_api_key'] ?? ''));
        $profitPercent = (float) ($_POST['ozvinoo_profit_percent'] ?? 0);
        $syncInterval = max(1, min(1440, (int) ($_POST['ozvinoo_sync_interval_minutes'] ?? 15)));

        if ($apiKey !== '' && (strlen($apiKey) > 2048 || preg_match('/[\r\n]/', $apiKey))) {
            flash('error', 'API Key عضوینو معتبر نیست.');
            header('Location: digital_services.php#ozvinoo');
            exit;
        }
        if ($profitPercent < 0 || $profitPercent > 1000) {
            flash('error', 'درصد سود عضوینو باید بین ۰ تا ۱۰۰۰ باشد.');
            header('Location: digital_services.php#ozvinoo');
            exit;
        }

        $effectiveApiKey = $apiKey !== '' ? $apiKey : ds_panel_setting($pdo, 'ozvinoo_api_key');
        if ($effectiveApiKey === '') {
            flash('warning', 'ابتدا API Key عضوینو را وارد کنید.');
            header('Location: digital_services.php#ozvinoo');
            exit;
        }

        ds_panel_set_setting($pdo, 'ozvinoo_base_url', 'https://api.ozvinoo.xyz');
        ds_panel_set_setting($pdo, 'ozvinoo_profit_percent', (string) $profitPercent);
        ds_panel_set_setting($pdo, 'ozvinoo_sync_interval_minutes', (string) $syncInterval);
        ds_panel_set_setting($pdo, 'ozvinoo_currency', 'toman');
        ds_panel_set_setting($pdo, 'ozvinoo_exchange_rate_toman', '1');
        ds_panel_set_setting($pdo, 'ozvinoo_auth_header', 'Authorization');
        ds_panel_set_setting($pdo, 'ozvinoo_auth_prefix', 'Bearer');
        ds_panel_set_setting($pdo, 'ozvinoo_api_style', 'official-v1');
        ds_panel_set_setting($pdo, 'ozvinoo_catalog_path', '/telegram-services/stars/');
        ds_panel_set_setting($pdo, 'ozvinoo_order_path', '/telegram-services/stars/');
        if ($apiKey !== '') {
            ds_panel_set_setting($pdo, 'ozvinoo_api_key', $apiKey, true);
        }

        try {
            $sync = BluebotDigitalServices::syncOZVinooCatalog($pdo);
            if (!empty($sync['ok'])) {
                $wallet = BluebotDigitalServices::ozvinooWalletStatus($pdo);
                $balanceText = !empty($wallet['ok']) && is_numeric($wallet['balance'] ?? null)
                    ? ' · موجودی API: ' . number_format((float) $wallet['balance']) . ' تومان'
                    : '';

                flash(
                    'success',
                    'عضوینو با API رسمی همگام شد: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال. '
                    . 'سود ' . rtrim(rtrim(number_format($profitPercent, 2, '.', ''), '0'), '.') . '٪ اعمال شد'
                    . $balanceText
                );
            } else {
                flash('error', 'همگام‌سازی عضوینو ناموفق بود: ' . (string) ($sync['message'] ?? 'خطای نامشخص'));
            }
        } catch (Throwable $e) {
            flash('error', 'ذخیره/همگام‌سازی عضوینو انجام نشد: ' . $e->getMessage());
        }

        header('Location: digital_services.php#ozvinoo');
        exit;
    }

    if ($action === 'sync_ozvinoo_catalog') {
        try {
            $sync = BluebotDigitalServices::syncOZVinooCatalog($pdo);
            if (!empty($sync['ok'])) {
                flash(
                    'success',
                    'محصولات عضوینو از endpointهای رسمی بروزرسانی شدند: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال.'
                );
            } else {
                flash('error', 'همگام‌سازی عضوینو ناموفق بود: ' . (string) ($sync['message'] ?? 'خطای نامشخص'));
            }
        } catch (Throwable $e) {
            flash('error', 'همگام‌سازی عضوینو انجام نشد: ' . $e->getMessage());
        }
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
                    $errorText = (string) ($result['error'] ?? 'خطای Provider');
                    if (!empty($result['retryable'])) {
                        flash('warning', 'ارسال انجام نشد ولی قابل تلاش مجدد است: ' . $errorText);
                    } elseif (!empty($result['refunded'])) {
                        flash('warning', 'ارسال انجام نشد و مبلغ به کیف پول کاربر برگشت: ' . $errorText);
                    } else {
                        flash('error', 'ارسال ناموفق بود: ' . $errorText);
                    }
                } elseif (!empty($result['pending'])) {
                    flash('success', 'سفارش به Provider ارسال شد و در حال پردازش است.');
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
$tgWalletStatus = $tgApiKey !== ''
    ? BluebotDigitalServices::tgToolsWalletStatus($pdo)
    : ['ok' => false, 'configured' => false, 'balance_ton' => null, 'deposit_address' => '', 'message' => 'API Key تنظیم نشده است.'];
$providerCatalogs = array_values(array_filter(
    BluebotProviderCatalogService::listProviders($pdo),
    static fn (array $provider): bool => strtolower((string) ($provider['provider_key'] ?? '')) !== 'ozvinoo'
));
$ozApiKey = ds_panel_setting($pdo, 'ozvinoo_api_key');
$ozProfitPercent = (float) ds_panel_setting($pdo, 'ozvinoo_profit_percent', '0');
$ozSyncInterval = (int) ds_panel_setting($pdo, 'ozvinoo_sync_interval_minutes', '15');
$ozProvider = BluebotProviderCatalogService::findProvider($pdo, 'ozvinoo');
$ozProductCountStmt = $pdo->query("SELECT COUNT(*) FROM digital_service_products WHERE provider = 'ozvinoo' AND active = 1");
$ozProductCount = (int) $ozProductCountStmt->fetchColumn();
$ozWalletStatus = $ozApiKey !== ''
    ? BluebotDigitalServices::ozvinooWalletStatus($pdo)
    : ['ok' => false, 'configured' => false, 'balance' => null, 'message' => 'API Key تنظیم نشده است.'];

$pageTitle = 'فروش خدمات';
$pageLede = 'فروش Stars، Premium و شماره مجازی با Providerهای متصل و تأیید دستی قبل از ارسال';
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
                        <option value="virtual_number">Telegram Virtual Number</option>
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
            <div class="notice <?= !empty($tgWalletStatus['ok']) ? 'notice-info' : 'notice-warn' ?>">
                <strong>کیف پول API TGTools:</strong>
                <?php if (!empty($tgWalletStatus['ok'])): ?>
                    موجودی:
                    <code><?= is_numeric($tgWalletStatus['balance_ton'] ?? null)
                        ? htmlspecialchars(rtrim(rtrim(number_format((float) $tgWalletStatus['balance_ton'], 6, '.', ''), '0'), '.'))
                        : 'نامشخص' ?> TON</code>
                    <?php if (!empty($tgWalletStatus['deposit_address'])): ?>
                        <br>آدرس واریز:
                        <code><?= htmlspecialchars((string) $tgWalletStatus['deposit_address']) ?></code>
                    <?php endif; ?>
                <?php else: ?>
                    <?= htmlspecialchars((string) ($tgWalletStatus['message'] ?? 'دریافت موجودی ناموفق بود.')) ?>
                <?php endif; ?>
                <br><small>
                    اتصال Tonkeeper به سایت به‌تنهایی موجودی API را تأمین نمی‌کند؛ سفارش API از موجودی کیف پول TGTools کسر می‌شود.
                </small>
            </div>
            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>حاشیه سود Stars (%)</label>
                    <input class="input" type="number" name="tgtools_stars_profit_percent" min="0" max="1000" step="0.1"
                        value="<?= htmlspecialchars((string) $tgStarsProfit) ?>" required>
                </div>
                <div class="field">
                    <label>حاشیه سود Premium (%)</label>
                    <input class="input" type="number" name="tgtools_premium_profit_percent" min="0" max="1000" step="0.1"
                        value="<?= htmlspecialchars((string) $tgPremiumProfit) ?>" required>
                </div>
            </div>
            <div class="field">
                <label>نرخ هر 1 TON به تومان</label>
                <input class="input" type="number" name="tgtools_ton_toman_rate" min="0" step="1"
                    value="<?= htmlspecialchars((string) $tgTonRateToman) ?>" placeholder="مثلاً 350000">
                <small class="field-hint">قیمت فروش TGTools = قیمت عمده TON × نرخ تومان × (۱ + درصد سود). در پایان به هزار تومان رو به بالا گرد می‌شود.</small>
            </div>
            <div class="notice notice-info">
                BlueBot بسته‌های Stars و Premium را از <code>/api/purchase/prices</code> می‌خواند، قیمت عمده را دریافت می‌کند و قیمت فروش را خودکار می‌سازد.
                حاشیه سود Stars و Premium مستقل است؛ نیازی به واردکردن قیمت تک‌تک محصولات یا Provider Service Code نیست.
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
                <div class="card-title">عضوینو / OZVinoo</div>
                <div class="card-subtitle">اتصال مستقیم به API رسمی Stars، Premium و شماره مجازی؛ بدون حدس‌زدن endpoint.</div>
            </div>
        </div>
        <form method="post" class="card-body" style="display:grid;gap:12px" data-ozvinoo-sync-form>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ozvinoo">

            <div class="field">
                <label>Base URL</label>
                <input class="input" value="https://api.ozvinoo.xyz" disabled dir="ltr">
            </div>

            <div class="field">
                <label>API Key</label>
                <input class="input" type="password" name="ozvinoo_api_key" autocomplete="new-password"
                    placeholder="<?= $ozApiKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'xxxx-xxxx-xxxx-xxxx' ?>">
                <small class="field-hint">برای درخواست‌های Stars/Premium/Numbers با Bearer استفاده می‌شود؛ موجودی نیز از endpoint رسمی حساب خوانده می‌شود.</small>
            </div>

            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>درصد سود همه محصولات عضوینو</label>
                    <input class="input" type="number" name="ozvinoo_profit_percent" min="0" max="1000" step="0.1"
                        value="<?= htmlspecialchars((string) $ozProfitPercent) ?>" required>
                    <small class="field-hint">روی قیمت عمده Stars، Premium و تمام کشورهای شماره مجازی اعمال می‌شود.</small>
                </div>
                <div class="field">
                    <label>بروزرسانی خودکار (دقیقه)</label>
                    <input class="input" type="number" name="ozvinoo_sync_interval_minutes" min="1" max="1440"
                        value="<?= htmlspecialchars((string) $ozSyncInterval) ?>" required>
                </div>
            </div>

            <div class="notice <?= !empty($ozWalletStatus['ok']) ? 'notice-info' : 'notice-warn' ?>">
                <strong>کیف پول API عضوینو:</strong>
                <?php if (!empty($ozWalletStatus['ok'])): ?>
                    <code><?= number_format((float) ($ozWalletStatus['balance'] ?? 0)) ?> تومان</code>
                <?php else: ?>
                    <?= htmlspecialchars((string) ($ozWalletStatus['message'] ?? 'دریافت موجودی ناموفق بود.')) ?>
                <?php endif; ?>
            </div>

            <div class="notice notice-info">
                <strong>Endpointهای فعال:</strong><br>
                <code>GET /telegram-services/stars/</code> · <code>POST /telegram-services/stars/</code><br>
                <code>GET /telegram-services/premium/</code> · <code>POST /telegram-services/premium/</code><br>
                <code>GET/POST /telegram-numbers/numbers/</code> · <code>GET /telegram-numbers/number-services/</code><br>
                <small>BlueBot دیگر مسیر <code>/api/</code> یا SMM catalog را برای عضوینو probe نمی‌کند.</small>
            </div>

            <div class="notice <?= $ozProductCount > 0 ? 'notice-info' : 'notice-warn' ?>">
                <strong>محصولات فعال عضوینو در ربات:</strong> <?= number_format($ozProductCount) ?>
                <?php if ($ozProductCount === 0): ?>
                    <br><small>پس از ذخیره API Key، محصولات رسمی عضوینو خودکار ساخته و قیمت‌گذاری می‌شوند.</small>
                <?php endif; ?>
            </div>

            <?php if (is_array($ozProvider)): ?>
                <div class="notice <?= in_array((string) ($ozProvider['last_sync_status'] ?? ''), ['success', 'partial'], true) ? 'notice-info' : 'notice-warn' ?>">
                    روش API: <code>OFFICIAL V1</code>
                    · آخرین Sync: <?= htmlspecialchars((string) ($ozProvider['last_sync_at'] ?? '—')) ?>
                    · وضعیت: <?= htmlspecialchars((string) ($ozProvider['last_sync_status'] ?? '—')) ?>
                    <?php if (!empty($ozProvider['last_sync_message'])): ?>
                        <br><small><?= htmlspecialchars((string) $ozProvider['last_sync_message']) ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره + همگام‌سازی رسمی عضوینو</button>
        </form>

        <form method="post" class="card-body" style="padding-top:0" data-ozvinoo-sync-form>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="sync_ozvinoo_catalog">
            <button class="btn btn-ghost" type="submit">↻ بروزرسانی Stars / Premium / شماره‌ها</button>
        </form>
        <script>
        document.querySelectorAll('[data-ozvinoo-sync-form]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"]');
                if (!button || button.disabled) return;
                button.disabled = true;
                button.dataset.originalText = button.textContent || '';
                button.textContent = '⏳ در حال همگام‌سازی API رسمی...';
            });
        });
        </script>
    </div>

<div class="card fade-up d1" id="providers" style="margin-top:16px">
    <div class="card-head">
        <div>
            <div class="card-title">ارائه‌دهندگان و کاتالوگ خودکار</div>
            <div class="card-subtitle">هر Provider را یک‌بار تعریف کنید؛ تمام محصولاتش خودکار وارد ربات، دسته‌بندی و قیمت‌گذاری می‌شوند.</div>
        </div>
    </div>

    <form method="post" class="card-body" style="display:grid;gap:12px">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_provider_catalog">

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>نام ارائه‌دهنده</label>
                <input class="input" name="provider_name" maxlength="190" required placeholder="مثلاً SocialProvider">
            </div>
            <div class="field">
                <label>کلید داخلی</label>
                <input class="input" name="provider_key" maxlength="50" required dir="ltr" placeholder="socialprovider">
                <small class="field-hint">حروف انگلیسی کوچک، عدد، خط تیره یا زیرخط. <code>tgtools</code> رزرو شده است.</small>
            </div>
        </div>

        <div class="field">
            <label>Catalog URL</label>
            <input class="input" name="catalog_url" type="url" required dir="ltr" placeholder="https://provider.example/api/products">
            <small class="field-hint">فقط HTTPS عمومی پذیرفته می‌شود. پاسخ باید JSON باشد.</small>
        </div>

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>API Key (اختیاری)</label>
                <input class="input" type="password" name="provider_api_key" autocomplete="new-password" dir="ltr">
            </div>
            <div class="field">
                <label>حاشیه سود Provider (%)</label>
                <input class="input" type="number" name="profit_percent" min="0" max="1000" step="0.1" required placeholder="20">
            </div>
        </div>

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>Auth Header</label>
                <input class="input" name="provider_auth_header" value="Authorization" dir="ltr">
            </div>
            <div class="field">
                <label>Auth Prefix</label>
                <input class="input" name="provider_auth_prefix" value="Bearer" dir="ltr">
            </div>
        </div>

        <div class="two-col" style="gap:10px">
            <div class="field">
                <label>ارز قیمت عمده</label>
                <select class="select" name="provider_currency" required>
                    <option value="toman">تومان</option>
                    <option value="rial">ریال</option>
                    <option value="usd">USD</option>
                    <option value="ton">TON</option>
                    <option value="other">سایر</option>
                </select>
            </div>
            <div class="field">
                <label>نرخ هر واحد ارز به تومان</label>
                <input class="input" type="number" name="exchange_rate_toman" min="0.000001" step="0.000001" value="1" required>
                <small class="field-hint">برای تومان ۱ و برای ریال ۰.۱ خودکار اعمال می‌شود.</small>
            </div>
        </div>

        <details>
            <summary style="cursor:pointer;font-weight:700">تنظیم ساختار JSON کاتالوگ</summary>
            <div style="display:grid;gap:10px;margin-top:12px">
                <div class="two-col" style="gap:10px">
                    <div class="field">
                        <label>Products path</label>
                        <input class="input" name="products_path" value="auto" dir="ltr" placeholder="data.items">
                    </div>
                    <div class="field">
                        <label>Product ID field</label>
                        <input class="input" name="id_field" value="auto" dir="ltr">
                    </div>
                </div>
                <div class="two-col" style="gap:10px">
                    <div class="field">
                        <label>Name field</label>
                        <input class="input" name="name_field" value="auto" dir="ltr">
                    </div>
                    <div class="field">
                        <label>Category field</label>
                        <input class="input" name="category_field" value="auto" dir="ltr">
                    </div>
                </div>
                <div class="two-col" style="gap:10px">
                    <div class="field">
                        <label>Price field</label>
                        <input class="input" name="price_field" value="auto" dir="ltr">
                    </div>
                    <div class="field">
                        <label>فاصله همگام‌سازی (دقیقه)</label>
                        <input class="input" type="number" name="sync_interval_minutes" min="1" max="1440" value="15">
                    </div>
                </div>
                <small class="field-hint">پیش‌فرض <code>auto</code> است؛ BlueBot ساختار رایج JSON را خودش تشخیص می‌دهد. برای APIهای خاص می‌توانید مسیرهایی مثل <code>service.id</code> وارد کنید.</small>
            </div>
        </details>

        <div class="notice notice-info">
            قیمت فروش تمام محصولات این Provider به‌صورت خودکار از قیمت عمده + درصد سود ساخته می‌شود. محصول حذف‌شده از API نیز در ربات خودکار غیرفعال می‌شود.
        </div>

        <button class="btn btn-primary" type="submit"><?= icon('plus', 14) ?> افزودن/بروزرسانی Provider + همگام‌سازی</button>
    </form>

    <div class="card-body" style="padding-top:0">
        <?php if ($providerCatalogs === []): ?>
            <div class="empty"><p>هنوز Provider عمومی تعریف نشده است.</p></div>
        <?php else: ?>
            <div style="display:grid;gap:10px">
                <?php foreach ($providerCatalogs as $providerCatalog): ?>
                    <div class="notice <?= (int) $providerCatalog['active'] === 1 ? 'notice-info' : 'notice-warn' ?>" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                        <div>
                            <strong><?= htmlspecialchars($providerCatalog['name']) ?></strong>
                            <span class="cell-mono"> · <?= htmlspecialchars($providerCatalog['provider_key']) ?></span>
                            <div class="field-hint" style="margin-top:4px">
                                سود: <?= htmlspecialchars((string) $providerCatalog['profit_percent']) ?>٪
                                · ارز: <?= htmlspecialchars(strtoupper((string) $providerCatalog['currency'])) ?>
                                · آخرین Sync: <?= htmlspecialchars((string) ($providerCatalog['last_sync_at'] ?? '—')) ?>
                                <?php if (!empty($providerCatalog['last_sync_status'])): ?>
                                    · <?= htmlspecialchars((string) $providerCatalog['last_sync_status']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="sync_provider_catalog">
                                <input type="hidden" name="provider_key" value="<?= htmlspecialchars($providerCatalog['provider_key']) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">↻ Sync</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_provider_catalog">
                                <input type="hidden" name="provider_key" value="<?= htmlspecialchars($providerCatalog['provider_key']) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $providerCatalog['active'] === 1 ? 'غیرفعال' : 'فعال' ?></button>
                            </form>
                            <form method="post" data-confirm="ارائه‌دهنده حذف شود؟">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_provider_catalog">
                                <input type="hidden" name="provider_key" value="<?= htmlspecialchars($providerCatalog['provider_key']) ?>">
                                <button class="btn btn-no btn-sm" type="submit"><?= icon('trash', 12) ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
                        <?php
                        $priceMeta = json_decode((string) ($product['metadata'] ?? ''), true);
                        $priceMeta = is_array($priceMeta) ? $priceMeta : [];
                        $isMarginPrice = (($priceMeta['price_mode'] ?? '') === 'margin');
                        ?>
                        <strong><?= number_format((float) $product['price']) ?> تومان</strong>
                        <?php if ($isMarginPrice): ?>
                            <div class="field-hint" style="margin-top:4px">
                                سود: <?= htmlspecialchars((string) ($priceMeta['profit_percent'] ?? '0')) ?>٪
                                <?php if (isset($priceMeta['wholesale_ton'])): ?>
                                    · عمده: <?= htmlspecialchars((string) $priceMeta['wholesale_ton']) ?> TON
                                <?php elseif (isset($priceMeta['wholesale_cost'])): ?>
                                    · عمده: <?= htmlspecialchars((string) $priceMeta['wholesale_cost']) ?>
                                    <?= htmlspecialchars(strtoupper((string) ($priceMeta['wholesale_currency'] ?? ''))) ?>
                                <?php endif; ?>
                            </div>
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
