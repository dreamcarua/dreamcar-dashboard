<?php
// === GIT_SERVER_SYNC.PHP ===
// cron/git_server_sync.php
// НАЗНАЧЕНИЕ: Supervisor-демон - проверяет каждые 5 сек изменения на сервере,
//             генерирует коммит через OpenAI, пушит в GitHub
// СВЯЗИ: config.php или config/app_config.php (через Safe Config Adapter)
// РАЗМЕР: ~480 строк

// Только CLI
if (php_sapi_name() !== 'cli') {
    die('This script must run in CLI mode (Supervisor).' . PHP_EOL);
}

// Эмулируем HTTP-контекст для корректной работы config
$_SERVER['HTTP_HOST']   = 'dreamcar.ai-platform.space';
$_SERVER['HTTPS']       = 'on';
$_projectPath = '';
$_SERVER['SCRIPT_NAME'] = ($_projectPath !== '' ? '/' . $_projectPath : '') . '/cron/git_server_sync.php';

// === SAFE CONFIG ADAPTER ===
// config.php может содержать PDO с die() при ошибке БД - защищаемся
// Для CLI: die() убьет процесс, но supervisor перезапустит
ob_start();

if (file_exists(dirname(__DIR__) . '/config/git_sync.php')) {
    $gitSync = require dirname(__DIR__) . '/config/git_sync.php';
    if (!defined('GIT_BIN'))              define('GIT_BIN', $gitSync['git_bin'] ?? '/usr/bin/git');
    if (!defined('OPENAI_API_KEY'))       define('OPENAI_API_KEY', $gitSync['openai_api_key'] ?? '');
    if (!defined('GIT_SYNC_TG_TOKEN'))    define('GIT_SYNC_TG_TOKEN', $gitSync['tg_token'] ?? '');
    if (!defined('GIT_SYNC_TG_CHAT'))     define('GIT_SYNC_TG_CHAT', $gitSync['tg_chat'] ?? '');
    if (!defined('GIT_SYNC_TG_MENTION'))  define('GIT_SYNC_TG_MENTION', $gitSync['tg_mention'] ?? '');
    if (!defined('GIT_SYNC_NOTIFY_ROUTINE')) define('GIT_SYNC_NOTIFY_ROUTINE', $gitSync['notify_routine'] ?? 0);
    if (!defined('GITHUB_REPO_URL'))      define('GITHUB_REPO_URL', $gitSync['github_repo_url'] ?? '');
} else {
    if (file_exists(dirname(__DIR__) . '/config/env.php')) {
        require_once dirname(__DIR__) . '/config/env.php';
    }
    try {
        if (file_exists(dirname(__DIR__) . '/config.php')) {
            require_once dirname(__DIR__) . '/config.php';
        } elseif (file_exists(dirname(__DIR__) . '/config/app_config.php')) {
            require_once dirname(__DIR__) . '/config/app_config.php';
        }
    } catch (Throwable $e) {
        echo '[CONFIG] Non-fatal error: ' . $e->getMessage() . PHP_EOL;
    }
}
ob_end_clean();

// Переменные окружения для git (CLI может не иметь HOME)
putenv('HOME=/home/serverflow');
putenv('GIT_AUTHOR_NAME=Server Bot');
putenv('GIT_AUTHOR_EMAIL=server@dreamcar.ai');
putenv('GIT_COMMITTER_NAME=Server Bot');
putenv('GIT_COMMITTER_EMAIL=server@dreamcar.ai');

$GIT_BIN      = defined('GIT_BIN') ? GIT_BIN : '/usr/bin/git';
$PROJECT_DIR  = dirname(__DIR__);
$DOMAIN       = 'dreamcar.ai-platform.space';
$PROJECT_PATH = '';
$PROJECT_NAME = $DOMAIN . ($PROJECT_PATH ? '/' . $PROJECT_PATH : '');
$LOG_FILE     = $PROJECT_DIR . '/data/git_sync.log';
$TG_MENTION   = defined('GIT_SYNC_TG_MENTION') ? GIT_SYNC_TG_MENTION : '';
$SERVER_HOST  = trim((string)@gethostname()) ?: php_uname('n');
$REPO_URL     = defined('GITHUB_REPO_URL') ? preg_replace('#https://[^@]+@#', 'https://', (string)GITHUB_REPO_URL) : '';
$PROJECT_URL  = 'https://' . $DOMAIN . ($PROJECT_PATH ? '/' . $PROJECT_PATH : '') . '/';

// Создаем data/ если нет
$dataDir = $PROJECT_DIR . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// ВАЖНО: lock и pause файлы в data/ проекта, НЕ в /tmp/
// На VPS daemon работает от root, PHP-FPM (webhook) от serverflow.
// /tmp файл от root: права 644 - serverflow не может открыть fopen('c+') -> fatal error в webhook.
// data/ папка: chown serverflow делается в миграции - оба процесса имеют доступ.
// КРИТИЧНО: daemon создает файлы от root - нужен umask(0) чтобы lock/pause файлы
// создавались с правами 0666, иначе serverflow (webhook) не сможет их открыть.
umask(0);

// ВРЕМЕННАЯ ЗОНА: VPS работает в UTC, но логи/сообщения должны быть в Kyiv time
// Не меняем системный timezone (затрагивает все сервисы) - только для этого процесса
date_default_timezone_set('Europe/Kyiv');
$LOCK_FILE    = $PROJECT_DIR . '/data/gitsync.lock';
$PAUSE_FILE   = $PROJECT_DIR . '/data/gitsync_pause';

// --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---

function syncLog(string $message, string $level = 'INFO'): void {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '][' . $level . '] ' . $message . PHP_EOL;
    file_put_contents($LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line; // Supervisor пишет stdout в свои логи

    // Ротация: если > 500KB - обрезать
    if (file_exists($LOG_FILE) && filesize($LOG_FILE) > 512000) {
        $lines = file($LOG_FILE);
        $lines = array_slice($lines, -200);
        file_put_contents($LOG_FILE, implode('', $lines), LOCK_EX);
    }
}

function gitRun(string $subCmd, &$out = null, &$code = null): bool {
    global $GIT_BIN, $PROJECT_DIR;
    $cmd  = $GIT_BIN . ' -C ' . escapeshellarg($PROJECT_DIR) . ' ' . $subCmd . ' 2>&1';
    $out  = [];
    $code = 0;
    exec($cmd, $out, $code);
    return $code === 0;
}

// === FLOCK - атомарная блокировка (вместо file_exists TOCTOU) ===

function acquireLock(): mixed {
    global $LOCK_FILE;
    $fp = fopen($LOCK_FILE, 'c+');
    if (!$fp) return false;

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false; // Кто-то (webhook или другой daemon) уже держит lock
    }
    ftruncate($fp, 0);
    fwrite($fp, (string)getmypid());
    fflush($fp);
    return $fp; // Возвращаем handle - lock держится пока handle открыт
}

function releaseLock($fp): void {
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// === COOLDOWN / PAUSE - защита от бесконечного цикла ===

function isPaused(): bool {
    global $PAUSE_FILE;
    if (!file_exists($PAUSE_FILE)) return false;
    $pauseUntil = (int)file_get_contents($PAUSE_FILE);
    if (time() < $pauseUntil) return true;
    // Пауза истекла - удаляем файл
    @unlink($PAUSE_FILE);
    syncLog('Пауза истекла, возобновляем работу');
    return false;
}

function setPause(int $seconds, string $reason): void {
    global $PAUSE_FILE, $PROJECT_NAME, $PROJECT_DIR, $TG_MENTION;
    $pauseUntil = time() + $seconds;
    file_put_contents($PAUSE_FILE, (string)$pauseUntil);
    $minutes = (int)($seconds / 60);
    syncLog("ПАУЗА {$minutes} мин: {$reason}", 'WARN');

    $msg  = "⏸ <b>Git Sync: пауза {$minutes} мин</b>\n\n";
    $msg .= gitSyncProjectHeader();
    $msg .= "\n⚠️ <b>Причина:</b> {$reason}\n";
    $msg .= "⏰ <b>Возобновление:</b> " . date('Y-m-d H:i', $pauseUntil) . " (Kyiv)\n\n";
    $msg .= gitSyncMentionBlock();
    $msg .= gitSyncTailLog(8);
    sendGitTelegram($msg);
}

// --- TELEGRAM УВЕДОМЛЕНИЯ ---

function sendGitTelegram(string $text): void {
    $token = defined('GIT_SYNC_TG_TOKEN') ? GIT_SYNC_TG_TOKEN : (defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '');
    $chat  = defined('GIT_SYNC_TG_CHAT') ? GIT_SYNC_TG_CHAT : (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '');
    if (!$token || !$chat) return;

    $ch = curl_init("https://api.telegram.org/bot" . $token . "/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'chat_id'    => $chat,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
}

function gitSyncFlag(string $name, bool $default = false): bool {
    if (!defined($name)) {
        return $default;
    }

    $value = constant($name);
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function sendRoutineGitTelegram(string $text): void {
    if (!gitSyncFlag('GIT_SYNC_NOTIFY_ROUTINE', false)) {
        return;
    }

    sendGitTelegram($text);
}

function gitSyncMentionBlock(): string {
    global $TG_MENTION;
    return $TG_MENTION !== '' ? $TG_MENTION . "\n\n" : '';
}

/**
 * Унифицированная шапка для ВСЕХ Telegram-сообщений Git Sync.
 * Содержит ВСЕ детали проекта чтобы было сразу понятно: какой проект,
 * какой путь, какой сервер, какой репозиторий, какой URL, какой PID, какой лог.
 */
function gitSyncProjectHeader(): string {
    global $PROJECT_NAME, $PROJECT_DIR, $SERVER_HOST, $REPO_URL, $PROJECT_URL, $LOG_FILE;
    $h  = "📦 <b>Проект:</b> {$PROJECT_NAME}\n";
    $h .= "🌐 <b>URL:</b> <a href=\"{$PROJECT_URL}\">{$PROJECT_URL}</a>\n";
    $h .= "🖥 <b>Сервер:</b> <code>{$SERVER_HOST}</code>\n";
    $h .= "📂 <b>Путь:</b> <code>{$PROJECT_DIR}</code>\n";
    if ($REPO_URL !== '') {
        $h .= "🐙 <b>Repo:</b> <a href=\"{$REPO_URL}\">" . htmlspecialchars($REPO_URL) . "</a>\n";
    }
    $h .= "🆔 <b>PID:</b> <code>" . getmypid() . "</code>\n";
    $h .= "📝 <b>Лог:</b> <code>{$LOG_FILE}</code>\n";
    $h .= "🕐 <b>Время:</b> " . date('Y-m-d H:i:s') . " (Kyiv)\n";
    return $h;
}

/**
 * Последние N строк лога — для прикрепления к ошибкам, чтобы сразу видеть контекст.
 */
function gitSyncTailLog(int $lines = 10): string {
    global $LOG_FILE;
    if (!file_exists($LOG_FILE)) return '';
    $all = @file($LOG_FILE);
    if (!$all) return '';
    $tail = array_slice($all, -$lines);
    $text = implode('', $tail);
    if (mb_strlen($text) > 1500) $text = mb_substr($text, -1500);
    return "\n📋 <b>Последние строки лога:</b>\n<pre>" . htmlspecialchars($text) . "</pre>";
}

/**
 * Двойная проверка реально ли PAT отозван, или это разовый сетевой сбой.
 * Делает повторный git ls-remote через 5 секунд. Возвращает true только
 * если ОБЕ попытки провалились — тогда уверенно идем в долгую паузу.
 *
 * Защита от инцидента 05.05.2026 (mindway): GitHub API глюкнул на 1 запрос,
 * демон ушел в час паузы и спамил Telegram. PAT был валидный.
 */
function verifyAuthReallyFailed(): bool {
    syncLog('Возможен PAT expired - проверка через 5 сек повторным ls-remote...', 'WARN');
    sleep(5);
    $verifyOut = [];
    $verifyCode = 0;
    gitRun('ls-remote --heads origin', $verifyOut, $verifyCode);
    if ($verifyCode === 0) {
        syncLog('Повторная проверка OK - был разовый сбой, продолжаем работу');
        return false;
    }
    $verifyText = implode(' ', $verifyOut);
    syncLog('Повторная проверка тоже fail: ' . mb_substr($verifyText, 0, 200), 'ERROR');
    return true;
}

// --- OPENAI: ГЕНЕРАЦИЯ КОММИТ-СООБЩЕНИЯ ---

function generateCommitMessage(string $filesList, string $stats, string $fullDiff): string {
    if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) return '';

    $truncDiff = mb_substr($fullDiff, 0, 3000);
    if (mb_strlen($fullDiff) > 3000) $truncDiff .= "\n... (сокращено)";

    $prompt = <<<PROMPT
Ты - эксперт по написанию подробных git commit сообщений на русском языке.
Эти сообщения будут использоваться для аналитики, ретроспектив и отслеживания прогресса проекта.
Пиши грамотно - проверяй падежи, окончания, согласование слов.

Проект на сервере: {{PROJECT_NAME}}
Измененные файлы на сервере: {$filesList}
Статистика: {$stats}
Git diff: {$truncDiff}

ОБЯЗАТЕЛЬНЫЙ ФОРМАТ:
- Строка 1: слово "Server" + пробел + Глагол в прошедшем времени + что сделано (до 72 символов)
- Строка 2: пустая
- Строки 3+: подробный список ВСЕХ изменений с тире

ПРАВИЛА:
- ОБЯЗАТЕЛЬНО начинать строку 1 со слова "Server"
- Далее: Добавлен/Обновлен/Исправлен/Удален/Реализован/Оптимизирован
- Согласовывать род глагола с существительным
- Каждый пункт - конкретное изменение с файлом/компонентом
- Если 1 файл - все равно 2-4 пункта
- Если файлов много - группировать по смыслу
PROMPT;

    $data = [
        'model'       => 'gpt-4o-mini',
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => 400,
        'temperature' => 0.3,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200 || !$response) {
        return '';
    }

    $result  = json_decode($response, true);
    $message = trim($result['choices'][0]['message']['content'] ?? '');

    // Убираем ``` обертки если OpenAI добавит
    $message = preg_replace('/^```[a-z]*\n?/m', '', $message);
    $message = preg_replace('/```$/m', '', $message);
    $message = trim($message);

    // Гарантируем префикс Server
    if ($message && stripos($message, 'server') !== 0) {
        $message = 'Server ' . $message;
    }

    return $message;
}

// --- ПАРСИНГ ИЗМЕНЕНИЙ ДЛЯ TELEGRAM ---

function categorizeChanges(array $changedLines): array {
    $added = $modified = $deleted = $other = [];
    foreach ($changedLines as $line) {
        $status = trim(substr($line, 0, 2));
        $file   = trim(substr($line, 3));
        if (!$file) continue;
        switch ($status) {
            case '??': case 'A':  $added[]    = $file; break;
            case 'M':             $modified[] = $file; break;
            case 'D':             $deleted[]  = $file; break;
            default:              $other[]    = $file; break;
        }
    }
    return compact('added', 'modified', 'deleted', 'other');
}

// --- ПРОВЕРКА .GITIGNORE НА КРИТИЧЕСКИЕ ПАТТЕРНЫ ---

function checkGitignoreSafety(): void {
    global $PROJECT_DIR;
    $gitignoreFile = $PROJECT_DIR . '/.gitignore';
    if (!file_exists($gitignoreFile)) {
        syncLog('.gitignore НЕ НАЙДЕН - риск коммита sensitive файлов!', 'WARN');
        return;
    }

    $content = file_get_contents($gitignoreFile);
    $critical = ['.env', 'sessions/', 'data/'];
    $missing = [];

    foreach ($critical as $pattern) {
        if (strpos($content, $pattern) === false) {
            $missing[] = $pattern;
        }
    }

    if (!empty($missing)) {
        syncLog('.gitignore не содержит критические паттерны: ' . implode(', ', $missing), 'WARN');
    }
}

// --- ОСНОВНАЯ ЛОГИКА ИТЕРАЦИИ ---

function runSyncIteration(): void {
    global $PROJECT_DIR, $PROJECT_NAME, $TG_MENTION;

    // Проверка паузы (cooldown после конфликта)
    if (isPaused()) return;

    $lockFp = acquireLock();
    if (!$lockFp) return; // webhook или другой процесс работает с git

    try {
        // 1. Проверяем незакоммиченные изменения
        gitRun('status --porcelain', $statusOut);
        $changedLines = array_filter($statusOut);

        if (!empty($changedLines)) {
            // --- Есть серверные изменения - коммитим и пушим ---
            $changedCount = count($changedLines);
            syncLog("Обнаружены изменения: {$changedCount} файлов");

            $categories = categorizeChanges($changedLines);

            $filesList = implode(', ', array_map(function (string $line): string {
                return trim(substr($line, 3));
            }, $changedLines));

            gitRun('diff --stat HEAD', $statOut);
            gitRun('diff HEAD', $diffOut);
            $stats    = implode("\n", $statOut);
            $fullDiff = implode("\n", $diffOut);

            $commitMsg = generateCommitMessage($filesList, $stats, $fullDiff);

            if (!$commitMsg) {
                $commitMsg = 'Server Обновлены файлы на сервере ' . date('Y-m-d H:i:s') . "\n\n- " . str_replace(', ', "\n- ", $filesList);
                syncLog('OpenAI недоступен, использован fallback', 'WARN');
            }

            // git add -A
            if (!gitRun('add -A', $addOut)) {
                syncLog('Ошибка git add: ' . implode(' | ', $addOut), 'ERROR');
                $msg  = "❌ <b>Git Sync: ошибка git add</b>\n\n";
                $msg .= gitSyncProjectHeader();
                $msg .= "\n";
                $msg .= gitSyncMentionBlock();
                $msg .= "💥 <b>Stderr:</b>\n<code>" . htmlspecialchars(implode(' | ', $addOut)) . "</code>";
                $msg .= gitSyncTailLog(8);
                sendGitTelegram($msg);
                releaseLock($lockFp);
                return;
            }

            // Проверяем есть ли staged changes после add (race condition: webhook мог забрать файлы)
            $stagedCheck = [];
            gitRun('diff --cached --quiet', $stagedCheck, $stagedCode);
            if ($stagedCode === 0) {
                // Нет staged changes - webhook уже забрал изменения
                syncLog('SKIP: nothing staged after add (webhook resolved)');
                releaseLock($lockFp);
                return;
            }

            // git commit (через temp-файл для многострочных сообщений)
            $tmpFile = tempnam(sys_get_temp_dir(), 'gitmsg');
            file_put_contents($tmpFile, $commitMsg);
            $commitOk = gitRun('commit -F ' . escapeshellarg($tmpFile), $commitOut, $commitCode);
            unlink($tmpFile);

            if (!$commitOk) {
                syncLog('Ошибка commit: ' . implode(' | ', $commitOut), 'ERROR');
                $msg  = "❌ <b>Git Sync: ошибка коммита</b>\n\n";
                $msg .= gitSyncProjectHeader();
                $msg .= "\n";
                $msg .= gitSyncMentionBlock();
                $msg .= "💥 <b>Stderr:</b>\n<code>" . htmlspecialchars(implode(' | ', $commitOut)) . "</code>";
                $msg .= gitSyncTailLog(8);
                sendGitTelegram($msg);
                releaseLock($lockFp);
                return;
            }

            $firstLine = explode("\n", $commitMsg)[0];
            syncLog("Коммит создан: {$firstLine}");

            // Формируем детальное Telegram-сообщение
            $tgMsg  = "🖥 <b>Сервер сделал коммит</b>\n\n";
            $tgMsg .= "📦 <b>{$PROJECT_NAME}</b>\n";
            $tgMsg .= "📂 <code>{$PROJECT_DIR}</code>\n";
            $tgMsg .= "📝 Файлов: <b>{$changedCount}</b>";

            // Краткая сводка по типам изменений
            $summary = [];
            if (!empty($categories['added']))    $summary[] = "+" . count($categories['added']) . " новых";
            if (!empty($categories['modified'])) $summary[] = "~" . count($categories['modified']) . " изменено";
            if (!empty($categories['deleted']))  $summary[] = "-" . count($categories['deleted']) . " удалено";
            if ($summary) $tgMsg .= " (" . implode(', ', $summary) . ")";

            $tgMsg .= "\n\n<b>Коммит:</b>\n<code>" . htmlspecialchars($firstLine) . "</code>\n";

            // Список файлов (максимум 10)
            $allFiles = array_merge($categories['added'], $categories['modified'], $categories['deleted'], $categories['other']);
            if (count($allFiles) <= 10) {
                $tgMsg .= "\n<b>Файлы:</b>\n";
                foreach ($allFiles as $f) {
                    $tgMsg .= "  <code>" . htmlspecialchars($f) . "</code>\n";
                }
            } else {
                $tgMsg .= "\n<b>Файлы (первые 10):</b>\n";
                for ($i = 0; $i < 10; $i++) {
                    $tgMsg .= "  <code>" . htmlspecialchars($allFiles[$i]) . "</code>\n";
                }
                $rest = count($allFiles) - 10;
                $tgMsg .= "  <i>... и еще {$rest}</i>\n";
            }

            // git push
            if (!gitRun('push origin main', $pushOut)) {
                $pushError = implode(' ', $pushOut);

                // Детекция PAT expired - с двойной проверкой против ложных срабатываний
                if (strpos($pushError, 'Authentication failed') !== false || strpos($pushError, '403') !== false) {
                    if (verifyAuthReallyFailed()) {
                        syncLog('GitHub PAT expired!', 'CRITICAL');
                        setPause(3600, 'GitHub PAT недействителен - требуется обновление');
                        releaseLock($lockFp);
                        return;
                    }
                    // Разовый сбой - попробуем push еще раз
                    if (gitRun('push origin main', $pushRetryOut)) {
                        syncLog('Push после разового сбоя OK');
                        sendRoutineGitTelegram($tgMsg);
                        releaseLock($lockFp);
                        return;
                    }
                    $pushError = implode(' ', $pushRetryOut);
                }

                // Push fail - pull --rebase + retry
                syncLog('Пуш не прошел, делаем pull --rebase...');

                if (!gitRun('pull --rebase', $rebaseOut)) {
                    gitRun('rebase --abort');
                    syncLog('Rebase конфликт - abort, пауза 30 мин', 'ERROR');

                    // COOLDOWN 30 минут - защита от бесконечного цикла
                    setPause(1800, 'Конфликт при rebase - требуется ручное разрешение');
                    releaseLock($lockFp);
                    return;
                }

                if (!gitRun('push origin main', $push2Out)) {
                    syncLog('Пуш после rebase не прошел: ' . implode(' | ', $push2Out), 'ERROR');
                    $msg  = "⚠️ <b>Git Sync: пуш после rebase не прошел</b>\n\n";
                    $msg .= gitSyncProjectHeader();
                    $msg .= "\n";
                    $msg .= gitSyncMentionBlock();
                    $msg .= "💥 <b>Stderr:</b>\n<code>" . htmlspecialchars(implode(' | ', $push2Out)) . "</code>";
                    $msg .= gitSyncTailLog(8);
                    sendGitTelegram($msg);
                } else {
                    syncLog('OK (rebase): ' . $firstLine);
                    $tgMsg = str_replace('Сервер сделал коммит', 'Сервер сделал коммит (rebase)', $tgMsg);
                    sendRoutineGitTelegram($tgMsg);
                }
            } else {
                syncLog('OK: ' . $firstLine);
                sendRoutineGitTelegram($tgMsg);
            }

        } else {
            // --- Нет серверных изменений - проверяем не отстали ли от remote ---
            gitRun('fetch origin', $fetchOut, $fetchCode);

            if ($fetchCode !== 0) {
                $fetchOutput = implode(' ', $fetchOut);

                // Детекция PAT expired - с двойной проверкой против ложных срабатываний
                if (strpos($fetchOutput, 'Authentication failed') !== false || strpos($fetchOutput, '403') !== false) {
                    if (verifyAuthReallyFailed()) {
                        syncLog('GitHub PAT expired!', 'CRITICAL');
                        setPause(3600, 'GitHub PAT недействителен - требуется обновление');
                        releaseLock($lockFp);
                        return;
                    }
                    // Разовый сбой - повторим fetch
                    gitRun('fetch origin', $fetchOut, $fetchCode);
                    if ($fetchCode !== 0) {
                        syncLog('Fetch retry после ложного auth-fail тоже fail: ' . implode(' ', $fetchOut), 'WARN');
                        releaseLock($lockFp);
                        return;
                    }
                    syncLog('Fetch после разового auth-сбоя OK');
                    $fetchOutput = '';
                }

                // Auto-fix: если "cannot lock ref" - сбросить ref и повторить
                if (strpos($fetchOutput, 'cannot lock ref') !== false) {
                    gitRun('update-ref -d refs/remotes/origin/main');
                    gitRun('fetch origin', $fetchOut, $fetchCode);
                    if ($fetchCode !== 0) {
                        syncLog('FETCH RETRY FAIL: ' . implode(' ', $fetchOut), 'ERROR');
                        releaseLock($lockFp);
                        return;
                    }
                    syncLog('FETCH: auto-fixed lock ref');
                } else {
                    releaseLock($lockFp);
                    return; // fetch fail - пропускаем
                }
            }

            gitRun('rev-list HEAD..origin/main --count', $behindOut, $behindCode);
            $behind = (int)trim($behindOut[0] ?? '0');

            if ($behind > 0) {
                syncLog("Отставание от remote: {$behind} коммит(ов). Делаем merge...");

                // Stash dirty files перед merge (race condition: файл может измениться между status и merge)
                $stashBefore = [];
                gitRun('stash push -m "daemon-auto-stash"', $stashBefore);
                $didStashBeforeMerge = !empty($stashBefore) && strpos(implode(' ', $stashBefore), 'No local changes') === false;

                // Получаем логи новых коммитов ДО merge
                gitRun('log HEAD..origin/main --oneline --format=%H|%an|%s', $logOut);

                if (gitRun('merge --ff-only origin/main', $mergeOut)) {
                    syncLog("PULL: подтянули {$behind} коммитов");

                    $msg  = "🔄 <b>Сервер подтянул с GitHub</b>\n\n";
                    $msg .= gitSyncProjectHeader();
                    $msg .= "🔢 <b>Коммитов:</b> {$behind}\n\n";

                    // Показать коммиты (максимум 5)
                    $shown = 0;
                    foreach ($logOut as $logLine) {
                        if ($shown >= 5) {
                            $remaining = $behind - 5;
                            $msg .= "  <i>... и еще {$remaining}</i>\n";
                            break;
                        }
                        $parts = explode('|', $logLine, 3);
                        if (count($parts) === 3) {
                            $cSha    = substr($parts[0], 0, 7);
                            $cAuthor = $parts[1];
                            $cMsg    = mb_substr($parts[2], 0, 70);
                            $msg .= "<code>{$cSha}</code> {$cAuthor}\n";
                            $msg .= "  " . htmlspecialchars($cMsg) . "\n";
                        }
                        $shown++;
                    }

                    sendRoutineGitTelegram($msg);
                } else {
                    $mergeError = implode(' | ', $mergeOut);
                    syncLog('MERGE ERROR: ' . $mergeError, 'ERROR');

                    // ff-only fail = diverged history. Автоматически делаем rebase
                    if (strpos($mergeError, 'Not possible to fast-forward') !== false) {
                        syncLog('ff-only невозможен - делаем pull --rebase...');

                        if (gitRun('pull --rebase origin main', $rebaseOut)) {
                            syncLog('AUTO-REBASE OK: история выровнена');

                            // Push ребейзнутые коммиты обратно в GitHub
                            if (gitRun('push origin main', $pushRebaseOut)) {
                                syncLog('AUTO-REBASE PUSH OK');
                                $msg  = "🔧 <b>Git Sync: auto-rebase успешен</b>\n\n";
                                $msg .= gitSyncProjectHeader();
                                $msg .= "\n✅ История выровнена автоматически (rebase + push)";
                                sendRoutineGitTelegram($msg);
                            } else {
                                syncLog('AUTO-REBASE: rebase OK но push fail: ' . implode(' | ', $pushRebaseOut), 'WARN');
                            }
                        } else {
                            // Rebase конфликт - abort и пауза
                            gitRun('rebase --abort');
                            syncLog('AUTO-REBASE FAIL: конфликт, пауза 30 мин', 'ERROR');
                            setPause(1800, 'Rebase конфликт при auto-rebase - требуется ручное разрешение');
                        }
                    } else {
                        $msg  = "⚠️ <b>Git Sync: ошибка git merge</b>\n\n";
                        $msg .= gitSyncProjectHeader();
                        $msg .= "\n";
                        $msg .= gitSyncMentionBlock();
                        $msg .= "💥 <b>Stderr:</b>\n<code>" . htmlspecialchars(mb_substr($mergeError, 0, 500)) . "</code>";
                        $msg .= gitSyncTailLog(8);
                        sendGitTelegram($msg);
                    }
                }

                // Восстанавливаем stash если был
                if ($didStashBeforeMerge) {
                    $popStash = [];
                    gitRun('stash pop', $popStash, $popCode);
                    if ($popCode !== 0) {
                        // Конфликт stash - дропаем, файлы пересоздадутся в следующей итерации
                        gitRun('stash drop');
                        syncLog('WARN: stash pop conflict after merge - dropped');
                    }
                }
            }
        }

    } catch (Throwable $e) {
        syncLog('Исключение: ' . $e->getMessage(), 'ERROR');
        $msg  = "❌ <b>Git Sync: исключение</b>\n\n";
        $msg .= gitSyncProjectHeader();
        $msg .= "\n";
        $msg .= gitSyncMentionBlock();
        $msg .= "💥 <b>Exception:</b>\n<code>" . htmlspecialchars($e->getMessage()) . "</code>";
        $msg .= gitSyncTailLog(8);
        sendGitTelegram($msg);
    }

    releaseLock($lockFp);
}

// --- ОСНОВНОЙ ЦИКЛ SUPERVISOR ---

syncLog('=== Daemon запущен (PID: ' . getmypid() . ') ===');

// Проверка .gitignore при старте
checkGitignoreSafety();

sendRoutineGitTelegram(
    "🟢 <b>Git Sync daemon запущен</b>\n\n" .
    gitSyncProjectHeader() .
    "\n⏰ <b>Старт:</b> " . date('Y-m-d H:i:s')
);

$iterationCount = 0;

while (true) {
    runSyncIteration();
    $iterationCount++;

    // Каждые 720 итераций (~1 час) - лог heartbeat
    if ($iterationCount % 720 === 0) {
        syncLog("HEARTBEAT: {$iterationCount} итераций, PID " . getmypid());
    }

    sleep(5);
}
