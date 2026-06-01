<?php
// === remove_duplicates.php ===
// НАЗНАЧЕНИЕ: Удалить дубликаты по deal_id, оставить последнюю запись
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер

ini_set('max_execution_time', 600);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Удаление дубликатов</title>
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
    </style>
</head>
<body>";

echo "<h1>🗑️ Удаление дубликатов по deal_id</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Шаг 1: Найти дубликаты
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Поиск дубликатов</div>";

    $sql = "SELECT deal_id, COUNT(*) as cnt
    FROM crm_deals
    WHERE deal_id IS NOT NULL
    GROUP BY deal_id
    HAVING cnt > 1
    ORDER BY cnt DESC";

    $stmt = $pdo->query($sql);
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p class='info'>Найдено deal_id с дубликатами: <strong>" . count($duplicates) . "</strong></p>";

    if (count($duplicates) === 0) {
        echo "<p class='success'>✅ Дубликатов не найдено!</p>";
        echo "</div>";
        echo "</body></html>";
        exit;
    }

    // Показать топ-10 дубликатов
    echo "<p class='warning'>Топ-10 самых дублируемых deal_id:</p>";
    echo "<pre>";
    foreach (array_slice($duplicates, 0, 10) as $dup) {
        echo "deal_id: {$dup['deal_id']} - {$dup['cnt']} копий\n";
    }
    echo "</pre>";

    echo "</div>";

    // Шаг 2: Удалить дубликаты, оставить последнюю запись (с максимальным id)
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Удаление дубликатов</div>";

    $deleted = 0;

    // Удалить все записи кроме последней (с максимальным id) для каждого deal_id
    $deleteSql = "DELETE t1 FROM crm_deals t1
    INNER JOIN (
        SELECT deal_id, MAX(id) as max_id
        FROM crm_deals
        WHERE deal_id IS NOT NULL
        GROUP BY deal_id
        HAVING COUNT(*) > 1
    ) t2 ON t1.deal_id = t2.deal_id
    WHERE t1.id < t2.max_id";

    $stmt = $pdo->prepare($deleteSql);
    $stmt->execute();
    $deleted = $stmt->rowCount();

    echo "<p class='success'>✅ Удалено дубликатов: <strong>$deleted</strong></p>";
    echo "</div>";

    // Шаг 3: Проверить результат
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Проверка результата</div>";

    $sql = "SELECT deal_id, COUNT(*) as cnt
    FROM crm_deals
    WHERE deal_id IS NOT NULL
    GROUP BY deal_id
    HAVING cnt > 1";

    $stmt = $pdo->query($sql);
    $remaining = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($remaining) === 0) {
        echo "<p class='success'>✅ Все дубликаты удалены! Каждый deal_id теперь уникален.</p>";
    } else {
        echo "<p class='error'>❌ Остались дубликаты: " . count($remaining) . "</p>";
    }

    // Показать текущую статистику
    $totalSql = "SELECT COUNT(*) as total FROM crm_deals";
    $stmt = $pdo->query($totalSql);
    $total = $stmt->fetchColumn();

    $uniqueSql = "SELECT COUNT(DISTINCT deal_id) as unique_deals FROM crm_deals WHERE deal_id IS NOT NULL";
    $stmt = $pdo->query($uniqueSql);
    $unique = $stmt->fetchColumn();

    echo "<p class='info'><strong>Всего записей в БД:</strong> $total</p>";
    echo "<p class='info'><strong>Уникальных deal_id:</strong> $unique</p>";

    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='step'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Удалено дубликатов:</strong> <span class='success'>$deleted</span></p>";
    echo "<p><strong>Всего записей:</strong> $total</p>";
    echo "<p><strong>Уникальных deal_id:</strong> $unique</p>";
    echo "</div>";

    if ($deleted > 0) {
        $logger->success("Удалено дубликатов: $deleted");
    }

    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><a href='../upload_deals.php'>📤 Загрузить сделки</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка удаления</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка удаления дубликатов', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
