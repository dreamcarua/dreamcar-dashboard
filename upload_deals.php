<?php
// === upload_deals.php ===
// НАЗНАЧЕНИЕ: Интерфейс для загрузки данных о сделках из CSV в MySQL
// СВЯЗИ: config/app_config.php, core/Database.php, core/models/CrmDeal.php
// ОБНОВЛЕНО: 2025-01-24 - MySQL версия

// Увеличить лимиты для больших файлов
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

require_once 'config/app_config.php';
require_once 'core/Database.php';
require_once 'core/models/CrmDeal.php';
require_once 'core/Logger.php';
require_once 'core/Auth.php';
require_once 'core/Session.php';
require_once 'finance/core/models/FinanceTransaction.php';

// Тільки для адміністраторів!
Auth::requireAdmin();

$logger = new Logger();
$message = '';
$messageType = '';

// Обработка очистки базы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_database') {
    $logger->warning('Начало очистки базы данных');

    try {
        $db = Database::getInstance();
        $db->execute("TRUNCATE TABLE crm_deals");

        $logger->success('База данных очищена');
        $message = '✅ Таблица crm_deals успешно очищена! Теперь можно загрузить данные с нуля.';
        $messageType = 'success';
    } catch (Exception $e) {
        $logger->error('Ошибка очистки базы', ['error' => $e->getMessage()]);
        $message = '❌ Ошибка: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Обработка загрузки файла
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['deals_file'])) {
    $logger->info('Начало загрузки файла со сделками');

    $file = $_FILES['deals_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmpName = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Проверка расширения
        if (!in_array($fileExt, ['csv', 'xlsx', 'xls'])) {
            $message = '❌ Неподдерживаемый формат файла. Используйте CSV или Excel (.xlsx, .xls)';
            $messageType = 'error';
        } else {
            try {
                // Парсить файл
                $parseResult = parseDealsFile($tmpName, $fileExt);
                $deals = $parseResult['deals'];
                $parseStats = $parseResult['stats'];

                if (empty($deals)) {
                    $message = '⚠️ Файл пуст или не удалось распарсить данные<br>';
                    $message .= 'Всего строк в файле: ' . $parseStats['total_rows'] . '<br>';
                    $message .= 'Обработано: ' . $parseStats['processed'] . '<br>';
                    $message .= 'Пропущено: ' . $parseStats['skipped'];
                    $messageType = 'warning';
                } else {
                    // Загрузить в MySQL через CrmDeal::batchUpsert
                    $stats = CrmDeal::batchUpsert($deals, 500);

                    // Статистика по типам сделок + автосинхронизация в finance
                    $typeStats = [
                        'paid' => 0,
                        'failed' => 0,
                        'pending' => 0,
                        'leads' => 0,
                        'paid_amount' => 0,
                        'failed_amount' => 0,
                        'pending_amount' => 0,
                        'finance_synced' => 0,  // создано новых finance транзакций
                        'finance_errors' => 0,  // сколько сделок не удалось синкнуть
                    ];

                    foreach ($deals as $deal) {
                        if ($deal['is_paid']) {
                            $typeStats['paid']++;
                            $typeStats['paid_amount'] += $deal['amount_uah'];

                            // Автосинхронизация оплаченных сделок в finance_transactions
                            // createFromCrm сам защищён от дублей и НЕ бросает исключение
                            // при отсутствии проекта — просто вернёт 0.
                            if (!empty($deal['deal_id']) && $deal['amount_uah'] > 0) {
                                try {
                                    $txId = FinanceTransaction::createFromCrm([
                                        'deal_id'      => $deal['deal_id'],
                                        'amount_uah'   => $deal['amount_uah'],
                                        'deal_project' => $deal['deal_project'] ?? '',
                                        'created_at'   => $deal['created_at'] ?? date('Y-m-d H:i:s'),
                                    ]);
                                    if ($txId > 0) {
                                        $typeStats['finance_synced']++;
                                    } else {
                                        $typeStats['finance_errors']++;
                                    }
                                } catch (Throwable $finTxErr) {
                                    $typeStats['finance_errors']++;
                                    error_log('[upload_deals] Finance sync failed for deal_id='
                                        . $deal['deal_id'] . ': ' . $finTxErr->getMessage());
                                }
                            }
                        } elseif ($deal['is_failed']) {
                            $typeStats['failed']++;
                            $typeStats['failed_amount'] += $deal['amount_uah'];
                        } elseif ($deal['is_pending']) {
                            $typeStats['pending']++;
                            $typeStats['pending_amount'] += $deal['amount_uah'];
                        } else {
                            $typeStats['leads']++;
                        }
                    }

                    $logger->success('Сделки загружены в MySQL', array_merge($stats, $typeStats, $parseStats));

                    $message = '✅ Успешно обработано ' . count($deals) . ' сделок из ' . $parseStats['total_rows'] . ' строк<br>';

                    if ($parseStats['skipped'] > 0) {
                        $message .= '<br><strong>📋 Статистика парсинга:</strong><br>';
                        $message .= '✅ Распарсено: ' . $parseStats['processed'] . '<br>';
                        $message .= '⏭️ Пропущено: ' . $parseStats['skipped'] . '<br>';
                    }

                    $message .= '<br><strong>📊 По типам сделок:</strong><br>';
                    $message .= '💰 Оплачено: ' . $typeStats['paid'] . ' (' . number_format($typeStats['paid_amount'], 2) . ' UAH)<br>';
                    $message .= '❌ Неуспешно: ' . $typeStats['failed'] . ' (' . number_format($typeStats['failed_amount'], 2) . ' UAH)<br>';
                    $message .= '⏳ В процессе: ' . $typeStats['pending'] . ' (' . number_format($typeStats['pending_amount'], 2) . ' UAH)<br>';
                    $message .= '👥 Лиды: ' . $typeStats['leads'] . '<br>';

                    $message .= '<br><strong>💾 Загрузка в MySQL:</strong><br>';
                    $message .= '🆕 Новых: ' . $stats['new'] . '<br>';
                    $message .= '🔄 Обновлено: ' . $stats['updated'] . '<br>';
                    $message .= '📊 Всего в БД: ' . CrmDeal::count() . '<br>';

                    $message .= '<br><strong>💼 Синхронизация с финансами:</strong><br>';
                    $message .= '✅ Создано транзакций: ' . $typeStats['finance_synced'] . '<br>';
                    if ($typeStats['finance_errors'] > 0) {
                        $message .= '⚠️ Не удалось синхронизировать: ' . $typeStats['finance_errors']
                            . ' (обычно — проект не найден в finance_projects)<br>';
                    }

                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $logger->error('Ошибка загрузки сделок', ['error' => $e->getMessage()]);
                $message = '❌ Ошибка: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } else {
        $message = '❌ Ошибка загрузки файла';
        $messageType = 'error';
    }
}

/**
 * Парсить файл со сделками
 */
function parseDealsFile($filePath, $ext) {
    $deals = [];

    if ($ext === 'csv') {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception('Не удалось открыть файл');
        }

        // Определить разделитель
        $delimiter = "\t";
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');

        if (!$headers || count($headers) < 5) {
            rewind($handle);
            $delimiter = ',';
            $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        }

        if (!$headers || count($headers) < 5) {
            rewind($handle);
            $delimiter = ';';
            $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        }

        if (!$headers || count($headers) < 5) {
            fclose($handle);
            throw new Exception('Не удалось определить формат CSV');
        }

        $headers = array_map('trim', $headers);

        $rowNum = 1;
        $processed = 0;
        $skipped = 0;
        $fileDealIds = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rowNum++;

            if (empty(array_filter($row))) {
                continue;
            }

            if (count($row) < count($headers)) {
                $skipped++;
                continue;
            }

            $deal = [];
            foreach ($headers as $index => $header) {
                $deal[$header] = isset($row[$index]) ? trim($row[$index]) : '';
            }

            // deal_id ВСЕГДА берется из первой колонки CSV (индекс 0)
            $dealId = !empty($row[0]) ? trim($row[0]) : null;
            if ($dealId && isset($fileDealIds[$dealId])) {
                $skipped++;
                continue;
            }

            $converted = convertDealToUTMFormat($deal, $dealId);
            if ($converted) {
                $deals[] = $converted;
                $processed++;

                if ($dealId) {
                    $fileDealIds[$dealId] = true;
                }
            } else {
                $skipped++;
            }
        }

        fclose($handle);

        return [
            'deals' => $deals,
            'stats' => [
                'total_rows' => $rowNum - 1,
                'processed' => $processed,
                'skipped' => $skipped
            ]
        ];
    } else {
        throw new Exception('Excel файлы пока не поддерживаются. Конвертируйте в CSV');
    }
}

/**
 * Преобразовать сделку в формат для MySQL
 */
function convertDealToUTMFormat($deal, $dealId = null) {
    // Email - сохраняем все через ; как в CSV
    $email = !empty($deal['contact_1_emails']) ? trim($deal['contact_1_emails']) : '';

    // Телефон - сохраняем все через ; как в CSV
    $phone = !empty($deal['contact_1_phones']) ? trim($deal['contact_1_phones']) : '';

    // Сумма
    $amountUAH = !empty($deal['deal_price']) ? floatval($deal['deal_price']) : 0;

    // Определить статус
    $pipeline = !empty($deal['deal_pipeline']) ? trim($deal['deal_pipeline']) : '';
    $hasTickets = !empty($deal['НОМЕРИ КВИТКІВ!']) ||
                  (!empty($deal['biletov']) && intval($deal['biletov']) > 0);

    $dealType = 'lead';
    $isPaid = false;
    $isFailed = false;
    $isPending = false;

    if ($pipeline === 'default_step_done') {
        $dealType = 'paid';
        $isPaid = true;
    } elseif ($pipeline === 'test' && $hasTickets) {
        $dealType = 'paid';
        $isPaid = true;
    } elseif ($hasTickets && empty($pipeline)) {
        $dealType = 'paid';
        $isPaid = true;
    } elseif ($pipeline === 'default_step_in_progress') {
        $dealType = 'failed';
        $isFailed = true;
    } elseif ($pipeline === 'default_step_new') {
        $dealType = 'pending';
        $isPending = true;
    }

    $createdAt = !empty($deal['deal_created_at']) ? trim($deal['deal_created_at']) : date('Y-m-d H:i:s');
    if (strpos($createdAt, ' ') === false) {
        $createdAt .= ' 00:00:00';
    }

    return [
        'deal_id' => $dealId, // deal_id берется из первой колонки CSV
        'contact_id' => !empty($deal['contact_id']) ? intval($deal['contact_id']) : null,
        'email' => strtolower($email),
        'phone' => $phone,
        'full_name' => !empty($deal['contact_1_fullName']) ? $deal['contact_1_fullName'] : null,
        'created_at' => $createdAt,
        'deal_updated_at' => !empty($deal['deal_updated_at']) ? trim($deal['deal_updated_at']) : null,
        'amount' => $amountUAH,
        'amount_uah' => $amountUAH,
        'deal_price' => $amountUAH,
        'deal_currency' => !empty($deal['deal_currency']) ? $deal['deal_currency'] : 'UAH',
        'utm_source' => !empty($deal['utm_source']) ? strtolower(trim($deal['utm_source'])) : null,
        'utm_medium' => !empty($deal['utm_medium']) ? strtolower(trim($deal['utm_medium'])) : null,
        'utm_campaign' => !empty($deal['utm_campaign']) ? strtolower(trim($deal['utm_campaign'])) : null,
        'utm_term' => !empty($deal['utm_term']) ? strtolower(trim($deal['utm_term'])) : null,
        'utm_content' => !empty($deal['utm_content']) ? strtolower(trim($deal['utm_content'])) : null,
        'deal_pipeline' => $pipeline,
        'deal_type' => $dealType,
        'deal_status' => $isPaid ? 'paid' : ($isFailed ? 'failed' : ($isPending ? 'pending' : 'lead')),
        'is_paid' => $isPaid,
        'is_failed' => $isFailed,
        'is_pending' => $isPending,
        'deal_name' => !empty($deal['deal_name']) ? $deal['deal_name'] : null,
        'deal_step' => !empty($deal['deal_step']) ? $deal['deal_step'] : null,
        'model' => !empty($deal['deal_step']) ? $deal['deal_step'] : null, // deal_step = название проекта (VOLVO, OLD и т.д.)
        'comment' => null, // Комментарий (если есть в CSV можно добавить)
        'product' => !empty($deal['product']) ? $deal['product'] : null,
        'tickets' => !empty($deal['НОМЕРИ КВИТКІВ!']) ? $deal['НОМЕРИ КВИТКІВ!'] : null,
        'tickets_count' => !empty($deal['biletov']) ? intval($deal['biletov']) : 0,
        'list_name' => 'Deals',
        'tag_list' => 'deal'
    ];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📤 Загрузка сделок | UTM Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
    <style>
        .upload-container {
            max-width: 800px;
            margin: 4rem auto;
            padding: 2rem;
        }
        .upload-card {
            background: var(--gradient-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
        }
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-input-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            background: var(--ai-gray);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .file-input-label:hover {
            border-color: var(--ai-blue);
            background: rgba(59, 130, 246, 0.1);
        }
        .file-input-label.has-file {
            border-color: var(--ai-green);
            background: rgba(16, 185, 129, 0.1);
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .message.success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--ai-green);
            color: var(--ai-green);
        }
        .message.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--color-danger);
            color: var(--color-danger);
        }
        .message.warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid #f59e0b;
            color: #f59e0b;
        }
        .info-box {
            background: var(--ai-gray);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        .info-box ul {
            margin: 0.5rem 0;
            padding-left: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="upload-container">
            <div class="upload-card">
                <h1 class="gradient-text">📤 Загрузка сделок в MySQL</h1>
                <p class="text-muted">Загрузите CSV файл со сделками для импорта в базу данных</p>

                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="file-input-wrapper">
                        <input type="file" name="deals_file" id="dealsFile" class="file-input" accept=".csv,.xlsx,.xls" required>
                        <label for="dealsFile" class="file-input-label" id="fileLabel">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📁</div>
                            <div style="font-weight: 600; margin-bottom: 0.5rem;">Нажмите для выбора файла</div>
                            <div style="font-size: 0.875rem; color: var(--text-muted);">CSV (до 50MB)</div>
                            <div id="fileName" style="margin-top: 1rem; font-weight: 500; color: var(--ai-blue); display: none;"></div>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem; min-height: 60px;">
                        📤 Загрузить в MySQL
                    </button>
                </form>

                <div class="info-box">
                    <strong>📋 Формат CSV:</strong>
                    <ul>
                        <li>Разделитель: табуляция, запятая или точка с запятой</li>
                        <li>Обязательные поля: contact_1_emails или contact_1_phones</li>
                        <li>Уникальность: по deal_id (один email может иметь много сделок)</li>
                        <li>Поддержка больших файлов (30,000+ записей)</li>
                    </ul>
                    <strong>💾 MySQL:</strong> Данные сохраняются в таблицу crm_deals с автоматическим обновлением при повторной загрузке
                </div>

                <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border-color);">
                    <h3 style="color: var(--color-danger); margin-bottom: 0.5rem;">⚠️ Опасная зона</h3>
                    <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1rem;">
                        Полностью очистить таблицу crm_deals. Это действие нельзя отменить!
                    </p>
                    <form method="POST" onsubmit="return confirm('⚠️ ВНИМАНИЕ! Очистить таблицу crm_deals?\\n\\nВсе сделки будут удалены без возможности восстановления!');">
                        <input type="hidden" name="action" value="clear_database">
                        <button type="submit" class="btn" style="background: var(--color-danger); border-color: var(--color-danger); min-height: 54px;">
                            🗑️ Очистить базу данных
                        </button>
                    </form>
                </div>

                <div style="margin-top: 2rem; text-align: center;">
                    <a href="index.php" class="btn btn-outline">← Вернуться к дашборду</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('dealsFile').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const label = document.getElementById('fileLabel');
            const fileName = document.getElementById('fileName');

            if (file) {
                label.classList.add('has-file');
                fileName.textContent = '📄 ' + file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
                fileName.style.display = 'block';
            } else {
                label.classList.remove('has-file');
                fileName.style.display = 'none';
            }
        });
    </script>
</body>
</html>
