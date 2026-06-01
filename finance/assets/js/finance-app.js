// === FINANCE-APP.JS ===
// finance/assets/js/finance-app.js
// НАЗНАЧЕНИЕ: Ядро SPA - namespace ZK, Config, Api, Toast, Theme, Router, Format, Modal
// СВЯЗИ: finance/index.php, finance-dashboard.js, finance-projects.js, finance-expenses.js
// РАЗМЕР: ~280 строк

var ZK = ZK || {};

// ─── Config ─────────────────────────────────────────────────────────────────

ZK.Config = {
    apiUrl: 'api/handler.php',
    financeRole: $('body').data('finance-role') || '',
    canWrite: $('body').data('can-write') === 1 || $('body').data('can-write') === '1',
    canUsdt: $('body').data('can-usdt') === 1 || $('body').data('can-usdt') === '1',
    username: $('body').data('username') || ''
};

// ─── Api ─────────────────────────────────────────────────────────────────────

ZK.Api = function(action, data, callback) {
    var payload = $.extend({ action: action }, data || {});

    $.ajax({
        url: ZK.Config.apiUrl,
        method: 'POST',
        data: payload,
        dataType: 'json',
        success: function(response) {
            if (typeof callback === 'function') {
                var success = response && response.success !== false;
                // Передаем response.data (unwrapped) — основной payload
                var payload = (response && response.data !== undefined) ? response.data : response;
                callback(payload, success);
            }
        },
        error: function(xhr) {
            ZK.Toast.error('Помилка запиту');
            if (typeof callback === 'function') {
                callback(null, false);
            }
        }
    });
};

// ─── Toast ───────────────────────────────────────────────────────────────────

ZK.Toast = (function() {
    function show(msg, type) {
        var cls = 'zk-toast zk-toast-' + (type || 'info');
        var $toast = $('<div class="' + cls + '"></div>').text(msg);
        $('body').append($toast);

        // trigger reflow so CSS transition works
        void $toast[0].offsetWidth;
        $toast.addClass('zk-toast-visible');

        setTimeout(function() {
            $toast.removeClass('zk-toast-visible');
            setTimeout(function() { $toast.remove(); }, 300);
        }, 3000);
    }

    return {
        success: function(msg) { show(msg, 'success'); },
        error: function(msg)   { show(msg, 'error'); },
        info: function(msg)    { show(msg, 'info'); }
    };
})();

// ─── Theme ───────────────────────────────────────────────────────────────────

ZK.Theme = (function() {
    var STORAGE_KEY = 'zk_theme_mode';

    function detectMode() {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved === 'dark' || saved === 'light') { return saved; }
        var h = new Date().getHours();
        if (h >= 22 || h < 7) { return 'dark'; }
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    function apply(mode) {
        if (mode === 'light') {
            document.documentElement.classList.add('light-theme');
        } else {
            document.documentElement.classList.remove('light-theme');
        }
        localStorage.setItem(STORAGE_KEY, mode);
    }

    function toggle() {
        var current = document.documentElement.classList.contains('light-theme') ? 'light' : 'dark';
        apply(current === 'dark' ? 'light' : 'dark');
    }

    function init() {
        apply(detectMode());
        $(document).on('click', '#zk-theme-toggle', function() {
            toggle();
        });
    }

    return { init: init, apply: apply, toggle: toggle };
})();

// ─── Router ──────────────────────────────────────────────────────────────────

ZK.Router = (function() {
    function get() {
        var hash  = window.location.hash.replace('#', '') || '';
        var parts = hash.split('/');
        var section = parts[0] || '';
        var id = parts[1] ? parseInt(parts[1], 10) : null;
        return { section: section, id: isNaN(id) ? null : id };
    }

    function set(section, id) {
        var hash = section || '';
        if (id !== undefined && id !== null) { hash += '/' + id; }
        window.location.hash = hash;
    }

    function setSection(section) {
        set(section, null);
    }

    return { get: get, set: set, setSection: setSection };
})();

// ─── Format ──────────────────────────────────────────────────────────────────

ZK.Format = (function() {
    function money(n) {
        if (n === null || n === undefined || n === '') { return '—'; }
        var num = parseFloat(n);
        if (isNaN(num)) { return '—'; }
        var parts = num.toFixed(2).split('.');
        var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
        return intPart + '.' + parts[1] + '\u00a0\u20b4';
    }

    function pct(n) {
        if (n === null || n === undefined || n === '') { return '—'; }
        var num = parseFloat(n);
        if (isNaN(num)) { return '—'; }
        return num.toFixed(1) + '%';
    }

    function date(str) {
        if (!str) { return '—'; }
        var d = new Date(str);
        if (isNaN(d.getTime())) { return str; }
        var dd = String(d.getDate()).padStart(2, '0');
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var yyyy = d.getFullYear();
        return dd + '.' + mm + '.' + yyyy;
    }

    return { money: money, pct: pct, date: date };
})();

// ─── Modal ───────────────────────────────────────────────────────────────────

ZK.Modal = (function() {
    var $overlay = null;

    function open(titleOrHtml, htmlOrOptions, options) {
        close();

        // Поддержка двух форм: open(html) и open(title, html)
        var title = null;
        var html, opts;
        if (typeof htmlOrOptions === 'string') {
            title = titleOrHtml;
            html  = htmlOrOptions;
            opts  = $.extend({ closable: true }, options || {});
        } else {
            html = titleOrHtml;
            opts = $.extend({ closable: true }, htmlOrOptions || {});
        }

        $overlay = $('<div class="modal-overlay"></div>');
        var $modal = $('<div class="modal-box"></div>');
        if (title) {
            $modal.append('<div class="modal-header"><h3>' + title + '</h3><button class="zk-btn zk-btn-xs" id="zk-modal-close">✕</button></div>');
        }
        var $content = $('<div class="modal-body"></div>').html(html);
        $modal.append($content);
        $overlay.append($modal);
        $('body').append($overlay);

        // trigger reflow
        void $overlay[0].offsetWidth;
        $overlay.addClass('modal-visible');

        if (opts.closable) {
            $overlay.on('click.zkmodal', function(e) {
                if ($(e.target).is($overlay)) { close(); }
            });
            $overlay.on('click.zkmodal', '#zk-modal-close', function() { close(); });
            $(document).on('keydown.zkmodal', function(e) {
                if (e.key === 'Escape') { close(); }
            });
        }
    }

    function close() {
        if (!$overlay) { return; }
        $overlay.removeClass('modal-visible');
        var $toRemove = $overlay;
        setTimeout(function() { $toRemove.remove(); }, 300);
        $(document).off('keydown.zkmodal');
        $overlay = null;
    }

    return { open: open, close: close };
})();

// ─── Section state tracker ────────────────────────────────────────────────────

ZK.SectionState = {};

// ─── Route handler ────────────────────────────────────────────────────────────

function handleRoute() {
    var route = ZK.Router.get();
    var section = route.section || 'dashboard';

    // update active nav button
    $('.nav-btn').removeClass('active');
    $('.nav-btn[data-section="' + section + '"]').addClass('active');

    // show/hide sections
    $('.content-section').removeClass('active');
    var $target = $('#' + section + '-section');
    if ($target.length) {
        $target.addClass('active');
    }

    // load section data
    switch (section) {
        case 'dashboard':
            if (typeof ZK.Dashboard !== 'undefined') {
                ZK.Dashboard.load();
            }
            break;

        case 'projects':
            if (typeof ZK.Projects !== 'undefined') {
                ZK.Projects.load(route.id);
                if (route.id) {
                    ZK.Projects.openDetail(route.id);
                }
            }
            break;

        case 'expenses':
            if (typeof ZK.Expenses !== 'undefined') {
                ZK.Expenses.load();
            }
            break;

        case 'payroll':
            if (typeof ZK.Payroll !== 'undefined') {
                ZK.Payroll.load();
            }
            break;

        case 'cards':
            if (typeof ZK.Cards !== 'undefined') {
                ZK.Cards.load();
            }
            break;

        case 'usdt':
            if (typeof ZK.Usdt !== 'undefined') {
                ZK.Usdt.load();
            }
            break;

        case 'settings':
            if (typeof ZK.Settings !== 'undefined') {
                ZK.Settings.load();
            }
            break;

        default:
            break;
    }
}

// ─── Document ready ──────────────────────────────────────────────────────────

$(function() {
    ZK.Theme.init();

    $(document).on('click', '.nav-btn', function() {
        var section = $(this).data('section');
        ZK.Router.setSection(section);
    });

    $(window).on('hashchange', handleRoute);

    handleRoute();
});
