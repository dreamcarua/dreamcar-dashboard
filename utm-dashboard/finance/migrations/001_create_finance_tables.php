<?php
// === 001_create_finance_tables.php ===
// finance/migrations/001_create_finance_tables.php
// НАЗНАЧЕНИЕ: Создание 6 таблиц финансового модуля + seed данные
// РАЗМЕР: ~250 строк

declare(strict_types=1);
ini_set('max_execution_time', '120');

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$results = [];
$errors = [];

// ─── 1. finance_projects ───────────────────────────────────────────────────
try {
    $db->execute("CREATE TABLE IF NOT EXISTS finance_projects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        status ENUM('active','completed','planned') DEFAULT 'active',
        date_start DATE NOT NULL,
        date_end DATE NOT NULL,
        budget_plan DECIMAL(14,2) DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_name (name),
        INDEX idx_status (status),
        INDEX idx_dates (date_start, date_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'finance_projects: OK';
} catch (Exception $e) {
    $errors[] = 'finance_projects: ' . $e->getMessage();
}

// ─── 2. finance_transactions ───────────────────────────────────────────────
try {
    $db->execute("CREATE TABLE IF NOT EXISTS finance_transactions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT UNSIGNED NOT NULL,
        type ENUM(
            'income',
            'income_extra',
            'expense',
            'card_topup',
            'withdrawal_vadym',
            'withdrawal_artem',
            'conversion',
            'salary'
        ) NOT NULL,
        category VARCHAR(100),
        description TEXT NOT NULL,
        amount_uah DECIMAL(14,2) NOT NULL,
        source_type ENUM('manual','crm_auto','payroll_auto') DEFAULT 'manual',
        crm_deal_id VARCHAR(100),
        card_id INT UNSIGNED,
        employee_id INT UNSIGNED,
        payroll_id BIGINT UNSIGNED,
        notes TEXT,
        transaction_date DATE NOT NULL,
        deleted_at DATETIME DEFAULT NULL,
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_project (project_id),
        INDEX idx_type (type),
        INDEX idx_source (source_type),
        INDEX idx_date (transaction_date),
        INDEX idx_crm_deal (crm_deal_id),
        INDEX idx_not_deleted (deleted_at),
        INDEX idx_project_date (project_id, transaction_date),
        INDEX idx_project_type (project_id, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'finance_transactions: OK';
} catch (Exception $e) {
    $errors[] = 'finance_transactions: ' . $e->getMessage();
}

// ─── 3. finance_cards ──────────────────────────────────────────────────────
try {
    $db->execute("CREATE TABLE IF NOT EXISTS finance_cards (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bank_name VARCHAR(100) NOT NULL,
        last4 VARCHAR(4) NOT NULL,
        owner_name VARCHAR(255),
        location VARCHAR(100),
        platforms VARCHAR(255),
        balance_uah DECIMAL(14,2) DEFAULT 0,
        limit_uah DECIMAL(14,2) DEFAULT 0,
        status ENUM('active','blocked','archived') DEFAULT 'active',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'finance_cards: OK';
} catch (Exception $e) {
    $errors[] = 'finance_cards: ' . $e->getMessage();
}

// ─── 4. finance_employees ──────────────────────────────────────────────────
try {
    $db->execute("CREATE TABLE IF NOT EXISTS finance_employees (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        role_name VARCHAR(100),
        employee_type ENUM('staff','contractor') DEFAULT 'staff',
        fixed_salary DECIMAL(14,2) DEFAULT 0,
        active BOOLEAN DEFAULT TRUE,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'finance_employees: OK';
} catch (Exception $e) {
    $errors[] = 'finance_employees: ' . $e->getMessage();
}

// ─── 5. finance_payroll ────────────────────────────────────────────────────
try {
    $db->execute("CREATE TABLE IF NOT EXISTS finance_payroll (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id INT UNSIGNED NOT NULL,
        project_id INT UNSIGNED,
        amount_uah DECIMAL(14,2) NOT NULL,
        period_month DATE,
        status ENUM('pending','paid') DEFAULT 'pending',
        paid_at DATETIME,
        transaction_id BIGINT UNSIGNED,
        notes TEXT,
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_employee (employee_id),
        INDEX idx_status (status),
        INDEX idx_period (period_month),
        INDEX idx_project (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'finance_payroll: OK';
} catch (Exception $e) {
    $errors[] = 'finance_payroll: ' . $e->getMessage();
}

// ─── 6. finance_usdt_balances ──────────────────────────────────────────────
try {
    $db->execute("CREATE TABLE IF NOT EXISTS finance_usdt_balances (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner ENUM('vadym','artem') NOT NULL,
        amount DECIMAL(14,4) NOT NULL,
        snapshot_date DATE NOT NULL,
        notes TEXT,
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_owner (owner),
        INDEX idx_date (snapshot_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'finance_usdt_balances: OK';
} catch (Exception $e) {
    $errors[] = 'finance_usdt_balances: ' . $e->getMessage();
}

// ─── SEED: 5 проектов ──────────────────────────────────────────────────────
$projects = [
    ['VOLVO XC90',         'completed', '2025-10-10', '2025-11-30'],
    ['AUDI Q7',            'completed', '2025-12-08', '2025-12-28'],
    ['BMW 330E HYBRID',    'completed', '2026-01-09', '2026-01-23'],
    ['MERCEDES GLE COUPE', 'completed', '2026-02-06', '2026-03-01'],
    ['BMW X5 HYBRID',      'active',    '2026-03-24', '2026-04-19'],
];

$seededProjects = 0;
foreach ($projects as [$name, $status, $start, $end]) {
    try {
        $check = $db->query(
            "SELECT id FROM finance_projects WHERE name = :name",
            ['name' => $name]
        )->fetch();
        if (!$check) {
            $db->execute(
                "INSERT INTO finance_projects (name, status, date_start, date_end) VALUES (:name, :status, :start, :end)",
                ['name' => $name, 'status' => $status, 'start' => $start, 'end' => $end]
            );
            $seededProjects++;
        }
    } catch (Exception $e) {
        $errors[] = "seed project {$name}: " . $e->getMessage();
    }
}
$results[] = "Проекти: {$seededProjects} додано (вже існуючи пропущено)";

// ─── SEED: 3 карты-заглушки ────────────────────────────────────────────────
$cards = [
    ['Monobank', '4242', 'Вадим',  'UA', 'Meta, Google', 15000, 50000],
    ['Privat',   '1234', 'Артем',  'UA', 'Meta',         8000,  30000],
    ['Privat',   '5678', 'Вадим',  'UA', 'TikTok',       3000,  20000],
];

$seededCards = 0;
foreach ($cards as [$bank, $last4, $owner, $loc, $platforms, $balance, $limit]) {
    try {
        $check = $db->query(
            "SELECT id FROM finance_cards WHERE bank_name = :bank AND last4 = :last4 AND owner_name = :owner",
            ['bank' => $bank, 'last4' => $last4, 'owner' => $owner]
        )->fetch();
        if (!$check) {
            $db->execute(
                "INSERT INTO finance_cards (bank_name, last4, owner_name, location, platforms, balance_uah, limit_uah)
                 VALUES (:bank, :last4, :owner, :loc, :platforms, :balance, :limit)",
                ['bank' => $bank, 'last4' => $last4, 'owner' => $owner,
                 'loc' => $loc, 'platforms' => $platforms, 'balance' => $balance, 'limit' => $limit]
            );
            $seededCards++;
        }
    } catch (Exception $e) {
        $errors[] = "seed card {$bank}/{$last4}: " . $e->getMessage();
    }
}
$results[] = "Картки: {$seededCards} додано";

// ─── SEED: 3 сотрудника-заглушки ──────────────────────────────────────────
$employees = [
    ['Вадим',  'Таргетолог',  'staff',      0],
    ['Артем',  'Таргетолог',  'staff',      0],
    ['Вiра',   'Таргетолог',  'contractor', 0],
];

$seededEmployees = 0;
foreach ($employees as [$name, $role, $type, $salary]) {
    try {
        $check = $db->query(
            "SELECT id FROM finance_employees WHERE name = :name",
            ['name' => $name]
        )->fetch();
        if (!$check) {
            $db->execute(
                "INSERT INTO finance_employees (name, role_name, employee_type, fixed_salary) VALUES (:name, :role, :type, :salary)",
                ['name' => $name, 'role' => $role, 'type' => $type, 'salary' => $salary]
            );
            $seededEmployees++;
        }
    } catch (Exception $e) {
        $errors[] = "seed employee {$name}: " . $e->getMessage();
    }
}
$results[] = "Спiвробiтники: {$seededEmployees} додано";

// ─── Результат ────────────────────────────────────────────────────────────
echo json_encode([
    'success' => empty($errors),
    'tables_created' => $results,
    'errors' => $errors,
    'message' => empty($errors)
        ? 'Мiграцiя виконана успiшно'
        : 'Мiграцiя завершена з помилками'
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
