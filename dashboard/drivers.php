<?php
// dashboard/drivers.php - Liste Chauffeurs, Expiration Imminente & CHAUFFEURS PERMANENCE
$pageTitle = 'Liste Chauffeurs | سجل السائقين والمداومة';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

// Handle Add Driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_driver') {
    require_csrf_token();
    $fullName = trim($_POST['full_name'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    if (empty($fullName) && (!empty($firstName) || !empty($lastName))) {
        $fullName = trim($firstName . ' ' . $lastName);
    }
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $wilaya = trim($_POST['wilaya'] ?? '01 - أدرار');
    $birthDate = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birthPlace = trim($_POST['birth_place'] ?? '');
    $fatherName = trim($_POST['father_name'] ?? '');
    $motherName = trim($_POST['mother_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $licenseIssueDate = !empty($_POST['license_issue_date']) ? $_POST['license_issue_date'] : null;
    $licenseIssuePlace = trim($_POST['license_issue_place'] ?? '');
    $licenseExpiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
    
    $driverType = trim($_POST['driver_type'] ?? 'ajir');
    $probationStage = trim($_POST['probation_stage'] ?? 'trial_1');
    $activityStartDate = !empty($_POST['activity_start_date']) ? $_POST['activity_start_date'] : null;
    $dtwVisaDate = !empty($_POST['dtw_visa_date']) ? $_POST['dtw_visa_date'] : null;
    $cardNumber = trim($_POST['card_number'] ?? '');
    
    $medExpiry = !empty($_POST['medical_cert_expiry']) ? $_POST['medical_cert_expiry'] : null;
    $medStart = !empty($_POST['medical_exam_start']) ? $_POST['medical_exam_start'] : null;
    $medEnd = !empty($_POST['medical_exam_end']) ? $_POST['medical_exam_end'] : $medExpiry;
    $medVisaDate = !empty($_POST['medical_visa_date']) ? $_POST['medical_visa_date'] : null;
    $nextMedStart = !empty($_POST['next_medical_start']) ? $_POST['next_medical_start'] : null;
    $nextMedEnd = !empty($_POST['next_medical_end']) ? $_POST['next_medical_end'] : null;
    
    $taxiContractStart = !empty($_POST['taxi_contract_start']) ? $_POST['taxi_contract_start'] : null;
    $taxiContractEnd = !empty($_POST['taxi_contract_end']) ? $_POST['taxi_contract_end'] : null;
    $assignedVehicleId = !empty($_POST['assigned_vehicle_id']) ? intval($_POST['assigned_vehicle_id']) : null;

    // Handle photo upload
    $avatarUrl = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts)) {
            $destDir = __DIR__ . '/../uploads/drivers';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }
            $fileName = 'driver_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destDir . '/' . $fileName)) {
                $avatarUrl = '/uploads/drivers/' . $fileName;
            }
        }
    }

    if (!empty($fullName) && !empty($phone)) {
        if (empty($email)) {
            $email = 'driver_' . time() . '_' . rand(100, 999) . '@taxinaguilou.dz';
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    full_name, first_name, last_name, email, password_hash, phone, role, status, 
                    wilaya, birth_date, birth_place, father_name, mother_name, address,
                    license_number, license_issue_date, license_issue_place, license_expiry,
                    driver_type, probation_stage, activity_start_date, dtw_visa_date, card_number,
                    medical_cert_expiry, medical_exam_start, medical_exam_end, medical_visa_date,
                    next_medical_start, next_medical_end, taxi_contract_start, taxi_contract_end, avatar_url
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 'driver', 'active',
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $fullName, $firstName, $lastName, $email, password_hash('driver123', PASSWORD_BCRYPT), $phone,
                $wilaya, $birthDate, $birthPlace, $fatherName, $motherName, $address,
                $licenseNumber, $licenseIssueDate, $licenseIssuePlace, $licenseExpiry,
                $driverType, $probationStage, $activityStartDate, $dtwVisaDate, $cardNumber,
                $medEnd, $medStart, $medEnd, $medVisaDate,
                $nextMedStart, $nextMedEnd, $taxiContractStart, $taxiContractEnd, $avatarUrl
            ]);
            $newDriverId = (int)$pdo->lastInsertId();

            // Link vehicle if assigned
            if ($assignedVehicleId && $assignedVehicleId > 0) {
                $stmtVeh = $pdo->prepare("UPDATE vehicles SET current_driver_id = ? WHERE id = ?");
                $stmtVeh->execute([$newDriverId, $assignedVehicleId]);
            }

            // Auto-issue official labor contract from taxi contract dates so admin never enters duplicates
            if (!empty($taxiContractStart) && !empty($taxiContractEnd)) {
                $maxCId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM contracts")->fetchColumn();
                $contractNum = 'CT-' . date('Y') . '-' . str_pad($maxCId + 1, 3, '0', STR_PAD_LEFT);
                $stmtContract = $pdo->prepare("
                    INSERT INTO contracts (contract_number, driver_id, vehicle_id, owner_id, start_date, end_date, monthly_wage, status, signed_at, notes)
                    VALUES (?, ?, ?, ?, ?, ?, 45000.00, 'signed', NOW(), 'عقد عمل وسريان استغلال رخصة الأجرة وفق القانون 90-11')
                ");
                $stmtContract->execute([$contractNum, $newDriverId, ($assignedVehicleId && $assignedVehicleId > 0) ? $assignedVehicleId : null, $user['id'], $taxiContractStart, $taxiContractEnd]);
            }

            log_audit($pdo, 'driver_created', 'users', $newDriverId, "تسجيل بطاقة فنية لسائق جديد: $fullName ($phone)");
            set_flash('success', 'تم تسجيل السائق بنجاح وتوليد عقد العمل والبطاقة الفنية الرسمية');
            redirect('/dashboard/driver_fiche.php?id=' . $newDriverId);
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء التسجيل: ' . $e->getMessage());
        }
    }
}

// Handle Quick Update Driver Probation Stage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver_stage') {
    require_csrf_token();
    $driverId = intval($_POST['driver_id'] ?? 0);
    $newStage = trim($_POST['probation_stage'] ?? 'trial_1');
    if ($driverId > 0 && in_array($newStage, ['trial_1', 'trial_2', 'trial_3', 'official'])) {
        $stmtUpdate = $pdo->prepare("UPDATE users SET probation_stage = ? WHERE id = ? AND role = 'driver'");
        $stmtUpdate->execute([$newStage, $driverId]);
        log_audit($pdo, 'driver_stage_updated', 'users', $driverId, "تحديث مرحلة السائق إلى: $newStage");
        set_flash('success', 'تم تحديث المرحلة المهنية للسائق بنجاح');
        redirect('/dashboard/drivers.php');
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, actifs, permanence, anciens, trial_1, trial_2, trial_3, official

// Fetch Expiration Imminente Drivers (< 30 days or expired)
$stmtExpiring = $pdo->query("
    SELECT u.*, v.plate_number, v.door_number,
           DATEDIFF(u.license_expiry, CURDATE()) as license_days,
           DATEDIFF(u.medical_cert_expiry, CURDATE()) as med_days
    FROM users u
    LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
    WHERE u.role = 'driver'
      AND (
          (u.license_expiry IS NOT NULL AND u.license_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
          OR
          (u.medical_cert_expiry IS NOT NULL AND u.medical_cert_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
      )
    ORDER BY LEAST(
        COALESCE(u.license_expiry, '2099-12-31'),
        COALESCE(u.medical_cert_expiry, '2099-12-31')
    ) ASC
");
$imminentDrivers = $stmtExpiring->fetchAll();

// Fetch Permanence Drivers IDs from driver_schedules
$permanenceDriverIds = $pdo->query("
    SELECT DISTINCT driver_id 
    FROM driver_schedules 
    WHERE is_permanence = 1 AND shift_date >= CURDATE()
")->fetchAll(PDO::FETCH_COLUMN);

// Fetch Main Drivers List
$sql = "
    SELECT u.*, v.plate_number, v.brand, v.model, v.door_number
    FROM users u
    LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
    WHERE u.role = 'driver'
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR u.license_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY u.id DESC";
$stmtD = $pdo->prepare($sql);
$stmtD->execute($params);
$allDrivers = $stmtD->fetchAll();

// If filter is permanence or specific stage
$drivers = [];
foreach ($allDrivers as $d) {
    $isPerm = in_array($d['id'], $permanenceDriverIds);
    $d['is_permanence'] = $isPerm;

    if ($filter === 'permanence' && !$isPerm) {
        continue;
    }
    if (in_array($filter, ['trial_1', 'trial_2', 'trial_3', 'official'])) {
        if (($d['probation_stage'] ?? 'trial_1') !== $filter) {
            continue;
        }
    }
    $drivers[] = $d;
}

// Display counts
$countActifs = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status = 'active'")->fetchColumn();
$countAnciens = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status != 'active'")->fetchColumn();
$countPermanence = count($permanenceDriverIds);
$countTrial1 = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND (probation_stage = 'trial_1' OR probation_stage IS NULL)")->fetchColumn();
$countTrial2 = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND probation_stage = 'trial_2'")->fetchColumn();
$countTrial3 = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND probation_stage = 'trial_3'")->fetchColumn();
$countOfficial = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND probation_stage = 'official'")->fetchColumn();

// Fetch Active Vehicles for assignment
$activeVehicles = [];
if ($pdo) {
    try {
        $stmtV = $pdo->query("
            SELECT v.id, v.plate_number, v.brand, v.model, v.door_number, v.current_driver_id,
                   u.full_name as current_driver_name
            FROM vehicles v
            LEFT JOIN users u ON v.current_driver_id = u.id
            WHERE v.status = 'active'
            ORDER BY v.plate_number ASC
        ");
        $activeVehicles = $stmtV ? $stmtV->fetchAll() : [];
    } catch (\Throwable $e) {}
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Liste Chauffeurs</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    سجل السائقين والمداومة
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">إدارة الكفاءة المهنية، رخص السياقة، الفحوصات الطبية الدورية، وتعيين سائقي المداومة الرسمية</p>
        </div>
        
        <div class="flex items-center gap-2">
            <a href="planning.php" 
               class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
                <span>مخطط المناوبات (3 أيام)</span>
            </a>
            <button type="button" onclick="openAddDriverModal()"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>تسجيل سائق جديد</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards (Clean & Simple) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">السائقون النشطون</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $countActifs ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">سائقو المداومة (Permanence)</p>
                <p class="text-xl font-bold text-amber-600 font-num mt-0.5"><?= $countPermanence ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">تنبيهات الانتهاء الوشيكة</p>
                <p class="text-xl font-bold <?= count($imminentDrivers) > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num mt-0.5"><?= count($imminentDrivers) ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?= count($imminentDrivers) > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">سابقون (ذمم مالية)</p>
                <p class="text-xl font-bold text-slate-700 font-num mt-0.5"><?= $countAnciens ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6H2.25m0 0H3m0 0h18m0 0h.75a.75.75 0 01.75.75v.75m0 0H21m0 0v11.25c0 .754-.726 1.294-1.453 1.096a60.07 60.07 0 00-15.797-2.101" />
                </svg>
            </div>
        </div>
    </div>

    <!-- SECTION 1: Expiration Imminente (Clean Alert Area) -->
    <?php if (!empty($imminentDrivers)): ?>
        <div class="bg-white rounded-2xl border border-rose-200/80 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-rose-100 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-900 text-sm">تنبيهات انتهاء الصلاحية الوشيكة (Expiration Imminente)</h3>
                        <p class="text-xs text-slate-500">رخص السياقة والفحوصات الطبية الدورية التي تنتهي صلاحيتها خلال أقل من 30 يوماً</p>
                    </div>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 font-num">
                    <?= count($imminentDrivers) ?> تنبيهات
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
                <?php foreach ($imminentDrivers as $expD): ?>
                    <div class="p-3.5 rounded-xl bg-rose-50/30 border border-rose-200/70 space-y-2.5">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-slate-900 text-xs"><?= htmlspecialchars($expD['full_name']) ?></span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800">مستعجل</span>
                        </div>
                        <div class="space-y-1.5 text-xs text-slate-700">
                            <div class="flex justify-between">
                                <span class="text-slate-500">الهاتف:</span>
                                <span class="font-num font-semibold" dir="ltr"><?= htmlspecialchars($expD['phone']) ?></span>
                            </div>
                            <?php if ($expD['license_days'] !== null && $expD['license_days'] <= 30): ?>
                                <div class="flex justify-between items-center text-rose-700 font-medium bg-white px-2 py-1 rounded border border-rose-100">
                                    <span>رخصة السياقة:</span>
                                    <span class="font-num text-[11px] font-bold">
                                        <?= $expD['license_days'] < 0 ? 'منتهية منذ ' . abs($expD['license_days']) . ' يوم' : 'باقي ' . $expD['license_days'] . ' يوم' ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if ($expD['med_days'] !== null && $expD['med_days'] <= 30): ?>
                                <div class="flex justify-between items-center text-amber-700 font-medium bg-white px-2 py-1 rounded border border-amber-100">
                                    <span>الفحص الطبي:</span>
                                    <span class="font-num text-[11px] font-bold">
                                        <?= $expD['med_days'] < 0 ? 'منتهي الصلاحية' : 'باقي ' . $expD['med_days'] . ' يوم' ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- SECTION 2: Toolbar & Filters -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث بالاسم، رقم الهاتف، أو رقم رخصة السياقة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="drivers.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <!-- Segmented Filter Tabs -->
        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            <a href="drivers.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                الكل
            </a>

            <a href="drivers.php?filter=actifs<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1 <?= $filter === 'actifs' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                <span>سائقون نشطون</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white/80 dark:bg-slate-700 font-num font-bold text-slate-900 dark:text-slate-100"><?= $countActifs ?></span>
            </a>

            <a href="drivers.php?filter=trial_1<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1 <?= $filter === 'trial_1' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-amber-50 dark:bg-amber-500/10 text-amber-900 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30 hover:bg-amber-100 dark:hover:bg-amber-500/20' ?>">
                <span>تجريبية 1</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-200/80 dark:bg-amber-500/30 font-num font-bold text-amber-950 dark:text-amber-200"><?= $countTrial1 ?></span>
            </a>

            <a href="drivers.php?filter=trial_2<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1 <?= $filter === 'trial_2' ? 'bg-blue-600 text-white font-bold' : 'bg-blue-50 dark:bg-blue-500/10 text-blue-900 dark:text-blue-300 border border-blue-200 dark:border-blue-500/30 hover:bg-blue-100 dark:hover:bg-blue-500/20' ?>">
                <span>تجريبية 2</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-blue-200/80 dark:bg-blue-500/30 font-num font-bold text-blue-950 dark:text-blue-200"><?= $countTrial2 ?></span>
            </a>

            <a href="drivers.php?filter=trial_3<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1 <?= $filter === 'trial_3' ? 'bg-indigo-600 text-white font-bold' : 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-900 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-500/30 hover:bg-indigo-100 dark:hover:bg-indigo-500/20' ?>">
                <span>تجريبية 3</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-indigo-200/80 dark:bg-indigo-500/30 font-num font-bold text-indigo-950 dark:text-indigo-200"><?= $countTrial3 ?></span>
            </a>

            <a href="drivers.php?filter=official<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1 <?= $filter === 'official' ? 'bg-emerald-600 text-white font-bold' : 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-900 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30 hover:bg-emerald-100 dark:hover:bg-emerald-500/20' ?>">
                <span>سائق رسمي</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-emerald-200/80 dark:bg-emerald-500/30 font-num font-bold text-emerald-950 dark:text-emerald-200"><?= $countOfficial ?></span>
            </a>

            <a href="drivers.php?filter=permanence<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1 <?= $filter === 'permanence' ? 'bg-amber-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                <span>المداومة</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-700 text-white font-num"><?= $countPermanence ?></span>
            </a>

            <a href="debts.php?filter=all" 
               class="px-2.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700">
                <span>سابقون (ديون)</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-slate-300 dark:bg-slate-700 font-num font-bold text-slate-800 dark:text-slate-200"><?= $countAnciens ?></span>
            </a>
        </div>
    </div>

    <!-- SECTION 3: Drivers Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap">السائق والهوية المهنية</th>
                        <th class="p-3.5 whitespace-nowrap">رقم الهاتف</th>
                        <th class="p-3.5 whitespace-nowrap">رخصة السياقة</th>
                        <th class="p-3.5 whitespace-nowrap">صلاحية الرخصة</th>
                        <th class="p-3.5 whitespace-nowrap">الفحص الطبي الدوري</th>
                        <th class="p-3.5 whitespace-nowrap">المركبة الحالية</th>
                        <th class="p-3.5 whitespace-nowrap text-center">المداومة</th>
                        <th class="p-3.5 whitespace-nowrap text-center">البطاقة الفنية (Fiche)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($drivers)): ?>
                        <?php foreach ($drivers as $d): ?>
                            <tr class="hover:bg-slate-50/60 transition <?= $d['is_permanence'] ? 'bg-amber-50/15' : '' ?>">
                                
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl overflow-hidden border border-slate-200 bg-slate-100 flex items-center justify-center shrink-0">
                                            <?php if (!empty($d['avatar_url'])): ?>
                                                <img src="<?= url($d['avatar_url']) ?>" alt="<?= htmlspecialchars($d['full_name']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <span class="font-black text-slate-600 text-xs"><?= mb_substr($d['full_name'], 0, 1) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-1.5">
                                                <a href="driver_fiche.php?id=<?= $d['id'] ?>" class="font-bold text-slate-900 text-[13px] hover:text-amber-600 transition">
                                                    <?= htmlspecialchars($d['full_name']) ?>
                                                </a>
                                                <?php 
                                                $stage = $d['probation_stage'] ?? 'trial_1';
                                                $stageBadges = [
                                                    'trial_1' => ['label' => 'فترة تجريبية الأولى', 'cls' => 'bg-amber-50 text-amber-800 border-amber-200'],
                                                    'trial_2' => ['label' => 'تجريبية الثانية', 'cls' => 'bg-blue-50 text-blue-800 border-blue-200'],
                                                    'trial_3' => ['label' => 'تجريبية الثالثة', 'cls' => 'bg-indigo-50 text-indigo-800 border-indigo-200'],
                                                    'official' => ['label' => 'سائق رسمي', 'cls' => 'bg-emerald-50 text-emerald-800 border-emerald-200'],
                                                ];
                                                $curB = $stageBadges[$stage] ?? $stageBadges['trial_1'];
                                                ?>
                                                <div class="inline-block relative group">
                                                    <button type="button" class="px-2 py-0.5 rounded text-[10px] font-bold border flex items-center gap-1 <?= $curB['cls'] ?> hover:shadow-2xs transition" title="انقر لتعديل مرحلة السائق">
                                                        <span><?= $curB['label'] ?></span>
                                                        <svg class="w-2.5 h-2.5 opacity-60 group-hover:rotate-180 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                                                    </button>
                                                    <div class="hidden group-hover:block absolute z-30 right-0 mt-1 w-48 bg-white dark:bg-[#161d27] rounded-xl shadow-xl border border-slate-200 dark:border-slate-700/80 p-1.5 text-right">
                                                        <form method="POST" action="">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="update_driver_stage">
                                                            <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                                            <button type="submit" name="probation_stage" value="trial_1" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-amber-50 dark:hover:bg-amber-500/20 text-amber-900 dark:text-amber-300 transition flex items-center justify-between">
                                                                <span>فترة تجريبية الأولى</span>
                                                                <?= $stage === 'trial_1' ? '<span class="text-amber-500 font-bold">✓</span>' : '' ?>
                                                            </button>
                                                            <button type="submit" name="probation_stage" value="trial_2" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-blue-50 dark:hover:bg-blue-500/20 text-blue-900 dark:text-blue-300 transition flex items-center justify-between">
                                                                <span>تجريبية الثانية</span>
                                                                <?= $stage === 'trial_2' ? '<span class="text-blue-500 font-bold">✓</span>' : '' ?>
                                                            </button>
                                                            <button type="submit" name="probation_stage" value="trial_3" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-indigo-50 dark:hover:bg-indigo-500/20 text-indigo-900 dark:text-indigo-300 transition flex items-center justify-between">
                                                                <span>تجريبية الثالثة</span>
                                                                <?= $stage === 'trial_3' ? '<span class="text-indigo-500 font-bold">✓</span>' : '' ?>
                                                            </button>
                                                            <button type="submit" name="probation_stage" value="official" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-emerald-50 dark:hover:bg-emerald-500/20 text-emerald-900 dark:text-emerald-300 transition flex items-center justify-between border-t border-slate-100 dark:border-slate-700/60 mt-1 pt-1">
                                                                <span>سائق رسمي</span>
                                                                <?= $stage === 'official' ? '<span class="text-emerald-500 font-bold">✓</span>' : '' ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-[11px] text-slate-400 mt-0.5" dir="ltr"><?= htmlspecialchars($d['email']) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num font-semibold text-slate-800" dir="ltr">
                                    <?= htmlspecialchars($d['phone']) ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num text-slate-700 font-semibold" dir="ltr">
                                    <?= htmlspecialchars($d['license_number'] ?? '—') ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num">
                                    <?php if (!empty($d['license_expiry'])): ?>
                                        <span class="font-semibold text-slate-800" dir="ltr"><?= htmlspecialchars($d['license_expiry']) ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 font-num" dir="ltr">
                                        <?= htmlspecialchars($d['medical_cert_expiry'] ?? $d['medical_exam_end'] ?? 'صالح ومفحوص') ?>
                                    </span>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($d['plate_number'])): ?>
                                        <div class="font-bold text-slate-800 font-num text-[11px]" dir="ltr"><?= htmlspecialchars($d['plate_number']) ?></div>
                                        <div class="text-[11px] text-slate-400"><?= htmlspecialchars($d['brand'] . ' ' . $d['model']) ?> (باب: <?= htmlspecialchars($d['door_number']) ?>)</div>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">بدون مركبة (احتياطي)</span>
                                    <?php endif; ?>
                                </td>

                                <!-- CHAUFFEURS PERMANENCE BADGE -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($d['is_permanence']): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-500 text-slate-950 font-bold text-[10px] shadow-xs">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                                            <span>PERMANENCE</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">خدمة عادية</span>
                                    <?php endif; ?>
                                </td>

                                <!-- FICHE TECHNIQUE ACTION -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <a href="driver_fiche.php?id=<?= $d['id'] ?>" 
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-[11px] transition shadow-2xs">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                        <span>البطاقة الفنية</span>
                                    </a>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="p-10 text-center text-slate-400">لا يوجد سائقون مطابقون لشرط التصفية.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Add Driver (Sleek Desktop/ERP Multi-Step Wizard Modal) -->
<div id="addDriverModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-md hidden items-center justify-center p-3 sm:p-6 overflow-y-auto">
    <div class="bg-white max-w-4xl w-full rounded-3xl border border-slate-200/90 shadow-2xl overflow-hidden flex flex-col max-h-[92vh] my-auto transition-all animate-fadeIn text-right">
        
        <!-- Modal Top Bar -->
        <div class="px-6 py-4 bg-slate-50/90 border-b border-slate-200/80 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-400 text-slate-950 font-black flex items-center justify-center shadow-xs shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.765z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="font-black text-slate-900 text-base sm:text-lg">تسجيل سائق سيارة أجرة جديد</h3>
                        <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-200 font-num">
                            دفتر المقررة
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">إدخال البطاقة الفنية، رخصة السياقة، الفحوصات الطبية، وتعيين المركبة وفق القانون 90-11</p>
                </div>
            </div>
            
            <button type="button" onclick="closeAddDriverModal()" 
                    class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition" 
                    title="إغلاق (Esc)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Stepper Navigation Bar (Tabs) -->
        <div class="bg-white border-b border-slate-200/80 px-6 pt-3 pb-0 shrink-0">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs font-bold" id="wizardStepperTabs">
                <button type="button" onclick="goToWizardStep(1)" id="stepTab-1" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-900 text-white border-slate-900 shadow-xs">
                    <span class="w-5 h-5 rounded-full bg-white/20 text-white flex items-center justify-center text-[11px] font-num font-black shrink-0">1</span>
                    <span class="truncate">الحالة المدنية والصورة</span>
                </button>
                <button type="button" onclick="goToWizardStep(2)" id="stepTab-2" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100">
                    <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0">2</span>
                    <span class="truncate">الرخصة والمقررة</span>
                </button>
                <button type="button" onclick="goToWizardStep(3)" id="stepTab-3" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100">
                    <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0">3</span>
                    <span class="truncate">الفحص الطبي الدوري</span>
                </button>
                <button type="button" onclick="goToWizardStep(4)" id="stepTab-4" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100">
                    <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0">4</span>
                    <span class="truncate">المركبة والعقد</span>
                </button>
            </div>
            
            <!-- Dynamic Progress Line -->
            <div class="w-full bg-slate-100 h-1 mt-3 rounded-full overflow-hidden">
                <div id="wizardProgressLine" class="h-full bg-amber-500 transition-all duration-300 w-1/4"></div>
            </div>
        </div>

        <!-- Form Body (Scrollable container) -->
        <form id="addDriverForm" method="POST" action="" enctype="multipart/form-data" class="flex-1 overflow-y-auto p-6 sm:p-7 space-y-6 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_driver">

            <!-- ==============================================
                 STEP 1: الحالة المدنية والصورة الشخصية
                 ============================================== -->
            <div id="stepSection-1" class="wizard-section space-y-5">
                
                <div class="flex flex-col lg:flex-row items-start gap-6">
                    
                    <!-- Left: Driver Photo Portrait Uploader Box -->
                    <div class="w-full lg:w-48 shrink-0 flex flex-col items-center">
                        <label class="block font-bold text-slate-800 text-xs mb-2 text-center">صورة السائق (35x45 مم)</label>
                        
                        <div onclick="document.getElementById('driverAvatarInput').click()" 
                             id="photoDropZone"
                             class="w-36 h-48 bg-slate-50 rounded-2xl border-2 border-dashed border-slate-300 hover:border-amber-500 flex flex-col items-center justify-center relative cursor-pointer group shadow-2xs overflow-hidden transition">
                            
                            <!-- Preview Image (Initially Hidden) -->
                            <img id="driverAvatarPreview" src="" alt="معاينة الصورة" 
                                 class="w-full h-full object-cover hidden">
                            
                            <!-- Placeholder State -->
                            <div id="photoPlaceholder" class="flex flex-col items-center justify-center p-3 text-center space-y-2">
                                <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center group-hover:scale-110 transition shadow-2xs">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                                    </svg>
                                </div>
                                <span class="text-[11px] font-bold text-slate-700">اضغط لرفع الصورة</span>
                                <span class="text-[10px] text-slate-400">JPG, PNG, WEBP</span>
                            </div>

                            <!-- Hover Overlay -->
                            <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold">
                                تغيير الصورة 📷
                            </div>
                        </div>

                        <input type="file" id="driverAvatarInput" name="avatar" accept="image/*" class="hidden" onchange="previewDriverAvatar(this)">
                        <span class="text-[10px] text-slate-400 text-center mt-2">تظهر في البطاقة الفنية ودفتر المقررة</span>
                    </div>

                    <!-- Right: Civil Status & Personal Details Form -->
                    <div class="flex-1 w-full space-y-4">
                        
                        <div class="p-3 bg-amber-50/60 rounded-xl border border-amber-200/80 flex items-center gap-2 text-amber-900 font-bold text-xs">
                            <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                            <span>الصفحة 4 من دفتر المقررة: الحالة المدنية والهوية الشخصية</span>
                        </div>

                        <!-- Nom & Prénom -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">اللقب (Nom de famille) <span class="text-rose-500">*</span></label>
                                <input type="text" name="last_name" id="inputLastName" required placeholder="مثال: حمو صالح"
                                       oninput="updateDriverPreviewName()"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">الاسم (Prénom) <span class="text-rose-500">*</span></label>
                                <input type="text" name="first_name" id="inputFirstName" required placeholder="مثال: يوسف"
                                       oninput="updateDriverPreviewName()"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                        </div>

                        <!-- Date & Lieu de Naissance -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">تاريخ الازدياد (Date de Naissance)</label>
                                <input type="date" name="birth_date" value="1983-05-18"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">مكان الازدياد (Lieu de Naissance)</label>
                                <input type="text" name="birth_place" placeholder="مثال: بعريانو تامست، أدرار" value="بعريانو تامست"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                        </div>

                        <!-- Filiation: Père & Mère -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">اسم الأب (إبن)</label>
                                <input type="text" name="father_name" placeholder="مثال: أحمد" value="أحمد"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">اسم ولقب الأم (وابن)</label>
                                <input type="text" name="mother_name" placeholder="مثال: حمو صالح خيرة" value="حمو صالح خيرة"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                        </div>

                        <!-- Contact & Location -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">رقم الهاتف <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <input type="tel" name="phone" id="inputPhone" required placeholder="0661234567" dir="ltr" value="0661234567"
                                           oninput="updateDriverPreviewPhone()"
                                           class="w-full pl-12 pr-3 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-slate-50/60 focus:bg-white text-right transition shadow-2xs">
                                    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded text-[10px] font-num font-bold bg-slate-200 text-slate-700">🇩🇿 +213</span>
                                </div>
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">ولاية الإقامة</label>
                                <input type="text" name="wilaya" value="01 - أدرار"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">العنوان السكني</label>
                                <input type="text" name="address" placeholder="حي الحطابة أدرار" value="حي الحطابة أدرار"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- ==============================================
                 STEP 2: رخصة السياقة ودفتر المقررة
                 ============================================== -->
            <div id="stepSection-2" class="wizard-section space-y-5 hidden">
                
                <div class="p-3 bg-blue-50/60 rounded-xl border border-blue-200/80 flex items-center gap-2 text-blue-900 font-bold text-xs">
                    <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z"/></svg>
                    <span>الصفحة 4 و 7 من دفتر المقررة: رخصة السياقة والكفاءة المهنية وبداية النشاط</span>
                </div>

                <!-- Driver's License Info -->
                <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-4">
                    <h4 class="font-black text-slate-800 text-xs flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                        <span>بيانات رخصة السياقة (Permis de Conduire)</span>
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">رقم رخصة السياقة</label>
                            <input type="text" name="license_number" placeholder="مثال: A02669719" dir="ltr" value="A02669719"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num text-right bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">تاريخ الإصدار (Délivré le)</label>
                            <input type="date" name="license_issue_date" value="2008-03-24"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">جهة الإصدار (Délivré par)</label>
                            <input type="text" name="license_issue_place" placeholder="مثال: بلدية أدرار" value="بلدية أدرار"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-white transition shadow-2xs">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء صلاحية الرخصة</label>
                            <input type="date" name="license_expiry" value="2028-03-23"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">رقم دفتر المقررة / البطاقة المهنية</label>
                            <input type="text" name="card_number" placeholder="مثال: LIV-01-2024-047" dir="ltr" value="LIV-01-2024-047"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num text-right bg-white transition shadow-2xs">
                        </div>
                    </div>
                </div>

                <!-- Driver Status Type (Default to 'ajir' as requested) -->
                <input type="hidden" name="driver_type" value="ajir">

                <!-- Activity Start Date -->
                <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-2">
                    <label class="block font-black text-slate-800 text-xs">تاريخ بداية ممارسة النشاط (Début d'activité)</label>
                    <input type="date" name="activity_start_date" value="2024-09-03"
                           class="w-full sm:w-1/2 px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                    <p class="text-[11px] text-slate-400">تاريخ أول انطلاق لممارسة مهنة سائق سيارة أجرة المعتمد في السجلات الرسمية.</p>
                </div>

                <!-- Professional Status & Probation Period Selection (فترات التجربة والتثبيت) -->
                <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <label class="block font-black text-slate-900 text-xs">
                                <span>المرحلة والوضعية المهنية للسائق (دفتر المقررة)</span>
                                <span class="text-rose-500">*</span>
                            </label>
                            <p class="text-[11px] text-slate-500 mt-0.5">حدد مرحلة التجربة أو التثبيت كـ «سائق رسمي» وفق أحكام قانون العمل 90-11:</p>
                        </div>
                        <span class="text-[10px] text-amber-900 font-bold bg-amber-100/90 border border-amber-200 px-2.5 py-0.5 rounded-md">
                            المادة 07 (قانون 90-11)
                        </span>
                    </div>

                    <!-- 4 Visual Selection Cards for Probation Stages -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5" id="probationStageCardsContainer">
                        <label class="cursor-pointer relative flex flex-col items-center justify-center p-3.5 rounded-xl border-2 transition text-center select-none bg-white hover:border-amber-400 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/80 has-[:checked]:shadow-xs">
                            <input type="radio" name="probation_stage" value="trial_1" checked onchange="updateDriverPreviewStage(this.value)" class="sr-only">
                            <span class="w-3 h-3 rounded-full bg-amber-500 mb-1.5"></span>
                            <span class="font-black text-slate-900 text-xs">فترة تجريبية الأولى</span>
                            <span class="text-[10px] text-slate-500 mt-0.5 font-medium">1ère Période (06 أشهر)</span>
                        </label>

                        <label class="cursor-pointer relative flex flex-col items-center justify-center p-3.5 rounded-xl border-2 transition text-center select-none bg-white hover:border-blue-400 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/80 has-[:checked]:shadow-xs">
                            <input type="radio" name="probation_stage" value="trial_2" onchange="updateDriverPreviewStage(this.value)" class="sr-only">
                            <span class="w-3 h-3 rounded-full bg-blue-500 mb-1.5"></span>
                            <span class="font-black text-slate-900 text-xs">تجريبية الثانية</span>
                            <span class="text-[10px] text-slate-500 mt-0.5 font-medium">2ème Période d'essai</span>
                        </label>

                        <label class="cursor-pointer relative flex flex-col items-center justify-center p-3.5 rounded-xl border-2 transition text-center select-none bg-white hover:border-indigo-400 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50/80 has-[:checked]:shadow-xs">
                            <input type="radio" name="probation_stage" value="trial_3" onchange="updateDriverPreviewStage(this.value)" class="sr-only">
                            <span class="w-3 h-3 rounded-full bg-indigo-500 mb-1.5"></span>
                            <span class="font-black text-slate-900 text-xs">تجريبية الثالثة</span>
                            <span class="text-[10px] text-slate-500 mt-0.5 font-medium">3ème Période d'essai</span>
                        </label>

                        <label class="cursor-pointer relative flex flex-col items-center justify-center p-3.5 rounded-xl border-2 transition text-center select-none bg-white hover:border-emerald-400 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50/90 has-[:checked]:shadow-xs">
                            <input type="radio" name="probation_stage" value="official" onchange="updateDriverPreviewStage(this.value)" class="sr-only">
                            <span class="w-3 h-3 rounded-full bg-emerald-600 mb-1.5"></span>
                            <span class="font-black text-emerald-950 text-xs">سائق رسمي</span>
                            <span class="text-[10px] text-emerald-700 mt-0.5 font-bold">تثبيت دائم (Titulaire)</span>
                        </label>
                    </div>
                </div>

            </div>

            <!-- ==============================================
                 STEP 3: المراقبة والفحوصات الطبية الدورية
                 ============================================== -->
            <div id="stepSection-3" class="wizard-section space-y-5 hidden">
                
                <div class="p-3 bg-emerald-50/60 rounded-xl border border-emerald-200/80 flex items-center gap-2 text-emerald-900 font-bold text-xs">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                    <span>الصفحة 8-9 من دفتر المقررة: الفحص الطبي الإجباري الدوري (صلاحية سنتين وفق المرسوم 12-230)</span>
                </div>

                <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-4">
                    <h4 class="font-black text-slate-800 text-xs flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        <span>فترة صلاحية الفحص الطبي الدوري</span>
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">صلاحية الفحص الطبي: من (Date début)</label>
                            <input type="date" name="medical_exam_start" id="medStartInput" value="2024-09-01"
                                   onchange="autoCalculateMedicalDates()"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">
                                <span>صلاحية الفحص الطبي: إلى (Fin - سنتين)</span>
                                <span class="text-[10px] text-emerald-600 font-normal mr-1">(محسوب تلقائياً)</span>
                            </label>
                            <input type="date" name="medical_exam_end" id="medEndInput" value="2026-08-31"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">تاريخ إجراء الفحص الطبي (Date Examen)</label>
                        <input type="date" value="2024-09-01"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                    </div>
                </div>

                <!-- Next Checkup Reminder -->
                <div class="p-4 rounded-2xl bg-amber-50/50 border border-amber-200/80 space-y-3">
                    <h4 class="font-black text-amber-900 text-xs flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                        <span>موعد الفحص الطبي الدوري القادم (Visite Médicale Prochaine)</span>
                    </h4>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">تاريخ الفحص القادم: من</label>
                            <input type="date" name="next_medical_start" value="2026-09-01"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">تاريخ الفحص القادم: إلى</label>
                            <input type="date" name="next_medical_end" value="2028-08-31"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                    </div>

                    <p class="text-[11px] text-slate-500 flex items-center gap-1.5 pt-1">
                        <span>💡</span>
                        <span>يقوم رادار الصلاحية في لوحة التحكم بتنبيهك تلقائياً قبل 30 يوماً من حلول هذا الموعد.</span>
                    </p>
                </div>

            </div>

            <!-- ==============================================
                 STEP 4: تعيين المركبة وعقد الاستغلال ومعاينة البطاقة
                 ============================================== -->
            <div id="stepSection-4" class="wizard-section space-y-5 hidden">
                
                <div class="p-3 bg-purple-50/60 rounded-xl border border-purple-200/80 flex items-center gap-2 text-purple-900 font-bold text-xs">
                    <svg class="w-4 h-4 text-purple-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V4.5a2.25 2.25 0 00-2.25-2.25h-4.5A2.25 2.25 0 005.25 4.5v3m11.25 0H5.25"/></svg>
                    <span>ربط السائق بمركبة من الأسطول وصلاحية عقد استغلال رخصة الأجرة</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    
                    <!-- Vehicle Assignment Selection -->
                    <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-3">
                        <label class="block font-black text-slate-800 text-xs">تعيين سيارة أجرة من الأسطول (Véhicule Assigné)</label>
                        
                        <select name="assigned_vehicle_id" id="assignedVehicleSelect" onchange="updateDriverPreviewVehicle()"
                                class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-white transition shadow-2xs">
                            <option value="">-- بدون تعيين مركبة حالياً (تعيين لاحقاً) --</option>
                            <?php foreach ($activeVehicles as $veh): 
                                $isOccupied = !empty($veh['current_driver_id']);
                            ?>
                                <option value="<?= $veh['id'] ?>" data-plate="<?= htmlspecialchars($veh['plate_number']) ?>" data-brand="<?= htmlspecialchars($veh['brand'] . ' ' . $veh['model']) ?>">
                                    <?= htmlspecialchars($veh['plate_number']) ?> - <?= htmlspecialchars($veh['brand'] . ' ' . $veh['model']) ?> (باب: <?= htmlspecialchars($veh['door_number']) ?>) <?= $isOccupied ? '[مع سائق: ' . htmlspecialchars($veh['current_driver_name']) . ']' : '[شاغرة]' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="text-[11px] text-slate-400">يمكنك ربط السائق بسيارة الأجرة مباشرة لتظهر في تتبع الأسطول الميداني ومخطط المناوبات.</p>
                    </div>

                    <!-- Contract Validity Period -->
                    <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="block font-black text-slate-800 text-xs">صلاحية عقد العمل ورخصة الأجرة</label>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="autoCalculateTaxiContractDates(3)" class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-500 text-slate-950 hover:bg-amber-600 transition shadow-2xs font-num">
                                    3 سنوات (قياسي)
                                </button>
                                <button type="button" onclick="autoCalculateTaxiContractDates(5)" class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-200 text-slate-700 hover:bg-slate-300 transition font-num">
                                    5 سنوات
                                </button>
                                <button type="button" onclick="autoCalculateTaxiContractDates(1)" class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-200 text-slate-700 hover:bg-slate-300 transition font-num">
                                    سنة واحدة
                                </button>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block font-bold text-slate-600 text-[11px] mb-1">من (Début)</label>
                                <input type="date" name="taxi_contract_start" id="taxiContractStart" value="2024-09-01"
                                       onchange="autoCalculateTaxiContractDates(3)"
                                       class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-600 text-[11px] mb-1">
                                    <span>إلى (Fin)</span>
                                    <span class="text-[10px] text-emerald-600 font-normal mr-1">(محسوب تلقائياً)</span>
                                </label>
                                <input type="date" name="taxi_contract_end" id="taxiContractEnd" value="2027-08-31"
                                       class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-400">عقد استغلال رخصة الأجرة وفق المرسوم التنفيذي 12-230. يحسب تاريخ الانتهاء تلقائياً بمجرد إدخال تاريخ البداية.</p>
                    </div>

                </div>

                <!-- LIVE DRIVER CARD PREVIEW -->
                <div class="p-4 rounded-2xl bg-slate-900 text-white space-y-3 shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-[11px] font-bold text-slate-400 flex items-center gap-1.5">
                            <span>🔍</span>
                            <span>معاينة البطاقة الفنية للسائق قبل الحفظ النهائي</span>
                        </span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500 text-slate-950 font-num">
                            TAXI CHAUFFEUR
                        </span>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="w-14 h-18 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center overflow-hidden shrink-0">
                            <img id="livePreviewImg" src="" class="w-full h-full object-cover hidden">
                            <span id="livePreviewInitials" class="text-lg font-black text-amber-500 font-num">?</span>
                        </div>
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center gap-2">
                                <h4 id="livePreviewName" class="font-black text-sm text-white truncate">يوسف حمو صالح</h4>
                                <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">نشط</span>
                                <span id="livePreviewStageBadge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                    فترة تجريبية الأولى
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-300 flex items-center gap-2 font-num">
                                <span id="livePreviewPhone">0661234567</span>
                                <span>•</span>
                                <span>01 - أدرار</span>
                            </div>
                            <div class="text-[10px] text-amber-400 font-semibold flex items-center gap-2">
                                <span>المركبة: <strong id="livePreviewVehicle" class="text-white font-num font-bold">لم تعين بعد</strong></span>
                                <span>•</span>
                                <span>دفتر المقررة: LIV-01-2024-047</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </form>

        <!-- Sticky Bottom Actions Footer -->
        <div class="px-6 py-4 bg-slate-50/90 border-t border-slate-200/80 flex items-center justify-between shrink-0">
            <button type="button" id="prevStepBtn" onclick="prevWizardStep()" 
                    class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-white transition text-xs hidden items-center gap-1.5 shadow-2xs">
                <span>🠔 السابق</span>
            </button>
            
            <div class="text-xs font-bold text-slate-400" id="wizardStepIndicator">
                الخطوة <span id="currentStepNum" class="font-num text-slate-900 font-black">1</span> من <span class="font-num">4</span>
            </div>

            <div class="flex items-center gap-2.5">
                <button type="button" onclick="closeAddDriverModal()" 
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-white transition text-xs shadow-2xs">
                    إلغاء
                </button>

                <button type="button" id="nextStepBtn" onclick="nextWizardStep()" 
                        class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-black transition text-xs flex items-center gap-1.5 shadow-xs cursor-pointer">
                    <span>التالي</span>
                    <span>➔</span>
                </button>

                <button type="button" id="submitDriverBtn" onclick="submitAddDriverForm()" 
                        class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-400 hover:from-amber-600 hover:to-amber-500 text-slate-950 font-black transition text-xs hidden items-center gap-2 shadow-md cursor-pointer">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    <span>حفظ وتسجيل البطاقة الفنية الرسمية</span>
                </button>
            </div>
        </div>

    </div>
</div>

<!-- Add Driver Wizard Interactive JavaScript -->
<script>
    let currentWizardStep = 1;
    const totalWizardSteps = 4;

    function openAddDriverModal() {
        const modal = document.getElementById('addDriverModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        goToWizardStep(1);
        updateDriverPreviewName();
        updateDriverPreviewPhone();
        updateDriverPreviewVehicle();
    }

    function closeAddDriverModal() {
        const modal = document.getElementById('addDriverModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    function goToWizardStep(step) {
        if (step < 1 || step > totalWizardSteps) return;

        // Validation before moving forward from step 1
        if (step > 1 && currentWizardStep === 1) {
            const lastName = document.getElementById('inputLastName');
            const firstName = document.getElementById('inputFirstName');
            const phone = document.getElementById('inputPhone');
            if (!lastName.value.trim() || !firstName.value.trim() || !phone.value.trim()) {
                alert('يرجى ملء الاسم، اللقب، ورقم الهاتف لمتابعة التسجيل.');
                if (!lastName.value.trim()) lastName.focus();
                else if (!firstName.value.trim()) firstName.focus();
                else phone.focus();
                return;
            }
        }

        currentWizardStep = step;

        // Show active section, hide others
        for (let i = 1; i <= totalWizardSteps; i++) {
            const section = document.getElementById('stepSection-' + i);
            const tab = document.getElementById('stepTab-' + i);
            if (section) {
                if (i === step) {
                    section.classList.remove('hidden');
                } else {
                    section.classList.add('hidden');
                }
            }
            if (tab) {
                if (i === step) {
                    tab.className = 'wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-900 text-white border-slate-900 shadow-xs';
                    tab.querySelector('span:first-child').className = 'w-5 h-5 rounded-full bg-white/20 text-white flex items-center justify-center text-[11px] font-num font-black shrink-0';
                } else if (i < step) {
                    tab.className = 'wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-amber-50 text-amber-900 border-amber-200/80 shadow-2xs';
                    tab.querySelector('span:first-child').className = 'w-5 h-5 rounded-full bg-amber-200 text-amber-900 flex items-center justify-center text-[11px] font-num font-black shrink-0';
                } else {
                    tab.className = 'wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100';
                    tab.querySelector('span:first-child').className = 'w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0';
                }
            }
        }

        // Progress bar width
        const progressLine = document.getElementById('wizardProgressLine');
        if (progressLine) {
            progressLine.style.width = ((step / totalWizardSteps) * 100) + '%';
        }

        // Indicator numbers
        document.getElementById('currentStepNum').textContent = step;

        // Bottom buttons visibility
        const prevBtn = document.getElementById('prevStepBtn');
        const nextBtn = document.getElementById('nextStepBtn');
        const submitBtn = document.getElementById('submitDriverBtn');

        if (step === 1) {
            prevBtn.classList.add('hidden');
            prevBtn.classList.remove('flex');
        } else {
            prevBtn.classList.remove('hidden');
            prevBtn.classList.add('flex');
        }

        if (step === totalWizardSteps) {
            nextBtn.classList.add('hidden');
            submitBtn.classList.remove('hidden');
            submitBtn.classList.add('flex');
        } else {
            nextBtn.classList.remove('hidden');
            submitBtn.classList.add('hidden');
            submitBtn.classList.remove('flex');
        }
    }

    function nextWizardStep() {
        goToWizardStep(currentWizardStep + 1);
    }

    function prevWizardStep() {
        goToWizardStep(currentWizardStep - 1);
    }

    function submitAddDriverForm() {
        const form = document.getElementById('addDriverForm');
        const lastName = document.getElementById('inputLastName');
        const firstName = document.getElementById('inputFirstName');
        const phone = document.getElementById('inputPhone');

        if (!lastName.value.trim() || !firstName.value.trim() || !phone.value.trim()) {
            goToWizardStep(1);
            alert('يرجى استيفاء الحقول الإجبارية (الاسم، اللقب، الهاتف) قبل الحفظ.');
            return;
        }
        form.submit();
    }

    // Live avatar preview
    function previewDriverAvatar(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImg = document.getElementById('driverAvatarPreview');
                const placeholder = document.getElementById('photoPlaceholder');
                const livePreviewImg = document.getElementById('livePreviewImg');
                const livePreviewInitials = document.getElementById('livePreviewInitials');

                previewImg.src = e.target.result;
                previewImg.classList.remove('hidden');
                placeholder.classList.add('hidden');

                if (livePreviewImg) {
                    livePreviewImg.src = e.target.result;
                    livePreviewImg.classList.remove('hidden');
                }
                if (livePreviewInitials) {
                    livePreviewInitials.classList.add('hidden');
                }
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Live preview name
    function updateDriverPreviewName() {
        const first = document.getElementById('inputFirstName')?.value.trim() || '';
        const last = document.getElementById('inputLastName')?.value.trim() || '';
        const fullName = (first + ' ' + last).trim() || 'اسم السائق';
        
        const previewName = document.getElementById('livePreviewName');
        const previewInitials = document.getElementById('livePreviewInitials');

        if (previewName) previewName.textContent = fullName;
        if (previewInitials && first) {
            previewInitials.textContent = first.charAt(0);
        }
    }

    // Live preview phone
    function updateDriverPreviewPhone() {
        const phone = document.getElementById('inputPhone')?.value.trim() || '0661000000';
        const previewPhone = document.getElementById('livePreviewPhone');
        if (previewPhone) previewPhone.textContent = phone;
    }

    // Live preview vehicle
    function updateDriverPreviewVehicle() {
        const select = document.getElementById('assignedVehicleSelect');
        const previewVeh = document.getElementById('livePreviewVehicle');
        if (!select || !previewVeh) return;

        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const plate = selectedOption.getAttribute('data-plate') || '';
            const brand = selectedOption.getAttribute('data-brand') || '';
            previewVeh.textContent = plate + ' (' + brand + ')';
        } else {
            previewVeh.textContent = 'لم تعين بعد (شاغرة)';
        }
    }

    // Live preview probation stage badge
    function updateDriverPreviewStage(stage) {
        const badge = document.getElementById('livePreviewStageBadge');
        if (!badge) return;
        const stageMap = {
            'trial_1': { text: 'فترة تجريبية الأولى', cls: 'bg-amber-500/20 text-amber-300 border-amber-500/30' },
            'trial_2': { text: 'تجريبية الثانية', cls: 'bg-blue-500/20 text-blue-300 border-blue-500/30' },
            'trial_3': { text: 'تجريبية الثالثة', cls: 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30' },
            'official': { text: 'سائق رسمي', cls: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' }
        };
        const s = stageMap[stage] || stageMap['trial_1'];
        badge.textContent = s.text;
        badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold border ' + s.cls;
    }

    // Auto-calculate medical dates (+2 years)
    function autoCalculateMedicalDates() {
        const startInput = document.getElementById('medStartInput');
        const endInput = document.getElementById('medEndInput');
        if (startInput && startInput.value && endInput) {
            const startDate = new Date(startInput.value);
            if (!isNaN(startDate.getTime())) {
                const endDate = new Date(startDate);
                endDate.setFullYear(endDate.getFullYear() + 2);
                endDate.setDate(endDate.getDate() - 1);
                endInput.value = endDate.toISOString().split('T')[0];
            }
        }
    }

    // Auto-calculate Taxi Contract dates (+3 years default)
    function autoCalculateTaxiContractDates(years = 3) {
        const startInput = document.getElementById('taxiContractStart');
        const endInput = document.getElementById('taxiContractEnd');
        if (startInput && startInput.value && endInput) {
            const startDate = new Date(startInput.value);
            if (!isNaN(startDate.getTime())) {
                const endDate = new Date(startDate);
                endDate.setFullYear(endDate.getFullYear() + parseInt(years));
                endDate.setDate(endDate.getDate() - 1);
                endInput.value = endDate.toISOString().split('T')[0];
            }
        }
    }

    // Keyboard support: Escape closes modal
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('addDriverModal');
            if (modal && !modal.classList.contains('hidden')) {
                closeAddDriverModal();
            }
        }
    });

    // Close when clicking modal backdrop
    document.getElementById('addDriverModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeAddDriverModal();
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


