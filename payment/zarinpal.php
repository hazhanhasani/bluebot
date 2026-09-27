<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Support/JalaliDate.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../panels.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange(dirname(__DIR__));

$Authority = trim((string) ($_GET['Authority'] ?? ''));
$StatusPayment = trim((string) ($_GET['Status'] ?? ''));
if ($Authority === '' || strlen($Authority) > 255) {
    http_response_code(400);
    exit('invalid request');
}
$setting = select("setting", "*");
$setting = is_array($setting) ? $setting : [];

$PaySetting = trim((string) getPaySettingValue('merchant_zarinpal', ''));
if ($PaySetting === '' || $PaySetting === '0') {
    http_response_code(503);
    exit('payment gateway unavailable');
}

$Payment_reports = select("Payment_report", "*", "dec_not_confirmed", $Authority, "select");
if (!is_array($Payment_reports) || ($Payment_reports['Payment_Method'] ?? '') !== 'zarinpal') {
    http_response_code(404);
    exit('order not found');
}
if (($Payment_reports['payment_Status'] ?? '') === "expire") {
    exit('order expired');
}
$price = $Payment_reports['price'];
$invoice_id = $Payment_reports['id_order'];
// verify Transaction
$dec_payment_status = "";
$payment_status = "";
if ($StatusPayment === "OK") {
    $curl = curl_init();
    if ($curl === false) {
        http_response_code(503);
        exit('payment gateway unavailable');
    }

curl_setopt_array($curl, array(
  CURLOPT_URL => 'https://api.zarinpal.com/pg/v4/payment/verify.json',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_HTTPHEADER => array(
    'Content-Type: application/json',
    'Accept: application/json'
  ),
));
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
  "merchant_id" => $PaySetting,
  "amount"=> $price,
  "authority" => $Authority,
  "description" => $Payment_reports['id_user']
        ]));
$rawResponse = curl_exec($curl);
$curlError = $rawResponse === false ? curl_error($curl) : '';
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if (!is_string($rawResponse) || $httpCode < 200 || $httpCode >= 300) {
    bluebotLog('warning', 'ZarinPal verification request failed', [
        'order_id' => (string) $invoice_id,
        'http_code' => $httpCode,
        'error' => $curlError,
    ]);
    $response = [];
} else {
    $response = json_decode($rawResponse, true);
    $response = is_array($response) ? $response : [];
}

$payment_status = $textbotlang['paymentGateway']['zarinpalErrors'][$response['errors']['code'] ?? ''] ?? '';
if (($response['data']['message'] ?? '') === "Verified" || ($response['data']['message'] ?? '') === "Paid") {
    $payment_status = $textbotlang['paymentGateway']['statusSuccess'];
    $dec_payment_status = $textbotlang['paymentGateway']['descThanks'];
    $Payment_report = select("Payment_report", "*", "id_order", $invoice_id,"select");
    if (claimPaymentPaid($invoice_id)) {
    try {
        DirectPayment($invoice_id,"../images.jpg");
    } catch (Throwable $directPaymentError) {
        error_log("DirectPayment failed for order {$invoice_id}: " . $directPaymentError->getMessage());
        markPaymentDeliveryError($invoice_id, $directPaymentError->getMessage());
        return;
    }
    $pricecashback = (float) getPaySettingValue('chashbackzarinpal', 0);
    $Balance_id = is_array($Payment_report)
        ? select("user", "*", "id", $Payment_report['id_user'], "select")
        : false;

    if (!is_array($Payment_report) || !is_array($Balance_id)) {
        bluebotLog('warning', 'ZarinPal paid order has no buyer record', [
            'order_id' => (string) $invoice_id,
        ]);
        return;
    }

    if ($pricecashback > 0) {
        $result = ($Payment_report['price'] * $pricecashback) / 100;
        $Balance_confrim = intval($Balance_id['Balance']) +$result;
        update("user","Balance",$Balance_confrim, "id",$Balance_id['id']); 
        $pricecashback =  number_format($pricecashback);
        $text_report = sprintf($textbotlang['paymentGateway']['giftReport'], $result);
        sendmessage($Balance_id['id'], $text_report, null, 'HTML');
    }
    $topicRow = select("topicid", "idreport", "report", "paymentreport", "select");
    $paymentreports = is_array($topicRow) ? ($topicRow['idreport'] ?? null) : null;
    $refcode = (string) ($response['data']['ref_id'] ?? '');
    $cart_number = (string) ($response['data']['card_pan'] ?? '');
    $price = number_format((float) $price);
    $text_report = sprintf(
        $textbotlang['paymentGateway']['reportZarinpal'],
        (string) $Payment_report['id_user'],
        (string) ($Balance_id['username'] ?? ''),
        $price,
        $refcode,
        $cart_number
    );
    if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
        telegram('sendmessage',[
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_report,
        'parse_mode' => "HTML"
        ]);
    }
}
}else {
        $payment_status = $textbotlang['paymentGateway']['zarinpalResultCodes'][$response['errors']['code'] ?? ''] ?? $textbotlang['paymentGateway']['statusFailed'];
     $dec_payment_status = "";
}
}
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
