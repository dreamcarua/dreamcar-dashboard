<?php
/**
 * Перевірка наявності поля project в таблиці ads_data
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

try {
    $db = Database::getInstance();

    // Перевірити структуру таблиці
    $sql = "DESCRIBE ads_data";
    $columns = $db->fetchAll($sql);

    echo "<h2>Структура таблиці ads_data:</h2>";
    echo "<pre>";

    $hasProject = false;
    foreach ($columns as $column) {
        echo $column['Field'] . " - " . $column['Type'];
        if ($column['Field'] === 'project') {
            echo " ✅ ЗНАЙДЕНО!";
            $hasProject = true;
        }
        echo "\n";
    }
    echo "</pre>";

    if (!$hasProject) {
        echo "<h3 style='color: red;'>❌ ПОЛЕ 'project' НЕ ЗНАЙДЕНО!</h3>";
        echo "<p>Виконай SQL міграцію:</p>";
        echo "<pre>";
        echo "ALTER TABLE ads_data\n";
        echo "ADD COLUMN project VARCHAR(100) DEFAULT NULL AFTER optimization_goal,\n";
        echo "ADD INDEX idx_project (project);\n\n";
        echo "UPDATE ads_data SET project = 'VOLVO'\n";
        echo "WHERE publisher_platform = 'manual' AND project IS NULL;";
        echo "</pre>";
    } else {
        echo "<h3 style='color: green;'>✅ Поле 'project' існує!</h3>";

        // Показати кілька записів
        $sql = "SELECT id, date_start, utm_source, project FROM ads_data WHERE publisher_platform = 'manual' LIMIT 5";
        $rows = $db->fetchAll($sql);

        echo "<h3>Приклад даних:</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Дата</th><th>UTM Source</th><th>Project</th></tr>";
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['date_start']) . "</td>";
            echo "<td>" . htmlspecialchars($row['utm_source'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['project'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>Помилка:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
