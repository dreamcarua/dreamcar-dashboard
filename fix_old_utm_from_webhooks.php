<?php
/**
 * Виправлення старих UTM-міток з webhook логів
 * Призначення: Знайти правильні UTM-мітки з webhook логів і оновити БД
 */

set_time_limit(300); // 5 хвилин

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔧 Виправлення старих UTM-міток</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
    th { background: #3b82f6; color: white; }
    .error { background: #fee; }
    .success { background: #efe; }
    .warning { background: #fef3c7; padding: 15px; border-left: 5px solid orange; margin: 20px 0; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px; }
    .btn { padding: 12px 24px; background: #3b82f6; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; cursor: pointer; border: none; font-size: 14px; font-weight: bold; }
    .btn-danger { background: #ef4444; }
    .progress { background: #e5e7eb; height: 30px; border-radius: 5px; overflow: hidden; margin: 20px 0; }
    .progress-bar { background: #10b981; height: 100%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
</style>";

try {
    $db = Database::getInstance();

    // ==========================================
    // КРОК 1: Знайти проблемні сделки
    // ==========================================

    echo "<h2>📊 Крок 1: Пошук проблемних сделок</h2>";

    $sql = "SELECT deal_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content, created_at
            FROM crm_deals
            WHERE utm_term REGEXP '^[0-9]{15,}$'
               OR utm_campaign REGEXP '^[0-9]{15,}$'
               OR utm_content REGEXP '^[0-9]{15,}$'
            ORDER BY created_at DESC";

    $problematicDeals = $db->fetchAll($sql);
    $totalProblems = count($problematicDeals);

    echo "<p>Знайдено проблемних сделок: <strong>{$totalProblems}</strong></p>";

    if ($totalProblems === 0) {
        echo "<p class='success'>✅ Проблемних записів не знайдено!</p>";
        exit;
    }

    // Показати перші 5 прикладів
    echo "<h3>Приклади (перші 5):</h3>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Campaign (поганий)</th><th>Term (поганий)</th><th>Content (поганий)</th><th>Дата</th></tr>";
    foreach (array_slice($problematicDeals, 0, 5) as $deal) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($deal['deal_id']) . "</td>";
        echo "<td class='error'>" . htmlspecialchars($deal['utm_campaign'] ?? '-') . "</td>";
        echo "<td class='error'>" . htmlspecialchars($deal['utm_term'] ?? '-') . "</td>";
        echo "<td class='error'>" . htmlspecialchars($deal['utm_content'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($deal['created_at']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // ==========================================
    // КРОК 2: Виконати виправлення
    // ==========================================

    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {

        echo "<h2>🚀 Крок 2: ВИКОНАННЯ ВИПРАВЛЕННЯ</h2>";

        $fixed = 0;
        $notFound = 0;
        $errors = 0;

        echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%;'>0%</div></div>";
        echo "<p id='status'>Обробка...</p>";

        echo "<table>";
        echo "<tr><th>Deal ID</th><th>Було</th><th>Стало</th><th>Статус</th></tr>";

        foreach ($problematicDeals as $index => $deal) {
            $dealId = $deal['deal_id'];

            // Знайти webhook лог для цієї сделки
            $sql = "SELECT raw_data FROM webhook_log WHERE deal_id = :deal_id ORDER BY created_at DESC LIMIT 1";
            $webhookLog = $db->fetchOne($sql, ['deal_id' => $dealId]);

            if (!$webhookLog) {
                echo "<tr class='error'>";
                echo "<td>{$dealId}</td>";
                echo "<td colspan='2'>-</td>";
                echo "<td>❌ Webhook лог не знайдено</td>";
                echo "</tr>";
                $notFound++;
                continue;
            }

            $rawData = json_decode($webhookLog['raw_data'], true);
            if (!$rawData || !isset($rawData['variables'])) {
                echo "<tr class='error'>";
                echo "<td>{$dealId}</td>";
                echo "<td colspan='2'>-</td>";
                echo "<td>❌ Помилка парсингу JSON</td>";
                echo "</tr>";
                $errors++;
                continue;
            }

            $variables = $rawData['variables'];

            // Витягти правильні значення з _deal полів
            $correctUtmSource = isset($variables['utm_source_deal']) ? strtolower(trim($variables['utm_source_deal'])) : null;
            $correctUtmMedium = isset($variables['utm_medium_deal']) ? strtolower(trim($variables['utm_medium_deal'])) : null;
            $correctUtmCampaign = isset($variables['utm_campaign_deal']) ? strtolower(trim($variables['utm_campaign_deal'])) : null;
            $correctUtmTerm = isset($variables['utm_term_deal']) ? strtolower(trim($variables['utm_term_deal'])) : null;
            $correctUtmContent = isset($variables['utm_content_deal']) ? strtolower(trim($variables['utm_content_deal'])) : null;

            // Оновити в БД
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
            }
            if ($correctUtmContent !== null) {
                $updates[] = "utm_content = :utm_content";
                $params['utm_content'] = $correctUtmContent;
            }

            if (!empty($updates)) {
                $updateSql = "UPDATE crm_deals SET " . implode(', ', $updates) . " WHERE deal_id = :deal_id";
                $db->execute($updateSql, $params);

                echo "<tr class='success'>";
                echo "<td>{$dealId}</td>";
                echo "<td>Campaign: " . htmlspecialchars($deal['utm_campaign'] ?? '-') . "<br>Term: " . htmlspecialchars($deal['utm_term'] ?? '-') . "</td>";
                echo "<td>Campaign: " . htmlspecialchars($correctUtmCampaign ?? '-') . "<br>Term: " . htmlspecialchars($correctUtmTerm ?? '-') . "</td>";
                echo "<td>✅ Виправлено</td>";
                echo "</tr>";

                $fixed++;
            }

            // Показати прогрес кожні 50 записів
            if ($index % 50 === 0) {
                $progress = round(($index / $totalProblems) * 100);
                echo "<script>
                    document.getElementById('progressBar').style.width = '{$progress}%';
                    document.getElementById('progressBar').innerText = '{$progress}%';
                    document.getElementById('status').innerText = 'Оброблено: {$index} з {$totalProblems}';
                </script>";
                flush();
            }
        }

        echo "</table>";

        // Фінальний прогрес
        echo "<script>
            document.getElementById('progressBar').style.width = '100%';
            document.getElementById('progressBar').innerText = '100%';
            document.getElementById('status').innerText = 'Завершено!';
        </script>";

        echo "<hr>";
        echo "<h2 class='success'>🎉 ВИПРАВЛЕННЯ ЗАВЕРШЕНО!</h2>";

        echo "<table>";
        echo "<tr><th>Статус</th><th>Кількість</th></tr>";
        echo "<tr class='success'><td>✅ Виправлено успішно</td><td><strong>{$fixed}</strong></td></tr>";
        echo "<tr class='error'><td>❌ Webhook не знайдено</td><td>{$notFound}</td></tr>";
        echo "<tr class='error'><td>❌ Помилки</td><td>{$errors}</td></tr>";
        echo "<tr><td><strong>Всього оброблено</strong></td><td><strong>{$totalProblems}</strong></td></tr>";
        echo "</table>";

        echo "<p><a href='index.php' class='btn'>→ Перейти до дашборду</a></p>";
        echo "<p><a href='analyze_strange_utm.php' class='btn'>→ Перевірити результат</a></p>";

    } else {
        // Показати попередження
        echo "<div class='warning'>";
        echo "<h3>⚠️ УВАГА! Ти збираєшся виправити {$totalProblems} записів</h3>";
        echo "<p>Скрипт знайде webhook логи для кожної сделки і витягне ПРАВИЛЬНІ UTM-мітки з полів <code>*_deal</code></p>";

        echo "<h4>Що буде зроблено:</h4>";
        echo "<ol>";
        echo "<li>Знайти webhook лог для кожної проблемної сделки</li>";
        echo "<li>Витягти правильні значення з <code>utm_source_deal</code>, <code>utm_campaign_deal</code>, <code>utm_term_deal</code>, <code>utm_content_deal</code></li>";
        echo "<li>Оновити запис в <code>crm_deals</code> правильними значеннями</li>";
        echo "</ol>";

        echo "<h4>Приклад виправлення:</h4>";
        echo "<pre>";
        echo "Було:\n";
        echo "  utm_campaign = '120229752843300605' (campaign_id)\n";
        echo "  utm_term = '120229752843310605' (adset_id)\n";
        echo "  utm_content = '120229753111080605' (ad_id)\n\n";
        echo "Стане:\n";
        echo "  utm_campaign = 'перші картинки'\n";
        echo "  utm_term = 'vadym'\n";
        echo "  utm_content = 'новий набір реклами з ціллю «продажі»'\n";
        echo "</pre>";

        echo "<div style='background: #fee; padding: 15px; margin: 20px 0; border-left: 5px solid red;'>";
        echo "<h4>⚠️ ВАЖЛИВО:</h4>";
        echo "<ul>";
        echo "<li>Процес може зайняти 2-5 хвилин для {$totalProblems} записів</li>";
        echo "<li>Якщо для сделки немає webhook логу - вона буде пропущена</li>";
        echo "<li>Рекомендується зробити backup БД перед виконанням</li>";
        echo "</ul>";
        echo "</div>";

        echo "<p style='margin-top: 30px;'>";
        echo "<a href='?execute=yes' class='btn btn-danger' onclick='return confirm(\"Почати виправлення {$totalProblems} записів?\")'>🔧 ВИПРАВИТИ СТАРІ ДАНІ</a>";
        echo "<a href='index.php' class='btn'>← Скасувати</a>";
        echo "</p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
