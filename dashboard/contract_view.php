<?php
// dashboard/contract_view.php - Official 16-Article Labor Contract (Law 90-11)
require_once __DIR__ . '/../includes/auth.php';
require_login();

$contractId = intval($_GET['id'] ?? 0);
$pdo = getDBConnection();

$contract = null;
if ($pdo && $contractId > 0) {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               v.brand, v.model, v.plate_number, v.door_number, v.color,
               u_driver.full_name as driver_name, u_driver.phone as driver_phone, 
               u_driver.license_number as driver_license, u_driver.wilaya as driver_wilaya,
               u_owner.full_name as owner_name, u_owner.phone as owner_phone, u_owner.wilaya as owner_wilaya
        FROM contracts c
        JOIN users u_driver ON c.driver_id = u_driver.id
        LEFT JOIN vehicles v ON c.vehicle_id = v.id
        LEFT JOIN users u_owner ON c.owner_id = u_owner.id
        WHERE c.id = ?
    ");
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch();
}

if (!$contract) {
    set_flash('error', 'العقد المطلوب غير موجود أو تم حذفه.');
    redirect('/dashboard/contracts.php');
}

// Role-based access restriction
if (is_driver() && (int)$contract['driver_id'] !== (int)$_SESSION['user_id']) {
    set_flash('error', 'عذراً، لا يمكنك الاطلاع على عقود السائقين الآخرين.');
    redirect('/dashboard/index.php');
}
if (is_owner() && !is_admin() && (int)($contract['owner_id'] ?? 0) !== (int)$_SESSION['user_id']) {
    set_flash('error', 'عذراً، هذا العقد لا يتبع لمركبات أسطولك.');
    redirect('/dashboard/contracts.php');
}

// Handle Save/Update Dynamic DTW Accreditation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_dtw') {
    require_csrf_token();
    $newDtwNum = trim($_POST['dtw_number'] ?? '16');
    $newDtwYear = trim($_POST['dtw_year'] ?? '2024');
    try {
        $stmtUp = $pdo->prepare("UPDATE contracts SET dtw_number = ?, dtw_year = ? WHERE id = ?");
        $stmtUp->execute([$newDtwNum, $newDtwYear, $contractId]);
        if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'dtw_number' => $newDtwNum, 'dtw_year' => $newDtwYear]);
            exit;
        }
        set_flash('success', 'تم حفظ رقم وسنة اعتماد DTW بنجاح');
    } catch (\Throwable $e) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        set_flash('error', 'خطأ أثناء حفظ الاعتماد: ' . $e->getMessage());
    }
    redirect('/dashboard/contract_view.php?id=' . $contractId);
    exit;
}

// Company legal & headquarters metadata from settings
$company = get_company_meta($pdo);

// Dynamic DTW accreditation values
$defaultWilayaNum = preg_match('/^(\d+)/', $company['wilaya_raw'] ?? '', $m) ? $m[1] : '01';
$dtwNumber = htmlspecialchars($_GET['dtw_num'] ?? (!empty($contract['dtw_number']) ? $contract['dtw_number'] : $defaultWilayaNum));
$dtwYear = htmlspecialchars($_GET['dtw_year'] ?? (!empty($contract['dtw_year']) ? $contract['dtw_year'] : date('Y', strtotime($contract['start_date'] ?? 'now'))));

// Format duration and dates
$d1 = !empty($contract['start_date']) ? new DateTime($contract['start_date']) : new DateTime();
$d2 = !empty($contract['end_date']) ? new DateTime($contract['end_date']) : (clone $d1)->modify('+5 years');
$diffY = $d1->diff($d2)->y;
$diffYearsStr = str_pad(max(1, $diffY), 2, '0', STR_PAD_LEFT);
$startDateFormatted = date('d/m/Y', strtotime($contract['start_date']));
$endDateFormatted = date('d/m/Y', strtotime($contract['end_date']));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>عقد عمل رسمي <?= htmlspecialchars($contract['contract_number']) ?> | تاكسي نقيلو</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <!-- Production Compiled Tailwind CSS -->
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">
    <?= render_theme_head() ?>
    <style>
        html, body, input, button, select, textarea { 
            font-family: 'Cairo', 'Tajawal', sans-serif !important; 
            font-weight: 500;
            text-rendering: optimizeLegibility; 
            -webkit-font-smoothing: antialiased; 
            -moz-osx-font-smoothing: grayscale;
        }
        .font-black { font-weight: 900 !important; }
        .font-extrabold { font-weight: 800 !important; }
        .font-bold { font-weight: 700 !important; }
        .font-semibold { font-weight: 600 !important; }
        strong, b { font-weight: 800 !important; }
        html[dir="rtl"], [dir="rtl"], h1, h2, h3, h4, h5, h6, p, span, a, button, label, input, td, th {
            letter-spacing: normal !important;
        }
        .font-num, .font-mono, [dir="ltr"] { 
            font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif !important; 
            font-feature-settings: "tnum" 1; 
            font-variant-numeric: tabular-nums; 
            unicode-bidi: isolate; 
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; color: #000 !important; }
            .contract-paper { border: none !important; box-shadow: none !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
            strong, b, .font-bold, .font-black { font-weight: 900 !important; color: #000 !important; }
            .contract-paper img { border-radius: 9999px !important; object-fit: cover !important; }
        }
    </style>
</head>
<body class="bg-slate-100 dark:bg-[#0b0f17] text-slate-900 dark:text-slate-100 min-h-screen py-8 px-4 transition-colors">

    <?php $flash = get_flash(); if ($flash): ?>
    <div class="max-w-4xl mx-auto mb-5 no-print">
        <div class="p-4 rounded-xl flex items-center justify-between gap-3 text-xs font-bold <?= $flash['type'] === 'success' ? 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/15 text-rose-800 dark:text-rose-300 border border-rose-500/30' ?>">
            <div class="flex items-center gap-2">
                <span><?= $flash['type'] === 'success' ? '✓' : '✕' ?></span>
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top Action Toolbar (Hidden in print) -->
    <div class="max-w-4xl mx-auto mb-6 flex items-center justify-between no-print bg-white dark:bg-[#161d27] p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <?php if (!is_driver()): ?>
            <a href="contracts.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
                <span>العودة إلى قائمة العقود</span>
            </a>
            <?php else: ?>
            <a href="driver_fiche.php?id=<?= $user['id'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
                <span>العودة إلى البطاقة الفنية</span>
            </a>
            <?php endif; ?>

            <?php if ($contract['status'] === 'terminated'): ?>
            <a href="contract_termination.php?id=<?= $contractId ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-black transition shadow-xs">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <span>📜 محضر وبوردرورو الفسخ</span>
            </a>
            <?php endif; ?>

            <span class="text-xs font-bold text-slate-600 dark:text-slate-400">رقم العقد: <span class="font-num text-slate-900 dark:text-white font-mono inline-block font-black" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></span></span>
        </div>
        <div class="flex items-center gap-2">
            <!-- Dynamic Accreditation Inputs (Toolbar) -->
            <div class="flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800 px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs">
                <span class="font-bold text-slate-600 dark:text-slate-300">اعتماد DTW:</span>
                <input type="text" id="toolbarDtwNum" value="<?= $dtwNumber ?>" oninput="syncDtwFromToolbar()" class="w-12 px-1.5 py-0.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-center font-bold font-num text-xs focus:ring-1 focus:ring-amber-500" placeholder="الرقم" title="رقم الاعتماد / البوردرورو">
                <span class="text-slate-400">/</span>
                <input type="text" id="toolbarDtwYear" value="<?= $dtwYear ?>" oninput="syncDtwFromToolbar()" class="w-14 px-1.5 py-0.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-center font-bold font-num text-xs focus:ring-1 focus:ring-amber-500" placeholder="السنة" title="سنة الاعتماد">
                <button type="button" onclick="saveDtwAccreditation()" id="saveDtwBtn" class="px-2 py-0.5 rounded bg-slate-900 dark:bg-amber-500 text-white dark:text-slate-950 font-bold hover:bg-slate-800 transition text-[11px] cursor-pointer" title="حفظ وتثبيت الاعتماد">
                    حفظ
                </button>
            </div>
            <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-xs flex items-center gap-2 cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                </svg>
                <span>طباعة العقد الرسمي (PDF)</span>
            </button>
            <?= render_theme_toggle_btn('p-2') ?>
        </div>
    </div>

    <!-- Official Document Paper -->
    <div class="contract-paper max-w-4xl mx-auto bg-white dark:bg-[#161d27] p-8 sm:p-12 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-md space-y-6 text-slate-900 dark:text-slate-100 text-xs sm:text-sm leading-relaxed">
        
        <?php if ($contract['status'] === 'terminated'): 
            $termDateFormatted = !empty($contract['termination_date']) ? date('d/m/Y', strtotime($contract['termination_date'])) : '—';
            $clauseTitle = !empty($contract['termination_clause']) ? $contract['termination_clause'] : 'فسخ العقد طبقاً للقانون 90-11 المنظم لعلاقات العمل';
        ?>
        <!-- Official Termination Alert Banner (Screen) -->
        <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border-2 border-rose-500 text-rose-900 dark:text-rose-100 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-xs no-print">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-rose-600 text-white flex items-center justify-center text-xl font-black shrink-0 shadow-xs">
                    ✕
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-black text-sm text-rose-950 dark:text-white">تنبيه قانوني: تم فسخ هذا العقد رسمياً</span>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-rose-600 text-white">عقد مفسوخ</span>
                    </div>
                    <div class="text-xs text-rose-800 dark:text-rose-300 font-bold mt-1">
                        تاريخ سريان الفسخ: <strong class="font-num text-rose-950 dark:text-white" dir="ltr"><?= $termDateFormatted ?></strong> • السند والسبب: <strong class="text-rose-950 dark:text-white"><?= htmlspecialchars($clauseTitle) ?></strong>
                        <?php if (!empty($contract['termination_notes'])): ?>
                            <span class="block text-[11px] font-normal text-rose-700 dark:text-rose-400 mt-0.5">ملاحظات وسند الفسخ: <?= htmlspecialchars($contract['termination_notes']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0 w-full sm:w-auto justify-end">
                <a href="contract_termination.php?id=<?= $contractId ?>&tab=document" 
                   class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-black transition shadow-xs flex items-center gap-1.5">
                    <span>📜 فتح محضر الفسخ الرسمي</span>
                    <span>←</span>
                </a>
                <a href="contract_termination.php?id=<?= $contractId ?>&tab=slip" 
                   class="px-3.5 py-2.5 rounded-xl border border-rose-300 dark:border-rose-800 bg-white dark:bg-slate-900 hover:bg-rose-50 dark:hover:bg-slate-800 text-rose-800 dark:text-rose-200 text-xs font-black transition">
                    <span>📋 بوردرورو DTW</span>
                </a>
            </div>
        </div>

        <!-- Official Termination Banner (Print Mode) -->
        <div class="hidden print:block border-2 border-rose-600 p-2.5 rounded-lg text-center text-rose-800 font-black text-xs">
            ⚠️ تنبيه رسمي: هذا العقد تم فسخه رسمياً وإلغاؤه بتاريخ <?= $termDateFormatted ?> بموجب محضر الفسخ المعتمد وبوردرورو مديرية النقل.
        </div>
        <?php endif; ?>

        <!-- Republic Official Header with Logo -->
        <div class="border-b-2 border-slate-900 dark:border-slate-700 pb-5 space-y-3">
            <div class="flex items-center justify-between gap-4">
                <img src="<?= url('/logo.webp') ?>" alt="EURL TAXI NAGUILOU" class="w-24 h-24 sm:w-28 sm:h-28 rounded-full object-cover border-2 border-amber-400 dark:border-amber-500 shadow-md shrink-0 p-1 bg-white">
                <div class="text-center space-y-1.5 flex-1">
                    <h1 class="text-base sm:text-xl font-black text-slate-950 dark:text-white">الجمهورية الجزائرية الديمقراطية الشعبية</h1>
                    <h2 class="text-sm sm:text-base font-black text-slate-900 dark:text-slate-100">مديرية النقل لولاية <?= htmlspecialchars($company['wilaya_clean']) ?></h2>
                    <div class="text-xs sm:text-sm font-black text-amber-700 dark:text-amber-400 font-num"><?= htmlspecialchars($company['name']) ?></div>
                </div>
                <div class="w-28 text-center text-xs text-slate-600 dark:text-slate-400 font-num shrink-0">
                    <div class="font-bold">اعتماد DTW</div>
                    <div class="font-black text-slate-950 dark:text-white text-sm flex items-center justify-center gap-0.5" dir="ltr">
                        <span id="dtwNumDisplay" contenteditable="true" oninput="syncDtwFromDoc()" class="hover:bg-amber-50 dark:hover:bg-amber-950/40 px-1 rounded transition border-b border-dashed border-slate-400 print:border-none cursor-text" title="انقر لتعديل رقم الاعتماد"><?= $dtwNumber ?></span>
                        <span>/</span>
                        <span id="dtwYearDisplay" contenteditable="true" oninput="syncDtwFromDoc()" class="hover:bg-amber-50 dark:hover:bg-amber-950/40 px-1 rounded transition border-b border-dashed border-slate-400 print:border-none cursor-text" title="انقر لتعديل سنة الاعتماد"><?= $dtwYear ?></span>
                    </div>
                </div>
            </div>
            <div class="text-center pt-2">
                <span class="inline-block px-5 py-1.5 border-2 border-slate-950 dark:border-slate-500 rounded-lg font-black text-sm sm:text-base text-slate-950 dark:text-white bg-slate-50 dark:bg-slate-800/60 shadow-xs">
                    عقد عمل نموذجي لسائق سيارة أجرة (القانون 90-11)
                </span>
            </div>
            <div class="text-center text-xs text-slate-700 dark:text-slate-300 font-num pt-1">
                الرقم التسلسلي للعقد: <strong class="inline-block font-mono text-slate-950 dark:text-white font-black text-sm" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></strong> • تاريخ الإصدار: <strong class="font-num inline-block text-slate-950 dark:text-white font-bold" dir="ltr"><?= date('Y-m-d') ?></strong>
            </div>
        </div>

        <!-- The Parties (Basic Information in Bold) -->
        <div class="space-y-3 bg-slate-50 dark:bg-[#1a2332] p-5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
            <div class="leading-relaxed">
                <strong class="font-black text-slate-900 dark:text-slate-200">الطرف الأول (المستخدم / صاحب رخصة الاستغلال):</strong><br>
                المؤسسة: <strong class="text-slate-950 dark:text-white font-black text-sm sm:text-base"><?= htmlspecialchars($company['name']) ?></strong>، 
                العنوان والمقر الاجتماعي (Siège Social): <strong class="text-slate-950 dark:text-white font-bold"><?= htmlspecialchars($company['address']) ?></strong>، 
                الهاتف: <strong class="font-num font-mono inline-block text-slate-950 dark:text-white font-black text-sm" dir="ltr"><?= htmlspecialchars(!empty($company['phone']) ? $company['phone'] : (!empty($contract['owner_phone']) ? $contract['owner_phone'] : '0663.55.00.42')) ?></strong>
                <?php if (!empty($company['rc'])): ?>
                    • السجل التجاري (RC): <strong class="font-num inline-block text-slate-950 dark:text-white font-bold" dir="ltr"><?= htmlspecialchars($company['rc']) ?></strong>
                <?php endif; ?>
                <?php if (!empty($company['nif'])): ?>
                    • الرقم الجبائي (NIF): <strong class="font-num inline-block text-slate-950 dark:text-white font-bold" dir="ltr"><?= htmlspecialchars($company['nif']) ?></strong>
                <?php endif; ?>.
            </div>
            <div class="border-t border-slate-200 dark:border-slate-800 pt-3 leading-relaxed">
                <strong class="font-black text-slate-900 dark:text-slate-200">الطرف الثاني (العامل / سائق سيارة الأجرة):</strong><br>
                السيد: <strong class="text-slate-950 dark:text-white font-black text-sm sm:text-base"><?= htmlspecialchars($contract['driver_name']) ?></strong>، 
                رقم رخصة السياقة: <strong class="font-num font-mono inline-block text-slate-950 dark:text-white font-black text-sm" dir="ltr"><?= htmlspecialchars($contract['driver_license'] ?? 'مسجل بالملف') ?></strong>، 
                الهاتف: <strong class="font-num font-mono inline-block text-slate-950 dark:text-white font-black text-sm" dir="ltr"><?= htmlspecialchars($contract['driver_phone']) ?></strong>، 
                المقيم بـ: <strong class="text-slate-950 dark:text-white font-bold"><?= htmlspecialchars($contract['driver_wilaya']) ?></strong>.
            </div>
            <div class="border-t border-slate-200 dark:border-slate-800 pt-3 leading-relaxed text-xs sm:text-sm text-slate-700 dark:text-slate-300">
                <strong class="font-black text-slate-900 dark:text-slate-200">بيانات سيارة الأجرة المعنية:</strong> 
                <?php if (!empty($contract['plate_number'])): ?>
                    الصانع والنوع: <strong class="text-slate-950 dark:text-white font-black text-sm"><?= htmlspecialchars(($contract['brand'] ?? '') . ' ' . ($contract['model'] ?? '')) ?></strong> • 
                    رقم اللوحة: <strong class="font-num font-mono inline-block text-slate-950 dark:text-white font-black text-sm px-2 py-0.5 bg-amber-50 dark:bg-amber-950/40 rounded border border-amber-300 dark:border-amber-700" dir="ltr"><?= htmlspecialchars($contract['plate_number']) ?></strong> • 
                    رقم الباب المعتمد: <strong class="font-num inline-block text-slate-950 dark:text-white font-black text-sm px-2 py-0.5 bg-slate-100 dark:bg-slate-800 rounded border border-slate-300 dark:border-slate-700" dir="ltr"><?= htmlspecialchars($contract['door_number']) ?></strong> • 
                    اللون: <strong class="text-slate-950 dark:text-white font-bold"><?= htmlspecialchars($contract['color'] ?? 'أصفر') ?></strong>.
                <?php else: ?>
                    <strong class="text-amber-800 dark:text-amber-300 font-black">مركبة الأسطول: قيد التخصيص والتعيين</strong>
                <?php endif; ?>
            </div>
        </div>

        <!-- Preamble -->
        <p class="text-justify text-xs sm:text-sm font-medium leading-relaxed">
            <strong class="font-black text-slate-950 dark:text-white">تمهيد:</strong> بناءً على أحكام القانون رقم 90-11 المؤرخ في 21 أفريل 1990 المتعلق بعلاقات العمل، المعدل والمتمم، والمرسوم التنفيذي المحدد لشروط وقواعد استغلال سيارات الأجرة ودفتر الشروط الصادر عن وزارة النقل، اتفق الطرفان بكامل أهليتهما القانونية على البنود والمواد التالية:
        </p>

        <!-- The 16 Legal Articles (Official Company Text) -->
        <div class="space-y-3.5 text-xs sm:text-sm text-justify leading-relaxed">
            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 01:</strong>
                يهدف العقد الحالي إلى تحديد مضمون علاقة العمل بين المستخدم والعامل.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 02:</strong>
                بموجب هذا العقد يتم تشغيل السيد / <strong class="font-black text-slate-950 dark:text-white text-sm"><?= htmlspecialchars($contract['driver_name']) ?></strong>، بصفته: سائقا أجيرا من طرف : <strong class="font-black text-slate-950 dark:text-white"><?= htmlspecialchars($company['name']) ?></strong> لمدة زمنية محدودة تحت سلطة ومراقبة المستخدم أو المسؤول الذي يعين لغرض حسن سير المهام المسندة إليه.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 03:</strong>
                يجب على السائق الأجير بأن يصرح على أي تعديل أو طارئ على وضعيته الشخصية عند توقيع العقد.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 04:</strong>
                تم إبرام العقد لمدة محددة طبقا لأحكام المادة 12 من القانون 11/90 المؤرخ في 21 أفريل 1990 المتعلق بعلاقات العمل والمعدلة والمتممة بالمادة 02 من الأمر رقم 21/96 وفقا لاحتياجات المؤسسة المستخدمة.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 05:</strong>
                توظيف السائق لتنفيذ عمل مرتبط بعقود أشغال أو خدمات غير متجددة.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 06:</strong>
                تم إبرام هذا العقد لمدة محددة بـ ( <strong class="font-black text-slate-950 dark:text-white text-sm"><?= htmlspecialchars($diffYearsStr) ?></strong> ) سنوات، ويبدأ سريان مفعول العقد ابتداء من <strong class="font-num inline-block font-black text-slate-950 dark:text-white text-sm" dir="ltr"><?= $startDateFormatted ?></strong> إلى غاية <strong class="font-num inline-block font-black text-slate-950 dark:text-white text-sm" dir="ltr"><?= $endDateFormatted ?></strong>.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 07:</strong>
                اتفق كلا من الطرفين على أن علاقة العمل المبرمة بينهما تخضع السائق بالاتفاق لفترة تجريبية قصوى محددة بـ (06) أشهر، ويمكن لأحد الطرفين في هذه الفترة أن يضع حدا لعلاقة العمل دون تعويض أو إشعار مسبق.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 08:</strong>
                اتفق الطرفين على أن الأجر الذي يسدد لسائق مقابل العمل المذكور في المادة 02 ويكون بأجرة لا تقل في جميع الحالات عن الحد الأدنى المضمون للأجور وطنيا. كما أنه يتقاضاها في الأسبوع الأول من الشهر الموالي كحد أقصى.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 09:</strong>
                يحق للسائق ما أقره القانون كما يلتزم بالمقابل بما أوجبته عليه نفس الأحكام.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 10:</strong>
                يلتزم السائق باحترام النظام الداخلي للمؤسسة بعد الإطلاع عليه وقبول أحكامه وكذا النصوص التنظيمية المتعلقة بحركة المرور والنقل بسيارات الأجرة.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 11:</strong>
                يتحمل السائق قيمة الأضرار التي يكون سببا فيها أثناء تأدية مهامه بطريقة مباشرة أو غير مباشرة ويتم خصم قيمة الأضرار مباشرة من راتبه.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 12:</strong>
                عند الاستقالة يلتزم السائق فترة الأشعار المسبق بشهر واحد (01) يمكن تقليص المدة باتفاق الطرفين.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 13:</strong>
                نظرا لطبيعة نشاط المؤسسة يمكن لها وضع حدا لعلاقة العمل في حالة انتهاء المشروع أو حدوث طارئ أدى إلى عدم إمكانية مزاولة المشروع.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 14:</strong>
                يمكن إلغاء العقد عند الطلب الملح والضروري من طرف الزبون في حالة ارتكاب السائق لخطأ اتجاه الزبون في مكان العمل.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 15:</strong>
                يلتزم المستخدم في إطار احترام القواعد والإجراءات الساري بها داخل المؤسسة. يمنح للسائق الأجير جميع الحقوق المعترف بها لسائقي المؤسسة من طرف التشريع والتنظيم والقانون الداخلي.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white">المادة 16:</strong>
                صرح السائق الممضي أسفله أنه اطلع على القانون الداخلي المطبق داخل المؤسسة وقبل بأحكامه.
            </div>
        </div>

        <!-- Official Signatures Box (First party signature removed as requested, leaving balanced 2 columns) -->
        <div class="pt-8 border-t-2 border-slate-900 dark:border-slate-700 grid grid-cols-2 gap-12 text-center text-xs sm:text-sm">
            <div class="space-y-14">
                <span class="font-black text-slate-950 dark:text-slate-100 text-sm sm:text-base">توقيع وبصمة السائق (الطرف الثاني)</span>
                <div class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-bold italic">قرئ وصودق عليه</div>
            </div>

            <div class="space-y-10">
                <span class="font-black text-slate-950 dark:text-slate-100 text-sm sm:text-base">تأشيرة وختم <?= htmlspecialchars($company['name']) ?></span>
                <div class="w-20 h-20 rounded-full border-2 border-dashed border-amber-500 mx-auto flex items-center justify-center text-xs text-amber-800 dark:text-amber-400 font-black bg-amber-50/50 dark:bg-amber-950/20">
                    موثق رقمياً
                </div>
            </div>
        </div>

    </div>

    <script>
    function syncDtwFromToolbar() {
        const num = document.getElementById('toolbarDtwNum').value;
        const yr = document.getElementById('toolbarDtwYear').value;
        document.getElementById('dtwNumDisplay').textContent = num;
        document.getElementById('dtwYearDisplay').textContent = yr;
    }

    function syncDtwFromDoc() {
        const num = document.getElementById('dtwNumDisplay').textContent.trim();
        const yr = document.getElementById('dtwYearDisplay').textContent.trim();
        document.getElementById('toolbarDtwNum').value = num;
        document.getElementById('toolbarDtwYear').value = yr;
    }

    async function saveDtwAccreditation() {
        const num = document.getElementById('toolbarDtwNum').value.trim();
        const yr = document.getElementById('toolbarDtwYear').value.trim();
        const btn = document.getElementById('saveDtwBtn');
        const oldText = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'جاري الحفظ...';

        const formData = new FormData();
        formData.append('csrf_token', '<?= csrf_token() ?>');
        formData.append('action', 'update_dtw');
        formData.append('dtw_number', num);
        formData.append('dtw_year', yr);
        formData.append('ajax', '1');

        try {
            const res = await fetch('', { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            if (json.success) {
                btn.textContent = 'تم الحفظ ✓';
                btn.classList.add('bg-emerald-600', 'text-white');
                setTimeout(() => {
                    btn.textContent = 'حفظ';
                    btn.classList.remove('bg-emerald-600', 'text-white');
                    btn.disabled = false;
                }, 1800);
            } else {
                alert('حدث خطأ: ' + (json.error || 'تعذر الحفظ'));
                btn.disabled = false;
                btn.innerHTML = oldText;
            }
        } catch (e) {
            alert('تعذر الاتصال بالخادم لحفظ التعديل.');
            btn.disabled = false;
            btn.innerHTML = oldText;
        }
    }
    </script>
</body>
</html>
