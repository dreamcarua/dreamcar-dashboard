<?php
// === 005_fix_dreamcar_ai_projects.php ===
// НАЗНАЧЕНИЕ: Обновить deal_project для сделок DreamCar AI (DC-/DCP-/LAVA- префиксы)
// Эти сделки получали order_reference как deal_project вместо "DreamCar AI"

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);

    // Подсчитать затронутые сделки
    $countSql = "SELECT COUNT(*) as cnt FROM crm_deals
                 WHERE deal_project NOT IN ('BMW', 'Mercedes', 'Q7', 'VOLVO', 'OLD', 'TEST', 'DreamCar AI', 'UNKNOWN')
                   AND (deal_project LIKE 'DC-%' OR deal_project LIKE 'DCP-%' OR deal_project LIKE 'LAVA-%'
                        OR model LIKE '%DreamCar AI%')";
    $count = $pdo->query($countSql)->fetch()['cnt'];

    if ($count == 0) {
        echo json_encode(['success' => true, 'message' => 'Нет сделок для обновления', 'updated' => 0]);
        exit;
    }

    // Обновить deal_project на "DreamCar AI"
    $updateSql = "UPDATE crm_deals SET deal_project = 'DreamCar AI'
                  WHERE deal_project NOT IN ('BMW', 'Mercedes', 'Q7', 'VOLVO', 'OLD', 'TEST', 'DreamCar AI', 'UNKNOWN')
                    AND (deal_project LIKE 'DC-%' OR deal_project LIKE 'DCP-%' OR deal_project LIKE 'LAVA-%'
                         OR model LIKE '%DreamCar AI%')";
    $updated = $pdo->exec($updateSql);

    echo json_encode([
        'success' => true,
        'message' => "Обновлено $updated сделок: deal_project = 'DreamCar AI'",
        'found' => $count,
        'updated' => $updated
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
