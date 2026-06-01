<?php
/**
 * ПЕРЕВІРКА ВСІХ UTM_TERM ЗА ВЧОРА
 * Чи є webhook для кожної мітки
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 Перевірка всіх UTM Term за вчора</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; font-size: 11px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #3b82f6; color: white; position: sticky; top: 0; }
    .error { background: #fee; font-weight: bold; }
    .success { background: #efe; }
    .warning { background: #fef3c7; }
    pre { background: #f5f5f5; padding: 10px; font-size: 10px; max-height: 400px; overflow-y: auto; border-left: 4px solid #ef4444; }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));

try {
    $db = Database::getInstance();

    echo "<p><strong>Дата вчора:</strong> {$yesterday}</p>";

    // ==========================================
    // КРОК 1: Всі UTM_TERM за вчора
    // ==========================================

    echo "<h2>📋 Всі utm_term за вчора</h2>";

    $sql = "SELECT utm_term,
                   COUNT(*) as count,
                   GROUP_CONCAT(DISTINCT deal_id ORDER BY deal_id ASC SEPARATOR ', ') as deal_ids
            FROM crm_deals
            WHERE DATE(created_at) = :yesterday
              AND utm_term IS NOT NULL
              AND utm_term != ''
            GROUP BY utm_term
            ORDER BY count DESC";

    $terms = $db->fetchAll($sql, ['yesterday' => $yesterday]);

    echo "<p>Унікальних utm_term: <strong>" . count($terms) . "</strong></p>";

    echo "<table>";
    echo "<tr>
            <th>#</th>
            <th>UTM Term</th>
            <th>Кількість сделок</th>
            <th>Deal IDs (приклади)</th>
            <th>Є webhook?</th>
            <th>Що в webhook?</th>
          </tr>";

    foreach ($terms as $i => $term) {
        $utmTermValue = $term['utm_term'];
        $dealIds = explode(', ', $term['deal_ids']);
        $firstDealId = $dealIds[0];

        // Знайти webhook для першої сделки
        $sql = "SELECT id, raw_data, created_at
                FROM webhook_log
                WHERE deal_id = :deal_id AND webhook_type = 'crm'
                ORDER BY created_at ASC LIMIT 1";

        $webhook = $db->fetchOne($sql, ['deal_id' => $firstDealId]);

        $hasWebhook = !empty($webhook);
        $webhookUtmTerm = null;
        $webhookUtmTermDeal = null;

        if ($hasWebhook) {
            $rawData = json_decode($webhook['raw_data'], true);
            $vars = $rawData['variables'] ?? [];
            $webhookUtmTerm = $vars['utm_term'] ?? null;
            $webhookUtmTermDeal = $vars['utm_term_deal'] ?? null;
        }

        // Перевірка чи збігається
        $isMatch = false;
        $matchInfo = '';

        if ($hasWebhook) {
            if ($webhookUtmTermDeal && strtolower(trim($webhookUtmTermDeal)) === strtolower($utmTermValue)) {
                $isMatch = true;
                $matchInfo = "✅ Збігається з utm_term_deal";
            } elseif ($webhookUtmTerm && strtolower(trim($webhookUtmTerm)) === strtolower($utmTermValue)) {
                $isMatch = true;
                $matchInfo = "⚠️ Збігається з utm_term (БЕЗ _deal)";
            } else {
                $matchInfo = "❌ НЕ збігається!<br>webhook: term='" . htmlspecialchars(substr($webhookUtmTerm ?? 'NULL', 0, 30)) . "...' term_deal='" . htmlspecialchars($webhookUtmTermDeal ?? 'NULL') . "'";
            }
        }

        $rowClass = !$hasWebhook ? "error" : (!$isMatch ? "warning" : "success");

        echo "<tr class='{$rowClass}'>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td><strong>" . htmlspecialchars($utmTermValue) . "</strong></td>";
        echo "<td>{$term['count']}</td>";
        echo "<td style='font-size: 10px;'>" . htmlspecialchars(implode(', ', array_slice($dealIds, 0, 5))) . ($term['count'] > 5 ? "..." : "") . "</td>";
        echo "<td>" . ($hasWebhook ? "✅ Так" : "❌ Ні") . "</td>";
        echo "<td style='font-size: 10px;'>{$matchInfo}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 2: Статистика
    // ==========================================

    $withWebhook = 0;
    $withoutWebhook = 0;
    $matched = 0;
    $notMatched = 0;

    foreach ($terms as $term) {
        $dealIds = explode(', ', $term['deal_ids']);
        $firstDealId = $dealIds[0];

        $sql = "SELECT id, raw_data FROM webhook_log WHERE deal_id = :deal_id AND webhook_type = 'crm' ORDER BY created_at ASC LIMIT 1";
        $webhook = $db->fetchOne($sql, ['deal_id' => $firstDealId]);

        if ($webhook) {
            $withWebhook++;

            $rawData = json_decode($webhook['raw_data'], true);
            $vars = $rawData['variables'] ?? [];
            $webhookUtmTermDeal = $vars['utm_term_deal'] ?? null;

            if ($webhookUtmTermDeal && strtolower(trim($webhookUtmTermDeal)) === strtolower($term['utm_term'])) {
                $matched++;
            } else {
                $notMatched++;
            }
        } else {
            $withoutWebhook++;
        }
    }

    echo "<h2>📊 Загальна статистика</h2>";
    echo "<table>";
    echo "<tr><th>Статус</th><th>Кількість UTM term</th><th>%</th></tr>";
    echo "<tr class='success'><td>✅ Є webhook і збігається з utm_term_deal</td><td><strong>{$matched}</strong></td><td>" . round(($matched / count($terms)) * 100, 1) . "%</td></tr>";
    echo "<tr class='warning'><td>⚠️ Є webhook але НЕ збігається</td><td><strong>{$notMatched}</strong></td><td>" . round(($notMatched / count($terms)) * 100, 1) . "%</td></tr>";
    echo "<tr class='error'><td>❌ Немає webhook</td><td><strong>{$withoutWebhook}</strong></td><td>" . round(($withoutWebhook / count($terms)) * 100, 1) . "%</td></tr>";
    echo "<tr><td><strong>ВСЬОГО унікальних utm_term</strong></td><td><strong>" . count($terms) . "</strong></td><td>100%</td></tr>";
    echo "</table>";

    // ==========================================
    // КРОК 3: Проблемні мітки
    // ==========================================

    echo "<h2>⚠️ Проблемні мітки (потребують уваги)</h2>";

    echo "<h3>1. Мітки БЕЗ webhook:</h3>";
    $count = 0;
    foreach ($terms as $term) {
        $dealIds = explode(', ', $term['deal_ids']);
        $firstDealId = $dealIds[0];

        $sql = "SELECT COUNT(*) as count FROM webhook_log WHERE deal_id = :deal_id AND webhook_type = 'crm'";
        $hasWebhook = $db->fetchOne($sql, ['deal_id' => $firstDealId])['count'] > 0;

        if (!$hasWebhook) {
            echo "<p class='error'>❌ <strong>" . htmlspecialchars($term['utm_term']) . "</strong> (сделок: {$term['count']}, приклад: {$firstDealId})</p>";
            $count++;
            if ($count >= 10) {
                echo "<p>... і ще " . ($withoutWebhook - 10) . " міток</p>";
                break;
            }
        }
    }

    echo "<h3>2. Мітки НЕ збігаються з webhook:</h3>";
    $count = 0;
    foreach ($terms as $term) {
        $dealIds = explode(', ', $term['deal_ids']);
        $firstDealId = $dealIds[0];

        $sql = "SELECT raw_data FROM webhook_log WHERE deal_id = :deal_id AND webhook_type = 'crm' ORDER BY created_at ASC LIMIT 1";
        $webhook = $db->fetchOne($sql, ['deal_id' => $firstDealId]);

        if ($webhook) {
            $rawData = json_decode($webhook['raw_data'], true);
            $vars = $rawData['variables'] ?? [];
            $webhookUtmTermDeal = $vars['utm_term_deal'] ?? null;

            if (!$webhookUtmTermDeal || strtolower(trim($webhookUtmTermDeal)) !== strtolower($term['utm_term'])) {
                echo "<p class='warning'>⚠️ <strong>" . htmlspecialchars($term['utm_term']) . "</strong><br>";
                echo "→ В webhook: utm_term_deal = '" . htmlspecialchars($webhookUtmTermDeal ?? 'NULL') . "' (Deal: {$firstDealId})</p>";
                $count++;
                if ($count >= 10) {
                    echo "<p>... і ще " . ($notMatched - 10) . " міток</p>";
                    break;
                }
            }
        }
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
