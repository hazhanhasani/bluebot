<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/AppClientAuth.php';

function bluebotAppClientEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bluebotAppClientServiceToken(string $invoiceId): string
{
    return substr(hash('sha256', $invoiceId), 0, 20);
}

/**
 * @param array<int,array<string,mixed>> $services
 */
function bluebotAppClientResolveService(array $services, string $token): ?array
{
    $matches = [];
    foreach ($services as $service) {
        $invoiceId = trim((string) ($service['id_invoice'] ?? ''));
        if ($invoiceId !== '' && hash_equals(bluebotAppClientServiceToken($invoiceId), $token)) {
            $matches[] = $service;
        }
    }

    return count($matches) === 1 ? $matches[0] : null;
}

function bluebotAppClientServiceTitle(array $service): string
{
    $product = trim((string) ($service['name_product'] ?? ''));
    $username = trim((string) ($service['username'] ?? ''));
    if ($product !== '' && $username !== '') {
        return $product . ' • ' . $username;
    }
    return $product !== '' ? $product : ($username !== '' ? $username : 'اشتراک');
}

/**
 * @param array<int,array<string,mixed>> $services
 */
function bluebotAppClientListMarkup(array $services): string
{
    $keyboard = [];

    foreach ($services as $service) {
        $invoiceId = trim((string) ($service['id_invoice'] ?? ''));
        if ($invoiceId === '') {
            continue;
        }

        $label = bluebotAppClientServiceTitle($service);
        if (mb_strlen($label, 'UTF-8') > 38) {
            $label = mb_substr($label, 0, 35, 'UTF-8') . '…';
        }

        $keyboard[] = [[
            'text' => '📱 ' . $label,
            'callback_data' => 'blueapp_pick:' . bluebotAppClientServiceToken($invoiceId),
        ]];
    }

    return json_encode(
        ['inline_keyboard' => $keyboard],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '{"inline_keyboard":[]}';
}

function bluebotAppClientServiceMarkup(string $invoiceId): string
{
    $token = bluebotAppClientServiceToken($invoiceId);

    return json_encode([
        'inline_keyboard' => [
            [[
                'text' => '🔐 ساخت رمز جدید برای همین سرویس',
                'callback_data' => 'blueapp_rotate:' . $token,
            ]],
            [[
                'text' => '↩️ انتخاب اشتراک دیگر',
                'callback_data' => 'blueapp_list',
            ]],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"inline_keyboard":[]}';
}

function bluebotAppClientListText(int $count): string
{
    return "📱 <b>ورود به اپلیکیشن Blue VPN</b>\n\n"
        . "هر اشتراک، <b>نام کاربری و رمز مستقل</b> دارد. "
        . "با اطلاعات یک سرویس، فقط همان سرویس در اپ نمایش داده می‌شود.\n\n"
        . "یکی از {$count} اشتراک زیر را انتخاب کنید:";
}

function bluebotAppClientCredentialsText(array $credentials, array $service): string
{
    $username = bluebotAppClientEscape((string) ($credentials['username'] ?? ''));
    $password = bluebotAppClientEscape((string) ($credentials['password'] ?? ''));
    $serviceTitle = bluebotAppClientEscape(bluebotAppClientServiceTitle($service));

    return "📱 <b>حساب اختصاصی این سرویس آماده شد</b>\n\n"
        . "🛍 سرویس: <b>{$serviceTitle}</b>\n\n"
        . "👤 نام کاربری:\n<code>{$username}</code>\n\n"
        . "🔑 رمز عبور:\n<code>{$password}</code>\n\n"
        . "این حساب فقط به همین اشتراک دسترسی دارد و هیچ سرویس دیگری را نمایش نمی‌دهد.\n\n"
        . "📌 این نام کاربری و رمز بعداً هم از بخش «سرویس‌های من» قابل دریافت است.";
}

function bluebotAppClientExistingText(array $credentials, array $service): string
{
    return bluebotAppClientCredentialsText($credentials, $service);
}

function bluebotAppClientCredentialsBlock(PDO $pdo, string $userId, string $invoiceId): string
{
    try {
        $credentials = AppClientAuth::credentialsForService($pdo, $userId, $invoiceId);
        $username = bluebotAppClientEscape((string) ($credentials['username'] ?? ''));
        $password = bluebotAppClientEscape((string) ($credentials['password'] ?? ''));

        return "\n\n📱 <b>ورود به Blue VPN</b>"
            . "\n👤 نام کاربری: <code>{$username}</code>"
            . "\n🔑 رمز عبور: <code>{$password}</code>"
            . "\n<i>برای کپی، روی مقدار داخل کادر بزنید.</i>";
    } catch (Throwable $e) {
        if (function_exists('bluebotLog')) {
            bluebotLog('warning', 'Unable to expose app credentials in service details', [
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);
        }
        return '';
    }
}


function bluebotAppClientCredentialCopyRow(
    PDO $pdo,
    string $userId,
    string $invoiceId
): array {
    try {
        $credentials = AppClientAuth::credentialsForService($pdo, $userId, $invoiceId);
        $username = trim((string) ($credentials['username'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($username === '' || $password === '') {
            return [];
        }

        return [
            [
                'text' => '📋 کپی نام کاربری',
                'copy_text' => ['text' => $username],
            ],
            [
                'text' => '🔑 کپی رمز عبور',
                'copy_text' => ['text' => $password],
            ],
        ];
    } catch (Throwable $e) {
        if (function_exists('bluebotLog')) {
            bluebotLog('warning', 'Unable to build app credential copy buttons', [
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);
        }
        return [];
    }
}

function bluebotAppClientAppendCredentialButtons(
    PDO $pdo,
    string $userId,
    string $invoiceId,
    array|string|null $markup
): string {
    $decoded = is_array($markup)
        ? $markup
        : json_decode((string) ($markup ?? ''), true);

    if (!is_array($decoded)) {
        $decoded = ['inline_keyboard' => []];
    }
    if (!isset($decoded['inline_keyboard']) || !is_array($decoded['inline_keyboard'])) {
        $decoded['inline_keyboard'] = [];
    }

    $row = bluebotAppClientCredentialCopyRow($pdo, $userId, $invoiceId);
    if ($row !== []) {
        $decoded['inline_keyboard'][] = $row;
    }

    return json_encode(
        $decoded,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '{"inline_keyboard":[]}';
}

/**
 * @param array<int,string>|string|null $configs
 * @return array<int,string>
 */
function bluebotAppClientQrPayloadList(string $subscriptionUrl, array|string|null $configs): array
{
    $payloads = [];
    $subscriptionUrl = trim($subscriptionUrl);
    if ($subscriptionUrl !== '') {
        $payloads[] = $subscriptionUrl;
    }

    $values = is_array($configs) ? $configs : [$configs];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $payloads[] = $value;
        }
    }

    return array_values(array_unique($payloads));
}

/**
 * @param array<int,string>|string $payloads
 */
function bluebotAppClientSyncQr(
    PDO $pdo,
    string $userId,
    string $invoiceId,
    array|string $payloads
): void {
    try {
        AppClientAuth::replaceQrPayloads($pdo, $userId, $invoiceId, $payloads);
    } catch (Throwable $e) {
        if (function_exists('bluebotLog')) {
            bluebotLog('warning', 'Unable to sync service QR fingerprint', [
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}

function bluebotAppClientAnswerCallback(string $text, bool $alert = false): void
{
    global $callback_query_id;

    if (empty($callback_query_id)) {
        return;
    }

    telegram('answerCallbackQuery', [
        'callback_query_id' => $callback_query_id,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function bluebotAppClientShowList(string $userId, array $services, bool $edit = false): void
{
    global $message_id;

    $body = bluebotAppClientListText(count($services));
    $markup = bluebotAppClientListMarkup($services);

    if ($edit && !empty($message_id)) {
        Editmessagetext($userId, (int) $message_id, $body, $markup, 'HTML');
        return;
    }

    sendmessage($userId, $body, $markup, 'HTML');
}

/** Handles /app and service-scoped app credential callbacks. */
function bluebotHandleAppClientEntry(): bool
{
    global $pdo, $text, $datain, $from_id, $message_id;

    $command = trim((string) ($text ?? ''));
    $callback = trim((string) ($datain ?? ''));

    $isCommand = preg_match('/^\/app(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $command) === 1;
    $isList = $callback === 'blueapp_list' || $callback === 'blueapp_rotate';
    $isPick = preg_match('/^blueapp_pick:([a-f0-9]{20})$/', $callback, $pickMatch) === 1;
    $isRotate = preg_match('/^blueapp_rotate:([a-f0-9]{20})$/', $callback, $rotateMatch) === 1;

    if (!$isCommand && !$isList && !$isPick && !$isRotate) {
        return false;
    }

    $userId = trim((string) ($from_id ?? ''));
    if ($userId === '') {
        return false;
    }

    $user = select('user', '*', 'id', $userId, 'select');
    if (!is_array($user) || (string) ($user['User_Status'] ?? '') === 'block') {
        if (!$isCommand) {
            bluebotAppClientAnswerCallback('این حساب در دسترس نیست.', true);
        }
        return true;
    }

    try {
        $services = AppClientAuth::servicesForUser($pdo, $userId);

        if (empty($services)) {
            $body = "📱 <b>Blue VPN</b>\n\n"
                . "در حال حاضر اشتراک فعالی برای ساخت حساب اپلیکیشن پیدا نشد.";
            if (!$isCommand && !empty($message_id)) {
                Editmessagetext($userId, (int) $message_id, $body, null, 'HTML');
                bluebotAppClientAnswerCallback('اشتراک فعالی پیدا نشد.', true);
            } else {
                sendmessage($userId, $body, null, 'HTML');
            }
            return true;
        }

        if ($isCommand || $isList) {
            bluebotAppClientShowList($userId, $services, !$isCommand);
            if (!$isCommand) {
                bluebotAppClientAnswerCallback(
                    $callback === 'blueapp_rotate'
                        ? 'ساختار ورود تغییر کرده؛ حالا برای هر سرویس حساب جدا ساخته می‌شود.'
                        : 'یک اشتراک را انتخاب کنید.'
                );
            }
            return true;
        }

        $token = $isPick
            ? (string) ($pickMatch[1] ?? '')
            : (string) ($rotateMatch[1] ?? '');
        $service = bluebotAppClientResolveService($services, $token);

        if (!is_array($service)) {
            bluebotAppClientAnswerCallback('این اشتراک دیگر در دسترس نیست.', true);
            bluebotAppClientShowList($userId, $services, true);
            return true;
        }

        $invoiceId = (string) $service['id_invoice'];

        if ($isRotate) {
            $credentials = AppClientAuth::createCredentials($pdo, $userId, $invoiceId);
            $body = bluebotAppClientCredentialsText($credentials, $service);
            $markup = bluebotAppClientServiceMarkup($invoiceId);

            if (!empty($message_id)) {
                Editmessagetext($userId, (int) $message_id, $body, $markup, 'HTML');
            } else {
                sendmessage($userId, $body, $markup, 'HTML');
            }

            bluebotAppClientAnswerCallback('رمز جدید همین سرویس ساخته شد.');
            return true;
        }

        $credentials = AppClientAuth::credentialsForService($pdo, $userId, $invoiceId);
        $body = bluebotAppClientExistingText($credentials, $service);

        $markup = bluebotAppClientServiceMarkup($invoiceId);
        if (!empty($message_id)) {
            Editmessagetext($userId, (int) $message_id, $body, $markup, 'HTML');
        } else {
            sendmessage($userId, $body, $markup, 'HTML');
        }
        bluebotAppClientAnswerCallback('حساب همین سرویس نمایش داده شد.');
        return true;
    } catch (Throwable $e) {
        if (function_exists('bluebotLog')) {
            bluebotLog('error', 'App client credential operation failed', [
                'user_id' => $userId,
                'reason' => $e->getMessage(),
            ]);
        }

        if (!$isCommand) {
            bluebotAppClientAnswerCallback('عملیات حساب اپلیکیشن انجام نشد.', true);
        } else {
            sendmessage(
                $userId,
                '❌ ساخت حساب اپلیکیشن در حال حاضر انجام نشد. لطفاً دوباره تلاش کنید.',
                null,
                'HTML'
            );
        }
        return true;
    }
}
