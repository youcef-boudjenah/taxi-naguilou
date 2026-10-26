<?php
// dashboard/invoice.php - Facture Officielle & Quittance de Paiement (EURL TAXI NAGUILOU)
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = getDBConnection();
$company = get_company_meta($pdo);
$invoiceId = intval($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'subscription'; // subscription, versement, debt

if (!$pdo || $invoiceId <= 0) {
    die("معرف الفاتورة أو الوصل غير صالح.");
}

$invoiceData = null;
$driver = null;
$vehicle = null;

if ($type === 'subscription') {
    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name as driver_name, u.phone as driver_phone, u.wilaya as driver_wilaya, 
               u.card_number, u.license_number, u.email as driver_email,
               v.brand, v.model, v.plate_number, v.door_number
        FROM driver_subscriptions s
        LEFT JOIN users u ON u.id = s.driver_id
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.id = s.vehicles_count)
        WHERE s.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sub) {
        $invoiceData = [
            'num' => 'FAC-' . date('Y', strtotime($sub['created_at'])) . '-' . str_pad($sub['id'], 5, '0', STR_PAD_LEFT),
            'date' => date('Y-m-d H:i', strtotime($sub['created_at'])),
            'period' => $sub['period_month'] ?? date('Y-m'),
            'driver_name' => $sub['driver_name'] ?? 'سائق معتمد',
            'driver_phone' => $sub['driver_phone'] ?? '',
            'driver_wilaya' => $sub['driver_wilaya'] ?? '16 - الجزائر',
            'card_number' => $sub['card_number'] ?? 'LIV-2026-XX',
            'license_number' => $sub['license_number'] ?? 'PERMIS-XX',
            'vehicle_brand' => $sub['brand'] ?? 'Renault',
            'vehicle_model' => $sub['model'] ?? 'Symbol',
            'plate_number' => $sub['plate_number'] ?? '00000-116-16',
            'door_number' => $sub['door_number'] ?? '01',
            'space_fee' => floatval($sub['space_fee'] ?? 350.00),
            'vehicle_fee' => floatval($sub['vehicle_fee'] ?? 4000.00),
            'total_due' => floatval($sub['total_due'] ?? 4350.00),
            'paid_amount' => floatval($sub['paid_amount'] ?? 0.00),
            'status' => $sub['status'] ?? 'en_attente',
            'payment_method' => $sub['payment_method'] ?? 'Espèces (نقداً)',
            'notes' => $sub['notes'] ?? 'اشتراك شهري نظامي لاستغلال رخصة وسيارة الأجرة'
        ];
    }
} elseif ($type === 'versement') {
    $stmt = $pdo->prepare("
        SELECT vr.*, u.full_name as driver_name, u.phone as driver_phone, u.wilaya as driver_wilaya, 
               u.card_number, u.license_number,
               v.brand, v.model, v.plate_number, v.door_number
        FROM versements vr
        LEFT JOIN users u ON u.id = vr.driver_id
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id)
        WHERE vr.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $vr = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($vr) {
        $invoiceData = [
            'num' => 'QUITT-' . date('Y', strtotime($vr['payment_date'] ?? 'now')) . '-' . str_pad($vr['id'], 5, '0', STR_PAD_LEFT),
            'date' => date('Y-m-d H:i', strtotime($vr['payment_date'] ?? 'now')),
            'period' => date('Y-m', strtotime($vr['payment_date'] ?? 'now')),
            'driver_name' => $vr['driver_name'] ?? 'سائق معتمد',
            'driver_phone' => $vr['driver_phone'] ?? '',
            'driver_wilaya' => $vr['driver_wilaya'] ?? '16 - الجزائر',
            'card_number' => $vr['card_number'] ?? 'LIV-2026-XX',
            'license_number' => $vr['license_number'] ?? 'PERMIS-XX',
            'vehicle_brand' => $vr['brand'] ?? 'Renault',
            'vehicle_model' => $vr['model'] ?? 'Symbol',
            'plate_number' => $vr['plate_number'] ?? '00000-116-16',
            'door_number' => $vr['door_number'] ?? '01',
            'space_fee' => 0.00,
            'vehicle_fee' => floatval($vr['amount'] ?? 4000.00),
            'total_due' => floatval($vr['amount'] ?? 4000.00),
            'paid_amount' => floatval($vr['amount'] ?? 4000.00),
            'status' => 'regle',
            'payment_method' => $vr['payment_method'] ?? 'Espèces',
            'notes' => 'تسديد وصل مالي رسمي لصالح مؤسسة تاكسي نقيلو'
        ];
    }
} elseif ($type === 'debt') {
    $stmt = $pdo->prepare("
        SELECT d.*, u.full_name as driver_name, u.phone as driver_phone, u.wilaya as driver_wilaya,
               u.card_number, u.license_number,
               v.brand, v.model, v.plate_number, v.door_number
        FROM driver_debts d
        LEFT JOIN users u ON u.id = d.driver_id
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id)
        WHERE d.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $dbRec = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($dbRec) {
        $invoiceData = [
            'num' => 'DETTE-' . date('Y', strtotime($dbRec['created_at'] ?? 'now')) . '-' . str_pad($dbRec['id'], 5, '0', STR_PAD_LEFT),
            'date' => date('Y-m-d H:i', strtotime($dbRec['created_at'] ?? 'now')),
            'period' => date('Y-m', strtotime($dbRec['created_at'] ?? 'now')),
            'driver_name' => $dbRec['driver_name'] ?? 'سائق معتمد',
            'driver_phone' => $dbRec['driver_phone'] ?? '',
            'driver_wilaya' => $dbRec['driver_wilaya'] ?? '16 - الجزائر',
            'card_number' => $dbRec['card_number'] ?? 'LIV-2026-XX',
            'license_number' => $dbRec['license_number'] ?? 'PERMIS-XX',
            'vehicle_brand' => $dbRec['brand'] ?? 'Renault',
            'vehicle_model' => $dbRec['model'] ?? 'Symbol',
            'plate_number' => $dbRec['plate_number'] ?? '00000-116-16',
            'door_number' => $dbRec['door_number'] ?? '01',
            'space_fee' => 0.00,
            'vehicle_fee' => floatval($dbRec['total_debt'] ?? 0.00),
            'total_due' => floatval($dbRec['total_debt'] ?? 0.00),
            'paid_amount' => floatval($dbRec['paid_amount'] ?? 0.00),
            'status' => ($dbRec['remaining_debt'] <= 0) ? 'regle' : (($dbRec['paid_amount'] > 0) ? 'partiel' : 'en_attente'),
            'payment_method' => 'سند مالي مقيد',
            'notes' => $dbRec['notes'] ?? 'كشف حساب مستحقات ودين معتمد'
        ];
    }
}

// Fallback if not found
if (!$invoiceData) {
    die("تعذر العثور على سجل الفاتورة المطلوب.");
}

$remainingBalance = max(0, $invoiceData['total_due'] - $invoiceData['paid_amount']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة وصل اشتراك | <?= htmlspecialchars($invoiceData['num']) ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!-- Production Compiled Tailwind CSS -->
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">
    <style>
        body, * { font-family: 'Cairo', sans-serif !important; }
        .font-num, [dir="ltr"] { font-family: 'Plus Jakarta Sans', sans-serif !important; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .invoice-card { box-shadow: none !important; border: none !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="min-h-full py-8 px-4 flex flex-col items-center">

    <!-- Top Action Bar (Hidden on Print) -->
    <div class="max-w-3xl w-full mb-6 flex items-center justify-between no-print">
        <a href="javascript:history.back()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition shadow-xs">
            <svg class="w-4 h-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
            </svg>
            <span>رجوع للوحة التحكم</span>
        </a>

        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-black transition shadow-xs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                </svg>
                <span>طباعة الفاتورة والوصل (Imprimer)</span>
            </button>
        </div>
    </div>

    <!-- Printable Invoice Document (A4 Container) -->
    <div class="invoice-card bg-white max-w-3xl w-full rounded-3xl border border-slate-200 shadow-xl p-8 sm:p-12 space-y-8 relative overflow-hidden">
        
        <!-- Header: Enterprise Identity & Registration -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 border-b-2 border-slate-900 pb-6">
            <div class="space-y-1.5">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-amber-500 text-slate-950 font-black flex items-center justify-center text-lg shadow-xs">
                        🚕
                    </div>
                    <div>
                        <h1 class="text-xl font-black text-slate-950 tracking-tight leading-none"><?= htmlspecialchars($company['name']) ?></h1>
                        <span class="text-xs font-extrabold text-amber-800 font-num tracking-wide" dir="ltr"><?= htmlspecialchars($company['name']) ?></span>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 font-medium leading-relaxed">
                    مؤسسة معتمدة لنقل الأشخاص واستغلال سيارات الأجرة الفردية والجماعية<br>
                    المقر الاجتماعي: <?= htmlspecialchars($company['address']) ?>
                </p>
                <div class="text-[10px] text-slate-600 font-num flex flex-wrap gap-x-4 gap-y-1 pt-1" dir="ltr">
                    <?php if (!empty($company['rc'])): ?><span><strong>RC:</strong> <?= htmlspecialchars($company['rc']) ?></span><?php endif; ?>
                    <?php if (!empty($company['nif'])): ?><span><strong>NIF:</strong> <?= htmlspecialchars($company['nif']) ?></span><?php endif; ?>
                    <?php if (!empty($company['nis'])): ?><span><strong>NIS:</strong> <?= htmlspecialchars($company['nis']) ?></span><?php endif; ?>
                    <?php if (!empty($company['article'])): ?><span><strong>Art:</strong> <?= htmlspecialchars($company['article']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="text-left bg-slate-50 p-4 rounded-2xl border border-slate-200/80 space-y-1 min-w-[200px]" dir="ltr">
                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">FACTURE / QUITTANCE</div>
                <div class="text-base font-black text-slate-950 font-num"><?= htmlspecialchars($invoiceData['num']) ?></div>
                <div class="text-xs text-slate-600 font-num font-semibold">Date: <?= htmlspecialchars($invoiceData['date']) ?></div>
                <div class="pt-1">
                    <?php if ($invoiceData['paid_amount'] >= $invoiceData['total_due']): ?>
                        <span class="px-2.5 py-1 rounded-md text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                            PAYÉ / مسدد بالكامل ✓
                        </span>
                    <?php elseif ($invoiceData['paid_amount'] > 0): ?>
                        <span class="px-2.5 py-1 rounded-md text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                            PARTIEL / مسدد جزئياً
                        </span>
                    <?php else: ?>
                        <span class="px-2.5 py-1 rounded-md text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300">
                            IMPAYÉ / غير مسدد
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Driver and Vehicle Dossier Info -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 text-xs">
            <!-- Driver Details -->
            <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-2">
                <div class="font-black text-slate-900 border-b border-slate-200/60 pb-1.5 flex items-center justify-between">
                    <span>بيانات السائق المهني</span>
                    <span class="text-[10px] text-slate-500 font-num font-bold">CHAUFFEUR</span>
                </div>
                <div class="space-y-1 text-slate-700">
                    <div class="flex justify-between"><span>الاسم واللقب:</span> <strong class="text-slate-950 font-bold"><?= htmlspecialchars($invoiceData['driver_name']) ?></strong></div>
                    <div class="flex justify-between"><span>رقم الهاتف:</span> <strong class="font-num font-semibold" dir="ltr"><?= htmlspecialchars($invoiceData['driver_phone']) ?></strong></div>
                    <div class="flex justify-between"><span>الولاية:</span> <strong class="text-slate-800"><?= htmlspecialchars($invoiceData['driver_wilaya']) ?></strong></div>
                    <div class="flex justify-between"><span>دفتر المقررة:</span> <strong class="font-num text-amber-800 font-bold" dir="ltr"><?= htmlspecialchars($invoiceData['card_number']) ?></strong></div>
                </div>
            </div>

            <!-- Vehicle Details -->
            <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-2">
                <div class="font-black text-slate-900 border-b border-slate-200/60 pb-1.5 flex items-center justify-between">
                    <span>بيانات سيارة الأجرة المستغلة</span>
                    <span class="text-[10px] text-slate-500 font-num font-bold">VÉHICULE TAXI</span>
                </div>
                <div class="space-y-1 text-slate-700">
                    <div class="flex justify-between"><span>الماركة والطراز:</span> <strong class="text-slate-950 font-bold"><?= htmlspecialchars($invoiceData['vehicle_brand'] . ' ' . $invoiceData['vehicle_model']) ?></strong></div>
                    <div class="flex justify-between"><span>رقم لوحة الترقيم:</span> <strong class="font-num font-black text-amber-900" dir="ltr"><?= htmlspecialchars($invoiceData['plate_number']) ?></strong></div>
                    <div class="flex justify-between"><span>رقم الباب الرسمي:</span> <strong class="font-num font-bold"><?= htmlspecialchars($invoiceData['door_number']) ?></strong></div>
                    <div class="flex justify-between"><span>فترة الاشتراك:</span> <strong class="font-num text-slate-900 font-semibold" dir="ltr"><?= htmlspecialchars($invoiceData['period']) ?></strong></div>
                </div>
            </div>
        </div>

        <!-- Table of Fees & Services -->
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-900 text-white font-bold">
                        <th class="p-3.5 rounded-r-xl">البيان / الخدمة (Désignation)</th>
                        <th class="p-3.5 text-center">الفترة المشمولة</th>
                        <th class="p-3.5 text-center">الكمية</th>
                        <th class="p-3.5 text-left rounded-l-xl">المبلغ (دج)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <tr>
                        <td class="p-3.5">
                            <div class="font-bold text-slate-900">اشتراك استغلال سيارة الأجرة في الأسطول</div>
                            <div class="text-[11px] text-slate-400">حق استغلال رخصة ومزاولة النشاط مع الدعم الإداري والقانوني</div>
                        </td>
                        <td class="p-3.5 text-center font-num" dir="ltr"><?= htmlspecialchars($invoiceData['period']) ?></td>
                        <td class="p-3.5 text-center font-num">1</td>
                        <td class="p-3.5 text-left font-num font-bold text-slate-900" dir="ltr"><?= number_format($invoiceData['vehicle_fee'], 2) ?> دج</td>
                    </tr>
                    <?php if ($invoiceData['space_fee'] > 0): ?>
                    <tr>
                        <td class="p-3.5">
                            <div class="font-bold text-slate-900">رسوم الفضاء</div>
                            <div class="text-[11px] text-slate-400">Space / Platform fee (350.00 DA)</div>
                        </td>
                        <td class="p-3.5 text-center font-num" dir="ltr"><?= htmlspecialchars($invoiceData['period']) ?></td>
                        <td class="p-3.5 text-center font-num">1</td>
                        <td class="p-3.5 text-left font-num font-bold text-slate-900" dir="ltr"><?= number_format($invoiceData['space_fee'], 2) ?> دج</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Financial Summary & Balance -->
        <div class="flex flex-col sm:flex-row items-end justify-between gap-6 pt-4 border-t border-slate-100">
            <div class="space-y-1.5 text-xs text-slate-600 max-w-sm">
                <span class="block font-bold text-slate-900">طريقة التسديد المعتمدة:</span>
                <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-200/80 font-bold text-slate-800">
                    <?= htmlspecialchars($invoiceData['payment_method']) ?>
                </div>
                <p class="text-[10px] text-slate-400 pt-1 leading-relaxed">
                    يعتبر هذا الوصل سند دفع قانوني يثبت سداد الحقوق والاشتراكات لمؤسسة تاكسي نقيلو وفق التشريع والتنظيم الساري المفعول.
                </p>
            </div>

            <div class="w-full sm:w-72 space-y-2 text-xs">
                <div class="flex justify-between text-slate-600">
                    <span>المجموع الإجمالي المستحق:</span>
                    <strong class="font-num text-slate-900 font-bold" dir="ltr"><?= number_format($invoiceData['total_due'], 2) ?> دج</strong>
                </div>
                <div class="flex justify-between text-emerald-700 bg-emerald-50/70 p-2 rounded-lg font-bold">
                    <span>المبلغ المسدد (Payé):</span>
                    <strong class="font-num" dir="ltr"><?= number_format($invoiceData['paid_amount'], 2) ?> دج</strong>
                </div>
                <div class="flex justify-between text-slate-900 border-t-2 border-slate-900 pt-2 font-black text-sm">
                    <span>الرصيد المتبقي (Reste):</span>
                    <strong class="font-num <?= $remainingBalance > 0 ? 'text-rose-600' : 'text-emerald-600' ?>" dir="ltr">
                        <?= number_format($remainingBalance, 2) ?> دج
                    </strong>
                </div>
            </div>
        </div>

        <!-- Official Stamp & Signatures -->
        <div class="pt-8 border-t-2 border-dashed border-slate-200 grid grid-cols-2 gap-8 text-center text-xs">
            <div class="space-y-12">
                <span class="font-bold text-slate-700 block">توقيع المستفيد / السائق</span>
                <div class="font-semibold text-slate-500"><?= htmlspecialchars($invoiceData['driver_name']) ?></div>
            </div>
            <div class="space-y-12">
                <span class="font-bold text-slate-700 block">ختم وتأشيرة المحاسبة (TAXI NAGUILOU)</span>
                <div class="text-[11px] font-black text-amber-800 font-num" dir="ltr">
                    [ CACHET OFFICIEL - PAYÉ ]
                </div>
            </div>
        </div>

    </div>

</body>
</html>
