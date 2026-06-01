<?php
// === FinanceProject.php ===
// finance/core/models/FinanceProject.php
// НАЗНАЧЕНИЕ: CRUD для finance_projects + расчет P&L
// СВЯЗИ: core/Database.php, finance_transactions таблица
// РАЗМЕР: ~250 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinanceProject {

    private static function db(): Database {
        return Database::getInstance();
    }

    /**
     * Все проекты с агрегатами доходов/расходов из транзакций
     */
    public static function getAll(): array {
        $db = self::db();

        $sql = "
            SELECT
                p.*,
                COALESCE(SUM(CASE WHEN t.type IN ('income','income_extra') AND t.deleted_at IS NULL THEN t.amount_uah ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN t.type IN ('expense','card_topup','salary') AND t.deleted_at IS NULL THEN t.amount_uah ELSE 0 END), 0) AS expenses
            FROM finance_projects p
            LEFT JOIN finance_transactions t ON t.project_id = p.id
            GROUP BY p.id
            ORDER BY p.date_start DESC
        ";

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $income   = (float) $row['income'];
            $expenses = (float) $row['expenses'];
            $profit   = $income - $expenses;

            $row['income']   = $income;
            $row['expenses'] = $expenses;
            $row['profit']   = $profit;
            $row['margin']   = $income > 0 ? round($profit / $income * 100, 2) : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Проект по ID
     */
    public static function getById(int $id): ?array {
        $db   = self::db();
        $stmt = $db->query(
            'SELECT * FROM finance_projects WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Поиск по точному названию (без учета регистра)
     */
    public static function getByName(string $name): ?array {
        $db   = self::db();
        $stmt = $db->query(
            'SELECT * FROM finance_projects WHERE UPPER(name) = UPPER(:name) LIMIT 1',
            [':name' => $name]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * P&L по проекту
     * @return array{income:float,expenses:float,profit:float,margin:float|null,by_type:array,by_category:array}
     */
    public static function getPL(int $id): array {
        $db = self::db();

        // Итоговые суммы по доходам и расходам
        $totals = $db->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type IN ('income','income_extra') THEN amount_uah ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN type IN ('expense','card_topup','salary') THEN amount_uah ELSE 0 END), 0) AS expenses
             FROM finance_transactions
             WHERE project_id = :id AND deleted_at IS NULL",
            [':id' => $id]
        )->fetch(PDO::FETCH_ASSOC);

        $income   = (float) ($totals['income']   ?? 0);
        $expenses = (float) ($totals['expenses'] ?? 0);
        $profit   = $income - $expenses;
        $margin   = $income > 0 ? round($profit / $income * 100, 2) : null;

        // Разбивка по типам транзакций
        $byTypeRows = $db->query(
            "SELECT type, COALESCE(SUM(amount_uah), 0) AS total
             FROM finance_transactions
             WHERE project_id = :id AND deleted_at IS NULL
             GROUP BY type",
            [':id' => $id]
        )->fetchAll(PDO::FETCH_ASSOC);

        $byType = [];
        foreach ($byTypeRows as $r) {
            $byType[$r['type']] = (float) $r['total'];
        }

        // Разбивка по категориям (только расходы)
        $byCatRows = $db->query(
            "SELECT category, COALESCE(SUM(amount_uah), 0) AS total
             FROM finance_transactions
             WHERE project_id = :id AND type = 'expense' AND deleted_at IS NULL
             GROUP BY category",
            [':id' => $id]
        )->fetchAll(PDO::FETCH_ASSOC);

        $byCategory = [];
        foreach ($byCatRows as $r) {
            $key = $r['category'] ?? 'other';
            $byCategory[$key] = (float) $r['total'];
        }

        return [
            'income'      => $income,
            'expenses'    => $expenses,
            'profit'      => $profit,
            'margin'      => $margin,
            'by_type'     => $byType,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Добавить проект
     */
    public static function add(array $data): int {
        $db = self::db();

        $db->query(
            "INSERT INTO finance_projects (name, status, date_start, date_end, budget_plan, notes, created_at)
             VALUES (:name, :status, :date_start, :date_end, :budget_plan, :notes, NOW())",
            [
                ':name'        => $data['name']        ?? '',
                ':status'      => $data['status']      ?? 'active',
                ':date_start'  => $data['date_start']  ?? null,
                ':date_end'    => $data['date_end']    ?? null,
                ':budget_plan' => $data['budget_plan'] ?? null,
                ':notes'       => $data['notes']       ?? null,
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Обновить проект (только разрешенные поля)
     */
    public static function update(int $id, array $data): bool {
        $allowed = ['name', 'status', 'date_start', 'date_end', 'budget_plan', 'notes'];
        $sets    = [];
        $params  = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]           = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $params[':updated_at'] = date('Y-m-d H:i:s');
        $sql = 'UPDATE finance_projects SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE id = :id';

        $stmt = $db = self::db();
        $stmt = $db->query($sql, $params);
        return $stmt->rowCount() >= 0;
    }
}
