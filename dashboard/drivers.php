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
                    driver_type, activity_start_date, dtw_visa_date, card_number,
                    medical_cert_expiry, medical_exam_start, medical_exam_end, medical_visa_date,
                    next_medical_start, next_medical_end, taxi_contract_start, taxi_contract_end, avatar_url
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 'driver', 'active',
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $fullName, $firstName, $lastName, $email, password_hash('driver123', PASSWORD_BCRYPT), $phone,
                $wilaya, $birthDate, $birthPlace, $fatherName, $motherName, $address,
                $licenseNumber, $licenseIssueDate, $licenseIssuePlace, $licenseExpiry,
                $driverType, $activityStartDate, $dtwVisaDate, $cardNumber,
                $medEnd, $medStart, $medEnd, $medVisaDate,
                $nextMedStart, $nextMedEnd, $taxiContractStart, $taxiContractEnd, $avatarUrl
            ]);
            $newDriverId = (int)$pdo->lastInsertId();
            log_audit($pdo, 'driver_created', 'users', $newDriverId, "تسجيل بطاقة فنية لسائق جديد: $fullName ($phone)");
            set_flash('success', 'تم تسجيل السائق بنجاح وإصدار بطاقته الفنية');
            redirect('/dashboard/driver_fiche.php?id=' . $newDriverId);
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء التسجيل: ' . $e->getMessage());
        }
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, actifs, permanence, anciens

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

// If filter is permanence
$drivers = [];
foreach ($allDrivers as $d) {
    $isPerm = in_array($d['id'], $permanenceDriverIds);
    $d['is_permanence'] = $isPerm;

    if ($filter === 'permanence' && !$isPerm) {
        continue;
    }
    $drivers[] = $d;
}

// Display counts
$countActifs = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status = 'active'")->fetchColumn();
$countAnciens = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status != 'active'")->fetchColumn();
$countPermanence = count($permanenceDriverIds);
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
            <button onclick="document.getElementById('addDriverModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
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
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                الكل
            </a>

            <a href="drivers.php?filter=actifs<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'actifs' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                <span>سائقون نشطون</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white/80 font-num font-bold text-slate-900"><?= $countActifs ?></span>
            </a>

            <a href="drivers.php?filter=permanence<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'permanence' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-800 border border-amber-200 hover:bg-amber-100' ?>">
                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
                <span>سائقو المداومة (Permanence)</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-700 text-white font-num"><?= $countPermanence ?></span>
            </a>

            <a href="debts.php?filter=all" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 bg-slate-100 text-slate-600 hover:bg-slate-200">
                <span>سابقون (ديون)</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-slate-300 font-num font-bold text-slate-800"><?= $countAnciens ?></span>
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
                                                <span class="px-1.5 py-0.2 rounded text-[10px] font-bold <?= ($d['driver_type'] ?? 'ajir') === 'ajir' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-slate-100 text-slate-700' ?>">
                                                    <?= ($d['driver_type'] ?? 'ajir') === 'ajir' ? 'أجير (90-11)' : 'مؤجر' ?>
                                                </span>
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

<!-- Modal: Add Driver (Fiche Technique Complète) -->
<div id="addDriverModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="bg-white max-w-2xl w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-5 text-right my-8 max-h-[90vh] overflow-y-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-black text-slate-900 text-lg">تسجيل سائق سيارة أجرة جديد (دفتر المقررة)</h3>
                <span class="text-xs text-slate-500">إدخال البطاقة الفنية، رخصة السياقة، الفحوصات الطبية، وعقد استغلال رخصة الأجرة</span>
            </div>
            <button onclick="document.getElementById('addDriverModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="space-y-5 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_driver">

            <!-- Tab 1: الحالة المدنية والهوية الشخصية -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200/80 space-y-3">
                <h4 class="font-bold text-slate-800 text-xs border-b border-slate-200 pb-1.5 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span>1. الحالة المدنية والهوية الشخصية (الصفحة 4 من الدفتر)</span>
                </h4>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اللقب (Nom) <span class="text-rose-500">*</span></label>
                        <input type="text" name="last_name" required placeholder="مثال: حمو صالح"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">الاسم (Prénom) <span class="text-rose-500">*</span></label>
                        <input type="text" name="first_name" required placeholder="مثال: يوسف"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ الازدياد (Date de Naissance)</label>
                        <input type="date" name="birth_date"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">مكان الازدياد (Lieu de Naissance)</label>
                        <input type="text" name="birth_place" placeholder="مثال: بعريانو تامست"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اسم الأب (إبن)</label>
                        <input type="text" name="father_name" placeholder="مثال: أحمد"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اسم ولقب الأم (و)</label>
                        <input type="text" name="mother_name" placeholder="مثال: حمو صالح خيرة"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block font-bold text-slate-700 mb-1">العنوان الشخصي</label>
                        <input type="text" name="address" placeholder="مثال: حي الحطابة أدرار"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">الولاية</label>
                        <input type="text" name="wilaya" value="01 - أدرار"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم الهاتف <span class="text-rose-500">*</span></label>
                        <input type="tel" name="phone" required placeholder="0661234567" dir="ltr"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num text-right transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">صورة السائق (Photo Portrait)</label>
                        <input type="file" name="avatar" accept="image/*"
                               class="w-full text-xs text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-bold file:bg-amber-100 file:text-amber-800 hover:file:bg-amber-200 transition">
                    </div>
                </div>
            </div>

            <!-- Tab 2: رخصة السياقة والكفاءة المهنية -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200/80 space-y-3">
                <h4 class="font-bold text-slate-800 text-xs border-b border-slate-200 pb-1.5 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    <span>2. رخصة السياقة وبطاقة الكفاءة المهنية</span>
                </h4>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم رخصة السياقة</label>
                        <input type="text" name="license_number" placeholder="مثال: A02669719" dir="ltr"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num text-right transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ الإصدار</label>
                        <input type="date" name="license_issue_date"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">جهة الإصدار (من طرف)</label>
                        <input type="text" name="license_issue_place" placeholder="مثال: بلدية أدرار"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ انتهاء صلاحية الرخصة</label>
                        <input type="date" name="license_expiry"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم دفتر المقررة / البطاقة المهنية</label>
                        <input type="text" name="card_number" placeholder="مثال: LIV-01-2024-047" dir="ltr"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num text-right transition">
                    </div>
                </div>
            </div>

            <!-- Tab 3: عقد استغلال رخصة الأجرة والفحص الطبي وبداية النشاط -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200/80 space-y-3">
                <h4 class="font-bold text-slate-800 text-xs border-b border-slate-200 pb-1.5 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>3. عقد رخصة الأجرة، الفحص الطبي الدوري وبداية النشاط (الصفحة 7)</span>
                </h4>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">صفة السائق</label>
                        <select name="driver_type" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white transition">
                            <option value="ajir" selected>أجير (Salarié) - القانون 90-11</option>
                            <option value="loueur">مؤجر (Loueur de licence)</option>
                            <option value="artisan">حرفي تاكسي (Artisan Taxi)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ بداية النشاط</label>
                        <input type="date" name="activity_start_date" value="2024-09-03"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تأشيرة مديرية النقل (DTW)</label>
                        <input type="date" name="dtw_visa_date" value="2024-09-03"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">صلاحية عقد رخصة الأجرة (من)</label>
                        <input type="date" name="taxi_contract_start" value="2024-09-01"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">صلاحية عقد رخصة الأجرة (إلى)</label>
                        <input type="date" name="taxi_contract_end" value="2027-08-31"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">الفحص الطبي (من)</label>
                        <input type="date" name="medical_exam_start" value="2024-09-01"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">الفحص الطبي (إلى - سنتين)</label>
                        <input type="date" name="medical_exam_end" value="2026-08-31"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تأشيرة الفحص الطبي DTW</label>
                        <input type="date" name="medical_visa_date" value="2024-09-20"
                               class="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num bg-white transition">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('addDriverModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition shadow-xs flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    <span>حفظ وتسجيل البطاقة الفنية</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

