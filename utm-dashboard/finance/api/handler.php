<?php
// === handler.php ===
// finance/api/handler.php
// НАЗНАЧЕНИЕ: REST API финансового модуля — все AJAX запросы
// СВЯЗИ: FinanceAuth, все Finance модели, Logger
// РАЗМЕР: ~480 строк

declare(strict_types=1);

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Session.php';
require_once __DIR__ . '/../core/FinanceAuth.php';
require_once __DIR__ . '/../core/models/FinanceProject.php';
require_once __DIR__ . '/../core/models/FinanceTransaction.php';
require_once __DIR__ . '/../core/models/FinanceCard.php';
require_once __DIR__ . '/../core/models/FinanceEmployee.php';
require_once __DIR__ . '/../core/models/FinancePayroll.php';

header('Content-Type: application/json; charset=utf-8');

// Авторизация
Auth::checkAccess(true);
FinanceAuth::checkAccess();

$logger  = new Logger();
$traceId = uniqid('fin_', true);
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$username = FinanceAuth::getUsername();

$logger->log("Finance API [{$action}]", 'info', [
    'trace_id' => $traceId,
    'action'   => $action,
    'user'     => $username,
]);

// ─── Хелперы ──────────────────────────────────────────────────────────────

function sendSuccess(mixed $data = null, string $message = 'OK'): never
{
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error'   => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function postJson(): array
{
    // Данные приходят как form POST (jQuery по умолчанию)
    if (!empty($_POST)) {
        return $_POST;
    }
    // Fallback: JSON body
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// ─── Роутер ───────────────────────────────────────────────────────────────

switch ($action) {

    // ── ПРОЕКТЫ ───────────────────────────────────────────────────────────
    case 'projects.list':
        sendSuccess(FinanceProject::getAll());

    case 'projects.get':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        $project = FinanceProject::getById($id);
        if (!$project) sendError('Проект не знайдено', 404);
        $project['pl'] = FinanceProject::getPL($id);
        sendSuccess($project);

    case 'projects.add':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d = postJson();
        if (empty($d['name'])) sendError('Назва обов\'язкова');
        if (empty($d['date_start']) || empty($d['date_end'])) sendError('Дати обов\'язковi');
        $newId = FinanceProject::add($d);
        sendSuccess(['id' => $newId], 'Проект створено');

    case 'projects.update':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d  = postJson();
        $id = (int)($d['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(['updated' => FinanceProject::update($id, $d)]);

    // ── ДАШБОРД ───────────────────────────────────────────────────────────
    case 'dashboard.summary':
        $projects = FinanceProject::getAll();
        $totalIncome    = 0;
        $totalExpenses  = 0;
        $totalWithdrawVadym  = 0;
        $totalWithdrawArtem  = 0;
        $totalConversion     = 0;

        foreach ($projects as $p) {
            $totalIncome   += (float)($p['income']   ?? 0);
            $totalExpenses += (float)($p['expenses'] ?? 0);
        }

        // Дивиденды и конвертация — из всех транзакций
        try {
            $db   = Database::getInstance();
            $stmt = $db->query(
                "SELECT type, SUM(amount_uah) as total
                 FROM finance_transactions
                 WHERE type IN ('withdrawal_vadym','withdrawal_artem','conversion')
                   AND deleted_at IS NULL
                 GROUP BY type"
            );
            foreach ($stmt->fetchAll() as $row) {
                if ($row['type'] === 'withdrawal_vadym') $totalWithdrawVadym = (float)$row['total'];
                if ($row['type'] === 'withdrawal_artem') $totalWithdrawArtem = (float)$row['total'];
                if ($row['type'] === 'conversion')       $totalConversion    = (float)$row['total'];
            }
        } catch (Exception $e) {
            $logger->log('dashboard.summary error: ' . $e->getMessage(), 'error');
        }

        $profit = $totalIncome - $totalExpenses;
        $margin = $totalIncome > 0 ? round($profit / $totalIncome * 100, 2) : null;

        sendSuccess([
            'total_income'       => $totalIncome,
            'total_expenses'     => $totalExpenses,
            'profit'             => $profit,
            'margin'             => $margin,
            'taxes_commissions'  => $totalConversion,
            'withdrawal_vadym'   => $totalWithdrawVadym,
            'withdrawal_artem'   => $totalWithdrawArtem,
            'projects'           => $projects,
        ]);

    case 'dashboard.project_pl':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(FinanceProject::getPL($id));

    // ── ТРАНЗАКЦИИ ────────────────────────────────────────────────────────
    case 'transactions.list':
        $filters = [
            'project_id' => (int)($_POST['project_id'] ?? 0) ?: null,
            'type'       => $_POST['type'] ?? null,
            'date_from'  => $_POST['date_from'] ?? null,
            'date_to'    => $_POST['date_to']   ?? null,
            'search'     => $_POST['search']    ?? null,
            'page'       => (int)($_POST['page'] ?? 1),
            'per_page'   => (int)($_POST['per_page'] ?? 50),
        ];
        sendSuccess(FinanceTransaction::getList($filters));

    case 'transactions.add':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d = postJson();
        if (empty($d['project_id'])) sendError('Потрiбен project_id');
        if (empty($d['type']))       sendError('Потрiбен type');
        if (empty($d['description'])) sendError('Потрiбен description');
        if (!isset($d['amount_uah']) || (float)$d['amount_uah'] <= 0) sendError('Сума повинна бути > 0');
        if (empty($d['transaction_date'])) sendError('Потрiбена дата');
        $d['created_by'] = $username;
        $newId = FinanceTransaction::add($d);
        sendSuccess(['id' => $newId], 'Транзакцiю додано');

    case 'transactions.update':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d  = postJson();
        $id = (int)($d['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(['updated' => FinanceTransaction::update($id, $d)]);

    case 'transactions.delete':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(['deleted' => FinanceTransaction::softDelete($id)]);

    case 'transactions.get':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        $tx = FinanceTransaction::getById($id);
        if (!$tx) sendError('Транзакцiю не знайдено', 404);
        sendSuccess($tx);

    // ── ВИТРАТИ (expenses.report) ─────────────────────────────────────────
    case 'expenses.report':
        require_once __DIR__ . '/../../core/models/Analytics.php';
        $projectId = (int)($_POST['project_id'] ?? 0);
        $dateFrom  = $_POST['date_from'] ?? null;
        $dateTo    = $_POST['date_to']   ?? null;

        // Получить название проекта для Analytics фильтра
        $modelFilter = null;
        if ($projectId) {
            $proj = FinanceProject::getById($projectId);
            $modelFilter = $proj['name'] ?? null;
        }

        $filters = [];
        if ($modelFilter) $filters['model']     = $modelFilter;
        if ($dateFrom)    $filters['date_from'] = $dateFrom;
        if ($dateTo)      $filters['date_to']   = $dateTo;

        $report = Analytics::getBySource($filters);
        sendSuccess($report);

    // ── КАРТЫ ─────────────────────────────────────────────────────────────
    case 'cards.list':
        sendSuccess(FinanceCard::getAll());

    case 'cards.add':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d = postJson();
        if (empty($d['bank_name'])) sendError('Потрiбен bank_name');
        if (empty($d['last4']))     sendError('Потрiбен last4');
        $newId = FinanceCard::add($d);
        sendSuccess(['id' => $newId], 'Картку додано');

    case 'cards.update':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d  = postJson();
        $id = (int)($d['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(['updated' => FinanceCard::update($id, $d)]);

    case 'cards.topup':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d         = postJson();
        $id        = (int)($d['id'] ?? 0);
        $amount    = (float)($d['amount_uah'] ?? 0);
        $projectId = (int)($d['project_id'] ?? 0);
        if (!$id)        sendError('Потрiбен id');
        if ($amount <= 0) sendError('Сума повинна бути > 0');
        if (!$projectId)  sendError('Потрiбен project_id');
        $result = FinanceCard::topup($id, $amount, $projectId, $d['description'] ?? '');
        sendSuccess($result, 'Картку поповнено');

    // ── СОТРУДНИКИ ────────────────────────────────────────────────────────
    case 'employees.list':
        $activeOnly = (bool)($_GET['active_only'] ?? false);
        sendSuccess(FinanceEmployee::getAll($activeOnly));

    case 'employees.add':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d = postJson();
        if (empty($d['name'])) sendError('Потрiбен name');
        $newId = FinanceEmployee::add($d);
        sendSuccess(['id' => $newId], 'Спiвробiтника додано');

    case 'employees.update':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d  = postJson();
        $id = (int)($d['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(['updated' => FinanceEmployee::update($id, $d)]);

    case 'employees.toggle':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(['toggled' => FinanceEmployee::toggleActive($id)]);

    // ── ЗАРПЛАТЫ ──────────────────────────────────────────────────────────
    case 'payroll.list':
        $filters = [
            'employee_id' => (int)($_POST['employee_id'] ?? 0) ?: null,
            'project_id'  => (int)($_POST['project_id']  ?? 0) ?: null,
            'status'      => $_POST['status'] ?? null,
            'month'       => $_POST['month']  ?? null,
            'page'        => (int)($_POST['page'] ?? 1),
            'per_page'    => (int)($_POST['per_page'] ?? 50),
        ];
        sendSuccess(FinancePayroll::getList($filters));

    case 'payroll.add':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d = postJson();
        if (empty($d['employee_id']))  sendError('Потрiбен employee_id');
        if (!isset($d['amount_uah']) || (float)$d['amount_uah'] <= 0) sendError('Сума повинна бути > 0');
        $d['created_by'] = $username;
        $newId = FinancePayroll::add($d);
        sendSuccess(['id' => $newId], 'Виплату додано');

    case 'payroll.mark_paid':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $id = (int)($_POST['id'] ?? postJson()['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        $result = FinancePayroll::markPaid($id);
        sendSuccess($result, 'Виплату позначено як виплачено');

    // ── USDT (тiльки admin) ───────────────────────────────────────────────
    case 'usdt.summary':
        FinanceAuth::requireAdmin();
        $db = Database::getInstance();

        // Транзакции withdrawal + conversion
        $stmt = $db->query(
            "SELECT ft.*, fp.name as project_name
             FROM finance_transactions ft
             LEFT JOIN finance_projects fp ON ft.project_id = fp.id
             WHERE ft.type IN ('withdrawal_vadym','withdrawal_artem','conversion')
               AND ft.deleted_at IS NULL
             ORDER BY ft.transaction_date DESC, ft.id DESC
             LIMIT 100"
        );
        $transactions = $stmt->fetchAll();

        // Агрегаты
        $totals = ['withdrawal_vadym' => 0, 'withdrawal_artem' => 0, 'conversion' => 0];
        foreach ($transactions as $t) {
            $totals[$t['type']] = ($totals[$t['type']] ?? 0) + (float)$t['amount_uah'];
        }

        // Балансы USDT
        $balances = $db->query(
            "SELECT * FROM finance_usdt_balances ORDER BY snapshot_date DESC, id DESC LIMIT 20"
        )->fetchAll();

        $delta = $totals['withdrawal_vadym'] - $totals['withdrawal_artem'];

        sendSuccess([
            'taxes_and_fees'   => $totals['conversion'],
            'dividends_vadym'  => $totals['withdrawal_vadym'],
            'dividends_artem'  => $totals['withdrawal_artem'],
            'delta'            => $delta,
            'transactions'     => $transactions,
            'balances'         => $balances,
        ]);

    case 'usdt.update_balance':
        FinanceAuth::requireAdmin();
        $d = postJson();
        if (empty($d['owner']))         sendError('Потрiбен owner (vadym/artem)');
        if (!isset($d['amount']))       sendError('Потрiбена сума');
        if (empty($d['snapshot_date'])) sendError('Потрiбена дата');
        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO finance_usdt_balances (owner, amount, snapshot_date, notes, created_by)
             VALUES (:owner, :amount, :date, :notes, :by)",
            [
                'owner'  => $d['owner'],
                'amount' => (float)$d['amount'],
                'date'   => $d['snapshot_date'],
                'notes'  => $d['notes'] ?? null,
                'by'     => $username,
            ]
        );
        sendSuccess(['id' => $db->lastInsertId()], 'Баланс оновлено');

    // ── Неизвестный action ─────────────────────────────────────────────────
    default:
        sendError("Невiдомий action: {$action}", 400);
}
