<?php

return [
    'create' => <<<SQL
        event_key VARCHAR(80) PRIMARY KEY,
        title VARCHAR(180) NOT NULL DEFAULT '',
        category VARCHAR(80) NOT NULL DEFAULT '',
        body TEXT NULL,
        variables_json TEXT NULL,
        pattern_code VARCHAR(180) NOT NULL DEFAULT '',
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        broadcast TINYINT(1) NOT NULL DEFAULT 0,
        updated_at BIGINT NULL
        SQL,
    'columns' => [
        ['title', '', 'VARCHAR(180) NOT NULL DEFAULT \'\''],
        ['category', '', 'VARCHAR(80) NOT NULL DEFAULT \'\''],
        ['body', null, 'TEXT NULL'],
        ['variables_json', null, 'TEXT NULL'],
        ['pattern_code', '', 'VARCHAR(180) NOT NULL DEFAULT \'\''],
        ['enabled', '0', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['broadcast', '0', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['updated_at', null, 'BIGINT NULL'],
    ],
];
