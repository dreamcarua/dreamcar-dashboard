// === date-filter.js ===
// Логика фильтрации по датам

(function($) {
    'use strict';

    // Глобальные переменные для фильтра
    // По умолчанию - вчера
    window.UTMDashboard.dateFilter = {
        range: 'yesterday',
        from: null,
        to: null
    };

    // Инициализация фильтра
    $(document).ready(function() {
        initDateFilter();
        initOtherFilters();
        // Восстановить фильтр из URL
        restoreFilterFromURL();
    });

    function initDateFilter() {
        // Обработчик изменения фильтра
        $('#dateRangeFilter').on('change', function() {
            const value = $(this).val();

            // Обработка проектных периодов
            if (value && value.startsWith('project_')) {
                var project = $('#modelFilter').val();
                var projectKey = (project || '').toUpperCase();
                var dates = window.PROJECT_DATES || {};

                if (dates[projectKey]) {
                    var pFrom = dates[projectKey]['date_from'];
                    var pTo = dates[projectKey]['date_to'];
                    var from, to;

                    if (value === 'project_full') {
                        from = pFrom;
                        to = pTo;
                    } else if (value === 'project_last_day') {
                        from = pTo;
                        to = pTo;
                    } else if (value === 'project_last_week') {
                        // последние 7 календарных дней проекта (включая дату окончания)
                        var lastWeek = new Date(pTo);
                        lastWeek.setDate(lastWeek.getDate() - 6);
                        var lwFrom = lastWeek < new Date(pFrom) ? pFrom : lastWeek.toISOString().split('T')[0];
                        from = lwFrom;
                        to = pTo;
                    } else if (value === 'project_first_week') {
                        // первые 7 календарных дней проекта (включая дату старта)
                        var firstWeek = new Date(pFrom);
                        firstWeek.setDate(firstWeek.getDate() + 6);
                        var fwTo = firstWeek > new Date(pTo) ? pTo : firstWeek.toISOString().split('T')[0];
                        from = pFrom;
                        to = fwTo;
                    } else if (value === 'project_first_3_days') {
                        // первые 3 календарных дня проекта (включая дату старта)
                        var first3 = new Date(pFrom);
                        first3.setDate(first3.getDate() + 2);
                        var f3To = first3 > new Date(pTo) ? pTo : first3.toISOString().split('T')[0];
                        from = pFrom;
                        to = f3To;
                    }

                    window.UTMDashboard.dateFilter.range = 'custom';
                    window.UTMDashboard.dateFilter.from = from;
                    window.UTMDashboard.dateFilter.to = to;

                    updateURL();
                    $(document).trigger('filtersChanged');
                    if (typeof window.UTMDashboard.loadData === 'function') {
                        window.UTMDashboard.loadData();
                    }
                }
                return;
            }

            if (value === 'custom') {
                // Открыть модальное окно для выбора периода
                openModal('customDateModal');

                // Установить текущие даты
                const today = new Date().toISOString().split('T')[0];
                const weekAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

                $('#dateFrom').val(weekAgo);
                $('#dateTo').val(today);
            } else {
                // Применить предустановленный период
                window.UTMDashboard.dateFilter.range = value;
                window.UTMDashboard.dateFilter.from = null;
                window.UTMDashboard.dateFilter.to = null;

                // Обновить URL
                updateURL();

                // Вызвать событие изменения фильтров
                $(document).trigger('filtersChanged');
                // Перезагрузить данные с сервера с новым фильтром
                if (typeof window.UTMDashboard.loadData === 'function') {
                    window.UTMDashboard.loadData();
                } else {
                    applyDateFilter();
                }
            }
        });

        // Применить кастомный период
        $('#applyCustomDateBtn').on('click', function() {
            const from = $('#dateFrom').val();
            const to = $('#dateTo').val();

            if (!from || !to) {
                if (typeof showNotification === 'function') {
                    showNotification('warning', 'Предупреждение', 'Выберите обе даты');
                }
                return;
            }

            if (new Date(from) > new Date(to)) {
                if (typeof showNotification === 'function') {
                    showNotification('error', 'Ошибка', 'Дата начала не может быть позже даты окончания');
                }
                return;
            }

            window.UTMDashboard.dateFilter.range = 'custom';
            window.UTMDashboard.dateFilter.from = from;
            window.UTMDashboard.dateFilter.to = to;

            // Обновить URL
            updateURL();

            closeModal('customDateModal');
            // Вызвать событие изменения фильтров
            $(document).trigger('filtersChanged');
            // Перезагрузить данные с сервера с новым фильтром
            if (typeof window.UTMDashboard.loadData === 'function') {
                window.UTMDashboard.loadData();
            } else {
                applyDateFilter();
            }
        });
    }

    function initOtherFilters() {
        // Обработчики для других фильтров
        $('#customerTypeFilter, #funnelTypeFilter').on('change', function() {
            updateURL();
        });
    }

    function applyDateFilter() {
        console.log('📅 Применяем фильтр:', window.UTMDashboard.dateFilter);

        if (!window.UTMDashboard.data || !window.UTMDashboard.data.length) {
            console.log('⚠️ Нет данных для фильтрации');
            return;
        }

        // Получить диапазон дат
        const { fromDate, toDate } = getDateRange();

        console.log('📆 Период:', fromDate, '-', toDate);

        // Фильтровать данные
        const filteredData = window.UTMDashboard.data.filter(item => {
            if (!item.created_at) return false;

            const itemDate = new Date(item.created_at);
            return itemDate >= fromDate && itemDate <= toDate;
        });

        console.log('✅ Отфильтровано записей:', filteredData.length);

        // Пересчитать статистику
        const stats = calculateStats(filteredData);

        // Обновить UI
        updateStats(stats);
        updateTables(filteredData, stats);
        
        // Обновить графики если функция доступна
        if (typeof window.updateCharts === 'function') {
            window.updateCharts(filteredData, stats);
        } else {
            console.warn('⚠️ updateCharts не определена, графики не обновлены');
        }

        if (typeof showNotification === 'function') {
            const msg = window.UTMDashboard.dateFilter.range === 'custom'
                ? `С ${window.UTMDashboard.dateFilter.from} по ${window.UTMDashboard.dateFilter.to}`
                : getRangeName();

            showNotification('success', 'Фильтр применён', `${msg}: ${filteredData.length} лидов`);
        }
    }

    function getDateRange() {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        let fromDate, toDate;

        const range = window.UTMDashboard.dateFilter.range;

        switch (range) {
            case 'today':
                fromDate = today;
                toDate = new Date(today.getTime() + 24 * 60 * 60 * 1000);
                break;

            case 'yesterday':
                fromDate = new Date(today.getTime() - 24 * 60 * 60 * 1000);
                toDate = today;
                break;

            case '7days':
                fromDate = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
                toDate = new Date(today.getTime() + 24 * 60 * 60 * 1000);
                break;

            case '30days':
                fromDate = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
                toDate = new Date(today.getTime() + 24 * 60 * 60 * 1000);
                break;

            case '60days':
                fromDate = new Date(today.getTime() - 60 * 24 * 60 * 60 * 1000);
                toDate = new Date(today.getTime() + 24 * 60 * 60 * 1000);
                break;

            case 'custom':
                fromDate = new Date(window.UTMDashboard.dateFilter.from);
                toDate = new Date(window.UTMDashboard.dateFilter.to);
                toDate.setHours(23, 59, 59, 999);
                break;

            case 'all':
            default:
                fromDate = new Date('2000-01-01');
                toDate = new Date('2099-12-31');
                break;
        }

        return { fromDate, toDate };
    }

    function getRangeName() {
        const names = {
            'all': 'Весь период',
            'today': 'Сегодня',
            'yesterday': 'Вчера',
            '7days': 'Последние 7 дней',
            '30days': 'Последние 30 дней',
            '60days': 'Последние 60 дней',
            'project_full': 'Весь проект',
            'project_last_day': 'Последний день проекта',
            'project_last_week': 'Последняя неделя проекта',
            'project_first_week': 'Первая неделя проекта',
            'project_first_3_days': 'Первые 3 дня проекта'
        };

        return names[window.UTMDashboard.dateFilter.range] || 'Период';
    }

    function calculateStats(data) {
        const stats = {
            total_leads: data.length,
            sources: {},
            medium: {},
            campaigns: {},
            terms: {},
            content: {},
            by_date: {}
        };

        data.forEach(item => {
            // Источники
            const source = item.utm_source || 'unknown';
            stats.sources[source] = (stats.sources[source] || 0) + 1;

            // Medium
            const medium = item.utm_medium || 'unknown';
            stats.medium[medium] = (stats.medium[medium] || 0) + 1;

            // Campaigns
            const campaign = item.utm_campaign || 'unknown';
            stats.campaigns[campaign] = (stats.campaigns[campaign] || 0) + 1;

            // Terms
            if (item.utm_term) {
                stats.terms[item.utm_term] = (stats.terms[item.utm_term] || 0) + 1;
            }

            // Content
            if (item.utm_content) {
                stats.content[item.utm_content] = (stats.content[item.utm_content] || 0) + 1;
            }

            // По дате
            if (item.created_at) {
                const date = item.created_at.split(' ')[0];
                stats.by_date[date] = (stats.by_date[date] || 0) + 1;
            }
        });

        return stats;
    }

    // ==========================================
    // РАБОТА С URL ПАРАМЕТРАМИ
    // ==========================================

    function updateURL() {
        const params = new URLSearchParams(window.location.search);

        // Обновить параметры фильтра по датам
        params.set('date_range', window.UTMDashboard.dateFilter.range);

        if (window.UTMDashboard.dateFilter.range === 'custom') {
            params.set('date_from', window.UTMDashboard.dateFilter.from);
            params.set('date_to', window.UTMDashboard.dateFilter.to);
        } else {
            params.delete('date_from');
            params.delete('date_to');
        }

        // Обновить параметры других фильтров
        const customerType = $('#customerTypeFilter').val();
        const funnelType = $('#funnelTypeFilter').val();

        if (customerType && customerType !== 'all') {
            params.set('customer_type', customerType);
        } else {
            params.delete('customer_type');
        }

        if (funnelType && funnelType !== 'all') {
            params.set('funnel_type', funnelType);
        } else {
            params.delete('funnel_type');
        }

        // UTM фильтры
        const utmSource = $('#utmSourceFilter').val()?.trim();
        const utmMedium = $('#utmMediumFilter').val()?.trim();
        const utmCampaign = $('#utmCampaignFilter').val()?.trim();
        const utmTerm = $('#utmTermFilter').val()?.trim();
        const utmContent = $('#utmContentFilter').val()?.trim();

        if (utmSource) {
            params.set('utm_source', utmSource);
        } else {
            params.delete('utm_source');
        }

        if (utmMedium) {
            params.set('utm_medium', utmMedium);
        } else {
            params.delete('utm_medium');
        }

        if (utmCampaign) {
            params.set('utm_campaign', utmCampaign);
        } else {
            params.delete('utm_campaign');
        }

        if (utmTerm) {
            params.set('utm_term', utmTerm);
        } else {
            params.delete('utm_term');
        }

        if (utmContent) {
            params.set('utm_content', utmContent);
        } else {
            params.delete('utm_content');
        }

        // Обновить URL без перезагрузки страницы
        const newURL = window.location.pathname + '?' + params.toString() + window.location.hash;
        window.history.pushState({}, '', newURL);

        console.log('🔗 URL обновлён:', newURL);
    }

    function restoreFilterFromURL() {
        const params = new URLSearchParams(window.location.search);

        const dateRange = params.get('date_range');
        const dateFrom = params.get('date_from');
        const dateTo = params.get('date_to');
        const customerType = params.get('customer_type');
        const funnelType = params.get('funnel_type');

        if (dateRange) {
            console.log('📥 Восстанавливаем фильтр из URL:', dateRange);

            if (dateRange === 'custom' && (!dateFrom || !dateTo)) {
                // Custom без дат - сброс на yesterday
                console.log('📥 Custom без дат - сброс на yesterday');
                window.UTMDashboard.dateFilter.range = 'yesterday';
                window.UTMDashboard.dateFilter.from = null;
                window.UTMDashboard.dateFilter.to = null;
                $('#dateRangeFilter').val('yesterday');
            } else if (dateRange.startsWith('project_')) {
                // Проектный период - убедиться что опции созданы, затем выбрать и применить
                if (typeof window.UTMDashboard.refreshProjectDateOptions === 'function') {
                    window.UTMDashboard.refreshProjectDateOptions(false);
                }
                if ($('#dateRangeFilter option[value="' + dateRange + '"]').length) {
                    $('#dateRangeFilter').val(dateRange).trigger('change');
                } else {
                    // Опция недоступна (проект не выбран / нет дат) - откат на yesterday
                    window.UTMDashboard.dateFilter.range = 'yesterday';
                    window.UTMDashboard.dateFilter.from = null;
                    window.UTMDashboard.dateFilter.to = null;
                    $('#dateRangeFilter').val('yesterday');
                }
            } else {
                window.UTMDashboard.dateFilter.range = dateRange;

                if (dateRange === 'custom' && dateFrom && dateTo) {
                    window.UTMDashboard.dateFilter.from = dateFrom;
                    window.UTMDashboard.dateFilter.to = dateTo;
                }

                // Установить значение в select
                $('#dateRangeFilter').val(dateRange);
            }

            console.log('✅ Фильтр восстановлен:', window.UTMDashboard.dateFilter);
        } else {
            // Если в URL нет параметров - установить "вчера" по умолчанию
            console.log('📥 URL пустой, устанавливаем фильтр по умолчанию: yesterday');
            window.UTMDashboard.dateFilter.range = 'yesterday';
            $('#dateRangeFilter').val('yesterday');
        }

        // Восстановить другие фильтры
        if (customerType) {
            $('#customerTypeFilter').val(customerType);
            console.log('📥 Восстановлен фильтр клиентов:', customerType);
        }

        if (funnelType) {
            $('#funnelTypeFilter').val(funnelType);
            console.log('📥 Восстановлен фильтр воронки:', funnelType);
        }

        // Восстановить UTM фильтры
        const utmSource = params.get('utm_source');
        const utmMedium = params.get('utm_medium');
        const utmCampaign = params.get('utm_campaign');
        const utmTerm = params.get('utm_term');
        const utmContent = params.get('utm_content');

        if (utmSource) {
            $('#utmSourceFilter').val(utmSource);
            console.log('📥 Восстановлен utm_source:', utmSource);
        }
        if (utmMedium) {
            $('#utmMediumFilter').val(utmMedium);
            console.log('📥 Восстановлен utm_medium:', utmMedium);
        }
        if (utmCampaign) {
            $('#utmCampaignFilter').val(utmCampaign);
            console.log('📥 Восстановлен utm_campaign:', utmCampaign);
        }
        if (utmTerm) {
            $('#utmTermFilter').val(utmTerm);
            console.log('📥 Восстановлен utm_term:', utmTerm);
        }
        if (utmContent) {
            $('#utmContentFilter').val(utmContent);
            console.log('📥 Восстановлен utm_content:', utmContent);
        }
    }

    // Сделать функцию доступной глобально
    window.applyDateFilter = applyDateFilter;
    window.updateDateFilterURL = updateURL;

    console.log('📅 Фильтр по датам инициализирован');

})(jQuery);
