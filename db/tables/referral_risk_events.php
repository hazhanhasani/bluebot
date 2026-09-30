<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(200) NOT NULL,
        inviter_id VARCHAR(200) NULL,
        event_type VARCHAR(60) NOT NULL,
        reason VARCHAR(120) NOT NULL,
        payload VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_referral_risk_user (user_id, created_at),
        KEY idx_referral_risk_inviter (inviter_id, created_at),
        KEY idx_referral_risk_event (event_type, created_at)
        SQL,
    'ensureUtf8mb4' => true,
];
