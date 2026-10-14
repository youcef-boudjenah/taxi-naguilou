<?php
// auth/register.php - Clean Owner/Driver Registration
require_once __DIR__ . '/../includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'owner';
    $wilaya = trim($_POST['wilaya'] ?? '16 - الجزائر العاصمة');

    // Prevent privilege escalation - only owners and drivers can self-register
    $allowedRoles = ['owner', 'driver'];
    if (!in_array($role, $allowedRoles)) {
        $role = 'owner';
    }

    if (empty($fullName) || empty($email) || empty($phone) || empty($password)) {
        $error = 'يرجى ملء جميع الحقول الإلزامية';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'يرجى إدخال بريد إلكتروني صالح';
    } elseif (strlen($password) < 6) {
        $error = 'كلمة المرور يجب ألا تقل عن 6 أحرف أو أرقام';
    } else {
        $pdo = getDBConnection();
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'البريد الإلكتروني مسجل مسبقاً، يرجى تسجيل الدخول';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmtInsert = $pdo->prepare("
                    INSERT INTO users (full_name, email, password_hash, phone, role, status, wilaya)
                    VALUES (?, ?, ?, ?, ?, 'active', ?)
                ");
                $stmtInsert->execute([$fullName, $email, $hash, $phone, $role, $wilaya]);

                login_user($email, $password);
                redirect('/dashboard/index.php');
            }
        } else {
            $error = 'تعذر الاتصال بقاعدة البيانات';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب جديد | تاكسي نقيلو</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { amber: { 500: '#f59e0b', 600: '#d97706' } },
                    fontFamily: { sans: ['Cairo', 'sans-serif'], num: ['Plus Jakarta Sans', 'Cairo', 'sans-serif'] }
                }
            }
        };
    </script>
    <?= render_theme_head() ?>
    <style>body { font-family: 'Cairo', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">

    <!-- Floating Theme Toggle -->
    <div class="fixed top-4 left-4 z-50">
        <?= render_theme_toggle_btn('shadow-md') ?>
    </div>

    <div class="max-w-md w-full space-y-6">
        
        <div class="text-center space-y-2">
            <a href="<?= url('/') ?>" class="inline-flex flex-col items-center justify-center gap-2">
                <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-16 h-16 object-contain rounded-full shadow-md bg-white p-1 border-2 border-amber-400 mx-auto">
                <div>
                    <span class="text-2xl font-black text-slate-900 tracking-tight block">تاكسي <span class="text-amber-600">نقيلو</span></span>
                    <span class="text-[10px] text-slate-400 font-bold block uppercase tracking-wider">EURL TAXI NAGUILOU Services</span>
                </div>
            </a>
            <h2 class="text-lg font-bold text-slate-800 pt-1">إنشاء حساب جديد</h2>
            <p class="text-xs text-slate-500">سجل للبدء في إدارة سيارات الأجرة والعقود</p>
        </div>

        <div class="bg-white p-7 rounded-2xl border border-slate-200 shadow-sm space-y-5">
            
            <?php if (!empty($error)): ?>
                <div class="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold rounded-lg">
                    ✕ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <div>
                    <label for="fullName" class="block text-xs font-bold text-slate-700 mb-1">الاسم واللقب</label>
                    <input type="text" id="fullName" name="full_name" required placeholder="مثال: جمال بن يحيى"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                </div>

                <div>
                    <label for="email" class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                    <input type="email" id="email" name="email" required dir="ltr" placeholder="jamal@example.dz"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-right">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="phone" class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                        <input type="tel" id="phone" name="phone" required dir="ltr" placeholder="0550123456"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-right">
                    </div>

                    <div>
                        <label for="role" class="block text-xs font-bold text-slate-700 mb-1">صفتك</label>
                        <select id="role" name="role" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            <option value="owner">مالك مركبة / مكتب</option>
                            <option value="driver">سائق تاكسي</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="wilaya" class="block text-xs font-bold text-slate-700 mb-1">الولاية</label>
                    <input type="text" id="wilaya" name="wilaya" value="16 - الجزائر العاصمة"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                </div>

                <div>
                    <label for="password" class="block text-xs font-bold text-slate-700 mb-1">كلمة المرور</label>
                    <input type="password" id="password" name="password" required dir="ltr" placeholder="••••••••"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-right">
                </div>

                <button type="submit" class="w-full py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-sm transition shadow-sm">
                    إنشاء الحساب وبدء الاستخدام ←
                </button>
            </form>

        </div>

        <div class="text-center text-xs text-slate-500">
            لديك حساب بالفعل؟ <a href="<?= url('/auth/login.php') ?>" class="font-bold text-amber-600 hover:underline">تسجيل الدخول</a>
        </div>

    </div>

</body>
</html>
