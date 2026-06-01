<?php
// === generate_deal_ids.php ===
// НАЗНАЧЕНИЕ: Сгенерировать уникальные deal_id для всех записей где он NULL
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
    <title>Генерация deal_id</title>
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

echo "<h1>🔢 Генерация уникальных deal_id</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Шаг 1: Найти записи без deal_id
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Поиск записей без deal_id</div>";

    $sql = "SELECT COUNT(*) as cnt FROM crm_deals WHERE deal_id IS NULL OR deal_id = ''";
    $stmt = $pdo->query($sql);
    $count = $stmt->fetchColumn();

    echo "<p class='info'>Найдено записей без deal_id: <strong>$count</strong></p>";

    if ($count === 0) {
        echo "<p class='success'>✅ Все записи имеют deal_id!</p>";
        echo "</div>";
        echo "</body></html>";
        exit;
    }

    echo "</div>";

    // Шаг 2: Сгенерировать уникальные deal_id
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Генерация deal_id</div>";

    // Получить все записи без deal_id
    $sql = "SELECT id, email, phone, created_at FROM crm_deals WHERE deal_id IS NULL OR deal_id = '' ORDER BY id";
    $stmt = $pdo->query($sql);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $errors = 0;

    echo "<p class='info'>Генерирую deal_id для $count записей...</p>";

    foreach ($records as $record) {
        // Генерировать уникальный deal_id на основе id + email + timestamp
        // Формат: DEAL_[ID]_[HASH]
        $hash = substr(md5($record['id'] . $record['email'] . $record['created_at']), 0, 8);
        $dealId = 'DEAL_' . $record['id'] . '_' . $hash;

        try {
            $updateSql = "UPDATE crm_deals SET deal_id = :deal_id WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'deal_id' => $dealId,
                'id' => $record['id']
            ]);
            $updated++;
        } catch (Exception $e) {
            $errors++;
            echo "<p class='error'>Ошибка для ID {$record['id']}: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }

    echo "<p class='success'>✅ Сгенерировано deal_id: <strong>$updated</strong></p>";
    if ($errors > 0) {
        echo "<p class='error'>❌ Ошибок: <strong>$errors</strong></p>";
    }

    echo "</div>";

    // Шаг 3: Проверить результат
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Проверка результата</div>";

    $sql = "SELECT COUNT(*) as cnt FROM crm_deals WHERE deal_id IS NULL OR deal_id = ''";
    $stmt = $pdo->query($sql);
    $remaining = $stmt->fetchColumn();

    if ($remaining === 0) {
        echo "<p class='success'>✅ Все записи теперь имеют уникальный deal_id!</p>";
    } else {
        echo "<p class='error'>❌ Остались записи без deal_id: $remaining</p>";
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

    // Проверить дубликаты
    $dupSql = "SELECT deal_id, COUNT(*) as cnt
               FROM crm_deals
               WHERE deal_id IS NOT NULL
               GROUP BY deal_id
               HAVING cnt > 1";
    $stmt = $pdo->query($dupSql);
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($duplicates) > 0) {
        echo "<p class='warning'>⚠️ Найдено дубликатов deal_id: " . count($duplicates) . "</p>";
        echo "<p class='info'>Запустите <a href='remove_duplicates.php'>скрипт удаления дубликатов</a></p>";
    } else {
        echo "<p class='success'>✅ Дубликаты не найдены</p>";
    }

    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='step'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Сгенерировано deal_id:</strong> <span class='success'>$updated</span></p>";
    echo "<p><strong>Ошибок:</strong> <span class='error'>$errors</span></p>";
    echo "<p><strong>Всего записей:</strong> $total</p>";
    echo "<p><strong>Уникальных deal_id:</strong> $unique</p>";
    echo "</div>";

    if ($updated > 0) {
        $logger->success("Сгенерировано deal_id: $updated");
    }

    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><a href='remove_duplicates.php'>🗑️ Удалить дубликаты</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка генерации</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка генерации deal_id', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
