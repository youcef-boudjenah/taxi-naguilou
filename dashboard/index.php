<?php
// dashboard/index.php - Executive Dashboard
$pageTitle = 'نظرة عامة على الأسطول';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Fetch KPIs
$totalVehicles = 0;
$activeDrivers = 0;
$activeContracts = 0;
$totalRevenue = 0.00;
$expiringVehicles = [];
$recentContracts = [];
$activeLeaves = [];

if ($pdo) {
    $totalVehicles = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
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
           OR (v.saccuriometre_expiry IS NOT NULL AND v.saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
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

    // Active leaves
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

<div class="space-y-8">
    
    <!-- Top Action Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex items-center gap-3.5">
            <img src="/logo.webp" alt="EURL TAXI NAGUILOU" class="w-12 h-12 object-contain rounded-full bg-white p-0.5 border border-amber-300 shadow-sm shrink-0">
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">مرحباً بك، <?= htmlspecialchars($user['name']) ?></h2>
                <p class="text-xs text-slate-500 mt-0.5">منصة تاكسي نقيلو (EURL TAXI NAGUILOU Services) لإدارة الأسطول والعقود النظامية 90-11</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="/dashboard/vehicles.php?action=new" class="px-3.5 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                <span>➕</span>
                <span>إضافة مركبة</span>
            </a>
            <a href="/dashboard/contracts.php?action=new" class="px-3.5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                <span>📜</span>
                <span>توليد عقد 90-11</span>
            </a>
            <a href="/dashboard/leaves.php?action=new" class="px-3.5 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs transition flex items-center gap-1.5">
                <span>🔄</span>
                <span>تعيين سائق بديل</span>
            </a>
        </div>
    </div>

    <!-- 4 Clean KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">إجمالي الأسطول</span>
                <span class="text-xl">🚕</span>
            </div>
            <div class="text-3xl font-black text-slate-900 font-num"><?= $totalVehicles ?></div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between">
                <span>مركبات مسجلة</span>
                <a href="/dashboard/vehicles.php" class="text-amber-600 font-bold hover:underline">السجل ←</a>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">السائقون النشطون</span>
                <span class="text-xl">👨‍✈️</span>
            </div>
            <div class="text-3xl font-black text-slate-900 font-num"><?= $activeDrivers ?></div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between">
                <span>بطاقات كفاءة مهنية</span>
                <a href="/dashboard/drivers.php" class="text-amber-600 font-bold hover:underline">الملفات ←</a>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">عقود العمل السارية</span>
                <span class="text-xl">📑</span>
            </div>
            <div class="text-3xl font-black text-slate-900 font-num"><?= $activeContracts ?></div>
            <div class="text-[11px] text-emerald-600 font-bold flex items-center justify-between">
                <span>مطابقة مفتشية العمل</span>
                <a href="/dashboard/contracts.php" class="text-amber-600 font-bold hover:underline">العقود ←</a>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between text-slate-400">
                <span class="text-xs font-bold">الاشتراكات المحصلة</span>
                <span class="text-xl">💵</span>
            </div>
            <div class="text-3xl font-black text-emerald-600 font-num"><?= number_format($totalRevenue) ?> <span class="text-xs font-bold text-slate-400">دج</span></div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between">
                <span>BaridiMob / CCP</span>
                <a href="/dashboard/billing.php" class="text-amber-600 font-bold hover:underline">الفوترة ←</a>
            </div>
        </div>

    </div>

    <!-- Urgent Document Expiry Radar -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-pulse"></span>
                <h3 class="font-extrabold text-slate-900 text-sm sm:text-base">رادار مراقبة الوثائق وتفادي المحشر (Fourrière)</h3>
            </div>
            <span class="text-xs text-slate-400">تنبيهات استباقية قبل 30 يوماً</span>
        </div>

        <?php if (!empty($expiringVehicles)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-100">
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
                                    <div class="text-[11px] text-slate-500 font-num"><?= htmlspecialchars($v['plate_number']) ?></div>
                                </td>
                                <td class="p-3.5 text-slate-700"><?= htmlspecialchars($v['driver_name'] ?? 'بدون سائق') ?></td>
                                
                                <!-- TC Days -->
                                <td class="p-3.5">
                                    <?php if ($v['tc_days'] !== null && $v['tc_days'] <= 15): ?>
                                        <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700 font-bold border border-rose-200">
                                            باقي <?= $v['tc_days'] ?> أيام!
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600"><?= htmlspecialchars($v['technical_control_expiry'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Ins Days -->
                                <td class="p-3.5">
                                    <?php if ($v['ins_days'] !== null && $v['ins_days'] <= 15): ?>
                                        <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-700 font-bold border border-amber-200">
                                            باقي <?= $v['ins_days'] ?> أيام
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600"><?= htmlspecialchars($v['insurance_expiry'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- GPL Days -->
                                <td class="p-3.5">
                                    <?php if ($v['gpl_days'] !== null && $v['gpl_days'] <= 15): ?>
                                        <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-700 font-bold border border-amber-200">
                                            باقي <?= $v['gpl_days'] ?> أيام
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600"><?= htmlspecialchars($v['gpl_expiry'] ?? 'مطابق') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Saccuriometre -->
                                <td class="p-3.5">
                                    <span class="text-slate-700 font-semibold"><?= htmlspecialchars($v['saccuriometre_text'] ?? 'معاير') ?></span>
                                </td>

                                <td class="p-3.5 text-center">
                                    <a href="/dashboard/vehicles.php?edit=<?= $v['id'] ?>" class="px-2.5 py-1 rounded bg-slate-100 hover:bg-amber-500 hover:text-slate-950 font-bold text-slate-700 transition">
                                        تحديث الوثائق
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-8 text-center text-slate-400 text-xs">
                ✓ ممتاز! جميع وثائق الأسطول سارية المفعول لأكثر من 30 يوماً.
            </div>
        <?php endif; ?>
    </div>

    <!-- Dual Grid: Recent Contracts & Active Relief Drivers -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Recent Contracts 90-11 -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="font-extrabold text-slate-900 text-sm flex items-center gap-2">
                    <span>📜</span>
                    <span>أحدث عقود العمل الموقعة (قانون 90-11)</span>
                </h3>
                <a href="/dashboard/contracts.php" class="text-xs font-bold text-amber-600 hover:underline">كل العقود ←</a>
            </div>

            <div class="space-y-3">
                <?php foreach ($recentContracts as $ct): ?>
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between text-xs">
                        <div>
                            <div class="font-bold text-slate-800">
                                العقد رقم: <span class="font-num text-amber-700"><?= htmlspecialchars($ct['contract_number']) ?></span>
                            </div>
                            <div class="text-slate-500 mt-0.5">
                                السائق: <?= htmlspecialchars($ct['driver_name']) ?> • <?= htmlspecialchars($ct['brand'] . ' ' . $ct['model']) ?>
                            </div>
                        </div>
                        <div class="text-left">
                            <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-bold">موقع ومطابق</span>
                            <a href="/dashboard/contract_view.php?id=<?= $ct['id'] ?>" class="block text-[11px] text-slate-500 hover:text-slate-800 mt-1 underline">معاينة وطباعة</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Relief Drivers / Dispatches -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="font-extrabold text-slate-900 text-sm flex items-center gap-2">
                    <span>🔄</span>
                    <span>السائقون البدلاء وجداول الإرسال النشطة</span>
                </h3>
                <a href="/dashboard/leaves.php" class="text-xs font-bold text-amber-600 hover:underline">إدارة العطل ←</a>
            </div>

            <?php if (!empty($activeLeaves)): ?>
                <div class="space-y-3">
                    <?php foreach ($activeLeaves as $lv): ?>
                        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between text-xs">
                            <div>
                                <div class="font-bold text-slate-800">السيارة: <span class="font-num"><?= htmlspecialchars($lv['plate_number']) ?></span></div>
                                <div class="text-slate-500 mt-0.5">
                                    الأصلي: <span class="line-through"><?= htmlspecialchars($lv['original_driver']) ?></span> ← <span class="text-emerald-600 font-bold">البديل: <?= htmlspecialchars($lv['relief_driver'] ?? 'معين') ?></span>
                                </div>
                            </div>
                            <div class="text-left">
                                <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-800 font-bold">حتى <?= htmlspecialchars($lv['end_date']) ?></span>
                                <a href="/dashboard/slips.php" class="block text-[11px] text-slate-500 hover:text-slate-800 mt-1 underline">جدول الإرسال</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-8 text-center text-slate-400 text-xs">
                    لا توجد عطل نشطة حالياً. جميع السائقين الأساسيين على رأس عملهم.
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
