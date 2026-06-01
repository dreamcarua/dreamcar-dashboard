<?php
// === cleanup_database.php ===
// НАЗНАЧЕНИЕ: Удаление устаревших таблиц analytics_by_* и analytics_cache
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер один раз
// ВАЖНО: Удаляет только таблицы предагрегации, данные CRM и рекламы сохраняются

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
    <title>Очистка базы данных</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .warning { color: #f59e0b; font-weight: bold; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>";

echo "<h1>🗑️ Очистка базы данных от устаревших таблиц</h1>";

try {
    // Подключение к БД
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Подключение к базе данных</div>";

    $db = Database::getInstance();
    $pdo = $db->getPDO();

    echo "<p class='success'>✅ Успешно подключено к: " . DB_HOST . " / " . DB_NAME . "</p>";
    echo "</div>";

    // Список таблиц для удаления
    $tablesToDrop = [
        'analytics_by_source',
        'analytics_by_medium',
        'analytics_by_campaign',
        'analytics_by_term',
        'analytics_by_content',
        'analytics_combinations',
        'analytics_cache'
    ];

    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Проверка существующих таблиц</div>";

    // Получить список всех таблиц в БД
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<p class='info'>Всего таблиц в БД: " . count($existingTables) . "</p>";

    // Определить какие таблицы нужно удалить
    $foundToDelete = [];
    foreach ($tablesToDrop as $table) {
        if (in_array($table, $existingTables)) {
            $foundToDelete[] = $table;
            echo "<p class='warning'>⚠️ Таблица <strong>$table</strong> будет удалена</p>";
        } else {
            echo "<p class='info'>ℹ️ Таблица <strong>$table</strong> не найдена (уже удалена)</p>";
        }
    }

    echo "</div>";

    if (empty($foundToDelete)) {
        echo "<div class='success'>";
        echo "<h2>✅ База данных уже актуальна!</h2>";
        echo "<p>Все устаревшие таблицы уже удалены. Ничего не нужно делать.</p>";
        echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
        echo "</div>";
        echo "</body></html>";
        exit;
    }

    // Удаление таблиц
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Удаление устаревших таблиц</div>";

    $deletedCount = 0;
    $errors = [];

    foreach ($foundToDelete as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "<p class='success'>✅ Удалена таблица: <strong>$table</strong></p>";
            $deletedCount++;

            $logger->info("Таблица удалена", ['table' => $table]);

        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            echo "<p class='error'>❌ Ошибка удаления таблицы <strong>$table</strong>: " . htmlspecialchars($errorMsg) . "</p>";
            $errors[] = $table . ': ' . $errorMsg;
        }
    }

    echo "<p class='success'>✅ Удалено таблиц: $deletedCount</p>";

    if (!empty($errors)) {
        echo "<p class='error'>Ошибок: " . count($errors) . "</p>";
    }

    echo "</div>";

    // Проверка результата
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 4: Проверка результата</div>";

    $stmt = $pdo->query("SHOW TABLES");
    $remainingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<p class='success'>✅ Осталось таблиц в БД: " . count($remainingTables) . "</p>";
    echo "<pre>" . implode("\n", $remainingTables) . "</pre>";
    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='stats'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Удалено таблиц:</strong> $deletedCount</p>";
    echo "<p><strong>Ошибок:</strong> " . count($errors) . "</p>";
    echo "<p><strong>База данных:</strong> " . DB_NAME . "</p>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<h2>✅ Очистка базы данных завершена!</h2>";
    echo "<p>База данных теперь содержит только актуальные таблицы:</p>";
    echo "<ul>";
    echo "<li><strong>crm_deals</strong> - сделки из CRM</li>";
    echo "<li><strong>ads_data</strong> - данные рекламы</li>";
    echo "<li><strong>utm_mapping</strong> - соответствия UTM меток</li>";
    echo "<li><strong>import_log</strong> - лог импортов</li>";
    echo "</ul>";
    echo "<p>Метрики теперь рассчитываются на лету через модель Analytics.</p>";
    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "</div>";

    $logger->success('Очистка БД завершена', [
        'deleted' => $deletedCount,
        'errors' => count($errors),
        'duration' => $duration
    ]);

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка очистки</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка очистки БД', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
