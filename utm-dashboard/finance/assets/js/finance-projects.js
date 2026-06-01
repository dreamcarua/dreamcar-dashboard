// === FINANCE-PROJECTS.JS ===
// finance/assets/js/finance-projects.js
// НАЗНАЧЕНИЕ: Модуль проектiв - список P&L карток та детальний перегляд з журналом транзакцiй
// СВЯЗИ: finance-app.js (ZK namespace), api/handler.php
// РАЗМЕР: ~380 строк

ZK.Projects = (function() {
    var currentProjectId = null;
    var txPage = 1;
    var txFilters = {};

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
                ? '<button class="finance-btn finance-btn-primary" id="btn-add-project">+ Новий проект</button>'
                : '';

            var html = '<div class="finance-section-header">' +
                '<h2>Проекти</h2>' + addBtn +
                '</div>' +
                '<div class="finance-table-wrap">' +
                '<table class="finance-table" id="projects-table">' +
                '<thead><tr>' +
                '<th>Назва</th>' +
                '<th>Статус</th>' +
                '<th>Дати</th>' +
                '<th class="num">Дохiд</th>' +
                '<th class="num">Витрати</th>' +
                '<th class="num">Прибуток</th>' +
                '<th class="num">Маржа</th>' +
                '<th>Дiї</th>' +
                '</tr></thead>' +
                '<tbody>';

            for (var i = 0; i < items.length; i++) {
                html += renderProjectRow(items[i]);
            }

            html += '</tbody></table></div>';
            $section.html(html);
        });
    }

    function renderProjectRow(p) {
        var profit  = (parseFloat(p.total_income) || 0) - (parseFloat(p.total_expenses) || 0);
        var margin  = (parseFloat(p.total_income) || 0) > 0
            ? (profit / parseFloat(p.total_income) * 100).toFixed(1) + '%'
            : '—';
        var profitClass = profit >= 0 ? 'positive' : 'negative';

        var dateRange = '';
        if (p.start_date || p.end_date) {
            dateRange = (p.start_date ? ZK.Format.date(p.start_date) : '?') +
                ' — ' +
                (p.end_date ? ZK.Format.date(p.end_date) : '...');
        } else {
            dateRange = '—';
        }

        return '<tr>' +
            '<td><strong>' + escHtml(p.name) + '</strong>' +
            (p.description ? '<br><span class="tx-desc-muted">' + escHtml(p.description) + '</span>' : '') +
            '</td>' +
            '<td><span class="status-badge ' + escHtml(p.status || '') + '">' + escHtml(p.status || '—') + '</span></td>' +
            '<td>' + dateRange + '</td>' +
            '<td class="num">' + ZK.Format.money(p.total_income) + '</td>' +
            '<td class="num">' + ZK.Format.money(p.total_expenses) + '</td>' +
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
        var income   = parseFloat(project.total_income)   || 0;
        var expenses = parseFloat(project.total_expenses) || 0;
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
    function renderFilterBar() {
        return '<div class="finance-filters" id="tx-filters-bar">' +
            '<input type="date" class="finance-input tx-filter" data-key="date_from" placeholder="Вiд">' +
            '<input type="date" class="finance-input tx-filter" data-key="date_to" placeholder="До">' +
            '<select class="finance-select tx-filter" data-key="type">' +
            '<option value="">Всi типи</option>' +
            buildTypeOptions() +
            '</select>' +
            '<input type="text" class="finance-input tx-filter" data-key="search" placeholder="Пошук...">' +
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

            var html = '<div class="finance-table-wrap">' +
                '<table class="finance-table" id="tx-table">' +
                '<thead><tr>' +
                '<th>Дата</th>' +
                '<th>Тип</th>' +
                '<th>Категорiя</th>' +
                '<th>Опис</th>' +
                '<th class="num">Сума</th>' +
                '<th>Джерело</th>' +
                '<th>Дiї</th>' +
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
            '<td>' + escHtml(tx.transaction_date ? tx.transaction_date.substr(0, 10) : '—') + '</td>' +
            '<td>' + typeBadge + '</td>' +
            '<td>' + escHtml(tx.category || '—') + '</td>' +
            '<td>' + escHtml(tx.description || '—') + '</td>' +
            '<td class="num ' + amtClass + '">' + ZK.Format.money(amount) + '</td>' +
            '<td>' + escHtml(tx.source_type || '—') + '</td>' +
            '<td>' + actions + '</td>' +
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

        var html = '<div class="finance-pagination">';

        html += '<button class="finance-btn finance-btn-sm pag-btn" data-page="' + (page - 1) + '"' +
            (page <= 1 ? ' disabled' : '') + '>‹</button>';

        var start = Math.max(1, page - 2);
        var end   = Math.min(pages, page + 2);
        for (var i = start; i <= end; i++) {
            html += '<button class="finance-btn finance-btn-sm pag-btn' +
                (i === page ? ' active' : '') +
                '" data-page="' + i + '">' + i + '</button>';
        }

        html += '<button class="finance-btn finance-btn-sm pag-btn" data-page="' + (page + 1) + '"' +
            (page >= pages ? ' disabled' : '') + '>›</button>';

        html += '<span class="pag-info"> ' + page + ' / ' + pages + ' (всього ' + total + ')</span>';
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
    function bindDetailEvents(projectId) {
        // Use native delegation to avoid jQuery 4 View Transition issue
        document.addEventListener('click', function onDetailClick(e) {
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
        });

        // Filters — delegated via jQuery on document (safe: not inside View Transition)
        $(document).on('change.txfilters input.txfilters', '.tx-filter', function() {
            var key = $(this).data('key');
            var val = (this.value || '').trim();
            txFilters[key] = val;
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

    return {
        load: load,
        openDetail: openDetail
    };

})();
