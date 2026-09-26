<?php

declare(strict_types=1);

ini_set('error_log', 'error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange();

function blupalCallbackFinish(bool $ok, string $title, string $detail, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $safeTitle . '</title>'
        . '<style>'
        . 'body{margin:0;background:#f5f7fb;font-family:Tahoma,Arial,sans-serif;color:#152033;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}'
        . '.card{width:min(520px,100%);background:#fff;border-radius:20px;padding:34px 26px;box-shadow:0 18px 50px rgba(20,35,70,.10);text-align:center}'
        . '.icon{font-size:54px;line-height:1;margin-bottom:18px}.ok{color:#16a34a}.wait{color:#2563eb}.bad{color:#dc2626}'
        . 'h1{font-size:22px;margin:0 0 12px}p{font-size:15px;line-height:2;color:#64748b;margin:0}'
        . '.hint{margin-top:22px;font-size:13px;color:#94a3b8}'
        . '</style></head><body><main class="card">'
        . '<div class="icon ' . ($ok ? 'ok' : 'wait') . '">' . ($ok ? '&#10003;' : '&#8987;') . '</div>'
        . '<h1>' . $safeTitle . '</h1><p>' . $safeDetail . '</p>'
        . '<div class="hint">می‌توانید این صفحه را ببندید و به ربات برگردید.</div>'
        . '</main></body></html>';
    exit;
}

$successTitle = $textbotlang['paymentGateway']['statusSuccess'] ?? 'پرداخت موفق';
$failedTitle = $textbotlang['paymentGateway']['statusFailed'] ?? 'پرداخت ناموفق';

$invoiceId = trim((string) (
    $_GET['invoice_id']
    ?? $_GET['invoiceId']
    ?? $_GET['invoice']
    ?? $_GET['id']
    ?? $_POST['invoice_id']
    ?? $_POST['invoiceId']
    ?? $_POST['invoice']
    ?? $_POST['id']
    ?? ''
));

$orderId = trim((string) ($_GET['order_id'] ?? $_POST['order_id'] ?? ''));

if ($invoiceId === '' && $orderId !== '') {
    $local = select('Payment_report', '*', 'id_order', $orderId, 'select');
    if (is_array($local) && (string) ($local['Payment_Method'] ?? '') === 'blupal') {
        $invoiceId = trim((string) ($local['dec_not_confirmed'] ?? ''));
    }
}

if ($invoiceId === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $invoiceId)) {
    blupalCallbackFinish(false, $failedTitle, 'شناسه فاکتور بلوپال در بازگشت پرداخت ارسال نشده است.', 400);
}

$payment = bluebotBlupalFindPaymentByInvoice($invoiceId);
if (!$payment) {
    blupalCallbackFinish(false, $failedTitle, 'تراکنش مربوط به این فاکتور در ربات پیدا نشد.', 404);
}

if ((string) ($payment['payment_Status'] ?? '') === 'paid') {
    blupalCallbackFinish(true, $successTitle, 'پرداخت شما قبلاً تایید و سفارش پردازش شده است.');
}

$result = bluebotBlupalSettle($invoiceId);
$state = strtolower((string) ($result['state'] ?? 'verify_failed'));

if (!empty($result['ok'])) {
    blupalCallbackFinish(true, $successTitle, 'پرداخت با موفقیت تایید شد و سفارش شما پردازش شد.');
}

if ($state === 'delivery_error') {
    blupalCallbackFinish(false, $failedTitle, 'پرداخت تایید شده است اما تحویل سفارش با خطا مواجه شد. با پشتیبانی تماس بگیرید.', 500);
}

if ($state === 'amount_mismatch') {
    blupalCallbackFinish(false, $failedTitle, 'مبلغ پرداخت با مبلغ فاکتور مطابقت ندارد.', 409);
}

if (in_array($state, ['pending', 'canceled', 'expired'], true)) {
    $messages = [
        'pending' => 'پرداخت هنوز در انتظار تایید بلوپال است.',
        'canceled' => 'پرداخت لغو شده است.',
        'expired' => 'مهلت این فاکتور به پایان رسیده است.',
    ];
    blupalCallbackFinish(false, 'وضعیت پرداخت', $messages[$state], 200);
}

blupalCallbackFinish(false, $failedTitle, 'امکان تایید وضعیت پرداخت در این لحظه وجود ندارد. چند لحظه بعد دوباره بررسی کنید.', 503);
