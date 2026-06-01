<?php
/**
 * Очистити utm для deal 11521318
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🧹 Очищення Deal 11521318</h1>";

try {
    $db = Database::getInstance();

    $sql = "UPDATE crm_deals
            SET utm_term = '',
                utm_campaign = '',
                utm_content = ''
            WHERE deal_id = 11521318";

    $db->execute($sql);

    echo "<h2 style='color: green;'>✅ ГОТОВО!</h2>";
    echo "<p>Deal 11521318 очищено:</p>";
    echo "<ul>";
    echo "<li>utm_term: <code>120231525461530624...</code> → <strong>(пусто)</strong></li>";
    echo "<li>utm_campaign: <code>120231525461540624...</code> → <strong>(пусто)</strong></li>";
    echo "<li>utm_content: <code>120231525461580624...</code> → <strong>(пусто)</strong></li>";
    echo "</ul>";

    echo "<p><a href='index.php' style='padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 5px;'>→ Перейти до дашборду</a></p>";

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
