<?php
// === verify_data_integrity.php ===
// НАЗНАЧЕНИЕ: Проверка целостности и качества данных в БД
// ИСПОЛЬЗОВАНИЕ: Запуск через браузер для диагностики
// СВЯЗИ: core/models/CrmDeal.php, core/models/AdsData.php

ini_set('max_execution_time', 600);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/CrmDeal.php';
require_once __DIR__ . '/../core/models/AdsData.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();
$startTime = microtime(true);

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>🔍 Проверка целостности данных</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        h2 { color: #1e40af; margin-top: 30px; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        .info { color: #6b7280; }
        .step { background: #f3f4f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .step-title { font-weight: bold; margin-bottom: 10px; font-size: 16px; }
        .stats { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: bold; }
        .issue { background: #fef2f2; padding: 10px; margin: 5px 0; border-left: 4px solid #ef4444; }
        .ok { background: #f0fdf4; padding: 10px; margin: 5px 0; border-left: 4px solid #10b981; }
        pre { background: #f9fafb; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>";

echo "<h1>🔍 Проверка целостности данных UTM Dashboard</h1>";
echo "<p class='info'>Дата запуска: " . date('Y-m-d H:i:s') . "</p>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    $issues = [];
    $warnings = [];
    $checks = 0;
    $passed = 0;

    // ========================================
    // 1. Проверка подключения к БД
    // ========================================

    echo "<div class='step'>";
    echo "<div class='step-title'>🔌 Проверка подключения к базе данных</div>";
    $checks++;

    try {
        $stmt = $pdo->query("SELECT DATABASE() as db_name, VERSION() as db_version");
        $dbInfo = $stmt->fetch();

        echo "<p class='success'>✅ Подключение установлено</p>";
        echo "<p class='info'>База данных: <strong>{$dbInfo['db_name']}</strong></p>";
        echo "<p class='info'>Версия MySQL: <strong>{$dbInfo['db_version']}</strong></p>";
        $passed++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Ошибка подключения: " . htmlspecialchars($e->getMessage()) . "</p>";
        $issues[] = 'Нет подключения к базе данных';
    }

    echo "</div>";

    // ========================================
    // 2. Проверка наличия таблиц
    // ========================================

    echo "<div class='step'>";
    echo "<div class='step-title'>📋 Проверка структуры таблиц</div>";

    $requiredTables = ['crm_deals', 'ads_data', 'utm_mapping', 'import_log', 'webhook_log'];

    foreach ($requiredTables as $table) {
        $checks++;
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->fetch();

        if ($exists) {
            $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
            $count = $countStmt->fetch()['cnt'];

            echo "<p class='success'>✅ Таблица <strong>$table</strong> существует (записей: " . number_format($count) . ")</p>";
            $passed++;
        } else {
            echo "<p class='error'>❌ Таблица <strong>$table</strong> не найдена</p>";
            $issues[] = "Отсутствует таблица: $table";
        }
    }

    echo "</div>";

    // ========================================
    // 3. Проверка CRM данных
    // ========================================

    echo "<h2>📊 Анализ CRM данных (crm_deals)</h2>";

    echo "<div class='step'>";
    echo "<div class='step-title'>Общая статистика</div>";

    $crmStats = CrmDeal::getStats();

    echo "<table>";
    echo "<tr><th>Метрика</th><th>Значение</th></tr>";
    echo "<tr><td>Всего лидов</td><td>" . number_format($crmStats['total_leads']) . "</td></tr>";
    echo "<tr><td>Оплачено</td><td class='success'>" . number_format($crmStats['paid_count']) . " (" . number_format($crmStats['paid_amount'], 2) . " UAH)</td></tr>";
    echo "<tr><td>Неуспешно</td><td class='error'>" . number_format($crmStats['failed_count']) . " (" . number_format($crmStats['failed_amount'], 2) . " UAH)</td></tr>";
    echo "<tr><td>В ожидании</td><td class='warning'>" . number_format($crmStats['pending_count']) . " (" . number_format($crmStats['pending_amount'], 2) . " UAH)</td></tr>";
    echo "</table>";

    echo "</div>";

    // Проверка дубликатов по deal_id
    echo "<div class='step'>";
    echo "<div class='step-title'>🔍 Проверка дубликатов deal_id</div>";
    $checks++;

    $duplicates = $pdo->query("
        SELECT deal_id, COUNT(*) as cnt
        FROM crm_deals
        WHERE deal_id IS NOT NULL
        GROUP BY deal_id
        HAVING cnt > 1
        ORDER BY cnt DESC
        LIMIT 10
    ")->fetchAll();

    if (empty($duplicates)) {
        echo "<p class='success'>✅ Дубликатов deal_id не найдено</p>";
        $passed++;
    } else {
        echo "<p class='error'>❌ Найдено дубликатов: " . count($duplicates) . "</p>";
        echo "<table>";
        echo "<tr><th>deal_id</th><th>Количество</th></tr>";
        foreach ($duplicates as $dup) {
            echo "<tr><td>{$dup['deal_id']}</td><td>{$dup['cnt']}</td></tr>";
        }
        echo "</table>";
        $issues[] = "Дубликаты deal_id в crm_deals";
    }

    echo "</div>";

    // Проверка пустых UTM меток
    echo "<div class='step'>";
    echo "<div class='step-title'>🏷️ Проверка UTM меток</div>";

    $emptyUtm = $pdo->query("
        SELECT
            SUM(CASE WHEN utm_source IS NULL OR utm_source = '' THEN 1 ELSE 0 END) as empty_source,
            SUM(CASE WHEN utm_medium IS NULL OR utm_medium = '' THEN 1 ELSE 0 END) as empty_medium,
            SUM(CASE WHEN utm_campaign IS NULL OR utm_campaign = '' THEN 1 ELSE 0 END) as empty_campaign
        FROM crm_deals
    ")->fetch();

    echo "<table>";
    echo "<tr><th>Поле</th><th>Пустых значений</th><th>Процент</th></tr>";
    $total = $crmStats['total_leads'];
    foreach (['source', 'medium', 'campaign'] as $field) {
        $empty = $emptyUtm["empty_$field"];
        $percent = $total > 0 ? round($empty / $total * 100, 2) : 0;
        $class = $percent > 50 ? 'error' : ($percent > 20 ? 'warning' : 'success');

        echo "<tr>";
        echo "<td>utm_$field</td>";
        echo "<td class='$class'>" . number_format($empty) . "</td>";
        echo "<td class='$class'>$percent%</td>";
        echo "</tr>";

        if ($percent > 50) {
            $warnings[] = "Более 50% записей без utm_$field";
        }
    }
    echo "</table>";

    echo "</div>";

    // Проверка аномальных сумм
    echo "<div class='step'>";
    echo "<div class='step-title'>💰 Проверка аномальных сумм</div>";
    $checks++;

    $anomalies = $pdo->query("
        SELECT deal_id, email, amount_uah, is_paid, is_failed
        FROM crm_deals
        WHERE amount_uah >= 1000000
        ORDER BY amount_uah DESC
        LIMIT 20
    ")->fetchAll();

    if (empty($anomalies)) {
        echo "<p class='success'>✅ Аномальных сумм (>= 1 млн UAH) не найдено</p>";
        $passed++;
    } else {
        echo "<p class='warning'>⚠️ Найдено аномальных сумм: " . count($anomalies) . "</p>";
        echo "<p class='info'>Эти суммы исключаются из расчетов благодаря фильтру в CrmDeal.php</p>";
        echo "<table>";
        echo "<tr><th>deal_id</th><th>Email</th><th>Сумма (UAH)</th><th>Статус</th></tr>";
        foreach ($anomalies as $row) {
            $status = $row['is_paid'] ? 'Оплачено' : ($row['is_failed'] ? 'Неуспешно' : 'В ожидании');
            echo "<tr>";
            echo "<td>{$row['deal_id']}</td>";
            echo "<td>{$row['email']}</td>";
            echo "<td class='warning'>" . number_format($row['amount_uah'], 2) . "</td>";
            echo "<td>$status</td>";
            echo "</tr>";
        }
        echo "</table>";
        $warnings[] = "Найдено " . count($anomalies) . " записей с аномально высокими суммами";
    }

    echo "</div>";

    // ========================================
    // 4. Проверка рекламных данных
    // ========================================

    echo "<h2>📢 Анализ рекламных данных (ads_data)</h2>";

    echo "<div class='step'>";
    echo "<div class='step-title'>Общая статистика</div>";

    $adsStats = AdsData::getTotalStats();

    echo "<table>";
    echo "<tr><th>Метрика</th><th>Значение</th></tr>";
    echo "<tr><td>Всего записей</td><td>" . number_format(AdsData::count()) . "</td></tr>";
    echo "<tr><td>Общие затраты</td><td>" . number_format($adsStats['total_spend'], 2) . " UAH</td></tr>";
    echo "<tr><td>Кликов</td><td>" . number_format($adsStats['total_clicks']) . "</td></tr>";
    echo "<tr><td>Показов</td><td>" . number_format($adsStats['total_impressions']) . "</td></tr>";
    echo "<tr><td>Охват</td><td>" . number_format($adsStats['total_reach']) . "</td></tr>";
    echo "<tr><td>Средний CPM</td><td>" . number_format($adsStats['avg_cpm'], 2) . "</td></tr>";
    echo "<tr><td>Средний CTR</td><td>" . number_format($adsStats['avg_ctr'], 2) . "%</td></tr>";
    echo "</table>";

    echo "</div>";

    // Проверка дубликатов рекламных данных
    echo "<div class='step'>";
    echo "<div class='step-title'>🔍 Проверка дубликатов рекламных записей</div>";
    $checks++;

    $adsDuplicates = $pdo->query("
        SELECT
            date_start, account_id, campaign_id, adset_id, ad_id,
            publisher_platform, platform_position,
            COUNT(*) as cnt
        FROM ads_data
        GROUP BY date_start, account_id, campaign_id, adset_id, ad_id,
                 publisher_platform, platform_position
        HAVING cnt > 1
        ORDER BY cnt DESC
        LIMIT 10
    ")->fetchAll();

    if (empty($adsDuplicates)) {
        echo "<p class='success'>✅ Дубликатов рекламных записей не найдено</p>";
        $passed++;
    } else {
        echo "<p class='warning'>⚠️ Найдено дубликатов: " . count($adsDuplicates) . "</p>";
        echo "<p class='info'>Это может быть нормально из-за ON DUPLICATE KEY UPDATE</p>";
        $warnings[] = "Возможные дубликаты в ads_data";
    }

    echo "</div>";

    // ========================================
    // 5. Проверка webhook логов
    // ========================================

    echo "<h2>📝 Анализ webhook логов (webhook_log)</h2>";

    echo "<div class='step'>";
    echo "<div class='step-title'>Статистика webhook запросов</div>";

    $webhookStats = $pdo->query("
        SELECT
            webhook_type,
            event_type,
            COUNT(*) as total,
            SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed,
            AVG(processing_time) as avg_time,
            MAX(processing_time) as max_time
        FROM webhook_log
        GROUP BY webhook_type, event_type
        ORDER BY webhook_type, event_type
    ")->fetchAll();

    if (empty($webhookStats)) {
        echo "<p class='info'>ℹ️ Webhook логов пока нет</p>";
    } else {
        echo "<table>";
        echo "<tr><th>Тип</th><th>Событие</th><th>Всего</th><th>Успешно</th><th>Ошибок</th><th>Ср. время (сек)</th><th>Макс. время (сек)</th></tr>";
        foreach ($webhookStats as $stat) {
            $successRate = $stat['total'] > 0 ? round($stat['successful'] / $stat['total'] * 100, 1) : 0;
            $class = $stat['failed'] > 0 ? 'warning' : 'success';

            echo "<tr>";
            echo "<td>{$stat['webhook_type']}</td>";
            echo "<td>{$stat['event_type']}</td>";
            echo "<td>" . number_format($stat['total']) . "</td>";
            echo "<td class='success'>" . number_format($stat['successful']) . " ($successRate%)</td>";
            echo "<td class='$class'>" . number_format($stat['failed']) . "</td>";
            echo "<td>" . number_format($stat['avg_time'], 3) . "</td>";
            echo "<td>" . number_format($stat['max_time'], 3) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "</div>";

    // ========================================
    // 6. Сравнение с test_calculations.php
    // ========================================

    echo "<h2>🧮 Сравнение расчетов</h2>";

    echo "<div class='step'>";
    echo "<div class='step-title'>Проверка соответствия с test_calculations.php</div>";

    echo "<p class='info'>Расчеты через CrmDeal::getStats() (с фильтром amount_uah < 1000000):</p>";

    echo "<table>";
    echo "<tr><th>Метрика</th><th>Значение</th></tr>";
    echo "<tr><td>Всего лидов</td><td>" . number_format($crmStats['total_leads']) . "</td></tr>";
    echo "<tr><td>Оплаченная сумма</td><td>" . number_format($crmStats['paid_amount'], 2) . " UAH</td></tr>";
    echo "<tr><td>Затраты на рекламу</td><td>" . number_format($adsStats['total_spend'], 2) . " UAH</td></tr>";

    if ($adsStats['total_spend'] > 0) {
        $roi = (($crmStats['paid_amount'] - $adsStats['total_spend']) / $adsStats['total_spend']) * 100;
        $roas = $crmStats['paid_amount'] / $adsStats['total_spend'];

        echo "<tr><td>ROI</td><td class='success'>" . number_format($roi, 2) . "%</td></tr>";
        echo "<tr><td>ROAS</td><td class='success'>" . number_format($roas, 2) . "</td></tr>";
    }

    echo "</table>";

    echo "<p class='info'>✅ Если эти цифры совпадают с дашбордом - все корректно</p>";

    echo "</div>";

    // ========================================
    // Итоги
    // ========================================

    $duration = round(microtime(true) - $startTime, 2);

    echo "<div class='stats'>";
    echo "<h2>📊 Итоговый отчет</h2>";
    echo "<p><strong>Время выполнения:</strong> $duration секунд</p>";
    echo "<p><strong>Проверок выполнено:</strong> $checks</p>";
    echo "<p><strong>Успешных проверок:</strong> $passed</p>";
    echo "<p><strong>Критических ошибок:</strong> " . count($issues) . "</p>";
    echo "<p><strong>Предупреждений:</strong> " . count($warnings) . "</p>";

    if (empty($issues)) {
        echo "<div class='ok'>";
        echo "<h3 class='success'>✅ Критических проблем не обнаружено</h3>";
        echo "<p>База данных находится в хорошем состоянии.</p>";
        echo "</div>";
    } else {
        echo "<div class='issue'>";
        echo "<h3 class='error'>❌ Обнаружены критические проблемы:</h3>";
        echo "<ul>";
        foreach ($issues as $issue) {
            echo "<li>$issue</li>";
        }
        echo "</ul>";
        echo "</div>";
    }

    if (!empty($warnings)) {
        echo "<div class='issue' style='border-color: #f59e0b; background: #fffbeb;'>";
        echo "<h3 class='warning'>⚠️ Предупреждения:</h3>";
        echo "<ul>";
        foreach ($warnings as $warning) {
            echo "<li>$warning</li>";
        }
        echo "</ul>";
        echo "</div>";
    }

    echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";
    echo "<p><a href='verify_data_integrity.php'>🔄 Запустить проверку снова</a></p>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='step' style='border: 2px solid #ef4444;'>";
    echo "<h2 class='error'>❌ Ошибка проверки</h2>";
    echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background:#fef2f2; padding:10px; border-radius:5px; color:#ef4444;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    echo "</div>";

    $logger->error('Ошибка проверки целостности данных', [
        'error' => $e->getMessage()
    ]);
}

echo "</body></html>";
