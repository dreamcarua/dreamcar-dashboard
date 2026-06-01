<?php
/**
 * get_unique_values.php
 * API: Отримати унікальні значення CRM та ADS для dropdown
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Session.php';
require_once __DIR__ . '/../../core/Database.php';

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
    $fieldType = $_GET['field_type'] ?? 'utm_term';

    // Валідація поля
    $allowedFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    if (!in_array($fieldType, $allowedFields)) {
        throw new Exception('Невірний тип поля');
    }

    $db = Database::getInstance();

    // Отримати унікальні значення з CRM
    $sqlCrm = "SELECT DISTINCT $fieldType as value
               FROM crm_deals
               WHERE $fieldType IS NOT NULL AND $fieldType != ''
               ORDER BY $fieldType";
    $crmValues = $db->fetchAll($sqlCrm);

    // Отримати унікальні значення з ADS
    $sqlAds = "SELECT DISTINCT $fieldType as value
               FROM ads_data
               WHERE $fieldType IS NOT NULL AND $fieldType != ''
               ORDER BY $fieldType";
    $adsValues = $db->fetchAll($sqlAds);

    // Форматувати для dropdown
    $crm = array_column($crmValues, 'value');
    $ads = array_column($adsValues, 'value');

    echo json_encode([
        'success' => true,
        'crm_values' => $crm,
        'ads_values' => $ads,
        'field_type' => $fieldType
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
