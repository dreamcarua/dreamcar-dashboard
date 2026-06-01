<?php
/**
 * ВИПРАВЛЕННЯ СЬОГОДНІШНІХ СДЕЛОК
 * Оновити utm_term для сделок за сьогодні з webhook логів
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔧 Виправлення сьогоднішніх сделок</h1>";
echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #3b82f6; color: white; }
    .error { background: #fee; }
    .success { background: #efe; }
    .btn { padding: 12px 24px; background: #ef4444; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 0; font-weight: bold; }
</style>";

$today = date('Y-m-d');

try {
    $db = Database::getInstance();

    // ==========================================
    // КРОК 1: Знайти сьогоднішні сделки з довгими utm_term
    // ==========================================

    echo "<h2>📋 Крок 1: Пошук проблемних сделок за сьогодні</h2>";

    $sql = "SELECT deal_id, utm_term, utm_campaign, utm_content, created_at
            FROM crm_deals
            WHERE DATE(created_at) = :today
              AND (LENGTH(utm_term) > 50
                   OR LENGTH(utm_campaign) > 50
                   OR LENGTH(utm_content) > 50
                   OR utm_term REGEXP '^[0-9]{15,}')
            ORDER BY created_at DESC";

    $problematic = $db->fetchAll($sql, ['today' => $today]);

    echo "<p>Знайдено проблемних сделок: <strong>" . count($problematic) . "</strong></p>";

    if (empty($problematic)) {
        echo "<p class='success'>✅ Проблемних сделок не знайдено!</p>";
        exit;
    }

    echo "<table>";
    echo "<tr><th>Deal ID</th><th>utm_term (ПОГАНИЙ - що в БД зараз)</th><th>utm_campaign</th><th>utm_content</th><th>Час</th></tr>";
    foreach (array_slice($problematic, 0, 10) as $deal) {
        $isLongTerm = (strlen($deal['utm_term'] ?? '') > 50);
        $isLongCampaign = (strlen($deal['utm_campaign'] ?? '') > 50);
        $isLongContent = (strlen($deal['utm_content'] ?? '') > 50);

        $termClass = $isLongTerm ? "error" : "";
        $campaignClass = $isLongCampaign ? "error" : "";
        $contentClass = $isLongContent ? "error" : "";

        echo "<tr>";
        echo "<td>{$deal['deal_id']}</td>";
        echo "<td class='{$termClass}' style='max-width: 300px; word-break: break-all;'>" . htmlspecialchars($deal['utm_term'] ?? '-') . "</td>";
        echo "<td class='{$campaignClass}' style='max-width: 200px; word-break: break-all;'>" . htmlspecialchars($deal['utm_campaign'] ?? '-') . "</td>";
        echo "<td class='{$contentClass}' style='max-width: 200px; word-break: break-all;'>" . htmlspecialchars($deal['utm_content'] ?? '-') . "</td>";
        echo "<td>{$deal['created_at']}</td>";
        echo "</tr>";
    }
    if (count($problematic) > 10) {
        echo "<tr><td colspan='5'>... і ще " . (count($problematic) - 10) . " записів</td></tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 2: ВИПРАВЛЕННЯ
    // ==========================================

    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {

        echo "<h2>🚀 Крок 2: ВИКОНАННЯ ВИПРАВЛЕННЯ</h2>";

        $fixed = 0;
        $notFound = 0;

        foreach ($problematic as $deal) {
            $dealId = $deal['deal_id'];

            // Знайти webhook лог
            $sql = "SELECT raw_data FROM webhook_log
                    WHERE deal_id = :deal_id AND webhook_type = 'crm'
                    ORDER BY created_at DESC LIMIT 1";

            $webhook = $db->fetchOne($sql, ['deal_id' => $dealId]);

            if (!$webhook) {
                $notFound++;
                continue;
            }

            $rawData = json_decode($webhook['raw_data'], true);
            if (!isset($rawData['variables'])) {
                continue;
            }

            $vars = $rawData['variables'];

            // Витягти ПРАВИЛЬНІ значення з _deal полів
            $correctUtmSource = isset($vars['utm_source_deal']) ? strtolower(trim($vars['utm_source_deal'])) : null;
            $correctUtmMedium = isset($vars['utm_medium_deal']) ? strtolower(trim($vars['utm_medium_deal'])) : null;
            $correctUtmCampaign = isset($vars['utm_campaign_deal']) ? strtolower(trim($vars['utm_campaign_deal'])) : null;
            $correctUtmTerm = isset($vars['utm_term_deal']) ? strtolower(trim($vars['utm_term_deal'])) : null;
            $correctUtmContent = isset($vars['utm_content_deal']) ? strtolower(trim($vars['utm_content_deal'])) : null;

            // Оновити БД
            $updates = [];
            $params = ['deal_id' => $dealId];

            if ($correctUtmSource !== null) {
                $updates[] = "utm_source = :utm_source";
                $params['utm_source'] = $correctUtmSource;
            }
            if ($correctUtmMedium !== null) {
                $updates[] = "utm_medium = :utm_medium";
                $params['utm_medium'] = $correctUtmMedium;
            }
            if ($correctUtmCampaign !== null) {
                $updates[] = "utm_campaign = :utm_campaign";
                $params['utm_campaign'] = $correctUtmCampaign;
            }
            if ($correctUtmTerm !== null) {
                $updates[] = "utm_term = :utm_term";
                $params['utm_term'] = $correctUtmTerm;
            }
            if ($correctUtmContent !== null) {
                $updates[] = "utm_content = :utm_content";
                $params['utm_content'] = $correctUtmContent;
            }

            if (!empty($updates)) {
                $updateSql = "UPDATE crm_deals SET " . implode(', ', $updates) . " WHERE deal_id = :deal_id";
                $db->execute($updateSql, $params);
                $fixed++;

                echo "<p class='success'>✅ Deal {$dealId}: {$deal['utm_term']} → {$correctUtmTerm}</p>";
            }
        }

        echo "<hr>";
        echo "<h2 class='success'>🎉 ВИПРАВЛЕННЯ ЗАВЕРШЕНО!</h2>";
        echo "<table>";
        echo "<tr><td>✅ Виправлено</td><td><strong>{$fixed}</strong></td></tr>";
        echo "<tr><td>❌ Webhook не знайдено</td><td>{$notFound}</td></tr>";
        echo "</table>";

        echo "<p><a href='index.php' class='btn' style='background: #10b981;'>→ Перейти до дашборду</a></p>";

    } else {
        // Попередження
        echo "<div style='background: #fef3c7; padding: 20px; margin: 20px 0; border-left: 5px solid orange;'>";
        echo "<h3>⚠️ УВАГА!</h3>";
        echo "<p>Буде виправлено <strong>" . count($problematic) . "</strong> сьогоднішніх сделок</p>";
        echo "<p>Скрипт знайде webhook логи і оновить utm_term з правильними значеннями з <code>utm_term_deal</code></p>";
        echo "<p><a href='?execute=yes' class='btn' onclick='return confirm(\"Виправити " . count($problematic) . " сделок?\")'>🔧 ВИПРАВИТИ ЗАРАЗ</a></p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
