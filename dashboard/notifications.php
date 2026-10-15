<?php
// dashboard/notifications.php - System Notifications Center (الإشعارات وتنبيهات النظام)
$pageTitle = 'Notifications Système | مركز الإشعارات والتنبيهات';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Handle Actions (Mark all read, mark single, delete, trigger audit scan)
if (isset($_REQUEST['action'])) {
    $act = $_REQUEST['action'];
    if ($act === 'mark_all_read') {
        $pdo->query("UPDATE notifications SET is_read = 1");
        set_flash('success', 'تم تحديد جميع الإشعارات كمقروءة');
        redirect('/dashboard/notifications.php');
    } elseif ($act === 'toggle_read' && isset($_REQUEST['id'])) {
        $nId = intval($_REQUEST['id']);
        $pdo->prepare("UPDATE notifications SET is_read = NOT is_read WHERE id = ?")->execute([$nId]);
        redirect('/dashboard/notifications.php');
    } elseif ($act === 'delete' && isset($_REQUEST['id'])) {
        $nId = intval($_REQUEST['id']);
        $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$nId]);
        set_flash('success', 'تم حذف الإشعار بنجاح');
        redirect('/dashboard/notifications.php');
    } elseif ($act === 'run_audit_scan') {
        // Dynamic scan of vehicles and drivers to detect upcoming expirations
        $vExpiring = $pdo->query("
            SELECT plate_number, technical_control_expiry, insurance_expiry 
            FROM vehicles 
            WHERE (technical_control_expiry IS NOT NULL AND technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
               OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
        ")->fetchAll();

        $addedCount = 0;
        foreach ($vExpiring as $v) {
            $msg = "فحص دوري: المركبة {$v['plate_number']} تتطلب المعاينة الفورية للوثائق المكتملة أو الوشيكة.";
            $chk = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE message = ? AND created_at >= CURDATE()");
            $chk->execute([$msg]);
            if ($chk->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO notifications (type, title, message, link, is_read) VALUES ('urgent', 'تنبيه فحص الأسطول الدوري', ?, '/dashboard/vehicles.php?filter=expiring', 0)")
                    ->execute([$msg]);
                $addedCount++;
            }
        }
        log_audit($pdo, 'run_audit_scan', 'notifications', null, "مسح وتدقيق دوري للأسطول - إضافة $addedCount تنبيهات جديدة");
        set_flash('success', "تم إجراء مسح وتدقيق شامل للأسطول وتحديث مركز التنبيهات بنجاح");
        redirect('/dashboard/notifications.php');
    } elseif ($act === 'add_notification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_token();
        if (!is_admin()) {
            set_flash('error', 'غير مصرح.');
            redirect('/dashboard/notifications.php');
        }
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['urgent', 'warning', 'info', 'success']) ? $_POST['type'] : 'info';
        $link = trim($_POST['link'] ?? '');
        if (!empty($title) && !empty($message)) {
            $stmt = $pdo->prepare("INSERT INTO notifications (type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->execute([$type, $title, $message, $link ?: null]);
            $notifId = (int)$pdo->lastInsertId();
            log_audit($pdo, 'notification_created', 'notifications', $notifId, "بث إشعار إداري جديد: $title");
            set_flash('success', 'تم إرسال وبث الإشعار بنجاح');
        } else {
            set_flash('error', 'يرجى إدخال عنوان ونص الإشعار.');
        }
        redirect('/dashboard/notifications.php');
    } elseif ($act === 'delete_all_read') {
        if (!is_admin()) {
            set_flash('error', 'غير مصرح.');
            redirect('/dashboard/notifications.php');
        }
        $count = $pdo->query("DELETE FROM notifications WHERE is_read = 1")->rowCount();
        log_audit($pdo, 'notifications_cleared', 'notifications', null, "تنظيف $count إشعارات مقروءة");
        set_flash('success', "تم حذف وتنظيف $count إشعار مقروء بنجاح");
        redirect('/dashboard/notifications.php');
    }
}

// Filters & Search
$search = trim($_GET['search'] ?? '');
$filterType = $_GET['type'] ?? 'all'; // all, unread, urgent, info

$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(title LIKE ? OR message LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filterType === 'unread') {
    $where[] = "is_read = 0";
} elseif ($filterType === 'urgent') {
    $where[] = "type IN ('urgent', 'alert')";
} elseif ($filterType === 'info') {
    $where[] = "type IN ('info', 'warning', 'success')";
}

$notificationsList = [];
$totalNotifs = 0;
$unreadCount = 0;
$urgentCount = 0;
$infoCount = 0;

if ($pdo) {
    try {
        $sql = "SELECT * FROM notifications WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notificationsList = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        $notificationsList = [];
    }

    try {
        $totalNotifs = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    } catch (\Throwable $e) { $totalNotifs = 0; }

    try {
        $unreadCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    } catch (\Throwable $e) { $unreadCount = 0; }

    try {
        $urgentCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type IN ('urgent', 'alert')")->fetchColumn();
    } catch (\Throwable $e) { $urgentCount = 0; }

    try {
        $infoCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type IN ('info', 'warning', 'success')")->fetchColumn();
    } catch (\Throwable $e) { $infoCount = 0; }
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Notifications Système</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20 font-num">
                    <?= $totalNotifs ?> إشعار
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">مراقبة التنبيهات الفنية، وثائق التأمين، رخص السائقين، واستحقاقات الاشتراكات في الوقت الفعلي</p>
        </div>

        <div class="flex items-center flex-wrap gap-2">
            <?php if (is_admin()): ?>
                <button type="button" onclick="document.getElementById('newNotifModal').classList.remove('hidden')"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>إرسال إشعار جديد</span>
                </button>
            <?php endif; ?>

            <a href="notifications.php?action=run_audit_scan" 
               class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                <span>فحص وتدقيق الأسطول</span>
            </a>
            
            <?php if ($unreadCount > 0): ?>
                <a href="notifications.php?action=mark_all_read" 
                   class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-xs">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    <span>تحديد الكل كمقروء</span>
                </a>
            <?php endif; ?>

            <?php if (is_admin() && ($totalNotifs - $unreadCount) > 0): ?>
                <a href="notifications.php?action=delete_all_read" 
                   onclick="return confirm('هل تريد حذف جميع الإشعارات المقروءة لتنظيف القائمة؟')"
                   class="inline-flex items-center gap-1.5 px-3 py-2.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs transition border border-rose-200/60 shadow-xs" title="حذف وتنظيف المقروءة">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                    <span>تنظيف المقروءة</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4 KPI Metric Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إجمالي الإشعارات</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $totalNotifs ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-amber-700">غير مقروءة (Non Lues)</p>
                <p class="text-xl font-bold text-amber-600 font-num mt-0.5"><?= $unreadCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-rose-700">تنبيهات حرجة وعاجلة</p>
                <p class="text-xl font-bold <?= $urgentCount > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num mt-0.5"><?= $urgentCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $urgentCount > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إشعارات مالية وإدارية</p>
                <p class="text-xl font-bold text-blue-600 font-num mt-0.5"><?= $infoCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="بحث في محتوى الإشعارات والتنبيهات..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filterType !== 'all'): ?>
                <a href="notifications.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <!-- Segmented Tabs -->
        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            <a href="notifications.php?type=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filterType === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                الكل
            </a>
            <a href="notifications.php?type=unread<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filterType === 'unread' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-100 text-amber-700 hover:bg-amber-50' ?>">
                <span>غير مقروءة</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white font-num font-bold"><?= $unreadCount ?></span>
            </a>
            <a href="notifications.php?type=urgent<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filterType === 'urgent' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-rose-700 hover:bg-rose-50' ?>">
                حرجة (Urgentes)
            </a>
            <a href="notifications.php?type=info<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filterType === 'info' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-blue-700 hover:bg-blue-50' ?>">
                إدارية ومالية
            </a>
        </div>

    </div>

    <!-- Notifications Feed Cards -->
    <div class="space-y-3">
        <?php if (!empty($notificationsList)): ?>
            <?php foreach ($notificationsList as $notif): 
                $badgeStyle = [
                    'urgent' => 'bg-rose-50 text-rose-700 border-rose-200',
                    'alert' => 'bg-amber-50 text-amber-800 border-amber-200',
                    'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
                    'info' => 'bg-blue-50 text-blue-700 border-blue-200',
                    'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200'
                ][$notif['type']] ?? 'bg-slate-100 text-slate-700 border-slate-200';
            ?>
                <div class="bg-white p-4 sm:p-5 rounded-2xl border <?= !$notif['is_read'] ? 'border-amber-300 ring-1 ring-amber-200/50 shadow-xs' : 'border-slate-200 shadow-2xs' ?> transition flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    
                    <div class="flex items-start gap-3.5 flex-1 min-w-0">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 <?= !$notif['is_read'] ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600' ?>">
                            <?php if ($notif['type'] === 'urgent' || $notif['type'] === 'alert'): ?>
                                <svg class="w-5 h-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                            <?php else: ?>
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                                </svg>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-1 min-w-0 flex-1 text-xs">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold border uppercase font-sans <?= $badgeStyle ?>">
                                    <?= htmlspecialchars($notif['type']) ?>
                                </span>
                                <h3 class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($notif['title']) ?></h3>
                                <?php if (!$notif['is_read']): ?>
                                    <span class="px-2 py-0.2 rounded-full text-[10px] bg-rose-500 text-white font-bold">جديد</span>
                                <?php endif; ?>
                            </div>

                            <p class="text-slate-600 leading-relaxed text-xs">
                                <?= htmlspecialchars($notif['message']) ?>
                            </p>

                            <div class="flex items-center gap-3 pt-1 text-[11px] text-slate-400 font-num">
                                <span dir="ltr"><?= date('Y-m-d H:i', strtotime($notif['created_at'])) ?></span>
                                <span>•</span>
                                <span>الحالة: <strong class="<?= $notif['is_read'] ? 'text-slate-500' : 'text-amber-700' ?>"><?= $notif['is_read'] ? 'مقروء' : 'غير مقروء' ?></strong></span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-center gap-2 self-end sm:self-center shrink-0 text-xs">
                        <?php if (!empty($notif['link'])): ?>
                            <a href="<?= htmlspecialchars($notif['link']) ?>" 
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-2xs">
                                <span>معالجة وتفاصيل</span>
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                                </svg>
                            </a>
                        <?php endif; ?>

                        <a href="notifications.php?action=toggle_read&id=<?= $notif['id'] ?>" 
                           title="<?= $notif['is_read'] ? 'تحديد كغير مقروء' : 'تحديد كمقروء' ?>"
                           class="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600 transition">
                            <?php if ($notif['is_read']): ?>
                                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                            <?php else: ?>
                                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            <?php endif; ?>
                        </a>

                        <a href="notifications.php?action=delete&id=<?= $notif['id'] ?>" 
                           onclick="return confirm('هل تريد حذف هذا الإشعار؟')"
                           title="حذف الإشعار"
                           class="p-1.5 rounded-lg border border-slate-200 hover:bg-rose-50 hover:text-rose-600 text-slate-400 transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </a>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 text-xs bg-white rounded-2xl border border-slate-200 space-y-2">
                <div class="w-12 h-12 mx-auto rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="font-bold text-slate-700 text-sm">لا توجد إشعارات مطابقة حالياً</div>
                <p class="text-slate-400 text-xs">جميع وثائق الأسطول واشتراكات السائقين ومواعيد المراقبة التقنية في حالة نظامية.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php if (is_admin()): ?>
<!-- Modal: New Manual Notification -->
<div id="newNotifModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-bold text-slate-900">إرسال وبث إشعار نظام جديد</h3>
                <span class="text-xs text-slate-500">نشر تنبيه أو رسالة إدارية لكافة مستخدمي المنصة</span>
            </div>
            <button type="button" onclick="document.getElementById('newNotifModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="notifications.php?action=add_notification" class="space-y-4 text-xs">
            <?= csrf_field() ?>

            <div>
                <label class="block font-bold text-slate-700 mb-1">عنوان الإشعار *</label>
                <input type="text" name="title" required placeholder="مثال: تنبيه بخصوص تجديد وثائق التأمين لشهر نوفمبر"
                       class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">نوع الإشعار ومستوى الأهمية *</label>
                    <select name="type" required class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="info">ℹ️ معلومة عادية (Info)</option>
                        <option value="warning">⚠️ تحذير إداري (Warning)</option>
                        <option value="urgent">🚨 عاجل وحرج (Urgent)</option>
                        <option value="success">✅ نجاح / إشعار إيجابي (Success)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رابط الإجراء السريع (اختياري)</label>
                    <input type="text" name="link" placeholder="مثال: /dashboard/vehicles.php"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-mono text-[11px]" dir="ltr">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">نص وتفاصيل الإشعار *</label>
                <textarea name="message" rows="3" required placeholder="اكتب تفاصيل التنبيه أو التوجيهات الموجهة للسائقين أو الإدارة..."
                          class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newNotifModal').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition">
                    بث الإشعار الآن
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
