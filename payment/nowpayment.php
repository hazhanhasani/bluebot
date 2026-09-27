<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';
$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$setting = is_array($setting) ? $setting : [];

$topicRow = select("topicid", "idreport", "report", "paymentreport", "select");
$paymentreports = is_array($topicRow) ? ($topicRow['idreport'] ?? null) : null;

$textbotlang = languagechange();
$data = json_decode((string) file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    exit('invalid request');
}

$paymentStatus = trim((string) ($data['payment_status'] ?? ''));
$paymentId = trim((string) ($data['payment_id'] ?? ''));
if ($paymentStatus === "finished" && $paymentId !== '' && strlen($paymentId) <= 255) {
    $pay = StatusPayment($paymentId);
    if (!is_array($pay) || ($pay['payment_status'] ?? '') !== "finished") {
        return;
    }
    $Payment_report = select("Payment_report", "*", "id_order", (string) ($pay['order_id'] ?? ''), "select");
    if ($Payment_report && $Payment_report['Payment_Method'] === "nowpayment" && (string) $Payment_report['dec_not_confirmed'] === (string) ($pay['invoice_id'] ?? '')) {
        if (!claimPaymentPaid($Payment_report['id_order']))
            return;
        try {
            DirectPayment($Payment_report['id_order'], "../images.jpg");
        } catch (Throwable $directPaymentError) {
            error_log("DirectPayment failed for order {$Payment_report['id_order']}: " . $directPaymentError->getMessage());
            markPaymentDeliveryError($Payment_report['id_order'], $directPaymentError->getMessage());
            return;
        }
        $pricecashback = (float) getPaySettingValue('cashbacknowpayment', 0);
        $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
        if (!is_array($Balance_id)) {
            bluebotLog('warning', 'NOWPayments paid order has no buyer record', [
                'order_id' => (string) $Payment_report['id_order'],
                'user_id' => (string) $Payment_report['id_user'],
            ]);
            return;
        }

        if ($pricecashback > 0) {
            $result = ($Payment_report['price'] * $pricecashback) / 100;
            $Balance_confrim = intval($Balance_id['Balance']) + $result;
            update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
            $pricecashback = number_format($pricecashback);
            $text_report = sprintf($textbotlang['paymentGateway']['giftReport'], $result);
            sendmessage($Balance_id['id'], $text_report, null, 'HTML');
        }
        $text_reportpayment = sprintf(
            $textbotlang['paymentGateway']['reportNowpayment'],
            (string) ($Balance_id['username'] ?? ''),
            (string) ($Balance_id['id'] ?? ''),
            (string) ($Payment_report['price'] ?? 0),
            (string) ($pay['actually_paid'] ?? '')
        );
        if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => $text_reportpayment,
                'parse_mode' => "HTML"
            ]);
        }
    }
}