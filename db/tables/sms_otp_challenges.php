<?php

return [
    'create' => <<<SQL
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(500) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        code_hash VARCHAR(128) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        max_attempts INT NOT NULL DEFAULT 5,
        expires_at BIGINT NOT NULL,
        resend_at BIGINT NOT NULL,
        consumed_at BIGINT NULL,
        created_at BIGINT NOT NULL
        SQL,
    'columns' => [
        ['user_id', '', 'VARCHAR(500) NOT NULL'],
        ['phone', '', 'VARCHAR(30) NOT NULL'],
        ['code_hash', '', 'VARCHAR(128) NOT NULL'],
        ['attempts', '0', 'INT NOT NULL DEFAULT 0'],
        ['max_attempts', '5', 'INT NOT NULL DEFAULT 5'],
        ['expires_at', '0', 'BIGINT NOT NULL'],
        ['resend_at', '0', 'BIGINT NOT NULL'],
        ['consumed_at', null, 'BIGINT NULL'],
        ['created_at', '0', 'BIGINT NOT NULL'],
    ],
];
