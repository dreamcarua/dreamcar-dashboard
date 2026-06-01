<?php
// === import_historical_data.php ===
// НАЗНАЧЕНИЕ: Импорт исторических данных за прошлые периоды
// ИСПОЛЬЗОВАНИЕ: Загрузка CSV (CRM) или JSON (реклама) файлов
// СВЯЗИ: core/models/CrmDeal.php, core/models/AdsData.php

ini_set('max_execution_time', 3600);
ini_set('memory_limit', '2G');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/CrmDeal.php';
require_once __DIR__ . '/../core/models/AdsData.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>📦 Импорт исторических данных</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1100px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #6b7280; }
        .warning { color: #f59e0b; font-weight: bold; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; font-size: 16px; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .form-group { margin: 15px 0; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input[type=file], input[type=text], select {
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            width: 100%;
            max-width: 400px;
        }
        button {
            background: #2563eb;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover { background: #1d4ed8; }
        .upload-form { background: white; padding: 20px; border: 2px solid #e5e7eb; border-radius: 8px; margin: 20px 0; }
        pre { background: #f9fafb; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>";

echo "<h1>📦 Импорт исторических данных</h1>";

try {
    // ========================================
    // Проверить была ли отправка формы
    // ========================================

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $dataType = $_POST['data_type'] ?? 'crm';
        $dateOverride = $_POST['date_override'] ?? null;

        echo "<div class='step'>";
        echo "<div class='step-title'>📥 Обработка загруженных данных</div>";
        echo "<p class='info'><strong>Тип данных:</strong> " . ($dataType === 'crm' ? 'CRM (CSV)' : 'Реклама (JSON)') . "</p>";

        if ($dateOverride) {
            echo "<p class='info'><strong>Переопределение даты:</strong> $dateOverride</p>";
        }

        // ========================================
        // Обработка CRM данных (CSV)
        // ========================================

        if ($dataType === 'crm' && isset($_FILES['csv_file'])) {
            $csvFile = $_FILES['csv_file'];

            if ($csvFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Ошибка загрузки файла: ' . $csvFile['error']);
            }

            echo "<p class='info'>📄 Файл: {$csvFile['name']} (" . round($csvFile['size'] / 1024, 2) . " KB)</p>";

            // Читать CSV
            $handle = fopen($csvFile['tmp_name'], 'r');
            if (!$handle) {
                throw new Exception('Не удалось открыть CSV файл');
            }

            // Первая строка - заголовки
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            echo "<p class='info'>Колонки: " . implode(', ', $headers) . "</p>";

            $crmData = [];
            $lineNumber = 1;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;

                // Создать ассоциативный массив
                $rowData = array_combine($headers, $row);

                // Переопределить дату если указана
                if ($dateOverride) {
                    $rowData['created_at'] = $dateOverride;
                }

                $crmData[] = $rowData;
            }

            fclose($handle);

            echo "<p class='success'>✅ Прочитано строк: " . count($crmData) . "</p>";
            echo "</div>";

            // Обработать данные
            echo "<div class='step'>";
            echo "<div class='step-title'>💾 Импорт в базу данных</div>";

            $stats = CrmDeal::batchUpsert($crmData, 500);

            echo "<p class='success'>✅ Новых записей: {$stats['new']}</p>";
            echo "<p class='success'>✅ Обновлено записей: {$stats['updated']}</p>";
            echo "<p class='info'>📊 Всего в БД: " . CrmDeal::count() . " сделок</p>";
            echo "</div>";

            $logger->success('CRM исторические данные импортированы', [
                'file' => $csvFile['name'],
                'records' => count($crmData),
                'new' => $stats['new'],
                'updated' => $stats['updated']
            ]);
        }

        // ========================================
        // Обработка рекламных данных (JSON)
        // ========================================

        if ($dataType === 'ads' && isset($_FILES['json_file'])) {
            $jsonFile = $_FILES['json_file'];

            if ($jsonFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Ошибка загрузки файла: ' . $jsonFile['error']);
            }

            echo "<p class='info'>📄 Файл: {$jsonFile['name']} (" . round($jsonFile['size'] / 1024, 2) . " KB)</p>";

            // Читать JSON
            $jsonContent = file_get_contents($jsonFile['tmp_name']);
            $adsData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Ошибка парсинга JSON: ' . json_last_error_msg());
            }

            if (!is_array($adsData)) {
                throw new Exception('JSON должен содержать массив данных');
            }

            echo "<p class='success'>✅ Прочитано записей: " . count($adsData) . "</p>";

            // Переопределить дату если указана
            if ($dateOverride) {
                foreach ($adsData as &$row) {
                    $row['date_start'] = $dateOverride;
                    $row['date_stop'] = $dateOverride;
                }
                unset($row);
                echo "<p class='warning'>⚠️ Даты переопределены на: $dateOverride</p>";
            }

            echo "</div>";

            // Обработать данные
            echo "<div class='step'>";
            echo "<div class='step-title'>💾 Импорт в базу данных</div>";

            $inserted = AdsData::insertFromFacebook($adsData);
            $adsStats = AdsData::getTotalStats();

            echo "<p class='success'>✅ Вставлено/обновлено записей: $inserted</p>";
            echo "<p class='info'>📊 Всего в БД: " . AdsData::count() . " записей</p>";
            echo "<p class='info'>💰 Общие затраты: " . number_format($adsStats['total_spend'], 2) . " UAH</p>";
            echo "<p class='info'>👆 Кликов: " . number_format($adsStats['total_clicks']) . "</p>";
            echo "<p class='info'>👁️ Показов: " . number_format($adsStats['total_impressions']) . "</p>";
            echo "</div>";

            $logger->success('Рекламные исторические данные импортированы', [
                'file' => $jsonFile['name'],
                'records' => count($adsData),
                'inserted' => $inserted
            ]);
        }

        // Итоги
        $duration = round(microtime(true) - $startTime, 2);

        echo "<div class='stats'>";
        echo "<h2>✅ Импорт завершен!</h2>";
        echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
        echo "<p><a href='import_historical_data.php'>← Импортировать еще данные</a></p>";
        echo "<p><a href='../index.php'>→ Перейти к дашборду</a></p>";
        echo "</div>";

    } else {

        // ========================================
        // Показать форму загрузки
        // ========================================

        echo "<div class='upload-form'>";
        echo "<h2>📤 Загрузить исторические данные</h2>";
        echo "<p class='info'>Выберите тип данных и загрузите файл в формате CSV (для CRM) или JSON (для рекламы)</p>";

        echo "<form method='POST' enctype='multipart/form-data'>";

        echo "<div class='form-group'>";
        echo "<label>Тип данных:</label>";
        echo "<select name='data_type' id='data_type' onchange='updateFileInput()'>";
        echo "<option value='crm'>CRM данные (CSV)</option>";
        echo "<option value='ads'>Рекламные данные (JSON)</option>";
        echo "</select>";
        echo "</div>";

        echo "<div class='form-group' id='csv_upload'>";
        echo "<label>Загрузить CSV файл:</label>";
        echo "<input type='file' name='csv_file' accept='.csv'>";
        echo "<p class='info'>Формат CSV: email, phone, deal_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term, amount_uah, is_paid, is_failed, is_pending, created_at</p>";
        echo "</div>";

        echo "<div class='form-group' id='json_upload' style='display:none;'>";
        echo "<label>Загрузить JSON файл:</label>";
        echo "<input type='file' name='json_file' accept='.json'>";
        echo "<p class='info'>Формат JSON: массив объектов с полями date_start, account_id, campaign_name, spend, clicks, impressions и т.д.</p>";
        echo "</div>";

        echo "<div class='form-group'>";
        echo "<label>Переопределить дату (опционально):</label>";
        echo "<input type='text' name='date_override' placeholder='YYYY-MM-DD' value=''>";
        echo "<p class='info'>Если указано - все записи получат эту дату вместо оригинальной</p>";
        echo "</div>";

        echo "<button type='submit'>📦 Импортировать данные</button>";
        echo "</form>";
        echo "</div>";

        // Примеры форматов
        echo "<div class='step'>";
        echo "<div class='step-title'>📋 Примеры форматов данных</div>";

        echo "<h3>Пример CSV (CRM):</h3>";
        echo "<pre>";
        echo "email,phone,deal_id,utm_source,utm_medium,utm_campaign,amount_uah,is_paid,created_at\n";
        echo "test@example.com,+380123456789,deal123,facebook,cpc,summer_sale,50000,1,2024-01-15\n";
        echo "user@example.com,+380987654321,deal124,instagram,cpc,winter_promo,75000,1,2024-01-16";
        echo "</pre>";

        echo "<h3>Пример JSON (Реклама):</h3>";
        echo "<pre>";
        echo json_encode([
            [
                'date_start' => '2024-01-15',
                'date_stop' => '2024-01-15',
                'account_id' => '123456',
                'campaign_id' => '789012',
                'adset_id' => '345678',
                'ad_id' => '901234',
                'publisher_platform' => 'facebook',
                'platform_position' => 'feed',
                'account_name' => 'My Account',
                'campaign_name' => 'Summer Sale',
                'adset_name' => 'Target Audience 1',
                'ad_name' => 'Ad Creative 1',
                'spend' => '150.50',
                'clicks' => '45',
                'impressions' => '1200',
                'reach' => '980',
                'cpm' => '12.54',
                'ctr' => '3.75'
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "</pre>";
        echo "</div>";

        echo "<script>
        function updateFileInput() {
            var dataType = document.getElementById('data_type').value;
            var csvUpload = document.getElementById('csv_upload');
            var jsonUpload = document.getElementById('json_upload');

            if (dataType === 'crm') {
                csvUpload.style.display = 'block';
                jsonUpload.style.display = 'none';
            } else {
                csvUpload.style.display = 'none';
                jsonUpload.style.display = 'block';
            }
        }
        </script>";

        echo "<div class='info' style='margin-top: 30px;'>";
        echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<div class='step' style='border: 2px solid #ef4444;'>";
    echo "<h2 class='error'>❌ Ошибка импорта</h2>";
    echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background:#fef2f2; padding:10px; border-radius:5px; color:#ef4444;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    echo "<p><a href='import_historical_data.php'>← Попробовать снова</a></p>";
    echo "</div>";

    $logger->error('Ошибка импорта исторических данных', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
