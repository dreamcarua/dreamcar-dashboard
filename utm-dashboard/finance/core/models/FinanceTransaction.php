<?php
// === FinanceTransaction.php ===
// finance/core/models/FinanceTransaction.php
// НАЗНАЧЕНИЕ: CRUD для finance_transactions + агрегаты + CRM интеграция
// СВЯЗИ: core/Database.php, finance_projects, finance_cards
// РАЗМЕР: ~450 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinanceTransaction {

    private const ALLOWED_TYPES = [
        'income', 'income_extra', 'expense', 'card_topup', 'salary',
    ];

    private const EDITABLE_FIELDS = [
        'type', 'category', 'description', 'amount_uah', 'transaction_date', 'card_id', 'notes',
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
                 source_type, crm_deal_id, card_id, employee_id, payroll_id, notes, created_by, created_at)
             VALUES
                (:project_id, :type, :category, :description, :amount_uah, :transaction_date,
                 :source_type, :crm_deal_id, :card_id, :employee_id, :payroll_id, :notes, :created_by, NOW())",
            [
                ':project_id'       => (int) $data['project_id'],
                ':type'             => $data['type'],
                ':category'         => $data['category']         ?? null,
                ':description'      => $data['description']      ?? '',
                ':amount_uah'       => (float) ($data['amount_uah'] ?? 0),
                ':transaction_date' => $data['transaction_date']  ?? date('Y-m-d'),
                ':source_type'      => $data['source_type']       ?? 'manual',
                ':crm_deal_id'      => $data['crm_deal_id']       ?? null,
                ':card_id'          => $data['card_id']           ?? null,
                ':employee_id'      => $data['employee_id']       ?? null,
                ':payroll_id'       => $data['payroll_id']        ?? null,
                ':notes'            => $data['notes']             ?? null,
                ':created_by'       => $data['created_by']        ?? null,
            ]
        );

        return (int) $db->lastInsertId();
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
     * Мягкое удаление (только source_type='manual')
     */
    public static function softDelete(int $id): bool {
        $existing = self::getById($id);
        if (!$existing || ($existing['source_type'] ?? '') !== 'manual') {
            return false;
        }

        $stmt = self::db()->query(
            "UPDATE finance_transactions SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL",
            [':id' => $id]
        );
        return $stmt->rowCount() > 0;
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
     * Создать транзакцию из данных CRM сделки
     *
     * @param array $deal Поля: deal_id, amount_uah (или deal_price), deal_project, created_at
     * @return int Новый ID транзакции, 0 при ошибке
     */
    public static function createFromCrm(array $deal): int {
        $projectId = self::getProjectIdByName($deal['deal_project'] ?? '');

        if ($projectId === 0) {
            error_log('[FinanceTransaction] Warning: проект не найден для CRM сделки deal_id='
                . ($deal['deal_id'] ?? 'unknown') . ', project="' . ($deal['deal_project'] ?? '') . '"');
            return 0;
        }

        $amountUah = (float) ($deal['amount_uah'] ?? $deal['deal_price'] ?? 0);
        $date      = date('Y-m-d', strtotime($deal['created_at'] ?? 'now'));
        $dealId    = (string) ($deal['deal_id'] ?? '');

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
     * Проверить дубликат по crm_deal_id
     */
    public static function isDuplicateCrm(string $dealId): bool {
        $row = self::db()->query(
            "SELECT 1 FROM finance_transactions
             WHERE crm_deal_id = :id
               AND type IN ('income','income_extra')
               AND deleted_at IS NULL
             LIMIT 1",
            [':id' => $dealId]
        )->fetch(PDO::FETCH_ASSOC);

        return $row !== false;
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

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
