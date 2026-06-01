<?php
// === webhook_logs.php ===
// НАЗНАЧЕНИЕ: Интерфейс просмотра webhook логов
// ИСПОЛЬЗОВАНИЕ: Браузер https://dreamcar.ai-platform.space/dashboard/utm-dashboard/webhook_logs.php

require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/models/WebhookLog.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Session.php';

// Тільки для адміністраторів!
Auth::requireAdmin();

// Параметры фильтрации
$webhookType = $_GET['webhook_type'] ?? '';
$eventType = $_GET['event_type'] ?? '';
$success = (isset($_GET['success']) && $_GET['success'] !== '') ? (int)$_GET['success'] : null;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;

// Построить фильтры
$filters = [];
if ($webhookType) $filters['webhook_type'] = $webhookType;
if ($eventType) $filters['event_type'] = $eventType;
if ($success !== null) $filters['success'] = $success;
if ($dateFrom) $filters['date_from'] = $dateFrom . ' 00:00:00';
if ($dateTo) $filters['date_to'] = $dateTo . ' 23:59:59';

// Получить логи
$offset = ($page - 1) * $perPage;
$logs = WebhookLog::getRecent($filters, $perPage, $offset);
$totalLogs = WebhookLog::count($filters);
$totalPages = ceil($totalLogs / $perPage);

// Получить статистику
$stats = WebhookLog::getStats($filters);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📝 Webhook Логи | UTM Dashboard</title>

    <!-- FOUC Prevention: применить тему ДО загрузки CSS -->
    <script>
    (function() {
        var saved = localStorage.getItem('zk_theme_mode');
        var isLight;
        if (saved === 'light' || saved === 'dark') {
            isLight = saved === 'light';
        } else {
            var h = new Date().getHours();
            isLight = !(h >= 22 || h < 7) &&
                      !(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        }
        if (isLight) document.documentElement.classList.add('light-theme');
    })();
    </script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/animations.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="dashboard-container">
        <!-- Заголовок -->
        <header class="dashboard-header">
            <div class="header-content">
                <div class="header-left">
                    <h1 class="gradient-text">📝 Webhook Логи</h1>
                    <p class="text-muted">Мониторинг входящих webhook запросов</p>
                </div>
                <div class="header-right">
                    <a href="index.php" class="btn btn-secondary">← Дашборд</a>
                    <a href="upload_deals.php" class="btn btn-secondary">📤 Загрузить сделки</a>
                    <a href="upload_ads_mysql.php" class="btn btn-secondary">📊 Загрузить рекламу</a>
                    <a href="scripts/verify_data_integrity.php" class="btn btn-secondary">🔍 Проверка данных</a>
                    <button class="btn btn-primary" onclick="location.reload()">🔄 Обновить</button>
                </div>
            </div>
        </header>

        <!-- Статистика -->
        <section class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($totalLogs); ?></div>
                        <div class="stat-label">Всего запросов</div>
                    </div>
                </div>

                <?php foreach ($stats as $stat): ?>
                <div class="stat-card">
                    <div class="stat-icon"><?php echo $stat['webhook_type'] === 'crm' ? '👤' : '📢'; ?></div>
                    <div class="stat-content">
                        <div class="stat-value" style="font-size: 20px;">
                            <span style="color: #10b981;"><?php echo $stat['successful_requests']; ?></span> /
                            <span style="color: #ef4444;"><?php echo $stat['failed_requests']; ?></span>
                        </div>
                        <div class="stat-label"><?php echo strtoupper($stat['webhook_type']); ?> / <?php echo ucfirst($stat['event_type']); ?></div>
                        <div class="stat-info" style="font-size: 11px; margin-top: 4px;">
                            ⏱️ Avg: <?php echo number_format($stat['avg_processing_time'], 3); ?>s
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Фильтры -->
        <section class="filters-section" style="background: white; padding: 20px; border-radius: 12px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; align-items: end;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Тип webhook</label>
                    <select name="webhook_type" class="filter-select" style="width: 100%;">
                        <option value="">Все</option>
                        <option value="crm" <?php echo $webhookType === 'crm' ? 'selected' : ''; ?>>👤 CRM</option>
                        <option value="ads" <?php echo $webhookType === 'ads' ? 'selected' : ''; ?>>📢 Реклама</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Тип события</label>
                    <select name="event_type" class="filter-select" style="width: 100%;">
                        <option value="">Все</option>
                        <option value="new" <?php echo $eventType === 'new' ? 'selected' : ''; ?>>🆕 New</option>
                        <option value="pay" <?php echo $eventType === 'pay' ? 'selected' : ''; ?>>💰 Pay</option>
                        <option value="fail" <?php echo $eventType === 'fail' ? 'selected' : ''; ?>>❌ Fail</option>
                        <option value="bulk" <?php echo $eventType === 'bulk' ? 'selected' : ''; ?>>📦 Bulk</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Статус</label>
                    <select name="success" class="filter-select" style="width: 100%;">
                        <option value="">Все</option>
                        <option value="1" <?php echo $success === 1 ? 'selected' : ''; ?>>✅ Успешно</option>
                        <option value="0" <?php echo ($success !== null && $success === 0) ? 'selected' : ''; ?>>❌ Ошибка</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Дата от</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="filter-select" style="width: 100%;">
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Дата до</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="filter-select" style="width: 100%;">
                </div>

                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">🔍 Применить</button>
                    <a href="webhook_logs.php" class="btn btn-secondary">🔄 Сброс</a>
                </div>
            </form>
        </section>

        <!-- Таблица логов -->
        <section class="data-table-section" style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 140px;">Дата/Время</th>
                            <th style="width: 80px;">Тип</th>
                            <th style="width: 90px;">Событие</th>
                            <th style="width: 120px;">Deal ID</th>
                            <th style="width: 70px;">Записей</th>
                            <th style="width: 100px;">Статус</th>
                            <th style="width: 80px;">Время</th>
                            <th style="width: 110px;">IP адрес</th>
                            <th style="width: 100px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 60px 20px; color: #9ca3af;">
                                <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
                                <div style="font-size: 16px; font-weight: 600; color: #6b7280;">Логов не найдено</div>
                                <div style="font-size: 14px; margin-top: 8px;">Попробуйте изменить параметры фильтрации</div>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr style="border-bottom: 1px solid #f3f4f6;">
                                <td>
                                    <strong style="color: #2563eb;">#<?php echo $log['id']; ?></strong>
                                </td>
                                <td style="font-size: 13px; color: #6b7280;">
                                    <?php echo date('d.m.Y', strtotime($log['created_at'])); ?><br>
                                    <span style="color: #9ca3af;"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?php echo $log['webhook_type'] === 'crm' ? '#dbeafe' : '#fef3c7'; ?>; color: <?php echo $log['webhook_type'] === 'crm' ? '#1e40af' : '#92400e'; ?>; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                        <?php echo $log['webhook_type'] === 'crm' ? '👤 CRM' : '📢 ADS'; ?>
                                    </span>
                                </td>
                                <td style="font-size: 13px; font-weight: 500;">
                                    <?php echo htmlspecialchars($log['event_type'] ?? '-'); ?>
                                </td>
                                <td style="font-size: 12px; font-family: monospace; color: #6b7280;">
                                    <?php echo htmlspecialchars($log['deal_id'] ?? '-'); ?>
                                </td>
                                <td style="text-align: center; font-weight: 600;">
                                    <?php echo $log['records_count']; ?>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?php echo $log['success'] ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $log['success'] ? '#065f46' : '#991b1b'; ?>; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                        <?php echo $log['success'] ? '✅ OK' : '❌ Error'; ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px; text-align: right; font-family: monospace;">
                                    <?php echo number_format($log['processing_time'], 3); ?>s
                                </td>
                                <td style="font-size: 11px; color: #9ca3af; font-family: monospace;">
                                    <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                                </td>
                                <td>
                                    <button onclick="viewLog(<?php echo $log['id']; ?>)" class="btn btn-sm" style="padding: 6px 12px; font-size: 12px; background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb;">
                                        👁️ Детали
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination" style="display: flex; justify-content: center; gap: 8px; padding: 20px; border-top: 1px solid #f3f4f6;">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-sm">← Назад</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="btn btn-sm btn-primary" style="pointer-events: none;"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="btn btn-sm"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-sm">Вперед →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Модальное окно -->
    <div id="logModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; overflow-y: auto;">
        <div style="background: white; margin: 40px auto; padding: 0; max-width: 1000px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 24px 32px; border-bottom: 2px solid #f3f4f6;">
                <h2 style="margin: 0; font-size: 22px; font-weight: 700; color: #111827;">Детали webhook лога</h2>
                <button onclick="closeModal()" style="background: none; border: none; font-size: 32px; color: #9ca3af; cursor: pointer; line-height: 1;">&times;</button>
            </div>
            <div id="logDetails" style="padding: 32px; max-height: calc(100vh - 200px); overflow-y: auto;"></div>
        </div>
    </div>

    <script>
        function viewLog(id) {
            fetch('api/get_webhook_log.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showLogDetails(data.log);
                    } else {
                        alert('Ошибка: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Ошибка загрузки данных');
                    console.error(error);
                });
        }

        function showLogDetails(log) {
            let html = '';

            // Основная информация
            html += '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px;">';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Тип webhook</div>';
            html += '<div style="font-size: 16px; font-weight: 600; color: #111827;">' + (log.webhook_type === 'crm' ? '👤 CRM' : '📢 Реклама') + '</div>';
            html += '</div>';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Тип события</div>';
            html += '<div style="font-size: 16px; font-weight: 600; color: #111827;">' + (log.event_type || '-') + '</div>';
            html += '</div>';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Deal ID</div>';
            html += '<div style="font-size: 16px; font-weight: 600; color: #111827; font-family: monospace;">' + (log.deal_id || '-') + '</div>';
            html += '</div>';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Статус</div>';
            html += '<div style="font-size: 16px; font-weight: 600;">' + (log.success ? '<span style="color: #10b981;">✅ Успешно</span>' : '<span style="color: #ef4444;">❌ Ошибка</span>') + '</div>';
            html += '</div>';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Записей</div>';
            html += '<div style="font-size: 16px; font-weight: 600; color: #111827;">' + log.records_count + '</div>';
            html += '</div>';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Время обработки</div>';
            html += '<div style="font-size: 16px; font-weight: 600; color: #111827;">' + log.processing_time + ' сек</div>';
            html += '</div>';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">IP адрес</div>';
            html += '<div style="font-size: 16px; font-weight: 600; color: #111827; font-family: monospace;">' + (log.ip_address || '-') + '</div>';
            html += '</div>';

            html += '<div>';
            html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Дата</div>';
            html += '<div style="font-size: 16px; font-weight: 600; color: #111827;">' + log.created_at + '</div>';
            html += '</div>';

            html += '</div>';

            if (log.user_agent) {
                html += '<div style="margin-bottom: 24px; padding: 12px; background: #f9fafb; border-radius: 8px;">';
                html += '<div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">User-Agent</div>';
                html += '<div style="font-size: 13px; color: #374151; word-break: break-all;">' + escapeHtml(log.user_agent) + '</div>';
                html += '</div>';
            }

            if (log.error_message) {
                html += '<div style="margin-bottom: 24px; padding: 16px; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 8px;">';
                html += '<div style="font-size: 14px; font-weight: 700; color: #991b1b; margin-bottom: 8px;">❌ Ошибка</div>';
                html += '<pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; font-family: monospace; font-size: 13px; color: #7f1d1d;">' + escapeHtml(log.error_message) + '</pre>';
                html += '</div>';
            }

            html += '<div style="margin-bottom: 20px;">';
            html += '<div style="font-size: 14px; font-weight: 700; color: #111827; margin-bottom: 12px;">📥 Сырые данные (raw_data)</div>';
            html += '<pre style="background: #f3f4f6; padding: 16px; border-radius: 8px; overflow-x: auto; font-family: monospace; font-size: 12px; line-height: 1.6; color: #374151; margin: 0;">' + escapeHtml(log.raw_data) + '</pre>';
            html += '</div>';

            if (log.processed_data) {
                html += '<div>';
                html += '<div style="font-size: 14px; font-weight: 700; color: #111827; margin-bottom: 12px;">⚙️ Обработанные данные (processed_data)</div>';
                html += '<pre style="background: #f3f4f6; padding: 16px; border-radius: 8px; overflow-x: auto; font-family: monospace; font-size: 12px; line-height: 1.6; color: #374151; margin: 0;">' + escapeHtml(log.processed_data) + '</pre>';
                html += '</div>';
            }

            document.getElementById('logDetails').innerHTML = html;
            document.getElementById('logModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('logModal').style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        window.onclick = function(event) {
            var modal = document.getElementById('logModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
    <script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>
</body>
</html>
