<?php
/**
 * get_mappings.php
 * API: Отримати всі mappings CRM-ADS
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Session.php';
require_once __DIR__ . '/../../core/models/UtmCrmAdsMapping.php';

// Перевірка сесії без редиректу (для API)
Session::start();

if (!Session::isLoggedIn() || Session::getRole() !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Доступ заборонено. Потрібна авторизація адміністратора.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $fieldType = $_GET['field_type'] ?? null;

    $filters = [];
    if ($fieldType) {
        $filters['field_type'] = $fieldType;
    }

    $mappings = UtmCrmAdsMapping::getAll($filters);

    echo json_encode([
        'success' => true,
        'mappings' => $mappings,
        'count' => count($mappings)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
