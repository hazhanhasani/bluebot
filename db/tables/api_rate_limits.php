<?php
return ['options'=>'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','create'=><<<SQL
bucket_key VARCHAR(190) PRIMARY KEY,
hits INT UNSIGNED NOT NULL DEFAULT 0,
window_started_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
KEY idx_api_rate_window (window_started_at)
SQL,'ensureUtf8mb4'=>true];