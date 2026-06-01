<?php
// === app_config.php ===
// /home/serflow/dreamcar.ai-platform.space/www/dashboard/utm-dashboard/config/app_config.php
// НАЗНАЧЕНИЕ: Основная конфигурация приложения
// СВЯЗИ: Используется во всех PHP файлах
// ДАННЫЕ: -
// API: SendPulse, Google Sheets, Telegram
// РАЗМЕР: ~180 строк
// ОБНОВЛЕНО: 2025-01-24

/**
 * СТРУКТУРА ФАЙЛА:
 * 1. Основные настройки (строки 15-40)
 * 2. API конфигурация (строки 41-90)
 * 3. Пути к файлам (строки 91-120)
 * 4. Системные настройки (строки 121-180)
 */

// ==========================================
// 1. ОСНОВНЫЕ НАСТРОЙКИ
// ==========================================

// Часовой пояс
date_default_timezone_set('Europe/Chisinau');

// Отображение ошибок (только для разработки)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Кодировка
mb_internal_encoding('UTF-8');

// Настройки приложения
define('APP_NAME', 'UTM Dashboard');
define('APP_VERSION', '1.0.0');

// ==========================================
// АВТОМАТИЧЕСКОЕ ОПРЕДЕЛЕНИЕ ОКРУЖЕНИЯ
// ==========================================

/**
 * Определить окружение (локальное/серверное)
 */
function detectEnvironment() {
    // Проверка по хосту
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Локальное окружение
    if (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false ||
        strpos($host, '::1') !== false ||
        (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost')
    ) {
        return 'local';
    }
    
    // Серверное окружение
    return 'production';
}

/**
 * Автоматически определить BASE_URL
 */
function detectBaseUrl() {
    // Проверка переменной окружения (приоритет)
    if (isset($_ENV['BASE_URL']) && !empty($_ENV['BASE_URL'])) {
        return rtrim($_ENV['BASE_URL'], '/') . '/';
    }
    
    $env = detectEnvironment();
    
    if ($env === 'local') {
        // Локальное окружение - определяем автоматически
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8090';
        
        // Получить реальный путь к файлу конфигурации
        $configFile = __FILE__;
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        
        // Вычислить относительный путь от DOCUMENT_ROOT до utm-dashboard
        if (!empty($documentRoot)) {
            // Нормализовать пути
            $configFile = str_replace('\\', '/', $configFile);
            $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
            
            // Если config файл находится внутри DOCUMENT_ROOT
            if (strpos($configFile, $documentRoot) === 0) {
                // Получить путь относительно DOCUMENT_ROOT
                $relativePath = substr($configFile, strlen($documentRoot));
                
                // Убрать имя файла и путь до config
                // Например: /dreamcar.ai-platform.space/www/dashboard/utm-dashboard/config/app_config.php
                // → /dreamcar.ai-platform.space/www/dashboard/utm-dashboard/
                $relativePath = dirname(dirname($relativePath));
                
                // Убрать /www/ если есть (так как пользователь открывает без /www/)
                $relativePath = str_replace('/www/', '/', $relativePath);
                
                $basePath = $relativePath . '/';
            } else {
                // Если файл вне DOCUMENT_ROOT, использовать REQUEST_URI
                $requestUri = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
                $requestUri = parse_url($requestUri, PHP_URL_PATH);
                
                // Убрать /www/ если есть
                $requestUri = str_replace('/www/', '/', $requestUri);
                
                $basePath = dirname($requestUri) . '/';
            }
        } else {
            // Fallback: использовать REQUEST_URI
            $requestUri = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
            $requestUri = parse_url($requestUri, PHP_URL_PATH);
            
            // Убрать /www/ если есть
            $requestUri = str_replace('/www/', '/', $requestUri);
            
            $basePath = dirname($requestUri) . '/';
        }
        
        // Нормализовать путь
        $basePath = preg_replace('#/+#', '/', $basePath);
        if (substr($basePath, 0, 1) !== '/') {
            $basePath = '/' . $basePath;
        }
        
        return $protocol . '://' . $host . $basePath;
    } else {
        // Серверное окружение - определять по HTTP_HOST
        $host = $_SERVER['HTTP_HOST'] ?? 'dreamcar.ai-platform.space';
        return 'https://' . $host . '/dashboard/utm-dashboard/';
    }
}

// Определить окружение
$appEnv = detectEnvironment();
define('APP_ENV', $appEnv);

// Автоматически определить BASE_URL
$baseUrl = detectBaseUrl();
define('BASE_URL', $baseUrl);

// Отладочная информация (только для локального окружения)
if ($appEnv === 'local' && (isset($_GET['debug']) || isset($_ENV['DEBUG']))) {
    error_log('[APP_CONFIG] Окружение: ' . $appEnv);
    error_log('[APP_CONFIG] BASE_URL: ' . $baseUrl);
    error_log('[APP_CONFIG] HTTP_HOST: ' . ($_SERVER['HTTP_HOST'] ?? 'не определен'));
    error_log('[APP_CONFIG] SCRIPT_NAME: ' . ($_SERVER['SCRIPT_NAME'] ?? 'не определен'));
}

// ==========================================
// 2. API КОНФИГУРАЦИЯ
// ==========================================

// SendPulse API
define('SENDPULSE_ID', 'YOUR_SENDPULSE_ID'); // Замени на свой ID
define('SENDPULSE_SECRET', 'YOUR_SENDPULSE_SECRET'); // Замени на свой Secret
define('SENDPULSE_TOKEN_FILE', __DIR__ . '/../data/sendpulse_token.json');

// Google Sheets API
define('GOOGLE_CREDENTIALS_FILE', __DIR__ . '/../data/google_credentials.json');
define('GOOGLE_SPREADSHEET_ID', 'YOUR_SPREADSHEET_ID'); // ID таблицы Google Sheets
define('GOOGLE_SHEET_RAW', 'contacts_raw'); // Лист с сырыми данными
define('GOOGLE_SHEET_CLEAN', 'utm_clean'); // Лист с очищенными данными

// Telegram Bot
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_CHAT_ID', '-4800447687');

// ==========================================
// 3. ПУТИ К ФАЙЛАМ
// ==========================================

// Корневая директория проекта
define('ROOT_DIR', dirname(__DIR__));

// Директории
define('CONFIG_DIR', ROOT_DIR . '/config');
define('CORE_DIR', ROOT_DIR . '/core');
define('API_DIR', ROOT_DIR . '/api');
define('DATA_DIR', ROOT_DIR . '/data');
define('CACHE_DIR', ROOT_DIR . '/cache');
define('ASSETS_DIR', ROOT_DIR . '/assets');

// Файлы данных
define('UTM_RAW_FILE', DATA_DIR . '/utm_raw.json');
define('UTM_CLEAN_FILE', DATA_DIR . '/utm_clean.json');
define('CONTACTS_FILE', DATA_DIR . '/contacts.json');
define('SETTINGS_FILE', DATA_DIR . '/settings.json');
define('LOG_FILE', ROOT_DIR . '/log_actual.json');

// ==========================================
// 4. СИСТЕМНЫЕ НАСТРОЙКИ
// ==========================================

// Настройки обновления данных
define('SYNC_INTERVAL', 3600); // Интервал синхронизации в секундах (1 час)
define('CACHE_TTL', 1800); // Время жизни кэша (30 минут)

// Лимиты
define('MAX_CONTACTS_PER_REQUEST', 500); // Максимум контактов за один запрос
define('PAGINATION_LIMIT', 50); // Лимит на страницу

// Настройки очистки данных
$dataCleaningRules = [
    'lowercase' => true, // Приводить к нижнему регистру
    'trim' => true, // Удалять пробелы
    'replace_empty' => [ // Заменять пустые значения
        '(not set)',
        'undefined',
        'null',
        '',
        'N/A',
        'none',
        'unknown'
    ]
];

// Поля для экспорта
$exportFields = [
    'email',
    'phone',
    'created_at',
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_term',
    'utm_content',
    'list_name',
    'tags'
];

// ==========================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ==========================================

/**
 * Получить путь к файлу
 */
function getFilePath($type) {
    $paths = [
        'utm_raw' => UTM_RAW_FILE,
        'utm_clean' => UTM_CLEAN_FILE,
        'contacts' => CONTACTS_FILE,
        'settings' => SETTINGS_FILE,
        'log' => LOG_FILE
    ];

    return $paths[$type] ?? null;
}

/**
 * Загрузить JSON файл
 */
function loadJSON($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }

    $content = file_get_contents($filePath);
    return json_decode($content, true) ?? [];
}

/**
 * Сохранить данные в JSON
 */
function saveJSON($filePath, $data) {
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return file_put_contents(
        $filePath,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Получить настройки
 */
function getSettings() {
    static $settings = null;

    if ($settings === null) {
        $settings = loadJSON(SETTINGS_FILE);

        // Настройки по умолчанию
        if (empty($settings)) {
            $settings = [
                'last_sync' => null,
                'total_contacts' => 0,
                'auto_sync' => true,
                'notification_enabled' => true
            ];
            saveJSON(SETTINGS_FILE, $settings);
        }
    }

    return $settings;
}

/**
 * Обновить настройки
 */
function updateSettings($key, $value) {
    $settings = getSettings();
    $settings[$key] = $value;
    saveJSON(SETTINGS_FILE, $settings);
}

// === Git Server Sync ===
define('GITHUB_WEBHOOK_SECRET', getenv('GITHUB_WEBHOOK_SECRET') ?: '');
define('GITHUB_REPO_URL', getenv('GITHUB_REPO_URL') ?: '');
define('OPENAI_API_KEY', 'REPLACE_FROM_ENV');
define('GIT_BIN', '/usr/bin/git');

// === Git Sync - Telegram (Виртуальный сотрудник Александра Цемаха) ===
define('GIT_SYNC_TG_TOKEN', getenv('GIT_SYNC_TG_TOKEN') ?: '');
define('GIT_SYNC_TG_CHAT', '-1003713547131');
define('GIT_SYNC_TG_MENTION', '');
