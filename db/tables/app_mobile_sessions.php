<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        device_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY uq_app_mobile_token (token_hash),
        KEY idx_app_mobile_account (account_id),
        KEY idx_app_mobile_expiry (expires_at),
        CONSTRAINT fk_app_mobile_account FOREIGN KEY (account_id) REFERENCES app_mobile_accounts(id) ON DELETE CASCADE
        SQL,
    'ensureUtf8mb4' => true,
];
