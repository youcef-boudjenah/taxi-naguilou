<?php
// dashboard/vehicles.php - Full Fleet & Vehicle Management
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = getDBConnection();
$drivers = [];

// Fetch Vehicle Brand & Model Presets from settings (parameter)
$defaultPresets = [
    'Renault' => ['Symbol', 'Megane', 'Clio', 'Fluence', 'Kangoo'],
    'Dacia' => ['Logan', 'Sandero', 'Stepway', 'Duster', 'Dokker'],
    'Peugeot' => ['301', '208', '308', '2008', 'Partner', '307'],
    'Hyundai' => ['Accent', 'i10', 'i20', 'Elantra', 'Tucson'],
    'Toyota' => ['Corolla', 'Yaris', 'Avensis', 'Hilux'],
    'Citroën' => ['C-Elysée', 'C3', 'C4', 'Berlingo'],
    'Fiat' => ['Tipo', 'Punto', 'Doblo', '500'],
    'Skoda' => ['Octavia', 'Fabia', 'Rapid'],
    'Volkswagen' => ['Golf', 'Polo', 'Passat', 'Caddy'],
    'Kia' => ['Picanto', 'Rio', 'Cerato']
];
$vehiclePresets = $defaultPresets;

if ($pdo) {
    // 1. Defensively load drivers for vehicle assignment
    try {
        $uCols = [];
        try {
            $uCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {}
        $uColMap = array_flip($uCols);

        $vCols = [];
        try {
            $vCols = $pdo->query("SHOW COLUMNS FROM `vehicles`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {}
        $vColMap = array_flip($vCols);

        $cardSelect = isset($uColMap['card_number']) ? "u.card_number" : "NULL AS card_number";
        $probSelect = isset($uColMap['probation_stage']) ? "u.probation_stage" : "'trial_1' AS probation_stage";
        $plateSelect = isset($vColMap['plate_number']) ? "v.plate_number AS current_plate" : "NULL AS current_plate";
        $doorSelect = isset($vColMap['door_number']) ? "v.door_number AS current_door" : "NULL AS current_door";

        $joinVeh = "";
        if (isset($vColMap['current_driver_id'])) {
            $statusCond = isset($vColMap['status']) ? "AND v.status = 'active'" : "";
            $joinVeh = "LEFT JOIN vehicles v ON (v.current_driver_id = u.id $statusCond)";
        }

        $stmtDrivers = $pdo->query("
            SELECT u.id, u.full_name, u.phone, u.license_number, $cardSelect, $probSelect,
                   $plateSelect, $doorSelect
            FROM users u
            $joinVeh
            WHERE u.role = 'driver' AND u.status = 'active'
            ORDER BY u.full_name ASC
        ");
        $drivers = $stmtDrivers ? $stmtDrivers->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {
        error_log("vehicles.php - Driver query notice: " . $e->getMessage());
        $drivers = [];
    }

    // 2. Load presets safely
    try {
        $vehiclePresetsRaw = get_setting($pdo, 'vehicle_brands_models', '');
        $decoded = !empty($vehiclePresetsRaw) ? json_decode($vehiclePresetsRaw, true) : null;
        if (is_array($decoded) && !empty($decoded)) {
            $vehiclePresets = $decoded;
        } else {
            update_setting($pdo, 'vehicle_brands_models', json_encode($defaultPresets, JSON_UNESCAPED_UNICODE), 'fleet');
        }
    } catch (\Throwable $e) {
        $vehiclePresets = $defaultPresets;
    }
}

// Handle Add / Edit / Delete Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    if (!$pdo) {
        set_flash('error', 'تعذر الاتصال بقاعدة البيانات.');
        redirect('/dashboard/vehicles.php');
    }
    require_csrf_token();
    $actionType = $_POST['action_type'];

    if ($actionType === 'delete') {
        if (!is_admin()) {
            set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف المركبات.');
            redirect('/dashboard/vehicles.php');
        }
        $delId = intval($_POST['vehicle_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM vehicles WHERE id=?")->execute([$delId]);
            log_audit($pdo, 'vehicle_deleted', 'vehicles', $delId, "حذف مركبة الأسطول رقم $delId");
            set_flash('success', 'تم حذف المركبة بنجاح من الأسطول');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر حذف المركبة لوجود عقود أو بيانات تأمين وسجلات مرتبطة بها');
        }
        redirect('/dashboard/vehicles.php');
    }

    if ($actionType === 'create' || $actionType === 'update') {
        if (is_driver()) {
            set_flash('error', 'عذراً، إضافة وتعديل بيانات المركبات محصورة بمدير المنصة ومالك الأسطول.');
            redirect('/dashboard/vehicles.php');
        }
    }

    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $color = trim($_POST['color'] ?? 'أصفر');
    $doorNumber = trim($_POST['door_number'] ?? '');
    $driverId = !empty($_POST['current_driver_id']) ? intval($_POST['current_driver_id']) : null;
    $replacementDriverId = !empty($_POST['replacement_driver_id']) ? intval($_POST['replacement_driver_id']) : null;
    $insuranceExpiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $insuranceCompany = trim($_POST['insurance_company'] ?? 'SAA');
    $tcExpiry = !empty($_POST['technical_control_expiry']) ? $_POST['technical_control_expiry'] : null;
    $extExpiry = !empty($_POST['extinguisher_expiry']) ? $_POST['extinguisher_expiry'] : null;
    $gplExpiry = !empty($_POST['gpl_expiry']) ? $_POST['gpl_expiry'] : null;
    $gplText = trim($_POST['gpl_text'] ?? '');
    $sacExpiry = !empty($_POST['saccuriometre_expiry']) ? $_POST['saccuriometre_expiry'] : null;
    $sacText = trim($_POST['saccuriometre_text'] ?? 'معاير ومختوم');

    if ($actionType === 'create') {
        try {
            $vCols = [];
            try {
                $vCols = $pdo->query("SHOW COLUMNS FROM `vehicles`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (\Throwable $e) {}
            $vColMap = array_flip($vCols);

            $insertData = [
                'owner_id' => $user['id'],
                'current_driver_id' => $driverId,
                'brand' => $brand,
                'model' => $model,
                'plate_number' => $plateNumber,
                'color' => $color,
                'door_number' => $doorNumber,
                'status' => 'active'
            ];
            if (isset($vColMap['replacement_driver_id'])) $insertData['replacement_driver_id'] = $replacementDriverId;
            if (isset($vColMap['insurance_expiry'])) $insertData['insurance_expiry'] = $insuranceExpiry;
            if (isset($vColMap['insurance_company'])) $insertData['insurance_company'] = $insuranceCompany;
            if (isset($vColMap['technical_control_expiry'])) $insertData['technical_control_expiry'] = $tcExpiry;
            if (isset($vColMap['extinguisher_expiry'])) $insertData['extinguisher_expiry'] = $extExpiry;
            if (isset($vColMap['gpl_expiry'])) $insertData['gpl_expiry'] = $gplExpiry;
            if (isset($vColMap['gpl_text'])) $insertData['gpl_text'] = $gplText;
            if (isset($vColMap['saccuriometre_expiry'])) $insertData['saccuriometre_expiry'] = $sacExpiry;
            if (isset($vColMap['saccuriometre_text'])) $insertData['saccuriometre_text'] = $sacText;

            $finalCols = [];
            $placeholders = [];
            $values = [];
            foreach ($insertData as $k => $val) {
                if (empty($vCols) || isset($vColMap[$k])) {
                    $finalCols[] = "`$k`";
                    $placeholders[] = "?";
                    $values[] = $val;
                }
            }

            $sql = "INSERT INTO vehicles (" . implode(', ', $finalCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $newId = (int)$pdo->lastInsertId();
            log_audit($pdo, 'vehicle_created', 'vehicles', $newId, "إضافة مركبة أسطول جديدة: $brand $model ($plateNumber)");
            set_flash('success', 'تمت إضافة سيارة الأجرة بنجاح إلى الأسطول');
            redirect('/dashboard/vehicles.php');
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء الإضافة: رقم اللوحة قد يكون مسجلاً مسبقاً أو تعذر الحفظ (' . $e->getMessage() . ')');
        }
    } elseif ($actionType === 'update') {
        $vId = intval($_POST['vehicle_id'] ?? 0);
        try {
            $vCols = [];
            try {
                $vCols = $pdo->query("SHOW COLUMNS FROM `vehicles`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (\Throwable $e) {}
            $vColMap = array_flip($vCols);

            $updateData = [
                'brand' => $brand,
                'model' => $model,
                'plate_number' => $plateNumber,
                'color' => $color,
                'door_number' => $doorNumber,
                'current_driver_id' => $driverId
            ];
            if (isset($vColMap['replacement_driver_id'])) $updateData['replacement_driver_id'] = $replacementDriverId;
            if (isset($vColMap['insurance_expiry'])) $updateData['insurance_expiry'] = $insuranceExpiry;
            if (isset($vColMap['insurance_company'])) $updateData['insurance_company'] = $insuranceCompany;
            if (isset($vColMap['technical_control_expiry'])) $updateData['technical_control_expiry'] = $tcExpiry;
            if (isset($vColMap['extinguisher_expiry'])) $updateData['extinguisher_expiry'] = $extExpiry;
            if (isset($vColMap['gpl_expiry'])) $updateData['gpl_expiry'] = $gplExpiry;
            if (isset($vColMap['gpl_text'])) $updateData['gpl_text'] = $gplText;
            if (isset($vColMap['saccuriometre_expiry'])) $updateData['saccuriometre_expiry'] = $sacExpiry;
            if (isset($vColMap['saccuriometre_text'])) $updateData['saccuriometre_text'] = $sacText;

            $sets = [];
            $values = [];
            foreach ($updateData as $k => $val) {
                if (empty($vCols) || isset($vColMap[$k])) {
                    $sets[] = "`$k` = ?";
                    $values[] = $val;
                }
            }
            $values[] = $vId;

            $sql = "UPDATE vehicles SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            log_audit($pdo, 'vehicle_updated', 'vehicles', $vId, "تحديث بيانات ووثائق المركبة $plateNumber");
            set_flash('success', 'تم تحديث بيانات المركبة والوثائق بنجاح');
            redirect('/dashboard/vehicles.php');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر تحديث المركبة: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'سجل المركبات والوثائق';
require_once __DIR__ . '/../includes/header.php';

// Stats for Quick KPI Strip
$totalVehiclesCount = 0;
$assignedVehiclesCount = 0;
$expiringDocsCount = 0;
$conformityCount = 0;
$vehicles = [];

if ($pdo) {
    try {
        $totalVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $assignedVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE current_driver_id IS NOT NULL")->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $expiringDocsCount = (int)$pdo->query("
            SELECT COUNT(*) FROM vehicles 
            WHERE (technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND technical_control_expiry IS NOT NULL)
               OR (insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND insurance_expiry IS NOT NULL)
        ")->fetchColumn();
    } catch (\Throwable $e) {}

    $conformityCount = max(0, $totalVehiclesCount - $expiringDocsCount);

    // Fetch vehicles with filters
    $search = trim($_GET['search'] ?? '');
    $filter = $_GET['filter'] ?? 'all';

    $hasReplacementCol = false;
    try {
        $pdo->query("SELECT replacement_driver_id FROM vehicles LIMIT 0");
        $hasReplacementCol = true;
    } catch (\Throwable $e) {}

    $joinRepl = $hasReplacementCol ? "LEFT JOIN users r ON v.replacement_driver_id = r.id" : "";
    $selectRepl = $hasReplacementCol ? "r.full_name as replacement_driver_name, r.phone as replacement_driver_phone," : "NULL as replacement_driver_name, NULL as replacement_driver_phone,";

    $query = "SELECT v.*, u.full_name as driver_name, u.phone as driver_phone, $selectRepl v.id FROM vehicles v LEFT JOIN users u ON v.current_driver_id = u.id $joinRepl WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $hasDoorCol = false;
        try {
            $pdo->query("SELECT door_number FROM vehicles LIMIT 0");
            $hasDoorCol = true;
        } catch (\Throwable $e) {}

        $doorCondition = $hasDoorCol ? " OR v.door_number LIKE ?" : "";
        $query .= " AND (v.plate_number LIKE ? OR v.brand LIKE ? OR v.model LIKE ? OR u.full_name LIKE ?$doorCondition)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        if ($hasDoorCol) {
            $params[] = "%$search%";
        }
    }

    if ($filter === 'expiring') {
        $query .= " AND ((v.technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND v.technical_control_expiry IS NOT NULL) OR (v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND v.insurance_expiry IS NOT NULL))";
    } elseif ($filter === 'unassigned') {
        $query .= " AND v.current_driver_id IS NULL";
    }

    if (is_driver()) {
        $dId = intval($_SESSION['user_id']);
        if ($hasReplacementCol) {
            $query .= " AND (v.current_driver_id = ? OR v.replacement_driver_id = ?)";
            $params[] = $dId;
            $params[] = $dId;
        } else {
            $query .= " AND v.current_driver_id = ?";
            $params[] = $dId;
        }
    } elseif (is_owner() && !is_admin()) {
        $oId = intval($_SESSION['user_id']);
        $query .= " AND (v.owner_id = ? OR v.owner_id IS NULL)";
        $params[] = $oId;
    }

    $query .= " ORDER BY v.id DESC";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $vehicles = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        error_log("vehicles.php - Query vehicles error: " . $e->getMessage());
        $vehicles = [];
    }
}

// Vehicle to edit if edit param present
$editVehicle = null;
$selectedPrimaryDriver = null;
$selectedReplacementDriver = null;

if (isset($_GET['edit']) && $pdo) {
    try {
        $eId = intval($_GET['edit']);
        $stmtE = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
        $stmtE->execute([$eId]);
        $editVehicle = $stmtE->fetch() ?: null;
        if ($editVehicle) {
            if (!empty($editVehicle['current_driver_id'])) {
                foreach ($drivers as $d) {
                    if ($d['id'] == $editVehicle['current_driver_id']) {
                        $selectedPrimaryDriver = $d;
                        break;
                    }
                }
            }
            if (!empty($editVehicle['replacement_driver_id'])) {
                foreach ($drivers as $d) {
                    if ($d['id'] == $editVehicle['replacement_driver_id']) {
                        $selectedReplacementDriver = $d;
                        break;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("vehicles.php - Fetch edit vehicle error: " . $e->getMessage());
        $editVehicle = null;
    }
}
?>

<div class="space-y-6">

    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">سجل المركبات والأسطول</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20 font-num">
                    <?= count($vehicles) ?> مركبة
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">متابعة الفحص التقني الدوري، التأمين الشامل، خزان سيرغاز GPL، والساكوريومتر</p>
        </div>
        <div class="flex items-center gap-2">
            <?php if (is_admin() || is_owner()): ?>
            <button onclick="document.getElementById('addVehicleModal').classList.toggle('hidden')" 
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition-all shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>إضافة سيارة جديدة</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Stats Cards (Clean & Simple) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">إجمالي الأسطول</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $totalVehiclesCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75a3 3 0 013 3v3.75m-6-6.75H6.75a2.25 2.25 0 00-2.25 2.25v6.75" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">في الخدمة مع سائق</p>
                <p class="text-xl font-bold text-emerald-600 font-num mt-0.5"><?= $assignedVehiclesCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">مطابقة الوثائق</p>
                <p class="text-xl font-bold text-slate-700 font-num mt-0.5"><?= $conformityCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                </svg>
            </div>
        </div>

        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">تنبيهات الانتهاء (&lt; 15 يوم)</p>
                <p class="text-xl font-bold <?= $expiringDocsCount > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num mt-0.5"><?= $expiringDocsCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $expiringDocsCount > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar (Unified) -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث برقم اللوحة، الماركة، رقم الباب، أو اسم السائق..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="vehicles.php" class="text-xs text-slate-400 hover:text-slate-600 underline">إلغاء</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            <a href="vehicles.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                الكل (<?= $totalVehiclesCount ?>)
            </a>
            <a href="vehicles.php?filter=expiring<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'expiring' ? 'bg-rose-500 text-white' : 'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200/60' ?>">
                <span>تنبيهات الوثائق</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= $filter === 'expiring' ? 'bg-white text-rose-600' : 'bg-rose-200 text-rose-800' ?>"><?= $expiringDocsCount ?></span>
            </a>
            <a href="vehicles.php?filter=unassigned<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'unassigned' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                شاغرة بدون سائق
            </a>
        </div>
    </div>

    <!-- Vehicles Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
                    <tr>
                        <th class="p-3.5 whitespace-nowrap">المركبة ولوحة الترقيم</th>
                        <th class="p-3.5 whitespace-nowrap">رقم الباب واللون</th>
                        <th class="p-3.5 whitespace-nowrap">السائق المكلف</th>
                        <th class="p-3.5 whitespace-nowrap">المراقبة التقنية</th>
                        <th class="p-3.5 whitespace-nowrap">التأمين الشامل</th>
                        <th class="p-3.5 whitespace-nowrap">فحص سيرغاز GPL</th>
                        <th class="p-3.5 whitespace-nowrap">عداد التاكسي</th>
                        <th class="p-3.5 text-center whitespace-nowrap">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (!empty($vehicles)): ?>
                        <?php foreach ($vehicles as $v): 
                            $tcDiff = !empty($v['technical_control_expiry']) ? round((strtotime($v['technical_control_expiry']) - time()) / 86400) : null;
                            $insDiff = !empty($v['insurance_expiry']) ? round((strtotime($v['insurance_expiry']) - time()) / 86400) : null;
                        ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <!-- Vehicle & Plate -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 text-[13px]"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?></div>
                                    <div class="mt-0.5 inline-flex items-center px-2 py-0.5 rounded bg-slate-900 text-amber-400 font-num font-mono text-[11px] tracking-wider" dir="ltr">
                                        <?= htmlspecialchars($v['plate_number']) ?>
                                    </div>
                                </td>

                                <!-- Door & Color -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-semibold text-slate-800 font-num">باب: <?= htmlspecialchars($v['door_number']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($v['color']) ?></div>
                                </td>

                                <!-- Driver -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['driver_name'])): ?>
                                        <div class="font-bold text-slate-800"><?= htmlspecialchars($v['driver_name']) ?></div>
                                        <div class="text-[11px] text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($v['driver_phone']) ?></div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-500">
                                            شاغر (بدون سائق)
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($v['replacement_driver_name'])): ?>
                                        <div class="mt-1">
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200/60">
                                                <span>بديل:</span>
                                                <span><?= htmlspecialchars($v['replacement_driver_name']) ?></span>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Technical Control -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($tcDiff !== null && $tcDiff <= 15): ?>
                                        <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                            </svg>
                                            <span class="font-num"><?= htmlspecialchars($v['technical_control_expiry']) ?></span>
                                            <span class="text-[10px]">(باقي <?= $tcDiff ?> ي)</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="font-num text-slate-700 font-medium"><?= htmlspecialchars($v['technical_control_expiry'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Insurance -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($insDiff !== null && $insDiff <= 15): ?>
                                        <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            <span class="font-num"><?= htmlspecialchars($v['insurance_expiry']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="font-num text-slate-800 font-medium"><?= htmlspecialchars($v['insurance_expiry'] ?? '—') ?></div>
                                    <?php endif; ?>
                                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($v['insurance_company']) ?></div>
                                </td>

                                <!-- GPL Check -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['gpl_expiry'])): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 font-num">
                                            <span>GPL:</span>
                                            <span><?= htmlspecialchars($v['gpl_expiry']) ?></span>
                                        </span>
                                    <?php elseif (($v['gpl_text'] ?? '') === 'بدون GPL'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                                            بدون GPL
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 font-num">
                                            <?= htmlspecialchars(!empty($v['gpl_text']) ? $v['gpl_text'] : 'مطابق') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Taximeter (Saccuriometre) -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (($v['saccuriometre_text'] ?? '') === 'بدون عداد'): ?>
                                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-500 text-[11px] font-medium border border-slate-200">
                                            بدون عداد
                                        </span>
                                    <?php else: ?>
                                        <div class="text-[11px] font-medium text-slate-800">
                                            <?= htmlspecialchars($v['saccuriometre_text'] ?? 'معاير ومختوم') ?>
                                        </div>
                                        <?php if (!empty($v['saccuriometre_expiry'])): ?>
                                            <div class="text-[10px] text-slate-400 font-num">إلى: <?= htmlspecialchars($v['saccuriometre_expiry']) ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if (is_admin() || is_owner()): ?>
                                        <a href="vehicles.php?edit=<?= $v['id'] ?>" 
                                           title="تعديل البيانات والوثائق"
                                           class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition text-xs">
                                            تعديل
                                        </a>
                                        <a href="contracts.php?action=new&vehicle_id=<?= $v['id'] ?>" 
                                           title="إصدار عقد عمل 90-11"
                                           class="px-2.5 py-1 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-800 font-bold transition text-xs border border-amber-200/60">
                                            عقد 90-11
                                        </a>
                                        <?php else: ?>
                                        <a href="slips.php" class="px-2.5 py-1 rounded-lg bg-amber-50 text-amber-800 font-bold transition text-xs border border-amber-200/60">
                                            وصل السير
                                        </a>
                                        <?php endif; ?>
                                        <?php if (is_admin()): ?>
                                        <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذه المركبة من الأسطول؟')" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action_type" value="delete">
                                            <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                            <button type="submit" class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="حذف المركبة">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-12 text-center text-slate-400">
                                <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-3">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75a3 3 0 013 3v3.75m-6-6.75H6.75a2.25 2.25 0 00-2.25 2.25v6.75" />
                                    </svg>
                                </div>
                                <p class="text-sm font-semibold text-slate-600">لا توجد مركبات مطابقة لمعايير البحث</p>
                                <p class="text-xs text-slate-400 mt-1">جرّب تغيير كلمات البحث أو إعادة ضبط التصفية</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Add / Edit Vehicle -->
<div id="addVehicleModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm <?= $editVehicle ? 'flex' : 'hidden' ?> flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-2xl w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl max-h-[92vh] overflow-y-auto space-y-5 text-right m-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">
                    <?= $editVehicle ? 'تعديل بيانات سيارة الأجرة والوثائق' : 'إضافة سيارة أجرة جديدة إلى الأسطول' ?>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">أدخل بيانات المركبة وتواريخ صلاحية المراقبة التقنية والتأمين والعداد</p>
            </div>
            <a href="vehicles.php" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </a>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action_type" value="<?= $editVehicle ? 'update' : 'create' ?>">
            <?php if ($editVehicle): ?>
                <input type="hidden" name="vehicle_id" value="<?= $editVehicle['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="insurance_company" value="<?= htmlspecialchars($editVehicle['insurance_company'] ?? 'SAA') ?>">
            <input type="hidden" name="current_driver_id" id="driverInput_primary" value="<?= htmlspecialchars($editVehicle['current_driver_id'] ?? '') ?>">
            <input type="hidden" name="replacement_driver_id" id="driverInput_replacement" value="<?= htmlspecialchars($editVehicle['replacement_driver_id'] ?? '') ?>">

            <!-- ==============================================
                 1. تعيين السائق الأساسي والسائق البديل (في بداية الاستمارة - بحث AJAX)
                 ============================================== -->
            <div class="p-4 rounded-2xl bg-amber-50/70 border border-amber-200/80 space-y-4 shadow-2xs">
                <div class="flex items-center justify-between pb-2 border-b border-amber-200/60">
                    <div>
                        <span class="font-black text-amber-950 text-xs flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <span>تعيين السائقين للمركبة (خطوة أولى أساسية)</span>
                        </span>
                        <p class="text-[10px] text-amber-800/80 mt-0.5">قائمة ذكية مع بحث مباشر (AJAX) لربط فوري بالأسطول</p>
                    </div>
                    <span class="text-[10px] font-bold bg-amber-200/70 text-amber-950 px-2.5 py-1 rounded-md">
                        قائمة وسجل السائقين
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- تعيين السائق الأساسي مع بحث AJAX -->
                    <div class="space-y-1.5" id="slotContainer_primary">
                        <label class="block font-bold text-slate-800 text-xs flex items-center justify-between">
                            <span>تعيين السائق الأساسي <span class="text-rose-500">*</span></span>
                            <span class="text-[10px] text-amber-800 font-bold">بحث مباشر AJAX</span>
                        </label>

                        <!-- Selected Driver Card (Primary) -->
                        <div id="driverSelectedCard_primary" class="<?= $selectedPrimaryDriver ? '' : 'hidden' ?> p-3 rounded-xl bg-white border border-amber-300 shadow-2xs flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center text-xs shadow-2xs shrink-0">
                                    👤
                                </div>
                                <div>
                                    <div class="font-bold text-slate-900 text-xs flex items-center gap-1.5">
                                        <span id="driverSelectedName_primary"><?= htmlspecialchars($selectedPrimaryDriver['full_name'] ?? '') ?></span>
                                        <span id="driverSelectedStage_primary" class="text-[9px] px-1.5 py-0.5 rounded font-bold bg-amber-100 text-amber-900">
                                            <?= htmlspecialchars($selectedPrimaryDriver['probation_stage'] ?? 'نشط') ?>
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-slate-500 font-num mt-0.5" id="driverSelectedDetails_primary">
                                        هاتف: <?= htmlspecialchars($selectedPrimaryDriver['phone'] ?? '') ?> • رخصة: <?= htmlspecialchars($selectedPrimaryDriver['license_number'] ?? 'غير مسجلة') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" onclick="showDriverSearch('primary')" class="px-2 py-1 text-[11px] font-bold text-amber-900 hover:bg-amber-100 rounded-lg transition">
                                    تغيير
                                </button>
                                <button type="button" onclick="clearDriverSelection('primary')" class="px-1.5 py-1 text-slate-400 hover:text-rose-600 rounded-lg transition" title="إلغاء التعيين">
                                    ✕
                                </button>
                            </div>
                        </div>

                        <!-- Search Box & Dropdown (Primary) -->
                        <div id="driverSearchBox_primary" class="<?= $selectedPrimaryDriver ? 'hidden' : '' ?> relative space-y-1">
                            <div class="relative">
                                <input type="text" id="driverSearchInput_primary" 
                                       placeholder="🔍 اكتب اسم السائق أو الهاتف أو الرخصة..." 
                                       oninput="onDriverSearchInput('primary', this.value)"
                                       onfocus="openDriverDropdown('primary')"
                                       autocomplete="off"
                                       class="w-full pl-8 pr-3 py-2.5 rounded-xl border border-amber-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 font-medium placeholder:text-slate-400 shadow-2xs">
                                <div id="driverSearchSpinner_primary" class="absolute left-2.5 top-1/2 -translate-y-1/2 hidden">
                                    <svg class="animate-spin h-3.5 w-3.5 text-amber-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                </div>
                            </div>

                            <!-- Dropdown Results (Primary) -->
                            <div id="driverDropdown_primary" class="hidden absolute top-full right-0 left-0 mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl divide-y divide-slate-100 text-xs z-30">
                                <!-- Populated dynamically -->
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-500">اختر السائق الأساسي المعتمد لتسيير المركبة وإصدار عقود العمل المرتبطة بها.</p>
                    </div>

                    <!-- تعيين السائق البديل مع بحث AJAX -->
                    <div class="space-y-1.5" id="slotContainer_replacement">
                        <label class="block font-bold text-slate-800 text-xs flex items-center justify-between">
                            <span>تعيين السائق البديل (Chauffeur Remplaçant)</span>
                            <span class="text-[10px] text-slate-400 font-normal">اختياري</span>
                        </label>

                        <!-- Selected Driver Card (Replacement) -->
                        <div id="driverSelectedCard_replacement" class="<?= $selectedReplacementDriver ? '' : 'hidden' ?> p-3 rounded-xl bg-white border border-slate-300 shadow-2xs flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-slate-700 text-white font-black flex items-center justify-center text-xs shadow-2xs shrink-0">
                                    🔄
                                </div>
                                <div>
                                    <div class="font-bold text-slate-900 text-xs flex items-center gap-1.5">
                                        <span id="driverSelectedName_replacement"><?= htmlspecialchars($selectedReplacementDriver['full_name'] ?? '') ?></span>
                                        <span id="driverSelectedStage_replacement" class="text-[9px] px-1.5 py-0.5 rounded font-bold bg-slate-100 text-slate-800">
                                            <?= htmlspecialchars($selectedReplacementDriver['probation_stage'] ?? 'بديل') ?>
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-slate-500 font-num mt-0.5" id="driverSelectedDetails_replacement">
                                        هاتف: <?= htmlspecialchars($selectedReplacementDriver['phone'] ?? '') ?> • رخصة: <?= htmlspecialchars($selectedReplacementDriver['license_number'] ?? 'غير مسجلة') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" onclick="showDriverSearch('replacement')" class="px-2 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-100 rounded-lg transition">
                                    تغيير
                                </button>
                                <button type="button" onclick="clearDriverSelection('replacement')" class="px-1.5 py-1 text-slate-400 hover:text-rose-600 rounded-lg transition" title="إلغاء التعيين">
                                    ✕
                                </button>
                            </div>
                        </div>

                        <!-- Search Box & Dropdown (Replacement) -->
                        <div id="driverSearchBox_replacement" class="<?= $selectedReplacementDriver ? 'hidden' : '' ?> relative space-y-1">
                            <div class="relative">
                                <input type="text" id="driverSearchInput_replacement" 
                                       placeholder="🔍 اكتب اسم السائق البديل للتصفية..." 
                                       oninput="onDriverSearchInput('replacement', this.value)"
                                       onfocus="openDriverDropdown('replacement')"
                                       autocomplete="off"
                                       class="w-full pl-8 pr-3 py-2.5 rounded-xl border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 font-medium placeholder:text-slate-400 shadow-2xs">
                                <div id="driverSearchSpinner_replacement" class="absolute left-2.5 top-1/2 -translate-y-1/2 hidden">
                                    <svg class="animate-spin h-3.5 w-3.5 text-slate-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                </div>
                            </div>

                            <!-- Dropdown Results (Replacement) -->
                            <div id="driverDropdown_replacement" class="hidden absolute top-full right-0 left-0 mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl divide-y divide-slate-100 text-xs z-30">
                                <!-- Populated dynamically -->
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-500">السائق البديل يتولى المناوبة الميدانية في حال غياب أو عطلة السائق الأساسي.</p>
                    </div>
                </div>
            </div>

            <!-- ==============================================
                 2. بيانات وتفاصيل المركبة (الماركة أو الصانع + الموديل - من الإعدادات)
                 ============================================== -->
            <div class="space-y-3.5">
                <div class="flex items-center justify-between pb-1.5 border-b border-slate-100">
                    <span class="font-black text-slate-800 text-xs flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-slate-700"></span>
                        <span>بيانات المركبة والموديل</span>
                    </span>
                    <button type="button" onclick="openQuickPresetModal()" 
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 flex items-center gap-1 transition shadow-2xs">
                        <span>⚙️ إضافة ماركة / طراز في الإعدادات</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- الماركة أو الصانع -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label class="block font-bold text-slate-700">
                                الماركة أو الصانع <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-slate-400">مثال: رينو، داسيا، بيجو</span>
                        </div>

                        <!-- Brand selection dropdown from parameters -->
                        <select id="brandSelectDropdown" onchange="onBrandSelectChanged(this.value)"
                                class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white shadow-2xs">
                            <option value="">-- اختر الصانع من الإعدادات أو اكتب أدناه --</option>
                            <?php $presetBrandKeys = is_array($vehiclePresets) ? array_keys($vehiclePresets) : []; ?>
                            <?php foreach ($presetBrandKeys as $b): ?>
                                <option value="<?= htmlspecialchars($b) ?>" <?= ($editVehicle && strcasecmp($editVehicle['brand'] ?? '', $b) === 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Brand custom text input -->
                        <input type="text" name="brand" id="vehicleBrandInput" required 
                               placeholder="اكتب اسم الصانع أو اختر من القائمة أعلاه..." 
                               value="<?= htmlspecialchars($editVehicle['brand'] ?? '') ?>"
                               oninput="onBrandInputChanged(this.value)"
                               class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-bold transition bg-white shadow-2xs">

                        <!-- Brand Preset Pills -->
                        <div class="flex flex-wrap gap-1 mt-1" id="brandPillsContainer">
                            <?php foreach (array_slice($presetBrandKeys, 0, 7) as $b): ?>
                                <button type="button" onclick="selectBrandPreset('<?= htmlspecialchars($b, ENT_QUOTES) ?>')" 
                                        class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 hover:bg-amber-100 hover:text-amber-900 text-slate-700 transition">
                                    <?= htmlspecialchars($b) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- طراز المركبة (الموديل) -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label class="block font-bold text-slate-700">
                                طراز المركبة (الموديل) <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-slate-400">طراز التاكسي</span>
                        </div>

                        <!-- Model selection dropdown from parameters -->
                        <select id="modelSelectDropdown" onchange="onModelSelectChanged(this.value)"
                                class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white shadow-2xs">
                            <option value="">-- اختر الطراز من الإعدادات أو اكتب أدناه --</option>
                            <!-- Dynamically filled based on selected brand -->
                        </select>

                        <!-- Model custom text input -->
                        <input type="text" name="model" id="vehicleModelInput" required 
                               placeholder="مثال: سامبول، لوغان، 301، أكسنت..." 
                               value="<?= htmlspecialchars($editVehicle['model'] ?? '') ?>"
                               class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-bold transition bg-white shadow-2xs">

                        <!-- Quick Model Pills (Dynamically refreshed on brand click) -->
                        <div class="flex flex-wrap gap-1 mt-1" id="modelPillsContainer">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-1">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">
                            رقم لوحة الترقيم <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="plate_number" required placeholder="01458-121-16" dir="ltr" 
                               value="<?= htmlspecialchars($editVehicle['plate_number'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 text-right font-num font-mono transition bg-white shadow-2xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">
                            رقم الباب المعتمد <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="door_number" required placeholder="مثال: 16-042" 
                               value="<?= htmlspecialchars($editVehicle['door_number'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-num transition bg-white shadow-2xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">لون السيارة</label>
                        <input type="text" name="color" value="<?= htmlspecialchars($editVehicle['color'] ?? 'أصفر') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition bg-white shadow-2xs">
                    </div>
                </div>
            </div>

            <!-- ==============================================
                 3. تواريخ الصلاحية وتجهيزات الأمان والعداد
                 ============================================== -->
            <div class="border-t border-slate-100 pt-4 space-y-4">
                <span class="block font-black text-slate-800 text-xs">تواريخ الصلاحية والوثائق وتجهيزات الأمان:</span>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء المراقبة التقنية (Contrôle Technique)</label>
                        <input type="date" name="technical_control_expiry" value="<?= htmlspecialchars($editVehicle['technical_control_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition shadow-2xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء التأمين الشامل</label>
                        <input type="date" name="insurance_expiry" value="<?= htmlspecialchars($editVehicle['insurance_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition shadow-2xs">
                    </div>
                </div>

                <!-- تاريخ انتهاء طفاية الحريق + GPL -->
                <div class="p-4 rounded-2xl bg-slate-50/90 border border-slate-200/90 space-y-3.5 shadow-2xs">
                    <div class="flex items-center justify-between border-b border-slate-200/80 pb-2">
                        <span class="font-black text-slate-900 text-xs flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <span>تاريخ انتهاء طفاية الحريق + GPL</span>
                        </span>
                        <span class="text-[10px] font-bold text-slate-500">تجهيزات السلامة ومطابقة الغاز</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- انتهاء صلاحية مطفأة الحريق -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">انتهاء صلاحية مطفأة الحريق</label>
                            <input type="date" name="extinguisher_expiry" value="<?= htmlspecialchars($editVehicle['extinguisher_expiry'] ?? '') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition shadow-2xs">
                        </div>

                        <!-- انتهاء صلاحية شهادة GPL -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">انتهاء صلاحية شهادة GPL</label>
                            <input type="date" name="gpl_expiry" id="gplExpiryInput" value="<?= htmlspecialchars($editVehicle['gpl_expiry'] ?? '') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition shadow-2xs">
                        </div>
                    </div>

                    <!-- بدون GPL (ملاحظة) -->
                    <div class="space-y-1.5 pt-2 border-t border-slate-200/60">
                        <div class="flex items-center justify-between">
                            <label class="block font-bold text-slate-700">بدون GPL (ملاحظة)</label>
                            <button type="button" onclick="setWithoutGpl()" 
                                    class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 transition shadow-2xs flex items-center gap-1.5">
                                <span>اكتب "بدون GPL" تلقائي</span>
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" name="gpl_text" id="gplTextInput" placeholder='اكتب "بدون GPL" أو ملاحظة خزان الغاز...' 
                                   value="<?= htmlspecialchars($editVehicle['gpl_text'] ?? '') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white text-slate-700 transition shadow-2xs">
                            <button type="button" onclick="setWithoutGpl()" 
                                    class="shrink-0 px-3 py-2.5 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-slate-950 transition flex items-center gap-1 shadow-2xs">
                                <span>تلقائي</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- تاريخ انتهاء شهادة العداد -->
                <div class="p-4 rounded-2xl bg-slate-50/90 border border-slate-200/90 space-y-3.5 shadow-2xs">
                    <div class="flex items-center justify-between border-b border-slate-200/80 pb-2">
                        <span class="font-black text-slate-900 text-xs flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                            <span>تاريخ انتهاء شهادة العداد</span>
                        </span>
                        <span class="text-[10px] font-bold text-slate-500">الساكوريومتر وختم المعايرة</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- انتهاء صلاحية العداد -->
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">انتهاء صلاحية العداد</label>
                            <input type="date" name="saccuriometre_expiry" id="sacExpiryInput" value="<?= htmlspecialchars($editVehicle['saccuriometre_expiry'] ?? '') ?>"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-num transition shadow-2xs">
                        </div>

                        <!-- بدون عداد -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="block font-bold text-slate-700">بدون عداد</label>
                                <button type="button" onclick="setWithoutTaximeter()" 
                                        class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-slate-200 hover:bg-slate-300 text-slate-800 border border-slate-300 transition shadow-2xs flex items-center gap-1.5">
                                    <span>اكتب "بدون عداد" تلقائي</span>
                                </button>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="text" name="saccuriometre_text" id="sacTextInput" placeholder='اكتب "بدون عداد" أو حالة ختم العداد...' 
                                       value="<?= htmlspecialchars($editVehicle['saccuriometre_text'] ?? 'معاير ومختوم') ?>"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white text-slate-800 transition shadow-2xs">
                                <button type="button" onclick="setWithoutTaximeter()" 
                                        class="shrink-0 px-3 py-2.5 rounded-xl text-xs font-bold bg-slate-800 hover:bg-slate-900 text-white transition flex items-center gap-1 shadow-2xs">
                                    <span>تلقائي</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="vehicles.php" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </a>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    <?= $editVehicle ? 'حفظ التعديلات' : 'إضافة المركبة إلى الأسطول' ?>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- ==============================================
     Quick Add Brand / Model Preset Modal (Parameters)
     ============================================== -->
<div id="quickPresetModal" class="fixed inset-0 z-60 bg-slate-900/60 backdrop-blur-xs hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-md w-full rounded-2xl p-6 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div class="flex items-center gap-2">
                <span class="text-lg">⚙️</span>
                <h4 class="font-bold text-slate-900 text-sm">إضافة ماركة أو طراز في الإعدادات</h4>
            </div>
            <button type="button" onclick="closeQuickPresetModal()" class="text-slate-400 hover:text-slate-700 text-base">✕</button>
        </div>

        <p class="text-[11px] text-slate-500">
            أدخل الماركة والطرازات المعتمدة ليتم حفظها في إعدادات المنصة واعتمادها فورياً لجميع سيارات الأجرة.
        </p>

        <div class="space-y-3 text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">اسم الماركة أو الصانع <span class="text-rose-500">*</span></label>
                <input type="text" id="quickBrandInput" placeholder="مثال: Chery, Geely, DFSK, Suzuki..." 
                       class="w-full px-3 py-2 rounded-xl border border-slate-200 font-bold focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">الموديلات المعتمدة (مفصولة بفواصل)</label>
                <input type="text" id="quickModelsInput" placeholder="مثال: Tiggo 2, Tiggo 4, Arrizo 5" 
                       class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white">
            </div>
        </div>

        <div class="flex items-center justify-between pt-3 border-t border-slate-100">
            <a href="/dashboard/settings.php#fleet-presets" target="_blank" class="text-[11px] text-amber-600 hover:underline font-semibold">
                إدارة الإعدادات العامة ↗
            </a>
            <div class="flex items-center gap-2">
                <button type="button" onclick="closeQuickPresetModal()" class="px-3 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50">
                    إلغاء
                </button>
                <button type="button" onclick="saveQuickPreset()" id="savePresetBtn" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs shadow-2xs">
                    حفظ واعتماد
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Driver and Preset state
const ALL_DRIVERS = <?= json_encode($drivers, JSON_UNESCAPED_UNICODE) ?>;
let VEHICLE_PRESETS = <?= json_encode($vehiclePresets, JSON_UNESCAPED_UNICODE) ?>;
let driverSearchDebounce = null;

// ==========================================
// 1. AJAX & Interactive Driver Search
// ==========================================
function openDriverDropdown(slot) {
    const dropdown = document.getElementById('driverDropdown_' + slot);
    if (!dropdown) return;
    dropdown.classList.remove('hidden');
    
    const input = document.getElementById('driverSearchInput_' + slot);
    const query = input ? input.value.trim() : '';
    filterAndRenderDrivers(slot, query);
}

function onDriverSearchInput(slot, val) {
    clearTimeout(driverSearchDebounce);
    const spinner = document.getElementById('driverSearchSpinner_' + slot);
    if (spinner) spinner.classList.remove('hidden');

    driverSearchDebounce = setTimeout(() => {
        fetchDriversAjax(slot, val.trim());
    }, 200);
}

function fetchDriversAjax(slot, query) {
    const spinner = document.getElementById('driverSearchSpinner_' + slot);
    
    // Attempt live AJAX search endpoint
    fetch('/dashboard/ajax_drivers.php?q=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(data => {
            if (spinner) spinner.classList.add('hidden');
            renderDriverResults(slot, Array.isArray(data) ? data : []);
        })
        .catch(err => {
            // Fallback to client-side list
            if (spinner) spinner.classList.add('hidden');
            filterAndRenderDrivers(slot, query);
        });
}

function filterAndRenderDrivers(slot, query) {
    const q = query.toLowerCase();
    const filtered = ALL_DRIVERS.filter(d => {
        if (!q) return true;
        return (d.full_name && d.full_name.toLowerCase().includes(q)) ||
               (d.phone && d.phone.includes(q)) ||
               (d.license_number && d.license_number.toLowerCase().includes(q)) ||
               (d.card_number && d.card_number.toLowerCase().includes(q));
    });
    renderDriverResults(slot, filtered);
}

function renderDriverResults(slot, list) {
    const dropdown = document.getElementById('driverDropdown_' + slot);
    if (!dropdown) return;
    dropdown.classList.remove('hidden');

    const emptyText = slot === 'primary' ? '-- بدون سائق أساسي حالياً (شاغرة) --' : '-- بدون سائق بديل --';
    
    let html = `
        <div onclick="selectDriver('${slot}', null)" class="p-2.5 hover:bg-slate-50 cursor-pointer flex items-center justify-between text-slate-500 font-bold border-b border-slate-100">
            <span>🚫 ${emptyText}</span>
            <span class="text-[10px] text-slate-400">تفريغ</span>
        </div>
    `;

    if (list.length === 0) {
        html += `
            <div class="p-3 text-center text-slate-400 text-xs">
                لا يوجد سائق مطابق لبحثك
            </div>
        `;
    } else {
        html += list.map(d => {
            const stageLabel = d.probation_stage === 'trial_1' ? 'تجريبية 1' :
                               d.probation_stage === 'trial_2' ? 'تجريبية 2' :
                               d.probation_stage === 'trial_3' ? 'تجريبية 3' : 'سائق رسمي';
            const stageColor = d.probation_stage === 'official' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-amber-50 text-amber-800 border-amber-200';
            const plateNote = d.current_plate ? `<span class="text-[9px] text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded border border-amber-100">يسير: ${d.current_plate}</span>` : '';

            // Clean data for onclick
            const safeD = JSON.stringify(d).replace(/"/g, '&quot;');
            return `
                <div onclick='selectDriver("${slot}", ${safeD})' class="p-2.5 hover:bg-amber-50/70 cursor-pointer flex items-center justify-between transition">
                    <div class="flex items-center gap-2.5">
                        <div class="w-7 h-7 rounded-lg bg-slate-100 text-slate-800 font-bold flex items-center justify-center text-xs shrink-0">
                            👤
                        </div>
                        <div>
                            <div class="font-bold text-slate-900 text-xs flex items-center gap-1.5">
                                <span>${d.full_name}</span>
                                <span class="text-[9px] px-1.5 py-0.2 rounded border font-semibold ${stageColor}">${stageLabel}</span>
                                ${plateNote}
                            </div>
                            <div class="text-[10px] text-slate-500 font-num">
                                هاتف: ${d.phone || '-'} • رخصة: ${d.license_number || '-'}
                            </div>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold text-amber-600 bg-amber-100/60 px-2 py-0.5 rounded">اختيار</span>
                </div>
            `;
        }).join('');
    }

    dropdown.innerHTML = html;
}

function selectDriver(slot, driver) {
    const input = document.getElementById('driverInput_' + slot);
    const card = document.getElementById('driverSelectedCard_' + slot);
    const searchBox = document.getElementById('driverSearchBox_' + slot);
    const dropdown = document.getElementById('driverDropdown_' + slot);

    if (dropdown) dropdown.classList.add('hidden');

    if (!driver) {
        // Clear driver
        if (input) input.value = '';
        if (card) card.classList.add('hidden');
        if (searchBox) searchBox.classList.remove('hidden');
        const sInput = document.getElementById('driverSearchInput_' + slot);
        if (sInput) sInput.value = '';
        return;
    }

    if (input) input.value = driver.id;

    // Update selected card UI
    const nameEl = document.getElementById('driverSelectedName_' + slot);
    const stageEl = document.getElementById('driverSelectedStage_' + slot);
    const detailsEl = document.getElementById('driverSelectedDetails_' + slot);

    if (nameEl) nameEl.textContent = driver.full_name;
    if (stageEl) {
        stageEl.textContent = driver.probation_stage === 'trial_1' ? 'فترة تجريبية 1' :
                              driver.probation_stage === 'trial_2' ? 'فترة تجريبية 2' :
                              driver.probation_stage === 'trial_3' ? 'فترة تجريبية 3' : 'سائق رسمي';
    }
    if (detailsEl) {
        detailsEl.textContent = `هاتف: ${driver.phone || '-'} • رخصة: ${driver.license_number || 'غير مسجلة'}`;
    }

    if (card) card.classList.remove('hidden');
    if (searchBox) searchBox.classList.add('hidden');
}

function showDriverSearch(slot) {
    const card = document.getElementById('driverSelectedCard_' + slot);
    const searchBox = document.getElementById('driverSearchBox_' + slot);
    if (card) card.classList.add('hidden');
    if (searchBox) {
        searchBox.classList.remove('hidden');
        const sInput = document.getElementById('driverSearchInput_' + slot);
        if (sInput) {
            sInput.focus();
            openDriverDropdown(slot);
        }
    }
}

function clearDriverSelection(slot) {
    selectDriver(slot, null);
}

// Close driver dropdown on outside click
document.addEventListener('click', function(e) {
    ['primary', 'replacement'].forEach(slot => {
        const container = document.getElementById('slotContainer_' + slot);
        const dropdown = document.getElementById('driverDropdown_' + slot);
        if (container && dropdown && !container.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
});

// ==========================================
// 2. Vehicle Presets (Brands & Models from Parameters)
// ==========================================
function selectBrandPreset(brandName) {
    const brandInput = document.getElementById('vehicleBrandInput');
    const brandSelect = document.getElementById('brandSelectDropdown');
    if (brandInput) brandInput.value = brandName;
    if (brandSelect) brandSelect.value = brandName;
    updateModelsForBrand(brandName);
}

function onBrandSelectChanged(brandName) {
    const brandInput = document.getElementById('vehicleBrandInput');
    if (brandInput) brandInput.value = brandName;
    updateModelsForBrand(brandName);
}

function onBrandInputChanged(brandName) {
    const brandSelect = document.getElementById('brandSelectDropdown');
    if (brandSelect) {
        brandSelect.value = VEHICLE_PRESETS[brandName] ? brandName : '';
    }
    updateModelsForBrand(brandName);
}

function updateModelsForBrand(brandName) {
    const modelSelect = document.getElementById('modelSelectDropdown');
    const modelPills = document.getElementById('modelPillsContainer');
    if (!modelSelect || !modelPills) return;

    const models = VEHICLE_PRESETS[brandName] || [];

    // Populate model dropdown
    let opts = '<option value="">-- اختر الطراز أو اكتب أدناه --</option>';
    models.forEach(m => {
        opts += `<option value="${m}">${m}</option>`;
    });
    modelSelect.innerHTML = opts;

    // Populate model pills
    if (models.length > 0) {
        modelPills.innerHTML = models.map(m => `
            <button type="button" onclick="selectModelPreset('${m}')" 
                    class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 transition">
                ${m}
            </button>
        `).join('');
    } else {
        modelPills.innerHTML = '';
    }
}

function selectModelPreset(modelName) {
    const modelInput = document.getElementById('vehicleModelInput');
    const modelSelect = document.getElementById('modelSelectDropdown');
    if (modelInput) {
        modelInput.value = modelName;
        modelInput.focus();
    }
    if (modelSelect) modelSelect.value = modelName;
}

function onModelSelectChanged(modelName) {
    const modelInput = document.getElementById('vehicleModelInput');
    if (modelInput && modelName) {
        modelInput.value = modelName;
    }
}

// ==========================================
// 3. Quick Preset Modal (Parameters)
// ==========================================
function openQuickPresetModal() {
    const modal = document.getElementById('quickPresetModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.getElementById('quickBrandInput').focus();
    }
}

function closeQuickPresetModal() {
    const modal = document.getElementById('quickPresetModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function saveQuickPreset() {
    const brandInput = document.getElementById('quickBrandInput');
    const modelsInput = document.getElementById('quickModelsInput');
    const brand = brandInput.value.trim();
    const models = modelsInput.value.trim();

    if (!brand) {
        alert('يرجى كتابة اسم الماركة أو الصانع.');
        brandInput.focus();
        return;
    }

    const btn = document.getElementById('savePresetBtn');
    if (btn) btn.disabled = true;

    const formData = new FormData();
    formData.append('brand', brand);
    formData.append('model', models);

    fetch('/dashboard/ajax_vehicle_presets.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (btn) btn.disabled = false;
        if (data.presets) {
            VEHICLE_PRESETS = data.presets;
            
            // Re-render brand select options
            const brandSelect = document.getElementById('brandSelectDropdown');
            if (brandSelect) {
                let html = '<option value="">-- اختر الصانع من الإعدادات أو اكتب أدناه --</option>';
                Object.keys(VEHICLE_PRESETS).forEach(b => {
                    html += `<option value="${b}">${b}</option>`;
                });
                brandSelect.innerHTML = html;
            }

            // Auto select the new brand & model
            selectBrandPreset(brand);
            if (models) {
                const firstModel = models.split(',')[0].trim();
                selectModelPreset(firstModel);
            }

            closeQuickPresetModal();
            brandInput.value = '';
            modelsInput.value = '';
            alert(`✓ تم حفظ ماركة "${brand}" بنجاح في الإعدادات واعتمادها بالنموذج!`);
        } else if (data.error) {
            alert(data.error);
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        alert('حدث خطأ أثناء حفظ الإعدادات، يرجى المحاولة لاحقاً.');
    });
}

// ==========================================
// 4. Equipment Actions: GPL & Taximeter
// ==========================================
function setWithoutGpl() {
    const expiryInput = document.getElementById('gplExpiryInput');
    const textInput = document.getElementById('gplTextInput');
    if (expiryInput) expiryInput.value = '';
    if (textInput) {
        textInput.value = 'بدون GPL';
        textInput.classList.add('ring-2', 'ring-amber-500/50');
        setTimeout(() => textInput.classList.remove('ring-2', 'ring-amber-500/50'), 1200);
    }
}

function setWithoutTaximeter() {
    const expiryInput = document.getElementById('sacExpiryInput');
    const textInput = document.getElementById('sacTextInput');
    if (expiryInput) expiryInput.value = '';
    if (textInput) {
        textInput.value = 'بدون عداد';
        textInput.classList.add('ring-2', 'ring-blue-500/50');
        setTimeout(() => textInput.classList.remove('ring-2', 'ring-blue-500/50'), 1200);
    }
}

// On load: if brand exists, populate its models
document.addEventListener('DOMContentLoaded', function() {
    const currentBrand = document.getElementById('vehicleBrandInput')?.value;
    if (currentBrand) {
        updateModelsForBrand(currentBrand);
        const currentModel = document.getElementById('vehicleModelInput')?.value;
        if (currentModel) {
            const modelSelect = document.getElementById('modelSelectDropdown');
            if (modelSelect) modelSelect.value = currentModel;
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
