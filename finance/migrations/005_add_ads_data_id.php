<?php
// === 005_add_ads_data_id.php ===
// finance/migrations/005_add_ads_data_id.php
// НАЗНАЧЕНИЕ: Добавить связь finance_transactions → ads_data (manual_costs)
// Для синхронизации ручных расходов из manual_costs.php с финансовым модулем.
// РАЗМЕР: ~50 строк

declare(strict_types=1);
ini_set('max_execution_time', '60');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$db     = Database::getInstance();
$result = ['success' => false, 'steps' => []];

try {
    // 1. Проверить есть ли колонка ads_data_id
    $col = $db->query(
        "SHOW COLUMNS FROM finance_transactions LIKE 'ads_data_id'"
    )->fetch(PDO::FETCH_ASSOC);

    if ($col) {
        $result['steps'][] = 'ads_data_id уже существует - пропускаем';
    } else {
        $db->query(
            "ALTER TABLE finance_transactions
             ADD COLUMN ads_data_id INT UNSIGNED NULL DEFAULT NULL
             COMMENT 'Связь с ads_data для ручных расходов из manual_costs.php'
             AFTER payroll_id"
        );
        $result['steps'][] = '✅ Колонка ads_data_id добавлена';
    }

    // 2. Проверить индекс
    $idx = $db->query(
        "SHOW INDEX FROM finance_transactions WHERE Key_name = 'idx_ads_data_id'"
    )->fetch(PDO::FETCH_ASSOC);

    if ($idx) {
        $result['steps'][] = 'Индекс idx_ads_data_id уже существует';
    } else {
        $db->query(
            "ALTER TABLE finance_transactions ADD INDEX idx_ads_data_id (ads_data_id)"
        );
        $result['steps'][] = '✅ Индекс idx_ads_data_id создан';
    }

    // 3. Проверить итоговую структуру
    $finalCol = $db->query(
        "SHOW COLUMNS FROM finance_transactions LIKE 'ads_data_id'"
    )->fetch(PDO::FETCH_ASSOC);

    $result['final_column'] = $finalCol;
    $result['success']      = true;
    $result['message']      = 'Миграция 005 выполнена успешно';

} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
