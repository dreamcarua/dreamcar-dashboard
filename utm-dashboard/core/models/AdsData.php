<?php
// === AdsData.php ===
// НАЗНАЧЕНИЕ: Модель для работы с таблицей ads_data
// СВЯЗИ: core/Database.php
// ИСПОЛЬЗОВАНИЕ: AdsData::insertFromFacebook($fbData), AdsData::getByUTM()
// РАЗМЕР: ~350 строк

require_once __DIR__ . '/../Database.php';

class AdsData {
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
     * Вставка рекламных данных из Facebook Ads
     * Преобразует поля рекламы в UTM метки
     *
     * @param array $fbData Массив данных от Facebook
     * @return int Количество вставленных записей
     */
    public static function insertFromFacebook($fbData) {
        if (empty($fbData)) {
            return 0;
        }

        $db = self::getDB();
        $inserted = 0;

        $db->beginTransaction();

        try {
            foreach ($fbData as $row) {
                // Преобразовать в формат БД
                $data = self::convertFacebookToUTM($row);

                // Вставка с ON DUPLICATE KEY UPDATE (новый синтаксис для MySQL 8.4)
                $sql = "INSERT INTO ads_data (
                    date_start, date_stop, account_id, campaign_id, adset_id, ad_id,
                    publisher_platform, platform_position,
                    account_name, campaign_name, adset_name, ad_name,
                    utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                    spend, clicks, impressions, reach, unique_clicks, cpm, ctr,
                    account_currency, buying_type, objective, optimization_goal
                ) VALUES (
                    :date_start, :date_stop, :account_id, :campaign_id, :adset_id, :ad_id,
                    :publisher_platform, :platform_position,
                    :account_name, :campaign_name, :adset_name, :ad_name,
                    :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term,
                    :spend, :clicks, :impressions, :reach, :unique_clicks, :cpm, :ctr,
                    :account_currency, :buying_type, :objective, :optimization_goal
                ) AS new_values
                ON DUPLICATE KEY UPDATE
                    spend = new_values.spend,
                    clicks = new_values.clicks,
                    impressions = new_values.impressions,
                    reach = new_values.reach,
                    unique_clicks = new_values.unique_clicks,
                    cpm = new_values.cpm,
                    ctr = new_values.ctr,
                    updated_at = CURRENT_TIMESTAMP";

                $db->execute($sql, $data);
                $inserted++;
            }

            $db->commit();

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

        return $inserted;
    }

    /**
     * Преобразовать данные Facebook в формат с UTM метками
     */
    private static function convertFacebookToUTM($fbRow) {
        // Преобразовать дату (ISO 8601 → DATE)
        // Обработать как date_start так и date (для совместимости)
        $dateStartRaw = $fbRow['date_start'] ?? $fbRow['date'] ?? date('Y-m-d');
        $dateStopRaw = $fbRow['date_stop'] ?? $fbRow['date'] ?? date('Y-m-d');

        $dateStart = date('Y-m-d', strtotime($dateStartRaw));
        $dateStop = date('Y-m-d', strtotime($dateStopRaw));

        // Преобразовать в UTM метки согласно маппингу:
        // utm_source = publisher_platform (facebook/instagram)
        // utm_medium = platform_position (feed/stories/reels)
        // utm_campaign = campaign_name
        // utm_content = adset_name + "_" + ad_name
        // utm_term = account_name

        $utmSource = strtolower(trim($fbRow['publisher_platform'] ?? ''));
        $utmMedium = strtolower(trim($fbRow['platform_position'] ?? ''));
        $utmCampaign = strtolower(trim($fbRow['campaign_name'] ?? ''));
        $utmContent = strtolower(trim($fbRow['adset_name'] ?? '')) . '_' .
                      strtolower(trim($fbRow['ad_name'] ?? ''));
        $utmTerm = strtolower(trim($fbRow['account_name'] ?? ''));

        // Подготовить UTM данные для нормализации
        $utmData = [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'utm_content' => $utmContent,
            'utm_term' => $utmTerm,
        ];

        // Применить нормализацию через utm_mapping (fb → facebook)
        $utmData = self::normalizeUTM($utmData);

        // Конвертация валюты в UAH
        $currency = strtoupper($fbRow['account_currency'] ?? 'UAH');
        $spend = floatval($fbRow['spend'] ?? 0);
        $cpm = floatval($fbRow['cpm'] ?? 0);

        // Если валюта не UAH - конвертируем
        if ($currency !== 'UAH' && $spend > 0) {
            $rate = self::getExchangeRate($currency);
            $spend = round($spend * $rate, 2);
            $cpm = round($cpm * $rate, 2);
        }

        return [
            'date_start' => $dateStart,
            'date_stop' => $dateStop,
            'account_id' => $fbRow['account_id'] ?? '',
            'campaign_id' => $fbRow['campaign_id'] ?? '',
            'adset_id' => $fbRow['adset_id'] ?? '',
            'ad_id' => $fbRow['ad_id'] ?? '',
            'publisher_platform' => strtolower(trim($fbRow['publisher_platform'] ?? '')),
            'platform_position' => strtolower(trim($fbRow['platform_position'] ?? '')),
            'account_name' => $fbRow['account_name'] ?? '',
            'campaign_name' => $fbRow['campaign_name'] ?? '',
            'adset_name' => $fbRow['adset_name'] ?? '',
            'ad_name' => $fbRow['ad_name'] ?? '',
            'utm_source' => $utmData['utm_source'],
            'utm_medium' => $utmData['utm_medium'],
            'utm_campaign' => $utmData['utm_campaign'],
            'utm_content' => $utmData['utm_content'],
            'utm_term' => $utmData['utm_term'],
            'spend' => $spend,
            'clicks' => intval($fbRow['clicks'] ?? 0),
            'impressions' => intval($fbRow['impressions'] ?? 0),
            'reach' => intval($fbRow['reach'] ?? 0),
            'unique_clicks' => intval($fbRow['unique_clicks'] ?? 0),
            'cpm' => $cpm,
            'ctr' => floatval($fbRow['ctr'] ?? 0),
            'account_currency' => 'UAH', // Всегда сохраняем как UAH после конвертации
            'buying_type' => $fbRow['buying_type'] ?? '',
            'objective' => $fbRow['objective'] ?? '',
            'optimization_goal' => $fbRow['optimization_goal'] ?? ''
        ];
    }

    /**
     * Получить затраты по UTM метке
     *
     * @param string $field utm_source|utm_medium|utm_campaign|utm_term|utm_content
     * @param array $filters
     * @return array
     */
    public static function getSpendByUTMField($field, $filters = []) {
        $db = self::getDB();

        $allowedFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        if (!in_array($field, $allowedFields)) {
            throw new Exception("Invalid UTM field: $field");
        }

        $where = [];
        $params = [];

        // Фильтр по датам
        if (!empty($filters['date_from'])) {
            $where[] = "date_start >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date_start <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // UTM фильтры (частичное совпадение)
        if (!empty($filters['utm_source'])) {
            $where[] = "LOWER(utm_source) LIKE :filter_utm_source";
            $params['filter_utm_source'] = '%' . strtolower($filters['utm_source']) . '%';
        }
        if (!empty($filters['utm_medium'])) {
            $where[] = "LOWER(utm_medium) LIKE :filter_utm_medium";
            $params['filter_utm_medium'] = '%' . strtolower($filters['utm_medium']) . '%';
        }
        if (!empty($filters['utm_campaign'])) {
            $where[] = "LOWER(utm_campaign) LIKE :filter_utm_campaign";
            $params['filter_utm_campaign'] = '%' . strtolower($filters['utm_campaign']) . '%';
        }
        // utm_term: підтримка списку значень (для mappings)
        if (!empty($filters['utm_term_list'])) {
            // Фільтр по списку значень (для пов'язаних ADS міток)
            $termConditions = [];
            foreach ($filters['utm_term_list'] as $index => $termValue) {
                $paramKey = "filter_utm_term_$index";
                $termConditions[] = "LOWER(utm_term) LIKE :$paramKey";
                $params[$paramKey] = '%' . strtolower($termValue) . '%';
            }
            if (!empty($termConditions)) {
                $where[] = '(' . implode(' OR ', $termConditions) . ')';
            }
        } elseif (!empty($filters['utm_term'])) {
            $where[] = "LOWER(utm_term) LIKE :filter_utm_term";
            $params['filter_utm_term'] = '%' . strtolower($filters['utm_term']) . '%';
        }
        if (!empty($filters['utm_content'])) {
            $where[] = "LOWER(utm_content) LIKE :filter_utm_content";
            $params['filter_utm_content'] = '%' . strtolower($filters['utm_content']) . '%';
        }

        // Исключить NULL
        $where[] = "$field IS NOT NULL AND $field != ''";

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
            $field as utm_value,
            SUM(spend) as total_spend,
            SUM(clicks) as total_clicks,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            AVG(cpm) as avg_cpm,
            AVG(ctr) as avg_ctr
        FROM ads_data
        $whereStr
        GROUP BY $field
        ORDER BY total_spend DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Получить данные по UTM ключу
     *
     * @param array $utmKey ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']
     * @param array $filters ['date_from', 'date_to']
     * @return array
     */
    public static function getByUTM($utmKey, $filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        // Фильтр по UTM меткам
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $field) {
            if (isset($utmKey[$field])) {
                $where[] = "$field = :$field";
                $params[$field] = $utmKey[$field];
            }
        }

        // Фильтр по датам
        if (!empty($filters['date_from'])) {
            $where[] = "date_start >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date_start <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (empty($where)) {
            return [];
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
            SUM(spend) as total_spend,
            SUM(clicks) as total_clicks,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach
        FROM ads_data
        $whereStr";

        return $db->fetchOne($sql, $params);
    }

    /**
     * Получить все данные с фильтрами
     */
    public static function getFiltered($filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "date_start >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date_start <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "$field = :$field";
                $params[$field] = $filters[$field];
            }
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM ads_data $whereStr ORDER BY date_start DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Удалить все данные (для тестирования)
     */
    public static function truncate() {
        $db = self::getDB();
        return $db->execute("TRUNCATE TABLE ads_data");
    }

    /**
     * Получить общую статистику
     */
    public static function getTotalStats($filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "date_start >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date_start <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // UTM фильтры (частичное совпадение)
        if (!empty($filters['utm_source'])) {
            $where[] = "LOWER(utm_source) LIKE :filter_utm_source";
            $params['filter_utm_source'] = '%' . strtolower($filters['utm_source']) . '%';
        }
        if (!empty($filters['utm_medium'])) {
            $where[] = "LOWER(utm_medium) LIKE :filter_utm_medium";
            $params['filter_utm_medium'] = '%' . strtolower($filters['utm_medium']) . '%';
        }
        if (!empty($filters['utm_campaign'])) {
            $where[] = "LOWER(utm_campaign) LIKE :filter_utm_campaign";
            $params['filter_utm_campaign'] = '%' . strtolower($filters['utm_campaign']) . '%';
        }
        // utm_term: підтримка списку значень (для mappings)
        if (!empty($filters['utm_term_list'])) {
            // Фільтр по списку значень (для пов'язаних ADS міток)
            $termConditions = [];
            foreach ($filters['utm_term_list'] as $index => $termValue) {
                $paramKey = "filter_utm_term_$index";
                $termConditions[] = "LOWER(utm_term) LIKE :$paramKey";
                $params[$paramKey] = '%' . strtolower($termValue) . '%';
            }
            if (!empty($termConditions)) {
                $where[] = '(' . implode(' OR ', $termConditions) . ')';
            }
        } elseif (!empty($filters['utm_term'])) {
            $where[] = "LOWER(utm_term) LIKE :filter_utm_term";
            $params['filter_utm_term'] = '%' . strtolower($filters['utm_term']) . '%';
        }
        if (!empty($filters['utm_content'])) {
            $where[] = "LOWER(utm_content) LIKE :filter_utm_content";
            $params['filter_utm_content'] = '%' . strtolower($filters['utm_content']) . '%';
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
            SUM(spend) as total_spend,
            SUM(clicks) as total_clicks,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            AVG(cpm) as avg_cpm,
            AVG(ctr) as avg_ctr
        FROM ads_data
        $whereStr";

        return $db->fetchOne($sql, $params);
    }

    /**
     * Получить количество записей
     */
    public static function count($filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "date_start >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date_start <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) as total FROM ads_data $whereStr";

        $result = $db->fetchOne($sql, $params);
        return (int)$result['total'];
    }

    /**
     * Нормализовать UTM метки
     * Преобразует старые значения (fb → facebook) через utm_mapping
     *
     * @param array $data Массив с UTM метками
     * @return array Нормализованный массив
     */
    private static function normalizeUTM($data) {
        static $mapping = null;

        // Загрузить маппинг один раз
        if ($mapping === null) {
            $mapping = self::loadUTMMapping();
        }

        // Нормализовать каждую метку
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $field) {
            if (!empty($data[$field])) {
                $value = strtolower(trim($data[$field]));

                // Заменить через маппинг
                $fieldType = str_replace('utm_', '', $field);
                $key = $fieldType . '_' . $value;

                if (isset($mapping[$key])) {
                    $data[$field] = $mapping[$key];
                } else {
                    $data[$field] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Загрузить маппинг UTM меток из БД
     *
     * @return array Маппинг вида ['source_fb' => 'facebook', ...]
     */
    private static function loadUTMMapping() {
        $db = self::getDB();
        $sql = "SELECT field_type, old_value, new_value FROM utm_mapping";
        $rows = $db->fetchAll($sql);

        $mapping = [];
        foreach ($rows as $row) {
            $key = $row['field_type'] . '_' . strtolower($row['old_value']);
            $mapping[$key] = strtolower($row['new_value']);
        }

        return $mapping;
    }

    /**
     * Получить курс валюты от НБУ
     * Кешируется на 1 час в памяти
     *
     * @param string $currency Код валюты (USD, EUR, etc)
     * @return float Курс к UAH
     */
    private static function getExchangeRate($currency) {
        static $rates = [];
        static $lastFetch = 0;

        $currency = strtoupper(trim($currency));

        // UAH не требует конвертации
        if ($currency === 'UAH') {
            return 1.0;
        }

        // Кеш на 1 час
        $cacheTime = 3600;

        if (time() - $lastFetch > $cacheTime) {
            $rates = [];
            $lastFetch = time();
        }

        if (isset($rates[$currency])) {
            return $rates[$currency];
        }

        // Получить курс от НБУ
        $url = "https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode={$currency}&json";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data[0]['rate'])) {
                $rates[$currency] = floatval($data[0]['rate']);
                error_log("[AdsData] Курс {$currency} от НБУ: " . $rates[$currency]);
                return $rates[$currency];
            }
        }

        // Fallback курсы если API недоступен
        $fallbackRates = [
            'USD' => 42.5,
            'EUR' => 45.0,
            'PLN' => 10.0,
            'GBP' => 52.0
        ];

        $rate = $fallbackRates[$currency] ?? 1.0;
        error_log("[AdsData] Используем fallback курс {$currency}: {$rate}");
        $rates[$currency] = $rate;

        return $rate;
    }

    // ========================================
    // МЕТОДЫ ДЛЯ РУЧНЫХ РАСХОДОВ
    // ========================================

    /**
     * Вставить ручной расход
     *
     * @param array $data Данные расхода
     * @return int ID вставленной записи
     */
    public static function insertManualCost($data) {
        $db = self::getDB();

        // Конвертировать валюту в UAH если нужно
        $currency = strtoupper($data['currency'] ?? 'UAH');
        $amount = floatval($data['amount'] ?? 0);

        if ($currency !== 'UAH' && $amount > 0) {
            $rate = self::getExchangeRate($currency);
            $amount = round($amount * $rate, 2);
            error_log("[AdsData] Ручной расход: конвертация {$data['amount']} {$currency} → {$amount} UAH (курс: {$rate})");
        }

        // Генерировать уникальные ID для ручного расхода
        $timestamp = time();
        $random = substr(md5(uniqid()), 0, 8);
        $manualId = "manual_{$timestamp}_{$random}";

        // Отримати проект з даних
        $project = strtoupper(trim($data['project'] ?? 'VOLVO'));

        $sql = "INSERT INTO ads_data (
            date_start, date_stop, account_id, campaign_id, adset_id, ad_id,
            publisher_platform, platform_position,
            account_name, campaign_name, adset_name, ad_name,
            utm_source, utm_medium, utm_campaign, utm_content, utm_term,
            spend, clicks, impressions, reach, unique_clicks, cpm, ctr,
            account_currency, buying_type, objective, optimization_goal, project
        ) VALUES (
            :date_start, :date_stop, :account_id, :campaign_id, :adset_id, :ad_id,
            'manual', :platform_position,
            'manual_input', :campaign_name, '', '',
            :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term,
            :spend, 0, 0, 0, 0, 0, 0,
            'UAH', '', 'manual', '', :project
        )";

        $db->execute($sql, [
            'date_start' => $data['date'],
            'date_stop' => $data['date'],
            'account_id' => $manualId,
            'campaign_id' => $manualId,
            'adset_id' => $manualId,
            'ad_id' => $manualId,
            'platform_position' => $data['note'] ?? '',
            'campaign_name' => $data['utm_campaign'] ?? '',
            'utm_source' => strtolower(trim($data['utm_source'] ?? '')),
            'utm_medium' => strtolower(trim($data['utm_medium'] ?? '')),
            'utm_campaign' => strtolower(trim($data['utm_campaign'] ?? '')),
            'utm_content' => strtolower(trim($data['utm_content'] ?? '')),
            'utm_term' => strtolower(trim($data['utm_term'] ?? '')),
            'spend' => $amount,
            'project' => $project
        ]);

        return $db->lastInsertId();
    }

    /**
     * Получить все ручные расходы
     *
     * @param array $filters Фильтры (date_from, date_to)
     * @return array
     */
    public static function getManualCosts($filters = []) {
        $db = self::getDB();

        $where = ["publisher_platform = 'manual'"];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "date_start >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date_start <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Фільтр по utm_term (для таргетологів)
        if (!empty($filters['utm_term'])) {
            $where[] = "LOWER(utm_term) LIKE :filter_utm_term";
            $params['filter_utm_term'] = '%' . strtolower($filters['utm_term']) . '%';
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
            id, date_start, date_stop,
            utm_source, utm_medium, utm_campaign, utm_content, utm_term,
            spend, platform_position as note, project
        FROM ads_data
        $whereStr
        ORDER BY date_start DESC, id DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Получить один ручной расход по ID
     *
     * @param int $id
     * @return array|null
     */
    public static function getManualCostById($id) {
        $db = self::getDB();

        $sql = "SELECT
            id, date_start, date_stop,
            utm_source, utm_medium, utm_campaign, utm_content, utm_term,
            spend, platform_position as note
        FROM ads_data
        WHERE id = :id AND publisher_platform = 'manual'
        LIMIT 1";

        return $db->fetchOne($sql, ['id' => intval($id)]);
    }

    /**
     * Обновить ручной расход
     *
     * @param array $data Данные для обновления (должен содержать id)
     * @return bool
     */
    public static function updateManualCost($data) {
        $db = self::getDB();

        if (empty($data['id'])) {
            throw new Exception('ID расхода не указан');
        }

        $sql = "UPDATE ads_data SET
            date_start = :date_start,
            date_stop = :date_stop,
            utm_source = :utm_source,
            utm_medium = :utm_medium,
            utm_campaign = :utm_campaign,
            utm_content = :utm_content,
            utm_term = :utm_term,
            spend = :spend,
            platform_position = :note,
            campaign_name = :campaign_name,
            project = :project
        WHERE id = :id AND publisher_platform = 'manual'";

        $db->execute($sql, [
            'id' => intval($data['id']),
            'date_start' => $data['date'],
            'date_stop' => $data['date'],
            'utm_source' => strtolower(trim($data['utm_source'] ?? '')),
            'utm_medium' => strtolower(trim($data['utm_medium'] ?? '')),
            'utm_campaign' => strtolower(trim($data['utm_campaign'] ?? '')),
            'utm_content' => strtolower(trim($data['utm_content'] ?? '')),
            'utm_term' => strtolower(trim($data['utm_term'] ?? '')),
            'spend' => floatval($data['amount'] ?? 0),
            'note' => $data['note'] ?? '',
            'campaign_name' => $data['utm_campaign'] ?? '',
            'project' => strtoupper(trim($data['project'] ?? 'VOLVO'))
        ]);

        return true;
    }

    /**
     * Удалить ручной расход
     *
     * @param int $id
     * @return bool
     */
    public static function deleteManualCost($id) {
        $db = self::getDB();

        $sql = "DELETE FROM ads_data WHERE id = :id AND publisher_platform = 'manual'";
        $db->execute($sql, ['id' => intval($id)]);

        return true;
    }

    /**
     * Получить статистику по ручным расходам
     *
     * @param array $filters
     * @return array
     */
    public static function getManualCostsStats($filters = []) {
        $db = self::getDB();

        $where = ["publisher_platform = 'manual'"];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "date_start >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date_start <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
            COUNT(*) as total_count,
            SUM(spend) as total_spend
        FROM ads_data
        $whereStr";

        return $db->fetchOne($sql, $params);
    }
}
