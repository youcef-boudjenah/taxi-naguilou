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
        if (is_driver() && (int)$report['driver_id'] !== (int)$_SESSION['user_id']) {
            set_flash('error', 'عذراً، لا يمكنك الاطلاع على محاضر رحلات السائقين الآخرين.');
            redirect('/dashboard/traveler_reports.php');
            exit;
        }
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
            <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
            <!-- Production Compiled Tailwind CSS -->
            <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">
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
$pageTitle = 'الأمر بالمهمة (PV)';
require_once __DIR__ . '/../includes/header.php';

// Handle Form Actions (Add, Edit, Delete Trip Manifest)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf_token();
    $action = $_POST['action'] ?? 'add_report';

    if (!is_admin()) {
        $message = 'عذراً، فقط إدارة المنصة مخولة بالعمليات على محاضر المسافرين.';
        $messageType = 'error';
    } elseif ($action === 'edit_report') {
        $repId = intval($_POST['report_id'] ?? 0);
        $lineName = trim($_POST['line_name'] ?? '');
        $depStation = trim($_POST['departure_station'] ?? '');
        $arrStation = trim($_POST['arrival_station'] ?? '');
        $depDate = $_POST['departure_date'] ?? date('Y-m-d');
        $depTime = $_POST['departure_time'] ?? date('H:i');
        $pricePerSeat = floatval($_POST['price_per_seat'] ?? 1200.00);
        $seatsCount = intval($_POST['seats_count'] ?? 4);
        $totalRev = $pricePerSeat * $seatsCount;
        $status = trim($_POST['status'] ?? 'scheduled');
        $notes = trim($_POST['notes'] ?? '');

        if ($repId > 0 && !empty($lineName)) {
            try {
                $stmtUp = $pdo->prepare("
                    UPDATE traveler_reports
                    SET line_name = ?, departure_station = ?, arrival_station = ?, 
                        departure_date = ?, departure_time = ?, seats_count = ?, 
                        price_per_seat = ?, total_revenue = ?, status = ?, notes = ?
                    WHERE id = ?
                ");
                $stmtUp->execute([$lineName, $depStation, $arrStation, $depDate, $depTime, $seatsCount, $pricePerSeat, $totalRev, $status, $notes, $repId]);
                log_audit($pdo, 'traveler_report_updated', 'traveler_reports', $repId, "تعديل بيانات محضر السفر رقم $repId على خط $lineName");
                $message = "تم تحديث محضر المسافرين بنجاح.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'حدث خطأ أثناء تعديل المحضر: ' . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = 'يرجى ملء جميع الحقول الإلزامية للمحضر.';
            $messageType = 'error';
        }
    } elseif ($action === 'delete_report') {
        $repId = intval($_POST['report_id'] ?? 0);
        if ($repId > 0) {
            try {
                $pdo->prepare("UPDATE traveler_reports SET deleted_at = NOW() WHERE id = ?")->execute([$repId]);
                log_audit($pdo, 'traveler_report_deleted', 'traveler_reports', $repId, "نقل محضر المسافرين رقم $repId وسجل ركابه إلى سلة المهملات");
                $message = "تم نقل محضر السفر إلى سلة المهملات والأرشيف بنجاح. يمكنك استرجاعه في أي وقت.";
                $messageType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'حدث خطأ أثناء حذف المحضر: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } else {
        // Add New Trip Manifest
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
        $whereRep = ["1=1", "r.deleted_at IS NULL"];
        $paramsRep = [];
        if (is_driver()) {
            $whereRep[] = "r.driver_id = ?";
            $paramsRep[] = intval($_SESSION['user_id']);
        } elseif (is_owner() && !is_admin()) {
            $whereRep[] = "(v.owner_id = ? OR v.owner_id IS NULL)";
            $paramsRep[] = intval($_SESSION['user_id']);
        }

        $stmtR = $pdo->prepare("
            SELECT r.*, v.plate_number, v.brand, v.model, v.door_number, u.full_name as driver_name, u.phone as driver_phone,
                   (SELECT COUNT(*) FROM traveler_passengers WHERE report_id = r.id) as registered_passengers
            FROM traveler_reports r
            JOIN vehicles v ON r.vehicle_id = v.id
            JOIN users u ON r.driver_id = u.id
            WHERE " . implode(' AND ', $whereRep) . "
            ORDER BY CASE WHEN v.door_number IS NOT NULL AND v.door_number != '' THEN 0 ELSE 1 END,
                     CAST(SUBSTRING_INDEX(v.door_number, '-', -1) AS UNSIGNED) ASC,
                     v.door_number ASC,
                     r.departure_date DESC, r.departure_time DESC
        ");
        $stmtR->execute($paramsRep);
        $reports = $stmtR ? $stmtR->fetchAll() : [];

        $vehiclesList = $pdo->query("
            SELECT id, brand, model, plate_number, door_number 
            FROM vehicles 
            WHERE status = 'active' 
            ORDER BY CASE WHEN door_number IS NOT NULL AND door_number != '' THEN 0 ELSE 1 END,
                     CAST(SUBSTRING_INDEX(door_number, '-', -1) AS UNSIGNED) ASC,
                     door_number ASC,
                     brand ASC
        ")->fetchAll();

        $driversList = $pdo->query("
            SELECT u.id, u.full_name, u.phone, MAX(v.door_number) as door_number
            FROM users u
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id)
            WHERE u.role = 'driver' AND u.status = 'active'
            GROUP BY u.id, u.full_name, u.phone
            ORDER BY CASE WHEN MAX(v.door_number) IS NOT NULL AND MAX(v.door_number) != '' THEN 0 ELSE 1 END,
                     CAST(SUBSTRING_INDEX(MAX(v.door_number), '-', -1) AS UNSIGNED) ASC,
                     MAX(v.door_number) ASC,
                     u.id ASC
        ")->fetchAll();
    } catch (\Throwable $e) {
        error_log("traveler_reports fetch error: " . $e->getMessage());
    }
}
?>

<div class="space-y-6">

    <!-- Top Action Banner -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-black text-slate-900">الأمر بالمهمة (PV)</h2>
            <p class="text-xs text-slate-500 mt-1">أوامر المهمة، تنظيم الرحلات ما بين الولايات، تسجيل هويات الركاب (بطاقات التعريف CNI)، وإصدار أوراق العبور الرسمية المعتمدة.</p>
        </div>
        <?php if (is_admin()): ?>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('newReportModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-2">
                <span>➕</span>
                <span>إصدار أمر بمهمة جديد</span>
            </button>
        </div>
        <?php endif; ?>
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
            <h3 class="text-xs font-extrabold text-slate-800">قائمة أوامر المهمة ومحاضر النقل النظامية</h3>
            <span class="text-[11px] text-slate-400">دفتر شروط النقل العمومي للأشخاص</span>
        </div>

        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200/80 dark:border-slate-700/80 text-slate-700 dark:text-slate-200 font-extrabold uppercase tracking-wider">
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
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                                <td class="p-4">
                                    <span class="table-num-badge table-num-sky font-bold font-mono text-[11px]" dir="ltr">📄 <?= htmlspecialchars($r['report_number']) ?></span>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($r['line_name']) ?></div>
                                    <div class="text-[10px] text-slate-400">من: <?= htmlspecialchars($r['departure_station']) ?></div>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                                        <span><?= htmlspecialchars($r['brand'] . ' ' . $r['model']) ?></span>
                                        <?php if (!empty($r['door_number'])): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-black bg-amber-500/15 text-amber-800 dark:text-amber-300 font-num border border-amber-500/30" dir="ltr">🚪 <?= htmlspecialchars($r['door_number']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="table-num-badge bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/80 text-[10px] font-bold" dir="ltr">🚗 <?= htmlspecialchars($r['plate_number']) ?></span>
                                        <span class="text-[10px] text-slate-500">السائق: <?= htmlspecialchars($r['driver_name']) ?></span>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <span class="table-num-badge table-num-slate text-[11px]" dir="ltr">
                                        <?= htmlspecialchars($r['departure_date']) ?>
                                        <span class="opacity-60 text-[10px]"><?= htmlspecialchars($r['departure_time']) ?></span>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <span class="table-num-badge table-num-amber text-[11px] font-bold" dir="ltr">
                                        <span class="table-num-dot-amber"></span>
                                        <?= $r['registered_passengers'] ?> / <?= $r['seats_count'] ?> ركاب
                                    </span>
                                </td>
                                <td class="p-4">
                                    <span class="table-num-badge table-num-emerald text-xs font-bold" dir="ltr">
                                        <span class="table-num-dot-emerald"></span>
                                        <?= number_format($r['total_revenue'], 2, '.', ' ') ?> دج
                                    </span>
                                </td>
                                <td class="p-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $statusBadge ?>">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="?print_id=<?= $r['id'] ?>" target="_blank" 
                                           class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold text-[11px] transition shadow-2xs" title="طباعة المحضر الرسمي">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                                            </svg>
                                            <span>طباعة</span>
                                        </a>
                                        <?php if (is_admin()): ?>
                                            <button type="button" 
                                                    onclick='openEditReportModal(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-[11px] transition shadow-2xs" title="تعديل المحضر">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل</span>
                                            </button>
                                            <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف محضر المسافرين رقم <?= htmlspecialchars($r['report_number']) ?> وسجل ركابه؟')" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_report">
                                                <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                                <button type="submit" 
                                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-[11px] transition border border-rose-200/60 shadow-2xs" title="حذف المحضر">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    <span>حذف</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php if (is_admin()): ?>
<!-- Modal: New Traveler Report -->
<div id="newReportModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
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
                            <option value="<?= $v['id'] ?>"><?= !empty($v['door_number']) ? '[' . htmlspecialchars($v['door_number']) . '] ' : '' ?><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">السائق المكلف بالرحلة *</label>
                    <select name="driver_id" required class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="">-- اختر السائق المعتمد --</option>
                        <?php foreach ($driversList as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= !empty($d['door_number']) ? '[' . htmlspecialchars($d['door_number']) . '] ' : '' ?><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)</option>
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

<!-- Modal: Edit Traveler Report -->
<div id="editReportModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl border border-slate-200 space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-bold text-slate-900">تعديل محضر سفر وقائمة المسافرين</h3>
                <span id="editRepNumber" class="text-xs text-amber-600 font-bold font-mono"></span>
            </div>
            <button type="button" onclick="document.getElementById('editReportModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_report">
            <input type="hidden" id="editReportId" name="report_id" value="">

            <div>
                <label class="block font-bold text-slate-700 mb-1">خط السير المرخص *</label>
                <input type="text" id="editLineName" name="line_name" required
                       class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-semibold">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">محطة الانطلاق</label>
                    <input type="text" id="editDepartureStation" name="departure_station"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">محطة الوصول</label>
                    <input type="text" id="editArrivalStation" name="arrival_station"
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تاريخ الانطلاق *</label>
                    <input type="date" id="editDepartureDate" name="departure_date" required
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">توقيت الانطلاق *</label>
                    <input type="time" id="editDepartureTime" name="departure_time" required
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">عدد المقاعد</label>
                    <input type="number" id="editSeatsCount" name="seats_count" min="1" max="15" required
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">سعر المقعد (دج)</label>
                    <input type="number" id="editPricePerSeat" name="price_per_seat" step="50" min="0" required
                           class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">حالة الرحلة</label>
                    <select id="editStatus" name="status" class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500 font-bold">
                        <option value="scheduled">مجدولة / في انتظار الانطلاق</option>
                        <option value="in_progress">قيد السير على الطريق</option>
                        <option value="completed">مكتملة ووصلت بنجاح</option>
                        <option value="cancelled">ملغاة</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">ملاحظات الرحلة</label>
                <textarea id="editNotes" name="notes" rows="2"
                          class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('editReportModal').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black transition">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditReportModal(data) {
    if (!data) return;
    document.getElementById('editReportId').value = data.id || '';
    document.getElementById('editRepNumber').textContent = data.report_number ? 'محضر: ' + data.report_number : '';
    document.getElementById('editLineName').value = data.line_name || '';
    document.getElementById('editDepartureStation').value = data.departure_station || '';
    document.getElementById('editArrivalStation').value = data.arrival_station || '';
    document.getElementById('editDepartureDate').value = data.departure_date || '';
    document.getElementById('editDepartureTime').value = data.departure_time ? data.departure_time.substring(0, 5) : '';
    document.getElementById('editSeatsCount').value = data.seats_count || 4;
    document.getElementById('editPricePerSeat').value = data.price_per_seat || 1200;
    document.getElementById('editStatus').value = data.status || 'scheduled';
    document.getElementById('editNotes').value = data.notes || '';
    document.getElementById('editReportModal').classList.remove('hidden');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
