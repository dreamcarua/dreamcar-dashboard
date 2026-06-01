<?php
// === WebhookLog.php ===
// НАЗНАЧЕНИЕ: Модель для работы с таблицей webhook_log
// СВЯЗИ: core/Database.php
// ИСПОЛЬЗОВАНИЕ: WebhookLog::create(), WebhookLog::getRecent()
// РАЗМЕР: ~250 строк

require_once __DIR__ . '/../Database.php';

class WebhookLog {
    private static $db = null;

    /**
     * Получить экземпляр БД
     */
    private static function getDB() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    /**
     * Создать запись в логе webhook
     *
     * @param string $webhookType 'crm' или 'ads'
     * @param string $eventType 'new', 'pay', 'fail' для CRM, null для ads
     * @param array|string $rawData Сырые данные (будут преобразованы в JSON)
     * @param array $processedData Обработанные данные (опционально)
     * @param string $dealId ID сделки (для CRM)
     * @param int $recordsCount Количество записей в запросе
     * @param bool $success Успешность обработки
     * @param string $errorMessage Сообщение об ошибке
     * @param float $processingTime Время обработки в секундах
     * @return int ID созданной записи
     */
    public static function create(
        $webhookType,
        $eventType = null,
        $rawData = null,
        $processedData = null,
        $dealId = null,
        $recordsCount = 1,
        $success = false,
        $errorMessage = null,
        $processingTime = null
    ) {
        $db = self::getDB();

        // Преобразовать rawData в JSON если это массив
        if (is_array($rawData)) {
            $rawDataJson = json_encode($rawData, JSON_UNESCAPED_UNICODE);
        } else {
            $rawDataJson = $rawData;
        }

        // Преобразовать processedData в JSON если это массив
        if (is_array($processedData)) {
            $processedDataJson = json_encode($processedData, JSON_UNESCAPED_UNICODE);
        } else {
            $processedDataJson = $processedData;
        }

        // Получить IP адрес и User-Agent
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $sql = "INSERT INTO webhook_log (
            webhook_type, event_type, raw_data, processed_data, deal_id,
            records_count, success, error_message, processing_time,
            ip_address, user_agent
        ) VALUES (
            :webhook_type, :event_type, :raw_data, :processed_data, :deal_id,
            :records_count, :success, :error_message, :processing_time,
            :ip_address, :user_agent
        )";

        $params = [
            'webhook_type' => $webhookType,
            'event_type' => $eventType,
            'raw_data' => $rawDataJson,
            'processed_data' => $processedDataJson,
            'deal_id' => $dealId,
            'records_count' => $recordsCount,
            'success' => $success ? 1 : 0,
            'error_message' => $errorMessage,
            'processing_time' => $processingTime,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ];

        $db->execute($sql, $params);

        return $db->lastInsertId();
    }

    /**
     * Получить недавние записи с фильтрацией
     *
     * @param array $filters ['webhook_type', 'event_type', 'success', 'date_from', 'date_to']
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @return array
     */
    public static function getRecent($filters = [], $limit = 100, $offset = 0) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['webhook_type'])) {
            $where[] = "webhook_type = :webhook_type";
            $params['webhook_type'] = $filters['webhook_type'];
        }

        if (!empty($filters['event_type'])) {
            $where[] = "event_type = :event_type";
            $params['event_type'] = $filters['event_type'];
        }

        if (isset($filters['success'])) {
            $where[] = "success = :success";
            $params['success'] = $filters['success'] ? 1 : 0;
        }

        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['deal_id'])) {
            $where[] = "deal_id = :deal_id";
            $params['deal_id'] = $filters['deal_id'];
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT * FROM webhook_log
                $whereStr
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $stmt = $db->getPDO()->prepare($sql);

        // Bind параметры с типами
        foreach ($params as $key => $value) {
            if ($key === 'limit' || $key === 'offset') {
                $stmt->bindValue(":$key", (int)$value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$key", $value);
            }
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Получить запись по ID
     *
     * @param int $id
     * @return array|false
     */
    public static function getById($id) {
        $db = self::getDB();
        $sql = "SELECT * FROM webhook_log WHERE id = :id";
        return $db->fetchOne($sql, ['id' => $id]);
    }

    /**
     * Получить количество записей с фильтрацией
     *
     * @param array $filters
     * @return int
     */
    public static function count($filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['webhook_type'])) {
            $where[] = "webhook_type = :webhook_type";
            $params['webhook_type'] = $filters['webhook_type'];
        }

        if (!empty($filters['event_type'])) {
            $where[] = "event_type = :event_type";
            $params['event_type'] = $filters['event_type'];
        }

        if (isset($filters['success'])) {
            $where[] = "success = :success";
            $params['success'] = $filters['success'] ? 1 : 0;
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*) as total FROM webhook_log $whereStr";

        $result = $db->fetchOne($sql, $params);
        return (int)$result['total'];
    }

    /**
     * Получить статистику по webhook
     *
     * @param array $filters
     * @return array
     */
    public static function getStats($filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
            webhook_type,
            event_type,
            COUNT(*) as total_requests,
            SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_requests,
            SUM(records_count) as total_records,
            AVG(processing_time) as avg_processing_time,
            MAX(processing_time) as max_processing_time
        FROM webhook_log
        $whereStr
        GROUP BY webhook_type, event_type
        ORDER BY webhook_type, event_type";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Удалить старые записи (для очистки)
     *
     * @param int $days Количество дней для хранения
     * @return int Количество удаленных записей
     */
    public static function deleteOld($days = 30) {
        $db = self::getDB();
        $sql = "DELETE FROM webhook_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
        return $db->execute($sql, ['days' => $days]);
    }
}
