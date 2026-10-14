<?php
// dashboard/contracts.php - Gestion des Contrats: Gérez et générez les contrats d'affiliation et de travail
$pageTitle = 'Gestion des Contrats | إدارة عقود العمل والانتساب';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

$vehicles = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles ORDER BY plate_number ASC")->fetchAll();
$drivers = $pdo->query("SELECT id, full_name, phone, license_number FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// Handle Create Contract
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_contract') {
    require_csrf_token();
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $years = max(1, intval($_POST['duration_years'] ?? 1));
    $endDate = date('Y-m-d', strtotime("+$years years", strtotime($startDate)));
    $monthlyWage = floatval($_POST['monthly_wage'] ?? 45000.00);
    $notes = trim($_POST['notes'] ?? 'Contrat de travail pour chauffeur de taxi régi par la loi 90-11.');

    if ($vehicleId > 0 && $driverId > 0) {
        try {
            $pdo->beginTransaction();
            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM contracts")->fetchColumn();
            $contractNum = 'CT-' . date('Y') . '-' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO contracts (contract_number, driver_id, vehicle_id, owner_id, start_date, end_date, monthly_wage, status, signed_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'signed', NOW(), ?)
            ");
            $stmt->execute([$contractNum, $driverId, $vehicleId, $user['id'], $startDate, $endDate, $monthlyWage, $notes]);
            $cId = (int)$pdo->lastInsertId();
            
            // Assign driver to vehicle
            $pdo->prepare("UPDATE vehicles SET current_driver_id = ? WHERE id = ?")->execute([$driverId, $vehicleId]);

            $pdo->commit();
            log_audit($pdo, 'contract_created', 'contracts', $cId, "إنشاء وتوثيق عقد عمل نظامي رقم $contractNum للسائق رقم $driverId");
            set_flash('success', "تم إنشاء وتوثيق العقد رقم $contractNum بنجاح وفقاً لأحكام القانون 90-11");
            redirect("/dashboard/contract_view.php?id=$cId");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء إنشاء العقد: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى تحديد المركبة والسائق');
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, active, expired

$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(c.contract_number LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR v.plate_number LIKE ? OR v.door_number LIKE ? OR v.brand LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'active') {
    $where[] = "c.end_date >= CURDATE()";
} elseif ($filter === 'expired') {
    $where[] = "c.end_date < CURDATE()";
}

$sql = "
    SELECT c.*, 
           v.plate_number, v.brand, v.model, v.door_number,
           u.full_name as driver_name, u.phone as driver_phone, u.license_number as driver_license,
           TIMESTAMPDIFF(YEAR, c.start_date, c.end_date) as duration_years,
           DATEDIFF(c.end_date, CURDATE()) as days_remaining
    FROM contracts c
    JOIN vehicles v ON c.vehicle_id = v.id
    JOIN users u ON c.driver_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contracts = $stmt->fetchAll();

// Display metrics
$countTous = (int)$pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn();
if ($countTous === 0) $countTous = count($contracts);
$countActifs = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE end_date >= CURDATE()")->fetchColumn();
$countExpires = max(0, $countTous - $countActifs);
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Gestion des Contrats</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    عقود العمل والانتساب (القانون 90-11)
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">توليد وإدارة عقود العمل والانتساب المتوافقة مع متطلبات مفتشية العمل ومديرية النقل</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="window.print()" 
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.03-.42-2.1-.523-3.2A11.75 11.75 0 0112 3c3.08 0 5.86 1.173 7.803 3.09.28.277.53.57.753.878m-1.556 7.861A11.724 11.724 0 0112 21c-3.08 0-5.86-1.173-7.803-3.09-.28-.277-.53-.57-.753-.878" />
                </svg>
                <span>طباعة السجل</span>
            </button>
            <button onclick="document.getElementById('newContractModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>إصدار عقد جديد</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إجمالي العقود المسجلة</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $countTous ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">عقود سارية المفعول</p>
                <p class="text-xl font-bold text-emerald-600 font-num mt-0.5"><?= $countActifs ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">عقود منتهية الصلاحية</p>
                <p class="text-xl font-bold <?= $countExpires > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num mt-0.5"><?= $countExpires ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $countExpires > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث برقم العقد، اسم السائق، أو رقم اللوحة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="contracts.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            <a href="contracts.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                الكل (<?= $countTous ?>)
            </a>
            <a href="contracts.php?filter=active<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'active' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                سارية المفعول
            </a>
            <a href="contracts.php?filter=expired<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'expired' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                منتهية
            </a>
        </div>
    </div>

    <!-- Contracts Table -->
    <div id="contractsTableContainer" class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        
        <!-- Printable Registry Header -->
        <div class="hidden print:block p-6 border-b border-slate-300 text-center space-y-1">
            <div class="text-sm font-bold">الجمهورية الجزائرية الديمقراطية الشعبية</div>
            <div class="text-xs font-semibold text-slate-600">RÉPUBLIQUE ALGÉRIENNE DÉMOCRATIQUE ET POPULAIRE</div>
            <div class="text-base font-bold text-slate-900 pt-2">REGISTRE OFFICIEL DES CONTRATS D'AFFILIATION ET DE TRAVAIL (LOI 90-11)</div>
            <div class="text-xs text-slate-500 font-num">SARL NAGUILOU TAXI • Date d'édition: <?= date('d/m/Y') ?></div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap text-center w-12">N°</th>
                        <th class="p-3.5 whitespace-nowrap">رقم العقد</th>
                        <th class="p-3.5 whitespace-nowrap">السائق المكلف</th>
                        <th class="p-3.5 whitespace-nowrap">المركبة ولوحة الترقيم</th>
                        <th class="p-3.5 whitespace-nowrap">فترة الصلاحية</th>
                        <th class="p-3.5 whitespace-nowrap text-center">الوضعية القانونية</th>
                        <th class="p-3.5 text-center whitespace-nowrap print:hidden">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($contracts)): ?>
                        <?php $rowNum = 1; foreach ($contracts as $c): 
                            $durYears = !empty($c['duration_years']) && $c['duration_years'] > 0 ? $c['duration_years'] : 1;
                            $daysRemaining = $c['days_remaining'] !== null ? intval($c['days_remaining']) : 365;
                        ?>
                            <tr class="hover:bg-slate-50/60 transition">
                                <!-- N° -->
                                <td class="p-3.5 text-center font-num font-bold text-slate-400">
                                    <?= $rowNum++ ?>
                                </td>

                                <!-- Contract Number -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num font-bold text-slate-900 text-sm font-mono tracking-wide">
                                        <span class="inline-block" dir="ltr"><?= htmlspecialchars($c['contract_number']) ?></span>
                                    </div>
                                    <span class="text-[10px] text-slate-400">Loi 90-11 • Conforme DTW</span>
                                </td>

                                <!-- Driver -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 text-[13px]"><?= htmlspecialchars($c['driver_name']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-num" dir="ltr">
                                        <?= htmlspecialchars($c['driver_phone']) ?>
                                    </div>
                                </td>

                                <!-- Vehicle -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-semibold text-slate-900">
                                        <?= htmlspecialchars($c['brand'] . ' ' . $c['model']) ?>
                                    </div>
                                    <div class="font-num font-semibold text-slate-700 text-[11px]" dir="ltr">
                                        <?= htmlspecialchars($c['plate_number']) ?>
                                        <span class="text-slate-400 font-normal">(باب: <?= htmlspecialchars($c['door_number']) ?>)</span>
                                    </div>
                                </td>

                                <!-- Duration / Validity -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num text-slate-800 font-semibold" dir="ltr">
                                        <?= htmlspecialchars($c['start_date']) ?> → <?= htmlspecialchars($c['end_date']) ?>
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="px-2 py-0.2 rounded bg-slate-100 text-slate-700 font-semibold text-[10px]">
                                            <?= $durYears ?> <?= $durYears > 1 ? 'سنوات' : 'سنة' ?>
                                        </span>
                                        <?php if ($daysRemaining > 0): ?>
                                            <span class="text-[10px] text-emerald-600 font-bold font-num">باقي <?= $daysRemaining ?> يوم</span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-rose-600 font-bold font-num">منتهي الصلاحية</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($daysRemaining > 0): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <span>ساري ونظامي (90-11)</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200/60">
                                            <span>منتهي الصلاحية</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="p-3.5 whitespace-nowrap text-center print:hidden">
                                    <a href="contract_view.php?id=<?= $c['id'] ?>" 
                                       class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-900 font-bold text-xs transition border border-amber-200/60 shadow-2xs">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <span>معاينة وطباعة</span>
                                    </a>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-10 text-center text-slate-400">
                                لا توجد عقود مطابقة لشروط البحث والتصفية.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: New Contract -->
<div id="newContractModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">إصدار عقد عمل وانتساب نظامي</h3>
                <span class="text-xs text-slate-500">يتضمن المواد الـ 16 الإلزامية طبقاً لأحكام القانون 90-11 ومفتشية العمل</span>
            </div>
            <button onclick="document.getElementById('newContractModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_contract">

            <!-- Vehicle -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">
                    اختر سيارة الأجرة من الأسطول (Véhicule) <span class="text-rose-500">*</span>
                </label>
                <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- حدد السيارة من الأسطول --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (لوحة: <?= htmlspecialchars($v['plate_number']) ?> - باب: <?= htmlspecialchars($v['door_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Driver -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">
                    اختر السائق الأجير (Chauffeur) <span class="text-rose-500">*</span>
                </label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- حدد السائق المكلف --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>">
                            <?= htmlspecialchars($d['full_name']) ?> (هاتف: <?= htmlspecialchars($d['phone']) ?> - رخصة: <?= htmlspecialchars($d['license_number'] ?? 'غير مسجل') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dates & Duration -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ سريان العقد (Début)</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">مدة العقد (Durée)</label>
                    <select name="duration_years" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                        <option value="1" selected>سنة واحدة (1 an)</option>
                        <option value="2">سنتان (2 ans)</option>
                        <option value="3">3 سنوات (3 ans)</option>
                        <option value="5">5 سنوات (5 ans)</option>
                    </select>
                </div>
            </div>

            <!-- Wage / Redevance -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">الأجر المرجعي الشهري أو نسبة الإيراد (DZD)</label>
                <input type="number" name="monthly_wage" value="45000" min="20000" step="1000" required
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num font-bold transition">
                <span class="text-[11px] text-slate-500 mt-1 block">يضمن الحد الأدنى للأجر المضمون SNMG وتغطية الضمان الاجتماعي CNAS</span>
            </div>

            <!-- Notes -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات وشروط تكميلية</label>
                <textarea name="notes" rows="2" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">التزام السائق بإجراء الصيانة الدورية، معايرة العداد (الساكوريومتر)، واحترام جدول المناوبات والمداومة الرسمية للوكالة.</textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newContractModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    تأكيد وتوقيع العقد
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
