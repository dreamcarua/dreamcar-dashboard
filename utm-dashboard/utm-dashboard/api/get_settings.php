<?php
/**
 * API: Отримання налаштувань проектів
 * Файл: api/get_settings.php
 * Призначення: Повертає активний проект з конфігурації
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $settingsFile = __DIR__ . '/../config/dashboard_settings.json';

    // Якщо файл існує - читаємо налаштування
    if (file_exists($settingsFile)) {
        $fileContent = file_get_contents($settingsFile);
        $settings = json_decode($fileContent, true);

        if ($settings === null) {
            throw new Exception('Помилка парсингу JSON конфігурації');
        }

        echo json_encode([
            'success' => true,
            'active_project' => $settings['active_project'] ?? 'VOLVO',
            'last_updated' => $settings['last_updated'] ?? null
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } else {
        // Файл не існує - повертаємо дефолтні значення
        echo json_encode([
            'success' => true,
            'active_project' => 'VOLVO',
            'last_updated' => null,
            'note' => 'Використано дефолтні значення (файл конфігурації не знайдено)'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Помилка отримання налаштувань: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
