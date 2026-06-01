<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../lib/MetaAPI.php';
require_once '../lib/helpers.php';

$accountId = $_GET['account_id'] ?? '';
$token = $_GET['token'] ?? '';

$response = [
    'success' => false,
    'data' => null,
    'error' => null
];

try {
    if (empty($accountId)) {
        throw new Exception('Account ID не указан');
    }

    if (empty($token)) {
        throw new Exception('Access Token не указан');
    }

    $api = new MetaAPI(resolveAccessToken($token));
    $result = $api->getCampaigns($accountId);

    // Debug: показать сырой ответ
    $response['raw_response'] = $result;

    if (isset($result['error'])) {
        // Детальная информация об ошибке
        $errorMsg = $result['error']['message'] ?? 'API Error';
        $errorCode = $result['error']['code'] ?? 'unknown';
        $errorType = $result['error']['type'] ?? 'unknown';
        throw new Exception("[$errorCode] $errorType: $errorMsg");
    }

    $response['success'] = true;
    $response['data'] = $result;
    $response['count'] = isset($result['data']) ? count($result['data']) : 0;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
