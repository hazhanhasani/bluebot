<?php
return ['options'=>'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','create'=><<<SQL
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
commission_id BIGINT UNSIGNED NOT NULL,
event_key VARCHAR(190) NOT NULL,
referrer_id VARCHAR(200) NOT NULL,
amount BIGINT UNSIGNED NOT NULL,
reason VARCHAR(190) NOT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
UNIQUE KEY uniq_referral_reversal_event (event_key),
UNIQUE KEY uniq_referral_reversal_commission (commission_id),
KEY idx_referral_reversal_referrer (referrer_id, created_at)
SQL,'ensureUtf8mb4'=>true];