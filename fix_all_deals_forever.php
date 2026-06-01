<?php
/**
 * ВИПРАВЛЕННЯ ВСІХ СДЕЛОК ЗА ВЕСЬ ЧАС
 * Очистити всі довгі Meta Ads ID з utm_term, utm_campaign, utm_content
 */

set_time_limit(600); // 10 хвилин
ini_set('memory_limit', '512M');

require_once 'config/app_config.php';
require_once 'core/Database.php';

echo "<h1>🔧 Виправлення ВСІХ сделок за весь час</h1>";
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
    .progress-bar { background: #10b981; height: 100%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 16px; }
</style>";

try {
    $db = Database::getInstance();

    // ==========================================
    // КРОК 1: АНАЛІЗ ВСІХ ПРОБЛЕМНИХ ЗАПИСІВ
    // ==========================================

    echo "<h2>📊 Крок 1: Аналіз проблемних записів (за весь час)</h2>";

    // Підрахунок по типах проблем
    $sql1 = "SELECT COUNT(*) as count FROM crm_deals
             WHERE utm_term REGEXP '^[0-9]{15,}' OR LENGTH(utm_term) > 50";
    $countTerm = $db->fetchOne($sql1)['count'];

    $sql2 = "SELECT COUNT(*) as count FROM crm_deals
             WHERE utm_campaign REGEXP '^[0-9]{15,}' OR LENGTH(utm_campaign) > 50";
    $countCampaign = $db->fetchOne($sql2)['count'];

    $sql3 = "SELECT COUNT(*) as count FROM crm_deals
             WHERE utm_content REGEXP '^[0-9]{15,}' OR LENGTH(utm_content) > 50";
    $countContent = $db->fetchOne($sql3)['count'];

    echo "<table>";
    echo "<tr><th>Поле</th><th>Кількість проблемних записів</th></tr>";
    echo "<tr class='error'><td><strong>utm_term</strong></td><td>{$countTerm}</td></tr>";
    echo "<tr class='error'><td><strong>utm_campaign</strong></td><td>{$countCampaign}</td></tr>";
    echo "<tr class='error'><td><strong>utm_content</strong></td><td>{$countContent}</td></tr>";
    echo "<tr><td><strong>ВСЬОГО</strong></td><td><strong>" . ($countTerm + $countCampaign + $countContent) . "</strong></td></tr>";
    echo "</table>";

    $totalProblems = max($countTerm, $countCampaign, $countContent);

    if ($totalProblems === 0) {
        echo "<p class='success'>✅ Проблемних записів не знайдено!</p>";
        exit;
    }

    // ==========================================
    // КРОК 2: ВИКОНАННЯ ОЧИЩЕННЯ
    // ==========================================

    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {

        echo "<h2>🚀 Крок 2: ВИКОНАННЯ ОЧИЩЕННЯ</h2>";
        echo "<div class='progress'><div class='progress-bar' id='progressBar' style='width: 0%;'>0%</div></div>";
        echo "<p id='status'>Початок обробки...</p>";

        flush();

        // 1. Очистити utm_term (тільки цифри або довгі з цифрами)
        echo "<h3>1. Очищення utm_term...</h3>";
        $sql = "UPDATE crm_deals
                SET utm_term = ''
                WHERE utm_term REGEXP '^[0-9]{15,}'
                   OR (LENGTH(utm_term) > 50 AND utm_term REGEXP '^[0-9]{15,}_')";
        $db->execute($sql);
        echo "<p class='success'>✅ Очищено utm_term: {$countTerm} записів</p>";

        echo "<script>document.getElementById('progressBar').style.width = '33%'; document.getElementById('progressBar').innerText = '33%';</script>";
        flush();

        // 2. Очистити utm_campaign
        echo "<h3>2. Очищення utm_campaign...</h3>";
        $sql = "UPDATE crm_deals
                SET utm_campaign = ''
                WHERE utm_campaign REGEXP '^[0-9]{15,}'
                   OR (LENGTH(utm_campaign) > 50 AND utm_campaign REGEXP '^[0-9]{15,}_')";
        $db->execute($sql);
        echo "<p class='success'>✅ Очищено utm_campaign: {$countCampaign} записів</p>";

        echo "<script>document.getElementById('progressBar').style.width = '66%'; document.getElementById('progressBar').innerText = '66%';</script>";
        flush();

        // 3. Очистити utm_content
        echo "<h3>3. Очищення utm_content...</h3>";
        $sql = "UPDATE crm_deals
                SET utm_content = ''
                WHERE utm_content REGEXP '^[0-9]{15,}'
                   OR (LENGTH(utm_content) > 50 AND utm_content REGEXP '^[0-9]{15,}_')";
        $db->execute($sql);
        echo "<p class='success'>✅ Очищено utm_content: {$countContent} записів</p>";

        echo "<script>document.getElementById('progressBar').style.width = '100%'; document.getElementById('progressBar').innerText = '100% - Готово!';</script>";
        flush();

        // 4. Перевірка результату
        echo "<h3>4. Перевірка результату...</h3>";

        $sql = "SELECT COUNT(*) as count FROM crm_deals
                WHERE utm_term REGEXP '^[0-9]{15,}' OR LENGTH(utm_term) > 50";
        $remainingTerm = $db->fetchOne($sql)['count'];

        $sql = "SELECT COUNT(*) as count FROM crm_deals
                WHERE utm_campaign REGEXP '^[0-9]{15,}' OR LENGTH(utm_campaign) > 50";
        $remainingCampaign = $db->fetchOne($sql)['count'];

        $sql = "SELECT COUNT(*) as count FROM crm_deals
                WHERE utm_content REGEXP '^[0-9]{15,}' OR LENGTH(utm_content) > 50";
        $remainingContent = $db->fetchOne($sql)['count'];

        echo "<table>";
        echo "<tr><th>Поле</th><th>Залишилось проблемних</th></tr>";
        echo "<tr><td>utm_term</td><td>{$remainingTerm}</td></tr>";
        echo "<tr><td>utm_campaign</td><td>{$remainingCampaign}</td></tr>";
        echo "<tr><td>utm_content</td><td>{$remainingContent}</td></tr>";
        echo "</table>";

        echo "<hr>";
        echo "<h2 class='success'>🎉 ОЧИЩЕННЯ ЗАВЕРШЕНО!</h2>";

        echo "<table>";
        echo "<tr><th>Статистика</th><th>Значення</th></tr>";
        echo "<tr class='success'><td>✅ Очищено utm_term</td><td><strong>{$countTerm}</strong></td></tr>";
        echo "<tr class='success'><td>✅ Очищено utm_campaign</td><td><strong>{$countCampaign}</strong></td></tr>";
        echo "<tr class='success'><td>✅ Очищено utm_content</td><td><strong>{$countContent}</strong></td></tr>";
        echo "<tr><td><strong>Всього оброблено</strong></td><td><strong>" . ($countTerm + $countCampaign + $countContent) . "</strong></td></tr>";
        echo "</table>";

        echo "<p><a href='index.php' class='btn' style='background: #10b981; font-size: 18px;'>→ Перейти до дашборду</a></p>";

    } else {
        // Попередження
        echo "<div class='warning'>";
        echo "<h3>⚠️ КРИТИЧНО ВАЖЛИВО!</h3>";
        echo "<p><strong>Ти збираєшся очистити Meta Ads ID з ВСІХ сделок (за весь час):</strong></p>";

        echo "<table>";
        echo "<tr><th>Що буде очищено</th><th>Кількість</th></tr>";
        echo "<tr><td>utm_term (довгі Meta Ads ID)</td><td class='error'><strong>{$countTerm}</strong> записів</td></tr>";
        echo "<tr><td>utm_campaign (довгі Meta Ads ID)</td><td class='error'><strong>{$countCampaign}</strong> записів</td></tr>";
        echo "<tr><td>utm_content (довгі Meta Ads ID)</td><td class='error'><strong>{$countContent}</strong> записів</td></tr>";
        echo "</table>";

        echo "<h4>Що буде видалено:</h4>";
        echo "<ul>";
        echo "<li>Всі utm_term що починаються з цифр (campaign_id: <code>120227...</code>)</li>";
        echo "<li>Всі довгі utm_term (>50 символів) типу <code>120231525461530624_geo-ua_pl...</code></li>";
        echo "<li>Те саме для utm_campaign і utm_content</li>";
        echo "</ul>";

        echo "<h4>Що залишиться:</h4>";
        echo "<ul>";
        echo "<li>✅ Короткі utm_term: artem, vira, vadym, oborotfb, dreamcar</li>";
        echo "<li>✅ Нормальні utm_campaign: audiq7, anons_audi_q7, тощо</li>";
        echo "<li>✅ Нормальні utm_content</li>";
        echo "</ul>";

        echo "<div style='background: #fee; padding: 15px; margin: 20px 0;'>";
        echo "<h4 style='color: red;'>⚠️ УВАГА:</h4>";
        echo "<ul>";
        echo "<li><strong>Операція незворотна!</strong></li>";
        echo "<li>Час виконання: ~2-5 хвилин</li>";
        echo "<li>Буде оброблено ~{$totalProblems}+ записів</li>";
        echo "<li>Рекомендується backup БД (але не обов'язково)</li>";
        echo "</ul>";
        echo "</div>";

        echo "<p style='text-align: center; margin-top: 30px;'>";
        echo "<a href='?execute=yes' class='btn' style='font-size: 20px; padding: 20px 40px;' onclick='return confirm(\"ТИ ВПЕВНЕНИЙ? Буде очищено " . ($countTerm + $countCampaign + $countContent) . " записів!\")'>🧹 ОЧИСТИТИ ВСІ META ADS ID</a>";
        echo "</p>";
        echo "<p style='text-align: center;'>";
        echo "<a href='index.php' style='color: #666; text-decoration: underline;'>← Скасувати і повернутись до дашборду</a>";
        echo "</p>";

        echo "</div>";
    }

} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ ПОМИЛКА:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
