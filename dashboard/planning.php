<?php
// dashboard/planning.php - Garde de nuit du parc & Prochain planning des chauffeurs (3 jours)
$pageTitle = 'Garde de Nuit & Planning des Chauffeurs';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$drivers = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$vehicles = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles ORDER BY plate_number ASC")->fetchAll();

// Handle New Shift Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_shift') {
    require_csrf_token();
    $driverId = intval($_POST['driver_id'] ?? 0);
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $shiftDate = !empty($_POST['shift_date']) ? $_POST['shift_date'] : date('Y-m-d');
    $shiftType = $_POST['shift_type'] ?? 'matin';
    $isPermanence = isset($_POST['is_permanence']) ? 1 : 0;

    if ($driverId > 0 && $vehicleId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO driver_schedules (driver_id, vehicle_id, shift_date, shift_type, is_permanence)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$driverId, $vehicleId, $shiftDate, $shiftType, $isPermanence]);
        $sId = (int)$pdo->lastInsertId();
        log_audit($pdo, 'shift_scheduled', 'driver_schedules', $sId, "برمجة مناوبة للسائق $driverId على المركبة $vehicleId بتاريخ $shiftDate");
        set_flash('success', 'تم تسجيل مناوبة السائق بنجاح في الجدول التقديري');
        redirect('/dashboard/planning.php');
    }
}

// Handle Night Watch Entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_night_watch') {
    require_csrf_token();
    $vId = intval($_POST['vehicle_id'] ?? 0);
    $guardian = trim($_POST['guardian_name'] ?? 'عمي عيسى (حارس الحظيرة)');
    $slot = trim($_POST['park_slot'] ?? 'Zone A');
    $notes = trim($_POST['notes'] ?? '');

    if ($vId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO night_watch (vehicle_id, guardian_name, park_slot, entry_time, status, notes)
            VALUES (?, ?, ?, NOW(), 'in_park', ?)
        ");
        $stmt->execute([$vId, $guardian, $slot, $notes]);
        $nwId = (int)$pdo->lastInsertId();
        log_audit($pdo, 'night_watch_entry', 'night_watch', $nwId, "إيواء المركبة $vId في الحظيرة بموقف $slot تحت حراسة $guardian");
        set_flash('success', 'تم تسجيل إيواء المركبة في حراسة الحظيرة الليلية (Garde de nuit)');
        redirect('/dashboard/planning.php');
    }
}

// Handle Exit from park (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'exit_park') {
    require_csrf_token();
    $nwId = intval($_POST['night_watch_id'] ?? 0);
    if ($nwId > 0) {
        $pdo->prepare("UPDATE night_watch SET status = 'sorti', exit_time = NOW() WHERE id = ?")->execute([$nwId]);
        log_audit($pdo, 'night_watch_exit', 'night_watch', $nwId, "تسجيل خروج المركبة من الحظيرة إلى الخدمة");
        set_flash('success', 'تم تسجيل خروج سيارة الأجرة من الحظيرة إلى الخدمة الصباحية');
    }
    redirect('/dashboard/planning.php');
}

// Fetch Night Watch records
$nightWatch = $pdo->query("
    SELECT nw.*, v.brand, v.model, v.plate_number, v.door_number
    FROM night_watch nw
    JOIN vehicles v ON nw.vehicle_id = v.id
    WHERE nw.status = 'in_park'
    ORDER BY nw.entry_time DESC
")->fetchAll();

// Fetch 3-day schedules (Today, Tomorrow, Day+2)
$today = date('Y-m-d');
$day1 = date('Y-m-d', strtotime('+1 day'));
$day2 = date('Y-m-d', strtotime('+2 days'));

$schedules = $pdo->prepare("
    SELECT s.*, u.full_name as driver_name, u.phone as driver_phone,
           v.brand, v.model, v.plate_number, v.door_number
    FROM driver_schedules s
    JOIN users u ON s.driver_id = u.id
    JOIN vehicles v ON s.vehicle_id = v.id
    WHERE s.shift_date IN (?, ?, ?)
    ORDER BY s.shift_date ASC, s.shift_type ASC
");
$schedules->execute([$today, $day1, $day2]);
$allShifts = $schedules->fetchAll();

// Group shifts by date
$shiftsByDate = [
    $today => [],
    $day1 => [],
    $day2 => []
];
foreach ($allShifts as $sh) {
    if (isset($shiftsByDate[$sh['shift_date']])) {
        $shiftsByDate[$sh['shift_date']][] = $sh;
    }
}
?>

<div class="space-y-6">
    
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Garde de Nuit & Planning</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    مداومة الحظيرة وجدول المناوبات
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">حراسة الأسطول الليلي وجدول مناوبات وسائقي المداومة (CHAUFFEURS PERMANENCE)</p>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('addNightModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
                <span>إيواء بالحظيرة (Garde de Nuit)</span>
            </button>
            <button onclick="document.getElementById('addShiftModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>برمجة مناوبة جديدة</span>
            </button>
        </div>
    </div>

    <!-- Section 1: Garde de nuit du parc -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-5 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-700 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-sm">Garde de nuit du parc (حراسة الحظيرة الليلية)</h3>
                    <p class="text-xs text-slate-500">السيارات المتواجدة حالياً تحت حراسة الحظيرة المعتمدة</p>
                </div>
            </div>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 font-num">
                <?= count($nightWatch) ?> سيارات مأوية
            </span>
        </div>

        <?php if (!empty($nightWatch)): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5">
                <?php foreach ($nightWatch as $nw): ?>
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/80 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($nw['brand'] . ' ' . $nw['model']) ?></div>
                                <div class="font-num text-amber-700 font-semibold text-xs" dir="ltr"><?= htmlspecialchars($nw['plate_number']) ?> (باب: <?= htmlspecialchars($nw['door_number']) ?>)</div>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                داخل الحظيرة
                            </span>
                        </div>

                        <div class="space-y-1 text-xs text-slate-600 pt-2 border-t border-slate-200/80">
                            <div class="flex justify-between"><span>الموقف:</span> <strong class="text-slate-800"><?= htmlspecialchars($nw['park_slot']) ?></strong></div>
                            <div class="flex justify-between"><span>الحارس المكلف:</span> <strong class="text-slate-800"><?= htmlspecialchars($nw['guardian_name']) ?></strong></div>
                            <div class="flex justify-between"><span>ساعة الدخول:</span> <strong class="font-num text-slate-800" dir="ltr"><?= htmlspecialchars($nw['entry_time']) ?></strong></div>
                            <?php if (!empty($nw['notes'])): ?>
                                <div class="text-[11px] text-slate-400 italic pt-1">ملاحظات: <?= htmlspecialchars($nw['notes']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="pt-1">
                            <form method="POST" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="exit_park">
                                <input type="hidden" name="night_watch_id" value="<?= $nw['id'] ?>">
                                <button type="submit" 
                                        class="block w-full py-2 rounded-lg bg-white hover:bg-slate-100 border border-slate-300 text-center text-xs font-bold text-slate-700 transition">
                                    تسجيل خروج للخدمة (Sortie du parc)
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-8 text-center text-slate-400 text-xs">
                لا توجد سيارات مأوية حالياً في حراسة الحظيرة. جميع المركبات في النشاط الميداني.
            </div>
        <?php endif; ?>
    </div>

    <!-- Section 2: 3-Day Shift Planning -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-5 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-sm">Prochain planning des chauffeurs (3 jours)</h3>
                    <p class="text-xs text-slate-500">جدول مناوبات السائقين للأيام الثلاثة القادمة مع تحديد سائقي المداومة</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-800 text-xs font-bold border border-amber-200">
                    <svg class="w-3.5 h-3.5 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                    <span>CHAUFFEURS PERMANENCE</span>
                </span>
            </div>
        </div>

        <!-- 3-Day Columns Layout -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            
            <!-- Day 0 (Aujourd'hui) -->
            <div class="bg-slate-50/70 rounded-xl border border-slate-200 p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <div class="font-bold text-slate-900 text-xs">اليوم (Aujourd'hui)</div>
                    <span class="font-num text-xs font-bold text-amber-700 font-mono"><?= $today ?></span>
                </div>

                <div class="space-y-2">
                    <?php if (!empty($shiftsByDate[$today])): ?>
                        <?php foreach ($shiftsByDate[$today] as $s): ?>
                            <div class="bg-white p-3 rounded-lg border <?= $s['is_permanence'] ? 'border-amber-300 shadow-2xs' : 'border-slate-200' ?> text-xs space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($s['driver_name']) ?></span>
                                    <?php if ($s['is_permanence']): ?>
                                        <span class="px-2 py-0.2 rounded bg-amber-500 text-slate-950 font-bold text-[10px]">PERMANENCE</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.2 rounded bg-slate-100 text-slate-600 font-semibold text-[10px] uppercase"><?= $s['shift_type'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-500 font-num" dir="ltr">
                                    <?= htmlspecialchars($s['brand'] . ' ' . $s['model']) ?> • <?= htmlspecialchars($s['plate_number']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-6 text-center text-slate-400 text-xs">لا توجد مناوبات مبرمجة لليوم</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Day 1 (Demain J+1) -->
            <div class="bg-slate-50/70 rounded-xl border border-slate-200 p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <div class="font-bold text-slate-900 text-xs">غداً (Demain J+1)</div>
                    <span class="font-num text-xs font-bold text-slate-500 font-mono"><?= $day1 ?></span>
                </div>

                <div class="space-y-2">
                    <?php if (!empty($shiftsByDate[$day1])): ?>
                        <?php foreach ($shiftsByDate[$day1] as $s): ?>
                            <div class="bg-white p-3 rounded-lg border <?= $s['is_permanence'] ? 'border-amber-300 shadow-2xs' : 'border-slate-200' ?> text-xs space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($s['driver_name']) ?></span>
                                    <?php if ($s['is_permanence']): ?>
                                        <span class="px-2 py-0.2 rounded bg-amber-500 text-slate-950 font-bold text-[10px]">PERMANENCE</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.2 rounded bg-slate-100 text-slate-600 font-semibold text-[10px] uppercase"><?= $s['shift_type'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-500 font-num" dir="ltr">
                                    <?= htmlspecialchars($s['brand'] . ' ' . $s['model']) ?> • <?= htmlspecialchars($s['plate_number']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-6 text-center text-slate-400 text-xs">لا توجد مناوبات مبرمجة لغد</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Day 2 (Après-demain J+2) -->
            <div class="bg-slate-50/70 rounded-xl border border-slate-200 p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <div class="font-bold text-slate-900 text-xs">بعد غد (Après-demain J+2)</div>
                    <span class="font-num text-xs font-bold text-slate-500 font-mono"><?= $day2 ?></span>
                </div>

                <div class="space-y-2">
                    <?php if (!empty($shiftsByDate[$day2])): ?>
                        <?php foreach ($shiftsByDate[$day2] as $s): ?>
                            <div class="bg-white p-3 rounded-lg border <?= $s['is_permanence'] ? 'border-amber-300 shadow-2xs' : 'border-slate-200' ?> text-xs space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($s['driver_name']) ?></span>
                                    <?php if ($s['is_permanence']): ?>
                                        <span class="px-2 py-0.2 rounded bg-amber-500 text-slate-950 font-bold text-[10px]">PERMANENCE</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.2 rounded bg-slate-100 text-slate-600 font-semibold text-[10px] uppercase"><?= $s['shift_type'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-500 font-num" dir="ltr">
                                    <?= htmlspecialchars($s['brand'] . ' ' . $s['model']) ?> • <?= htmlspecialchars($s['plate_number']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-6 text-center text-slate-400 text-xs">لا توجد مناوبات مبرمجة</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- Modal: Add Shift Schedule -->
<div id="addShiftModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-md w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-900 text-base">برمجة مناوبة سائق (Planning Chauffeur)</h3>
            <button onclick="document.getElementById('addShiftModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_shift">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">حدد السائق <span class="text-rose-500">*</span></label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <option value="">-- اختر السائق --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">حدد سيارة الأجرة <span class="text-rose-500">*</span></label>
                <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <option value="">-- اختر السيارة --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ المناوبة</label>
                    <input type="date" name="shift_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs font-num bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">فترة الدوام (Shift)</label>
                    <select name="shift_type" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                        <option value="matin">Matin (صباحي 06h - 14h)</option>
                        <option value="soir">Soir (مسائي 14h - 22h)</option>
                        <option value="nuit">Nuit (ليلي 22h - 06h)</option>
                    </select>
                </div>
            </div>

            <div class="p-3 bg-amber-50 border border-amber-200/60 rounded-xl flex items-center gap-3">
                <input type="checkbox" id="isPermanenceCheck" name="is_permanence" value="1" class="w-4 h-4 rounded text-amber-600 focus:ring-amber-500">
                <label for="isPermanenceCheck" class="text-xs font-bold text-amber-950">
                    تصنيف كسائق مداومة رسمية (CHAUFFEURS PERMANENCE)
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('addShiftModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">إلغاء</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold transition shadow-xs">حفظ المناوبة</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Night Watch Entry -->
<div id="addNightModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-md w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-900 text-base">تسجيل إيواء بالحظيرة (Garde de Nuit)</h3>
            <button onclick="document.getElementById('addNightModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_night_watch">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">حدد سيارة الأجرة <span class="text-rose-500">*</span></label>
                <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <option value="">-- حدد السيارة --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">الحارس المكلف</label>
                    <input type="text" name="guardian_name" value="عمي عيسى (حارس الحظيرة)" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">موقع الموقف (Slot)</label>
                    <input type="text" name="park_slot" value="Zone A-01" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات حالة السيارة</label>
                <input type="text" name="notes" placeholder="مغلقة، عداد سليم، مفاتيح مسلمة للحراسة..." class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('addNightModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">إلغاء</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">تأكيد الإيواء بالحظيرة</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
