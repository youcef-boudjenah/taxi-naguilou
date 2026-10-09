<?php
// dashboard/insurance.php - Gérer Assurance: Gestion, Alertes et Renouvellement des Polices d'Assurance
$pageTitle = 'Gérer Assurance | إدارة وثائق التأمين';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Handle New/Renew Insurance Policy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_insurance') {
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $policyNumber = trim($_POST['policy_number'] ?? '');
    $company = trim($_POST['company'] ?? 'SAA');
    $coverageType = trim($_POST['coverage_type'] ?? 'Tous Risques + Transport Public Voyageurs');
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate = $_POST['end_date'] ?? date('Y-m-d', strtotime('+1 year'));
    $premium = floatval($_POST['premium_amount'] ?? 35000);
    $attestation = trim($_POST['attestation_number'] ?? ('ATT-' . $company . '-' . date('Y') . '-' . rand(100, 999)));

    if ($vehicleId > 0 && !empty($policyNumber) && !empty($endDate)) {
        try {
            // Insert into insurance_policies
            $stmt = $pdo->prepare("
                INSERT INTO insurance_policies (vehicle_id, policy_number, company, coverage_type, start_date, end_date, premium_amount, attestation_number, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$vehicleId, $policyNumber, $company, $coverageType, $startDate, $endDate, $premium, $attestation]);

            // Synchronize with vehicles table
            $stmtVeh = $pdo->prepare("
                UPDATE vehicles 
                SET insurance_expiry = ?, insurance_company = ?
                WHERE id = ?
            ");
            $stmtVeh->execute([$endDate, $company, $vehicleId]);

            set_flash('success', "تم تسجيل وتجديد وثيقة التأمين رقم $policyNumber لدى شركة $company بنجاح");
            header('Location: /dashboard/insurance.php');
            exit;
        } catch (Exception $e) {
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
$allVehicles = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles ORDER BY plate_number ASC")->fetchAll();

// Query Insurance list with vehicles & drivers
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

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Filter in-memory for days_left to ensure consistency
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
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-black text-slate-900">Gérer Assurance</h2>
                <span class="text-xs font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2.5 py-0.5 rounded-full">
                    إدارة وثائق التأمين للأسطول
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">تتبع صلاحية بوالص التأمين، التنبيهات الاستباقية وتجديد عقود شركات التأمين المعتمدة (SAA, CAAT, CIAR, CASH, Alliance)</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('renewModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-2">
                <span>➕</span>
                <span>تجديد / تسجيل بوليصة جديدة</span>
            </button>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        
        <!-- Total Insured -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500">إجمالي الأسطول المؤمن</span>
                <span class="w-8 h-8 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-sm font-bold">🛡️</span>
            </div>
            <div class="text-3xl font-black text-slate-900 font-num"><?= $totalCount ?></div>
            <div class="text-[11px] text-slate-400 font-semibold">مركبات خاضعة للتأمين التجاري</div>
        </div>

        <!-- Imminent Expiry (< 15 days) -->
        <div class="bg-white p-5 rounded-2xl border <?= $imminentCount > 0 ? 'border-amber-300 ring-2 ring-amber-100 bg-amber-50/20' : 'border-slate-200' ?> shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-amber-700">Expiration Imminente (&le; 15j)</span>
                <span class="w-8 h-8 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-sm font-bold">⚠️</span>
            </div>
            <div class="text-3xl font-black text-amber-600 font-num"><?= $imminentCount ?></div>
            <div class="text-[11px] text-amber-600 font-semibold">تتطلب التجديد الفوري لتفادي الحجز</div>
        </div>

        <!-- Active Policies -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-emerald-700">بوالص سارية المفعول</span>
                <span class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm font-bold">✅</span>
            </div>
            <div class="text-3xl font-black text-emerald-600 font-num"><?= $activeCount ?></div>
            <div class="text-[11px] text-emerald-600 font-semibold">صالحة لأكثر من 15 يوماً</div>
        </div>

        <!-- Expired Policies -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-rose-700">بوالص منتهية الصلاحية</span>
                <span class="w-8 h-8 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center text-sm font-bold">⛔</span>
            </div>
            <div class="text-3xl font-black text-rose-600 font-num"><?= $expiredCount ?></div>
            <div class="text-[11px] text-rose-600 font-semibold">توقف فوري عن النشاط الميداني</div>
        </div>

    </div>

    <!-- Imminent Expiration Warning Alert Banner -->
    <?php if ($imminentCount > 0): ?>
        <div class="bg-gradient-to-l from-amber-500 to-amber-600 text-slate-950 p-4 rounded-2xl shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="text-2xl">🚨</span>
                <div>
                    <h3 class="font-black text-sm">تنبيه حرج: توجد <?= $imminentCount ?> مركبات تنتهي صلاحية تأمينها خلال أقل من 15 يوماً!</h3>
                    <p class="text-xs font-semibold text-amber-950 mt-0.5">تفرض المادة 12 من قانون النقل البري التوقيف الفوري لأي سيارة أجرة لا تحمل شهادة تأمين سارية المفعول.</p>
                </div>
            </div>
            <button onclick="document.getElementById('renewModal').classList.remove('hidden')" 
                    class="px-4 py-2 rounded-xl bg-slate-950 hover:bg-slate-900 text-white font-bold text-xs whitespace-nowrap shadow transition">
                تجديد البوالص الآن
            </button>
        </div>
    <?php endif; ?>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-xs">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="بحث برقم اللوحة، الباب، أو البوليصة..."
                       class="w-full px-3.5 py-2 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 text-white font-bold">بحث</button>
            <?php if (!empty($search) || $filterCompany !== 'all' || $filterStatus !== 'all'): ?>
                <a href="/dashboard/insurance.php" class="text-slate-400 hover:text-slate-600 underline">إعادة ضبط</a>
            <?php endif; ?>
        </form>

        <!-- Company & Status Filters -->
        <div class="flex items-center gap-2 w-full sm:w-auto">
            
            <!-- Company dropdown -->
            <select onchange="location.href='/dashboard/insurance.php?company=' + this.value + '&status=<?= urlencode($filterStatus) ?>'" 
                    class="px-3 py-1.5 rounded-lg border border-slate-300 font-bold text-xs bg-white text-slate-700">
                <option value="all" <?= $filterCompany === 'all' ? 'selected' : '' ?>>كل الشركات (Toutes compagnies)</option>
                <option value="SAA" <?= $filterCompany === 'SAA' ? 'selected' : '' ?>>SAA (الشركة الوطنية)</option>
                <option value="CAAT" <?= $filterCompany === 'CAAT' ? 'selected' : '' ?>>CAAT</option>
                <option value="CIAR" <?= $filterCompany === 'CIAR' ? 'selected' : '' ?>>CIAR</option>
                <option value="CASH" <?= $filterCompany === 'CASH' ? 'selected' : '' ?>>CASH Assurances</option>
                <option value="Alliance" <?= $filterCompany === 'Alliance' ? 'selected' : '' ?>>Alliance Assurances</option>
            </select>

            <!-- Status Tabs -->
            <div class="flex items-center gap-1">
                <a href="/dashboard/insurance.php?company=<?= urlencode($filterCompany) ?>&status=all" 
                   class="px-3 py-1.5 rounded-lg font-bold transition <?= $filterStatus === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                    الكل
                </a>
                <a href="/dashboard/insurance.php?company=<?= urlencode($filterCompany) ?>&status=imminent" 
                   class="px-3 py-1.5 rounded-lg font-bold transition <?= $filterStatus === 'imminent' ? 'bg-amber-500 text-slate-950 font-black' : 'bg-slate-100 text-amber-700 hover:bg-amber-50' ?>">
                    وشيكة (&le; 15j)
                </a>
                <a href="/dashboard/insurance.php?company=<?= urlencode($filterCompany) ?>&status=active" 
                   class="px-3 py-1.5 rounded-lg font-bold transition <?= $filterStatus === 'active' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-emerald-700 hover:bg-emerald-50' ?>">
                    سارية
                </a>
            </div>

        </div>

    </div>

    <!-- Insurance Policies Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-extrabold border-b border-slate-100">
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
                            <tr class="hover:bg-slate-50/60 transition <?= $days <= 15 && $days >= 0 ? 'bg-amber-50/20' : ($days < 0 ? 'bg-rose-50/20' : '') ?>">
                                
                                <!-- Vehicle -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-extrabold text-slate-900"><?= htmlspecialchars($row['brand'] . ' ' . $row['model']) ?></div>
                                    <div class="text-[11px] font-num font-bold text-amber-700">
                                        <?= htmlspecialchars($row['plate_number']) ?> 
                                        <span class="text-slate-400 font-normal">(باب: <?= htmlspecialchars($row['door_number']) ?>)</span>
                                    </div>
                                </td>

                                <!-- Driver -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($row['driver_name'])): ?>
                                        <div class="font-bold text-slate-800"><?= htmlspecialchars($row['driver_name']) ?></div>
                                        <div class="text-[11px] text-slate-400 font-num"><?= htmlspecialchars($row['driver_phone']) ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic">غير معين</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Company -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-black
                                        <?= $company === 'SAA' ? 'bg-blue-50 text-blue-700 border border-blue-200' :
                                           ($company === 'CAAT' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' :
                                           ($company === 'CIAR' ? 'bg-purple-50 text-purple-700 border border-purple-200' :
                                           'bg-slate-100 text-slate-800 border border-slate-200')) ?>">
                                        🏢 <?= htmlspecialchars($company) ?>
                                    </span>
                                </td>

                                <!-- Policy Number -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num font-bold text-slate-900"><?= htmlspecialchars($policy) ?></div>
                                    <?php if (!empty($row['attestation_number'])): ?>
                                        <div class="text-[10px] text-slate-400 font-num">وصل: <?= htmlspecialchars($row['attestation_number']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Coverage Type -->
                                <td class="p-3.5 whitespace-nowrap text-slate-600">
                                    <?= htmlspecialchars($coverage) ?>
                                </td>

                                <!-- Expiry Date -->
                                <td class="p-3.5 whitespace-nowrap font-num font-bold text-slate-800">
                                    <?= htmlspecialchars($expDate) ?>
                                </td>

                                <!-- Days Left -->
                                <td class="p-3.5 whitespace-nowrap text-center font-num">
                                    <?php if ($days < 0): ?>
                                        <span class="text-rose-600 font-bold">منتهي منذ <?= abs($days) ?> يوم</span>
                                    <?php elseif ($days <= 15): ?>
                                        <span class="text-amber-600 font-bold bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                                            باقي <?= $days ?> أيام ⚠️
                                        </span>
                                    <?php else: ?>
                                        <span class="text-emerald-700 font-bold">باقي <?= $days ?> يوم</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status Badge -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($days < 0): ?>
                                        <span class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 font-bold text-[10px] border border-rose-200">
                                            منتهي (Expiré)
                                        </span>
                                    <?php elseif ($days <= 15): ?>
                                        <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 font-black text-[10px] border border-amber-200">
                                            وشيك الانتهاء (&le; 15j)
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold text-[10px] border border-emerald-200">
                                            ساري المفعول (Valide)
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Action -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <button onclick="openRenewModalFor(<?= $row['vehicle_id'] ?>, '<?= htmlspecialchars($row['plate_number']) ?>', '<?= htmlspecialchars($company) ?>')"
                                            class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-[11px] transition shadow-sm">
                                        تجديد البوليصة ↻
                                    </button>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="p-8 text-center text-slate-400">
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
    <div class="bg-white rounded-2xl max-w-lg w-full border border-slate-200 shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-150">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div class="flex items-center gap-2">
                <span class="text-xl">🛡️</span>
                <h3 class="text-base font-black text-slate-900">تجديد أو تسجيل بوليصة تأمين (Gérer Assurance)</h3>
            </div>
            <button onclick="document.getElementById('renewModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xl font-bold">✕</button>
        </div>

        <form method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="action" value="save_insurance">

            <!-- Vehicle selection -->
            <div>
                <label class="block font-bold text-slate-700 mb-1">المركبة المعنية *</label>
                <select name="vehicle_id" id="modalVehicleSelect" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white font-bold text-xs focus:ring-2 focus:ring-amber-500">
                    <?php foreach ($allVehicles as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (لوحة: <?= htmlspecialchars($v['plate_number']) ?> - باب: <?= htmlspecialchars($v['door_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Insurance Company -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">شركة التأمين *</label>
                    <select name="company" id="modalCompanySelect" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white font-bold text-xs focus:ring-2 focus:ring-amber-500">
                        <option value="SAA">SAA (الشركة الوطنية للتأمين)</option>
                        <option value="CAAT">CAAT (الصندوق الجزائري للتأمين)</option>
                        <option value="CIAR">CIAR (التأمين الدولي الجزائري)</option>
                        <option value="CASH">CASH Assurances</option>
                        <option value="Alliance">Alliance Assurances</option>
                        <option value="Macir Vie">Macir Vie</option>
                        <option value="GAM">GAM Assurances</option>
                        <option value="AXA">AXA Algérie</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم بوليصة التأمين (N° Police) *</label>
                    <input type="text" name="policy_number" id="modalPolicyInput" required placeholder="مثال: POL-2026-9901"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-num font-bold text-xs focus:ring-2 focus:ring-amber-500">
                </div>
            </div>

            <!-- Coverage Type -->
            <div>
                <label class="block font-bold text-slate-700 mb-1">نوع التغطية التأمينية</label>
                <select name="coverage_type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white font-bold text-xs focus:ring-2 focus:ring-amber-500">
                    <option value="Tous Risques + Transport Public Voyageurs">Tous Risques + Transport Public Voyageurs (شامل + ركاب)</option>
                    <option value="Responsabilité Civile + Défense & Recours">Responsabilité Civile + Défense & Recours (المسؤولية المدنية)</option>
                    <option value="Tous Risques Flotte Taxi">Tous Risques Flotte Taxi (تأمين أسطول سيارات الأجرة)</option>
                </select>
            </div>

            <!-- Start and End Dates -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تاريخ بداية العقد</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-num text-xs focus:ring-2 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تاريخ انتهاء الصلاحية *</label>
                    <input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-num font-bold text-xs focus:ring-2 focus:ring-amber-500">
                </div>
            </div>

            <!-- Premium & Attestation Ref -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">مبلغ قسط التأمين (DZD)</label>
                    <input type="number" step="500" name="premium_amount" value="38000"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-num text-xs focus:ring-2 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم الوصل / الشهادة المؤقتة</label>
                    <input type="text" name="attestation_number" placeholder="ATT-16-..."
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 font-num text-xs focus:ring-2 focus:ring-amber-500">
                </div>
            </div>

            <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('renewModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl border border-slate-300 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black shadow-sm transition">
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
