<?php
// api/cron_penalties.php - Headless CLI / Scheduled Automated Penalties Runner
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . (getDBLastError() ?? 'unknown')]);
    exit;
}

$isCli = (php_sapi_name() === 'cli');
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) || (PHP_OS_FAMILY === 'Windows');

// Security check for web requests
if (!$isCli && !$isLocal) {
    session_start();
    $isAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'admin']);
    $cronKey = $_GET['secret'] ?? $_POST['secret'] ?? '';
    $expectedKey = 'taxinaguilou_cron_2026';
    if (!$isAdmin && $cronKey !== $expectedKey) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized cron access']);
        exit;
    }
}

$force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');
if ($isCli) {
    global $argv;
    if (isset($argv) && in_array('--force', $argv)) {
        $force = true;
    }
}

$res = apply_automated_debt_penalties($pdo, $force);

if ($isCli) {
    echo "=== TAXI NAGUILOU AUTOMATED DEBT PENALTIES ===\n";
    echo "Status: " . ($res['success'] ? "SUCCESS" : "FAILED") . "\n";
    echo "Message: " . $res['message'] . "\n";
    echo "Checked Debts: " . $res['debts_checked'] . "\n";
    echo "Penalized Debts: " . $res['debts_penalized'] . "\n";
    echo "Penalties Applied: " . $res['penalties_applied'] . "\n";
    echo "Total Amount Added: " . number_format($res['total_amount'], 2) . " DZD\n";
    exit($res['success'] ? 0 : 1);
}

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

