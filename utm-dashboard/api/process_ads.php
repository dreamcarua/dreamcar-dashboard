<?php
// === process_ads.php ===
// API для обработки входящих рекламных данных из Facebook Ads
// НАЗНАЧЕНИЕ: Принимает JSON с данными рекламы, конвертирует в UTM формат и сохраняет в MySQL
// ИСПОЛЬЗОВАНИЕ: POST запрос с JSON payload или GET для тестирования

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/AdsData.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

try {
    // Определить метод запроса
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        // Получить JSON из тела запроса
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if ($data === null) {
            throw new Exception('Invalid JSON payload');
        }

        // Проверить формат данных
        if (!is_array($data)) {
            throw new Exception('Expected array of advertising records');
        }

        // Если это объект с ключом data
        if (isset($data['data']) && is_array($data['data'])) {
            $adsData = $data['data'];
        } else {
            $adsData = $data;
        }

        $logger->info('Получены рекламные данные', [
            'records' => count($adsData),
            'method' => 'POST'
        ]);

    } elseif ($method === 'GET') {
        // Режим тестирования - прочитать из файла
        $testFile = __DIR__ . '/../data/make_request/data/' . date('Y-m-d') . '/facebook_ads.json';

        if (!file_exists($testFile)) {
            // Попробовать найти последний файл
            $dataDir = __DIR__ . '/../data/make_request/data';
            $dateFolders = glob($dataDir . '/*', GLOB_ONLYDIR);

            if (!empty($dateFolders)) {
                rsort($dateFolders); // Сортировать по убыванию даты
                foreach ($dateFolders as $folder) {
                    $fbFile = $folder . '/facebook_ads.json';
                    if (file_exists($fbFile)) {
                        $testFile = $fbFile;
                        break;
                    }
                }
            }
        }

        if (!file_exists($testFile)) {
            throw new Exception('Test file not found: ' . $testFile);
        }

        $fileContent = file_get_contents($testFile);
        $adsData = json_decode($fileContent, true);

        if ($adsData === null) {
            throw new Exception('Invalid JSON in test file');
        }

        $logger->info('Загружены тестовые данные', [
            'file' => $testFile,
            'records' => count($adsData),
            'method' => 'GET'
        ]);

    } else {
        throw new Exception('Method not allowed. Use POST or GET');
    }

    // Валидация данных
    if (empty($adsData)) {
        throw new Exception('No advertising data provided');
    }

    // Обработать и вставить данные
    $inserted = AdsData::insertFromFacebook($adsData);

    $duration = round(microtime(true) - $startTime, 2);

    // Получить статистику
    $stats = AdsData::getTotalStats();

    $response = [
        'success' => true,
        'message' => 'Advertising data processed successfully',
        'stats' => [
            'records_received' => count($adsData),
            'records_inserted' => $inserted,
            'total_in_db' => AdsData::count(),
            'total_spend' => $stats['total_spend'],
            'total_clicks' => $stats['total_clicks'],
            'total_impressions' => $stats['total_impressions'],
            'avg_cpm' => $stats['avg_cpm'],
            'avg_ctr' => $stats['avg_ctr']
        ],
        'duration' => $duration
    ];

    $logger->success('Рекламные данные обработаны', [
        'records' => count($adsData),
        'inserted' => $inserted,
        'duration' => $duration
    ]);

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);

    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => DEBUG ? $e->getTraceAsString() : null
    ];

    $logger->error('Ошибка обработки рекламных данных', [
        'error' => $e->getMessage()
    ]);

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
