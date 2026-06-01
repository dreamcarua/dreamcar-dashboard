<?php
/**
 * Debug: Що передається в getCombinations()
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';
require_once 'core/models/Analytics.php';
require_once 'core/models/UtmCrmAdsMapping.php';

echo "<h1>🐛 Debug: Combinations Filter</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
pre { background: #f5f5f5; padding: 15px; border-left: 4px solid #3b82f6; }
.success { background: #efe; padding: 10px; }
.error { background: #fee; padding: 10px; }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));

// Симуляція логіки з api/test.php
$filters = [
    'date_from' => $yesterday . ' 00:00:00',
    'date_to' => $yesterday . ' 23:59:59',
    'utm_term' => 'oborotfb'
];

echo "<h2>1. Початкові фільтри:</h2>";
echo "<pre>" . print_r($filters, true) . "</pre>";

// Розширення як в api/test.php
$filtersForCombinations = $filters;
if (!empty($filters['utm_term'])) {
    $mappings = UtmCrmAdsMapping::getMappingsByField('utm_term');

    echo "<h2>2. Mappings з БД:</h2>";
    echo "<pre>" . print_r($mappings, true) . "</pre>";

    $searchTerm = strtolower(trim($filters['utm_term']));
    $relatedAdsValues = [];

    foreach ($mappings as $map) {
        $crmValue = strtolower(trim($map['crm_value']));
        echo "<p>Перевірка: '<strong>$crmValue</strong>' містить '<strong>$searchTerm</strong>'? ";
        if (strpos($crmValue, $searchTerm) !== false) {
            echo "<span style='color: green;'>✅ ТАК</span></p>";
            $relatedAdsValues[] = strtolower(trim($map['ads_value']));
        } else {
            echo "<span style='color: red;'>❌ НІ</span></p>";
        }
    }

    echo "<h2>3. Знайдені пов'язані ADS значення:</h2>";
    echo "<pre>" . print_r($relatedAdsValues, true) . "</pre>";

    if (!empty($relatedAdsValues)) {
        unset($filtersForCombinations['utm_term']);
        $filtersForCombinations['utm_term_include'] = array_merge([$searchTerm], $relatedAdsValues);
    }
}

echo "<h2>4. Фінальні фільтри для getCombinations():</h2>";
echo "<pre>" . print_r($filtersForCombinations, true) . "</pre>";

// Виклик getCombinations()
echo "<h2>5. Результат Analytics::getCombinations():</h2>";

try {
    $result = Analytics::getCombinations($filtersForCombinations);

    echo "<div class='success'><p>✅ Отримано комбінацій: <strong>" . count($result) . "</strong></p></div>";

    // Показати campaigns
    $campaigns = array_filter($result, function($item) {
        return strpos($item['type'] ?? '', 'campaign') !== false;
    });

    echo "<h3>Campaigns:</h3>";
    echo "<pre>" . print_r(array_slice($campaigns, 0, 5), true) . "</pre>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<p>❌ Помилка: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>
