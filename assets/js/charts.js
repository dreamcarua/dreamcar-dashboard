// === charts.js ===
// Логика графиков для UTM Dashboard с использованием Chart.js

(function($) {
    'use strict';

    // ==========================================
    // ЦВЕТА ТЕМЫ (возвращаются динамически из CSS переменных)
    // ==========================================

    function themeColors() {
        var isLight = document.documentElement.classList.contains('light-theme');
        if (isLight) {
            return {
                textPrimary: '#111827',
                textMuted:   '#6b7280',
                tooltipBg:   '#ffffff',
                cardBg:      '#ffffff',
                borderColor: '#e5e7eb'
            };
        }
        return {
            textPrimary: '#fafafa',
            textMuted:   '#a3a3a3',
            tooltipBg:   '#171717',
            cardBg:      '#0a0a0a',
            borderColor: '#262626'
        };
    }

    // ==========================================
    // ОБНОВЛЕНИЕ ГРАФИКОВ
    // ==========================================

    window.updateCharts = function(data, stats) {
        // Сохраним последние данные для перерисовки при смене темы
        window.UTMDashboard._lastChartData = data;
        window.UTMDashboard._lastChartStats = stats;

        updateLeadsTimelineChart(stats.by_date || {});
        updateAmountTimelineChart(stats.by_date || {}, data);
        updateLeadsDistributionChart(stats.sources || {});
        updateSourcesDistributionChart(stats.sources || {}, stats.amount_by_source || {});
        updateSourcesChart(stats.sources || {}, stats.amount_by_source || {});
        updateMediumChart(stats.medium || {});
    };

    // При смене темы - перерисовать графики с новыми цветами
    window.addEventListener('zk:theme-change', function() {
        if (window.UTMDashboard && window.UTMDashboard._lastChartData && window.UTMDashboard._lastChartStats) {
            window.updateCharts(window.UTMDashboard._lastChartData, window.UTMDashboard._lastChartStats);
        }
    });

    // ==========================================
    // ГРАФИК ЛИДОВ ПО ВРЕМЕНИ
    // ==========================================

    function updateLeadsTimelineChart(byDate) {
        const ctx = document.getElementById('leadsTimelineChart');
        const emptyState = document.getElementById('leadsTimelineChartEmpty');
        const titleElement = document.getElementById('leadsTimelineTitle');
        if (!ctx) return;

        if (window.UTMDashboard.charts.leadsTimeline) {
            window.UTMDashboard.charts.leadsTimeline.destroy();
        }

        const dates = Object.keys(byDate).sort();
        // Извлечь количество лидов из объектов {leads: X, revenue: Y}
        const values = dates.map(date => {
            const item = byDate[date];
            return typeof item === 'object' ? item.leads : item;
        });

        // Определить: если все даты за один день - показать "по часам"
        const isOneDayPeriod = dates.length > 0 && dates.every(d => d.substring(0, 10) === dates[0].substring(0, 10));
        if (titleElement) {
            titleElement.textContent = isOneDayPeriod ? '📊 Лиды по часам' : '📊 Лиды по дням';
        }

        if (dates.length === 0 || values.every(v => v === 0)) {
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';

        // При почасовой детализации показываем короткие метки (только часы)
        const labels = isOneDayPeriod
            ? dates.map(d => d.length > 10 ? d.substring(11, 16) : d)
            : dates;

        window.UTMDashboard.charts.leadsTimeline = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Лиды',
                    data: values,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: themeColors().tooltipBg,
                        titleColor: themeColors().textPrimary,
                        bodyColor: themeColors().textMuted,
                        borderColor: themeColors().borderColor,
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: { color: themeColors().borderColor },
                        ticks: { color: themeColors().textMuted }
                    },
                    y: {
                        grid: { color: themeColors().borderColor },
                        ticks: { color: themeColors().textMuted }
                    }
                }
            }
        });
    }

    // ==========================================
    // ГРАФИК ДЕНЕГ ПО ВРЕМЕНИ
    // ==========================================

    function updateAmountTimelineChart(byDate, data) {
        const ctx = document.getElementById('amountTimelineChart');
        const emptyState = document.getElementById('amountTimelineChartEmpty');
        const titleElement = document.getElementById('amountTimelineTitle');
        if (!ctx) return;

        if (window.UTMDashboard.charts.amountTimeline) {
            window.UTMDashboard.charts.amountTimeline.destroy();
        }

        const dates = Object.keys(byDate).sort();
        // Извлечь revenue из объектов {leads: X, revenue: Y}
        const values = dates.map(date => {
            const item = byDate[date];
            return typeof item === 'object' ? item.revenue : item;
        });

        // Определить: если все даты за один день - показать "по часам"
        const isOneDayPeriod = dates.length > 0 && dates.every(d => d.substring(0, 10) === dates[0].substring(0, 10));
        if (titleElement) {
            titleElement.textContent = isOneDayPeriod ? '💰 Деньги по часам' : '💰 Деньги по дням';
        }

        if (dates.length === 0 || values.every(v => v === 0)) {
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';

        // При почасовой детализации показываем короткие метки (только часы)
        const labels = isOneDayPeriod
            ? dates.map(d => d.length > 10 ? d.substring(11, 16) : d)
            : dates;

        window.UTMDashboard.charts.amountTimeline = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Сумма (UAH) - только оплаты',
                    data: values,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: themeColors().tooltipBg,
                        titleColor: themeColors().textPrimary,
                        bodyColor: themeColors().textMuted,
                        borderColor: themeColors().borderColor,
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                return Math.round(context.parsed.y).toLocaleString() + ' UAH';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: themeColors().borderColor },
                        ticks: { color: themeColors().textMuted }
                    },
                    y: {
                        grid: { color: themeColors().borderColor },
                        ticks: {
                            color: themeColors().textMuted,
                            callback: function(value) {
                                return value.toLocaleString() + ' UAH';
                            }
                        }
                    }
                }
            }
        });
    }

    // ==========================================
    // РАСПРЕДЕЛЕНИЕ ЛИДОВ ПО ИСТОЧНИКАМ (ПОНЧИК)
    // ==========================================

    function updateLeadsDistributionChart(sources) {
        const ctx = document.getElementById('leadsDistributionChart');
        const emptyState = document.getElementById('leadsDistributionChartEmpty');
        if (!ctx) return;

        if (window.UTMDashboard.charts.leadsDistribution) {
            window.UTMDashboard.charts.leadsDistribution.destroy();
        }

        const sortedSources = Object.entries(sources)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 10);

        if (sortedSources.length === 0) {
            if (emptyState) emptyState.style.display = 'block';
            return;
        }
        
        if (emptyState) emptyState.style.display = 'none';

        const labels = sortedSources.map(([source]) => source || 'Не указано');
        const values = sortedSources.map(([, count]) => count);

        const colors = [
            '#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444',
            '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#6366f1'
        ];

        window.UTMDashboard.charts.leadsDistribution = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: themeColors().cardBg,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: themeColors().textPrimary,
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: themeColors().tooltipBg,
                        titleColor: themeColors().textPrimary,
                        bodyColor: themeColors().textMuted,
                        borderColor: themeColors().borderColor,
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                return label + ': ' + value + ' лидов';
                            }
                        }
                    }
                }
            }
        });
    }

    // ==========================================
    // РАСПРЕДЕЛЕНИЕ СУММ ПО ИСТОЧНИКАМ (ПОНЧИК)
    // ==========================================

    function updateSourcesDistributionChart(sources, amountBySource) {
        const ctx = document.getElementById('sourcesDistributionChart');
        const emptyState = document.getElementById('sourcesDistributionChartEmpty');
        if (!ctx) return;

        // Уничтожить предыдущий график
        if (window.UTMDashboard.charts.sourcesDistribution) {
            window.UTMDashboard.charts.sourcesDistribution.destroy();
        }

        // Подготовить данные (ТОП-10 по суммам)
        const sortedSources = Object.entries(amountBySource)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 10);
            
        if (sortedSources.length === 0) {
            if (emptyState) emptyState.style.display = 'block';
            return;
        }
        
        if (emptyState) emptyState.style.display = 'none';

        const labels = sortedSources.map(([source]) => source || 'Не указано');
        const values = sortedSources.map(([, amount]) => amount);

        // Цвета для графика
        const colors = [
            '#3b82f6',
            '#8b5cf6',
            '#10b981',
            '#f59e0b',
            '#ef4444',
            '#06b6d4',
            '#ec4899',
            '#84cc16',
            '#f97316',
            '#6366f1'
        ];

        // Создать график
        window.UTMDashboard.charts.sourcesDistribution = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: themeColors().cardBg,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: themeColors().textPrimary,
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: themeColors().tooltipBg,
                        titleColor: themeColors().textPrimary,
                        bodyColor: themeColors().textMuted,
                        borderColor: themeColors().borderColor,
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                return label + ': $' + Math.round(value).toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }

    // ==========================================
    // ГРАФИК ИСТОЧНИКОВ (БАР)
    // ==========================================

    function updateSourcesChart(sources, amountBySource) {
        const ctx = document.getElementById('sourcesChart');
        const emptyState = document.getElementById('sourcesChartEmpty');
        if (!ctx) return;

        // Уничтожить предыдущий график
        if (window.UTMDashboard.charts.sources) {
            window.UTMDashboard.charts.sources.destroy();
        }

        // Подготовить данные (ТОП-15)
        const sortedSources = Object.entries(sources)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 15);
            
        if (sortedSources.length === 0) {
            if (emptyState) emptyState.style.display = 'block';
            return;
        }
        
        if (emptyState) emptyState.style.display = 'none';

        const labels = sortedSources.map(([source]) => source || 'Не указано');
        const leadsData = sortedSources.map(([, count]) => count);
        const amountData = sortedSources.map(([source]) => amountBySource[source] || 0);

        // Создать график
        window.UTMDashboard.charts.sources = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Количество лидов',
                        data: leadsData,
                        backgroundColor: 'rgba(59, 130, 246, 0.5)',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        xAxisID: 'x'
                    },
                    {
                        label: 'Сумма ($)',
                        data: amountData,
                        backgroundColor: 'rgba(16, 185, 129, 0.5)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        xAxisID: 'x1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: themeColors().textPrimary
                        }
                    },
                    tooltip: {
                        backgroundColor: themeColors().tooltipBg,
                        titleColor: themeColors().textPrimary,
                        bodyColor: themeColors().textMuted,
                        borderColor: themeColors().borderColor,
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 1) {
                                    label += '$' + Math.round(context.parsed.x).toLocaleString();
                                } else {
                                    label += context.parsed.x;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        position: 'bottom',
                        grid: {
                            color: themeColors().borderColor
                        },
                        ticks: {
                            color: themeColors().textMuted
                        },
                        title: {
                            display: true,
                            text: 'Лиды',
                            color: '#3b82f6'
                        }
                    },
                    x1: {
                        type: 'linear',
                        position: 'top',
                        grid: {
                            drawOnChartArea: false
                        },
                        ticks: {
                            color: themeColors().textMuted,
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        },
                        title: {
                            display: true,
                            text: 'Сумма ($)',
                            color: '#10b981'
                        }
                    },
                    y: {
                        grid: {
                            color: themeColors().borderColor
                        },
                        ticks: {
                            color: themeColors().textMuted
                        }
                    }
                }
            }
        });
    }

    // ==========================================
    // ГРАФИК MEDIUM (ПИРОГ)
    // ==========================================

    function updateMediumChart(medium) {
        const ctx = document.getElementById('mediumChart');
        const emptyState = document.getElementById('mediumChartEmpty');
        if (!ctx) return;

        // Уничтожить предыдущий график
        if (window.UTMDashboard.charts.medium) {
            window.UTMDashboard.charts.medium.destroy();
        }

        // Подготовить данные
        const sortedMedium = Object.entries(medium)
            .sort((a, b) => b[1] - a[1]);
            
        if (sortedMedium.length === 0) {
            if (emptyState) emptyState.style.display = 'block';
            return;
        }
        
        if (emptyState) emptyState.style.display = 'none';

        const labels = sortedMedium.map(([type]) => type || 'Не указано');
        const values = sortedMedium.map(([, count]) => count);

        // Цвета
        const colors = [
            '#8b5cf6',
            '#3b82f6',
            '#10b981',
            '#f59e0b',
            '#ef4444',
            '#06b6d4'
        ];

        // Создать график
        window.UTMDashboard.charts.medium = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: themeColors().cardBg,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: themeColors().textPrimary,
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: themeColors().tooltipBg,
                        titleColor: themeColors().textPrimary,
                        bodyColor: themeColors().textMuted,
                        borderColor: themeColors().borderColor,
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // ==========================================
    // ИНИЦИАЛИЗАЦИЯ
    // ==========================================

    $(document).ready(function() {
        console.log('📊 Система графиков инициализирована');
    });

})(jQuery);
