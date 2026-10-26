<?php
// dashboard/planning.php - Gestion de la Garde de Nuit & Planning Officiel des Permanences
$pageTitle = 'قائمة المناوبة الليلية (Permanence) | حراسة الحظيرة والمداومة';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();

// Fetch settings for company headers
$settings = [];
if ($pdo) {
    try {
        $stmtSettings = $pdo->query("SELECT `key`, `value` FROM settings");
        $settings = $stmtSettings ? $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    } catch (\Throwable $e) { $settings = []; }
}

$companyInfo = [
    'name'     => $settings['company_name'] ?? 'تاكسي نقيلو (TAXI NAGUILOU)',
    'rc'       => !empty($settings['tax_rc']) ? $settings['tax_rc'] : (!empty($settings['legal_rc']) ? $settings['legal_rc'] : '0000'),
    'nif'      => !empty($settings['tax_nif']) ? $settings['tax_nif'] : (!empty($settings['legal_nif']) ? $settings['legal_nif'] : '0000'),
    'nis'      => !empty($settings['tax_nis']) ? $settings['tax_nis'] : (!empty($settings['legal_nis']) ? $settings['legal_nis'] : '0000'),
    'art'      => !empty($settings['tax_article']) ? $settings['tax_article'] : (!empty($settings['legal_article']) ? $settings['legal_article'] : '0000'),
    'phone'    => '0663.55.00.42',
    'email'    => 'naguiloutaxi@gmail.com',
    'address'  => 'أولاد احمد / تيمي / ادرار',
    'agrement' => '02/22',
    'wilaya'   => 'أدرار',
];

// Fetch active drivers and vehicles
$drivers = [];
$vehicles = [];
if ($pdo) {
    try {
        $stmtD = $pdo->query("
            SELECT u.id, u.full_name, u.phone, u.license_number,
                   v.id as vehicle_id, v.brand, v.model, v.plate_number, v.door_number
            FROM users u
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id AND v.deleted_at IS NULL)
            WHERE u.role = 'driver' AND u.status = 'active' AND u.deleted_at IS NULL
            ORDER BY u.full_name ASC
        ");
        $drivers = $stmtD ? $stmtD->fetchAll() : [];
    } catch (\Throwable $e) { $drivers = []; }

    try {
        $stmtV = $pdo->query("
            SELECT id, brand, model, plate_number, door_number 
            FROM vehicles 
            WHERE deleted_at IS NULL
            ORDER BY door_number ASC, id ASC
        ");
        $vehicles = $stmtV ? $stmtV->fetchAll() : [];
    } catch (\Throwable $e) { $vehicles = []; }
}

// Active Tab ('generator', 'archive', or 'fleet_watch')
$activeTab = $_GET['tab'] ?? 'generator';

// Default 10 Rows Configuration for Night Shift
$defaultAgrementBase = $companyInfo['agrement']; // e.g. 02/22
$defaultItems = [];
for ($i = 1; $i <= 10; $i++) {
    $numStr = str_pad($i, 2, '0', STR_PAD_LEFT);
    $defaultItems[] = [
        'num'          => $numStr,
        'driver_id'    => '',
        'driver_name'  => '',
        'agrement_num' => "($defaultAgrementBase)-$numStr",
        'shift_date'   => date('Y-m-d')
    ];
}

// Handle Save Permanence List
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_permanence_list') {
    require_csrf_token();
    if (is_driver()) {
        set_flash('error', 'عذراً، لا تملك صلاحية حفظ أو إنشاء قوائم المناوبة الليلية.');
        redirect('/dashboard/planning.php?tab=archive');
        exit;
    }

    $listId = intval($_POST['list_id'] ?? 0);
    $title = trim($_POST['title'] ?? 'قائمة المناوبة الليلية');
    $subTitle = trim($_POST['sub_title'] ?? 'بالمؤسسة الاستشفائية بأدرار (عبد الكريم بودرغومة)');
    $wilaya = trim($_POST['wilaya'] ?? 'أدرار');
    $periodMonth = trim($_POST['period_month'] ?? '');
    $shiftDate = !empty($_POST['global_shift_date']) ? $_POST['global_shift_date'] : date('Y-m-d');

    $docNums = $_POST['item_num'] ?? [];
    $docDriverIds = $_POST['item_driver_id'] ?? [];
    $docDriverNames = $_POST['item_driver_name'] ?? [];
    $docAgrements = $_POST['item_agrement'] ?? [];
    $docDates = $_POST['item_date'] ?? [];

    $items = [];
    if (is_array($docDriverNames)) {
        for ($i = 0; $i < count($docDriverNames); $i++) {
            $dName = trim($docDriverNames[$i] ?? '');
            $items[] = [
                'num'          => str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'driver_id'    => intval($docDriverIds[$i] ?? 0),
                'driver_name'  => $dName,
                'agrement_num' => trim($docAgrements[$i] ?? "($defaultAgrementBase)-" . str_pad($i + 1, 2, '0', STR_PAD_LEFT)),
                'shift_date'   => !empty($docDates[$i]) ? $docDates[$i] : $shiftDate
            ];
        }
    }
    if (empty($items)) {
        $items = $defaultItems;
    }
    $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

    try {
        if ($listId > 0) {
            $stmt = $pdo->prepare("
                UPDATE permanence_lists 
                SET title = ?, sub_title = ?, wilaya = ?, period_month = ?, shift_date = ?, items_json = ?
                WHERE id = ?
            ");
            $stmt->execute([$title, $subTitle, $wilaya, $periodMonth, $shiftDate, $itemsJson, $listId]);
            log_audit($pdo, 'permanence_list_updated', 'permanence_lists', $listId, "تعديل وحفظ قائمة المناوبة الليلية ($title)");
            set_flash('success', "تم تعديل وحفظ قائمة المناوبة الليلية بنجاح في الأرشيف");
            redirect("/dashboard/planning.php?tab=generator&list_id=$listId");
            exit;
        } else {
            $currYear = date('Y', strtotime($shiftDate));
            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM permanence_lists")->fetchColumn();
            $listNum = sprintf("PERM-%s-%04d", $currYear, $maxId + 1);

            $stmt = $pdo->prepare("
                INSERT INTO permanence_lists 
                (list_number, title, sub_title, wilaya, period_month, shift_date, items_json)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$listNum, $title, $subTitle, $wilaya, $periodMonth, $shiftDate, $itemsJson]);
            $newId = (int)$pdo->lastInsertId();
            log_audit($pdo, 'permanence_list_created', 'permanence_lists', $newId, "إنشاء وحفظ قائمة المناوبة الليلية رقم $listNum");
            set_flash('success', "تم حفظ وأرشفة قائمة المناوبة الليلية بنجاح! الوثيقة جاهزة للطباعة أدناه.");
            redirect("/dashboard/planning.php?tab=generator&list_id=$newId");
            exit;
        }
    } catch (\Throwable $e) {
        set_flash('error', 'حدث خطأ أثناء حفظ قائمة المناوبة: ' . $e->getMessage());
    }
}

// Handle Delete Permanence List
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_permanence_list') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف قوائم المناوبة.');
        redirect('/dashboard/planning.php?tab=archive');
        exit;
    }
    $delId = intval($_POST['list_id'] ?? 0);
    if ($delId > 0) {
        try {
            $pdo->prepare("UPDATE permanence_lists SET deleted_at = NOW() WHERE id = ?")->execute([$delId]);
            log_audit($pdo, 'permanence_list_deleted', 'permanence_lists', $delId, "حذف قائمة المناوبة الليلية رقم $delId");
            set_flash('success', "تم نقل قائمة المناوبة بنجاح إلى سلة المهملات والأرشيف.");
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر حذف القائمة: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/planning.php?tab=archive');
    exit;
}

// Handle Night Watch Parking Entry & Exit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_night_watch') {
    require_csrf_token();
    $vId = intval($_POST['vehicle_id'] ?? 0);
    $guardian = trim($_POST['guardian_name'] ?? 'حارس الحظيرة');
    $slot = trim($_POST['park_slot'] ?? 'Zone A');
    $notes = trim($_POST['notes'] ?? '');

    if ($vId > 0 && $pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO night_watch (vehicle_id, guardian_name, park_slot, entry_time, status, notes)
                VALUES (?, ?, ?, NOW(), 'in_park', ?)
            ");
            $stmt->execute([$vId, $guardian, $slot, $notes]);
            $nwId = (int)$pdo->lastInsertId();
            log_audit($pdo, 'night_watch_entry', 'night_watch', $nwId, "إيواء المركبة $vId بالحراسة الليلية");
            set_flash('success', 'تم تسجيل إيواء المركبة في حراسة الحظيرة بنجاح');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر تسجيل الإيواء: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/planning.php?tab=fleet_watch');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'exit_park') {
    require_csrf_token();
    $nwId = intval($_POST['night_watch_id'] ?? 0);
    if ($nwId > 0 && $pdo) {
        try {
            $pdo->prepare("UPDATE night_watch SET status = 'sorti', exit_time = NOW() WHERE id = ?")->execute([$nwId]);
            log_audit($pdo, 'night_watch_exit', 'night_watch', $nwId, "تسجيل خروج المركبة من الحظيرة للخدمة");
            set_flash('success', 'تم تسجيل خروج سيارة الأجرة من الحظيرة للخدمة الميدانية');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر تسجيل الخروج: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/planning.php?tab=fleet_watch');
    exit;
}

// Load Active Permanence List if editing/viewing
$activeList = null;
if (isset($_GET['list_id']) && $pdo) {
    try {
        $stmtL = $pdo->prepare("SELECT * FROM permanence_lists WHERE id = ? AND deleted_at IS NULL");
        $stmtL->execute([intval($_GET['list_id'])]);
        $activeList = $stmtL->fetch();
    } catch (\Throwable $e) {
        $activeList = null;
    }
}

// Active Form Fields
$currentTitle = $activeList['title'] ?? 'قائمة المناوبة الليلية';
$currentSubTitle = $activeList['sub_title'] ?? 'بالمؤسسة الاستشفائية بأدرار (عبد الكريم بودرغومة)';
$currentWilaya = $activeList['wilaya'] ?? 'أدرار';
$currentPeriod = $activeList['period_month'] ?? 'أكتوبر 2026';
$currentShiftDate = $activeList['shift_date'] ?? date('Y-m-d');

$activeItems = $defaultItems;
if ($activeList && !empty($activeList['items_json'])) {
    $decoded = json_decode($activeList['items_json'], true);
    if (is_array($decoded) && !empty($decoded)) {
        $activeItems = $decoded;
    }
}

// Fetch Archive of Lists
$archiveLists = [];
if ($pdo) {
    try {
        $archiveLists = $pdo->query("SELECT * FROM permanence_lists WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll() ?: [];
    } catch (\Throwable $e) {
        $archiveLists = [];
    }
}

// Fetch Night Watch Vehicles currently in park
$nightWatch = [];
if ($pdo) {
    try {
        $stmtNW = $pdo->query("
            SELECT nw.*, v.brand, v.model, v.plate_number, v.door_number
            FROM night_watch nw
            JOIN vehicles v ON nw.vehicle_id = v.id
            WHERE nw.status = 'in_park'
            ORDER BY nw.entry_time DESC
        ");
        $nightWatch = $stmtNW ? $stmtNW->fetchAll() : [];
    } catch (\Throwable $e) { $nightWatch = []; }
}
?>

<div class="space-y-6">

    <!-- Screen Header (Hidden on Print) -->
    <div class="no-print flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-lg shadow-2xs">
                    🌙
                </div>
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">قائمة المناوبة الليلية (Permanence)</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">إعداد وطباعة جدول المداومة الليلية بالمؤسسة الاستشفائية بأدرار</p>
                </div>
            </div>
        </div>

        <!-- Segmented Tab Navigation -->
        <div class="flex items-center bg-slate-100 dark:bg-[#161d27] p-1 rounded-2xl border border-slate-200 dark:border-slate-800 text-xs font-bold">
            <?php if (!is_driver()): ?>
            <a href="planning.php?tab=generator" 
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl transition <?= $activeTab === 'generator' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>إنشاء مستند جديد</span>
            </a>
            <?php endif; ?>

            <a href="planning.php?tab=archive" 
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl transition <?= $activeTab === 'archive' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                <svg class="w-4 h-4 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                </svg>
                <span>أرشيف المناوبة</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-num"><?= count($archiveLists) ?></span>
            </a>

            <a href="planning.php?tab=fleet_watch" 
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl transition <?= $activeTab === 'fleet_watch' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
                <span>حراسة الحظيرة</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] bg-indigo-100 dark:bg-indigo-950 text-indigo-800 dark:text-indigo-300 font-num"><?= count($nightWatch) ?></span>
            </a>
        </div>
    </div>

    <?php if ($activeTab === 'generator'): ?>
    <!-- TAB 1: GENERATEUR & LIVE PRINT PREVIEW -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">

        <!-- Form Column (No Print) -->
        <div class="no-print xl:col-span-5 space-y-5">

            <form id="planningForm" method="POST" action="" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_permanence_list">
                <input type="hidden" name="list_id" value="<?= $activeList['id'] ?? 0 ?>">

                <!-- Card 1: معلومات الجدول -->
                <div class="bg-white dark:bg-[#161d27] rounded-3xl p-5 sm:p-6 border border-slate-200 dark:border-slate-800 shadow-xs space-y-4 text-xs">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <h3 class="font-black text-slate-900 dark:text-white text-sm">معلومات الجدول</h3>
                        </div>
                        <button type="button" onclick="resetToDefaults()" 
                                class="px-2.5 py-1 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:text-amber-600 font-bold text-[10px] border border-slate-200 dark:border-slate-700 transition cursor-pointer">
                            الافتراضي
                        </button>
                    </div>

                    <!-- Main Document Title -->
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">العنوان الرئيسي للوثيقة</label>
                        <input type="text" id="inputMainTitle" name="title" value="<?= htmlspecialchars($currentTitle) ?>" oninput="syncPlanningPreview()"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-black text-sm focus:border-amber-500 transition">
                    </div>

                    <!-- Subtitle / Establishment -->
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">المؤسسة / العنوان الفرعي</label>
                        <input type="text" id="inputSubTitle" name="sub_title" value="<?= htmlspecialchars($currentSubTitle) ?>" oninput="syncPlanningPreview()"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500 transition">
                    </div>

                    <!-- Wilaya & Period & Global Date -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الولاية / الموقع</label>
                            <input type="text" id="inputWilaya" name="wilaya" value="<?= htmlspecialchars($currentWilaya) ?>" oninput="syncPlanningPreview()"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500 transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الفترة / الشهر</label>
                            <input type="text" id="inputPeriod" name="period_month" value="<?= htmlspecialchars($currentPeriod) ?>" placeholder="مثال: مارس 2026" oninput="syncPlanningPreview()"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500 transition">
                        </div>
                    </div>

                    <!-- Global Shift Date -->
                    <div class="p-3 bg-amber-500/5 dark:bg-amber-500/10 rounded-2xl border border-amber-500/20 space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block font-black text-amber-900 dark:text-amber-400 text-xs">تاريخ المداومة (سيطبق على الكل)</label>
                            <button type="button" onclick="applyDateToAll()" 
                                    class="text-[10px] font-bold text-amber-700 dark:text-amber-400 hover:underline cursor-pointer">
                                تطبيق فوري على الكل ✓
                            </button>
                        </div>
                        <input type="date" id="inputGlobalDate" name="global_shift_date" value="<?= htmlspecialchars($currentShiftDate) ?>" onchange="applyDateToAll()"
                               class="w-full px-3 py-2 rounded-xl border border-amber-300 dark:border-amber-600/50 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold focus:border-amber-500 transition">
                    </div>
                </div>

                <!-- Card 2: جدول المناوبة -->
                <div class="bg-white dark:bg-[#161d27] rounded-3xl p-5 sm:p-6 border border-slate-200 dark:border-slate-800 shadow-xs space-y-4 text-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                            <h3 class="font-black text-slate-900 dark:text-white text-sm">جدول المناوبة</h3>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="autoFillDrivers()" 
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-400 font-bold border border-amber-500/20 transition cursor-pointer" title="تعبئة تلقائية للسائقين من قاعدة البيانات">
                                <span>⚡ تعبئة تلقائية</span>
                            </button>
                            <button type="button" onclick="addNewDriverRow()" 
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400 font-bold border border-emerald-500/20 transition cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                                <span>إضافة سائق مناوب</span>
                            </button>
                        </div>
                    </div>

                    <!-- Shift Rows Container -->
                    <div id="shiftRowsContainer" class="space-y-3">
                        <?php foreach ($activeItems as $idx => $item): ?>
                            <div class="shift-row p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 space-y-2 transition">
                                <div class="flex items-center justify-between">
                                    <span class="shift-num-badge px-2.5 py-0.5 rounded-lg bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-mono font-black text-xs">
                                        <?= htmlspecialchars($item['num'] ?? str_pad($idx + 1, 2, '0', STR_PAD_LEFT)) ?>
                                    </span>
                                    <input type="hidden" name="item_num[]" value="<?= htmlspecialchars($item['num'] ?? str_pad($idx + 1, 2, '0', STR_PAD_LEFT)) ?>" class="item-num-hidden">
                                    <input type="hidden" name="item_driver_id[]" value="<?= htmlspecialchars($item['driver_id'] ?? '') ?>" class="item-driver-id-hidden">

                                    <button type="button" onclick="removeShiftRow(this)" class="text-slate-400 hover:text-rose-600 transition cursor-pointer px-1" title="حذف السطر">
                                        ✕
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
                                    <!-- Driver Platform Selector & Text Input -->
                                    <div class="sm:col-span-5 space-y-1">
                                        <select onchange="onRowDriverSelect(this)" 
                                                class="w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold text-[11px] focus:border-amber-500">
                                            <option value="">اختر سائق...▼</option>
                                            <?php foreach ($drivers as $d): ?>
                                                <option value="<?= $d['id'] ?>" 
                                                        data-name="<?= htmlspecialchars($d['full_name']) ?>"
                                                        <?= ($item['driver_id'] == $d['id'] || $item['driver_name'] === $d['full_name']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($d['full_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="item_driver_name[]" value="<?= htmlspecialchars($item['driver_name']) ?>" placeholder="اسم السائق" oninput="syncPlanningPreview()"
                                               class="item-driver-name-input w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold text-xs focus:border-amber-500">
                                    </div>

                                    <!-- Agrement Number (e.g. (02/22)-01) -->
                                    <div class="sm:col-span-3">
                                        <label class="block text-[10px] text-slate-400 mb-0.5 font-bold">رقم الاعتماد</label>
                                        <input type="text" name="item_agrement[]" value="<?= htmlspecialchars($item['agrement_num'] ?? "($defaultAgrementBase)-" . str_pad($idx + 1, 2, '0', STR_PAD_LEFT)) ?>" oninput="syncPlanningPreview()" dir="ltr"
                                               class="item-agrement-input w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold text-center text-xs focus:border-amber-500">
                                    </div>

                                    <!-- Shift Date -->
                                    <div class="sm:col-span-4">
                                        <label class="block text-[10px] text-slate-400 mb-0.5 font-bold">التاريخ</label>
                                        <input type="date" name="item_date[]" value="<?= htmlspecialchars($item['shift_date'] ?? $currentShiftDate) ?>" oninput="syncPlanningPreview()"
                                               class="item-date-input w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num text-xs focus:border-amber-500">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Action Buttons Box -->
                <div class="bg-white dark:bg-[#161d27] rounded-3xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-wrap items-center justify-between gap-3">
                    <button type="button" onclick="printPlanningNow()"
                            class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-black text-xs transition shadow-sm flex items-center gap-2 cursor-pointer active:scale-95">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                        </svg>
                        <span>طباعة المستند</span>
                    </button>

                    <button type="submit" 
                            class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-sm flex items-center gap-2 cursor-pointer active:scale-95">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                        </svg>
                        <span>حفظ في الأرشيف</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Live Print Preview Column -->
        <div class="xl:col-span-7 space-y-4">

            <!-- Control Bar (No Print) -->
            <div class="no-print flex items-center justify-between bg-white dark:bg-[#161d27] px-5 py-3 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xs">
                <div class="flex items-center gap-2 text-xs font-black text-slate-800 dark:text-slate-200">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>معاينة الطباعة المباشرة (A4 Live Preview)</span>
                </div>
                <button type="button" onclick="printPlanningNow()" 
                        class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-2xs cursor-pointer active:scale-95">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                    </svg>
                    <span>طباعة المستند الآن</span>
                </button>
            </div>

            <!-- OFFICIAL NIGHT SHIFT DOCUMENT PAPER (PRINTABLE) -->
            <div id="printablePlanning" class="bg-white text-slate-950 p-8 sm:p-11 rounded-2xl sm:rounded-3xl border border-slate-300 shadow-xl space-y-6 text-right font-sans mx-auto transition-all">

                <!-- TOP HEADER: LOGO & OFFICIAL FISCAL DATA -->
                <div class="border-b-2 border-slate-900 pb-4">
                    <div class="flex items-start justify-between gap-4">
                        <!-- Right: Company Logo & Info -->
                        <div class="flex items-start gap-3.5">
                            <img src="<?= url('/logo.webp') ?>" alt="Logo" class="w-16 h-16 object-contain rounded-xl border border-slate-200 shrink-0">
                            <div class="space-y-0.5 text-[11px] leading-tight text-slate-800">
                                <div class="font-black text-sm text-slate-950 tracking-tight">تاكسي نقيلو (TAXI NAGUILOU)</div>
                                <div><span class="font-bold">السجل التجاري (RC):</span> <span class="font-mono font-bold"><?= htmlspecialchars($companyInfo['rc']) ?></span></div>
                                <div><span class="font-bold">التعريف الجبائي (NIF):</span> <span class="font-mono font-bold"><?= htmlspecialchars($companyInfo['nif']) ?></span></div>
                                <div><span class="font-bold">التعريف الإحصائي (NIS):</span> <span class="font-mono font-bold"><?= htmlspecialchars($companyInfo['nis']) ?></span></div>
                                <div><span class="font-bold">رقم المادة (Art):</span> <span class="font-mono font-bold"><?= htmlspecialchars($companyInfo['art']) ?></span></div>
                                <div><span class="font-bold">الهاتف:</span> <span class="font-mono font-bold" dir="ltr"><?= htmlspecialchars($companyInfo['phone']) ?></span></div>
                                <div><span class="font-bold">البريد الإلكتروني:</span> <span class="font-mono" dir="ltr"><?= htmlspecialchars($companyInfo['email']) ?></span></div>
                            </div>
                        </div>

                        <!-- Left: Wilaya & Date -->
                        <div class="text-left space-y-1 text-xs">
                            <div class="font-black text-slate-950 text-base" id="pvWilaya"><?= htmlspecialchars($currentWilaya) ?></div>
                            <div class="text-xs text-slate-600 font-bold" id="pvPeriodBadge"><?= htmlspecialchars($currentPeriod) ?></div>
                        </div>
                    </div>
                </div>

                <!-- DOCUMENT TITLE BANNER -->
                <div class="text-center py-2 space-y-1">
                    <h2 class="text-xl font-black text-slate-950 tracking-wide" id="pvMainTitle">
                        <?= htmlspecialchars($currentTitle) ?>
                    </h2>
                    <p class="text-xs font-bold text-slate-700" id="pvSubTitle">
                        <?= htmlspecialchars($currentSubTitle) ?>
                    </p>
                </div>

                <!-- OFFICIAL SHIFT TABLE -->
                <div class="overflow-hidden border border-slate-900 rounded-xl">
                    <table class="w-full border-collapse text-xs text-right">
                        <thead>
                            <tr class="bg-slate-200 border-b border-slate-900 font-black text-slate-950 text-center">
                                <th class="border-l border-slate-900 p-2.5 w-16">الرقم</th>
                                <th class="border-l border-slate-900 p-2.5 text-right">الاسم السائق</th>
                                <th class="border-l border-slate-900 p-2.5 w-36 text-center">رقم الاعتماد</th>
                                <th class="p-2.5 w-32 text-center">التاريخ</th>
                            </tr>
                        </thead>
                        <tbody id="pvTableBody" class="divide-y divide-slate-300 font-medium">
                            <?php foreach ($activeItems as $item): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="border-l border-slate-300 p-2.5 text-center font-mono font-bold text-xs"><?= htmlspecialchars($item['num']) ?></td>
                                    <td class="border-l border-slate-300 p-2.5 font-bold text-slate-900">
                                        <?= !empty($item['driver_name']) ? htmlspecialchars($item['driver_name']) : '...................' ?>
                                    </td>
                                    <td class="border-l border-slate-300 p-2.5 text-center font-mono font-bold" dir="ltr">
                                        <?= htmlspecialchars($item['agrement_num']) ?>
                                    </td>
                                    <td class="p-2.5 text-center font-num text-slate-800" dir="ltr">
                                        <?= !empty($item['shift_date']) ? htmlspecialchars($item['shift_date']) : '...................' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- SIGNATURE SECTION -->
                <div class="pt-8 flex justify-end text-left">
                    <div class="w-64 text-center space-y-2">
                        <div class="font-black text-sm text-slate-950">مسير الشــــــــــــــركة</div>
                        <div class="text-xs font-bold text-slate-600">SARL NAGUILOU TAXI</div>
                        <div class="pt-10 text-[11px] text-slate-400">....................................................</div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <?php elseif ($activeTab === 'archive'): ?>
    <!-- TAB 2: ARCHIVE OF PERMANENCE LISTS -->
    <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h3 class="font-black text-slate-900 dark:text-white text-base">أرشيف قوائم المناوبة الليلية</h3>
                <p class="text-xs text-slate-400">سجل الجداول المعتمدة بالمؤسسة الاستشفائية بأدرار</p>
            </div>
            <?php if (!is_driver()): ?>
            <a href="planning.php?tab=generator" 
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-xs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>إنشاء مستند جديد</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap text-center">رقم القائمة</th>
                        <th class="p-3.5 whitespace-nowrap">العنوان / المؤسسة</th>
                        <th class="p-3.5 whitespace-nowrap text-center">الفترة / الشهر</th>
                        <th class="p-3.5 whitespace-nowrap text-center">تاريخ المداومة</th>
                        <th class="p-3.5 whitespace-nowrap text-center">عدد السائقين</th>
                        <th class="p-3.5 text-center whitespace-nowrap">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 font-medium">
                    <?php if (!empty($archiveLists)): ?>
                        <?php foreach ($archiveLists as $list): ?>
                            <?php 
                            $itemsDec = json_decode($list['items_json'] ?? '[]', true);
                            $driverCount = is_array($itemsDec) ? count($itemsDec) : 0;
                            ?>
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-sky font-bold font-mono text-xs" dir="ltr">
                                        <?= htmlspecialchars($list['list_number'] ?? ('#PL-' . $list['id'])) ?>
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($list['title']) ?></div>
                                    <div class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($list['sub_title']) ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center font-bold text-slate-700 dark:text-slate-300">
                                    <?= htmlspecialchars($list['period_month'] ?: '—') ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center font-num" dir="ltr">
                                    <?= htmlspecialchars($list['shift_date']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="px-2.5 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-num font-bold text-[11px]">
                                        <?= $driverCount ?> مناوب
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="planning.php?tab=generator&list_id=<?= $list['id'] ?>"
                                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-black text-xs transition shadow-2xs" title="معاينة وطباعة">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                                            </svg>
                                            <span>معاينة وطباعة</span>
                                        </a>

                                        <?php if (is_admin()): ?>
                                        <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف قائمة المناوبة هذه من الأرشيف؟')" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_permanence_list">
                                            <input type="hidden" name="list_id" value="<?= $list['id'] ?>">
                                            <button type="submit" 
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 text-rose-600 font-bold text-xs transition border border-rose-200 dark:border-rose-900" title="حذف">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="p-10 text-center text-slate-400">
                                لا توجد قوائم مناوبة مسجلة في الأرشيف حالياً.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($activeTab === 'fleet_watch'): ?>
    <!-- TAB 3: FLEET NIGHT WATCH (حراسة الحظيرة) -->
    <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs p-5 sm:p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-3">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                    🛡️
                </div>
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white text-sm">حراسة الحظيرة الليلية (Garde de Nuit du Parc)</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">السيارات المأوية حالياً داخل الموقف المعتمد</p>
                </div>
            </div>
            <button onclick="document.getElementById('addNightWatchModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-xs cursor-pointer">
                <span>+ تسجيل إيواء سيارة</span>
            </button>
        </div>

        <?php if (!empty($nightWatch)): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php foreach ($nightWatch as $nw): ?>
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-black text-slate-900 dark:text-white text-sm"><?= htmlspecialchars($nw['brand'] . ' ' . $nw['model']) ?></div>
                                <div class="font-num text-amber-600 dark:text-amber-400 font-bold text-xs" dir="ltr">
                                    <?= htmlspecialchars($nw['plate_number']) ?> (باب: <?= htmlspecialchars($nw['door_number']) ?>)
                                </div>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                داخل الحظيرة
                            </span>
                        </div>

                        <div class="space-y-1 text-xs text-slate-600 dark:text-slate-400 pt-2 border-t border-slate-200 dark:border-slate-800">
                            <div class="flex justify-between"><span>الموقف:</span> <strong class="text-slate-800 dark:text-slate-200"><?= htmlspecialchars($nw['park_slot']) ?></strong></div>
                            <div class="flex justify-between"><span>الحارس المكلف:</span> <strong class="text-slate-800 dark:text-slate-200"><?= htmlspecialchars($nw['guardian_name']) ?></strong></div>
                            <div class="flex justify-between"><span>ساعة الدخول:</span> <strong class="font-num text-slate-800 dark:text-slate-200" dir="ltr"><?= htmlspecialchars($nw['entry_time']) ?></strong></div>
                        </div>

                        <form method="POST" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="exit_park">
                            <input type="hidden" name="night_watch_id" value="<?= $nw['id'] ?>">
                            <button type="submit" 
                                    class="block w-full py-2 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-center text-xs font-bold text-slate-700 dark:text-slate-200 transition cursor-pointer">
                                تسجيل خروج للخدمة (Sortie)
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-8 text-center text-slate-400 text-xs">
                لا توجد سيارات مأوية حالياً في حراسة الحظيرة. جميع المركبات في النشاط الميداني.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Modal: Add Night Watch Entry -->
<div id="addNightWatchModal" class="hidden fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white dark:bg-[#161d27] rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 space-y-4 text-right">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white text-sm">تسجيل إيواء سيارة في حراسة الحظيرة</h3>
            <button onclick="document.getElementById('addNightWatchModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg cursor-pointer">✕</button>
        </div>
        <form method="POST" action="" class="space-y-3.5 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_night_watch">
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">المركبة *</label>
                <select name="vehicle_id" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold">
                    <option value="">-- اختر مركبة الأسطول --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (باب: <?= htmlspecialchars($v['door_number']) ?> - لوحة: <?= htmlspecialchars($v['plate_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">اسم الحارس المكلف</label>
                <input type="text" name="guardian_name" value="عمي عيسى (حارس الحظيرة)" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold">
            </div>
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">رقم الموقف / Zone</label>
                <input type="text" name="park_slot" value="Zone A" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold">
            </div>
            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="document.getElementById('addNightWatchModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-bold">إلغاء</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-black">تسجيل الدخول</button>
            </div>
        </form>
    </div>
</div>

<!-- PRINT CSS STYLES -->
<style>
@media print {
    body {
        background: #ffffff !important;
        color: #000000 !important;
    }
    .no-print, header, nav, aside, footer, #erpNavCategories, .erp-ribbon, #desktopHeader {
        display: none !important;
    }
    #printablePlanning {
        display: block !important;
        visibility: visible !important;
        position: static !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }
    #printablePlanning * {
        visibility: visible !important;
        color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    @page {
        size: A4 portrait;
        margin: 12mm 15mm;
    }
}
</style>

<!-- CLIENT SCRIPT: REACTIVE LIVE PREVIEW & ROW BUILDER -->
<script>
const activeDriversList = <?= json_encode($drivers, JSON_UNESCAPED_UNICODE) ?>;
const defaultAgrement = "<?= htmlspecialchars($companyInfo['agrement']) ?>";

function syncPlanningPreview() {
    const mainTitle = document.getElementById('inputMainTitle').value || 'قائمة المناوبة الليلية';
    const subTitle = document.getElementById('inputSubTitle').value || 'بالمؤسسة الاستشفائية بأدرار (عبد الكريم بودرغومة)';
    const wilaya = document.getElementById('inputWilaya').value || 'أدرار';
    const period = document.getElementById('inputPeriod').value || '';

    // Update Header Spans
    document.getElementById('pvMainTitle').textContent = mainTitle;
    document.getElementById('pvSubTitle').textContent = subTitle;
    document.getElementById('pvWilaya').textContent = wilaya;
    document.getElementById('pvPeriodBadge').textContent = period ? 'الفترة: ' + period : '';

    // Update Table of Shifts
    const rowsContainer = document.getElementById('shiftRowsContainer');
    const shiftRows = rowsContainer.querySelectorAll('.shift-row');
    const tableBody = document.getElementById('pvTableBody');
    tableBody.innerHTML = '';

    shiftRows.forEach((row, index) => {
        const num = String(index + 1).padStart(2, '0');
        const driverName = row.querySelector('.item-driver-name-input').value.trim();
        const agrement = row.querySelector('.item-agrement-input').value.trim() || `(${defaultAgrement})-${num}`;
        const shiftDate = row.querySelector('.item-date-input').value.trim();

        // Update badges inside form
        const badge = row.querySelector('.shift-num-badge');
        if (badge) badge.textContent = num;
        const numHidden = row.querySelector('.item-num-hidden');
        if (numHidden) numHidden.value = num;

        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50';
        tr.innerHTML = `
            <td class="border-l border-slate-300 p-2.5 text-center font-mono font-bold text-xs">${num}</td>
            <td class="border-l border-slate-300 p-2.5 font-bold text-slate-900">${driverName ? escapeHtml(driverName) : '...................'}</td>
            <td class="border-l border-slate-300 p-2.5 text-center font-mono font-bold" dir="ltr">${escapeHtml(agrement)}</td>
            <td class="p-2.5 text-center font-num text-slate-800" dir="ltr">${shiftDate ? escapeHtml(shiftDate) : '...................'}</td>
        `;
        tableBody.appendChild(tr);
    });
}

function onRowDriverSelect(selectEl) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const row = selectEl.closest('.shift-row');
    if (!row) return;

    const driverId = selectEl.value;
    const driverName = selectedOption ? (selectedOption.getAttribute('data-name') || '') : '';

    row.querySelector('.item-driver-id-hidden').value = driverId;
    row.querySelector('.item-driver-name-input').value = driverName;

    syncPlanningPreview();
}

function autoFillDrivers() {
    const rowsContainer = document.getElementById('shiftRowsContainer');
    const shiftRows = rowsContainer.querySelectorAll('.shift-row');

    shiftRows.forEach((row, index) => {
        if (index < activeDriversList.length) {
            const driver = activeDriversList[index];
            row.querySelector('.item-driver-id-hidden').value = driver.id;
            row.querySelector('.item-driver-name-input').value = driver.full_name;

            const select = row.querySelector('select');
            if (select) select.value = driver.id;
        }
    });
    syncPlanningPreview();
}

function applyDateToAll() {
    const globalDate = document.getElementById('inputGlobalDate').value;
    if (!globalDate) return;

    const dateInputs = document.querySelectorAll('.item-date-input');
    dateInputs.forEach(input => {
        input.value = globalDate;
    });
    syncPlanningPreview();
}

function addNewDriverRow() {
    const container = document.getElementById('shiftRowsContainer');
    const currentCount = container.querySelectorAll('.shift-row').length + 1;
    const num = String(currentCount).padStart(2, '0');
    const globalDate = document.getElementById('inputGlobalDate').value || '';

    let optionsHtml = '<option value="">اختر سائق...▼</option>';
    activeDriversList.forEach(d => {
        optionsHtml += `<option value="${d.id}" data-name="${escapeHtml(d.full_name)}">${escapeHtml(d.full_name)}</option>`;
    });

    const div = document.createElement('div');
    div.className = 'shift-row p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 space-y-2 transition';
    div.innerHTML = `
        <div class="flex items-center justify-between">
            <span class="shift-num-badge px-2.5 py-0.5 rounded-lg bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-mono font-black text-xs">
                ${num}
            </span>
            <input type="hidden" name="item_num[]" value="${num}" class="item-num-hidden">
            <input type="hidden" name="item_driver_id[]" value="" class="item-driver-id-hidden">
            <button type="button" onclick="removeShiftRow(this)" class="text-slate-400 hover:text-rose-600 transition cursor-pointer px-1" title="حذف السطر">
                ✕
            </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
            <div class="sm:col-span-5 space-y-1">
                <select onchange="onRowDriverSelect(this)" 
                        class="w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold text-[11px] focus:border-amber-500">
                    ${optionsHtml}
                </select>
                <input type="text" name="item_driver_name[]" value="" placeholder="اسم السائق" oninput="syncPlanningPreview()"
                       class="item-driver-name-input w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold text-xs focus:border-amber-500">
            </div>
            <div class="sm:col-span-3">
                <label class="block text-[10px] text-slate-400 mb-0.5 font-bold">رقم الاعتماد</label>
                <input type="text" name="item_agrement[]" value="(${defaultAgrement})-${num}" oninput="syncPlanningPreview()" dir="ltr"
                       class="item-agrement-input w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold text-center text-xs focus:border-amber-500">
            </div>
            <div class="sm:col-span-4">
                <label class="block text-[10px] text-slate-400 mb-0.5 font-bold">التاريخ</label>
                <input type="date" name="item_date[]" value="${globalDate}" oninput="syncPlanningPreview()"
                       class="item-date-input w-full px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num text-xs focus:border-amber-500">
            </div>
        </div>
    `;
    container.appendChild(div);
    syncPlanningPreview();
}

function removeShiftRow(btn) {
    const row = btn.closest('.shift-row');
    if (row) {
        row.remove();
        syncPlanningPreview();
    }
}

function resetToDefaults() {
    document.getElementById('inputMainTitle').value = 'قائمة المناوبة الليلية';
    document.getElementById('inputSubTitle').value = 'بالمؤسسة الاستشفائية بأدرار (عبد الكريم بودرغومة)';
    document.getElementById('inputWilaya').value = 'أدرار';
    document.getElementById('inputPeriod').value = 'أكتوبر 2026';
    syncPlanningPreview();
}

function printPlanningNow() {
    window.print();
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Initial sync on load
document.addEventListener('DOMContentLoaded', function() {
    syncPlanningPreview();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
