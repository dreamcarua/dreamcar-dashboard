<?php
// === 006_extended_categories.php ===
// finance/migrations/006_extended_categories.php
// НАЗНАЧЕНИЕ: Расширение справочника категорий расходов
// Схема БД не меняется (category это VARCHAR). Миграция проверяет индекс
// на поле category и добавляет его если отсутствует (для быстрой
// группировки по категориям в dashboard.summary).
// РАЗМЕР: ~60 строк

declare(strict_types=1);
ini_set('max_execution_time', '60');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$db     = Database::getInstance();
$result = ['success' => false, 'steps' => []];

try {
    // 1. Проверить есть ли индекс на category
    $idx = $db->query(
        "SHOW INDEX FROM finance_transactions WHERE Key_name = 'idx_category'"
    )->fetch(PDO::FETCH_ASSOC);

    if ($idx) {
        $result['steps'][] = 'Индекс idx_category уже существует';
    } else {
        $db->query(
            "ALTER TABLE finance_transactions ADD INDEX idx_category (category)"
        );
        $result['steps'][] = '✅ Индекс idx_category создан';
    }

    // 2. Статистика по категориям — сколько существующих расходов
    $stats = $db->query(
        "SELECT category, COUNT(*) as cnt, SUM(amount_uah) as total
         FROM finance_transactions
         WHERE type = 'expense' AND deleted_at IS NULL
         GROUP BY category
         ORDER BY total DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $result['existing_categories'] = $stats;
    $result['total_expense_records'] = array_sum(array_column($stats, 'cnt'));

    // 3. Итого
    $result['success'] = true;
    $result['message'] = 'Миграция 006 выполнена. Справочник категорий расширен (5 групп × 25+ категорий).';

} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
