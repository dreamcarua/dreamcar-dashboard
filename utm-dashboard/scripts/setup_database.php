<?php
// === setup_database.php ===
// НАЗНАЧЕНИЕ: Автоматическая установка базы данных
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер или CLI
// ВАЖНО: Создает все таблицы и индексы

// Увеличить лимиты
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
    <title>Установка базы данных</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        pre { background: #1f2937; color: #f3f4f6; padding: 15px; border-radius: 8px; overflow-x: auto; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>";

echo "<h1>🗄️ Установка базы данных UTM Dashboard</h1>";

try {
    // Шаг 1: Подключение к БД
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Подключение к базе данных</div>";

    $db = Database::getInstance();
    $pdo = $db->getPDO();

    echo "<p class='success'>✅ Успешно подключено к: " . DB_HOST . " / " . DB_NAME . "</p>";
    echo "</div>";

    // Шаг 2: Чтение SQL схемы
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Чтение SQL схемы</div>";

    $schemaFile = __DIR__ . '/../sql/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Файл схемы не найден: $schemaFile");
    }

    $sql = file_get_contents($schemaFile);
    echo "<p class='success'>✅ Схема загружена (" . number_format(strlen($sql)) . " байт)</p>";
    echo "</div>";

    // Шаг 3: Выполнение SQL команд
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Создание таблиц</div>";

    // Разделить на отдельные команды
    // Удалить комментарии
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*$/m', '', $sql);

    // Разделить по точке с запятой, но только если это конец команды
    $statements = preg_split('/;\s*$/m', $sql, -1, PREG_SPLIT_NO_EMPTY);

    $executedCount = 0;
    $skippedCount = 0;
    $tables = [];

    foreach ($statements as $statement) {
        $statement = trim($statement);

        // Пропустить пустые
        if (empty($statement)) {
            continue;
        }

        try {
            $pdo->exec($statement);
            $executedCount++;

            // Определить название таблицы
            if (preg_match('/CREATE TABLE.*?IF NOT EXISTS\s+`?(\w+)`?/i', $statement, $matches)) {
                $tables[] = $matches[1];
                echo "<p class='info'>✓ Таблица: {$matches[1]}</p>";
            } elseif (preg_match('/INSERT INTO\s+`?(\w+)`?/i', $statement, $matches)) {
                echo "<p class='info'>✓ Данные в: {$matches[1]}</p>";
            }

        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();

            // Игнорировать ошибки "таблица уже существует"
            if (strpos($errorMsg, 'already exists') !== false ||
                strpos($errorMsg, 'Duplicate entry') !== false) {
                echo "<p class='info'>⚠️ Уже существует (пропущено)</p>";
                $skippedCount++;
            } else {
                echo "<p class='error'>❌ Ошибка: " . htmlspecialchars($errorMsg) . "</p>";
                echo "<pre style='font-size:10px;'>" . htmlspecialchars(substr($statement, 0, 200)) . "...</pre>";
                $skippedCount++;
                // Не бросаем исключение, продолжаем
            }
        }
    }

    echo "<p class='success'>✅ Выполнено команд: $executedCount</p>";
    echo "<p class='info'>Пропущено: $skippedCount</p>";
    echo "</div>";

    // Шаг 4: Проверка созданных таблиц
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 4: Проверка созданных таблиц</div>";

    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<p>Найдено таблиц в базе: <strong>" . count($existingTables) . "</strong></p>";
    echo "<pre>" . implode("\n", $existingTables) . "</pre>";
    echo "</div>";

    // Шаг 5: Статистика таблиц
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 5: Статистика таблиц</div>";

    echo "<table border='1' cellpadding='10' cellspacing='0' style='width:100%; border-collapse:collapse;'>";
    echo "<tr style='background:#e5e7eb;'><th>Таблица</th><th>Записей</th><th>Размер</th></tr>";

    foreach ($existingTables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $count = $stmt->fetchColumn();

        $stmt = $pdo->query("
            SELECT
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = '" . DB_NAME . "' AND table_name = '$table'
        ");
        $size = $stmt->fetchColumn();

        echo "<tr>";
        echo "<td><strong>$table</strong></td>";
        echo "<td>" . number_format($count) . "</td>";
        echo "<td>{$size} MB</td>";
        echo "</tr>";
    }

    echo "</table>";
    echo "</div>";

    // Шаг 6: Проверка соединения
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 6: Проверка работоспособности</div>";

    // Тестовый запрос
    $testQuery = "SELECT COUNT(*) as total FROM crm_deals";
    $stmt = $pdo->query($testQuery);
    $result = $stmt->fetch();

    echo "<p class='success'>✅ База данных работает корректно</p>";
    echo "<p class='info'>Тестовый запрос: $testQuery</p>";
    echo "<p class='info'>Результат: {$result['total']} записей</p>";
    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='stats'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Таблиц создано:</strong> " . count($tables) . "</p>";
    echo "<p><strong>SQL команд выполнено:</strong> $executedCount</p>";
    echo "<p><strong>База данных:</strong> " . DB_NAME . "</p>";
    echo "<p><strong>Хост:</strong> " . DB_HOST . "</p>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<h2>✅ Установка завершена успешно!</h2>";
    echo "<p>База данных готова к использованию.</p>";
    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "</div>";

    $logger->success('База данных установлена', [
        'tables' => count($existingTables),
        'duration' => $duration
    ]);

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка установки</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";

    $logger->error('Ошибка установки БД', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
