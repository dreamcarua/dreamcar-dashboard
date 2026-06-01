<?php
// === upload_ads_mysql.php ===
// НАЗНАЧЕНИЕ: Интерфейс для загрузки рекламных данных из CSV/JSON в MySQL
// СВЯЗИ: config/app_config.php, core/Database.php, core/models/AdsData.php

ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

require_once 'config/app_config.php';
require_once 'core/Database.php';
require_once 'core/models/AdsData.php';
require_once 'core/Logger.php';

$logger = new Logger();
$message = '';
$messageType = '';

// Обработка очистки базы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_database') {
    $logger->warning('Начало очистки таблицы ads_data');

    try {
        AdsData::truncate();

        $logger->success('Таблица ads_data очищена');
        $message = '✅ Таблица ads_data успешно очищена! Теперь можно загрузить данные с нуля.';
        $messageType = 'success';
    } catch (Exception $e) {
        $logger->error('Ошибка очистки таблицы', ['error' => $e->getMessage()]);
        $message = '❌ Ошибка: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Обработка загрузки файла
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ads_file'])) {
    $logger->info('Начало загрузки файла с рекламными данными');

    $file = $_FILES['ads_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmpName = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExt === 'json') {
            // JSON формат
            try {
                $jsonContent = file_get_contents($tmpName);
                $adsData = json_decode($jsonContent, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Ошибка парсинга JSON: ' . json_last_error_msg());
                }

                if (!is_array($adsData)) {
                    throw new Exception('JSON должен содержать массив данных');
                }

                // Вставить через AdsData::insertFromFacebook
                $inserted = AdsData::insertFromFacebook($adsData);
                $adsStats = AdsData::getTotalStats();

                $logger->success('Рекламные данные загружены из JSON', [
                    'records' => count($adsData),
                    'inserted' => $inserted
                ]);

                $message = '✅ Успешно обработано ' . count($adsData) . ' записей<br>';
                $message .= '<br><strong>💾 Загрузка в MySQL:</strong><br>';
                $message .= '📊 Вставлено/обновлено: ' . $inserted . '<br>';
                $message .= '📊 Всего в БД: ' . AdsData::count() . '<br>';
                $message .= '<br><strong>📈 Статистика:</strong><br>';
                $message .= '💰 Общие затраты: ' . number_format($adsStats['total_spend'], 2) . ' UAH<br>';
                $message .= '👆 Кликов: ' . number_format($adsStats['total_clicks']) . '<br>';
                $message .= '👁️ Показов: ' . number_format($adsStats['total_impressions']) . '<br>';
                $message .= '📍 Охват: ' . number_format($adsStats['total_reach']) . '<br>';

                $messageType = 'success';

            } catch (Exception $e) {
                $logger->error('Ошибка загрузки JSON', ['error' => $e->getMessage()]);
                $message = '❌ Ошибка: ' . $e->getMessage();
                $messageType = 'error';
            }

        } elseif ($fileExt === 'csv') {
            // CSV формат
            try {
                $handle = fopen($tmpName, 'r');
                if (!$handle) {
                    throw new Exception('Не удалось открыть файл');
                }

                // Определить разделитель
                $delimiter = detectDelimiter($tmpName);
                $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');

                if (!$headers) {
                    throw new Exception('Не удалось прочитать заголовки CSV');
                }

                // Нормализовать заголовки
                $headers = array_map('trim', $headers);

                $adsData = [];
                while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                    if (count($row) < count($headers)) {
                        continue; // Пропустить неполные строки
                    }

                    $adRow = array_combine($headers, $row);
                    $adsData[] = convertCsvToFacebookFormat($adRow);
                }

                fclose($handle);

                if (empty($adsData)) {
                    throw new Exception('Не удалось распарсить данные из CSV');
                }

                // Вставить через AdsData::insertFromFacebook
                $inserted = AdsData::insertFromFacebook($adsData);
                $adsStats = AdsData::getTotalStats();

                $logger->success('Рекламные данные загружены из CSV', [
                    'records' => count($adsData),
                    'inserted' => $inserted
                ]);

                $message = '✅ Успешно обработано ' . count($adsData) . ' записей из CSV<br>';
                $message .= '<br><strong>💾 Загрузка в MySQL:</strong><br>';
                $message .= '📊 Вставлено/обновлено: ' . $inserted . '<br>';
                $message .= '📊 Всего в БД: ' . AdsData::count() . '<br>';
                $message .= '<br><strong>📈 Статистика:</strong><br>';
                $message .= '💰 Общие затраты: ' . number_format($adsStats['total_spend'], 2) . ' UAH<br>';
                $message .= '👆 Кликов: ' . number_format($adsStats['total_clicks']) . '<br>';
                $message .= '👁️ Показов: ' . number_format($adsStats['total_impressions']) . '<br>';
                $message .= '📍 Охват: ' . number_format($adsStats['total_reach']) . '<br>';

                $messageType = 'success';

            } catch (Exception $e) {
                $logger->error('Ошибка загрузки CSV', ['error' => $e->getMessage()]);
                $message = '❌ Ошибка: ' . $e->getMessage();
                $messageType = 'error';
            }

        } else {
            $message = '❌ Неподдерживаемый формат файла. Используйте JSON или CSV';
            $messageType = 'error';
        }
    } else {
        $message = '❌ Ошибка загрузки файла';
        $messageType = 'error';
    }
}

/**
 * Определить разделитель CSV
 */
function detectDelimiter($filePath) {
    $handle = fopen($filePath, 'r');
    $firstLine = fgets($handle);
    fclose($handle);

    $delimiters = ["\t", ',', ';'];
    $maxCount = 0;
    $detectedDelimiter = ',';

    foreach ($delimiters as $delimiter) {
        $count = substr_count($firstLine, $delimiter);
        if ($count > $maxCount) {
            $maxCount = $count;
            $detectedDelimiter = $delimiter;
        }
    }

    return $detectedDelimiter;
}

/**
 * Преобразовать строку CSV в формат Facebook Ads
 */
function convertCsvToFacebookFormat($row) {
    // Маппинг полей CSV → Facebook Ads формат
    return [
        'date_start' => $row['date_start'] ?? $row['Date'] ?? $row['Дата'] ?? date('Y-m-d'),
        'date_stop' => $row['date_stop'] ?? $row['Date'] ?? $row['Дата'] ?? date('Y-m-d'),
        'account_id' => $row['account_id'] ?? $row['Account ID'] ?? '',
        'account_name' => $row['account_name'] ?? $row['Account Name'] ?? '',
        'campaign_id' => $row['campaign_id'] ?? $row['Campaign ID'] ?? '',
        'campaign_name' => $row['campaign_name'] ?? $row['Campaign Name'] ?? '',
        'adset_id' => $row['adset_id'] ?? $row['Ad Set ID'] ?? '',
        'adset_name' => $row['adset_name'] ?? $row['Ad Set Name'] ?? '',
        'ad_id' => $row['ad_id'] ?? $row['Ad ID'] ?? '',
        'ad_name' => $row['ad_name'] ?? $row['Ad Name'] ?? '',
        'publisher_platform' => strtolower($row['publisher_platform'] ?? $row['Platform'] ?? 'facebook'),
        'platform_position' => strtolower($row['platform_position'] ?? $row['Placement'] ?? ''),
        'spend' => floatval($row['spend'] ?? $row['Spend'] ?? 0),
        'clicks' => intval($row['clicks'] ?? $row['Clicks'] ?? 0),
        'impressions' => intval($row['impressions'] ?? $row['Impressions'] ?? 0),
        'reach' => intval($row['reach'] ?? $row['Reach'] ?? 0),
        'unique_clicks' => intval($row['unique_clicks'] ?? $row['Link Clicks'] ?? 0),
        'cpm' => floatval($row['cpm'] ?? $row['CPM'] ?? 0),
        'ctr' => floatval($row['ctr'] ?? $row['CTR'] ?? 0),
        'account_currency' => $row['account_currency'] ?? $row['Currency'] ?? 'UAH',
        'buying_type' => $row['buying_type'] ?? $row['Buying Type'] ?? 'AUCTION',
        'objective' => $row['objective'] ?? $row['Objective'] ?? '',
        'optimization_goal' => $row['optimization_goal'] ?? $row['Optimization Goal'] ?? ''
    ];
}

// Получить текущую статистику
$currentStats = AdsData::getTotalStats();
$totalRecords = AdsData::count();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Загрузка рекламных данных | UTM Dashboard</title>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <div class="header-content">
                <div class="header-left">
                    <h1 class="gradient-text">📊 Загрузка рекламных данных</h1>
                    <p class="text-muted">Массовая загрузка данных Facebook/Instagram Ads из JSON или CSV</p>
                </div>
                <div class="header-right">
                    <a href="index.php" class="btn btn-secondary">← Дашборд</a>
                    <a href="webhook_logs.php" class="btn btn-secondary">📝 Webhook Логи</a>
                </div>
            </div>
        </header>

        <!-- Текущая статистика -->
        <section class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($totalRecords); ?></div>
                        <div class="stat-label">Записей в БД</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($currentStats['total_spend'], 2); ?></div>
                        <div class="stat-label">Общие затраты (UAH)</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">👆</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($currentStats['total_clicks']); ?></div>
                        <div class="stat-label">Кликов</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">👁️</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($currentStats['total_impressions']); ?></div>
                        <div class="stat-label">Показов</div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Форма загрузки -->
        <section style="background: white; padding: 32px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px;">
            <h2 style="margin: 0 0 24px 0; font-size: 20px; font-weight: 700;">📤 Загрузить файл</h2>

            <form method="POST" enctype="multipart/form-data">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                        Выберите JSON или CSV файл с рекламными данными
                    </label>
                    <input type="file" name="ads_file" accept=".json,.csv" required
                           style="width: 100%; padding: 12px; border: 2px dashed #d1d5db; border-radius: 8px; background: #f9fafb; cursor: pointer;">
                    <p style="font-size: 13px; color: #6b7280; margin-top: 8px;">
                        Поддерживаемые форматы: JSON (формат Facebook Ads API) или CSV
                    </p>
                </div>

                <button type="submit" class="btn btn-primary" style="font-size: 16px; padding: 12px 32px;">
                    📊 Загрузить данные
                </button>
            </form>
        </section>

        <!-- Формат данных -->
        <section style="background: white; padding: 32px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px;">
            <h2 style="margin: 0 0 20px 0; font-size: 20px; font-weight: 700;">📋 Формат данных</h2>

            <h3 style="font-size: 16px; font-weight: 600; margin: 20px 0 12px 0;">Формат JSON (рекомендуется):</h3>
            <pre style="background: #f3f4f6; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px;">[
  {
    "date_start": "2024-01-15",
    "date_stop": "2024-01-15",
    "account_id": "123456",
    "account_name": "My Account",
    "campaign_id": "789012",
    "campaign_name": "Summer Sale",
    "adset_id": "345678",
    "adset_name": "Target Audience 1",
    "ad_id": "901234",
    "ad_name": "Ad Creative 1",
    "publisher_platform": "facebook",
    "platform_position": "feed",
    "spend": "150.50",
    "clicks": "45",
    "impressions": "1200",
    "reach": "980",
    "cpm": "12.54",
    "ctr": "3.75"
  }
]</pre>

            <h3 style="font-size: 16px; font-weight: 600; margin: 20px 0 12px 0;">Формат CSV:</h3>
            <pre style="background: #f3f4f6; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px;">date_start,account_id,campaign_name,adset_name,ad_name,platform_position,spend,clicks,impressions
2024-01-15,123456,Summer Sale,Target Audience 1,Ad Creative 1,feed,150.50,45,1200</pre>

            <p style="font-size: 13px; color: #6b7280; margin-top: 12px;">
                <strong>Важно:</strong> Уникальность записи определяется по комбинации: date_start + account_id + campaign_id + adset_id + ad_id + publisher_platform + platform_position
            </p>
        </section>

        <!-- Очистка базы -->
        <section style="background: #fef2f2; padding: 32px; border-radius: 12px; border: 2px solid #fecaca;">
            <h2 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #991b1b;">⚠️ Опасная зона</h2>
            <p style="margin-bottom: 16px; color: #7f1d1d;">
                Очистка таблицы ads_data удалит ВСЕ рекламные данные без возможности восстановления!
            </p>
            <form method="POST" onsubmit="return confirm('Вы уверены? Это действие нельзя отменить!');">
                <input type="hidden" name="action" value="clear_database">
                <button type="submit" class="btn" style="background: #dc2626; color: white;">
                    🗑️ Очистить таблицу ads_data
                </button>
            </form>
        </section>
    </div>
</body>
</html>
