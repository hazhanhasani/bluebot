<?php
function readJsonFileIfExists($path, $default = [])
{
    if (!is_file($path)) {
        return $default;
    }

    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return $default;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}
function vpnbotUserDataPath($userId): ?string
{
    $userId = trim((string) $userId);
    if ($userId === '' || !ctype_digit($userId)) {
        return null;
    }

    return __DIR__ . '/data/' . $userId . '/' . $userId . '.json';
}

function vpnbotReadUserData($userId): array
{
    $path = vpnbotUserDataPath($userId);
    return $path !== null ? readJsonFileIfExists($path, ['Balance' => 0]) : ['Balance' => 0];
}

function vpnbotAdjustWallet($userId, $delta): ?int
{
    $path = vpnbotUserDataPath($userId);
    $delta = is_numeric($delta) ? (int) $delta : 0;
    if ($path === null) {
        return null;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return null;
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return null;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $data = bluebotJsonArray(is_string($raw) ? $raw : '', ['Balance' => 0]);
    $current = is_numeric($data['Balance'] ?? null) ? (int) $data['Balance'] : 0;
    $newBalance = $current + $delta;
    $data['Balance'] = $newBalance;

    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $written = false;
    if ($encoded !== false) {
        rewind($handle);
        $written = ftruncate($handle, 0)
            && fwrite($handle, $encoded) !== false
            && fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $written ? $newBalance : null;
}

function vpnbotCreditWallet($userId, $amount): ?int
{
    $amount = is_numeric($amount) ? (int) $amount : 0;
    return $amount >= 0 ? vpnbotAdjustWallet($userId, $amount) : null;
}

function DirectPaymentbot($order_id, $image = 'images.jpg')
{
    global $Confirm_pay, $from_id, $message_id, $ApiToken;

    $orderId = trim((string) $order_id);
    if ($orderId === '') {
        return false;
    }

    $payment = select("Payment_report", "*", "id_order", $orderId, "select");
    if (!is_array($payment)) {
        return false;
    }

    $paymentBot = trim((string) ($payment['bottype'] ?? ''));
    if ($paymentBot !== '' && isset($ApiToken) && $paymentBot !== (string) $ApiToken) {
        bluebotLog('warning', 'Blocked cross-agent payment confirmation', [
            'order_id' => $orderId,
        ]);
        return false;
    }

    if (($payment['payment_Status'] ?? '') === 'paid') {
        return true;
    }

    if (!claimPaymentPaid($orderId)) {
        $latestStatus = (string) selectValue(
            "Payment_report",
            "payment_Status",
            "id_order",
            $orderId,
            ''
        );
        return $latestStatus === 'paid';
    }

    $userId = trim((string) ($payment['id_user'] ?? ''));
    $buyer = $userId !== '' ? select("user", "*", "id", $userId, "select") : false;
    if (!is_array($buyer)) {
        markPaymentDeliveryError($orderId, 'agent buyer record missing');
        return false;
    }

    $newBalance = vpnbotCreditWallet($userId, $payment['price'] ?? 0);
    if ($newBalance === null) {
        markPaymentDeliveryError($orderId, 'agent wallet file update failed');
        return false;
    }

    foreach (['Processing_value', 'Processing_value_one', 'Processing_value_tow', 'Processing_value_four'] as $column) {
        update("user", $column, "0", "id", $buyer['id']);
    }

    $formattedPrice = number_format((float) ($payment['price'] ?? 0), 0);
    if (in_array((string) ($payment['Payment_Method'] ?? ''), ['cart to cart', 'arze digital offline'], true)) {
        $textConfirm = "⭕️ یک پرداخت جدید انجام شده است
افزایش موجودی.
👤 شناسه کاربر: <code>" . htmlspecialchars((string) $buyer['id'], ENT_QUOTES, 'UTF-8') . "</code>
🛒 کد پیگیری پرداخت: " . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . "
⚜️ نام کاربری: @" . htmlspecialchars((string) ($buyer['username'] ?? ''), ENT_QUOTES, 'UTF-8') . "
💸 مبلغ پرداختی: {$formattedPrice} تومان
✍️ توضیحات: " . htmlspecialchars((string) ($payment['dec_not_confirmed'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (!empty($from_id) && !empty($message_id)) {
            Editmessagetext($from_id, $message_id, $textConfirm, $Confirm_pay ?? null);
        }
    }

    sendmessage(
        $userId,
        "💎 مبلغ {$formattedPrice} تومان به کیف پول شما اضافه شد.

🛒 کد پیگیری: <code>" . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . "</code>
💰 موجودی جدید: " . number_format($newBalance) . " تومان",
        null,
        'HTML'
    );

    bluebotAudit('agent.payment_delivered', [
        'order_id' => $orderId,
        'user_id' => $userId,
        'balance' => $newBalance,
    ]);

    return true;
}
function channel_check($id_channel)
{
    global $from_id;
    if (isTelegramChatIdEmpty($id_channel)) {
        return [];
    }
    $channel_link = array();
    $response = telegram('getChatMember', [
        'chat_id' => $id_channel,
        'user_id' => $from_id
    ]);
    if ($response['ok']) {
        if (!in_array($response['result']['status'], ['member', 'creator', 'administrator'])) {
            $channel_link[] = $id_channel;
        }
    }

    if (count($channel_link) == 0) {
        return [];
    } else {
        return $channel_link;
    }
}
