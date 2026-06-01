<?php
// === Logger.php ===
// /home/rizakz/tsemakh.pp.ua/www/utm-dashboard/core/Logger.php
// НАЗНАЧЕНИЕ: Система логирования с отправкой в Telegram
// СВЯЗИ: config/app_config.php
// ДАННЫЕ: log_actual.json
// API: Telegram Bot API
// РАЗМЕР: ~250 строк
// ОБНОВЛЕНО: 2025-11-15 12:00

/**
 * СТРУКТУРА ФАЙЛА:
 * 1. Класс Logger (строки 15-80)
 * 2. Методы логирования (строки 81-150)
 * 3. Telegram интеграция (строки 151-250)
 */

class Logger {
    private $logFile;
    private $logs = [];

    public function __construct() {
        $this->logFile = LOG_FILE;
        $this->loadLogs();
    }

    /**
     * Загрузить существующие логи
     */
    private function loadLogs() {
        if (file_exists($this->logFile)) {
            $content = file_get_contents($this->logFile);
            $this->logs = json_decode($content, true) ?? [];
        } else {
            $this->logs = [
                'app_start' => date('Y-m-d H:i:s'),
                'events' => []
            ];
        }
    }

    /**
     * Добавить запись в лог
     */
    public function log($message, $type = 'info', $data = []) {
        $logEntry = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'memory' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory' => $this->formatBytes(memory_get_peak_usage(true))
        ];

        $this->logs['events'][] = $logEntry;
        $this->logs['last_update'] = date('Y-m-d H:i:s');

        // Сохранить логи
        $this->saveLogs();

        // Отправить в Telegram только ошибки и критические события
        if (in_array($type, ['error', 'critical'])) {
            $this->sendToTelegram($logEntry);
        }

        return $logEntry;
    }

    /**
     * Сохранить логи в файл
     */
    private function saveLogs() {
        // Оставляем только последние 100 записей
        if (count($this->logs['events']) > 100) {
            $this->logs['events'] = array_slice($this->logs['events'], -100);
        }

        file_put_contents(
            $this->logFile,
            json_encode($this->logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Получить все логи
     */
    public function getLogs() {
        return $this->logs;
    }

    /**
     * Очистить логи
     */
    public function clear() {
        $this->logs = [
            'app_start' => date('Y-m-d H:i:s'),
            'events' => []
        ];
        $this->saveLogs();
    }

    /**
     * Отправить сообщение в Telegram
     */
    private function sendToTelegram($logEntry) {
        $emoji = [
            'info' => 'ℹ️',
            'success' => '✅',
            'warning' => '⚠️',
            'error' => '❌',
            'critical' => '🚨'
        ];

        $icon = $emoji[$logEntry['type']] ?? '📝';

        $text = "*{$icon} UTM Dashboard Log*\n\n";
        $text .= "*Тип:* {$logEntry['type']}\n";
        $text .= "*Сообщение:* {$logEntry['message']}\n";
        $text .= "*Время:* {$logEntry['time']}\n";
        $text .= "*Память:* {$logEntry['memory']}\n";

        if (!empty($logEntry['data'])) {
            $text .= "\n*Данные:*\n";
            $text .= "```\n" . json_encode($logEntry['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```";
        }

        $this->sendTelegramMessage($text);
    }

    /**
     * Отправить сообщение в Telegram
     */
    private function sendTelegramMessage($text) {
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";

        $data = [
            'chat_id' => TELEGRAM_CHAT_ID,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    /**
     * Форматировать байты в читаемый вид
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Логировать успех
     */
    public function success($message, $data = []) {
        return $this->log($message, 'success', $data);
    }

    /**
     * Логировать ошибку
     */
    public function error($message, $data = []) {
        return $this->log($message, 'error', $data);
    }

    /**
     * Логировать предупреждение
     */
    public function warning($message, $data = []) {
        return $this->log($message, 'warning', $data);
    }

    /**
     * Логировать критическую ошибку
     */
    public function critical($message, $data = []) {
        return $this->log($message, 'critical', $data);
    }

    /**
     * Логировать информацию
     */
    public function info($message, $data = []) {
        return $this->log($message, 'info', $data);
    }

    /**
     * Логировать отладочную информацию
     */
    public function debug($message, $data = []) {
        return $this->log($message, 'debug', $data);
    }

    /**
     * Получить статистику логов
     */
    public function getStats() {
        $stats = [
            'total' => count($this->logs['events']),
            'by_type' => []
        ];

        foreach ($this->logs['events'] as $event) {
            $type = $event['type'];
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = 0;
            }
            $stats['by_type'][$type]++;
        }

        return $stats;
    }
}
