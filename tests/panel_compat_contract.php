<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$contracts = [
    'Marzban.php' => [
        'rawurlencode((string) $username)',
        'Marzban delete returned an error after successful deletion',
        '$data[\'group_ids\'] = $inbounds',
        '"proxy_settings" => json_decode',
    ],
    'marzneshin.php' => [
        '$data[\'expire_strategy\'] = \'never\'',
        '$data[\'expire_strategy\'] = \'start_on_first_use\'',
        'date(DATE_ATOM',
        "'/api/admins/token'",
    ],
    'solidlayer.php' => [
        "'X-API-Key: '",
        "'/api/subscriptions'",
        "'/api/services'",
    ],
    'x-ui_single.php' => [
        '?keepTraffic=0',
        '\'inboundIds\' => $ids',
        'array_replace($current, $config)',
        "'/panel/api/clients/update/'",
    ],
    'alireza_single.php' => [
        'function alirezaClientIdentifier',
        "['id', 'password', 'email']",
        "'/xui/API/inbounds/updateClient/'",
    ],
    'hiddify.php' => [
        "'Hiddify-API-Key: '",
        "'/api/v2/admin/user/'",
        "'Authorization: Basic '",
    ],
    's_ui.php' => [
        "'/apiv2/save'",
        "json_encode(array('id' => (int) \$data_user['id']))",
        "empty(\$response['success'])",
    ],
    'WGDashboard.php' => [
        'function wgDashboardRequest',
        "'wg-dashboard-apikey: '",
        "rawurlencode((string) \$panel['inboundid'])",
    ],
    'mikrotik.php' => [
        'function mikrotikRequest',
        "'PUT', 'user-manager/user'",
        "'DELETE'",
        "'user-manager/user/print'",
    ],
    'ibsng.php' => [
        'Modules\\IBSng',
    ],
    'mirza_agent.php' => [
        'create_user_mirza',
        'get_user_data_mirza',
    ],
    'Rebecca.php' => [
        'adduser_rebecca',
        'Modifyuser_rebecca',
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

$matrix = $root . '/docs/PANEL_COMPATIBILITY.md';
if (!is_file($matrix)) {
    $failures[] = 'Missing panel compatibility matrix.';
} else {
    $matrixSource = file_get_contents($matrix);
    foreach ([
        'Marzban v0.8.4',
        'PasarGuard v5.4.1',
        'Marzneshin v0.7.4',
        '3x-ui v3.8.5',
        'alireza0/x-ui v1.12.0',
        'Hiddify Manager v12.3.3 stable',
        'S-UI v1.6.3',
        'WGDashboard v4.3.3',
        'RouterOS v7 REST API',
    ] as $baseline) {
        if ($matrixSource === false || !str_contains($matrixSource, $baseline)) {
            $failures[] = "Compatibility matrix missing baseline: {$baseline}";
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
