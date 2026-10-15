<?php
// dashboard/index.php - Modern Desktop/ERP Launcher (GStock Inspired)
$pageTitle = 'لوحة التحكم الرئيسية';
require_once __DIR__ . '/../includes/header.php';

// Initialize KPIs and Live Counts
$counts = [
    'fleet_tracking'   => 0,
    'vehicles'         => 0,
    'insurance'        => 0,
    'drivers'          => 0,
    'contracts'        => 0,
    'cnas'             => 0,
    'leaves'           => 0,
    'slips'            => 0,
    'traveler_reports' => 0,
    'planning'         => 0,
    'debts'            => 0,
    'billing'          => 0,
    'notifications'    => 0,
    'users'            => 0,
    'audit_logs'       => 0,
    'settings'         => 0,
    'expiring_docs'    => $alertCount,
];

if ($pdo) {
    try {
        if ($isTaxiDriver) {
            $stmtDriverSlips = $pdo->prepare("SELECT COUNT(*) FROM transmission_slips WHERE driver_id = ?");
            $stmtDriverSlips->execute([$user['id']]);
            $counts['slips'] = (int)$stmtDriverSlips->fetchColumn();

            $counts['notifications'] = (int)$unreadNotifCount;
            $counts['planning'] = (int)$pdo->query("SELECT COUNT(*) FROM night_watch WHERE status = 'in_park'")->fetchColumn();
            $counts['traveler_reports'] = (int)$pdo->query("SELECT COUNT(*) FROM traveler_reports")->fetchColumn();
            $counts['vehicles'] = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
        } else {
            $counts['vehicles']         = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
            $counts['fleet_tracking']   = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active'")->fetchColumn();
            $counts['drivers']          = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status = 'active'")->fetchColumn();
            $counts['contracts']        = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status = 'signed'")->fetchColumn();
            $counts['cnas']             = (int)$pdo->query("SELECT COUNT(*) FROM cnas_declarations")->fetchColumn();
            $counts['leaves']           = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE status = 'approved' AND end_date >= CURDATE()")->fetchColumn();
            $counts['slips']            = (int)$pdo->query("SELECT COUNT(*) FROM transmission_slips WHERE status = 'active'")->fetchColumn();
            $counts['traveler_reports'] = (int)$pdo->query("SELECT COUNT(*) FROM traveler_reports")->fetchColumn();
            $counts['planning']         = (int)$pdo->query("SELECT COUNT(*) FROM night_watch WHERE status = 'in_park'")->fetchColumn();
            $counts['debts']            = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE status = 'endette'")->fetchColumn();
            $counts['billing']          = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM billings WHERE status = 'paid'")->fetchColumn();
            $counts['notifications']    = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
            $counts['users']            = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $counts['audit_logs']       = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
            $counts['settings']         = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        }
    } catch (\Throwable $e) {
        error_log("Dashboard query error: " . $e->getMessage());
    }
}
?>

<!-- Grid Container: 18 Launcher Modules (GStock Clean Card Style) -->
<div id="modulesGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3.5">

    <!-- ==============================================
         Category 1: الأسطول والمركبات (Fleet & Vehicles)
         ============================================== -->

    <?php if ($isSuperOrAdmin || $isFleetOwner): ?>
    <!-- 1. السائقون والبطاقات المهنية -->
    <a href="<?= url('/dashboard/drivers.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-violet-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-violet-50 text-violet-600 group-hover:bg-violet-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-violet-50 text-violet-700 font-num">
                <?= $counts['drivers'] ?> سائق
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-violet-600 transition">السائقون والبطاقات المهنية</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">دفاتر المقررة والبطاقات الفنية</p>
        </div>
    </a>
    <?php elseif ($isTaxiDriver): ?>
    <!-- 1. بطاقتي الفنية ودفتر المقررة (Driver Dedicated Card) -->
    <a href="<?= url('/dashboard/driver_fiche.php?id=' . $user['id']) ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-violet-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-violet-50 text-violet-600 group-hover:bg-violet-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-violet-50 text-violet-700 font-num">
                ملفي المهني
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-violet-600 transition">بطاقتي الفنية ودفتر المقررة</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">بيانات الركوب، التأمين، والعقد 90-11</p>
        </div>
    </a>
    <?php endif; ?>

    <!-- 2. سجل المركبات والوثائق -->
    <a href="<?= url('/dashboard/vehicles.php') ?>" data-category="fleet" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-amber-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-amber-50 text-amber-600 group-hover:bg-amber-500 group-hover:text-slate-950 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V4.5a2.25 2.25 0 00-2.25-2.25h-4.5A2.25 2.25 0 005.25 4.5v3m11.25 0H5.25" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-800 font-num">
                <?= $counts['vehicles'] ?> مركبة
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-amber-600 transition">سجل المركبات والوثائق</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">البطاقات الرمادية وتجهيزات الأمان</p>
        </div>
    </a>

    <?php if ($isSuperOrAdmin || $isFleetOwner): ?>
    <!-- 3. عقود العمل (قانون 90-11) -->
    <a href="<?= url('/dashboard/contracts.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-teal-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-teal-50 text-teal-600 group-hover:bg-teal-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-teal-50 text-teal-700 font-num">
                <?= $counts['contracts'] ?> موثق
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-teal-600 transition">عقود العمل (قانون 90-11)</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">عقود إيجار واستغلال رخصة الأجرة</p>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($isSuperOrAdmin): ?>
    <!-- 4. الضمان الاجتماعي (CNAS) -->
    <a href="<?= url('/dashboard/cnas.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-sky-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-sky-50 text-sky-600 group-hover:bg-sky-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.333A12.016 12.016 0 0012 9c-2.474 0-4.78.747-6.75 2.033V21" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-sky-50 text-sky-700 font-num">
                <?= $counts['cnas'] ?> تصريح
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-sky-600 transition">الضمان الاجتماعي (CNAS)</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">تصريحات الأجراء والاشتراكات</p>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($isSuperOrAdmin || $isFleetOwner): ?>
    <!-- 5. تتبع الأسطول الميداني -->
    <a href="<?= url('/dashboard/fleet.php') ?>" data-category="fleet" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-blue-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-blue-50 text-blue-600 group-hover:bg-blue-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-blue-50 text-blue-700 font-num">
                <?= $counts['fleet_tracking'] ?> نشطة
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-blue-600 transition">تتبع الأسطول الميداني</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">خريطة ومواقع المركبات في الخدمة</p>
        </div>
    </a>

    <!-- 6. إدارة التأمين والمراقبة التقنية -->
    <a href="<?= url('/dashboard/insurance.php') ?>" data-category="fleet" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-emerald-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-emerald-50 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                </svg>
            </div>
            <?php if ($counts['expiring_docs'] > 0): ?>
                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700 font-num">
                    <?= $counts['expiring_docs'] ?> تنبيه
                </span>
            <?php else: ?>
                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-700 font-num">
                    سارية
                </span>
            <?php endif; ?>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-emerald-600 transition">التأمين والمراقبة التقنية</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">بوالص التأمين، فحص GPL والعداد</p>
        </div>
    </a>

    <!-- 7. السائق البديل والعطل -->
    <a href="<?= url('/dashboard/leaves.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-indigo-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-indigo-50 text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-50 text-indigo-700 font-num">
                <?= $counts['leaves'] ?> عطلة
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-indigo-600 transition">السائق البديل والعطل</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">إنابة المركبات والإجازات الرسمية</p>
        </div>
    </a>
    <?php endif; ?>

    <!-- 8. جداول الإرسال (Bordereaux) -->
    <a href="<?= url('/dashboard/slips.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-amber-500 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-amber-50 text-amber-700 group-hover:bg-amber-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-800 font-num">
                <?= $counts['slips'] ?> إرسال
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-amber-700 transition">جداول الإرسال (Bordereaux)</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">بطاقات الإرسال لمديرية النقل</p>
        </div>
    </a>

    <!-- 9. محاضر المسافرين (PV Voyageurs) -->
    <a href="<?= url('/dashboard/traveler_reports.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-cyan-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-cyan-50 text-cyan-600 group-hover:bg-cyan-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-cyan-50 text-cyan-700 font-num">
                <?= $counts['traveler_reports'] ?> رحلة
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-cyan-600 transition">محاضر المسافرين (PV)</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">دفاتر النقل بين الولايات وقوائم الركاب</p>
        </div>
    </a>

    <!-- 10. حراسة الحظيرة والجدول (3j) -->
    <a href="<?= url('/dashboard/planning.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-purple-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-purple-50 text-purple-600 group-hover:bg-purple-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-purple-50 text-purple-700 font-num">
                <?= $counts['planning'] ?> موقف
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-purple-600 transition">حراسة الحظيرة والجدول (3j)</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">الموقف الليلي ومناوبات السائقين</p>
        </div>
    </a>

    <!-- مركز التدقيق والمصادقة على الملفات (Vérifications) -->
    <a href="<?= url('/dashboard/verifications.php') ?>" data-category="drivers" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-amber-500 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-amber-50 text-amber-600 group-hover:bg-amber-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-800 font-num">
                تدقيق
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-amber-600 transition">مركز التدقيق والمصادقة</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">مراجعة رخص السائقين والملفات قيد الانتظار</p>
        </div>
    </a>

    <!-- ==============================================
         Category 3: المالية والاشتراكات (Finance & Subscriptions)
         ============================================== -->

    <?php if ($isSuperOrAdmin || $isFleetOwner): ?>
    <!-- التحليلات والمؤشرات التنفيذية (Executive Analytics & KPIs) -->
    <a href="<?= url('/dashboard/analytics.php') ?>" data-category="finance" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-amber-500 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-amber-50 text-amber-600 group-hover:bg-amber-500 group-hover:text-slate-950 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-800 font-num">
                Live KPIs
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-amber-600 transition">التحليلات والمؤشرات (KPIs)</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">توازن أعمار الديون ونسب التحصيل</p>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($isSuperOrAdmin): ?>
    <!-- 11. المالية والديون -->
    <a href="<?= url('/dashboard/debts.php') ?>" data-category="finance" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-rose-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-rose-50 text-rose-600 group-hover:bg-rose-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $counts['debts'] > 0 ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600' ?> font-num">
                <?= $counts['debts'] ?> ذمة
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-rose-600 transition">المالية والديون</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">مستحقات السائقين وجداول التحصيل</p>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($isSuperOrAdmin || $isFleetOwner): ?>
    <!-- 12. الفوترة والاشتراكات -->
    <a href="<?= url('/dashboard/billing.php') ?>" data-category="finance" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-emerald-500 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-emerald-50 text-emerald-700 group-hover:bg-emerald-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 002.25 19.5z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-800 font-num">
                <?= number_format($counts['billing']) ?> دج
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-emerald-700 transition">الفوترة والاشتراكات</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">BaridiMob / CCP والدفع الشهري</p>
        </div>
    </a>
    <?php endif; ?>

    <!-- ==============================================
         Category 4: النظام والتنبيهات (System & Alerts)
         ============================================== -->

    <!-- 13. مركز الإشعارات والتنبيهات -->
    <a href="<?= url('/dashboard/notifications.php') ?>" data-category="system" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-amber-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-amber-50 text-amber-600 group-hover:bg-amber-500 group-hover:text-slate-950 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
            </div>
            <?php if ($counts['notifications'] > 0): ?>
                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700 font-num animate-pulse">
                    <?= $counts['notifications'] ?> غير مقروء
                </span>
            <?php else: ?>
                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-500 font-num">
                    هادئ
                </span>
            <?php endif; ?>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-amber-600 transition">مركز الإشعارات والتنبيهات</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">تنبيهات الانتهاء والأوامر الإدارية</p>
        </div>
    </a>

    <?php if ($isSuperOrAdmin): ?>
    <!-- 14. المستخدمون والصلاحيات -->
    <a href="<?= url('/dashboard/users.php') ?>" data-category="system" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-slate-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-slate-100 text-slate-700 group-hover:bg-slate-900 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-700 font-num">
                <?= $counts['users'] ?> مستخدم
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-slate-900 transition">المستخدمون والصلاحيات</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">المشرفون، الملاك والسائقون</p>
        </div>
    </a>

    <!-- 15. سجل التدقيق والرقابة -->
    <a href="<?= url('/dashboard/audit_logs.php') ?>" data-category="system" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-orange-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-orange-50 text-orange-600 group-hover:bg-orange-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-2.171 7.208A7.5 7.5 0 0112 19.5c-4.142 0-7.5-3.358-7.5-7.5 0-2.316 1.053-4.385 2.709-5.753m11.291 0A7.472 7.472 0 0119.5 12c0 1.637-.525 3.152-1.417 4.385M15.75 9l-3.75 3.75" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-orange-50 text-orange-700 font-num">
                <?= $counts['audit_logs'] ?> عملية
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-orange-600 transition">سجل التدقيق والرقابة</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">تتبع العمليات الأمنية والمسارات</p>
        </div>
    </a>

    <!-- 16. إعدادات المنصة والهوية -->
    <a href="<?= url('/dashboard/settings.php') ?>" data-category="system" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-slate-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-slate-100 text-slate-600 group-hover:bg-slate-800 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-500 font-num">
                مهيأة
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-slate-800 transition">إعدادات المنصة والهوية</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">الهوية الجبائية والبيانات البنكية</p>
        </div>
    </a>
    <?php endif; ?>

    <!-- 17. الموقع الرئيسي -->
    <a href="<?= url('/') ?>" target="_blank" data-category="system" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-blue-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-blue-50 text-blue-500 group-hover:bg-blue-500 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-blue-50 text-blue-600 font-num">
                نافذة عامة ↗
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-blue-500 transition">الموقع الرئيسي</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">بوابة المسافرين والطلبات</p>
        </div>
    </a>

    <!-- 18. تسجيل الخروج -->
    <a href="<?= url('/auth/logout.php') ?>" data-category="system" 
       class="launcher-card bg-white rounded-xl border border-slate-200/90 shadow-2xs hover:shadow-md hover:border-rose-400 transition-all cursor-pointer flex flex-col justify-between min-h-[145px] p-4 group">
        <div class="flex items-start justify-between">
            <div class="p-2.5 rounded-lg bg-rose-50 text-rose-500 group-hover:bg-rose-600 group-hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                </svg>
            </div>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700 font-num">
                إنهاء الجلسة
            </span>
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-slate-900 text-[13px] leading-snug group-hover:text-rose-600 transition">تسجيل الخروج</h3>
            <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1">قفل الحساب وتسجيل الخروج</p>
        </div>
    </a>

</div>

<!-- 5. Empty State (When Search or Filter matches nothing) -->
<div id="noResultsState" class="hidden py-16 text-center space-y-3 bg-white rounded-2xl border border-slate-200/90 shadow-2xs">
    <div class="w-12 h-12 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 text-xl font-bold">
        🔍
    </div>
    <div class="text-sm font-bold text-slate-800">لم يتم العثور على أي وحدة مطابقة لبحثك</div>
    <p class="text-xs text-slate-400">تأكد من كتابة الكلمة بشكل صحيح، أو اضغط زر "الكل" لعرض كافة الوحدات</p>
    <button onclick="resetLauncherFilter()" class="mt-2 px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 transition">
        إعادة ضبط البحث والتصفيات
    </button>
</div>

<!-- Dynamic Search & Category Filtering Script -->
<script>
    (function() {
        const searchInput = document.getElementById('launcherSearch');
        const categoryBtns = document.querySelectorAll('.category-btn');
        const cards = document.querySelectorAll('.launcher-card');
        const noResultsState = document.getElementById('noResultsState');

        let currentCategory = 'all';

        function filterModules() {
            const query = (searchInput ? searchInput.value.toLowerCase().trim() : '');
            let visibleCount = 0;

            cards.forEach(card => {
                const category = card.getAttribute('data-category');
                const title = (card.querySelector('h3')?.textContent || '').toLowerCase();
                const desc = (card.querySelector('p')?.textContent || '').toLowerCase();

                const matchesCat = (currentCategory === 'all' || category === currentCategory);
                const matchesQuery = (!query || title.includes(query) || desc.includes(query));

                if (matchesCat && matchesQuery) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (visibleCount === 0) {
                noResultsState.classList.remove('hidden');
            } else {
                noResultsState.classList.add('hidden');
            }
        }

        // Category buttons click handler
        categoryBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                categoryBtns.forEach(b => {
                    b.classList.remove('bg-slate-900', 'text-white');
                    b.classList.add('bg-slate-100', 'text-slate-600', 'hover:bg-slate-200');
                });
                this.classList.remove('bg-slate-100', 'text-slate-600', 'hover:bg-slate-200');
                this.classList.add('bg-slate-900', 'text-white');

                currentCategory = this.getAttribute('data-filter') || 'all';
                filterModules();
            });
        });

        // Live Search input
        if (searchInput) {
            searchInput.addEventListener('input', filterModules);
        }

        // Global reset function
        window.resetLauncherFilter = function() {
            if (searchInput) searchInput.value = '';
            currentCategory = 'all';
            categoryBtns.forEach(b => {
                if (b.getAttribute('data-filter') === 'all') {
                    b.classList.remove('bg-slate-100', 'text-slate-600', 'hover:bg-slate-200');
                    b.classList.add('bg-slate-900', 'text-white');
                } else {
                    b.classList.remove('bg-slate-900', 'text-white');
                    b.classList.add('bg-slate-100', 'text-slate-600', 'hover:bg-slate-200');
                }
            });
            filterModules();
        };
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
