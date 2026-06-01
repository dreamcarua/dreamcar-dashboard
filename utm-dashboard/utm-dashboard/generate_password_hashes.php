<?php
/**
 * generate_password_hashes.php
 * Скрипт для генерації реальних хешів паролів
 * Запустити ОДИН РАЗ, потім видалити!
 */

$users = [
    ['username' => 'admin_vadym', 'password' => 'admin_vadym123$', 'role' => 'admin', 'utm_term' => null],
    ['username' => 'vadym', 'password' => 'vadym', 'role' => 'targetolog', 'utm_term' => 'vadym'],
    ['username' => 'vira', 'password' => 'vira', 'role' => 'targetolog', 'utm_term' => 'vira'],
    ['username' => 'artem', 'password' => 'artem', 'role' => 'targetolog', 'utm_term' => 'artem'],
    ['username' => 'oborotfb', 'password' => 'oborotfb', 'role' => 'targetolog', 'utm_term' => 'oborotfb']
];

$result = [
    'version' => '1.0',
    'last_updated' => date('Y-m-d\TH:i:s\Z'),
    'users' => []
];

echo "<h1>🔐 Генерація хешів паролів</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } pre { background: #f5f5f5; padding: 15px; border-left: 4px solid #10b981; }</style>";

echo "<h2>Створені користувачі:</h2>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Username</th><th>Password (plain)</th><th>Role</th><th>UTM Term</th></tr>";

foreach ($users as $user) {
    $passwordHash = password_hash($user['password'], PASSWORD_DEFAULT);

    $result['users'][] = [
        'username' => $user['username'],
        'password_hash' => $passwordHash,
        'role' => $user['role'],
        'utm_term' => $user['utm_term'],
        'created_at' => date('Y-m-d\TH:i:s\Z'),
        'last_login' => null,
        'is_active' => true,
        'settings' => [
            'theme' => 'dark',
            'language' => 'ru'
        ]
    ];

    echo "<tr>";
    echo "<td><strong>{$user['username']}</strong></td>";
    echo "<td>{$user['password']}</td>";
    echo "<td>{$user['role']}</td>";
    echo "<td>" . ($user['utm_term'] ?: '-') . "</td>";
    echo "</tr>";
}

echo "</table>";

// Зберегти в файл
$jsonContent = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents(__DIR__ . '/config/users.json', $jsonContent);

echo "<h2 style='color: green;'>✅ Файл config/users.json оновлено!</h2>";

echo "<h3>JSON вміст:</h3>";
echo "<pre>" . htmlspecialchars($jsonContent) . "</pre>";

echo "<p style='color: red; font-weight: bold; margin-top: 30px;'>⚠️ ВИДАЛИ ЦЕЙ ФАЙЛ після використання: generate_password_hashes.php</p>";

// Самознищення через 1 хвилину після виконання
echo "<script>
setTimeout(function() {
    if (confirm('Видалити цей файл generate_password_hashes.php зараз?')) {
        window.location.href = 'delete_script.php?file=generate_password_hashes.php';
    }
}, 5000);
</script>";
?>
