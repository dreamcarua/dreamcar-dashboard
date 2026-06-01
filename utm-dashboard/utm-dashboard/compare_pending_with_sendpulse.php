<?php
/**
 * Порівняти is_pending в БД з "Новые" в SendPulse
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo "<h1>⚖️ Порівняння: БД vs SendPulse Новые</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
th { background: #3b82f6; color: white; }
.in-both { background: #d1fae5; }
.only-db { background: #fef3c7; }
.only-sendpulse { background: #fee2e2; }
</style>";

// SendPulse "Новые" (з повідомлення)
$sendpulsePending = [11610339, 11608890, 11608883, 11608882, 11608878, 11597708, 11597706, 11597328, 11592851, 11592849];

// БД Q7 is_pending=1
$sql = "SELECT deal_id, amount_uah, utm_term, created_at
        FROM crm_deals
        WHERE DATE(created_at) = :yesterday
          AND is_pending = 1
          AND UPPER(model) = 'Q7'
        ORDER BY deal_id ASC";

$dbPending = $db->fetchAll($sql, ['yesterday' => $yesterday]);
$dbPendingIds = array_column($dbPending, 'deal_id');

echo "<h2>📊 Статистика:</h2>";
echo "<table style='max-width: 500px;'>";
echo "<tr><th>Джерело</th><th>Кількість</th></tr>";
echo "<tr><td>SendPulse 'Новые'</td><td><strong>" . count($sendpulsePending) . "</strong></td></tr>";
echo "<tr><td>БД Q7 is_pending=1</td><td><strong>" . count($dbPendingIds) . "</strong></td></tr>";
echo "</table>";

// Знайти які є в обох
$inBoth = array_intersect($sendpulsePending, $dbPendingIds);

// Тільки в БД (зайві)
$onlyInDb = array_diff($dbPendingIds, $sendpulsePending);

// Тільки в SendPulse (відсутні в БД)
$onlyInSendpulse = array_diff($sendpulsePending, $dbPendingIds);

echo "<h2>✅ В ОБОХ (співпадають): " . count($inBoth) . "</h2>";
echo "<table>";
echo "<tr><th>Deal ID</th><th>Сума</th><th>UTM Term</th><th>Час</th></tr>";

foreach ($inBoth as $dealId) {
    $dealInfo = null;
    foreach ($dbPending as $d) {
        if ($d['deal_id'] == $dealId) {
            $dealInfo = $d;
            break;
        }
    }

    if ($dealInfo) {
        echo "<tr class='in-both'>";
        echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId=$dealId' target='_blank'>$dealId</a></td>";
        echo "<td>" . number_format($dealInfo['amount_uah'], 0) . " UAH</td>";
        echo "<td><code>{$dealInfo['utm_term']}</code></td>";
        echo "<td>" . substr($dealInfo['created_at'], 11, 5) . "</td>";
        echo "</tr>";
    }
}
echo "</table>";

echo "<h2>⚠️ ТІЛЬКИ В БД (зайві, +4): " . count($onlyInDb) . "</h2>";

if (empty($onlyInDb)) {
    echo "<p style='color: green;'>✅ Немає зайвих!</p>";
} else {
    echo "<p style='color: orange;'><strong>Ці сделки є в БД як is_pending=1, але в SendPulse вони НЕ в статусі 'Новые'!</strong></p>";
    echo "<p>Можливо вони вже оплатились або змінили статус.</p>";

    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Сума</th><th>UTM Term</th><th>Час</th><th>Перевірити в CRM</th></tr>";

    foreach ($onlyInDb as $dealId) {
        $dealInfo = null;
        foreach ($dbPending as $d) {
            if ($d['deal_id'] == $dealId) {
                $dealInfo = $d;
                break;
            }
        }

        if ($dealInfo) {
            echo "<tr class='only-db'>";
            echo "<td><strong>$dealId</strong></td>";
            echo "<td>" . number_format($dealInfo['amount_uah'], 0) . " UAH</td>";
            echo "<td><code>{$dealInfo['utm_term']}</code></td>";
            echo "<td>" . substr($dealInfo['created_at'], 11, 5) . "</td>";
            echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId=$dealId' target='_blank' style='padding: 5px 10px; background: #3b82f6; color: white; text-decoration: none; border-radius: 4px;'>Перевірити →</a></td>";
            echo "</tr>";
        }
    }
    echo "</table>";

    // Перевірити їх останній webhook
    echo "<h3>🔍 Останній webhook для зайвих сделок:</h3>";

    foreach ($onlyInDb as $dealId) {
        $sql = "SELECT raw_data, created_at
                FROM webhook_log
                WHERE deal_id = :deal_id
                  AND webhook_type = 'crm'
                ORDER BY created_at DESC
                LIMIT 1";

        $webhook = $db->fetchOne($sql, ['deal_id' => $dealId]);

        if ($webhook) {
            $data = json_decode($webhook['raw_data'], true);
            $title = $data['title'] ?? '—';
            $stepName = $data['variables']['stepName_deal'] ?? '—';

            echo "<p><strong>Deal $dealId:</strong> ";
            echo "Останній webhook = <code>title='$title'</code>, ";
            echo "<code>stepName='$stepName'</code>, ";
            echo "час: {$webhook['created_at']}</p>";

            if ($title === 'pay') {
                echo "<p style='color: red; font-weight: bold;'>❌ ПРОБЛЕМА! Webhook = 'pay', але в БД is_pending=1 (має бути is_paid=1)!</p>";
            }
        }
    }
}

echo "<h2>❌ ТІЛЬКИ В SENDPULSE (відсутні в БД): " . count($onlyInSendpulse) . "</h2>";

if (empty($onlyInSendpulse)) {
    echo "<p style='color: green;'>✅ Всі є в БД!</p>";
} else {
    echo "<p style='color: red;'><strong>Ці сделки є в SendPulse 'Новые', але відсутні в БД як is_pending=1!</strong></p>";

    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Статус в БД</th></tr>";

    foreach ($onlyInSendpulse as $dealId) {
        $sql = "SELECT is_paid, is_failed, is_pending, amount_uah FROM crm_deals WHERE deal_id = :deal_id";
        $dealInfo = $db->fetchOne($sql, ['deal_id' => $dealId]);

        echo "<tr class='only-sendpulse'>";
        echo "<td><strong>$dealId</strong></td>";

        if ($dealInfo) {
            $status = $dealInfo['is_paid'] ? 'is_paid=1' : ($dealInfo['is_failed'] ? 'is_failed=1' : ($dealInfo['is_pending'] ? 'is_pending=1' : 'unknown'));
            echo "<td>$status (сума: {$dealInfo['amount_uah']} UAH)</td>";
        } else {
            echo "<td style='color: red;'>❌ Взагалі відсутній в БД!</td>";
        }

        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<h2>🎯 ВИСНОВОК:</h2>";

if (count($onlyInDb) === 4 && empty($onlyInSendpulse)) {
    echo "<p style='background: #fef3c7; padding: 15px; border-left: 5px solid #f59e0b;'>";
    echo "<strong>⚠️ Знайдено 4 'зайві' сделки в БД:</strong><br>";
    echo "Вони є в БД як is_pending=1, але в SendPulse вже НЕ в статусі 'Новые'.<br>";
    echo "<strong>Потрібно:</strong> Перевірити їх останній webhook - можливо вони вже оплатились (title='pay')!";
    echo "</p>";
} elseif (empty($onlyInDb) && empty($onlyInSendpulse)) {
    echo "<p style='background: #d1fae5; padding: 15px; border-left: 5px solid #10b981;'>";
    echo "✅ <strong>ВСЕ СПІВПАДАЄ!</strong> Дані синхронізовані правильно!";
    echo "</p>";
} else {
    echo "<p style='background: #fee2e2; padding: 15px; border-left: 5px solid #ef4444;'>";
    echo "❌ <strong>Є розбіжності!</strong> Потрібна синхронізація.";
    echo "</p>";
}
?>
