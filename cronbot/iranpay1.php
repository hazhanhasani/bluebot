<?php
chdir(__DIR__);
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../function.php';
require __DIR__ . '/../vendor/autoload.php';
$ManagePanel = new ManagePanel();
$setting = select("setting", "*", null, null, "select");
$setting = is_array($setting) ? $setting : [];
$paymentTopic = select("topicid", "idreport", "report", "paymentreport", "select");
$paymentreports = is_array($paymentTopic) ? ($paymentTopic['idreport'] ?? null) : null;
$textbotlang = languagechange();
$list_service = $pdo->prepare("SELECT * FROM Payment_report WHERE payment_Status = 'Unpaid' AND Payment_Method = 'Currency Rial 3' ORDER BY RAND() LIMIT 10");
$list_service->execute();
while ($Payment_report = ($list_service)->fetch(PDO::FETCH_ASSOC)) {
    $StatusPayment = verifpay($Payment_report['dec_not_confirmed']);
    if (!is_string($StatusPayment))
        continue;
    $StatusPayment = json_decode($StatusPayment, true);
    if (!is_array($StatusPayment))
        continue;
    if (empty($StatusPayment['success']) || !is_array($StatusPayment['data'] ?? null))
        continue;
    if (($StatusPayment['data']['status'] ?? '') !== "approved")
        continue;
    if (!claimPaymentPaid($Payment_report['id_order']))
        continue;
    update("Payment_report", "dec_not_confirmed", json_encode($StatusPayment['data']), "id_order", $Payment_report['id_order']);

    try {
        DirectPayment($Payment_report['id_order']);
    } catch (Throwable $deliveryError) {
        markPaymentDeliveryError($Payment_report['id_order'], $deliveryError->getMessage());
        bluebotLog('error', 'IranPay cron payment delivery failed', [
            'order_id' => (string) $Payment_report['id_order'],
            'error' => $deliveryError->getMessage(),
        ]);
        continue;
    }

    $cashbackRow = select("PaySetting", "ValuePay", "NamePay", "chashbackiranpay1", "select");
    $pricecashback = is_array($cashbackRow) ? ($cashbackRow['ValuePay'] ?? '0') : '0';
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    if (!is_array($Balance_id)) {
        bluebotLog('warning', 'IranPay paid order has no buyer record', [
            'order_id' => (string) $Payment_report['id_order'],
            'user_id' => (string) $Payment_report['id_user'],
        ]);
        continue;
    }
    if ($pricecashback != "0") {
        $result = ($Payment_report['price'] * $pricecashback) / 100;
        $Balance_confrim = intval($Balance_id['Balance']) + $result;
        update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
        $text_report = sprintf($textbotlang['users']['Balance']['giftDepositIranpay'], $result);
        sendmessage($Balance_id['id'], $text_report, null, 'HTML');
    }
    $text_reportpayment = sprintf($textbotlang['Admin']['reportgroup']['newPaymentIranpay'], $Balance_id['username'], $Balance_id['id'], $Payment_report['price']);
    if (strlen($setting['Channel_Report']) > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $paymentreports,
            'text' => $text_reportpayment,
            'parse_mode' => "HTML"
        ]);
    }
    update("Payment_report", "dec_not_confirmed", json_encode($StatusPayment), "id_order", $Payment_report['id_order']);
}