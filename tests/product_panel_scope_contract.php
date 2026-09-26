<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$function = (string) @file_get_contents($root . '/function.php');
$panel = (string) @file_get_contents($root . '/panel/product.php');
$api = (string) @file_get_contents($root . '/api/product.php');
$indexes = (string) @file_get_contents($root . '/db/indexes.php');

$checks = [
    [$function, 'function productNameLocationConflict', 'Shared product name/location conflict helper is missing.'],
    [$function, "Location = :exact_location", 'Product conflict rule must scope names to the selected panel.'],
    [$function, "OR Location = '/all'", 'Global /all products must still conflict with panel-specific duplicates.'],
    [$function, "ORDER BY CASE WHEN Location = :exact_location THEN 0 ELSE 1 END", 'Product lookup must prefer the exact panel over /all.'],
    [$panel, 'productNameLocationConflict($name, $location)', 'Web panel add flow must use panel-scoped duplicate detection.'],
    [$panel, 'productNameLocationConflict($name, $location, $pid)', 'Web panel edit flow must use panel-scoped duplicate detection.'],
    [$api, 'productNameLocationConflict((string) $data[\'name\'], (string) $locationName)', 'Product API add flow must use panel-scoped duplicate detection.'],
    [$api, 'productNameLocationConflict($newName, (string) $location, (int) $product[\'id\'])', 'Product API edit flow must use panel-scoped duplicate detection.'],
    [$api, 'Service_location = :location', 'Product statistics must be scoped to the product panel.'],
    [$indexes, 'idx_product_name_location', 'Product name/location index is missing.'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if (str_contains($panel, 'SELECT COUNT(*) FROM product WHERE name_product = ?')) {
    $failures[] = 'Web panel still enforces global product-name uniqueness.';
}

if (str_contains($api, 'select("product", "*", "name_product", $data[\'name\'], "count")')) {
    $failures[] = 'Product API still enforces global product-name uniqueness.';
}

if ($failures !== []) {
    fwrite(STDERR, "Product panel scope contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Product panel scope contract OK.\n";
