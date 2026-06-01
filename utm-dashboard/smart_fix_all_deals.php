<?php
/**
 * РОЗУМНЕ ВИПРАВЛЕННЯ ВСІХ СДЕЛОК
 * 1. Спробувати взяти правильні дані з webhook логів (*_deal поля)
 * 2. Якщо немає - очистити Meta Ads ID
 */

set_time_limit(1800); // 30 хвилин
ini_set('memory_limit', '1024M');

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🧠 РОЗУМНЕ виправлення всіх сделок</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #3b82f6; color: white; }
    .error { background: #fee; }
    .success { background: #efe; }
    .warning { background: #fef3c7; padding: 20px; margin: 20px 0; border-left: 5px solid orange; }
    .btn { padding: 15px 30px; background: #ef4444; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; font-weight: bold; font-size: 16px; cursor: pointer; border: none; }
    .progress { background: #e5e7eb; height: 40px; border-radius: 5px; overflow: hidden; margin: 20px 0; }
    .progress-bar { background: linear-gradient(to right, #10b981, #3b82f6); height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 16px; }
    .stats-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
</style>";

try {
    $db = Database::getInstance();

    // ==========================================
    // КРОК 1: АНАЛІЗ
    // ==========================================

    echo "<h2>📊 Крок 1: Аналіз проблемних записів</h2>";

    $sql = "SELECT COUNT(*) as count FROM crm_deals
            WHERE utm_term REGEXP '^[0-9]{15,}'
               OR LENGTH(utm_term) > 50
               OR utm_campaign REGEXP '^[0-9]{15,}'
               OR LENGTH(utm_campaign) > 50
               OR utm_content REGEXP '^[0-9]{15,}'
               OR LENGTH(utm_content) > 50";

    $totalProblems = $db->fetchOne($sql)['count'];

    echo "<div class='stats-box'>";
    echo "<h3>Знайдено проблемних сделок: <strong style='color: red; font-size: 24px;'>{$totalProblems}</strong></h3>";
    echo "</div>";

    if ($totalProblems === 0) {
        echo "<p class='success'>✅ Проблемних записів не знайдено!</p>";
        exit;
    }

    // ==========================================
    // КРОК 2: ВИКОНАННЯ
    // ==========================================

    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {

        echo "<h2>🚀 Крок 2: ВИКОНАННЯ РОЗУМНОГО ВИПРАВЛЕННЯ</h2>";
        echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%;'>Початок...</div></div>";
        echo "<p id='status'>Завантаження...</p>";

        flush();

        // Отримати список всіх проблемних deal_id
        $sql = "SELECT DISTINCT deal_id
                FROM crm_deals
                WHERE utm_term REGEXP '^[0-9]{15,}'
                   OR LENGTH(utm_term) > 50
                   OR utm_campaign REGEXP '^[0-9]{15,}'
                   OR LENGTH(utm_campaign) > 50
                   OR utm_content REGEXP '^[0-9]{15,}'
                   OR LENGTH(utm_content) > 50
                ORDER BY deal_id DESC";

        $problematicDealIds = $db->fetchAll($sql);

        $total = count($problematicDealIds);
        $fixedFromWebhook = 0;
        $cleared = 0;
        $noWebhook = 0;
        $errors = 0;

        echo "<p><strong>Всього сделок для обробки:</strong> {$total}</p>";
        echo "<table style='font-size: 11px;'>";
        echo "<tr><th>Deal ID</th><th>Дія</th><th>Було → Стало</th></tr>";

        foreach ($problematicDealIds as $index => $row) {
            $dealId = $row['deal_id'];

            // Знайти webhook лог
            $sql = "SELECT raw_data FROM webhook_log
                    WHERE deal_id = :deal_id AND webhook_type = 'crm'
                    ORDER BY created_at ASC LIMIT 1";

            $webhook = $db->fetchOne($sql, ['deal_id' => $dealId]);

            if ($webhook) {
                $rawData = json_decode($webhook['raw_data'], true);
                $vars = $rawData['variables'] ?? [];

                // Спробувати взяти з _deal полів
                $correctUtmSource = isset($vars['utm_source_deal']) && !empty($vars['utm_source_deal']) ? strtolower(trim($vars['utm_source_deal'])) : null;
                $correctUtmMedium = isset($vars['utm_medium_deal']) && !empty($vars['utm_medium_deal']) ? strtolower(trim($vars['utm_medium_deal'])) : null;
                $correctUtmCampaign = isset($vars['utm_campaign_deal']) && !empty($vars['utm_campaign_deal']) ? strtolower(trim($vars['utm_campaign_deal'])) : null;
                $correctUtmTerm = isset($vars['utm_term_deal']) && !empty($vars['utm_term_deal']) ? strtolower(trim($vars['utm_term_deal'])) : null;
                $correctUtmContent = isset($vars['utm_content_deal']) && !empty($vars['utm_content_deal']) ? strtolower(trim($vars['utm_content_deal'])) : null;

                // Якщо є хоч щось з _deal - виправити
                if ($correctUtmSource || $correctUtmMedium || $correctUtmCampaign || $correctUtmTerm || $correctUtmContent) {

                    $updates = [];
                    $params = ['deal_id' => $dealId];

                    if ($correctUtmSource !== null) {
                        $updates[] = "utm_source = :utm_source";
                        $params['utm_source'] = $correctUtmSource;
                    }
                    if ($correctUtmMedium !== null) {
                        $updates[] = "utm_medium = :utm_medium";
                        $params['utm_medium'] = $correctUtmMedium;
                    }
                    if ($correctUtmCampaign !== null) {
                        $updates[] = "utm_campaign = :utm_campaign";
                        $params['utm_campaign'] = $correctUtmCampaign;
                    }
                    if ($correctUtmTerm !== null) {
                        $updates[] = "utm_term = :utm_term";
                        $params['utm_term'] = $correctUtmTerm;
                    } else {
                        // Якщо utm_term_deal пустий - очистити
                        $updates[] = "utm_term = ''";
                    }
                    if ($correctUtmContent !== null) {
                        $updates[] = "utm_content = :utm_content";
                        $params['utm_content'] = $correctUtmContent;
                    }

                    if (!empty($updates)) {
                        $updateSql = "UPDATE crm_deals SET " . implode(', ', $updates) . " WHERE deal_id = :deal_id";
                        $db->execute($updateSql, $params);

                        echo "<tr class='success'><td>{$dealId}</td><td>✅ Виправлено з webhook</td><td>term: {$correctUtmTerm}</td></tr>";
                        $fixedFromWebhook++;
                    }

                } else {
                    // Немає даних з _deal - очистити
                    $sql = "UPDATE crm_deals
                            SET utm_term = '', utm_campaign = '', utm_content = ''
                            WHERE deal_id = :deal_id
                              AND (utm_term REGEXP '^[0-9]{15,}' OR LENGTH(utm_term) > 50
                                   OR utm_campaign REGEXP '^[0-9]{15,}' OR LENGTH(utm_campaign) > 50
                                   OR utm_content REGEXP '^[0-9]{15,}' OR LENGTH(utm_content) > 50)";
                    $db->execute($sql, ['deal_id' => $dealId]);

                    echo "<tr class='warning'><td>{$dealId}</td><td>🧹 Очищено (немає _deal)</td><td>→ пусто</td></tr>";
                    $cleared++;
                }

            } else {
                // Немає webhook - очистити
                $sql = "UPDATE crm_deals
                        SET utm_term = '', utm_campaign = '', utm_content = ''
                        WHERE deal_id = :deal_id
                          AND (utm_term REGEXP '^[0-9]{15,}' OR LENGTH(utm_term) > 50
                               OR utm_campaign REGEXP '^[0-9]{15,}' OR LENGTH(utm_campaign) > 50
                               OR utm_content REGEXP '^[0-9]{15,}' OR LENGTH(utm_content) > 50)";
                $db->execute($sql, ['deal_id' => $dealId]);

                echo "<tr class='error'><td>{$dealId}</td><td>❌ Очищено (немає webhook)</td><td>→ пусто</td></tr>";
                $noWebhook++;
            }

            // Оновити прогрес кожні 100 записів
            if ($index % 100 === 0) {
                $progress = round(($index / $total) * 100);
                echo "<script>
                    document.getElementById('progressBar').style.width = '{$progress}%';
                    document.getElementById('progressBar').innerText = '{$progress}% - Оброблено: {$index} з {$total}';
                    document.getElementById('status').innerText = 'Виправлено: {$fixedFromWebhook} | Очищено: " . ($cleared + $noWebhook) . "';
                </script>";
                flush();

                // Показати тільки перші 50 в таблиці
                if ($index === 50) {
                    echo "<tr><td colspan='3'>... обробка продовжується (показано перші 50) ...</td></tr>";
                    echo "</table>";
                    echo "<div id='liveStats'></div>";
                    echo "<table style='display:none;' id='hiddenTable'>";
                }
            }
        }

        echo "</table>";

        // Фінальний прогрес
        echo "<script>
            document.getElementById('progressBar').style.width = '100%';
            document.getElementById('progressBar').innerText = '100% - ЗАВЕРШЕНО!';
        </script>";

        echo "<hr>";
        echo "<h2 style='color: green;'>🎉 ВИПРАВЛЕННЯ ЗАВЕРШЕНО!</h2>";

        echo "<div class='stats-box'>";
        echo "<table>";
        echo "<tr><th>Статус</th><th>Кількість</th><th>%</th></tr>";
        echo "<tr class='success'><td>✅ Виправлено з webhook (знайдено *_deal)</td><td><strong>{$fixedFromWebhook}</strong></td><td>" . round(($fixedFromWebhook / $total) * 100, 1) . "%</td></tr>";
        echo "<tr class='warning'><td>🧹 Очищено (webhook є, але *_deal пусті)</td><td><strong>{$cleared}</strong></td><td>" . round(($cleared / $total) * 100, 1) . "%</td></tr>";
        echo "<tr class='error'><td>❌ Очищено (webhook не знайдено)</td><td><strong>{$noWebhook}</strong></td><td>" . round(($noWebhook / $total) * 100, 1) . "%</td></tr>";
        echo "<tr><td>⚠️ Помилки</td><td>{$errors}</td><td>-</td></tr>";
        echo "<tr style='font-weight: bold; background: #e0f2fe;'><td><strong>ВСЬОГО оброблено</strong></td><td><strong>{$total}</strong></td><td>100%</td></tr>";
        echo "</table>";
        echo "</div>";

        echo "<p style='text-align: center;'><a href='index.php' class='btn' style='background: #10b981; font-size: 20px;'>→ Перейти до дашборду</a></p>";

    } else {
        // Попередження
        echo "<div class='warning'>";
        echo "<h3>📋 ПЛАН ВИПРАВЛЕННЯ:</h3>";

        echo "<h4>Етап 1: Спроба виправити з webhook логів (ПРІОРИТЕТ)</h4>";
        echo "<p>Для кожної проблемної сделки:</p>";
        echo "<ol>";
        echo "<li>Знайти webhook лог в таблиці <code>webhook_log</code></li>";
        echo "<li>Витягти <strong>правильні</strong> дані з полів <code>utm_*_deal</code></li>";
        echo "<li>Оновити БД правильними значеннями</li>";
        echo "</ol>";

        echo "<p><strong>Приклад:</strong></p>";
        echo "<pre>";
        echo "Webhook має:\n";
        echo "  utm_term = '120231525...' (Meta Ads ID) ❌\n";
        echo "  utm_term_deal = 'vadym' ✅\n\n";
        echo "→ Збережемо: utm_term = 'vadym'\n";
        echo "</pre>";

        echo "<h4>Етап 2: Очищення (якщо немає webhook або *_deal пусті)</h4>";
        echo "<p>Якщо для сделки:</p>";
        echo "<ul>";
        echo "<li>Немає webhook логу, АБО</li>";
        echo "<li>В webhook всі <code>*_deal</code> поля пусті (NULL)</li>";
        echo "</ul>";
        echo "<p>→ Очистити Meta Ads ID (встановити пусті значення)</p>";

        echo "<h4>⏱️ Очікуваний час:</h4>";
        echo "<ul>";
        echo "<li>Всього сделок: <strong>{$totalProblems}</strong></li>";
        echo "<li>Швидкість: ~100-200 записів/сек</li>";
        echo "<li>Приблизний час: <strong>3-10 хвилин</strong></li>";
        echo "</ul>";

        echo "<div style='background: #fee; padding: 15px; margin: 20px 0;'>";
        echo "<h4 style='color: red;'>⚠️ ВАЖЛИВО:</h4>";
        echo "<ul>";
        echo "<li>Операція незворотна (але безпечна)</li>";
        echo "<li>НЕ закривай сторінку під час виконання</li>";
        echo "<li>Буде показано детальний лог процесу</li>";
        echo "</ul>";
        echo "</div>";

        echo "<p style='text-align: center; margin-top: 40px;'>";
        echo "<a href='?execute=yes' class='btn' style='font-size: 22px; padding: 25px 50px;' onclick='return confirm(\"Почати розумне виправлення {$totalProblems} сделок?\n\n1. Спробує взяти дані з webhook\n2. Якщо немає - очистить\n\nПродовжити?\")'>🧠 РОЗУМНЕ ВИПРАВЛЕННЯ ({$totalProblems} сделок)</a>";
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
