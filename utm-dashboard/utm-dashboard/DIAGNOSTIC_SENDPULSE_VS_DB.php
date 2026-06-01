<?php
/**
 * 🔬 ДІАГНОСТИКА: SendPulse CRM vs Dashboard БД
 * Знайти причину розбіжності в сумах
 */

set_time_limit(600);
ini_set('memory_limit', '512M');

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔬 ДІАГНОСТИКА: SendPulse vs Dashboard</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
h2 { background: #3b82f6; color: white; padding: 12px; margin-top: 25px; border-radius: 8px; }
h3 { background: #6366f1; color: white; padding: 10px; margin-top: 20px; border-radius: 6px; }
table { border-collapse: collapse; width: 100%; margin: 15px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
th { background: #3b82f6; color: white; position: sticky; top: 0; }
.success { background: #d1fae5; border-left: 5px solid #10b981; padding: 15px; margin: 15px 0; }
.error { background: #fee2e2; border-left: 5px solid #ef4444; padding: 15px; margin: 15px 0; }
.warning { background: #fef3c7; border-left: 5px solid #f59e0b; padding: 15px; margin: 15px 0; }
.info { background: #dbeafe; border-left: 5px solid #3b82f6; padding: 15px; margin: 15px 0; }
pre { background: #f5f5f5; padding: 12px; border-left: 3px solid #6366f1; overflow-x: auto; font-size: 13px; }
.test-section { background: white; padding: 25px; margin: 25px 0; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
.highlight { background: #fef3c7; font-weight: bold; }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));
$db = Database::getInstance();

echo "<div class='info'>";
echo "<h3>📅 Дата перевірки: <strong>$yesterday</strong></h3>";
echo "<p><strong>SendPulse дані (з скріншоту):</strong></p>";
echo "<ul>";
echo "<li>Всього сделок: <strong>1,174</strong></li>";
echo "<li>Новые (10): 4,288 UAH</li>";
echo "<li>В работе (253): 117,362 UAH</li>";
echo "<li>На подпись (911): 446,216 UAH</li>";
echo "<li><strong>ЗАГАЛЬНА СУМА: 567,866 UAH</strong></li>";
echo "</ul>";
echo "</div>";

// ============================================
// КРОК 1: Статистика з БД
// ============================================

echo "<div class='test-section'>";
echo "<h2>📊 КРОК 1: Статистика з БД (crm_deals)</h2>";

$sql = "SELECT
    COUNT(*) as total_deals,
    SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
    SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
    SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount,
    SUM(CASE WHEN is_pending = 1 THEN amount_uah ELSE 0 END) as pending_amount,
    SUM(amount_uah) as total_amount
FROM crm_deals
WHERE DATE(created_at) = :yesterday";

$stats = $db->fetchOne($sql, ['yesterday' => $yesterday]);

echo "<table>";
echo "<tr><th>Метрика</th><th>Dashboard (БД)</th><th>SendPulse (CRM)</th><th>Різниця</th></tr>";

echo "<tr>";
echo "<td>Всього сделок</td>";
echo "<td><strong>{$stats['total_deals']}</strong></td>";
echo "<td>1,174</td>";
echo "<td>" . ($stats['total_deals'] - 1174) . "</td>";
echo "</tr>";

echo "<tr class='highlight'>";
echo "<td><strong>✅ Оплачено (На подпись)</strong></td>";
echo "<td><strong>" . number_format($stats['paid_amount'], 0) . " UAH</strong></td>";
echo "<td><strong>446,216 UAH</strong></td>";
$diff = $stats['paid_amount'] - 446216;
$diffClass = abs($diff) > 1000 ? "style='color: red; font-weight: bold;'" : "";
echo "<td $diffClass><strong>" . number_format($diff, 0) . " UAH</strong></td>";
echo "</tr>";

echo "<tr>";
echo "<td>⏳ В процесі (В работе)</td>";
echo "<td>" . number_format($stats['pending_amount'], 0) . " UAH</td>";
echo "<td>117,362 UAH</td>";
echo "<td>" . number_format($stats['pending_amount'] - 117362, 0) . " UAH</td>";
echo "</tr>";

echo "<tr>";
echo "<td>❌ Неуспешно (Новые?)</td>";
echo "<td>" . number_format($stats['failed_amount'], 0) . " UAH</td>";
echo "<td>4,288 UAH</td>";
echo "<td>" . number_format($stats['failed_amount'] - 4288, 0) . " UAH</td>";
echo "</tr>";

echo "<tr style='background: #e0f2fe;'>";
echo "<td><strong>ЗАГАЛЬНА СУМА</strong></td>";
$dbTotal = $stats['paid_amount'] + $stats['pending_amount'] + $stats['failed_amount'];
echo "<td><strong>" . number_format($dbTotal, 0) . " UAH</strong></td>";
echo "<td><strong>567,866 UAH</strong></td>";
$totalDiff = $dbTotal - 567866;
$totalDiffClass = abs($totalDiff) > 1000 ? "style='color: red; font-weight: bold;'" : "";
echo "<td $totalDiffClass><strong>" . number_format($totalDiff, 0) . " UAH</strong></td>";
echo "</tr>";

echo "</table>";

if (abs($totalDiff) > 1000) {
    echo "<div class='error'>";
    echo "<h3>❌ ЗНАЙДЕНО РОЗБІЖНІСТЬ!</h3>";
    echo "<p>Різниця: <strong>" . number_format(abs($totalDiff), 0) . " UAH</strong></p>";
    echo "</div>";
} else {
    echo "<div class='success'><p>✅ Дані співпадають!</p></div>";
}

echo "</div>";

// ============================================
// КРОК 2: Перевірка webhooks
// ============================================

echo "<div class='test-section'>";
echo "<h2>📡 КРОК 2: Webhooks за вчора</h2>";

$sql = "SELECT
    COUNT(*) as total_webhooks,
    COUNT(DISTINCT deal_id) as unique_deals,
    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count,
    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_count,
    webhook_type
FROM webhook_log
WHERE DATE(created_at) = :yesterday
GROUP BY webhook_type";

$webhookStats = $db->fetchAll($sql, ['yesterday' => $yesterday]);

echo "<table>";
echo "<tr><th>Тип</th><th>Всього webhooks</th><th>Унікальних deal_id</th><th>Успішно</th><th>Помилки</th></tr>";

$totalWebhooks = 0;
$totalFailed = 0;

foreach ($webhookStats as $row) {
    echo "<tr>";
    echo "<td><strong>{$row['webhook_type']}</strong></td>";
    echo "<td>{$row['total_webhooks']}</td>";
    echo "<td>{$row['unique_deals']}</td>";
    echo "<td style='color: green;'>{$row['success_count']}</td>";
    $failedClass = $row['failed_count'] > 0 ? "style='color: red; font-weight: bold;'" : "";
    echo "<td $failedClass>{$row['failed_count']}</td>";
    echo "</tr>";

    $totalWebhooks += $row['total_webhooks'];
    $totalFailed += $row['failed_count'];
}

echo "</table>";

if ($totalFailed > 0) {
    echo "<div class='warning'>";
    echo "<p>⚠️ Знайдено <strong>$totalFailed</strong> помилок в webhooks!</p>";
    echo "</div>";

    // Показати помилки
    $sql = "SELECT deal_id, event_type, error_message, created_at
            FROM webhook_log
            WHERE DATE(created_at) = :yesterday AND success = 0
            ORDER BY created_at DESC
            LIMIT 20";

    $errors = $db->fetchAll($sql, ['yesterday' => $yesterday]);

    if (!empty($errors)) {
        echo "<h3>❌ Помилки webhooks:</h3>";
        echo "<table>";
        echo "<tr><th>Deal ID</th><th>Event</th><th>Помилка</th><th>Час</th></tr>";

        foreach ($errors as $err) {
            echo "<tr>";
            echo "<td>{$err['deal_id']}</td>";
            echo "<td>{$err['event_type']}</td>";
            echo "<td style='color: red;'>" . htmlspecialchars($err['error_message']) . "</td>";
            echo "<td>{$err['created_at']}</td>";
            echo "</tr>";
        }

        echo "</table>";
    }
} else {
    echo "<div class='success'><p>✅ Всі webhooks успішні!</p></div>";
}

echo "</div>";

// ============================================
// КРОК 3: Перевірка статусів сделок
// ============================================

echo "<div class='test-section'>";
echo "<h2>🏷️ КРОК 3: Розподіл по статусах</h2>";

$sql = "SELECT
    is_paid, is_failed, is_pending,
    COUNT(*) as count,
    SUM(amount_uah) as total_amount
FROM crm_deals
WHERE DATE(created_at) = :yesterday
GROUP BY is_paid, is_failed, is_pending
ORDER BY total_amount DESC";

$statusBreakdown = $db->fetchAll($sql, ['yesterday' => $yesterday]);

echo "<table>";
echo "<tr><th>Статус</th><th>is_paid</th><th>is_failed</th><th>is_pending</th><th>Кількість</th><th>Сума</th></tr>";

foreach ($statusBreakdown as $row) {
    $statusName = '';
    if ($row['is_paid'] == 1) $statusName = '✅ Оплачено (На подпись)';
    elseif ($row['is_failed'] == 1) $statusName = '❌ Неуспешно (Новые?)';
    elseif ($row['is_pending'] == 1) $statusName = '⏳ В процессе (В работе)';
    else $statusName = '❓ Невідомий';

    echo "<tr>";
    echo "<td><strong>$statusName</strong></td>";
    echo "<td>{$row['is_paid']}</td>";
    echo "<td>{$row['is_failed']}</td>";
    echo "<td>{$row['is_pending']}</td>";
    echo "<td>{$row['count']}</td>";
    echo "<td><strong>" . number_format($row['total_amount'], 0) . " UAH</strong></td>";
    echo "</tr>";
}

echo "</table>";

echo "</div>";

// ============================================
// КРОК 4: Порівняння amount_uah
// ============================================

echo "<div class='test-section'>";
echo "<h2>💰 КРОК 4: Аналіз amount_uah</h2>";

$sql = "SELECT
    MIN(amount_uah) as min_amount,
    MAX(amount_uah) as max_amount,
    AVG(amount_uah) as avg_amount,
    SUM(CASE WHEN amount_uah = 0 THEN 1 ELSE 0 END) as zero_count,
    SUM(CASE WHEN amount_uah IS NULL THEN 1 ELSE 0 END) as null_count
FROM crm_deals
WHERE DATE(created_at) = :yesterday";

$amountStats = $db->fetchOne($sql, ['yesterday' => $yesterday]);

echo "<table>";
echo "<tr><th>Метрика</th><th>Значення</th></tr>";
echo "<tr><td>Мінімальна сума</td><td>" . number_format($amountStats['min_amount'], 0) . " UAH</td></tr>";
echo "<tr><td>Максимальна сума</td><td>" . number_format($amountStats['max_amount'], 0) . " UAH</td></tr>";
echo "<tr><td>Середня сума</td><td>" . number_format($amountStats['avg_amount'], 0) . " UAH</td></tr>";

$zeroClass = $amountStats['zero_count'] > 0 ? "style='background: #fee2e2;'" : "";
echo "<tr $zeroClass><td>Сделок з amount = 0</td><td><strong>{$amountStats['zero_count']}</strong></td></tr>";

$nullClass = $amountStats['null_count'] > 0 ? "style='background: #fee2e2;'" : "";
echo "<tr $nullClass><td>Сделок з amount = NULL</td><td><strong>{$amountStats['null_count']}</strong></td></tr>";

echo "</table>";

if ($amountStats['zero_count'] > 0 || $amountStats['null_count'] > 0) {
    echo "<div class='warning'>";
    echo "<p>⚠️ Знайдено сделки з нульовими/порожніми сумами!</p>";
    echo "</div>";
}

echo "</div>";

// ============================================
// КРОК 5: Топ-10 найбільших сделок
// ============================================

echo "<div class='test-section'>";
echo "<h2>💎 КРОК 5: Топ-10 найбільших сделок за вчора</h2>";

$sql = "SELECT
    deal_id,
    created_at,
    amount_uah,
    is_paid,
    is_failed,
    is_pending,
    utm_source,
    utm_term
FROM crm_deals
WHERE DATE(created_at) = :yesterday
ORDER BY amount_uah DESC
LIMIT 10";

$topDeals = $db->fetchAll($sql, ['yesterday' => $yesterday]);

echo "<table>";
echo "<tr><th>Deal ID</th><th>Час</th><th>Сума</th><th>Статус</th><th>Source</th><th>Term</th></tr>";

foreach ($topDeals as $deal) {
    $status = $deal['is_paid'] ? '✅ Paid' : ($deal['is_failed'] ? '❌ Failed' : ($deal['is_pending'] ? '⏳ Pending' : '❓'));

    echo "<tr>";
    echo "<td><a href='https://dreamcar.sendpulse.com/messengers/deals/{$deal['deal_id']}' target='_blank'>{$deal['deal_id']}</a></td>";
    echo "<td>" . substr($deal['created_at'], 11, 5) . "</td>";
    echo "<td><strong>" . number_format($deal['amount_uah'], 0) . " UAH</strong></td>";
    echo "<td>$status</td>";
    echo "<td>{$deal['utm_source']}</td>";
    echo "<td>{$deal['utm_term']}</td>";
    echo "</tr>";
}

echo "</table>";

echo "</div>";

// ============================================
// КРОК 6: Webhook помилки
// ============================================

echo "<div class='test-section'>";
echo "<h2>⚠️ КРОК 6: Аналіз webhook помилок</h2>";

$sql = "SELECT
    event_type,
    COUNT(*) as error_count,
    GROUP_CONCAT(DISTINCT error_message SEPARATOR ' | ') as error_messages
FROM webhook_log
WHERE DATE(created_at) = :yesterday
  AND success = 0
GROUP BY event_type
ORDER BY error_count DESC";

$webhookErrors = $db->fetchAll($sql, ['yesterday' => $yesterday]);

if (empty($webhookErrors)) {
    echo "<div class='success'><p>✅ Немає помилок webhooks за вчора!</p></div>";
} else {
    echo "<table>";
    echo "<tr><th>Event Type</th><th>Кількість помилок</th><th>Повідомлення</th></tr>";

    foreach ($webhookErrors as $err) {
        echo "<tr>";
        echo "<td><strong>{$err['event_type']}</strong></td>";
        echo "<td style='color: red;'><strong>{$err['error_count']}</strong></td>";
        echo "<td style='font-size: 12px;'>" . htmlspecialchars(substr($err['error_messages'], 0, 200)) . "...</td>";
        echo "</tr>";
    }

    echo "</table>";
}

echo "</div>";

// ============================================
// КРОК 7: Сделки БЕЗ webhook
// ============================================

echo "<div class='test-section'>";
echo "<h2>🔍 КРОК 7: Сделки БЕЗ webhook логів</h2>";

$sql = "SELECT COUNT(DISTINCT d.deal_id) as count
FROM crm_deals d
LEFT JOIN webhook_log w ON d.deal_id = w.deal_id AND w.webhook_type = 'crm'
WHERE DATE(d.created_at) = :yesterday
  AND w.id IS NULL";

$dealsWithoutWebhook = $db->fetchOne($sql, ['yesterday' => $yesterday]);

echo "<p>Сделок БЕЗ webhook: <strong>{$dealsWithoutWebhook['count']}</strong></p>";

if ($dealsWithoutWebhook['count'] > 0) {
    echo "<div class='warning'>";
    echo "<p>⚠️ Знайдено {$dealsWithoutWebhook['count']} сделок без webhook!</p>";
    echo "<p>Це може означати що дані були імпортовані не через webhook.</p>";
    echo "</div>";

    // Показати приклади
    $sql = "SELECT d.deal_id, d.created_at, d.amount_uah, d.utm_source, d.utm_term
            FROM crm_deals d
            LEFT JOIN webhook_log w ON d.deal_id = w.deal_id AND w.webhook_type = 'crm'
            WHERE DATE(d.created_at) = :yesterday AND w.id IS NULL
            ORDER BY d.amount_uah DESC
            LIMIT 10";

    $examples = $db->fetchAll($sql, ['yesterday' => $yesterday]);

    echo "<h4>Приклади (топ-10 по сумі):</h4>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Час</th><th>Сума</th><th>Source</th><th>Term</th></tr>";

    foreach ($examples as $deal) {
        echo "<tr>";
        echo "<td>{$deal['deal_id']}</td>";
        echo "<td>{$deal['created_at']}</td>";
        echo "<td>" . number_format($deal['amount_uah'], 0) . " UAH</td>";
        echo "<td>{$deal['utm_source']}</td>";
        echo "<td>{$deal['utm_term']}</td>";
        echo "</tr>";
    }

    echo "</table>";
}

echo "</div>";

// ============================================
// КРОК 8: Дублікати deal_id
// ============================================

echo "<div class='test-section'>";
echo "<h2>🔄 КРОК 8: Перевірка дублікатів</h2>";

$sql = "SELECT deal_id, COUNT(*) as count
FROM crm_deals
WHERE DATE(created_at) = :yesterday
GROUP BY deal_id
HAVING count > 1
ORDER BY count DESC
LIMIT 20";

$duplicates = $db->fetchAll($sql, ['yesterday' => $yesterday]);

if (empty($duplicates)) {
    echo "<div class='success'><p>✅ Дублікатів не знайдено!</p></div>";
} else {
    echo "<div class='error'>";
    echo "<p>❌ Знайдено <strong>" . count($duplicates) . "</strong> дублікатів!</p>";
    echo "</div>";

    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Кількість записів</th></tr>";

    foreach ($duplicates as $dup) {
        echo "<tr>";
        echo "<td><a href='https://dreamcar.sendpulse.com/messengers/deals/{$dup['deal_id']}' target='_blank'>{$dup['deal_id']}</a></td>";
        echo "<td style='color: red;'><strong>{$dup['count']}</strong></td>";
        echo "</tr>";
    }

    echo "</table>";
}

echo "</div>";

// ============================================
// ПІДСУМОК
// ============================================

echo "<div class='test-section' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;'>";
echo "<h2 style='background: transparent; color: white;'>🎯 ПІДСУМОК ДІАГНОСТИКИ</h2>";

$issues = [];

if (abs($totalDiff) > 1000) {
    $issues[] = "Розбіжність сум: " . number_format(abs($totalDiff), 0) . " UAH";
}

if ($totalFailed > 0) {
    $issues[] = "$totalFailed помилок в webhooks";
}

if ($dealsWithoutWebhook['count'] > 0) {
    $issues[] = "{$dealsWithoutWebhook['count']} сделок без webhook";
}

if (!empty($duplicates)) {
    $issues[] = count($duplicates) . " дублікатів deal_id";
}

if (empty($issues)) {
    echo "<div style='background: #10b981; padding: 25px; margin-top: 20px; border-radius: 8px; text-align: center;'>";
    echo "<h2 style='margin: 0; background: transparent; color: white;'>🎉 ВСЕ ДОБРЕ!</h2>";
    echo "<p style='margin: 15px 0 0 0; font-size: 18px;'>Дані SendPulse та Dashboard співпадають!</p>";
    echo "</div>";
} else {
    echo "<div style='background: #ef4444; padding: 25px; margin-top: 20px; border-radius: 8px;'>";
    echo "<h2 style='margin: 0; background: transparent; color: white;'>⚠️ ЗНАЙДЕНІ ПРОБЛЕМИ:</h2>";
    echo "<ul style='font-size: 16px; margin-top: 15px;'>";
    foreach ($issues as $issue) {
        echo "<li>$issue</li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "</div>";

echo "<p style='text-align: center; margin-top: 30px;'>";
echo "<a href='webhook_logs.php' style='padding: 15px 30px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: bold; margin-right: 10px;'>📝 Webhook Логи</a>";
echo "<a href='index.php' style='padding: 15px 30px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: bold;'>→ Dashboard</a>";
echo "</p>";
?>
