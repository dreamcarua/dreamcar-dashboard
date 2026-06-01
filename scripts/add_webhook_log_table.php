<?php
// === add_webhook_log_table.php ===
// НАЗНАЧЕНИЕ: Добавить таблицу webhook_log в базу данных
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер один раз

ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Добавление таблицы webhook_log</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>";

echo "<h1>🔧 Добавление таблицы webhook_log</h1>";

try {
    // Подключение к БД
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Подключение к базе данных</div>";

    $db = Database::getInstance();
    $pdo = $db->getPDO();

    echo "<p class='success'>✅ Успешно подключено к: " . DB_HOST . " / " . DB_NAME . "</p>";
    echo "</div>";

    // Проверить есть ли таблица
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Проверка существования таблицы</div>";

    $stmt = $pdo->query("SHOW TABLES LIKE 'webhook_log'");
    $exists = $stmt->fetch();

    if ($exists) {
        echo "<p class='info'>⚠️ Таблица <strong>webhook_log</strong> уже существует</p>";
        echo "<p class='info'>Пропускаем создание...</p>";
    } else {
        echo "<p class='info'>ℹ️ Таблица <strong>webhook_log</strong> не найдена</p>";
        echo "<p class='info'>Создаём новую таблицу...</p>";
    }

    echo "</div>";

    // Создать таблицу
    if (!$exists) {
        echo "<div class='step'>";
        echo "<div class='step-title'>Шаг 3: Создание таблицы webhook_log</div>";

        $sql = "CREATE TABLE IF NOT EXISTS webhook_log (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          webhook_type ENUM('crm', 'ads') NOT NULL,
          event_type VARCHAR(50),
          raw_data MEDIUMTEXT NOT NULL,
          processed_data JSON,
          deal_id VARCHAR(100),
          records_count INT DEFAULT 1,
          success BOOLEAN DEFAULT FALSE,
          error_message TEXT,
          processing_time DECIMAL(10,3),
          ip_address VARCHAR(50),
          user_agent VARCHAR(255),
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

          INDEX idx_webhook_type (webhook_type),
          INDEX idx_event_type (event_type),
          INDEX idx_deal_id (deal_id),
          INDEX idx_success (success),
          INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $pdo->exec($sql);

        echo "<p class='success'>✅ Таблица <strong>webhook_log</strong> успешно создана</p>";
        echo "<p class='info'>📊 Поля:</p>";
        echo "<ul>";
        echo "<li><strong>id</strong> - уникальный ID записи</li>";
        echo "<li><strong>webhook_type</strong> - тип webhook (crm или ads)</li>";
        echo "<li><strong>event_type</strong> - тип события (new, pay, fail)</li>";
        echo "<li><strong>raw_data</strong> - сырые JSON данные</li>";
        echo "<li><strong>processed_data</strong> - обработанные данные</li>";
        echo "<li><strong>deal_id</strong> - ID сделки (для CRM)</li>";
        echo "<li><strong>records_count</strong> - количество записей</li>";
        echo "<li><strong>success</strong> - успешность обработки</li>";
        echo "<li><strong>error_message</strong> - сообщение об ошибке</li>";
        echo "<li><strong>processing_time</strong> - время обработки (сек)</li>";
        echo "<li><strong>ip_address</strong> - IP адрес отправителя</li>";
        echo "<li><strong>user_agent</strong> - User-Agent</li>";
        echo "<li><strong>created_at</strong> - дата создания</li>";
        echo "</ul>";

        echo "</div>";

        $logger->success('Таблица webhook_log создана');
    }

    // Проверка результата
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 4: Проверка структуры таблицы</div>";

    $stmt = $pdo->query("DESCRIBE webhook_log");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p class='success'>✅ Таблица содержит " . count($columns) . " полей</p>";
    echo "<pre>";
    foreach ($columns as $col) {
        echo "{$col['Field']} ({$col['Type']})\n";
    }
    echo "</pre>";

    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='stats'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>База данных:</strong> " . DB_NAME . "</p>";
    echo "<p><strong>Статус:</strong> <span class='success'>✅ Готово</span></p>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<h2>✅ Таблица webhook_log добавлена!</h2>";
    echo "<p>Теперь все webhook запросы будут логироваться в эту таблицу.</p>";
    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><a href='../webhook_logs.php'>→ Перейти к просмотру логов webhook</a></p>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка добавления таблицы</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка добавления webhook_log', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
