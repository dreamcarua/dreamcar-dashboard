<?php
// === 006_add_parent_transaction_id.php ===
// finance/migrations/006_add_parent_transaction_id.php
// НАЗНАЧЕНИЕ: Добавить parent_transaction_id для авто-расходов (2% + 10%) к входящим платежам
// СВЯЗИ: finance/core/models/FinanceTransaction.php
// РАЗМЕР: ~60 строк

declare(strict_types=1);
ini_set('max_execution_time', '60');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$db     = Database::getInstance();
$result = ['success' => false, 'steps' => []];

try {
    // 1. Проверить наличие колонки parent_transaction_id
    $col = $db->query(
        "SHOW COLUMNS FROM finance_transactions LIKE 'parent_transaction_id'"
    )->fetch(PDO::FETCH_ASSOC);

    if ($col) {
        $result['steps'][] = 'parent_transaction_id уже существует - пропускаем';
    } else {
        $db->query(
            "ALTER TABLE finance_transactions
             ADD COLUMN parent_transaction_id BIGINT UNSIGNED NULL DEFAULT NULL
             COMMENT 'Ссылка на родительскую транзакцию (для авто-расходов 2% и 10%)'
             AFTER source_type"
        );
        $result['steps'][] = '✅ Колонка parent_transaction_id добавлена';
    }

    // 2. Проверить индекс
    $idx = $db->query(
        "SHOW INDEX FROM finance_transactions WHERE Key_name = 'idx_parent_tx'"
    )->fetch(PDO::FETCH_ASSOC);

    if ($idx) {
        $result['steps'][] = 'Индекс idx_parent_tx уже существует';
    } else {
        $db->query(
            "ALTER TABLE finance_transactions ADD INDEX idx_parent_tx (parent_transaction_id)"
        );
        $result['steps'][] = '✅ Индекс idx_parent_tx создан';
    }

    // 3. Проверить итоговую структуру
    $finalCol = $db->query(
        "SHOW COLUMNS FROM finance_transactions LIKE 'parent_transaction_id'"
    )->fetch(PDO::FETCH_ASSOC);

    $result['final_column'] = $finalCol;
    $result['success']      = true;
    $result['message']      = 'Миграция 006 выполнена успешно';

} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
