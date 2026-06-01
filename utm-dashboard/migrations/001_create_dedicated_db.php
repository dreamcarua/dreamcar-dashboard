<?php
// === 001_create_dedicated_db.php ===
// НАЗНАЧЕНИЕ: Создание базы данных на выделенном MySQL сервисе
// ИСПОЛЬЗОВАНИЕ: Выполнить через curl ОДИН РАЗ
// УДАЛИТЬ после успешного переезда!

header('Content-Type: text/html; charset=utf-8');
ini_set('max_execution_time', 60);

echo "<h1>Создание БД на выделенном MySQL (fincheck.mysql.network)</h1>";

$authToken = 'nfzatc02d7130ebte615wapf3j7j28h53db7pjmelnj0e7n52utvphr6pyu9b147';

$post = [
    'mysql_id'    => 987,
    'name'        => 'dreamcar_utm',
    'charset_id'  => 45,  // utf8mb4_general_ci
    'create_user' => 1,
];

echo "<p>Отправляю запрос к API adm.tools...</p>";
echo "<p>Параметры: mysql_id=987, name=dreamcar_utm, charset_id=45, create_user=1</p>";

$ch = curl_init('https://adm.tools/action/mysql/database/create/save/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $authToken],
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($post),
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<p>HTTP код: <strong>{$httpCode}</strong></p>";

if ($curlError) {
    echo "<p style='color:red;'>CURL ошибка: " . htmlspecialchars($curlError) . "</p>";
    exit;
}

$result = json_decode($response, true);

echo "<h2>Ответ API:</h2>";
echo "<pre>" . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

if (isset($result['result']) && $result['result'] === true) {
    echo "<h2 style='color:green;'>БД создана успешно!</h2>";
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
    echo "<tr><th>Параметр</th><th>Значение</th></tr>";
    echo "<tr><td>DB ID</td><td><strong>" . ($result['response']['db_id'] ?? 'N/A') . "</strong></td></tr>";
    echo "<tr><td>Имя базы</td><td><strong>" . ($result['response']['name'] ?? 'N/A') . "</strong></td></tr>";
    echo "<tr><td>Пользователь</td><td><strong>" . ($result['response']['user'] ?? 'N/A') . "</strong></td></tr>";
    echo "<tr><td>Пароль</td><td><strong style='color:red;'>" . ($result['response']['password'] ?? 'N/A') . "</strong></td></tr>";
    echo "<tr><td>Хост</td><td><strong>fincheck.mysql.network</strong></td></tr>";
    echo "<tr><td>Порт</td><td><strong>10145</strong></td></tr>";
    echo "</table>";

    echo "<h3 style='color:red;'>СОХРАНИ ЭТИ ДАННЫЕ! Пароль показывается только при создании!</h3>";
} else {
    echo "<h2 style='color:red;'>Ошибка создания БД</h2>";
    if (isset($result['messages']['error'])) {
        foreach ($result['messages']['error'] as $err) {
            echo "<p style='color:red;'>" . htmlspecialchars($err) . "</p>";
        }
    }
}
