<?php
// Meta Marketing API Configuration

// App Credentials (з ENV, див. .env.example + SECURITY.md)
define('META_APP_ID', getenv('META_APP_ID') ?: '272589421753029');
define('META_APP_SECRET', getenv('META_APP_SECRET') ?: '');
define('META_CLIENT_TOKEN', getenv('META_CLIENT_TOKEN') ?: '');

// API Version
define('META_API_VERSION', 'v21.0');
define('META_API_BASE_URL', 'https://graph.facebook.com/' . META_API_VERSION);

// Ad Accounts
$AD_ACCOUNTS = [
    'test_uah' => [
        'id' => 'act_1320925609223169',
        'name' => 'DreamCar.ua CLUB UAH TEST',
        'currency' => 'UAH'
    ],
    'club_uah' => [
        'id' => 'act_1057590556523878',
        'name' => 'DreamCar.ua CLUB UAH',
        'currency' => 'UAH'
    ],
    'club_usd' => [
        'id' => 'act_1469553690525881',
        'name' => 'DreamCar.ua CLUB USD',
        'currency' => 'USD'
    ],
    'main_uah' => [
        'id' => 'act_4136058269783354',
        'name' => 'DreamCar.ua UAH',
        'currency' => 'UAH'
    ]
];

// Paths
define('ADS_ROOT', __DIR__);
define('ADS_DATA_DIR', ADS_ROOT . '/data');
define('ADS_LOG_FILE', ADS_DATA_DIR . '/api_log.json');

// Create data directory if not exists
if (!is_dir(ADS_DATA_DIR)) {
    mkdir(ADS_DATA_DIR, 0755, true);
}

// Initialize log file
if (!file_exists(ADS_LOG_FILE)) {
    file_put_contents(ADS_LOG_FILE, json_encode([
        'created' => date('Y-m-d H:i:s'),
        'logs' => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
