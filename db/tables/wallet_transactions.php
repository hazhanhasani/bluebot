<?php
return [
 'options'=>'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
 'create'=><<<SQL
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(190) NOT NULL,
 user_id VARCHAR(200) NOT NULL,
 direction ENUM('credit','debit') NOT NULL,
 amount BIGINT UNSIGNED NOT NULL,
 balance_after BIGINT NULL,
 source_type VARCHAR(50) NOT NULL,
 source_id VARCHAR(190) NOT NULL,
 metadata TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_wallet_event (event_key),
 KEY idx_wallet_user_created (user_id, created_at),
 KEY idx_wallet_source (source_type, source_id)
 SQL,
 'ensureUtf8mb4'=>true,
];