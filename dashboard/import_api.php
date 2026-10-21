<?php
/**
 * dashboard/import_api.php — REST API for data import
 * Accepts JSON POST, returns JSON response
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$type = $_GET['type'] ?? '';
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$pdo = getDBConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$inserted = 0;
$skipped  = 0;
$errors   = 0;
$log      = [];

// ────────────────────────────────────────────────────────────
// HELPERS
// ────────────────────────────────────────────────────────────
function safe_date(?string $v): ?string {
    if (!$v) return null;
    try {
        $d = new DateTime($v);
        return $d->format('Y-m-d');
    } catch (Exception $e) { return null; }
}
function safe_decimal($v, float $default = 0): float {
    $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($v) ? (float)$v : $default;
}
function str_val($v, string $default = ''): string {
    return trim((string)($v ?? $default));
}

// ────────────────────────────────────────────────────────────
// DRIVERS / USERS
// ────────────────────────────────────────────────────────────
if ($type === 'drivers') {
    $stmt = $pdo->prepare("
        INSERT INTO users 
            (full_name, first_name, last_name, email, password_hash, phone, role, status,
             license_number, license_expiry, medical_cert_expiry, activity_start_date,
             birth_date, birth_place, father_name, mother_name, address, wilaya)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name),
            first_name = COALESCE(VALUES(first_name), first_name),
            last_name = COALESCE(VALUES(last_name), last_name),
            phone = VALUES(phone),
            license_number = COALESCE(VALUES(license_number), license_number),
            license_expiry = COALESCE(VALUES(license_expiry), license_expiry),
            medical_cert_expiry = COALESCE(VALUES(medical_cert_expiry), medical_cert_expiry),
            activity_start_date = COALESCE(VALUES(activity_start_date), activity_start_date),
            birth_date = COALESCE(VALUES(birth_date), birth_date),
            birth_place = COALESCE(VALUES(birth_place), birth_place),
            father_name = COALESCE(VALUES(father_name), father_name),
            mother_name = COALESCE(VALUES(mother_name), mother_name),
            address = COALESCE(VALUES(address), address),
            wilaya = COALESCE(VALUES(wilaya), wilaya)
    ");

    foreach ($data as $row) {
        try {
            $email = str_val($row['email'] ?? $row['username'] ?? '');
            if (empty($email)) {
                // Generate placeholder email
                $email = 'driver_' . time() . '_' . rand(100,999) . '@naguilou.local';
            }
            $name     = str_val($row['full_name'] ?? $row['name'] ?? $row['fullName'] ?? 'سائق');
            $phone    = str_val($row['phone'] ?? $row['phoneNumber'] ?? '0000000000');
            $role     = str_val($row['role'] ?? 'driver');
            if (!in_array($role, ['super_admin','admin','owner','driver'])) $role = 'driver';
            $passHash = password_hash(str_val($row['password'] ?? 'Pass@2026'), PASSWORD_DEFAULT);
            $first    = str_val($row['first_name'] ?? $row['firstName'] ?? '');
            $last     = str_val($row['last_name']  ?? $row['lastName']  ?? '');

            $stmt->execute([
                $name, $first ?: null, $last ?: null, $email, $passHash, $phone, $role, 'active',
                str_val($row['license_number'] ?? $row['licenseNumber'] ?? null) ?: null,
                safe_date($row['license_expiry'] ?? $row['licenseExpiry'] ?? null),
                safe_date($row['medical_cert_expiry'] ?? $row['medicalExpiry'] ?? null),
                safe_date($row['activity_start_date'] ?? $row['startDate'] ?? null),
                safe_date($row['birth_date'] ?? $row['birthDate'] ?? null),
                str_val($row['birth_place'] ?? $row['birthPlace'] ?? null) ?: null,
                str_val($row['father_name'] ?? $row['fatherName'] ?? null) ?: null,
                str_val($row['mother_name'] ?? $row['motherName'] ?? null) ?: null,
                str_val($row['address'] ?? null) ?: null,
                str_val($row['wilaya'] ?? '01 - أدرار'),
            ]);
            $inserted++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { $skipped++; } // Duplicate
            else { $errors++; $log[] = $e->getMessage(); }
        }
    }
}

// ────────────────────────────────────────────────────────────
// VEHICLES
// ────────────────────────────────────────────────────────────
elseif ($type === 'vehicles') {
    $stmt = $pdo->prepare("
        INSERT INTO vehicles 
            (brand, model, plate_number, door_number, color, insurance_expiry, insurance_company,
             technical_control_expiry, gpl_expiry, saccuriometre_expiry, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            brand = VALUES(brand),
            model = VALUES(model),
            insurance_expiry = VALUES(insurance_expiry)
    ");

    foreach ($data as $row) {
        try {
            $plate = str_val($row['plate_number'] ?? $row['plateNumber'] ?? $row['plate'] ?? '');
            if (!$plate) { $skipped++; continue; }
            $stmt->execute([
                str_val($row['brand'] ?? 'Unknown'),
                str_val($row['model'] ?? $row['vehicleModel'] ?? 'Unknown'),
                $plate,
                str_val($row['door_number'] ?? $row['doorNumber'] ?? $plate),
                str_val($row['color'] ?? 'أصفر'),
                safe_date($row['insurance_expiry'] ?? $row['insuranceExpiry'] ?? null),
                str_val($row['insurance_company'] ?? $row['insuranceCompany'] ?? 'SAA'),
                safe_date($row['technical_control_expiry'] ?? $row['technicalExpiry'] ?? null),
                safe_date($row['gpl_expiry'] ?? null),
                safe_date($row['saccuriometre_expiry'] ?? null),
                'active',
            ]);
            $inserted++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { $skipped++; }
            else { $errors++; $log[] = $e->getMessage(); }
        }
    }
}

// ────────────────────────────────────────────────────────────
// BILLING
// ────────────────────────────────────────────────────────────
elseif ($type === 'billing') {
    // Lookup vehicles by plate
    $vehicleMap = [];
    foreach ($pdo->query("SELECT id, plate_number FROM vehicles") as $v) {
        $vehicleMap[$v['plate_number']] = $v['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO billings (vehicle_id, amount, paid_amount, period_month, due_date, payment_date, payment_method, status, notes)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    foreach ($data as $row) {
        try {
            $plate    = str_val($row['vehicle_plate'] ?? $row['vehiclePlate'] ?? $row['plate'] ?? '');
            $vehicleId = $vehicleMap[$plate] ?? null;
            if (!$vehicleId && !empty($plate)) {
                // Try partial match
                foreach ($vehicleMap as $p => $id) {
                    if (str_contains(strtolower($p), strtolower($plate)) || str_contains(strtolower($plate), strtolower($p))) {
                        $vehicleId = $id; break;
                    }
                }
            }
            if (!$vehicleId) { $skipped++; $log[] = "Vehicle not found: $plate"; continue; }

            $status = str_val($row['status'] ?? 'pending');
            if (!in_array($status, ['paid','pending','overdue'])) $status = 'pending';
            $method = str_val($row['payment_method'] ?? $row['paymentMethod'] ?? 'baridimob');
            if (!in_array($method, ['cash','baridimob','ccp','bank'])) $method = 'baridimob';

            $stmt->execute([
                $vehicleId,
                safe_decimal($row['amount'] ?? 5000),
                safe_decimal($row['paid_amount'] ?? $row['paidAmount'] ?? 0),
                str_val($row['period_month'] ?? $row['month'] ?? date('Y-m')),
                safe_date($row['due_date'] ?? $row['dueDate'] ?? date('Y-m-d')),
                safe_date($row['payment_date'] ?? $row['paymentDate'] ?? null),
                $method,
                $status,
                str_val($row['notes'] ?? null) ?: null,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors++; $log[] = $e->getMessage();
        }
    }
}

// ────────────────────────────────────────────────────────────
// CONTRACTS
// ────────────────────────────────────────────────────────────
elseif ($type === 'contracts') {
    $userMap    = [];
    $vehicleMap = [];
    foreach ($pdo->query("SELECT id, email FROM users") as $u) {
        $userMap[$u['email']] = $u['id'];
    }
    foreach ($pdo->query("SELECT id, plate_number FROM vehicles") as $v) {
        $vehicleMap[$v['plate_number']] = $v['id'];
    }
    // Default owner = first admin
    $defaultOwner = (int)($pdo->query("SELECT id FROM users WHERE role IN ('super_admin','admin') LIMIT 1")->fetchColumn() ?: 1);

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO contracts 
            (contract_number, driver_id, vehicle_id, owner_id, start_date, end_date, monthly_wage, status, notes)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    foreach ($data as $row) {
        try {
            $driverEmail = str_val($row['driver_email'] ?? $row['driverEmail'] ?? '');
            $plate       = str_val($row['vehicle_plate'] ?? $row['vehiclePlate'] ?? $row['plate'] ?? '');
            $driverId    = $userMap[$driverEmail] ?? null;
            $vehicleId   = $vehicleMap[$plate] ?? null;
            if (!$driverId || !$vehicleId) { $skipped++; continue; }

            $ownerEmail = str_val($row['owner_email'] ?? $row['ownerEmail'] ?? '');
            $ownerId    = $userMap[$ownerEmail] ?? $defaultOwner;
            $num        = str_val($row['contract_number'] ?? $row['contractNumber'] ?? 'CTR-' . uniqid());
            $status     = str_val($row['status'] ?? 'signed');
            if (!in_array($status, ['draft','signed','terminated'])) $status = 'signed';

            $stmt->execute([
                $num, $driverId, $vehicleId, $ownerId,
                safe_date($row['start_date'] ?? $row['startDate'] ?? date('Y-01-01')),
                safe_date($row['end_date']   ?? $row['endDate']   ?? date('Y-12-31')),
                safe_decimal($row['monthly_wage'] ?? $row['monthlyWage'] ?? 45000, 45000),
                $status,
                str_val($row['notes'] ?? null) ?: null,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { $skipped++; }
            else { $errors++; $log[] = $e->getMessage(); }
        }
    }
}

// ────────────────────────────────────────────────────────────
// DEBTS
// ────────────────────────────────────────────────────────────
elseif ($type === 'debts') {
    $userMap = [];
    foreach ($pdo->query("SELECT id, email, full_name FROM users") as $u) {
        $userMap[$u['email']] = $u['id'];
        $userMap[$u['full_name']] = $u['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO driver_debts 
            (user_id, chauffeur_name, phone, total_credits, total_versements, driver_type, status, notes)
        VALUES (?,?,?,?,?,?,?,?)
    ");

    foreach ($data as $row) {
        try {
            $name   = str_val($row['chauffeur_name'] ?? $row['driverName'] ?? $row['name'] ?? 'مجهول');
            $userId = $userMap[$row['email'] ?? ''] ?? $userMap[$name] ?? null;
            $dType  = str_val($row['driver_type'] ?? $row['driverType'] ?? 'actif');
            if (!in_array($dType, ['actif','ancien'])) $dType = 'actif';
            $status = str_val($row['status'] ?? 'endette');
            if (!in_array($status, ['endette','a_jour'])) $status = 'endette';

            $stmt->execute([
                $userId,
                $name,
                str_val($row['phone'] ?? '0000000000'),
                safe_decimal($row['total_credits'] ?? $row['totalCredits'] ?? 0),
                safe_decimal($row['total_versements'] ?? $row['totalVersements'] ?? 0),
                $dType, $status,
                str_val($row['notes'] ?? null) ?: null,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors++; $log[] = $e->getMessage();
        }
    }
}

// ────────────────────────────────────────────────────────────
// CNAS Declarations
// ────────────────────────────────────────────────────────────
elseif ($type === 'cnas') {
    $userMap = [];
    foreach ($pdo->query("SELECT id, email FROM users") as $u) {
        $userMap[$u['email']] = $u['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO cnas_declarations 
            (driver_id, cnas_number, affiliation_date, period_quarter, base_salary,
             cotisation_patronale, cotisation_ouvriere, total_cotisation, payment_status, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($data as $row) {
        try {
            $email    = str_val($row['driver_email'] ?? $row['email'] ?? '');
            $driverId = $userMap[$email] ?? null;
            if (!$driverId) { $skipped++; continue; }
            $base    = safe_decimal($row['base_salary'] ?? 45000, 45000);
            $patron  = safe_decimal($row['cotisation_patronale'] ?? round($base * 0.26, 2));
            $ouvrier = safe_decimal($row['cotisation_ouvriere']  ?? round($base * 0.09, 2));
            $total   = safe_decimal($row['total_cotisation']     ?? ($patron + $ouvrier));
            $pStatus = str_val($row['payment_status'] ?? 'regle');
            if (!in_array($pStatus, ['regle','en_attente','retard'])) $pStatus = 'regle';

            $stmt->execute([
                $driverId,
                str_val($row['cnas_number'] ?? 'CNAS-' . uniqid()),
                safe_date($row['affiliation_date'] ?? date('Y-01-01')),
                str_val($row['period_quarter'] ?? 'T1-' . date('Y')),
                $base, $patron, $ouvrier, $total, $pStatus,
                str_val($row['notes'] ?? null) ?: null,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors++; $log[] = $e->getMessage();
        }
    }
}

// ────────────────────────────────────────────────────────────
// LEAVES
// ────────────────────────────────────────────────────────────
elseif ($type === 'leaves') {
    $userMap    = [];
    $vehicleMap = [];
    foreach ($pdo->query("SELECT id, email FROM users") as $u)        $userMap[$u['email']] = $u['id'];
    foreach ($pdo->query("SELECT id, plate_number FROM vehicles") as $v) $vehicleMap[$v['plate_number']] = $v['id'];

    $stmt = $pdo->prepare("
        INSERT INTO leaves (driver_id, vehicle_id, start_date, end_date, reason, status)
        VALUES (?,?,?,?,?,?)
    ");

    foreach ($data as $row) {
        try {
            $driverId  = $userMap[$row['driver_email'] ?? ''] ?? null;
            $vehicleId = $vehicleMap[$row['vehicle_plate'] ?? ''] ?? null;
            if (!$driverId || !$vehicleId) { $skipped++; continue; }
            $status = str_val($row['status'] ?? 'approved');
            if (!in_array($status, ['pending','approved','rejected','completed'])) $status = 'approved';
            $stmt->execute([
                $driverId, $vehicleId,
                safe_date($row['start_date'] ?? $row['startDate'] ?? date('Y-m-d')),
                safe_date($row['end_date']   ?? $row['endDate']   ?? date('Y-m-d')),
                str_val($row['reason'] ?? 'عطلة'),
                $status,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors++; $log[] = $e->getMessage();
        }
    }
}
// ────────────────────────────────────────────────────────────
// FULL BACKUP (Direct or Scraped bundle)
// ────────────────────────────────────────────────────────────
elseif ($type === 'full_backup' || $type === 'all' || $type === 'backup') {
    require_once __DIR__ . '/../includes/backup_importer.php';
    $result = import_full_backup($pdo, $data);
    echo json_encode([
        'success'  => $result['success'],
        'type'     => $type,
        'inserted' => array_sum($result['counts']),
        'skipped'  => 0,
        'counts'   => $result['counts'],
        'errors'   => count($result['errors']),
        'log'      => $result['errors'],
        'message'  => $result['message'],
    ]);
    exit;
} else {
    echo json_encode(['success' => false, 'message' => "Unknown type: $type"]);
    exit;
}

// Log audit
log_audit($pdo, 'import', $type, null, "Imported $inserted records ($skipped skipped, $errors errors)");

echo json_encode([
    'success'  => true,
    'type'     => $type,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'log'      => $log,
]);
