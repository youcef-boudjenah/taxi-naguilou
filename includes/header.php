<?php
// includes/header.php - GStock Desktop/ERP Global Navigation & Shell
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user() ?? [
    'id' => 1,
    'name' => 'المشرف',
    'email' => 'admin@taxinaguilou.dz',
    'role' => 'super_admin',
    'phone' => '',
    'wilaya' => '16 - الجزائر العاصمة'
];
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$userRole = $user['role'] ?? 'driver';
$isSuperOrAdmin = in_array($userRole, ['super_admin', 'admin']);
$isFleetOwner = ($userRole === 'owner');
$isTaxiDriver = ($userRole === 'driver');

$pdo = getDBConnection();

// Handle Mark Notification as Read
if (isset($_GET['mark_notif_read']) && $pdo) {
    try {
        if ($_GET['mark_notif_read'] === 'all') {
            $pdo->query("UPDATE notifications SET is_read = 1");
        } else {
            $nId = intval($_GET['mark_notif_read']);
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$nId]);
        }
    } catch (\Throwable $e) {}
    $targetUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $targetUrl");
    exit;
}

// Fetch urgent alert count for document expirations (< 30 days)
$alertCount = 0;
$expiringList = [];
if ($pdo) {
    try {
        $stmtAlerts = $pdo->query("
            SELECT v.id, v.brand, v.model, v.plate_number,
                   DATEDIFF(v.technical_control_expiry, CURDATE()) as tc_days,
                   DATEDIFF(v.insurance_expiry, CURDATE()) as ins_days,
                   DATEDIFF(v.gpl_expiry, CURDATE()) as gpl_days,
                   DATEDIFF(v.saccuriometre_expiry, CURDATE()) as sac_days
            FROM vehicles v
            WHERE (v.technical_control_expiry IS NOT NULL AND v.technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
               OR (v.insurance_expiry IS NOT NULL AND v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
               OR (v.gpl_expiry IS NOT NULL AND v.gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
               OR (v.saccuriometre_expiry IS NOT NULL AND v.saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
            ORDER BY LEAST(
                COALESCE(tc_days, 999),
                COALESCE(ins_days, 999),
                COALESCE(gpl_days, 999),
                COALESCE(sac_days, 999)
            ) ASC
            LIMIT 5
        ");
        $expiringList = $stmtAlerts ? $stmtAlerts->fetchAll() : [];
        $alertCount = count($expiringList);
    } catch (\Throwable $e) {}
}

// Fetch unread notifications & recent list
$unreadNotifCount = 0;
$recentNotifs = [];
if ($pdo) {
    try {
        $unreadNotifCount = getUnreadNotificationsCount($pdo);
        $recentNotifs = getRecentNotifications($pdo, 5);
    } catch (\Throwable $e) {}
}

// Navigation Categories Configuration
$erpNavCategories = [
    'fleet' => [
        'name' => 'الأسطول والمركبات',
        'color' => 'blue',
        'pages' => [
            'fleet' => ['title' => 'تتبع الأسطول الميداني', 'url' => '/dashboard/fleet.php', 'badge' => 'ميداني'],
            'vehicles' => ['title' => 'سجل المركبات والوثائق', 'url' => '/dashboard/vehicles.php', 'badge' => 'وثائق'],
            'insurance' => ['title' => 'التأمين والمراقبة التقنية', 'url' => '/dashboard/insurance.php', 'badge' => 'SAA/GPL'],
        ]
    ],
    'drivers' => [
        'name' => 'السائقون والتشغيل',
        'color' => 'violet',
        'pages' => [
            'drivers' => ['title' => 'السائقون والبطاقات المهنية', 'url' => '/dashboard/drivers.php', 'badge' => 'دفاتر'],
            'driver_fiche' => ['title' => 'البطاقة الفنية للسائق', 'url' => '/dashboard/driver_fiche.php', 'badge' => 'Fiche'],
            'contracts' => ['title' => 'عقود العمل (قانون 90-11)', 'url' => '/dashboard/contracts.php', 'badge' => 'عقود'],
            'cnas' => ['title' => 'الضمان الاجتماعي (CNAS)', 'url' => '/dashboard/cnas.php', 'badge' => 'أجراء'],
            'leaves' => ['title' => 'السائق البديل والعطل', 'url' => '/dashboard/leaves.php', 'badge' => 'إنابة'],
            'slips' => ['title' => 'جداول الإرسال (Bordereaux)', 'url' => '/dashboard/slips.php', 'badge' => 'DTW'],
            'traveler_reports' => ['title' => 'محاضر المسافرين (PV)', 'url' => '/dashboard/traveler_reports.php', 'badge' => 'سفر'],
            'planning' => ['title' => 'حراسة الحظيرة (3j)', 'url' => '/dashboard/planning.php', 'badge' => 'مناوبة'],
        ]
    ],
    'finance' => [
        'name' => 'المالية والاشتراكات',
        'color' => 'emerald',
        'pages' => [
            'debts' => ['title' => 'المالية والديون', 'url' => '/dashboard/debts.php', 'badge' => 'ذمم'],
            'billing' => ['title' => 'الفوترة والاشتراكات (CCP)', 'url' => '/dashboard/billing.php', 'badge' => 'CCP'],
        ]
    ],
    'system' => [
        'name' => 'النظام والتنبيهات',
        'color' => 'slate',
        'pages' => [
            'notifications' => ['title' => 'مركز الإشعارات', 'url' => '/dashboard/notifications.php', 'badge' => 'تنبيهات'],
            'users' => ['title' => 'المستخدمون والصلاحيات', 'url' => '/dashboard/users.php', 'badge' => 'صلاحيات'],
            'audit_logs' => ['title' => 'سجل التدقيق والرقابة', 'url' => '/dashboard/audit_logs.php', 'badge' => 'أمان'],
            'settings' => ['title' => 'إعدادات المنصة والهوية', 'url' => '/dashboard/settings.php', 'badge' => 'نظام'],
        ]
    ]
];

// Determine Active Category for Sub-Ribbon
$activeCategoryKey = null;
$activeCategoryName = null;
foreach ($erpNavCategories as $catKey => $catData) {
    if (array_key_exists($currentPage, $catData['pages'])) {
        $activeCategoryKey = $catKey;
        $activeCategoryName = $catData['name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-[#f4f6f9]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'لوحة التحكم' ?> | تاكسي نقيلو (Desktop ERP)</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        amber: {
                            50: '#fffbeb', 100: '#fef3c7', 200: '#fde68a', 300: '#fcd34d',
                            400: '#fbbf24', 500: '#f59e0b', 600: '#d97706', 700: '#b45309',
                            800: '#92400e', 900: '#78350f', 950: '#451a03',
                        }
                    },
                    fontFamily: {
                        sans: ['Cairo', 'sans-serif'],
                        num: ['Plus Jakarta Sans', 'Cairo', 'sans-serif']
                    },
                    boxShadow: {
                        '2xs': '0 1px 2px 0 rgba(0, 0, 0, 0.03)',
                    }
                }
            }
        };
    </script>
    <?= render_theme_head() ?>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            font-feature-settings: "tnum";
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
        }
        .font-num, .font-mono, [dir="ltr"], .num-ltr {
            font-feature-settings: "tnum";
            unicode-bidi: isolate;
        }
        [dir="ltr"], .num-ltr {
            direction: ltr !important;
            unicode-bidi: isolate;
        }
        span.font-num, span.font-mono, td.font-num, td.font-mono, div.font-num, div.font-mono {
            unicode-bidi: isolate;
        }
        input[type="tel"], input[type="date"], input[type="time"], input[type="number"] {
            font-feature-settings: "tnum";
            text-align: right;
            direction: ltr;
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
        .launcher-card {
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .launcher-card:hover {
            transform: translateY(-2px);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeIn {
            animation: fadeIn 0.15s ease-out forwards;
        }
    </style>
</head>
<body class="min-h-full bg-[#f4f6f9] text-slate-800 antialiased flex flex-col font-sans">
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">

        <!-- 1. Top Expiry Radar Alert Banner (If Any Warnings) -->
        <?php if (!empty($expiringList)): ?>
            <div class="bg-rose-600 text-white px-4 py-2 text-xs font-bold shadow-2xs z-50">
                <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-white animate-ping"></span>
                        <span>تنبيه رادار الصلاحية: يوجد <?= count($expiringList) ?> وثائق لمركبات الأسطول تنتهي خلال أقل من 30 يوماً (تأمين / مراقبة تقنية / GPL).</span>
                    </div>
                    <a href="<?= url('/dashboard/vehicles.php?filter=expiring') ?>" class="underline hover:text-rose-100 whitespace-nowrap text-[11px] font-black">
                        معاينة الوثائق وتفادي المحشر ←
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- 2. Sticky Desktop Top ERP Header -->
        <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-slate-200/90 shadow-2xs">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-3">
                
                <!-- Brand & Desktop Launcher Button & Quick Dropdowns -->
                <div class="flex items-center gap-2.5 sm:gap-4 shrink-0">
                    
                    <!-- Brand Identity -->
                    <a href="<?= url('/dashboard/index.php') ?>" class="flex items-center gap-2.5 group">
                        <div class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center shadow-2xs group-hover:scale-105 transition">
                            <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-6 h-6 object-contain">
                        </div>
                        <div class="hidden lg:block text-right">
                            <div class="text-sm font-black text-slate-900 tracking-tight leading-none flex items-center gap-1.5">
                                <span>تاكسي</span>
                                <span class="text-amber-600">نقيلو</span>
                                <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-slate-100 text-slate-600 border border-slate-200">ERP</span>
                            </div>
                            <span class="text-[8px] text-slate-400 font-extrabold block mt-0.5 uppercase tracking-wider">EURL TAXI NAGUILOU</span>
                        </div>
                    </a>

                    <!-- Direct Launcher Button (GStock Style) -->
                    <a href="<?= url('/dashboard/index.php') ?>" 
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-xs font-bold transition shadow-2xs <?= $currentPage === 'index' ? 'bg-amber-500 text-slate-950 border-amber-600/30 font-black' : 'bg-slate-100 hover:bg-slate-200 text-slate-700 border-slate-200' ?>" 
                       title="لوحة التحكم الرئيسية (Desktop Launcher)">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                        </svg>
                        <span class="hidden sm:inline">لوحة التحكم</span>
                    </a>

                    <!-- Desktop Module Dropdown Menus (Quick ERP Navigation) -->
                    <div class="hidden xl:flex items-center gap-1 border-r border-slate-200 pr-3 mr-1">
                        
                        <!-- 1. الأسطول -->
                        <div class="relative group">
                            <button type="button" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition <?= $activeCategoryKey === 'fleet' ? 'text-blue-700 bg-blue-50 font-black' : '' ?>">
                                <span>الأسطول</span>
                                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div class="absolute right-0 mt-1 w-56 bg-white rounded-xl shadow-xl border border-slate-200/90 py-1.5 hidden group-hover:block z-50 animate-fadeIn">
                                <a href="<?= url('/dashboard/fleet.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition">
                                    <span>تتبع الأسطول الميداني</span>
                                    <span class="text-[10px] text-slate-400 font-num">GPS</span>
                                </a>
                                <a href="<?= url('/dashboard/vehicles.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-amber-50 hover:text-amber-700 transition">
                                    <span>سجل المركبات والوثائق</span>
                                    <span class="text-[10px] text-slate-400 font-num">البطاقات</span>
                                </a>
                                <a href="<?= url('/dashboard/insurance.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700 transition">
                                    <span>التأمين والمراقبة التقنية</span>
                                    <span class="text-[10px] text-slate-400 font-num">SAA / GPL</span>
                                </a>
                            </div>
                        </div>

                        <!-- 2. السائقون -->
                        <div class="relative group">
                            <button type="button" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition <?= $activeCategoryKey === 'drivers' ? 'text-violet-700 bg-violet-50 font-black' : '' ?>">
                                <span>السائقون والتشغيل</span>
                                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div class="absolute right-0 mt-1 w-64 bg-white rounded-xl shadow-xl border border-slate-200/90 py-1.5 hidden group-hover:block z-50 animate-fadeIn">
                                <a href="<?= url('/dashboard/drivers.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-violet-50 hover:text-violet-700 transition">
                                    <span>السائقون والبطاقات المهنية</span>
                                    <span class="text-[10px] text-slate-400 font-num">البطاقات</span>
                                </a>
                                <a href="<?= url('/dashboard/contracts.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-teal-50 hover:text-teal-700 transition">
                                    <span>عقود العمل (قانون 90-11)</span>
                                    <span class="text-[10px] text-slate-400 font-num">قانوني</span>
                                </a>
                                <?php if ($isSuperOrAdmin): ?>
                                <a href="<?= url('/dashboard/cnas.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-sky-50 hover:text-sky-700 transition">
                                    <span>الضمان الاجتماعي (CNAS)</span>
                                    <span class="text-[10px] text-slate-400 font-num">أجراء</span>
                                </a>
                                <?php endif; ?>
                                <a href="<?= url('/dashboard/leaves.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span>السائق البديل والعطل</span>
                                    <span class="text-[10px] text-slate-400 font-num">إنابة</span>
                                </a>
                                <a href="<?= url('/dashboard/slips.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-amber-50 hover:text-amber-800 transition">
                                    <span>جداول الإرسال (Bordereaux)</span>
                                    <span class="text-[10px] text-slate-400 font-num">DTW</span>
                                </a>
                                <a href="<?= url('/dashboard/traveler_reports.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-cyan-50 hover:text-cyan-700 transition">
                                    <span>محاضر المسافرين (PV)</span>
                                    <span class="text-[10px] text-slate-400 font-num">بين الولايات</span>
                                </a>
                                <a href="<?= url('/dashboard/planning.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-purple-50 hover:text-purple-700 transition">
                                    <span>حراسة الحظيرة والجدول (3j)</span>
                                    <span class="text-[10px] text-slate-400 font-num">مناوبة</span>
                                </a>
                            </div>
                        </div>

                        <!-- 3. المالية -->
                        <?php if ($isSuperOrAdmin || $isFleetOwner): ?>
                        <div class="relative group">
                            <button type="button" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition <?= $activeCategoryKey === 'finance' ? 'text-emerald-700 bg-emerald-50 font-black' : '' ?>">
                                <span>المالية</span>
                                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div class="absolute right-0 mt-1 w-56 bg-white rounded-xl shadow-xl border border-slate-200/90 py-1.5 hidden group-hover:block z-50 animate-fadeIn">
                                <?php if ($isSuperOrAdmin): ?>
                                <a href="<?= url('/dashboard/debts.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-rose-50 hover:text-rose-700 transition">
                                    <span>المالية والديون</span>
                                    <span class="text-[10px] text-slate-400 font-num">مستحقات</span>
                                </a>
                                <?php endif; ?>
                                <a href="<?= url('/dashboard/billing.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700 transition">
                                    <span>الفوترة والاشتراكات (CCP)</span>
                                    <span class="text-[10px] text-slate-400 font-num">BaridiMob</span>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 4. النظام -->
                        <div class="relative group">
                            <button type="button" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition <?= $activeCategoryKey === 'system' ? 'text-slate-950 bg-slate-100 font-black' : '' ?>">
                                <span>النظام</span>
                                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div class="absolute right-0 mt-1 w-56 bg-white rounded-xl shadow-xl border border-slate-200/90 py-1.5 hidden group-hover:block z-50 animate-fadeIn">
                                <a href="<?= url('/dashboard/notifications.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-amber-50 hover:text-amber-800 transition">
                                    <span>مركز الإشعارات</span>
                                    <span class="text-[10px] text-slate-400 font-num">تنبيهات</span>
                                </a>
                                <?php if ($isSuperOrAdmin): ?>
                                <a href="<?= url('/dashboard/users.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 hover:text-slate-950 transition">
                                    <span>المستخدمون والصلاحيات</span>
                                    <span class="text-[10px] text-slate-400 font-num">RBAC</span>
                                </a>
                                <a href="<?= url('/dashboard/audit_logs.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-orange-50 hover:text-orange-700 transition">
                                    <span>سجل التدقيق والرقابة</span>
                                    <span class="text-[10px] text-slate-400 font-num">أمان</span>
                                </a>
                                <a href="<?= url('/dashboard/settings.php') ?>" class="flex items-center justify-between px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 hover:text-slate-950 transition">
                                    <span>إعدادات المنصة والهوية</span>
                                    <span class="text-[10px] text-slate-400 font-num">تخصيص</span>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Global Search Input (Ctrl + K) -->
                <div class="flex-1 max-w-sm sm:max-w-md relative hidden md:block">
                    <div class="relative">
                        <?php if ($currentPage === 'index'): ?>
                            <input type="text" id="launcherSearch" 
                                   placeholder="بحث فوري في الوحدات والمهام (اضغط Ctrl+K للتركيز)..." 
                                   class="w-full pr-9 pl-16 py-1.5 rounded-xl border border-slate-200/90 bg-slate-50/70 hover:bg-slate-50 focus:bg-white text-xs font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition shadow-2xs">
                        <?php else: ?>
                            <input type="text" id="globalSearchInput" 
                                   placeholder="بحث سريع في الأسطول والسائقين (Ctrl+K)..." 
                                   onkeydown="if(event.key==='Enter'){ window.location='<?= url('/dashboard/vehicles.php') ?>?search=' + encodeURIComponent(this.value); }"
                                   class="w-full pr-9 pl-16 py-1.5 rounded-xl border border-slate-200/90 bg-slate-50/70 hover:bg-slate-50 focus:bg-white text-xs font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition shadow-2xs">
                        <?php endif; ?>
                        <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <div class="absolute left-2 top-1/2 -translate-y-1/2 flex items-center gap-1 pointer-events-none">
                            <kbd class="px-1.5 py-0.5 rounded text-[9px] font-num font-bold bg-white text-slate-400 border border-slate-200 shadow-2xs">Ctrl</kbd>
                            <kbd class="px-1.5 py-0.5 rounded text-[9px] font-num font-bold bg-white text-slate-400 border border-slate-200 shadow-2xs">K</kbd>
                        </div>
                    </div>
                </div>

                <!-- Right Quick Actions: Expiry Badge, Notification Bell, User Status, Logout -->
                <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                    
                    <!-- Expiry Badge -->
                    <?php if ($alertCount > 0): ?>
                        <a href="<?= url('/dashboard/vehicles.php?filter=expiring') ?>" 
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold hover:bg-rose-100 transition shadow-2xs">
                            <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                            <span class="hidden sm:inline font-num"><?= $alertCount ?></span>
                            <span class="hidden xl:inline">وثائق للتجديد</span>
                        </a>
                    <?php endif; ?>

                    <!-- Notification Bell Dropdown -->
                    <div class="relative">
                        <button id="notifBellBtn" onclick="document.getElementById('notifDropdown').classList.toggle('hidden')" 
                                class="relative p-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition flex items-center justify-center" 
                                title="مركز الإشعارات">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                            <?php if ($unreadNotifCount > 0): ?>
                                <span class="absolute top-1 left-1 w-2.5 h-2.5 rounded-full bg-rose-500 ring-2 ring-white"></span>
                            <?php endif; ?>
                        </button>

                        <!-- Notification Dropdown Panel -->
                        <div id="notifDropdown" class="hidden absolute left-0 sm:left-auto sm:right-0 mt-2 w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-slate-200/90 z-50 overflow-hidden text-right animate-fadeIn">
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
                                        <div class="p-3 hover:bg-slate-50 transition <?= !$n['is_read'] ? 'bg-amber-50/20' : '' ?>">
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

                    <!-- Global Theme Toggle (Sun/Moon) -->
                    <?= render_theme_toggle_btn() ?>

                    <div class="h-6 w-px bg-slate-200"></div>

                    <!-- User Status Pill -->
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-slate-900 text-white font-black flex items-center justify-center text-xs shadow-2xs font-num shrink-0">
                            <?= mb_substr($user['name'], 0, 1) ?>
                        </div>
                        <div class="hidden md:block text-right">
                            <div class="text-xs font-bold text-slate-900 leading-tight truncate max-w-[120px]"><?= htmlspecialchars($user['name']) ?></div>
                            <span class="text-[10px] font-bold <?= $isSuperOrAdmin ? 'text-amber-700' : ($isFleetOwner ? 'text-blue-700' : 'text-emerald-700') ?>">
                                <?= $isSuperOrAdmin ? 'مدير المنصة' : ($isFleetOwner ? 'مالك أسطول' : 'سائق تاكسي') ?>
                            </span>
                        </div>
                    </div>

                    <!-- Direct Logout Button -->
                    <a href="<?= url('/auth/logout.php') ?>" class="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="تسجيل الخروج">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                        </svg>
                    </a>

                    <!-- Mobile Menu Button -->
                    <button id="mobileNavBtn" onclick="document.getElementById('mobileNavDrawer').classList.toggle('hidden')" 
                            class="xl:hidden p-2 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>

                </div>

            </div>
        </header>

        <!-- Mobile Navigation Drawer / Dropdown -->
        <div id="mobileNavDrawer" class="hidden xl:hidden bg-white border-b border-slate-200/90 px-4 py-3 shadow-lg z-40 space-y-3">
            <div class="grid grid-cols-2 gap-2 text-xs font-bold">
                <a href="<?= url('/dashboard/fleet.php') ?>" class="p-2 rounded-lg bg-blue-50 text-blue-700">📍 تتبع الأسطول</a>
                <a href="<?= url('/dashboard/vehicles.php') ?>" class="p-2 rounded-lg bg-amber-50 text-amber-800">🚗 سجل المركبات</a>
                <a href="<?= url('/dashboard/drivers.php') ?>" class="p-2 rounded-lg bg-violet-50 text-violet-700">👤 سجل السائقين</a>
                <a href="<?= url('/dashboard/contracts.php') ?>" class="p-2 rounded-lg bg-teal-50 text-teal-700">📄 عقود 90-11</a>
                <a href="<?= url('/dashboard/insurance.php') ?>" class="p-2 rounded-lg bg-emerald-50 text-emerald-700">🛡️ التأمين والفحص</a>
                <a href="<?= url('/dashboard/cnas.php') ?>" class="p-2 rounded-lg bg-sky-50 text-sky-700">🏥 الضمان CNAS</a>
                <a href="<?= url('/dashboard/billing.php') ?>" class="p-2 rounded-lg bg-emerald-50 text-emerald-800">🧾 الفوترة CCP</a>
                <a href="<?= url('/dashboard/debts.php') ?>" class="p-2 rounded-lg bg-rose-50 text-rose-700">💳 المالية والديون</a>
                <a href="<?= url('/dashboard/slips.php') ?>" class="p-2 rounded-lg bg-amber-50 text-amber-900">📋 جداول الإرسال</a>
                <a href="<?= url('/dashboard/leaves.php') ?>" class="p-2 rounded-lg bg-indigo-50 text-indigo-700">🌴 العطل والبديل</a>
            </div>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <?= render_theme_toggle_btn() ?>
                    <span class="theme-toggle-label font-bold text-slate-700">الوضع الليلي</span>
                </div>
                <div class="flex items-center gap-3">
                    <span>المستخدم: <?= htmlspecialchars($user['name']) ?></span>
                    <a href="<?= url('/auth/logout.php') ?>" class="text-rose-600 font-bold">تسجيل الخروج ←</a>
                </div>
            </div>
        </div>

        <!-- 3. Contextual Sub-Ribbon Bar (Category Filter on Index / Sibling Modules on Inner Pages) -->
        <section class="bg-white border-b border-slate-200/90 shadow-2xs">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2.5 flex items-center justify-between gap-3 overflow-x-auto">
                
                <?php if ($currentPage === 'index'): ?>
                    <!-- On Launcher Grid (index.php): Filter Pills -->
                    <div class="flex items-center gap-1.5 shrink-0" id="categoryFilterBar">
                        <button type="button" data-filter="all" class="category-btn px-3.5 py-1.5 rounded-lg text-xs font-bold transition shadow-2xs bg-slate-900 text-white">
                            الكل (18)
                        </button>
                        <button type="button" data-filter="fleet" class="category-btn px-3.5 py-1.5 rounded-lg text-xs font-bold transition shadow-2xs bg-slate-100 text-slate-600 hover:bg-slate-200">
                            الأسطول والمركبات
                        </button>
                        <button type="button" data-filter="drivers" class="category-btn px-3.5 py-1.5 rounded-lg text-xs font-bold transition shadow-2xs bg-slate-100 text-slate-600 hover:bg-slate-200">
                            السائقون والتشغيل
                        </button>
                        <button type="button" data-filter="finance" class="category-btn px-3.5 py-1.5 rounded-lg text-xs font-bold transition shadow-2xs bg-slate-100 text-slate-600 hover:bg-slate-200">
                            المالية والاشتراكات
                        </button>
                        <button type="button" data-filter="system" class="category-btn px-3.5 py-1.5 rounded-lg text-xs font-bold transition shadow-2xs bg-slate-100 text-slate-600 hover:bg-slate-200">
                            النظام والتنبيهات
                        </button>
                    </div>

                    <div class="hidden sm:flex items-center gap-2 text-xs text-slate-400 font-semibold shrink-0">
                        <span>الولاية: <strong class="text-slate-700"><?= htmlspecialchars($user['wilaya']) ?></strong></span>
                        <span>•</span>
                        <span class="text-emerald-700 font-bold">الخادم متصل ونشط</span>
                    </div>

                <?php else: ?>
                    <!-- On Inner Pages: Breadcrumbs + Sibling Module Jump Pills -->
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-500 shrink-0">
                        <a href="<?= url('/dashboard/index.php') ?>" class="text-slate-700 hover:text-amber-600 transition flex items-center gap-1">
                            <span>⊞ لوحة التحكم</span>
                        </a>
                        <span class="text-slate-300">/</span>
                        <?php if ($activeCategoryName): ?>
                            <span class="text-slate-400 font-medium"><?= $activeCategoryName ?></span>
                            <span class="text-slate-300">/</span>
                        <?php endif; ?>
                        <span class="text-slate-900 font-black truncate max-w-[200px]"><?= $pageTitle ?? 'الوحدة الحالية' ?></span>
                    </div>

                    <!-- Sibling Category Pills for Instant Module Jump -->
                    <?php if ($activeCategoryKey && isset($erpNavCategories[$activeCategoryKey]['pages'])): ?>
                        <div class="flex items-center gap-1.5 overflow-x-auto py-0.5">
                            <?php foreach ($erpNavCategories[$activeCategoryKey]['pages'] as $pKey => $pInfo): 
                                // Role-based visibility
                                if ($pKey === 'cnas' && !$isSuperOrAdmin) continue;
                                if ($pKey === 'debts' && !$isSuperOrAdmin) continue;
                                if ($pKey === 'users' && !$isSuperOrAdmin) continue;
                                if ($pKey === 'settings' && !$isSuperOrAdmin) continue;
                                if ($pKey === 'contract_view') continue; // Not a standalone listing
                                if ($pKey === 'driver_fiche' && !isset($_GET['id'])) continue; // Only relevant when viewing fiche
                                
                                $isCurrent = ($currentPage === $pKey);
                            ?>
                                <a href="<?= url($pInfo['url']) ?>" 
                                   class="px-2.5 py-1 rounded-lg text-xs font-bold whitespace-nowrap transition shadow-2xs <?= $isCurrent ? 'bg-slate-900 text-white font-black' : 'bg-slate-100 hover:bg-slate-200 text-slate-600' ?>">
                                    <?= $pInfo['title'] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </div>
        </section>

        <!-- 4. Flash Messages Display -->
        <?php $flash = get_flash(); if ($flash): ?>
            <div class="max-w-7xl mx-auto w-full px-4 sm:px-6 mt-4">
                <div class="p-3.5 rounded-xl text-xs font-bold flex items-center justify-between <?= $flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?> shadow-2xs">
                    <div class="flex items-center gap-2">
                        <span><?= $flash['type'] === 'success' ? '✓' : '⚠️' ?></span>
                        <span><?= htmlspecialchars($flash['message']) ?></span>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
            </div>
        <?php endif; ?>

        <!-- 5. Main Content Container (Full Width Desktop / ERP Canvas) -->
        <main class="flex-1 max-w-7xl mx-auto w-full px-4 sm:px-6 py-6 space-y-6">
