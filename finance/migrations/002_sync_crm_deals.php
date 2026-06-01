<?php
// === 002_sync_crm_deals.php ===
// finance/migrations/002_sync_crm_deals.php
// НАЗНАЧЕНИЕ: Одноразовый импорт оплаченных сделок из crm_deals в finance_transactions
// Батчи по 500. Параметр ?offset=0

declare(strict_types=1);
ini_set('max_execution_time', '60');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../core/models/FinanceProject.php';
require_once __DIR__ . '/../core/models/FinanceTransaction.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Маппинг алиасов crm_deals.deal_project → finance_projects.name ──────
// Ключи в UPPER, значения — точные названия finance_projects.name
$aliasMap = [
    'DREAMCAR AI'        => 'BMW X5 HYBRID',
    'BMW X5'             => 'BMW X5 HYBRID',
    'BMW X5 HYBRID'      => 'BMW X5 HYBRID',
    'MERCEDES'           => 'MERCEDES GLE COUPE',
    'MERCEDES GLE COUPE' => 'MERCEDES GLE COUPE',
    'BMW'                => 'BMW 330E HYBRID',
    'BMW 330E'           => 'BMW 330E HYBRID',
    'BMW 330E HYBRID'    => 'BMW 330E HYBRID',
    'VOLVO'              => 'VOLVO XC90',
    'VOLVO XC90'         => 'VOLVO XC90',
    'Q7'                 => 'AUDI Q7',
    'AUDI Q7'            => 'AUDI Q7',
    'BANK'               => 'AUDI Q7',
    'BASIC'              => 'AUDI Q7',
    'GOLD'               => 'AUDI Q7',
    'IBANOPLATA'         => 'AUDI Q7',
    'START'              => 'AUDI Q7',
    // OLD — сделки до разбивки по проектам → VOLVO XC90 (самый ранний проект)
    'OLD'                => 'VOLVO XC90',
    // TEST, ? — игнорировать (нет в finance_projects)
];

// Кешируем ID проектов
$projectCache = [];
$allProjects  = FinanceProject::getAll();
foreach ($allProjects as $p) {
    $projectCache[strtoupper($p['name'])] = (int)$p['id'];
}

function resolveProjectId(string $dealProject, array $aliasMap, array $projectCache): int
{
    $key = strtoupper(trim($dealProject));
    // Прямой маппинг
    if (isset($aliasMap[$key])) {
        $resolved = strtoupper($aliasMap[$key]);
        return $projectCache[$resolved] ?? 0;
    }
    // Прямое совпадение с проектом
    return $projectCache[$key] ?? 0;
}

$db     = Database::getInstance();
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit  = 500;

// Общее количество
$total = (int)$db->query(
    "SELECT COUNT(*) FROM crm_deals WHERE is_paid = 1 AND deal_id IS NOT NULL AND amount_uah > 0"
)->fetchColumn();

// Батч
$deals = $db->query(
    sprintf(
        "SELECT deal_id, amount_uah, deal_project, created_at
         FROM crm_deals
         WHERE is_paid = 1 AND deal_id IS NOT NULL AND amount_uah > 0
         ORDER BY created_at ASC
         LIMIT %d OFFSET %d",
        $limit,
        $offset
    )
)->fetchAll(PDO::FETCH_ASSOC);

$imported  = 0;
$skipped   = 0;
$noProject = 0;
$errors    = [];

foreach ($deals as $deal) {
    try {
        if (FinanceTransaction::isDuplicateCrm((string)$deal['deal_id'])) {
            $skipped++;
            continue;
        }

        $projectId = resolveProjectId($deal['deal_project'] ?? '', $aliasMap, $projectCache);

        if ($projectId === 0) {
            $noProject++;
            continue;
        }

        // Прямо вставляем без createFromCrm (который делает getProjectIdByName по name)
        $db->execute(
            "INSERT INTO finance_transactions
                (project_id, type, category, description, amount_uah,
                 source_type, crm_deal_id, transaction_date, created_by, created_at)
             VALUES
                (:pid, 'income', 'CRM оплата', :desc, :amt,
                 'crm_auto', :deal_id, :date, 'migration', NOW())",
            [
                ':pid'     => $projectId,
                ':desc'    => 'Оплата CRM #' . $deal['deal_id'],
                ':amt'     => (float)$deal['amount_uah'],
                ':deal_id' => (string)$deal['deal_id'],
                ':date'    => substr((string)$deal['created_at'], 0, 10),
            ]
        );
        $imported++;

    } catch (Throwable $e) {
        $errors[] = "deal_id={$deal['deal_id']}: " . $e->getMessage();
        if (count($errors) >= 5) break;
    }
}

$nextOffset = $offset + $limit;
$hasMore    = $nextOffset < $total;

echo json_encode([
    'success'     => true,
    'total_paid'  => $total,
    'offset'      => $offset,
    'batch_size'  => count($deals),
    'imported'    => $imported,
    'skipped'     => $skipped,
    'no_project'  => $noProject,
    'errors'      => $errors,
    'has_more'    => $hasMore,
    'next_offset' => $hasMore ? $nextOffset : null,
    'next_url'    => $hasMore
        ? BASE_URL . 'finance/migrations/002_sync_crm_deals.php?offset=' . $nextOffset
        : null,
    'message'     => "Батч {$offset}-" . ($offset + count($deals)) .
                     ": iмпортовано {$imported}, пропущено {$skipped}, без проекту {$noProject}" .
                     ($hasMore ? " | Запусти next_url для продовження" : " | ЗАВЕРШЕНО"),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
