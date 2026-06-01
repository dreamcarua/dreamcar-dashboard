<?php
// === fix_ads_utm_source.php ===
// НАЗНАЧЕНИЕ: Исправить utm_source в ads_data (было platform_position, должно быть publisher_platform)
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер ОДИН РАЗ

ini_set('max_execution_time', 600);
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
    <title>Исправление utm_source в ads_data</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .warning { color: #f59e0b; font-weight: bold; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        pre { background: #1f2937; color: #10b981; padding: 15px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>";

echo "<h1>🔧 Исправление utm_source в ads_data</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Шаг 1: Показать текущее состояние
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Текущее состояние</div>";

    $sql = "SELECT COUNT(*) as total FROM ads_data";
    $total = $pdo->query($sql)->fetchColumn();

    echo "<p class='info'>Всего записей в ads_data: <strong>$total</strong></p>";

    // Показать примеры НЕПРАВИЛЬНЫХ utm_source
    $sql = "SELECT DISTINCT utm_source, publisher_platform, COUNT(*) as cnt
    FROM ads_data
    GROUP BY utm_source, publisher_platform
    ORDER BY cnt DESC
    LIMIT 10";

    $examples = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo "<p class='warning'>Примеры НЕПРАВИЛЬНЫХ данных (utm_source = platform_position вместо publisher_platform):</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>utm_source (НЕПРАВИЛЬНО)</th><th>publisher_platform (ПРАВИЛЬНО)</th><th>Записей</th></tr>";
    foreach ($examples as $row) {
        echo "<tr>";
        echo "<td>{$row['utm_source']}</td>";
        echo "<td class='success'>{$row['publisher_platform']}</td>";
        echo "<td>{$row['cnt']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "</div>";

    // Шаг 2: Исправить utm_source
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Исправление utm_source</div>";

    echo "<p class='info'>Обновляю utm_source = publisher_platform для всех записей...</p>";

    $sql = "UPDATE ads_data SET utm_source = publisher_platform";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $updated = $stmt->rowCount();

    echo "<p class='success'>✅ Обновлено записей: <strong>$updated</strong></p>";

    echo "</div>";

    // Шаг 3: Проверить результат
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Проверка результата</div>";

    $sql = "SELECT DISTINCT utm_source, COUNT(*) as cnt
    FROM ads_data
    GROUP BY utm_source
    ORDER BY cnt DESC";

    $results = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo "<p class='success'>✅ Теперь utm_source содержит правильные значения:</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>utm_source</th><th>Записей</th></tr>";
    foreach ($results as $row) {
        echo "<tr><td class='success'><strong>{$row['utm_source']}</strong></td><td>{$row['cnt']}</td></tr>";
    }
    echo "</table>";

    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='step'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Обновлено записей:</strong> <span class='success'>$updated</span></p>";
    echo "<p class='success' style='font-size: 1.2em;'>✅ Теперь расходы будут правильно привязываться к источникам!</p>";
    echo "</div>";

    $logger->success("Исправлено utm_source в ads_data: $updated записей");

    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка исправления</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка исправления utm_source', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
