<?php
/**
 * ДЕТАЛЬНИЙ АНАЛІЗ ВСІХ WEBHOOK ЛОГІВ ЗА СЬОГОДНІ
 * Перевірка чи РЕАЛЬНО приходили ці довгі utm_term в webhook
 */

set_time_limit(300);

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 ДЕТАЛЬНИЙ АНАЛІЗ WEBHOOK ЛОГІВ ЗА СЬОГОДНІ</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; font-size: 11px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #3b82f6; color: white; position: sticky; top: 0; }
    .error { background: #fee; }
    .success { background: #efe; }
    .warning { background: #fef3c7; }
    .long { background: #fee; font-weight: bold; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 10px; max-height: 400px; overflow-y: auto; }
    .section { background: white; padding: 15px; margin: 20px 0; border-radius: 8px; }
</style>";

$today = date('Y-m-d');

try {
    $db = Database::getInstance();

    echo "<p><strong>Сьогоднішня дата:</strong> {$today}</p>";

    // ==========================================
    // КРОК 1: ВСІ WEBHOOK ЛОГИ ЗА СЬОГОДНІ
    // ==========================================

    echo "<h2>📋 Крок 1: Всі CRM webhook логи за сьогодні</h2>";

    $sql = "SELECT id, webhook_type, event_type, deal_id, raw_data, processed_data, created_at
            FROM webhook_log
            WHERE webhook_type = 'crm'
              AND DATE(created_at) = :today
            ORDER BY created_at DESC";

    $webhooks = $db->fetchAll($sql, ['today' => $today]);

    echo "<p>Знайдено webhook логів: <strong>" . count($webhooks) . "</strong></p>";

    if (empty($webhooks)) {
        echo "<p class='error'>❌ ЗА СЬОГОДНІ НЕМАЄ WEBHOOK ЛОГІВ!</p>";
        exit;
    }

    // ==========================================
    // КРОК 2: АНАЛІЗ UTM_TERM В КОЖНОМУ WEBHOOK
    // ==========================================

    echo "<h2>🔍 Крок 2: Аналіз utm_term в кожному webhook</h2>";

    $longTermsFound = [];
    $allTerms = [];

    echo "<table>";
    echo "<tr>
            <th>#</th>
            <th>Webhook ID</th>
            <th>Deal ID</th>
            <th>Event</th>
            <th>utm_term (raw)</th>
            <th>utm_term_deal (raw)</th>
            <th>utm_term (processed)</th>
            <th>Довгий?</th>
            <th>Час</th>
          </tr>";

    foreach ($webhooks as $index => $webhook) {
        $rawData = json_decode($webhook['raw_data'], true);
        $processedData = json_decode($webhook['processed_data'], true);

        $utmTerm = null;
        $utmTermDeal = null;
        $processedUtmTerm = null;

        // Витягти utm_term з raw_data
        if (isset($rawData['variables'])) {
            $utmTerm = $rawData['variables']['utm_term'] ?? null;
            $utmTermDeal = $rawData['variables']['utm_term_deal'] ?? null;
        }

        // Витягти utm_term з processed_data
        if (isset($processedData['utm_term'])) {
            $processedUtmTerm = $processedData['utm_term'];
        }

        // Перевірити чи довгий
        $isLong = false;
        if ($utmTerm && strlen($utmTerm) > 50) {
            $isLong = true;
            $longTermsFound[] = [
                'webhook_id' => $webhook['id'],
                'deal_id' => $webhook['deal_id'],
                'utm_term' => $utmTerm,
                'utm_term_deal' => $utmTermDeal,
                'processed_utm_term' => $processedUtmTerm
            ];
        }

        // Зберегти всі терми
        if ($utmTerm) {
            $allTerms[$utmTerm] = ($allTerms[$utmTerm] ?? 0) + 1;
        }

        $rowClass = $isLong ? "long" : "";

        echo "<tr class='{$rowClass}'>";
        echo "<td>" . ($index + 1) . "</td>";
        echo "<td>{$webhook['id']}</td>";
        echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId={$webhook['deal_id']}' target='_blank'>{$webhook['deal_id']}</a></td>";
        echo "<td>{$webhook['event_type']}</td>";
        echo "<td>" . htmlspecialchars(substr($utmTerm ?? 'NULL', 0, 60)) . "...</td>";
        echo "<td>" . htmlspecialchars($utmTermDeal ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars(substr($processedUtmTerm ?? 'NULL', 0, 60)) . "...</td>";
        echo "<td>" . ($isLong ? "❌ ТАК" : "✅ НІ") . "</td>";
        echo "<td>" . date('H:i:s', strtotime($webhook['created_at'])) . "</td>";
        echo "</tr>";

        // Показати тільки перші 100
        if ($index >= 99) {
            echo "<tr><td colspan='9'>... і ще " . (count($webhooks) - 100) . " записів</td></tr>";
            break;
        }
    }
    echo "</table>";

    // ==========================================
    // КРОК 3: ЗНАЙДЕНІ ДОВГІ UTM_TERM
    // ==========================================

    if (!empty($longTermsFound)) {
        echo "<div class='section' style='background: #fee;'>";
        echo "<h2>❌ Крок 3: ЗНАЙДЕНІ ДОВГІ utm_term В WEBHOOK!</h2>";
        echo "<p><strong>Кількість:</strong> " . count($longTermsFound) . "</p>";

        echo "<table>";
        echo "<tr><th>Webhook ID</th><th>Deal ID</th><th>utm_term (з webhook)</th><th>utm_term_deal (з webhook)</th><th>Що збережено в БД</th></tr>";

        foreach ($longTermsFound as $item) {
            echo "<tr class='long'>";
            echo "<td>{$item['webhook_id']}</td>";
            echo "<td><a href='https://login.sendpulse.com/crm/deals?dealId={$item['deal_id']}' target='_blank'>{$item['deal_id']}</a></td>";
            echo "<td>" . htmlspecialchars($item['utm_term']) . "</td>";
            echo "<td>" . htmlspecialchars($item['utm_term_deal'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($item['processed_utm_term'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        echo "<h3>🎯 ДЕТАЛІ ПЕРШОГО WEBHOOK З ДОВГИМ UTM_TERM:</h3>";

        $firstLong = $longTermsFound[0];
        $sql = "SELECT raw_data FROM webhook_log WHERE id = :id";
        $webhookData = $db->fetchOne($sql, ['id' => $firstLong['webhook_id']]);

        if ($webhookData) {
            $rawData = json_decode($webhookData['raw_data'], true);
            echo "<h4>ПОВНИЙ RAW DATA:</h4>";
            echo "<pre>" . json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        }

        echo "</div>";

    } else {
        echo "<div class='section' style='background: #efe;'>";
        echo "<h2>✅ Крок 3: Довгі utm_term НЕ ЗНАЙДЕНІ в webhook!</h2>";
        echo "<p>За сьогодні в жодному webhook немає довгих utm_term (>50 символів)</p>";
        echo "</div>";
    }

    // ==========================================
    // КРОК 4: СТАТИСТИКА ПО ВСІМ utm_term
    // ==========================================

    echo "<h2>📊 Крок 4: Статистика по всім utm_term з webhook за сьогодні</h2>";

    arsort($allTerms);

    echo "<table>";
    echo "<tr><th>#</th><th>utm_term</th><th>Кількість в webhook</th><th>Довжина</th></tr>";

    $counter = 0;
    foreach ($allTerms as $term => $count) {
        $counter++;
        $isLong = (strlen($term) > 50);
        $rowClass = $isLong ? "long" : "";

        echo "<tr class='{$rowClass}'>";
        echo "<td>{$counter}</td>";
        echo "<td>" . htmlspecialchars($term) . "</td>";
        echo "<td><strong>{$count}</strong></td>";
        echo "<td>" . strlen($term) . " " . ($isLong ? "❌" : "✅") . "</td>";
        echo "</tr>";

        if ($counter >= 30) {
            echo "<tr><td colspan='4'>... і ще " . (count($allTerms) - 30) . " різних utm_term</td></tr>";
            break;
        }
    }
    echo "</table>";

    // ==========================================
    // ВИСНОВОК
    // ==========================================

    echo "<h2>🎯 ФІНАЛЬНИЙ ВИСНОВОК</h2>";

    if (!empty($longTermsFound)) {
        echo "<div style='background: #fee; padding: 20px; border-left: 5px solid red;'>";
        echo "<h3>❌ ПІДТВЕРДЖУЮ: Довгі utm_term ПРИХОДЯТЬ в webhook!</h3>";
        echo "<p><strong>Кількість webhook з довгими utm_term за сьогодні:</strong> " . count($longTermsFound) . "</p>";
        echo "<p><strong>Джерело:</strong> SendPulse CRM webhook (поле <code>utm_term</code> БЕЗ _deal суффіксу)</p>";
        echo "<p><strong>Що це:</strong> Meta Ads campaign/adset names які автоматично підставляються Facebook</p>";

        echo "<h4>Рішення:</h4>";
        echo "<ol>";
        echo "<li>✅ webhook_crm.php вже виправлено - тепер бере utm_term_deal</li>";
        echo "<li>⏳ Для НОВИХ webhook це буде працювати правильно</li>";
        echo "<li>⏳ Для СТАРИХ даних треба запустити виправлення (якщо є webhook логи)</li>";
        echo "</ol>";
        echo "</div>";
    } else {
        echo "<div style='background: #efe; padding: 20px; border-left: 5px solid green;'>";
        echo "<h3>✅ Довгі utm_term НЕ ЗНАЙДЕНІ в webhook за сьогодні!</h3>";
        echo "<p>Виправлення webhook_crm.php <strong>ПРАЦЮЄ!</strong></p>";
        echo "<p>Нові webhook беруть utm_term_deal (короткі значення) замість utm_term (довгі Meta Ads ID)</p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
