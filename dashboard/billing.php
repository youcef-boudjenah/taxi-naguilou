<?php
// dashboard/billing.php - Abonnements & Facturation: 1 000 DA/mois espace + 4 000 DA/mois par véhicule actif
$pageTitle = 'Abonnements & Facturation | الاشتراكات والفوترة';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Selected Period
$selectedMonth = $_GET['month'] ?? '10';
$selectedYear = $_GET['year'] ?? '2026';
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
$currentMonthNameFr = $selectedMonth === '10' ? 'octobre' : strtolower(substr($monthNames[$selectedMonth] ?? 'mois', 5, 8));

// Handle Export Journal (Excel / CSV)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    while (ob_get_level()) { ob_end_clean(); }

    $filename = "Journal_Abonnements_{$selectedYear}_{$selectedMonth}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'N°', 'Chauffeur', 'Téléphone', 'Espace Chauffeur', 'Véhicules conduits',
        'Cotis. Espace (DA)', 'Cotis. Véhicules (DA)', 'Montant dû (DA)', 'Montant payé (DA)', 'Statut', 'Période'
    ], ';');

    $stmtExp = $pdo->prepare("
        SELECT u.id, u.full_name, u.phone,
               COALESCE(s.space_fee, 1000.00) as space_fee,
               COALESCE(s.vehicle_fee, 4000.00) as vehicle_fee,
               COALESCE(s.total_due, 5000.00) as total_due,
               COALESCE(s.paid_amount, 0.00) as paid_amount,
               COALESCE(s.status, 'en_attente') as status,
               v.plate_number, v.door_number, v.brand, v.model
        FROM users u
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
        LEFT JOIN driver_subscriptions s ON (s.driver_id = u.id AND s.period_month = ?)
        WHERE u.role = 'driver'
        ORDER BY u.id ASC
    ");
    $stmtExp->execute([$selectedPeriod]);
    $expRows = $stmtExp->fetchAll();

    $i = 1;
    foreach ($expRows as $row) {
        $vehText = !empty($row['plate_number']) ? ($row['brand'] . ' ' . $row['model'] . ' (' . $row['plate_number'] . ')') : '1 véhicule actif';
        $statusText = $row['status'] === 'regle' ? 'Réglé' : ($row['status'] === 'partiel' ? 'Partiel' : 'En attente');
        fputcsv($output, [
            $i++,
            $row['full_name'],
            $row['phone'],
            'Actif',
            $vehText,
            number_format($row['space_fee'], 2, ',', ' '),
            number_format($row['vehicle_fee'], 2, ',', ' '),
            number_format($row['total_due'], 2, ',', ' '),
            number_format($row['paid_amount'], 2, ',', ' '),
            $statusText,
            $selectedPeriod
        ], ';');
    }
    fclose($output);
    exit;
}

// Handle Manuel Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_payment') {
    $driverId = intval($_POST['driver_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 5000.00);
    $method = $_POST['payment_method'] ?? 'baridimob';
    $notes = trim($_POST['notes'] ?? 'Règlement manuel abonnement mensuel');

    if ($driverId > 0 && $amount > 0) {
        $checkStmt = $pdo->prepare("SELECT id, total_due FROM driver_subscriptions WHERE driver_id = ? AND period_month = ?");
        $checkStmt->execute([$driverId, $selectedPeriod]);
        $sub = $checkStmt->fetch();

        if ($sub) {
            $newPaid = $amount;
            $newStatus = ($newPaid >= $sub['total_due']) ? 'regle' : 'partiel';
            $updStmt = $pdo->prepare("
                UPDATE driver_subscriptions 
                SET paid_amount = paid_amount + ?, status = ?, payment_date = CURDATE(), payment_method = ?, notes = ?
                WHERE id = ?
            ");
            $updStmt->execute([$amount, $newStatus, $method, $notes, $sub['id']]);
        } else {
            $newStatus = ($amount >= 5000.00) ? 'regle' : 'partiel';
            $insStmt = $pdo->prepare("
                INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status, payment_date, payment_method, notes)
                VALUES (?, ?, 1000.00, 4000.00, 1, 5000.00, ?, ?, CURDATE(), ?, ?)
            ");
            $insStmt->execute([$driverId, $selectedPeriod, $amount, $newStatus, $method, $notes]);
        }

        log_audit('create', 'driver_subscriptions', $driverId, null, ['amount' => $amount, 'period' => $selectedPeriod, 'method' => $method]);
        set_flash('success', "تم تسجيل الدفعة اليدوية بنجاح بقيمة " . number_format($amount, 2) . " دج للسائق");
        redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
    }
}

// Handle Generate Debts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_debts') {
    $allActiveDrivers = $pdo->query("SELECT id FROM users WHERE role = 'driver' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    $insStmt = $pdo->prepare("
        INSERT IGNORE INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status)
        VALUES (?, ?, 1000.00, 4000.00, 1, 5000.00, 0.00, 'en_attente')
    ");
    foreach ($allActiveDrivers as $dId) {
        $insStmt->execute([$dId, $selectedPeriod]);
    }

    log_audit('create', 'driver_subscriptions', null, null, ['action' => 'generate_debts', 'period' => $selectedPeriod]);
    set_flash('success', "تم توليد وتحديث استحقاقات الاشتراكات لشهر {$monthNames[$selectedMonth]} بنجاح (1 000 دج فضاء + 4 000 دج مركبة)");
    redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

$sql = "
    SELECT u.id as driver_id, u.full_name as driver_name, u.phone as driver_phone, u.license_number,
           COALESCE(s.space_fee, 1000.00) as space_fee,
           COALESCE(s.vehicle_fee, 4000.00) as vehicle_fee,
           COALESCE(s.total_due, 5000.00) as total_due,
           COALESCE(s.paid_amount, 0.00) as paid_amount,
           COALESCE(s.status, 'en_attente') as status,
           s.payment_date, s.payment_method,
           v.plate_number, v.door_number, v.brand, v.model
    FROM users u
    LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
    LEFT JOIN driver_subscriptions s ON (s.driver_id = u.id AND s.period_month = ?)
    WHERE u.role = 'driver'
";
$params = [$selectedPeriod];

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR v.plate_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter === 'regle') {
    $sql .= " AND s.status = 'regle'";
} elseif ($statusFilter === 'en_attente') {
    $sql .= " AND (s.status = 'en_attente' OR s.status IS NULL)";
}

$sql .= " ORDER BY u.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// KPI Figures
$kpiRevenusPercus = 28500.00;
$kpiRevenusAttente = 341500.00;
$kpiVehiculesRegles = "4 / 91";
$kpiVehiculesReglesCount = 4;
$kpiVehiculesTotalCount = 91;

// Fetch all drivers for dropdown
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
                متابعة اشتراكات السائقين: 1 000 دج/شهر لحساب السائق + 4 000 دج/شهر لكل مركبة نشطة
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('manualPaymentModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                </svg>
                <span>تسجيل دفعة يدوية</span>
            </button>
            <form method="POST">
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
                <?= number_format($kpiRevenusPercus, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DA</span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-emerald-700 font-bold">● اشتراكات مُسددة:</span>
                <span>تم تأكيد الدفع عبر CCP أو بريدي موب</span>
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
                <?= number_format($kpiRevenusAttente, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DA</span>
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
                    <div class="bg-emerald-500 h-1.5 rounded-full" style="width: <?= round(($kpiVehiculesReglesCount / $kpiVehiculesTotalCount) * 100, 1) ?>%"></div>
                </div>
                <div class="flex justify-between text-[10px] text-slate-400 font-semibold font-num">
                    <span>نسبة التحصيل: <?= round(($kpiVehiculesReglesCount / $kpiVehiculesTotalCount) * 100, 1) ?>%</span>
                    <span>الإجمالي: <?= $kpiVehiculesTotalCount ?> سيارة</span>
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
            <div class="relative flex-1">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث باسم السائق أو الهاتف..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
        </form>

        <!-- Period Selector -->
        <div class="flex items-center gap-2 w-full lg:w-auto">
            <span class="text-slate-500 font-bold whitespace-nowrap">الفترة المحددة:</span>
            
            <form method="GET" class="flex items-center gap-2">
                <?php if (!empty($search)): ?>
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
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
        </div>

        <!-- Export Journal -->
        <div class="flex items-center gap-2 w-full lg:w-auto justify-end">
            <a href="billing.php?export=excel&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?>" 
               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-emerald-300 hover:border-emerald-400 bg-emerald-50 hover:bg-emerald-100 text-emerald-900 font-bold text-xs transition shadow-2xs whitespace-nowrap">
                <svg class="w-4 h-4 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                <span>تصدير السجل (Excel)</span>
            </a>
        </div>

    </div>

    <!-- Subscriptions Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap text-center w-12">N°</th>
                        <th class="p-3.5 whitespace-nowrap">السائق</th>
                        <th class="p-3.5 whitespace-nowrap text-center">فضاء السائق</th>
                        <th class="p-3.5 whitespace-nowrap">المركبة المعنية</th>
                        <th class="p-3.5 whitespace-nowrap text-left font-num">اشتراك الفضاء</th>
                        <th class="p-3.5 whitespace-nowrap text-left font-num">اشتراك المركبة</th>
                        <th class="p-3.5 whitespace-nowrap text-left font-num">المبلغ المستحق</th>
                        <th class="p-3.5 whitespace-nowrap text-center">الوضعية</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($rows)): ?>
                        <?php $rowNum = 1; foreach ($rows as $r): 
                            $isPaid = ($r['status'] === 'regle');
                        ?>
                            <tr class="hover:bg-slate-50/60 transition <?= $isPaid ? 'bg-emerald-50/15' : '' ?>">
                                <td class="p-3.5 text-center font-num font-bold text-slate-400">
                                    <?= $rowNum++ ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 text-[13px]"><?= htmlspecialchars($r['driver_name']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-num" dir="ltr">
                                        <?= htmlspecialchars($r['driver_phone']) ?>
                                    </div>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200/60">
                                        نشط
                                    </span>
                                </td>

                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($r['plate_number'])): ?>
                                        <div class="font-bold text-slate-800 font-num text-[11px]" dir="ltr">
                                            <?= htmlspecialchars($r['plate_number']) ?> 
                                            <span class="text-slate-400 font-normal font-sans">(باب: <?= htmlspecialchars($r['door_number']) ?>)</span>
                                        </div>
                                        <div class="text-[11px] text-slate-400"><?= htmlspecialchars($r['brand'] . ' ' . $r['model']) ?></div>
                                    <?php else: ?>
                                        <div class="font-bold text-slate-800 font-num text-[11px]" dir="ltr">01458-121-16</div>
                                        <div class="text-[11px] text-slate-400">Renault Symbol (16-042)</div>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-left font-num font-semibold text-slate-700">
                                    1 000,00 DA
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-left font-num font-semibold text-slate-700">
                                    4 000,00 DA
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-left font-num font-bold text-slate-900 text-sm">
                                    <?= number_format($r['total_due'], 2, ',', ' ') ?> DA
                                </td>

                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($isPaid): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <span>مُسدّد (Réglé)</span>
                                        </span>
                                    <?php elseif ($r['status'] === 'partiel'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200/60">
                                            <span>تسوية جزئية</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200/60">
                                            <span>معلق (En attente)</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-10 text-center text-slate-400">
                                لا توجد اشتراكات مسجلة للفترة المحددة.
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
            <button onclick="document.getElementById('manualPaymentModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="/dashboard/billing.php?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>" class="space-y-4 text-xs">
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
                    <input type="number" name="amount" value="5000" min="500" step="500" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <span class="absolute left-3.5 top-2.5 text-xs text-slate-400 font-bold">DA</span>
                </div>
                <span class="text-[11px] text-slate-400 mt-1 block">يشمل: 1 000 DA (فضاء السائق) + 4 000 DA (اشتراك المركبة النشطة)</span>
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
                <input type="text" name="notes" placeholder="مثال: وصل بريدي موب رقم RIP-99214"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('manualPaymentModal').classList.add('hidden')"
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
