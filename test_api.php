<?php
// Тест API
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Тест API</h1>";

// Проверка файла данных
$dataFile = __DIR__ . '/data/utm_clean.json';
echo "<h2>1. Проверка файла данных</h2>";
echo "Путь: {$dataFile}<br>";
echo "Существует: " . (file_exists($dataFile) ? "✅ Да" : "❌ Нет") . "<br>";

if (file_exists($dataFile)) {
    $content = file_get_contents($dataFile);
    $data = json_decode($content, true);
    echo "Размер файла: " . filesize($dataFile) . " байт<br>";
    echo "Записей: " . count($data) . "<br>";
    echo "Первая запись: <pre>" . print_r($data[0], true) . "</pre>";
}

// Проверка API
echo "<h2>2. Проверка API handler</h2>";
$apiFile = __DIR__ . '/api/handler.php';
echo "Файл API: " . (file_exists($apiFile) ? "✅ Существует" : "❌ Не найден") . "<br>";

// Тест вызова API
echo "<h2>3. Тест вызова API</h2>";
$apiUrl = 'http://dreamcar.ai-platform.space/volvo/dashboard/utm-dashboard/api/handler.php?action=get_data';
echo "URL: {$apiUrl}<br>";

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP код: {$httpCode}<br>";
echo "Ответ:<br>";
echo "<pre style='background:#000;color:#0f0;padding:10px;'>";
echo htmlspecialchars($response);
echo "</pre>";

// Проверка config
echo "<h2>4. Проверка конфигурации</h2>";
$configFile = __DIR__ . '/config/app_config.php';
echo "Config файл: " . (file_exists($configFile) ? "✅ Существует" : "❌ Не найден") . "<br>";

if (file_exists($configFile)) {
    require_once $configFile;
    echo "UTM_CLEAN_FILE: " . (defined('UTM_CLEAN_FILE') ? UTM_CLEAN_FILE : "Не определено") . "<br>";
}
?>
