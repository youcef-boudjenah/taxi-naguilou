<?php
// tests/test_late_penalties.php - Comprehensive Unit & Integration Tests for Progressive Late Penalties
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();
if (!$pdo) {
    echo "FAILED: Cannot connect to database\n";
    exit(1);
}

$passCount = 0;
$failCount = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $name" . ($detail ? " ($detail)" : "") . "\n";
        $passCount++;
    } else {
        echo "  [FAIL] $name" . ($detail ? " ($detail)" : "") . "\n";
        $failCount++;
    }
}

echo "========================================================\n";
echo "       TAXI NAGUILOU - AUTOMATED LATE PENALTIES TEST     \n";
echo "========================================================\n\n";

// TEST 1: Schema & Migration
echo "1. Testing Schema & Migrations...\n";
migrateDebtsAndVersements($pdo);
$hasTable = !empty($pdo->query("SHOW TABLES LIKE 'debt_penalties'")->fetchAll());
assert_test("debt_penalties table exists", $hasTable);

$cols = $pdo->query("SHOW COLUMNS FROM debt_penalties")->fetchAll(PDO::FETCH_COLUMN);
assert_test("debt_penalties has required columns", in_array('penalty_month', $cols) && in_array('multiplier', $cols) && in_array('ref_date', $cols));

$debtCols = $pdo->query("SHOW COLUMNS FROM driver_debts LIKE 'total_penalties'")->fetchAll();
assert_test("driver_debts has total_penalties column", !empty($debtCols));

// TEST 2: Settings Configuration & Persistence
echo "\n2. Testing Settings Configuration...\n";
$stmtSet = $pdo->prepare("INSERT INTO settings (`key`, `value`, `category`) VALUES (?, ?, 'finance') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
$stmtSet->execute(['debt_penalty_3m', '6000']);
$stmtSet->execute(['debt_penalty_6m', '12000']); // Exactly double (2x)
$stmtSet->execute(['debt_penalty_enabled', '1']);
$stmtSet->execute(['debt_penalty_auto_apply', '1']);

$allSettings = get_all_settings($pdo);
assert_test("debt_penalty_3m saved correctly", (float)($allSettings['debt_penalty_3m'] ?? 0) === 6000.0);
assert_test("debt_penalty_6m is double (2x)", (float)($allSettings['debt_penalty_6m'] ?? 0) === 12000.0);
assert_test("debt_penalty_enabled is 1", ($allSettings['debt_penalty_enabled'] ?? '') === '1');
assert_test("debt_penalty_auto_apply is 1", ($allSettings['debt_penalty_auto_apply'] ?? '') === '1');

// TEST 3: Progressive Penalty Calculation Engine
echo "\n3. Testing Progressive Penalty Calculation Across Delay Periods...\n";

// Helper function to create test debt
function createTestDebt(PDO $pdo, int $daysAgo, float $initialCredit = 40000.0): int {
    $refDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $stmt = $pdo->prepare("
        INSERT INTO driver_debts (chauffeur_name, phone, total_credits, total_versements, driver_type, status, last_versement_date, notes)
        VALUES (?, '0555001122', ?, 0, 'actif', 'endette', ?, 'اختبار النظام')
    ");
    $stmt->execute(["سائق اختبار {$daysAgo} يوم", $initialCredit, $refDate]);
    return (int)$pdo->lastInsertId();
}

// Case A: 45 days delay (< 3 months) => 0 penalties
$debtA = createTestDebt($pdo, 45);
apply_automated_debt_penalties($pdo, true);
$penA = get_debt_penalties($pdo, $debtA);
assert_test("Grace period (< 90 days): 0 penalties", count($penA) === 0);

// Case B: 95 days delay (over 3 months, month 4 due) => 1 penalty of 6000 DZD (1x)
$debtB = createTestDebt($pdo, 95);
apply_automated_debt_penalties($pdo, true);
$penB = get_debt_penalties($pdo, $debtB);
assert_test("Delay 95 days (Month 4): 1 penalty applied", count($penB) === 1);
assert_test("Month 4 amount is base rate (6000 DZD)", isset($penB[0]) && (float)$penB[0]['amount'] === 6000.0);
assert_test("Month 4 multiplier is 1.0", isset($penB[0]) && (float)$penB[0]['multiplier'] === 1.0);

// Case C: 160 days delay (Month 4, 5, 6 due) => 3 penalties of 6000 DZD (total 18000 DZD)
$debtC = createTestDebt($pdo, 160);
apply_automated_debt_penalties($pdo, true);
$penC = get_debt_penalties($pdo, $debtC);
assert_test("Delay 160 days (Months 4, 5, 6): 3 penalties applied", count($penC) === 3);
$sumC = array_sum(array_column($penC, 'amount'));
assert_test("Total penalty for 160 days is 18,000 DZD", $sumC === 18000.0);

// Case D: 195 days delay (over 6 months, Month 7 due) => Month 4,5,6 at 6000 + Month 7 at 12000 (2x) = 30000 DZD!
$debtD = createTestDebt($pdo, 195);
apply_automated_debt_penalties($pdo, true);
$penD = get_debt_penalties($pdo, $debtD);
assert_test("Delay 195 days (Month 7+): 4 penalties applied", count($penD) === 4);
$month7 = array_values(array_filter($penD, fn($p) => (int)$p['penalty_month'] === 7))[0] ?? null;
assert_test("Month 7 is DOUBLE amount (12,000 DZD)", $month7 && (float)$month7['amount'] === 12000.0);
assert_test("Month 7 multiplier is 2.0 (Double)", $month7 && (float)$month7['multiplier'] === 2.0);
$sumD = array_sum(array_column($penD, 'amount'));
assert_test("Total penalty for 195 days is 30,000 DZD (18k + 12k)", $sumD === 30000.0);

// Case E: 225 days delay (Month 4,5,6 at 6000 + Month 7,8 at 12000) = 42000 DZD!
$debtE = createTestDebt($pdo, 225);
apply_automated_debt_penalties($pdo, true);
$penE = get_debt_penalties($pdo, $debtE);
assert_test("Delay 225 days (Month 8): 5 penalties applied", count($penE) === 5);
$sumE = array_sum(array_column($penE, 'amount'));
assert_test("Total penalty for 225 days is 42,000 DZD (3x6k + 2x12k)", $sumE === 42000.0);

// TEST 4: Idempotency & Duplicate Prevention
echo "\n4. Testing Idempotency (Running second time)...\n";
$run2 = apply_automated_debt_penalties($pdo, true);
assert_test("Second run applies 0 new penalties", $run2['penalties_applied'] === 0);
assert_test("Second run adds 0.00 DZD", (float)$run2['total_amount'] === 0.0);

// Check that test debts have balance and total_penalties updated correctly
$rowD = $pdo->query("SELECT total_credits, total_penalties FROM driver_debts WHERE id = $debtD")->fetch(PDO::FETCH_ASSOC);
assert_test("Driver D total_credits updated (40000 + 30000 = 70000 DZD)", (float)$rowD['total_credits'] === 70000.0);
assert_test("Driver D total_penalties matches (30000 DZD)", (float)$rowD['total_penalties'] === 30000.0);

// Clean up test debts and penalties
$testIds = [$debtA, $debtB, $debtC, $debtD, $debtE];
$inStr = implode(',', $testIds);
$pdo->exec("DELETE FROM debt_penalties WHERE debt_id IN ($inStr)");
$pdo->exec("DELETE FROM driver_debts WHERE id IN ($inStr)");
echo "\nCleaned up all synthetic test debts.\n";

echo "========================================================\n";
echo "TEST RESULTS: PASS: $passCount | FAIL: $failCount\n";
echo "========================================================\n";

exit($failCount === 0 ? 0 : 1);

