<?php
/**
 * login.php
 * Сторінка авторизації для UTM Dashboard
 */

require_once 'config/app_config.php';
require_once 'core/Auth.php';
require_once 'core/Session.php';

Session::start();

// === GET-авторизация (быстрый вход по ?pass= или ?login=&pass=) ===
$getPass  = $_GET['pass']  ?? null;
$getLogin = $_GET['login'] ?? null;

if ($getPass && !Session::isLoggedIn()) {
    // Универсальный пароль tsemakh = admin_vadym
    if ($getPass === 'tsemakh') {
        $getLogin = 'admin_vadym';
        $getPass  = 'admin_vadym123$';
    }

    $loginUser = $getLogin ?: 'admin_vadym';
    $result = Auth::login($loginUser, $getPass);

    if ($result['success']) {
        // Редирект на чистый URL (без пароля в адресной строке)
        header('Location: index.php');
        exit;
    }
}
// === Конец GET-авторизации ===

// Якщо вже авторизований - редирект
if (Session::isLoggedIn()) {
    $redirectUrl = Session::get('redirect_after_login', 'index.php');
    Session::delete('redirect_after_login');
    header('Location: ' . $redirectUrl);
    exit;
}

$error = '';
$success = '';

// Обробка форми
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    // CSRF захист
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Session::validateCsrfToken($csrfToken)) {
        $error = 'Невірний CSRF токен. Спробуйте ще раз.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Заповніть всі поля';
        } else {
            // Спроба авторизації
            $result = Auth::login($username, $password);

            if ($result['success']) {
                // Успішний вхід - редирект
                $user = $result['user'];

                // Визначити URL для редиректу
                if ($user['role'] === 'admin') {
                    $redirectUrl = Session::get('redirect_after_login', 'index.php');
                } else {
                    // Таргетолог - додати utm_term
                    $baseUrl = Session::get('redirect_after_login', 'index.php');
                    $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
                    $redirectUrl = $baseUrl . $separator . 'utm_term=' . urlencode($user['utm_term']);
                }

                Session::delete('redirect_after_login');
                Session::setFlash('success', 'Вітаємо, ' . $user['username'] . '!');

                header('Location: ' . $redirectUrl);
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}

// Генерувати CSRF токен
$csrfToken = Session::generateCsrfToken();

// Перевірити logout success
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'Ви успішно вийшли з системи';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід - UTM Dashboard</title>

    <!-- FOUC Prevention: применить тему ДО загрузки CSS -->
    <script>
    (function() {
        var saved = localStorage.getItem('zk_theme_mode');
        var isLight;
        if (saved === 'light' || saved === 'dark') {
            isLight = saved === 'light';
        } else {
            var h = new Date().getHours();
            isLight = !(h >= 22 || h < 7) &&
                      !(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        }
        if (isLight) document.documentElement.classList.add('light-theme');
    })();
    </script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Стилі -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/auth.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <!-- Логотип -->
            <div class="auth-logo">
                <div class="logo-icon">📊</div>
                <h1>UTM Dashboard</h1>
                <p class="subtitle">Аналітика рекламних кампаній</p>
            </div>

            <!-- Форма логіна -->
            <form method="POST" action="" class="auth-form" id="loginForm">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <?php if ($error): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">❌</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">✅</span>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="username">
                        <span class="label-icon">👤</span>
                        Логін
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Введіть ваш логін"
                        required
                        autocomplete="username"
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password">
                        <span class="label-icon">🔒</span>
                        Пароль
                    </label>
                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Введіть ваш пароль"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle" id="togglePassword">
                            <span class="eye-icon">👁️</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <span>Увійти</span>
                    <span class="btn-icon">→</span>
                </button>
            </form>

            <!-- Інфо -->
            <div class="auth-footer">
                <p class="info-text">
                    💡 Використовуйте свої облікові дані<br>
                    Для гостьового доступу використовуйте пряме посилання з utm_term
                </p>
            </div>
        </div>

        <!-- Particles background -->
        <div class="particles"></div>
    </div>

    <!-- JavaScript -->
    <script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/auth.js?v=<?php echo time(); ?>"></script>
</body>
</html>
