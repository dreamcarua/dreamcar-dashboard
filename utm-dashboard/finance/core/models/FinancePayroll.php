<?php
// === FinancePayroll.php ===
// finance/core/models/FinancePayroll.php
// НАЗНАЧЕНИЕ: CRUD для finance_payroll + вiдмiтка виплачено
// СВЯЗИ: core/Database.php, finance_transactions, finance_employees
// РАЗМЕР: ~200 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinancePayroll
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Список записiв зарплати з пагiнацiєю
     * Filters: employee_id, project_id, status, month ('Y-m')
     * Повертає ['items' => [], 'pagination' => [...]]
     */
    public function getList(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'p.employee_id = :employee_id';
            $params[':employee_id'] = (int)$filters['employee_id'];
        }
        if (!empty($filters['project_id'])) {
            $where[] = 'p.project_id = :project_id';
            $params[':project_id'] = (int)$filters['project_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['month'])) {
            // Перетворюємо 'Y-m' у першу дату мiсяця
            $monthDate = $filters['month'] . '-01';
            $where[] = 'p.period_month = :period_month';
            $params[':period_month'] = $monthDate;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $perPage = (int)($filters['per_page'] ?? 50);
        $page    = max(1, (int)($filters['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        // Загальна кiлькiсть
        $countSql = "SELECT COUNT(*) FROM finance_payroll p {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Данi з JOIN
        $dataSql = "SELECT p.*,
                        e.name AS employee_name
                    FROM finance_payroll p
                    LEFT JOIN finance_employees e ON e.id = p.employee_id
                    {$whereClause}
                    ORDER BY p.created_at DESC
                    LIMIT {$perPage} OFFSET {$offset}";
        $dataStmt = $this->db->prepare($dataSql);
        $dataStmt->execute($params);
        $items = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);

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
     * Один запис за ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, e.name AS employee_name
             FROM finance_payroll p
             LEFT JOIN finance_employees e ON e.id = p.employee_id
             WHERE p.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Додати запис зарплати
     * Обов'язковi: employee_id, amount_uah
     * Необов'язковi: project_id, period_month, notes, created_by
     */
    public function add(array $data): int
    {
        // period_month — перший день мiсяця
        $periodMonth = null;
        if (!empty($data['period_month'])) {
            $periodMonth = date('Y-m-01', strtotime($data['period_month']));
        }

        $stmt = $this->db->prepare(
            "INSERT INTO finance_payroll
                (employee_id, amount_uah, project_id, period_month, notes, created_by, status, created_at)
             VALUES
                (:employee_id, :amount_uah, :project_id, :period_month, :notes, :created_by, 'pending', NOW())"
        );
        $stmt->execute([
            ':employee_id'  => (int)$data['employee_id'],
            ':amount_uah'   => (float)$data['amount_uah'],
            ':project_id'   => $data['project_id'] ?? null,
            ':period_month' => $periodMonth,
            ':notes'        => $data['notes'] ?? null,
            ':created_by'   => $data['created_by'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Вiдмiтити як виплачено
     * 1. Отримати запис payroll
     * 2. UPDATE status='paid', paid_at=NOW()
     * 3. INSERT у finance_transactions
     * 4. UPDATE finance_payroll SET transaction_id
     * Повертає ['success' => true, 'transaction_id' => int]
     */
    public function markPaid(int $id): array
    {
        // 1. Отримуємо запис
        $payroll = $this->getById($id);
        if (!$payroll) {
            return ['success' => false, 'transaction_id' => null];
        }

        $employeeName = $payroll['employee_name'] ?? ('ID:' . $payroll['employee_id']);
        $projectId    = $payroll['project_id'] ?? 1;

        // 2. Оновлюємо статус
        $stmtUpd = $this->db->prepare(
            "UPDATE finance_payroll
             SET status = 'paid', paid_at = NOW()
             WHERE id = :id"
        );
        $stmtUpd->execute([':id' => $id]);

        // 3. Вставляємо транзакцiю
        $stmtTx = $this->db->prepare(
            "INSERT INTO finance_transactions
                (type, project_id, employee_id, payroll_id, amount_uah,
                 description, transaction_date, source_type, created_at)
             VALUES
                ('salary', :project_id, :employee_id, :payroll_id, :amount_uah,
                 :description, :transaction_date, 'payroll_auto', NOW())"
        );
        $stmtTx->execute([
            ':project_id'       => $projectId,
            ':employee_id'      => $payroll['employee_id'],
            ':payroll_id'       => $id,
            ':amount_uah'       => $payroll['amount_uah'],
            ':description'      => 'ЗП: ' . $employeeName,
            ':transaction_date' => date('Y-m-d'),
        ]);
        $txId = (int)$this->db->lastInsertId();

        // 4. Зберiгаємо transaction_id у payroll
        $stmtLink = $this->db->prepare(
            "UPDATE finance_payroll SET transaction_id = :tx_id WHERE id = :id"
        );
        $stmtLink->execute([':tx_id' => $txId, ':id' => $id]);

        return [
            'success'        => true,
            'transaction_id' => $txId,
        ];
    }

    /**
     * Звiт за мiсяць (тiльки виплаченi)
     * Повертає ['total' => float, 'by_project' => [...]]
     */
    public function getMonthlyReport(string $month): array
    {
        $monthDate = date('Y-m-01', strtotime($month . '-01'));

        $stmt = $this->db->prepare(
            "SELECT project_id, SUM(amount_uah) AS total_amount
             FROM finance_payroll
             WHERE period_month = :month
               AND status = 'paid'
             GROUP BY project_id
             ORDER BY total_amount DESC"
        );
        $stmt->execute([':month' => $monthDate]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $total = 0.0;
        $byProject = [];
        foreach ($rows as $row) {
            $amount      = (float)$row['total_amount'];
            $total      += $amount;
            $byProject[] = [
                'project_id' => $row['project_id'],
                'total'      => $amount,
            ];
        }

        return [
            'total'      => $total,
            'by_project' => $byProject,
        ];
    }
}
