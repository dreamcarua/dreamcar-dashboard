<?php
/**
 * Перевірка дат створення для довгих utm_term
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>📅 Перевірка дат для довгих utm_term</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 12px; }
    th { background: #3b82f6; color: white; }
    .today { background: #d1fae5; font-weight: bold; }
    .old { background: #fee; font-weight: bold; }
    .error { color: red; font-weight: bold; }
</style>";

$today = date('Y-m-d');

try {
    $db = Database::getInstance();

    echo "<p><strong>Сьогоднішня дата:</strong> {$today}</p>";

    // Довгі utm_term які показуються в дашборді
    $longTerms = [
        '120231525461530624_geo-ua_pl-all_audience-lal_all_20-45_20250726_instagram_reels',
        '120231875499050624_geo-ua_pl-all_audience-broad_all_18+_creo-post_20250730_instagram_feed',
        '120231527577930624_geo-ua_pl-all_audience-lal_all_18+_creo-post_20250726_instagram_feed',
        '120233142429400624_geo-ua_pl-ig_audience-interests_all_20-50_creo-video_test-cc-75_20250821_instagram_reels',
        '120233104636430624_geo-ua_pl-ig_audience-interests_all_20-50_creo-video_test-cc-75_20250821_instagram_feed',
        '120231955637660624_geo-ua_pl-all_audience-interest-car_male_20-50_20250731_instagram_feed'
    ];

    echo "<h2>🔍 Перевірка кожної мітки:</h2>";

    foreach ($longTerms as $i => $term) {
        echo "<h3>" . ($i + 1) . ". {$term}</h3>";

        // Знайти ВСІ сделки з цим utm_term ЗА СЬОГОДНІ
        $sql = "SELECT deal_id, contact_id, full_name, phone, model,
                       created_at,
                       DATE(created_at) as created_date,
                       TIME(created_at) as created_time
                FROM crm_deals
                WHERE utm_term = :term
                  AND DATE(created_at) = :today
                ORDER BY created_at DESC";

        $todayDeals = $db->fetchAll($sql, [
            'term' => $term,
            'today' => $today
        ]);

        // Знайти ВСІ сделки з цим utm_term (незалежно від дати)
        $sql = "SELECT COUNT(*) as total,
                       COUNT(CASE WHEN DATE(created_at) = :today1 THEN 1 END) as today_count,
                       COUNT(CASE WHEN DATE(created_at) != :today2 THEN 1 END) as old_count,
                       MIN(created_at) as first_date,
                       MAX(created_at) as last_date
                FROM crm_deals
                WHERE utm_term = :term";

        $stats = $db->fetchOne($sql, [
            'term' => $term,
            'today1' => $today,
            'today2' => $today
        ]);

        echo "<table>";
        echo "<tr><th>Статистика</th><th>Значення</th></tr>";
        echo "<tr><td>Всього сделок з цим utm_term</td><td><strong>{$stats['total']}</strong></td></tr>";
        echo "<tr class='today'><td>За СЬОГОДНІ ({$today})</td><td><strong>{$stats['today_count']}</strong></td></tr>";
        echo "<tr class='old'><td>СТАРИХ (не сьогодні)</td><td><strong>{$stats['old_count']}</strong></td></tr>";
        echo "<tr><td>Перша сделка</td><td>{$stats['first_date']}</td></tr>";
        echo "<tr><td>Остання сделка</td><td>{$stats['last_date']}</td></tr>";
        echo "</table>";

        if ($stats['today_count'] > 0) {
            echo "<h4>✅ Сделки ЗА СЬОГОДНІ ({$stats['today_count']} шт):</h4>";
            echo "<table>";
            echo "<tr><th>Deal ID</th><th>Ім'я</th><th>Телефон</th><th>Model</th><th>Час створення</th></tr>";

            foreach ($todayDeals as $deal) {
                echo "<tr class='today'>";
                echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId={$deal['deal_id']}' target='_blank'>{$deal['deal_id']}</a></td>";
                echo "<td>" . htmlspecialchars($deal['full_name'] ?? '-') . "</td>";
                echo "<td>" . htmlspecialchars($deal['phone'] ?? '-') . "</td>";
                echo "<td>" . htmlspecialchars($deal['model']) . "</td>";
                echo "<td><strong>{$deal['created_date']} {$deal['created_time']}</strong></td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ За сьогодні НЕМАЄ сделок з цим utm_term!</p>";
            echo "<p><strong>ЦЕ АНОМАЛІЯ!</strong> Якщо в дашборді показується - це БАҐ!</p>";
        }

        echo "<hr>";
    }

    // ==========================================
    // ТЕСТ SQL як в CrmDeal.php
    // ==========================================

    echo "<h2>🧪 Тест SQL з CrmDeal.php (метод getStatsByUTMField)</h2>";

    echo "<h3>SQL запит:</h3>";
    echo "<pre>";
    echo "SELECT utm_term as utm_value, COUNT(*) as leads\n";
    echo "FROM crm_deals\n";
    echo "WHERE DATE(created_at) >= '{$today}'\n";
    echo "  AND DATE(created_at) <= '{$today}'\n";
    echo "  AND UPPER(model) = 'Q7'\n";
    echo "  AND utm_term IS NOT NULL AND utm_term != ''\n";
    echo "GROUP BY utm_term\n";
    echo "ORDER BY leads DESC\n";
    echo "LIMIT 20";
    echo "</pre>";

    $sql = "SELECT utm_term as utm_value, COUNT(*) as leads,
                   GROUP_CONCAT(DISTINCT deal_id ORDER BY deal_id SEPARATOR ', ') as deal_ids
            FROM crm_deals
            WHERE DATE(created_at) >= :date_from
              AND DATE(created_at) <= :date_to
              AND UPPER(model) = 'Q7'
              AND utm_term IS NOT NULL AND utm_term != ''
            GROUP BY utm_term
            ORDER BY leads DESC
            LIMIT 20";

    $results = $db->fetchAll($sql, [
        'date_from' => $today,
        'date_to' => $today
    ]);

    echo "<h3>Результати (TOP 20):</h3>";
    echo "<table>";
    echo "<tr><th>#</th><th>UTM Term</th><th>Кількість</th><th>Deal IDs</th></tr>";

    foreach ($results as $i => $row) {
        $isLong = (strlen($row['utm_value']) > 50);
        $rowClass = $isLong ? "old" : "today";

        echo "<tr class='{$rowClass}'>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td>" . htmlspecialchars($row['utm_value']) . "</td>";
        echo "<td><strong>{$row['leads']}</strong></td>";
        echo "<td style='font-size: 10px;'>" . htmlspecialchars(substr($row['deal_ids'], 0, 100)) . "...</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
