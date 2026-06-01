// === FINANCE-EXPENSES.JS ===
// finance/assets/js/finance-expenses.js
// НАЗНАЧЕНИЕ: Модуль аналiтики витрат — розподiл по групах/категорiях,
//             pie chart (по групах), line chart (по днях), таблиця категорiй
// СВЯЗИ: finance-app.js (ZK namespace), api/handler.php, Chart.js (CDN)
// РАЗМЕР: ~430 строк

ZK.Expenses = (function() {
    var pieChart  = null;
    var lineChart = null;
    var currentFilters = {};

    var CHART_COLORS = [
        '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
        '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16',
    ];

    var GROUP_OPTIONS = [
        { value: '',            label: 'Всi групи'            },
        { value: 'advertising', label: '📣 Реклама'           },
        { value: 'production',  label: '🎁 Подарунки'         },
        { value: 'fees',        label: '💳 Податки та комiсiї' },
        { value: 'operations',  label: '🏢 Операцiйнi'        },
        { value: 'team',        label: '👥 Команда'           },
        { value: 'other',       label: '❓ Iнше'              }
    ];

    // Метки групп для pie chart и карточек
    var GROUP_LABELS = {
        advertising: '📣 Реклама',
        production:  '🎁 Подарунки',
        fees:        '💳 Податки та комiсiї',
        operations:  '🏢 Операцiйнi',
        team:        '👥 Команда',
        other:       '❓ Iнше'
    };

    // ----------------------------------------------------------------
    // Public entry point
    // ----------------------------------------------------------------
    function load() {
        currentFilters = {};
        renderFilters();
    }

    // ----------------------------------------------------------------
    // Filters + layout
    // ----------------------------------------------------------------
    function buildGroupOptions() {
        var opts = '';
        for (var i = 0; i < GROUP_OPTIONS.length; i++) {
            opts += '<option value="' + GROUP_OPTIONS[i].value + '">' + GROUP_OPTIONS[i].label + '</option>';
        }
        return opts;
    }

    function renderFilters() {
        var $section = $('#expenses-section');

        var html =
            '<div class="finance-section-header">' +
                '<h2 class="exp-title">Аналiтика витрат</h2>' +
            '</div>' +

            '<div class="exp-filters-row" id="expenses-filters-bar">' +
                '<select class="finance-select exp-filter" data-key="project_id" id="exp-project-select">' +
                    '<option value="">Всi проекти</option>' +
                '</select>' +
                '<select class="finance-select exp-filter" data-key="category_group" id="exp-group-select">' +
                    buildGroupOptions() +
                '</select>' +
                '<input type="date" class="finance-input exp-filter" data-key="date_from" placeholder="Вiд">' +
                '<input type="date" class="finance-input exp-filter" data-key="date_to"   placeholder="До">' +
            '</div>' +

            // KPI-карточки — только витрати (без доходу/ROI)
            '<div class="exp-totals-grid exp-totals-grid--groups" id="expenses-totals">' +
                '<div class="zk-skeleton" style="height:80px"></div>' +
            '</div>' +

            // Графiки
            '<div class="exp-charts-grid">' +
                '<div class="exp-chart-card">' +
                    '<h3 class="exp-chart-title">Розподiл по групах</h3>' +
                    '<div id="expenses-pie-container" style="position:relative; height:300px;"></div>' +
                '</div>' +
                '<div class="exp-chart-card">' +
                    '<h3 class="exp-chart-title">Витрати по днях</h3>' +
                    '<div id="expenses-line-container" style="position:relative; height:300px;"></div>' +
                '</div>' +
            '</div>' +

            // Таблиця категорiй
            '<div class="exp-table-card">' +
                '<h3 class="exp-chart-title">Деталi за категорiями</h3>' +
                '<div id="expenses-table-wrap">' +
                    '<div class="zk-skeleton" style="height:200px"></div>' +
                '</div>' +
            '</div>';

        $section.html(html);

        // Проекти
        ZK.Api('projects.list', {}, function(data) {
            var items = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
            var $sel  = $('#exp-project-select');
            var activeId = null;
            for (var j = 0; j < items.length; j++) {
                var selected = items[j].status === 'active' ? ' selected' : '';
                $sel.append('<option value="' + items[j].id + '"' + selected + '>' + escHtml(items[j].name) + '</option>');
                if (items[j].status === 'active') { activeId = items[j].id; }
            }
            if (activeId) { currentFilters.project_id = activeId; }
            loadReport(currentFilters);
        });

        $(document).off('change.expfilters input.expfilters', '.exp-filter');
        $(document).on('change.expfilters input.expfilters', '.exp-filter', function() {
            var key = $(this).data('key');
            var val = (this.value || '').trim();
            if (val !== '') {
                currentFilters[key] = val;
            } else {
                delete currentFilters[key];
            }
            loadReport(currentFilters);
        });
    }

    // ----------------------------------------------------------------
    // Report loader
    // ----------------------------------------------------------------
    function loadReport(filters) {
        $('#expenses-totals').html('<div class="zk-skeleton" style="height:80px"></div>');
        $('#expenses-table-wrap').html('<div class="zk-skeleton" style="height:150px"></div>');

        ZK.Api('expenses.report', filters, function(data) {
            var bySource, byGroup, totals, byDay;
            if (data && data.by_source) {
                bySource = data.by_source || [];
                byGroup  = data.by_group  || [];
                totals   = data.totals    || null;
                byDay    = data.by_day    || [];
            } else {
                bySource = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
                byGroup  = [];
                totals   = null;
                byDay    = [];
            }

            renderTotals(totals, bySource, byGroup);
            renderPieChart(byGroup, bySource);
            renderLineChart(byDay);
            renderTable(bySource);
        });
    }

    // ----------------------------------------------------------------
    // KPI-карточки: итого + по группах
    // ----------------------------------------------------------------
    function renderTotals(totals, bySource, byGroup) {
        // Считаем общую сумму расходов
        var totalSpend = 0;
        for (var i = 0; i < bySource.length; i++) {
            totalSpend += parseFloat(bySource[i].spend) || 0;
        }
        if (totals && totals.total_spend) {
            totalSpend = parseFloat(totals.total_spend) || totalSpend;
        }

        // Строим карточку "Всього витрат" + карточки по группах
        var html = '<div class="exp-kpi-card kpi-neutral exp-kpi-total">' +
            '<div class="kpi-icon">💸</div>' +
            '<div class="kpi-body">' +
                '<div class="kpi-title">Всього витрат</div>' +
                '<div class="kpi-value">' + ZK.Format.money(totalSpend) + '</div>' +
            '</div>' +
        '</div>';

        // Карточки по группах (из byGroup)
        var groupData = buildGroupTotals(byGroup, bySource);
        for (var g in GROUP_LABELS) {
            if (!groupData[g]) continue;
            var amt = groupData[g];
            var pct = totalSpend > 0 ? ((amt / totalSpend) * 100).toFixed(1) : '0.0';
            html +=
                '<div class="exp-kpi-card kpi-neutral">' +
                    '<div class="kpi-body">' +
                        '<div class="kpi-title">' + escHtml(GROUP_LABELS[g]) + '</div>' +
                        '<div class="kpi-value">' + ZK.Format.money(amt) + '</div>' +
                        '<div class="kpi-subtitle">' + pct + '% вiд загального</div>' +
                    '</div>' +
                '</div>';
        }

        $('#expenses-totals').html(html);
    }

    // Строим объект { groupKey: totalAmount } из byGroup или fallback из bySource
    function buildGroupTotals(byGroup, bySource) {
        var result = {};

        // Если backend вернул by_group — используем
        if (byGroup && byGroup.length) {
            for (var i = 0; i < byGroup.length; i++) {
                var g = byGroup[i].group_key || byGroup[i].group;
                if (g) result[g] = (result[g] || 0) + (parseFloat(byGroup[i].spend) || 0);
            }
            return result;
        }

        // Fallback: пробуем определить группу из метки категории
        var labelToGroup = {
            'Реклама': 'advertising',
            'Meta': 'advertising', 'Google': 'advertising', 'Facebook': 'advertising',
            'Instagram': 'advertising', 'Viber': 'advertising', 'SMS': 'advertising',
            'Подарунки': 'production', 'Покупка авто': 'production', 'Приз': 'production',
            'Банкiвськi комiсiї': 'fees', 'Еквайринг': 'fees', 'Комiсiя': 'fees', 'Податки': 'fees',
            'Операцiйнi': 'operations',
            'Команда': 'team',
        };

        for (var j = 0; j < (bySource || []).length; j++) {
            var src = bySource[j].utm_source || bySource[j].source || '';
            var grp = 'other';
            for (var lbl in labelToGroup) {
                if (src.indexOf(lbl) !== -1) { grp = labelToGroup[lbl]; break; }
            }
            result[grp] = (result[grp] || 0) + (parseFloat(bySource[j].spend) || 0);
        }
        return result;
    }

    // ----------------------------------------------------------------
    // Pie chart — по группах
    // ----------------------------------------------------------------
    function renderPieChart(byGroup, bySource) {
        var $container = $('#expenses-pie-container');

        if (pieChart) {
            try { pieChart.destroy(); } catch (e) {}
            pieChart = null;
        }

        // Данные для pie: группы из byGroup или bySource fallback
        var groupTotals = buildGroupTotals(byGroup, bySource);

        var labels = [];
        var values = [];
        var groupOrder = ['advertising', 'production', 'fees', 'operations', 'team', 'other'];
        for (var i = 0; i < groupOrder.length; i++) {
            var gk = groupOrder[i];
            if (groupTotals[gk] && groupTotals[gk] > 0) {
                labels.push(GROUP_LABELS[gk] || gk);
                values.push(groupTotals[gk]);
            }
        }

        if (!values.length) {
            $container.html('<div class="finance-empty-state"><p>Немає витрат</p></div>');
            return;
        }

        $container.html('<canvas id="expenses-pie"></canvas>');

        setTimeout(function() {
            var canvas = document.getElementById('expenses-pie');
            if (!canvas) return;

            pieChart = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: CHART_COLORS.slice(0, values.length),
                        borderWidth: 2,
                        borderColor: getCssVar('--card-bg', '#0a0a0a'),
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 8,
                                font: { size: 11 },
                                color: getCssVar('--text-primary', '#e0e0e0')
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var val = ctx.parsed || 0;
                                    var sum = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                    var pct = sum > 0 ? ((val / sum) * 100).toFixed(1) : 0;
                                    return ' ' + ctx.label + ': ' + ZK.Format.money(val) + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }, 100);
    }

    // ----------------------------------------------------------------
    // Line chart — расходы по дням
    // ----------------------------------------------------------------
    function renderLineChart(byDay) {
        var $container = $('#expenses-line-container');

        if (lineChart) {
            try { lineChart.destroy(); } catch (e) {}
            lineChart = null;
        }

        if (!byDay || !byDay.length) {
            $container.html('<div class="finance-empty-state"><p>Немає даних за перiод</p></div>');
            return;
        }

        $container.html('<canvas id="expenses-line"></canvas>');

        var labels = [];
        var values = [];
        for (var i = 0; i < byDay.length; i++) {
            labels.push(formatDateShort(byDay[i].date));
            values.push(parseFloat(byDay[i].spend) || 0);
        }

        setTimeout(function() {
            var canvas = document.getElementById('expenses-line');
            if (!canvas) return;

            lineChart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Витрати UAH',
                        data: values,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.15)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        fill: true,
                        tension: 0.3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    return ' ' + ZK.Format.money(ctx.parsed.y || 0);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: getCssVar('--text-muted', '#888'),
                                maxRotation: 45,
                                minRotation: 0,
                                font: { size: 10 }
                            },
                            grid: { color: getCssVar('--border-color', '#222') }
                        },
                        y: {
                            ticks: {
                                color: getCssVar('--text-muted', '#888'),
                                font: { size: 10 },
                                callback: function(value) {
                                    if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000)    return (value / 1000).toFixed(0) + 'k';
                                    return value;
                                }
                            },
                            grid: { color: getCssVar('--border-color', '#222') }
                        }
                    }
                }
            });
        }, 100);
    }

    // ----------------------------------------------------------------
    // Таблица категорий (без Revenue/ROI/ROAS)
    // ----------------------------------------------------------------
    function renderTable(items) {
        var $wrap = $('#expenses-table-wrap');

        if (!items || !items.length) {
            $wrap.html('<div class="finance-empty-state"><p>Немає даних</p></div>');
            return;
        }

        var sorted = items.slice().sort(function(a, b) {
            return (parseFloat(b.spend) || 0) - (parseFloat(a.spend) || 0);
        });

        var totalSpend = 0;
        for (var k = 0; k < sorted.length; k++) {
            totalSpend += parseFloat(sorted[k].spend) || 0;
        }

        var html =
            '<div class="finance-table-wrap">' +
                '<table class="finance-table" id="expenses-table">' +
                    '<thead><tr>' +
                        '<th>Категорiя</th>' +
                        '<th class="num">Витрати</th>' +
                        '<th class="num">% вiд загального</th>' +
                    '</tr></thead>' +
                    '<tbody>';

        for (var i = 0; i < sorted.length; i++) {
            html += renderExpenseRow(sorted[i], totalSpend);
        }

        // Итого
        html +=
            '<tr class="exp-table-total">' +
                '<td><strong>Всього</strong></td>' +
                '<td class="num"><strong>' + ZK.Format.money(totalSpend) + '</strong></td>' +
                '<td class="num"><strong>100%</strong></td>' +
            '</tr>';

        html += '</tbody></table></div>';
        $wrap.html(html);
    }

    function renderExpenseRow(row, totalSpend) {
        var spend = parseFloat(row.spend) || 0;
        var pct   = totalSpend > 0 ? ((spend / totalSpend) * 100).toFixed(1) : '0.0';
        var label = row.utm_source || row.source || '—';

        return '<tr>' +
            '<td>' + escHtml(label) + '</td>' +
            '<td class="num">' + ZK.Format.money(spend) + '</td>' +
            '<td class="num">' +
                '<div class="exp-pct-bar">' +
                    '<div class="exp-pct-fill" style="width:' + pct + '%"></div>' +
                    '<span class="exp-pct-label">' + pct + '%</span>' +
                '</div>' +
            '</td>' +
        '</tr>';
    }

    // Перерисовка при смене темы
    window.addEventListener('zk:theme-change', function() {
        if (currentFilters) loadReport(currentFilters);
    });

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getCssVar(name, fallback) {
        try {
            var val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return val || (fallback || '#ffffff');
        } catch (e) {
            return fallback || '#ffffff';
        }
    }

    function formatDateShort(isoDate) {
        if (!isoDate) return '';
        var parts = String(isoDate).split('-');
        if (parts.length >= 3) return parts[2] + '.' + parts[1];
        return isoDate;
    }

    return { load: load };

})();
