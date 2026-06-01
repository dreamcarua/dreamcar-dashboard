// === FINANCE-SETTINGS.JS ===
// finance/assets/js/finance-settings.js
// НАЗНАЧЕНИЕ: Модуль налаштувань - тема, проекти, iнформацiя про користувача
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Toast, ZK.Modal, ZK.Theme, ZK.Config), api/handler.php
// РАЗМЕР: ~180 строк

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

        // Блок: Iнформацiя
        html += '<div class="settings-section-block">';
        html += '<div class="settings-block-title">Iнформацiя</div>';
        html += renderInfoSection();
        html += '</div>';

        $('#settings-section').html(html);

        bindSettingsEvents();
        loadProjects();
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
