<?php
/**
 * Тестування фільтру utm_term з mappings
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';
require_once 'core/models/Analytics.php';
require_once 'core/models/UtmCrmAdsMapping.php';

echo "<h1>🧪 Тест фільтру utm_term з mappings</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
pre { background: white; padding: 15px; border-left: 4px solid #3b82f6; overflow-x: auto; }
.success { background: #efe; padding: 10px; margin: 10px 0; border-left: 4px solid #10b981; }
.error { background: #fee; padding: 10px; margin: 10px 0; border-left: 4px solid #ef4444; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #3b82f6; color: white; }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));

// ====================================
// КРОК 1: Перевірити mappings
// ====================================

echo "<h2>📋 Крок 1: Mappings в БД</h2>";

$mappings = UtmCrmAdsMapping::getMappingsByField('utm_term');

echo "<table>";
echo "<tr><th>CRM value</th><th>ADS value</th><th>Merged name</th></tr>";
foreach ($mappings as $map) {
    echo "<tr>";
    echo "<td><strong>{$map['crm_value']}</strong></td>";
    echo "<td><code>{$map['ads_value']}</code></td>";
    echo "<td>{$map['merged_name']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<p>Всього mappings: <strong>" . count($mappings) . "</strong></p>";

// ====================================
// КРОК 2: Тест фільтру oborot
// ====================================

echo "<h2>🔍 Крок 2: Тест фільтру utm_term=oborot</h2>";

$filters = [
    'date_from' => $yesterday . ' 00:00:00',
    'date_to' => $yesterday . ' 23:59:59',
    'utm_term' => 'oborot'
];

echo "<p>Фільтр: <code>utm_term=oborot</code></p>";

// Викликати Analytics::getByTerm()
echo "<h3>2.1. Виклик Analytics::getByTerm() з фільтром</h3>";

try {
    $result = Analytics::getByTerm($filters);

    echo "<div class='success'>";
    echo "<p>✅ Отримано результатів: <strong>" . count($result) . "</strong></p>";
    echo "</div>";

    echo "<table>";
    echo "<tr><th>UTM Term</th><th>Source</th><th>Лiди</th><th>Оплачено</th><th>Заробили</th><th>Витрати</th><th>Прибуток</th><th>ROI</th></tr>";

    foreach ($result as $row) {
        $roi = number_format($row['roi'], 1);
        $roiClass = $row['roi'] > 0 ? 'style="color: green; font-weight: bold;"' : 'style="color: red;"';

        echo "<tr>";
        echo "<td><strong>{$row['utm_term']}</strong></td>";
        echo "<td>{$row['source']}</td>";
        echo "<td>{$row['leads']}</td>";
        echo "<td>{$row['paid_count']}</td>";
        echo "<td>" . number_format($row['paid_amount'], 0) . " UAH</td>";
        echo "<td>" . number_format($row['spend'], 0) . " UAH</td>";
        echo "<td>" . number_format($row['profit'], 0) . " UAH</td>";
        echo "<td $roiClass>+{$roi}%</td>";
        echo "</tr>";
    }

    echo "</table>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<p>❌ Помилка: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

// ====================================
// КРОК 3: Тест getTotalStats()
// ====================================

echo "<h2>📊 Крок 3: Тест Analytics::getTotalStats() з фільтром</h2>";

try {
    $totalStats = Analytics::getTotalStats($filters);

    echo "<table>";
    echo "<tr><th>Метрика</th><th>Значення</th></tr>";
    echo "<tr><td>Всього лідів</td><td><strong>{$totalStats['total_leads']}</strong></td></tr>";
    echo "<tr><td>Оплачено</td><td><strong>{$totalStats['paid_count']}</strong></td></tr>";
    echo "<tr><td>Заробили</td><td><strong>" . number_format($totalStats['paid_amount'], 0) . " UAH</strong></td></tr>";
    echo "<tr style='background: " . ($totalStats['total_spend'] > 0 ? '#efe' : '#fee') . "'>";
    echo "<td><strong>Витрати</strong></td>";
    echo "<td><strong>" . number_format($totalStats['total_spend'], 0) . " UAH</strong></td>";
    echo "</tr>";
    echo "<tr><td>Прибуток</td><td><strong>" . number_format($totalStats['total_profit'], 0) . " UAH</strong></td></tr>";
    echo "<tr><td>ROI</td><td><strong>+" . number_format($totalStats['avg_roi'], 1) . "%</strong></td></tr>";
    echo "<tr><td>ROAS</td><td><strong>" . number_format($totalStats['avg_roas'], 2) . "</strong></td></tr>";
    echo "</table>";

    if ($totalStats['total_spend'] == 0) {
        echo "<div class='error'><p>❌ ПРОБЛЕМА: Витрати = 0! Mappings не спрацювали!</p></div>";
    } else {
        echo "<div class='success'><p>✅ Витрати знайдені! Mappings працюють!</p></div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<p>❌ Помилка: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='index.php?date_range=yesterday&utm_term=oborot#term' style='padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 5px;'>→ Перейти до dashboard з фільтром</a></p>";
?>
