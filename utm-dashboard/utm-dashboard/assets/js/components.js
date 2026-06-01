// === components.js ===
// Компоненты UI: модальные окна, уведомления, загрузчик

(function($) {
    'use strict';

    // ==========================================
    // МОДАЛЬНОЕ ОКНО
    // ==========================================

    function initModal() {
        // Открытие модального окна
        window.openModal = function(modalId) {
            $('#' + modalId).addClass('active');
            $('body').css('overflow', 'hidden');
        };

        // Закрытие модального окна
        window.closeModal = function(modalId) {
            $('#' + modalId).removeClass('active');
            $('body').css('overflow', 'auto');
        };

        // Закрытие по клику на кнопку
        $('.modal-close').on('click', function() {
            $(this).closest('.modal').removeClass('active');
            $('body').css('overflow', 'auto');
        });

        // Закрытие по клику вне окна
        $('.modal').on('click', function(e) {
            if ($(e.target).hasClass('modal')) {
                $(this).removeClass('active');
                $('body').css('overflow', 'auto');
            }
        });

        // Закрытие по ESC
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('.modal.active').removeClass('active');
                $('body').css('overflow', 'auto');
            }
        });
    }

    // ==========================================
    // ПОКАЗАТЬ СЫРЫЕ ДАННЫЕ
    // ==========================================

    window.showRawData = function() {
        if (!window.UTMDashboard.data) {
            showNotification('warning', 'Предупреждение', 'Нет данных для отображения');
            return;
        }

        const jsonStr = JSON.stringify(window.UTMDashboard.data, null, 2);
        $('#rawDataContent').text(jsonStr);
        openModal('rawDataModal');
    };

    // Кнопка закрытия модального окна
    $('#closeModalBtn').on('click', function() {
        closeModal('rawDataModal');
    });

    // Кнопка копирования данных
    $('#copyDataBtn').on('click', function() {
        const data = $('#rawDataContent').text();

        navigator.clipboard.writeText(data).then(function() {
            showNotification('success', 'Скопировано', 'Данные скопированы в буфер обмена');
        }).catch(function() {
            showNotification('error', 'Ошибка', 'Не удалось скопировать данные');
        });
    });

    // Кнопка скачивания данных
    $('#downloadDataBtn').on('click', function() {
        const data = $('#rawDataContent').text();
        const blob = new Blob([data], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'utm_raw_data_' + new Date().toISOString().split('T')[0] + '.json';
        link.click();

        showNotification('success', 'Готово', 'Данные скачаны');
    });

    // ==========================================
    // СИСТЕМА УВЕДОМЛЕНИЙ
    // ==========================================

    window.showNotification = function(type, title, message, duration = 5000) {
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };

        const icon = icons[type] || icons.info;

        const notification = $(`
            <div class="notification ${type} fade-in">
                <div class="notification-icon">${icon}</div>
                <div class="notification-content">
                    <div class="notification-title">${title}</div>
                    <div class="notification-message">${message}</div>
                </div>
                <button class="notification-close">✕</button>
            </div>
        `);

        $('#notificationsContainer').append(notification);

        // Закрытие по клику
        notification.find('.notification-close').on('click', function() {
            notification.remove();
        });

        // Автоматическое удаление
        if (duration > 0) {
            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);
        }

        return notification;
    };

    // ==========================================
    // ЗАГРУЗЧИК
    // ==========================================

    window.showLoader = function(text = 'Загрузка данных...') {
        $('#loaderOverlay p').text(text);
        $('#loaderOverlay').addClass('active');
    };

    window.hideLoader = function() {
        $('#loaderOverlay').removeClass('active');
    };

    // ==========================================
    // ИНИЦИАЛИЗАЦИЯ
    // ==========================================

    $(document).ready(function() {
        initModal();
        console.log('✅ Компоненты UI инициализированы');
    });

})(jQuery);
