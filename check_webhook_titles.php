<?php
/**
 * Перевірити які title приходять з SendPulse webhooks
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>📡 Аналіз webhook titles</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
th { background: #3b82f6; color: white; }
pre { background: #f5f5f5; padding: 10px; font-size: 12px; }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));
$db = Database::getInstance();

// Знайти всі унікальні title
$sql = "SELECT
    JSON_EXTRACT(raw_data, '$.title') as title,
    COUNT(*) as count,
    MIN(deal_id) as example_deal_id
FROM webhook_log
WHERE DATE(created_at) = :yesterday
  AND webhook_type = 'crm'
GROUP BY title
ORDER BY count DESC";

$titles = $db->fetchAll($sql, ['yesterday' => $yesterday]);

echo "<h2>Унікальні titles з webhooks:</h2>";
echo "<table>";
echo "<tr><th>Title</th><th>Кількість</th><th>Приклад Deal ID</th></tr>";

foreach ($titles as $row) {
    $title = trim($row['title'], '"');

    echo "<tr>";
    echo "<td><strong>$title</strong></td>";
    echo "<td>{$row['count']}</td>";
    echo "<td><a href='#' onclick='showWebhookExample({$row['example_deal_id']}, \"$title\"); return false;'>{$row['example_deal_id']}</a></td>";
    echo "</tr>";
}

echo "</table>";

// Показати приклад для кожного title
echo "<h2>Приклади webhooks:</h2>";

foreach ($titles as $row) {
    $title = trim($row['title'], '"');

    echo "<h3>Title: <code>$title</code></h3>";

    $sql = "SELECT raw_data, deal_id
            FROM webhook_log
            WHERE DATE(created_at) = :yesterday
              AND webhook_type = 'crm'
              AND JSON_EXTRACT(raw_data, '$.title') = :title
            LIMIT 1";

    // Простіше - шукати по LIKE
    $sqlSimple = "SELECT raw_data, deal_id
                  FROM webhook_log
                  WHERE DATE(created_at) = :yesterday
                    AND webhook_type = 'crm'
                    AND raw_data LIKE :title_pattern
                  LIMIT 1";

    $example = $db->fetchOne($sqlSimple, [
        'yesterday' => $yesterday,
        'title_pattern' => '%"title":"' . $title . '"%'
    ]);

    if ($example) {
        $data = json_decode($example['raw_data'], true);

        echo "<p>Deal ID: <strong>{$example['deal_id']}</strong></p>";

        // Показати важливі поля
        echo "<table style='max-width: 800px;'>";
        echo "<tr><th>Поле</th><th>Значення</th></tr>";
        echo "<tr><td><strong>title</strong></td><td><strong style='color: #3b82f6;'>{$data['title']}</strong></td></tr>";
        echo "<tr><td>stepName_deal</td><td><code>" . ($data['variables']['stepName_deal'] ?? '—') . "</code></td></tr>";
        echo "<tr><td>status_deal</td><td><code>" . ($data['variables']['status_deal'] ?? '—') . "</code></td></tr>";
        echo "<tr><td>step_deal</td><td><code>" . ($data['variables']['step_deal'] ?? '—') . "</code></td></tr>";
        echo "<tr><td>price_deal</td><td><strong>" . ($data['variables']['price_deal'] ?? '—') . " UAH</strong></td></tr>";
        echo "</table>";

        // Показати mapping в БД
        $sqlDeal = "SELECT is_paid, is_failed, is_pending, amount_uah
                    FROM crm_deals
                    WHERE deal_id = :deal_id";

        $dealInfo = $db->fetchOne($sqlDeal, ['deal_id' => $example['deal_id']]);

        if ($dealInfo) {
            echo "<p><strong>Як збережено в БД:</strong></p>";
            echo "<table style='max-width: 400px;'>";
            echo "<tr><th>Поле</th><th>Значення</th></tr>";
            echo "<tr><td>is_paid</td><td>{$dealInfo['is_paid']}</td></tr>";
            echo "<tr><td>is_failed</td><td>{$dealInfo['is_failed']}</td></tr>";
            echo "<tr><td>is_pending</td><td>{$dealInfo['is_pending']}</td></tr>";
            echo "<tr><td>amount_uah</td><td>{$dealInfo['amount_uah']} UAH</td></tr>";
            echo "</table>";
        }
    } else {
        echo "<p style='color: red;'>❌ Не знайдено приклад для title='$title'</p>";
    }
}

echo "<hr>";
echo "<h2>💡 Висновок</h2>";
echo "<p>Перевір відповідність:</p>";
echo "<ul>";
echo "<li><strong>new</strong> → що це? (Новые або В работе?)</li>";
echo "<li><strong>pay</strong> → На подпись ✅</li>";
echo "<li><strong>fail</strong> → що це? (Неуспешно або В работе?)</li>";
echo "</ul>";
?>
