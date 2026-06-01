<?php
// === GIT_WEBHOOK.PHP ===
// api/git_webhook.php
// НАЗНАЧЕНИЕ: Принимает GitHub Push webhook и делает git pull на сервере (instant sync)
// СВЯЗИ: config.php или config/app_config.php (через Config Adapter)
// РАЗМЕР: ~250 строк

// === SAFE CONFIG ADAPTER ===
// config.php может содержать PDO с die() при ошибке БД - нам БД не нужна
// ob_start() ловит вывод, register_shutdown_function ловит die()
$_configLoaded = false;
$_configDied   = false;

register_shutdown_function(function() use (&$_configDied) {
    // Если config.php вызвал die() - мы все равно умрем, но хотя бы вернем JSON
    if ($_configDied) return;
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'config_error', 'error' => $error['message']]);
    }
});

ob_start();

// Приоритет: config/git_sync.php (чистый, без PDO) > config.php > config/app_config.php
if (file_exists(__DIR__ . '/../config/git_sync.php')) {
    $gitSync = require __DIR__ . '/../config/git_sync.php';
    if (!defined('GITHUB_WEBHOOK_SECRET')) define('GITHUB_WEBHOOK_SECRET', $gitSync['webhook_secret'] ?? '');
    if (!defined('GIT_BIN'))              define('GIT_BIN', $gitSync['git_bin'] ?? '/usr/bin/git');
    if (!defined('GIT_SYNC_TG_TOKEN'))    define('GIT_SYNC_TG_TOKEN', $gitSync['tg_token'] ?? '');
    if (!defined('GIT_SYNC_TG_CHAT'))     define('GIT_SYNC_TG_CHAT', $gitSync['tg_chat'] ?? '');
    if (!defined('GIT_SYNC_TG_MENTION'))  define('GIT_SYNC_TG_MENTION', $gitSync['tg_mention'] ?? '');
    $_configLoaded = true;
} else {
    if (file_exists(__DIR__ . '/../config/env.php')) {
        require_once __DIR__ . '/../config/env.php';
    }
    try {
        if (file_exists(__DIR__ . '/../config.php')) {
            require_once __DIR__ . '/../config.php';
            $_configLoaded = true;
        } elseif (file_exists(__DIR__ . '/../config/app_config.php')) {
            require_once __DIR__ . '/../config/app_config.php';
            $_configLoaded = true;
        }
    } catch (Throwable $e) {
        // Git sync не требует БД - продолжаем с тем что есть
    }
}
ob_end_clean();
$_configDied = true; // Дальше shutdown function не нужна

header('Content-Type: application/json; charset=utf-8');

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method Not Allowed']));
}

// Проверяем что exec() доступен (на VPS может быть отключен в PHP FPM disable_functions)
// Если отключен - webhook не сможет запускать git. Нужно убрать exec из disable_functions.
if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    die(json_encode([
        'error'  => 'exec() is disabled in PHP FPM (disable_functions)',
        'fix'    => 'Remove exec,system,passthru,shell_exec,proc_open,popen from disable_functions in /etc/php/8.4/fpm/php.ini and restart php8.4-fpm',
        'status' => 'config_error',
    ]));
}

// Читаем тело запроса
$rawBody = file_get_contents('php://input');

// Проверяем подпись GitHub
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!$signature) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing X-Hub-Signature-256 header']));
}

$expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, GITHUB_WEBHOOK_SECRET);
if (!hash_equals($expectedSig, $signature)) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid signature']));
}

// === ДЕДУПЛИКАЦИЯ по X-GitHub-Delivery ===
// GitHub может отправить retry если ответ медленный - не обрабатываем повторно
// ВАЖНО: дедупликация тоже в data/ (не в /tmp/) - та же проблема прав root vs serverflow
$deliveryId = $_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '';
$dedupDir   = dirname(__DIR__) . '/data/gitsync_dedup';
if (!is_dir($dedupDir)) @mkdir($dedupDir, 0755, true);

if ($deliveryId) {
    $dedupFile = $dedupDir . '/' . md5($deliveryId);
    if (file_exists($dedupFile) && (time() - filemtime($dedupFile)) < 120) {
        die(json_encode(['status' => 'duplicate', 'delivery' => $deliveryId]));
    }
    touch($dedupFile);

    // Чистка старых файлов дедупликации (>5 мин)
    foreach (glob($dedupDir . '/*') as $f) {
        if (time() - filemtime($f) > 300) @unlink($f);
    }
}

// Декодируем payload
$payload = json_decode($rawBody, true);
$branch  = str_replace('refs/heads/', '', $payload['ref'] ?? '');

// Только ветка main
if ($branch !== 'main') {
    die(json_encode(['status' => 'skipped', 'reason' => 'Not main branch', 'branch' => $branch]));
}

// === FLOCK - атомарная блокировка ===
// ВАЖНО: lock файл в data/ проекта, НЕ в /tmp/
// Причина: на VPS daemon работает от root, PHP-FPM от serverflow.
// /tmp/file созданный root имеет права 644 - serverflow не может открыть на запись.
// data/ принадлежит тому же user что и проект - оба процесса имеют доступ.
$dataDir  = dirname(__DIR__) . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
$lockFile = $dataDir . '/gitsync.lock';
$lockFp   = fopen($lockFile, 'c+');

if (!$lockFp) {
    die(json_encode(['error' => 'Cannot create lock file', 'path' => $lockFile, 'fix' => 'Check data/ directory permissions']));
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Кто-то (daemon или другой webhook) уже работает с git
    fclose($lockFp);
    // HTTP 200 (не 500!) - чтобы GitHub не деактивировал webhook
    die(json_encode(['status' => 'locked', 'reason' => 'Another sync in progress, daemon will pull shortly']));
}

// Lock захвачен - записываем PID
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());
fflush($lockFp);

// Устанавливаем HOME для git в web-контексте
putenv('HOME=/home/serverflow');

$git  = defined('GIT_BIN') ? GIT_BIN : '/usr/bin/git';
$path = dirname(__DIR__);

// --- Вспомогательная функция ---
function gitCmd(string $git, string $path, string $args): array {
    $cmd = $git . ' -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1';
    exec($cmd, $output, $code);
    return ['output' => implode("\n", $output), 'code' => $code];
}

// --- Telegram уведомление ---
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

function gitSyncMentionValue(): string {
    if (defined('GIT_SYNC_TG_MENTION')) {
        $mention = trim((string)GIT_SYNC_TG_MENTION);
        if ($mention !== '') {
            return $mention;
        }
    }

    if (isset($GLOBALS['TG_MENTION'])) {
        $mention = trim((string)$GLOBALS['TG_MENTION']);
        if ($mention !== '') {
            return $mention;
        }
    }

    return '';
}

function gitSyncIsRoutineMessage(string $text): bool {
    foreach ([
        'Git Sync daemon запущен',
        'Сервер сделал коммит',
        'Сервер подтянул с GitHub',
        'Сервер подтянул коммит',
        'auto-rebase успешен',
        'Сервер: merge (ветки расходились)',
    ] as $needle) {
        if (strpos($text, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function gitSyncNeedsUrgentMention(string $text): bool {
    foreach (['❌ <b>', '⚠️ <b>', '⏸ <b>', '🔑 <b>'] as $needle) {
        if (strpos($text, $needle) !== false) {
            return true;
        }
    }

    return false;
}
function sendGitTelegram(string $text): void {
    $token = defined('GIT_SYNC_TG_TOKEN') ? GIT_SYNC_TG_TOKEN : (defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '');
    $chat  = defined('GIT_SYNC_TG_CHAT') ? GIT_SYNC_TG_CHAT : (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '');
    if (!$token || !$chat) return;

    if (!gitSyncFlag('GIT_SYNC_NOTIFY_ROUTINE', false) && !gitSyncNeedsUrgentMention($text)) {
        return;
    }

    $mention = gitSyncMentionValue();
    if ($mention !== '' && gitSyncNeedsUrgentMention($text) && strpos($text, $mention) === false) {
        $text = rtrim($text) . "\n\n" . $mention;
    }

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

// --- ВСЕГДА stash перед pull (race condition: файл может измениться между проверкой и pull) ---
$stashResult = gitCmd($git, $path, 'stash push -m "webhook-auto-stash"');
$didStash = ($stashResult['code'] === 0 && strpos($stashResult['output'], 'No local changes') === false);

// --- fetch + merge вместо pull ---
$fetchResult = gitCmd($git, $path, 'fetch origin main');

// Auto-fix: "cannot lock ref" (гонка daemon vs webhook)
if ($fetchResult['code'] !== 0 && strpos($fetchResult['output'], 'cannot lock ref') !== false) {
    gitCmd($git, $path, 'update-ref -d refs/remotes/origin/main');
    $fetchResult = gitCmd($git, $path, 'fetch origin main');
}

// Детекция: PAT expired
if ($fetchResult['code'] !== 0 && (
    strpos($fetchResult['output'], 'Authentication failed') !== false ||
    strpos($fetchResult['output'], '403') !== false
)) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $msg  = "🔑 <b>Git Sync: GitHub PAT недействителен!</b>\n\n";
    $msg .= "📦 Проект: <b>utm-dashboard</b>\n";
    $msg .= "Требуется обновить GITHUB_PAT в конфиге.";
    sendGitTelegram($msg);
    die(json_encode(['status' => 'auth_error', 'reason' => 'GitHub PAT expired or revoked']));
}

if ($fetchResult['code'] !== 0) {
    $result = $fetchResult;
} else {
    $result = gitCmd($git, $path, 'merge --ff-only origin/main');

    // Если merge упал (dirty files появились после stash из-за race condition) - reset
    if ($result['code'] !== 0 && strpos($result['output'], 'would be overwritten') !== false) {
        gitCmd($git, $path, 'reset --hard origin/main');
        $result = ['code' => 0, 'output' => 'reset to origin/main (auto-resolved dirty conflict)'];
    }
}

// Восстанавливаем stash если был
if ($didStash) {
    $popResult = gitCmd($git, $path, 'stash pop');
    if ($popResult['code'] !== 0) {
        // Конфликт - дропаем stash, daemon пересоздаст файлы
        gitCmd($git, $path, 'stash drop');
    }
}

$code   = $result['code'];
$output = explode("\n", $result['output']);
$sha    = substr($payload['after'] ?? '', 0, 8);
$pusher = $payload['pusher']['name'] ?? 'unknown';
$repoName = $payload['repository']['name'] ?? 'utm-dashboard';

// Собираем список коммитов из payload
$commits     = $payload['commits'] ?? [];
$commitCount = count($commits);

if ($code === 0) {
    // Формируем детальное сообщение
    $msg  = "📥 <b>Сервер подтянул с GitHub</b>\n\n";
    $msg .= "📦 Проект: <b>{$repoName}</b>\n";
    $msg .= "👤 Автор push: <b>{$pusher}</b>\n";
    $msg .= "🔢 Коммитов: <b>{$commitCount}</b>\n\n";

    // Детали каждого коммита (максимум 5)
    $shown = 0;
    foreach ($commits as $c) {
        if ($shown >= 5) {
            $remaining = $commitCount - 5;
            $msg .= "  <i>... и еще {$remaining}</i>\n";
            break;
        }
        $cSha     = substr($c['id'] ?? '', 0, 7);
        $cAuthor  = $c['author']['name'] ?? $pusher;
        $cMsg     = explode("\n", $c['message'] ?? '')[0];
        $cMsg     = mb_substr($cMsg, 0, 80);
        $added    = count($c['added'] ?? []);
        $modified = count($c['modified'] ?? []);
        $removed  = count($c['removed'] ?? []);

        $fileSummary = [];
        if ($added > 0)    $fileSummary[] = "+{$added}";
        if ($modified > 0) $fileSummary[] = "~{$modified}";
        if ($removed > 0)  $fileSummary[] = "-{$removed}";
        $fileStr = $fileSummary ? ' (' . implode(' ', $fileSummary) . ')' : '';

        $msg .= "<code>{$cSha}</code> {$cAuthor}{$fileStr}\n";
        $msg .= "  " . htmlspecialchars($cMsg) . "\n";

        // Список файлов (максимум 10 на коммит)
        $allFiles = array_merge($c['added'] ?? [], $c['modified'] ?? [], $c['removed'] ?? []);
        if (!empty($allFiles)) {
            $showFiles = array_slice($allFiles, 0, 10);
            foreach ($showFiles as $f) {
                $msg .= "  <code>" . htmlspecialchars($f) . "</code>\n";
            }
            if (count($allFiles) > 10) {
                $rest = count($allFiles) - 10;
                $msg .= "  <i>... и еще {$rest}</i>\n";
            }
        }

        $shown++;
    }

    sendGitTelegram($msg);
    $status = 'ok';
} elseif (strpos($result['output'], 'Not possible to fast-forward') !== false ||
          strpos($result['output'], 'fatal: Not a valid') !== false) {
    // ff-only fail - история разошлась. Пробуем rebase прямо тут
    $rebaseResult = gitCmd($git, $path, 'pull --rebase origin main');
    if ($rebaseResult['code'] === 0) {
        // Rebase успешен - push обратно
        $pushResult = gitCmd($git, $path, 'push origin main');
        $status = ($pushResult['code'] === 0) ? 'ok_after_rebase' : 'rebase_ok_push_deferred';
        $result = $rebaseResult;

        $msg  = "🔧 <b>Webhook: auto-rebase успешен</b>\n\n";
        $msg .= "📦 Проект: <b>{$repoName}</b>\n";
        $msg .= "История выровнена автоматически";
        sendGitTelegram($msg);
    } else {
        // Rebase конфликт - abort, defer на daemon (daemon поставит паузу)
        gitCmd($git, $path, 'rebase --abort');
        $status = 'deferred_to_daemon';
    }
} else {
    $errText = implode(' | ', $output);
    $msg  = "❌ <b>Webhook: ошибка git pull</b>\n\n";
    $msg .= "📦 Проект: <b>{$repoName}</b>\n";
    $msg .= "👤 Push от: <b>{$pusher}</b>\n";
    $msg .= "🔗 Коммит: <code>{$sha}</code>\n\n";
    $msg .= "<code>" . htmlspecialchars(mb_substr($errText, 0, 500)) . "</code>";
    sendGitTelegram($msg);
    $status = 'error';
    // Все равно HTTP 200 - GitHub не должен деактивировать webhook
}

// Освобождаем lock
flock($lockFp, LOCK_UN);
fclose($lockFp);

echo json_encode([
    'status'    => $status,
    'output'    => implode("\n", $output),
    'timestamp' => date('Y-m-d H:i:s'),
    'branch'    => $branch,
    'commit'    => $sha,
    'pusher'    => $pusher,
    'commits'   => $commitCount,
], JSON_UNESCAPED_UNICODE);
