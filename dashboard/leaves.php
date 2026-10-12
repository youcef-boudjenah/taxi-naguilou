<?php
// dashboard/leaves.php - Driver Leaves & Relief Driver Dispatch
$pageTitle = 'نظام السائق البديل والعطل';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$vehicles = $pdo->query("SELECT id, brand, model, plate_number, current_driver_id FROM vehicles ORDER BY plate_number ASC")->fetchAll();
$drivers = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// Handle New Leave & Relief Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_leave') {
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $reliefDriverId = intval($_POST['replacement_driver_id'] ?? 0);
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate = $_POST['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
    $reason = trim($_POST['reason'] ?? 'عطلة سنوية نظامية');

    if ($vehicleId > 0 && $driverId > 0) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO leaves (driver_id, vehicle_id, replacement_driver_id, start_date, end_date, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, 'approved')
            ");
            $stmt->execute([$driverId, $vehicleId, $reliefDriverId > 0 ? $reliefDriverId : null, $startDate, $endDate, $reason]);
            $leaveId = $pdo->lastInsertId();

            // Assign replacement driver to vehicle
            if ($reliefDriverId > 0) {
                $pdo->prepare("UPDATE vehicles SET replacement_driver_id = ? WHERE id = ?")->execute([$reliefDriverId, $vehicleId]);

                // Also generate transmission slip automatically
                $slipNum = 'BRD-' . date('Y') . '-' . str_pad(rand(10, 999), 4, '0', STR_PAD_LEFT);
                $stmtSlip = $pdo->prepare("
                    INSERT INTO transmission_slips (slip_number, vehicle_id, driver_id, route_line, issue_date, valid_until, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmtSlip->execute([
                    $slipNum, $vehicleId, $reliefDriverId,
                    'خط سيارة الأجرة المعتمد (سائق بديل معتمد)', $startDate, $endDate
                ]);
            }

            log_audit('create', 'leaves', $leaveId, null, [
                'driver_id' => $driverId,
                'vehicle_id' => $vehicleId,
                'replacement_driver_id' => $reliefDriverId
            ]);

            set_flash('success', 'تم اعتماد العطلة وتعيين السائق البديل واستخراج جدول الإرسال بنجاح');
            redirect('/dashboard/leaves.php');
        } catch (Exception $e) {
            set_flash('error', 'حدث خطأ أثناء تسجيل العطلة: ' . $e->getMessage());
        }
    }
}

// Fetch leaves
$leaves = $pdo->query("
    SELECT l.*, v.brand, v.model, v.plate_number, v.door_number,
           u1.full_name as main_driver, u1.phone as main_phone,
           u2.full_name as relief_driver, u2.phone as relief_phone
    FROM leaves l
    JOIN vehicles v ON l.vehicle_id = v.id
    JOIN users u1 ON l.driver_id = u1.id
    LEFT JOIN users u2 ON l.replacement_driver_id = u2.id
    ORDER BY l.id DESC
")->fetchAll();

$totalLeaves = count($leaves);
$activeReliefCount = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE replacement_driver_id IS NOT NULL AND end_date >= CURDATE()")->fetchColumn();
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

    <!-- Leaves Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
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
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 font-num text-[11px]" dir="ltr"><?= htmlspecialchars($l['plate_number']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($l['brand'] . ' ' . $l['model']) ?> (باب: <?= htmlspecialchars($l['door_number']) ?>)</div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-semibold text-slate-700"><?= htmlspecialchars($l['main_driver']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($l['main_phone']) ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($l['relief_driver'])): ?>
                                        <div class="font-bold text-emerald-700"><?= htmlspecialchars($l['relief_driver']) ?></div>
                                        <div class="text-[11px] text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($l['relief_phone']) ?></div>
                                    <?php else: ?>
                                        <span class="text-rose-600 font-semibold text-[11px]">بدون بديل (متوقفة)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-num text-slate-800" dir="ltr"><?= htmlspecialchars($l['start_date']) ?> → <?= htmlspecialchars($l['end_date']) ?></div>
                                </td>
                                <td class="p-3.5 text-slate-700 whitespace-nowrap"><?= htmlspecialchars($l['reason']) ?></td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span>خدمة مستمرة</span>
                                    </span>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <a href="slips.php" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition text-xs">
                                        جدول الإرسال
                                    </a>
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
<div id="newLeaveModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right">
        
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

        <form method="POST" action="/dashboard/leaves.php" class="space-y-4 text-xs">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
