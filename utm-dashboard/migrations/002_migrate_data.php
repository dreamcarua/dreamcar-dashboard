<?php
// === 002_migrate_data.php ===
// НАЗНАЧЕНИЕ: Перенос схемы и данных из старой БД в новую (точная копия)
// МЕТОД: SHOW CREATE TABLE → CREATE TABLE + батчевый SELECT/INSERT
// УДАЛИТЬ после успешного переезда!

set_time_limit(3600);
ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

// Параметр: какую таблицу мигрировать (или all для всех)
$targetTable = $_GET['table'] ?? 'all';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Миграция данных</title></head><body>";
echo "<h1>Миграция данных: старая БД → новая БД</h1>";
echo "<p>Режим: <strong>" . htmlspecialchars($targetTable) . "</strong></p>";

// Flush output для прогресса в реальном времени
if (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

// === ПОДКЛЮЧЕНИЯ ===

// Старая БД (serflow.mysql.tools)
$oldDsn = "mysql:host=serflow.mysql.tools;dbname=serflow_analdream;charset=utf8mb4";
$oldOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
];

// Новая БД (fincheck.mysql.network)
$newDsn = "mysql:host=fincheck.mysql.network;port=10145;dbname=dreamcar_utm;charset=utf8mb4";
$newOptions = $oldOptions;

try {
    echo "<h2>Подключение к базам данных...</h2>";

    $oldPdo = new PDO($oldDsn, 'serflow_analdream', 'B8g&4d+s9Z', $oldOptions);
    echo "<p style='color:green;'>Старая БД (serflow.mysql.tools): OK</p>";

    $newPdo = new PDO($newDsn, 'dreamcar_utm', getenv('DB_PASS') ?: '', $newOptions);
    echo "<p style='color:green;'>Новая БД (fincheck.mysql.network:10145): OK</p>";

} catch (PDOException $e) {
    echo "<p style='color:red;'>ОШИБКА ПОДКЛЮЧЕНИЯ: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// === КОНФИГУРАЦИЯ БАТЧЕЙ ===
$batchConfig = [
    'webhook_log'         => 100,   // 198 MB, MEDIUMTEXT — маленькие батчи
    'crm_deals'           => 500,   // 134 MB, 149K записей
    'ads_data'            => 1000,  // 21 MB
    'utm_mapping'         => 10000, // 2 записи — все за раз
    'utm_crm_ads_mapping' => 10000, // 3 записи — все за раз
    'import_log'          => 10000, // 0 записей
];

// Порядок миграции (маленькие таблицы сначала)
$tableOrder = ['utm_mapping', 'utm_crm_ads_mapping', 'import_log', 'ads_data', 'crm_deals', 'webhook_log'];

// === ПОЛУЧИТЬ СПИСОК ТАБЛИЦ ИЗ СТАРОЙ БД ===
$stmt = $oldPdo->query("SHOW TABLES");
$existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "<p>Таблиц в старой БД: <strong>" . count($existingTables) . "</strong> (" . implode(', ', $existingTables) . ")</p>";

// Определить какие таблицы мигрировать
if ($targetTable === 'all') {
    $tablesToMigrate = $tableOrder;
} else {
    if (!in_array($targetTable, $existingTables)) {
        echo "<p style='color:red;'>Таблица '{$targetTable}' не найдена!</p>";
        exit;
    }
    $tablesToMigrate = [$targetTable];
}

$totalStartTime = microtime(true);
$results = [];

foreach ($tablesToMigrate as $table) {
    if (!in_array($table, $existingTables)) {
        echo "<p style='color:orange;'>Таблица '{$table}' не найдена в старой БД, пропускаю</p>";
        continue;
    }

    $tableStartTime = microtime(true);
    echo "<hr>";
    echo "<h2>Таблица: {$table}</h2>";

    // === ФАЗА A: КОПИРОВАНИЕ СХЕМЫ ===
    echo "<h3>Фаза A: Копирование схемы...</h3>";

    try {
        $stmt = $oldPdo->query("SHOW CREATE TABLE `{$table}`");
        $createTableRow = $stmt->fetch();
        $createSQL = $createTableRow['Create Table'];

        // Проверить существует ли таблица в новой БД
        $checkStmt = $newPdo->query("SHOW TABLES LIKE '{$table}'");
        $tableExists = $checkStmt->fetch();

        if ($tableExists) {
            echo "<p style='color:orange;'>Таблица уже существует в новой БД (пропускаю создание)</p>";
        } else {
            $newPdo->exec($createSQL);
            echo "<p style='color:green;'>Таблица создана</p>";
        }

        // Показать количество индексов
        $idxStmt = $oldPdo->query("SHOW INDEX FROM `{$table}`");
        $indexes = $idxStmt->fetchAll();
        $uniqueIndexNames = array_unique(array_column($indexes, 'Key_name'));
        echo "<p>Индексов: <strong>" . count($uniqueIndexNames) . "</strong> (" . implode(', ', $uniqueIndexNames) . ")</p>";

    } catch (PDOException $e) {
        echo "<p style='color:red;'>ОШИБКА создания схемы: " . htmlspecialchars($e->getMessage()) . "</p>";
        $results[$table] = ['status' => 'SCHEMA_ERROR', 'error' => $e->getMessage()];
        continue;
    }

    // === ФАЗА B: КОПИРОВАНИЕ ДАННЫХ ===
    echo "<h3>Фаза B: Копирование данных...</h3>";

    try {
        // Подсчет строк в старой БД
        $countStmt = $oldPdo->query("SELECT COUNT(*) FROM `{$table}`");
        $totalRows = (int)$countStmt->fetchColumn();
        echo "<p>Строк в старой БД: <strong>" . number_format($totalRows) . "</strong></p>";

        if ($totalRows === 0) {
            echo "<p style='color:green;'>Таблица пустая, пропускаю копирование данных</p>";
            $results[$table] = ['status' => 'OK', 'rows_old' => 0, 'rows_new' => 0];
            continue;
        }

        // Подсчет уже перенесенных строк в новой БД
        $newCountStmt = $newPdo->query("SELECT COUNT(*) FROM `{$table}`");
        $existingRows = (int)$newCountStmt->fetchColumn();

        if ($existingRows >= $totalRows) {
            echo "<p style='color:green;'>Данные уже перенесены ({$existingRows} строк). Пропускаю.</p>";
            $results[$table] = ['status' => 'OK', 'rows_old' => $totalRows, 'rows_new' => $existingRows];
            continue;
        }

        if ($existingRows > 0) {
            echo "<p style='color:orange;'>Частично перенесено: {$existingRows}/{$totalRows}. Продолжаю с INSERT IGNORE.</p>";
        }

        // Получить колонки таблицы
        $colStmt = $oldPdo->query("SHOW COLUMNS FROM `{$table}`");
        $columns = $colStmt->fetchAll();
        $columnNames = array_column($columns, 'Field');
        $columnsStr = '`' . implode('`, `', $columnNames) . '`';
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columnNames), '?')) . ')';

        // Батчевое копирование
        $batchSize = $batchConfig[$table] ?? 500;
        $offset = 0;
        $copiedRows = 0;
        $batchNum = 0;

        while ($offset < $totalRows) {
            $batchNum++;
            $limit = (int)$batchSize;
            $offsetInt = (int)$offset;

            // SELECT батч из старой БД
            $selectSQL = "SELECT * FROM `{$table}` ORDER BY id LIMIT {$limit} OFFSET {$offsetInt}";
            $selectStmt = $oldPdo->query($selectSQL);
            $rows = $selectStmt->fetchAll();

            if (empty($rows)) {
                break;
            }

            // Построить multi-row INSERT IGNORE
            $placeholders = implode(', ', array_fill(0, count($rows), $placeholderRow));
            $insertSQL = "INSERT IGNORE INTO `{$table}` ({$columnsStr}) VALUES {$placeholders}";

            // Собрать параметры
            $params = [];
            foreach ($rows as $row) {
                foreach ($columnNames as $col) {
                    $params[] = $row[$col];
                }
            }

            // Выполнить INSERT
            $insertStmt = $newPdo->prepare($insertSQL);
            $insertStmt->execute($params);
            $inserted = $insertStmt->rowCount();
            $copiedRows += $inserted;

            $offset += count($rows);
            $percent = round(($offset / $totalRows) * 100, 1);
            echo "<p>[Батч {$batchNum}] {$table}: " . number_format($offset) . "/" . number_format($totalRows) . " ({$percent}%) — вставлено {$inserted} из " . count($rows) . "</p>\n";

            // Освободить память
            unset($rows, $params, $insertStmt);
        }

        // Финальная проверка
        $finalCountStmt = $newPdo->query("SELECT COUNT(*) FROM `{$table}`");
        $finalCount = (int)$finalCountStmt->fetchColumn();

        echo "<p style='color:green;'>Копирование завершено. Старая: " . number_format($totalRows) . ", Новая: " . number_format($finalCount) . "</p>";

        if ($finalCount === $totalRows) {
            echo "<p style='color:green; font-weight:bold;'>СОВПАДЕНИЕ ПОЛНОЕ</p>";
            $results[$table] = ['status' => 'OK', 'rows_old' => $totalRows, 'rows_new' => $finalCount];
        } else {
            echo "<p style='color:red; font-weight:bold;'>РАСХОЖДЕНИЕ! Старая: {$totalRows}, Новая: {$finalCount}</p>";
            $results[$table] = ['status' => 'MISMATCH', 'rows_old' => $totalRows, 'rows_new' => $finalCount];
        }

    } catch (PDOException $e) {
        echo "<p style='color:red;'>ОШИБКА копирования данных: " . htmlspecialchars($e->getMessage()) . "</p>";
        $results[$table] = ['status' => 'DATA_ERROR', 'error' => $e->getMessage()];
    }

    $tableTime = round(microtime(true) - $tableStartTime, 2);
    echo "<p>Время: <strong>{$tableTime} сек</strong></p>";
}

// === ИТОГОВЫЙ ОТЧЕТ ===
$totalTime = round(microtime(true) - $totalStartTime, 2);

echo "<hr>";
echo "<h2>ИТОГОВЫЙ ОТЧЕТ</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
echo "<tr style='background:#e5e7eb;'><th>Таблица</th><th>Статус</th><th>Строк (старая)</th><th>Строк (новая)</th></tr>";

$allOk = true;
foreach ($results as $tbl => $res) {
    $color = ($res['status'] === 'OK') ? 'green' : 'red';
    $allOk = $allOk && ($res['status'] === 'OK');
    echo "<tr>";
    echo "<td>{$tbl}</td>";
    echo "<td style='color:{$color};'>{$res['status']}</td>";
    echo "<td>" . number_format($res['rows_old'] ?? 0) . "</td>";
    echo "<td>" . number_format($res['rows_new'] ?? 0) . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p>Общее время: <strong>{$totalTime} сек</strong></p>";

if ($allOk) {
    echo "<h2 style='color:green;'>ВСЕ ТАБЛИЦЫ ПЕРЕНЕСЕНЫ УСПЕШНО!</h2>";
    echo "<p>Можно запускать верификацию: <a href='003_verify_migration.php'>003_verify_migration.php</a></p>";
} else {
    echo "<h2 style='color:red;'>ЕСТЬ ОШИБКИ! Проверь отчет выше.</h2>";
}

echo "</body></html>";
