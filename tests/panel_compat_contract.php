<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$contracts = [
    'Marzban.php' => [
        "rawurlencode((string) $username)",
        "Marzban delete returned an error after successful deletion",
    ],
    'marzneshin.php' => [
        "'expire_strategy' => 'never'",
        "'expire_strategy'] = 'start_on_first_use'",
        "date(DATE_ATOM",
    ],
    'solidlayer.php' => [
        "'X-API-Key: '",
        "'/api/subscriptions'",
    ],
    'x-ui_single.php' => [
        '?keepTraffic=0',
        "'inboundIds' =>",
        'array_replace($current, $config)',
    ],
    'alireza_single.php' => [
        'function alirezaClientIdentifier',
        "['id', 'password', 'email']",
    ],
    'hiddify.php' => [
        "'Hiddify-API-Key: '",
        "'/api/v2/admin/user/'",
    ],
    's_ui.php' => [
        "'/apiv2/save'",
        "json_encode(array('id' => (int) $data_user['id']))",
    ],
    'WGDashboard.php' => [
        'function wgDashboardRequest',
        "'wg-dashboard-apikey: '",
    ],
    'mikrotik.php' => [
        "mikrotikRequest($panel, 'PUT', 'user-manager/user'",
        "'DELETE'",
    ],
    'ibsng.php' => [
        'Modules\\IBSng',
    ],
    'mirza_agent.php' => [
        'create_user_mirza',
    ],
    'Rebecca.php' => [
        'adduser_rebecca',
    ],
];

$failures = [];

foreach ($contracts as $file => $needles) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        $failures[] = "Missing adapter: {$file}";
        continue;
    }

    $source = file_get_contents($path);
    if ($source === false) {
        $failures[] = "Unable to read adapter: {$file}";
        continue;
    }

    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            $failures[] = "{$file}: missing compatibility contract {$needle}";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Panel compatibility contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Panel compatibility contracts OK.\n";
