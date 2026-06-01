<?php
require_once 'config/app_config.php';
require_once 'core/Auth.php';
require_once 'core/Session.php';

Auth::checkAccess();
$userRole = Session::getRole();

if ($userRole !== 'admin') {
    header('Location: index.php');
    exit;
}

$username = Session::get('user')['username'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔗 Відповідності CRM ↔ ADS | UTM Dashboard</title>

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

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <div class="header-content">
                <div class="header-left">
                    <h1 class="gradient-text">🔗 Відповідності CRM ↔ ADS</h1>
                    <p class="text-muted">Налаштування зіставлення міток між CRM та рекламними системами</p>
                </div>
                <div class="header-title-actions">
                    <a href="index.php" class="btn btn-outline btn-sm">← Назад до Dashboard</a>
                </div>
            </div>
        </header>

        <div class="content-wrapper" style="padding: 2rem;">
            <section class="content-section" style="padding: 1.5rem; margin-bottom: 2rem; background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6;">
                <h3 style="margin-bottom: 1rem;">📘 Навіщо потрібні відповідності?</h3>
                <p style="margin-bottom: 0.5rem;">
                    Одна і та ж людина (таргетолог) може мати різні назви міток в CRM та рекламних системах.
                    Наприклад: <code style="background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px;">vadym</code> в CRM і
                    <code style="background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px;">dreamcar.ua uah</code> в Facebook Ads.
                </p>
                <p>
                    Щоб правильно рахувати ROI та ROAS, система повинна знати що це <strong>одна людина</strong>.
                    Створіть відповідність нижче, і дані автоматично об'єднаються.
                </p>
            </section>

            <div style="margin-bottom: 1.5rem;">
                <button class="btn btn-primary" id="createMappingBtn">+ Створити нове відповідність</button>
            </div>

            <div class="content-section">
                <div class="table-container">
                    <table class="data-table" id="mappingsTable">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Тип поля</th>
                                <th>🔵 CRM мітка</th>
                                <th>🟡 ADS мітка</th>
                                <th>Об'єднана назва</th>
                                <th style="width: 250px;">Коментар</th>
                                <th style="width: 150px;">Створено</th>
                                <th style="width: 180px;">Дії</th>
                            </tr>
                        </thead>
                        <tbody id="mappingsTableBody">
                            <tr><td colspan="7" style="text-align: center; padding: 2rem;">Завантаження...</td></tr>
                        </tbody>
                    </table>
                    <div class="empty-state" id="mappingsTableEmpty" style="display: none; padding: 3rem; text-align: center;">
                        <div style="font-size: 64px; margin-bottom: 1rem;">🔗</div>
                        <h3>Немає відповідностей</h3>
                        <p class="text-muted">Створіть першу відповідність для об'єднання даних</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal" id="mappingFormModal">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3 id="modalTitle">Створити відповідність</h3>
                    <button class="modal-close" id="closeMappingModalBtn">✕</button>
                </div>
                <div class="modal-body">
                    <form id="mappingForm">
                        <input type="hidden" id="mappingId" name="id">

                        <div class="form-group">
                            <label>Тип UTM мітки</label>
                            <select id="fieldType" name="field_type" class="filter-select" required style="width: 100%; padding: 0.75rem; background: var(--ai-gray); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">
                                <option value="">-- Виберіть --</option>
                                <option value="utm_source">📍 Source (джерело трафіку)</option>
                                <option value="utm_medium">🔗 Medium (тип трафіку)</option>
                                <option value="utm_campaign">🎯 Campaign (кампанія)</option>
                                <option value="utm_term">🔑 Term (ключове слово / таргетолог)</option>
                                <option value="utm_content">🎨 Content (варіант оголошення)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>🔵 CRM значення</label>
                            <select id="crmValue" name="crm_value" class="filter-select" required style="width: 100%; padding: 0.75rem; background: var(--ai-gray); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">
                                <option value="">-- Спочатку виберіть тип поля --</option>
                            </select>
                            <small class="text-muted">Значення яке приходить з CRM (SendPulse)</small>
                        </div>

                        <div class="form-group">
                            <label>🟡 ADS значення</label>
                            <select id="adsValue" name="ads_value" class="filter-select" required style="width: 100%; padding: 0.75rem; background: var(--ai-gray); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">
                                <option value="">-- Спочатку виберіть тип поля --</option>
                            </select>
                            <small class="text-muted">Значення яке приходить з Facebook/Google Ads</small>
                        </div>

                        <div class="form-group">
                            <label>Об'єднана назва</label>
                            <input type="text" id="mergedName" name="merged_name" class="search-input" required placeholder="vadym" style="width: 100%; padding: 0.75rem; background: var(--ai-gray); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">
                            <small class="text-muted">Назва для відображення в таблиці (рекомендуємо CRM значення)</small>
                        </div>

                        <div class="form-group">
                            <label>Коментар (необов'язково)</label>
                            <textarea id="notes" name="notes" rows="3" placeholder="Таргетолог Вадим - основний акаунт" style="width: 100%; padding: 0.75rem; background: var(--ai-gray); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); resize: vertical;"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" id="cancelMappingBtn">Скасувати</button>
                    <button class="btn btn-primary" id="saveMappingBtn">Зберегти</button>
                </div>
            </div>
        </div>

        <div class="loader-overlay" id="loaderOverlay" style="display: none;">
            <div class="loader"></div>
            <p>Завантаження...</p>
        </div>

        <div class="notifications" id="notificationsContainer"></div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/utm_mapping.js?v=<?php echo time(); ?>"></script>
</body>
</html>
