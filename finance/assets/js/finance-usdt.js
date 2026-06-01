// === FINANCE-USDT.JS ===
// finance/assets/js/finance-usdt.js
// НАЗНАЧЕНИЕ: Модуль USDT - балансi, дивiденди, транзакцiї (лише для canUsdt)
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Toast, ZK.Modal, ZK.Format, ZK.Config), api/handler.php
// РАЗМЕР: ~220 строк

ZK.Usdt = (function() {

    // ─── Загрузка ────────────────────────────────────────────────────────────

    function load() {
        if (!ZK.Config.canUsdt) {
            $('#usdt-wrap').html('<div class="zk-empty">Немає доступу до USDT-модуля</div>');
            return;
        }

        var $wrap = $('#usdt-wrap');
        $wrap.html('<div class="zk-loading">Завантаження USDT...</div>');

        ZK.Api('usdt.summary', {}, function(data, ok) {
            if (!ok || !data) {
                $wrap.html('<div class="zk-empty">Помилка завантаження</div>');
                return;
            }
            render(data);
        });
    }

    // ─── Основний рендер ─────────────────────────────────────────────────────

    function render(data) {
        var html = '<div class="usdt-layout">';
        html += renderMetrics(data);
        html += '<div class="usdt-bottom-row">';
        html += renderBalances(data.balances || []);
        html += renderTransactions(data.transactions || []);
        html += '</div>';
        html += '</div>';

        $('#usdt-wrap').html(html);
        bindUsdtEvents();
    }

    // ─── Метрики ─────────────────────────────────────────────────────────────

    function renderMetrics(data) {
        var taxes = parseFloat(data.taxes_and_fees) || 0;
        var divVadym = parseFloat(data.dividends_vadym) || 0;
        var divArtem = parseFloat(data.dividends_artem) || 0;
        var delta = divVadym - divArtem;

        var deltaLabel;
        if (Math.abs(delta) < 0.001) {
            deltaLabel = 'Рiвно';
        } else if (delta > 0) {
            deltaLabel = 'Вадим +' + formatUsdt(delta);
        } else {
            deltaLabel = 'Артем +' + formatUsdt(Math.abs(delta));
        }

        var html = '<div class="usdt-metrics">';

        html += metricCard('Податки + комiсiї', formatUsdt(taxes) + ' USDT', 'metric-taxes');
        html += metricCard('Дивiденди Вадим', formatUsdt(divVadym) + ' USDT', 'metric-vadym');
        html += metricCard('Дивiденди Артем', formatUsdt(divArtem) + ' USDT', 'metric-artem');
        html += metricCard('Дельта', deltaLabel, 'metric-delta');

        html += '</div>';
        return html;
    }

    function metricCard(label, value, cls) {
        return '<div class="usdt-metric-card ' + cls + '">'
            + '<div class="usdt-metric-label">' + label + '</div>'
            + '<div class="usdt-metric-value">' + value + '</div>'
            + '</div>';
    }

    // ─── Транзакцiї ──────────────────────────────────────────────────────────

    function renderTransactions(transactions) {
        var canWrite = ZK.Config.canWrite;
        var html = '<div class="usdt-transactions">';
        html += '<div class="usdt-block-header">';
        html += '<h3 class="usdt-block-title">Транзакцiї</h3>';
        if (canWrite) {
            html += '<button class="zk-btn zk-btn-sm zk-btn-primary" id="btn-usdt-update-balance">+ Оновити баланс</button>';
        }
        html += '</div>';

        var list = transactions || [];
        if (list.length === 0) {
            html += '<div class="zk-empty">Транзакцiй немає</div>';
            html += '</div>';
            return html;
        }

        // Show last 50
        var shown = list.slice(0, 50);

        html += '<div class="zk-table-wrap"><table class="zk-table">';
        html += '<thead><tr>';
        html += '<th>Дата</th><th>Проект</th><th>Тип</th><th>Опис</th><th>Сума (UAH)</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < shown.length; i++) {
            var tx = shown[i];
            html += '<tr>';
            html += '<td>' + ZK.Format.date(tx.date) + '</td>';
            html += '<td>' + escHtml(tx.project_name || '') + '</td>';
            html += '<td>' + escHtml(tx.type || '') + '</td>';
            html += '<td>' + escHtml(tx.description || '') + '</td>';
            html += '<td>' + ZK.Format.money(tx.amount_uah) + '</td>';
            html += '</tr>';
        }

        html += '</tbody></table></div>';
        html += '</div>'; // .usdt-transactions
        return html;
    }

    // ─── Баланси ─────────────────────────────────────────────────────────────

    function renderBalances(balances) {
        var html = '<div class="usdt-balances">';
        html += '<div class="usdt-block-header">';
        html += '<h3 class="usdt-block-title">Баланси</h3>';
        html += '</div>';

        if (!balances || balances.length === 0) {
            html += '<div class="zk-empty">Балансiв немає</div>';
            html += '</div>';
            return html;
        }

        html += '<div class="usdt-balance-list">';
        for (var i = 0; i < balances.length; i++) {
            var b = balances[i];
            var ownerLabel = b.owner === 'vadym' ? 'Вадим' : (b.owner === 'artem' ? 'Артем' : escHtml(b.owner || ''));
            html += '<div class="usdt-balance-item">';
            html += '<span class="usdt-balance-owner">' + ownerLabel + '</span>';
            html += '<span class="usdt-balance-amount">' + formatUsdt(b.amount_usdt) + ' USDT</span>';
            if (b.updated_at) {
                html += '<span class="usdt-balance-date">оновлено ' + ZK.Format.date(b.updated_at) + '</span>';
            }
            html += '</div>';
        }
        html += '</div>';
        html += '</div>'; // .usdt-balances
        return html;
    }

    // ─── Модал: оновлення балансу ─────────────────────────────────────────────

    function openUpdateBalanceModal() {
        var html = '<div class="modal-header"><h3>Оновлення балансу USDT</h3></div>';
        html += '<div class="modal-body">';
        html += '<form id="form-usdt-balance">';
        html += '<div class="form-row"><label>Власник</label>';
        html += '<select name="owner" class="zk-select">';
        html += '<option value="vadym">Вадим</option>';
        html += '<option value="artem">Артем</option>';
        html += '</select></div>';
        html += '<div class="form-row"><label>Сума (USDT)</label>';
        html += '<input type="number" name="amount_usdt" class="zk-input" min="0" step="0.000001" required></div>';
        html += '<div class="form-row"><label>Дата</label>';
        html += '<input type="date" name="date" class="zk-input" value="' + todayStr() + '" required></div>';
        html += '<div class="form-row"><label>Нотатки</label>';
        html += '<textarea name="notes" class="zk-input" rows="2"></textarea></div>';
        html += '</form></div>';
        html += '<div class="modal-footer">';
        html += '<button class="zk-btn zk-btn-secondary" id="btn-modal-cancel">Скасувати</button>';
        html += '<button class="zk-btn zk-btn-primary" id="btn-modal-submit-usdt">Зберегти</button>';
        html += '</div>';

        ZK.Modal.open(html);

        $(document).off('click.modal-cancel');
        $(document).on('click.modal-cancel', '#btn-modal-cancel', function() {
            ZK.Modal.close();
        });

        $(document).off('click.usdt-submit');
        $(document).on('click.usdt-submit', '#btn-modal-submit-usdt', function() {
            var form = document.getElementById('form-usdt-balance');
            var amount = parseFloat(form.querySelector('[name="amount_usdt"]').value);
            if (isNaN(amount) || amount < 0) { ZK.Toast.error('Введiть суму'); return; }
            var date = (form.querySelector('[name="date"]').value || '').trim();
            if (!date) { ZK.Toast.error('Вкажiть дату'); return; }
            var payload = {
                owner: form.querySelector('[name="owner"]').value,
                amount_usdt: amount,
                date: date,
                notes: (form.querySelector('[name="notes"]').value || '').trim()
            };
            ZK.Api('usdt.update_balance', payload, function(data, ok) {
                if (ok) {
                    ZK.Toast.success('Баланс оновлено');
                    ZK.Modal.close();
                    load();
                } else {
                    ZK.Toast.error('Помилка оновлення');
                }
            });
        });
    }

    function bindUsdtEvents() {
        $(document).off('click.usdt-btn');
        $(document).on('click.usdt-btn', '#btn-usdt-update-balance', function() {
            openUpdateBalanceModal();
        });
    }

    // ─── Утилiти ─────────────────────────────────────────────────────────────

    function formatUsdt(n) {
        var num = parseFloat(n) || 0;
        return num.toFixed(2);
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function todayStr() {
        var d = new Date();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    return { load: load };

})();
