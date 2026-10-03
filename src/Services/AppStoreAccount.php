<?php

declare(strict_types=1);

final class AppStoreAccount
{
    private const SESSION_TTL = 2592000;
    private static bool $ready = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$ready) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_mobile_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id VARCHAR(64) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            linked_telegram_id VARCHAR(64) NULL,
            verified_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_app_mobile_phone (phone),
            UNIQUE KEY uq_app_mobile_user (user_id),
            KEY idx_app_mobile_telegram (linked_telegram_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_mobile_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_app_mobile_token (token_hash),
            KEY idx_app_mobile_account (account_id),
            KEY idx_app_mobile_expiry (expires_at),
            CONSTRAINT fk_app_mobile_account
                FOREIGN KEY (account_id) REFERENCES app_mobile_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::$ready = true;
    }

    public static function requestOtp(PDO $pdo, string $phone, string $deviceId): array
    {
        self::ensureSchema($pdo);
        self::validateDevice($deviceId);

        if (!class_exists('BluebotSms')) {
            throw new RuntimeException('SMS service is unavailable');
        }

        $phone = BluebotSms::normalizePhone($phone);
        if ($phone === '') {
            throw new InvalidArgumentException('شماره موبایل معتبر نیست.');
        }

        $challengeUserId = self::challengeUserId($phone, $deviceId);
        $result = BluebotSms::requestPhoneOtp($challengeUserId, $phone);

        return [
            'phone' => $phone,
            'expires_in' => (int) ($result['expires_in'] ?? 120),
            'resend_after' => (int) ($result['resend_after'] ?? 60),
        ];
    }

    public static function verifyOtp(
        PDO $pdo,
        string $phone,
        string $code,
        string $deviceId
    ): array {
        self::ensureSchema($pdo);
        self::validateDevice($deviceId);

        if (!class_exists('BluebotSms')) {
            throw new RuntimeException('SMS service is unavailable');
        }

        $phone = BluebotSms::normalizePhone($phone);
        if ($phone === '') {
            throw new InvalidArgumentException('شماره موبایل معتبر نیست.');
        }

        $verified = BluebotSms::verifyPhoneOtp(
            self::challengeUserId($phone, $deviceId),
            $code
        );
        $verifiedPhone = BluebotSms::normalizePhone((string) ($verified['phone'] ?? ''));
        if ($verifiedPhone === '' || !hash_equals($phone, $verifiedPhone)) {
            throw new RuntimeException('تأیید شماره موبایل نامعتبر است.');
        }

        $userId = self::resolveOrCreateUser($pdo, $phone);
        $account = self::upsertAccount($pdo, $userId, $phone);
        $user = self::user($pdo, $userId);

        if (!is_array($user) || strtolower((string) ($user['User_Status'] ?? '')) === 'block') {
            throw new RuntimeException('حساب کاربری در دسترس نیست.');
        }

        return self::issueSession($pdo, $account, $user, $deviceId);
    }

    public static function authorize(PDO $pdo, string $token, string $deviceId): array
    {
        self::ensureSchema($pdo);
        self::validateDevice($deviceId);

        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Authentication required');
        }

        $stmt = $pdo->prepare(
            "SELECT
                s.id AS session_id,
                s.device_hash,
                a.id AS account_id,
                a.user_id,
                a.phone,
                a.linked_telegram_id
             FROM app_mobile_sessions s
             JOIN app_mobile_accounts a ON a.id = s.account_id
             WHERE s.token_hash = ?
               AND s.revoked_at IS NULL
               AND s.expires_at > UTC_TIMESTAMP()
             LIMIT 1"
        );
        $stmt->execute([
            hash('sha256', $token),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($row)
            || !hash_equals(
                (string) ($row['device_hash'] ?? ''),
                hash('sha256', trim($deviceId))
            )
        ) {
            throw new RuntimeException('Authentication required');
        }

        $user = self::user($pdo, (string) $row['user_id']);
        if (!is_array($user) || strtolower((string) ($user['User_Status'] ?? '')) === 'block') {
            throw new RuntimeException('Authentication required');
        }

        $pdo->prepare("UPDATE app_mobile_sessions SET last_seen_at=? WHERE id=?")
            ->execute([self::now(), (int) $row['session_id']]);

        return [
            'session_id' => (int) $row['session_id'],
            'account_id' => (int) $row['account_id'],
            'user_id' => (string) $row['user_id'],
            'phone' => (string) $row['phone'],
            'linked_telegram_id' => (string) ($row['linked_telegram_id'] ?? ''),
            'user' => $user,
            'service' => [],
            'invoice_id' => '',
            'username' => (string) $row['phone'],
            'session_type' => 'mobile',
        ];
    }

    public static function logout(PDO $pdo, string $token): void
    {
        self::ensureSchema($pdo);
        $token = trim($token);
        if ($token === '') {
            return;
        }

        $pdo->prepare(
            "UPDATE app_mobile_sessions
             SET revoked_at=?
             WHERE token_hash=? AND revoked_at IS NULL"
        )->execute([self::now(), hash('sha256', $token)]);
    }

    public static function linkTelegramUserByPhone(
        PDO $pdo,
        string $telegramUserId,
        string $phone
    ): array {
        self::ensureSchema($pdo);

        $telegramUserId = trim($telegramUserId);
        $phone = class_exists('BluebotSms')
            ? BluebotSms::normalizePhone($phone)
            : trim($phone);

        if ($telegramUserId === '' || $phone === '') {
            return ['linked' => false, 'migrated' => false];
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM app_mobile_accounts WHERE phone=? LIMIT 1"
        );
        $stmt->execute([$phone]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($account)) {
            $target = self::user($pdo, $telegramUserId);
            if (!is_array($target)) {
                return ['linked' => false, 'migrated' => false];
            }

            $account = self::upsertAccount($pdo, $telegramUserId, $phone, $telegramUserId);
            return [
                'linked' => true,
                'migrated' => false,
                'user_id' => $telegramUserId,
                'account_id' => (int) $account['id'],
            ];
        }

        $sourceUserId = (string) $account['user_id'];
        if ($sourceUserId === $telegramUserId) {
            $pdo->prepare(
                "UPDATE app_mobile_accounts
                 SET linked_telegram_id=?,updated_at=? WHERE id=?"
            )->execute([$telegramUserId, self::now(), (int) $account['id']]);

            return [
                'linked' => true,
                'migrated' => false,
                'user_id' => $telegramUserId,
                'account_id' => (int) $account['id'],
            ];
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $targetStmt = $pdo->prepare("SELECT * FROM user WHERE id=? FOR UPDATE");
            $targetStmt->execute([$telegramUserId]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($target)) {
                throw new RuntimeException('Telegram user not found');
            }

            $sourceStmt = $pdo->prepare("SELECT * FROM user WHERE id=? FOR UPDATE");
            $sourceStmt->execute([$sourceUserId]);
            $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($source)) {
                $sourceBalance = (int) ($source['Balance'] ?? 0);
                if ($sourceBalance !== 0) {
                    $pdo->prepare("UPDATE user SET Balance=Balance+? WHERE id=?")
                        ->execute([$sourceBalance, $telegramUserId]);
                    $pdo->prepare("UPDATE user SET Balance=0 WHERE id=?")
                        ->execute([$sourceUserId]);
                }
            }

            $moves = [
                ['invoice', 'id_user'],
                ['Payment_report', 'id_user'],
                ['service_other', 'id_user'],
                ['sms_deliveries', 'user_id'],
                ['digital_service_orders', 'user_id'],
                ['wallet_transactions', 'user_id'],
                ['app_client_accounts', 'user_id'],
                ['app_client_qr_links', 'user_id'],
            ];
            $migratedRows = 0;

            foreach ($moves as [$table, $column]) {
                if (!self::hasColumn($pdo, $table, $column)) {
                    continue;
                }
                $sql = "UPDATE `{$table}` SET `{$column}`=? WHERE `{$column}`=?";
                $move = $pdo->prepare($sql);
                $move->execute([$telegramUserId, $sourceUserId]);
                $migratedRows += $move->rowCount();
            }

            $pdo->prepare(
                "UPDATE app_mobile_accounts
                 SET user_id=?,linked_telegram_id=?,updated_at=?
                 WHERE id=?"
            )->execute([
                $telegramUserId,
                $telegramUserId,
                self::now(),
                (int) $account['id'],
            ]);

            if (is_array($source) && self::isSyntheticUserId($sourceUserId)) {
                $pdo->prepare("DELETE FROM user WHERE id=?")->execute([$sourceUserId]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            if (function_exists('clearSelectCache')) {
                clearSelectCache('user');
                clearSelectCache('invoice');
                clearSelectCache('Payment_report');
            }

            return [
                'linked' => true,
                'migrated' => true,
                'migrated_rows' => $migratedRows,
                'from_user_id' => $sourceUserId,
                'user_id' => $telegramUserId,
                'account_id' => (int) $account['id'],
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function catalog(PDO $pdo, string $userId): array
    {
        $user = self::user($pdo, $userId);
        if (!is_array($user)) {
            throw new RuntimeException('User not found');
        }

        $agent = (string) ($user['agent'] ?? 'f');
        $panels = $pdo->prepare(
            "SELECT * FROM marzban_panel
             WHERE status='active'
               AND (agent=? OR agent='all')
               AND type<>'Manualsale'
             ORDER BY name_panel ASC"
        );
        $panels->execute([$agent]);

        $items = [];
        while ($panel = $panels->fetch(PDO::FETCH_ASSOC)) {
            $hiddenUsers = json_decode((string) ($panel['hide_user'] ?? '[]'), true);
            $hiddenUsers = is_array($hiddenUsers) ? array_map('strval', $hiddenUsers) : [];
            if (in_array($userId, $hiddenUsers, true)) {
                continue;
            }

            $stmt = $pdo->prepare(
                "SELECT * FROM product
                 WHERE (Location=? OR Location='/all')
                   AND agent=?
                 ORDER BY price_product+0 ASC, id ASC"
            );
            $stmt->execute([(string) $panel['name_panel'], $agent]);

            while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hiddenPanels = json_decode((string) ($product['hide_panel'] ?? '[]'), true);
                $hiddenPanels = is_array($hiddenPanels) ? array_map('strval', $hiddenPanels) : [];
                if (in_array((string) $panel['name_panel'], $hiddenPanels, true)) {
                    continue;
                }

                if ((string) ($product['one_buy_status'] ?? '0') === '1') {
                    $once = $pdo->prepare(
                        "SELECT COUNT(*) FROM invoice
                         WHERE id_user=? AND Status<>'Unpaid'"
                    );
                    $once->execute([$userId]);
                    if ((int) $once->fetchColumn() > 0) {
                        continue;
                    }
                }

                $price = (float) ($product['price_product'] ?? 0);
                $discount = (int) ($user['pricediscount'] ?? 0);
                if ($discount > 0) {
                    $price -= ($price * $discount) / 100;
                }

                $items[] = [
                    'id' => (string) ($product['code_product'] ?? ''),
                    'name' => (string) ($product['name_product'] ?? ''),
                    'description' => (string) ($product['note'] ?? ''),
                    'price' => max(0, (int) round($price)),
                    'traffic_gb' => (int) ($product['Volume_constraint'] ?? 0),
                    'time_days' => (int) ($product['Service_time'] ?? 0),
                    'category' => (string) ($product['category'] ?? ''),
                    'panel_id' => (string) ($panel['code_panel'] ?? ''),
                    'panel_name' => (string) ($panel['name_panel'] ?? ''),
                ];
            }
        }

        $gateways = [];
        if (function_exists('bluebotBlupalConfigured') && bluebotBlupalConfigured()) {
            $gateways[] = 'blupal';
        }
        $zarinpal = trim((string) (function_exists('getPaySettingValue')
            ? getPaySettingValue('merchant_zarinpal', '')
            : ''));
        if ($zarinpal !== '' && $zarinpal !== '0') {
            $gateways[] = 'zarinpal';
        }
        $gateways[] = 'wallet';

        return [
            'products' => $items,
            'gateways' => array_values(array_unique($gateways)),
            'balance' => (int) ($user['Balance'] ?? 0),
        ];
    }

    public static function createCheckout(
        PDO $pdo,
        string $userId,
        string $productCode,
        string $panelCode,
        string $gateway
    ): array {
        global $ManagePanel;

        $user = self::user($pdo, $userId);
        if (!is_array($user)) {
            throw new RuntimeException('User not found');
        }

        $panelStmt = $pdo->prepare(
            "SELECT * FROM marzban_panel
             WHERE code_panel=? AND status='active'
             LIMIT 1"
        );
        $panelStmt->execute([$panelCode]);
        $panel = $panelStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($panel) || (string) ($panel['type'] ?? '') === 'Manualsale') {
            throw new InvalidArgumentException('پلن یا موقعیت انتخاب‌شده در دسترس نیست.');
        }

        $agent = (string) ($user['agent'] ?? 'f');
        $panelAgent = (string) ($panel['agent'] ?? 'all');
        if ($panelAgent !== 'all' && $panelAgent !== $agent) {
            throw new InvalidArgumentException('پلن یا موقعیت انتخاب‌شده در دسترس نیست.');
        }

        $productStmt = $pdo->prepare(
            "SELECT * FROM product
             WHERE code_product=?
               AND (Location=? OR Location='/all')
               AND agent=?
             LIMIT 1"
        );
        $productStmt->execute([$productCode, (string) $panel['name_panel'], $agent]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) {
            throw new InvalidArgumentException('پلن انتخاب‌شده پیدا نشد.');
        }

        $hiddenPanels = json_decode((string) ($product['hide_panel'] ?? '[]'), true);
        $hiddenPanels = is_array($hiddenPanels) ? array_map('strval', $hiddenPanels) : [];
        if (in_array((string) $panel['name_panel'], $hiddenPanels, true)) {
            throw new InvalidArgumentException('پلن انتخاب‌شده در دسترس نیست.');
        }

        if ((string) ($product['one_buy_status'] ?? '0') === '1') {
            $once = $pdo->prepare(
                "SELECT COUNT(*) FROM invoice
                 WHERE id_user=? AND Status<>'Unpaid'"
            );
            $once->execute([$userId]);
            if ((int) $once->fetchColumn() > 0) {
                throw new DomainException('این پلن فقط برای خرید اول قابل استفاده است.');
            }
        }

        $price = (float) ($product['price_product'] ?? 0);
        $discount = (int) ($user['pricediscount'] ?? 0);
        if ($discount > 0) {
            $price -= ($price * $discount) / 100;
        }
        $price = max(0, (int) round($price));

        $invoiceId = bin2hex(random_bytes(4));
        $username = strtolower(generateUsername(
            $userId,
            (string) ($panel['MethodUsername'] ?? ''),
            (string) ($user['username'] ?? 'NOT_USERNAME'),
            $invoiceId,
            null,
            (string) ($panel['namecustom'] ?? ''),
            (string) ($user['namecustom'] ?? 'none')
        ));

        if ($username === '' || rowExists('invoice', 'username', $username)) {
            throw new RuntimeException('ساخت نام کاربری سرویس ناموفق بود؛ دوباره تلاش کنید.');
        }

        if ($ManagePanel instanceof ManagePanel) {
            $existing = $ManagePanel->DataUser((string) $panel['name_panel'], $username);
            if (is_array($existing) && !empty($existing['username'])) {
                throw new RuntimeException('نام کاربری سرویس تکراری است؛ دوباره تلاش کنید.');
            }
        }

        $notifications = json_encode(['volume' => false, 'time' => false]);
        $insertInvoice = $pdo->prepare(
            "INSERT INTO invoice
                (id_user,id_invoice,username,time_sell,Service_location,name_product,price_product,Volume,Service_time,Status,note,refral,notifctions)
             VALUES (?,?,?,?,?,?,?,?,?,'Unpaid',?,?,?)"
        );
        $insertInvoice->execute([
            $userId,
            $invoiceId,
            $username,
            time(),
            (string) $panel['name_panel'],
            (string) $product['name_product'],
            $price,
            (string) $product['Volume_constraint'],
            (string) $product['Service_time'],
            null,
            (string) ($user['affiliates'] ?? ''),
            $notifications,
        ]);

        $orderId = bin2hex(random_bytes(5));
        $deliveryState = 'getconfigafterpay|' . $username;
        $gateway = strtolower(trim($gateway));

        try {
            if ($gateway === 'wallet' || $price === 0) {
                if ($price > 0) {
                    $debit = $pdo->prepare(
                        "UPDATE user
                         SET Balance=Balance-?
                         WHERE id=? AND Balance>=?"
                    );
                    $debit->execute([$price, $userId, $price]);
                    if ($debit->rowCount() !== 1) {
                        throw new DomainException('موجودی کیف پول کافی نیست.');
                    }
                }

                $report = $pdo->prepare(
                    "INSERT INTO Payment_report
                        (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $report->execute([
                    $userId,
                    $orderId,
                    date('Y/m/d H:i:s'),
                    $price,
                    'paid',
                    'wallet',
                    $deliveryState,
                ]);

                DirectPayment($orderId);
                $invoice = self::invoice($pdo, $invoiceId);
                if (!is_array($invoice) || (string) ($invoice['Status'] ?? '') !== 'active') {
                    if (function_exists('markPaymentDeliveryError')) {
                        markPaymentDeliveryError($orderId, 'App checkout paid but service provisioning did not become active.');
                    }
                    throw new RuntimeException('پرداخت انجام شد اما ساخت سرویس کامل نشد؛ مبلغ یا سرویس نیاز به بررسی دارد.');
                }

                return [
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId,
                    'status' => 'paid',
                    'payment_url' => null,
                    'gateway' => 'wallet',
                ];
            }

            if ($gateway === 'blupal') {
                if (!function_exists('bluebotBlupalConfigured') || !bluebotBlupalConfigured()) {
                    throw new RuntimeException('درگاه BluePal فعال نیست.');
                }

                $report = $pdo->prepare(
                    "INSERT INTO Payment_report
                        (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $report->execute([
                    $userId,
                    $orderId,
                    date('Y/m/d H:i:s'),
                    $price,
                    'Unpaid',
                    'blupal',
                    $deliveryState,
                ]);

                $card = trim((string) getPaySettingValue('blupal_card_number', '0'));
                $remote = bluebotBlupalCreateInvoice($price, $card === '0' ? null : $card);
                $remoteId = trim((string) ($remote['invoice_id'] ?? ''));
                $paymentUrl = trim((string) ($remote['payment_link'] ?? ''));
                if (
                    empty($remote['success'])
                    || $remoteId === ''
                    || filter_var($paymentUrl, FILTER_VALIDATE_URL) === false
                ) {
                    throw new RuntimeException('ساخت لینک پرداخت انجام نشد.');
                }

                $pdo->prepare(
                    "UPDATE Payment_report SET dec_not_confirmed=? WHERE id_order=?"
                )->execute([$remoteId, $orderId]);

                return [
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId,
                    'status' => 'unpaid',
                    'payment_url' => $paymentUrl,
                    'gateway' => 'blupal',
                ];
            }

            if ($gateway === 'zarinpal') {
                $merchant = trim((string) getPaySettingValue('merchant_zarinpal', ''));
                if ($merchant === '' || $merchant === '0') {
                    throw new RuntimeException('درگاه زرین‌پال فعال نیست.');
                }

                $remote = createPayZarinpal($price, $orderId);
                $code = (int) ($remote['data']['code'] ?? 0);
                $authority = trim((string) ($remote['data']['authority'] ?? ''));
                if ($code !== 100 || $authority === '') {
                    throw new RuntimeException('ساخت لینک پرداخت زرین‌پال انجام نشد.');
                }

                $report = $pdo->prepare(
                    "INSERT INTO Payment_report
                        (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed)
                     VALUES (?,?,?,?,?,?,?,?)"
                );
                $report->execute([
                    $userId,
                    $orderId,
                    date('Y/m/d H:i:s'),
                    $price,
                    'Unpaid',
                    'zarinpal',
                    $deliveryState,
                    $authority,
                ]);

                return [
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId,
                    'status' => 'unpaid',
                    'payment_url' => 'https://www.zarinpal.com/pg/StartPay/' . $authority,
                    'gateway' => 'zarinpal',
                ];
            }

            throw new InvalidArgumentException('درگاه پرداخت معتبر نیست.');
        } catch (Throwable $e) {
            $paymentStateStmt = $pdo->prepare(
                "SELECT payment_Status FROM Payment_report WHERE id_order=? LIMIT 1"
            );
            $paymentStateStmt->execute([$orderId]);
            $paymentState = strtolower((string) ($paymentStateStmt->fetchColumn() ?: ''));

            if ($paymentState === '' || $paymentState === 'unpaid') {
                $pdo->prepare(
                    "DELETE FROM Payment_report WHERE id_order=? AND payment_Status='Unpaid'"
                )->execute([$orderId]);
                $pdo->prepare(
                    "DELETE FROM invoice WHERE id_invoice=? AND Status='Unpaid'"
                )->execute([$invoiceId]);
            }

            throw $e;
        }
    }

    public static function orderStatus(PDO $pdo, string $userId, string $orderId): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM Payment_report
             WHERE id_order=? AND id_user=?
             LIMIT 1"
        );
        $stmt->execute([$orderId, $userId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($payment)) {
            throw new InvalidArgumentException('سفارش پیدا نشد.');
        }

        if (
            strtolower((string) ($payment['Payment_Method'] ?? '')) === 'blupal'
            && strtolower((string) ($payment['payment_Status'] ?? '')) !== 'paid'
        ) {
            $remoteId = trim((string) ($payment['dec_not_confirmed'] ?? ''));
            if ($remoteId !== '' && function_exists('bluebotBlupalSettle')) {
                bluebotBlupalSettle($remoteId);
                $stmt->execute([$orderId, $userId]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: $payment;
            }
        }

        $parts = explode('|', (string) ($payment['id_invoice'] ?? ''), 2);
        $service = null;
        $deliveryExpected = ($parts[0] ?? '') === 'getconfigafterpay' && !empty($parts[1]);
        if ($deliveryExpected) {
            $invoiceStmt = $pdo->prepare(
                "SELECT * FROM invoice
                 WHERE id_user=? AND username=?
                 ORDER BY time_sell DESC LIMIT 1"
            );
            $invoiceStmt->execute([$userId, $parts[1]]);
            $row = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            $serviceStatus = is_array($row)
                ? strtolower((string) ($row['Status'] ?? ''))
                : '';
            if (is_array($row) && in_array($serviceStatus, [
                'active',
                'end_of_time',
                'end_of_volume',
                'sendedwarn',
                'send_on_hold',
            ], true)) {
                $service = [
                    'id' => (string) ($row['id_invoice'] ?? ''),
                    'username' => (string) ($row['username'] ?? ''),
                    'status' => (string) ($row['Status'] ?? ''),
                ];
            }
        }

        $paymentStatus = strtolower((string) ($payment['payment_Status'] ?? ''));
        if ($deliveryExpected && $paymentStatus === 'paid' && $service === null) {
            if (function_exists('markPaymentDeliveryError')) {
                markPaymentDeliveryError(
                    (string) ($payment['id_order'] ?? ''),
                    'Paid app checkout has no active provisioned service.'
                );
            }
            $paymentStatus = 'delivery_error';
        }

        return [
            'order_id' => (string) ($payment['id_order'] ?? ''),
            'status' => $paymentStatus,
            'gateway' => strtolower((string) ($payment['Payment_Method'] ?? '')),
            'service' => $service,
        ];
    }

    private static function resolveOrCreateUser(PDO $pdo, string $phone): string
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM user
             WHERE number=? AND verify='1' AND User_Status<>'block'
             ORDER BY register ASC
             LIMIT 1"
        );
        $stmt->execute([$phone]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && trim((string) $existing) !== '') {
            return (string) $existing;
        }

        $setting = function_exists('select') ? select('setting', '*') : [];
        $setting = is_array($setting) ? $setting : [];

        do {
            $userId = '8' . (string) random_int(1000000000000000, 9999999999999999);
            $check = $pdo->prepare("SELECT 1 FROM user WHERE id=? LIMIT 1");
            $check->execute([$userId]);
        } while ($check->fetchColumn());

        $username = 'app_' . substr(hash('sha256', $phone), 0, 10);
        $invite = bin2hex(random_bytes(4));
        $stmt = $pdo->prepare(
            "INSERT INTO user
                (id,step,limit_usertest,User_Status,number,Balance,pagenumber,username,agent,
                 message_count,last_message_time,affiliates,affiliatescount,cardpayment,
                 number_username,namecustom,register,verify,codeInvitation,pricediscount,
                 maxbuyagent,joinchannel,score,status_cron,sms_enabled)
             VALUES
                (?, 'home', ?, 'Active', ?, 0, 1, ?, 'f',
                 '0','0','0','0',?,
                 '100','none',?, '1', ?, '0',
                 '0','0','0','1',1)"
        );
        $stmt->execute([
            $userId,
            (string) ($setting['limit_usertest_all'] ?? '0'),
            $phone,
            $username,
            (string) ($setting['showcard'] ?? '0'),
            time(),
            $invite,
        ]);

        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }

        return $userId;
    }

    private static function upsertAccount(
        PDO $pdo,
        string $userId,
        string $phone,
        ?string $telegramUserId = null
    ): array {
        $now = self::now();

        $stmt = $pdo->prepare(
            "INSERT INTO app_mobile_accounts
                (user_id,phone,linked_telegram_id,verified_at,created_at,updated_at)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                user_id=VALUES(user_id),
                linked_telegram_id=COALESCE(VALUES(linked_telegram_id),linked_telegram_id),
                verified_at=VALUES(verified_at),
                updated_at=VALUES(updated_at)"
        );
        $stmt->execute([
            $userId,
            $phone,
            $telegramUserId,
            $now,
            $now,
            $now,
        ]);

        $find = $pdo->prepare("SELECT * FROM app_mobile_accounts WHERE phone=? LIMIT 1");
        $find->execute([$phone]);
        $account = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new RuntimeException('Unable to create mobile account');
        }

        return $account;
    }

    private static function issueSession(
        PDO $pdo,
        array $account,
        array $user,
        string $deviceId
    ): array {
        $token = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify('+' . self::SESSION_TTL . ' seconds');

        $stmt = $pdo->prepare(
            "INSERT INTO app_mobile_sessions
                (account_id,token_hash,device_hash,created_at,expires_at,last_seen_at)
             VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([
            (int) $account['id'],
            hash('sha256', $token),
            hash('sha256', trim($deviceId)),
            $now->format('Y-m-d H:i:s'),
            $expires->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        ]);

        return [
            'token' => $token,
            'expires_in' => self::SESSION_TTL,
            'expires_at' => $expires->format(DATE_ATOM),
            'user_id' => (string) $account['user_id'],
            'phone' => (string) $account['phone'],
            'user' => $user,
            'account' => $account,
            'session_type' => 'mobile',
        ];
    }

    private static function challengeUserId(string $phone, string $deviceId): string
    {
        return 'app:' . substr(hash('sha256', $phone . '|' . trim($deviceId)), 0, 48);
    }

    private static function validateDevice(string $deviceId): void
    {
        $deviceId = trim($deviceId);
        if (
            strlen($deviceId) < 8
            || strlen($deviceId) > 190
            || preg_match('/^[A-Za-z0-9._:-]+$/', $deviceId) !== 1
        ) {
            throw new InvalidArgumentException('Invalid device id');
        }
    }

    private static function user(PDO $pdo, string $userId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM user WHERE id=? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function invoice(PDO $pdo, string $invoiceId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE id_invoice=? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function isSyntheticUserId(string $userId): bool
    {
        return preg_match('/^8\d{16}$/', $userId) === 1;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
