// === FINANCE-DASHBOARD.JS ===
// finance/assets/js/finance-dashboard.js
// НАЗНАЧЕНИЕ: ZK.Dashboard - метрики, таблица проектов, загрузка данных
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Format, ZK.Toast, ZK.Config), api/handler.php
// РАЗМЕР: ~320 строк

var ZK = ZK || {};

ZK.Dashboard = (function() {
    var initialized = false;
    var selectedProjectId = 0;  // 0 = все проекты
    var STORAGE_KEY = 'zk_finance_dashboard_project';

    // ─── Public: получить текущий выбранный проект (для других модулей) ────
    function getSelectedProjectId() {
        // Если в памяти 0 - попробовать localStorage
        if (selectedProjectId === 0) {
            try {
                var saved = parseInt(localStorage.getItem(STORAGE_KEY), 10) || 0;
                if (saved > 0) selectedProjectId = saved;
            } catch (e) {}
        }
        return selectedProjectId;
    }

    function setSelectedProjectId(id) {
        selectedProjectId = parseInt(id, 10) || 0;
        try {
            if (selectedProjectId > 0) {
                localStorage.setItem(STORAGE_KEY, String(selectedProjectId));
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {}
    }

    // ─── Skeleton ─────────────────────────────────────────────────────────────

    function showSkeleton() {
        var $section = $('#dashboard-section');
        if (!$section.length) { return; }

        var html = '<div class="dashboard-loading">';

        // metrics skeleton
        html += '<div class="finance-metrics finance-metrics-skeleton">';
        for (var i = 0; i < 5; i++) {
            html += '<div class="metric-card skeleton-card">';
            html += '<div class="zk-skeleton skeleton-label"></div>';
            html += '<div class="zk-skeleton skeleton-value"></div>';
            html += '</div>';
        }
        html += '</div>';

        // table skeleton
        html += '<div class="dashboard-table-wrap skeleton-table">';
        html += '<div class="zk-skeleton skeleton-table-header"></div>';
        for (var j = 0; j < 4; j++) {
            html += '<div class="zk-skeleton skeleton-table-row"></div>';
        }
        html += '</div>';

        html += '</div>';

        $section.html(html);
    }

    // ─── Load ─────────────────────────────────────────────────────────────────

    function load() {
        showSkeleton();

        // Восстановить выбор проекта из localStorage при первом запуске
        getSelectedProjectId();

        var params = {};
        if (selectedProjectId > 0) {
            params.project_id = selectedProjectId;
        }

        ZK.Api('dashboard.summary', params, function(data, success) {
            if (!success || !data) {
                ZK.Toast.error('Не вдалося завантажити дашборд');
                $('#dashboard-section').html(
                    '<div class="dashboard-error">Помилка завантаження даних. Спробуйте оновити сторiнку.</div>'
                );
                return;
            }
            render(data);
        });
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    function render(data) {
        var $section = $('#dashboard-section');
        if (!$section.length) { return; }

        var html = '<div class="dashboard-inner">';
        html += renderProjectFilter(data.projects || []);
        html += renderMetrics(data);
        html += renderExpensesByGroup(data);
        html += renderProjectsTable(data.projects || []);
        html += '</div>';

        $section.html(html);
        initialized = true;

        bindEvents($section);
    }

    // ─── Project Filter ──────────────────────────────────────────────────────

    function renderProjectFilter(projects) {
        var html = '<div class="dashboard-filter-bar">';
        html += '<label class="dashboard-filter-label">📁 Фiльтр проекту:</label>';
        html += '<select class="finance-select dashboard-project-filter" id="dashboard-project-filter">';
        html += '<option value="0">Всi проекти</option>';

        for (var i = 0; i < projects.length; i++) {
            var p = projects[i];
            var selected = (parseInt(p.id, 10) === selectedProjectId) ? ' selected' : '';
            html += '<option value="' + p.id + '"' + selected + '>' + escHtmlLocal(p.name) + '</option>';
        }
        html += '</select>';

        // Индикатор активного фильтра
        if (selectedProjectId > 0) {
            var selectedName = '';
            for (var j = 0; j < projects.length; j++) {
                if (parseInt(projects[j].id, 10) === selectedProjectId) {
                    selectedName = projects[j].name;
                    break;
                }
            }
            html += '<span class="dashboard-filter-hint">Показанi данi тiльки для <strong>' + escHtmlLocal(selectedName) + '</strong></span>';
        }

        html += '</div>';
        return html;
    }

    // ─── Metrics ─────────────────────────────────────────────────────────────

    function renderMetrics(data) {
        var cards = [];

        cards.push({
            type: 'income',
            icon: '\ud83d\udc9a',
            label: 'Загальний дохiд',
            value: ZK.Format.money(data.total_income)
        });

        cards.push({
            type: 'expense',
            icon: '\ud83d\udd34',
            label: 'Операцiйнi витрати',
            value: ZK.Format.money(data.total_expenses)
        });

        cards.push({
            type: 'profit',
            icon: '\ud83d\udc8e',
            label: 'Прибуток',
            value: ZK.Format.money(data.profit)
        });

        cards.push({
            type: 'margin',
            icon: '\ud83d\udcca',
            label: 'Маржа',
            value: ZK.Format.pct(data.margin)
        });

        // Унiкальнi покупцi
        if (data.total_payments > 0) {
            var buyerSubtitle = '';
            if (data.repeat_buyers_pct !== null && data.repeat_buyers_pct > 0) {
                buyerSubtitle = data.repeat_buyers_pct + '% повторних оплат';
            }
            cards.push({
                type: 'unique-buyers',
                icon: '\ud83d\udc65',
                label: 'Унiкальних покупцiв',
                value: data.unique_buyers,
                subtitle: buyerSubtitle,
                extra: data.total_payments + ' оплат всього'
            });
        }

        if (ZK.Config.canUsdt) {
            cards.push({
                type: 'taxes',
                icon: '\ud83d\udcb1',
                label: 'Подат. та комiсiї',
                value: ZK.Format.money(data.taxes_and_fees)
            });

            cards.push({
                type: 'dividend-vadym',
                icon: '\ud83d\udfe0',
                label: 'Дивiденди Вадим',
                value: ZK.Format.money(data.dividends_vadym)
            });

            cards.push({
                type: 'dividend-artem',
                icon: '\ud83d\udfe0',
                label: 'Дивiденди Артем',
                value: ZK.Format.money(data.dividends_artem)
            });
        }

        var html = '<div class="finance-metrics">';
        for (var i = 0; i < cards.length; i++) {
            var c = cards[i];
            html += '<div class="metric-card ' + c.type + '">';
            html += '<div class="metric-label">' + c.icon + ' ' + escapeHtml(c.label) + '</div>';
            html += '<div class="metric-value">' + escapeHtml(String(c.value)) + '</div>';
            if (c.subtitle) {
                html += '<div class="metric-subtitle">' + escapeHtml(c.subtitle) + '</div>';
            }
            if (c.extra) {
                html += '<div class="metric-extra">' + escapeHtml(c.extra) + '</div>';
            }
            html += '</div>';
        }
        html += '</div>';

        return html;
    }

    // ─── Витрати по групах ────────────────────────────────────────────────────

    function renderExpensesByGroup(data) {
        var groups = data.expenses_by_group || null;
        var labels = data.group_labels || {};

        if (!groups) return '';

        // Собираем массив для сортировки по убыванию суммы
        var items = [];
        var total = 0;
        for (var key in groups) {
            if (groups.hasOwnProperty(key)) {
                var val = parseFloat(groups[key]) || 0;
                items.push({
                    key:   key,
                    label: labels[key] || key,
                    value: val
                });
                total += val;
            }
        }

        if (total === 0) return '';

        // Сортировка: больше сверху
        items.sort(function(a, b) { return b.value - a.value; });

        var html = '<div class="expenses-by-group-card">';
        html += '<h3>💸 Витрати по групах <span class="total-hint">Всього: ' + ZK.Format.money(total) + ' ₴</span></h3>';

        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var pct = total > 0 ? (it.value / total * 100) : 0;
            html += '<div class="ebg-item" data-group="' + it.key + '">';
            html +=     '<div class="ebg-label">' + escHtmlLocal(it.label) + '</div>';
            html +=     '<div class="ebg-bar-wrap"><div class="ebg-bar" style="width:' + pct.toFixed(1) + '%"></div></div>';
            html +=     '<div class="ebg-value">' + ZK.Format.money(it.value) + ' ₴</div>';
            html +=     '<div class="ebg-pct">' + pct.toFixed(1) + '%</div>';
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    function escHtmlLocal(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ─── Projects table ───────────────────────────────────────────────────────

    function renderProjectsTable(projects) {
        var html = '<div class="dashboard-table-section">';
        html += '<div class="dashboard-table-header">';
        html += '<h3 class="dashboard-table-title">Проекти</h3>';
        html += '</div>';

        if (!projects || !projects.length) {
            html += '<div class="dashboard-empty">Проектiв немає</div>';
            html += '</div>';
            return html;
        }

        html += '<div class="table-wrap">';
        html += '<table class="finance-table">';
        html += '<thead><tr>';
        html += '<th>Проект</th>';
        html += '<th>Статус</th>';
        html += '<th>Дати</th>';
        html += '<th class="col-num">Дохiд</th>';
        html += '<th class="col-num">Витрати</th>';
        html += '<th class="col-num">Прибуток</th>';
        html += '<th class="col-num">Маржа</th>';
        html += '<th class="col-actions">Дiї</th>';
        html += '</tr></thead>';
        html += '<tbody>';

        for (var i = 0; i < projects.length; i++) {
            html += renderProjectRow(projects[i]);
        }

        html += '</tbody>';
        html += '</table>';
        html += '</div>';
        html += '</div>';

        return html;
    }

    function renderProjectRow(p) {
        var statusBadge = buildStatusBadge(p.status);
        var dateRange = buildDateRange(p.date_start, p.date_end);

        var income   = ZK.Format.money(p.total_income);
        var expenses = ZK.Format.money(p.total_expenses);
        var profit   = ZK.Format.money(p.profit);
        var margin   = ZK.Format.pct(p.margin);

        var profitClass = '';
        if (p.profit > 0) { profitClass = 'val-positive'; }
        else if (p.profit < 0) { profitClass = 'val-negative'; }

        var html = '<tr>';
        html += '<td class="col-name"><span class="project-name">' + escapeHtml(p.name || '—') + '</span></td>';
        html += '<td>' + statusBadge + '</td>';
        html += '<td class="col-dates">' + escapeHtml(dateRange) + '</td>';
        html += '<td class="col-num val-income">' + escapeHtml(income) + '</td>';
        html += '<td class="col-num val-expense">' + escapeHtml(expenses) + '</td>';
        html += '<td class="col-num ' + profitClass + '">' + escapeHtml(profit) + '</td>';
        html += '<td class="col-num">' + escapeHtml(margin) + '</td>';
        html += '<td class="col-actions">';
        html += '<button class="btn btn-sm btn-detail" data-project-id="' + parseInt(p.id, 10) + '">Деталi</button>';
        html += '</td>';
        html += '</tr>';

        return html;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    function buildStatusBadge(status) {
        var label = '—';
        var cls   = 'status-default';

        switch ((status || '').toLowerCase()) {
            case 'active':
            case 'активний':
                label = 'Активний';
                cls   = 'status-active';
                break;
            case 'completed':
            case 'завершений':
                label = 'Завершений';
                cls   = 'status-completed';
                break;
            case 'paused':
            case 'призупинений':
                label = 'Призупинений';
                cls   = 'status-paused';
                break;
            case 'planned':
            case 'плановий':
                label = 'Плановий';
                cls   = 'status-planned';
                break;
            default:
                label = escapeHtml(status || '—');
                break;
        }

        return '<span class="status-badge ' + cls + '">' + label + '</span>';
    }

    function buildDateRange(start, end) {
        var s = ZK.Format.date(start);
        var e = ZK.Format.date(end);
        if (s === '—' && e === '—') { return '—'; }
        if (s === '—') { return 'до ' + e; }
        if (e === '—') { return 'з ' + s; }
        return s + ' - ' + e;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) { return ''; }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ─── Events ──────────────────────────────────────────────────────────────

    function bindEvents($container) {
        $container.on('click', '.btn-detail', function() {
            var id = parseInt($(this).data('project-id'), 10);
            if (id) {
                ZK.Router.set('projects', id);
            }
        });

        // Фильтр проекта на дашборде
        $container.on('change', '#dashboard-project-filter', function() {
            var newId = parseInt($(this).val(), 10) || 0;
            setSelectedProjectId(newId);

            // Оповестить другие модули (включая модалку "Додати витрату")
            try {
                window.dispatchEvent(new CustomEvent('zk:dashboard-project-changed', {
                    detail: { project_id: newId }
                }));
            } catch (e) {}

            // Перезагрузить дашборд с новым фильтром
            load();
        });
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    // Перезагрузить дашборд когда добавлен новый расход через модалку
    window.addEventListener('zk:expense-added', function() {
        // Перезагружаем только если мы сейчас на вкладке Дашборд
        var $section = $('#dashboard-section');
        if ($section.length && $section.hasClass('active')) {
            load();
        }
    });

    return {
        load: load,
        getSelectedProjectId: getSelectedProjectId,
        setSelectedProjectId: setSelectedProjectId,
        get initialized() { return initialized; }
    };
})();
