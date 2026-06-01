<?php
// === fix_wc_order_id_utm.php ===
// НАЗНАЧЕНИЕ: Исправление исторических UTM меток в crm_deals
// ПРОБЛЕМА: wp_webhook.php подменяет utm_content на wc_order_id:XXXXX
//           и не парсит _wc_order_attribution_referrer для UTM
// РЕШЕНИЕ: Кросс-запрос к dreamlava.wp_webhooks для получения реальных UTM
// ОБНОВЛЕНО: 2026-02-09

ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

echo "=== Fix WC Order ID UTM ===\n";
echo "Дата запуска: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Подключение к dreamcar_utm (дашборд)
    $dbDashboard = new PDO(
        'mysql:host=fincheck.mysql.network;port=10145;dbname=dreamcar_utm;charset=utf8mb4',
        'dreamcar_utm',
        getenv('DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Подключение к dreamlava (WooCommerce webhooks)
    $dbWP = new PDO(
        'mysql:host=fincheck.mysql.network;port=10145;dbname=dreamlava;charset=utf8mb4',
        'dreamlava',
        getenv('WP_DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    echo "Подключение к БД: OK\n\n";

    // ==========================================
    // 1. Найти сделки с мусорными UTM
    // ==========================================
    $sql = "SELECT deal_id, deal_name, utm_source, utm_medium, utm_campaign, utm_content, utm_term
            FROM crm_deals
            WHERE utm_content LIKE 'wc_order_id:%'
               OR utm_campaign REGEXP '^order_[0-9]+$'
               OR utm_medium = 'order'
               OR utm_source = 'woocommerce'
            ORDER BY created_at DESC";

    $deals = $dbDashboard->query($sql)->fetchAll();
    $totalDeals = count($deals);
    echo "Найдено сделок с мусорными UTM: $totalDeals\n\n";

    if ($totalDeals === 0) {
        echo "Нечего исправлять.\n";
        exit;
    }

    // ==========================================
    // 2. Подготовить SQL для получения UTM из WP
    // ==========================================
    $wpSql = "SELECT jt.meta_key, jt.meta_value
              FROM wp_webhooks w,
              JSON_TABLE(
                  w.webhook_data,
                  '$.meta_data[*]' COLUMNS (
                      meta_key VARCHAR(100) PATH '$.key',
                      meta_value VARCHAR(500) PATH '$.value'
                  )
              ) jt
              WHERE w.order_id = :order_id
                AND jt.meta_key IN (
                    '_wc_order_attribution_utm_source',
                    '_wc_order_attribution_utm_medium',
                    '_wc_order_attribution_utm_campaign',
                    '_wc_order_attribution_utm_content',
                    '_wc_order_attribution_utm_term',
                    '_wc_order_attribution_referrer'
                )
              ORDER BY w.id DESC";
    $wpStmt = $dbWP->prepare($wpSql);

    // SQL для обновления
    $updateSql = "UPDATE crm_deals SET
                    utm_source = :utm_source,
                    utm_medium = :utm_medium,
                    utm_campaign = :utm_campaign,
                    utm_content = :utm_content,
                    utm_term = :utm_term
                  WHERE deal_id = :deal_id";
    $updateStmt = $dbDashboard->prepare($updateSql);

    // Статусы заказов WooCommerce (мусорные значения utm_term)
    $orderStatuses = ['pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed'];

    // ==========================================
    // 3. Обработать каждую сделку
    // ==========================================
    $stats = [
        'fixed_from_utm' => 0,       // Исправлено из прямых UTM meta_data
        'fixed_from_referrer' => 0,   // Исправлено из referrer URL
        'cleaned_fallbacks' => 0,     // Очищены фолбеки (нет данных в WP)
        'not_found_in_wp' => 0,       // Не найден order_id в wp_webhooks
        'no_order_id' => 0,           // Не удалось определить order_id
        'errors' => 0
    ];

    foreach ($deals as $i => $deal) {
        $dealId = $deal['deal_id'];
        $num = $i + 1;

        // Определить order_id
        $orderId = null;

        // Из utm_content: wc_order_id:XXXXX
        if (preg_match('/^wc_order_id:(\d+)$/', $deal['utm_content'], $m)) {
            $orderId = (int)$m[1];
        }
        // Из utm_campaign: order_XXXXX
        if (!$orderId && preg_match('/^order_(\d+)$/', $deal['utm_campaign'], $m)) {
            $orderId = (int)$m[1];
        }
        // Из deal_name: ... #XXXXX
        if (!$orderId && preg_match('/#(\d+)/', $deal['deal_name'], $m)) {
            $orderId = (int)$m[1];
        }

        if (!$orderId) {
            echo "[$num/$totalDeals] deal=$dealId: НЕ удалось определить order_id из " .
                 "content='{$deal['utm_content']}', campaign='{$deal['utm_campaign']}', name='{$deal['deal_name']}'\n";
            $stats['no_order_id']++;
            continue;
        }

        try {
            // Запросить WP
            $wpStmt->execute(['order_id' => $orderId]);
            $rows = $wpStmt->fetchAll();

            if (empty($rows)) {
                // Нет данных в wp_webhooks — очистить фолбеки
                $newUtm = [
                    'utm_source' => $deal['utm_source'],
                    'utm_medium' => $deal['utm_medium'],
                    'utm_campaign' => $deal['utm_campaign'],
                    'utm_content' => $deal['utm_content'],
                    'utm_term' => $deal['utm_term']
                ];

                // Очистить мусорные значения
                if (preg_match('/^wc_order_id:/', $newUtm['utm_content'])) $newUtm['utm_content'] = '';
                if (preg_match('/^order_\d+$/', $newUtm['utm_campaign'])) $newUtm['utm_campaign'] = '';
                if ($newUtm['utm_medium'] === 'order') $newUtm['utm_medium'] = '';
                if ($newUtm['utm_source'] === 'woocommerce') $newUtm['utm_source'] = '';
                if (in_array($newUtm['utm_term'], $orderStatuses)) $newUtm['utm_term'] = '';

                $updateStmt->execute([
                    'utm_source' => $newUtm['utm_source'],
                    'utm_medium' => $newUtm['utm_medium'],
                    'utm_campaign' => $newUtm['utm_campaign'],
                    'utm_content' => $newUtm['utm_content'],
                    'utm_term' => $newUtm['utm_term'],
                    'deal_id' => $dealId
                ]);

                echo "[$num/$totalDeals] deal=$dealId, order=$orderId: НЕТ в wp_webhooks, очищены фолбеки\n";
                $stats['not_found_in_wp']++;
                continue;
            }

            // Разобрать результаты
            $wpUtm = [];
            $referrerUrl = null;

            foreach ($rows as $row) {
                if ($row['meta_key'] === '_wc_order_attribution_referrer') {
                    if (!$referrerUrl) $referrerUrl = $row['meta_value'];
                } else {
                    $shortKey = str_replace('_wc_order_attribution_', '', $row['meta_key']);
                    if (!isset($wpUtm[$shortKey])) {
                        $wpUtm[$shortKey] = $row['meta_value'];
                    }
                }
            }

            // Определить источник данных
            $fixSource = 'utm';

            // Если source = "(direct)" или пустой, но есть referrer с UTM — парсить referrer
            $sourceValue = $wpUtm['utm_source'] ?? '';
            if ((empty($sourceValue) || $sourceValue === '(direct)') && $referrerUrl) {
                $parsed = parse_url($referrerUrl);
                if (isset($parsed['query'])) {
                    parse_str($parsed['query'], $queryParams);
                    $hasUtmInReferrer = false;
                    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
                        if (!empty($queryParams[$key])) {
                            $wpUtm[$key] = $queryParams[$key];
                            $hasUtmInReferrer = true;
                        }
                    }
                    if ($hasUtmInReferrer) {
                        $fixSource = 'referrer';
                    }
                }
            }

            // Собрать финальные значения
            $newUtm = [
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
                'utm_content' => '',
                'utm_term' => ''
            ];

            // source: "(direct)" → "direct"
            if (!empty($wpUtm['utm_source'])) {
                $src = strtolower(trim($wpUtm['utm_source']));
                $newUtm['utm_source'] = ($src === '(direct)') ? 'direct' : $src;
            }
            // medium: берем если не фолбек
            if (!empty($wpUtm['utm_medium']) && strtolower(trim($wpUtm['utm_medium'])) !== 'order') {
                $newUtm['utm_medium'] = strtolower(trim($wpUtm['utm_medium']));
            }
            // campaign: берем если не фолбек
            if (!empty($wpUtm['utm_campaign']) && !preg_match('/^order_\d+$/', $wpUtm['utm_campaign'])) {
                $newUtm['utm_campaign'] = strtolower(trim($wpUtm['utm_campaign']));
            }
            // content: берем реальное
            if (!empty($wpUtm['utm_content'])) {
                $newUtm['utm_content'] = strtolower(trim($wpUtm['utm_content']));
            }
            // term: берем если не статус заказа
            if (!empty($wpUtm['utm_term']) && !in_array(strtolower($wpUtm['utm_term']), $orderStatuses)) {
                $newUtm['utm_term'] = strtolower(trim($wpUtm['utm_term']));
            }

            // Обновить в БД
            $updateStmt->execute([
                'utm_source' => $newUtm['utm_source'],
                'utm_medium' => $newUtm['utm_medium'],
                'utm_campaign' => $newUtm['utm_campaign'],
                'utm_content' => $newUtm['utm_content'],
                'utm_term' => $newUtm['utm_term'],
                'deal_id' => $dealId
            ]);

            $changed = ($deal['utm_source'] !== $newUtm['utm_source'] ||
                        $deal['utm_medium'] !== $newUtm['utm_medium'] ||
                        $deal['utm_campaign'] !== $newUtm['utm_campaign'] ||
                        $deal['utm_content'] !== $newUtm['utm_content'] ||
                        $deal['utm_term'] !== $newUtm['utm_term']);

            if ($changed) {
                echo "[$num/$totalDeals] deal=$dealId, order=$orderId ($fixSource): ";
                echo "source: '{$deal['utm_source']}'->'{$newUtm['utm_source']}', ";
                echo "medium: '{$deal['utm_medium']}'->'{$newUtm['utm_medium']}', ";
                echo "campaign: '{$deal['utm_campaign']}'->'{$newUtm['utm_campaign']}', ";
                echo "content: '{$deal['utm_content']}'->'{$newUtm['utm_content']}', ";
                echo "term: '{$deal['utm_term']}'->'{$newUtm['utm_term']}'\n";

                if ($fixSource === 'referrer') {
                    $stats['fixed_from_referrer']++;
                } else {
                    $stats['fixed_from_utm']++;
                }
            } else {
                echo "[$num/$totalDeals] deal=$dealId, order=$orderId: без изменений\n";
                $stats['cleaned_fallbacks']++;
            }

        } catch (Exception $e) {
            echo "[$num/$totalDeals] deal=$dealId, order=$orderId: ОШИБКА — " . $e->getMessage() . "\n";
            $stats['errors']++;
        }
    }

    // ==========================================
    // 4. Отчет
    // ==========================================
    echo "\n=== ИТОГО ===\n";
    echo "Всего сделок с мусорными UTM: $totalDeals\n";
    echo "Исправлено из прямых UTM: {$stats['fixed_from_utm']}\n";
    echo "Исправлено из referrer URL: {$stats['fixed_from_referrer']}\n";
    echo "Очищены фолбеки (без данных в WP): {$stats['not_found_in_wp']}\n";
    echo "Без изменений: {$stats['cleaned_fallbacks']}\n";
    echo "Не удалось определить order_id: {$stats['no_order_id']}\n";
    echo "Ошибки: {$stats['errors']}\n";
    echo "\nГотово: " . date('Y-m-d H:i:s') . "\n";

    // ==========================================
    // 5. Проверка контрольных записей
    // ==========================================
    echo "\n=== ПРОВЕРКА КОНТРОЛЬНЫХ ЗАПИСЕЙ ===\n";

    // Заказ 19499 (deal 13140912)
    $check1 = $dbDashboard->query("SELECT deal_id, deal_name, utm_source, utm_medium, utm_campaign, utm_content, utm_term FROM crm_deals WHERE deal_id = '13140912'")->fetch();
    if ($check1) {
        echo "Deal 13140912 (заказ 19499):\n";
        echo "  source:   {$check1['utm_source']}\n";
        echo "  medium:   {$check1['utm_medium']}\n";
        echo "  campaign: {$check1['utm_campaign']}\n";
        echo "  content:  {$check1['utm_content']}\n";
        echo "  term:     {$check1['utm_term']}\n";
    } else {
        echo "Deal 13140912 не найден\n";
    }

    // Заказ 19496 (deal 13140815)
    $check2 = $dbDashboard->query("SELECT deal_id, deal_name, utm_source, utm_medium, utm_campaign, utm_content, utm_term FROM crm_deals WHERE deal_id = '13140815'")->fetch();
    if ($check2) {
        echo "\nDeal 13140815 (заказ 19496):\n";
        echo "  source:   {$check2['utm_source']}\n";
        echo "  medium:   {$check2['utm_medium']}\n";
        echo "  campaign: {$check2['utm_campaign']}\n";
        echo "  content:  {$check2['utm_content']}\n";
        echo "  term:     {$check2['utm_term']}\n";
    } else {
        echo "\nDeal 13140815 не найден\n";
    }

    // Сколько осталось мусорных
    $remaining = $dbDashboard->query("SELECT COUNT(*) as cnt FROM crm_deals WHERE utm_content LIKE 'wc_order_id:%' OR utm_campaign REGEXP '^order_[0-9]+$' OR utm_medium = 'order'")->fetch();
    echo "\nОсталось записей с мусорными UTM: {$remaining['cnt']}\n";

} catch (Exception $e) {
    echo "КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
