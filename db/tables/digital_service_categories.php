<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_key VARCHAR(40) NOT NULL,
        name VARCHAR(120) NOT NULL,
        emoji VARCHAR(32) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_digital_service_category_key (category_key)
        SQL,
    'seedOnCreate' => [
        ['category_key' => 'premium', 'name' => 'تلگرام پرمیوم', 'emoji' => '🎁', 'sort_order' => 10, 'active' => 1],
        ['category_key' => 'stars', 'name' => 'استارز تلگرام', 'emoji' => '⭐', 'sort_order' => 20, 'active' => 1],
        ['category_key' => 'virtual_number', 'name' => 'شماره مجازی', 'emoji' => '📱', 'sort_order' => 30, 'active' => 1],
        ['category_key' => 'telegram', 'name' => 'خدمات تلگرام', 'emoji' => '✈️', 'sort_order' => 40, 'active' => 1],
        ['category_key' => 'instagram', 'name' => 'خدمات اینستاگرام', 'emoji' => '📸', 'sort_order' => 50, 'active' => 1],
        ['category_key' => 'youtube', 'name' => 'خدمات یوتیوب', 'emoji' => '▶️', 'sort_order' => 60, 'active' => 1],
        ['category_key' => 'twitter', 'name' => 'خدمات X / توییتر', 'emoji' => '𝕏', 'sort_order' => 70, 'active' => 1],
        ['category_key' => 'tiktok', 'name' => 'خدمات تیک‌تاک', 'emoji' => '🎵', 'sort_order' => 80, 'active' => 1],
        ['category_key' => 'spotify', 'name' => 'خدمات اسپاتیفای', 'emoji' => '🎧', 'sort_order' => 90, 'active' => 1],
        ['category_key' => 'linkedin', 'name' => 'خدمات لینکدین', 'emoji' => '💼', 'sort_order' => 100, 'active' => 1],
        ['category_key' => 'facebook', 'name' => 'خدمات فیسبوک', 'emoji' => '📘', 'sort_order' => 110, 'active' => 1],
        ['category_key' => 'whatsapp', 'name' => 'خدمات واتساپ', 'emoji' => '🟢', 'sort_order' => 120, 'active' => 1],
        ['category_key' => 'giftcards', 'name' => 'گیفت‌کارت', 'emoji' => '🎁', 'sort_order' => 130, 'active' => 1],
        ['category_key' => 'games', 'name' => 'بازی و شارژ', 'emoji' => '🎮', 'sort_order' => 140, 'active' => 1],
        ['category_key' => 'apple', 'name' => 'خدمات اپل', 'emoji' => '🍎', 'sort_order' => 150, 'active' => 1],
        ['category_key' => 'chatgpt', 'name' => 'هوش مصنوعی', 'emoji' => '🤖', 'sort_order' => 160, 'active' => 1],
        ['category_key' => 'design', 'name' => 'طراحی و گرافیک', 'emoji' => '🎨', 'sort_order' => 170, 'active' => 1],
        ['category_key' => 'other', 'name' => 'سایر خدمات', 'emoji' => '🧩', 'sort_order' => 999, 'active' => 1],
    ],
    'ensureUtf8mb4' => true,
];
