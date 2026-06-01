<?php
// ИНИЦИАЛИЗАЦИЯ ТЕСТОВЫХ ДАННЫХ
// Открой этот файл в браузере: http://dreamcar.ai-platform.space/volvo/dashboard/utm-dashboard/init_data.php

$dataDir = __DIR__ . '/data';

// Создать папку если не существует
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$sources = ['google', 'facebook', 'instagram', 'tiktok', 'youtube', 'direct', 'referral', 'linkedin', 'twitter'];
$mediums = ['cpc', 'organic', 'social', 'email', 'referral', 'direct', 'display', 'affiliate'];
$campaigns = ['summer_sale', 'black_friday', 'new_year', 'spring_promo', 'brand_awareness', 'retargeting', 'product_launch', 'seasonal_offer'];
$terms = ['volvo xc90', 'volvo s60', 'купить volvo', 'вольво официальный', 'volvo цена', 'volvo test drive', 'новый volvo'];
$contents = ['banner_1', 'banner_2', 'video_ad', 'carousel', 'story', 'post', 'ad_1', 'ad_2'];

$testData = [];

// Генерировать 1000 записей
for ($i = 1; $i <= 1000; $i++) {
    $daysAgo = rand(1, 60);
    $date = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));

    // Случайные суммы сделок (30-70% лидов имеют сумму)
    $hasAmount = rand(0, 100) > 35;
    $amount = $hasAmount ? rand(500, 15000) : 0;

    $testData[] = [
        'email' => 'user' . $i . '@example.com',
        'phone' => '+373' . str_pad(rand(60000000, 79999999), 8, '0', STR_PAD_LEFT),
        'created_at' => $date,
        'utm_source' => $sources[array_rand($sources)],
        'utm_medium' => $mediums[array_rand($mediums)],
        'utm_campaign' => $campaigns[array_rand($campaigns)],
        'utm_term' => rand(0, 100) > 40 ? $terms[array_rand($terms)] : null,
        'utm_content' => rand(0, 100) > 30 ? $contents[array_rand($contents)] : null,
        'amount' => $amount,
        'list_name' => 'Test List',
        'tag_list' => 'lead, active'
    ];
}

// Сохранить
$filePath = $dataDir . '/utm_clean.json';
file_put_contents(
    $filePath,
    json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Создать пустой лог
$logPath = __DIR__ . '/log_actual.json';
if (!file_exists($logPath)) {
    $logData = [
        'app_start' => date('Y-m-d H:i:s'),
        'events' => [
            [
                'time' => date('Y-m-d H:i:s'),
                'type' => 'success',
                'message' => 'Тестовые данные созданы',
                'data' => ['count' => count($testData)]
            ]
        ]
    ];
    file_put_contents($logPath, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Вывод
echo "<!DOCTYPE html>";
echo "<html><head><meta charset='UTF-8'><title>Инициализация данных</title>";
echo "<style>body{font-family:Arial;padding:40px;background:#0a0a0a;color:#fafafa;} h1{color:#3b82f6;} .success{color:#10b981;font-size:24px;} .info{color:#a3a3a3;margin:10px 0;} a{color:#3b82f6;text-decoration:none;padding:10px 20px;background:#171717;border-radius:8px;display:inline-block;margin-top:20px;}</style>";
echo "</head><body>";
echo "<h1>✅ Данные успешно созданы!</h1>";
echo "<div class='success'>Создано записей: " . count($testData) . "</div>";
echo "<div class='info'>📁 Файл: {$filePath}</div>";
echo "<div class='info'>📊 Источников: " . count($sources) . "</div>";
echo "<div class='info'>🔗 Типов трафика: " . count($mediums) . "</div>";
echo "<div class='info'>🎯 Кампаний: " . count($campaigns) . "</div>";
echo "<div class='info'>📅 Период: последние 60 дней</div>";
echo "<br><a href='index.php'>🚀 Перейти к дашборду</a>";
echo "</body></html>";
?>
