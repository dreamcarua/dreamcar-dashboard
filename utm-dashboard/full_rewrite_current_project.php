<?php
/**
 * ПОВНЕ ПЕРЕЗАПИСУВАННЯ UTM ДЛЯ ПОТОЧНОГО ПРОЕКТУ
 * Взяти всі сделки поточного проекту і перезаписати з webhook
 */

set_time_limit(3600); // 1 година
ini_set('memory_limit', '2048M');

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔄 Повне перезаписування UTM для поточного проекту</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 11px; }
    th { background: #3b82f6; color: white; }
    .success { background: #efe; }
    .error { background: #fee; }
    .warning { background: #fef3c7; padding: 20px; margin: 20px 0; border-left: 5px solid orange; }
    .btn { padding: 15px 30px; background: #ef4444; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; font-weight: bold; font-size: 16px; cursor: pointer; border: none; }
    .progress { background: #e5e7eb; height: 40px; border-radius: 5px; margin: 20px 0; }
    .progress-bar { background: linear-gradient(to right, #10b981, #3b82f6); height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 16px; }
    .info-box { background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0; }
</style>";

try {
    $db = Database::getInstance();

    // Отримати активний проект
    $settingsFile = __DIR__ . '/config/dashboard_settings.json';
    $activeProject = 'Q7';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        $activeProject = $settings['active_project'] ?? 'Q7';
    }

    echo "<div class='info-box'>";
    echo "<h3>⚙️ Активний проект: <strong>{$activeProject}</strong></h3>";
    echo "</div>";

    // ==========================================
    // КРОК 1: Скільки сделок в проекті
    // ==========================================

    echo "<h2>📊 Крок 1: Аналіз проекту {$activeProject}</h2>";

    // Всього сделок проекту
    $sql = "SELECT COUNT(*) as count FROM crm_deals WHERE UPPER(model) = :project";
    $totalDeals = $db->fetchOne($sql, ['project' => strtoupper($activeProject)])['count'];

    // Скільки з них мають webhook
    $sql = "SELECT COUNT(DISTINCT d.deal_id) as count
            FROM crm_deals d
            INNER JOIN webhook_log w ON d.deal_id = w.deal_id
            WHERE UPPER(d.model) = :project
              AND w.webhook_type = 'crm'";
    $dealsWithWebhook = $db->fetchOne($sql, ['project' => strtoupper($activeProject)])['count'];

    echo "<table>";
    echo "<tr><th>Параметр</th><th>Значення</th></tr>";
    echo "<tr><td>Всього сделок проекту {$activeProject}</td><td><strong>{$totalDeals}</strong></td></tr>";
    echo "<tr class='success'><td>З них мають webhook</td><td><strong>{$dealsWithWebhook}</strong></td></tr>";
    echo "<tr class='error'><td>Без webhook</td><td><strong>" . ($totalDeals - $dealsWithWebhook) . "</strong></td></tr>";
    echo "<tr><td>% з webhook</td><td><strong>" . round(($dealsWithWebhook / $totalDeals) * 100, 1) . "%</strong></td></tr>";
    echo "</table>";

    if ($dealsWithWebhook === 0) {
        echo "<p class='error'>❌ Немає сделок з webhook для цього проекту!</p>";
        exit;
    }

    // ==========================================
    // КРОК 2: ВИКОНАННЯ
    // ==========================================

    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {

        echo "<h2>🚀 Крок 2: ПЕРЕЗАПИСУВАННЯ UTM</h2>";
        echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%;'>0%</div></div>";
        echo "<p id='status'>Початок обробки...</p>";
        flush();

        // Отримати всі deal_id проекту які мають webhook
        $sql = "SELECT DISTINCT d.deal_id
                FROM crm_deals d
                INNER JOIN webhook_log w ON d.deal_id = w.deal_id
                WHERE UPPER(d.model) = :project
                  AND w.webhook_type = 'crm'
                ORDER BY d.deal_id DESC";

        $dealIds = $db->fetchAll($sql, ['project' => strtoupper($activeProject)]);

        $total = count($dealIds);
        $updated = 0;
        $unchanged = 0;
        $errors = 0;

        echo "<p><strong>Обробка {$total} сделок проекту {$activeProject}...</strong></p>";

        echo "<table id='resultsTable'>";
        echo "<tr><th>Deal ID</th><th>utm_term: Було</th><th>→</th><th>Стало</th></tr>";

        foreach ($dealIds as $index => $row) {
            $dealId = $row['deal_id'];

            try {
                // Поточні дані з БД
                $sql = "SELECT utm_source, utm_medium, utm_campaign, utm_term, utm_content
                        FROM crm_deals WHERE deal_id = :deal_id";
                $current = $db->fetchOne($sql, ['deal_id' => $dealId]);

                if (!$current) {
                    $errors++;
                    continue;
                }

                // ПЕРШИЙ webhook (створення)
                $sql = "SELECT raw_data FROM webhook_log
                        WHERE deal_id = :deal_id AND webhook_type = 'crm'
                        ORDER BY created_at ASC LIMIT 1";
                $webhook = $db->fetchOne($sql, ['deal_id' => $dealId]);

                if (!$webhook) {
                    $errors++;
                    continue;
                }

                $rawData = json_decode($webhook['raw_data'], true);
                $vars = $rawData['variables'] ?? [];

                // Витягти utm_*_deal (пріоритет) або utm_* (fallback)
                $newUtmSource = isset($vars['utm_source_deal']) && !empty($vars['utm_source_deal'])
                    ? strtolower(trim($vars['utm_source_deal']))
                    : strtolower(trim($vars['utm_source'] ?? ''));

                $newUtmMedium = isset($vars['utm_medium_deal']) && !empty($vars['utm_medium_deal'])
                    ? strtolower(trim($vars['utm_medium_deal']))
                    : strtolower(trim($vars['utm_medium'] ?? ''));

                $newUtmCampaign = isset($vars['utm_campaign_deal']) && !empty($vars['utm_campaign_deal'])
                    ? strtolower(trim($vars['utm_campaign_deal']))
                    : strtolower(trim($vars['utm_campaign'] ?? ''));

                $newUtmTerm = isset($vars['utm_term_deal']) && !empty($vars['utm_term_deal'])
                    ? strtolower(trim($vars['utm_term_deal']))
                    : strtolower(trim($vars['utm_term'] ?? ''));

                $newUtmContent = isset($vars['utm_content_deal']) && !empty($vars['utm_content_deal'])
                    ? strtolower(trim($vars['utm_content_deal']))
                    : strtolower(trim($vars['utm_content'] ?? ''));

                // Очистити Meta Ads ID
                if (preg_match('/^\d{15,}/', $newUtmCampaign) || strlen($newUtmCampaign) > 50) {
                    $newUtmCampaign = '';
                }
                if (preg_match('/^\d{15,}/', $newUtmTerm) || strlen($newUtmTerm) > 50) {
                    $newUtmTerm = '';
                }
                if (preg_match('/^\d{15,}/', $newUtmContent) || strlen($newUtmContent) > 50) {
                    $newUtmContent = '';
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

                // Показати тільки якщо змінилось
                $changed = ($current['utm_term'] !== $newUtmTerm)
                    || ($current['utm_campaign'] !== $newUtmCampaign)
                    || ($current['utm_content'] !== $newUtmContent);

                if ($changed) {
                    echo "<tr class='success'>";
                    echo "<td>{$dealId}</td>";
                    echo "<td>" . htmlspecialchars(substr($current['utm_term'] ?? '(пусто)', 0, 40)) . "</td>";
                    echo "<td>→</td>";
                    echo "<td><strong>" . htmlspecialchars(substr($newUtmTerm ?: '(пусто)', 0, 40)) . "</strong></td>";
                    echo "</tr>";
                    $updated++;
                } else {
                    $unchanged++;
                }

            } catch (Exception $e) {
                $errors++;
            }

            // Прогрес
            if ($index % 100 === 0 || $index === $total - 1) {
                $progress = round((($index + 1) / $total) * 100);
                echo "<script>
                    document.getElementById('progressBar').style.width = '{$progress}%';
                    document.getElementById('progressBar').innerText = '{$progress}%';
                    document.getElementById('status').innerText = 'Оброблено: " . ($index + 1) . " з {$total} | Змінено: {$updated} | Без змін: {$unchanged}';
                </script>";
                flush();
            }

            // Показати тільки перші 50 змін
            if ($updated === 50) {
                echo "<tr><td colspan='4'>... обробка продовжується (показано перші 50 змін) ...</td></tr>";
                echo "</table><table style='display:none;'>";
            }
        }

        echo "</table>";

        echo "<h2 style='color: green;'>🎉 ПЕРЕЗАПИСУВАННЯ ЗАВЕРШЕНО!</h2>";

        echo "<table>";
        echo "<tr><th>Статус</th><th>Кількість</th><th>%</th></tr>";
        echo "<tr class='success'><td>✅ Оновлено (були зміни)</td><td><strong>{$updated}</strong></td><td>" . round(($updated / $total) * 100, 1) . "%</td></tr>";
        echo "<tr><td>⏭️ Без змін (вже правильні)</td><td>{$unchanged}</td><td>" . round(($unchanged / $total) * 100, 1) . "%</td></tr>";
        echo "<tr class='error'><td>⚠️ Помилки</td><td>{$errors}</td><td>" . round(($errors / $total) * 100, 1) . "%</td></tr>";
        echo "<tr style='font-weight: bold;'><td><strong>ВСЬОГО оброблено</strong></td><td><strong>{$total}</strong></td><td>100%</td></tr>";
        echo "</table>";

        echo "<p><a href='index.php' class='btn' style='background: #10b981; font-size: 18px;'>→ Перейти до дашборду</a></p>";

    } else {
        // Попередження
        echo "<div class='warning'>";
        echo "<h3>⚠️ ПЛАН ДІЙ:</h3>";

        echo "<p><strong>Буде перезаписано UTM для:</strong></p>";
        echo "<ul>";
        echo "<li><strong>Проект:</strong> {$activeProject}</li>";
        echo "<li><strong>Сделок з webhook:</strong> {$dealsWithWebhook}</li>";
        echo "<li><strong>Період:</strong> ВСІ сделки проекту (з самого початку)</li>";
        echo "</ul>";

        echo "<h4>Логіка:</h4>";
        echo "<ol>";
        echo "<li>Для кожної сделки проекту {$activeProject} знайти ПЕРШИЙ webhook</li>";
        echo "<li>Витягти <code>utm_*_deal</code> (якщо є) або <code>utm_*</code> (fallback)</li>";
        echo "<li>Перевірити на Meta Ads ID (цифри >15 символів, довгі >50)</li>";
        echo "<li>Перезаписати в БД</li>";
        echo "</ol>";

        echo "<h4>Що буде виправлено:</h4>";
        echo "<pre>";
        echo "✅ Всі utm_term отримають значення з utm_term_deal\n";
        echo "✅ Всі utm_campaign отримають значення з utm_campaign_deal\n";
        echo "✅ Всі utm_content отримають значення з utm_content_deal\n";
        echo "🧹 Meta Ads ID (120227..., довгі >50) будуть очищені\n";
        echo "</pre>";

        echo "<div style='background: #fee; padding: 15px; margin: 20px 0;'>";
        echo "<h4 style='color: red;'>⚠️ КРИТИЧНО:</h4>";
        echo "<ul>";
        echo "<li><strong>Час виконання:</strong> 10-30 хвилин для {$dealsWithWebhook} сделок</li>";
        echo "<li><strong>НЕ закривай вкладку</strong> під час виконання!</li>";
        echo "<li>Операція незворотна (але це правильні дані з webhook)</li>";
        echo "</ul>";
        echo "</div>";

        echo "<p style='text-align: center; margin-top: 40px;'>";
        echo "<a href='?execute=yes' class='btn' style='font-size: 22px; padding: 25px 50px;' onclick='return confirm(\"ПЕРЕЗАПИСАТИ UTM для ВСІХ {$dealsWithWebhook} сделок проекту {$activeProject}?\n\nЦе займе 10-30 хвилин.\n\nПродовжити?\")'>🔄 ПЕРЕЗАПИСАТИ ВЕСЬ ПРОЕКТ</a>";
        echo "</p>";
        echo "<p style='text-align: center;'>";
        echo "<a href='index.php' style='color: #666; text-decoration: underline;'>← Скасувати</a>";
        echo "</p>";

        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
