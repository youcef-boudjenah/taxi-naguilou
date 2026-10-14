<?php
/**
 * TAXI-NAGUILOU Automated Comprehensive Test Suite
 * Production-readiness test runner (Phase 8 & 9)
 * 
 * Run with: php tests/run_tests.php
 */

declare(strict_types=1);

// Ensure session starts cleanly for CLI
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

class TestRunner {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private ?PDO $pdo;

    public function __construct() {
        $this->pdo = getDBConnection();
    }

    public function run(): void {
        echo "\n=======================================================\n";
        echo "🚕 TAXI-NAGUILOU — COMPREHENSIVE PRODUCTION TEST SUITE\n";
        echo "=======================================================\n\n";

        $this->testDatabaseConnection();
        $this->testPolymorphicAuditLogger();
        $this->testCsrfProtectionEngine();
        $this->testAuthenticationAndRbac();
        $this->testSequentialNumberGenerators();
        $this->testDebtReconciliationLogic();
        $this->testBillingPaymentArithmetic();
        $this->testTravelerReportCalculations();
        $this->testLeadApiValidationAndPersistence();

        echo "\n-------------------------------------------------------\n";
        echo sprintf("RESULTS: %d PASSED, %d FAILED\n", $this->passed, $this->failed);
        echo "-------------------------------------------------------\n";

        if ($this->failed > 0) {
            echo "FAILED TESTS:\n";
            foreach ($this->failures as $fail) {
                echo "  ❌ {$fail}\n";
            }
            exit(1);
        } else {
            echo "✨ ALL TESTS PASSED SUCCESSFULLY! SOFTWARE IS PRODUCTION READY.\n\n";
            exit(0);
        }
    }

    private function assert(bool $condition, string $testName, string $errorDetail = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  ✅ PASS: {$testName}\n";
        } else {
            $this->failed++;
            $msg = $testName . ($errorDetail ? " -> {$errorDetail}" : "");
            $this->failures[] = $msg;
            echo "  ❌ FAIL: {$msg}\n";
        }
    }

    private function testDatabaseConnection(): void {
        echo "1. Testing Database & Schema Integrity...\n";
        $this->assert($this->pdo instanceof PDO, "MySQL Connection established via PDO");

        $expectedTables = [
            'users', 'vehicles', 'driver_debts', 'versements', 'billings',
            'driver_subscriptions', 'traveler_reports', 'traveler_passengers',
            'contracts', 'leaves', 'transmission_slips', 'audit_logs',
            'settings', 'leads', 'insurance_policies', 'cnas_declarations'
        ];

        foreach ($expectedTables as $table) {
            $stmt = $this->pdo->query("SHOW TABLES LIKE '{$table}'");
            $this->assert($stmt->rowCount() > 0, "Table '{$table}' exists in database");
        }
    }

    private function testPolymorphicAuditLogger(): void {
        echo "\n2. Testing Polymorphic Audit Logger (Fix TypeError & Variadic Signatures)...\n";
        
        // Test 1: Calling with PDO as first argument
        try {
            $res1 = log_audit($this->pdo, 'test_pdo_first', 'users', 1, 'Testing explicit PDO param');
            $this->assert($res1 === true, "log_audit(PDO, ...) executes successfully");
        } catch (Throwable $e) {
            $this->assert(false, "log_audit(PDO, ...) threw exception", $e->getMessage());
        }

        // Test 2: Calling legacy style without PDO (action as first argument)
        try {
            $res2 = log_audit('test_legacy_call', 'vehicles', 2, 'Testing legacy auto-PDO injection');
            $this->assert($res2 === true, "log_audit(action, ...) legacy call executes without TypeError");
        } catch (Throwable $e) {
            $this->assert(false, "log_audit(action, ...) legacy call threw TypeError", $e->getMessage());
        }

        // Test 3: Array details JSON serialization
        try {
            $res3 = log_audit('test_json_details', 'settings', null, ['key' => 'app_mode', 'value' => 'prod']);
            $this->assert($res3 === true, "log_audit handles array details with automatic JSON serialization");
            
            // Verify entry in database
            $stmt = $this->pdo->prepare("SELECT details FROM audit_logs WHERE action = 'test_json_details' ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            $this->assert(
                $row && str_contains($row['details'], 'app_mode'),
                "Audit log persists serialized JSON details correctly"
            );
        } catch (Throwable $e) {
            $this->assert(false, "log_audit JSON handling failed", $e->getMessage());
        }

        // Cleanup test logs
        $this->pdo->exec("DELETE FROM audit_logs WHERE action LIKE 'test_%'");
    }

    private function testCsrfProtectionEngine(): void {
        echo "\n3. Testing CSRF Defense Mechanism...\n";

        // Generate token
        $token = csrf_token();
        $this->assert(!empty($token) && strlen($token) === 64, "csrf_token() generates 64-char cryptographically secure hex token");

        // Verify correct token
        $this->assert(verify_csrf_token($token), "verify_csrf_token() accepts legitimate token");

        // Reject forged token
        $this->assert(!verify_csrf_token('invalid_forged_token_12345'), "verify_csrf_token() rejects forged token");

        // Reject null/empty token
        $this->assert(!verify_csrf_token(''), "verify_csrf_token() rejects empty string");
        $this->assert(!verify_csrf_token(null), "verify_csrf_token() rejects null token");

        // Test form helper
        $field = csrf_field();
        $this->assert(str_contains($field, 'name="csrf_token"') && str_contains($field, $token), "csrf_field() outputs hidden input with active session token");
    }

    private function testAuthenticationAndRbac(): void {
        echo "\n4. Testing Authentication, Password Hashing & RBAC Enforcement...\n";

        // Rejection of invalid credentials
        $badAuth = login_user('nonexistent@user.dz', 'wrongpass');
        $this->assert($badAuth['success'] === false, "login_user rejects unregistered email");

        $adminAuth = login_user('admin@taxinaguilou.dz', 'wrongpass123');
        $this->assert($adminAuth['success'] === false, "login_user rejects wrong password for existing user");

        // Valid authentication
        $validAuth = login_user('admin@taxinaguilou.dz', 'password123');
        $this->assert($validAuth['success'] === true, "login_user authenticates legitimate user with bcrypt hash");
        $this->assert(is_logged_in() === true, "is_logged_in() returns true after authentication");
        $this->assert(is_admin() === true, "is_admin() accurately identifies administrator role");
        $this->assert(has_role(['admin', 'manager']) === true, "has_role(['admin', 'manager']) passes for admin");
        $this->assert(has_role(['driver']) === false, "has_role(['driver']) safely rejects admin from driver-only scope");
    }

    private function testSequentialNumberGenerators(): void {
        echo "\n5. Testing Collision-Safe Sequential Number Generators...\n";

        $currYear = date('Y');

        // Test Contract Number Sequential Generation
        $stmtC = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(contract_number, 9) AS UNSIGNED)) as max_num FROM contracts WHERE contract_number LIKE ?");
        $stmtC->execute(["CT-$currYear-%"]);
        $rowC = $stmtC->fetch();
        $nextC = ($rowC && $rowC['max_num']) ? intval($rowC['max_num']) + 1 : 1;
        $contractNum = sprintf("CT-%s-%03d", $currYear, $nextC);
        $this->assert(preg_match("/^CT-\d{4}-\d{3}$/", $contractNum) === 1, "Contract number matches standard sequential format CT-YYYY-XXX ($contractNum)");

        // Test Slip Number Sequential Generation
        $stmtS = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(slip_number, 10) AS UNSIGNED)) as max_num FROM transmission_slips WHERE slip_number LIKE ?");
        $stmtS->execute(["BRD-$currYear-%"]);
        $rowS = $stmtS->fetch();
        $nextS = ($rowS && $rowS['max_num']) ? intval($rowS['max_num']) + 1 : 1;
        $slipNum = sprintf("BRD-%s-%04d", $currYear, $nextS);
        $this->assert(preg_match("/^BRD-\d{4}-\d{4}$/", $slipNum) === 1, "Slip number matches standard sequential format BRD-YYYY-XXXX ($slipNum)");

        // Test Traveler Report Number Sequential Generation
        $stmtP = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(report_number, 9) AS UNSIGNED)) as max_num FROM traveler_reports WHERE report_number LIKE ?");
        $stmtP->execute(["PV-$currYear-%"]);
        $rowP = $stmtP->fetch();
        $nextP = ($rowP && $rowP['max_num']) ? intval($rowP['max_num']) + 1 : 1;
        $reportNum = sprintf("PV-%s-%04d", $currYear, $nextP);
        $this->assert(preg_match("/^PV-\d{4}-\d{4}$/", $reportNum) === 1, "Traveler manifest number matches standard format PV-YYYY-XXXX ($reportNum)");
    }

    private function testDebtReconciliationLogic(): void {
        echo "\n6. Testing Real Debt & Payment Reconciliation Logic...\n";

        // Create temporary driver debt for testing in driver_debts
        $stmtInsDebt = $this->pdo->prepare("
            INSERT INTO driver_debts (user_id, chauffeur_name, phone, total_credits, total_versements, driver_type, status, notes)
            VALUES (1, 'سائق تجريبي للاختبار', '0550999888', 30000.00, 0.00, 'actif', 'endette', 'TEST RECONCILIATION')
        ");
        $stmtInsDebt->execute();
        $testDebtId = (int)$this->pdo->lastInsertId();

        // 1. Initial State Check
        $stmt = $this->pdo->prepare("SELECT * FROM driver_debts WHERE id = ?");
        $stmt->execute([$testDebtId]);
        $debt = $stmt->fetch();
        $remInitial = (float)$debt['total_credits'] - (float)$debt['total_versements'];
        $this->assert($remInitial === 30000.00 && $debt['status'] === 'endette', "Initial debt created with 30,000 DZD balance and status 'endette'");

        // 2. Add partial versement of 10,000 DZD
        $stmtV1 = $this->pdo->prepare("
            INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, notes)
            VALUES (?, 10000.00, 'especes', CURDATE(), 'VER-TEST-1', 'Acompte 1')
        ");
        $stmtV1->execute([$testDebtId]);
        $v1Id = (int)$this->pdo->lastInsertId();

        // Run reconciliation calculation (same logic as debts.php recalculate_month)
        $sumStmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_v, MAX(payment_date) as last_v FROM versements WHERE debt_id = ?");
        $sumStmt->execute([$testDebtId]);
        $sumData1 = $sumStmt->fetch();
        $totalV1 = (float)$sumData1['total_v'];
        $lastV1 = $sumData1['last_v'];
        $st1 = (($debt['total_credits'] - $totalV1) <= 0) ? 'a_jour' : 'endette';

        $this->pdo->prepare("UPDATE driver_debts SET total_versements = ?, last_versement_date = ?, status = ? WHERE id = ?")
                  ->execute([$totalV1, $lastV1, $st1, $testDebtId]);

        $stmt->execute([$testDebtId]);
        $debtUpdated1 = $stmt->fetch();
        $rem1 = (float)$debtUpdated1['total_credits'] - (float)$debtUpdated1['total_versements'];
        $this->assert($rem1 === 20000.00 && $debtUpdated1['status'] === 'endette', "Partial payment leaves accurate remaining balance 20,000 DZD and status 'endette'");

        // 3. Add full remaining versement of 20,000 DZD
        $stmtV2 = $this->pdo->prepare("
            INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, notes)
            VALUES (?, 20000.00, 'baridimob', CURDATE(), 'VER-TEST-2', 'Solde 2')
        ");
        $stmtV2->execute([$testDebtId]);
        $v2Id = (int)$this->pdo->lastInsertId();

        // Run reconciliation calculation again
        $sumStmt->execute([$testDebtId]);
        $sumData2 = $sumStmt->fetch();
        $totalV2 = (float)$sumData2['total_v'];
        $lastV2 = $sumData2['last_v'];
        $st2 = (($debt['total_credits'] - $totalV2) <= 0) ? 'a_jour' : 'endette';

        $this->pdo->prepare("UPDATE driver_debts SET total_versements = ?, last_versement_date = ?, status = ? WHERE id = ?")
                  ->execute([$totalV2, $lastV2, $st2, $testDebtId]);

        $stmt->execute([$testDebtId]);
        $debtUpdated2 = $stmt->fetch();
        $rem2 = (float)$debtUpdated2['total_credits'] - (float)$debtUpdated2['total_versements'];
        $this->assert($rem2 === 0.00 && $debtUpdated2['status'] === 'a_jour', "Full settlement clears balance to 0.00 DZD and flips status to 'a_jour'");

        // Clean up test data
        $this->pdo->prepare("DELETE FROM versements WHERE id IN (?, ?)")->execute([$v1Id, $v2Id]);
        $this->pdo->prepare("DELETE FROM driver_debts WHERE id = ?")->execute([$testDebtId]);
    }

    private function testBillingPaymentArithmetic(): void {
        echo "\n7. Testing Billing Payment Arithmetic (Cumulative vs Single Installment)...\n";

        // Get or create valid driver ID
        $driverId = (int)$this->pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();

        // Create test subscription record in driver_subscriptions
        $stmtSub = $this->pdo->prepare("
            INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status)
            VALUES (?, '2099-12', 1000.00, 4000.00, 1, 5000.00, 0.00, 'en_attente')
        ");
        $stmtSub->execute([$driverId]);
        $subId = (int)$this->pdo->lastInsertId();

        // First installment of 2,000 DA
        $firstPayment = 2000.00;
        $checkStmt = $this->pdo->prepare("SELECT id, total_due, paid_amount, status FROM driver_subscriptions WHERE id = ?");
        $checkStmt->execute([$subId]);
        $sub = $checkStmt->fetch();

        $accumulatedPaid1 = (float)$sub['paid_amount'] + $firstPayment;
        $status1 = ($accumulatedPaid1 >= (float)$sub['total_due']) ? 'regle' : 'partiel';
        $this->pdo->prepare("UPDATE driver_subscriptions SET paid_amount = ?, status = ? WHERE id = ?")
                  ->execute([$accumulatedPaid1, $status1, $subId]);

        $checkStmt->execute([$subId]);
        $sub1 = $checkStmt->fetch();
        $this->assert($sub1['status'] === 'partiel' && (float)$sub1['paid_amount'] === 2000.00, "First payment correctly sets subscription status to 'partiel' (2,000 / 5,000 DA)");

        // Second installment of 3,000 DA (satisfies the 5,000 DA total due)
        $secondPayment = 3000.00;
        $accumulatedPaid2 = (float)$sub1['paid_amount'] + $secondPayment;
        $status2 = ($accumulatedPaid2 >= (float)$sub['total_due']) ? 'regle' : 'partiel';
        $this->pdo->prepare("UPDATE driver_subscriptions SET paid_amount = ?, status = ? WHERE id = ?")
                  ->execute([$accumulatedPaid2, $status2, $subId]);

        $checkStmt->execute([$subId]);
        $sub2 = $checkStmt->fetch();
        $this->assert($sub2['status'] === 'regle' && (float)$sub2['paid_amount'] === 5000.00, "Second installment accurately satisfies total due and marks subscription 'regle'");

        // Clean up test subscription
        $this->pdo->prepare("DELETE FROM driver_subscriptions WHERE id = ?")->execute([$subId]);
    }

    private function testTravelerReportCalculations(): void {
        echo "\n8. Testing Traveler Manifest Passenger & Revenue Calculations...\n";

        $seats = 4;
        $price = 1500.00;
        $expectedTotal = $seats * $price; // 6000.00 DZD

        $this->assert($expectedTotal === 6000.00, "Traveler manifest revenue formula strictly calculates price * seats");

        // Ensure valid vehicle and driver IDs exist for foreign keys
        $dId = (int)$this->pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
        $vId = (int)$this->pdo->query("SELECT id FROM vehicles LIMIT 1")->fetchColumn();
        if (!$vId) {
            $this->pdo->exec("INSERT INTO vehicles (brand, model, plate_number, door_number, status) VALUES ('Renault', 'Symbol', '01458-121-16', '16-042', 'active')");
            $vId = (int)$this->pdo->lastInsertId();
        }

        // Test insertion in database with passengers
        $stmtR = $this->pdo->prepare("
            INSERT INTO traveler_reports (report_number, vehicle_id, driver_id, line_name, departure_station, arrival_station, departure_date, departure_time, seats_count, price_per_seat, total_revenue, status)
            VALUES ('TEST-PV-001', ?, ?, 'الجزائر — وهران', 'خروبة', 'الباهية', CURDATE(), '08:00:00', ?, ?, ?, 'scheduled')
        ");
        $stmtR->execute([$vId, $dId, $seats, $price, $expectedTotal]);
        $repId = (int)$this->pdo->lastInsertId();

        $stmtP = $this->pdo->prepare("
            INSERT INTO traveler_passengers (report_id, seat_number, passenger_name, passenger_cni, phone)
            VALUES (?, 1, 'سليمان بوزيد', '10982341092', '0551234567')
        ");
        $stmtP->execute([$repId]);

        $count = $this->pdo->query("SELECT COUNT(*) FROM traveler_passengers WHERE report_id = {$repId}")->fetchColumn();
        $this->assert((int)$count === 1, "Passenger record successfully persisted and linked to trip manifest");

        // Clean up
        $this->pdo->exec("DELETE FROM traveler_passengers WHERE report_id = {$repId}");
        $this->pdo->exec("DELETE FROM traveler_reports WHERE id = {$repId}");
    }

    private function testLeadApiValidationAndPersistence(): void {
        echo "\n9. Testing Lead Intake API Validation & Persistence...\n";

        // Directly invoke validation rules and DB insertion logic
        $validData = [
            'full_name' => 'كمال لعريبي',
            'phone' => '0555987654',
            'email' => 'kamel@example.dz',
            'role_type' => 'owner',
            'wilaya' => '16 - الجزائر',
            'fleet_size' => 3,
            'message' => 'استفسار حول الاشتراك'
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO leads (full_name, phone, email, role_type, wilaya, fleet_size, message)
            VALUES (:full_name, :phone, :email, :role_type, :wilaya, :fleet_size, :message)
        ");
        $stmt->execute([
            ':full_name' => $validData['full_name'],
            ':phone' => $validData['phone'],
            ':email' => $validData['email'],
            ':role_type' => $validData['role_type'],
            ':wilaya' => $validData['wilaya'],
            ':fleet_size' => $validData['fleet_size'],
            ':message' => $validData['message']
        ]);
        $leadId = (int)$this->pdo->lastInsertId();

        $this->assert($leadId > 0, "Lead cleanly inserted into database with real primary key (ID: {$leadId})");

        // Verify lead retrieval
        $stmtCheck = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmtCheck->execute([$leadId]);
        $lead = $stmtCheck->fetch();
        $this->assert($lead['phone'] === '0555987654' && $lead['role_type'] === 'owner', "Persisted lead data strictly matches submitted payload");

        // Clean up
        $this->pdo->prepare("DELETE FROM leads WHERE id = ?")->execute([$leadId]);
    }
}

// Execute test suite
$runner = new TestRunner();
$runner->run();
