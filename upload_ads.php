<?php
// === upload_ads.php ===
// Страница для загрузки статистики рекламы (Facebook/Instagram)
// Позволяет загрузить данные о затратах на рекламу для расчета ROI

set_time_limit(300);
ini_set('memory_limit', '512M');

require_once 'config/app_config.php';
require_once 'core/Logger.php';

$logger = new Logger();
$message = '';
$messageType = '';

// Обработка загрузки файла
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ads_file'])) {
    $logger->info('Начало загрузки файла с рекламной статистикой');
    
    $file = $_FILES['ads_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmpName = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Проверка расширения
        if (!in_array($fileExt, ['csv', 'xlsx', 'xls'])) {
            $message = '❌ Неподдерживаемый формат файла. Используйте CSV или Excel (.xlsx, .xls)';
            $messageType = 'error';
        } else {
            try {
                // Парсить файл
                $parseResult = parseAdsFile($tmpName, $fileExt);
                $ads = $parseResult['ads'];
                $parseStats = $parseResult['stats'];
                
                if (empty($ads)) {
                    $message = '⚠️ Файл пуст или не удалось распарсить данные<br>';
                    $message .= 'Всего строк в файле: ' . $parseStats['total_rows'] . '<br>';
                    $message .= 'Обработано: ' . $parseStats['processed'] . '<br>';
                    $message .= 'Пропущено: ' . $parseStats['skipped'] . '<br>';
                    $messageType = 'warning';
                } else {
                    // Сохранить рекламные данные
                    $savedCount = saveAdsData($ads);
                    
                    // Статистика
                    $totalSpend = 0;
                    $totalClicks = 0;
                    $totalImpressions = 0;
                    $campaignsCount = 0;
                    $campaigns = [];
                    
                    foreach ($ads as $ad) {
                        $totalSpend += floatval($ad['spend'] ?? 0);
                        $totalClicks += intval($ad['clicks'] ?? 0);
                        $totalImpressions += intval($ad['impressions'] ?? 0);
                        
                        $campaignName = $ad['campaign_name'] ?? '';
                        if ($campaignName && !isset($campaigns[$campaignName])) {
                            $campaigns[$campaignName] = true;
                            $campaignsCount++;
                        }
                    }
                    
                    $logger->success('Рекламная статистика загружена', [
                        'total' => count($ads),
                        'saved' => $savedCount,
                        'total_spend' => $totalSpend,
                        'campaigns' => $campaignsCount
                    ]);
                    
                    $message = '✅ Успешно загружено ' . count($ads) . ' записей рекламной статистики<br>';
                    $message .= '💰 Общие затраты: ' . number_format($totalSpend, 2) . ' UAH<br>';
                    $message .= '👆 Кликов: ' . number_format($totalClicks) . '<br>';
                    $message .= '👁️ Показов: ' . number_format($totalImpressions) . '<br>';
                    $message .= '📊 Кампаний: ' . $campaignsCount . '<br>';
                    $message .= '💾 Сохранено записей: ' . $savedCount;
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $logger->error('Ошибка загрузки рекламной статистики', ['error' => $e->getMessage()]);
                $message = '❌ Ошибка: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } else {
        $message = '❌ Ошибка загрузки файла';
        $messageType = 'error';
    }
}

/**
 * Парсить файл с рекламной статистикой
 */
function parseAdsFile($filePath, $ext) {
    $ads = [];
    
    if ($ext === 'csv') {
        // Парсить CSV
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception('Не удалось открыть файл');
        }
        
        // Определить разделитель
        $delimiter = "\t";
        $escape = "\\";
        $headers = fgetcsv($handle, 0, $delimiter, '"', $escape);
        
        if (!$headers || count($headers) < 5) {
            // Попробовать запятую
            rewind($handle);
            $delimiter = ',';
            $headers = fgetcsv($handle, 0, $delimiter, '"', $escape);
        }
        
        if (!$headers || count($headers) < 5) {
            // Попробовать точку с запятой
            rewind($handle);
            $delimiter = ';';
            $headers = fgetcsv($handle, 0, $delimiter, '"', $escape);
        }
        
        if (!$headers || count($headers) < 5) {
            fclose($handle);
            throw new Exception('Не удалось определить формат файла. Используйте CSV с табуляцией, запятой или точкой с запятой');
        }
        
        // Нормализовать заголовки
        $headers = array_map('trim', $headers);
        
        $rowNum = 1;
        $processed = 0;
        $skipped = 0;
        $skippedReasons = [
            'empty_row' => 0,
            'incomplete_row' => 0,
            'no_spend' => 0
        ];
        
        while (($row = fgetcsv($handle, 0, $delimiter, '"', $escape)) !== false) {
            $rowNum++;
            
            // Пропустить пустые строки
            if (empty(array_filter($row))) {
                $skippedReasons['empty_row']++;
                continue;
            }
            
            if (count($row) < count($headers)) {
                $skippedReasons['incomplete_row']++;
                $skipped++;
                continue;
            }
            
            $ad = [];
            foreach ($headers as $index => $header) {
                $ad[$header] = isset($row[$index]) ? trim($row[$index]) : '';
            }
            
            // Проверить что есть Spend (затраты)
            $spend = floatval($ad['Spend'] ?? $ad['spend'] ?? 0);
            if ($spend <= 0) {
                $skippedReasons['no_spend']++;
                $skipped++;
                continue;
            }
            
            // Преобразовать в формат дашборда
            $converted = convertAdToFormat($ad);
            if ($converted) {
                $ads[] = $converted;
                $processed++;
            } else {
                $skipped++;
            }
        }
        
        fclose($handle);
        
        return [
            'ads' => $ads,
            'stats' => [
                'total_rows' => $rowNum - 1,
                'processed' => $processed,
                'skipped' => $skipped,
                'skipped_reasons' => $skippedReasons
            ]
        ];
    } else {
        throw new Exception('Excel файлы пока не поддерживаются. Конвертируйте в CSV');
    }
}

/**
 * Преобразовать рекламную запись в формат дашборда
 */
function convertAdToFormat($ad) {
    // Основные поля
    $date = !empty($ad['Дата']) ? trim($ad['Дата']) : (!empty($ad['Date']) ? trim($ad['Date']) : date('Y-m-d'));
    $campaignId = !empty($ad['Campaign ID']) ? trim($ad['Campaign ID']) : (!empty($ad['campaign_id']) ? trim($ad['campaign_id']) : '');
    $campaignName = !empty($ad['Campaign Name']) ? trim($ad['Campaign Name']) : (!empty($ad['campaign_name']) ? trim($ad['campaign_name']) : '');
    $adSetId = !empty($ad['Ad Set ID']) ? trim($ad['Ad Set ID']) : (!empty($ad['ad_set_id']) ? trim($ad['ad_set_id']) : '');
    $adSetName = !empty($ad['Ad Set Name']) ? trim($ad['Ad Set Name']) : (!empty($ad['ad_set_name']) ? trim($ad['ad_set_name']) : '');
    $adId = !empty($ad['Ad ID']) ? trim($ad['Ad ID']) : (!empty($ad['ad_id']) ? trim($ad['ad_id']) : '');
    $adName = !empty($ad['Ad Name']) ? trim($ad['Ad Name']) : (!empty($ad['ad_name']) ? trim($ad['ad_name']) : '');
    
    // Метрики
    $spend = floatval($ad['Spend'] ?? $ad['spend'] ?? 0);
    $clicks = intval($ad['Clicks'] ?? $ad['clicks'] ?? 0);
    $impressions = intval($ad['Impressions'] ?? $ad['impressions'] ?? 0);
    $reach = intval($ad['Reach'] ?? $ad['reach'] ?? 0);
    $linkClicks = intval($ad['Link Clicks'] ?? $ad['link_clicks'] ?? 0);
    $conversions = intval($ad['Conversions'] ?? $ad['conversions'] ?? 0);
    
    // Платформа и размещение
    $platform = !empty($ad['Platform']) ? trim($ad['Platform']) : (!empty($ad['platform']) ? trim($ad['platform']) : '');
    $placement = !empty($ad['Placement']) ? trim($ad['Placement']) : (!empty($ad['placement']) ? trim($ad['placement']) : '');
    $device = !empty($ad['Device']) ? trim($ad['Device']) : (!empty($ad['device']) ? trim($ad['device']) : '');
    
    // Нормализовать дату
    if (strpos($date, ' ') === false) {
        $date .= ' 00:00:00';
    }
    
    // Создать уникальный ключ для этой записи
    $uniqueKey = md5($date . '|' . $campaignId . '|' . $adSetId . '|' . $adId . '|' . $platform . '|' . $placement);
    
    return [
        'date' => $date,
        'campaign_id' => $campaignId,
        'campaign_name' => $campaignName,
        'ad_set_id' => $adSetId,
        'ad_set_name' => $adSetName,
        'ad_id' => $adId,
        'ad_name' => $adName,
        'spend' => $spend,
        'clicks' => $clicks,
        'impressions' => $impressions,
        'reach' => $reach,
        'link_clicks' => $linkClicks,
        'conversions' => $conversions,
        'platform' => strtolower($platform),
        'placement' => strtolower($placement),
        'device' => strtolower($device),
        'unique_key' => $uniqueKey,
        'created_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Сохранить рекламные данные
 */
function saveAdsData($ads) {
    $adsFile = DATA_DIR . '/ads_stats.json';
    
    // Загрузить существующие данные
    $existingData = [];
    if (file_exists($adsFile)) {
        $content = file_get_contents($adsFile);
        $existingData = json_decode($content, true) ?: [];
    }
    
    // Создать индекс по unique_key для быстрого поиска
    $existingKeys = [];
    foreach ($existingData as $item) {
        if (!empty($item['unique_key'])) {
            $existingKeys[$item['unique_key']] = true;
        }
    }
    
    // Добавить только новые записи
    $newCount = 0;
    foreach ($ads as $ad) {
        if (!isset($existingKeys[$ad['unique_key']])) {
            $existingData[] = $ad;
            $existingKeys[$ad['unique_key']] = true;
            $newCount++;
        }
    }
    
    // Сохранить
    saveJSON($adsFile, $existingData);
    
    return $newCount;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Загрузка рекламной статистики | UTM Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
    <style>
        .upload-container {
            max-width: 800px;
            margin: 4rem auto;
            padding: 2rem;
        }
        .upload-card {
            background: var(--gradient-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
        }
        .upload-form {
            margin: 2rem 0;
        }
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-input-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            background: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.3s;
        }
        .file-input-label:hover {
            border-color: var(--ai-green);
            background: rgba(16, 185, 129, 0.05);
        }
        .file-input-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .message.success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--ai-green);
            color: var(--ai-green);
        }
        .message.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--color-danger);
            color: var(--color-danger);
        }
        .message.warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid var(--color-warning);
            color: var(--color-warning);
        }
    </style>
</head>
<body>
    <div class="upload-container">
        <div class="upload-card">
            <h1>📊 Загрузка рекламной статистики</h1>
            <p class="text-muted">Загрузите CSV файл со статистикой рекламы (Facebook/Instagram) для расчета ROI</p>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="upload-form">
                <div class="file-input-wrapper">
                    <input type="file" name="ads_file" id="adsFile" class="file-input" accept=".csv,.xlsx,.xls" required>
                    <label for="adsFile" class="file-input-label">
                        <span class="file-input-icon">📁</span>
                        <span id="fileName">Нажмите для выбора файла</span>
                        <small class="text-muted" style="margin-top: 0.5rem;">CSV или Excel (.xlsx, .xls)</small>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem; min-height: 54px;">
                    📤 Загрузить статистику
                </button>
            </form>
            
            <div style="margin-top: 2rem; padding: 1rem; background: var(--bg-secondary); border-radius: 8px;">
                <h3 style="margin-bottom: 1rem;">📋 Формат файла:</h3>
                <ul style="line-height: 1.8;">
                    <li>Файл должен быть в формате CSV</li>
                    <li>Разделитель: табуляция, запятая или точка с запятой (определяется автоматически)</li>
                    <li>Первая строка должна содержать заголовки колонок</li>
                    <li><strong>Обязательные поля:</strong> Дата, Campaign Name, Spend</li>
                    <li><strong>Рекомендуемые поля:</strong> Clicks, Impressions, Reach, Platform, Placement</li>
                    <li>Поддерживаются файлы до 30,000+ записей</li>
                </ul>
                <p style="margin-top: 1rem; color: var(--text-muted);">
                    <strong>💡 Подсказка:</strong> Если у вас Excel файл, сохраните его как CSV перед загрузкой. Система автоматически определит разделитель.
                </p>
            </div>
            
            <div style="margin-top: 2rem; text-align: center;">
                <a href="index.php" class="btn btn-outline">← Вернуться к дашборду</a>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('adsFile').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || 'Нажмите для выбора файла';
            document.getElementById('fileName').textContent = fileName;
        });
    </script>
</body>
</html>


