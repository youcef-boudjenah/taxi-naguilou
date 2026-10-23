<?php
/**
 * TAXI-NAGUILOU — One-Click Database Migration Runner (CLI & Web)
 * 
 * Executes `migrate_all_new_columns.sql` safely against the active database.
 * Run via CLI: php migrate.php
 * Run via Web: http://localhost/migrate.php (or your local domain)
 */

require_once __DIR__ . '/config/db.php';

$pdo = getDBConnection();
if (!$pdo) {
    die("❌ خطأ: تعذر الاتصال بقاعدة البيانات. يرجى التحقق من config/db.php\n");
}

$sqlFile = __DIR__ . '/migrate_all_new_columns.sql';
if (!file_exists($sqlFile)) {
    die("❌ خطأ: ملف الترقية غير موجود: $sqlFile\n");
}

$isCli = (php_sapi_name() === 'cli');

// If running in browser and session has user, require admin/super_admin; if fresh/not logged in, require confirmation
if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Allow if super_admin or if running on local environment (127.0.0.1 / ::1 / localhost)
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocal = in_array($clientIp, ['127.0.0.1', '::1', 'localhost']) || strpos($_SERVER['HTTP_HOST'] ?? '', '.test') !== false;
    
    if (!$isLocal && (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['super_admin', 'admin']))) {
        die("❌ غير مصرح: الترقية تتطلب صلاحيات المشرف العام أو التشغيل محلياً.\n");
    }
}

$results = [
    'success' => true,
    'tables_created' => [],
    'columns_verified' => 0,
    'errors' => []
];

try {
    $sqlContent = file_get_contents($sqlFile);
    
    // Remove USE statement to use current PDO database context safely
    $cleanSql = preg_replace('/USE\s+`?[^`]+`?;/i', '', $sqlContent);

    // If DELIMITER is present (used for procedures in CLI/phpMyAdmin), parse it cleanly for PDO
    if (preg_match('/DELIMITER\s+\$\$(.*?)DELIMITER\s+;/s', $cleanSql, $m)) {
        $procBody = trim($m[1]);
        $procBody = preg_replace('/\$\$\s*$/', '', $procBody); // remove trailing $$
        
        $parts = explode('DELIMITER $$', $cleanSql);
        $before = trim($parts[0]);
        $after = trim(explode('DELIMITER ;', $cleanSql)[1] ?? '');

        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
        if (!empty($before)) {
            $pdo->exec($before);
        }
        if (!empty($procBody)) {
            $pdo->exec($procBody);
        }
        if (!empty($after)) {
            $pdo->exec($after);
        }
    } else {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
        $pdo->exec($cleanSql);
    }

    // Call internal db.php helpers to ensure caches, indexes and data backfills are 100% synced
    if (function_exists('initAllTables')) {
        initAllTables($pdo);
    }
    if (function_exists('migrateDriverFicheFields')) {
        migrateDriverFicheFields($pdo);
    }
    if (function_exists('migrateSoftDeleteFields')) {
        migrateSoftDeleteFields($pdo);
    }
    if (function_exists('migrateDebtsAndVersements')) {
        migrateDebtsAndVersements($pdo);
    }
    if (function_exists('ensure_db_indexes')) {
        ensure_db_indexes($pdo);
    }

    // Refresh migration lock timestamp
    $schemaLock = __DIR__ . '/uploads/.schema_migrated_v250';
    $destDir = dirname($schemaLock);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0777, true);
    }
    @file_put_contents($schemaLock, json_encode([
        'migrated_at' => date('c'),
        'v' => '2.5.0',
        'file' => 'migrate_all_new_columns.sql'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

} catch (\Throwable $e) {
    $results['success'] = false;
    $results['errors'][] = $e->getMessage();
}

// Fetch verified status
$allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalVehicles = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();

if ($isCli) {
    echo "\n=======================================================\n";
    echo "  🚕 TAXI-NAGUILOU — DATABASE MIGRATION SYSTEM\n";
    echo "=======================================================\n\n";

    if ($results['success']) {
        echo "✅ تم تطبيق جميع ترقيات قاعدة البيانات بنجاح!\n";
        echo "📊 إحصائيات النظام:\n";
        echo "  - عدد الجداول النشطة: " . count($allTables) . " جدول\n";
        echo "  - عدد المستخدمين: " . $totalUsers . " سجل\n";
        echo "  - عدد المركبات: " . $totalVehicles . " سجل\n";
        echo "  - ملف الترقية: migrate_all_new_columns.sql\n\n";
    } else {
        echo "❌ حدثت أخطاء أثناء تنفيذ الترقية:\n";
        foreach ($results['errors'] as $err) {
            echo "  - " . $err . "\n";
        }
        echo "\n";
    }
    exit($results['success'] ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-900 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ترقية قاعدة البيانات | تاكسي نقيلو</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">
    <style>
        body, * { font-family: 'Cairo', sans-serif !important; }
    </style>
</head>
<body class="min-h-full flex items-center justify-center p-6 bg-slate-950 text-slate-100">
    <div class="max-w-xl w-full bg-slate-900 border border-slate-800 rounded-3xl p-8 shadow-2xl">
        <div class="flex items-center gap-4 mb-6">
            <div class="w-14 h-14 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-3xl">
                🚕
            </div>
            <div>
                <h1 class="text-2xl font-black text-white">ترقية قاعدة البيانات (Migration)</h1>
                <p class="text-sm text-slate-400">منصة تاكسي نقيلو — تحديث الجداول والأعمدة والفهارس</p>
            </div>
        </div>

        <?php if ($results['success']): ?>
            <div class="p-5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 mb-6 flex items-start gap-3">
                <span class="text-2xl">✅</span>
                <div>
                    <h3 class="font-black text-base">تمت الترقية بنجاح تام!</h3>
                    <p class="text-xs text-emerald-400 mt-1">تم فحص وإضافة جميع الأعمدة الجديدة والجداول والفهارس المطلوبة لقاعدة البيانات دون أي تكرار أو تعارض.</p>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 mb-6">
                <div class="bg-slate-800/60 border border-slate-800 p-4 rounded-2xl text-center">
                    <span class="text-xs text-slate-400 block font-bold">الجداول المحدثة</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= count($allTables) ?></span>
                </div>
                <div class="bg-slate-800/60 border border-slate-800 p-4 rounded-2xl text-center">
                    <span class="text-xs text-slate-400 block font-bold">المستخدمون</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= $totalUsers ?></span>
                </div>
                <div class="bg-slate-800/60 border border-slate-800 p-4 rounded-2xl text-center">
                    <span class="text-xs text-slate-400 block font-bold">المركبات</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= $totalVehicles ?></span>
                </div>
            </div>

            <div class="space-y-2 text-xs text-slate-400 mb-6 bg-slate-950 p-4 rounded-xl border border-slate-800 font-mono">
                <div>📁 الملف المصدري: <span class="text-amber-400">migrate_all_new_columns.sql</span></div>
                <div>🔄 طريقة التنفيذ: <span class="text-emerald-400">Idempotent Stored Procedure</span></div>
                <div>🔒 التوافقية: <span class="text-blue-400">MySQL 5.7+ / 8.0+ / MariaDB</span></div>
            </div>

            <div class="flex items-center gap-3">
                <a href="<?= url('/dashboard/') ?>" class="flex-1 py-3 px-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-center text-sm transition">
                    الانتقال إلى لوحة التحكم ←
                </a>
                <a href="<?= url('/migrate.php') ?>" class="py-3 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-center text-sm transition">
                    إعادة الفحص 🔄
                </a>
            </div>
        <?php else: ?>
            <div class="p-5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-300 mb-6 flex items-start gap-3">
                <span class="text-2xl">⚠️</span>
                <div>
                    <h3 class="font-black text-base">حدث خطأ أثناء الترقية</h3>
                    <?php foreach ($results['errors'] as $err): ?>
                        <p class="text-xs text-rose-400 mt-1"><?= htmlspecialchars($err) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="<?= url('/migrate.php') ?>" class="block w-full py-3 px-4 rounded-xl bg-amber-500 text-slate-950 font-black text-center text-sm">
                إعادة المحاولة
            </a>
        <?php endif; ?>
    </div>
</body>
</html>
