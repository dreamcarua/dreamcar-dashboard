<?php
// === migrate_ads_to_mysql.php ===
// НАЗНАЧЕНИЕ: Миграция рекламных данных из JSON в MySQL
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер
// ВАЖНО: Читает все facebook_ads.json из data/make_request/data/YYYY-MM-DD/

ini_set('max_execution_time', 600);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/AdsData.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Миграция рекламных данных в MySQL</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; }
        .file-item { padding: 5px; border-left: 3px solid #3b82f6; margin: 5px 0; padding-left: 10px; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>";

echo "<h1>📊 Миграция рекламных данных в MySQL</h1>";

try {
    // Шаг 1: Поиск файлов
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 1: Поиск файлов с рекламными данными</div>";

    $adsDataDir = DATA_DIR . '/make_request/data';

    if (!is_dir($adsDataDir)) {
        throw new Exception("Папка не найдена: $adsDataDir");
    }

    // Сканировать папки с датами
    $dateFolders = glob($adsDataDir . '/*', GLOB_ONLYDIR);
    $files = [];

    foreach ($dateFolders as $folder) {
        $fbAdsFile = $folder . '/facebook_ads.json';
        if (file_exists($fbAdsFile)) {
            $files[] = $fbAdsFile;
        }
    }

    if (empty($files)) {
        echo "<p class='error'>⚠️ Файлы с рекламными данными не найдены</p>";
        echo "<p class='info'>Ожидаемая структура: data/make_request/data/YYYY-MM-DD/facebook_ads.json</p>";
        echo "</body></html>";
        exit;
    }

    echo "<p class='success'>✅ Найдено файлов: " . count($files) . "</p>";
    foreach ($files as $file) {
        $basename = basename(dirname($file));
        $size = filesize($file);
        echo "<div class='file-item'>📁 $basename/facebook_ads.json (" . number_format($size / 1024, 2) . " KB)</div>";
    }
    echo "</div>";

    // Шаг 2: Чтение и объединение данных
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 2: Чтение данных из файлов</div>";

    $allAdsData = [];
    $totalRecords = 0;
    $filesProcessed = 0;
    $filesSkipped = 0;

    foreach ($files as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);

        if ($data === null) {
            $filesSkipped++;
            echo "<p class='error'>⚠️ Ошибка парсинга: " . basename(dirname($file)) . "</p>";
            continue;
        }

        $recordsCount = count($data);
        $totalRecords += $recordsCount;
        $allAdsData = array_merge($allAdsData, $data);
        $filesProcessed++;

        echo "<p class='info'>✓ " . basename(dirname($file)) . ": $recordsCount записей</p>";
    }

    echo "<p class='success'>✅ Всего записей: " . number_format($totalRecords) . "</p>";
    echo "<p class='info'>Обработано файлов: $filesProcessed</p>";
    if ($filesSkipped > 0) {
        echo "<p class='error'>Пропущено файлов: $filesSkipped</p>";
    }
    echo "</div>";

    if ($totalRecords === 0) {
        echo "<p class='error'>⚠️ Нет данных для миграции</p>";
        echo "</body></html>";
        exit;
    }

    // Шаг 3: Миграция в MySQL
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 3: Миграция в MySQL</div>";
    echo "<p class='info'>Процесс миграции с преобразованием UTM меток...</p>";

    $inserted = AdsData::insertFromFacebook($allAdsData);

    echo "<p class='success'>✅ Миграция завершена!</p>";
    echo "<p class='info'>Вставлено/обновлено записей: " . number_format($inserted) . "</p>";
    echo "</div>";

    // Шаг 4: Проверка результата
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 4: Проверка результата</div>";

    $count = AdsData::count();
    echo "<p class='success'>✅ Всего записей в БД: " . number_format($count) . "</p>";

    // Статистика
    $adsStats = AdsData::getTotalStats();
    echo "<p class='info'>Общие затраты: " . number_format($adsStats['total_spend'], 2) . " UAH</p>";
    echo "<p class='info'>Всего кликов: " . number_format($adsStats['total_clicks']) . "</p>";
    echo "<p class='info'>Всего показов: " . number_format($adsStats['total_impressions']) . "</p>";
    echo "<p class='info'>Охват: " . number_format($adsStats['total_reach']) . "</p>";
    echo "<p class='info'>Средний CPM: " . number_format($adsStats['avg_cpm'], 2) . "</p>";
    echo "<p class='info'>Средний CTR: " . number_format($adsStats['avg_ctr'], 4) . "%</p>";
    echo "</div>";

    // Шаг 5: Примеры преобразованных данных
    echo "<div class='step'>";
    echo "<div class='step-title'>Шаг 5: Примеры преобразования UTM меток</div>";
    echo "<p class='info'>Показано первые 3 записи:</p>";

    $samples = AdsData::getFiltered(['date_from' => date('Y-m-d', strtotime('-30 days'))]);
    $samplesLimit = array_slice($samples, 0, 3);

    echo "<table border='1' cellpadding='8' style='width:100%; border-collapse:collapse; font-size:12px;'>";
    echo "<tr style='background:#e5e7eb;'>";
    echo "<th>Дата</th><th>Кампания (оригинал)</th><th>UTM Source</th><th>UTM Medium</th><th>UTM Campaign</th><th>UTM Content</th><th>UTM Term</th><th>Затраты</th>";
    echo "</tr>";

    foreach ($samplesLimit as $row) {
        echo "<tr>";
        echo "<td>" . $row['date_start'] . "</td>";
        echo "<td>" . htmlspecialchars(substr($row['campaign_name'], 0, 30)) . "...</td>";
        echo "<td>" . htmlspecialchars($row['utm_source']) . "</td>";
        echo "<td>" . htmlspecialchars($row['utm_medium']) . "</td>";
        echo "<td>" . htmlspecialchars(substr($row['utm_campaign'], 0, 30)) . "...</td>";
        echo "<td>" . htmlspecialchars(substr($row['utm_content'], 0, 30)) . "...</td>";
        echo "<td>" . htmlspecialchars($row['utm_term']) . "</td>";
        echo "<td>" . number_format($row['spend'], 2) . " UAH</td>";
        echo "</tr>";
    }

    echo "</table>";
    echo "</div>";

    // Итоговая статистика
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "<div class='stats'>";
    echo "<h2>📊 Итоговая статистика</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Файлов обработано:</strong> $filesProcessed</p>";
    echo "<p><strong>Записей из JSON:</strong> " . number_format($totalRecords) . "</p>";
    echo "<p><strong>Загружено в MySQL:</strong> " . number_format($inserted) . "</p>";
    echo "<p><strong>Скорость:</strong> " . number_format($totalRecords / $duration, 0) . " записей/сек</p>";
    echo "<p><strong>Общие затраты:</strong> " . number_format($adsStats['total_spend'], 2) . " UAH</p>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<h2>✅ Миграция рекламных данных завершена успешно!</h2>";
    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><strong>Следующий шаг:</strong> Запустить агрегацию данных для расчета метрик</p>";
    echo "</div>";

    $logger->success('Миграция рекламы завершена', [
        'files' => $filesProcessed,
        'records' => $totalRecords,
        'inserted' => $inserted,
        'duration' => $duration,
        'spend' => $adsStats['total_spend']
    ]);

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Ошибка миграции</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    $logger->error('Ошибка миграции рекламы', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
