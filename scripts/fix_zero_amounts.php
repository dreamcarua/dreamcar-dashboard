<?php
// === fix_zero_amounts.php ===
// НАЗНАЧЕНИЕ: Восстановить суммы для записей со статусом paid но нулевой суммой
// ПРОБЛЕМА: При событии pay webhook не передает суммы, и они обнуляются
// РЕШЕНИЕ: Использовать webhook_log для восстановления сумм из события new

ini_set('max_execution_time', 300);
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
    <title>Восстановление сумм</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .warning { color: #f59e0b; font-weight: bold; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>";

echo "<h1>🔧 Восстановление сумм для оплаченных записей</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Шаг 1: Найти все записи со статусом paid но нулевой суммой
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Поиск проблемных записей</div>";

    $sql = "SELECT
        id, deal_id, email, created_at, is_paid, amount_uah
    FROM crm_deals
    WHERE is_paid = 1 AND (amount_uah = 0 OR amount_uah IS NULL)
    ORDER BY created_at DESC
    LIMIT 100";

    $stmt = $pdo->query($sql);
    $problems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p class='info'>Найдено записей с нулевой суммой: <strong>" . count($problems) . "</strong></p>";

    if (count($problems) === 0) {
        echo "<p class='success'>✅ Проблемных записей не найдено!</p>";
        echo "</div>";
        echo "</body></html>";
        exit;
    }

    echo "</div>";

    // Шаг 2: Попытаться восстановить суммы из webhook_log
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Восстановление сумм из webhook_log</div>";

    $fixed = 0;
    $notFound = 0;

    echo "<table>";
    echo "<tr>
        <th>Deal ID</th>
        <th>Email</th>
        <th>Статус ДО</th>
        <th>Сумма ДО</th>
        <th>Сумма ПОСЛЕ</th>
        <th>Результат</th>
    </tr>";

    foreach ($problems as $problem) {
        $dealId = $problem['deal_id'];
        $oldAmount = $problem['amount_uah'];

        // Найти webhook запрос для этого deal_id (любой тип - new или pay)
        $sql = "SELECT raw_data, processed_data
        FROM webhook_log
        WHERE deal_id = :deal_id
        AND success = 1
        ORDER BY created_at DESC
        LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['deal_id' => $dealId]);
        $logRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $newAmount = 0;

        if ($logRow) {
            // Сначала пробуем raw_data (там есть price_deal)
            if (!empty($logRow['raw_data'])) {
                $rawData = json_decode($logRow['raw_data'], true);

                // Извлечь price_deal из variables
                $variables = $rawData['variables'] ?? [];
                $newAmount = floatval(
                    $variables['price_deal'] ??
                    $variables['amount_uah'] ??
                    $variables['amount'] ??
                    0
                );
            }

            // Если в raw_data не нашли - пробуем processed_data
            if ($newAmount == 0 && !empty($logRow['processed_data'])) {
                $processedData = json_decode($logRow['processed_data'], true);
                $newAmount = $processedData['amount_uah'] ?? 0;
            }
        }

        if ($logRow && $newAmount > 0) {
            // Обновить сумму в crm_deals
            $updateSql = "UPDATE crm_deals
                SET amount_uah = :amount_uah, amount = :amount
                WHERE deal_id = :deal_id";

            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'amount_uah' => $newAmount,
                'amount' => $newAmount, // Используем ту же сумму
                'deal_id' => $dealId
            ]);

            $fixed++;

            echo "<tr>";
            echo "<td>{$dealId}</td>";
            echo "<td>{$problem['email']}</td>";
            echo "<td class='warning'>paid (0 UAH)</td>";
            echo "<td class='error'>{$oldAmount}</td>";
            echo "<td class='success'>{$newAmount} UAH</td>";
            echo "<td class='success'>✅ Восстановлено</td>";
            echo "</tr>";
        } else if ($logRow) {
            $notFound++;
            echo "<tr>";
            echo "<td>{$dealId}</td>";
            echo "<td>{$problem['email']}</td>";
            echo "<td class='warning'>paid (0 UAH)</td>";
            echo "<td class='error'>{$oldAmount}</td>";
            echo "<td class='error'>0 UAH</td>";
            echo "<td class='warning'>⚠️ Сумма не найдена в логе</td>";
            echo "</tr>";
        } else {
            $notFound++;
            echo "<tr>";
            echo "<td>{$dealId}</td>";
            echo "<td>{$problem['email']}</td>";
            echo "<td class='warning'>paid (0 UAH)</td>";
            echo "<td class='error'>{$oldAmount}</td>";
            echo "<td class='error'>-</td>";
            echo "<td class='warning'>⚠️ Webhook не найден</td>";
            echo "</tr>";
        }
    }

    echo "</table>";
    echo "<p class='success'>✅ Восстановлено записей: <strong>$fixed</strong></p>";
    echo "<p class='warning'>⚠️ Не удалось восстановить: <strong>$notFound</strong></p>";
    echo "</div>";

    // Шаг 3: Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='step'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Всего проблемных записей:</strong> " . count($problems) . "</p>";
    echo "<p><strong>Успешно восстановлено:</strong> <span class='success'>$fixed</span></p>";
    echo "<p><strong>Не удалось восстановить:</strong> <span class='warning'>$notFound</span></p>";
    echo "</div>";

    if ($fixed > 0) {
        $logger->success("Восстановлено сумм: $fixed из " . count($problems));
    }

    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><a href='check_today_data.php'>🔍 Проверить данные за сегодня</a></p>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка восстановления</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка восстановления сумм', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
