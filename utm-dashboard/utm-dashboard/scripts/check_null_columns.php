<?php
// === check_null_columns.php ===
// НАЗНАЧЕНИЕ: Диагностика - показать какие колонки имеют NOT NULL ограничение
// ИСПОЛЬЗОВАНИЕ: Открыть в браузере один раз

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Диагностика NOT NULL колонок</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #60a5fa; }
        h2 { color: #fbbf24; margin-top: 2rem; }
        .ok { color: #4ade80; }
        .warning { color: #fbbf24; }
        .error { color: #f87171; }
        .info { color: #60a5fa; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #444; padding: 10px; text-align: left; }
        th { background: #16213e; }
        tr:nth-child(even) { background: #1e2a47; }
        .badge-no { background: #f87171; color: #000; padding: 2px 8px; border-radius: 4px; font-weight: bold; }
        .badge-yes { background: #4ade80; color: #000; padding: 2px 8px; border-radius: 4px; font-weight: bold; }
        pre { background: #16213e; padding: 15px; border-radius: 8px; overflow-x: auto; }
        code { color: #fbbf24; }
    </style>
</head>
<body>

<h1>🔍 Диагностика NOT NULL колонок в таблице crm_deals</h1>

<?php
try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    // Получить все колонки таблицы
    $stmt = $pdo->query("SHOW COLUMNS FROM crm_deals");
    $allColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Разделить на категории
    $notNullColumns = [];
    $nullableColumns = [];

    foreach ($allColumns as $col) {
        $field = $col['Field'];
        $type = $col['Type'];
        $null = $col['Null'];
        $default = $col['Default'];

        // Пропустить id и deal_id - они должны быть NOT NULL
        if ($field === 'id' || $field === 'deal_id') {
            continue;
        }

        if ($null === 'NO') {
            $notNullColumns[] = $col;
        } else {
            $nullableColumns[] = $col;
        }
    }

    // Показать статистику
    echo "<h2>📊 Статистика</h2>";
    echo "<p class='info'>Всего колонок: <strong>" . count($allColumns) . "</strong></p>";
    echo "<p class='error'>❌ NOT NULL (нужно исправить): <strong>" . count($notNullColumns) . "</strong></p>";
    echo "<p class='ok'>✅ Nullable (OK): <strong>" . count($nullableColumns) . " + 2 (id, deal_id)</strong></p>";

    // Таблица NOT NULL колонок
    if (count($notNullColumns) > 0) {
        echo "<h2>❌ Колонки с NOT NULL ограничением (нужно исправить)</h2>";
        echo "<p class='warning'>Эти колонки вызовут ошибку если webhook пришлет NULL значение!</p>";
        echo "<table>";
        echo "<tr><th>Поле</th><th>Тип</th><th>NULL</th><th>Default</th><th>ALTER команда</th></tr>";

        foreach ($notNullColumns as $col) {
            $field = $col['Field'];
            $type = $col['Type'];
            $default = $col['Default'] ?? 'NULL';

            // Сгенерировать ALTER команду
            $alterCmd = "ALTER TABLE crm_deals MODIFY COLUMN `{$field}` {$type} NULL";
            if ($default !== 'NULL' && $default !== null) {
                $alterCmd .= " DEFAULT '{$default}'";
            }
            $alterCmd .= ";";

            echo "<tr>";
            echo "<td><strong>{$field}</strong></td>";
            echo "<td>{$type}</td>";
            echo "<td><span class='badge-no'>NO</span></td>";
            echo "<td>{$default}</td>";
            echo "<td><code>{$alterCmd}</code></td>";
            echo "</tr>";
        }
        echo "</table>";

        // SQL для копирования
        echo "<h2>📋 SQL скрипт для исправления (копируй)</h2>";
        echo "<pre>";
        foreach ($notNullColumns as $col) {
            $field = $col['Field'];
            $type = $col['Type'];
            $default = $col['Default'] ?? null;

            echo "ALTER TABLE crm_deals MODIFY COLUMN `{$field}` {$type} NULL";
            if ($default !== null && $default !== '') {
                echo " DEFAULT '{$default}'";
            }
            echo ";\n";
        }
        echo "</pre>";
    } else {
        echo "<h2 class='ok'>✅ Все колонки уже позволяют NULL!</h2>";
        echo "<p>Таблица настроена правильно.</p>";
    }

    // Таблица Nullable колонок (для справки)
    echo "<h2>✅ Колонки которые уже позволяют NULL (OK)</h2>";
    echo "<table>";
    echo "<tr><th>Поле</th><th>Тип</th><th>NULL</th><th>Default</th></tr>";

    // Сначала id и deal_id
    foreach ($allColumns as $col) {
        if ($col['Field'] === 'id' || $col['Field'] === 'deal_id') {
            echo "<tr>";
            echo "<td><strong>{$col['Field']}</strong> (обязательное)</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td><span class='badge-no'>NO</span> (правильно!)</td>";
            echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
    }

    foreach ($nullableColumns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td><span class='badge-yes'>YES</span></td>";
        echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "<p class='error'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

</body>
</html>
