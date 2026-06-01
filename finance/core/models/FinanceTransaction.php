<?php
// === FinanceTransaction.php ===
// finance/core/models/FinanceTransaction.php
// НАЗНАЧЕНИЕ: CRUD для finance_transactions + агрегаты + CRM интеграция
// СВЯЗИ: core/Database.php, finance_projects, finance_cards
// РАЗМЕР: ~450 строк

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/FinanceCard.php';

class FinanceTransaction {

    private const ALLOWED_TYPES = [
        'income', 'income_extra', 'expense', 'card_topup', 'salary',
    ];

    private const EDITABLE_FIELDS = [
        'type', 'category', 'description', 'amount_uah', 'transaction_date', 'card_id', 'notes',
    ];

    /**
     * Группированный справочник категорий расходов (5 групп, 25+ категорий).
     *
     * Поддерживает ВСЕ типы расходов проекта:
     * - Реклама (Meta, Google, TikTok, Viber, SMS, Email, Дизайн)
     * - Виробництво (покупка авто, сервис, подготовка к розыгрышу)
     * - Операційні (офис, бухгалтерия, налоги, подписки)
     * - Команда (зарплаты, подрядчики, бонусы, отдрядження)
     * - Інше
     *
     * Обратная совместимость: все ключи которые были в старой EXPENSE_CATEGORIES
     * (meta, google, viber, sms, email, design, other) остаются рабочими.
     */
    private const EXPENSE_CATEGORIES_GROUPED = [
        'advertising' => [
            'label' => '📣 Реклама',
            'items' => [
                'meta'   => 'Meta Ads (Facebook/Instagram)',
                'google' => 'Google Ads',
                'tiktok' => 'TikTok Ads',
                'viber'  => 'Viber рассылки',
                'sms'    => 'SMS рассылки',
                'email'  => 'Email рассылки',
                'design' => 'Дизайн / креативи',
            ],
        ],
        'production' => [
            'label' => '🎁 Подарунки',
            'items' => [
                'car_purchase' => 'Покупка авто (приз)',
                'car_service'  => 'Сервiс / ремонт авто',
                'car_prep'     => 'Підготовка до розіграшу',
                'delivery'     => 'Доставка / логістика',
                'insurance'    => 'Страхування / оформлення',
            ],
        ],
        'fees' => [
            'label' => '💳 Податки та комісії',
            'items' => [
                'taxes'     => 'Податки',
                'bank_fees' => 'Банківські комісії / еквайринг',
            ],
        ],
        'operations' => [
            'label' => '🏢 Операційні',
            'items' => [
                'office_rent'   => 'Оренда офісу',
                'utilities'     => 'Комунальні послуги',
                'accounting'    => 'Бухгалтерія',
                'legal'         => 'Юридичні послуги',
                'supplies'      => 'Канцтовари / офіс',
                'subscriptions' => 'Підписки на сервіси',
            ],
        ],
        'team' => [
            'label' => '👥 Команда',
            'items' => [
                'salary_manual'    => 'Зарплата (ручна)',
                'contractor'       => 'Оплата підрядника',
                'bonus'            => 'Бонуси / премії',
                'travel'           => 'Відрядження',
            ],
        ],
        'other' => [
            'label' => '❓ Інше',
            'items' => [
                'other' => 'Інше (без категорії)',
            ],
        ],
    ];

    /**
     * Маппинг crm_deals.deal_project → finance_projects.name
     * Ключи в UPPER, значения — точные названия finance_projects.name
     */
    private const PROJECT_ALIAS_MAP = [
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
        'AUDI E-TRON'        => 'AUDI E-TRON',
        // OLD — сделки до разбивки по проектам → VOLVO XC90 (самый ранний)
        'OLD'                => 'VOLVO XC90',
        // TEST, UNKNOWN — игнорировать
    ];

    private static function db(): Database {
        return Database::getInstance();
    }

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    /**
     * Список транзакций с фильтрацией и пагинацией
     *
     * @param array $filters Доступные ключи: project_id, type (string|array),
     *                       date_from, date_to, search, page, per_page
     * @return array{items:array,pagination:array{page:int,per_page:int,total:int}}
     */
    public static function getList(array $filters = []): array {
        $db     = self::db();
        $where  = ['t.deleted_at IS NULL'];
        $params = [];

        // project_id
        if (!empty($filters['project_id'])) {
            $where[]              = 't.project_id = :project_id';
            $params[':project_id'] = (int) $filters['project_id'];
        }

        // type — строка или массив
        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
            $types = array_filter($types, static fn($t) => in_array($t, self::ALLOWED_TYPES, true));

            if ($types) {
                $placeholders = [];
                foreach (array_values($types) as $i => $type) {
                    $key                = ':type_' . $i;
                    $placeholders[]     = $key;
                    $params[$key]       = $type;
                }
                $where[] = 't.type IN (' . implode(',', $placeholders) . ')';
            }
        }

        // date_from
        if (!empty($filters['date_from'])) {
            $where[]             = 't.transaction_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        // date_to
        if (!empty($filters['date_to'])) {
            $where[]           = 't.transaction_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        // search по description
        if (!empty($filters['search'])) {
            $where[]          = 't.description LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // category_group — фильтр по группе расходных категорий
        if (!empty($filters['category_group'])) {
            $catGroup = $filters['category_group'];
            $grouped  = self::getExpenseCategoriesGrouped();

            if ($catGroup === 'other_all') {
                // Все НЕ рекламные расходы: category NOT IN (advertising items)
                $adsItems = $grouped['advertising']['items'] ?? [];
                if (!empty($adsItems)) {
                    $notInPh = [];
                    $ni = 0;
                    foreach ($adsItems as $ck => $_) {
                        $ph = ':notcat' . $ni;
                        $notInPh[] = $ph;
                        $params[$ph] = $ck;
                        $ni++;
                    }
                    $where[] = 't.category NOT IN (' . implode(',', $notInPh) . ')';
                }
            } elseif (isset($grouped[$catGroup])) {
                // Конкретная группа: category IN (items)
                $groupItems = $grouped[$catGroup]['items'] ?? [];
                if (!empty($groupItems)) {
                    $inPh = [];
                    $ii = 0;
                    foreach ($groupItems as $ck => $_) {
                        $ph = ':incat' . $ii;
                        $inPh[] = $ph;
                        $params[$ph] = $ck;
                        $ii++;
                    }
                    $where[] = 't.category IN (' . implode(',', $inPh) . ')';
                }
            }
        }

        $whereClause = implode(' AND ', $where);

        // Пагинация
        $page    = max(1, (int) ($filters['page']     ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 50));
        $offset  = ($page - 1) * $perPage;

        // Общее количество
        $countSql  = "SELECT COUNT(*) FROM finance_transactions t WHERE $whereClause";
        $total     = (int) $db->query($countSql, $params)->fetchColumn();

        // Данные — LIMIT/OFFSET через sprintf (PDO не поддерживает named params для LIMIT)
        $sql = sprintf(
            "SELECT t.* FROM finance_transactions t WHERE %s ORDER BY t.transaction_date DESC, t.id DESC LIMIT %d OFFSET %d",
            $whereClause,
            $perPage,
            $offset
        );

        $items = $db->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items'      => $items,
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
            ],
        ];
    }

    /**
     * Одна транзакция по ID
     */
    public static function getById(int $id): ?array {
        $stmt = self::db()->query(
            'SELECT * FROM finance_transactions WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    /**
     * Добавить транзакцию
     *
     * @throws InvalidArgumentException при невалидном type
     */
    public static function add(array $data): int {
        if (!in_array($data['type'] ?? '', self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Недопустимый тип транзакции: ' . ($data['type'] ?? 'null'));
        }

        $db = self::db();
        $db->query(
            "INSERT INTO finance_transactions
                (project_id, type, category, description, amount_uah, transaction_date,
                 source_type, parent_transaction_id, crm_deal_id, card_id, employee_id, payroll_id, ads_data_id, notes, created_by, created_at)
             VALUES
                (:project_id, :type, :category, :description, :amount_uah, :transaction_date,
                 :source_type, :parent_transaction_id, :crm_deal_id, :card_id, :employee_id, :payroll_id, :ads_data_id, :notes, :created_by, NOW())",
            [
                ':project_id'            => (int) $data['project_id'],
                ':type'                  => $data['type'],
                ':category'              => $data['category']              ?? null,
                ':description'           => $data['description']           ?? '',
                ':amount_uah'            => (float) ($data['amount_uah']   ?? 0),
                ':transaction_date'      => $data['transaction_date']       ?? date('Y-m-d'),
                ':source_type'           => $data['source_type']            ?? 'manual',
                ':parent_transaction_id' => $data['parent_transaction_id']  ?? null,
                ':crm_deal_id'           => $data['crm_deal_id']            ?? null,
                ':card_id'               => $data['card_id']                ?? null,
                ':employee_id'           => $data['employee_id']            ?? null,
                ':payroll_id'            => $data['payroll_id']             ?? null,
                ':ads_data_id'           => $data['ads_data_id']            ?? null,
                ':notes'                 => $data['notes']                  ?? null,
                ':created_by'            => $data['created_by']             ?? null,
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Прочитать настройку из finance_settings.
     * Возвращает float-значение или $default если запись не найдена.
     */
    public static function getSetting(string $key, float $default): float {
        try {
            $row = self::db()->query(
                "SELECT setting_val FROM finance_settings WHERE setting_key = :k LIMIT 1",
                [':k' => $key]
            )->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['setting_val'])) {
                return (float) $row['setting_val'];
            }
        } catch (Throwable $e) {
            error_log('[FinanceTransaction] getSetting error: ' . $e->getMessage());
        }
        return $default;
    }

    /**
     * Создать авто-расходы при входящем платеже.
     * Процент комиссии эквайринга и налогов читается из finance_settings
     * (ключи: acquiring_fee_pct, tax_pct). Дефолты: 2% и 10%.
     *
     * @param int         $parentId  ID родительской income-транзакции
     * @param float       $amount    Сумма родительской транзакции (UAH)
     * @param int         $projectId ID проекта
     * @param string      $date      Дата транзакции (YYYY-MM-DD)
     * @param string|null $createdBy Имя пользователя
     * @return array{fee_id:int, tax_id:int, fee_pct:float, tax_pct:float}
     */
    public static function createAutoExpenses(
        int $parentId,
        float $amount,
        int $projectId,
        string $date,
        ?string $createdBy = null
    ): array {
        $feePct = self::getSetting('acquiring_fee_pct', 2.0);
        $taxPct = self::getSetting('tax_pct', 10.0);

        $feeId = self::add([
            'project_id'            => $projectId,
            'type'                  => 'expense',
            'category'              => 'bank_fees',
            'description'           => 'Комiсiя еквайрингу ' . $feePct . '% вiд #' . $parentId,
            'amount_uah'            => round($amount * ($feePct / 100), 2),
            'transaction_date'      => $date,
            'source_type'           => 'crm_auto',
            'parent_transaction_id' => $parentId,
            'created_by'            => $createdBy,
        ]);

        $taxId = self::add([
            'project_id'            => $projectId,
            'type'                  => 'expense',
            'category'              => 'taxes',
            'description'           => 'Податки та бух. витрати ' . $taxPct . '% вiд #' . $parentId,
            'amount_uah'            => round($amount * ($taxPct / 100), 2),
            'transaction_date'      => $date,
            'source_type'           => 'crm_auto',
            'parent_transaction_id' => $parentId,
            'created_by'            => $createdBy,
        ]);

        return ['fee_id' => $feeId, 'tax_id' => $taxId, 'fee_pct' => $feePct, 'tax_pct' => $taxPct];
    }

    /**
     * Обновить транзакцию (только source_type='manual')
     */
    public static function update(int $id, array $data): bool {
        $existing = self::getById($id);
        if (!$existing || ($existing['source_type'] ?? '') !== 'manual') {
            return false;
        }

        $sets   = [];
        $params = [':id' => $id];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'type' && !in_array($data[$field], self::ALLOWED_TYPES, true)) {
                    continue;
                }
                $sets[]           = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $params[':updated_at'] = date('Y-m-d H:i:s');
        $sql = 'UPDATE finance_transactions SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE id = :id';

        $stmt = self::db()->query($sql, $params);
        return $stmt->rowCount() >= 0;
    }

    /**
     * Мягкое удаление (только source_type='manual').
     * Если тип транзакции income/income_extra — каскадно удаляет авто-расходы.
     */
    public static function softDelete(int $id): bool {
        $existing = self::getById($id);
        if (!$existing || ($existing['source_type'] ?? '') !== 'manual') {
            return false;
        }

        $db   = self::db();
        $stmt = $db->query(
            "UPDATE finance_transactions SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL",
            [':id' => $id]
        );

        if ($stmt->rowCount() > 0) {
            // Каскадное удаление авто-расходов если это доход
            if (in_array($existing['type'] ?? '', ['income', 'income_extra'], true)) {
                $db->query(
                    "UPDATE finance_transactions
                     SET deleted_at = NOW()
                     WHERE parent_transaction_id = :parent_id
                       AND source_type = 'crm_auto'
                       AND deleted_at IS NULL",
                    [':parent_id' => $id]
                );
            }
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // AGGREGATES
    // -------------------------------------------------------------------------

    /**
     * Сводка по проекту: доходы, расходы, прибыль, маржа, разбивки
     *
     * @return array{by_type:array,by_category:array,total_income:float,total_expenses:float,profit:float,margin:float|null}
     */
    public static function getAggregates(int $projectId): array {
        $db = self::db();

        // Итоги
        $totals = $db->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type IN ('income','income_extra') THEN amount_uah ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type IN ('expense','card_topup','salary') THEN amount_uah ELSE 0 END), 0) AS total_expenses
             FROM finance_transactions
             WHERE project_id = :pid AND deleted_at IS NULL",
            [':pid' => $projectId]
        )->fetch(PDO::FETCH_ASSOC);

        $income   = (float) ($totals['total_income']   ?? 0);
        $expenses = (float) ($totals['total_expenses'] ?? 0);
        $profit   = $income - $expenses;

        // По типам
        $byTypeRows = $db->query(
            "SELECT type, COALESCE(SUM(amount_uah), 0) AS total
             FROM finance_transactions
             WHERE project_id = :pid AND deleted_at IS NULL
             GROUP BY type",
            [':pid' => $projectId]
        )->fetchAll(PDO::FETCH_ASSOC);

        $byType = [];
        foreach ($byTypeRows as $r) {
            $byType[$r['type']] = (float) $r['total'];
        }

        // По категориям (только расходы)
        $byCatRows = $db->query(
            "SELECT category, COALESCE(SUM(amount_uah), 0) AS total
             FROM finance_transactions
             WHERE project_id = :pid AND type = 'expense' AND deleted_at IS NULL
             GROUP BY category",
            [':pid' => $projectId]
        )->fetchAll(PDO::FETCH_ASSOC);

        $byCategory = [];
        foreach ($byCatRows as $r) {
            $key = $r['category'] ?? 'other';
            $byCategory[$key] = (float) $r['total'];
        }

        return [
            'by_type'       => $byType,
            'by_category'   => $byCategory,
            'total_income'  => $income,
            'total_expenses'=> $expenses,
            'profit'        => $profit,
            'margin'        => $income > 0 ? round($profit / $income * 100, 2) : null,
        ];
    }

    // -------------------------------------------------------------------------
    // CRM INTEGRATION
    // -------------------------------------------------------------------------

    /**
     * Создать транзакцию из данных CRM сделки.
     *
     * Защита от дублей: если транзакция с таким crm_deal_id (income/income_extra)
     * уже существует — возвращается её ID без создания новой.
     *
     * Маппинг проекта: использует PROJECT_ALIAS_MAP (например 'BMW' → 'BMW 330E HYBRID').
     *
     * Сумма: берётся amount_uah или deal_price. Если 0 или отрицательная — транзакция не создаётся.
     *
     * @param array $deal Поля: deal_id, amount_uah (или deal_price), deal_project, created_at
     * @return int Новый/существующий ID транзакции, 0 при ошибке
     */
    public static function createFromCrm(array $deal): int {
        $dealId = (string) ($deal['deal_id'] ?? '');
        if ($dealId === '') {
            error_log('[FinanceTransaction] createFromCrm: пустой deal_id');
            return 0;
        }

        // Защита от дублей — если транзакция уже существует, вернуть её ID
        $existingId = self::findByCrmDealId($dealId);
        if ($existingId > 0) {
            return $existingId;
        }

        // Поиск проекта с учётом alias map
        $projectName = (string) ($deal['deal_project'] ?? '');
        $projectId   = self::resolveProjectIdByDealProject($projectName);

        if ($projectId === 0) {
            error_log('[FinanceTransaction] createFromCrm: проект не найден для deal_id='
                . $dealId . ', deal_project="' . $projectName . '"');
            return 0;
        }

        // Проверка суммы
        $amountUah = (float) ($deal['amount_uah'] ?? $deal['deal_price'] ?? 0);
        if ($amountUah <= 0) {
            error_log('[FinanceTransaction] createFromCrm: некорректная сумма deal_id='
                . $dealId . ', amount_uah=' . $amountUah);
            return 0;
        }

        $date = date('Y-m-d', strtotime($deal['created_at'] ?? 'now'));

        return self::add([
            'project_id'       => $projectId,
            'type'             => 'income',
            'source_type'      => 'crm_auto',
            'crm_deal_id'      => $dealId,
            'description'      => 'Оплата CRM #' . $dealId,
            'amount_uah'       => $amountUah,
            'transaction_date' => $date,
        ]);
    }

    /**
     * Проверить существование транзакции по crm_deal_id
     */
    public static function isDuplicateCrm(string $dealId): bool {
        return self::findByCrmDealId($dealId) > 0;
    }

    /**
     * Найти ID существующей income-транзакции по crm_deal_id
     */
    public static function findByCrmDealId(string $dealId): int {
        if ($dealId === '') {
            return 0;
        }
        $row = self::db()->query(
            "SELECT id FROM finance_transactions
             WHERE crm_deal_id = :id
               AND type IN ('income','income_extra')
               AND deleted_at IS NULL
             LIMIT 1",
            [':id' => $dealId]
        )->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int) $row['id'] : 0;
    }

    // -------------------------------------------------------------------------
    // MANUAL COSTS INTEGRATION (sync manual_costs.php → finance_transactions)
    // -------------------------------------------------------------------------

    /**
     * Плоский список категорий [key => label] (обратная совместимость).
     * Используется в старых формах (manual_costs.php) и JSON ответах.
     * @return array<string,string> ['meta' => 'Meta Ads...', 'car_purchase' => 'Покупка авто...', ...]
     */
    public static function getExpenseCategories(): array {
        return self::getFlatCategories();
    }

    /**
     * Полная группированная структура категорий.
     * Возвращает массив с ключами групп (advertising, production, operations, team, other),
     * каждая группа имеет label и items (key => label).
     */
    public static function getExpenseCategoriesGrouped(): array {
        return self::EXPENSE_CATEGORIES_GROUPED;
    }

    /**
     * Плоский массив всех категорий [key => label].
     * Удобен для валидации и поиска label по ключу.
     */
    public static function getFlatCategories(): array {
        static $flat = null;
        if ($flat !== null) return $flat;

        $flat = [];
        foreach (self::EXPENSE_CATEGORIES_GROUPED as $group) {
            foreach ($group['items'] as $key => $label) {
                $flat[$key] = $label;
            }
        }
        return $flat;
    }

    /**
     * Название категории по ключу. Возвращает 'Інше' если ключ неизвестен.
     */
    public static function getCategoryLabel(string $key): string {
        $flat = self::getFlatCategories();
        return $flat[$key] ?? 'Інше';
    }

    /**
     * Ключ группы по категории (advertising, production, operations, team, other).
     *
     * Поддерживает:
     * 1. Точные ключи из EXPENSE_CATEGORIES_GROUPED (meta, car_purchase, ...)
     * 2. LEGACY категории "Реклама PLATFORM" (от старой автосинхронизации Facebook Ads)
     * 3. LEGACY категории на английском/украинском
     *
     * Возвращает 'other' если категория неизвестна.
     */
    public static function getCategoryGroup(string $key): string {
        static $categoryToGroup = null;
        if ($categoryToGroup === null) {
            $categoryToGroup = [];
            foreach (self::EXPENSE_CATEGORIES_GROUPED as $groupKey => $group) {
                foreach ($group['items'] as $catKey => $label) {
                    $categoryToGroup[$catKey] = $groupKey;
                }
            }
        }

        // 1. Точное совпадение с новым справочником
        if (isset($categoryToGroup[$key])) {
            return $categoryToGroup[$key];
        }

        if ($key === '' || $key === null) {
            return 'other';
        }

        $lower = mb_strtolower(trim($key));

        // 2. LEGACY: "Реклама PLATFORM" (instagram, facebook, viber, audience_network,
        //    threads, messenger) - от автосинхронизации Facebook Ads
        if (mb_stripos($lower, 'реклама') === 0 || mb_stripos($lower, 'ad_') === 0) {
            return 'advertising';
        }

        // 3. LEGACY: отдельные слова платформ
        $adKeywords = ['instagram', 'facebook', 'meta', 'google', 'tiktok',
                       'viber', 'sms', 'email', 'messenger', 'audience', 'threads'];
        foreach ($adKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                return 'advertising';
            }
        }

        // 4. LEGACY: команда
        if (mb_strpos($lower, 'зарплат') !== false || mb_strpos($lower, 'salary') !== false
            || mb_strpos($lower, 'підрядник') !== false || mb_strpos($lower, 'contractor') !== false) {
            return 'team';
        }

        // 5. LEGACY: податки та комiсii
        if (mb_strpos($lower, 'податк') !== false || mb_strpos($lower, 'tax') !== false
            || mb_strpos($lower, 'bank_fee') !== false || mb_strpos($lower, 'комiсiя') !== false
            || mb_strpos($lower, 'комiсiї') !== false || mb_strpos($lower, 'еквайринг') !== false) {
            return 'fees';
        }

        // 6. LEGACY: операционные
        if (mb_strpos($lower, 'оренда') !== false || mb_strpos($lower, 'офіс') !== false
            || mb_strpos($lower, 'офис') !== false || mb_strpos($lower, 'бухгалтер') !== false) {
            return 'operations';
        }

        // 6. LEGACY: виробництво
        if (mb_strpos($lower, 'авто') !== false || mb_strpos($lower, 'car') !== false
            || mb_strpos($lower, 'машин') !== false) {
            return 'production';
        }

        return 'other';
    }

    /**
     * Метки групп для отображения в UI (ключ → human-readable label).
     * @return array<string,string> ['advertising' => '📣 Реклама', ...]
     */
    public static function getGroupLabels(): array {
        $labels = [];
        foreach (self::EXPENSE_CATEGORIES_GROUPED as $key => $group) {
            $labels[$key] = $group['label'];
        }
        return $labels;
    }

    /**
     * Публичный wrapper для разрешения project_id по имени проекта из ads_data/crm_deals.
     * Использует PROJECT_ALIAS_MAP (BMW → BMW 330E HYBRID и т.д.).
     */
    public static function resolveProjectId(string $dealProject): int {
        return self::resolveProjectIdByDealProject($dealProject);
    }

    /**
     * Создать expense-транзакцию из данных manual_costs (ads_data).
     * Вызывается после успешного AdsData::insertManualCost() из api/handler.php.
     *
     * Если указан card_id - баланс карты уменьшается на сумму расхода.
     *
     * @param int   $adsDataId ID записи в ads_data
     * @param array $costData  Данные из формы: project, amount, date, note, category, utm_source, card_id...
     * @return int ID новой finance транзакции (0 если проект не найден)
     */
    public static function createFromManualCost(int $adsDataId, array $costData): int {
        // 1. Разрешить проект через alias map
        $projectName = (string) ($costData['project'] ?? '');
        $projectId   = self::resolveProjectId($projectName);

        if ($projectId === 0) {
            error_log('[FinanceTransaction] createFromManualCost: проект не найден для ads_data_id='
                . $adsDataId . ', project="' . $projectName . '"');
            return 0;
        }

        // 2. Валидация суммы (уже должна быть в UAH после конвертации в AdsData::insertManualCost)
        $amount = (float) ($costData['amount'] ?? 0);
        if ($amount <= 0) {
            return 0;
        }

        // 3. Категория (если не указана — по умолчанию 'other')
        $category = $costData['category'] ?? 'other';
        if (!array_key_exists($category, self::getFlatCategories())) {
            $category = 'other';
        }

        // 4. Дата
        $date = $costData['date'] ?? date('Y-m-d');

        // 5. Описание: берём из note или формируем по UTM
        $description = trim((string) ($costData['note'] ?? ''));
        if ($description === '') {
            $utmParts = array_filter([
                $costData['utm_source']   ?? null,
                $costData['utm_medium']   ?? null,
                $costData['utm_campaign'] ?? null,
            ]);
            $description = 'Ручные расходы' . (count($utmParts) ? ': ' . implode(' / ', $utmParts) : '');
        }

        // 6. Card ID (опциональный, 0 если не указан)
        $cardId = (int) ($costData['card_id'] ?? 0);
        if ($cardId > 0 && !FinanceCard::exists($cardId)) {
            $cardId = 0; // Невалидный ID - игнорируем
        }

        // 7. Вставка транзакции
        $txId = self::add([
            'project_id'       => $projectId,
            'type'             => 'expense',
            'category'         => $category,
            'description'      => $description,
            'amount_uah'       => $amount,
            'transaction_date' => $date,
            'source_type'      => 'manual',
            'ads_data_id'      => $adsDataId,
            'card_id'          => $cardId > 0 ? $cardId : null,
            'notes'            => 'Синхронизировано из manual_costs #' . $adsDataId,
        ]);

        // 8. Списать с карты если указана
        if ($txId > 0 && $cardId > 0) {
            try {
                FinanceCard::deductBalance($cardId, $amount);
            } catch (Throwable $e) {
                error_log('[FinanceTransaction] deductBalance failed for card=' . $cardId
                    . ', amount=' . $amount . ': ' . $e->getMessage());
            }
        }

        return $txId;
    }

    /**
     * Обновить finance-транзакцию связанную с ads_data.
     * Вызывается после AdsData::updateManualCost().
     *
     * Корректирует балансы карт при изменении суммы или привязки к карте:
     * - Если карта изменилась: вернуть старую сумму на старую карту, списать новую с новой
     * - Если карта та же, но сумма изменилась: корректировать разницу
     *
     * Если транзакции нет (была создана до миграции) - создаёт новую.
     *
     * @return bool true если обновлено или создано, false если ничего не сделано
     */
    public static function updateFromManualCost(int $adsDataId, array $costData): bool {
        $existing = self::findByAdsDataId($adsDataId);

        if ($existing === 0) {
            // Нет связанной транзакции - создать новую
            return self::createFromManualCost($adsDataId, $costData) > 0;
        }

        // Загружаем старую версию для корректировки баланса карты
        $oldRow = self::getById($existing);
        if (!$oldRow) {
            return false;
        }
        $oldAmount = (float) ($oldRow['amount_uah'] ?? 0);
        $oldCardId = (int)   ($oldRow['card_id']    ?? 0);

        // Разрешить проект
        $projectName = (string) ($costData['project'] ?? '');
        $projectId   = self::resolveProjectId($projectName);
        if ($projectId === 0) {
            return false;
        }

        $category = $costData['category'] ?? 'other';
        if (!array_key_exists($category, self::getFlatCategories())) {
            $category = 'other';
        }

        $newAmount = (float) ($costData['amount'] ?? 0);
        $date      = $costData['date'] ?? date('Y-m-d');

        $description = trim((string) ($costData['note'] ?? ''));
        if ($description === '') {
            $utmParts = array_filter([
                $costData['utm_source']   ?? null,
                $costData['utm_medium']   ?? null,
                $costData['utm_campaign'] ?? null,
            ]);
            $description = 'Ручные расходы' . (count($utmParts) ? ': ' . implode(' / ', $utmParts) : '');
        }

        // Новая карта (0 если не указана или невалидная)
        $newCardId = (int) ($costData['card_id'] ?? 0);
        if ($newCardId > 0 && !FinanceCard::exists($newCardId)) {
            $newCardId = 0;
        }

        // Обновление транзакции
        $stmt = self::db()->query(
            "UPDATE finance_transactions
             SET project_id = :pid, category = :cat, description = :desc,
                 amount_uah = :amt, transaction_date = :dt, card_id = :cid, updated_at = NOW()
             WHERE id = :id AND deleted_at IS NULL",
            [
                ':pid'  => $projectId,
                ':cat'  => $category,
                ':desc' => $description,
                ':amt'  => $newAmount,
                ':dt'   => $date,
                ':cid'  => $newCardId > 0 ? $newCardId : null,
                ':id'   => $existing,
            ]
        );

        // Корректировка балансов карт
        try {
            if ($oldCardId > 0 && $oldCardId !== $newCardId) {
                // Карта изменилась - вернуть старой
                FinanceCard::refundBalance($oldCardId, $oldAmount);
            }
            if ($newCardId > 0) {
                if ($newCardId === $oldCardId) {
                    // Та же карта - корректируем разницу
                    $diff = $newAmount - $oldAmount;
                    if ($diff > 0) {
                        FinanceCard::deductBalance($newCardId, $diff);
                    } elseif ($diff < 0) {
                        FinanceCard::refundBalance($newCardId, abs($diff));
                    }
                } else {
                    // Новая карта - списываем полную сумму
                    FinanceCard::deductBalance($newCardId, $newAmount);
                }
            }
        } catch (Throwable $e) {
            error_log('[FinanceTransaction] updateFromManualCost card balance error: ' . $e->getMessage());
        }

        return $stmt->rowCount() >= 0;
    }

    /**
     * Soft-delete finance-транзакции связанной с ads_data.
     * Вызывается после AdsData::deleteManualCost().
     * Если была привязана к карте - возвращает сумму на карту.
     */
    public static function deleteByAdsDataId(int $adsDataId): bool {
        $existing = self::findByAdsDataId($adsDataId);
        if ($existing === 0) {
            return false;
        }

        // Загружаем данные ДО удаления (чтобы знать карту и сумму для возврата)
        $row = self::getById($existing);

        $stmt = self::db()->query(
            "UPDATE finance_transactions SET deleted_at = NOW()
             WHERE id = :id AND deleted_at IS NULL",
            [':id' => $existing]
        );

        $deleted = $stmt->rowCount() > 0;

        // Возвращаем сумму на карту если была привязана
        if ($deleted && $row && !empty($row['card_id']) && (int)$row['card_id'] > 0) {
            $refundAmount = (float) ($row['amount_uah'] ?? 0);
            try {
                FinanceCard::refundBalance((int)$row['card_id'], $refundAmount);
            } catch (Throwable $e) {
                error_log('[FinanceTransaction] deleteByAdsDataId refund error: ' . $e->getMessage());
            }
        }

        return $deleted;
    }

    /**
     * Получить card_id связанной с ads_data finance-транзакции.
     * Используется при редактировании manual_cost - для подгрузки текущей карты в форму.
     */
    public static function getCardIdByAdsDataId(int $adsDataId): int {
        if ($adsDataId <= 0) {
            return 0;
        }
        $row = self::db()->query(
            "SELECT card_id FROM finance_transactions
             WHERE ads_data_id = :aid AND deleted_at IS NULL
             LIMIT 1",
            [':aid' => $adsDataId]
        )->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int) ($row['card_id'] ?? 0) : 0;
    }

    /**
     * Найти ID finance-транзакции по ads_data_id.
     */
    public static function findByAdsDataId(int $adsDataId): int {
        if ($adsDataId <= 0) {
            return 0;
        }
        $row = self::db()->query(
            "SELECT id FROM finance_transactions
             WHERE ads_data_id = :aid AND deleted_at IS NULL
             LIMIT 1",
            [':aid' => $adsDataId]
        )->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int) $row['id'] : 0;
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    /**
     * Разрешить project_id по значению crm_deals.deal_project
     * Использует PROJECT_ALIAS_MAP, а если нет — ищет по прямому совпадению.
     */
    private static function resolveProjectIdByDealProject(string $dealProject): int {
        if ($dealProject === '') {
            return 0;
        }

        $key = strtoupper(trim($dealProject));

        // 1. Alias map (основной путь)
        if (isset(self::PROJECT_ALIAS_MAP[$key])) {
            return self::getProjectIdByName(self::PROJECT_ALIAS_MAP[$key]);
        }

        // 2. Fallback — прямое совпадение
        return self::getProjectIdByName($dealProject);
    }

    /**
     * Найти project_id по имени (без учета регистра)
     */
    private static function getProjectIdByName(string $name): int {
        if ($name === '') {
            return 0;
        }

        $row = self::db()->query(
            'SELECT id FROM finance_projects WHERE UPPER(name) = UPPER(:name) LIMIT 1',
            [':name' => $name]
        )->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? (int) $row['id'] : 0;
    }
}
