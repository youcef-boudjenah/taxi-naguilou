<?php
// tests/test_vehicle_sticker.php - Automated test for Pure Barcode & Sticker Printing
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
echo "🧪 Running Test Suite: Pure Barcode & Decal Sticker Printing\n";
echo "=========================================================\n\n";

// 1. Pick an active vehicle
$vehicle = $pdo->query("SELECT * FROM vehicles WHERE deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$vehicle) {
    echo "⚠️ No vehicle found for testing.\n";
    exit(1);
}

$vehicleId = (int)$vehicle['id'];
echo "Testing with Vehicle ID: {$vehicleId} (Door: " . ($vehicle['door_number'] ?? '—') . ")\n\n";

// 2. Test rendering in default barcode-only mode
$_SESSION = [
    'user_id' => 1,
    'user_role' => 'super_admin',
    'phone' => '0663550042',
    'full_name' => 'مدير المنصة',
    'wilaya' => 'أدرار'
];
$_GET['id'] = $vehicleId;
unset($_GET['mode']);
$_SERVER['REQUEST_URI'] = "/dashboard/vehicle_sticker.php?id={$vehicleId}";

ob_start();
try {
    include __DIR__ . '/../dashboard/vehicle_sticker.php';
} catch (\Throwable $t) {}
$stickerHtml = ob_get_clean();

assertTest("vehicle_sticker.php renders non-empty HTML", strlen($stickerHtml) > 500);
assertTest("Default body class is mode-barcode-only", strpos($stickerHtml, 'mode-barcode-only') !== false);
assertTest("HTML contains QR code container #stickerQrContainer", strpos($stickerHtml, 'id="stickerQrContainer"') !== false);
assertTest("HTML contains Barcode SVG #stickerBarcodeSvg", strpos($stickerHtml, 'id="stickerBarcodeSvg"') !== false);
assertTest("Administrative header has .hide-on-barcode-only class", strpos($stickerHtml, 'hide-on-barcode-only bg-gradient-to-r') !== false);
assertTest("Details box has .hide-on-barcode-only class", strpos($stickerHtml, 'hide-on-barcode-only p-3.5') !== false);
assertTest("Instruction labels have .hide-on-barcode-only class", strpos($stickerHtml, 'hide-on-barcode-only text-[10px]') !== false);
assertTest("Contains mode switch button for Barcode Only", strpos($stickerHtml, 'btnModeBarcodeOnly') !== false);
assertTest("Contains mode switch button for QR Only", strpos($stickerHtml, 'btnModeQrOnly') !== false);
assertTest("Contains mode switch button for Linear Only", strpos($stickerHtml, 'btnModeLinearOnly') !== false);
assertTest("Contains mode switch button for Full Decal", strpos($stickerHtml, 'btnModeFull') !== false);
assertTest("Contains auto-print trigger for ?print=1", strpos($stickerHtml, "urlParams.get('print') === '1'") !== false);

echo "\n=========================================================\n";
echo "📊 Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "=========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);

