<?php
// dashboard/driver_fiche.php - Fiche Technique Officielle du Chauffeur de Taxi (دفتر المقررة والبطاقة الفنية للسائق)
require_once __DIR__ . '/../includes/auth.php';
require_login();

$driverId = intval($_GET['id'] ?? 0);
$pdo = getDBConnection();

$driver = null;
if ($pdo && $driverId > 0) {
    try {
        $hasVehReplCol = false;
        try {
            $pdo->query("SELECT replacement_driver_id FROM vehicles LIMIT 0");
            $hasVehReplCol = true;
        } catch (\Throwable $e) {}

        $joinVehOn = $hasVehReplCol 
            ? "(v.current_driver_id = u.id OR v.replacement_driver_id = u.id)"
            : "v.current_driver_id = u.id";

        $stmt = $pdo->prepare("
            SELECT u.*, 
                   v.id as vehicle_id, v.brand, v.model, v.plate_number, v.door_number, v.color,
                   v.insurance_expiry, v.technical_control_expiry,
                   (CASE WHEN v.replacement_driver_id = u.id THEN 1 ELSE 0 END) as is_veh_replacement,
                   (SELECT COUNT(*) FROM vehicles v2 WHERE v2.replacement_driver_id = u.id) as veh_repl_count,
                   (SELECT COUNT(*) FROM leaves l WHERE l.replacement_driver_id = u.id AND l.status = 'approved' AND l.end_date >= CURDATE()) as act_leave_repl_count,
                   c.id as contract_id, c.contract_number, c.start_date as contract_start, c.end_date as contract_end, 
                   c.monthly_wage, c.status as contract_status,
                   u_owner.full_name as owner_name, u_owner.phone as owner_phone
            FROM users u
            LEFT JOIN vehicles v ON $joinVehOn
            LEFT JOIN contracts c ON (c.driver_id = u.id AND c.status = 'signed')
            LEFT JOIN users u_owner ON (c.owner_id = u_owner.id OR v.owner_id = u_owner.id)
            WHERE u.id = ? AND u.role = 'driver'
        ");
        $stmt->execute([$driverId]);
        $driver = $stmt->fetch();
        if ($driver) {
            $isReplacement = (!empty($driver['is_veh_replacement']))
                || (!empty($driver['veh_repl_count']) && (int)$driver['veh_repl_count'] > 0)
                || (!empty($driver['act_leave_repl_count']) && (int)$driver['act_leave_repl_count'] > 0)
                || in_array(strtolower($driver['driver_type'] ?? ''), ['replacement', 'remplacant', 'substitute', 'بديل'])
                || (($driver['probation_stage'] ?? '') === 'replacement')
                || (mb_strpos($driver['full_name'] ?? '', 'بديل') !== false);
            $driver['is_replacement'] = $isReplacement;
            $cleanName = preg_replace('/\s*\(?سائق\s*بديل( معتمد)?\)?|\s*\(بديل\)/u', '', $driver['full_name'] ?? '');
            $driver['clean_name'] = trim($cleanName) ?: $driver['full_name'];
        }
    } catch (\Throwable $e) {
        error_log("driver_fiche query error: " . $e->getMessage());
        $driver = null;
    }
}

// Handle stage update from driver fiche
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver_stage') {
    $targetId = intval($_POST['driver_id'] ?? 0);
    $newStage = trim($_POST['probation_stage'] ?? 'trial_1');
    if ($targetId > 0 && in_array($newStage, ['trial_1', 'trial_2', 'trial_3', 'official']) && !is_driver()) {
        $stmtUpdate = $pdo->prepare("UPDATE users SET probation_stage = ? WHERE id = ? AND role = 'driver'");
        $stmtUpdate->execute([$newStage, $targetId]);
        log_audit($pdo, 'driver_stage_updated', 'users', $targetId, "تحديث مرحلة السائق إلى: $newStage من البطاقة الفنية");
        set_flash('success', 'تم تحديث المرحلة المهنية للسائق بنجاح');
        redirect('/dashboard/driver_fiche.php?id=' . $targetId);
    }
}

// Fallback: If no ID supplied or not found, select first available driver (or own ID if driver)
if (!$driver && $pdo) {
    if (is_driver() && !empty($_SESSION['user_id'])) {
        redirect('/dashboard/driver_fiche.php?id=' . intval($_SESSION['user_id']));
    }
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

// Load Yearly Breakdown & Subscriptions for this driver (Feature from Old Website)
$driverSubs = [];
$driverVersements = [];
$debtSummary = null;
$totalSubDue = 0;
$totalSubPaid = 0;

if ($driver && $pdo) {
    try {
        $stmtSubs = $pdo->prepare("SELECT * FROM driver_subscriptions WHERE driver_id = ? ORDER BY period_month ASC");
        $stmtSubs->execute([$driver['id']]);
        $rows = $stmtSubs->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $driverSubs[$r['period_month']] = $r;
            $totalSubDue += floatval($r['total_due'] ?? 0);
            $totalSubPaid += floatval($r['paid_amount'] ?? 0);
        }
    } catch (\Throwable $e) {}

    try {
        $stmtV = $pdo->prepare("SELECT * FROM versements WHERE driver_id = ? ORDER BY payment_date DESC LIMIT 8");
        $stmtV->execute([$driver['id']]);
        $driverVersements = $stmtV->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    try {
        $stmtDebt = $pdo->prepare("SELECT * FROM driver_debts WHERE user_id = ? LIMIT 1");
        $stmtDebt->execute([$driver['id']]);
        $debtSummary = $stmtDebt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

// Calculate Probation info according to the 3-month cycle rule
$probInfo = resolve_driver_probation_info($driver);

// Calculate accurate document statuses
$today = strtotime(date('Y-m-d'));

// License
$lic = !empty($driver['license_expiry']) && $driver['license_expiry'] !== '0000-00-00' ? $driver['license_expiry'] : null;
$licDays = $lic ? (int)round((strtotime($lic) - $today) / 86400) : null;
$licExpired = ($licDays !== null && $licDays < 0);
$licExpiringSoon = ($licDays !== null && $licDays >= 0 && $licDays <= 30);
$licMissing = empty($lic);

// Medical Exam & Certificate
$med = !empty($driver['medical_cert_expiry']) && $driver['medical_cert_expiry'] !== '0000-00-00' 
    ? $driver['medical_cert_expiry'] 
    : (!empty($driver['medical_exam_end']) && $driver['medical_exam_end'] !== '0000-00-00' ? $driver['medical_exam_end'] : null);
$medDays = $med ? (int)round((strtotime($med) - $today) / 86400) : null;
$medExpired = ($medDays !== null && $medDays < 0);
$medExpiringSoon = ($medDays !== null && $medDays >= 0 && $medDays <= 30);
$medMissing = empty($med);

// Taxi Exploitation Contract
$taxiCont = !empty($driver['taxi_contract_end']) && $driver['taxi_contract_end'] !== '0000-00-00' ? $driver['taxi_contract_end'] : null;
$taxiContDays = $taxiCont ? (int)round((strtotime($taxiCont) - $today) / 86400) : null;
$taxiContExpired = ($taxiContDays !== null && $taxiContDays < 0);
$taxiContExpiringSoon = ($taxiContDays !== null && $taxiContDays >= 0 && $taxiContDays <= 30);

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
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <!-- Tailwind CSS CDN -->
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
            .fiche-paper { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
            .page-break { page-break-before: always; }
        }
    </style>
</head>
<body class="bg-slate-100 dark:bg-[#0b0f17] text-slate-900 dark:text-slate-100 min-h-screen py-6 px-3 sm:px-6 transition-colors">

    <!-- Top Action Toolbar (Hidden in Print) -->
    <div class="max-w-5xl mx-auto mb-6 flex flex-wrap items-center justify-between gap-3 no-print bg-white dark:bg-[#161d27] p-3.5 sm:p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs">
        <div class="flex items-center gap-3 flex-wrap">
            <?php if (!is_driver()): ?>
            <a href="drivers.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition whitespace-nowrap">
                <svg class="w-4 h-4 shrink-0 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                <span>سجل السائقين</span>
            </a>
            <?php else: ?>
            <a href="index.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition whitespace-nowrap">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
                <span>لوحة التحكم</span>
            </a>
            <?php endif; ?>
            <div class="hidden sm:block h-5 w-px bg-slate-200 dark:bg-slate-800"></div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-bold text-slate-600 dark:text-slate-400">البطاقة الفنية:</span>
                <span class="font-extrabold text-slate-900 dark:text-white text-xs"><?= htmlspecialchars($driver['clean_name'] ?? $driver['full_name']) ?> <?= !empty($driver['is_replacement']) ? '<span class="text-amber-600 dark:text-amber-400 font-bold text-xs mr-1">(بديل)</span>' : '' ?></span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-700/60 font-num">
                    <?= htmlspecialchars($driver['card_number'] ?? 'دفتر المقررة رقم 4') ?>
                </span>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap justify-end">
            <?php if (!is_driver()): ?>
                <a href="drivers.php?filter=alerts&open_docs_id=<?= $driver['id'] ?>" 
                   class="px-3.5 py-2 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-900 dark:text-amber-200 border border-amber-300 dark:border-amber-700/60 font-bold text-xs transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0 shadow-2xs"
                   title="تحديث وتجديد رخصة السياقة، الفحص الطبي، وعقد رخصة الأجرة مباشرة">
                    <svg class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                    <span>⚡ إدارة وتجديد الوثائق</span>
                </a>
                <?php if (!empty($probInfo['is_ready_for_promotion'])): ?>
                    <form method="POST" action="" class="inline-flex items-center m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_driver_stage">
                        <input type="hidden" name="driver_id" value="<?= $driver['id'] ?>">
                        <input type="hidden" name="probation_stage" value="official">
                        <button type="submit" 
                                class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-black text-xs shadow-xs transition inline-flex items-center gap-1.5 whitespace-nowrap cursor-pointer"
                                title="اكتملت 9 أشهر (3 فترات تجريبية) - انقر هنا للتثبيت كسائق رسمي فوراً">
                            <svg class="w-3.5 h-3.5 text-amber-300 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                            <span>⭐ تثبيت كسائق رسمي (اكتملت 9 أشهر)</span>
                        </button>
                    </form>
                <?php endif; ?>
                <form method="POST" action="" class="inline-flex items-center m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_driver_stage">
                    <input type="hidden" name="driver_id" value="<?= $driver['id'] ?>">
                    <select name="probation_stage" onchange="this.form.submit()" 
                            class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 hover:bg-white dark:hover:bg-slate-700 transition cursor-pointer shadow-2xs"
                            title="ترقية وتعديل المرحلة المهنية للسائق">
                        <option value="trial_1" <?= ($driver['probation_stage'] ?? 'trial_1') === 'trial_1' ? 'selected' : '' ?>>فترة تجريبية 1 (3 أشهر)</option>
                        <option value="trial_2" <?= ($driver['probation_stage'] ?? '') === 'trial_2' ? 'selected' : '' ?>>فترة تجريبية 2 (3 أشهر)</option>
                        <option value="trial_3" <?= ($driver['probation_stage'] ?? '') === 'trial_3' ? 'selected' : '' ?>>فترة تجريبية 3 (3 أشهر)</option>
                        <option value="official" <?= ($driver['probation_stage'] ?? '') === 'official' ? 'selected' : '' ?>>سائق رسمي (تثبيت دائم يدوي)</option>
                    </select>
                </form>
            <?php endif; ?>
            <?php if (!empty($driver['contract_id'])): ?>
                <a href="contract_view.php?id=<?= $driver['contract_id'] ?>" target="_blank" 
                   class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                    <svg class="w-3.5 h-3.5 text-slate-500 dark:text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    <span>معاينة العقد <bdi dir="ltr">(90-11)</bdi></span>
                </a>
            <?php elseif (is_admin() || is_owner()): ?>
                <a href="contracts.php?action=new&driver_id=<?= $driver['id'] ?>" 
                   class="px-3.5 py-2 rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-800 font-bold text-xs transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0 border border-teal-200">
                    <svg class="w-3.5 h-3.5 text-teal-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>إصدار عقد <bdi dir="ltr">(90-11)</bdi></span>
                </a>
            <?php endif; ?>
                <a href="debt_statement.php?user_id=<?= $driver['id'] ?>" target="_blank" 
                   class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0" title="كشف الحساب المالي الرسمي للسائق">
                    <svg class="w-3.5 h-3.5 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>كشف الحساب <bdi dir="ltr">(Solde)</bdi></span>
                </a>
            <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs inline-flex items-center gap-2 whitespace-nowrap shrink-0 cursor-pointer">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                </svg>
                <span>طباعة البطاقة الفنية (PDF)</span>
            </button>
            <?= render_theme_toggle_btn('p-2') ?>
        </div>
    </div>

    <!-- Official Document Container (Fiche Technique Paper) -->
    <div class="fiche-paper max-w-5xl mx-auto bg-white dark:bg-[#161d27] p-6 sm:p-10 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-6">

        <!-- Header: Official Republic & Ministry Banner -->
        <div class="border-b-2 border-slate-900 dark:border-slate-700 pb-5 space-y-2 text-center">
            <div class="flex items-center justify-between">
                <div class="w-28 text-right text-[11px] font-bold text-slate-600 dark:text-slate-300 leading-tight">
                    <div>الجمهورية الجزائرية</div>
                    <div>الديمقراطية الشعبية</div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 mt-1">مديرية النقل لولاية <?= htmlspecialchars($driver['wilaya'] ?? 'أدرار') ?></div>
                </div>
                <div class="text-center space-y-1">
                    <div class="w-12 h-12 mx-auto rounded-2xl bg-amber-500/10 border border-amber-300 dark:border-amber-600/40 flex items-center justify-center p-1.5 shadow-2xs">
                        <img src="<?= url('/logo.webp') ?>" alt="تاكسي نقيلو" class="w-full h-full object-contain">
                    </div>
                    <h1 class="text-lg sm:text-xl font-black text-slate-900 dark:text-white tracking-tight">البطاقة الفنية للسائق المهني ودفتر المقررة</h1>
                    <h2 class="text-xs font-bold text-amber-700 dark:text-amber-400 tracking-wide font-num">FICHE TECHNIQUE DU CHAUFFEUR DE TAXI • LIVRET DE BORD</h2>
                </div>
                <div class="w-28 text-left text-[10px] text-slate-600 dark:text-slate-400 font-num">
                    <div class="font-bold text-slate-900 dark:text-white text-xs" dir="ltr"><?= htmlspecialchars($driver['card_number'] ?? 'LIV-01-2024') ?></div>
                    <div class="text-slate-600 dark:text-slate-300">اعتماد رسمي</div>
                    <div class="text-emerald-700 dark:text-emerald-400 font-bold">مطابق 90-11</div>
                </div>
            </div>
        </div>

        <!-- Identity & Photo Card (Hero Strip) -->
        <div class="bg-slate-50/80 dark:bg-[#1a2332] p-5 rounded-2xl border border-amber-300/80 dark:border-amber-500/30 flex flex-col sm:flex-row items-center sm:items-start gap-6 shadow-2xs">
            
            <!-- Driver Photo with Seal & Stamp effect -->
            <div class="relative shrink-0">
                <div class="w-32 h-40 rounded-xl overflow-hidden border-2 border-amber-400 dark:border-amber-500 shadow-md bg-white dark:bg-slate-800 flex flex-col items-center justify-center relative">
                    <?php if (!empty($driver['avatar_url']) && file_exists(__DIR__ . '/../' . ltrim($driver['avatar_url'], '/'))): ?>
                        <img src="<?= url($driver['avatar_url']) ?>" alt="<?= htmlspecialchars($driver['full_name']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <!-- Sleek Driver Silhouette Avatar with Initials -->
                        <div class="w-full h-full flex flex-col items-center justify-center bg-gradient-to-b from-amber-50/60 to-slate-100 dark:from-slate-800 dark:to-slate-900 text-slate-700 dark:text-slate-200 p-2">
                            <div class="w-16 h-16 rounded-full bg-amber-500/20 dark:bg-amber-400/20 border-2 border-amber-400/50 flex items-center justify-center text-amber-700 dark:text-amber-300 font-black text-2xl mb-1 shadow-inner">
                                <?= mb_substr($driver['full_name'], 0, 1) ?>
                            </div>
                            <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 font-num">سائق معتمد</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="absolute -bottom-2.5 inset-x-0 mx-auto w-max px-2.5 py-0.5 rounded-full bg-emerald-600 text-white font-bold text-[10px] shadow-sm flex items-center gap-1">
                    <span>✓</span><span>مصدق رسمياً</span>
                </div>
            </div>

            <!-- Core Identity Info -->
            <div class="flex-1 space-y-3 text-right w-full">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-200 dark:border-slate-700/80 pb-3">
                    <div>
                        <div class="text-xs font-bold text-slate-600 dark:text-slate-400">الاسم واللقب الكامل:</div>
                        <h3 class="text-xl sm:text-2xl font-black text-slate-950 dark:text-white tracking-tight flex items-center gap-2 flex-wrap">
                            <span><?= htmlspecialchars($driver['clean_name'] ?? $driver['full_name']) ?></span>
                            <?php if (!empty($driver['is_replacement'])): ?>
                                <span class="text-amber-600 dark:text-amber-400 font-extrabold text-lg mr-1">(بديل)</span>
                            <?php endif; ?>
                        </h3>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold <?= !empty($driver['is_replacement']) ? 'bg-amber-100 text-amber-900 border border-amber-300' : 'bg-amber-500 text-slate-950' ?> shadow-2xs">
                            سائق <?= !empty($driver['is_replacement']) ? 'بديل (Remplaçant)' : ($driver['driver_type'] === 'ajir' ? 'أجير (Salarié)' : 'مؤجر / مهني') ?>
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold border <?= $probInfo['cls'] ?>">
                            <span><?= $probInfo['label'] ?></span>
                            <?php if (!empty($probInfo['sub_label'])): ?>
                                <span class="opacity-75 text-[10px] font-normal mr-1 font-num">(<?= $probInfo['sub_label'] ?>)</span>
                            <?php endif; ?>
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">
                            نشط ميدانياً
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3.5 text-xs">
                    <div class="p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/40 border border-slate-200/70 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-0.5">اللقب (Nom):</span>
                        <strong class="text-slate-950 dark:text-white text-sm font-bold"><?= !empty($driver['last_name']) ? htmlspecialchars($driver['last_name']) : '—' ?></strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/40 border border-slate-200/70 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-0.5">الاسم (Prénom):</span>
                        <strong class="text-slate-950 dark:text-white text-sm font-bold"><?= !empty($driver['first_name']) ? htmlspecialchars($driver['first_name']) : '—' ?></strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/40 border border-slate-200/70 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-0.5">تاريخ ومكان الازدياد:</span>
                        <strong class="text-slate-950 dark:text-white font-bold flex items-center gap-1">
                            <span class="font-num" dir="ltr"><?= !empty($driver['birth_date']) ? htmlspecialchars($driver['birth_date']) : '—' ?></span> 
                            <?php if (!empty($driver['birth_place'])): ?>
                                <span>(<?= htmlspecialchars($driver['birth_place']) ?>)</span>
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/40 border border-slate-200/70 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-0.5">ابن (Fils de):</span>
                        <strong class="text-slate-900 dark:text-slate-100 font-bold"><?= !empty($driver['father_name']) ? htmlspecialchars($driver['father_name']) : '—' ?></strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/40 border border-slate-200/70 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-0.5">و (Et de):</span>
                        <strong class="text-slate-900 dark:text-slate-100 font-bold"><?= !empty($driver['mother_name']) ? htmlspecialchars($driver['mother_name']) : '—' ?></strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/40 border border-slate-200/70 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-0.5">العنوان:</span>
                        <strong class="text-slate-900 dark:text-slate-100 font-bold truncate block" title="<?= htmlspecialchars($driver['address'] ?? '') ?>"><?= !empty($driver['address']) ? htmlspecialchars($driver['address']) : '—' ?></strong>
                    </div>
                </div>
            </div>

        </div>

        <!-- Section 1 & 2: License Details & Assigned Vehicle -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            
            <!-- Box A: رخصة السياقة والكفاءة المهنية -->
            <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#1a2332] space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full <?= $licExpired ? 'bg-rose-500' : ($licExpiringSoon ? 'bg-amber-500' : ($licMissing ? 'bg-slate-400' : 'bg-emerald-500')) ?>"></span>
                        <h4 class="font-black text-slate-900 dark:text-white text-sm">بيانات رخصة السياقة والمهنة</h4>
                    </div>
                    <?php if ($licExpired): ?>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-500/10 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-700/60 font-num">منتهية الصلاحية</span>
                    <?php elseif ($licExpiringSoon): ?>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-700/60 font-num">تجديد وشيك</span>
                    <?php elseif ($licMissing): ?>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 font-num">غير مسجلة</span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700/60 font-num">سارية المفعول</span>
                    <?php endif; ?>
                </div>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 dark:border-slate-800/60">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">رقم رخصة السياقة:</span>
                        <span class="font-num font-bold text-slate-950 dark:text-white text-sm" dir="ltr"><?= !empty($driver['license_number']) ? htmlspecialchars($driver['license_number']) : '<span class="text-slate-400 font-normal">غير مسجل</span>' ?></span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 dark:border-slate-800/60">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">تاريخ الإصدار:</span>
                        <span class="font-num font-bold text-slate-900 dark:text-slate-200" dir="ltr"><?= !empty($driver['license_issue_date']) ? htmlspecialchars($driver['license_issue_date']) : '<span class="text-slate-400 font-normal">—</span>' ?></span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 dark:border-slate-800/60">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">جهة الإصدار الرسمية:</span>
                        <span class="font-bold text-slate-900 dark:text-slate-200"><?= !empty($driver['license_issue_place']) ? htmlspecialchars($driver['license_issue_place']) : '<span class="text-slate-400 font-normal">—</span>' ?></span>
                    </div>
                    <div class="flex justify-between items-center py-1">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">صلاحية رخصة السياقة:</span>
                        <?php if ($licExpired): ?>
                            <span class="font-num font-bold text-rose-700 dark:text-rose-400 flex items-center gap-1.5" dir="ltr">
                                <span><?= htmlspecialchars($lic) ?></span>
                                <span class="text-[10px] bg-rose-50 dark:bg-rose-950/60 px-1.5 py-0.2 rounded border border-rose-300 dark:border-rose-700 font-semibold">(منتهية منذ <?= abs($licDays) ?> يوم)</span>
                            </span>
                        <?php elseif ($licExpiringSoon): ?>
                            <span class="font-num font-bold text-amber-700 dark:text-amber-400 flex items-center gap-1.5" dir="ltr">
                                <span><?= htmlspecialchars($lic) ?></span>
                                <span class="text-[10px] bg-amber-50 dark:bg-amber-950/60 px-1.5 py-0.2 rounded border border-amber-300 dark:border-amber-700 font-semibold">(متبقي <?= $licDays ?> يوم)</span>
                            </span>
                        <?php elseif ($licMissing): ?>
                            <span class="font-bold text-slate-400">غير مسجلة بالنظام</span>
                        <?php else: ?>
                            <span class="font-num font-bold text-emerald-700 dark:text-emerald-400" dir="ltr"><?= htmlspecialchars($lic) ?> (سارية)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Box B: المركبة ورقم التسجيل المعتمد -->
            <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#1a2332] space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                        <h4 class="font-black text-slate-900 dark:text-white text-sm">المركبة المستغلة ورخصة سيارة الأجرة</h4>
                    </div>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-bold uppercase tracking-wider font-num">Véhicule Assigné</span>
                </div>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 dark:border-slate-800/60">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">رقم تسجيل المركبة (اللوحة الحالية):</span>
                        <?php if (!empty($driver['plate_number'])): ?>
                            <span class="font-num font-black text-slate-950 dark:text-white bg-amber-500/10 dark:bg-amber-400/10 px-2 py-0.5 rounded border border-amber-300 dark:border-amber-600/40 text-sm" dir="ltr">
                                <?= htmlspecialchars($driver['plate_number']) ?>
                            </span>
                        <?php else: ?>
                            <span class="font-bold text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded text-xs">
                                بدون مركبة حالياً (احتياطي)
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 dark:border-slate-800/60">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">صنف وطراز المركبة:</span>
                        <?php if (!empty($driver['brand']) || !empty($driver['model'])): ?>
                            <span class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                <span><?= htmlspecialchars(($driver['brand'] ?? '') . ' ' . ($driver['model'] ?? '')) ?></span>
                                <?php if (!empty($driver['door_number'])): ?>
                                    <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-num text-[11px] font-bold" dir="ltr">(باب: <?= htmlspecialchars($driver['door_number']) ?>)</span>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="text-slate-400 font-normal">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex justify-between items-center py-1">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">مدة صلاحية عقد رخصة الأجرة:</span>
                        <?php if (!empty($driver['taxi_contract_start']) || !empty($taxiCont)): ?>
                            <span class="font-bold text-indigo-700 dark:text-indigo-400 flex items-center gap-1 flex-wrap">
                                <span>من</span>
                                <span class="font-num" dir="ltr"><?= htmlspecialchars($driver['taxi_contract_start'] ?? '—') ?></span>
                                <span>إلى</span>
                                <span class="font-num font-black <?= $taxiContExpired ? 'text-rose-600' : ($taxiContExpiringSoon ? 'text-amber-600' : '') ?>" dir="ltr"><?= htmlspecialchars($taxiCont ?? '—') ?></span>
                                <?php if ($taxiContExpired): ?>
                                    <span class="text-[9px] px-1.5 py-0.2 rounded bg-rose-100 text-rose-800 font-num font-bold">منتهي</span>
                                <?php elseif ($taxiContExpiringSoon): ?>
                                    <span class="text-[9px] px-1.5 py-0.2 rounded bg-amber-100 text-amber-800 font-num font-bold"><?= $taxiContDays ?> يوم</span>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="text-slate-400 font-medium">غير محدد</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Section 3: الفحوصات الطبية الدورية (Visites Médicales) -->
        <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#1a2332] space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2.5">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full <?= $medExpired ? 'bg-rose-500' : ($medExpiringSoon ? 'bg-amber-500' : ($medMissing ? 'bg-slate-400' : 'bg-emerald-500')) ?>"></span>
                    <h4 class="font-black text-slate-900 dark:text-white text-sm">الفحوصات الطبية الدورية (Visites Médicales)</h4>
                </div>
                <?php if ($medExpired): ?>
                    <span class="px-2.5 py-0.5 rounded text-[10px] font-bold bg-rose-500/10 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-700/60 font-num">
                        ⚠️ منتهي الصلاحية (بحاجة لتجديد فوري)
                    </span>
                <?php elseif ($medExpiringSoon): ?>
                    <span class="px-2.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-700/60 font-num">
                        ⏳ وشيك الانتهاء (متبقي <?= $medDays ?> يوم)
                    </span>
                <?php elseif ($medMissing): ?>
                    <span class="px-2.5 py-0.5 rounded text-[10px] font-bold bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 font-num">
                        فحص طبي غير مسجل
                    </span>
                <?php else: ?>
                    <span class="px-2.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700/60 font-num">
                        ✓ ساري المفعول (لائق صحياً)
                    </span>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800 space-y-1">
                    <span class="text-slate-500 dark:text-slate-400 block font-semibold text-xs">بداية الفحص الطبي:</span>
                    <div class="font-num font-bold text-slate-900 dark:text-white text-base" dir="ltr">
                        <?= !empty($driver['medical_exam_start']) ? htmlspecialchars($driver['medical_exam_start']) : '—' ?>
                    </div>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800 space-y-1">
                    <span class="text-slate-500 dark:text-slate-400 block font-semibold text-xs">نهاية صلاحية الفحص:</span>
                    <div class="flex items-center gap-2">
                        <span class="font-num font-black <?= $medExpired ? 'text-rose-600 dark:text-rose-400' : ($medExpiringSoon ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400') ?> text-base" dir="ltr">
                            <?= !empty($med) ? htmlspecialchars($med) : '—' ?>
                        </span>
                        <?php if ($medExpired): ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-rose-100 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-700 font-num">منتهي منذ <?= abs($medDays) ?> يوم</span>
                        <?php elseif ($medExpiringSoon): ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-700 font-num">متبقي <?= $medDays ?> يوم</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 4: بداية النشاط (Début d'Activité) -->
        <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#1a2332] space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2.5">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                    <h4 class="font-black text-slate-900 dark:text-white text-sm">سريان النشاط المهني (Début d'Activité)</h4>
                </div>
                <span class="text-[10px] text-slate-500 dark:text-slate-400 font-bold uppercase tracking-wider font-num">Activité Professionnelle</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800">
                    <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-1">تاريخ بداية النشاط:</span>
                    <strong class="text-slate-950 dark:text-white text-sm font-num font-black" dir="ltr"><?= !empty($driver['activity_start_date']) ? htmlspecialchars($driver['activity_start_date']) : '—' ?></strong>
                </div>
                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800">
                    <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-1">دفتر المقررة / البطاقة:</span>
                    <strong class="text-slate-950 dark:text-white text-sm font-num font-bold" dir="ltr"><?= !empty($driver['card_number']) ? htmlspecialchars($driver['card_number']) : '—' ?></strong>
                </div>
                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800">
                    <span class="text-slate-600 dark:text-slate-400 block font-semibold mb-1">حالة النشاط والتوقف:</span>
                    <strong class="text-emerald-700 dark:text-emerald-400 text-sm font-bold">مستمر ميدانياً (دون توقف)</strong>
                </div>
            </div>
        </div>

        <!-- Section 4.5: الجدول المالي السنوي للاشتراكات والديون (Décomposition Financière Annuelle) -->
        <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#1a2332] space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-3">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    <h4 class="font-black text-slate-900 dark:text-white text-sm">الجدول المالي السنوي للاشتراكات والديون (2026)</h4>
                    <span class="text-[10px] text-slate-400 font-num font-bold">12 MOIS</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-num" dir="ltr">
                        المستحق السنوي: <?= number_format($totalSubDue > 0 ? $totalSubDue : 48000, 2) ?> DZD
                    </span>
                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 font-num border border-emerald-200/60" dir="ltr">
                        المحصل: <?= number_format($totalSubPaid, 2) ?> DZD
                    </span>
                    <?php 
                        $resteDette = max(0, ($totalSubDue > 0 ? $totalSubDue : 48000) - $totalSubPaid);
                    ?>
                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold <?= $resteDette > 0 ? 'bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200/60' : 'bg-slate-100 dark:bg-slate-800 text-slate-500' ?> font-num" dir="ltr">
                        الذمة: <?= number_format($resteDette, 2) ?> DZD
                    </span>
                </div>
            </div>

            <!-- 12-Month Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 dark:bg-slate-900/60 text-slate-700 dark:text-slate-300 font-bold border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="p-2.5">الشهر (Mois)</th>
                            <th class="p-2.5 text-center">اشتراك المركبة</th>
                            <th class="p-2.5 text-center">رسوم الحيز</th>
                            <th class="p-2.5 text-center">المستحق الإجمالي</th>
                            <th class="p-2.5 text-center">المسدد (Versé)</th>
                            <th class="p-2.5 text-center">المتبقي (Reste)</th>
                            <th class="p-2.5 text-center">الوضعية المالية</th>
                            <th class="p-2.5 text-center">الوصل الرسمي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                        <?php
                        $arabicMonths = [
                            1 => 'جانفي (Jan)', 2 => 'فيفري (Fév)', 3 => 'مارس (Mar)', 4 => 'أفريل (Avr)',
                            5 => 'ماي (Mai)', 6 => 'جوان (Juin)', 7 => 'جويلية (Juil)', 8 => 'أوت (Août)',
                            9 => 'سبتمبر (Sep)', 10 => 'أكتوبر (Oct)', 11 => 'نوفمبر (Nov)', 12 => 'ديسمبر (Déc)'
                        ];
                        $curMonthNum = intval(date('n'));

                        for ($m = 1; $m <= 12; $m++):
                            $mKey = '2026-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                            $subItem = $driverSubs[$mKey] ?? null;

                            $vFee = $subItem ? floatval($subItem['vehicle_fee']) : 4000.00;
                            $sFee = $subItem ? floatval($subItem['space_fee']) : 350.00;
                            $totDue = $subItem ? floatval($subItem['total_due']) : 4350.00;
                            $paid = $subItem ? floatval($subItem['paid_amount']) : 0.00;
                            $rem = max(0, $totDue - $paid);

                            $isPast = ($m < $curMonthNum);
                            $isCur = ($m === $curMonthNum);
                            $isUpcoming = ($m > $curMonthNum);

                            $isRegle = ($subItem && $subItem['status'] === 'regle') || ($paid >= $totDue && $totDue > 0);
                            $isPartiel = ($subItem && $subItem['status'] === 'partiel') || ($paid > 0 && $paid < $totDue);
                        ?>
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-900/40 transition <?= $isCur ? 'bg-amber-50/30 dark:bg-amber-950/20' : '' ?>">
                                <td class="p-2.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                        <?php if ($isCur): ?>
                                            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                        <?php endif; ?>
                                        <span><?= $arabicMonths[$m] ?></span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-num" dir="ltr"><?= $mKey ?></span>
                                </td>
                                <td class="p-2.5 text-center font-num text-slate-700 dark:text-slate-300" dir="ltr"><?= number_format($vFee, 0) ?> دج</td>
                                <td class="p-2.5 text-center font-num text-slate-500 dark:text-slate-400" dir="ltr"><?= number_format($sFee, 0) ?> دج</td>
                                <td class="p-2.5 text-center font-num font-bold text-slate-900 dark:text-white" dir="ltr"><?= number_format($totDue, 0) ?> دج</td>
                                <td class="p-2.5 text-center font-num font-bold text-emerald-600 dark:text-emerald-400" dir="ltr"><?= number_format($paid, 0) ?> دج</td>
                                <td class="p-2.5 text-center font-num font-bold <?= $rem > 0 && !$isUpcoming ? 'text-rose-600 dark:text-rose-400' : 'text-slate-400' ?>" dir="ltr">
                                    <?= $isUpcoming && $rem == $totDue ? '-' : number_format($rem, 0) . ' دج' ?>
                                </td>
                                <td class="p-2.5 text-center whitespace-nowrap">
                                    <?php if ($isRegle): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">
                                            مسدد بالكامل ✓
                                        </span>
                                    <?php elseif ($isPartiel): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-700">
                                            تسوية جزئية
                                        </span>
                                    <?php elseif ($isUpcoming): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 border border-slate-200 dark:border-slate-700">
                                            استحقاق قادم
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-700">
                                            معلق (ذمة)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2.5 text-center whitespace-nowrap">
                                    <?php if ($subItem): ?>
                                        <a href="/dashboard/invoice.php?type=subscription&id=<?= $subItem['id'] ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[10px] font-bold bg-amber-500 hover:bg-amber-600 text-slate-950 transition shadow-2xs">
                                            <span>فاتورة</span>
                                            <span class="font-num">🖨️</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-[10px] text-slate-400 font-num">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <!-- Versements History for this driver -->
            <?php if (!empty($driverVersements)): ?>
            <div class="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-2">
                <span class="block font-bold text-xs text-slate-700 dark:text-slate-300">سجل الوصولات والدفعات المباشرة (Derniers Versements):</span>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2.5 text-xs">
                    <?php foreach ($driverVersements as $vr): ?>
                    <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <div>
                            <div class="font-num font-black text-emerald-600 dark:text-emerald-400" dir="ltr"><?= number_format($vr['amount'] ?? 0, 2) ?> DZD</div>
                            <div class="text-[10px] text-slate-400 font-num" dir="ltr"><?= substr($vr['payment_date'] ?? '', 0, 10) ?> • <?= htmlspecialchars($vr['payment_method'] ?? 'Espèces') ?></div>
                        </div>
                        <a href="/dashboard/invoice.php?type=versement&id=<?= $vr['id'] ?>" target="_blank" 
                           class="px-2 py-1 rounded bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-white font-bold text-[10px] hover:bg-amber-500 hover:text-slate-950 transition">
                            وصل 🖨️
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>


        <!-- Official Signatures & Seal Area (For Print & Authority Checks) -->
        <div class="pt-6 border-t-2 border-slate-900 dark:border-slate-700 grid grid-cols-3 gap-6 text-center text-xs">
            <div class="space-y-10">
                <span class="font-bold text-slate-800 dark:text-slate-200 block">إمضاء وبصمة السائق المهني</span>
                <div class="text-slate-600 dark:text-slate-400 text-xs font-semibold"><?= htmlspecialchars($driver['full_name']) ?></div>
            </div>
            <div class="space-y-10">
                <span class="font-bold text-slate-800 dark:text-slate-200 block">ختم وتأشيرة مؤسسة تاكسي نقيلو</span>
                <div class="text-slate-600 dark:text-slate-400 text-xs font-semibold font-num">EURL TAXI NAGUILOU</div>
            </div>
            <div class="space-y-10">
                <span class="font-bold text-slate-800 dark:text-slate-200 block">تأشيرة مديرية النقل لولاية <?= htmlspecialchars($driver['wilaya'] ?? 'أدرار') ?></span>
                <div class="text-slate-600 dark:text-slate-400 text-xs font-semibold">مفتشية النقل البري</div>
            </div>
        </div>

    </div>

</body>
</html>
