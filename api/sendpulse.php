<?php
// === sendpulse.php ===
// /home/rizakz/tsemakh.pp.ua/www/utm-dashboard/api/sendpulse.php
// НАЗНАЧЕНИЕ: Интеграция с SendPulse API для получения контактов
// СВЯЗИ: config/app_config.php, core/Logger.php
// ДАННЫЕ: data/sendpulse_token.json, data/contacts.json
// API: SendPulse REST API
// РАЗМЕР: ~350 строк
// ОБНОВЛЕНО: 2025-11-15 12:00

/**
 * СТРУКТУРА ФАЙЛА:
 * 1. Класс SendPulseAPI (строки 15-100)
 * 2. Авторизация и токены (строки 101-150)
 * 3. Получение контактов (строки 151-250)
 * 4. Обработка UTM-меток (строки 251-350)
 */

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Logger.php';

class SendPulseAPI {
    private $clientId;
    private $clientSecret;
    private $tokenFile;
    private $accessToken;
    private $logger;
    private $baseUrl = 'https://api.sendpulse.com';

    public function __construct() {
        $this->clientId = SENDPULSE_ID;
        $this->clientSecret = SENDPULSE_SECRET;
        $this->tokenFile = SENDPULSE_TOKEN_FILE;
        $this->logger = new Logger();

        // Получить или обновить токен
        $this->getAccessToken();
    }

    /**
     * Получить токен доступа
     */
    private function getAccessToken() {
        // Проверяем существующий токен
        if (file_exists($this->tokenFile)) {
            $tokenData = json_decode(file_get_contents($this->tokenFile), true);

            // Если токен еще действителен
            if (isset($tokenData['expires_at']) && $tokenData['expires_at'] > time()) {
                $this->accessToken = $tokenData['access_token'];
                return $this->accessToken;
            }
        }

        // Получить новый токен
        return $this->requestNewToken();
    }

    /**
     * Запросить новый токен
     */
    private function requestNewToken() {
        $url = $this->baseUrl . '/oauth/access_token';

        $data = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $result = json_decode($response, true);

            $tokenData = [
                'access_token' => $result['access_token'],
                'expires_at' => time() + 3600 // Токен действует 1 час
            ];

            // Сохранить токен
            saveJSON($this->tokenFile, $tokenData);

            $this->accessToken = $result['access_token'];
            $this->logger->success('Получен новый токен SendPulse');

            return $this->accessToken;
        } else {
            $this->logger->error('Ошибка получения токена SendPulse', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return null;
        }
    }

    /**
     * Выполнить API запрос
     */
    private function apiRequest($endpoint, $method = 'GET', $data = []) {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken
        ]);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        } else {
            $this->logger->error('Ошибка API запроса', [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return null;
        }
    }

    /**
     * Получить список адресных книг
     */
    public function getAddressBooks() {
        $result = $this->apiRequest('/addressbooks');
        $this->logger->info('Получены адресные книги', ['count' => count($result ?? [])]);
        return $result;
    }

    /**
     * Получить контакты из адресной книги
     */
    public function getContacts($bookId, $limit = 500, $offset = 0) {
        $endpoint = "/addressbooks/{$bookId}/emails?limit={$limit}&offset={$offset}";
        $result = $this->apiRequest($endpoint);

        if ($result) {
            $this->logger->info('Получены контакты', [
                'book_id' => $bookId,
                'count' => count($result ?? [])
            ]);
        }

        return $result;
    }

    /**
     * Получить все контакты со всех книг
     */
    public function getAllContacts() {
        $allContacts = [];

        // Получить список книг
        $books = $this->getAddressBooks();

        if (!$books) {
            return [];
        }

        foreach ($books as $book) {
            $bookId = $book['id'];
            $bookName = $book['name'];

            $this->logger->info("Обработка книги: {$bookName}");

            $offset = 0;
            $limit = MAX_CONTACTS_PER_REQUEST;

            do {
                $contacts = $this->getContacts($bookId, $limit, $offset);

                if (!$contacts || empty($contacts)) {
                    break;
                }

                foreach ($contacts as $contact) {
                    // Извлечь UTM метки из переменных контакта
                    $utmData = $this->extractUTM($contact);

                    $allContacts[] = [
                        'email' => $contact['email'] ?? '',
                        'phone' => $contact['phone'] ?? '',
                        'created_at' => $contact['created_at'] ?? '',
                        'utm_source' => $utmData['utm_source'] ?? null,
                        'utm_medium' => $utmData['utm_medium'] ?? null,
                        'utm_campaign' => $utmData['utm_campaign'] ?? null,
                        'utm_term' => $utmData['utm_term'] ?? null,
                        'utm_content' => $utmData['utm_content'] ?? null,
                        'list_name' => $bookName,
                        'tags' => $contact['tags'] ?? []
                    ];
                }

                $offset += $limit;

            } while (count($contacts) == $limit);
        }

        $this->logger->success('Получены все контакты', ['total' => count($allContacts)]);

        return $allContacts;
    }

    /**
     * Извлечь UTM метки из контакта
     */
    private function extractUTM($contact) {
        $utm = [
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null
        ];

        // Проверяем поля variables
        if (isset($contact['variables'])) {
            foreach ($contact['variables'] as $variable) {
                $name = strtolower($variable['name'] ?? '');
                $value = $variable['value'] ?? null;

                if (in_array($name, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])) {
                    $utm[$name] = $value;
                }
            }
        }

        return $utm;
    }

    /**
     * Синхронизировать контакты
     */
    public function syncContacts() {
        $this->logger->info('Начало синхронизации контактов SendPulse');

        $contacts = $this->getAllContacts();

        if (empty($contacts)) {
            $this->logger->warning('Контакты не получены');
            return false;
        }

        // Сохранить сырые данные
        saveJSON(UTM_RAW_FILE, $contacts);

        $this->logger->success('Контакты синхронизированы', ['count' => count($contacts)]);

        // Обновить настройки
        updateSettings('last_sync', date('Y-m-d H:i:s'));
        updateSettings('total_contacts', count($contacts));

        return $contacts;
    }
}
