<?php
// === FinanceEmployee.php ===
// finance/core/models/FinanceEmployee.php
// НАЗНАЧЕНИЕ: CRUD для finance_employees (статические методы)
// СВЯЗИ: core/Database.php, finance_payroll
// РАЗМЕР: ~130 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinanceEmployee
{
    private static function db(): Database
    {
        return Database::getInstance();
    }

    public static function getAll(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE e.active = 1' : '';
        $sql = "SELECT e.*,
                    COALESCE(
                        (SELECT SUM(p.amount_uah)
                         FROM finance_payroll p
                         WHERE p.employee_id = e.id
                           AND p.status = 'paid'
                           AND p.period_month >= DATE_FORMAT(NOW(), '%Y-%m-01')),
                        0
                    ) AS paid_current_month,
                    COALESCE(
                        (SELECT SUM(p.amount_uah)
                         FROM finance_payroll p
                         WHERE p.employee_id = e.id
                           AND p.status = 'paid'),
                        0
                    ) AS paid_total
                FROM finance_employees e
                {$where}
                ORDER BY e.name ASC";
        return self::db()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function getById(int $id): ?array
    {
        $stmt = self::db()->query(
            "SELECT * FROM finance_employees WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function add(array $data): int
    {
        $db = self::db();
        $db->execute(
            "INSERT INTO finance_employees
                (name, role_name, employee_type, fixed_salary, notes, active, created_at)
             VALUES
                (:name, :role_name, :employee_type, :fixed_salary, :notes, 1, NOW())",
            [
                ':name'          => $data['name'],
                ':role_name'     => $data['role_name'] ?? null,
                ':employee_type' => $data['employee_type'] ?? 'staff',
                ':fixed_salary'  => $data['fixed_salary'] ?? null,
                ':notes'         => $data['notes'] ?? null,
            ]
        );
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $allowed = ['name', 'role_name', 'employee_type', 'fixed_salary', 'active', 'notes'];
        $sets    = [];
        $params  = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]          = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        if (empty($sets)) return false;

        $sql = "UPDATE finance_employees SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id";
        self::db()->execute($sql, $params);
        return true;
    }

    public static function toggleActive(int $id): bool
    {
        self::db()->execute(
            "UPDATE finance_employees SET active = IF(active = 1, 0, 1), updated_at = NOW() WHERE id = :id",
            [':id' => $id]
        );
        return true;
    }
}
