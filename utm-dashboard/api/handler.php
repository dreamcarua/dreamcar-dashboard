<?php
// === handler.php ===
// /home/serflow/dreamcar.ai-platform.space/www/dashboard/utm-dashboard/api/handler.php
// НАЗНАЧЕНИЕ: API обработчик для AJAX запросов
// СВЯЗИ: config/app_config.php, core/Logger.php, core/DataCleaner.php
// ДАННЫЕ: data/utm_clean.json
// API: -
// РАЗМЕР: ~300 строк
// ОБНОВЛЕНО: 2025-11-15 12:00

// Включить отображение ошибок для отладки
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * СТРУКТУРА ФАЙЛА:
 * 1. Подключение файлов (строки 15-30)
 * 2. Обработка действий (строки 31-150)
 * 3. Вспомогательные функции (строки 151-300)
 */

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/DataCleaner.php';
require_once __DIR__ . '/../core/models/AdsData.php';
require_once __DIR__ . '/../core/models/CrmDeal.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Session.php';

// Перевірка доступу для API
Session::start();

// Отримати utm_term користувача (для фільтрації витрат)
$userRole = Session::getRole();
$isGuest = Auth::isGuest();
if ($isGuest) {
    $userUtmTerm = $_GET['utm_term'] ?? null;
} else {
    $userUtmTerm = Auth::getUserUtmTerm();
}

header('Content-Type: application/json');

$logger = new Logger();
$action = $_REQUEST['action'] ?? 'get_data';

// ==========================================
// МАРШРУТИЗАЦИЯ ДЕЙСТВИЙ
// ==========================================

try {
    switch ($action) {
        case 'get_data':
            handleGetData();
            break;

        case 'sync':
            handleSync();
            break;

        case 'clean_data':
            handleCleanData();
            break;

        case 'get_stats':
            handleGetStats();
            break;

        case 'export':
            handleExport();
            break;

        // ==========================================
        // РУЧНЫЕ РАСХОДЫ
        // ==========================================
        case 'get_manual_costs':
            handleGetManualCosts();
            break;

        case 'get_manual_cost':
            handleGetManualCost();
            break;

        case 'add_manual_cost':
            handleAddManualCost();
            break;

        case 'update_manual_cost':
            handleUpdateManualCost();
            break;

        case 'delete_manual_cost':
            handleDeleteManualCost();
            break;

        // ==========================================
        // ТАБЛИЦА СДЕЛОК
        // ==========================================
        case 'get_deals_table':
            handleGetDealsTable();
            break;

        default:
            sendError('Неизвестное действие');
    }
} catch (Exception $e) {
    $logger->error('Ошибка в API handler', [
        'action' => $action,
        'error' => $e->getMessage()
    ]);

    sendError('Ошибка сервера: ' . $e->getMessage());
}

// ==========================================
// ОБРАБОТЧИКИ ДЕЙСТВИЙ
// ==========================================

/**
 * Получить данные
 */
function handleGetData() {
    global $logger;

    // Загрузить очищенные данные
    $data = loadJSON(UTM_CLEAN_FILE);

    // Если данных нет - создать тестовые
    if (empty($data)) {
        $logger->warning('Нет данных в utm_clean.json, генерируем тестовые данные');
        $data = generateTestData();
        saveJSON(UTM_CLEAN_FILE, $data);
    }

    // Получить статистику
    $cleaner = new DataCleaner();
    $stats = $cleaner->getUTMStats($data);

    $logger->info('Данные отправлены клиенту', [
        'count' => count($data)
    ]);

    sendSuccess([
        'data' => $data,
        'stats' => $stats
    ]);
}

/**
 * Синхронизация с SendPulse
 */
function handleSync() {
    global $logger;

    $logger->info('Запуск синхронизации');

    // Проверить наличие API класса
    if (!file_exists(__DIR__ . '/sendpulse.php')) {
        sendError('SendPulse API не настроен');
        return;
    }

    require_once __DIR__ . '/sendpulse.php';

    try {
        $api = new SendPulseAPI();
        $contacts = $api->syncContacts();

        if ($contacts) {
            // Очистить данные
            $cleaner = new DataCleaner();
            $result = $cleaner->processAndSave();

            $logger->success('Синхронизация завершена', [
                'contacts' => count($contacts),
                'cleaned' => count($result['cleaned_data'])
            ]);

            sendSuccess([
                'contacts' => count($contacts),
                'cleaned' => count($result['cleaned_data'])
            ]);
        } else {
            sendError('Не удалось получить контакты');
        }
    } catch (Exception $e) {
        $logger->error('Ошибка синхронизации', ['error' => $e->getMessage()]);
        sendError('Ошибка: ' . $e->getMessage());
    }
}

/**
 * Очистить данные
 */
function handleCleanData() {
    global $logger;

    $logger->info('Запуск очистки данных');

    $cleaner = new DataCleaner();
    $result = $cleaner->processAndSave();

    if ($result) {
        sendSuccess([
            'cleaned' => count($result['cleaned_data']),
            'stats' => $result['stats']
        ]);
    } else {
        sendError('Нет данных для очистки');
    }
}

/**
 * Получить статистику
 */
function handleGetStats() {
    $data = loadJSON(UTM_CLEAN_FILE);

    if (empty($data)) {
        sendError('Нет данных');
        return;
    }

    $cleaner = new DataCleaner();
    $stats = $cleaner->getUTMStats($data);

    sendSuccess($stats);
}

/**
 * Экспорт данных
 */
function handleExport() {
    $data = loadJSON(UTM_CLEAN_FILE);

    if (empty($data)) {
        sendError('Нет данных для экспорта');
        return;
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="utm_export_' . date('Y-m-d') . '.json"');

    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// ГЕНЕРАЦИЯ ТЕСТОВЫХ ДАННЫХ
// ==========================================

function generateTestData() {
    $testData = [];

    $sources = ['google', 'facebook', 'instagram', 'tiktok', 'youtube', 'direct', 'referral'];
    $mediums = ['cpc', 'organic', 'social', 'email', 'referral', 'direct'];
    $campaigns = ['summer_sale', 'black_friday', 'new_year', 'spring_promo', 'brand_awareness', 'retargeting'];
    $terms = ['volvo xc90', 'volvo s60', 'купить volvo', 'вольво официальный', 'volvo цена'];
    $contents = ['banner_1', 'banner_2', 'video_ad', 'carousel', 'story'];

    // Генерировать 1000 тестовых записей
    for ($i = 1; $i <= 1000; $i++) {
        $date = date('Y-m-d H:i:s', strtotime('-' . rand(1, 60) . ' days'));

        $testData[] = [
            'email' => 'user' . $i . '@example.com',
            'phone' => '+373' . rand(60000000, 79999999),
            'created_at' => $date,
            'utm_source' => $sources[array_rand($sources)],
            'utm_medium' => $mediums[array_rand($mediums)],
            'utm_campaign' => $campaigns[array_rand($campaigns)],
            'utm_term' => rand(0, 100) > 50 ? $terms[array_rand($terms)] : null,
            'utm_content' => rand(0, 100) > 40 ? $contents[array_rand($contents)] : null,
            'amount' => round(rand(100, 50000) / 100, 2),
            'list_name' => 'Test List',
            'tag_list' => 'lead, active'
        ];
    }

    return $testData;
}

// ==========================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ==========================================

// ==========================================
// ОБРАБОТЧИКИ РУЧНЫХ РАСХОДОВ
// ==========================================

/**
 * Получить список ручных расходов
 */
function handleGetManualCosts() {
    global $logger, $userUtmTerm;

    $filters = [];

    if (!empty($_GET['date_from'])) {
        $filters['date_from'] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $filters['date_to'] = $_GET['date_to'];
    }

    // Якщо користувач має фіксований utm_term - фільтрувати тільки його витрати
    if ($userUtmTerm) {
        $filters['utm_term'] = $userUtmTerm;
    }

    try {
        $costs = AdsData::getManualCosts($filters);

        // Возвращаем пустой массив если нет данных
        if ($costs === null) {
            $costs = [];
        }

        $logger->info('Ручные расходы загружены', ['count' => count($costs)]);

        sendSuccess($costs);
    } catch (Exception $e) {
        $logger->error('Ошибка загрузки ручных расходов', ['error' => $e->getMessage()]);
        sendError('Ошибка БД: ' . $e->getMessage());
    } catch (Error $e) {
        $logger->error('PHP Error в ручных расходах', ['error' => $e->getMessage()]);
        sendError('PHP Error: ' . $e->getMessage());
    }
}

/**
 * Получить один ручной расход по ID
 */
function handleGetManualCost() {
    global $logger;

    $id = intval($_GET['id'] ?? 0);

    if (!$id) {
        sendError('ID не указан');
        return;
    }

    try {
        $cost = AdsData::getManualCostById($id);

        if ($cost) {
            sendSuccess($cost);
        } else {
            sendError('Расход не найден', 404);
        }
    } catch (Exception $e) {
        $logger->error('Ошибка загрузки расхода', ['error' => $e->getMessage()]);
        sendError('Ошибка: ' . $e->getMessage());
    }
}

/**
 * Добавить ручной расход
 */
function handleAddManualCost() {
    global $logger;

    $costDataRaw = $_POST['cost_data'] ?? null;

    if (!$costDataRaw) {
        sendError('Данные не получены');
        return;
    }

    $costData = json_decode($costDataRaw, true);

    if (!$costData) {
        sendError('Неверный формат данных');
        return;
    }

    // Валидация: хотя бы одна UTM-метка
    $hasUtm = !empty($costData['utm_source']) ||
              !empty($costData['utm_medium']) ||
              !empty($costData['utm_campaign']) ||
              !empty($costData['utm_term']) ||
              !empty($costData['utm_content']);

    if (!$hasUtm) {
        sendError('Укажите хотя бы одну UTM-метку');
        return;
    }

    // Валидация: дата и сумма
    if (empty($costData['date'])) {
        sendError('Укажите дату');
        return;
    }

    if (empty($costData['amount']) || floatval($costData['amount']) <= 0) {
        sendError('Укажите корректную сумму');
        return;
    }

    try {
        $id = AdsData::insertManualCost($costData);

        $logger->success('Ручной расход добавлен', [
            'id' => $id,
            'amount' => $costData['amount'],
            'currency' => $costData['currency'] ?? 'UAH',
            'source' => $costData['utm_source'] ?? ''
        ]);

        sendSuccess(['id' => $id], 'Расход успешно добавлен');
    } catch (Exception $e) {
        $logger->error('Ошибка добавления расхода', ['error' => $e->getMessage()]);
        sendError('Ошибка: ' . $e->getMessage());
    }
}

/**
 * Обновить ручной расход
 */
function handleUpdateManualCost() {
    global $logger;

    $costDataRaw = $_POST['cost_data'] ?? null;

    if (!$costDataRaw) {
        sendError('Данные не получены');
        return;
    }

    $costData = json_decode($costDataRaw, true);

    if (!$costData || empty($costData['id'])) {
        sendError('Неверный формат данных или отсутствует ID');
        return;
    }

    // Валидация
    $hasUtm = !empty($costData['utm_source']) ||
              !empty($costData['utm_medium']) ||
              !empty($costData['utm_campaign']) ||
              !empty($costData['utm_term']) ||
              !empty($costData['utm_content']);

    if (!$hasUtm) {
        sendError('Укажите хотя бы одну UTM-метку');
        return;
    }

    try {
        AdsData::updateManualCost($costData);

        $logger->success('Ручной расход обновлён', [
            'id' => $costData['id'],
            'amount' => $costData['amount'] ?? 0
        ]);

        sendSuccess([], 'Расход успешно обновлён');
    } catch (Exception $e) {
        $logger->error('Ошибка обновления расхода', ['error' => $e->getMessage()]);
        sendError('Ошибка: ' . $e->getMessage());
    }
}

/**
 * Удалить ручной расход
 */
function handleDeleteManualCost() {
    global $logger;

    $id = intval($_POST['id'] ?? 0);

    if (!$id) {
        sendError('ID не указан');
        return;
    }

    try {
        AdsData::deleteManualCost($id);

        $logger->success('Ручной расход удалён', ['id' => $id]);

        sendSuccess([], 'Расход успешно удалён');
    } catch (Exception $e) {
        $logger->error('Ошибка удаления расхода', ['error' => $e->getMessage()]);
        sendError('Ошибка: ' . $e->getMessage());
    }
}

/**
 * Получить данные таблицы сделок с пагинацией
 */
function handleGetDealsTable() {
    global $logger, $userUtmTerm;

    try {
        // Получить параметры фильтра
        $dateRange = $_GET['date_range'] ?? 'all';
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        // Преобразовать date_range в конкретные даты
        if ($dateRange !== 'custom' && $dateRange !== 'all') {
            $today = date('Y-m-d');
            $dateTo = $today . ' 23:59:59';

            switch ($dateRange) {
                case 'today':
                    $dateFrom = $today . ' 00:00:00';
                    break;
                case 'yesterday':
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    $dateFrom = $yesterday . ' 00:00:00';
                    $dateTo = $yesterday . ' 23:59:59';
                    break;
                case '7days':
                    $dateFrom = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
                    break;
                case '30days':
                    $dateFrom = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
                    break;
                case '60days':
                    $dateFrom = date('Y-m-d', strtotime('-60 days')) . ' 00:00:00';
                    break;
            }
        } else if ($dateRange === 'custom') {
            if ($dateFrom && strlen($dateFrom) === 10) {
                $dateFrom .= ' 00:00:00';
            }
            if ($dateTo && strlen($dateTo) === 10) {
                $dateTo .= ' 23:59:59';
            }
        }

        // Построить фильтры
        $filters = [];
        if ($dateFrom) {
            $filters['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $filters['date_to'] = $dateTo;
        }

        // UTM фильтры
        $utmSource = $_GET['utm_source'] ?? null;
        $utmMedium = $_GET['utm_medium'] ?? null;
        $utmCampaign = $_GET['utm_campaign'] ?? null;
        $utmTerm = $_GET['utm_term'] ?? null;
        if ($userUtmTerm !== null) {
            $utmTerm = $userUtmTerm;
        }
        $utmContent = $_GET['utm_content'] ?? null;

        if ($utmSource) $filters['utm_source'] = $utmSource;
        if ($utmMedium) $filters['utm_medium'] = $utmMedium;
        if ($utmCampaign) $filters['utm_campaign'] = $utmCampaign;
        if ($utmTerm) $filters['utm_term'] = $utmTerm;
        if ($utmContent) $filters['utm_content'] = $utmContent;

        // Фильтр по проекту
        $settingsFile = __DIR__ . '/../config/dashboard_settings.json';
        $defaultProject = 'BMW X5 HYBRID';
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
            $defaultProject = isset($settings['active_project']) ? $settings['active_project'] : 'BMW X5 HYBRID';
        }
        $modelFilter = $_GET['model'] ?? $defaultProject;
        if ($modelFilter && $modelFilter !== 'all') {
            $filters['model'] = $modelFilter;
        }

        // Фильтры по типу клиента и воронке
        $customerType = $_GET['customer_type'] ?? 'all';
        $funnelType = $_GET['funnel_type'] ?? 'all';
        if ($customerType && $customerType !== 'all') {
            $filters['customer_type'] = $customerType;
        }
        if ($funnelType && $funnelType !== 'all') {
            $filters['funnel_type'] = $funnelType;
        }

        // Фильтры по тарифу и платежной системе
        $tariffFilter = $_GET['tariff'] ?? 'all';
        $payProviderFilter = $_GET['pay_provider'] ?? 'all';
        if ($tariffFilter && $tariffFilter !== 'all') {
            $filters['tariff'] = $tariffFilter;
        }
        if ($payProviderFilter && $payProviderFilter !== 'all') {
            $filters['pay_provider'] = $payProviderFilter;
        }

        // Пагинация
        $page = intval($_GET['page'] ?? 1);
        $perPage = $_GET['per_page'] ?? '25';
        $perPage = ($perPage === 'all') ? 999999 : intval($perPage);
        $offset = ($page - 1) * $perPage;

        // Сортировка
        $sortField = $_GET['sort_field'] ?? 'id';
        $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

        // Получить данные из БД
        $db = Database::getInstance();
        $where = [];
        $params = [];

        // Фильтр по датам
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Фильтр по проекту - deal_project через маппинг + даты проекта
        if (!empty($filters['model']) && $filters['model'] !== 'all') {
            $project = $filters['model'];
            $projectAliases = CrmDeal::getProjectAliases($project);

            if (count($projectAliases) > 1) {
                $qPl = [];
                foreach ($projectAliases as $qi => $qAlias) {
                    $qPl[] = ":hdp_prj_{$qi}";
                    $params["hdp_prj_{$qi}"] = $qAlias;
                }
                $where[] = "deal_project IN (" . implode(', ', $qPl) . ")";
            } else {
                $where[] = "deal_project = :hdp_project";
                $params['hdp_project'] = $projectAliases[0] ?? $project;
            }

            // Фильтр по датам проекта
            $projectDates = CrmDeal::getProjectDates();
            $projectUpper = strtoupper(trim($project));
            if (isset($projectDates[$projectUpper])) {
                $where[] = "created_at >= :project_date_from";
                $where[] = "created_at <= :project_date_to";
                $params['project_date_from'] = $projectDates[$projectUpper]['date_from'] . ' 00:00:00';
                $params['project_date_to'] = $projectDates[$projectUpper]['date_to'] . ' 23:59:59';
            }
        }

        // UTM фильтры
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "LOWER($field) LIKE :filter_$field";
                $params["filter_$field"] = '%' . strtolower($filters[$field]) . '%';
            }
        }

        // Фильтр по типу клиента - через колонку customer_type
        if (!empty($filters['customer_type']) && $filters['customer_type'] !== 'all') {
            $where[] = "customer_type = :filter_customer_type";
            $params['filter_customer_type'] = $filters['customer_type'];
        }

        // Фильтр по типу воронки
        if (!empty($filters['funnel_type']) && $filters['funnel_type'] !== 'all') {
            $dealIds = CrmDeal::getDealIdsByFunnelType($filters['funnel_type']);
            if (!empty($dealIds)) {
                $escapedIds = array_map('intval', $dealIds);
                $idsList = implode(',', $escapedIds);
                $where[] = "deal_id IN ($idsList)";
            } else {
                sendSuccess([
                    'deals' => [],
                    'total' => 0,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => 0
                ]);
                return;
            }
        }

        // Фильтр по тарифу
        if (!empty($filters['tariff']) && $filters['tariff'] !== 'all') {
            $where[] = "tariff = :filter_tariff";
            $params['filter_tariff'] = $filters['tariff'];
        }

        // Фильтр по платежной системе
        if (!empty($filters['pay_provider']) && $filters['pay_provider'] !== 'all') {
            $where[] = "pay_provider = :filter_pay_provider";
            $params['filter_pay_provider'] = $filters['pay_provider'];
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Подсчет общего количества
        $countSql = "SELECT COUNT(*) as total FROM crm_deals $whereStr";
        $totalResult = $db->fetchOne($countSql, $params);
        $total = intval($totalResult['total'] ?? 0);

        // Валидация сортировки
        $allowedSortFields = [
            'id', 'deal_id', 'contact_id', 'email', 'phone', 'full_name',
            'created_at', 'deal_updated_at', 'amount', 'amount_uah', 'deal_price',
            'deal_currency', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
            'utm_content', 'deal_pipeline', 'deal_type', 'deal_status', 'is_paid',
            'is_failed', 'is_pending', 'deal_name', 'deal_step', 'model', 'comment',
            'product', 'tickets', 'tickets_count', 'list_name', 'tag_list',
            'imported_at', 'updated_at'
        ];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'id';
        }
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        // Получить данные с пагинацией
        // LIMIT и OFFSET должны быть числами, не параметрами
        $perPageInt = intval($perPage);
        $offsetInt = intval($offset);
        $sql = "SELECT * FROM crm_deals $whereStr ORDER BY $sortField $sortOrder LIMIT $perPageInt OFFSET $offsetInt";

        $deals = $db->fetchAll($sql, $params);

        $totalPages = $perPage > 0 ? ceil($total / $perPage) : 1;

        $logger->info('Данные таблицы загружены', [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'count' => count($deals)
        ]);

        sendSuccess([
            'deals' => $deals,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ]);
    } catch (Exception $e) {
        $logger->error('Ошибка загрузки таблицы сделок', ['error' => $e->getMessage()]);
        sendError('Ошибка: ' . $e->getMessage());
    }
}

// ==========================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ==========================================

/**
 * Отправить успешный ответ
 */
function sendSuccess($data = [], $message = 'Success') {
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Отправить ошибку
 */
function sendError($message, $code = 400) {
    http_response_code($code);

    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
