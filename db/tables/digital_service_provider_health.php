<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(50) NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        total_requests INT UNSIGNED NOT NULL DEFAULT 0,
        success_count INT UNSIGNED NOT NULL DEFAULT 0,
        consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
        availability_failures INT UNSIGNED NOT NULL DEFAULT 0,
        timeout_failures INT UNSIGNED NOT NULL DEFAULT 0,
        suspended_until DATETIME NULL,
        suspend_reason VARCHAR(50) NULL,
        last_error VARCHAR(500) NULL,
        last_result_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_digital_provider_health (provider, service_id),
        KEY idx_digital_provider_suspended (suspended_until),
        KEY idx_digital_provider_health_updated (updated_at)
        SQL,
    'ensureUtf8mb4' => true,
];
