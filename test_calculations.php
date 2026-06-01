<?php
// === test_calculations.php ===
// Тестовый файл для проверки расчетов статистики
// Открой в браузере: https://dreamcar.ai-platform.space/volvo/dashboard/utm-dashboard/test_calculations.php

header('Content-Type: text/html; charset=utf-8');

require_once 'config/app_config.php';

$dataFile = __DIR__ . '/data/utm_clean.json';

if (!file_exists($dataFile)) {
    die('❌ Файл данных не найден: ' . $dataFile);
}

$content = file_get_contents($dataFile);
$data = json_decode($content, true);

if (!$data) {
    die('❌ Не удалось распарсить JSON');
}

echo "<!DOCTYPE html>";
echo "<html><head><meta charset='UTF-8'><title>Проверка расчетов</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#0a0a0a;color:#fafafa;} .card{background:#171717;padding:20px;margin:10px 0;border-radius:8px;} .error{color:#ef4444;} .success{color:#10b981;} .warning{color:#f59e0b;} table{border-collapse:collapse;width:100%;margin:10px 0;} th,td{padding:8px;text-align:left;border:1px solid #262626;} th{background:#1a1a1a;}</style>";
echo "</head><body>";

echo "<h1>🔍 Проверка расчетов статистики</h1>";
echo "<div class='card'>";
echo "<h2>📊 Общая информация</h2>";
echo "<p>Всего записей в базе: <strong>" . count($data) . "</strong></p>";

// Подсчет по типам
$paidCount = 0;
$failedCount = 0;
$pendingCount = 0;
$leadsCount = 0;

$paidAmount = 0;
$failedAmount = 0;
$pendingAmount = 0;
$totalAmountAll = 0;

foreach ($data as $item) {
    // Использовать amount_uah или deal_price если есть, иначе amount
    // amount может быть в USD (конвертированный), а нам нужны UAH
    $amount = 0;
    if (!empty($item['amount_uah'])) {
        $amount = floatval($item['amount_uah']);
    } elseif (!empty($item['deal_price'])) {
        $amount = floatval($item['deal_price']);
    } else {
        $amount = floatval($item['amount'] ?? 0);
    }
    
    // Исключить аномальные значения (> 1,000,000 UAH)
    if ($amount > 1000000) {
        // Пропустить аномальные сделки
        continue;
    }
    
    $totalAmountAll += $amount;
    
    // Логика определения типа сделки (совпадает с API)
    $pipeline = !empty($item['deal_pipeline']) ? trim($item['deal_pipeline']) : '';
    $dealType = $item['deal_type'] ?? 'lead';
    $hasTickets = !empty($item['tickets']) || (!empty($item['tickets_count']) && intval($item['tickets_count']) > 0);
    
    // Определить isPaid
    $isPaid = false;
    if (!empty($item['is_paid'])) {
        $isPaid = true;
    } elseif ($dealType === 'paid') {
        $isPaid = true;
    } elseif ($pipeline === 'default_step_done') {
        // default_step_done всегда оплачено
        $isPaid = true;
    } elseif ($pipeline === 'test' && $hasTickets) {
        // test с билетами = оплачено
        $isPaid = true;
    } elseif ($hasTickets && empty($pipeline)) {
        // Если есть билеты, но нет pipeline - тоже оплачено
        $isPaid = true;
    }
    
    // Определить isFailed
    $isFailed = false;
    if (!empty($item['is_failed'])) {
        $isFailed = true;
    } elseif ($dealType === 'failed') {
        $isFailed = true;
    } elseif ($pipeline === 'default_step_in_progress') {
        $isFailed = true;
    }
    
    // Определить isPending
    $isPending = false;
    if (!empty($item['is_pending'])) {
        $isPending = true;
    } elseif ($dealType === 'pending') {
        $isPending = true;
    } elseif ($pipeline === 'default_step_new') {
        $isPending = true;
    }
    
    if ($isPaid) {
        $paidCount++;
        $paidAmount += $amount;
    } elseif ($isFailed) {
        $failedCount++;
        $failedAmount += $amount;
    } elseif ($isPending) {
        $pendingCount++;
        $pendingAmount += $amount;
    } else {
        $leadsCount++;
    }
}

echo "<h3>📈 По типам сделок:</h3>";
echo "<table>";
echo "<tr><th>Тип</th><th>Количество</th><th>Сумма (UAH)</th></tr>";
echo "<tr><td>✅ Оплачено</td><td>" . $paidCount . "</td><td>" . number_format($paidAmount, 2) . "</td></tr>";
echo "<tr><td>❌ Неуспешно</td><td>" . $failedCount . "</td><td>" . number_format($failedAmount, 2) . "</td></tr>";
echo "<tr><td>⏳ В процессе</td><td>" . $pendingCount . "</td><td>" . number_format($pendingAmount, 2) . "</td></tr>";
echo "<tr><td>👥 Лиды</td><td>" . $leadsCount . "</td><td>-</td></tr>";
echo "<tr><th>ВСЕГО</th><th>" . count($data) . "</th><th>" . number_format($totalAmountAll, 2) . "</th></tr>";
echo "</table>";

echo "<h3>💰 Расчеты:</h3>";
echo "<table>";
echo "<tr><th>Показатель</th><th>Формула</th><th>Результат</th></tr>";

// Общая сумма (должна быть только оплаченные)
$totalAmountPaid = $paidAmount;
echo "<tr><td><strong>Общая сумма (заработано)</strong></td><td>Сумма всех оплаченных сделок</td><td class='success'>" . number_format($totalAmountPaid, 2) . " UAH</td></tr>";

// Средний чек
$avgCheck = $paidCount > 0 ? round($paidAmount / $paidCount, 2) : 0;
echo "<tr><td><strong>Средний чек</strong></td><td>paid_amount / paid_count</td><td class='success'>" . number_format($avgCheck, 2) . " UAH</td></tr>";

// Заработано
echo "<tr><td><strong>Заработано</strong></td><td>paid_amount</td><td class='success'>" . number_format($paidAmount, 2) . " UAH</td></tr>";

// Потеряно = только неуспешные (failed), БЕЗ "в процессе" (pending)
$lostAmount = $failedAmount;
echo "<tr><td><strong>Потеряно</strong></td><td>failed_amount (только неуспешные, без pending)</td><td class='error'>" . number_format($lostAmount, 2) . " UAH</td></tr>";
echo "<tr><td><strong>В процессе</strong></td><td>pending_amount (не считается потерянным)</td><td>" . number_format($pendingAmount, 2) . " UAH</td></tr>";

echo "</table>";

// Проверка через API
echo "<h3>🔍 Проверка через API:</h3>";
$apiUrl = 'api/test.php?date_range=all';
$apiResponse = @file_get_contents($apiUrl);
if ($apiResponse) {
    $apiData = json_decode($apiResponse, true);
    if ($apiData && isset($apiData['stats'])) {
        $apiStats = $apiData['stats'];
        
        echo "<table>";
        echo "<tr><th>Показатель</th><th>Наш расчет</th><th>API результат</th><th>Статус</th></tr>";
        
        // Проверка общей суммы
        $ourTotal = $totalAmountPaid;
        $apiTotal = $apiStats['total_amount'] ?? 0;
        $totalMatch = abs($ourTotal - $apiTotal) < 0.01;
        echo "<tr><td>Общая сумма</td><td>" . number_format($ourTotal, 2) . "</td><td>" . number_format($apiTotal, 2) . "</td><td class='" . ($totalMatch ? 'success' : 'error') . "'>" . ($totalMatch ? '✅' : '❌') . "</td></tr>";
        
        // Проверка среднего чека
        $ourAvg = $avgCheck;
        $apiAvg = $apiStats['avg_amount'] ?? 0;
        $avgMatch = abs($ourAvg - $apiAvg) < 0.01;
        echo "<tr><td>Средний чек</td><td>" . number_format($ourAvg, 2) . "</td><td>" . number_format($apiAvg, 2) . "</td><td class='" . ($avgMatch ? 'success' : 'error') . "'>" . ($avgMatch ? '✅' : '❌') . "</td></tr>";
        
        // Проверка оплачено
        $ourPaid = $paidAmount;
        $apiPaid = $apiStats['paid_amount'] ?? 0;
        $paidMatch = abs($ourPaid - $apiPaid) < 0.01;
        echo "<tr><td>Заработано</td><td>" . number_format($ourPaid, 2) . "</td><td>" . number_format($apiPaid, 2) . "</td><td class='" . ($paidMatch ? 'success' : 'error') . "'>" . ($paidMatch ? '✅' : '❌') . "</td></tr>";
        
        // Проверка потеряно (только failed, без pending)
        $ourLost = $lostAmount;
        $apiLost = $apiStats['failed_amount'] ?? 0; // Только failed, без pending
        $lostMatch = abs($ourLost - $apiLost) < 0.01;
        echo "<tr><td>Потеряно (только failed)</td><td>" . number_format($ourLost, 2) . "</td><td>" . number_format($apiLost, 2) . "</td><td class='" . ($lostMatch ? 'success' : 'error') . "'>" . ($lostMatch ? '✅' : '❌') . "</td></tr>";
        
        echo "</table>";
        
        // Детальная информация
        echo "<h3>📋 Детальная информация из API:</h3>";
        echo "<pre style='background:#1a1a1a;padding:15px;border-radius:8px;overflow:auto;'>";
        echo "paid_count: " . ($apiStats['paid_count'] ?? 0) . "\n";
        echo "failed_count: " . ($apiStats['failed_count'] ?? 0) . "\n";
        echo "pending_count: " . ($apiStats['pending_count'] ?? 0) . "\n";
        echo "leads_count: " . ($apiStats['leads_count'] ?? 0) . "\n";
        echo "paid_amount: " . number_format($apiStats['paid_amount'] ?? 0, 2) . "\n";
        echo "failed_amount: " . number_format($apiStats['failed_amount'] ?? 0, 2) . "\n";
        echo "pending_amount: " . number_format($apiStats['pending_amount'] ?? 0, 2) . "\n";
        echo "total_amount: " . number_format($apiStats['total_amount'] ?? 0, 2) . "\n";
        echo "avg_amount: " . number_format($apiStats['avg_amount'] ?? 0, 2) . "\n";
        echo "</pre>";
    }
}

// Примеры сделок для проверки
echo "<h3>🔬 Примеры сделок (первые 20 оплаченных):</h3>";
echo "<table>";
echo "<tr><th>Deal ID</th><th>Email</th><th>Pipeline</th><th>Amount</th><th>Amount UAH</th><th>is_paid</th><th>is_failed</th><th>is_pending</th><th>Тип</th><th>Билеты</th></tr>";

// Найти оплаченные сделки (с правильной логикой)
$paidExamples = [];
foreach ($data as $item) {
    $pipeline = !empty($item['deal_pipeline']) ? trim($item['deal_pipeline']) : '';
    $dealType = $item['deal_type'] ?? 'lead';
    $hasTickets = !empty($item['tickets']) || (!empty($item['tickets_count']) && intval($item['tickets_count']) > 0);
    
    $isPaid = false;
    if (!empty($item['is_paid'])) {
        $isPaid = true;
    } elseif ($dealType === 'paid') {
        $isPaid = true;
    } elseif ($pipeline === 'default_step_done') {
        $isPaid = true;
    } elseif ($pipeline === 'test' && $hasTickets) {
        $isPaid = true;
    } elseif ($hasTickets && empty($pipeline)) {
        $isPaid = true;
    }
    
    if ($isPaid) {
        $paidExamples[] = $item;
        if (count($paidExamples) >= 20) break;
    }
}

foreach ($paidExamples as $item) {
    $pipeline = !empty($item['deal_pipeline']) ? trim($item['deal_pipeline']) : '';
    $dealType = $item['deal_type'] ?? 'lead';
    $hasTickets = !empty($item['tickets']) || (!empty($item['tickets_count']) && intval($item['tickets_count']) > 0);
    
    $isPaid = false;
    if (!empty($item['is_paid'])) {
        $isPaid = true;
    } elseif ($dealType === 'paid') {
        $isPaid = true;
    } elseif ($pipeline === 'default_step_done') {
        $isPaid = true;
    } elseif ($pipeline === 'test' && $hasTickets) {
        $isPaid = true;
    } elseif ($hasTickets && empty($pipeline)) {
        $isPaid = true;
    }
    
    $isFailed = false;
    if (!empty($item['is_failed'])) {
        $isFailed = true;
    } elseif ($dealType === 'failed') {
        $isFailed = true;
    } elseif ($pipeline === 'default_step_in_progress') {
        $isFailed = true;
    }
    
    $isPending = false;
    if (!empty($item['is_pending'])) {
        $isPending = true;
    } elseif ($dealType === 'pending') {
        $isPending = true;
    } elseif ($pipeline === 'default_step_new') {
        $isPending = true;
    }
    
    $type = $isPaid ? '✅ Оплачено' : ($isFailed ? '❌ Неуспешно' : ($isPending ? '⏳ В процессе' : '👥 Лид'));
    
    echo "<tr>";
    echo "<td><strong>" . ($item['deal_id'] ?? 'нет') . "</strong></td>";
    echo "<td>" . substr($item['email'] ?? 'нет', 0, 30) . "</td>";
    echo "<td>" . ($item['deal_pipeline'] ?? 'нет') . "</td>";
    echo "<td>" . number_format($item['amount'] ?? 0, 2) . "</td>";
    echo "<td>" . number_format($item['amount_uah'] ?? $item['deal_price'] ?? 0, 2) . "</td>";
    echo "<td>" . ($isPaid ? '✅' : '❌') . "</td>";
    echo "<td>" . ($isFailed ? '✅' : '❌') . "</td>";
    echo "<td>" . ($isPending ? '✅' : '❌') . "</td>";
    echo "<td>" . $type . "</td>";
    echo "<td>" . ($item['tickets_count'] ?? 0) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Проверить суммы по amount и amount_uah
echo "<h3>🔍 Проверка сумм по полям:</h3>";
$sumByAmount = 0;
$sumByAmountUAH = 0;
$sumByDealPrice = 0;
$paidCountByAmount = 0;
$paidCountByAmountUAH = 0;
$paidCountByDealPrice = 0;

foreach ($data as $item) {
    $isPaid = !empty($item['is_paid']) || ($item['deal_type'] ?? '') === 'paid' || 
              ($item['deal_pipeline'] ?? '') === 'default_step_done' ||
              (($item['deal_pipeline'] ?? '') === 'test' && (!empty($item['tickets']) || ($item['tickets_count'] ?? 0) > 0));
    
    if ($isPaid) {
        $amount = floatval($item['amount'] ?? 0);
        $amountUAH = floatval($item['amount_uah'] ?? 0);
        $dealPrice = floatval($item['deal_price'] ?? 0);
        
        if ($amount > 0) {
            $sumByAmount += $amount;
            $paidCountByAmount++;
        }
        if ($amountUAH > 0) {
            $sumByAmountUAH += $amountUAH;
            $paidCountByAmountUAH++;
        }
        if ($dealPrice > 0) {
            $sumByDealPrice += $dealPrice;
            $paidCountByDealPrice++;
        }
    }
}

echo "<table>";
echo "<tr><th>Поле</th><th>Сумма (UAH)</th><th>Количество</th></tr>";
echo "<tr><td>amount (конвертированный в USD)</td><td>" . number_format($sumByAmount, 2) . "</td><td>" . $paidCountByAmount . "</td></tr>";
echo "<tr><td>amount_uah</td><td>" . number_format($sumByAmountUAH, 2) . "</td><td>" . $paidCountByAmountUAH . "</td></tr>";
echo "<tr><td>deal_price</td><td>" . number_format($sumByDealPrice, 2) . "</td><td>" . $paidCountByDealPrice . "</td></tr>";
echo "</table>";

// Проверить какие сделки не определяются как оплаченные, но должны быть
echo "<h3>🔍 Поиск пропущенных оплаченных сделок:</h3>";
$missedPaid = [];
$pipelineStats = [];

foreach ($data as $item) {
    $pipeline = $item['deal_pipeline'] ?? '';
    $dealType = $item['deal_type'] ?? '';
    $isPaid = !empty($item['is_paid']);
    $hasTickets = !empty($item['tickets']) || (!empty($item['tickets_count']) && intval($item['tickets_count']) > 0);
    
    // Статистика по pipeline
    if (!isset($pipelineStats[$pipeline])) {
        $pipelineStats[$pipeline] = ['total' => 0, 'paid' => 0, 'not_paid' => 0];
    }
    $pipelineStats[$pipeline]['total']++;
    
    // Проверить разные варианты определения оплаты
    $currentIsPaid = !empty($item['is_paid']) || $dealType === 'paid' || 
                     $pipeline === 'default_step_done' ||
                     ($pipeline === 'test' && $hasTickets);
    
    if ($currentIsPaid) {
        $pipelineStats[$pipeline]['paid']++;
    } else {
        $pipelineStats[$pipeline]['not_paid']++;
        
        // Если есть deal_price или amount_uah, но не определяется как оплаченная
        $hasAmount = !empty($item['deal_price']) || !empty($item['amount_uah']);
        if ($hasAmount && ($pipeline === 'default_step_done' || ($pipeline === 'test' && $hasTickets))) {
            $missedPaid[] = [
                'deal_id' => $item['deal_id'] ?? 'нет',
                'pipeline' => $pipeline,
                'deal_type' => $dealType,
                'is_paid' => $isPaid,
                'has_tickets' => $hasTickets,
                'amount_uah' => $item['amount_uah'] ?? 0,
                'deal_price' => $item['deal_price'] ?? 0
            ];
        }
    }
}

echo "<h4>📊 Статистика по pipeline:</h4>";
echo "<table>";
echo "<tr><th>Pipeline</th><th>Всего</th><th>Оплачено</th><th>Не оплачено</th></tr>";
foreach ($pipelineStats as $pipeline => $stats) {
    echo "<tr>";
    echo "<td>" . ($pipeline ?: 'нет') . "</td>";
    echo "<td>" . $stats['total'] . "</td>";
    echo "<td>" . $stats['paid'] . "</td>";
    echo "<td>" . $stats['not_paid'] . "</td>";
    echo "</tr>";
}
echo "</table>";

if (count($missedPaid) > 0) {
    echo "<h4>⚠️ Потенциально пропущенные оплаченные сделки (" . count($missedPaid) . "):</h4>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Pipeline</th><th>Deal Type</th><th>is_paid</th><th>Билеты</th><th>Amount UAH</th><th>Deal Price</th></tr>";
    foreach (array_slice($missedPaid, 0, 20) as $item) {
        echo "<tr>";
        echo "<td>" . $item['deal_id'] . "</td>";
        echo "<td>" . $item['pipeline'] . "</td>";
        echo "<td>" . $item['deal_type'] . "</td>";
        echo "<td>" . ($item['is_paid'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($item['has_tickets'] ? '✅' : '❌') . "</td>";
        echo "<td>" . number_format($item['amount_uah'], 2) . "</td>";
        echo "<td>" . number_format($item['deal_price'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Анализ неуспешных сделок
echo "<h3>🔍 Анализ неуспешных сделок:</h3>";
$failedExamples = [];
$failedWithHighAmount = [];

foreach ($data as $item) {
    $pipeline = !empty($item['deal_pipeline']) ? trim($item['deal_pipeline']) : '';
    $dealType = $item['deal_type'] ?? 'lead';
    
    $isFailed = false;
    if (!empty($item['is_failed'])) {
        $isFailed = true;
    } elseif ($dealType === 'failed') {
        $isFailed = true;
    } elseif ($pipeline === 'default_step_in_progress') {
        $isFailed = true;
    }
    
    if ($isFailed) {
        $amount = 0;
        if (!empty($item['amount_uah'])) {
            $amount = floatval($item['amount_uah']);
        } elseif (!empty($item['deal_price'])) {
            $amount = floatval($item['deal_price']);
        } else {
            $amount = floatval($item['amount'] ?? 0);
        }
        
        $failedExamples[] = [
            'deal_id' => $item['deal_id'] ?? 'нет',
            'pipeline' => $item['deal_pipeline'] ?? 'нет',
            'amount' => $amount,
            'amount_uah' => floatval($item['amount_uah'] ?? 0),
            'deal_price' => floatval($item['deal_price'] ?? 0),
            'amount_field' => floatval($item['amount'] ?? 0)
        ];
        
        // Сделки с очень большой суммой (> 10000 UAH)
        if ($amount > 10000) {
            $failedWithHighAmount[] = [
                'deal_id' => $item['deal_id'] ?? 'нет',
                'pipeline' => $item['deal_pipeline'] ?? 'нет',
                'amount' => $amount,
                'amount_uah' => floatval($item['amount_uah'] ?? 0),
                'deal_price' => floatval($item['deal_price'] ?? 0),
                'amount_field' => floatval($item['amount'] ?? 0)
            ];
        }
    }
}

// Статистика по неуспешным
$failedStats = [
    'total' => count($failedExamples),
    'with_amount' => 0,
    'without_amount' => 0,
    'avg_amount' => 0,
    'max_amount' => 0,
    'min_amount' => PHP_INT_MAX,
    'sum' => 0
];

foreach ($failedExamples as $item) {
    if ($item['amount'] > 0) {
        $failedStats['with_amount']++;
        $failedStats['sum'] += $item['amount'];
        if ($item['amount'] > $failedStats['max_amount']) {
            $failedStats['max_amount'] = $item['amount'];
        }
        if ($item['amount'] < $failedStats['min_amount']) {
            $failedStats['min_amount'] = $item['amount'];
        }
    } else {
        $failedStats['without_amount']++;
    }
}

if ($failedStats['with_amount'] > 0) {
    $failedStats['avg_amount'] = $failedStats['sum'] / $failedStats['with_amount'];
}

echo "<h4>📊 Статистика по неуспешным:</h4>";
echo "<table>";
echo "<tr><th>Показатель</th><th>Значение</th></tr>";
echo "<tr><td>Всего неуспешных</td><td>" . $failedStats['total'] . "</td></tr>";
echo "<tr><td>С суммой > 0</td><td>" . $failedStats['with_amount'] . "</td></tr>";
echo "<tr><td>Без суммы</td><td>" . $failedStats['without_amount'] . "</td></tr>";
echo "<tr><td>Средняя сумма</td><td>" . number_format($failedStats['avg_amount'], 2) . " UAH</td></tr>";
echo "<tr><td>Максимальная сумма</td><td>" . number_format($failedStats['max_amount'], 2) . " UAH</td></tr>";
echo "<tr><td>Минимальная сумма</td><td>" . number_format($failedStats['min_amount'] == PHP_INT_MAX ? 0 : $failedStats['min_amount'], 2) . " UAH</td></tr>";
echo "<tr><td><strong>Общая сумма</strong></td><td><strong>" . number_format($failedStats['sum'], 2) . " UAH</strong></td></tr>";
echo "</table>";

// Примеры неуспешных сделок с большими суммами
if (count($failedWithHighAmount) > 0) {
    echo "<h4>⚠️ Неуспешные сделки с большой суммой (> 10,000 UAH):</h4>";
    echo "<p class='warning'>Найдено " . count($failedWithHighAmount) . " сделок с суммой больше 10,000 UAH</p>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Pipeline</th><th>Amount UAH</th><th>Deal Price</th><th>Amount (field)</th></tr>";
    foreach (array_slice($failedWithHighAmount, 0, 20) as $item) {
        echo "<tr>";
        echo "<td>" . $item['deal_id'] . "</td>";
        echo "<td>" . $item['pipeline'] . "</td>";
        echo "<td>" . number_format($item['amount_uah'], 2) . "</td>";
        echo "<td>" . number_format($item['deal_price'], 2) . "</td>";
        echo "<td>" . number_format($item['amount_field'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Примеры первых 20 неуспешных сделок
echo "<h4>🔬 Примеры неуспешных сделок (первые 20):</h4>";
echo "<table>";
echo "<tr><th>Deal ID</th><th>Pipeline</th><th>Amount UAH</th><th>Deal Price</th><th>Amount (field)</th></tr>";
foreach (array_slice($failedExamples, 0, 20) as $item) {
    echo "<tr>";
    echo "<td>" . $item['deal_id'] . "</td>";
    echo "<td>" . $item['pipeline'] . "</td>";
    echo "<td>" . number_format($item['amount_uah'], 2) . "</td>";
    echo "<td>" . number_format($item['deal_price'], 2) . "</td>";
    echo "<td>" . number_format($item['amount_field'], 2) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Найти аномальные сделки (сумма > 1,000,000 UAH)
echo "<h4>🚨 Аномальные сделки (сумма > 1,000,000 UAH):</h4>";
$anomalousDeals = [];
foreach ($failedExamples as $item) {
    if ($item['amount'] > 1000000) {
        $anomalousDeals[] = $item;
    }
}

if (count($anomalousDeals) > 0) {
    echo "<p class='error'><strong>⚠️ ВНИМАНИЕ:</strong> Найдено " . count($anomalousDeals) . " аномальных сделок с суммой больше 1,000,000 UAH!</p>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Pipeline</th><th>Amount UAH</th><th>Deal Price</th><th>Amount (field)</th><th>Возможная ошибка</th></tr>";
    foreach ($anomalousDeals as $item) {
        // Проверить может ли это быть ошибка (например, сумма в копейках или другой валюте)
        $possibleError = '';
        if ($item['amount'] > 10000000) {
            $possibleError = 'Возможно сумма в копейках или другой валюте';
        } elseif ($item['amount'] / $item['amount_field'] > 100) {
            $possibleError = 'Возможно неправильная конвертация валюты';
        }
        
        echo "<tr>";
        echo "<td><strong>" . $item['deal_id'] . "</strong></td>";
        echo "<td>" . $item['pipeline'] . "</td>";
        echo "<td class='error'>" . number_format($item['amount_uah'], 2) . "</td>";
        echo "<td class='error'>" . number_format($item['deal_price'], 2) . "</td>";
        echo "<td>" . number_format($item['amount_field'], 2) . "</td>";
        echo "<td>" . $possibleError . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Пересчитать сумму БЕЗ аномальных сделок
$failedSumWithoutAnomalous = 0;
$failedCountWithoutAnomalous = 0;
foreach ($failedExamples as $item) {
    if ($item['amount'] <= 1000000) { // Исключить аномальные
        $failedSumWithoutAnomalous += $item['amount'];
        $failedCountWithoutAnomalous++;
    }
}

// Анализ сумм по полям для неуспешных
echo "<h4>🔍 Суммы по полям для неуспешных:</h4>";
$failedSumByAmount = 0;
$failedSumByAmountUAH = 0;
$failedSumByDealPrice = 0;

foreach ($failedExamples as $item) {
    $failedSumByAmount += $item['amount_field'];
    $failedSumByAmountUAH += $item['amount_uah'];
    $failedSumByDealPrice += $item['deal_price'];
}

echo "<table>";
echo "<tr><th>Поле</th><th>Сумма (UAH)</th><th>Примечание</th></tr>";
echo "<tr><td>amount (field)</td><td>" . number_format($failedSumByAmount, 2) . "</td><td>Конвертированное из USD</td></tr>";
echo "<tr><td>amount_uah (ВСЕ)</td><td class='error'>" . number_format($failedSumByAmountUAH, 2) . "</td><td>Включает аномальные значения</td></tr>";
echo "<tr><td>amount_uah (без аномальных)</td><td class='success'>" . number_format($failedSumWithoutAnomalous, 2) . "</td><td>Исключены суммы > 1,000,000</td></tr>";
echo "<tr><td>deal_price</td><td class='error'>" . number_format($failedSumByDealPrice, 2) . "</td><td>Включает аномальные значения</td></tr>";
echo "</table>";

// Поиск пропущенных оплаченных и неуспешных сделок
echo "<h3>🔍 Поиск пропущенных сделок:</h3>";

// Ожидаемые значения
$expectedPaid = 21745;
$expectedPaidAmount = 8999134;
$expectedFailed = 5185;
$expectedFailedAmount = 33292614;

echo "<h4>📊 Ожидаемые vs Фактические:</h4>";
echo "<table>";
echo "<tr><th>Тип</th><th>Ожидается</th><th>Фактически</th><th>Разница</th></tr>";
echo "<tr><td>Оплачено (количество)</td><td>" . $expectedPaid . "</td><td>" . $paidCount . "</td><td class='" . (abs($expectedPaid - $paidCount) > 0 ? 'error' : 'success') . "'>" . ($expectedPaid - $paidCount) . "</td></tr>";
echo "<tr><td>Оплачено (сумма)</td><td>" . number_format($expectedPaidAmount, 2) . " UAH</td><td>" . number_format($paidAmount, 2) . " UAH</td><td class='" . (abs($expectedPaidAmount - $paidAmount) > 1 ? 'error' : 'success') . "'>" . number_format($expectedPaidAmount - $paidAmount, 2) . " UAH</td></tr>";
echo "<tr><td>Неуспешно (количество)</td><td>" . $expectedFailed . "</td><td>" . $failedCount . "</td><td class='" . (abs($expectedFailed - $failedCount) > 0 ? 'error' : 'success') . "'>" . ($expectedFailed - $failedCount) . "</td></tr>";
echo "<tr><td>Неуспешно (сумма)</td><td>" . number_format($expectedFailedAmount, 2) . " UAH</td><td>" . number_format($failedAmount, 2) . " UAH</td><td class='" . (abs($expectedFailedAmount - $failedAmount) > 1 ? 'error' : 'success') . "'>" . number_format($expectedFailedAmount - $failedAmount, 2) . " UAH</td></tr>";
echo "</table>";

// Поиск сделок которые должны быть оплаченными, но не определяются
$missedPaid = [];
$missedFailed = [];

foreach ($data as $item) {
    $pipeline = !empty($item['deal_pipeline']) ? trim($item['deal_pipeline']) : '';
    $dealType = $item['deal_type'] ?? 'lead';
    $hasTickets = !empty($item['tickets']) || (!empty($item['tickets_count']) && intval($item['tickets_count']) > 0);
    
    // Определить тип
    $isPaid = false;
    if (!empty($item['is_paid'])) {
        $isPaid = true;
    } elseif ($dealType === 'paid') {
        $isPaid = true;
    } elseif ($pipeline === 'default_step_done') {
        $isPaid = true;
    } elseif ($pipeline === 'test' && $hasTickets) {
        $isPaid = true;
    } elseif ($hasTickets && empty($pipeline)) {
        $isPaid = true;
    }
    
    $isFailed = false;
    if (!empty($item['is_failed'])) {
        $isFailed = true;
    } elseif ($dealType === 'failed') {
        $isFailed = true;
    } elseif ($pipeline === 'default_step_in_progress') {
        $isFailed = true;
    }
    
    $amount = 0;
    if (!empty($item['amount_uah'])) {
        $amount = floatval($item['amount_uah']);
    } elseif (!empty($item['deal_price'])) {
        $amount = floatval($item['deal_price']);
    }
    
    // Исключить аномальные
    if ($amount > 1000000) continue;
    
    // Если есть deal_price или amount_uah, но не определяется как оплаченная
    if ($amount > 0 && !$isPaid && !$isFailed && $pipeline !== 'default_step_new' && $pipeline !== 'default_step_in_progress') {
        $missedPaid[] = [
            'deal_id' => $item['deal_id'] ?? 'нет',
            'pipeline' => $pipeline,
            'deal_type' => $dealType,
            'has_tickets' => $hasTickets,
            'amount' => $amount
        ];
    }
    
    // Если pipeline = default_step_in_progress, но не определяется как failed
    if ($pipeline === 'default_step_in_progress' && !$isFailed) {
        $missedFailed[] = [
            'deal_id' => $item['deal_id'] ?? 'нет',
            'pipeline' => $pipeline,
            'deal_type' => $dealType,
            'is_paid' => $isPaid,
            'is_failed' => $isFailed,
            'amount' => $amount
        ];
    }
}

if (count($missedPaid) > 0) {
    echo "<h4>⚠️ Пропущенные оплаченные сделки (" . count($missedPaid) . "):</h4>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Pipeline</th><th>Deal Type</th><th>Билеты</th><th>Amount</th></tr>";
    foreach (array_slice($missedPaid, 0, 30) as $item) {
        echo "<tr>";
        echo "<td>" . $item['deal_id'] . "</td>";
        echo "<td>" . ($item['pipeline'] ?: 'нет') . "</td>";
        echo "<td>" . $item['deal_type'] . "</td>";
        echo "<td>" . ($item['has_tickets'] ? '✅' : '❌') . "</td>";
        echo "<td>" . number_format($item['amount'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

if (count($missedFailed) > 0) {
    echo "<h4>⚠️ Пропущенные неуспешные сделки (" . count($missedFailed) . "):</h4>";
    echo "<table>";
    echo "<tr><th>Deal ID</th><th>Pipeline</th><th>Deal Type</th><th>is_paid</th><th>is_failed</th><th>Amount</th></tr>";
    foreach (array_slice($missedFailed, 0, 20) as $item) {
        echo "<tr>";
        echo "<td>" . $item['deal_id'] . "</td>";
        echo "<td>" . $item['pipeline'] . "</td>";
        echo "<td>" . $item['deal_type'] . "</td>";
        echo "<td>" . ($item['is_paid'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($item['is_failed'] ? '✅' : '❌') . "</td>";
        echo "<td>" . number_format($item['amount'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='success'>✅ Все сделки с pipeline='default_step_in_progress' правильно определяются как failed.</p>";
}

echo "<p class='warning'><strong>⚠️ ВАЖНО:</strong> В данных есть аномальные значения! Возможно нужно фильтровать сделки с суммой больше разумного предела (например, 100,000 UAH).</p>";

echo "<p class='warning'><strong>⚠️ ВАЖНО:</strong> Проверьте какое поле используется для суммы! Возможно нужно использовать deal_price или amount_uah вместо amount!</p>";

echo "</div>";
echo "<div class='card'>";
echo "<h2>❓ Вопросы для проверки:</h2>";
echo "<ol>";
echo "<li>Сколько должно быть оплаченных сделок? (ожидаемое число)</li>";
echo "<li>Какая должна быть общая сумма заработанных денег? (ожидаемое число)</li>";
echo "<li>Правильно ли определяется тип сделки по deal_pipeline?</li>";
echo "<li>Есть ли сделки с deal_pipeline='test' но без билетов - должны ли они считаться оплаченными?</li>";
echo "<li>Есть ли сделки с amount=0 - должны ли они учитываться в расчетах?</li>";
echo "</ol>";
echo "</div>";

echo "</body></html>";
?>

