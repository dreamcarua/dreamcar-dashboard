<?php
// === debug_filters.php ===
// Диагностика фильтров "Клиенты" и "Воронка"

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/models/CrmDeal.php';

$db = Database::getInstance();

// Получить все сделки
$allDeals = $db->fetchAll("SELECT deal_id, contact_id, phone, email, model, full_name, created_at, is_paid FROM crm_deals ORDER BY created_at ASC");

echo "<h1>🔍 Диагностика фильтров</h1>";
echo "<p>Всего сделок в базе: <strong>" . count($allDeals) . "</strong></p>";

// === ГРУППИРОВКА ПО КЛИЕНТАМ ===
$contactIdToClient = [];
$phoneToClient = [];
$emailToClient = [];
$clients = [];
$nextClientId = 1;

function parseMultipleValues($value) {
    if (empty($value)) return [];
    $parts = explode(';', $value);
    $result = [];
    foreach ($parts as $part) {
        $part = trim(strtolower($part));
        if (!empty($part)) $result[] = $part;
    }
    return $result;
}

foreach ($allDeals as $deal) {
    $dealId = $deal['deal_id'];
    $contactId = $deal['contact_id'];
    $phones = parseMultipleValues($deal['phone']);
    $emails = parseMultipleValues($deal['email']);

    $foundClientId = null;

    // Поиск по contact_id
    if ($contactId && isset($contactIdToClient[$contactId])) {
        $foundClientId = $contactIdToClient[$contactId];
    }

    // Поиск по телефонам
    if (!$foundClientId) {
        foreach ($phones as $phone) {
            if (isset($phoneToClient[$phone])) {
                $foundClientId = $phoneToClient[$phone];
                break;
            }
        }
    }

    // Поиск по email
    if (!$foundClientId) {
        foreach ($emails as $email) {
            if (isset($emailToClient[$email])) {
                $foundClientId = $emailToClient[$email];
                break;
            }
        }
    }

    // Создать нового клиента
    if (!$foundClientId) {
        $foundClientId = $nextClientId++;
        $clients[$foundClientId] = [
            'deals' => [],
            'volvo_deals' => [],
            'first_volvo_deal_id' => null
        ];
    }

    // Добавить сделку
    $clients[$foundClientId]['deals'][] = $deal;

    // Отслеживать VOLVO
    if (strtoupper($deal['model']) === 'VOLVO') {
        $clients[$foundClientId]['volvo_deals'][] = $deal;
        if ($clients[$foundClientId]['first_volvo_deal_id'] === null) {
            $clients[$foundClientId]['first_volvo_deal_id'] = $dealId;
        }
    }

    // Обновить индексы
    if ($contactId) $contactIdToClient[$contactId] = $foundClientId;
    foreach ($phones as $phone) $phoneToClient[$phone] = $foundClientId;
    foreach ($emails as $email) $emailToClient[$email] = $foundClientId;
}

echo "<p>Уникальных клиентов: <strong>" . count($clients) . "</strong></p>";

// === СТАТИСТИКА ===
$newClients = [];      // 1 сделка
$returningClients = []; // 2+ сделок

foreach ($clients as $clientId => $clientData) {
    $dealsCount = count($clientData['deals']);
    if ($dealsCount === 1) {
        $newClients[$clientId] = $clientData;
    } else {
        $returningClients[$clientId] = $clientData;
    }
}

$newDealsCount = 0;
$newPaidCount = 0;
foreach ($newClients as $c) {
    $newDealsCount += count($c['deals']);
    foreach ($c['deals'] as $d) {
        if ($d['is_paid']) $newPaidCount++;
    }
}

$returningDealsCount = 0;
$returningPaidCount = 0;
foreach ($returningClients as $c) {
    $returningDealsCount += count($c['deals']);
    foreach ($c['deals'] as $d) {
        if ($d['is_paid']) $returningPaidCount++;
    }
}

echo "<hr>";
echo "<h2>👥 Фильтр 'Клиенты'</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Тип</th><th>Клиентов</th><th>Сделок (лидов)</th><th>Оплат</th></tr>";
echo "<tr><td>Новые (1 сделка)</td><td>" . count($newClients) . "</td><td>$newDealsCount</td><td>$newPaidCount</td></tr>";
echo "<tr><td>Существующие (2+ сделок)</td><td>" . count($returningClients) . "</td><td>$returningDealsCount</td><td>$returningPaidCount</td></tr>";
echo "<tr style='background:#ddd'><td><strong>Всего</strong></td><td>" . count($clients) . "</td><td>" . ($newDealsCount + $returningDealsCount) . "</td><td>" . ($newPaidCount + $returningPaidCount) . "</td></tr>";
echo "</table>";

// === ПРИМЕРЫ КЛИЕНТОВ С НЕСКОЛЬКИМИ СДЕЛКАМИ ===
echo "<hr>";
echo "<h2>📋 Примеры существующих клиентов (с 2+ сделками)</h2>";

$examples = array_slice($returningClients, 0, 5, true);

foreach ($examples as $clientId => $clientData) {
    $dealsCount = count($clientData['deals']);
    $firstDeal = $clientData['deals'][0];

    echo "<div style='background:#f5f5f5; padding:10px; margin:10px 0; border-radius:5px'>";
    echo "<strong>Клиент #$clientId</strong> — $dealsCount сделок<br>";
    echo "Имя: " . htmlspecialchars($firstDeal['full_name']) . "<br>";
    echo "Email: " . htmlspecialchars($firstDeal['email']) . "<br>";
    echo "Телефон: " . htmlspecialchars($firstDeal['phone']) . "<br>";
    echo "<br><strong>Сделки:</strong><ul>";

    foreach ($clientData['deals'] as $deal) {
        $status = $deal['is_paid'] ? '✅ Оплачено' : '⏳ Не оплачено';
        $model = $deal['model'] ?: '—';
        echo "<li>#{$deal['deal_id']} — {$deal['created_at']} — $model — $status</li>";
    }
    echo "</ul></div>";
}

// === ФИЛЬТР ВОРОНКА ===
echo "<hr>";
echo "<h2>🎯 Фильтр 'Воронка' (VOLVO)</h2>";

$newInFunnelCount = 0;
$newInFunnelPaid = 0;
$returningInFunnelCount = 0;
$returningInFunnelPaid = 0;

foreach ($clients as $clientData) {
    $volvoDealIds = $clientData['volvo_deals'];
    $firstVolvoDealId = $clientData['first_volvo_deal_id'];

    foreach ($volvoDealIds as $deal) {
        if ($deal['deal_id'] === $firstVolvoDealId) {
            $newInFunnelCount++;
            if ($deal['is_paid']) $newInFunnelPaid++;
        } else {
            $returningInFunnelCount++;
            if ($deal['is_paid']) $returningInFunnelPaid++;
        }
    }
}

$totalVolvo = $newInFunnelCount + $returningInFunnelCount;
$totalVolvoPaid = $newInFunnelPaid + $returningInFunnelPaid;

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Тип</th><th>Сделок</th><th>Оплат</th></tr>";
echo "<tr><td>Новые в воронке (первая сделка VOLVO)</td><td>$newInFunnelCount</td><td>$newInFunnelPaid</td></tr>";
echo "<tr><td>Повторные в воронке (2+ сделок VOLVO)</td><td>$returningInFunnelCount</td><td>$returningInFunnelPaid</td></tr>";
echo "<tr style='background:#ddd'><td><strong>Всего VOLVO</strong></td><td>$totalVolvo</td><td>$totalVolvoPaid</td></tr>";
echo "</table>";

// === ПРИМЕРЫ ПОВТОРНЫХ В ВОРОНКЕ ===
echo "<hr>";
echo "<h2>📋 Примеры повторных в воронке VOLVO</h2>";

$volvoExamples = [];
foreach ($clients as $clientId => $clientData) {
    if (count($clientData['volvo_deals']) >= 2) {
        $volvoExamples[$clientId] = $clientData;
        if (count($volvoExamples) >= 5) break;
    }
}

if (empty($volvoExamples)) {
    echo "<p>Нет клиентов с 2+ сделками в VOLVO</p>";
} else {
    foreach ($volvoExamples as $clientId => $clientData) {
        $firstDeal = $clientData['deals'][0];

        echo "<div style='background:#e8f5e9; padding:10px; margin:10px 0; border-radius:5px'>";
        echo "<strong>Клиент #$clientId</strong> — " . count($clientData['volvo_deals']) . " сделок VOLVO<br>";
        echo "Имя: " . htmlspecialchars($firstDeal['full_name']) . "<br>";
        echo "<br><strong>Сделки VOLVO:</strong><ul>";

        $isFirst = true;
        foreach ($clientData['volvo_deals'] as $deal) {
            $status = $deal['is_paid'] ? '✅' : '⏳';
            $marker = $isFirst ? '🆕 ПЕРВАЯ' : '🔄 ПОВТОРНАЯ';
            echo "<li>#{$deal['deal_id']} — {$deal['created_at']} — $status — $marker</li>";
            $isFirst = false;
        }
        echo "</ul></div>";
    }
}

echo "<hr>";
echo "<p><em>Сгенерировано: " . date('Y-m-d H:i:s') . "</em></p>";
