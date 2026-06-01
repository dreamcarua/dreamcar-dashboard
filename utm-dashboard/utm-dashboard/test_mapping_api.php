<?php
/**
 * Тестування API utm_mapping
 */

require_once 'config/app_config.php';
require_once 'core/Auth.php';
require_once 'core/Session.php';

Auth::checkAccess();

echo "<h1>🧪 Тест API utm_mapping</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } pre { background: #f5f5f5; padding: 15px; border-left: 4px solid #3b82f6; }</style>";

echo "<h2>1. Тест get_mappings.php</h2>";
$url1 = 'https://dreamcar.ai-platform.space/volvo/dashboard/utm-dashboard/api/utm_mapping/get_mappings.php';
echo "<p><a href='$url1' target='_blank'>Відкрити →</a></p>";

echo "<h2>2. Тест get_unique_values.php</h2>";
$url2 = 'https://dreamcar.ai-platform.space/volvo/dashboard/utm-dashboard/api/utm_mapping/get_unique_values.php?field_type=utm_term';
echo "<p><a href='$url2' target='_blank'>Відкрити →</a></p>";

echo "<h2>3. Роль та сесія:</h2>";
echo "<pre>";
echo "Username: " . (Session::get('user')['username'] ?? 'NOT SET') . "\n";
echo "Role: " . Session::getRole() . "\n";
echo "Is logged in: " . (Session::isLoggedIn() ? 'YES' : 'NO') . "\n";
echo "Is guest: " . (Session::isGuest() ? 'YES' : 'NO') . "\n";
echo "</pre>";

echo "<h2>4. Тест прямого виклику моделі:</h2>";
try {
    require_once 'core/models/UtmCrmAdsMapping.php';

    $mappings = UtmCrmAdsMapping::getAll();

    echo "<p style='color: green;'>✅ Модель працює! Знайдено mappings: " . count($mappings) . "</p>";
    echo "<pre>" . print_r($mappings, true) . "</pre>";

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Помилка моделі: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
