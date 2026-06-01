<?php
// === 010_add_audi_etron_project.php ===
// dashboard/utm-dashboard/migrations/010_add_audi_etron_project.php
// НАЗНАЧЕНИЕ: Добавить проект AUDI E-TRON в finance_projects + перевести BMW X5 HYBRID в completed
// СВЯЗИ: finance_projects (БД)
// БЕЗОПАСНО: проверяет существование перед INSERT, не дублирует записи

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);

    $log = [];

    // 1. Проверить - есть ли уже AUDI E-TRON в finance_projects
    $check = $pdo->prepare("SELECT id, status FROM finance_projects WHERE name = :name LIMIT 1");
    $check->execute([':name' => 'AUDI E-TRON']);
    $existing = $check->fetch();

    if ($existing) {
        $log[] = "AUDI E-TRON уже существует (id=" . $existing['id'] . ", status=" . $existing['status'] . ") - пропускаю INSERT";
    } else {
        $insert = $pdo->prepare("
            INSERT INTO finance_projects (name, status, date_start, date_end)
            VALUES (:name, :status, :start, :end)
        ");
        $insert->execute([
            ':name'   => 'AUDI E-TRON',
            ':status' => 'active',
            ':start'  => '2026-05-06',
            ':end'    => '2026-05-31',
        ]);
        $newId = $pdo->lastInsertId();
        $log[] = "AUDI E-TRON создан (id=$newId, 2026-05-06 - 2026-05-31, active)";
    }

    // 2. Перевести BMW X5 HYBRID в completed (период 24.03-19.04 уже прошел)
    $checkBmw = $pdo->prepare("SELECT id, status FROM finance_projects WHERE name = :name LIMIT 1");
    $checkBmw->execute([':name' => 'BMW X5 HYBRID']);
    $bmw = $checkBmw->fetch();

    if ($bmw) {
        if ($bmw['status'] === 'completed') {
            $log[] = "BMW X5 HYBRID уже completed - пропускаю UPDATE";
        } else {
            $upd = $pdo->prepare("UPDATE finance_projects SET status = 'completed' WHERE name = :name");
            $upd->execute([':name' => 'BMW X5 HYBRID']);
            $log[] = "BMW X5 HYBRID переведен в completed";
        }
    } else {
        $log[] = "BMW X5 HYBRID не найден в finance_projects (странно, но не критично)";
    }

    // 3. Финальный список проектов
    $projects = $pdo->query("
        SELECT id, name, status, date_start, date_end
        FROM finance_projects
        ORDER BY date_start ASC
    ")->fetchAll();

    echo json_encode([
        'success' => true,
        'log'     => $log,
        'finance_projects' => $projects,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
