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
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <!-- Production Compiled Tailwind CSS -->
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">
    <?= render_theme_head() ?>
    <style>
        html, body, input, button, select, textarea { 
            font-family: 'Cairo', 'Tajawal', sans-serif !important; 
            font-weight: 400;
            text-rendering: optimizeLegibility; 
            -webkit-font-smoothing: antialiased; 
            -moz-osx-font-smoothing: grayscale;
        }
        .font-black { font-weight: 700 !important; }
        .font-extrabold { font-weight: 650 !important; }
        .font-bold { font-weight: 600 !important; }
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
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-left font-mono"
                           placeholder="" value="">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="password" class="block text-xs font-bold text-slate-700">كلمة المرور</label>
                        <button type="button" onclick="togglePassVisibility()" class="text-[11px] font-bold text-amber-600 hover:text-amber-700 select-none">
                            <span id="passToggleText">إظهار كلمة المرور 👁️</span>
                        </button>
                    </div>
                    <div class="relative">
                        <input type="password" id="password" name="password" required dir="ltr"
                               class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-left font-mono"
                               placeholder="">
                    </div>
                </div>

                <button type="submit" class="w-full py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-sm transition shadow-sm flex items-center justify-center gap-2">
                    <span>دخول إلى المنصة</span>
                    <span>←</span>
                </button>
            </form>

            <!-- Quick 1-Click Demo & Admin Accounts -->
            

        </div>

        <div class="text-center text-xs text-slate-500 flex items-center justify-between px-2">
            <a href="<?= url('/') ?>" class="hover:text-amber-600 transition">← العودة للرئيسية</a>
            <!-- <a href="<?= url('/install.php') ?>" class="text-slate-400 hover:text-amber-600 transition text-[11px]">معالج التثبيت (Installer)</a> -->
            <a href="<?= url('/auth/register.php') ?>" class="font-bold text-amber-600 hover:underline">تسجيل حساب جديد</a>
        </div>

    </div>

    <script>
        function fillLogin(email, password) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
        }

        function togglePassVisibility() {
            const input = document.getElementById('password');
            const text = document.getElementById('passToggleText');
            if (input.type === 'password') {
                input.type = 'text';
                text.textContent = 'إخفاء كلمة المرور 🔒';
            } else {
                input.type = 'password';
                text.textContent = 'إظهار كلمة المرور 👁️';
            }
        }
    </script>
</body>
</html>
