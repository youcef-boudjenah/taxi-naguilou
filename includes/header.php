<?php
// includes/header.php - Clean Unified Dashboard Layout & Design System
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Fetch urgent alert count for document expirations
$pdo = getDBConnection();
$alertCount = 0;
if ($pdo) {
    try {
        $stmtAlerts = $pdo->query("
            SELECT COUNT(*) FROM vehicles 
            WHERE (technical_control_expiry IS NOT NULL AND technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
               OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
               OR (gpl_expiry IS NOT NULL AND gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
               OR (saccuriometre_expiry IS NOT NULL AND saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
        ");
        $alertCount = (int)$stmtAlerts->fetchColumn();
    } catch (Exception $e) {}
}

// Handle Mark Notification as Read
if (isset($_GET['mark_notif_read']) && $pdo) {
    if ($_GET['mark_notif_read'] === 'all') {
        $pdo->query("UPDATE notifications SET is_read = 1");
    } else {
        $nId = intval($_GET['mark_notif_read']);
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$nId]);
    }
    $targetUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $targetUrl");
    exit;
}

// Fetch unread notifications & recent list
$unreadNotifCount = 0;
$recentNotifs = [];
if ($pdo) {
    $unreadNotifCount = getUnreadNotificationsCount($pdo);
    $recentNotifs = getRecentNotifications($pdo, 5);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'لوحة التحكم' ?> | تاكسي نقيلو</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        amber: {
                            50: '#fffbeb',
                            100: '#fef3c7',
                            200: '#fde68a',
                            300: '#fcd34d',
                            400: '#fbbf24',
                            500: '#f59e0b',
                            600: '#d97706',
                            700: '#b45309',
                            800: '#92400e',
                            900: '#78350f',
                            950: '#451a03',
                        }
                    },
                    fontFamily: {
                        sans: ['Cairo', 'sans-serif'],
                        num: ['Plus Jakarta Sans', 'Cairo', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Cairo', sans-serif;
            font-feature-settings: "tnum";
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
        }
        ::selection {
            background-color: #fef3c7;
            color: #92400e;
        }
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .nav-item-active {
            background-color: #f59e0b;
            color: #020617;
            font-weight: 800;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .nav-item-inactive {
            color: #475569;
        }
        .nav-item-inactive:hover {
            background-color: #f8fafc;
            color: #0f172a;
        }
    </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased flex flex-col md:flex-row overflow-x-hidden">

    <!-- Mobile Drawer Backdrop -->
    <div id="mobileDrawerBackdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-40 hidden md:hidden transition-opacity"></div>

    <!-- Sidebar Navigation -->
    <aside id="sidebarNav" class="w-72 bg-white border-l border-slate-200 shrink-0 flex flex-col justify-between fixed md:sticky top-0 h-screen z-50 md:z-20 transition-transform duration-300 ease-in-out -translate-x-full md:translate-x-0 overflow-y-auto">
        <div>
            <!-- Sidebar Header / Brand -->
            <div class="h-16 px-5 border-b border-slate-100 flex items-center justify-between">
                <a href="<?= url('/dashboard/index.php') ?>" class="flex items-center gap-3">
                    <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-9 h-9 object-contain rounded-xl bg-amber-50 p-1 border border-amber-200 shadow-2xs shrink-0">
                    <div>
                        <div class="text-base font-black text-slate-900 tracking-tight leading-none">تاكسي <span class="text-amber-600">نقيلو</span></div>
                        <span class="text-[9px] text-slate-400 font-extrabold block mt-0.5 uppercase tracking-wider">EURL TAXI NAGUILOU</span>
                    </div>
                </a>
                <button onclick="toggleMobileSidebar()" class="md:hidden p-1.5 text-slate-400 hover:text-slate-600 rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- User Status Pill -->
            <div class="p-3 mx-3 my-3 rounded-xl bg-slate-50 border border-slate-100 flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-amber-500 text-slate-950 font-black flex items-center justify-center text-xs shadow-2xs shrink-0 font-num">
                    <?= mb_substr($user['name'], 0, 1) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-bold text-slate-900 truncate"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="text-[10px] text-slate-400 font-medium">
                        <?= $user['role'] === 'super_admin' ? 'مدير المنصة العام' : ($user['role'] === 'owner' ? 'مالك مركبة' : 'سائق معتمد') ?>
                    </div>
                </div>
                <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0" title="متصل"></span>
            </div>

            <!-- Navigation Links -->
            <nav class="px-3 py-1 space-y-0.5 text-xs font-bold">
                
                <a href="<?= url('/dashboard/index.php') ?>" class="flex items-center justify-between px-3 py-2 rounded-xl transition <?= $currentPage === 'index' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        <span>لوحة التحكم الرئيسية</span>
                    </div>
                    <?php if ($alertCount > 0): ?>
                        <span class="px-1.5 py-0.2 rounded-md text-[10px] bg-rose-500 text-white font-num font-bold"><?= $alertCount ?></span>
                    <?php endif; ?>
                </a>

                <!-- 1. الأسطول والمركبات -->
                <div class="pt-3 pb-1 px-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">الأسطول والمركبات</div>

                <a href="<?= url('/dashboard/fleet.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'fleet' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span>تتبع الأسطول الميداني</span>
                </a>

                <a href="<?= url('/dashboard/vehicles.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'vehicles' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 17a2 2 0 100-4 2 2 0 000 4zm8 0a2 2 0 100-4 2 2 0 000 4zM5 11l2-5h10l2 5M5 11h14v5a1 1 0 01-1 1H6a1 1 0 01-1-1v-5z"/></svg>
                    <span>سجل المركبات والوثائق</span>
                </a>

                <a href="<?= url('/dashboard/insurance.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'insurance' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>إدارة التأمين (Assurance)</span>
                </a>

                <!-- 2. السائقون والتشغيل -->
                <div class="pt-3 pb-1 px-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">السائقون والتشغيل</div>

                <a href="<?= url('/dashboard/drivers.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'drivers' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <span>السائقون والبطاقات المهنية</span>
                </a>

                <a href="<?= url('/dashboard/contracts.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'contracts' || $currentPage === 'contract_view' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>عقود العمل (قانون 90-11)</span>
                </a>

                <a href="<?= url('/dashboard/cnas.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'cnas' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span>الضمان الاجتماعي (CNAS)</span>
                </a>

                <a href="<?= url('/dashboard/leaves.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'leaves' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span>السائق البديل والعطل</span>
                </a>

                <a href="<?= url('/dashboard/slips.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'slips' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                    <span>جداول الإرسال (Bordereaux)</span>
                </a>

                <a href="<?= url('/dashboard/traveler_reports.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'traveler_reports' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    <span>محاضر المسافرين (PV Voyageurs)</span>
                </a>

                <a href="<?= url('/dashboard/planning.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'planning' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <span>حراسة الحظيرة والجدول (3j)</span>
                </a>

                <!-- 3. المالية والاشتراكات -->
                <div class="pt-3 pb-1 px-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">المالية والاشتراكات</div>

                <a href="<?= url('/dashboard/debts.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'debts' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>المالية والديون (Finances)</span>
                </a>

                <a href="<?= url('/dashboard/billing.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'billing' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span>الفوترة والاشتراكات (CCP)</span>
                </a>

                <!-- 4. النظام والأمان -->
                <div class="pt-3 pb-1 px-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">النظام والأمان</div>

                <a href="<?= url('/dashboard/notifications.php') ?>" class="flex items-center justify-between px-3 py-2 rounded-xl transition <?= $currentPage === 'notifications' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <span>مركز الإشعارات</span>
                    </div>
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="px-1.5 py-0.2 rounded-md text-[10px] bg-rose-500 text-white font-num font-bold animate-pulse"><?= $unreadNotifCount ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= url('/dashboard/users.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'users' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span>المستخدمون والصلاحيات</span>
                </a>

                <a href="<?= url('/dashboard/audit_logs.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'audit_logs' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    <span>سجل التدقيق والرقابة</span>
                </a>

                <a href="<?= url('/dashboard/settings.php') ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-xl transition <?= $currentPage === 'settings' ? 'nav-item-active' : 'nav-item-inactive' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span>إعدادات المنصة والهوية</span>
                </a>

            </nav>
        </div>

        <!-- Sidebar Footer -->
        <div class="p-3 border-t border-slate-100 space-y-1">
            <a href="<?= url('/') ?>" target="_blank" class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-50 transition">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                <span>الموقع الرئيسي</span>
            </a>
            <a href="<?= url('/auth/logout.php') ?>" class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-rose-600 hover:bg-rose-50 transition">
                <svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                <span>تسجيل الخروج</span>
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-y-auto">
        
        <!-- Top Navigation Bar -->
        <header class="h-16 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between sticky top-0 z-30 shadow-2xs">
            <div class="flex items-center gap-3">
                <button onclick="toggleMobileSidebar()" class="md:hidden p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div>
                    <h1 class="text-sm sm:text-base font-black text-slate-900"><?= $pageTitle ?? 'لوحة التحكم' ?></h1>
                    <span class="text-[10px] text-slate-400 font-medium hidden sm:inline">EURL TAXI NAGUILOU • تسيير الأساطيل وعقود 90-11</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                
                <!-- Quick Search Input -->
                <div class="relative hidden lg:block w-64">
                    <input type="text" placeholder="بحث سريع في الأسطول (Matricule)..." 
                           onkeydown="if(event.key==='Enter'){ window.location='<?= url('/dashboard/vehicles.php') ?>?search=' + encodeURIComponent(this.value); }"
                           class="w-full pl-3 pr-8 py-1.5 rounded-xl border border-slate-200 text-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500 bg-slate-50/60">
                    <svg class="w-3.5 h-3.5 absolute right-2.5 top-2.5 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>

                <!-- Notification Bell with Interactive Dropdown -->
                <div class="relative">
                    <button id="notifBellBtn" onclick="document.getElementById('notifDropdown').classList.toggle('hidden')" 
                            class="relative p-2 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 transition flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-rose-500 text-[10px] font-num font-bold text-white shadow-xs">
                                <?= $unreadNotifCount ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <!-- Dropdown Panel -->
                    <div id="notifDropdown" class="hidden absolute left-0 sm:left-auto sm:right-0 mt-2 w-80 sm:w-96 bg-white rounded-2xl shadow-xl border border-slate-200 z-50 overflow-hidden text-right">
                        <div class="p-3.5 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="font-extrabold text-slate-900 text-xs">إشعارات وتنبيهات النظام</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-100 text-amber-800 font-bold font-num"><?= $unreadNotifCount ?> جديدة</span>
                            </div>
                            <?php if ($unreadNotifCount > 0): ?>
                                <a href="?mark_notif_read=all" class="text-[11px] text-amber-700 hover:underline font-bold">تحديد الكل كمقروء</a>
                            <?php endif; ?>
                        </div>

                        <div class="max-h-80 overflow-y-auto divide-y divide-slate-100 text-xs">
                            <?php if (!empty($recentNotifs)): ?>
                                <?php foreach ($recentNotifs as $n): 
                                    $typeBadge = [
                                        'urgent' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'alert' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
                                        'info' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                    ][$n['type']] ?? 'bg-slate-50 text-slate-700 border-slate-200';
                                ?>
                                    <div class="p-3 hover:bg-slate-50/80 transition <?= !$n['is_read'] ? 'bg-amber-50/20' : '' ?>">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-1.5 mb-1">
                                                    <span class="px-1.5 py-0.2 rounded text-[9px] font-bold border <?= $typeBadge ?>">
                                                        <?= strtoupper($n['type']) ?>
                                                    </span>
                                                    <span class="font-bold text-slate-900 truncate text-xs"><?= htmlspecialchars($n['title']) ?></span>
                                                </div>
                                                <p class="text-slate-600 text-[11px] leading-relaxed"><?= htmlspecialchars($n['message']) ?></p>
                                                <?php if (!empty($n['link'])): ?>
                                                    <a href="<?= url($n['link']) ?>" class="inline-block mt-1 text-[11px] text-amber-700 font-bold hover:underline">
                                                        معاينة التفاصيل ←
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-[10px] text-slate-400 font-num shrink-0"><?= date('H:i', strtotime($n['created_at'])) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-6 text-center text-slate-400 text-xs">
                                    لا توجد إشعارات جديدة حالياً
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="p-2.5 bg-slate-50 border-t border-slate-100 text-center">
                            <a href="<?= url('/dashboard/notifications.php') ?>" class="text-xs font-bold text-slate-800 hover:text-amber-700 transition">
                                كل التنبيهات (<?= count($recentNotifs) ?>) ←
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($alertCount > 0): ?>
                    <a href="<?= url('/dashboard/vehicles.php?filter=expiring') ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold hover:bg-rose-100 transition">
                        <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                        <span><?= $alertCount ?> وثيقة للتجديد</span>
                    </a>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-700 text-xs font-bold">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        <span class="hidden sm:inline">جميع الوثائق نظامية</span>
                    </span>
                <?php endif; ?>

                <div class="h-4 w-px bg-slate-200"></div>

                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-700 font-bold flex items-center justify-center text-xs">
                        <?= mb_substr($user['name'], 0, 1) ?>
                    </div>
                    <span class="text-xs text-slate-500 font-semibold hidden xl:inline"><?= htmlspecialchars($user['wilaya']) ?></span>
                </div>
            </div>
        </header>

        <!-- Flash Messages Display -->
        <?php $flash = get_flash(); if ($flash): ?>
            <div class="mx-4 sm:mx-8 mt-4 p-3.5 rounded-xl text-xs font-bold flex items-center justify-between <?= $flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
                <div class="flex items-center gap-2">
                    <span><?= $flash['type'] === 'success' ? '✓' : '⚠️' ?></span>
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>
        <?php endif; ?>

        <!-- Content Container -->
        <main class="flex-1 p-4 sm:p-8">

<script>
    function toggleMobileSidebar() {
        const sidebar = document.getElementById('sidebarNav');
        const backdrop = document.getElementById('mobileDrawerBackdrop');
        const isHidden = sidebar.classList.contains('-translate-x-full');
        if (isHidden) {
            sidebar.classList.remove('-translate-x-full');
            backdrop.classList.remove('hidden');
        } else {
            sidebar.classList.add('-translate-x-full');
            backdrop.classList.add('hidden');
        }
    }
</script>
