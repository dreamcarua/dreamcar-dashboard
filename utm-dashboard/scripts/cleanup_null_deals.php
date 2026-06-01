<?php
// === cleanup_null_deals.php ===
// НАЗНАЧЕНИЕ: Удалить записи из crm_deals где deal_id = NULL
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер ОДИН РАЗ

ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Очистка записей с NULL deal_id</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .warning { color: #f59e0b; font-weight: bold; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        pre { background: #1f2937; color: #10b981; padding: 15px; border-radius: 8px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>";

echo "<h1>🧹 Очистка записей с NULL deal_id</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Шаг 1: Показать текущее состояние
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Текущее состояние базы данных</div>";

    $sql = "SELECT COUNT(*) as total FROM crm_deals";
    $total = $pdo->query($sql)->fetchColumn();

    $sql = "SELECT COUNT(*) as null_count FROM crm_deals WHERE deal_id IS NULL";
    $nullCount = $pdo->query($sql)->fetchColumn();

    echo "<p class='info'>Всего записей в crm_deals: <strong>$total</strong></p>";
    echo "<p class='warning'>Записей с NULL deal_id: <strong>$nullCount</strong></p>";

    if ($nullCount === 0) {
        echo "<p class='success'>✅ Нет записей с NULL deal_id. Очистка не требуется!</p>";
        echo "</div>";
        echo "</body></html>";
        exit;
    }

    echo "</div>";

    // Шаг 2: Показать примеры записей с NULL
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Примеры записей с NULL deal_id (первые 10)</div>";

    $sql = "SELECT id, email, phone, created_at, deal_pipeline, utm_source
            FROM crm_deals
            WHERE deal_id IS NULL
            LIMIT 10";
    $examples = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo "<table>";
    echo "<tr><th>ID</th><th>Email</th><th>Phone</th><th>Created</th><th>Pipeline</th><th>UTM Source</th></tr>";
    foreach ($examples as $row) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>{$row['phone']}</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "<td>{$row['deal_pipeline']}</td>";
        echo "<td>{$row['utm_source']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "</div>";

    // Шаг 3: Удаление записей
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Удаление записей с NULL deal_id</div>";

    echo "<p class='warning'>⚠️ Удаляю $nullCount записей...</p>";

    $sql = "DELETE FROM crm_deals WHERE deal_id IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $deleted = $stmt->rowCount();

    echo "<p class='success'>✅ Удалено записей: <strong>$deleted</strong></p>";

    echo "</div>";

    // Шаг 4: Проверить результат
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 4: Проверка результата</div>";

    $sql = "SELECT COUNT(*) as total FROM crm_deals";
    $totalAfter = $pdo->query($sql)->fetchColumn();

    $sql = "SELECT COUNT(*) as null_count FROM crm_deals WHERE deal_id IS NULL";
    $nullAfter = $pdo->query($sql)->fetchColumn();

    echo "<p class='info'>Записей в базе после очистки: <strong>$totalAfter</strong></p>";
    echo "<p class='success'>Записей с NULL deal_id: <strong>$nullAfter</strong></p>";

    if ($nullAfter === 0) {
        echo "<p class='success' style='font-size: 1.2em;'>✅ База данных очищена! Все записи с NULL deal_id удалены.</p>";
    } else {
        echo "<p class='error'>❌ Ошибка: остались записи с NULL deal_id!</p>";
    }

    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='step'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Было записей:</strong> $total</p>";
    echo "<p><strong>Удалено:</strong> <span class='error'>$deleted</span></p>";
    echo "<p><strong>Осталось:</strong> <span class='success'>$totalAfter</span></p>";
    echo "</div>";

    $logger->success("Очистка crm_deals: удалено $deleted записей с NULL deal_id");

    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка очистки</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка очистки crm_deals', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
