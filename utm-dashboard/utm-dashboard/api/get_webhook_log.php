<?php
// === get_webhook_log.php ===
// НАЗНАЧЕНИЕ: API для получения детальной информации о webhook логе
// ИСПОЛЬЗОВАНИЕ: GET /api/get_webhook_log.php?id=123

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/WebhookLog.php';

try {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        throw new Exception('Missing parameter: id');
    }

    $log = WebhookLog::getById($id);

    if (!$log) {
        throw new Exception('Log not found');
    }

    echo json_encode([
        'success' => true,
        'log' => $log
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
