<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(190) NOT NULL,
        referrer_id VARCHAR(200) NOT NULL,
        referred_user_id VARCHAR(200) NOT NULL,
        source_type VARCHAR(40) NOT NULL,
        source_id VARCHAR(190) NOT NULL,
        amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
        percent DECIMAL(7,3) NOT NULL DEFAULT 0,
        source_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_referral_commission_event (event_key),
        KEY idx_referral_commission_referrer (referrer_id, created_at),
        KEY idx_referral_commission_referred (referred_user_id, created_at),
        KEY idx_referral_commission_source (source_type, source_id)
        SQL,
    'ensureUtf8mb4' => true,
];
