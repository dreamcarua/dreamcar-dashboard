<?php
/**
 * Виконання SQL міграції - додавання поля project
 * Файл: run_migration.php
 * УВАГА: Виконати ОДИН РАЗ!
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔧 Виконання SQL міграції</h1>";
echo "<hr>";

try {
    $db = Database::getInstance();

    // Перевірити чи поле вже існує
    $sql = "SHOW COLUMNS FROM ads_data LIKE 'project'";
    $result = $db->fetchAll($sql);

    if (!empty($result)) {
        echo "<h3 style='color: orange;'>⚠️ Поле 'project' вже існує!</h3>";
        echo "<p>Міграція вже виконана раніше.</p>";
        exit;
    }

    echo "<h3>Крок 1: Додавання поля 'project'...</h3>";

    // Крок 1: Додати поле project
    $sql1 = "ALTER TABLE ads_data
             ADD COLUMN project VARCHAR(100) DEFAULT NULL AFTER optimization_goal";
    $db->execute($sql1);
    echo "<p style='color: green;'>✅ Поле 'project' додано успішно!</p>";

    // Крок 2: Додати індекс
    echo "<h3>Крок 2: Додавання індексу...</h3>";
    $sql2 = "ALTER TABLE ads_data ADD INDEX idx_project (project)";
    $db->execute($sql2);
    echo "<p style='color: green;'>✅ Індекс 'idx_project' створено!</p>";

    // Крок 3: Оновити існуючі записи
    echo "<h3>Крок 3: Оновлення існуючих записів...</h3>";
    $sql3 = "UPDATE ads_data
             SET project = 'VOLVO'
             WHERE publisher_platform = 'manual' AND project IS NULL";
    $db->execute($sql3);
    echo "<p style='color: green;'>✅ Існуючі записи оновлено!</p>";

    // Крок 4: Перевірка результату
    echo "<h3>Крок 4: Перевірка результату...</h3>";
    $sql4 = "SELECT
                COUNT(*) as total_manual,
                COUNT(CASE WHEN project IS NOT NULL THEN 1 END) as with_project,
                COUNT(CASE WHEN project IS NULL THEN 1 END) as without_project
             FROM ads_data
             WHERE publisher_platform = 'manual'";
    $stats = $db->fetchOne($sql4);

    echo "<table border='1' cellpadding='10' style='margin-top: 20px;'>";
    echo "<tr><th>Всього ручних витрат</th><td>{$stats['total_manual']}</td></tr>";
    echo "<tr><th>З проектом</th><td style='color: green;'>{$stats['with_project']}</td></tr>";
    echo "<tr><th>Без проекту</th><td>{$stats['without_project']}</td></tr>";
    echo "</table>";

    echo "<hr>";
    echo "<h2 style='color: green;'>🎉 МІГРАЦІЯ ЗАВЕРШЕНА УСПІШНО!</h2>";
    echo "<p><a href='manual_costs.php' style='padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 5px;'>→ Перейти до ручних витрат</a></p>";
    echo "<p><a href='index.php' style='padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 5px;'>→ Перейти до дашборду</a></p>";

    echo "<hr>";
    echo "<h3>⚠️ ВАЖЛИВО!</h3>";
    echo "<p style='color: red; font-weight: bold;'>Видали цей файл після виконання міграції для безпеки!</p>";
    echo "<p>Команда: <code>rm run_migration.php</code></p>";

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА МІГРАЦІЇ:</h3>";
    echo "<pre style='background: #fee; padding: 20px; border-left: 4px solid red;'>";
    echo htmlspecialchars($e->getMessage());
    echo "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";

    echo "<hr>";
    echo "<h3>Що робити?</h3>";
    echo "<ol>";
    echo "<li>Перевір підключення до бази даних</li>";
    echo "<li>Переконайся що у тебе є права на ALTER TABLE</li>";
    echo "<li>Спробуй виконати SQL вручну через phpMyAdmin</li>";
    echo "</ol>";
}
?>
