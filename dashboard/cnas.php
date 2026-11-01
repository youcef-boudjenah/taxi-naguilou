<?php
// dashboard/cnas.php - CNAS Social Security Declarations & Official Bordereau (Law 90-11 & 83-11)
$pageTitle = 'تأمينات الضمان الاجتماعي (CNAS) | بوردورو وبيان حركات السائقين';
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
                $stmtDel = $pdo->prepare("UPDATE cnas_declarations SET deleted_at = NOW() WHERE id = ?");
                $stmtDel->execute([$decId]);
                log_audit($pdo, 'cnas_declaration_deleted', 'cnas_declarations', $decId, "نقل تصريح CNAS رقم $decId إلى سلة المهملات");
                $message = 'تم نقل تصريح الضمان الاجتماعي إلى سلة المهملات والأرشيف بنجاح. يمكنك استرجاعه في أي وقت.';
                $messageType = 'success';
            }
        }
    }
}

// Company metadata & assets
$company = get_company_meta($pdo);
$activeTab = $_GET['tab'] ?? 'declarations'; // declarations, bordereau, mouvements
$selectedQuarter = htmlspecialchars($_GET['quarter'] ?? 'T4-2026');

$slipLogo = file_exists(__DIR__ . '/../uploads/slips/slip_header_logo.png') 
    ? url('/uploads/slips/slip_header_logo.png') 
    : (file_exists(__DIR__ . '/../uploads/slips/slip_header_logo.jpg') 
        ? url('/uploads/slips/slip_header_logo.jpg') 
        : url('/logo.webp'));

// Fetch Declarations with Driver Details
$declarations = [];
$driversList = [];
$totalDeclaredDrivers = 0;
$totalCotisationsPaid = 0.00;
$pendingCotisations = 0.00;

if ($pdo) {
    $search = trim($_GET['search'] ?? '');
    try {
        $sqlDec = "
            SELECT c.*, u.full_name as driver_name, u.phone as driver_phone, u.wilaya,
                   v.door_number,
                   DATEDIFF(c.attestation_expiry, CURDATE()) as days_to_expiry
            FROM cnas_declarations c
            JOIN users u ON c.driver_id = u.id
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id)
            WHERE c.deleted_at IS NULL
        ";
        $paramsDec = [];
        if (!empty($search)) {
            $sqlDec .= " AND (u.full_name LIKE ? OR c.cnas_number LIKE ? OR c.period_quarter LIKE ?)";
            $paramsDec[] = "%$search%";
            $paramsDec[] = "%$search%";
            $paramsDec[] = "%$search%";
        }
        $sqlDec .= " ORDER BY CASE WHEN v.door_number IS NOT NULL AND v.door_number != '' THEN 0 ELSE 1 END,
                              CAST(SUBSTRING_INDEX(v.door_number, '-', -1) AS UNSIGNED) ASC,
                              v.door_number ASC,
                              c.created_at DESC";
        $stmtDec = $pdo->prepare($sqlDec);
        $stmtDec->execute($paramsDec);
        $declarations = $stmtDec ? $stmtDec->fetchAll() : [];
    } catch (\Throwable $e) {
        $declarations = [];
    }

    try {
        $stmtDrv = $pdo->query("
            SELECT u.id, u.full_name, u.phone, MAX(v.door_number) as door_number 
            FROM users u 
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id)
            WHERE u.role = 'driver' AND u.status = 'active' AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY CASE WHEN MAX(v.door_number) IS NOT NULL AND MAX(v.door_number) != '' THEN 0 ELSE 1 END,
                     CAST(SUBSTRING_INDEX(MAX(v.door_number), '-', -1) AS UNSIGNED) ASC,
                     MAX(v.door_number) ASC,
                     u.id ASC
        ");
        $driversList = $stmtDrv ? $stmtDrv->fetchAll() : [];
    } catch (\Throwable $e) {
        $driversList = [];
    }

    try {
        $totalDeclaredDrivers = (int)$pdo->query("SELECT COUNT(DISTINCT driver_id) FROM cnas_declarations WHERE deleted_at IS NULL")->fetchColumn();
    } catch (\Throwable $e) { $totalDeclaredDrivers = 0; }

    try {
        $totalCotisationsPaid = (float)$pdo->query("SELECT COALESCE(SUM(total_cotisation), 0) FROM cnas_declarations WHERE payment_status = 'regle' AND deleted_at IS NULL")->fetchColumn();
    } catch (\Throwable $e) { $totalCotisationsPaid = 0.0; }

    try {
        $pendingCotisations = (float)$pdo->query("SELECT COALESCE(SUM(total_cotisation), 0) FROM cnas_declarations WHERE payment_status != 'regle' AND deleted_at IS NULL")->fetchColumn();
    } catch (\Throwable $e) { $pendingCotisations = 0.0; }
}

// Compute Aggregates for Bordereau CNAS
$totalAssiette = 0.00;
$totalPatronale = 0.00;
$totalOuvriere = 0.00;
$totalCotisationCalc = 0.00;

if (!empty($declarations)) {
    foreach ($declarations as $d) {
        $totalAssiette += (float)$d['base_salary'];
        $totalPatronale += (float)$d['cotisation_patronale'];
        $totalOuvriere += (float)$d['cotisation_ouvriere'];
        $totalCotisationCalc += (float)$d['total_cotisation'];
    }
}
if ($totalAssiette <= 0 && $totalDeclaredDrivers > 0) {
    $totalAssiette = $totalDeclaredDrivers * 45000.00;
    $totalPatronale = round($totalAssiette * 0.26, 2);
    $totalOuvriere = round($totalAssiette * 0.09, 2);
    $totalCotisationCalc = $totalPatronale + $totalOuvriere;
}

// Fetch Nominative Driver Movements (Entrées / Sorties) for Tab 3
$mouvements = [];
if ($pdo) {
    try {
        $stmtMouv = $pdo->query("
            SELECT u.id, u.full_name, u.phone, u.wilaya, u.license_number, u.status, u.created_at,
                   c.cnas_number, c.affiliation_date, c.period_quarter,
                   ctr.contract_number, ctr.start_date, ctr.end_date, ctr.status as contract_status, ctr.termination_date
            FROM users u
            LEFT JOIN cnas_declarations c ON c.driver_id = u.id AND c.deleted_at IS NULL
            LEFT JOIN contracts ctr ON ctr.driver_id = u.id AND ctr.deleted_at IS NULL
            WHERE u.role = 'driver' AND u.deleted_at IS NULL
            ORDER BY u.created_at DESC
        ");
        $mouvements = $stmtMouv ? $stmtMouv->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {
        $mouvements = [];
    }
}

$nbEntrees = count(array_filter($mouvements, fn($m) => ($m['contract_status'] ?? '') !== 'terminated' && $m['status'] === 'active'));
$nbSorties = count(array_filter($mouvements, fn($m) => ($m['contract_status'] ?? '') === 'terminated' || $m['status'] !== 'active'));
?>

<style>
/* CNAS Official Paper Styles */
.cnas-paper {
    max-width: 210mm !important;
    min-height: 297mm !important;
    width: 100% !important;
    margin-left: auto !important;
    margin-right: auto !important;
}

@media print {
    .no-print, nav, header, aside, .sidebar, footer { display: none !important; }
    body { background: white !important; padding: 0 !important; margin: 0 !important; color: #000 !important; }
    
    .cnas-paper {
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        width: 100% !important;
        max-width: 100% !important;
        min-height: 275mm !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        background: #ffffff !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
    .cnas-paper * {
        visibility: visible !important;
        color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    @page {
        size: A4 portrait;
        margin: 10mm 15mm;
    }
}
</style>

<div class="space-y-6">

    <!-- Top Action Banner & Tab Navigation -->
    <div class="no-print bg-white dark:bg-[#161d27] p-6 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-2xl bg-sky-500/10 text-sky-600 dark:text-sky-400 flex items-center justify-center font-bold text-lg shadow-2xs">
                    🏥
                </div>
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">تأمينات الضمان الاجتماعي للعمال الأجراء (CNAS)</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">المطابقة القانونية لعلاقات العمل (قانون 90-11)، بوردورو الاشتراكات (26% مشغل + 9% عامل)، وبيان حركات السائقين</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <!-- Tab Switcher -->
            <div class="flex items-center bg-slate-100 dark:bg-slate-800 p-1 rounded-2xl text-xs font-bold">
                <a href="cnas.php?tab=declarations" 
                   class="px-3.5 py-2 rounded-xl transition <?= $activeTab === 'declarations' ? 'bg-white dark:bg-[#161d27] text-slate-900 dark:text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white' ?>">
                    📋 سجل التصاريح
                </a>
                <a href="cnas.php?tab=bordereau" 
                   class="px-3.5 py-2 rounded-xl transition <?= $activeTab === 'bordereau' ? 'bg-sky-600 text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white' ?>">
                    📑 بوردورو CNAS الرسمي
                </a>
                <a href="cnas.php?tab=mouvements" 
                   class="px-3.5 py-2 rounded-xl transition <?= $activeTab === 'mouvements' ? 'bg-white dark:bg-[#161d27] text-slate-900 dark:text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white' ?>">
                    👥 بيان الحركات
                </a>
            </div>

            <?php if ($activeTab === 'declarations'): ?>
            <button onclick="document.getElementById('newCnasModal').classList.remove('hidden')"
                    class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs flex items-center gap-1.5 cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>تصريح فصلي جديد (DPS)</span>
            </button>
            <?php else: ?>
            <button onclick="window.print()"
                    class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-xs flex items-center gap-1.5 cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                </svg>
                <span>طباعة الوثيقة (PDF)</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="no-print p-4 rounded-2xl text-xs font-bold flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/15 text-rose-800 dark:text-rose-300 border border-rose-500/30' ?>">
            <span><?= $messageType === 'success' ? '✓' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'declarations'): ?>
    <!-- ============================================================== -->
    <!-- TAB 1: DECLARATIONS & DRIVER CNAS REGISTER                     -->
    <!-- ============================================================== -->

    <!-- 3 Modern KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-[#161d27] p-5 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs space-y-1">
            <div class="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-bold">
                <span>السائقون المصرح بهم</span>
                <div class="w-8 h-8 rounded-xl bg-sky-50 dark:bg-sky-950/50 text-sky-600 dark:text-sky-400 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-black text-slate-900 dark:text-white font-num"><?= $totalDeclaredDrivers ?> سائق</div>
            <div class="text-[11px] text-emerald-600 dark:text-emerald-400 font-bold">تغطية تأمينية واجتماعية كاملة</div>
        </div>

        <div class="bg-white dark:bg-[#161d27] p-5 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs space-y-1">
            <div class="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-bold">
                <span>إجمالي الاشتراكات المسددة (35%)</span>
                <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v1.069m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-num">
                <span dir="ltr"><?= number_format($totalCotisationsPaid, 2) ?></span> دج
            </div>
            <div class="text-[11px] text-slate-400">حصة المشغل 26% + حصة العامل 9%</div>
        </div>

        <div class="bg-white dark:bg-[#161d27] p-5 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs space-y-1">
            <div class="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-bold">
                <span>اشتراكات قيد التسوية</span>
                <div class="w-8 h-8 rounded-xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-black text-amber-600 dark:text-amber-400 font-num">
                <span dir="ltr"><?= number_format($pendingCotisations, 2) ?></span> دج
            </div>
            <div class="text-[11px] text-amber-700 dark:text-amber-400 font-bold">استحقاقات ثلاثية تتطلب التسوية</div>
        </div>
    </div>

    <!-- Search Toolbar -->
    <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200 dark:border-slate-800 flex items-center justify-between gap-3 text-xs shadow-xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <input type="hidden" name="tab" value="declarations">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث باسم السائق، رقم الضمان الاجتماعي، أو الفترة الثلاثية..."
                       class="w-full pr-9 pl-8 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-slate-900 dark:bg-slate-700 hover:bg-slate-800 text-white font-bold transition cursor-pointer">بحث</button>
            <?php if (!empty($search)): ?>
                <a href="cnas.php?tab=declarations" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <a href="cnas.php?tab=bordereau" class="px-3.5 py-2.5 rounded-xl bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-300 border border-sky-200 dark:border-sky-800 font-bold flex items-center gap-1.5 hover:bg-sky-100 transition">
            <span>📑 فتح بوردورو CNAS</span>
            <span>↗</span>
        </a>
    </div>

    <!-- Declarations Table -->
    <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="text-xs font-black text-slate-900 dark:text-white">سجل تصاريح الضمان الاجتماعي وشهادات التحيين</h3>
            <span class="text-[11px] text-slate-400">القانون 11/90 المتعلق بعلاقات العمل والضمان الاجتماعي</span>
        </div>

        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80">
                    <tr>
                        <th class="p-3.5">السائق المصرح</th>
                        <th class="p-3.5">رقم الضمان الاجتماعي</th>
                        <th class="p-3.5">الفترة الثلاثية</th>
                        <th class="p-3.5">الأجر الأساسي</th>
                        <th class="p-3.5">الاشتراك الإجمالي (35%)</th>
                        <th class="p-3.5">حالة الدفع</th>
                        <th class="p-3.5">شهادة التحيين</th>
                        <th class="p-3.5 text-center">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
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
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3.5">
                                    <div class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($d['driver_name']) ?></div>
                                    <div class="mt-1" dir="ltr">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold font-num bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                            <?= htmlspecialchars($d['driver_phone']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="p-3.5 font-mono font-bold" dir="ltr">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs font-bold font-num bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-300 border border-sky-200/80 dark:border-sky-800 shadow-2xs font-mono">
                                        <span>🏥</span>
                                        <span><?= htmlspecialchars($d['cnas_number']) ?></span>
                                    </span>
                                </td>
                                <td class="p-3.5 font-bold text-slate-700 dark:text-slate-200">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                        <?= htmlspecialchars($d['period_quarter']) ?>
                                    </span>
                                </td>
                                <td class="p-3.5 font-num">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold font-num bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200">
                                        <span dir="ltr"><?= number_format($d['base_salary'], 2) ?></span>&nbsp;دج
                                    </span>
                                </td>
                                <td class="p-3.5">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-black font-num bg-sky-50 dark:bg-sky-950/40 text-sky-900 dark:text-sky-200 border border-sky-200 dark:border-sky-800">
                                        <span dir="ltr"><?= number_format($d['total_cotisation'], 2) ?></span>&nbsp;دج
                                    </span>
                                    <div class="text-[10px] text-slate-400 mt-1">
                                        مشغل: <span dir="ltr"><?= number_format($d['cotisation_patronale'], 0) ?></span> دج | عامل: <span dir="ltr"><?= number_format($d['cotisation_ouvriere'], 0) ?></span> دج
                                    </div>
                                </td>
                                <td class="p-3.5">
                                    <?php if ($isPaid): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            <span>مسدد (<?= htmlspecialchars($d['receipt_ref'] ?? 'وصل') ?>)</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800 animate-pulse">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                            <span>قيد الانتظار</span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5">
                                    <?php if (!empty($d['attestation_expiry'])): ?>
                                        <?php if ($days !== null && $days <= 15): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-rose-100 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-800 font-num" dir="ltr">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span>
                                                <span><?= htmlspecialchars($d['attestation_expiry']) ?></span>
                                            </span>
                                            <span class="text-[10px] font-bold text-rose-600 block mt-0.5">تنتهي خلال <?= $days ?> أيام!</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800 font-num" dir="ltr">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                <span><?= htmlspecialchars($d['attestation_expiry']) ?></span>
                                            </span>
                                            <span class="text-[10px] text-emerald-600 block mt-0.5">سارية المفعول (<?= $days ?> يوماً)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if (!$isPaid): ?>
                                            <form method="POST" action="" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="declaration_id" value="<?= $d['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[10px] transition shadow-xs cursor-pointer" title="تأكيد التسديد">
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
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-blue-50 dark:bg-blue-950/50 hover:bg-blue-100 text-blue-700 dark:text-blue-300 font-bold text-[11px] transition border border-blue-200/60 shadow-2xs cursor-pointer" title="تعديل التصريح">
                                            <span>تعديل</span>
                                        </button>
                                        <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا التصريح نهائياً؟')" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="declaration_id" value="<?= $d['id'] ?>">
                                            <button type="submit" class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition cursor-pointer" title="حذف">
                                                ✕
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

    <?php elseif ($activeTab === 'bordereau'): ?>
    <!-- ============================================================== -->
    <!-- TAB 2: OFFICIAL CNAS BORDEREAU (الجمهورية الجزائرية - A4)      -->
    <!-- Directly replicated from Ahmed-Rabah-Comptable-main            -->
    <!-- ============================================================== -->

    <div class="cnas-paper bg-white text-slate-950 p-8 sm:p-12 rounded-2xl border border-slate-300 shadow-xl space-y-6 text-right font-sans mx-auto transition-all text-xs leading-relaxed">
        
        <!-- Top Official Republic Header -->
        <div class="border-b-2 border-slate-900 pb-4 text-center space-y-1">
            <div class="flex items-center justify-between gap-4">
                <img src="<?= $slipLogo ?>" alt="EURL TAXI NAGUILOU" class="h-16 sm:h-20 max-w-[200px] object-contain shrink-0">
                <div class="text-center flex-1 space-y-1">
                    <h1 class="text-sm sm:text-base font-black text-slate-950">الجمهورية الجزائرية الديمقراطية الشعبية</h1>
                    <div class="text-[11px] font-bold text-slate-700" dir="ltr">RÉPUBLIQUE ALGÉRIENNE DÉMOCRATIQUE ET POPULAIRE</div>
                    <h2 class="text-xs font-black text-slate-900">وزارة العمل والتشغيل والضمان الاجتماعي</h2>
                    <h3 class="text-xs sm:text-sm font-black text-sky-800">الصندوق الوطني للتأمينات الاجتماعية للعمال الأجراء (CNAS)</h3>
                </div>
                <div class="w-24 text-center text-[11px] text-slate-700 font-num shrink-0">
                    <div class="font-bold">الفترة المصرحة</div>
                    <div class="font-black text-slate-950 text-sm"><?= htmlspecialchars($selectedQuarter) ?></div>
                </div>
            </div>

            <!-- Title Banner -->
            <div class="pt-3">
                <span class="inline-block px-6 py-2 border-2 border-slate-950 rounded-xl font-black text-sm sm:text-base text-slate-950 bg-slate-50 shadow-xs">
                    بوردورو التصريح بالاشتراكات واشتراكات الضمان الاجتماعي (BORDEREAU CNAS)
                </span>
                <div class="text-[11px] text-slate-600 font-num mt-1" dir="ltr">Bordereau de Déclaration des Cotisations de Sécurité Sociale (Part Patronale & Part Ouvrière)</div>
            </div>
        </div>

        <!-- Administrative Location & Regulatory Box -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center p-3 bg-slate-50 rounded-xl border border-slate-300 text-[11px]">
            <div class="p-1.5 border-l border-slate-200">
                <span class="block text-slate-500 font-bold mb-0.5">وكالة الولاية</span>
                <strong class="text-slate-950 font-black">وكالة ولاية أدرار (01)</strong>
            </div>
            <div class="p-1.5 border-l border-slate-200">
                <span class="block text-slate-500 font-bold mb-0.5">مركز الدفع والانتساب</span>
                <strong class="text-slate-950 font-black">مركز أدرار الرئيسي</strong>
            </div>
            <div class="p-1.5 border-l border-slate-200">
                <span class="block text-slate-500 font-bold mb-0.5">نظام الانتساب</span>
                <strong class="text-slate-950 font-black">النظام العام للعمال الأجراء (90-11)</strong>
            </div>
            <div class="p-1.5">
                <span class="block text-slate-500 font-bold mb-0.5">نسبة الاشتراك الإجمالية</span>
                <strong class="text-sky-800 font-black text-xs">35.00 %</strong>
            </div>
        </div>

        <!-- Employer Identification Box -->
        <div class="p-4 rounded-xl border border-slate-300 space-y-3 bg-slate-50/50">
            <div class="font-black text-slate-950 text-xs border-b border-slate-200 pb-1.5 flex items-center justify-between">
                <span>إطار تعيين المستخدم والمؤسسة المشغلة (ORGANISME COTISANT)</span>
                <span class="font-num text-[11px] text-slate-500">رقم الحساب: <?= htmlspecialchars($company['settings']['cnas_cotisant_num'] ?? '01485810 56') ?></span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div class="space-y-1">
                    <div><span class="text-slate-500 font-bold">اسم المؤسسة:</span> <strong class="text-slate-950 font-black"><?= htmlspecialchars($company['name']) ?></strong></div>
                    <div><span class="text-slate-500 font-bold">المقر الاجتماعي:</span> <span><?= htmlspecialchars($company['address']) ?> - ولاية أدرار</span></div>
                    <div><span class="text-slate-500 font-bold">النشاط الممارس:</span> <span>مؤسسة استغلال سيارات الأجرة ونقل الأشخاص</span></div>
                </div>
                <div class="space-y-1">
                    <div><span class="text-slate-500 font-bold">رقم السجل التجاري (RC):</span> <strong class="font-mono" dir="ltr"><?= htmlspecialchars($company['rc']) ?></strong></div>
                    <div><span class="text-slate-500 font-bold">رقم التعريف الجبائي (NIF):</span> <strong class="font-mono" dir="ltr"><?= htmlspecialchars($company['nif']) ?></strong></div>
                    <div><span class="text-slate-500 font-bold">الهاتف المعتمد:</span> <span class="font-mono" dir="ltr"><?= htmlspecialchars($company['phone']) ?></span></div>
                </div>
            </div>

            <div class="pt-2 border-t border-slate-200 flex items-center justify-between flex-wrap gap-2 text-xs">
                <div><span class="text-slate-600 font-bold">العدد الإجمالي للسائقين المصرحين:</span> <strong class="font-num font-black text-slate-950 text-sm"><?= $totalDeclaredDrivers ?> سائق</strong></div>
                <div><span class="text-slate-600 font-bold">الوعاء الخام الإجمالي الخاضع للاشتراك:</span> <strong class="font-num font-black text-slate-950 text-sm" dir="ltr"><?= number_format($totalAssiette, 2, ',', ' ') ?> DZD</strong></div>
            </div>
        </div>

        <!-- Official Decomposition Table (35% Breakdown) -->
        <div class="overflow-hidden border-2 border-slate-900 rounded-xl">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-200 border-b-2 border-slate-900 font-black text-slate-950">
                    <tr>
                        <th class="p-3 border-l border-slate-900 text-center w-14">الرمز</th>
                        <th class="p-3 border-l border-slate-900">طبيعة الاشتراك ونوع الحصة القانونية</th>
                        <th class="p-3 border-l border-slate-900 text-center w-36">الوعاء الخاضع (دج)</th>
                        <th class="p-3 border-l border-slate-900 text-center w-20">النسبة %</th>
                        <th class="p-3 text-center w-36 font-num">مبلغ الاشتراك (دج)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-300 font-medium">
                    <tr>
                        <td class="p-3 text-center font-mono font-bold border-l border-slate-300">R06</td>
                        <td class="p-3 border-l border-slate-300">
                            <strong class="font-black text-slate-950 block">حصة المستخدم المشغل (Part Patronale)</strong>
                            <span class="text-[11px] text-slate-600">التأمينات الاجتماعية، حوادث العمل والأمراض المهنية، التقاعد المباشر</span>
                        </td>
                        <td class="p-3 text-center font-mono font-bold border-l border-slate-300" dir="ltr"><?= number_format($totalAssiette, 2, ',', ' ') ?></td>
                        <td class="p-3 text-center font-mono font-black border-l border-slate-300">26.00 %</td>
                        <td class="p-3 text-center font-mono font-black text-slate-950" dir="ltr"><?= number_format($totalPatronale, 2, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td class="p-3 text-center font-mono font-bold border-l border-slate-300">R07</td>
                        <td class="p-3 border-l border-slate-300">
                            <strong class="font-black text-slate-950 block">حصة السائق الأجير (Part Ouvrière)</strong>
                            <span class="text-[11px] text-slate-600">اقتطاع الضمان الاجتماعي من الأجر القاعدي للسائق</span>
                        </td>
                        <td class="p-3 text-center font-mono font-bold border-l border-slate-300" dir="ltr"><?= number_format($totalAssiette, 2, ',', ' ') ?></td>
                        <td class="p-3 text-center font-mono font-black border-l border-slate-300">09.00 %</td>
                        <td class="p-3 text-center font-mono font-black text-slate-950" dir="ltr"><?= number_format($totalOuvriere, 2, ',', ' ') ?></td>
                    </tr>
                    <tr class="bg-slate-100 font-black border-t-2 border-slate-900 text-slate-950">
                        <td colspan="3" class="p-3 text-left font-black border-l border-slate-900 text-sm">
                            المجموع الإجمالي للاشتراكات الواجبة الدفع لدى مصالح CNAS:
                        </td>
                        <td class="p-3 text-center font-mono font-black border-l border-slate-900 text-sm">35.00 %</td>
                        <td class="p-3 text-center font-mono font-black text-sky-900 text-base" dir="ltr"><?= number_format($totalCotisationCalc, 2, ',', ' ') ?> DZD</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Official Signatures & Seal -->
        <div class="pt-8 border-t-2 border-slate-900 grid grid-cols-2 gap-12 text-center text-xs">
            <div class="space-y-12">
                <span class="font-black text-slate-950 text-sm block">تأشيرة وختم استلام مصالح صندوق CNAS</span>
                <div class="text-xs text-slate-400 font-bold italic">وصل الإيداع والاستلام الرسمي</div>
            </div>

            <div class="space-y-8">
                <span class="font-black text-slate-950 text-sm block">تأشيرة وختم مسير المؤسسة (<?= htmlspecialchars($company['name']) ?>)</span>
                <div class="w-20 h-20 rounded-full border-2 border-dashed border-sky-500 mx-auto flex items-center justify-center text-xs text-sky-800 font-black bg-sky-50/50">
                    معتمد رسمياً
                </div>
            </div>
        </div>

    </div>

    <?php elseif ($activeTab === 'mouvements'): ?>
    <!-- ============================================================== -->
    <!-- TAB 3: NOMINATIVE MOVEMENTS (بيان حركات السائقين - ENTRÉES/SORTIES)-->
    <!-- Directly replicated from Ahmed-Rabah-Comptable-main            -->
    <!-- ============================================================== -->

    <div class="space-y-5">
        <!-- Movements Summary Bar -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-[#161d27] p-5 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-0.5">سائقون في الخدمة (Entrées)</span>
                    <strong class="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-num"><?= $nbEntrees ?> سائق</strong>
                </div>
                <div class="w-10 h-10 rounded-2xl bg-emerald-50 dark:bg-emerald-950 text-emerald-600 flex items-center justify-center font-black">
                    E
                </div>
            </div>

            <div class="bg-white dark:bg-[#161d27] p-5 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-0.5">سائقون مشطوبون (Sorties)</span>
                    <strong class="text-2xl font-black text-rose-600 dark:text-rose-400 font-num"><?= $nbSorties ?> سائق</strong>
                </div>
                <div class="w-10 h-10 rounded-2xl bg-rose-50 dark:bg-rose-950 text-rose-600 flex items-center justify-center font-black">
                    S
                </div>
            </div>

            <div class="bg-white dark:bg-[#161d27] p-5 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-0.5">إجمالي الملفات المقيدة</span>
                    <strong class="text-2xl font-black text-slate-900 dark:text-white font-num"><?= count($mouvements) ?> ملف</strong>
                </div>
                <div class="w-10 h-10 rounded-2xl bg-sky-50 dark:bg-sky-950 text-sky-600 flex items-center justify-center font-black">
                    👥
                </div>
            </div>
        </div>

        <!-- Official Nominative Table Paper -->
        <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white text-sm">بيان الحركات الاسمية للسائقين (État Nominatif des Salariés - CNAS)</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">جدول تتبع حركات الدخول والخروج وشطب الانتساب وفقاً لعقود العمل 90-11</p>
                </div>
                <button onclick="window.print()" class="px-3.5 py-1.5 rounded-xl bg-slate-900 dark:bg-slate-700 text-white font-bold text-xs flex items-center gap-1.5 cursor-pointer">
                    <span>طباعة بيان الحركات</span>
                </button>
            </div>

            <div class="table-sticky-container overflow-x-auto">
                <table class="table-sticky w-full text-right text-xs">
                    <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200 dark:border-slate-700">
                        <tr>
                            <th class="p-3.5 text-center w-12 font-num">الرقم</th>
                            <th class="p-3.5">رقم الضمان الاجتماعي (NSS)</th>
                            <th class="p-3.5">اسم ولقب السائق</th>
                            <th class="p-3.5 text-center">نوع الحركة</th>
                            <th class="p-3.5 text-center font-num">تاريخ السريان</th>
                            <th class="p-3.5">سند العقد المرجعي</th>
                            <th class="p-3.5 text-center">الوضعية الحالية</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                        <?php if (empty($mouvements)): ?>
                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-400">لا توجد حركات مسجلة حالياً.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($mouvements as $idx => $m): 
                                $isTerminated = (($m['contract_status'] ?? '') === 'terminated' || $m['status'] !== 'active');
                                $mouvType = $isTerminated ? 'S' : 'E';
                                $effectDate = $isTerminated ? ($m['termination_date'] ?? $m['created_at']) : ($m['start_date'] ?? $m['affiliation_date'] ?? $m['created_at']);
                            ?>
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                    <td class="p-3.5 text-center font-num font-bold text-slate-500">
                                        <?= str_pad($idx + 1, 2, '0', STR_PAD_LEFT) ?>
                                    </td>
                                    <td class="p-3.5 font-mono font-bold" dir="ltr">
                                        <?php if (!empty($m['cnas_number'])): ?>
                                            <span class="inline-flex items-center gap-1 text-sky-800 dark:text-sky-300 bg-sky-50 dark:bg-sky-950/40 px-2 py-0.5 rounded-md font-mono">
                                                <?= htmlspecialchars($m['cnas_number']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">قيد التسجيل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5">
                                        <strong class="font-black text-slate-900 dark:text-white block"><?= htmlspecialchars($m['full_name']) ?></strong>
                                        <div class="text-[11px] text-slate-500 font-num" dir="ltr"><?= htmlspecialchars($m['phone']) ?> • ولاية أدرار</div>
                                    </td>
                                    <td class="p-3.5 text-center">
                                        <?php if ($mouvType === 'E'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                <span>✓ دخول (E)</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-black bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300">
                                                <span>✕ خروج (S)</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 text-center font-num font-bold text-slate-700 dark:text-slate-300" dir="ltr">
                                        <?= date('Y-m-d', strtotime($effectDate)) ?>
                                    </td>
                                    <td class="p-3.5">
                                        <?php if (!empty($m['contract_number'])): ?>
                                            <span class="font-mono font-bold text-slate-900 dark:text-slate-100" dir="ltr"><?= htmlspecialchars($m['contract_number']) ?></span>
                                            <div class="text-[10px] text-slate-400">عقد عمل قانون 90-11</div>
                                        <?php else: ?>
                                            <span class="text-slate-400">مسجل بدون عقد رقمي</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 text-center">
                                        <?php if ($m['status'] === 'active'): ?>
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">نشط بالأسطول</span>
                                        <?php else: ?>
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">مشطوب / سابق</span>
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
    <?php endif; ?>

</div>

<!-- Modal: تسجيل تصريح CNAS جديد -->
<div id="newCnasModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 hidden">
    <div class="bg-white dark:bg-[#161d27] rounded-3xl max-w-lg w-full border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-4 text-right text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white text-sm">تسجيل تصريح ضمان اجتماعي جديد (CNAS)</h3>
            <button type="button" onclick="document.getElementById('newCnasModal').classList.add('hidden')" class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 hover:text-rose-600 flex items-center justify-center text-slate-500 transition cursor-pointer">✕</button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">اختيار السائق المعني *</label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500">
                    <option value="">-- اختر السائق من الأسطول --</option>
                    <?php foreach ($driversList as $drv): ?>
                        <option value="<?= $drv['id'] ?>">
                            <?= htmlspecialchars($drv['full_name']) ?> <?= !empty($drv['door_number']) ? '(باب: ' . $drv['door_number'] . ')' : '' ?> - <?= htmlspecialchars($drv['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">رقم الضمان الاجتماعي (N° CNAS) *</label>
                    <input type="text" name="cnas_number" placeholder="مثال: 018458105678" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-mono font-bold focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">تاريخ الانتساب الأولي *</label>
                    <input type="date" name="affiliation_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الفترة الثلاثية المصرحة *</label>
                    <input type="text" name="period_quarter" value="T4-<?= date('Y') ?>" placeholder="مثال: T4-2026" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الأجر الأساسي الشهري (دج) *</label>
                    <input type="number" step="500" name="base_salary" value="45000" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-mono font-bold focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">حالة التسديد</label>
                    <select name="payment_status" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500">
                        <option value="regle">مسدد بالكامل (Réglé)</option>
                        <option value="en_attente">قيد الانتظار (En attente)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">رقم وصل التسديد (Quittance)</label>
                    <input type="text" name="receipt_ref" placeholder="مثال: QUITT-CNAS-8941"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-mono focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">تاريخ انتهاء صلاحية شهادة التحيين (Attestation)</label>
                <input type="date" name="attestation_expiry" value="<?= date('Y-m-d', strtotime('+3 months')) ?>"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold focus:border-amber-500">
            </div>

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">ملاحظات إدارية</label>
                <textarea name="notes" rows="2" placeholder="ملاحظات حول التصريح..."
                          class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="document.getElementById('newCnasModal').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 font-bold transition cursor-pointer">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition cursor-pointer shadow-xs">
                    حفظ التصريح
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: تعديل تصريح CNAS -->
<div id="editCnasModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 hidden">
    <div class="bg-white dark:bg-[#161d27] rounded-3xl max-w-lg w-full border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-4 text-right text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white text-sm">تعديل تصريح الضمان الاجتماعي (CNAS)</h3>
            <button type="button" onclick="closeEditCnasModal()" class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 hover:text-rose-600 flex items-center justify-center text-slate-500 transition cursor-pointer">✕</button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="declaration_id" id="editCnasId">

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">السائق المعني *</label>
                <select name="driver_id" id="editCnasDriver" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500">
                    <?php foreach ($driversList as $drv): ?>
                        <option value="<?= $drv['id'] ?>">
                            <?= htmlspecialchars($drv['full_name']) ?> - <?= htmlspecialchars($drv['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">رقم الضمان الاجتماعي (N° CNAS) *</label>
                    <input type="text" name="cnas_number" id="editCnasNumber" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-mono font-bold focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">تاريخ الانتساب الأولي *</label>
                    <input type="date" name="affiliation_date" id="editCnasAffiliationDate" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الفترة الثلاثية *</label>
                    <input type="text" name="period_quarter" id="editCnasQuarter" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الأجر الأساسي الشهري (دج) *</label>
                    <input type="number" step="500" name="base_salary" id="editCnasSalary" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-mono font-bold focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">حالة التسديد</label>
                    <select name="payment_status" id="editCnasPaymentStatus" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500">
                        <option value="regle">مسدد بالكامل (Réglé)</option>
                        <option value="en_attente">قيد الانتظار (En attente)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">رقم وصل التسديد (Quittance)</label>
                    <input type="text" name="receipt_ref" id="editCnasReceipt"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-mono focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">تاريخ انتهاء صلاحية شهادة التحيين</label>
                <input type="date" name="attestation_expiry" id="editCnasExpiry"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold focus:border-amber-500">
            </div>

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">ملاحظات إضافية</label>
                <textarea name="notes" id="editCnasNotes" rows="2"
                          class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="closeEditCnasModal()"
                        class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 font-bold transition cursor-pointer">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition cursor-pointer shadow-xs">
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
