<?php
// === 004_sync_ads_expenses.php ===
// Импорт рекламных расходов из ads_data → finance_transactions
// Определяет проект по дате (попадание в период проекта) или по полю project

declare(strict_types=1);
ini_set('max_execution_time', '120');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();

// Удалить старые (если запускается повторно)
$db->execute("DELETE FROM finance_transactions WHERE source_type = 'manual' AND created_by = 'ads_migration'");

// Маппинг project поля ads_data → finance_projects.name
$projCase = "
    CASE
        WHEN UPPER(TRIM(a.project)) = 'Q7'     THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN UPPER(TRIM(a.project)) = 'AUDI Q7' THEN (SELECT id FROM finance_projects WHERE name = 'AUDI Q7' LIMIT 1)
        WHEN UPPER(TRIM(a.project)) = 'BMW'     THEN (SELECT id FROM finance_projects WHERE name = 'BMW 330E HYBRID' LIMIT 1)
        WHEN UPPER(TRIM(a.project)) = 'VOLVO'   THEN (SELECT id FROM finance_projects WHERE name = 'VOLVO XC90' LIMIT 1)
        WHEN UPPER(TRIM(a.project)) = 'MERCEDES' THEN (SELECT id FROM finance_projects WHERE name = 'MERCEDES GLE COUPE' LIMIT 1)
        WHEN UPPER(TRIM(a.project)) = 'DREAMCAR AI' THEN (SELECT id FROM finance_projects WHERE name = 'BMW X5 HYBRID' LIMIT 1)
        -- Для NULL/пустых — определяем по дате
        ELSE (
            SELECT fp.id
            FROM finance_projects fp
            WHERE a.date_start >= fp.date_start
              AND a.date_start <= fp.date_end
            ORDER BY fp.date_start DESC
            LIMIT 1
        )
    END
";

$sql = "
    INSERT INTO finance_transactions
        (project_id, type, category, description, amount_uah,
         source_type, transaction_date, created_by, created_at)
    SELECT
        ({$projCase})                                   AS project_id,
        'expense'                                       AS type,
        CONCAT('Реклама ', UPPER(COALESCE(a.utm_source, a.publisher_platform, 'інше'))) AS category,
        CONCAT(
            COALESCE(a.utm_source, a.publisher_platform, 'Реклама'), ' / ',
            COALESCE(a.utm_campaign, a.campaign_name, '—')
        )                                               AS description,
        a.spend                                         AS amount_uah,
        'manual'                                        AS source_type,
        a.date_start                                    AS transaction_date,
        'ads_migration'                                 AS created_by,
        NOW()                                           AS created_at
    FROM ads_data a
    WHERE a.spend > 0
      AND ({$projCase}) IS NOT NULL
";

$t0 = microtime(true);
$db->execute($sql);
$inserted = (int)$db->query("SELECT COUNT(*) FROM finance_transactions WHERE source_type='manual' AND created_by='ads_migration'")->fetchColumn();
$elapsed  = round(microtime(true) - $t0, 2);

$byProject = $db->query(
    "SELECT fp.name, COUNT(*) as cnt, ROUND(SUM(ft.amount_uah),2) as total
     FROM finance_transactions ft
     JOIN finance_projects fp ON fp.id = ft.project_id
     WHERE ft.source_type = 'manual' AND ft.created_by = 'ads_migration'
     GROUP BY fp.name ORDER BY fp.name"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success'    => true,
    'inserted'   => $inserted,
    'elapsed_sec'=> $elapsed,
    'by_project' => $byProject,
    'message'    => "Готово! {$inserted} витрат iмпортовано за {$elapsed}с",
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
