<?php
/**
 * api/meta_analytics.php
 * ІЗОЛЬОВАНИЙ endpoint реальної виручки для розділу «Meta Ads Аналітика».
 * НЕ змінює існуючі роути (api/handler.php) — окремий файл, тільки читання.
 * Перевикористовує наявну модель CrmDeal::getStats() (реальні оплати з crm_deals).
 *
 * Виклик: api/meta_analytics.php?action=real_revenue&date_from=2026-06-07&date_to=2026-06-15[&model=KTM]
 * Відповідь: {"success":true,"paid_amount":..,"paid_count":..,"leads":..}
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- легкий guard: та сама сесія, що й дашборд (read-only агрегати) ---
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
$hasSession = !empty($_SESSION['user']) || !empty($_SESSION['role']) || !empty($_SESSION['utm_term']);
$hasGuest   = !empty($_GET['utm_term']); // гостьовий режим дашборду
if (!$hasSession && !$hasGuest) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action !== 'real_revenue') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'unknown action']);
    exit;
}

// --- валідація дат (строго YYYY-MM-DD) ---
function valid_date($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}
$from = $_GET['date_from'] ?? '';
$to   = $_GET['date_to'] ?? '';
if (!valid_date($from) || !valid_date($to)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid date_from/date_to (need YYYY-MM-DD)']);
    exit;
}

try {
    require_once __DIR__ . '/../core/models/CrmDeal.php';

    $filters = [
        'date_from' => $from . ' 00:00:00',
        'date_to'   => $to   . ' 23:59:59',
    ];
    // опційний фільтр по проєкту (використовує наявний маппінг аліасів CrmDeal)
    if (!empty($_GET['model'])) {
        $filters['model'] = $_GET['model'];
    }
    // гостьовий режим — обмежити по utm_term (як у решті дашборду)
    if ($hasGuest) {
        $filters['utm_term'] = $_GET['utm_term'];
    }

    $stats = CrmDeal::getStats($filters);

    echo json_encode([
        'success'     => true,
        'paid_amount' => isset($stats['paid_amount']) ? (float)$stats['paid_amount'] : 0,
        'paid_count'  => isset($stats['paid_count'])  ? (int)$stats['paid_count']   : 0,
        'leads'       => isset($stats['total_leads']) ? (int)$stats['total_leads']  : 0,
        'date_from'   => $from,
        'date_to'     => $to,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server error']);
}
