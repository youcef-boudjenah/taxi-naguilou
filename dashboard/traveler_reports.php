<?php
// dashboard/traveler_reports.php - Inter-Wilaya Traveler Reports & Manifests (PV de Voyageurs)
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = getDBConnection();
$message = null;
$messageType = null;

// Handle Print View (Clean Official Printable Manifest)
if (isset($_GET['print_id']) && $pdo) {
    $printId = intval($_GET['print_id']);
    $stmtP = $pdo->prepare("
        SELECT r.*, v.plate_number, v.brand, v.model, v.door_number, v.color,
               u.full_name as driver_name, u.phone as driver_phone, u.license_number
        FROM traveler_reports r
        JOIN vehicles v ON r.vehicle_id = v.id
        JOIN users u ON r.driver_id = u.id
        WHERE r.id = ?
    ");
    $stmtP->execute([$printId]);
    $report = $stmtP->fetch();

    if ($report) {
        $stmtPass = $pdo->prepare("SELECT * FROM traveler_passengers WHERE report_id = ? ORDER BY seat_number ASC");
        $stmtPass->execute([$printId]);
        $passengers = $stmtPass->fetchAll();
        $settings = get_all_settings($pdo);
        ?>
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>محضر نقل المسافرين الرسمي - <?= htmlspecialchars($report['report_number']) ?></title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Alexandria:wght@400;500;600;700;800;900&family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
            <script src="https://cdn.tailwindcss.com"></script>
            <?= render_theme_head() ?>
            <style>
                html, body, input, button, select, textarea { 
                    font-family: 'Alexandria', 'Cairo', 'Tajawal', sans-serif; 
                    text-rendering: optimizeLegibility; 
                    -webkit-font-smoothing: antialiased; 
                }
                html[dir="rtl"], [dir="rtl"], h1, h2, h3, h4, h5, h6, p, span, a, button, label, input, td, th {
                    letter-spacing: normal !important;
                }
                .font-num, .font-mono, [dir="ltr"] { 
                    font-family: 'Plus Jakarta Sans', 'Alexandria', 'Cairo', sans-serif !important; 
                    font-feature-settings: "tnum" 1; 
                    font-variant-numeric: tabular-nums; 
                    unicode-bidi: isolate; 
                }
                @media print {
                    .no-print { display: none !important; }
                    body { background: white !important; margin: 0 !important; padding: 0 !important; }
                }
            </style>
        </head>
        <body class="bg-slate-100 p-4 sm:p-8 text-slate-900">
            <div class="max-w-3xl mx-auto bg-white p-8 rounded-2xl shadow-xl border border-slate-200">
                <div class="flex items-center justify-between no-print mb-6 pb-4 border-b border-slate-100">
                    <a href="traveler_reports.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-slate-900 transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                        <span>العودة إلى القائمة</span>
                    </a>
                    <button onclick="window.print()" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs rounded-xl shadow-xs transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                        </svg>
                        <span>طباعة المحضر الرسمي</span>
                    </button>
                </div>

                <!-- Official Header -->
                <div class="text-center pb-6 border-b-2 border-slate-900 space-y-1">
                    <div class="text-sm font-bold text-slate-600">الجمهورية الجزائرية الديمقراطية الشعبية</div>
                    <div class="text-xs font-bold text-slate-500">وزارة النقل — مديرية النقل البري</div>
                    <h1 class="text-lg font-black text-slate-900 pt-2">محضر وقائمة ركاب سيارة الأجرة ما بين الولايات</h1>
                    <div class="text-xs font-bold text-amber-600 font-mono tracking-wider">FEUILLE DE ROUTE & PV DE TRANSPORT VOYAGEURS</div>
                    <div class="text-xs font-bold text-slate-700 pt-1">رقم المحضر: <span class="font-mono text-slate-950 font-black inline-block" dir="ltr"><?= htmlspecialchars($report['report_number']) ?></span></div>
                </div>

                <!-- Company & Trip Meta -->
                <div class="grid grid-cols-2 gap-4 my-6 text-xs bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <div>
                        <span class="text-slate-500 block">المؤسسة المشغلة:</span>
                        <strong class="text-slate-900 text-sm"><?= htmlspecialchars($settings['company_name'] ?? 'EURL TAXI NAGUILOU') ?></strong>
                        <div class="text-[11px] text-slate-600 mt-1">سجل تجاري: <span class="font-mono" dir="ltr"><?= htmlspecialchars($settings['legal_rc'] ?? '-') ?></span></div>
                    </div>
                    <div>
                        <span class="text-slate-500 block">الخط المرخص به:</span>
                        <strong class="text-slate-900 text-sm"><?= htmlspecialchars($report['line_name']) ?></strong>
                        <div class="text-[11px] text-slate-600 mt-1">انطلاق: <?= htmlspecialchars($report['departure_station']) ?></div>
                    </div>
                </div>

                <!-- Driver & Vehicle Details -->
                <div class="grid grid-cols-3 gap-3 text-xs mb-6 p-4 rounded-xl border border-slate-200">
                    <div>
                        <span class="text-slate-400 block text-[10px]">السائق المعتمد:</span>
                        <strong class="text-slate-900"><?= htmlspecialchars($report['driver_name']) ?></strong>
                        <div class="text-[10px] text-slate-600">رخصة: <span class="font-mono" dir="ltr"><?= htmlspecialchars($report['license_number'] ?? '-') ?></span></div>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px]">المركبة ولوحة الترقيم:</span>
                        <strong class="text-slate-900"><?= htmlspecialchars($report['brand'] . ' ' . $report['model']) ?></strong>
                        <div class="text-[10px] text-slate-800 font-bold font-mono" dir="ltr"><?= htmlspecialchars($report['plate_number']) ?></div>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px]">تاريخ وتوقيت الرحلة:</span>
                        <strong class="text-slate-900 font-num inline-block" dir="ltr"><?= htmlspecialchars($report['departure_date']) ?></strong>
                        <div class="text-[10px] text-slate-700">الساعة: <span class="font-num inline-block" dir="ltr"><?= htmlspecialchars($report['departure_time']) ?></span></div>
                    </div>
                </div>

                <!-- Passengers Manifest Table -->
                <div class="mb-6">
                    <h3 class="text-xs font-black text-slate-900 mb-2">قائمة المسافرين المحمولة (Manifeste des Voyageurs):</h3>
                    <table class="w-full text-right text-xs border border-slate-200">
                        <thead>
                            <tr class="bg-slate-100 border-b border-slate-200 text-slate-700">
                                <th class="p-2 border-l border-slate-200 text-center w-12">المقعد</th>
                                <th class="p-2 border-l border-slate-200">الاسم واللقب للمسافر</th>
                                <th class="p-2 border-l border-slate-200">رقم بطاقة التعريف الوطنية (CNI)</th>
                                <th class="p-2">رقم الهاتف</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php foreach ($passengers as $p): ?>
                                <tr>
                                    <td class="p-2.5 text-center font-bold border-l border-slate-200"><?= $p['seat_number'] ?></td>
                                    <td class="p-2.5 font-bold border-l border-slate-200"><?= htmlspecialchars($p['passenger_name']) ?></td>
                                    <td class="p-2.5 font-mono border-l border-slate-200" dir="ltr"><?= htmlspecialchars($p['passenger_cni']) ?></td>
                                    <td class="p-2.5 font-mono" dir="ltr"><?= htmlspecialchars($p['phone'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Signatures -->
                <div class="grid grid-cols-2 gap-8 text-center text-xs pt-8 border-t border-slate-200">
                    <div>
                        <p class="font-bold text-slate-700 mb-12">توقيع وبصمة السائق المعتمد</p>
                        <div class="text-[10px] text-slate-400">...................................................</div>
                    </div>
                    <div>
                        <p class="font-bold text-slate-700 mb-12">ختم وتأشيرة مكتب الاستغلال / المحطة</p>
                        <div class="text-[10px] text-slate-400">...................................................</div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Main Dashboard Layout
$pageTitle = 'محاضر ودفاتر المسافرين (PV de Voyageurs)';
require_once __DIR__ . '/../includes/header.php';

// Handle Add New Trip Manifest
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf_token();
    if (!is_admin()) {
        $message = 'عذراً، فقط إدارة المنصة مخولة بإصدار محاضر المسافرين الرسمية.';
        $messageType = 'error';
    } else {
        $vehicleId = intval($_POST['vehicle_id'] ?? 0);
        $driverId = intval($_POST['driver_id'] ?? 0);
        $lineName = trim($_POST['line_name'] ?? '');
        $depStation = trim($_POST['departure_station'] ?? '');
        $arrStation = trim($_POST['arrival_station'] ?? '');
        $depDate = $_POST['departure_date'] ?? date('Y-m-d');
        $depTime = $_POST['departure_time'] ?? date('H:i');
        $pricePerSeat = floatval($_POST['price_per_seat'] ?? 1200.00);
        $seatsCount = intval($_POST['seats_count'] ?? 4);
        $totalRev = $pricePerSeat * $seatsCount;
        $notes = trim($_POST['notes'] ?? '');

        if ($vehicleId > 0 && $driverId > 0 && !empty($lineName)) {
            try {
                $pdo->beginTransaction();

                // Sequential numbering for PV: PV-YYYY-XXXX
                $currYear = date('Y');
                $stmtMax = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(report_number, 9) AS UNSIGNED)) as max_num FROM traveler_reports WHERE report_number LIKE ?");
                $stmtMax->execute(["PV-$currYear-%"]);
                $rowMax = $stmtMax->fetch();
                $nextSeq = ($rowMax && $rowMax['max_num']) ? intval($rowMax['max_num']) + 1 : 1;
                $reportNumber = sprintf("PV-%s-%04d", $currYear, $nextSeq);

                $stmtIns = $pdo->prepare("
                    INSERT INTO traveler_reports (report_number, vehicle_id, driver_id, line_name, departure_station, arrival_station, departure_date, departure_time, seats_count, price_per_seat, total_revenue, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
                ");
                $stmtIns->execute([$reportNumber, $vehicleId, $driverId, $lineName, $depStation, $arrStation, $depDate, $depTime, $seatsCount, $pricePerSeat, $totalRev, $notes]);
                $newRepId = $pdo->lastInsertId();

                // Insert Passengers
                $stmtPassIns = $pdo->prepare("
                    INSERT INTO traveler_passengers (report_id, seat_number, passenger_name, passenger_cni, phone)
                    VALUES (?, ?, ?, ?, ?)
                ");
                for ($i = 1; $i <= $seatsCount; $i++) {
                    $pName = trim($_POST["passenger_name_$i"] ?? '');
                    $pCni = trim($_POST["passenger_cni_$i"] ?? '');
                    $pPhone = trim($_POST["passenger_phone_$i"] ?? '');
                    if (!empty($pName)) {
                        $stmtPassIns->execute([$newRepId, $i, $pName, $pCni, $pPhone]);
                    }
                }

                log_audit($pdo, 'traveler_report_created', 'traveler_reports', (int)$newRepId, "إصدار محضر سفر جديد: $reportNumber على خط $lineName");
                $pdo->commit();

                $message = "تم إصدار محضر المسافرين رقم $reportNumber بنجاح.";
                $messageType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'حدث خطأ أثناء إصدار المحضر: ' . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = 'يرجى اختيار المركبة والسائق وتحديد مسار الخط.';
            $messageType = 'error';
        }
    }
}

// Fetch Reports
$reports = [];
$vehiclesList = [];
$driversList = [];

if ($pdo) {
    try {
        $stmtR = $pdo->query("
            SELECT r.*, v.plate_number, v.brand, v.model, u.full_name as driver_name, u.phone as driver_phone,
                   (SELECT COUNT(*) FROM traveler_passengers WHERE report_id = r.id) as registered_passengers
            FROM traveler_reports r
            JOIN vehicles v ON r.vehicle_id = v.id
            JOIN users u ON r.driver_id = u.id
            ORDER BY r.departure_date DESC, r.departure_time DESC
        ");
        $reports = $stmtR ? $stmtR->fetchAll() : [];

        $vehiclesList = $pdo->query("SELECT id, brand, model, plate_number FROM vehicles WHERE status = 'active' ORDER BY brand ASC")->fetchAll();
        $driversList = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' AND status = 'active' ORDER BY full_name ASC")->fetchAll();
    } catch (\Throwable $e) {
        error_log("traveler_reports fetch error: " . $e->getMessage());
    }
}
?>

<div class="space-y-6">

    <!-- Top Action Banner -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-black text-slate-900">محاضر ودفاتر المسافرين (PV de Voyageurs)</h2>
            <p class="text-xs text-slate-500 mt-1">تنظيم الرحلات ما بين الولايات، تسجيل هويات الركاب (بطاقات التعريف CNI)، وإصدار أوراق العبور الرسمية المعتمدة.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('newReportModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-2">
                <span>➕</span>
                <span>إصدار محضر سفر جديد</span>
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-xl text-xs font-bold flex items-center gap-2 <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
            <span><?= $messageType === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Reports Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-xs font-extrabold text-slate-800">قائمة محاضر النقل النظامية بين الولايات</h3>
            <span class="text-[11px] text-slate-400">دفتر شروط النقل العمومي للأشخاص</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-extrabold uppercase tracking-wider">
                        <th class="p-4">رقم المحضر</th>
                        <th class="p-4">خط السير والمحطة</th>
                        <th class="p-4">المركبة والسائق</th>
                        <th class="p-4">تاريخ الانطلاق</th>
                        <th class="p-4">الركاب المسجلين</th>
                        <th class="p-4">الإيراد المقدر</th>
                        <th class="p-4">الحالة</th>
                        <th class="p-4 text-center">الوثيقة</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-400">
                                لا توجد محاضر مسافرين مسجلة حالياً.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $r): 
                            $statusBadge = [
                                'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'in_transit' => 'bg-blue-50 text-blue-700 border-blue-200',
                                'scheduled' => 'bg-amber-50 text-amber-700 border-amber-200',
                                'cancelled' => 'bg-rose-50 text-rose-700 border-rose-200'
                            ][$r['status']] ?? 'bg-slate-50 text-slate-700 border-slate-200';

                            $statusLabel = [
                                'completed' => 'مكتملة',
                                'in_transit' => 'في الطريق',
                                'scheduled' => 'مبرمجة',
                                'cancelled' => 'ملغاة'
                            ][$r['status']] ?? $r['status'];
                        ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="p-4 font-mono font-bold text-slate-900">
                                    <span class="inline-block" dir="ltr"><?= htmlspecialchars($r['report_number']) ?></span>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars($r['line_name']) ?></div>
                                    <div class="text-[10px] text-slate-400">من: <?= htmlspecialchars($r['departure_station']) ?></div>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-800"><?= htmlspecialchars($r['brand'] . ' ' . $r['model']) ?> (<span class="font-mono text-[10px] inline-block font-bold" dir="ltr"><?= htmlspecialchars($r['plate_number']) ?></span>)</div>
                                    <div class="text-[10px] text-slate-500">السائق: <?= htmlspecialchars($r['driver_name']) ?></div>
                                </td>
                                <td class="p-4 font-num text-slate-700">
                                    <div class="font-bold inline-block" dir="ltr"><?= htmlspecialchars($r['departure_date']) ?></div>
                                    <div class="text-[10px] text-slate-400" dir="ltr"><?= htmlspecialchars($r['departure_time']) ?></div>
                                </td>
                                <td class="p-4 font-bold text-slate-800">
                                    <span class="font-num text-amber-700 inline-block" dir="ltr"><?= $r['registered_passengers'] ?> / <?= $r['seats_count'] ?></span> ركاب
                                </td>
                                <td class="p-4 font-num font-bold text-slate-900">
                                    <span class="inline-block" dir="ltr"><?= number_format($r['total_revenue'], 2, '.', ' ') ?></span> دج
                                </td>
                                <td class="p-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $statusBadge ?>">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <a href="?print_id=<?= $r['id'] ?>" target="_blank" 
                                       class="px-3 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-[10px] transition shadow-xs flex items-center justify-center gap-1">
                                        <span>🖨️</span>
                                        <span>المحضر الرسمي</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: New Traveler Report -->
<div id="newReportModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900">إصدار محضر سفر وقائمة مسافرين رسمية</h3>
            <button onclick="document.getElementById('newReportModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">المركبة المعنية *</label>
                    <select name="vehicle_id" required class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="">-- اختر سيارة الأجرة --</option>
                        <?php foreach ($vehiclesList as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">السائق المكلف بالرحلة *</label>
                    <select name="driver_id" required class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="">-- اختر السائق المعتمد --</option>
                        <?php foreach ($driversList as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">خط السير المرخص *</label>
                    <input type="text" name="line_name" required placeholder="مثال: خط الجزائر العاصمة ⇄ وهران (الباهية)"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">عدد المقاعد المتاحة</label>
                    <select name="seats_count" class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="4">4 مقاعد (سيدان)</option>
                        <option value="7">7 مقاعد (فان / ستيشن)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">محطة الانطلاق</label>
                    <input type="text" name="departure_station" value="محطة نقل المسافرين خروبة (الجزائر)"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">محطة الوصول</label>
                    <input type="text" name="arrival_station" placeholder="مثال: محطة الباهية (وهران)"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تاريخ الانطلاق</label>
                    <input type="date" name="departure_date" value="<?= date('Y-m-d') ?>"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">توقيت الانطلاق</label>
                    <input type="time" name="departure_time" value="06:30"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">سعر المقعد (دج)</label>
                    <input type="number" step="100" name="price_per_seat" value="1500"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-num font-bold">
                </div>
            </div>

            <!-- Passengers Input -->
            <div class="border-t border-slate-100 pt-3">
                <h4 class="font-extrabold text-slate-800 mb-2">بيانات الركاب (الأسماء وبطاقات التعريف CNI):</h4>
                <div class="space-y-2">
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="grid grid-cols-3 gap-2 bg-slate-50 p-2 rounded-lg border border-slate-200">
                            <div>
                                <input type="text" name="passenger_name_<?= $i ?>" placeholder="اسم الراكب <?= $i ?>"
                                       class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs bg-white">
                            </div>
                            <div>
                                <input type="text" name="passenger_cni_<?= $i ?>" placeholder="رقم بطاقة CNI" dir="ltr"
                                       class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs bg-white font-mono">
                            </div>
                            <div>
                                <input type="text" name="passenger_phone_<?= $i ?>" placeholder="رقم الهاتف" dir="ltr"
                                       class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs bg-white font-mono">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newReportModal').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition">
                    إصدار المحضر
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
