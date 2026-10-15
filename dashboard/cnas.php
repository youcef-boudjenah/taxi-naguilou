<?php
// dashboard/cnas.php - CNAS Social Security Declarations (Law 90-11)
$pageTitle = 'تأمينات الضمان الاجتماعي (CNAS)';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin']);

$pdo = getDBConnection();
$message = null;
$messageType = null;

// Handle New CNAS Declaration or Payment Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf_token();
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
        } elseif ($action === 'edit') {
            $decId = intval($_POST['declaration_id'] ?? 0);
            $driverId = intval($_POST['driver_id'] ?? 0);
            $cnasNumber = trim($_POST['cnas_number'] ?? '');
            $affiliationDate = $_POST['affiliation_date'] ?? date('Y-m-d');
            $periodQuarter = trim($_POST['period_quarter'] ?? '');
            $baseSalary = floatval($_POST['base_salary'] ?? 45000.00);
            $patronale = round($baseSalary * 0.26, 2);
            $ouvriere = round($baseSalary * 0.09, 2);
            $totalCotisation = $patronale + $ouvriere;
            $paymentStatus = $_POST['payment_status'] ?? 'regle';
            $receiptRef = trim($_POST['receipt_ref'] ?? '');
            $paymentDate = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
            $attestationExpiry = !empty($_POST['attestation_expiry']) ? $_POST['attestation_expiry'] : null;
            $notes = trim($_POST['notes'] ?? '');

            if ($decId > 0 && $driverId > 0 && !empty($cnasNumber)) {
                $stmtUpd = $pdo->prepare("
                    UPDATE cnas_declarations 
                    SET driver_id = ?, cnas_number = ?, affiliation_date = ?, period_quarter = ?,
                        base_salary = ?, cotisation_patronale = ?, cotisation_ouvriere = ?, total_cotisation = ?,
                        payment_status = ?, payment_date = ?, receipt_ref = ?, attestation_expiry = ?, notes = ?
                    WHERE id = ?
                ");
                $stmtUpd->execute([
                    $driverId, $cnasNumber, $affiliationDate, $periodQuarter,
                    $baseSalary, $patronale, $ouvriere, $totalCotisation,
                    $paymentStatus, $paymentDate, $receiptRef, $attestationExpiry, $notes,
                    $decId
                ]);
                log_audit($pdo, 'cnas_declaration_updated', 'cnas_declarations', $decId, "تعديل تصريح CNAS رقم $decId");
                $message = 'تم تعديل تصريح الضمان الاجتماعي بنجاح.';
                $messageType = 'success';
            } else {
                $message = 'يرجى ملء جميع الحقول الإلزامية للتعديل.';
                $messageType = 'error';
            }
        } elseif ($action === 'delete') {
            $decId = intval($_POST['declaration_id'] ?? 0);
            if ($decId > 0) {
                $stmtDel = $pdo->prepare("DELETE FROM cnas_declarations WHERE id = ?");
                $stmtDel->execute([$decId]);
                log_audit($pdo, 'cnas_declaration_deleted', 'cnas_declarations', $decId, "حذف تصريح CNAS رقم $decId");
                $message = 'تم حذف تصريح الضمان الاجتماعي بنجاح.';
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
    try {
        $stmtDec = $pdo->query("
            SELECT c.*, u.full_name as driver_name, u.phone as driver_phone, u.wilaya,
                   DATEDIFF(c.attestation_expiry, CURDATE()) as days_to_expiry
            FROM cnas_declarations c
            JOIN users u ON c.driver_id = u.id
            ORDER BY c.created_at DESC
        ");
        $declarations = $stmtDec ? $stmtDec->fetchAll() : [];
    } catch (\Throwable $e) {
        $declarations = [];
    }

    try {
        $stmtDrv = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC");
        $driversList = $stmtDrv ? $stmtDrv->fetchAll() : [];
    } catch (\Throwable $e) {
        $driversList = [];
    }

    try {
        $totalDeclaredDrivers = (int)$pdo->query("SELECT COUNT(DISTINCT driver_id) FROM cnas_declarations")->fetchColumn();
    } catch (\Throwable $e) { $totalDeclaredDrivers = 0; }

    try {
        $totalCotisationsPaid = (float)$pdo->query("SELECT COALESCE(SUM(total_cotisation), 0) FROM cnas_declarations WHERE payment_status = 'regle'")->fetchColumn();
    } catch (\Throwable $e) { $totalCotisationsPaid = 0.0; }

    try {
        $pendingCotisations = (float)$pdo->query("SELECT COALESCE(SUM(total_cotisation), 0) FROM cnas_declarations WHERE payment_status != 'regle'")->fetchColumn();
    } catch (\Throwable $e) { $pendingCotisations = 0.0; }
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
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>تصريح فصلي جديد (DPS)</span>
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-xl text-xs font-bold flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
            <span><?= $messageType === 'success' ? '✓' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- 3 KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div class="flex items-center justify-between text-slate-500 text-xs font-bold">
                <span>السائقون المصرح بهم</span>
                <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-slate-900 font-num"><?= $totalDeclaredDrivers ?> سائق</div>
            <div class="text-[10px] text-emerald-600 font-bold">تغطية تأمينية واجتماعية كاملة</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div class="flex items-center justify-between text-slate-500 text-xs font-bold">
                <span>إجمالي الاشتراكات المسددة (35%)</span>
                <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v1.069m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-emerald-600 font-num"><?= number_format($totalCotisationsPaid, 2, ',', ' ') ?> دج</div>
            <div class="text-[10px] text-slate-400">حصة المشغل 26% + حصة العامل 9%</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-1">
            <div class="flex items-center justify-between text-slate-500 text-xs font-bold">
                <span>اشتراكات قيد التسوية</span>
                <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-amber-600 font-num"><?= number_format($pendingCotisations, 2, ',', ' ') ?> دج</div>
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
                                <td class="p-4 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if (!$isPaid): ?>
                                            <form method="POST" action="" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="declaration_id" value="<?= $d['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[10px] transition shadow-xs" title="تأكيد التسديد">
                                                    تأكيد
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button"
                                                onclick='openEditCnasModal(<?= json_encode([
                                                    "id" => (int)$d["id"],
                                                    "driver_id" => (int)$d["driver_id"],
                                                    "cnas_number" => $d["cnas_number"],
                                                    "affiliation_date" => $d["affiliation_date"],
                                                    "period_quarter" => $d["period_quarter"],
                                                    "base_salary" => (float)$d["base_salary"],
                                                    "payment_status" => $d["payment_status"],
                                                    "payment_date" => $d["payment_date"] ?? "",
                                                    "receipt_ref" => $d["receipt_ref"] ?? "",
                                                    "attestation_expiry" => $d["attestation_expiry"] ?? "",
                                                    "notes" => $d["notes"] ?? ""
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-[11px] transition border border-blue-200/60 shadow-2xs" title="تعديل التصريح">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 015.25 6H10" />
                                            </svg>
                                            <span class="hidden sm:inline">تعديل</span>
                                        </button>
                                        <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا التصريح نهائياً؟')" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="declaration_id" value="<?= $d['id'] ?>">
                                            <button type="submit" 
                                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-[11px] transition border border-rose-200/60 shadow-2xs" title="حذف التصريح">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                </svg>
                                            </button>
                                        </form>
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

<!-- Modal: New CNAS Declaration -->
<div id="newCnasModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900">تسجيل تصريح بالمرتبات للضمان الاجتماعي (CNAS)</h3>
            <button onclick="document.getElementById('newCnasModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-3 text-xs">
            <?= csrf_field() ?>
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

<!-- Modal: Edit CNAS Declaration -->
<div id="editCnasModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900">تعديل تصريح الضمان الاجتماعي (CNAS)</h3>
            <button onclick="closeEditCnasModal()" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-3 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="declaration_id" id="editCnasId">

            <div>
                <label class="block font-bold text-slate-700 mb-1">السائق المصرح *</label>
                <select name="driver_id" id="editCnasDriver" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                    <?php foreach ($driversList as $drv): ?>
                        <option value="<?= $drv['id'] ?>"><?= htmlspecialchars($drv['full_name']) ?> (<?= htmlspecialchars($drv['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم الضمان الاجتماعي (N° CNAS) *</label>
                    <input type="text" name="cnas_number" id="editCnasNumber" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-mono font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تاريخ الانتساب</label>
                    <input type="date" name="affiliation_date" id="editCnasAffiliationDate"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الفترة الثلاثية *</label>
                    <input type="text" name="period_quarter" id="editCnasQuarter" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الأجر الأساسي الشهري (دج) *</label>
                    <input type="number" step="500" name="base_salary" id="editCnasSalary" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-num font-bold">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">حالة التسديد</label>
                    <select name="payment_status" id="editCnasPaymentStatus" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="regle">مسدد بالكامل (Réglé)</option>
                        <option value="en_attente">قيد الانتظار (En attente)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم وصل التسديد (Quittance)</label>
                    <input type="text" name="receipt_ref" id="editCnasReceipt"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">تاريخ انتهاء صلاحية شهادة التحيين</label>
                <input type="date" name="attestation_expiry" id="editCnasExpiry"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">ملاحظات إضافية</label>
                <textarea name="notes" id="editCnasNotes" rows="2"
                          class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditCnasModal()"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditCnasModal(data) {
    document.getElementById('editCnasId').value = data.id || '';
    document.getElementById('editCnasDriver').value = data.driver_id || '';
    document.getElementById('editCnasNumber').value = data.cnas_number || '';
    document.getElementById('editCnasAffiliationDate').value = data.affiliation_date || '';
    document.getElementById('editCnasQuarter').value = data.period_quarter || '';
    document.getElementById('editCnasSalary').value = data.base_salary || 45000;
    document.getElementById('editCnasPaymentStatus').value = data.payment_status || 'regle';
    document.getElementById('editCnasReceipt').value = data.receipt_ref || '';
    document.getElementById('editCnasExpiry').value = data.attestation_expiry || '';
    document.getElementById('editCnasNotes').value = data.notes || '';

    const modal = document.getElementById('editCnasModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditCnasModal() {
    const modal = document.getElementById('editCnasModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
