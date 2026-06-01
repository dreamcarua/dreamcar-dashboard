<?php
// === handler_bulk.php ===
// НАЗНАЧЕНИЕ: Автоматический обработчик входящих данных (CRM + реклама)
// ИСПОЛЬЗОВАНИЕ: POST запрос с JSON данными или автоматический cron в 9:00
// СВЯЗИ: api/process_ads.php, core/models/CrmDeal.php, core/models/AdsData.php

ini_set('max_execution_time', 600);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/models/CrmDeal.php';
require_once __DIR__ . '/core/models/AdsData.php';
require_once __DIR__ . '/core/models/WebhookLog.php';
require_once __DIR__ . '/core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

// Определить формат ответа
$format = $_GET['format'] ?? 'json';
$isHtml = ($format === 'html');

if ($isHtml) {
    echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>📦 Обработка данных | UTM Dashboard</title>
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
<body>
<h1>📦 Автоматическая обработка данных</h1>";
}

try {
    $results = [];

    // ========================================
    // 1. Обработка CRM данных (если есть)
    // ========================================

    $crmData = null;
    $crmSource = null;

    // Проверить POST данные для CRM
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['crm_data'])) {
        $crmData = json_decode($_POST['crm_data'], true);
        $crmSource = 'POST';
    }

    // Проверить JSON body для CRM
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crmData === null) {
        $input = file_get_contents('php://input');
        $jsonData = json_decode($input, true);

        if (isset($jsonData['crm_data']) && is_array($jsonData['crm_data'])) {
            $crmData = $jsonData['crm_data'];
            $crmSource = 'JSON body';
        }
    }

    // Обработать CRM данные
    if ($crmData && is_array($crmData)) {
        if ($isHtml) {
            echo "<div class='step'>";
            echo "<div class='step-title'>📊 Обработка CRM данных</div>";
            echo "<p class='info'>Источник: $crmSource</p>";
            echo "<p class='info'>Получено записей: " . count($crmData) . "</p>";
        }

        $crmStartTime = microtime(true);
        $stats = CrmDeal::batchUpsert($crmData, 500);
        $crmProcessingTime = round(microtime(true) - $crmStartTime, 3);

        $results['crm'] = [
            'success' => true,
            'source' => $crmSource,
            'records_received' => count($crmData),
            'new' => $stats['new'],
            'updated' => $stats['updated'],
            'total_in_db' => CrmDeal::count()
        ];

        // Логировать в webhook_log
        WebhookLog::create(
            webhookType: 'crm',
            eventType: 'bulk',
            rawData: $crmData,
            processedData: $results['crm'],
            dealId: null,
            recordsCount: count($crmData),
            success: true,
            errorMessage: null,
            processingTime: $crmProcessingTime
        );

        if ($isHtml) {
            echo "<p class='success'>✅ Новых: {$stats['new']}</p>";
            echo "<p class='success'>✅ Обновлено: {$stats['updated']}</p>";
            echo "<p class='info'>📊 Всего в БД: " . CrmDeal::count() . "</p>";
            echo "</div>";
        }

        $logger->success('CRM данные обработаны через handler_bulk', $results['crm']);
    }

    // ========================================
    // 2. Обработка рекламных данных
    // ========================================

    $adsData = null;
    $adsSource = null;

    // Проверить POST данные для рекламы
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ads_data'])) {
        $adsData = json_decode($_POST['ads_data'], true);
        $adsSource = 'POST';
    }

    // Проверить JSON body для рекламы
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adsData === null) {
        $input = file_get_contents('php://input');
        $jsonData = json_decode($input, true);

        if (isset($jsonData['ads_data']) && is_array($jsonData['ads_data'])) {
            $adsData = $jsonData['ads_data'];
            $adsSource = 'JSON body';
        } elseif (isset($jsonData['data']) && is_array($jsonData['data'])) {
            // Альтернативный формат
            $adsData = $jsonData['data'];
            $adsSource = 'JSON body (data)';
        } elseif (is_array($jsonData) && !isset($jsonData['crm_data'])) {
            // Если это просто массив - считаем что это реклама
            $adsData = $jsonData;
            $adsSource = 'JSON body (array)';
        }
    }

    // Если нет данных в POST - проверить последний файл facebook_ads.json
    if ($adsData === null && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $dataDir = DATA_DIR . '/make_request/data';

        if (is_dir($dataDir)) {
            $dateFolders = glob($dataDir . '/*', GLOB_ONLYDIR);

            if (!empty($dateFolders)) {
                rsort($dateFolders); // Сортировать по убыванию (последняя дата первая)

                foreach ($dateFolders as $folder) {
                    $fbFile = $folder . '/facebook_ads.json';
                    if (file_exists($fbFile)) {
                        $fileContent = file_get_contents($fbFile);
                        $adsData = json_decode($fileContent, true);
                        $adsSource = 'File: ' . basename($folder) . '/facebook_ads.json';
                        break;
                    }
                }
            }
        }
    }

    // Обработать рекламные данные
    if ($adsData && is_array($adsData)) {
        if ($isHtml) {
            echo "<div class='step'>";
            echo "<div class='step-title'>📢 Обработка рекламных данных</div>";
            echo "<p class='info'>Источник: $adsSource</p>";
            echo "<p class='info'>Получено записей: " . count($adsData) . "</p>";
        }

        $adsStartTime = microtime(true);
        $inserted = AdsData::insertFromFacebook($adsData);
        $adsStats = AdsData::getTotalStats();
        $adsProcessingTime = round(microtime(true) - $adsStartTime, 3);

        $results['ads'] = [
            'success' => true,
            'source' => $adsSource,
            'records_received' => count($adsData),
            'records_inserted' => $inserted,
            'total_in_db' => AdsData::count(),
            'total_spend' => $adsStats['total_spend'],
            'total_clicks' => $adsStats['total_clicks'],
            'total_impressions' => $adsStats['total_impressions'],
            'avg_cpm' => $adsStats['avg_cpm'],
            'avg_ctr' => $adsStats['avg_ctr']
        ];

        // Логировать в webhook_log
        WebhookLog::create(
            webhookType: 'ads',
            eventType: 'bulk',
            rawData: $adsData,
            processedData: $results['ads'],
            dealId: null,
            recordsCount: count($adsData),
            success: true,
            errorMessage: null,
            processingTime: $adsProcessingTime
        );

        if ($isHtml) {
            echo "<p class='success'>✅ Вставлено/обновлено: $inserted</p>";
            echo "<p class='info'>📊 Всего в БД: " . AdsData::count() . "</p>";
            echo "<p class='info'>💰 Общие затраты: " . number_format($adsStats['total_spend'], 2) . " UAH</p>";
            echo "<p class='info'>👆 Кликов: " . number_format($adsStats['total_clicks']) . "</p>";
            echo "<p class='info'>👁️ Показов: " . number_format($adsStats['total_impressions']) . "</p>";
            echo "</div>";
        }

        $logger->success('Рекламные данные обработаны через handler_bulk', $results['ads']);
    }

    // ========================================
    // 3. Итоги
    // ========================================

    $duration = round(microtime(true) - $startTime, 2);

    if (empty($results)) {
        if ($isHtml) {
            echo "<div class='step'>";
            echo "<p class='info'>ℹ️ Нет данных для обработки</p>";
            echo "<p class='info'>Отправьте POST запрос с JSON данными:</p>";
            echo "<pre style='background:#f3f4f6; padding:10px; border-radius:5px;'>";
            echo json_encode([
                'crm_data' => [
                    ['email' => 'test@example.com', 'amount_uah' => 100, 'is_paid' => true]
                ],
                'ads_data' => [
                    ['date_start' => '2024-01-01', 'spend' => 50, 'clicks' => 10]
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            echo "</pre>";
            echo "</div>";
        }

        $results = [
            'success' => false,
            'message' => 'No data to process',
            'info' => 'Send POST request with crm_data and/or ads_data'
        ];
    } else {
        $results['success'] = true;
        $results['duration'] = $duration;
        $results['processed_at'] = date('Y-m-d H:i:s');
    }

    if ($isHtml) {
        echo "<div class='stats'>";
        echo "<h2>📊 Итоговая статистика</h2>";
        echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";

        if (isset($results['crm'])) {
            echo "<p><strong>CRM записей:</strong> {$results['crm']['records_received']} (новых: {$results['crm']['new']}, обновлено: {$results['crm']['updated']})</p>";
        }

        if (isset($results['ads'])) {
            echo "<p><strong>Рекламных записей:</strong> {$results['ads']['records_received']} (вставлено: {$results['ads']['records_inserted']})</p>";
        }

        echo "</div>";

        echo "<div class='success'>";
        echo "<h2>✅ Обработка завершена!</h2>";
        echo "<p><a href='index.php'>← Вернуться к дашборду</a></p>";
        echo "</div>";

        echo "</body></html>";
    } else {
        // JSON response
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    $duration = round(microtime(true) - $startTime, 2);

    // Логировать ошибку в webhook_log
    try {
        $rawInput = file_get_contents('php://input');
        WebhookLog::create(
            webhookType: isset($adsData) ? 'ads' : 'crm',
            eventType: 'bulk_error',
            rawData: $rawInput ?: null,
            processedData: null,
            dealId: null,
            recordsCount: 0,
            success: false,
            errorMessage: $e->getMessage(),
            processingTime: $duration
        );
    } catch (Exception $logError) {
        // Игнорировать ошибку логирования
    }

    if ($isHtml) {
        echo "<div class='step' style='border: 2px solid #ef4444;'>";
        echo "<h2 class='error'>❌ Ошибка обработки</h2>";
        echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre style='background:#fef2f2; padding:10px; border-radius:5px; color:#ef4444;'>";
        echo htmlspecialchars($e->getTraceAsString());
        echo "</pre>";
        echo "</div>";
        echo "</body></html>";
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => DEBUG ? $e->getTraceAsString() : null,
            'duration' => $duration
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    $logger->error('Ошибка в handler_bulk', [
        'error' => $e->getMessage(),
        'duration' => $duration
    ]);
}
