<?php

require_once __DIR__ . '/TgToolsClient.php';

final class BluebotDigitalServices
{
    private const STATUS_PENDING = 'pending_approval';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_DELIVERED = 'delivered';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_FAILED = 'failed';

    public static function isAvailable(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'digital_service_products'");
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function listActive(PDO $pdo): array
    {
        if (!self::isAvailable($pdo)) {
            return [];
        }

        $stmt = $pdo->query(
            "SELECT * FROM digital_service_products
             WHERE active = 1
             ORDER BY sort_order ASC, id ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findProduct(PDO $pdo, int $id, bool $activeOnly = true): ?array
    {
        if ($id <= 0 || !self::isAvailable($pdo)) {
            return null;
        }

        $sql = "SELECT * FROM digital_service_products WHERE id = ?";
        if ($activeOnly) {
            $sql .= " AND active = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function catalogKeyboard(PDO $pdo, string $backText): string
    {
        $rows = [];
        foreach (self::listActive($pdo) as $product) {
            $label = sprintf(
                '%s · %s تومان',
                trim((string) $product['name']),
                number_format((float) $product['price'])
            );
            $rows[] = [[
                'text' => $label,
                'callback_data' => 'ds_product:' . (int) $product['id'],
            ]];
        }

        $rows[] = [[
            'text' => $backText,
            'callback_data' => 'backuser',
        ]];

        return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public static function productKeyboard(array $product, string $backText): string
    {
        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '🛒 ثبت سفارش',
                    'callback_data' => 'ds_buy:' . (int) $product['id'],
                    'style' => 'success',
                ]],
                [[
                    'text' => $backText,
                    'callback_data' => 'ds_home',
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function confirmKeyboard(int $productId, string $backText): string
    {
        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '✅ تأیید و ثبت سفارش',
                    'callback_data' => 'ds_confirm:' . $productId,
                    'style' => 'success',
                ]],
                [[
                    'text' => $backText,
                    'callback_data' => 'ds_home',
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function adminKeyboard(int $orderId): string
    {
        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '✅ تأیید و ارسال',
                    'callback_data' => 'ds_approve:' . $orderId,
                    'style' => 'success',
                ]],
                [[
                    'text' => '❌ رد و برگشت وجه',
                    'callback_data' => 'ds_reject:' . $orderId,
                    'style' => 'danger',
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function validateTarget(array $product, string $target): array
    {
        $target = trim($target);
        if ($target === '' || mb_strlen($target, 'UTF-8') > 255) {
            return [false, 'شناسه مقصد معتبر نیست.'];
        }

        $type = (string) ($product['type'] ?? '');
        $provider = (string) ($product['provider'] ?? 'manual');

        if ($provider === 'tgtools' && in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            $normalized = ltrim($target, '@');
            if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $normalized)) {
                return [false, 'برای TGTools باید یوزرنیم معتبر تلگرام وارد شود؛ Telegram User ID پشتیبانی نمی‌شود.'];
            }
            return [true, $normalized];
        }

        if ($type === 'telegram_premium' && $provider === 'telegram_bot') {
            $normalized = ltrim($target, '@');
            if (!ctype_digit($normalized) || (int) $normalized <= 0) {
                return [false, 'برای ارسال خودکار Premium باید Telegram User ID عددی وارد شود.'];
            }
            return [true, $normalized];
        }

        if (in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            if (!preg_match('/^@?[A-Za-z0-9_]{5,32}$/', $target) && !ctype_digit($target)) {
                return [false, 'یوزرنیم یا Telegram User ID معتبر وارد کنید.'];
            }
        }

        return [true, $target];
    }

    public static function createWalletOrder(PDO $pdo, array $user, array $product, string $target): array
    {
        $userId = trim((string) ($user['id'] ?? ''));
        $productId = (int) ($product['id'] ?? 0);
        $price = (int) ($product['price'] ?? 0);

        if ($userId === '' || $productId <= 0 || $price <= 0) {
            throw new RuntimeException('Invalid digital service order payload.');
        }

        [$valid, $targetOrError] = self::validateTarget($product, $target);
        if (!$valid) {
            throw new InvalidArgumentException($targetOrError);
        }
        $target = $targetOrError;

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT * FROM user WHERE id = ? FOR UPDATE");
            $lock->execute([$userId]);
            $freshUser = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($freshUser)) {
                throw new RuntimeException('User not found.');
            }

            $freshProductStmt = $pdo->prepare(
                "SELECT * FROM digital_service_products WHERE id = ? AND active = 1 FOR UPDATE"
            );
            $freshProductStmt->execute([$productId]);
            $freshProduct = $freshProductStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($freshProduct)) {
                throw new RuntimeException('Service is unavailable.');
            }

            $freshPrice = (int) ($freshProduct['price'] ?? 0);
            if ($freshPrice <= 0) {
                throw new RuntimeException('Service price is invalid.');
            }

            $minBalance = ($freshUser['agent'] ?? 'f') === 'n2' && (int) ($freshUser['maxbuyagent'] ?? 0) !== 0
                ? -(int) $freshUser['maxbuyagent']
                : 0;
            $balance = (int) ($freshUser['Balance'] ?? 0);
            if ($balance - $freshPrice < $minBalance) {
                throw new DomainException('INSUFFICIENT_BALANCE');
            }

            $debit = $pdo->prepare("UPDATE user SET Balance = Balance - ? WHERE id = ?");
            $debit->execute([$freshPrice, $userId]);
            if ($debit->rowCount() !== 1) {
                throw new RuntimeException('Unable to debit wallet.');
            }

            $orderCode = 'DS-' . strtoupper(bin2hex(random_bytes(6)));
            $insert = $pdo->prepare(
                "INSERT INTO digital_service_orders
                (order_code, user_id, service_id, service_code, service_name, target, amount, quantity, provider, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)"
            );
            $insert->execute([
                $orderCode,
                $userId,
                $productId,
                (string) $freshProduct['code'],
                (string) $freshProduct['name'],
                $target,
                $freshPrice,
                (string) ($freshProduct['provider'] ?? 'manual'),
                self::STATUS_PENDING,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $pdo->commit();
            clearSelectCache('user');

            return self::findOrder($pdo, $orderId) ?? [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function findOrder(PDO $pdo, int $orderId): ?array
    {
        if ($orderId <= 0 || !self::isAvailable($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function notifyAdmins(PDO $pdo, array $order): void
    {
        $admins = select('admin', 'id_admin', null, null, 'FETCH_COLUMN');
        if (!is_array($admins)) {
            return;
        }

        $text = self::adminOrderText($order);
        $keyboard = self::adminKeyboard((int) $order['id']);
        foreach (array_unique(array_map('strval', $admins)) as $adminId) {
            if ($adminId === '' || $adminId === '0') {
                continue;
            }
            sendmessage($adminId, $text, $keyboard, 'HTML');
        }
    }

    public static function adminOrderText(array $order): string
    {
        return "🛍 <b>سفارش فروش خدمات</b>

"
            . "🧾 کد: <code>" . self::escape((string) ($order['order_code'] ?? '—')) . "</code>
"
            . "👤 کاربر: <code>" . self::escape((string) ($order['user_id'] ?? '—')) . "</code>
"
            . "📦 سرویس: <b>" . self::escape((string) ($order['service_name'] ?? '—')) . "</b>
"
            . "🎯 مقصد: <code>" . self::escape((string) ($order['target'] ?? '—')) . "</code>
"
            . "💳 مبلغ: <b>" . number_format((float) ($order['amount'] ?? 0)) . " تومان</b>
"
            . "🔌 Provider: <code>" . self::escape((string) ($order['provider'] ?? 'manual')) . "</code>

"
            . "ارسال فقط بعد از زدن «تأیید و ارسال» انجام می‌شود.";
    }

    public static function approveAndDeliver(PDO $pdo, int $orderId, string $adminId): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found.');
            }

            $status = (string) ($order['status'] ?? '');
            if ($status === self::STATUS_DELIVERED) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order];
            }
            if (!in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
                throw new RuntimeException('Order is not ready for delivery.');
            }
            if ($status === self::STATUS_FAILED && (int) ($order['refunded'] ?? 0) === 1) {
                throw new RuntimeException('This failed order was already refunded. Create a new order before retrying.');
            }

            $claim = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, admin_id = ?, approved_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            );
            $claim->execute([self::STATUS_PROCESSING, $adminId, $orderId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $order = self::findOrder($pdo, $orderId);
        if (!is_array($order)) {
            throw new RuntimeException('Order disappeared after approval.');
        }

        $product = self::findProduct($pdo, (int) $order['service_id'], false);
        if (!is_array($product)) {
            return self::markFailed($pdo, $orderId, 'Product snapshot is no longer available.');
        }

        try {
            $delivery = self::deliver($pdo, $order, $product);
        } catch (Throwable $e) {
            return self::markFailed($pdo, $orderId, $e->getMessage());
        }

        if (empty($delivery['ok'])) {
            return self::markFailed(
                $pdo,
                $orderId,
                (string) ($delivery['error'] ?? 'Unknown provider error'),
                $delivery['response'] ?? null
            );
        }

        if (!empty($delivery['pending'])) {
            $responseJson = json_encode($delivery['response'] ?? $delivery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $reference = trim((string) ($delivery['reference'] ?? ''));
            $pending = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, provider_reference = ?, provider_response = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $pending->execute([
                self::STATUS_PROCESSING,
                $reference !== '' ? $reference : null,
                is_string($responseJson) ? $responseJson : null,
                $orderId,
            ]);

            $processingOrder = self::findOrder($pdo, $orderId) ?? $order;
            sendmessage(
                (string) $processingOrder['user_id'],
                "⏳ <b>سفارش شما تأیید شد و در حال ارسال است</b>\n\n"
                    . "🧾 کد: <code>" . self::escape((string) $processingOrder['order_code']) . "</code>\n"
                    . "📦 " . self::escape((string) $processingOrder['service_name']),
                null,
                'HTML'
            );

            return ['ok' => true, 'pending' => true, 'order' => $processingOrder, 'delivery' => $delivery];
        }

        return self::finalizeDeliveredOrder($pdo, $orderId, $delivery);
    }

    public static function rejectAndRefund(PDO $pdo, int $orderId, string $adminId): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found.');
            }

            $status = (string) ($order['status'] ?? '');
            if ($status === self::STATUS_REJECTED) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order];
            }
            if ($status === self::STATUS_DELIVERED) {
                throw new RuntimeException('Delivered order cannot be refunded automatically.');
            }
            if ($status === self::STATUS_PROCESSING) {
                throw new RuntimeException('Order is being processed. Retry after delivery state is known.');
            }

            if ((int) ($order['refunded'] ?? 0) !== 1) {
                $refund = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
                $refund->execute([(int) $order['amount'], (string) $order['user_id']]);
                if ($refund->rowCount() !== 1) {
                    throw new RuntimeException('Refund failed.');
                }
            }

            $update = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, refunded = 1, admin_id = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([self::STATUS_REJECTED, $adminId, $orderId]);
            $pdo->commit();
            clearSelectCache('user');

            $finalOrder = self::findOrder($pdo, $orderId) ?? $order;
            sendmessage(
                (string) $finalOrder['user_id'],
                "❌ <b>سفارش رد شد و مبلغ به کیف پول برگشت</b>

"
                    . "🧾 کد: <code>" . self::escape((string) $finalOrder['order_code']) . "</code>
"
                    . "💰 مبلغ برگشتی: <b>" . number_format((float) $finalOrder['amount']) . " تومان</b>",
                null,
                'HTML'
            );

            return ['ok' => true, 'order' => $finalOrder];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function deliver(PDO $pdo, array $order, array $product): array
    {
        $provider = (string) ($product['provider'] ?? 'manual');
        $type = (string) ($product['type'] ?? '');

        if ($provider === 'manual') {
            return [
                'ok' => true,
                'reference' => 'manual:' . ($order['order_code'] ?? $order['id']),
                'response' => ['mode' => 'manual', 'confirmed_by_admin' => true],
            ];
        }

        if ($provider === 'telegram_bot' && $type === 'telegram_premium') {
            $months = (int) ($product['service_value'] ?? 0);
            $starsByMonths = [3 => 1000, 6 => 1500, 12 => 2500];
            if (!isset($starsByMonths[$months])) {
                return ['ok' => false, 'error' => 'Premium month_count must be 3, 6, or 12.'];
            }

            $target = ltrim((string) $order['target'], '@');
            if (!ctype_digit($target) || (int) $target <= 0) {
                return ['ok' => false, 'error' => 'Telegram Premium auto-delivery requires numeric user_id.'];
            }

            $response = telegram('giftPremiumSubscription', [
                'user_id' => (int) $target,
                'month_count' => $months,
                'star_count' => $starsByMonths[$months],
                'text' => 'هدیه Premium از طرف فروشگاه',
            ]);

            return [
                'ok' => is_array($response) && !empty($response['ok']),
                'reference' => 'telegram:' . $target . ':' . $months,
                'response' => $response,
                'error' => is_array($response) ? (string) ($response['description'] ?? '') : 'Telegram API request failed.',
            ];
        }

        if ($provider === 'tgtools') {
            return self::deliverTgTools($pdo, $order, $product);
        }

        if ($provider === 'ozvinoo') {
            return self::deliverOZVinoo($pdo, $order, $product);
        }

        return ['ok' => false, 'error' => 'Unsupported digital service provider.'];
    }

    public static function reconcileTgToolsProcessing(PDO $pdo, int $limit = 25): array
    {
        $stats = ['checked' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];

        if (!self::isAvailable($pdo)) {
            return $stats;
        }

        $apiKey = trim(self::setting($pdo, 'tgtools_api_key', ''));
        if ($apiKey === '') {
            return $stats;
        }

        $limit = max(1, min(100, $limit));
        $stmt = $pdo->query(
            "SELECT * FROM digital_service_orders
             WHERE provider = 'tgtools'
               AND status = 'processing'
               AND provider_reference IS NOT NULL
               AND provider_reference <> ''
             ORDER BY id ASC
             LIMIT " . $limit
        );
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $client = new TgToolsClient($apiKey);

        foreach ($orders as $order) {
            $stats['checked']++;
            $transactionId = (int) ($order['provider_reference'] ?? 0);
            if ($transactionId <= 0) {
                $stats['errors']++;
                continue;
            }

            $statusResponse = $client->purchaseStatus($transactionId);
            if (empty($statusResponse['ok'])) {
                $stats['errors']++;
                continue;
            }

            $data = is_array($statusResponse['data'] ?? null) ? $statusResponse['data'] : [];
            $status = self::tgToolsStatus($data);

            if ($status === 'completed') {
                $result = self::finalizeDeliveredOrder($pdo, (int) $order['id'], [
                    'ok' => true,
                    'reference' => (string) $transactionId,
                    'response' => $statusResponse,
                ]);
                if (!empty($result['ok'])) {
                    $stats['completed']++;
                } else {
                    $stats['errors']++;
                }
                continue;
            }

            if ($status === 'failed') {
                self::failAndRefundProviderOrder(
                    $pdo,
                    (int) $order['id'],
                    'TGTools delivery failed.',
                    $statusResponse
                );
                $stats['failed']++;
                continue;
            }

            $stats['pending']++;
        }

        return $stats;
    }

    private static function deliverTgTools(PDO $pdo, array $order, array $product): array
    {
        $apiKey = trim(self::setting($pdo, 'tgtools_api_key', ''));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'TGTools API key is not configured.'];
        }

        $type = (string) ($product['type'] ?? '');
        if (!in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            return ['ok' => false, 'error' => 'TGTools provider only supports Telegram Stars and Premium.'];
        }

        $username = ltrim(trim((string) ($order['target'] ?? '')), '@');
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
            return ['ok' => false, 'error' => 'TGTools requires a valid Telegram username.'];
        }

        $client = new TgToolsClient($apiKey);
        $lookup = $client->lookupUser($username);
        if (empty($lookup['ok'])) {
            return [
                'ok' => false,
                'error' => trim((string) ($lookup['message'] ?? '')) ?: 'Unable to validate Telegram username with TGTools.',
                'response' => $lookup,
            ];
        }

        $profile = is_array($lookup['data'] ?? null) ? $lookup['data'] : [];
        if (array_key_exists('found', $profile) && !$profile['found']) {
            return ['ok' => false, 'error' => 'Telegram username was not found by TGTools.', 'response' => $lookup];
        }

        $serviceValue = max(1, (int) ($product['service_value'] ?? 1));
        $trackingCode = (string) ($order['order_code'] ?? ('bluebot-' . (int) ($order['id'] ?? 0)));

        if ($type === 'telegram_premium') {
            if (!in_array($serviceValue, [3, 6, 12], true)) {
                return ['ok' => false, 'error' => 'TGTools Premium months must be 3, 6, or 12.'];
            }
            if (array_key_exists('premiumEligible', $profile) && !$profile['premiumEligible']) {
                return ['ok' => false, 'error' => 'This Telegram account is not eligible for Premium.', 'response' => $lookup];
            }
            $purchase = $client->purchasePremium($username, $serviceValue, $trackingCode);
        } else {
            $purchase = $client->purchaseStars($username, $serviceValue, $trackingCode);
        }

        if (empty($purchase['ok'])) {
            return [
                'ok' => false,
                'error' => trim((string) ($purchase['message'] ?? '')) ?: 'TGTools purchase request failed.',
                'response' => $purchase,
            ];
        }

        $data = is_array($purchase['data'] ?? null) ? $purchase['data'] : [];
        $transactionId = (int) ($data['transactionId'] ?? $data['id'] ?? 0);
        if ($transactionId <= 0) {
            return [
                'ok' => false,
                'error' => 'TGTools did not return a transaction ID.',
                'response' => $purchase,
            ];
        }

        $status = self::tgToolsStatus($data);
        if ($status === 'failed') {
            return [
                'ok' => false,
                'error' => trim((string) ($data['message'] ?? $purchase['message'] ?? 'TGTools rejected the purchase.')),
                'reference' => (string) $transactionId,
                'response' => $purchase,
            ];
        }

        return [
            'ok' => true,
            'pending' => $status !== 'completed',
            'reference' => (string) $transactionId,
            'response' => $purchase,
        ];
    }

    private static function tgToolsStatus(array $data): string
    {
        $raw = strtolower(trim((string) ($data['status'] ?? '')));
        if (in_array($raw, ['completed', 'complete', 'delivered', 'success', 'succeeded'], true)) {
            return 'completed';
        }
        if (in_array($raw, ['failed', 'error', 'rejected', 'cancelled', 'canceled', 'refunded'], true)) {
            return 'failed';
        }
        return 'pending';
    }

    private static function finalizeDeliveredOrder(PDO $pdo, int $orderId, array $delivery): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found during delivery finalization.');
            }

            if ((string) ($order['status'] ?? '') === self::STATUS_DELIVERED) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order, 'delivery' => $delivery];
            }

            if ((string) ($order['status'] ?? '') !== self::STATUS_PROCESSING) {
                throw new RuntimeException('Order is not processing.');
            }

            $responseJson = json_encode($delivery['response'] ?? $delivery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $reference = trim((string) ($delivery['reference'] ?? $order['provider_reference'] ?? ''));

            $done = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, provider_reference = ?, provider_response = ?, delivered_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            );
            $done->execute([
                self::STATUS_DELIVERED,
                $reference !== '' ? $reference : null,
                is_string($responseJson) ? $responseJson : null,
                $orderId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $finalOrder = self::findOrder($pdo, $orderId) ?? $order;
        sendmessage(
            (string) $finalOrder['user_id'],
            "✅ <b>سفارش شما ارسال شد</b>\n\n"
                . "🧾 کد: <code>" . self::escape((string) $finalOrder['order_code']) . "</code>\n"
                . "📦 " . self::escape((string) $finalOrder['service_name']) . "\n"
                . "🎯 <code>" . self::escape((string) $finalOrder['target']) . "</code>",
            null,
            'HTML'
        );

        return ['ok' => true, 'order' => $finalOrder, 'delivery' => $delivery];
    }

    private static function failAndRefundProviderOrder(PDO $pdo, int $orderId, string $error, array $response): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found during provider failure handling.');
            }

            if ((string) ($order['status'] ?? '') !== self::STATUS_PROCESSING) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order];
            }

            if ((int) ($order['refunded'] ?? 0) !== 1) {
                $refund = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
                $refund->execute([(int) $order['amount'], (string) $order['user_id']]);
                if ($refund->rowCount() !== 1) {
                    throw new RuntimeException('Provider failure refund could not be credited.');
                }
            }

            $payload = json_encode(
                ['error' => $error, 'response' => $response],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $update = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, refunded = 1, provider_response = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([
                self::STATUS_FAILED,
                is_string($payload) ? $payload : $error,
                $orderId,
            ]);
            $pdo->commit();
            clearSelectCache('user');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $finalOrder = self::findOrder($pdo, $orderId) ?? $order;
        sendmessage(
            (string) $finalOrder['user_id'],
            "❌ <b>ارسال سفارش ناموفق بود</b>\n\n"
                . "🧾 کد: <code>" . self::escape((string) $finalOrder['order_code']) . "</code>\n"
                . "💰 مبلغ سفارش به کیف پول شما برگشت داده شد.",
            null,
            'HTML'
        );

        return ['ok' => false, 'refunded' => true, 'order' => $finalOrder, 'error' => $error];
    }

    private static function deliverOZVinoo(PDO $pdo, array $order, array $product): array
    {
        $baseUrl = rtrim(self::setting($pdo, 'ozvinoo_base_url', 'https://api.ozvinoo.xyz'), '/');
        $orderPath = trim(self::setting($pdo, 'ozvinoo_order_path', ''));
        $apiKey = trim(self::setting($pdo, 'ozvinoo_api_key', ''));
        $authHeader = trim(self::setting($pdo, 'ozvinoo_auth_header', 'Authorization'));
        $authPrefix = trim(self::setting($pdo, 'ozvinoo_auth_prefix', 'Bearer'));
        $serviceCode = trim((string) ($product['provider_service_code'] ?? ''));

        if ($orderPath === '' || $serviceCode === '') {
            return [
                'ok' => false,
                'error' => 'OZVinoo provider is not configured: order path or service code is missing.',
            ];
        }

        if (!str_starts_with($orderPath, '/')) {
            $orderPath = '/' . $orderPath;
        }

        $url = $baseUrl . $orderPath;
        if (!preg_match('#^https://api\.ozvinoo\.xyz(?::\d+)?/#i', $url)) {
            return ['ok' => false, 'error' => 'OZVinoo endpoint is outside the allowed host.'];
        }

        $payload = [
            'service' => $serviceCode,
            'target' => (string) $order['target'],
            'quantity' => max(1, (int) ($product['service_value'] ?? 1)),
            'reference' => (string) $order['order_code'],
        ];

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== '') {
            $headerValue = trim($authPrefix . ' ' . $apiKey);
            $headers[] = $authHeader . ': ' . $headerValue;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Unable to initialise OZVinoo request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'OZVinoo request failed.'];
        }

        $decoded = json_decode((string) $raw, true);
        $response = is_array($decoded) ? $decoded : ['raw' => mb_substr((string) $raw, 0, 2000, 'UTF-8')];
        $success = $http >= 200 && $http < 300
            && (!isset($response['success']) || (bool) $response['success'])
            && (!isset($response['ok']) || (bool) $response['ok']);

        $reference = '';
        foreach (['id', 'order_id', 'orderId', 'reference'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key])) {
                $reference = (string) $response[$key];
                break;
            }
        }

        return [
            'ok' => $success,
            'reference' => $reference,
            'response' => ['http_status' => $http, 'body' => $response],
            'error' => $success ? '' : ('OZVinoo returned HTTP ' . $http),
        ];
    }

    private static function markFailed(PDO $pdo, int $orderId, string $error, $response = null): array
    {
        $payload = json_encode(
            ['error' => $error, 'response' => $response],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $stmt = $pdo->prepare(
            "UPDATE digital_service_orders
             SET status = ?, provider_response = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([self::STATUS_FAILED, is_string($payload) ? $payload : $error, $orderId]);

        return [
            'ok' => false,
            'error' => $error,
            'order' => self::findOrder($pdo, $orderId),
        ];
    }

    private static function setting(PDO $pdo, string $key, string $default = ''): string
    {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM digital_service_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? $default : (string) $value;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

