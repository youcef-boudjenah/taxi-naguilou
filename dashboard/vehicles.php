<?php
// dashboard/vehicles.php - Full Fleet & Vehicle Management
$pageTitle = 'سجل المركبات والوثائق';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$drivers = [];
if ($pdo) {
    $drivers = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();
}

// Handle Add / Edit Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $actionType = $_POST['action_type'];
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $color = trim($_POST['color'] ?? 'أصفر');
    $doorNumber = trim($_POST['door_number'] ?? '');
    $driverId = !empty($_POST['current_driver_id']) ? intval($_POST['current_driver_id']) : null;
    $insuranceExpiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $insuranceCompany = trim($_POST['insurance_company'] ?? 'SAA');
    $tcExpiry = !empty($_POST['technical_control_expiry']) ? $_POST['technical_control_expiry'] : null;
    $extExpiry = !empty($_POST['extinguisher_expiry']) ? $_POST['extinguisher_expiry'] : null;
    $gplExpiry = !empty($_POST['gpl_expiry']) ? $_POST['gpl_expiry'] : null;
    $sacExpiry = !empty($_POST['saccuriometre_expiry']) ? $_POST['saccuriometre_expiry'] : null;
    $sacText = trim($_POST['saccuriometre_text'] ?? 'معاير ومختوم');

    if ($actionType === 'create') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO vehicles (owner_id, current_driver_id, brand, model, plate_number, color, door_number, insurance_expiry, insurance_company, technical_control_expiry, extinguisher_expiry, gpl_expiry, saccuriometre_expiry, saccuriometre_text, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $user['id'], $driverId, $brand, $model, $plateNumber, $color, $doorNumber,
                $insuranceExpiry, $insuranceCompany, $tcExpiry, $extExpiry, $gplExpiry, $sacExpiry, $sacText
            ]);
            $newId = $pdo->lastInsertId();
            log_audit('create', 'vehicles', $newId, null, ['plate_number' => $plateNumber, 'brand' => "$brand $model"]);
            set_flash('success', 'تمت إضافة سيارة الأجرة بنجاح إلى الأسطول');
            redirect('/dashboard/vehicles.php');
        } catch (Exception $e) {
            set_flash('error', 'حدث خطأ أثناء الإضافة: رقم اللوحة قد يكون مسجلاً مسبقاً');
        }
    } elseif ($actionType === 'update') {
        $vId = intval($_POST['vehicle_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE vehicles SET brand=?, model=?, plate_number=?, color=?, door_number=?, current_driver_id=?,
                       insurance_expiry=?, insurance_company=?, technical_control_expiry=?, extinguisher_expiry=?,
                       gpl_expiry=?, saccuriometre_expiry=?, saccuriometre_text=?
                WHERE id=?
            ");
            $stmt->execute([
                $brand, $model, $plateNumber, $color, $doorNumber, $driverId,
                $insuranceExpiry, $insuranceCompany, $tcExpiry, $extExpiry, $gplExpiry, $sacExpiry, $sacText, $vId
            ]);
            log_audit('update', 'vehicles', $vId, null, ['plate_number' => $plateNumber]);
            set_flash('success', 'تم تحديث بيانات المركبة والوثائق بنجاح');
            redirect('/dashboard/vehicles.php');
        } catch (Exception $e) {
            set_flash('error', 'تعذر تحديث المركبة');
        }
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    try {
        $pdo->prepare("DELETE FROM vehicles WHERE id=?")->execute([$delId]);
        log_audit('delete', 'vehicles', $delId, null, []);
        set_flash('success', 'تم حذف المركبة بنجاح');
    } catch (Exception $e) {
        set_flash('error', 'تعذر حذف المركبة لوجود عقود أو بيانات مرتبطة بها');
    }
    redirect('/dashboard/vehicles.php');
}

// Stats for Quick KPI Strip
$totalVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
$assignedVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE current_driver_id IS NOT NULL")->fetchColumn();
$expiringDocsCount = (int)$pdo->query("
    SELECT COUNT(*) FROM vehicles 
    WHERE (technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND technical_control_expiry IS NOT NULL)
       OR (insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND insurance_expiry IS NOT NULL)
")->fetchColumn();
$conformityCount = max(0, $totalVehiclesCount - $expiringDocsCount);

// Fetch vehicles with filters
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$query = "SELECT v.*, u.full_name as driver_name, u.phone as driver_phone FROM vehicles v LEFT JOIN users u ON v.current_driver_id = u.id WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (v.plate_number LIKE ? OR v.brand LIKE ? OR v.model LIKE ? OR u.full_name LIKE ? OR v.door_number LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"];
}

if ($filter === 'expiring') {
    $query .= " AND ((v.technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND v.technical_control_expiry IS NOT NULL) OR (v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND v.insurance_expiry IS NOT NULL))";
} elseif ($filter === 'unassigned') {
    $query .= " AND v.current_driver_id IS NULL";
}

$query .= " ORDER BY v.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

// Vehicle to edit if edit param present
$editVehicle = null;
if (isset($_GET['edit'])) {
    $eId = intval($_GET['edit']);
    $stmtE = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmtE->execute([$eId]);
    $editVehicle = $stmtE->fetch();
}
?>

<div class="space-y-6">

    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">سجل المركبات والأسطول</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20 font-num">
                    <?= count($vehicles) ?> مركبة
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">متابعة الفحص التقني الدوري، التأمين الشامل، خزان سيرغاز GPL، والساكوريومتر</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('addVehicleModal').classList.toggle('hidden')" 
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition-all shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>إضافة سيارة جديدة</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards (Clean & Simple) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إجمالي الأسطول</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $totalVehiclesCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75a3 3 0 013 3v3.75m-6-6.75H6.75a2.25 2.25 0 00-2.25 2.25v6.75" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">في الخدمة مع سائق</p>
                <p class="text-xl font-bold text-emerald-600 font-num mt-0.5"><?= $assignedVehiclesCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">مطابقة الوثائق</p>
                <p class="text-xl font-bold text-slate-700 font-num mt-0.5"><?= $conformityCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">تنبيهات الانتهاء (&lt; 15 يوم)</p>
                <p class="text-xl font-bold <?= $expiringDocsCount > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num mt-0.5"><?= $expiringDocsCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $expiringDocsCount > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar (Unified) -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث برقم اللوحة، الماركة، رقم الباب، أو اسم السائق..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="vehicles.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            <a href="vehicles.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                الكل (<?= $totalVehiclesCount ?>)
            </a>
            <a href="vehicles.php?filter=expiring<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'expiring' ? 'bg-rose-500 text-white' : 'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200/60' ?>">
                <span>تنبيهات الوثائق</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= $filter === 'expiring' ? 'bg-white text-rose-600' : 'bg-rose-200 text-rose-800' ?>"><?= $expiringDocsCount ?></span>
            </a>
            <a href="vehicles.php?filter=unassigned<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'unassigned' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                شاغرة بدون سائق
            </a>
        </div>
    </div>

    <!-- Vehicles Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap">المركبة ولوحة الترقيم</th>
                        <th class="p-3.5 whitespace-nowrap">رقم الباب واللون</th>
                        <th class="p-3.5 whitespace-nowrap">السائق المكلف</th>
                        <th class="p-3.5 whitespace-nowrap">المراقبة التقنية</th>
                        <th class="p-3.5 whitespace-nowrap">التأمين الشامل</th>
                        <th class="p-3.5 whitespace-nowrap">فحص سيرغاز GPL</th>
                        <th class="p-3.5 whitespace-nowrap">عداد التاكسي</th>
                        <th class="p-3.5 text-center whitespace-nowrap">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($vehicles)): ?>
                        <?php foreach ($vehicles as $v): 
                            $tcDiff = !empty($v['technical_control_expiry']) ? round((strtotime($v['technical_control_expiry']) - time()) / 86400) : null;
                            $insDiff = !empty($v['insurance_expiry']) ? round((strtotime($v['insurance_expiry']) - time()) / 86400) : null;
                        ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <!-- Vehicle & Plate -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 text-[13px]"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?></div>
                                    <div class="mt-0.5 inline-flex items-center px-2 py-0.5 rounded bg-slate-900 text-amber-400 font-num font-mono text-[11px] tracking-wider" dir="ltr">
                                        <?= htmlspecialchars($v['plate_number']) ?>
                                    </div>
                                </td>

                                <!-- Door & Color -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-semibold text-slate-800 font-num">باب: <?= htmlspecialchars($v['door_number']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($v['color']) ?></div>
                                </td>

                                <!-- Driver -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['driver_name'])): ?>
                                        <div class="font-bold text-slate-800"><?= htmlspecialchars($v['driver_name']) ?></div>
                                        <div class="text-[11px] text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($v['driver_phone']) ?></div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-500">
                                            شاغر (بدون سائق)
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Technical Control -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($tcDiff !== null && $tcDiff <= 15): ?>
                                        <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                            </svg>
                                            <span class="font-num"><?= htmlspecialchars($v['technical_control_expiry']) ?></span>
                                            <span class="text-[10px]">(باقي <?= $tcDiff ?> ي)</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="font-num text-slate-700 font-medium"><?= htmlspecialchars($v['technical_control_expiry'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Insurance -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($insDiff !== null && $insDiff <= 15): ?>
                                        <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            <span class="font-num"><?= htmlspecialchars($v['insurance_expiry']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="font-num text-slate-800 font-medium"><?= htmlspecialchars($v['insurance_expiry'] ?? '—') ?></div>
                                    <?php endif; ?>
                                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($v['insurance_company']) ?></div>
                                </td>

                                <!-- GPL Check -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 font-num">
                                        <?= htmlspecialchars($v['gpl_expiry'] ?? 'مطابق') ?>
                                    </span>
                                </td>

                                <!-- Taximeter (Saccuriometre) -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[11px] font-medium">
                                        <?= htmlspecialchars($v['saccuriometre_text']) ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="vehicles.php?edit=<?= $v['id'] ?>" 
                                           title="تعديل البيانات والوثائق"
                                           class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition text-xs">
                                            تعديل
                                        </a>
                                        <a href="contracts.php?action=new&vehicle_id=<?= $v['id'] ?>" 
                                           title="إصدار عقد عمل 90-11"
                                           class="px-2.5 py-1 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-800 font-bold transition text-xs border border-amber-200/60">
                                            عقد 90-11
                                        </a>
                                        <a href="vehicles.php?delete=<?= $v['id'] ?>" 
                                           onclick="return confirm('هل أنت متأكد من حذف هذه المركبة من الأسطول؟')" 
                                           class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition"
                                           title="حذف المركبة">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-12 text-center text-slate-400">
                                <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-3">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75a3 3 0 013 3v3.75m-6-6.75H6.75a2.25 2.25 0 00-2.25 2.25v6.75" />
                                    </svg>
                                </div>
                                <p class="text-sm font-semibold text-slate-600">لا توجد مركبات مطابقة لمعايير البحث</p>
                                <p class="text-xs text-slate-400 mt-1">جرّب تغيير كلمات البحث أو إعادة ضبط التصفية</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Add / Edit Vehicle -->
<div id="addVehicleModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm <?= $editVehicle ? 'flex' : 'hidden' ?> items-center justify-center p-4">
    <div class="bg-white max-w-2xl w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl max-h-[92vh] overflow-y-auto space-y-5 text-right">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">
                    <?= $editVehicle ? 'تعديل بيانات سيارة الأجرة والوثائق' : 'إضافة سيارة أجرة جديدة إلى الأسطول' ?>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">أدخل بيانات المركبة وتواريخ صلاحية المراقبة التقنية والتأمين والعداد</p>
            </div>
            <a href="vehicles.php" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </a>
        </div>

        <form method="POST" action="/dashboard/vehicles.php" class="space-y-4 text-xs">
            <input type="hidden" name="action_type" value="<?= $editVehicle ? 'update' : 'create' ?>">
            <?php if ($editVehicle): ?>
                <input type="hidden" name="vehicle_id" value="<?= $editVehicle['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        الماركة أو الصانع <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="brand" required placeholder="مثال: رينو، داسيا، بيجو" value="<?= htmlspecialchars($editVehicle['brand'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        طراز المركبة (الموديل) <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="model" required placeholder="مثال: سامبول، لوغان، 301" value="<?= htmlspecialchars($editVehicle['model'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        رقم لوحة الترقيم <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="plate_number" required placeholder="01458-121-16" dir="ltr" value="<?= htmlspecialchars($editVehicle['plate_number'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 text-right font-num font-mono transition">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">
                        رقم الباب المعتمد <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="door_number" required placeholder="مثال: 16-042" value="<?= htmlspecialchars($editVehicle['door_number'] ?? '') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">لون السيارة</label>
                    <input type="text" name="color" value="<?= htmlspecialchars($editVehicle['color'] ?? 'أصفر') ?>"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تعيين السائق الأساسي</label>
                    <select name="current_driver_id" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white transition">
                        <option value="">-- بدون سائق حالياً --</option>
                        <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($editVehicle && $editVehicle['current_driver_id'] == $d['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Documents & Regulatory Expiry Dates -->
            <div class="border-t border-slate-100 pt-4 space-y-3">
                <span class="block font-bold text-slate-800 text-sm">تواريخ الصلاحية والوثائق التنظيمية:</span>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء المراقبة التقنية</label>
                        <input type="date" name="technical_control_expiry" value="<?= htmlspecialchars($editVehicle['technical_control_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء التأمين الشامل</label>
                        <input type="date" name="insurance_expiry" value="<?= htmlspecialchars($editVehicle['insurance_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">شركة التأمين</label>
                        <select name="insurance_company" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white transition">
                            <option value="SAA" <?= ($editVehicle && $editVehicle['insurance_company'] === 'SAA') ? 'selected' : '' ?>>SAA (الوطنية)</option>
                            <option value="CAAT" <?= ($editVehicle && $editVehicle['insurance_company'] === 'CAAT') ? 'selected' : '' ?>>CAAT</option>
                            <option value="CIAR" <?= ($editVehicle && $editVehicle['insurance_company'] === 'CIAR') ? 'selected' : '' ?>>CIAR</option>
                            <option value="CASH" <?= ($editVehicle && $editVehicle['insurance_company'] === 'CASH') ? 'selected' : '' ?>>CASH</option>
                            <option value="Alliance" <?= ($editVehicle && $editVehicle['insurance_company'] === 'Alliance') ? 'selected' : '' ?>>Alliance</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">صلاحية فحص خزان سيرغاز</label>
                        <input type="date" name="gpl_expiry" value="<?= htmlspecialchars($editVehicle['gpl_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">صلاحية قارورة الإطفاء</label>
                        <input type="date" name="extinguisher_expiry" value="<?= htmlspecialchars($editVehicle['extinguisher_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">صلاحية فحص عداد التاكسي (الساكوريومتر)</label>
                        <input type="date" name="saccuriometre_expiry" value="<?= htmlspecialchars($editVehicle['saccuriometre_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">وضعية ختم العداد</label>
                        <input type="text" name="saccuriometre_text" value="<?= htmlspecialchars($editVehicle['saccuriometre_text'] ?? 'معاير ومختوم') ?>"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="vehicles.php" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </a>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    <?= $editVehicle ? 'حفظ التعديلات' : 'إضافة المركبة إلى الأسطول' ?>
                </button>
            </div>
        </form>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
