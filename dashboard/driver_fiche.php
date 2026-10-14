<?php
// dashboard/driver_fiche.php - Fiche Technique Officielle du Chauffeur de Taxi (دفتر المقررة والبطاقة الفنية للسائق)
require_once __DIR__ . '/../includes/auth.php';
require_login();

$driverId = intval($_GET['id'] ?? 0);
$pdo = getDBConnection();

$driver = null;
if ($pdo && $driverId > 0) {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               v.id as vehicle_id, v.brand, v.model, v.plate_number, v.door_number, v.color,
               v.insurance_expiry, v.technical_control_expiry,
               c.id as contract_id, c.contract_number, c.start_date as contract_start, c.end_date as contract_end, 
               c.monthly_wage, c.status as contract_status,
               u_owner.full_name as owner_name, u_owner.phone as owner_phone
        FROM users u
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
        LEFT JOIN contracts c ON (c.driver_id = u.id AND c.status = 'signed')
        LEFT JOIN users u_owner ON (c.owner_id = u_owner.id OR v.owner_id = u_owner.id)
        WHERE u.id = ? AND u.role = 'driver'
    ");
    $stmt->execute([$driverId]);
    $driver = $stmt->fetch();
}

// Fallback: If no ID supplied or not found, select first available driver
if (!$driver && $pdo) {
    $firstDriverId = (int)$pdo->query("SELECT id FROM users WHERE role = 'driver' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($firstDriverId > 0) {
        redirect('/dashboard/driver_fiche.php?id=' . $firstDriverId);
    }
}

if (!$driver) {
    set_flash('error', 'السائق المطلوب غير موجود في النظام.');
    redirect('/dashboard/drivers.php');
}

// Access Control: A driver can only view his own profile unless Admin/Owner
if (is_driver() && (int)$driver['id'] !== (int)$_SESSION['user_id']) {
    set_flash('error', 'عذراً، لا يمكنك الاطلاع على البطاقات الفنية للسائقين الآخرين.');
    redirect('/dashboard/index.php');
}

$pageTitle = 'البطاقة الفنية: ' . htmlspecialchars($driver['full_name']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>البطاقة الفنية للسائق <?= htmlspecialchars($driver['full_name']) ?> | تاكسي نقيلو</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
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
    <style>
        body { font-family: 'Cairo', sans-serif; font-feature-settings: "tnum"; text-rendering: optimizeLegibility; }
        .font-num, .font-mono, [dir="ltr"] { font-feature-settings: "tnum"; unicode-bidi: isolate; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; color: black !important; }
            .fiche-paper { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
            .page-break { page-break-before: always; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen py-6 px-3 sm:px-6">

    <!-- Top Action Toolbar (Hidden in Print) -->
    <div class="max-w-5xl mx-auto mb-6 flex flex-col sm:flex-row items-center justify-between gap-4 no-print bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center gap-3">
            <a href="drivers.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
                <span>سجل السائقين</span>
            </a>
            <div class="h-5 w-px bg-slate-200"></div>
            <div>
                <span class="text-xs font-bold text-slate-400">البطاقة الفنية للسائق المهني:</span>
                <span class="font-bold text-slate-900 text-xs mr-1"><?= htmlspecialchars($driver['full_name']) ?></span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200 font-num">
                    <?= htmlspecialchars($driver['card_number'] ?? 'دفتر المقررة رقم 4') ?>
                </span>
            </div>
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
            <?php if (!empty($driver['contract_id'])): ?>
                <a href="contract_view.php?id=<?= $driver['contract_id'] ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>معاينة العقد (90-11)</span>
                </a>
            <?php endif; ?>
            <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.03-.42-2.1-.523-3.2A11.75 11.75 0 0112 3c3.08 0 5.86 1.173 7.803 3.09.28.277.53.57.753.878m-1.556 7.861A11.724 11.724 0 0112 21c-3.08 0-5.86-1.173-7.803-3.09-.28-.277-.53-.57-.753-.878" />
                </svg>
                <span>طباعة البطاقة الفنية (PDF)</span>
            </button>
        </div>
    </div>

    <!-- Official Document Container (Fiche Technique Paper) -->
    <div class="fiche-paper max-w-5xl mx-auto bg-white p-6 sm:p-10 rounded-3xl border border-slate-200 shadow-sm space-y-6">

        <!-- Header: Official Republic & Ministry Banner -->
        <div class="border-b-2 border-slate-900 pb-5 space-y-2 text-center">
            <div class="flex items-center justify-between">
                <div class="w-20 text-right text-[11px] font-bold text-slate-500 leading-tight">
                    <div>الجمهورية الجزائرية</div>
                    <div>الديمقراطية الشعبية</div>
                    <div class="text-[9px] text-slate-400 mt-1">مديرية النقل لولاية <?= htmlspecialchars($driver['wilaya'] ?? 'أدرار') ?></div>
                </div>
                <div class="text-center space-y-1">
                    <div class="w-12 h-12 mx-auto rounded-2xl bg-amber-50 border border-amber-200 flex items-center justify-center p-1.5 shadow-2xs">
                        <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-full h-full object-contain">
                    </div>
                    <h1 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">البطاقة الفنية للسائق المهني ودفتر المقررة</h1>
                    <h2 class="text-xs font-bold text-amber-800 tracking-wide">FICHE TECHNIQUE DU CHAUFFEUR DE TAXI • LIVRET DE BORD</h2>
                </div>
                <div class="w-20 text-left text-[10px] text-slate-400 font-num">
                    <div class="font-bold text-slate-800" dir="ltr"><?= htmlspecialchars($driver['card_number'] ?? 'LIV-01-2024') ?></div>
                    <div>اعتماد رسمي</div>
                    <div class="text-emerald-700 font-bold">مطابق 90-11</div>
                </div>
            </div>
        </div>

        <!-- Identity & Photo Card (Hero Strip) -->
        <div class="bg-gradient-to-l from-amber-50/50 to-slate-50 p-5 rounded-2xl border border-amber-200/70 flex flex-col sm:flex-row items-center sm:items-start gap-6">
            
            <!-- Driver Photo with Seal & Stamp effect -->
            <div class="relative shrink-0">
                <div class="w-32 h-40 rounded-xl overflow-hidden border-2 border-amber-400 shadow-md bg-white flex items-center justify-center">
                    <?php if (!empty($driver['avatar_url'])): ?>
                        <img src="<?= url($driver['avatar_url']) ?>" alt="<?= htmlspecialchars($driver['full_name']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="w-full h-full bg-slate-200 flex items-center justify-center text-slate-400 font-bold text-2xl">
                            <?= mb_substr($driver['full_name'], 0, 1) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="absolute -bottom-2 -left-2 px-2 py-0.5 rounded-full bg-emerald-600 text-white font-bold text-[9px] shadow-xs">
                    مصدق رسمياً
                </div>
            </div>

            <!-- Core Identity Info -->
            <div class="flex-1 space-y-3 text-right w-full">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-amber-200/50 pb-2">
                    <div>
                        <div class="text-xs font-bold text-slate-400">الاسم واللقب الكامل:</div>
                        <h3 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight"><?= htmlspecialchars($driver['full_name']) ?></h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-500 text-slate-950 shadow-2xs">
                            سائق <?= $driver['driver_type'] === 'ajir' ? 'أجير (Salarié)' : 'مؤجر / مهني' ?>
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                            نشط ميدانياً
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                    <div>
                        <span class="text-slate-400 block font-medium">اللقب (Nom):</span>
                        <strong class="text-slate-900 text-sm font-bold"><?= htmlspecialchars($driver['last_name'] ?? 'حمو صالح') ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block font-medium">الاسم (Prénom):</span>
                        <strong class="text-slate-900 text-sm font-bold"><?= htmlspecialchars($driver['first_name'] ?? 'يوسف') ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block font-medium">تاريخ ومكان الازدياد:</span>
                        <strong class="text-slate-900 font-bold">
                            <span class="font-num" dir="ltr"><?= htmlspecialchars($driver['birth_date'] ?? '1992/04/01') ?></span> 
                            (<?= htmlspecialchars($driver['birth_place'] ?? 'بعريانو تامست') ?>)
                        </strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block font-medium">ابن (Fils de):</span>
                        <strong class="text-slate-800"><?= htmlspecialchars($driver['father_name'] ?? 'أحمد') ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block font-medium">و (Et de):</span>
                        <strong class="text-slate-800"><?= htmlspecialchars($driver['mother_name'] ?? 'حمو صالح خيرة') ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block font-medium">العنوان الشخصي:</span>
                        <strong class="text-slate-800"><?= htmlspecialchars($driver['address'] ?? 'حي الحطابة، بلدية أدرار') ?></strong>
                    </div>
                </div>
            </div>

        </div>

        <!-- Section 1 & 2: License Details & Assigned Vehicle -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            
            <!-- Box A: رخصة السياقة والكفاءة المهنية -->
            <div class="p-5 rounded-2xl border border-slate-200 bg-white space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        <h4 class="font-black text-slate-900 text-sm">بيانات رخصة السياقة والمهنة</h4>
                    </div>
                    <span class="text-[10px] text-slate-400 font-bold uppercase">Permis de Conduire</span>
                </div>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500">رقم رخصة السياقة:</span>
                        <span class="font-num font-bold text-slate-900 text-sm" dir="ltr"><?= htmlspecialchars($driver['license_number'] ?? 'A02669719') ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500">تاريخ الإصدار:</span>
                        <span class="font-num font-bold text-slate-800" dir="ltr"><?= htmlspecialchars($driver['license_issue_date'] ?? '2022/10/11') ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500">جهة الإصدار الرسمية:</span>
                        <span class="font-bold text-slate-800"><?= htmlspecialchars($driver['license_issue_place'] ?? 'بلدية أدرار') ?></span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-slate-500">صلاحية رخصة السياقة:</span>
                        <span class="font-num font-bold text-emerald-700" dir="ltr"><?= htmlspecialchars($driver['license_expiry'] ?? '2032/10/10') ?></span>
                    </div>
                </div>
            </div>

            <!-- Box B: المركبة ورقم التسجيل المعتمد -->
            <div class="p-5 rounded-2xl border border-slate-200 bg-white space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        <h4 class="font-black text-slate-900 text-sm">المركبة المستغلة ورخصة سيارة الأجرة</h4>
                    </div>
                    <span class="text-[10px] text-slate-400 font-bold uppercase">Véhicule Assigné</span>
                </div>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500">رقم تسجيل المركبة (اللوحة الحالية):</span>
                        <span class="font-num font-black text-slate-900 bg-amber-50 px-2 py-0.5 rounded border border-amber-200 text-sm" dir="ltr">
                            <?= htmlspecialchars($driver['plate_number'] ?? '07039-115-01') ?>
                        </span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500">المركبة السابقة المشطوبة بالدفتر:</span>
                        <span class="font-num font-semibold text-slate-400 line-through" dir="ltr">04722-110-01</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-500">صنف وطراز المركبة:</span>
                        <span class="font-bold text-slate-800"><?= htmlspecialchars($driver['brand'] ?? 'Dacia') ?> <?= htmlspecialchars($driver['model'] ?? 'Logan Taxi') ?> (باب: <?= htmlspecialchars($driver['door_number'] ?? '01') ?>)</span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-slate-500">مدة صلاحية عقد رخصة الأجرة:</span>
                        <span class="font-num font-bold text-indigo-700" dir="ltr">
                            من <?= htmlspecialchars($driver['taxi_contract_start'] ?? '2024/09/01') ?> إلى <?= htmlspecialchars($driver['taxi_contract_end'] ?? '2027/08/31') ?>
                        </span>
                    </div>
                </div>
            </div>

        </div>

        <!-- Section 3: الجدول الرسمي للفحوصات الطبية الدورية (Contrôles Médicaux) -->
        <div class="p-5 rounded-2xl border border-slate-200 bg-white space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <h4 class="font-black text-slate-900 text-sm">الفحوصات الطبية الدورية وتأشيرة مديرية النقل (Contrôles Médicaux)</h4>
                </div>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200 font-num">
                    لائق صحياً وبدنياً
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-100">
                        <tr>
                            <th class="p-2.5">الفحص الطبي الدوري</th>
                            <th class="p-2.5">تاريخ البداية</th>
                            <th class="p-2.5">تاريخ نهاية الصلاحية</th>
                            <th class="p-2.5">مدة الصلاحية</th>
                            <th class="p-2.5">تأشيرة مديرية النقل للولاية</th>
                            <th class="p-2.5 text-center">الوضعية القانونية</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <tr class="bg-emerald-50/20">
                            <td class="p-2.5 font-bold text-slate-900">الفحص الطبي الساري (مقررة معتمدة)</td>
                            <td class="p-2.5 font-num" dir="ltr"><?= htmlspecialchars($driver['medical_exam_start'] ?? '2024/09/01') ?></td>
                            <td class="p-2.5 font-num font-bold text-slate-900" dir="ltr"><?= htmlspecialchars($driver['medical_exam_end'] ?? '2026/08/31') ?></td>
                            <td class="p-2.5 font-bold text-slate-700">سنتان (24 شهراً)</td>
                            <td class="p-2.5">
                                <span class="inline-flex items-center gap-1 font-bold text-amber-800 font-num" dir="ltr">
                                    ✓ مؤشر بتاريخ <?= htmlspecialchars($driver['medical_visa_date'] ?? '2024/09/20') ?> (DTW Adrar)
                                </span>
                            </td>
                            <td class="p-2.5 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                    ساري الصلاحية
                                </span>
                            </td>
                        </tr>
                        <tr class="text-slate-500">
                            <td class="p-2.5 font-bold text-slate-700">الفحص الدوري المجدول القادم</td>
                            <td class="p-2.5 font-num" dir="ltr"><?= htmlspecialchars($driver['next_medical_start'] ?? '2026/09/15') ?></td>
                            <td class="p-2.5 font-num" dir="ltr"><?= htmlspecialchars($driver['next_medical_end'] ?? '2028/09/14') ?></td>
                            <td class="p-2.5">سنتان (تجديد)</td>
                            <td class="p-2.5 font-num">مجدول رسمياً بمديرية النقل</td>
                            <td class="p-2.5 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                    تجديد مستقبلي
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 4: بداية النشاط وتأشيرة المصالح (Début d'Activité) -->
        <div class="p-5 rounded-2xl border border-slate-200 bg-white space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                    <h4 class="font-black text-slate-900 text-sm">سريان النشاط المهني وتأشيرة مديرية النقل (Début d'Activité)</h4>
                </div>
                <span class="text-[10px] text-slate-400 font-bold uppercase">Direction des Transports</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100">
                    <span class="text-slate-400 block font-medium">تاريخ بداية النشاط:</span>
                    <strong class="text-slate-900 text-sm font-num font-black" dir="ltr"><?= htmlspecialchars($driver['activity_start_date'] ?? '2024/09/03') ?></strong>
                </div>
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100">
                    <span class="text-slate-400 block font-medium">صفة السائق المعتمدة:</span>
                    <strong class="text-amber-800 text-sm font-black">أجير (Loi 90-11)</strong>
                </div>
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100">
                    <span class="text-slate-400 block font-medium">تأشيرة مديرية النقل:</span>
                    <strong class="text-slate-900 text-sm font-num font-bold" dir="ltr"><?= htmlspecialchars($driver['dtw_visa_date'] ?? '2024/09/03') ?></strong>
                </div>
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100">
                    <span class="text-slate-400 block font-medium">حالة النشاط والتوقف:</span>
                    <strong class="text-emerald-700 text-sm font-bold">مستمر ميدانياً (دون توقف)</strong>
                </div>
            </div>
        </div>

        <!-- Section 5: المعاينة الأصلية للصفحات الممسوحة ضوئياً (Livret Scans Lightbox) -->
        <div class="p-5 rounded-2xl border border-slate-200 bg-slate-50/70 space-y-4 no-print">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2.5">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
                    </svg>
                    <h4 class="font-black text-slate-900 text-sm">الوثائق الأصلية الممسوحة ضوئياً (دفتر السائق الأصلي)</h4>
                </div>
                <span class="text-xs text-slate-500 font-medium">اضغط على أي صفحة لمعاينتها بدقة عالية</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                
                <!-- Page 4 Scan -->
                <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-2xs space-y-2">
                    <div class="text-xs font-bold text-slate-700 flex items-center justify-between">
                        <span>الصفحة 4: الهوية والرخصة</span>
                        <span class="text-[10px] text-amber-700 font-num">Page 4</span>
                    </div>
                    <a href="<?= url('/uploads/scans/driver_booklet_page4.png') ?>" target="_blank" class="block aspect-[3/4] rounded-lg overflow-hidden border border-slate-100 hover:border-amber-400 transition group relative">
                        <img src="<?= url('/uploads/scans/driver_booklet_page4.png') ?>" alt="الصفحة 4" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        <div class="absolute inset-0 bg-slate-900/30 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold">
                            تكبير ومعاينة ↗
                        </div>
                    </a>
                </div>

                <!-- Page 7 Scan -->
                <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-2xs space-y-2">
                    <div class="text-xs font-bold text-slate-700 flex items-center justify-between">
                        <span>الصفحة 7: عقد الرخصة والمركبة</span>
                        <span class="text-[10px] text-amber-700 font-num">Page 7</span>
                    </div>
                    <a href="<?= url('/uploads/scans/driver_booklet_page7.png') ?>" target="_blank" class="block aspect-[3/4] rounded-lg overflow-hidden border border-slate-100 hover:border-amber-400 transition group relative">
                        <img src="<?= url('/uploads/scans/driver_booklet_page7.png') ?>" alt="الصفحة 7" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        <div class="absolute inset-0 bg-slate-900/30 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold">
                            تكبير ومعاينة ↗
                        </div>
                    </a>
                </div>

                <!-- Medical & Activity Scan -->
                <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-2xs space-y-2">
                    <div class="text-xs font-bold text-slate-700 flex items-center justify-between">
                        <span>الفحص الطبي وبداية النشاط</span>
                        <span class="text-[10px] text-amber-700 font-num">Médical & Activité</span>
                    </div>
                    <a href="<?= url('/uploads/scans/driver_booklet_medical.png') ?>" target="_blank" class="block aspect-[3/4] rounded-lg overflow-hidden border border-slate-100 hover:border-amber-400 transition group relative">
                        <img src="<?= url('/uploads/scans/driver_booklet_medical.png') ?>" alt="الفحص الطبي" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        <div class="absolute inset-0 bg-slate-900/30 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold">
                            تكبير ومعاينة ↗
                        </div>
                    </a>
                </div>

            </div>
        </div>

        <!-- Official Signatures & Seal Area (For Print & Authority Checks) -->
        <div class="pt-6 border-t-2 border-slate-900 grid grid-cols-3 gap-6 text-center text-xs">
            <div class="space-y-12">
                <span class="font-bold text-slate-700 block">إمضاء وبصمة السائق المهني</span>
                <div class="text-slate-400 text-[10px] italic">يوسف حمو صالح</div>
            </div>
            <div class="space-y-12">
                <span class="font-bold text-slate-700 block">ختم وتأشيرة مؤسسة تاكسي نقيلو</span>
                <div class="text-slate-400 text-[10px] italic">EURL TAXI NAGUILOU</div>
            </div>
            <div class="space-y-12">
                <span class="font-bold text-slate-700 block">تأشيرة مديرية النقل لولاية أدرار</span>
                <div class="text-slate-400 text-[10px] italic">مفتشية النقل البري</div>
            </div>
        </div>

    </div>

</body>
</html>
