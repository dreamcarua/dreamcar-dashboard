<?php
// === FinanceCard.php ===
// finance/core/models/FinanceCard.php
// НАЗНАЧЕНИЕ: CRUD для finance_cards + пополнение баланса (статические методы)
// СВЯЗИ: core/Database.php, finance_transactions
// РАЗМЕР: ~150 строк

require_once __DIR__ . '/../../../core/Database.php';

class FinanceCard
{
    private static function db(): Database
    {
        return Database::getInstance();
    }

    public static function getAll(): array
    {
        $stmt = self::db()->query(
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

    public static function getById(int $id): ?array
    {
        $stmt = self::db()->query(
            "SELECT *,
                CASE
                    WHEN limit_uah > 0 THEN ROUND(balance_uah / limit_uah * 100, 2)
                    ELSE 0
                END AS balance_pct
             FROM finance_cards WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function add(array $data): int
    {
        $db = self::db();
        $db->execute(
            "INSERT INTO finance_cards
                (bank_name, last4, owner_name, location, platforms, balance_uah, limit_uah, notes, status, created_at)
             VALUES
                (:bank_name, :last4, :owner_name, :location, :platforms, :balance_uah, :limit_uah, :notes, 'active', NOW())",
            [
                ':bank_name'   => $data['bank_name'],
                ':last4'       => $data['last4'],
                ':owner_name'  => $data['owner_name'] ?? null,
                ':location'    => $data['location']   ?? null,
                ':platforms'   => $data['platforms']  ?? null,
                ':balance_uah' => $data['balance_uah'] ?? 0,
                ':limit_uah'   => $data['limit_uah']   ?? 0,
                ':notes'       => $data['notes']       ?? null,
            ]
        );
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $allowed = ['bank_name', 'last4', 'owner_name', 'location', 'platforms',
                    'balance_uah', 'limit_uah', 'status', 'notes'];
        $sets   = [];
        $params = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]          = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        if (empty($sets)) return false;

        self::db()->execute(
            "UPDATE finance_cards SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id",
            $params
        );
        return true;
    }

    public static function topup(int $id, float $amountUah, int $projectId, string $description = ''): array
    {
        $db = self::db();

        $db->execute(
            "UPDATE finance_cards SET balance_uah = balance_uah + :amount, updated_at = NOW() WHERE id = :id",
            [':amount' => $amountUah, ':id' => $id]
        );

        $newBalance = (float)$db->query(
            "SELECT balance_uah FROM finance_cards WHERE id = :id LIMIT 1",
            [':id' => $id]
        )->fetchColumn();

        $db->execute(
            "INSERT INTO finance_transactions
                (type, project_id, amount_uah, card_id, description, transaction_date, created_at)
             VALUES ('card_topup', :pid, :amt, :cid, :desc, :date, NOW())",
            [
                ':pid'  => $projectId,
                ':amt'  => $amountUah,
                ':cid'  => $id,
                ':desc' => $description ?: 'Поповнення картки',
                ':date' => date('Y-m-d'),
            ]
        );

        return ['transaction_id' => (int)$db->lastInsertId(), 'new_balance' => $newBalance];
    }

    /**
     * Списать сумму с карты (для expense транзакций).
     * GREATEST(0, ...) — не даёт уйти в минус.
     */
    public static function deductBalance(int $id, float $amount): bool
    {
        if ($id <= 0 || $amount <= 0) return false;
        self::db()->execute(
            "UPDATE finance_cards SET balance_uah = GREATEST(0, balance_uah - :amount), updated_at = NOW() WHERE id = :id",
            [':amount' => $amount, ':id' => $id]
        );
        return true;
    }

    /**
     * Вернуть сумму на карту (при удалении/откате expense транзакции).
     * НЕ создаёт card_topup транзакцию — это просто корректировка баланса.
     */
    public static function refundBalance(int $id, float $amount): bool
    {
        if ($id <= 0 || $amount <= 0) return false;
        self::db()->execute(
            "UPDATE finance_cards SET balance_uah = balance_uah + :amount, updated_at = NOW() WHERE id = :id",
            [':amount' => $amount, ':id' => $id]
        );
        return true;
    }

    /**
     * Проверить существует ли карта с указанным ID.
     */
    public static function exists(int $id): bool
    {
        if ($id <= 0) return false;
        $row = self::db()->query(
            "SELECT 1 FROM finance_cards WHERE id = :id LIMIT 1",
            [':id' => $id]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row !== false;
    }
}
