<?php
// dashboard/debts.php - Finances & Dettes: Gestion des créances, versements et recouvrement
$pageTitle = 'Finances & Dettes | إدارة الديون والتحصيل';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin']);

$pdo = getDBConnection();

// Handle Recalculate Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate_month'])) {
    require_csrf_token();
    try {
        $pdo->beginTransaction();
        $debtsList = $pdo->query("SELECT id, total_credits FROM driver_debts")->fetchAll();
        $recalculatedCount = 0;
        foreach ($debtsList as $dbItem) {
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_v, MAX(payment_date) as last_v FROM versements WHERE debt_id = ?");
            $sumStmt->execute([$dbItem['id']]);
            $sumData = $sumStmt->fetch();
            $totalV = (float)($sumData['total_v'] ?? 0);
            $lastV = $sumData['last_v'];
            $st = (($dbItem['total_credits'] - $totalV) <= 0) ? 'a_jour' : 'endette';

            $upStmt = $pdo->prepare("UPDATE driver_debts SET total_versements = ?, last_versement_date = ?, status = ? WHERE id = ?");
            $upStmt->execute([$totalV, $lastV, $st, $dbItem['id']]);
            $recalculatedCount++;
        }
        $pdo->commit();
        log_audit($pdo, 'recalculate_debts', 'driver_debts', null, "تمت إعادة احتساب ومطابقة حسابات $recalculatedCount سائقين مع سجل الدفعات");
        set_flash('success', "تمت إعادة مطابقة واحتساب أرصدة $recalculatedCount سائقين مع سجل الدفعات بنجاح");
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'تعذر إعادة احتساب الأرصدة: ' . $e->getMessage());
    }
    redirect('/dashboard/debts.php');
}

// Handle Register Versement (Payment)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_versement') {
    require_csrf_token();
    $debtId = intval($_POST['debt_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'especes';
    $date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $receipt = trim($_POST['receipt_ref'] ?? ('VER-' . date('Y') . '-' . rand(100, 999)));
    $notes = trim($_POST['notes'] ?? '');

    if ($debtId > 0 && $amount > 0) {
        try {
            $pdo->beginTransaction();
            // Insert versement
            $stmtV = $pdo->prepare("
                INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtV->execute([$debtId, $amount, $method, $date, $receipt, $notes]);

            // Update debt totals
            $stmtU = $pdo->prepare("
                UPDATE driver_debts 
                SET total_versements = total_versements + ?,
                    last_versement_date = ?,
                    status = IF((total_credits - (total_versements + ?)) <= 0, 'a_jour', 'endette')
                WHERE id = ?
            ");
            $stmtU->execute([$amount, $date, $amount, $debtId]);
            $newVId = (int)$pdo->lastInsertId();

            $pdo->commit();
            log_audit($pdo, 'versement_created', 'versements', $newVId, "تسجيل دفعة بقيمة $amount دج للسجل رقم $debtId بوصل: $receipt");
            set_flash('success', "تم تسجيل دفعة بقيمة " . number_format($amount, 2) . " دج بنجاح وتحديث الرصيد");
            redirect('/dashboard/debts.php');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء تسجيل الدفعة: ' . $e->getMessage());
        }
    }
}

// Handle Add New Debt / Loan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_debt') {
    require_csrf_token();
    $userId = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
    $chauffeurName = trim($_POST['chauffeur_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $totalCredits = floatval($_POST['total_credits'] ?? 0);
    $initialVersement = floatval($_POST['initial_versement'] ?? 0);
    $driverType = in_array($_POST['driver_type'] ?? '', ['actif', 'ancien']) ? $_POST['driver_type'] : 'actif';
    $notes = trim($_POST['notes'] ?? '');

    // Auto-fill from users table if selected
    if ($userId && empty($chauffeurName)) {
        $uStmt = $pdo->prepare("SELECT full_name, phone FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $uData = $uStmt->fetch();
        if ($uData) {
            $chauffeurName = $uData['full_name'];
            if (empty($phone)) $phone = $uData['phone'];
        }
    }

    if (!empty($chauffeurName) && $totalCredits > 0) {
        try {
            $pdo->beginTransaction();
            $status = ($totalCredits - $initialVersement) <= 0 ? 'a_jour' : 'endette';
            $stmtDebt = $pdo->prepare("
                INSERT INTO driver_debts (user_id, chauffeur_name, phone, total_credits, total_versements, driver_type, status, last_versement_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $lastVDate = ($initialVersement > 0) ? date('Y-m-d') : null;
            $stmtDebt->execute([$userId, $chauffeurName, $phone, $totalCredits, $initialVersement, $driverType, $status, $lastVDate, $notes]);
            $newDebtId = (int)$pdo->lastInsertId();

            if ($initialVersement > 0) {
                $stmtV = $pdo->prepare("
                    INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, notes)
                    VALUES (?, ?, 'especes', CURDATE(), ?, 'دفعة أولية عند تسجيل الدين')
                ");
                $receipt = 'VER-' . date('Y') . '-' . rand(100, 999);
                $stmtV->execute([$newDebtId, $initialVersement, $receipt]);
            }

            $pdo->commit();
            log_audit($pdo, 'debt_created', 'driver_debts', $newDebtId, "إضافة قيد مالي جديد للسائق $chauffeurName بمبلغ $totalCredits دج");
            set_flash('success', "تم تسجيل القيد المالي للسائق بنجاح بمبلغ " . number_format($totalCredits, 2) . " دج");
            redirect('/dashboard/debts.php');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء حفظ القيد: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى إدخال اسم السائق ومبلغ الدين/القرض بشكل صحيح');
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, endette, a_jour
$driverType = $_GET['type'] ?? 'all'; // all, actif, ancien

$registeredDrivers = [];
$debts = [];
$dettesActifs = 0.0;
$creancesAnciens = 0.0;
$countActifs = 0;
$countAnciens = 0;

if ($pdo) {
    try {
        $stmtD = $pdo->query("SELECT id, full_name, phone FROM users WHERE role = 'driver' ORDER BY full_name ASC");
        $registeredDrivers = $stmtD ? $stmtD->fetchAll() : [];
    } catch (\Throwable $e) {
        $registeredDrivers = [];
    }

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(chauffeur_name LIKE ? OR phone LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filter === 'endette') {
        $where[] = "status = 'endette'";
    } elseif ($filter === 'a_jour') {
        $where[] = "status = 'a_jour'";
    }

    if ($driverType === 'actif') {
        $where[] = "driver_type = 'actif'";
    } elseif ($driverType === 'ancien') {
        $where[] = "driver_type = 'ancien'";
    }

    try {
        $sql = "SELECT * FROM driver_debts WHERE " . implode(' AND ', $where) . " ORDER BY (total_credits - total_versements) DESC, id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $debts = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        error_log("debts.php - Query debts notice: " . $e->getMessage());
        $debts = [];
    }

    // Dynamic KPI Calculations from Real Database Data
    try {
        $dettesActifs = (float)$pdo->query("
            SELECT COALESCE(SUM(total_credits - total_versements), 0) 
            FROM driver_debts 
            WHERE driver_type = 'actif' AND (total_credits - total_versements) > 0
        ")->fetchColumn();
    } catch (\Throwable $e) {
        $dettesActifs = 0.0;
    }

    try {
        $creancesAnciens = (float)$pdo->query("
            SELECT COALESCE(SUM(total_credits - total_versements), 0) 
            FROM driver_debts 
            WHERE driver_type = 'ancien' AND (total_credits - total_versements) > 0
        ")->fetchColumn();
    } catch (\Throwable $e) {
        $creancesAnciens = 0.0;
    }

    try {
        $countActifs = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE driver_type = 'actif'")->fetchColumn();
    } catch (\Throwable $e) {
        $countActifs = 0;
    }

    try {
        $countAnciens = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE driver_type = 'ancien'")->fetchColumn();
    } catch (\Throwable $e) {
        $countAnciens = 0;
    }
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-black text-slate-900 tracking-tight">المالية والديون</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    متابعة المستحقات
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">إدارة قروض ودفعات السائقين المباشرين والأرشيف</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('addDebtModal').classList.remove('hidden'); document.getElementById('addDebtModal').classList.add('flex');"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>إضافة دين / قرض</span>
            </button>

            <button onclick="document.getElementById('aideModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                </svg>
                <span>مساعدة</span>
            </button>

            <form method="POST">
                <?= csrf_field() ?>
                <button type="submit" name="recalculate_month" value="1"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-amber-300 hover:border-amber-400 bg-amber-50 hover:bg-amber-100 text-amber-900 font-bold text-xs transition shadow-xs">
                    <svg class="w-4 h-4 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    <span>تسوية أوت 2026</span>
                </button>
            </form>
        </div>
    </div>

    <!-- 2 Main KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        
        <!-- Dettes Chauffeurs Actifs -->
        <a href="debts.php?type=actif" 
           class="bg-white p-5 rounded-2xl border <?= $driverType === 'actif' ? 'border-rose-400 ring-2 ring-rose-100' : 'border-slate-200/80' ?> hover:border-rose-300 shadow-xs space-y-3 relative overflow-hidden transition block">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">ديون السائقين النشطين</span>
                <span class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-rose-600 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($dettesActifs, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-slate-700 font-bold">السائقون النشطون:</span>
                <span class="font-num font-bold text-slate-900 px-2 py-0.5 bg-slate-100 rounded" dir="ltr"><?= $countActifs ?></span>
                <span class="text-slate-400 mr-2">• مستحقات الاشتراكات اليومية المعلقة</span>
            </div>
        </a>

        <!-- Créances Anciens Chauffeurs -->
        <a href="debts.php?type=ancien" 
           class="bg-white p-5 rounded-2xl border <?= $driverType === 'ancien' ? 'border-amber-400 ring-2 ring-amber-100' : 'border-slate-200/80' ?> hover:border-amber-300 shadow-xs space-y-3 relative overflow-hidden transition block">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">ديون السائقين السابقين</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-amber-600 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($creancesAnciens, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-slate-700 font-bold">الأرشيف (الديون):</span>
                <span class="font-num font-bold text-slate-900 px-2 py-0.5 bg-slate-100 rounded" dir="ltr"><?= $countAnciens ?></span>
                <span class="text-slate-400 mr-2">• مبالغ قيد المتابعة القضائية والودية</span>
            </div>
        </a>

    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col md:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="البحث عن سائق..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filter !== 'all' || $driverType !== 'all'): ?>
                <a href="debts.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <!-- Driver Type & Status Filters -->
        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            
            <!-- Type Tabs -->
            <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg">
                <a href="debts.php?type=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>&filter=<?= $filter ?>" 
                   class="px-2.5 py-1 rounded-md font-bold text-[11px] transition <?= $driverType === 'all' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900' ?>">
                    الكل
                </a>
                <a href="debts.php?type=actif<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>&filter=<?= $filter ?>" 
                   class="px-2.5 py-1 rounded-md font-bold text-[11px] transition flex items-center gap-1 <?= $driverType === 'actif' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900' ?>">
                    <span>السائقون النشطون</span>
                    <span class="font-num text-[10px] px-1 bg-slate-200/60 rounded"><?= $countActifs ?></span>
                </a>
                <a href="debts.php?type=ancien<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>&filter=<?= $filter ?>" 
                   class="px-2.5 py-1 rounded-md font-bold text-[11px] transition flex items-center gap-1 <?= $driverType === 'ancien' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900' ?>">
                    <span>الأرشيف (الديون)</span>
                    <span class="font-num text-[10px] px-1 bg-slate-200/60 rounded"><?= $countAnciens ?></span>
                </a>
            </div>

            <!-- Status Tabs -->
            <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg">
                <a href="debts.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>&type=<?= $driverType ?>" 
                   class="px-2.5 py-1 rounded-md font-bold text-[11px] transition <?= $filter === 'all' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900' ?>">
                    الكل
                </a>
                <a href="debts.php?filter=endette<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>&type=<?= $driverType ?>" 
                   class="px-2.5 py-1 rounded-md font-bold text-[11px] transition <?= $filter === 'endette' ? 'bg-rose-500 text-white shadow-2xs' : 'text-rose-700 hover:text-rose-900' ?>">
                    مدين
                </a>
                <a href="debts.php?filter=a_jour<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>&type=<?= $driverType ?>" 
                   class="px-2.5 py-1 rounded-md font-bold text-[11px] transition <?= $filter === 'a_jour' ? 'bg-emerald-600 text-white shadow-2xs' : 'text-emerald-700 hover:text-emerald-900' ?>">
                    مسدد
                </a>
            </div>

        </div>

    </div>

    <!-- Debts Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-700 font-bold border-b border-slate-200/90 whitespace-nowrap">
                    <tr>
                        <th class="p-3.5 text-center w-12 font-num">رقم</th>
                        <th class="p-3.5">السائق</th>
                        <th class="p-3.5 text-center font-num">إجمالي القروض</th>
                        <th class="p-3.5 text-center font-num">إجمالي الدفعات</th>
                        <th class="p-3.5 text-center font-num">الرصيد المتبقي</th>
                        <th class="p-3.5 text-center">الحالة</th>
                        <th class="p-3.5 text-center">إجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($debts)): ?>
                        <?php foreach ($debts as $idx => $d): 
                            $reste = max(0, $d['total_credits'] - $d['total_versements']);
                            $isEndette = ($reste > 0);
                        ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 whitespace-nowrap text-center font-num font-bold text-slate-400" dir="ltr">
                                    <?= $idx + 1 ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 text-[13px]"><?= htmlspecialchars($d['chauffeur_name']) ?></div>
                                    <div class="text-[11px] text-slate-500 flex items-center gap-1.5 font-num">
                                        <span dir="ltr"><?= htmlspecialchars($d['phone']) ?></span>
                                        <?= $d['driver_type'] === 'ancien' ? '<span class="text-amber-600 font-bold">(سابق)</span>' : '' ?>
                                    </div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-num font-bold text-slate-800">
                                    <span class="inline-block" dir="ltr"><?= number_format($d['total_credits'], 2, ',', ' ') ?> DZD</span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-num font-bold text-emerald-600">
                                    <span class="inline-block" dir="ltr"><?= number_format($d['total_versements'], 2, ',', ' ') ?> DZD</span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-num font-bold <?= $isEndette ? 'text-rose-600' : 'text-slate-400' ?>">
                                    <span class="inline-block" dir="ltr"><?= number_format($reste, 2, ',', ' ') ?> DZD</span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($isEndette): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200/60">
                                            مدين
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                            مسدد
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <button onclick="openVersementModal(<?= $d['id'] ?>, '<?= htmlspecialchars($d['chauffeur_name'], ENT_QUOTES) ?>', <?= $reste ?>)" 
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-2xs">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        <span>تسجيل دفعة</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-10 text-center text-slate-400 text-xs">
                                لا يوجد سائقون مطابقون لمعايير البحث الحالية.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Enregistrer un versement -->
<div id="versementModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-md w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">تسجيل دفعة جديدة (Versement)</h3>
                <span id="modalDriverName" class="text-xs font-semibold text-amber-700 block mt-0.5"></span>
            </div>
            <button onclick="closeVersementModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_versement">
            <input type="hidden" id="modalDebtId" name="debt_id" value="0">

            <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl flex items-center justify-between text-xs">
                <span class="text-slate-500">المبلغ المتبقي حالياً:</span>
                <span id="modalResteAmount" class="font-num font-bold text-rose-600 text-sm" dir="ltr"></span>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">مبلغ الدفعة (دج) <span class="text-rose-500">*</span></label>
                <input type="number" step="100" id="versementAmount" name="amount" required placeholder="مثال: 45000"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs font-num font-bold focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">طريقة الدفع</label>
                    <select name="payment_method" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white transition">
                        <option value="especes">نقداً (Espèces)</option>
                        <option value="baridimob">بريدي موب (BaridiMob)</option>
                        <option value="ccp">حوالة بريدية (CCP)</option>
                        <option value="virement">تحويل بنكي</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ الدفع</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs font-num bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">رقم الوصل أو مرجع المعاملة</label>
                <input type="text" name="receipt_ref" placeholder="REC-<?= date('Y') ?>-..." dir="ltr"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 text-xs font-num font-mono text-right focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeVersementModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    تأكيد تسجيل الدفعة
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Modal: Aide (Finances & Dettes) -->
<div id="aideModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-xl w-full rounded-2xl p-6 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2">
                <h3 class="font-bold text-slate-900 text-base">دليل إدارة المستحقات والتحصيل</h3>
            </div>
            <button onclick="document.getElementById('aideModal').classList.add('hidden')" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="text-xs text-slate-600 space-y-3 leading-relaxed">
            <p><strong>حساب الاشتراكات:</strong> يتم تسجيل التزام السائق اليومي أو الشهري بناءً على عقد الاستغلال (1 000 دج اشتراك المنصة + 4 000 دج للمركبة).</p>
            <p><strong>طرق التسديد:</strong> يمكن تسجيل الدفعات نقداً مع تسليم وصل استلام، أو عبر حوالة بريدية CCP أو تطبيق بريدي موب BaridiMob.</p>
            <p><strong>إعادة الاحتساب:</strong> تتيح أداة إعادة الاحتساب مزامنة كافة جداول السائقين مع سجل الدفعات الفعلي وتحديث وضعية السائق تلقائياً إلى "مُسدّد" فور بلوغ الرصيد صفراً.</p>
        </div>

        <div class="pt-3 border-t border-slate-100 flex justify-end">
            <button onclick="document.getElementById('aideModal').classList.add('hidden')" class="px-4 py-2 bg-slate-900 text-white rounded-lg font-bold text-xs">
                إغلاق
            </button>
        </div>
    </div>
</div>

<!-- Modal: Ajouter une dette / créance -->
<div id="addDebtModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">تسجيل دين أو قرض جديد للسائق</h3>
                <span class="text-xs text-slate-500">إضافة مستحق مالي مباشر أو رصيد لسائق نشط أو من الأرشيف</span>
            </div>
            <button onclick="document.getElementById('addDebtModal').classList.add('hidden'); document.getElementById('addDebtModal').classList.remove('flex');" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_debt">

            <!-- Driver selection or manual entry -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">اختيار سائق مسجل في المنصة</label>
                <select name="user_id" onchange="onDriverSelectChange(this)" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- اختر سائق مسجل أو اترك فارغاً للإدخال اليدوي --</option>
                    <?php foreach ($registeredDrivers as $rd): ?>
                        <option value="<?= $rd['id'] ?>" data-name="<?= htmlspecialchars($rd['full_name']) ?>" data-phone="<?= htmlspecialchars($rd['phone']) ?>">
                            <?= htmlspecialchars($rd['full_name']) ?> (<?= htmlspecialchars($rd['phone']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">اسم السائق <span class="text-rose-500">*</span></label>
                    <input type="text" id="addDriverName" name="chauffeur_name" required placeholder="الاسم الكامل للسائق"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">رقم الهاتف <span class="text-rose-500">*</span></label>
                    <input type="text" id="addDriverPhone" name="phone" required placeholder="0550..." dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تصنيف السائق</label>
                    <select name="driver_type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                        <option value="actif">سائق نشط (Chauffeur Actif)</option>
                        <option value="ancien">سائق سابق / أرشيف (Ancien Chauffeur)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">إجمالي مبلغ الدين / القرض (دج) <span class="text-rose-500">*</span></label>
                    <input type="number" step="100" name="total_credits" required placeholder="مثال: 50000"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">دفعة مسددة أولية (اختياري)</label>
                <input type="number" step="100" name="initial_versement" value="0"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <span class="text-[11px] text-slate-400 mt-1 block">إذا كان السائق قد سدد دفعة مسبقة، أدخل المبلغ هنا ليتم خصمه من الرصيد فوراً</span>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">بيان الدين أو الملاحظات</label>
                <input type="text" name="notes" placeholder="مثال: سلفة نقدية، اشتراك شهر أوت، غرامة مرورية..."
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('addDebtModal').classList.add('hidden'); document.getElementById('addDebtModal').classList.remove('flex');"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    حفظ وتأكيد الدين
                </button>
            </div>
        </form>

    </div>
</div>

<script>
function onDriverSelectChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('addDriverName').value = opt.getAttribute('data-name') || '';
        document.getElementById('addDriverPhone').value = opt.getAttribute('data-phone') || '';
    }
}
function openVersementModal(id, name, reste) {
    document.getElementById('modalDebtId').value = id;
    document.getElementById('modalDriverName').textContent = 'السائق: ' + name;
    document.getElementById('modalResteAmount').textContent = Number(reste).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' DZD';
    document.getElementById('versementAmount').value = reste > 0 ? reste : 5000;
    document.getElementById('versementModal').classList.remove('hidden');
    document.getElementById('versementModal').classList.add('flex');
}
function closeVersementModal() {
    document.getElementById('versementModal').classList.add('hidden');
    document.getElementById('versementModal').classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
