<?php
/**
 * Очищення campaign_id з UTM-міток
 * Призначення: Видалити Meta Ads ID з utm_term, utm_campaign, utm_content
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🧹 Очищення campaign_id з UTM-міток</h1>";
echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background: #3b82f6; color: white; }
    .error { background: #fee; color: red; font-weight: bold; }
    .success { background: #efe; color: green; font-weight: bold; }
    .warning { background: #fef3c7; padding: 15px; border-left: 5px solid orange; margin: 20px 0; }
    pre { background: #f5f5f5; padding: 15px; overflow-x: auto; }
    .btn { padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }
    .btn-danger { background: #ef4444; }
</style>";

try {
    $db = Database::getInstance();

    // ==========================================
    // КРОК 1: Аналіз проблемних записів
    // ==========================================

    echo "<h2>📊 Крок 1: Аналіз проблемних записів</h2>";

    // Знайти записи де utm_term - це campaign_id
    $sql1 = "SELECT COUNT(*) as count FROM crm_deals WHERE utm_term REGEXP '^[0-9]{15,}$'";
    $countTerm = $db->fetchOne($sql1)['count'];

    // Знайти записи де utm_campaign - це campaign_id
    $sql2 = "SELECT COUNT(*) as count FROM crm_deals WHERE utm_campaign REGEXP '^[0-9]{15,}$'";
    $countCampaign = $db->fetchOne($sql2)['count'];

    // Знайти записи де utm_content - це ad_id
    $sql3 = "SELECT COUNT(*) as count FROM crm_deals WHERE utm_content REGEXP '^[0-9]{15,}$'";
    $countContent = $db->fetchOne($sql3)['count'];

    echo "<table>";
    echo "<tr><th>Поле</th><th>Кількість проблемних записів</th></tr>";
    echo "<tr><td><strong>utm_term</strong></td><td class='error'>{$countTerm}</td></tr>";
    echo "<tr><td><strong>utm_campaign</strong></td><td class='error'>{$countCampaign}</td></tr>";
    echo "<tr><td><strong>utm_content</strong></td><td class='error'>{$countContent}</td></tr>";
    echo "</table>";

    $totalProblems = $countTerm + $countCampaign + $countContent;

    if ($totalProblems === 0) {
        echo "<p class='success'>✅ Проблемних записів не знайдено!</p>";
        exit;
    }

    // ==========================================
    // КРОК 2: Показати приклади
    // ==========================================

    echo "<h2>📋 Крок 2: Приклади проблемних записів</h2>";

    $sql = "SELECT deal_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content, created_at
            FROM crm_deals
            WHERE utm_term REGEXP '^[0-9]{15,}$'
               OR utm_campaign REGEXP '^[0-9]{15,}$'
               OR utm_content REGEXP '^[0-9]{15,}$'
            ORDER BY created_at DESC
            LIMIT 10";

    $examples = $db->fetchAll($sql);

    echo "<table>";
    echo "<tr><th>Deal ID</th><th>UTM Source</th><th>UTM Medium</th><th>UTM Campaign</th><th>UTM Term</th><th>UTM Content</th><th>Дата</th></tr>";

    foreach ($examples as $ex) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($ex['deal_id']) . "</td>";
        echo "<td>" . htmlspecialchars($ex['utm_source'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($ex['utm_medium'] ?? '-') . "</td>";

        $isCampaignBad = preg_match('/^\d{15,}$/', $ex['utm_campaign'] ?? '');
        echo "<td class='" . ($isCampaignBad ? "error" : "") . "'>" . htmlspecialchars($ex['utm_campaign'] ?? '-') . "</td>";

        $isTermBad = preg_match('/^\d{15,}$/', $ex['utm_term'] ?? '');
        echo "<td class='" . ($isTermBad ? "error" : "") . "'>" . htmlspecialchars($ex['utm_term'] ?? '-') . "</td>";

        $isContentBad = preg_match('/^\d{15,}$/', $ex['utm_content'] ?? '');
        echo "<td class='" . ($isContentBad ? "error" : "") . "'>" . htmlspecialchars($ex['utm_content'] ?? '-') . "</td>";

        echo "<td>" . htmlspecialchars($ex['created_at']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 3: Виконати очищення
    // ==========================================

    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {

        echo "<h2>🚀 Крок 3: ВИКОНАННЯ ОЧИЩЕННЯ</h2>";

        // 1. Очистити utm_term
        echo "<p>Очищення <strong>utm_term</strong>...</p>";
        $sql = "UPDATE crm_deals SET utm_term = NULL WHERE utm_term REGEXP '^[0-9]{15,}$'";
        $db->execute($sql);
        echo "<p class='success'>✅ Очищено: {$countTerm} записів</p>";

        // 2. Очистити utm_campaign
        echo "<p>Очищення <strong>utm_campaign</strong>...</p>";
        $sql = "UPDATE crm_deals SET utm_campaign = NULL WHERE utm_campaign REGEXP '^[0-9]{15,}$'";
        $db->execute($sql);
        echo "<p class='success'>✅ Очищено: {$countCampaign} записів</p>";

        // 3. Очистити utm_content
        echo "<p>Очищення <strong>utm_content</strong>...</p>";
        $sql = "UPDATE crm_deals SET utm_content = NULL WHERE utm_content REGEXP '^[0-9]{15,}$'";
        $db->execute($sql);
        echo "<p class='success'>✅ Очищено: {$countContent} записів</p>";

        echo "<hr>";
        echo "<h2 class='success'>🎉 ОЧИЩЕННЯ ЗАВЕРШЕНО!</h2>";
        echo "<p><a href='index.php' class='btn'>→ Перейти до дашборду</a></p>";
        echo "<p><a href='analyze_strange_utm.php' class='btn'>→ Перевірити результат</a></p>";

    } else {
        // Показати попередження
        echo "<div class='warning'>";
        echo "<h3>⚠️ УВАГА! Ти збираєшся очистити {$totalProblems} записів</h3>";
        echo "<p>Це видалить Meta Ads ID з полів utm_term, utm_campaign, utm_content в таблиці crm_deals</p>";
        echo "<p><strong>Операція незворотна!</strong> Переконайся що ти зробив backup БД</p>";

        echo "<h4>Що буде зроблено:</h4>";
        echo "<ul>";
        echo "<li>utm_term: очистити {$countTerm} записів</li>";
        echo "<li>utm_campaign: очистити {$countCampaign} записів</li>";
        echo "<li>utm_content: очистити {$countContent} записів</li>";
        echo "</ul>";

        echo "<p style='margin-top: 30px;'>";
        echo "<a href='?execute=yes' class='btn btn-danger' onclick='return confirm(\"Ти впевнений? Це видалить {$totalProblems} записів!\")'>🧹 ВИКОНАТИ ОЧИЩЕННЯ</a>";
        echo "<a href='index.php' class='btn'>← Скасувати</a>";
        echo "</p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
