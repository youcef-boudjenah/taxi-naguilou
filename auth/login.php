<?php
// auth/login.php - Clean Login Screen with 1-Click Demo Accounts
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('/dashboard/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $res = login_user($email, $password);
    if ($res['success']) {
        redirect('/dashboard/index.php');
    } else {
        $error = $res['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | تاكسي نقيلو</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alexandria:wght@400;500;600;700;800;900&family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { amber: { 500: '#f59e0b', 600: '#d97706' } },
                    fontFamily: { 
                        sans: ['Alexandria', 'Cairo', 'Tajawal', 'sans-serif'], 
                        num: ['Plus Jakarta Sans', 'Alexandria', 'Cairo', 'sans-serif'] 
                    }
                }
            }
        };
    </script>
    <?= render_theme_head() ?>
    <style>
        html, body, input, button, select, textarea { 
            font-family: 'Alexandria', 'Cairo', 'Tajawal', sans-serif; 
            text-rendering: optimizeLegibility; 
            -webkit-font-smoothing: antialiased; 
        }
        html[dir="rtl"], [dir="rtl"], h1, h2, h3, h4, h5, h6, p, span, a, button, label, input, td, th {
            letter-spacing: normal !important;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">

    <!-- Floating Theme Toggle -->
    <div class="fixed top-4 left-4 z-50">
        <?= render_theme_toggle_btn('shadow-md') ?>
    </div>

    <div class="max-w-md w-full space-y-6">
        
        <!-- Header / Logo -->
        <div class="text-center space-y-2">
            <a href="<?= url('/') ?>" class="inline-flex flex-col items-center justify-center gap-2">
                <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-16 h-16 object-contain rounded-full shadow-md bg-white p-1 border-2 border-amber-400 mx-auto">
                <div>
                    <span class="text-2xl font-black text-slate-900 tracking-tight block">تاكسي <span class="text-amber-600">نقيلو</span></span>
                    <span class="text-[10px] text-slate-400 font-bold block uppercase tracking-wider">EURL TAXI NAGUILOU Services</span>
                </div>
            </a>
            <h2 class="text-lg font-bold text-slate-800 pt-1">تسجيل الدخول إلى لوحة إدارة الأسطول</h2>
            <p class="text-xs text-slate-500">أدخل بريدك الإلكتروني وكلمة المرور للمتابعة</p>
        </div>

        <!-- Login Card -->
        <div class="bg-white p-7 rounded-2xl border border-slate-200 shadow-sm space-y-5">
            
            <?php if (!empty($error)): ?>
                <div class="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold rounded-lg">
                    ✕ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <div>
                    <label for="email" class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                    <input type="email" id="email" name="email" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-right"
                           placeholder="admin@taxinaguilou.dz">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="password" class="block text-xs font-bold text-slate-700">كلمة المرور</label>
                        <span class="text-[11px] text-slate-400">الافتراضية: password123</span>
                    </div>
                    <input type="password" id="password" name="password" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-right"
                           placeholder="••••••••">
                </div>

                <button type="submit" class="w-full py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-sm transition shadow-sm">
                    دخول إلى المنصة ←
                </button>
            </form>

            <!-- Quick 1-Click Demo Accounts -->
            <div class="pt-4 border-t border-slate-100 space-y-2.5">
                <div class="text-[11px] font-bold text-slate-400 text-center uppercase tracking-wider">
                    ⚡ تجربة سريعة بنقرة واحدة (حسابات تجريبية):
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" onclick="fillLogin('admin@taxinaguilou.dz', 'password123')" 
                            class="px-2 py-2 rounded-lg bg-slate-50 hover:bg-amber-50 border border-slate-200 hover:border-amber-300 text-xs font-bold text-slate-700 hover:text-amber-900 transition text-center">
                        مدير المنصة
                    </button>
                    <button type="button" onclick="fillLogin('owner@taxinaguilou.dz', 'password123')" 
                            class="px-2 py-2 rounded-lg bg-slate-50 hover:bg-amber-50 border border-slate-200 hover:border-amber-300 text-xs font-bold text-slate-700 hover:text-amber-900 transition text-center">
                        مالك أسطول
                    </button>
                    <button type="button" onclick="fillLogin('driver1@taxinaguilou.dz', 'password123')" 
                            class="px-2 py-2 rounded-lg bg-slate-50 hover:bg-amber-50 border border-slate-200 hover:border-amber-300 text-xs font-bold text-slate-700 hover:text-amber-900 transition text-center">
                        سائق تاكسي
                    </button>
                </div>
            </div>

        </div>

        <div class="text-center text-xs text-slate-500 flex items-center justify-between px-2">
            <a href="<?= url('/') ?>" class="hover:text-amber-600 transition">← العودة للرئيسية</a>
            <a href="<?= url('/install.php') ?>" class="text-slate-400 hover:text-amber-600 transition text-[11px]">معالج التثبيت (Installer)</a>
            <a href="<?= url('/auth/register.php') ?>" class="font-bold text-amber-600 hover:underline">تسجيل حساب جديد</a>
        </div>

    </div>

    <script>
        function fillLogin(email, password) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
        }
    </script>
</body>
</html>
