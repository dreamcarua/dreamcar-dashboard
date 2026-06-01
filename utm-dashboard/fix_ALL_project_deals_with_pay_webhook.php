<?php
/**
 * Виправити ВСІ сделки проекту де є webhook 'pay', але is_paid != 1
 * ЗА ВЕСЬ ЧАС (не тільки вчора)
 */

set_time_limit(1800);
ini_set('memory_limit', '1024M');

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

echo "<h1>🔧 Виправлення ВСІХ сделок проекту $activeProject</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
h2 { background: #ef4444; color: white; padding: 15px; margin-top: 30px; border-radius: 8px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; }
th { background: #3b82f6; color: white; }
.success { background: #d1fae5; padding: 20px; border-left: 5px solid #10b981; margin: 20px 0; }
.error { background: #fee2e2; padding: 20px; border-left: 5px solid #ef4444; margin: 20px 0; }
.warning { background: #fef3c7; padding: 20px; border-left: 5px solid #f59e0b; margin: 20px 0; }
.progress { background: #e5e7eb; height: 35px; margin: 25px 0; border-radius: 8px; overflow: hidden; }
.progress-bar { background: linear-gradient(to right, #10b981, #3b82f6); height: 100%; text-align: center; line-height: 35px; color: white; font-weight: bold; font-size: 16px; transition: width 0.3s; }
</style>";

if (!isset($_GET['execute'])) {
    echo "<div class='warning'>";
    echo "<h2>⚠️ ПЛАН ВИПРАВЛЕННЯ (ЗА ВЕСЬ ЧАС)</h2>";

    echo "<p><strong>Проект:</strong> $activeProject</p>";
    echo "<p><strong>Період:</strong> ВСІ сделки (за весь час)</p>";

    // КРОК 1: Знайти сделки де є webhook 'pay', але is_paid != 1
    echo "<h3>🔍 Пошук проблемних сделок...</h3>";

    $sql = "SELECT d.deal_id
            FROM crm_deals d
            WHERE UPPER(d.model) = :project
              AND d.is_paid != 1
              AND EXISTS (
                  SELECT 1 FROM webhook_log w
                  WHERE w.deal_id = d.deal_id
                    AND w.webhook_type = 'crm'
                    AND w.raw_data LIKE CONCAT('%', CHAR(34), 'title', CHAR(34), ': ', CHAR(34), 'pay', CHAR(34), '%')
              )
            ORDER BY d.created_at DESC";

    $problematicDealIds = $db->fetchAll($sql, ['project' => strtoupper($activeProject)]);

    echo "<p><strong>Знайдено проблемних сделок:</strong> <span style='font-size: 24px; color: #ef4444;'>" . count($problematicDealIds) . "</span></p>";

    if (empty($problematicDealIds)) {
        echo "<div class='success'>";
        echo "<h2>✅ ПРОБЛЕМНИХ СДЕЛОК НЕ ЗНАЙДЕНО!</h2>";
        echo "<p>Всі сделки з webhook 'pay' мають правильний статус is_paid=1</p>";
        echo "</div>";

    } else {
        // Отримати детальну інформацію
        $dealIds = array_column($problematicDealIds, 'deal_id');
        $dealIdsStr = implode(',', $dealIds);

        $sql = "SELECT
            COUNT(*) as total_count,
            SUM(amount_uah) as total_amount,
            MIN(created_at) as earliest_date,
            MAX(created_at) as latest_date
        FROM crm_deals
        WHERE deal_id IN ($dealIdsStr)";

        $stats = $db->fetchOne($sql);

        echo "<table>";
        echo "<tr><th>Метрика</th><th>Значення</th></tr>";
        echo "<tr><td>Всього сделок</td><td><strong style='font-size: 18px;'>{$stats['total_count']}</strong></td></tr>";
        echo "<tr><td>Загальна сума</td><td><strong style='font-size: 18px; color: #10b981;'>" . number_format($stats['total_amount'], 0) . " UAH</strong></td></tr>";
        echo "<tr><td>Перша сделка</td><td>{$stats['earliest_date']}</td></tr>";
        echo "<tr><td>Остання сделка</td><td>{$stats['latest_date']}</td></tr>";
        echo "</table>";

        echo "<h3>📊 Вплив на статистику:</h3>";
        echo "<ul style='font-size: 16px;'>";
        echo "<li>⏳ 'В процессе' → <strong style='color: red;'>-{$stats['total_count']}</strong> сделок</li>";
        echo "<li>✅ 'Оплачено' → <strong style='color: green;'>+{$stats['total_count']}</strong> сделок</li>";
        echo "<li>💰 'Заработано' → <strong style='color: green;'>+" . number_format($stats['total_amount'], 0) . " UAH</strong></li>";
        echo "</ul>";

        // Показати перші 50
        if (count($problematicDealIds) <= 50) {
            echo "<h3>ВСІ проблемні сделки:</h3>";
        } else {
            echo "<h3>Перші 50 проблемних сделок:</h3>";
        }

        $sql = "SELECT deal_id, created_at, amount_uah, utm_term, is_paid, is_pending
                FROM crm_deals
                WHERE deal_id IN ($dealIdsStr)
                ORDER BY created_at DESC
                LIMIT 50";

        $details = $db->fetchAll($sql);

        echo "<table>";
        echo "<tr><th>#</th><th>Deal ID</th><th>Дата</th><th>Сума</th><th>UTM Term</th><th>is_paid</th><th>is_pending</th></tr>";

        foreach ($details as $i => $deal) {
            echo "<tr>";
            echo "<td>" . ($i + 1) . "</td>";
            echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId={$deal['deal_id']}' target='_blank'>{$deal['deal_id']}</a></td>";
            echo "<td>" . substr($deal['created_at'], 0, 10) . "</td>";
            echo "<td>" . number_format($deal['amount_uah'], 0) . " UAH</td>";
            echo "<td><code>{$deal['utm_term']}</code></td>";
            echo "<td style='color: red;'>{$deal['is_paid']}</td>";
            echo "<td style='color: orange;'>{$deal['is_pending']}</td>";
            echo "</tr>";
        }

        if (count($problematicDealIds) > 50) {
            echo "<tr><td colspan='7' style='text-align: center; font-weight: bold;'>... і ще " . (count($problematicDealIds) - 50) . " сделок</td></tr>";
        }

        echo "</table>";

        echo "</div>";

        echo "<p style='text-align: center; margin-top: 40px;'>";
        echo "<a href='?execute=yes' style='padding: 25px 50px; background: #ef4444; color: white; text-decoration: none; border-radius: 12px; font-size: 22px; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.2);' onclick='return confirm(\"ВИПРАВИТИ " . count($problematicDealIds) . " СДЕЛОК?\\n\\nСума: " . number_format($stats['total_amount'], 0) . " UAH\\n\\nПроект: $activeProject\\n\\nЦе займе кілька хвилин.\");'>🔧 ВИПРАВИТИ ВСІ " . count($problematicDealIds) . " СДЕЛОК</a>";
        echo "</p>";
    }

} else {
    // ВИКОНАННЯ
    echo "<h2>🚀 ВИКОНАННЯ ВИПРАВЛЕННЯ...</h2>";
    flush();

    // Знайти всі проблемні deal_id
    $sql = "SELECT d.deal_id
            FROM crm_deals d
            WHERE UPPER(d.model) = :project
              AND d.is_paid != 1
              AND EXISTS (
                  SELECT 1 FROM webhook_log w
                  WHERE w.deal_id = d.deal_id
                    AND w.webhook_type = 'crm'
                    AND w.raw_data LIKE CONCAT('%', CHAR(34), 'title', CHAR(34), ': ', CHAR(34), 'pay', CHAR(34), '%')
              )
            ORDER BY d.deal_id ASC";

    $problematicDealIds = $db->fetchAll($sql, ['project' => strtoupper($activeProject)]);

    $total = count($problematicDealIds);
    $fixed = 0;

    echo "<p>Всього для обробки: <strong>$total</strong></p>";
    echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%;'>0%</div></div>";
    echo "<p id='status'>Початок обробки...</p>";

    flush();

    foreach ($problematicDealIds as $index => $row) {
        $dealId = $row['deal_id'];

        $sql = "UPDATE crm_deals
                SET is_paid = 1, is_failed = 0, is_pending = 0
                WHERE deal_id = :deal_id";

        $db->execute($sql, ['deal_id' => $dealId]);
        $fixed++;

        // Прогрес
        if ($index % 50 === 0 || $index === $total - 1) {
            $progress = round((($index + 1) / $total) * 100);
            echo "<script>
                document.getElementById('progressBar').style.width = '{$progress}%';
                document.getElementById('progressBar').innerText = '{$progress}% - {$fixed} з {$total}';
                document.getElementById('status').innerText = 'Оброблено: {$fixed} з {$total}';
            </script>";
            flush();
        }
    }

    echo "<div class='success'>";
    echo "<h2>🎉 ВИПРАВЛЕННЯ ЗАВЕРШЕНО!</h2>";
    echo "<p>Оновлено сделок: <strong style='font-size: 24px;'>$fixed</strong></p>";
    echo "</div>";

    echo "<p style='text-align: center; margin-top: 40px;'>";
    echo "<a href='index.php' style='padding: 20px 40px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-size: 20px; font-weight: bold; margin-right: 15px;'>→ Dashboard</a> ";
    echo "<a href='DIAGNOSTIC_SENDPULSE_VS_DB.php' style='padding: 20px 40px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-size: 20px; font-weight: bold;'>→ Діагностика</a>";
    echo "</p>";
}
?>
