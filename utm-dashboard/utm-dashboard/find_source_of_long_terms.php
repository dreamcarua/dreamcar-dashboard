<?php
/**
 * ЗНАЙТИ ДЖЕРЕЛО ДОВГИХ UTM_TERM
 * Де саме в коді вони зберігаються в БД
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 ПОШУК ДЖЕРЕЛА ДОВГИХ UTM_TERM</h1>";
echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 12px; }
    th { background: #3b82f6; color: white; }
    .error { background: #fee; font-weight: bold; }
    .success { background: #efe; }
    pre { background: #f5f5f5; padding: 15px; border-left: 5px solid red; font-size: 11px; max-height: 500px; overflow-y: auto; }
</style>";

$today = date('Y-m-d');

try {
    $db = Database::getInstance();

    // Візьмемо ОДНУ сьогоднішню сделку з довгим utm_term
    $sql = "SELECT deal_id, utm_term, created_at
            FROM crm_deals
            WHERE DATE(created_at) = :today
              AND LENGTH(utm_term) > 50
            LIMIT 1";

    $testDeal = $db->fetchOne($sql, ['today' => $today]);

    if (!$testDeal) {
        echo "<p class='success'>✅ За сьогодні немає сделок з довгими utm_term!</p>";
        echo "<p>Виправлення webhook_crm.php працює!</p>";
        exit;
    }

    $dealId = $testDeal['deal_id'];
    $badUtmTerm = $testDeal['utm_term'];

    echo "<h2>🎯 Тестова сделка: {$dealId}</h2>";
    echo "<p><strong>utm_term в БД:</strong> <code class='error'>{$badUtmTerm}</code></p>";
    echo "<p><strong>created_at:</strong> {$testDeal['created_at']}</p>";

    // ==========================================
    // КРОК 1: ЗНАЙТИ WEBHOOK ЛОГ
    // ==========================================

    echo "<h2>📋 Крок 1: Webhook лог для цієї сделки</h2>";

    $sql = "SELECT id, raw_data, processed_data, created_at
            FROM webhook_log
            WHERE deal_id = :deal_id AND webhook_type = 'crm'
            ORDER BY created_at ASC";

    $webhooks = $db->fetchAll($sql, ['deal_id' => $dealId]);

    echo "<p>Знайдено webhook логів: <strong>" . count($webhooks) . "</strong></p>";

    if (empty($webhooks)) {
        echo "<p class='error'>❌ WEBHOOK ЛОГ НЕ ЗНАЙДЕНО!</p>";
        echo "<p>Сделка була створена БЕЗ webhook або логи видалені</p>";
        exit;
    }

    // Взяти ПЕРШИЙ webhook (new)
    $firstWebhook = $webhooks[0];
    $rawData = json_decode($firstWebhook['raw_data'], true);
    $processedData = json_decode($firstWebhook['processed_data'], true);

    echo "<h3>ПЕРШИЙ webhook (створення сделки):</h3>";
    echo "<p><strong>Webhook ID:</strong> {$firstWebhook['id']}</p>";
    echo "<p><strong>Час:</strong> {$firstWebhook['created_at']}</p>";

    // ==========================================
    // КРОК 2: ЩО В RAW_DATA
    // ==========================================

    echo "<h2>📄 Крок 2: Що прийшло в RAW webhook?</h2>";

    $vars = $rawData['variables'] ?? [];

    echo "<table>";
    echo "<tr><th>Поле в webhook</th><th>Значення</th><th>Довжина</th></tr>";

    $utmFields = [
        'utm_source' => $vars['utm_source'] ?? null,
        'utm_source_deal' => $vars['utm_source_deal'] ?? null,
        'utm_medium' => $vars['utm_medium'] ?? null,
        'utm_medium_deal' => $vars['utm_medium_deal'] ?? null,
        'utm_campaign' => $vars['utm_campaign'] ?? null,
        'utm_campaign_deal' => $vars['utm_campaign_deal'] ?? null,
        'utm_term' => $vars['utm_term'] ?? null,
        'utm_term_deal' => $vars['utm_term_deal'] ?? null,
        'utm_content' => $vars['utm_content'] ?? null,
        'utm_content_deal' => $vars['utm_content_deal'] ?? null
    ];

    foreach ($utmFields as $field => $value) {
        $isLong = ($value && strlen($value) > 50);
        $rowClass = $isLong ? "error" : "";

        echo "<tr class='{$rowClass}'>";
        echo "<td><strong>{$field}</strong></td>";
        echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
        echo "<td>" . strlen($value ?? '') . " " . ($isLong ? "❌ ДОВГИЙ" : "") . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 3: ЩО В PROCESSED_DATA
    // ==========================================

    echo "<h2>⚙️ Крок 3: Що збережено в PROCESSED_DATA?</h2>";

    echo "<table>";
    echo "<tr><th>Поле</th><th>Значення</th></tr>";
    echo "<tr" . (($processedData['utm_source'] ?? '') ? "" : " class='error'") . "><td>utm_source</td><td>" . htmlspecialchars($processedData['utm_source'] ?? 'NULL') . "</td></tr>";
    echo "<tr" . (($processedData['utm_medium'] ?? '') ? "" : " class='error'") . "><td>utm_medium</td><td>" . htmlspecialchars($processedData['utm_medium'] ?? 'NULL') . "</td></tr>";
    echo "<tr" . (($processedData['utm_campaign'] ?? '') ? "" : " class='error'") . "><td>utm_campaign</td><td>" . htmlspecialchars($processedData['utm_campaign'] ?? 'NULL') . "</td></tr>";

    $processedUtmTerm = $processedData['utm_term'] ?? '';
    $isProcessedLong = (strlen($processedUtmTerm) > 50);
    echo "<tr class='" . ($isProcessedLong ? "error" : "") . "'><td><strong>utm_term</strong></td><td>" . htmlspecialchars($processedUtmTerm ?: 'NULL') . "</td></tr>";

    echo "<tr" . (($processedData['utm_content'] ?? '') ? "" : " class='error'") . "><td>utm_content</td><td>" . htmlspecialchars($processedData['utm_content'] ?? 'NULL') . "</td></tr>";
    echo "</table>";

    // ==========================================
    // КРОК 4: АНАЛІЗ - ЩО ПІШЛО НЕ ТАК
    // ==========================================

    echo "<h2>🎯 Крок 4: ЩО ПІШЛО НЕ ТАК?</h2>";

    $utmTermInWebhook = $vars['utm_term'] ?? null;
    $utmTermDealInWebhook = $vars['utm_term_deal'] ?? null;
    $utmTermInProcessed = $processedData['utm_term'] ?? null;
    $utmTermInDB = $badUtmTerm;

    echo "<table>";
    echo "<tr><th>Етап</th><th>Значення utm_term</th><th>Статус</th></tr>";
    echo "<tr><td>1. В webhook (utm_term БЕЗ _deal)</td><td>" . htmlspecialchars($utmTermInWebhook ?? 'NULL') . "</td><td>" . (strlen($utmTermInWebhook ?? '') > 50 ? "❌ ДОВГИЙ" : "✅") . "</td></tr>";
    echo "<tr class='success'><td>2. В webhook (utm_term_deal З _deal)</td><td><strong>" . htmlspecialchars($utmTermDealInWebhook ?? 'NULL') . "</strong></td><td>✅ ПРАВИЛЬНИЙ</td></tr>";
    echo "<tr class='" . (strlen($utmTermInProcessed ?? '') > 50 ? "error" : "") . "'><td>3. В processed_data (що зберіг webhook_crm.php)</td><td>" . htmlspecialchars($utmTermInProcessed ?? 'NULL') . "</td><td>" . (strlen($utmTermInProcessed ?? '') > 50 ? "❌ ДОВГИЙ - ТУТ ПОМИЛКА!" : "✅") . "</td></tr>";
    echo "<tr class='error'><td>4. В БД (crm_deals.utm_term)</td><td>{$utmTermInDB}</td><td>❌ ДОВГИЙ</td></tr>";
    echo "</table>";

    // ВИСНОВОК
    echo "<h2>💡 ДІАГНОЗ</h2>";

    if (strlen($utmTermInProcessed ?? '') > 50) {
        echo "<div style='background: #fee; padding: 20px; border-left: 5px solid red;'>";
        echo "<h3>❌ ПРОБЛЕМА В WEBHOOK_CRM.PHP!</h3>";
        echo "<p><strong>Причина:</strong> webhook_crm.php зберіг ДОВГИЙ utm_term в processed_data</p>";
        echo "<p><strong>Час створення webhook:</strong> {$firstWebhook['created_at']}</p>";

        // Перевірити чи це було ДО виправлення
        $webhookTime = strtotime($firstWebhook['created_at']);
        $now = time();
        $diff = $now - $webhookTime;
        $hours = round($diff / 3600, 1);

        echo "<p><strong>Скільки годин тому:</strong> {$hours} год</p>";

        if ($hours > 2) {
            echo "<p class='error'>Це webhook був СТВОРЕНИЙ ДО виправлення webhook_crm.php!</p>";
            echo "<p><strong>Рішення:</strong> Виправити існуючі записи в БД через fix_today_deals.php</p>";
        } else {
            echo "<p class='error'>Це НОВИЙ webhook (після виправлення) - webhook_crm.php ВСЕ ЩЕ НЕ ПРАЦЮЄ!</p>";
            echo "<p><strong>Рішення:</strong> Перевірити код webhook_crm.php - можливо виправлення не застосувалось</p>";
        }

        echo "<h4>ПОВНИЙ RAW DATA:</h4>";
        echo "<pre>" . json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

        echo "</div>";
    } else {
        echo "<div style='background: #efe; padding: 20px; border-left: 5px solid green;'>";
        echo "<h3>✅ processed_data ПРАВИЛЬНИЙ!</h3>";
        echo "<p>webhook_crm.php зберіг короткий utm_term: <strong>{$utmTermInProcessed}</strong></p>";
        echo "<p>АЛЕ в БД збережено довгий: <strong>{$utmTermInDB}</strong></p>";
        echo "<p><strong>Причина:</strong> Можливо пряме оновлення БД з іншого джерела (не через webhook_crm.php)?</p>";
        echo "</div>";
    }

    // ==========================================
    // ПОВНИЙ RAW DATA
    // ==========================================

    echo "<details>";
    echo "<summary style='cursor: pointer; padding: 10px; background: #f0f0f0; margin: 10px 0;'>📄 Показати ПОВНИЙ RAW_DATA</summary>";
    echo "<pre>" . json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    echo "</details>";

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
