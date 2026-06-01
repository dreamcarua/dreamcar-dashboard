<?php
/**
 * UtmCrmAdsMapping.php
 * Модель для роботи з відповідностями CRM ↔ ADS міток
 *
 * Таблиця: utm_crm_ads_mapping
 * Призначення: Зіставлення міток між CRM (SendPulse) та рекламними системами (Facebook/Google)
 */

require_once __DIR__ . '/../Database.php';

class UtmCrmAdsMapping {

    private static function getDB() {
        return Database::getInstance();
    }

    /**
     * Отримати всі mappings з можливістю фільтрації
     *
     * @param array $filters ['field_type' => 'utm_term']
     * @return array
     */
    public static function getAll($filters = []) {
        $db = self::getDB();

        $where = [];
        $params = [];

        if (!empty($filters['field_type'])) {
            $where[] = "field_type = :field_type";
            $params['field_type'] = $filters['field_type'];
        }

        $sql = "SELECT * FROM utm_crm_ads_mapping";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY field_type ASC, merged_name ASC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Отримати один mapping по ID
     */
    public static function getById($id) {
        $db = self::getDB();

        $sql = "SELECT * FROM utm_crm_ads_mapping WHERE id = :id";

        return $db->fetchOne($sql, ['id' => $id]);
    }

    /**
     * Створити новий mapping
     *
     * @param array $data ['field_type', 'crm_value', 'ads_value', 'merged_name', 'notes', 'created_by']
     * @return int ID створеного запису
     */
    public static function create($data) {
        $db = self::getDB();

        $sql = "INSERT INTO utm_crm_ads_mapping
                (field_type, crm_value, ads_value, merged_name, notes, created_by)
                VALUES
                (:field_type, :crm_value, :ads_value, :merged_name, :notes, :created_by)";

        $params = [
            'field_type' => $data['field_type'],
            'crm_value' => strtolower(trim($data['crm_value'])),
            'ads_value' => strtolower(trim($data['ads_value'])),
            'merged_name' => trim($data['merged_name']),
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? 'admin'
        ];

        $db->execute($sql, $params);

        return $db->getLastInsertId();
    }

    /**
     * Оновити існуючий mapping
     */
    public static function update($id, $data) {
        $db = self::getDB();

        $sql = "UPDATE utm_crm_ads_mapping SET
                field_type = :field_type,
                crm_value = :crm_value,
                ads_value = :ads_value,
                merged_name = :merged_name,
                notes = :notes
                WHERE id = :id";

        $params = [
            'id' => $id,
            'field_type' => $data['field_type'],
            'crm_value' => strtolower(trim($data['crm_value'])),
            'ads_value' => strtolower(trim($data['ads_value'])),
            'merged_name' => trim($data['merged_name']),
            'notes' => $data['notes'] ?? null
        ];

        $db->execute($sql, $params);

        return $id;
    }

    /**
     * Видалити mapping
     */
    public static function delete($id) {
        $db = self::getDB();

        $sql = "DELETE FROM utm_crm_ads_mapping WHERE id = :id";

        $db->execute($sql, ['id' => $id]);

        return true;
    }

    /**
     * Отримати всі mappings для конкретного поля
     * Повертає масив для швидкого пошуку
     *
     * @param string $fieldType 'utm_term', 'utm_source', etc
     * @return array
     */
    public static function getMappingsByField($fieldType) {
        $db = self::getDB();

        $sql = "SELECT crm_value, ads_value, merged_name, notes
                FROM utm_crm_ads_mapping
                WHERE field_type = :field_type
                ORDER BY merged_name ASC";

        $rows = $db->fetchAll($sql, ['field_type' => $fieldType]);

        return $rows;
    }

    /**
     * Знайти відповідність для конкретного значення
     *
     * @param string $fieldType 'utm_term'
     * @param string $value 'vadym' або 'dreamcar.ua uah'
     * @param string $source 'CRM' або 'ADS'
     * @return array|null ['crm_value', 'ads_value', 'merged_name']
     */
    public static function findMapping($fieldType, $value, $source = 'CRM') {
        $db = self::getDB();

        $column = ($source === 'CRM') ? 'crm_value' : 'ads_value';

        $sql = "SELECT crm_value, ads_value, merged_name, notes
                FROM utm_crm_ads_mapping
                WHERE field_type = :field_type
                  AND $column = :value
                LIMIT 1";

        return $db->fetchOne($sql, [
            'field_type' => $fieldType,
            'value' => strtolower(trim($value))
        ]);
    }
}
