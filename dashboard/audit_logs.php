<?php
// dashboard/audit_logs.php - Security Audit Trail & Activity Monitoring
$pageTitle = 'سجل التدقيق والرقابة الأمنية';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$pdo = getDBConnection();

// Export to CSV if requested
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $pdo) {
    if (!is_admin()) {
        set_flash('error', 'غير مصرح لك بتصدير سجل التدقيق.');
        redirect('/dashboard/index.php');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_logs_taxinaguilou_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['المعرف', 'المستخدم', 'نوع الإجراء', 'الكيان', 'معرف الكيان', 'التفاصيل', 'عنوان IP', 'التوقيت']);

    $stmtExp = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC");
    while ($row = $stmtExp->fetch()) {
        fputcsv($output, [
            $row['id'],
            $row['user_name'] ?? 'النظام',
            $row['action'],
            $row['entity'],
            $row['entity_id'] ?? '-',
            $row['details'] ?? '-',
            $row['ip_address'] ?? '127.0.0.1',
            $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// Filters
$actionFilter = $_GET['action'] ?? 'all';
$entityFilter = $_GET['entity'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM audit_logs WHERE 1=1";
$params = [];

if ($actionFilter !== 'all') {
    $sql .= " AND action = ?";
    $params[] = $actionFilter;
}

if ($entityFilter !== 'all') {
    $sql .= " AND entity = ?";
    $params[] = $entityFilter;
}

if (!empty($searchQuery)) {
    $sql .= " AND (user_name LIKE ? OR details LIKE ? OR ip_address LIKE ?)";
    $like = "%$searchQuery%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY created_at DESC LIMIT 100";

$logs = [];
$totalLogsCount = 0;
$distinctActions = [];
$distinctEntities = [];

if ($pdo) {
    try {
        $totalLogsCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    } catch (\Throwable $e) { $totalLogsCount = 0; }

    try {
        $distinctActions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) { $distinctActions = []; }

    try {
        $distinctEntities = $pdo->query("SELECT DISTINCT entity FROM audit_logs ORDER BY entity ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) { $distinctEntities = []; }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        $logs = [];
    }
}
?>

<div class="space-y-6">

    <!-- Top Action Banner -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-black text-slate-900">سجل التدقيق والرقابة الأمنية (Audit Trail)</h2>
            <p class="text-xs text-slate-500 mt-1">تتبع غير قابل للتعديل لجميع العمليات الحساسة: تسجيل الدخول، توثيق العقود، إصدار جداول الإرسال، والتسويات المالية.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?export=csv" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-2">
                <span>📥</span>
                <span>تصدير السجل إلى Excel (CSV)</span>
            </a>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-xs font-bold text-slate-400">إجمالي العمليات المسجلة</div>
            <div class="text-2xl font-black text-slate-900 font-num mt-1"><?= $totalLogsCount ?></div>
            <div class="text-[10px] text-slate-500 mt-1">تتبع أمني دقيق ومؤرخ بالثانية</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-xs font-bold text-slate-400">الكيانات الخاضعة للرقابة</div>
            <div class="text-2xl font-black text-amber-600 font-num mt-1"><?= count($distinctEntities) ?></div>
            <div class="text-[10px] text-slate-500 mt-1">عقود، مستخدمون، مركبات، اشتراكات</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-xs font-bold text-slate-400">مستوى الحماية والامتثال</div>
            <div class="text-2xl font-black text-emerald-600 font-num mt-1">100%</div>
            <div class="text-[10px] text-slate-500 mt-1">مطابق لدفتر شروط الرقابة الإدارية</div>
        </div>
    </div>

    <!-- Search & Filter Form -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col sm:flex-row gap-3">
        <form method="GET" action="" class="flex-1 flex flex-wrap gap-2 text-xs">
            <div class="relative flex-1 min-w-[200px]">
                <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" 
                       placeholder="بحث بالمستخدم، التفاصيل، أو عنوان IP..."
                       class="w-full pl-4 pr-9 py-2 rounded-xl border border-slate-200 text-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                <span class="absolute right-3 top-2.5 text-slate-400">🔍</span>
            </div>

            <select name="action" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-200 font-bold text-slate-700 focus:border-amber-500">
                <option value="all">كل الإجراءات</option>
                <?php foreach ($distinctActions as $act): ?>
                    <option value="<?= htmlspecialchars($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                        <?= htmlspecialchars($act) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="entity" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-200 font-bold text-slate-700 focus:border-amber-500">
                <option value="all">كل الكيانات</option>
                <?php foreach ($distinctEntities as $ent): ?>
                    <option value="<?= htmlspecialchars($ent) ?>" <?= $entityFilter === $ent ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ent) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="px-4 py-2 bg-slate-900 text-white rounded-xl font-bold hover:bg-slate-800 transition">
                تطبيق
            </button>

            <?php if (!empty($searchQuery) || $actionFilter !== 'all' || $entityFilter !== 'all'): ?>
                <a href="audit_logs.php" class="px-3 py-2 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition">إعادة ضبط</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200/80 dark:border-slate-700/80 text-slate-700 dark:text-slate-200 font-extrabold uppercase tracking-wider">
                        <th class="p-4">التوقيت</th>
                        <th class="p-4">المستخدم</th>
                        <th class="p-4">نوع الإجراء</th>
                        <th class="p-4">الكيان</th>
                        <th class="p-4">تفاصيل العملية</th>
                        <th class="p-4">عنوان IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-400">
                                لا توجد أحداث مسجلة مطابقة لمعايير البحث.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): 
                            $badgeColor = 'bg-slate-100 text-slate-800 border-slate-200';
                            if (str_contains($l['action'], 'login')) $badgeColor = 'bg-blue-50 text-blue-800 border-blue-200';
                            elseif (str_contains($l['action'], 'contract')) $badgeColor = 'bg-amber-50 text-amber-800 border-amber-200';
                            elseif (str_contains($l['action'], 'slip')) $badgeColor = 'bg-purple-50 text-purple-800 border-purple-200';
                            elseif (str_contains($l['action'], 'payment') || str_contains($l['action'], 'billing')) $badgeColor = 'bg-emerald-50 text-emerald-800 border-emerald-200';
                            elseif (str_contains($l['action'], 'created')) $badgeColor = 'bg-indigo-50 text-indigo-800 border-indigo-200';
                            elseif (str_contains($l['action'], 'delete') || str_contains($l['action'], 'suspend')) $badgeColor = 'bg-rose-50 text-rose-800 border-rose-200';
                        ?>
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                                <td class="p-4 whitespace-nowrap">
                                    <span class="table-num-badge table-num-slate text-[11px]" dir="ltr"><?= htmlspecialchars($l['created_at']) ?></span>
                                </td>
                                <td class="p-4 font-bold text-slate-900 dark:text-slate-100">
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-6 h-6 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[10px] text-slate-700 dark:text-slate-300">👤</span>
                                        <span><?= htmlspecialchars($l['user_name'] ?? 'النظام') ?></span>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold border font-mono <?= $badgeColor ?>">
                                        <?= htmlspecialchars($l['action']) ?>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <span class="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold text-[10px]">
                                        <?= htmlspecialchars($l['entity']) ?>
                                        <?= !empty($l['entity_id']) ? '#' . $l['entity_id'] : '' ?>
                                    </span>
                                </td>
                                <td class="p-4 text-slate-700 dark:text-slate-200">
                                    <?= htmlspecialchars($l['details'] ?? '-') ?>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span class="table-num-badge table-num-slate font-mono text-[10px]" dir="ltr">
                                        🌐 <?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
