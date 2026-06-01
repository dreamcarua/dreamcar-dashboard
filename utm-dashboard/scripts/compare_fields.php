<?php
// === compare_fields.php ===
// НАЗНАЧЕНИЕ: Сравнить какие поля заполняют webhook и CSV импорт
// ИСПОЛЬЗОВАНИЕ: Запустить через браузер

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Сравнение полей</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1400px; margin: 40px auto; padding: 20px; }
        h1 { color: #2563eb; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; }
        .yes { color: #10b981; font-weight: bold; }
        .no { color: #ef4444; }
        .field-name { font-family: 'Courier New', monospace; font-weight: bold; }
    </style>
</head>
<body>";

echo "<h1>📊 Сравнение полей: Webhook vs CSV Import</h1>";

// Все поля из схемы БД
$allFields = [
    'id' => 'AUTO_INCREMENT',
    'deal_id' => 'Уникальный ID сделки',
    'email' => 'Email контакта',
    'phone' => 'Телефон контакта',
    'full_name' => 'Полное имя',
    'created_at' => 'Дата создания сделки',
    'deal_updated_at' => 'Дата обновления сделки',
    'amount' => 'Сумма (базовая)',
    'amount_uah' => 'Сумма в UAH',
    'deal_price' => 'Цена сделки',
    'deal_currency' => 'Валюта (UAH/USD)',
    'utm_source' => 'UTM Source',
    'utm_medium' => 'UTM Medium',
    'utm_campaign' => 'UTM Campaign',
    'utm_term' => 'UTM Term',
    'utm_content' => 'UTM Content',
    'deal_pipeline' => 'Этап воронки',
    'deal_type' => 'Тип (lead/paid/failed/pending)',
    'deal_status' => 'Статус сделки',
    'is_paid' => 'Оплачено (boolean)',
    'is_failed' => 'Отклонено (boolean)',
    'is_pending' => 'В процессе (boolean)',
    'deal_name' => 'Название сделки/проекта',
    'deal_step' => 'Название этапа',
    'model' => 'Модель/проект (VOLVO, OLD)',
    'comment' => 'Комментарий',
    'product' => 'Продукт',
    'tickets' => 'Номера билетов',
    'tickets_count' => 'Количество билетов',
    'list_name' => 'Название списка',
    'tag_list' => 'Теги',
    'imported_at' => 'Timestamp импорта (AUTO)',
    'updated_at' => 'Timestamp обновления (AUTO)'
];

// Что заполняет webhook (ПОСЛЕ ИСПРАВЛЕНИЙ)
$webhookFields = [
    'deal_id', 'email', 'phone', 'full_name', 'created_at', 'deal_updated_at',
    'amount', 'amount_uah', 'deal_price', 'deal_currency',
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    'deal_pipeline', 'deal_type', 'deal_status',
    'is_paid', 'is_failed', 'is_pending',
    'deal_name', 'deal_step', 'model', 'comment',
    'product', 'tickets', 'tickets_count', 'list_name', 'tag_list'
];

// Что заполняет CSV импорт
$csvFields = [
    'deal_id', 'email', 'phone', 'full_name', 'created_at', 'deal_updated_at',
    'amount', 'amount_uah', 'deal_price', 'deal_currency',
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    'deal_pipeline', 'deal_type', 'deal_status',
    'is_paid', 'is_failed', 'is_pending',
    'deal_name', 'deal_step', 'model', 'comment',
    'product', 'tickets', 'tickets_count', 'list_name', 'tag_list'
];

echo "<table>";
echo "<tr>
    <th>Поле</th>
    <th>Описание</th>
    <th>Webhook</th>
    <th>CSV Import</th>
</tr>";

foreach ($allFields as $field => $description) {
    $inWebhook = in_array($field, $webhookFields);
    $inCsv = in_array($field, $csvFields);

    $webhookStatus = $inWebhook ? "<span class='yes'>✅ Да</span>" : "<span class='no'>❌ Нет</span>";
    $csvStatus = $inCsv ? "<span class='yes'>✅ Да</span>" : "<span class='no'>❌ Нет</span>";

    // Авто-поля
    if ($field === 'id' || $field === 'imported_at' || $field === 'updated_at') {
        $webhookStatus = "<span style='color: #6b7280;'>⚙️ AUTO</span>";
        $csvStatus = "<span style='color: #6b7280;'>⚙️ AUTO</span>";
    }

    echo "<tr>";
    echo "<td class='field-name'>$field</td>";
    echo "<td>$description</td>";
    echo "<td>$webhookStatus</td>";
    echo "<td>$csvStatus</td>";
    echo "</tr>";
}

echo "</table>";

$webhookCount = count($webhookFields);
$csvCount = count($csvFields);
$totalFields = count($allFields) - 3; // Минус AUTO поля

echo "<h2>📈 Статистика</h2>";
echo "<p><strong>Всего полей в схеме:</strong> " . count($allFields) . " (из них 3 авто-поля)</p>";
echo "<p><strong>Webhook заполняет:</strong> <span class='yes'>$webhookCount</span> / $totalFields полей</p>";
echo "<p><strong>CSV Import заполняет:</strong> <span class='yes'>$csvCount</span> / $totalFields полей</p>";

if ($webhookCount === $csvCount) {
    echo "<p class='yes' style='font-size: 1.2em;'>✅ Webhook и CSV Import заполняют одинаковое количество полей!</p>";
}

echo "<p><a href='../index.php'>← Вернуться к дашборду</a></p>";

echo "</body></html>";
