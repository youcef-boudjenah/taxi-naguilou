<?php
// debug.php - Bluehost / Shared Hosting Diagnostic & Health Check Tool
require_once __DIR__ . '/includes/auth.php';
require_login();
require_role(['super_admin', 'admin']);


$dbConfigExists = file_exists(__DIR__ . '/config/db.php');
if ($dbConfigExists) {
    require_once __DIR__ . '/config/db.php';
}

$dbHost = defined('DB_HOST') ? DB_HOST : 'NOT_DEFINED';
$dbPort = defined('DB_PORT') ? DB_PORT : 'NOT_DEFINED';
$dbUser = defined('DB_USER') ? DB_USER : 'NOT_DEFINED';
$dbName = defined('DB_NAME') ? DB_NAME : 'NOT_DEFINED';

// Test DB Connection directly
$dbConnected = false;
$dbError = '';
$tables = [];

if ($dbConfigExists) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4
        ]);
        $dbConnected = true;
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص تشخيص الخادم (Bluehost Diagnostic) | تاكسي نقيلو</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Cairo', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 p-4 sm:p-8">

    <div class="max-w-3xl mx-auto space-y-6">

        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div>
                <h1 class="text-xl font-black text-slate-900">🔍 أداة تشخيص الخادم وقاعدة البيانات (Server Diagnostic)</h1>
                <p class="text-xs text-slate-500 mt-1">كشف أسباب الشاشة البيضاء (White Screen) على استضافة Bluehost أو cPanel</p>
            </div>
            <a href="dashboard/index.php" class="px-3.5 py-2 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold rounded-xl text-xs transition">
                لوحة التحكم ←
            </a>
        </div>

        <!-- 1. Database Connection Status -->
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
            <h2 class="text-sm font-black text-slate-900 flex items-center gap-2">
                <span>1. حالة الاتصال بقاعدة البيانات MySQL</span>
                <?php if ($dbConnected): ?>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">✓ متصل بنجاح</span>
                <?php else: ?>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800">❌ فشل الاتصال</span>
                <?php endif; ?>
            </h2>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs font-mono">
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200">
                    <span class="block text-slate-400 text-[10px]">HOST</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($dbHost) ?></strong>
                </div>
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200">
                    <span class="block text-slate-400 text-[10px]">PORT</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($dbPort) ?></strong>
                </div>
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200">
                    <span class="block text-slate-400 text-[10px]">DB NAME</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($dbName) ?></strong>
                </div>
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200">
                    <span class="block text-slate-400 text-[10px]">DB USER</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($dbUser) ?></strong>
                </div>
            </div>

            <?php if (!$dbConnected): ?>
                <div class="p-4 bg-rose-50 border border-rose-200 rounded-xl space-y-2 text-xs">
                    <div class="font-bold text-rose-800">❌ رسالة الخطأ الصادرة من خادم MySQL:</div>
                    <pre class="bg-white p-3 rounded-lg border border-rose-200 text-rose-700 font-mono text-[11px] overflow-x-auto"><?= htmlspecialchars($dbError) ?></pre>
                    <div class="text-slate-700 mt-2">
                        💡 <strong>طريقة الإصلاح على Bluehost:</strong>
                        <ul class="list-disc list-inside mt-1 space-y-1 text-slate-600">
                            <li>ادخل إلى <strong>cPanel → MySQL Databases</strong> في Bluehost.</li>
                            <li>تأكد من إنشاء قاعدة بيانات باسم يبدأ ببادئة الحساب (مثال: <code class="font-mono bg-white px-1">user_taxi</code>).</li>
                            <li>تأكد من إنشاء مستخدم وإعطائه صلاحية <strong>ALL PRIVILEGES</strong> على هذه القاعدة.</li>
                            <li>قم بتحديث ملف <code class="font-mono bg-white px-1">config/db.php</code> بهذه البيانات، أو افتح <a href="install.php" class="text-amber-700 font-bold underline">معالج التثبيت (install.php)</a>.</li>
                        </ul>
                    </div>
                </div>
            <?php else: ?>
                <div class="p-3.5 bg-emerald-50 border border-emerald-200 rounded-xl text-xs space-y-1 text-emerald-900">
                    <div class="font-bold">✓ تم العثور على <?= count($tables) ?> جدول في قاعدة البيانات:</div>
                    <div class="text-[11px] font-mono text-emerald-800">
                        <?= implode(' • ', $tables) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. System Environment -->
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-3">
            <h2 class="text-sm font-black text-slate-900">2. بيئة التشغيل والخادم</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200 flex justify-between">
                    <span class="text-slate-500">إصدار PHP:</span>
                    <strong class="font-mono text-slate-800"><?= PHP_VERSION ?></strong>
                </div>
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200 flex justify-between">
                    <span class="text-slate-500">معرّف الجلسة (Session):</span>
                    <strong class="font-mono text-slate-800"><?= session_id() ? 'نشطة ✓' : 'غير مفعلة ✕' ?></strong>
                </div>
                <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200 flex justify-between">
                    <span class="text-slate-500">مجلد Uploads:</span>
                    <strong class="text-emerald-700 font-bold"><?= is_writable(__DIR__ . '/uploads') ? 'قابل للكتابة ✓' : 'للقراءة فقط ⚠️' ?></strong>
                </div>
            </div>
        </div>

        <!-- 3. Direct Actions -->
        <div class="flex items-center justify-between pt-2">
            <a href="install.php" class="px-5 py-2.5 bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs rounded-xl transition">
                تشغيل معالج التثبيت (install.php)
            </a>
            <a href="auth/login.php" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs rounded-xl transition">
                شاشة تسجيل الدخول (login.php)
            </a>
        </div>

    </div>

</body>
</html>
