<?php

/**
 * BlueBot installer guard.
 *
 * Keeps installer cleanup and compatibility aliases outside the legacy
 * function.php monolith while preserving the public function names used by
 * existing installations.
 */

function bluebotRemoveInstallerPath($path)
{
    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }

    if (!is_dir($path)) {
        return true;
    }

    $entries = @scandir($path);
    if ($entries === false) {
        return false;
    }

    $removed = true;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $removed = bluebotRemoveInstallerPath(
            $path . DIRECTORY_SEPARATOR . $entry
        ) && $removed;
    }

    return @rmdir($path) && $removed;
}

function bluebotInstallerNoticeTexts()
{
    global $textbotlang;

    $lang = is_array($textbotlang) && $textbotlang !== []
        ? $textbotlang
        : null;

    if ($lang === null) {
        $root = dirname(__DIR__, 2);
        $lang = @include $root . '/lang/fa.php';
    }

    $notice = is_array($lang)
        ? ($lang['Admin']['installerNotice'] ?? null)
        : null;

    return [
        'user' => $notice['user']
            ?? 'The bot is temporarily unavailable. Please try again later.',
        'admin' => $notice['admin']
            ?? 'The install folder still exists on the server and the bot could not remove it. Delete it manually to bring the bot back.',
    ];
}

function bluebotShouldAlertInstallerAdmin($cooldown = 3600)
{
    $root = dirname(__DIR__, 2);
    $cacheDir = $root . '/storage/cache';

    if (
        !is_dir($cacheDir)
        && !@mkdir($cacheDir, 0775, true)
        && !is_dir($cacheDir)
    ) {
        return true;
    }

    $marker = $cacheDir . '/installer_notice';
    $last = @file_get_contents($marker);
    if ($last !== false && (time() - intval($last)) < $cooldown) {
        return false;
    }

    @file_put_contents($marker, (string) time(), LOCK_EX);
    @chmod($marker, 0640);
    return true;
}

function bluebotNotifyInstallerBlocked()
{
    global $from_id, $adminnumber;

    if (!function_exists('sendmessage')) {
        return;
    }

    $texts = bluebotInstallerNoticeTexts();
    $adminId = isset($adminnumber) ? trim((string) $adminnumber) : '';
    $userId = isset($from_id) ? trim((string) $from_id) : '';
    $userIsAdmin = $adminId !== '' && $userId === $adminId;

    if (
        $userId !== ''
        && function_exists('isTelegramChatIdEmpty')
        && !isTelegramChatIdEmpty($userId)
    ) {
        sendmessage(
            $userId,
            $userIsAdmin ? $texts['admin'] : $texts['user'],
            null,
            'HTML'
        );
    }

    if (
        !$userIsAdmin
        && $adminId !== ''
        && bluebotShouldAlertInstallerAdmin()
    ) {
        sendmessage($adminId, $texts['admin'], null, 'HTML');
    }
}

function bluebotStopForInstaller($message)
{
    if (function_exists('bluebotLog')) {
        bluebotLog('warning', 'Installer guard blocked application startup', [
            'reason' => (string) $message,
        ]);
    } else {
        error_log((string) $message);
    }

    bluebotNotifyInstallerBlocked();

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo $message;
    exit;
}

function bluebotEnsureInstallerRemoved()
{
    $root = dirname(__DIR__, 2);
    $installerDirectory = $root . '/install';

    if (!is_dir($installerDirectory)) {
        return;
    }

    if (!bluebotRemoveInstallerPath($installerDirectory)) {
        bluebotStopForInstaller(
            'BlueBot install folder still exists and could not be removed automatically; delete it manually to enable the bot.'
        );
    }
}

/**
 * Legacy aliases retained for compatibility with existing integrations.
 */
function mirzaRemoveInstallerPath($path)
{
    return bluebotRemoveInstallerPath($path);
}

function mirzaInstallerNoticeTexts()
{
    return bluebotInstallerNoticeTexts();
}

function mirzaShouldAlertInstallerAdmin($cooldown = 3600)
{
    return bluebotShouldAlertInstallerAdmin($cooldown);
}

function mirzaNotifyInstallerBlocked()
{
    return bluebotNotifyInstallerBlocked();
}

function mirzaStopForInstaller($message)
{
    return bluebotStopForInstaller($message);
}

function mirzaEnsureInstallerRemoved()
{
    return bluebotEnsureInstallerRemoved();
}
