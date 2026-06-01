<?php
require_once 'config.php';
require_once 'lib/MetaAPI.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Ads API Tester - DreamCar.ua</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1>🎯 Meta Ads API Tester</h1>
            <p>Тестирование подключений к Facebook/Instagram Ads API</p>
        </header>

        <!-- Connection Status -->
        <section class="section">
            <h2>🔗 Статус подключения</h2>

            <div class="connection-methods">
                <div class="method-card">
                    <h3>Метод 1: App Access Token</h3>
                    <code class="token"><?php echo META_APP_ID; ?>|<?php echo META_APP_SECRET; ?></code>
                    <div class="status" id="status-app-token">
                        <span class="badge badge-pending">Не протестировано</span>
                    </div>
                    <button class="btn btn-primary" onclick="testConnection('app_token')">🧪 Тестировать</button>
                </div>

                <div class="method-card">
                    <h3>Метод 2: Client Token</h3>
                    <code class="token"><?php echo META_CLIENT_TOKEN; ?></code>
                    <div class="status" id="status-client-token">
                        <span class="badge badge-pending">Не протестировано</span>
                    </div>
                    <button class="btn btn-primary" onclick="testConnection('client_token')">🧪 Тестировать</button>
                </div>

                <div class="method-card">
                    <h3>Метод 3: User Access Token</h3>
                    <input type="text" id="user-token-input" placeholder="Вставьте User Access Token" class="input-token">
                    <div class="status" id="status-user-token">
                        <span class="badge badge-pending">Не протестировано</span>
                    </div>
                    <button class="btn btn-primary" onclick="testConnection('user_token')">🧪 Тестировать</button>
                    <a href="#" class="link" onclick="showOAuthInstructions()">❓ Как получить токен?</a>
                </div>
            </div>
        </section>

        <!-- Ad Accounts -->
        <section class="section">
            <h2>📊 Рекламные аккаунты (<?php echo count($AD_ACCOUNTS); ?>)</h2>

            <div class="accounts-grid" id="accounts-list">
                <?php foreach ($AD_ACCOUNTS as $key => $account): ?>
                <div class="account-card" data-account-id="<?php echo $account['id']; ?>">
                    <div class="account-header">
                        <h3><?php echo htmlspecialchars($account['name']); ?></h3>
                        <span class="badge badge-pending" id="badge-<?php echo $key; ?>">Не проверен</span>
                    </div>
                    <div class="account-info">
                        <div class="info-row">
                            <span class="label">ID:</span>
                            <code><?php echo $account['id']; ?></code>
                        </div>
                        <div class="info-row">
                            <span class="label">Валюта:</span>
                            <span><?php echo $account['currency']; ?></span>
                        </div>
                        <div class="info-row" id="balance-<?php echo $key; ?>" style="display:none;">
                            <span class="label">Баланс:</span>
                            <span class="balance"></span>
                        </div>
                        <div class="info-row" id="status-row-<?php echo $key; ?>" style="display:none;">
                            <span class="label">Статус:</span>
                            <span class="account-status"></span>
                        </div>
                    </div>
                    <div class="account-actions">
                        <button class="btn btn-sm" onclick="testAccount('<?php echo $key; ?>', '<?php echo $account['id']; ?>')">Проверить</button>
                        <button class="btn btn-sm" onclick="getCampaigns('<?php echo $account['id']; ?>')">Кампании</button>
                        <button class="btn btn-sm" onclick="getInsights('<?php echo $account['id']; ?>')">Статистика</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Results Display -->
        <section class="section">
            <h2>📋 Результаты тестов</h2>
            <div class="results-container" id="results">
                <div class="placeholder">Выберите тест для отображения результатов</div>
            </div>
        </section>

        <!-- API Logs -->
        <section class="section">
            <h2>📝 Лог API запросов</h2>
            <button class="btn btn-outline btn-sm" onclick="refreshLogs()">🔄 Обновить</button>
            <div class="logs-container" id="logs">
                <div class="placeholder">Логи запросов появятся здесь</div>
            </div>
        </section>

        <!-- OAuth Instructions Modal -->
        <div class="modal" id="oauth-modal" style="display:none;">
            <div class="modal-content">
                <span class="close" onclick="closeModal('oauth-modal')">&times;</span>
                <h2>Как получить User Access Token</h2>
                <ol>
                    <li>Перейдите на <a href="https://developers.facebook.com/tools/explorer/" target="_blank">Graph API Explorer</a></li>
                    <li>Выберите ваше приложение (App ID: <?php echo META_APP_ID; ?>)</li>
                    <li>Нажмите "Generate Access Token"</li>
                    <li>Разрешите права: <code>ads_management</code>, <code>ads_read</code></li>
                    <li>Скопируйте токен и вставьте выше</li>
                </ol>
                <p><strong>Важно:</strong> User Access Token имеет ограниченный срок действия (обычно 1-2 часа)</p>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
