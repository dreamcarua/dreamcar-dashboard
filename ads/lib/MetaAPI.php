<?php
/**
 * Meta Marketing API Wrapper Class
 * Работа с Facebook/Instagram Ads API
 */

class MetaAPI {
    private $accessToken;
    private $apiVersion;
    private $baseUrl;

    public function __construct($accessToken = null) {
        $this->accessToken = $accessToken;
        $this->apiVersion = META_API_VERSION;
        $this->baseUrl = META_API_BASE_URL;
    }

    /**
     * Установить access token
     */
    public function setAccessToken($token) {
        $this->accessToken = $token;
    }

    /**
     * Выполнить GET запрос к API
     */
    public function get($endpoint, $params = []) {
        $params['access_token'] = $this->accessToken;
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $startTime = microtime(true);
        $response = @file_get_contents($url);
        $responseTime = round((microtime(true) - $startTime) * 1000, 2);

        $data = $response ? json_decode($response, true) : null;

        // Логирование
        $this->log([
            'method' => 'GET',
            'endpoint' => $endpoint,
            'params' => $params,
            'response_time' => $responseTime . 'ms',
            'success' => !isset($data['error']),
            'data' => $data
        ]);

        return $data;
    }

    /**
     * Тест подключения - проверка токена
     */
    public function testConnection() {
        return $this->get('/me', ['fields' => 'id,name']);
    }

    /**
     * Получить информацию о токене
     */
    public function debugToken() {
        return $this->get('/debug_token', [
            'input_token' => $this->accessToken
        ]);
    }

    /**
     * Получить список рекламных аккаунтов
     */
    public function getAdAccounts() {
        return $this->get('/me/adaccounts', [
            'fields' => 'id,name,account_status,currency,balance,spend_cap,amount_spent'
        ]);
    }

    /**
     * Получить информацию об аккаунте
     */
    public function getAccountInfo($accountId) {
        return $this->get('/' . $accountId, [
            'fields' => 'id,name,account_status,currency,balance,spend_cap,amount_spent,created_time'
        ]);
    }

    /**
     * Alias для getAccountInfo
     */
    public function getAccount($accountId) {
        return $this->getAccountInfo($accountId);
    }

    /**
     * Получить кампании аккаунта
     */
    public function getCampaigns($accountId, $params = []) {
        $defaultParams = [
            'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,created_time,updated_time',
            'limit' => 100
        ];

        $params = array_merge($defaultParams, $params);
        return $this->get('/' . $accountId . '/campaigns', $params);
    }

    /**
     * Получить статистику (Insights)
     */
    public function getInsights($accountId, $params = []) {
        $defaultParams = [
            'level' => 'campaign',
            'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,cpc,cpm,reach,frequency',
            'time_range' => json_encode([
                'since' => date('Y-m-d', strtotime('-7 days')),
                'until' => date('Y-m-d')
            ])
        ];

        $params = array_merge($defaultParams, $params);
        return $this->get('/' . $accountId . '/insights', $params);
    }

    /**
     * Получить креативы
     */
    public function getCreatives($accountId, $params = []) {
        $defaultParams = [
            'fields' => 'id,name,status,title,body,image_url,video_id,thumbnail_url',
            'limit' => 50
        ];

        $params = array_merge($defaultParams, $params);
        return $this->get('/' . $accountId . '/adcreatives', $params);
    }

    /**
     * Получить объявления
     */
    public function getAds($accountId, $params = []) {
        $defaultParams = [
            'fields' => 'id,name,status,creative,preview_shareable_link',
            'limit' => 50
        ];

        $params = array_merge($defaultParams, $params);
        return $this->get('/' . $accountId . '/ads', $params);
    }

    /**
     * Логирование запросов
     */
    private function log($data) {
        $logFile = ADS_LOG_FILE;

        if (!file_exists($logFile)) {
            return;
        }

        $logs = json_decode(file_get_contents($logFile), true);

        $logs['logs'][] = array_merge([
            'timestamp' => date('Y-m-d H:i:s')
        ], $data);

        // Держать последние 100 записей
        if (count($logs['logs']) > 100) {
            $logs['logs'] = array_slice($logs['logs'], -100);
        }

        file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Получить логи
     */
    public static function getLogs($limit = 20) {
        $logFile = ADS_LOG_FILE;

        if (!file_exists($logFile)) {
            return [];
        }

        $logs = json_decode(file_get_contents($logFile), true);
        return array_slice(array_reverse($logs['logs']), 0, $limit);
    }
}
