<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$contracts = [
    'src/Panel/Adapters/Marzban.php' => [
        'rawurlencode((string) $username)',
        'Marzban delete returned an error after successful deletion',
        '$data[\'group_ids\'] = $inbounds',
        '"proxy_settings" => json_decode',
    ],
    'src/Panel/Adapters/Marzneshin.php' => [
        '$data[\'expire_strategy\'] = \'never\'',
        '$data[\'expire_strategy\'] = \'start_on_first_use\'',
        'date(DATE_ATOM',
        "'/api/admins/token'",
    ],
    'src/Panel/Adapters/SolidLayer.php' => [
        "'X-API-Key: '",
        "'/api/subscriptions'",
        "'/api/services'",
        "bool \$includeLinks = true",
        "\$includeLinks ? solidlayerGetSubscriptionLinks",
        "\$subscription['last_online_at'] ?? ''",
    ],
    'src/Panel/Adapters/ThreeXUI.php' => [
        '?keepTraffic=0',
        '\'inboundIds\' => $ids',
        'array_replace($current, $config)',
        "'/panel/api/clients/update/'",
    ],
    'src/Panel/Adapters/AlirezaXUI.php' => [
        'function alirezaClientIdentifier',
        "['id', 'password', 'email']",
        "'/xui/API/inbounds/updateClient/'",
    ],
    'src/Panel/Adapters/Hiddify.php' => [
        "'Hiddify-API-Key: '",
        "'/api/v2/admin/user/'",
        "'Authorization: Basic '",
        'function hiddifyIsSuccessfulResponse',
        'function hiddifyResponseError',
        'function resetuserusagehi',
    ],
    'panels.php' => [
        "resetuserusagehi(\$username, \$panel['name_panel'])",
        'max(0, (int) ceil(\$day / 86400))',
        "'Unable to delete Hiddify user'",
        "'Unable to update Hiddify user'",
    ],
    'admin.php' => [
        'hiddifyDecodeResponse(\$System_Stats_Response)',
        "\$System_Stats['stats']['system'] ?? null",
    ],
    'src/Panel/Adapters/SUI.php' => [
        "'/apiv2/save'",
        "json_encode(array('id' => (int) \$data_user['id']))",
        "empty(\$response['success'])",
    ],
    'src/Panel/Adapters/WGDashboard.php' => [
        'function wgDashboardRequest',
        "'wg-dashboard-apikey: '",
        "rawurlencode((string) \$panel['inboundid'])",
    ],
    'src/Panel/Adapters/MikroTik.php' => [
        'function mikrotikRequest',
        "'PUT', 'user-manager/user'",
        "'DELETE'",
        "'user-manager/user/print'",
    ],
    'src/Panel/Adapters/IBSng.php' => [
        'Modules\\IBSng',
    ],
    'src/Panel/Adapters/MirzaAgent.php' => [
        'create_user_mirza',
        'get_user_data_mirza',
    ],
    'src/Panel/Adapters/Rebecca.php' => [
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
        'Hiddify Manager v13.0.3 stable',
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
