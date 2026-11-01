<?php
// tests/test_driver_isolation.php - Automated test suite for Driver View Isolation & Mobile Experience
define('CLI_MODE', true);

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
echo "🧪 Running Test Suite: Driver Account Isolation & UX\n";
echo "=========================================================\n\n";

// Find or pick a driver account
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'driver' AND deleted_at IS NULL LIMIT 1");
$driverUser = $stmt->fetch();

if (!$driverUser) {
    echo "⚠️ Warning: No driver found in DB. Selecting any active user...\n";
    $driverUser = $pdo->query("SELECT * FROM users LIMIT 1")->fetch();
}

$driverId = (int)$driverUser['id'];
echo "Testing with Driver ID: {$driverId} (" . ($driverUser['full_name'] ?? 'Driver') . ")\n\n";

// ---------------------------------------------------------------------------
// TEST 1: Driver Session sees strictly the 5 Authorized Modules + Logout
// ---------------------------------------------------------------------------
echo "--- TEST 1: Driver Modules Isolation in index.php ---\n";

$_SESSION = [
    'user_id' => $driverId,
    'user_role' => 'driver',
    'phone' => $driverUser['phone'] ?? '0660000000',
    'full_name' => $driverUser['full_name'] ?? 'سائق تجريبي',
    'wilaya' => 'أدرار'
];
$_SERVER['PHP_SELF'] = '/dashboard/index.php';
$_SERVER['SCRIPT_NAME'] = '/dashboard/index.php';
$_SERVER['REQUEST_URI'] = '/dashboard/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
try {
    include __DIR__ . '/../dashboard/index.php';
} catch (\Throwable $t) {
    // If output ended early
}
$driverIndexHtml = ob_get_clean();

// Check authorized modules exist
$hasMonthlyFee = strpos($driverIndexHtml, 'الدراهم والاشتراك الشهري') !== false;
$hasDebts = strpos($driverIndexHtml, 'الديون والوضعية المالية') !== false;
$hasDocs = strpos($driverIndexHtml, 'الوثائق وتواريخ الصلاحية') !== false;
$hasPlanning = strpos($driverIndexHtml, 'المناوبة الليلية وحراسة الحظيرة') !== false;
$hasNotifs = strpos($driverIndexHtml, 'مركز الإشعارات والتنبيهات') !== false;

assertTest("Driver sees 'الدراهم والاشتراك الشهري' module", $hasMonthlyFee);
assertTest("Driver sees 'الديون والوضعية المالية' module", $hasDebts);
assertTest("Driver sees 'الوثائق وتواريخ الصلاحية' module", $hasDocs);
assertTest("Driver sees 'المناوبة الليلية وحراسة الحظيرة' module", $hasPlanning);
assertTest("Driver sees 'مركز الإشعارات والتنبيهات' module", $hasNotifs);

// Verify Admin modules are NOT in the launcher cards
$hasVehiclesCard = preg_match('/data-module-id="vehicles"/', $driverIndexHtml);
$hasDriversCard = preg_match('/data-module-id="drivers"/', $driverIndexHtml);
$hasContractsCard = preg_match('/data-module-id="contracts"/', $driverIndexHtml);
$hasCnasCard = preg_match('/data-module-id="cnas"/', $driverIndexHtml);
$hasSlipsCard = preg_match('/data-module-id="slips"/', $driverIndexHtml);
$hasUsersCard = preg_match('/data-module-id="users"/', $driverIndexHtml);
$hasSettingsCard = preg_match('/data-module-id="settings"/', $driverIndexHtml);
$hasAuditLogsCard = preg_match('/data-module-id="audit_logs"/', $driverIndexHtml);
$hasArchiveCard = preg_match('/data-module-id="archive"/', $driverIndexHtml);

assertTest("Driver DOES NOT see Vehicles launcher card", !$hasVehiclesCard);
assertTest("Driver DOES NOT see Drivers launcher card", !$hasDriversCard);
assertTest("Driver DOES NOT see 90-11 Contracts launcher card", !$hasContractsCard);
assertTest("Driver DOES NOT see CNAS launcher card", !$hasCnasCard);
assertTest("Driver DOES NOT see Slips launcher card", !$hasSlipsCard);
assertTest("Driver DOES NOT see Users card", !$hasUsersCard);
assertTest("Driver DOES NOT see Settings card", !$hasSettingsCard);
assertTest("Driver DOES NOT see Audit Logs card", !$hasAuditLogsCard);
assertTest("Driver DOES NOT see Archive/Trash card", !$hasArchiveCard);

// ---------------------------------------------------------------------------
// TEST 2: Driver High-Priority Banners (Monthly Dues & Mobile Alerts)
// ---------------------------------------------------------------------------
echo "\n--- TEST 2: Driver Top Banner (شعار رانا نسالوه) & Mobile Alerts ---\n";

$hasDuesBanner = (strpos($driverIndexHtml, 'رانا نسالوك هذا الشهر') !== false) || (strpos($driverIndexHtml, 'حسابك مسوى ومريكل بالكامل') !== false);
assertTest("Driver sees Monthly Dues / Debts notice banner", $hasDuesBanner);

$hasMobileNotifAlert = (strpos($driverIndexHtml, 'driverMobileNotifBanner') !== false) || (strpos($driverIndexHtml, 'تنبيه جديد في هاتفك') !== false) || ($counts['notifications'] === 0);
assertTest("Driver mobile notification logic correctly present", $hasMobileNotifAlert);

// ---------------------------------------------------------------------------
// TEST 3: Navigation Header & Drawer Isolation for Driver
// ---------------------------------------------------------------------------
echo "\n--- TEST 3: Header Navigation & Mobile Drawer ---\n";

$hasDriverDirectLinks = strpos($driverIndexHtml, 'وثائقي وصلاحياتها') !== false && strpos($driverIndexHtml, 'الدراهم والديون') !== false;
$hasAdminDropdownNav = strpos($driverIndexHtml, 'إدارة الأسطول والمركبات') !== false || strpos($driverIndexHtml, 'قانون 90-11 والتصاريح') !== false;

assertTest("Header shows tailored driver navigation links", $hasDriverDirectLinks);
assertTest("Header DOES NOT show Admin dropdown menus to driver", !$hasAdminDropdownNav);

// ---------------------------------------------------------------------------
// TEST 4: Category Filtering Tailored for Driver
// ---------------------------------------------------------------------------
echo "\n--- TEST 4: Category Filter Select Options ---\n";

$hasDriverCatOptions = strpos($driverIndexHtml, 'كافة خدماتي') !== false;
$hasAdminCatFleet = strpos($driverIndexHtml, '🚗 الأسطول والمركبات') !== false;

assertTest("App bar filter has driver-tailored categories", $hasDriverCatOptions);
assertTest("App bar filter DOES NOT contain Admin categories for driver", !$hasAdminCatFleet);

// ---------------------------------------------------------------------------
// TEST 5: Route Guards for Driver Accessing Restricted URLs
// ---------------------------------------------------------------------------
echo "\n--- TEST 5: Direct Route Guards ---\n";

// A. Test vehicles.php
$vehiclesCode = file_get_contents(__DIR__ . '/../dashboard/vehicles.php');
$vehiclesHasDriverRedirect = strpos($vehiclesCode, 'if (is_driver())') !== false && strpos($vehiclesCode, 'driver_fiche.php?id=') !== false;
assertTest("dashboard/vehicles.php redirects driver to personal driver_fiche.php", $vehiclesHasDriverRedirect);

// B. Test traveler_reports.php
$travelerCode = file_get_contents(__DIR__ . '/../dashboard/traveler_reports.php');
$travelerHasDriverRedirect = strpos($travelerCode, 'if (is_driver())') !== false && strpos($travelerCode, 'dashboard/index.php') !== false;
assertTest("dashboard/traveler_reports.php guards driver and redirects to index.php", $travelerHasDriverRedirect);

// C. Test slips.php
$slipsCode = file_get_contents(__DIR__ . '/../dashboard/slips.php');
$slipsHasDriverRedirect = strpos($slipsCode, 'if (is_driver()') !== false && strpos($slipsCode, 'dashboard/index.php') !== false;
assertTest("dashboard/slips.php guards driver from managing slips", $slipsHasDriverRedirect);

// D. Test settings.php
$settingsCode = file_get_contents(__DIR__ . '/../dashboard/settings.php');
$settingsHasAdminCheck = strpos($settingsCode, 'require_admin()') !== false;
assertTest("dashboard/settings.php requires admin permissions", $settingsHasAdminCheck);

// E. Test planning.php driver tab
$planningCode = file_get_contents(__DIR__ . '/../dashboard/planning.php');
$planningDefaultsArchive = strpos($planningCode, "is_driver() ? 'archive' : 'generator'") !== false;
assertTest("dashboard/planning.php defaults driver to archive tab (not generator)", $planningDefaultsArchive);

// ---------------------------------------------------------------------------
// TEST 6: Admin View Sanity (No Regressions for Super Admin)
// ---------------------------------------------------------------------------
echo "\n--- TEST 6: Super Admin View Sanity & Full Access ---\n";

$phpBinary = PHP_BINARY ?: 'php';
$output = shell_exec("\"$phpBinary\" " . escapeshellarg(__DIR__ . '/test_admin_sanity.php') . " 2>&1");
$adminSanityPassed = (strpos($output, 'Admin view sanity passed!') !== false);

assertTest("Admin sees Vehicles module", strpos($output, 'Admin sees Vehicles: YES') !== false);
assertTest("Admin sees Drivers module", strpos($output, 'Admin sees Drivers: YES') !== false);
assertTest("Admin sees Contracts module", strpos($output, 'Admin sees Contracts: YES') !== false);
assertTest("Admin sees Settings module", strpos($output, 'Admin sees Settings: YES') !== false);
assertTest("Super Admin view sanity passes completely", $adminSanityPassed);

echo "\n=========================================================\n";
echo "📊 Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "=========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
