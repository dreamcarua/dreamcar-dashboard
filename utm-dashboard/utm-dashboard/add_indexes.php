<?php
/**
 * add_indexes.php
 * Скрипт для добавления индексов в таблицу crm_deals
 * Решает проблему медленного запроса (6 секунд → миллисекунды)
 *
 * ОДНОРАЗОВЫЙ СКРИПТ - удалить после выполнения!
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>🔧 Добавление индексов</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 40px; max-width: 900px; margin: 0 auto; background: #1a1a2e; color: #eee; }
        h1 { color: #4ade80; }
        .success { color: #4ade80; background: rgba(74,222,128,0.1); padding: 10px 15px; border-radius: 8px; margin: 10px 0; }
        .error { color: #f87171; background: rgba(248,113,113,0.1); padding: 10px 15px; border-radius: 8px; margin: 10px 0; }
        .info { color: #60a5fa; background: rgba(96,165,250,0.1); padding: 10px 15px; border-radius: 8px; margin: 10px 0; }
        .warning { color: #fbbf24; background: rgba(251,191,36,0.1); padding: 10px 15px; border-radius: 8px; margin: 10px 0; }
        pre { background: #0f0f1a; padding: 15px; border-radius: 8px; overflow-x: auto; }
        code { color: #a78bfa; }
        .btn { display: inline-block; padding: 12px 24px; background: #4ade80; color: #1a1a2e; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 20px; }
        .btn:hover { background: #22c55e; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #333; }
        th { color: #4ade80; }
    </style>
</head>
<body>
    <h1>🔧 Добавление индексов в crm_deals</h1>
";

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);

    echo "<div class='success'>✅ Подключение к БД успешно</div>";

    // Список индексов для создания
    $indexes = [
        [
            'name' => 'idx_created_at',
            'sql' => 'ALTER TABLE crm_deals ADD INDEX idx_created_at (created_at)',
            'description' => 'Индекс для сортировки по дате создания'
        ],
        [
            'name' => 'idx_model_created',
            'sql' => 'ALTER TABLE crm_deals ADD INDEX idx_model_created (model, created_at)',
            'description' => 'Составной индекс для фильтра по модели + дата'
        ],
        [
            'name' => 'idx_contact_id',
            'sql' => 'ALTER TABLE crm_deals ADD INDEX idx_contact_id (contact_id)',
            'description' => 'Индекс для поиска по contact_id'
        ],
        [
            'name' => 'idx_is_paid',
            'sql' => 'ALTER TABLE crm_deals ADD INDEX idx_is_paid (is_paid)',
            'description' => 'Индекс для фильтра оплаченных сделок'
        ],
        [
            'name' => 'idx_utm_source',
            'sql' => 'ALTER TABLE crm_deals ADD INDEX idx_utm_source (utm_source(50))',
            'description' => 'Индекс для UTM source'
        ],
        [
            'name' => 'idx_utm_medium',
            'sql' => 'ALTER TABLE crm_deals ADD INDEX idx_utm_medium (utm_medium(50))',
            'description' => 'Индекс для UTM medium'
        ],
        [
            'name' => 'idx_utm_campaign',
            'sql' => 'ALTER TABLE crm_deals ADD INDEX idx_utm_campaign (utm_campaign(100))',
            'description' => 'Индекс для UTM campaign'
        ]
    ];

    // Получить существующие индексы
    $stmt = $pdo->query("SHOW INDEX FROM crm_deals");
    $existingIndexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $existingNames = [];
    foreach ($existingIndexes as $idx) {
        $existingNames[$idx['Key_name']] = true;
    }

    echo "<h2>📋 Существующие индексы</h2>";
    echo "<table><tr><th>Имя</th><th>Колонка</th><th>Уникальный</th></tr>";
    foreach ($existingIndexes as $idx) {
        $unique = $idx['Non_unique'] == 0 ? '✅ Да' : '❌ Нет';
        echo "<tr><td>{$idx['Key_name']}</td><td>{$idx['Column_name']}</td><td>{$unique}</td></tr>";
    }
    echo "</table>";

    echo "<h2>🚀 Добавление новых индексов</h2>";

    $added = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($indexes as $index) {
        echo "<div class='info'>";
        echo "<strong>{$index['name']}</strong>: {$index['description']}<br>";
        echo "<code>{$index['sql']}</code>";
        echo "</div>";

        if (isset($existingNames[$index['name']])) {
            echo "<div class='warning'>⏭️ Индекс <strong>{$index['name']}</strong> уже существует - пропускаем</div>";
            $skipped++;
            continue;
        }

        try {
            $pdo->exec($index['sql']);
            echo "<div class='success'>✅ Индекс <strong>{$index['name']}</strong> успешно создан!</div>";
            $added++;
        } catch (PDOException $e) {
            // Проверяем не дубликат ли это
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "<div class='warning'>⏭️ Индекс <strong>{$index['name']}</strong> уже существует</div>";
                $skipped++;
            } else {
                echo "<div class='error'>❌ Ошибка создания индекса <strong>{$index['name']}</strong>: " . htmlspecialchars($e->getMessage()) . "</div>";
                $errors++;
            }
        }
    }

    echo "<h2>📊 Результат</h2>";
    echo "<div class='success'>";
    echo "✅ Добавлено индексов: <strong>{$added}</strong><br>";
    echo "⏭️ Пропущено (уже есть): <strong>{$skipped}</strong><br>";
    if ($errors > 0) {
        echo "❌ Ошибок: <strong>{$errors}</strong>";
    }
    echo "</div>";

    // Показать итоговую структуру индексов
    echo "<h2>📋 Итоговая структура индексов</h2>";
    $stmt = $pdo->query("SHOW INDEX FROM crm_deals");
    $finalIndexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table><tr><th>Имя</th><th>Колонка</th><th>Тип</th></tr>";
    foreach ($finalIndexes as $idx) {
        $type = $idx['Non_unique'] == 0 ? 'UNIQUE' : 'INDEX';
        echo "<tr><td>{$idx['Key_name']}</td><td>{$idx['Column_name']}</td><td>{$type}</td></tr>";
    }
    echo "</table>";

    echo "<div class='warning' style='margin-top: 30px;'>";
    echo "⚠️ <strong>ВАЖНО:</strong> Удалите этот файл после выполнения!<br>";
    echo "Файл: <code>add_indexes.php</code>";
    echo "</div>";

    echo "<a href='index.php' class='btn'>← Вернуться к дашборду</a>";

} catch (PDOException $e) {
    echo "<div class='error'>❌ Ошибка подключения к БД: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</body></html>";
