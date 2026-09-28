<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(64) NOT NULL,
        user_id VARCHAR(200) NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        service_code VARCHAR(80) NOT NULL,
        service_name VARCHAR(190) NOT NULL,
        target VARCHAR(255) NOT NULL,
        amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        provider VARCHAR(50) NOT NULL DEFAULT 'manual',
        status VARCHAR(40) NOT NULL DEFAULT 'pending_approval',
        refunded TINYINT(1) NOT NULL DEFAULT 0,
        admin_id VARCHAR(200) NULL,
        provider_reference VARCHAR(255) NULL,
        provider_response TEXT NULL,
        admin_note TEXT NULL,
        approved_at DATETIME NULL,
        delivered_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_digital_order_code (order_code)
        SQL,
    'ensureUtf8mb4' => true,
];

