<?php
/**
 * 🔬 ПОВНА ДІАГНОСТИКА СИСТЕМИ MAPPINGS
 * Перевіряє ВСІ розділи dashboard з фільтром utm_term
 */

require_once 'config/app_config.php';
require_once 'core/Database.php';
require_once 'core/models/Analytics.php';
require_once 'core/models/UtmCrmAdsMapping.php';

echo "<h1>🔬 ПОВНА ДІАГНОСТИКА СИСТЕМИ CRM-ADS MAPPINGS</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
h2 { background: #3b82f6; color: white; padding: 10px; margin-top: 30px; }
h3 { background: #6366f1; color: white; padding: 8px; margin-top: 20px; }
table { border-collapse: collapse; width: 100%; margin: 15px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #3b82f6; color: white; position: sticky; top: 0; }
.success { background: #d1fae5; border-left: 4px solid #10b981; padding: 10px; margin: 10px 0; }
.error { background: #fee2e2; border-left: 4px solid #ef4444; padding: 10px; margin: 10px 0; }
.warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px; margin: 10px 0; }
pre { background: #f5f5f5; padding: 10px; border-left: 3px solid #6366f1; overflow-x: auto; }
.test-section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>";

$yesterday = date('Y-m-d', strtotime('-1 day'));
$testUtmTerm = 'oborotfb';

$filters = [
    'date_from' => $yesterday . ' 00:00:00',
    'date_to' => $yesterday . ' 23:59:59',
    'utm_term' => $testUtmTerm
];

echo "<div class='test-section'>";
echo "<h2>📋 КОНФІГУРАЦІЯ ТЕСТУ</h2>";
echo "<table>";
echo "<tr><th>Параметр</th><th>Значення</th></tr>";
echo "<tr><td>Дата</td><td><strong>$yesterday</strong></td></tr>";
echo "<tr><td>Тестовий utm_term</td><td><strong>$testUtmTerm</strong></td></tr>";
echo "</table>";
echo "</div>";

// ============================================
// КРОК 1: ПЕРЕВІРКА MAPPINGS В БД
// ============================================

echo "<div class='test-section'>";
echo "<h2>🗄️ КРОК 1: Перевірка mappings в БД</h2>";

try {
    $mappings = UtmCrmAdsMapping::getMappingsByField('utm_term');

    echo "<table>";
    echo "<tr><th>#</th><th>CRM value</th><th>ADS value</th><th>Merged name</th><th>Notes</th></tr>";

    $foundTestMapping = false;
    foreach ($mappings as $i => $map) {
        $isTest = (strpos(strtolower($map['crm_value']), strtolower($testUtmTerm)) !== false);
        $rowClass = $isTest ? "style='background: #d1fae5;'" : "";

        echo "<tr $rowClass>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td><strong>{$map['crm_value']}</strong></td>";
        echo "<td><code>{$map['ads_value']}</code></td>";
        echo "<td>{$map['merged_name']}</td>";
        echo "<td>" . ($map['notes'] ?: '—') . "</td>";
        echo "</tr>";

        if ($isTest) $foundTestMapping = true;
    }
    echo "</table>";

    if ($foundTestMapping) {
        echo "<div class='success'>✅ Знайдено mapping для <strong>$testUtmTerm</strong></div>";
    } else {
        echo "<div class='error'>❌ НЕ знайдено mapping для <strong>$testUtmTerm</strong></div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 2: ТЕСТ getTotalStats()
// ============================================

echo "<div class='test-section'>";
echo "<h2>📊 КРОК 2: Analytics::getTotalStats() - OVERVIEW</h2>";

try {
    $totalStats = Analytics::getTotalStats($filters);

    echo "<table>";
    echo "<tr><th>Метрика</th><th>Значення</th><th>Статус</th></tr>";
    echo "<tr><td>Всього лідів</td><td><strong>{$totalStats['total_leads']}</strong></td><td>✅</td></tr>";
    echo "<tr><td>Оплачено</td><td><strong>{$totalStats['paid_count']}</strong></td><td>✅</td></tr>";
    echo "<tr><td>Заробили</td><td><strong>" . number_format($totalStats['paid_amount'], 0) . " UAH</strong></td><td>✅</td></tr>";

    $spendStatus = $totalStats['total_spend'] > 0 ? "✅" : "❌";
    $spendClass = $totalStats['total_spend'] > 0 ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";
    echo "<tr $spendClass><td><strong>💸 Витрати (ADS)</strong></td><td><strong>" . number_format($totalStats['total_spend'], 2) . " UAH</strong></td><td>$spendStatus</td></tr>";

    echo "<tr><td>Прибуток</td><td><strong>" . number_format($totalStats['total_profit'], 0) . " UAH</strong></td><td>✅</td></tr>";
    echo "<tr><td>ROI</td><td><strong>+" . number_format($totalStats['avg_roi'], 1) . "%</strong></td><td>✅</td></tr>";
    echo "<tr><td>ROAS</td><td><strong>" . number_format($totalStats['avg_roas'], 2) . "</strong></td><td>✅</td></tr>";
    echo "</table>";

    if ($totalStats['total_spend'] > 0) {
        echo "<div class='success'>✅ <strong>OVERVIEW ПРАЦЮЄ!</strong> Витрати знайдені через mappings!</div>";
    } else {
        echo "<div class='error'>❌ <strong>OVERVIEW НЕ ПРАЦЮЄ!</strong> Витрати = 0!</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 3: ТЕСТ getByTerm()
// ============================================

echo "<div class='test-section'>";
echo "<h2>🔑 КРОК 3: Analytics::getByTerm() - ИСПОЛНИТЕЛЬ</h2>";

try {
    $termData = Analytics::getByTerm($filters);

    echo "<p>Отримано записів: <strong>" . count($termData) . "</strong></p>";

    echo "<table>";
    echo "<tr><th>UTM Term</th><th>Source</th><th>Лiди</th><th>Оплачено</th><th>Заробили</th><th>Витрати</th><th>Прибуток</th><th>ROI</th><th>Статус</th></tr>";

    $hasSpend = false;
    foreach ($termData as $row) {
        $spendStatus = $row['spend'] > 0 ? "✅" : "❌";
        $rowClass = $row['spend'] > 0 ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";

        echo "<tr $rowClass>";
        echo "<td><strong>" . htmlspecialchars(substr($row['utm_term'], 0, 80)) . "</strong></td>";
        echo "<td><code>{$row['source']}</code></td>";
        echo "<td>{$row['leads']}</td>";
        echo "<td>{$row['paid_count']}</td>";
        echo "<td>" . number_format($row['paid_amount'], 0) . " UAH</td>";
        echo "<td><strong>" . number_format($row['spend'], 0) . " UAH</strong></td>";
        echo "<td>" . number_format($row['profit'], 0) . " UAH</td>";
        echo "<td>+" . number_format($row['roi'], 1) . "%</td>";
        echo "<td>$spendStatus</td>";
        echo "</tr>";

        if ($row['spend'] > 0) $hasSpend = true;
    }
    echo "</table>";

    if ($hasSpend) {
        echo "<div class='success'>✅ <strong>ИСПОЛНИТЕЛЬ ПРАЦЮЄ!</strong> Витрати знайдені!</div>";
    } else {
        echo "<div class='error'>❌ <strong>ИСПОЛНИТЕЛЬ НЕ ПРАЦЮЄ!</strong> Всі витрати = 0!</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 4: ТЕСТ getByCampaign()
// ============================================

echo "<div class='test-section'>";
echo "<h2>🎯 КРОК 4: Analytics::getByCampaign() - КАМПАНИИ</h2>";

try {
    $campaignData = Analytics::getByCampaign($filters);

    echo "<p>Отримано кампаній: <strong>" . count($campaignData) . "</strong></p>";

    echo "<table>";
    echo "<tr><th>Campaign</th><th>Source</th><th>Лiди</th><th>Оплачено</th><th>Заробили</th><th>Витрати</th><th>ROI</th><th>Статус</th></tr>";

    $hasSpend = false;
    foreach (array_slice($campaignData, 0, 10) as $row) {
        $spendStatus = $row['spend'] > 0 ? "✅" : "❌";
        $rowClass = $row['spend'] > 0 ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";

        echo "<tr $rowClass>";
        echo "<td><strong>" . htmlspecialchars(substr($row['utm_campaign'], 0, 50)) . "</strong></td>";
        echo "<td><code>{$row['source']}</code></td>";
        echo "<td>{$row['leads']}</td>";
        echo "<td>{$row['paid_count']}</td>";
        echo "<td>" . number_format($row['paid_amount'], 0) . " UAH</td>";
        echo "<td><strong>" . number_format($row['spend'], 0) . " UAH</strong></td>";
        echo "<td>+" . number_format($row['roi'], 1) . "%</td>";
        echo "<td>$spendStatus</td>";
        echo "</tr>";

        if ($row['spend'] > 0) $hasSpend = true;
    }
    echo "</table>";

    if ($hasSpend) {
        echo "<div class='success'>✅ <strong>КАМПАНИИ ПРАЦЮЮТЬ!</strong> Витрати знайдені!</div>";
    } else {
        echo "<div class='error'>❌ <strong>КАМПАНИИ НЕ ПРАЦЮЮТЬ!</strong> Всі витрати = 0!</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 5: ТЕСТ getBySource()
// ============================================

echo "<div class='test-section'>";
echo "<h2>📍 КРОК 5: Analytics::getBySource() - ИСТОЧНИКИ</h2>";

try {
    $sourceData = Analytics::getBySource($filters);

    echo "<p>Отримано джерел: <strong>" . count($sourceData) . "</strong></p>";

    echo "<table>";
    echo "<tr><th>Source</th><th>Лiди</th><th>Оплачено</th><th>Заробили</th><th>Витрати</th><th>ROI</th><th>Статус</th></tr>";

    $hasSpend = false;
    foreach ($sourceData as $row) {
        $spendStatus = $row['spend'] > 0 ? "✅" : "❌";
        $rowClass = $row['spend'] > 0 ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";

        echo "<tr $rowClass>";
        echo "<td><strong>{$row['utm_source']}</strong></td>";
        echo "<td>{$row['leads']}</td>";
        echo "<td>{$row['paid_count']}</td>";
        echo "<td>" . number_format($row['paid_amount'], 0) . " UAH</td>";
        echo "<td><strong>" . number_format($row['spend'], 0) . " UAH</strong></td>";
        echo "<td>+" . number_format($row['roi'], 1) . "%</td>";
        echo "<td>$spendStatus</td>";
        echo "</tr>";

        if ($row['spend'] > 0) $hasSpend = true;
    }
    echo "</table>";

    if ($hasSpend) {
        echo "<div class='success'>✅ <strong>ИСТОЧНИКИ ПРАЦЮЮТЬ!</strong></div>";
    } else {
        echo "<div class='error'>❌ <strong>ИСТОЧНИКИ НЕ ПРАЦЮЮТЬ!</strong></div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 6: ТЕСТ getByMedium()
// ============================================

echo "<div class='test-section'>";
echo "<h2>🔗 КРОК 6: Analytics::getByMedium() - ТИП ТРАФИКА</h2>";

try {
    $mediumData = Analytics::getByMedium($filters);

    echo "<p>Отримано типів: <strong>" . count($mediumData) . "</strong></p>";

    echo "<table>";
    echo "<tr><th>Medium</th><th>Лiди</th><th>Оплачено</th><th>Витрати</th><th>ROI</th><th>Статус</th></tr>";

    $hasSpend = false;
    foreach (array_slice($mediumData, 0, 10) as $row) {
        $spendStatus = $row['spend'] > 0 ? "✅" : "❌";
        $rowClass = $row['spend'] > 0 ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";

        echo "<tr $rowClass>";
        echo "<td><strong>{$row['utm_medium']}</strong></td>";
        echo "<td>{$row['leads']}</td>";
        echo "<td>{$row['paid_count']}</td>";
        echo "<td><strong>" . number_format($row['spend'], 0) . " UAH</strong></td>";
        echo "<td>+" . number_format($row['roi'], 1) . "%</td>";
        echo "<td>$spendStatus</td>";
        echo "</tr>";

        if ($row['spend'] > 0) $hasSpend = true;
    }
    echo "</table>";

    if ($hasSpend) {
        echo "<div class='success'>✅ <strong>ТИП ТРАФИКА ПРАЦЮЄ!</strong></div>";
    } else {
        echo "<div class='error'>❌ <strong>ТИП ТРАФИКА НЕ ПРАЦЮЄ!</strong></div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 7: ТЕСТ getByContent()
// ============================================

echo "<div class='test-section'>";
echo "<h2>🎨 КРОК 7: Analytics::getByContent() - ОБЪЯВЛЕНИЯ</h2>";

try {
    $contentData = Analytics::getByContent($filters);

    echo "<p>Отримано контенту: <strong>" . count($contentData) . "</strong></p>";

    echo "<table>";
    echo "<tr><th>Content</th><th>Лiди</th><th>Оплачено</th><th>Витрати</th><th>ROI</th><th>Статус</th></tr>";

    $hasSpend = false;
    foreach (array_slice($contentData, 0, 10) as $row) {
        $spendStatus = $row['spend'] > 0 ? "✅" : "❌";
        $rowClass = $row['spend'] > 0 ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";

        echo "<tr $rowClass>";
        echo "<td><strong>" . htmlspecialchars(substr($row['utm_content'], 0, 40)) . "</strong></td>";
        echo "<td>{$row['leads']}</td>";
        echo "<td>{$row['paid_count']}</td>";
        echo "<td><strong>" . number_format($row['spend'], 0) . " UAH</strong></td>";
        echo "<td>+" . number_format($row['roi'], 1) . "%</td>";
        echo "<td>$spendStatus</td>";
        echo "</tr>";

        if ($row['spend'] > 0) $hasSpend = true;
    }
    echo "</table>";

    if ($hasSpend) {
        echo "<div class='success'>✅ <strong>ОБЪЯВЛЕНИЯ ПРАЦЮЮТЬ!</strong></div>";
    } else {
        echo "<div class='error'>❌ <strong>ОБЪЯВЛЕНИЯ НЕ ПРАЦЮЮТЬ!</strong></div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 8: ПОРІВНЯННЯ БЕЗ ФІЛЬТРУ vs З ФІЛЬТРОМ
// ============================================

echo "<div class='test-section'>";
echo "<h2>⚖️ КРОК 8: Порівняння БЕЗ фільтру vs З фільтром</h2>";

$filtersNoTerm = [
    'date_from' => $yesterday . ' 00:00:00',
    'date_to' => $yesterday . ' 23:59:59'
];

try {
    $statsNoFilter = Analytics::getTotalStats($filtersNoTerm);
    $statsWithFilter = Analytics::getTotalStats($filters);

    echo "<table>";
    echo "<tr><th>Метрика</th><th>БЕЗ фільтру</th><th>З utm_term=$testUtmTerm</th><th>Різниця</th></tr>";

    echo "<tr>";
    echo "<td>Лiди</td>";
    echo "<td>{$statsNoFilter['total_leads']}</td>";
    echo "<td>{$statsWithFilter['total_leads']}</td>";
    echo "<td>" . ($statsNoFilter['total_leads'] - $statsWithFilter['total_leads']) . "</td>";
    echo "</tr>";

    echo "<tr>";
    echo "<td><strong>Витрати</strong></td>";
    echo "<td>" . number_format($statsNoFilter['total_spend'], 0) . " UAH</td>";
    echo "<td><strong>" . number_format($statsWithFilter['total_spend'], 0) . " UAH</strong></td>";
    echo "<td>" . number_format($statsNoFilter['total_spend'] - $statsWithFilter['total_spend'], 0) . " UAH</td>";
    echo "</tr>";

    $percentage = $statsNoFilter['total_spend'] > 0
        ? round(($statsWithFilter['total_spend'] / $statsNoFilter['total_spend']) * 100, 1)
        : 0;

    echo "<tr style='background: #e0f2fe;'>";
    echo "<td><strong>% від загальних витрат</strong></td>";
    echo "<td colspan='3'><strong>$percentage%</strong> витрат належить $testUtmTerm</td>";
    echo "</tr>";

    echo "</table>";

    if ($statsWithFilter['total_spend'] > 0 && $statsWithFilter['total_spend'] < $statsNoFilter['total_spend']) {
        echo "<div class='success'>✅ <strong>ФІЛЬТР ПРАЦЮЄ ПРАВИЛЬНО!</strong> Витрати з фільтром менші ніж загальні.</div>";
    } else {
        echo "<div class='warning'>⚠️ Перевірте логіку фільтрації!</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>❌ Помилка: " . $e->getMessage() . "</div>";
}

echo "</div>";

// ============================================
// КРОК 9: ТЕСТ РІЗНИХ utm_term
// ============================================

echo "<div class='test-section'>";
echo "<h2>🧪 КРОК 9: Тест РІЗНИХ utm_term (vadym, vira, artem)</h2>";

$testTerms = ['vadym', 'vira', 'artem', 'oborotfb'];

echo "<table>";
echo "<tr><th>utm_term</th><th>Лiди</th><th>Витрати</th><th>ROI</th><th>Статус</th></tr>";

foreach ($testTerms as $term) {
    $testFilters = [
        'date_from' => $yesterday . ' 00:00:00',
        'date_to' => $yesterday . ' 23:59:59',
        'utm_term' => $term
    ];

    try {
        $stats = Analytics::getTotalStats($testFilters);

        $spendStatus = $stats['total_spend'] > 0 ? "✅ Працює" : "❌ Не працює";
        $rowClass = $stats['total_spend'] > 0 ? "style='background: #d1fae5;'" : "style='background: #fee2e2;'";

        echo "<tr $rowClass>";
        echo "<td><strong>$term</strong></td>";
        echo "<td>{$stats['total_leads']}</td>";
        echo "<td><strong>" . number_format($stats['total_spend'], 0) . " UAH</strong></td>";
        echo "<td>+" . number_format($stats['avg_roi'], 1) . "%</td>";
        echo "<td>$spendStatus</td>";
        echo "</tr>";

    } catch (Exception $e) {
        echo "<tr style='background: #fee2e2;'>";
        echo "<td><strong>$term</strong></td>";
        echo "<td colspan='4'>❌ Помилка: " . $e->getMessage() . "</td>";
        echo "</tr>";
    }
}

echo "</table>";

echo "</div>";

// ============================================
// ПІДСУМОК
// ============================================

echo "<div class='test-section' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;'>";
echo "<h2 style='background: transparent; color: white;'>🎯 ПІДСУМОК ДІАГНОСТИКИ</h2>";

$allPassed = true;
$results = [];

// Перевірка кожного компонента
try {
    $totalStats = Analytics::getTotalStats($filters);
    $results['Overview'] = $totalStats['total_spend'] > 0 ? '✅ ПРАЦЮЄ' : '❌ НЕ ПРАЦЮЄ';
    if ($totalStats['total_spend'] == 0) $allPassed = false;
} catch (Exception $e) {
    $results['Overview'] = '❌ ПОМИЛКА';
    $allPassed = false;
}

try {
    $termData = Analytics::getByTerm($filters);
    $hasSpend = false;
    foreach ($termData as $row) {
        if ($row['spend'] > 0) { $hasSpend = true; break; }
    }
    $results['Исполнитель'] = $hasSpend ? '✅ ПРАЦЮЄ' : '❌ НЕ ПРАЦЮЄ';
    if (!$hasSpend) $allPassed = false;
} catch (Exception $e) {
    $results['Исполнитель'] = '❌ ПОМИЛКА';
    $allPassed = false;
}

try {
    $campaignData = Analytics::getByCampaign($filters);
    $hasSpend = false;
    foreach ($campaignData as $row) {
        if ($row['spend'] > 0) { $hasSpend = true; break; }
    }
    $results['Кампании'] = $hasSpend ? '✅ ПРАЦЮЄ' : '❌ НЕ ПРАЦЮЄ';
    if (!$hasSpend) $allPassed = false;
} catch (Exception $e) {
    $results['Кампании'] = '❌ ПОМИЛКА';
    $allPassed = false;
}

try {
    $sourceData = Analytics::getBySource($filters);
    $hasSpend = false;
    foreach ($sourceData as $row) {
        if ($row['spend'] > 0) { $hasSpend = true; break; }
    }
    $results['Источники'] = $hasSpend ? '✅ ПРАЦЮЄ' : '❌ НЕ ПРАЦЮЄ';
    if (!$hasSpend) $allPassed = false;
} catch (Exception $e) {
    $results['Источники'] = '❌ ПОМИЛКА';
    $allPassed = false;
}

try {
    $mediumData = Analytics::getByMedium($filters);
    $hasSpend = false;
    foreach ($mediumData as $row) {
        if ($row['spend'] > 0) { $hasSpend = true; break; }
    }
    $results['Тип трафика'] = $hasSpend ? '✅ ПРАЦЮЄ' : '❌ НЕ ПРАЦЮЄ';
    if (!$hasSpend) $allPassed = false;
} catch (Exception $e) {
    $results['Тип трафика'] = '❌ ПОМИЛКА';
    $allPassed = false;
}

try {
    $contentData = Analytics::getByContent($filters);
    $hasSpend = false;
    foreach ($contentData as $row) {
        if ($row['spend'] > 0) { $hasSpend = true; break; }
    }
    $results['Объявления'] = $hasSpend ? '✅ ПРАЦЮЄ' : '❌ НЕ ПРАЦЮЄ';
    if (!$hasSpend) $allPassed = false;
} catch (Exception $e) {
    $results['Объявления'] = '❌ ПОМИЛКА';
    $allPassed = false;
}

echo "<table style='border: 2px solid white;'>";
echo "<tr style='background: rgba(255,255,255,0.2);'><th style='color: white; background: transparent;'>Розділ Dashboard</th><th style='color: white; background: transparent;'>Статус</th></tr>";

foreach ($results as $section => $status) {
    echo "<tr style='background: rgba(255,255,255,0.1);'>";
    echo "<td style='color: white; border-color: rgba(255,255,255,0.3);'><strong>$section</strong></td>";
    echo "<td style='color: white; border-color: rgba(255,255,255,0.3);'>$status</td>";
    echo "</tr>";
}

echo "</table>";

if ($allPassed) {
    echo "<div style='background: #10b981; padding: 20px; margin-top: 20px; border-radius: 8px; text-align: center;'>";
    echo "<h2 style='margin: 0; background: transparent; color: white;'>🎉 ВСІ ТЕСТИ ПРОЙДЕНІ!</h2>";
    echo "<p style='margin: 10px 0 0 0; font-size: 18px;'>Система CRM-ADS mappings працює повністю коректно!</p>";
    echo "</div>";
} else {
    echo "<div style='background: #ef4444; padding: 20px; margin-top: 20px; border-radius: 8px; text-align: center;'>";
    echo "<h2 style='margin: 0; background: transparent; color: white;'>❌ ЗНАЙДЕНІ ПРОБЛЕМИ!</h2>";
    echo "<p style='margin: 10px 0 0 0; font-size: 18px;'>Деякі розділи не знаходять витрати через mappings</p>";
    echo "</div>";
}

echo "</div>";

echo "<hr>";
echo "<p style='text-align: center; margin-top: 30px;'>";
echo "<a href='index.php?date_range=yesterday&utm_term=$testUtmTerm' style='padding: 15px 30px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-size: 18px; font-weight: bold;'>→ Перейти до Dashboard</a>";
echo "</p>";
?>
