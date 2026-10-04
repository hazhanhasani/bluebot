<?php

declare(strict_types=1);

require_once __DIR__ . '/support/source_function.php';
loadTestSourceFunction(dirname(__DIR__) . '/api/miniapp.php', 'mini_panel_accessible');
require_once dirname(__DIR__) . '/src/Services/AppStoreAccount.php';

final class StorefrontProductLookup extends RuntimeException
{
}

final class VisibilityStatement extends PDOStatement
{
    public function __construct(private array $rows)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }
}

final class VisibilityPdo extends PDO
{
    public function __construct(private array $user, private array $panel, private bool $checkout = false)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM user')) {
            return new VisibilityStatement([$this->user]);
        }
        if (str_contains($query, 'FROM marzban_panel')) {
            return new VisibilityStatement([$this->panel]);
        }
        if (str_contains($query, 'FROM product')) {
            // Stop checkout once its panel authorization has succeeded. No
            // invoice, wallet operation, or external panel call is needed.
            if ($this->checkout) {
                throw new StorefrontProductLookup();
            }
            return new VisibilityStatement([[
                'id' => 1,
                'code_product' => 'product-1',
                'name_product' => 'Test product',
                'hide_panel' => '[]',
                'one_buy_status' => '0',
                'price_product' => 100,
            ]]);
        }

        throw new RuntimeException('Unexpected database query: ' . $query);
    }
}

$failures = [];
$user = ['id' => '123456789', 'agent' => 'f', 'pricediscount' => 0];
$basePanel = [
    'status' => 'active',
    'agent' => 'all',
    'type' => 'marzban',
    'code_panel' => 'panel-1',
    'name_panel' => 'Test panel',
];
$cases = [
    ['hidden string ID', ['hide_user' => '["123456789"]'], false],
    ['hidden numeric ID', ['hide_user' => '[123456789]'], false],
    ['different user hidden', ['hide_user' => '["987654321"]'], true],
    ['empty hidden list', ['hide_user' => '[]'], true],
    ['null hidden list', ['hide_user' => null], true],
    ['missing hidden list', [], true],
    ['legacy empty hidden list', ['hide_user' => ''], true],
    ['malformed hidden list', ['hide_user' => '{invalid'], true],
    ['scalar hidden list', ['hide_user' => '123456789'], true],
];

foreach ($cases as [$name, $overrides, $expected]) {
    $panel = array_replace($basePanel, $overrides);
    if (mini_panel_accessible($panel, $user) !== $expected) {
        $failures[] = $name . ': Mini App must apply the configured panel visibility to sales.';
    }

    $catalog = AppStoreAccount::catalog(new VisibilityPdo($user, $panel), $user['id']);
    if ((count($catalog['products']) > 0) !== $expected) {
        $failures[] = $name . ': mobile catalog visibility differs from Mini App visibility.';
    }

    $checkoutAllowed = false;
    try {
        AppStoreAccount::createCheckout(new VisibilityPdo($user, $panel, true), $user['id'], 'product-1', 'panel-1', 'wallet');
        $failures[] = $name . ': test checkout unexpectedly continued past product lookup.';
    } catch (StorefrontProductLookup $e) {
        $checkoutAllowed = true;
    } catch (InvalidArgumentException $e) {
        // Hidden panels must be rejected before a product or payment is used.
    }
    if ($checkoutAllowed !== $expected) {
        $failures[] = $name . ': mobile checkout must enforce the same visibility as its catalog.';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Storefront visibility tests failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Storefront panel visibility tests OK.\n";
