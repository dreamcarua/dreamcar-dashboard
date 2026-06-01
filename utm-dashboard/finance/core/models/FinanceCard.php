<?php
// === FinanceCard.php ===
// finance/core/models/FinanceCard.php
// НАЗНАЧЕНИЕ: CRUD для finance_cards + поповнення балансу
// СВЯЗИ: core/Database.php, finance_transactions
// РАЗМЕР: ~200 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinanceCard
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Усi картки WHERE status != 'archived'
     * Додає поле balance_pct = balance_uah / limit_uah * 100
     */
    public function getAll(): array
    {
        $stmt = $this->db->query(
            "SELECT *,
                CASE
                    WHEN limit_uah > 0 THEN ROUND(balance_uah / limit_uah * 100, 2)
                    ELSE 0
                END AS balance_pct
             FROM finance_cards
             WHERE status != 'archived'
             ORDER BY bank_name ASC, last4 ASC"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Одна картка за ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT *,
                CASE
                    WHEN limit_uah > 0 THEN ROUND(balance_uah / limit_uah * 100, 2)
                    ELSE 0
                END AS balance_pct
             FROM finance_cards
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Додати нову картку
     * Обов'язковi: bank_name, last4
     * Необов'язковi: owner_name, location, platforms, balance_uah, limit_uah, notes
     */
    public function add(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO finance_cards
                (bank_name, last4, owner_name, location, platforms, balance_uah, limit_uah, notes, status, created_at)
             VALUES
                (:bank_name, :last4, :owner_name, :location, :platforms, :balance_uah, :limit_uah, :notes, 'active', NOW())"
        );
        $stmt->execute([
            ':bank_name'   => $data['bank_name'],
            ':last4'       => $data['last4'],
            ':owner_name'  => $data['owner_name'] ?? null,
            ':location'    => $data['location'] ?? null,
            ':platforms'   => $data['platforms'] ?? null,
            ':balance_uah' => $data['balance_uah'] ?? 0,
            ':limit_uah'   => $data['limit_uah'] ?? 0,
            ':notes'       => $data['notes'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Оновити картку
     * Дозволенi поля: bank_name, last4, owner_name, location, platforms,
     *                 balance_uah, limit_uah, status, notes
     */
    public function update(int $id, array $data): bool
    {
        $allowed = ['bank_name', 'last4', 'owner_name', 'location', 'platforms',
                    'balance_uah', 'limit_uah', 'status', 'notes'];
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

        $sql = "UPDATE finance_cards SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Поповнення картки
     * Оновлює balance_uah, вставляє запис у finance_transactions
     * Повертає ['transaction_id' => int, 'new_balance' => float]
     */
    public function topup(int $id, float $amountUah, int $projectId, string $description = ''): array
    {
        // Оновлюємо баланс картки
        $stmtUpd = $this->db->prepare(
            "UPDATE finance_cards
             SET balance_uah = balance_uah + :amount, updated_at = NOW()
             WHERE id = :id"
        );
        $stmtUpd->execute([':amount' => $amountUah, ':id' => $id]);

        // Отримуємо новий баланс
        $stmtBal = $this->db->prepare(
            "SELECT balance_uah FROM finance_cards WHERE id = :id LIMIT 1"
        );
        $stmtBal->execute([':id' => $id]);
        $newBalance = (float)$stmtBal->fetchColumn();

        // Вставляємо транзакцiю
        $desc = $description ?: 'Поповнення картки';
        $stmtTx = $this->db->prepare(
            "INSERT INTO finance_transactions
                (type, project_id, amount_uah, card_id, description, transaction_date, created_at)
             VALUES
                ('card_topup', :project_id, :amount_uah, :card_id, :description, :transaction_date, NOW())"
        );
        $stmtTx->execute([
            ':project_id'       => $projectId,
            ':amount_uah'       => $amountUah,
            ':card_id'          => $id,
            ':description'      => $desc,
            ':transaction_date' => date('Y-m-d'),
        ]);
        $txId = (int)$this->db->lastInsertId();

        return [
            'transaction_id' => $txId,
            'new_balance'    => $newBalance,
        ];
    }

    /**
     * Списати суму з балансу (мiнiмум 0)
     */
    public function deductBalance(int $id, float $amount): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE finance_cards
             SET balance_uah = GREATEST(0, balance_uah - :amount), updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([':amount' => $amount, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
