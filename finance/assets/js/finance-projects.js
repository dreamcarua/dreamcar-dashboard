// === FINANCE-PROJECTS.JS ===
// finance/assets/js/finance-projects.js
// НАЗНАЧЕНИЕ: Модуль проектiв - список P&L карток та детальний перегляд з журналом транзакцiй
// СВЯЗИ: finance-app.js (ZK namespace), api/handler.php
// РАЗМЕР: ~380 строк

ZK.Projects = (function() {
    var currentProjectId = null;
    var txPage = 1;
    var txFilters = {};
    var TX_GROUP_STORAGE_KEY = 'finance_tx_group_filter';

    var TX_GROUP_OPTIONS = [
        { value: '',             label: 'Всi витрати' },
        { value: 'advertising',  label: '📣 Рекламнi' },
        { value: 'production',   label: '🎁 Подарунки' },
        { value: 'operations',   label: '🏢 Операцiйнi' },
        { value: 'team',         label: '👥 Команда' },
        { value: 'other',        label: '❓ Iнше' },
        { value: 'other_all',    label: '🔧 Всi не рекламнi' },
    ];

    var STATUS_LABELS = {
        active:    'Активний',
        completed: 'Завершений',
        planned:   'Заплановано',
        paused:    'Призупинено'
    };

    var TYPE_LABELS = {
        income:             'Дохiд',
        income_extra:       'Доп. дохiд',
        expense:            'Витрата',
        card_topup:         'Поповн. картки',
        withdrawal_vadym:   'Дивiденд Вадим',
        withdrawal_artem:   'Дивiденд Артем',
        conversion:         'Конвертацiя',
        salary:             'ЗП'
    };

    var CARD_TYPES = ['expense', 'card_topup'];

    // ----------------------------------------------------------------
    // Public entry point — called by ZK.Router
    // ----------------------------------------------------------------
    function load(projectId) {
        if (projectId) {
            openDetail(parseInt(projectId, 10));
        } else {
            loadList();
        }
    }

    // ----------------------------------------------------------------
    // List view
    // ----------------------------------------------------------------
    function loadList() {
        currentProjectId = null;
        txPage = 1;
        txFilters = {};

        var $section = $('#projects-section');
        $section.html(
            '<div class="zk-skeleton" style="height:200px"></div>'
        );

        ZK.Api('projects.list', {}, function(data) {
            var items = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);

            if (!items.length) {
                $section.html(
                    '<div class="finance-empty-state">' +
                    '<p>Немає проектiв</p>' +
                    '</div>'
                );
                return;
            }

            var addBtn = ZK.Config.canWrite
                ? '<button class="zk-btn zk-btn-primary" id="btn-add-project">+ Новий проект</button>'
                : '';

            var html =
                '<div class="projects-list-header">' +
                '<h2 class="projects-list-title">Проекти</h2>' +
                addBtn +
                '</div>' +
                '<div class="zk-table-wrap">' +
                '<table class="zk-table" id="projects-table">' +
                '<thead><tr>' +
                '<th style="width:22%">Назва</th>' +
                '<th style="width:11%">Статус</th>' +
                '<th style="width:18%">Дати</th>' +
                '<th class="num" style="width:13%">Дохiд</th>' +
                '<th class="num" style="width:12%">Витрати</th>' +
                '<th class="num" style="width:13%">Прибуток</th>' +
                '<th class="num" style="width:7%">Маржа</th>' +
                '<th style="width:4%"></th>' +
                '</tr></thead>' +
                '<tbody>';

            for (var i = 0; i < items.length; i++) {
                html += renderProjectRow(items[i]);
            }

            html += '</tbody></table></div>';
            $section.html(html);
        });
    }

    // Открыть модал добавления проекта
    function openAddProjectModal() {
        var formHtml =
            '<form id="form-add-project">' +
            '<div class="finance-form-row">' +
            '<label>Назва *</label>' +
            '<input type="text" name="name" class="finance-input" required placeholder="Наприклад: BMW X6 2026">' +
            '</div>' +
            '<div class="finance-form-row">' +
            '<label>Статус</label>' +
            '<select name="status" class="finance-select">' +
            '<option value="active">Активний</option>' +
            '<option value="planned">Заплановано</option>' +
            '<option value="completed">Завершений</option>' +
            '</select>' +
            '</div>' +
            '<div class="finance-form-row">' +
            '<label>Дата старту *</label>' +
            '<input type="date" name="date_start" class="finance-input" required>' +
            '</div>' +
            '<div class="finance-form-row">' +
            '<label>Дата закiнчення *</label>' +
            '<input type="date" name="date_end" class="finance-input" required>' +
            '</div>' +
            '<div class="finance-form-row">' +
            '<label>Бюджет план (UAH)</label>' +
            '<input type="number" name="budget_plan" class="finance-input" min="0" step="1000" placeholder="0">' +
            '</div>' +
            '<div class="finance-form-actions">' +
            '<button type="submit" class="zk-btn zk-btn-primary">Створити</button>' +
            '<button type="button" class="zk-btn" id="btn-cancel-add-project">Скасувати</button>' +
            '</div>' +
            '</form>';

        ZK.Modal.open('Новий проект', formHtml);

        $(document).on('click.addproject', '#btn-cancel-add-project', function() {
            ZK.Modal.close();
        });

        $(document).on('submit.addproject', '#form-add-project', function(e) {
            e.preventDefault();
            var $btn = $(this).find('[type="submit"]');
            $btn.prop('disabled', true);
            var d = {};
            $(this).serializeArray().forEach(function(f) { d[f.name] = f.value; });
            ZK.Api('projects.add', d, function(data, ok) {
                $btn.prop('disabled', false);
                $(document).off('.addproject');
                if (ok) {
                    ZK.Toast.success('Проект створено');
                    ZK.Modal.close();
                    loadList();
                } else {
                    ZK.Toast.error('Помилка створення');
                }
            });
        });
    }

    function renderProjectRow(p) {
        var income  = parseFloat(p.income) || 0;
        var expenses = parseFloat(p.expenses) || 0;
        var profit  = income - expenses;
        var margin  = income > 0 ? (profit / income * 100).toFixed(1) + '%' : '—';
        var profitClass = profit >= 0 ? 'positive' : 'negative';

        var dateRange = '';
        if (p.date_start || p.date_end) {
            dateRange = (p.date_start ? ZK.Format.date(p.date_start) : '?') +
                ' — ' +
                (p.date_end ? ZK.Format.date(p.date_end) : '...');
        } else {
            dateRange = '—';
        }

        return '<tr>' +
            '<td><strong>' + escHtml(p.name) + '</strong>' +
            (p.description ? '<br><span class="tx-desc-muted">' + escHtml(p.description) + '</span>' : '') +
            '</td>' +
            '<td><span class="status-badge status-' + escHtml(p.status || '') + '">' + STATUS_LABELS[p.status] + '</span></td>' +
            '<td>' + dateRange + '</td>' +
            '<td class="num">' + ZK.Format.money(p.income) + '</td>' +
            '<td class="num">' + ZK.Format.money(p.expenses) + '</td>' +
            '<td class="num ' + profitClass + '">' + ZK.Format.money(profit) + '</td>' +
            '<td class="num">' + margin + '</td>' +
            '<td>' +
            '<button class="finance-btn finance-btn-sm btn-project-detail" data-id="' + p.id + '">Деталi</button>' +
            '</td>' +
            '</tr>';
    }

    // ----------------------------------------------------------------
    // Detail view
    // ----------------------------------------------------------------
    function openDetail(id) {
        currentProjectId = id;
        txPage = 1;
        txFilters = {};

        var $section = $('#projects-section');
        $section.html('<div class="zk-skeleton" style="height:300px"></div>');

        ZK.Api('projects.get', { id: id }, function(project) {
            if (!project || !project.id) {
                $section.html('<div class="finance-empty-state"><p>Проект не знайдено</p></div>');
                return;
            }

            var addTxBtn = ZK.Config.canWrite
                ? '<button class="finance-btn finance-btn-primary" id="btn-add-tx">+ Додати транзакцiю</button>'
                : '';

            var html =
                '<div class="finance-breadcrumb">' +
                '<button class="finance-btn finance-btn-ghost" id="btn-back-projects">← Всi проекти</button>' +
                '</div>' +

                '<div class="finance-project-header">' +
                '<h2>' + escHtml(project.name) + '</h2>' +
                (project.description
                    ? '<p class="tx-desc-muted">' + escHtml(project.description) + '</p>'
                    : '') +
                '</div>' +

                renderPL(project) +

                '<div class="finance-section-header" style="margin-top:20px">' +
                '<h3>Журнал транзакцiй</h3>' + addTxBtn +
                '</div>' +

                renderFilterBar() +

                '<div id="tx-table-wrap">' +
                '<div class="zk-skeleton" style="height:150px"></div>' +
                '</div>' +
                '<div id="tx-pagination"></div>';

            $section.html(html);
            bindDetailEvents(id);
            loadTransactions(id, 1);
        });
    }

    function renderPL(project) {
        // PHP возвращает project.income и project.expenses (через FinanceProject::getById + getPL)
        // Также может быть project.pl.income если вложено
        var pl = (project.pl && project.pl.income !== undefined) ? project.pl : project;
        var income   = parseFloat(pl.income)   || 0;
        var expenses = parseFloat(pl.expenses) || 0;
        var profit   = income - expenses;
        var margin   = income > 0 ? (profit / income * 100).toFixed(1) : '0';
        var profitClass = profit >= 0 ? 'positive' : 'negative';

        return '<div class="finance-metrics-row">' +

            '<div class="finance-metric-card">' +
            '<div class="fm-label">Дохiд</div>' +
            '<div class="fm-value positive">' + ZK.Format.money(income) + '</div>' +
            '</div>' +

            '<div class="finance-metric-card">' +
            '<div class="fm-label">Витрати</div>' +
            '<div class="fm-value negative">' + ZK.Format.money(expenses) + '</div>' +
            '</div>' +

            '<div class="finance-metric-card">' +
            '<div class="fm-label">Прибуток</div>' +
            '<div class="fm-value ' + profitClass + '">' + ZK.Format.money(profit) + '</div>' +
            '</div>' +

            '<div class="finance-metric-card">' +
            '<div class="fm-label">Маржа</div>' +
            '<div class="fm-value ' + profitClass + '">' + margin + '%</div>' +
            '</div>' +

            '</div>';
    }

    // ----------------------------------------------------------------
    // Transaction table
    // ----------------------------------------------------------------
    function getCurrentGroup() {
        try {
            return localStorage.getItem(TX_GROUP_STORAGE_KEY) || '';
        } catch (e) { return ''; }
    }

    function setCurrentGroup(val) {
        try { localStorage.setItem(TX_GROUP_STORAGE_KEY, val); } catch (e) {}
    }

    function renderGroupButtons() {
        var current = getCurrentGroup();
        var html = '<div class="tx-group-btns" id="tx-group-btns">';
        for (var i = 0; i < TX_GROUP_OPTIONS.length; i++) {
            var opt = TX_GROUP_OPTIONS[i];
            var active = (opt.value === current) ? ' active' : '';
            html += '<button type="button" class="tx-group-btn' + active + '" data-group="' + opt.value + '">' + opt.label + '</button>';
        }
        html += '</div>';
        return html;
    }

    function renderFilterBar() {
        return renderGroupButtons() +
            '<div class="tx-filters-bar" id="tx-filters-bar">' +
            '<input type="date" class="tx-filter-date tx-filter" data-key="date_from" placeholder="дд.мм.рррр">' +
            '<input type="date" class="tx-filter-date tx-filter" data-key="date_to" placeholder="дд.мм.рррр">' +
            '<select class="tx-filter-select tx-filter" data-key="type">' +
            '<option value="">Всi типи</option>' +
            buildTypeOptions() +
            '</select>' +
            '<input type="text" class="tx-filter-search tx-filter" data-key="search" placeholder="Пошук...">' +
            '</div>';
    }

    function buildTypeOptions() {
        var opts = '';
        for (var key in TYPE_LABELS) {
            if (TYPE_LABELS.hasOwnProperty(key)) {
                opts += '<option value="' + key + '">' + TYPE_LABELS[key] + '</option>';
            }
        }
        return opts;
    }

    function loadTransactions(projectId, page) {
        txPage = page || 1;

        var params = { project_id: projectId, page: txPage, per_page: 50 };
        for (var k in txFilters) {
            if (txFilters.hasOwnProperty(k) && txFilters[k] !== '') {
                params[k] = txFilters[k];
            }
        }
        // Фильтр по группе расходов
        var currentGroup = getCurrentGroup();
        if (currentGroup !== '') {
            params.category_group = currentGroup;
        }

        var $wrap = $('#tx-table-wrap');
        $wrap.html('<div class="zk-skeleton" style="height:150px"></div>');

        ZK.Api('transactions.list', params, function(data) {
            var items      = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
            var pagination = (data && data.pagination) ? data.pagination : {};

            if (!items.length) {
                $wrap.html('<div class="finance-empty-state"><p>Немає транзакцiй</p></div>');
                $('#tx-pagination').html('');
                return;
            }

            var html = '<div class="tx-table-wrap">' +
                '<table class="tx-table" id="tx-table">' +
                '<thead><tr>' +
                '<th>Дата</th>' +
                '<th>Тип</th>' +
                '<th>Категорiя</th>' +
                '<th>Опис</th>' +
                '<th class="num">Сума</th>' +
                '<th>Джерело</th>' +
                '<th></th>' +
                '</tr></thead>' +
                '<tbody>';

            for (var i = 0; i < items.length; i++) {
                html += renderTransactionRow(items[i]);
            }

            html += '</tbody></table></div>';
            $wrap.html(html);

            renderPagination(pagination, projectId);
        });
    }

    function renderTransactionRow(tx) {
        var typeLabel = TYPE_LABELS[tx.type] || tx.type || '—';
        var isAuto    = (tx.source_type === 'crm_auto');
        var isManual  = (tx.source_type === 'manual');
        var amount    = parseFloat(tx.amount_uah) || 0;
        var amtClass  = (tx.type === 'income' || tx.type === 'income_extra') ? 'positive' : 'negative';

        var typeBadge = '<span class="tx-type-badge ' + escHtml(tx.type) + '">' + typeLabel + '</span>';
        if (isAuto) {
            typeBadge += ' <span class="tx-type-badge auto-tag">[AUTO]</span>';
        }

        var actions = '';
        if (isManual && ZK.Config.canWrite) {
            actions =
                '<button class="finance-btn finance-btn-sm btn-edit-tx" data-id="' + tx.id + '">Ред</button> ' +
                '<button class="finance-btn finance-btn-sm finance-btn-danger btn-delete-tx" data-id="' + tx.id + '">Вид</button>';
        }

        return '<tr>' +
            '<td class="col-date">' + escHtml(tx.transaction_date ? tx.transaction_date.substr(0, 10) : '—') + '</td>' +
            '<td>' + typeBadge + '</td>' +
            '<td>' + escHtml(tx.category || '—') + '</td>' +
            '<td>' + escHtml(tx.description || '—') + '</td>' +
            '<td class="col-amount ' + amtClass + '">' + ZK.Format.money(amount) + '</td>' +
            '<td class="col-source">' + escHtml(tx.source_type || '—') + '</td>' +
            '<td class="col-actions">' + actions + '</td>' +
            '</tr>';
    }

    function renderPagination(pagination, projectId) {
        var $pag = $('#tx-pagination');
        if (!pagination || !pagination.total) { $pag.html(''); return; }

        var total    = parseInt(pagination.total,    10) || 0;
        var perPage  = parseInt(pagination.per_page, 10) || 50;
        var page     = parseInt(pagination.page,     10) || 1;
        var pages    = Math.ceil(total / perPage);

        if (pages <= 1) { $pag.html(''); return; }

        var html = '<div class="tx-pagination">';

        html += '<button class="pag-btn" data-page="' + (page - 1) + '"' +
            (page <= 1 ? ' disabled' : '') + '>‹</button>';

        var start = Math.max(1, page - 2);
        var end   = Math.min(pages, page + 2);
        for (var i = start; i <= end; i++) {
            html += '<button class="pag-btn' + (i === page ? ' active' : '') +
                '" data-page="' + i + '">' + i + '</button>';
        }

        html += '<button class="pag-btn" data-page="' + (page + 1) + '"' +
            (page >= pages ? ' disabled' : '') + '>›</button>';

        html += '<span style="font-size:0.78rem;color:var(--text-muted);margin-left:4px">' +
            page + '/' + pages + ' (' + total + ')</span>';
        html += '</div>';

        $pag.html(html);
    }

    // ----------------------------------------------------------------
    // Add transaction modal
    // ----------------------------------------------------------------
    function openAddModal(projectId) {
        var typeOptions = '';
        for (var key in TYPE_LABELS) {
            if (TYPE_LABELS.hasOwnProperty(key)) {
                typeOptions += '<option value="' + key + '">' + TYPE_LABELS[key] + '</option>';
            }
        }

        var formHtml =
            '<form id="form-add-tx">' +

            '<div class="finance-form-row">' +
            '<label>Дата *</label>' +
            '<input type="date" name="date" class="finance-input" required value="' + todayISO() + '">' +
            '</div>' +

            '<div class="finance-form-row">' +
            '<label>Тип *</label>' +
            '<select name="type" class="finance-select" id="tx-type-select" required>' +
            typeOptions +
            '</select>' +
            '</div>' +

            '<div class="finance-form-row">' +
            '<label>Категорiя</label>' +
            '<input type="text" name="category" class="finance-input" placeholder="Необов\'язково">' +
            '</div>' +

            '<div class="finance-form-row">' +
            '<label>Опис *</label>' +
            '<input type="text" name="description" class="finance-input" required placeholder="Опис транзакцiї">' +
            '</div>' +

            '<div class="finance-form-row">' +
            '<label>Сума UAH *</label>' +
            '<input type="number" name="amount" class="finance-input" step="0.01" min="0" required placeholder="0.00">' +
            '</div>' +

            '<div class="finance-form-row" id="card-select-row" style="display:none">' +
            '<label>Картка</label>' +
            '<select name="card_id" class="finance-select" id="tx-card-select">' +
            '<option value="">— виберiть —</option>' +
            '</select>' +
            '</div>' +

            '<div class="finance-form-row">' +
            '<label>Нотатки</label>' +
            '<textarea name="notes" class="finance-input" rows="2" placeholder="Необов\'язково"></textarea>' +
            '</div>' +

            '<div class="finance-form-actions">' +
            '<button type="submit" class="finance-btn finance-btn-primary">Зберегти</button>' +
            '<button type="button" class="finance-btn finance-btn-ghost" id="btn-cancel-tx">Скасувати</button>' +
            '</div>' +

            '</form>';

        ZK.Modal.open('Додати транзакцiю', formHtml);

        // Load cards for card-select
        ZK.Api('cards.list', {}, function(cardsData) {
            var cards = (cardsData && cardsData.items)
                ? cardsData.items
                : (Array.isArray(cardsData) ? cardsData : []);
            var $sel = $('#tx-card-select');
            for (var i = 0; i < cards.length; i++) {
                $sel.append('<option value="' + cards[i].id + '">' + escHtml(cards[i].name) + '</option>');
            }
        });

        // Show/hide card select based on type
        $(document).on('change.addtx', '#tx-type-select', function() {
            var type = $(this).val();
            if (CARD_TYPES.indexOf(type) !== -1) {
                $('#card-select-row').show();
            } else {
                $('#card-select-row').hide();
            }
        });

        $(document).on('click.addtx', '#btn-cancel-tx', function() {
            ZK.Modal.close();
        });

        $(document).on('submit.addtx', '#form-add-tx', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('[type="submit"]');
            $btn.prop('disabled', true);
            var formData = { project_id: projectId };
            $form.serializeArray().forEach(function(field) {
                formData[field.name] = field.value;
            });

            ZK.Api('transactions.add', formData, function(data, ok) {
                $btn.prop('disabled', false);
                $(document).off('.addtx');
                if (ok) {
                    ZK.Toast.success('Транзакцiю додано');
                    ZK.Modal.close();
                    loadTransactions(projectId, 1);
                } else {
                    ZK.Toast.error('Помилка збереження');
                }
            });
        });
    }

    // ----------------------------------------------------------------
    // Event delegation for detail view
    // ----------------------------------------------------------------
    // Хранит текущий listener чтобы удалить перед добавлением нового
    var _detailClickHandler = null;

    function bindDetailEvents(projectId) {
        // Удалить предыдущий listener если был
        if (_detailClickHandler) {
            document.removeEventListener('click', _detailClickHandler);
            _detailClickHandler = null;
        }
        $(document).off('.txfilters');

        // Use native delegation to avoid jQuery 4 View Transition issue
        function onDetailClick(e) {
            // Pagination
            var pagBtn = e.target.closest('.pag-btn');
            if (pagBtn && !pagBtn.disabled) {
                var pg = parseInt(pagBtn.getAttribute('data-page'), 10);
                if (pg > 0) loadTransactions(projectId, pg);
                return;
            }

            // Back to list
            if (e.target.closest('#btn-back-projects')) {
                document.removeEventListener('click', onDetailClick);
                ZK.Router.setSection('projects');
                return;
            }

            // Add transaction
            if (e.target.closest('#btn-add-tx')) {
                openAddModal(projectId);
                return;
            }

            // Edit transaction
            var editBtn = e.target.closest('.btn-edit-tx');
            if (editBtn) {
                var txId = parseInt(editBtn.getAttribute('data-id'), 10);
                openEditModal(txId, projectId);
                return;
            }

            // Delete transaction
            var delBtn = e.target.closest('.btn-delete-tx');
            if (delBtn) {
                var delId = parseInt(delBtn.getAttribute('data-id'), 10);
                if (window.confirm('Вилучити транзакцiю?')) {
                    ZK.Api('transactions.delete', { id: delId }, function() {
                        ZK.Toast.success('Видалено');
                        loadTransactions(projectId, txPage);
                    });
                }
                return;
            }
        }

        _detailClickHandler = onDetailClick;
        document.addEventListener('click', onDetailClick);

        // Filters — delegated via jQuery on document (safe: not inside View Transition)
        $(document).on('change.txfilters input.txfilters', '.tx-filter', function() {
            var key = $(this).data('key');
            var val = (this.value || '').trim();
            txFilters[key] = val;
            loadTransactions(projectId, 1);
        });

        // Group filter buttons
        $(document).on('click.txfilters', '.tx-group-btn', function() {
            var group = $(this).data('group');
            setCurrentGroup(group);
            // Обновляем активную кнопку
            $('.tx-group-btn').removeClass('active');
            $(this).addClass('active');
            loadTransactions(projectId, 1);
        });
    }

    // ----------------------------------------------------------------
    // Edit transaction modal
    // ----------------------------------------------------------------
    function openEditModal(txId, projectId) {
        ZK.Api('transactions.get', { id: txId }, function(tx) {
            if (!tx || !tx.id) { ZK.Toast.error('Не вдалося завантажити'); return; }

            var typeOptions = '';
            for (var key in TYPE_LABELS) {
                if (TYPE_LABELS.hasOwnProperty(key)) {
                    var sel = (key === tx.type) ? ' selected' : '';
                    typeOptions += '<option value="' + key + '"' + sel + '>' + TYPE_LABELS[key] + '</option>';
                }
            }

            var formHtml =
                '<form id="form-edit-tx">' +

                '<div class="finance-form-row">' +
                '<label>Дата *</label>' +
                '<input type="date" name="transaction_date" class="finance-input" required value="' + escHtml((tx.transaction_date || '').substr(0, 10)) + '">' +
                '</div>' +

                '<div class="finance-form-row">' +
                '<label>Тип</label>' +
                '<select name="type" class="finance-select">' + typeOptions + '</select>' +
                '</div>' +

                '<div class="finance-form-row">' +
                '<label>Категорiя</label>' +
                '<input type="text" name="category" class="finance-input" value="' + escHtml(tx.category || '') + '">' +
                '</div>' +

                '<div class="finance-form-row">' +
                '<label>Опис *</label>' +
                '<input type="text" name="description" class="finance-input" required value="' + escHtml(tx.description || '') + '">' +
                '</div>' +

                '<div class="finance-form-row">' +
                '<label>Сума UAH *</label>' +
                '<input type="number" name="amount_uah" class="finance-input" step="0.01" min="0" required value="' + escHtml(tx.amount_uah || '') + '">' +
                '</div>' +

                '<div class="finance-form-row">' +
                '<label>Нотатки</label>' +
                '<textarea name="notes" class="finance-input" rows="2">' + escHtml(tx.notes || '') + '</textarea>' +
                '</div>' +

                '<div class="finance-form-actions">' +
                '<button type="submit" class="finance-btn finance-btn-primary">Зберегти</button>' +
                '<button type="button" class="finance-btn finance-btn-ghost" id="btn-cancel-edit-tx">Скасувати</button>' +
                '</div>' +

                '</form>';

            ZK.Modal.open('Редагувати транзакцiю', formHtml);

            $(document).on('click.edittx', '#btn-cancel-edit-tx', function() {
                ZK.Modal.close();
            });

            $(document).on('submit.edittx', '#form-edit-tx', function(e) {
                e.preventDefault();
                var formData = { id: txId };
                $(this).serializeArray().forEach(function(field) {
                    formData[field.name] = field.value;
                });
                ZK.Api('transactions.update', formData, function() {
                    $(document).off('.edittx');
                    ZK.Toast.success('Збережено');
                    ZK.Modal.close();
                    loadTransactions(projectId, txPage);
                });
            });
        });
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

    function todayISO() {
        var d = new Date();
        var mm = ('0' + (d.getMonth() + 1)).slice(-2);
        var dd = ('0' + d.getDate()).slice(-2);
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    // ----------------------------------------------------------------
    // Event: back to list from list-view delegate
    // ----------------------------------------------------------------
    $(document).on('click', '.btn-project-detail', function() {
        var id = parseInt($(this).data('id'), 10);
        ZK.Router.set('projects', id);
    });

    $(document).on('click', '#btn-add-project', function() {
        openAddProjectModal();
    });

    return {
        load: load,
        openDetail: openDetail
    };

})();
