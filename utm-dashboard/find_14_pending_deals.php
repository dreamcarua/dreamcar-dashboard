<?php
/**
 * Знайти 14 сделок "В процессе" (is_pending=1)
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo "<h1>🔍 14 сделок 'В процессе' (is_pending=1)</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #3b82f6; color: white; }
.highlight { background: #fef3c7; }
pre { background: #f5f5f5; padding: 10px; font-size: 12px; }
</style>";

// Завантажити активний проект
$settingsFile = __DIR__ . '/config/dashboard_settings.json';
$activeProject = 'Q7';
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $activeProject = $settings['active_project'] ?? 'Q7';
}

echo "<p><strong>Активний проект:</strong> $activeProject</p>";

// Знайти ВСІ is_pending=1 за вчора
$sql = "SELECT
    deal_id,
    created_at,
    amount_uah,
    model,
    utm_source,
    utm_medium,
    utm_campaign,
    utm_term,
    is_paid,
    is_failed,
    is_pending
FROM crm_deals
WHERE DATE(created_at) = :yesterday
  AND is_pending = 1
ORDER BY created_at DESC";

$pendingDeals = $db->fetchAll($sql, ['yesterday' => $yesterday]);

echo "<h2>Знайдено: <strong>" . count($pendingDeals) . "</strong> сделок з is_pending=1</h2>";

// Розділити по проектах
$byProject = [];
foreach ($pendingDeals as $deal) {
    $project = strtoupper($deal['model'] ?? 'UNKNOWN');
    if (!isset($byProject[$project])) {
        $byProject[$project] = [];
    }
    $byProject[$project][] = $deal;
}

echo "<h3>Розподіл по проектах:</h3>";
echo "<table style='max-width: 400px;'>";
echo "<tr><th>Проект</th><th>Кількість</th></tr>";
foreach ($byProject as $project => $deals) {
    $highlight = ($project === strtoupper($activeProject)) ? "style='background: #d1fae5; font-weight: bold;'" : "";
    echo "<tr $highlight><td>$project</td><td>" . count($deals) . "</td></tr>";
}
echo "</table>";

echo "<h2>ВСІ 18 сделок:</h2>";
echo "<table>";
echo "<tr><th>#</th><th>Deal ID</th><th>Проект</th><th>Час</th><th>Сума</th><th>Source</th><th>Term</th><th>is_paid</th><th>is_failed</th><th>is_pending</th><th>Webhook</th></tr>";

foreach ($pendingDeals as $i => $deal) {
    // Знайти webhook
    $sqlWebhook = "SELECT raw_data, created_at
                   FROM webhook_log
                   WHERE deal_id = :deal_id
                     AND webhook_type = 'crm'
                   ORDER BY created_at DESC
                   LIMIT 1";

    $webhook = $db->fetchOne($sqlWebhook, ['deal_id' => $deal['deal_id']]);

    $webhookTitle = '—';
    $stepNameDeal = '—';

    if ($webhook) {
        $data = json_decode($webhook['raw_data'], true);
        $webhookTitle = $data['title'] ?? '—';
        $stepNameDeal = $data['variables']['stepName_deal'] ?? '—';
    }

    $projectHighlight = (strtoupper($deal['model']) === strtoupper($activeProject)) ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";

    echo "<tr $projectHighlight>";
    echo "<td>" . ($i + 1) . "</td>";
    echo "<td><a href='https://dreamcar.sendpulse.com/messengers/deals/{$deal['deal_id']}' target='_blank'><strong>{$deal['deal_id']}</strong></a></td>";
    echo "<td><strong>{$deal['model']}</strong></td>";
    echo "<td>" . substr($deal['created_at'], 11, 5) . "</td>";
    echo "<td><strong>" . number_format($deal['amount_uah'], 0) . " UAH</strong></td>";
    echo "<td>{$deal['utm_source']}</td>";
    echo "<td><code>{$deal['utm_term']}</code></td>";
    echo "<td>{$deal['is_paid']}</td>";
    echo "<td>{$deal['is_failed']}</td>";
    echo "<td><strong>{$deal['is_pending']}</strong></td>";
    echo "<td><strong>$webhookTitle</strong> / <code>$stepNameDeal</code></td>";
    echo "</tr>";
}

echo "</table>";

// Підсумкова сума
$totalAmount = array_sum(array_column($pendingDeals, 'amount_uah'));

echo "<p><strong>Загальна сума 'В процессе':</strong> " . number_format($totalAmount, 0) . " UAH</p>";

// Порівняння з SendPulse
echo "<hr>";
echo "<h2>📊 Порівняння:</h2>";
echo "<table style='max-width: 600px;'>";
echo "<tr><th>Джерело</th><th>Кількість</th><th>Сума</th></tr>";
echo "<tr><td><strong>SendPulse 'Новые'</strong></td><td>10</td><td>4,288 UAH</td></tr>";
echo "<tr class='highlight'><td><strong>Dashboard 'В процессе'</strong></td><td>" . count($pendingDeals) . "</td><td>" . number_format($totalAmount, 0) . " UAH</td></tr>";
echo "<tr><td>Різниця</td><td style='color: red;'><strong>+" . (count($pendingDeals) - 10) . "</strong></td><td style='color: red;'><strong>+" . number_format($totalAmount - 4288, 0) . " UAH</strong></td></tr>";
echo "</table>";

// Аналіз по webhook titles
echo "<h2>📡 Аналіз по webhook titles:</h2>";

$titleCounts = [];
foreach ($pendingDeals as $deal) {
    $sqlWebhook = "SELECT raw_data FROM webhook_log WHERE deal_id = :deal_id AND webhook_type = 'crm' ORDER BY created_at DESC LIMIT 1";
    $webhook = $db->fetchOne($sqlWebhook, ['deal_id' => $deal['deal_id']]);

    if ($webhook) {
        $data = json_decode($webhook['raw_data'], true);
        $title = $data['title'] ?? 'unknown';
        $titleCounts[$title] = ($titleCounts[$title] ?? 0) + 1;
    }
}

echo "<table style='max-width: 400px;'>";
echo "<tr><th>Webhook Title</th><th>Кількість</th></tr>";
foreach ($titleCounts as $title => $count) {
    echo "<tr><td><strong>$title</strong></td><td>$count</td></tr>";
}
echo "</table>";

echo "<h2>💡 Висновок:</h2>";
echo "<p>Ці 14 сделок мають <code>is_pending=1</code> в БД.</p>";
echo "<p>SendPulse показує тільки 10 'Новые'.</p>";
echo "<p><strong>Можливі причини:</strong></p>";
echo "<ul>";
echo "<li>4 сделки могли змінити статус в SendPulse після синхронізації</li>";
echo "<li>Або маппінг title → is_pending працює інакше</li>";
echo "</ul>";
?>
