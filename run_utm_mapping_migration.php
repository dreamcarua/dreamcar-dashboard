<?php
/**
 * Виконати міграцію: створити таблицю utm_crm_ads_mapping
 * ВИДАЛИТИ ЦЕЙ ФАЙЛ після виконання!
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔄 Міграція: Таблиця utm_crm_ads_mapping</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } .success { color: green; } .error { color: red; }</style>";

try {
    $db = Database::getInstance();

    // Читати SQL файл
    $sqlFile = __DIR__ . '/sql/utm_crm_ads_mapping.sql';

    if (!file_exists($sqlFile)) {
        throw new Exception("SQL файл не знайдено: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);

    echo "<h2>Виконую SQL...</h2>";
    echo "<pre>" . htmlspecialchars($sql) . "</pre>";

    // Видалити коментарі
    $lines = explode("\n", $sql);
    $cleanedLines = [];
    foreach ($lines as $line) {
        $line = trim($line);
        // Пропустити порожні рядки та коментарі
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        $cleanedLines[] = $line;
    }
    $cleanedSql = implode("\n", $cleanedLines);

    // Розділити на окремі запити
    $queries = array_filter(
        array_map('trim', explode(';', $cleanedSql)),
        function($query) {
            return !empty($query);
        }
    );

    $executed = 0;
    foreach ($queries as $query) {
        if (trim($query)) {
            echo "<p>Виконую: <code>" . substr($query, 0, 100) . "...</code></p>";
            $db->execute($query);
            $executed++;
        }
    }

    echo "<h2 class='success'>✅ Міграція виконана успішно!</h2>";
    echo "<p>Виконано запитів: <strong>$executed</strong></p>";

    // Перевірка
    $check = $db->fetchOne("SHOW TABLES LIKE 'utm_crm_ads_mapping'");
    if ($check) {
        echo "<p class='success'>✅ Таблиця utm_crm_ads_mapping створена</p>";

        // Порахувати записи
        $count = $db->fetchOne("SELECT COUNT(*) as count FROM utm_crm_ads_mapping");
        echo "<p>Записів в таблиці: <strong>{$count['count']}</strong></p>";
    }

    echo "<hr>";
    echo "<p style='color: red; font-weight: bold;'>⚠️ ВИДАЛИ ЦЕЙ ФАЙЛ: run_utm_mapping_migration.php</p>";
    echo "<p><a href='utm_mapping.php' style='padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 5px;'>→ Перейти до управління відповідностями</a></p>";

} catch (Exception $e) {
    echo "<h2 class='error'>❌ Помилка:</h2>";
    echo "<pre class='error'>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
