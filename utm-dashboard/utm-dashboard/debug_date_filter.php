<?php
/**
 * Діагностика фільтру дат
 * Перевірка чому стара сделка показується в "Сьогодні"
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🐛 Діагностика фільтру дат</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background: #3b82f6; color: white; }
    .error { background: #fee; color: red; font-weight: bold; }
    .success { background: #efe; color: green; }
    pre { background: #f5f5f5; padding: 15px; overflow-x: auto; border-left: 4px solid #ef4444; }
</style>";

try {
    $db = Database::getInstance();

    $today = date('Y-m-d');
    echo "<h2>📅 Сьогоднішня дата: <strong>{$today}</strong></h2>";

    // ==========================================
    // КРОК 1: Перевірити сделку 9489821
    // ==========================================

    echo "<h2>🔍 Крок 1: Перевірка сделки 9489821</h2>";

    $sql = "SELECT deal_id, contact_id, email, phone, full_name,
                   utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                   model, created_at, deal_updated_at,
                   DATE(created_at) as created_date,
                   DATE(deal_updated_at) as updated_date
            FROM crm_deals
            WHERE deal_id = 9489821";

    $deal = $db->fetchOne($sql);

    if ($deal) {
        echo "<p class='success'>✅ Сделка знайдена в БД</p>";

        echo "<table>";
        echo "<tr><th>Поле</th><th>Значення</th></tr>";
        foreach ($deal as $key => $value) {
            $highlight = ($key === 'created_at' || $key === 'deal_updated_at' || $key === 'created_date' || $key === 'updated_date') ? "class='error'" : "";
            echo "<tr {$highlight}>";
            echo "<td><strong>" . htmlspecialchars($key) . "</strong></td>";
            echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        // Перевірити чи дата = сьогодні
        if ($deal['created_date'] === $today) {
            echo "<p class='error'>❌ created_date = {$today} (СЬОГОДНІ!) - ЦЕ ПОМИЛКА!</p>";
        } else {
            echo "<p class='success'>✅ created_date = {$deal['created_date']} (НЕ сьогодні)</p>";
        }

        if ($deal['updated_date'] === $today) {
            echo "<p class='error'>❌ deal_updated_at = {$today} (СЬОГОДНІ!) - МОЖЛИВА ПРИЧИНА!</p>";
            echo "<p><strong>ВИСНОВОК:</strong> Фільтр може використовувати <code>deal_updated_at</code> замість <code>created_at</code>!</p>";
        } else {
            echo "<p>deal_updated_at = {$deal['updated_date']} (НЕ сьогодні)</p>";
        }

    } else {
        echo "<p class='error'>❌ Сделка НЕ знайдена в БД</p>";
    }

    // ==========================================
    // КРОК 2: Перевірити API фільтр
    // ==========================================

    echo "<h2>🔍 Крок 2: Перевірка API фільтру 'today'</h2>";

    // Імітувати запит як з фронтенду
    $_GET['date_range'] = 'today';
    $_GET['model'] = 'Q7';

    echo "<p>Імітуємо запит: <code>?date_range=today&model=Q7</code></p>";

    // Перевірити яку дату використовує API
    $dateFilter = $_GET['date_range'] ?? 'all';

    $filters = [];
    if ($dateFilter === 'today') {
        $filters['date_from'] = $today;
        $filters['date_to'] = $today;
    }

    echo "<table>";
    echo "<tr><th>Параметр</th><th>Значення</th></tr>";
    echo "<tr><td>date_range</td><td>{$dateFilter}</td></tr>";
    echo "<tr class='error'><td>date_from</td><td>" . ($filters['date_from'] ?? 'NULL') . "</td></tr>";
    echo "<tr class='error'><td>date_to</td><td>" . ($filters['date_to'] ?? 'NULL') . "</td></tr>";
    echo "</table>";

    // ==========================================
    // КРОК 3: Перевірити який SQL запит використовується
    // ==========================================

    echo "<h2>🔍 Крок 3: SQL запит який використовується в api/test.php</h2>";

    require_once 'core/models/CrmDeal.php';

    // Подивитись на метод getStatsByUTMField
    $sql = "SELECT utm_term, COUNT(*) as total
            FROM crm_deals
            WHERE DATE(created_at) >= :date_from AND DATE(created_at) <= :date_to
            GROUP BY utm_term
            ORDER BY total DESC
            LIMIT 20";

    echo "<pre>" . htmlspecialchars($sql) . "</pre>";

    $results = $db->fetchAll($sql, [
        'date_from' => $today,
        'date_to' => $today
    ]);

    echo "<h3>Результати запиту за сьогодні:</h3>";
    echo "<table>";
    echo "<tr><th>#</th><th>UTM Term</th><th>Кількість</th></tr>";

    $found9489821 = false;
    foreach ($results as $i => $row) {
        echo "<tr>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td>" . htmlspecialchars($row['utm_term'] ?? '(пусто)') . "</td>";
        echo "<td>" . htmlspecialchars($row['total']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 4: Перевірити чи є deal 9489821 в результатах TODAY
    // ==========================================

    echo "<h2>🔍 Крок 4: Чи є deal 9489821 в результатах фільтру 'today'?</h2>";

    $sql = "SELECT COUNT(*) as count
            FROM crm_deals
            WHERE deal_id = 9489821
              AND DATE(created_at) = :today";

    $result = $db->fetchOne($sql, ['today' => $today]);

    if ($result['count'] > 0) {
        echo "<p class='error'>❌ ТАК! Deal 9489821 ПОПАДАЄ в фільтр today по created_at</p>";
        echo "<p><strong>ЦЕ АНОМАЛІЯ!</strong> created_at = 2025-08-15, але DATE(created_at) = {$today}???</p>";
    } else {
        echo "<p class='success'>✅ НІ. Deal 9489821 НЕ попадає в фільтр today по created_at</p>";
    }

    // Перевірити по deal_updated_at
    $sql = "SELECT COUNT(*) as count
            FROM crm_deals
            WHERE deal_id = 9489821
              AND DATE(deal_updated_at) = :today";

    $result = $db->fetchOne($sql, ['today' => $today]);

    if ($result['count'] > 0) {
        echo "<p class='error'>❌ ТАК! Deal 9489821 ПОПАДАЄ в фільтр today по deal_updated_at</p>";
        echo "<p><strong>ПРИЧИНА ЗНАЙДЕНА!</strong> API використовує <code>deal_updated_at</code> замість <code>created_at</code>!</p>";
    } else {
        echo "<p class='success'>✅ НІ. Deal 9489821 НЕ попадає в фільтр today по deal_updated_at</p>";
    }

    // ==========================================
    // КРОК 5: Перевірити код в api/test.php
    // ==========================================

    echo "<h2>🔍 Крок 5: Читаємо код api/test.php</h2>";

    $apiCode = file_get_contents(__DIR__ . '/api/test.php');

    // Шукаємо який фільтр використовується
    if (strpos($apiCode, 'deal_updated_at') !== false) {
        echo "<p class='error'>❌ В api/test.php використовується <code>deal_updated_at</code>!</p>";
        echo "<p><strong>Це ПОМИЛКА!</strong> Треба використовувати <code>created_at</code> для фільтру дат!</p>";
    } elseif (strpos($apiCode, 'created_at') !== false) {
        echo "<p class='success'>✅ В api/test.php використовується <code>created_at</code></p>";
    } else {
        echo "<p class='error'>❌ Не можу знайти який фільтр використовується!</p>";
    }

    // Показати фрагмент коду з фільтром
    echo "<h3>Фрагмент коду з api/test.php:</h3>";
    preg_match('/date_from.*?date_to.*?\n.*?\n.*?\n/s', $apiCode, $matches);
    if (!empty($matches[0])) {
        echo "<pre>" . htmlspecialchars($matches[0]) . "</pre>";
    }

    // ==========================================
    // ВИСНОВОК
    // ==========================================

    echo "<h2>💡 ВИСНОВОК</h2>";
    echo "<div style='background: #fee; padding: 20px; border-left: 5px solid red;'>";
    echo "<p><strong>Проблема:</strong> Стара сделка (15.08.2025) показується в фільтрі 'Сьогодні'</p>";
    echo "<p><strong>Можливі причини:</strong></p>";
    echo "<ol>";
    echo "<li>API використовує <code>deal_updated_at</code> замість <code>created_at</code></li>";
    echo "<li>Сделка була оновлена сьогодні (змінився статус, додано поле)</li>";
    echo "<li>Помилка в SQL запиті фільтрації</li>";
    echo "</ol>";
    echo "</div>";

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
