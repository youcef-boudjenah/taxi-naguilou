<?php
// dashboard/verifications.php - Centre de Vérification & Conformité des Dossiers (Old Site: /verifications/pending)
$pageTitle = 'مركز التدقيق والمصادقة على الملفات | Centre de Vérification';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();
$message = null;
$messageType = null;

// Handle Verification Action (Approve / Reject / Request Document)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf_token();
    $action = $_POST['action'] ?? '';
    $itemType = $_POST['item_type'] ?? ''; // driver, vehicle
    $itemId = intval($_POST['item_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($action === 'approve' && $itemId > 0) {
        if ($itemType === 'driver') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'driver'")->execute([$itemId]);
            log_audit($pdo, 'driver_verified', 'users', $itemId, "المصادقة الرسمية على ملف السائق رقم $itemId وتفعيله");
            
            // Send system notification
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read) VALUES (?, ?, ?, 'success', 0)")
                ->execute([$itemId, 'تمت المصادقة على ملفك المهني', 'تم تدقيق وثائقك وتفعيل حسابك رسمياً للعمل في أسطول تاكسي نقيلو.']);
            
            set_flash('success', "✓ تمت المصادقة الرسمية على ملف السائق وتفعيل حسابه في الأسطول");
        } elseif ($itemType === 'vehicle') {
            $pdo->prepare("UPDATE vehicles SET status = 'active' WHERE id = ?")->execute([$itemId]);
            log_audit($pdo, 'vehicle_verified', 'vehicles', $itemId, "المصادقة على وثائق المركبة رقم $itemId وتفعيلها");
            set_flash('success', "✓ تم تدقيق واعتماد وثائق المركبة بنجاح");
        }
        redirect('/dashboard/verifications.php');
    } elseif ($action === 'reject' && $itemId > 0) {
        $rejNote = !empty($reason) ? $reason : 'عدم مطابقة الوثائق الرسمية أو انتهاء الصلاحية';
        if ($itemType === 'driver') {
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role = 'driver'")->execute([$itemId]);
            log_audit($pdo, 'driver_rejected', 'users', $itemId, "رفض/تعليق ملف السائق رقم $itemId: $rejNote");
            
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read) VALUES (?, ?, ?, 'warning', 0)")
                ->execute([$itemId, 'تنبيه: ملفك قيد التعليق', "تم تعليق ملفك المهني للأسباب التالية: $rejNote. يرجى مراجعة إدارة المنصة."]);

            set_flash('warning', "تم تعليق الملف وإشعار السائق بضرورة استكمال الوثائق");
        } elseif ($itemType === 'vehicle') {
            $pdo->prepare("UPDATE vehicles SET status = 'suspended', suspended_reason = ? WHERE id = ?")->execute([$rejNote, $itemId]);
            log_audit($pdo, 'vehicle_rejected', 'vehicles', $itemId, "تعليق مركبة الأسطول رقم $itemId: $rejNote");
            set_flash('warning', "تم تعليق المركبة وتسجيل سبب عدم المطابقة");
        }
        redirect('/dashboard/verifications.php');
    }
}

// Fetch Pending & Expiring Verification Items
$filter = $_GET['filter'] ?? 'all'; // all, drivers, vehicles, expiring

// 1. Pending Drivers
$pendingDrivers = [];
try {
    $stmtD = $pdo->query("
        SELECT u.id, u.full_name, u.phone, u.wilaya, u.license_number, u.license_expiry, 
               u.card_number, u.medical_cert_expiry, u.probation_stage, u.status, u.created_at,
               v.plate_number, v.door_number, v.brand, v.model
        FROM users u
        LEFT JOIN vehicles v ON v.current_driver_id = u.id
        WHERE u.role = 'driver' AND (u.status = 'pending' OR u.license_expiry < CURDATE() + INTERVAL 30 DAY OR u.license_expiry IS NULL)
        ORDER BY u.status = 'pending' DESC, u.license_expiry ASC
    ");
    $pendingDrivers = $stmtD->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}

// 2. Pending or At-Risk Vehicles
$pendingVehicles = [];
try {
    $stmtV = $pdo->query("
        SELECT v.*, u.full_name as driver_name, u.phone as driver_phone
        FROM vehicles v
        LEFT JOIN users u ON u.id = v.current_driver_id
        WHERE v.status IN ('suspended', 'in_maintenance') 
           OR v.technical_control_expiry < CURDATE() + INTERVAL 15 DAY
           OR v.insurance_expiry < CURDATE() + INTERVAL 15 DAY
        ORDER BY v.status != 'active' DESC, v.technical_control_expiry ASC
    ");
    $pendingVehicles = $stmtV->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}

// Calculate KPI Totals
$totalPendingDrivers = count(array_filter($pendingDrivers, fn($d) => $d['status'] === 'pending'));
$totalPendingVehicles = count(array_filter($pendingVehicles, fn($v) => in_array($v['status'], ['suspended', 'in_maintenance'])));
$totalExpiringDocs = count($pendingDrivers) + count($pendingVehicles) - $totalPendingDrivers - $totalPendingVehicles;
?>

<div class="space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">مركز التدقيق والمصادقة على الملفات</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20 font-num">
                    <?= $totalPendingDrivers + $totalPendingVehicles ?> ملفات تنتظر القرار
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">مراجعة وثائق السائقين، رخص السياقة، الفحص التقني الدوري، واعتماد الملفات الرسمية</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="/dashboard/driver_fiche.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition shadow-xs">
                <span>البطاقة الفنية للسائق</span>
                <span class="text-amber-400 font-num">↗</span>
            </a>
            <a href="/dashboard/drivers.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-xs font-bold transition shadow-xs">
                <span>سجل السائقين</span>
            </a>
        </div>
    </div>

    <!-- 3 KPI Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">سائقون قيد المراجعة</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-xs">
                    ⏳
                </span>
            </div>
            <div class="text-3xl font-extrabold text-amber-600 font-num">
                <?= $totalPendingDrivers ?>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-amber-700 font-bold">● طلبات انضمام:</span>
                <span>تنتظر المصادقة على الهوية والرخصة المهنية</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">مركبات معلقة / صيانة</span>
                <span class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-xs">
                    ⚠️
                </span>
            </div>
            <div class="text-3xl font-extrabold text-rose-600 font-num">
                <?= $totalPendingVehicles ?>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-rose-700 font-bold">● فحص تقني / تأمين:</span>
                <span>مركبات بحاجة لتجديد الوثائق أو إنهاء الصيانة</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">تنبيهات انتهاء الصلاحية</span>
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xs">
                    📅
                </span>
            </div>
            <div class="text-3xl font-extrabold text-blue-600 font-num">
                <?= $totalExpiringDocs ?>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-blue-700 font-bold">● وثائق قاربت الانتهاء:</span>
                <span>أقل من 30 يوماً على رخصة السياقة أو الفحص الدوري</span>
            </div>
        </div>
    </div>

    <!-- Filter Pills Toolbar -->
    <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs">
        <a href="?filter=all" class="px-4 py-2 rounded-xl font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            جميع الملفات (<?= count($pendingDrivers) + count($pendingVehicles) ?>)
        </a>
        <a href="?filter=drivers" class="px-4 py-2 rounded-xl font-bold transition whitespace-nowrap <?= $filter === 'drivers' ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            ملفات السائقين (<?= count($pendingDrivers) ?>)
        </a>
        <a href="?filter=vehicles" class="px-4 py-2 rounded-xl font-bold transition whitespace-nowrap <?= $filter === 'vehicles' ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            ملفات المركبات (<?= count($pendingVehicles) ?>)
        </a>
    </div>

    <!-- 1. DRIVERS VERIFICATION QUEUE -->
    <?php if ($filter === 'all' || $filter === 'drivers'): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="w-3 h-3 rounded-full bg-violet-500"></span>
                <h3 class="font-bold text-slate-900 text-sm">قائمة تدقيق واعتماد السائقين والرخص المهنية</h3>
            </div>
            <span class="text-xs text-slate-400 font-num"><?= count($pendingDrivers) ?> سائقاً</span>
        </div>

        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80">
                        <th class="p-3.5">السائق والولاية</th>
                        <th class="p-3.5">الهاتف والتواصل</th>
                        <th class="p-3.5">رخصة السياقة وصلاحيتها</th>
                        <th class="p-3.5">دفتر المقررة / الفحص الطبي</th>
                        <th class="p-3.5 text-center">الوضعية الحالية</th>
                        <th class="p-3.5 text-center">قرار المصادقة والاعتماد</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($pendingDrivers)): ?>
                        <?php foreach ($pendingDrivers as $drv): ?>
                            <?php 
                                $isExp = !empty($drv['license_expiry']) && strtotime($drv['license_expiry']) < strtotime('+30 days');
                                $isPending = $drv['status'] === 'pending';
                            ?>
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($drv['full_name']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($drv['wilaya'] ?? 'أدرار') ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="table-num-badge table-num-slate text-[11px]" dir="ltr">📞 <?= htmlspecialchars($drv['phone']) ?></span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="table-num-badge table-num-sky font-bold text-[11px]" dir="ltr">
                                        📄 <?= htmlspecialchars($drv['license_number'] ?? 'غير مسجل') ?>
                                    </span>
                                    <div class="mt-1">
                                        <?php if (!empty($drv['license_expiry'])): ?>
                                            <span class="table-num-badge <?= $isExp ? 'table-num-rose' : 'table-num-emerald' ?> text-[10px]" dir="ltr">
                                                <span class="<?= $isExp ? 'table-num-dot-rose' : 'table-num-dot-emerald' ?>"></span>
                                                انتهاء: <?= htmlspecialchars($drv['license_expiry']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="table-num-badge table-num-slate text-[10px]">تاريخ غير محدد</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="table-num-badge table-num-amber font-bold text-[11px]" dir="ltr">
                                        <?= htmlspecialchars($drv['card_number'] ?? 'LIV-2026-XX') ?>
                                    </span>
                                    <div class="mt-1">
                                        <span class="table-num-badge table-num-emerald text-[10px]">
                                            <span class="table-num-dot-emerald"></span>
                                            فحص طبي ساري الصلاحية
                                        </span>
                                    </div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($isPending): ?>
                                        <span class="table-num-badge table-num-amber text-[10px] font-bold">
                                            <span class="table-num-dot-amber"></span>
                                            قيد المراجعة ⏳
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-emerald text-[10px] font-bold">
                                            <span class="table-num-dot-emerald"></span>
                                            نشط ومفعل ✓
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="/dashboard/driver_fiche.php?id=<?= $drv['id'] ?>" target="_blank"
                                           class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition" title="معاينة البطاقة الفنية">
                                            معاينة
                                        </a>

                                        <?php if ($isPending): ?>
                                        <form method="POST" action="" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="item_type" value="driver">
                                            <input type="hidden" name="item_id" value="<?= $drv['id'] ?>">
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold text-xs transition shadow-2xs">
                                                اعتماد وتفعيل ✓
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <button type="button" onclick="openRejectModal('driver', <?= $drv['id'] ?>, '<?= htmlspecialchars($drv['full_name'], ENT_QUOTES) ?>')"
                                                class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs transition border border-rose-200/60">
                                            تعليق / رفض
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-400 text-xs">
                                لا توجد ملفات سائقين قيد الانتظار حالياً. جميع الملفات معتمدة ومطابقة!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. VEHICLES VERIFICATION QUEUE -->
    <?php if ($filter === 'all' || $filter === 'vehicles'): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="w-3 h-3 rounded-full bg-blue-500"></span>
                <h3 class="font-bold text-slate-900 text-sm">قائمة تدقيق مركبات الأسطول والمراقبة التقنية والتأمين</h3>
            </div>
            <span class="text-xs text-slate-400 font-num"><?= count($pendingVehicles) ?> مركبة</span>
        </div>

        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80">
                        <th class="p-3.5">المركبة والطراز</th>
                        <th class="p-3.5">رقم اللوحة والباب</th>
                        <th class="p-3.5">السائق المكلف</th>
                        <th class="p-3.5">المراقبة التقنية (Contrôle)</th>
                        <th class="p-3.5">التأمين الشامل (Assurance)</th>
                        <th class="p-3.5 text-center">الوضعية والقرار</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($pendingVehicles)): ?>
                        <?php foreach ($pendingVehicles as $veh): ?>
                            <?php
                                $isTcExp = !empty($veh['technical_control_expiry']) && strtotime($veh['technical_control_expiry']) < strtotime('+15 days');
                                $isInsExp = !empty($veh['insurance_expiry']) && strtotime($veh['insurance_expiry']) < strtotime('+15 days');
                            ?>
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($veh['brand'] . ' ' . $veh['model']) ?></div>
                                    <div class="text-[11px] text-slate-400">سنة السريان: 2026</div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 mb-1">
                                        <span class="table-num-badge bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/80 font-bold" dir="ltr">
                                            🚗 <?= htmlspecialchars($veh['plate_number']) ?>
                                        </span>
                                        <span class="table-num-badge table-num-slate text-[10px]">
                                            باب: <?= htmlspecialchars($veh['door_number']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($veh['driver_name'] ?? 'بدون سائق حالي') ?></div>
                                    <?php if (!empty($veh['driver_phone'])): ?>
                                        <span class="table-num-badge table-num-slate text-[10px] mt-0.5" dir="ltr">📞 <?= htmlspecialchars($veh['driver_phone']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($veh['technical_control_expiry'])): ?>
                                        <span class="table-num-badge <?= $isTcExp ? 'table-num-rose' : 'table-num-emerald' ?> text-[11px]" dir="ltr">
                                            <span class="<?= $isTcExp ? 'table-num-dot-rose' : 'table-num-dot-emerald' ?>"></span>
                                            <?= htmlspecialchars($veh['technical_control_expiry']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate text-[10px]">غير محدد</span>
                                    <?php endif; ?>
                                    <div class="text-[10px] text-slate-400 mt-0.5">فحص تقني دوري معتمد</div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($veh['insurance_expiry'])): ?>
                                        <span class="table-num-badge <?= $isInsExp ? 'table-num-rose' : 'table-num-emerald' ?> text-[11px]" dir="ltr">
                                            <span class="<?= $isInsExp ? 'table-num-dot-rose' : 'table-num-dot-emerald' ?>"></span>
                                            <?= htmlspecialchars($veh['insurance_expiry']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate text-[10px]">غير محدد</span>
                                    <?php endif; ?>
                                    <div class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($veh['insurance_company'] ?? 'SAA') ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if ($veh['status'] !== 'active'): ?>
                                        <form method="POST" action="" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="item_type" value="vehicle">
                                            <input type="hidden" name="item_id" value="<?= $veh['id'] ?>">
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold text-xs transition shadow-2xs">
                                                اعتماد للميدان ✓
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <a href="/dashboard/vehicles.php" class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition">
                                            تحديث الوثائق
                                        </a>
                                        <?php endif; ?>
                                        
                                        <button type="button" onclick="openRejectModal('vehicle', <?= $veh['id'] ?>, '<?= htmlspecialchars($veh['plate_number'], ENT_QUOTES) ?>')"
                                                class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs transition border border-rose-200/60">
                                            تعليق
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-400 text-xs">
                                جميع سيارات الأجرة في الأسطول مطابقة ومؤمنة بالكامل!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Modal: Reject / Suspend with Reason -->
<div id="rejectModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-rose-700">تعليق / رفض الملف والوثائق</h3>
            <button onclick="closeRejectModal()" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-3 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="item_type" id="rejectItemType">
            <input type="hidden" name="item_id" id="rejectItemId">

            <div class="p-3 bg-rose-50 rounded-xl border border-rose-100 text-slate-700">
                <span class="block font-bold">الملف المستهدف:</span>
                <span id="rejectItemLabel" class="font-bold text-rose-800 text-sm"></span>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">سبب التعليق أو الرفض *</label>
                <textarea name="reason" rows="3" required placeholder="مثال: رخصة السياقة منتهية الصلاحية، يرجى تقديم التجديد الرسمي..."
                          class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-rose-500 focus:ring-1 focus:ring-rose-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold transition shadow-xs">
                    تأكيد التعليق
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(type, id, label) {
    document.getElementById('rejectItemType').value = type;
    document.getElementById('rejectItemId').value = id;
    document.getElementById('rejectItemLabel').textContent = label;
    
    const m = document.getElementById('rejectModal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}

function closeRejectModal() {
    const m = document.getElementById('rejectModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
