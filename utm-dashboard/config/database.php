<?php
// === database.php ===
// Конфигурация подключения к базе данных MySQL 8.4
// НАЗНАЧЕНИЕ: Хранение параметров подключения к БД

// Параметры подключения к MySQL (выделенный MySQL сервис)
define('DB_HOST', getenv('DB_HOST') ?: 'fincheck.mysql.network');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 10145));
define('DB_NAME', getenv('DB_NAME') ?: 'dreamcar_utm');
define('DB_USER', getenv('DB_USER') ?: 'dreamcar_utm');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

// Дополнительные параметры PDO
// Убрали PDO::MYSQL_ATTR_INIT_COMMAND (deprecated в PHP 8.5+)
// Charset устанавливается через DSN строку
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
]);
