<?php
/**
 * save_mapping.php
 * API: Створити або оновити mapping CRM-ADS
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
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Невірний формат JSON');
    }

    // Валідація обов'язкових полів
    $required = ['field_type', 'crm_value', 'ads_value', 'merged_name'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Поле '$field' обов'язкове");
        }
    }

    // Валідація field_type
    $allowedTypes = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    if (!in_array($data['field_type'], $allowedTypes)) {
        throw new Exception('Невірний тип поля');
    }

    // Додати інформацію про користувача
    $user = Session::get('user');
    $data['created_by'] = $user['username'] ?? 'admin';

    // Створити або оновити
    if (!empty($data['id'])) {
        $id = UtmCrmAdsMapping::update($data['id'], $data);
        $action = 'updated';
        $message = 'Відповідність успішно оновлено';
    } else {
        $id = UtmCrmAdsMapping::create($data);
        $action = 'created';
        $message = 'Відповідність успішно створено';
    }

    echo json_encode([
        'success' => true,
        'id' => $id,
        'action' => $action,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
