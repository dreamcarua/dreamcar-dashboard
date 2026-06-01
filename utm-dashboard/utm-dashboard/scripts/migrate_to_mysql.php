<?php
// === migrate_to_mysql.php ===
// НАЗНАЧЕНИЕ: Миграция данных CRM из JSON в MySQL
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер
// ВАЖНО: Читает data/utm_clean.json и загружает в crm_deals

ini_set('max_execution_time', 600);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/CrmDeal.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Миграция CRM данных в MySQL</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        .progress { background: #e5e7eb; height: 20px; border-radius: 10px; margin: 10px 0; }
        .progress-bar { background: #3b82f6; height: 100%; border-radius: 10px; transition: width 0.3s; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>";

echo "<h1>📥 Миграция CRM данных в MySQL</h1>";

try {
    // Шаг 1: Проверка файла
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Проверка файла данных</div>";

    $jsonFile = UTM_CLEAN_FILE;

    if (!file_exists($jsonFile)) {
        throw new Exception("Файл не найден: $jsonFile");
    }

    $fileSize = filesize($jsonFile);
    echo "<p class='success'>✅ Файл найден: $jsonFile</p>";
    echo "<p class='info'>Размер: " . number_format($fileSize / 1024, 2) . " KB</p>";
    echo "</div>";

    // Шаг 2: Чтение данных
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Чтение данных из JSON</div>";

    $jsonContent = file_get_contents($jsonFile);
    $data = json_decode($jsonContent, true);

    if ($data === null) {
        throw new Exception("Ошибка парсинга JSON: " . json_last_error_msg());
    }

    $totalRecords = count($data);
    echo "<p class='success'>✅ Прочитано записей: " . number_format($totalRecords) . "</p>";
    echo "</div>";

    if ($totalRecords === 0) {
        echo "<p class='error'>⚠️ Нет данных для миграции</p>";
        echo "</body></html>";
        exit;
    }

    // Шаг 3: Подготовка данных
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Подготовка данных</div>";

    $validRecords = [];
    $skipped = 0;

    foreach ($data as $record) {
        // Проверка обязательных полей
        if (empty($record['email'])) {
            $skipped++;
            continue;
        }

        // Добавить в список
        $validRecords[] = $record;
    }

    echo "<p class='success'>✅ Валидных записей: " . number_format(count($validRecords)) . "</p>";
    if ($skipped > 0) {
        echo "<p class='info'>⚠️ Пропущено (нет email): $skipped</p>";
    }
    echo "</div>";

    // Шаг 4: Миграция в MySQL
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 4: Миграция в MySQL</div>";
    echo "<p class='info'>Процесс миграции... это может занять некоторое время</p>";

    echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%'></div></div>";
    echo "<p id='progressText'>0 / " . count($validRecords) . "</p>";

    // Flush для отображения прогресса
    ob_flush();
    flush();

    $stats = CrmDeal::batchUpsert($validRecords, 500);

    echo "<script>
        document.getElementById('progressBar').style.width = '100%';
        document.getElementById('progressText').textContent = '" . count($validRecords) . " / " . count($validRecords) . " (100%)';
    </script>";

    echo "<p class='success'>✅ Миграция завершена!</p>";
    echo "<p class='info'>Новых записей: {$stats['new']}</p>";
    echo "<p class='info'>Обновлено записей: {$stats['updated']}</p>";
    echo "</div>";

    // Шаг 5: Проверка результата
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 5: Проверка результата</div>";

    $count = CrmDeal::count();
    echo "<p class='success'>✅ Всего записей в БД: " . number_format($count) . "</p>";

    // Статистика по типам
    $crmStats = CrmDeal::getStats();
    echo "<p class='info'>Лидов: " . number_format($crmStats['total_leads']) . "</p>";
    echo "<p class='info'>Оплачено: " . number_format($crmStats['paid_count']) . " (" . number_format($crmStats['paid_amount'], 2) . " UAH)</p>";
    echo "<p class='info'>Неуспешно: " . number_format($crmStats['failed_count']) . " (" . number_format($crmStats['failed_amount'], 2) . " UAH)</p>";
    echo "<p class='info'>В процессе: " . number_format($crmStats['pending_count']) . " (" . number_format($crmStats['pending_amount'], 2) . " UAH)</p>";
    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='stats'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Прочитано из JSON:</strong> " . number_format($totalRecords) . "</p>";
    echo "<p><strong>Загружено в MySQL:</strong> " . number_format($stats['new'] + $stats['updated']) . "</p>";
    echo "<p><strong>Новых:</strong> " . number_format($stats['new']) . "</p>";
    echo "<p><strong>Обновлено:</strong> " . number_format($stats['updated']) . "</p>";
    echo "<p><strong>Скорость:</strong> " . number_format($totalRecords / $duration, 0) . " записей/сек</p>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<h2>✅ Миграция CRM данных завершена успешно!</h2>";
    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><a href='migrate_ads_to_mysql.php'>→ Мигрировать рекламные данные</a></p>";
    echo "</div>";

    $logger->success('Миграция CRM завершена', [
        'records' => $totalRecords,
        'new' => $stats['new'],
        'updated' => $stats['updated'],
        'duration' => $duration
    ]);

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка миграции</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка миграции CRM', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
