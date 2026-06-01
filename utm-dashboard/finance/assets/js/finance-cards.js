// === FINANCE-CARDS.JS ===
// finance/assets/js/finance-cards.js
// НАЗНАЧЕНИЕ: Модуль рекламних карток (баланси, поповнення, управлiння)
// СВЯЗИ: finance-app.js (ZK.Api, ZK.Toast, ZK.Modal, ZK.Format, ZK.Config), api/handler.php
// РАЗМЕР: ~280 строк

ZK.Cards = (function() {

    var allProjects = [];

    // ─── Загрузка ────────────────────────────────────────────────────────────

    function load() {
        loadProjects();
        loadCards();
    }

    function loadProjects() {
        ZK.Api('projects.list', {}, function(data, ok) {
            if (ok && data) {
                allProjects = data.items || data || [];
            }
        });
    }

    function loadCards() {
        var $wrap = $('#cards-wrap');
        $wrap.html('<div class="zk-loading">Завантаження карток...</div>');

        ZK.Api('cards.list', {}, function(data, ok) {
            if (!ok || !data) {
                $wrap.html('<div class="zk-empty">Помилка завантаження</div>');
                return;
            }
            var cards = data.items || data || [];
            renderCards(cards);
        });
    }

    // ─── Рендер карток ───────────────────────────────────────────────────────

    function renderCards(cards) {
        var canWrite = ZK.Config.canWrite;

        var html = '<div class="cards-section-header">';
        if (canWrite) {
            html += '<button class="zk-btn zk-btn-sm zk-btn-primary" id="btn-add-card">+ Картка</button>';
        }
        html += '</div>';

        if (!cards || cards.length === 0) {
            html += '<div class="zk-empty">Карток немає</div>';
            $('#cards-wrap').html(html);
            bindCardEvents();
            return;
        }

        html += '<div class="cards-grid">';

        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var last4 = card.last4 ? '****' + card.last4 : '****';
            var pct = parseFloat(card.balance_pct) || 0;
            var balanceUah = parseFloat(card.balance_uah) || 0;

            var cardClass = 'ad-card';
            if (balanceUah === 0) {
                cardClass += ' danger';
            } else if (pct < 20 && pct > 0) {
                cardClass += ' warn';
            }

            var platforms = card.platforms || '';

            html += '<div class="' + cardClass + '" data-card-id="' + card.id + '">';
            html += '<div class="ad-card-header">';
            html += '<span class="ad-card-bank">' + escHtml(card.bank_name || '') + '</span>';
            html += '<span class="ad-card-last4">' + last4 + '</span>';
            html += '</div>';
            html += '<div class="ad-card-meta">';
            html += '<span class="ad-card-owner">' + escHtml(card.owner_name || '') + '</span>';
            if (platforms) {
                html += '<span class="ad-card-platforms">' + escHtml(platforms) + '</span>';
            }
            html += '</div>';
            html += '<div class="ad-card-balance-row">';
            html += '<span class="ad-card-balance-val">' + ZK.Format.money(card.balance_uah) + '</span>';
            if (card.limit_uah) {
                html += '<span class="ad-card-limit"> / ' + ZK.Format.money(card.limit_uah) + '</span>';
            }
            html += '</div>';
            html += '<div class="card-balance-bar-wrap">';
            html += '<div class="card-balance-bar" style="width:' + Math.min(100, pct) + '%"></div>';
            html += '</div>';
            html += '<div class="ad-card-actions">';
            if (canWrite) {
                html += '<button class="zk-btn zk-btn-xs zk-btn-primary btn-topup-card" data-id="' + card.id + '">Поповнити</button> ';
                html += '<button class="zk-btn zk-btn-xs zk-btn-secondary btn-edit-card" data-id="' + card.id + '" data-card=\'' + jsonSafe(card) + '\'>Редагувати</button>';
            }
            html += '</div>';
            html += '</div>'; // .ad-card
        }

        html += '</div>'; // .cards-grid

        $('#cards-wrap').html(html);
        bindCardEvents();
    }

    function bindCardEvents() {
        var canWrite = ZK.Config.canWrite;
        $(document).off('click.cards');
        if (!canWrite) { return; }

        $(document).on('click.cards', '#btn-add-card', function() {
            openAddModal();
        });

        $(document).on('click.cards', '.btn-topup-card', function() {
            var id = parseInt($(this).data('id'), 10);
            openTopupModal(id);
        });

        $(document).on('click.cards', '.btn-edit-card', function() {
            var cardData = $(this).data('card');
            openEditModal(cardData);
        });
    }

    // ─── Модал: поповнення ───────────────────────────────────────────────────

    function openTopupModal(cardId) {
        var projOptions = '<option value="">— Без проекту —</option>';
        for (var i = 0; i < allProjects.length; i++) {
            projOptions += '<option value="' + allProjects[i].id + '">' + escHtml(allProjects[i].name || '') + '</option>';
        }

        var html = '<div class="modal-header"><h3>Поповнення картки</h3></div>';
        html += '<div class="modal-body">';
        html += '<form id="form-topup-card">';
        html += '<div class="form-row"><label>Сума (UAH)</label>';
        html += '<input type="number" name="amount_uah" class="zk-input" min="0.01" step="0.01" required></div>';
        html += '<div class="form-row"><label>Проект</label>';
        html += '<select name="project_id" class="zk-select">' + projOptions + '</select></div>';
        html += '<div class="form-row"><label>Опис</label>';
        html += '<input type="text" name="description" class="zk-input"></div>';
        html += '</form></div>';
        html += '<div class="modal-footer">';
        html += '<button class="zk-btn zk-btn-secondary" id="btn-modal-cancel">Скасувати</button>';
        html += '<button class="zk-btn zk-btn-primary" id="btn-modal-submit-topup">Поповнити</button>';
        html += '</div>';

        ZK.Modal.open(html);

        $(document).off('click.modal-cancel');
        $(document).on('click.modal-cancel', '#btn-modal-cancel', function() {
            ZK.Modal.close();
        });

        $(document).off('click.topup-submit');
        $(document).on('click.topup-submit', '#btn-modal-submit-topup', function() {
            var $btn = $(this);
            var form = document.getElementById('form-topup-card');
            var amount = parseFloat(form.querySelector('[name="amount_uah"]').value);
            if (!amount || amount <= 0) { ZK.Toast.error('Введiть суму'); return; }
            var payload = {
                id: cardId,
                amount_uah: amount,
                project_id: form.querySelector('[name="project_id"]').value || null,
                description: (form.querySelector('[name="description"]').value || '').trim()
            };
            $btn.prop('disabled', true);
            ZK.Api('cards.topup', payload, function(data, ok) {
                $btn.prop('disabled', false);
                if (ok) {
                    ZK.Toast.success('Картку поповнено');
                    ZK.Modal.close();
                    loadCards();
                } else {
                    ZK.Toast.error('Помилка поповнення');
                }
            });
        });
    }

    // ─── Модал: додати картку ────────────────────────────────────────────────

    function openAddModal() {
        var html = buildCardFormHtml('Нова картка', {});
        ZK.Modal.open(html);
        bindCardFormSubmit('cards.add', null);
    }

    // ─── Модал: редагувати картку ────────────────────────────────────────────

    function openEditModal(card) {
        var html = buildCardFormHtml('Редагування картки', card);
        ZK.Modal.open(html);
        bindCardFormSubmit('cards.update', card.id);
    }

    function buildCardFormHtml(title, card) {
        var v = card || {};
        var html = '<div class="modal-header"><h3>' + title + '</h3></div>';
        html += '<div class="modal-body"><form id="form-card">';
        html += '<div class="form-row"><label>Банк</label>';
        html += '<input type="text" name="bank_name" class="zk-input" value="' + escAttr(v.bank_name) + '" required></div>';
        html += '<div class="form-row"><label>Останнi 4 цифри</label>';
        html += '<input type="text" name="last4" class="zk-input" maxlength="4" pattern="\\d{4}" value="' + escAttr(v.last4) + '" required></div>';
        html += '<div class="form-row"><label>Власник</label>';
        html += '<input type="text" name="owner_name" class="zk-input" value="' + escAttr(v.owner_name) + '"></div>';
        html += '<div class="form-row"><label>Локацiя</label>';
        html += '<input type="text" name="location" class="zk-input" value="' + escAttr(v.location) + '"></div>';
        html += '<div class="form-row"><label>Платформи</label>';
        html += '<input type="text" name="platforms" class="zk-input" value="' + escAttr(v.platforms) + '"></div>';
        html += '<div class="form-row"><label>Баланс (UAH)</label>';
        html += '<input type="number" name="balance_uah" class="zk-input" step="0.01" value="' + (v.balance_uah || '') + '"></div>';
        html += '<div class="form-row"><label>Лiмiт (UAH)</label>';
        html += '<input type="number" name="limit_uah" class="zk-input" step="0.01" value="' + (v.limit_uah || '') + '"></div>';
        html += '</form></div>';
        html += '<div class="modal-footer">';
        html += '<button class="zk-btn zk-btn-secondary" id="btn-modal-cancel">Скасувати</button>';
        html += '<button class="zk-btn zk-btn-primary" id="btn-modal-submit-card">Зберегти</button>';
        html += '</div>';
        return html;
    }

    function bindCardFormSubmit(action, cardId) {
        $(document).off('click.modal-cancel');
        $(document).on('click.modal-cancel', '#btn-modal-cancel', function() {
            ZK.Modal.close();
        });

        $(document).off('click.card-form-submit');
        $(document).on('click.card-form-submit', '#btn-modal-submit-card', function() {
            var form = document.getElementById('form-card');
            var bankName = (form.querySelector('[name="bank_name"]').value || '').trim();
            var last4 = (form.querySelector('[name="last4"]').value || '').trim();
            if (!bankName) { ZK.Toast.error('Введiть назву банку'); return; }
            if (!/^\d{4}$/.test(last4)) { ZK.Toast.error('Введiть 4 цифри картки'); return; }
            var payload = {
                bank_name: bankName,
                last4: last4,
                owner_name: (form.querySelector('[name="owner_name"]').value || '').trim(),
                location: (form.querySelector('[name="location"]').value || '').trim(),
                platforms: (form.querySelector('[name="platforms"]').value || '').trim(),
                balance_uah: parseFloat(form.querySelector('[name="balance_uah"]').value) || 0,
                limit_uah: parseFloat(form.querySelector('[name="limit_uah"]').value) || 0
            };
            if (cardId) { payload.id = cardId; }
            ZK.Api(action, payload, function(data, ok) {
                if (ok) {
                    ZK.Toast.success(cardId ? 'Картку оновлено' : 'Картку додано');
                    ZK.Modal.close();
                    loadCards();
                } else {
                    ZK.Toast.error('Помилка збереження');
                }
            });
        });
    }

    // ─── Утилiти ─────────────────────────────────────────────────────────────

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function jsonSafe(obj) {
        return JSON.stringify(obj).replace(/'/g, '&#39;');
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    return { load: load };

})();
