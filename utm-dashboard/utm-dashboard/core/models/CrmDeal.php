<?php
// === CrmDeal.php ===
// НАЗНАЧЕНИЕ: Модель для работы с таблицей crm_deals
// СВЯЗИ: core/Database.php
// ИСПОЛЬЗОВАНИЕ: CrmDeal::upsert($data), CrmDeal::getStats($filters)
// РАЗМЕР: ~400 строк

require_once __DIR__ . '/../Database.php';

class CrmDeal {
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
     * Вставка или обновление сделки
     * ВАЖНО: Уникальность по deal_id
     *
     * @param array $data Данные сделки
     * @return bool
     */
    public static function upsert($data) {
        $db = self::getDB();

        // Подготовить данные
        $fields = [
            'deal_id', 'contact_id', 'email', 'phone', 'full_name',
            'created_at', 'deal_updated_at',
            'amount', 'amount_uah', 'deal_price', 'deal_currency',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'deal_pipeline', 'deal_type', 'deal_status',
            'is_paid', 'is_failed', 'is_pending',
            'deal_name', 'deal_step', 'product', 'tickets', 'tickets_count',
            'list_name', 'tag_list',
            'model', 'deal_project', 'tariff', 'pay_provider', 'wc_order_id', 'comment'
        ];

        // Нормализовать UTM метки
        $data = self::normalizeUTM($data);

        // Автоопределение customer_type
        if (empty($data['customer_type'])) {
            $data['customer_type'] = self::detectCustomerType($data);
        }

        $fields[] = 'customer_type';

        $insertData = [];
        foreach ($fields as $field) {
            $insertData[$field] = $data[$field] ?? null;
        }

        // Поля для обновления (все кроме deal_id и created_at)
        $updateFields = array_diff($fields, ['deal_id', 'created_at']);

        return $db->insertOrUpdate('crm_deals', $insertData, $updateFields);
    }

    /**
     * Массовая вставка сделок
     *
     * @param array $deals Массив сделок
     * @param int $batchSize Размер батча
     * @return array ['new' => int, 'updated' => int]
     */
    public static function batchUpsert($deals, $batchSize = 500) {
        $db = self::getDB();
        $stats = ['new' => 0, 'updated' => 0];

        // Получить существующие deal_id
        $existingIds = self::getExistingDealIds();

        $batches = array_chunk($deals, $batchSize);

        foreach ($batches as $batch) {
            $db->beginTransaction();

            try {
                foreach ($batch as $deal) {
                    $deal = self::normalizeUTM($deal);
                    $dealId = $deal['deal_id'] ?? null;

                    if ($dealId && isset($existingIds[$dealId])) {
                        // Обновление
                        self::update($dealId, $deal);
                        $stats['updated']++;
                    } else {
                        // Вставка
                        self::insert($deal);
                        $stats['new']++;

                        if ($dealId) {
                            $existingIds[$dealId] = true;
                        }
                    }
                }

                $db->commit();

            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
        }

        return $stats;
    }

    /**
     * Вставка новой сделки (с защитой от дубликатов по deal_id)
     */
    private static function insert($data) {
        $db = self::getDB();

        $sql = "INSERT INTO crm_deals (
            deal_id, contact_id, email, phone, full_name, created_at, deal_updated_at,
            amount, amount_uah, deal_price, deal_currency,
            utm_source, utm_medium, utm_campaign, utm_term, utm_content,
            deal_pipeline, deal_type, deal_status,
            is_paid, is_failed, is_pending,
            deal_name, deal_step, product, tickets, tickets_count,
            list_name, tag_list, model, deal_project, tariff, pay_provider, wc_order_id, comment
        ) VALUES (
            :deal_id, :contact_id, :email, :phone, :full_name, :created_at, :deal_updated_at,
            :amount, :amount_uah, :deal_price, :deal_currency,
            :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
            :deal_pipeline, :deal_type, :deal_status,
            :is_paid, :is_failed, :is_pending,
            :deal_name, :deal_step, :product, :tickets, :tickets_count,
            :list_name, :tag_list, :model, :deal_project, :tariff, :pay_provider, :wc_order_id, :comment
        ) AS new_values
        ON DUPLICATE KEY UPDATE
            contact_id = new_values.contact_id,
            email = new_values.email,
            phone = new_values.phone,
            full_name = new_values.full_name,
            deal_updated_at = new_values.deal_updated_at,
            amount = new_values.amount,
            amount_uah = new_values.amount_uah,
            deal_price = new_values.deal_price,
            deal_currency = new_values.deal_currency,
            utm_source = new_values.utm_source,
            utm_medium = new_values.utm_medium,
            utm_campaign = new_values.utm_campaign,
            utm_term = new_values.utm_term,
            utm_content = new_values.utm_content,
            deal_pipeline = new_values.deal_pipeline,
            deal_type = new_values.deal_type,
            deal_status = new_values.deal_status,
            is_paid = new_values.is_paid,
            is_failed = new_values.is_failed,
            is_pending = new_values.is_pending,
            deal_name = new_values.deal_name,
            deal_step = new_values.deal_step,
            product = new_values.product,
            tickets = new_values.tickets,
            tickets_count = new_values.tickets_count,
            list_name = new_values.list_name,
            tag_list = new_values.tag_list,
            model = new_values.model,
            deal_project = new_values.deal_project,
            tariff = new_values.tariff,
            pay_provider = new_values.pay_provider,
            wc_order_id = new_values.wc_order_id,
            comment = new_values.comment";

        return $db->execute($sql, $data);
    }

    /**
     * Обновление сделки по deal_id
     */
    private static function update($dealId, $data) {
        $db = self::getDB();

        $sql = "UPDATE crm_deals SET
            contact_id = :contact_id,
            email = :email,
            phone = :phone,
            full_name = :full_name,
            deal_updated_at = :deal_updated_at,
            amount = :amount,
            amount_uah = :amount_uah,
            deal_price = :deal_price,
            deal_currency = :deal_currency,
            utm_source = :utm_source,
            utm_medium = :utm_medium,
            utm_campaign = :utm_campaign,
            utm_term = :utm_term,
            utm_content = :utm_content,
            deal_pipeline = :deal_pipeline,
            deal_type = :deal_type,
            deal_status = :deal_status,
            is_paid = :is_paid,
            is_failed = :is_failed,
            is_pending = :is_pending,
            deal_name = :deal_name,
            deal_step = :deal_step,
            product = :product,
            tickets = :tickets,
            tickets_count = :tickets_count,
            list_name = :list_name,
            tag_list = :tag_list,
            model = :model,
            deal_project = :deal_project,
            tariff = :tariff,
            pay_provider = :pay_provider,
            wc_order_id = :wc_order_id,
            comment = :comment
        WHERE deal_id = :deal_id";

        $data['deal_id'] = $dealId;

        // Удалить поля которых нет в UPDATE запросе
        unset($data['created_at']); // created_at не обновляется при UPDATE

        return $db->execute($sql, $data);
    }

    /**
     * Получить существующие deal_id
     */
    private static function getExistingDealIds() {
        $db = self::getDB();
        $sql = "SELECT deal_id FROM crm_deals WHERE deal_id IS NOT NULL";
        $result = $db->fetchAll($sql);

        $ids = [];
        foreach ($result as $row) {
            $ids[$row['deal_id']] = true;
        }
        return $ids;
    }

    /**
     * Получить сделку по deal_id
     */
    public static function getByDealId($dealId) {
        $db = self::getDB();
        $sql = "SELECT * FROM crm_deals WHERE deal_id = :deal_id LIMIT 1";
        return $db->fetchOne($sql, ['deal_id' => $dealId]);
    }

    /**
     * Получить сделки с фильтрами
     *
     * @param array $filters ['date_from', 'date_to', 'deal_type', 'utm_source', ...]
     * @return array
     */
    public static function getFiltered($filters = []) {
        $db = self::getDB();

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

        // Фильтр по типу сделки
        if (!empty($filters['deal_type'])) {
            $where[] = "deal_type = :deal_type";
            $params['deal_type'] = $filters['deal_type'];
        }

        // Фильтр по UTM меткам
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "$field = :$field";
                $params[$field] = $filters[$field];
            }
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM crm_deals $whereStr ORDER BY created_at DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Получить статистику по фильтрам
     *
     * @param array $filters
     * @return array
     */
    public static function getStats($filters = []) {
        $db = self::getDB();

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
            $projectAliases = self::getProjectAliases($project);

            if (count($projectAliases) > 1) {
                $qPlaceholders = [];
                foreach ($projectAliases as $qi => $qAlias) {
                    $qPlaceholders[] = ":dp_prj_{$qi}";
                    $params["dp_prj_{$qi}"] = $qAlias;
                }
                $where[] = "deal_project IN (" . implode(', ', $qPlaceholders) . ")";
            } else {
                $where[] = "deal_project = :filter_deal_project";
                $params['filter_deal_project'] = $projectAliases[0] ?? $project;
            }

            // Фильтр по датам проекта
            $projectDates = self::getProjectDates();
            $projectUpper = strtoupper(trim($project));
            if (isset($projectDates[$projectUpper])) {
                $where[] = "created_at >= :project_date_from";
                $where[] = "created_at <= :project_date_to";
                $params['project_date_from'] = $projectDates[$projectUpper]['date_from'] . ' 00:00:00';
                $params['project_date_to'] = $projectDates[$projectUpper]['date_to'] . ' 23:59:59';
            }
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
        if (!empty($filters['utm_term'])) {
            $where[] = "LOWER(utm_term) LIKE :filter_utm_term";
            $params['filter_utm_term'] = '%' . strtolower($filters['utm_term']) . '%';
        }
        if (!empty($filters['utm_content'])) {
            $where[] = "LOWER(utm_content) LIKE :filter_utm_content";
            $params['filter_utm_content'] = '%' . strtolower($filters['utm_content']) . '%';
        }

        // Фильтр по типу клиента (new/returning) - через колонку customer_type
        if (!empty($filters['customer_type']) && $filters['customer_type'] !== 'all') {
            $where[] = "customer_type = :filter_customer_type";
            $params['filter_customer_type'] = $filters['customer_type'];
        }

        // Фильтр по типу воронки (new_in_funnel/returning_in_funnel)
        if (!empty($filters['funnel_type']) && $filters['funnel_type'] !== 'all') {
            $dealIds = self::getDealIdsByFunnelType($filters['funnel_type']);
            if (!empty($dealIds)) {
                $escapedIds = array_map('intval', $dealIds);
                $idsList = implode(',', $escapedIds);
                $where[] = "deal_id IN ($idsList)";
            } else {
                return [
                    'total_leads' => 0,
                    'paid_count' => 0,
                    'failed_count' => 0,
                    'pending_count' => 0,
                    'paid_amount' => 0,
                    'failed_amount' => 0,
                    'pending_amount' => 0
                ];
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

        // Фильтр аномальных сумм (больше 1 млн UAH)
        $where[] = "amount_uah < 1000000";

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
            COUNT(*) as total_leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount,
            SUM(CASE WHEN is_pending = 1 THEN amount_uah ELSE 0 END) as pending_amount
        FROM crm_deals
        $whereStr";

        return $db->fetchOne($sql, $params);
    }

    /**
     * Получить статистику по одной UTM метке
     *
     * @param string $field utm_source|utm_medium|utm_campaign|utm_term|utm_content
     * @param array $filters
     * @return array
     */
    public static function getStatsByUTMField($field, $filters = []) {
        $db = self::getDB();

        $allowedFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        if (!in_array($field, $allowedFields)) {
            throw new Exception("Invalid UTM field: $field");
        }

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
            $projectAliases = self::getProjectAliases($project);

            if (count($projectAliases) > 1) {
                $qPlaceholders = [];
                foreach ($projectAliases as $qi => $qAlias) {
                    $qPlaceholders[] = ":dp_prj_{$qi}";
                    $params["dp_prj_{$qi}"] = $qAlias;
                }
                $where[] = "deal_project IN (" . implode(', ', $qPlaceholders) . ")";
            } else {
                $where[] = "deal_project = :filter_deal_project";
                $params['filter_deal_project'] = $projectAliases[0] ?? $project;
            }

            // Фильтр по датам проекта
            $projectDates = self::getProjectDates();
            $projectUpper = strtoupper(trim($project));
            if (isset($projectDates[$projectUpper])) {
                $where[] = "created_at >= :project_date_from";
                $where[] = "created_at <= :project_date_to";
                $params['project_date_from'] = $projectDates[$projectUpper]['date_from'] . ' 00:00:00';
                $params['project_date_to'] = $projectDates[$projectUpper]['date_to'] . ' 23:59:59';
            }
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
        if (!empty($filters['utm_term'])) {
            $where[] = "LOWER(utm_term) LIKE :filter_utm_term";
            $params['filter_utm_term'] = '%' . strtolower($filters['utm_term']) . '%';
        }
        if (!empty($filters['utm_content'])) {
            $where[] = "LOWER(utm_content) LIKE :filter_utm_content";
            $params['filter_utm_content'] = '%' . strtolower($filters['utm_content']) . '%';
        }

        // Фильтр по типу клиента (new/returning) - через колонку customer_type
        if (!empty($filters['customer_type']) && $filters['customer_type'] !== 'all') {
            $where[] = "customer_type = :filter_customer_type";
            $params['filter_customer_type'] = $filters['customer_type'];
        }

        // Фильтр по типу воронки (new_in_funnel/returning_in_funnel)
        if (!empty($filters['funnel_type']) && $filters['funnel_type'] !== 'all') {
            $dealIds = self::getDealIdsByFunnelType($filters['funnel_type']);
            if (!empty($dealIds)) {
                $escapedIds = array_map('intval', $dealIds);
                $idsList = implode(',', $escapedIds);
                $where[] = "deal_id IN ($idsList)";
            } else {
                return []; // Нет сделок по этому фильтру
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

        // Исключить NULL значения в UTM метке
        $where[] = "$field IS NOT NULL AND $field != ''";

        // Фильтр аномальных сумм (больше 1 млн UAH)
        $where[] = "amount_uah < 1000000";

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
            $field as utm_value,
            COUNT(*) as leads,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_failed = 1 THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN is_paid = 1 THEN amount_uah ELSE 0 END) as paid_amount,
            SUM(CASE WHEN is_failed = 1 THEN amount_uah ELSE 0 END) as failed_amount,
            SUM(CASE WHEN is_pending = 1 THEN amount_uah ELSE 0 END) as pending_amount
        FROM crm_deals
        $whereStr
        GROUP BY $field
        ORDER BY paid_amount DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Удалить все сделки (для тестирования)
     */
    public static function truncate() {
        $db = self::getDB();
        return $db->execute("TRUNCATE TABLE crm_deals");
    }

    /**
     * Нормализовать UTM метки
     * Преобразует старые значения (fb → facebook) через utm_mapping
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
     * Сгруппировать сделки по уникальным клиентам
     * Клиент определяется по: contact_id ИЛИ телефон ИЛИ email
     *
     * @return array ['client_hash' => ['deal_ids' => [...], 'deals_count' => int], ...]
     */
    private static $groupDealsByClientCache = null;
    private static $groupDealsByClientCacheProject = null;

    private static function groupDealsByClient() {
        // Кеш в рамках одного HTTP запроса
        $activeProject = self::getActiveProject();
        if (self::$groupDealsByClientCache !== null && self::$groupDealsByClientCacheProject === $activeProject) {
            return self::$groupDealsByClientCache;
        }

        $db = self::getDB();

        // Получить все сделки с нужными полями
        $sql = "SELECT deal_id, contact_id, phone, email, model, deal_project, created_at
                FROM crm_deals
                ORDER BY created_at ASC";
        $deals = $db->fetchAll($sql);

        // Индексы для быстрого поиска
        $contactIdToClient = [];  // contact_id -> client_id
        $phoneToClient = [];      // phone -> client_id
        $emailToClient = [];      // email -> client_id

        $clients = [];            // client_id -> ['deal_ids' => [...], ...]
        $dealToClient = [];       // deal_id -> client_id
        $nextClientId = 1;

        // Вынесено из цикла - считаем один раз
        $projectAliases = array_map('strtoupper', self::getProjectAliases($activeProject));

        foreach ($deals as $deal) {
            $dealId = $deal['deal_id'];
            $contactId = $deal['contact_id'];
            $phones = self::parseMultipleValues($deal['phone']);
            $emails = self::parseMultipleValues($deal['email']);

            $foundClientId = null;

            // Поиск по contact_id
            if ($contactId && isset($contactIdToClient[$contactId])) {
                $foundClientId = $contactIdToClient[$contactId];
            }

            // Поиск по телефонам
            if (!$foundClientId) {
                foreach ($phones as $phone) {
                    if (isset($phoneToClient[$phone])) {
                        $foundClientId = $phoneToClient[$phone];
                        break;
                    }
                }
            }

            // Поиск по email
            if (!$foundClientId) {
                foreach ($emails as $email) {
                    if (isset($emailToClient[$email])) {
                        $foundClientId = $emailToClient[$email];
                        break;
                    }
                }
            }

            // Создать нового клиента или добавить к существующему
            if (!$foundClientId) {
                $foundClientId = $nextClientId++;
                $clients[$foundClientId] = [
                    'deal_ids' => [],
                    'project_deal_ids' => [],
                    'first_project_deal_id' => null
                ];
            }

            // Добавить сделку к клиенту
            $clients[$foundClientId]['deal_ids'][] = $dealId;
            $dealToClient[$dealId] = $foundClientId;

            // Отслеживать сделки активного проекта по deal_project
            $dealProjectValue = strtoupper($deal['deal_project'] ?? '');
            $isActiveProject = in_array($dealProjectValue, $projectAliases);
            
            if ($isActiveProject) {
                $clients[$foundClientId]['project_deal_ids'][] = $dealId;

                // Перша сделка активного проекту (сделки відсортовані по даті)
                if ($clients[$foundClientId]['first_project_deal_id'] === null) {
                    $clients[$foundClientId]['first_project_deal_id'] = $dealId;
                }
            }

            // Обновить индексы
            if ($contactId) {
                $contactIdToClient[$contactId] = $foundClientId;
            }
            foreach ($phones as $phone) {
                $phoneToClient[$phone] = $foundClientId;
            }
            foreach ($emails as $email) {
                $emailToClient[$email] = $foundClientId;
            }
        }

        $result = [
            'clients' => $clients,
            'deal_to_client' => $dealToClient
        ];

        // Сохранить в кеш (живет только в рамках текущего HTTP запроса)
        self::$groupDealsByClientCache = $result;
        self::$groupDealsByClientCacheProject = $activeProject;

        return $result;
    }

    /**
     * Разбить строку с несколькими значениями (разделитель ;)
     *
     * @param string|null $value
     * @return array
     */
    private static function parseMultipleValues($value) {
        if (empty($value)) {
            return [];
        }

        $parts = explode(';', $value);
        $result = [];

        foreach ($parts as $part) {
            $part = trim(strtolower($part));
            if (!empty($part)) {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * Получить ID сделок по типу клиента
     *
     * @param string $type 'new' (1 покупка) или 'returning' (2+ покупок)
     * @return array массив deal_id
     */
    public static function getDealIdsByCustomerType($type) {
        $grouped = self::groupDealsByClient();
        $clients = $grouped['clients'];

        $dealIds = [];

        foreach ($clients as $clientData) {
            $dealsCount = count($clientData['deal_ids']);

            if ($type === 'new' && $dealsCount === 1) {
                // Новый клиент — только 1 сделка
                $dealIds = array_merge($dealIds, $clientData['deal_ids']);
            } elseif ($type === 'returning' && $dealsCount >= 2) {
                // Существующий клиент — 2+ сделок
                $dealIds = array_merge($dealIds, $clientData['deal_ids']);
            }
        }

        return $dealIds;
    }

    /**
     * Отримати ID сделок за типом воронки активного проекту
     *
     * @param string $type 'new_in_funnel' (перша сделка в проекті) або 'returning_in_funnel' (повторна)
     * @return array масив deal_id
     */
    public static function getDealIdsByFunnelType($type) {
        $grouped = self::groupDealsByClient();
        $clients = $grouped['clients'];

        $dealIds = [];

        foreach ($clients as $clientData) {
            $projectDealIds = $clientData['project_deal_ids'] ?? [];
            $firstProjectDealId = $clientData['first_project_deal_id'] ?? null;

            if (empty($projectDealIds)) {
                continue; // У клієнта немає сделок активного проекту
            }

            if ($type === 'new_in_funnel') {
                // Тільки перша сделка клієнта в активному проекті
                if ($firstProjectDealId) {
                    $dealIds[] = $firstProjectDealId;
                }
            } elseif ($type === 'returning_in_funnel') {
                // Всі сделки активного проекту крім першої
                foreach ($projectDealIds as $projectDealId) {
                    if ($projectDealId !== $firstProjectDealId) {
                        $dealIds[] = $projectDealId;
                    }
                }
            }
        }

        return $dealIds;
    }

    /**
     * Получить количество сделок
     */
    public static function count($filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) as total FROM crm_deals $whereStr";

        $result = $db->fetchOne($sql, $params);
        return (int)$result['total'];
    }

    /**
     * Отримати активний проект з конфігурації
     *
     * @return string Назва активного проекту
     */
    private static function getActiveProject() {
        static $activeProject = null;

        if ($activeProject === null) {
            $settingsFile = __DIR__ . '/../../config/dashboard_settings.json';
            if (file_exists($settingsFile)) {
                $settings = json_decode(file_get_contents($settingsFile), true);
                $activeProject = strtoupper($settings['active_project'] ?? 'VOLVO');
            } else {
                $activeProject = 'VOLVO'; // дефолт
            }
        }

        return $activeProject;
    }

    /**
     * Маппинг алиасов проектов
     * Проекты BANK, BASIC, GOLD, IBANOPLATA, START относятся к проекту Q7
     *
     * @return array ['алиас' => 'основной_проект']
     */
    private static function getProjectMapping() {
        return [
            // Алиасы Q7 (тарифы)
            'BANK' => 'AUDI Q7',
            'BASIC' => 'AUDI Q7',
            'GOLD' => 'AUDI Q7',
            'IBANOPLATA' => 'AUDI Q7',
            'START' => 'AUDI Q7',
            'Q7' => 'AUDI Q7',
            // Старые названия -> новые
            'VOLVO' => 'VOLVO XC90',
            'BMW' => 'BMW 330E HYBRID',
            'MERCEDES' => 'MERCEDES GLE COUPE',
            'DREAMCAR AI' => 'BMW X5 HYBRID',
        ];
    }

    /**
     * Получить даты проведения проектов
     *
     * @return array ['НАЗВАНИЕ' => ['date_from' => '...', 'date_to' => '...']]
     */
    public static function getProjectDates() {
        return [
            'VOLVO XC90' => ['date_from' => '2025-10-10', 'date_to' => '2025-11-30'],
            'AUDI Q7' => ['date_from' => '2025-12-08', 'date_to' => '2025-12-28'],
            'BMW 330E HYBRID' => ['date_from' => '2026-01-09', 'date_to' => '2026-01-23'],
            'MERCEDES GLE COUPE' => ['date_from' => '2026-02-06', 'date_to' => '2026-03-01'],
            'BMW X5 HYBRID' => ['date_from' => '2026-03-24', 'date_to' => '2026-04-19'],
        ];
    }

    /**
     * Получить список основных проектов (для UI)
     *
     * @return array
     */
    public static function getMainProjects() {
        return array_keys(self::getProjectDates());
    }

    /**
     * Получить значения deal_project из БД для указанного проекта
     *
     * @param string $project Название проекта (новое)
     * @return array Значения deal_project в БД
     */
    public static function getDealProjectValues($project) {
        $project = strtoupper(trim($project));
        $mapping = self::getProjectMapping();

        // Собрать все значения deal_project которые маппятся на этот проект
        $values = [];
        foreach ($mapping as $alias => $mainProject) {
            if (strtoupper($mainProject) === $project) {
                $values[] = $alias;
            }
        }

        return array_unique($values);
    }

    /**
     * Получить список алиасов для проекта
     *
     * @param string $project Название проекта
     * @return array Массив алиасов (включая сам проект)
     */
    public static function getProjectAliases($project) {
        $project = strtoupper(trim($project));

        // Получить все значения deal_project из БД для этого проекта
        $aliases = self::getDealProjectValues($project);

        // Если проект сам является значением deal_project - добавить
        if (!in_array($project, $aliases)) {
            $aliases[] = $project;
        }

        return array_unique($aliases);
    }

    /**
     * Нормализовать название проекта (алиасы → основной проект)
     *
     * @param string $project Название проекта
     * @return string Нормализованное название
     */
    private static function normalizeProjectName($project) {
        $project = strtoupper(trim($project));
        $mapping = self::getProjectMapping();

        // Если это алиас/старое название - вернуть основной проект
        if (isset($mapping[$project])) {
            return strtoupper($mapping[$project]);
        }

        return $project;
    }

    /**
     * Проверить является ли проект алиасом (не должен показываться в списке)
     *
     * @param string $project Название проекта
     * @return bool
     */
    public static function isProjectAlias($project) {
        $project = strtoupper(trim($project));
        $mainProjects = self::getMainProjects();

        // Если это основной проект - не алиас
        if (in_array($project, array_map('strtoupper', $mainProjects))) {
            return false;
        }

        // Если есть в маппинге - значит алиас
        $mapping = self::getProjectMapping();
        return isset($mapping[$project]);
    }

    /**
     * Определить тип клиента (new/returning) по данным сделки
     * Проверяет есть ли в БД сделки с таким же contact_id, phone или email
     *
     * @param array $data Данные сделки
     * @return string 'new' или 'returning'
     */
    public static function detectCustomerType($data) {
        $db = self::getDB();

        $conditions = [];
        $params = [];

        if (!empty($data['contact_id'])) {
            $conditions[] = "contact_id = :ct_contact_id";
            $params['ct_contact_id'] = $data['contact_id'];
        }
        if (!empty($data['phone'])) {
            $phones = array_filter(array_map('trim', preg_split('/[;,]/', $data['phone'])));
            foreach ($phones as $i => $phone) {
                $conditions[] = "phone LIKE :ct_phone_{$i}";
                $params["ct_phone_{$i}"] = '%' . $phone . '%';
            }
        }
        if (!empty($data['email'])) {
            $emails = array_filter(array_map('trim', preg_split('/[;,]/', $data['email'])));
            foreach ($emails as $i => $email) {
                $conditions[] = "email LIKE :ct_email_{$i}";
                $params["ct_email_{$i}"] = '%' . $email . '%';
            }
        }

        if (empty($conditions)) {
            return 'new';
        }

        $where = implode(' OR ', $conditions);
        // Исключить текущую сделку если есть deal_id
        $excludeDeal = '';
        if (!empty($data['deal_id'])) {
            $excludeDeal = " AND deal_id != :ct_exclude_deal_id";
            $params['ct_exclude_deal_id'] = $data['deal_id'];
        }

        $sql = "SELECT COUNT(*) as cnt FROM crm_deals WHERE ({$where}){$excludeDeal} LIMIT 1";
        $result = $db->fetchOne($sql, $params);

        return ($result && $result['cnt'] > 0) ? 'returning' : 'new';
    }
}
