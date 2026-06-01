<?php
// === 004_add_deal_project.php ===
// dashboard/utm-dashboard/migrations/004_add_deal_project.php
// НАЗНАЧЕНИЕ: Добавить поле deal_project + wc_order_id, пересчитать по order_id

header('Content-Type: text/html; charset=utf-8');
ini_set('max_execution_time', 300);

require_once __DIR__ . '/../config/database.php';

echo "<h1>Миграция 004: deal_project</h1>";

// ========================================
// Определить проект по wc_order_id
// ========================================
function resolveProject($wcOrderId, $model) {
    if ($wcOrderId !== null && $wcOrderId > 0) {
        if ($wcOrderId >= 1500 && $wcOrderId <= 16395) return 'BMW';
        if ($wcOrderId >= 16396)                       return 'Mercedes';
    }
    // Фолбек по названию модели
    $m = strtoupper(trim($model ?? ''));
    if (strpos($m, 'BMW') === 0)      return 'BMW';
    if (strpos($m, 'MERCEDES') === 0) return 'Mercedes';
    if (strpos($m, 'Q7') === 0)       return 'Q7';
    if (strpos($m, 'VOLVO') === 0)    return 'VOLVO';
    // Первое слово
    $parts = preg_split('/\s+/', $m, 2);
    return $parts[0] ?: 'UNKNOWN';
}

// ========================================
// Извлечь wc_order_id из строки
// ========================================
function extractOrderId($dealName, $utmContent) {
    // Из deal_name: "Mercedes GLE AMG - #27102"
    if (preg_match('/#(\d+)$/', $dealName ?? '', $m)) {
        return (int)$m[1];
    }
    // Из utm_content: "wc_order_id:27102"
    if (preg_match('/^wc_order_id:(\d+)$/', $utmContent ?? '', $m)) {
        return (int)$m[1];
    }
    return null;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // === ШАГ 1: Проверка / добавление колонок ===
    echo "<h2>1. Колонки</h2>";
    $cols = $pdo->query("SHOW COLUMNS FROM crm_deals")->fetchAll();
    $colNames = array_column($cols, 'Field');

    if (!in_array('deal_project', $colNames)) {
        $pdo->exec("ALTER TABLE crm_deals ADD COLUMN deal_project VARCHAR(50) DEFAULT NULL AFTER model");
        $pdo->exec("ALTER TABLE crm_deals ADD INDEX idx_deal_project (deal_project)");
        echo "<p style='color:green;'>+ deal_project добавлена.</p>";
    } else {
        echo "<p style='color:orange;'>deal_project уже есть.</p>";
    }

    if (!in_array('wc_order_id', $colNames)) {
        $pdo->exec("ALTER TABLE crm_deals ADD COLUMN wc_order_id INT UNSIGNED DEFAULT NULL AFTER deal_project");
        $pdo->exec("ALTER TABLE crm_deals ADD INDEX idx_wc_order_id (wc_order_id)");
        echo "<p style='color:green;'>+ wc_order_id добавлена.</p>";
    } else {
        echo "<p style='color:orange;'>wc_order_id уже есть.</p>";
    }

    // === ШАГ 2: Загружаем все сделки и пересчитываем в PHP ===
    echo "<h2>2. Пересчет всех записей</h2>";

    $rows = $pdo->query("SELECT id, deal_name, utm_content, model FROM crm_deals")->fetchAll();
    $total = count($rows);
    echo "<p>Всего записей: {$total}</p>";

    $stmt = $pdo->prepare("UPDATE crm_deals SET wc_order_id = :wc_order_id, deal_project = :deal_project WHERE id = :id");

    $counts = ['BMW' => 0, 'Mercedes' => 0, 'Q7' => 0, 'VOLVO' => 0, 'other' => 0];
    $updated = 0;

    foreach ($rows as $row) {
        $orderId  = extractOrderId($row['deal_name'], $row['utm_content']);
        $project  = resolveProject($orderId, $row['model']);

        $stmt->execute([
            ':wc_order_id'  => $orderId,
            ':deal_project' => $project,
            ':id'           => $row['id'],
        ]);
        $updated++;
        $key = isset($counts[$project]) ? $project : 'other';
        $counts[$key]++;
    }

    echo "<p style='color:green;'>Обновлено: {$updated} записей.</p>";

    // === ШАГ 3: Статистика ===
    echo "<h2>3. Статистика по проектам</h2>";
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-family:monospace;'>";
    echo "<tr><th>deal_project</th><th>Кол-во сделок</th></tr>";
    $stats = $pdo->query("SELECT deal_project, COUNT(*) as cnt FROM crm_deals GROUP BY deal_project ORDER BY cnt DESC")->fetchAll();
    foreach ($stats as $row) {
        echo "<tr><td>" . htmlspecialchars($row['deal_project'] ?? 'NULL') . "</td><td>" . $row['cnt'] . "</td></tr>";
    }
    echo "</table>";

    // === ШАГ 4: Проверка — Mercedes по имени, но deal_project=BMW (order_id < 16396) ===
    echo "<h2>4. Проверка: Mercedes-named = BMW-project (order_id 1500-16395)</h2>";
    $check = $pdo->query("
        SELECT deal_id, deal_name, wc_order_id, deal_project
        FROM crm_deals
        WHERE deal_name LIKE 'Mercedes%'
          AND deal_project = 'BMW'
        ORDER BY wc_order_id ASC
        LIMIT 15
    ")->fetchAll();

    if ($check) {
        echo "<p style='color:green;'>Найдено " . count($check) . " записей (верно исправлено):</p>";
        echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-family:monospace;font-size:12px;'>";
        echo "<tr><th>deal_id</th><th>deal_name</th><th>wc_order_id</th><th>deal_project</th></tr>";
        foreach ($check as $r) {
            echo "<tr><td>{$r['deal_id']}</td><td>" . htmlspecialchars($r['deal_name']) . "</td><td>{$r['wc_order_id']}</td><td><b>{$r['deal_project']}</b></td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Таких записей нет.</p>";
    }

    // === ШАГ 5: Проверка — диапазоны ===
    echo "<h2>5. Проверка диапазонов order_id</h2>";
    $range = $pdo->query("
        SELECT deal_project, MIN(wc_order_id) as min_id, MAX(wc_order_id) as max_id, COUNT(*) as cnt
        FROM crm_deals
        WHERE wc_order_id IS NOT NULL
        GROUP BY deal_project
        ORDER BY min_id ASC
    ")->fetchAll();
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-family:monospace;'>";
    echo "<tr><th>deal_project</th><th>min order_id</th><th>max order_id</th><th>cnt</th></tr>";
    foreach ($range as $r) {
        echo "<tr><td>{$r['deal_project']}</td><td>{$r['min_id']}</td><td>{$r['max_id']}</td><td>{$r['cnt']}</td></tr>";
    }
    echo "</table>";

    echo "<h2 style='color:green;'>Миграция 004 выполнена успешно!</h2>";

} catch (Exception $e) {
    echo "<h2 style='color:red;'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</h2>";
}
