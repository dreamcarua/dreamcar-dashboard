<?php
// Генератор тестовых данных
require_once 'config/app_config.php';

$sources = ['google', 'facebook', 'instagram', 'tiktok', 'youtube', 'direct', 'referral', 'linkedin', 'twitter'];
$mediums = ['cpc', 'organic', 'social', 'email', 'referral', 'direct', 'display', 'affiliate'];
$campaigns = ['summer_sale', 'black_friday', 'new_year', 'spring_promo', 'brand_awareness', 'retargeting', 'product_launch', 'seasonal_offer'];
$terms = ['volvo xc90', 'volvo s60', 'купить volvo', 'вольво официальный', 'volvo цена', 'volvo test drive', 'новый volvo'];
$contents = ['banner_1', 'banner_2', 'video_ad', 'carousel', 'story', 'post', 'ad_1', 'ad_2'];

$testData = [];

// Генерировать 1000 записей
for ($i = 1; $i <= 1000; $i++) {
    $date = date('Y-m-d H:i:s', strtotime('-' . rand(1, 60) . ' days'));

    $testData[] = [
        'email' => 'user' . $i . '@example.com',
        'phone' => '+373' . rand(60000000, 79999999),
        'created_at' => $date,
        'utm_source' => $sources[array_rand($sources)],
        'utm_medium' => $mediums[array_rand($mediums)],
        'utm_campaign' => $campaigns[array_rand($campaigns)],
        'utm_term' => rand(0, 100) > 40 ? $terms[array_rand($terms)] : null,
        'utm_content' => rand(0, 100) > 30 ? $contents[array_rand($contents)] : null,
        'amount' => rand(0, 100) > 35 ? round(rand(500, 15000) / 100, 2) : round(rand(100, 50000) / 100, 2),
        'list_name' => 'Test List',
        'tag_list' => 'lead, active'
    ];
}

// Сохранить
saveJSON(UTM_CLEAN_FILE, $testData);

echo "✅ Создано записей: " . count($testData) . "\n";
echo "📁 Файл: " . UTM_CLEAN_FILE . "\n";
echo "✅ Готово!\n";
