<?php
/**
 * API: Збереження налаштувань проектів
 * Файл: api/save_settings.php
 * Призначення: Зберігає активний проект в конфігурації
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/CrmDeal.php';

try {
    // Отримати JSON з тіла запиту
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if ($data === null) {
        throw new Exception('Невірний формат JSON');
    }

    // Отримати та валідувати проект
    $project = isset($data['active_project']) ? trim($data['active_project']) : '';

    if (empty($project)) {
        throw new Exception('Поле active_project обов\'язкове');
    }

    // Нормалізувати назву проекту
    $project = strtoupper($project);

    // Перевірити що проект не є алиасом (алиаси не можна вибирати як активний проект)
    if (CrmDeal::isProjectAlias($project)) {
        throw new Exception("Проект {$project} є алиасом і не може бути обраний як активний. Оберіть основний проект (наприклад Q7)");
    }
    
    // Дозволити збереження будь-якого проекту (навіть якщо його немає в БД)
    // Проект може бути новим або без сделок

    // Підготувати дані для збереження
    $settings = [
        'active_project' => $project,
        'last_updated' => date('Y-m-d H:i:s')
    ];

    // Зберегти в JSON файл
    $settingsFile = __DIR__ . '/../config/dashboard_settings.json';
    $jsonContent = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($jsonContent === false) {
        throw new Exception('Помилка створення JSON');
    }

    $result = file_put_contents($settingsFile, $jsonContent);

    if ($result === false) {
        throw new Exception('Помилка запису в файл конфігурації');
    }

    // Успішна відповідь
    echo json_encode([
        'success' => true,
        'message' => 'Налаштування успішно збережено',
        'active_project' => $project,
        'last_updated' => $settings['last_updated']
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(400);
    
    // Логировать ошибку для отладки
    error_log('[save_settings.php] Ошибка: ' . $e->getMessage());
    error_log('[save_settings.php] Проект: ' . ($project ?? 'не определен'));
    error_log('[save_settings.php] Данные запроса: ' . ($input ?? 'пусто'));
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'project' => $project ?? null,
        'debug' => [
            'input' => $input ?? null,
            'data' => $data ?? null
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
