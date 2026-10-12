<?php
// dashboard/fleet.php - Live Fleet Tracking & Operational Readiness
$pageTitle = 'تتبع الأسطول الميداني والجاهزية';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

$filterStatus = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

$sql = "
    SELECT v.*, 
           u.full_name as driver_name, u.phone as driver_phone,
           ur.full_name as relief_driver_name, ur.phone as relief_driver_phone,
           DATEDIFF(v.technical_control_expiry, CURDATE()) as tc_days,
           DATEDIFF(v.insurance_expiry, CURDATE()) as ins_days,
           DATEDIFF(v.gpl_expiry, CURDATE()) as gpl_days,
           DATEDIFF(v.saccuriometre_expiry, CURDATE()) as sac_days,
           (SELECT status FROM night_watch WHERE vehicle_id = v.id ORDER BY entry_time DESC LIMIT 1) as park_status,
           (SELECT park_slot FROM night_watch WHERE vehicle_id = v.id ORDER BY entry_time DESC LIMIT 1) as park_slot
    FROM vehicles v
    LEFT JOIN users u ON v.current_driver_id = u.id
    LEFT JOIN users ur ON v.replacement_driver_id = ur.id
    WHERE 1=1
";
$params = [];

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
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();

    $counts['all'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
    $counts['active'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active'")->fetchColumn();
    $counts['maintenance'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'in_maintenance'")->fetchColumn();
    $counts['suspended'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'suspended'")->fetchColumn();
    $counts['relief'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE replacement_driver_id IS NOT NULL")->fetchColumn();
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

    <!-- Live Fleet Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php if (empty($vehicles)): ?>
            <div class="col-span-full bg-white p-12 text-center rounded-2xl border border-slate-200 text-slate-400">
                لا توجد مركبات مطابقة لمعايير التصفية الحالية.
            </div>
        <?php else: ?>
            <?php foreach ($vehicles as $v): 
                $hasUrgentAlert = ($v['tc_days'] !== null && $v['tc_days'] <= 15) 
                               || ($v['ins_days'] !== null && $v['ins_days'] <= 15)
                               || ($v['sac_days'] !== null && $v['sac_days'] <= 15)
                               || ($v['gpl_days'] !== null && $v['gpl_days'] <= 15);
                
                $activeDriver = !empty($v['replacement_driver_name']) ? $v['replacement_driver_name'] : ($v['driver_name'] ?? 'بدون سائق معين');
                $activePhone = !empty($v['replacement_driver_phone']) ? $v['replacement_driver_phone'] : ($v['driver_phone'] ?? '');
                $isRelief = !empty($v['replacement_driver_name']);
            ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col justify-between hover:border-amber-400 transition">
                    
                    <!-- Card Top Header -->
                    <div class="p-5 border-b border-slate-100 bg-slate-50/50">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-xl">🚕</span>
                                <div>
                                    <h3 class="font-black text-slate-900 text-sm"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?></h3>
                                    <span class="text-[10px] text-slate-400">رقم الباب: <?= htmlspecialchars($v['door_number']) ?> (<?= htmlspecialchars($v['color']) ?>)</span>
                                </div>
                            </div>

                            <span class="font-mono text-xs font-black px-2.5 py-1 rounded-lg bg-amber-100 text-amber-950 border border-amber-300" dir="ltr">
                                <?= htmlspecialchars($v['plate_number']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="p-5 space-y-3.5 text-xs">
                        
                        <!-- Driver Section -->
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 flex items-center justify-between">
                            <div>
                                <span class="text-[10px] text-slate-400 block font-bold">
                                    <?= $isRelief ? 'السائق البديل الحالي (عطلة):' : 'السائق المعتمد:' ?>
                                </span>
                                <strong class="text-slate-900 <?= $isRelief ? 'text-amber-800' : '' ?>">
                                    <?= htmlspecialchars($activeDriver) ?>
                                </strong>
                            </div>
                            <?php if (!empty($activePhone)): ?>
                                <a href="tel:<?= htmlspecialchars($activePhone) ?>" class="p-2 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                    <span>📞</span>
                                    <span class="font-mono" dir="ltr"><?= htmlspecialchars($activePhone) ?></span>
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Technical Equipments & Gauges -->
                        <div class="grid grid-cols-2 gap-2 text-[11px]">
                            <div class="p-2 rounded-lg bg-slate-50 border border-slate-100">
                                <span class="text-slate-400 block text-[9px]">عداد التاكسي (الساكوريومتر):</span>
                                <strong class="text-slate-800"><?= htmlspecialchars($v['saccuriometre_text'] ?? 'معاير ومختوم') ?></strong>
                                <?php if ($v['sac_days'] !== null && $v['sac_days'] <= 15): ?>
                                    <span class="text-[9px] text-rose-600 block font-bold">تجديد خلال <?= $v['sac_days'] ?>j!</span>
                                <?php endif; ?>
                            </div>

                            <div class="p-2 rounded-lg bg-slate-50 border border-slate-100">
                                <span class="text-slate-400 block text-[9px]">سيرغاز GPL & مطفأة:</span>
                                <strong class="text-slate-800">خزان معتمد ومطابق</strong>
                                <?php if ($v['gpl_days'] !== null && $v['gpl_days'] <= 15): ?>
                                    <span class="text-[9px] text-amber-700 block font-bold">فحص GPL خلال <?= $v['gpl_days'] ?>j!</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Document Status Indicators -->
                        <div class="space-y-1 pt-1">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="text-slate-500">التأمين (<?= htmlspecialchars($v['insurance_company']) ?>):</span>
                                <span class="font-bold <?= ($v['ins_days'] !== null && $v['ins_days'] <= 15) ? 'text-rose-600 font-num' : 'text-slate-800 font-num' ?>">
                                    <?= htmlspecialchars($v['insurance_expiry'] ?? '-') ?>
                                    <?= ($v['ins_days'] !== null && $v['ins_days'] <= 15) ? "({$v['ins_days']} أيام)" : '' ?>
                                </span>
                            </div>

                            <div class="flex items-center justify-between text-[11px]">
                                <span class="text-slate-500">المراقبة التقنية:</span>
                                <span class="font-bold <?= ($v['tc_days'] !== null && $v['tc_days'] <= 15) ? 'text-rose-600 font-num' : 'text-slate-800 font-num' ?>">
                                    <?= htmlspecialchars($v['technical_control_expiry'] ?? '-') ?>
                                    <?= ($v['tc_days'] !== null && $v['tc_days'] <= 15) ? "({$v['tc_days']} أيام)" : '' ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($hasUrgentAlert): ?>
                            <div class="p-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-[10px] font-bold flex items-center gap-1.5 animate-pulse">
                                <span>⚠️</span>
                                <span>تنبيه: وثائق إدارية وشيكة الانتهاء تستوجب التجديد لتفادي الحجز!</span>
                            </div>
                        <?php endif; ?>

                    </div>

                    <!-- Card Actions Footer -->
                    <div class="p-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="px-2 py-0.5 rounded-md font-bold text-[10px] <?= $v['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : ($v['status'] === 'in_maintenance' ? 'bg-blue-100 text-blue-800' : 'bg-rose-100 text-rose-800') ?>">
                            <?= $v['status'] === 'active' ? 'نشطة في الخدمة' : ($v['status'] === 'in_maintenance' ? 'في الصيانة' : 'موقوفة') ?>
                        </span>

                        <div class="flex items-center gap-1.5">
                            <a href="/dashboard/vehicles.php?search=<?= urlencode($v['plate_number']) ?>" 
                               class="px-2.5 py-1 rounded-lg border border-slate-200 text-slate-700 hover:bg-white text-[11px] font-bold transition">
                                الملف الكامل
                            </a>
                            <a href="/dashboard/leaves.php?vehicle_id=<?= $v['id'] ?>&action=new" 
                               class="px-2.5 py-1 rounded-lg bg-slate-900 text-white hover:bg-slate-800 text-[11px] font-bold transition">
                                تعيين بديل
                            </a>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
