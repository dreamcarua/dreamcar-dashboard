<?php
/**
 * Діагностика фільтру проектів
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 Діагностика фільтру проектів</h1>";
echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background: #3b82f6; color: white; }
    .error { background: #fee; font-weight: bold; }
    .success { background: #efe; }
    pre { background: #f5f5f5; padding: 15px; }
</style>";

try {
    $db = Database::getInstance();
    $today = date('Y-m-d');

    // ==========================================
    // КРОК 1: Активний проект
    // ==========================================

    echo "<h2>⚙️ Крок 1: Який активний проект?</h2>";

    $settingsFile = __DIR__ . '/config/dashboard_settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        $activeProject = $settings['active_project'] ?? 'VOLVO';
        echo "<p class='success'>✅ Активний проект з конфігу: <strong>{$activeProject}</strong></p>";
    } else {
        $activeProject = 'VOLVO';
        echo "<p class='error'>❌ Файл конфігу не знайдено, дефолт: <strong>VOLVO</strong></p>";
    }

    // ==========================================
    // КРОК 2: Які проекти є в БД
    // ==========================================

    echo "<h2>📊 Крок 2: Які проекти (model) є в БД?</h2>";

    $sql = "SELECT DISTINCT model, COUNT(*) as count
            FROM crm_deals
            WHERE model IS NOT NULL AND model != ''
            GROUP BY model
            ORDER BY count DESC";

    $projects = $db->fetchAll($sql);

    echo "<table>";
    echo "<tr><th>Model (Проект)</th><th>Кількість сделок</th><th>Статус</th></tr>";

    foreach ($projects as $p) {
        $isActive = (strtoupper($p['model']) === strtoupper($activeProject));
        $rowClass = $isActive ? "success" : "";
        $status = $isActive ? "✅ АКТИВНИЙ" : "";

        echo "<tr class='{$rowClass}'>";
        echo "<td><strong>" . htmlspecialchars($p['model']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($p['count']) . "</td>";
        echo "<td>{$status}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 3: Скільки сделок за СЬОГОДНІ по проектах
    // ==========================================

    echo "<h2>📅 Крок 3: Сделки за СЬОГОДНІ по проектах</h2>";

    $sql = "SELECT model, COUNT(*) as count
            FROM crm_deals
            WHERE DATE(created_at) = :today
            GROUP BY model
            ORDER BY count DESC";

    $todayByProject = $db->fetchAll($sql, ['today' => $today]);

    echo "<table>";
    echo "<tr><th>Model (Проект)</th><th>Сделок за сьогодні</th></tr>";

    foreach ($todayByProject as $p) {
        $isActive = (strtoupper($p['model']) === strtoupper($activeProject));
        $rowClass = $isActive ? "success" : "error";

        echo "<tr class='{$rowClass}'>";
        echo "<td><strong>" . htmlspecialchars($p['model'] ?? '(пусто)') . "</strong></td>";
        echo "<td>" . htmlspecialchars($p['count']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 4: Що передається в API?
    // ==========================================

    echo "<h2>🔍 Крок 4: Який фільтр передається в API з фронтенду?</h2>";

    echo "<p>Відкрий <strong>Developer Tools → Network</strong> в браузері і подивись запит до <code>api/test.php</code></p>";
    echo "<p>Шукай параметр: <code>model=???</code></p>";

    echo "<h4>Очікується:</h4>";
    echo "<pre>GET api/test.php?date_range=today&model={$activeProject}&...</pre>";

    // ==========================================
    // КРОК 5: Тестовий запит
    // ==========================================

    echo "<h2>🧪 Крок 5: Тестовий запит з фільтром проекту</h2>";

    echo "<h3>Запит 1: Всі проекти (model не вказано)</h3>";
    $sql = "SELECT COUNT(*) as count FROM crm_deals WHERE DATE(created_at) = :today";
    $result1 = $db->fetchOne($sql, ['today' => $today]);
    echo "<p>Результат: <strong>{$result1['count']}</strong> сделок</p>";

    echo "<h3>Запит 2: Тільки Q7</h3>";
    $sql = "SELECT COUNT(*) as count FROM crm_deals WHERE DATE(created_at) = :today AND UPPER(model) = 'Q7'";
    $result2 = $db->fetchOne($sql, ['today' => $today]);
    echo "<p>Результат: <strong>{$result2['count']}</strong> сделок</p>";

    echo "<h3>Запит 3: Тільки AUDI2025</h3>";
    $sql = "SELECT COUNT(*) as count FROM crm_deals WHERE DATE(created_at) = :today AND UPPER(model) = 'AUDI2025'";
    $result3 = $db->fetchOne($sql, ['today' => $today]);
    echo "<p>Результат: <strong>{$result3['count']}</strong> сделок</p>";

    echo "<h3>Запит 4: Тільки OLD</h3>";
    $sql = "SELECT COUNT(*) as count FROM crm_deals WHERE DATE(created_at) = :today AND UPPER(model) = 'OLD'";
    $result4 = $db->fetchOne($sql, ['today' => $today]);
    echo "<p>Результат: <strong>{$result4['count']}</strong> сделок</p>";

    // ==========================================
    // ВИСНОВОК
    // ==========================================

    echo "<h2>💡 ДІАГНОЗ</h2>";

    echo "<div style='background: #fee; padding: 20px; border-left: 5px solid red;'>";
    echo "<h3>❌ ПРОБЛЕМА:</h3>";

    echo "<p><strong>Активний проект:</strong> {$activeProject}</p>";
    echo "<p><strong>Сделок Q7 за сьогодні:</strong> {$result2['count']}</p>";
    echo "<p><strong>Сделок AUDI2025 за сьогодні:</strong> {$result3['count']}</p>";
    echo "<p><strong>Сделок OLD за сьогодні:</strong> {$result4['count']}</p>";

    if ($activeProject === 'AUDI2025' && $result3['count'] === 0 && $result2['count'] > 0) {
        echo "<hr>";
        echo "<p class='error'><strong>🎯 ПРИЧИНА ЗНАЙДЕНА!</strong></p>";
        echo "<p>Активний проект = <strong>AUDI2025</strong>, але в БД немає сделок з model='AUDI2025'!</p>";
        echo "<p>Всі сделки мають model='<strong>Q7</strong>' (це назва моделі авто, а не проекту!)</p>";

        echo "<h4>Рішення:</h4>";
        echo "<ol>";
        echo "<li><strong>Варіант 1:</strong> Змінити активний проект на 'Q7' в налаштуваннях</li>";
        echo "<li><strong>Варіант 2:</strong> Оновити webhook_crm.php щоб name_deal='Q7' зберігався як model='AUDI2025'</li>";
        echo "<li><strong>Варіант 3:</strong> Маппінг: Q7, Q8, A6 → AUDI2025</li>";
        echo "</ol>";
    }

    echo "</div>";

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
