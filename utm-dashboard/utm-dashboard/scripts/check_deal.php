<?php
// === check_deal.php ===
// НАЗНАЧЕНИЕ: Проверить конкретную сделку на дубли
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер с параметром ?deal_id=11204917

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';

$dealId = $_GET['deal_id'] ?? null;

if (!$dealId) {
    die('❌ Укажите deal_id в параметре URL: ?deal_id=11204917');
}

$db = Database::getInstance();

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Проверка сделки {$dealId}</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1400px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        .info { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 13px; }
        th { background: #f3f4f6; font-weight: bold; }
        .diff { background: #fef3c7; }
    </style>
</head>
<body>";

echo "<h1>🔍 Проверка сделки deal_id = {$dealId}</h1>";

// 1. Проверить количество записей с этим deal_id
$sql = "SELECT COUNT(*) as cnt FROM crm_deals WHERE deal_id = :deal_id";
$result = $db->fetchOne($sql, ['deal_id' => $dealId]);
$count = (int)$result['cnt'];

echo "<h2>1️⃣ Количество записей</h2>";
echo "<p class='info'>Записей с deal_id = <strong>{$dealId}</strong>: <span class='warning'>{$count}</span></p>";

if ($count === 0) {
    echo "<p class='error'>❌ Сделка не найдена в базе!</p>";
    echo "</body></html>";
    exit;
} elseif ($count === 1) {
    echo "<p class='success'>✅ Дублей НЕТ (правильно!)</p>";
} else {
    echo "<p class='error'>❌ НАЙДЕНЫ ДУБЛИ! В базе $count записей с одинаковым deal_id!</p>";
}

// 2. Показать все записи
$sql = "SELECT * FROM crm_deals WHERE deal_id = :deal_id ORDER BY imported_at DESC";
$deals = $db->fetchAll($sql, ['deal_id' => $dealId]);

echo "<h2>2️⃣ Все записи с deal_id = {$dealId}</h2>";
echo "<table>";
echo "<tr>
    <th>ID</th>
    <th>deal_id</th>
    <th>Email</th>
    <th>Phone</th>
    <th>Full Name</th>
    <th>Created At</th>
    <th>Deal Type</th>
    <th>is_paid</th>
    <th>is_pending</th>
    <th>Amount UAH</th>
    <th>Deal Step</th>
    <th>UTM Source</th>
    <th>UTM Medium</th>
    <th>Imported At</th>
    <th>Updated At</th>
</tr>";

foreach ($deals as $deal) {
    echo "<tr>";
    echo "<td>{$deal['id']}</td>";
    echo "<td>{$deal['deal_id']}</td>";
    echo "<td>{$deal['email']}</td>";
    echo "<td>{$deal['phone']}</td>";
    echo "<td>{$deal['full_name']}</td>";
    echo "<td>{$deal['created_at']}</td>";
    echo "<td>{$deal['deal_type']}</td>";
    echo "<td>" . ($deal['is_paid'] ? '✅' : '❌') . "</td>";
    echo "<td>" . ($deal['is_pending'] ? '✅' : '❌') . "</td>";
    echo "<td>{$deal['amount_uah']}</td>";
    echo "<td>{$deal['deal_step']}</td>";
    echo "<td>{$deal['utm_source']}</td>";
    echo "<td>{$deal['utm_medium']}</td>";
    echo "<td>{$deal['imported_at']}</td>";
    echo "<td>{$deal['updated_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// 3. Если есть дубли - показать различия
if ($count > 1) {
    echo "<h2>3️⃣ Анализ различий между дублями</h2>";

    $first = $deals[0];
    $differences = [];

    foreach ($deals as $idx => $deal) {
        if ($idx === 0) continue;

        foreach ($deal as $key => $value) {
            if ($first[$key] !== $value && !in_array($key, ['id', 'imported_at', 'updated_at'])) {
                $differences[$key][] = [
                    'record_id' => $deal['id'],
                    'value' => $value
                ];
            }
        }
    }

    if (empty($differences)) {
        echo "<p class='info'>Дубли полностью идентичны (отличаются только id, imported_at, updated_at)</p>";
    } else {
        echo "<p class='warning'>Найдены различия в полях:</p>";
        echo "<table>";
        echo "<tr><th>Поле</th><th>Различия</th></tr>";
        foreach ($differences as $field => $diffs) {
            echo "<tr class='diff'>";
            echo "<td><strong>{$field}</strong></td>";
            echo "<td>";
            foreach ($diffs as $diff) {
                echo "Record #{$diff['record_id']}: {$diff['value']}<br>";
            }
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

// 4. История webhook для этой сделки
echo "<h2>4️⃣ История webhook событий</h2>";
$sql = "SELECT * FROM webhook_log WHERE deal_id = :deal_id ORDER BY created_at DESC";
$webhooks = $db->fetchAll($sql, ['deal_id' => $dealId]);

if (empty($webhooks)) {
    echo "<p class='info'>Webhook событий для этой сделки не найдено</p>";
} else {
    echo "<table>";
    echo "<tr><th>ID</th><th>Тип</th><th>Event Type</th><th>Success</th><th>Время</th><th>Created At</th></tr>";
    foreach ($webhooks as $wh) {
        echo "<tr>";
        echo "<td>{$wh['id']}</td>";
        echo "<td>{$wh['webhook_type']}</td>";
        echo "<td>{$wh['event_type']}</td>";
        echo "<td>" . ($wh['success'] ? '✅' : '❌') . "</td>";
        echo "<td>{$wh['processing_time']} сек</td>";
        echo "<td>{$wh['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
echo "</body></html>";
?>
