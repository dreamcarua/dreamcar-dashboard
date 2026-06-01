<?php
// === 006_add_tariff_pay_provider.php ===
// НАЗНАЧЕНИЕ: Добавить колонки tariff и pay_provider в crm_deals
// tariff: Пробний, Базовий, Мінімум, Популярний (из deal_name после "DreamCar AI")
// pay_provider: WayForPay (DC-), Platon (DCP-), Lava.top (LAVA-) (из deal_name префикса)

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);

    $results = [];

    // 1. Добавить колонки если не существуют
    $columns = $pdo->query("SHOW COLUMNS FROM crm_deals")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('tariff', $columns)) {
        $pdo->exec("ALTER TABLE crm_deals ADD COLUMN tariff VARCHAR(50) DEFAULT NULL AFTER deal_project");
        $results[] = 'Колонка tariff добавлена';
    } else {
        $results[] = 'Колонка tariff уже существует';
    }

    if (!in_array('pay_provider', $columns)) {
        $pdo->exec("ALTER TABLE crm_deals ADD COLUMN pay_provider VARCHAR(30) DEFAULT NULL AFTER tariff");
        $results[] = 'Колонка pay_provider добавлена';
    } else {
        $results[] = 'Колонка pay_provider уже существует';
    }

    // 2. Добавить индексы
    $indexes = $pdo->query("SHOW INDEX FROM crm_deals")->fetchAll();
    $indexNames = array_column($indexes, 'Key_name');

    if (!in_array('idx_tariff', $indexNames)) {
        $pdo->exec("CREATE INDEX idx_tariff ON crm_deals (tariff)");
        $results[] = 'Индекс idx_tariff создан';
    }

    if (!in_array('idx_pay_provider', $indexNames)) {
        $pdo->exec("CREATE INDEX idx_pay_provider ON crm_deals (pay_provider)");
        $results[] = 'Индекс idx_pay_provider создан';
    }

    // 3. Заполнить tariff из deal_name (DreamCar AI + слово после)
    $updTariff = $pdo->exec("
        UPDATE crm_deals
        SET tariff = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(deal_name, 'DreamCar AI ', -1), ' ', 1))
        WHERE deal_project = 'DreamCar AI'
          AND deal_name LIKE '%DreamCar AI %'
          AND (tariff IS NULL OR tariff = '')
    ");
    $results[] = "tariff заполнен для $updTariff сделок";

    // 4. Заполнить pay_provider из deal_name префикса
    $updPlaton = $pdo->exec("
        UPDATE crm_deals
        SET pay_provider = 'Platon'
        WHERE deal_project = 'DreamCar AI'
          AND deal_name LIKE 'DCP-%'
          AND (pay_provider IS NULL OR pay_provider = '')
    ");

    $updWFP = $pdo->exec("
        UPDATE crm_deals
        SET pay_provider = 'WayForPay'
        WHERE deal_project = 'DreamCar AI'
          AND deal_name LIKE 'DC-%'
          AND deal_name NOT LIKE 'DCP-%'
          AND (pay_provider IS NULL OR pay_provider = '')
    ");

    $updLava = $pdo->exec("
        UPDATE crm_deals
        SET pay_provider = 'Lava.top'
        WHERE deal_project = 'DreamCar AI'
          AND deal_name LIKE 'LAVA-%'
          AND (pay_provider IS NULL OR pay_provider = '')
    ");
    $results[] = "pay_provider заполнен: Platon=$updPlaton, WayForPay=$updWFP, Lava.top=$updLava";

    // 5. Проверка
    $stats = $pdo->query("
        SELECT
            tariff, pay_provider, COUNT(*) as cnt
        FROM crm_deals
        WHERE deal_project = 'DreamCar AI'
        GROUP BY tariff, pay_provider
        ORDER BY cnt DESC
    ")->fetchAll();

    echo json_encode([
        'success' => true,
        'results' => $results,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
