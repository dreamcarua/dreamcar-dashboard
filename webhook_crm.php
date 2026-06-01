<?php
// === webhook_crm.php ===
// НАЗНАЧЕНИЕ: Webhook для приема CRM событий (new, pay, fail)
// ИСПОЛЬЗОВАНИЕ: POST https://dreamcar.ai-platform.space/dashboard/utm-dashboard/webhook_crm.php
// СВЯЗИ: core/models/CrmDeal.php, core/models/WebhookLog.php

ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/models/CrmDeal.php';
require_once __DIR__ . '/core/models/WebhookLog.php';
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/finance/core/models/FinanceTransaction.php';

$logger = new Logger();
$startTime = microtime(true);

// Установить заголовок JSON
header('Content-Type: application/json; charset=utf-8');

/**
 * Получить реальные UTM метки из WooCommerce (кросс-запрос к dreamlava.wp_webhooks)
 *
 * @param int $orderId WooCommerce order ID
 * @return array Ассоциативный массив ['utm_source' => ..., 'utm_medium' => ..., ...] или пустой
 */
function getRealUtmFromWP($orderId) {
    static $wpPdo = null;

    try {
        // Подключение к dreamlava (lazy, один раз за запрос)
        if ($wpPdo === null) {
            $wpPdo = new PDO(
                'mysql:host=fincheck.mysql.network;port=10145;dbname=dreamlava;charset=utf8mb4',
                'dreamlava',
                getenv('WP_DB_PASS') ?: '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5
                ]
            );
        }

        // Извлечь все _wc_order_attribution_* поля из JSON одним запросом
        $sql = "SELECT jt.meta_key, jt.meta_value
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

        $stmt = $wpPdo->prepare($sql);
        $stmt->execute(['order_id' => $orderId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        // Разобрать результаты
        $wpUtm = [];
        $referrerUrl = null;

        foreach ($rows as $row) {
            if ($row['meta_key'] === '_wc_order_attribution_referrer') {
                $referrerUrl = $row['meta_value'];
            } else {
                // Убрать префикс _wc_order_attribution_ → получим utm_source, utm_medium и т.д.
                $shortKey = str_replace('_wc_order_attribution_', '', $row['meta_key']);
                // Первое найденное значение (ORDER BY w.id DESC = самый свежий вебхук)
                if (!isset($wpUtm[$shortKey])) {
                    $wpUtm[$shortKey] = $row['meta_value'];
                }
            }
        }

        // Если source = "(direct)" или пустой, но есть referrer с UTM — парсить referrer
        $sourceValue = $wpUtm['utm_source'] ?? '';
        if ((empty($sourceValue) || $sourceValue === '(direct)') && $referrerUrl) {
            $parsed = parse_url($referrerUrl);
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $queryParams);
                foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
                    if (!empty($queryParams[$key])) {
                        $wpUtm[$key] = $queryParams[$key];
                    }
                }
            }
        }

        return $wpUtm;

    } catch (Exception $e) {
        error_log("getRealUtmFromWP error (order $orderId): " . $e->getMessage());
        return [];
    }
}

try {
    // Получить сырые данные
    $rawInput = file_get_contents('php://input');

    // Проверить что это POST запрос
    // GET от SendPulse (SP Web Client) = проверка доступности - тихо отвечаем 200
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'method' => $_SERVER['REQUEST_METHOD'], 'note' => 'Send POST with webhook data']);
        exit;
    }

    // Проверить что есть данные
    if (empty($rawInput)) {
        throw new Exception('Empty request body');
    }

    // Декодировать JSON
    $webhookData = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }

    // ========================================
    // Определить тип события
    // ========================================

    $eventType = null;
    $isPending = false;
    $isPaid = false;
    $isFailed = false;

    // ПРАВИЛЬНИЙ маппінг: використовувати stepName_deal ЗАМІСТЬ title!
    $stepNameDeal = isset($webhookData['variables']['stepName_deal'])
        ? mb_strtolower(trim($webhookData['variables']['stepName_deal']))
        : null;

    if ($stepNameDeal) {
        // Маппінг по stepName_deal (точніший!)
        if ($stepNameDeal === 'на підпис' || $stepNameDeal === 'на подпись') {
            $eventType = 'pay';
            $isPaid = true;
        } elseif ($stepNameDeal === 'в роботі' || $stepNameDeal === 'в работе') {
            $eventType = 'pending';
            $isPending = true;
        } elseif ($stepNameDeal === 'нові' || $stepNameDeal === 'новые') {
            $eventType = 'new';
            $isPending = true;
        } elseif ($stepNameDeal === 'fail' || $stepNameDeal === 'неуспешно') {
            $eventType = 'fail';
            $isFailed = true;
        }
    }

    // Fallback: якщо stepName_deal не визначив - використати title
    if (!$isPaid && !$isFailed && !$isPending && isset($webhookData['title'])) {
        $title = strtolower(trim($webhookData['title']));

        if ($title === 'pay') {
            $eventType = 'pay';
            $isPaid = true;
        } elseif ($title === 'new') {
            $eventType = 'new';
            $isPending = true;
        } elseif ($title === 'fail') {
            // title='fail' може бути "В роботі" → pending
            $eventType = 'pending';
            $isPending = true;
        }
    }

    // Если тип события не определен - ошибка
    if ($eventType === null) {
        throw new Exception('Cannot determine event type. Expected title="new", title="pay" or stepName_deal="fail"');
    }

    // ========================================
    // Извлечь поля из variables (БЕЗ суффикса _deal)
    // ========================================

    $variables = $webhookData['variables'] ?? [];

    // Функция для получения значения без _deal
    $getValue = function($field) use ($variables) {
        // Сначала пробуем без _deal
        if (isset($variables[$field])) {
            return $variables[$field];
        }
        // Если нет - пробуем с _deal (но это не приоритет)
        if (isset($variables[$field . '_deal'])) {
            return $variables[$field . '_deal'];
        }
        return null;
    };

    // Извлечь основные поля
    $email = $webhookData['email'] ?? $getValue('email');
    $phone = $webhookData['phone'] ?? $getValue('phone');
    $dealId = $getValue('deal_id') ?? $getValue('id') ?? null;
    $contactId = $getValue('contact_id') ?? null;

    // ==========================================
    // ВИПРАВЛЕННЯ: Правильне витягування UTM-міток
    // ==========================================
    // ПРОБЛЕМА: SendPulse відправляє два набори UTM:
    // 1. utm_source, utm_medium, utm_campaign, utm_term, utm_content - містять Meta Ads ID (campaign_id, ad_id)
    // 2. utm_source_deal, utm_medium_deal, utm_campaign_deal, utm_term_deal, utm_content_deal - містять ПРАВИЛЬНІ значення
    //
    // РІШЕННЯ: ЗАВЖДИ брати поля з _deal суффіксом!

    // UTM Source - спочатку utm_source_deal, потім utm_source
    $utmSourceRaw = isset($variables['utm_source_deal']) ? $variables['utm_source_deal'] : ($getValue('utm_source') ?? '');
    $utmSource = strtolower(trim($utmSourceRaw));

    // UTM Medium - спочатку utm_medium_deal
    $utmMediumRaw = isset($variables['utm_medium_deal']) ? $variables['utm_medium_deal'] : ($getValue('utm_medium') ?? '');
    $utmMedium = strtolower(trim($utmMediumRaw));

    // UTM Campaign - спочатку utm_campaign_deal
    $utmCampaignRaw = isset($variables['utm_campaign_deal']) ? $variables['utm_campaign_deal'] : ($getValue('utm_campaign') ?? '');
    $utmCampaign = strtolower(trim($utmCampaignRaw));

    // UTM Content - спочатку utm_content_deal
    $utmContentRaw = isset($variables['utm_content_deal']) ? $variables['utm_content_deal'] : ($getValue('utm_content') ?? '');
    $utmContent = strtolower(trim($utmContentRaw));

    // UTM Term - ТІЛЬКИ utm_term_deal, якщо його немає → пусто
    $utmTermRaw = isset($variables['utm_term_deal']) && !empty($variables['utm_term_deal'])
        ? $variables['utm_term_deal']
        : '';
    $utmTerm = strtolower(trim($utmTermRaw));

    // Якщо utm_term_deal ПОРОЖНІЙ, перевіряємо utm_term (БЕЗ _deal)
    if (empty($utmTerm)) {
        $utmTermFromPlain = $getValue('utm_term') ?? '';
        // Якщо utm_term це Meta Ads ID (тільки цифри АБО довгий з цифрами) → НЕ БРАТИ!
        if (!preg_match('/^\d{15,}$/', $utmTermFromPlain) && !(strlen($utmTermFromPlain) > 50 && preg_match('/^\d{15,}_/', $utmTermFromPlain))) {
            $utmTerm = strtolower(trim($utmTermFromPlain));
        }
    }

    // Додаткова валідація для campaign та content
    if (preg_match('/^\d{15,}$/', $utmCampaign) || (strlen($utmCampaign) > 50 && preg_match('/^\d{15,}_/', $utmCampaign))) {
        $utmCampaign = ''; // Це Meta Ads ID
    }
    if (preg_match('/^\d{15,}$/', $utmContent) || (strlen($utmContent) > 50 && preg_match('/^\d{15,}_/', $utmContent))) {
        $utmContent = ''; // Це Meta Ads ID
    }

    // ==========================================
    // ИСПРАВЛЕНИЕ: Получить реальные UTM из WooCommerce
    // wp_webhook.php подменяет utm_content на wc_order_id:XXXXX
    // и не парсит _wc_order_attribution_referrer
    // Здесь мы достаем оригинальные данные из dreamlava.wp_webhooks
    // ==========================================
    $wcOrderId = null;

    // Определить order_id из utm_content (wc_order_id:XXXXX)
    if (preg_match('/^wc_order_id:(\d+)$/', $utmContent, $m)) {
        $wcOrderId = (int)$m[1];
    }
    // Или из deal_name (Mercedes GLE AMG - #XXXXX)
    if (!$wcOrderId) {
        $tmpDealName = $getValue('name_deal') ?? '';
        if (preg_match('/#(\d+)$/', $tmpDealName, $m)) {
            $wcOrderId = (int)$m[1];
        }
    }

    // Если нашли order_id — попробовать достать реальные UTM из WP
    if ($wcOrderId) {
        $realUtm = getRealUtmFromWP($wcOrderId);
        if (!empty($realUtm)) {
            // source: "(direct)" → "direct", иначе берем реальное значение
            if (!empty($realUtm['utm_source'])) {
                $src = strtolower(trim($realUtm['utm_source']));
                $utmSource = ($src === '(direct)') ? 'direct' : $src;
            }
            // medium: берем если не мусорный фолбек "order"
            if (!empty($realUtm['utm_medium']) && strtolower(trim($realUtm['utm_medium'])) !== 'order') {
                $utmMedium = strtolower(trim($realUtm['utm_medium']));
            } else {
                $utmMedium = ''; // очистить фолбек
            }
            // campaign: берем если не мусорный фолбек "order_XXXXX"
            if (!empty($realUtm['utm_campaign']) && !preg_match('/^order_\d+$/', $realUtm['utm_campaign'])) {
                $utmCampaign = strtolower(trim($realUtm['utm_campaign']));
            } else {
                $utmCampaign = ''; // очистить фолбек
            }
            // content: берем реальное из WP
            if (!empty($realUtm['utm_content'])) {
                $utmContent = strtolower(trim($realUtm['utm_content']));
            } else {
                $utmContent = ''; // очистить wc_order_id:XXXXX
            }
            // term: берем если не мусорный фолбек (статус заказа)
            $orderStatuses = ['pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed'];
            if (!empty($realUtm['utm_term']) && !in_array(strtolower($realUtm['utm_term']), $orderStatuses)) {
                $utmTerm = strtolower(trim($realUtm['utm_term']));
            } else {
                $utmTerm = ''; // очистить фолбек
            }

            error_log("WP UTM fix (order $wcOrderId): source=$utmSource, medium=$utmMedium, campaign=$utmCampaign, content=$utmContent, term=$utmTerm");
        } else {
            // Нет данных из WP — очистить мусорные фолбеки
            if (preg_match('/^wc_order_id:/', $utmContent)) $utmContent = '';
            if (preg_match('/^order_\d+$/', $utmCampaign)) $utmCampaign = '';
            if ($utmMedium === 'order') $utmMedium = '';
            if ($utmSource === 'woocommerce') $utmSource = '';
            $orderStatuses = ['pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed'];
            if (in_array($utmTerm, $orderStatuses)) $utmTerm = '';

            error_log("WP UTM fix (order $wcOrderId): no data in wp_webhooks, cleaned fallbacks");
        }
    }

    // Извлечь суммы (пробуем разные поля)
    $amountUah = floatval(
        $getValue('amount_uah') ??
        $getValue('price_deal') ??
        $getValue('amount') ??
        0
    );
    $amount = floatval($getValue('amount') ?? ($amountUah > 0 ? $amountUah : 0));

    // Извлечь дополнительные поля
    $firstName = $getValue('firstName') ?? $getValue('name') ?? '';
    $fullName = $firstName; // full_name из firstName
    $comment = $getValue('comment') ?? '';
    $model = $getValue('name_deal') ?? $getValue('model') ?? ''; // name_deal = название проекта (VOLVO, OLD и т.д.)
    $dealName = $getValue('name_deal') ?? $model; // deal_name = название проекта
    $dealStep = $getValue('stepName_deal') ?? '';

    // === Определить wc_order_id и deal_project по диапазону ===
    // wc_order_id уже мог быть извлечен выше из utm_content или deal_name
    $dealWcOrderId = $wcOrderId ?? null;
    // Если не нашли — повторная попытка из deal_name
    if (!$dealWcOrderId && preg_match('/#(\d+)$/', $dealName, $_m)) {
        $dealWcOrderId = (int)$_m[1];
    }
    // Определить проект по диапазону order_id
    // BMW: 1500–16395 | Mercedes: 16396+
    if ($dealWcOrderId !== null && $dealWcOrderId > 0) {
        if ($dealWcOrderId >= 1500 && $dealWcOrderId <= 16395) {
            $dealProject = 'BMW';
        } elseif ($dealWcOrderId >= 16396) {
            $dealProject = 'Mercedes';
        } else {
            $dealProject = null; // меньше 1500 — фолбек по имени модели
        }
    } else {
        $dealProject = null;
    }
    // Фолбек: нет order_id — определяем по имени модели/сделки
    if ($dealProject === null) {
        $mUpper = strtoupper(trim($model));
        // DreamCar AI - новый проект (order_reference: DC-/DCP-/LAVA- + "DreamCar AI" в названии)
        if (strpos($model, 'DreamCar AI') !== false
            || strpos($mUpper, 'DC-') === 0
            || strpos($mUpper, 'DCP-') === 0
            || strpos($mUpper, 'LAVA-') === 0) {
            // С 06.05.2026 проект DreamCar AI = розыгрыш AUDI E-TRON
            // (логика SendPulse не меняется, меняется только название проекта в дашборде)
            $dealProject = (date('Y-m-d') >= '2026-05-06') ? 'AUDI E-TRON' : 'DreamCar AI';
        }
        elseif (strpos($mUpper, 'BMW') === 0)      $dealProject = 'BMW';
        elseif (strpos($mUpper, 'MERCEDES') === 0) $dealProject = 'Mercedes';
        elseif (strpos($mUpper, 'Q7') === 0)   $dealProject = 'Q7';
        elseif (strpos($mUpper, 'VOLVO') === 0) $dealProject = 'VOLVO';
        else {
            $parts = preg_split('/\s+/', $mUpper, 2);
            $dealProject = $parts[0] ?: 'UNKNOWN';
        }
    }
    // === Конец определения deal_project ===

    // === Определить tariff и pay_provider для DreamCar AI / AUDI E-TRON ===
    // Логика SendPulse одинакова для обоих проектов (DreamCar AI до 06.05, AUDI E-TRON с 06.05)
    $tariff = null;
    $payProvider = null;
    if ($dealProject === 'DreamCar AI' || $dealProject === 'AUDI E-TRON') {
        // Тариф: после "DreamCar AI " в deal_name
        if (preg_match('/DreamCar AI\s+(.+?)(?:\s*$)/u', $dealName, $tm)) {
            $tariff = trim($tm[1]); // Пробний, Базовий, Мінімум, Популярний, POWER
            // Фикс опечатки с латинской i -> кириллица
            if ($tariff === 'Мiнiмум') $tariff = 'Мінімум';
        }
        // Платежка: по префиксу order_reference в deal_name
        if (strpos($dealName, 'DCP-') !== false) $payProvider = 'Platon';
        elseif (strpos($dealName, 'LAVA-') !== false) $payProvider = 'Lava.top';
        elseif (strpos($dealName, 'DC-') !== false) $payProvider = 'WayForPay';
    }
    // === Конец определения tariff/pay_provider ===

    $product = $getValue('product') ?? '';
    $tickets = $getValue('tickets') ?? '';
    $ticketsCount = intval($getValue('tickets_count') ?? $getValue('biletov') ?? 0);
    $dealCurrency = $getValue('currency_deal') ?? 'UAH';
    $createdAtDeal = $getValue('createdAt_deal') ?? date('Y-m-d H:i:s');

    // Проверить обязательные поля - ТОЛЬКО deal_id обязателен!
    if (empty($dealId)) {
        throw new Exception('Missing required field: deal_id');
    }

    // ========================================
    // Подготовить данные для CrmDeal
    // ========================================

    // Маппинг stepName_deal → deal_pipeline
    $dealPipeline = '';
    $stepNameLower = strtolower(trim($dealStep));

    if ($stepNameLower === 'готово' || $stepNameLower === 'done') {
        $dealPipeline = 'default_step_done';
    } elseif ($stepNameLower === 'в процесі' || $stepNameLower === 'в процессе' || $stepNameLower === 'in progress') {
        $dealPipeline = 'default_step_in_progress';
    } elseif ($stepNameLower === 'нові' || $stepNameLower === 'новые' || $stepNameLower === 'new') {
        $dealPipeline = 'default_step_new';
    } elseif ($stepNameLower === 'test') {
        $dealPipeline = 'test';
    }

    // ВАЖНО: Для событий pay/fail - загрузить существующую запись
    // чтобы не потерять суммы и UTM метки
    $existingDeal = null;
    if ($eventType === 'pay' || $eventType === 'fail') {
        $existingDeal = CrmDeal::getByDealId($dealId);
    }

    $crmData = [
        'deal_id' => $dealId,
        'contact_id' => $contactId,
        'email' => $email,
        'phone' => $phone,
        'full_name' => $fullName,
        'created_at' => $createdAtDeal,
        'deal_updated_at' => date('Y-m-d H:i:s'),
        'deal_price' => $amountUah,
        'deal_currency' => $dealCurrency,
        'utm_source' => $utmSource,
        'utm_medium' => $utmMedium,
        'utm_campaign' => $utmCampaign,
        'utm_content' => $utmContent,
        'utm_term' => $utmTerm,
        'deal_pipeline' => $dealPipeline,
        'deal_type' => $isPaid ? 'paid' : ($isFailed ? 'failed' : ($isPending ? 'pending' : 'lead')),
        'deal_status' => $isPaid ? 'paid' : ($isFailed ? 'failed' : ($isPending ? 'pending' : 'lead')),
        'is_pending' => $isPending,
        'is_paid' => $isPaid,
        'is_failed' => $isFailed,
        'deal_name' => $dealName,
        'deal_step' => $dealStep,
        'model' => $model,
        'deal_project' => $dealProject,
        'tariff' => $tariff,
        'pay_provider' => $payProvider,
        'wc_order_id' => $dealWcOrderId,
        'comment' => $comment,
        'product' => $product,
        'tickets' => $tickets,
        'tickets_count' => $ticketsCount,
        'list_name' => 'Webhook',
        'tag_list' => 'webhook'
    ];

    // Для событий pay/fail - сохранить существующие суммы если новые не переданы
    if ($existingDeal && ($eventType === 'pay' || $eventType === 'fail')) {
        // Если сумма не передана - использовать существующую
        $crmData['amount_uah'] = ($amountUah > 0) ? $amountUah : ($existingDeal['amount_uah'] ?? 0);
        $crmData['amount'] = ($amount > 0) ? $amount : ($existingDeal['amount'] ?? 0);

        // Если UTM метки пустые - использовать существующие
        if (empty($utmSource)) $crmData['utm_source'] = $existingDeal['utm_source'] ?? '';
        if (empty($utmMedium)) $crmData['utm_medium'] = $existingDeal['utm_medium'] ?? '';
        if (empty($utmCampaign)) $crmData['utm_campaign'] = $existingDeal['utm_campaign'] ?? '';
        if (empty($utmContent)) $crmData['utm_content'] = $existingDeal['utm_content'] ?? '';
        if (empty($utmTerm)) $crmData['utm_term'] = $existingDeal['utm_term'] ?? '';

        // Исправить deal_project/tariff/pay_provider если остался order_reference от старого webhook
        if (!empty($existingDeal['deal_project']) && $existingDeal['deal_project'] !== 'DreamCar AI'
            && $crmData['deal_project'] === 'DreamCar AI') {
            // deal_project уже пересчитан правильно в crmData, оставляем
        }
    } else {
        // Для события new - использовать переданные суммы
        $crmData['amount_uah'] = $amountUah;
        $crmData['amount'] = $amount;
    }

    // ========================================
    // Сохранить в БД через CrmDeal::upsert
    // ========================================

    // ВАЖЛИВО: Перевірити поточний статус перед оновленням
    // Якщо сделка вже is_paid=1, НЕ перезаписувати на is_pending!
    require_once __DIR__ . '/core/Database.php';
    $db = Database::getInstance();

    $currentStatus = $db->fetchOne(
        "SELECT is_paid, is_failed, is_pending FROM crm_deals WHERE deal_id = :deal_id",
        ['deal_id' => $dealId]
    );

    if ($currentStatus && $currentStatus['is_paid'] == 1) {
        // Сделка вже оплачена - НЕ змінювати статус!
        // Але оновити інші поля (UTM, amount тощо)
        $crmData['is_paid'] = 1;
        $crmData['is_failed'] = 0;
        $crmData['is_pending'] = 0;

        error_log("Deal $dealId: Вже оплачена, залишаємо is_paid=1 (ігноруємо title='{$webhookData['title']}')");
    }

    $result = CrmDeal::upsert($crmData);

    // ========================================
    // Автосинхронизация в finance_transactions
    // Только для события 'pay' (реальная оплата).
    // createFromCrm() сам защищён от дублей (проверяет crm_deal_id).
    // Ошибки finance НЕ ломают основной webhook.
    // ========================================

    $financeTxId  = 0;
    $financeError = null;

    if ($eventType === 'pay' && !empty($dealId) && $amountUah > 0) {
        try {
            $financeTxId = FinanceTransaction::createFromCrm([
                'deal_id'      => $dealId,
                'amount_uah'   => $amountUah,
                'deal_project' => $dealProject,
                'created_at'   => $createdAtDeal ?? date('Y-m-d H:i:s'),
            ]);

            if ($financeTxId > 0) {
                $logger->info('CRM webhook → finance_transaction создана', [
                    'deal_id'      => $dealId,
                    'finance_tx'   => $financeTxId,
                    'amount_uah'   => $amountUah,
                    'deal_project' => $dealProject,
                ]);
            }
        } catch (Throwable $financeEx) {
            // НЕ бросаем дальше — финансы не должны ломать основной webhook
            $financeError = $financeEx->getMessage();
            error_log('[webhook_crm] Finance sync error for deal_id=' . $dealId . ': ' . $financeError);
            $logger->warning('CRM webhook → finance sync failed', [
                'deal_id' => $dealId,
                'error'   => $financeError,
            ]);
        }
    }

    // ========================================
    // Логировать в webhook_log
    // ========================================

    $processingTime = round(microtime(true) - $startTime, 3);

    $logId = WebhookLog::create(
        webhookType: 'crm',
        eventType: $eventType,
        rawData: $rawInput,
        processedData: $crmData,
        dealId: $dealId,
        recordsCount: 1,
        success: true,
        errorMessage: null,
        processingTime: $processingTime
    );

    // ========================================
    // Вернуть успешный ответ
    // ========================================

    $response = [
        'success' => true,
        'event_type' => $eventType,
        'deal_id' => $dealId,
        'email' => $email,
        'action' => $result['action'], // 'inserted' или 'updated'
        'is_pending' => $isPending,
        'is_paid' => $isPaid,
        'is_failed' => $isFailed,
        'amount_uah' => $amountUah,
        'finance_tx_id' => $financeTxId,    // ID созданной finance транзакции (0 если не синкнулось)
        'finance_error' => $financeError,   // ошибка finance sync если была
        'processing_time' => $processingTime,
        'log_id' => $logId,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    $logger->success('CRM webhook обработан', [
        'event_type' => $eventType,
        'deal_id' => $dealId,
        'email' => $email,
        'action' => $result['action']
    ]);

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $processingTime = round(microtime(true) - $startTime, 3);

    // Логировать ошибку в webhook_log
    try {
        WebhookLog::create(
            webhookType: 'crm',
            eventType: $eventType ?? 'unknown',
            rawData: $rawInput ?? null,
            processedData: null,
            dealId: $dealId ?? null,
            recordsCount: 0,
            success: false,
            errorMessage: $e->getMessage(),
            processingTime: $processingTime
        );
    } catch (Exception $logError) {
        // Если не удалось залогировать - просто продолжить
    }

    $logger->error('Ошибка в CRM webhook', [
        'error' => $e->getMessage(),
        'raw_input' => $rawInput ?? null
    ]);

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'processing_time' => $processingTime,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
