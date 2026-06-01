<?php
require_once 'config/app_config.php';
require_once 'core/models/Analytics.php';

echo "<h1>🧪 Тест Analytics::getByCampaign()</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } pre { background: #f5f5f5; padding: 15px; }</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));

$filters = [
    'date_from' => $yesterday . ' 00:00:00',
    'date_to' => $yesterday . ' 23:59:59',
    'utm_term' => 'oborotfb'
];

echo "<h2>Фільтри:</h2>";
echo "<pre>" . print_r($filters, true) . "</pre>";

echo "<h2>Виклик Analytics::getByCampaign(\$filters):</h2>";

try {
    $result = Analytics::getByCampaign($filters);

    echo "<p>✅ Отримано: <strong>" . count($result) . "</strong> campaigns</p>";

    if (empty($result)) {
        echo "<p style='background: #fee; padding: 10px;'>❌ ПУСТО! Проблема в getByFieldWithMapping()</p>";
    } else {
        echo "<h3>Перші 5:</h3>";
        echo "<pre>" . print_r(array_slice($result, 0, 5), true) . "</pre>";

        // Знайти цільову campaign
        foreach ($result as $row) {
            if (strpos($row['utm_campaign'], 'ob|atrib1d|audiq7|adv+|video|15.12') !== false) {
                echo "<h3>Цільова campaign знайдена:</h3>";
                echo "<pre>" . print_r($row, true) . "</pre>";

                if ($row['spend'] > 0) {
                    echo "<p style='background: #efe; padding: 10px;'>✅ spend = {$row['spend']} UAH</p>";
                } else {
                    echo "<p style='background: #fee; padding: 10px;'>❌ spend = 0 UAH</p>";
                }
                break;
            }
        }
    }

} catch (Exception $e) {
    echo "<p style='background: #fee; padding: 10px;'>❌ Помилка: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
