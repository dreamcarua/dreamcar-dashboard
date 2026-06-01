<?php
/**
 * Виправити маппінг статусів з webhooks
 * title='fail' + stepName_deal='В роботі' → is_pending=1 (НЕ is_failed!)
 */

set_time_limit(1800);
ini_set('memory_limit', '1024M');

require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();

echo "<h1>🔧 Виправлення маппінгу статусів</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 8px; }
th { background: #3b82f6; color: white; }
.success { background: #efe; padding: 15px; }
.error { background: #fee; padding: 15px; }
</style>";

if (!isset($_GET['execute'])) {
    // Показати план
    echo "<div class='error'>";
    echo "<h2>⚠️ ПЛАН ВИПРАВЛЕННЯ:</h2>";

    // Знайти проблемні записи
    $sql = "SELECT COUNT(*) as count
            FROM crm_deals d
            INNER JOIN webhook_log w ON d.deal_id = w.deal_id
            WHERE d.is_failed = 1
              AND w.webhook_type = 'crm'
              AND w.raw_data LIKE '%\"title\":\"fail\"%'
              AND w.raw_data LIKE '%В роботі%'";

    $problemCount = $db->fetchOne($sql);

    echo "<p><strong>Знайдено проблемних записів:</strong> {$problemCount['count']}</p>";
    echo "<p>Це сделки де:</p>";
    echo "<ul>";
    echo "<li>title = 'fail'</li>";
    echo "<li>stepName_deal = 'В роботі'</li>";
    echo "<li>is_failed = 1 ❌ (НЕПРАВИЛЬНО!)</li>";
    echo "</ul>";

    echo "<p><strong>Буде виправлено на:</strong></p>";
    echo "<ul>";
    echo "<li>is_failed = 0</li>";
    echo "<li>is_pending = 1 ✅</li>";
    echo "</ul>";

    echo "<p><strong>Вплив на суми:</strong></p>";

    $sql = "SELECT
        SUM(d.amount_uah) as total_amount
        FROM crm_deals d
        INNER JOIN webhook_log w ON d.deal_id = w.deal_id
        WHERE d.is_failed = 1
          AND w.webhook_type = 'crm'
          AND w.raw_data LIKE '%\"title\":\"fail\"%'
          AND w.raw_data LIKE '%В роботі%'";

    $amountToMove = $db->fetchOne($sql);

    echo "<p>Сума яка переміститься з 'Неуспешно' → 'В процессе': <strong>" . number_format($amountToMove['total_amount'], 0) . " UAH</strong></p>";

    echo "</div>";

    echo "<p style='text-align: center; margin-top: 30px;'>";
    echo "<a href='?execute=yes' style='padding: 20px 40px; background: #ef4444; color: white; text-decoration: none; border-radius: 8px; font-size: 18px; font-weight: bold;' onclick='return confirm(\"Виправити {$problemCount[\"count\"]} записів?\\n\\nСума: " . number_format($amountToMove['total_amount'], 0) . " UAH\");'>🔧 ВИПРАВИТИ МАППІНГ</a>";
    echo "</p>";

} else {
    // Виконати виправлення
    echo "<h2>🚀 ВИКОНАННЯ...</h2>";

    try {
        // Знайти всі проблемні deal_id
        $sql = "SELECT DISTINCT d.deal_id, w.raw_data
                FROM crm_deals d
                INNER JOIN webhook_log w ON d.deal_id = w.deal_id
                WHERE d.is_failed = 1
                  AND w.webhook_type = 'crm'
                  AND w.raw_data LIKE '%\"title\":\"fail\"%'
                ORDER BY d.deal_id ASC";

        $problematicDeals = $db->fetchAll($sql);

        $fixed = 0;
        $errors = 0;

        echo "<p>Обробка " . count($problematicDeals) . " сделок...</p>";

        foreach ($problematicDeals as $deal) {
            $data = json_decode($deal['raw_data'], true);
            $stepName = mb_strtolower(trim($data['variables']['stepName_deal'] ?? ''));

            // Перевірити чи це справді "В роботі"
            if ($stepName === 'в роботі' || $stepName === 'в работе') {
                // Виправити: is_failed=0, is_pending=1
                $sql = "UPDATE crm_deals
                        SET is_failed = 0, is_pending = 1
                        WHERE deal_id = :deal_id";

                $db->execute($sql, ['deal_id' => $deal['deal_id']]);
                $fixed++;
            }
        }

        echo "<div class='success'>";
        echo "<h2>✅ ВИПРАВЛЕНО!</h2>";
        echo "<p>Оновлено записів: <strong>$fixed</strong></p>";
        echo "</div>";

        echo "<p><a href='DIAGNOSTIC_SENDPULSE_VS_DB.php' style='padding: 15px 30px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>→ Перевірити результат</a></p>";

    } catch (Exception $e) {
        echo "<div class='error'>";
        echo "<p>❌ Помилка: " . $e->getMessage() . "</p>";
        echo "</div>";
    }
}
?>
