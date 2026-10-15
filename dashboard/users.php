<?php
// dashboard/users.php - User & Role Management
$pageTitle = 'إدارة المستخدمين والصلاحيات';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$pdo = getDBConnection();
$message = null;
$messageType = null;

// Handle User Actions (Add, Toggle Status, Reset Password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf_token();
    if (!is_admin()) {
        $message = 'عذراً، فقط مدير المنصة مخول بتعديل بيانات وحسابات المستخدمين.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $role = $_POST['role'] ?? 'driver';
            $status = $_POST['status'] ?? 'active';
            $wilaya = trim($_POST['wilaya'] ?? '16 - الجزائر العاصمة');
            $password = $_POST['password'] ?? 'password123';
            $licenseNumber = trim($_POST['license_number'] ?? '');
            $licenseExpiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;

            if (empty($fullName) || empty($email) || empty($phone)) {
                $message = 'يرجى ملء جميع الحقول الإلزامية (الاسم، البريد، والهاتف).';
                $messageType = 'error';
            } else {
                // Check if email exists
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmtCheck->execute([$email]);
                if ($stmtCheck->fetch()) {
                    $message = 'البريد الإلكتروني مستخدم بالفعل لمستخدم آخر.';
                    $messageType = 'error';
                } else {
                    $passHash = password_hash($password, PASSWORD_BCRYPT);
                    $stmtIns = $pdo->prepare("
                        INSERT INTO users (full_name, email, password_hash, phone, role, status, wilaya, license_number, license_expiry)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([$fullName, $email, $passHash, $phone, $role, $status, $wilaya, $licenseNumber, $licenseExpiry]);
                    $newId = $pdo->lastInsertId();

                    log_audit($pdo, 'user_created', 'users', (int)$newId, "إنشاء مستخدم جديد: $fullName ($role)");
                    $message = "تمت إضافة المستخدم $fullName بنجاح.";
                    $messageType = 'success';
                }
            }
        } elseif ($action === 'toggle_status') {
            $userId = intval($_POST['user_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? 'active';
            if ($userId > 0) {
                $stmtUp = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmtUp->execute([$newStatus, $userId]);
                log_audit($pdo, 'user_status_changed', 'users', $userId, "تغيير حالة المستخدم رقم $userId إلى $newStatus");
                $message = 'تم تحديث حالة الحساب بنجاح.';
                $messageType = 'success';
            }
        } elseif ($action === 'reset_password') {
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId > 0) {
                $defaultPass = password_hash('password123', PASSWORD_BCRYPT);
                $stmtUp = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmtUp->execute([$defaultPass, $userId]);
                log_audit($pdo, 'password_reset', 'users', $userId, "إعادة تعيين كلمة مرور المستخدم رقم $userId إلى الافتراضية");
                $message = 'تمت إعادة تعيين كلمة المرور إلى (password123) بنجاح.';
                $messageType = 'success';
            }
        }
    }
}

// Filters
$roleFilter = $_GET['role'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($roleFilter !== 'all') {
    $sql .= " AND role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}

if (!empty($searchQuery)) {
    $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR license_number LIKE ?)";
    $like = "%$searchQuery%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY created_at DESC";
$users = [];
if ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
}

$roleCounts = [
    'all' => $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0,
    'super_admin' => $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn() : 0,
    'admin' => $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() : 0,
    'owner' => $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner'")->fetchColumn() : 0,
    'driver' => $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver'")->fetchColumn() : 0,
];
?>

<div class="space-y-6">

    <!-- Top Action Banner -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-black text-slate-900">إدارة المستخدمين وفريق العمل</h2>
            <p class="text-xs text-slate-500 mt-1">إدارة حسابات المشرفين، ملاك الأساطيل، والسائقين المعتمدين مع التحكم الكامل بالصلاحيات وحالات الحسابات.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('newUserModal').classList.remove('hidden')" 
                    class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-2">
                <span>➕</span>
                <span>إضافة مستخدم جديد</span>
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-xl text-xs font-bold flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
            <span><?= $messageType === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Role Filter Pills -->
    <div class="flex flex-wrap gap-2 text-xs font-bold">
        <a href="?role=all&status=<?= urlencode($statusFilter) ?>" 
           class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $roleFilter === 'all' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>الكل</span>
            <span class="px-1.5 py-0.5 rounded-md bg-slate-800 text-white font-num text-[10px]"><?= $roleCounts['all'] ?></span>
        </a>
        <a href="?role=super_admin&status=<?= urlencode($statusFilter) ?>" 
           class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $roleFilter === 'super_admin' ? 'bg-amber-500 text-slate-950 border-amber-500' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>مدراء المنصة</span>
            <span class="px-1.5 py-0.5 rounded-md bg-amber-100 text-amber-800 font-num text-[10px]"><?= $roleCounts['super_admin'] ?></span>
        </a>
        <a href="?role=owner&status=<?= urlencode($statusFilter) ?>" 
           class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $roleFilter === 'owner' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>ملاك الأسطول</span>
            <span class="px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-700 font-num text-[10px]"><?= $roleCounts['owner'] ?></span>
        </a>
        <a href="?role=driver&status=<?= urlencode($statusFilter) ?>" 
           class="px-3.5 py-2 rounded-xl transition border flex items-center gap-1.5 <?= $roleFilter === 'driver' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <span>السائقون</span>
            <span class="px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-700 font-num text-[10px]"><?= $roleCounts['driver'] ?></span>
        </a>
    </div>

    <!-- Search & Secondary Filter Bar -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col sm:flex-row gap-3">
        <form method="GET" action="" class="flex-1 flex gap-2">
            <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
            <div class="relative flex-1">
                <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" 
                       placeholder="بحث بالاسم، البريد الإلكتروني، رقم الهاتف أو رخصة السياقة..."
                       class="w-full pl-4 pr-9 py-2 rounded-xl border border-slate-200 text-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                <span class="absolute right-3 top-2.5 text-slate-400 text-xs">🔍</span>
            </div>
            <select name="status" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700 focus:border-amber-500">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>كل الحالات</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>نشط (Active)</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>معلق (Pending)</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>موقوف (Suspended)</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800 transition">
                تطبيق
            </button>
            <?php if (!empty($searchQuery) || $statusFilter !== 'all' || $roleFilter !== 'all'): ?>
                <a href="users.php" class="px-3 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-200 transition">إعادة ضبط</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-extrabold uppercase tracking-wider">
                        <th class="p-4">المستخدم</th>
                        <th class="p-4">الاتصال</th>
                        <th class="p-4">الدور</th>
                        <th class="p-4">الولاية</th>
                        <th class="p-4">رخصة السياقة</th>
                        <th class="p-4">الحالة</th>
                        <th class="p-4 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-400">
                                لا يوجد مستخدمين مطابقين لمعايير البحث الحالية.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): 
                            $roleBadge = [
                                'super_admin' => 'bg-amber-100 text-amber-900 border-amber-200',
                                'admin' => 'bg-blue-100 text-blue-900 border-blue-200',
                                'owner' => 'bg-purple-100 text-purple-900 border-purple-200',
                                'driver' => 'bg-slate-100 text-slate-800 border-slate-200',
                            ][$u['role']] ?? 'bg-slate-100 text-slate-700 border-slate-200';

                            $roleLabel = [
                                'super_admin' => 'مدير المنصة',
                                'admin' => 'مشرف إدارة',
                                'owner' => 'مالك أسطول',
                                'driver' => 'سائق أجرة',
                            ][$u['role']] ?? $u['role'];

                            $statusBadge = [
                                'active' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
                                'suspended' => 'bg-rose-50 text-rose-700 border-rose-200',
                            ][$u['status']] ?? 'bg-slate-50 text-slate-700 border-slate-200';

                            $statusLabel = [
                                'active' => 'نشط',
                                'pending' => 'قيد المراجعة',
                                'suspended' => 'موقوف',
                            ][$u['status']] ?? $u['status'];
                        ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="p-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-800 font-bold flex items-center justify-center text-xs shrink-0">
                                            <?= mb_substr($u['full_name'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($u['full_name']) ?></div>
                                            <div class="text-[10px] text-slate-400 font-mono" dir="ltr"><?= htmlspecialchars($u['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <span class="font-num font-bold text-slate-700" dir="ltr"><?= htmlspecialchars($u['phone']) ?></span>
                                </td>
                                <td class="p-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $roleBadge ?>">
                                        <?= $roleLabel ?>
                                    </span>
                                </td>
                                <td class="p-4 text-slate-600">
                                    <?= htmlspecialchars($u['wilaya'] ?? '-') ?>
                                </td>
                                <td class="p-4">
                                    <?php if (!empty($u['license_number'])): ?>
                                        <div class="font-mono font-bold text-slate-800"><?= htmlspecialchars($u['license_number']) ?></div>
                                        <div class="text-[10px] text-slate-400">تنتهي: <?= htmlspecialchars($u['license_expiry'] ?? '-') ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $statusBadge ?>">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <!-- Toggle Status Form -->
                                        <form method="POST" action="" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <?php if ($u['status'] === 'active'): ?>
                                                <input type="hidden" name="new_status" value="suspended">
                                                <button type="submit" title="تجميد الحساب" class="p-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 transition text-xs">
                                                    ⛔
                                                </button>
                                            <?php else: ?>
                                                <input type="hidden" name="new_status" value="active">
                                                <button type="submit" title="تفعيل الحساب" class="p-1.5 rounded-lg border border-emerald-200 text-emerald-600 hover:bg-emerald-50 transition text-xs">
                                                    ✅
                                                </button>
                                            <?php endif; ?>
                                        </form>

                                        <!-- Reset Password Form -->
                                        <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من إعادة تعيين كلمة المرور إلى (password123)؟')" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" title="إعادة تعيين كلمة المرور" class="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100 transition text-xs">
                                                🔑
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: New User -->
<div id="newUserModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900">إضافة مستخدم جديد إلى المنصة</h3>
            <button onclick="document.getElementById('newUserModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-3 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div>
                <label class="block font-bold text-slate-700 mb-1">الاسم الكامل *</label>
                <input type="text" name="full_name" required placeholder="مثال: عبد الرؤوف منصوري" 
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">البريد الإلكتروني *</label>
                    <input type="email" name="email" required placeholder="user@taxinaguilou.dz" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم الهاتف *</label>
                    <input type="text" name="phone" required placeholder="0550112233" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الدور والصلاحية *</label>
                    <select name="role" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-bold">
                        <option value="driver">سائق أجرة (Driver)</option>
                        <option value="owner">مالك أسطول (Owner)</option>
                        <option value="admin">مشرف إدارة (Admin)</option>
                        <option value="super_admin">مدير المنصة (Super Admin)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الحالة الأولية</label>
                    <select name="status" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                        <option value="active">نشط ومفعل (Active)</option>
                        <option value="pending">قيد التدقيق (Pending)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">الولاية المعتمدة</label>
                <input type="text" name="wilaya" value="16 - الجزائر العاصمة" 
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم رخصة السياقة (اختياري)</label>
                    <input type="text" name="license_number" placeholder="مثال: B-9481726" 
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تاريخ انتهاء الرخصة</label>
                    <input type="date" name="license_expiry" 
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">كلمة المرور الأولية (افتراضياً: password123)</label>
                <input type="password" name="password" value="password123" 
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newUserModal').classList.add('hidden')" 
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition">
                    إضافة المستخدم
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
