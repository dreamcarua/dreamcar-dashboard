<?php
// === FinancePayroll.php ===
// finance/core/models/FinancePayroll.php
// НАЗНАЧЕНИЕ: CRUD для finance_payroll + отметка выплачено (статические методы)
// СВЯЗИ: core/Database.php, finance_transactions, finance_employees
// РАЗМЕР: ~200 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinancePayroll
{
    private static function db(): Database
    {
        return Database::getInstance();
    }

    public static function getList(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[]              = 'p.employee_id = :employee_id';
            $params[':employee_id'] = (int)$filters['employee_id'];
        }
        if (!empty($filters['project_id'])) {
            $where[]             = 'p.project_id = :project_id';
            $params[':project_id'] = (int)$filters['project_id'];
        }
        if (!empty($filters['status'])) {
            $where[]         = 'p.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['month'])) {
            $where[]               = 'p.period_month = :period_month';
            $params[':period_month'] = $filters['month'] . '-01';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $perPage     = (int)($filters['per_page'] ?? 50);
        $page        = max(1, (int)($filters['page'] ?? 1));
        $offset      = ($page - 1) * $perPage;

        $db    = self::db();
        $total = (int)$db->query("SELECT COUNT(*) FROM finance_payroll p {$whereClause}", $params)->fetchColumn();

        $sql   = sprintf(
            "SELECT p.*, e.name AS employee_name
             FROM finance_payroll p
             LEFT JOIN finance_employees e ON e.id = p.employee_id
             %s ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
            $whereClause, $perPage, $offset
        );
        $items = $db->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'items'      => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public static function getById(int $id): ?array
    {
        $stmt = self::db()->query(
            "SELECT p.*, e.name AS employee_name
             FROM finance_payroll p
             LEFT JOIN finance_employees e ON e.id = p.employee_id
             WHERE p.id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function add(array $data): int
    {
        $periodMonth = null;
        if (!empty($data['period_month'])) {
            $periodMonth = date('Y-m-01', strtotime($data['period_month']));
        }

        $db = self::db();
        $db->execute(
            "INSERT INTO finance_payroll
                (employee_id, amount_uah, project_id, period_month, notes, created_by, status, created_at)
             VALUES
                (:employee_id, :amount_uah, :project_id, :period_month, :notes, :created_by, 'pending', NOW())",
            [
                ':employee_id'  => (int)$data['employee_id'],
                ':amount_uah'   => (float)$data['amount_uah'],
                ':project_id'   => $data['project_id'] ?? null,
                ':period_month' => $periodMonth,
                ':notes'        => $data['notes'] ?? null,
                ':created_by'   => $data['created_by'] ?? null,
            ]
        );
        return (int)$db->lastInsertId();
    }

    public static function markPaid(int $id): array
    {
        $payroll = self::getById($id);
        if (!$payroll) {
            return ['success' => false, 'transaction_id' => null];
        }

        $db           = self::db();
        $employeeName = $payroll['employee_name'] ?? ('ID:' . $payroll['employee_id']);
        $projectId    = $payroll['project_id'] ?? 1;

        $db->execute(
            "UPDATE finance_payroll SET status = 'paid', paid_at = NOW() WHERE id = :id",
            [':id' => $id]
        );

        $db->execute(
            "INSERT INTO finance_transactions
                (type, project_id, employee_id, payroll_id, amount_uah,
                 description, transaction_date, source_type, created_at)
             VALUES
                ('salary', :project_id, :employee_id, :payroll_id, :amount_uah,
                 :description, :transaction_date, 'payroll_auto', NOW())",
            [
                ':project_id'       => $projectId,
                ':employee_id'      => $payroll['employee_id'],
                ':payroll_id'       => $id,
                ':amount_uah'       => $payroll['amount_uah'],
                ':description'      => 'ЗП: ' . $employeeName,
                ':transaction_date' => date('Y-m-d'),
            ]
        );
        $txId = (int)$db->lastInsertId();

        $db->execute(
            "UPDATE finance_payroll SET transaction_id = :tx_id WHERE id = :id",
            [':tx_id' => $txId, ':id' => $id]
        );

        return ['success' => true, 'transaction_id' => $txId];
    }

    public static function getMonthlyReport(string $month): array
    {
        $monthDate = date('Y-m-01', strtotime($month . '-01'));
        $rows      = self::db()->query(
            "SELECT project_id, SUM(amount_uah) AS total_amount
             FROM finance_payroll
             WHERE period_month = :month AND status = 'paid'
             GROUP BY project_id",
            [':month' => $monthDate]
        )->fetchAll(\PDO::FETCH_ASSOC);

        $total = 0;
        foreach ($rows as $r) { $total += (float)$r['total_amount']; }

        return ['total' => $total, 'by_project' => $rows];
    }
}
