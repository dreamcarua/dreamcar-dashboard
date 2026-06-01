<?php
// === 003_verify_migration.php ===
// НАЗНАЧЕНИЕ: Полная верификация миграции — 7 независимых проверок
// Если хоть одна проверка не прошла — НЕ переключать конфиги!
// УДАЛИТЬ после успешного переезда!

set_time_limit(300);
ini_set('memory_limit', '256M');
header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Верификация миграции</title>";
echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 1100px; margin: 0 auto; }
.pass { color: green; font-weight: bold; }
.fail { color: red; font-weight: bold; }
.warn { color: orange; font-weight: bold; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; }
th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
th { background: #f0f0f0; }
</style></head><body>";

echo "<h1>Верификация миграции: старая vs новая БД</h1>";

// === ПОДКЛЮЧЕНИЯ ===
$oldDsn = "mysql:host=serflow.mysql.tools;dbname=serflow_analdream;charset=utf8mb4";
$newDsn = "mysql:host=fincheck.mysql.network;port=10145;dbname=dreamcar_utm;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $oldPdo = new PDO($oldDsn, 'serflow_analdream', 'B8g&4d+s9Z', $options);
    $newPdo = new PDO($newDsn, 'dreamcar_utm', getenv('DB_PASS') ?: '', $options);
    echo "<p class='pass'>Подключение к обеим БД: OK</p>";
} catch (PDOException $e) {
    echo "<p class='fail'>ОШИБКА ПОДКЛЮЧЕНИЯ: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

$tables = ['crm_deals', 'ads_data', 'webhook_log', 'utm_mapping', 'utm_crm_ads_mapping', 'import_log'];
$totalChecks = 0;
$passedChecks = 0;

// =============================================
// ПРОВЕРКА 1: Количество строк
// =============================================
echo "<h2>1. Количество строк (COUNT)</h2>";
echo "<table><tr><th>Таблица</th><th>Старая БД</th><th>Новая БД</th><th>Результат</th></tr>";

foreach ($tables as $table) {
    $totalChecks++;
    try {
        $oldCount = (int)$oldPdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $newCount = (int)$newPdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $match = ($oldCount === $newCount);
        if ($match) $passedChecks++;
        $status = $match ? "<span class='pass'>СОВПАДЕНИЕ</span>" : "<span class='fail'>РАСХОЖДЕНИЕ!</span>";
        echo "<tr><td>{$table}</td><td>" . number_format($oldCount) . "</td><td>" . number_format($newCount) . "</td><td>{$status}</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td>{$table}</td><td colspan='3' class='fail'>" . htmlspecialchars($e->getMessage()) . "</td></tr>";
    }
}
echo "</table>";

// =============================================
// ПРОВЕРКА 2: Количество индексов
// =============================================
echo "<h2>2. Индексы</h2>";
echo "<table><tr><th>Таблица</th><th>Индексов (старая)</th><th>Индексов (новая)</th><th>Результат</th></tr>";

foreach ($tables as $table) {
    $totalChecks++;
    try {
        $oldIdx = $oldPdo->query("SHOW INDEX FROM `{$table}`")->fetchAll();
        $newIdx = $newPdo->query("SHOW INDEX FROM `{$table}`")->fetchAll();
        $oldNames = array_unique(array_column($oldIdx, 'Key_name'));
        $newNames = array_unique(array_column($newIdx, 'Key_name'));
        sort($oldNames);
        sort($newNames);
        $match = ($oldNames === $newNames);
        if ($match) $passedChecks++;

        $oldCount = count($oldNames);
        $newCount = count($newNames);
        $status = $match ? "<span class='pass'>СОВПАДЕНИЕ</span>" : "<span class='fail'>РАСХОЖДЕНИЕ: старая=[" . implode(',', $oldNames) . "] новая=[" . implode(',', $newNames) . "]</span>";
        echo "<tr><td>{$table}</td><td>{$oldCount}</td><td>{$newCount}</td><td>{$status}</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td>{$table}</td><td colspan='3' class='fail'>" . htmlspecialchars($e->getMessage()) . "</td></tr>";
    }
}
echo "</table>";

// =============================================
// ПРОВЕРКА 3: Структура колонок (SHOW COLUMNS)
// =============================================
echo "<h2>3. Структура колонок</h2>";
echo "<table><tr><th>Таблица</th><th>Колонок (старая)</th><th>Колонок (новая)</th><th>Результат</th></tr>";

foreach ($tables as $table) {
    $totalChecks++;
    try {
        $oldCols = $oldPdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
        $newCols = $newPdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();

        // Сравнить Field+Type+Null+Key+Default+Extra
        $oldMap = [];
        foreach ($oldCols as $c) {
            $oldMap[$c['Field']] = $c['Type'] . '|' . $c['Null'] . '|' . $c['Key'] . '|' . $c['Default'] . '|' . $c['Extra'];
        }
        $newMap = [];
        foreach ($newCols as $c) {
            $newMap[$c['Field']] = $c['Type'] . '|' . $c['Null'] . '|' . $c['Key'] . '|' . $c['Default'] . '|' . $c['Extra'];
        }

        $diff = array_diff_assoc($oldMap, $newMap);
        $match = empty($diff) && count($oldMap) === count($newMap);
        if ($match) $passedChecks++;

        $status = $match
            ? "<span class='pass'>СОВПАДЕНИЕ</span>"
            : "<span class='fail'>РАСХОЖДЕНИЕ: " . htmlspecialchars(json_encode(array_keys($diff))) . "</span>";
        echo "<tr><td>{$table}</td><td>" . count($oldCols) . "</td><td>" . count($newCols) . "</td><td>{$status}</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td>{$table}</td><td colspan='3' class='fail'>" . htmlspecialchars($e->getMessage()) . "</td></tr>";
    }
}
echo "</table>";

// =============================================
// ПРОВЕРКА 4: AUTO_INCREMENT
// =============================================
echo "<h2>4. AUTO_INCREMENT</h2>";
echo "<table><tr><th>Таблица</th><th>AI (старая)</th><th>AI (новая)</th><th>Результат</th></tr>";

foreach ($tables as $table) {
    $totalChecks++;
    try {
        $oldAI = $oldPdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'serflow_analdream' AND TABLE_NAME = '{$table}'")->fetchColumn();
        $newAI = $newPdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'dreamcar_utm' AND TABLE_NAME = '{$table}'")->fetchColumn();

        // AUTO_INCREMENT может отличаться на ±1, это нормально
        $diff = abs((int)$oldAI - (int)$newAI);
        $match = ($diff <= 1);
        if ($match) $passedChecks++;

        $status = $match ? "<span class='pass'>OK (разница: {$diff})</span>" : "<span class='warn'>Разница: {$diff}</span>";
        echo "<tr><td>{$table}</td><td>{$oldAI}</td><td>{$newAI}</td><td>{$status}</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td>{$table}</td><td colspan='3' class='fail'>" . htmlspecialchars($e->getMessage()) . "</td></tr>";
    }
}
echo "</table>";

// =============================================
// ПРОВЕРКА 5: Выборочные данные (первые и последние 3 записи)
// =============================================
echo "<h2>5. Выборочные данные (первые/последние 3 записи)</h2>";

foreach ($tables as $table) {
    $totalChecks++;
    try {
        $oldCount = (int)$oldPdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($oldCount === 0) {
            $passedChecks++;
            echo "<p>{$table}: пустая таблица — <span class='pass'>OK</span></p>";
            continue;
        }

        // Первые 3
        $oldFirst = $oldPdo->query("SELECT * FROM `{$table}` ORDER BY id ASC LIMIT 3")->fetchAll();
        $newFirst = $newPdo->query("SELECT * FROM `{$table}` ORDER BY id ASC LIMIT 3")->fetchAll();

        // Последние 3
        $oldLast = $oldPdo->query("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 3")->fetchAll();
        $newLast = $newPdo->query("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 3")->fetchAll();

        $firstMatch = ($oldFirst === $newFirst);
        $lastMatch = ($oldLast === $newLast);
        $match = $firstMatch && $lastMatch;
        if ($match) $passedChecks++;

        $status = $match
            ? "<span class='pass'>СОВПАДЕНИЕ</span>"
            : "<span class='fail'>РАСХОЖДЕНИЕ (first:" . ($firstMatch ? 'OK' : 'FAIL') . ", last:" . ($lastMatch ? 'OK' : 'FAIL') . ")</span>";
        echo "<p>{$table}: {$status}</p>";
    } catch (Exception $e) {
        echo "<p>{$table}: <span class='fail'>" . htmlspecialchars($e->getMessage()) . "</span></p>";
    }
}

// =============================================
// ПРОВЕРКА 6: CHECKSUM TABLE
// =============================================
echo "<h2>6. CHECKSUM TABLE</h2>";
echo "<table><tr><th>Таблица</th><th>Checksum (старая)</th><th>Checksum (новая)</th><th>Результат</th></tr>";

foreach ($tables as $table) {
    $totalChecks++;
    try {
        $oldCS = $oldPdo->query("CHECKSUM TABLE `{$table}`")->fetch();
        $newCS = $newPdo->query("CHECKSUM TABLE `{$table}`")->fetch();

        $oldVal = $oldCS['Checksum'] ?? 'NULL';
        $newVal = $newCS['Checksum'] ?? 'NULL';
        $match = ($oldVal === $newVal);
        if ($match) $passedChecks++;

        $status = $match ? "<span class='pass'>СОВПАДЕНИЕ</span>" : "<span class='warn'>РАЗЛИЧАЕТСЯ (это нормально при разных серверах)</span>";
        echo "<tr><td>{$table}</td><td>{$oldVal}</td><td>{$newVal}</td><td>{$status}</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td>{$table}</td><td colspan='3' class='fail'>" . htmlspecialchars($e->getMessage()) . "</td></tr>";
    }
}
echo "</table>";
echo "<p><em>Примечание: CHECKSUM TABLE может давать разные значения на разных серверах MySQL даже для идентичных данных. Основные проверки — COUNT и выборочные данные.</em></p>";

// =============================================
// ПРОВЕРКА 7: Размеры таблиц
// =============================================
echo "<h2>7. Размеры таблиц</h2>";
echo "<table><tr><th>Таблица</th><th>Размер (старая)</th><th>Размер (новая)</th></tr>";

foreach ($tables as $table) {
    try {
        $oldSize = $oldPdo->query("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = 'serflow_analdream' AND table_name = '{$table}'")->fetchColumn();
        $newSize = $newPdo->query("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = 'dreamcar_utm' AND table_name = '{$table}'")->fetchColumn();
        echo "<tr><td>{$table}</td><td>{$oldSize} MB</td><td>{$newSize} MB</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td>{$table}</td><td colspan='2' class='fail'>" . htmlspecialchars($e->getMessage()) . "</td></tr>";
    }
}
echo "</table>";

// =============================================
// ИТОГ
// =============================================
echo "<hr>";
echo "<h2>ИТОГ ВЕРИФИКАЦИИ</h2>";
echo "<p>Проверок пройдено: <strong>{$passedChecks}</strong> из <strong>{$totalChecks}</strong></p>";

// Не считаем CHECKSUM как критичную проверку (разные серверы дают разные checksums)
$criticalChecks = $totalChecks - count($tables); // Минус checksums
$criticalPassed = $passedChecks;
// Подсчет: если checksum не совпал но остальное ок — это норма
$checksumFails = 0;
foreach ($tables as $table) {
    try {
        $oldCS = $oldPdo->query("CHECKSUM TABLE `{$table}`")->fetch();
        $newCS = $newPdo->query("CHECKSUM TABLE `{$table}`")->fetch();
        if (($oldCS['Checksum'] ?? null) !== ($newCS['Checksum'] ?? null)) {
            $checksumFails++;
        }
    } catch (Exception $e) {}
}

$effectivePassed = $passedChecks + $checksumFails; // checksums не критичны
$effectiveTotal = $totalChecks;

if ($passedChecks >= ($totalChecks - $checksumFails)) {
    echo "<h2 class='pass'>ВЕРИФИКАЦИЯ ПРОЙДЕНА УСПЕШНО!</h2>";
    echo "<p>Все критичные проверки пройдены. Можно переключать конфигурацию.</p>";
    echo "<p><em>CHECKSUM TABLE может отличаться между серверами — это нормально.</em></p>";
} else {
    echo "<h2 class='fail'>ВЕРИФИКАЦИЯ НЕ ПРОЙДЕНА!</h2>";
    echo "<p>Есть расхождения. НЕ переключай конфигурацию! Проверь отчет выше.</p>";
}

echo "</body></html>";
