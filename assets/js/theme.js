// === theme.js ===
// assets/js/theme.js
// НАЗНАЧЕНИЕ: Переключатель dark/light темы (совместим с finance/ модулем)
// СВЯЗИ: localStorage ключ 'zk_theme_mode' (общий с finance)
// РАЗМЕР: ~60 строк

(function(window, document) {
    'use strict';

    // Namespace (совместим с finance)
    window.ZK = window.ZK || {};

    ZK.Theme = (function() {
        var STORAGE_KEY = 'zk_theme_mode';

        // Определить режим: сохранённый > время суток > системная тема > dark
        function detectMode() {
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'dark' || saved === 'light') {
                return saved;
            }
            var h = new Date().getHours();
            if (h >= 22 || h < 7) {
                return 'dark';
            }
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                return 'dark';
            }
            return 'light';
        }

        // Применить тему
        function apply(mode) {
            var html = document.documentElement;
            if (mode === 'light') {
                html.classList.add('light-theme');
            } else {
                html.classList.remove('light-theme');
            }
            localStorage.setItem(STORAGE_KEY, mode);

            // Событие для компонентов которые хотят перерисоваться (например Chart.js)
            try {
                window.dispatchEvent(new CustomEvent('zk:theme-change', { detail: { mode: mode } }));
            } catch (e) {}
        }

        // Переключить
        function toggle() {
            var current = document.documentElement.classList.contains('light-theme') ? 'light' : 'dark';
            apply(current === 'dark' ? 'light' : 'dark');
        }

        // Получить текущий режим
        function current() {
            return document.documentElement.classList.contains('light-theme') ? 'light' : 'dark';
        }

        // Инициализация: привязать клик на кнопку и применить сохранённую тему
        function init() {
            apply(detectMode());

            // Event delegation - кнопка может быть добавлена позже
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('#zk-theme-toggle');
                if (btn) {
                    e.preventDefault();
                    toggle();
                }
            });
        }

        return {
            init: init,
            toggle: toggle,
            apply: apply,
            current: current
        };
    })();

    // Автоматический init при DOMContentLoaded (тема применится через FOUC IIFE ДО CSS)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ZK.Theme.init);
    } else {
        ZK.Theme.init();
    }

})(window, document);
