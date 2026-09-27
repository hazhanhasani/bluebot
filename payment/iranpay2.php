<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Support/JalaliDate.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();

function cubepay_wants_html()
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && strpos(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html') !== false;
}

function cubepay_emit($state, $texts = null, $orderId = null, $price = null, $lang = 'fa')
{
    $ok = in_array($state, ['success', 'already', 'delivery_failed'], true);

    if (!cubepay_wants_html()) {
        echo json_encode(array("status" => $ok));
        return;
    }

    $pg = is_array($texts) && isset($texts['paymentGateway']) ? $texts['paymentGateway'] : [];
    $pick = function ($key, $fallback) use ($pg) {
        return isset($pg[$key]) && $pg[$key] !== '' ? $pg[$key] : $fallback;
    };

    $map = [
        'success' => ['✓', '#2ecc71', $pick('resultSuccessTitle', 'Payment completed'), $pick('resultSuccessText', '')],
        'already' => ['✓', '#2ecc71', $pick('resultAlreadyTitle', 'Already confirmed'), $pick('resultAlreadyText', '')],
        'failed' => ['✕', '#e74c3c', $pick('resultFailedTitle', 'Payment was not confirmed'), $pick('resultFailedText', '')],
        'delivery_failed' => ['!', '#e67e22', $pick('statusDeliveryFailed', 'Payment confirmed'), $pick('deliveryFailed', 'Service delivery is pending review.')],
        'expired' => ['⏱', '#e67e22', $pick('resultExpiredTitle', 'This payment has expired'), $pick('resultExpiredText', '')],
        'notfound' => ['?', '#e67e22', $pick('resultNotFoundTitle', 'Transaction not found'), $pick('resultNotFoundText', '')],
    ];
    list($icon, $colour, $title, $body) = isset($map[$state]) ? $map[$state] : $map['failed'];

    $dir = in_array($lang, ['fa', 'ar'], true) ? 'rtl' : 'ltr';
    $align = $dir === 'rtl' ? 'right' : 'left';

    $rows = '';
    if ($orderId !== null && $orderId !== '') {
        $rows .= '<div class="row"><span>' . htmlspecialchars($pick('resultOrderLabel', 'Order id'), ENT_QUOTES, 'UTF-8')
            . '</span><b>' . htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') . '</b></div>';
    }
    if ($price !== null && $price > 0) {
        $rows .= '<div class="row"><span>' . htmlspecialchars($pick('resultAmountLabel', 'Amount'), ENT_QUOTES, 'UTF-8')
            . '</span><b>' . number_format((float) $price) . '</b></div>';
    }

    global $usernamebot;
    $botLink = '';
    $botHandle = ltrim(trim((string) $usernamebot), '@');
    if ($botHandle !== '' && strpos($botHandle, '{') === false) {
        $botLink = '<a class="btn" href="https://t.me/' . htmlspecialchars($botHandle, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($pick('resultBackToBot', 'Back to the bot'), ENT_QUOTES, 'UTF-8') . '</a>';
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '" dir="' . $dir . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#0f1115;color:#e8eaed;font-family:Tahoma,Arial,sans-serif;padding:20px}'
        . '.card{width:100%;max-width:360px;background:#181b21;border:1px solid #262a33;border-radius:18px;'
        . 'padding:28px 24px;text-align:center}'
        . '.icon{width:58px;height:58px;line-height:58px;border-radius:50%;margin:0 auto 14px;'
        . 'font-size:28px;color:#fff;background:' . $colour . '}'
        . 'h1{font-size:17px;margin:0 0 8px}p{color:#9aa0aa;font-size:13px;line-height:2;margin:0}'
        . '.row{display:flex;justify-content:space-between;gap:12px;font-size:12.5px;'
        . 'border-top:1px solid #262a33;padding:9px 0;color:#9aa0aa}.row b{color:#e8eaed}'
        . '.rows{margin-top:18px;text-align:' . $align . '}'
        . '.btn{display:block;margin-top:18px;padding:11px;border-radius:11px;background:#2b6ef2;'
        . 'color:#fff;text-decoration:none;font-size:13.5px}'
        . '</style></head><body><div class="card">'
        . '<div class="icon">' . $icon . '</div>'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>'
        . ($rows !== '' ? '<div class="rows">' . $rows . '</div>' : '')
        . $botLink
        . '</div></body></html>';
}

$rawInput = file_get_contents('php://input');
$jsonInput = $rawInput ? json_decode($rawInput, true) : null;
$jsonInput = is_array($jsonInput) ? $jsonInput : [];

$callback_order_id = trim((string) ($jsonInput['order_id'] ?? ($_REQUEST['order_id'] ?? '')));
$callback_sig = trim((string) ($jsonInput['sig'] ?? ($_REQUEST['sig'] ?? '')));
$callback_status = trim((string) ($jsonInput['status'] ?? ($_REQUEST['status'] ?? '')));
$callback_amount = trim((string) ($jsonInput['amount'] ?? $jsonInput['amount_toman'] ?? ($_REQUEST['amount'] ?? ($_REQUEST['amount_toman'] ?? ''))));
$isSignedCallback = ($callback_sig !== '' && $callback_order_id !== '');

$authority = trim((string) ($jsonInput['authority'] ?? ($_REQUEST['authority'] ?? '')));
$data_order_id = $callback_order_id;

if ($data_order_id === '' || strlen($data_order_id) > 2000 || strlen($authority) > 255 || strlen($callback_sig) > 512) {
    cubepay_emit('notfound', languagechange(dirname(__DIR__)), '', null);
    return;
}

$Payment_report = select("Payment_report", "*", "id_order", $data_order_id, "select");
if (!$Payment_report) {
    cubepay_emit('notfound', languagechange(dirname(__DIR__)), $data_order_id, null);
    return;
}
$token_cubepay = trim((string) getPaySettingValue('apiternado', ''));
if ($token_cubepay === '' || $token_cubepay === '0' || $Payment_report['Payment_Method'] !== "Currency Rial 2") {
    cubepay_emit('notfound', languagechange(dirname(__DIR__)), $data_order_id, null);
    return;
}

$payer_row = select("user", "*", "id", $Payment_report['id_user'], "select");
$page_lang = is_array($payer_row) && !empty($payer_row['lang']) ? $payer_row['lang'] : 'fa';
$page_texts = languagechange(dirname(__DIR__), $page_lang);

if ($Payment_report['payment_Status'] == "expire") {
    cubepay_emit('expired', $page_texts, $data_order_id, $Payment_report['price'], $page_lang);
    return;
}
$setting = select("setting", "*", null, null, "select");
$setting = is_array($setting) ? $setting : [];
$price = (float) ($Payment_report['price'] ?? 0);

if ($Payment_report['payment_Status'] == "paid") {
    cubepay_emit('already', $page_texts, $data_order_id, $price, $page_lang);
    return;
}
$paymentAccepted = false;
$response = null;
if ($isSignedCallback) {
    $expected_sig = hash_hmac(
        'sha256',
        $callback_order_id . '|' . $callback_status . '|' . $callback_amount,
        (string) $token_cubepay
    );
    $signatureValid = hash_equals($expected_sig, $callback_sig);
    if (!$signatureValid) {
        error_log("CubePay: invalid callback signature for order {$data_order_id}");
    }
    $paymentAccepted = $signatureValid
        && $callback_status === 'paid'
        && (float) $callback_amount >= (float) $price;
    $response = [
        'order_id' => $callback_order_id,
        'status' => $callback_status,
        'amount_toman' => $callback_amount,
        'verified_by' => 'signature',
    ];
} elseif ($authority !== '') {
    $ch = curl_init('https://cubevps.ir/smspay/api/verify-payment.php');
    if ($ch === false) {
        cubepay_emit('failed', $page_texts, $data_order_id, $price, $page_lang);
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS => json_encode(['authority' => $authority]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token_cubepay,
        ],
    ]);

    $result = curl_exec($ch);
    $curlError = $result === false ? curl_error($ch) : '';
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($result)) {
        bluebotLog('warning', 'CubePay verification request failed', [
            'order_id' => $data_order_id,
            'error' => $curlError,
        ]);
        $response = [];
    } else {
        $response = json_decode($result, true);
        $response = is_array($response) ? $response : [];
    }

    $amount_rial = intval($price) * 10;
    $isVerifiedForThisOrder = is_array($response)
        && isset($response['order_id'], $response['amount'])
        && (string) $response['order_id'] === (string) $data_order_id
        && intval($response['amount']) >= $amount_rial;

    $paymentAccepted = (($httpCode == 200 && !empty($response['success'])) || $httpCode == 409)
        && $isVerifiedForThisOrder;
}

if (!$paymentAccepted) {
    cubepay_emit('failed', $page_texts, $data_order_id, $price, $page_lang);
    return;
}

if (!claimPaymentPaid($Payment_report['id_order'])) {
    $latestPayment = select("Payment_report", "*", "id_order", $Payment_report['id_order'], "select");
    $state = is_array($latestPayment) && ($latestPayment['payment_Status'] ?? '') === 'delivery_error'
        ? 'delivery_failed'
        : 'already';
    cubepay_emit($state, $page_texts, $data_order_id, $price, $page_lang);
    return;
}

$textbotlang = languagechange(dirname(__DIR__), $page_lang);
try {
    DirectPayment($data_order_id, "../images.jpg");
} catch (Throwable $directPaymentError) {
    error_log("DirectPayment failed for order {$data_order_id}: " . $directPaymentError->getMessage());
    markPaymentDeliveryError($data_order_id, $directPaymentError->getMessage());
    cubepay_emit('delivery_failed', $page_texts, $data_order_id, $price, $page_lang);
    return;
}

$pricecashback = (float) getPaySettingValue('chashbackiranpay2', 0);
$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
if (!is_array($Balance_id)) {
    bluebotLog('warning', 'CubePay paid order has no buyer record', [
        'order_id' => (string) $Payment_report['id_order'],
        'user_id' => (string) $Payment_report['id_user'],
    ]);
    cubepay_emit('success', $page_texts, $data_order_id, $price, $page_lang);
    return;
}

if ($pricecashback > 0) {
    $result_cashback = ((float) $Payment_report['price'] * $pricecashback) / 100;
    $Balance_confrim = (int) ($Balance_id['Balance'] ?? 0) + $result_cashback;
    update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
    $text_report = sprintf($textbotlang['paymentGateway']['giftReport'], $result_cashback);
    sendmessage($Balance_id['id'], $text_report, null, 'HTML');
}

$topicRow = select("topicid", "idreport", "report", "paymentreport", "select");
$paymentreports = is_array($topicRow) ? ($topicRow['idreport'] ?? null) : null;
$text_reportpayment = sprintf(
    $textbotlang['paymentGateway']['reportTronado'],
    (string) ($Balance_id['username'] ?? ''),
    (string) ($Balance_id['id'] ?? ''),
    number_format($price)
);

$database = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$statement = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = :dec_not_confirmed WHERE id_order = :id_order");
$statement->bindValue(':dec_not_confirmed', $database !== false ? $database : '{}');
$statement->bindValue(':id_order', $Payment_report['id_order']);
$statement->execute();

if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_reportpayment,
        'parse_mode' => "HTML"
    ]);
}

cubepay_emit('success', $page_texts, $data_order_id, $price, $page_lang);
