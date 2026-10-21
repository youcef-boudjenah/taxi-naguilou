<?php
// dashboard/archive.php - Central Recycle Bin & Archive Management Center
$pageTitle = 'سلة المهملات والأرشيف | Corbeille & Archives';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

// Handle Actions (Restore, Permanent Delete, Bulk Restore, Empty Trash)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    $action = $_POST['action'];

    if ($action === 'restore_item') {
        $table = trim($_POST['table'] ?? '');
        $id = intval($_POST['item_id'] ?? 0);
        $title = trim($_POST['item_title'] ?? "العنصر رقم $id");

        if (!empty($table) && $id > 0) {
            $success = restore_entity($pdo, $table, $id);
            if ($success) {
                set_flash('success', "تم استرجاع ($title) بنجاح وإعادته إلى السجل النشط.");
            } else {
                set_flash('error', "تعذر استرجاع ($title). يرجى المحاولة مرة أخرى.");
            }
        }
        redirect('/dashboard/archive.php' . (!empty($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    }

    if ($action === 'force_delete_item') {
        if (!is_admin()) {
            set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بالحذف النهائي.');
            redirect('/dashboard/archive.php');
        }
        $table = trim($_POST['table'] ?? '');
        $id = intval($_POST['item_id'] ?? 0);
        $title = trim($_POST['item_title'] ?? "العنصر رقم $id");

        if (!empty($table) && $id > 0) {
            $success = permanent_delete_entity($pdo, $table, $id);
            if ($success) {
                set_flash('success', "تم حذف ($title) نهائياً من قاعدة البيانات.");
            } else {
                set_flash('error', "تعذر الحذف النهائي لـ ($title) لوجود ارتباطات رسمية ملزمة في النظام.");
            }
        }
        redirect('/dashboard/archive.php' . (!empty($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    }

    if ($action === 'restore_all') {
        $type = trim($_POST['target_type'] ?? 'all');
        $tablesToRestore = [];
        if ($type === 'vehicles') $tablesToRestore = ['vehicles'];
        elseif ($type === 'drivers') $tablesToRestore = ['users'];
        elseif ($type === 'contracts') $tablesToRestore = ['contracts'];
        elseif ($type === 'insurance') $tablesToRestore = ['insurance_policies'];
        elseif ($type === 'leaves') $tablesToRestore = ['leaves'];
        elseif ($type === 'debts') $tablesToRestore = ['driver_debts', 'versements', 'driver_subscriptions'];
        elseif ($type === 'slips') $tablesToRestore = ['transmission_slips', 'traveler_reports'];
        else $tablesToRestore = ['vehicles', 'users', 'contracts', 'insurance_policies', 'leaves', 'driver_debts', 'versements', 'transmission_slips', 'driver_subscriptions', 'cnas_declarations', 'traveler_reports'];

        $countRestored = 0;
        foreach ($tablesToRestore as $tbl) {
            try {
                $cond = ($tbl === 'users' && $type === 'drivers') ? "WHERE role = 'driver' AND deleted_at IS NOT NULL" : "WHERE deleted_at IS NOT NULL";
                $stmt = $pdo->query("SELECT id FROM `$tbl` $cond");
                while ($rowId = $stmt->fetchColumn()) {
                    if (restore_entity($pdo, $tbl, (int)$rowId)) {
                        $countRestored++;
                    }
                }
            } catch (\Throwable $e) {}
        }
        set_flash('success', "تم استرجاع $countRestored عنصر(اً) بنجاح إلى النظام النشط.");
        redirect('/dashboard/archive.php' . (!empty($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    }

    if ($action === 'empty_trash') {
        if (!is_super_admin()) {
            set_flash('error', 'عذراً، إفراغ سلة المهملات بالكامل محصور بالمدير العام فقط.');
            redirect('/dashboard/archive.php');
        }
        $targetTables = ['vehicles', 'users', 'contracts', 'insurance_policies', 'leaves', 'driver_debts', 'versements', 'transmission_slips', 'driver_subscriptions', 'cnas_declarations', 'traveler_reports'];
        $purgedCount = 0;
        foreach ($targetTables as $tbl) {
            try {
                $stmt = $pdo->query("SELECT id FROM `$tbl` WHERE deleted_at IS NOT NULL");
                while ($rowId = $stmt->fetchColumn()) {
                    if (permanent_delete_entity($pdo, $tbl, (int)$rowId)) {
                        $purgedCount++;
                    }
                }
            } catch (\Throwable $e) {}
        }
        set_flash('success', "تم إفراغ سلة المهملات وحذف $purgedCount سجل نهائياً.");
        redirect('/dashboard/archive.php');
    }
}

// Fetch Trash Counts
$trashCounts = get_trash_counts($pdo);

// Active Tab & Search
$type = trim($_GET['type'] ?? 'all');
$search = trim($_GET['search'] ?? '');

// Helper for Relative Arabic Time
function arabic_time_ago(string $datetime): string {
    $time = strtotime($datetime);
    if (!$time) return '-';
    $diff = time() - $time;
    if ($diff < 60) return 'منذ لحظات';
    if ($diff < 3600) return 'منذ ' . floor($diff / 60) . ' دقيقة';
    if ($diff < 86400) return 'منذ ' . floor($diff / 3600) . ' ساعة';
    if ($diff < 604800) return 'منذ ' . floor($diff / 86400) . ' يوم';
    return date('Y-m-d H:i', $time);
}

// Build Unified Trash Items List
$items = [];

// 1. Vehicles
if (in_array($type, ['all', 'vehicles'])) {
    try {
        $stmtV = $pdo->query("SELECT id, brand, model, plate_number, door_number, color, deleted_at FROM vehicles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
        while ($v = $stmtV->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'table'      => 'vehicles',
                'id'         => (int)$v['id'],
                'type'       => 'vehicle',
                'type_name'  => 'مركبة أسطول',
                'icon'       => '🚗',
                'badge_cls'  => 'bg-blue-50 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border-blue-200 dark:border-blue-900',
                'title'      => trim($v['brand'] . ' ' . $v['model']) ?: 'مركبة بدون اسم',
                'subtitle'   => 'لوحة: ' . str_replace('/', '-', $v['plate_number']) . (!empty($v['door_number']) ? ' • باب: ' . $v['door_number'] : ''),
                'deleted_at' => $v['deleted_at'],
                'raw_search' => $v['brand'] . ' ' . $v['model'] . ' ' . $v['plate_number'] . ' ' . $v['door_number']
            ];
        }
    } catch (\Throwable $e) {}
}

// 2. Drivers & Users
if (in_array($type, ['all', 'drivers', 'users'])) {
    try {
        $roleCond = ($type === 'drivers') ? "AND role = 'driver'" : (($type === 'users') ? "AND role != 'driver'" : "");
        $stmtU = $pdo->query("SELECT id, full_name, phone, role, license_number, deleted_at FROM users WHERE deleted_at IS NOT NULL $roleCond ORDER BY deleted_at DESC");
        while ($u = $stmtU->fetch(PDO::FETCH_ASSOC)) {
            $isDriver = ($u['role'] === 'driver');
            $items[] = [
                'table'      => 'users',
                'id'         => (int)$u['id'],
                'type'       => $isDriver ? 'driver' : 'user',
                'type_name'  => $isDriver ? 'سائق' : 'مستخدم نظام',
                'icon'       => $isDriver ? '👤' : '👥',
                'badge_cls'  => $isDriver ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-200 dark:border-amber-900' : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200 border-slate-300 dark:border-slate-700',
                'title'      => $u['full_name'] ?: 'مستخدم بدون اسم',
                'subtitle'   => 'هاتف: ' . $u['phone'] . ($isDriver && !empty($u['license_number']) ? ' • رخصة: ' . $u['license_number'] : ' • رتبة: ' . $u['role']),
                'deleted_at' => $u['deleted_at'],
                'raw_search' => $u['full_name'] . ' ' . $u['phone'] . ' ' . $u['license_number'] . ' ' . $u['role']
            ];
        }
    } catch (\Throwable $e) {}
}

// 3. Contracts
if (in_array($type, ['all', 'contracts'])) {
    try {
        $stmtC = $pdo->query("
            SELECT c.id, c.contract_number, c.monthly_wage, c.deleted_at, u.full_name as driver_name, v.plate_number
            FROM contracts c
            LEFT JOIN users u ON c.driver_id = u.id
            LEFT JOIN vehicles v ON c.vehicle_id = v.id
            WHERE c.deleted_at IS NOT NULL
            ORDER BY c.deleted_at DESC
        ");
        while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'table'      => 'contracts',
                'id'         => (int)$c['id'],
                'type'       => 'contract',
                'type_name'  => 'عقد عمل 90-11',
                'icon'       => '📋',
                'badge_cls'  => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900',
                'title'      => 'عقد رقم ' . $c['contract_number'],
                'subtitle'   => 'السائق: ' . ($c['driver_name'] ?? 'غير محدد') . ($c['plate_number'] ? ' • مركبة: ' . $c['plate_number'] : '') . ' • أجر: ' . number_format((float)$c['monthly_wage']) . ' دج',
                'deleted_at' => $c['deleted_at'],
                'raw_search' => $c['contract_number'] . ' ' . $c['driver_name'] . ' ' . $c['plate_number']
            ];
        }
    } catch (\Throwable $e) {}
}

// 4. Insurance Policies
if (in_array($type, ['all', 'insurance'])) {
    try {
        $stmtI = $pdo->query("
            SELECT p.id, p.policy_number, p.company, p.start_date, p.end_date, p.deleted_at, v.plate_number
            FROM insurance_policies p
            LEFT JOIN vehicles v ON p.vehicle_id = v.id
            WHERE p.deleted_at IS NOT NULL
            ORDER BY p.deleted_at DESC
        ");
        while ($i = $stmtI->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'table'      => 'insurance_policies',
                'id'         => (int)$i['id'],
                'type'       => 'insurance',
                'type_name'  => 'وثيقة تأمين',
                'icon'       => '🛡️',
                'badge_cls'  => 'bg-cyan-50 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300 border-cyan-200 dark:border-cyan-900',
                'title'      => 'بوليصة ' . $i['policy_number'] . ' (' . $i['company'] . ')',
                'subtitle'   => 'صلاحية: ' . $i['start_date'] . ' إلى ' . $i['end_date'] . ($i['plate_number'] ? ' • مركبة: ' . $i['plate_number'] : ''),
                'deleted_at' => $i['deleted_at'],
                'raw_search' => $i['policy_number'] . ' ' . $i['company'] . ' ' . $i['plate_number']
            ];
        }
    } catch (\Throwable $e) {}
}

// 5. Leaves & Relief
if (in_array($type, ['all', 'leaves'])) {
    try {
        $stmtL = $pdo->query("
            SELECT l.id, l.leave_type, l.start_date, l.end_date, l.deleted_at, u.full_name as driver_name, r.full_name as repl_name
            FROM leaves l
            LEFT JOIN users u ON l.driver_id = u.id
            LEFT JOIN users r ON l.replacement_driver_id = r.id
            WHERE l.deleted_at IS NOT NULL
            ORDER BY l.deleted_at DESC
        ");
        while ($l = $stmtL->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'table'      => 'leaves',
                'id'         => (int)$l['id'],
                'type'       => 'leave',
                'type_name'  => 'عطلة واستخلاف',
                'icon'       => '🔄',
                'badge_cls'  => 'bg-purple-50 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border-purple-200 dark:border-purple-900',
                'title'      => 'إجازة للسائق: ' . ($l['driver_name'] ?? 'سائق'),
                'subtitle'   => 'فترة: ' . $l['start_date'] . ' إلى ' . $l['end_date'] . ($l['repl_name'] ? ' • السائق البديل: ' . $l['repl_name'] : ''),
                'deleted_at' => $l['deleted_at'],
                'raw_search' => $l['driver_name'] . ' ' . $l['repl_name'] . ' ' . $l['leave_type']
            ];
        }
    } catch (\Throwable $e) {}
}

// 6. Debts & Versements
if (in_array($type, ['all', 'debts'])) {
    try {
        $stmtD = $pdo->query("SELECT id, chauffeur_name, phone, total_credits, deleted_at FROM driver_debts WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
        while ($d = $stmtD->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'table'      => 'driver_debts',
                'id'         => (int)$d['id'],
                'type'       => 'debt',
                'type_name'  => 'قيد مالي / ذمة',
                'icon'       => '💰',
                'badge_cls'  => 'bg-amber-50 text-amber-900 dark:bg-amber-950/60 dark:text-amber-200 border-amber-300 dark:border-amber-800',
                'title'      => 'ذمة مالية: ' . $d['chauffeur_name'],
                'subtitle'   => 'مبلغ: ' . number_format((float)$d['total_credits'], 2) . ' دج • هاتف: ' . $d['phone'],
                'deleted_at' => $d['deleted_at'],
                'raw_search' => $d['chauffeur_name'] . ' ' . $d['phone']
            ];
        }
    } catch (\Throwable $e) {}
}

// 7. Slips & Reports
if (in_array($type, ['all', 'slips'])) {
    try {
        $stmtS = $pdo->query("
            SELECT s.id, s.slip_number, s.route_line, s.deleted_at, u.full_name as driver_name, v.plate_number
            FROM transmission_slips s
            LEFT JOIN users u ON s.driver_id = u.id
            LEFT JOIN vehicles v ON s.vehicle_id = v.id
            WHERE s.deleted_at IS NOT NULL
            ORDER BY s.deleted_at DESC
        ");
        while ($s = $stmtS->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'table'      => 'transmission_slips',
                'id'         => (int)$s['id'],
                'type'       => 'slip',
                'type_name'  => 'جدول إرسال DTW',
                'icon'       => '📄',
                'badge_cls'  => 'bg-indigo-50 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300 border-indigo-200 dark:border-indigo-900',
                'title'      => 'جدول إرسال رقم ' . $s['slip_number'],
                'subtitle'   => 'مسار: ' . $s['route_line'] . ' • سائق: ' . ($s['driver_name'] ?? '-') . ($s['plate_number'] ? ' • مركبة: ' . $s['plate_number'] : ''),
                'deleted_at' => $s['deleted_at'],
                'raw_search' => $s['slip_number'] . ' ' . $s['route_line'] . ' ' . $s['driver_name'] . ' ' . $s['plate_number']
            ];
        }

        $stmtR = $pdo->query("
            SELECT r.id, r.line_name, r.departure_date, r.departure_time, r.deleted_at, u.full_name as driver_name, v.plate_number
            FROM traveler_reports r
            LEFT JOIN users u ON r.driver_id = u.id
            LEFT JOIN vehicles v ON r.vehicle_id = v.id
            WHERE r.deleted_at IS NOT NULL
            ORDER BY r.deleted_at DESC
        ");
        while ($r = $stmtR->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'table'      => 'traveler_reports',
                'id'         => (int)$r['id'],
                'type'       => 'report',
                'type_name'  => 'محضر مسافرين PV',
                'icon'       => '🧳',
                'badge_cls'  => 'bg-rose-50 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border-rose-200 dark:border-rose-900',
                'title'      => 'محضر رحلة: ' . $r['line_name'],
                'subtitle'   => 'تاريخ: ' . $r['departure_date'] . ' ' . $r['departure_time'] . ' • سائق: ' . ($r['driver_name'] ?? '-') . ($r['plate_number'] ? ' • مركبة: ' . $r['plate_number'] : ''),
                'deleted_at' => $r['deleted_at'],
                'raw_search' => $r['line_name'] . ' ' . $r['driver_name'] . ' ' . $r['plate_number']
            ];
        }
    } catch (\Throwable $e) {}
}

// Search filter application
if (!empty($search)) {
    $searchLower = mb_strtolower($search);
    $items = array_values(array_filter($items, function($item) use ($searchLower) {
        return (mb_strpos(mb_strtolower($item['title']), $searchLower) !== false)
            || (mb_strpos(mb_strtolower($item['subtitle']), $searchLower) !== false)
            || (mb_strpos(mb_strtolower($item['raw_search']), $searchLower) !== false);
    }));
}

// Sort items by deleted_at descending
usort($items, function($a, $b) {
    return strcmp($b['deleted_at'] ?? '', $a['deleted_at'] ?? '');
});

$totalFilteredItems = count($items);
?>

<div class="space-y-6">

    <!-- Top Header & Actions -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-2xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold text-xl">
                    🗑️
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">سلة المهملات والأرشيف المركزي</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">إدارة واسترجاع جميع السجلات المحذوفة (مركبات، سائقون، عقود، وثائق رسمية) بنقرة زر واحدة</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <?php if ($totalFilteredItems > 0): ?>
                <form method="POST" action="" onsubmit="return confirm('هل تريد استرجاع جميع العناصر المعروضة حالياً وإعادتها دفعة واحدة للسجلات النشطة؟')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore_all">
                    <input type="hidden" name="target_type" value="<?= htmlspecialchars($type) ?>">
                    <button type="submit" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-xs flex items-center gap-1.5 cursor-pointer">
                        <span>استرجاع الكل 🔄</span>
                    </button>
                </form>

                <?php if (is_super_admin()): ?>
                <form method="POST" action="" onsubmit="return confirm('تحذير نهائي شديد الأهمية: هل أنت متأكد من رغبتك في إفراغ سلة المهملات نهائياً؟ سيتم حذف جميع السجلات المعلقة للأبد ولن يمكن استرجاعها!')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="empty_trash">
                    <button type="submit" class="px-3.5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs transition shadow-xs flex items-center gap-1.5 cursor-pointer">
                        <span>إفراغ سلة المهملات ⚠️</span>
                    </button>
                </form>
                <?php endif; ?>
            <?php endif; ?>

            <a href="vehicles.php" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition">
                العودة للأسطول 🚗
            </a>
            <a href="drivers.php" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition">
                العودة للسائقين 👤
            </a>
        </div>
    </div>

    <!-- Quick Stats Metric Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200 dark:border-slate-800 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-slate-500 dark:text-slate-400">إجمالي العناصر في السلة</p>
                <p class="text-2xl font-black text-slate-900 dark:text-white font-num mt-1"><?= $trashCounts['total'] ?></p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xl font-bold">
                🗑️
            </div>
        </div>

        <a href="archive.php?type=vehicles" class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border <?= $type === 'vehicles' ? 'border-blue-500 ring-2 ring-blue-500/20' : 'border-slate-200 dark:border-slate-800' ?> shadow-xs flex items-center justify-between hover:border-blue-400 transition group">
            <div>
                <p class="text-[11px] font-bold text-slate-500 dark:text-slate-400 group-hover:text-blue-600 transition">المركبات المحذوفة</p>
                <p class="text-2xl font-black text-blue-600 font-num mt-1"><?= $trashCounts['vehicles'] ?></p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xl font-bold group-hover:bg-blue-600 group-hover:text-white transition">
                🚗
            </div>
        </a>

        <a href="archive.php?type=drivers" class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border <?= $type === 'drivers' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'border-slate-200 dark:border-slate-800' ?> shadow-xs flex items-center justify-between hover:border-amber-400 transition group">
            <div>
                <p class="text-[11px] font-bold text-slate-500 dark:text-slate-400 group-hover:text-amber-600 transition">السائقون المحذوفون</p>
                <p class="text-2xl font-black text-amber-600 font-num mt-1"><?= $trashCounts['drivers'] ?></p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xl font-bold group-hover:bg-amber-600 group-hover:text-white transition">
                👤
            </div>
        </a>

        <a href="archive.php?type=contracts" class="p-4 rounded-2xl bg-white dark:bg-[#161d27] border <?= $type === 'contracts' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'border-slate-200 dark:border-slate-800' ?> shadow-xs flex items-center justify-between hover:border-emerald-400 transition group">
            <div>
                <p class="text-[11px] font-bold text-slate-500 dark:text-slate-400 group-hover:text-emerald-600 transition">عقود العمل المحذوفة</p>
                <p class="text-2xl font-black text-emerald-600 font-num mt-1"><?= $trashCounts['contracts'] ?></p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xl font-bold group-hover:bg-emerald-600 group-hover:text-white transition">
                📋
            </div>
        </a>
    </div>

    <!-- Toolbar: Search & Segmented Filter Tabs -->
    <div class="bg-white dark:bg-[#161d27] p-3 sm:p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 text-xs">
        
        <!-- Search Input -->
        <form method="GET" class="flex-1 w-full max-w-md flex items-center gap-2">
            <?php if ($type !== 'all'): ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
            <?php endif; ?>
            <div class="relative flex-1">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث بالاسم، رقم اللوحة، رقم العقد، أو الهاتف..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 dark:bg-rose-600 text-white font-bold hover:bg-slate-800 dark:hover:bg-rose-500 transition cursor-pointer">بحث</button>
            <?php if (!empty($search)): ?>
                <a href="archive.php<?= $type !== 'all' ? '?type=' . urlencode($type) : '' ?>" class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 underline">مسح</a>
            <?php endif; ?>
        </form>

        <!-- Category Tabs -->
        <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-0.5">
            <a href="archive.php?type=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap <?= $type === 'all' ? 'bg-slate-900 dark:bg-rose-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200' ?>">
                الكل (<?= $trashCounts['total'] ?>)
            </a>
            <a href="archive.php?type=vehicles<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1 <?= $type === 'vehicles' ? 'bg-blue-600 text-white shadow-xs' : 'bg-blue-50 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300 hover:bg-blue-100' ?>">
                <span>🚗 المركبات</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num bg-white/20"><?= $trashCounts['vehicles'] ?></span>
            </a>
            <a href="archive.php?type=drivers<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1 <?= $type === 'drivers' ? 'bg-amber-600 text-white shadow-xs' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 hover:bg-amber-100' ?>">
                <span>👤 السائقون</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num bg-white/20"><?= $trashCounts['drivers'] ?></span>
            </a>
            <a href="archive.php?type=contracts<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1 <?= $type === 'contracts' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100' ?>">
                <span>📋 العقود</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num bg-white/20"><?= $trashCounts['contracts'] ?></span>
            </a>
            <a href="archive.php?type=insurance<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1 <?= $type === 'insurance' ? 'bg-cyan-600 text-white shadow-xs' : 'bg-cyan-50 dark:bg-cyan-950/40 text-cyan-800 dark:text-cyan-300 hover:bg-cyan-100' ?>">
                <span>🛡️ التأمين</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num bg-white/20"><?= $trashCounts['insurance'] ?></span>
            </a>
            <a href="archive.php?type=leaves<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1 <?= $type === 'leaves' ? 'bg-purple-600 text-white shadow-xs' : 'bg-purple-50 dark:bg-purple-950/40 text-purple-800 dark:text-purple-300 hover:bg-purple-100' ?>">
                <span>🔄 العطل</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num bg-white/20"><?= $trashCounts['leaves'] ?></span>
            </a>
            <a href="archive.php?type=debts<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1 <?= $type === 'debts' ? 'bg-amber-700 text-white shadow-xs' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-300 hover:bg-amber-100' ?>">
                <span>💰 الديون</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num bg-white/20"><?= $trashCounts['debts'] ?></span>
            </a>
            <a href="archive.php?type=slips<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1 <?= $type === 'slips' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-800 dark:text-indigo-300 hover:bg-indigo-100' ?>">
                <span>📄 الجداول والمحاضر</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num bg-white/20"><?= ($trashCounts['slips'] + $trashCounts['reports']) ?></span>
            </a>
        </div>
    </div>

    <!-- Unified Trash Items Table -->
    <div class="bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="py-3 px-4">نوع العنصر</th>
                        <th class="py-3 px-4">التعريف / الاسم</th>
                        <th class="py-3 px-4">التفاصيل والمعلومات التكميلية</th>
                        <th class="py-3 px-4 whitespace-nowrap">تاريخ النقل للأرشيف</th>
                        <th class="py-3 px-4 text-center whitespace-nowrap">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $item): ?>
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                <!-- Type Badge -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-bold <?= $item['badge_cls'] ?>">
                                        <span><?= $item['icon'] ?></span>
                                        <span><?= $item['type_name'] ?></span>
                                    </span>
                                </td>

                                <!-- Title -->
                                <td class="py-3.5 px-4 font-bold text-slate-900 dark:text-white">
                                    <div class="text-sm"><?= htmlspecialchars($item['title']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-normal">معرف السجل: #<?= $item['id'] ?></div>
                                </td>

                                <!-- Subtitle / Details -->
                                <td class="py-3.5 px-4 text-slate-600 dark:text-slate-300">
                                    <?= htmlspecialchars($item['subtitle']) ?>
                                </td>

                                <!-- Deleted At -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-rose-600 dark:text-rose-400"><?= arabic_time_ago($item['deleted_at']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-num font-mono"><?= htmlspecialchars($item['deleted_at']) ?></div>
                                </td>

                                <!-- Actions -->
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-2">
                                        <!-- Restore Button Form -->
                                        <form method="POST" action="" onsubmit="return confirm('هل تريد استرجاع (<?= htmlspecialchars(addslashes($item['title'])) ?>) وإعادته فوراً للسجل النشط؟')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="restore_item">
                                            <input type="hidden" name="table" value="<?= htmlspecialchars($item['table']) ?>">
                                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                            <input type="hidden" name="item_title" value="<?= htmlspecialchars($item['title']) ?>">
                                            <button type="submit" 
                                                    class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-2xs flex items-center gap-1 cursor-pointer"
                                                    title="استرجاع هذا العنصر">
                                                <span>استرجاع</span>
                                                <span>🔄</span>
                                            </button>
                                        </form>

                                        <!-- Permanent Force Delete Button Form -->
                                        <?php if (is_admin()): ?>
                                        <form method="POST" action="" onsubmit="return confirm('تحذير نهائي: هل أنت متأكد من الحذف النهائي لـ (<?= htmlspecialchars(addslashes($item['title'])) ?>) من قاعدة البيانات؟ لا يمكن التراجع عن هذا الإجراء!')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="force_delete_item">
                                            <input type="hidden" name="table" value="<?= htmlspecialchars($item['table']) ?>">
                                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                            <input type="hidden" name="item_title" value="<?= htmlspecialchars($item['title']) ?>">
                                            <button type="submit" 
                                                    class="px-2.5 py-1.5 rounded-lg bg-rose-50 dark:bg-rose-950/50 hover:bg-rose-600 hover:text-white text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-900 font-bold text-xs transition cursor-pointer"
                                                    title="حذف نهائي لا رجعة فيه">
                                                <span>حذف نهائي ⚠️</span>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="py-16 text-center">
                                <div class="max-w-sm mx-auto space-y-3">
                                    <div class="w-14 h-14 mx-auto rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-3xl font-bold">
                                        ✨
                                    </div>
                                    <h3 class="font-black text-slate-800 dark:text-white text-base">سلة المهملات نظيفة وفارغة</h3>
                                    <p class="text-xs text-slate-400 dark:text-slate-500">لا توجد أي عناصر محذوفة ضمن هذا التصنيف حالياً. عند حذف أي سيارة، سائق، عقد، أو وثيقة ستظهر هنا لتتمكن من استرجاعها في أي لحظة.</p>
                                    <div class="pt-2">
                                        <a href="vehicles.php" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-950 font-bold text-xs hover:opacity-90 transition">
                                            <span>الذهاب إلى سجل المركبات</span>
                                            <span>🚗</span>
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
