// === FINANCE-ADD-EXPENSE.JS ===
// finance/assets/js/finance-add-expense.js
// НАЗНАЧЕНИЕ: Модалка быстрого добавления любого расхода (без UTM)
// ИСПОЛЬЗУЕТСЯ ДЛЯ: покупка авто, оренда офісу, бухгалтерія, підрядники и т.д.
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Toast), api/handler.php (transactions.add_expense)
// РАЗМЕР: ~280 строк

ZK.AddExpense = (function() {

    var MODAL_ID = 'add-expense-modal';
    var projects = [];
    var cards    = [];
    var isLoading = false;

    // ─── Public API ────────────────────────────────────────────────────────

    function open() {
        renderModal();
        loadProjects();
        loadCards();
        showModal();
    }

    function close() {
        hideModal();
    }

    // ─── Render ────────────────────────────────────────────────────────────

    function renderModal() {
        // Если модалка уже есть - не создаём заново
        if (document.getElementById(MODAL_ID)) return;

        var categoriesHtml = renderCategoriesOptgroup();

        var html =
            '<div class="finance-modal-overlay" id="' + MODAL_ID + '">' +
                '<div class="finance-modal-box add-expense-box">' +
                    '<div class="finance-modal-header">' +
                        '<h3>➕ Додати витрату</h3>' +
                        '<button type="button" class="finance-modal-close" id="ae-close-btn">✕</button>' +
                    '</div>' +
                    '<form class="add-expense-form" id="add-expense-form">' +

                        '<div class="form-row">' +
                            '<div class="form-col">' +
                                '<label>Дата <span class="req">*</span></label>' +
                                '<input type="date" name="transaction_date" id="ae-date" class="finance-input" required>' +
                            '</div>' +
                            '<div class="form-col">' +
                                '<label>Проект <span class="req">*</span></label>' +
                                '<select name="project_id" id="ae-project" class="finance-select" required>' +
                                    '<option value="">— Завантаження... —</option>' +
                                '</select>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-col">' +
                                '<label>Категорія <span class="req">*</span></label>' +
                                '<select name="category" id="ae-category" class="finance-select" required>' +
                                    categoriesHtml +
                                '</select>' +
                            '</div>' +
                            '<div class="form-col">' +
                                '<label>Сума (₴) <span class="req">*</span></label>' +
                                '<input type="number" name="amount_uah" id="ae-amount" class="finance-input" step="0.01" min="0.01" placeholder="0.00" required>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-col form-col-full">' +
                                '<label>Картка <small class="text-muted">(опцiонально, баланс списується автоматично)</small></label>' +
                                '<select name="card_id" id="ae-card" class="finance-select">' +
                                    '<option value="0">— Без прив\'язки —</option>' +
                                '</select>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-col form-col-full">' +
                                '<label>Опис <span class="req">*</span></label>' +
                                '<input type="text" name="description" id="ae-description" class="finance-input" maxlength="255" placeholder="Наприклад: BMW X5 Hybrid 2026 з салону" required>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-col form-col-full">' +
                                '<label>Коментар <small class="text-muted">(опцiонально)</small></label>' +
                                '<textarea name="notes" id="ae-notes" class="finance-input" rows="2"></textarea>' +
                            '</div>' +
                        '</div>' +

                        '<div class="finance-modal-footer">' +
                            '<button type="button" class="zk-btn zk-btn-secondary" id="ae-cancel-btn">Скасувати</button>' +
                            '<button type="submit" class="zk-btn zk-btn-primary" id="ae-submit-btn">💾 Зберегти витрату</button>' +
                        '</div>' +

                    '</form>' +
                '</div>' +
            '</div>';

        $('body').append(html);

        // Устанавливаем сегодняшнюю дату по умолчанию
        $('#ae-date').val(new Date().toISOString().slice(0, 10));

        // Bind events
        bindEvents();
    }

    function renderCategoriesOptgroup() {
        // ВАЖНО: Chrome на Windows игнорирует стили <optgroup> - получаются белые полосы.
        // Решение: плоский список с disabled <option> в роли заголовков групп.
        var groups = window.FINANCE_CATEGORIES || {};
        var html = '<option value="">— Оберіть —</option>';

        var groupOrder = ['advertising', 'production', 'operations', 'team', 'other'];

        for (var i = 0; i < groupOrder.length; i++) {
            var gKey = groupOrder[i];
            if (!groups[gKey]) continue;

            var grp = groups[gKey];
            var label = grp.label || gKey;

            // Заголовок группы - disabled option (стилизуется в CSS)
            html += '<option disabled class="cat-group-header" value="__group_' + escHtml(gKey) + '">'
                 +  '── ' + escHtml(label) + ' ──'
                 +  '</option>';

            var items = grp.items || {};
            for (var itemKey in items) {
                if (items.hasOwnProperty(itemKey)) {
                    html += '<option value="' + escHtml(itemKey) + '">'
                         +  '\u00A0\u00A0\u00A0' + escHtml(items[itemKey])
                         +  '</option>';
                }
            }
        }

        return html;
    }

    // ─── Data loading ──────────────────────────────────────────────────────

    function loadProjects() {
        ZK.Api('projects.list', {}, function(data, ok) {
            var items = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
            projects = items;

            var $sel = $('#ae-project');
            $sel.empty();
            $sel.append('<option value="">— Оберіть —</option>');

            // Приоритет выбора проекта:
            // 1. Если на дашборде выбран фильтр проекта - используем его
            // 2. Иначе - активный проект из списка
            var dashboardFilter = 0;
            if (typeof ZK.Dashboard !== 'undefined' && ZK.Dashboard.getSelectedProjectId) {
                dashboardFilter = ZK.Dashboard.getSelectedProjectId() || 0;
            }

            var activeId = null;
            for (var i = 0; i < items.length; i++) {
                var p = items[i];
                $sel.append('<option value="' + p.id + '">' + escHtml(p.name) + '</option>');
                if (p.status === 'active') activeId = p.id;
            }

            // Установить значение: сначала фильтр дашборда, потом активный
            if (dashboardFilter > 0) {
                $sel.val(dashboardFilter);
            } else if (activeId) {
                $sel.val(activeId);
            }
        });
    }

    function loadCards() {
        ZK.Api('cards.list', {}, function(data, ok) {
            var items = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
            cards = items;

            var $sel = $('#ae-card');
            for (var i = 0; i < items.length; i++) {
                var c = items[i];
                var bal = parseFloat(c.balance_uah) || 0;
                var label = (c.bank_name || '') + ' •••' + (c.last4 || '') +
                            ' (баланс: ' + formatMoney(bal) + ' ₴)';
                $sel.append('<option value="' + c.id + '">' + escHtml(label) + '</option>');
            }
        });
    }

    // ─── Submit ────────────────────────────────────────────────────────────

    function submit(e) {
        e.preventDefault();
        if (isLoading) return;

        var data = {
            project_id:       parseInt($('#ae-project').val(), 10) || 0,
            category:         $('#ae-category').val(),
            amount_uah:       parseFloat($('#ae-amount').val()) || 0,
            description:      ($('#ae-description').val() || '').trim(),
            transaction_date: $('#ae-date').val(),
            card_id:          parseInt($('#ae-card').val(), 10) || 0,
            notes:            ($('#ae-notes').val() || '').trim(),
        };

        // Client-side валидация
        if (data.project_id <= 0) {
            ZK.Toast.error('Оберіть проект');
            return;
        }
        if (!data.category) {
            ZK.Toast.error('Оберіть категорію');
            return;
        }
        if (data.amount_uah <= 0) {
            ZK.Toast.error('Введіть суму більше 0');
            return;
        }
        if (!data.description) {
            ZK.Toast.error('Введіть опис');
            return;
        }

        isLoading = true;
        $('#ae-submit-btn').prop('disabled', true).text('Збереження...');

        ZK.Api('transactions.add_expense', data, function(response, ok) {
            isLoading = false;
            $('#ae-submit-btn').prop('disabled', false).html('💾 Зберегти витрату');

            if (!ok) {
                ZK.Toast.error('Помилка збереження');
                return;
            }

            ZK.Toast.success('Витрату додано');
            hideModal();

            // Оповестить другие модули что расход добавлен - они перезагрузятся
            try {
                window.dispatchEvent(new CustomEvent('zk:expense-added', {
                    detail: response
                }));
            } catch (e) {}
        });
    }

    // ─── Modal show/hide ───────────────────────────────────────────────────

    function showModal() {
        var $m = $('#' + MODAL_ID);
        $m.addClass('visible');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            $('#ae-project').focus();
        }, 100);
    }

    function hideModal() {
        var $m = $('#' + MODAL_ID);
        $m.removeClass('visible');
        document.body.style.overflow = '';
        // Сброс формы
        $('#add-expense-form')[0] && $('#add-expense-form')[0].reset();
        $('#ae-date').val(new Date().toISOString().slice(0, 10));
    }

    // ─── Events ────────────────────────────────────────────────────────────

    function bindEvents() {
        $(document).off('click.addexpense');
        $(document).on('click.addexpense', '#ae-close-btn, #ae-cancel-btn', function(e) {
            e.preventDefault();
            hideModal();
        });
        // Клик по overlay (но не по боксу) - закрыть
        $(document).on('click.addexpense', '#' + MODAL_ID, function(e) {
            if (e.target.id === MODAL_ID) hideModal();
        });
        // Submit формы
        $(document).on('submit.addexpense', '#add-expense-form', submit);
        // Escape для закрытия
        $(document).on('keydown.addexpense', function(e) {
            if (e.key === 'Escape' && $('#' + MODAL_ID).hasClass('visible')) {
                hideModal();
            }
        });
        // Глобальная кнопка открытия
        $(document).on('click.addexpense', '#btn-open-add-expense', function(e) {
            e.preventDefault();
            open();
        });
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatMoney(n) {
        if (typeof ZK !== 'undefined' && ZK.Format && ZK.Format.money) {
            return ZK.Format.money(n);
        }
        return (parseFloat(n) || 0).toLocaleString('uk-UA', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    // Автобинд при загрузке (на случай если кнопка уже в DOM)
    $(function() {
        bindEvents();
    });

    return {
        open: open,
        close: close
    };

})();
