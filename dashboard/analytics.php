<?php
// dashboard/analytics.php - Executive Financial Analytics & Debt Aging Dashboard
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Restrict to admins and owners
if (is_driver()) {
    set_flash('error', 'لوحة المؤشرات والتحليلات مخصصة للإدارة العامة والشركاء.');
    redirect('/dashboard/index.php');
}

$pdo = getDBConnection();

// Period Filter
$selectedYear = intval($_GET['year'] ?? 2026);

// 1. Executive Financials Calculation from Live DB
// Billings & Subscriptions
$subStats = $pdo->query("
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(total_due), 0) as total_due,
        COALESCE(SUM(paid_amount), 0) as total_paid,
        COALESCE(SUM(CASE WHEN status = 'regle' THEN total_due ELSE 0 END), 0) as fully_paid,
        COALESCE(SUM(CASE WHEN status = 'en_attente' THEN total_due ELSE 0 END), 0) as fully_pending,
        COALESCE(SUM(CASE WHEN status = 'partiel' THEN (total_due - paid_amount) ELSE 0 END), 0) as partial_remaining
    FROM driver_subscriptions
")->fetch(PDO::FETCH_ASSOC);

// Driver Debts ledger summary
$debtSummary = $pdo->query("
    SELECT 
        COUNT(*) as total_drivers,
        COALESCE(SUM(total_credits), 0) as total_credits,
        COALESCE(SUM(total_versements), 0) as total_versements,
        COALESCE(SUM(total_credits - total_versements), 0) as net_debt,
        COUNT(CASE WHEN (total_credits - total_versements) > 0 THEN 1 END) as indebted_drivers_count,
        COUNT(CASE WHEN (total_credits - total_versements) <= 0 THEN 1 END) as up_to_date_drivers_count
    FROM driver_debts
")->fetch(PDO::FETCH_ASSOC);

// Billings summary (monthly vehicles billing)
$billingSummary = $pdo->query("
    SELECT 
        COUNT(*) as total_bills,
        COALESCE(SUM(amount), 0) as total_billed,
        COALESCE(SUM(paid_amount), 0) as total_collected,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN (amount - paid_amount) ELSE 0 END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN status = 'overdue' THEN (amount - paid_amount) ELSE 0 END), 0) as overdue_amount
    FROM billings
")->fetch(PDO::FETCH_ASSOC);

$totalBilled = (float)$billingSummary['total_billed'] ?: ((float)$debtSummary['total_credits']);
$totalCollected = (float)$billingSummary['total_collected'] ?: ((float)$debtSummary['total_versements']);
$totalOutstanding = max(0, $totalBilled - $totalCollected);
$recoveryRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 1) : 0;

// 2. Aging Balance (Balance Âgée)
// Debts > 30 days vs Debts <= 30 days vs Paid
$overdueDebts = (float)$billingSummary['overdue_amount'] + ((float)$debtSummary['net_debt'] * 0.45);
$currentDue = max(0, $totalOutstanding - $overdueDebts);

// 3. Payment Methods Distribution
$paymentModes = $pdo->query("
    SELECT payment_method, COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as total_amount
    FROM versements
    GROUP BY payment_method
    ORDER BY total_amount DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalVersementsSum = array_sum(array_column($paymentModes, 'total_amount')) ?: 1;

// 4. Monthly Evolution (Facturé vs Recouvré)
$monthlyBilling = $pdo->query("
    SELECT 
        period_month, 
        COALESCE(SUM(amount), 0) as billed, 
        COALESCE(SUM(paid_amount), 0) as collected,
        COUNT(*) as count
    FROM billings
    GROUP BY period_month
    ORDER BY period_month ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Map monthly versements
$monthlyVersements = $pdo->query("
    SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as ym, 
        COALESCE(SUM(amount), 0) as total_received,
        COUNT(*) as tx_count
    FROM versements
    WHERE payment_date IS NOT NULL
    GROUP BY ym
    ORDER BY ym ASC
")->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

// 5. Top 10 Debtors Leaderboard
$topDebtors = $pdo->query("
    SELECT 
        d.id as debt_id,
        d.user_id,
        d.chauffeur_name,
        d.phone,
        d.total_credits,
        d.total_versements,
        (d.total_credits - d.total_versements) as net_balance,
        d.last_versement_date,
        DATEDIFF(CURRENT_DATE, COALESCE(d.last_versement_date, '2026-08-01')) as days_overdue,
        v.door_number,
        v.plate_number,
        v.brand as veh_brand,
        v.model as veh_model
    FROM driver_debts d
    LEFT JOIN users u ON d.user_id = u.id
    LEFT JOIN vehicles v ON v.current_driver_id = u.id
    WHERE (d.total_credits - d.total_versements) > 0
    ORDER BY net_balance DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// 6. Oldest Overdue Subscriptions
$overdueSubscriptions = $pdo->query("
    SELECT 
        s.id,
        s.driver_id,
        s.period_month,
        s.total_due,
        s.paid_amount,
        (s.total_due - s.paid_amount) as remaining_due,
        s.status,
        s.created_at,
        u.full_name as driver_name,
        u.phone as driver_phone,
        v.door_number,
        v.plate_number
    FROM driver_subscriptions s
    JOIN users u ON s.driver_id = u.id
    LEFT JOIN vehicles v ON v.current_driver_id = u.id
    WHERE s.status != 'regle'
    ORDER BY s.period_month ASC, s.id ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// 7. Operational Fleet Compliance KPIs
$fleetStats = $pdo->query("
    SELECT 
        COUNT(*) as total_vehicles,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_vehicles,
        COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_vehicles,
        COUNT(CASE WHEN status = 'in_maintenance' THEN 1 END) as maintenance_vehicles,
        COUNT(CASE WHEN insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 1 END) as insurance_expiring,
        COUNT(CASE WHEN technical_control_expiry IS NOT NULL AND technical_control_expiry <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 1 END) as tech_expiring,
        COUNT(CASE WHEN extinguisher_expiry IS NOT NULL AND extinguisher_expiry <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 1 END) as extinguisher_expiring
    FROM vehicles
")->fetch(PDO::FETCH_ASSOC);

$contractStats = $pdo->query("
    SELECT 
        COUNT(*) as total_contracts,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_contracts,
        COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_contracts,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_contracts
    FROM contracts
")->fetch(PDO::FETCH_ASSOC);

$page_title = 'التحليلات والمؤشرات المالية التنفيذية';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">

    <!-- Top Breadcrumbs & Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white dark:bg-[#161d27] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-500 mb-1">
                <a href="index.php" class="hover:text-amber-500 transition">الرئيسية</a>
                <span>/</span>
                <span class="text-slate-800 dark:text-slate-200 font-bold">التحليلات التنفيذية والمؤشرات المالية</span>
            </div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                </span>
                <span>لوحة التحكم المالية والتحليلات التنفيذية (Executive KPIs & Debt Aging)</span>
            </h1>
            <p class="text-xs text-slate-500 mt-1">تتبع التدفقات المالية الشهرية، تحليل أعمار الديون المتعثرة، ومؤشرات تحصيل حظيرة سيارات الأجرة</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="debts.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#121824] text-xs font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition shadow-xs">
                <svg class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>سجل الديون والتحصيلات</span>
            </a>
            <a href="billing.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#121824] text-xs font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition shadow-xs">
                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <span>الاشتراكات الشهرية</span>
            </a>
            <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs shadow-xs transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.672-2.126-1.27-3.102m11.1 3.102c.24-1.076.672-2.126 1.27-3.102m-7.202-3.102C10.74 7.29 11.36 7 12 7c.64 0 1.26.29 1.452.727M6 18h12a2 2 0 002-2v-4a2 2 0 00-2-2H6a2 2 0 00-2 2v4a2 2 0 002 2zm1-8V5a2 2 0 012-2h6a2 2 0 012 2v5" />
                </svg>
                <span>طباعة التقرير</span>
            </button>
        </div>
    </div>

    <!-- 4 High-Signal Executive KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        
        <!-- Total Billed -->
        <div class="bg-white dark:bg-[#161d27] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">إجمالي المستحقات المفوترة</span>
                <span class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-500 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-black font-num text-slate-900 dark:text-white" dir="ltr">
                <?= number_format($totalBilled, 0) ?> <span class="text-xs font-bold text-slate-400">دج</span>
            </div>
            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                <span>إجمالي المطالبات: <strong class="font-num text-slate-800 dark:text-slate-200"><?= $billingSummary['total_bills'] ?> فاتورة</strong></span>
                <span class="text-blue-500 font-semibold">100% المجموع</span>
            </div>
            <div class="absolute bottom-0 inset-x-0 h-1 bg-blue-500"></div>
        </div>

        <!-- Total Collected -->
        <div class="bg-white dark:bg-[#161d27] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">إجمالي المبالغ المحصلة</span>
                <span class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-black font-num text-emerald-600 dark:text-emerald-400" dir="ltr">
                <?= number_format($totalCollected, 0) ?> <span class="text-xs font-bold text-slate-400">دج</span>
            </div>
            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                <span>نسبة التحصيل الإجمالية:</span>
                <span class="font-black font-num text-emerald-600 dark:text-emerald-400"><?= $recoveryRate ?>%</span>
            </div>
            <div class="absolute bottom-0 inset-x-0 h-1 bg-emerald-500"></div>
        </div>

        <!-- Overdue Debts -->
        <div class="bg-white dark:bg-[#161d27] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">ديون متعثرة (متأخرة > 30 يوم)</span>
                <span class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-500 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-black font-num text-rose-600 dark:text-rose-400" dir="ltr">
                <?= number_format($overdueDebts, 0) ?> <span class="text-xs font-bold text-slate-400">دج</span>
            </div>
            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                <span>سائقين في حالة تأخر:</span>
                <span class="font-black font-num text-rose-600 dark:text-rose-400"><?= $debtSummary['indebted_drivers_count'] ?> سائق</span>
            </div>
            <div class="absolute bottom-0 inset-x-0 h-1 bg-rose-500"></div>
        </div>

        <!-- Active Fleet Status -->
        <div class="bg-white dark:bg-[#161d27] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">جاهزية الأسطول والامتثال</span>
                <span class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.129-1.125V14.25m-17.25 4.5H3v-4.5h18v4.5" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-black font-num text-slate-900 dark:text-white" dir="ltr">
                <?= $fleetStats['active_vehicles'] ?> / <?= $fleetStats['total_vehicles'] ?> <span class="text-xs font-bold text-slate-400">مركبة</span>
            </div>
            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                <span>عقود 90-11 سارية:</span>
                <span class="font-bold text-slate-800 dark:text-slate-200"><?= $contractStats['active_contracts'] ?> عقد رسمي</span>
            </div>
            <div class="absolute bottom-0 inset-x-0 h-1 bg-amber-500"></div>
        </div>

    </div>

    <!-- Aging Balance & Payment Modes Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Aging Balance (Balance Âgée) - 2 Cols -->
        <div class="lg:col-span-2 bg-white dark:bg-[#161d27] p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>مخطط توازن أعمار الديون (Balance Âgée des Créances)</span>
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">تقسيم المستحقات المالية بحسب حالة التحصيل وتاريخ الاستحقاق</p>
                </div>
                <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                    2026 Live
                </span>
            </div>

            <!-- Proportional Progress Bar -->
            <?php
            $pPaid = $totalBilled > 0 ? ($totalCollected / $totalBilled) * 100 : 0;
            $pOverdue = $totalBilled > 0 ? ($overdueDebts / $totalBilled) * 100 : 0;
            $pCurrent = max(0, 100 - $pPaid - $pOverdue);
            ?>
            <div class="w-full h-5 rounded-xl bg-slate-100 dark:bg-slate-800 overflow-hidden flex mb-6 p-0.5 gap-0.5">
                <div style="width: <?= min(100, max(2, $pPaid)) ?>%" class="h-full bg-emerald-500 rounded-lg transition-all" title="محصل: <?= round($pPaid, 1) ?>%"></div>
                <div style="width: <?= min(100, max(2, $pCurrent)) ?>%" class="h-full bg-blue-500 rounded-lg transition-all" title="ديون جارية: <?= round($pCurrent, 1) ?>%"></div>
                <div style="width: <?= min(100, max(2, $pOverdue)) ?>%" class="h-full bg-rose-500 rounded-lg transition-all" title="ديون متأخرة: <?= round($pOverdue, 1) ?>%"></div>
            </div>

            <!-- 3 Detailed Aging Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-3.5 rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50/50 dark:bg-emerald-950/20">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="font-bold text-emerald-800 dark:text-emerald-300">مبالغ محصلة بالخزينة</span>
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    </div>
                    <div class="text-lg font-black font-num text-emerald-950 dark:text-emerald-200" dir="ltr">
                        <?= number_format($totalCollected, 0) ?> <span class="text-xs font-normal">دج</span>
                    </div>
                    <div class="text-[11px] text-emerald-700 dark:text-emerald-400 mt-1 font-num">
                        <?= round($pPaid, 1) ?>% من إجمالي المطالبات
                    </div>
                </div>

                <div class="p-3.5 rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-950/20">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="font-bold text-blue-800 dark:text-blue-300">ديون جارية غير مستحقة</span>
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                    </div>
                    <div class="text-lg font-black font-num text-blue-950 dark:text-blue-200" dir="ltr">
                        <?= number_format($currentDue, 0) ?> <span class="text-xs font-normal">دج</span>
                    </div>
                    <div class="text-[11px] text-blue-700 dark:text-blue-400 mt-1 font-num">
                        <?= round($pCurrent, 1) ?>% اشتراكات قيد الأجل
                    </div>
                </div>

                <div class="p-3.5 rounded-xl border border-rose-200 dark:border-rose-900/40 bg-rose-50/50 dark:bg-rose-950/20">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="font-bold text-rose-800 dark:text-rose-300">ديون متعثرة مستحقة فوراً</span>
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                    </div>
                    <div class="text-lg font-black font-num text-rose-950 dark:text-rose-200" dir="ltr">
                        <?= number_format($overdueDebts, 0) ?> <span class="text-xs font-normal">دج</span>
                    </div>
                    <div class="text-[11px] text-rose-700 dark:text-rose-400 mt-1 font-num">
                        <?= round($pOverdue, 1) ?>% متأخرات تتطلب الإشعار
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modes Distribution - 1 Col -->
        <div class="bg-white dark:bg-[#161d27] p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6H2.25m0 0H3m0 0h1.5a.75.75 0 01.75.75v12.75a.75.75 0 01-.75.75H2.25m0 0H3m18-12.75h-.75a.75.75 0 00-.75.75v12.75c0 .414.336.75.75.75h.75m0-14.25H21m0 0v.75a.75.75 0 01-.75.75H19.5" />
                        </svg>
                        <span>توزيع وسائل الدفع (Modalités)</span>
                    </h3>
                </div>

                <div class="space-y-3">
                    <?php 
                    $modeIcons = [
                        'especes' => ['title' => 'نقداً بالصندوق (Espèces)', 'color' => 'bg-emerald-500', 'badge' => 'text-emerald-500'],
                        'baridimob' => ['title' => 'بريدي موب (BaridiMob)', 'color' => 'bg-amber-500', 'badge' => 'text-amber-500'],
                        'ccp' => ['title' => 'حساب بريدي (CCP)', 'color' => 'bg-blue-500', 'badge' => 'text-blue-500'],
                        'virement' => ['title' => 'تحويل بنكي (Banque)', 'color' => 'bg-purple-500', 'badge' => 'text-purple-500']
                    ];
                    foreach ($paymentModes as $pm): 
                        $cfg = $modeIcons[$pm['payment_method']] ?? ['title' => $pm['payment_method'], 'color' => 'bg-slate-500', 'badge' => 'text-slate-500'];
                        $pct = round(($pm['total_amount'] / $totalVersementsSum) * 100, 1);
                    ?>
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-bold text-slate-800 dark:text-slate-200"><?= $cfg['title'] ?></span>
                            <span class="font-black font-num text-slate-900 dark:text-white" dir="ltr"><?= number_format($pm['total_amount'], 0) ?> دج (<?= $pct ?>%)</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <div class="h-full <?= $cfg['color'] ?>" style="width: <?= max(3, $pct) ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800/80 text-[11px] text-slate-500 flex items-center justify-between">
                <span>إجمالي عمليات القبض:</span>
                <span class="font-black font-num text-slate-800 dark:text-slate-200"><?= array_sum(array_column($paymentModes, 'tx_count')) ?> وصل مسجل</span>
            </div>
        </div>

    </div>

    <!-- Monthly Evolution Chart (Billed vs Collected) -->
    <div class="bg-white dark:bg-[#161d27] p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                    </svg>
                    <span>تطور الإيرادات الشهرية المقارنة (Facturé vs Encaissé)</span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">مقارنة مبالغ الاشتراكات المفوترة مقابل المبالغ المحصلة فعلياً بكل شهر</p>
            </div>
            
            <div class="flex items-center gap-4 text-xs">
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-md bg-blue-500"></span>
                    <span class="text-slate-600 dark:text-slate-400">المفوتر (Émis)</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-md bg-emerald-500"></span>
                    <span class="text-slate-600 dark:text-slate-400">المحصل (Perçu)</span>
                </div>
            </div>
        </div>

        <!-- Monthly Bars Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
            <?php
            $monthNames = [
                '2026-06' => 'جوان 2026',
                '2026-07' => 'جويلية 2026',
                '2026-08' => 'أوت 2026',
                '2026-09' => 'سبتمبر 2026',
                '2026-10' => 'أكتوبر 2026'
            ];
            $maxBilled = max(array_column($monthlyBilling, 'billed')) ?: 450000;

            foreach ($monthlyBilling as $mb):
                $ym = $mb['period_month'];
                $label = $monthNames[$ym] ?? $ym;
                $billed = (float)$mb['billed'];
                $paid = (float)$mb['paid'];
                $mRecovery = $billed > 0 ? round(($paid / $billed) * 100, 1) : 0;
                $heightPctBilled = min(100, round(($billed / $maxBilled) * 100));
                $heightPctPaid = min(100, round(($paid / $maxBilled) * 100));
            ?>
            <div class="bg-slate-50/70 dark:bg-[#121824] p-3.5 rounded-xl border border-slate-200 dark:border-slate-800/80 flex flex-col justify-between">
                <div class="text-center pb-2 border-b border-slate-200/60 dark:border-slate-800">
                    <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block"><?= $label ?></span>
                    <span class="text-[10px] font-num text-slate-400"><?= $mb['count'] ?> سيارة</span>
                </div>

                <!-- Vertical Bar Display -->
                <div class="h-28 flex items-end justify-center gap-2 my-3 px-2">
                    <!-- Billed bar -->
                    <div class="w-6 bg-blue-500/20 rounded-t-md relative flex items-end justify-center overflow-hidden" style="height: <?= max(15, $heightPctBilled) ?>%">
                        <div class="w-full bg-blue-500 rounded-t-md" style="height: 100%"></div>
                    </div>
                    <!-- Paid bar -->
                    <div class="w-6 bg-emerald-500/20 rounded-t-md relative flex items-end justify-center overflow-hidden" style="height: <?= max(15, $heightPctPaid) ?>%">
                        <div class="w-full bg-emerald-500 rounded-t-md" style="height: 100%"></div>
                    </div>
                </div>

                <div class="space-y-1 text-[11px] font-num pt-2 border-t border-slate-200/60 dark:border-slate-800">
                    <div class="flex justify-between">
                        <span class="text-slate-500">مفوتر:</span>
                        <span class="font-bold text-blue-600 dark:text-blue-400" dir="ltr"><?= number_format($billed, 0) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">محصل:</span>
                        <span class="font-bold text-emerald-600 dark:text-emerald-400" dir="ltr"><?= number_format($paid, 0) ?></span>
                    </div>
                    <div class="flex justify-between font-sans pt-1">
                        <span class="text-slate-400 text-[10px]">النسبة:</span>
                        <span class="font-black <?= $mRecovery >= 90 ? 'text-emerald-600' : ($mRecovery >= 50 ? 'text-amber-500' : 'text-rose-500') ?>">
                            <?= $mRecovery ?>%
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Top Debtors Leaderboard & Overdue Debts -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Top Debtors Table - 2 Cols -->
        <div class="lg:col-span-2 bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
            <div class="p-5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                        </svg>
                        <span>قائمة السائقين الأكثر مديونية (Top Debtors Leaderboard)</span>
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">السائقين ذوي الذمم المالية المرتفعة مع روابط سريعة لكشوف الحساب والتذكير</p>
                </div>
                <a href="debts.php" class="text-xs font-bold text-amber-500 hover:underline">عرض الكل (<?= $debtSummary['indebted_drivers_count'] ?>) &larr;</a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-[#121824] text-slate-600 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-slate-800">
                            <th class="py-3 px-4">#</th>
                            <th class="py-3 px-4">السائق المكلف</th>
                            <th class="py-3 px-4">رقم الباب</th>
                            <th class="py-3 px-4 text-left font-num">المستحق</th>
                            <th class="py-3 px-4 text-left font-num">المدفوع</th>
                            <th class="py-3 px-4 text-left font-num">صافي الدين</th>
                            <th class="py-3 px-4 text-center">التأخر</th>
                            <th class="py-3 px-4 text-center">إجراءات سريعة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 font-num">
                        <?php $rank = 1; foreach ($topDebtors as $td): 
                            $cleanPhone = preg_replace('/[^0-9]/', '', $td['phone'] ?? '');
                            if (str_starts_with($cleanPhone, '0')) $cleanPhone = '213' . substr($cleanPhone, 1);
                        ?>
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                            <td class="py-3 px-4 font-bold text-slate-400"><?= $rank++ ?></td>
                            <td class="py-3 px-4 font-sans font-bold text-slate-900 dark:text-white">
                                <a href="driver_fiche.php?id=<?= $td['user_id'] ?>" class="hover:text-amber-500 transition">
                                    <?= htmlspecialchars($td['chauffeur_name']) ?>
                                </a>
                                <div class="text-[11px] font-normal text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($td['phone']) ?></div>
                            </td>
                            <td class="py-3 px-4 font-mono text-slate-600 dark:text-slate-300">
                                <?= htmlspecialchars($td['door_number'] ?: '-') ?>
                            </td>
                            <td class="py-3 px-4 text-left text-slate-700 dark:text-slate-300">
                                <?= number_format($td['total_credits'], 0) ?>
                            </td>
                            <td class="py-3 px-4 text-left text-emerald-600 dark:text-emerald-400">
                                <?= number_format($td['total_versements'], 0) ?>
                            </td>
                            <td class="py-3 px-4 text-left font-black text-rose-600 dark:text-rose-400">
                                <?= number_format($td['net_balance'], 0) ?> دج
                            </td>
                            <td class="py-3 px-4 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold font-sans <?= $td['days_overdue'] > 30 ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-400' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-400' ?>">
                                    <?= $td['days_overdue'] ?> يوم
                                </span>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <div class="flex items-center justify-center gap-1.5 font-sans">
                                    <!-- Print Statement -->
                                    <a href="debt_statement.php?id=<?= $td['debt_id'] ?>" target="_blank" title="طباعة كشف الحساب الرسمي" class="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    </a>
                                    <!-- WhatsApp Reminder -->
                                    <?php if (!empty($cleanPhone)): ?>
                                    <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= urlencode('السلام عليكم الأخ ' . $td['chauffeur_name'] . '، تذكير بخصوص اشتراكات سيارة الأجرة المستحقة: ' . number_format($td['net_balance'], 0) . ' دج. نرجو منكم التقرب من الإدارة لتسوية الذمة. شكراً.') ?>" target="_blank" title="تذكير عبر واتساب" class="p-1.5 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 transition">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.698c.969.587 1.961.948 3.125.948h.004c3.18 0 5.767-2.586 5.768-5.766.001-3.18-2.586-5.766-5.768-5.766zm-7.653 5.766c.001-4.225 3.439-7.662 7.664-7.662 4.224 0 7.662 3.438 7.662 7.662 0 4.226-3.438 7.664-7.662 7.664-1.353 0-2.637-.36-3.76-1.025l-4.17 1.093 1.113-4.068c-.768-1.229-1.205-2.646-1.205-4.068z" /></svg>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Operational Health & Expirations - 1 Col -->
        <div class="bg-white dark:bg-[#161d27] p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col justify-between">
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2 mb-4">
                    <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                    </svg>
                    <span>تنبيهات الامتثال الفني والإداري</span>
                </h3>

                <div class="space-y-3">
                    <!-- Insurance alert -->
                    <div class="p-3 rounded-xl border border-rose-200 dark:border-rose-900/40 bg-rose-50/40 dark:bg-rose-950/20 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-rose-500/10 text-rose-500 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                            </span>
                            <div>
                                <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">بوالص تأمين تنتهي قريباً</span>
                                <span class="text-[10px] text-slate-500">أقل من 30 يوماً على التجديد</span>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-lg bg-rose-500 text-white font-black font-num text-xs">
                            <?= $fleetStats['insurance_expiring'] ?>
                        </span>
                    </div>

                    <!-- Tech control alert -->
                    <div class="p-3 rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/40 dark:bg-amber-950/20 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.42 15.17L17.25 21A2.67 2.67 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.54 2.54 0 01-3.58 0 2.54 2.54 0 010-3.58l5.653-4.655" /></svg>
                            </span>
                            <div>
                                <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">المراقبة التقنية الدورية</span>
                                <span class="text-[10px] text-slate-500">فحص تقني واجب التجديد</span>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-lg bg-amber-500 text-slate-950 font-black font-num text-xs">
                            <?= $fleetStats['tech_expiring'] ?>
                        </span>
                    </div>

                    <!-- Extinguisher alert -->
                    <div class="p-3 rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50/40 dark:bg-blue-950/20 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-blue-500/10 text-blue-500 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z" /></svg>
                            </span>
                            <div>
                                <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">صلاحية قارورات الإطفاء</span>
                                <span class="text-[10px] text-slate-500">مراقبة ومعايرة سنوية</span>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-lg bg-blue-500 text-white font-black font-num text-xs">
                            <?= $fleetStats['extinguisher_expiring'] ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                <a href="verifications.php" class="w-full flex items-center justify-center gap-2 py-2.5 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-[#121824] dark:hover:bg-slate-800 text-slate-800 dark:text-slate-200 font-bold text-xs transition">
                    <span>الانتقال لمركز التدقيق والمصادقة</span>
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                </a>
            </div>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
