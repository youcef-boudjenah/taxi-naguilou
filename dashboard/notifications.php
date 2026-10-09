<?php
// dashboard/notifications.php - System Notifications Center (الإشعارات وتنبيهات النظام)
$pageTitle = 'Notifications Système | مركز الإشعارات والتنبيهات';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Handle Actions (Mark all read, mark single, delete, trigger audit scan)
if (isset($_GET['action'])) {
    $act = $_GET['action'];
    if ($act === 'mark_all_read') {
        $pdo->query("UPDATE notifications SET is_read = 1");
        set_flash('success', 'تم تحديد جميع الإشعارات كمقروءة');
        header('Location: /dashboard/notifications.php');
        exit;
    } elseif ($act === 'toggle_read' && isset($_GET['id'])) {
        $nId = intval($_GET['id']);
        $pdo->prepare("UPDATE notifications SET is_read = NOT is_read WHERE id = ?")->execute([$nId]);
        header('Location: /dashboard/notifications.php');
        exit;
    } elseif ($act === 'delete' && isset($_GET['id'])) {
        $nId = intval($_GET['id']);
        $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$nId]);
        set_flash('success', 'تم حذف الإشعار بنجاح');
        header('Location: /dashboard/notifications.php');
        exit;
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
        set_flash('success', "تم إجراء مسح وتدقيق شامل للأسطول وتحديث مركز التنبيهات بنجاح");
        header('Location: /dashboard/notifications.php');
        exit;
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

$sql = "SELECT * FROM notifications WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notificationsList = $stmt->fetchAll();

// KPI Counts
$totalNotifs = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$unreadCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
$urgentCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type IN ('urgent', 'alert')")->fetchColumn();
$infoCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type IN ('info', 'warning', 'success')")->fetchColumn();
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-black text-slate-900">Notifications Système</h2>
                <span class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-0.5 rounded-full">
                    مركز الإشعارات والتنبيهات الحية
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">Surveillance en temps réel des alertes techniques, d'assurance, des chauffeurs et de la facturation.</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="/dashboard/notifications.php?action=run_audit_scan" 
               class="px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition flex items-center gap-1.5 shadow-sm">
                <span>🔍</span>
                <span>فحص وتدقيق الأسطول (Scan d'audit)</span>
            </a>
            
            <?php if ($unreadCount > 0): ?>
                <a href="/dashboard/notifications.php?action=mark_all_read" 
                   class="px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                    <span>✓</span>
                    <span>Tout marquer comme lu</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4 KPI Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        
        <!-- Total -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500">إجمالي الإشعارات</span>
                <span class="w-8 h-8 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-sm font-bold">🔔</span>
            </div>
            <div class="text-3xl font-black text-slate-900 font-num"><?= $totalNotifs ?></div>
            <div class="text-[11px] text-slate-400 font-semibold">سجل الأحداث والعمليات</div>
        </div>

        <!-- Non Lues (Unread) -->
        <div class="bg-white p-5 rounded-2xl border <?= $unreadCount > 0 ? 'border-amber-300 ring-2 ring-amber-100 bg-amber-50/20' : 'border-slate-200' ?> shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-amber-700">غير مقروءة (Non Lues)</span>
                <span class="w-8 h-8 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-sm font-bold">✉️</span>
            </div>
            <div class="text-3xl font-black text-amber-600 font-num"><?= $unreadCount ?></div>
            <div class="text-[11px] text-amber-600 font-semibold">تتطلب المراجعة والاطلاع</div>
        </div>

        <!-- Alertes Urgentes -->
        <div class="bg-white p-5 rounded-2xl border <?= $urgentCount > 0 ? 'border-rose-300 bg-rose-50/20' : 'border-slate-200' ?> shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-rose-700">تنبيهات حرجة وعاجلة</span>
                <span class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center text-sm font-bold">🚨</span>
            </div>
            <div class="text-3xl font-black text-rose-600 font-num"><?= $urgentCount ?></div>
            <div class="text-[11px] text-rose-600 font-semibold">مراقبة تقنية، تأمين، أو رخص منتهية</div>
        </div>

        <!-- Info & Facturation -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-blue-700">إشعارات مالية وإدارية</span>
                <span class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-sm font-bold">ℹ️</span>
            </div>
            <div class="text-3xl font-black text-blue-600 font-num"><?= $infoCount ?></div>
            <div class="text-[11px] text-blue-600 font-semibold">اشتراكات، جداول إرسال، وعقود</div>
        </div>

    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="بحث في محتوى الإشعارات والتنبيهات..."
                       class="w-full px-3.5 py-2 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 text-white font-bold">بحث</button>
            <?php if (!empty($search) || $filterType !== 'all'): ?>
                <a href="/dashboard/notifications.php" class="text-slate-400 hover:text-slate-600 underline">إعادة ضبط</a>
            <?php endif; ?>
        </form>

        <!-- Segmented Tabs -->
        <div class="flex items-center gap-1.5 w-full sm:w-auto">
            <a href="/dashboard/notifications.php?type=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition <?= $filterType === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                Toutes (الكل)
            </a>
            <a href="/dashboard/notifications.php?type=unread<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition flex items-center gap-1 <?= $filterType === 'unread' ? 'bg-amber-500 text-slate-950 font-black' : 'bg-slate-100 text-amber-700 hover:bg-amber-50' ?>">
                <span>Non Lues</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white font-num font-bold"><?= $unreadCount ?></span>
            </a>
            <a href="/dashboard/notifications.php?type=urgent<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition <?= $filterType === 'urgent' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-rose-700 hover:bg-rose-50' ?>">
                Urgentes (حرجة)
            </a>
            <a href="/dashboard/notifications.php?type=info<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition <?= $filterType === 'info' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-blue-700 hover:bg-blue-50' ?>">
                Informations (إدارية)
            </a>
        </div>

    </div>

    <!-- Notifications Feed Cards -->
    <div class="space-y-3">
        <?php if (!empty($notificationsList)): ?>
            <?php foreach ($notificationsList as $notif): 
                $badgeStyle = [
                    'urgent' => 'bg-rose-100 text-rose-800 border-rose-300',
                    'alert' => 'bg-amber-100 text-amber-800 border-amber-300',
                    'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
                    'info' => 'bg-blue-50 text-blue-700 border-blue-200',
                    'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200'
                ][$notif['type']] ?? 'bg-slate-100 text-slate-700 border-slate-200';

                $iconEmoji = [
                    'urgent' => '🚨',
                    'alert' => '⚠️',
                    'warning' => '⏳',
                    'info' => 'ℹ️',
                    'success' => '✅'
                ][$notif['type']] ?? '🔔';
            ?>
                <div class="bg-white p-5 rounded-2xl border <?= !$notif['is_read'] ? 'border-amber-300 ring-1 ring-amber-200/50 shadow-md' : 'border-slate-200 shadow-sm' ?> transition flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    
                    <div class="flex items-start gap-3.5 flex-1 min-w-0">
                        <span class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0 <?= !$notif['is_read'] ? 'bg-amber-100' : 'bg-slate-100' ?>">
                            <?= $iconEmoji ?>
                        </span>

                        <div class="space-y-1 min-w-0 flex-1 text-xs">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded text-[10px] font-black border uppercase font-sans <?= $badgeStyle ?>">
                                    <?= htmlspecialchars($notif['type']) ?>
                                </span>
                                <h3 class="font-extrabold text-slate-900 text-sm"><?= htmlspecialchars($notif['title']) ?></h3>
                                <?php if (!$notif['is_read']): ?>
                                    <span class="px-2 py-0.2 rounded-full text-[10px] bg-rose-500 text-white font-bold">جديد</span>
                                <?php endif; ?>
                            </div>

                            <p class="text-slate-600 leading-relaxed text-xs">
                                <?= htmlspecialchars($notif['message']) ?>
                            </p>

                            <div class="flex items-center gap-3 pt-1 text-[11px] text-slate-400 font-num">
                                <span><?= date('Y-m-d H:i', strtotime($notif['created_at'])) ?></span>
                                <span>•</span>
                                <span>الحالة: <strong class="<?= $notif['is_read'] ? 'text-slate-500' : 'text-amber-700' ?>"><?= $notif['is_read'] ? 'مقروء' : 'غير مقروء' ?></strong></span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-center gap-2 self-end sm:self-center shrink-0 text-xs">
                        <?php if (!empty($notif['link'])): ?>
                            <a href="<?= htmlspecialchars($notif['link']) ?>" 
                               class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-sm flex items-center gap-1.5">
                                <span>🔍</span>
                                <span>معالجة وتفاصيل ←</span>
                            </a>
                        <?php endif; ?>

                        <a href="/dashboard/notifications.php?action=toggle_read&id=<?= $notif['id'] ?>" 
                           title="<?= $notif['is_read'] ? 'تحديد كغير مقروء' : 'تحديد كمقروء' ?>"
                           class="p-2 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-600 transition">
                            <?= $notif['is_read'] ? '✉️' : '✓' ?>
                        </a>

                        <a href="/dashboard/notifications.php?action=delete&id=<?= $notif['id'] ?>" 
                           onclick="return confirm('هل تريد حذف هذا الإشعار؟')"
                           title="حذف الإشعار"
                           class="p-2 rounded-xl border border-slate-200 hover:bg-rose-50 hover:text-rose-600 text-slate-400 transition">
                            🗑️
                        </a>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 text-xs bg-white rounded-2xl border border-slate-200 space-y-2">
                <span class="text-3xl block">🎉</span>
                <div class="font-bold text-slate-700">لا توجد إشعارات مطابقة حالياً</div>
                <p class="text-slate-400">جميع وثائق الأسطول واشتراكات السائقين ومواعيد المراقبة التقنية في حالة نظامية.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
