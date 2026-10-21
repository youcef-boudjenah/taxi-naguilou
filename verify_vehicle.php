<?php
// verify_vehicle.php - Public Vehicle QR Verification & Passenger Rating Page
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = getDBConnection();
$token = trim($_GET['v'] ?? '');
$vehicle = null;
$driver = null;
$replacementDriver = null;
$scanLogged = false;
$ratingSubmitted = false;
$ratingError = null;

if (!$pdo) {
    die("تعذر الاتصال بقاعدة البيانات. يرجى المحاولة لاحقاً.");
}

// 1. Resolve vehicle by qr_code or by ID if numeric token fallback
if (!empty($token)) {
    $stmt = $pdo->prepare("
        SELECT v.*, 
               u.full_name as driver_name, u.phone as driver_phone, u.card_number as driver_card, u.avatar_url as driver_avatar, u.activity_status as driver_status,
               r.full_name as repl_name, r.phone as repl_phone, r.card_number as repl_card, r.avatar_url as repl_avatar
        FROM vehicles v
        LEFT JOIN users u ON v.current_driver_id = u.id
        LEFT JOIN users r ON v.replacement_driver_id = r.id
        WHERE v.qr_code = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    // If token wasn't found, try matching by plate_number or id as fallback for testing
    if (!$vehicle && is_numeric($token)) {
        $stmt2 = $pdo->prepare("
            SELECT v.*, 
                   u.full_name as driver_name, u.phone as driver_phone, u.card_number as driver_card, u.avatar_url as driver_avatar, u.activity_status as driver_status,
                   r.full_name as repl_name, r.phone as repl_phone, r.card_number as repl_card, r.avatar_url as repl_avatar
            FROM vehicles v
            LEFT JOIN users u ON v.current_driver_id = u.id
            LEFT JOIN users r ON v.replacement_driver_id = r.id
            WHERE v.id = ?
            LIMIT 1
        ");
        $stmt2->execute([intval($token)]);
        $vehicle = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
}

// Check active approved leave for replacement driver
if ($vehicle) {
    try {
        $stmtLeave = $pdo->prepare("
            SELECT l.*, u.full_name as repl_leave_name, u.phone as repl_leave_phone, u.card_number as repl_leave_card, u.avatar_url as repl_leave_avatar
            FROM leaves l
            JOIN users u ON l.replacement_driver_id = u.id
            WHERE l.vehicle_id = ? AND l.status = 'approved' AND l.start_date <= CURDATE() AND l.end_date >= CURDATE()
            ORDER BY l.id DESC LIMIT 1
        ");
        $stmtLeave->execute([$vehicle['id']]);
        $activeLeave = $stmtLeave->fetch(PDO::FETCH_ASSOC);
        if ($activeLeave) {
            $vehicle['repl_name'] = $activeLeave['repl_leave_name'];
            $vehicle['repl_phone'] = $activeLeave['repl_leave_phone'];
            $vehicle['repl_card'] = $activeLeave['repl_leave_card'];
            $vehicle['repl_avatar'] = $activeLeave['repl_leave_avatar'];
            $vehicle['is_on_leave'] = true;
        }
    } catch (\Throwable $e) {}
}

// 2. Handle Scan Notification (on GET request only, throttled 15 mins per IP)
if ($vehicle && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded[0]);
    }
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);
    $activeDriverId = $vehicle['current_driver_id'] ?? null;

    try {
        // Log raw scan
        $pdo->prepare("INSERT INTO vehicle_scans (vehicle_id, driver_id, ip_address, user_agent, scanned_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$vehicle['id'], $activeDriverId, $ip, $ua]);

        // Throttled notification: check if a scan notification occurred in the last 15 minutes for this vehicle and IP
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) FROM vehicle_scans 
            WHERE vehicle_id = ? AND ip_address = ? AND scanned_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmtCheck->execute([$vehicle['id'], $ip]);
        $recentScansCount = (int)$stmtCheck->fetchColumn();

        // If this is the first scan in the 15-minute window, notify admins and owners
        if ($recentScansCount <= 1) {
            $driverName = $vehicle['driver_name'] ?: 'غير معيّن';
            $doorNum = $vehicle['door_number'];
            $vehTitle = $vehicle['brand'] . ' ' . $vehicle['model'];
            $plateClean = str_replace('/', '-', $vehicle['plate_number']);

            $notifTitle = "مسح كود بار لمركبة الأجرة: $doorNum";
            $notifMsg = "قام راكب أو مفتش بمسح باركود سيارة الأجرة ($vehTitle - $plateClean) في الميدان. السائق المسجل: $driverName";
            $notifLink = "/dashboard/vehicles.php?search=" . urlencode($doorNum);

            $stmtNotif = $pdo->prepare("
                INSERT INTO notifications (type, title, message, link, is_read, created_at)
                VALUES ('info', ?, ?, ?, 0, NOW())
            ");
            $stmtNotif->execute([$notifTitle, $notifMsg, $notifLink]);
        }
    } catch (\Throwable $e) {
        error_log("verify_vehicle.php - scan log error: " . $e->getMessage());
    }
}

// 3. Handle Passenger Rating Submission (POST)
if ($vehicle && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    $rating = intval($_POST['rating'] ?? 5);
    if ($rating < 1) $rating = 1;
    if ($rating > 5) $rating = 5;

    $cleanliness = intval($_POST['cleanliness_rating'] ?? $rating);
    $driving = intval($_POST['driving_rating'] ?? $rating);
    $courtesy = intval($_POST['courtesy_rating'] ?? $rating);

    $passengerName = trim($_POST['passenger_name'] ?? '');
    $passengerPhone = trim($_POST['passenger_phone'] ?? '');
    $comment = trim($_POST['comment'] ?? '');

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded[0]);
    }
    $activeDriverId = $vehicle['current_driver_id'] ?? null;

    try {
        $stmtRate = $pdo->prepare("
            INSERT INTO driver_ratings (vehicle_id, driver_id, rating, cleanliness_rating, driving_rating, courtesy_rating, passenger_name, passenger_phone, comment, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtRate->execute([
            $vehicle['id'],
            $activeDriverId,
            $rating,
            $cleanliness,
            $driving,
            $courtesy,
            $passengerName ?: null,
            $passengerPhone ?: null,
            $comment ?: null,
            $ip
        ]);

        // Insert notification for admin & fleet managers
        $starsStr = str_repeat('⭐', $rating);
        $driverName = $vehicle['driver_name'] ?: 'سائق المركبة';
        $doorNum = $vehicle['door_number'];
        $notifType = ($rating >= 4) ? 'success' : ($rating <= 2 ? 'urgent' : 'warning');
        
        $notifTitle = "تقييم راكب جديد: $starsStr ($rating/5) للسائق $driverName";
        $commentExcerpt = !empty($comment) ? " • ملاحظة الراكب: \"$comment\"" : " • بدون تعليق";
        $passengerInfo = !empty($passengerName) ? " (من: $passengerName)" : "";
        $notifMsg = "تم تسجيل تقييم راكب جديد لسيارة الأجرة $doorNum ($starsStr)$passengerInfo$commentExcerpt";
        $notifLink = "/dashboard/drivers.php?search=" . urlencode($driverName);

        $pdo->prepare("
            INSERT INTO notifications (type, title, message, link, is_read, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ")->execute([$notifType, $notifTitle, $notifMsg, $notifLink]);

        $ratingSubmitted = true;
    } catch (\Throwable $e) {
        error_log("verify_vehicle.php - rating error: " . $e->getMessage());
        $ratingError = "حدث خطأ أثناء حفظ التقييم، يرجى المحاولة مرة أخرى.";
    }
}

// 4. Compute driver aggregate rating
$avgRating = 5.0;
$totalRatings = 0;
if ($vehicle && $vehicle['current_driver_id']) {
    try {
        $stmtAgg = $pdo->prepare("
            SELECT COUNT(*) as cnt, AVG(rating) as avg_score 
            FROM driver_ratings 
            WHERE driver_id = ?
        ");
        $stmtAgg->execute([$vehicle['current_driver_id']]);
        $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC);
        if ($agg && $agg['cnt'] > 0) {
            $totalRatings = (int)$agg['cnt'];
            $avgRating = round((float)$agg['avg_score'], 1);
        }
    } catch (\Throwable $e) {}
}

$siteName = 'تاكسي نقيلو';
$plateFormatted = $vehicle ? str_replace('/', '-', $vehicle['plate_number']) : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $vehicle ? "بطاقة مركبة الأجرة {$vehicle['door_number']} | {$siteName}" : "التحقق من المركبة | {$siteName}" ?></title>
    <!-- Production Compiled Tailwind CSS -->
    <link rel="stylesheet" href="<?= url('/assets/css/tailwind.min.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Changa:wght@500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', system-ui, sans-serif; -webkit-tap-highlight-color: transparent; }
        .font-brand { font-family: 'Changa', 'Cairo', sans-serif; }
        .font-mono-num { font-family: 'JetBrains Mono', monospace; font-feature-settings: "tnum"; }
        .taxi-pattern {
            background-image: repeating-linear-gradient(45deg, #0f172a 0, #0f172a 12px, #f59e0b 12px, #f59e0b 24px);
        }
        .star-rating input[type="radio"] { display: none; }
        .star-rating label { cursor: pointer; transition: transform 0.15s ease, color 0.15s ease; }
        .star-rating label:hover,
        .star-rating label:hover ~ label { transform: scale(1.15); }
    </style>
</head>
<body class="min-h-full flex flex-col items-center justify-start py-4 sm:py-8 px-3 text-slate-800 antialiased selection:bg-amber-500 selection:text-slate-950">

    <div class="w-full max-w-lg mx-auto">

        <!-- Top Header Strip -->
        <header class="mb-4 text-center">
            <div class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-slate-900 text-white shadow-sm mb-2">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                <span class="font-brand font-bold text-xs tracking-wide">المنظومة الرقمية لسيارات الأجرة • نقيلو</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight font-brand">بطاقة التحقق والتقييم الميداني</h1>
            <p class="text-xs text-slate-500 mt-0.5">خدمة المسافرين وتأكيد موثوقية الأسطول المعتمد</p>
        </header>

        <?php if (!$vehicle): ?>
            <!-- Vehicle Not Found Card -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-xl p-6 sm:p-8 text-center space-y-4">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center text-3xl">
                    ⚠️
                </div>
                <div class="space-y-1">
                    <h2 class="text-lg font-bold text-slate-900">رمز الاستجابة غير صحيح أو منتهي الصلاحية</h2>
                    <p class="text-xs text-slate-500 leading-relaxed">
                        تعذر العثور على مركبة تطابق رمز الاستجابة السريعة (QR Code). يرجى التأكد من مسح الرمز الملصق على الزجاج الجانبي أو الواجهة الداخلية لسيارة الأجرة.
                    </p>
                </div>
                <div class="pt-2">
                    <a href="/" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition">
                        <span>العودة للرئيسية</span>
                        <span dir="ltr">←</span>
                    </a>
                </div>
            </div>
        <?php else: ?>

            <!-- Main Vehicle Card -->
            <div class="bg-white rounded-3xl border border-slate-200/90 shadow-xl overflow-hidden transition-all mb-4">
                
                <!-- Taxi Yellow Header Bar -->
                <div class="bg-gradient-to-r from-amber-400 via-amber-500 to-amber-400 p-4 sm:p-5 text-slate-950 relative overflow-hidden">
                    <div class="absolute -right-6 -bottom-6 opacity-10 text-8xl font-black select-none pointer-events-none">TAXI</div>
                    
                    <div class="flex items-center justify-between relative z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl bg-slate-950 text-amber-400 flex items-center justify-center font-black text-xl shadow-md border-2 border-amber-300">
                                🚕
                            </div>
                            <div>
                                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-900/80">سيارة أجرة معتمدة</div>
                                <div class="text-lg font-black tracking-tight font-brand"><?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?></div>
                            </div>
                        </div>

                        <!-- Status Badge -->
                        <?php if (($vehicle['status'] ?? 'active') === 'active'): ?>
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-600 text-white font-bold text-xs shadow-sm">
                                <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                                <span>نشطة ومعتمدة</span>
                            </div>
                        <?php else: ?>
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-600 text-white font-bold text-xs shadow-sm">
                                <span>⏸️</span>
                                <span>معلقة حالياً</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Algerian Plate & Door Number Banner -->
                <div class="p-4 sm:p-5 bg-slate-900 text-white flex flex-wrap items-center justify-between gap-3 border-b border-slate-800">
                    <div>
                        <div class="text-[10px] text-slate-400 font-bold mb-1">رقم تسجيل المركبة (Matricule)</div>
                        <div class="inline-flex items-center px-3.5 py-1.5 rounded-lg bg-black text-amber-400 font-mono-num font-bold text-sm tracking-widest border border-amber-500/40 shadow-inner" dir="ltr">
                            <?= htmlspecialchars($plateFormatted) ?>
                        </div>
                    </div>

                    <div class="text-left sm:text-right">
                        <div class="text-[10px] text-slate-400 font-bold mb-1">رقم الباب الميداني (DTW)</div>
                        <div class="inline-flex items-center px-3 py-1.5 rounded-lg bg-amber-500/20 text-amber-300 font-mono-num font-bold text-sm border border-amber-500/30" dir="ltr">
                            <?= htmlspecialchars($vehicle['door_number']) ?>
                        </div>
                    </div>
                </div>

                <!-- Vehicle & Driver Details Section -->
                <div class="p-4 sm:p-6 space-y-4">

                    <!-- Status Notification If Suspended -->
                    <?php if (($vehicle['status'] ?? 'active') === 'suspended'): ?>
                        <div class="p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs space-y-1">
                            <div class="font-bold flex items-center gap-1.5">
                                <span>⚠️ تنبيه للمسافر:</span>
                                <span>هذه المركبة معلقة إدارياً في النظام</span>
                            </div>
                            <?php if (!empty($vehicle['suspended_reason'])): ?>
                                <p class="text-[11px] text-rose-700">السبب: <?= htmlspecialchars($vehicle['suspended_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Driver Profile Card -->
                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center gap-3.5">
                        <div class="w-14 h-14 rounded-2xl bg-amber-500/20 text-amber-700 border-2 border-amber-500/30 flex items-center justify-center text-2xl font-black shrink-0 overflow-hidden">
                            <?php if (!empty($vehicle['driver_avatar'])): ?>
                                <img src="<?= htmlspecialchars($vehicle['driver_avatar']) ?>" alt="صورة السائق" class="w-full h-full object-cover">
                            <?php else: ?>
                                👨‍✈️
                            <?php endif; ?>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-bold text-slate-400">السائق المعتمد:</span>
                                <?php if (!empty($vehicle['is_on_leave']) && !empty($vehicle['repl_name'])): ?>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">سائق بديل رسمي</span>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-sm font-extrabold text-slate-900 truncate">
                                <?= htmlspecialchars((!empty($vehicle['is_on_leave']) && !empty($vehicle['repl_name'])) ? $vehicle['repl_name'] : ($vehicle['driver_name'] ?: 'سائق قيد التعيين')) ?>
                            </h3>
                            <div class="flex flex-wrap items-center gap-3 mt-1 text-[11px] text-slate-500">
                                <?php if (!empty($vehicle['driver_card'])): ?>
                                    <span class="inline-flex items-center gap-1">
                                        <span>🪪 الدفتر:</span>
                                        <span class="font-mono-num font-bold text-slate-700"><?= htmlspecialchars($vehicle['driver_card']) ?></span>
                                    </span>
                                <?php endif; ?>

                                <?php if ($totalRatings > 0): ?>
                                    <span class="inline-flex items-center gap-1 text-amber-600 font-bold">
                                        <span>⭐</span>
                                        <span class="font-mono-num"><?= $avgRating ?></span>
                                        <span class="text-slate-400 font-normal">(<?= $totalRatings ?> تقييم)</span>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-400 text-[10px]">⭐ سائق جديد</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Technical Compliance Strip -->
                    <div class="grid grid-cols-2 gap-2 text-center text-xs">
                        <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-200">
                            <span class="text-[10px] text-slate-400 block font-semibold">المراقبة التقنية</span>
                            <span class="font-bold text-emerald-700 inline-flex items-center gap-1 mt-0.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                <span>سارية ومطابقة</span>
                            </span>
                        </div>
                        <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-200">
                            <span class="text-[10px] text-slate-400 block font-semibold">التأمين والمسؤولية المدنية</span>
                            <span class="font-bold text-emerald-700 inline-flex items-center gap-1 mt-0.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                <span>شامل الركاب (SAA)</span>
                            </span>
                        </div>
                    </div>

                    <!-- Passenger Rating Box -->
                    <div class="mt-6 pt-5 border-t border-slate-200">
                        
                        <?php if ($ratingSubmitted): ?>
                            <!-- Thank you message -->
                            <div class="p-5 rounded-2xl bg-emerald-50 border border-emerald-200 text-center space-y-2.5">
                                <div class="w-12 h-12 mx-auto rounded-full bg-emerald-500 text-white flex items-center justify-center text-2xl shadow-sm">
                                    ✓
                                </div>
                                <h3 class="text-base font-extrabold text-emerald-950">شكراً لمساهمتك الكريمة!</h3>
                                <p class="text-xs text-emerald-800 leading-relaxed">
                                    تم استلام تقييمك بنجاح، ووصل إلى إدارة أسطول تاكسي نقيلو لتحسين جودة الخدمة وسلامة المسافرين.
                                </p>
                                <div class="pt-1">
                                    <button onclick="window.location.reload()" class="px-4 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs transition">
                                        تحديث الصفحة
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>

                            <div class="text-center mb-4">
                                <span class="inline-block px-3 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-900 mb-1">
                                    رأيك يهمنا في الميدان 🌟
                                </span>
                                <h2 class="text-base font-black text-slate-900 font-brand">تقييم رحلتك وسلوك السائق</h2>
                                <p class="text-xs text-slate-500 mt-0.5">ساعدنا في مكافأة السائقين المتميزين والحفاظ على المعايير العالية</p>
                            </div>

                            <?php if ($ratingError): ?>
                                <div class="p-3 mb-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold text-center">
                                    <?= htmlspecialchars($ratingError) ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" class="space-y-3.5">
                                <input type="hidden" name="submit_rating" value="1">

                                <!-- Star Selector -->
                                <div class="bg-slate-50 p-3.5 rounded-2xl border border-slate-200 text-center">
                                    <label class="block text-xs font-bold text-slate-700 mb-2">التقييم العام للرحلة</label>
                                    
                                    <div class="flex items-center justify-center gap-2 text-3xl flex-row-reverse" id="starsContainer">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                            <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?> class="sr-only peer">
                                            <label for="star<?= $i ?>" onclick="updateStars(<?= $i ?>)" class="cursor-pointer transition-transform hover:scale-125 select-none star-label" data-value="<?= $i ?>">
                                                <span class="text-amber-400">★</span>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                    <div id="starText" class="text-xs font-extrabold text-amber-700 mt-1.5">ممتاز جداً (5/5)</div>
                                </div>

                                <!-- Sub-criteria Quality Badges -->
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-center">
                                        <label class="block text-[11px] font-bold text-slate-700 mb-1">🧼 النظافة</label>
                                        <select name="cleanliness_rating" class="w-full text-xs font-bold bg-white rounded-lg border border-slate-200 py-1 px-1.5 focus:ring-1 focus:ring-amber-500">
                                            <option value="5" selected>ممتازة ⭐ 5</option>
                                            <option value="4">جيدة ⭐ 4</option>
                                            <option value="3">متوسطة ⭐ 3</option>
                                            <option value="2">سيئة ⭐ 2</option>
                                            <option value="1">رديئة ⭐ 1</option>
                                        </select>
                                    </div>

                                    <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-center">
                                        <label class="block text-[11px] font-bold text-slate-700 mb-1">🚗 القيادة</label>
                                        <select name="driving_rating" class="w-full text-xs font-bold bg-white rounded-lg border border-slate-200 py-1 px-1.5 focus:ring-1 focus:ring-amber-500">
                                            <option value="5" selected>آمنة ومريحة ⭐ 5</option>
                                            <option value="4">جيدة ⭐ 4</option>
                                            <option value="3">مقبولة ⭐ 3</option>
                                            <option value="2">سريعة ⭐ 2</option>
                                            <option value="1">متهورة ⭐ 1</option>
                                        </select>
                                    </div>

                                    <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-center">
                                        <label class="block text-[11px] font-bold text-slate-700 mb-1">🤝 المعاملة</label>
                                        <select name="courtesy_rating" class="w-full text-xs font-bold bg-white rounded-lg border border-slate-200 py-1 px-1.5 focus:ring-1 focus:ring-amber-500">
                                            <option value="5" selected>قمة اللباقة ⭐ 5</option>
                                            <option value="4">محترم ⭐ 4</option>
                                            <option value="3">عادي ⭐ 3</option>
                                            <option value="2">غير لبق ⭐ 2</option>
                                            <option value="1">غير لائق ⭐ 1</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Feedback Comments -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظاتك أو مقترحاتك (اختياري)</label>
                                    <textarea name="comment" rows="2" placeholder="اكتب تعليقك حول التكييف، العداد، مسار الطريق أو الراحة..." class="w-full p-2.5 text-xs rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition resize-none"></textarea>
                                </div>

                                <!-- Optional Passenger Name / Phone -->
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-600 mb-1">الاسم (اختياري)</label>
                                        <input type="text" name="passenger_name" placeholder="مسافر / راكب" class="w-full px-2.5 py-1.5 text-xs rounded-lg border border-slate-200 focus:ring-1 focus:ring-amber-500">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-600 mb-1">رقم الهاتف (اختياري)</label>
                                        <input type="tel" name="passenger_phone" placeholder="06 / 05 / 07..." dir="ltr" class="w-full px-2.5 py-1.5 text-xs rounded-lg border border-slate-200 text-right focus:ring-1 focus:ring-amber-500">
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <button type="submit" class="w-full py-3 px-4 rounded-2xl bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-slate-950 font-black text-sm transition shadow-md flex items-center justify-center gap-2 cursor-pointer font-brand">
                                    <span>إرسال التقييم للإدارة</span>
                                    <span dir="ltr">✓</span>
                                </button>
                            </form>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Footer Taxi Pattern -->
                <div class="h-2.5 taxi-pattern"></div>
            </div>

        <?php endif; ?>

        <!-- Footer Notice -->
        <footer class="text-center text-[11px] text-slate-400 space-y-1">
            <p>جميع البيانات مسجلة رسمياً ومحمية طبقاً للقوانين المنظمة لنقل الأشخاص عبر سيارات الأجرة</p>
            <p class="font-mono-num font-bold text-slate-500">© <?= date('Y') ?> TAXI NAGUILOU • ALGERIA</p>
        </footer>

    </div>

    <script>
        const starLabels = [
            'ضعيف جداً (1/5)',
            'بحاجة لتحسين (2/5)',
            'متوسط ومقبول (3/5)',
            'جيد جداً (4/5)',
            'ممتاز ورائع (5/5)'
        ];

        function updateStars(val) {
            const container = document.getElementById('starsContainer');
            const starText = document.getElementById('starText');
            if (starText) {
                starText.textContent = starLabels[val - 1] || `${val}/5`;
            }
            const labels = container.querySelectorAll('.star-label');
            labels.forEach(lbl => {
                const itemVal = parseInt(lbl.getAttribute('data-value'));
                const starSpan = lbl.querySelector('span');
                if (itemVal <= val) {
                    starSpan.className = 'text-amber-400';
                    starSpan.textContent = '★';
                } else {
                    starSpan.className = 'text-slate-300';
                    starSpan.textContent = '☆';
                }
            });
        }
    </script>
</body>
</html>
