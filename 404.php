<?php
// 404.php - Enterprise Page Not Found Error Page
http_response_code(404);
require_once __DIR__ . '/config/db.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - الصفحة غير موجودة | تاكسي نقيلو</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">
    <style>
        body { font-family: 'Cairo', 'Tajawal', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full text-center space-y-6 bg-slate-800/80 border border-slate-700/80 rounded-3xl p-8 shadow-2xl backdrop-blur-sm">
        <div class="w-20 h-20 mx-auto rounded-3xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center text-4xl shadow-inner font-black">
            🔍
        </div>
        <div class="space-y-2">
            <span class="px-3 py-1 rounded-full text-xs font-black bg-amber-500/20 text-amber-300 border border-amber-500/30 font-num">خطأ 404 • مفقود</span>
            <h1 class="text-2xl font-black text-white pt-1">الصفحة غير موجودة</h1>
            <p class="text-xs text-slate-400 leading-relaxed">
                لم نتمكن من العثور على الصفحة أو الرابط المطلوب. ربما تم نقلها، تغيير اسمها، أو أن الرابط غير صحيح.
            </p>
        </div>
        <div class="pt-2 flex flex-col sm:flex-row gap-3 justify-center">
            <a href="<?= url('/dashboard/index.php') ?>" class="px-5 py-2.5 rounded-xl bg-amber-500 text-slate-950 font-bold text-xs hover:bg-amber-400 transition shadow">
                الانتقال للرئيسية
            </a>
            <button onclick="window.history.back()" class="px-4 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-slate-200 font-bold text-xs transition">
                رجوع للخلف
            </button>
        </div>
        <div class="text-[11px] text-slate-500 pt-3 border-t border-slate-700/60">
            تاكسي نقيلو ERP • نظام تسيير حظائر سيارات الأجرة
        </div>
    </div>
</body>
</html>

