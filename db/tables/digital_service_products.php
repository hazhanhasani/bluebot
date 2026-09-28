<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(80) NOT NULL,
        name VARCHAR(190) NOT NULL,
        type VARCHAR(50) NOT NULL,
        provider VARCHAR(50) NOT NULL DEFAULT 'manual',
        price BIGINT UNSIGNED NOT NULL DEFAULT 0,
        service_value INT UNSIGNED NOT NULL DEFAULT 1,
        provider_service_code VARCHAR(190) NULL,
        description TEXT NULL,
        metadata TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_digital_service_code (code)
        SQL,
    'ensureUtf8mb4' => true,
];

