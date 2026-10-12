<?php
// dashboard/index.php - Executive Fleet & Compliance Dashboard
$pageTitle = 'لوحة التحكم والمؤشرات';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Fetch KPIs
$totalVehicles = 0;
$activeVehiclesCount = 0;
$activeDrivers = 0;
$activeContracts = 0;
$totalRevenue = 0.00;
$expiringVehicles = [];
$recentContracts = [];
$activeLeaves = [];

if ($pdo) {
    $totalVehicles = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
    $activeVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active'")->fetchColumn();
    $activeDrivers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status = 'active'")->fetchColumn();
    $activeContracts = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status = 'signed'")->fetchColumn();
    $totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM billings WHERE status = 'paid'")->fetchColumn();

    // Vehicles with documents expiring within 30 days
    $stmtExp = $pdo->query("
        SELECT v.*, u.full_name as driver_name,
               DATEDIFF(v.technical_control_expiry, CURDATE()) as tc_days,
               DATEDIFF(v.insurance_expiry, CURDATE()) as ins_days,
               DATEDIFF(v.gpl_expiry, CURDATE()) as gpl_days,
               DATEDIFF(v.saccuriometre_expiry, CURDATE()) as sac_days
        FROM vehicles v
        LEFT JOIN users u ON v.current_driver_id = u.id
        WHERE (v.technical_control_expiry IS NOT NULL AND v.technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
           OR (v.insurance_expiry IS NOT NULL AND v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
           OR (v.gpl_expiry IS NOT NULL AND v.gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
           OR (v.saccuriometre_expiry IS NOT NULL AND saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        ORDER BY tc_days ASC LIMIT 6
    ");
    $expiringVehicles = $stmtExp->fetchAll();

    // Recent contracts
    $stmtCt = $pdo->query("
        SELECT c.*, v.plate_number, v.brand, v.model, u.full_name as driver_name
        FROM contracts c
        JOIN vehicles v ON c.vehicle_id = v.id
        JOIN users u ON c.driver_id = u.id
        ORDER BY c.created_at DESC LIMIT 4
    ");
    $recentContracts = $stmtCt->fetchAll();

    // Active leaves / relief
    $stmtLv = $pdo->query("
        SELECT l.*, v.plate_number, u1.full_name as original_driver, u2.full_name as relief_driver
        FROM leaves l
        JOIN vehicles v ON l.vehicle_id = v.id
        JOIN users u1 ON l.driver_id = u1.id
        LEFT JOIN users u2 ON l.replacement_driver_id = u2.id
        WHERE l.status = 'approved' AND l.end_date >= CURDATE()
        ORDER BY l.start_date DESC LIMIT 3
    ");
    $activeLeaves = $stmtLv->fetchAll();
}
?>

<div class="space-y-6 max-w-7xl mx-auto">
    
    <!-- Top Action Banner (Clean & Authoritative) -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 border border-amber-200 flex items-center justify-center shrink-0 shadow-2xs">
                <img src="/logo.webp" alt="تاكسي نقيلو" class="w-8 h-8 object-contain">
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base sm:text-lg font-black text-slate-900 leading-tight">مرحباً بك، <?= htmlspecialchars($user['name']) ?></h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                        الأسطول الميداني نشط
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">منصة تاكسي نقيلو لإدارة الأساطيل، عقود العمل النظامية 90-11، ومطابقة مديرية النقل.</p>
            </div>
        </div>

        <!-- Cohesive Quick Action Controls -->
        <div class="flex items-center gap-2 self-start md:self-auto">
            <a href="/dashboard/fleet.php" class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-2xs flex items-center gap-2">
                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span>تتبع الأسطول</span>
            </a>
            <a href="/dashboard/vehicles.php?action=new" class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-2xs flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>إضافة مركبة</span>
            </a>
            <a href="/dashboard/traveler_reports.php" class="px-3 py-2 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs transition flex items-center gap-1.5">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span class="hidden sm:inline">محضر سفر</span>
            </a>
        </div>
    </div>

    <!-- 4 High-Signal Metric Cards (Impeccable Minimalist Layout) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        
        <!-- Total Fleet -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">أسطول سيارات الأجرة</span>
                <span class="p-1.5 rounded-lg bg-amber-50 text-amber-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 17a2 2 0 100-4 2 2 0 000 4zm8 0a2 2 0 100-4 2 2 0 000 4zM5 11l2-5h10l2 5M5 11h14v5a1 1 0 01-1 1H6a1 1 0 01-1-1v-5z"/></svg>
                </span>
            </div>
            <div class="my-2">
                <div class="text-3xl font-black text-slate-900 font-num tracking-tight"><?= $totalVehicles ?></div>
            </div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between border-t border-slate-100 pt-2.5">
                <span class="text-emerald-700 font-bold"><?= $activeVehiclesCount ?> في الخدمة</span>
                <a href="/dashboard/vehicles.php" class="text-amber-700 font-bold hover:underline">السجل الكامل ←</a>
            </div>
        </div>

        <!-- Active Drivers -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">السائقون المعتمدون</span>
                <span class="p-1.5 rounded-lg bg-slate-100 text-slate-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </span>
            </div>
            <div class="my-2">
                <div class="text-3xl font-black text-slate-900 font-num tracking-tight"><?= $activeDrivers ?></div>
            </div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between border-t border-slate-100 pt-2.5">
                <span>بطاقات كفاءة مهنية</span>
                <a href="/dashboard/drivers.php" class="text-amber-700 font-bold hover:underline">الملفات ←</a>
            </div>
        </div>

        <!-- Valid Contracts 90-11 -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">عقود العمل (قانون 90-11)</span>
                <span class="p-1.5 rounded-lg bg-emerald-50 text-emerald-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
            </div>
            <div class="my-2">
                <div class="text-3xl font-black text-slate-900 font-num tracking-tight"><?= $activeContracts ?></div>
            </div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between border-t border-slate-100 pt-2.5">
                <span class="text-emerald-700 font-bold">100% موثقة</span>
                <a href="/dashboard/contracts.php" class="text-amber-700 font-bold hover:underline">العقود ←</a>
            </div>
        </div>

        <!-- Collected Subscriptions -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs flex flex-col justify-between">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">الاشتراكات المحصلة</span>
                <span class="p-1.5 rounded-lg bg-emerald-50 text-emerald-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
            </div>
            <div class="my-2">
                <div class="text-3xl font-black text-emerald-700 font-num tracking-tight"><?= number_format($totalRevenue) ?> <span class="text-xs font-bold text-slate-400">دج</span></div>
            </div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between border-t border-slate-100 pt-2.5">
                <span>BaridiMob / CCP</span>
                <a href="/dashboard/billing.php" class="text-amber-700 font-bold hover:underline">الفوترة ←</a>
            </div>
        </div>

    </div>

    <!-- Urgent Document Expiry Radar (Operating Mode: Clear, Dense & Scannable) -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-ping"></span>
                <h3 class="font-black text-slate-900 text-sm">رادار صلاحية وثائق الأسطول وتفادي المحشر</h3>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-400 font-medium">عتبة التنبيه: 30 يوماً</span>
                <a href="/dashboard/vehicles.php?filter=expiring" class="text-xs font-bold text-amber-700 hover:underline">عرض الكل ←</a>
            </div>
        </div>

        <?php if (!empty($expiringVehicles)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-extrabold border-b border-slate-100 uppercase tracking-wider">
                        <tr>
                            <th class="p-3.5">المركبة واللوحة</th>
                            <th class="p-3.5">السائق المعين</th>
                            <th class="p-3.5">المراقبة التقنية</th>
                            <th class="p-3.5">التأمين</th>
                            <th class="p-3.5">غاز GPL</th>
                            <th class="p-3.5">الساكوريومتر</th>
                            <th class="p-3.5 text-center">الإجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($expiringVehicles as $v): ?>
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="p-3.5">
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?></div>
                                    <div class="text-[11px] text-slate-500 font-num font-bold" dir="ltr"><?= htmlspecialchars($v['plate_number']) ?></div>
                                </td>
                                <td class="p-3.5 text-slate-700 font-bold"><?= htmlspecialchars($v['driver_name'] ?? 'بدون سائق') ?></td>
                                
                                <!-- TC Days -->
                                <td class="p-3.5">
                                    <?php if ($v['tc_days'] !== null && $v['tc_days'] <= 15): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-rose-50 text-rose-700 font-bold border border-rose-200">
                                            باقي <?= $v['tc_days'] ?> أيام!
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600 font-num"><?= htmlspecialchars($v['technical_control_expiry'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Ins Days -->
                                <td class="p-3.5">
                                    <?php if ($v['ins_days'] !== null && $v['ins_days'] <= 15): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 font-bold border border-amber-200">
                                            باقي <?= $v['ins_days'] ?> أيام
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600 font-num"><?= htmlspecialchars($v['insurance_expiry'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- GPL Days -->
                                <td class="p-3.5">
                                    <?php if ($v['gpl_days'] !== null && $v['gpl_days'] <= 15): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 font-bold border border-amber-200">
                                            باقي <?= $v['gpl_days'] ?> أيام
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600 font-num"><?= htmlspecialchars($v['gpl_expiry'] ?? 'مطابق') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Saccuriometre -->
                                <td class="p-3.5">
                                    <span class="text-slate-700 font-bold"><?= htmlspecialchars($v['saccuriometre_text'] ?? 'معاير ومختوم') ?></span>
                                </td>

                                <td class="p-3.5 text-center">
                                    <a href="/dashboard/vehicles.php?search=<?= urlencode($v['plate_number']) ?>" class="px-3 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-[11px] transition shadow-2xs inline-block">
                                        تجديد الوثائق
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-8 text-center text-slate-400 text-xs">
                ✓ جميع وثائق الأسطول (تأمين، فحص تقني، عداد، GPL) سارية المفعول لأكثر من 30 يوماً.
            </div>
        <?php endif; ?>
    </div>

    <!-- Operations Dual Hub: Recent Contracts & Active Relief Drivers -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Recent Contracts 90-11 -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <h3 class="font-extrabold text-slate-900 text-sm">أحدث عقود العمل (قانون 90-11)</h3>
                </div>
                <a href="/dashboard/contracts.php" class="text-xs font-bold text-amber-700 hover:underline">كل العقود ←</a>
            </div>

            <div class="space-y-2.5">
                <?php foreach ($recentContracts as $ct): ?>
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between text-xs hover:border-slate-200 transition">
                        <div>
                            <div class="font-black text-slate-900 flex items-center gap-2">
                                <span>العقد: <span class="font-num text-amber-800"><?= htmlspecialchars($ct['contract_number']) ?></span></span>
                                <span class="px-2 py-0.2 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold">موقع وموثق</span>
                            </div>
                            <div class="text-slate-500 mt-1">
                                السائق: <strong class="text-slate-800"><?= htmlspecialchars($ct['driver_name']) ?></strong> • <?= htmlspecialchars($ct['brand'] . ' ' . $ct['model']) ?> (<span class="font-num" dir="ltr"><?= htmlspecialchars($ct['plate_number']) ?></span>)
                            </div>
                        </div>
                        <div>
                            <a href="/dashboard/contract_view.php?id=<?= $ct['id'] ?>" class="px-2.5 py-1 rounded-lg bg-white border border-slate-200 text-slate-700 hover:text-slate-950 font-bold text-[11px] transition shadow-2xs">
                                معاينة العقد
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Relief Drivers / Dispatches -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <h3 class="font-extrabold text-slate-900 text-sm">السائقون البدلاء وجداول الإرسال</h3>
                </div>
                <a href="/dashboard/leaves.php" class="text-xs font-bold text-amber-700 hover:underline">إدارة العطل ←</a>
            </div>

            <?php if (!empty($activeLeaves)): ?>
                <div class="space-y-2.5">
                    <?php foreach ($activeLeaves as $lv): ?>
                        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between text-xs hover:border-slate-200 transition">
                            <div>
                                <div class="font-bold text-slate-900">
                                    المركبة: <span class="font-num font-bold text-slate-800" dir="ltr"><?= htmlspecialchars($lv['plate_number']) ?></span>
                                </div>
                                <div class="text-slate-500 mt-1">
                                    الأصلي: <span class="line-through"><?= htmlspecialchars($lv['original_driver']) ?></span> ← <strong class="text-emerald-700">البديل: <?= htmlspecialchars($lv['relief_driver'] ?? 'معين') ?></strong>
                                </div>
                            </div>
                            <div class="text-left">
                                <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 font-bold border border-amber-200 font-num text-[10px] block">
                                    حتى <?= htmlspecialchars($lv['end_date']) ?>
                                </span>
                                <a href="/dashboard/slips.php" class="inline-block text-[11px] text-slate-500 hover:text-slate-800 mt-1 font-bold">
                                    جدول الإرسال ←
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-8 text-center text-slate-400 text-xs">
                    ✓ جميع السائقين الأساسيين على رأس عملهم دون عطل نشطة.
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
