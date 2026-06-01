<?php
/**
 * Глибокий аналіз дивних UTM-міток
 * Призначення: Знайти причину появи campaign_id замість utm_term
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔍 Глибокий аналіз дивних UTM-міток</h1>";
echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
    th { background: #3b82f6; color: white; }
    .highlight { background: #fef3c7; font-weight: bold; }
    .error { background: #fee; color: red; }
    .success { background: #efe; color: green; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px; }
</style>";

try {
    $db = Database::getInstance();

    // ==========================================
    // КРОК 1: Знайти сделки за сьогодні
    // ==========================================

    echo "<h2>📅 Крок 1: Сделки за сьогодні</h2>";

    $today = date('Y-m-d');
    $sql = "SELECT
                deal_id, contact_id, email, phone, full_name,
                utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                model, deal_name, created_at, deal_type
            FROM crm_deals
            WHERE DATE(created_at) = :today
            ORDER BY created_at DESC
            LIMIT 20";

    $deals = $db->fetchAll($sql, ['today' => $today]);

    echo "<p>Знайдено сделок за сьогодні: <strong>" . count($deals) . "</strong></p>";

    if (empty($deals)) {
        echo "<p class='error'>❌ За сьогодні немає сделок!</p>";
        exit;
    }

    // Показати таблицю
    echo "<table>";
    echo "<tr>
            <th>#</th>
            <th>Deal ID</th>
            <th>UTM Source</th>
            <th>UTM Medium</th>
            <th>UTM Campaign</th>
            <th class='highlight'>UTM Term</th>
            <th>UTM Content</th>
            <th>Model</th>
            <th>Час</th>
          </tr>";

    $strangeTerms = [];

    foreach ($deals as $i => $deal) {
        $utmTerm = $deal['utm_term'] ?? '';

        // Перевірити чи utm_term виглядає як campaign_id (тільки цифри, довжина > 15)
        $isStrange = false;
        if (!empty($utmTerm) && preg_match('/^\d{15,}$/', $utmTerm)) {
            $isStrange = true;
            $strangeTerms[] = $deal;
        }

        $rowClass = $isStrange ? "class='error'" : "";

        echo "<tr {$rowClass}>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td>" . htmlspecialchars($deal['deal_id']) . "</td>";
        echo "<td>" . htmlspecialchars($deal['utm_source'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['utm_medium'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['utm_campaign'] ?? '-') . "</td>";
        echo "<td class='highlight'>" . htmlspecialchars($utmTerm ?: '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['utm_content'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['model'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['created_at']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 2: Аналіз дивних utm_term
    // ==========================================

    if (!empty($strangeTerms)) {
        echo "<h2>⚠️ Крок 2: Знайдено ДИВНІ utm_term (схожі на campaign_id)</h2>";
        echo "<p style='color: red;'>Кількість: <strong>" . count($strangeTerms) . "</strong></p>";

        echo "<h3>Детальний аналіз першої дивної сделки:</h3>";
        $firstStrange = $strangeTerms[0];

        echo "<table>";
        echo "<tr><th>Поле</th><th>Значення</th></tr>";
        foreach ($firstStrange as $key => $value) {
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($key) . "</strong></td>";
            echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        // Знайти webhook лог для цієї сделки
        echo "<h3>🔍 Пошук webhook логу для deal_id: {$firstStrange['deal_id']}</h3>";

        $sql = "SELECT * FROM webhook_log
                WHERE deal_id = :deal_id
                ORDER BY created_at DESC
                LIMIT 1";
        $webhookLog = $db->fetchOne($sql, ['deal_id' => $firstStrange['deal_id']]);

        if ($webhookLog) {
            echo "<p class='success'>✅ Webhook лог знайдено!</p>";

            echo "<h4>Raw Data (сирі дані webhook):</h4>";
            $rawData = json_decode($webhookLog['raw_data'], true);
            echo "<pre>" . json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

            echo "<h4>Processed Data (оброблені дані):</h4>";
            $processedData = json_decode($webhookLog['processed_data'], true);
            echo "<pre>" . json_encode($processedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

            // Аналіз звідки взявся utm_term
            echo "<h4>🎯 АНАЛІЗ: Звідки utm_term?</h4>";

            if (isset($rawData['variables']['utm_term_deal'])) {
                echo "<p class='error'>❌ В webhook є <code>utm_term_deal</code> = <strong>" . htmlspecialchars($rawData['variables']['utm_term_deal']) . "</strong></p>";
                echo "<p>Це значення прийшло ЗІ SENDPULSE CRM!</p>";
            }

            if (isset($processedData['utm_term'])) {
                echo "<p class='error'>❌ В processed_data є <code>utm_term</code> = <strong>" . htmlspecialchars($processedData['utm_term']) . "</strong></p>";
            }

        } else {
            echo "<p class='error'>❌ Webhook лог не знайдено для цієї сделки</p>";
        }

    } else {
        echo "<h2 class='success'>✅ Крок 2: Дивних utm_term НЕ ЗНАЙДЕНО</h2>";
        echo "<p>Всі utm_term виглядають нормально</p>";
    }

    // ==========================================
    // КРОК 3: Статистика по utm_term
    // ==========================================

    echo "<h2>📊 Крок 3: Статистика по utm_term за сьогодні</h2>";

    $sql = "SELECT
                utm_term,
                COUNT(*) as count,
                GROUP_CONCAT(DISTINCT deal_id ORDER BY deal_id SEPARATOR ', ') as deal_ids
            FROM crm_deals
            WHERE DATE(created_at) = :today
            GROUP BY utm_term
            ORDER BY count DESC";

    $termStats = $db->fetchAll($sql, ['today' => $today]);

    echo "<table>";
    echo "<tr><th>UTM Term</th><th>Кількість</th><th>Deal IDs</th><th>Тип</th></tr>";

    foreach ($termStats as $stat) {
        $term = $stat['utm_term'] ?? '';
        $isNumeric = preg_match('/^\d{15,}$/', $term);
        $typeClass = $isNumeric ? "error" : ($term ? "success" : "");
        $typeLabel = $isNumeric ? "❌ Campaign ID" : ($term ? "✅ Нормальний" : "⚪️ Пустий");

        echo "<tr class='{$typeClass}'>";
        echo "<td><strong>" . htmlspecialchars($term ?: '(пусто)') . "</strong></td>";
        echo "<td>" . htmlspecialchars($stat['count']) . "</td>";
        echo "<td style='max-width: 200px; overflow: hidden; text-overflow: ellipsis;'>" . htmlspecialchars($stat['deal_ids']) . "</td>";
        echo "<td>{$typeLabel}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 4: Перевірка webhook_crm.php
    // ==========================================

    echo "<h2>🔧 Крок 4: Рекомендації</h2>";

    if (!empty($strangeTerms)) {
        echo "<div style='background: #fee; padding: 20px; border-left: 5px solid red;'>";
        echo "<h3>❌ ПРОБЛЕМА ЗНАЙДЕНА!</h3>";
        echo "<p><strong>Причина:</strong> В SendPulse CRM поле <code>utm_term_deal</code> містить campaign_id замість справжньої UTM-мітки</p>";

        echo "<h4>Можливі рішення:</h4>";
        echo "<ol>";
        echo "<li><strong>Перевірити SendPulse CRM:</strong> Подивись які поля заповнюються в автоматизації</li>";
        echo "<li><strong>Виправити webhook_crm.php:</strong> Додати валідацію utm_term (якщо це тільки цифри довжиною > 15 - ігнорувати)</li>";
        echo "<li><strong>Очистити дані:</strong> Оновити існуючі сделки, замінити дивні utm_term на NULL</li>";
        echo "</ol>";

        echo "<h4>SQL для очищення:</h4>";
        echo "<pre>";
        echo "UPDATE crm_deals\n";
        echo "SET utm_term = NULL\n";
        echo "WHERE utm_term REGEXP '^[0-9]{15,}$';\n";
        echo "</pre>";

        echo "</div>";
    } else {
        echo "<div style='background: #efe; padding: 20px; border-left: 5px solid green;'>";
        echo "<h3>✅ Проблем не знайдено!</h3>";
        echo "<p>Всі utm_term виглядають нормально</p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
