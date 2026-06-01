<?php
// Тестовый файл для проверки by_date
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';

try {
    $db = Database::getInstance();

    // Простой запрос без фильтров
    $sql = "SELECT
        DATE(created_at) as date,
        COUNT(*) as leads,
        SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as revenue
    FROM crm_deals
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 10";

    $results = $db->fetchAll($sql);

    $byDate = [];
    foreach ($results as $row) {
        $byDate[$row['date']] = [
            'leads' => (int)$row['leads'],
            'revenue' => (float)$row['revenue']
        ];
    }

    echo json_encode([
        'success' => true,
        'query' => $sql,
        'raw_results' => $results,
        'by_date' => $byDate,
        'count' => count($results)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
