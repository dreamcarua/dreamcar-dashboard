// === app.js ===
// Основная логика приложения UTM Dashboard

(function($) {
    'use strict';

    // ==========================================
    // ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ
    // ==========================================

    window.UTMDashboard = {
        data: null,
        stats: null,
        charts: {},
        currentSection: 'overview',
        tableSort: {}, // Хранит состояние сортировки для каждой таблицы
        tablePagination: {}, // Хранит состояние пагинации для каждой таблицы
        // Дані авторизації
        userRole: window.USER_ROLE || 'guest',
        userUtmTerm: window.USER_UTM_TERM || null,
        isGuest: window.IS_GUEST || false
    };

    // ==========================================
    // ИНИЦИАЛИЗАЦИЯ
    // ==========================================

    $(document).ready(function() {
        console.log('🚀 UTM Dashboard запущен');
        console.log('jQuery версия:', $.fn.jquery);

        // Инициализация компонентов
        initNavigation();
        initButtons();
        initTableSorting();
        initLeadDetails();

        // Заблокувати utm_term для не-адмінів
        lockUtmTermFilter();

        // Загрузить данные СРАЗУ
        console.log('▶️ Начинаем загрузку данных...');
        setTimeout(function() {
            loadData();
        }, 100);
    });

    // ==========================================
    // НАВИГАЦИЯ МЕЖДУ СЕКЦИЯМИ
    // ==========================================

    function initNavigation() {
        // Обработчик клика на кнопки навигации
        $('.nav-btn').on('click', function() {
            const section = $(this).data('section');
            switchToSection(section);
        });
        
        // Обработчик изменения хэша (кнопка "Назад/Вперед" в браузере)
        $(window).on('hashchange', function() {
            const hash = window.location.hash.replace('#', '');
            if (hash) {
                switchToSection(hash, false); // false = не обновлять URL (он уже правильный)
            }
        });
        
        // Проверить хэш при загрузке страницы
        const hash = window.location.hash.replace('#', '');
        if (hash) {
            // Есть хэш - открыть соответствующий раздел
            setTimeout(function() {
                switchToSection(hash, false); // false = не обновлять URL (он уже правильный)
            }, 100);
        }
    }
    
    function switchToSection(section, updateHash = true) {
        // Проверить что секция существует
        const sectionElement = $('#' + section + '-section');
        if (sectionElement.length === 0) {
            console.warn('Секция не найдена:', section);
            return;
        }

        // Обновить активную кнопку
        $('.nav-btn').removeClass('active');
        $('.nav-btn[data-section="' + section + '"]').addClass('active');

        // Показать нужную секцию
        $('.content-section').removeClass('active');
        sectionElement.addClass('active');

        window.UTMDashboard.currentSection = section;

        // Обновить URL с хэшем
        if (updateHash) {
            window.location.hash = section;
        }

        console.log('Переключено на секцию:', section);
        
        // Вызвать событие переключения секции
        $(document).trigger('sectionSwitched', [section]);
        
        // Загрузить данные для секции если нужно
        if (section === 'combinations' && window.UTMDashboard.stats) {
            const type = $('#combinationType').val() || 'source_medium';
            updateCombinationsTable(type);
        }
        
        // Загрузить таблицу сделок если переключились на секцию table
        if (section === 'table' && typeof window.loadDealsTable === 'function') {
            window.loadDealsTable();
        }
    }

    // ==========================================
    // ОБРАБОТЧИКИ КНОПОК
    // ==========================================

    function initButtons() {
        // Кнопка синхронизации
        $('#syncDataBtn').on('click', function() {
            syncData();
        });

        // Кнопка показа сырых данных
        $('#showRawDataBtn').on('click', function() {
            showRawData();
        });

        // Кнопка экспорта
        $('#exportOverviewBtn').on('click', function() {
            exportData();
        });

        // Кнопки обновления Term и Content
        $('#refreshTermBtn').on('click', function() {
            loadData();
        });

        $('#refreshContentBtn').on('click', function() {
            loadData();
        });

        // Поиск
        setupSearch();

        // Фильтры
        setupFilters();
        
        // Фильтры по типу клиентов и проекту
        $('#customerTypeFilter, #funnelTypeFilter, #tariffFilter, #payProviderFilter').on('change', function() {
            console.log('Фильтр изменен');
            highlightActiveFilters();
            $(document).trigger('filtersChanged');
            loadData();
        });

        // Отдельный обработчик для проекта - автопереключение периода
        $('#modelFilter').on('change', function() {
            var project = $(this).val();
            var dates = window.PROJECT_DATES || {};
            var projectKey = (project || '').toUpperCase();
            var $dateFilter = $('#dateRangeFilter');

            // Убрать старые опции проекта
            $dateFilter.find('.project-date-option').remove();

            if (project && project !== 'all' && dates[projectKey]) {
                var dateTo = new Date(dates[projectKey]['date_to']);
                var today = new Date();
                today.setHours(0,0,0,0);

                // Добавить опции проекта
                var fromLabel = dates[projectKey]['date_from'].split('-').reverse().join('.');
                var toLabel = dates[projectKey]['date_to'].split('-').reverse().join('.');
                $dateFilter.append('<option class="project-date-option" value="project_full">Весь проект (' + fromLabel + ' - ' + toLabel + ')</option>');
                $dateFilter.append('<option class="project-date-option" value="project_last_day">Последний день проекта</option>');
                $dateFilter.append('<option class="project-date-option" value="project_last_week">Последняя неделя проекта</option>');
                $dateFilter.append('<option class="project-date-option" value="project_first_week">Первая неделя проекта</option>');

                // Если проект завершен - автоматически "Весь проект"
                if (dateTo < today) {
                    $dateFilter.val('project_full').trigger('change');
                    return;
                } else {
                    // Текущий проект - сбросить период на "Вчера"
                    var curVal = $dateFilter.val();
                    if (curVal && curVal.startsWith('project_')) {
                        $dateFilter.val('yesterday');
                        window.UTMDashboard.dateFilter.range = 'yesterday';
                        window.UTMDashboard.dateFilter.from = null;
                        window.UTMDashboard.dateFilter.to = null;
                    }
                }
            } else {
                // "Все проекты" - сбросить период на "Вчера"
                var curVal = $dateFilter.val();
                if (curVal && curVal.startsWith('project_')) {
                    $dateFilter.val('yesterday');
                    window.UTMDashboard.dateFilter.range = 'yesterday';
                    window.UTMDashboard.dateFilter.from = null;
                    window.UTMDashboard.dateFilter.to = null;
                }
            }

            highlightActiveFilters();
            $(document).trigger('filtersChanged');
            loadData();
        });

        // Подсветка при загрузке
        highlightActiveFilters();

        // UTM фильтры
        $('#applyUtmFilters').on('click', function() {
            console.log('Применение UTM фильтров');
            // Обновить URL с UTM параметрами
            if (typeof window.updateDateFilterURL === 'function') {
                window.updateDateFilterURL();
            }
            $(document).trigger('filtersChanged');
            loadData();
        });

        $('#clearUtmFilters').on('click', function() {
            console.log('Сброс UTM фильтров');
            $('#utmSourceFilter, #utmMediumFilter, #utmCampaignFilter, #utmTermFilter, #utmContentFilter').val('');
            $('#tariffFilter, #payProviderFilter').val('all');
            highlightActiveFilters();
            // Обновить URL (убрать UTM параметры)
            if (typeof window.updateDateFilterURL === 'function') {
                window.updateDateFilterURL();
            }
            $(document).trigger('filtersChanged');
            loadData();
        });

        // Enter для применения UTM фильтров
        $('.filter-input').on('keypress', function(e) {
            if (e.which === 13) {
                console.log('Enter в UTM фильтре');
                // Обновить URL с UTM параметрами
                if (typeof window.updateDateFilterURL === 'function') {
                    window.updateDateFilterURL();
                }
                loadData();
            }
        });

        // Комбинации
        setupCombinations();
    }

    // ==========================================
    // ПОДСВЕТКА АКТИВНЫХ ФИЛЬТРОВ
    // ==========================================

    function highlightActiveFilters() {
        $('#customerTypeFilter, #funnelTypeFilter, #tariffFilter, #payProviderFilter').each(function() {
            var $wrapper = $(this).closest('.date-filter');
            if ($(this).val() && $(this).val() !== 'all') {
                $wrapper.addClass('filter-active');
            } else {
                $wrapper.removeClass('filter-active');
            }
        });
    }

    // ==========================================
    // ЗАГРУЗКА ДАННЫХ
    // ==========================================

    function loadData() {
        console.log('📡 loadData() вызвана');

        if (typeof showLoader === 'function') {
            showLoader();
        }

        // Получить параметры фильтра по датам
        const dateFilter = window.UTMDashboard.dateFilter || {};
        const modelFilter = $('#modelFilter').val() || 'VOLVO';
        const customerType = $('#customerTypeFilter').val() || 'all';
        const funnelType = $('#funnelTypeFilter').val() || 'all';
        const tariffFilter = $('#tariffFilter').val() || 'all';
        const payProviderFilter = $('#payProviderFilter').val() || 'all';

        const params = {
            date_range: dateFilter.range || 'all',
            model: modelFilter,
            customer_type: customerType,
            funnel_type: funnelType,
            tariff: tariffFilter,
            pay_provider: payProviderFilter
        };

        if (dateFilter.range === 'custom' && dateFilter.from && dateFilter.to) {
            params.date_from = dateFilter.from;
            params.date_to = dateFilter.to;
        }

        // UTM фильтры
        const utmSource = $('#utmSourceFilter').val()?.trim();
        const utmMedium = $('#utmMediumFilter').val()?.trim();
        const utmCampaign = $('#utmCampaignFilter').val()?.trim();
        const utmTerm = $('#utmTermFilter').val()?.trim();
        const utmContent = $('#utmContentFilter').val()?.trim();

        if (utmSource) params.utm_source = utmSource;
        if (utmMedium) params.utm_medium = utmMedium;
        if (utmCampaign) params.utm_campaign = utmCampaign;
        if (utmTerm) params.utm_term = utmTerm;
        if (utmContent) params.utm_content = utmContent;

        // Определить URL для API запроса
        const apiUrl = (typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : '') + 'api/test.php';
        
        console.log('🔗 API URL:', apiUrl);
        console.log('📋 Параметры:', params);
        
        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(response) {
                console.log('✅ Данные получены:', response);

                if (typeof hideLoader === 'function') {
                    hideLoader();
                }

                if (response.success) {
                    window.UTMDashboard.data = response.data || [];
                    window.UTMDashboard.stats = response.stats || {};
                    window.UTMDashboard.lastUpdate = new Date();

                    console.log('📊 Записей:', response.data.length);
                    console.log('📈 Статистика:', response.stats);

                    // Обновить индикатор последнего обновления
                    updateLastUpdateIndicator();

                    // Обновить UI
                    updateStats(response.stats);
                    updateTables(response.data, response.stats);
                    
                    // Обновить графики если функция доступна
                    if (typeof window.updateCharts === 'function') {
                        window.updateCharts(response.data, response.stats);
                    } else {
                        console.warn('⚠️ updateCharts не определена, графики не обновлены');
                    }
                    
                    // Обновить аналитику если секция активна
                    if (window.UTMDashboard.currentSection === 'analytics') {
                        updateAnalytics(response.stats);
                    }
                    
                    // Обновить комбинации если секция активна
                    if (window.UTMDashboard.currentSection === 'combinations') {
                        const type = $('#combinationType').val() || 'source_medium';
                        updateCombinationsTable(type);
                    }

                    if (typeof showNotification === 'function') {
                        const totalLeads = response.stats?.total_leads || 0;
                        showNotification('success', 'Данные загружены', 'Всего лидов: ' + totalLeads);
                    }
                } else {
                    console.error('❌ Ответ не успешен');
                    if (typeof showNotification === 'function') {
                        showNotification('error', 'Ошибка', response.message || 'Не удалось загрузить данные');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX ошибка:', { xhr, status, error });
                console.error('📄 Ответ сервера:', xhr.responseText);
                console.error('🔗 URL запроса:', apiUrl);
                
                // Если требуется авторизация - перенаправить на страницу входа
                if (xhr.status === 401) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.auth_required) {
                            window.location.href = (typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : '') + 'login.php';
                            return;
                        }
                    } catch (e) {
                        // Не JSON ответ
                    }
                }

                if (typeof hideLoader === 'function') {
                    hideLoader();
                }
                if (typeof showNotification === 'function') {
                    showNotification('error', 'Ошибка сети', 'Не удалось подключиться к серверу');
                }
            }
        });
    }

    // ==========================================
    // СИНХРОНИЗАЦИЯ ДАННЫХ
    // ==========================================

    function syncData() {
        showLoader('Синхронизация с SendPulse...');

        $.ajax({
            url: 'api/handler.php',
            method: 'POST',
            data: { action: 'sync' },
            dataType: 'json',
            success: function(response) {
                hideLoader();

                if (response.success) {
                    showNotification('success', 'Успешно', 'Данные синхронизированы');
                    loadData();
                } else {
                    showNotification('error', 'Ошибка', response.message || 'Не удалось синхронизировать');
                }
            },
            error: function() {
                hideLoader();
                showNotification('error', 'Ошибка', 'Не удалось выполнить синхронизацию');
            }
        });
    }

    // ==========================================
    // ОБНОВЛЕНИЕ СТАТИСТИКИ
    // ==========================================

    function updateStats(stats) {
        $('#totalLeads').text(stats.total_leads || 0);
        $('#totalPaid').text(stats.paid_count || 0);
        $('#totalSources').text(Object.keys(stats.sources || {}).length);
        $('#totalCampaigns').text(Object.keys(stats.campaigns || {}).length);

        // Форматировать суммы
        // total_amount теперь содержит только оплаченные сделки (заработанные деньги)
        const totalAmount = stats.total_amount || 0; // Это заработанные деньги
        const avgAmount = stats.avg_amount || 0; // Средний чек по оплаченным
        $('#totalAmount').text(formatNumber(totalAmount) + ' UAH');
        $('#avgAmount').text(formatNumber(avgAmount) + ' UAH');

        // НОВЫЕ МЕТРИКИ: рекламные расходы, прибыль, ROI, ROAS, CPL, CPA
        const totalAdsSpend = stats.total_ads_spend || 0;
        const totalProfit = stats.total_profit || 0;
        const totalROI = stats.total_roi || 0;
        const totalROAS = stats.total_roas || 0;
        const avgCPL = stats.avg_cpl || 0;
        const avgCPA = stats.avg_cpa || 0;

        $('#totalAdsSpend').text(formatNumber(totalAdsSpend) + ' UAH');

        // Прибыль с цветом (зеленый если положительная, красный если отрицательная)
        const profitFormatted = totalProfit >= 0 ? '+' + formatNumber(totalProfit) : formatNumber(totalProfit);
        $('#totalProfit').html(`<span style="color: ${totalProfit >= 0 ? 'var(--ai-green)' : 'var(--color-danger)'};">${profitFormatted} UAH</span>`);

        // ROI с цветом
        const roiFormatted = totalROI >= 0 ? '+' + totalROI.toFixed(1) : totalROI.toFixed(1);
        $('#totalROI').html(`<span style="color: ${totalROI >= 0 ? 'var(--ai-green)' : 'var(--color-danger)'};">${roiFormatted}%</span>`);

        // ROAS
        $('#totalROAS').text(totalROAS.toFixed(2));

        // CPL и CPA
        $('#avgCPL').text(formatNumber(avgCPL) + ' UAH');
        $('#avgCPA').text(formatNumber(avgCPA) + ' UAH');

        // Обновить аналитику
        updateAnalytics(stats);
    }
    
    function updateAnalytics(stats) {
        // Статистика по типам
        $('#analyticsLeads').text(stats.total_leads || 0);
        $('#analyticsPaid').text(stats.paid_count || 0);
        $('#analyticsFailed').text(stats.failed_count || 0);
        $('#analyticsPending').text(stats.pending_count || 0);
        $('#analyticsPaidAmount').text(formatNumber(stats.paid_amount || 0) + ' UAH');
        // Потеряно = только неуспешные (failed), БЕЗ "в процессе" (pending)
        $('#analyticsLostAmount').text(formatNumber(stats.failed_amount || 0) + ' UAH');
        
        // Таблица аналитики по источникам
        updateAnalyticsSourcesTable(stats.sources_analytics || {});
    }
    
    function updateAnalyticsSourcesTable(analytics) {
        const tableId = 'analyticsSourcesTable';
        const tbody = $('#' + tableId + ' tbody');
        const emptyState = $('#' + tableId + 'Empty');
        const pagination = $('#' + tableId + 'Pagination');
        tbody.empty();

        const sortedSources = Object.entries(analytics)
            .sort((a, b) => (b[1].total_amount || 0) - (a[1].total_amount || 0));

        if (sortedSources.length === 0) {
            emptyState.show();
            pagination.hide();
            return;
        }
        
        emptyState.hide();
        
        if (!window.UTMDashboard.tablePagination[tableId]) {
            window.UTMDashboard.tablePagination[tableId] = { page: 1, perPage: 20 };
        }
        
        const paginationState = window.UTMDashboard.tablePagination[tableId];
        const startIndex = (paginationState.page - 1) * paginationState.perPage;
        const endIndex = startIndex + paginationState.perPage;
        const paginatedSources = sortedSources.slice(startIndex, endIndex);

        paginatedSources.forEach(([source, data]) => {
            const leads = data.leads || 0;
            const paid = data.paid || 0;
            const failed = data.failed || 0;
            const pending = data.pending || 0;
            const paidAmount = data.paid_amount || 0;
            const adsSpend = data.ads_spend || 0;
            const dataType = data.data_type || 'common';

            // Прибыль = заработано - расходы на рекламу
            const profit = data.profit || 0;
            const profitFormatted = profit >= 0 ? '+' + formatNumber(profit) : formatNumber(profit);
            const profitClass = profit > 0 ? 'trend-up' : (profit < 0 ? 'trend-down' : 'trend-stable');

            // ROI
            const roi = data.roi || 0;
            const roiFormatted = roi >= 0 ? '+' + roi.toFixed(1) + '%' : roi.toFixed(1) + '%';
            const roiClass = roi > 0 ? 'trend-up' : (roi < 0 ? 'trend-down' : 'trend-stable');

            // ROAS
            const roas = data.roas || 0;
            const roasFormatted = roas.toFixed(2);

            // CPL
            const cpl = data.cpl || 0;
            const cplFormatted = formatNumber(cpl);

            // CPA
            const cpa = data.cpa || 0;
            const cpaFormatted = formatNumber(cpa);

            // Конверсия
            const conversion = data.conversion_rate || 0;
            const conversionFormatted = conversion.toFixed(1) + '%';
            const conversionClass = conversion > 10 ? 'trend-up' : (conversion > 5 ? 'trend-stable' : 'trend-down');

            const row = `
                <tr data-type="${dataType}">
                    <td><strong>${source || 'Не указано'}</strong> ${getDataTypeBadge(dataType)}</td>
                    <td>${leads}</td>
                    <td><span class="badge badge-success">${paid}</span></td>
                    <td><span class="badge badge-danger">${failed}</span></td>
                    <td><span class="badge badge-warning">${pending}</span></td>
                    <td style="color: var(--ai-green); font-weight: 600;">${formatNumber(paidAmount)} UAH</td>
                    <td style="color: var(--color-warning); font-weight: 600;">${formatNumber(adsSpend)} UAH</td>
                    <td><span class="${profitClass}">${profitFormatted} UAH</span></td>
                    <td><span class="${roiClass}">${roiFormatted}</span></td>
                    <td>${roasFormatted}</td>
                    <td>${cplFormatted} UAH</td>
                    <td>${cpaFormatted} UAH</td>
                    <td><span class="${conversionClass}">${conversionFormatted}</span></td>
                </tr>
            `;

            tbody.append(row);
        });
        
        if (sortedSources.length > paginationState.perPage) {
            renderPagination(tableId, sortedSources.length, paginationState.page, paginationState.perPage);
            pagination.show();
        } else {
            pagination.hide();
        }
    }

    // Форматировать число с разделителями
    function formatNumber(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    // ==========================================
    // ОБНОВЛЕНИЕ ТАБЛИЦ
    // ==========================================

    function updateTables(data, stats) {
        // Таблица источников
        updateSourcesTable(stats.sources || {}, stats.total_leads);

        // Таблица medium
        updateMediumTable(stats.medium || {}, stats.total_leads);

        // Таблица кампаний
        updateCampaignsTable(data, stats.campaigns || {});

        // Таблица term
        updateTermTable(stats.terms || {}, stats.total_leads);

        // Таблица content
        updateContentTable(stats.content || {}, stats.total_leads);
    }

    // ==========================================
    // ТАБЛИЦА ИСТОЧНИКОВ
    // ==========================================

    function updateSourcesTable(sources, total) {
        const tableId = 'sourcesTable';
        const tbody = $('#' + tableId + ' tbody');
        const emptyState = $('#' + tableId + 'Empty');
        const pagination = $('#' + tableId + 'Pagination');
        tbody.empty();

        const sortedSources = Object.entries(sources)
            .sort((a, b) => b[1] - a[1]);

        if (sortedSources.length === 0) {
            emptyState.show();
            pagination.hide();
            return;
        }
        
        emptyState.hide();

        const amountBySource = window.UTMDashboard.stats?.amount_by_source || {};
        const trends = window.UTMDashboard.stats?.trends || {};
        
        // Инициализировать пагинацию если нужно
        if (!window.UTMDashboard.tablePagination[tableId]) {
            window.UTMDashboard.tablePagination[tableId] = { page: 1, perPage: 20 };
        }
        
        const paginationState = window.UTMDashboard.tablePagination[tableId];
        const startIndex = (paginationState.page - 1) * paginationState.perPage;
        const endIndex = startIndex + paginationState.perPage;
        const paginatedSources = sortedSources.slice(startIndex, endIndex);

        const sourcesAnalytics = window.UTMDashboard.stats?.sources_analytics || {};

        paginatedSources.forEach(([source, count]) => {
            const analytics = sourcesAnalytics[source] || {};
            const paid = analytics.paid || 0;
            const amount = analytics.paid_amount || 0;
            const adsSpend = analytics.ads_spend || 0;
            const profit = analytics.profit || 0;
            const roi = analytics.roi || 0;
            const roas = analytics.roas || 0;
            const cpl = analytics.cpl || 0;
            const cpa = analytics.cpa || 0;
            const conversion = analytics.conversion_rate || 0;
            const dataType = analytics.data_type || 'common';

            // Форматирование
            const profitFormatted = profit >= 0 ? '+' + formatNumber(profit) : formatNumber(profit);
            const profitClass = profit > 0 ? 'trend-up' : (profit < 0 ? 'trend-down' : 'trend-stable');
            const roiFormatted = roi >= 0 ? '+' + roi.toFixed(1) + '%' : roi.toFixed(1) + '%';
            const roiClass = roi > 0 ? 'trend-up' : (roi < 0 ? 'trend-down' : 'trend-stable');
            const conversionFormatted = conversion.toFixed(1) + '%';
            const conversionClass = conversion > 10 ? 'trend-up' : (conversion > 5 ? 'trend-stable' : 'trend-down');

            const row = `
                <tr data-type="${dataType}">
                    <td><strong>${source || 'Не указано'}</strong> ${getDataTypeBadge(dataType)}</td>
                    <td>${count}</td>
                    <td><span class="badge badge-success">${paid}</span></td>
                    <td style="color: var(--ai-green); font-weight: 600;">${formatNumber(amount)} UAH</td>
                    <td style="color: var(--color-warning);">${formatNumber(adsSpend)} UAH</td>
                    <td><span class="${profitClass}">${profitFormatted} UAH</span></td>
                    <td><span class="${roiClass}">${roiFormatted}</span></td>
                    <td>${roas.toFixed(2)}</td>
                    <td>${formatNumber(cpl)} UAH</td>
                    <td>${formatNumber(cpa)} UAH</td>
                    <td><span class="${conversionClass}">${conversionFormatted}</span></td>
                </tr>
            `;

            tbody.append(row);
        });
        
        // Показать пагинацию если записей больше чем perPage
        if (sortedSources.length > paginationState.perPage) {
            renderPagination(tableId, sortedSources.length, paginationState.page, paginationState.perPage);
            pagination.show();
        } else {
            pagination.hide();
        }
    }
    
    function renderPagination(tableId, totalItems, currentPage, perPage) {
        const totalPages = Math.ceil(totalItems / perPage);
        const pagination = $('#' + tableId + 'Pagination');
        
        const startItem = (currentPage - 1) * perPage + 1;
        const endItem = Math.min(currentPage * perPage, totalItems);
        
        let html = `
            <div class="pagination-info">
                Показано ${startItem}-${endItem} из ${totalItems}
            </div>
            <div class="pagination-controls">
                <button class="pagination-btn" data-page="1" ${currentPage === 1 ? 'disabled' : ''}>«</button>
                <button class="pagination-btn" data-page="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''}>‹</button>
        `;
        
        // Показать номера страниц
        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        
        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        if (startPage > 1) {
            html += `<button class="pagination-btn" data-page="1">1</button>`;
            if (startPage > 2) {
                html += `<span style="padding: 0 0.5rem; color: var(--text-muted);">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span style="padding: 0 0.5rem; color: var(--text-muted);">...</span>`;
            }
            html += `<button class="pagination-btn" data-page="${totalPages}">${totalPages}</button>`;
        }
        
        html += `
                <button class="pagination-btn" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''}>›</button>
                <button class="pagination-btn" data-page="${totalPages}" ${currentPage === totalPages ? 'disabled' : ''}>»</button>
                <select class="pagination-select" data-table="${tableId}">
                    <option value="20" ${perPage === 20 ? 'selected' : ''}>20</option>
                    <option value="50" ${perPage === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${perPage === 100 ? 'selected' : ''}>100</option>
                    <option value="9999" ${perPage >= 9999 ? 'selected' : ''}>Все</option>
                </select>
            </div>
        `;
        
        pagination.html(html);
        
        // Обработчики кликов
        pagination.find('.pagination-btn').on('click', function() {
            if ($(this).prop('disabled')) return;
            const page = parseInt($(this).data('page'));
            window.UTMDashboard.tablePagination[tableId].page = page;
            // Перезагрузить таблицу
            if (tableId === 'sourcesTable') {
                updateSourcesTable(window.UTMDashboard.stats?.sources || {}, window.UTMDashboard.stats?.total_leads || 0);
            } else if (tableId === 'mediumTable') {
                updateMediumTable(window.UTMDashboard.stats?.medium || {}, window.UTMDashboard.stats?.total_leads || 0);
            } else if (tableId === 'campaignsTable') {
                updateCampaignsTable(window.UTMDashboard.data || [], window.UTMDashboard.stats?.campaigns || {});
            } else if (tableId === 'termTable') {
                updateTermTable(window.UTMDashboard.stats?.terms || {}, window.UTMDashboard.stats?.total_leads || 0);
            } else if (tableId === 'contentTable') {
                updateContentTable(window.UTMDashboard.stats?.content || {}, window.UTMDashboard.stats?.total_leads || 0);
            }
        });
        
        pagination.find('.pagination-select').on('change', function() {
            const newPerPage = parseInt($(this).val());
            window.UTMDashboard.tablePagination[tableId].perPage = newPerPage;
            window.UTMDashboard.tablePagination[tableId].page = 1;
            // Перезагрузить таблицу
            if (tableId === 'sourcesTable') {
                updateSourcesTable(window.UTMDashboard.stats?.sources || {}, window.UTMDashboard.stats?.total_leads || 0);
            } else if (tableId === 'mediumTable') {
                updateMediumTable(window.UTMDashboard.stats?.medium || {}, window.UTMDashboard.stats?.total_leads || 0);
            } else if (tableId === 'campaignsTable') {
                updateCampaignsTable(window.UTMDashboard.data || [], window.UTMDashboard.stats?.campaigns || {});
            } else if (tableId === 'termTable') {
                updateTermTable(window.UTMDashboard.stats?.terms || {}, window.UTMDashboard.stats?.total_leads || 0);
            } else if (tableId === 'contentTable') {
                updateContentTable(window.UTMDashboard.stats?.content || {}, window.UTMDashboard.stats?.total_leads || 0);
            } else if (tableId === 'analyticsSourcesTable') {
                updateAnalyticsSourcesTable(window.UTMDashboard.stats?.sources_analytics || {});
            } else if (tableId === 'combinationsTable') {
                const type = $('#combinationType').val() || 'source_medium';
                updateCombinationsTable(type);
            }
        });
    }
    
    function getTrendForSource(source, trends) {
        if (!trends[source]) {
            return '<span class="trend-stable">➡️ 0%</span>';
        }
        
        const trend = trends[source];
        const value = Math.abs(trend.value);
        const sign = trend.value > 0 ? '+' : (trend.value < 0 ? '-' : '');
        
        let icon = '➡️';
        let className = 'trend-stable';
        
        if (trend.direction === 'up') {
            icon = '📈';
            className = 'trend-up';
        } else if (trend.direction === 'down') {
            icon = '📉';
            className = 'trend-down';
        }
        
        return `<span class="${className}">${icon} ${sign}${value}%</span>`;
    }

    // ==========================================
    // ТАБЛИЦА MEDIUM
    // ==========================================

    function updateMediumTable(medium, total) {
        const tableId = 'mediumTable';
        const tbody = $('#' + tableId + ' tbody');
        const emptyState = $('#' + tableId + 'Empty');
        const pagination = $('#' + tableId + 'Pagination');
        tbody.empty();

        const sortedMedium = Object.entries(medium)
            .sort((a, b) => b[1] - a[1]);

        if (sortedMedium.length === 0) {
            emptyState.show();
            pagination.hide();
            return;
        }
        
        emptyState.hide();

        const amountByMedium = window.UTMDashboard.stats?.amount_by_medium || {};
        
        if (!window.UTMDashboard.tablePagination[tableId]) {
            window.UTMDashboard.tablePagination[tableId] = { page: 1, perPage: 20 };
        }
        
        const paginationState = window.UTMDashboard.tablePagination[tableId];
        const startIndex = (paginationState.page - 1) * paginationState.perPage;
        const endIndex = startIndex + paginationState.perPage;
        const paginatedMedium = sortedMedium.slice(startIndex, endIndex);

        const mediumAnalytics = window.UTMDashboard.stats?.medium_analytics || {};

        paginatedMedium.forEach(([type, count]) => {
            const analytics = mediumAnalytics[type] || {};
            const paid = analytics.paid || 0;
            const amount = analytics.paid_amount || 0;
            const adsSpend = analytics.ads_spend || 0;
            const profit = analytics.profit || 0;
            const roi = analytics.roi || 0;
            const roas = analytics.roas || 0;
            const cpl = analytics.cpl || 0;
            const cpa = analytics.cpa || 0;
            const conversion = analytics.conversion_rate || 0;
            const dataType = analytics.data_type || 'common';

            const profitFormatted = profit >= 0 ? '+' + formatNumber(profit) : formatNumber(profit);
            const profitClass = profit > 0 ? 'trend-up' : (profit < 0 ? 'trend-down' : 'trend-stable');
            const roiFormatted = roi >= 0 ? '+' + roi.toFixed(1) + '%' : roi.toFixed(1) + '%';
            const roiClass = roi > 0 ? 'trend-up' : (roi < 0 ? 'trend-down' : 'trend-stable');
            const conversionFormatted = conversion.toFixed(1) + '%';
            const conversionClass = conversion > 10 ? 'trend-up' : (conversion > 5 ? 'trend-stable' : 'trend-down');

            const row = `
                <tr data-type="${dataType}">
                    <td><strong>${type || 'Не указано'}</strong> ${getDataTypeBadge(dataType)}</td>
                    <td>${count}</td>
                    <td><span class="badge badge-success">${paid}</span></td>
                    <td style="color: var(--ai-green); font-weight: 600;">${formatNumber(amount)} UAH</td>
                    <td style="color: var(--color-warning);">${formatNumber(adsSpend)} UAH</td>
                    <td><span class="${profitClass}">${profitFormatted} UAH</span></td>
                    <td><span class="${roiClass}">${roiFormatted}</span></td>
                    <td>${roas.toFixed(2)}</td>
                    <td>${formatNumber(cpl)} UAH</td>
                    <td>${formatNumber(cpa)} UAH</td>
                    <td><span class="${conversionClass}">${conversionFormatted}</span></td>
                </tr>
            `;

            tbody.append(row);
        });
        
        if (sortedMedium.length > paginationState.perPage) {
            renderPagination(tableId, sortedMedium.length, paginationState.page, paginationState.perPage);
            pagination.show();
        } else {
            pagination.hide();
        }
    }

    // ==========================================
    // ТАБЛИЦА КАМПАНИЙ
    // ==========================================

    function updateCampaignsTable(data, campaigns) {
        const tableId = 'campaignsTable';
        const tbody = $('#' + tableId + ' tbody');
        const emptyState = $('#' + tableId + 'Empty');
        const pagination = $('#' + tableId + 'Pagination');
        tbody.empty();

        const sortedCampaigns = Object.entries(campaigns)
            .sort((a, b) => b[1] - a[1]);

        if (sortedCampaigns.length === 0) {
            emptyState.show();
            pagination.hide();
            return;
        }
        
        emptyState.hide();

        const amountByCampaign = window.UTMDashboard.stats?.amount_by_campaign || {};
        
        if (!window.UTMDashboard.tablePagination[tableId]) {
            window.UTMDashboard.tablePagination[tableId] = { page: 1, perPage: 20 };
        }
        
        const paginationState = window.UTMDashboard.tablePagination[tableId];
        const startIndex = (paginationState.page - 1) * paginationState.perPage;
        const endIndex = startIndex + paginationState.perPage;
        const paginatedCampaigns = sortedCampaigns.slice(startIndex, endIndex);

        const campaignsAnalytics = window.UTMDashboard.stats?.campaigns_analytics || {};

        paginatedCampaigns.forEach(([campaign, count]) => {
            const analytics = campaignsAnalytics[campaign] || {};
            const paid = analytics.paid || 0;
            const amount = analytics.paid_amount || 0;
            const adsSpend = analytics.ads_spend || 0;
            const profit = analytics.profit || 0;
            const roi = analytics.roi || 0;
            const roas = analytics.roas || 0;
            const cpl = analytics.cpl || 0;
            const cpa = analytics.cpa || 0;
            const conversion = analytics.conversion_rate || 0;
            const dataType = analytics.data_type || 'common';

            const profitFormatted = profit >= 0 ? '+' + formatNumber(profit) : formatNumber(profit);
            const profitClass = profit > 0 ? 'trend-up' : (profit < 0 ? 'trend-down' : 'trend-stable');
            const roiFormatted = roi >= 0 ? '+' + roi.toFixed(1) + '%' : roi.toFixed(1) + '%';
            const roiClass = roi > 0 ? 'trend-up' : (roi < 0 ? 'trend-down' : 'trend-stable');
            const conversionFormatted = conversion.toFixed(1) + '%';
            const conversionClass = conversion > 10 ? 'trend-up' : (conversion > 5 ? 'trend-stable' : 'trend-down');

            const row = `
                <tr data-type="${dataType}">
                    <td><strong>${campaign || 'Не указано'}</strong> ${getDataTypeBadge(dataType)}</td>
                    <td>${count}</td>
                    <td><span class="badge badge-success">${paid}</span></td>
                    <td style="color: var(--ai-green); font-weight: 600;">${formatNumber(amount)} UAH</td>
                    <td style="color: var(--color-warning);">${formatNumber(adsSpend)} UAH</td>
                    <td><span class="${profitClass}">${profitFormatted} UAH</span></td>
                    <td><span class="${roiClass}">${roiFormatted}</span></td>
                    <td>${roas.toFixed(2)}</td>
                    <td>${formatNumber(cpl)} UAH</td>
                    <td>${formatNumber(cpa)} UAH</td>
                    <td><span class="${conversionClass}">${conversionFormatted}</span></td>
                </tr>
            `;

            tbody.append(row);
        });
        
        if (sortedCampaigns.length > paginationState.perPage) {
            renderPagination(tableId, sortedCampaigns.length, paginationState.page, paginationState.perPage);
            pagination.show();
        } else {
            pagination.hide();
        }
    }

    // ==========================================
    // ТАБЛИЦА TERM
    // ==========================================

    function updateTermTable(terms, total) {
        const tableId = 'termTable';
        const tbody = $('#' + tableId + ' tbody');
        const emptyState = $('#' + tableId + 'Empty');
        const pagination = $('#' + tableId + 'Pagination');
        tbody.empty();

        const sortedTerms = Object.entries(terms)
            .sort((a, b) => b[1] - a[1]);

        if (sortedTerms.length === 0) {
            emptyState.show();
            if (pagination.length) pagination.hide();
            return;
        }
        
        emptyState.hide();

        const amountByTerm = window.UTMDashboard.stats?.amount_by_term || {};
        
        if (!window.UTMDashboard.tablePagination[tableId]) {
            window.UTMDashboard.tablePagination[tableId] = { page: 1, perPage: 20 };
        }
        
        const paginationState = window.UTMDashboard.tablePagination[tableId];
        const startIndex = (paginationState.page - 1) * paginationState.perPage;
        const endIndex = startIndex + paginationState.perPage;
        const paginatedTerms = sortedTerms.slice(startIndex, endIndex);

        const termsAnalytics = window.UTMDashboard.stats?.terms_analytics || {};

        paginatedTerms.forEach(([term, count]) => {
            const analytics = termsAnalytics[term] || {};
            const paid = analytics.paid || 0;
            const amount = analytics.paid_amount || 0;
            const adsSpend = analytics.ads_spend || 0;
            const profit = analytics.profit || 0;
            const roi = analytics.roi || 0;
            const conversion = analytics.conversion_rate || 0;
            const dataType = analytics.data_type || 'common';

            const profitFormatted = profit >= 0 ? '+' + formatNumber(profit) : formatNumber(profit);
            const profitClass = profit > 0 ? 'trend-up' : (profit < 0 ? 'trend-down' : 'trend-stable');
            const roiFormatted = roi >= 0 ? '+' + roi.toFixed(1) + '%' : roi.toFixed(1) + '%';
            const roiClass = roi > 0 ? 'trend-up' : (roi < 0 ? 'trend-down' : 'trend-stable');
            const conversionFormatted = conversion.toFixed(1) + '%';
            const conversionClass = conversion > 10 ? 'trend-up' : (conversion > 5 ? 'trend-stable' : 'trend-down');

            const row = `
                <tr data-type="${dataType}">
                    <td>${term || 'Не указано'} ${getDataTypeBadge(dataType)}</td>
                    <td>${count}</td>
                    <td><span class="badge badge-success">${paid}</span></td>
                    <td style="color: var(--ai-green); font-weight: 600;">${formatNumber(amount)} UAH</td>
                    <td style="color: var(--color-warning);">${formatNumber(adsSpend)} UAH</td>
                    <td><span class="${profitClass}">${profitFormatted} UAH</span></td>
                    <td><span class="${roiClass}">${roiFormatted}</span></td>
                    <td><span class="${conversionClass}">${conversionFormatted}</span></td>
                </tr>
            `;

            tbody.append(row);
        });
        
        if (sortedTerms.length > paginationState.perPage && pagination.length) {
            renderPagination(tableId, sortedTerms.length, paginationState.page, paginationState.perPage);
            pagination.show();
        } else if (pagination.length) {
            pagination.hide();
        }
    }

    // ==========================================
    // ТАБЛИЦА CONTENT
    // ==========================================

    function updateContentTable(content, total) {
        const tableId = 'contentTable';
        const tbody = $('#' + tableId + ' tbody');
        const emptyState = $('#' + tableId + 'Empty');
        const pagination = $('#' + tableId + 'Pagination');
        tbody.empty();

        const sortedContent = Object.entries(content)
            .sort((a, b) => b[1] - a[1]);

        if (sortedContent.length === 0) {
            emptyState.show();
            if (pagination.length) pagination.hide();
            return;
        }
        
        emptyState.hide();

        const amountByContent = window.UTMDashboard.stats?.amount_by_content || {};
        
        if (!window.UTMDashboard.tablePagination[tableId]) {
            window.UTMDashboard.tablePagination[tableId] = { page: 1, perPage: 20 };
        }
        
        const paginationState = window.UTMDashboard.tablePagination[tableId];
        const startIndex = (paginationState.page - 1) * paginationState.perPage;
        const endIndex = startIndex + paginationState.perPage;
        const paginatedContent = sortedContent.slice(startIndex, endIndex);

        const contentAnalytics = window.UTMDashboard.stats?.content_analytics || {};

        paginatedContent.forEach(([item, count]) => {
            const analytics = contentAnalytics[item] || {};
            const paid = analytics.paid || 0;
            const amount = analytics.paid_amount || 0;
            const adsSpend = analytics.ads_spend || 0;
            const profit = analytics.profit || 0;
            const roi = analytics.roi || 0;
            const conversion = analytics.conversion_rate || 0;
            const dataType = analytics.data_type || 'common';

            const profitFormatted = profit >= 0 ? '+' + formatNumber(profit) : formatNumber(profit);
            const profitClass = profit > 0 ? 'trend-up' : (profit < 0 ? 'trend-down' : 'trend-stable');
            const roiFormatted = roi >= 0 ? '+' + roi.toFixed(1) + '%' : roi.toFixed(1) + '%';
            const roiClass = roi > 0 ? 'trend-up' : (roi < 0 ? 'trend-down' : 'trend-stable');
            const conversionFormatted = conversion.toFixed(1) + '%';
            const conversionClass = conversion > 10 ? 'trend-up' : (conversion > 5 ? 'trend-stable' : 'trend-down');

            const row = `
                <tr data-type="${dataType}">
                    <td>${item || 'Не указано'} ${getDataTypeBadge(dataType)}</td>
                    <td>${count}</td>
                    <td><span class="badge badge-success">${paid}</span></td>
                    <td style="color: var(--ai-green); font-weight: 600;">${formatNumber(amount)} UAH</td>
                    <td style="color: var(--color-warning);">${formatNumber(adsSpend)} UAH</td>
                    <td><span class="${profitClass}">${profitFormatted} UAH</span></td>
                    <td><span class="${roiClass}">${roiFormatted}</span></td>
                    <td><span class="${conversionClass}">${conversionFormatted}</span></td>
                </tr>
            `;

            tbody.append(row);
        });
        
        if (sortedContent.length > paginationState.perPage && pagination.length) {
            renderPagination(tableId, sortedContent.length, paginationState.page, paginationState.perPage);
            pagination.show();
        } else if (pagination.length) {
            pagination.hide();
        }
    }

    // ==========================================
    // СОРТИРОВКА ТАБЛИЦ
    // ==========================================

    function initTableSorting() {
        $(document).on('click', '.data-table th.sortable', function() {
            const $th = $(this);
            const tableId = $th.closest('table').attr('id');
            const sortField = $th.data('sort');
            
            // Получить текущее состояние сортировки
            if (!window.UTMDashboard.tableSort[tableId]) {
                window.UTMDashboard.tableSort[tableId] = { field: null, direction: 'asc' };
            }
            
            const currentSort = window.UTMDashboard.tableSort[tableId];
            
            // Определить направление сортировки
            let direction = 'asc';
            if (currentSort.field === sortField && currentSort.direction === 'asc') {
                direction = 'desc';
            }
            
            // Обновить состояние
            window.UTMDashboard.tableSort[tableId] = { field: sortField, direction: direction };
            
            // Обновить визуальные индикаторы
            $th.closest('table').find('th.sortable').removeClass('sort-asc sort-desc');
            $th.addClass('sort-' + direction);
            
            // Применить сортировку
            sortTable(tableId, sortField, direction);
        });
    }
    
    function sortTable(tableId, field, direction) {
        const $table = $('#' + tableId);
        const $tbody = $table.find('tbody');
        const rows = $tbody.find('tr').toArray();

        // Числовые поля для правильной сортировки
        const numericFields = [
            'leads', 'paid', 'failed', 'pending',
            'amount', 'paidAmount', 'adsSpend', 'profit',
            'roi', 'roas', 'cpl', 'cpa', 'conversion',
            'percentage', 'avgCheck', 'lostAmount', 'totalAmount'
        ];

        rows.sort(function(a, b) {
            let aVal, bVal;

            // Получить значения для сравнения
            const aCells = $(a).find('td');
            const bCells = $(b).find('td');

            // Определить индекс колонки по полю
            let colIndex = -1;
            $table.find('thead th').each(function(index) {
                if ($(this).data('sort') === field) {
                    colIndex = index;
                    return false;
                }
            });

            if (colIndex === -1) return 0;

            aVal = $(aCells[colIndex]).text().trim();
            bVal = $(bCells[colIndex]).text().trim();

            // Обработка разных типов данных
            // Числа (включая проценты, суммы с символами валют и знаками +/-)
            if (numericFields.includes(field)) {
                // Удаляем все кроме цифр, точки и минуса
                aVal = parseFloat(aVal.replace(/[^0-9.\-]/g, '')) || 0;
                bVal = parseFloat(bVal.replace(/[^0-9.\-]/g, '')) || 0;
            }
            // Даты
            else if (field === 'date') {
                aVal = new Date(aVal) || new Date(0);
                bVal = new Date(bVal) || new Date(0);
            }
            // Строки
            else {
                aVal = aVal.toLowerCase();
                bVal = bVal.toLowerCase();
            }

            // Сравнение
            if (aVal < bVal) return direction === 'asc' ? -1 : 1;
            if (aVal > bVal) return direction === 'asc' ? 1 : -1;
            return 0;
        });

        // Перестроить таблицу
        $tbody.empty().append(rows);
    }

    // ==========================================
    // ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
    // ==========================================

    function setupSearch() {
        // Поиск по источникам
        $('#sourcesSearch').on('input', function() {
            const query = $(this).val().toLowerCase();
            filterTable('#sourcesTable', query);
        });

        // Поиск по medium
        $('#mediumSearch').on('input', function() {
            const query = $(this).val().toLowerCase();
            filterTable('#mediumTable', query);
        });

        // Поиск по кампаниям
        $('#campaignsSearch').on('input', function() {
            const query = $(this).val().toLowerCase();
            filterTable('#campaignsTable', query);
        });
    }

    function filterTable(tableId, query) {
        $(tableId + ' tbody tr').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(query) > -1);
        });
    }

    /**
     * Получить HTML бейджа для типа данных
     *
     * @param {string} dataType - 'common', 'crm_only', 'ads_only'
     * @returns {string} HTML бейджа
     */
    function getDataTypeBadge(dataType) {
        const badges = {
            'common': '<span class="badge badge-common" title="Есть и лиды и рекламные расходы">🟢 Общие</span>',
            'crm_only': '<span class="badge badge-crm-only" title="Есть лиды, нет рекламных расходов (органический трафик)">🔵 CRM</span>',
            'ads_only': '<span class="badge badge-ads-only" title="Есть рекламные расходы, нет лидов">🟡 ADS</span>'
        };

        return badges[dataType] || '';
    }

    function setupFilters() {
        // Фильтр по кампаниям (TOP10/Активные)
        $('#campaignsFilter').on('change', function() {
            const filter = $(this).val();
            console.log('Фильтр кампаний:', filter);

            // Применить фильтр к таблице кампаний
            if (window.UTMDashboard.data && window.UTMDashboard.stats) {
                applyCampaignFilter(filter);
            }
        });

        // Фильтры по типу данных (data_type)
        $('#analyticsDataTypeFilter').on('change', function() {
            filterTableByDataType('#analyticsSourcesTable', $(this).val());
        });

        $('#sourcesDataTypeFilter').on('change', function() {
            filterTableByDataType('#sourcesTable', $(this).val());
        });

        $('#mediumDataTypeFilter').on('change', function() {
            filterTableByDataType('#mediumTable', $(this).val());
        });

        $('#campaignsDataTypeFilter').on('change', function() {
            filterTableByDataType('#campaignsTable', $(this).val());
        });

        $('#termDataTypeFilter').on('change', function() {
            filterTableByDataType('#termTable', $(this).val());
        });

        $('#contentDataTypeFilter').on('change', function() {
            filterTableByDataType('#contentTable', $(this).val());
        });

        $('#combinationsDataTypeFilter').on('change', function() {
            filterTableByDataType('#combinationsTable', $(this).val());
        });
    }

    /**
     * Фильтрация строк таблицы по data_type
     * @param {string} tableId - ID таблицы (с #)
     * @param {string} filterValue - 'all', 'common', 'crm_only', 'ads_only'
     */
    function filterTableByDataType(tableId, filterValue) {
        $(tableId + ' tbody tr').each(function() {
            if (filterValue === 'all') {
                $(this).show();
            } else {
                // Получить data-type из атрибута строки
                const rowDataType = $(this).attr('data-type');
                if (rowDataType === filterValue) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            }
        });
    }
    
    function setupCombinations() {
        $('#combinationType').on('change', function() {
            const type = $(this).val();
            updateCombinationsTable(type);
        });
    }
    
    function updateCombinationsTable(type) {
        const combinations = window.UTMDashboard.stats?.combinations || {};
        const tableId = 'combinationsTable';
        const tbody = $('#' + tableId + ' tbody');
        const emptyState = $('#' + tableId + 'Empty');
        const pagination = $('#' + tableId + 'Pagination');
        tbody.empty();

        // Фильтровать по типу
        const filtered = Object.entries(combinations).filter(([key, data]) => {
            return data.type === type;
        });

        if (filtered.length === 0) {
            emptyState.show();
            pagination.hide();
            return;
        }
        
        emptyState.hide();
        
        // Сортировать по общей сумме
        const sorted = filtered.sort((a, b) => (b[1].total_amount || 0) - (a[1].total_amount || 0));
        
        if (!window.UTMDashboard.tablePagination[tableId]) {
            window.UTMDashboard.tablePagination[tableId] = { page: 1, perPage: 20 };
        }
        
        const paginationState = window.UTMDashboard.tablePagination[tableId];
        const startIndex = (paginationState.page - 1) * paginationState.perPage;
        const endIndex = startIndex + paginationState.perPage;
        const paginated = sorted.slice(startIndex, endIndex);

        paginated.forEach(([key, data]) => {
            const leads = data.leads || 0;
            const paid = data.paid || 0;
            const failed = data.failed || 0;
            const pending = data.pending || 0;
            const paidAmount = data.paid_amount || 0;
            // Потеряно = только неуспешные (failed), БЕЗ "в процессе" (pending)
            const lostAmount = data.failed_amount || 0;
            const totalAmount = data.total_amount || 0;
            const total = leads + paid + failed + pending;
            
            // Прибыль = заработано - потеряно (без учета затрат на рекламу)
            const profit = paidAmount - lostAmount;
            const profitFormatted = profit >= 0 ? '+' + formatNumber(profit) : formatNumber(profit);
            const profitClass = profit > 0 ? 'trend-up' : (profit < 0 ? 'trend-down' : 'trend-stable');
            
            // ROI = (Доход - Затраты) / Затраты * 100
            const adsSpend = data.ads_spend || 0;
            let roi = '—';
            let roiClass = 'trend-stable';
            if (adsSpend > 0) {
                const roiValue = ((paidAmount - adsSpend) / adsSpend * 100).toFixed(1);
                roi = roiValue + '%';
                roiClass = parseFloat(roiValue) > 0 ? 'trend-up' : (parseFloat(roiValue) < 0 ? 'trend-down' : 'trend-stable');
            }
            
            // Конверсия (оплачено / всего * 100)
            const conversion = total > 0 ? ((paid / total) * 100).toFixed(1) : '0';
            const conversionClass = parseFloat(conversion) > 10 ? 'trend-up' : (parseFloat(conversion) > 5 ? 'trend-stable' : 'trend-down');

            // Data type
            const dataType = data.data_type || 'common';

            const row = `
                <tr data-type="${dataType}">
                    <td><strong>${key || 'Не указано'}</strong> ${getDataTypeBadge(dataType)}</td>
                    <td>${leads}</td>
                    <td><span class="badge badge-success">${paid}</span></td>
                    <td><span class="badge badge-danger">${failed}</span></td>
                    <td><span class="badge badge-warning">${pending}</span></td>
                    <td style="color: var(--ai-green); font-weight: 600;">${formatNumber(paidAmount)} UAH</td>
                    <td style="color: var(--color-danger); font-weight: 600;">${formatNumber(lostAmount)} UAH</td>
                    <td>${formatNumber(totalAmount)} UAH</td>
                    <td><span class="${profitClass}">${profitFormatted} UAH</span></td>
                    <td><span class="${roiClass}">${roi}</span></td>
                    <td><span class="${conversionClass}">${conversion}%</span></td>
                </tr>
            `;

            tbody.append(row);
        });
        
        if (sorted.length > paginationState.perPage) {
            renderPagination(tableId, sorted.length, paginationState.page, paginationState.perPage);
            pagination.show();
        } else {
            pagination.hide();
        }
    }
    
    function applyCampaignFilter(filter) {
        const allCampaigns = window.UTMDashboard.stats.campaigns || {};
        const data = window.UTMDashboard.data || [];
        let filteredCampaigns = {};
        
        if (filter === 'top10') {
            // ТОП-10 кампаний по количеству лидов
            const sorted = Object.entries(allCampaigns)
                .sort((a, b) => b[1] - a[1])
                .slice(0, 10);
            
            filteredCampaigns = Object.fromEntries(sorted);
        } else if (filter === 'active') {
            // Активные кампании (с лидами за последние 7 дней)
            const sevenDaysAgo = new Date();
            sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
            
            const activeCampaigns = new Set();
            data.forEach(item => {
                if (item.created_at && item.utm_campaign) {
                    const itemDate = new Date(item.created_at);
                    if (itemDate >= sevenDaysAgo) {
                        activeCampaigns.add(item.utm_campaign);
                    }
                }
            });
            
            // Оставить только активные кампании
            Object.keys(allCampaigns).forEach(campaign => {
                if (activeCampaigns.has(campaign)) {
                    filteredCampaigns[campaign] = allCampaigns[campaign];
                }
            });
        } else {
            // Все кампании
            filteredCampaigns = allCampaigns;
        }
        
        // Обновить таблицу с отфильтрованными данными
        updateCampaignsTable(data, filteredCampaigns);
    }

    // ==========================================
    // ЭКСПОРТ ДАННЫХ
    // ==========================================

    function exportData() {
        if (!window.UTMDashboard.data) {
            showNotification('warning', 'Предупреждение', 'Нет данных для экспорта');
            return;
        }

        const dataStr = JSON.stringify(window.UTMDashboard.data, null, 2);
        const blob = new Blob([dataStr], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'utm_data_' + new Date().toISOString().split('T')[0] + '.json';
        link.click();

        showNotification('success', 'Готово', 'Данные экспортированы');
    }
    
    function exportToCSV() {
        if (!window.UTMDashboard.data || window.UTMDashboard.data.length === 0) {
            showNotification('warning', 'Предупреждение', 'Нет данных для экспорта');
            return;
        }
        
        // Получить отфильтрованные данные (уже применен фильтр по датам)
        const data = window.UTMDashboard.data;
        
        // Определить заголовки CSV
        const headers = ['Email', 'Телефон', 'Дата создания', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content', 'Сумма', 'Список', 'Теги'];
        
        // Создать CSV строку
        let csv = headers.join(',') + '\n';
        
        data.forEach(item => {
            const row = [
                escapeCSV(item.email || ''),
                escapeCSV(item.phone || ''),
                escapeCSV(item.created_at || ''),
                escapeCSV(item.utm_source || ''),
                escapeCSV(item.utm_medium || ''),
                escapeCSV(item.utm_campaign || ''),
                escapeCSV(item.utm_term || ''),
                escapeCSV(item.utm_content || ''),
                escapeCSV(item.amount || '0'),
                escapeCSV(item.list_name || ''),
                escapeCSV(item.tag_list || '')
            ];
            csv += row.join(',') + '\n';
        });
        
        // Скачать файл
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        
        // Имя файла с датой и периодом
        const dateFilter = window.UTMDashboard.dateFilter || {};
        const rangeName = dateFilter.range || 'all';
        const fileName = 'utm_export_' + rangeName + '_' + new Date().toISOString().split('T')[0] + '.csv';
        
        link.download = fileName;
        link.click();
        URL.revokeObjectURL(url);
        
        showNotification('success', 'Готово', 'Данные экспортированы в CSV');
    }
    
    function escapeCSV(value) {
        if (value === null || value === undefined) {
            return '';
        }
        
        const stringValue = String(value);
        
        // Если содержит запятую, кавычки или перенос строки - обернуть в кавычки
        if (stringValue.includes(',') || stringValue.includes('"') || stringValue.includes('\n')) {
            return '"' + stringValue.replace(/"/g, '""') + '"';
        }
        
        return stringValue;
    }
    
    // Добавить обработчик для кнопки экспорта CSV
    $(document).on('click', '#exportCSVBtn', function() {
        exportToCSV();
    });

    // ==========================================
    // ДЕТАЛЬНЫЙ ПРОСМОТР ЛИДА
    // ==========================================

    function initLeadDetails() {
        // Клик по строке таблицы открывает детали лида
        $(document).on('click', '.data-table tbody tr', function() {
            // Найти email в строке (первая колонка обычно содержит ключевое поле)
            const $row = $(this);
            const $cells = $row.find('td');
            
            // Попытаться найти email или другой идентификатор
            // Для таблиц с лидами нужно найти соответствующий лид в данных
            const rowText = $row.text();
            
            // Найти лид по содержимому строки
            const lead = findLeadByRowData($row, rowText);
            
            if (lead) {
                showLeadDetails(lead);
            }
        });
    }
    
    function findLeadByRowData($row, rowText) {
        if (!window.UTMDashboard.data) return null;
        
        // Попытаться найти лид по различным полям
        const data = window.UTMDashboard.data;
        
        // Для таблицы источников - найти первый лид с этим источником
        const tableId = $row.closest('table').attr('id');
        
        if (tableId === 'sourcesTable') {
            const source = $row.find('td:first').text().trim();
            return data.find(item => item.utm_source === source) || null;
        } else if (tableId === 'mediumTable') {
            const medium = $row.find('td:first').text().trim();
            return data.find(item => item.utm_medium === medium) || null;
        } else if (tableId === 'campaignsTable') {
            const campaign = $row.find('td:first').text().trim();
            return data.find(item => item.utm_campaign === campaign) || null;
        } else if (tableId === 'termTable') {
            const term = $row.find('td:first').text().trim();
            return data.find(item => item.utm_term === term) || null;
        } else if (tableId === 'contentTable') {
            const content = $row.find('td:first').text().trim();
            return data.find(item => item.utm_content === content) || null;
        }
        
        // Если не нашли - вернуть первый лид
        return data[0] || null;
    }
    
    function showLeadDetails(lead) {
        const content = $('#leadDetailsContent');
        
        const html = `
            <div class="lead-details">
                <div class="lead-details-section">
                    <h4>📧 Контактная информация</h4>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Email:</div>
                        <div class="lead-details-value">${lead.email || 'Не указано'}</div>
                    </div>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Телефон:</div>
                        <div class="lead-details-value">${lead.phone || 'Не указано'}</div>
                    </div>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Дата создания:</div>
                        <div class="lead-details-value">${lead.created_at ? new Date(lead.created_at).toLocaleString('ru-RU') : 'Не указано'}</div>
                    </div>
                </div>
                
                <div class="lead-details-section">
                    <h4>🎯 UTM параметры</h4>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Source:</div>
                        <div class="lead-details-value">${lead.utm_source || 'Не указано'}</div>
                    </div>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Medium:</div>
                        <div class="lead-details-value">${lead.utm_medium || 'Не указано'}</div>
                    </div>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Campaign:</div>
                        <div class="lead-details-value">${lead.utm_campaign || 'Не указано'}</div>
                    </div>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Term:</div>
                        <div class="lead-details-value">${lead.utm_term || 'Не указано'}</div>
                    </div>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Content:</div>
                        <div class="lead-details-value">${lead.utm_content || 'Не указано'}</div>
                    </div>
                </div>
                
                <div class="lead-details-section">
                    <h4>💰 Финансовая информация</h4>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Сумма:</div>
                        <div class="lead-details-value">${lead.amount ? formatNumber(lead.amount) : '0'} UAH</div>
                    </div>
                </div>
                
                <div class="lead-details-section">
                    <h4>📋 Дополнительная информация</h4>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Список:</div>
                        <div class="lead-details-value">${lead.list_name || 'Не указано'}</div>
                    </div>
                    <div class="lead-details-row">
                        <div class="lead-details-label">Теги:</div>
                        <div class="lead-details-value">${lead.tag_list || 'Нет тегов'}</div>
                    </div>
                </div>
            </div>
        `;
        
        content.html(html);
        
        if (typeof openModal === 'function') {
            openModal('leadDetailsModal');
        }
    }

    // ==========================================
    // ИНДИКАТОР ПОСЛЕДНЕГО ОБНОВЛЕНИЯ
    // ==========================================

    function updateLastUpdateIndicator() {
        if (!window.UTMDashboard.lastUpdate) {
            $('#updateStatus').text('🔄 Загрузка...').removeClass('fresh stale').addClass('loading');
            $('#updateTime').text('');
            return;
        }
        
        const now = new Date();
        const lastUpdate = window.UTMDashboard.lastUpdate;
        const diffMs = now - lastUpdate;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);
        
        let statusText = '';
        let statusClass = 'fresh';
        let timeText = '';
        
        if (diffMins < 5) {
            statusText = '✅ Данные свежие';
            statusClass = 'fresh';
            timeText = 'Обновлено только что';
        } else if (diffMins < 60) {
            statusText = '✅ Данные свежие';
            statusClass = 'fresh';
            timeText = `Обновлено ${diffMins} мин. назад`;
        } else if (diffHours < 24) {
            statusText = '⚠️ Данные устарели';
            statusClass = 'stale';
            timeText = `Обновлено ${diffHours} ч. назад`;
        } else {
            statusText = '❌ Данные устарели';
            statusClass = 'stale';
            timeText = `Обновлено ${diffDays} дн. назад`;
        }
        
        $('#updateStatus').text(statusText).removeClass('fresh stale loading').addClass(statusClass);
        $('#updateTime').text(timeText);
    }
    
    // Обновлять индикатор каждую минуту
    setInterval(function() {
        if (window.UTMDashboard.lastUpdate) {
            updateLastUpdateIndicator();
        }
    }, 60000);

    // ==========================================
    // БЛОКУВАННЯ UTM_TERM ДЛЯ НЕ-АДМІНІВ
    // ==========================================

    /**
     * Заблокувати utm_term фільтр для таргетологів і гостей
     */
    function lockUtmTermFilter() {
        const $utmTermFilter = $('#utmTermFilter');

        if (window.UTMDashboard.userRole !== 'admin') {
            // Заблокувати поле
            $utmTermFilter
                .prop('readonly', true)
                .prop('disabled', true)
                .addClass('filter-locked')
                .attr('title', 'Це поле заблоковане для вашої ролі');

            // Встановити значення
            $utmTermFilter.val(window.UTMDashboard.userUtmTerm);

            // Додати іконку замка
            if (!$utmTermFilter.siblings('.lock-icon').length) {
                $utmTermFilter.after('<span class="lock-icon" title="Заблоковано">🔒</span>');
            }

            console.log('🔒 UTM Term фільтр заблокований для роли:', window.UTMDashboard.userRole);
        }
    }

    // Сделать функции доступными глобально
    window.UTMDashboard.loadData = loadData;
    window.UTMDashboard.syncData = syncData;
    // updateCharts определена в charts.js, не дублируем здесь
    if (typeof window.updateCharts === 'function') {
        window.UTMDashboard.updateCharts = window.updateCharts;
    }
    window.UTMDashboard.lockUtmTermFilter = lockUtmTermFilter;

})(jQuery);
