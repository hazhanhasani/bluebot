<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(200) NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_digital_service_favorite (user_id, service_id),
        KEY idx_digital_service_favorite_user (user_id, created_at),
        KEY idx_digital_service_favorite_service (service_id)
        SQL,
    'ensureUtf8mb4' => true,
];
