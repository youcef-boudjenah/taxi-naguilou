<?php
// dashboard/billing.php - Abonnements & Facturation: فضاء السائق واشتراك المركبات
$pageTitle = 'Abonnements & Facturation | الاشتراكات والفوترة';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

// System subscription fees from settings
$feeSpaceMonthly = (float)get_setting($pdo, 'fee_space_monthly', '1000.00');
$feeVehicleMonthly = (float)get_setting($pdo, 'fee_vehicle_monthly', '5000.00');

// Selected Period
$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');
$selectedPeriod = sprintf('%04d-%02d', $selectedYear, $selectedMonth);

// Month names in French & Arabic
$monthNames = [
    '01' => '01 - Janvier (جانفي)',
    '02' => '02 - Février (فيفري)',
    '03' => '03 - Mars (مارس)',
    '04' => '04 - Avril (أفريل)',
    '05' => '05 - Mai (ماي)',
    '06' => '06 - Juin (جوان)',
    '07' => '07 - Juillet (جويلية)',
    '08' => '08 - Août (أوت)',
    '09' => '09 - Septembre (سبتمبر)',
    '10' => '10 - Octobre (أكتوبر)',
    '11' => '11 - Novembre (نوفمبر)',
    '12' => '12 - Décembre (ديسمبر)'
];
$currentMonthNameFr = strtolower(substr($monthNames[$selectedMonth] ?? 'mois', 5, 8));

// Handle Export Journal (Excel / CSV)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    while (ob_get_level()) { ob_end_clean(); }

    $filename = "Journal_Abonnements_{$selectedYear}_{$selectedMonth}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'رقم', 'السائق', 'الهاتف', 'حساب الفضاء', 'المركبات التي يقودها',
        'اشتراك الفضاء (دج)', 'cotis. المركبات (دج)', 'المبلغ المستحق (دج)', 'المبلغ المدفوع (دج)', 'الحالة', 'الفترة'
    ], ';');

    $stmtExp = $pdo->prepare("
        SELECT 
            u.id as driver_id, u.full_name as driver_name, u.phone as driver_phone, u.status as user_status,
            s.space_fee, s.vehicle_fee, s.total_due, s.paid_amount, s.status as payment_status,
            COALESCE(v_agg.vehicles_count, 0) as assigned_vehicles_count,
            v_agg.vehicles_data
        FROM users u
        LEFT JOIN (
            SELECT 
                v_driver_id,
                COUNT(*) as vehicles_count,
                GROUP_CONCAT(CONCAT(COALESCE(brand, ''), ' ', COALESCE(model, ''), ' (', COALESCE(plate_number, ''), ') [باب: ', COALESCE(door_number, '-'), ']') SEPARATOR ' | ') as vehicles_data
            FROM (
                SELECT id, brand, model, plate_number, door_number, current_driver_id as v_driver_id FROM vehicles WHERE current_driver_id IS NOT NULL
                UNION ALL
                SELECT id, brand, model, plate_number, door_number, replacement_driver_id as v_driver_id FROM vehicles WHERE replacement_driver_id IS NOT NULL
            ) v_all
            GROUP BY v_driver_id
        ) v_agg ON v_agg.v_driver_id = u.id
        LEFT JOIN driver_subscriptions s ON (s.driver_id = u.id AND s.period_month = ?)
        WHERE u.role = 'driver'
        ORDER BY u.id ASC
    ");
    $stmtExp->execute([$selectedPeriod]);
    $expRows = $stmtExp->fetchAll();

    $i = 1;
    foreach ($expRows as $row) {
        $vCount = (int)$row['assigned_vehicles_count'];
        $spaceFee = $row['space_fee'] !== null ? (float)$row['space_fee'] : $feeSpaceMonthly;
        $vehicleFee = $row['vehicle_fee'] !== null ? (float)$row['vehicle_fee'] : ($vCount * $feeVehicleMonthly);
        $totalDue = $row['total_due'] !== null ? (float)$row['total_due'] : ($spaceFee + $vehicleFee);
        $paidAmount = (float)($row['paid_amount'] ?? 0);
        $st = $row['payment_status'] ?? ($paidAmount >= $totalDue ? 'regle' : 'en_attente');

        $statusText = $st === 'regle' ? 'مُسدد (Réglé)' : ($st === 'partiel' ? 'تسوية جزئية' : 'معلق (En attente)');
        $spaceAccountText = $row['user_status'] === 'active' ? 'مفعل' : 'موقوف';
        $vehText = !empty($row['vehicles_data']) ? $row['vehicles_data'] : 'بدون مركبة';

        fputcsv($output, [
            $i++,
            $row['driver_name'],
            $row['driver_phone'],
            $spaceAccountText,
            $vehText,
            number_format($spaceFee, 2, ',', ' '),
            number_format($vehicleFee, 2, ',', ' '),
            number_format($totalDue, 2, ',', ' '),
            number_format($paidAmount, 2, ',', ' '),
            $statusText,
            $selectedPeriod
        ], ';');
    }
    fclose($output);
    exit;
}

// Handle Manual Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_payment') {
    require_csrf_token();
    $driverId = intval($_POST['driver_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'baridimob';
    $paymentDate = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $notes = trim($_POST['notes'] ?? 'تسوية يدوية لاشتراك الشهر');

    if ($driverId > 0 && $amount > 0) {
        // Count driver's vehicles
        $stmtVCount = $pdo->prepare("
            SELECT COUNT(DISTINCT id) FROM vehicles 
            WHERE current_driver_id = ? OR replacement_driver_id = ?
        ");
        $stmtVCount->execute([$driverId, $driverId]);
        $driverVehCount = (int)$stmtVCount->fetchColumn();

        $calcSpaceFee = $feeSpaceMonthly;
        $calcVehicleFee = $driverVehCount * $feeVehicleMonthly;
        $calcTotalDue = $calcSpaceFee + $calcVehicleFee;

        $checkStmt = $pdo->prepare("SELECT id, total_due, paid_amount FROM driver_subscriptions WHERE driver_id = ? AND period_month = ?");
        $checkStmt->execute([$driverId, $selectedPeriod]);
        $sub = $checkStmt->fetch();

        if ($sub) {
            $accumulatedPaid = (float)$sub['paid_amount'] + $amount;
            $newStatus = ($accumulatedPaid >= (float)$sub['total_due']) ? 'regle' : 'partiel';
            $updStmt = $pdo->prepare("
                UPDATE driver_subscriptions 
                SET paid_amount = ?, status = ?, payment_date = ?, payment_method = ?, notes = ?
                WHERE id = ?
            ");
            $updStmt->execute([$accumulatedPaid, $newStatus, $paymentDate, $method, $notes, $sub['id']]);
        } else {
            $newStatus = ($amount >= $calcTotalDue) ? 'regle' : 'partiel';
            $insStmt = $pdo->prepare("
                INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status, payment_date, payment_method, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insStmt->execute([$driverId, $selectedPeriod, $calcSpaceFee, $calcVehicleFee, $driverVehCount, $calcTotalDue, $amount, $newStatus, $paymentDate, $method, $notes]);
        }

        log_audit($pdo, 'subscription_payment', 'driver_subscriptions', $driverId, "تسجيل دفعة اشتراك شهري للسائق $driverId بمبلغ $amount دج عبر $method للفترة $selectedPeriod");
        set_flash('success', "تم تسجيل الدفعة بنجاح بقيمة " . number_format($amount, 2) . " دج للسائق");
        redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
    }
}

// Handle Generate Subscriptions for all active drivers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_debts') {
    require_csrf_token();
    
    // Fetch all active drivers with their assigned vehicle counts
    $stmtDrivers = $pdo->query("
        SELECT u.id, COUNT(DISTINCT v.id) as veh_count
        FROM users u
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
        WHERE u.role = 'driver' AND u.status = 'active'
        GROUP BY u.id
    ");
    $activeDrivers = $stmtDrivers->fetchAll();

    $checkExisting = $pdo->prepare("SELECT id, paid_amount FROM driver_subscriptions WHERE driver_id = ? AND period_month = ?");
    $updateSub = $pdo->prepare("
        UPDATE driver_subscriptions 
        SET space_fee = ?, vehicle_fee = ?, vehicles_count = ?, total_due = ?,
            status = CASE WHEN paid_amount >= ? THEN 'regle' WHEN paid_amount > 0 THEN 'partiel' ELSE 'en_attente' END
        WHERE id = ?
    ");
    $insertSub = $pdo->prepare("
        INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status)
        VALUES (?, ?, ?, ?, ?, ?, 0.00, 'en_attente')
    ");

    $countGenerated = 0;
    foreach ($activeDrivers as $d) {
        $dId = (int)$d['id'];
        $vCount = (int)$d['veh_count'];
        $sFee = $feeSpaceMonthly;
        $vFee = $vCount * $feeVehicleMonthly;
        $tot = $sFee + $vFee;

        $checkExisting->execute([$dId, $selectedPeriod]);
        $existing = $checkExisting->fetch();

        if ($existing) {
            $updateSub->execute([$sFee, $vFee, $vCount, $tot, $tot, $existing['id']]);
        } else {
            $insertSub->execute([$dId, $selectedPeriod, $sFee, $vFee, $vCount, $tot]);
        }
        $countGenerated++;
    }

    log_audit($pdo, 'generate_monthly_subscriptions', 'driver_subscriptions', null, "توليد وتحديث استحقاقات الاشتراكات لشهر $selectedPeriod لـ $countGenerated سائق");
    set_flash('success', "تم توليد وتحديث استحقاقات الاشتراكات لشهر {$monthNames[$selectedMonth]} لـ {$countGenerated} سائق نشط بنجاح.");
    redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

// Main Table Query (ONLY_FULL_GROUP_BY safe)
$sql = "
    SELECT 
        u.id as driver_id, 
        u.full_name as driver_name, 
        u.phone as driver_phone, 
        u.status as user_status, 
        u.email as driver_email, 
        u.license_number,
        s.id as subscription_id, 
        s.space_fee, 
        s.vehicle_fee, 
        s.vehicles_count as sub_vehicles_count, 
        s.total_due, 
        s.paid_amount, 
        s.status as payment_status, 
        s.payment_date, 
        s.payment_method, 
        s.notes as payment_notes,
        COALESCE(v_agg.vehicles_count, 0) as assigned_vehicles_count,
        v_agg.vehicles_data
    FROM users u
    LEFT JOIN (
        SELECT 
            v_driver_id,
            COUNT(*) as vehicles_count,
            GROUP_CONCAT(CONCAT(COALESCE(brand, ''), ' ', COALESCE(model, ''), '|||', COALESCE(plate_number, ''), '|||', COALESCE(door_number, '')) SEPARATOR '###') as vehicles_data
        FROM (
            SELECT id, brand, model, plate_number, door_number, current_driver_id as v_driver_id FROM vehicles WHERE current_driver_id IS NOT NULL
            UNION ALL
            SELECT id, brand, model, plate_number, door_number, replacement_driver_id as v_driver_id FROM vehicles WHERE replacement_driver_id IS NOT NULL
        ) v_all
        GROUP BY v_driver_id
    ) v_agg ON v_agg.v_driver_id = u.id
    LEFT JOIN driver_subscriptions s ON (s.driver_id = u.id AND s.period_month = ?)
    WHERE u.role = 'driver'
";
$params = [$selectedPeriod];

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR v_agg.vehicles_data LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter === 'regle') {
    $sql .= " AND s.status = 'regle'";
} elseif ($statusFilter === 'partiel') {
    $sql .= " AND s.status = 'partiel'";
} elseif ($statusFilter === 'en_attente') {
    $sql .= " AND (s.status = 'en_attente' OR s.status IS NULL)";
}

$sql .= " ORDER BY u.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Accurate Dynamic KPI Figures
$stmtPaid = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM driver_subscriptions WHERE period_month = ?");
$stmtPaid->execute([$selectedPeriod]);
$kpiRevenusPercus = (float)$stmtPaid->fetchColumn();

$stmtDue = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0, total_due - paid_amount)), 0) FROM driver_subscriptions WHERE period_month = ?");
$stmtDue->execute([$selectedPeriod]);
$kpiRevenusAttente = (float)$stmtDue->fetchColumn();

// If no subscriptions generated yet for this period, estimate pending from active drivers
if ($kpiRevenusAttente == 0 && $kpiRevenusPercus == 0) {
    $stmtEst = $pdo->query("
        SELECT COUNT(DISTINCT u.id) as drivers_count, COUNT(DISTINCT v.id) as veh_count
        FROM users u
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
        WHERE u.role = 'driver' AND u.status = 'active'
    ");
    $est = $stmtEst->fetch();
    if ($est && $est['drivers_count'] > 0) {
        $kpiRevenusAttente = ($est['drivers_count'] * $feeSpaceMonthly) + ($est['veh_count'] * $feeVehicleMonthly);
    }
}

// Total vehicles and settled vehicles
$totalVehicles = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status != 'out_of_service'")->fetchColumn();
$stmtPaidVeh = $pdo->prepare("
    SELECT COUNT(DISTINCT v.id)
    FROM vehicles v
    JOIN driver_subscriptions s ON (s.driver_id = v.current_driver_id OR s.driver_id = v.replacement_driver_id)
    WHERE s.period_month = ? AND s.status = 'regle'
");
$stmtPaidVeh->execute([$selectedPeriod]);
$paidVehiclesCount = (int)$stmtPaidVeh->fetchColumn();

$kpiVehiculesReglesCount = $paidVehiclesCount;
$kpiVehiculesTotalCount = max(1, $totalVehicles);
$kpiVehiculesRegles = "{$paidVehiclesCount} / {$totalVehicles}";
$kpiCollectionRate = $kpiVehiculesTotalCount > 0 ? round(($kpiVehiculesReglesCount / $kpiVehiculesTotalCount) * 100, 1) : 0;

// Fetch all drivers for manual payment dropdown
$allDriversList = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' ORDER BY full_name ASC")->fetchAll();
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Abonnements & Facturation</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    الاشتراكات والفوترة الشهرية
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">
                متابعة اشتراكات السائقين: <span class="font-bold text-slate-700 font-num"><?= number_format($feeSpaceMonthly, 0, ',', ' ') ?> دج/شهر</span> لحساب السائق + <span class="font-bold text-slate-700 font-num"><?= number_format($feeVehicleMonthly, 0, ',', ' ') ?> دج/شهر</span> لكل مركبة نشطة
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('manualPaymentModal').classList.remove('hidden'); document.getElementById('manualPaymentModal').classList.add('flex');"
                    class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                </svg>
                <span>تسجيل دفعة يدوية</span>
            </button>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_debts">
                <button type="submit" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                    <span>توليد استحقاقات الشهر</span>
                </button>
            </form>
        </div>
    </div>

    <!-- 3 KPI Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        
        <!-- Revenus perçus -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">الإيرادات المحصلة (<?= $currentMonthNameFr ?>)</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-emerald-600 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($kpiRevenusPercus, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-emerald-700 font-bold">● اشتراكات مُسددة:</span>
                <span>تم تأكيد الدفع عبر CCP أو بريدي موب أو نقداً</span>
            </div>
        </div>

        <!-- Revenus en attente -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">مستحقات معلقة (<?= $currentMonthNameFr ?>)</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-amber-600 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($kpiRevenusAttente, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-amber-700 font-bold">● مستحقات غير مسددة:</span>
                <span>قيد التذكير والإشعار قبل نهاية الشهر</span>
            </div>
        </div>

        <!-- Véhicules réglés -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">المركبات المُسددة (<?= $currentMonthNameFr ?>)</span>
                <span class="w-8 h-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75a3 3 0 013 3v3.75m-6-6.75H6.75a2.25 2.25 0 00-2.25 2.25v6.75" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-slate-900 font-num">
                <?= $kpiVehiculesRegles ?>
            </div>
            
            <div class="space-y-1 pt-1 border-t border-slate-100">
                <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                    <div class="bg-emerald-500 h-1.5 rounded-full" style="width: <?= $kpiCollectionRate ?>%"></div>
                </div>
                <div class="flex justify-between text-[10px] text-slate-400 font-semibold font-num">
                    <span>نسبة التحصيل: <?= $kpiCollectionRate ?>%</span>
                    <span>الإجمالي: <?= $totalVehicles ?> سيارة</span>
                </div>
            </div>
        </div>

    </div>

    <!-- Period Selector, Search & Export Toolbar -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col lg:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="w-full lg:max-w-xs flex items-center gap-2">
            <input type="hidden" name="month" value="<?= htmlspecialchars($selectedMonth) ?>">
            <input type="hidden" name="year" value="<?= htmlspecialchars($selectedYear) ?>">
            <?php if ($statusFilter !== 'all'): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <?php endif; ?>
            <div class="relative flex-1">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث بالسائق، الهاتف، اللوحة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
        </form>

        <!-- Status Filter Tabs -->
        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl">
            <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&status=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
               class="px-3 py-1.5 rounded-lg font-bold text-xs transition <?= $statusFilter === 'all' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900' ?>">
                الكل
            </a>
            <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&status=regle<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
               class="px-3 py-1.5 rounded-lg font-bold text-xs transition <?= $statusFilter === 'regle' ? 'bg-white text-emerald-700 shadow-2xs' : 'text-slate-600 hover:text-emerald-700' ?>">
                مُسدّد
            </a>
            <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&status=en_attente<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
               class="px-3 py-1.5 rounded-lg font-bold text-xs transition <?= $statusFilter === 'en_attente' ? 'bg-white text-rose-700 shadow-2xs' : 'text-slate-600 hover:text-rose-700' ?>">
                معلق
            </a>
        </div>

        <!-- Period Selector & Export -->
        <div class="flex items-center gap-2 w-full lg:w-auto justify-end">
            <span class="text-slate-500 font-bold whitespace-nowrap hidden sm:inline">الفترة:</span>
            
            <form method="GET" class="flex items-center gap-2">
                <?php if (!empty($search)): ?>
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <?php endif; ?>
                <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <?php endif; ?>
                
                <select name="month" onchange="this.form.submit()"
                        class="px-3 py-2 rounded-lg border border-slate-200 bg-white font-bold text-slate-800 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <?php foreach ($monthNames as $mKey => $mLabel): ?>
                        <option value="<?= $mKey ?>" <?= $selectedMonth === $mKey ? 'selected' : '' ?>>
                            <?= $mLabel ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="year" onchange="this.form.submit()"
                        class="px-3 py-2 rounded-lg border border-slate-200 bg-white font-num font-bold text-slate-800 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <option value="2026" <?= $selectedYear === '2026' ? 'selected' : '' ?>>2026</option>
                    <option value="2027" <?= $selectedYear === '2027' ? 'selected' : '' ?>>2027</option>
                </select>
            </form>

            <a href="billing.php?export=excel&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?>" 
               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-emerald-300 hover:border-emerald-400 bg-emerald-50 hover:bg-emerald-100 text-emerald-900 font-bold text-xs transition shadow-2xs whitespace-nowrap">
                <svg class="w-4 h-4 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                <span>Excel</span>
            </a>
        </div>

    </div>

    <!-- Subscriptions Table: Exact 8 Columns Requested -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-700 font-bold border-b border-slate-200/90 whitespace-nowrap">
                    <tr>
                        <th class="p-3.5">السائق</th>
                        <th class="p-3.5 text-center">حساب الفضاء</th>
                        <th class="p-3.5">المركبات التي يقودها</th>
                        <th class="p-3.5 text-center font-num">اشتراك الفضاء</th>
                        <th class="p-3.5 text-center font-num">cotis. المركبات</th>
                        <th class="p-3.5 text-center font-num">المبلغ المستحق</th>
                        <th class="p-3.5 text-center">الحالة</th>
                        <th class="p-3.5 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $r): 
                            $vCount = (int)$r['assigned_vehicles_count'];
                            $sFee = $r['space_fee'] !== null ? (float)$r['space_fee'] : $feeSpaceMonthly;
                            $vFee = $r['vehicle_fee'] !== null ? (float)$r['vehicle_fee'] : ($vCount * $feeVehicleMonthly);
                            $tot = $r['total_due'] !== null ? (float)$r['total_due'] : ($sFee + $vFee);
                            $paidAmount = (float)($r['paid_amount'] ?? 0);
                            $remaining = max(0, $tot - $paidAmount);
                            
                            $status = $r['payment_status'] ?? ($paidAmount >= $tot ? 'regle' : ($paidAmount > 0 ? 'partiel' : 'en_attente'));
                            $isPaid = ($status === 'regle');
                        ?>
                            <tr class="hover:bg-slate-50/60 transition <?= $isPaid ? 'bg-emerald-50/15' : '' ?>">
                                
                                <!-- 1. السائق -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 text-[13px]"><?= htmlspecialchars($r['driver_name']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-num" dir="ltr">
                                        <?= htmlspecialchars($r['driver_phone']) ?>
                                    </div>
                                    <?php if (!empty($r['license_number'])): ?>
                                        <div class="text-[10px] text-slate-400 font-num">رخصة: <?= htmlspecialchars($r['license_number']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- 2. حساب الفضاء -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($r['user_status'] === 'active'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            <span>مفعل</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600 border border-slate-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                            <span>موقوف</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 3. المركبات التي يقودها -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($r['vehicles_data'])): 
                                        $vList = explode('###', $r['vehicles_data']);
                                    ?>
                                        <div class="flex flex-col gap-1.5">
                                            <?php foreach ($vList as $vItem): 
                                                $vParts = explode('|||', $vItem);
                                                $vModel = $vParts[0] ?? '';
                                                $vPlate = $vParts[1] ?? '';
                                                $vDoor = $vParts[2] ?? '';
                                            ?>
                                                <div class="inline-flex items-center gap-1.5 bg-slate-50 border border-slate-200/80 px-2 py-1 rounded-lg">
                                                    <span class="font-bold text-slate-900 font-num text-[11px]" dir="ltr"><?= htmlspecialchars($vPlate) ?></span>
                                                    <?php if (!empty($vDoor)): ?>
                                                        <span class="text-amber-800 font-bold text-[10px] bg-amber-100/70 px-1.5 py-0.2 rounded font-num">باب <?= htmlspecialchars($vDoor) ?></span>
                                                    <?php endif; ?>
                                                    <span class="text-slate-400 text-[10px]"><?= htmlspecialchars($vModel) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] text-slate-400 bg-slate-50 border border-slate-200/60">بدون مركبة حالياً</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. اشتراك الفضاء -->
                                <td class="p-3.5 whitespace-nowrap text-center font-num font-semibold text-slate-700">
                                    <span class="inline-block" dir="ltr"><?= number_format($sFee, 2, ',', ' ') ?> DZD</span>
                                </td>

                                <!-- 5. cotis. المركبات -->
                                <td class="p-3.5 whitespace-nowrap text-center font-num font-semibold text-slate-700">
                                    <span class="inline-block font-bold text-slate-800" dir="ltr"><?= number_format($vFee, 2, ',', ' ') ?> DZD</span>
                                    <div class="text-[10px] text-slate-400 font-sans">
                                        (<?= $vCount ?> <?= $vCount > 1 ? 'مركبات' : ($vCount === 1 ? 'مركبة' : 'بدون مركبة') ?>)
                                    </div>
                                </td>

                                <!-- 6. المبلغ المستحق -->
                                <td class="p-3.5 whitespace-nowrap text-center font-num">
                                    <div class="font-black text-slate-900 text-sm" dir="ltr">
                                        <?= number_format($tot, 2, ',', ' ') ?> DZD
                                    </div>
                                    <?php if ($paidAmount > 0 && !$isPaid): ?>
                                        <div class="text-[10px] text-emerald-600 font-bold" dir="ltr">
                                            المدفوع: <?= number_format($paidAmount, 2, ',', ' ') ?> DZD
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 7. الحالة -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($isPaid): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/70">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            <span>مُسدّد (Réglé)</span>
                                        </span>
                                    <?php elseif ($status === 'partiel'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200/70">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>تسوية جزئية</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200/70">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                            <span>معلق (غير مسدد)</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 8. الإجراءات -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <button onclick="openDriverPayment(<?= (int)$r['driver_id'] ?>, '<?= htmlspecialchars($r['driver_name'], ENT_QUOTES) ?>', <?= $remaining > 0 ? $remaining : $tot ?>)" 
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-2xs">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        <span>تسجيل دفعة</span>
                                    </button>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-12 text-center text-slate-400">
                                <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-3">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                    </svg>
                                </div>
                                <div class="font-bold text-slate-600 text-sm">لا توجد بيانات اشتراكات مسجلة للفترة المحددة</div>
                                <div class="text-xs text-slate-400 mt-1">اضغط على "توليد استحقاقات الشهر" لتوليد التكاليف لكافة السائقين النشطين.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Paiement Manuel -->
<div id="manualPaymentModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">تسجيل دفعة اشتراك يدوية (Paiement Manuel)</h3>
                <span class="text-xs text-slate-500">تسجيل تسوية اشتراك السائق والمركبة لشهر <?= $monthNames[$selectedMonth] ?> <?= $selectedYear ?></span>
            </div>
            <button onclick="document.getElementById('manualPaymentModal').classList.add('hidden'); document.getElementById('manualPaymentModal').classList.remove('flex');" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="<?= url('/dashboard/billing.php?month=' . $selectedMonth . '&year=' . $selectedYear) ?>" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="manual_payment">

            <!-- Driver selection -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">السائق المعني (Chauffeur) <span class="text-rose-500">*</span></label>
                <select name="driver_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- اختر السائق من القائمة --</option>
                    <?php foreach ($allDriversList as $dl): ?>
                        <option value="<?= $dl['id'] ?>">
                            <?= htmlspecialchars($dl['full_name']) ?> (هاتف: <?= htmlspecialchars($dl['phone']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Amount -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">المبلغ المسدد (DZD) <span class="text-rose-500">*</span></label>
                <div class="relative">
                    <input type="number" name="amount" value="<?= $feeSpaceMonthly + $feeVehicleMonthly ?>" min="500" step="100" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <span class="absolute left-3.5 top-2.5 text-xs text-slate-400 font-bold">DZD</span>
                </div>
                <span class="text-[11px] text-slate-400 mt-1 block">
                    حساب السائق (<?= number_format($feeSpaceMonthly, 0, ',', ' ') ?> دج) + اشتراك المركبة (<?= number_format($feeVehicleMonthly, 0, ',', ' ') ?> دج/مركبة)
                </span>
            </div>

            <!-- Payment Method -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">وسيلة الدفع <span class="text-rose-500">*</span></label>
                    <select name="payment_method" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                        <option value="baridimob">بريدي موب (BaridiMob)</option>
                        <option value="ccp">حساب بريدي جاري (CCP)</option>
                        <option value="especes">نقداً بالوكالة (Espèces)</option>
                        <option value="virement">تحويل بنكي (Virement)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ الدفع</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num bg-white focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات أو رقم الإشعار / الوصل</label>
                <input type="text" name="notes" placeholder="مثال: وصل بريدي موب أو رقم الحوالة"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('manualPaymentModal').classList.add('hidden'); document.getElementById('manualPaymentModal').classList.remove('flex');"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    تأكيد الدفع والتسوية
                </button>
            </div>
        </form>

    </div>
</div>

<script>
function openDriverPayment(driverId, driverName, amount) {
    const modal = document.getElementById('manualPaymentModal');
    if (!modal) return;
    const select = modal.querySelector('select[name="driver_id"]');
    if (select) {
        select.value = driverId;
    }
    const inputAmount = modal.querySelector('input[name="amount"]');
    if (inputAmount && amount > 0) {
        inputAmount.value = amount;
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
