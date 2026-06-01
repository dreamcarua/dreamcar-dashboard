<?php
// === Database.php ===
// НАЗНАЧЕНИЕ: PDO Singleton класс для работы с MySQL
// СВЯЗИ: config/database.php
// ИСПОЛЬЗОВАНИЕ: Database::getInstance()->query()
// РАЗМЕР: ~150 строк

class Database {
    private static $instance = null;
    private $pdo;
    private $logger;

    /**
     * Приватный конструктор (Singleton pattern)
     */
    private function __construct() {
        require_once __DIR__ . '/../config/app_config.php';
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/Logger.php';

        $this->logger = new Logger();
        $this->connect();
    }

    /**
     * Получить единственный экземпляр класса
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Подключение к базе данных
     */
    private function connect() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);
            $this->logger->info('Успешное подключение к БД', ['database' => DB_NAME]);

        } catch (PDOException $e) {
            $this->logger->error('Ошибка подключения к БД', [
                'error' => $e->getMessage(),
                'host' => DB_HOST,
                'database' => DB_NAME
            ]);
            throw new Exception('Не удалось подключиться к базе данных: ' . $e->getMessage());
        }
    }

    /**
     * Получить объект PDO
     */
    public function getPDO() {
        return $this->pdo;
    }

    /**
     * Выполнить запрос с параметрами
     *
     * @param string $sql SQL запрос
     * @param array $params Параметры для prepared statement
     * @return PDOStatement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logger->error('Ошибка выполнения запроса', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Ошибка выполнения запроса: ' . $e->getMessage());
        }
    }

    /**
     * Выполнить SELECT запрос и вернуть все строки
     *
     * @param string $sql SQL запрос
     * @param array $params Параметры
     * @return array
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Выполнить SELECT запрос и вернуть одну строку
     *
     * @param string $sql SQL запрос
     * @param array $params Параметры
     * @return array|false
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Выполнить INSERT/UPDATE/DELETE и вернуть количество затронутых строк
     *
     * @param string $sql SQL запрос
     * @param array $params Параметры
     * @return int
     */
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Получить ID последней вставленной записи
     *
     * @return string
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    /**
     * Начать транзакцию
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    /**
     * Зафиксировать транзакцию
     */
    public function commit() {
        return $this->pdo->commit();
    }

    /**
     * Откатить транзакцию
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }

    /**
     * Проверить, активна ли транзакция
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }

    /**
     * Массовая вставка данных (batch insert)
     *
     * @param string $table Название таблицы
     * @param array $data Массив ассоциативных массивов
     * @param int $batchSize Размер батча (по умолчанию 500)
     * @param bool $ignore Использовать INSERT IGNORE (пропускать дубликаты)
     * @return int Количество вставленных записей
     */
    public function batchInsert($table, $data, $batchSize = 500, $ignore = false) {
        if (empty($data)) {
            return 0;
        }

        $totalInserted = 0;
        $batches = array_chunk($data, $batchSize);

        foreach ($batches as $batch) {
            try {
                $this->beginTransaction();

                // Получить колонки из первой записи
                $columns = array_keys($batch[0]);
                $columnsStr = '`' . implode('`, `', $columns) . '`';

                // Подготовить плейсхолдеры
                $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $valuesStr = implode(', ', array_fill(0, count($batch), $placeholders));

                // Собрать SQL
                $ignoreStr = $ignore ? 'IGNORE' : '';
                $sql = "INSERT $ignoreStr INTO `$table` ($columnsStr) VALUES $valuesStr";

                // Собрать параметры
                $params = [];
                foreach ($batch as $row) {
                    foreach ($columns as $col) {
                        $params[] = $row[$col];
                    }
                }

                // Выполнить
                $inserted = $this->execute($sql, $params);
                $totalInserted += $inserted;

                $this->commit();

            } catch (Exception $e) {
                if ($this->inTransaction()) {
                    $this->rollback();
                }
                $this->logger->error('Ошибка batch insert', [
                    'table' => $table,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return $totalInserted;
    }

    /**
     * Вставка с обновлением при дубликате (ON DUPLICATE KEY UPDATE)
     *
     * @param string $table Название таблицы
     * @param array $data Ассоциативный массив данных
     * @param array $updateColumns Колонки для обновления (если пусто - обновляются все кроме ключей)
     * @return bool
     */
    public function insertOrUpdate($table, $data, $updateColumns = []) {
        $columns = array_keys($data);
        $columnsStr = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // Если не указаны колонки для обновления, обновляем все
        if (empty($updateColumns)) {
            $updateColumns = $columns;
        }

        // Подготовить UPDATE часть (новый синтаксис для MySQL 8.4)
        $updateParts = [];
        foreach ($updateColumns as $col) {
            $updateParts[] = "`$col` = new_values.`$col`";
        }
        $updateStr = implode(', ', $updateParts);

        $sql = "INSERT INTO `$table` ($columnsStr) VALUES ($placeholders) AS new_values
                ON DUPLICATE KEY UPDATE $updateStr";

        return $this->execute($sql, array_values($data)) > 0;
    }

    /**
     * Проверить подключение к БД
     */
    public function ping() {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Запретить клонирование (Singleton)
     */
    private function __clone() {}

    /**
     * Запретить десериализацию (Singleton)
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
