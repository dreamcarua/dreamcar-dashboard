<?php
/**
 * Виправити ВСІ сделки поточного проекту де є webhook 'pay', але is_paid=0
 */

set_time_limit(600);
ini_set('memory_limit', '512M');

require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();

// Завантажити активний проект
$settingsFile = __DIR__ . '/config/dashboard_settings.json';
$activeProject = 'Q7';
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $activeProject = $settings['active_project'] ?? 'Q7';
}

echo "<h1>🔧 Виправлення сделок проекту $activeProject</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; }
th { background: #3b82f6; color: white; }
.success { background: #d1fae5; padding: 15px; border-left: 5px solid #10b981; }
.error { background: #fee2e2; padding: 15px; border-left: 5px solid #ef4444; }
.warning { background: #fef3c7; padding: 15px; border-left: 5px solid #f59e0b; }
.progress { background: #e5e7eb; height: 30px; margin: 20px 0; border-radius: 5px; }
.progress-bar { background: #10b981; height: 100%; text-align: center; line-height: 30px; color: white; font-weight: bold; }
</style>";

if (!isset($_GET['execute'])) {
    echo "<div class='warning'>";
    echo "<h2>⚠️ ПЛАН ВИПРАВЛЕННЯ</h2>";

    // Знайти сделки де є webhook 'pay', але is_paid != 1
    $sql = "SELECT DISTINCT d.deal_id, d.amount_uah, d.is_paid, d.is_failed, d.is_pending, d.created_at
            FROM crm_deals d
            INNER JOIN webhook_log w ON d.deal_id = w.deal_id
            WHERE UPPER(d.model) = :project
              AND w.webhook_type = 'crm'
              AND w.raw_data LIKE '%\"title\":\"pay\"%'
              AND d.is_paid != 1
            ORDER BY d.created_at DESC";

    $problematicDeals = $db->fetchAll($sql, ['project' => strtoupper($activeProject)]);

    echo "<p><strong>Проект:</strong> $activeProject</p>";
    echo "<p><strong>Знайдено проблемних сделок:</strong> " . count($problematicDeals) . "</p>";

    echo "<p>Це сделки де:</p>";
    echo "<ul>";
    echo "<li>Є webhook з title='pay' (На підпись) ✅</li>";
    echo "<li>Але в БД is_paid=0 ❌</li>";
    echo "</ul>";

    if (empty($problematicDeals)) {
        echo "<div class='success'><p>✅ Проблемних сделок не знайдено!</p></div>";
    } else {
        echo "<h3>Перші 20 сделок для виправлення:</h3>";
        echo "<table>";
        echo "<tr><th>Deal ID</th><th>Дата</th><th>Сума</th><th>Поточний статус</th><th>→</th><th>Новий статус</th></tr>";

        $totalAmount = 0;

        foreach (array_slice($problematicDeals, 0, 20) as $deal) {
            $currentStatus = '';
            if ($deal['is_paid']) $currentStatus = 'is_paid=1';
            elseif ($deal['is_failed']) $currentStatus = 'is_failed=1';
            elseif ($deal['is_pending']) $currentStatus = 'is_pending=1';
            else $currentStatus = 'unknown';

            echo "<tr>";
            echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId={$deal['deal_id']}' target='_blank'>{$deal['deal_id']}</a></td>";
            echo "<td>" . substr($deal['created_at'], 0, 10) . "</td>";
            echo "<td>" . number_format($deal['amount_uah'], 0) . " UAH</td>";
            echo "<td style='color: red;'><strong>$currentStatus</strong></td>";
            echo "<td>→</td>";
            echo "<td style='color: green;'><strong>is_paid=1</strong></td>";
            echo "</tr>";

            $totalAmount += $deal['amount_uah'];
        }

        if (count($problematicDeals) > 20) {
            echo "<tr><td colspan='6'>... і ще " . (count($problematicDeals) - 20) . " сделок</td></tr>";
        }

        echo "</table>";

        echo "<h3>📊 Вплив на статистику:</h3>";
        echo "<table style='max-width: 600px;'>";
        echo "<tr><th>Метрика</th><th>Зміна</th></tr>";
        echo "<tr><td>Кількість сделок</td><td><strong>" . count($problematicDeals) . "</strong></td></tr>";
        echo "<tr><td>Сума що переміститься</td><td><strong>" . number_format($totalAmount, 0) . " UAH</strong></td></tr>";
        echo "<tr><td>⏳ В процессе</td><td style='color: red;'>-" . count($problematicDeals) . "</td></tr>";
        echo "<tr><td>✅ Оплачено</td><td style='color: green;'>+" . count($problematicDeals) . "</td></tr>";
        echo "</table>";

        echo "</div>";

        echo "<p style='text-align: center; margin-top: 30px;'>";
        echo "<a href='?execute=yes' style='padding: 20px 40px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-size: 20px; font-weight: bold;' onclick='return confirm(\"Виправити " . count($problematicDeals) . " сделок?\\n\\nСума: " . number_format($totalAmount, 0) . " UAH\\n\\nПроект: $activeProject\");'>✅ ВИПРАВИТИ ВСІ</a>";
        echo "</p>";
    }

} else {
    echo "<h2>🚀 ВИКОНАННЯ ВИПРАВЛЕННЯ...</h2>";

    // Знайти всі проблемні сделки
    $sql = "SELECT DISTINCT d.deal_id
            FROM crm_deals d
            INNER JOIN webhook_log w ON d.deal_id = w.deal_id
            WHERE UPPER(d.model) = :project
              AND w.webhook_type = 'crm'
              AND w.raw_data LIKE '%\"title\":\"pay\"%'
              AND d.is_paid = 0";

    $problematicDeals = $db->fetchAll($sql, ['project' => strtoupper($activeProject)]);

    $total = count($problematicDeals);
    $fixed = 0;

    echo "<p>Всього для обробки: <strong>$total</strong></p>";
    echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%;'>0%</div></div>";

    foreach ($problematicDeals as $index => $deal) {
        $dealId = $deal['deal_id'];

        $sql = "UPDATE crm_deals
                SET is_paid = 1, is_failed = 0, is_pending = 0
                WHERE deal_id = :deal_id";

        $db->execute($sql, ['deal_id' => $dealId]);
        $fixed++;

        // Прогрес
        if ($index % 10 === 0 || $index === $total - 1) {
            $progress = round((($index + 1) / $total) * 100);
            echo "<script>
                document.getElementById('progressBar').style.width = '{$progress}%';
                document.getElementById('progressBar').innerText = '{$progress}% ({$fixed} з {$total})';
            </script>";
            flush();
        }
    }

    echo "<div class='success'>";
    echo "<h2>🎉 ВИПРАВЛЕНО!</h2>";
    echo "<p>Оновлено сделок: <strong>$fixed</strong></p>";
    echo "</div>";

    echo "<p style='text-align: center; margin-top: 30px;'>";
    echo "<a href='index.php?date_range=yesterday' style='padding: 15px 30px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-size: 18px; font-weight: bold;'>→ Перевірити Dashboard</a> ";
    echo "<a href='DIAGNOSTIC_SENDPULSE_VS_DB.php' style='padding: 15px 30px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-size: 18px; font-weight: bold;'>→ Діагностика</a>";
    echo "</p>";
}
?>
