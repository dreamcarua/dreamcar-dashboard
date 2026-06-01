<?php
// === 007_retroactive_auto_expenses.php ===
// finance/migrations/007_retroactive_auto_expenses.php
// НАЗНАЧЕНИЕ: Ретроспективно создать авто-расходы 2% + 10% для всех существующих income транзакций
// СВЯЗИ: finance/core/models/FinanceTransaction.php
// РАЗМЕР: ~80 строк

declare(strict_types=1);
ini_set('max_execution_time', '300');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../core/models/FinanceTransaction.php';

header('Content-Type: application/json; charset=utf-8');

$db     = Database::getInstance();
$result = [
    'success'  => false,
    'created'  => 0,
    'skipped'  => 0,
    'errors'   => 0,
    'batches'  => 0,
    'details'  => [],
];

try {
    // Проверить наличие колонки parent_transaction_id
    $col = $db->query(
        "SHOW COLUMNS FROM finance_transactions LIKE 'parent_transaction_id'"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$col) {
        $result['error'] = 'Колонка parent_transaction_id не существует. Сначала выполните миграцию 006.';
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Получить все активные income транзакции батчами по 100
    $offset    = 0;
    $batchSize = 100;

    do {
        $rows = $db->query(
            "SELECT id, amount_uah, project_id, transaction_date, created_by
             FROM finance_transactions
             WHERE type IN ('income', 'income_extra')
               AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            [':limit' => $batchSize, ':offset' => $offset]
        )->fetchAll(PDO::FETCH_ASSOC);

        $result['batches']++;

        foreach ($rows as $row) {
            $parentId = (int)$row['id'];

            // Проверить: нет ли уже авто-расходов для этой транзакции
            $existing = $db->query(
                "SELECT COUNT(*) AS cnt FROM finance_transactions
                 WHERE parent_transaction_id = :pid
                   AND source_type = 'crm_auto'
                   AND deleted_at IS NULL",
                [':pid' => $parentId]
            )->fetch(PDO::FETCH_ASSOC);

            if ((int)($existing['cnt'] ?? 0) > 0) {
                $result['skipped']++;
                continue;
            }

            try {
                FinanceTransaction::createAutoExpenses(
                    $parentId,
                    (float)$row['amount_uah'],
                    (int)$row['project_id'],
                    $row['transaction_date'],
                    'migration_007'
                );
                $result['created'] += 2;
                $result['details'][] = 'income #' . $parentId . ' -> fee + tax created';
            } catch (Throwable $eRow) {
                $result['errors']++;
                $result['details'][] = 'income #' . $parentId . ' error: ' . $eRow->getMessage();
            }
        }

        $offset += $batchSize;
    } while (count($rows) === $batchSize);

    $result['success'] = true;
    $result['message'] = 'Миграция 007 выполнена. Создано: ' . $result['created'] . ', пропущено: ' . $result['skipped'];

} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
