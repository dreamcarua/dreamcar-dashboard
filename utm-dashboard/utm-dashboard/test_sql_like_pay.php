<?php
require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();

echo "<h1>🧪 Тест SQL LIKE для пошуку 'pay'</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } pre { background: #f5f5f5; padding: 10px; font-size: 11px; } table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; } th, td { border: 1px solid #ddd; padding: 10px; } th { background: #3b82f6; color: white; }</style>";

$dealId = 11605688;

echo "<h2>Deal ID: $dealId</h2>";

// Взяти raw_data
$sql = "SELECT id, raw_data FROM webhook_log WHERE deal_id = :deal_id AND webhook_type = 'crm' ORDER BY created_at ASC";
$webhooks = $db->fetchAll($sql, ['deal_id' => $dealId]);

echo "<p>Webhooks: <strong>" . count($webhooks) . "</strong></p>";

foreach ($webhooks as $i => $webhook) {
    echo "<h3>Webhook #" . ($i + 1) . " (ID: {$webhook['id']})</h3>";

    $data = json_decode($webhook['raw_data'], true);
    $title = $data['title'] ?? 'НЕ ЗНАЙДЕНО';

    echo "<p><strong>Title (з JSON parse):</strong> <code style='background: #d1fae5; padding: 5px 10px; font-size: 16px;'>$title</code></p>";

    // Тест різних LIKE patterns
    echo "<h4>Тести LIKE:</h4>";
    echo "<table>";
    echo "<tr><th>Pattern</th><th>Результат</th></tr>";

    $patterns = [
        '%"title":"pay"%',
        '%title%pay%',
        '%\"title\":\"pay\"%',
        '%pay%',
        '%title%'
    ];

    foreach ($patterns as $pattern) {
        $sql = "SELECT COUNT(*) as count FROM webhook_log WHERE id = :id AND raw_data LIKE :pattern";
        $result = $db->fetchOne($sql, ['id' => $webhook['id'], 'pattern' => $pattern]);

        $found = $result['count'] > 0 ? "✅ ЗНАЙДЕНО" : "❌ НЕ ЗНАЙДЕНО";
        $color = $result['count'] > 0 ? "green" : "red";

        echo "<tr>";
        echo "<td><code>$pattern</code></td>";
        echo "<td style='color: $color; font-weight: bold;'>$found</td>";
        echo "</tr>";
    }

    echo "</table>";

    // Показати raw_data (перші 500 символів)
    echo "<h4>raw_data (перші 500 символів):</h4>";
    echo "<pre>" . htmlspecialchars(substr($webhook['raw_data'], 0, 500)) . "...</pre>";

    echo "<hr>";
}

// Тест EXISTS запиту
echo "<h2>🔍 Тест EXISTS запиту:</h2>";

$sql = "SELECT d.deal_id, d.is_paid
        FROM crm_deals d
        WHERE d.deal_id = :deal_id
          AND d.is_paid != 1
          AND EXISTS (
              SELECT 1 FROM webhook_log w
              WHERE w.deal_id = d.deal_id
                AND w.webhook_type = 'crm'
                AND w.raw_data LIKE '%pay%'
          )";

$result = $db->fetchOne($sql, ['deal_id' => $dealId]);

if ($result) {
    echo "<p style='color: green; font-size: 18px;'>✅ EXISTS знайшов сделку!</p>";
    echo "<pre>" . print_r($result, true) . "</pre>";
} else {
    echo "<p style='color: red; font-size: 18px;'>❌ EXISTS НЕ знайшов сделку!</p>";

    // Перевірка чому
    $sql2 = "SELECT is_paid FROM crm_deals WHERE deal_id = :deal_id";
    $dealStatus = $db->fetchOne($sql2, ['deal_id' => $dealId]);

    echo "<p>is_paid в БД: <strong>{$dealStatus['is_paid']}</strong></p>";

    if ($dealStatus['is_paid'] == 1) {
        echo "<p style='color: orange;'>⚠️ Проблема: is_paid вже = 1! (Можливо вже виправлено)</p>";
    }
}
?>
