<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/AppClientAuth.php';

function bluebotAppClientMarkup(): string
{
    return json_encode([
        'inline_keyboard' => [
            [[
                'text' => '🔐 ساخت رمز جدید',
                'callback_data' => 'blueapp_rotate',
            ]],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"inline_keyboard":[]}';
}

function bluebotAppClientEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bluebotAppClientCredentialsText(array $credentials): string
{
    $username = bluebotAppClientEscape((string) ($credentials['username'] ?? ''));
    $password = bluebotAppClientEscape((string) ($credentials['password'] ?? ''));

    return "📱 <b>حساب اپلیکیشن Blue Panel آماده شد</b>\n\n"
        . "👤 نام کاربری:\n<code>{$username}</code>\n\n"
        . "🔑 رمز عبور:\n<code>{$password}</code>\n\n"
        . "این رمز فقط همین یک‌بار نمایش داده می‌شود. آن را در اپ وارد کنید و نیازی به کپی کردن لینک اشتراک ندارید.\n\n"
        . "⚠️ با ساخت رمز جدید، نشست‌های قبلی اپ فوراً باطل می‌شوند.";
}

function bluebotAppClientExistingText(array $account): string
{
    $username = bluebotAppClientEscape((string) ($account['username'] ?? ''));

    return "📱 <b>حساب اپلیکیشن Blue Panel</b>\n\n"
        . "👤 نام کاربری:\n<code>{$username}</code>\n\n"
        . "🔐 به دلایل امنیتی رمز قبلی قابل مشاهده نیست. اگر آن را فراموش کرده‌اید، «ساخت رمز جدید» را بزنید.\n\n"
        . "بعد از ورود، اشتراک‌های فعال شما خودکار داخل اپ نمایش داده می‌شوند.";
}

/** Handles /app and the password-rotation callback. */
function bluebotHandleAppClientEntry(): bool
{
    global $pdo, $text, $datain, $from_id, $message_id, $callback_query_id;

    $command = trim((string) ($text ?? ''));
    $callback = (string) ($datain ?? '');
    $isCommand = preg_match('/^\/app(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $command) === 1;
    $isRotate = hash_equals('blueapp_rotate', $callback);

    if (!$isCommand && !$isRotate) {
        return false;
    }

    $userId = trim((string) ($from_id ?? ''));
    if ($userId === '') {
        return false;
    }

    $user = select('user', '*', 'id', $userId, 'select');
    if (!is_array($user) || (string) ($user['User_Status'] ?? '') === 'block') {
        if ($isRotate && !empty($callback_query_id)) {
            telegram('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => 'این حساب در دسترس نیست.',
                'show_alert' => true,
            ]);
        }
        return true;
    }

    try {
        if ($isRotate) {
            $credentials = AppClientAuth::createCredentials($pdo, $userId);
            $body = bluebotAppClientCredentialsText($credentials);
            $markup = bluebotAppClientMarkup();

            if (!empty($message_id)) {
                Editmessagetext($userId, (int) $message_id, $body, $markup, 'HTML');
            } else {
                sendmessage($userId, $body, $markup, 'HTML');
            }
            if (!empty($callback_query_id)) {
                telegram('answerCallbackQuery', [
                    'callback_query_id' => $callback_query_id,
                    'text' => 'رمز جدید ساخته شد و نشست‌های قبلی باطل شدند.',
                    'show_alert' => false,
                ]);
            }
            return true;
        }

        $account = AppClientAuth::accountForUser($pdo, $userId);
        if (!is_array($account)) {
            $credentials = AppClientAuth::createCredentials($pdo, $userId);
            sendmessage($userId, bluebotAppClientCredentialsText($credentials), bluebotAppClientMarkup(), 'HTML');
        } else {
            sendmessage($userId, bluebotAppClientExistingText($account), bluebotAppClientMarkup(), 'HTML');
        }
        return true;
    } catch (Throwable $e) {
        if (function_exists('bluebotLog')) {
            bluebotLog('error', 'App client credential operation failed', [
                'user_id' => $userId,
                'reason' => $e->getMessage(),
            ]);
        }
        sendmessage($userId, '❌ ساخت حساب اپلیکیشن در حال حاضر انجام نشد. لطفاً دوباره تلاش کنید.', null, 'HTML');
        return true;
    }
}
