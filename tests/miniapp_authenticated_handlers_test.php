<?php

declare(strict_types=1);

require_once __DIR__ . '/support/source_function.php';
$source = dirname(__DIR__) . '/api/miniapp.php';
foreach (['mini_panel_accessible', 'mini_user_info', 'mini_countries', 'mini_categories', 'mini_time_ranges', 'mini_services', 'mini_custom_price', 'mini_purchase'] as $function) {
    loadTestSourceFunction($source, $function);
}

final class MiniAppWalletCharge extends RuntimeException
{
    public function __construct(public array $parameters)
    {
        parent::__construct('Checkout reached the wallet charge');
    }
}

final class MiniAppHandlerStatement extends PDOStatement
{
    private int $cursor = 0;

    public function __construct(private array $rows, private bool $charge = false)
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return true;
    }

    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->charge) {
            throw new MiniAppWalletCharge($params ?? []);
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return 2;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}

final class MiniAppHandlerPdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        global $panel;
        if (str_starts_with($query, 'UPDATE user SET Balance = Balance -')) {
            return new MiniAppHandlerStatement([], true);
        }
        if (str_contains($query, 'SELECT COUNT(*)')) {
            return new MiniAppHandlerStatement([]);
        }
        if (str_contains($query, 'FROM marzban_panel')) {
            return new MiniAppHandlerStatement([$panel, array_replace($panel, ['hide_user' => '[42]'])]);
        }
        if (str_contains($query, 'FROM category')) {
            return new MiniAppHandlerStatement([['id' => 'category-1', 'remark' => 'VPN']]);
        }
        if (str_contains($query, 'SELECT (Service_time)')) {
            return new MiniAppHandlerStatement(['30']);
        }
        if (str_contains($query, 'FROM product')) {
            return new MiniAppHandlerStatement([[
                'code_product' => 'product-1', 'name_product' => 'VPN plan',
                'note' => '', 'hide_panel' => '[]', 'one_buy_status' => '0',
                'price_product' => 100, 'Volume_constraint' => 10, 'Service_time' => 30,
            ]]);
        }
        throw new RuntimeException('Unexpected database query: ' . $query);
    }
}

function select($table, $field, $whereField = null, $whereValue = null, $type = 'select', $options = [])
{
    global $panel, $setting;
    if ($table === 'user') {
        throw new RuntimeException('Authenticated handlers must not re-resolve the original bearer token');
    }
    return match ($table) {
        'setting' => $setting,
        'marzban_panel' => $panel,
        'category' => ['id' => 'category-1', 'remark' => 'VPN'],
        default => throw new RuntimeException('Unexpected lookup: ' . $table),
    };
}

function usernameMethodKey($method): string
{
    return 'numericIdRandom';
}

function panelAgentValue($value, $agent)
{
    return json_decode((string) $value, true)[$agent] ?? 0;
}

function jdate($format, $timestamp = null): string
{
    return '1405/07/12';
}

function sendmessage(...$args): void
{
}

$pdo = new MiniAppHandlerPdo();
$usercheck = [
    'id' => '42', 'agent' => 'f', 'pricediscount' => 20,
    'codeInvitation' => 'invite-42', 'number' => 'none', 'Balance' => 90,
    'register' => 1700000000, 'affiliatescount' => 0,
];
$tokencheck = 'stale-other-account-token';
$setting = [
    'statusnamecustom' => 'offnamecustom', 'statusnoteforf' => '0',
    'statuscategorygenral' => 'oncategorys', 'statuscategory' => 'oncategory',
];
$panel = [
    'status' => 'active', 'agent' => 'all', 'hide_user' => '[]',
    'type' => 'marzban', 'code_panel' => 'panel-1', 'name_panel' => 'VPN',
    'MethodUsername' => 'numericIdRandom', 'customvolume' => '{"f":1}',
    'mainvolume' => '{"f":1}', 'maxvolume' => '{"f":100}',
    'maintime' => '{"f":1}', 'maxtime' => '{"f":365}',
    'pricecustomvolume' => '{"f":7}', 'pricecustomtime' => '{"f":1}',
];
$textbotlang = [
    'common' => [
        'labels' => ['receiptNotSent' => 'No phone', 'testServiceName' => 'Test'],
        'roles' => ['normal' => 'Normal', 'agent' => 'Agent', 'advancedAgent' => 'Advanced'],
        'duration' => ['1' => 'One month'],
    ],
    'users' => [
        'customSellVolume' => ['title' => 'Custom plan'],
        'Discount' => ['discountapplied' => '%s percent discount'],
        'Balance' => ['lessThanPrice' => 'Insufficient balance'],
    ],
];

function handlerResponse(string $function, array $data = []): array
{
    http_response_code(200);
    ob_start();
    try {
        $function($data + ['user_id' => '42', 'id_panel' => 'panel-1', 'category_id' => 'category-1', 'time_range_day' => 30, 'traffic_gb' => 10, 'time_days' => 30], 'GET');
        return json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        ob_end_clean();
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// A signed Telegram launch may have no bearer, or a bearer from another account.
// Every handler must use the identity already accepted by authentication.
foreach (['', 'stale-other-account-token'] as $tokencheck) {
    foreach (['mini_user_info', 'mini_countries', 'mini_categories', 'mini_time_ranges', 'mini_services', 'mini_custom_price'] as $handler) {
        $response = handlerResponse($handler);
        check(($response['status'] ?? null) === true, $handler . ' failed for signed Telegram authentication');
        if ($handler === 'mini_countries') {
            check(count($response['obj']) === 1, 'Hidden country must still be excluded');
        }
        if ($handler === 'mini_services') {
            check((float) $response['obj'][0]['price'] === 80.0, 'Catalog must retain the authenticated user discount');
        }
    }
}

foreach ([0, 20, 50] as $discount) {
    $usercheck['pricediscount'] = $discount;
    $quote = handlerResponse('mini_custom_price');
    ob_start();
    try {
        mini_purchase(['country_id' => 'panel-1', 'custom_service' => ['traffic_gb' => 10, 'time_days' => 30]], 'POST');
        throw new RuntimeException('Custom checkout did not reach the wallet operation');
    } catch (MiniAppWalletCharge $charge) {
        check((float) $quote['obj']['price'] === (float) $charge->parameters[':price'], 'Custom quote and wallet charge must match at discount ' . $discount);
        check((string) $charge->parameters[':id'] === '42', 'Wallet must charge authenticated user');
    } finally {
        ob_end_clean();
    }
}

$panel['hide_user'] = '[42]';
foreach (['mini_categories', 'mini_time_ranges', 'mini_services', 'mini_custom_price'] as $handler) {
    $response = handlerResponse($handler);
    check($response['status'] === false && http_response_code() === 403, $handler . ' must keep panel authorization');
}

echo "Mini App authenticated handler and custom price tests OK.\n";
