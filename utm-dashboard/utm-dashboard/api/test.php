<?php
// === test.php ===
// API для получения аналитики из MySQL с фильтрацией
// Возвращает: общая статистика + данные по каждой UTM метке
// Расчет метрик на лету через Analytics::getBySource/Medium/Campaign/Term/Content()

// Отключить вывод ошибок в браузер (только для JSON ответов)
// ВАЖНО: Должно быть ДО require_once, чтобы перехватить все ошибки
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Начать буферизацию вывода чтобы перехватить любые предупреждения
ob_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/Analytics.php';
require_once __DIR__ . '/../core/models/CrmDeal.php';
require_once __DIR__ . '/../core/models/AdsData.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Session.php';

// API також потребує авторизації
Auth::checkAccess();

// Отримати utm_term користувача
$userUtmTerm = Auth::getUserUtmTerm();

try {
    // Получить параметры фильтра
    $dateRange = $_GET['date_range'] ?? '30days';
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;

    // Преобразовать date_range в конкретные даты
    if ($dateRange !== 'custom') {
        $today = date('Y-m-d');
        $dateTo = $today . ' 23:59:59'; // Включить весь день до конца

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
            case 'all':
                $dateFrom = null;
                $dateTo = null;
                break;
        }
    } else {
        // Для custom фильтра добавляем время если его нет
        if ($dateFrom && strlen($dateFrom) === 10) { // Формат YYYY-MM-DD
            $dateFrom .= ' 00:00:00';
        }
        if ($dateTo && strlen($dateTo) === 10) { // Формат YYYY-MM-DD
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

    // UTM term - для не-адмінів завжди фіксований
    $utmTerm = $_GET['utm_term'] ?? null;
    if ($userUtmTerm !== null) {
        // Якщо користувач має фіксований utm_term (таргетолог або гість)
        $utmTerm = $userUtmTerm;
    }

    $utmContent = $_GET['utm_content'] ?? null;

    if ($utmSource) $filters['utm_source'] = $utmSource;
    if ($utmMedium) $filters['utm_medium'] = $utmMedium;
    if ($utmCampaign) $filters['utm_campaign'] = $utmCampaign;
    if ($utmTerm) $filters['utm_term'] = $utmTerm;
    if ($utmContent) $filters['utm_content'] = $utmContent;

    // Фільтр по проекту (model) - завантажити з конфігурації
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

    // Розширити utm_term фільтр на пов'язані ADS значення для getCombinations()
    $filtersForCombinations = $filters;
    if (!empty($filters['utm_term'])) {
        require_once __DIR__ . '/../core/models/UtmCrmAdsMapping.php';
        $mappings = UtmCrmAdsMapping::getMappingsByField('utm_term');

        $searchTerm = strtolower(trim($filters['utm_term']));
        $relatedAdsValues = [];

        foreach ($mappings as $map) {
            $crmValue = strtolower(trim($map['crm_value']));
            if (strpos($crmValue, $searchTerm) !== false) {
                $relatedAdsValues[] = strtolower(trim($map['ads_value']));
            }
        }

        if (!empty($relatedAdsValues)) {
            // Замінити utm_term на utm_term_include для getCombinations
            unset($filtersForCombinations['utm_term']);
            $filtersForCombinations['utm_term_include'] = array_merge([$searchTerm], $relatedAdsValues);
        }
    }

    // Получить общую статистику
    $totalStats = Analytics::getTotalStats($filters);

    // Получить аналитику по каждой UTM метке
    $sourceAnalytics = Analytics::getBySource($filters);
    $mediumAnalytics = Analytics::getByMedium($filters);
    $campaignAnalytics = Analytics::getByCampaign($filters);
    $termAnalytics = Analytics::getByTerm($filters);
    $contentAnalytics = Analytics::getByContent($filters);

    // Получить комбинации UTM меток (з розширеним фільтром utm_term)
    $db = Database::getInstance();
    $combinationsData = Analytics::getCombinations($filtersForCombinations);

    // Получить данные по датам для графиков

    // Определить детализацию: если период 1 день - группировать по часам, иначе по дням
    $isOneDay = ($dateRange === 'today' || $dateRange === 'yesterday');

    if ($isOneDay) {
        // Группировка по часам для периода 1 день
        $byDateQuery = "SELECT
            DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as date,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as revenue
        FROM crm_deals";
    } else {
        // Группировка по дням для остальных периодов
        $byDateQuery = "SELECT
            DATE(created_at) as date,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as revenue
        FROM crm_deals";
    }

    $whereConditions = [];
    $params = [];

    if (!empty($filters['date_from'])) {
        $whereConditions[] = "created_at >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $whereConditions[] = "created_at <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    // Фильтр по проекту - deal_project через маппинг + даты проекта
    if (!empty($filters['model']) && $filters['model'] !== 'all') {
        $project = $filters['model'];
        $projectAliases = CrmDeal::getProjectAliases($project);

        if (count($projectAliases) > 1) {
            $qPl = [];
            foreach ($projectAliases as $qi => $qAlias) {
                $qPl[] = ":tp_prj_{$qi}";
                $params["tp_prj_{$qi}"] = $qAlias;
            }
            $whereConditions[] = "deal_project IN (" . implode(', ', $qPl) . ")";
        } else {
            $whereConditions[] = "deal_project = :tp_deal_project";
            $params['tp_deal_project'] = $projectAliases[0] ?? $project;
        }

        // Даты проекта
        $projectDates = CrmDeal::getProjectDates();
        $projectUpper = strtoupper(trim($project));
        if (isset($projectDates[$projectUpper])) {
            $whereConditions[] = "created_at >= :tp_project_date_from";
            $whereConditions[] = "created_at <= :tp_project_date_to";
            $params['tp_project_date_from'] = $projectDates[$projectUpper]['date_from'] . ' 00:00:00';
            $params['tp_project_date_to'] = $projectDates[$projectUpper]['date_to'] . ' 23:59:59';
        }
    }

    // Фильтр по тарифу для графиков
    if (!empty($filters['tariff']) && $filters['tariff'] !== 'all') {
        $whereConditions[] = "tariff = :tp_tariff";
        $params['tp_tariff'] = $filters['tariff'];
    }

    // Фильтр по платежной системе для графиков
    if (!empty($filters['pay_provider']) && $filters['pay_provider'] !== 'all') {
        $whereConditions[] = "pay_provider = :tp_pay_provider";
        $params['tp_pay_provider'] = $filters['pay_provider'];
    }

    // Фильтр по типу клиента для графиков - через колонку customer_type
    if (!empty($filters['customer_type']) && $filters['customer_type'] !== 'all') {
        $whereConditions[] = "customer_type = :tp_customer_type";
        $params['tp_customer_type'] = $filters['customer_type'];
    }

    if (!empty($whereConditions)) {
        $byDateQuery .= " WHERE " . implode(' AND ', $whereConditions);
    }

    if ($isOneDay) {
        $byDateQuery .= " GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') ORDER BY date ASC";
    } else {
        $byDateQuery .= " GROUP BY DATE(created_at) ORDER BY date ASC";
    }

    try {
        $byDateResults = $db->fetchAll($byDateQuery, $params);
    } catch (Exception $e) {
        // Если ошибка - вернуть пустой массив
        error_log("Error fetching by_date data: " . $e->getMessage());
        $byDateResults = [];
    }

    // Преобразовать в формат: {"2025-11-24": {leads: 15, revenue: 2980}, ...}
    $byDate = [];
    if ($byDateResults) {
        foreach ($byDateResults as $row) {
            $byDate[$row['date']] = [
                'leads' => (int)$row['leads'],
                'revenue' => (float)$row['revenue']
            ];
        }
    }

    // Преобразовать аналитику в удобный формат для фронтенда
    // Источники: счетчик
    $sources = [];
    foreach ($sourceAnalytics as $row) {
        $utm = $row['utm_source'];
        $sources[$utm] = $row['leads'];
    }

    // Medium: счетчик
    $medium = [];
    foreach ($mediumAnalytics as $row) {
        $utm = $row['utm_medium'];
        $medium[$utm] = $row['leads'];
    }

    // Campaigns: счетчик
    $campaigns = [];
    foreach ($campaignAnalytics as $row) {
        $utm = $row['utm_campaign'];
        $campaigns[$utm] = $row['leads'];
    }

    // Terms: счетчик
    $terms = [];
    foreach ($termAnalytics as $row) {
        $utm = $row['utm_term'];
        $terms[$utm] = $row['leads'];
    }

    // Content: счетчик
    $content = [];
    foreach ($contentAnalytics as $row) {
        $utm = $row['utm_content'];
        $content[$utm] = $row['leads'];
    }

    // Суммы по источникам (только оплаченные)
    $amountBySource = [];
    foreach ($sourceAnalytics as $row) {
        $utm = $row['utm_source'];
        $amountBySource[$utm] = $row['paid_amount'];
    }

    $amountByMedium = [];
    foreach ($mediumAnalytics as $row) {
        $utm = $row['utm_medium'];
        $amountByMedium[$utm] = $row['paid_amount'];
    }

    $amountByCampaign = [];
    foreach ($campaignAnalytics as $row) {
        $utm = $row['utm_campaign'];
        $amountByCampaign[$utm] = $row['paid_amount'];
    }

    $amountByTerm = [];
    foreach ($termAnalytics as $row) {
        $utm = $row['utm_term'];
        $amountByTerm[$utm] = $row['paid_amount'];
    }

    $amountByContent = [];
    foreach ($contentAnalytics as $row) {
        $utm = $row['utm_content'];
        $amountByContent[$utm] = $row['paid_amount'];
    }

    // Детальная аналитика (с метриками ROI, ROAS, CPL, CPA)
    $sourcesAnalytics = [];
    foreach ($sourceAnalytics as $row) {
        $utm = $row['utm_source'];
        $sourcesAnalytics[$utm] = [
            'leads' => $row['leads'],
            'paid' => $row['paid_count'],
            'failed' => $row['failed_count'],
            'pending' => $row['pending_count'],
            'paid_amount' => $row['paid_amount'],
            'failed_amount' => $row['failed_amount'],
            'pending_amount' => $row['pending_amount'],
            'total_amount' => $row['paid_amount'] + $row['failed_amount'] + $row['pending_amount'],
            'ads_spend' => $row['spend'],
            'roi' => $row['roi'],
            'roas' => $row['roas'],
            'cpl' => $row['cpl'],
            'cpa' => $row['cpa'],
            'profit' => $row['profit'],
            'conversion_rate' => $row['conversion_rate'],
            'cpm' => $row['cpm'],
            'ctr' => $row['ctr'],
            'clicks' => $row['clicks'],
            'impressions' => $row['impressions'],
            'reach' => $row['reach'],
            'data_type' => $row['data_type'] ?? 'common'
        ];
    }

    $mediumAnalyticsFormatted = [];
    foreach ($mediumAnalytics as $row) {
        $utm = $row['utm_medium'];
        $mediumAnalyticsFormatted[$utm] = [
            'leads' => $row['leads'],
            'paid' => $row['paid_count'],
            'failed' => $row['failed_count'],
            'pending' => $row['pending_count'],
            'paid_amount' => $row['paid_amount'],
            'failed_amount' => $row['failed_amount'],
            'pending_amount' => $row['pending_amount'],
            'total_amount' => $row['paid_amount'] + $row['failed_amount'] + $row['pending_amount'],
            'ads_spend' => $row['spend'],
            'roi' => $row['roi'],
            'roas' => $row['roas'],
            'cpl' => $row['cpl'],
            'cpa' => $row['cpa'],
            'profit' => $row['profit'],
            'conversion_rate' => $row['conversion_rate'],
            'data_type' => $row['data_type'] ?? 'common'
        ];
    }

    $campaignsAnalyticsFormatted = [];
    foreach ($campaignAnalytics as $row) {
        $utm = $row['utm_campaign'];
        $campaignsAnalyticsFormatted[$utm] = [
            'leads' => $row['leads'],
            'paid' => $row['paid_count'],
            'failed' => $row['failed_count'],
            'pending' => $row['pending_count'],
            'paid_amount' => $row['paid_amount'],
            'failed_amount' => $row['failed_amount'],
            'pending_amount' => $row['pending_amount'],
            'total_amount' => $row['paid_amount'] + $row['failed_amount'] + $row['pending_amount'],
            'ads_spend' => $row['spend'],
            'roi' => $row['roi'],
            'roas' => $row['roas'],
            'cpl' => $row['cpl'],
            'cpa' => $row['cpa'],
            'profit' => $row['profit'],
            'conversion_rate' => $row['conversion_rate'],
            'data_type' => $row['data_type'] ?? 'common'
        ];
    }

    $termsAnalyticsFormatted = [];
    foreach ($termAnalytics as $row) {
        $utm = $row['utm_term'];
        $termsAnalyticsFormatted[$utm] = [
            'leads' => $row['leads'],
            'paid' => $row['paid_count'],
            'failed' => $row['failed_count'],
            'pending' => $row['pending_count'],
            'paid_amount' => $row['paid_amount'],
            'failed_amount' => $row['failed_amount'],
            'pending_amount' => $row['pending_amount'],
            'total_amount' => $row['paid_amount'] + $row['failed_amount'] + $row['pending_amount'],
            'ads_spend' => $row['spend'],
            'roi' => $row['roi'],
            'roas' => $row['roas'],
            'cpl' => $row['cpl'],
            'cpa' => $row['cpa'],
            'profit' => $row['profit'],
            'conversion_rate' => $row['conversion_rate'],
            'data_type' => $row['data_type'] ?? 'common'
        ];
    }

    $contentAnalyticsFormatted = [];
    foreach ($contentAnalytics as $row) {
        $utm = $row['utm_content'];
        $contentAnalyticsFormatted[$utm] = [
            'leads' => $row['leads'],
            'paid' => $row['paid_count'],
            'failed' => $row['failed_count'],
            'pending' => $row['pending_count'],
            'paid_amount' => $row['paid_amount'],
            'failed_amount' => $row['failed_amount'],
            'pending_amount' => $row['pending_amount'],
            'total_amount' => $row['paid_amount'] + $row['failed_amount'] + $row['pending_amount'],
            'ads_spend' => $row['spend'],
            'roi' => $row['roi'],
            'roas' => $row['roas'],
            'cpl' => $row['cpl'],
            'cpa' => $row['cpa'],
            'profit' => $row['profit'],
            'conversion_rate' => $row['conversion_rate'],
            'data_type' => $row['data_type'] ?? 'common'
        ];
    }

    // Рассчитать тренды (опционально, пока заглушка)
    $trends = [];

    // Построить ответ в формате совместимом со старым API
    $response = [
        'success' => true,
        'stats' => [
            // Общая статистика
            'total_leads' => $totalStats['total_leads'],
            'paid_count' => $totalStats['paid_count'],
            'failed_count' => $totalStats['failed_count'],
            'pending_count' => $totalStats['pending_count'],
            'leads_count' => $totalStats['total_leads'] - $totalStats['paid_count'] - $totalStats['failed_count'] - $totalStats['pending_count'],
            'paid_amount' => $totalStats['paid_amount'],
            'failed_amount' => $totalStats['failed_amount'],
            'pending_amount' => $totalStats['pending_amount'],
            'total_amount' => $totalStats['paid_amount'], // Только оплаченные
            'attempts_amount' => $totalStats['failed_amount'] + $totalStats['pending_amount'],
            'avg_amount' => $totalStats['avg_amount'],
            'total_ads_spend' => $totalStats['total_spend'],
            'total_profit' => $totalStats['total_profit'],
            'total_roi' => $totalStats['avg_roi'],
            'total_roas' => $totalStats['avg_roas'],
            'avg_cpl' => $totalStats['avg_cpl'],
            'avg_cpa' => $totalStats['avg_cpa'],
            'conversion_rate' => $totalStats['conversion_rate'],
            'avg_cpm' => $totalStats['avg_cpm'],
            'avg_ctr' => $totalStats['avg_ctr'],
            'leads_with_amount' => $totalStats['paid_count'],

            // Счетчики по меткам
            'sources' => $sources,
            'medium' => $medium,
            'campaigns' => $campaigns,
            'terms' => $terms,
            'content' => $content,

            // Суммы по меткам (только оплаченные)
            'amount_by_source' => $amountBySource,
            'amount_by_medium' => $amountByMedium,
            'amount_by_campaign' => $amountByCampaign,
            'amount_by_term' => $amountByTerm,
            'amount_by_content' => $amountByContent,

            // Детальная аналитика с метриками
            'sources_analytics' => $sourcesAnalytics,
            'medium_analytics' => $mediumAnalyticsFormatted,
            'campaigns_analytics' => $campaignsAnalyticsFormatted,
            'terms_analytics' => $termsAnalyticsFormatted,
            'content_analytics' => $contentAnalyticsFormatted,

            // Тренды
            'trends' => $trends,

            // Комбинации UTM меток
            'combinations' => $combinationsData,

            // По датам для графиков
            'by_date' => $byDate
        ],
        'filters' => [
            'date_range' => $dateRange,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'model' => $modelFilter,
            'customer_type' => $customerType,
            'funnel_type' => $funnelType,
            'tariff' => $tariffFilter,
            'pay_provider' => $payProviderFilter
        ],
        'data' => [] // Детальные данные не возвращаем для производительности
    ];

    // Очистить буфер от любых предупреждений/ошибок
    ob_clean();
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // Завершить буферизацию
    ob_end_flush();

} catch (Exception $e) {
    // Очистить буфер от любых предупреждений/ошибок
    ob_clean();
    
    // Логировать ошибку
    error_log('[api/test.php] Ошибка: ' . $e->getMessage());
    error_log('[api/test.php] Trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => APP_ENV === 'local' ? $e->getTraceAsString() : null // Показывать trace только локально
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // Завершить буферизацию
    ob_end_flush();
}
