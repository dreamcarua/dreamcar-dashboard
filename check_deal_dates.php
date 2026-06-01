<?php
require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();

echo "<h1>📅 Перевірка дат webhooks</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; } th, td { border: 1px solid #ddd; padding: 10px; } th { background: #3b82f6; color: white; }</style>";

$testDealIds = [11589686, 11589687, 11589558];

echo "<h2>Перевірка тестових deal_id:</h2>";
echo "<table>";
echo "<tr><th>Deal ID</th><th>Дата webhook</th><th>Дата сделки</th><th>Title</th></tr>";

foreach ($testDealIds as $dealId) {
    // Webhook
    $sql = "SELECT created_at, raw_data FROM webhook_log WHERE deal_id = :deal_id AND webhook_type = 'crm' LIMIT 1";
    $webhook = $db->fetchOne($sql, ['deal_id' => $dealId]);

    // Deal
    $sql = "SELECT created_at FROM crm_deals WHERE deal_id = :deal_id LIMIT 1";
    $deal = $db->fetchOne($sql, ['deal_id' => $dealId]);

    if ($webhook) {
        $data = json_decode($webhook['raw_data'], true);
        $title = $data['title'] ?? '—';

        echo "<tr>";
        echo "<td><strong>$dealId</strong></td>";
        echo "<td>{$webhook['created_at']}</td>";
        echo "<td>" . ($deal['created_at'] ?? '—') . "</td>";
        echo "<td><code>$title</code></td>";
        echo "</tr>";
    } else {
        echo "<tr><td>$dealId</td><td colspan='3' style='color: red;'>❌ Webhook не знайдено</td></tr>";
    }
}

echo "</table>";

// Показати які дати є в webhook_log
echo "<h2>Дати в webhook_log (останні 7 днів):</h2>";

$sql = "SELECT
    DATE(created_at) as date,
    COUNT(*) as webhooks_count,
    COUNT(DISTINCT deal_id) as deals_count
FROM webhook_log
WHERE webhook_type = 'crm'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY date DESC";

$dates = $db->fetchAll($sql);

echo "<table>";
echo "<tr><th>Дата</th><th>Webhooks</th><th>Унікальних deals</th></tr>";

foreach ($dates as $row) {
    echo "<tr>";
    echo "<td><strong>{$row['date']}</strong></td>";
    echo "<td>{$row['webhooks_count']}</td>";
    echo "<td>{$row['deals_count']}</td>";
    echo "</tr>";
}

echo "</table>";

echo "<p><strong>Вчора (для порівняння):</strong> " . date('Y-m-d', strtotime('-1 day')) . "</p>";
?>
