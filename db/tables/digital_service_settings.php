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
        ['setting_key' => 'tgtools_ton_rate_source', 'setting_value' => 'nobitex', 'is_secret' => 0],
        ['setting_key' => 'tgtools_ton_rate_market', 'setting_value' => 'GRAMIRT', 'is_secret' => 0],
        ['setting_key' => 'tgtools_ton_rate_last_sync', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'tgtools_ton_rate_market_update', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'tgtools_ton_rate_last_error', 'setting_value' => '', 'is_secret' => 0],
        ['setting_key' => 'tgtools_nobitex_trade_fee_percent', 'setting_value' => '0.25', 'is_secret' => 0],
        ['setting_key' => 'tgtools_gram_network_fee', 'setting_value' => '0.000562', 'is_secret' => 0],
        ['setting_key' => 'tgtools_gram_funding_batch', 'setting_value' => '1', 'is_secret' => 0],
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
        ['setting_key' => 'ozvinoo_api_style', 'setting_value' => 'official-v1', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_catalog_last_sync', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'ozvinoo_catalog_schema_version', 'setting_value' => '0', 'is_secret' => 0],
        ['setting_key' => 'digital_services_keyboard_initialized', 'setting_value' => '0', 'is_secret' => 0],
    ],
];

