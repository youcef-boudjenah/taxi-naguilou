<?php
// tests/test_debts_fk_reconciliation.php
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();
$passed = 0;
$failed = 0;

function it($condition, $description) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $description\n";
        $passed++;
    } else {
        echo "  [FAIL] $description\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "   TAXI NAGUILOU - DEBTS FK RECONCILIATION TEST         \n";
echo "========================================================\n\n";

// 1. Insert synthetic debts with invalid user_id values (0 and non-existent ID)
echo "1. Creating Synthetic Records with Invalid user_id...\n";
// Temporarily disable FK checks to simulate production orphaned state
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$stmt = $pdo->prepare("
    INSERT INTO driver_debts (user_id, chauffeur_name, phone, total_credits, total_versements, driver_type, status)
    VALUES (?, ?, '0600000000', 5000.00, 0.00, 'actif', 'endette')
");

$stmt->execute([0, 'سائق تجريبي صفر']);
$idZero = (int)$pdo->lastInsertId();

$stmt->execute([9999999, 'سائق تجريبي يتيم']);
$idOrphan = (int)$pdo->lastInsertId();

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

it($idZero > 0 && $idOrphan > 0, "Inserted synthetic test debts (ID $idZero with user_id=0, ID $idOrphan with user_id=9999999)");

// 2. Test Defensive Sanitization
echo "\n2. Testing Defensive Sanitization...\n";
$pdo->exec("UPDATE `driver_debts` SET `user_id` = NULL WHERE `user_id` = 0");
$pdo->exec("
    UPDATE `driver_debts` d
    LEFT JOIN `users` u ON d.`user_id` = u.`id`
    SET d.`user_id` = NULL
    WHERE d.`user_id` IS NOT NULL AND u.`id` IS NULL
");

$checkZero = $pdo->query("SELECT user_id FROM driver_debts WHERE id = $idZero")->fetchColumn();
$checkOrphan = $pdo->query("SELECT user_id FROM driver_debts WHERE id = $idOrphan")->fetchColumn();

it($checkZero === null, "Debt with user_id=0 was sanitized to NULL");
it($checkOrphan === null, "Debt with orphaned user_id=9999999 was sanitized to NULL");

// 3. Test Recalculation Flow
echo "\n3. Testing Recalculation Flow on Sanitized Records...\n";
$debtsList = $pdo->query("SELECT id, total_credits FROM driver_debts WHERE id IN ($idZero, $idOrphan)")->fetchAll();
$successCount = 0;

$pdo->beginTransaction();
foreach ($debtsList as $dbItem) {
    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_v, MAX(payment_date) as last_v FROM versements WHERE debt_id = ?");
    $sumStmt->execute([$dbItem['id']]);
    $sumData = $sumStmt->fetch();
    $totalV = (float)($sumData['total_v'] ?? 0);
    $lastV = $sumData['last_v'];
    $st = (($dbItem['total_credits'] - $totalV) <= 0) ? 'a_jour' : 'endette';

    try {
        $upStmt = $pdo->prepare("UPDATE driver_debts SET total_versements = ?, last_versement_date = ?, status = ? WHERE id = ?");
        $upStmt->execute([$totalV, $lastV, $st, $dbItem['id']]);
        $successCount++;
    } catch (\PDOException $e) {
        echo "Error on ID {$dbItem['id']}: " . $e->getMessage() . "\n";
    }
}
$pdo->commit();

it($successCount === 2, "Recalculation executed cleanly on both records without FK constraint violation");

// Cleanup
$pdo->exec("DELETE FROM driver_debts WHERE id IN ($idZero, $idOrphan)");
echo "\nCleaned up synthetic test records.\n";

echo "========================================================\n";
echo "TEST RESULTS: PASS: $passed | FAIL: $failed\n";
echo "========================================================\n";

if ($failed > 0) exit(1);

