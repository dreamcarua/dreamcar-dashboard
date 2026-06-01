<?php
/**
 * ГЛИБОКА ПЕРЕВІРКА 4 проблемних сделок
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();

echo "<h1>🔬 ГЛИБОКА ПЕРЕВІРКА 4 сделок</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
h2 { background: #ef4444; color: white; padding: 12px; margin-top: 25px; border-radius: 8px; }
h3 { background: #f59e0b; color: white; padding: 10px; margin-top: 20px; border-radius: 6px; }
table { border-collapse: collapse; width: 100%; margin: 15px 0; background: white; font-size: 13px; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #3b82f6; color: white; }
pre { background: white; padding: 12px; border: 1px solid #ddd; overflow-x: auto; font-size: 11px; }
.error { background: #fee2e2; padding: 15px; border-left: 5px solid #ef4444; margin: 15px 0; }
.success { background: #d1fae5; padding: 15px; border-left: 5px solid #10b981; margin: 15px 0; }
.warning { background: #fef3c7; padding: 15px; border-left: 5px solid #f59e0b; margin: 15px 0; }
</style>";

$problematicDeals = [11605688, 11614119, 11614166, 11614175];

foreach ($problematicDeals as $dealId) {
    echo "<h2>Deal ID: <a href='https://login.sendpulse.com/crm/deals?dealId=$dealId' target='_blank' style='color: white;'>$dealId</a></h2>";

    // КРОК 1: Статус в БД
    echo "<h3>1️⃣ Статус в crm_deals:</h3>";

    $sql = "SELECT * FROM crm_deals WHERE deal_id = :deal_id";
    $deal = $db->fetchOne($sql, ['deal_id' => $dealId]);

    if ($deal) {
        echo "<table>";
        echo "<tr><th>Поле</th><th>Значення</th></tr>";
        echo "<tr><td>deal_id</td><td><strong>{$deal['deal_id']}</strong></td></tr>";
        echo "<tr><td>created_at</td><td>{$deal['created_at']}</td></tr>";
        echo "<tr><td>amount_uah</td><td><strong>{$deal['amount_uah']} UAH</strong></td></tr>";
        echo "<tr><td>model</td><td><strong>{$deal['model']}</strong></td></tr>";
        echo "<tr><td>utm_term</td><td><code>{$deal['utm_term']}</code></td></tr>";

        $statusClass = '';
        if ($deal['is_paid'] == 1) $statusClass = "style='background: #d1fae5; font-weight: bold;'";
        elseif ($deal['is_pending'] == 1) $statusClass = "style='background: #fef3c7; font-weight: bold;'";
        elseif ($deal['is_failed'] == 1) $statusClass = "style='background: #fee2e2; font-weight: bold;'";

        echo "<tr $statusClass><td><strong>is_paid</strong></td><td><strong>{$deal['is_paid']}</strong></td></tr>";
        echo "<tr><td>is_failed</td><td>{$deal['is_failed']}</td></tr>";
        echo "<tr><td>is_pending</td><td>{$deal['is_pending']}</td></tr>";
        echo "</table>";
    } else {
        echo "<div class='error'><p>❌ Сделка не знайдена в crm_deals!</p></div>";
        continue;
    }

    // КРОК 2: ВСІ webhooks
    echo "<h3>2️⃣ ВСІ webhooks для цієї сделки (в хронологічному порядку):</h3>";

    $sql = "SELECT id, created_at, raw_data, success
            FROM webhook_log
            WHERE deal_id = :deal_id
              AND webhook_type = 'crm'
            ORDER BY created_at ASC";

    $webhooks = $db->fetchAll($sql, ['deal_id' => $dealId]);

    echo "<p>Всього webhooks: <strong>" . count($webhooks) . "</strong></p>";

    if (empty($webhooks)) {
        echo "<div class='error'><p>❌ Немає webhooks!</p></div>";
        continue;
    }

    echo "<table>";
    echo "<tr><th>#</th><th>ID</th><th>Час</th><th>Title</th><th>stepName_deal</th><th>price_deal</th><th>Success</th><th>raw_data (частково)</th></tr>";

    $hasPayWebhook = false;
    $payWebhookTime = null;

    foreach ($webhooks as $i => $webhook) {
        $data = json_decode($webhook['raw_data'], true);
        $title = $data['title'] ?? 'UNKNOWN';
        $stepName = $data['variables']['stepName_deal'] ?? '—';
        $price = $data['variables']['price_deal'] ?? '—';

        $rowClass = '';
        if ($title === 'pay') {
            $rowClass = "style='background: #d1fae5; font-weight: bold;'";
            $hasPayWebhook = true;
            $payWebhookTime = $webhook['created_at'];
        }

        echo "<tr $rowClass>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td>{$webhook['id']}</td>";
        echo "<td><strong>{$webhook['created_at']}</strong></td>";
        echo "<td><code style='font-size: 14px; font-weight: bold;'>$title</code></td>";
        echo "<td><code>$stepName</code></td>";
        echo "<td>$price UAH</td>";
        echo "<td>" . ($webhook['success'] ? '✅' : '❌') . "</td>";
        echo "<td><pre style='margin: 0; padding: 5px; font-size: 10px;'>" . htmlspecialchars(substr($webhook['raw_data'], 0, 150)) . "...</pre></td>";
        echo "</tr>";
    }

    echo "</table>";

    // КРОК 3: Аналіз проблеми
    echo "<h3>3️⃣ АНАЛІЗ ПРОБЛЕМИ:</h3>";

    if (!$hasPayWebhook) {
        echo "<div class='error'>";
        echo "<p>❌ <strong>НЕМАЄ WEBHOOK 'PAY'!</strong></p>";
        echo "<p>Webhook з title='pay' не прийшов з SendPulse.</p>";
        echo "<p><strong>Рішення:</strong> Чекати на webhook або вручну встановити is_paid=1</p>";
        echo "</div>";
    } else {
        echo "<div class='warning'>";
        echo "<p>⚠️ <strong>WEBHOOK 'PAY' Є!</strong></p>";
        echo "<p>Час webhook 'pay': <strong>$payWebhookTime</strong></p>";

        // Перевірити чи є webhooks ПІСЛЯ 'pay'
        $webhooksAfterPay = [];
        $foundPay = false;

        foreach ($webhooks as $webhook) {
            if ($foundPay) {
                $data = json_decode($webhook['raw_data'], true);
                $webhooksAfterPay[] = [
                    'time' => $webhook['created_at'],
                    'title' => $data['title'] ?? 'UNKNOWN'
                ];
            }

            $data = json_decode($webhook['raw_data'], true);
            if (($data['title'] ?? '') === 'pay') {
                $foundPay = true;
            }
        }

        if (!empty($webhooksAfterPay)) {
            echo "<p style='color: red; font-weight: bold;'>❌ <strong>ЗНАЙДЕНА ПРОБЛЕМА!</strong></p>";
            echo "<p>Після webhook 'pay' прийшли ще webhooks які ПЕРЕЗАПИСАЛИ статус:</p>";

            echo "<table style='max-width: 600px;'>";
            echo "<tr><th>Час</th><th>Title</th></tr>";

            foreach ($webhooksAfterPay as $w) {
                echo "<tr style='background: #fee2e2;'>";
                echo "<td>{$w['time']}</td>";
                echo "<td><strong>{$w['title']}</strong></td>";
                echo "</tr>";
            }

            echo "</table>";

            echo "<p><strong>Що сталось:</strong></p>";
            echo "<ol>";
            echo "<li>Webhook 'pay' прийшов → встановлено is_paid=1 ✅</li>";
            echo "<li>Потім прийшов webhook '" . $webhooksAfterPay[0]['title'] . "' → ПЕРЕЗАПИСАВ is_paid на is_pending ❌</li>";
            echo "</ol>";

            echo "<p><strong>Рішення:</strong> В webhook_crm.php вже додана перевірка - якщо is_paid=1, не змінювати!</p>";
            echo "<p style='color: green;'>Для майбутніх webhooks проблема вирішена. Ці 4 треба виправити вручну.</p>";

        } else {
            echo "<p style='color: green;'>✅ Після 'pay' інших webhooks не було</p>";
            echo "<p style='color: red;'>Але чомусь статус в БД = is_pending=1!</p>";
            echo "<p>Можлива причина: webhook_crm.php не спрацював правильно при обробці 'pay'</p>";
        }

        echo "</div>";
    }

    echo "<hr style='margin: 40px 0; border: 2px solid #3b82f6;'>";
}

// ПІДСУМОК
echo "<h2 style='background: #10b981;'>🎯 ПІДСУМОК ДЛЯ 4 СДЕЛОК</h2>";
echo "<div class='warning'>";
echo "<p><strong>Всі 4 сделки мають webhook 'pay', але is_pending=1 в БД!</strong></p>";
echo "<p><strong>Причина:</strong> Webhook 'new' прийшов ПІСЛЯ 'pay' і перезаписав статус.</p>";
echo "<p><strong>Виправлення:</strong></p>";
echo "<ul>";
echo "<li>✅ webhook_crm.php вже виправлено - для нових webhooks проблема не повториться</li>";
echo "<li>⚠️ Ці 4 сделки треба виправити вручну</li>";
echo "</ul>";
echo "</div>";

echo "<p style='text-align: center; margin-top: 30px;'>";
echo "<a href='fix_4_pending_to_paid.php' style='padding: 20px 40px; background: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-size: 20px; font-weight: bold;'>🔧 ВИПРАВИТИ 4 СДЕЛКИ</a>";
echo "</p>";
?>
