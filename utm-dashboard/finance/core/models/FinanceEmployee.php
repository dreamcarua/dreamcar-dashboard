<?php
// === FinanceEmployee.php ===
// finance/core/models/FinanceEmployee.php
// НАЗНАЧЕНИЕ: CRUD для finance_employees
// СВЯЗИ: core/Database.php, finance_payroll
// РАЗМЕР: ~150 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinanceEmployee
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Список спiвробiтникiв
     * $activeOnly=true — тiльки активнi (active=1)
     * Додає paid_current_month та paid_total з finance_payroll
     */
    public function getAll(bool $activeOnly = false): array
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
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Один спiвробiтник за ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM finance_employees WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Додати спiвробiтника
     * Обов'язкове: name
     * Необов'язковi: role_name, employee_type, fixed_salary, notes
     */
    public function add(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO finance_employees
                (name, role_name, employee_type, fixed_salary, notes, active, created_at)
             VALUES
                (:name, :role_name, :employee_type, :fixed_salary, :notes, 1, NOW())"
        );
        $stmt->execute([
            ':name'          => $data['name'],
            ':role_name'     => $data['role_name'] ?? null,
            ':employee_type' => $data['employee_type'] ?? 'staff',
            ':fixed_salary'  => $data['fixed_salary'] ?? null,
            ':notes'         => $data['notes'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Оновити спiвробiтника
     * Дозволенi поля: name, role_name, employee_type, fixed_salary, active, notes
     */
    public function update(int $id, array $data): bool
    {
        $allowed = ['name', 'role_name', 'employee_type', 'fixed_salary', 'active', 'notes'];
        $sets = [];
        $params = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sql = "UPDATE finance_employees SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Перемикач активностi: 0 -> 1 або 1 -> 0
     */
    public function toggleActive(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE finance_employees
             SET active = IF(active = 1, 0, 1), updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
