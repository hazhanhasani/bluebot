<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/function.php';
require_once dirname(__DIR__) . '/src/Support/UpdateManager.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$channel = $argv[1] ?? '';
$ref = $argv[2] ?? '';

if (!in_array($channel, ['release', 'beta', 'auto'], true) || trim($ref) === '') {
    fwrite(STDERR, "usage: php scripts/update-state.php <release|beta|auto> <ref>\n");
    exit(2);
}

bluebotUpdateMarkInstalled($channel, trim($ref));
echo "Update state saved.\n";
