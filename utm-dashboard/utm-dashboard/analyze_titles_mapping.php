<?php
require_once 'config/app_config.php';
require_once 'core/Database.php';

$db = Database::getInstance();
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo "<h1>🔍 Аналіз маппінгу titles → статуси</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
th { background: #3b82f6; color: white; }
.error { background: #fee; }
.success { background: #efe; }
pre { background: #f5f5f5; padding: 10px; font-size: 12px; }
</style>";

// Знайти приклади для кожного title
$titles = ['new', 'pay', 'fail'];

foreach ($titles as $title) {
    echo "<h2>Title: '<strong>$title</strong>'</h2>";

    // Знайти webhooks з цим title
    $sql = "SELECT w.deal_id, w.raw_data, w.created_at,
            d.is_paid, d.is_failed, d.is_pending, d.amount_uah
            FROM webhook_log w
            LEFT JOIN crm_deals d ON w.deal_id = d.deal_id
            WHERE DATE(w.created_at) = :yesterday
              AND w.webhook_type = 'crm'
            LIMIT 3000";

    $webhooks = $db->fetchAll($sql, ['yesterday' => $yesterday]);

    $examples = [];
    $countByTitle = 0;

    foreach ($webhooks as $webhook) {
        $data = json_decode($webhook['raw_data'], true);
        if (isset($data['title']) && $data['title'] === $title) {
            $examples[] = $webhook;
            $countByTitle++;
            if (count($examples) >= 3) break; // Взяти 3 приклади
        }
    }

    echo "<p>Знайдено за вчора: <strong>$countByTitle</strong> webhooks</p>";

    if (!empty($examples)) {
        echo "<table>";
        echo "<tr><th>Deal ID</th><th>stepName_deal</th><th>is_paid</th><th>is_failed</th><th>is_pending</th><th>amount_uah</th></tr>";

        foreach ($examples as $ex) {
            $data = json_decode($ex['raw_data'], true);
            $stepName = $data['variables']['stepName_deal'] ?? '—';

            $rowClass = '';
            if ($ex['is_failed'] == 1) $rowClass = "class='error'";
            elseif ($ex['is_paid'] == 1) $rowClass = "class='success'";

            echo "<tr $rowClass>";
            echo "<td><strong>{$ex['deal_id']}</strong></td>";
            echo "<td><code>$stepName</code></td>";
            echo "<td>{$ex['is_paid']}</td>";
            echo "<td>{$ex['is_failed']}</td>";
            echo "<td>{$ex['is_pending']}</td>";
            echo "<td>" . number_format($ex['amount_uah'], 0) . " UAH</td>";
            echo "</tr>";
        }

        echo "</table>";
    }
}

// Підсумок
echo "<hr>";
echo "<h2>📊 Статистика по titles:</h2>";

$sql = "SELECT
    JSON_UNQUOTE(JSON_EXTRACT(w.raw_data, '$.title')) as title,
    COUNT(*) as total,
    SUM(CASE WHEN d.is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN d.is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
    SUM(CASE WHEN d.is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
    SUM(d.amount_uah) as total_amount
FROM webhook_log w
LEFT JOIN crm_deals d ON w.deal_id = d.deal_id
WHERE DATE(w.created_at) = :yesterday
  AND w.webhook_type = 'crm'
GROUP BY title
ORDER BY total DESC";

$summary = $db->fetchAll($sql, ['yesterday' => $yesterday]);

echo "<table>";
echo "<tr><th>Title</th><th>Всього</th><th>is_paid</th><th>is_failed</th><th>is_pending</th><th>Сума</th></tr>";

foreach ($summary as $row) {
    echo "<tr>";
    echo "<td><strong>{$row['title']}</strong></td>";
    echo "<td>{$row['total']}</td>";
    echo "<td style='color: green;'>{$row['paid_count']}</td>";
    echo "<td style='color: red;'>{$row['failed_count']}</td>";
    echo "<td style='color: orange;'>{$row['pending_count']}</td>";
    echo "<td><strong>" . number_format($row['total_amount'], 0) . " UAH</strong></td>";
    echo "</tr>";
}

echo "</table>";

echo "<h3>💡 Висновок:</h3>";
echo "<p>Перевір чи правильний маппінг в webhook_crm.php:</p>";
echo "<pre>
if (\$title === 'new') {
    \$isPending = true;  // ← що має бути?
} elseif (\$title === 'pay') {
    \$isPaid = true;     // ✅ правильно
} elseif (\$title === 'fail') {
    \$isFailed = true;   // ← що має бути?
}
</pre>";
?>
