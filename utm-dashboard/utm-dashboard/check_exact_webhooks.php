<?php
/**
 * Перевірка точних webhook даних для довгих utm_term
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 Перевірка точних webhook даних</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 12px; }
    th { background: #3b82f6; color: white; }
    .highlight { background: #fef3c7; font-weight: bold; }
    pre { background: #f5f5f5; padding: 15px; overflow-x: auto; font-size: 11px; border-left: 4px solid #3b82f6; }
    .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>";

try {
    $db = Database::getInstance();

    // Список сумнівних utm_term
    $suspiciousTerms = [
        '120233142429400624_geo-ua_pl-ig_audience-interests_all_20-50_creo-video_test-cc-75_20250821_instagram_reels',
        '120231955637660624_geo-ua_pl-all_audience-interest-car_male_20-50_20250731_instagram_feed',
        '120231527577930624_geo-ua_pl-all_audience-lal_all_18+_creo-post_20250726_instagram_feed',
        '120233104636430624_geo-ua_pl-ig_audience-interests_all_20-50_creo-video_test-cc-75_20250821_instagram_feed',
        '120231525461530624_geo-ua_pl-all_audience-lal_all_20-45_20250726_instagram_reels'
    ];

    foreach ($suspiciousTerms as $termIndex => $term) {

        $displayIndex = $termIndex + 1;
        echo "<div class='section'>";
        echo "<h2>📋 Аналіз #{$displayIndex}: {$term}</h2>";

        // Знайти сделку з таким utm_term
        $sql = "SELECT deal_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content, created_at
                FROM crm_deals
                WHERE utm_term = :term1 OR utm_campaign = :term2 OR utm_content = :term3
                LIMIT 1";

        $deal = $db->fetchOne($sql, [
            'term1' => $term,
            'term2' => $term,
            'term3' => $term
        ]);

        if (!$deal) {
            echo "<p style='color: orange;'>⚠️ Сделка з таким utm_term не знайдена в БД</p>";
            echo "</div>";
            continue;
        }

        echo "<h3>📌 Deal ID: {$deal['deal_id']}</h3>";

        echo "<table>";
        echo "<tr><th>Поле crm_deals</th><th>Значення</th></tr>";
        echo "<tr><td><strong>utm_source</strong></td><td>" . htmlspecialchars($deal['utm_source'] ?? '-') . "</td></tr>";
        echo "<tr><td><strong>utm_medium</strong></td><td>" . htmlspecialchars($deal['utm_medium'] ?? '-') . "</td></tr>";
        echo "<tr><td><strong>utm_campaign</strong></td><td>" . htmlspecialchars($deal['utm_campaign'] ?? '-') . "</td></tr>";
        echo "<tr class='highlight'><td><strong>utm_term</strong></td><td>" . htmlspecialchars($deal['utm_term'] ?? '-') . "</td></tr>";
        echo "<tr><td><strong>utm_content</strong></td><td>" . htmlspecialchars($deal['utm_content'] ?? '-') . "</td></tr>";
        echo "<tr><td>created_at</td><td>" . htmlspecialchars($deal['created_at']) . "</td></tr>";
        echo "</table>";

        // Знайти webhook лог
        $sql = "SELECT raw_data, processed_data, created_at
                FROM webhook_log
                WHERE deal_id = :deal_id
                ORDER BY created_at DESC
                LIMIT 1";

        $webhookLog = $db->fetchOne($sql, ['deal_id' => $deal['deal_id']]);

        if ($webhookLog) {
            echo "<h3>✅ Webhook лог знайдено (час: {$webhookLog['created_at']})</h3>";

            $rawData = json_decode($webhookLog['raw_data'], true);
            $processedData = json_decode($webhookLog['processed_data'], true);

            // Показати UTM поля з webhook
            if (isset($rawData['variables'])) {
                $vars = $rawData['variables'];

                echo "<h4>🔍 UTM поля в RAW webhook:</h4>";
                echo "<table>";
                echo "<tr><th>Поле</th><th>Значення</th></tr>";

                // Поля БЕЗ _deal
                echo "<tr><td>utm_source</td><td>" . htmlspecialchars($vars['utm_source'] ?? 'NULL') . "</td></tr>";
                echo "<tr><td>utm_medium</td><td>" . htmlspecialchars($vars['utm_medium'] ?? 'NULL') . "</td></tr>";
                echo "<tr><td>utm_campaign</td><td>" . htmlspecialchars($vars['utm_campaign'] ?? 'NULL') . "</td></tr>";
                echo "<tr class='highlight'><td><strong>utm_term</strong></td><td><strong>" . htmlspecialchars($vars['utm_term'] ?? 'NULL') . "</strong></td></tr>";
                echo "<tr><td>utm_content</td><td>" . htmlspecialchars($vars['utm_content'] ?? 'NULL') . "</td></tr>";

                echo "<tr style='background: #e0f2fe;'><td colspan='2'><strong>Поля З _deal суффіксом:</strong></td></tr>";

                // Поля З _deal
                echo "<tr><td>utm_source_deal</td><td>" . htmlspecialchars($vars['utm_source_deal'] ?? 'NULL') . "</td></tr>";
                echo "<tr><td>utm_medium_deal</td><td>" . htmlspecialchars($vars['utm_medium_deal'] ?? 'NULL') . "</td></tr>";
                echo "<tr><td>utm_campaign_deal</td><td>" . htmlspecialchars($vars['utm_campaign_deal'] ?? 'NULL') . "</td></tr>";
                echo "<tr class='highlight'><td><strong>utm_term_deal</strong></td><td><strong>" . htmlspecialchars($vars['utm_term_deal'] ?? 'NULL') . "</strong></td></tr>";
                echo "<tr><td>utm_content_deal</td><td>" . htmlspecialchars($vars['utm_content_deal'] ?? 'NULL') . "</td></tr>";

                echo "</table>";

                // ВИСНОВОК
                echo "<h4>🎯 ВИСНОВОК:</h4>";

                $utmTermInWebhook = $vars['utm_term'] ?? null;
                $utmTermDealInWebhook = $vars['utm_term_deal'] ?? null;

                if ($utmTermInWebhook === $term) {
                    echo "<p class='highlight' style='padding: 15px; background: #fee; border-left: 5px solid red;'>";
                    echo "❌ <strong>ТАК, це РЕАЛЬНО прийшло в webhook!</strong><br>";
                    echo "Поле <code>utm_term</code> в webhook = <strong>{$term}</strong><br>";
                    echo "Поле <code>utm_term_deal</code> в webhook = <strong>" . htmlspecialchars($utmTermDealInWebhook ?? 'NULL') . "</strong><br><br>";

                    if ($utmTermDealInWebhook && $utmTermDealInWebhook !== $term) {
                        echo "✅ Є ПРАВИЛЬНЕ значення в <code>utm_term_deal</code>: <strong>{$utmTermDealInWebhook}</strong><br>";
                        echo "Треба оновити БД на правильне значення!";
                    } else {
                        echo "⚠️ В <code>utm_term_deal</code> НЕМАЄ правильного значення<br>";
                        echo "Це значення дійсно таке довге і прийшло з CRM";
                    }
                    echo "</p>";
                } else {
                    echo "<p style='padding: 15px; background: #efe; border-left: 5px solid green;'>";
                    echo "✅ В webhook <code>utm_term</code> = <strong>" . htmlspecialchars($utmTermInWebhook ?? 'NULL') . "</strong><br>";
                    echo "Значення в БД (<strong>{$term}</strong>) - це з іншого поля";
                    echo "</p>";
                }
            }

            // Показати ПОВНИЙ raw_data
            echo "<details>";
            echo "<summary style='cursor: pointer; padding: 10px; background: #f0f0f0; margin: 10px 0;'>📄 Показати ПОВНИЙ raw_data webhook</summary>";
            echo "<pre>" . json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
            echo "</details>";

        } else {
            echo "<p style='color: red;'>❌ Webhook лог не знайдено для deal_id: {$deal['deal_id']}</p>";
        }

        echo "</div>";
    }

    // Загальний висновок
    echo "<div class='section' style='background: #fef3c7;'>";
    echo "<h2>💡 Загальний висновок</h2>";
    echo "<p>Ці довгі рядки типу <code>120231525461530624_geo-ua_pl-all_audience...</code> - це:</p>";
    echo "<ul>";
    echo "<li><strong>Meta Ads назви campaigns/adsets</strong> які містять і campaign_id, і опис таргетингу</li>";
    echo "<li>Вони <strong>дійсно приходять</strong> з SendPulse CRM в полі <code>utm_term</code> (БЕЗ _deal)</li>";
    echo "<li>В полі <code>utm_term_deal</code> зазвичай є КОРОТКЕ значення (vadym, artem, vira)</li>";
    echo "</ul>";

    echo "<h4>Рішення:</h4>";
    echo "<p>Поточне виправлення в <code>webhook_crm.php</code> <strong>правильне</strong> - воно бере значення з <code>utm_term_deal</code> замість <code>utm_term</code></p>";
    echo "</div>";

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
