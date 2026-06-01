<?php
// === google_sheets.php ===
// /home/serflow/dreamcar.ai-platform.space/www/dashboard/utm-dashboard/api/google_sheets.php
// НАЗНАЧЕНИЕ: Интеграция с Google Sheets API
// СВЯЗИ: config/app_config.php, core/Logger.php
// ДАННЫЕ: data/google_credentials.json
// API: Google Sheets API v4
// РАЗМЕР: ~400 строк
// ОБНОВЛЕНО: 2025-11-15 12:00

/**
 * СТРУКТУРА ФАЙЛА:
 * 1. Класс GoogleSheetsAPI (строки 15-120)
 * 2. Методы работы с данными (строки 121-250)
 * 3. Запись в таблицу (строки 251-350)
 * 4. Чтение из таблицы (строки 351-400)
 */

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Logger.php';

class GoogleSheetsAPI {
    private $spreadsheetId;
    private $credentials;
    private $accessToken;
    private $logger;
    private $baseUrl = 'https://sheets.googleapis.com/v4/spreadsheets';

    public function __construct() {
        $this->spreadsheetId = GOOGLE_SPREADSHEET_ID;
        $this->logger = new Logger();
        $this->loadCredentials();
        $this->authenticate();
    }

    /**
     * Загрузить учетные данные
     */
    private function loadCredentials() {
        if (!file_exists(GOOGLE_CREDENTIALS_FILE)) {
            $this->logger->error('Файл credentials.json не найден');
            return false;
        }

        $this->credentials = json_decode(file_get_contents(GOOGLE_CREDENTIALS_FILE), true);
        return true;
    }

    /**
     * Аутентификация через Service Account
     */
    private function authenticate() {
        // Создать JWT токен
        $jwt = $this->createJWT();

        // Обменять на access token
        $url = 'https://oauth2.googleapis.com/token';

        $data = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if (isset($result['access_token'])) {
            $this->accessToken = $result['access_token'];
            $this->logger->success('Успешная аутентификация в Google Sheets API');
            return true;
        } else {
            $this->logger->error('Ошибка аутентификации Google', ['response' => $result]);
            return false;
        }
    }

    /**
     * Создать JWT токен
     */
    private function createJWT() {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        $now = time();
        $claim = [
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ];

        $header_encoded = $this->base64url_encode(json_encode($header));
        $claim_encoded = $this->base64url_encode(json_encode($claim));

        $signature_input = $header_encoded . '.' . $claim_encoded;

        $private_key = $this->credentials['private_key'];

        openssl_sign($signature_input, $signature, $private_key, 'SHA256');

        $signature_encoded = $this->base64url_encode($signature);

        return $signature_input . '.' . $signature_encoded;
    }

    /**
     * Base64 URL encode
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Выполнить API запрос
     */
    private function apiRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . '/' . $this->spreadsheetId . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method == 'POST' || $method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            $this->logger->error('Ошибка API запроса Google Sheets', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return null;
        }
    }

    /**
     * Записать данные в лист
     */
    public function writeData($sheetName, $data, $range = 'A1') {
        $endpoint = "/values/{$sheetName}!{$range}:append?valueInputOption=RAW";

        $body = [
            'values' => $data
        ];

        $result = $this->apiRequest($endpoint, 'POST', $body);

        if ($result) {
            $this->logger->success("Данные записаны в лист {$sheetName}", [
                'rows' => count($data)
            ]);
        }

        return $result;
    }

    /**
     * Очистить лист
     */
    public function clearSheet($sheetName) {
        $endpoint = "/values/{$sheetName}:clear";

        $result = $this->apiRequest($endpoint, 'POST');

        if ($result) {
            $this->logger->info("Лист {$sheetName} очищен");
        }

        return $result;
    }

    /**
     * Прочитать данные из листа
     */
    public function readData($sheetName, $range = 'A:Z') {
        $endpoint = "/values/{$sheetName}!{$range}";

        $result = $this->apiRequest($endpoint);

        if ($result && isset($result['values'])) {
            $this->logger->info("Прочитаны данные из {$sheetName}", [
                'rows' => count($result['values'])
            ]);
            return $result['values'];
        }

        return [];
    }

    /**
     * Обновить данные в листе
     */
    public function updateData($sheetName, $data, $range = 'A1') {
        $endpoint = "/values/{$sheetName}!{$range}?valueInputOption=RAW";

        $body = [
            'values' => $data
        ];

        $result = $this->apiRequest($endpoint, 'PUT', $body);

        if ($result) {
            $this->logger->success("Данные обновлены в {$sheetName}");
        }

        return $result;
    }

    /**
     * Синхронизировать контакты в Google Sheets
     */
    public function syncContacts($contacts) {
        $this->logger->info('Начало синхронизации с Google Sheets');

        // Подготовить данные для листа contacts_raw
        $rawData = [
            ['email', 'phone', 'created_at', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'list_name', 'tags']
        ];

        foreach ($contacts as $contact) {
            $rawData[] = [
                $contact['email'],
                $contact['phone'],
                $contact['created_at'],
                $contact['utm_source'] ?? '',
                $contact['utm_medium'] ?? '',
                $contact['utm_campaign'] ?? '',
                $contact['utm_term'] ?? '',
                $contact['utm_content'] ?? '',
                $contact['list_name'] ?? '',
                is_array($contact['tags']) ? implode(', ', $contact['tags']) : ''
            ];
        }

        // Очистить и записать в contacts_raw
        $this->clearSheet(GOOGLE_SHEET_RAW);
        $this->updateData(GOOGLE_SHEET_RAW, $rawData, 'A1');

        $this->logger->success('Контакты синхронизированы в Google Sheets', [
            'count' => count($contacts)
        ]);

        return true;
    }

    /**
     * Синхронизировать очищенные UTM данные
     */
    public function syncCleanedUTM($cleanedData) {
        // Подготовить данные для листа utm_clean
        $utmData = [
            ['email', 'phone', 'created_at', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'tag_list']
        ];

        foreach ($cleanedData as $item) {
            $utmData[] = [
                $item['email'],
                $item['phone'],
                $item['created_at'],
                $item['utm_source'],
                $item['utm_medium'],
                $item['utm_campaign'],
                $item['utm_term'],
                $item['utm_content'],
                $item['tag_list'] ?? ''
            ];
        }

        // Очистить и записать в utm_clean
        $this->clearSheet(GOOGLE_SHEET_CLEAN);
        $this->updateData(GOOGLE_SHEET_CLEAN, $utmData, 'A1');

        $this->logger->success('Очищенные UTM данные синхронизированы', [
            'count' => count($cleanedData)
        ]);

        return true;
    }

    /**
     * Получить данные из utm_clean
     */
    public function getCleanedData() {
        $data = $this->readData(GOOGLE_SHEET_CLEAN);

        if (empty($data)) {
            return [];
        }

        // Первая строка - заголовки
        $headers = array_shift($data);

        // Преобразовать в ассоциативный массив
        $result = [];
        foreach ($data as $row) {
            $item = [];
            foreach ($headers as $index => $header) {
                $item[$header] = $row[$index] ?? null;
            }
            $result[] = $item;
        }

        return $result;
    }
}
