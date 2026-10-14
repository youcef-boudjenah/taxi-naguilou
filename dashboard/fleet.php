<?php
// dashboard/fleet.php - Live Fleet Tracking & Operational Readiness
$pageTitle = 'تتبع الأسطول الميداني والجاهزية';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

$filterStatus = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

$sql = "
    SELECT v.*, 
           u.full_name as driver_name, u.phone as driver_phone,
           ur.full_name as relief_driver_name, ur.phone as relief_driver_phone,
           DATEDIFF(v.technical_control_expiry, CURDATE()) as tc_days,
           DATEDIFF(v.insurance_expiry, CURDATE()) as ins_days,
           DATEDIFF(v.extinguisher_expiry, CURDATE()) as ext_days,
           DATEDIFF(v.gpl_expiry, CURDATE()) as gpl_days,
           DATEDIFF(v.saccuriometre_expiry, CURDATE()) as sac_days
    FROM vehicles v
    LEFT JOIN users u ON v.current_driver_id = u.id
    LEFT JOIN users ur ON v.replacement_driver_id = ur.id
    WHERE 1=1
";
$params = [];

if (is_owner() && !is_admin()) {
    $sql .= " AND (v.owner_id = ? OR v.owner_id IS NULL)";
    $params[] = intval($_SESSION['user_id']);
}

if ($filterStatus === 'active') {
    $sql .= " AND v.status = 'active'";
} elseif ($filterStatus === 'maintenance') {
    $sql .= " AND v.status = 'in_maintenance'";
} elseif ($filterStatus === 'suspended') {
    $sql .= " AND v.status = 'suspended'";
} elseif ($filterStatus === 'relief') {
    $sql .= " AND v.replacement_driver_id IS NOT NULL";
}

if (!empty($searchQuery)) {
    $sql .= " AND (v.plate_number LIKE ? OR v.door_number LIKE ? OR u.full_name LIKE ? OR v.brand LIKE ?)";
    $like = "%$searchQuery%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$sql .= " ORDER BY v.status ASC, v.id ASC";

$vehicles = [];
$counts = ['all' => 0, 'active' => 0, 'maintenance' => 0, 'suspended' => 0, 'relief' => 0];

if ($pdo) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $vehicles = $stmt->fetchAll();

        $ownerFilter = (is_owner() && !is_admin()) ? " WHERE owner_id = " . intval($_SESSION['user_id']) : "";
        $whereOrAnd = empty($ownerFilter) ? " WHERE " : " AND ";

        $counts['all'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles" . $ownerFilter)->fetchColumn();
        $counts['active'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles" . $ownerFilter . $whereOrAnd . "status = 'active'")->fetchColumn();
        $counts['maintenance'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles" . $ownerFilter . $whereOrAnd . "status = 'in_maintenance'")->fetchColumn();
        $counts['suspended'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles" . $ownerFilter . $whereOrAnd . "status = 'suspended'")->fetchColumn();
        $counts['relief'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles" . $ownerFilter . $whereOrAnd . "replacement_driver_id IS NOT NULL")->fetchColumn();
    } catch (\Throwable $e) {
        error_log("fleet.php query error: " . $e->getMessage());
    }
}
?>

<div class="space-y-6">

    <!-- Top Action Banner -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-black text-slate-900">تتبع الأسطول الميداني والجاهزية الفورية</h2>
            <p class="text-xs text-slate-500 mt-1">متابعة سيارات الأجرة في الخدمة، السائقين الميدانيين، المناوبات الحالية، وتنبيهات سلامة وتجهيزات المركبات.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                مؤشرات الأسطول محدثة لحظياً
            </span>
        </div>
    </div>

    <!-- Filter Pills -->
    <div class="flex flex-wrap gap-2 text-xs font-bold">
        <a href="?status=all" class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $filterStatus === 'all' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>كامل الأسطول</span>
            <span class="px-1.5 py-0.5 rounded-md bg-slate-800 text-white font-num text-[10px]"><?= $counts['all'] ?></span>
        </a>
        <a href="?status=active" class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $filterStatus === 'active' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>في الخدمة الميدانية</span>
            <span class="px-1.5 py-0.5 rounded-md bg-emerald-700 text-white font-num text-[10px]"><?= $counts['active'] ?></span>
        </a>
        <a href="?status=relief" class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $filterStatus === 'relief' ? 'bg-amber-500 text-slate-950 border-amber-500' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>يقودها سائق بديل</span>
            <span class="px-1.5 py-0.5 rounded-md bg-amber-100 text-amber-800 font-num text-[10px]"><?= $counts['relief'] ?></span>
        </a>
        <a href="?status=maintenance" class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $filterStatus === 'maintenance' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>في الصيانة والدورية</span>
            <span class="px-1.5 py-0.5 rounded-md bg-blue-700 text-white font-num text-[10px]"><?= $counts['maintenance'] ?></span>
        </a>
        <a href="?status=suspended" class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $filterStatus === 'suspended' ? 'bg-rose-600 text-white border-rose-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>متوقفة إدارياً</span>
            <span class="px-1.5 py-0.5 rounded-md bg-rose-700 text-white font-num text-[10px]"><?= $counts['suspended'] ?></span>
        </a>
    </div>

    <!-- Live Fleet Table with Exact Requested Columns -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-700 font-bold border-b border-slate-200/90 whitespace-nowrap">
                    <tr>
                        <th class="p-3.5">المركبة</th>
                        <th class="p-3.5 text-center">رقم الباب</th>
                        <th class="p-3.5">السائق الرئيسي</th>
                        <th class="p-3.5">السائق البديل</th>
                        <th class="p-3.5">السائق النشط</th>
                        <th class="p-3.5 text-center">رقم اللوحة</th>
                        <th class="p-3.5 text-center font-num">تاريخ انتهاء التأمين</th>
                        <th class="p-3.5 text-center font-num">تاريخ انتهاء الفحص التقني</th>
                        <th class="p-3.5 text-center font-num">تاريخ انتهاء طفاية الحريق</th>
                        <th class="p-3.5 text-center font-num">تاريخ انتهاء GPL</th>
                        <th class="p-3.5 text-center font-num">تاريخ انتهاء شهادة العداد</th>
                        <th class="p-3.5 text-center">الحالة</th>
                        <th class="p-3.5 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($vehicles)): ?>
                        <tr>
                            <td colspan="13" class="p-12 text-center text-slate-400">
                                لا توجد مركبات مطابقة لمعايير التصفية الحالية.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vehicles as $v): 
                            $isRelief = !empty($v['relief_driver_name']);
                            $activeDriver = $isRelief ? $v['relief_driver_name'] : ($v['driver_name'] ?? 'بدون سائق');
                            $activePhone = $isRelief ? ($v['relief_driver_phone'] ?? '') : ($v['driver_phone'] ?? '');

                            // Expiry Helpers
                            $insWarning = ($v['ins_days'] !== null && $v['ins_days'] <= 30);
                            $tcWarning  = ($v['tc_days'] !== null && $v['tc_days'] <= 30);
                            $extWarning = ($v['ext_days'] !== null && $v['ext_days'] <= 30);
                            $gplWarning = ($v['gpl_days'] !== null && $v['gpl_days'] <= 30);
                            $sacWarning = ($v['sac_days'] !== null && $v['sac_days'] <= 30);
                        ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                
                                <!-- 1. المركبة -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="text-base">🚕</span>
                                        <div>
                                            <span class="font-black text-slate-900 block"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?></span>
                                            <span class="text-[10px] text-slate-400"><?= htmlspecialchars($v['color'] ?? 'أصفر') ?></span>
                                        </div>
                                    </div>
                                </td>

                                <!-- 2. رقم الباب -->
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-md bg-slate-100 font-num font-bold text-slate-800 text-[11px]" dir="ltr">
                                        <?= htmlspecialchars($v['door_number']) ?>
                                    </span>
                                </td>

                                <!-- 3. السائق الرئيسي -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['driver_name'])): ?>
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($v['driver_name']) ?></div>
                                        <?php if (!empty($v['driver_phone'])): ?>
                                            <div class="text-[10px] text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($v['driver_phone']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-xs">غير معيّن</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. السائق البديل -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['relief_driver_name'])): ?>
                                        <div class="font-bold text-amber-700"><?= htmlspecialchars($v['relief_driver_name']) ?></div>
                                        <?php if (!empty($v['relief_driver_phone'])): ?>
                                            <div class="text-[10px] text-amber-600 font-num" dir="ltr"><?= htmlspecialchars($v['relief_driver_phone']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-300 text-xs">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. السائق النشط -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold <?= $isRelief ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-emerald-50 text-emerald-800 border border-emerald-200' ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $isRelief ? 'bg-amber-500' : 'bg-emerald-500' ?>"></span>
                                        <span><?= htmlspecialchars($activeDriver) ?></span>
                                        <?= $isRelief ? '<span class="text-[9px] opacity-75">(بديل)</span>' : '' ?>
                                    </span>
                                </td>

                                <!-- 6. رقم اللوحة -->
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <span class="font-mono text-xs font-black px-2.5 py-1 rounded-lg bg-amber-100 text-amber-950 border border-amber-300 inline-block" dir="ltr">
                                        <?= htmlspecialchars($v['plate_number']) ?>
                                    </span>
                                </td>

                                <!-- 7. تاريخ انتهاء التأمين -->
                                <td class="p-3.5 text-center whitespace-nowrap font-num">
                                    <span class="inline-block <?= $insWarning ? 'text-rose-600 font-bold' : 'text-slate-700' ?>" dir="ltr">
                                        <?= htmlspecialchars($v['insurance_expiry'] ?? '—') ?>
                                    </span>
                                    <?php if ($v['ins_days'] !== null && $v['ins_days'] <= 30): ?>
                                        <span class="block text-[9px] text-rose-500 font-sans font-bold"><?= $v['ins_days'] <= 0 ? 'منتهي!' : "باقي {$v['ins_days']} يوم" ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- 8. تاريخ انتهاء الفحص التقني -->
                                <td class="p-3.5 text-center whitespace-nowrap font-num">
                                    <span class="inline-block <?= $tcWarning ? 'text-rose-600 font-bold' : 'text-slate-700' ?>" dir="ltr">
                                        <?= htmlspecialchars($v['technical_control_expiry'] ?? '—') ?>
                                    </span>
                                    <?php if ($v['tc_days'] !== null && $v['tc_days'] <= 30): ?>
                                        <span class="block text-[9px] text-rose-500 font-sans font-bold"><?= $v['tc_days'] <= 0 ? 'منتهي!' : "باقي {$v['tc_days']} يوم" ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- 9. تاريخ انتهاء طفاية الحريق -->
                                <td class="p-3.5 text-center whitespace-nowrap font-num">
                                    <span class="inline-block <?= $extWarning ? 'text-amber-700 font-bold' : 'text-slate-700' ?>" dir="ltr">
                                        <?= htmlspecialchars($v['extinguisher_expiry'] ?? '—') ?>
                                    </span>
                                    <?php if ($v['ext_days'] !== null && $v['ext_days'] <= 30): ?>
                                        <span class="block text-[9px] text-amber-600 font-sans font-bold"><?= $v['ext_days'] <= 0 ? 'منتهي!' : "باقي {$v['ext_days']} يوم" ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- 10. تاريخ انتهاء GPL -->
                                <td class="p-3.5 text-center whitespace-nowrap font-num">
                                    <span class="inline-block <?= $gplWarning ? 'text-amber-700 font-bold' : 'text-slate-700' ?>" dir="ltr">
                                        <?= htmlspecialchars($v['gpl_expiry'] ?? '—') ?>
                                    </span>
                                    <?php if ($v['gpl_days'] !== null && $v['gpl_days'] <= 30): ?>
                                        <span class="block text-[9px] text-amber-600 font-sans font-bold"><?= $v['gpl_days'] <= 0 ? 'منتهي!' : "باقي {$v['gpl_days']} يوم" ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- 11. تاريخ انتهاء شهادة العداد -->
                                <td class="p-3.5 text-center whitespace-nowrap font-num">
                                    <span class="inline-block <?= $sacWarning ? 'text-rose-600 font-bold' : 'text-slate-700' ?>" dir="ltr">
                                        <?= htmlspecialchars($v['saccuriometre_expiry'] ?? '—') ?>
                                    </span>
                                    <?php if ($v['sac_days'] !== null && $v['sac_days'] <= 30): ?>
                                        <span class="block text-[9px] text-rose-500 font-sans font-bold"><?= $v['sac_days'] <= 0 ? 'منتهي!' : "باقي {$v['sac_days']} يوم" ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- 12. الحالة -->
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <?php if ($v['status'] === 'active'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            نشطة في الخدمة
                                        </span>
                                    <?php elseif ($v['status'] === 'in_maintenance'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                            في الصيانة
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            موقوفة
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 13. الإجراءات -->
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5">
                                        <a href="vehicles.php?search=<?= urlencode($v['plate_number']) ?>" 
                                           class="px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-700 font-bold text-[11px] transition" title="عرض ملف المركبة">
                                            الملف
                                        </a>
                                        <a href="leaves.php?vehicle_id=<?= $v['id'] ?>&action=new" 
                                           class="px-2.5 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-[11px] transition" title="تعيين سائق بديل">
                                            تعيين بديل
                                        </a>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
