<?php
/**
 * Auth.php
 * Система авторизації та контролю доступу для UTM Dashboard
 *
 * Функціонал:
 * - checkAccess() - головна перевірка доступу (на кожній сторінці)
 * - requireAdmin() - тільки для адміністраторів
 * - login() - авторизація з bruteforce захистом
 * - Підтримка гостьових сесій через прямі посилання
 */

require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Logger.php';

class Auth {
    private const USERS_FILE = __DIR__ . '/../config/users.json';
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 хвилин

    private static $logger;
    private static $users = null;

    /**
     * Ініціалізація логера
     */
    private static function init() {
        if (self::$logger === null) {
            self::$logger = new Logger();
        }
    }

    /**
     * Завантажити користувачів з файлу
     */
    private static function loadUsers() {
        if (self::$users !== null) {
            return self::$users;
        }

        self::init();

        if (!file_exists(self::USERS_FILE)) {
            self::$logger->error('Файл користувачів не знайдено', [
                'path' => self::USERS_FILE
            ]);
            return [];
        }

        $json = file_get_contents(self::USERS_FILE);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$logger->error('Помилка парсингу users.json', [
                'error' => json_last_error_msg()
            ]);
            return [];
        }

        self::$users = $data['users'] ?? [];
        return self::$users;
    }

    /**
     * ОСНОВНА ПЕРЕВІРКА ДОСТУПУ
     * Викликається на КОЖНІЙ сторінці dashboard
     *
     * Логіка:
     * 1. Перевірити чи є utm_term в URL
     * 2. Якщо ТАК → створити гостьову сесію, дозволити перегляд
     * 3. Якщо НІ → перевірити авторизацію
     * 4. Якщо не авторизований → редирект на login.php (або JSON для API)
     * 
     * @param bool $isApiRequest Если true, возвращает JSON вместо редиректа
     */
    public static function checkAccess($isApiRequest = false) {
        self::init();
        Session::start();

        // СЦЕНАРІЙ Б: Перевірка прямого посилання з utm_term
        $utmTermFromUrl = $_GET['utm_term'] ?? null;

        if ($utmTermFromUrl && !Session::isLoggedIn()) {
            // Пряме посилання з utm_term - створити гостьову сесію
            Session::createGuestSession($utmTermFromUrl);

            self::$logger->info('Створено гостьовий доступ через utm_term', [
                'utm_term' => $utmTermFromUrl,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            return; // Дозволити доступ
        }

        // СЦЕНАРІЙ А: Перевірка авторизації
        if (!Session::isLoggedIn() && !Session::isGuest()) {
            // Немає авторизації і не гість
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
            self::$logger->warning('Неавторизований доступ', [
                'url' => $currentUrl,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            // Проверить, это API запрос или обычная страница
            $isApiRequest = (
                strpos($currentUrl, '/api/') !== false ||
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            );

            if ($isApiRequest) {
                // Для API запросов вернуть JSON
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => 'Требуется авторизация',
                    'auth_required' => true
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            // Зберегти URL для повернення після логіна
            Session::set('redirect_after_login', $currentUrl);

            // Редирект на login.php (абсолютный URL чтобы работало из подпапок finance/ и др.)
            header('Location: ' . self::getLoginUrl());
            exit;
        }

        // СЦЕНАРІЙ Г: Гість видалив utm_term з URL - знищити сесію
        if (Session::isGuest() && !$utmTermFromUrl) {
            self::$logger->warning('Гість видалив utm_term з URL', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            // Знищити гостьову сесію
            Session::destroy();

            // Редирект на login (абсолютный URL)
            header('Location: ' . self::getLoginUrl());
            exit;
        }

        // СЦЕНАРІЙ В: Таргетолог намагається змінити utm_term
        if (Session::getRole() === 'targetolog') {
            $userUtmTerm = Session::getUtmTerm();

            // Якщо в URL інший utm_term або його немає
            if ($utmTermFromUrl && $utmTermFromUrl !== $userUtmTerm) {
                self::$logger->warning('Спроба зміни utm_term таргетологом', [
                    'username' => Session::get('user')['username'] ?? 'unknown',
                    'assigned_utm_term' => $userUtmTerm,
                    'attempted_utm_term' => $utmTermFromUrl
                ]);

                // Перенаправити на правильний URL з його utm_term
                $redirectUrl = self::replaceUtmTermInUrl($_SERVER['REQUEST_URI'], $userUtmTerm);
                header('Location: ' . $redirectUrl);
                exit;
            }

            // Якщо utm_term відсутній в URL - додати його
            if (!$utmTermFromUrl) {
                $redirectUrl = self::addUtmTermToUrl($_SERVER['REQUEST_URI'], $userUtmTerm);
                header('Location: ' . $redirectUrl);
                exit;
            }
        }

        // Доступ дозволено
        self::$logger->debug('Доступ дозволено', [
            'username' => Session::get('user')['username'] ?? 'guest',
            'role' => Session::getRole(),
            'is_guest' => Session::isGuest()
        ]);
    }

    /**
     * Вимагати роль адміністратора
     * Використовується на сторінках: upload_deals.php, webhook_logs.php, settings
     */
    public static function requireAdmin() {
        self::checkAccess(); // Спочатку перевірити базовий доступ

        if (Session::getRole() !== 'admin') {
            self::$logger->warning('Спроба доступу до адмінської сторінки', [
                'username' => Session::get('user')['username'] ?? 'unknown',
                'role' => Session::getRole(),
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            // Показати 403 Forbidden
            http_response_code(403);
            echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>403 - Доступ заборонено</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        h1 { color: #dc3545; }
        .back-btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        .back-btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>🚫 Доступ заборонено</h1>
    <p>У вас немає прав для перегляду цієї сторінки.</p>
    <p>Ця сторінка доступна тільки для адміністраторів.</p>
    <a href="index.php" class="back-btn">Повернутися на головну</a>
</body>
</html>';
            exit;
        }
    }

    /**
     * Вимагати роль адміністратора або таргетолога (не гість)
     * Використовується на: manual_costs.php
     */
    public static function requireAuth() {
        self::checkAccess();

        if (Session::isGuest()) {
            http_response_code(403);
            echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>403 - Доступ заборонено</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        h1 { color: #dc3545; }
        .login-btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 4px; }
        .login-btn:hover { background: #218838; }
    </style>
</head>
<body>
    <h1>🔒 Потрібна авторизація</h1>
    <p>Ця функція доступна тільки для авторизованих користувачів.</p>
    <a href="login.php" class="login-btn">Увійти</a>
</body>
</html>';
            exit;
        }
    }

    /**
     * Перевірити право доступу до розділу
     *
     * @param string $section Ключ розділу: main_dashboard, finance, finance_salary, finance_cards, finance_usdt, finance_settings
     * @return bool
     */
    public static function hasPermission(string $section): bool {
        $user = Session::get('user');
        if (!$user) {
            return false;
        }

        // Адміни мають всі права
        $role = $user['role'] ?? '';
        if ($role === 'admin') {
            return true;
        }

        // Гості — тільки main_dashboard
        if (Session::isGuest()) {
            return $section === 'main_dashboard';
        }

        // Перевірка з permissions поля
        $permissions = $user['permissions'] ?? [];
        return (bool)($permissions[$section] ?? false);
    }

    /**
     * Авторизувати користувача
     *
     * @param string $username Логін
     * @param string $password Пароль
     * @return array ['success' => bool, 'message' => string, 'user' => array|null]
     */
    public static function login($username, $password) {
        self::init();

        // Перевірка bruteforce
        if (self::isLockedOut($username)) {
            $remainingTime = self::getRemainingLockoutTime($username);
            return [
                'success' => false,
                'message' => "Занадто багато спроб входу. Спробуйте через $remainingTime секунд.",
                'user' => null
            ];
        }

        // Завантажити користувачів
        $users = self::loadUsers();

        // Знайти користувача
        $user = null;
        foreach ($users as $u) {
            if ($u['username'] === $username && $u['is_active'] === true) {
                $user = $u;
                break;
            }
        }

        if (!$user) {
            self::registerFailedAttempt($username);
            self::$logger->warning('Спроба входу з неіснуючим логіном', [
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'message' => 'Невірний логін або пароль',
                'user' => null
            ];
        }

        // Перевірити пароль
        if (!password_verify($password, $user['password_hash'])) {
            self::registerFailedAttempt($username);
            self::$logger->warning('Невірний пароль', [
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'message' => 'Невірний логін або пароль',
                'user' => null
            ];
        }

        // Успішний вхід
        self::clearFailedAttempts($username);

        // Підготувати дані для сесії (без password_hash!)
        $userData = [
            'username' => $user['username'],
            'role' => $user['role'],
            'finance_role' => $user['finance_role'] ?? null,
            'utm_term' => $user['utm_term'],
            'is_guest' => false,
            'settings' => $user['settings'] ?? []
        ];

        // Встановити в сесію
        Session::setUser($userData);

        // Оновити last_login в users.json
        self::updateLastLogin($username);

        self::$logger->info('Успішний вхід', [
            'username' => $username,
            'role' => $user['role'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        return [
            'success' => true,
            'message' => 'Вхід виконано успішно',
            'user' => $userData
        ];
    }

    /**
     * Вийти з системи
     */
    public static function logout() {
        self::init();

        $username = Session::get('user')['username'] ?? 'unknown';

        Session::destroy();

        self::$logger->info('Вихід з системи', ['username' => $username]);

        return true;
    }

    /**
     * Отримати utm_term поточного користувача
     * null - для адміна (може бачити все)
     * "vadym" - для таргетолога (фільтр фіксований)
     */
    public static function getUserUtmTerm() {
        return Session::getUtmTerm();
    }

    /**
     * Чи є користувач адміністратором
     */
    public static function isAdmin() {
        return Session::getRole() === 'admin';
    }

    /**
     * Чи є користувач таргетологом
     */
    public static function isTargetolog() {
        return Session::getRole() === 'targetolog';
    }

    /**
     * Чи є користувач гостем
     */
    public static function isGuest() {
        return Session::isGuest();
    }

    /**
     * Отримати поточного користувача
     */
    public static function user() {
        return Session::getUser();
    }

    /**
     * Повертає абсолютний URL до login.php відносно кореня хоста.
     * Працює з підпапок (finance/, api/) - знаходить корінь проекту
     * незалежно від BASE_URL (який може бути хардкоднутий).
     */
    public static function getLoginUrl() {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';

        // Якщо скрипт всередині /finance/ - вирізаємо все починаючи з /finance/
        $pos = strpos($scriptName, '/finance/');
        if ($pos !== false) {
            $basePath = substr($scriptName, 0, $pos);
        } else {
            // Інакше беремо директорію поточного скрипта
            $basePath = dirname($scriptName);
        }

        // Нормалізація: видалити подвійні слеші, забезпечити слеш на початку
        $basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');
        if ($basePath === '/') {
            return '/login.php';
        }
        return $basePath . '/login.php';
    }

    // ==========================================
    // ДОПОМІЖНІ МЕТОДИ (ПРИВАТНІ)
    // ==========================================

    /**
     * Перевірка чи заблокований користувач через bruteforce
     */
    private static function isLockedOut($username) {
        $attempts = Session::get("login_attempts_$username", []);

        if (count($attempts) < self::MAX_LOGIN_ATTEMPTS) {
            return false;
        }

        $lastAttempt = end($attempts);
        $timeSinceLastAttempt = time() - $lastAttempt;

        return $timeSinceLastAttempt < self::LOCKOUT_TIME;
    }

    /**
     * Отримати час до розблокування
     */
    private static function getRemainingLockoutTime($username) {
        $attempts = Session::get("login_attempts_$username", []);
        $lastAttempt = end($attempts);
        $timeSinceLastAttempt = time() - $lastAttempt;

        return self::LOCKOUT_TIME - $timeSinceLastAttempt;
    }

    /**
     * Зареєструвати невдалу спробу входу
     */
    private static function registerFailedAttempt($username) {
        $attempts = Session::get("login_attempts_$username", []);
        $attempts[] = time();
        Session::set("login_attempts_$username", $attempts);
    }

    /**
     * Очистити невдалі спроби після успішного входу
     */
    private static function clearFailedAttempts($username) {
        Session::delete("login_attempts_$username");
    }

    /**
     * Оновити last_login в users.json
     */
    private static function updateLastLogin($username) {
        $json = file_get_contents(self::USERS_FILE);
        $data = json_decode($json, true);

        foreach ($data['users'] as &$user) {
            if ($user['username'] === $username) {
                $user['last_login'] = date('Y-m-d\TH:i:s\Z');
                break;
            }
        }

        file_put_contents(
            self::USERS_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Замінити utm_term в URL
     */
    private static function replaceUtmTermInUrl($url, $newUtmTerm) {
        $parsedUrl = parse_url($url);
        $query = $parsedUrl['query'] ?? '';

        parse_str($query, $params);
        $params['utm_term'] = $newUtmTerm;

        $newQuery = http_build_query($params);
        $path = $parsedUrl['path'] ?? '/';

        return $path . ($newQuery ? '?' . $newQuery : '');
    }

    /**
     * Додати utm_term в URL
     */
    private static function addUtmTermToUrl($url, $utmTerm) {
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . 'utm_term=' . urlencode($utmTerm);
    }
}
