<?php
/**
 * Debug: Що повертає API test.php з фільтром utm_term
 */

$yesterday = date('Y-m-d', strtotime('-1 day'));

$url = "https://dreamcar.ai-platform.space/volvo/dashboard/utm-dashboard/api/test.php?date_range=yesterday&utm_term=oborotfb";

echo "<h1>🔍 Debug: API Response</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
pre { background: #f5f5f5; padding: 15px; border-left: 4px solid #3b82f6; overflow-x: auto; max-height: 600px; }
</style>";

echo "<p>URL: <a href='$url' target='_blank'>$url</a></p>";

// Отримати відповідь API
session_start(); // Використати поточну сесію

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

echo "<h2>Campaigns в відповіді:</h2>";

// Шукаємо в analytics.campaigns_analytics
if (isset($data['analytics']['campaigns_analytics'])) {
    echo "<p>✅ campaigns_analytics знайдено: <strong>" . count($data['analytics']['campaigns_analytics']) . "</strong></p>";

    $targetCampaign = null;
    foreach ($data['analytics']['campaigns_analytics'] as $name => $campaign) {
        if (strpos($name, 'ob|atrib1d|audiq7|adv+|video|15.12') !== false) {
            $targetCampaign = $campaign;
            echo "<h3>Знайдена цільова campaign:</h3>";
            echo "<p>Назва: <strong>$name</strong></p>";
            break;
        }
    }

    if ($targetCampaign) {
        echo "<pre>";
        print_r($targetCampaign);
        echo "</pre>";

        if ($targetCampaign['ads_spend'] > 0) {
            echo "<p style='background: #efe; padding: 10px;'>✅ ads_spend = " . $targetCampaign['ads_spend'] . " (ПРАВИЛЬНО!)</p>";
        } else {
            echo "<p style='background: #fee; padding: 10px;'>❌ ads_spend = 0 (ПРОБЛЕМА!)</p>";
        }
    }
} else {
    echo "<p style='color: red;'>❌ Немає campaigns_analytics в відповіді!</p>";
}

echo "<h2>Повна відповідь (перші 3000 символів):</h2>";
echo "<pre>" . htmlspecialchars(substr($response, 0, 3000)) . "...</pre>";
?>
