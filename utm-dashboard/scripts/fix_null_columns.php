<?php
// === fix_null_columns.php ===
// НАЗНАЧЕНИЕ: Исправить NOT NULL ограничения - разрешить NULL для email и created_at
// ИСПОЛЬЗОВАНИЕ: Открыть в браузере один раз

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Исправление NOT NULL колонок</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #60a5fa; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .info { color: #60a5fa; }
        .warning { color: #fbbf24; }
        pre { background: #16213e; padding: 15px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>

<h1>🔧 Исправление NOT NULL ограничений</h1>

<?php
try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    $fixes = [
        [
            'field' => 'email',
            'sql' => "ALTER TABLE crm_deals MODIFY COLUMN `email` varchar(255) NULL"
        ],
        [
            'field' => 'created_at',
            'sql' => "ALTER TABLE crm_deals MODIFY COLUMN `created_at` datetime NULL"
        ]
    ];

    echo "<h2>Выполняю исправления...</h2>";

    $successCount = 0;
    $errorCount = 0;

    foreach ($fixes as $fix) {
        echo "<p><strong>{$fix['field']}</strong>: ";

        try {
            // Проверить текущее состояние
            $stmt = $pdo->query("SHOW COLUMNS FROM crm_deals WHERE Field = '{$fix['field']}'");
            $column = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($column && $column['Null'] === 'YES') {
                echo "<span class='info'>уже позволяет NULL ✅</span></p>";
                $successCount++;
                continue;
            }

            // Выполнить ALTER
            $pdo->exec($fix['sql']);
            echo "<span class='success'>исправлено! ✅</span></p>";
            $logger->success("Колонка {$fix['field']} изменена на NULL");
            $successCount++;

        } catch (Exception $e) {
            echo "<span class='error'>ошибка: " . htmlspecialchars($e->getMessage()) . " ❌</span></p>";
            $logger->error("Ошибка изменения {$fix['field']}", ['error' => $e->getMessage()]);
            $errorCount++;
        }
    }

    echo "<h2>Результат</h2>";
    echo "<p class='success'>✅ Успешно: {$successCount}</p>";

    if ($errorCount > 0) {
        echo "<p class='error'>❌ Ошибок: {$errorCount}</p>";
    }

    // Проверить итоговое состояние
    echo "<h2>Проверка после исправления</h2>";

    $stmt = $pdo->query("SHOW COLUMNS FROM crm_deals WHERE `Null` = 'NO' AND Field NOT IN ('id', 'deal_id')");
    $notNullColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($notNullColumns) === 0) {
        echo "<p class='success'>✅ Все колонки теперь позволяют NULL (кроме id и deal_id)</p>";
        echo "<p class='success'>🎉 Готово! Webhook теперь сможет принимать данные с пустыми полями.</p>";
    } else {
        echo "<p class='warning'>⚠️ Остались колонки с NOT NULL:</p>";
        echo "<pre>";
        foreach ($notNullColumns as $col) {
            echo "{$col['Field']} - {$col['Type']}\n";
        }
        echo "</pre>";
    }

} catch (Exception $e) {
    $logger->error('Ошибка исправления NOT NULL колонок', ['error' => $e->getMessage()]);
    echo "<p class='error'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<p style="margin-top: 2rem;">
    <a href="check_null_columns.php" style="color: #60a5fa;">← Вернуться к диагностике</a>
</p>

</body>
</html>
