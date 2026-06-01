<?php
// === check_ads_data.php ===
// НАЗНАЧЕНИЕ: Проверить есть ли данные о расходах в ads_data
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';

$db = Database::getInstance();

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Проверка рекламных данных</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .info { color: #6b7280; }
        .warning { color: #f59e0b; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        pre { background: #f3f4f6; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>";

echo "<h1>🔍 Проверка рекламных данных (ads_data)</h1>";

// 1. Общая статистика
echo "<h2>1️⃣ Общая статистика</h2>";

$sql = "SELECT COUNT(*) as total FROM ads_data";
$total = $db->fetchOne($sql)['total'];

echo "<p class='info'><strong>Всего записей в ads_data:</strong> $total</p>";

if ($total === 0) {
    echo "<p class='error'>❌ Таблица ads_data пустая! Нужно загрузить рекламные данные.</p>";
    echo "<p><a href='../upload_ads_mysql.php'>📤 Загрузить рекламные данные</a></p>";
} else {
    echo "<p class='success'>✅ Данные есть</p>";

    // 2. Диапазон дат
    echo "<h2>2️⃣ Диапазон дат</h2>";
    $sql = "SELECT MIN(date_start) as min_date, MAX(date_stop) as max_date FROM ads_data";
    $dates = $db->fetchOne($sql);
    echo "<p><strong>Минимальная дата:</strong> {$dates['min_date']}</p>";
    echo "<p><strong>Максимальная дата:</strong> {$dates['max_date']}</p>";

    // 3. Проверить вчерашний день
    echo "<h2>3️⃣ Данные за вчера (2025-11-24)</h2>";
    $yesterday = '2025-11-24';
    $sql = "SELECT
        utm_source,
        COUNT(*) as records,
        SUM(spend) as total_spend,
        SUM(clicks) as total_clicks
    FROM ads_data
    WHERE date_start = :date
    GROUP BY utm_source";

    $yesterdayData = $db->fetchAll($sql, ['date' => $yesterday]);

    if (empty($yesterdayData)) {
        echo "<p class='warning'>⚠️ Нет данных за $yesterday</p>";
    } else {
        echo "<table>";
        echo "<tr><th>UTM Source</th><th>Записей</th><th>Расход</th><th>Клики</th></tr>";
        foreach ($yesterdayData as $row) {
            echo "<tr>";
            echo "<td>{$row['utm_source']}</td>";
            echo "<td>{$row['records']}</td>";
            echo "<td>" . number_format($row['total_spend'], 2) . " UAH</td>";
            echo "<td>{$row['total_clicks']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // 4. Уникальные utm_source в ads_data
    echo "<h2>4️⃣ Уникальные utm_source в ads_data</h2>";
    $sql = "SELECT DISTINCT utm_source, COUNT(*) as cnt
    FROM ads_data
    GROUP BY utm_source
    ORDER BY cnt DESC
    LIMIT 20";

    $sources = $db->fetchAll($sql);

    echo "<table>";
    echo "<tr><th>UTM Source</th><th>Записей</th></tr>";
    foreach ($sources as $row) {
        echo "<tr><td>{$row['utm_source']}</td><td>{$row['cnt']}</td></tr>";
    }
    echo "</table>";

    // 5. Уникальные utm_source в crm_deals
    echo "<h2>5️⃣ Уникальные utm_source в crm_deals (за вчера)</h2>";
    $sql = "SELECT DISTINCT utm_source, COUNT(*) as cnt
    FROM crm_deals
    WHERE DATE(created_at) = :date
    GROUP BY utm_source
    ORDER BY cnt DESC";

    $crmSources = $db->fetchAll($sql, ['date' => $yesterday]);

    echo "<table>";
    echo "<tr><th>UTM Source</th><th>Лидов</th></tr>";
    foreach ($crmSources as $row) {
        echo "<tr><td>{$row['utm_source']}</td><td>{$row['cnt']}</td></tr>";
    }
    echo "</table>";

    // 6. Проверить совпадения
    echo "<h2>6️⃣ Анализ совпадений</h2>";

    $adsSourcesList = array_column($sources, 'utm_source');
    $crmSourcesList = array_column($crmSources, 'utm_source');

    $inBoth = array_intersect($adsSourcesList, $crmSourcesList);
    $onlyInAds = array_diff($adsSourcesList, $crmSourcesList);
    $onlyInCrm = array_diff($crmSourcesList, $adsSourcesList);

    echo "<p class='success'><strong>Совпадают (в обеих таблицах):</strong> " . implode(', ', $inBoth) . "</p>";
    echo "<p class='warning'><strong>Только в ads_data:</strong> " . implode(', ', $onlyInAds) . "</p>";
    echo "<p class='warning'><strong>Только в crm_deals:</strong> " . implode(', ', $onlyInCrm) . "</p>";
}

echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
echo "</body></html>";
