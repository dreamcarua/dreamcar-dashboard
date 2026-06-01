/**
 * manual_costs.js
 * JavaScript для страницы ручного ввода рекламных расходов
 * Создано: 2025-11-30
 */

$(document).ready(function() {
    // Установить сегодняшнюю дату по умолчанию
    const today = new Date().toISOString().split('T')[0];
    $('#costDate').val(today);

    // Заблокувати utm_term для не-адмінів
    if (window.USER_UTM_TERM) {
        $('#costTerm').val(window.USER_UTM_TERM).prop('readonly', true).addClass('filter-locked');
        $('#editCostTerm').prop('readonly', true).addClass('filter-locked');
        console.log('🔒 UTM Term заблокований для користувача:', window.USER_UTM_TERM);
    }

    // Загрузить список расходов
    loadManualCosts();

    // Обработчик формы добавления
    $('#addCostForm').on('submit', function(e) {
        e.preventDefault();
        addManualCost();
    });

    // Обработчик кнопки обновления
    $('#updateCostBtn').on('click', function() {
        updateManualCost();
    });

    // Обработчик кнопки удаления
    $('#confirmDeleteBtn').on('click', function() {
        deleteManualCost();
    });

    // Фильтры по дате
    $('#applyFilterBtn').on('click', function() {
        loadManualCosts();
    });

    $('#clearFilterBtn').on('click', function() {
        $('#filterDateFrom').val('');
        $('#filterDateTo').val('');
        loadManualCosts();
    });
});

/**
 * Загрузить список ручных расходов
 */
function loadManualCosts() {
    const dateFrom = $('#filterDateFrom').val();
    const dateTo = $('#filterDateTo').val();

    let url = 'api/handler.php?action=get_manual_costs';
    if (dateFrom) url += '&date_from=' + dateFrom;
    if (dateTo) url += '&date_to=' + dateTo;

    // Додати utm_term якщо користувач має фіксований
    if (window.USER_UTM_TERM) {
        url += '&utm_term=' + encodeURIComponent(window.USER_UTM_TERM);
        console.log('🔒 Фільтрація витрат по utm_term:', window.USER_UTM_TERM);
    }

    $.ajax({
        url: url,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderCostsTable(response.data);
            } else {
                showNotification('error', 'Ошибка', response.message || 'Не удалось загрузить данные');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading costs:', error);
            showNotification('error', 'Ошибка', 'Не удалось загрузить данные');
            $('#costsTableBody').html('<tr><td colspan="10" class="empty-cell">Ошибка загрузки данных</td></tr>');
        }
    });
}

/**
 * Отрисовать таблицу расходов
 */
function renderCostsTable(costs) {
    const tbody = $('#costsTableBody');
    tbody.empty();

    if (!costs || costs.length === 0) {
        tbody.html('<tr><td colspan="11" class="empty-cell">Нет данных. Добавьте первый расход выше.</td></tr>');
        $('#totalAmount').text('0.00 UAH');
        $('#showingCount').text('0');
        return;
    }

    let totalAmount = 0;

    costs.forEach(function(cost, index) {
        const row = $('<tr>');
        row.html(`
            <td>${index + 1}</td>
            <td>${formatDate(cost.date_start)}</td>
            <td><strong>${escapeHtml(cost.project || 'VOLVO')}</strong></td>
            <td>${escapeHtml(cost.utm_source || '-')}</td>
            <td>${escapeHtml(cost.utm_medium || '-')}</td>
            <td class="campaign-cell" title="${escapeHtml(cost.utm_campaign || '')}">${truncateText(cost.utm_campaign || '-', 20)}</td>
            <td>${escapeHtml(cost.utm_term || '-')}</td>
            <td>${escapeHtml(cost.utm_content || '-')}</td>
            <td class="amount-cell">${formatAmount(cost.spend)} UAH</td>
            <td class="note-cell" title="${escapeHtml(cost.note || '')}">${truncateText(cost.note || '-', 25)}</td>
            <td class="actions-cell">
                <button class="btn-icon btn-edit" onclick="editCost(${cost.id})" title="Редактировать">✏️</button>
                <button class="btn-icon btn-delete" onclick="confirmDeleteCost(${cost.id}, '${escapeHtml(cost.utm_source || '')}', ${cost.spend})" title="Удалить">🗑️</button>
            </td>
        `);
        tbody.append(row);

        totalAmount += parseFloat(cost.spend) || 0;
    });

    $('#totalAmount').text(formatAmount(totalAmount) + ' UAH');
    $('#showingCount').text(costs.length);
}

/**
 * Добавить новый расход
 */
function addManualCost() {
    // Собрать данные формы
    const formData = {
        date: $('#costDate').val(),
        project: $('#costProject').val(),
        utm_source: $('#costSource').val().trim().toLowerCase(),
        utm_medium: $('#costMedium').val().trim().toLowerCase(),
        utm_campaign: $('#costCampaign').val().trim().toLowerCase(),
        utm_term: window.USER_UTM_TERM || $('#costTerm').val().trim().toLowerCase(), // Примусово utm_term користувача
        utm_content: $('#costContent').val().trim().toLowerCase(),
        amount: parseFloat($('#costAmount').val()),
        currency: $('#costCurrency').val(),
        note: $('#costNote').val().trim()
    };

    // Валидация: хотя бы одна UTM-метка
    if (!formData.utm_source && !formData.utm_medium && !formData.utm_campaign &&
        !formData.utm_term && !formData.utm_content) {
        showNotification('error', 'Ошибка', 'Заполните хотя бы одну UTM-метку');
        return;
    }

    // Валидация: дата и сумма
    if (!formData.date) {
        showNotification('error', 'Ошибка', 'Укажите дату');
        return;
    }

    if (!formData.amount || formData.amount <= 0) {
        showNotification('error', 'Ошибка', 'Укажите корректную сумму');
        return;
    }

    // Отключить кнопку
    $('#submitCostBtn').prop('disabled', true).text('Сохранение...');

    $.ajax({
        url: 'api/handler.php',
        method: 'POST',
        data: {
            action: 'add_manual_cost',
            cost_data: JSON.stringify(formData)
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showNotification('success', 'Успешно', 'Расход добавлен');

                // Очистить форму (кроме даты и проекта)
                $('#costSource, #costMedium, #costCampaign, #costTerm, #costContent, #costAmount, #costNote').val('');
                $('#costCurrency').val('UAH');
                // Проект залишається той самий

                // Обновить таблицу
                loadManualCosts();
            } else {
                showNotification('error', 'Ошибка', response.message || 'Не удалось сохранить');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error adding cost:', error);
            showNotification('error', 'Ошибка', 'Не удалось сохранить расход');
        },
        complete: function() {
            $('#submitCostBtn').prop('disabled', false).text('💾 Сохранить расход');
        }
    });
}

/**
 * Открыть модальное окно редактирования
 */
function editCost(id) {
    $.ajax({
        url: 'api/handler.php',
        method: 'GET',
        data: {
            action: 'get_manual_cost',
            id: id
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                const cost = response.data;

                // Заполнить форму
                $('#editCostId').val(cost.id);
                $('#editCostDate').val(cost.date_start);
                $('#editCostProject').val(cost.project || 'VOLVO');
                $('#editCostSource').val(cost.utm_source || '');
                $('#editCostMedium').val(cost.utm_medium || '');
                $('#editCostCampaign').val(cost.utm_campaign || '');
                $('#editCostTerm').val(cost.utm_term || '');
                $('#editCostContent').val(cost.utm_content || '');
                $('#editCostAmount').val(cost.spend);
                $('#editCostNote').val(cost.note || '');

                openModal('editCostModal');
            } else {
                showNotification('error', 'Ошибка', 'Не удалось загрузить данные расхода');
            }
        },
        error: function() {
            showNotification('error', 'Ошибка', 'Не удалось загрузить данные расхода');
        }
    });
}

/**
 * Обновить расход
 */
function updateManualCost() {
    const formData = {
        id: $('#editCostId').val(),
        date: $('#editCostDate').val(),
        project: $('#editCostProject').val(),
        utm_source: $('#editCostSource').val().trim().toLowerCase(),
        utm_medium: $('#editCostMedium').val().trim().toLowerCase(),
        utm_campaign: $('#editCostCampaign').val().trim().toLowerCase(),
        utm_term: $('#editCostTerm').val().trim().toLowerCase(),
        utm_content: $('#editCostContent').val().trim().toLowerCase(),
        amount: parseFloat($('#editCostAmount').val()),
        note: $('#editCostNote').val().trim()
    };

    // Валидация
    if (!formData.utm_source && !formData.utm_medium && !formData.utm_campaign &&
        !formData.utm_term && !formData.utm_content) {
        showNotification('error', 'Ошибка', 'Заполните хотя бы одну UTM-метку');
        return;
    }

    $('#updateCostBtn').prop('disabled', true).text('Сохранение...');

    $.ajax({
        url: 'api/handler.php',
        method: 'POST',
        data: {
            action: 'update_manual_cost',
            cost_data: JSON.stringify(formData)
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showNotification('success', 'Успешно', 'Расход обновлён');
                closeModal('editCostModal');
                loadManualCosts();
            } else {
                showNotification('error', 'Ошибка', response.message || 'Не удалось обновить');
            }
        },
        error: function() {
            showNotification('error', 'Ошибка', 'Не удалось обновить расход');
        },
        complete: function() {
            $('#updateCostBtn').prop('disabled', false).text('💾 Сохранить изменения');
        }
    });
}

/**
 * Показать подтверждение удаления
 */
function confirmDeleteCost(id, source, amount) {
    $('#deleteCostId').val(id);
    $('#deleteDetails').html(`
        <strong>Источник:</strong> ${escapeHtml(source) || 'не указан'}<br>
        <strong>Сумма:</strong> ${formatAmount(amount)} UAH
    `);
    openModal('deleteCostModal');
}

/**
 * Удалить расход
 */
function deleteManualCost() {
    const id = $('#deleteCostId').val();

    $('#confirmDeleteBtn').prop('disabled', true).text('Удаление...');

    $.ajax({
        url: 'api/handler.php',
        method: 'POST',
        data: {
            action: 'delete_manual_cost',
            id: id
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showNotification('success', 'Успешно', 'Расход удалён');
                closeModal('deleteCostModal');
                loadManualCosts();
            } else {
                showNotification('error', 'Ошибка', response.message || 'Не удалось удалить');
            }
        },
        error: function() {
            showNotification('error', 'Ошибка', 'Не удалось удалить расход');
        },
        complete: function() {
            $('#confirmDeleteBtn').prop('disabled', false).text('🗑️ Удалить');
        }
    });
}

/**
 * Вспомогательные функции
 */

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ru-RU');
}

function formatAmount(amount) {
    return parseFloat(amount || 0).toLocaleString('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function truncateText(text, maxLength) {
    if (!text || text.length <= maxLength) return text || '-';
    return text.substring(0, maxLength) + '...';
}

function showNotification(type, title, message) {
    const notification = $('#notification');
    notification.removeClass('success error warning info').addClass(type);
    notification.html(`<strong>${title}:</strong> ${message}`);
    notification.addClass('show');

    setTimeout(function() {
        notification.removeClass('show');
    }, 4000);
}

function openModal(modalId) {
    $('#' + modalId).addClass('active');
    $('body').css('overflow', 'hidden');
}

function closeModal(modalId) {
    $('#' + modalId).removeClass('active');
    $('body').css('overflow', 'auto');
}
