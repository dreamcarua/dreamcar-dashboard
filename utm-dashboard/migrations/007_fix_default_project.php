<?php
// Одноразовый: установить active_project = DreamCar AI
$file = __DIR__ . '/../config/dashboard_settings.json';
$data = json_decode(file_get_contents($file), true) ?: [];
$data['active_project'] = 'DreamCar AI';
$data['last_updated'] = date('Y-m-d H:i:s');
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
header('Content-Type: application/json');
echo json_encode(['success' => true, 'active_project' => $data['active_project']]);
