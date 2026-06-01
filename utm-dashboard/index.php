<?php
// === index.php ===
// /home/rizakz/tsemakh.pp.ua/www/utm-dashboard/index.php
// НАЗНАЧЕНИЕ: Главная страница дашборда UTM-меток из SendPulse
// СВЯЗИ: config/app_config.php, core/Logger.php, api/handler.php
// ДАННЫЕ: data/utm_clean.json, log_actual.json
// API: SendPulse, Google Sheets
// РАЗМЕР: ~450 строк
// ОБНОВЛЕНО: 2025-11-15 12:00

require_once 'config/app_config.php';
require_once 'core/Logger.php';
require_once 'core/Auth.php';
require_once 'core/Session.php';

// Перевірка доступу (обробляє всі сценарії A, B, C)
Auth::checkAccess();

// Отримати роль та utm_term користувача
$userRole = Session::getRole();
$isGuest = Auth::isGuest();
$username = Session::get('user')['username'] ?? 'guest';

// Для гостей брати utm_term з URL, для авторизованих - з сесії
if ($isGuest) {
    $userUtmTerm = $_GET['utm_term'] ?? null;
} else {
    $userUtmTerm = Auth::getUserUtmTerm();
}

$logger = new Logger();
$logger->log('Загрузка главной страницы дашборда', 'info', [
    'username' => $username,
    'role' => $userRole
]);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 UTM Dashboard | SendPulse Analytics</title>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/animations.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/date-filter.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/settings.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Главный контейнер -->
    <div class="dashboard-container">

        <!-- Заголовок -->
        <header class="dashboard-header">
            <div class="header-content">
                <div class="header-left">
                    <h1 class="gradient-text">📊 UTM Analytics Dashboard</h1>
                    <p class="text-muted">Анализ лидов из SendPulse по UTM-меткам</p>
                </div>
                <div class="header-title-actions">
                    <?php if ($userRole === 'admin'): ?>
                    <a href="upload_deals.php" class="btn btn-secondary btn-sm">
                        📤 Загрузить сделки
                    </a>
                    <?php endif; ?>

                    <?php if ($userRole === 'admin' || $userRole === 'targetolog' || $userRole === 'guest'): ?>
                    <a href="manual_costs.php<?php echo $userUtmTerm ? '?utm_term=' . urlencode($userUtmTerm) : ''; ?>" class="btn btn-secondary btn-sm">
                        💰 Указать затраты вручную
                    </a>
                    <?php endif; ?>

                    <?php if ($userRole === 'admin'): ?>
                    <a href="webhook_logs.php" class="btn btn-secondary btn-sm">
                        📝 Webhook Логи
                    </a>
                    <a href="utm_mapping.php" class="btn btn-secondary btn-sm">
                        🔗 Відповідності CRM-ADS
                    </a>
                    <a href="#" id="openSettingsBtn" class="btn btn-secondary btn-sm">
                        ⚙️ Настройки
                    </a>
                    <?php endif; ?>

                    <?php if ($isGuest): ?>
                    <a href="login.php" class="btn btn-success btn-sm">
                        🔑 Увійти
                    </a>
                    <?php else: ?>
                    <span class="user-info">
                        👤 <?php echo htmlspecialchars($username); ?>
                        <?php if ($userRole === 'admin'): ?>
                            <span class="badge badge-admin">ADMIN</span>
                        <?php elseif ($userRole === 'targetolog'): ?>
                            <span class="badge badge-targetolog">Таргетолог</span>
                        <?php endif; ?>
                    </span>
                    <a href="logout.php" class="btn btn-outline btn-sm">
                        🚪 Вийти
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="header-filters">
                <div class="date-filter">
                    <label>📅 Период:</label>
                    <select class="filter-select" id="dateRangeFilter">
                        <option value="all" selected>Весь период</option>
                        <option value="today">Сегодня</option>
                        <option value="yesterday">Вчера</option>
                        <option value="7days">Последние 7 дней</option>
                        <option value="30days">Последние 30 дней</option>
                        <option value="60days">Последние 60 дней</option>
                        <option value="custom">Свой период</option>
                    </select>
                </div>
                <div class="date-filter">
                    <label>🏷️ Проект:</label>
                    <select class="filter-select" id="modelFilter">
                        <?php
                        // Завантажити активний проект з налаштувань
                        $settingsFile = __DIR__ . '/config/dashboard_settings.json';
                        $activeProject = 'BMW X5 HYBRID';

                        if (file_exists($settingsFile)) {
                            $settings = json_decode(file_get_contents($settingsFile), true);
                            $activeProject = isset($settings['active_project']) ? $settings['active_project'] : 'BMW X5 HYBRID';
                        }

                        // Список проектов из CrmDeal
                        require_once __DIR__ . '/core/Database.php';
                        $db = Database::getInstance();
                        require_once __DIR__ . '/core/models/CrmDeal.php';

                        $mainProjects = CrmDeal::getMainProjects();
                        $projectDates = CrmDeal::getProjectDates();

                        $projects = ['all' => 'Все проекты'];
                        foreach ($mainProjects as $p) {
                            $dates = $projectDates[$p] ?? null;
                            $label = $p;
                            if ($dates) {
                                $label .= ' (' . date('d.m.y', strtotime($dates['date_from'])) . '-' . date('d.m.y', strtotime($dates['date_to'])) . ')';
                            }
                            $projects[$p] = $label;
                        }

                        foreach ($projects as $value => $label) {
                            $selected = (strtoupper($value) === strtoupper($activeProject)) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($value) . "\" {$selected}>" . htmlspecialchars($label) . "</option>\n";
                        }
                        ?>
                    </select>
                    <script>
                    window.PROJECT_DATES = <?php echo json_encode($projectDates); ?>;
                    </script>
                </div>
                <div class="date-filter">
                    <label>👥 Клиенты:</label>
                    <select class="filter-select" id="customerTypeFilter">
                        <option value="all" selected>Все клиенты</option>
                        <option value="new">Новые (первая покупка)</option>
                        <option value="returning">Существующие (повторная покупка)</option>
                    </select>
                </div>
                <div class="date-filter">
                    <label>🎯 Воронка:</label>
                    <select class="filter-select" id="funnelTypeFilter">
                        <option value="all" selected>Все воронки</option>
                        <option value="new_in_funnel">Новые в воронке</option>
                        <option value="returning_in_funnel">Повторные в воронке</option>
                    </select>
                </div>
                <div class="date-filter">
                    <label>📦 Тариф:</label>
                    <select class="filter-select" id="tariffFilter">
                        <option value="all" selected>Все тарифы</option>
                        <option value="Пробний">Пробний</option>
                        <option value="Мінімум">Мінімум</option>
                        <option value="Базовий">Базовий</option>
                        <option value="Популярний">Популярний</option>
                    </select>
                </div>
                <div class="date-filter">
                    <label>💳 Платежка:</label>
                    <select class="filter-select" id="payProviderFilter">
                        <option value="all" selected>Все платежки</option>
                        <option value="WayForPay">WayForPay</option>
                        <option value="Platon">Platon</option>
                        <option value="Lava.top">Lava.top</option>
                    </select>
                </div>
                <div class="last-update-indicator" id="lastUpdateIndicator">
                    <span class="update-status" id="updateStatus">🔄 Загрузка...</span>
                    <span class="update-time" id="updateTime"></span>
                </div>
            </div>
        </header>

        <!-- UTM Фильтры -->
        <div class="utm-filters-row">
            <div class="utm-filter">
                <label>📍 Source:</label>
                <input type="text" class="filter-input" id="utmSourceFilter" placeholder="instagram">
            </div>
            <div class="utm-filter">
                <label>🔗 Medium:</label>
                <input type="text" class="filter-input" id="utmMediumFilter" placeholder="cpc">
            </div>
            <div class="utm-filter">
                <label>🎯 Campaign:</label>
                <input type="text" class="filter-input" id="utmCampaignFilter" placeholder="sale_2024">
            </div>
            <div class="utm-filter">
                <label>🔑 Term:</label>
                <input type="text" class="filter-input" id="utmTermFilter" placeholder="vira">
            </div>
            <div class="utm-filter">
                <label>🎨 Content:</label>
                <input type="text" class="filter-input" id="utmContentFilter" placeholder="banner1">
            </div>
            <button class="btn btn-primary btn-sm" id="applyUtmFilters">🔍 Применить</button>
            <button class="btn btn-outline btn-sm" id="clearUtmFilters">✕ Сбросить</button>
        </div>

        <!-- Статистика -->
        <section class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalLeads">0</div>
                        <div class="stat-label">Всего лидов</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalPaid">0</div>
                        <div class="stat-label">Всего оплат</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalAmount">0 UAH</div>
                        <div class="stat-label">Заработано (оплаты)</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">💵</div>
                    <div class="stat-content">
                        <div class="stat-value" id="avgAmount">0 UAH</div>
                        <div class="stat-label">Средний чек</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📍</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalSources">0</div>
                        <div class="stat-label">Источников</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🎯</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalCampaigns">0</div>
                        <div class="stat-label">Кампаний</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">💸</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalAdsSpend">0 UAH</div>
                        <div class="stat-label">Рекламные расходы</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalProfit">0 UAH</div>
                        <div class="stat-label">Прибыль</div>
                    </div>
                </div>

                <div class="stat-card" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalROI">0%</div>
                        <div class="stat-label">ROI</div>
                    </div>
                </div>

                <div class="stat-card" title="Return on Ad Spend = Заработано / Расход. Показывает сколько гривен заработано на каждую потраченную гривну.">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalROAS">0</div>
                        <div class="stat-label">ROAS</div>
                    </div>
                </div>

                <div class="stat-card" title="Cost Per Lead = Расход / Лиды. Стоимость привлечения одного лида.">
                    <div class="stat-icon">👤</div>
                    <div class="stat-content">
                        <div class="stat-value" id="avgCPL">0 UAH</div>
                        <div class="stat-label">CPL (стоимость лида)</div>
                    </div>
                </div>

                <div class="stat-card" title="Cost Per Acquisition = Расход / Оплачено. Стоимость привлечения одного платящего клиента.">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value" id="avgCPA">0 UAH</div>
                        <div class="stat-label">CPA (стоимость оплаты)</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Навигация между разделами -->
        <nav class="section-nav">
            <button class="nav-btn active" data-section="overview">📊 Обзор</button>
            <button class="nav-btn" data-section="analytics">💰 Аналитика</button>
            <button class="nav-btn" data-section="combinations">🔗 Комбинации</button>
            <button class="nav-btn" data-section="sources">📍 Источники</button>
            <button class="nav-btn" data-section="medium">🔗 Тип трафика</button>
            <button class="nav-btn" data-section="campaigns">🎯 Кампании</button>
            <button class="nav-btn" data-section="term">🔑 Исполнитель</button>
            <button class="nav-btn" data-section="content">🎨 Объявления</button>
            <button class="nav-btn" data-section="table">📋 Таблица</button>
            <button class="nav-btn" data-section="help">❓ Справка</button>
        </nav>

        <!-- Секция: Детальная аналитика -->
        <section class="content-section" id="analytics-section">
            <div class="section-header">
                <h2>💰 Детальная аналитика по UTM меткам</h2>
                <div class="section-actions">
                    <select class="filter-select" id="analyticsDataTypeFilter">
                        <option value="all">Все источники</option>
                        <option value="common">🟢 Общие (CRM + ADS)</option>
                        <option value="crm_only">🔵 CRM-only (органика)</option>
                        <option value="ads_only">🟡 ADS-only (без лидов)</option>
                    </select>
                    <button class="btn btn-outline" id="exportAnalyticsBtn">💾 Экспорт</button>
                </div>
            </div>

            <!-- Статистика по типам -->
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <div class="stat-value" id="analyticsLeads">0</div>
                        <div class="stat-label">Всего лидов</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value" id="analyticsPaid">0</div>
                        <div class="stat-label">Оплаченых</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">❌</div>
                    <div class="stat-content">
                        <div class="stat-value" id="analyticsFailed">0</div>
                        <div class="stat-label">Неуспешно</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-content">
                        <div class="stat-value" id="analyticsPending">0</div>
                        <div class="stat-label">В процессе</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <div class="stat-value" id="analyticsPaidAmount">0 UAH</div>
                        <div class="stat-label">Заработано</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💸</div>
                    <div class="stat-content">
                        <div class="stat-value" id="analyticsLostAmount">0 UAH</div>
                        <div class="stat-label">Потеряно</div>
                    </div>
                </div>
            </div>

            <!-- Таблица аналитики по источникам -->
            <div class="section-header">
                <h3>📍 Аналитика по источникам</h3>
            </div>
            <div class="table-container">
                <table class="data-table" id="analyticsSourcesTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="source">Источник</th>
                            <th class="sortable" data-sort="leads">Лиды</th>
                            <th class="sortable" data-sort="paid">Оплачено</th>
                            <th class="sortable" data-sort="failed">Неуспешно</th>
                            <th class="sortable" data-sort="pending">В процессе</th>
                            <th class="sortable" data-sort="paidAmount">💰 Заработано</th>
                            <th class="sortable" data-sort="adsSpend">💸 Расходы на рекламу</th>
                            <th class="sortable" data-sort="profit">💰 Прибыль</th>
                            <th class="sortable" data-sort="roi" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">📈 ROI</th>
                            <th class="sortable" data-sort="roas" title="Return on Ad Spend = Заработано / Расход. Показывает сколько гривен заработано на каждую потраченную гривну.">💰 ROAS</th>
                            <th class="sortable" data-sort="cpl" title="Cost Per Lead = Расход / Лиды. Стоимость привлечения одного лида.">👤 CPL</th>
                            <th class="sortable" data-sort="cpa" title="Cost Per Acquisition = Расход / Оплачено. Стоимость привлечения одного платящего клиента.">✅ CPA</th>
                            <th class="sortable" data-sort="conversion" title="Conversion Rate = (Оплачено / Лиды) × 100%. Процент лидов, ставших платящими клиентами.">📈 Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Данные загружаются через JS -->
                    </tbody>
                </table>
                <div class="empty-state" id="analyticsSourcesTableEmpty" style="display: none;">
                    <div class="empty-state-icon">📊</div>
                    <div class="empty-state-title">Нет данных для аналитики</div>
                </div>
                <div class="table-pagination" id="analyticsSourcesTablePagination" style="display: none;"></div>
            </div>
        </section>

        <!-- Секция: Комбинации UTM меток -->
        <section class="content-section" id="combinations-section">
            <div class="section-header">
                <h2>🔗 Аналитика по комбинациям UTM меток</h2>
                <div class="section-actions">
                    <select class="filter-select" id="combinationsDataTypeFilter">
                        <option value="all">Все источники</option>
                        <option value="common">🟢 Общие (CRM + ADS)</option>
                        <option value="crm_only">🔵 CRM-only (органика)</option>
                        <option value="ads_only">🟡 ADS-only (без лидов)</option>
                    </select>
                    <select class="filter-select" id="combinationType">
                        <option value="source_medium">Source + Medium</option>
                        <option value="source_campaign">Source + Campaign</option>
                        <option value="medium_campaign">Medium + Campaign</option>
                        <option value="source_medium_campaign">Source + Medium + Campaign</option>
                        <option value="full">Все метки</option>
                    </select>
                    <button class="btn btn-outline" id="exportCombinationsBtn">💾 Экспорт</button>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table" id="combinationsTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="combination">Комбинация</th>
                            <th class="sortable" data-sort="leads">Лиды</th>
                            <th class="sortable" data-sort="paid">Оплачено</th>
                            <th class="sortable" data-sort="failed">Неуспешно</th>
                            <th class="sortable" data-sort="pending">В процессе</th>
                            <th class="sortable" data-sort="paidAmount">💰 Заработано</th>
                            <th class="sortable" data-sort="adsSpend">💸 Расходы</th>
                            <th class="sortable" data-sort="profit">💰 Прибыль</th>
                            <th class="sortable" data-sort="roi" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">📈 ROI</th>
                            <th class="sortable" data-sort="roas" title="Return on Ad Spend = Заработано / Расход. Показывает сколько гривен заработано на каждую потраченную гривну.">💰 ROAS</th>
                            <th class="sortable" data-sort="conversion" title="Conversion Rate = (Оплачено / Лиды) × 100%. Процент лидов, ставших платящими клиентами.">📈 Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Данные загружаются через JS -->
                    </tbody>
                </table>
                <div class="empty-state" id="combinationsTableEmpty" style="display: none;">
                    <div class="empty-state-icon">🔗</div>
                    <div class="empty-state-title">Нет данных для комбинаций</div>
                </div>
                <div class="table-pagination" id="combinationsTablePagination" style="display: none;"></div>
            </div>
        </section>

        <!-- Секция: Обзор -->
        <section class="content-section active" id="overview-section">
            <div class="section-header">
                <h2>📊 Общий обзор</h2>
                <div class="section-actions">
                    <button class="btn btn-outline" id="exportOverviewBtn">💾 JSON</button>
                    <button class="btn btn-outline" id="exportCSVBtn">📊 CSV</button>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card">
                    <h3 id="leadsTimelineTitle">📊 Лиды по дням</h3>
                    <canvas id="leadsTimelineChart"></canvas>
                    <div class="empty-state" id="leadsTimelineChartEmpty" style="display: none;">
                        <div class="empty-state-icon">📊</div>
                        <div class="empty-state-title">Нет данных для графика</div>
                    </div>
                </div>

                <div class="chart-card">
                    <h3 id="amountTimelineTitle">💰 Деньги по дням</h3>
                    <canvas id="amountTimelineChart"></canvas>
                    <div class="empty-state" id="amountTimelineChartEmpty" style="display: none;">
                        <div class="empty-state-icon">💰</div>
                        <div class="empty-state-title">Нет данных для графика</div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card">
                    <h3>👥 Распределение лидов по источникам</h3>
                    <canvas id="leadsDistributionChart"></canvas>
                    <div class="empty-state" id="leadsDistributionChartEmpty" style="display: none;">
                        <div class="empty-state-icon">👥</div>
                        <div class="empty-state-title">Нет данных для графика</div>
                    </div>
                </div>

                <div class="chart-card">
                    <h3>💰 Распределение сумм по источникам</h3>
                    <canvas id="sourcesDistributionChart"></canvas>
                    <div class="empty-state" id="sourcesDistributionChartEmpty" style="display: none;">
                        <div class="empty-state-icon">💰</div>
                        <div class="empty-state-title">Нет данных для графика</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Секция: Источники -->
        <section class="content-section" id="sources-section">
            <div class="section-header">
                <h2>📍 UTM Source - Источники трафика</h2>
                <div class="section-actions">
                    <select class="filter-select" id="sourcesDataTypeFilter">
                        <option value="all">Все источники</option>
                        <option value="common">🟢 Общие (CRM + ADS)</option>
                        <option value="crm_only">🔵 CRM-only (органика)</option>
                        <option value="ads_only">🟡 ADS-only (без лидов)</option>
                    </select>
                    <input type="text" class="search-input" id="sourcesSearch" placeholder="🔍 Поиск по источникам...">
                </div>
            </div>

            <div class="table-container">
                <table class="data-table" id="sourcesTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="source">Источник</th>
                            <th class="sortable" data-sort="leads">Лиды</th>
                            <th class="sortable" data-sort="paid">Оплачено</th>
                            <th class="sortable" data-sort="amount">💰 Заработано</th>
                            <th class="sortable" data-sort="adsSpend">💸 Расходы</th>
                            <th class="sortable" data-sort="profit">💰 Прибыль</th>
                            <th class="sortable" data-sort="roi" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">📈 ROI</th>
                            <th class="sortable" data-sort="roas" title="Return on Ad Spend = Заработано / Расход. Показывает сколько гривен заработано на каждую потраченную гривну.">💰 ROAS</th>
                            <th class="sortable" data-sort="cpl" title="Cost Per Lead = Расход / Лиды. Стоимость привлечения одного лида.">👤 CPL</th>
                            <th class="sortable" data-sort="cpa" title="Cost Per Acquisition = Расход / Оплачено. Стоимость привлечения одного платящего клиента.">✅ CPA</th>
                            <th class="sortable" data-sort="conversion" title="Conversion Rate = (Оплачено / Лиды) × 100%. Процент лидов, ставших платящими клиентами.">📈 Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Данные загружаются через JS -->
                    </tbody>
                </table>
                <div class="empty-state" id="sourcesTableEmpty" style="display: none;">
                    <div class="empty-state-icon">📊</div>
                    <div class="empty-state-title">Нет данных по источникам</div>
                    <div class="empty-state-message">Попробуйте изменить период фильтрации или синхронизировать данные</div>
                </div>
                <div class="table-pagination" id="sourcesTablePagination" style="display: none;"></div>
            </div>

            <div class="chart-card">
                <h3>График распределения источников</h3>
                <canvas id="sourcesChart"></canvas>
                <div class="empty-state" id="sourcesChartEmpty" style="display: none;">
                    <div class="empty-state-icon">📊</div>
                    <div class="empty-state-title">Нет данных для графика</div>
                </div>
            </div>
        </section>

        <!-- Секция: Medium -->
        <section class="content-section" id="medium-section">
            <div class="section-header">
                <h2>🔗 UTM Medium - Тип трафика</h2>
                <div class="section-actions">
                    <select class="filter-select" id="mediumDataTypeFilter">
                        <option value="all">Все источники</option>
                        <option value="common">🟢 Общие (CRM + ADS)</option>
                        <option value="crm_only">🔵 CRM-only (органика)</option>
                        <option value="ads_only">🟡 ADS-only (без лидов)</option>
                    </select>
                    <input type="text" class="search-input" id="mediumSearch" placeholder="🔍 Поиск по типу трафика...">
                </div>
            </div>

            <div class="table-container">
                <table class="data-table" id="mediumTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="medium">Тип трафика</th>
                            <th class="sortable" data-sort="leads">Лиды</th>
                            <th class="sortable" data-sort="paid">Оплачено</th>
                            <th class="sortable" data-sort="amount">💰 Заработано</th>
                            <th class="sortable" data-sort="adsSpend">💸 Расходы</th>
                            <th class="sortable" data-sort="profit">💰 Прибыль</th>
                            <th class="sortable" data-sort="roi" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">📈 ROI</th>
                            <th class="sortable" data-sort="roas" title="Return on Ad Spend = Заработано / Расход. Показывает сколько гривен заработано на каждую потраченную гривну.">💰 ROAS</th>
                            <th class="sortable" data-sort="cpl" title="Cost Per Lead = Расход / Лиды. Стоимость привлечения одного лида.">👤 CPL</th>
                            <th class="sortable" data-sort="cpa" title="Cost Per Acquisition = Расход / Оплачено. Стоимость привлечения одного платящего клиента.">✅ CPA</th>
                            <th class="sortable" data-sort="conversion" title="Conversion Rate = (Оплачено / Лиды) × 100%. Процент лидов, ставших платящими клиентами.">📈 Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Данные загружаются через JS -->
                    </tbody>
                </table>
                <div class="empty-state" id="mediumTableEmpty" style="display: none;">
                    <div class="empty-state-icon">🔗</div>
                    <div class="empty-state-title">Нет данных по типам трафика</div>
                    <div class="empty-state-message">Попробуйте изменить период фильтрации или синхронизировать данные</div>
                </div>
                <div class="table-pagination" id="mediumTablePagination" style="display: none;"></div>
            </div>

            <div class="chart-card">
                <h3>Диаграмма распределения типов трафика</h3>
                <canvas id="mediumChart"></canvas>
                <div class="empty-state" id="mediumChartEmpty" style="display: none;">
                    <div class="empty-state-icon">🔗</div>
                    <div class="empty-state-title">Нет данных для графика</div>
                </div>
            </div>
        </section>

        <!-- Секция: Кампании -->
        <section class="content-section" id="campaigns-section">
            <div class="section-header">
                <h2>🎯 UTM Campaign - Кампании</h2>
                <div class="section-actions">
                    <select class="filter-select" id="campaignsDataTypeFilter">
                        <option value="all">Все источники</option>
                        <option value="common">🟢 Общие (CRM + ADS)</option>
                        <option value="crm_only">🔵 CRM-only (органика)</option>
                        <option value="ads_only">🟡 ADS-only (без лидов)</option>
                    </select>
                    <input type="text" class="search-input" id="campaignsSearch" placeholder="🔍 Поиск по кампаниям...">
                    <select class="filter-select" id="campaignsFilter">
                        <option value="all">Все кампании</option>
                        <option value="top10">ТОП-10</option>
                        <option value="active">Активные</option>
                    </select>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table" id="campaignsTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="campaign">Кампания</th>
                            <th class="sortable" data-sort="leads">Лиды</th>
                            <th class="sortable" data-sort="paid">Оплачено</th>
                            <th class="sortable" data-sort="amount">💰 Заработано</th>
                            <th class="sortable" data-sort="adsSpend">💸 Расходы</th>
                            <th class="sortable" data-sort="profit">💰 Прибыль</th>
                            <th class="sortable" data-sort="roi" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">📈 ROI</th>
                            <th class="sortable" data-sort="roas" title="Return on Ad Spend = Заработано / Расход. Показывает сколько гривен заработано на каждую потраченную гривну.">💰 ROAS</th>
                            <th class="sortable" data-sort="cpl" title="Cost Per Lead = Расход / Лиды. Стоимость привлечения одного лида.">👤 CPL</th>
                            <th class="sortable" data-sort="cpa" title="Cost Per Acquisition = Расход / Оплачено. Стоимость привлечения одного платящего клиента.">✅ CPA</th>
                            <th class="sortable" data-sort="conversion" title="Conversion Rate = (Оплачено / Лиды) × 100%. Процент лидов, ставших платящими клиентами.">📈 Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Данные загружаются через JS -->
                    </tbody>
                </table>
                <div class="empty-state" id="campaignsTableEmpty" style="display: none;">
                    <div class="empty-state-icon">🎯</div>
                    <div class="empty-state-title">Нет данных по кампаниям</div>
                    <div class="empty-state-message">Попробуйте изменить период фильтрации или синхронизировать данные</div>
                </div>
                <div class="table-pagination" id="campaignsTablePagination" style="display: none;"></div>
            </div>
        </section>

        <!-- Секция: Детали -->
        <!-- Секция: UTM Term (Исполнитель) -->
        <section class="content-section" id="term-section">
            <div class="section-header">
                <h2>🔑 UTM Term - Исполнитель</h2>
                <div class="section-actions">
                    <select class="filter-select" id="termDataTypeFilter">
                        <option value="all">Все источники</option>
                        <option value="common">🟢 Общие (CRM + ADS)</option>
                        <option value="crm_only">🔵 CRM-only (органика)</option>
                        <option value="ads_only">🟡 ADS-only (без лидов)</option>
                    </select>
                    <button class="btn btn-outline" id="refreshTermBtn">🔄 Обновить</button>
                </div>
            </div>
            <div class="table-container">
                <table class="data-table" id="termTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="term">Исполнитель</th>
                            <th class="sortable" data-sort="leads">Лиды</th>
                            <th class="sortable" data-sort="paid">Оплачено</th>
                            <th class="sortable" data-sort="amount">💰 Заработано</th>
                            <th class="sortable" data-sort="adsSpend">💸 Расходы</th>
                            <th class="sortable" data-sort="profit">💰 Прибыль</th>
                            <th class="sortable" data-sort="roi" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">📈 ROI</th>
                            <th class="sortable" data-sort="conversion" title="Conversion Rate = (Оплачено / Лиды) × 100%. Процент лидов, ставших платящими клиентами.">📈 Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Данные загружаются через JS -->
                    </tbody>
                </table>
                <div class="empty-state" id="termTableEmpty" style="display: none;">
                    <div class="empty-state-icon">🔑</div>
                    <div class="empty-state-title">Нет данных по ключевым словам</div>
                    <div class="empty-state-message">Попробуйте изменить период фильтрации</div>
                </div>
                <div class="table-pagination" id="termTablePagination" style="display: none;"></div>
            </div>
        </section>

        <!-- Секция: UTM Content (Варианты объявлений) -->
        <section class="content-section" id="content-section">
            <div class="section-header">
                <h2>🎨 UTM Content - Варианты объявлений</h2>
                <div class="section-actions">
                    <select class="filter-select" id="contentDataTypeFilter">
                        <option value="all">Все источники</option>
                        <option value="common">🟢 Общие (CRM + ADS)</option>
                        <option value="crm_only">🔵 CRM-only (органика)</option>
                        <option value="ads_only">🟡 ADS-only (без лидов)</option>
                    </select>
                    <button class="btn btn-outline" id="refreshContentBtn">🔄 Обновить</button>
                </div>
            </div>
            <div class="table-container">
                <table class="data-table" id="contentTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="content">Вариант</th>
                            <th class="sortable" data-sort="leads">Лиды</th>
                            <th class="sortable" data-sort="paid">Оплачено</th>
                            <th class="sortable" data-sort="amount">💰 Заработано</th>
                            <th class="sortable" data-sort="adsSpend">💸 Расходы</th>
                            <th class="sortable" data-sort="profit">💰 Прибыль</th>
                            <th class="sortable" data-sort="roi" title="Return on Investment = ((Заработано - Расход) / Расход) × 100%. Показывает процент прибыли от инвестиций.">📈 ROI</th>
                            <th class="sortable" data-sort="conversion" title="Conversion Rate = (Оплачено / Лиды) × 100%. Процент лидов, ставших платящими клиентами.">📈 Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Данные загружаются через JS -->
                    </tbody>
                </table>
                <div class="empty-state" id="contentTableEmpty" style="display: none;">
                    <div class="empty-state-icon">🎨</div>
                    <div class="empty-state-title">Нет данных по вариантам объявлений</div>
                    <div class="empty-state-message">Попробуйте изменить период фильтрации</div>
                </div>
                <div class="table-pagination" id="contentTablePagination" style="display: none;"></div>
            </div>
        </section>

        <!-- Секция: Таблица сделок -->
        <section class="content-section" id="table-section">
            <div class="section-header">
                <h2>📋 Таблица всех сделок</h2>
                <div class="section-actions">
                    <select class="filter-select" id="tablePerPage">
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                        <option value="all">Все</option>
                    </select>
                </div>
            </div>

            <div class="table-container">
                <div class="table-wrapper" style="overflow-x: auto;">
                    <table class="data-table" id="dealsTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="id">ID</th>
                                <th class="sortable" data-sort="deal_id">Deal ID</th>
                                <th class="sortable" data-sort="contact_id">Contact ID</th>
                                <th class="sortable" data-sort="email">Email</th>
                                <th class="sortable" data-sort="phone">Phone</th>
                                <th class="sortable" data-sort="full_name">Full Name</th>
                                <th class="sortable" data-sort="created_at">Created At</th>
                                <th class="sortable" data-sort="deal_updated_at">Updated At</th>
                                <th class="sortable" data-sort="amount">Amount</th>
                                <th class="sortable" data-sort="amount_uah">Amount UAH</th>
                                <th class="sortable" data-sort="deal_price">Deal Price</th>
                                <th class="sortable" data-sort="deal_currency">Currency</th>
                                <th class="sortable" data-sort="utm_source">UTM Source</th>
                                <th class="sortable" data-sort="utm_medium">UTM Medium</th>
                                <th class="sortable" data-sort="utm_campaign">UTM Campaign</th>
                                <th class="sortable" data-sort="utm_term">UTM Term</th>
                                <th class="sortable" data-sort="utm_content">UTM Content</th>
                                <th class="sortable" data-sort="deal_pipeline">Pipeline</th>
                                <th class="sortable" data-sort="deal_type">Type</th>
                                <th class="sortable" data-sort="deal_status">Status</th>
                                <th class="sortable" data-sort="is_paid">Is Paid</th>
                                <th class="sortable" data-sort="is_failed">Is Failed</th>
                                <th class="sortable" data-sort="is_pending">Is Pending</th>
                                <th class="sortable" data-sort="deal_name">Deal Name</th>
                                <th class="sortable" data-sort="deal_step">Step</th>
                                <th class="sortable" data-sort="model">Model</th>
                                <th class="sortable" data-sort="comment">Comment</th>
                                <th class="sortable" data-sort="product">Product</th>
                                <th class="sortable" data-sort="tickets">Tickets</th>
                                <th class="sortable" data-sort="tickets_count">Tickets Count</th>
                                <th class="sortable" data-sort="list_name">List Name</th>
                                <th class="sortable" data-sort="tag_list">Tags</th>
                                <th class="sortable" data-sort="imported_at">Imported At</th>
                                <th class="sortable" data-sort="updated_at">Updated At</th>
                            </tr>
                        </thead>
                        <tbody id="dealsTableBody">
                            <!-- Данные загружаются через JS -->
                        </tbody>
                    </table>
                </div>
                <div class="empty-state" id="dealsTableEmpty" style="display: none;">
                    <div class="empty-state-icon">📋</div>
                    <div class="empty-state-title">Нет данных для отображения</div>
                </div>
                <div class="table-pagination" id="dealsTablePagination"></div>
            </div>
        </section>

        <!-- Секция: Справка по категоризации -->
        <section class="content-section" id="help-section">
            <div class="section-header">
                <h2>❓ Справка по категоризации источников</h2>
            </div>

            <div style="max-width: 1200px; margin: 0 auto;">
                <!-- Категории данных -->
                <div class="details-card" style="margin-bottom: 2rem;">
                    <h3>📊 Типы данных</h3>
                    <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                        Система автоматически категоризирует каждый источник трафика на основе наличия лидов из CRM и рекламных расходов из Facebook Ads.
                    </p>

                    <div style="display: grid; gap: 1.5rem;">
                        <!-- Common -->
                        <div style="padding: 1.5rem; background: var(--card-bg); border-left: 4px solid #10b981; border-radius: 8px;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <span class="badge badge-common" style="font-size: 1rem;">🟢 Общие</span>
                                <span style="color: var(--text-secondary); font-size: 0.875rem;">(CRM + ADS)</span>
                            </div>
                            <p style="margin: 0; color: var(--text-primary);">
                                <strong>Есть и лиды из CRM, и рекламные расходы.</strong><br>
                                Это платный трафик с Facebook/Instagram, который генерирует лиды.
                                Показывает полную картину: расходы на рекламу + полученный результат.
                            </p>
                            <p style="margin-top: 0.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                                Пример: <code>instagram</code> - есть лиды из CRM и расходы на рекламу в Instagram
                            </p>
                        </div>

                        <!-- CRM-only -->
                        <div style="padding: 1.5rem; background: var(--card-bg); border-left: 4px solid #3b82f6; border-radius: 8px;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <span class="badge badge-crm-only" style="font-size: 1rem;">🔵 CRM-only</span>
                                <span style="color: var(--text-secondary); font-size: 0.875rem;">(Органический трафик)</span>
                            </div>
                            <p style="margin: 0; color: var(--text-primary);">
                                <strong>Есть лиды из CRM, но нет рекламных расходов.</strong><br>
                                Это органический трафик - пользователи пришли сами (прямой переход, поиск, рекомендации).
                                Не требует затрат на рекламу.
                            </p>
                            <p style="margin-top: 0.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                                Пример: <code>telegram</code>, <code>viber</code> - лиды есть, но реклама не запускалась
                            </p>
                        </div>

                        <!-- ADS-only -->
                        <div style="padding: 1.5rem; background: var(--card-bg); border-left: 4px solid #f59e0b; border-radius: 8px;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <span class="badge badge-ads-only" style="font-size: 1rem;">🟡 ADS-only</span>
                                <span style="color: var(--text-secondary); font-size: 0.875rem;">(Пока без лидов)</span>
                            </div>
                            <p style="margin: 0; color: var(--text-primary);">
                                <strong>Есть рекламные расходы, но нет лидов.</strong><br>
                                Реклама запущена и тратит бюджет, но пока не принесла лидов в CRM.
                                Может быть новая кампания или неэффективная настройка.
                            </p>
                            <p style="margin-top: 0.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                                Пример: <code>audience_network</code> - реклама работает, но лиды ещё не конвертировались
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Mapping правила -->
                <div class="details-card">
                    <h3>🔗 Правила маппинга UTM меток</h3>
                    <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                        Система преобразует данные из Facebook Ads в стандартные UTM метки для единой аналитики.
                    </p>

                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>UTM Метка</th>
                                <th>Источник данных (Facebook Ads)</th>
                                <th>Пример значения</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>utm_source</code></td>
                                <td>publisher_platform</td>
                                <td>facebook, instagram, messenger</td>
                            </tr>
                            <tr>
                                <td><code>utm_medium</code></td>
                                <td>platform_position</td>
                                <td>feed, stories, reels, search</td>
                            </tr>
                            <tr>
                                <td><code>utm_campaign</code></td>
                                <td>campaign_name</td>
                                <td>summer_sale_2024</td>
                            </tr>
                            <tr>
                                <td><code>utm_content</code></td>
                                <td>adset_name + "_" + ad_name</td>
                                <td>targeting_25-34_video_v2</td>
                            </tr>
                            <tr>
                                <td><code>utm_term</code></td>
                                <td>account_name</td>
                                <td>company_ads_account</td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 8px;">
                        <p style="margin: 0; color: var(--text-primary); font-size: 0.875rem;">
                            <strong>💡 Совет:</strong> Используйте фильтры по типу данных в каждом разделе, чтобы анализировать только нужные источники.
                            Это поможет оценить эффективность платной рекламы отдельно от органического трафика.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Модальное окно с сырыми данными -->
        <div class="modal" id="rawDataModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>📋 Сырые JSON данные</h3>
                    <button class="modal-close" id="closeModalBtn">✕</button>
                </div>
                <div class="modal-body">
                    <pre id="rawDataContent"></pre>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" id="copyDataBtn">📋 Копировать</button>
                    <button class="btn btn-secondary" id="downloadDataBtn">💾 Скачать</button>
                </div>
            </div>
        </div>

        <!-- Модальное окно для выбора периода -->
        <div class="modal" id="customDateModal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>📅 Выбрать период</h3>
                    <button class="modal-close" onclick="closeModal('customDateModal')">✕</button>
                </div>
                <div class="modal-body">
                    <div style="display: grid; gap: 1rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-primary);">От:</label>
                            <input type="date" id="dateFrom" class="search-input" style="width: 100%;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-primary);">До:</label>
                            <input type="date" id="dateTo" class="search-input" style="width: 100%;">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" onclick="closeModal('customDateModal')">Отмена</button>
                    <button class="btn btn-primary" id="applyCustomDateBtn">Применить</button>
                </div>
            </div>
        </div>

        <!-- Модальное окно детального просмотра лида -->
        <div class="modal" id="leadDetailsModal">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h3>👤 Детальная информация о лиде</h3>
                    <button class="modal-close" onclick="closeModal('leadDetailsModal')">✕</button>
                </div>
                <div class="modal-body" id="leadDetailsContent">
                    <!-- Данные загружаются через JS -->
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" onclick="closeModal('leadDetailsModal')">Закрыть</button>
                </div>
            </div>
        </div>

        <!-- Модальное окно налаштувань проекту -->
        <div class="modal" id="settingsModal">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3>⚙️ Налаштування проектів</h3>
                    <button class="modal-close" id="closeSettingsBtn">✕</button>
                </div>
                <div class="modal-body">
                    <div class="settings-description">
                        <p>Виберіть активний проект для дашборду. Всі дані будуть фільтруватися по обраному проекту.</p>
                    </div>
                    <div id="projectsList" class="projects-list">
                        <!-- Список проектів завантажується через JS -->
                        <div class="loading-state">
                            <div class="loading-spinner"></div>
                            <p>Завантаження проектів...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loader -->
        <div class="loader-overlay" id="loaderOverlay">
            <div class="loader"></div>
            <p>Загрузка данных...</p>
        </div>

        <!-- Уведомления -->
        <div class="notifications" id="notificationsContainer"></div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Chart.js для графиков -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

    <!-- Передати дані авторизації в JavaScript -->
    <script>
    // Передати роль і utm_term в JavaScript
    window.USER_ROLE = <?php echo json_encode($userRole); ?>;
    window.USER_UTM_TERM = <?php echo json_encode($userUtmTerm); ?>;
    window.IS_GUEST = <?php echo json_encode($isGuest); ?>;
    // Передати BASE_URL для AJAX запросов
    window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>

    <!-- JS -->
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/ajax.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/charts.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/components.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/date-filter.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/settings.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/deals-table.js?v=<?php echo time(); ?>"></script>
</body>
</html>
