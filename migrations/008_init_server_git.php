<?php
// === 008_INIT_SERVER_GIT.PHP ===
// migrations/008_init_server_git.php
// НАЗНАЧЕНИЕ: Инициализация Git на сервере (запускается ОДИН РАЗ)
// СВЯЗИ: config.php или config/app_config.php
// РАЗМЕР: ~110 строк

// Config adapter: поддержка обоих форматов
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/../config/app_config.php')) {
    require_once __DIR__ . '/../config/app_config.php';
}

header('Content-Type: application/json; charset=utf-8');

// Запускать только на продакшне (CLI от root на VPS = OK)
$isCli = php_sapi_name() === 'cli';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocalhost = !$isCli && (in_array($httpHost, ['localhost', '127.0.0.1']) || strpos($httpHost, 'localhost:') === 0);
if ($isLocalhost) {
    die(json_encode(['error' => 'Run this migration on production server only (or use CLI: php migrations/XXX_init_server_git.php)'], JSON_UNESCAPED_UNICODE));
}
if ($isCli) {
    // CLI: подавляем header() вызов выше
    @header('Content-Type: application/json; charset=utf-8');
}

$git        = defined('GIT_BIN') ? GIT_BIN : '/usr/bin/git';
$projectDir = dirname(__DIR__);
$results    = [];

// HOME для git
putenv('HOME=/home/serverflow');

// --- ШАГ 1: git init (если не инициализирован) ---

$gitDir = $projectDir . '/.git';
if (!is_dir($gitDir)) {
    $out = [];
    exec($git . ' -C ' . escapeshellarg($projectDir) . ' init 2>&1', $out, $code);
    $results[] = ['step' => 'git init', 'code' => $code, 'output' => implode(' ', $out)];
} else {
    $results[] = ['step' => 'git init', 'code' => 0, 'output' => 'Already initialized'];
}

// --- ШАГ 1.1: safe.directory СРАЗУ после init ---
// КРИТИЧНО: должен быть ДО любых git -C команд!
// На VPS с HestiaCP: .git создан от root, но git -C проверяет ownership.
// Без safe.directory все последующие git команды падают с "dubious ownership".
$out = [];
exec('git config --global --add safe.directory ' . escapeshellarg($projectDir) . ' 2>&1', $out, $code);
$results[] = ['step' => 'git config safe.directory (global)', 'code' => $code, 'output' => implode(' ', $out) ?: 'ok'];

// --- ШАГ 2: Настройка remote origin ---

$repoUrl = defined('GITHUB_REPO_URL') ? GITHUB_REPO_URL : '';
if (!$repoUrl) {
    die(json_encode(['error' => 'GITHUB_REPO_URL not defined in config'], JSON_UNESCAPED_UNICODE));
}

$remoteOut = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' remote get-url origin 2>&1', $remoteOut, $remoteCode);

if ($remoteCode !== 0) {
    $out = [];
    $addRemote = $git . ' -C ' . escapeshellarg($projectDir) . ' remote add origin ' . escapeshellarg($repoUrl) . ' 2>&1';
    exec($addRemote, $out, $code);
    $results[] = ['step' => 'remote add origin', 'code' => $code, 'output' => implode(' ', $out)];
} else {
    $out = [];
    $setRemote = $git . ' -C ' . escapeshellarg($projectDir) . ' remote set-url origin ' . escapeshellarg($repoUrl) . ' 2>&1';
    exec($setRemote, $out, $code);
    $results[] = ['step' => 'remote set-url origin', 'code' => $code, 'output' => implode(' ', $out)];
}

// --- ШАГ 3: Git user config ---

$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' config user.email "server@dashboard.ai" 2>&1', $out, $code);
$results[] = ['step' => 'config user.email', 'code' => $code, 'output' => implode(' ', $out)];

$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' config user.name "Server Bot" 2>&1', $out, $code);
$results[] = ['step' => 'config user.name', 'code' => $code, 'output' => implode(' ', $out)];

// КРИТИЧНО: глобальный .gitconfig может содержать pull.rebase=true
$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' config pull.rebase false 2>&1', $out, $code);
$results[] = ['step' => 'config pull.rebase false', 'code' => $code, 'output' => implode(' ', $out)];

// --- ШАГ 3.1: chown .git на web-user ---
// После этого PHP-FPM (serverflow) сможет открывать .git файлы на запись.
// exec() для shell команд - доступен в CLI (миграция запускается через веб, но exec() уже должен работать).
$webUser = 'serverflow';
$out = [];
exec('chown -R ' . escapeshellarg($webUser) . ':' . escapeshellarg($webUser) . ' ' . escapeshellarg($projectDir . '/.git') . ' 2>&1', $out, $code);
$results[] = ['step' => 'chown .git to web-user (' . $webUser . ')', 'code' => $code, 'output' => implode(' ', $out) ?: 'ok'];

// --- ШАГ 3.2: Создать data/ с правами web-user ---
// Lock файлы (gitsync.lock, gitsync_pause) и логи хранятся в data/.
// Daemon (root) и webhook (serverflow) оба должны иметь доступ.
// Создаем от root, отдаем serverflow - daemon все равно сможет писать (root > все).
$dataPath = $projectDir . '/data';
if (!is_dir($dataPath)) {
    mkdir($dataPath, 0755, true);
}
$out = [];
exec('chown -R ' . escapeshellarg($webUser) . ':' . escapeshellarg($webUser) . ' ' . escapeshellarg($dataPath) . ' 2>&1', $out, $code);
$results[] = ['step' => 'chown data/ to web-user (' . $webUser . ')', 'code' => $code, 'output' => implode(' ', $out) ?: 'ok'];

// --- ШАГ 4: git fetch ---
// ВАЖНО: шаг 3.3 (gitignore) перенесен ПОСЛЕ reset --hard (шаг 5)!
// Причина: reset --hard перезаписывает .gitignore из origin - добавленные строки теряются.
// Инцидент 27.03.2026: monitor.ai-platform.space - бесконечный цикл dedup коммитов.
// Инцидент 19.03.2026: study.ai-platform.space - 30+ спам-коммитов.

$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' fetch origin 2>&1', $out, $code);
$results[] = ['step' => 'git fetch origin', 'code' => $code, 'output' => implode(' ', $out)];

// --- ШАГ 5: git reset --hard origin/main ---
// БЕЗОПАСНО: файлы из .gitignore (data/, logs/, tokens) НЕ трогаются

$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' reset --hard origin/main 2>&1', $out, $code);
$results[] = ['step' => 'git reset --hard origin/main', 'code' => $code, 'output' => implode(' ', $out)];

// --- ШАГ 5.1: chown ВСЕ файлы проекта на web-user ---
// КРИТИЧНО: git reset --hard от root создает файлы с владельцем root.
// PHP-FPM (serverflow) не сможет перезаписать их (upload, cache, logs).
// Инцидент 14.03.2026: ai.dreamcar.ua "помилка збереження" - файлы принадлежали root.

$out = [];
exec('chown -R ' . escapeshellarg($webUser) . ':' . escapeshellarg($webUser) . ' ' . escapeshellarg($projectDir) . ' 2>&1', $out, $code);
$results[] = ['step' => 'chown ALL project files to web-user (' . $webUser . ')', 'code' => $code, 'output' => implode(' ', $out) ?: 'ok'];

// --- ШАГ 5.2: Обновить .gitignore ПОСЛЕ reset --hard ---
// КРИТИЧНО: reset --hard перезаписывает .gitignore из origin!
// Поэтому добавляем gitsync строки ПОСЛЕ reset, не до.
// Без этого: бесконечный цикл daemon коммит -> webhook -> dedup файл -> daemon коммит.

$gitignorePath = $projectDir . '/.gitignore';
$gitignoreContent = file_exists($gitignorePath) ? file_get_contents($gitignorePath) : '';
$gitignoreLines = [
    'data/gitsync_dedup/',
    'data/git_sync.log',
    'data/gitsync.lock',
    'data/gitsync_pause',
];
$added = [];
foreach ($gitignoreLines as $line) {
    if (strpos($gitignoreContent, $line) === false) {
        $added[] = $line;
    }
}
if (!empty($added)) {
    $append = "\n# Git Server Sync runtime files (ОБЯЗАТЕЛЬНО игнорировать!)\n";
    $append .= implode("\n", $added) . "\n";
    file_put_contents($gitignorePath, $append, FILE_APPEND);
    $results[] = ['step' => 'gitignore: add gitsync runtime files (after reset)', 'code' => 0, 'output' => 'Added: ' . implode(', ', $added)];
} else {
    $results[] = ['step' => 'gitignore: check gitsync runtime files', 'code' => 0, 'output' => 'Already covered'];
}

// Убрать из git index если уже были закоммичены
$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' rm -r --cached data/gitsync_dedup/ 2>&1', $out, $code);
if ($code === 0) {
    $results[] = ['step' => 'git rm --cached data/gitsync_dedup/', 'code' => 0, 'output' => 'Removed from index'];
}

// Коммит .gitignore чтобы daemon не закоммитил его как "серверное изменение"
$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' add .gitignore 2>&1', $out, $code);
exec($git . ' -C ' . escapeshellarg($projectDir) . ' commit -m "Add gitsync runtime files to .gitignore" 2>&1', $out, $code);
if ($code === 0) {
    exec($git . ' -C ' . escapeshellarg($projectDir) . ' push origin main 2>&1', $out, $code);
    $results[] = ['step' => 'commit+push .gitignore update', 'code' => $code, 'output' => implode(' ', $out)];
}

// --- ШАГ 6: Ветка main + upstream ---

$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' branch -m master main 2>&1', $out, $code);
$results[] = ['step' => 'git branch -m master main', 'code' => $code, 'output' => implode(' ', $out)];

$out = [];
exec($git . ' -C ' . escapeshellarg($projectDir) . ' branch --set-upstream-to=origin/main main 2>&1', $out, $code);
$results[] = ['step' => 'git branch --set-upstream-to', 'code' => $code, 'output' => implode(' ', $out)];

// --- ШАГ 7: Проверить статус ---

exec($git . ' -C ' . escapeshellarg($projectDir) . ' log --oneline -3 2>&1', $logOut, $logCode);
$results[] = ['step' => 'git log -3', 'code' => $logCode, 'output' => implode(' | ', $logOut)];

// --- ИТОГ ---

$allOk   = array_reduce($results, fn($carry, $item) => $carry && $item['code'] === 0, true);
$summary = [
    'status'  => $allOk ? 'ok' : 'partial',
    'project' => 'utm-dashboard',
    'path'    => $projectDir,
    'steps'   => $results,
    'message' => $allOk
        ? 'Git инициализирован. Сервер готов к синхронизации.'
        : 'Некоторые шаги завершились с ошибкой - проверь steps.',
];

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
