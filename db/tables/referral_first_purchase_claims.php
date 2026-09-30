<?php
return ['options'=>'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','create'=><<<SQL
referred_user_id VARCHAR(200) PRIMARY KEY,
event_key VARCHAR(190) NOT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
UNIQUE KEY uniq_referral_first_event (event_key)
SQL,'ensureUtf8mb4'=>true];
