<?php
// === check_model_values.php ===
// НАЗНАЧЕНИЕ: Диагностика - какие значения model есть в базе
// ИСПОЛЬЗОВАНИЕ: Открыть в браузере

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getInstance();

    // Статистика по model
    $sql = "SELECT
        COALESCE(model, 'NULL') as model_value,
        COUNT(*) as cnt,
        SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as amount
    FROM crm_deals
    GROUP BY model
    ORDER BY cnt DESC";

    $results = $db->fetchAll($sql);

    // Проверить UPPER
    $sql2 = "SELECT
        UPPER(COALESCE(model, 'NULL')) as model_upper,
        COUNT(*) as cnt
    FROM crm_deals
    GROUP BY UPPER(model)
    ORDER BY cnt DESC";

    $resultsUpper = $db->fetchAll($sql2);

    // Проверить sms источник - какой у него model
    $sql3 = "SELECT model, utm_source, COUNT(*) as cnt
    FROM crm_deals
    WHERE utm_source IN ('sms', 'oborotfb', 'viber', 'telegram')
    GROUP BY model, utm_source
    ORDER BY utm_source, cnt DESC";

    $sourcesByModel = $db->fetchAll($sql3);

    echo json_encode([
        'model_values' => $results,
        'model_upper' => $resultsUpper,
        'sources_by_model' => $sourcesByModel,
        'note' => 'Проверь какие model есть. VOLVO должен быть основным.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
