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

// Filters & Search
$search = trim($_GET['search'] ?? '');
$filterCompany = trim($_GET['company'] ?? 'all');
$filterStatus = trim($_GET['status'] ?? 'all'); // all, imminent, active, expired

// Fetch All Vehicles for selector
$allVehicles = [];
if ($pdo) {
    try {
        $stmtAllV = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles ORDER BY plate_number ASC");
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
                GROUP BY vehicle_id
            ) p2 ON p1.id = p2.max_id
        ) p ON v.id = p.vehicle_id
        LEFT JOIN users u ON v.current_driver_id = u.id
        WHERE 1=1
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
            <select onchange="location.href='/dashboard/insurance.php?company=' + this.value + '&status=<?= urlencode($filterStatus) ?>'" 
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
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
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
                                        <div class="text-[10px] text-slate-400 font-num font-mono">وصل: <?= htmlspecialchars($row['attestation_number']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-slate-600 text-[11px]">
                                    <?= htmlspecialchars($coverage) ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap font-num font-semibold text-slate-800" dir="ltr">
                                    <?= htmlspecialchars($expDate) ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-center font-num">
                                    <?php if ($days < 0): ?>
                                        <span class="text-rose-600 font-bold">منتهي منذ <?= abs($days) ?> يوم</span>
                                    <?php elseif ($days <= 15): ?>
                                        <span class="text-amber-700 font-bold bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                                            باقي <?= $days ?> أيام
                                        </span>
                                    <?php else: ?>
                                        <span class="text-emerald-700 font-semibold">باقي <?= $days ?> يوم</span>
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
                                    <button onclick="openRenewModalFor(<?= $row['vehicle_id'] ?>, '<?= htmlspecialchars($row['plate_number']) ?>', '<?= htmlspecialchars($company) ?>')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-2xs">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                        </svg>
                                        <span>تجديد</span>
                                    </button>
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
