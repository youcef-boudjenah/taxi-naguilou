<?php
// dashboard/contract_termination.php - Official Labor Contract Termination Act & DTW Bordereau (Law 90-11)
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
               u_driver.address as driver_address,
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
    set_flash('error', 'عذراً، لا يمكنك الاطلاع على عقود ومحاضر السائقين الآخرين.');
    redirect('/dashboard/index.php');
}
if (is_owner() && !is_admin() && (int)($contract['owner_id'] ?? 0) !== (int)$_SESSION['user_id']) {
    set_flash('error', 'عذراً، هذا العقد لا يتبع لمركبات أسطولك.');
    redirect('/dashboard/contracts.php');
}

// Active document tab: 'document' (محضر الفسخ) or 'slip' (بوردرورو الإرسال DTW)
$activeTab = $_GET['tab'] ?? 'document';

// Company legal & headquarters metadata from settings
$company = get_company_meta($pdo);

// Dynamic DTW accreditation values
$defaultWilayaNum = preg_match('/^(\d+)/', $company['wilaya_raw'] ?? '', $m) ? $m[1] : '01';
$dtwNumber = htmlspecialchars($_GET['dtw_num'] ?? (!empty($contract['dtw_number']) ? $contract['dtw_number'] : $defaultWilayaNum));
$dtwYear = htmlspecialchars($_GET['dtw_year'] ?? (!empty($contract['dtw_year']) ? $contract['dtw_year'] : date('Y', strtotime($contract['start_date'] ?? 'now'))));

// Termination details
$termDate = !empty($contract['termination_date']) ? $contract['termination_date'] : date('Y-m-d');
$termDateFormatted = date('d/m/Y', strtotime($termDate));
$startDateFormatted = !empty($contract['start_date']) ? date('d/m/Y', strtotime($contract['start_date'])) : '—';
$clauseTitle = !empty($contract['termination_clause']) ? $contract['termination_clause'] : 'فسخ العقد بموجب أحكام القانون 90-11 المنظم لعلاقات العمل';
$terminationNotes = !empty($contract['termination_notes']) ? $contract['termination_notes'] : '';

// Reference numbers
$resNum = 'RES-' . date('Y', strtotime($termDate)) . '-' . str_pad((int)$contract['id'], 3, '0', STR_PAD_LEFT);
$slipNum = 'BRD-RES-' . date('Y', strtotime($termDate)) . '-' . str_pad((int)$contract['id'], 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>محضر وبوردرورو فسخ العقد <?= htmlspecialchars($contract['contract_number']) ?> | تاكسي نقيلو</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
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
            <span class="text-[11px] opacity-75 hidden sm:inline">تم تجهيز محضر الفسخ والبوردرورو المعتمد</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top Action Toolbar (Hidden in print) -->
    <div class="max-w-4xl mx-auto mb-6 flex items-center justify-between no-print bg-white dark:bg-[#161d27] p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <a href="contracts.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
                <span>العودة إلى قائمة العقود</span>
            </a>
            <a href="contract_view.php?id=<?= $contractId ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <span>معاينة العقد الأصلي</span>
            </a>
            <span class="text-xs font-bold text-slate-600 dark:text-slate-400">عقد رقم: <span class="font-num text-slate-900 dark:text-white font-mono inline-block font-black" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></span></span>
        </div>

        <!-- Document / Slip Tab Switcher -->
        <div class="flex items-center gap-1 bg-slate-100 dark:bg-slate-800 p-1 rounded-xl">
            <a href="contract_termination.php?id=<?= $contractId ?>&tab=document" 
               class="px-3 py-1.5 rounded-lg text-xs font-black transition <?= $activeTab === 'document' ? 'bg-rose-600 text-white shadow-xs' : 'text-slate-700 dark:text-slate-300 hover:bg-white/60 dark:hover:bg-slate-700' ?>">
                📜 محضر الفسخ الرسمي
            </a>
            <a href="contract_termination.php?id=<?= $contractId ?>&tab=slip" 
               class="px-3 py-1.5 rounded-lg text-xs font-black transition <?= $activeTab === 'slip' ? 'bg-rose-600 text-white shadow-xs' : 'text-slate-700 dark:text-slate-300 hover:bg-white/60 dark:hover:bg-slate-700' ?>">
                📋 بوردرورو الفسخ (DTW)
            </a>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-black text-xs transition shadow-xs flex items-center gap-2 cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                </svg>
                <span>طباعة الوثيقة (PDF)</span>
            </button>
            <?= render_theme_toggle_btn('p-2') ?>
        </div>
    </div>

    <?php if ($activeTab === 'document'): ?>
    <!-- ========================================== -->
    <!-- TAB 1: OFFICIAL TERMINATION DOCUMENT (ACT) -->
    <!-- ========================================== -->
    <div class="contract-paper max-w-4xl mx-auto bg-white dark:bg-[#161d27] p-8 sm:p-12 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-md space-y-6 text-slate-900 dark:text-slate-100 text-xs sm:text-sm leading-relaxed">
        
        <!-- Republic Official Header with Logo -->
        <div class="border-b-2 border-slate-900 dark:border-slate-700 pb-5 space-y-3">
            <div class="flex items-center justify-between gap-4">
                <img src="<?= url('/logo.webp') ?>" alt="EURL TAXI NAGUILOU" class="w-24 h-24 sm:w-28 sm:h-28 rounded-full object-cover border-2 border-rose-500 shadow-md shrink-0 p-1 bg-white">
                <div class="text-center space-y-1.5 flex-1">
                    <h1 class="text-base sm:text-xl font-black text-slate-950 dark:text-white">الجمهورية الجزائرية الديمقراطية الشعبية</h1>
                    <h2 class="text-sm sm:text-base font-black text-slate-900 dark:text-slate-100">مديرية النقل لولاية <?= htmlspecialchars($company['wilaya_clean']) ?></h2>
                    <div class="text-xs sm:text-sm font-black text-rose-700 dark:text-rose-400 font-num"><?= htmlspecialchars($company['name']) ?></div>
                </div>
                <div class="w-28 text-center text-xs text-slate-600 dark:text-slate-400 font-num shrink-0">
                    <div class="font-bold">اعتماد DTW</div>
                    <div class="font-black text-slate-950 dark:text-white text-sm" dir="ltr"><?= $dtwNumber ?>/<?= $dtwYear ?></div>
                </div>
            </div>
            <div class="text-center pt-2">
                <span class="inline-block px-5 py-1.5 border-2 border-rose-900 dark:border-rose-500 rounded-lg font-black text-sm sm:text-base text-rose-900 dark:text-rose-200 bg-rose-50 dark:bg-rose-950/40 shadow-xs">
                    محضر إنهاء وفسخ عقد عمل سائق سيارة أجرة (القانون 90-11)
                </span>
            </div>
            <div class="text-center text-xs text-slate-700 dark:text-slate-300 font-num pt-1 flex items-center justify-center gap-3 flex-wrap">
                <span>الرقم المرجعي للفسخ: <strong class="inline-block font-mono text-rose-700 dark:text-rose-400 font-black text-sm" dir="ltr"><?= $resNum ?></strong></span>
                <span>•</span>
                <span>العقد الأصلي رقم: <strong class="inline-block font-mono text-slate-950 dark:text-white font-black text-sm" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></strong></span>
                <span>•</span>
                <span>تاريخ سريان الفسخ: <strong class="font-num inline-block text-slate-950 dark:text-white font-bold" dir="ltr"><?= $termDateFormatted ?></strong></span>
            </div>
        </div>

        <!-- The Parties -->
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
                العنوان: <strong class="text-slate-950 dark:text-white font-bold"><?= htmlspecialchars(!empty($contract['driver_address']) ? $contract['driver_address'] : (!empty($contract['driver_wilaya']) ? $contract['driver_wilaya'] : 'أدرار')) ?></strong>.
            </div>
            <div class="border-t border-slate-200 dark:border-slate-800 pt-3 leading-relaxed text-xs sm:text-sm text-slate-700 dark:text-slate-300">
                <strong class="font-black text-slate-900 dark:text-slate-200">بيانات سيارة الأجرة المعنية:</strong> 
                <?php if (!empty($contract['plate_number'])): ?>
                    الصانع والنوع: <strong class="text-slate-950 dark:text-white font-black text-sm"><?= htmlspecialchars(($contract['brand'] ?? '') . ' ' . ($contract['model'] ?? '')) ?></strong> • 
                    رقم اللوحة: <strong class="font-num font-mono inline-block text-slate-950 dark:text-white font-black text-sm px-2 py-0.5 bg-amber-50 dark:bg-amber-950/40 rounded border border-amber-300 dark:border-amber-700" dir="ltr"><?= htmlspecialchars($contract['plate_number']) ?></strong> • 
                    رقم الباب المعتمد (DTW): <strong class="font-num inline-block text-slate-950 dark:text-white font-black text-sm px-2 py-0.5 bg-slate-100 dark:bg-slate-800 rounded border border-slate-300 dark:border-slate-700" dir="ltr"><?= htmlspecialchars($contract['door_number']) ?></strong>.
                <?php else: ?>
                    <strong class="text-amber-800 dark:text-amber-300 font-black">مركبة الأسطول: بدون مركبة مخصصة حالياً</strong>
                <?php endif; ?>
            </div>
        </div>

        <!-- Preamble -->
        <p class="text-justify text-xs sm:text-sm font-medium leading-relaxed">
            <strong class="font-black text-slate-950 dark:text-white">تمهيد:</strong> بناءً على أحكام القانون رقم 90-11 المؤرخ في 21 أفريل 1990 المتعلق بعلاقات العمل، المعدل والمتمم، والمرسوم التنفيذي المحدد لشروط وقواعد استغلال سيارات الأجرة ودفتر الشروط الصادر عن وزارة النقل، وبناءً على عقد العمل المبرم بين الطرفين بتاريخ <strong class="font-num font-bold" dir="ltr"><?= $startDateFormatted ?></strong> تحت رقم <strong class="font-mono font-bold" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></strong>، اجتمع الطرفان بكامل أهليتهما القانونية وتم الاتفاق على إنهاء وفسخ علاقة العمل طبقاً للبنود والشروط القانونية التالية:
        </p>

        <!-- Official Termination Articles (بنود الفسخ) -->
        <div class="space-y-4 text-xs sm:text-sm text-justify leading-relaxed">
            <div>
                <strong class="font-black text-slate-950 dark:text-white text-sm">البند 01 (موضوع الفسخ):</strong>
                يقر الطرفان صراحةً ونهائياً بوضع حد لعلاقة العمل التعاقدية الرابطة بينهما، وفسخ عقد العمل النموذجي لسائق سيارة الأجرة رقم <strong class="font-mono font-bold" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></strong> ابتداءً من تاريخ <strong class="font-num font-bold" dir="ltr"><?= $termDateFormatted ?></strong>.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white text-sm">البند 02 (السند القانوني وسبب الفسخ):</strong>
                تم إقرار هذا الفسخ رسمياً استناداً إلى الأساس القانوني والمبرر الآتي:
                <div class="p-3 my-1.5 rounded-xl bg-rose-50/70 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 font-black text-rose-950 dark:text-rose-200">
                    <?= htmlspecialchars($clauseTitle) ?>
                    <?php if (!empty($terminationNotes)): ?>
                        <div class="font-normal text-xs text-rose-800 dark:text-rose-300 mt-1">
                            سند وملاحظات الفسخ: <?= nl2br(htmlspecialchars($terminationNotes)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white text-sm">البند 03 (استرداد المركبة والوثائق):</strong>
                يصرح السائق (الطرف الثاني) ويثبت أنه قام بإعادة وتسليم مركبة سيارة الأجرة بكامل تجهيزاتها الرسمية ودفتر المقررة والشارة وبطاقة الوقود لإدارة المؤسسة بحالة مطابقة لمحضر الاستلام، مع إلغاء تخصيص المركبة في سجل الأسطول المعتمد.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white text-sm">البند 04 (تصفية المستحقات وبراءة الذمة):</strong>
                تمت التصفية المحاسبية والإدارية الشاملة لكافة المستحقات المالية والاشتراكات المتبادلة بين الطرفين حتى تاريخ سريان هذا الفسخ. ويقر كلا الطرفين ببراءة ذمة كل منهما تجاه الآخر براءة تامة ونهائية لا رجعة فيها، دون أي تعويض مالي إضافي من أي نوع.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white text-sm">البند 05 (السريان والإخطار الرسمي):</strong>
                يسري مفعول هذا الفسخ ابتداءً من <strong class="font-num font-bold" dir="ltr"><?= $termDateFormatted ?></strong>، وتتولى المؤسسة المستخدمة إخطار مفتشية العمل ومصالح مديرية النقل (DTW) ومصالح الصندوق الوطني للتأمينات الاجتماعية (CNAS) بنسخة من هذا المحضر لشطب انتساب السائق نظامياً.
            </div>

            <div>
                <strong class="font-black text-slate-950 dark:text-white text-sm">البند 06 (القبول والمصادقة):</strong>
                حرر هذا المحضر في نسختين أصليتين، بيد كل طرف نسخة للعمل والاحتجاج بها عند الاقتضاء، وصرح الطرفان بأنهما اطلعا على كافة بنوده ووافقا عليها بعد القراءة والمصادقة.
            </div>
        </div>

        <!-- Official Signatures Box -->
        <div class="pt-8 border-t-2 border-slate-900 dark:border-slate-700 grid grid-cols-2 gap-12 text-center text-xs sm:text-sm">
            <div class="space-y-14">
                <span class="font-black text-slate-950 dark:text-slate-100 text-sm sm:text-base">توقيع وبصمة السائق (الطرف الثاني)</span>
                <div class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-bold italic">قرئ وصودق عليه</div>
            </div>

            <div class="space-y-10">
                <span class="font-black text-slate-950 dark:text-slate-100 text-sm sm:text-base">تأشيرة وختم مؤسسة الطاكسي نقيلو</span>
                <div class="w-20 h-20 rounded-full border-2 border-dashed border-rose-500 mx-auto flex items-center justify-center text-xs text-rose-800 dark:text-rose-400 font-black bg-rose-50/50 dark:bg-rose-950/20">
                    موثق رسمياً
                </div>
            </div>
        </div>

    </div>

    <?php else: ?>
    <!-- ============================================================== -->
    <!-- TAB 2: OFFICIAL TRANSMISSION BORDEREAU FOR DTW (بوردرورو الفسخ) -->
    <!-- ============================================================== -->
    <div class="contract-paper max-w-4xl mx-auto bg-white dark:bg-[#161d27] p-8 sm:p-12 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-md space-y-6 text-slate-900 dark:text-slate-100 text-xs sm:text-sm leading-relaxed">
        
        <!-- DTW Bordereau Republic Header -->
        <div class="border-b-2 border-slate-900 dark:border-slate-700 pb-5 space-y-3">
            <div class="flex items-center justify-between gap-4">
                <img src="<?= url('/logo.webp') ?>" alt="EURL TAXI NAGUILOU" class="w-20 h-20 sm:w-24 sm:h-24 rounded-full object-cover border-2 border-amber-400 dark:border-amber-500 shadow-md shrink-0 p-1 bg-white">
                <div class="text-center space-y-1 flex-1">
                    <h1 class="text-sm sm:text-lg font-black text-slate-950 dark:text-white">الجمهورية الجزائرية الديمقراطية الشعبية</h1>
                    <h2 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-200">وزارة النقل — مديرية النقل لولاية أدرار (DTW)</h2>
                    <h3 class="text-xs font-black text-amber-700 dark:text-amber-400">مؤسسة الطاكسي نقيلو لخدمات سيارات الأجرة</h3>
                </div>
                <div class="w-28 text-center text-xs text-slate-600 dark:text-slate-400 font-num shrink-0">
                    <div class="font-bold">اعتماد DTW</div>
                    <div class="font-black text-slate-950 dark:text-white text-sm" dir="ltr"><?= $dtwNumber ?>/<?= $dtwYear ?></div>
                </div>
            </div>

            <!-- Title & Metadata -->
            <div class="text-center pt-2">
                <span class="inline-block px-6 py-2 border-2 border-slate-950 dark:border-slate-500 rounded-xl font-black text-sm sm:text-base text-slate-950 dark:text-white bg-slate-50 dark:bg-slate-800/60 shadow-xs">
                    جدول إرسال رسمي: إخطار بفسخ عقد عمل سائق وشطب الانتساب
                </span>
                <div class="text-[11px] text-slate-500 dark:text-slate-400 font-num mt-1" dir="ltr">Bordereau d'Envoi — Résiliation de Contrat de Travail & Radiation DTW</div>
            </div>

            <div class="flex items-center justify-between text-xs text-slate-700 dark:text-slate-300 font-num pt-2 border-t border-slate-200 dark:border-slate-800 flex-wrap gap-2">
                <span>رقم جدول الإرسال: <strong class="inline-block font-mono text-slate-950 dark:text-white font-black text-sm" dir="ltr"><?= $slipNum ?></strong></span>
                <span>تاريخ التحرير: <strong class="font-num inline-block text-slate-950 dark:text-white font-bold" dir="ltr"><?= date('Y-m-d') ?></strong></span>
            </div>
        </div>

        <!-- Recipient & Subject Box -->
        <div class="space-y-3 bg-slate-50 dark:bg-[#1a2332] p-5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <span class="font-bold text-slate-500 dark:text-slate-400 block mb-0.5">الجهة المرسل إليها (Destinataire):</span>
                    <strong class="text-slate-950 dark:text-white font-black text-sm block">
                        السيد / مدير النقل لولاية <?= htmlspecialchars($company['wilaya_clean']) ?> (DTW)
                    </strong>
                    <span class="text-xs text-slate-600 dark:text-slate-300">مصلحة استغلال سيارات الأجرة ونقل الأشخاص</span>
                </div>
                <div>
                    <span class="font-bold text-slate-500 dark:text-slate-400 block mb-0.5">الجهة الباعثة (Expéditeur):</span>
                    <strong class="text-slate-950 dark:text-white font-black text-sm block">
                        <?= htmlspecialchars($company['name']) ?>
                    </strong>
                    <span class="text-xs text-slate-600 dark:text-slate-300">الهاتف: <?= htmlspecialchars(!empty($company['phone']) ? $company['phone'] : (!empty($contract['owner_phone']) ? $contract['owner_phone'] : '0663.55.00.42')) ?> • المقر: <?= htmlspecialchars($company['address']) ?></span>
                </div>
            </div>

            <div class="border-t border-slate-200 dark:border-slate-800 pt-3">
                <span class="font-bold text-slate-700 dark:text-slate-300">الموضوع:</span> 
                <strong class="text-slate-950 dark:text-white font-black">
                    إخطار رسمي بفسخ عقد عمل نموذجي لسائق سيارة أجرة وشطب انتسابه بالأسطول
                </strong>
            </div>

            <div>
                <span class="font-bold text-slate-700 dark:text-slate-300">المرجع:</span> 
                <span class="text-slate-800 dark:text-slate-200">
                    أحكام القانون رقم 90-11 المؤرخ في 21 أفريل 1990، وعقد العمل الموثق برقم <strong class="font-mono font-bold" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></strong>.
                </span>
            </div>
        </div>

        <!-- Official Bordereau Table -->
        <div class="overflow-x-auto rounded-xl border border-slate-300 dark:border-slate-700">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-100 dark:bg-slate-800 font-bold text-slate-800 dark:text-slate-200 border-b border-slate-300 dark:border-slate-700">
                    <tr>
                        <th class="p-3 text-center w-12 font-num">الرقم</th>
                        <th class="p-3">بيانات السائق المشطوب</th>
                        <th class="p-3">المركبة ورقم الباب</th>
                        <th class="p-3">سند وسبب الفسخ</th>
                        <th class="p-3 text-center">تاريخ السريان</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700 font-medium">
                    <tr>
                        <td class="p-3 text-center font-num font-bold">01</td>
                        <td class="p-3 space-y-1">
                            <strong class="text-slate-950 dark:text-white font-black text-sm block"><?= htmlspecialchars($contract['driver_name']) ?></strong>
                            <div class="text-[11px] text-slate-600 dark:text-slate-400 font-num">
                                رخصة السياقة: <strong class="font-mono font-bold text-slate-900 dark:text-slate-100" dir="ltr"><?= htmlspecialchars($contract['driver_license'] ?? 'مسجل بالملف') ?></strong>
                            </div>
                            <div class="text-[11px] text-slate-600 dark:text-slate-400 font-num">
                                الهاتف: <span dir="ltr"><?= htmlspecialchars($contract['driver_phone']) ?></span>
                            </div>
                        </td>
                        <td class="p-3 space-y-1">
                            <?php if (!empty($contract['plate_number'])): ?>
                                <span class="table-num-badge font-mono font-bold text-xs" dir="ltr"><?= htmlspecialchars($contract['plate_number']) ?></span>
                                <div class="text-[11px] text-slate-600 dark:text-slate-400">
                                    الصانع: <?= htmlspecialchars(($contract['brand'] ?? '') . ' ' . ($contract['model'] ?? '')) ?>
                                </div>
                                <div class="text-[11px] font-bold text-amber-800 dark:text-amber-400 font-num">
                                    رقم الباب (DTW): <?= htmlspecialchars($contract['door_number']) ?>
                                </div>
                            <?php else: ?>
                                <span class="text-slate-400 font-bold">بدون مركبة حالياً</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 space-y-1">
                            <span class="inline-block px-2 py-0.5 rounded bg-rose-100 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 font-black text-xs">
                                <?= htmlspecialchars($clauseTitle) ?>
                            </span>
                            <?php if (!empty($terminationNotes)): ?>
                                <div class="text-[11px] text-slate-600 dark:text-slate-400">
                                    <?= htmlspecialchars($terminationNotes) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 text-center font-num font-black text-sm text-rose-700 dark:text-rose-400" dir="ltr">
                            <?= $termDateFormatted ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Enclosed Official Documents List -->
        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 space-y-2 text-xs">
            <span class="font-bold text-slate-900 dark:text-slate-100 block">الوثائق الرسمية المرفقة بجدول الإرسال:</span>
            <ul class="list-disc list-inside space-y-1 text-slate-700 dark:text-slate-300 pr-2">
                <li>نسخة أصلية ومطابقة من محضر فسخ العقد رقم <strong class="font-mono" dir="ltr"><?= $resNum ?></strong> ممضى ومصادق عليه.</li>
                <li>نسخة من رخصة السياقة للسائق المعني المشطوب.</li>
                <li>محضر استرجاع تجهيزات سيارة الأجرة ورخصة الاستغلال وبراءة الذمة.</li>
            </ul>
        </div>

        <!-- Official Signatures Box -->
        <div class="pt-8 border-t-2 border-slate-900 dark:border-slate-700 grid grid-cols-2 gap-12 text-center text-xs sm:text-sm">
            <div class="space-y-14">
                <span class="font-black text-slate-950 dark:text-slate-100 text-sm sm:text-base">تأشيرة وختم استلام مصالح مديرية النقل (DTW)</span>
                <div class="text-xs sm:text-sm text-slate-400 font-bold italic">وصل الاستلام والإيداع الرسمي</div>
            </div>

            <div class="space-y-10">
                <span class="font-black text-slate-950 dark:text-slate-100 text-sm sm:text-base">تأشيرة وختم مدير <?= htmlspecialchars($company['name']) ?></span>
                <div class="w-20 h-20 rounded-full border-2 border-dashed border-amber-500 mx-auto flex items-center justify-center text-xs text-amber-800 dark:text-amber-400 font-black bg-amber-50/50 dark:bg-amber-950/20">
                    موثق رقمياً
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

</body>
</html>
