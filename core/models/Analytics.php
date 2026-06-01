<?php
// === Analytics.php ===
// НАЗНАЧЕНИЕ: Модель для расчета метрик аналитики на лету (без предагрегации)
// СВЯЗИ: core/Database.php, core/models/CrmDeal.php, core/models/AdsData.php
// ИСПОЛЬЗОВАНИЕ: Analytics::getBySource(), Analytics::getByMedium(), Analytics::getTotalStats()
// РАЗМЕР: ~350 строк

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/CrmDeal.php';
require_once __DIR__ . '/AdsData.php';

class Analytics {
    private static $db = null;

    /**
     * Получить экземпляр БД
     */
    private static function getDB() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    /**
     * Получить аналитику по utm_source
     *
     * @param array $filters date_from, date_to
     * @return array
     */
    public static function getBySource($filters = []) {
        $rawData = self::getByFieldWithMapping('utm_source', $filters);

        // Фильтр по типу трафика: paid / organic
        $trafficType = $filters['traffic_type'] ?? '';
        if ($trafficType === 'paid' || $trafficType === 'organic') {
            $paidSources = self::getPaidSources();

            $rawData = array_values(array_filter($rawData, function($row) use ($trafficType, $paidSources) {
                $src = strtolower(trim($row['utm_source'] ?? ''));
                $isPaid = false;
                foreach ($paidSources as $ps) {
                    if (stripos($src, $ps) !== false) {
                        $isPaid = true;
                        break;
                    }
                }
                return $trafficType === 'paid' ? $isPaid : !$isPaid;
            }));
        }

        return $rawData;
    }

    /**
     * Получить список платных источников трафика из dashboard_settings.json
     */
    private static function getPaidSources(): array {
        static $paidSources = null;
        if ($paidSources !== null) {
            return $paidSources;
        }
        $settingsFile = __DIR__ . '/../../config/dashboard_settings.json';
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
            $paidSources = $settings['paid_sources'] ?? [];
        } else {
            $paidSources = ['instagram', 'facebook', 'audience_network', 'threads', 'messenger'];
        }
        return $paidSources;
    }

    /**
     * Получить аналитику по utm_medium
     *
     * @param array $filters date_from, date_to
     * @return array
     */
    public static function getByMedium($filters = []) {
        return self::getByFieldWithMapping('utm_medium', $filters);
    }

    /**
     * Получить аналитику по utm_campaign
     *
     * @param array $filters date_from, date_to
     * @return array
     */
    public static function getByCampaign($filters = []) {
        return self::getByFieldWithMapping('utm_campaign', $filters);
    }

    /**
     * Получить аналитику по utm_term
     *
     * @param array $filters date_from, date_to
     * @return array
     */
    public static function getByTerm($filters = []) {
        return self::getByFieldWithMapping('utm_term', $filters);
    }

    /**
     * Получить аналитику по utm_content
     *
     * @param array $filters date_from, date_to
     * @return array
     */
    public static function getByContent($filters = []) {
        return self::getByFieldWithMapping('utm_content', $filters);
    }

    /**
     * Универсальный метод получения аналитики по UTM полю (на лету)
     *
     * @param string $field utm_source, utm_medium, utm_campaign, utm_term, utm_content
     * @param array $filters date_from, date_to
     * @return array
     */
    private static function getByField($field, $filters = []) {
        $allowedFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        if (!in_array($field, $allowedFields)) {
            throw new Exception("Invalid field: $field");
        }

        // Шаг 1: Получить CRM данные сгруппированные по UTM полю
        $crmData = CrmDeal::getStatsByUTMField($field, $filters);

        // Шаг 2: Підготувати фільтри для ADS (розширити utm_term якщо є mappings)
        $adsFilters = $filters;
        if (!empty($filters['utm_term'])) {
            require_once __DIR__ . '/UtmCrmAdsMapping.php';
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
                $adsFilters['utm_term_list'] = $relatedAdsValues;
                unset($adsFilters['utm_term']);
            }
        }

        // Привязать расходы к проекту по датам (пересечение с фильтром периода)
        if (!empty($filters['model']) && $filters['model'] !== 'all') {
            $projectDates = CrmDeal::getProjectDates();
            $projectKey = strtoupper(trim($filters['model']));
            if (isset($projectDates[$projectKey])) {
                $pFrom = $projectDates[$projectKey]['date_from'];
                $pTo = $projectDates[$projectKey]['date_to'];
                if (!empty($adsFilters['date_from'])) {
                    $adsFilters['date_from'] = max($adsFilters['date_from'], $pFrom);
                } else {
                    $adsFilters['date_from'] = $pFrom;
                }
                if (!empty($adsFilters['date_to'])) {
                    $adsFilters['date_to'] = min($adsFilters['date_to'], $pTo);
                } else {
                    $adsFilters['date_to'] = $pTo;
                }
            }
        }
        unset($adsFilters['model']);

        // Шаг 2: Получить рекламные данные сгруппированные по тому же полю
        $adsData = AdsData::getSpendByUTMField($field, $adsFilters);

        // Шаг 3: Индексировать рекламу по utm_value для быстрого поиска
        $adsIndex = [];
        foreach ($adsData as $row) {
            $utmValue = $row['utm_value'];
            $adsIndex[$utmValue] = $row;
        }

        // Шаг 4: Объединить данные и рассчитать метрики
        $analytics = [];
        $processedUtmValues = []; // Отслеживать обработанные UTM

        // 4.1: Обработать CRM данные (с привязкой к ADS если есть)
        foreach ($crmData as $crmRow) {
            $utmValue = $crmRow['utm_value'];
            $processedUtmValues[$utmValue] = true;

            // Найти рекламу для этого utm_value
            $adsRow = $adsIndex[$utmValue] ?? [
                'total_spend' => 0,
                'total_clicks' => 0,
                'total_impressions' => 0,
                'total_reach' => 0
            ];

            // CRM метрики
            $leads = intval($crmRow['leads']);
            $paidCount = intval($crmRow['paid_count']);
            $failedCount = intval($crmRow['failed_count']);
            $pendingCount = intval($crmRow['pending_count']);
            $paidAmount = floatval($crmRow['paid_amount']);
            $failedAmount = floatval($crmRow['failed_amount']);
            $pendingAmount = floatval($crmRow['pending_amount']);

            // Реклама метрики
            $spend = floatval($adsRow['total_spend']);
            $clicks = intval($adsRow['total_clicks']);
            $impressions = intval($adsRow['total_impressions']);
            $reach = intval($adsRow['total_reach']);

            // Рассчитать метрики
            $profit = $paidAmount - $spend;
            $roi = $spend > 0 ? (($paidAmount - $spend) / $spend) * 100 : 0;
            $roas = $spend > 0 ? $paidAmount / $spend : 0;
            $cpl = $leads > 0 ? $spend / $leads : 0;
            $cpa = $paidCount > 0 ? $spend / $paidCount : 0;
            $conversionRate = $leads > 0 ? ($paidCount / $leads) * 100 : 0;
            $cpm = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            $analytics[] = [
                $field => $utmValue,

                // Джерело даних
                'source' => 'COMMON',

                // CRM метрики
                'leads' => $leads,
                'paid_count' => $paidCount,
                'failed_count' => $failedCount,
                'pending_count' => $pendingCount,
                'paid_amount' => $paidAmount,
                'failed_amount' => $failedAmount,
                'pending_amount' => $pendingAmount,

                // Реклама метрики
                'spend' => $spend,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'reach' => $reach,

                // Рассчитанные метрики
                'profit' => $profit,
                'roi' => $roi,
                'roas' => $roas,
                'cpl' => $cpl,
                'cpa' => $cpa,
                'conversion_rate' => $conversionRate,
                'cpm' => $cpm,
                'ctr' => $ctr,

                // Тип данных (для категоризации)
                'data_type' => self::determineDataType($leads, $spend)
            ];
        }

        // 4.2: Добавить источники которые есть только в ADS (без лидов)
        foreach ($adsData as $adsRow) {
            $utmValue = $adsRow['utm_value'];

            // Пропустить если уже обработали с CRM
            if (isset($processedUtmValues[$utmValue])) {
                continue;
            }

            // Реклама метрики
            $spend = floatval($adsRow['total_spend']);
            $clicks = intval($adsRow['total_clicks']);
            $impressions = intval($adsRow['total_impressions']);
            $reach = intval($adsRow['total_reach']);

            // Рассчитать метрики (без лидов)
            $profit = 0 - $spend; // Убыток (потратили без результата)
            $roi = -100; // -100% (полный убыток)
            $roas = 0;
            $cpl = 0;
            $cpa = 0;
            $conversionRate = 0;
            $cpm = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            $analytics[] = [
                $field => $utmValue,

                // Джерело даних
                'source' => 'ADS',

                // CRM метрики (все нули)
                'leads' => 0,
                'paid_count' => 0,
                'failed_count' => 0,
                'pending_count' => 0,
                'paid_amount' => 0,
                'failed_amount' => 0,
                'pending_amount' => 0,

                // Реклама метрики
                'spend' => $spend,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'reach' => $reach,

                // Рассчитанные метрики
                'profit' => $profit,
                'roi' => $roi,
                'roas' => $roas,
                'cpl' => $cpl,
                'cpa' => $cpa,
                'conversion_rate' => $conversionRate,
                'cpm' => $cpm,
                'ctr' => $ctr,

                // Тип данных (для категоризации)
                'data_type' => self::determineDataType(0, $spend) // leads=0, spend>0 → ads_only
            ];
        }

        // Сортировать: сначала по paid_amount (убывание), потом по spend (убывание)
        usort($analytics, function($a, $b) {
            // Сначала сравнить по paid_amount
            $cmp = $b['paid_amount'] <=> $a['paid_amount'];
            if ($cmp !== 0) {
                return $cmp;
            }
            // Если одинаковые paid_amount, сравнить по spend
            return $b['spend'] <=> $a['spend'];
        });

        return $analytics;
    }

    /**
     * Получить детальную аналитику по нескольким UTM полям одновременно
     *
     * @param array $fields Массив полей ['utm_source', 'utm_medium', 'utm_campaign']
     * @param array $filters date_from, date_to
     * @return array
     */
    public static function getByMultipleFields($fields, $filters = []) {
        $allowedFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

        // Проверить валидность полей
        foreach ($fields as $field) {
            if (!in_array($field, $allowedFields)) {
                throw new Exception("Invalid field: $field");
            }
        }

        $db = self::getDB();

        // Построить WHERE для фильтрации по датам
        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Построить GROUP BY
        $fieldsStr = implode(', ', $fields);

        // Запрос CRM данных
        $sqlCrm = "SELECT
            $fieldsStr,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount,
            SUM(CASE WHEN is_pending = 1 THEN amount_uah ELSE 0 END) as pending_amount
        FROM crm_deals
        $whereStr
        GROUP BY $fieldsStr
        ORDER BY paid_amount DESC";

        $crmData = $db->fetchAll($sqlCrm, $params);

        // Построить WHERE для рекламы
        $whereAds = [];
        $paramsAds = [];

        if (!empty($filters['date_from'])) {
            $whereAds[] = "date_start >= :date_from";
            $paramsAds['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $whereAds[] = "date_start <= :date_to";
            $paramsAds['date_to'] = $filters['date_to'];
        }

        $whereAdsStr = !empty($whereAds) ? 'WHERE ' . implode(' AND ', $whereAds) : '';

        // Запрос рекламных данных
        $sqlAds = "SELECT
            $fieldsStr,
            SUM(spend) as total_spend,
            SUM(clicks) as total_clicks,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach
        FROM ads_data
        $whereAdsStr
        GROUP BY $fieldsStr";

        $adsData = $db->fetchAll($sqlAds, $paramsAds);

        // Индексировать рекламу
        $adsIndex = [];
        foreach ($adsData as $row) {
            $key = implode('|', array_map(function($field) use ($row) {
                return $row[$field] ?? '';
            }, $fields));
            $adsIndex[$key] = $row;
        }

        // Объединить данные
        $analytics = [];

        foreach ($crmData as $crmRow) {
            $key = implode('|', array_map(function($field) use ($crmRow) {
                return $crmRow[$field] ?? '';
            }, $fields));

            $adsRow = $adsIndex[$key] ?? [
                'total_spend' => 0,
                'total_clicks' => 0,
                'total_impressions' => 0,
                'total_reach' => 0
            ];

            $leads = intval($crmRow['leads']);
            $paidCount = intval($crmRow['paid_count']);
            $paidAmount = floatval($crmRow['paid_amount']);
            $spend = floatval($adsRow['total_spend']);

            $profit = $paidAmount - $spend;
            $roi = $spend > 0 ? (($paidAmount - $spend) / $spend) * 100 : 0;
            $roas = $spend > 0 ? $paidAmount / $spend : 0;
            $cpl = $leads > 0 ? $spend / $leads : 0;
            $cpa = $paidCount > 0 ? $spend / $paidCount : 0;
            $conversionRate = $leads > 0 ? ($paidCount / $leads) * 100 : 0;

            $row = [];
            foreach ($fields as $field) {
                $row[$field] = $crmRow[$field] ?? '';
            }

            $row['leads'] = $leads;
            $row['paid_count'] = $paidCount;
            $row['failed_count'] = intval($crmRow['failed_count']);
            $row['pending_count'] = intval($crmRow['pending_count']);
            $row['paid_amount'] = $paidAmount;
            $row['failed_amount'] = floatval($crmRow['failed_amount']);
            $row['pending_amount'] = floatval($crmRow['pending_amount']);
            $row['spend'] = $spend;
            $row['clicks'] = intval($adsRow['total_clicks']);
            $row['impressions'] = intval($adsRow['total_impressions']);
            $row['reach'] = intval($adsRow['total_reach']);
            $row['profit'] = $profit;
            $row['roi'] = $roi;
            $row['roas'] = $roas;
            $row['cpl'] = $cpl;
            $row['cpa'] = $cpa;
            $row['conversion_rate'] = $conversionRate;

            $analytics[] = $row;
        }

        return $analytics;
    }

    /**
     * Получить общую статистику (на лету)
     *
     * @param array $filters date_from, date_to
     * @return array
     */
    public static function getTotalStats($filters = []) {
        // Якщо є фільтр по utm_term - розширити на пов'язані ADS значення
        $adsFilters = $filters;

        if (!empty($filters['utm_term'])) {
            require_once __DIR__ . '/UtmCrmAdsMapping.php';
            $mappings = UtmCrmAdsMapping::getMappingsByField('utm_term');

            $searchTerm = strtolower(trim($filters['utm_term']));
            $relatedAdsValues = [];

            foreach ($mappings as $map) {
                $crmValue = strtolower(trim($map['crm_value']));
                // Тільки якщо crmValue МІСТИТЬ searchTerm
                if (strpos($crmValue, $searchTerm) !== false) {
                    $relatedAdsValues[] = strtolower(trim($map['ads_value']));
                }
            }

            // Для ADS stats використовувати пов'язані значення
            if (!empty($relatedAdsValues)) {
                // Замінити utm_term фільтр на список пов'язаних ADS значень
                $adsFilters['utm_term_list'] = $relatedAdsValues;
                unset($adsFilters['utm_term']);
            }
        }

        $crmStats = CrmDeal::getStats($filters);

        // Привязать расходы к проекту по датам (пересечение с фильтром периода)
        if (!empty($filters['model']) && $filters['model'] !== 'all') {
            $projectDates = CrmDeal::getProjectDates();
            $projectKey = strtoupper(trim($filters['model']));
            if (isset($projectDates[$projectKey])) {
                $pFrom = $projectDates[$projectKey]['date_from'];
                $pTo = $projectDates[$projectKey]['date_to'];
                // Пересечение: берем максимум из date_from и минимум из date_to
                if (!empty($adsFilters['date_from'])) {
                    $adsFilters['date_from'] = max($adsFilters['date_from'], $pFrom);
                } else {
                    $adsFilters['date_from'] = $pFrom;
                }
                if (!empty($adsFilters['date_to'])) {
                    $adsFilters['date_to'] = min($adsFilters['date_to'], $pTo);
                } else {
                    $adsFilters['date_to'] = $pTo;
                }
            }
        }
        // Убрать model из ads фильтров (ads_data не имеет deal_project)
        unset($adsFilters['model']);

        $adsStats = AdsData::getTotalStats($adsFilters);

        $paidAmount = floatval($crmStats['paid_amount'] ?? 0);
        $spend = floatval($adsStats['total_spend'] ?? 0);
        $leads = intval($crmStats['total_leads'] ?? 0);
        $paidCount = intval($crmStats['paid_count'] ?? 0);
        $uniqueBuyers = intval($crmStats['unique_buyers'] ?? 0);
        $repeatBuyersPct = ($paidCount > 0 && $uniqueBuyers > 0) ? round((1 - $uniqueBuyers / $paidCount) * 100, 1) : null;
        $clicks = intval($adsStats['total_clicks'] ?? 0);
        $impressions = intval($adsStats['total_impressions'] ?? 0);

        $profit = $paidAmount - $spend;
        $roi = $spend > 0 ? (($paidAmount - $spend) / $spend) * 100 : 0;
        $roas = $spend > 0 ? $paidAmount / $spend : 0;
        $cpl = $leads > 0 ? $spend / $leads : 0;
        $cpa = $paidCount > 0 ? $spend / $paidCount : 0;
        $conversionRate = $leads > 0 ? ($paidCount / $leads) * 100 : 0;
        $cpm = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;
        $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

        return [
            // CRM метрики
            'total_leads' => $leads,
            'paid_count' => $paidCount,
            'failed_count' => intval($crmStats['failed_count'] ?? 0),
            'pending_count' => intval($crmStats['pending_count'] ?? 0),
            'paid_amount' => $paidAmount,
            'failed_amount' => floatval($crmStats['failed_amount'] ?? 0),
            'pending_amount' => floatval($crmStats['pending_amount'] ?? 0),

            // Реклама метрики
            'total_spend' => $spend,
            'total_clicks' => $clicks,
            'total_impressions' => $impressions,
            'total_reach' => intval($adsStats['total_reach'] ?? 0),

            // Рассчитанные метрики
            'total_profit' => $profit,
            'avg_roi' => $roi,
            'avg_roas' => $roas,
            'avg_cpl' => $cpl,
            'avg_cpa' => $cpa,
            'conversion_rate' => $conversionRate,
            'avg_cpm' => $cpm,
            'avg_ctr' => $ctr,
            'avg_amount' => $paidCount > 0 ? $paidAmount / $paidCount : 0,
            'unique_buyers' => $uniqueBuyers,
            'repeat_buyers_pct' => $repeatBuyersPct
        ];
    }

    /**
     * Получить комбинации UTM меток с аналитикой
     *
     * @param array $filters date_from, date_to
     * @return array Массив комбинаций с типами: source_medium, source_campaign, medium_campaign, source_medium_campaign, full
     */
    public static function getCombinations($filters = []) {
        $db = self::getDB();
        $combinations = [];

        // Построить WHERE для CRM (created_at)
        $whereCRM = [];
        $paramsCRM = [];

        if (!empty($filters['date_from'])) {
            $whereCRM[] = 'created_at >= :date_from';
            $paramsCRM['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereCRM[] = 'created_at <= :date_to';
            $paramsCRM['date_to'] = $filters['date_to'];
        }

        // Фильтр по проекту — deal_project (точное совпадение по диапазону wc_order_id)
        // BMW: order_id 1500–16395 | Mercedes: order_id 16396+
        if (!empty($filters['model']) && $filters['model'] !== 'all') {
            $project = $filters['model']; // оригинальный регистр
            $projectUpper = strtoupper($project);
            if ($projectUpper === 'Q7') {
                $q7Aliases = CrmDeal::getProjectAliases('Q7');
                $qPl = [];
                foreach ($q7Aliases as $qi => $qAlias) {
                    $qPl[] = ":an_q7_{$qi}";
                    $paramsCRM["an_q7_{$qi}"] = $qAlias;
                }
                $whereCRM[] = "deal_project IN (" . implode(', ', $qPl) . ")";
            } else {
                $whereCRM[] = "deal_project = :an_deal_project";
                $paramsCRM['an_deal_project'] = $project;
            }
        }

        // Фільтр по utm_term (якщо є і НЕ має utm_term_include)
        if (!empty($filters['utm_term']) && empty($filters['utm_term_include'])) {
            $whereCRM[] = 'LOWER(utm_term) LIKE :filter_utm_term_crm';
            $paramsCRM['filter_utm_term_crm'] = '%' . strtolower($filters['utm_term']) . '%';
        } elseif (!empty($filters['utm_term_include'])) {
            // Якщо є utm_term_include - фільтрувати CRM по всіх можливих значеннях
            $crmTermConditions = [];
            foreach ($filters['utm_term_include'] as $index => $termValue) {
                $paramKey = "utm_term_crm_$index";
                $crmTermConditions[] = "LOWER(utm_term) LIKE :$paramKey";
                $paramsCRM[$paramKey] = '%' . strtolower($termValue) . '%';
            }
            if (!empty($crmTermConditions)) {
                $whereCRM[] = '(' . implode(' OR ', $crmTermConditions) . ')';
            }
        }

        $whereStrCRM = !empty($whereCRM) ? 'WHERE ' . implode(' AND ', $whereCRM) : '';

        // Построить WHERE для ADS (date_start)
        $whereADS = [];
        $paramsADS = [];

        if (!empty($filters['date_from'])) {
            $whereADS[] = 'date_start >= :date_from';
            $paramsADS['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereADS[] = 'date_start <= :date_to';
            $paramsADS['date_to'] = $filters['date_to'];
        }

        // Додати фільтр по utm_term_include (для mappings)
        if (!empty($filters['utm_term_include'])) {
            $termConditions = [];
            foreach ($filters['utm_term_include'] as $index => $termValue) {
                $paramKey = "utm_term_incl_$index";
                $termConditions[] = "LOWER(utm_term) LIKE :$paramKey";
                $paramsADS[$paramKey] = '%' . strtolower($termValue) . '%';
            }
            if (!empty($termConditions)) {
                $whereADS[] = '(' . implode(' OR ', $termConditions) . ')';
            }
        }

        $whereStrADS = !empty($whereADS) ? 'WHERE ' . implode(' AND ', $whereADS) : '';

        // 1. Source + Medium
        $sql = "SELECT
            CONCAT(COALESCE(utm_source, '—'), ' + ', COALESCE(utm_medium, '—')) as combination_key,
            utm_source,
            utm_medium,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount
        FROM crm_deals
        $whereStrCRM
        GROUP BY utm_source, utm_medium
        HAVING leads > 0
        ORDER BY paid_amount DESC";

        $results = $db->fetchAll($sql, $paramsCRM);

        // Получить расходы на рекламу для комбинаций source+medium
        $adsSpendIndex = [];
        $adsSpendSql = "SELECT
            CONCAT(COALESCE(utm_source, '—'), ' + ', COALESCE(utm_medium, '—')) as combination_key,
            SUM(spend) as total_spend
        FROM ads_data
        $whereStrADS
        GROUP BY utm_source, utm_medium";
        $adsResults = $db->fetchAll($adsSpendSql, $paramsADS);
        foreach ($adsResults as $row) {
            $adsSpendIndex[$row['combination_key']] = floatval($row['total_spend']);
        }

        foreach ($results as $row) {
            $key = $row['combination_key'];
            $adsSpend = $adsSpendIndex[$key] ?? 0;

            $combinations[$key] = [
                'type' => 'source_medium',
                'leads' => intval($row['leads']),
                'paid' => intval($row['paid_count']),
                'failed' => intval($row['failed_count']),
                'pending' => intval($row['pending_count']),
                'paid_amount' => floatval($row['paid_amount']),
                'failed_amount' => floatval($row['failed_amount']),
                'total_amount' => floatval($row['paid_amount']),
                'ads_spend' => $adsSpend
            ];
        }

        // 2. Source + Campaign
        $sql = "SELECT
            CONCAT(COALESCE(utm_source, '—'), ' + ', COALESCE(utm_campaign, '—')) as combination_key,
            utm_source,
            utm_campaign,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount
        FROM crm_deals
        $whereStrCRM
        GROUP BY utm_source, utm_campaign
        HAVING leads > 0
        ORDER BY paid_amount DESC";

        $results = $db->fetchAll($sql, $paramsCRM);

        $adsSpendIndex = [];
        $adsSpendSql = "SELECT
            CONCAT(COALESCE(utm_source, '—'), ' + ', COALESCE(utm_campaign, '—')) as combination_key,
            SUM(spend) as total_spend
        FROM ads_data
        $whereStrADS
        GROUP BY utm_source, utm_campaign";
        $adsResults = $db->fetchAll($adsSpendSql, $paramsADS);
        foreach ($adsResults as $row) {
            $adsSpendIndex[$row['combination_key']] = floatval($row['total_spend']);
        }

        foreach ($results as $row) {
            $key = $row['combination_key'];
            $adsSpend = $adsSpendIndex[$key] ?? 0;

            $combinations[$key] = [
                'type' => 'source_campaign',
                'leads' => intval($row['leads']),
                'paid' => intval($row['paid_count']),
                'failed' => intval($row['failed_count']),
                'pending' => intval($row['pending_count']),
                'paid_amount' => floatval($row['paid_amount']),
                'failed_amount' => floatval($row['failed_amount']),
                'total_amount' => floatval($row['paid_amount']),
                'ads_spend' => $adsSpend
            ];
        }

        // 3. Medium + Campaign
        $sql = "SELECT
            CONCAT(COALESCE(utm_medium, '—'), ' + ', COALESCE(utm_campaign, '—')) as combination_key,
            utm_medium,
            utm_campaign,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount
        FROM crm_deals
        $whereStrCRM
        GROUP BY utm_medium, utm_campaign
        HAVING leads > 0
        ORDER BY paid_amount DESC";

        $results = $db->fetchAll($sql, $paramsCRM);

        $adsSpendIndex = [];
        $adsSpendSql = "SELECT
            CONCAT(COALESCE(utm_medium, '—'), ' + ', COALESCE(utm_campaign, '—')) as combination_key,
            SUM(spend) as total_spend
        FROM ads_data
        $whereStrADS
        GROUP BY utm_medium, utm_campaign";
        $adsResults = $db->fetchAll($adsSpendSql, $paramsADS);
        foreach ($adsResults as $row) {
            $adsSpendIndex[$row['combination_key']] = floatval($row['total_spend']);
        }

        foreach ($results as $row) {
            $key = $row['combination_key'];
            $adsSpend = $adsSpendIndex[$key] ?? 0;

            $combinations[$key] = [
                'type' => 'medium_campaign',
                'leads' => intval($row['leads']),
                'paid' => intval($row['paid_count']),
                'failed' => intval($row['failed_count']),
                'pending' => intval($row['pending_count']),
                'paid_amount' => floatval($row['paid_amount']),
                'failed_amount' => floatval($row['failed_amount']),
                'total_amount' => floatval($row['paid_amount']),
                'ads_spend' => $adsSpend
            ];
        }

        // 4. Source + Medium + Campaign
        $sql = "SELECT
            CONCAT(COALESCE(utm_source, '—'), ' + ', COALESCE(utm_medium, '—'), ' + ', COALESCE(utm_campaign, '—')) as combination_key,
            utm_source,
            utm_medium,
            utm_campaign,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount
        FROM crm_deals
        $whereStrCRM
        GROUP BY utm_source, utm_medium, utm_campaign
        HAVING leads > 0
        ORDER BY paid_amount DESC";

        $results = $db->fetchAll($sql, $paramsCRM);

        $adsSpendIndex = [];
        $adsSpendSql = "SELECT
            CONCAT(COALESCE(utm_source, '—'), ' + ', COALESCE(utm_medium, '—'), ' + ', COALESCE(utm_campaign, '—')) as combination_key,
            SUM(spend) as total_spend
        FROM ads_data
        $whereStrADS
        GROUP BY utm_source, utm_medium, utm_campaign";
        $adsResults = $db->fetchAll($adsSpendSql, $paramsADS);
        foreach ($adsResults as $row) {
            $adsSpendIndex[$row['combination_key']] = floatval($row['total_spend']);
        }

        foreach ($results as $row) {
            $key = $row['combination_key'];
            $adsSpend = $adsSpendIndex[$key] ?? 0;

            $combinations[$key] = [
                'type' => 'source_medium_campaign',
                'leads' => intval($row['leads']),
                'paid' => intval($row['paid_count']),
                'failed' => intval($row['failed_count']),
                'pending' => intval($row['pending_count']),
                'paid_amount' => floatval($row['paid_amount']),
                'failed_amount' => floatval($row['failed_amount']),
                'total_amount' => floatval($row['paid_amount']),
                'ads_spend' => $adsSpend
            ];
        }

        // 5. Full (все метки)
        $sql = "SELECT
            CONCAT(
                COALESCE(utm_source, '—'), ' / ',
                COALESCE(utm_medium, '—'), ' / ',
                COALESCE(utm_campaign, '—'), ' / ',
                COALESCE(utm_term, '—'), ' / ',
                COALESCE(utm_content, '—')
            ) as combination_key,
            utm_source,
            utm_medium,
            utm_campaign,
            utm_term,
            utm_content,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount
        FROM crm_deals
        $whereStrCRM
        GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content
        HAVING leads > 0
        ORDER BY paid_amount DESC";

        $results = $db->fetchAll($sql, $paramsCRM);

        $adsSpendIndex = [];
        $adsSpendSql = "SELECT
            CONCAT(
                COALESCE(utm_source, '—'), ' / ',
                COALESCE(utm_medium, '—'), ' / ',
                COALESCE(utm_campaign, '—'), ' / ',
                COALESCE(utm_term, '—'), ' / ',
                COALESCE(utm_content, '—')
            ) as combination_key,
            SUM(spend) as total_spend
        FROM ads_data
        $whereStrADS
        GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content";
        $adsResults = $db->fetchAll($adsSpendSql, $paramsADS);
        foreach ($adsResults as $row) {
            $adsSpendIndex[$row['combination_key']] = floatval($row['total_spend']);
        }

        foreach ($results as $row) {
            $key = $row['combination_key'];
            $adsSpend = $adsSpendIndex[$key] ?? 0;

            $combinations[$key] = [
                'type' => 'full',
                'leads' => intval($row['leads']),
                'paid' => intval($row['paid_count']),
                'failed' => intval($row['failed_count']),
                'pending' => intval($row['pending_count']),
                'paid_amount' => floatval($row['paid_amount']),
                'failed_amount' => floatval($row['failed_amount']),
                'total_amount' => floatval($row['paid_amount']),
                'ads_spend' => $adsSpend
            ];
        }

        return $combinations;
    }

    /**
     * Определить тип данных источника
     *
     * @param int $leads Количество лидов
     * @param float $spend Рекламный расход
     * @return string 'common'|'crm_only'|'ads_only'
     */
    private static function determineDataType($leads, $spend) {
        // Приведение к числовым типам для строгой проверки
        $leads = intval($leads);
        $spend = floatval($spend);

        $hasLeads = $leads > 0;
        $hasSpend = $spend > 0.01; // Учитываем небольшие расходы (больше 1 копейки)

        if ($hasLeads && $hasSpend) {
            return 'common'; // 🟢 Общие - есть и лиды и расходы
        } elseif ($hasLeads && !$hasSpend) {
            return 'crm_only'; // 🔵 CRM-only - есть лиды, нет расходов (органика)
        } elseif (!$hasLeads && $hasSpend) {
            return 'ads_only'; // 🟡 ADS-only - есть расходы, нет лидов (пока)
        } else {
            return 'unknown'; // Не должно случиться
        }
    }

    /**
     * Універсальний метод отримання аналітики з mappings
     *
     * @param string $field utm_source, utm_medium, utm_campaign, utm_term, utm_content
     * @param array $filters
     * @return array
     */
    private static function getByFieldWithMapping($field, $filters = []) {
        // Завантажити mappings
        require_once __DIR__ . '/UtmCrmAdsMapping.php';
        $mappings = UtmCrmAdsMapping::getMappingsByField($field);

        $filterKey = $field; // utm_term, utm_source, etc

        // Якщо є фільтр по цьому полю - розширити на пов'язані ADS значення
        if (!empty($filters[$filterKey]) && !empty($mappings)) {
            $searchValue = strtolower(trim($filters[$filterKey]));

            // Знайти всі ADS значення пов'язані з цим CRM значенням (LIKE)
            $relatedAdsValues = [];
            foreach ($mappings as $map) {
                $crmValue = strtolower(trim($map['crm_value']));
                // Тільки якщо crmValue МІСТИТЬ searchValue
                if (strpos($crmValue, $searchValue) !== false) {
                    $relatedAdsValues[] = strtolower(trim($map['ads_value']));
                }
            }

            // Якщо знайшли - отримати дані БЕЗ фільтру, потім відфільтрувати
            if (!empty($relatedAdsValues)) {
                $filtersWithoutField = $filters;
                unset($filtersWithoutField[$filterKey]);

                $rawData = self::getByField($field, $filtersWithoutField);

                // Відфільтрувати тільки потрібні значення
                $filteredData = [];
                foreach ($rawData as $row) {
                    $utmValue = strtolower(trim($row[$field] ?? ''));

                    // Перевірка: чи utmValue МІСТИТЬ searchValue
                    $matchesCrm = (strpos($utmValue, $searchValue) !== false);

                    // Перевірка: чи utmValue точно дорівнює одному з ADS значень
                    $matchesAds = in_array($utmValue, $relatedAdsValues);

                    if ($matchesCrm || $matchesAds) {
                        $filteredData[] = $row;
                    }
                }

                return self::mergeByCrmAdsMapping($filteredData, $mappings, $field);
            }
        }

        // Звичайний режим
        $rawData = self::getByField($field, $filters);

        if (!empty($mappings)) {
            return self::mergeByCrmAdsMapping($rawData, $mappings, $field);
        }

        return $rawData;
    }

    /**
     * Об'єднати дані CRM та ADS згідно mappings
     *
     * @param array $data Сирі дані з getByField()
     * @param array $mappings Масив mappings з UtmCrmAdsMapping::getMappingsByField()
     * @param string $field Назва UTM поля (utm_term, utm_source, etc)
     * @return array Об'єднані дані
     */
    private static function mergeByCrmAdsMapping($data, $mappings, $field) {
        $result = [];
        $processed = [];

        // Індексувати дані по utm_value та source
        $dataIndex = [];
        foreach ($data as $row) {
            $utmValue = strtolower(trim($row[$field] ?? ''));
            $source = $row['source'] ?? 'COMMON';

            if (!isset($dataIndex[$utmValue])) {
                $dataIndex[$utmValue] = [];
            }
            $dataIndex[$utmValue][$source] = $row;
        }

        // Групувати mappings по merged_name
        $groupedMappings = [];
        foreach ($mappings as $map) {
            $mergedName = $map['merged_name'];
            if (!isset($groupedMappings[$mergedName])) {
                $groupedMappings[$mergedName] = [
                    'crm_values' => [],
                    'ads_values' => []
                ];
            }
            $groupedMappings[$mergedName]['crm_values'][] = strtolower(trim($map['crm_value']));
            $groupedMappings[$mergedName]['ads_values'][] = strtolower(trim($map['ads_value']));
        }

        // Обробити згруповані mappings
        foreach ($groupedMappings as $mergedName => $group) {
            $crmValues = $group['crm_values'];
            $adsValues = $group['ads_values'];

            // Знайти CRM рядок (беремо перший з групи)
            $crmRow = null;
            foreach ($crmValues as $crmVal) {
                if (isset($dataIndex[$crmVal]['COMMON'])) {
                    $crmRow = $dataIndex[$crmVal]['COMMON'];
                    break;
                } elseif (isset($dataIndex[$crmVal]['CRM'])) {
                    $crmRow = $dataIndex[$crmVal]['CRM'];
                    break;
                }
            }

            // Об'єднати ВСІ ADS рядки для цього merged_name
            $totalSpend = 0;
            $totalClicks = 0;
            $totalImpressions = 0;
            $totalReach = 0;
            $adsCount = 0;

            foreach ($adsValues as $adsVal) {
                if (isset($dataIndex[$adsVal]['ADS'])) {
                    $adsRow = $dataIndex[$adsVal]['ADS'];
                    $totalSpend += ($adsRow['spend'] ?? 0);
                    $totalClicks += ($adsRow['clicks'] ?? 0);
                    $totalImpressions += ($adsRow['impressions'] ?? 0);
                    $totalReach += ($adsRow['reach'] ?? 0);
                    $adsCount++;
                    $processed[$adsVal] = true;
                }
            }

            if ($crmRow || $adsCount > 0) {
                // Візуальне відображення
                if ($adsCount > 1) {
                    $adsLabel = "({$adsCount} акаунти) 🟡 ADS " . implode(' + 🟡 ADS ', $adsValues);
                } else {
                    $adsLabel = "🟡 ADS " . implode(', ', $adsValues);
                }
                $displayLabel = $mergedName . ' 🔵 CRM = ' . $adsLabel;

                // Об'єднати метрики
                $leads = ($crmRow['leads'] ?? 0);
                $paidCount = ($crmRow['paid_count'] ?? 0);
                $paidAmount = ($crmRow['paid_amount'] ?? 0);
                $spend = ($crmRow['spend'] ?? 0) + $totalSpend;

                $profit = $paidAmount - $spend;
                $roi = $spend > 0 ? (($paidAmount - $spend) / $spend) * 100 : 0;
                $roas = $spend > 0 ? $paidAmount / $spend : 0;
                $cpl = $leads > 0 ? $spend / $leads : 0;
                $cpa = $paidCount > 0 ? $spend / $paidCount : 0;
                $conversionRate = $leads > 0 ? ($paidCount / $leads) * 100 : 0;

                $result[] = [
                    $field => $displayLabel,
                    'source' => 'MERGED',
                    'is_merged' => true,
                    'leads' => $leads,
                    'paid_count' => $paidCount,
                    'failed_count' => ($crmRow['failed_count'] ?? 0),
                    'pending_count' => ($crmRow['pending_count'] ?? 0),
                    'paid_amount' => $paidAmount,
                    'failed_amount' => ($crmRow['failed_amount'] ?? 0),
                    'pending_amount' => ($crmRow['pending_amount'] ?? 0),
                    'spend' => $spend,
                    'clicks' => ($crmRow['clicks'] ?? 0) + $totalClicks,
                    'impressions' => ($crmRow['impressions'] ?? 0) + $totalImpressions,
                    'reach' => ($crmRow['reach'] ?? 0) + $totalReach,
                    'profit' => $profit,
                    'roi' => $roi,
                    'roas' => $roas,
                    'cpl' => $cpl,
                    'cpa' => $cpa,
                    'conversion_rate' => $conversionRate,
                    'cpm' => ($crmRow['cpm'] ?? 0),
                    'ctr' => ($crmRow['ctr'] ?? 0),
                    'data_type' => self::determineDataType($leads, $spend)
                ];

                // Позначити CRM значення як оброблені
                foreach ($crmValues as $crmVal) {
                    $processed[$crmVal] = true;
                }
            }
        }

        // Додати незіставлені мітки
        foreach ($data as $row) {
            $utmValue = strtolower(trim($row[$field] ?? ''));

            if (!isset($processed[$utmValue])) {
                $source = $row['source'] ?? 'COMMON';
                if ($source === 'COMMON') {
                    $row[$field] = $row[$field] . ' 🟢';
                } elseif ($source === 'ADS') {
                    $row[$field] = $row[$field] . ' 🟡';
                }
                $row['is_merged'] = false;
                $result[] = $row;
            }
        }

        // Сортувати: merged спочатку, потім по paid_amount
        usort($result, function($a, $b) {
            if (($a['is_merged'] ?? false) && !($b['is_merged'] ?? false)) return -1;
            if (!($a['is_merged'] ?? false) && ($b['is_merged'] ?? false)) return 1;
            return $b['paid_amount'] <=> $a['paid_amount'];
        });

        return $result;
    }
}
