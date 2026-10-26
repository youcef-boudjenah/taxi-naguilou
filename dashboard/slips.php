<?php
// dashboard/slips.php - Générateur de Bordereaux d'Envoi & Historique des Bordereaux
$pageTitle = 'جداول الإرسال الرسمية (Bordereaux) | مديرية النقل';
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

// Fetch active drivers and their vehicles for quick autofill
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

// Active Tab ('generator' or 'history')
$activeTab = $_GET['tab'] ?? 'generator';
if (is_driver() && !isset($_GET['print_id'])) {
    $activeTab = 'history';
}

// Default 8 Official Documents as requested
$defaultDocuments = [
    ['num' => '01', 'title' => 'نسخة من البطاقة الرمادية', 'count' => '01', 'notes' => 'التجديد'],
    ['num' => '02', 'title' => 'نسخة من محضر المراقبة التقنية', 'count' => '01', 'notes' => 'التجديد'],
    ['num' => '03', 'title' => 'نسخة من شهادة تأمين السيارة', 'count' => '01', 'notes' => 'التجديد'],
    ['num' => '04', 'title' => 'طابع جبائي 50 دج', 'count' => '01', 'notes' => 'التجديد'],
    ['num' => '05', 'title' => 'رخصة استغلال منتهية الصلاحية', 'count' => '01', 'notes' => 'التجديد'],
    ['num' => '06', 'title' => 'دفتر المقاعد', 'count' => '01', 'notes' => 'التجديد'],
    ['num' => '07', 'title' => 'نسخة من دفتر الفحص الميترولوجي لعدادات سيارة الاجرة', 'count' => '01', 'notes' => 'التجديد'],
    ['num' => '08', 'title' => 'عقد العمل المحدد بمدة زمنية', 'count' => '01', 'notes' => 'التجديد'],
];

// Handle Save Slip (Create or Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_slip') {
    require_csrf_token();
    if (is_driver()) {
        set_flash('error', 'عذراً، لا تملك صلاحية حفظ أو إنشاء جداول الإرسال.');
        redirect('/dashboard/slips.php?tab=history');
        exit;
    }

    $slipId = intval($_POST['slip_id'] ?? 0);
    $slipNumber = trim($_POST['slip_number'] ?? '');
    $address = trim($_POST['address'] ?? $companyInfo['address']);
    $agrement = trim($_POST['agrement'] ?? $companyInfo['agrement']);
    $wilaya = trim($_POST['wilaya'] ?? $companyInfo['wilaya']);
    $issueDate = !empty($_POST['issue_date']) ? $_POST['issue_date'] : date('Y-m-d');
    $recipient = trim($_POST['recipient'] ?? 'مدير النقل لولاية أدرار');
    $driverId = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;
    $driverName = trim($_POST['driver_name'] ?? '');
    $vehicleId = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $subject = trim($_POST['subject'] ?? 'تجدون رفقة هذا الارسال ملف تجديد رخص الاستغلال للسائق الأجير: ' . $driverName);

    // Build documents array from POST
    $docTitles = $_POST['doc_title'] ?? [];
    $docCounts = $_POST['doc_count'] ?? [];
    $docNotes = $_POST['doc_notes'] ?? [];
    $documents = [];

    if (is_array($docTitles)) {
        for ($i = 0; $i < count($docTitles); $i++) {
            $t = trim($docTitles[$i] ?? '');
            if ($t !== '') {
                $documents[] = [
                    'num'   => str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                    'title' => $t,
                    'count' => str_pad(trim($docCounts[$i] ?? '01'), 2, '0', STR_PAD_LEFT),
                    'notes' => trim($docNotes[$i] ?? 'التجديد')
                ];
            }
        }
    }

    if (empty($documents)) {
        $documents = $defaultDocuments;
    }
    $documentsJson = json_encode($documents, JSON_UNESCAPED_UNICODE);

    if (empty($slipNumber)) {
        $currYear = date('Y', strtotime($issueDate));
        $maxSlipId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM transmission_slips")->fetchColumn();
        $slipNumber = sprintf("%06d-%s", $maxSlipId + 1, $currYear);
    }

    $routeLine = "$subject | $recipient | $address";
    $validUntil = date('Y-m-d', strtotime($issueDate . ' +30 days'));

    try {
        if ($slipId > 0) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE transmission_slips 
                SET slip_number = ?, recipient = ?, subject = ?, address = ?, agrement = ?, wilaya = ?,
                    driver_id = ?, driver_name = ?, vehicle_id = ?, route_line = ?, issue_date = ?,
                    valid_until = ?, documents_json = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $slipNumber, $recipient, $subject, $address, $agrement, $wilaya,
                $driverId, $driverName, $vehicleId, $routeLine, $issueDate,
                $validUntil, $documentsJson, $slipId
            ]);
            log_audit($pdo, 'slip_updated', 'transmission_slips', $slipId, "تعديل جدول إرسال رقم $slipNumber للمديرية");
            set_flash('success', "تم تعديل وحفظ جدول الإرسال رقم $slipNumber بنجاح في الأرشيف");
            redirect("/dashboard/slips.php?tab=generator&print_id=$slipId");
            exit;
        } else {
            // Create new
            $stmt = $pdo->prepare("
                INSERT INTO transmission_slips 
                (slip_number, recipient, subject, address, agrement, wilaya, driver_id, driver_name, vehicle_id, route_line, issue_date, valid_until, documents_json, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $slipNumber, $recipient, $subject, $address, $agrement, $wilaya,
                $driverId, $driverName, $vehicleId, $routeLine, $issueDate,
                $validUntil, $documentsJson
            ]);
            $newId = (int)$pdo->lastInsertId();
            log_audit($pdo, 'slip_generated', 'transmission_slips', $newId, "إنشاء جدول إرسال جديد رقم $slipNumber للمديرية");
            set_flash('success', "تم حفظ وأرشفة جدول الإرسال رقم $slipNumber بنجاح! الوثيقة جاهزة للطباعة أدناه.");
            redirect("/dashboard/slips.php?tab=generator&print_id=$newId");
            exit;
        }
    } catch (\Throwable $e) {
        set_flash('error', 'حدث خطأ أثناء حفظ جدول الإرسال: ' . $e->getMessage());
    }
}

// Handle Delete Slip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_slip') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف جداول الإرسال.');
        redirect('/dashboard/slips.php?tab=history');
        exit;
    }
    $slipId = intval($_POST['slip_id'] ?? 0);
    if ($slipId > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE transmission_slips SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$slipId]);
            log_audit($pdo, 'slip_deleted', 'transmission_slips', $slipId, "أرشفة وحذف جدول الإرسال رقم $slipId");
            set_flash('success', "تم نقل جدول الإرسال بنجاح إلى سلة المهملات والأرشيف.");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء حذف جدول الإرسال: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/slips.php?tab=history');
    exit;
}

// If specific slip requested to view / print
$activeSlip = null;
if (isset($_GET['print_id']) && $pdo) {
    try {
        $pId = intval($_GET['print_id']);
        $stmtP = $pdo->prepare("
            SELECT s.*, u.full_name as db_driver_name, u.phone as db_driver_phone,
                   v.brand, v.model, v.plate_number, v.door_number
            FROM transmission_slips s
            LEFT JOIN users u ON s.driver_id = u.id
            LEFT JOIN vehicles v ON s.vehicle_id = v.id
            WHERE s.id = ? AND s.deleted_at IS NULL
        ");
        $stmtP->execute([$pId]);
        $activeSlip = $stmtP->fetch();
    } catch (\Throwable $e) {
        $activeSlip = null;
    }
}

// Default values for the generator
$currYear = date('Y');
$currentDate = $activeSlip['issue_date'] ?? date('Y-m-d');
$currentAddress = $activeSlip['address'] ?? $companyInfo['address'];
$currentAgrement = $activeSlip['agrement'] ?? $companyInfo['agrement'];
$currentWilaya = $activeSlip['wilaya'] ?? $companyInfo['wilaya'];
$currentRecipient = $activeSlip['recipient'] ?? 'مدير النقل لولاية أدرار';
$currentDriverId = $activeSlip['driver_id'] ?? '';
$currentDriverName = $activeSlip['driver_name'] ?? ($activeSlip['db_driver_name'] ?? '');
$currentSubject = $activeSlip['subject'] ?? 'تجدون رفقة هذا الارسال ملف تجديد رخص الاستغلال للسائق الأجير: ' . ($currentDriverName ? $currentDriverName : '...................');

// Calculate sequential default slip number if creating new
if ($activeSlip) {
    $currentSlipNumber = $activeSlip['slip_number'];
} else {
    try {
        $stmtCnt = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM transmission_slips");
        $maxId = (int)$stmtCnt->fetchColumn();
        $currentSlipNumber = sprintf("%06d-%s", $maxId + 1, $currYear);
    } catch (\Throwable $e) {
        $currentSlipNumber = "000001-$currYear";
    }
}

// Parse active documents list
$activeDocuments = $defaultDocuments;
if ($activeSlip && !empty($activeSlip['documents_json'])) {
    $decoded = json_decode($activeSlip['documents_json'], true);
    if (is_array($decoded) && !empty($decoded)) {
        $activeDocuments = $decoded;
    }
}

// Fetch slips archive list for Tab 2
$slipsHistory = [];
if ($pdo) {
    try {
        if (is_driver()) {
            $stmtH = $pdo->prepare("
                SELECT s.*, COALESCE(s.driver_name, u.full_name) as effective_driver_name,
                       v.brand, v.model, v.plate_number, v.door_number
                FROM transmission_slips s
                LEFT JOIN users u ON s.driver_id = u.id
                LEFT JOIN vehicles v ON s.vehicle_id = v.id
                WHERE s.driver_id = ? AND s.deleted_at IS NULL
                ORDER BY s.id DESC
            ");
            $stmtH->execute([$_SESSION['user_id']]);
        } else {
            $stmtH = $pdo->query("
                SELECT s.*, COALESCE(s.driver_name, u.full_name) as effective_driver_name,
                       v.brand, v.model, v.plate_number, v.door_number
                FROM transmission_slips s
                LEFT JOIN users u ON s.driver_id = u.id
                LEFT JOIN vehicles v ON s.vehicle_id = v.id
                WHERE s.deleted_at IS NULL
                ORDER BY s.id DESC
            ");
        }
        $slipsHistory = $stmtH ? $stmtH->fetchAll() : [];
    } catch (\Throwable $e) {
        $slipsHistory = [];
    }
}
?>

<div class="space-y-6">

    <!-- Screen Header (Hidden on Print) -->
    <div class="no-print flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-lg shadow-2xs">
                    📑
                </div>
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">جداول الإرسال (Bordereaux)</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">إنشاء وطباعة جداول الإرسال الرسمية للمديرية ومصالح النقل الولائية</p>
                </div>
            </div>
        </div>

        <!-- Segmented Tab Navigation -->
        <div class="flex items-center bg-slate-100 dark:bg-[#161d27] p-1 rounded-2xl border border-slate-200 dark:border-slate-800 text-xs font-bold">
            <?php if (!is_driver()): ?>
            <a href="slips.php?tab=generator" 
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl transition <?= $activeTab === 'generator' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>إنشاء مستند جديد</span>
            </a>
            <?php endif; ?>
            <a href="slips.php?tab=history" 
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl transition <?= $activeTab === 'history' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-xs font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                <svg class="w-4 h-4 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                </svg>
                <span>أرشيف جداول الإرسال</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-num"><?= count($slipsHistory) ?></span>
            </a>
        </div>
    </div>

    <?php if ($activeTab === 'generator'): ?>
    <!-- TAB 1: GENERATEUR & LIVE PREVIEW -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">

        <!-- Form Column (No Print) -->
        <div class="no-print xl:col-span-5 space-y-5">

            <form id="slipForm" method="POST" action="" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_slip">
                <input type="hidden" name="slip_id" value="<?= $activeSlip['id'] ?? 0 ?>">
                <input type="hidden" name="driver_id" id="hiddenDriverId" value="<?= htmlspecialchars($currentDriverId) ?>">
                <input type="hidden" name="vehicle_id" id="hiddenVehicleId" value="<?= htmlspecialchars($activeSlip['vehicle_id'] ?? '') ?>">

                <!-- Card 1: معلومات الجدول -->
                <div class="bg-white dark:bg-[#161d27] rounded-3xl p-5 sm:p-6 border border-slate-200 dark:border-slate-800 shadow-xs space-y-4 text-xs">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <h3 class="font-black text-slate-900 dark:text-white text-sm">معلومات الجدول</h3>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 text-amber-700 dark:text-amber-400 font-bold text-[10px] border border-amber-500/20">
                            الافتراضي
                        </span>
                    </div>

                    <!-- Address & Agrement -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">العنوان</label>
                            <input type="text" id="inputAddress" name="address" value="<?= htmlspecialchars($currentAddress) ?>" oninput="syncLivePreview()"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-medium focus:border-amber-500 transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الاعتماد</label>
                            <input type="text" id="inputAgrement" name="agrement" value="<?= htmlspecialchars($currentAgrement) ?>" oninput="syncLivePreview()" dir="ltr"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold focus:border-amber-500 transition text-right">
                        </div>
                    </div>

                    <!-- Wilaya & Date & Slip Number -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الموقع / الولاية</label>
                            <input type="text" id="inputWilaya" name="wilaya" value="<?= htmlspecialchars($currentWilaya) ?>" oninput="syncLivePreview()"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500 transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">التاريخ</label>
                            <input type="date" id="inputDate" name="issue_date" value="<?= htmlspecialchars($currentDate) ?>" oninput="syncLivePreview()"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num focus:border-amber-500 transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">الرقم الإرسال</label>
                            <input type="text" id="inputSlipNumber" name="slip_number" value="<?= htmlspecialchars($currentSlipNumber) ?>" oninput="syncLivePreview()" dir="ltr"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold focus:border-amber-500 transition text-right">
                        </div>
                    </div>

                    <!-- Recipient -->
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">إلى السيد</label>
                        <input type="text" id="inputRecipient" name="recipient" value="<?= htmlspecialchars($currentRecipient) ?>" oninput="syncLivePreview()"
                               class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500 transition">
                    </div>

                    <!-- Driver Selector Helper -->
                    <div class="p-3 bg-amber-500/5 dark:bg-amber-500/10 rounded-2xl border border-amber-500/20 space-y-2">
                        <label class="block font-black text-amber-900 dark:text-amber-400 text-xs">اختر سائق من المنصة لتسهيل التعبئة</label>
                        <select id="driverQuickSelect" onchange="onDriverQuickSelected(this)"
                                class="w-full px-3 py-2 rounded-xl border border-amber-300 dark:border-amber-600/50 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-bold focus:border-amber-500 transition">
                            <option value="">اختر سائق...▼</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= $d['id'] ?>" 
                                        data-name="<?= htmlspecialchars($d['full_name']) ?>"
                                        data-vehicle="<?= $d['vehicle_id'] ?? '' ?>"
                                        <?= ($currentDriverId == $d['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['full_name']) ?> <?= !empty($d['door_number']) ? '(باب: ' . htmlspecialchars($d['door_number']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Driver Name -->
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">اسم السائق *</label>
                        <input type="text" id="inputDriverName" name="driver_name" required value="<?= htmlspecialchars($currentDriverName) ?>" oninput="onDriverNameTyped(this.value)" placeholder="مثال: عبد الرؤوف منصوري"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-black text-sm focus:border-amber-500 transition">
                    </div>

                    <!-- Subject -->
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">نص موضوع الإرسال</label>
                        <textarea id="inputSubject" name="subject" rows="2" oninput="syncLivePreview()"
                                  class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-medium focus:border-amber-500 transition"><?= htmlspecialchars($currentSubject) ?></textarea>
                    </div>
                </div>

                <!-- Card 2: قائمة الوثائق المرفقة -->
                <div class="bg-white dark:bg-[#161d27] rounded-3xl p-5 sm:p-6 border border-slate-200 dark:border-slate-800 shadow-xs space-y-4 text-xs">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <h3 class="font-black text-slate-900 dark:text-white text-sm">قائمة الوثائق المرفقة</h3>
                        </div>
                        <button type="button" onclick="addNewDocRow()" 
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400 font-bold border border-emerald-500/20 transition cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                            </svg>
                            <span>إضافة وثيقة</span>
                        </button>
                    </div>

                    <div id="docsRowsContainer" class="space-y-2.5">
                        <?php foreach ($activeDocuments as $idx => $doc): ?>
                            <div class="doc-row flex items-center gap-2 p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 transition">
                                <span class="doc-badge font-mono font-bold text-[11px] w-7 text-center text-slate-500 shrink-0">
                                    <?= htmlspecialchars($doc['num'] ?? str_pad($idx + 1, 2, '0', STR_PAD_LEFT)) ?>
                                </span>
                                <input type="text" name="doc_title[]" value="<?= htmlspecialchars($doc['title']) ?>" placeholder="تعيين الوثيقة" oninput="syncLivePreview()"
                                       class="doc-title-input flex-1 px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-medium focus:border-amber-500 text-xs">
                                <input type="text" name="doc_count[]" value="<?= htmlspecialchars($doc['count'] ?? '01') ?>" placeholder="العدد" oninput="syncLivePreview()" dir="ltr"
                                       class="doc-count-input w-12 px-1.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold text-center focus:border-amber-500 text-xs">
                                <input type="text" name="doc_notes[]" value="<?= htmlspecialchars($doc['notes'] ?? 'التجديد') ?>" placeholder="الملاحظة" oninput="syncLivePreview()"
                                       class="doc-notes-input w-20 px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-medium text-center focus:border-amber-500 text-xs">
                                <button type="button" onclick="removeDocRow(this)" class="w-7 h-7 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 flex items-center justify-center transition shrink-0 cursor-pointer" title="حذف السطر">
                                    ✕
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Action Buttons Bottom Sticky Box -->
                <div class="bg-white dark:bg-[#161d27] rounded-3xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-wrap items-center justify-between gap-3">
                    <button type="button" onclick="printBordereauNow()"
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

        <!-- Live Preview Column -->
        <div class="xl:col-span-7 space-y-4">

            <!-- Preview Top Control Bar (No Print) -->
            <div class="no-print flex items-center justify-between bg-white dark:bg-[#161d27] px-5 py-3 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xs">
                <div class="flex items-center gap-2 text-xs font-black text-slate-800 dark:text-slate-200">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>معاينة الطباعة المباشرة (A4 Live Preview)</span>
                </div>
                <button type="button" onclick="printBordereauNow()" 
                        class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-2xs cursor-pointer active:scale-95">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                    </svg>
                    <span>طباعة المستند الآن</span>
                </button>
            </div>

            <!-- OFFICIAL BORDEREAU SHEET PAPER (PRINTABLE) -->
            <div id="printableBordereau" class="bg-white text-slate-950 p-7 sm:p-10 rounded-2xl sm:rounded-3xl border border-slate-300 shadow-xl space-y-5 text-right font-sans mx-auto transition-all">
                
                <!-- TOP HEADER WITH LOGO & OFFICIAL FISCAL DATA -->
                <div class="border-b-2 border-slate-900 pb-4">
                    <div class="flex items-start justify-between gap-4">
                        <!-- Right: Company Logo & Fiscal Info -->
                        <div class="flex items-start gap-3.5">
                            <img src="<?= url('/logo.webp') ?>" alt="Logo" class="w-16 h-16 object-contain rounded-xl border border-slate-200 shrink-0">
                            <div class="space-y-0.5 text-[11px] leading-tight text-slate-800">
                                <div class="font-black text-sm text-slate-950 tracking-tight">تاكسي نقيلو (TAXI NAGUILOU)</div>
                                <div><span class="font-bold">السجل التجاري (RC):</span> <span class="font-mono font-bold" id="pvRc"><?= htmlspecialchars($companyInfo['rc']) ?></span></div>
                                <div><span class="font-bold">التعريف الجبائي (NIF):</span> <span class="font-mono font-bold" id="pvNif"><?= htmlspecialchars($companyInfo['nif']) ?></span></div>
                                <div><span class="font-bold">التعريف الإحصائي (NIS):</span> <span class="font-mono font-bold" id="pvNis"><?= htmlspecialchars($companyInfo['nis']) ?></span></div>
                                <div><span class="font-bold">رقم المادة (Art):</span> <span class="font-mono font-bold" id="pvArt"><?= htmlspecialchars($companyInfo['art']) ?></span></div>
                                <div><span class="font-bold">الهاتف:</span> <span class="font-mono font-bold" dir="ltr" id="pvPhone"><?= htmlspecialchars($companyInfo['phone']) ?></span></div>
                                <div><span class="font-bold">البريد الإلكتروني:</span> <span class="font-mono" dir="ltr" id="pvEmail"><?= htmlspecialchars($companyInfo['email']) ?></span></div>
                            </div>
                        </div>

                        <!-- Left: Wilaya / Date / Address / Agrement / Slip Number -->
                        <div class="text-left space-y-1 text-xs">
                            <div class="font-black text-slate-950 text-sm">
                                <span id="pvWilayaDate"><?= htmlspecialchars($currentWilaya) ?> في:</span> 
                                <span id="pvDate" class="font-mono"><?= htmlspecialchars($currentDate) ?></span>
                            </div>
                            <div class="text-[11px] text-slate-700">
                                <span class="font-bold">العنوان:</span> 
                                <span id="pvAddress"><?= htmlspecialchars($currentAddress) ?></span>
                            </div>
                            <div class="text-[11px] text-slate-700">
                                <span class="font-bold">الاعتماد:</span> 
                                <span id="pvAgrement" class="font-mono font-bold"><?= htmlspecialchars($currentAgrement) ?></span>
                            </div>
                            <div class="pt-1.5">
                                <span class="inline-block px-3 py-1 bg-slate-100 border border-slate-300 rounded-lg font-mono font-black text-xs text-slate-950 shadow-2xs" id="pvSlipNum">
                                    رقم الإرسال : <?= htmlspecialchars($currentSlipNumber) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DESTINATAIRE (المرسل إليه) -->
                <div class="flex justify-end pt-1">
                    <div class="w-80 border-2 border-slate-900 rounded-xl p-3 bg-slate-50/60 text-right space-y-0.5">
                        <div class="text-[11px] font-bold text-slate-500">المرسل إليه</div>
                        <div class="text-sm font-black text-slate-950" id="pvRecipient">
                            إلى السيد : <?= htmlspecialchars($currentRecipient) ?>
                        </div>
                    </div>
                </div>

                <!-- MAIN TITLE: جدول إرسال -->
                <div class="text-center py-1">
                    <div class="inline-block px-10 py-1.5 border-2 border-slate-900 rounded-xl bg-slate-100 font-black text-base text-slate-950 tracking-wider">
                        جدول إرسال
                    </div>
                </div>

                <!-- SUBJECT LINE -->
                <div class="p-2.5 border-r-4 border-slate-900 bg-slate-50 rounded text-xs font-bold text-slate-900 leading-relaxed">
                    <span id="pvSubject"><?= htmlspecialchars($currentSubject) ?></span>
                </div>

                <!-- OFFICIAL DOCUMENTS TABLE -->
                <div class="overflow-hidden border border-slate-900 rounded-xl">
                    <table class="w-full border-collapse text-xs text-right">
                        <thead>
                            <tr class="bg-slate-200 border-b border-slate-900 font-black text-slate-950 text-center">
                                <th class="border-l border-slate-900 p-2.5 w-16">الرقم</th>
                                <th class="border-l border-slate-900 p-2.5 text-right">تعيين الوثائق</th>
                                <th class="border-l border-slate-900 p-2.5 w-20">العدد</th>
                                <th class="p-2.5 w-32">الملاحظة</th>
                            </tr>
                        </thead>
                        <tbody id="pvTableBody" class="divide-y divide-slate-300 font-medium">
                            <?php foreach ($activeDocuments as $doc): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="border-l border-slate-300 p-2 text-center font-mono font-bold"><?= htmlspecialchars($doc['num']) ?></td>
                                    <td class="border-l border-slate-300 p-2 font-bold text-slate-900"><?= htmlspecialchars($doc['title']) ?></td>
                                    <td class="border-l border-slate-300 p-2 text-center font-mono font-bold"><?= htmlspecialchars($doc['count']) ?></td>
                                    <td class="p-2 text-center text-slate-800"><?= htmlspecialchars($doc['notes']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- BOTTOM SIGNATURES & RECEPTION RECEIPT -->
                <div class="grid grid-cols-2 gap-6 pt-4 text-center text-xs">
                    <div class="p-4 border border-dashed border-slate-400 rounded-2xl h-36 flex flex-col justify-between">
                        <span class="font-black text-slate-800">وصل استلام من مديرية النقل</span>
                        <div class="space-y-1 text-[11px] text-slate-500">
                            <div>تاريخ الاستلام: ...... / ...... / ............</div>
                            <div>الختم والتوقيع: ............................</div>
                        </div>
                    </div>
                    <div class="p-4 border border-dashed border-slate-400 rounded-2xl h-36 flex flex-col justify-between">
                        <span class="font-black text-slate-800">ختم وتوقيع مسيّر الوكالة</span>
                        <div class="text-xs font-black text-slate-950">SARL NAGUILOU TAXI</div>
                        <span class="text-[11px] text-slate-400">............................................</span>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <?php else: ?>
    <!-- TAB 2: ARCHIVE & HISTORY (أرشيف جداول الإرسال) -->
    <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h3 class="font-black text-slate-900 dark:text-white text-base">أرشيف جداول الإرسال الرسمية</h3>
                <p class="text-xs text-slate-400">سجل كامل بجميع جداول الإرسال المودعة لدى مديرية النقل لولاية أدرار</p>
            </div>
            <?php if (!is_driver()): ?>
            <a href="slips.php?tab=generator" 
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
                        <th class="p-3.5 whitespace-nowrap text-center">رقم الإرسال</th>
                        <th class="p-3.5 whitespace-nowrap">السائق المعني</th>
                        <th class="p-3.5 whitespace-nowrap">المرسل إليه</th>
                        <th class="p-3.5 whitespace-nowrap">الموضوع</th>
                        <th class="p-3.5 whitespace-nowrap text-center">تاريخ الإصدار</th>
                        <th class="p-3.5 whitespace-nowrap text-center">عدد الوثائق</th>
                        <th class="p-3.5 whitespace-nowrap text-center">الحالة</th>
                        <th class="p-3.5 text-center whitespace-nowrap">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 font-medium">
                    <?php if (!empty($slipsHistory)): ?>
                        <?php foreach ($slipsHistory as $slip): ?>
                            <?php 
                            $docsCount = 0;
                            if (!empty($slip['documents_json'])) {
                                $dec = json_decode($slip['documents_json'], true);
                                if (is_array($dec)) $docsCount = count($dec);
                            }
                            ?>
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-sky font-bold font-mono text-xs" dir="ltr">
                                        📄 <?= htmlspecialchars($slip['slip_number']) ?>
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-bold text-slate-900 dark:text-white">
                                    <?= htmlspecialchars($slip['effective_driver_name'] ?: 'غير محدد') ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    <?= htmlspecialchars($slip['recipient'] ?? 'مدير النقل لولاية أدرار') ?>
                                </td>
                                <td class="p-3.5 max-w-xs truncate text-slate-700 dark:text-slate-300">
                                    <?= htmlspecialchars($slip['subject'] ?? $slip['route_line']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center font-num" dir="ltr">
                                    <?= htmlspecialchars($slip['issue_date']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-num font-bold text-[11px]">
                                        <?= $docsCount ?> وثائق
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-emerald text-[10px] font-bold">
                                        <span class="table-num-dot-emerald"></span>
                                        ساري
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="slips.php?tab=generator&print_id=<?= $slip['id'] ?>"
                                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-black text-xs transition shadow-2xs" title="معاينة وطباعة">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                                            </svg>
                                            <span>معاينة وطباعة</span>
                                        </a>

                                        <?php if (is_admin()): ?>
                                        <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف جدول الإرسال هذا من الأرشيف؟')" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_slip">
                                            <input type="hidden" name="slip_id" value="<?= $slip['id'] ?>">
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
                            <td colspan="8" class="p-10 text-center text-slate-400">
                                لا توجد جداول إرسال مسجلة في الأرشيف حالياً.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- PRINT CSS STYLES -->
<style>
@media print {
    /* Hide everything on screen except printable paper */
    body {
        background: #ffffff !important;
        color: #000000 !important;
    }
    .no-print, header, nav, aside, footer, #erpNavCategories, .erp-ribbon, #desktopHeader {
        display: none !important;
    }
    #printableBordereau {
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
    #printableBordereau * {
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
function syncLivePreview() {
    const address = document.getElementById('inputAddress').value || 'أولاد احمد / تيمي / ادرار';
    const agrement = document.getElementById('inputAgrement').value || '02/22';
    const wilaya = document.getElementById('inputWilaya').value || 'أدرار';
    const date = document.getElementById('inputDate').value || '';
    const slipNum = document.getElementById('inputSlipNumber').value || '000001-2026';
    const recipient = document.getElementById('inputRecipient').value || 'مدير النقل لولاية أدرار';
    const subject = document.getElementById('inputSubject').value || '';

    // Update Header Spans
    document.getElementById('pvAddress').textContent = address;
    document.getElementById('pvAgrement').textContent = agrement;
    document.getElementById('pvWilayaDate').textContent = wilaya + ' في:';
    document.getElementById('pvDate').textContent = date;
    document.getElementById('pvSlipNum').textContent = 'رقم الإرسال : ' + slipNum;
    document.getElementById('pvRecipient').textContent = 'إلى السيد : ' + recipient;
    document.getElementById('pvSubject').textContent = subject;

    // Update Table of Documents
    const rowsContainer = document.getElementById('docsRowsContainer');
    const docRows = rowsContainer.querySelectorAll('.doc-row');
    const tableBody = document.getElementById('pvTableBody');
    tableBody.innerHTML = '';

    docRows.forEach((row, index) => {
        const title = row.querySelector('.doc-title-input').value.trim();
        const count = row.querySelector('.doc-count-input').value.trim() || '01';
        const notes = row.querySelector('.doc-notes-input').value.trim() || 'التجديد';
        const num = String(index + 1).padStart(2, '0');

        // Update badge inside form
        const badge = row.querySelector('.doc-badge');
        if (badge) badge.textContent = num;

        if (title) {
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-slate-50';
            tr.innerHTML = `
                <td class="border-l border-slate-300 p-2 text-center font-mono font-bold">${num}</td>
                <td class="border-l border-slate-300 p-2 font-bold text-slate-900">${escapeHtml(title)}</td>
                <td class="border-l border-slate-300 p-2 text-center font-mono font-bold">${escapeHtml(count)}</td>
                <td class="p-2 text-center text-slate-800">${escapeHtml(notes)}</td>
            `;
            tableBody.appendChild(tr);
        }
    });
}

function onDriverQuickSelected(selectEl) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    if (!selectedOption || !selectedOption.value) return;

    const driverId = selectedOption.value;
    const driverName = selectedOption.getAttribute('data-name') || '';
    const vehicleId = selectedOption.getAttribute('data-vehicle') || '';

    document.getElementById('hiddenDriverId').value = driverId;
    document.getElementById('hiddenVehicleId').value = vehicleId;
    document.getElementById('inputDriverName').value = driverName;

    onDriverNameTyped(driverName);
}

function onDriverNameTyped(name) {
    const defaultPrefix = 'تجدون رفقة هذا الارسال ملف تجديد رخص الاستغلال للسائق الأجير: ';
    document.getElementById('inputSubject').value = defaultPrefix + (name.trim() ? name.trim() : '...................');
    syncLivePreview();
}

function addNewDocRow() {
    const container = document.getElementById('docsRowsContainer');
    const currentCount = container.querySelectorAll('.doc-row').length + 1;
    const num = String(currentCount).padStart(2, '0');

    const div = document.createElement('div');
    div.className = 'doc-row flex items-center gap-2 p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 transition';
    div.innerHTML = `
        <span class="doc-badge font-mono font-bold text-[11px] w-7 text-center text-slate-500 shrink-0">${num}</span>
        <input type="text" name="doc_title[]" value="" placeholder="تعيين الوثيقة الجديدة" oninput="syncLivePreview()"
               class="doc-title-input flex-1 px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-medium focus:border-amber-500 text-xs">
        <input type="text" name="doc_count[]" value="01" placeholder="العدد" oninput="syncLivePreview()" dir="ltr"
               class="doc-count-input w-12 px-1.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-num font-bold text-center focus:border-amber-500 text-xs">
        <input type="text" name="doc_notes[]" value="التجديد" placeholder="الملاحظة" oninput="syncLivePreview()"
               class="doc-notes-input w-20 px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#111827] text-slate-900 dark:text-white font-medium text-center focus:border-amber-500 text-xs">
        <button type="button" onclick="removeDocRow(this)" class="w-7 h-7 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 flex items-center justify-center transition shrink-0 cursor-pointer" title="حذف السطر">
            ✕
        </button>
    `;
    container.appendChild(div);
    div.querySelector('.doc-title-input').focus();
    syncLivePreview();
}

function removeDocRow(btn) {
    const row = btn.closest('.doc-row');
    if (row) {
        row.remove();
        syncLivePreview();
    }
}

function printBordereauNow() {
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
    syncLivePreview();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
