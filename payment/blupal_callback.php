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

function blupalCallbackEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function blupalCallbackStateIcon(string $state): string
{
    return match ($state) {
        'success' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.2 16.2 4.8 11.8l-1.6 1.6 6 6L21 7.6 19.4 6z"/></svg>',
        'pending' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10.01 10.01 0 0 0 12 2Zm1 11h-6v-2h4V6h2Z"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 7h2v6h-2Zm0 8h2v2h-2Zm1-13a10 10 0 1 0 10 10A10.01 10.01 0 0 0 12 2Z"/></svg>',
    };
}

function blupalCallbackFinish(
    string $state,
    string $title,
    string $detail,
    array $meta = [],
    int $httpStatus = 200
): never {
    http_response_code($httpStatus);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $state = in_array($state, ['success', 'pending', 'error'], true) ? $state : 'error';
    $safeTitle = blupalCallbackEscape($title);
    $safeDetail = blupalCallbackEscape($detail);

    $rows = [];
    $labels = [
        'amount' => 'مبلغ پرداخت',
        'invoice_id' => 'شناسه فاکتور',
        'order_id' => 'کد سفارش',
        'transaction_id' => 'شناسه تراکنش',
        'paid_at' => 'زمان پرداخت',
    ];

    foreach ($labels as $key => $label) {
        $value = trim((string) ($meta[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        $rows[] = '<div class="meta-row">'
            . '<span class="meta-label">' . blupalCallbackEscape($label) . '</span>'
            . '<strong class="meta-value">' . blupalCallbackEscape($value) . '</strong>'
            . '</div>';
    }

    $metaHtml = $rows !== []
        ? '<div class="meta-card">' . implode('', $rows) . '</div>'
        : '';

    $icon = blupalCallbackStateIcon($state);
    $badge = match ($state) {
        'success' => 'تایید شده',
        'pending' => 'در حال بررسی',
        default => 'نیاز به بررسی',
    };

    $buttonLabel = $state === 'pending' ? 'بررسی دوباره' : 'بازگشت به ربات';
    $buttonAction = $state === 'pending'
        ? 'location.reload()'
        : 'window.close();setTimeout(()=>history.back(),120)';

    echo '<!doctype html><html lang="fa" dir="rtl"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="theme-color" content="#eff7ff">'
        . '<title>' . $safeTitle . '</title>'
        . '<style>'
        . ':root{color-scheme:light;--ink:#10233f;--muted:#6f8098;--line:rgba(98,123,158,.16);--card:rgba(255,255,255,.82);--shadow:0 32px 90px rgba(45,86,132,.18)}'
        . '*{box-sizing:border-box}html,body{min-height:100%;margin:0}'
        . 'body{font-family:Tahoma,"Segoe UI",Arial,sans-serif;color:var(--ink);background:linear-gradient(145deg,#eef8ff 0%,#f8fbff 48%,#eefaf6 100%);overflow-x:hidden}'
        . '.scene{position:relative;min-height:100vh;display:grid;place-items:center;padding:34px 18px;isolation:isolate}'
        . '.orb{position:fixed;border-radius:999px;filter:blur(10px);opacity:.6;z-index:-2;animation:float 9s ease-in-out infinite}'
        . '.orb.one{width:340px;height:340px;top:-110px;right:-80px;background:radial-gradient(circle at 35% 35%,#4fd7c8,#7cb9ff 72%,transparent 74%)}'
        . '.orb.two{width:310px;height:310px;left:-100px;bottom:-90px;background:radial-gradient(circle at 60% 45%,#9ac7ff,#bdf6df 72%,transparent 74%);animation-delay:-3s}'
        . '.grid{position:fixed;inset:0;z-index:-3;opacity:.32;background-image:linear-gradient(rgba(70,112,158,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(70,112,158,.06) 1px,transparent 1px);background-size:28px 28px;mask-image:linear-gradient(to bottom,#000,transparent 78%)}'
        . '.shell{width:min(560px,100%)}'
        . '.brand{display:flex;align-items:center;justify-content:center;gap:9px;margin-bottom:14px;color:#456078;font-size:13px;font-weight:700;letter-spacing:.1px}'
        . '.brand-dot{width:11px;height:11px;border-radius:50%;background:linear-gradient(135deg,#14c7a6,#4d9cff);box-shadow:0 0 0 6px rgba(35,182,178,.10)}'
        . '.card{position:relative;overflow:hidden;background:var(--card);border:1px solid rgba(255,255,255,.9);border-radius:30px;padding:34px 28px 26px;box-shadow:var(--shadow);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px)}'
        . '.card:before{content:"";position:absolute;inset:0 0 auto;height:4px;background:linear-gradient(90deg,#25c7a5,#57a6ff,#7a7cff)}'
        . '.status-wrap{display:grid;place-items:center;margin-top:2px}'
        . '.status-icon{width:86px;height:86px;border-radius:50%;display:grid;place-items:center;position:relative;box-shadow:inset 0 0 0 1px rgba(255,255,255,.65),0 18px 38px rgba(70,110,150,.16)}'
        . '.status-icon:after{content:"";position:absolute;inset:-9px;border-radius:50%;border:1px solid currentColor;opacity:.12}'
        . '.status-icon svg{width:42px;height:42px;fill:currentColor}'
        . '.success .status-icon{color:#0ba97e;background:linear-gradient(145deg,#dff9ef,#effff9)}'
        . '.pending .status-icon{color:#3b82f6;background:linear-gradient(145deg,#e4efff,#f4f8ff)}'
        . '.error .status-icon{color:#ef5b63;background:linear-gradient(145deg,#ffe8ea,#fff5f6)}'
        . '.badge{display:inline-flex;align-items:center;justify-content:center;margin:22px auto 0;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:700}'
        . '.success .badge{background:#e5f8f1;color:#078864}.pending .badge{background:#eaf2ff;color:#2c68c9}.error .badge{background:#ffedef;color:#cf3d49}'
        . 'h1{margin:14px 0 8px;text-align:center;font-size:clamp(24px,5vw,31px);letter-spacing:-.5px}'
        . '.desc{margin:0 auto;text-align:center;max-width:430px;font-size:14px;line-height:2;color:var(--muted)}'
        . '.meta-card{margin:26px 0 0;border:1px solid var(--line);background:rgba(248,251,255,.72);border-radius:20px;padding:6px 16px}'
        . '.meta-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 2px;border-bottom:1px dashed var(--line)}'
        . '.meta-row:last-child{border-bottom:0}.meta-label{font-size:13px;color:#8290a5}.meta-value{font-size:13px;color:#263b57;direction:ltr;text-align:left;word-break:break-word}'
        . '.action{margin-top:24px;width:100%;border:0;border-radius:16px;padding:14px 18px;font:700 14px Tahoma,"Segoe UI",Arial,sans-serif;color:#fff;cursor:pointer;background:linear-gradient(100deg,#13b991,#439ce9);box-shadow:0 14px 30px rgba(31,161,158,.24);transition:transform .18s ease,box-shadow .18s ease}'
        . '.action:hover{transform:translateY(-1px);box-shadow:0 18px 34px rgba(31,161,158,.3)}.action:active{transform:translateY(1px)}'
        . '.footer{display:flex;justify-content:center;align-items:center;gap:7px;margin-top:17px;color:#98a5b6;font-size:11px}.lock{font-size:13px}'
        . '@keyframes float{0%,100%{transform:translate3d(0,0,0)}50%{transform:translate3d(0,18px,0)}}'
        . '@media(max-width:480px){.scene{padding:20px 12px}.card{padding:30px 20px 22px;border-radius:24px}.meta-row{align-items:flex-start;flex-direction:column;gap:6px}.meta-value{text-align:right;direction:rtl}}'
        . '@media(prefers-reduced-motion:reduce){.orb{animation:none}.action{transition:none}}'
        . '</style></head>'
        . '<body><div class="grid"></div><div class="orb one"></div><div class="orb two"></div>'
        . '<main class="scene"><section class="shell">'
        . '<div class="brand"><span class="brand-dot"></span><span>BlueBot Payments · Blupal</span></div>'
        . '<article class="card ' . $state . '">'
        . '<div class="status-wrap"><div class="status-icon">' . $icon . '</div>'
        . '<div class="badge">' . blupalCallbackEscape($badge) . '</div></div>'
        . '<h1>' . $safeTitle . '</h1>'
        . '<p class="desc">' . $safeDetail . '</p>'
        . $metaHtml
        . '<button class="action" type="button" onclick="' . $buttonAction . '">' . blupalCallbackEscape($buttonLabel) . '</button>'
        . '<div class="footer"><span class="lock">🔒</span><span>وضعیت پرداخت مستقیماً از API بلوپال بررسی شده است</span></div>'
        . '</article></section></main></body></html>';
    exit;
}

function blupalCallbackMeta(array $payment, array $remote = []): array
{
    $amountToman = (int) ($payment['price'] ?? 0);
    $transactionId = trim((string) (
        $remote['transaction_id']
        ?? $remote['transactionId']
        ?? ''
    ));

    $paidAt = trim((string) (
        $remote['paid_at']
        ?? $remote['paidAt']
        ?? $payment['at_updated']
        ?? $payment['time']
        ?? ''
    ));

    return [
        'amount' => $amountToman > 0 ? number_format($amountToman) . ' تومان' : '',
        'invoice_id' => (string) ($payment['dec_not_confirmed'] ?? ''),
        'order_id' => (string) ($payment['id_order'] ?? ''),
        'transaction_id' => $transactionId,
        'paid_at' => $paidAt,
    ];
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
    blupalCallbackFinish(
        'error',
        $failedTitle,
        'شناسه فاکتور بلوپال در بازگشت پرداخت ارسال نشده است.',
        [],
        400
    );
}

$payment = bluebotBlupalFindPaymentByInvoice($invoiceId);
if (!$payment) {
    blupalCallbackFinish(
        'error',
        $failedTitle,
        'تراکنش مربوط به این فاکتور در ربات پیدا نشد.',
        ['invoice_id' => $invoiceId],
        404
    );
}

if ((string) ($payment['payment_Status'] ?? '') === 'paid') {
    blupalCallbackFinish(
        'success',
        $successTitle,
        'پرداخت شما قبلاً تایید شده و سفارش با موفقیت پردازش شده است.',
        blupalCallbackMeta($payment)
    );
}

$result = bluebotBlupalSettle($invoiceId);
$state = strtolower((string) ($result['state'] ?? 'verify_failed'));
$remote = is_array($result['remote'] ?? null) ? $result['remote'] : [];
$meta = blupalCallbackMeta($payment, $remote);

if (!empty($result['ok'])) {
    blupalCallbackFinish(
        'success',
        $successTitle,
        'پرداخت شما با موفقیت تایید شد و سفارش در BlueBot پردازش شد.',
        $meta
    );
}

if ($state === 'delivery_error') {
    blupalCallbackFinish(
        'error',
        'پرداخت تایید شد',
        'پرداخت تایید شده است اما تحویل سفارش با خطا مواجه شد. لطفاً با پشتیبانی در ارتباط باشید.',
        $meta,
        500
    );
}

if ($state === 'amount_mismatch') {
    blupalCallbackFinish(
        'error',
        $failedTitle,
        'مبلغ پرداخت‌شده با مبلغ فاکتور مطابقت ندارد.',
        $meta,
        409
    );
}

if (in_array($state, ['pending', 'canceled', 'expired'], true)) {
    $messages = [
        'pending' => 'پرداخت هنوز در انتظار تایید نهایی بلوپال است. می‌توانید چند لحظه بعد دوباره بررسی کنید.',
        'canceled' => 'این پرداخت لغو شده است و سفارشی پردازش نشده.',
        'expired' => 'مهلت این فاکتور به پایان رسیده است.',
    ];

    $titles = [
        'pending' => 'در انتظار تایید',
        'canceled' => 'پرداخت لغو شد',
        'expired' => 'فاکتور منقضی شد',
    ];

    blupalCallbackFinish(
        $state === 'pending' ? 'pending' : 'error',
        $titles[$state],
        $messages[$state],
        $meta
    );
}

blupalCallbackFinish(
    'error',
    $failedTitle,
    'امکان تایید وضعیت پرداخت در این لحظه وجود ندارد. چند لحظه بعد دوباره بررسی کنید.',
    $meta,
    503
);
