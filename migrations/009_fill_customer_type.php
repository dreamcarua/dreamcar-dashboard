<?php
$_SERVER['HTTP_HOST'] = 'ticket.ai-platform.space';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';

echo "Starting customer_type migration...\n";
$start = microtime(true);
$db = Database::getInstance();

$sql = "SELECT deal_id, contact_id, phone, email FROM crm_deals ORDER BY created_at ASC";
$deals = $db->fetchAll($sql);
echo "Loaded " . count($deals) . " deals\n";

$contactIdToClient = [];
$phoneToClient = [];
$emailToClient = [];
$clients = [];
$nextClientId = 1;

foreach ($deals as $deal) {
    $dealId = $deal['deal_id'];
    $contactId = $deal['contact_id'];
    $phones = array_filter(array_map('trim', preg_split('/[;,]/', $deal['phone'] ?? '')));
    $emails = array_filter(array_map('trim', preg_split('/[;,]/', $deal['email'] ?? '')));

    $found = null;
    if ($contactId && isset($contactIdToClient[$contactId])) $found = $contactIdToClient[$contactId];
    if (!$found) { foreach ($phones as $p) { if (isset($phoneToClient[$p])) { $found = $phoneToClient[$p]; break; } } }
    if (!$found) { foreach ($emails as $e) { if (isset($emailToClient[$e])) { $found = $emailToClient[$e]; break; } } }

    if (!$found) { $found = $nextClientId++; $clients[$found] = []; }
    $clients[$found][] = $dealId;

    if ($contactId) $contactIdToClient[$contactId] = $found;
    foreach ($phones as $p) $phoneToClient[$p] = $found;
    foreach ($emails as $e) $emailToClient[$e] = $found;
}

echo count($clients) . " unique clients, Memory: " . round(memory_get_peak_usage(true)/1024/1024,1) . " MB\n";

$newIds = [];
$retIds = [];
foreach ($clients as $dids) {
    $newIds[] = $dids[0];
    for ($i=1; $i<count($dids); $i++) $retIds[] = $dids[$i];
}
echo "New: " . count($newIds) . ", Returning: " . count($retIds) . "\n";

foreach (array_chunk($newIds, 1000) as $chunk) {
    $pl = implode(',', array_fill(0, count($chunk), '?'));
    $db->execute("UPDATE crm_deals SET customer_type='new' WHERE deal_id IN ($pl)", $chunk);
}
foreach (array_chunk($retIds, 1000) as $chunk) {
    $pl = implode(',', array_fill(0, count($chunk), '?'));
    $db->execute("UPDATE crm_deals SET customer_type='returning' WHERE deal_id IN ($pl)", $chunk);
}

echo "Done in " . round(microtime(true)-$start,1) . "s\n";
$check = $db->fetchAll("SELECT customer_type, COUNT(*) as cnt FROM crm_deals GROUP BY customer_type");
foreach ($check as $r) echo ($r['customer_type'] ?? 'NULL') . ": " . $r['cnt'] . "\n";
