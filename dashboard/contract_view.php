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
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        amber: {
                            50: '#fffbeb', 100: '#fef3c7', 200: '#fde68a', 300: '#fcd34d',
                            400: '#fbbf24', 500: '#f59e0b', 600: '#d97706', 700: '#b45309',
                            800: '#92400e', 900: '#78350f', 950: '#451a03',
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
    <?= render_theme_head() ?>
    <style>
        body { font-family: 'Cairo', sans-serif; font-feature-settings: "tnum"; }
        .font-num, .font-mono, [dir="ltr"] { font-feature-settings: "tnum"; unicode-bidi: isolate; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; color: black !important; }
            .contract-paper { border: none !important; box-shadow: none !important; padding: 0 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen py-8 px-4">

    <!-- Top Action Toolbar (Hidden in print) -->
    <div class="max-w-4xl mx-auto mb-6 flex items-center justify-between no-print bg-white p-4 rounded-xl border border-slate-200 shadow-xs">
        <div class="flex items-center gap-3">
            <a href="contracts.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
                <span>العودة إلى قائمة العقود</span>
            </a>
            <span class="text-xs font-bold text-slate-500">رقم العقد: <span class="font-num text-slate-900 font-mono inline-block font-bold" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></span></span>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.03-.42-2.1-.523-3.2A11.75 11.75 0 0112 3c3.08 0 5.86 1.173 7.803 3.09.28.277.53.57.753.878m-1.556 7.861A11.724 11.724 0 0112 21c-3.08 0-5.86-1.173-7.803-3.09-.28-.277-.53-.57-.753-.878" />
                </svg>
                <span>طباعة العقد الرسمي (PDF)</span>
            </button>
            <?= render_theme_toggle_btn('p-2') ?>
        </div>
    </div>

    <!-- Official Document Paper -->
    <div class="contract-paper max-w-4xl mx-auto bg-white p-8 sm:p-12 rounded-2xl border border-slate-200 shadow-md space-y-6 text-slate-900 text-xs sm:text-sm leading-relaxed">
        
        <!-- Republic Official Header with Logo -->
        <div class="border-b-2 border-slate-900 pb-5 space-y-3">
            <div class="flex items-center justify-between gap-4">
                <img src="<?= url('/logo.webp') ?>" alt="EURL TAXI NAGUILOU" class="w-16 h-16 object-contain rounded-full border border-amber-300 shadow-sm shrink-0">
                <div class="text-center space-y-1 flex-1">
                    <h1 class="text-base sm:text-lg font-bold">الجمهورية الجزائرية الديمقراطية الشعبية</h1>
                    <h2 class="text-xs sm:text-sm font-semibold text-slate-700">وزارة العمل والتشغيل والضمان الاجتماعي • وزارة النقل</h2>
                    <div class="text-[11px] font-bold text-amber-700">EURL TAXI NAGUILOU Services</div>
                </div>
                <div class="w-16 text-center text-[10px] text-slate-400 font-num shrink-0">
                    <div>اعتماد DTW</div>
                    <div class="font-bold text-slate-800" dir="ltr">16/2024</div>
                </div>
            </div>
            <div class="text-center pt-1">
                <span class="inline-block px-4 py-1 border border-slate-900 rounded font-black text-sm sm:text-base">
                    عقد عمل نموذجي لسائق سيارة أجرة (القانون 90-11)
                </span>
            </div>
            <div class="text-center text-[11px] text-slate-600 font-num">
                الرقم التسلسلي للعقد: <strong class="inline-block font-mono" dir="ltr"><?= htmlspecialchars($contract['contract_number']) ?></strong> • تاريخ الإصدار: <span class="font-num inline-block" dir="ltr"><?= date('Y-m-d') ?></span>
            </div>
        </div>

        <!-- The Parties -->
        <div class="space-y-3 bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs">
            <div>
                <strong>الطرف الأول (المستخدم / صاحب رخصة الاستغلال):</strong><br>
                السيد/المؤسسة: <strong class="text-slate-900"><?= htmlspecialchars($contract['owner_name']) ?></strong>، 
                الهاتف: <span class="font-num inline-block" dir="ltr"><?= htmlspecialchars($contract['owner_phone']) ?></span>، 
                المقيم بـ: <?= htmlspecialchars($contract['owner_wilaya']) ?>.
            </div>
            <div class="border-t border-slate-200 pt-2">
                <strong>الطرف الثاني (العامل / سائق سيارة الأجرة):</strong><br>
                السيد: <strong class="text-slate-900"><?= htmlspecialchars($contract['driver_name']) ?></strong>، 
                رقم رخصة السياقة: <span class="font-num font-mono inline-block" dir="ltr"><?= htmlspecialchars($contract['driver_license'] ?? 'مسجل بالملف') ?></span>، 
                الهاتف: <span class="font-num inline-block" dir="ltr"><?= htmlspecialchars($contract['driver_phone']) ?></span>، 
                المقيم بـ: <?= htmlspecialchars($contract['driver_wilaya']) ?>.
            </div>
            <div class="border-t border-slate-200 pt-2 text-[11px] text-slate-600">
                <strong>بيانات سيارة الأجرة المعنية:</strong> 
                <?php if (!empty($contract['plate_number'])): ?>
                    الصانع والنوع: <?= htmlspecialchars(($contract['brand'] ?? '') . ' ' . ($contract['model'] ?? '')) ?> • 
                    رقم اللوحة: <strong class="font-num font-mono inline-block" dir="ltr"><?= htmlspecialchars($contract['plate_number']) ?></strong> • 
                    رقم الباب المعتمد: <strong class="font-num inline-block" dir="ltr"><?= htmlspecialchars($contract['door_number']) ?></strong> • 
                    اللون: <?= htmlspecialchars($contract['color'] ?? 'أصفر') ?>.
                <?php else: ?>
                    <span class="text-amber-800 font-bold">مركبة الأسطول: قيد التخصيص والتعيين</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Preamble -->
        <p class="text-justify text-xs font-medium">
            <strong>تمهيد:</strong> بناءً على أحكام القانون رقم 90-11 المؤرخ في 21 أفريل 1990 المتعلق بعلاقات العمل، المعدل والمتمم، والمرسوم التنفيذي المحدد لشروط وقواعد استغلال سيارات الأجرة ودفتر الشروط الصادر عن وزارة النقل، اتفق الطرفان بكامل أهليتهما القانونية على البنود والمواد التالية:
        </p>

        <!-- The 16 Legal Articles (Official Company Text) -->
        <div class="space-y-3.5 text-xs text-justify leading-relaxed">
            <div>
                <strong class="font-black text-slate-900">المادة 01:</strong>
                يهدف العقد الحالي إلى تحديد مضمون علاقة العمل بين المستخدم والعامل.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 02:</strong>
                بموجب هذا العقد يتم تشغيل السيد / <strong class="text-slate-900"><?= htmlspecialchars($contract['driver_name']) ?></strong>، بصفته: سائقا أجيرا من طرف : مؤسسة تاكسي نقيلو لسيارات الأجرة لمدة زمنية محدودة تحت سلطة ومراقبة المستخدم أو المسؤول الذي يعين لغرض حسن سير المهام المسندة إليه.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 03:</strong>
                يجب على السائق الأجير بأن يصرح على أي تعديل أو طارئ على وضعيته الشخصية عند توقيع العقد.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 04:</strong>
                تم إبرام العقد لمدة محددة طبقا لأحكام المادة 12 من القانون 11/90 المؤرخ في 21 أفريل 1990 المتعلق بعلاقات العمل والمعدلة والمتممة بالمادة 02 من الأمر رقم 21/96 وفقا لاحتياجات المؤسسة المستخدمة.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 05:</strong>
                توظيف السائق لتنفيذ عمل مرتبط بعقود أشغال أو خدمات غير متجددة.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 06:</strong>
                تم إبرام هذا العقد لمدة محددة بـ ( <strong><?= htmlspecialchars($diffYearsStr) ?></strong> ) سنوات، ويبدأ سريان مفعول العقد ابتداء من <strong class="font-num inline-block" dir="ltr"><?= $startDateFormatted ?></strong> إلى غاية <strong class="font-num inline-block" dir="ltr"><?= $endDateFormatted ?></strong>.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 07:</strong>
                اتفق كلا من الطرفين على أن علاقة العمل المبرمة بينهما تخضع السائق بالاتفاق لفترة تجريبية قصوى محددة بـ (06) أشهر، ويمكن لأحد الطرفين في هذه الفترة أن يضع حدا لعلاقة العمل دون تعويض أو إشعار مسبق.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 08:</strong>
                اتفق الطرفين على أن الأجر الذي يسدد لسائق مقابل العمل المذكور في المادة 02 ويكون بأجرة لا تقل في جميع الحالات عن الحد الأدنى المضمون للأجور وطنيا. كما أنه يتقاضاها في الأسبوع الأول من الشهر الموالي كحد أقصى.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 09:</strong>
                يحق للسائق ما أقره القانون كما يلتزم بالمقابل بما أوجبته عليه نفس الأحكام.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 10:</strong>
                يلتزم السائق باحترام النظام الداخلي للمؤسسة بعد الإطلاع عليه وقبول أحكامه وكذا النصوص التنظيمية المتعلقة بحركة المرور والنقل بسيارات الأجرة.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 11:</strong>
                يتحمل السائق قيمة الأضرار التي يكون سببا فيها أثناء تأدية مهامه بطريقة مباشرة أو غير مباشرة ويتم خصم قيمة الأضرار مباشرة من راتبه.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 12:</strong>
                عند الاستقالة يلتزم السائق فترة الأشعار المسبق بشهر واحد (01) يمكن تقليص المدة باتفاق الطرفين.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 13:</strong>
                نظرا لطبيعة نشاط المؤسسة يمكن لها وضع حدا لعلاقة العمل في حالة انتهاء المشروع أو حدوث طارئ أدى إلى عدم إمكانية مزاولة المشروع.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 14:</strong>
                يمكن إلغاء العقد عند الطلب الملح والضروري من طرف الزبون في حالة ارتكاب السائق لخطأ اتجاه الزبون في مكان العمل.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 15:</strong>
                يلتزم المستخدم في إطار احترام القواعد والإجراءات الساري بها داخل المؤسسة. يمنح للسائق الأجير جميع الحقوق المعترف بها لسائقي المؤسسة من طرف التشريع والتنظيم والقانون الداخلي.
            </div>

            <div>
                <strong class="font-black text-slate-900">المادة 16:</strong>
                صرح السائق الممضي أسفله أنه اطلع على القانون الداخلي المطبق داخل المؤسسة وقبل بأحكامه.
            </div>
        </div>

        <!-- Official Signatures Box -->
        <div class="pt-8 border-t-2 border-slate-900 grid grid-cols-3 gap-6 text-center text-xs">
            <div class="space-y-12">
                <span class="font-bold">توقيع وبصمة السائق (الطرف الثاني)</span>
                <div class="text-[11px] text-slate-400 italic">قرئ وصودق عليه</div>
            </div>

            <div class="space-y-12">
                <span class="font-bold">تأشيرة وختم منصة تاكسي نقيلو</span>
                <div class="w-16 h-16 rounded-full border-2 border-dashed border-amber-500 mx-auto flex items-center justify-center text-[10px] text-amber-700 font-bold">
                    موثق رقمياً
                </div>
            </div>

            <div class="space-y-12">
                <span class="font-bold">ختم وتوقيع المستخدم (الطرف الأول)</span>
                <div class="text-[11px] text-slate-400 italic">قرئ وصودق عليه</div>
            </div>
        </div>

    </div>

</body>
</html>
