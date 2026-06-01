// === FINANCE-PAYROLL.JS ===
// finance/assets/js/finance-payroll.js
// НАЗНАЧЕНИЕ: Модуль зарплат та виплат спiвробiтникам
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Toast, ZK.Modal, ZK.Format, ZK.Config), api/handler.php
// РАЗМЕР: ~320 строк

ZK.Payroll = (function() {

    var employees = [];

    // ─── Загрузка ────────────────────────────────────────────────────────────

    function load() {
        loadBoth();
    }

    function loadBoth() {
        loadEmployees();
        loadPayroll({});
    }

    function loadEmployees() {
        var $wrap = $('#payroll-employees-wrap');
        $wrap.html('<div class="zk-loading">Завантаження...</div>');

        ZK.Api('employees.list', {}, function(data, ok) {
            if (!ok || !data) {
                $wrap.html('<div class="zk-empty">Помилка завантаження</div>');
                return;
            }
            employees = data.items || data || [];
            renderEmployees(employees);
        });
    }

    function loadPayroll(filters) {
        var $wrap = $('#payroll-journal-wrap');
        $wrap.html('<div class="zk-loading">Завантаження...</div>');

        ZK.Api('payroll.list', filters || {}, function(data, ok) {
            if (!ok || !data) {
                $wrap.html('<div class="zk-empty">Помилка завантаження</div>');
                return;
            }
            renderPayroll(data);
        });
    }

    // ─── Рендер спiвробiтникiв ───────────────────────────────────────────────

    function renderEmployees(list) {
        var canWrite = ZK.Config.canWrite;
        var html = '<div class="payroll-block">';
        html += '<div class="payroll-block-header">';
        html += '<h3 class="payroll-block-title">Спiвробiтники</h3>';
        if (canWrite) {
            html += '<button class="zk-btn zk-btn-sm zk-btn-primary" id="btn-add-employee">+ Спiвробiтник</button>';
        }
        html += '</div>';

        if (!list || list.length === 0) {
            html += '<div class="zk-empty">Спiвробiтникiв немає</div>';
            html += '</div>';
            $('#payroll-employees-wrap').html(html);
            bindEmployeeEvents();
            return;
        }

        html += '<div class="zk-table-wrap"><table class="zk-table">';
        html += '<thead><tr>';
        html += '<th>Iм\'я</th><th>Роль</th><th>Тип</th>';
        html += '<th>Виплачено (мiс)</th><th>Виплачено (всього)</th>';
        html += '<th>Статус</th><th>Дiї</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < list.length; i++) {
            var emp = list[i];
            var typLabel = emp.employee_type === 'contractor' ? 'Пiдрядник' : 'Штатний';
            var statusBadge = emp.active
                ? '<span class="zk-badge zk-badge-green">Активний</span>'
                : '<span class="zk-badge zk-badge-gray">Архiв</span>';
            var toggleLabel = emp.active ? 'Архiв' : 'Активн.';

            html += '<tr data-emp-id="' + emp.id + '">';
            html += '<td>' + escHtml(emp.name || '') + '</td>';
            html += '<td>' + escHtml(emp.role || '') + '</td>';
            html += '<td>' + typLabel + '</td>';
            html += '<td>' + ZK.Format.money(emp.paid_month) + '</td>';
            html += '<td>' + ZK.Format.money(emp.paid_total) + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td class="zk-actions">';
            if (canWrite) {
                html += '<button class="zk-btn zk-btn-xs zk-btn-primary btn-add-payroll" data-id="' + emp.id + '">+ Виплата</button> ';
                html += '<button class="zk-btn zk-btn-xs zk-btn-secondary btn-toggle-employee" data-id="' + emp.id + '">' + toggleLabel + '</button>';
            }
            html += '</td></tr>';
        }

        html += '</tbody></table></div>';
        html += '</div>';

        $('#payroll-employees-wrap').html(html);
        bindEmployeeEvents();
    }

    function bindEmployeeEvents() {
        var canWrite = ZK.Config.canWrite;
        if (!canWrite) { return; }

        $(document).off('click.payroll-emp');
        $(document).on('click.payroll-emp', '#btn-add-employee', function() {
            openAddEmployeeModal();
        });
        $(document).on('click.payroll-emp', '.btn-add-payroll', function() {
            var id = parseInt($(this).data('id'), 10);
            openAddPayrollModal(id);
        });
        $(document).on('click.payroll-emp', '.btn-toggle-employee', function() {
            var id = parseInt($(this).data('id'), 10);
            ZK.Api('employees.toggle', { id: id }, function(data, ok) {
                if (ok) {
                    ZK.Toast.success('Статус змiнено');
                    loadEmployees();
                } else {
                    ZK.Toast.error('Помилка змiни статусу');
                }
            });
        });
    }

    // ─── Рендер журналу виплат ───────────────────────────────────────────────

    function renderPayroll(result) {
        var items = result.items || result || [];
        var canWrite = ZK.Config.canWrite;

        var html = '<div class="payroll-block">';
        html += '<div class="payroll-block-header">';
        html += '<h3 class="payroll-block-title">Журнал виплат</h3>';
        html += '</div>';

        // Filters
        html += '<div class="payroll-filters">';
        html += '<input type="month" id="filter-payroll-month" class="zk-input zk-input-sm" placeholder="Мiсяць">';
        html += '<select id="filter-payroll-employee" class="zk-select zk-select-sm">';
        html += '<option value="">Всi спiвробiтники</option>';
        for (var e = 0; e < employees.length; e++) {
            html += '<option value="' + employees[e].id + '">' + escHtml(employees[e].name || '') + '</option>';
        }
        html += '</select>';
        html += '<select id="filter-payroll-status" class="zk-select zk-select-sm">';
        html += '<option value="">Всi статуси</option>';
        html += '<option value="pending">Очiкує</option>';
        html += '<option value="paid">Виплачено</option>';
        html += '</select>';
        html += '<button class="zk-btn zk-btn-sm zk-btn-secondary" id="btn-apply-payroll-filters">Фiльтр</button>';
        html += '</div>';

        if (!items || items.length === 0) {
            html += '<div class="zk-empty">Виплат немає</div>';
            html += '</div>';
            $('#payroll-journal-wrap').html(html);
            bindPayrollFilterEvents();
            return;
        }

        html += '<div class="zk-table-wrap"><table class="zk-table">';
        html += '<thead><tr>';
        html += '<th>Дата</th><th>Спiвробiтник</th><th>Проект</th>';
        html += '<th>Сума</th><th>Перiод</th><th>Статус</th><th>Дiї</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < items.length; i++) {
            var row = items[i];
            var statusBadge = row.status === 'paid'
                ? '<span class="zk-badge zk-badge-green">Виплачено</span>'
                : '<span class="zk-badge zk-badge-orange">Очiкує</span>';

            html += '<tr data-payroll-id="' + row.id + '">';
            html += '<td>' + ZK.Format.date(row.date) + '</td>';
            html += '<td>' + escHtml(row.employee_name || '') + '</td>';
            html += '<td>' + escHtml(row.project_name || '') + '</td>';
            html += '<td>' + ZK.Format.money(row.amount) + '</td>';
            html += '<td>' + escHtml(row.period || '') + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td class="zk-actions">';
            if (canWrite && row.status !== 'paid') {
                html += '<button class="zk-btn zk-btn-xs zk-btn-success btn-mark-paid" data-id="' + row.id + '">Виплатити</button>';
            }
            html += '</td></tr>';
        }

        html += '</tbody></table></div>';
        html += '</div>';

        $('#payroll-journal-wrap').html(html);
        bindPayrollFilterEvents();
        bindPayrollRowEvents();
    }

    function bindPayrollFilterEvents() {
        $(document).off('click.payroll-filter');
        $(document).on('click.payroll-filter', '#btn-apply-payroll-filters', function() {
            var filters = {
                month: $('#filter-payroll-month').val() || '',
                employee_id: $('#filter-payroll-employee').val() || '',
                status: $('#filter-payroll-status').val() || ''
            };
            loadPayroll(filters);
        });
    }

    function bindPayrollRowEvents() {
        if (!ZK.Config.canWrite) { return; }
        $(document).off('click.payroll-rows');
        $(document).on('click.payroll-rows', '.btn-mark-paid', function() {
            var id = parseInt($(this).data('id'), 10);
            markPaid(id);
        });
    }

    // ─── Позначити виплачено ─────────────────────────────────────────────────

    function markPaid(id) {
        if (!window.confirm('Позначити виплату як виплачену?')) { return; }
        ZK.Api('payroll.mark_paid', { id: id }, function(data, ok) {
            if (ok) {
                ZK.Toast.success('Виплату позначено як виплачену');
                loadBoth();
            } else {
                ZK.Toast.error('Помилка оновлення виплати');
            }
        });
    }

    // ─── Модал: додати спiвробiтника ─────────────────────────────────────────

    function openAddEmployeeModal() {
        var html = '<div class="modal-header"><h3>Новий спiвробiтник</h3></div>';
        html += '<div class="modal-body">';
        html += '<form id="form-add-employee">';
        html += '<div class="form-row"><label>Iм\'я</label>';
        html += '<input type="text" name="name" class="zk-input" required></div>';
        html += '<div class="form-row"><label>Роль</label>';
        html += '<input type="text" name="role" class="zk-input"></div>';
        html += '<div class="form-row"><label>Тип</label>';
        html += '<select name="employee_type" class="zk-select">';
        html += '<option value="staff">Штатний</option>';
        html += '<option value="contractor">Пiдрядник</option>';
        html += '</select></div>';
        html += '</form></div>';
        html += '<div class="modal-footer">';
        html += '<button class="zk-btn zk-btn-secondary" id="btn-modal-cancel">Скасувати</button>';
        html += '<button class="zk-btn zk-btn-primary" id="btn-modal-submit-employee">Додати</button>';
        html += '</div>';

        ZK.Modal.open(html);

        $(document).off('click.modal-cancel');
        $(document).on('click.modal-cancel', '#btn-modal-cancel', function() {
            ZK.Modal.close();
        });

        $(document).off('click.modal-emp-submit');
        $(document).on('click.modal-emp-submit', '#btn-modal-submit-employee', function() {
            var $btn = $(this);
            var name = (document.querySelector('#form-add-employee [name="name"]').value || '').trim();
            if (!name) { ZK.Toast.error('Введiть iм\'я'); return; }
            var payload = {
                name: name,
                role_name: (document.querySelector('#form-add-employee [name="role"]').value || '').trim(),
                employee_type: document.querySelector('#form-add-employee [name="employee_type"]').value
            };
            $btn.prop('disabled', true);
            ZK.Api('employees.add', payload, function(data, ok) {
                $btn.prop('disabled', false);
                if (ok) {
                    ZK.Toast.success('Спiвробiтника додано');
                    ZK.Modal.close();
                    loadBoth();
                } else {
                    ZK.Toast.error('Помилка додавання');
                }
            });
        });
    }

    // ─── Модал: додати виплату ───────────────────────────────────────────────

    function openAddPayrollModal(employeeId) {
        var empOptions = '';
        for (var i = 0; i < employees.length; i++) {
            var sel = employees[i].id === employeeId ? ' selected' : '';
            empOptions += '<option value="' + employees[i].id + '"' + sel + '>' + escHtml(employees[i].name || '') + '</option>';
        }

        var html = '<div class="modal-header"><h3>Нова виплата</h3></div>';
        html += '<div class="modal-body">';
        html += '<form id="form-add-payroll">';
        html += '<div class="form-row"><label>Спiвробiтник</label>';
        html += '<select name="employee_id" class="zk-select">' + empOptions + '</select></div>';
        html += '<div class="form-row"><label>Проект</label>';
        html += '<select name="project_id" class="zk-select" id="payroll-project-select">';
        html += '<option value="">Завантаження...</option>';
        html += '</select></div>';
        html += '<div class="form-row"><label>Сума (UAH)</label>';
        html += '<input type="number" name="amount" class="zk-input" min="0" step="0.01" required></div>';
        html += '<div class="form-row"><label>Перiод</label>';
        html += '<input type="month" name="period" class="zk-input" required></div>';
        html += '<div class="form-row"><label>Нотатки</label>';
        html += '<textarea name="notes" class="zk-input" rows="2"></textarea></div>';
        html += '</form></div>';
        html += '<div class="modal-footer">';
        html += '<button class="zk-btn zk-btn-secondary" id="btn-modal-cancel">Скасувати</button>';
        html += '<button class="zk-btn zk-btn-primary" id="btn-modal-submit-payroll">Додати</button>';
        html += '</div>';

        ZK.Modal.open(html);

        // Load projects for select
        ZK.Api('projects.list', {}, function(data, ok) {
            var $sel = $('#payroll-project-select');
            $sel.empty().append('<option value="">— Без проекту —</option>');
            if (ok && data) {
                var list = data.items || data || [];
                for (var i = 0; i < list.length; i++) {
                    $sel.append('<option value="' + list[i].id + '">' + escHtml(list[i].name || '') + '</option>');
                }
            }
        });

        $(document).off('click.modal-cancel');
        $(document).on('click.modal-cancel', '#btn-modal-cancel', function() {
            ZK.Modal.close();
        });

        $(document).off('click.modal-payroll-submit');
        $(document).on('click.modal-payroll-submit', '#btn-modal-submit-payroll', function() {
            var $btn = $(this);
            var form = document.getElementById('form-add-payroll');
            var amount = parseFloat(form.querySelector('[name="amount"]').value);
            if (!amount || amount <= 0) { ZK.Toast.error('Введiть суму'); return; }
            var period = (form.querySelector('[name="period"]').value || '').trim();
            if (!period) { ZK.Toast.error('Вкажiть перiод'); return; }
            var payload = {
                employee_id: parseInt(form.querySelector('[name="employee_id"]').value, 10),
                project_id: form.querySelector('[name="project_id"]').value || null,
                amount_uah: amount,
                period_month: period,
                notes: (form.querySelector('[name="notes"]').value || '').trim()
            };
            $btn.prop('disabled', true);
            ZK.Api('payroll.add', payload, function(data, ok) {
                $btn.prop('disabled', false);
                if (ok) {
                    ZK.Toast.success('Виплату додано');
                    ZK.Modal.close();
                    loadBoth();
                } else {
                    ZK.Toast.error('Помилка додавання виплати');
                }
            });
        });
    }

    // ─── Утилiти ─────────────────────────────────────────────────────────────

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    return { load: load };

})();
