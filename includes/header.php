<?php
// includes/header.php - Clean Unified Dashboard Layout
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
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'لوحة التحكم' ?> | تاكسي نقيلو</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        amber: {
                            500: '#f59e0b',
                            600: '#d97706',
                            700: '#b45309'
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
        body {
            font-family: 'Cairo', sans-serif;
            font-feature-settings: "tnum";
        }
        ::selection {
            background-color: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen flex flex-col md:flex-row">

    <!-- Sidebar Navigation -->
    <aside class="w-full md:w-64 bg-white border-l border-slate-200 shrink-0 flex flex-col justify-between md:min-h-screen">
        <div>
            <!-- Sidebar Header / Brand -->
            <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                <a href="/dashboard/index.php" class="flex items-center gap-2.5">
                    <img src="/logo.webp" alt="تاكسي نقيلو" class="w-10 h-10 object-contain rounded-full bg-white p-0.5 border border-amber-300 shadow-sm shrink-0">
                    <div>
                        <div class="text-lg font-black text-slate-900 tracking-tight leading-none">تاكسي <span class="text-amber-600">نقيلو</span></div>
                        <span class="text-[9px] text-slate-400 font-bold block mt-0.5 uppercase tracking-wider">EURL TAXI NAGUILOU</span>
                    </div>
                </a>
            </div>

            <!-- User Quick Card -->
            <div class="p-4 mx-3 my-3 rounded-xl bg-slate-50 border border-slate-100 flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-amber-100 text-amber-800 font-bold flex items-center justify-center text-sm shrink-0">
                    <?= mb_substr($user['name'], 0, 1) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="text-[10px] text-slate-400 font-medium">
                        <?= $user['role'] === 'super_admin' ? 'مدير المنصة' : ($user['role'] === 'owner' ? 'مالك مركبة' : 'سائق معتمد') ?>
                    </div>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="px-3 py-2 space-y-1 text-xs font-bold">
                
                <a href="/dashboard/index.php" class="flex items-center justify-between px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'index' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <div class="flex items-center gap-2.5">
                        <span class="text-base">📊</span>
                        <span>لوحة التحكم الرئيسية</span>
                    </div>
                    <?php if ($alertCount > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] bg-rose-500 text-white font-num"><?= $alertCount ?></span>
                    <?php endif; ?>
                </a>

                <a href="/dashboard/vehicles.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'vehicles' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">🚗</span>
                    <span>سجل المركبات والوثائق</span>
                </a>

                <a href="/dashboard/insurance.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'insurance' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">🛡️</span>
                    <span>إدارة التأمين (Gérer Assurance)</span>
                </a>

                <a href="/dashboard/drivers.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'drivers' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">👥</span>
                    <span>السائقون والبطاقات المهنية</span>
                </a>

                <a href="/dashboard/planning.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'planning' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">🌙</span>
                    <span>حراسة الحظيرة والجدول (3j)</span>
                </a>

                <a href="/dashboard/contracts.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'contracts' || $currentPage === 'contract_view' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">📜</span>
                    <span>عقود العمل (قانون 90-11)</span>
                </a>

                <a href="/dashboard/leaves.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'leaves' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">🔄</span>
                    <span>السائق البديل والعطل</span>
                </a>

                <a href="/dashboard/slips.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'slips' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">📋</span>
                    <span>جداول الإرسال (Bordereaux)</span>
                </a>

                <a href="/dashboard/debts.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'debts' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">💰</span>
                    <span>المالية والديون (Finances)</span>
                </a>

                <a href="/dashboard/billing.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'billing' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <span class="text-base">💳</span>
                    <span>الفوترة والاشتراكات (CCP)</span>
                </a>

                <a href="/dashboard/notifications.php" class="flex items-center justify-between px-3.5 py-2.5 rounded-lg transition <?= $currentPage === 'notifications' ? 'bg-amber-500 text-slate-950 font-extrabold' : 'text-slate-600 hover:bg-slate-100' ?>">
                    <div class="flex items-center gap-2.5">
                        <span class="text-base">🔔</span>
                        <span>الإشعارات وتنبيهات النظام</span>
                    </div>
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] bg-rose-500 text-white font-num font-bold animate-pulse"><?= $unreadNotifCount ?></span>
                    <?php endif; ?>
                </a>

            </nav>
        </div>

        <!-- Sidebar Footer -->
        <div class="p-3 border-t border-slate-100 space-y-1">
            <a href="/" target="_blank" class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition">
                <span>🌐</span>
                <span>زيارة صفحة الموقع الرئيسية</span>
            </a>
            <a href="/auth/logout.php" class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-bold text-rose-600 hover:bg-rose-50 transition">
                <span>🚪</span>
                <span>تسجيل الخروج</span>
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        
        <!-- Top Navigation Bar -->
        <header class="h-16 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <h1 class="text-base sm:text-lg font-bold text-slate-900"><?= $pageTitle ?? 'لوحة التحكم' ?></h1>
            </div>

            <div class="flex items-center gap-3">
                
                <!-- Notification Bell with Interactive Dropdown -->
                <div class="relative">
                    <button id="notifBellBtn" onclick="document.getElementById('notifDropdown').classList.toggle('hidden')" 
                            class="relative p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition flex items-center justify-center">
                        <span class="text-base">🔔</span>
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-rose-500 text-[10px] font-num font-bold text-white shadow">
                                <?= $unreadNotifCount ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <!-- Dropdown Panel -->
                    <div id="notifDropdown" class="hidden absolute left-0 sm:left-auto sm:right-0 mt-2 w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-slate-200 z-50 overflow-hidden text-right">
                        <div class="p-4 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-base">🔔</span>
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
                                    <div class="p-3.5 hover:bg-slate-50/80 transition <?= !$n['is_read'] ? 'bg-amber-50/20' : '' ?>">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-1.5 mb-1">
                                                    <span class="px-2 py-0.2 rounded text-[10px] font-bold border <?= $typeBadge ?>">
                                                        <?= strtoupper($n['type']) ?>
                                                    </span>
                                                    <span class="font-bold text-slate-900 truncate text-xs"><?= htmlspecialchars($n['title']) ?></span>
                                                </div>
                                                <p class="text-slate-600 text-[11px] leading-relaxed"><?= htmlspecialchars($n['message']) ?></p>
                                                <?php if (!empty($n['link'])): ?>
                                                    <a href="<?= htmlspecialchars($n['link']) ?>" class="inline-block mt-1 text-[11px] text-amber-700 font-bold hover:underline">
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

                        <div class="p-3 bg-slate-50 border-t border-slate-100 text-center">
                            <a href="/dashboard/notifications.php" class="text-xs font-bold text-slate-800 hover:text-amber-700 transition">
                                عرض كل الإشعارات والتنبيهات (<?= count($recentNotifs) ?>) ←
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($alertCount > 0): ?>
                    <a href="/dashboard/vehicles.php?filter=expiring" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold hover:bg-rose-100 transition">
                        <span>⚠️</span>
                        <span><?= $alertCount ?> وثيقة بحاجة لتجديد</span>
                    </a>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 text-xs font-bold">
                        <span>✓</span>
                        <span>جميع الوثائق نظامية</span>
                    </span>
                <?php endif; ?>

                <div class="h-4 w-px bg-slate-200"></div>

                <span class="text-xs text-slate-400 font-semibold hidden sm:inline">ولاية: <?= htmlspecialchars($user['wilaya']) ?></span>
            </div>
        </header>

        <!-- Flash Messages Display -->
        <?php $flash = get_flash(); if ($flash): ?>
            <div class="mx-4 sm:mx-8 mt-4 p-3.5 rounded-xl text-xs font-bold flex items-center justify-between <?= $flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
                <span><?= htmlspecialchars($flash['message']) ?></span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>
        <?php endif; ?>

        <!-- Content Container -->
        <main class="flex-1 p-4 sm:p-8">
