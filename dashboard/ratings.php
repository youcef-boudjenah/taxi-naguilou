<?php
// dashboard/ratings.php - Passenger Ratings & Reviews System (تقييمات الركاب وآراء الزبائن)
$pageTitle = 'تقييمات الركاب وآراء الزبائن | Passenger Ratings & Reviews';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDBConnection();
$currentUser = current_user();
$isDriver = is_driver();
$driverId = $isDriver ? (int)($currentUser['id'] ?? 0) : 0;

// Handle Delete Rating (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_rating') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'غير مصرح لك بحذف تقييمات الركاب.');
        redirect('/dashboard/ratings.php');
        exit;
    }
    $delId = intval($_POST['rating_id'] ?? 0);
    if ($delId > 0 && $pdo) {
        try {
            $stmtDel = $pdo->prepare("DELETE FROM driver_ratings WHERE id = ?");
            $stmtDel->execute([$delId]);
            if (function_exists('log_audit')) {
                log_audit($pdo, 'driver_rating_deleted', 'driver_ratings', $delId, "حذف تقييم راكب رقم #$delId");
            }
            set_flash('success', 'تم حذف التقييم بنجاح.');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر حذف التقييم: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/ratings.php');
    exit;
}

// Filters
$search = trim($_GET['search'] ?? '');
$scoreFilter = trim($_GET['score'] ?? 'all');
$filterDriverId = intval($_GET['driver_id'] ?? 0);
$filterVehicleId = intval($_GET['vehicle_id'] ?? 0);
$targetRatingId = intval($_GET['id'] ?? 0);

// Base conditions
$where = ["1=1"];
$params = [];

if ($isDriver) {
    $where[] = "(r.driver_id = ? OR v.current_driver_id = ?)";
    $params[] = $driverId;
    $params[] = $driverId;
} else {
    if ($filterDriverId > 0) {
        $where[] = "r.driver_id = ?";
        $params[] = $filterDriverId;
    }
}

if ($filterVehicleId > 0) {
    $where[] = "r.vehicle_id = ?";
    $params[] = $filterVehicleId;
}

if ($scoreFilter === '5') {
    $where[] = "r.rating = 5";
} elseif ($scoreFilter === '4') {
    $where[] = "r.rating = 4";
} elseif ($scoreFilter === '3') {
    $where[] = "r.rating = 3";
} elseif ($scoreFilter === 'low') {
    $where[] = "r.rating <= 2";
}

if (!empty($search)) {
    $where[] = "(r.passenger_name LIKE ? OR r.passenger_phone LIKE ? OR r.comment LIKE ? OR u.full_name LIKE ? OR v.door_number LIKE ? OR v.plate_number LIKE ?)";
    $sParam = "%$search%";
    $params[] = $sParam;
    $params[] = $sParam;
    $params[] = $sParam;
    $params[] = $sParam;
    $params[] = $sParam;
    $params[] = $sParam;
}

$whereClause = implode(" AND ", $where);

// Fetch Overall Statistics
$totalRatings = 0;
$avgOverall = 5.0;
$avgCleanliness = 5.0;
$avgDriving = 5.0;
$avgCourtesy = 5.0;
$starsBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

if ($pdo) {
    try {
        $statsWhere = $isDriver ? "WHERE r.driver_id = $driverId OR v.current_driver_id = $driverId" : "";
        $statsSql = "
            SELECT 
                COUNT(*) as total_count,
                AVG(r.rating) as avg_rating,
                AVG(r.cleanliness_rating) as avg_clean,
                AVG(r.driving_rating) as avg_drive,
                AVG(r.courtesy_rating) as avg_court,
                SUM(CASE WHEN r.rating = 5 THEN 1 ELSE 0 END) as s5,
                SUM(CASE WHEN r.rating = 4 THEN 1 ELSE 0 END) as s4,
                SUM(CASE WHEN r.rating = 3 THEN 1 ELSE 0 END) as s3,
                SUM(CASE WHEN r.rating = 2 THEN 1 ELSE 0 END) as s2,
                SUM(CASE WHEN r.rating = 1 THEN 1 ELSE 0 END) as s1
            FROM driver_ratings r
            LEFT JOIN vehicles v ON v.id = r.vehicle_id
            $statsWhere
        ";
        $stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);
        if ($stats) {
            $totalRatings = (int)($stats['total_count'] ?? 0);
            $avgOverall = round((float)($stats['avg_rating'] ?? 5.0), 1);
            $avgCleanliness = round((float)($stats['avg_clean'] ?? 5.0), 1);
            $avgDriving = round((float)($stats['avg_drive'] ?? 5.0), 1);
            $avgCourtesy = round((float)($stats['avg_court'] ?? 5.0), 1);
            $starsBreakdown[5] = (int)($stats['s5'] ?? 0);
            $starsBreakdown[4] = (int)($stats['s4'] ?? 0);
            $starsBreakdown[3] = (int)($stats['s3'] ?? 0);
            $starsBreakdown[2] = (int)($stats['s2'] ?? 0);
            $starsBreakdown[1] = (int)($stats['s1'] ?? 0);
        }
    } catch (\Throwable $e) {}
}

// Fetch Ratings List
$ratingsList = [];
if ($pdo) {
    try {
        $stmtList = $pdo->prepare("
            SELECT 
                r.*,
                v.door_number, v.plate_number, v.brand as vehicle_brand, v.model as vehicle_model,
                u.id as driver_user_id, u.full_name as driver_name, u.phone as driver_phone, u.avatar_url as driver_avatar
            FROM driver_ratings r
            LEFT JOIN vehicles v ON v.id = r.vehicle_id
            LEFT JOIN users u ON u.id = r.driver_id
            WHERE $whereClause
            ORDER BY r.id DESC
            LIMIT 100
        ");
        $stmtList->execute($params);
        $ratingsList = $stmtList->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $ratingsList = [];
    }
}

// Fetch active drivers for filter dropdown (Admin only)
$driversDropdown = [];
if (!$isDriver && $pdo) {
    try {
        $driversDropdown = $pdo->query("SELECT id, full_name FROM users WHERE role = 'driver' AND deleted_at IS NULL ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
}
?>

<div class="space-y-6 max-w-7xl mx-auto pb-16">

    <!-- Top Navigation / Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-[#161d27] p-4 sm:p-5 rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-2xs">
        <div>
            <div class="flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 text-xl font-bold">⭐</span>
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                        تقييمات الركاب وآراء الزبائن
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        <?= $isDriver ? 'سجل آراء وتقييمات الركاب لرحلاتك وسيارتك' : 'متابعة جودة الخدمة، نظافة المركبات، وحسن سلوك السائقين مع المسافرين' ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <a href="index.php" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-300 font-bold text-xs transition inline-flex items-center gap-1.5">
                <span>← لوحة التحكم</span>
            </a>
            <?php if (!$isDriver): ?>
                <a href="vehicles.php" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-300 font-bold text-xs transition inline-flex items-center gap-1.5">
                    <span>🚕 الأسطول</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI Summary Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5 sm:gap-4">
        
        <!-- Overall Rating Hero Card -->
        <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-br from-amber-500/15 via-amber-500/5 to-white dark:from-amber-950/40 dark:via-slate-900 dark:to-[#161d27] border-2 border-amber-400 dark:border-amber-600/70 shadow-2xs space-y-2 sm:col-span-2">
            <div class="flex items-center justify-between text-xs">
                <span class="font-black text-slate-900 dark:text-white">متوسط التقييم العام للخدمة</span>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-500 text-slate-950 font-num">
                    <?= $totalRatings ?> تقييم مسجل
                </span>
            </div>
            
            <div class="flex items-baseline gap-3">
                <span class="text-3xl sm:text-4xl font-black text-amber-600 dark:text-amber-400 font-num" dir="ltr">
                    <?= number_format($avgOverall, 1) ?>
                </span>
                <div class="space-y-0.5">
                    <div class="text-amber-500 text-lg leading-none" title="<?= $avgOverall ?> من 5">
                        <?php 
                        $fullStars = floor($avgOverall);
                        echo str_repeat('★', (int)$fullStars);
                        echo str_repeat('☆', 5 - (int)$fullStars);
                        ?>
                    </div>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400">بناءً على تجارب الركاب الحقيقية</span>
                </div>
            </div>

            <!-- Mini distribution bar -->
            <div class="pt-2 border-t border-amber-200/60 dark:border-amber-800/40 grid grid-cols-5 gap-1 text-center text-[10px]">
                <div>
                    <span class="text-slate-400 block font-num">5 ★</span>
                    <strong class="text-slate-900 dark:text-white font-num font-bold"><?= $starsBreakdown[5] ?></strong>
                </div>
                <div>
                    <span class="text-slate-400 block font-num">4 ★</span>
                    <strong class="text-slate-900 dark:text-white font-num font-bold"><?= $starsBreakdown[4] ?></strong>
                </div>
                <div>
                    <span class="text-slate-400 block font-num">3 ★</span>
                    <strong class="text-slate-900 dark:text-white font-num font-bold"><?= $starsBreakdown[3] ?></strong>
                </div>
                <div>
                    <span class="text-slate-400 block font-num">2 ★</span>
                    <strong class="text-slate-900 dark:text-white font-num font-bold"><?= $starsBreakdown[2] ?></strong>
                </div>
                <div>
                    <span class="text-slate-400 block font-num">1 ★</span>
                    <strong class="text-slate-900 dark:text-white font-num font-bold"><?= $starsBreakdown[1] ?></strong>
                </div>
            </div>
        </div>

        <!-- Cleanliness KPI -->
        <div class="p-4 sm:p-5 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-2">
            <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-slate-500 dark:text-slate-400">نظافة المركبة</span>
                <span class="text-base">✨</span>
            </div>
            <div class="text-2xl font-black text-slate-900 dark:text-white font-num" dir="ltr">
                <?= number_format($avgCleanliness, 1) ?> <span class="text-xs font-bold text-slate-400">/ 5</span>
            </div>
            <p class="text-[11px] text-slate-400">المظهر الداخلي والخارجي</p>
        </div>

        <!-- Driving Safety KPI -->
        <div class="p-4 sm:p-5 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-2">
            <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-slate-500 dark:text-slate-400">أسلوب السياقة والأمان</span>
                <span class="text-base">🛡️</span>
            </div>
            <div class="text-2xl font-black text-slate-900 dark:text-white font-num" dir="ltr">
                <?= number_format($avgDriving, 1) ?> <span class="text-xs font-bold text-slate-400">/ 5</span>
            </div>
            <p class="text-[11px] text-slate-400">احترام قواعد المرور والسرعة</p>
        </div>

        <!-- Courtesy KPI -->
        <div class="p-4 sm:p-5 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-2">
            <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-slate-500 dark:text-slate-400">حسن المعاملة والسلوك</span>
                <span class="text-base">🤝</span>
            </div>
            <div class="text-2xl font-black text-slate-900 dark:text-white font-num" dir="ltr">
                <?= number_format($avgCourtesy, 1) ?> <span class="text-xs font-bold text-slate-400">/ 5</span>
            </div>
            <p class="text-[11px] text-slate-400">الترحيب والتعامل المهني</p>
        </div>

    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white dark:bg-[#161d27] p-3.5 sm:p-4 rounded-2xl border border-slate-200/90 dark:border-slate-800 flex flex-col md:flex-row items-center justify-between gap-3 text-xs shadow-2xs">
        
        <!-- Search Form -->
        <form method="GET" class="w-full md:w-auto flex-1 flex items-center gap-2">
            <div class="relative flex-1 max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="بحث بالراكب، الهاتف، التعليق، السائق، أو رقم الباب..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">🔍</span>
            </div>

            <?php if (!$isDriver && !empty($driversDropdown)): ?>
                <select name="driver_id" class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none hidden sm:block">
                    <option value="">جميع السائقين</option>
                    <?php foreach ($driversDropdown as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $filterDriverId === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <input type="hidden" name="score" value="<?= htmlspecialchars($scoreFilter) ?>">
            <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 font-bold hover:bg-slate-800 transition cursor-pointer">
                بحث
            </button>
            <?php if (!empty($search) || $scoreFilter !== 'all' || $filterDriverId > 0): ?>
                <a href="ratings.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <!-- Score Pills -->
        <div class="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1 md:pb-0 font-bold">
            <a href="ratings.php?score=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $scoreFilter === 'all' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200' ?>">
                الكل (<?= $totalRatings ?>)
            </a>
            <a href="ratings.php?score=5<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition whitespace-nowrap flex items-center gap-1 <?= $scoreFilter === '5' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100' ?>">
                <span>⭐⭐⭐⭐⭐</span>
                <span class="font-num">(<?= $starsBreakdown[5] ?>)</span>
            </a>
            <a href="ratings.php?score=4<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition whitespace-nowrap flex items-center gap-1 <?= $scoreFilter === '4' ? 'bg-blue-600 text-white' : 'bg-blue-50 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300 hover:bg-blue-100' ?>">
                <span>⭐⭐⭐⭐</span>
                <span class="font-num">(<?= $starsBreakdown[4] ?>)</span>
            </a>
            <a href="ratings.php?score=low<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition whitespace-nowrap flex items-center gap-1 <?= $scoreFilter === 'low' ? 'bg-rose-600 text-white' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 hover:bg-rose-100' ?>">
                <span>⚠️ ضعيفة (1-2★)</span>
                <span class="font-num">(<?= $starsBreakdown[1] + $starsBreakdown[2] ?>)</span>
            </a>
        </div>

    </div>

    <!-- Ratings Feed -->
    <?php if (empty($ratingsList)): ?>
        <div class="p-12 text-center bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200/90 dark:border-slate-800 space-y-3">
            <span class="text-4xl block">⭐</span>
            <h3 class="text-base font-black text-slate-900 dark:text-white">لا توجد تقييمات مسجلة تطابق البحث حالياً</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                عندما يقوم الركاب بمسح كود باركود QR الموجود في سيارات الأجرة وتقييم التجربة، ستظهر كافة التقييمات والملاحظات هنا بتفاصيلها.
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="ratingsContainer">
            <?php foreach ($ratingsList as $r): 
                $starsStr = str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']);
                $isTarget = ($targetRatingId > 0 && (int)$r['id'] === $targetRatingId);
                $isNegative = ((int)$r['rating'] <= 2);
                $isTop = ((int)$r['rating'] === 5);
            ?>
                <div id="rating-card-<?= $r['id'] ?>" 
                     class="rating-card p-4 sm:p-5 rounded-3xl border transition-all duration-200 flex flex-col justify-between space-y-3.5 <?= $isTarget ? 'border-amber-500 ring-2 ring-amber-400 bg-amber-50/20 dark:bg-amber-950/20 shadow-md' : ($isNegative ? 'bg-rose-50/20 dark:bg-rose-950/10 border-rose-300 dark:border-rose-900/60 shadow-2xs' : 'bg-white dark:bg-[#161d27] border-slate-200/90 dark:border-slate-800 shadow-2xs') ?>">
                    
                    <div>
                        <!-- Header with Passenger Info & Rating Stars -->
                        <div class="flex items-start justify-between gap-3 mb-2.5">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="w-10 h-10 rounded-2xl bg-amber-500/15 text-amber-700 dark:text-amber-300 font-black flex items-center justify-center text-sm shrink-0">
                                    <?= mb_substr($r['passenger_name'] ?: 'ر', 0, 1) ?>
                                </span>
                                <div class="min-w-0">
                                    <div class="font-black text-slate-900 dark:text-white text-xs sm:text-sm truncate">
                                        <?= htmlspecialchars($r['passenger_name'] ?: 'راكب (بدون اسم)') ?>
                                    </div>
                                    <?php if (!empty($r['passenger_phone'])): ?>
                                        <div class="flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400 font-num" dir="ltr">
                                            <span><?= htmlspecialchars($r['passenger_phone']) ?></span>
                                            <a href="tel:<?= htmlspecialchars($r['passenger_phone']) ?>" class="text-amber-600 hover:text-amber-700" title="اتصال">📞</a>
                                            <a href="https://wa.me/213<?= ltrim(preg_replace('/[^0-9]/', '', $r['passenger_phone']), '0') ?>" target="_blank" class="text-emerald-600 hover:text-emerald-700" title="واتساب">💬</a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-[10px] text-slate-400">بدون رقم هاتف</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="text-right shrink-0">
                                <span class="px-2.5 py-1 rounded-xl text-xs font-black font-num <?= $isNegative ? 'bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 border border-rose-300' : ($isTop ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border border-emerald-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border border-amber-300') ?>" dir="ltr">
                                    <?= $r['rating'] ?> / 5 ⭐
                                </span>
                                <div class="text-amber-500 text-sm tracking-wider mt-0.5" dir="ltr">
                                    <?= $starsStr ?>
                                </div>
                            </div>
                        </div>

                        <!-- Taxi Vehicle & Driver Badge -->
                        <div class="p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200/70 dark:border-slate-800/80 flex items-center justify-between gap-2 text-xs mb-3">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-base">🚕</span>
                                <div class="min-w-0">
                                    <div class="font-black text-slate-800 dark:text-slate-200 truncate">
                                        <?= !empty($r['driver_name']) ? htmlspecialchars($r['driver_name']) : 'سائق معتمد' ?>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-num truncate" dir="ltr">
                                        باب: <?= htmlspecialchars($r['door_number'] ?: '—') ?> • <?= htmlspecialchars($r['plate_number'] ?: '') ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($r['driver_user_id'])): ?>
                                <a href="driver_fiche.php?id=<?= $r['driver_user_id'] ?>" class="text-[10px] font-bold text-amber-600 dark:text-amber-400 hover:underline shrink-0">
                                    الملف المهني ←
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- 3 Criteria Breakdown Pills -->
                        <div class="grid grid-cols-3 gap-1.5 text-center text-[10px] mb-3">
                            <div class="p-1.5 rounded-xl bg-slate-100 dark:bg-slate-800/60">
                                <span class="text-slate-400 block text-[9px]">نظافة</span>
                                <strong class="text-slate-900 dark:text-white font-num font-bold"><?= (int)$r['cleanliness_rating'] ?> / 5</strong>
                            </div>
                            <div class="p-1.5 rounded-xl bg-slate-100 dark:bg-slate-800/60">
                                <span class="text-slate-400 block text-[9px]">سياقة</span>
                                <strong class="text-slate-900 dark:text-white font-num font-bold"><?= (int)$r['driving_rating'] ?> / 5</strong>
                            </div>
                            <div class="p-1.5 rounded-xl bg-slate-100 dark:bg-slate-800/60">
                                <span class="text-slate-400 block text-[9px]">معاملة</span>
                                <strong class="text-slate-900 dark:text-white font-num font-bold"><?= (int)$r['courtesy_rating'] ?> / 5</strong>
                            </div>
                        </div>

                        <!-- Comment Quote -->
                        <?php if (!empty($r['comment'])): ?>
                            <div class="p-3 rounded-2xl bg-amber-50/40 dark:bg-amber-950/20 border border-amber-200/60 dark:border-amber-800/40 text-xs text-slate-800 dark:text-slate-200 leading-relaxed italic relative">
                                <span class="text-amber-400 text-sm font-serif">“</span>
                                <?= nl2br(htmlspecialchars($r['comment'])) ?>
                                <span class="text-amber-400 text-sm font-serif">”</span>
                            </div>
                        <?php else: ?>
                            <div class="text-[11px] text-slate-400 italic">
                                — بدون ملاحظة نصية مكتوبة.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Footer: Date, IP, Actions -->
                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[11px] text-slate-400">
                        <span class="font-num" dir="ltr">
                            <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                        </span>

                        <div class="flex items-center gap-2">
                            <!-- View Full Details Trigger -->
                            <button type="button" 
                                    onclick='openRatingModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                    class="px-2.5 py-1 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-[11px] transition cursor-pointer flex items-center gap-1 shadow-2xs">
                                <span>👁️</span>
                                <span>التفاصيل</span>
                            </button>

                            <?php if (is_admin()): ?>
                                <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا التقييم نهائياً؟');" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_rating">
                                    <input type="hidden" name="rating_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="p-1 rounded-lg text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/50 cursor-pointer" title="حذف">
                                        🗑️
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ==============================================
     Rating Details Modal (نافذة تفاصيل التقييم الكاملة)
     ============================================== -->
<div id="ratingDetailModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-xs hidden no-print" onclick="closeRatingModal()">
    <div class="bg-white dark:bg-[#161d27] w-full max-w-lg rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 sm:p-7 space-y-5 text-right max-h-[92vh] overflow-y-auto" onclick="event.stopPropagation()">
        
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div class="flex items-center gap-2.5">
                <span class="w-10 h-10 rounded-2xl bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xl font-bold">⭐</span>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white" id="modalRatingTitle">
                        تفاصيل تقييم الراكب
                    </h3>
                    <p class="text-[11px] text-slate-400" id="modalRatingSubtitle">معلومات التقييم والراكب والمركبة</p>
                </div>
            </div>
            <button type="button" onclick="closeRatingModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                ✕
            </button>
        </div>

        <!-- Overall Score Banner -->
        <div class="p-4 rounded-2xl bg-gradient-to-br from-amber-500/20 via-amber-500/10 to-amber-500/5 dark:from-amber-950/40 dark:via-slate-900 border border-amber-300 dark:border-amber-700/60 flex items-center justify-between">
            <div>
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-0.5">التقييم الإجمالي للراكب:</span>
                <div class="text-2xl sm:text-3xl font-black text-amber-600 dark:text-amber-400 font-num" id="modalOverallScore" dir="ltr">
                    5.0 / 5
                </div>
            </div>
            <div class="text-2xl text-amber-500 tracking-wider" id="modalStarsRow" dir="ltr">
                ★★★★★
            </div>
        </div>

        <!-- Passenger Details -->
        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200/80 dark:border-slate-800 space-y-2.5 text-xs">
            <span class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                <span>👤</span>
                <span>بيانات الراكب المسافر:</span>
            </span>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <div>
                    <span class="text-slate-400 block text-[11px]">اسم الراكب:</span>
                    <strong class="text-slate-900 dark:text-white" id="modalPassengerName">—</strong>
                </div>
                <div>
                    <span class="text-slate-400 block text-[11px]">رقم الهاتف:</span>
                    <div class="flex items-center gap-2 mt-0.5">
                        <strong class="text-slate-900 dark:text-white font-num" id="modalPassengerPhone" dir="ltr">—</strong>
                        <span id="modalPassengerActions" class="flex items-center gap-1.5"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vehicle & Driver Details -->
        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200/80 dark:border-slate-800 space-y-2.5 text-xs">
            <span class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                <span>🚕</span>
                <span>المركبة والسائق المقيد:</span>
            </span>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <div>
                    <span class="text-slate-400 block text-[11px]">السائق المكلف:</span>
                    <strong class="text-slate-900 dark:text-white" id="modalDriverName">—</strong>
                </div>
                <div>
                    <span class="text-slate-400 block text-[11px]">سيارة الأجرة:</span>
                    <strong class="text-slate-900 dark:text-white font-num" id="modalVehicleInfo">—</strong>
                </div>
            </div>
        </div>

        <!-- Criteria Breakdown -->
        <div class="space-y-2 text-xs">
            <span class="font-bold text-slate-700 dark:text-slate-300 block">تفصيل المحاور والمعايير:</span>
            <div class="grid grid-cols-3 gap-2">
                <div class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-center space-y-1">
                    <span class="text-slate-400 block text-[11px]">✨ نظافة المركبة</span>
                    <div class="text-base font-black text-slate-900 dark:text-white font-num" id="modalCleanliness">5 / 5</div>
                </div>
                <div class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-center space-y-1">
                    <span class="text-slate-400 block text-[11px]">🛡️ أسلوب السياقة</span>
                    <div class="text-base font-black text-slate-900 dark:text-white font-num" id="modalDriving">5 / 5</div>
                </div>
                <div class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-center space-y-1">
                    <span class="text-slate-400 block text-[11px]">🤝 حسن المعاملة</span>
                    <div class="text-base font-black text-slate-900 dark:text-white font-num" id="modalCourtesy">5 / 5</div>
                </div>
            </div>
        </div>

        <!-- Passenger Comment -->
        <div class="space-y-1.5 text-xs">
            <span class="font-bold text-slate-700 dark:text-slate-300 block">ملاحظات وتعليق الراكب:</span>
            <div class="p-4 rounded-2xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200/70 dark:border-amber-800/60 text-slate-800 dark:text-slate-200 leading-relaxed italic" id="modalComment">
                — لا يوجد تعليق مكتوب
            </div>
        </div>

        <!-- Metadata -->
        <div class="flex items-center justify-between text-[11px] text-slate-400 pt-2 border-t border-slate-100 dark:border-slate-800">
            <span id="modalDate" class="font-num" dir="ltr">—</span>
            <span id="modalIp" class="font-num" dir="ltr">—</span>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-2 pt-2">
            <button type="button" onclick="closeRatingModal()" class="px-5 py-2.5 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 text-xs font-bold hover:bg-slate-800 cursor-pointer shadow-xs">
                إغلاق
            </button>
        </div>

    </div>
</div>

<script>
function openRatingModal(data) {
    if (!data) return;

    document.getElementById('modalRatingTitle').innerText = 'تفاصيل تقييم: ' + (data.passenger_name || 'راكب');
    document.getElementById('modalRatingSubtitle').innerText = 'رقم المعاملة #' + data.id + ' • ' + (data.created_at || '');

    const ratingVal = parseInt(data.rating || 5);
    document.getElementById('modalOverallScore').innerText = ratingVal + ' / 5 ⭐';
    
    let starsStr = '';
    for (let i = 0; i < ratingVal; i++) starsStr += '★';
    for (let i = ratingVal; i < 5; i++) starsStr += '☆';
    document.getElementById('modalStarsRow').innerText = starsStr;

    document.getElementById('modalPassengerName').innerText = data.passenger_name || 'غير مصرح';
    document.getElementById('modalPassengerPhone').innerText = data.passenger_phone || 'غير مسجل';

    const actionsContainer = document.getElementById('modalPassengerActions');
    actionsContainer.innerHTML = '';
    if (data.passenger_phone) {
        const cleanPhone = data.passenger_phone.replace(/[^0-9]/g, '');
        actionsContainer.innerHTML = `
            <a href="tel:${data.passenger_phone}" class="px-2 py-0.5 rounded-md bg-amber-500 text-slate-950 font-bold text-[10px]" title="اتصال">اتصال 📞</a>
            <a href="https://wa.me/213${cleanPhone.replace(/^0/, '')}" target="_blank" class="px-2 py-0.5 rounded-md bg-emerald-600 text-white font-bold text-[10px]" title="واتساب">واتساب 💬</a>
        `;
    }

    document.getElementById('modalDriverName').innerText = data.driver_name || 'سائق معتمد';
    const vehText = (data.door_number ? ('باب ' + data.door_number) : '') + (data.plate_number ? (' • ' + data.plate_number) : '');
    document.getElementById('modalVehicleInfo').innerText = vehText || '—';

    document.getElementById('modalCleanliness').innerText = (data.cleanliness_rating || ratingVal) + ' / 5';
    document.getElementById('modalDriving').innerText = (data.driving_rating || ratingVal) + ' / 5';
    document.getElementById('modalCourtesy').innerText = (data.courtesy_rating || ratingVal) + ' / 5';

    document.getElementById('modalComment').innerText = data.comment ? ('“' + data.comment + '”') : '— لا توجد ملاحظة مكتوبة من الراكب.';
    document.getElementById('modalDate').innerText = 'تاريخ التقييم: ' + (data.created_at || '—');
    document.getElementById('modalIp').innerText = 'IP: ' + (data.ip_address || '—');

    document.getElementById('ratingDetailModal').classList.remove('hidden');
}

function closeRatingModal() {
    document.getElementById('ratingDetailModal').classList.add('hidden');
}

// Auto-open modal if URL contains ?id=...
document.addEventListener('DOMContentLoaded', function() {
    const targetId = <?= (int)$targetRatingId ?>;
    if (targetId > 0) {
        const targetCard = document.getElementById('rating-card-' + targetId);
        if (targetCard) {
            targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const btn = targetCard.querySelector('button[onclick*="openRatingModal"]');
            if (btn) btn.click();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

