// === FINANCE-DASHBOARD.JS ===
// finance/assets/js/finance-dashboard.js
// НАЗНАЧЕНИЕ: ZK.Dashboard - метрики, таблица проектов, загрузка данных
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Format, ZK.Toast, ZK.Config), api/handler.php
// РАЗМЕР: ~320 строк

var ZK = ZK || {};

ZK.Dashboard = (function() {
    var initialized = false;

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
        ZK.Api('dashboard.summary', {}, function(data, success) {
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
        html += renderMetrics(data);
        html += renderProjectsTable(data.projects || []);
        html += '</div>';

        $section.html(html);
        initialized = true;

        bindEvents($section);
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
            html += '<div class="metric-value">' + escapeHtml(c.value) + '</div>';
            html += '</div>';
        }
        html += '</div>';

        return html;
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
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    return {
        load: load,
        get initialized() { return initialized; }
    };
})();
