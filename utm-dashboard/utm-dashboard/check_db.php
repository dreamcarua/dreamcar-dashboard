<?php
// Временный скрипт для проверки структуры таблицы ads_data
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);

    echo "<h2>✅ Подключение к БД успешно</h2>";

    // Структура таблицы
    echo "<h3>📋 Структура таблицы ads_data:</h3>";
    $stmt = $pdo->query("DESCRIBE ads_data");
    $columns = $stmt->fetchAll();

    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td><strong>{$col['Field']}</strong></td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "<td>{$col['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Количество записей
    echo "<h3>📊 Статистика:</h3>";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM ads_data");
    $total = $stmt->fetch()['total'];
    echo "<p>Всего записей: <strong>{$total}</strong></p>";

    // Ручные записи
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM ads_data WHERE publisher_platform = 'manual'");
    $manual = $stmt->fetch()['cnt'];
    echo "<p>Ручных записей (manual): <strong>{$manual}</strong></p>";

    // Пример данных
    echo "<h3>📝 Последние 5 записей:</h3>";
    $stmt = $pdo->query("SELECT id, date_start, publisher_platform, utm_source, utm_medium, spend FROM ads_data ORDER BY id DESC LIMIT 5");
    $rows = $stmt->fetchAll();

    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Date</th><th>Platform</th><th>Source</th><th>Medium</th><th>Spend</th></tr>";
    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['date_start']}</td>";
        echo "<td>{$row['publisher_platform']}</td>";
        echo "<td>{$row['utm_source']}</td>";
        echo "<td>{$row['utm_medium']}</td>";
        echo "<td>{$row['spend']}</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (PDOException $e) {
    echo "<h2>❌ Ошибка подключения:</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
