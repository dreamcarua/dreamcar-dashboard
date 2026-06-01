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
        // Фильтр проектов: 0 или отсутствует = все проекты
        $filterProjectId = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);

        $projects = FinanceProject::getAll();
        $totalIncome    = 0;
        $totalExpenses  = 0;
        $totalWithdrawVadym  = 0;
        $totalWithdrawArtem  = 0;
        $totalConversion     = 0;

        // Сумма income/expenses - либо по всем проектам либо по одному
        foreach ($projects as $p) {
            if ($filterProjectId > 0 && (int)$p['id'] !== $filterProjectId) continue;
            $totalIncome   += (float)($p['income']   ?? 0);
            $totalExpenses += (float)($p['expenses'] ?? 0);
        }

        // Дивиденды и налоги/комиссии — из транзакций (фильтр по проекту если указан)
        try {
            $db = Database::getInstance();

            $withdrawWhere  = "type IN ('withdrawal_vadym','withdrawal_artem') AND deleted_at IS NULL";
            $withdrawParams = [];
            if ($filterProjectId > 0) {
                $withdrawWhere        .= " AND project_id = :pid";
                $withdrawParams[':pid'] = $filterProjectId;
            }

            $stmt = $db->query(
                "SELECT type, SUM(amount_uah) as total
                 FROM finance_transactions
                 WHERE $withdrawWhere
                 GROUP BY type",
                $withdrawParams
            );
            foreach ($stmt->fetchAll() as $row) {
                if ($row['type'] === 'withdrawal_vadym') $totalWithdrawVadym = (float)$row['total'];
                if ($row['type'] === 'withdrawal_artem') $totalWithdrawArtem = (float)$row['total'];
            }

            // Налоги и комиссии эквайринга — из expense с категориями taxes и bank_fees
            $taxWhere  = "type = 'expense' AND category IN ('taxes','bank_fees') AND deleted_at IS NULL";
            $taxParams = [];
            if ($filterProjectId > 0) {
                $taxWhere        .= " AND project_id = :pid_tax";
                $taxParams[':pid_tax'] = $filterProjectId;
            }
            $totalConversion = (float)$db->query(
                "SELECT COALESCE(SUM(amount_uah), 0) FROM finance_transactions WHERE $taxWhere",
                $taxParams
            )->fetchColumn();

        } catch (Exception $e) {
            $logger->log('dashboard.summary error: ' . $e->getMessage(), 'error');
        }

        $profit = $totalIncome - $totalExpenses;
        $margin = $totalIncome > 0 ? round($profit / $totalIncome * 100, 2) : null;

        // Уникальные покупатели: из crm_deals (первичный источник данных)
        // Идентификатор покупателя: phone → email → deal_id
        // contact_id НЕ используем: SendPulse создает новый contact_id на каждую сделку
        $uniqueBuyers    = 0;
        $totalPayments   = 0;
        $repeatBuyersPct = null;
        try {
            $ubWhere  = "is_paid = 1";
            $ubParams = [];

            if ($filterProjectId > 0) {
                // Получаем crm_deal_project для выбранного проекта
                $proj = $db->query(
                    "SELECT crm_deal_project FROM finance_projects WHERE id = :pid LIMIT 1",
                    [':pid' => $filterProjectId]
                )->fetch();
                if ($proj && !empty($proj['crm_deal_project'])) {
                    $dealProjects = array_map('trim', explode(',', $proj['crm_deal_project']));
                    $placeholders = implode(',', array_map(fn($i) => ":dp$i", array_keys($dealProjects)));
                    $ubWhere .= " AND deal_project IN ($placeholders)";
                    foreach ($dealProjects as $i => $dp) {
                        $ubParams[":dp$i"] = $dp;
                    }
                }
            }

            $totalPayments = (int)$db->query(
                "SELECT COUNT(*) FROM crm_deals WHERE $ubWhere",
                $ubParams
            )->fetchColumn();

            $uniqueBuyers = (int)$db->query(
                "SELECT COUNT(DISTINCT COALESCE(
                     NULLIF(phone, ''),
                     NULLIF(email, ''),
                     deal_id
                 )) FROM crm_deals WHERE $ubWhere",
                $ubParams
            )->fetchColumn();

            if ($totalPayments > 0 && $uniqueBuyers > 0) {
                $repeatBuyersPct = round((1 - $uniqueBuyers / $totalPayments) * 100, 1);
            }
        } catch (Exception $e) {
            $logger->log('dashboard.summary unique_buyers error: ' . $e->getMessage(), 'error');
        }

        // Разбивка расходов по группам категорий (только type='expense')
        // card_topup НЕ включаем (внутренний перевод)
        // salary добавляется отдельным запросом в группу team
        $expensesByGroup = [
            'advertising' => 0,
            'production'  => 0,
            'fees'        => 0,
            'operations'  => 0,
            'team'        => 0,
            'other'       => 0,
        ];
        try {
            $ebgWhere  = "type = 'expense' AND deleted_at IS NULL";
            $ebgParams = [];
            if ($filterProjectId > 0) {
                $ebgWhere        .= " AND project_id = :pid";
                $ebgParams[':pid'] = $filterProjectId;
            }

            $stmt = $db->query(
                "SELECT category, SUM(amount_uah) as total
                 FROM finance_transactions
                 WHERE $ebgWhere
                 GROUP BY category",
                $ebgParams
            );
            foreach ($stmt->fetchAll() as $row) {
                $cat   = (string)($row['category'] ?? 'other');
                $group = FinanceTransaction::getCategoryGroup($cat);
                $expensesByGroup[$group] += (float)$row['total'];
            }

            // Зарплаты (type='salary') → группа 'team'
            $salaryWhere  = "type = 'salary' AND deleted_at IS NULL";
            $salaryParams = [];
            if ($filterProjectId > 0) {
                $salaryWhere        .= " AND project_id = :pid";
                $salaryParams[':pid'] = $filterProjectId;
            }
            $salarySum = (float)$db->query(
                "SELECT COALESCE(SUM(amount_uah), 0) FROM finance_transactions WHERE $salaryWhere",
                $salaryParams
            )->fetchColumn();
            $expensesByGroup['team'] += $salarySum;

        } catch (Exception $e) {
            $logger->log('dashboard.summary expenses_by_group error: ' . $e->getMessage(), 'error');
        }

        sendSuccess([
            'total_income'       => $totalIncome,
            'total_expenses'     => $totalExpenses,
            'profit'             => $profit,
            'margin'             => $margin,
            'taxes_and_fees'     => $totalConversion,
            'withdrawal_vadym'   => $totalWithdrawVadym,
            'withdrawal_artem'   => $totalWithdrawArtem,
            'expenses_by_group'  => $expensesByGroup,
            'group_labels'       => FinanceTransaction::getGroupLabels(),
            'unique_buyers'      => $uniqueBuyers,
            'total_payments'     => $totalPayments,
            'repeat_buyers_pct'  => $repeatBuyersPct,
            'projects'           => $projects,
            'filter_project_id'  => $filterProjectId,
        ]);

    case 'dashboard.project_pl':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) sendError('Потрiбен id');
        sendSuccess(FinanceProject::getPL($id));

    // ── ТРАНЗАКЦИИ ────────────────────────────────────────────────────────
    case 'transactions.list':
        $filters = [
            'project_id'     => (int)($_POST['project_id'] ?? 0) ?: null,
            'type'           => $_POST['type'] ?? null,
            'date_from'      => $_POST['date_from'] ?? null,
            'date_to'        => $_POST['date_to']   ?? null,
            'search'         => $_POST['search']    ?? null,
            'page'           => (int)($_POST['page'] ?? 1),
            'per_page'       => (int)($_POST['per_page'] ?? 50),
            'category_group' => $_POST['category_group'] ?? null,
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

        // Авто-расходы: 2% bank_fees + 10% taxes при каждом входящем платеже
        $autoExpenses = null;
        if (in_array($d['type'] ?? '', ['income', 'income_extra'], true)) {
            try {
                $autoExpenses = FinanceTransaction::createAutoExpenses(
                    $newId,
                    (float)$d['amount_uah'],
                    (int)$d['project_id'],
                    $d['transaction_date'] ?? date('Y-m-d'),
                    $username
                );
            } catch (Throwable $eAuto) {
                error_log('[transactions.add] createAutoExpenses error: ' . $eAuto->getMessage());
            }
        }

        sendSuccess(['id' => $newId, 'auto_expenses' => $autoExpenses], 'Транзакцiю додано');

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

    // ── УПРОЩЁННОЕ ДОБАВЛЕНИЕ ЛЮБОГО РАСХОДА (БЕЗ UTM) ──────────────────
    // Используется новой кнопкой "➕ Додати витрату" в шапке финансов.
    // Для покупки авто, оренди офиса, бухгалтерии и т.д.
    case 'transactions.add_expense':
        if (!FinanceAuth::canWrite()) sendError('Немає прав', 403);
        $d = postJson();

        $projectId   = (int)   ($d['project_id']       ?? 0);
        $category    = (string)($d['category']         ?? '');
        $amount      = (float) ($d['amount_uah']       ?? 0);
        $description = trim((string)($d['description'] ?? ''));
        $date        = $d['transaction_date'] ?? date('Y-m-d');
        $cardId      = (int)   ($d['card_id']          ?? 0);
        $notes       = $d['notes'] ?? null;

        // Валидация
        if ($projectId <= 0)     sendError('Потрiбен project_id');
        if ($amount <= 0)        sendError('Сума повинна бути > 0');
        if ($description === '') sendError('Потрiбен опис');

        $flatCategories = FinanceTransaction::getFlatCategories();
        if (!array_key_exists($category, $flatCategories)) {
            sendError('Невiдома категорiя: ' . $category);
        }

        // Проверить что проект существует
        $project = FinanceProject::getById($projectId);
        if (!$project) sendError('Проект не знайдено', 404);

        // Создание expense транзакции
        try {
            $txId = FinanceTransaction::add([
                'project_id'       => $projectId,
                'type'             => 'expense',
                'category'         => $category,
                'description'      => $description,
                'amount_uah'       => $amount,
                'transaction_date' => $date,
                'source_type'      => 'manual',
                'card_id'          => $cardId > 0 ? $cardId : null,
                'notes'            => $notes,
            ]);
        } catch (Throwable $e) {
            sendError('Помилка створення: ' . $e->getMessage());
        }

        // Автосписание с карты (если указана)
        $newBalance = null;
        if ($txId > 0 && $cardId > 0 && FinanceCard::exists($cardId)) {
            try {
                FinanceCard::deductBalance($cardId, $amount);
                $card = FinanceCard::getById($cardId);
                $newBalance = $card ? (float)$card['balance_uah'] : null;
            } catch (Throwable $e) {
                error_log('[transactions.add_expense] deductBalance failed: ' . $e->getMessage());
            }
        }

        sendSuccess([
            'tx_id'       => $txId,
            'new_balance' => $newBalance,
            'category'    => $category,
            'group'       => FinanceTransaction::getCategoryGroup($category),
        ], 'Витрату додано');

    // ── ВИТРАТИ (expenses.report) ─────────────────────────────────────────
    case 'expenses.report':
        require_once __DIR__ . '/../../core/models/Analytics.php';
        $projectId     = (int)($_POST['project_id'] ?? 0);
        $dateFrom      = $_POST['date_from'] ?? null;
        $dateTo        = $_POST['date_to']   ?? null;
        $categoryGroup = (string)($_POST['category_group'] ?? '');

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

        // 1. По каналам (источникам) — агрегация из finance_transactions
        // Все группы берем из finance_transactions (ручные записи пользователя).
        // Если category_group пустой — все категории; если указан — только категории группы.
        $bySource = [];
        try {
            $db = Database::getInstance();
            $grouped = FinanceTransaction::getExpenseCategoriesGrouped();

            $sqlWhere  = ["type = 'expense'", "deleted_at IS NULL"];
            $sqlParams = [];

            if ($projectId > 0) {
                $sqlWhere[] = 'project_id = :pid';
                $sqlParams[':pid'] = $projectId;
            }
            if ($dateFrom) {
                $sqlWhere[] = 'transaction_date >= :df';
                $sqlParams[':df'] = $dateFrom;
            }
            if ($dateTo) {
                $sqlWhere[] = 'transaction_date <= :dt';
                $sqlParams[':dt'] = $dateTo;
            }

            // Если указана группа — фильтруем по категориям группы
            if ($categoryGroup !== '') {
                $groupCategories = $grouped[$categoryGroup]['items'] ?? [];
                if (!empty($groupCategories)) {
                    $catPlaceholders = [];
                    $idx = 0;
                    foreach ($groupCategories as $catKey => $_) {
                        $ph = ':cat' . $idx;
                        $catPlaceholders[] = $ph;
                        $sqlParams[$ph] = $catKey;
                        $idx++;
                    }
                    $sqlWhere[] = 'category IN (' . implode(',', $catPlaceholders) . ')';
                }
            }

            $sql = 'SELECT category, SUM(amount_uah) AS total
                    FROM finance_transactions
                    WHERE ' . implode(' AND ', $sqlWhere) . '
                    GROUP BY category
                    ORDER BY total DESC';

            $rows = $db->query($sql, $sqlParams)->fetchAll(PDO::FETCH_ASSOC);

            // Строим карту label-ов из всех групп
            $allLabels = [];
            foreach ($grouped as $grp) {
                foreach ($grp['items'] as $k => $v) {
                    $allLabels[$k] = $v;
                }
            }

            // Строим карту category => groupKey
            $catToGroup = [];
            foreach ($grouped as $grpKey => $grpData) {
                foreach ($grpData['items'] as $catKey => $_) {
                    $catToGroup[$catKey] = $grpKey;
                }
            }

            foreach ($rows as $r) {
                $label = $allLabels[$r['category']] ?? $r['category'];
                $grpKey = $catToGroup[$r['category']] ?? 'other';
                $bySource[] = [
                    'utm_source'  => $label,
                    'source'      => $label,
                    'group_key'   => $grpKey,
                    'spend'       => (float)$r['total'],
                ];
            }
        } catch (Throwable $e) {
            error_log('[expenses.report] by_source error: ' . $e->getMessage());
        }

        // 2. By group — агрегация по группам для pie chart
        $byGroupMap = [];
        foreach ($bySource as $row) {
            $gk = $row['group_key'] ?? 'other';
            $byGroupMap[$gk] = ($byGroupMap[$gk] ?? 0.0) + (float)($row['spend'] ?? 0);
        }
        $byGroup = [];
        foreach ($byGroupMap as $gk => $gTotal) {
            if ($gTotal > 0) {
                $byGroup[] = ['group_key' => $gk, 'spend' => $gTotal];
            }
        }

        // 3. Totals — общая сумма расходов
        $totSpend = 0.0;
        foreach ($bySource as $row) {
            $totSpend += (float)($row['spend'] ?? 0);
        }
        $totals = [
            'total_spend' => $totSpend,
        ];

        // 3. By day — расходы по дням (для line chart)
        // Резолвим даты проекта если указан project_id
        $byDayFrom = $dateFrom;
        $byDayTo   = $dateTo;
        if ($projectId && $modelFilter) {
            require_once __DIR__ . '/../../core/models/CrmDeal.php';
            $projectDates = CrmDeal::getProjectDates();
            $projectKey   = strtoupper(trim($modelFilter));
            if (isset($projectDates[$projectKey])) {
                $pFrom = $projectDates[$projectKey]['date_from'];
                $pTo   = $projectDates[$projectKey]['date_to'];
                $byDayFrom = $byDayFrom ? max($byDayFrom, $pFrom) : $pFrom;
                $byDayTo   = $byDayTo   ? min($byDayTo,   $pTo)   : $pTo;
            }
        }

        $byDay = [];
        try {
            $db = Database::getInstance();
            $sqlWhere  = ["type = 'expense'", "deleted_at IS NULL"];
            $sqlParams = [];
            if ($projectId > 0) {
                $sqlWhere[] = 'project_id = :pid2';
                $sqlParams[':pid2'] = $projectId;
            }
            if ($byDayFrom) { $sqlWhere[] = 'transaction_date >= :df2'; $sqlParams[':df2'] = $byDayFrom; }
            if ($byDayTo)   { $sqlWhere[] = 'transaction_date <= :dt2'; $sqlParams[':dt2'] = $byDayTo; }
            // Если указана конкретная группа — фильтруем по её категориям
            if ($categoryGroup !== '') {
                $grpItems = FinanceTransaction::getExpenseCategoriesGrouped()[$categoryGroup]['items'] ?? [];
                if (!empty($grpItems)) {
                    $dayPh = [];
                    $di = 0;
                    foreach ($grpItems as $ck => $_) {
                        $ph = ':dcat' . $di;
                        $dayPh[] = $ph;
                        $sqlParams[$ph] = $ck;
                        $di++;
                    }
                    $sqlWhere[] = 'category IN (' . implode(',', $dayPh) . ')';
                }
            }
            $sql = "SELECT transaction_date AS d, COALESCE(SUM(amount_uah), 0) AS s
                    FROM finance_transactions
                    WHERE " . implode(' AND ', $sqlWhere) . "
                    GROUP BY transaction_date
                    ORDER BY transaction_date ASC";
            $rows = $db->query($sql, $sqlParams)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $byDay[] = ['date' => $r['d'], 'spend' => (float)$r['s']];
            }
        } catch (Throwable $eByDay) {
            error_log('[expenses.report] by_day error: ' . $eByDay->getMessage());
        }

        sendSuccess([
            'by_source' => $bySource,
            'by_group'  => $byGroup,
            'totals'    => $totals,
            'by_day'    => $byDay,
        ]);

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

    // ── УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ ──────────────────────────────────────────

    case 'users.list':
        FinanceAuth::requireAdmin();
        $usersFile = __DIR__ . '/../../config/users.json';
        $usersData = json_decode(file_get_contents($usersFile), true);
        $usersList = array_map(function($u) {
            // Не отдавать хеш пароля на фронт
            return [
                'username'    => $u['username'],
                'role'        => $u['role'] ?? '',
                'finance_role' => $u['finance_role'] ?? '',
                'utm_term'    => $u['utm_term'] ?? null,
                'is_active'   => $u['is_active'] ?? true,
                'last_login'  => $u['last_login'] ?? null,
                'created_at'  => $u['created_at'] ?? null,
                'permissions' => $u['permissions'] ?? [],
            ];
        }, $usersData['users'] ?? []);
        sendSuccess(['users' => $usersList]);

    case 'users.save':
        FinanceAuth::requireAdmin();
        $d = postJson();
        $uname = trim($d['username'] ?? '');
        if (!$uname) sendError('Потрiбен username');
        if (strlen($uname) < 3) sendError('Username мiнiмум 3 символи');

        $usersFile = __DIR__ . '/../../config/users.json';
        $usersData = json_decode(file_get_contents($usersFile), true);
        $users = $usersData['users'] ?? [];

        // Визначити: новий чи оновлення (по наявностi в файлi)
        $existingIndex = -1;
        foreach ($users as $idx => $u) {
            if ($u['username'] === $uname) { $existingIndex = $idx; break; }
        }
        $isNew = ($existingIndex === -1);

        if ($isNew) {
            // Новий користувач — пароль обов'язковий
            $pass = $d['password'] ?? '';
            if (strlen($pass) < 8) sendError('Пароль мiнiмум 8 символiв');
            $users[] = [
                'username'     => $uname,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]),
                'role'         => $d['role'] ?? 'targetolog',
                'finance_role' => $d['finance_role'] ?? '',
                'utm_term'     => $d['utm_term'] ?? null,
                'is_active'    => (bool)($d['is_active'] ?? true),
                'created_at'   => date('c'),
                'last_login'   => null,
                'settings'     => ['theme' => 'dark', 'language' => 'ru'],
                'permissions'  => $d['permissions'] ?? [
                    'main_dashboard' => true,
                    'finance' => false,
                    'finance_salary' => false,
                    'finance_cards' => false,
                    'finance_usdt' => false,
                    'finance_settings' => false,
                ],
            ];
        } else {
            // Оновити iснуючого по $existingIndex
            $u = &$users[$existingIndex];
            if (!empty($d['password']) && strlen($d['password']) >= 8) {
                $u['password_hash'] = password_hash($d['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            }
            $u['role']         = $d['role'] ?? $u['role'];
            $u['finance_role'] = $d['finance_role'] ?? $u['finance_role'];
            $u['utm_term']     = $d['utm_term'] ?? $u['utm_term'];
            $u['is_active']    = (bool)($d['is_active'] ?? $u['is_active']);
            if (isset($d['permissions'])) {
                $u['permissions'] = $d['permissions'];
            }
            unset($u);
        }

        $usersData['users'] = $users;
        $usersData['last_updated'] = date('c');
        file_put_contents($usersFile, json_encode($usersData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        sendSuccess([], $isNew ? 'Користувача створено' : 'Користувача оновлено');

    case 'users.delete':
        FinanceAuth::requireAdmin();
        $uname = trim($_POST['username'] ?? '');
        if (!$uname) sendError('Потрiбен username');
        if ($uname === $username) sendError('Не можна видалити поточного користувача');

        $usersFile = __DIR__ . '/../../config/users.json';
        $usersData = json_decode(file_get_contents($usersFile), true);
        $before = count($usersData['users'] ?? []);
        $usersData['users'] = array_values(array_filter(
            $usersData['users'] ?? [],
            fn($u) => $u['username'] !== $uname
        ));

        if (count($usersData['users']) === $before) sendError('Користувача не знайдено');
        $usersData['last_updated'] = date('c');
        file_put_contents($usersFile, json_encode($usersData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        sendSuccess([], 'Користувача видалено');

    // ── НАЛАШТУВАННЯ СТАВОК (тiльки admin) ───────────────────────────────
    case 'settings.get_rates':
        FinanceAuth::requireAdmin();
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT setting_key, setting_val, description FROM finance_settings
             WHERE setting_key IN ('acquiring_fee_pct','tax_pct')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $rates = [];
        foreach ($rows as $r) {
            $rates[$r['setting_key']] = [
                'value'       => (float) $r['setting_val'],
                'description' => $r['description'],
            ];
        }
        // Дефолты если таблица пустая
        if (!isset($rates['acquiring_fee_pct'])) {
            $rates['acquiring_fee_pct'] = ['value' => 2.0, 'description' => 'Комiсiя еквайрингу (%)'];
        }
        if (!isset($rates['tax_pct'])) {
            $rates['tax_pct'] = ['value' => 10.0, 'description' => 'Податки та бух. витрати (%)'];
        }
        sendSuccess($rates);

    case 'settings.save_rates':
        FinanceAuth::requireAdmin();
        $d      = postJson();
        $feePct = isset($d['acquiring_fee_pct']) ? (float) $d['acquiring_fee_pct'] : null;
        $taxPct = isset($d['tax_pct'])            ? (float) $d['tax_pct']            : null;

        if ($feePct === null || $taxPct === null) {
            sendError('Потрiбнi обидва поля: acquiring_fee_pct, tax_pct');
        }
        if ($feePct < 0 || $feePct > 100) sendError('acquiring_fee_pct має бути вiд 0 до 100');
        if ($taxPct < 0 || $taxPct > 100) sendError('tax_pct має бути вiд 0 до 100');

        $db = Database::getInstance();

        // 1. Сохранить новые ставки
        foreach ([
            'acquiring_fee_pct' => $feePct,
            'tax_pct'           => $taxPct,
        ] as $key => $val) {
            $db->query(
                "INSERT INTO finance_settings (setting_key, setting_val, updated_by)
                 VALUES (:k, :v, :by)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_by = VALUES(updated_by), updated_at = NOW()",
                [':k' => $key, ':v' => $val, ':by' => $username]
            );
        }

        // 2. Пересчитать ВСЕ авто-транзакции bank_fees по новому %
        //    Сумма = сумма родительской income * новый %
        $db->query(
            "UPDATE finance_transactions ft
             JOIN finance_transactions parent ON parent.id = ft.parent_transaction_id
             SET ft.amount_uah = ROUND(parent.amount_uah * :pct / 100, 2),
                 ft.description = CONCAT('Комiсiя еквайрингу ', :pct_desc, '% вiд #', parent.id)
             WHERE ft.source_type = 'crm_auto'
               AND ft.category = 'bank_fees'
               AND ft.deleted_at IS NULL",
            [':pct' => $feePct, ':pct_desc' => $feePct]
        );

        // 3. Пересчитать ВСЕ авто-транзакции taxes по новому %
        $db->query(
            "UPDATE finance_transactions ft
             JOIN finance_transactions parent ON parent.id = ft.parent_transaction_id
             SET ft.amount_uah = ROUND(parent.amount_uah * :pct / 100, 2),
                 ft.description = CONCAT('Податки та бух. витрати ', :pct_desc, '% вiд #', parent.id)
             WHERE ft.source_type = 'crm_auto'
               AND ft.category = 'taxes'
               AND ft.deleted_at IS NULL",
            [':pct' => $taxPct, ':pct_desc' => $taxPct]
        );

        // Считаем сколько записей пересчитано
        $recalcFee = (int)$db->query(
            "SELECT COUNT(*) FROM finance_transactions WHERE source_type='crm_auto' AND category='bank_fees' AND deleted_at IS NULL"
        )->fetchColumn();
        $recalcTax = (int)$db->query(
            "SELECT COUNT(*) FROM finance_transactions WHERE source_type='crm_auto' AND category='taxes' AND deleted_at IS NULL"
        )->fetchColumn();

        $logger->log('settings.save_rates', 'info', [
            'trace_id'          => $traceId,
            'acquiring_fee_pct' => $feePct,
            'tax_pct'           => $taxPct,
            'recalc_bank_fees'  => $recalcFee,
            'recalc_taxes'      => $recalcTax,
            'user'              => $username,
        ]);
        sendSuccess([
            'recalc_bank_fees' => $recalcFee,
            'recalc_taxes'     => $recalcTax,
        ], "Ставки збережено. Перераховано: {$recalcFee} комiсiй, {$recalcTax} податкiв");

    // ── Неизвестный action ─────────────────────────────────────────────────
    default:
        sendError("Невiдомий action: {$action}", 400);
}
