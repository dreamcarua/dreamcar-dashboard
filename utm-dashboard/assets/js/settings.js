// =====================================
// JS: Налаштування проектів
// Файл: assets/js/settings.js
// Призначення: Управління налаштуваннями проектів
// =====================================

(function($) {
    'use strict';

    // Глобальні змінні
    window.ProjectSettings = {
        currentProject: 'VOLVO',
        availableProjects: []
    };

    // Ініціалізація при завантаженні сторінки
    $(document).ready(function() {
        loadCurrentSettings();
        initSettingsButton();
        initSettingsModal();
    });

    /**
     * 1. Завантажити поточні налаштування
     */
    function loadCurrentSettings() {
        $.ajax({
            url: 'api/get_settings.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    window.ProjectSettings.currentProject = response.active_project;
                    console.log('✅ Активний проект:', response.active_project);
                }
            },
            error: function() {
                console.warn('⚠️ Не вдалося завантажити налаштування, використовуємо дефолт');
                window.ProjectSettings.currentProject = 'VOLVO';
            }
        });
    }

    /**
     * 2. Ініціалізація кнопки "Налаштування"
     */
    function initSettingsButton() {
        $('#openSettingsBtn').on('click', function(e) {
            e.preventDefault();
            openSettingsModal();
        });
    }

    /**
     * 3. Відкрити модальне вікно
     */
    function openSettingsModal() {
        // Завантажити список проектів
        $.ajax({
            url: 'api/get_projects.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    window.ProjectSettings.availableProjects = response.projects;
                    renderProjectsList(response.projects);
                    $('#settingsModal').addClass('show');
                } else {
                    showNotification('Помилка завантаження проектів', 'error');
                }
            },
            error: function() {
                showNotification('Помилка завантаження проектів', 'error');
            }
        });
    }

    /**
     * 4. Рендер списку проектів
     */
    function renderProjectsList(projects) {
        const container = $('#projectsList');
        container.empty();

        if (projects.length === 0) {
            container.html('<div class="loading-state"><p>Немає доступних проектів</p></div>');
            return;
        }

        projects.forEach(function(project) {
            const isActive = project === window.ProjectSettings.currentProject;
            const item = $('<div>')
                .addClass('project-item')
                .toggleClass('active', isActive)
                .attr('data-project', project)
                .html(`
                    <div class="project-name">${project}</div>
                    ${isActive ? '<span class="project-badge">Активний</span>' : ''}
                `);

            item.on('click', function() {
                selectProject(project);
            });

            container.append(item);
        });
    }

    /**
     * 5. Вибрати проект
     */
    function selectProject(project) {
        // Візуальне оновлення
        $('.project-item').removeClass('active').find('.project-badge').remove();
        const selectedItem = $(`.project-item[data-project="${project}"]`);
        selectedItem.addClass('active')
            .append('<span class="project-badge">Активний</span>');

        // Зберегти на сервері
        $.ajax({
            url: 'api/save_settings.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ active_project: project }),
            success: function(response) {
                if (response.success) {
                    window.ProjectSettings.currentProject = project;
                    const message = response.message || ('Проект змінено на ' + project);
                    showNotification('✅ ' + message, 'success');

                    // Закрити модальне вікно
                    setTimeout(function() {
                        closeSettingsModal();

                        // Перезавантажити дані дашборду
                        if (typeof window.UTMDashboard !== 'undefined' && typeof window.UTMDashboard.loadData === 'function') {
                            window.UTMDashboard.loadData();
                        } else {
                            // Fallback - перезавантажити сторінку
                            window.location.reload();
                        }
                    }, 1000);
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Помилка збереження';
                showNotification('❌ ' + error, 'error');
            }
        });
    }

    /**
     * 6. Закрити модальне вікно
     */
    function closeSettingsModal() {
        $('#settingsModal').removeClass('show');
    }

    /**
     * 7. Ініціалізація модального вікна
     */
    function initSettingsModal() {
        // Закриття по кліку на хрестик
        $('#closeSettingsBtn').on('click', function(e) {
            e.preventDefault();
            closeSettingsModal();
        });

        // Закриття по кліку поза модальним вікном
        $('#settingsModal').on('click', function(e) {
            if ($(e.target).is('#settingsModal')) {
                closeSettingsModal();
            }
        });

        // Закриття по ESC
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#settingsModal').hasClass('show')) {
                closeSettingsModal();
            }
        });
    }

    /**
     * 8. Показати повідомлення
     */
    function showNotification(message, type) {
        // Використати існуючу систему нотифікацій якщо є
        if (typeof window.showNotification === 'function') {
            window.showNotification(message, type);
        } else {
            // Fallback - console.log
            const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
            console.log(`${icon} ${message}`);

            // Простий alert для важливих повідомлень
            if (type === 'error') {
                alert(message);
            }
        }
    }

    /**
     * 9. Публічний API
     */
    window.ProjectSettings.openSettings = openSettingsModal;
    window.ProjectSettings.closeSettings = closeSettingsModal;
    window.ProjectSettings.getCurrentProject = function() {
        return window.ProjectSettings.currentProject;
    };

})(jQuery);
