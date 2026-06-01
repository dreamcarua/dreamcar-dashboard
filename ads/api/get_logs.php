<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../lib/MetaAPI.php';

$limit = $_GET['limit'] ?? 20;

$response = [
    'success' => true,
    'logs' => MetaAPI::getLogs($limit)
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
