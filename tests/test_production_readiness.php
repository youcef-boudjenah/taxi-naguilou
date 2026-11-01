<?php
// tests/test_production_readiness.php - Automated Production Readiness Verification Suite
define('CLI_MODE', true);
define('TEST_MODE', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = getDBConnection();
if (!$pdo) {
    echo "❌ FATAL: Database connection failed.\n";
    exit(1);
}

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $description, bool $condition, string $detail = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail ? " - {$detail}" : "") . "\n";
        $testsFailed++;
    }
}

echo "=========================================================\n";
echo "🚀 Running Test Suite: Production Readiness Verification\n";
echo "=========================================================\n\n";

// ---------------------------------------------------------------------------
// TEST 1: Environment & .env Configuration
// ---------------------------------------------------------------------------
echo "--- TEST 1: Environment & .env Configuration ---\n";
assertTest("APP_ENV is defined and valid", defined('APP_ENV') && in_array(APP_ENV, ['production', 'development', 'testing']));
assertTest("APP_DEBUG is defined as boolean", defined('APP_DEBUG') && is_bool(APP_DEBUG));
assertTest(".env.example template exists", file_exists(__DIR__ . '/../.env.example'));

$envExampleContent = file_get_contents(__DIR__ . '/../.env.example');
assertTest(".env.example contains DB_HOST", strpos($envExampleContent, 'DB_HOST') !== false);
assertTest(".env.example contains APP_ENV", strpos($envExampleContent, 'APP_ENV') !== false);
assertTest(".env.example contains CRON_SECRET", strpos($envExampleContent, 'CRON_SECRET') !== false);

// ---------------------------------------------------------------------------
// TEST 2: Session Security & Cookie Hardening
// ---------------------------------------------------------------------------
echo "\n--- TEST 2: Session Security & Cookie Hardening ---\n";
$cookieParams = session_get_cookie_params();
assertTest("Session cookies have HttpOnly enabled", $cookieParams['httponly'] === true || ini_get('session.cookie_httponly') == '1');
assertTest("Session cookies have SameSite set", !empty($cookieParams['samesite']));
assertTest("Session strictly uses cookies only", ini_get('session.use_only_cookies') == '1');
assertTest("Session strict mode enabled", ini_get('session.use_strict_mode') == '1');

// ---------------------------------------------------------------------------
// TEST 3: Web Server Apache Hardening (.htaccess)
// ---------------------------------------------------------------------------
echo "\n--- TEST 3: Web Server Apache Hardening (.htaccess) ---\n";
$rootHtaccess = file_get_contents(__DIR__ . '/../.htaccess');
assertTest("Root .htaccess disables directory browsing (Options -Indexes)", strpos($rootHtaccess, 'Options -Indexes') !== false);
assertTest("Root .htaccess blocks .env files", strpos($rootHtaccess, '\.env') !== false);
assertTest("Root .htaccess blocks .sql database dumps", strpos($rootHtaccess, '\.sql') !== false);
assertTest("Root .htaccess blocks .log files", strpos($rootHtaccess, '\.log') !== false);
assertTest("Root .htaccess blocks install.lock & install.bat", strpos($rootHtaccess, 'install\.lock') !== false);
assertTest("Root .htaccess maps ErrorDocument 403, 404, 500", strpos($rootHtaccess, 'ErrorDocument 404') !== false);
assertTest("Root .htaccess sends X-Content-Type-Options: nosniff", strpos($rootHtaccess, 'X-Content-Type-Options') !== false);
assertTest("Root .htaccess sends X-Frame-Options: SAMEORIGIN", strpos($rootHtaccess, 'X-Frame-Options') !== false);
assertTest("Root .htaccess enables GZIP compression (mod_deflate)", strpos($rootHtaccess, 'mod_deflate') !== false);

// ---------------------------------------------------------------------------
// TEST 4: Uploads & Storage Protection
// ---------------------------------------------------------------------------
echo "\n--- TEST 4: Uploads & Storage Protection ---\n";
assertTest("uploads/.htaccess exists", file_exists(__DIR__ . '/../uploads/.htaccess'));
$uploadsHtaccess = file_get_contents(__DIR__ . '/../uploads/.htaccess');
assertTest("uploads/.htaccess turns off PHP engine", strpos($uploadsHtaccess, 'php_flag engine off') !== false);
assertTest("uploads/.htaccess denies executable files (.php, .phtml, .cgi, etc.)", strpos($uploadsHtaccess, 'Require all denied') !== false);

assertTest("storage/.htaccess exists", file_exists(__DIR__ . '/../storage/.htaccess'));
$storageHtaccess = file_get_contents(__DIR__ . '/../storage/.htaccess');
assertTest("storage/.htaccess denies all direct web access", strpos($storageHtaccess, 'Require all denied') !== false);

// ---------------------------------------------------------------------------
// TEST 5: Health Check Monitoring Endpoint (api/health.php)
// ---------------------------------------------------------------------------
echo "\n--- TEST 5: Health Check Monitoring Endpoint (api/health.php) ---\n";
assertTest("api/health.php exists", file_exists(__DIR__ . '/../api/health.php'));

ob_start();
require __DIR__ . '/../api/health.php';
$healthJson = ob_get_clean();
$healthData = json_decode($healthJson, true);

assertTest("api/health.php returns valid JSON", is_array($healthData));
assertTest("api/health.php reports status 'healthy'", ($healthData['status'] ?? '') === 'healthy');
assertTest("api/health.php checks database connectivity", ($healthData['checks']['database']['status'] ?? '') === 'up');
assertTest("api/health.php measures database latency", isset($healthData['checks']['database']['latency_ms']));
assertTest("api/health.php checks storage write permissions", ($healthData['checks']['storage']['status'] ?? '') === 'healthy');

// ---------------------------------------------------------------------------
// TEST 6: Automated Backup & Maintenance Engine (scripts/backup_db.php)
// ---------------------------------------------------------------------------
echo "\n--- TEST 6: Automated Backup & Maintenance Engine ---\n";
assertTest("scripts/backup_db.php exists", file_exists(__DIR__ . '/../scripts/backup_db.php'));

$backupsBefore = glob(__DIR__ . '/../storage/backups/backup_taxi_naguilou_*.sql*');
$countBefore = count($backupsBefore);

ob_start();
require __DIR__ . '/../scripts/backup_db.php';
$backupOutput = ob_get_clean();

$backupsAfter = glob(__DIR__ . '/../storage/backups/backup_taxi_naguilou_*.sql*');
assertTest("scripts/backup_db.php generates database backup file", count($backupsAfter) > 0);

$latestBackup = end($backupsAfter);
assertTest("Backup file is non-empty (> 10 KB)", filesize($latestBackup) > 10240);
assertTest("Backup logs entry to storage/logs/backups.log", file_exists(__DIR__ . '/../storage/logs/backups.log'));

// ---------------------------------------------------------------------------
// TEST 7: Branded Custom Error Pages (403, 404, 500)
// ---------------------------------------------------------------------------
echo "\n--- TEST 7: Custom Production Error Pages ---\n";
assertTest("403.php exists", file_exists(__DIR__ . '/../403.php'));
assertTest("404.php exists", file_exists(__DIR__ . '/../404.php'));
assertTest("500.php exists", file_exists(__DIR__ . '/../500.php'));

$err404 = file_get_contents(__DIR__ . '/../404.php');
assertTest("404.php contains link back to dashboard", strpos($err404, 'dashboard/index.php') !== false);
assertTest("404.php uses Naguilou design shell", strpos($err404, 'tailwind.min.css') !== false);

// ---------------------------------------------------------------------------
// TEST 8: Database Performance Composite Indexes
// ---------------------------------------------------------------------------
echo "\n--- TEST 8: Database Performance Composite Indexes ---\n";
ensure_db_indexes($pdo);

function checkIndexExists(PDO $pdo, string $table, string $indexName): bool {
    try {
        $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
        return (bool)$stmt->fetch();
    } catch (\Throwable $e) {
        return false;
    }
}

assertTest("users has index idx_users_role_status", checkIndexExists($pdo, 'users', 'idx_users_role_status'));
assertTest("vehicles has index idx_veh_status", checkIndexExists($pdo, 'vehicles', 'idx_veh_status'));
assertTest("contracts has index idx_contracts_driver_status", checkIndexExists($pdo, 'contracts', 'idx_contracts_driver_status'));
assertTest("driver_debts has index idx_debts_user_status", checkIndexExists($pdo, 'driver_debts', 'idx_debts_user_status'));
assertTest("billings has index idx_billings_status_created", checkIndexExists($pdo, 'billings', 'idx_billings_status_created'));
assertTest("driver_subscriptions has index idx_sub_driver_period", checkIndexExists($pdo, 'driver_subscriptions', 'idx_sub_driver_period'));
assertTest("night_watch has index idx_nw_entry_status", checkIndexExists($pdo, 'night_watch', 'idx_nw_entry_status'));
assertTest("transmission_slips has index idx_slips_status_issue", checkIndexExists($pdo, 'transmission_slips', 'idx_slips_status_issue'));
assertTest("audit_logs has index idx_audit_user_created", checkIndexExists($pdo, 'audit_logs', 'idx_audit_user_created'));

echo "\n=========================================================\n";
echo "📊 Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "=========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
