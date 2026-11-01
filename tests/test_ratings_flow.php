<?php
// tests/test_ratings_flow.php - Automated test for Driver Rating submission & Detail Viewing flow
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
echo "🧪 Running Test Suite: Driver Ratings Notification & Details\n";
echo "=========================================================\n\n";

// 1. Pick a vehicle and driver
$vehicle = $pdo->query("SELECT v.*, u.full_name as driver_name FROM vehicles v LEFT JOIN users u ON u.id = v.current_driver_id WHERE v.deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$vehicle) {
    echo "⚠️ No vehicle found for testing.\n";
    exit(1);
}

$vehicleId = (int)$vehicle['id'];
$driverId = (int)($vehicle['current_driver_id'] ?? 0);
$doorNum = $vehicle['door_number'];
$driverName = $vehicle['driver_name'] ?: 'سائق تجريبي';

echo "Testing with Vehicle ID: {$vehicleId} (Door: {$doorNum}), Driver ID: {$driverId} ({$driverName})\n\n";

// 2. Simulate passenger rating submission in verify_vehicle.php
echo "--- TEST 1: Rating Submission & Notification Link Generation ---\n";

$rating = 5;
$cleanliness = 5;
$driving = 4;
$courtesy = 5;
$passengerName = 'عبد القادر بلمختار';
$passengerPhone = '0661234567';
$comment = 'سائق محترم جداً، قيادة هادئة وسيارة نظيفة ومعطرة. شكراً تاكسي نقيلو!';
$ip = '127.0.0.1';

$stmtRate = $pdo->prepare("
    INSERT INTO driver_ratings (vehicle_id, driver_id, rating, cleanliness_rating, driving_rating, courtesy_rating, passenger_name, passenger_phone, comment, ip_address, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmtRate->execute([$vehicleId, $driverId ?: null, $rating, $cleanliness, $driving, $courtesy, $passengerName, $passengerPhone, $comment, $ip]);
$newRatingId = (int)$pdo->lastInsertId();

assertTest("Rating row created in driver_ratings table", $newRatingId > 0, "Got ID: {$newRatingId}");

// Simulate notification logic as in verify_vehicle.php
$starsStr = str_repeat('⭐', $rating);
$notifTitle = "تقييم راكب جديد: $starsStr ($rating/5) للسائق $driverName";
$notifMsg = "تم تسجيل تقييم راكب جديد لسيارة الأجرة $doorNum ($starsStr) (من: $passengerName) • ملاحظة الراكب: \"$comment\"";
$notifLink = "/dashboard/ratings.php?id=" . $newRatingId;

$stmtN = $pdo->prepare("
    INSERT INTO notifications (type, title, message, link, is_read, created_at)
    VALUES ('success', ?, ?, ?, 0, NOW())
");
$stmtN->execute([$notifTitle, $notifMsg, $notifLink]);
$notifId = (int)$pdo->lastInsertId();

// Audit log
log_audit($pdo, 'passenger_rating_submitted', 'driver_ratings', $newRatingId, "تقييم راكب جديد ($rating/5) من $passengerName لمركبة الأجرة $doorNum");
$auditLogId = (int)$pdo->lastInsertId();

// Check notification row
$savedNotif = $pdo->query("SELECT * FROM notifications WHERE id = $notifId")->fetch(PDO::FETCH_ASSOC);
assertTest("Notification record contains link to ratings.php?id={$newRatingId}", strpos($savedNotif['link'], "/dashboard/ratings.php?id={$newRatingId}") !== false, "Saved link: " . ($savedNotif['link'] ?? ''));

// 3. Test Ajax Live Activity Notification Link
echo "\n--- TEST 2: Live Activity Toast Generation for Rating ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['since_id'] = $auditLogId - 1;

ob_start();
require __DIR__ . '/../dashboard/ajax_live_activity.php';
$ajaxOutput = ob_get_clean();
$ajaxData = json_decode($ajaxOutput, true);

assertTest("ajax_live_activity returns valid JSON", is_array($ajaxData) && ($ajaxData['success'] ?? false) === true);

$ratingEvent = null;
foreach ($ajaxData['events'] ?? [] as $ev) {
    if (strpos($ev['title'] ?? '', 'تقييم راكب') !== false || ($ev['actor_type'] ?? '') === 'client') {
        $ratingEvent = $ev;
        break;
    }
}

assertTest("Live activity event exists for rating", $ratingEvent !== null);
if ($ratingEvent) {
    assertTest("Live activity link points to ratings.php?id={$newRatingId}", strpos($ratingEvent['link'], "ratings.php?id={$newRatingId}") !== false, "Got link: " . ($ratingEvent['link'] ?? ''));
}

// 4. Test dashboard/ratings.php Rendering & Detail View
echo "\n--- TEST 3: dashboard/ratings.php View & Details ---\n";

$_SESSION = [
    'user_id' => 1,
    'user_role' => 'super_admin',
    'phone' => '0663550042',
    'full_name' => 'مدير المنصة',
    'wilaya' => 'أدرار'
];
$_GET['id'] = $newRatingId;
$_SERVER['REQUEST_URI'] = "/dashboard/ratings.php?id={$newRatingId}";

ob_start();
try {
    include __DIR__ . '/../dashboard/ratings.php';
} catch (\Throwable $t) {}
$ratingsHtml = ob_get_clean();

assertTest("ratings.php renders passenger name", strpos($ratingsHtml, $passengerName) !== false);
assertTest("ratings.php renders passenger phone", strpos($ratingsHtml, $passengerPhone) !== false);
assertTest("ratings.php renders passenger comment", strpos($ratingsHtml, $comment) !== false);
assertTest("ratings.php contains ratingDetailModal", strpos($ratingsHtml, 'id="ratingDetailModal"') !== false);
assertTest("ratings.php contains auto-open script for target ID", strpos($ratingsHtml, "rating-card-{$newRatingId}") !== false);

// 5. Test Driver View Isolation in ratings.php
echo "\n--- TEST 4: Driver Role Isolation in ratings.php ---\n";
$_SESSION = [
    'user_id' => $driverId ?: 210,
    'user_role' => 'driver',
    'phone' => '0660000000',
    'full_name' => $driverName,
    'wilaya' => 'أدرار'
];
unset($_GET['id']);
$_SERVER['REQUEST_URI'] = "/dashboard/ratings.php";

ob_start();
try {
    include __DIR__ . '/../dashboard/ratings.php';
} catch (\Throwable $t) {}
$driverRatingsHtml = ob_get_clean();

assertTest("Driver can access ratings.php without crash", strlen($driverRatingsHtml) > 500);
assertTest("Driver view does not contain admin delete form", strpos($driverRatingsHtml, 'name="delete_rating"') === false);

// 6. Clean up synthetic records
$pdo->prepare("DELETE FROM driver_ratings WHERE id = ?")->execute([$newRatingId]);
$pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$notifId]);
$pdo->prepare("DELETE FROM audit_logs WHERE action = 'passenger_rating_submitted' AND entity_id = ?")->execute([$newRatingId]);

echo "\nCleaned up synthetic test records.\n";
echo "=========================================================\n";
echo "📊 Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "=========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
