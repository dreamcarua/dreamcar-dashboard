<?php
/**
 * Діагностика: Чи campaign має utm_term в ads_data?
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 Діагностика: Campaign + UTM Term</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #3b82f6; color: white; }
.error { background: #fee; }
.success { background: #efe; }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));
$db = Database::getInstance();

// ====================================
// КРОК 1: Перевірити ads_data для campaign
// ====================================

echo "<h2>📊 Крок 1: Дані ads_data для campaign</h2>";

$sql = "SELECT
    utm_campaign,
    utm_term,
    SUM(spend) as total_spend,
    COUNT(*) as records
FROM ads_data
WHERE DATE(date_start) = :yesterday
  AND LOWER(utm_campaign) LIKE :campaign
GROUP BY utm_campaign, utm_term
ORDER BY total_spend DESC";

$campaign = '%ob|atrib1d|audiq7|adv+|video|15.12%';

$rows = $db->fetchAll($sql, [
    'yesterday' => $yesterday,
    'campaign' => $campaign
]);

echo "<p>Дата: <strong>$yesterday</strong></p>";
echo "<p>Campaign фільтр: <code>$campaign</code></p>";

if (empty($rows)) {
    echo "<p class='error'>❌ Немає записів в ads_data для цієї кампанії!</p>";
} else {
    echo "<table>";
    echo "<tr><th>Campaign</th><th>UTM Term</th><th>Витрати</th><th>Записів</th></tr>";

    foreach ($rows as $row) {
        $termClass = empty($row['utm_term']) ? 'error' : 'success';

        echo "<tr class='$termClass'>";
        echo "<td>" . htmlspecialchars($row['utm_campaign']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($row['utm_term'] ?: '(ПУСТО!)') . "</strong></td>";
        echo "<td>" . number_format($row['total_spend'], 0) . " UAH</td>";
        echo "<td>{$row['records']}</td>";
        echo "</tr>";
    }

    echo "</table>";
}

// ====================================
// КРОК 2: Перевірити crm_deals для campaign
// ====================================

echo "<h2>📊 Крок 2: Дані crm_deals для campaign</h2>";

$sql = "SELECT
    utm_campaign,
    utm_term,
    COUNT(*) as leads,
    SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid,
    SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as revenue
FROM crm_deals
WHERE DATE(created_at) = :yesterday
  AND LOWER(utm_campaign) LIKE :campaign
GROUP BY utm_campaign, utm_term
ORDER BY revenue DESC";

$rows = $db->fetchAll($sql, [
    'yesterday' => $yesterday,
    'campaign' => $campaign
]);

if (empty($rows)) {
    echo "<p class='error'>❌ Немає лідів для цієї кампанії!</p>";
} else {
    echo "<table>";
    echo "<tr><th>Campaign</th><th>UTM Term</th><th>Ліди</th><th>Оплачено</th><th>Заробили</th></tr>";

    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['utm_campaign']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($row['utm_term']) . "</strong></td>";
        echo "<td>{$row['leads']}</td>";
        echo "<td>{$row['paid']}</td>";
        echo "<td>" . number_format($row['revenue'], 0) . " UAH</td>";
        echo "</tr>";
    }

    echo "</table>";
}

// ====================================
// ВИСНОВОК
// ====================================

echo "<h2>💡 Висновок</h2>";
echo "<p><strong>Проблема:</strong></p>";
echo "<ul>";
echo "<li>Якщо в ads_data utm_term ПУСТИЙ → витрати не знайдуться при фільтрі utm_term=oborotfb</li>";
echo "<li>Якщо в crm_deals є utm_term=oborotfb, але в ads_data немає → витрати 0</li>";
echo "</ul>";

echo "<p><strong>Рішення:</strong></p>";
echo "<ul>";
echo "<li>Додати utm_term в ads_data при імпорті з Facebook (в ImportAds.php)</li>";
echo "<li>АБО зробити mapping по utm_campaign (якщо campaign унікальна для таргетолога)</li>";
echo "</ul>";
?>
