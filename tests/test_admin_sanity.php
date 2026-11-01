<?php
// tests/test_admin_sanity.php
define('CLI_MODE', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$_SESSION = [
    'user_id' => 1,
    'user_role' => 'super_admin',
    'phone' => '0661000000',
    'full_name' => 'المدير العام',
    'wilaya' => 'أدرار'
];
$_SERVER['REQUEST_URI'] = '/dashboard/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
try {
    include __DIR__ . '/../dashboard/index.php';
} catch (\Throwable $t) {
}
$adminIndexHtml = ob_get_clean();

$adminHasVehicles = strpos($adminIndexHtml, 'data-module-id="vehicles"') !== false;
$adminHasDrivers = strpos($adminIndexHtml, 'data-module-id="drivers"') !== false;
$adminHasContracts = strpos($adminIndexHtml, 'data-module-id="contracts"') !== false;
$adminHasSettings = strpos($adminIndexHtml, 'data-module-id="settings"') !== false;

echo "Admin sees Vehicles: " . ($adminHasVehicles ? "YES" : "NO") . "\n";
echo "Admin sees Drivers: " . ($adminHasDrivers ? "YES" : "NO") . "\n";
echo "Admin sees Contracts: " . ($adminHasContracts ? "YES" : "NO") . "\n";
echo "Admin sees Settings: " . ($adminHasSettings ? "YES" : "NO") . "\n";

if ($adminHasVehicles && $adminHasDrivers && $adminHasContracts && $adminHasSettings) {
    echo "✅ Admin view sanity passed!\n";
    exit(0);
} else {
    echo "❌ Admin view failed!\n";
    exit(1);
}

