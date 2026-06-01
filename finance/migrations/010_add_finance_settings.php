<?php
// === 010_add_finance_settings.php ===
// finance/migrations/010_add_finance_settings.php
// НАЗНАЧЕНИЕ: Создать таблицу finance_settings для хранения % комиссий и налогов
// СВЯЗИ: config/database.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Database.php';

$db = Database::getInstance();

// Создаем таблицу настроек
$db->query("
    CREATE TABLE IF NOT EXISTS finance_settings (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(64) NOT NULL UNIQUE,
        setting_val VARCHAR(255) NOT NULL,
        description VARCHAR(255) NULL,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by  VARCHAR(100) NULL,
        INDEX idx_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Вставляем дефолтные значения (2% и 10%) если их нет
$db->query("
    INSERT IGNORE INTO finance_settings (setting_key, setting_val, description)
    VALUES
        ('acquiring_fee_pct',  '2',  'Комiсiя еквайрингу (%) — автоматично нараховується при кожному доходi'),
        ('tax_pct',            '10', 'Податки та бух. витрати (%) — автоматично нараховуються при кожному доходi')
");

echo "Migration 010: finance_settings table created, defaults inserted.\n";
