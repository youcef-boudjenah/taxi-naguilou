<?php
/**
 * TAXI-NAGUILOU — Web & CLI System Installer
 * 
 * Supports:
 * 1. Interactive Web Wizard (Requirements Check, DB Configuration, Schema Import, Admin Setup)
 * 2. 1-Click Express Installation (Instant setup for Laragon / Localhost)
 * 3. CLI Mode via `php install.php [--express|--status|--force|--help]`
 */

define('INSTALLER_VERSION', '2.4.0');
define('LOCK_FILE', __DIR__ . '/install.lock');
define('CONFIG_FILE', __DIR__ . '/config/db.php');
define('SQL_FILE', __DIR__ . '/database.sql');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------------------------------------------
// Helper: System Diagnostics & Prerequisites
// -------------------------------------------------------------
function check_system_requirements(): array {
    $dirs = [
        'config' => __DIR__ . '/config',
        'uploads' => __DIR__ . '/uploads',
        'uploads/cars' => __DIR__ . '/uploads/cars',
        'uploads/drivers' => __DIR__ . '/uploads/drivers',
        'uploads/contracts' => __DIR__ . '/uploads/contracts',
        'uploads/slips' => __DIR__ . '/uploads/slips',
    ];

    // Auto-create uploads directories if missing
    foreach ($dirs as $key => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }
    }

    $extensions = [
        'pdo' => ['name' => 'PDO', 'required' => true],
        'pdo_mysql' => ['name' => 'PDO MySQL Driver', 'required' => true],
        'mbstring' => ['name' => 'Mbstring', 'required' => true],
        'json' => ['name' => 'JSON Parser', 'required' => true],
        'fileinfo' => ['name' => 'Fileinfo', 'required' => false],
        'gd' => ['name' => 'GD Image Library', 'required' => false],
        'openssl' => ['name' => 'OpenSSL', 'required' => true],
        'curl' => ['name' => 'cURL Client', 'required' => false],
    ];

    $extResults = [];
    $allExtsPass = true;
    foreach ($extensions as $ext => $info) {
        $loaded = extension_loaded($ext);
        if ($info['required'] && !$loaded) {
            $allExtsPass = false;
        }
        $extResults[$ext] = [
            'name' => $info['name'],
            'loaded' => $loaded,
            'required' => $info['required'],
        ];
    }

    $dirResults = [];
    $allDirsPass = true;
    foreach ($dirs as $key => $path) {
        $writable = is_writable($path) || (is_dir($path) && is_writable(dirname($path)));
        if (!$writable) {
            $allDirsPass = false;
        }
        $dirResults[$key] = [
            'path' => $path,
            'exists' => is_dir($path),
            'writable' => $writable,
        ];
    }

    $phpVersion = PHP_VERSION;
    $phpPass = version_compare($phpVersion, '7.4.0', '>=');

    return [
        'php' => [
            'version' => $phpVersion,
            'required' => '7.4.0+',
            'pass' => $phpPass,
        ],
        'extensions' => $extResults,
        'extensions_pass' => $allExtsPass,
        'directories' => $dirResults,
        'directories_pass' => $allDirsPass,
        'overall_pass' => ($phpPass && $allExtsPass && $allDirsPass),
    ];
}

// -------------------------------------------------------------
// Helper: Test Database Connection
// -------------------------------------------------------------
function test_db_connection(string $host, string $port, string $user, string $pass, string $name = ''): array {
    try {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);

        $dbExists = false;
        if (!empty($name)) {
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$name]);
            $dbExists = (bool)$stmt->fetchColumn();
        }

        return [
            'success' => true,
            'message' => 'تم الاتصال بخادم MySQL بنجاح!',
            'db_exists' => $dbExists,
            'version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'فشل الاتصال: ' . $e->getMessage(),
            'db_exists' => false,
        ];
    }
}

// -------------------------------------------------------------
// Helper: Update config/db.php Constants
// -------------------------------------------------------------
function update_config_file(string $host, string $port, string $user, string $pass, string $name): bool {
    if (!file_exists(CONFIG_FILE)) {
        return false;
    }

    $content = file_get_contents(CONFIG_FILE);
    if ($content === false) {
        return false;
    }

    $replacements = [
        "/define\('DB_HOST',\s*'[^']*'\);/" => "define('DB_HOST', " . var_export($host, true) . ");",
        "/define\('DB_PORT',\s*'[^']*'\);/" => "define('DB_PORT', " . var_export($port, true) . ");",
        "/define\('DB_USER',\s*'[^']*'\);/" => "define('DB_USER', " . var_export($user, true) . ");",
        "/define\('DB_PASS',\s*'[^']*'\);/" => "define('DB_PASS', " . var_export($pass, true) . ");",
        "/define\('DB_NAME',\s*'[^']*'\);/" => "define('DB_NAME', " . var_export($name, true) . ");",
    ];

    $newContent = preg_replace(array_keys($replacements), array_values($replacements), $content);
    if ($newContent !== null) {
        return (bool)file_put_contents(CONFIG_FILE, $newContent, LOCK_EX);
    }
    return false;
}

// -------------------------------------------------------------
// Helper: Execute Full Installation Logic
// -------------------------------------------------------------
function perform_installation(array $params): array {
    $host = trim($params['db_host'] ?? '127.0.0.1');
    $port = trim($params['db_port'] ?? '3306');
    $user = trim($params['db_user'] ?? 'root');
    $pass = $params['db_pass'] ?? '';
    $name = trim($params['db_name'] ?? 'taxi_naguilou');

    $adminName = trim($params['admin_name'] ?? 'مدير المنصة - تاكسي نقيلو');
    $adminEmail = trim($params['admin_email'] ?? 'admin@taxinaguilou.dz');
    $adminPhone = trim($params['admin_phone'] ?? '0550000001');
    $adminPass = $params['admin_pass'] ?? 'password123';
    $adminWilaya = trim($params['admin_wilaya'] ?? '16 - الجزائر العاصمة');
    $seedDemo = !empty($params['seed_demo']);

    if (empty($name)) {
        return ['success' => false, 'message' => 'يرجى تحديد اسم قاعدة البيانات'];
    }

    try {
        // 1. Connect without selecting database
        $dsnNoDb = "mysql:host={$host};port={$port};charset=utf8mb4";
        $rawPdo = new PDO($dsnNoDb, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 4,
        ]);

        // 2. Create Database
        $rawPdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // 3. Connect to created Database
        $dsnWithDb = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsnWithDb, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 4,
        ]);

        // 4. Load & Execute Schema
        if (file_exists(CONFIG_FILE)) {
            require_once CONFIG_FILE;
            if (function_exists('initAllTables')) {
                initAllTables($pdo);
            }
        }

        // If schema wasn't fully initialized by initAllTables or as supplementary guarantee, execute database.sql if present
        if (file_exists(SQL_FILE)) {
            $sqlContent = file_get_contents(SQL_FILE);
            if (!empty($sqlContent)) {
                // Remove CREATE DATABASE / USE statements to avoid overriding custom DB name
                $sqlContent = preg_replace('/CREATE\s+DATABASE\s+[^;]+;/i', '', $sqlContent);
                $sqlContent = preg_replace('/USE\s+[^;]+;/i', '', $sqlContent);
                
                // Split queries safely
                $queries = array_filter(array_map('trim', explode(';', $sqlContent)));
                foreach ($queries as $q) {
                    if (!empty($q)) {
                        try {
                            $pdo->exec($q);
                        } catch (\PDOException $e) {
                            // Non-fatal if table already created
                        }
                    }
                }
            }
        }

        // 5. Seed Demo Data if requested
        if ($seedDemo && function_exists('seedDefaultData')) {
            seedDefaultData($pdo);
        }

        // 6. Create or Update Super Admin User
        $hashedPass = password_hash($adminPass, PASSWORD_BCRYPT);
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkStmt->execute([$adminEmail]);
        $existingAdminId = $checkStmt->fetchColumn();

        if ($existingAdminId) {
            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET full_name = ?, password_hash = ?, phone = ?, role = 'super_admin', status = 'active', wilaya = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$adminName, $hashedPass, $adminPhone, $adminWilaya, $existingAdminId]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO users (full_name, email, password_hash, phone, role, status, wilaya)
                VALUES (?, ?, ?, ?, 'super_admin', 'active', ?)
            ");
            $insertStmt->execute([$adminName, $adminEmail, $hashedPass, $adminPhone, $adminWilaya]);
        }

        // 7. Update config/db.php constants
        update_config_file($host, $port, $user, $pass, $name);

        // 8. Create install.lock file
        $lockPayload = json_encode([
            'installed_at' => date('Y-m-d H:i:s'),
            'installer_version' => INSTALLER_VERSION,
            'db_name' => $name,
            'admin_email' => $adminEmail,
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents(LOCK_FILE, $lockPayload);

        return [
            'success' => true,
            'message' => 'تم تثبيت منصة تاكسي نقيلو بنجاح وتهيئة قاعدة البيانات والمشرف العام.',
            'db_name' => $name,
            'admin_email' => $adminEmail,
            'admin_pass' => $adminPass,
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'حدث خطأ أثناء التثبيت: ' . $e->getMessage(),
        ];
    }
}

// -------------------------------------------------------------
// CLI Execution Handler
// -------------------------------------------------------------
if (php_sapi_name() === 'cli') {
    $args = $argv ?? [];
    $isExpress = in_array('--express', $args, true);
    $isForce = in_array('--force', $args, true);
    $isStatus = in_array('--status', $args, true);
    $isHelp = in_array('--help', $args, true) || in_array('-h', $args, true);

    echo "\n=======================================================\n";
    echo "  🚕 TAXI-NAGUILOU — AUTOMATED SYSTEM INSTALLER v" . INSTALLER_VERSION . "\n";
    echo "=======================================================\n\n";

    if ($isHelp) {
        echo "Usage:\n";
        echo "  php install.php [--express] [--force] [--status] [--help]\n\n";
        echo "Options:\n";
        echo "  --express   Run 1-click automated setup with local defaults\n";
        echo "  --force     Overwrite existing installation lock\n";
        echo "  --status    Display environment & system diagnostics\n";
        echo "  --help      Show this help information\n\n";
        exit(0);
    }

    if (file_exists(LOCK_FILE) && !$isForce) {
        echo "⚠️  WARNING: Installation is already completed and locked!\n";
        echo "Lock file found: " . LOCK_FILE . "\n";
        echo "To reinstall, re-run with --force or delete the install.lock file.\n\n";
        exit(1);
    }

    $diag = check_system_requirements();
    echo "1. Checking System Environment:\n";
    echo "  PHP Version: " . $diag['php']['version'] . " (" . ($diag['php']['pass'] ? "✅ OK" : "❌ FAIL") . ")\n";
    foreach ($diag['extensions'] as $ext => $info) {
        echo "  - Extension " . $info['name'] . ": " . ($info['loaded'] ? "✅ Loaded" : ($info['required'] ? "❌ Missing (Required)" : "⚠️ Missing (Optional)")) . "\n";
    }
    foreach ($diag['directories'] as $dir => $info) {
        echo "  - Directory " . $dir . ": " . ($info['writable'] ? "✅ Writable" : "❌ Not Writable") . "\n";
    }
    echo "\n";

    if ($isStatus) {
        exit($diag['overall_pass'] ? 0 : 1);
    }

    if (!$diag['overall_pass'] && !$isForce) {
        echo "❌ Critical system requirements failed. Aborting installation.\n\n";
        exit(1);
    }

    echo "2. Installing Database & Migrations...\n";
    $result = perform_installation([
        'db_host' => '127.0.0.1',
        'db_port' => '3306',
        'db_user' => 'root',
        'db_pass' => '',
        'db_name' => 'taxi_naguilou',
        'admin_name' => 'مدير المنصة - تاكسي نقيلو',
        'admin_email' => 'admin@taxinaguilou.dz',
        'admin_phone' => '0550000001',
        'admin_pass' => 'password123',
        'admin_wilaya' => '16 - الجزائر العاصمة',
        'seed_demo' => true,
    ]);

    if ($result['success']) {
        echo "✅ SUCCESS: " . $result['message'] . "\n\n";
        echo "Super Admin Credentials:\n";
        echo "  URL:      http://localhost/taxi-naguilou/auth/login.php\n";
        echo "  Email:    " . $result['admin_email'] . "\n";
        echo "  Password: " . $result['admin_pass'] . "\n\n";
        exit(0);
    } else {
        echo "❌ FAILED: " . $result['message'] . "\n\n";
        exit(1);
    }
}

// -------------------------------------------------------------
// Web AJAX API: Test Database Connection
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json; charset=utf-8');
    $host = trim($_POST['db_host'] ?? '127.0.0.1');
    $port = trim($_POST['db_port'] ?? '3306');
    $user = trim($_POST['db_user'] ?? 'root');
    $pass = $_POST['db_pass'] ?? '';
    $name = trim($_POST['db_name'] ?? 'taxi_naguilou');

    $res = test_db_connection($host, $port, $user, $pass, $name);
    echo json_encode($res);
    exit;
}

// -------------------------------------------------------------
// Web Action: Execute Installation Form POST
// -------------------------------------------------------------
$installResult = null;
$error = '';
$isLocked = file_exists(LOCK_FILE);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_install'])) {
    if ($isLocked && empty($_POST['force_reinstall'])) {
        $error = 'النظام مثبت ومقفل مسبقاً. إذا كنت ترغب في إعادة التثبيت، يرجى تفعيل خيار فرض إعادة التثبيت أو حذف ملف install.lock.';
    } else {
        $installResult = perform_installation($_POST);
        if (!$installResult['success']) {
            $error = $installResult['message'];
        } else {
            $isLocked = true;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['express_install'])) {
    if ($isLocked && empty($_POST['force_reinstall'])) {
        $error = 'النظام مثبت ومقفل مسبقاً.';
    } else {
        $installResult = perform_installation([
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_user' => 'root',
            'db_pass' => '',
            'db_name' => 'taxi_naguilou',
            'admin_name' => 'مدير المنصة - تاكسي نقيلو',
            'admin_email' => 'admin@taxinaguilou.dz',
            'admin_phone' => '0550000001',
            'admin_pass' => 'password123',
            'admin_wilaya' => '16 - الجزائر العاصمة',
            'seed_demo' => true,
        ]);
        if (!$installResult['success']) {
            $error = $installResult['message'];
        } else {
            $isLocked = true;
        }
    }
}

$diag = check_system_requirements();

// Resolve base URL for clean asset and navigation links
function installer_base_url(): string {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return rtrim($scriptDir, '/');
}
$baseUrl = installer_base_url();

$wilayas = [
    "01 - أدرار", "02 - الشلف", "03 - الأغواط", "04 - أم البواقي", "05 - باتنة",
    "06 - بجاية", "07 - بسكرة", "08 - بشار", "09 - البليدة", "10 - البويرة",
    "11 - تمنراست", "12 - تبسة", "13 - تلمسان", "14 - تيارت", "15 - تيزي وزو",
    "16 - الجزائر العاصمة", "17 - الجلفة", "18 - جيجل", "19 - سطيف", "20 - سعيدة",
    "21 - سكيكدة", "22 - سيدي بلعباس", "23 - عنابة", "24 - قالمة", "25 - قسنطينة",
    "26 - المدية", "27 - مستغانم", "28 - المسيلة", "29 - معسكر", "30 - ورقلة",
    "31 - وهران", "32 - البيض", "33 - إليزي", "34 - برج بوعريريج", "35 - بومرداس",
    "36 - الطارف", "37 - تندوف", "38 - تسمسيلت", "39 - الوادي", "40 - خنشلة",
    "41 - سوق أهراس", "42 - تيبازة", "43 - ميلة", "44 - عين الدفلى", "45 - النعامة",
    "46 - عين تموشنت", "47 - غرداية", "48 - غليزان", "49 - تيميمون", "50 - برج باجي مختار",
    "51 - أولاد جلال", "52 - بني عباس", "53 - عين صالح", "54 - عين قزام", "55 - تقرت",
    "56 - جانت", "57 - المغير", "58 - المنيعة"
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معالج تثبيت منصة تاكسي نقيلو | Setup Installer</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        amber: {
                            50: '#fffbeb',
                            100: '#fef3c7',
                            200: '#fde68a',
                            500: '#f59e0b',
                            600: '#d97706',
                            700: '#b45309',
                        }
                    },
                    fontFamily: {
                        sans: ['Cairo', 'sans-serif'],
                        num: ['Plus Jakarta Sans', 'Cairo', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style>
        body { font-family: 'Cairo', sans-serif; font-feature-settings: "tnum"; }
        ::selection { background-color: #fef3c7; color: #92400e; }
        .tab-step.active { border-color: #f59e0b; color: #b45309; font-weight: 700; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen py-8 px-4 flex flex-col justify-between">

    <div class="max-w-3xl mx-auto w-full space-y-6">

        <!-- Top Header & Brand -->
        <header class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <img src="<?= htmlspecialchars($baseUrl . '/logo.webp') ?>" alt="تاكسي نقيلو" 
                     class="w-14 h-14 object-contain rounded-full p-1 bg-amber-50 border-2 border-amber-300 shadow-sm"
                     onerror="this.style.display='none'">
                <div>
                    <h1 class="text-2xl font-black text-slate-900 leading-tight">
                        تاكسي <span class="text-amber-600">نقيلو</span>
                    </h1>
                    <p class="text-xs text-slate-500 font-bold">معالج التثبيت والتهيئة الآلية (Setup Wizard v<?= INSTALLER_VERSION ?>)</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="<?= htmlspecialchars($baseUrl . '/index.php') ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">
                    الصفحة الرئيسية
                </a>
                <a href="<?= htmlspecialchars($baseUrl . '/auth/login.php') ?>" class="px-3.5 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-bold transition shadow-sm">
                    تسجيل الدخول
                </a>
            </div>
        </header>

        <!-- System Notice if Locked -->
        <?php if ($isLocked && !$installResult): ?>
            <div class="bg-white rounded-2xl p-8 border border-emerald-200 shadow-sm text-center space-y-4">
                <div class="w-16 h-16 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center text-3xl mx-auto font-black">
                    ✓
                </div>
                <div>
                    <h2 class="text-xl font-black text-slate-900">المنصة مثبتة ومقفلة وجاهزة للعمل!</h2>
                    <p class="text-sm text-slate-500 mt-1">
                        تم العثور على ملف القفل <code class="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-xs">install.lock</code>. لحماية أمان قاعدة البيانات لا يمكن إعادة التثبيت بدون إذن.
                    </p>
                </div>

                <div class="p-4 bg-slate-50 rounded-xl border border-slate-100 text-xs text-slate-600 text-right space-y-1.5">
                    <div class="font-bold text-slate-800">بيانات الدخول الافتراضية للمدير:</div>
                    <div>• البريد: <strong class="font-mono text-slate-900">admin@taxinaguilou.dz</strong></div>
                    <div>• كلمة المرور: <strong class="font-mono text-slate-900">password123</strong></div>
                </div>

                <div class="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="<?= htmlspecialchars($baseUrl . '/auth/login.php') ?>" class="w-full sm:w-auto px-6 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold rounded-xl text-sm transition shadow-sm">
                        الانتقال إلى تسجيل الدخول ←
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl . '/dashboard/index.php') ?>" class="w-full sm:w-auto px-6 py-2.5 bg-slate-900 hover:bg-slate-800 text-white font-bold rounded-xl text-sm transition">
                        لوحة الإدارة الرئيسية
                    </a>
                </div>

                <div class="pt-4 border-t border-slate-100">
                    <details class="text-right">
                        <summary class="text-xs text-amber-700 font-bold cursor-pointer hover:underline">
                            هل ترغب في إعادة التثبيت أو تعديل الإعدادات؟ (خيارات متقدمة)
                        </summary>
                        <div class="mt-3 p-4 bg-amber-50 rounded-xl border border-amber-200 text-xs space-y-2">
                            <p class="text-slate-700">لإعادة التثبيت، يمكنك حذف ملف <code class="bg-white px-1 py-0.5 rounded border border-amber-200">install.lock</code> من المجلد الرئيسي، أو استخدام زر التثبيت القسري أدناه:</p>
                            <form method="POST" action="" onsubmit="return confirm('تحذير: سيتم إعادة تهيئة قاعدة البيانات. هل أنت متأكد؟');">
                                <input type="hidden" name="force_reinstall" value="1">
                                <button type="submit" name="express_install" value="1" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-lg transition shadow-sm">
                                    ⚠️ فرض إعادة التثبيت السريع الآن
                                </button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>

        <!-- Success Screen after Installation -->
        <?php elseif ($installResult && $installResult['success']): ?>
            <div class="bg-white rounded-2xl p-8 border border-emerald-300 shadow-sm text-center space-y-6">
                <div class="w-20 h-20 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center text-4xl mx-auto font-black shadow-inner">
                    ✓
                </div>
                <div>
                    <h2 class="text-2xl font-black text-slate-900">مبارك! تم تثبيت منصة تاكسي نقيلو بنجاح</h2>
                    <p class="text-sm text-emerald-700 font-bold mt-1">
                        قاعدة البيانات جاهزة، الجداول مهيأة، وحساب المشرف العام نشط.
                    </p>
                </div>

                <div class="max-w-md mx-auto bg-slate-50 p-5 rounded-2xl border border-slate-200 text-right space-y-3">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200 pb-2">
                        معلومات الدخول لحساب المشرف العام:
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">اسم قاعدة البيانات:</span>
                        <span class="font-mono font-bold text-slate-900"><?= htmlspecialchars($installResult['db_name']) ?></span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">البريد الإلكتروني:</span>
                        <span class="font-mono font-bold text-amber-700"><?= htmlspecialchars($installResult['admin_email']) ?></span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">كلمة المرور:</span>
                        <span class="font-mono font-bold text-slate-900"><?= htmlspecialchars($installResult['admin_pass']) ?></span>
                    </div>
                    <div class="text-[11px] text-slate-400 pt-2 border-t border-slate-200">
                        🔒 تم إنشاء ملف الحماية <span class="font-mono text-slate-600">install.lock</span> تلقائياً لمنع أي تلاعب غير مصرح به.
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
                    <a href="<?= htmlspecialchars($baseUrl . '/auth/login.php') ?>" class="w-full sm:w-auto px-8 py-3 bg-amber-500 hover:bg-amber-600 text-slate-950 font-black rounded-xl text-sm transition shadow">
                        الدخول إلى لوحة التحكم الآن ←
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl . '/index.php') ?>" class="w-full sm:w-auto px-6 py-3 border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold rounded-xl text-sm transition">
                        معاينة الموقع الرئيسي
                    </a>
                </div>
            </div>

        <!-- Installation Wizard -->
        <?php else: ?>

            <?php if (!empty($error)): ?>
                <div class="p-4 bg-rose-50 border border-rose-300 text-rose-800 text-sm font-bold rounded-xl flex items-center gap-3">
                    <span class="text-lg">❌</span>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- 1-Click Express Install Callout -->
            <div class="bg-gradient-to-r from-amber-500 to-amber-600 rounded-2xl p-5 text-slate-950 shadow-md flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="space-y-1 text-center md:text-right">
                    <div class="flex items-center justify-center md:justify-start gap-2">
                        <span class="px-2 py-0.5 bg-slate-950 text-amber-400 text-[10px] font-black rounded-full uppercase">سريع وفوري</span>
                        <h3 class="font-black text-base text-slate-950">التثبيت السريع بنقرة واحدة (Laragon / Localhost)</h3>
                    </div>
                    <p class="text-xs text-amber-950 font-medium">
                        تثبيت مباشر وسريع بالإعدادات الافتراضية وقاعدة البيانات المحلية وحساب المشرف بضغطة زر واحدة.
                    </p>
                </div>
                <form method="POST" action="" class="shrink-0">
                    <input type="hidden" name="express_install" value="1">
                    <button type="submit" class="px-5 py-2.5 bg-slate-950 hover:bg-slate-900 text-amber-400 font-black text-xs rounded-xl transition shadow flex items-center gap-2">
                        <span>⚡ تثبيت سريع فوري الآن</span>
                        <span>←</span>
                    </button>
                </form>
            </div>

            <!-- Custom Wizard Form Container -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                
                <!-- Wizard Steps Bar -->
                <div class="border-b border-slate-100 bg-slate-50/50 p-3 sm:p-4 grid grid-cols-3 gap-2 text-center text-xs">
                    <div id="step-btn-1" class="tab-step active flex items-center justify-center gap-1.5 pb-1 border-b-2 border-amber-500 text-amber-700">
                        <span class="w-5 h-5 rounded-full bg-amber-500 text-slate-950 text-[11px] font-bold flex items-center justify-center">1</span>
                        <span>فحص المتطلبات</span>
                    </div>
                    <div id="step-btn-2" class="tab-step flex items-center justify-center gap-1.5 pb-1 border-b-2 border-transparent text-slate-400">
                        <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-600 text-[11px] font-bold flex items-center justify-center">2</span>
                        <span>قاعدة البيانات</span>
                    </div>
                    <div id="step-btn-3" class="tab-step flex items-center justify-center gap-1.5 pb-1 border-b-2 border-transparent text-slate-400">
                        <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-600 text-[11px] font-bold flex items-center justify-center">3</span>
                        <span>المشرف والتثبيت</span>
                    </div>
                </div>

                <form method="POST" action="" id="installForm" class="p-6 sm:p-8 space-y-6">
                    <input type="hidden" name="do_install" value="1">

                    <!-- STEP 1: Environment & Diagnostics -->
                    <div id="step-1" class="space-y-5">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                            <div>
                                <h3 class="text-base font-black text-slate-900">1. فحص توافق البيئة ومتطلبات الخادم</h3>
                                <p class="text-xs text-slate-500">التأكد من توفر إصدار PHP والإضافات وصلاحيات المجلدات اللازمة</p>
                            </div>
                            <span class="text-xs px-2.5 py-1 rounded-full font-bold <?= $diag['overall_pass'] ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                <?= $diag['overall_pass'] ? '✓ البيئة متوافقة تماماً' : '⚠️ بعض المتطلبات بحاجة للانتباه' ?>
                            </span>
                        </div>

                        <!-- PHP Version Check -->
                        <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                            <span class="font-bold text-slate-700">إصدار PHP (الحد الأدنى 7.4.0):</span>
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-slate-900">PHP <?= htmlspecialchars($diag['php']['version']) ?></span>
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold <?= $diag['php']['pass'] ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                    <?= $diag['php']['pass'] ? 'متوافق' : 'غير متوافق' ?>
                                </span>
                            </div>
                        </div>

                        <!-- Extensions Check -->
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-slate-700">إضافات ومكتبات PHP المطلوبة:</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <?php foreach ($diag['extensions'] as $ext => $item): ?>
                                    <div class="p-2.5 rounded-lg border border-slate-100 bg-slate-50 flex items-center justify-between text-xs">
                                        <span class="font-medium text-slate-700"><?= htmlspecialchars($item['name']) ?></span>
                                        <?php if ($item['loaded']): ?>
                                            <span class="text-emerald-700 font-bold flex items-center gap-1">✓ متوفرة</span>
                                        <?php elseif ($item['required']): ?>
                                            <span class="text-rose-700 font-bold flex items-center gap-1">❌ غير مفعلة (إلزامية)</span>
                                        <?php else: ?>
                                            <span class="text-amber-700 font-bold flex items-center gap-1">⚠️ موصى بها</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Directories Check -->
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-slate-700">مجلدات التخزين وصلاحيات الكتابة:</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <?php foreach ($diag['directories'] as $dirKey => $dir): ?>
                                    <div class="p-2.5 rounded-lg border border-slate-100 bg-slate-50 flex items-center justify-between text-xs">
                                        <span class="font-mono text-slate-700"><?= htmlspecialchars($dirKey) ?>/</span>
                                        <span class="font-bold <?= $dir['writable'] ? 'text-emerald-700' : 'text-rose-700' ?>">
                                            <?= $dir['writable'] ? '✓ قابل للكتابة' : '❌ للقراءة فقط' ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="pt-4 flex justify-end">
                            <button type="button" onclick="goToStep(2)" class="px-6 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs rounded-xl transition shadow flex items-center gap-2">
                                <span>المتابعة إلى إعداد قاعدة البيانات</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 2: Database Settings & Live Test -->
                    <div id="step-2" class="space-y-5 hidden">
                        <div class="border-b border-slate-100 pb-3">
                            <h3 class="text-base font-black text-slate-900">2. إعدادات الاتصال بقاعدة البيانات MySQL</h3>
                            <p class="text-xs text-slate-500">أدخل معلومات خادم MySQL المحلي أو السحابي</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="db_host" class="block text-xs font-bold text-slate-700 mb-1">خادم قاعدة البيانات (Host)</label>
                                <input type="text" id="db_host" name="db_host" value="127.0.0.1" required dir="ltr"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right font-mono focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            </div>

                            <div>
                                <label for="db_port" class="block text-xs font-bold text-slate-700 mb-1">المنفذ (Port)</label>
                                <input type="text" id="db_port" name="db_port" value="3306" required dir="ltr"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right font-mono focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            </div>

                            <div>
                                <label for="db_user" class="block text-xs font-bold text-slate-700 mb-1">اسم المستخدم (User)</label>
                                <input type="text" id="db_user" name="db_user" value="root" required dir="ltr"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right font-mono focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            </div>

                            <div>
                                <label for="db_pass" class="block text-xs font-bold text-slate-700 mb-1">كلمة المرور (Password)</label>
                                <input type="password" id="db_pass" name="db_pass" value="" placeholder="فارغة في Laragon/XAMPP" dir="ltr"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right font-mono focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            </div>

                            <div class="sm:col-span-2">
                                <label for="db_name" class="block text-xs font-bold text-slate-700 mb-1">اسم قاعدة البيانات (Database Name)</label>
                                <input type="text" id="db_name" name="db_name" value="taxi_naguilou" required dir="ltr"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right font-mono focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                                <span class="text-[11px] text-slate-400 mt-1 block">سيتم إنشاء قاعدة البيانات تلقائياً إذا لم تكن موجودة.</span>
                            </div>
                        </div>

                        <!-- Live Test Box -->
                        <div class="p-4 bg-slate-50 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-bold text-slate-800">اختبار الاتصال المباشر:</div>
                                <div id="db-test-feedback" class="text-xs text-slate-500 mt-0.5">اضغط على الزر للتحقق من الاتصال بخادم MySQL قبل المتابعة.</div>
                            </div>
                            <button type="button" onclick="testConnection()" id="test-btn"
                                    class="shrink-0 px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs transition flex items-center gap-1.5">
                                <span>🔌 فحص الاتصال</span>
                            </button>
                        </div>

                        <div class="pt-4 flex items-center justify-between">
                            <button type="button" onclick="goToStep(1)" class="px-5 py-2.5 border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs rounded-xl transition">
                                → السابق
                            </button>
                            <button type="button" onclick="goToStep(3)" class="px-6 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs rounded-xl transition shadow flex items-center gap-2">
                                <span>المتابعة إلى بيانات المشرف</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 3: Super Admin & Options -->
                    <div id="step-3" class="space-y-5 hidden">
                        <div class="border-b border-slate-100 pb-3">
                            <h3 class="text-base font-black text-slate-900">3. تهيئة حساب المشرف العام وخيارات المنصة</h3>
                            <p class="text-xs text-slate-500">سيتم منح هذا الحساب كافة صلاحيات إدارة الأسطول والعقود والسائقين</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="admin_name" class="block text-xs font-bold text-slate-700 mb-1">الاسم الكامل للمشرف</label>
                                <input type="text" id="admin_name" name="admin_name" value="مدير المنصة - تاكسي نقيلو" required
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            </div>

                            <div>
                                <label for="admin_email" class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني للولوج</label>
                                <input type="email" id="admin_email" name="admin_email" value="admin@taxinaguilou.dz" required dir="ltr"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            </div>

                            <div>
                                <label for="admin_phone" class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                                <input type="text" id="admin_phone" name="admin_phone" value="0550000001" required dir="ltr"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                            </div>

                            <div>
                                <label for="admin_wilaya" class="block text-xs font-bold text-slate-700 mb-1">الولاية (المقر)</label>
                                <select id="admin_wilaya" name="admin_wilaya" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                                    <?php foreach ($wilayas as $w): ?>
                                        <option value="<?= htmlspecialchars($w) ?>" <?= strpos($w, '16') !== false ? 'selected' : '' ?>><?= htmlspecialchars($w) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="admin_pass" class="block text-xs font-bold text-slate-700 mb-1">كلمة المرور الرئيسية</label>
                                <div class="relative">
                                    <input type="text" id="admin_pass" name="admin_pass" value="password123" required dir="ltr"
                                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-right font-mono focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                                </div>
                                <span class="text-[11px] text-slate-400 mt-1 block">يمكنك استخدام كلمة المرور الافتراضية <code class="font-mono text-slate-700 font-bold">password123</code> أو تخصيصها.</span>
                            </div>
                        </div>

                        <!-- Additional Options -->
                        <div class="p-4 bg-slate-50 rounded-xl border border-slate-200 space-y-3">
                            <div class="text-xs font-bold text-slate-800">خيارات البيانات الأولية:</div>
                            <label class="flex items-start gap-2.5 cursor-pointer">
                                <input type="checkbox" name="seed_demo" value="1" checked class="mt-0.5 rounded text-amber-500 focus:ring-amber-500">
                                <div class="text-xs">
                                    <strong class="text-slate-800 block">تضمين البيانات التجريبية الأولية (موصى به للتجربة)</strong>
                                    <span class="text-slate-500">يضيف سيارات جاهزة، سائقين، عقود عمل نموذجية مطابقة للقانون 90-11، جداول إرسال، وديون تجريبية لاختبار المنصة فوراً.</span>
                                </div>
                            </label>
                        </div>

                        <div class="pt-4 flex items-center justify-between">
                            <button type="button" onclick="goToStep(2)" class="px-5 py-2.5 border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs rounded-xl transition">
                                → السابق
                            </button>
                            <button type="submit" id="submitBtn" class="px-8 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm rounded-xl transition shadow-md flex items-center gap-2">
                                <span>🚀 بدء التثبيت النهائي وتوليد النظام</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                </form>

            </div>

        <?php endif; ?>

        <!-- Footer -->
        <footer class="text-center text-xs text-slate-400 pt-4 space-y-1">
            <div>منصة تاكسي نقيلو لإدارة سيارات ومكاتب الأجرة في الجزائر © <?= date('Y') ?></div>
            <div class="text-[11px] text-slate-400">مطابقة لقانون العمل الجزائري 90-11 والقرارات الوزارية لنقل المسافرين.</div>
        </footer>

    </div>

    <!-- Interactive Step Switching & AJAX Script -->
    <script>
        function goToStep(step) {
            document.querySelectorAll('[id^="step-"]').forEach(el => {
                if (el.id.startsWith('step-') && !el.id.startsWith('step-btn')) {
                    el.classList.add('hidden');
                }
            });
            const activeSection = document.getElementById('step-' + step);
            if (activeSection) {
                activeSection.classList.remove('hidden');
            }

            for (let i = 1; i <= 3; i++) {
                const btn = document.getElementById('step-btn-' + i);
                if (btn) {
                    if (i === step) {
                        btn.className = 'tab-step active flex items-center justify-center gap-1.5 pb-1 border-b-2 border-amber-500 text-amber-700 font-bold';
                        const badge = btn.querySelector('span:first-child');
                        if (badge) badge.className = 'w-5 h-5 rounded-full bg-amber-500 text-slate-950 text-[11px] font-bold flex items-center justify-center';
                    } else if (i < step) {
                        btn.className = 'tab-step flex items-center justify-center gap-1.5 pb-1 border-b-2 border-emerald-500 text-emerald-700';
                        const badge = btn.querySelector('span:first-child');
                        if (badge) badge.className = 'w-5 h-5 rounded-full bg-emerald-500 text-white text-[11px] font-bold flex items-center justify-center';
                    } else {
                        btn.className = 'tab-step flex items-center justify-center gap-1.5 pb-1 border-b-2 border-transparent text-slate-400';
                        const badge = btn.querySelector('span:first-child');
                        if (badge) badge.className = 'w-5 h-5 rounded-full bg-slate-200 text-slate-600 text-[11px] font-bold flex items-center justify-center';
                    }
                }
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        async function testConnection() {
            const btn = document.getElementById('test-btn');
            const feedback = document.getElementById('db-test-feedback');
            
            const host = document.getElementById('db_host').value;
            const port = document.getElementById('db_port').value;
            const user = document.getElementById('db_user').value;
            const pass = document.getElementById('db_pass').value;
            const name = document.getElementById('db_name').value;

            btn.disabled = true;
            btn.innerHTML = '<span>⏳ جاري الفحص...</span>';
            feedback.className = 'text-xs text-amber-600 mt-0.5 font-bold';
            feedback.innerText = 'جاري محاولة الاتصال بخادم MySQL...';

            try {
                const formData = new FormData();
                formData.append('db_host', host);
                formData.append('db_port', port);
                formData.append('db_user', user);
                formData.append('db_pass', pass);
                formData.append('db_name', name);

                const res = await fetch('install.php?action=test_db', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    feedback.className = 'text-xs text-emerald-700 mt-0.5 font-bold';
                    feedback.innerText = '✓ ' + data.message + (data.db_exists ? ' (قاعدة البيانات موجودة)' : ' (سيتم إنشاؤها تلقائياً)');
                } else {
                    feedback.className = 'text-xs text-rose-700 mt-0.5 font-bold';
                    feedback.innerText = '✕ ' + data.message;
                }
            } catch (err) {
                feedback.className = 'text-xs text-rose-700 mt-0.5 font-bold';
                feedback.innerText = 'تعذر الاتصال بالخادم: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>🔌 فحص الاتصال</span>';
            }
        }
    </script>
</body>
</html>
