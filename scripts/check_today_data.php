<?php
// === check_today_data.php ===
// НАЗНАЧЕНИЕ: Проверить данные за сегодня
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
    <title>Проверка данных за сегодня</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .info { color: #3b82f6; }
        pre { background: #f3f4f6; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>";

echo "<h1>🔍 Проверка данных за сегодня</h1>";

// Получить сегодняшнюю дату
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd = $today . ' 23:59:59';

echo "<p><strong>📅 Сегодня:</strong> $today</p>";
echo "<p><strong>🕐 Период:</strong> с $todayStart до $todayEnd</p>";
echo "<hr>";

// 1. Проверить сколько всего записей за сегодня
echo "<h2>1️⃣ Общая статистика за сегодня</h2>";

$sql = "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
    SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
    SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount,
    SUM(CASE WHEN is_pending = 1 THEN amount_uah ELSE 0 END) as pending_amount,
    MIN(created_at) as first_record,
    MAX(created_at) as last_record
FROM crm_deals
WHERE created_at >= :start AND created_at <= :end";

$stmt = $pdo->prepare($sql);
$stmt->execute(['start' => $todayStart, 'end' => $todayEnd]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Показатель</th><th>Значение</th></tr>";
echo "<tr><td>📊 Всего записей</td><td class='info'><strong>" . $stats['total'] . "</strong></td></tr>";
echo "<tr><td>⏳ Pending (ожидают)</td><td class='warning'>" . $stats['pending_count'] . "</td></tr>";
echo "<tr><td>✅ Paid (оплачено)</td><td class='success'>" . $stats['paid_count'] . "</td></tr>";
echo "<tr><td>❌ Failed (отклонено)</td><td class='error'>" . $stats['failed_count'] . "</td></tr>";
echo "<tr><td>💰 Сумма оплаченных</td><td class='success'><strong>" . number_format($stats['paid_amount'], 2) . " UAH</strong></td></tr>";
echo "<tr><td>💸 Сумма отклоненных</td><td class='error'>" . number_format($stats['failed_amount'], 2) . " UAH</td></tr>";
echo "<tr><td>⏳ Сумма ожидающих</td><td class='warning'>" . number_format($stats['pending_amount'], 2) . " UAH</td></tr>";
echo "<tr><td>🕐 Первая запись</td><td>" . $stats['first_record'] . "</td></tr>";
echo "<tr><td>🕐 Последняя запись</td><td>" . $stats['last_record'] . "</td></tr>";
echo "</table>";

// 2. Показать первые 10 записей за сегодня
echo "<h2>2️⃣ Последние 10 записей за сегодня</h2>";

$sql = "SELECT
    id, deal_id, email,
    created_at,
    is_pending, is_paid, is_failed,
    amount_uah,
    utm_source, utm_medium, utm_campaign
FROM crm_deals
WHERE created_at >= :start AND created_at <= :end
ORDER BY created_at DESC
LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute(['start' => $todayStart, 'end' => $todayEnd]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($records) > 0) {
    echo "<table>";
    echo "<tr>
        <th>ID</th>
        <th>Deal ID</th>
        <th>Email</th>
        <th>Дата</th>
        <th>Статус</th>
        <th>Сумма UAH</th>
        <th>UTM Source</th>
        <th>UTM Campaign</th>
    </tr>";

    foreach ($records as $row) {
        $status = '';
        $statusClass = '';
        if ($row['is_paid'] == 1) {
            $status = '✅ Paid';
            $statusClass = 'success';
        } elseif ($row['is_failed'] == 1) {
            $status = '❌ Failed';
            $statusClass = 'error';
        } elseif ($row['is_pending'] == 1) {
            $status = '⏳ Pending';
            $statusClass = 'warning';
        }

        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['deal_id']}</td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "<td class='$statusClass'><strong>$status</strong></td>";
        echo "<td>" . number_format($row['amount_uah'], 2) . "</td>";
        echo "<td>{$row['utm_source']}</td>";
        echo "<td>{$row['utm_campaign']}</td>";
        echo "</tr>";
    }

    echo "</table>";
} else {
    echo "<p class='warning'>⚠️ Нет записей за сегодня</p>";
}

// 3. Проверить рекламные расходы за сегодня
echo "<h2>3️⃣ Рекламные расходы за сегодня</h2>";

$sql = "SELECT
    COUNT(*) as total_records,
    SUM(spend) as total_spend,
    SUM(clicks) as total_clicks,
    SUM(impressions) as total_impressions
FROM ads_data
WHERE date_start = :today";

$stmt = $pdo->prepare($sql);
$stmt->execute(['today' => $today]);
$adsStats = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Показатель</th><th>Значение</th></tr>";
echo "<tr><td>📊 Записей в ads_data</td><td>" . $adsStats['total_records'] . "</td></tr>";
echo "<tr><td>💸 Всего потрачено</td><td><strong>" . number_format($adsStats['total_spend'], 2) . " USD</strong></td></tr>";
echo "<tr><td>👆 Кликов</td><td>" . number_format($adsStats['total_clicks'], 0) . "</td></tr>";
echo "<tr><td>👁️ Показов</td><td>" . number_format($adsStats['total_impressions'], 0) . "</td></tr>";
echo "</table>";

// 4. Показать проблемные записи (если есть)
echo "<h2>4️⃣ Проверка проблемных записей</h2>";

// Записи с нулевой суммой но статусом paid
$sql = "SELECT COUNT(*) as count FROM crm_deals
WHERE created_at >= :start AND created_at <= :end
AND is_paid = 1 AND (amount_uah = 0 OR amount_uah IS NULL)";
$stmt = $pdo->prepare($sql);
$stmt->execute(['start' => $todayStart, 'end' => $todayEnd]);
$zeroPaid = $stmt->fetchColumn();

// Записи с пустыми UTM
$sql = "SELECT COUNT(*) as count FROM crm_deals
WHERE created_at >= :start AND created_at <= :end
AND (utm_source IS NULL OR utm_source = '')";
$stmt = $pdo->prepare($sql);
$stmt->execute(['start' => $todayStart, 'end' => $todayEnd]);
$emptyUtm = $stmt->fetchColumn();

echo "<table>";
echo "<tr><th>Проблема</th><th>Количество</th></tr>";
echo "<tr><td>⚠️ Оплаченные с нулевой суммой</td><td class='" . ($zeroPaid > 0 ? 'error' : 'success') . "'>" . $zeroPaid . "</td></tr>";
echo "<tr><td>⚠️ Пустые UTM метки</td><td class='" . ($emptyUtm > 0 ? 'warning' : 'success') . "'>" . $emptyUtm . "</td></tr>";
echo "</table>";

if ($zeroPaid > 0) {
    echo "<p class='error'>❌ Найдены оплаченные записи с нулевой суммой! Это может быть причиной нулевого дохода.</p>";
}

echo "<hr>";
echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
echo "<p><a href='verify_data_integrity.php'>🔍 Полная проверка целостности данных</a></p>";

echo "</body></html>";
