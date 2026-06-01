<?php
// === add_model_comment_fields.php ===
// НАЗНАЧЕНИЕ: Добавить поля model и comment в таблицу crm_deals
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер один раз

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
    <title>Добавление полей model и comment</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>";

echo "<h1>🔧 Добавление полей model и comment в таблицу crm_deals</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Подключение к базе данных</div>";
    echo "<p class='success'>✅ Успешно подключено к: " . DB_HOST . " / " . DB_NAME . "</p>";
    echo "</div>";

    // Проверить есть ли уже поле model
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Проверка существования полей</div>";

    $stmt = $pdo->query("SHOW COLUMNS FROM crm_deals LIKE 'model'");
    $modelExists = $stmt->fetch();

    $stmt = $pdo->query("SHOW COLUMNS FROM crm_deals LIKE 'comment'");
    $commentExists = $stmt->fetch();

    if ($modelExists) {
        echo "<p class='info'>⚠️ Поле <strong>model</strong> уже существует</p>";
    } else {
        echo "<p class='info'>ℹ️ Поле <strong>model</strong> не найдено - будет добавлено</p>";
    }

    if ($commentExists) {
        echo "<p class='info'>⚠️ Поле <strong>comment</strong> уже существует</p>";
    } else {
        echo "<p class='info'>ℹ️ Поле <strong>comment</strong> не найдено - будет добавлено</p>";
    }

    echo "</div>";

    // Добавить поля
    if (!$modelExists || !$commentExists) {
        echo "<div class='step'>";
        echo "<div class='step-title'>Шаг 3: Добавление полей</div>";

        if (!$modelExists) {
            $sql = "ALTER TABLE crm_deals ADD COLUMN model VARCHAR(100) AFTER deal_step";
            $pdo->exec($sql);
            echo "<p class='success'>✅ Поле <strong>model</strong> добавлено</p>";
            echo "<p class='info'>📝 Тип: VARCHAR(100) - название проекта (VOLVO, OLD и т.д.)</p>";

            // Скопировать данные из deal_step в model
            $sql = "UPDATE crm_deals SET model = deal_step WHERE deal_step IS NOT NULL AND model IS NULL";
            $updated = $pdo->exec($sql);
            echo "<p class='success'>✅ Скопировано $updated записей из deal_step в model</p>";
        }

        if (!$commentExists) {
            $sql = "ALTER TABLE crm_deals ADD COLUMN comment TEXT AFTER model";
            $pdo->exec($sql);
            echo "<p class='success'>✅ Поле <strong>comment</strong> добавлено</p>";
            echo "<p class='info'>📝 Тип: TEXT - комментарии к сделке</p>";
        }

        echo "</div>";

        $logger->success('Поля model и comment добавлены в crm_deals');
    }

    // Проверка результата
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 4: Проверка структуры таблицы</div>";

    $stmt = $pdo->query("DESCRIBE crm_deals");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $foundModel = false;
    $foundComment = false;

    foreach ($columns as $col) {
        if ($col['Field'] === 'model') {
            $foundModel = true;
            echo "<p class='success'>✅ Поле <strong>model</strong> ({$col['Type']})</p>";
        }
        if ($col['Field'] === 'comment') {
            $foundComment = true;
            echo "<p class='success'>✅ Поле <strong>comment</strong> ({$col['Type']})</p>";
        }
    }

    if (!$foundModel || !$foundComment) {
        echo "<p class='error'>❌ Не все поля добавлены!</p>";
    } else {
        echo "<p class='success'>✅ Все поля на месте</p>";
    }

    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='stats'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>База данных:</strong> " . DB_NAME . "</p>";
    echo "<p><strong>Статус:</strong> <span class='success'>✅ Готово</span></p>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<h2>✅ Поля model и comment добавлены!</h2>";
    echo "<ul>";
    echo "<li><strong>model</strong> - название проекта (VOLVO, OLD и т.д.), берется из name_deal в webhook или deal_step в CSV</li>";
    echo "<li><strong>comment</strong> - комментарии к сделке</li>";
    echo "</ul>";
    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><a href='../webhook_logs.php'>→ Перейти к логам webhook</a></p>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка добавления полей</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка добавления полей model/comment', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
