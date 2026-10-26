<?php
// dashboard/contracts.php - Gestion des Contrats: Gérez et générez les contrats d'affiliation et de travail
$pageTitle = 'Gestion des Contrats | إدارة عقود العمل والانتساب';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

if (is_owner() && !is_admin()) {
    $stmtV = $pdo->prepare("SELECT id, brand, model, plate_number, door_number FROM vehicles WHERE owner_id = ? OR owner_id IS NULL ORDER BY plate_number ASC");
    $stmtV->execute([$_SESSION['user_id']]);
    $vehicles = $stmtV->fetchAll() ?: [];
} else {
    $vehicles = $pdo->query("SELECT id, brand, model, plate_number, door_number FROM vehicles ORDER BY plate_number ASC")->fetchAll() ?: [];
}
$drivers = $pdo->query("
    SELECT u.id, u.full_name, u.phone, u.license_number, u.taxi_contract_start, u.taxi_contract_end,
           (SELECT v.id FROM vehicles v WHERE v.current_driver_id = u.id LIMIT 1) as assigned_vehicle_id,
           (SELECT v.door_number FROM vehicles v WHERE v.current_driver_id = u.id LIMIT 1) as assigned_door_number
    FROM users u 
    WHERE u.role = 'driver' AND u.status = 'active' 
    ORDER BY CASE WHEN (SELECT v.door_number FROM vehicles v WHERE v.current_driver_id = u.id LIMIT 1) IS NOT NULL AND (SELECT v.door_number FROM vehicles v WHERE v.current_driver_id = u.id LIMIT 1) != '' THEN 0 ELSE 1 END,
             CAST(SUBSTRING_INDEX((SELECT v.door_number FROM vehicles v WHERE v.current_driver_id = u.id LIMIT 1), '-', -1) AS UNSIGNED) ASC,
             u.full_name ASC
")->fetchAll() ?: [];

$preselectedDriverId = intval($_GET['driver_id'] ?? 0);

// Handle Create Contract
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_contract') {
    require_csrf_token();
    $vehicleId = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $driverId = intval($_POST['driver_id'] ?? 0);
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $years = max(1, intval($_POST['duration_years'] ?? 5));
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d', strtotime("+$years years -1 day", strtotime($startDate)));
    $monthlyWage = floatval($_POST['monthly_wage'] ?? 45000.00);
    $notes = trim($_POST['notes'] ?? 'Contrat de travail pour chauffeur de taxi régi par la loi 90-11.');

    if ($driverId > 0) {
        try {
            $pdo->beginTransaction();
            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM contracts")->fetchColumn();
            $contractNum = 'CT-' . date('Y') . '-' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO contracts (contract_number, driver_id, vehicle_id, owner_id, start_date, end_date, monthly_wage, status, signed_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'signed', NOW(), ?)
            ");
            $stmt->execute([$contractNum, $driverId, $vehicleId, $user['id'], $startDate, $endDate, $monthlyWage, $notes]);
            $cId = (int)$pdo->lastInsertId();
            
            // Assign driver to vehicle if selected
            if ($vehicleId) {
                $pdo->prepare("UPDATE vehicles SET current_driver_id = ? WHERE id = ?")->execute([$driverId, $vehicleId]);
            }

            $pdo->commit();
            log_audit($pdo, 'contract_created', 'contracts', $cId, "إنشاء وتوثيق عقد عمل نظامي رقم $contractNum للسائق رقم $driverId");
            set_flash('success', "تم إنشاء وتوثيق العقد رقم $contractNum بنجاح للسائق المحدد وفقاً لأحكام القانون 90-11");
            redirect("/dashboard/contract_view.php?id=$cId");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء إنشاء العقد: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى تحديد السائق المكلف بالعقد');
    }
}

// Handle Edit Contract
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_contract') {
    require_csrf_token();
    $contractId = intval($_POST['contract_id'] ?? 0);
    $vehicleId = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $driverId = intval($_POST['driver_id'] ?? 0);
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');
    $monthlyWage = floatval($_POST['monthly_wage'] ?? 45000.00);
    $status = in_array($_POST['status'] ?? '', ['signed', 'draft', 'terminated']) ? $_POST['status'] : 'signed';
    $notes = trim($_POST['notes'] ?? '');

    if ($contractId > 0 && $driverId > 0) {
        try {
            $stmt = $pdo->prepare("
                UPDATE contracts 
                SET driver_id = ?, vehicle_id = ?, start_date = ?, end_date = ?, monthly_wage = ?, status = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$driverId, $vehicleId, $startDate, $endDate, $monthlyWage, $status, $notes, $contractId]);
            
            if ($status === 'signed') {
                $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND deleted_at IS NULL")->execute([$driverId]);
                if ($vehicleId) {
                    $pdo->prepare("UPDATE vehicles SET current_driver_id = ? WHERE id = ?")->execute([$driverId, $vehicleId]);
                }
            }

            log_audit($pdo, 'contract_updated', 'contracts', $contractId, "تعديل بيانات العقد رقم $contractId");
            set_flash('success', "تم تعديل وتجديد بيانات العقد بنجاح.");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء تعديل العقد: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى تحديد السائق المكلف بالعقد');
    }
    redirect('/dashboard/contracts.php');
}

// Handle Terminate Contract with Specific Clauses & Archive Driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'terminate_contract') {
    require_csrf_token();
    $contractId = intval($_POST['contract_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $clause = trim($_POST['termination_clause'] ?? 'article_14');
    $clauseTitles = [
        'article_14' => 'المادة 14: ارتكاب خطأ جسيم تجاه الزبون أو أثناء العمل',
        'article_12' => 'المادة 12: استقالة السائق الأجير مع الإشعار المسبق (شهر واحد)',
        'article_13' => 'المادة 13: انتهاء المشروع أو حدوث طارئ يمنع مزاولة النشاط',
        'article_07' => 'المادة 07: وضع حد لعلاقة العمل أثناء الفترة التجريبية (خلال 6 أشهر)',
        'article_10_11' => 'المادتان 10 و 11: مخالفة النظام الداخلي أو التسبب بأضرار مادية بالمركبة',
        'amiable' => 'فسخ العقد بالتراضي والاتفاق المتبادل بين الطرفين',
        'custom' => 'فسخ العقد بموجب بند قانوني مخصص أو سبب معلن'
    ];
    $clauseTitle = $clauseTitles[$clause] ?? 'فسخ العقد بموجب القانون 90-11';
    $termDate = !empty($_POST['termination_date']) ? $_POST['termination_date'] : date('Y-m-d');
    $details = trim($_POST['termination_notes'] ?? '');

    if ($contractId > 0 && $driverId > 0) {
        try {
            $pdo->beginTransaction();

            // 1. Ensure contracts table has required termination columns (self-healing)
            try {
                $cCheck = $pdo->query("SHOW COLUMNS FROM `contracts` LIKE 'termination_clause'")->fetch();
                if (empty($cCheck)) {
                    $pdo->exec("ALTER TABLE `contracts` 
                        ADD COLUMN `termination_clause` VARCHAR(150) NULL AFTER `status`,
                        ADD COLUMN `termination_date` DATE NULL AFTER `termination_clause`,
                        ADD COLUMN `termination_notes` TEXT NULL AFTER `termination_date`");
                }
            } catch (\Throwable $colEx) {}

            // Mark contract as terminated and save structured termination metadata
            $noteAppend = "\n[تم فسخ العقد بتاريخ $termDate - البند: $clauseTitle" . ($details ? " - السند/التفاصيل: $details" : "") . "]";
            try {
                $stmtTerm = $pdo->prepare("
                    UPDATE contracts 
                    SET status = 'terminated', 
                        termination_clause = ?,
                        termination_date = ?,
                        termination_notes = ?,
                        notes = CONCAT(COALESCE(notes, ''), ?) 
                    WHERE id = ?
                ");
                $stmtTerm->execute([$clauseTitle, $termDate, $details, $noteAppend, $contractId]);
            } catch (\Throwable $upEx) {
                // Defensive fallback if columns cannot be updated
                $stmtFallback = $pdo->prepare("
                    UPDATE contracts 
                    SET status = 'terminated', 
                        notes = CONCAT(COALESCE(notes, ''), ?) 
                    WHERE id = ?
                ");
                $stmtFallback->execute([$noteAppend, $contractId]);
            }

            // 2. Unassign driver from all vehicles
            $pdo->prepare("UPDATE vehicles SET current_driver_id = NULL WHERE current_driver_id = ?")->execute([$driverId]);
            $pdo->prepare("UPDATE vehicles SET replacement_driver_id = NULL WHERE replacement_driver_id = ?")->execute([$driverId]);

            // 3. Freeze driver account & transfer to archive / recycle bin
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$driverId]);
            soft_delete_entity($pdo, 'users', $driverId, "فسخ العقد رقم $contractId: $clauseTitle");

            $pdo->commit();
            log_audit($pdo, 'contract_terminated', 'contracts', $contractId, "فسخ العقد رقم $contractId للسائق $driverId بناءً على: $clauseTitle وتوليد محضر وبوردرورو الفسخ الرسمي");
            set_flash('success', "تم فسخ العقد بنجاح طبقاً لـ ($clauseTitle) وإلغاء تخصيص المركبة، وتوليد محضر وبوردرورو الفسخ الرسمي.");
            redirect("/dashboard/contract_termination.php?id=$contractId");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء فسخ العقد: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'بيانات العقد أو السائق غير مكتملة.');
    }
    redirect('/dashboard/contracts.php');
}

// Handle Archive Expired Driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_expired_driver') {
    require_csrf_token();
    $contractId = intval($_POST['contract_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);

    if ($driverId > 0) {
        try {
            $pdo->beginTransaction();
            // 1. Unassign vehicles
            $pdo->prepare("UPDATE vehicles SET current_driver_id = NULL WHERE current_driver_id = ?")->execute([$driverId]);
            $pdo->prepare("UPDATE vehicles SET replacement_driver_id = NULL WHERE replacement_driver_id = ?")->execute([$driverId]);

            // 2. Freeze driver and move to archive
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$driverId]);
            soft_delete_entity($pdo, 'users', $driverId, "أرشفة السائق لانتهاء العقد رقم $contractId وعدم التجديد");

            $pdo->commit();
            log_audit($pdo, 'driver_archived_expired_contract', 'users', $driverId, "إحالة السائق $driverId للأرشيف لانتهاء العقد رقم $contractId");
            set_flash('success', "تمت إحالة السائق بنجاح إلى الأرشيف وسلة المهملات لانتهاء مدة عقده وعدم التجديد.");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء أرشفة السائق: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/contracts.php');
}

// Handle Delete Contract
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_contract') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف العقود.');
        redirect('/dashboard/contracts.php');
    }
    $contractId = intval($_POST['contract_id'] ?? 0);
    if ($contractId > 0) {
        try {
            soft_delete_entity($pdo, 'contracts', $contractId, 'حذف ونقل لسلة المهملات');
            set_flash('success', "تم نقل العقد بنجاح إلى سلة المهملات والأرشيف. يمكنك استرجاعه في أي وقت.");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء نقل العقد لسلة المهملات: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/contracts.php');
}

// Auto-freeze drivers whose active contract has expired and have no newer active signed contract
try {
    $pdo->exec("
        UPDATE users u
        SET u.status = 'suspended'
        WHERE u.role = 'driver'
          AND u.status = 'active'
          AND u.deleted_at IS NULL
          AND u.id IN (
              SELECT c.driver_id FROM contracts c
              WHERE c.deleted_at IS NULL
              GROUP BY c.driver_id
              HAVING MAX(c.end_date) < CURDATE()
          )
    ");
} catch (\Throwable $e) {}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, active, expired

$where = ["1=1", "c.deleted_at IS NULL"];
$params = [];

if (!empty($search)) {
    $where[] = "(c.contract_number LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR v.plate_number LIKE ? OR v.door_number LIKE ? OR v.brand LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'active') {
    $where[] = "c.status = 'signed' AND c.end_date >= CURDATE()";
} elseif ($filter === 'expired') {
    $where[] = "c.status != 'terminated' AND c.end_date < CURDATE()";
} elseif ($filter === 'expiring_soon') {
    // 30 days (1 month) early warning filter
    $where[] = "c.status = 'signed' AND c.end_date >= CURDATE() AND c.end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($filter === 'terminated') {
    $where[] = "c.status = 'terminated'";
}

if (is_owner() && !is_admin()) {
    $where[] = "(c.owner_id = ? OR v.owner_id = ?)";
    $params[] = intval($_SESSION['user_id']);
    $params[] = intval($_SESSION['user_id']);
}

$sql = "
    SELECT c.*, 
           v.plate_number, v.brand, v.model, v.door_number,
           u.full_name as driver_name, u.phone as driver_phone, u.license_number as driver_license, u.status as driver_status,
           TIMESTAMPDIFF(YEAR, c.start_date, c.end_date) as duration_years,
           DATEDIFF(c.end_date, CURDATE()) as days_remaining
    FROM contracts c
    LEFT JOIN vehicles v ON c.vehicle_id = v.id
    JOIN users u ON c.driver_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY 
        CASE WHEN v.door_number IS NOT NULL AND v.door_number != '' THEN 0 ELSE 1 END,
        CAST(SUBSTRING_INDEX(v.door_number, '-', -1) AS UNSIGNED) ASC,
        v.door_number ASC,
        c.id ASC
";

$contracts = [];
$countTous = 0;
$countActifs = 0;
$countExpiringSoon = 0;
$countExpires = 0;

if ($pdo) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        error_log("contracts.php - Query contracts error: " . $e->getMessage());
        $contracts = [];
    }

    try {
        if (is_owner() && !is_admin()) {
            $stmtCt = $pdo->prepare("SELECT COUNT(*) FROM contracts c LEFT JOIN vehicles v ON c.vehicle_id = v.id WHERE (c.owner_id = ? OR v.owner_id = ?)");
            $stmtCt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $countTous = (int)$stmtCt->fetchColumn();
        } else {
            $countTous = (int)$pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn();
        }
    } catch (\Throwable $e) { $countTous = count($contracts); }

    try {
        if (is_owner() && !is_admin()) {
            $stmtCa = $pdo->prepare("SELECT COUNT(*) FROM contracts c LEFT JOIN vehicles v ON c.vehicle_id = v.id WHERE c.status = 'signed' AND c.end_date >= CURDATE() AND (c.owner_id = ? OR v.owner_id = ?)");
            $stmtCa->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $countActifs = (int)$stmtCa->fetchColumn();
        } else {
            $countActifs = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status = 'signed' AND end_date >= CURDATE()")->fetchColumn();
        }
    } catch (\Throwable $e) { $countActifs = 0; }

    try {
        if (is_owner() && !is_admin()) {
            $stmtCs = $pdo->prepare("SELECT COUNT(*) FROM contracts c LEFT JOIN vehicles v ON c.vehicle_id = v.id WHERE c.status = 'signed' AND c.end_date >= CURDATE() AND c.end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND (c.owner_id = ? OR v.owner_id = ?)");
            $stmtCs->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $countExpiringSoon = (int)$stmtCs->fetchColumn();
        } else {
            $countExpiringSoon = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status = 'signed' AND end_date >= CURDATE() AND end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
        }
    } catch (\Throwable $e) { $countExpiringSoon = 0; }

    try {
        if (is_owner() && !is_admin()) {
            $stmtCtm = $pdo->prepare("SELECT COUNT(*) FROM contracts c LEFT JOIN vehicles v ON c.vehicle_id = v.id WHERE c.status = 'terminated' AND (c.owner_id = ? OR v.owner_id = ?)");
            $stmtCtm->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $countTerminated = (int)$stmtCtm->fetchColumn();
        } else {
            $countTerminated = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status = 'terminated'")->fetchColumn();
        }
    } catch (\Throwable $e) { $countTerminated = 0; }

    $countExpires = max(0, $countTous - $countActifs - $countTerminated);
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Gestion des Contrats</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    عقود العمل والانتساب (القانون 90-11)
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">توليد وإدارة عقود العمل والانتساب المتوافقة مع متطلبات مفتشية العمل ومديرية النقل</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="window.print()" 
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v6.75H4.5V9a2.25 2.25 0 012.25-2.25zm0 0V4.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v2.25m-9 9h9.75M6.75 18h10.5a2.25 2.25 0 002.25-2.25v-3H4.5v3A2.25 2.25 0 006.75 18z" />
                </svg>
                <span>طباعة السجل</span>
            </button>
            <button onclick="openTerminateContractSelectModal()"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs transition shadow-xs cursor-pointer"
                    title="فسخ عقد عمل ساري المفعول وتوثيق بنود الفسخ وبوردرورو DTW">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
                <span>فسخ العقد</span>
            </button>
            <button onclick="document.getElementById('newContractModal').classList.remove('hidden'); document.getElementById('newContractModal').classList.add('flex');"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>إصدار عقد جديد</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards (4 Columns with 1-Month Expiry Alert) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <a href="contracts.php?filter=all" class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between hover:border-slate-400 transition">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إجمالي العقود المسجلة</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $countTous ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            </div>
        </a>

        <a href="contracts.php?filter=active" class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between hover:border-emerald-400 transition">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">عقود سارية المفعول</p>
                <p class="text-xl font-bold text-emerald-600 font-num mt-0.5"><?= $countActifs ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </a>

        <!-- 30-Day Expiry Early Alert Card -->
        <a href="contracts.php?filter=expiring_soon" class="bg-white p-4 rounded-xl border <?= $countExpiringSoon > 0 ? 'border-amber-300 dark:border-amber-800 bg-amber-50/20' : 'border-slate-200/80' ?> shadow-xs flex items-center justify-between hover:border-amber-400 hover:shadow-2xs transition group" title="انقر لتصفية العقود التي تنتهي خلال أقل من شهر (30 يوماً)">
            <div>
                <p class="text-[11px] font-semibold text-slate-500 group-hover:text-amber-700 transition">تنتهي خلال شهر (30 يوماً)</p>
                <div class="flex items-baseline gap-2 mt-0.5">
                    <p class="text-xl font-bold <?= $countExpiringSoon > 0 ? 'text-amber-600' : 'text-slate-400' ?> font-num"><?= $countExpiringSoon ?></p>
                    <?php if ($countExpiringSoon > 0): ?>
                        <span class="text-[10px] font-bold text-amber-800 bg-amber-100 px-1.5 py-0.2 rounded border border-amber-200 font-num">
                            تنبيه تجديد ⚠️
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $countExpiringSoon > 0 ? 'bg-amber-100 text-amber-700 group-hover:bg-amber-500 group-hover:text-slate-950' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </a>

        <a href="contracts.php?filter=expired" class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between hover:border-rose-400 transition">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">عقود منتهية الصلاحية</p>
                <p class="text-xl font-bold <?= $countExpires > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num mt-0.5"><?= $countExpires ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $countExpires > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
        </a>
    </div>

    <!-- Alert Banner: Contracts Expiring within 30 Days (تنبيه مسبق قبل شهر) -->
    <?php if ($countExpiringSoon > 0): ?>
        <div class="p-4 rounded-2xl bg-amber-500/10 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-900/60 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center shrink-0 shadow-xs font-black">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h4 class="font-bold text-slate-900 dark:text-amber-200 text-xs sm:text-sm">تنبيه انتهاء صلاحية عقود العمل (تنبيه مسبق قبل شهر / 30 يوماً)</h4>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-200 text-amber-950 dark:bg-amber-900/80 dark:text-amber-100 font-num">
                            <?= $countExpiringSoon ?> عقود وشيكة
                        </span>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">يوجد <strong class="text-amber-700 dark:text-amber-400 font-num"><?= $countExpiringSoon ?></strong> عقد عمل ستنتهي صلاحيتها خلال أقل من 30 يوماً. يرجى المبادرة بتجديد العقود القانونية لضمان استمرارية النشاط وفق القانون 90-11.</p>
                </div>
            </div>
            <div class="shrink-0 flex items-center gap-2">
                <a href="contracts.php?filter=expiring_soon" class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs whitespace-nowrap">
                    عرض وتجديد العقود (<?= $countExpiringSoon ?>)
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث برقم العقد، اسم السائق، أو رقم اللوحة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="contracts.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            <a href="contracts.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                الكل (<?= $countTous ?>)
            </a>
            <a href="contracts.php?filter=active<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'active' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                سارية المفعول (<?= $countActifs ?>)
            </a>
            <a href="contracts.php?filter=expiring_soon<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'expiring_soon' ? 'bg-amber-500 text-slate-950 shadow-xs' : 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-900/60 hover:bg-amber-100' ?>">
                <span>تنتهي قريباً (خلال شهر)</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $filter === 'expiring_soon' ? 'bg-slate-950/20 text-slate-950' : 'bg-amber-200 dark:bg-amber-800 text-amber-950 dark:text-white' ?> font-num font-bold"><?= $countExpiringSoon ?></span>
            </a>
            <a href="contracts.php?filter=expired<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'expired' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                منتهية (<?= $countExpires ?>)
            </a>
            <a href="contracts.php?filter=terminated<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3.5 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'terminated' ? 'bg-rose-700 text-white shadow-xs' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-900/60 hover:bg-rose-100' ?>">
                <span>عقود مفسوخة (محاضر وبوردروروات)</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $filter === 'terminated' ? 'bg-white/20 text-white' : 'bg-rose-200 dark:bg-rose-800 text-rose-950 dark:text-white' ?> font-num font-bold"><?= $countTerminated ?></span>
            </a>
        </div>
    </div>

    <!-- Contracts Table -->
    <div id="contractsTableContainer" class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        
        <!-- Printable Registry Header -->
        <div class="hidden print:block p-6 border-b border-slate-300 text-center space-y-1">
            <div class="text-sm font-bold">الجمهورية الجزائرية الديمقراطية الشعبية</div>
            <div class="text-xs font-semibold text-slate-600">RÉPUBLIQUE ALGÉRIENNE DÉMOCRATIQUE ET POPULAIRE</div>
            <div class="text-base font-bold text-slate-900 pt-2">REGISTRE OFFICIEL DES CONTRATS D'AFFILIATION ET DE TRAVAIL (LOI 90-11)</div>
            <div class="text-xs text-slate-500 font-num">SARL NAGUILOU TAXI • Date d'édition: <?= date('d/m/Y') ?></div>
        </div>

        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr>
                        <th class="py-3 px-2 text-center whitespace-nowrap w-10">N°</th>
                        <th class="py-3 px-3 whitespace-nowrap sticky-col-start">رقم العقد</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">السائق المكلف</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">المركبة ولوحة الترقيم</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">فترة الصلاحية</th>
                        <th class="py-3 px-2.5 whitespace-nowrap text-center">الوضعية القانونية</th>
                        <th class="py-3 px-2 text-center whitespace-nowrap sticky-col-end w-12 print:hidden">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($contracts)): ?>
                        <?php $rowNum = 1; foreach ($contracts as $c): 
                            $durYears = !empty($c['duration_years']) && $c['duration_years'] > 0 ? $c['duration_years'] : 1;
                            $daysRemaining = $c['days_remaining'] !== null ? intval($c['days_remaining']) : 365;
                            $isExpired = $daysRemaining < 0;
                            $isExpiringSoon = $daysRemaining >= 0 && $daysRemaining <= 30;

                            $rowCls = '';
                            if ($isExpired) {
                                $rowCls = 'bg-rose-50/25 dark:bg-rose-950/20 border-r-4 border-r-rose-500';
                            } elseif ($isExpiringSoon) {
                                $rowCls = 'bg-amber-50/30 dark:bg-amber-950/25 border-r-4 border-r-amber-500';
                            }
                        ?>
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition <?= $rowCls ?>">
                                <!-- N° -->
                                <td class="p-3.5 text-center font-num font-bold text-slate-400">
                                    <?= $rowNum++ ?>
                                </td>

                                <!-- Contract Number -->
                                <td class="py-2.5 px-3 whitespace-nowrap sticky-col-start">
                                    <div>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs font-bold font-num bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-300 border border-sky-200/80 dark:border-sky-800 shadow-2xs font-mono tracking-wide" dir="ltr">
                                            <span>📄</span>
                                            <span><?= htmlspecialchars($c['contract_number']) ?></span>
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 mt-1 block">Loi 90-11 • Conforme DTW</span>
                                </td>

                                <!-- Driver -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 dark:text-slate-100 text-[13px] flex items-center gap-1.5 flex-wrap">
                                        <span><?= htmlspecialchars($c['driver_name']) ?></span>
                                        <?php if ($c['status'] === 'terminated'): ?>
                                            <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800">مفسوخ (بالأرشيف)</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-200 border border-slate-300 dark:border-slate-700">مجمد لانتهاء العقد</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1" dir="ltr">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold font-num bg-slate-100/90 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200/70 dark:border-slate-700">
                                            <?= htmlspecialchars($c['driver_phone']) ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Vehicle -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($c['plate_number'])): ?>
                                        <div class="font-semibold text-slate-900 dark:text-slate-100">
                                            <?= htmlspecialchars($c['brand'] . ' ' . $c['model']) ?>
                                        </div>
                                        <div class="mt-1 flex items-center gap-1.5" dir="ltr">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold font-num bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200 border border-amber-200/80 dark:border-amber-800/80">
                                                🚗 <?= htmlspecialchars($c['plate_number']) ?>
                                            </span>
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold font-num bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                باب: <?= htmlspecialchars($c['door_number']) ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-xs italic">سائق بدون تعيين مركبة</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Duration / Validity -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800 font-num" dir="ltr">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            <span><?= htmlspecialchars($c['start_date']) ?></span>
                                            <span class="text-emerald-400">→</span>
                                            <span><?= htmlspecialchars($c['end_date']) ?></span>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <span class="px-2 py-0.2 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold text-[10px]">
                                            <?= $durYears ?> <?= $durYears > 1 ? 'سنوات' : 'سنة' ?>
                                        </span>
                                        <?php if ($c['status'] === 'terminated'): ?>
                                            <span class="text-[10px] text-rose-700 dark:text-rose-300 font-bold bg-rose-50 dark:bg-rose-950/60 px-1.5 py-0.2 rounded border border-rose-200 dark:border-rose-900/50">
                                                فسخ رسمي
                                            </span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="text-[10px] text-rose-600 dark:text-rose-400 font-bold font-num bg-rose-50 dark:bg-rose-950/60 px-1.5 py-0.2 rounded border border-rose-200 dark:border-rose-900/50">
                                                منتهي منذ <?= abs($daysRemaining) ?> يوم
                                            </span>
                                        <?php elseif ($isExpiringSoon): ?>
                                            <span class="text-[10px] text-amber-800 dark:text-amber-300 font-bold font-num bg-amber-100 dark:bg-amber-950/80 px-1.5 py-0.2 rounded border border-amber-300 dark:border-amber-800/80 flex items-center gap-1">
                                                <span>⚠️</span>
                                                <span>ينتهي خلال <?= $daysRemaining ?> يوماً</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold font-num">باقي <?= $daysRemaining ?> يوم</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($c['status'] === 'terminated'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-200 border border-rose-300 dark:border-rose-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span>
                                            <span>مفسوخ رسمياً (Résilié)</span>
                                        </span>
                                    <?php elseif ($isExpired): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black bg-slate-100 text-slate-800 dark:bg-slate-800/80 dark:text-slate-200 border border-slate-300 dark:border-slate-700">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span>
                                            <span>منتهي الصلاحية (مجمد)</span>
                                        </span>
                                    <?php elseif ($isExpiringSoon): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-900 dark:bg-amber-950/80 dark:text-amber-200 border border-amber-300 dark:border-amber-800 animate-pulse">
                                            <span>⚠️ انتهاء وشيك (خلال شهر)</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800/60">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <span>ساري ونظامي (90-11)</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions (Direct Buttons + Dropdown Menu) -->
                                <td class="py-2.5 px-2 whitespace-nowrap text-center sticky-col-end print:hidden">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if ($c['status'] === 'terminated'): ?>
                                        <a href="contract_termination.php?id=<?= $c['id'] ?>"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/60 dark:hover:bg-rose-900/80 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 font-bold text-xs transition shadow-2xs"
                                           title="معاينة وطباعة محضر الفسخ وبوردرورو DTW">
                                            <svg class="w-3.5 h-3.5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                            </svg>
                                            <span>عقد الفسخ</span>
                                        </a>
                                        <?php endif; ?>

                                        <div class="relative inline-block text-right">
                                            <button type="button"
                                                    onclick="toggleTableDropdown(event, this)"
                                                    class="table-dropdown-btn w-8 h-8 rounded-xl bg-slate-100 hover:bg-amber-500/15 hover:text-amber-700 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 shadow-2xs cursor-pointer"
                                                    title="خيارات وإجراءات العقد">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                                </svg>
                                            </button>

                                        <div class="table-dropdown-menu hidden w-64 min-w-[250px] rounded-2xl bg-white/95 dark:bg-[#161d27]/95 backdrop-blur-md border border-slate-200 dark:border-slate-800 shadow-2xl p-1.5 space-y-0.5 text-xs text-right select-none">
                                            <!-- Option 1: Renew Contract (+3 years) for expired or expiring soon -->
                                            <?php if ($isExpiringSoon || $isExpired): ?>
                                            <button type="button"
                                                    onclick='openEditContractModal(<?= json_encode([
                                                        "id" => (int)$c["id"],
                                                        "contract_number" => $c["contract_number"],
                                                        "driver_id" => (int)$c["driver_id"],
                                                        "vehicle_id" => (int)$c["vehicle_id"],
                                                        "start_date" => date("Y-m-d"),
                                                        "end_date" => date("Y-m-d", strtotime("+3 years -1 day")),
                                                        "monthly_wage" => (float)$c["monthly_wage"],
                                                        "status" => "signed",
                                                        "notes" => ($c["notes"] ?? "") . " - تم التجديد بتاريخ " . date("Y-m-d")
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-amber-900 dark:text-amber-200 bg-amber-50 dark:bg-amber-950/50 hover:bg-amber-100 dark:hover:bg-amber-900/60 font-black transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                </svg>
                                                <span>تجديد العقد (+3 سنوات وإلغاء التجميد)</span>
                                            </button>
                                            <?php endif; ?>

                                            <!-- Option 2: Archive driver if contract is expired -->
                                            <?php if ($isExpired && $c['status'] !== 'terminated'): ?>
                                            <form method="POST" action="" onsubmit="return confirm('تأكيد: هل أنت متأكد من رغبتك في إرسال هذا السائق إلى الأرشيف وسلة المهملات لانتهاء مدة عقده وعدم التجديد؟')" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="archive_expired_driver">
                                                <input type="hidden" name="contract_id" value="<?= $c['id'] ?>">
                                                <input type="hidden" name="driver_id" value="<?= $c['driver_id'] ?>">
                                                <button type="submit"
                                                        class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 bg-slate-100/80 dark:bg-slate-800/80 hover:bg-slate-200 dark:hover:bg-slate-700 font-bold transition text-right cursor-pointer">
                                                    <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                                    </svg>
                                                    <span>إرسال السائق للأرشيف</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <!-- View Contract -->
                                            <a href="contract_view.php?id=<?= $c['id'] ?>"
                                               class="table-dropdown-item flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-amber-50 dark:hover:bg-amber-900/30 hover:text-amber-800 dark:hover:text-amber-300 font-bold transition">
                                                <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                                <span>معاينة وطباعة العقد</span>
                                            </a>

                                            <!-- Preview Termination & DTW Bordereau if terminated -->
                                            <?php if ($c['status'] === 'terminated'): ?>
                                            <a href="contract_termination.php?id=<?= $c['id'] ?>"
                                               class="table-dropdown-item flex items-center gap-2 px-3 py-2 rounded-xl text-rose-800 dark:text-rose-200 bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100 dark:hover:bg-rose-900/50 font-black transition">
                                                <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                                <span>معاينة الفسخ وبوردرورو DTW</span>
                                            </a>
                                            <?php endif; ?>

                                            <!-- Edit Contract -->
                                            <button type="button"
                                                    onclick='openEditContractModal(<?= json_encode([
                                                        "id" => (int)$c["id"],
                                                        "contract_number" => $c["contract_number"],
                                                        "driver_id" => (int)$c["driver_id"],
                                                        "vehicle_id" => (int)$c["vehicle_id"],
                                                        "start_date" => $c["start_date"],
                                                        "end_date" => $c["end_date"],
                                                        "monthly_wage" => (float)$c["monthly_wage"],
                                                        "status" => $c["status"],
                                                        "notes" => $c["notes"] ?? ""
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل بنود العقد</span>
                                            </button>

                                            <!-- Option 3: Terminate Contract with Clauses & send driver to archive -->
                                            <?php if ($c['status'] !== 'terminated'): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <button type="button"
                                                    onclick="openTerminateContractModal(<?= (int)$c['id'] ?>, '<?= htmlspecialchars($c['contract_number'], ENT_QUOTES) ?>', <?= (int)$c['driver_id'] ?>, '<?= htmlspecialchars($c['driver_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['plate_number'] ?? '', ENT_QUOTES) ?>')"
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-rose-700 dark:text-rose-400 bg-rose-50/70 dark:bg-rose-950/40 hover:bg-rose-100 dark:hover:bg-rose-900/50 font-black transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                </svg>
                                                <span>فسخ العقد وإحالة السائق للأرشيف</span>
                                            </button>
                                            <?php endif; ?>

                                            <?php if (is_admin()): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من رغبتك في نقل هذا العقد إلى سلة المهملات والأرشيف؟')" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_contract">
                                                <input type="hidden" name="contract_id" value="<?= $c['id'] ?>">
                                                <button type="submit"
                                                        class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-rose-500 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-semibold transition text-right cursor-pointer">
                                                    <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    <span>حذف العقد إلى المهملات</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-10 text-center text-slate-400">
                                لا توجد عقود مطابقة لشروط البحث والتصفية.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: New Contract -->
<div id="newContractModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">إصدار عقد عمل وانتساب نظامي</h3>
                <span class="text-xs text-slate-500">مديرية النقل ولاية أدرار • يتضمن المواد الـ 16 الإلزامية طبقاً لأحكام القانون 90-11</span>
            </div>
            <button onclick="document.getElementById('newContractModal').classList.add('hidden'); document.getElementById('newContractModal').classList.remove('flex');" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_contract">

            <input type="hidden" name="monthly_wage" value="45000">

            <!-- 1. Driver First -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                    <span>اختر السائق الأجير (Chauffeur) <span class="text-rose-500">*</span></span>
                    <span class="text-[10px] text-emerald-600 font-semibold" id="driverAutoFillBadge">استيراد تلقائي للتواريخ</span>
                </label>
                <select name="driver_id" id="contractDriverSelect" required onchange="onDriverSelectedForContract(this)"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- حدد السائق المكلف --</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>" 
                                <?= $preselectedDriverId === (int)$d['id'] ? 'selected' : '' ?>
                                data-start="<?= htmlspecialchars($d['taxi_contract_start'] ?? '') ?>"
                                data-end="<?= htmlspecialchars($d['taxi_contract_end'] ?? '') ?>"
                                data-vehicle="<?= htmlspecialchars($d['assigned_vehicle_id'] ?? '') ?>">
                            <?= htmlspecialchars($d['full_name']) ?> (هاتف: <?= htmlspecialchars($d['phone']) ?> - رخصة: <?= htmlspecialchars($d['license_number'] ?? 'غير مسجل') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. Vehicle Second -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                    <span>سيارة الأجرة (اختياري)</span>
                    <span class="text-[10px] text-slate-400 font-normal">يمكن ربطها لاحقاً</span>
                </label>
                <select name="vehicle_id" id="contractVehicleSelect" 
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                    <option value="">-- بدون ربط بمركبة (عقد سائق مستقل) --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> (لوحة: <?= htmlspecialchars($v['plate_number']) ?> - باب: <?= htmlspecialchars($v['door_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dates & Duration (Auto calculated from driver booklet if filled) -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                        <span>تاريخ سريان العقد (من) <span class="text-rose-500">*</span></span>
                        <span class="text-[10px] text-slate-400 font-normal">تلقائي من السائق</span>
                    </label>
                    <input type="date" name="start_date" id="contractStartDateInput" value="<?= date('Y-m-d') ?>" required
                           onchange="recalculateContractEndDate()"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                        <span>مدة العقد (Durée)</span>
                        <span class="text-[10px] text-slate-400 font-normal">محسوبة تلقائياً</span>
                    </label>
                    <select name="duration_years" id="contractDurationSelect" onchange="recalculateContractEndDate()"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                        <option value="5" selected>5 سنوات (05 ans - مدة رخصة الأجرة)</option>
                        <option value="3">3 سنوات (03 ans)</option>
                        <option value="2">سنتان (02 ans)</option>
                        <option value="1">سنة واحدة (01 an)</option>
                    </select>
                </div>
            </div>

            <!-- Calculated End Date Display (Matches Article 06) -->
            <div class="p-3 rounded-xl bg-amber-50/70 border border-amber-200/80 flex items-center justify-between text-xs">
                <div>
                    <span class="font-bold text-slate-800">تاريخ انتهاء وسريان العقد القانوني (إلى):</span>
                    <span id="calculatedEndDateBadge" class="font-num font-bold text-amber-950 mr-2 text-sm">--/--/----</span>
                </div>
                <input type="hidden" name="end_date" id="contractEndDateHidden" value="">
                <span class="text-[10px] font-bold text-amber-900 bg-amber-200/70 px-2 py-0.5 rounded">
                    مطابق للمادة 06
                </span>
            </div>

            <!-- Notes -->
            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات وشروط تكميلية</label>
                <textarea name="notes" rows="2" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">التزام السائق بإجراء الصيانة الدورية، معايرة العداد (الساكوريومتر)، واحترام جدول المناوبات والمداومة الرسمية للوكالة.</textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('newContractModal').classList.add('hidden'); document.getElementById('newContractModal').classList.remove('flex');"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    تأكيد وتوقيع العقد
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-fill contract start date, duration and assigned vehicle from selected driver
function onDriverSelectedForContract(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    if (!opt) return;

    const start = opt.getAttribute('data-start');
    const end = opt.getAttribute('data-end');
    const vehId = opt.getAttribute('data-vehicle');
    const badge = document.getElementById('driverAutoFillBadge');

    if (start) {
        const startInput = document.getElementById('contractStartDateInput');
        if (startInput) startInput.value = start;
    }
    if (vehId) {
        const vehSelect = document.getElementById('contractVehicleSelect');
        if (vehSelect && vehId > 0) vehSelect.value = vehId;
    }
    if (start && end) {
        const d1 = new Date(start);
        const d2 = new Date(end);
        const yearsDiff = Math.round((d2 - d1) / (365.25 * 24 * 60 * 60 * 1000));
        const durSelect = document.getElementById('contractDurationSelect');
        if (durSelect) {
            if ([1, 2, 3, 5].includes(yearsDiff)) {
                durSelect.value = yearsDiff;
            } else {
                durSelect.value = 5;
            }
        }
        const endHidden = document.getElementById('contractEndDateHidden');
        if (endHidden) endHidden.value = end;

        const endBadge = document.getElementById('calculatedEndDateBadge');
        if (endBadge) {
            const parts = end.split('-');
            endBadge.textContent = parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : end;
        }

        if (badge) {
            badge.textContent = `✓ تم استيراد الصلاحية (${yearsDiff > 0 ? yearsDiff : 5} سنوات) تلقائياً`;
            badge.className = "text-[10px] text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded";
        }
    } else {
        recalculateContractEndDate();
    }
}

function recalculateContractEndDate() {
    const startInput = document.getElementById('contractStartDateInput');
    const durSelect = document.getElementById('contractDurationSelect');
    const endHidden = document.getElementById('contractEndDateHidden');
    const endBadge = document.getElementById('calculatedEndDateBadge');

    if (!startInput || !durSelect) return;
    const startVal = startInput.value;
    const years = parseInt(durSelect.value) || 5;

    if (startVal) {
        const d = new Date(startVal);
        d.setFullYear(d.getFullYear() + years);
        d.setDate(d.getDate() - 1);

        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const endFormatted = `${yyyy}-${mm}-${dd}`;

        if (endHidden) endHidden.value = endFormatted;
        if (endBadge) endBadge.textContent = `${dd}/${mm}/${yyyy}`;
    }
}

// Initial calculation on page load
</script>

<!-- Modal: Edit Contract -->
<div id="editContractModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">تعديل بيانات العقد</h3>
                <span class="text-xs text-slate-500 font-num" id="editContractNumLabel"></span>
            </div>
            <button onclick="closeEditContractModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_contract">
            <input type="hidden" name="contract_id" id="editContractId">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">السائق المكلف <span class="text-rose-500">*</span></label>
                <select name="driver_id" id="editContractDriver" required class="w-full p-2.5 rounded-xl border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                    <span>المركبة (اختياري)</span>
                    <span class="text-[10px] text-slate-400 font-normal">يمكن تركها فارغة</span>
                </label>
                <select name="vehicle_id" id="editContractVehicle" class="w-full p-2.5 rounded-xl border border-slate-200 bg-white text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                    <option value="">-- بدون ربط بمركبة --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> - <?= htmlspecialchars($v['plate_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ البداية <span class="text-rose-500">*</span></label>
                    <input type="date" name="start_date" id="editContractStartDate" required class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تاريخ النهاية <span class="text-rose-500">*</span></label>
                    <input type="date" name="end_date" id="editContractEndDate" required class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                </div>
            </div>

            <input type="hidden" name="monthly_wage" id="editContractWage" value="45000">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">الوضعية القانونية <span class="text-rose-500">*</span></label>
                <select name="status" id="editContractStatus" class="w-full p-2.5 rounded-xl border border-slate-200 bg-white text-xs font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                    <option value="signed">ساري وموثق (Signé)</option>
                    <option value="draft">مسودة (Brouillon)</option>
                    <option value="terminated">ملغى / منتهي (Résilié)</option>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات العقد</label>
                <textarea name="notes" id="editContractNotes" rows="2" class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeEditContractModal()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100 font-bold transition">إلغاء</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Terminate Contract with Legal Clauses & Archive Driver -->
<div id="terminateContractModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white dark:bg-[#161d27] max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-rose-200 dark:border-rose-900/60 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                </span>
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white text-base">فسخ العقد وإحالة السائق للأرشيف</h3>
                    <span id="termContractNumLabel" class="text-xs font-mono font-bold text-rose-700 dark:text-rose-400"></span>
                </div>
            </div>
            <button onclick="closeTerminateContractModal()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="terminate_contract">
            <input type="hidden" name="contract_id" id="termContractId">
            <input type="hidden" name="driver_id" id="termDriverId">

            <!-- Contract Selector (Visible when opened from top action button) -->
            <div id="termContractSelectContainer" class="hidden">
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">اختر العقد المراد فسخه <span class="text-rose-500">*</span></label>
                <select id="termContractPicker" onchange="onTermContractPickerChange(this)" class="w-full p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#1a2332] text-xs font-bold focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500">
                    <option value="">-- حدد العقد والسائق --</option>
                    <?php foreach ($contracts as $ct): 
                        if ($ct['status'] === 'terminated') continue;
                    ?>
                        <option value="<?= $ct['id'] ?>" 
                                data-num="<?= htmlspecialchars($ct['contract_number']) ?>" 
                                data-driver-id="<?= $ct['driver_id'] ?>" 
                                data-driver-name="<?= htmlspecialchars($ct['driver_name']) ?>" 
                                data-vehicle="<?= htmlspecialchars(($ct['plate_number'] ?? '') . (!empty($ct['door_number']) ? ' (باب: ' . $ct['door_number'] . ')' : '')) ?>">
                            <?= htmlspecialchars($ct['contract_number']) ?> - <?= htmlspecialchars($ct['driver_name']) ?> <?= !empty($ct['plate_number']) ? ' - ' . htmlspecialchars($ct['plate_number']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Driver & Vehicle Summary Card -->
            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-1">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400 font-semibold">السائق المعني:</span>
                    <strong id="termDriverName" class="text-slate-950 dark:text-white font-black text-sm"></strong>
                </div>
                <div class="flex items-center justify-between text-[11px]" id="termVehicleRow">
                    <span class="text-slate-500 dark:text-slate-400 font-semibold">المركبة الحالية:</span>
                    <span id="termVehiclePlate" class="font-mono font-bold text-slate-800 dark:text-slate-200"></span>
                </div>
            </div>

            <!-- Termination Clause Selection -->
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">بند / سبب فسخ العقد (قانون 90-11) <span class="text-rose-500">*</span></label>
                <select name="termination_clause" id="termClauseSelect" required class="w-full p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#1a2332] text-xs font-semibold focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500">
                    <option value="article_14">المادة 14: ارتكاب خطأ جسيم تجاه الزبون أو أثناء تأدية المهام</option>
                    <option value="article_12">المادة 12: استقالة السائق الأجير مع استيفاء فترة الإشعار المسبق (شهر واحد)</option>
                    <option value="article_13">المادة 13: انتهاء المشروع أو حدوث طارئ يمنع مزاولة النشاط</option>
                    <option value="article_07">المادة 07: وضع حد لعلاقة العمل أثناء الفترة التجريبية (خلال 6 أشهر)</option>
                    <option value="article_10_11">المادتان 10 و 11: مخالفة أحكام النظام الداخلي أو التسبب بأضرار مادية بالمركبة</option>
                    <option value="amiable">فسخ العقد بالتراضي والاتفاق المتبادل بين الطرفين</option>
                    <option value="custom">بند قانوني مخصص أو سبب آخر...</option>
                </select>
            </div>

            <!-- Termination Date -->
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">تاريخ سريان الفسخ <span class="text-rose-500">*</span></label>
                <input type="date" name="termination_date" value="<?= date('Y-m-d') ?>" required class="w-full p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#1a2332] text-xs font-num focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500">
            </div>

            <!-- Detailed Notes -->
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">ملاحظات وسند الفسخ (رقم المحضر / تقرير المعاينة)</label>
                <textarea name="termination_notes" id="termNotes" rows="2.5" placeholder="أدخل تفاصيل ومبررات قرار الفسخ أو رقم المحضر والسند القانوني..." class="w-full p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#1a2332] text-xs focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500"></textarea>
            </div>

            <!-- Warning Notice -->
            <div class="p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-[11px] text-rose-800 dark:text-rose-300 space-y-1">
                <div class="font-bold flex items-center gap-1.5">
                    <span>⚠️</span>
                    <span>الإجراءات التلقائية المترتبة على الفسخ:</span>
                </div>
                <ul class="list-disc list-inside space-y-0.5 text-[10px] text-rose-700 dark:text-rose-400">
                    <li>تحويل حالة العقد رسمياً إلى <strong>مفسوخ (Terminé)</strong> مع توثيق السند والسبب.</li>
                    <li>إلغاء تخصيص أي مركبة مرتبطة بالسائق لتصبح متاحة لسائق آخر فوراً.</li>
                    <li>تجميد حساب السائق ونقل ملفه مباشرة إلى <strong>الأرشيف وسلة المهملات</strong>.</li>
                </ul>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="closeTerminateContractModal()" class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold transition">إلغاء</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black transition shadow-xs flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                    <span>تأكيد الفسخ وإحالة السائق للأرشيف</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openTerminateContractModal(contractId, contractNumber, driverId, driverName, vehiclePlate) {
    const selContainer = document.getElementById('termContractSelectContainer');
    if (selContainer) selContainer.classList.add('hidden');

    document.getElementById('termContractId').value = contractId || '';
    document.getElementById('termContractNumLabel').textContent = contractNumber ? `عقد رقم: ${contractNumber}` : '';
    document.getElementById('termDriverId').value = driverId || '';
    document.getElementById('termDriverName').textContent = driverName || '';
    const vehRow = document.getElementById('termVehicleRow');
    const vehPlate = document.getElementById('termVehiclePlate');
    if (vehiclePlate) {
        vehPlate.textContent = vehiclePlate;
        vehRow.classList.remove('hidden');
    } else {
        vehPlate.textContent = 'بدون مركبة معينة';
    }
    const modal = document.getElementById('terminateContractModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function openTerminateContractSelectModal() {
    const selContainer = document.getElementById('termContractSelectContainer');
    if (selContainer) selContainer.classList.remove('hidden');

    const picker = document.getElementById('termContractPicker');
    if (picker) picker.value = '';

    document.getElementById('termContractId').value = '';
    document.getElementById('termContractNumLabel').textContent = '';
    document.getElementById('termDriverId').value = '';
    document.getElementById('termDriverName').textContent = 'يرجى تحديد العقد من القائمة أعلاه';
    document.getElementById('termVehiclePlate').textContent = '-';

    const modal = document.getElementById('terminateContractModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function onTermContractPickerChange(select) {
    const opt = select.options[select.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('termContractId').value = opt.value;
        document.getElementById('termContractNumLabel').textContent = opt.dataset.num ? `عقد رقم: ${opt.dataset.num}` : '';
        document.getElementById('termDriverId').value = opt.dataset.driverId || '';
        document.getElementById('termDriverName').textContent = opt.dataset.driverName || '';
        document.getElementById('termVehiclePlate').textContent = opt.dataset.vehicle || 'بدون مركبة معينة';
    } else {
        document.getElementById('termContractId').value = '';
        document.getElementById('termContractNumLabel').textContent = '';
        document.getElementById('termDriverId').value = '';
        document.getElementById('termDriverName').textContent = 'يرجى تحديد العقد من القائمة أعلاه';
        document.getElementById('termVehiclePlate').textContent = '-';
    }
}

function closeTerminateContractModal() {
    const modal = document.getElementById('terminateContractModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openEditContractModal(data) {
    document.getElementById('editContractId').value = data.id || '';
    document.getElementById('editContractNumLabel').textContent = data.contract_number ? `رقم العقد: ${data.contract_number}` : '';
    document.getElementById('editContractDriver').value = data.driver_id || '';
    document.getElementById('editContractVehicle').value = data.vehicle_id || '';
    document.getElementById('editContractStartDate').value = data.start_date || '';
    document.getElementById('editContractEndDate').value = data.end_date || '';
    document.getElementById('editContractWage').value = data.monthly_wage || 45000;
    document.getElementById('editContractStatus').value = data.status || 'signed';
    document.getElementById('editContractNotes').value = data.notes || '';

    const modal = document.getElementById('editContractModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditContractModal() {
    const modal = document.getElementById('editContractModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if ($preselectedDriverId > 0 || ($_GET['action'] ?? '') === 'new'): ?>
    const newModal = document.getElementById('newContractModal');
    if (newModal) {
        newModal.classList.remove('hidden');
        newModal.classList.add('flex');
        const driverSelect = document.getElementById('contractDriverSelect');
        if (driverSelect && driverSelect.value) {
            onDriverSelectedForContract(driverSelect);
        }
    }
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
