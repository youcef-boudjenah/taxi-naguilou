<?php
/**
 * Test Suite: Driver & Admin Notifications Isolation & Role Scoping
 * Run with: php tests/test_notifications_isolation.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = getDBConnection();
$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ PASS: $name\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: $name ($detail)\n";
        $failed++;
    }
}

echo "\n=======================================================\n";
echo "🧪 TESTING CHAUFFEUR & ADMIN NOTIFICATIONS ISOLATION\n";
echo "=======================================================\n\n";

// Find or create test driver and test admin
$stmtD = $pdo->query("SELECT id FROM users WHERE role = 'driver' AND deleted_at IS NULL LIMIT 1");
$driverId = (int)$stmtD->fetchColumn();

$stmtA = $pdo->query("SELECT id FROM users WHERE role IN ('super_admin', 'admin') AND deleted_at IS NULL LIMIT 1");
$adminId = (int)$stmtA->fetchColumn();

assert_test("Test Driver and Admin exist in DB", $driverId > 0 && $adminId > 0, "Driver: $driverId, Admin: $adminId");

// Clean any previous test artifacts
$pdo->prepare("DELETE FROM notifications WHERE title LIKE '[TEST_SUITE]%'")->execute();

// 1. Insert a Driver-specific Notification
$pdo->prepare("
    INSERT INTO notifications (user_id, target_role, type, title, message, is_read, created_at)
    VALUES (?, 'driver', 'info', '[TEST_SUITE] Driver Personal Alert', 'Your document is verified.', 0, NOW())
")->execute([$driverId]);
$driverNotifId = (int)$pdo->lastInsertId();

// 2. Insert an Admin-specific Fleet Notification
$pdo->prepare("
    INSERT INTO notifications (user_id, target_role, type, title, message, is_read, created_at)
    VALUES (?, 'admin', 'urgent', '[TEST_SUITE] Admin Fleet Inspection Alert', 'Fleet vehicle inspection needed.', 0, NOW())
")->execute([$adminId]);
$adminNotifId = (int)$pdo->lastInsertId();

// 3. Insert a Global Broadcast Notification
$pdo->prepare("
    INSERT INTO notifications (user_id, target_role, type, title, message, is_read, created_at)
    VALUES (NULL, 'all', 'info', '[TEST_SUITE] Company Broadcast', 'General company meeting tomorrow.', 0, NOW())
")->execute();
$broadcastNotifId = (int)$pdo->lastInsertId();

// 4. Insert an Obsolete Connection Log (Simulate legacy noise)
$pdo->prepare("
    INSERT INTO notifications (user_id, target_role, type, title, message, is_read, created_at)
    VALUES (?, 'all', 'info', 'Connexion Collaborateur', 'Collaborator connected to dashboard.', 0, NOW())
")->execute([$driverId]);
$noiseNotifId = (int)$pdo->lastInsertId();

// Test Unread Count Isolation for Driver
$driverUnread = getUnreadNotificationsCount($pdo, $driverId, 'driver');
$adminUnread = getUnreadNotificationsCount($pdo, $adminId, 'admin');

assert_test(
    "Driver unread count isolates personal + broadcast alerts and ignores legacy connection noise",
    $driverUnread >= 2,
    "Driver unread returned: $driverUnread"
);

assert_test(
    "Admin unread count includes admin alerts and broadcasts",
    $adminUnread >= 2,
    "Admin unread returned: $adminUnread"
);

// Test Recent Notifications Scoping
$driverRecent = getRecentNotifications($pdo, 20, $driverId, 'driver');
$driverRecentIds = array_column($driverRecent, 'id');

assert_test(
    "Driver recent feed contains driver personal notification",
    in_array($driverNotifId, $driverRecentIds)
);

assert_test(
    "Driver recent feed contains general broadcast notification",
    in_array($broadcastNotifId, $driverRecentIds)
);

assert_test(
    "Driver recent feed DOES NOT contain admin fleet notification (Strict Role Isolation)",
    !in_array($adminNotifId, $driverRecentIds),
    "Admin alert $adminNotifId found in driver feed"
);

assert_test(
    "Driver recent feed DOES NOT contain legacy connection logs",
    !in_array($noiseNotifId, $driverRecentIds)
);

// Test Admin Recent Notifications Feed
$adminRecent = getRecentNotifications($pdo, 20, $adminId, 'admin');
$adminRecentIds = array_column($adminRecent, 'id');

assert_test(
    "Admin recent feed contains admin fleet alert",
    in_array($adminNotifId, $adminRecentIds)
);

assert_test(
    "Admin recent feed contains company broadcast",
    in_array($broadcastNotifId, $adminRecentIds)
);

// Test Scoped Mark-As-Read Action
// When driver marks all as read, admin notification MUST remain unread
$pdo->prepare("
    UPDATE notifications 
    SET is_read = 1 
    WHERE user_id = ? OR (user_id IS NULL AND (target_role IS NULL OR target_role IN ('all', 'driver')))
")->execute([$driverId]);

$driverNotifRead = (int)$pdo->query("SELECT is_read FROM notifications WHERE id = $driverNotifId")->fetchColumn();
$adminNotifRead = (int)$pdo->query("SELECT is_read FROM notifications WHERE id = $adminNotifId")->fetchColumn();

assert_test(
    "Driver mark-all-read marks driver notification as read",
    $driverNotifRead === 1
);

assert_test(
    "Driver mark-all-read DOES NOT touch Admin notification (Admin alert remains unread)",
    $adminNotifRead === 0,
    "Admin alert was marked read: $adminNotifRead"
);

// Clean test artifacts
$pdo->prepare("DELETE FROM notifications WHERE id IN (?, ?, ?, ?)")->execute([$driverNotifId, $adminNotifId, $broadcastNotifId, $noiseNotifId]);

echo "\n-------------------------------------------------------\n";
echo "RESULTS: $passed PASSED, $failed FAILED\n";
echo "-------------------------------------------------------\n";

if ($failed === 0) {
    echo "🎉 ALL CHAUFFEUR & ADMIN NOTIFICATION TESTS PASSED!\n\n";
    exit(0);
} else {
    echo "⚠️ FAILURES DETECTED IN NOTIFICATION TESTS!\n\n";
    exit(1);
}

