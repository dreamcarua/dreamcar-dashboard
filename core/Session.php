<?php
/**
 * Session.php
 * Безпечне управління PHP сесіями для UTM Dashboard
 *
 * Функціонал:
 * - CSRF токени
 * - Session regeneration кожні 30 хвилин
 * - Гостьові сесії для прямих посилань
 * - Flash повідомлення
 * - Безпечні налаштування cookies
 */

class Session {
    // Конфігурація безпеки
    private const SESSION_LIFETIME = 28800; // 8 годин
    private const REGENERATE_INTERVAL = 1800; // 30 хвилин
    private const SESSION_NAME = 'UTM_DASHBOARD_SESSION';

    private static $started = false;
    private static $logger;

    /**
     * Запустити сесію з безпечними налаштуваннями
     */
    public static function start() {
        if (self::$started) {
            return true;
        }

        require_once __DIR__ . '/Logger.php';
        self::$logger = new Logger();

        // Безпечні налаштування cookie
        ini_set('session.cookie_httponly', 1); // Захист від XSS
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS'])); // HTTPS only якщо доступний
        ini_set('session.cookie_samesite', 'Strict'); // Захист від CSRF
        ini_set('session.use_strict_mode', 1); // Не приймати невідомі session ID
        ini_set('session.use_only_cookies', 1); // Тільки через cookies

        // Налаштування імені та часу життя
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME,
            'path' => '/',
            'domain' => '', // Поточний домен
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        // Запустити сесію
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            self::$started = true;

            // Перевірити регенерацію
            self::checkRegeneration();

            self::$logger->debug('Сесія запущена', [
                'session_id' => session_id(),
                'is_guest' => self::isGuest()
            ]);
        }

        return true;
    }

    /**
     * Перевірити чи потрібна регенерація session ID
     */
    private static function checkRegeneration() {
        $lastRegeneration = self::get('_last_regeneration', 0);
        $now = time();

        if ($now - $lastRegeneration > self::REGENERATE_INTERVAL) {
            session_regenerate_id(true);
            self::set('_last_regeneration', $now);
            self::$logger->debug('Session ID регенеровано');
        }
    }

    /**
     * Встановити значення в сесію
     */
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Отримати значення з сесії
     */
    public static function get($key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Видалити значення з сесії
     */
    public static function delete($key) {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Перевірити чи існує ключ
     */
    public static function has($key) {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Отримати дані користувача
     */
    public static function getUser() {
        return self::get('user', null);
    }

    /**
     * Встановити дані користувача
     */
    public static function setUser($userData) {
        self::set('user', $userData);
        self::set('_last_regeneration', time());
        self::set('_login_time', time());

        // Регенерувати session ID після входу (запобігання session fixation)
        session_regenerate_id(true);

        self::$logger->info('Користувач встановлений у сесію', [
            'username' => $userData['username'],
            'role' => $userData['role'],
            'session_id' => session_id()
        ]);
    }

    /**
     * Чи є користувач авторизованим
     */
    public static function isLoggedIn() {
        return self::has('user') && !self::get('user.is_guest', false);
    }

    /**
     * Чи є користувач гостем (через пряме посилання з utm_term)
     */
    public static function isGuest() {
        $user = self::getUser();
        return $user !== null && isset($user['is_guest']) && $user['is_guest'] === true;
    }

    /**
     * Отримати роль користувача
     */
    public static function getRole() {
        $user = self::getUser();
        return $user['role'] ?? 'guest';
    }

    /**
     * Отримати utm_term користувача
     */
    public static function getUtmTerm() {
        $user = self::getUser();
        return $user['utm_term'] ?? null;
    }

    /**
     * Знищити сесію (logout)
     */
    public static function destroy() {
        self::start();

        $username = self::get('user')['username'] ?? 'unknown';

        // Очистити всі дані
        $_SESSION = [];

        // Видалити cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                self::SESSION_NAME,
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Знищити сесію
        session_destroy();
        self::$started = false;

        self::$logger->info('Сесія знищена', ['username' => $username]);
    }

    /**
     * Створити гостьову сесію з utm_term з URL
     */
    public static function createGuestSession($utmTerm) {
        self::start();

        $guestData = [
            'username' => 'guest',
            'role' => 'guest',
            'utm_term' => strtolower(trim($utmTerm)),
            'is_guest' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        self::setUser($guestData);

        self::$logger->info('Створено гостьову сесію', [
            'utm_term' => $utmTerm,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        return true;
    }

    /**
     * Генерувати CSRF токен
     */
    public static function generateCsrfToken() {
        self::start();

        if (!self::has('csrf_token')) {
            $token = bin2hex(random_bytes(32));
            self::set('csrf_token', $token);
        }

        return self::get('csrf_token');
    }

    /**
     * Перевірити CSRF токен
     */
    public static function validateCsrfToken($token) {
        self::start();
        $sessionToken = self::get('csrf_token');
        return $sessionToken !== null && hash_equals($sessionToken, $token);
    }

    /**
     * Flash повідомлення (показується один раз)
     */
    public static function setFlash($type, $message) {
        self::start();
        $_SESSION['_flash'][$type] = $message;
    }

    /**
     * Отримати і видалити flash повідомлення
     */
    public static function getFlash($type) {
        self::start();
        $message = $_SESSION['_flash'][$type] ?? null;
        unset($_SESSION['_flash'][$type]);
        return $message;
    }

    /**
     * Отримати всі flash повідомлення
     */
    public static function getAllFlashes() {
        self::start();
        $flashes = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $flashes;
    }
}
