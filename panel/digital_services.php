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

    if ($action === 'save_tgtools') {
        $apiKey = trim((string) ($_POST['tgtools_api_key'] ?? ''));
        $nobitexPublicKey = trim((string) ($_POST['nobitex_api_public_key'] ?? ''));
        $nobitexPrivateKey = trim((string) ($_POST['nobitex_api_private_key'] ?? ''));
        if ($apiKey !== '' && (strlen($apiKey) > 512 || preg_match('/[\r\n]/', $apiKey))) {
            flash('error', 'API Key واردشده معتبر نیست.');
            header('Location: digital_services.php#tgtools');
            exit;
        }
        foreach ([
            'Nobitex Public Key' => $nobitexPublicKey,
            'Nobitex Private Key' => $nobitexPrivateKey,
        ] as $label => $credential) {
            if ($credential !== '' && (strlen($credential) > 4096 || preg_match('/[\r\n]/', $credential))) {
                flash('error', $label . ' معتبر نیست.');
                header('Location: digital_services.php#tgtools');
                exit;
            }
        }

        $starsProfit = (float) ($_POST['tgtools_stars_profit_percent'] ?? 0);
        $premiumProfit = (float) ($_POST['tgtools_premium_profit_percent'] ?? 0);
        $tradeFeePercent = (float) ($_POST['tgtools_nobitex_trade_fee_percent'] ?? 0.25);
        $networkFeeGram = (float) ($_POST['tgtools_gram_network_fee'] ?? 0.000562);
        $fundingBatchGram = (float) ($_POST['tgtools_gram_funding_batch'] ?? 1);
        $approvalMode = strtolower(trim((string) ($_POST['tgtools_approval_mode'] ?? 'manual')));

        if ($starsProfit < 0 || $starsProfit > 1000 || $premiumProfit < 0 || $premiumProfit > 1000) {
            flash('error', 'درصد سود باید بین ۰ تا ۱۰۰۰ باشد.');
            header('Location: digital_services.php#tgtools');
            exit;
        }
        if (!in_array($approvalMode, ['manual', 'automatic'], true)) {
            flash('error', 'نوع تأیید TGTools معتبر نیست.');
            header('Location: digital_services.php#tgtools');
            exit;
        }
        ds_panel_set_setting($pdo, 'tgtools_base_url', 'https://api.tg-tools.shop');
        ds_panel_set_setting($pdo, 'tgtools_payment_method', 'ton');
        ds_panel_set_setting($pdo, 'nobitex_base_url', 'https://apiv2.nobitex.ir');
        ds_panel_set_setting($pdo, 'tgtools_stars_profit_percent', (string) $starsProfit);
        ds_panel_set_setting($pdo, 'tgtools_premium_profit_percent', (string) $premiumProfit);
        ds_panel_set_setting($pdo, 'tgtools_nobitex_trade_fee_percent', (string) max(0, min(20, $tradeFeePercent)));
        ds_panel_set_setting($pdo, 'tgtools_gram_network_fee', (string) max(0, $networkFeeGram));
        ds_panel_set_setting($pdo, 'tgtools_gram_funding_batch', (string) max(0.000001, $fundingBatchGram));
        if ($apiKey !== '') {
            ds_panel_set_setting($pdo, 'tgtools_api_key', $apiKey, true);
        }
        if ($nobitexPublicKey !== '') {
            ds_panel_set_setting($pdo, 'nobitex_api_public_key', $nobitexPublicKey, true);
        }
        if ($nobitexPrivateKey !== '') {
            ds_panel_set_setting($pdo, 'nobitex_api_private_key', $nobitexPrivateKey, true);
        }
        BluebotDigitalServices::setProviderApprovalMode($pdo, 'tgtools', $approvalMode);

        $rateRefresh = BluebotDigitalServices::refreshTgToolsTonRateFromNobitex($pdo, true, 60);

        // TGTools owns only its own synchronized products. Other providers
        // (for example OZVinoo) may expose the same package sizes and must
        // remain independent.
        $catalog = BluebotDigitalServices::ensureTgToolsCatalog($pdo);
        $catalogMessage = 'تنظیمات TGTools ذخیره شد. محصولات Stars/Premium همگام شدند: '
            . (int) ($catalog['created'] ?? 0) . ' جدید، '
            . (int) ($catalog['updated'] ?? 0) . ' بروزرسانی.';
        if (!empty($catalog['remote_ok'])) {
            $rateMessage = !empty($rateRefresh['ok'])
                ? ' نرخ GRAMIRT نوبیتکس: ' . number_format((float) ($rateRefresh['rate_toman'] ?? 0)) . ' تومان.'
                : ' نرخ قبلی TON حفظ شد؛ دریافت نوبیتکس موقتاً ناموفق بود.';
            flash('success', $catalogMessage . ' قیمت‌های زنده TGTools نیز دریافت شد.' . $rateMessage);
        } else {
            flash('warning', $catalogMessage . ' دریافت قیمت زنده موقتاً ممکن نبود و کاتالوگ جایگزین استفاده شد.');
        }
        header('Location: digital_services.php#tgtools');
        exit;
    }

    if ($action === 'test_nobitex_api_key') {
        $test = BluebotDigitalServices::testNobitexApiKey($pdo);
        if (!empty($test['ok'])) {
            flash('success', 'اتصال API Key نوبیتکس با موفقیت تأیید شد (READ /users/profile).');
        } else {
            flash(
                'warning',
                'تست API Key نوبیتکس ناموفق بود: ' . (string) ($test['message'] ?? 'خطای نامشخص')
            );
        }
        header('Location: digital_services.php#tgtools');
        exit;
    }

    if ($action === 'refresh_tgtools_ton_rate') {
        $rate = BluebotDigitalServices::refreshTgToolsTonRateFromNobitex($pdo, true, 60);
        if (!empty($rate['ok'])) {
            flash(
                'success',
                'نرخ لحظه‌ای GRAMIRT از نوبیتکس دریافت شد: '
                . number_format((float) ($rate['rate_toman'] ?? 0))
                . ' تومان · '
                . number_format((int) ($rate['repriced'] ?? 0))
                . ' محصول TGTools دوباره قیمت‌گذاری شد.'
            );
        } else {
            flash(
                'warning',
                'دریافت نرخ نوبیتکس ناموفق بود؛ نرخ ذخیره‌شده قبلی حفظ شد. '
                . (string) ($rate['message'] ?? '')
            );
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

    if ($action === 'save_tivanovin') {
        $apiKey = trim((string) ($_POST['tivanovin_api_key'] ?? ''));
        $profitPercent = (float) ($_POST['tivanovin_profit_percent'] ?? 0);
        $syncInterval = max(1, min(1440, (int) ($_POST['tivanovin_sync_interval_minutes'] ?? 15)));
        $approvalMode = strtolower(trim((string) ($_POST['tivanovin_approval_mode'] ?? 'manual')));

        if ($apiKey !== '' && (strlen($apiKey) > 2048 || preg_match('/[\r\n]/', $apiKey))) {
            flash('error', 'API Key تیوا نوین معتبر نیست.');
            header('Location: digital_services.php#tivanovin');
            exit;
        }
        if ($profitPercent < 0 || $profitPercent > 1000) {
            flash('error', 'درصد سود تیوا نوین باید بین ۰ تا ۱۰۰۰ باشد.');
            header('Location: digital_services.php#tivanovin');
            exit;
        }

        try {
            BluebotDigitalServices::saveTivaNovinProvider($pdo, $apiKey, $profitPercent, $syncInterval);
            BluebotDigitalServices::setProviderApprovalMode($pdo, 'tivanovin', $approvalMode);
            $sync = BluebotProviderCatalogService::syncProvider($pdo, 'tivanovin');
            $wallet = BluebotDigitalServices::tivaNovinWalletStatus($pdo);

            if (!empty($sync['ok'])) {
                $balanceText = '';
                if (!empty($wallet['ok']) && is_numeric($wallet['balance'] ?? null)) {
                    $balance = (float) $wallet['balance'];
                    $currency = strtoupper((string) ($wallet['currency'] ?? 'IRR'));
                    $balanceText = ' · موجودی: ' . number_format($balance) . ' ' . $currency;
                    if ($currency === 'IRR') {
                        $balanceText .= ' (≈ ' . number_format($balance / 10) . ' تومان)';
                    }
                }

                flash(
                    'success',
                    'تیوا نوین متصل و همگام شد: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال'
                    . $balanceText
                );
            } else {
                flash(
                    'warning',
                    'تنظیمات تیوا نوین ذخیره شد اما دریافت سرویس‌ها ناموفق بود: '
                    . (string) ($sync['message'] ?? 'خطای نامشخص')
                );
            }
        } catch (Throwable $e) {
            flash('error', 'اتصال تیوا نوین انجام نشد: ' . $e->getMessage());
        }

        header('Location: digital_services.php#tivanovin');
        exit;
    }

    if ($action === 'sync_tivanovin_catalog') {
        try {
            $sync = BluebotProviderCatalogService::syncProvider($pdo, 'tivanovin');
            if (!empty($sync['ok'])) {
                flash(
                    'success',
                    'سرویس‌های تیوا نوین بروزرسانی شدند: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال.'
                );
            } else {
                flash('error', 'همگام‌سازی تیوا نوین ناموفق بود: ' . (string) ($sync['message'] ?? 'خطای نامشخص'));
            }
        } catch (Throwable $e) {
            flash('error', 'همگام‌سازی تیوا نوین انجام نشد: ' . $e->getMessage());
        }

        header('Location: digital_services.php#tivanovin');
        exit;
    }

    if ($action === 'save_panelbaz') {
        $apiKey = trim((string) ($_POST['panelbaz_api_key'] ?? ''));
        $profitPercent = (float) ($_POST['panelbaz_profit_percent'] ?? 0);
        $syncInterval = max(1, min(1440, (int) ($_POST['panelbaz_sync_interval_minutes'] ?? 15)));
        $approvalMode = strtolower(trim((string) ($_POST['panelbaz_approval_mode'] ?? 'manual')));

        if ($apiKey !== '' && (strlen($apiKey) > 2048 || preg_match('/[\r\n]/', $apiKey))) {
            flash('error', 'API Key پنل باز معتبر نیست.');
            header('Location: digital_services.php#panelbaz');
            exit;
        }
        if ($profitPercent < 0 || $profitPercent > 1000) {
            flash('error', 'درصد سود پنل باز باید بین ۰ تا ۱۰۰۰ باشد.');
            header('Location: digital_services.php#panelbaz');
            exit;
        }
        if (!in_array($approvalMode, ['manual', 'automatic'], true)) {
            flash('error', 'نوع تأیید پنل باز معتبر نیست.');
            header('Location: digital_services.php#panelbaz');
            exit;
        }

        try {
            BluebotDigitalServices::savePanelBazProvider(
                $pdo,
                $apiKey,
                $profitPercent,
                $syncInterval
            );
            BluebotDigitalServices::setProviderApprovalMode($pdo, 'panelbaz', $approvalMode);
            $sync = BluebotProviderCatalogService::syncProvider($pdo, 'panelbaz');
            $wallet = BluebotDigitalServices::panelBazWalletStatus($pdo);

            if (!empty($sync['ok'])) {
                $balanceText = '';
                if (!empty($wallet['ok']) && is_numeric($wallet['balance'] ?? null)) {
                    $balance = (float) $wallet['balance'];
                    $balanceText = ' · موجودی: ' . number_format($balance) . ' تومان';
                }

                flash(
                    'success',
                    'پنل باز متصل و همگام شد: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال'
                    . $balanceText
                );
            } else {
                flash(
                    'warning',
                    'تنظیمات پنل باز ذخیره شد اما دریافت سرویس‌ها ناموفق بود: '
                    . (string) ($sync['message'] ?? 'خطای نامشخص')
                );
            }
        } catch (Throwable $e) {
            flash('error', 'اتصال پنل باز انجام نشد: ' . $e->getMessage());
        }

        header('Location: digital_services.php#panelbaz');
        exit;
    }

    if ($action === 'sync_panelbaz_catalog') {
        try {
            $sync = BluebotProviderCatalogService::syncProvider($pdo, 'panelbaz');
            if (!empty($sync['ok'])) {
                flash(
                    'success',
                    'سرویس‌های پنل باز بروزرسانی شدند: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال.'
                );
            } else {
                flash('error', 'همگام‌سازی پنل باز ناموفق بود: ' . (string) ($sync['message'] ?? 'خطای نامشخص'));
            }
        } catch (Throwable $e) {
            flash('error', 'همگام‌سازی پنل باز انجام نشد: ' . $e->getMessage());
        }

        header('Location: digital_services.php#panelbaz');
        exit;
    }

    if ($action === 'save_provider_catalog') {
        $requestedApprovalMode = strtolower(trim((string) ($_POST['provider_approval_mode'] ?? 'manual')));
        $providerCatalogMode = strtolower(trim((string) ($_POST['provider_catalog_mode'] ?? 'auto')));
        $providerProductsPath = trim((string) ($_POST['products_path'] ?? 'auto'));
        if ($providerCatalogMode === 'smm-post') {
            $providerProductsPath = 'smm:.';
        } elseif ($providerCatalogMode === 'smm-get') {
            $providerProductsPath = 'smm-get:.';
        } elseif ($providerCatalogMode !== 'auto') {
            flash('error', 'نوع اتصال Provider معتبر نیست.');
            header('Location: digital_services.php#providers');
            exit;
        }

        try {
            $provider = BluebotProviderCatalogService::saveProvider($pdo, [
                'provider_key' => $_POST['provider_key'] ?? '',
                'name' => $_POST['provider_name'] ?? '',
                'catalog_url' => $_POST['catalog_url'] ?? '',
                'api_key' => $_POST['provider_api_key'] ?? '',
                'auth_header' => $_POST['provider_auth_header'] ?? 'Authorization',
                'auth_prefix' => $_POST['provider_auth_prefix'] ?? 'Bearer',
                'products_path' => $providerProductsPath,
                'id_field' => $_POST['id_field'] ?? 'auto',
                'name_field' => $_POST['name_field'] ?? 'auto',
                'category_field' => $_POST['category_field'] ?? 'auto',
                'price_field' => $_POST['price_field'] ?? 'auto',
                'currency' => $_POST['provider_currency'] ?? 'toman',
                'exchange_rate_toman' => $_POST['exchange_rate_toman'] ?? 1,
                'profit_percent' => $_POST['profit_percent'] ?? 0,
                'sync_interval_minutes' => $_POST['sync_interval_minutes'] ?? 15,
            ]);

            $providerKey = (string) ($provider['provider_key'] ?? '');
            $sync = BluebotProviderCatalogService::syncProvider($pdo, $providerKey);
            $approvalWarning = '';
            try {
                BluebotDigitalServices::setProviderApprovalMode($pdo, $providerKey, $requestedApprovalMode);
            } catch (InvalidArgumentException $approvalError) {
                BluebotDigitalServices::setProviderApprovalMode($pdo, $providerKey, 'manual');
                $approvalWarning = ' · حالت ارسال روی دستی باقی ماند: ' . $approvalError->getMessage();
            }

            if (!empty($sync['ok'])) {
                flash(
                    $approvalWarning === '' ? 'success' : 'warning',
                    'ارائه‌دهنده ذخیره و محصولات همگام شدند: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی.'
                    . $approvalWarning
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

    if ($action === 'set_provider_approval_mode') {
        $providerKey = strtolower(trim((string) ($_POST['provider_key'] ?? '')));
        $approvalMode = strtolower(trim((string) ($_POST['approval_mode'] ?? 'manual')));
        try {
            BluebotDigitalServices::setProviderApprovalMode($pdo, $providerKey, $approvalMode);
            flash(
                'success',
                $approvalMode === 'automatic'
                    ? 'ارسال خودکار برای این Provider فعال شد.'
                    : 'تأیید دستی برای این Provider فعال شد.'
            );
        } catch (Throwable $e) {
            flash('error', 'تغییر نوع تأیید انجام نشد: ' . $e->getMessage());
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
        $approvalMode = strtolower(trim((string) ($_POST['ozvinoo_approval_mode'] ?? 'manual')));

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
            BluebotDigitalServices::setProviderApprovalMode($pdo, 'ozvinoo', $approvalMode);
            $sync = BluebotDigitalServices::syncOZVinooCatalog($pdo);
            if (!empty($sync['ok'])) {
                $wallet = BluebotDigitalServices::ozvinooWalletStatus($pdo);
                $balanceText = !empty($wallet['ok']) && is_numeric($wallet['balance'] ?? null)
                    ? ' · موجودی API: ' . number_format((float) $wallet['balance']) . ' تومان'
                    : '';
                $typeCounts = is_array($sync['type_counts'] ?? null) ? $sync['type_counts'] : [];
                $postProbeText = !empty($typeCounts['number_apps_post_attempted'])
                    ? ' / POST ' . (int) ($typeCounts['number_apps_post'] ?? 0)
                    : ' / POST نیاز نشد';
                $catalogText = ' · Stars: ' . (int) ($typeCounts['stars'] ?? 0)
                    . ' · Premium: ' . (int) ($typeCounts['premium'] ?? 0)
                    . ' · پلتفرم شماره: ' . (int) ($typeCounts['number_apps'] ?? 0)
                    . ' (GET ' . (int) ($typeCounts['number_apps_get'] ?? 0) . $postProbeText . ')'
                    . ' · شماره/کشور: ' . (int) ($typeCounts['numbers'] ?? 0);

                flash(
                    'success',
                    'عضوینو با API رسمی همگام شد: '
                    . (int) ($sync['created'] ?? 0) . ' جدید، '
                    . (int) ($sync['updated'] ?? 0) . ' بروزرسانی، '
                    . (int) ($sync['disabled'] ?? 0) . ' غیرفعال. '
                    . 'سود ' . rtrim(rtrim(number_format($profitPercent, 2, '.', ''), '0'), '.') . '٪ اعمال شد'
                    . $catalogText
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
                $typeCounts = is_array($sync['type_counts'] ?? null) ? $sync['type_counts'] : [];
                flash(
                    'success',
                    'کاتالوگ عضوینو بروزرسانی شد: '
                    . 'Stars ' . (int) ($typeCounts['stars'] ?? 0)
                    . ' · Premium ' . (int) ($typeCounts['premium'] ?? 0)
                    . ' · پلتفرم شماره ' . (int) ($typeCounts['number_apps'] ?? 0)
                    . ' (GET ' . (int) ($typeCounts['number_apps_get'] ?? 0)
                    . (!empty($typeCounts['number_apps_post_attempted'])
                        ? ' / POST ' . (int) ($typeCounts['number_apps_post'] ?? 0)
                        : ' / POST نیاز نشد')
                    . ')'
                    . ' · شماره/کشور ' . (int) ($typeCounts['numbers'] ?? 0)
                    . ' · ' . (int) ($sync['created'] ?? 0) . ' جدید'
                    . ' · ' . (int) ($sync['updated'] ?? 0) . ' بروزرسانی'
                    . ' · ' . (int) ($sync['disabled'] ?? 0) . ' غیرفعال'
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

}

$tgApiKey = ds_panel_setting($pdo, 'tgtools_api_key');
$nobitexPublicKey = ds_panel_setting($pdo, 'nobitex_api_public_key');
$nobitexPrivateKey = ds_panel_setting($pdo, 'nobitex_api_private_key');
$tgStarsProfit = (float) ds_panel_setting($pdo, 'tgtools_stars_profit_percent', '0');
$tgPremiumProfit = (float) ds_panel_setting($pdo, 'tgtools_premium_profit_percent', '0');
$tgApprovalMode = BluebotDigitalServices::providerApprovalMode($pdo, 'tgtools');
BluebotDigitalServices::refreshTgToolsTonRateFromNobitex($pdo, false, 60);
$tgTonRateStatus = BluebotDigitalServices::tgToolsTonRateStatus($pdo);
$tgTonRateToman = (float) ($tgTonRateStatus['rate_toman'] ?? 0);
$tgLandedTonRateToman = (float) ($tgTonRateStatus['landed_rate_toman'] ?? 0);
$tgWalletStatus = $tgApiKey !== ''
    ? BluebotDigitalServices::tgToolsWalletStatus($pdo)
    : ['ok' => false, 'configured' => false, 'balance_ton' => null, 'deposit_address' => '', 'message' => 'API Key تنظیم نشده است.'];
$providerCatalogs = array_values(array_filter(
    BluebotProviderCatalogService::listProviders($pdo),
    static fn (array $provider): bool => !in_array(
        strtolower((string) ($provider['provider_key'] ?? '')),
        ['ozvinoo', 'tivanovin', 'panelbaz'],
        true
    )
));
$tivaProvider = BluebotProviderCatalogService::findProvider($pdo, 'tivanovin');
$tivaApiKey = is_array($tivaProvider) ? trim((string) ($tivaProvider['api_key'] ?? '')) : '';
$tivaProfitPercent = is_array($tivaProvider) ? (float) ($tivaProvider['profit_percent'] ?? 0) : 0.0;
$tivaSyncInterval = is_array($tivaProvider) ? max(1, (int) ($tivaProvider['sync_interval_minutes'] ?? 15)) : 15;
$tivaApprovalMode = BluebotDigitalServices::providerApprovalMode($pdo, 'tivanovin');
$tivaProductCountStmt = $pdo->query("SELECT COUNT(*) FROM digital_service_products WHERE provider = 'tivanovin' AND active = 1");
$tivaProductCount = (int) $tivaProductCountStmt->fetchColumn();
$tivaWalletStatus = $tivaApiKey !== ''
    ? BluebotDigitalServices::tivaNovinWalletStatus($pdo)
    : ['ok' => false, 'configured' => false, 'balance' => null, 'currency' => 'IRR', 'message' => 'API Key تنظیم نشده است.'];
$panelBazProvider = BluebotProviderCatalogService::findProvider($pdo, 'panelbaz');
$panelBazApiKey = is_array($panelBazProvider) ? trim((string) ($panelBazProvider['api_key'] ?? '')) : '';
$panelBazProfitPercent = is_array($panelBazProvider) ? (float) ($panelBazProvider['profit_percent'] ?? 0) : 0.0;
$panelBazSyncInterval = is_array($panelBazProvider) ? max(1, (int) ($panelBazProvider['sync_interval_minutes'] ?? 15)) : 15;
$panelBazApprovalMode = BluebotDigitalServices::providerApprovalMode($pdo, 'panelbaz');
$panelBazProductCountStmt = $pdo->query("SELECT COUNT(*) FROM digital_service_products WHERE provider = 'panelbaz' AND active = 1");
$panelBazProductCount = (int) $panelBazProductCountStmt->fetchColumn();
$panelBazWalletStatus = $panelBazApiKey !== ''
    ? BluebotDigitalServices::panelBazWalletStatus($pdo)
    : ['ok' => false, 'configured' => false, 'balance' => null, 'currency' => 'TOMAN', 'message' => 'API Key تنظیم نشده است.'];
$ozApiKey = ds_panel_setting($pdo, 'ozvinoo_api_key');
$ozProfitPercent = (float) ds_panel_setting($pdo, 'ozvinoo_profit_percent', '0');
$ozSyncInterval = (int) ds_panel_setting($pdo, 'ozvinoo_sync_interval_minutes', '15');
$ozApprovalMode = BluebotDigitalServices::providerApprovalMode($pdo, 'ozvinoo');
$ozProvider = BluebotProviderCatalogService::findProvider($pdo, 'ozvinoo');
$ozProductCountStmt = $pdo->query("SELECT COUNT(*) FROM digital_service_products WHERE provider = 'ozvinoo' AND active = 1");
$ozProductCount = (int) $ozProductCountStmt->fetchColumn();
$digitalServicesMenuEnabled = BluebotDigitalServices::mainKeyboardHasDigitalServices($pdo);
$ozWalletStatus = $ozApiKey !== ''
    ? BluebotDigitalServices::ozvinooWalletStatus($pdo)
    : ['ok' => false, 'configured' => false, 'balance' => null, 'message' => 'API Key تنظیم نشده است.'];

$pageTitle = 'فروش خدمات';
$pageLede = 'مدیریت APIها، موجودی Providerها، درصد سود و همگام‌سازی کاتالوگ';
$activeNav = 'digital-services';
include __DIR__ . '/inc/layout_head.php';
?>
<div class="card fade-up" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">مدیریت فروش خدمات</div>
            <div class="card-subtitle">این صفحه فقط برای اتصال API، موجودی Providerها، درصد سود و همگام‌سازی است.</div>
        </div>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
        <a href="service.php?scope=digital" class="btn btn-ghost" style="justify-content:center;padding:14px">📦 مدیریت سرویس‌ها</a>
        <a href="invoice.php?scope=digital" class="btn btn-ghost" style="justify-content:center;padding:14px">🧾 مدیریت سفارش‌ها</a>
        <a href="category.php?scope=digital" class="btn btn-ghost" style="justify-content:center;padding:14px">🗂 مدیریت دسته‌بندی‌ها</a>
    </div>
</div>

<div class="two-col">
<div class="card fade-up d1" id="tgtools">
        <div class="card-head">
            <div>
                <div class="card-title">TGTools API</div>
                <div class="card-subtitle">فروش Stars و Premium با انتخاب مستقل تأیید دستی یا ارسال خودکار؛ وضعیت سفارش خودکار پیگیری می‌شود.</div>
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
            <?php
            $tgWalletBalanceText = is_numeric($tgWalletStatus['balance_ton'] ?? null)
                ? rtrim(rtrim(number_format((float) $tgWalletStatus['balance_ton'], 6, '.', ''), '0'), '.') . ' TON'
                : 'نامشخص';
            $tgWalletAddress = trim((string) ($tgWalletStatus['deposit_address'] ?? ''));
            ?>
            <div class="card" data-persistent-tgtools-wallet
                style="display:grid;gap:12px;padding:16px;background:var(--sf2);border-color:<?= !empty($tgWalletStatus['ok']) ? 'var(--bds)' : 'var(--warn)' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                    <strong>💎 کیف پول TGTools</strong>
                    <a class="btn btn-ghost btn-sm" href="digital_services.php#tgtools">↻ بروزرسانی موجودی</a>
                </div>

                <?php if (!empty($tgWalletStatus['ok'])): ?>
                    <div class="two-col" style="gap:10px">
                        <div class="field">
                            <label>موجودی TGTools</label>
                            <input class="input" type="text"
                                value="<?= htmlspecialchars($tgWalletBalanceText) ?>"
                                readonly dir="ltr">
                            <small class="field-hint">موجودی واقعی کیف پول API که سفارش‌های TGTools از آن کسر می‌شوند.</small>
                        </div>
                        <div class="field">
                            <label>آدرس کیف پول / واریز TGTools</label>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input class="input" type="text"
                                    value="<?= htmlspecialchars($tgWalletAddress !== '' ? $tgWalletAddress : 'آدرس از API دریافت نشد') ?>"
                                    readonly dir="ltr" data-tgtools-wallet-address>
                                <?php if ($tgWalletAddress !== ''): ?>
                                    <button class="btn btn-ghost btn-sm" type="button"
                                        data-copy-tgtools-wallet
                                        data-wallet-address="<?= htmlspecialchars($tgWalletAddress) ?>">📋 کپی</button>
                                <?php endif; ?>
                            </div>
                            <small class="field-hint">برای شارژ حساب TGTools از همین آدرس واریز استفاده کنید.</small>
                        </div>
                    </div>
                <?php else: ?>
                    <div>
                        <?= htmlspecialchars((string) ($tgWalletStatus['message'] ?? 'دریافت اطلاعات کیف پول TGTools ناموفق بود.')) ?>
                    </div>
                <?php endif; ?>

                <small>
                    اتصال Tonkeeper به سایت به‌تنهایی موجودی API را تأمین نمی‌کند؛ سفارش API از موجودی کیف پول TGTools کسر می‌شود.
                </small>
            </div>
            <script>
            document.querySelectorAll('[data-copy-tgtools-wallet]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    var address = button.getAttribute('data-wallet-address') || '';
                    if (!address) return;
                    try {
                        await navigator.clipboard.writeText(address);
                        var original = button.textContent;
                        button.textContent = '✅ کپی شد';
                        setTimeout(function () { button.textContent = original; }, 1400);
                    } catch (error) {
                        var input = document.querySelector('[data-tgtools-wallet-address]');
                        if (input) {
                            input.focus();
                            input.select();
                        }
                    }
                });
            });
            </script>
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
                <label>نوع تأیید و ارسال سفارش</label>
                <select class="select" name="tgtools_approval_mode">
                    <option value="manual" <?= $tgApprovalMode === 'manual' ? 'selected' : '' ?>>🛡️ دستی — ادمین تأیید و ارسال کند</option>
                    <option value="automatic" <?= $tgApprovalMode === 'automatic' ? 'selected' : '' ?>>⚡ خودکار — بلافاصله بعد از پرداخت ارسال شود</option>
                </select>
                <small class="field-hint">این تنظیم فقط برای TGTools است و روی Providerهای دیگر اثری ندارد.</small>
            </div>
            <div class="field">
                <label>نرخ لحظه‌ای TON به تومان</label>
                <input class="input" type="text"
                    value="<?= $tgTonRateToman > 0 ? htmlspecialchars(number_format($tgTonRateToman) . ' تومان') : 'در انتظار دریافت نرخ...' ?>"
                    readonly dir="ltr">
                <small class="field-hint">
                    منبع: <strong>Nobitex</strong> · بازار <code>GRAMIRT</code> ·
                    بروزرسانی خودکار هر ۱ دقیقه.
                    <?php if ((int) ($tgTonRateStatus['last_sync'] ?? 0) > 0): ?>
                        آخرین دریافت: <?= htmlspecialchars(date('Y/m/d H:i:s', (int) $tgTonRateStatus['last_sync'])) ?>
                    <?php endif; ?>
                </small>
                <?php if (!empty($tgTonRateStatus['last_error'])): ?>
                    <small class="field-hint" style="color:var(--danger)">
                        آخرین خطا: <?= htmlspecialchars((string) $tgTonRateStatus['last_error']) ?> · نرخ قبلی حفظ شده است.
                    </small>
                <?php endif; ?>
            </div>
            <div class="notice notice-info">
                <strong>Nobitex API</strong><br>
                <small>
                    نرخ GRAM و <code>/v2/options</code> عمومی هستند و بدون کلید کار می‌کنند.
                    API Key اختیاری است و برای اتصال احراز‌شده/آماده‌سازی قابلیت‌های حساب استفاده می‌شود.
                    Base URL رسمی: <code>https://apiv2.nobitex.ir</code>
                </small>
            </div>
            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>Nobitex Public Key</label>
                    <input class="input" type="password" name="nobitex_api_public_key" autocomplete="new-password"
                        placeholder="<?= $nobitexPublicKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'Nobitex-Key' ?>">
                    <small class="field-hint">کلید عمومی فیلد <code>key</code>. برای تست اتصال، مجوز <code>READ</code> کافی است.</small>
                </div>
                <div class="field">
                    <label>Nobitex Private Key</label>
                    <input class="input" type="password" name="nobitex_api_private_key" autocomplete="new-password"
                        placeholder="<?= $nobitexPrivateKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'privateKey (Base64 URL-safe)' ?>">
                    <small class="field-hint">کلید خصوصی Ed25519 که هنگام ساخت API Key فقط یک‌بار نمایش داده می‌شود.</small>
                </div>
            </div>
            <div class="notice notice-info">
                <strong>نرخ تمام‌شده GRAM برای TGTools:</strong>
                <code><?= $tgLandedTonRateToman > 0 ? htmlspecialchars(number_format($tgLandedTonRateToman)) . ' تومان' : '—' ?></code>
                <br><small>شامل کارمزد معامله نوبیتکس + کارمزد برداشت GRAM از نوبیتکس + کارمزد انتقال Tonkeeper تا کیف پول TGTools.</small>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
                <div class="field">
                    <label>کارمزد بازار تومانی نوبیتکس (%)</label>
                    <input class="input" type="number" name="tgtools_nobitex_trade_fee_percent" min="0" max="20" step="0.001"
                        value="<?= htmlspecialchars((string) ($tgTonRateStatus['trade_fee_percent'] ?? 0.25)) ?>">
                    <small class="field-hint">وابسته به سطح کاربری نوبیتکس؛ برای سطح پایه ۰٫۲۵٪ است.</small>
                </div>
                <div class="field">
                    <label>کارمزد برداشت GRAM از نوبیتکس</label>
                    <input class="input" type="text"
                        value="<?= htmlspecialchars((string) ($tgTonRateStatus['nobitex_withdraw_fee_gram'] ?? 0.1) . ' GRAM') ?>"
                        readonly dir="ltr">
                    <small class="field-hint">
                        دریافت خودکار از <code>/v2/options</code> · حداقل برداشت:
                        <?= htmlspecialchars((string) ($tgTonRateStatus['nobitex_withdraw_min_gram'] ?? 0.2)) ?> GRAM
                        <?php if ((int) ($tgTonRateStatus['nobitex_withdraw_fee_last_sync'] ?? 0) > 0): ?>
                            · آخرین بروزرسانی <?= htmlspecialchars(date('Y/m/d H:i:s', (int) $tgTonRateStatus['nobitex_withdraw_fee_last_sync'])) ?>
                        <?php endif; ?>
                    </small>
                    <?php if (!empty($tgTonRateStatus['nobitex_withdraw_fee_last_error'])): ?>
                        <small class="field-hint" style="color:var(--danger)">
                            آخرین خطا: <?= htmlspecialchars((string) $tgTonRateStatus['nobitex_withdraw_fee_last_error']) ?> · مقدار قبلی حفظ شده است.
                        </small>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label>کارمزد انتقال Tonkeeper → TGTools (GRAM)</label>
                    <input class="input" type="number" name="tgtools_gram_network_fee" min="0" step="0.000001"
                        value="<?= htmlspecialchars((string) ($tgTonRateStatus['network_fee_gram'] ?? 0.000562)) ?>">
                    <small class="field-hint">کارمزد شبکه‌ای که در انتقال نهایی کیف پول پرداخت می‌شود.</small>
                </div>
                <div class="field">
                    <label>حجم هر شارژ TGTools (GRAM)</label>
                    <input class="input" type="number" name="tgtools_gram_funding_batch" min="0.000001" step="0.000001"
                        value="<?= htmlspecialchars((string) ($tgTonRateStatus['funding_batch_gram'] ?? 1)) ?>">
                    <small class="field-hint">کارمزدهای ثابت برداشت و انتقال روی این مقدار سرشکن می‌شوند.</small>
                </div>
            </div>
            <div class="notice notice-info">
                BlueBot بسته‌های Stars و Premium را از <code>/api/purchase/prices</code> می‌خواند، قیمت عمده را دریافت می‌کند و قیمت فروش را خودکار می‌سازد.
                حاشیه سود Stars و Premium مستقل است؛ نیازی به واردکردن قیمت تک‌تک محصولات یا Provider Service Code نیست.
            </div>
            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره TGTools</button>
        </form>
        <div class="card-body" style="padding-top:0;display:flex;gap:8px;flex-wrap:wrap">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="refresh_tgtools_ton_rate">
                <button class="btn btn-ghost" type="submit">↻ دریافت نرخ لحظه‌ای نوبیتکس</button>
            </form>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="test_nobitex_api_key">
                <button class="btn btn-ghost" type="submit">🔐 تست API Key نوبیتکس</button>
            </form>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="sync_tgtools_catalog">
                <button class="btn btn-ghost" type="submit">↻ ساخت/همگام‌سازی خودکار محصولات</button>
            </form>
        </div>
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
                    <small class="field-hint">روی قیمت عمده Stars، Premium و همه پلتفرم‌ها/کشورهای شماره مجازی Callinoo اعمال می‌شود.</small>
                </div>
                <div class="field">
                    <label>بروزرسانی خودکار (دقیقه)</label>
                    <input class="input" type="number" name="ozvinoo_sync_interval_minutes" min="1" max="1440"
                        value="<?= htmlspecialchars((string) $ozSyncInterval) ?>" required>
                </div>
            </div>
            <div class="field">
                <label>نوع تأیید و ارسال سفارش</label>
                <select class="select" name="ozvinoo_approval_mode">
                    <option value="manual" <?= $ozApprovalMode === 'manual' ? 'selected' : '' ?>>🛡️ دستی — ادمین تأیید و ارسال کند</option>
                    <option value="automatic" <?= $ozApprovalMode === 'automatic' ? 'selected' : '' ?>>⚡ خودکار — بلافاصله بعد از پرداخت ارسال شود</option>
                </select>
                <small class="field-hint">انتخاب مستقل عضوینو؛ در حالت خودکار سفارش بدون دکمه تأیید ادمین به API ارسال می‌شود.</small>
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

            <div class="notice <?= $digitalServicesMenuEnabled ? 'notice-info' : 'notice-warn' ?>">
                <strong>نمایش فروش خدمات در منوی ربات:</strong>
                <?= $digitalServicesMenuEnabled ? '✅ فعال' : '⚠️ غیرفعال' ?>
                <?php if (!$digitalServicesMenuEnabled): ?>
                    <br><small>در نسخه‌های ارتقایافته، مهاجرت خودکار منو با اولین پیام کاربر انجام می‌شود.</small>
                <?php endif; ?>
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

    <div class="card fade-up d1" id="tivanovin">
        <div class="card-head">
            <div>
                <div class="card-title">TivaNovin / تیوا نوین</div>
                <div class="card-subtitle">اتصال کامل SMM API برای دریافت سرویس‌ها، ثبت سفارش، پیگیری وضعیت و موجودی کیف پول.</div>
            </div>
        </div>

        <form method="post" class="card-body" style="display:grid;gap:12px">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_tivanovin">

            <div class="field">
                <label>API Endpoint</label>
                <input class="input" value="http://tivanovin.ir/api" disabled dir="ltr">
                <small class="field-hint">طبق مستندات خود پنل. BlueBot دسترسی HTTP را فقط به همین دامنه و مسیر محدود کرده است.</small>
            </div>

            <div class="field">
                <label>API Key</label>
                <input class="input" type="password" name="tivanovin_api_key" autocomplete="new-password" dir="ltr"
                    placeholder="<?= $tivaApiKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'کلید API تیوا نوین' ?>">
                <small class="field-hint">کلید فقط سمت سرور ذخیره می‌شود و در ربات یا سفارش کاربر نمایش داده نمی‌شود.</small>
            </div>

            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>درصد سود همه سرویس‌ها</label>
                    <input class="input" type="number" name="tivanovin_profit_percent" min="0" max="1000" step="0.1"
                        value="<?= htmlspecialchars((string) $tivaProfitPercent) ?>" required>
                    <small class="field-hint">قیمت API به‌صورت ریال خوانده و به تومان تبدیل می‌شود؛ سپس این درصد سود اعمال می‌شود.</small>
                </div>
                <div class="field">
                    <label>بروزرسانی خودکار (دقیقه)</label>
                    <input class="input" type="number" name="tivanovin_sync_interval_minutes" min="1" max="1440"
                        value="<?= htmlspecialchars((string) $tivaSyncInterval) ?>" required>
                </div>
            </div>
            <div class="field">
                <label>نوع تأیید و ارسال سفارش</label>
                <select class="select" name="tivanovin_approval_mode">
                    <option value="manual" <?= $tivaApprovalMode === 'manual' ? 'selected' : '' ?>>🛡️ دستی — ادمین تأیید و ارسال کند</option>
                    <option value="automatic" <?= $tivaApprovalMode === 'automatic' ? 'selected' : '' ?>>⚡ خودکار — بلافاصله بعد از پرداخت ارسال شود</option>
                </select>
                <small class="field-hint">در حالت خودکار، سفارش مستقیماً با <code>add</code> ثبت و سپس با <code>status</code> پیگیری می‌شود.</small>
            </div>

            <div class="notice <?= !empty($tivaWalletStatus['ok']) ? 'notice-info' : 'notice-warn' ?>">
                <strong>کیف پول API تیوا نوین:</strong>
                <?php if (!empty($tivaWalletStatus['ok'])): ?>
                    <?php
                    $tivaBalance = (float) ($tivaWalletStatus['balance'] ?? 0);
                    $tivaCurrency = strtoupper((string) ($tivaWalletStatus['currency'] ?? 'IRR'));
                    ?>
                    <code><?= number_format($tivaBalance) ?> <?= htmlspecialchars($tivaCurrency) ?></code>
                    <?php if ($tivaCurrency === 'IRR'): ?>
                        · حدود <code><?= number_format($tivaBalance / 10) ?> تومان</code>
                    <?php endif; ?>
                <?php else: ?>
                    <?= htmlspecialchars((string) ($tivaWalletStatus['message'] ?? 'دریافت موجودی ناموفق بود.')) ?>
                <?php endif; ?>
            </div>

            <div class="notice <?= $tivaProductCount > 0 ? 'notice-info' : 'notice-warn' ?>">
                <strong>سرویس‌های فعال تیوا نوین در ربات:</strong> <?= number_format($tivaProductCount) ?>
                <br><small>
                    <code>services</code> برای کاتالوگ · <code>add</code> برای سفارش ·
                    <code>status</code> برای پیگیری · <code>balance</code> برای کیف پول.
                    نوع تأیید فعلی: <strong><?= $tivaApprovalMode === 'automatic' ? '⚡ خودکار' : '🛡️ دستی' ?></strong>.
                </small>
            </div>

            <?php if (is_array($tivaProvider)): ?>
                <div class="notice <?= (string) ($tivaProvider['last_sync_status'] ?? '') === 'success' ? 'notice-info' : 'notice-warn' ?>">
                    آخرین Sync: <?= htmlspecialchars((string) ($tivaProvider['last_sync_at'] ?? '—')) ?>
                    · وضعیت: <?= htmlspecialchars((string) ($tivaProvider['last_sync_status'] ?? '—')) ?>
                    <?php if (!empty($tivaProvider['last_sync_message'])): ?>
                        <br><small><?= htmlspecialchars((string) $tivaProvider['last_sync_message']) ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره + اتصال + همگام‌سازی تیوا نوین</button>
        </form>

        <form method="post" class="card-body" style="padding-top:0">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="sync_tivanovin_catalog">
            <button class="btn btn-ghost" type="submit">↻ بروزرسانی سرویس‌های تیوا نوین</button>
        </form>
    </div>

    <div class="card fade-up d1" id="panelbaz">
        <div class="card-head">
            <div>
                <div class="card-title">PanelBaz / پنل باز</div>
                <div class="card-subtitle">اتصال SMM API برای دریافت سرویس‌ها، قیمت‌گذاری مستقیم با تومان، سفارش خودکار/دستی، پیگیری وضعیت و موجودی.</div>
            </div>
        </div>

        <form method="post" class="card-body" style="display:grid;gap:12px">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_panelbaz">

            <div class="field">
                <label>API Endpoint</label>
                <input class="input" value="https://panelbaz.ir/panelbaz/api/v1" disabled dir="ltr">
                <small class="field-hint">اتصال با POST و کلید API در بدنه درخواست انجام می‌شود.</small>
            </div>

            <div class="field">
                <label>API Key</label>
                <input class="input" type="password" name="panelbaz_api_key" autocomplete="new-password" dir="ltr"
                    placeholder="<?= $panelBazApiKey !== '' ? '•••••••• (ذخیره شده؛ برای تغییر وارد کنید)' : 'کلید API پنل باز' ?>">
                <small class="field-hint">کلید را از ویرایش پروفایل PanelBaz بسازید؛ فقط سمت سرور ذخیره می‌شود.</small>
            </div>

            <div class="field">
                <label>درصد سود همه سرویس‌ها</label>
                <input class="input" type="number" name="panelbaz_profit_percent" min="0" max="1000" step="0.1"
                    value="<?= htmlspecialchars((string) $panelBazProfitPercent) ?>" required>
                <small class="field-hint">قیمت <code>rate</code> پنل باز مستقیماً تومان است؛ فقط درصد سود روی قیمت پایه اعمال می‌شود.</small>
            </div>

            <div class="two-col" style="gap:10px">
                <div class="field">
                    <label>بروزرسانی خودکار (دقیقه)</label>
                    <input class="input" type="number" name="panelbaz_sync_interval_minutes" min="1" max="1440"
                        value="<?= htmlspecialchars((string) $panelBazSyncInterval) ?>" required>
                </div>
                <div class="field">
                    <label>نوع تأیید و ارسال سفارش</label>
                    <select class="select" name="panelbaz_approval_mode">
                        <option value="manual" <?= $panelBazApprovalMode === 'manual' ? 'selected' : '' ?>>🛡️ دستی — ادمین تأیید و ارسال کند</option>
                        <option value="automatic" <?= $panelBazApprovalMode === 'automatic' ? 'selected' : '' ?>>⚡ خودکار — بلافاصله بعد از پرداخت ارسال شود</option>
                    </select>
                </div>
            </div>

            <div class="card" data-persistent-panelbaz-wallet style="padding:14px;background:var(--sf2)">
                <strong>💰 موجودی API پنل باز</strong>
                <div style="margin-top:8px">
                    <?php if (!empty($panelBazWalletStatus['ok'])): ?>
                        <?php
                        $panelBazBalance = (float) ($panelBazWalletStatus['balance'] ?? 0);
                        ?>
                        <code><?= number_format($panelBazBalance) ?> تومان</code>
                    <?php else: ?>
                        <?= htmlspecialchars((string) ($panelBazWalletStatus['message'] ?? 'دریافت موجودی ناموفق بود.')) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="padding:14px;background:var(--sf2)">
                <strong>📦 سرویس‌های فعال PanelBaz در ربات:</strong> <?= number_format($panelBazProductCount) ?>
                <br><small class="field-hint">
                    <code>services</code> کاتالوگ · <code>add</code> سفارش · <code>status</code> پیگیری ·
                    <code>balance</code> موجودی · هسته SMM همچنین <code>refill</code>، <code>refill_status</code> و <code>cancel</code> را پشتیبانی می‌کند.
                    نوع تأیید فعلی: <strong><?= $panelBazApprovalMode === 'automatic' ? '⚡ خودکار' : '🛡️ دستی' ?></strong>.
                </small>
            </div>

            <?php if (is_array($panelBazProvider)): ?>
                <div class="card" style="padding:14px;background:var(--sf2)">
                    آخرین Sync: <?= htmlspecialchars((string) ($panelBazProvider['last_sync_at'] ?? '—')) ?>
                    · وضعیت: <?= htmlspecialchars((string) ($panelBazProvider['last_sync_status'] ?? '—')) ?>
                    <?php if (!empty($panelBazProvider['last_sync_message'])): ?>
                        <br><small class="field-hint"><?= htmlspecialchars((string) $panelBazProvider['last_sync_message']) ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <button class="btn btn-primary" type="submit"><?= icon('check', 14) ?> ذخیره + اتصال + همگام‌سازی PanelBaz</button>
        </form>

        <form method="post" class="card-body" style="padding-top:0">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="sync_panelbaz_catalog">
            <button class="btn btn-ghost" type="submit">↻ بروزرسانی سرویس‌های PanelBaz</button>
        </form>
    </div>

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
                <small class="field-hint">حروف انگلیسی کوچک، عدد، خط تیره یا زیرخط. <code>tgtools</code>، <code>tivanovin</code> و <code>panelbaz</code> رزرو شده‌اند.</small>
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
        <div class="field">
            <label>نوع تأیید و ارسال سفارش</label>
            <select class="select" name="provider_approval_mode">
                <option value="manual" selected>🛡️ دستی — ابتدا تأیید ادمین</option>
                <option value="automatic">⚡ خودکار — ارسال مستقیم بعد از پرداخت</option>
            </select>
            <small class="field-hint">خودکار فقط برای Providerهای SMM سازگار با <code>add/status</code> فعال می‌شود؛ در غیر این صورت BlueBot آن را روی دستی نگه می‌دارد.</small>
        </div>

        <div class="field">
            <label>روش اتصال API / کاتالوگ</label>
            <select class="select" name="provider_catalog_mode">
                <option value="auto" selected>🔎 خودکار / REST JSON</option>
                <option value="smm-post">📨 SMM استاندارد POST — پیشنهادشده</option>
                <option value="smm-get">🧩 SMM قدیمی GET — سازگار با پنل‌های V8</option>
            </select>
            <small class="field-hint">
                حالت GET فقط برای پنل‌های قدیمی که <code>?key=...&amp;action=services</code> می‌خواهند استفاده شود.
                چون کلید API در Query String قرار می‌گیرد، برای Providerهای جدید همیشه POST را ترجیح دهید.
            </small>
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
                <small class="field-hint">
                    در حالت خودکار، BlueBot ساختار JSON را تشخیص می‌دهد. انتخاب SMM POST/GET در بالا مسیر را به
                    <code>smm:.</code> یا <code>smm-get:.</code> تبدیل می‌کند؛ لازم نیست این مقدارها را دستی بنویسید.
                </small>
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
                    <?php
                    $providerKey = strtolower((string) ($providerCatalog['provider_key'] ?? ''));
                    $providerApprovalMode = BluebotDigitalServices::providerApprovalMode($pdo, $providerKey);
                    $providerAutoCapable = BluebotDigitalServices::providerSupportsAutomaticDelivery($pdo, $providerKey);
                    $providerProductsPath = (string) ($providerCatalog['products_path'] ?? '');
                    $providerTransportLabel = str_starts_with($providerProductsPath, 'smm-get:')
                        ? 'SMM GET (Legacy)'
                        : (str_starts_with($providerProductsPath, 'smm:') ? 'SMM POST' : 'REST/JSON');
                    ?>
                    <div class="notice <?= (int) $providerCatalog['active'] === 1 ? 'notice-info' : 'notice-warn' ?>" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                        <div>
                            <strong><?= htmlspecialchars($providerCatalog['name']) ?></strong>
                            <span class="cell-mono"> · <?= htmlspecialchars($providerCatalog['provider_key']) ?></span>
                            <div class="field-hint" style="margin-top:4px">
                                سود: <?= htmlspecialchars((string) $providerCatalog['profit_percent']) ?>٪
                                · ارز: <?= htmlspecialchars(strtoupper((string) $providerCatalog['currency'])) ?>
                                · API: <strong><?= htmlspecialchars($providerTransportLabel) ?></strong>
                                · تأیید: <strong><?= $providerApprovalMode === 'automatic' ? '⚡ خودکار' : '🛡️ دستی' ?></strong>
                                · آخرین Sync: <?= htmlspecialchars((string) ($providerCatalog['last_sync_at'] ?? '—')) ?>
                                <?php if (!empty($providerCatalog['last_sync_status'])): ?>
                                    · <?= htmlspecialchars((string) $providerCatalog['last_sync_status']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <?php if ($providerAutoCapable): ?>
                                <form method="post">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="set_provider_approval_mode">
                                    <input type="hidden" name="provider_key" value="<?= htmlspecialchars($providerKey) ?>">
                                    <input type="hidden" name="approval_mode" value="<?= $providerApprovalMode === 'automatic' ? 'manual' : 'automatic' ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= $providerApprovalMode === 'automatic' ? '🛡️ تغییر به دستی' : '⚡ تغییر به خودکار' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="btn btn-ghost btn-sm" style="opacity:.55;cursor:not-allowed" title="این Provider مسیر ارسال خودکار سازگار ندارد">🛡️ فقط دستی</span>
                            <?php endif; ?>
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


<?php include __DIR__ . '/inc/layout_foot.php'; ?>