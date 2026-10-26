<?php
// dashboard/insurance.php - Gérer Assurance: Gestion, Alertes et Renouvellement des Polices d'Assurance
$pageTitle = 'Gérer Assurance | إدارة وثائق التأمين';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

// Handle New/Renew Insurance Policy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_insurance') {
    require_csrf_token();
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $policyNumber = trim($_POST['policy_number'] ?? '');
    $company = trim($_POST['company'] ?? 'SAA');
    if (empty($policyNumber)) {
        $policyNumber = 'POL-' . $company . '-' . date('Y') . '-' . rand(10000, 99999);
    }
    $coverageType = trim($_POST['coverage_type'] ?? 'Tous Risques + Transport Public Voyageurs');
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d', strtotime('+1 year'));
    $premium = floatval($_POST['premium_amount'] ?? 35000);
    $attestation = trim($_POST['attestation_number'] ?? ('ATT-' . $company . '-' . date('Y') . '-' . rand(100, 999)));

    if ($vehicleId > 0 && !empty($endDate)) {
        try {
            $pdo->beginTransaction();
            // Insert into insurance_policies
            $stmt = $pdo->prepare("
                INSERT INTO insurance_policies (vehicle_id, policy_number, company, coverage_type, start_date, end_date, premium_amount, attestation_number, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$vehicleId, $policyNumber, $company, $coverageType, $startDate, $endDate, $premium, $attestation]);
            $insId = (int)$pdo->lastInsertId();

            // Synchronize with vehicles table
            $stmtVeh = $pdo->prepare("
                UPDATE vehicles 
                SET insurance_expiry = ?, insurance_company = ?
                WHERE id = ?
            ");
            $stmtVeh->execute([$endDate, $company, $vehicleId]);

            $pdo->commit();
            log_audit($pdo, 'insurance_saved', 'insurance_policies', $insId, "تسجيل وتجديد وثيقة التأمين رقم $policyNumber لدى $company للمركبة رقم $vehicleId حتى $endDate");

            set_flash('success', "تم تسجيل وتجديد وثيقة التأمين رقم $policyNumber لدى شركة $company بنجاح");
            redirect('/dashboard/insurance.php');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء حفظ وثيقة التأمين: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى ملء جميع الحقول الإلزامية');
    }
}

// Handle Edit Insurance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_insurance') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بتعديل وثائق التأمين.');
        redirect('/dashboard/insurance.php');
    }
    $policyId = intval($_POST['policy_id'] ?? 0);
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $policyNumber = trim($_POST['policy_number'] ?? '');
    $company = trim($_POST['company'] ?? 'SAA');
    $coverageType = trim($_POST['coverage_type'] ?? 'Tous Risques + Transport Public Voyageurs');
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d', strtotime('+1 year'));
    $premium = floatval($_POST['premium_amount'] ?? 35000);
    $attestation = trim($_POST['attestation_number'] ?? '');

    if ($vehicleId > 0 && !empty($endDate)) {
        try {
            $pdo->beginTransaction();
            if ($policyId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE insurance_policies 
                    SET policy_number = ?, company = ?, coverage_type = ?, start_date = ?, end_date = ?, premium_amount = ?, attestation_number = ?
                    WHERE id = ?
                ");
                $stmt->execute([$policyNumber, $company, $coverageType, $startDate, $endDate, $premium, $attestation, $policyId]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO insurance_policies (vehicle_id, policy_number, company, coverage_type, start_date, end_date, premium_amount, attestation_number, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$vehicleId, $policyNumber, $company, $coverageType, $startDate, $endDate, $premium, $attestation]);
            }

            // Synchronize with vehicles table
            $stmtVeh = $pdo->prepare("
                UPDATE vehicles 
                SET insurance_expiry = ?, insurance_company = ?
                WHERE id = ?
            ");
            $stmtVeh->execute([$endDate, $company, $vehicleId]);

            $pdo->commit();
            log_audit($pdo, 'insurance_edited', 'insurance_policies', $policyId, "تعديل بيانات وثيقة التأمين للمركبة رقم $vehicleId لدى شركة $company");
            set_flash('success', "تم تعديل وثيقة التأمين بنجاح");
            redirect('/dashboard/insurance.php');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء تعديل وثيقة التأمين: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى ملء جميع الحقول الإلزامية');
    }
}

// Handle Delete Insurance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_insurance') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف وثائق التأمين.');
        redirect('/dashboard/insurance.php');
    }
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $policyId = intval($_POST['policy_id'] ?? 0);
    if ($vehicleId > 0) {
        try {
            $pdo->beginTransaction();
            if ($policyId > 0) {
                $pdo->prepare("UPDATE insurance_policies SET deleted_at = NOW() WHERE id = ?")->execute([$policyId]);
            }
            $pdo->prepare("UPDATE vehicles SET insurance_expiry = NULL, insurance_company = NULL WHERE id = ?")->execute([$vehicleId]);
            $pdo->commit();
            log_audit($pdo, 'insurance_deleted', 'vehicles', $vehicleId, "نقل وثيقة التأمين للمركبة رقم $vehicleId إلى سلة المهملات");
            set_flash('success', "تم نقل وثيقة التأمين إلى سلة المهملات والأرشيف بنجاح");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء حذف الوثيقة: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/insurance.php');
}

// Filters & Search
$search = trim($_GET['search'] ?? '');
$filterCompany = trim($_GET['company'] ?? 'all');
$filterStatus = trim($_GET['status'] ?? 'all'); // all, imminent, active, expired

// Fetch All Vehicles for selector
$allVehicles = [];
if ($pdo) {
    try {
        $stmtAllV = $pdo->query("
            SELECT id, brand, model, plate_number, door_number 
            FROM vehicles 
            WHERE deleted_at IS NULL 
            ORDER BY CASE WHEN door_number IS NOT NULL AND door_number != '' THEN 0 ELSE 1 END,
                     CAST(SUBSTRING_INDEX(door_number, '-', -1) AS UNSIGNED) ASC,
                     door_number ASC,
                     id ASC
        ");
        $allVehicles = $stmtAllV ? $stmtAllV->fetchAll() : [];
    } catch (\Throwable $e) {
        $allVehicles = [];
    }
}

// Query Insurance list with vehicles & drivers
$rows = [];
if ($pdo) {
    $sql = "
        SELECT v.id as vehicle_id, v.brand, v.model, v.plate_number, v.door_number, v.insurance_expiry, v.insurance_company,
               u.full_name as driver_name, u.phone as driver_phone,
               p.id as policy_id, p.policy_number, p.company as pol_company, p.coverage_type, p.start_date, p.end_date,
               p.premium_amount, p.attestation_number,
               DATEDIFF(COALESCE(p.end_date, v.insurance_expiry), CURDATE()) as days_left
        FROM vehicles v
        LEFT JOIN (
            SELECT p1.*
            FROM insurance_policies p1
            INNER JOIN (
                SELECT vehicle_id, MAX(id) as max_id
                FROM insurance_policies
                WHERE deleted_at IS NULL
                GROUP BY vehicle_id
            ) p2 ON p1.id = p2.max_id
            WHERE p1.deleted_at IS NULL
        ) p ON v.id = p.vehicle_id
        LEFT JOIN users u ON v.current_driver_id = u.id
        WHERE v.deleted_at IS NULL
    ";

    $params = [];

    if (!empty($search)) {
        $sql .= " AND (v.plate_number LIKE ? OR v.door_number LIKE ? OR p.policy_number LIKE ? OR v.brand LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filterCompany !== 'all' && !empty($filterCompany)) {
        $sql .= " AND (p.company = ? OR v.insurance_company = ?)";
        $params[] = $filterCompany;
        $params[] = $filterCompany;
    }

    $sql .= " ORDER BY CASE WHEN v.door_number IS NOT NULL AND v.door_number != '' THEN 0 ELSE 1 END,
                        CAST(SUBSTRING_INDEX(v.door_number, '-', -1) AS UNSIGNED) ASC,
                        v.door_number ASC,
                        v.id ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        error_log("insurance.php - Query policies error: " . $e->getMessage());
        $rows = [];
    }
}

// Filter in-memory for days_left
$filteredRows = [];
$totalCount = count($rows);
$imminentCount = 0;
$activeCount = 0;
$expiredCount = 0;

foreach ($rows as $r) {
    $days = $r['days_left'] !== null ? intval($r['days_left']) : 999;
    
    if ($days < 0) {
        $expiredCount++;
        $statusKey = 'expired';
    } elseif ($days <= 15) {
        $imminentCount++;
        $statusKey = 'imminent';
    } else {
        $activeCount++;
        $statusKey = 'active';
    }

    if ($filterStatus === 'all' || $filterStatus === $statusKey) {
        $filteredRows[] = $r;
    }
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Gérer Assurance</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    إدارة وثائق التأمين
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">تتبع صلاحية بوالص التأمين، التنبيهات الاستباقية وتجديد عقود شركات التأمين المعتمدة (SAA, CAAT, CIAR, CASH, Alliance)</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('renewModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>تجديد / تسجيل بوليصة جديدة</span>
            </button>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إجمالي الأسطول المؤمن</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $totalCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-amber-700">تنبيه وشيك (&le; 15 يوم)</p>
                <p class="text-xl font-bold text-amber-600 font-num mt-0.5"><?= $imminentCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">بوالص سارية المفعول</p>
                <p class="text-xl font-bold text-emerald-600 font-num mt-0.5"><?= $activeCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">بوالص منتهية</p>
                <p class="text-xl font-bold <?= $expiredCount > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num mt-0.5"><?= $expiredCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $expiredCount > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Imminent Expiration Warning Alert Banner -->
    <?php if ($imminentCount > 0): ?>
        <div class="bg-amber-50 border border-amber-200/80 text-amber-950 p-4 rounded-xl flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-xs">تنبيه حرج: توجد <?= $imminentCount ?> مركبات تنتهي صلاحية تأمينها خلال أقل من 15 يوماً</h3>
                    <p class="text-[11px] text-amber-800 mt-0.5">تفرض لوائح النقل البري التوقيف الفوري لأي سيارة أجرة لا تحمل شهادة تأمين سارية المفعول.</p>
                </div>
            </div>
            <button onclick="document.getElementById('renewModal').classList.remove('hidden')" 
                    class="px-3.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs whitespace-nowrap transition shadow-xs">
                تجديد البوالص الآن
            </button>
        </div>
    <?php endif; ?>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="بحث برقم اللوحة، الباب، أو البوليصة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filterCompany !== 'all' || $filterStatus !== 'all'): ?>
                <a href="insurance.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <!-- Company & Status Filters -->
        <div class="flex items-center gap-2 w-full sm:w-auto">
            <select onchange="location.href='<?= url('/dashboard/insurance.php') ?>?company=' + this.value + '&status=<?= urlencode($filterStatus) ?>'" 
                    class="px-3 py-1.5 rounded-lg border border-slate-200 font-semibold text-xs bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <option value="all" <?= $filterCompany === 'all' ? 'selected' : '' ?>>كل الشركات</option>
                <option value="SAA" <?= $filterCompany === 'SAA' ? 'selected' : '' ?>>SAA (الشركة الوطنية)</option>
                <option value="CAAT" <?= $filterCompany === 'CAAT' ? 'selected' : '' ?>>CAAT</option>
                <option value="CIAR" <?= $filterCompany === 'CIAR' ? 'selected' : '' ?>>CIAR</option>
                <option value="CASH" <?= $filterCompany === 'CASH' ? 'selected' : '' ?>>CASH Assurances</option>
                <option value="Alliance" <?= $filterCompany === 'Alliance' ? 'selected' : '' ?>>Alliance</option>
            </select>

            <div class="flex items-center gap-1">
                <a href="insurance.php?company=<?= urlencode($filterCompany) ?>&status=all" 
                   class="px-3 py-1.5 rounded-lg font-bold transition <?= $filterStatus === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                    الكل
                </a>
                <a href="insurance.php?company=<?= urlencode($filterCompany) ?>&status=imminent" 
                   class="px-3 py-1.5 rounded-lg font-bold transition <?= $filterStatus === 'imminent' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-100 text-amber-700 hover:bg-amber-50' ?>">
                    وشيكة (&le; 15j)
                </a>
                <a href="insurance.php?company=<?= urlencode($filterCompany) ?>&status=active" 
                   class="px-3 py-1.5 rounded-lg font-bold transition <?= $filterStatus === 'active' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-emerald-700 hover:bg-emerald-50' ?>">
                    سارية
                </a>
            </div>
        </div>

    </div>

    <!-- Insurance Policies Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap">المركبة المعنية</th>
                        <th class="p-3.5 whitespace-nowrap">السائق المكلف</th>
                        <th class="p-3.5 whitespace-nowrap">شركة التأمين</th>
                        <th class="p-3.5 whitespace-nowrap">رقم بوليصة التأمين</th>
                        <th class="p-3.5 whitespace-nowrap">نوع التغطية</th>
                        <th class="p-3.5 whitespace-nowrap">تاريخ الانتهاء</th>
                        <th class="p-3.5 whitespace-nowrap text-center">المدة المتبقية</th>
                        <th class="p-3.5 whitespace-nowrap text-center">الحالة</th>
                        <th class="p-3.5 text-center whitespace-nowrap">إجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($filteredRows)): ?>
                        <?php foreach ($filteredRows as $row): 
                            $days = $row['days_left'] !== null ? intval($row['days_left']) : 999;
                            $expDate = $row['end_date'] ?? $row['insurance_expiry'] ?? 'غير محدد';
                            $company = $row['pol_company'] ?? $row['insurance_company'] ?? 'SAA';
                            $policy = $row['policy_number'] ?? ('POL-' . $company . '-2026');
                            $coverage = $row['coverage_type'] ?? 'تأمين تجاري شامل + ركاب';
                        ?>
                            <tr class="hover:bg-slate-50/60 transition <?= $days <= 15 && $days >= 0 ? 'bg-amber-50/15' : ($days < 0 ? 'bg-rose-50/15' : '') ?>">
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 text-[13px]"><?= htmlspecialchars($row['brand'] . ' ' . $row['model']) ?></div>
                                    <div class="text-[11px] font-num text-slate-500" dir="ltr">
                                        <?= htmlspecialchars($row['plate_number']) ?> 
                                        <span class="text-slate-400 font-normal">(باب: <?= htmlspecialchars($row['door_number']) ?>)</span>
                                    </div>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($row['driver_name'])): ?>
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($row['driver_name']) ?></div>
                                        <div class="text-[11px] text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($row['driver_phone']) ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">غير معين</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold
                                        <?= $company === 'SAA' ? 'bg-blue-50 text-blue-700 border border-blue-200/60' :
                                           ($company === 'CAAT' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200/60' :
                                           ($company === 'CIAR' ? 'bg-purple-50 text-purple-700 border border-purple-200/60' :
                                           'bg-slate-100 text-slate-800 border border-slate-200/60')) ?>">
                                        <?= htmlspecialchars($company) ?>
                                    </span>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num font-mono text-slate-900 font-bold"><?= htmlspecialchars($policy) ?></div>
                                    <?php if (!empty($row['attestation_number'])): ?>
                                        <div class="mt-1">
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold font-num bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                وصل: <?= htmlspecialchars($row['attestation_number']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-slate-600 dark:text-slate-300 text-[11px]">
                                    <?= htmlspecialchars($coverage) ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num">
                                    <?php if ($days < 0): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-rose-100 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-800" dir="ltr">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span>
                                            <span><?= htmlspecialchars($expDate) ?></span>
                                        </span>
                                    <?php elseif ($days <= 15): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-amber-100 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800" dir="ltr">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                                            <span><?= htmlspecialchars($expDate) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800" dir="ltr">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            <span><?= htmlspecialchars($expDate) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-center font-num">
                                    <?php if ($days < 0): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800">منتهي منذ <?= abs($days) ?> يوم</span>
                                    <?php elseif ($days <= 15): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                            باقي <?= $days ?> أيام
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">باقي <?= $days ?> يوم</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($days < 0): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 font-bold text-[10px] border border-rose-200/60">
                                            منتهي
                                        </span>
                                    <?php elseif ($days <= 15): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 font-bold text-[10px] border border-amber-200/60">
                                            وشيك الانتهاء
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold text-[10px] border border-emerald-200/60">
                                            ساري المفعول
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="relative inline-block text-right">
                                        <button type="button"
                                                onclick="toggleTableDropdown(event, this)"
                                                class="table-dropdown-btn w-8 h-8 rounded-xl bg-slate-100 hover:bg-amber-500/15 hover:text-amber-700 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 shadow-2xs cursor-pointer"
                                                title="خيارات وثيقة التأمين">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                            </svg>
                                        </button>

                                        <div class="table-dropdown-menu hidden w-60 min-w-[240px] rounded-2xl bg-white/95 dark:bg-[#161d27]/95 backdrop-blur-md border border-slate-200 dark:border-slate-800 shadow-2xl p-1.5 space-y-0.5 text-xs text-right select-none">
                                            <button type="button"
                                                    onclick="openRenewModalFor(<?= $row['vehicle_id'] ?>, '<?= htmlspecialchars($row['plate_number']) ?>', '<?= htmlspecialchars($company) ?>')"
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-amber-50 dark:hover:bg-amber-900/30 hover:text-amber-800 dark:hover:text-amber-300 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                </svg>
                                                <span>تجديد / تمديد البوليصة</span>
                                            </button>

                                            <?php if (!empty($row['insurance_expiry'])): ?>
                                            <button type="button"
                                                    onclick='openEditInsuranceModal(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل تفاصيل الوثيقة</span>
                                            </button>
                                            <?php endif; ?>

                                            <?php if (is_admin() && !empty($row['insurance_expiry'])): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف وتصفير وثيقة التأمين لهذه المركبة؟')" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_insurance">
                                                <input type="hidden" name="vehicle_id" value="<?= $row['vehicle_id'] ?>">
                                                <input type="hidden" name="policy_id" value="<?= $row['policy_id'] ?? 0 ?>">
                                                <button type="submit"
                                                        class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-bold transition text-right cursor-pointer">
                                                    <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    <span>حذف وثيقة التأمين</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="p-10 text-center text-slate-400">
                                لا توجد وثائق تأمين مطابقة لشروط البحث والتصفية المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Renouveler / Souscrire Police d'assurance -->
<div id="renewModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full border border-slate-200 shadow-2xl overflow-hidden space-y-4 p-6 sm:p-7 text-right m-auto">
        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">تجديد أو تسجيل بوليصة تأمين (Gérer Assurance)</h3>
                <span class="text-xs text-slate-500">حفظ بيانات عقد التأمين التجاري ومطابقة الوثيقة للأسطول</span>
            </div>
            <button onclick="document.getElementById('renewModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_insurance">

            <!-- Vehicle selection -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">المركبة المعنية <span class="text-rose-500">*</span></label>
                <select name="vehicle_id" id="modalVehicleSelect" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white font-semibold text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <?php foreach ($allVehicles as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (لوحة: <?= htmlspecialchars($v['plate_number']) ?> - باب: <?= htmlspecialchars($v['door_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Hidden Company & Policy fields for background default -->
            <input type="hidden" name="company" id="modalCompanySelect" value="SAA">
            <input type="hidden" name="policy_number" id="modalPolicyInput" value="">
            <input type="hidden" name="start_date" value="<?= date('Y-m-d') ?>">

            <!-- Coverage Type -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">نوع التغطية التأمينية</label>
                <select name="coverage_type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white font-semibold text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <option value="Tous Risques + Transport Public Voyageurs">Tous Risques + Transport Public Voyageurs (شامل + ركاب)</option>
                    <option value="Responsabilité Civile + Défense & Recours">Responsabilité Civile + Défense & Recours (المسؤولية المدنية)</option>
                    <option value="Tous Risques Flotte Taxi">Tous Risques Flotte Taxi (تأمين أسطول سيارات الأجرة)</option>
                </select>
            </div>

            <!-- Expiry Date (End Date) -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء صلاحية التأمين <span class="text-rose-500">*</span></label>
                <input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num font-bold text-xs bg-white focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition shadow-2xs">
                <p class="text-[11px] text-slate-400 mt-1">تاريخ انتهاء وثيقة التأمين المسجل على شهادة التأمين الرسمية.</p>
            </div>

            <!-- Premium & Attestation Ref -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">مبلغ قسط التأمين (DZD)</label>
                    <input type="number" step="500" name="premium_amount" value="38000"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">رقم الوصل / الشهادة المؤقتة</label>
                    <input type="text" name="attestation_number" placeholder="ATT-16-..."
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num font-mono text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('renewModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold shadow-xs transition">
                    تأكيد التجديد وحفظ الوثيقة
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Insurance Policy -->
<div id="editInsuranceModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full border border-slate-200 shadow-2xl overflow-hidden space-y-4 p-6 sm:p-7 text-right m-auto">
        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">تعديل بيانات وثيقة التأمين</h3>
                <span id="editInsPlate" class="text-xs text-amber-600 font-bold font-mono"></span>
            </div>
            <button type="button" onclick="document.getElementById('editInsuranceModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_insurance">
            <input type="hidden" id="editPolicyId" name="policy_id" value="">
            <input type="hidden" id="editVehicleId" name="vehicle_id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">شركة التأمين المعتمدة *</label>
                    <select id="editInsCompany" name="company" required
                            class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition font-bold">
                        <option value="SAA">SAA - الشركة الوطنية للتأمين</option>
                        <option value="CAAT">CAAT - الشركة الجزائرية للتأمينات</option>
                        <option value="CAAR">CAAR - الصندوق الجزائري للتأمين وإعادة التأمين</option>
                        <option value="CASH">CASH Assurances</option>
                        <option value="Alliance">Alliance Assurances</option>
                        <option value="Macir">Macir Vie / Macir Assurances</option>
                        <option value="Trust">Trust Algeria</option>
                        <option value="CIAR">CIAR Assurances</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">رقم بوليصة التأمين (N° Police) *</label>
                    <input type="text" id="editInsPolicyNumber" name="policy_number" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num font-mono text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition font-bold">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">طبيعة الضمانات والتغطية (Formule)</label>
                <select id="editInsCoverage" name="coverage_type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition font-bold">
                    <option value="Tous Risques + Transport Public Voyageurs">Tous Risques + Transport Public Voyageurs (شامل مع تأمين المسافرين)</option>
                    <option value="Tierce Collision + Transport Public">Tierce Collision + Transport Public</option>
                    <option value="Responsabilité Civile (Obligatoire) + Défense & Recours">Responsabilité Civile (المسؤولية المدنية الإلزامية)</option>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ سريان الوثيقة (Date d'Effet)</label>
                    <input type="date" id="editInsStartDate" name="start_date"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition font-bold font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ نهاية التأمين (Échéance) *</label>
                    <input type="date" id="editInsEndDate" name="end_date" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition font-bold font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">مبلغ قسط التأمين (DZD)</label>
                    <input type="number" step="500" id="editInsPremium" name="premium_amount"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">رقم الوصل / الشهادة المؤقتة</label>
                    <input type="text" id="editInsAttestation" name="attestation_number"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 font-num font-mono text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('editInsuranceModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold shadow-xs transition">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRenewModalFor(vehId, plate, company) {
    const modal = document.getElementById('renewModal');
    const select = document.getElementById('modalVehicleSelect');
    const comp = document.getElementById('modalCompanySelect');
    const polInput = document.getElementById('modalPolicyInput');

    if (select) select.value = vehId;
    if (comp && company) comp.value = company;
    if (polInput) polInput.value = 'POL-' + (company || 'SAA') + '-<?= date('Y') ?>-' + Math.floor(1000 + Math.random() * 9000);

    modal.classList.remove('hidden');
}

function openEditInsuranceModal(data) {
    if (!data) return;
    document.getElementById('editPolicyId').value = data.policy_id || '';
    document.getElementById('editVehicleId').value = data.vehicle_id || '';
    document.getElementById('editInsPlate').textContent = data.plate_number ? 'مركبة: ' + data.plate_number : '';
    
    const company = data.pol_company || data.insurance_company || 'SAA';
    document.getElementById('editInsCompany').value = company;
    document.getElementById('editInsPolicyNumber').value = data.policy_number || ('POL-' + company + '-<?= date('Y') ?>');
    document.getElementById('editInsCoverage').value = data.coverage_type || 'Tous Risques + Transport Public Voyageurs';
    document.getElementById('editInsStartDate').value = data.start_date || '';
    document.getElementById('editInsEndDate').value = data.end_date || data.insurance_expiry || '';
    document.getElementById('editInsPremium').value = data.premium_amount || 35000;
    document.getElementById('editInsAttestation').value = data.attestation_number || '';
    
    document.getElementById('editInsuranceModal').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
