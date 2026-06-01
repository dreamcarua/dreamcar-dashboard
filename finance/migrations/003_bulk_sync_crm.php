<?php
// === 003_bulk_sync_crm.php ===
// Быстрый одноразовый импорт: INSERT ... SELECT напрямую через SQL
// Один запрос вместо 276 батчей

declare(strict_types=1);
ini_set('max_execution_time', '300');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();

// Получаем ID проектов
$projects = $db->query("SELECT id, name FROM finance_projects")->fetchAll(PDO::FETCH_KEY_PAIR);
// $projects = ['VOLVO XC90' => 1, 'AUDI Q7' => 2, ...]

// Маппинг deal_project -> project_id через CASE WHEN в SQL
// Реальные значения из БД: OLD, VOLVO, Mercedes, BMW, Q7, DreamCar AI
$caseWhen = "
    CASE UPPER(TRIM(deal_project))
        WHEN 'OLD'              THEN (SELECT id FROM finance_projects WHERE name = 'VOLVO XC90' LIMIT 1)
        WHEN 'VOLVO'            THEN (SELECT id FROM finance_projects WHERE name = 'VOLVO XC90' LIMIT 1)
        WHEN 'VOLVO XC90'       THEN (SELECT id FROM finance_projects WHERE name = 'VOLVO XC90' LIMIT 1)
        WHEN 'Q7'               THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN 'AUDI Q7'          THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN 'BANK'             THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN 'BASIC'            THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN 'GOLD'             THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN 'IBANOPLATA'       THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN 'START'            THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN 'BMW'              THEN (SELECT id FROM finance_projects WHERE name = 'BMW 330E HYBRID' LIMIT 1)
        WHEN 'BMW 330E'         THEN (SELECT id FROM finance_projects WHERE name = 'BMW 330E HYBRID' LIMIT 1)
        WHEN 'BMW 330E HYBRID'  THEN (SELECT id FROM finance_projects WHERE name = 'BMW 330E HYBRID' LIMIT 1)
        WHEN 'MERCEDES'         THEN (SELECT id FROM finance_projects WHERE name = 'MERCEDES GLE COUPE' LIMIT 1)
        WHEN 'MERCEDES GLE COUPE' THEN (SELECT id FROM finance_projects WHERE name = 'MERCEDES GLE COUPE' LIMIT 1)
        WHEN 'DREAMCAR AI'      THEN (SELECT id FROM finance_projects WHERE name = 'BMW X5 HYBRID' LIMIT 1)
        WHEN 'BMW X5'           THEN (SELECT id FROM finance_projects WHERE name = 'BMW X5 HYBRID' LIMIT 1)
        WHEN 'BMW X5 HYBRID'    THEN (SELECT id FROM finance_projects WHERE name = 'BMW X5 HYBRID' LIMIT 1)
        ELSE NULL
    END
";

// Сначала удалим старые (из батчей 0-1000 которые уже вставили)
$db->execute("DELETE FROM finance_transactions WHERE source_type = 'crm_auto'");
$deleted = $db->query("SELECT ROW_COUNT()")->fetchColumn();

// Один массовый INSERT ... SELECT
$sql = "
    INSERT INTO finance_transactions
        (project_id, type, category, description, amount_uah,
         source_type, crm_deal_id, transaction_date, created_by, created_at)
    SELECT
        ({$caseWhen})                        AS project_id,
        'income'                             AS type,
        'CRM оплата'                         AS category,
        CONCAT('Оплата CRM #', deal_id)      AS description,
        amount_uah,
        'crm_auto'                           AS source_type,
        deal_id                              AS crm_deal_id,
        DATE(created_at)                     AS transaction_date,
        'migration'                          AS created_by,
        NOW()                                AS created_at
    FROM crm_deals
    WHERE is_paid = 1
      AND deal_id IS NOT NULL
      AND amount_uah > 0
      AND UPPER(TRIM(deal_project)) NOT IN ('TEST', '?', '')
      AND ({$caseWhen}) IS NOT NULL
";

$t0 = microtime(true);
$db->execute($sql);
$inserted = $db->query("SELECT ROW_COUNT()")->fetchColumn();
$elapsed  = round(microtime(true) - $t0, 2);

// Итог по проектам
$byProject = $db->query(
    "SELECT fp.name, COUNT(*) as cnt, SUM(ft.amount_uah) as total
     FROM finance_transactions ft
     JOIN finance_projects fp ON fp.id = ft.project_id
     WHERE ft.source_type = 'crm_auto'
     GROUP BY fp.name ORDER BY fp.name"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success'    => true,
    'deleted_old'=> (int)$deleted,
    'inserted'   => (int)$inserted,
    'elapsed_sec'=> $elapsed,
    'by_project' => $byProject,
    'message'    => "Готово! {$inserted} транзакцiй iмпортовано за {$elapsed}с",
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
