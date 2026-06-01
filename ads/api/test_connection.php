<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../lib/MetaAPI.php';

$method = $_POST['method'] ?? '';

$response = [
    'success' => false,
    'method' => $method,
    'data' => null,
    'error' => null
];

try {
    $api = new MetaAPI();

    switch ($method) {
        case 'app_token':
            // App Access Token: app_id|app_secret
            $token = META_APP_ID . '|' . META_APP_SECRET;
            $api->setAccessToken($token);
            break;

        case 'client_token':
            // Client Token
            $token = META_CLIENT_TOKEN;
            $api->setAccessToken($token);
            break;

        case 'user_token':
            // User Access Token from input
            $token = $_POST['token'] ?? '';
            if (empty($token)) {
                throw new Exception('User Access Token не предоставлен');
            }
            $api->setAccessToken($token);
            break;

        default:
            throw new Exception('Неизвестный метод: ' . $method);
    }

    // Для App/Client Token - тестируем прямой доступ к аккаунтам
    // Для User Token - тестируем через /me
    global $AD_ACCOUNTS;

    $accessible_accounts = [];
    $test_result = null;

    if ($method === 'user_token') {
        // User Token - проверяем через /me
        $test_result = $api->testConnection();
        if (isset($test_result['error'])) {
            throw new Exception($test_result['error']['message'] ?? 'Unknown error');
        }

        // Пробуем получить список аккаунтов
        $accounts = $api->getAdAccounts();
        if (isset($accounts['data'])) {
            $accessible_accounts = $accounts['data'];
        }
    } else {
        // App/Client Token - проверяем прямой доступ к каждому аккаунту
        foreach ($AD_ACCOUNTS as $key => $account) {
            $accountData = $api->getAccount($account['id']);

            if (!isset($accountData['error'])) {
                $accessible_accounts[] = [
                    'key' => $key,
                    'id' => $account['id'],
                    'name' => $accountData['name'] ?? $account['name'],
                    'account_status' => $accountData['account_status'] ?? null,
                    'currency' => $accountData['currency'] ?? $account['currency']
                ];
            }
        }
    }

    $response['success'] = true;
    $response['data'] = [
        'token_type' => $method,
        'test_result' => $test_result,
        'message' => 'Подключение успешно',
        'accounts_accessible' => count($accessible_accounts) > 0,
        'accounts_count' => count($accessible_accounts),
        'accounts' => $accessible_accounts
    ];

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
