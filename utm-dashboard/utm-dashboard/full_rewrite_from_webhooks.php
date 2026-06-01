<?php
/**
 * ПОВНЕ ПЕРЕЗАПИСУВАННЯ UTM З WEBHOOK ЛОГІВ
 * Для ВСІХ сделок де є webhook - взяти utm_*_deal і перезаписати в БД
 */

set_time_limit(1800);
ini_set('memory_limit', '1024M');

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔄 ПОВНЕ перезаписування UTM з webhook</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 11px; }
    th { background: #3b82f6; color: white; }
    .success { background: #efe; }
    .warning { background: #fef3c7; padding: 20px; margin: 20px 0; border-left: 5px solid orange; }
    .btn { padding: 15px 30px; background: #ef4444; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; font-weight: bold; font-size: 16px; cursor: pointer; border: none; }
    .progress { background: #e5e7eb; height: 40px; border-radius: 5px; margin: 20px 0; }
    .progress-bar { background: linear-gradient(to right, #10b981, #3b82f6); height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 16px; }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));

try {
    $db = Database::getInstance();

    // ==========================================
    // КРОК 1: Скільки webhook за вчора
    // ==========================================

    echo "<h2>📊 Крок 1: Аналіз webhook за вчора</h2>";

    $sql = "SELECT COUNT(DISTINCT deal_id) as count
            FROM webhook_log
            WHERE webhook_type = 'crm'
              AND DATE(created_at) = :yesterday
              AND deal_id IS NOT NULL";

    $webhookCount = $db->fetchOne($sql, ['yesterday' => $yesterday])['count'];

    echo "<p>Унікальних deal_id в webhook за вчора: <strong>{$webhookCount}</strong></p>";

    if ($webhookCount === 0) {
        echo "<p class='error'>❌ За вчора немає webhook!</p>";
        exit;
    }

    // ==========================================
    // КРОК 2: ВИКОНАННЯ
    // ==========================================

    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {

        echo "<h2>🚀 Крок 2: ПЕРЕЗАПИСУВАННЯ З WEBHOOK</h2>";
        echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%;'>0%</div></div>";
        echo "<p id='status'>Завантаження...</p>";
        flush();

        // Отримати всі webhook за вчора
        $sql = "SELECT DISTINCT deal_id
                FROM webhook_log
                WHERE webhook_type = 'crm'
                  AND DATE(created_at) = :yesterday
                  AND deal_id IS NOT NULL
                ORDER BY deal_id ASC";

        $dealIds = $db->fetchAll($sql, ['yesterday' => $yesterday]);

        $total = count($dealIds);
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        echo "<p><strong>Обробка {$total} сделок...</strong></p>";

        echo "<table id='resultsTable'>";
        echo "<tr><th>Deal ID</th><th>Було</th><th>→</th><th>Стало</th></tr>";

        foreach ($dealIds as $index => $row) {
            $dealId = $row['deal_id'];

            try {
                // Отримати поточні дані з БД
                $sql = "SELECT utm_source, utm_medium, utm_campaign, utm_term, utm_content
                        FROM crm_deals WHERE deal_id = :deal_id";
                $currentData = $db->fetchOne($sql, ['deal_id' => $dealId]);

                if (!$currentData) {
                    $skipped++;
                    continue;
                }

                // Знайти ПЕРШИЙ webhook (створення)
                $sql = "SELECT raw_data FROM webhook_log
                        WHERE deal_id = :deal_id AND webhook_type = 'crm'
                        ORDER BY created_at ASC LIMIT 1";
                $webhook = $db->fetchOne($sql, ['deal_id' => $dealId]);

                if (!$webhook) {
                    $skipped++;
                    continue;
                }

                $rawData = json_decode($webhook['raw_data'], true);
                $vars = $rawData['variables'] ?? [];

                // Витягти з _deal полів
                $newUtmSource = isset($vars['utm_source_deal']) && !empty($vars['utm_source_deal'])
                    ? strtolower(trim($vars['utm_source_deal']))
                    : strtolower(trim($vars['utm_source'] ?? ''));

                $newUtmMedium = isset($vars['utm_medium_deal']) && !empty($vars['utm_medium_deal'])
                    ? strtolower(trim($vars['utm_medium_deal']))
                    : strtolower(trim($vars['utm_medium'] ?? ''));

                $newUtmCampaign = isset($vars['utm_campaign_deal']) && !empty($vars['utm_campaign_deal'])
                    ? strtolower(trim($vars['utm_campaign_deal']))
                    : null;

                $newUtmTerm = isset($vars['utm_term_deal']) && !empty($vars['utm_term_deal'])
                    ? strtolower(trim($vars['utm_term_deal']))
                    : null;

                $newUtmContent = isset($vars['utm_content_deal']) && !empty($vars['utm_content_deal'])
                    ? strtolower(trim($vars['utm_content_deal']))
                    : null;

                // Очистити Meta Ads ID якщо немає _deal альтернативи
                if ($newUtmCampaign === null) {
                    $plainCampaign = strtolower(trim($vars['utm_campaign'] ?? ''));
                    if (!preg_match('/^\d{15,}/', $plainCampaign) && strlen($plainCampaign) <= 50) {
                        $newUtmCampaign = $plainCampaign;
                    } else {
                        $newUtmCampaign = '';
                    }
                }

                if ($newUtmTerm === null) {
                    $plainTerm = strtolower(trim($vars['utm_term'] ?? ''));
                    if (!preg_match('/^\d{15,}/', $plainTerm) && strlen($plainTerm) <= 50) {
                        $newUtmTerm = $plainTerm;
                    } else {
                        $newUtmTerm = '';
                    }
                }

                if ($newUtmContent === null) {
                    $plainContent = strtolower(trim($vars['utm_content'] ?? ''));
                    if (!preg_match('/^\d{15,}/', $plainContent) && strlen($plainContent) <= 50) {
                        $newUtmContent = $plainContent;
                    } else {
                        $newUtmContent = '';
                    }
                }

                // Оновити БД
                $sql = "UPDATE crm_deals SET
                            utm_source = :utm_source,
                            utm_medium = :utm_medium,
                            utm_campaign = :utm_campaign,
                            utm_term = :utm_term,
                            utm_content = :utm_content
                        WHERE deal_id = :deal_id";

                $db->execute($sql, [
                    'deal_id' => $dealId,
                    'utm_source' => $newUtmSource,
                    'utm_medium' => $newUtmMedium,
                    'utm_campaign' => $newUtmCampaign,
                    'utm_term' => $newUtmTerm,
                    'utm_content' => $newUtmContent
                ]);

                // Показати зміни тільки якщо щось змінилось
                if ($currentData['utm_term'] !== $newUtmTerm) {
                    echo "<tr class='success'>";
                    echo "<td>{$dealId}</td>";
                    echo "<td>" . htmlspecialchars($currentData['utm_term'] ?? '(пусто)') . "</td>";
                    echo "<td>→</td>";
                    echo "<td><strong>" . htmlspecialchars($newUtmTerm ?: '(пусто)') . "</strong></td>";
                    echo "</tr>";
                }

                $updated++;

            } catch (Exception $e) {
                $errors++;
            }

            // Прогрес
            if ($index % 50 === 0 || $index === $total - 1) {
                $progress = round((($index + 1) / $total) * 100);
                echo "<script>
                    document.getElementById('progressBar').style.width = '{$progress}%';
                    document.getElementById('progressBar').innerText = '{$progress}%';
                    document.getElementById('status').innerText = 'Оброблено: " . ($index + 1) . " з {$total} | Оновлено: {$updated}';
                </script>";
                flush();
            }

            // Показувати тільки перші 30
            if ($index === 30) {
                echo "<tr><td colspan='4'>... обробка продовжується (показано перші 30 змін) ...</td></tr>";
                echo "</table><table style='display:none;'>";
            }
        }

        echo "</table>";

        echo "<h2 style='color: green;'>🎉 ПЕРЕЗАПИСУВАННЯ ЗАВЕРШЕНО!</h2>";
        echo "<table>";
        echo "<tr><th>Статус</th><th>Кількість</th></tr>";
        echo "<tr class='success'><td>✅ Оновлено сделок</td><td><strong>{$updated}</strong></td></tr>";
        echo "<tr><td>⏭️ Пропущено (немає в БД/webhook)</td><td>{$skipped}</td></tr>";
        echo "<tr><td>⚠️ Помилки</td><td>{$errors}</td></tr>";
        echo "</table>";

        echo "<p><a href='index.php?date_range=yesterday' class='btn' style='background: #10b981; font-size: 18px;'>→ Перевірити dashboard (вчора)</a></p>";

    } else {
        // Попередження
        echo "<div class='warning'>";
        echo "<h3>⚠️ ЩО БУДЕ ЗРОБЛЕНО:</h3>";

        echo "<p><strong>Для ВСІХ {$webhookCount} сделок за вчора:</strong></p>";
        echo "<ol>";
        echo "<li>Знайти webhook лог (ПЕРШИЙ - при створенні сделки)</li>";
        echo "<li>Витягти <strong>utm_*_deal</strong> (правильні значення)</li>";
        echo "<li><strong>ПЕРЕЗАПИСАТИ</strong> в БД (замінити на правильні)</li>";
        echo "</ol>";

        echo "<h4>Приклади виправлень:</h4>";
        echo "<pre>";
        echo "Deal 11489757:\n";
        echo "  Було: utm_term = 'artem'\n";
        echo "  Стане: utm_term = 'vira' (з utm_term_deal)\n\n";
        echo "Deal 11514422:\n";
        echo "  Було: utm_term = '{{adset.id}}_{{adset.name}}_{{placement}}'\n";
        echo "  Стане: utm_term = 'vira' (з utm_term_deal)\n";
        echo "</pre>";

        echo "<h4>⏱️ Час виконання:</h4>";
        echo "<p>~5-10 хвилин для {$webhookCount} сделок</p>";

        echo "<p style='text-align: center; margin-top: 40px;'>";
        echo "<a href='?execute=yes' class='btn' style='font-size: 20px; padding: 20px 40px;' onclick='return confirm(\"ПОВНІСТЮ перезаписати UTM для {$webhookCount} сделок за вчора?\n\nВСІ utm_term будуть замінені на дані з webhook!\")'>🔄 ПЕРЕЗАПИСАТИ ВСЕ З WEBHOOK</a>";
        echo "</p>";
        echo "<p style='text-align: center;'><a href='index.php' style='color: #666; text-decoration: underline;'>← Скасувати</a></p>";

        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
