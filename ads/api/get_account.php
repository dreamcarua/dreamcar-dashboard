<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../lib/MetaAPI.php';
require_once '../lib/helpers.php';

$accountId = $_GET['account_id'] ?? '';
$tokenMarker = $_GET['token'] ?? '';

$response = [
    'success' => false,
    'data' => null,
    'error' => null
];

try {
    if (empty($accountId)) {
        throw new Exception('Account ID не указан');
    }

    if (empty($tokenMarker)) {
        throw new Exception('Access Token не указан');
    }

    $api = new MetaAPI(resolveAccessToken($tokenMarker));
    $result = $api->getAccountInfo($accountId);

    if (isset($result['error'])) {
        throw new Exception($result['error']['message'] ?? 'API Error');
    }

    $response['success'] = true;
    $response['data'] = $result;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
