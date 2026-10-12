<?php
// dashboard/cnas.php - CNAS Social Security Declarations (Law 90-11)
$pageTitle = 'تأمينات الضمان الاجتماعي (CNAS)';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$message = null;
$messageType = null;

// Handle New CNAS Declaration or Payment Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    if (!is_admin()) {
        $message = 'عذراً، فقط إدارة المنصة مخولة بإضافة وتحديث تصريحات الضمان الاجتماعي.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $driverId = intval($_POST['driver_id'] ?? 0);
            $cnasNumber = trim($_POST['cnas_number'] ?? '');
            $affiliationDate = $_POST['affiliation_date'] ?? date('Y-m-d');
            $periodQuarter = trim($_POST['period_quarter'] ?? 'T4-2026');
            $baseSalary = floatval($_POST['base_salary'] ?? 45000.00);
            $patronale = round($baseSalary * 0.26, 2);
            $ouvriere = round($baseSalary * 0.09, 2);
            $totalCotisation = $patronale + $ouvriere;
            $paymentStatus = $_POST['payment_status'] ?? 'regle';
            $receiptRef = trim($_POST['receipt_ref'] ?? '');
            $paymentDate = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
            $attestationExpiry = !empty($_POST['attestation_expiry']) ? $_POST['attestation_expiry'] : null;
            $notes = trim($_POST['notes'] ?? '');

            if ($driverId > 0 && !empty($cnasNumber)) {
                $stmtIns = $pdo->prepare("
                    INSERT INTO cnas_declarations (driver_id, cnas_number, affiliation_date, period_quarter, base_salary, cotisation_patronale, cotisation_ouvriere, total_cotisation, payment_status, payment_date, receipt_ref, attestation_expiry, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $driverId, $cnasNumber, $affiliationDate, $periodQuarter,
                    $baseSalary, $patronale, $ouvriere, $totalCotisation,
                    $paymentStatus, $paymentDate, $receiptRef, $attestationExpiry, $notes
                ]);
                $decId = $pdo->lastInsertId();

                log_audit($pdo, 'cnas_declaration_created', 'cnas_declarations', (int)$decId, "تسجيل تصريح CNAS جديد للفترة $periodQuarter برقم $cnasNumber");
                $message = 'تم تسجيل تصريح الضمان الاجتماعي بنجاح.';
                $messageType = 'success';
            } else {
                $message = 'يرجى اختيار السائق وإدخال رقم الضمان الاجتماعي.';
                $messageType = 'error';
            }
        } elseif ($action === 'mark_paid') {
            $decId = intval($_POST['declaration_id'] ?? 0);
            $receiptRef = trim($_POST['receipt_ref'] ?? 'QUITT-CNAS-' . date('Ymd'));
            if ($decId > 0) {
                $stmtUp = $pdo->prepare("UPDATE cnas_declarations SET payment_status = 'regle', payment_date = CURDATE(), receipt_ref = ? WHERE id = ?");
                $stmtUp->execute([$receiptRef, $decId]);
                log_audit($pdo, 'cnas_marked_paid', 'cnas_declarations', $decId, "تسوية اشتراك CNAS بوصل: $receiptRef");
                $message = 'تمت تسوية اشتراك الضمان الاجتماعي وتأكيد الوصل.';
                $messageType = 'success';
            }
        }
    }
}

// Fetch Declarations with Driver Details
$declarations = [];
$driversList = [];
$totalDeclaredDrivers = 0;
$totalCotisationsPaid = 0.00;
$pendingCotisations = 0.00;

if ($pdo) {
    $stmtDec = $pdo->query("
        SELECT c.*, u.full_name as driver_name, u.phone as driver_phone, u.wilaya,
               DATEDIFF(c.attestation_expiry, CURDATE()) as days_to_expiry
        FROM cnas_declarations c
        JOIN users u ON c.driver_id = u.id
        ORDER BY c.created_at DESC
    ");
    $declarations = $stmtDec->fetchAll();

    $driversList = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();

    $totalDeclaredDrivers = (int)$pdo->query("SELECT COUNT(DISTINCT driver_id) FROM cnas_declarations")->fetchColumn();
    $totalCotisationsPaid = (float)$pdo->query("SELECT COALESCE(SUM(total_cotisation), 0) FROM cnas_declarations WHERE payment_status = 'regle'")->fetchColumn();
    $pendingCotisations = (float)$pdo->query("SELECT COALESCE(SUM(total_cotisation), 0) FROM cnas_declarations WHERE payment_status != 'regle'")->fetchColumn();
}
?>

<div class="space-y-6">

    <!-- Top Action Banner -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-black text-slate-900">تأمينات الضمان الاجتماعي للعمال الأجراء (CNAS)</h2>
            <p class="text-xs text-slate-500 mt-1">المطابقة القانونية لعلاقات العمل (قانون 90-11)، اشتراكات الضمان الاجتماعي (26% مشغل + 9% سائق)، وشهادات التحيين الدورية.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('newCnasModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-2">
                <span>➕</span>
                <span>تصريح فصلي جديد (DPS)</span>
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-xl text-xs font-bold flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
            <span><?= $messageType === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- 3 KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div class="flex items-center justify-between text-slate-400 text-xs font-bold">
                <span>السائقون المصرح بهم</span>
                <span class="text-base">👥</span>
            </div>
            <div class="text-2xl font-black text-slate-900 font-num"><?= $totalDeclaredDrivers ?> سائق</div>
            <div class="text-[10px] text-emerald-600 font-bold">تغطية تأمينية واجتماعية كاملة</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div class="flex items-center justify-between text-slate-400 text-xs font-bold">
                <span>إجمالي الاشتراكات المسددة (35%)</span>
                <span class="text-base">💼</span>
            </div>
            <div class="text-2xl font-black text-emerald-600 font-num"><?= number_format($totalCotisationsPaid, 2, ',', ' ') ?> دج</div>
            <div class="text-[10px] text-slate-400">حصة المشغل 26% + حصة العامل 9%</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div class="flex items-center justify-between text-slate-400 text-xs font-bold">
                <span>اشتراكات قيد التسوية</span>
                <span class="text-base">⏳</span>
            </div>
            <div class="text-2xl font-black text-amber-600 font-num"><?= number_format($pendingCotisations, 2, ',', ' ') ?> دج</div>
            <div class="text-[10px] text-amber-700 font-bold">استحقاقات ثلاثية تتطلب الدفع الفوري</div>
        </div>
    </div>

    <!-- Declarations Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-xs font-extrabold text-slate-800">سجل تصاريح الضمان الاجتماعي وشهادات التحيين</h3>
            <span class="text-[11px] text-slate-400">القانون 11/90 المتعلق بعلاقات العمل</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-extrabold uppercase tracking-wider">
                        <th class="p-4">السائق المصرح</th>
                        <th class="p-4">رقم الضمان الاجتماعي</th>
                        <th class="p-4">الفترة الثلاثية</th>
                        <th class="p-4">الأجر الأساسي</th>
                        <th class="p-4">الاشتراك الإجمالي (35%)</th>
                        <th class="p-4">حالة الدفع</th>
                        <th class="p-4">شهادة التحيين</th>
                        <th class="p-4 text-center">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($declarations)): ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-400">
                                لا توجد تصاريح ضمان اجتماعي مسجلة حالياً.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($declarations as $d): 
                            $isPaid = $d['payment_status'] === 'regle';
                            $days = $d['days_to_expiry'];
                        ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="p-4">
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars($d['driver_name']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($d['driver_phone']) ?></div>
                                </td>
                                <td class="p-4 font-mono font-bold text-slate-800" dir="ltr">
                                    <?= htmlspecialchars($d['cnas_number']) ?>
                                </td>
                                <td class="p-4 font-bold text-slate-700">
                                    <?= htmlspecialchars($d['period_quarter']) ?>
                                </td>
                                <td class="p-4 font-num text-slate-700 font-bold">
                                    <?= number_format($d['base_salary'], 2, ',', ' ') ?> دج
                                </td>
                                <td class="p-4">
                                    <div class="font-num font-bold text-slate-900"><?= number_format($d['total_cotisation'], 2, ',', ' ') ?> دج</div>
                                    <div class="text-[10px] text-slate-400">
                                        مشغل: <?= number_format($d['cotisation_patronale'], 0) ?> دج | عامل: <?= number_format($d['cotisation_ouvriere'], 0) ?> دج
                                    </div>
                                </td>
                                <td class="p-4">
                                    <?php if ($isPaid): ?>
                                        <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            مسدد (<?= htmlspecialchars($d['receipt_ref'] ?? 'وصل') ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200 animate-pulse">
                                            قيد الانتظار
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4">
                                    <?php if (!empty($d['attestation_expiry'])): ?>
                                        <div class="font-num text-[11px] font-bold text-slate-800"><?= htmlspecialchars($d['attestation_expiry']) ?></div>
                                        <?php if ($days !== null && $days <= 15): ?>
                                            <span class="text-[10px] font-bold text-rose-600 block">تنتهي خلال <?= $days ?> أيام!</span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-emerald-600 block">سارية المفعول (<?= $days ?> يوماً)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if (!$isPaid): ?>
                                        <form method="POST" action="" class="inline">
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="declaration_id" value="<?= $d['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[10px] transition shadow-xs">
                                                تأكيد التسديد
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-xs">✓ تم التسديد</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: New CNAS Declaration -->
<div id="newCnasModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900">تسجيل تصريح بالمرتبات للضمان الاجتماعي (CNAS)</h3>
            <button onclick="document.getElementById('newCnasModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-3 text-xs">
            <input type="hidden" name="action" value="create">

            <div>
                <label class="block font-bold text-slate-700 mb-1">السائق المصرح *</label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                    <option value="">-- اختر السائق المعتمد --</option>
                    <?php foreach ($driversList as $drv): ?>
                        <option value="<?= $drv['id'] ?>"><?= htmlspecialchars($drv['full_name']) ?> (<?= htmlspecialchars($drv['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم الضمان الاجتماعي (N° CNAS) *</label>
                    <input type="text" name="cnas_number" required placeholder="16-XXXXXXXX-XX" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-mono font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تاريخ الانتساب الأول</label>
                    <input type="date" name="affiliation_date" value="<?= date('Y-m-d') ?>"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الفترة الثلاثية *</label>
                    <select name="period_quarter" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="T4-2026 (أكتوبر - ديسمبر)">T4-2026 (أكتوبر - ديسمبر)</option>
                        <option value="T3-2026 (جويلية - سبتمبر)">T3-2026 (جويلية - سبتمبر)</option>
                        <option value="T2-2026 (أفريل - جوان)">T2-2026 (أفريل - جوان)</option>
                        <option value="T1-2026 (جانفي - مارس)">T1-2026 (جانفي - مارس)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الأجر الأساسي الشهري (دج) *</label>
                    <input type="number" step="500" name="base_salary" value="45000" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-num font-bold">
                </div>
            </div>

            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 text-[11px] text-slate-600 space-y-1">
                <div class="flex justify-between"><span>نسبة المشغل (Patronale 26%):</span><span class="font-bold font-num">11 700 دج</span></div>
                <div class="flex justify-between"><span>نسبة السائق (Ouvrière 9%):</span><span class="font-bold font-num">4 050 دج</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-1 font-bold text-slate-900">
                    <span>المجموع الإجمالي المطلوب (35%):</span><span class="font-num text-amber-600">15 750 دج</span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">حالة التسديد</label>
                    <select name="payment_status" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="regle">مسدد بالكامل (Réglé)</option>
                        <option value="en_attente">قيد الانتظار (En attente)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم وصل التسديد (Quittance)</label>
                    <input type="text" name="receipt_ref" placeholder="QUITT-CNAS-..."
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">تاريخ انتهاء صلاحية شهادة التحيين (Mise à jour)</label>
                <input type="date" name="attestation_expiry" value="<?= date('Y-m-d', strtotime('+90 days')) ?>"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500">
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newCnasModal').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition">
                    تسجيل التصريح
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
