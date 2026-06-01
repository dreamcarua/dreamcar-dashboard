<?php
/**
 * Виправити 4 сделки: is_pending=1 → is_paid=1
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();

echo "<h1>🔧 Виправлення 4 проблемних сделок</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; }
th { background: #3b82f6; color: white; }
.success { background: #d1fae5; padding: 15px; }
.error { background: #fee2e2; padding: 15px; }
</style>";

$problematicDeals = [11605688, 11614119, 11614166, 11614175];

if (!isset($_GET['execute'])) {
    echo "<div class='error'>";
    echo "<h2>⚠️ ПЛАН:</h2>";
    echo "<p>Виправити 4 сделки які мають:</p>";
    echo "<ul>";
    echo "<li>Webhook 'pay' (На підпис) прийшов ✅</li>";
    echo "<li>Але в БД is_pending=1 ❌</li>";
    echo "</ul>";

    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Поточний статус</th><th>→</th><th>Новий статус</th><th>Сума</th></tr>";

    $totalAmount = 0;

    foreach ($problematicDeals as $dealId) {
        $sql = "SELECT is_paid, is_pending, amount_uah FROM crm_deals WHERE deal_id = :deal_id";
        $deal = $db->fetchOne($sql, ['deal_id' => $dealId]);

        if ($deal) {
            echo "<tr>";
            echo "<td><strong>$dealId</strong></td>";
            echo "<td style='color: red;'>is_pending=1</td>";
            echo "<td>→</td>";
            echo "<td style='color: green;'><strong>is_paid=1</strong></td>";
            echo "<td>" . number_format($deal['amount_uah'], 0) . " UAH</td>";
            echo "</tr>";

            $totalAmount += $deal['amount_uah'];
        }
    }

    echo "</table>";

    echo "<p><strong>Загальна сума:</strong> " . number_format($totalAmount, 0) . " UAH</p>";
    echo "<p>Ця сума переміститься з 'В процессе' → 'Оплачено'</p>";

    echo "</div>";

    echo "<p style='text-align: center; margin-top: 30px;'>";
    echo "<a href='?execute=yes' style='padding: 20px 40px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-size: 18px; font-weight: bold;' onclick='return confirm(\"Виправити 4 сделки?\\n\\nСума: " . number_format($totalAmount, 0) . " UAH\");'>✅ ВИПРАВИТИ 4 СДЕЛКИ</a>";
    echo "</p>";

} else {
    echo "<h2>🚀 ВИКОНАННЯ...</h2>";

    $fixed = 0;

    foreach ($problematicDeals as $dealId) {
        $sql = "UPDATE crm_deals
                SET is_paid = 1, is_failed = 0, is_pending = 0
                WHERE deal_id = :deal_id";

        $db->execute($sql, ['deal_id' => $dealId]);
        $fixed++;

        echo "<p>✅ Deal $dealId → is_paid=1</p>";
    }

    echo "<div class='success'>";
    echo "<h2>✅ ВИПРАВЛЕНО!</h2>";
    echo "<p>Оновлено: <strong>$fixed</strong> сделок</p>";
    echo "</div>";

    echo "<p><a href='index.php?date_range=yesterday' style='padding: 15px 30px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>→ Перевірити Dashboard</a></p>";
    echo "<p><a href='DIAGNOSTIC_SENDPULSE_VS_DB.php' style='padding: 15px 30px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>→ Запустити діагностику</a></p>";
}
?>
