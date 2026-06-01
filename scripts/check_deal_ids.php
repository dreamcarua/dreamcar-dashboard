<?php
// === check_deal_ids.php ===
// НАЗНАЧЕНИЕ: Проверить почему deal_id стали NULL
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер

ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';

$db = Database::getInstance();
$pdo = $db->getPDO();

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Проверка deal_id</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .info { color: #6b7280; }
        .warning { color: #f59e0b; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        pre { background: #f3f4f6; padding: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>";

echo "<h1>🔍 Проверка deal_id в базе данных</h1>";

// 1. Общая статистика
echo "<h2>1️⃣ Общая статистика</h2>";

$sql = "SELECT
    COUNT(*) as total,
    COUNT(deal_id) as with_deal_id,
    COUNT(*) - COUNT(deal_id) as without_deal_id
FROM crm_deals";
$stmt = $pdo->query($sql);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Показатель</th><th>Значение</th></tr>";
echo "<tr><td>Всего записей</td><td class='info'><strong>{$stats['total']}</strong></td></tr>";
echo "<tr><td>С deal_id</td><td class='success'><strong>{$stats['with_deal_id']}</strong></td></tr>";
echo "<tr><td>Без deal_id (NULL)</td><td class='error'><strong>{$stats['without_deal_id']}</strong></td></tr>";
echo "</table>";

// 2. Показать примеры записей БЕЗ deal_id
if ($stats['without_deal_id'] > 0) {
    echo "<h2>2️⃣ Примеры записей БЕЗ deal_id (первые 10)</h2>";

    $sql = "SELECT id, email, phone, created_at, deal_name, utm_source, amount_uah, is_paid
    FROM crm_deals
    WHERE deal_id IS NULL
    ORDER BY id DESC
    LIMIT 10";
    $stmt = $pdo->query($sql);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table>";
    echo "<tr>
        <th>ID</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Created At</th>
        <th>Deal Name</th>
        <th>UTM Source</th>
        <th>Amount</th>
        <th>Paid</th>
    </tr>";

    foreach ($records as $row) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>{$row['phone']}</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "<td>{$row['deal_name']}</td>";
        echo "<td>{$row['utm_source']}</td>";
        echo "<td>{$row['amount_uah']}</td>";
        echo "<td>" . ($row['is_paid'] ? 'Да' : 'Нет') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 3. Показать примеры записей С deal_id
if ($stats['with_deal_id'] > 0) {
    echo "<h2>3️⃣ Примеры записей С deal_id (первые 10)</h2>";

    $sql = "SELECT id, deal_id, email, phone, created_at, deal_name, utm_source, amount_uah, is_paid
    FROM crm_deals
    WHERE deal_id IS NOT NULL
    ORDER BY id DESC
    LIMIT 10";
    $stmt = $pdo->query($sql);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table>";
    echo "<tr>
        <th>ID</th>
        <th>Deal ID</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Created At</th>
        <th>Deal Name</th>
        <th>UTM Source</th>
        <th>Amount</th>
        <th>Paid</th>
    </tr>";

    foreach ($records as $row) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td class='success'><strong>{$row['deal_id']}</strong></td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>{$row['phone']}</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "<td>{$row['deal_name']}</td>";
        echo "<td>{$row['utm_source']}</td>";
        echo "<td>{$row['amount_uah']}</td>";
        echo "<td>" . ($row['is_paid'] ? 'Да' : 'Нет') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 4. Проверить webhook_log
echo "<h2>4️⃣ Последние записи в webhook_log</h2>";

$sql = "SELECT id, event_type, deal_id, success, created_at
FROM webhook_log
WHERE webhook_type = 'crm'
ORDER BY created_at DESC
LIMIT 10";
$stmt = $pdo->query($sql);
$webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr>
    <th>ID</th>
    <th>Event Type</th>
    <th>Deal ID</th>
    <th>Success</th>
    <th>Created At</th>
</tr>";

foreach ($webhooks as $row) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['event_type']}</td>";
    echo "<td class='success'><strong>{$row['deal_id']}</strong></td>";
    echo "<td>" . ($row['success'] ? '✅' : '❌') . "</td>";
    echo "<td>{$row['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// 5. Проверить дубликаты deal_id
echo "<h2>5️⃣ Дубликаты deal_id</h2>";

$sql = "SELECT deal_id, COUNT(*) as cnt
FROM crm_deals
WHERE deal_id IS NOT NULL
GROUP BY deal_id
HAVING cnt > 1
ORDER BY cnt DESC
LIMIT 10";
$stmt = $pdo->query($sql);
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($duplicates) > 0) {
    echo "<p class='warning'>⚠️ Найдено дубликатов: <strong>" . count($duplicates) . "</strong></p>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Количество копий</th></tr>";
    foreach ($duplicates as $row) {
        echo "<tr>";
        echo "<td>{$row['deal_id']}</td>";
        echo "<td class='error'><strong>{$row['cnt']}</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='success'>✅ Дубликаты не найдены</p>";
}

echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";

echo "</body></html>";
