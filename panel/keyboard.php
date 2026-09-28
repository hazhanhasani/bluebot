<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
setcookie('XSRF-TOKEN', csrf_token(), [
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);

$settingRow = select("setting", "*", null, null, "select");
$keyboardLayoutCurrent = json_decode((string) ($settingRow['keyboardmain'] ?? ''), true);
$keyboardRowsCurrent = is_array($keyboardLayoutCurrent['keyboard'] ?? null)
    ? $keyboardLayoutCurrent['keyboard']
    : [];

$digitalServiceButtonEnabled = false;
foreach ($keyboardRowsCurrent as $row) {
    foreach ((array) $row as $button) {
        if (is_array($button) && ($button['text'] ?? '') === 'text_digital_services') {
            $digitalServiceButtonEnabled = true;
            break 2;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_digital_services') {
    csrf_check_post();
    $enable = ($_POST['enable'] ?? '') === '1';
    $rows = $keyboardRowsCurrent;

    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) {
            unset($rows[$rowIndex]);
            continue;
        }
        foreach ($row as $buttonIndex => $button) {
            if (is_array($button) && ($button['text'] ?? '') === 'text_digital_services') {
                unset($rows[$rowIndex][$buttonIndex]);
            }
        }
        $rows[$rowIndex] = array_values($rows[$rowIndex]);
        if ($rows[$rowIndex] === []) {
            unset($rows[$rowIndex]);
        }
    }
    $rows = array_values($rows);

    if ($enable) {
        array_splice($rows, min(1, count($rows)), 0, [[['text' => 'text_digital_services']]]);
    }

    update(
        "setting",
        "keyboardmain",
        json_encode(['keyboard' => $rows], JSON_UNESCAPED_UNICODE),
        null,
        null
    );
    header('Location: keyboard.php');
    exit;
}

$keyboard = json_decode(file_get_contents("php://input"), true);
$method = $_SERVER['REQUEST_METHOD'];
if ($method == "POST" && !csrf_check_value($_SERVER['HTTP_X_XSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
    exit;
}
if ($method == "POST" && is_array($keyboard)) {
    $validKeyboard = count($keyboard) > 0;
    foreach ($keyboard as $row) {
        if (!is_array($row) || count($row) === 0) {
            $validKeyboard = false;
            break;
        }
        foreach ($row as $button) {
            if (!is_array($button) || !isset($button['text']) || !is_string($button['text']) || trim($button['text']) === '') {
                $validKeyboard = false;
                break 2;
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    if (!$validKeyboard) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
        exit;
    }
    $keyboardmain = ['keyboard' => $keyboard];
    update("setting", "keyboardmain", json_encode($keyboardmain), null, null);
    echo json_encode(['ok' => true]);
    exit;
} else {
    $keyboardmain = '{"keyboard":[[{"text":"text_sell"},{"text":"text_extend"}],[{"text":"text_digital_services"}],[{"text":"text_usertest"},{"text":"text_wheel_luck"}],[{"text":"text_Purchased_services"},{"text":"accountwallet"}],[{"text":"text_affiliates"},{"text":"text_Tariff_list"}],[{"text":"text_support"},{"text":"text_help"}],[{"text":"text_agentpanel"},{"text":"text_requestagent"}]]}';
    $action = filter_input(INPUT_GET, 'action');
    if ($action === "reaset") {
        csrf_check_get();
        update("setting", "keyboardmain", $keyboardmain, null, null);
        header('Location: keyboard.php');
        exit;
    }
}
?>

<!doctype html>
<html lang="FA">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $textbotlang['panel']['keyboardManageTitle'] ?></title>

    <script type="module" crossorigin src="js/sort_keyboard.js"></script>
    <link rel="stylesheet" crossorigin href="css/sort_keyboard.css">
    <style>
        @import url(https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap);

        * {
            font-family: 'Vazirmatn' !important;
        }

        button {
            font-family: yekan;
        }

        .btnback {
            position: fixed;
            top: 10px;
            left: 10px;
            padding: 7px;
            background-color: #3d3d3d;
            color: #fff;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
        }

        .btndefult {
            position: fixed;
            top: 10px;
            left: 150px;
            padding: 7px;
            background-color: #fff;
            border: 2px solid #3d3d3d;
            color: #3d3d3d;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <a class="btnback" href="index.php"><?= $textbotlang['panel']['keyboardSortHint'] ?></a>
    <a class="btndefult" href="keyboard.php?action=reaset&_csrf=<?= urlencode(csrf_token()) ?>"><?= $textbotlang['panel']['keyboardSaveBtn'] ?></a>
    <div style="max-width:980px;margin:72px auto 14px;padding:0 14px">
        <form method="post" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 16px;border:1px solid #d7d7d7;border-radius:12px;background:#fff;color:#222;box-shadow:0 6px 24px rgba(0,0,0,.08)">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="toggle_digital_services">
            <input type="hidden" name="enable" value="<?= $digitalServiceButtonEnabled ? '0' : '1' ?>">
            <div>
                <strong>🛍 فروش خدمات</strong>
                <div style="font-size:12px;opacity:.72;margin-top:3px">نمایش کلید فروش Stars، Telegram Premium و سایر خدمات در کیبورد اصلی ربات</div>
            </div>
            <button type="submit" style="border:0;border-radius:9px;padding:9px 14px;cursor:pointer;background:<?= $digitalServiceButtonEnabled ? '#fee2e2' : '#dcfce7' ?>;color:#111;font-weight:700">
                <?= $digitalServiceButtonEnabled ? 'غیرفعال کردن' : 'فعال کردن' ?>
            </button>
        </form>
    </div>
    <div id="root"></div>
</body>

</html>