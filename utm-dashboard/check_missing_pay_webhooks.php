<?php
/**
 * Перевірити чи є webhook 'pay' для проблемних сделок
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();

echo "<h1>🔍 Перевірка webhooks для 4 проблемних сделок</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; }
th { background: #3b82f6; color: white; }
.has-pay { background: #d1fae5; }
.no-pay { background: #fee2e2; }
pre { background: #f5f5f5; padding: 10px; font-size: 12px; }
</style>";

$problematicDeals = [11605688, 11614119, 11614166, 11614175];

echo "<p><strong>Проблемні сделки:</strong> Є в SendPulse як 'На підпись', але в БД is_pending=1</p>";

foreach ($problematicDeals as $dealId) {
    echo "<h2>Deal ID: <a href='https://login.sendpulse.com/crm/deals?dealId=$dealId' target='_blank'>$dealId</a></h2>";

    // Знайти ВСІ webhooks для цієї сделки
    $sql = "SELECT id, created_at, raw_data, success
            FROM webhook_log
            WHERE deal_id = :deal_id
              AND webhook_type = 'crm'
            ORDER BY created_at ASC";

    $webhooks = $db->fetchAll($sql, ['deal_id' => $dealId]);

    echo "<p>Всього webhooks: <strong>" . count($webhooks) . "</strong></p>";

    if (empty($webhooks)) {
        echo "<p style='background: #fee2e2; padding: 10px;'>❌ Взагалі немає webhooks!</p>";
        continue;
    }

    echo "<table>";
    echo "<tr><th>#</th><th>Час</th><th>Title</th><th>stepName_deal</th><th>price_deal</th><th>Success</th></tr>";

    $hasPayWebhook = false;

    foreach ($webhooks as $i => $webhook) {
        $data = json_decode($webhook['raw_data'], true);
        $title = $data['title'] ?? '—';
        $stepName = $data['variables']['stepName_deal'] ?? '—';
        $price = $data['variables']['price_deal'] ?? '—';

        $rowClass = ($title === 'pay') ? "class='has-pay'" : "";

        if ($title === 'pay') $hasPayWebhook = true;

        echo "<tr $rowClass>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td>{$webhook['created_at']}</td>";
        echo "<td><strong>$title</strong></td>";
        echo "<td><code>$stepName</code></td>";
        echo "<td>$price UAH</td>";
        echo "<td>" . ($webhook['success'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }

    echo "</table>";

    // Висновок
    if ($hasPayWebhook) {
        echo "<div style='background: #fef3c7; padding: 15px; border-left: 5px solid #f59e0b;'>";
        echo "<p>⚠️ <strong>Є webhook 'pay', але в БД is_pending=1!</strong></p>";
        echo "<p>Проблема: webhook прийшов, але НЕ ОБРОБЛЕНИЙ або оброблений неправильно!</p>";
        echo "</div>";

        // Перевірити статус в БД
        $sql = "SELECT is_paid, is_failed, is_pending, amount_uah FROM crm_deals WHERE deal_id = :deal_id";
        $dealInfo = $db->fetchOne($sql, ['deal_id' => $dealId]);

        echo "<p><strong>Статус в БД:</strong></p>";
        echo "<pre>" . print_r($dealInfo, true) . "</pre>";

        if ($dealInfo['is_pending'] == 1) {
            echo "<p style='color: red; font-weight: bold;'>❌ ПОТРІБНО ВИПРАВИТИ: is_pending=1 → is_paid=1</p>";
        }

    } else {
        echo "<div style='background: #fee2e2; padding: 15px; border-left: 5px solid #ef4444;'>";
        echo "<p>❌ <strong>Немає webhook 'pay'!</strong></p>";
        echo "<p>Webhook не прийшов з SendPulse або загубився.</p>";
        echo "<p><strong>Рішення:</strong> Потрібно вручну оновити статус або дочекатись webhook.</p>";
        echo "</div>";
    }

    echo "<hr>";
}

echo "<h2>🎯 ПІДСУМОК:</h2>";
echo "<p>Перевірте кожну сделку в таблиці вище:</p>";
echo "<ul>";
echo "<li>Зелений рядок = є webhook 'pay' → потрібно виправити is_pending → is_paid</li>";
echo "<li>Білий рядок = немає webhook 'pay' → чекати або вручну оновити</li>";
echo "</ul>";
?>
