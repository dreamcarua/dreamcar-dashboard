<?php
// === manual_costs.php ===
// НАЗНАЧЕНИЕ: Страница управления ручными рекламными расходами
// СВЯЗИ: config/app_config.php, api/handler.php, core/models/AdsData.php
// СОЗДАНО: 2025-11-30

require_once 'config/app_config.php';
require_once 'core/Logger.php';
require_once 'core/Auth.php';
require_once 'core/Session.php';
require_once 'finance/core/models/FinanceTransaction.php';
require_once 'finance/core/models/FinanceCard.php';

// Для всіх (адмін, таргетолог, гість з utm_term)
Auth::checkAccess();

// Категории расходов для dropdown (синхронизировано с FinanceTransaction::EXPENSE_CATEGORIES)
$expenseCategories        = FinanceTransaction::getExpenseCategories();         // плоский (legacy)
$expenseCategoriesGrouped = FinanceTransaction::getExpenseCategoriesGrouped();   // группированный

// Список активных рекламных карт для dropdown
try {
    $financeCards = FinanceCard::getAll();
} catch (Throwable $e) {
    $financeCards = [];
    error_log('[manual_costs] FinanceCard::getAll failed: ' . $e->getMessage());
}

// Отримати роль та utm_term користувача
$userRole = Session::getRole();
$isGuest = Auth::isGuest();
$username = Session::get('user')['username'] ?? 'user';

// Для гостей брати utm_term з URL, для авторизованих - з сесії
if ($isGuest) {
    $userUtmTerm = $_GET['utm_term'] ?? null;
} else {
    $userUtmTerm = Auth::getUserUtmTerm();
}

$logger = new Logger();
$logger->log('Загрузка страницы ручных расходов', 'info', [
    'username' => $username,
    'role' => $userRole,
    'utm_term' => $userUtmTerm
]);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💰 Ручные затраты | UTM Dashboard</title>

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/animations.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/manual-costs-compact.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Главный контейнер -->
    <div class="dashboard-container">

        <!-- Заголовок -->
        <header class="dashboard-header">
            <div class="header-content">
                <div class="header-left">
                    <h1 class="gradient-text">💰 Ручные затраты на рекламу</h1>
                    <p class="text-muted">Добавление расходов на Viber, SMS, Email рассылки и т.п.</p>
                </div>
                <div class="header-title-actions">
                    <a href="index.php<?php echo $userUtmTerm ? '?utm_term=' . urlencode($userUtmTerm) : ''; ?>" class="btn btn-outline btn-sm">
                        ← Назад к дашборду
                    </a>
                </div>
            </div>
        </header>

        <!-- Форма добавления -->
        <section class="content-section active">
            <div class="section-header">
                <h2>➕ Добавить новый расход</h2>
            </div>

            <form id="addCostForm" class="cost-form">
                <div class="form-grid">
                    <!-- Дата -->
                    <div class="form-group">
                        <label for="costDate">📅 Дата <span class="required">*</span></label>
                        <input type="date" id="costDate" name="date" class="form-input" required>
                    </div>

                    <!-- Проект -->
                    <div class="form-group">
                        <label for="costProject">🏷️ Проект <span class="required">*</span></label>
                        <select id="costProject" name="project" class="form-input" required>
                            <?php
                            // Завантажити активний проект з налаштувань
                            $settingsFile = __DIR__ . '/config/dashboard_settings.json';
                            $activeProject = 'VOLVO'; // дефолт

                            if (file_exists($settingsFile)) {
                                $settings = json_decode(file_get_contents($settingsFile), true);
                                $activeProject = isset($settings['active_project']) ? $settings['active_project'] : 'VOLVO';
                            }

                            // Завантажити список проектів з БД
                            require_once __DIR__ . '/core/Database.php';
                            $db = Database::getInstance();
                            $sql = "SELECT DISTINCT model FROM crm_deals
                                    WHERE model IS NOT NULL AND model != ''
                                    ORDER BY model";
                            $rows = $db->fetchAll($sql);

                            // Додати проекти з БД
                            $projectsSet = [];
                            $projects = [];

                            foreach ($rows as $row) {
                                $model = strtoupper(trim($row['model']));
                                // Витягти базову назву проекту
                                if (preg_match('/^([A-Z0-9]+)/', $model, $matches)) {
                                    $project = $matches[1];
                                    if (!in_array($project, $projectsSet)) {
                                        $projectsSet[] = $project;
                                        $projects[] = $project;
                                    }
                                }
                            }

                            // Сортування
                            sort($projects);

                            // Вивести опції
                            foreach ($projects as $project) {
                                $selected = ($project === $activeProject) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($project) . "\" {$selected}>" . htmlspecialchars($project) . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Категория (для finance_transactions) -->
                    <div class="form-group">
                        <label for="costCategory">📂 Категория <span class="required">*</span></label>
                        <select id="costCategory" name="category" class="form-input" required>
                            <?php foreach ($expenseCategoriesGrouped as $groupKey => $group): ?>
                                <optgroup label="<?php echo htmlspecialchars($group['label']); ?>">
                                    <?php foreach ($group['items'] as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Категория будет видна в финансовом модуле</small>
                    </div>

                    <!-- Карта (опционально - для автосписания с баланса) -->
                    <div class="form-group">
                        <label for="costCardId">💳 Карта <small class="text-muted">(опцiонально)</small></label>
                        <select id="costCardId" name="card_id" class="form-input">
                            <option value="0">— Без привязки —</option>
                            <?php foreach ($financeCards as $card): ?>
                                <option value="<?php echo (int)$card['id']; ?>">
                                    <?php echo htmlspecialchars($card['bank_name'] . ' •••' . $card['last4'] . ' (баланс: ' . number_format((float)$card['balance_uah'], 0, '.', ' ') . ' ₴)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Сумма будет списана с баланса карты</small>
                    </div>

                    <!-- UTM Source -->
                    <div class="form-group">
                        <label for="costSource">📍 UTM Source</label>
                        <input type="text" id="costSource" name="utm_source" class="form-input"
                               placeholder="viber, sms, email">
                    </div>

                    <!-- UTM Medium -->
                    <div class="form-group">
                        <label for="costMedium">🔗 UTM Medium</label>
                        <input type="text" id="costMedium" name="utm_medium" class="form-input"
                               placeholder="broadcast, newsletter">
                    </div>

                    <!-- UTM Campaign -->
                    <div class="form-group">
                        <label for="costCampaign">🎯 UTM Campaign</label>
                        <input type="text" id="costCampaign" name="utm_campaign" class="form-input"
                               placeholder="black_friday_2025">
                    </div>

                    <!-- UTM Term -->
                    <div class="form-group">
                        <label for="costTerm">🏷️ UTM Term <?php if ($userUtmTerm): ?><span style="color: #f59e0b;">🔒 (заблоковано)</span><?php endif; ?></label>
                        <input type="text" id="costTerm" name="utm_term" class="form-input"
                               placeholder="<?php echo $userUtmTerm ?: 'promo_code'; ?>"
                               value="<?php echo htmlspecialchars($userUtmTerm ?: ''); ?>"
                               <?php if ($userUtmTerm): ?>readonly<?php endif; ?>>
                        <?php if ($userUtmTerm): ?>
                        <small class="text-muted">Ваша мітка фіксована: <strong><?php echo htmlspecialchars($userUtmTerm); ?></strong></small>
                        <?php endif; ?>
                    </div>

                    <!-- UTM Content -->
                    <div class="form-group">
                        <label for="costContent">📝 UTM Content</label>
                        <input type="text" id="costContent" name="utm_content" class="form-input"
                               placeholder="variant_a">
                    </div>

                    <!-- Сумма -->
                    <div class="form-group">
                        <label for="costAmount">💵 Сумма <span class="required">*</span></label>
                        <input type="number" id="costAmount" name="amount" class="form-input"
                               step="0.01" min="0.01" placeholder="1500.00" required>
                    </div>

                    <!-- Валюта -->
                    <div class="form-group">
                        <label for="costCurrency">💱 Валюта <span class="required">*</span></label>
                        <select id="costCurrency" name="currency" class="form-input" required>
                            <option value="UAH" selected>UAH (гривна)</option>
                            <option value="USD">USD (доллар)</option>
                        </select>
                    </div>
                </div>

                <!-- Примечание -->
                <div class="form-group form-group-full">
                    <label for="costNote">📋 Примечание</label>
                    <textarea id="costNote" name="note" class="form-input form-textarea"
                              placeholder="Viber рассылка на 5000 контактов"></textarea>
                </div>

                <div class="form-hint">
                    <span class="required">*</span> Обязательно заполнить хотя бы одну UTM-метку (Source, Medium, Campaign, Term или Content)
                </div>

                <div class="form-actions">
                    <button type="reset" class="btn btn-outline">🔄 Очистить</button>
                    <button type="submit" class="btn btn-primary" id="submitCostBtn">
                        💾 Сохранить расход
                    </button>
                </div>
            </form>
        </section>

        <!-- Таблица расходов -->
        <section class="content-section active">
            <div class="section-header">
                <h2>📋 Список ручных расходов</h2>
                <div class="section-actions">
                    <div class="filter-group">
                        <label>📅 Период:</label>
                        <input type="date" id="filterDateFrom" class="form-input form-input-sm">
                        <span>—</span>
                        <input type="date" id="filterDateTo" class="form-input form-input-sm">
                        <button class="btn btn-outline btn-sm" id="applyFilterBtn">Применить</button>
                        <button class="btn btn-outline btn-sm" id="clearFilterBtn">Сбросить</button>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table" id="costsTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 100px;">Дата</th>
                            <th style="width: 100px;">Проект</th>
                            <th>Source</th>
                            <th>Medium</th>
                            <th>Campaign</th>
                            <th>Term</th>
                            <th>Content</th>
                            <th style="width: 120px;">Сумма (UAH)</th>
                            <th>Примечание</th>
                            <th style="width: 100px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="costsTableBody">
                        <tr>
                            <td colspan="11" class="loading-cell">
                                <div class="loading-spinner"></div>
                                Загрузка данных...
                            </td>
                        </tr>
                    </tbody>
                    <tfoot id="costsTableFoot">
                        <tr>
                            <td colspan="8" class="total-label">Итого:</td>
                            <td id="totalAmount" class="total-value">0.00 UAH</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="table-info" id="tableInfo">
                Показано: <span id="showingCount">0</span> записей
            </div>
        </section>

    </div>

    <!-- Модальное окно редактирования -->
    <div class="modal" id="editCostModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>✏️ Редактировать расход</h3>
                <button class="modal-close" onclick="closeModal('editCostModal')">✕</button>
            </div>
            <div class="modal-body">
                <form id="editCostForm">
                    <input type="hidden" id="editCostId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="editCostDate">📅 Дата</label>
                            <input type="date" id="editCostDate" name="date" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label for="editCostProject">🏷️ Проект</label>
                            <select id="editCostProject" name="project" class="form-input" required>
                                <?php
                                // Використовуємо той самий код що і вище
                                foreach ($projects as $project) {
                                    echo "<option value=\"" . htmlspecialchars($project) . "\">" . htmlspecialchars($project) . "</option>\n";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="editCostCategory">📂 Категория</label>
                            <select id="editCostCategory" name="category" class="form-input" required>
                                <?php foreach ($expenseCategoriesGrouped as $groupKey => $group): ?>
                                    <optgroup label="<?php echo htmlspecialchars($group['label']); ?>">
                                        <?php foreach ($group['items'] as $key => $label): ?>
                                            <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="editCostCardId">💳 Карта</label>
                            <select id="editCostCardId" name="card_id" class="form-input">
                                <option value="0">— Без привязки —</option>
                                <?php foreach ($financeCards as $card): ?>
                                    <option value="<?php echo (int)$card['id']; ?>">
                                        <?php echo htmlspecialchars($card['bank_name'] . ' •••' . $card['last4']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="editCostSource">📍 UTM Source</label>
                            <input type="text" id="editCostSource" name="utm_source" class="form-input">
                        </div>

                        <div class="form-group">
                            <label for="editCostMedium">🔗 UTM Medium</label>
                            <input type="text" id="editCostMedium" name="utm_medium" class="form-input">
                        </div>

                        <div class="form-group">
                            <label for="editCostCampaign">🎯 UTM Campaign</label>
                            <input type="text" id="editCostCampaign" name="utm_campaign" class="form-input">
                        </div>

                        <div class="form-group">
                            <label for="editCostTerm">🏷️ UTM Term <?php if ($userUtmTerm): ?><span style="color: #f59e0b;">🔒</span><?php endif; ?></label>
                            <input type="text" id="editCostTerm" name="utm_term" class="form-input"
                                   <?php if ($userUtmTerm): ?>readonly<?php endif; ?>>
                        </div>

                        <div class="form-group">
                            <label for="editCostContent">📝 UTM Content</label>
                            <input type="text" id="editCostContent" name="utm_content" class="form-input">
                        </div>

                        <div class="form-group">
                            <label for="editCostAmount">💵 Сумма (UAH)</label>
                            <input type="number" id="editCostAmount" name="amount" class="form-input"
                                   step="0.01" min="0.01" required>
                        </div>

                        <div class="form-group">
                            <label for="editCostCurrency">💱 Валюта</label>
                            <select id="editCostCurrency" name="currency" class="form-input" disabled>
                                <option value="UAH" selected>UAH (уже конвертировано)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group form-group-full">
                        <label for="editCostNote">📋 Примечание</label>
                        <textarea id="editCostNote" name="note" class="form-input form-textarea"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('editCostModal')">Отмена</button>
                <button class="btn btn-primary" id="updateCostBtn">💾 Сохранить изменения</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно подтверждения удаления -->
    <div class="modal" id="deleteCostModal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3>🗑️ Удалить расход?</h3>
                <button class="modal-close" onclick="closeModal('deleteCostModal')">✕</button>
            </div>
            <div class="modal-body">
                <p>Вы уверены, что хотите удалить этот расход?</p>
                <div id="deleteDetails" class="delete-details"></div>
                <p class="text-danger">Это действие нельзя отменить.</p>
                <input type="hidden" id="deleteCostId">
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('deleteCostModal')">Отмена</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">🗑️ Удалить</button>
            </div>
        </div>
    </div>

    <!-- Уведомления -->
    <div id="notification" class="notification"></div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Передати дані авторизації в JavaScript -->
    <script>
    window.USER_ROLE = <?php echo json_encode($userRole); ?>;
    window.USER_UTM_TERM = <?php echo json_encode($userUtmTerm); ?>;
    </script>

    <!-- Theme JS -->
    <script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>

    <!-- Components JS -->
    <script src="assets/js/components.js?v=<?php echo time(); ?>"></script>

    <!-- Manual Costs JS -->
    <script src="assets/js/manual_costs.js?v=<?php echo time(); ?>"></script>
</body>
</html>
