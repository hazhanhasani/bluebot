<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        is_secret TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        SQL,
    'ensureUtf8mb4' => true,
    'seed' => [
        ['setting_key' => 'tgtools_base_url', 'setting_value' => 'https://api.tg-tools.shop', 'is_secret' => 0],
        ['setting_key' => 'tgtools_api_key', 'setting_value' => '', 'is_secret' => 1],
        ['setting_key' => 'tgtools_payment_method', 'setting_value' => 'ton', 'is_secret' => 0],
        ['setting_key' => 'tgtools_catalog_last_sync', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'tgtools_stars_profit_percent', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'tgtools_premium_profit_percent', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'tgtools_ton_toman_rate', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_base_url', 'setting_value' => 'https://api.ozvinoo.xyz', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_order_path', 'setting_value' => '', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_api_key', 'setting_value' => '', 'is_secret' => 1],
        ['setting_key' => 'ozvinoo_auth_header', 'setting_value' => 'Authorization', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_auth_prefix', 'setting_value' => 'Bearer', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_catalog_path', 'setting_value' => '', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_profit_percent', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_currency', 'setting_value' => 'toman', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_exchange_rate_toman', 'setting_value' => '1', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_sync_interval_minutes', 'setting_value' => '15', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_api_style', 'setting_value' => 'auto', 'is_secret' => 0],
    ],
];

