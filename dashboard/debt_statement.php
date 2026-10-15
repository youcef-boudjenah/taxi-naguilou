<?php
// dashboard/debt_statement.php - Official Driver Financial Account Statement (كشف حساب ذمة سائق)
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = getDBConnection();

$debtId = intval($_GET['id'] ?? 0);
$userId = intval($_GET['user_id'] ?? 0);

if ($debtId <= 0 && $userId <= 0) {
    set_flash('error', 'يرجى تحديد السائق أو سجل الذمة لعرض كشف الحساب.');
    redirect('/dashboard/debts.php');
}

// Find debt and driver info
if ($debtId > 0) {
    $stmt = $pdo->prepare("
        SELECT d.*, 
               u.id as driver_user_id, u.full_name as driver_name, u.phone as driver_phone, 
               u.license_number, u.card_number, u.address as driver_address, u.wilaya as driver_wilaya,
               v.brand as veh_brand, v.model as veh_model, v.plate_number as veh_plate, v.door_number as veh_door,
               c.contract_number
        FROM driver_debts d
        LEFT JOIN users u ON d.user_id = u.id
        LEFT JOIN vehicles v ON v.current_driver_id = u.id
        LEFT JOIN contracts c ON c.driver_id = u.id AND c.status = 'active'
        WHERE d.id = ?
        LIMIT 1
    ");
    $stmt->execute([$debtId]);
    $debt = $stmt->fetch();
} else {
    $stmt = $pdo->prepare("
        SELECT d.*, 
               u.id as driver_user_id, u.full_name as driver_name, u.phone as driver_phone, 
               u.license_number, u.card_number, u.address as driver_address, u.wilaya as driver_wilaya,
               v.brand as veh_brand, v.model as veh_model, v.plate_number as veh_plate, v.door_number as veh_door,
               c.contract_number
        FROM users u
        LEFT JOIN driver_debts d ON d.user_id = u.id
        LEFT JOIN vehicles v ON v.current_driver_id = u.id
        LEFT JOIN contracts c ON c.driver_id = u.id AND c.status = 'active'
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $debt = $stmt->fetch();
}

if (!$debt) {
    set_flash('error', 'سجل الذمة المالية غير متوفر لهذا السائق.');
    redirect('/dashboard/debts.php');
}

// Permissions check
if (is_driver() && (int)($debt['driver_user_id'] ?? $debt['user_id']) !== (int)$_SESSION['user_id']) {
    set_flash('error', 'عذراً، لا يمكنك الاطلاع على كشوف حسابات السائقين الآخرين.');
    redirect('/dashboard/index.php');
}

$driverUserId = (int)($debt['driver_user_id'] ?? $debt['user_id']);
$realDebtId = (int)($debt['id'] ?? 0);

// Fetch all Subscriptions
$subsStmt = $pdo->prepare("
    SELECT id, period_month, space_fee, vehicle_fee, total_due, paid_amount, status, payment_date, payment_method, created_at
    FROM driver_subscriptions
    WHERE driver_id = ?
    ORDER BY period_month ASC, id ASC
");
$subsStmt->execute([$driverUserId]);
$subscriptions = $subsStmt->fetchAll();

// Fetch all Versements
$versements = [];
if ($realDebtId > 0) {
    $versStmt = $pdo->prepare("
        SELECT id, amount, payment_method, payment_date, receipt_ref, notes, created_at
        FROM versements
        WHERE debt_id = ?
        ORDER BY payment_date ASC, id ASC
    ");
    $versStmt->execute([$realDebtId]);
    $versements = $versStmt->fetchAll();
}

// Merge ledger entries chronologically
$ledger = [];
foreach ($subscriptions as $s) {
    $date = !empty($s['payment_date']) ? $s['payment_date'] : substr($s['created_at'], 0, 10);
    if (empty($date)) $date = $s['period_month'] . '-01';
    $ledger[] = [
        'type' => 'due',
        'date' => $date,
        'ref' => 'SUB-' . $s['period_month'] . '-' . str_pad($s['id'], 3, '0', STR_PAD_LEFT),
        'label' => 'مستحقات اشتراك شهر ' . $s['period_month'] . ' (مركبة: ' . number_format($s['vehicle_fee'], 0) . ' + فضاء: ' . number_format($s['space_fee'], 0) . ' دج)',
        'debit' => (float)$s['total_due'],
        'credit' => 0.0,
        'status' => $s['status'],
        'sub_id' => $s['id']
    ];
}

foreach ($versements as $v) {
    $date = !empty($v['payment_date']) ? $v['payment_date'] : substr($v['created_at'], 0, 10);
    $payLabel = match($v['payment_method']) {
        'baridimob' => 'بريدي موب (BaridiMob)',
        'ccp' => 'حساب بريدي جاري (CCP)',
        'virement' => 'تحويل بنكي (Virement)',
        default => 'نقداً بالصندوق (Espèces)'
    };
    $ledger[] = [
        'type' => 'payment',
        'date' => $date,
        'ref' => $v['receipt_ref'] ?: ('REC-2026-' . str_pad($v['id'], 3, '0', STR_PAD_LEFT)),
        'label' => 'دفعة تسوية مالية (' . $payLabel . ') ' . ($v['notes'] ? ' - ' . $v['notes'] : ''),
        'debit' => 0.0,
        'credit' => (float)$v['amount'],
        'status' => 'regle',
        'vers_id' => $v['id']
    ];
}

// Sort ledger entries chronologically
usort($ledger, function($a, $b) {
    $cmp = strcmp($a['date'], $b['date']);
    if ($cmp !== 0) return $cmp;
    // Debits come before payments on same day
    return ($a['type'] === 'due') ? -1 : 1;
});

// Calculate progressive balance
$runningBalance = 0.0;
$totalDebits = 0.0;
$totalCredits = 0.0;
foreach ($ledger as &$entry) {
    $totalDebits += $entry['debit'];
    $totalCredits += $entry['credit'];
    $runningBalance += ($entry['debit'] - $entry['credit']);
    $entry['balance'] = $runningBalance;
}
unset($entry);

// Fallbacks if ledger is smaller than summary table
$summaryCredits = (float)($debt['total_credits'] ?? $totalDebits);
$summaryVersements = (float)($debt['total_versements'] ?? $totalCredits);
$summaryRemaining = $summaryCredits - $summaryVersements;

$todayFormatted = date('d/m/Y');
$refCode = 'EXT-' . date('Y') . '-' . str_pad($realDebtId ?: $driverUserId, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كشف حساب ذمة سائق - <?= htmlspecialchars($debt['chauffeur_name'] ?: $debt['driver_name']) ?> | تاكسي نقيلو</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
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
                        sans: ['Cairo', 'Tajawal', 'sans-serif'],
                        num: ['Plus Jakarta Sans', 'Cairo', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <?= render_theme_head() ?>
    <style>
        html, body, input, button, select, textarea { 
            font-family: 'Cairo', 'Tajawal', sans-serif !important; 
            font-weight: 400;
            text-rendering: optimizeLegibility; 
            -webkit-font-smoothing: antialiased; 
            -moz-osx-font-smoothing: grayscale;
        }
        .font-black { font-weight: 700 !important; }
        .font-extrabold { font-weight: 650 !important; }
        .font-bold { font-weight: 600 !important; }
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
            body { background: white !important; padding: 0 !important; color: black !important; }
            .statement-sheet { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
            @page { margin: 12mm; size: A4 portrait; }
        }
    </style>
</head>
<body class="bg-slate-100 dark:bg-[#0b0f17] text-slate-900 dark:text-slate-100 min-h-screen py-8 px-4 transition-colors">

    <!-- Action Toolbar (No print) -->
    <div class="max-w-4xl mx-auto mb-6 flex items-center justify-between no-print bg-white dark:bg-[#161d27] p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs">
        <div class="flex items-center gap-3">
            <a href="debts.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
                <span>سجل ديون السائقين</span>
            </a>
            <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
                <span>التحليلات والمؤشرات</span>
            </a>
        </div>
        <div class="flex items-center gap-2">
            <?php if (!empty($debt['phone']) || !empty($debt['driver_phone'])): 
                $cleanPhone = preg_replace('/[^0-9]/', '', $debt['phone'] ?: $debt['driver_phone']);
                if (str_starts_with($cleanPhone, '0')) $cleanPhone = '213' . substr($cleanPhone, 1);
            ?>
            <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= urlencode('السلام عليكم الأخ ' . ($debt['chauffeur_name'] ?: $debt['driver_name']) . '، تذكير بخصوص كشف حساب ذمتكم لدى مؤسسة تاكسي نقيلو، الرصيد المستحق: ' . number_format($summaryRemaining, 2) . ' دج. شكراً لتعاونكم.') ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition shadow-xs">
                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.698c.969.587 1.961.948 3.125.948h.004c3.18 0 5.767-2.586 5.768-5.766.001-3.18-2.586-5.766-5.768-5.766zm-7.653 5.766c.001-4.225 3.439-7.662 7.664-7.662 4.224 0 7.662 3.438 7.662 7.662 0 4.226-3.438 7.664-7.662 7.664-1.353 0-2.637-.36-3.76-1.025l-4.17 1.093 1.113-4.068c-.768-1.229-1.205-2.646-1.205-4.068z" />
                </svg>
                <span>إرسال عبر واتساب</span>
            </a>
            <?php endif; ?>
            <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-900 font-bold text-xs shadow-xs transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.672-2.126-1.27-3.102m11.1 3.102c.24-1.076.672-2.126 1.27-3.102m-7.202-3.102C10.74 7.29 11.36 7 12 7c.64 0 1.26.29 1.452.727M6 18h12a2 2 0 002-2v-4a2 2 0 00-2-2H6a2 2 0 00-2 2v4a2 2 0 002 2zm1-8V5a2 2 0 012-2h6a2 2 0 012 2v5" />
                </svg>
                <span>طباعة الكشف الرسمي</span>
            </button>
        </div>
    </div>

    <!-- Official Statement Sheet (A4 format) -->
    <div class="statement-sheet max-w-4xl mx-auto bg-white dark:bg-[#161d27] p-8 sm:p-10 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm transition-colors text-slate-800 dark:text-slate-100">
        
        <!-- Document Header -->
        <div class="border-b-2 border-slate-900 dark:border-slate-700 pb-5 mb-6">
            <div class="text-center mb-4">
                <p class="text-xs font-bold text-slate-500 dark:text-slate-400">الجمهورية الجزائرية الديمقراطية الشعبية</p>
                <p class="text-[11px] text-slate-400 dark:text-slate-500">RÉPUBLIQUE ALGÉRIENNE DÉMOCRATIQUE ET POPULAIRE</p>
            </div>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-amber-500 flex items-center justify-center text-slate-950 font-black text-xl shadow-xs">
                        TN
                    </div>
                    <div>
                        <h1 class="text-lg font-black text-slate-950 dark:text-white leading-tight">شركة تاكسي نقيلو ش.ذ.م.م</h1>
                        <p class="text-xs text-slate-600 dark:text-slate-400">EURL TAXI NAGUILOU - حظيرة سيارات الأجرة</p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-500 mt-0.5">المقر الاجتماعي: حي النصر، ورقلة - الجزائر</p>
                    </div>
                </div>
                <div class="text-left font-num text-[11px] text-slate-600 dark:text-slate-400 border-r border-slate-200 dark:border-slate-800 pr-4">
                    <div><span class="font-bold text-slate-700 dark:text-slate-300">RC :</span> 30/00-0982341B22</div>
                    <div><span class="font-bold text-slate-700 dark:text-slate-300">NIF :</span> 002230098234185</div>
                    <div><span class="font-bold text-slate-700 dark:text-slate-300">NIS :</span> 002230010048123</div>
                    <div><span class="font-bold text-slate-700 dark:text-slate-300">Art. Imp :</span> 30010928341</div>
                </div>
            </div>
        </div>

        <!-- Statement Title & Reference Banner -->
        <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/40 rounded-xl p-4 mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <span class="inline-block px-2.5 py-0.5 bg-amber-500 text-slate-950 text-[10px] font-black rounded-md uppercase tracking-wider mb-1">وثيقة مالية رسمية</span>
                <h2 class="text-lg font-black text-slate-900 dark:text-white">كشف حساب الذمة المالية للسائق (Relevé de Compte)</h2>
                <p class="text-xs text-slate-600 dark:text-slate-400">جدول متابعة الاشتراكات المستحقة والدفعات المسجلة بحساب السائق</p>
            </div>
            <div class="text-left font-num text-xs">
                <div class="font-bold text-slate-900 dark:text-white">المرجع: <span class="text-amber-700 dark:text-amber-400 font-mono"><?= $refCode ?></span></div>
                <div class="text-slate-500 text-[11px] mt-0.5">تاريخ الإصدار: <span class="font-semibold text-slate-700 dark:text-slate-300"><?= $todayFormatted ?></span></div>
                <div class="text-slate-500 text-[11px]">السنة المالية: <span class="font-semibold text-slate-700 dark:text-slate-300">2026</span></div>
            </div>
        </div>

        <!-- Driver & Operational Identifiers Box -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-slate-50 dark:bg-[#121824] border border-slate-200 dark:border-slate-800 rounded-xl p-4">
                <div class="text-xs font-bold text-amber-600 dark:text-amber-400 mb-2 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                    <span>هوية وبيانات السائق المكلف</span>
                </div>
                <div class="space-y-1.5 text-xs">
                    <div class="flex justify-between">
                        <span class="text-slate-500 dark:text-slate-400">الاسم واللقب:</span>
                        <span class="font-black text-slate-900 dark:text-white"><?= htmlspecialchars($debt['chauffeur_name'] ?: $debt['driver_name']) ?></span>
                    </div>
                    <div class="flex justify-between font-num">
                        <span class="text-slate-500 dark:text-slate-400">رقم الهاتف:</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200" dir="ltr"><?= htmlspecialchars($debt['phone'] ?: $debt['driver_phone'] ?: 'غير محدد') ?></span>
                    </div>
                    <div class="flex justify-between font-num">
                        <span class="text-slate-500 dark:text-slate-400">رخصة السياقة:</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200" dir="ltr"><?= htmlspecialchars($debt['license_number'] ?: '01/21/XXXXX') ?></span>
                    </div>
                    <div class="flex justify-between font-num">
                        <span class="text-slate-500 dark:text-slate-400">البطاقة المهنية:</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200" dir="ltr"><?= htmlspecialchars($debt['card_number'] ?: '1099XXXXX') ?></span>
                    </div>
                </div>
            </div>

            <div class="bg-slate-50 dark:bg-[#121824] border border-slate-200 dark:border-slate-800 rounded-xl p-4">
                <div class="text-xs font-bold text-amber-600 dark:text-amber-400 mb-2 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    <span>المركبة والعقد الملحق</span>
                </div>
                <div class="space-y-1.5 text-xs">
                    <div class="flex justify-between">
                        <span class="text-slate-500 dark:text-slate-400">المركبة المستغلة:</span>
                        <span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars(($debt['veh_brand'] ?? '') . ' ' . ($debt['veh_model'] ?? 'مركبة الحظيرة')) ?></span>
                    </div>
                    <div class="flex justify-between font-num">
                        <span class="text-slate-500 dark:text-slate-400">رقم الباب / اللوحة:</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200" dir="ltr"><?= htmlspecialchars($debt['veh_door'] ?: '02/22-XX') ?> | <?= htmlspecialchars($debt['veh_plate'] ?: 'XXXXX-108-01') ?></span>
                    </div>
                    <div class="flex justify-between font-num">
                        <span class="text-slate-500 dark:text-slate-400">عقد قانون 90-11:</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($debt['contract_number'] ?: 'CONT-2026-NAG') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500 dark:text-slate-400">الوضعية الحالية:</span>
                        <span class="font-bold <?= $summaryRemaining > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' ?>">
                            <?= $summaryRemaining > 0 ? 'ذمة مالية جارية (غير مسوى)' : 'وضعية مسواة (على الصفر)' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-900/40 rounded-xl p-3 text-center">
                <span class="text-[11px] font-bold text-blue-700 dark:text-blue-400 block mb-0.5">إجمالي المستحقات (Total Dû)</span>
                <span class="text-lg font-black font-num text-blue-950 dark:text-blue-200" dir="ltr"><?= number_format($summaryCredits, 2) ?> <span class="text-xs font-normal">دج</span></span>
            </div>
            <div class="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900/40 rounded-xl p-3 text-center">
                <span class="text-[11px] font-bold text-emerald-700 dark:text-emerald-400 block mb-0.5">إجمالي المدفوعات (Total Versé)</span>
                <span class="text-lg font-black font-num text-emerald-950 dark:text-emerald-200" dir="ltr"><?= number_format($summaryVersements, 2) ?> <span class="text-xs font-normal">دج</span></span>
            </div>
            <div class="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/40 rounded-xl p-3 text-center">
                <span class="text-[11px] font-bold text-rose-700 dark:text-rose-400 block mb-0.5">الرصيد المتبقي بذمة السائق (Solde)</span>
                <span class="text-lg font-black font-num text-rose-950 dark:text-rose-200" dir="ltr"><?= number_format($summaryRemaining, 2) ?> <span class="text-xs font-normal">دج</span></span>
            </div>
        </div>

        <!-- Detailed Ledger Table -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider">دفتر اليومية المالية والتراكمية (Grand Livre Conducteur)</h3>
                <span class="text-[11px] font-num text-slate-500">عدد العمليات المسجلة: <?= count($ledger) ?></span>
            </div>

            <div class="overflow-x-auto border border-slate-200 dark:border-slate-800 rounded-xl">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-100 dark:bg-[#121824] text-slate-700 dark:text-slate-300 font-bold border-b border-slate-200 dark:border-slate-800">
                            <th class="py-2.5 px-3">التاريخ</th>
                            <th class="py-2.5 px-3">المرجع</th>
                            <th class="py-2.5 px-3">البيان والتفاصيل</th>
                            <th class="py-2.5 px-3 text-left font-num">مستحق (+)</th>
                            <th class="py-2.5 px-3 text-left font-num">مدفوع (-)</th>
                            <th class="py-2.5 px-3 text-left font-num">الرصيد التراكمي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 font-num">
                        <?php if (empty($ledger)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-400">لا توجد عمليات مقيدة حالياً في سجل الحساب.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($ledger as $item): ?>
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                                <td class="py-2.5 px-3 text-slate-600 dark:text-slate-400 whitespace-nowrap"><?= htmlspecialchars($item['date']) ?></td>
                                <td class="py-2.5 px-3 font-mono text-[11px] text-slate-500 whitespace-nowrap"><?= htmlspecialchars($item['ref']) ?></td>
                                <td class="py-2.5 px-3 font-sans text-slate-900 dark:text-slate-200 font-medium">
                                    <?= htmlspecialchars($item['label']) ?>
                                    <?php if (!empty($item['sub_id'])): ?>
                                    <a href="invoice.php?type=subscription&id=<?= $item['sub_id'] ?>" target="_blank" class="no-print mr-1 inline-flex items-center text-[10px] text-amber-600 hover:underline">
                                        [فاتورة]
                                    </a>
                                    <?php elseif (!empty($item['vers_id'])): ?>
                                    <a href="invoice.php?type=versement&id=<?= $item['vers_id'] ?>" target="_blank" class="no-print mr-1 inline-flex items-center text-[10px] text-emerald-600 hover:underline">
                                        [وصل قبض]
                                    </a>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-left text-slate-900 dark:text-white font-semibold">
                                    <?= $item['debit'] > 0 ? number_format($item['debit'], 2) : '-' ?>
                                </td>
                                <td class="py-2.5 px-3 text-left text-emerald-600 dark:text-emerald-400 font-semibold">
                                    <?= $item['credit'] > 0 ? number_format($item['credit'], 2) : '-' ?>
                                </td>
                                <td class="py-2.5 px-3 text-left font-bold <?= $item['balance'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600' ?>">
                                    <?= number_format($item['balance'], 2) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-slate-100/80 dark:bg-[#121824] border-t-2 border-slate-300 dark:border-slate-700 font-num font-bold">
                        <tr>
                            <td colspan="3" class="py-2.5 px-3 text-slate-900 dark:text-white font-sans text-left">المجاميع الإجمالية:</td>
                            <td class="py-2.5 px-3 text-left text-slate-950 dark:text-white"><?= number_format($totalDebits ?: $summaryCredits, 2) ?> دج</td>
                            <td class="py-2.5 px-3 text-left text-emerald-600 dark:text-emerald-400"><?= number_format($totalCredits ?: $summaryVersements, 2) ?> دج</td>
                            <td class="py-2.5 px-3 text-left <?= $summaryRemaining > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600' ?>">
                                <?= number_format($summaryRemaining, 2) ?> دج
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Official Signatures & QR Section -->
        <div class="border-t border-slate-200 dark:border-slate-800 pt-6 mt-6">
            <div class="grid grid-cols-3 gap-6 text-center text-xs">
                
                <!-- Driver signature -->
                <div class="flex flex-col justify-between h-32 border border-slate-200 dark:border-slate-800 rounded-xl p-3 bg-slate-50/50 dark:bg-[#121824]/50">
                    <span class="font-bold text-slate-700 dark:text-slate-300">إمضاء السائق بالاطلاع</span>
                    <span class="text-[10px] text-slate-400">«قرأت واطلعت وصادقت على صحة الحساب»</span>
                    <div class="border-b border-dashed border-slate-300 dark:border-slate-700 w-3/4 mx-auto mb-2"></div>
                </div>

                <!-- Verification Stamp / QR -->
                <div class="flex flex-col items-center justify-center border border-slate-200 dark:border-slate-800 rounded-xl p-3 bg-slate-50/50 dark:bg-[#121824]/50">
                    <div class="w-16 h-16 bg-white p-1 rounded-lg border border-slate-300 flex items-center justify-center mb-1">
                        <!-- SVG QR Code Icon -->
                        <svg class="w-14 h-14 text-slate-900" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm10-2h8v8h-8V2zm2 2v4h4V4h-4zM2 14h8v8H2v-8zm2 2v4h4v-4H4zm14-2h4v2h-4v-2zm-4 0h2v4h-2v-4zm2 4h2v4h-2v-4zm2 2h2v2h-2v-2zm-6 0h2v2h-2v-2zM5 5h2v2H5V5zm12 0h2v2h-2V5zM5 17h2v2H5v-2z"/>
                        </svg>
                    </div>
                    <span class="text-[10px] font-mono font-bold text-slate-600 dark:text-slate-400"><?= $refCode ?></span>
                    <span class="text-[9px] text-slate-400">وثيقة إلكترونية مؤمنة ومسجلة</span>
                </div>

                <!-- General Management cachet -->
                <div class="flex flex-col justify-between h-32 border border-slate-200 dark:border-slate-800 rounded-xl p-3 bg-slate-50/50 dark:bg-[#121824]/50">
                    <span class="font-bold text-slate-700 dark:text-slate-300">ختم وتأشيرة الإدارة العامة</span>
                    <div class="flex items-center justify-center">
                        <div class="w-16 h-16 rounded-full border-2 border-dashed border-amber-600/60 flex items-center justify-center text-[9px] text-amber-700 dark:text-amber-400 font-bold rotate-12">
                            شركة تاكسي نقيلو<br>ورقلة
                        </div>
                    </div>
                    <div class="text-[10px] text-slate-500 font-num">حرر بورقلة في: <?= $todayFormatted ?></div>
                </div>

            </div>

            <!-- Legal Footer -->
            <div class="text-center text-[10px] text-slate-400 mt-6 border-t border-slate-100 dark:border-slate-800 pt-3">
                يعتبر هذا الكشف وثيقة مرجعية لحسابات السائق لدى المؤسسة بناءً على بنود عقد العمل المؤرخ وفق القانون 90-11 والنظام الداخلي للحظيرة.
            </div>
        </div>

    </div>

</body>
</html>
