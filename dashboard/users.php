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

            $subscriptionType = in_array($_POST['subscription_type'] ?? '', ['unlimited', 'free', 'paid']) ? $_POST['subscription_type'] : 'unlimited';
            $subscriptionExpiresAt = (!empty($_POST['subscription_expires_at']) && $subscriptionType !== 'unlimited') ? $_POST['subscription_expires_at'] : null;

            if (in_array($subscriptionType, ['free', 'paid']) && !empty($subscriptionExpiresAt) && $subscriptionExpiresAt < date('Y-m-d')) {
                $status = 'suspended';
            }

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
                    $qrToken = bin2hex(random_bytes(16));
                    $stmtIns = $pdo->prepare("
                        INSERT INTO users (full_name, email, password_hash, qr_token, phone, role, status, wilaya, license_number, license_expiry, subscription_type, subscription_expires_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([$fullName, $email, $passHash, $qrToken, $phone, $role, $status, $wilaya, $licenseNumber, $licenseExpiry, $subscriptionType, $subscriptionExpiresAt]);
                    $newId = $pdo->lastInsertId();

                    log_audit($pdo, 'user_created', 'users', (int)$newId, "إنشاء مستخدم جديد: $fullName ($role) | نوع الاشتراك: $subscriptionType");
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
        } elseif ($action === 'regenerate_qr') {
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId > 0) {
                $newToken = bin2hex(random_bytes(16));
                $stmtUp = $pdo->prepare("UPDATE users SET qr_token = ? WHERE id = ?");
                $stmtUp->execute([$newToken, $userId]);
                log_audit($pdo, 'qr_token_regenerated', 'users', $userId, "إعادة توليد رمز QR جديد للدخول إلى الحساب للمستخدم رقم $userId");
                $message = 'تم تجديد وإصدار رمز QR جديد للدخول إلى الحساب بنجاح.';
                $messageType = 'success';
            }
        } elseif ($action === 'edit_user') {
            $userId = intval($_POST['user_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $role = $_POST['role'] ?? 'driver';
            $status = in_array($_POST['status'] ?? '', ['active', 'suspended']) ? $_POST['status'] : 'active';
            $password = trim($_POST['password'] ?? '');

            $subscriptionType = in_array($_POST['subscription_type'] ?? '', ['unlimited', 'free', 'paid']) ? $_POST['subscription_type'] : 'unlimited';
            $subscriptionExpiresAt = (!empty($_POST['subscription_expires_at']) && $subscriptionType !== 'unlimited') ? $_POST['subscription_expires_at'] : null;

            // Auto-suspend / freeze account if subscription is expired
            if (in_array($subscriptionType, ['free', 'paid']) && !empty($subscriptionExpiresAt) && $subscriptionExpiresAt < date('Y-m-d')) {
                $status = 'suspended';
            }

            if ($userId > 0 && !empty($fullName)) {
                if (!empty($password)) {
                    $passHash = password_hash($password, PASSWORD_BCRYPT);
                    $stmtUp = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, status = ?, subscription_type = ?, subscription_expires_at = ?, password_hash = ? WHERE id = ?");
                    $stmtUp->execute([$fullName, $email, $phone, $role, $status, $subscriptionType, $subscriptionExpiresAt, $passHash, $userId]);
                } else {
                    $stmtUp = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, status = ?, subscription_type = ?, subscription_expires_at = ? WHERE id = ?");
                    $stmtUp->execute([$fullName, $email, $phone, $role, $status, $subscriptionType, $subscriptionExpiresAt, $userId]);
                }
                log_audit($pdo, 'user_updated', 'users', $userId, "تعديل بيانات المستخدم: $fullName ($role) | نوع الاشتراك: $subscriptionType حتى " . ($subscriptionExpiresAt ?? 'دائم'));
                $message = "تم تعديل بيانات المستخدم $fullName بنجاح.";
                $messageType = 'success';
            } else {
                $message = 'يرجى إدخال اسم المستخدم.';
                $messageType = 'error';
            }
        } elseif ($action === 'delete_user') {
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId === intval($_SESSION['user_id'])) {
                $message = 'عذراً، لا يمكنك حذف حسابك الشخصي الذي تستخدمه حالياً.';
                $messageType = 'error';
            } elseif ($userId > 0) {
                $targetRole = $pdo->query("SELECT role FROM users WHERE id = $userId")->fetchColumn();
                if ($targetRole === 'super_admin' && !is_super_admin()) {
                    $message = 'عذراً، فقط المدير العام مخول بحذف حسابات المشرفين العامين.';
                    $messageType = 'error';
                } else {
                    try {
                        soft_delete_entity($pdo, 'users', $userId, "حذف حساب المستخدم ونقله للأرشيف");
                        $message = 'تم نقل حساب المستخدم إلى سلة المهملات والأرشيف بنجاح. يمكنك استرجاعه في أي وقت من قسم سلة المهملات.';
                        $messageType = 'success';
                    } catch (\Throwable $e) {
                        $message = 'حدث خطأ أثناء محاولة الحذف: ' . htmlspecialchars($e->getMessage());
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

// Auto-suspend / freeze accounts whose free or paid subscription period has expired
if ($pdo) {
    try {
        $autoFrozen = $pdo->exec("
            UPDATE users 
            SET status = 'suspended' 
            WHERE status = 'active' 
              AND subscription_type IN ('free', 'paid') 
              AND subscription_expires_at IS NOT NULL 
              AND subscription_expires_at < CURDATE()
        ");
        if ($autoFrozen && $autoFrozen > 0) {
            log_audit($pdo, 'auto_freeze_expired', 'users', null, "تجميد تلقائي لعدد $autoFrozen حساب مستخدم بعد انتهاء مدة الصلاحية المحددة (مجانية أو مدفوعة)");
        }
    } catch (\Throwable $e) {}
}

// Filters
$roleFilter = $_GET['role'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$subFilter = $_GET['subscription_type'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM users WHERE deleted_at IS NULL";
$params = [];

if ($roleFilter !== 'all') {
    $sql .= " AND role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}

if ($subFilter === 'expired') {
    $sql .= " AND subscription_type IN ('free', 'paid') AND subscription_expires_at IS NOT NULL AND subscription_expires_at < CURDATE()";
} elseif (in_array($subFilter, ['unlimited', 'free', 'paid'])) {
    $sql .= " AND subscription_type = ?";
    $params[] = $subFilter;
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
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>موقوف / مجمد (Suspended)</option>
            </select>
            <select name="subscription_type" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700 focus:border-amber-500">
                <option value="all" <?= $subFilter === 'all' ? 'selected' : '' ?>>كل الاشتراكات</option>
                <option value="unlimited" <?= $subFilter === 'unlimited' ? 'selected' : '' ?>>♾️ دائم / غير محدود</option>
                <option value="free" <?= $subFilter === 'free' ? 'selected' : '' ?>>🎁 فترة مجانية</option>
                <option value="paid" <?= $subFilter === 'paid' ? 'selected' : '' ?>>💎 فترة مدفوعة</option>
                <option value="expired" <?= $subFilter === 'expired' ? 'selected' : '' ?>>⚠️ منتهية الصلاحية (مجمدة)</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800 transition">
                تطبيق
            </button>
            <?php if (!empty($searchQuery) || $statusFilter !== 'all' || $roleFilter !== 'all' || $subFilter !== 'all'): ?>
                <a href="users.php" class="px-3 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-200 transition">إعادة ضبط</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200/80 dark:border-slate-700/80 text-slate-700 dark:text-slate-200 font-extrabold uppercase tracking-wider">
                        <th class="p-4">المستخدم</th>
                        <th class="p-4">الاتصال</th>
                        <th class="p-4">الدور</th>
                        <th class="p-4">الولاية</th>
                        <th class="p-4">رخصة السياقة</th>
                        <th class="p-4">نوع الصلاحية والاشتراك</th>
                        <th class="p-4">الحالة</th>
                        <th class="p-4 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-400">
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
                                'suspended' => 'موقوف / مجمد',
                            ][$u['status']] ?? $u['status'];

                            $subType = $u['subscription_type'] ?? 'unlimited';
                            $subExpiry = $u['subscription_expires_at'] ?? null;
                            $today = date('Y-m-d');
                            $isSubExpired = false;
                            $daysRemaining = null;
                            if (!empty($subExpiry)) {
                                $diff = (strtotime($subExpiry) - strtotime($today)) / 86400;
                                $daysRemaining = (int)round($diff);
                                if ($daysRemaining < 0) {
                                    $isSubExpired = true;
                                }
                            }
                        ?>
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                                <td class="p-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-bold flex items-center justify-center text-xs shrink-0">
                                            <?= mb_substr($u['full_name'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($u['full_name']) ?></div>
                                            <div class="text-[10px] text-slate-400 font-mono" dir="ltr"><?= htmlspecialchars($u['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <span class="table-num-badge table-num-slate text-[11px]" dir="ltr">📞 <?= htmlspecialchars($u['phone']) ?></span>
                                </td>
                                <td class="p-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $roleBadge ?>">
                                        <?= $roleLabel ?>
                                    </span>
                                </td>
                                <td class="p-4 text-slate-600 dark:text-slate-300">
                                    <?= htmlspecialchars($u['wilaya'] ?? '-') ?>
                                </td>
                                <td class="p-4">
                                    <?php if (!empty($u['license_number'])): ?>
                                        <span class="table-num-badge table-num-sky font-bold text-[11px]" dir="ltr">📄 <?= htmlspecialchars($u['license_number']) ?></span>
                                        <?php if (!empty($u['license_expiry'])): ?>
                                            <div class="mt-1">
                                                <span class="table-num-badge table-num-slate text-[10px]" dir="ltr">تنتهي: <?= htmlspecialchars($u['license_expiry']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4">
                                    <?php if ($subType === 'unlimited'): ?>
                                        <span class="table-num-badge table-num-slate text-[10px]">
                                            <span>♾️</span>
                                            <span>غير محدود (دائم)</span>
                                        </span>
                                    <?php elseif ($subType === 'free'): ?>
                                        <div class="space-y-1">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $isSubExpired ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
                                                <span>🎁 مجاني</span>
                                                <?php if ($isSubExpired): ?>
                                                    <span class="font-extrabold">(منتهي - مجمد)</span>
                                                <?php elseif ($daysRemaining !== null): ?>
                                                    <span class="font-num">(باقٍ <?= $daysRemaining ?> ي)</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if (!empty($subExpiry)): ?>
                                                <div>
                                                    <span class="table-num-badge <?= $isSubExpired ? 'table-num-rose' : 'table-num-emerald' ?> text-[10px]" dir="ltr">
                                                        <span class="<?= $isSubExpired ? 'table-num-dot-rose' : 'table-num-dot-emerald' ?>"></span>
                                                        ينتهي: <?= htmlspecialchars($subExpiry) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($subType === 'paid'): ?>
                                        <div class="space-y-1">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $isSubExpired ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-blue-50 text-blue-700 border border-blue-200' ?>">
                                                <span>💎 مدفوع</span>
                                                <?php if ($isSubExpired): ?>
                                                    <span class="font-extrabold">(منتهي - مجمد)</span>
                                                <?php elseif ($daysRemaining !== null): ?>
                                                    <span class="font-num">(باقٍ <?= $daysRemaining ?> ي)</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if (!empty($subExpiry)): ?>
                                                <div>
                                                    <span class="table-num-badge <?= $isSubExpired ? 'table-num-rose' : 'table-num-sky' ?> text-[10px]" dir="ltr">
                                                        <span class="<?= $isSubExpired ? 'table-num-dot-rose' : 'table-num-dot-sky' ?>"></span>
                                                        ينتهي: <?= htmlspecialchars($subExpiry) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $statusBadge ?>">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center whitespace-nowrap">
                                    <div class="relative inline-block text-right">
                                        <button type="button"
                                                onclick="toggleTableDropdown(event, this)"
                                                class="table-dropdown-btn w-9 h-9 rounded-xl bg-slate-100 hover:bg-amber-500/15 hover:text-amber-700 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 shadow-2xs cursor-pointer"
                                                title="خيارات وإجراءات الحساب">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                            </svg>
                                        </button>

                                        <div class="table-dropdown-menu hidden w-60 min-w-[240px] rounded-2xl bg-white/95 dark:bg-[#161d27]/95 backdrop-blur-md border border-slate-200 dark:border-slate-800 shadow-2xl p-1.5 space-y-0.5 text-xs text-right select-none">
                                            <!-- Edit User Button -->
                                            <button type="button"
                                                    onclick='openEditUserModal(<?= json_encode([
                                                        "id" => (int)$u["id"],
                                                        "full_name" => $u["full_name"],
                                                        "email" => $u["email"] ?? "",
                                                        "phone" => $u["phone"] ?? "",
                                                        "role" => $u["role"],
                                                        "status" => $u["status"],
                                                        "subscription_type" => $u["subscription_type"] ?? "unlimited",
                                                        "subscription_expires_at" => $u["subscription_expires_at"] ?? ""
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل بيانات المستخدم</span>
                                            </button>

                                            <!-- Toggle Status Form -->
                                            <form method="POST" action="" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <?php if ($u['status'] === 'active'): ?>
                                                    <input type="hidden" name="new_status" value="suspended">
                                                    <button type="submit" class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/40 font-bold transition text-right cursor-pointer">
                                                        <span>⛔</span>
                                                        <span>تجميد حساب المستخدم</span>
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="new_status" value="active">
                                                    <button type="submit" class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 font-bold transition text-right cursor-pointer">
                                                        <span>✅</span>
                                                        <span>تفعيل وتنشيط الحساب</span>
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- QR Code Direct Login Button -->
                                            <button type="button"
                                                    onclick='openUserQrModal(<?= json_encode([
                                                        "id" => (int)$u["id"],
                                                        "full_name" => $u["full_name"],
                                                        "role" => $u["role"],
                                                        "role_label" => $roleLabel,
                                                        "email" => $u["email"] ?? "",
                                                        "phone" => $u["phone"] ?? "",
                                                        "qr_token" => $u["qr_token"] ?? "",
                                                        "login_url" => absolute_url("/auth/qr_login.php?token=" . ($u["qr_token"] ?? ""))
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-indigo-700 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-indigo-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.008v.008H6.75V6.75zM6.75 16.5h.008v.008H6.75V16.5zM16.5 6.75h.008v.008H16.5V6.75zM13.5 13.5h3.75m0 3.75h.008v.008h-.008v-.008zm-3.75 3.75h7.5" />
                                                </svg>
                                                <span>رمز QR للدخول إلى الحساب</span>
                                            </button>

                                            <!-- Delete User Form -->
                                            <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من رغبتك في حذف هذا المستخدم نهائياً؟')" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-bold transition text-right cursor-pointer">
                                                    <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    <span>حذف المستخدم</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
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

            <!-- New User: Subscription / Validity Configuration -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 space-y-2.5">
                <div class="flex items-center justify-between">
                    <label class="block font-bold text-slate-800 text-xs flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>نوع الصلاحية ومدة الاشتراك</span>
                    </label>
                    <span class="text-[10px] text-amber-700 font-bold bg-amber-100/70 px-2 py-0.5 rounded-md border border-amber-200">تجميد تلقائي عند الانتهاء</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block font-medium text-slate-600 mb-1 text-[11px]">نوع الاشتراك *</label>
                        <select name="subscription_type" id="newSubscriptionType" onchange="onNewSubscriptionTypeChanged()" 
                                class="w-full px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 font-bold focus:border-amber-500 text-xs">
                            <option value="unlimited" selected>♾️ غير محدود / دائم (Permanent)</option>
                            <option value="free">🎁 مجاناً لفترة محددة (Free Trial)</option>
                            <option value="paid">💎 مدفوع لفترة محددة (Paid)</option>
                        </select>
                    </div>

                    <div id="newSubscriptionExpiryGroup" class="opacity-40 pointer-events-none transition">
                        <label class="block font-medium text-slate-600 mb-1 text-[11px]">تاريخ انتهاء الصلاحية</label>
                        <input type="date" name="subscription_expires_at" id="newSubscriptionExpiresAt"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 font-num focus:border-amber-500 text-xs">
                    </div>
                </div>

                <!-- Presets buttons -->
                <div id="newSubscriptionPresetsGroup" class="hidden space-y-1 pt-1 border-t border-slate-200/60">
                    <div class="text-[10px] text-slate-500 font-bold">تحديد المدة بسرعة من اليوم:</div>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" onclick="setNewSubPreset(7)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+7 أيام</button>
                        <button type="button" onclick="setNewSubPreset(15)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+15 يوم</button>
                        <button type="button" onclick="setNewSubPreset(30)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ شهر (30 يوم)</button>
                        <button type="button" onclick="setNewSubPreset(90)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ 3 أشهر</button>
                        <button type="button" onclick="setNewSubPreset(180)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ 6 أشهر</button>
                        <button type="button" onclick="setNewSubPreset(365)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ سنة كاملة</button>
                    </div>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">كلمة المرور الأولية (افتراضياً: password123)</label>
                <div class="relative">
                    <input type="password" name="password" id="newUserPassword" value="password123" dir="ltr"
                           class="w-full pl-11 pr-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 font-mono text-xs">
                    <button type="button" onclick="togglePasswordVisibility('newUserPassword', this)" 
                            class="absolute inset-y-0 left-0 pl-3.5 pr-2 flex items-center text-slate-400 hover:text-slate-700 cursor-pointer focus:outline-none transition" 
                            title="إظهار / إخفاء كلمة المرور">
                        <svg class="eye-open w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg class="eye-closed w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
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

<!-- Modal: Edit User -->
<div id="editUserModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900">تعديل بيانات المستخدم والصلاحيات</h3>
            <button onclick="closeEditUserModal()" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-3 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">

            <div>
                <label class="block font-bold text-slate-700 mb-1">الاسم واللقب *</label>
                <input type="text" name="full_name" id="editUserFullName" required 
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                    <input type="email" name="email" id="editUserEmail" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رقم الهاتف</label>
                    <input type="text" name="phone" id="editUserPhone" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-num">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الدور والصلاحية *</label>
                    <select name="role" id="editUserRole" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="driver">سائق أجرة (Driver)</option>
                        <option value="owner">مالك أسطول (Owner)</option>
                        <option value="admin">مشرف إدارة (Admin)</option>
                        <option value="super_admin">مدير المنصة (Super Admin)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">الحالة</label>
                    <select name="status" id="editUserStatus" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="active">نشط ومفعل (Active)</option>
                        <option value="suspended">موقوف / مجمد (Suspended)</option>
                    </select>
                </div>
            </div>

            <!-- Edit User: Subscription / Validity Configuration -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 space-y-2.5">
                <div class="flex items-center justify-between">
                    <label class="block font-bold text-slate-800 text-xs flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>نوع الصلاحية ومدة الاشتراك</span>
                    </label>
                    <span class="text-[10px] text-amber-700 font-bold bg-amber-100/70 px-2 py-0.5 rounded-md border border-amber-200">تجميد تلقائي عند الانتهاء</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block font-medium text-slate-600 mb-1 text-[11px]">نوع الاشتراك *</label>
                        <select name="subscription_type" id="editSubscriptionType" onchange="onEditSubscriptionTypeChanged()" 
                                class="w-full px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 font-bold focus:border-amber-500 text-xs">
                            <option value="unlimited">♾️ غير محدود / دائم (Permanent)</option>
                            <option value="free">🎁 مجاناً لفترة محددة (Free Trial)</option>
                            <option value="paid">💎 مدفوع لفترة محددة (Paid)</option>
                        </select>
                    </div>

                    <div id="editSubscriptionExpiryGroup" class="transition">
                        <label class="block font-medium text-slate-600 mb-1 text-[11px]">تاريخ انتهاء الصلاحية</label>
                        <input type="date" name="subscription_expires_at" id="editSubscriptionExpiresAt"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 font-num focus:border-amber-500 text-xs">
                    </div>
                </div>

                <!-- Presets buttons -->
                <div id="editSubscriptionPresetsGroup" class="space-y-1 pt-1 border-t border-slate-200/60">
                    <div class="flex items-center justify-between text-[10px] text-slate-500">
                        <span class="font-bold">تمديد سريع من اليوم:</span>
                        <span id="editSubscriptionDaysBadge" class="font-num font-bold"></span>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" onclick="setEditSubPreset(7)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+7 أيام</button>
                        <button type="button" onclick="setEditSubPreset(15)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+15 يوم</button>
                        <button type="button" onclick="setEditSubPreset(30)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ شهر (30 يوم)</button>
                        <button type="button" onclick="setEditSubPreset(90)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ 3 أشهر</button>
                        <button type="button" onclick="setEditSubPreset(180)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ 6 أشهر</button>
                        <button type="button" onclick="setEditSubPreset(365)" class="px-2 py-1 rounded-lg bg-white border border-slate-200 hover:border-amber-500 hover:text-amber-600 text-[10px] font-bold transition cursor-pointer">+ سنة كاملة</button>
                    </div>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">كلمة مرور جديدة (اتركه فارغاً إذا كنت لا ترغب بتغييرها)</label>
                <div class="relative">
                    <input type="password" name="password" id="editUserPassword" placeholder="اتركه فارغاً للحفاظ على القديمة" dir="ltr"
                           class="w-full pl-11 pr-3.5 py-2.5 rounded-xl border border-slate-200 focus:border-amber-500 font-mono text-xs">
                    <button type="button" onclick="togglePasswordVisibility('editUserPassword', this)" 
                            class="absolute inset-y-0 left-0 pl-3.5 pr-2 flex items-center text-slate-400 hover:text-slate-700 cursor-pointer focus:outline-none transition" 
                            title="إظهار / إخفاء كلمة المرور">
                        <svg class="eye-open w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg class="eye-closed w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditUserModal()" 
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: User QR Code Quick Login -->
<div id="userQrModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white dark:bg-[#161d27] rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.008v.008H6.75V6.75zM6.75 16.5h.008v.008H6.75V16.5zM16.5 6.75h.008v.008H16.5V6.75zM13.5 13.5h3.75m0 3.75h.008v.008h-.008v-.008zm-3.75 3.75h7.5" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">رمز QR للدخول الفوري للحساب</h3>
                    <p class="text-[10px] text-slate-400">مسح الكود للدخول المباشر إلى المنصة</p>
                </div>
            </div>
            <button type="button" onclick="closeUserQrModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-lg cursor-pointer">✕</button>
        </div>

        <!-- Target User Info Card -->
        <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-200 dark:border-slate-700/80 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div id="qrUserAvatar" class="w-10 h-10 rounded-full bg-indigo-600 text-white font-bold flex items-center justify-center text-sm shrink-0 shadow-xs">
                    م
                </div>
                <div>
                    <div id="qrUserName" class="font-bold text-slate-900 dark:text-white text-xs">اسم المستخدم</div>
                    <div id="qrUserEmail" class="text-[10px] text-slate-500 font-mono" dir="ltr">user@taxinaguilou.dz</div>
                </div>
            </div>
            <span id="qrUserRoleBadge" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-200">
                سائق أجرة
            </span>
        </div>

        <!-- QR Code Container Card -->
        <div class="p-4 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 flex flex-col items-center justify-center space-y-3 shadow-inner">
            <div class="p-3 bg-white rounded-2xl border-2 border-slate-100 shadow-sm flex items-center justify-center">
                <div id="qrCodeCanvas" class="w-[200px] h-[200px] flex items-center justify-center overflow-hidden">
                    <!-- QR is rendered here via qrcode.min.js -->
                </div>
            </div>

            <p class="text-[11px] text-slate-500 dark:text-slate-400 text-center leading-relaxed">
                امسح هذا الرمز بكاميرا هاتفك للدخول الفوري والمباشر إلى هذا الحساب بدون الحاجة لكتابة كلمة المرور.
            </p>
        </div>

        <!-- Direct Login Button -->
        <div>
            <a id="qrDirectLoginBtn" href="#" 
               class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 font-black text-xs text-center flex items-center justify-center gap-2 transition shadow-sm cursor-pointer">
                <span>🚀</span>
                <span>دخول مباشر الآن إلى حسابه (فتح الحساب)</span>
            </a>
        </div>

        <!-- Secondary Actions (Copy Link, Print, Regenerate) -->
        <div class="grid grid-cols-2 gap-2 pt-1">
            <button type="button" onclick="copyQrLoginUrl()" 
                    class="py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold text-[11px] text-center flex items-center justify-center gap-1.5 transition cursor-pointer">
                <span>📋</span>
                <span id="qrCopyBtnText">نسخ رابط الدخول</span>
            </button>
            <button type="button" onclick="printUserQrCard()" 
                    class="py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold text-[11px] text-center flex items-center justify-center gap-1.5 transition cursor-pointer">
                <span>🖨️</span>
                <span>طباعة بطاقة QR</span>
            </button>
        </div>

        <!-- Regenerate QR Token Form -->
        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[11px]">
            <form method="POST" action="" onsubmit="return confirm('هل تريد تجديد وإلغاء رمز QR القديم لهذا المستخدم وإصدار رمز جديد؟')" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="regenerate_qr">
                <input type="hidden" name="user_id" id="qrRegenerateUserId">
                <button type="submit" class="text-slate-400 hover:text-amber-600 font-bold text-[10px] transition flex items-center gap-1 cursor-pointer">
                    <span>🔄</span>
                    <span>توليد رمز QR جديد (إلغاء القديم)</span>
                </button>
            </form>
            <button type="button" onclick="closeUserQrModal()" 
                    class="px-4 py-1.5 rounded-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold text-[11px] transition cursor-pointer">
                إغلاق
            </button>
        </div>
    </div>
</div>

<script src="<?= url('/assets/js/qrcode.min.js') ?>"></script>
<script>
function openEditUserModal(data) {
    document.getElementById('editUserId').value = data.id || '';
    document.getElementById('editUserFullName').value = data.full_name || '';
    document.getElementById('editUserEmail').value = data.email || '';
    document.getElementById('editUserPhone').value = data.phone || '';
    document.getElementById('editUserRole').value = data.role || 'driver';
    document.getElementById('editUserStatus').value = data.status || 'active';

    const subType = data.subscription_type || 'unlimited';
    const subExp = data.subscription_expires_at || '';
    document.getElementById('editSubscriptionType').value = subType;
    document.getElementById('editSubscriptionExpiresAt').value = subExp;
    onEditSubscriptionTypeChanged();

    const pwdInput = document.getElementById('editUserPassword');
    if (pwdInput) {
        pwdInput.value = '';
        pwdInput.type = 'password';
    }

    const modal = document.getElementById('editUserModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function onEditSubscriptionTypeChanged() {
    const type = document.getElementById('editSubscriptionType').value;
    const expiryGroup = document.getElementById('editSubscriptionExpiryGroup');
    const presetsGroup = document.getElementById('editSubscriptionPresetsGroup');
    const expiryInput = document.getElementById('editSubscriptionExpiresAt');

    if (type === 'unlimited') {
        expiryGroup.classList.add('opacity-40', 'pointer-events-none');
        presetsGroup.classList.add('hidden');
        expiryInput.removeAttribute('required');
        expiryInput.value = '';
        updateSubscriptionRemainingBadge('');
    } else {
        expiryGroup.classList.remove('opacity-40', 'pointer-events-none');
        presetsGroup.classList.remove('hidden');
        expiryInput.setAttribute('required', 'required');
        if (!expiryInput.value) {
            setEditSubPreset(type === 'free' ? 7 : 30);
        } else {
            updateSubscriptionRemainingBadge(expiryInput.value);
        }
    }
}

function setEditSubPreset(days) {
    const d = new Date();
    d.setDate(d.getDate() + parseInt(days, 10));
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const formatted = `${yyyy}-${mm}-${dd}`;
    
    const input = document.getElementById('editSubscriptionExpiresAt');
    if (input) {
        input.value = formatted;
        updateSubscriptionRemainingBadge(formatted);
    }
    
    const statusSelect = document.getElementById('editUserStatus');
    if (statusSelect && statusSelect.value === 'suspended') {
        statusSelect.value = 'active';
    }
}

function updateSubscriptionRemainingBadge(dateStr) {
    const badge = document.getElementById('editSubscriptionDaysBadge');
    if (!badge) return;
    if (!dateStr) {
        badge.textContent = '';
        return;
    }
    const target = new Date(dateStr + 'T00:00:00');
    const today = new Date();
    today.setHours(0,0,0,0);
    const diffTime = target - today;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    if (diffDays < 0) {
        badge.textContent = `⚠️ منتهي منذ ${Math.abs(diffDays)} يوم (حساب مجمد)`;
        badge.className = 'font-num text-rose-600 font-bold';
    } else if (diffDays === 0) {
        badge.textContent = '⚠️ ينتهي اليوم!';
        badge.className = 'font-num text-amber-600 font-bold';
    } else {
        badge.textContent = `صالح لمدة ${diffDays} يوم من اليوم`;
        badge.className = 'font-num text-emerald-600 font-bold';
    }
}

function onNewSubscriptionTypeChanged() {
    const type = document.getElementById('newSubscriptionType').value;
    const expiryGroup = document.getElementById('newSubscriptionExpiryGroup');
    const presetsGroup = document.getElementById('newSubscriptionPresetsGroup');
    const expiryInput = document.getElementById('newSubscriptionExpiresAt');

    if (type === 'unlimited') {
        expiryGroup.classList.add('opacity-40', 'pointer-events-none');
        presetsGroup.classList.add('hidden');
        expiryInput.removeAttribute('required');
        expiryInput.value = '';
    } else {
        expiryGroup.classList.remove('opacity-40', 'pointer-events-none');
        presetsGroup.classList.remove('hidden');
        expiryInput.setAttribute('required', 'required');
        if (!expiryInput.value) {
            setNewSubPreset(type === 'free' ? 7 : 30);
        }
    }
}

function setNewSubPreset(days) {
    const d = new Date();
    d.setDate(d.getDate() + parseInt(days, 10));
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const formatted = `${yyyy}-${mm}-${dd}`;
    const input = document.getElementById('newSubscriptionExpiresAt');
    if (input) {
        input.value = formatted;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const editExp = document.getElementById('editSubscriptionExpiresAt');
    if (editExp) {
        editExp.addEventListener('change', function() {
            updateSubscriptionRemainingBadge(this.value);
        });
    }
});

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPass = input.type === 'password';
    input.type = isPass ? 'text' : 'password';

    const eyeOpen = btn.querySelector('.eye-open');
    const eyeClosed = btn.querySelector('.eye-closed');
    if (eyeOpen && eyeClosed) {
        if (isPass) {
            eyeOpen.classList.add('hidden');
            eyeClosed.classList.remove('hidden');
            btn.classList.add('text-amber-600');
            btn.classList.remove('text-slate-400');
        } else {
            eyeOpen.classList.remove('hidden');
            eyeClosed.classList.add('hidden');
            btn.classList.remove('text-amber-600');
            btn.classList.add('text-slate-400');
        }
    }
}

// User QR Code Direct Login Logic
let currentQrInstance = null;
let currentQrLoginUrl = '';
let currentQrUserData = null;

function openUserQrModal(data) {
    currentQrUserData = data;
    document.getElementById('qrRegenerateUserId').value = data.id || '';
    document.getElementById('qrUserName').textContent = data.full_name || '';
    document.getElementById('qrUserEmail').textContent = data.email || '';
    document.getElementById('qrUserAvatar').textContent = (data.full_name || 'م').charAt(0);
    document.getElementById('qrUserRoleBadge').textContent = data.role_label || data.role || '';

    currentQrLoginUrl = data.login_url || '';
    document.getElementById('qrDirectLoginBtn').href = currentQrLoginUrl;

    const qrContainer = document.getElementById('qrCodeCanvas');
    qrContainer.innerHTML = '';

    if (typeof QRCode !== 'undefined') {
        currentQrInstance = new QRCode(qrContainer, {
            text: currentQrLoginUrl,
            width: 200,
            height: 200,
            colorDark: "#0f172a",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.M
        });
    } else {
        const img = document.createElement('img');
        img.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&data=${encodeURIComponent(currentQrLoginUrl)}`;
        img.alt = 'QR Code';
        img.className = 'w-[200px] h-[200px] rounded-lg';
        qrContainer.appendChild(img);
    }

    const copyBtnText = document.getElementById('qrCopyBtnText');
    if (copyBtnText) copyBtnText.textContent = 'نسخ رابط الدخول';

    const modal = document.getElementById('userQrModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeUserQrModal() {
    const modal = document.getElementById('userQrModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function copyQrLoginUrl() {
    if (!currentQrLoginUrl) return;
    navigator.clipboard.writeText(currentQrLoginUrl).then(function() {
        const btnText = document.getElementById('qrCopyBtnText');
        if (btnText) {
            btnText.textContent = '✅ تم النسخ!';
            setTimeout(function() {
                btnText.textContent = 'نسخ رابط الدخول';
            }, 2500);
        }
    }).catch(function() {
        prompt('انسخ رابط الدخول السريع:', currentQrLoginUrl);
    });
}

function printUserQrCard() {
    if (!currentQrUserData || !currentQrLoginUrl) return;
    const printWindow = window.open('', '_blank', 'width=600,height=700');
    const qrImgSrc = `https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=8&data=${encodeURIComponent(currentQrLoginUrl)}`;
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>بطاقة الدخول السريع QR - ${currentQrUserData.full_name}</title>
            <style>
                body { font-family: 'Cairo', 'Tajawal', sans-serif; text-align: center; padding: 30px; background: #fff; }
                .card { border: 2px dashed #0f172a; border-radius: 20px; padding: 25px; max-width: 350px; margin: auto; }
                h2 { margin: 10px 0 5px; font-size: 18px; color: #0f172a; }
                .role { font-size: 12px; color: #b45309; font-weight: bold; margin-bottom: 15px; }
                .qr-img { width: 220px; height: 220px; margin: 15px auto; display: block; }
                .instructions { font-size: 11px; color: #64748b; line-height: 1.6; margin-top: 15px; }
                .footer { font-size: 10px; color: #94a3b8; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
            </style>
        </head>
        <body>
            <div class="card">
                <div style="font-size:24px;">🚖 تاكسي نقيلو</div>
                <h2>${currentQrUserData.full_name}</h2>
                <div class="role">${currentQrUserData.role_label || currentQrUserData.role}</div>
                <img src="${qrImgSrc}" class="qr-img" onload="window.print();" />
                <div class="instructions">امسح رمز QR بكاميرا الهاتف لتسجيل الدخول الفوري والمباشر إلى المنصة.</div>
                <div class="footer">منصة تاكسي نقيلو لإدارة سيارات الأجرة © 2026</div>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
