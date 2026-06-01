<?php
/**
 * Знайти всі сделки з однаковим utm_term
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 Пошук дублікатів utm_term</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 12px; }
    th { background: #3b82f6; color: white; }
    .old { background: #fee; }
    .new { background: #efe; }
    .highlight { font-weight: bold; background: #fef3c7; }
</style>";

$today = date('Y-m-d');

try {
    $db = Database::getInstance();

    $suspiciousTerm = '120231525461530624_geo-ua_pl-all_audience-lal_all_20-45_20250726_instagram_reels';

    echo "<h2>🎯 Пошук всіх сделок з utm_term:</h2>";
    echo "<p><code>{$suspiciousTerm}</code></p>";

    $sql = "SELECT deal_id, contact_id, email, phone, full_name,
                   model, created_at, deal_updated_at,
                   DATE(created_at) as created_date,
                   utm_source, utm_medium, utm_campaign, utm_term, utm_content
            FROM crm_deals
            WHERE utm_term = :term
            ORDER BY created_at DESC";

    $deals = $db->fetchAll($sql, ['term' => $suspiciousTerm]);

    echo "<p>Знайдено сделок: <strong>" . count($deals) . "</strong></p>";

    echo "<table>";
    echo "<tr>
            <th>Deal ID</th>
            <th>Ім'я</th>
            <th>Телефон</th>
            <th>Model</th>
            <th class='highlight'>created_at</th>
            <th>created_date</th>
            <th>Сьогодні?</th>
          </tr>";

    foreach ($deals as $deal) {
        $isToday = ($deal['created_date'] === $today);
        $rowClass = $isToday ? "new" : "old";

        echo "<tr class='{$rowClass}'>";
        echo "<td>" . htmlspecialchars($deal['deal_id']) . "</td>";
        echo "<td>" . htmlspecialchars($deal['full_name'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['phone'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['model'] ?? '-') . "</td>";
        echo "<td class='highlight'>" . htmlspecialchars($deal['created_at']) . "</td>";
        echo "<td>" . htmlspecialchars($deal['created_date']) . "</td>";
        echo "<td>" . ($isToday ? "✅ ТАК" : "❌ НІ") . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Статистика
    $todayCount = 0;
    $oldCount = 0;

    foreach ($deals as $deal) {
        if ($deal['created_date'] === $today) {
            $todayCount++;
        } else {
            $oldCount++;
        }
    }

    echo "<h3>📊 Статистика:</h3>";
    echo "<table>";
    echo "<tr><td><strong>Сделок за СЬОГОДНІ</strong></td><td class='new'>{$todayCount}</td></tr>";
    echo "<tr><td><strong>Сделок СТАРИХ</strong></td><td class='old'>{$oldCount}</td></tr>";
    echo "<tr><td><strong>ВСЬОГО</strong></td><td>" . count($deals) . "</td></tr>";
    echo "</table>";

    // ВИСНОВОК
    echo "<h2>💡 ВИСНОВОК</h2>";

    if ($todayCount > 0) {
        echo "<div style='background: #fef3c7; padding: 20px; border-left: 5px solid orange;'>";
        echo "<p><strong>Є {$todayCount} НОВІ сделки за сьогодні з таким самим utm_term!</strong></p>";
        echo "<p>Це означає:</p>";
        echo "<ul>";
        echo "<li>✅ Ця utm_term <strong>ДІЙСНО використовується</strong> в поточних рекламних кампаніях</li>";
        echo "<li>⚠️ Це ДОВГА назва adset з Meta Ads (містить campaign_id + опис таргетингу)</li>";
        echo "<li>🤔 Можливо це правильно? Треба перевірити SendPulse налаштування</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div style='background: #fee; padding: 20px; border-left: 5px solid red;'>";
        echo "<p><strong>ТІЛЬКИ СТАРІ сделки ({$oldCount} шт)</strong></p>";
        echo "<p>За сьогодні немає сделок з таким utm_term - це АНОМАЛІЯ!</p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
