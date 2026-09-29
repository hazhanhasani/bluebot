<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider_key VARCHAR(50) NOT NULL,
        name VARCHAR(190) NOT NULL,
        catalog_url VARCHAR(500) NOT NULL,
        api_key TEXT NULL,
        auth_header VARCHAR(80) NOT NULL DEFAULT 'Authorization',
        auth_prefix VARCHAR(50) NOT NULL DEFAULT 'Bearer',
        products_path VARCHAR(190) NOT NULL DEFAULT 'data',
        id_field VARCHAR(100) NOT NULL DEFAULT 'id',
        name_field VARCHAR(100) NOT NULL DEFAULT 'name',
        category_field VARCHAR(100) NOT NULL DEFAULT 'category',
        price_field VARCHAR(100) NOT NULL DEFAULT 'price',
        currency VARCHAR(20) NOT NULL DEFAULT 'toman',
        exchange_rate_toman DECIMAL(20,6) NOT NULL DEFAULT 1,
        profit_percent DECIMAL(8,3) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sync_interval_minutes INT UNSIGNED NOT NULL DEFAULT 15,
        last_sync_at DATETIME NULL,
        last_sync_status VARCHAR(30) NULL,
        last_sync_message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_digital_service_provider_key (provider_key)
        SQL,
    'ensureUtf8mb4' => true,
];
