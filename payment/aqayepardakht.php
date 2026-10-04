<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../src/Support/JalaliDate.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange();

$invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
$transid = trim((string) ($_POST['transid'] ?? ''));
$payment_status = $textbotlang['paymentGateway']['statusFailed'];
$dec_payment_status = '';
$price = 0;

if (
    $invoice_id === ''
    || strlen($invoice_id) > 2000
    || $transid === ''
    || strlen($transid) > 255
) {
    http_response_code(400);
    exit('invalid request');
}

$setting = select('setting', '*');
$payment = select('Payment_report', '*', 'id_order', $invoice_id, 'select');

if (!is_array($payment) || ($payment['Payment_Method'] ?? '') !== 'aqayepardakht') {
    http_response_code(404);
    exit('order not found');
}

if (($payment['payment_Status'] ?? '') === 'expire') {
    exit('order expired');
}

$expectedTransid = trim((string) ($payment['dec_not_confirmed'] ?? ''));
if ($expectedTransid === '') {
    bluebotLog('warning', 'AqayePardakht order has no stored transaction binding', [
        'order_id' => $invoice_id,
    ]);
    http_response_code(409);
    exit('این فاکتور قدیمی شناسه تراکنش ثبت‌شده ندارد. لطفاً یک فاکتور جدید ایجاد کنید؛ اگر پرداخت کرده‌اید با پشتیبانی تماس بگیرید.');
}
if (!hash_equals($expectedTransid, $transid)) {
    bluebotLog('warning', 'AqayePardakht callback transaction does not match order', [
        'order_id' => $invoice_id,
    ]);
    http_response_code(400);
    exit('invalid transaction');
}

$price = (int) ($payment['price'] ?? 0);
if ($price <= 0) {
    bluebotLog('warning', 'AqayePardakht rejected invalid order amount', [
        'order_id' => $invoice_id,
    ]);
    http_response_code(400);
    exit('invalid amount');
}

$merchant = trim((string) getPaySettingValue('merchant_id_aqayepardakht', ''));

if ($merchant === '' || $merchant === '0') {
    bluebotLog('error', 'AqayePardakht merchant is not configured', [
        'order_id' => $invoice_id,
    ]);
    http_response_code(503);
    exit('payment gateway unavailable');
}

$payload = json_encode([
    'pin' => $merchant,
    'amount' => $price,
    'transid' => $transid,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$verified = false;
if (is_string($payload)) {
    $ch = curl_init('https://panel.aqayepardakht.ir/api/v2/verify');
    if ($ch === false) {
        bluebotLog('error', 'AqayePardakht HTTP client initialization failed', [
            'order_id' => $invoice_id,
        ]);
        http_response_code(503);
        exit('payment gateway unavailable');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT_MS => 10000,
        CURLOPT_CONNECTTIMEOUT_MS => 4000,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($payload),
        ],
    ]);

    $rawResult = curl_exec($ch);
    $curlError = $rawResult === false ? curl_error($ch) : '';
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResult === false || $httpCode < 200 || $httpCode >= 300) {
        bluebotLog('warning', 'AqayePardakht verification request failed', [
            'order_id' => $invoice_id,
            'http_code' => $httpCode,
            'error' => $curlError,
        ]);
    } else {
        $result = json_decode($rawResult, true);
        $verified = is_array($result) && (string) ($result['code'] ?? '') === '1';

        if (!$verified) {
            bluebotLog('warning', 'AqayePardakht verification was rejected', [
                'order_id' => $invoice_id,
                'http_code' => $httpCode,
            ]);
        }
    }
}

if ($verified) {
    $payment_status = $textbotlang['paymentGateway']['statusSuccess'];
    $dec_payment_status = $textbotlang['paymentGateway']['descThanks'];

    $claimed = claimPaymentPaid($invoice_id);
    if ($claimed) {
        $deliverySucceeded = true;

        try {
            DirectPayment($invoice_id, '../images.jpg');
        } catch (Throwable $directPaymentError) {
            markPaymentDeliveryError($invoice_id, $directPaymentError->getMessage());
            $deliverySucceeded = false;
            $payment_status = $textbotlang['paymentGateway']['statusDeliveryFailed'];
            $dec_payment_status = $textbotlang['paymentGateway']['deliveryFailed'];
        }

        if ($deliverySucceeded) {
            $payment = select('Payment_report', '*', 'id_order', $invoice_id, 'select');
            $buyer = is_array($payment)
                ? select('user', '*', 'id', $payment['id_user'], 'select')
                : false;

            if (is_array($payment) && is_array($buyer)) {
                $cashback = (float) getPaySettingValue('chashbackaqaypardokht', 0);

                if ($cashback > 0) {
                    $reward = ((float) $payment['price'] * $cashback) / 100;
                    $newBalance = (int) ($buyer['Balance'] ?? 0) + $reward;
                    update('user', 'Balance', $newBalance, 'id', $buyer['id']);

                    $giftText = sprintf(
                        $textbotlang['paymentGateway']['giftReport'],
                        $reward
                    );
                    sendmessage($buyer['id'], $giftText, null, 'HTML');
                }

                $topic = select('topicid', 'idreport', 'report', 'paymentreport', 'select');
                $paymentReportTopic = is_array($topic)
                    ? ($topic['idreport'] ?? 0)
                    : 0;

                $reportText = sprintf(
                    $textbotlang['paymentGateway']['reportAqayepardakht'],
                    $payment['id_user'],
                    $buyer['username'] ?? '',
                    number_format((float) $payment['price'])
                );

                if (is_array($setting) && strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
                    telegram('sendmessage', [
                        'chat_id' => $setting['Channel_Report'],
                        'message_thread_id' => $paymentReportTopic,
                        'text' => $reportText,
                        'parse_mode' => 'HTML',
                    ]);
                }
            } else {
                bluebotLog('warning', 'AqayePardakht delivery completed but buyer record was unavailable', [
                    'order_id' => $invoice_id,
                ]);
            }
        }
    } else {
        $latestPayment = select('Payment_report', '*', 'id_order', $invoice_id, 'select');
        if (is_array($latestPayment) && ($latestPayment['payment_Status'] ?? '') === 'delivery_error') {
            $payment_status = $textbotlang['paymentGateway']['statusDeliveryFailed'];
            $dec_payment_status = $textbotlang['paymentGateway']['deliveryFailed'];
        }
    }
}

$price = number_format($price);
?>
<html>
<head>
    <title><?php echo $textbotlang['paymentGateway']['invoiceTitle'] ?></title>
    <style>
    @font-face {
    font-family: 'vazir';
    src: url('/Vazir.eot');
    src: local('☺'), url('../fonts/Vazir.woff') format('woff'), url('../fonts/Vazir.ttf') format('truetype');
}

        body {
            font-family:vazir;
            background-color: #f2f2f2;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .confirmation-box {
            background-color: #ffffff;
            border-radius: 8px;
            width:25%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 40px;
            text-align: center;
        }

        h1 {
            color: #333333;
            margin-bottom: 20px;
        }

        p {
            color: #666666;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="confirmation-box">
        <h1><?php echo htmlspecialchars((string) $payment_status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p><?php echo $textbotlang['paymentGateway']['invoiceTransactionNo'] ?><span><?php echo htmlspecialchars((string) $invoice_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></p>
        <p><?php echo $textbotlang['paymentGateway']['invoiceAmount'] ?>  <span><?php echo htmlspecialchars((string) $price, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php echo $textbotlang['paymentGateway']['invoiceAmountUnit'] ?></p>
        <p><?php echo $textbotlang['paymentGateway']['invoiceDate'] ?> <span>  <?php echo jdate('Y/m/d')  ?>  </span></p>
        <p><?php echo htmlspecialchars((string) $dec_payment_status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </div>
</body>
</html>
