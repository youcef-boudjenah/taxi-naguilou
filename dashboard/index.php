<?php
// dashboard/index.php - Modern Desktop/ERP Launcher with Dynamic Layout Switching (Grid, Sidebar, List)
$pageTitle = 'لوحة التحكم الرئيسية';
require_once __DIR__ . '/../includes/header.php';

// Initialize KPIs and Live Counts
$counts = [
    'vehicles'           => 0,
    'insurance'          => 0,
    'drivers'            => 0,
    'contracts'          => 0,
    'cnas'               => 0,
    'leaves'             => 0,
    'slips'              => 0,
    'traveler_reports'   => 0,
    'planning'           => 0,
    'debts'              => 0,
    'billing'            => 0,
    'notifications'      => 0,
    'users'              => 0,
    'audit_logs'         => 0,
    'settings'           => 0,
    'trash'              => 0,
    'expiring_docs'      => $alertCount,
    'expiring_contracts' => 0,
];

// Driver Financials & Documents Tracking Variables
$driverDebt            = null;
$driverTotalBalance    = 0.0;
$driverSubscriptionDue = 0.0;
$driverHasOverdue      = false;
$driverExpiringDocs    = 0;
$driverVehicleInfo     = null;

// Executive KPIs Tracking Variables (Always initialized with safe defaults)
$totalDebtsBalance     = 0.0;
$activeVehiclesCount   = 0;
$cnasCotisationsPaid   = 0.0;
$totalBilledAmount     = 0.0;
$recoveryRate          = 100.0;

if ($pdo) {
    try {
        if ($isTaxiDriver) {
            $counts['notifications'] = (int)$unreadNotifCount;
            $counts['planning']      = (int)$pdo->query("SELECT COUNT(*) FROM night_watch WHERE status = 'in_park'")->fetchColumn();

            // 1. Debt balance from driver_debts
            $stmtD = $pdo->prepare("SELECT * FROM driver_debts WHERE user_id = ? AND deleted_at IS NULL LIMIT 1");
            $stmtD->execute([$user['id']]);
            $driverDebt = $stmtD->fetch();
            $driverTotalBalance = $driverDebt ? max(0, (float)($driverDebt['total_credits'] ?? 0) + (float)($driverDebt['total_penalties'] ?? 0) - (float)($driverDebt['total_versements'] ?? 0)) : 0.0;

            // 2. Subscription due for current month
            $currentMonth = date('Y-m');
            $stmtSub = $pdo->prepare("SELECT * FROM driver_subscriptions WHERE driver_id = ? AND period_month = ? LIMIT 1");
            $stmtSub->execute([$user['id'], $currentMonth]);
            $curSub = $stmtSub->fetch();

            if ($curSub) {
                $driverSubscriptionDue = max(0, (float)$curSub['total_due'] - (float)$curSub['paid_amount']);
            } else {
                $driverSubscriptionDue = (float)($user['custom_vehicle_fee'] ?? 3000);
            }

            $driverHasOverdue = ($driverTotalBalance > 0 || $driverSubscriptionDue > 0);

            // 3. Driver & Vehicle Document Expirations
            $stmtV = $pdo->prepare("SELECT * FROM vehicles WHERE current_driver_id = ? AND deleted_at IS NULL LIMIT 1");
            $stmtV->execute([$user['id']]);
            $driverVehicleInfo = $stmtV->fetch();

            $stmtU = $pdo->prepare("SELECT license_expiry, medical_cert_expiry, medical_exam_end FROM users WHERE id = ? LIMIT 1");
            $stmtU->execute([$user['id']]);
            $uDocs = $stmtU->fetch();

            $warningLimit = date('Y-m-d', strtotime('+30 days'));
            $checkDates = [];
            if (!empty($uDocs['license_expiry']) && $uDocs['license_expiry'] !== '0000-00-00') $checkDates[] = $uDocs['license_expiry'];
            if (!empty($uDocs['medical_cert_expiry']) && $uDocs['medical_cert_expiry'] !== '0000-00-00') $checkDates[] = $uDocs['medical_cert_expiry'];
            elseif (!empty($uDocs['medical_exam_end']) && $uDocs['medical_exam_end'] !== '0000-00-00') $checkDates[] = $uDocs['medical_exam_end'];

            if ($driverVehicleInfo) {
                if (!empty($driverVehicleInfo['insurance_expiry']) && $driverVehicleInfo['insurance_expiry'] !== '0000-00-00') $checkDates[] = $driverVehicleInfo['insurance_expiry'];
                if (!empty($driverVehicleInfo['technical_control_expiry']) && $driverVehicleInfo['technical_control_expiry'] !== '0000-00-00') $checkDates[] = $driverVehicleInfo['technical_control_expiry'];
                if (!empty($driverVehicleInfo['extinguisher_expiry']) && $driverVehicleInfo['extinguisher_expiry'] !== '0000-00-00') $checkDates[] = $driverVehicleInfo['extinguisher_expiry'];
                if (!empty($driverVehicleInfo['gpl_expiry']) && $driverVehicleInfo['gpl_expiry'] !== '0000-00-00') $checkDates[] = $driverVehicleInfo['gpl_expiry'];
                if (!empty($driverVehicleInfo['saccuriometre_expiry']) && $driverVehicleInfo['saccuriometre_expiry'] !== '0000-00-00') $checkDates[] = $driverVehicleInfo['saccuriometre_expiry'];
            }

            foreach ($checkDates as $d) {
                if ($d <= $warningLimit) {
                    $driverExpiringDocs++;
                }
            }
        } else {
            $counts['vehicles']           = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL")->fetchColumn();
            $counts['drivers']            = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status = 'active' AND deleted_at IS NULL")->fetchColumn();
            $counts['contracts']          = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status = 'signed' AND deleted_at IS NULL")->fetchColumn();
            $counts['expiring_contracts'] = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE end_date >= CURDATE() AND end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND deleted_at IS NULL")->fetchColumn();
            $counts['cnas']               = (int)$pdo->query("SELECT COUNT(*) FROM cnas_declarations WHERE deleted_at IS NULL")->fetchColumn();
            $counts['leaves']             = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE status = 'approved' AND end_date >= CURDATE() AND deleted_at IS NULL")->fetchColumn();
            $counts['slips']              = (int)$pdo->query("SELECT COUNT(*) FROM transmission_slips WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
            $counts['traveler_reports']   = (int)$pdo->query("SELECT COUNT(*) FROM traveler_reports WHERE deleted_at IS NULL")->fetchColumn();
            $counts['planning']           = (int)$pdo->query("SELECT COUNT(*) FROM night_watch WHERE status = 'in_park'")->fetchColumn();
            $counts['debts']              = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE status = 'endette' AND deleted_at IS NULL")->fetchColumn();
            $counts['billing']            = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM billings WHERE status = 'paid'")->fetchColumn();
            $counts['notifications']      = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
            $counts['users']              = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
            $counts['audit_logs']         = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
            $counts['settings']           = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
            $counts['trash']              = function_exists('get_trash_counts') ? (get_trash_counts($pdo)['total'] ?? 0) : 0;
            $totalDebtsBalance            = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(0, total_credits + COALESCE(total_penalties, 0) - total_versements)), 0) FROM driver_debts WHERE deleted_at IS NULL")->fetchColumn();
            $activeVehiclesCount          = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
            $cnasCotisationsPaid          = (float)$pdo->query("SELECT COALESCE(SUM(total_cotisation), 0) FROM cnas_declarations WHERE payment_status = 'regle' AND deleted_at IS NULL")->fetchColumn();
            $totalBilledAmount            = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM billings")->fetchColumn();
            $recoveryRate                 = $totalBilledAmount > 0 ? round(($counts['billing'] / $totalBilledAmount) * 100, 1) : 100.0;
        }
    } catch (\Throwable $e) {
        error_log("Dashboard query error: " . $e->getMessage());
    }
}

// Categories Definitions & Metadata
if ($isTaxiDriver) {
    // Tailored Categories for Drivers Only
    $categories = [
        'stats' => [
            'id'    => 'stats',
            'name'  => 'لوحة إحصائياتي (Dashboard Stats)',
            'short' => 'الإحصائيات',
            'desc'  => 'ملخص وضعيّتي المالية، اشتراكاتي، ديوني، رخصة السياقة والمناوبة',
            'icon'  => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        ],
        'all' => [
            'id'    => 'all',
            'name'  => 'كافة خدمات السائق',
            'short' => 'الكل',
            'desc'  => 'كافة المستحقات، الديون، الوثائق، المناوبة والإشعارات الخاصة بك',
            'icon'  => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
        ],
        'finance' => [
            'id'    => 'finance',
            'name'  => 'الدراهم والديون',
            'short' => 'المالية',
            'desc'  => 'الاشتراك الشهري المستحق، كشف الحساب والديون المسجلة',
            'icon'  => 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        'fleet' => [
            'id'    => 'fleet',
            'name'  => 'الوثائق وتواريخ الصلاحية',
            'short' => 'الوثائق',
            'desc'  => 'رخصة السياقة، التأمين، المراقبة التقنية، قارورة الإطفاء، GPL ومعايرة العداد',
            'icon'  => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
        ],
        'drivers' => [
            'id'    => 'drivers',
            'name'  => 'المناوبة الليلية',
            'short' => 'المناوبة',
            'desc'  => 'جدول المناوبة الليلية وحراسة الحظيرة للمؤسسة الاستشفائية بأدرار',
            'icon'  => 'M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z',
        ],
        'system' => [
            'id'    => 'system',
            'name'  => 'الإشعارات والتنبيهات',
            'short' => 'الإشعارات',
            'desc'  => 'التنبيهات المهمة، الاستحقاقات والتذكيرات الفورية',
            'icon'  => 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
        ],
    ];
} else {
    // Admin & Owner Categories
    $categories = [
        'stats' => [
            'id'    => 'stats',
            'name'  => 'لوحة الإحصائيات (Dashboard Stats)',
            'short' => 'الإحصائيات',
            'desc'  => 'المؤشرات التشغيلية والمالية الشاملة للأجهزة والأسطول والسائقين',
            'icon'  => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        ],
        'all' => [
            'id'    => 'all',
            'name'  => 'كافة الأقسام والوحدات',
            'short' => 'الكل',
            'desc'  => 'عرض موحد لكافة الوحدات والمهام التشغيلية في المنصة',
            'icon'  => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
        ],
        'fleet' => [
            'id'    => 'fleet',
            'name'  => 'الأسطول والمركبات',
            'short' => 'الأسطول',
            'desc'  => 'سجل المركبات، البطاقات الرمادية، التأمين والمراقبة التقنية',
            'icon'  => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V4.5a2.25 2.25 0 00-2.25-2.25h-4.5A2.25 2.25 0 005.25 4.5v3m11.25 0H5.25',
        ],
        'drivers' => [
            'id'    => 'drivers',
            'name'  => 'السائقون والتشغيل',
            'short' => 'السائقون',
            'desc'  => 'ملفات السائقين، عقود العمل، CNAS، المناوبة وجداول الإرسال',
            'icon'  => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
        ],
        'finance' => [
            'id'    => 'finance',
            'name'  => 'المالية والاشتراكات',
            'short' => 'المالية',
            'desc'  => 'الفوترة الشهرية، مستحقات السائقين، كشوفات الحساب والتحليلات',
            'icon'  => 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        'system' => [
            'id'    => 'system',
            'name'  => 'النظام والتنبيهات',
            'short' => 'النظام',
            'desc'  => 'مركز الإشعارات، إدارة المستخدمين، سجل التدقيق والأرشيف',
            'icon'  => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        ],
    ];
}

// Master Modules Data Source
if ($isTaxiDriver) {
    // 100% Focused Driver-Only Modules (Strictly ONLY His 5 Areas + Logout)
    $launcherModules = [
        [
            'id'          => 'driver_monthly_fee',
            'title'       => 'الدراهم والاشتراك الشهري',
            'desc'        => 'مستحقات اشتراك شهر ' . date('m/Y') . ' (' . number_format($driverSubscriptionDue) . ' دج) ووسائل الدفع',
            'url'         => url('/dashboard/debt_statement.php?user_id=' . $user['id']),
            'category'    => 'finance',
            'badge'       => $driverSubscriptionDue > 0 ? (number_format($driverSubscriptionDue) . ' دج مستحق') : 'مسوى ومريكل ✅',
            'badge_class' => $driverSubscriptionDue > 0 ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 font-bold' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 font-bold',
            'color'       => 'emerald',
            'icon_bg'     => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 group-hover:bg-emerald-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 002.25 19.5z" />',
            'visible'     => true,
            'target'      => '_self',
        ],
        [
            'id'          => 'driver_debts',
            'title'       => 'الديون والوضعية المالية',
            'desc'        => 'كشف حساب الذمة المالية، الرصيد المتبقي وسجل الدفعات والغرامات',
            'url'         => url('/dashboard/debt_statement.php?user_id=' . $user['id']),
            'category'    => 'finance',
            'badge'       => $driverTotalBalance > 0 ? (number_format($driverTotalBalance) . ' دج ذمة') : '0 دج ديون (مريكل)',
            'badge_class' => $driverTotalBalance > 0 ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 font-bold' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 font-bold',
            'color'       => 'rose',
            'icon_bg'     => 'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 group-hover:bg-rose-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />',
            'visible'     => true,
            'target'      => '_blank',
        ],
        [
            'id'          => 'driver_documents',
            'title'       => 'الوثائق وتواريخ الصلاحية',
            'desc'        => 'رخصة السياقة، الفحص الطبي، التأمين، المراقبة التقنية، GPL والعداد',
            'url'         => url('/dashboard/driver_fiche.php?id=' . $user['id']),
            'category'    => 'fleet',
            'badge'       => $driverExpiringDocs > 0 ? ($driverExpiringDocs . ' تنبيه تجديد ⚠️') : 'سارية وموثقة ✅',
            'badge_class' => $driverExpiringDocs > 0 ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 animate-pulse font-bold' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 font-bold',
            'color'       => 'amber',
            'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-slate-950',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />',
            'visible'     => true,
            'target'      => '_self',
        ],
        [
            'id'          => 'planning',
            'title'       => 'المناوبة الليلية وحراسة الحظيرة',
            'desc'        => 'جدول المداومة الليلية للمؤسسة الاستشفائية بأدرار وتواريخ المناوبة',
            'url'         => url('/dashboard/planning.php'),
            'category'    => 'drivers',
            'badge'       => $counts['planning'] . ' موقف',
            'badge_class' => 'bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300',
            'color'       => 'purple',
            'icon_bg'     => 'bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 group-hover:bg-purple-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />',
            'visible'     => true,
            'target'      => '_self',
        ],
        [
            'id'          => 'notifications',
            'title'       => 'مركز الإشعارات والتنبيهات',
            'desc'        => 'تنبيهات الاستحقاق، تذكير تجديد الوثائق والتعليمات الإدارية',
            'url'         => url('/dashboard/notifications.php'),
            'category'    => 'system',
            'badge'       => $counts['notifications'] > 0 ? ($counts['notifications'] . ' غير مقروء') : 'هادئ',
            'badge_class' => $counts['notifications'] > 0 ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 animate-pulse' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
            'color'       => 'amber',
            'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-slate-950',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />',
            'visible'     => true,
            'target'      => '_self',
        ],
        [
            'id'          => 'logout',
            'title'       => 'تسجيل الخروج',
            'desc'        => 'قفل الحساب وإنهاء الجلسة بأمان',
            'url'         => url('/auth/logout.php'),
            'category'    => 'system',
            'badge'       => 'إنهاء الجلسة',
            'badge_class' => 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300',
            'color'       => 'rose',
            'icon_bg'     => 'bg-rose-50 dark:bg-rose-950/40 text-rose-500 dark:text-rose-400 group-hover:bg-rose-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />',
            'visible'     => true,
            'target'      => '_self',
        ],
    ];
} else {
    // Admin & Fleet Owner Complete Launcher Modules
    $launcherModules = [
        [
            'id'          => 'drivers',
            'title'       => 'السائقون والبطاقات المهنية',
            'desc'        => 'دفاتر المقررة والبطاقات الفنية',
            'url'         => url('/dashboard/drivers.php'),
            'category'    => 'drivers',
            'badge'       => $counts['drivers'] . ' سائق',
            'badge_class' => 'bg-violet-50 dark:bg-violet-950/40 text-violet-700 dark:text-violet-300',
            'color'       => 'violet',
            'icon_bg'     => 'bg-violet-50 dark:bg-violet-950/40 text-violet-600 dark:text-violet-400 group-hover:bg-violet-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'vehicles',
            'title'       => 'سجل المركبات والوثائق',
            'desc'        => 'البطاقات الرمادية وتجهيزات الأمان',
            'url'         => url('/dashboard/vehicles.php'),
            'category'    => 'fleet',
            'badge'       => $counts['vehicles'] . ' مركبة',
            'badge_class' => 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300',
            'color'       => 'amber',
            'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-slate-950',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V4.5a2.25 2.25 0 00-2.25-2.25h-4.5A2.25 2.25 0 005.25 4.5v3m11.25 0H5.25" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'contracts',
            'title'       => 'عقود العمل (قانون 90-11)',
            'desc'        => 'عقود إيجار واستغلال رخصة الأجرة',
            'url'         => url('/dashboard/contracts.php'),
            'category'    => 'drivers',
            'badge'       => $counts['expiring_contracts'] > 0 ? ($counts['expiring_contracts'] . ' تنبيه تجديد ⚠️') : ($counts['contracts'] . ' موثق'),
            'badge_class' => $counts['expiring_contracts'] > 0 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200 border border-amber-200 dark:border-amber-700 animate-pulse' : 'bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300',
            'color'       => 'teal',
            'icon_bg'     => 'bg-teal-50 dark:bg-teal-950/40 text-teal-600 dark:text-teal-400 group-hover:bg-teal-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'cnas',
            'title'       => 'الضمان الاجتماعي (CNAS)',
            'desc'        => 'تصريحات الأجراء والاشتراكات',
            'url'         => url('/dashboard/cnas.php'),
            'category'    => 'drivers',
            'badge'       => $counts['cnas'] . ' تصريح',
            'badge_class' => 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300',
            'color'       => 'sky',
            'icon_bg'     => 'bg-sky-50 dark:bg-sky-950/40 text-sky-600 dark:text-sky-400 group-hover:bg-sky-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.333A12.016 12.016 0 0012 9c-2.474 0-4.78.747-6.75 2.033V21" />',
            'visible'     => $isSuperOrAdmin,
            'target'      => '_self',
        ],
        [
            'id'          => 'billing',
            'title'       => 'الفوترة والاشتراكات',
            'desc'        => 'BaridiMob / CCP والدفع الشهري',
            'url'         => url('/dashboard/billing.php'),
            'category'    => 'finance',
            'badge'       => number_format($counts['billing']) . ' دج',
            'badge_class' => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300',
            'color'       => 'emerald',
            'icon_bg'     => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 group-hover:bg-emerald-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 002.25 19.5z" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'debts',
            'title'       => 'المالية والديون',
            'desc'        => 'مستحقات السائقين وجداول التحصيل',
            'url'         => url('/dashboard/debts.php'),
            'category'    => 'finance',
            'badge'       => $counts['debts'] . ' ذمة',
            'badge_class' => $counts['debts'] > 0 ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
            'color'       => 'rose',
            'icon_bg'     => 'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 group-hover:bg-rose-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />',
            'visible'     => $isSuperOrAdmin,
            'target'      => '_self',
        ],
        [
            'id'          => 'planning',
            'title'       => 'المناوبة الليلية',
            'desc'        => 'قوائم المداومة الليلية للمؤسسة الاستشفائية بأدرار',
            'url'         => url('/dashboard/planning.php'),
            'category'    => 'drivers',
            'badge'       => $counts['planning'] . ' موقف',
            'badge_class' => 'bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300',
            'color'       => 'purple',
            'icon_bg'     => 'bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 group-hover:bg-purple-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />',
            'visible'     => true,
            'target'      => '_self',
        ],
        [
            'id'          => 'slips',
            'title'       => 'جداول الإرسال (Bordereaux)',
            'desc'        => 'بطاقات الإرسال لمديرية النقل',
            'url'         => url('/dashboard/slips.php'),
            'category'    => 'drivers',
            'badge'       => $counts['slips'] . ' إرسال',
            'badge_class' => 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300',
            'color'       => 'amber',
            'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 group-hover:bg-amber-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'traveler_reports',
            'title'       => 'الأمر بالمهمة (PV)',
            'desc'        => 'أوامر المهمة، محاضر المسافرين وقوائم الركاب',
            'url'         => url('/dashboard/traveler_reports.php'),
            'category'    => 'drivers',
            'badge'       => $counts['traveler_reports'] . ' مهمة',
            'badge_class' => 'bg-cyan-50 dark:bg-cyan-950/40 text-cyan-700 dark:text-cyan-300',
            'color'       => 'cyan',
            'icon_bg'     => 'bg-cyan-50 dark:bg-cyan-950/40 text-cyan-600 dark:text-cyan-400 group-hover:bg-cyan-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'notifications',
            'title'       => 'مركز الإشعارات والتنبيهات',
            'desc'        => 'تنبيهات الانتهاء والأوامر الإدارية',
            'url'         => url('/dashboard/notifications.php'),
            'category'    => 'system',
            'badge'       => $counts['notifications'] > 0 ? ($counts['notifications'] . ' غير مقروء') : 'هادئ',
            'badge_class' => $counts['notifications'] > 0 ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 animate-pulse' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
            'color'       => 'amber',
            'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-slate-950',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />',
            'visible'     => true,
            'target'      => '_self',
        ],
        [
            'id'          => 'users',
            'title'       => 'المستخدمون والصلاحيات',
            'desc'        => 'المشرفون، الملاك والسائقون',
            'url'         => url('/dashboard/users.php'),
            'category'    => 'system',
            'badge'       => $counts['users'] . ' مستخدم',
            'badge_class' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
            'color'       => 'slate',
            'icon_bg'     => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 group-hover:bg-slate-900 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />',
            'visible'     => $isSuperOrAdmin,
            'target'      => '_self',
        ],
        [
            'id'          => 'insurance',
            'title'       => 'التأمين والمراقبة التقنية',
            'desc'        => 'بوالص التأمين، فحص GPL والعداد',
            'url'         => url('/dashboard/insurance.php'),
            'category'    => 'fleet',
            'badge'       => $counts['expiring_docs'] > 0 ? ($counts['expiring_docs'] . ' تنبيه') : 'سارية',
            'badge_class' => $counts['expiring_docs'] > 0 ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 font-bold' : 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 font-bold',
            'color'       => 'emerald',
            'icon_bg'     => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 group-hover:bg-emerald-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'leaves',
            'title'       => 'السائق البديل والعطل',
            'desc'        => 'إنابة المركبات والإجازات الرسمية',
            'url'         => url('/dashboard/leaves.php'),
            'category'    => 'drivers',
            'badge'       => $counts['leaves'] . ' عطلة',
            'badge_class' => 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300',
            'color'       => 'indigo',
            'icon_bg'     => 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'analytics',
            'title'       => 'التحليلات والمؤشرات (KPIs)',
            'desc'        => 'توازن أعمار الديون ونسب التحصيل',
            'url'         => url('/dashboard/analytics.php'),
            'category'    => 'finance',
            'badge'       => 'Live KPIs',
            'badge_class' => 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300',
            'color'       => 'amber',
            'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-slate-950',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'verifications',
            'title'       => 'مركز التدقيق والمصادقة',
            'desc'        => 'مراجعة رخص السائقين والملفات قيد الانتظار',
            'url'         => url('/dashboard/verifications.php'),
            'category'    => 'drivers',
            'badge'       => 'تدقيق',
            'badge_class' => 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300',
            'color'       => 'amber',
            'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 group-hover:bg-amber-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />',
            'visible'     => ($isSuperOrAdmin || $isFleetOwner),
            'target'      => '_self',
        ],
        [
            'id'          => 'audit_logs',
            'title'       => 'سجل التدقيق والرقابة',
            'desc'        => 'تتبع العمليات الأمنية والمسارات',
            'url'         => url('/dashboard/audit_logs.php'),
            'category'    => 'system',
            'badge'       => $counts['audit_logs'] . ' عملية',
            'badge_class' => 'bg-orange-50 dark:bg-orange-950/40 text-orange-700 dark:text-orange-300',
            'color'       => 'orange',
            'icon_bg'     => 'bg-orange-50 dark:bg-orange-950/40 text-orange-600 dark:text-orange-400 group-hover:bg-orange-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-2.171 7.208A7.5 7.5 0 0112 19.5c-4.142 0-7.5-3.358-7.5-7.5 0-2.316 1.053-4.385 2.709-5.753m11.291 0A7.472 7.472 0 0119.5 12c0 1.637-.525 3.152-1.417 4.385M15.75 9l-3.75 3.75" />',
            'visible'     => $isSuperOrAdmin,
            'target'      => '_self',
        ],
        [
            'id'          => 'settings',
            'title'       => 'إعدادات المنصة والهوية',
            'desc'        => 'الهوية الجبائية والبيانات البنكية',
            'url'         => url('/dashboard/settings.php'),
            'category'    => 'system',
            'badge'       => 'مهيأة',
            'badge_class' => 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
            'color'       => 'slate',
            'icon_bg'     => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 group-hover:bg-slate-800 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
            'visible'     => $isSuperOrAdmin,
            'target'      => '_self',
        ],
        [
            'id'          => 'archive',
            'title'       => 'سلة المهملات والأرشيف',
            'desc'        => 'استرجاع المركبات، السائقين والبيانات',
            'url'         => url('/dashboard/archive.php'),
            'category'    => 'system',
            'badge'       => $counts['trash'] . ' محذوف',
            'badge_class' => $counts['trash'] > 0 ? 'bg-rose-100 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300 animate-pulse' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
            'color'       => 'rose',
            'icon_bg'     => 'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 group-hover:bg-rose-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />',
            'visible'     => $isSuperOrAdmin,
            'target'      => '_self',
        ],
        [
            'id'          => 'portal',
            'title'       => 'الموقع الرئيسي',
            'desc'        => 'بوابة المسافرين والطلبات',
            'url'         => url('/'),
            'category'    => 'system',
            'badge'       => 'نافذة عامة ↗',
            'badge_class' => 'bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-300',
            'color'       => 'blue',
            'icon_bg'     => 'bg-blue-50 dark:bg-blue-950/40 text-blue-500 dark:text-blue-400 group-hover:bg-blue-500 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />',
            'visible'     => true,
            'target'      => '_blank',
        ],
        [
            'id'          => 'logout',
            'title'       => 'تسجيل الخروج',
            'desc'        => 'قفل الحساب وإنهاء الجلسة',
            'url'         => url('/auth/logout.php'),
            'category'    => 'system',
            'badge'       => 'إنهاء الجلسة',
            'badge_class' => 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300',
            'color'       => 'rose',
            'icon_bg'     => 'bg-rose-50 dark:bg-rose-950/40 text-rose-500 dark:text-rose-400 group-hover:bg-rose-600 group-hover:text-white',
            'icon_svg'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />',
            'visible'     => true,
            'target'      => '_self',
        ],
    ];
}

// Active permitted modules
$activeModules = array_values(array_filter($launcherModules, fn($m) => !empty($m['visible'])));

// Compute live counts per category
$categoryCounts = [
    'stats'   => 'Live',
    'all'     => count($activeModules),
    'fleet'   => count(array_filter($activeModules, fn($m) => $m['category'] === 'fleet')),
    'drivers' => count(array_filter($activeModules, fn($m) => $m['category'] === 'drivers')),
    'finance' => count(array_filter($activeModules, fn($m) => $m['category'] === 'finance')),
    'system'  => count(array_filter($activeModules, fn($m) => $m['category'] === 'system')),
];
?>

<?php if ($isTaxiDriver): ?>
    <!-- =========================================================================
         DRIVER SPECIALIZED HIGH-PRIORITY ALERTS (الاشتراك، الديون والوثائق)
         ========================================================================= -->
    <div class="space-y-4 mb-6">
        
        <!-- 1. شِعار رانا نسالوه في كل شهر والديون (Monthly Dues & Debts Banner) -->
        <?php if ($driverHasOverdue): ?>
            <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-amber-500/15 via-rose-500/10 to-amber-500/15 border-2 border-amber-400 dark:border-amber-500 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div class="flex items-start sm:items-center gap-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-slate-950 flex items-center justify-center font-black text-2xl shrink-0 shadow">
                        📢
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-rose-600 text-white animate-pulse">إشعار المستحقات الشهرية</span>
                            <span class="text-xs font-bold text-slate-500 dark:text-slate-400">شهر <?= date('m/Y') ?></span>
                        </div>
                        <h2 class="text-base sm:text-xl font-black text-slate-900 dark:text-white mt-1">
                            رانا نسالوك هذا الشهر: <span class="text-rose-600 dark:text-rose-400 font-num"><?= number_format($driverTotalBalance > 0 ? $driverTotalBalance : $driverSubscriptionDue) ?> دج</span>
                        </h2>
                        <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                            <?php if ($driverTotalBalance > 0): ?>
                                إجمالي الذمة والديون المسجلة: <strong class="font-num text-slate-900 dark:text-white"><?= number_format($driverTotalBalance) ?> دج</strong>.
                            <?php else: ?>
                                اشتراك الشهر الحالي: <strong class="font-num text-slate-900 dark:text-white"><?= number_format($driverSubscriptionDue) ?> دج</strong>.
                            <?php endif; ?>
                            يرجى تسوية المبلغ في الآجال المحددة لتفادي تطبيق غرامات التأخير التلقائية.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0 w-full md:w-auto">
                    <a href="<?= url('/dashboard/debt_statement.php?user_id=' . $user['id']) ?>" target="_blank" 
                       class="w-full md:w-auto px-4 py-2.5 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 text-xs font-bold text-center hover:bg-slate-800 shadow transition flex items-center justify-center gap-1.5">
                        <span>كشف الحساب والديون التفصيلي</span>
                        <span>←</span>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">✅</span>
                    <div>
                        <h4 class="text-sm font-bold text-emerald-900 dark:text-emerald-200">حسابك مسوى ومريكل بالكامل لشهر <?= date('m/Y') ?>!</h4>
                        <p class="text-xs text-emerald-700 dark:text-emerald-400">لا توجد أي ديون أو مستحقات متأخرة مسجلة على ذمتك المالية. شكراً لالتزامك بالتسوية الدورية.</p>
                    </div>
                </div>
                <a href="<?= url('/dashboard/debt_statement.php?user_id=' . $user['id']) ?>" target="_blank" class="text-xs font-bold text-emerald-800 dark:text-emerald-300 underline shrink-0">معاينة كشف الحساب</a>
            </div>
        <?php endif; ?>

        <!-- 2. تنبيه الهاتف للإشعارات المهمة (الاشعارات ضرورية تطلعله فا التلفون) -->
        <?php if ($counts['notifications'] > 0): ?>
            <div id="driverMobileNotifBanner" class="p-3.5 bg-gradient-to-r from-amber-500 to-rose-600 text-white rounded-2xl shadow-md flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-white/20 backdrop-blur-xs flex items-center justify-center text-lg shrink-0">
                        🔔
                    </div>
                    <div>
                        <div class="font-black text-xs">لديك <?= $counts['notifications'] ?> إشعار وتنبيه جديد في هاتفك!</div>
                        <div class="text-[11px] text-white/90">تحديثات المستحقات، الصلاحيات وجداول العمل</div>
                    </div>
                </div>
                <a href="<?= url('/dashboard/notifications.php') ?>" class="px-3.5 py-1.5 rounded-xl bg-white text-slate-900 font-black text-xs shrink-0 shadow-sm hover:bg-slate-100">
                    عرض الآن
                </a>
            </div>
        <?php endif; ?>

        <!-- 3. تنبيه تواريخ صلاحية الوثائق القريبة من الانتهاء -->
        <?php if ($driverExpiringDocs > 0): ?>
            <div class="p-3.5 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-xl">⚠️</span>
                    <div>
                        <div class="font-bold text-xs text-rose-900 dark:text-rose-200">انتبه: لديك <?= $driverExpiringDocs ?> وثيقة قريبة الانتهاء أو منتهية الصلاحية!</div>
                        <div class="text-[11px] text-rose-700 dark:text-rose-400">تأكد من تجديد رخصة السياقة، الفحص الطبي، التأمين والمراقبة التقنية فوراً</div>
                    </div>
                </div>
                <a href="<?= url('/dashboard/driver_fiche.php?id=' . $user['id']) ?>" class="px-3 py-1.5 rounded-xl bg-rose-600 text-white font-bold text-xs shrink-0 hover:bg-rose-700">
                    فحص الوثائق
                </a>
            </div>
        <?php endif; ?>

    </div>
<?php endif; ?>

<!-- Dynamic Layout Switching Root Wrapper -->
<div id="homeLayoutWrapper" class="space-y-6 w-full min-w-0 overflow-x-hidden">

    <!-- =========================================================================
         1. GRID VIEW (Modern Responsive Cards)
         ========================================================================= -->
    <section id="layoutViewGrid" class="home-layout-container transition-opacity duration-200">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3.5">
            <?php foreach ($activeModules as $mod): ?>
                <a href="<?= $mod['url'] ?>" target="<?= $mod['target'] ?>" 
                   data-module-id="<?= $mod['id'] ?>" 
                   data-category="<?= $mod['category'] ?>" 
                   class="launcher-item launcher-card bg-white dark:bg-[#161d27] rounded-xl border border-slate-200/90 dark:border-slate-800 shadow-2xs hover:shadow-md hover:border-<?= $mod['color'] ?>-400 dark:hover:border-<?= $mod['color'] ?>-500 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
                    <div class="flex items-start justify-between">
                        <div class="p-2.5 rounded-lg <?= $mod['icon_bg'] ?> transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <?= $mod['icon_svg'] ?>
                            </svg>
                        </div>
                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $mod['badge_class'] ?> font-num">
                            <?= $mod['badge'] ?>
                        </span>
                    </div>
                    <div class="mt-4">
                        <h3 class="font-bold text-slate-900 dark:text-white text-[13px] leading-snug group-hover:text-<?= $mod['color'] ?>-600 dark:group-hover:text-<?= $mod['color'] ?>-400 transition"><?= $mod['title'] ?></h3>
                        <p class="text-[11px] text-slate-400 dark:text-slate-400 mt-0.5 line-clamp-1"><?= $mod['desc'] ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- =========================================================================
         2. SIDEBAR VIEW (Two-Pane: Interactive Sidebar Nav + Grouped Cards)
         ========================================================================= -->
    <section id="layoutViewSidebar" class="home-layout-container hidden transition-opacity duration-200">
        
        <!-- Mobile Drawer Open Trigger Bar (Small Screens) -->
        <div class="lg:hidden mb-4 flex items-center justify-between p-3.5 bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-2xs">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                <span class="text-xs font-bold text-slate-800 dark:text-slate-200" id="sidebarActiveCatMobileLabel">كافة الأقسام</span>
                <span class="text-[11px] text-slate-400 font-num">(<span id="sidebarMobileCount"><?= $categoryCounts['all'] ?></span> خدمة)</span>
            </div>
            <button type="button" onclick="toggleSidebarDrawer(true)" class="px-3.5 py-1.5 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-bold flex items-center gap-1.5 shadow-2xs hover:bg-slate-800">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <span>أقسام القائمة</span>
            </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- A. Dedicated Right Sidebar Panel (Desktop Sticky) -->
            <aside class="hidden lg:block lg:col-span-4 xl:col-span-3 sticky top-24 space-y-4">
                
                <!-- Category Navigation Card -->
                <div class="bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-2xs p-3.5">
                    <div class="px-2.5 py-2 mb-2 flex items-center justify-between border-b border-slate-100 dark:border-slate-800">
                        <span class="text-xs font-extrabold text-slate-900 dark:text-white uppercase tracking-wider"><?= $isTaxiDriver ? 'خدماتي' : 'أقسام المنصة' ?></span>
                        <span class="text-[10px] font-bold text-slate-400 font-num"><?= $categoryCounts['all'] ?> وحدة</span>
                    </div>

                    <nav class="space-y-1" aria-label="تصفح أقسام النظام">
                        <?php foreach ($categories as $catKey => $cat): ?>
                            <?php if (!$isTaxiDriver && $catKey === 'finance' && !($isSuperOrAdmin || $isFleetOwner)) continue; ?>
                            <button type="button" 
                                    onclick="setHomeCategory('<?= $catKey ?>')" 
                                    data-category-target="<?= $catKey ?>" 
                                    class="sidebar-nav-item w-full flex items-center justify-between p-2.5 rounded-xl text-right transition border <?= $catKey === 'stats' ? 'border-amber-500 bg-amber-50/80 dark:bg-amber-950/30 text-amber-950 dark:text-amber-200 font-bold' : 'border-transparent text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 font-medium' ?> group">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center shrink-0 group-hover:bg-white dark:group-hover:bg-slate-700 transition">
                                        <svg class="w-4 h-4 <?= $catKey === 'stats' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-600 dark:text-slate-300' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="<?= $cat['icon'] ?>" />
                                        </svg>
                                    </div>
                                    <div class="truncate text-xs">
                                        <?= $cat['name'] ?>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-num">
                                    <?= $categoryCounts[$catKey] ?? 0 ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <!-- Server & Wilaya Info Widget -->
                <div class="p-3.5 bg-gradient-to-br from-slate-900 to-slate-800 text-white rounded-2xl shadow-2xs space-y-2">
                    <div class="flex items-center justify-between text-[11px] font-bold">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                            الخادم متصل
                        </span>
                        <span class="font-num text-slate-400"><?= date('H:i') ?></span>
                    </div>
                    <div class="text-xs text-slate-300 pt-1 border-t border-slate-700/80 flex items-center justify-between">
                        <span>الولاية:</span>
                        <strong class="text-amber-400"><?= htmlspecialchars($user['wilaya'] ?? 'أدرار') ?></strong>
                    </div>
                    <div class="text-[11px] text-slate-400">
                        مؤسسة النقل الحضري وشبه الحضري - طاكسي نقيلو
                    </div>
                </div>

            </aside>

            <!-- B. Main Content Beside Sidebar -->
            <div class="lg:col-span-8 xl:col-span-9 space-y-4 min-w-0">
                
                <!-- Active Category Header Banner -->
                <div class="p-4 sm:p-5 bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 id="sidebarBannerTitle" class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white"><?= $isTaxiDriver ? 'لوحة إحصائياتي (Dashboard Stats)' : 'لوحة الإحصائيات (Dashboard Stats)' ?></h2>
                            <span id="sidebarVisibleBadge" class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 font-num">
                                <span id="sidebarVisibleCount">Live</span>
                            </span>
                        </div>
                        <p id="sidebarBannerDesc" class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= $isTaxiDriver ? 'ملخص وضعيّتي المالية، اشتراكاتي، ديوني، رخصة السياقة والمناوبة' : 'المؤشرات التشغيلية والمالية الشاملة للأجهزة والأسطول والسائقين' ?></p>
                    </div>

                    <div class="flex items-center gap-2 self-start sm:self-center">
                        <button type="button" onclick="setHomeLayout('grid')" class="text-xs font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white underline">عرض شبكة</button>
                        <span class="text-slate-300">•</span>
                        <button type="button" onclick="setHomeLayout('list')" class="text-xs font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white underline">عرض قائمة</button>
                    </div>
                </div>

                <!-- 1. DASHBOARD STATISTICS PANEL (DEFAULT IN SIDEBAR VIEW) -->
                <div id="sidebarStatsSection" class="space-y-4 transition-all duration-200">
                    <?php if ($isTaxiDriver): ?>
                        <!-- Driver Specialized Statistics -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3.5">
                            <!-- 1. Monthly Fee Status -->
                            <div class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs flex flex-col justify-between">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">اشتراك الشهر (<?= date('m/Y') ?>)</span>
                                    <span class="p-2 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 font-bold">
                                        💳
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <div class="text-2xl font-black text-slate-900 dark:text-white font-num" dir="ltr">
                                        <?= number_format($driverSubscriptionDue) ?> <span class="text-xs font-bold text-slate-500">دج</span>
                                    </div>
                                    <div class="mt-1 flex items-center gap-1.5 text-[11px] font-bold <?= $driverSubscriptionDue > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' ?>">
                                        <span><?= $driverSubscriptionDue > 0 ? '● مستحق الدفع' : '✔ تم التسديد بالكامل' ?></span>
                                    </div>
                                </div>
                                <a href="<?= url('/dashboard/debts.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-amber-600 dark:text-amber-400 hover:underline flex items-center justify-between">
                                    <span>كشف الحساب والوصولات</span>
                                    <span>←</span>
                                </a>
                            </div>

                            <!-- 2. Total Balance / Debt -->
                            <div class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs flex flex-col justify-between">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">إجمالي الذمة والديون</span>
                                    <span class="p-2 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 font-bold">
                                        ⚖️
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <div class="text-2xl font-black <?= $driverTotalBalance > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' ?> font-num" dir="ltr">
                                        <?= number_format($driverTotalBalance) ?> <span class="text-xs font-bold text-slate-500">دج</span>
                                    </div>
                                    <div class="mt-1 text-[11px] font-bold text-slate-500">
                                        <?= $driverTotalBalance > 0 ? 'مستحقات غير مسواة بذمتك' : 'وضعية مالية سليمة ومبرأة' ?>
                                    </div>
                                </div>
                                <a href="<?= url('/dashboard/debts.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline flex items-center justify-between">
                                    <span>تصفية المستحقات</span>
                                    <span>←</span>
                                </a>
                            </div>

                            <!-- 3. Expiring Documents -->
                            <div class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs flex flex-col justify-between">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">الوثائق والصلاحيات</span>
                                    <span class="p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 font-bold">
                                        🪪
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <div class="text-2xl font-black text-slate-900 dark:text-white font-num">
                                        <?= $driverExpiringDocs ?> <span class="text-xs font-bold text-slate-500">وثائق قريبة الانتهاء</span>
                                    </div>
                                    <div class="mt-1 text-[11px] font-bold <?= $driverExpiringDocs > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' ?>">
                                        <?= $driverExpiringDocs > 0 ? 'تتطلب التجديد لتفادي التوقيف' : 'كافة الوثائق والرخصة سارية' ?>
                                    </div>
                                </div>
                                <a href="<?= url('/dashboard/driver_fiche.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-blue-600 dark:text-blue-400 hover:underline flex items-center justify-between">
                                    <span>فحص ملف الوثائق</span>
                                    <span>←</span>
                                </a>
                            </div>

                            <!-- 4. Assigned Vehicle & Night Watch -->
                            <div class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs flex flex-col justify-between">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">مركبة العمل والمناوبة</span>
                                    <span class="p-2 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 font-bold">
                                        🚕
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <div class="text-lg font-black text-slate-900 dark:text-white font-num truncate" title="<?= htmlspecialchars($driverVehicleInfo['matricule'] ?? 'غير مسندة') ?>">
                                        <?= htmlspecialchars($driverVehicleInfo['matricule'] ?? 'مركبة غير مسندة') ?>
                                    </div>
                                    <div class="mt-1 text-[11px] font-bold text-indigo-600 dark:text-indigo-400">
                                        <?= htmlspecialchars(($driverVehicleInfo['brand'] ?? '') . ' ' . ($driverVehicleInfo['model'] ?? '')) ?>
                                    </div>
                                </div>
                                <a href="<?= url('/dashboard/planning.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-purple-600 dark:text-purple-400 hover:underline flex items-center justify-between">
                                    <span>جدول حراسة الحظيرة والمناوبة</span>
                                    <span>←</span>
                                </a>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Executive Admin Dashboard Statistics -->
                        <div class="space-y-4">
                            
                            <!-- 1. Four Main KPI Cards Grid -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3.5">
                                
                                <!-- KPI 1: Fleet & Readiness -->
                                <div class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs hover:shadow-md transition flex flex-col justify-between group">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400">جاهزية الأسطول</span>
                                        <div class="w-9 h-9 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V4.5a2.25 2.25 0 00-2.25-2.25h-4.5A2.25 2.25 0 005.25 4.5v3m11.25 0H5.25" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white font-num"><?= $activeVehiclesCount ?></span>
                                            <span class="text-xs font-bold text-slate-400 font-num">/ <?= $counts['vehicles'] ?> مركبة</span>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between text-[11px]">
                                            <span class="text-emerald-600 dark:text-emerald-400 font-bold"><?= $counts['vehicles'] > 0 ? round(($activeVehiclesCount / $counts['vehicles']) * 100) : 0 ?>% جاهزية</span>
                                            <span class="text-amber-600 dark:text-amber-400 font-bold font-num"><?= $counts['expiring_docs'] ?> تنبيه وثائق</span>
                                        </div>
                                    </div>
                                    <a href="<?= url('/dashboard/vehicles.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-blue-600 dark:text-blue-400 hover:text-blue-700 flex items-center justify-between">
                                        <span>سجل وحركات المركبات</span>
                                        <span class="text-[10px]">←</span>
                                    </a>
                                </div>

                                <!-- KPI 2: Drivers & 90-11 Contracts -->
                                <div class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs hover:shadow-md transition flex flex-col justify-between group">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400">السائقون والعقود</span>
                                        <div class="w-9 h-9 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white font-num"><?= $counts['drivers'] ?></span>
                                            <span class="text-xs font-bold text-slate-400">سائق نشط</span>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between text-[11px]">
                                            <span class="text-slate-600 dark:text-slate-300 font-bold font-num"><?= $counts['contracts'] ?> عقد (90-11)</span>
                                            <span class="text-rose-600 dark:text-rose-400 font-bold font-num"><?= $counts['expiring_contracts'] ?> ينتهي قريباً</span>
                                        </div>
                                    </div>
                                    <a href="<?= url('/dashboard/drivers.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 flex items-center justify-between">
                                        <span>ملفات السائقين وعقودهم</span>
                                        <span class="text-[10px]">←</span>
                                    </a>
                                </div>

                                <!-- KPI 3: Financial Collection & Recovery -->
                                <div class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs hover:shadow-md transition flex flex-col justify-between group">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400">التحصيل والاسترداد</span>
                                        <div class="w-9 h-9 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-2xl sm:text-3xl font-black text-amber-600 dark:text-amber-400 font-num" dir="ltr"><?= number_format($counts['billing']) ?></span>
                                            <span class="text-xs font-bold text-slate-500">دج</span>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between text-[11px]">
                                            <span class="text-emerald-600 dark:text-emerald-400 font-bold font-num"><?= $recoveryRate ?>% نسبة التحصيل</span>
                                            <span class="text-rose-600 dark:text-rose-400 font-bold font-num"><?= $counts['debts'] ?> مدين</span>
                                        </div>
                                    </div>
                                    <a href="<?= url('/dashboard/debts.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-amber-600 dark:text-amber-400 hover:text-amber-700 flex items-center justify-between">
                                        <span>موازين الديون والتحصيل</span>
                                        <span class="text-[10px]">←</span>
                                    </a>
                                </div>

                                <!-- KPI 4: Night Watch & Transmission Slips -->
                                <div class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs hover:shadow-md transition flex flex-col justify-between group">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400">المناوبة وبطاقات الإرسال</span>
                                        <div class="w-9 h-9 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white font-num"><?= $counts['planning'] ?></span>
                                            <span class="text-xs font-bold text-slate-400">مركبة بالحراسة</span>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between text-[11px]">
                                            <span class="text-indigo-600 dark:text-indigo-400 font-bold font-num"><?= $counts['slips'] ?> بطاقة إرسال</span>
                                            <span class="text-slate-500 font-bold font-num"><?= $counts['leaves'] ?> سائق بديل</span>
                                        </div>
                                    </div>
                                    <a href="<?= url('/dashboard/planning.php') ?>" class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 text-xs font-bold text-purple-600 dark:text-purple-400 hover:text-purple-700 flex items-center justify-between">
                                        <span>جدول الحراسة الليلية</span>
                                        <span class="text-[10px]">←</span>
                                    </a>
                                </div>

                            </div>

                            <!-- 2. Dual Deep Breakdown Cards -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                
                                <!-- CNAS & Legal Compliance -->
                                <div class="p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-4">
                                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800/80 pb-3">
                                        <div class="flex items-center gap-2.5">
                                            <span class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold text-sm">🛡️</span>
                                            <div>
                                                <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">الامتثال للضمان الاجتماعي (CNAS)</h3>
                                                <p class="text-[11px] text-slate-400">التصريحات بالعمال، الاشتراكات الفصلية ونسب المشغل والأجير</p>
                                            </div>
                                        </div>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300">ممتثل رسمياً</span>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                                            <div class="text-[11px] font-bold text-slate-400">إجمالي الاشتراكات المسددة</div>
                                            <div class="text-base sm:text-lg font-black text-emerald-600 dark:text-emerald-400 font-num mt-1" dir="ltr">
                                                <?= number_format($cnasCotisationsPaid) ?> دج
                                            </div>
                                        </div>
                                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                                            <div class="text-[11px] font-bold text-slate-400">السائقون المصرح بهم</div>
                                            <div class="text-base sm:text-lg font-black text-slate-900 dark:text-white font-num mt-1">
                                                <?= $counts['cnas'] ?> سائق
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pt-1 flex items-center justify-between text-xs font-bold">
                                        <span class="text-slate-500">تغطية تأمينية واجتماعية كاملة 100%</span>
                                        <a href="<?= url('/dashboard/cnas.php') ?>" class="text-emerald-600 dark:text-emerald-400 hover:underline flex items-center gap-1">
                                            <span>فتح سجل CNAS الشامل</span>
                                            <span>←</span>
                                        </a>
                                    </div>
                                </div>

                                <!-- Financial Health & Debt Balance -->
                                <div class="p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-4">
                                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800/80 pb-3">
                                        <div class="flex items-center gap-2.5">
                                            <span class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-sm">📊</span>
                                            <div>
                                                <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">التوازن المالي والديون المتبقية</h3>
                                                <p class="text-[11px] text-slate-400">مراقبة السيولة النقدية، الأقساط الشهرية والذمم المالية</p>
                                            </div>
                                        </div>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300">موازنة حية</span>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                                            <div class="text-[11px] font-bold text-slate-400">الديون المتبقية بذمة السائقين</div>
                                            <div class="text-base sm:text-lg font-black text-rose-600 dark:text-rose-400 font-num mt-1" dir="ltr">
                                                <?= number_format($totalDebtsBalance) ?> دج
                                            </div>
                                        </div>
                                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                                            <div class="text-[11px] font-bold text-slate-400">إجمالي الفوترة الشهرية</div>
                                            <div class="text-base sm:text-lg font-black text-slate-900 dark:text-white font-num mt-1" dir="ltr">
                                                <?= number_format($totalBilledAmount) ?> دج
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pt-1 flex items-center justify-between text-xs font-bold">
                                        <span class="text-slate-500">معدل التحصيل: <strong class="text-emerald-600 dark:text-emerald-400 font-num"><?= $recoveryRate ?>%</strong></span>
                                        <a href="<?= url('/dashboard/debts.php') ?>" class="text-amber-600 dark:text-amber-400 hover:underline flex items-center gap-1">
                                            <span>إدارة ديون واشتراكات السائقين</span>
                                            <span>←</span>
                                        </a>
                                    </div>
                                </div>

                            </div>

                            <!-- 3. Fast Operational Shortcuts Bar -->
                            <div class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs">
                                <div class="text-xs font-extrabold text-slate-700 dark:text-slate-300 mb-3 flex items-center gap-2">
                                    <span>⚡</span>
                                    <span>إجراءات تشغيلية سريعة (Fast Shortcuts)</span>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2.5">
                                    <a href="<?= url('/dashboard/drivers.php?action=create') ?>" class="p-2.5 rounded-xl bg-slate-50 hover:bg-emerald-50 dark:bg-slate-800/60 dark:hover:bg-emerald-950/40 text-slate-700 dark:text-slate-200 hover:text-emerald-700 dark:hover:text-emerald-300 border border-slate-200/80 dark:border-slate-700 text-xs font-bold text-center transition flex flex-col items-center gap-1.5">
                                        <span>➕ إضافة سائق</span>
                                    </a>
                                    <a href="<?= url('/dashboard/vehicles.php?action=create') ?>" class="p-2.5 rounded-xl bg-slate-50 hover:bg-blue-50 dark:bg-slate-800/60 dark:hover:bg-blue-950/40 text-slate-700 dark:text-slate-200 hover:text-blue-700 dark:hover:text-blue-300 border border-slate-200/80 dark:border-slate-700 text-xs font-bold text-center transition flex flex-col items-center gap-1.5">
                                        <span>🚗 إضافة مركبة</span>
                                    </a>
                                    <a href="<?= url('/dashboard/transmission_slips.php') ?>" class="p-2.5 rounded-xl bg-slate-50 hover:bg-purple-50 dark:bg-slate-800/60 dark:hover:bg-purple-950/40 text-slate-700 dark:text-slate-200 hover:text-purple-700 dark:hover:text-purple-300 border border-slate-200/80 dark:border-slate-700 text-xs font-bold text-center transition flex flex-col items-center gap-1.5">
                                        <span>📑 بطاقة إرسال</span>
                                    </a>
                                    <a href="<?= url('/dashboard/planning.php') ?>" class="p-2.5 rounded-xl bg-slate-50 hover:bg-indigo-50 dark:bg-slate-800/60 dark:hover:bg-indigo-950/40 text-slate-700 dark:text-slate-200 hover:text-indigo-700 dark:hover:text-indigo-300 border border-slate-200/80 dark:border-slate-700 text-xs font-bold text-center transition flex flex-col items-center gap-1.5">
                                        <span>🌙 جدول المناوبة</span>
                                    </a>
                                    <a href="<?= url('/dashboard/audit_logs.php') ?>" class="p-2.5 rounded-xl bg-slate-50 hover:bg-amber-50 dark:bg-slate-800/60 dark:hover:bg-amber-950/40 text-slate-700 dark:text-slate-200 hover:text-amber-700 dark:hover:text-amber-300 border border-slate-200/80 dark:border-slate-700 text-xs font-bold text-center transition flex flex-col items-center gap-1.5">
                                        <span>🛡️ مركز التدقيق</span>
                                    </a>
                                </div>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>

                <!-- 2. MODULE CARDS BESIDE SIDEBAR (WHEN BROWSING SPECIFIC CATEGORIES) -->
                <div id="sidebarCardsSection" class="hidden transition-all duration-200">
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3.5">
                        <?php foreach ($activeModules as $mod): ?>
                            <a href="<?= $mod['url'] ?>" target="<?= $mod['target'] ?>" 
                               data-module-id="<?= $mod['id'] ?>" 
                               data-category="<?= $mod['category'] ?>" 
                               class="launcher-item launcher-card bg-white dark:bg-[#161d27] rounded-xl border border-slate-200/90 dark:border-slate-800 shadow-2xs hover:shadow-md hover:border-<?= $mod['color'] ?>-400 dark:hover:border-<?= $mod['color'] ?>-500 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
                                <div class="flex items-start justify-between">
                                    <div class="p-2.5 rounded-lg <?= $mod['icon_bg'] ?> transition">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <?= $mod['icon_svg'] ?>
                                        </svg>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $mod['badge_class'] ?> font-num">
                                        <?= $mod['badge'] ?>
                                    </span>
                                </div>
                                <div class="mt-4">
                                    <h3 class="font-bold text-slate-900 dark:text-white text-[13px] leading-snug group-hover:text-<?= $mod['color'] ?>-600 dark:group-hover:text-<?= $mod['color'] ?>-400 transition"><?= $mod['title'] ?></h3>
                                    <p class="text-[11px] text-slate-400 dark:text-slate-400 mt-0.5 line-clamp-1"><?= $mod['desc'] ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

        </div>

        <!-- Mobile Drawer BackDrop & Panel -->
        <div id="sidebarDrawerBackdrop" onclick="toggleSidebarDrawer(false)" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 lg:hidden transition-opacity"></div>
        <aside id="sidebarDrawerPanel" class="hidden fixed top-0 right-0 h-full w-80 max-w-[85vw] bg-white dark:bg-[#161d27] z-50 shadow-2xl p-5 overflow-y-auto transform translate-x-full transition-transform duration-200 lg:hidden">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                    <h3 class="font-bold text-slate-900 dark:text-white text-sm"><?= $isTaxiDriver ? 'خدمات السائق' : 'أقسام المنصة' ?></h3>
                </div>
                <button type="button" onclick="toggleSidebarDrawer(false)" class="p-1 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-white">✕</button>
            </div>
            
            <nav class="space-y-1.5 mt-4" aria-label="أقسام المنصة للجوال">
                <?php foreach ($categories as $catKey => $cat): ?>
                    <?php if (!$isTaxiDriver && $catKey === 'finance' && !($isSuperOrAdmin || $isFleetOwner)) continue; ?>
                    <button type="button" 
                            onclick="setHomeCategory('<?= $catKey ?>'); toggleSidebarDrawer(false);" 
                            data-drawer-category="<?= $catKey ?>" 
                            class="sidebar-nav-item w-full flex items-center justify-between p-3 rounded-xl text-right transition border <?= $catKey === 'stats' ? 'border-amber-500 bg-amber-50/80 dark:bg-amber-950/30 text-amber-950 dark:text-amber-200 font-bold' : 'border-transparent text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                        <span class="text-xs"><?= $cat['name'] ?></span>
                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-num"><?= $categoryCounts[$catKey] ?? 0 ?></span>
                    </button>
                <?php endforeach; ?>
            </nav>
        </aside>

    </section>

    <!-- =========================================================================
         3. LIST VIEW (Clean Horizontal Rows with KPIs & Actions)
         ========================================================================= -->
    <section id="layoutViewList" class="home-layout-container hidden transition-opacity duration-200">
        <div class="bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-2xs overflow-hidden">
            
            <!-- Table Header -->
            <div class="hidden sm:grid grid-cols-12 gap-4 px-5 py-3 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-100 dark:border-slate-800 text-xs font-bold text-slate-400 dark:text-slate-400">
                <div class="col-span-6"><?= $isTaxiDriver ? 'الخدمة والمهام الخاصة بالسائق' : 'الوحدة والمهام التشغيلية' ?></div>
                <div class="col-span-2 text-center">القسم</div>
                <div class="col-span-2 text-center">الحالة والمؤشر</div>
                <div class="col-span-2 text-left">الإجراء</div>
            </div>

            <!-- List Rows -->
            <div class="divide-y divide-slate-100 dark:divide-slate-800/80">
                <?php foreach ($activeModules as $mod): ?>
                    <div data-module-id="<?= $mod['id'] ?>" 
                         data-category="<?= $mod['category'] ?>" 
                         class="launcher-item launcher-row p-4 hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors flex flex-col sm:grid sm:grid-cols-12 sm:items-center gap-3 group">
                        
                        <!-- Col 1: Icon + Title + Description -->
                        <div class="sm:col-span-6 flex items-center gap-3.5 min-w-0">
                            <div class="w-10 h-10 rounded-xl <?= $mod['icon_bg'] ?> flex items-center justify-center shrink-0 transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <?= $mod['icon_svg'] ?>
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <a href="<?= $mod['url'] ?>" target="<?= $mod['target'] ?>" class="font-bold text-slate-900 dark:text-white text-[13px] hover:text-amber-600 dark:hover:text-amber-400 transition truncate block">
                                    <?= $mod['title'] ?>
                                </a>
                                <p class="text-xs text-slate-400 dark:text-slate-400 truncate mt-0.5"><?= $mod['desc'] ?></p>
                            </div>
                        </div>

                        <!-- Col 2: Category Badge -->
                        <div class="sm:col-span-2 flex items-center sm:justify-center">
                            <span class="px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                <?= $categories[$mod['category']]['short'] ?? $mod['category'] ?>
                            </span>
                        </div>

                        <!-- Col 3: Live KPI Metric Badge -->
                        <div class="sm:col-span-2 flex items-center sm:justify-center">
                            <span class="px-2.5 py-1 rounded-lg text-[11px] font-bold <?= $mod['badge_class'] ?> font-num">
                                <?= $mod['badge'] ?>
                            </span>
                        </div>

                        <!-- Col 4: Action Button -->
                        <div class="sm:col-span-2 flex items-center justify-end">
                            <a href="<?= $mod['url'] ?>" target="<?= $mod['target'] ?>" 
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 text-xs font-bold hover:bg-slate-800 dark:hover:bg-slate-100 shadow-2xs hover:shadow transition">
                                <span>فتح</span>
                                <span class="text-[10px]">←</span>
                            </a>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

    <!-- =========================================================================
         4. EMPTY SEARCH & FILTER STATE
         ========================================================================= -->
    <div id="noResultsState" class="hidden py-16 text-center space-y-3 bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-2xs">
        <div class="w-12 h-12 mx-auto rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 text-xl font-bold">
            🔍
        </div>
        <div class="text-sm font-bold text-slate-800 dark:text-slate-100">لم يتم العثور على أي خدمة أو بند مطابق لبحثك</div>
        <p class="text-xs text-slate-400 dark:text-slate-400">تأكد من كتابة الكلمة بشكل صحيح، أو اضغط زر "الكل" لعرض كافة الخدمات</p>
        <button type="button" onclick="resetLauncherFilter()" class="mt-2 px-4 py-2 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 font-bold text-xs hover:bg-slate-800 transition">
            إعادة ضبط البحث والتصفيات
        </button>
    </div>

</div>

<!-- =========================================================================
     5. DYNAMIC LAYOUT & CATEGORY FILTERING CLIENT ENGINE
     ========================================================================= -->
<script>
    (function() {
        const STORAGE_KEY = 'naguilou_home_layout';
        let currentLayout = localStorage.getItem(STORAGE_KEY) || 'grid';
        let currentCategory = (currentLayout === 'sidebar' ? 'stats' : 'all');

        const categoryMeta = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;

        // Switch Layout (Grid, Sidebar, List)
        window.setHomeLayout = function(layout) {
            if (!['grid', 'sidebar', 'list'].includes(layout)) {
                layout = 'grid';
            }
            currentLayout = layout;
            try {
                localStorage.setItem(STORAGE_KEY, layout);
            } catch (e) {
                console.warn('Storage unavailable', e);
            }

            // When entering sidebar view, default first page to stats
            if (layout === 'sidebar' && (currentCategory === 'all' || !currentCategory)) {
                currentCategory = 'stats';
            } else if (layout !== 'sidebar' && currentCategory === 'stats') {
                currentCategory = 'all';
            }

            // Toggle Containers
            const views = {
                grid: document.getElementById('layoutViewGrid'),
                sidebar: document.getElementById('layoutViewSidebar'),
                list: document.getElementById('layoutViewList')
            };

            Object.keys(views).forEach(k => {
                const el = views[k];
                if (el) {
                    if (k === layout) {
                        el.classList.remove('hidden');
                    } else {
                        el.classList.add('hidden');
                    }
                }
            });

            // Update All Switcher Buttons (App Bar & Sub-Ribbon)
            document.querySelectorAll('.home-layout-btn').forEach(btn => {
                const target = btn.getAttribute('data-layout-target');
                if (target === layout) {
                    btn.classList.remove('text-slate-600', 'dark:text-slate-300', 'hover:text-slate-900', 'dark:hover:text-white');
                    btn.classList.add('bg-slate-900', 'text-white', 'dark:bg-white', 'dark:text-slate-900', 'shadow-2xs');
                } else {
                    btn.classList.remove('bg-slate-900', 'text-white', 'dark:bg-white', 'dark:text-slate-900', 'shadow-2xs');
                    btn.classList.add('text-slate-600', 'dark:text-slate-300', 'hover:text-slate-900', 'dark:hover:text-white');
                }
            });

            setHomeCategory(currentCategory);
        };

        // Switch Category (Content Selection)
        window.setHomeCategory = function(cat) {
            currentCategory = cat || (currentLayout === 'sidebar' ? 'stats' : 'all');

            // If user selects stats while in grid/list, switch to sidebar layout to display stats page
            if (currentCategory === 'stats' && currentLayout !== 'sidebar') {
                setHomeLayout('sidebar');
                return;
            }

            // 1. Sync App Bar Dropdown
            const appBarSelect = document.getElementById('appBarCategorySelect');
            if (appBarSelect && appBarSelect.value !== currentCategory) {
                appBarSelect.value = currentCategory;
            }

            // 2. Sync Mobile Dropdown
            const mobileSelect = document.getElementById('mobileCategorySelect');
            if (mobileSelect && mobileSelect.value !== currentCategory) {
                mobileSelect.value = currentCategory;
            }

            // 3. Sync Ribbon Filter Buttons
            document.querySelectorAll('.category-btn').forEach(btn => {
                const filter = btn.getAttribute('data-filter');
                if (filter === currentCategory) {
                    btn.classList.remove('bg-slate-100', 'dark:bg-slate-800', 'text-slate-600', 'dark:text-slate-300', 'hover:bg-slate-200');
                    btn.classList.add('bg-slate-900', 'dark:bg-white', 'text-white', 'dark:text-slate-900');
                } else {
                    btn.classList.remove('bg-slate-900', 'dark:bg-white', 'text-white', 'dark:text-slate-900');
                    btn.classList.add('bg-slate-100', 'dark:bg-slate-800', 'text-slate-600', 'dark:text-slate-300', 'hover:bg-slate-200');
                }
            });

            // 4. Sync Sidebar Panel Items
            document.querySelectorAll('.sidebar-nav-item').forEach(item => {
                const target = item.getAttribute('data-category-target') || item.getAttribute('data-drawer-category');
                if (target === currentCategory) {
                    item.classList.remove('border-transparent', 'hover:bg-slate-50', 'dark:hover:bg-slate-800');
                    item.classList.add('border-amber-500', 'bg-amber-50/80', 'dark:bg-amber-950/30', 'text-amber-950', 'dark:text-amber-200', 'font-bold');
                } else {
                    item.classList.remove('border-amber-500', 'bg-amber-50/80', 'dark:bg-amber-950/30', 'text-amber-950', 'dark:text-amber-200', 'font-bold');
                    item.classList.add('border-transparent', 'hover:bg-slate-50', 'dark:hover:bg-slate-800');
                }
            });

            // 5. Toggle Stats Section vs Cards Section in Sidebar View
            const statsSection = document.getElementById('sidebarStatsSection');
            const cardsSection = document.getElementById('sidebarCardsSection');
            if (statsSection && cardsSection) {
                if (currentCategory === 'stats') {
                    statsSection.classList.remove('hidden');
                    cardsSection.classList.add('hidden');
                } else {
                    statsSection.classList.add('hidden');
                    cardsSection.classList.remove('hidden');
                }
            }

            // 6. Update Sidebar Banner Labels
            const meta = categoryMeta[currentCategory] || categoryMeta['all'];
            const bannerTitle = document.getElementById('sidebarBannerTitle');
            const bannerDesc = document.getElementById('sidebarBannerDesc');
            const mobileLabel = document.getElementById('sidebarActiveCatMobileLabel');
            if (bannerTitle) bannerTitle.textContent = meta.name;
            if (bannerDesc) bannerDesc.textContent = meta.desc;
            if (mobileLabel) mobileLabel.textContent = meta.short || meta.name;

            filterHomeModules();
        };

        // Filter Modules by Category & Live Search
        window.filterHomeModules = function() {
            const searchInput = document.getElementById('launcherSearch');
            const query = (searchInput ? searchInput.value.toLowerCase().trim() : '');

            // If user searches while on stats in sidebar view, switch to 'all' so modules are searchable
            if (query && currentLayout === 'sidebar' && currentCategory === 'stats') {
                setHomeCategory('all');
                return;
            }

            // If on stats in sidebar view
            if (currentLayout === 'sidebar' && currentCategory === 'stats') {
                const sidebarCountEl = document.getElementById('sidebarVisibleCount');
                const sidebarMobileCountEl = document.getElementById('sidebarMobileCount');
                if (sidebarCountEl) sidebarCountEl.textContent = 'Live';
                if (sidebarMobileCountEl) sidebarMobileCountEl.textContent = 'Live';
                const noResultsState = document.getElementById('noResultsState');
                if (noResultsState) noResultsState.classList.add('hidden');
                return;
            }

            // Target the active layout container
            const activeId = currentLayout === 'grid' ? 'layoutViewGrid' : (currentLayout === 'sidebar' ? 'layoutViewSidebar' : 'layoutViewList');
            const container = document.getElementById(activeId);
            if (!container) return;

            const items = container.querySelectorAll('.launcher-item');
            let visibleCount = 0;

            items.forEach(item => {
                const cat = item.getAttribute('data-category');
                const title = (item.querySelector('h3, a')?.textContent || '').toLowerCase();
                const desc = (item.querySelector('p')?.textContent || '').toLowerCase();

                const matchesCat = (currentCategory === 'all' || cat === currentCategory);
                const matchesQuery = (!query || title.includes(query) || desc.includes(query));

                if (matchesCat && matchesQuery) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            // Update Counter in Sidebar View
            const sidebarCountEl = document.getElementById('sidebarVisibleCount');
            const sidebarMobileCountEl = document.getElementById('sidebarMobileCount');
            if (sidebarCountEl) sidebarCountEl.textContent = visibleCount;
            if (sidebarMobileCountEl) sidebarMobileCountEl.textContent = visibleCount;

            // Empty State
            const noResultsState = document.getElementById('noResultsState');
            if (noResultsState) {
                if (visibleCount === 0) {
                    noResultsState.classList.remove('hidden');
                } else {
                    noResultsState.classList.add('hidden');
                }
            }
        };

        // Mobile Drawer Controller
        window.toggleSidebarDrawer = function(open) {
            const backdrop = document.getElementById('sidebarDrawerBackdrop');
            const panel = document.getElementById('sidebarDrawerPanel');
            if (!backdrop || !panel) return;

            if (open) {
                backdrop.classList.remove('hidden');
                panel.classList.remove('hidden');
                requestAnimationFrame(() => {
                    panel.classList.remove('translate-x-full');
                });
            } else {
                panel.classList.add('translate-x-full');
                setTimeout(() => {
                    backdrop.classList.add('hidden');
                    panel.classList.add('hidden');
                }, 200);
            }
        };

        // Auto-close mobile drawer on desktop resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                const backdrop = document.getElementById('sidebarDrawerBackdrop');
                const panel = document.getElementById('sidebarDrawerPanel');
                if (backdrop) backdrop.classList.add('hidden');
                if (panel) {
                    panel.classList.add('translate-x-full');
                    panel.classList.add('hidden');
                }
            }
        });

        // Global Reset Filter
        window.resetLauncherFilter = function() {
            const searchInput = document.getElementById('launcherSearch');
            if (searchInput) searchInput.value = '';
            setHomeCategory('all');
        };

        // Wire search input live listener
        const searchInput = document.getElementById('launcherSearch');
        if (searchInput) {
            searchInput.addEventListener('input', filterHomeModules);
        }

        // Initialize saved layout on page ready
        document.addEventListener('DOMContentLoaded', function() {
            setHomeLayout(currentLayout);
        });

        // Run immediately in case DOM is already ready
        setHomeLayout(currentLayout);
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
