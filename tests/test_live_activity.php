<?php
// tests/test_live_activity.php - Tests for Live Bottom-Left Notifications
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();
if (!$pdo) {
    echo "FAILED: Cannot connect to database\n";
    exit(1);
}

$pass = 0;
$fail = 0;
function test_assert(string $msg, bool $cond) {
    global $pass, $fail;
    if ($cond) {
        echo "  [PASS] $msg\n";
        $pass++;
    } else {
        echo "  [FAIL] $msg\n";
        $fail++;
    }
}

function callAjaxLive(int $sinceId): array {
    $php = 'C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe';
    $script = __DIR__ . '/../dashboard/ajax_live_activity.php';
    $cmd = "\"$php\" -r \"\$_GET['since_id'] = $sinceId; require '$script';\"";
    $out = shell_exec($cmd);
    // extract json
    if (preg_match('/\{.*\}/s', $out, $matches)) {
        return json_decode($matches[0], true) ?: [];
    }
    return [];
}

echo "========================================================\n";
echo "   TAXI NAGUILOU - LIVE BOTTOM-LEFT TOAST SYSTEM TEST    \n";
echo "========================================================\n\n";

// 1. Initial Cursor Test
echo "1. Testing Initial Cursor Sync...\n";
$json1 = callAjaxLive(0);

test_assert("Endpoint returns success: true", !empty($json1['success']));
test_assert("Endpoint returns current_max_id", isset($json1['current_max_id']));
test_assert("Initial sync returns empty events array", empty($json1['events']));
$baseCursor = (int)($json1['current_max_id'] ?? 0);

// 2. Insert Operations for Driver, Client, Admin, System
echo "\n2. Testing Multi-Actor Activity Logging...\n";

// Action 1: Driver
log_audit($pdo, 'payment_submitted', 'versements', 999, 'تسجيل دفعة بريدي موب بمبلغ 30,000 دج للسائق مراد بن صالح');
$driverLogId = (int)$pdo->lastInsertId();

// Action 2: Client
log_audit($pdo, 'client_lead_submitted', 'leads', 888, 'طلب انضمام جديد من العميل سمير بوزيان (0661223344 - وهران)');
$clientLogId = (int)$pdo->lastInsertId();

// Action 3: Admin
log_audit($pdo, 'versement_approved', 'versements', 999, 'تأكيد واعتماد دفعة بريدي موب بمبلغ 30,000 دج');
$adminLogId = (int)$pdo->lastInsertId();

// Action 4: Passenger / Client Rating
log_audit($pdo, 'passenger_rating_submitted', 'driver_ratings', 777, 'تقييم راكب جديد (5/5) لمركبة الأجرة A-12');
$passengerLogId = (int)$pdo->lastInsertId();

// 3. Query with since_id = $baseCursor
echo "\n3. Testing Retrieval & Correct Actor Classification...\n";
$json2 = callAjaxLive($baseCursor);

test_assert("Endpoint returns new events", !empty($json2['events']) && count($json2['events']) >= 4);

$events = $json2['events'] ?? [];
$evDriver = null;
$evClient = null;
$evAdmin = null;
$evRating = null;

foreach ($events as $ev) {
    if ($ev['id'] === $driverLogId) $evDriver = $ev;
    if ($ev['id'] === $clientLogId) $evClient = $ev;
    if ($ev['id'] === $adminLogId) $evAdmin = $ev;
    if ($ev['id'] === $passengerLogId) $evRating = $ev;
}

test_assert("Driver event classified as 'driver'", $evDriver && $evDriver['actor_type'] === 'driver');
test_assert("Driver event has badge '🚗 سائق'", $evDriver && str_contains($evDriver['actor_badge'], 'سائق'));
test_assert("Driver event has debts link", $evDriver && str_contains($evDriver['link'], 'debts.php'));

test_assert("Client event classified as 'client'", $evClient && $evClient['actor_type'] === 'client');
test_assert("Client event has badge '👤 عميل'", $evClient && str_contains($evClient['actor_badge'], 'عميل'));

test_assert("Passenger rating classified as 'client'", $evRating && $evRating['actor_type'] === 'client');

test_assert("Admin event classified as 'admin'", $evAdmin && $evAdmin['actor_type'] === 'admin');
test_assert("Admin event has badge '🛡️'", $evAdmin && (str_contains($evAdmin['actor_badge'], 'إدارة') || str_contains($evAdmin['actor_badge'], 'مسؤول')));

// Clean up synthetic test audit logs
$testIds = [$driverLogId, $clientLogId, $adminLogId, $passengerLogId];
$pdo->exec("DELETE FROM audit_logs WHERE id IN (" . implode(',', $testIds) . ")");
echo "\nCleaned up synthetic test logs.\n";

echo "========================================================\n";
echo "TEST RESULTS: PASS: $pass | FAIL: $fail\n";
echo "========================================================\n";

exit($fail === 0 ? 0 : 1);
