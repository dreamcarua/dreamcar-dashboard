<?php
/**
 * Знайти залишкову сделку з довгим utm_term
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 Пошук залишкової довгої мітки</h1>";
echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #ef4444; color: white; }
    .error { background: #fee; font-weight: bold; }
    pre { background: #f5f5f5; padding: 15px; border-left: 5px solid red; max-height: 500px; overflow-y: auto; }
    .btn { padding: 12px 24px; background: #ef4444; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 0; font-weight: bold; }
</style>";

$today = date('Y-m-d');
$targetTerm = '120231525461530624_geo-ua_pl-all_audience-lal_all_20-45_20250726_instagram_reels';

try {
    $db = Database::getInstance();

    echo "<h2>🎯 Шукаємо сделку з utm_term:</h2>";
    echo "<p><code>{$targetTerm}</code></p>";

    // Знайти ВСІ сьогоднішні сделки з цим utm_term
    $sql = "SELECT deal_id, contact_id, full_name, phone, email,
                   utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                   model, created_at
            FROM crm_deals
            WHERE DATE(created_at) = :today
              AND utm_term = :term
            ORDER BY created_at DESC";

    $deals = $db->fetchAll($sql, [
        'today' => $today,
        'term' => $targetTerm
    ]);

    echo "<p>Знайдено сделок: <strong>" . count($deals) . "</strong></p>";

    if (empty($deals)) {
        echo "<p class='error'>❌ СДЕЛКА НЕ ЗНАЙДЕНА!</p>";
        echo "<p>Можливо вона вже виправлена? Спробуй оновити dashboard</p>";
        exit;
    }

    // Показати всі знайдені
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Ім'я</th><th>Телефон</th><th>Model</th><th>created_at</th><th>CRM</th></tr>";

    foreach ($deals as $deal) {
        echo "<tr class='error'>";
        echo "<td><strong>{$deal['deal_id']}</strong></td>";
        echo "<td>" . htmlspecialchars($deal['full_name'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['phone'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['model']) . "</td>";
        echo "<td>{$deal['created_at']}</td>";
        echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId={$deal['deal_id']}' target='_blank'>Відкрити в CRM</a></td>";
        echo "</tr>";
    }
    echo "</table>";

    // Для ПЕРШОЇ сделки показати детальний аналіз
    $firstDeal = $deals[0];
    $dealId = $firstDeal['deal_id'];

    echo "<h2>📋 Детальний аналіз Deal ID: {$dealId}</h2>";

    // Знайти webhook лог
    $sql = "SELECT id, raw_data, processed_data, created_at
            FROM webhook_log
            WHERE deal_id = :deal_id AND webhook_type = 'crm'
            ORDER BY created_at ASC
            LIMIT 1";

    $webhook = $db->fetchOne($sql, ['deal_id' => $dealId]);

    if ($webhook) {
        echo "<p class='error'>✅ Webhook лог знайдено (ID: {$webhook['id']})</p>";

        $rawData = json_decode($webhook['raw_data'], true);
        $processedData = json_decode($webhook['processed_data'], true);

        $vars = $rawData['variables'] ?? [];

        echo "<table>";
        echo "<tr><th>Джерело</th><th>utm_term</th><th>utm_term_deal</th></tr>";
        echo "<tr><td><strong>RAW webhook</strong></td><td class='error'>" . htmlspecialchars($vars['utm_term'] ?? 'NULL') . "</td><td>" . htmlspecialchars($vars['utm_term_deal'] ?? 'NULL') . "</td></tr>";
        echo "<tr><td><strong>Processed Data</strong></td><td class='error'>" . htmlspecialchars($processedData['utm_term'] ?? 'NULL') . "</td><td>-</td></tr>";
        echo "<tr><td><strong>БД (зараз)</strong></td><td class='error'>" . htmlspecialchars($firstDeal['utm_term']) . "</td><td>-</td></tr>";
        echo "</table>";

        echo "<h3>❓ ЧОМУ НЕ ВИПРАВИЛОСЬ?</h3>";

        echo "<h4>ПОВНИЙ RAW DATA:</h4>";
        echo "<pre>" . json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

        echo "<h4>ПОВНИЙ PROCESSED DATA:</h4>";
        echo "<pre>" . json_encode($processedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

    } else {
        echo "<p class='error'>❌ Webhook лог НЕ знайдено!</p>";
    }

    // ==========================================
    // РУЧНЕ ВИПРАВЛЕННЯ
    // ==========================================

    if (isset($_GET['fix']) && $_GET['fix'] === 'yes') {

        echo "<h2>🔧 РУЧНЕ ВИПРАВЛЕННЯ</h2>";

        if ($webhook && isset($vars['utm_term_deal'])) {
            $correctUtmTerm = strtolower(trim($vars['utm_term_deal']));
            $correctUtmSource = isset($vars['utm_source_deal']) ? strtolower(trim($vars['utm_source_deal'])) : null;
            $correctUtmMedium = isset($vars['utm_medium_deal']) ? strtolower(trim($vars['utm_medium_deal'])) : null;
            $correctUtmCampaign = isset($vars['utm_campaign_deal']) ? strtolower(trim($vars['utm_campaign_deal'])) : null;
            $correctUtmContent = isset($vars['utm_content_deal']) ? strtolower(trim($vars['utm_content_deal'])) : null;

            $sql = "UPDATE crm_deals SET
                        utm_source = :utm_source,
                        utm_medium = :utm_medium,
                        utm_campaign = :utm_campaign,
                        utm_term = :utm_term,
                        utm_content = :utm_content
                    WHERE deal_id = :deal_id";

            $db->execute($sql, [
                'deal_id' => $dealId,
                'utm_source' => $correctUtmSource ?? $firstDeal['utm_source'],
                'utm_medium' => $correctUtmMedium ?? $firstDeal['utm_medium'],
                'utm_campaign' => $correctUtmCampaign ?? $firstDeal['utm_campaign'],
                'utm_term' => $correctUtmTerm,
                'utm_content' => $correctUtmContent ?? $firstDeal['utm_content']
            ]);

            echo "<p class='success' style='background: #efe; padding: 20px;'>✅ ВИПРАВЛЕНО!</p>";
            echo "<p>utm_term змінено з <code class='error'>{$targetTerm}</code> на <code><strong>{$correctUtmTerm}</strong></code></p>";

            echo "<p><a href='index.php' style='padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 5px;'>→ Перейти до дашборду</a></p>";

        } else {
            echo "<p class='error'>Не можу виправити - немає webhook логу або utm_term_deal</p>";
        }

    } else {
        echo "<p><a href='?fix=yes' class='btn'>🔧 ВИПРАВИТИ ЦЮ СДЕЛКУ</a></p>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
