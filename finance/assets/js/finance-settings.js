// === FINANCE-SETTINGS.JS ===
// finance/assets/js/finance-settings.js
// НАЗНАЧЕНИЕ: Модуль налаштувань - тема, проекти, iнформацiя про користувача, управлiння користувачами
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Toast, ZK.Modal, ZK.Theme, ZK.Config), api/handler.php
// РАЗМЕР: ~430 строк

ZK.Settings = (function() {

    var initialized = false;

    // ─── Точка входа ─────────────────────────────────────────────────────────

    function load() {
        if (!initialized) {
            init();
            initialized = true;
        }
    }

    // ─── Iнiцiалiзацiя ───────────────────────────────────────────────────────

    function init() {
        var canWrite = ZK.Config.canWrite;
        var isAdmin = (ZK.Config.financeRole === 'admin');
        var html = '';

        // Блок: Тема
        html += '<div class="settings-section-block">';
        html += '<div class="settings-block-title">Тема</div>';
        html += renderThemeSection();
        html += '</div>';

        // Блок: Проекти (рендерится после загрузки)
        html += '<div class="settings-section-block" id="settings-projects-block">';
        html += '<div class="settings-block-title">Проекти</div>';
        html += '<div id="settings-projects-wrap"><div class="zk-loading">Завантаження...</div></div>';
        html += '</div>';

        // Блок: Ставки комiсiй та податкiв (только для admin)
        if (isAdmin) {
            html += '<div class="settings-section-block" id="settings-rates-block">';
            html += '<div class="settings-block-title">Ставки комiсiй та податкiв</div>';
            html += '<div id="settings-rates-wrap"><div class="zk-loading">Завантаження...</div></div>';
            html += '</div>';
        }

        // Блок: Користувачi (только для admin)
        if (isAdmin) {
            html += '<div class="settings-section-block" id="settings-users-block">';
            html += '<div class="settings-block-title settings-block-title-row">';
            html += '<span>Користувачi</span>';
            html += '<button class="zk-btn zk-btn-sm zk-btn-primary" id="btn-add-user">+ Додати</button>';
            html += '</div>';
            html += '<div id="settings-users-wrap"><div class="zk-loading">Завантаження...</div></div>';
            html += '</div>';
        }

        // Блок: Iнформацiя
        html += '<div class="settings-section-block">';
        html += '<div class="settings-block-title">Iнформацiя</div>';
        html += renderInfoSection();
        html += '</div>';

        $('#settings-section').html(html);

        bindSettingsEvents();
        loadProjects();
        if (isAdmin) {
            loadRates();
            loadUsers();
        }
    }

    // ─── Секцiя: Тема ────────────────────────────────────────────────────────

    function renderThemeSection() {
        var isLight = document.documentElement.classList.contains('light-theme');
        var currentLabel = isLight ? 'Свiтла' : 'Темна';
        var btnLabel = isLight ? 'Переключити на темну' : 'Переключити на свiтлу';

        return '<div class="settings-theme-row">'
            + '<span class="settings-theme-current">Поточна: <strong>' + currentLabel + '</strong></span>'
            + '<button class="zk-btn zk-btn-sm zk-btn-secondary" id="btn-toggle-theme">' + btnLabel + '</button>'
            + '</div>';
    }

    // ─── Секцiя: Iнформацiя ──────────────────────────────────────────────────

    function renderInfoSection() {
        var role = ZK.Config.financeRole || '';
        var roleLabel;
        switch (role) {
            case 'owner': roleLabel = 'Власник'; break;
            case 'accountant': roleLabel = 'Бухгалтер'; break;
            case 'viewer': roleLabel = 'Переглядач'; break;
            default: roleLabel = role || '—';
        }

        return '<div class="settings-info-list">'
            + '<div class="settings-info-row"><span class="settings-info-key">Користувач</span><span class="settings-info-val">' + escHtml(ZK.Config.username) + '</span></div>'
            + '<div class="settings-info-row"><span class="settings-info-key">Роль</span><span class="settings-info-val">' + escHtml(roleLabel) + '</span></div>'
            + '</div>';
    }

    // ─── Секцiя: Проекти ─────────────────────────────────────────────────────

    function loadProjects() {
        ZK.Api('projects.list', {}, function(data, ok) {
            if (!ok || !data) {
                $('#settings-projects-wrap').html('<div class="zk-empty">Помилка завантаження</div>');
                return;
            }
            var projects = data.items || data || [];
            renderProjects(projects);
        });
    }

    function renderProjects(projects) {
        var canWrite = ZK.Config.canWrite;

        if (!projects || projects.length === 0) {
            $('#settings-projects-wrap').html('<div class="zk-empty">Проектiв немає</div>');
            return;
        }

        var html = '<div class="zk-table-wrap"><table class="zk-table">';
        html += '<thead><tr>';
        html += '<th>Назва</th><th>Статус</th><th>Дати</th><th>Бюджет (план)</th><th>Дiї</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < projects.length; i++) {
            var p = projects[i];
            var dateRange = '';
            if (p.date_start || p.date_end) {
                dateRange = (ZK.Format.date(p.date_start) || '?') + ' — ' + (ZK.Format.date(p.date_end) || '?');
            } else {
                dateRange = '—';
            }
            var statusBadge = buildStatusBadge(p.status);

            html += '<tr data-project-id="' + p.id + '">';
            html += '<td>' + escHtml(p.name || '') + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td>' + dateRange + '</td>';
            html += '<td>' + ZK.Format.money(p.budget_plan) + '</td>';
            html += '<td class="zk-actions">';
            if (canWrite) {
                html += '<button class="zk-btn zk-btn-xs zk-btn-secondary btn-edit-project" data-project=\'' + jsonSafe(p) + '\'>Ред.</button>';
            }
            html += '</td></tr>';
        }

        html += '</tbody></table></div>';
        $('#settings-projects-wrap').html(html);
        bindProjectTableEvents();
    }

    function buildStatusBadge(status) {
        switch (status) {
            case 'active': return '<span class="zk-badge zk-badge-green">Активний</span>';
            case 'paused': return '<span class="zk-badge zk-badge-orange">Призупинено</span>';
            case 'done': return '<span class="zk-badge zk-badge-gray">Завершено</span>';
            default: return '<span class="zk-badge">' + escHtml(status || '—') + '</span>';
        }
    }

    // ─── Модал: редагування проекту ──────────────────────────────────────────

    function openEditProjectModal(project) {
        var p = project || {};

        var html = '<div class="modal-header"><h3>Редагування проекту</h3></div>';
        html += '<div class="modal-body">';
        html += '<form id="form-edit-project">';
        html += '<div class="form-row"><label>Назва</label>';
        html += '<input type="text" name="name" class="zk-input" value="' + escAttr(p.name) + '" required></div>';
        html += '<div class="form-row"><label>Статус</label>';
        html += '<select name="status" class="zk-select">';
        var statuses = [['active', 'Активний'], ['paused', 'Призупинено'], ['done', 'Завершено']];
        for (var i = 0; i < statuses.length; i++) {
            var sel = p.status === statuses[i][0] ? ' selected' : '';
            html += '<option value="' + statuses[i][0] + '"' + sel + '>' + statuses[i][1] + '</option>';
        }
        html += '</select></div>';
        html += '<div class="form-row"><label>Дата початку</label>';
        html += '<input type="date" name="date_start" class="zk-input" value="' + escAttr(p.date_start) + '"></div>';
        html += '<div class="form-row"><label>Дата закiнчення</label>';
        html += '<input type="date" name="date_end" class="zk-input" value="' + escAttr(p.date_end) + '"></div>';
        html += '<div class="form-row"><label>Бюджет (план, UAH)</label>';
        html += '<input type="number" name="budget_plan" class="zk-input" step="0.01" value="' + (p.budget_plan || '') + '"></div>';
        html += '<div class="form-row"><label>Нотатки</label>';
        html += '<textarea name="notes" class="zk-input" rows="2">' + escHtml(p.notes || '') + '</textarea></div>';
        html += '</form></div>';
        html += '<div class="modal-footer">';
        html += '<button class="zk-btn zk-btn-secondary" id="btn-modal-cancel">Скасувати</button>';
        html += '<button class="zk-btn zk-btn-primary" id="btn-modal-submit-project">Зберегти</button>';
        html += '</div>';

        ZK.Modal.open(html);

        $(document).off('click.modal-cancel');
        $(document).on('click.modal-cancel', '#btn-modal-cancel', function() {
            ZK.Modal.close();
        });

        $(document).off('click.project-save');
        $(document).on('click.project-save', '#btn-modal-submit-project', function() {
            var form = document.getElementById('form-edit-project');
            var name = (form.querySelector('[name="name"]').value || '').trim();
            if (!name) { ZK.Toast.error('Введiть назву'); return; }
            var payload = {
                id: p.id,
                name: name,
                status: form.querySelector('[name="status"]').value,
                date_start: form.querySelector('[name="date_start"]').value || null,
                date_end: form.querySelector('[name="date_end"]').value || null,
                budget_plan: parseFloat(form.querySelector('[name="budget_plan"]').value) || 0,
                notes: (form.querySelector('[name="notes"]').value || '').trim()
            };
            ZK.Api('projects.update', payload, function(data, ok) {
                if (ok) {
                    ZK.Toast.success('Проект оновлено');
                    ZK.Modal.close();
                    loadProjects();
                } else {
                    ZK.Toast.error('Помилка оновлення проекту');
                }
            });
        });
    }

    // ─── Прив'язки подiй ─────────────────────────────────────────────────────

    function bindSettingsEvents() {
        $(document).off('click.settings-theme');
        $(document).on('click.settings-theme', '#btn-toggle-theme', function() {
            ZK.Theme.toggle();
            // Re-render theme section to reflect new mode label
            var isLight = document.documentElement.classList.contains('light-theme');
            var currentLabel = isLight ? 'Свiтла' : 'Темна';
            var btnLabel = isLight ? 'Переключити на темну' : 'Переключити на свiтлу';
            $(this).text(btnLabel);
            $(this).closest('.settings-theme-row').find('strong').text(currentLabel);
        });
    }

    function bindProjectTableEvents() {
        if (!ZK.Config.canWrite) { return; }
        $(document).off('click.settings-proj');
        $(document).on('click.settings-proj', '.btn-edit-project', function() {
            var projectData = $(this).data('project');
            openEditProjectModal(projectData);
        });
    }

    // ─── Секцiя: Ставки комiсiй та податкiв ─────────────────────────────────

    function loadRates() {
        ZK.Api('settings.get_rates', {}, function(data, ok) {
            if (!ok || !data) {
                $('#settings-rates-wrap').html('<div class="zk-empty">Помилка завантаження ставок</div>');
                return;
            }
            renderRates(data);
        });
    }

    function renderRates(rates) {
        var feePct = rates.acquiring_fee_pct ? rates.acquiring_fee_pct.value : 2;
        var taxPct = rates.tax_pct           ? rates.tax_pct.value           : 10;

        var html = '<form id="form-rates" class="settings-rates-form">';
        html += '<div class="settings-rates-grid">';

        html += '<div class="settings-rate-row">';
        html += '<label class="settings-rate-label">Комiсiя еквайрингу (%)<span class="settings-rate-hint">нараховується автоматично при кожному доходi</span></label>';
        html += '<div class="settings-rate-input-wrap">';
        html += '<input type="number" id="rate-fee" class="zk-input settings-rate-input" min="0" max="100" step="0.01" value="' + feePct + '">';
        html += '<span class="settings-rate-unit">%</span>';
        html += '</div></div>';

        html += '<div class="settings-rate-row">';
        html += '<label class="settings-rate-label">Податки та бух. витрати (%)<span class="settings-rate-hint">нараховуються автоматично при кожному доходi</span></label>';
        html += '<div class="settings-rate-input-wrap">';
        html += '<input type="number" id="rate-tax" class="zk-input settings-rate-input" min="0" max="100" step="0.01" value="' + taxPct + '">';
        html += '<span class="settings-rate-unit">%</span>';
        html += '</div></div>';

        html += '</div>';
        html += '<div class="settings-rates-footer">';
        html += '<span class="settings-rates-note">Змiни застосовуються до ВСIХ транзакцiй - iснуючi авто-витрати будуть перерахованi.</span>';
        html += '<button type="button" class="zk-btn zk-btn-primary zk-btn-sm" id="btn-save-rates">Зберегти ставки</button>';
        html += '</div>';
        html += '</form>';

        $('#settings-rates-wrap').html(html);
        bindRatesEvents();
    }

    function bindRatesEvents() {
        $(document).off('click.settings-rates');
        $(document).on('click.settings-rates', '#btn-save-rates', function() {
            var feePct = parseFloat($('#rate-fee').val());
            var taxPct = parseFloat($('#rate-tax').val());

            if (isNaN(feePct) || feePct < 0 || feePct > 100) {
                ZK.Toast.error('Комiсiя еквайрингу: введiть вiд 0 до 100');
                return;
            }
            if (isNaN(taxPct) || taxPct < 0 || taxPct > 100) {
                ZK.Toast.error('Податки: введiть вiд 0 до 100');
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Збереження...');

            ZK.Api('settings.save_rates', {
                acquiring_fee_pct: feePct,
                tax_pct: taxPct
            }, function(data, ok) {
                btn.prop('disabled', false).text('Зберегти ставки');
                if (ok) {
                    var msg = 'Ставки збережено'; if (data && data.recalc_bank_fees) { msg += '. Перераховано: ' + data.recalc_bank_fees + ' комiсiй, ' + data.recalc_taxes + ' податкiв'; } ZK.Toast.success(msg);
                } else {
                    var msg = (data && data.error) ? data.error : 'Помилка збереження';
                    ZK.Toast.error(msg);
                }
            });
        });
    }

    // ─── Секцiя: Користувачi ────────────────────────────────────────────────

    var PERMISSIONS_LIST = [
        { key: 'main_dashboard',   label: 'UTM Дашборд' },
        { key: 'finance',          label: 'Фiнанси (вхiд)' },
        { key: 'finance_salary',   label: 'Зарплати' },
        { key: 'finance_cards',    label: 'Картки' },
        { key: 'finance_usdt',     label: 'USDT' },
        { key: 'finance_settings', label: 'Налаштування' }
    ];

    var ROLE_OPTIONS = [
        { val: 'admin',      label: 'Admin' },
        { val: 'targetolog', label: 'Targetolog' },
        { val: 'accountant', label: 'Accountant' },
        { val: 'viewer',     label: 'Viewer' }
    ];

    var FINANCE_ROLE_OPTIONS = [
        { val: '',          label: '— немає —' },
        { val: 'admin',     label: 'Admin' },
        { val: 'manager',   label: 'Manager' },
        { val: 'viewer',    label: 'Viewer' }
    ];

    function loadUsers() {
        ZK.Api('users.list', {}, function(data, ok) {
            if (!ok || !data) {
                $('#settings-users-wrap').html('<div class="zk-empty">Помилка завантаження</div>');
                return;
            }
            var users = data.users || data || [];
            renderUsers(users);
        });
    }

    function renderUsers(users) {
        if (!users || users.length === 0) {
            $('#settings-users-wrap').html('<div class="zk-empty">Користувачiв немає</div>');
            return;
        }

        var html = '<div class="zk-table-wrap"><table class="zk-table">';
        html += '<thead><tr>';
        html += '<th>Логiн</th><th>Роль</th><th>Фiн. роль</th><th>Права</th><th>Дiї</th>';
        html += '</tr></thead><tbody>';

        var currentUser = ZK.Config.username || '';
        for (var i = 0; i < users.length; i++) {
            var u = users[i];
            var perms = u.permissions || {};
            var permLabels = [];
            for (var j = 0; j < PERMISSIONS_LIST.length; j++) {
                if (perms[PERMISSIONS_LIST[j].key]) {
                    permLabels.push(PERMISSIONS_LIST[j].label);
                }
            }
            var permStr = permLabels.length ? permLabels.join(', ') : '—';
            var isActive = u.is_active !== false;
            var activeBadge = isActive
                ? '<span class="zk-badge zk-badge-green">Активний</span>'
                : '<span class="zk-badge zk-badge-gray">Деактивовано</span>';
            var isSelf = (u.username === currentUser);

            html += '<tr data-username="' + escAttr(u.username) + '">';
            html += '<td><strong>' + escHtml(u.username) + '</strong>'
                + (isSelf ? ' <span class="settings-self-label">(ви)</span>' : '') + '</td>';
            html += '<td>' + escHtml(u.role || '—') + '</td>';
            html += '<td>' + escHtml(u.finance_role || '—') + '</td>';
            html += '<td class="settings-perms-cell">' + escHtml(permStr) + '</td>';
            html += '<td class="zk-actions">';
            html += '<button class="zk-btn zk-btn-xs zk-btn-secondary btn-edit-user" data-user=\'' + jsonSafe(u) + '\'>Ред.</button>';
            if (!isSelf) {
                html += '<button class="zk-btn zk-btn-xs zk-btn-danger btn-delete-user" data-username="' + escAttr(u.username) + '">Видалити</button>';
            }
            html += '</td></tr>';
        }

        html += '</tbody></table></div>';
        $('#settings-users-wrap').html(html);
        bindUserTableEvents();
    }

    // ─── Модал: додавання / редагування користувача ──────────────────────────

    function openUserModal(user) {
        var u = user || {};
        var isNew = !u.username;
        var title = isNew ? 'Новий користувач' : 'Редагування: ' + u.username;
        var perms = u.permissions || {};

        var html = '<div class="modal-header"><h3>' + title + '</h3></div>';
        html += '<div class="modal-body">';
        html += '<form id="form-user" autocomplete="off">';

        // Логiн (тiльки для нових)
        if (isNew) {
            html += '<div class="form-row"><label>Логiн <span class="required">*</span></label>';
            html += '<input type="text" name="username" class="zk-input" placeholder="latin_only" required autocomplete="new-password"></div>';
        }

        // Пароль
        html += '<div class="form-row"><label>' + (isNew ? 'Пароль <span class="required">*</span>' : 'Новий пароль (залишити порожнiм = без змiн)') + '</label>';
        html += '<input type="password" name="password" class="zk-input" placeholder="мiн. 8 символiв" '
            + (isNew ? 'required' : '') + ' autocomplete="new-password"></div>';

        // Роль
        html += '<div class="form-row"><label>Системна роль</label>';
        html += '<select name="role" class="zk-select">';
        for (var i = 0; i < ROLE_OPTIONS.length; i++) {
            var sel = (u.role === ROLE_OPTIONS[i].val) ? ' selected' : '';
            html += '<option value="' + ROLE_OPTIONS[i].val + '"' + sel + '>' + ROLE_OPTIONS[i].label + '</option>';
        }
        html += '</select></div>';

        // Фiнансова роль
        html += '<div class="form-row"><label>Фiнансова роль</label>';
        html += '<select name="finance_role" class="zk-select">';
        for (var i = 0; i < FINANCE_ROLE_OPTIONS.length; i++) {
            var fsel = (u.finance_role === FINANCE_ROLE_OPTIONS[i].val || (!u.finance_role && FINANCE_ROLE_OPTIONS[i].val === '')) ? ' selected' : '';
            html += '<option value="' + FINANCE_ROLE_OPTIONS[i].val + '"' + fsel + '>' + FINANCE_ROLE_OPTIONS[i].label + '</option>';
        }
        html += '</select></div>';

        // UTM Term
        html += '<div class="form-row"><label>UTM Term</label>';
        html += '<input type="text" name="utm_term" class="zk-input" value="' + escAttr(u.utm_term || '') + '" placeholder="для фiльтрацiї звiтiв" autocomplete="off"></div>';

        // Активний
        var isActiveChecked = (u.is_active !== false) ? ' checked' : '';
        html += '<div class="form-row form-row-checkbox"><label>';
        html += '<input type="checkbox" name="is_active" value="1"' + isActiveChecked + '> Активний</label></div>';

        // Права доступу
        html += '<div class="form-row form-row-perms"><label>Права доступу</label>';
        html += '<div class="user-perms-grid">';
        for (var i = 0; i < PERMISSIONS_LIST.length; i++) {
            var p = PERMISSIONS_LIST[i];
            var checked = perms[p.key] ? ' checked' : '';
            html += '<label class="perm-checkbox-label">';
            html += '<input type="checkbox" name="perm_' + p.key + '" value="1"' + checked + '> ' + escHtml(p.label);
            html += '</label>';
        }
        html += '</div></div>';

        html += '</form></div>';
        html += '<div class="modal-footer">';
        html += '<button class="zk-btn zk-btn-secondary" id="btn-modal-cancel">Скасувати</button>';
        html += '<button class="zk-btn zk-btn-primary" id="btn-modal-submit-user">Зберегти</button>';
        html += '</div>';

        ZK.Modal.open(html);

        $(document).off('click.modal-cancel');
        $(document).on('click.modal-cancel', '#btn-modal-cancel', function() {
            ZK.Modal.close();
        });

        $(document).off('click.user-save');
        $(document).on('click.user-save', '#btn-modal-submit-user', function() {
            submitUserForm(isNew, u.username || null);
        });
    }

    function submitUserForm(isNew, existingUsername) {
        var form = document.getElementById('form-user');
        var username = isNew ? ((form.querySelector('[name="username"]').value || '').trim()) : existingUsername;
        var password = (form.querySelector('[name="password"]').value || '').trim();
        var role = form.querySelector('[name="role"]').value;
        var financeRole = form.querySelector('[name="finance_role"]').value;
        var utmTerm = (form.querySelector('[name="utm_term"]').value || '').trim();
        var isActive = form.querySelector('[name="is_active"]') ? form.querySelector('[name="is_active"]').checked : true;

        if (!username) { ZK.Toast.error('Вкажiть логiн'); return; }
        if (isNew && password.length < 8) { ZK.Toast.error('Пароль мiн. 8 символiв'); return; }
        if (!isNew && password && password.length < 8) { ZK.Toast.error('Новий пароль мiн. 8 символiв'); return; }

        // Збираємо права
        var permissions = {};
        for (var i = 0; i < PERMISSIONS_LIST.length; i++) {
            var el = form.querySelector('[name="perm_' + PERMISSIONS_LIST[i].key + '"]');
            permissions[PERMISSIONS_LIST[i].key] = el ? el.checked : false;
        }

        var payload = {
            username: username,
            role: role,
            finance_role: financeRole || null,
            utm_term: utmTerm || null,
            is_active: isActive,
            permissions: permissions
        };
        if (isNew) { payload.password = password; }
        else if (password) { payload.password = password; }

        ZK.Api('users.save', payload, function(data, ok) {
            if (ok) {
                ZK.Toast.success(isNew ? 'Користувача створено' : 'Збережено');
                ZK.Modal.close();
                loadUsers();
            } else {
                var msg = (data && data.error) ? data.error : 'Помилка збереження';
                ZK.Toast.error(msg);
            }
        });
    }

    function deleteUser(username) {
        if (!username) { return; }
        ZK.Api('users.delete', { username: username }, function(data, ok) {
            if (ok) {
                ZK.Toast.success('Користувача видалено');
                loadUsers();
            } else {
                var msg = (data && data.error) ? data.error : 'Помилка видалення';
                ZK.Toast.error(msg);
            }
        });
    }

    // ─── Прив'язки подiй: користувачi ────────────────────────────────────────

    function bindUserTableEvents() {
        $(document).off('click.settings-users');
        $(document).on('click.settings-users', '#btn-add-user', function() {
            openUserModal(null);
        });
        $(document).on('click.settings-users', '.btn-edit-user', function() {
            var userData = $(this).data('user');
            openUserModal(userData);
        });
        $(document).on('click.settings-users', '.btn-delete-user', function() {
            var uname = $(this).data('username');
            if (!uname) { return; }
            if (!confirm('Видалити користувача "' + uname + '"?')) { return; }
            deleteUser(uname);
        });
    }

    // ─── Утилiти ─────────────────────────────────────────────────────────────

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function jsonSafe(obj) {
        return JSON.stringify(obj).replace(/'/g, '&#39;');
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    return {
        load: load,
        initialized: initialized
    };

})();
