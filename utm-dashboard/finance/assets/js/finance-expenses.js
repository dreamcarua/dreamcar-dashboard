// === FINANCE-EXPENSES.JS ===
// finance/assets/js/finance-expenses.js
// НАЗНАЧЕНИЕ: Модуль аналiтики витрат - звiт по каналах з ROI/ROAS/CPL + кругова дiаграма
// СВЯЗИ: finance-app.js (ZK namespace), api/handler.php, Chart.js (CDN)
// РАЗМЕР: ~300 строк

ZK.Expenses = (function() {
    var chartInstance = null;
    var currentFilters = {};

    var CHART_COLORS = [
        '#3b82f6',
        '#10b981',
        '#f59e0b',
        '#ef4444',
        '#8b5cf6',
        '#06b6d4'
    ];

    var SOURCE_OPTIONS = [
        { value: '',       label: 'Всi джерела' },
        { value: 'Meta',   label: 'Meta'   },
        { value: 'Google', label: 'Google' },
        { value: 'Viber',  label: 'Viber'  },
        { value: 'SMS',    label: 'SMS'    },
        { value: 'Email',  label: 'Email'  }
    ];

    // ----------------------------------------------------------------
    // Public entry point
    // ----------------------------------------------------------------
    function load() {
        currentFilters = {};
        renderFilters();
        loadReport({});
    }

    // ----------------------------------------------------------------
    // Filters
    // ----------------------------------------------------------------
    function renderFilters() {
        var $section = $('#expenses-section');

        var sourceOpts = '';
        for (var i = 0; i < SOURCE_OPTIONS.length; i++) {
            sourceOpts += '<option value="' + SOURCE_OPTIONS[i].value + '">' + SOURCE_OPTIONS[i].label + '</option>';
        }

        var html =
            '<div class="finance-section-header"><h2>Аналiтика витрат</h2></div>' +

            '<div class="finance-filters" id="expenses-filters-bar">' +

            '<select class="finance-select exp-filter" data-key="project_id" id="exp-project-select">' +
            '<option value="">Всi проекти</option>' +
            '</select>' +

            '<select class="finance-select exp-filter" data-key="source" id="exp-source-select">' +
            sourceOpts +
            '</select>' +

            '<input type="date" class="finance-input exp-filter" data-key="date_from" placeholder="Вiд">' +
            '<input type="date" class="finance-input exp-filter" data-key="date_to" placeholder="До">' +

            '</div>' +

            '<div id="expenses-chart-container" style="max-width:420px;margin:10px 0"></div>' +

            '<div id="expenses-table-wrap">' +
            '<div class="zk-skeleton" style="height:150px"></div>' +
            '</div>';

        $section.html(html);

        // Load projects into select
        ZK.Api('projects.list', {}, function(data) {
            var items = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
            var $sel  = $('#exp-project-select');
            for (var j = 0; j < items.length; j++) {
                $sel.append('<option value="' + items[j].id + '">' + escHtml(items[j].name) + '</option>');
            }
        });

        // Bind filter changes
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
        var $wrap = $('#expenses-table-wrap');
        $wrap.html('<div class="zk-skeleton" style="height:150px"></div>');

        ZK.Api('expenses.report', filters, function(data) {
            var items = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
            renderTable(items);
            renderChart(items);
        });
    }

    // ----------------------------------------------------------------
    // Table
    // ----------------------------------------------------------------
    function renderTable(items) {
        var $wrap = $('#expenses-table-wrap');

        if (!items || !items.length) {
            $wrap.html('<div class="finance-empty-state"><p>Немає даних</p></div>');
            return;
        }

        var html =
            '<div class="finance-table-wrap">' +
            '<table class="finance-table" id="expenses-table">' +
            '<thead><tr>' +
            '<th>Канал</th>' +
            '<th class="num">Витрати UAH</th>' +
            '<th class="num">Дохiд UAH</th>' +
            '<th class="num">ROI %</th>' +
            '<th class="num">ROAS</th>' +
            '<th class="num">CPL</th>' +
            '<th class="num">CPA</th>' +
            '<th class="num">Лiди</th>' +
            '<th class="num">Оплати</th>' +
            '</tr></thead>' +
            '<tbody>';

        for (var i = 0; i < items.length; i++) {
            html += renderExpenseRow(items[i]);
        }

        html += '</tbody>' + renderTotalsRow(items) + '</table></div>';
        $wrap.html(html);
    }

    function renderExpenseRow(row) {
        var spend   = parseFloat(row.spend)        || 0;
        var revenue = parseFloat(row.paid_amount)  || 0;
        var leads   = parseInt(row.leads, 10)      || 0;
        var pays    = parseInt(row.paid_count, 10) || 0;

        var roi  = spend > 0 ? ((revenue - spend) / spend * 100).toFixed(1) : '—';
        var roas = spend > 0 ? (revenue / spend).toFixed(2) : '—';
        var cpl  = leads > 0 ? (spend / leads).toFixed(2) : '—';
        var cpa  = pays  > 0 ? (spend / pays).toFixed(2)  : '—';

        var roiClass = '';
        if (roi !== '—') {
            roiClass = parseFloat(roi) >= 0 ? 'positive' : 'negative';
        }

        return '<tr>' +
            '<td>' + escHtml(row.utm_source || row.source || '—') + '</td>' +
            '<td class="num">'  + ZK.Format.money(spend)   + '</td>' +
            '<td class="num">'  + ZK.Format.money(revenue) + '</td>' +
            '<td class="num ' + roiClass + '">' + (roi !== '—' ? roi + '%' : '—') + '</td>' +
            '<td class="num">'  + roas + '</td>' +
            '<td class="num">'  + (cpl !== '—' ? ZK.Format.money(cpl) : '—') + '</td>' +
            '<td class="num">'  + (cpa !== '—' ? ZK.Format.money(cpa) : '—') + '</td>' +
            '<td class="num">'  + leads + '</td>' +
            '<td class="num">'  + pays  + '</td>' +
            '</tr>';
    }

    function renderTotalsRow(items) {
        var totSpend = 0, totRevenue = 0, totLeads = 0, totPays = 0;
        for (var i = 0; i < items.length; i++) {
            totSpend   += parseFloat(items[i].spend)       || 0;
            totRevenue += parseFloat(items[i].paid_amount) || 0;
            totLeads   += parseInt(items[i].leads, 10)     || 0;
            totPays    += parseInt(items[i].paid_count, 10) || 0;
        }

        var totRoi  = totSpend > 0 ? ((totRevenue - totSpend) / totSpend * 100).toFixed(1) : '—';
        var totRoas = totSpend > 0 ? (totRevenue / totSpend).toFixed(2) : '—';
        var totCpl  = totLeads > 0 ? (totSpend / totLeads).toFixed(2) : '—';
        var totCpa  = totPays  > 0 ? (totSpend / totPays).toFixed(2)  : '—';
        var totRoiClass = totRoi !== '—' ? (parseFloat(totRoi) >= 0 ? 'positive' : 'negative') : '';

        return '<tfoot><tr class="totals-row">' +
            '<td><strong>Всього</strong></td>' +
            '<td class="num">' + ZK.Format.money(totSpend)   + '</td>' +
            '<td class="num">' + ZK.Format.money(totRevenue) + '</td>' +
            '<td class="num ' + totRoiClass + '">' + (totRoi !== '—' ? totRoi + '%' : '—') + '</td>' +
            '<td class="num">' + totRoas + '</td>' +
            '<td class="num">' + (totCpl !== '—' ? ZK.Format.money(totCpl) : '—') + '</td>' +
            '<td class="num">' + (totCpa !== '—' ? ZK.Format.money(totCpa) : '—') + '</td>' +
            '<td class="num">' + totLeads + '</td>' +
            '<td class="num">' + totPays  + '</td>' +
            '</tr></tfoot>';
    }

    // ----------------------------------------------------------------
    // Chart
    // ----------------------------------------------------------------
    function renderChart(items) {
        if (!items || !items.length) {
            var $c = $('#expenses-chart-container');
            $c.html('');
            chartInstance = null;
            return;
        }

        var $container = $('#expenses-chart-container');

        // Ensure canvas exists
        if (!$container.find('canvas').length) {
            $container.html('<canvas id="expenses-chart" width="400" height="400"></canvas>');
        }

        // Destroy previous instance
        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }

        // Filter rows that have non-zero spend
        var chartItems = [];
        for (var i = 0; i < items.length; i++) {
            var sp = parseFloat(items[i].spend) || 0;
            if (sp > 0) chartItems.push(items[i]);
        }

        if (!chartItems.length) {
            $container.html('');
            return;
        }

        var labels = [];
        var values = [];
        for (var j = 0; j < chartItems.length; j++) {
            labels.push(chartItems[j].utm_source || chartItems[j].source || 'Невiдомо');
            values.push(parseFloat(chartItems[j].spend) || 0);
        }

        // Delay to avoid View Transition + canvas race condition
        setTimeout(function() {
            var canvas = document.getElementById('expenses-chart');
            if (!canvas) return;

            chartInstance = new Chart(canvas, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: CHART_COLORS.slice(0, values.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 10,
                                color: getCssVar('--text-primary', '#e0e0e0')
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var val = ctx.parsed || 0;
                                    return ' ' + ctx.label + ': ' + ZK.Format.money(val);
                                }
                            }
                        }
                    }
                }
            });
        }, 100);
    }

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

    return {
        load: load
    };

})();
