// === deals-table.js ===
// Логика отображения таблицы сделок с пагинацией
// ОБНОВЛЕНО: 2025-01-14 20:30:00

(function($) {
    'use strict';

    // Состояние таблицы
    let tableState = {
        currentPage: 1,
        perPage: 25,
        sortField: 'id',
        sortOrder: 'DESC',
        total: 0,
        totalPages: 0
    };

    // Инициализация
    $(document).ready(function() {
        initDealsTable();
    });

    /**
     * Инициализация таблицы сделок
     */
    function initDealsTable() {
        // Обработчик изменения количества записей на странице
        $('#tablePerPage').on('change', function() {
            tableState.perPage = $(this).val();
            tableState.currentPage = 1;
            loadDealsTable();
        });

        // Обработчик сортировки
        $(document).on('click', '#dealsTable .sortable', function() {
            const field = $(this).data('sort');
            if (field) {
                if (tableState.sortField === field) {
                    // Переключить порядок сортировки
                    tableState.sortOrder = tableState.sortOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    tableState.sortField = field;
                    tableState.sortOrder = 'DESC';
                }
                loadDealsTable();
            }
        });

        // Загрузить данные при переключении на секцию
        $(document).on('sectionSwitched', function(e, section) {
            if (section === 'table') {
                setTimeout(function() {
                    loadDealsTable();
                }, 100);
            }
        });

        // Загрузить данные если секция активна при загрузке страницы
        setTimeout(function() {
            if ($('#table-section').hasClass('active')) {
                loadDealsTable();
            }
        }, 500);
    }

    /**
     * Загрузить данные таблицы
     */
    function loadDealsTable() {
        console.log('📋 Загрузка таблицы сделок...');

        // Показать загрузчик
        $('#dealsTableBody').html('<tr><td colspan="35" style="text-align: center; padding: 2rem;"><div class="loading-spinner"></div></td></tr>');

        // Получить параметры фильтра
        const dateFilter = window.UTMDashboard.dateFilter || {};
        const modelFilter = $('#modelFilter').val() || 'VOLVO';
        const customerType = $('#customerTypeFilter').val() || 'all';
        const funnelType = $('#funnelTypeFilter').val() || 'all';
        const tariffFilter = $('#tariffFilter').val() || 'all';
        const payProviderFilter = $('#payProviderFilter').val() || 'all';

        const params = {
            action: 'get_deals_table',
            date_range: dateFilter.range || 'all',
            model: modelFilter,
            customer_type: customerType,
            funnel_type: funnelType,
            tariff: tariffFilter,
            pay_provider: payProviderFilter,
            page: tableState.currentPage,
            per_page: tableState.perPage,
            sort_field: tableState.sortField,
            sort_order: tableState.sortOrder
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

        // API URL
        const apiUrl = (typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : '') + 'api/handler.php';

        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    renderDealsTable(response.data.deals || []);
                    updatePagination(response.data);
                    tableState.total = response.data.total || 0;
                    tableState.totalPages = response.data.total_pages || 0;
                } else {
                    showError('Ошибка загрузки данных: ' + (response.message || 'Неизвестная ошибка'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Ошибка загрузки таблицы:', error);
                showError('Не удалось загрузить данные таблицы');
                $('#dealsTableBody').html('<tr><td colspan="35" class="empty-cell">Ошибка загрузки данных</td></tr>');
            }
        });
    }

    /**
     * Отрисовать таблицу сделок
     */
    function renderDealsTable(deals) {
        const tbody = $('#dealsTableBody');
        tbody.empty();

        if (!deals || deals.length === 0) {
            $('#dealsTableEmpty').show();
            tbody.html('<tr><td colspan="35" class="empty-cell">Нет данных для отображения</td></tr>');
            return;
        }

        $('#dealsTableEmpty').hide();

        deals.forEach(function(deal) {
            const row = $('<tr>');
            
            // Форматирование значений
            const formatValue = function(value) {
                if (value === null || value === undefined || value === '') {
                    return '<span class="text-muted">-</span>';
                }
                return String(value);
            };

            const formatDate = function(dateStr) {
                if (!dateStr) return '<span class="text-muted">-</span>';
                const date = new Date(dateStr);
                return date.toLocaleString('ru-RU', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            };

            const formatBoolean = function(value) {
                if (value === 1 || value === '1' || value === true) {
                    return '<span class="badge badge-success">Да</span>';
                }
                return '<span class="badge badge-secondary">Нет</span>';
            };

            const formatAmount = function(value) {
                if (!value || value === '0' || value === 0) {
                    return '<span class="text-muted">-</span>';
                }
                return parseFloat(value).toFixed(2);
            };

            // Добавить ячейки
            row.append($('<td>').html(formatValue(deal.id)));
            row.append($('<td>').html(formatValue(deal.deal_id)));
            row.append($('<td>').html(formatValue(deal.contact_id)));
            row.append($('<td>').html(formatValue(deal.email)));
            row.append($('<td>').html(formatValue(deal.phone)));
            row.append($('<td>').html(formatValue(deal.full_name)));
            row.append($('<td>').html(formatDate(deal.created_at)));
            row.append($('<td>').html(formatDate(deal.deal_updated_at)));
            row.append($('<td>').html(formatAmount(deal.amount)));
            row.append($('<td>').html(formatAmount(deal.amount_uah)));
            row.append($('<td>').html(formatAmount(deal.deal_price)));
            row.append($('<td>').html(formatValue(deal.deal_currency)));
            row.append($('<td>').html(formatValue(deal.utm_source)));
            row.append($('<td>').html(formatValue(deal.utm_medium)));
            row.append($('<td>').html(formatValue(deal.utm_campaign)));
            row.append($('<td>').html(formatValue(deal.utm_term)));
            row.append($('<td>').html(formatValue(deal.utm_content)));
            row.append($('<td>').html(formatValue(deal.deal_pipeline)));
            row.append($('<td>').html(formatValue(deal.deal_type)));
            row.append($('<td>').html(formatValue(deal.deal_status)));
            row.append($('<td>').html(formatBoolean(deal.is_paid)));
            row.append($('<td>').html(formatBoolean(deal.is_failed)));
            row.append($('<td>').html(formatBoolean(deal.is_pending)));
            row.append($('<td>').html(formatValue(deal.deal_name)));
            row.append($('<td>').html(formatValue(deal.deal_step)));
            row.append($('<td>').html(formatValue(deal.model)));
            row.append($('<td>').html(formatValue(deal.comment)));
            row.append($('<td>').html(formatValue(deal.product)));
            row.append($('<td>').html(formatValue(deal.tickets)));
            row.append($('<td>').html(formatValue(deal.tickets_count)));
            row.append($('<td>').html(formatValue(deal.list_name)));
            row.append($('<td>').html(formatValue(deal.tag_list)));
            row.append($('<td>').html(formatDate(deal.imported_at)));
            row.append($('<td>').html(formatDate(deal.updated_at)));

            tbody.append(row);
        });

        // Обновить индикаторы сортировки
        updateSortIndicators();
    }

    /**
     * Обновить индикаторы сортировки
     */
    function updateSortIndicators() {
        $('#dealsTable .sortable').each(function() {
            const field = $(this).data('sort');
            $(this).removeClass('sort-asc sort-desc');
            
            if (field === tableState.sortField) {
                $(this).addClass(tableState.sortOrder === 'ASC' ? 'sort-asc' : 'sort-desc');
            }
        });
    }

    /**
     * Обновить пагинацию
     */
    function updatePagination(data) {
        const pagination = $('#dealsTablePagination');
        pagination.empty();

        if (!data || data.total_pages <= 1) {
            pagination.hide();
            return;
        }

        pagination.show();

        const total = data.total || 0;
        const currentPage = data.page || 1;
        const totalPages = data.total_pages || 1;
        const perPage = data.per_page || 25;

        // Информация о записях
        const startRecord = total === 0 ? 0 : ((currentPage - 1) * perPage) + 1;
        const endRecord = Math.min(currentPage * perPage, total);

        const info = $('<div>').addClass('pagination-info')
            .html(`Показано ${startRecord}-${endRecord} из ${total} записей`);
        pagination.append(info);

        // Кнопки навигации
        const nav = $('<div>').addClass('pagination-nav');

        // Кнопка "Первая"
        const firstBtn = $('<button>')
            .addClass('pagination-btn')
            .html('« Первая')
            .prop('disabled', currentPage === 1)
            .on('click', function() {
                if (currentPage > 1) {
                    tableState.currentPage = 1;
                    loadDealsTable();
                }
            });
        nav.append(firstBtn);

        // Кнопка "Предыдущая"
        const prevBtn = $('<button>')
            .addClass('pagination-btn')
            .html('‹ Предыдущая')
            .prop('disabled', currentPage === 1)
            .on('click', function() {
                if (currentPage > 1) {
                    tableState.currentPage = currentPage - 1;
                    loadDealsTable();
                }
            });
        nav.append(prevBtn);

        // Номера страниц
        const pageNumbers = $('<div>').addClass('pagination-numbers');
        
        // Показать максимум 7 страниц вокруг текущей
        let startPage = Math.max(1, currentPage - 3);
        let endPage = Math.min(totalPages, currentPage + 3);

        if (startPage > 1) {
            const firstPageBtn = $('<button>')
                .addClass('pagination-number')
                .html('1')
                .on('click', function() {
                    tableState.currentPage = 1;
                    loadDealsTable();
                });
            pageNumbers.append(firstPageBtn);
            
            if (startPage > 2) {
                pageNumbers.append($('<span>').addClass('pagination-ellipsis').html('...'));
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const pageBtn = $('<button>')
                .addClass('pagination-number')
                .addClass(i === currentPage ? 'active' : '')
                .html(i)
                .on('click', function() {
                    tableState.currentPage = i;
                    loadDealsTable();
                });
            pageNumbers.append(pageBtn);
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                pageNumbers.append($('<span>').addClass('pagination-ellipsis').html('...'));
            }
            const lastPageBtn = $('<button>')
                .addClass('pagination-number')
                .html(totalPages)
                .on('click', function() {
                    tableState.currentPage = totalPages;
                    loadDealsTable();
                });
            pageNumbers.append(lastPageBtn);
        }

        nav.append(pageNumbers);

        // Кнопка "Следующая"
        const nextBtn = $('<button>')
            .addClass('pagination-btn')
            .html('Следующая ›')
            .prop('disabled', currentPage === totalPages)
            .on('click', function() {
                if (currentPage < totalPages) {
                    tableState.currentPage = currentPage + 1;
                    loadDealsTable();
                }
            });
        nav.append(nextBtn);

        // Кнопка "Последняя"
        const lastBtn = $('<button>')
            .addClass('pagination-btn')
            .html('Последняя »')
            .prop('disabled', currentPage === totalPages)
            .on('click', function() {
                if (currentPage < totalPages) {
                    tableState.currentPage = totalPages;
                    loadDealsTable();
                }
            });
        nav.append(lastBtn);

        pagination.append(nav);
    }

    /**
     * Показать ошибку
     */
    function showError(message) {
        if (typeof showNotification === 'function') {
            showNotification('error', 'Ошибка', message);
        } else {
            console.error(message);
        }
    }

    // Экспортировать функцию загрузки для использования из других модулей
    window.loadDealsTable = loadDealsTable;

    // Слушать изменения фильтров
    $(document).on('filtersChanged', function() {
        if ($('#table-section').hasClass('active')) {
            tableState.currentPage = 1;
            loadDealsTable();
        }
    });

})(jQuery);
