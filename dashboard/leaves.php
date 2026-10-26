<?php
// dashboard/leaves.php - Driver Leaves & Relief Driver Dispatch
$pageTitle = 'نظام السائق البديل والعطل';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();
if (is_owner() && !is_admin()) {
    $stmtVeh = $pdo->prepare("
        SELECT id, brand, model, plate_number, door_number, current_driver_id 
        FROM vehicles 
        WHERE (owner_id = ? OR owner_id IS NULL) AND deleted_at IS NULL
        ORDER BY CASE WHEN door_number IS NOT NULL AND door_number != '' THEN 0 ELSE 1 END,
                 CAST(SUBSTRING_INDEX(door_number, '-', -1) AS UNSIGNED) ASC,
                 door_number ASC,
                 id ASC
    ");
    $stmtVeh->execute([intval($_SESSION['user_id'])]);
    $vehicles = $stmtVeh->fetchAll() ?: [];
} else {
    $vehicles = $pdo->query("
        SELECT id, brand, model, plate_number, door_number, current_driver_id 
        FROM vehicles 
        WHERE deleted_at IS NULL
        ORDER BY CASE WHEN door_number IS NOT NULL AND door_number != '' THEN 0 ELSE 1 END,
                 CAST(SUBSTRING_INDEX(door_number, '-', -1) AS UNSIGNED) ASC,
                 door_number ASC,
                 id ASC
    ")->fetchAll() ?: [];
}
$drivers = $pdo->query("
    SELECT u.id, u.full_name, u.phone, MAX(v.door_number) as door_number 
    FROM users u 
    LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id)
    WHERE u.role = 'driver' AND u.status = 'active' AND u.deleted_at IS NULL
    GROUP BY u.id
    ORDER BY CASE WHEN MAX(v.door_number) IS NOT NULL AND MAX(v.door_number) != '' THEN 0 ELSE 1 END,
             CAST(SUBSTRING_INDEX(MAX(v.door_number), '-', -1) AS UNSIGNED) ASC,
             MAX(v.door_number) ASC,
             u.id ASC
")->fetchAll() ?: [];

// Handle New Leave & Relief Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_leave') {
    require_csrf_token();
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $reliefDriverId = intval($_POST['replacement_driver_id'] ?? 0);
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d', strtotime('+7 days'));
    $reason = trim($_POST['reason'] ?? 'عطلة سنوية نظامية');

    if ($vehicleId > 0 && $driverId > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO leaves (driver_id, vehicle_id, replacement_driver_id, start_date, end_date, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, 'approved')
            ");
            $stmt->execute([$driverId, $vehicleId, $reliefDriverId > 0 ? $reliefDriverId : null, $startDate, $endDate, $reason]);
            $leaveId = (int)$pdo->lastInsertId();

            // Assign replacement driver to vehicle
            if ($reliefDriverId > 0) {
                $pdo->prepare("UPDATE vehicles SET replacement_driver_id = ? WHERE id = ?")->execute([$reliefDriverId, $vehicleId]);

                // Also generate transmission slip automatically
                $maxSlip = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM transmission_slips")->fetchColumn();
                $slipNum = 'BRD-' . date('Y') . '-' . str_pad($maxSlip + 1, 4, '0', STR_PAD_LEFT);
                $stmtSlip = $pdo->prepare("
                    INSERT INTO transmission_slips (slip_number, vehicle_id, driver_id, route_line, issue_date, valid_until, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmtSlip->execute([
                    $slipNum, $vehicleId, $reliefDriverId,
                    'خط سيارة الأجرة المعتمد (سائق بديل معتمد)', $startDate, $endDate
                ]);
            }

            $pdo->commit();
            log_audit($pdo, 'leave_approved', 'leaves', $leaveId, "تسجيل عطلة للسائق $driverId وتعيين البديل $reliefDriverId على المركبة $vehicleId");

            set_flash('success', 'تم اعتماد العطلة وتعيين السائق البديل واستخراج جدول الإرسال بنجاح');
            redirect('/dashboard/leaves.php');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء تسجيل العطلة: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى تحديد المركبة والسائق');
    }
}

// Handle Edit Leave
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_leave') {
    require_csrf_token();
    $leaveId = intval($_POST['leave_id'] ?? 0);
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $reliefDriverId = intval($_POST['replacement_driver_id'] ?? 0);
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');
    $reason = trim($_POST['reason'] ?? 'عطلة سنوية نظامية');
    $status = in_array($_POST['status'] ?? '', ['approved', 'pending', 'rejected', 'completed']) ? $_POST['status'] : 'approved';

    if ($leaveId > 0 && $vehicleId > 0 && $driverId > 0) {
        try {
            $stmt = $pdo->prepare("
                UPDATE leaves 
                SET vehicle_id = ?, driver_id = ?, replacement_driver_id = ?, start_date = ?, end_date = ?, reason = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $vehicleId, $driverId, $reliefDriverId > 0 ? $reliefDriverId : null,
                $startDate, $endDate, $reason, $status, $leaveId
            ]);

            // Sync replacement driver on vehicle
            if ($reliefDriverId > 0 && $status === 'approved') {
                $pdo->prepare("UPDATE vehicles SET replacement_driver_id = ? WHERE id = ?")->execute([$reliefDriverId, $vehicleId]);
            } else {
                $pdo->prepare("UPDATE vehicles SET replacement_driver_id = NULL WHERE id = ? AND replacement_driver_id = ?")->execute([$vehicleId, $reliefDriverId]);
            }

            log_audit($pdo, 'leave_updated', 'leaves', $leaveId, "تعديل بيانات العطلة رقم $leaveId");
            set_flash('success', "تم تعديل بيانات الإجازة والسائق البديل بنجاح");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء تعديل الإجازة: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى ملء جميع الحقول المطلوبة');
    }
    redirect('/dashboard/leaves.php');
}

// Handle Delete Leave
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_leave') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بإلغاء وحذف العطل.');
        redirect('/dashboard/leaves.php');
    }
    $leaveId = intval($_POST['leave_id'] ?? 0);
    if ($leaveId > 0) {
        try {
            // Check vehicle replacement driver
            $leaveData = $pdo->query("SELECT vehicle_id, replacement_driver_id FROM leaves WHERE id = $leaveId")->fetch();
            if ($leaveData && $leaveData['replacement_driver_id']) {
                $pdo->prepare("UPDATE vehicles SET replacement_driver_id = NULL WHERE id = ? AND replacement_driver_id = ?")->execute([$leaveData['vehicle_id'], $leaveData['replacement_driver_id']]);
            }

            $stmtDel = $pdo->prepare("UPDATE leaves SET deleted_at = NOW() WHERE id = ?");
            $stmtDel->execute([$leaveId]);
            log_audit($pdo, 'leave_deleted', 'leaves', $leaveId, "نقل العطلة رقم $leaveId إلى سلة المهملات");
            set_flash('success', "تم نقل العطلة إلى سلة المهملات والأرشيف بنجاح واستعادة حالة المركبة الأصلية. يمكنك استرجاعها من سلة المهملات.");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء حذف العطلة: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/leaves.php');
}

// Fetch leaves
$whereLeaves = "l.deleted_at IS NULL";
$paramsLeaves = [];
if (is_owner() && !is_admin()) {
    $whereLeaves = "(v.owner_id = ? OR v.owner_id IS NULL)";
    $paramsLeaves = [intval($_SESSION['user_id'])];
}

$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    $whereLeaves .= " AND (u1.full_name LIKE ? OR u2.full_name LIKE ? OR v.plate_number LIKE ? OR v.door_number LIKE ?)";
    $paramsLeaves[] = "%$search%";
    $paramsLeaves[] = "%$search%";
    $paramsLeaves[] = "%$search%";
    $paramsLeaves[] = "%$search%";
}

$stmtL = $pdo->prepare("
    SELECT l.*, v.brand, v.model, v.plate_number, v.door_number,
           u1.full_name as main_driver, u1.phone as main_phone,
           u2.full_name as relief_driver, u2.phone as relief_phone
    FROM leaves l
    JOIN vehicles v ON l.vehicle_id = v.id
    JOIN users u1 ON l.driver_id = u1.id
    LEFT JOIN users u2 ON l.replacement_driver_id = u2.id
    WHERE $whereLeaves
    ORDER BY 
        CASE WHEN v.door_number IS NOT NULL AND v.door_number != '' THEN 0 ELSE 1 END,
        CAST(SUBSTRING_INDEX(v.door_number, '-', -1) AS UNSIGNED) ASC,
        v.door_number ASC,
        l.id DESC
");
$stmtL->execute($paramsLeaves);
$leaves = $stmtL->fetchAll() ?: [];

$totalLeaves = count($leaves);
if (is_owner() && !is_admin()) {
    $stmtArc = $pdo->prepare("SELECT COUNT(*) FROM leaves l JOIN vehicles v ON l.vehicle_id = v.id WHERE l.replacement_driver_id IS NOT NULL AND l.end_date >= CURDATE() AND (v.owner_id = ? OR v.owner_id IS NULL)");
    $stmtArc->execute([intval($_SESSION['user_id'])]);
    $activeReliefCount = (int)$stmtArc->fetchColumn();
} else {
    $activeReliefCount = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE replacement_driver_id IS NOT NULL AND end_date >= CURDATE()")->fetchColumn();
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">نظام السائق البديل والإجازات</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20 font-num">
                    <?= $totalLeaves ?> سجلات
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">تفادي توقف سيارات الأجرة أثناء غياب السائق؛ تعيين بديل نظامي وتوليد وثائق الإرسال فورياً</p>
        </div>
        <div>
            <button onclick="document.getElementById('newLeaveModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>تسجيل عطلة وتعيين بديل</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إجمالي الإجازات المسجلة</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $totalLeaves ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">سائقون بدلاء في الخدمة</p>
                <p class="text-xl font-bold text-emerald-600 font-num mt-0.5"><?= $activeReliefCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.765z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">معدل الاستمرارية الميدانية</p>
                <p class="text-xl font-bold text-blue-600 font-num mt-0.5">100%</p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Search Toolbar -->
    <div class="bg-white dark:bg-slate-900 p-3 sm:p-4 rounded-xl border border-slate-200 dark:border-slate-800 flex items-center justify-between gap-3 text-xs shadow-2xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث باسم السائق الأصلي، السائق البديل، أو رقم لوحة المركبة..."
                       class="w-full pr-9 pl-8 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 text-white font-bold hover:bg-slate-800 transition cursor-pointer">بحث</button>
            <?php if (!empty($search)): ?>
                <a href="leaves.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Leaves Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap">المركبة</th>
                        <th class="p-3.5 whitespace-nowrap">السائق الأصلي</th>
                        <th class="p-3.5 whitespace-nowrap">السائق البديل المعين</th>
                        <th class="p-3.5 whitespace-nowrap">فترة العطلة</th>
                        <th class="p-3.5 whitespace-nowrap">سبب الغياب</th>
                        <th class="p-3.5 whitespace-nowrap text-center">حالة الاستمرارية</th>
                        <th class="p-3.5 text-center whitespace-nowrap">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($leaves)): ?>
                        <?php foreach ($leaves as $l): ?>
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 mb-1">
                                        <span class="table-num-badge bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/80 font-bold" dir="ltr">
                                            🚗 <?= htmlspecialchars($l['plate_number']) ?>
                                        </span>
                                        <span class="table-num-badge table-num-slate text-[10px]">
                                            باب: <?= htmlspecialchars($l['door_number']) ?>
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-500 dark:text-slate-400 font-medium"><?= htmlspecialchars($l['brand'] . ' ' . $l['model']) ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($l['main_driver']) ?></div>
                                    <?php if (!empty($l['main_phone'])): ?>
                                        <span class="table-num-badge table-num-slate mt-0.5 text-[10px]" dir="ltr">
                                            📞 <?= htmlspecialchars($l['main_phone']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($l['relief_driver'])): ?>
                                        <div class="font-bold text-emerald-700 dark:text-emerald-400"><?= htmlspecialchars($l['relief_driver']) ?></div>
                                        <?php if (!empty($l['relief_phone'])): ?>
                                            <span class="table-num-badge table-num-slate mt-0.5 text-[10px]" dir="ltr">
                                                📞 <?= htmlspecialchars($l['relief_phone']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-rose text-[11px]">بدون بديل (متوقفة)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="table-num-badge table-num-emerald text-[11px]" dir="ltr">
                                        <span class="table-num-dot-emerald"></span>
                                        <?= htmlspecialchars($l['start_date']) ?> → <?= htmlspecialchars($l['end_date']) ?>
                                    </span>
                                </td>
                                <td class="p-3.5 text-slate-700 dark:text-slate-200 whitespace-nowrap"><?= htmlspecialchars($l['reason']) ?></td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-emerald">
                                        <svg class="w-3 h-3 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span>خدمة مستمرة</span>
                                    </span>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="relative inline-block text-right">
                                        <button type="button"
                                                onclick="toggleTableDropdown(event, this)"
                                                class="table-dropdown-btn w-9 h-9 rounded-xl bg-slate-100 hover:bg-amber-500/15 hover:text-amber-700 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 shadow-2xs cursor-pointer"
                                                title="خيارات العطلة">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                            </svg>
                                        </button>

                                        <div class="table-dropdown-menu hidden w-60 min-w-[240px] rounded-2xl bg-white/95 dark:bg-[#161d27]/95 backdrop-blur-md border border-slate-200 dark:border-slate-800 shadow-2xl p-1.5 space-y-0.5 text-xs text-right select-none">
                                            <a href="slips.php"
                                               class="table-dropdown-item flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-amber-50 dark:hover:bg-amber-900/30 font-bold transition">
                                                <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                                <span>جدول الإرسال (Bordereau)</span>
                                            </a>

                                            <button type="button"
                                                    onclick='openEditLeaveModal(<?= json_encode([
                                                        "id" => (int)$l["id"],
                                                        "vehicle_id" => (int)$l["vehicle_id"],
                                                        "driver_id" => (int)$l["driver_id"],
                                                        "replacement_driver_id" => (int)($l["replacement_driver_id"] ?? 0),
                                                        "start_date" => $l["start_date"],
                                                        "end_date" => $l["end_date"],
                                                        "reason" => $l["reason"],
                                                        "status" => $l["status"] ?? "approved"
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل فترة الإجازة</span>
                                            </button>

                                            <?php if (is_admin()): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من إلغاء وحذف هذه العطلة؟')" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_leave">
                                                <input type="hidden" name="leave_id" value="<?= $l['id'] ?>">
                                                <button type="submit"
                                                        class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-bold transition text-right cursor-pointer">
                                                    <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    <span>إلغاء وحذف العطلة</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="p-10 text-center text-slate-400">لا توجد عطل أو إجازات مسجلة حالياً.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: New Leave & Relief -->
<div id="newLeaveModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">تسجيل عطلة وتعيين سائق بديل</h3>
                <span class="text-xs text-slate-500">لضمان استمرار عمل سيارة الأجرة دون توقف أو مخالفة مرورية</span>
            </div>
            <button onclick="document.getElementById('newLeaveModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_leave">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">
                    حدد سيارة الأجرة من الأسطول <span class="text-rose-500">*</span>
                </label>
                <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- اختر السيارة --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (لوحة: <?= htmlspecialchars($v['plate_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">
                    السائق الأصلي (المستفيد من الإجازة) <span class="text-rose-500">*</span>
                </label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- اختر السائق الأساسي --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>">
                            <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="p-3.5 bg-amber-50/70 border border-amber-200/60 rounded-xl space-y-2">
                <label class="block font-bold text-amber-950">
                    السائق البديل المؤهل والمعتمد <span class="text-rose-500">*</span>
                </label>
                <select name="replacement_driver_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-amber-300 bg-white text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <option value="">-- حدد السائق البديل لتفادي توقف السيارة --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>">
                            <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="text-[11px] text-amber-800 block">يتم توليد جدول الإرسال الرسمي وتعيين البديل تلقائياً في السجلات</span>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">بداية العطلة</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">نهاية العطلة</label>
                    <input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">سبب العطلة</label>
                <select name="reason" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="عطلة سنوية نظامية مدفوعة الأجر">عطلة سنوية نظامية مدفوعة الأجر</option>
                    <option value="عطلة مرضية / نقاهة">عطلة مرضية / نقاهة</option>
                    <option value="ظرف عائلي طارئ">ظرف عائلي طارئ</option>
                    <option value="راحة دورية">راحة دورية</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newLeaveModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    اعتماد العطلة وتعيين البديل
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Modal: Edit Leave -->
<div id="editLeaveModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-900 text-lg">تعديل بيانات العطلة والسائق البديل</h3>
            <button onclick="closeEditLeaveModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_leave">
            <input type="hidden" name="leave_id" id="editLeaveId">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">المركبة المعنية <span class="text-rose-500">*</span></label>
                <select name="vehicle_id" id="editLeaveVehicle" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold">
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> - <?= htmlspecialchars($v['plate_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">السائق الأصلي (في عطلة) <span class="text-rose-500">*</span></label>
                <select name="driver_id" id="editLeaveDriver" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold">
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">السائق البديل (Remplaçant)</label>
                <select name="replacement_driver_id" id="editLeaveReliefDriver" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold">
                    <option value="0">-- بدون بديل (المركبة في حالة توقف) --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">بداية العطلة <span class="text-rose-500">*</span></label>
                    <input type="date" name="start_date" id="editLeaveStartDate" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">نهاية العطلة <span class="text-rose-500">*</span></label>
                    <input type="date" name="end_date" id="editLeaveEndDate" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">سبب العطلة</label>
                    <input type="text" name="reason" id="editLeaveReason" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-semibold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">الحالة</label>
                    <select name="status" id="editLeaveStatus" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-bold">
                        <option value="approved">معتمدة (Approuvée)</option>
                        <option value="pending">قيد الانتظار (En attente)</option>
                        <option value="completed">منتهية (Terminée)</option>
                        <option value="rejected">مرفوضة (Rejetée)</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditLeaveModal()"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditLeaveModal(data) {
    document.getElementById('editLeaveId').value = data.id || '';
    document.getElementById('editLeaveVehicle').value = data.vehicle_id || '';
    document.getElementById('editLeaveDriver').value = data.driver_id || '';
    document.getElementById('editLeaveReliefDriver').value = data.replacement_driver_id || 0;
    document.getElementById('editLeaveStartDate').value = data.start_date || '';
    document.getElementById('editLeaveEndDate').value = data.end_date || '';
    document.getElementById('editLeaveReason').value = data.reason || '';
    document.getElementById('editLeaveStatus').value = data.status || 'approved';

    const modal = document.getElementById('editLeaveModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditLeaveModal() {
    const modal = document.getElementById('editLeaveModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
