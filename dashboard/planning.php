<?php
// dashboard/planning.php - Garde de nuit du parc & Prochain planning des chauffeurs (3 jours)
$pageTitle = 'Garde de Nuit & Planning des Chauffeurs';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$drivers = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active'")->fetchAll();
$vehicles = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles")->fetchAll();

// Handle New Shift Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_shift') {
    $driverId = intval($_POST['driver_id'] ?? 0);
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $shiftDate = $_POST['shift_date'] ?? date('Y-m-d');
    $shiftType = $_POST['shift_type'] ?? 'matin';
    $isPermanence = isset($_POST['is_permanence']) ? 1 : 0;

    if ($driverId > 0 && $vehicleId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO driver_schedules (driver_id, vehicle_id, shift_date, shift_type, is_permanence)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$driverId, $vehicleId, $shiftDate, $shiftType, $isPermanence]);
        set_flash('success', 'تم تسجيل مناوبة السائق بنجاح في الجدول التقديري');
        header('Location: /dashboard/planning.php');
        exit;
    }
}

// Handle Night Watch Entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_night_watch') {
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
        set_flash('success', 'تم تسجيل إيواء المركبة في حراسة الحظيرة الليلية (Garde de nuit)');
        header('Location: /dashboard/planning.php');
        exit;
    }
}

// Handle Exit from park
if (isset($_GET['exit_park'])) {
    $nwId = intval($_GET['exit_park']);
    $pdo->prepare("UPDATE night_watch SET status = 'sorti', exit_time = NOW() WHERE id = ?")->execute([$nwId]);
    set_flash('success', 'تم تسجيل خروج سيارة الأجرة من الحظيرة إلى الخدمة الصباحية');
    header('Location: /dashboard/planning.php');
    exit;
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

<div class="space-y-8">
    
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-black text-slate-900">Garde de Nuit & Planning des Chauffeurs</h2>
                <span class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-0.5 rounded-full">
                    مداومة الحظيرة وجدول 3 أيام
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">حراسة الأسطول الليلي وجدول مناوبات وسائقي المداومة (CHAUFFEURS PERMANENCE)</p>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('addShiftModal').classList.remove('hidden')"
                    class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                <span>➕</span>
                <span>برمجة مناوبة جديدة (Planning)</span>
            </button>
            <button onclick="document.getElementById('addNightModal').classList.remove('hidden')"
                    class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                <span>🌙</span>
                <span>إيواء مركبة بالحظيرة (Garde de Nuit)</span>
            </button>
        </div>
    </div>

    <!-- Section 1: Garde de nuit du parc -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden space-y-4 p-5">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-700 flex items-center justify-center font-bold text-sm">🌙</span>
                <div>
                    <h3 class="font-extrabold text-slate-900 text-base">Garde de nuit du parc (حراسة الحظيرة الليلية)</h3>
                    <p class="text-xs text-slate-500">السيارات المتواجدة حالياً تحت حراسة الحظيرة المعتمدة</p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                <?= count($nightWatch) ?> سيارات مأوية
            </span>
        </div>

        <?php if (!empty($nightWatch)): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php foreach ($nightWatch as $nw): ?>
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-extrabold text-slate-900 text-sm"><?= htmlspecialchars($nw['brand'] . ' ' . $nw['model']) ?></div>
                                <div class="font-num text-amber-700 font-bold text-xs"><?= htmlspecialchars($nw['plate_number']) ?> (<?= htmlspecialchars($nw['door_number']) ?>)</div>
                            </div>
                            <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 text-[10px] font-bold">داخل الحظيرة</span>
                        </div>

                        <div class="space-y-1 text-xs text-slate-600 pt-1 border-t border-slate-200">
                            <div>الموقع/الموقف: <strong class="text-slate-800"><?= htmlspecialchars($nw['park_slot']) ?></strong></div>
                            <div>الحارس المكلف: <strong class="text-slate-800"><?= htmlspecialchars($nw['guardian_name']) ?></strong></div>
                            <div>ساعة الدخول: <strong class="font-num text-slate-800"><?= htmlspecialchars($nw['entry_time']) ?></strong></div>
                            <?php if (!empty($nw['notes'])): ?>
                                <div class="text-[11px] text-slate-400 italic">ملاحظات: <?= htmlspecialchars($nw['notes']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="pt-2">
                            <a href="/dashboard/planning.php?exit_park=<?= $nw['id'] ?>" 
                               class="block w-full py-2 rounded-lg bg-white hover:bg-slate-100 border border-slate-300 text-center text-xs font-bold text-slate-700 transition">
                                تسجيل خروج للخدمة (Sortie du parc) →
                            </a>
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

    <!-- Section 2: Prochain planning des chauffeurs (3 jours) & CHAUFFEURS PERMANENCE -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center font-bold text-sm">📅</span>
                <div>
                    <h3 class="font-extrabold text-slate-900 text-base">Prochain planning des chauffeurs (3 jours)</h3>
                    <p class="text-xs text-slate-500">جدول مناوبات السائقين للأيام الثلاثة القادمة مع تحديد سائقي المداومة (CHAUFFEURS PERMANENCE)</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-50 text-amber-800 text-xs font-black border border-amber-200">
                    <span>⭐</span>
                    <span>CHAUFFEURS PERMANENCE (المداومة الرسمية)</span>
                </span>
            </div>
        </div>

        <!-- 3-Day Columns Layout -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            
            <!-- Day 0 (Aujourd'hui) -->
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <div class="font-bold text-slate-900 text-sm">اليوم (Aujourd'hui)</div>
                    <span class="font-num text-xs font-bold text-amber-700"><?= $today ?></span>
                </div>

                <div class="space-y-2.5">
                    <?php if (!empty($shiftsByDate[$today])): ?>
                        <?php foreach ($shiftsByDate[$today] as $s): ?>
                            <div class="bg-white p-3 rounded-lg border <?= $s['is_permanence'] ? 'border-amber-400 shadow-sm' : 'border-slate-200' ?> text-xs space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($s['driver_name']) ?></span>
                                    <?php if ($s['is_permanence']): ?>
                                        <span class="px-2 py-0.5 rounded bg-amber-500 text-slate-950 font-black text-[10px]">PERMANENCE</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-bold text-[10px] uppercase"><?= $s['shift_type'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-500 font-num">
                                    <?= htmlspecialchars($s['brand'] . ' ' . $s['model']) ?> • <?= htmlspecialchars($s['plate_number']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-slate-400 text-xs">لا توجد مناوبات مبرمجة لليوم</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Day 1 (Demain J+1) -->
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <div class="font-bold text-slate-900 text-sm">غداً (Demain J+1)</div>
                    <span class="font-num text-xs font-bold text-slate-500"><?= $day1 ?></span>
                </div>

                <div class="space-y-2.5">
                    <?php if (!empty($shiftsByDate[$day1])): ?>
                        <?php foreach ($shiftsByDate[$day1] as $s): ?>
                            <div class="bg-white p-3 rounded-lg border <?= $s['is_permanence'] ? 'border-amber-400 shadow-sm' : 'border-slate-200' ?> text-xs space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($s['driver_name']) ?></span>
                                    <?php if ($s['is_permanence']): ?>
                                        <span class="px-2 py-0.5 rounded bg-amber-500 text-slate-950 font-black text-[10px]">PERMANENCE</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-bold text-[10px] uppercase"><?= $s['shift_type'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-500 font-num">
                                    <?= htmlspecialchars($s['brand'] . ' ' . $s['model']) ?> • <?= htmlspecialchars($s['plate_number']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-slate-400 text-xs">لا توجد مناوبات مبرمجة لغد</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Day 2 (Après-demain J+2) -->
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <div class="font-bold text-slate-900 text-sm">بعد غد (Après-demain J+2)</div>
                    <span class="font-num text-xs font-bold text-slate-500"><?= $day2 ?></span>
                </div>

                <div class="space-y-2.5">
                    <?php if (!empty($shiftsByDate[$day2])): ?>
                        <?php foreach ($shiftsByDate[$day2] as $s): ?>
                            <div class="bg-white p-3 rounded-lg border <?= $s['is_permanence'] ? 'border-amber-400 shadow-sm' : 'border-slate-200' ?> text-xs space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900"><?= htmlspecialchars($s['driver_name']) ?></span>
                                    <?php if ($s['is_permanence']): ?>
                                        <span class="px-2 py-0.5 rounded bg-amber-500 text-slate-950 font-black text-[10px]">PERMANENCE</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-bold text-[10px] uppercase"><?= $s['shift_type'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-500 font-num">
                                    <?= htmlspecialchars($s['brand'] . ' ' . $s['model']) ?> • <?= htmlspecialchars($s['plate_number']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-slate-400 text-xs">لا توجد مناوبات مبرمجة</div>
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
            <h3 class="font-extrabold text-slate-900 text-base">برمجة مناوبة سائق (Planning Chauffeur)</h3>
            <button onclick="document.getElementById('addShiftModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 font-bold text-sm">✕</button>
        </div>

        <form method="POST" action="/dashboard/planning.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="add_shift">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">حدد السائق *</label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs bg-white">
                    <option value="">-- اختر السائق --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">حدد سيارة الأجرة *</label>
                <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs bg-white">
                    <option value="">-- اختر السيارة --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ المناوبة</label>
                    <input type="date" name="shift_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs font-num bg-white">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">فترة الدوام (Shift)</label>
                    <select name="shift_type" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs bg-white">
                        <option value="matin">Matin (صباحي 06h - 14h)</option>
                        <option value="soir">Soir (مسائي 14h - 22h)</option>
                        <option value="nuit">Nuit (ليلي 22h - 06h)</option>
                    </select>
                </div>
            </div>

            <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-center gap-3">
                <input type="checkbox" id="isPermanenceCheck" name="is_permanence" value="1" class="w-4 h-4 rounded text-amber-600 focus:ring-amber-500">
                <label for="isPermanenceCheck" class="text-xs font-bold text-amber-950">
                    تصنيف كسائق مداومة رسمية (CHAUFFEURS PERMANENCE)
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('addShiftModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">إلغاء</button>
                <button type="submit" class="px-6 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-extrabold transition shadow-sm">حفظ المناوبة ✓</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Night Watch Entry -->
<div id="addNightModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-md w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-extrabold text-slate-900 text-base">تسجيل إيواء بالحظيرة (Garde de Nuit)</h3>
            <button onclick="document.getElementById('addNightModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 font-bold text-sm">✕</button>
        </div>

        <form method="POST" action="/dashboard/planning.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="add_night_watch">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">حدد سيارة الأجرة *</label>
                <select name="vehicle_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs bg-white">
                    <option value="">-- حدد السيارة --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">الحارس المكلف</label>
                    <input type="text" name="guardian_name" value="عمي عيسى (حارس الحظيرة)" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">موقع الموقف (Slot)</label>
                    <input type="text" name="park_slot" value="Zone A-01" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات حالة السيارة (المفاتيح، العداد، إلخ)</label>
                <input type="text" name="notes" placeholder="مغلقة، عداد سليم، مفاتيح مسلمة للحراسة..." class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-xs">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('addNightModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">إلغاء</button>
                <button type="submit" class="px-6 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold transition shadow-sm">تأكيد الإيواء بالحظيرة ✓</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
