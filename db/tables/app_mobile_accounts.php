<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        linked_telegram_id VARCHAR(64) NULL,
        verified_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_app_mobile_phone (phone),
        UNIQUE KEY uq_app_mobile_user (user_id),
        KEY idx_app_mobile_telegram (linked_telegram_id)
        SQL,
    'ensureUtf8mb4' => true,
];
