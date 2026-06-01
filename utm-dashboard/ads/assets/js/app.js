// Meta Ads API Tester - JavaScript Logic

let currentToken = null;

$(document).ready(function() {
    console.log('🚀 Meta Ads API Tester загружен');
    refreshLogs();
});

// Тест подключения
function testConnection(method) {
    const statusId = '#status-' + method.replace('_', '-');
    $(statusId).html('<span class="badge badge-pending">Тестируется...</span>');

    let token = null;
    if (method === 'user_token') {
        token = $('#user-token-input').val().trim();
        if (!token) {
            $(statusId).html('<span class="badge badge-error">Токен не указан</span>');
            showNotification('error', 'Введите User Access Token');
            return;
        }
    }

    $.ajax({
        url: 'api/test_connection.php',
        method: 'POST',
        data: { method: method, token: token },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $(statusId).html('<span class="badge badge-success">✅ Работает</span>');

                // Сохранить токен для дальнейших запросов
                if (method === 'user_token') {
                    currentToken = token;
                } else if (method === 'app_token') {
                    currentToken = 'app_token';
                } else {
                    currentToken = 'client_token';
                }

                showResult('Тест подключения: ' + method, response.data);

                let message = 'Подключение успешно! Аккаунтов доступно: ' + response.data.accounts_count;

                // Если есть список аккаунтов - показать их
                if (response.data.accounts && response.data.accounts.length > 0) {
                    response.data.accounts.forEach(function(acc) {
                        $('#badge-' + acc.key).html('<span class="badge badge-success">✅ Доступен</span>');
                    });
                    message += ' (' + response.data.accounts.map(a => a.name).join(', ') + ')';
                }

                showNotification('success', message);
            } else {
                $(statusId).html('<span class="badge badge-error">❌ Ошибка</span>');
                showResult('Ошибка теста: ' + method, { error: response.error });
                showNotification('error', response.error);
            }
        },
        error: function() {
            $(statusId).html('<span class="badge badge-error">❌ Ошибка сети</span>');
            showNotification('error', 'Ошибка сети');
        }
    });
}

// Тест аккаунта
function testAccount(key, accountId) {
    if (!currentToken) {
        showNotification('error', 'Сначала пройдите тест подключения');
        return;
    }

    $('#badge-' + key).html('<span class="badge badge-pending">Проверка...</span>');

    $.ajax({
        url: 'api/get_account.php',
        method: 'GET',
        data: { account_id: accountId, token: currentToken },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const data = response.data;
                $('#badge-' + key).html('<span class="badge badge-success">✅ Активен</span>');

                // Показать баланс
                if (data.balance !== undefined) {
                    const balance = (data.balance / 100).toFixed(2); // Meta возвращает в центах
                    $('#balance-' + key + ' .balance').text(balance + ' ' + data.currency);
                    $('#balance-' + key).show();
                }

                // Показать статус
                if (data.account_status !== undefined) {
                    const statusMap = {
                        1: '✅ Активен',
                        2: '⚠️ Отключен',
                        3: '❌ Незавершенный',
                        7: '⏸️ На паузе'
                    };
                    $('#status-row-' + key + ' .account-status').text(statusMap[data.account_status] || 'Unknown');
                    $('#status-row-' + key).show();
                }

                showResult('Аккаунт: ' + accountId, data);
                showNotification('success', 'Аккаунт проверен');
            } else {
                $('#badge-' + key).html('<span class="badge badge-error">❌ Ошибка</span>');
                showResult('Ошибка аккаунта: ' + accountId, { error: response.error });
                showNotification('error', response.error);
            }
        },
        error: function() {
            $('#badge-' + key).html('<span class="badge badge-error">❌ Ошибка</span>');
            showNotification('error', 'Ошибка сети');
        }
    });
}

// Получить кампании
function getCampaigns(accountId) {
    if (!currentToken) {
        showNotification('error', 'Сначала пройдите тест подключения');
        return;
    }

    showNotification('info', 'Загрузка кампаний...');

    $.ajax({
        url: 'api/get_campaigns.php',
        method: 'GET',
        data: { account_id: accountId, token: currentToken },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showResult('Кампании: ' + accountId + ' (' + response.count + ' шт)', response.data);
                showNotification('success', 'Найдено кампаний: ' + response.count);
            } else {
                showResult('Ошибка кампаний: ' + accountId, { error: response.error });
                showNotification('error', response.error);
            }
        },
        error: function() {
            showNotification('error', 'Ошибка сети');
        }
    });
}

// Получить статистику
function getInsights(accountId) {
    if (!currentToken) {
        showNotification('error', 'Сначала пройдите тест подключения');
        return;
    }

    showNotification('info', 'Загрузка статистики...');

    $.ajax({
        url: 'api/get_insights.php',
        method: 'GET',
        data: { account_id: accountId, token: currentToken, date_preset: 'last_7d' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showResult('Статистика: ' + accountId + ' (за 7 дней)', response.data);
                showNotification('success', 'Статистика загружена: ' + response.count + ' записей');
            } else {
                showResult('Ошибка статистики: ' + accountId, { error: response.error });
                showNotification('error', response.error);
            }
        },
        error: function() {
            showNotification('error', 'Ошибка сети');
        }
    });
}

// Показать результат
function showResult(title, data) {
    const resultsContainer = $('#results');
    resultsContainer.find('.placeholder').remove();

    const resultHtml = `
        <div class="result-item">
            <div class="result-header">
                <div class="result-title">${title}</div>
                <div class="result-time">${new Date().toLocaleTimeString()}</div>
            </div>
            <div class="result-data">${JSON.stringify(data, null, 2)}</div>
        </div>
    `;

    resultsContainer.prepend(resultHtml);

    // Держать последние 10 результатов
    const items = resultsContainer.find('.result-item');
    if (items.length > 10) {
        items.slice(10).remove();
    }
}

// Обновить логи
function refreshLogs() {
    $.ajax({
        url: 'api/get_logs.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            const logsContainer = $('#logs');
            logsContainer.empty();

            if (response.logs && response.logs.length > 0) {
                response.logs.forEach(function(log) {
                    const statusClass = log.success ? 'success' : 'error';
                    const logHtml = `
                        <div class="log-item ${statusClass}">
                            <div class="log-header">
                                <div class="log-timestamp">${log.timestamp}</div>
                                <div class="log-method">${log.method} ${log.endpoint}</div>
                                <div class="log-time">${log.response_time}</div>
                            </div>
                            <div class="log-details">${JSON.stringify(log.data, null, 2)}</div>
                        </div>
                    `;
                    logsContainer.append(logHtml);
                });
            } else {
                logsContainer.html('<div class="placeholder">Нет логов</div>');
            }
        }
    });
}

// Показать инструкции OAuth
function showOAuthInstructions() {
    $('#oauth-modal').fadeIn();
}

// Закрыть модальное окно
function closeModal(modalId) {
    $('#' + modalId).fadeOut();
}

// Уведомления
function showNotification(type, message) {
    const color = {
        'success': 'var(--accent-green)',
        'error': 'var(--accent-red)',
        'info': 'var(--accent-blue)',
        'warning': 'var(--accent-yellow)'
    }[type] || 'var(--accent-blue)';

    // Простое console уведомление (можно заменить на toast)
    console.log(`[${type.toUpperCase()}] ${message}`);

    // Можно добавить toast библиотеку позже
}
