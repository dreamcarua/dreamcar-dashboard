<?php
// === index.php ===
// finance/index.php
// НАЗНАЧЕНИЕ: SPA entry point финансового модуля DreamCar
// СВЯЗИ: ../config/app_config.php, ../core/Auth.php, core/FinanceAuth.php
// РАЗМЕР: ~130 строк

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/core/FinanceAuth.php';
require_once __DIR__ . '/core/models/FinanceTransaction.php';

// Проверить основную авторизацию UTM Dashboard
Auth::checkAccess();

// Перевірити право доступу до фінансового модуля
if (!Auth::hasPermission('finance')) {
    header('Location: ../index.php');
    exit;
}

// Проверить доступ к финансовому модулю
FinanceAuth::checkAccess();

$financeRole  = FinanceAuth::getFinanceRole();
$username     = FinanceAuth::getUsername();
$canWrite     = FinanceAuth::canWrite();
$canViewUsdt  = FinanceAuth::canViewUsdt();
$userRole     = Session::getRole();

$logger = new Logger();
$logger->log('Загрузка финансового модуля', 'info', [
    'username'     => $username,
    'finance_role' => $financeRole,
]);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💼 Фінанси | DreamCar Dashboard</title>

    <!-- FOUC Prevention: тема ДО загрузки CSS -->
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

    <!-- Шрифты: Inter + JetBrains Mono (Dev Terminal SaaS) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS родительского дашборда -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/animations.css?v=<?php echo time(); ?>">

    <!-- CSS финансового модуля -->
    <link rel="stylesheet" href="assets/css/finance.css?v=<?php echo time(); ?>">
</head>
<body
    data-finance-role="<?php echo htmlspecialchars($financeRole ?? '', ENT_QUOTES); ?>"
    data-can-write="<?php echo $canWrite ? '1' : '0'; ?>"
    data-can-usdt="<?php echo $canViewUsdt ? '1' : '0'; ?>"
    data-username="<?php echo htmlspecialchars($username, ENT_QUOTES); ?>"
>

<div class="dashboard-container">

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-content">
            <div class="header-left">
                <h1 class="gradient-text">💼 Фінансовий модуль</h1>
                <p class="text-muted">DreamCar — облiк доходiв, витрат, P&amp;L проектiв</p>
            </div>
            <div class="header-title-actions">
                <!-- ➕ Додати витрату (только для admin/finance_manager) -->
                <?php if ($canWrite): ?>
                <button type="button" class="btn btn-success btn-sm" id="btn-open-add-expense" title="Додати будь-яку витрату">
                    ➕ Додати витрату
                </button>
                <?php endif; ?>
                <!-- Назад -->
                <a href="../index.php" class="btn btn-secondary btn-sm">
                    ← UTM Dashboard
                </a>
                <!-- Dark/Light toggle (inline SVG — НЕ lucide) -->
                <button id="zk-theme-toggle" class="btn btn-secondary btn-sm zk-theme-btn" title="Змiнити тему">
                    <!-- Sun icon (показывается в dark режиме) -->
                    <svg class="zk-icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                    <!-- Moon icon (показывается в light режиме) -->
                    <svg class="zk-icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
                <!-- Пользователь -->
                <span class="text-muted" style="font-size:0.8rem;">
                    <?php echo htmlspecialchars($username); ?>
                    <span class="badge badge-role"><?php echo htmlspecialchars($financeRole ?? ''); ?></span>
                </span>
            </div>
        </div>

        <!-- Навигация SPA -->
        <nav class="finance-nav" id="finance-nav">
            <button class="nav-btn active" data-section="dashboard">📊 Дашборд</button>
            <button class="nav-btn" data-section="projects">💰 Проекти</button>
            <button class="nav-btn" data-section="expenses">📣 Витрати</button>
            <?php if (Auth::hasPermission('finance_salary')): ?>
            <button class="nav-btn" data-section="payroll">👥 Зарплати</button>
            <?php endif; ?>
            <?php if (Auth::hasPermission('finance_cards')): ?>
            <button class="nav-btn" data-section="cards">💳 Картки</button>
            <?php endif; ?>
            <?php if ($canViewUsdt && Auth::hasPermission('finance_usdt')): ?>
            <button class="nav-btn" data-section="usdt">💱 USDT</button>
            <?php endif; ?>
            <?php if (Auth::hasPermission('finance_settings')): ?>
            <button class="nav-btn" data-section="settings">⚙️ Налаштування</button>
            <?php endif; ?>
        </nav>
    </header>

    <!-- Секции SPA -->
    <main class="finance-main">

        <div class="content-section active" id="dashboard-section">
            <div class="section-loading">
                <div class="loading-spinner"></div>
                <p>Завантаження дашборду...</p>
            </div>
        </div>

        <div class="content-section" id="projects-section">
            <div class="section-loading">
                <div class="loading-spinner"></div>
                <p>Завантаження проектiв...</p>
            </div>
        </div>

        <div class="content-section" id="expenses-section">
            <div class="section-loading">
                <div class="loading-spinner"></div>
                <p>Завантаження витрат...</p>
            </div>
        </div>

        <div class="content-section" id="payroll-section">
            <div id="payroll-employees-wrap">
                <div class="section-loading">
                    <div class="loading-spinner"></div>
                    <p>Завантаження зарплат...</p>
                </div>
            </div>
            <div id="payroll-journal-wrap" style="margin-top:16px;"></div>
        </div>

        <div class="content-section" id="cards-section">
            <div id="cards-wrap">
                <div class="section-loading">
                    <div class="loading-spinner"></div>
                    <p>Завантаження карток...</p>
                </div>
            </div>
        </div>

        <?php if ($canViewUsdt): ?>
        <div class="content-section" id="usdt-section">
            <div id="usdt-wrap">
                <div class="section-loading">
                    <div class="loading-spinner"></div>
                    <p>Завантаження USDT...</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="content-section" id="settings-section">
            <div class="section-loading">
                <div class="loading-spinner"></div>
                <p>Завантаження налаштувань...</p>
            </div>
        </div>

    </main>
</div><!-- .dashboard-container -->

<!-- jQuery CDN -->
<script src="https://code.jquery.com/jquery-4.0.0.min.js" crossorigin="anonymous"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- Передать справочник категорий в JavaScript (для модалки "Додати витрату") -->
<script>
window.FINANCE_CATEGORIES = <?php echo json_encode(FinanceTransaction::getExpenseCategoriesGrouped(), JSON_UNESCAPED_UNICODE); ?>;
</script>

<!-- JS финансового модуля (порядок важен) -->
<script src="assets/js/finance-app.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/finance-dashboard.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/finance-projects.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/finance-expenses.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/finance-payroll.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/finance-cards.js?v=<?php echo time(); ?>"></script>
<?php if ($canViewUsdt): ?>
<script src="assets/js/finance-usdt.js?v=<?php echo time(); ?>"></script>
<?php endif; ?>
<script src="assets/js/finance-settings.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/finance-add-expense.js?v=<?php echo time(); ?>"></script>

</body>
</html>
