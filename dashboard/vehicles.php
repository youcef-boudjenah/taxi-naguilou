<?php
// dashboard/vehicles.php - Full Fleet & Vehicle Management
require_once __DIR__ . '/../includes/auth.php';
require_login();
$user = current_user();

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

if (!function_exists('parse_algerian_plate')) {
    function parse_algerian_plate(?string $raw): array {
        $raw = trim($raw ?? '');
        if (empty($raw)) {
            return ['', '', ''];
        }
        $parts = preg_split('/[\/\-\s]+/u', $raw);
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        if (count($parts) === 3) {
            if (strlen($parts[0]) <= 2 && strlen($parts[2]) > 2) {
                return [$parts[0], $parts[1], $parts[2]];
            } elseif (strlen($parts[2]) <= 2 && strlen($parts[0]) > 2) {
                return [$parts[2], $parts[1], $parts[0]];
            }
            return [$parts[0], $parts[1], $parts[2]];
        } elseif (count($parts) === 2) {
            return [$parts[0], $parts[1], ''];
        } elseif (count($parts) === 1) {
            return ['', '', $parts[0]];
        }
        return ['', '', ''];
    }
}

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
            soft_delete_entity($pdo, 'vehicles', $delId, 'حذف ونقل لسلة المهملات');
            set_flash('success', 'تم نقل المركبة بنجاح إلى سلة المهملات والأرشيف. يمكنك استرجاعها في أي وقت من قسم سلة المهملات.');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر نقل المركبة للأرشيف: ' . $e->getMessage());
        }
        redirect('/dashboard/vehicles.php');
    }

    if ($actionType === 'restore') {
        if (!is_admin() && !is_owner()) {
            set_flash('error', 'عذراً، فقط إدارة المنصة ومالك الأسطول مخولان باسترجاع المركبات.');
            redirect('/dashboard/vehicles.php');
        }
        $resId = intval($_POST['vehicle_id'] ?? 0);
        try {
            restore_entity($pdo, 'vehicles', $resId);
            set_flash('success', 'تم استرجاع المركبة وإعادتها إلى الأسطول النشط بنجاح.');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر استرجاع المركبة: ' . $e->getMessage());
        }
        $redirectUrl = (isset($_GET['filter']) && $_GET['filter'] === 'trash') ? '/dashboard/vehicles.php?filter=trash' : '/dashboard/vehicles.php';
        redirect($redirectUrl);
    }

    if ($actionType === 'force_delete') {
        if (!is_admin()) {
            set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بالحذف النهائي.');
            redirect('/dashboard/vehicles.php?filter=trash');
        }
        $delId = intval($_POST['vehicle_id'] ?? 0);
        try {
            permanent_delete_entity($pdo, 'vehicles', $delId);
            set_flash('success', 'تم حذف المركبة نهائياً من قاعدة البيانات.');
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر الحذف النهائي لوجود سجلات رسمية ملزمة مرتبطة بها.');
        }
        redirect('/dashboard/vehicles.php?filter=trash');
    }

    if ($actionType === 'toggle_status') {
        if (!is_admin() && !is_owner()) {
            set_flash('error', 'عذراً، فقط إدارة المنصة ومالك الأسطول مخولان بتغيير حالة المركبة.');
            redirect('/dashboard/vehicles.php');
        }
        $vId = intval($_POST['vehicle_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['active', 'suspended', 'in_maintenance']) ? $_POST['new_status'] : 'active';
        $reason = trim($_POST['suspended_reason'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE vehicles SET status = ?, suspended_reason = ? WHERE id = ?");
            $stmt->execute([$newStatus, ($newStatus === 'suspended' ? $reason : null), $vId]);
            $statusLabel = ($newStatus === 'active') ? 'تنشيط' : ($newStatus === 'suspended' ? 'تعليق' : 'وضع في الصيانة');
            log_audit($pdo, 'vehicle_status_changed', 'vehicles', $vId, "$statusLabel المركبة رقم $vId" . ($reason ? ": $reason" : ''));
            set_flash('success', "تم {$statusLabel} المركبة بنجاح");
        } catch (\Throwable $e) {
            set_flash('error', 'تعذر تغيير حالة المركبة: ' . $e->getMessage());
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
    $rawPlate = trim($_POST['plate_number'] ?? '');
    $pWilaya = trim($_POST['plate_wilaya'] ?? '');
    $pYear = trim($_POST['plate_year'] ?? '');
    $pSerial = trim($_POST['plate_serial'] ?? '');
    if ($pWilaya !== '' || $pYear !== '' || $pSerial !== '') {
        $cleanW = preg_replace('/\D/', '', $pWilaya);
        $cleanY = preg_replace('/\D/', '', $pYear);
        $cleanS = preg_replace('/\D/', '', $pSerial);
        if ($cleanW !== '' && $cleanY !== '' && $cleanS !== '') {
            $plateNumber = "{$cleanW}-{$cleanY}-{$cleanS}";
        } else {
            $plateParts = array_filter([$cleanW, $cleanY, $cleanS], fn($v) => $v !== '');
            $plateNumber = implode('-', $plateParts);
        }
    } else {
        $plateNumber = $rawPlate;
    }
    // Absolute ban on '/' in matricule: replace any '/' with '-'
    $plateNumber = str_replace('/', '-', trim($plateNumber));
    $color = trim($_POST['color'] ?? 'Jaune');
    $rawDoor = trim($_POST['door_number'] ?? '');
    if ($rawDoor !== '') {
        $cleanDoor = preg_replace('/^\(?0?2\/22\)?[-–—\s]*/u', '', $rawDoor);
        $doorNumber = '(02/22)-' . trim($cleanDoor);
    } else {
        $doorNumber = '(02/22)-';
    }
    $driverId = !empty($_POST['current_driver_id']) ? intval($_POST['current_driver_id']) : null;
    $replacementDriverId = !empty($_POST['replacement_driver_id']) ? intval($_POST['replacement_driver_id']) : null;
    $insuranceExpiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $insuranceCompany = trim($_POST['insurance_company'] ?? 'SAA');
    $tcExpiry = !empty($_POST['technical_control_expiry']) ? $_POST['technical_control_expiry'] : null;
    $extExpiry = !empty($_POST['extinguisher_expiry']) ? $_POST['extinguisher_expiry'] : null;
    
    // حالة المركبة: نشطة أو معلقة
    $status = in_array($_POST['status'] ?? '', ['active', 'suspended', 'in_maintenance']) ? $_POST['status'] : 'active';
    $suspendedReason = ($status === 'suspended') ? trim($_POST['suspended_reason'] ?? '') : null;

    // GPL: خيارين فقط (مع / بدون)
    $gplMode = $_POST['gpl_mode'] ?? (!empty($_POST['gpl_expiry']) ? 'with' : (($_POST['gpl_text'] ?? '') === 'بدون GPL' ? 'without' : 'with'));
    if ($gplMode === 'without' || ($_POST['gpl_text'] ?? '') === 'بدون GPL') {
        $gplExpiry = null;
        $gplText = 'بدون GPL';
    } else {
        $gplExpiry = !empty($_POST['gpl_expiry']) ? $_POST['gpl_expiry'] : null;
        $gplText = null;
    }

    // عداد التاكسي: خيارين فقط (مع / بدون)
    $sacMode = $_POST['taximeter_mode'] ?? (!empty($_POST['saccuriometre_expiry']) ? 'with' : (($_POST['saccuriometre_text'] ?? '') === 'بدون عداد' ? 'without' : 'with'));
    if ($sacMode === 'without' || ($_POST['saccuriometre_text'] ?? '') === 'بدون عداد') {
        $sacExpiry = null;
        $sacText = 'بدون عداد';
    } else {
        $sacExpiry = !empty($_POST['saccuriometre_expiry']) ? $_POST['saccuriometre_expiry'] : null;
        $sacText = null;
    }

    if ($actionType === 'create') {
        try {
            $vCols = [];
            try {
                $vCols = $pdo->query("SHOW COLUMNS FROM `vehicles`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (\Throwable $e) {}
            $vColMap = array_flip($vCols);

            $qrToken = 'TN-V' . bin2hex(random_bytes(6));

            $insertData = [
                'owner_id' => $user['id'],
                'current_driver_id' => $driverId,
                'brand' => $brand,
                'model' => $model,
                'plate_number' => $plateNumber,
                'color' => $color,
                'door_number' => $doorNumber,
                'status' => $status
            ];
            if (isset($vColMap['suspended_reason'])) $insertData['suspended_reason'] = $suspendedReason;
            if (isset($vColMap['qr_code'])) $insertData['qr_code'] = $qrToken;
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
                'current_driver_id' => $driverId,
                'status' => $status
            ];
            if (isset($vColMap['suspended_reason'])) $updateData['suspended_reason'] = $suspendedReason;
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

            // Ensure vehicle has qr_code token
            try {
                $stmtChkQr = $pdo->prepare("SELECT qr_code FROM vehicles WHERE id = ?");
                $stmtChkQr->execute([$vId]);
                $curQr = $stmtChkQr->fetchColumn();
                if (empty($curQr)) {
                    $newQr = 'TN-V' . str_pad($vId, 3, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(6));
                    $pdo->prepare("UPDATE vehicles SET qr_code = ? WHERE id = ?")->execute([$newQr, $vId]);
                }
            } catch (\Throwable $ex) {}

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

// Stats for Quick KPI Strip & Alerts Breakdown
$totalVehiclesCount = 0;
$activeVehiclesCount = 0;
$suspendedVehiclesCount = 0;
$assignedVehiclesCount = 0;
$reliefVehiclesCount = 0;
$expiringDocsCount = 0;
$expiringTcCount = 0;
$expiringInsCount = 0;
$expiringExtCount = 0;
$expiringGplCount = 0;
$expiringSacCount = 0;
$conformityCount = 0;
$vehicles = [];

$trashVehiclesCount = 0;

if ($pdo) {
    $hasVehDeletedAt = false;
    try {
        $colV = $pdo->query("SHOW COLUMNS FROM `vehicles` LIKE 'deleted_at'")->fetch();
        $hasVehDeletedAt = !empty($colV);
        if (!$hasVehDeletedAt) {
            try {
                $pdo->exec("ALTER TABLE `vehicles` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL, ADD INDEX `idx_vehicles_deleted_at` (`deleted_at`)");
                $hasVehDeletedAt = true;
            } catch (\Throwable $eAlter) {}
        }
    } catch (\Throwable $e) {}

    $vDelCond = $hasVehDeletedAt ? " AND deleted_at IS NULL" : "";
    $vWhereDel = $hasVehDeletedAt ? " WHERE deleted_at IS NULL" : "";
    $vDelAlias = $hasVehDeletedAt ? " AND v.deleted_at IS NULL" : "";

    try {
        $totalVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles {$vWhereDel}")->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $trashVehiclesCount = $hasVehDeletedAt ? (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NOT NULL")->fetchColumn() : 0;
    } catch (\Throwable $e) {}

    try {
        $activeVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE (status = 'active' OR status IS NULL) {$vDelCond}")->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $suspendedVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'suspended' {$vDelCond}")->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $assignedVehiclesCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE current_driver_id IS NOT NULL {$vDelCond}")->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $reliefVehiclesCount = (int)$pdo->query("
            SELECT COUNT(DISTINCT v.id) FROM vehicles v
            LEFT JOIN leaves l ON (l.vehicle_id = v.id AND l.status = 'approved' AND l.end_date >= CURDATE())
            WHERE (v.replacement_driver_id IS NOT NULL OR l.replacement_driver_id IS NOT NULL) {$vDelAlias}
        ")->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $alertRow = $pdo->query("
            SELECT 
                COUNT(DISTINCT CASE WHEN (
                    (technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND technical_control_expiry IS NOT NULL)
                    OR (insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND insurance_expiry IS NOT NULL)
                    OR (extinguisher_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND extinguisher_expiry IS NOT NULL)
                    OR (gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND gpl_expiry IS NOT NULL)
                    OR (saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND saccuriometre_expiry IS NOT NULL)
                ) THEN id END) as total_alerts,
                COUNT(DISTINCT CASE WHEN (
                    (technical_control_expiry < CURDATE() AND technical_control_expiry IS NOT NULL)
                    OR (insurance_expiry < CURDATE() AND insurance_expiry IS NOT NULL)
                    OR (extinguisher_expiry < CURDATE() AND extinguisher_expiry IS NOT NULL)
                    OR (gpl_expiry < CURDATE() AND gpl_expiry IS NOT NULL)
                    OR (saccuriometre_expiry < CURDATE() AND saccuriometre_expiry IS NOT NULL)
                ) THEN id END) as total_expired,
                COUNT(DISTINCT CASE WHEN (
                    (technical_control_expiry >= CURDATE() AND technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                    OR (insurance_expiry >= CURDATE() AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                    OR (extinguisher_expiry >= CURDATE() AND extinguisher_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                    OR (gpl_expiry >= CURDATE() AND gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                    OR (saccuriometre_expiry >= CURDATE() AND saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                ) THEN id END) as total_soon,
                COUNT(DISTINCT CASE WHEN (
                    (technical_control_expiry >= CURDATE() AND technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                    OR (insurance_expiry >= CURDATE() AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                    OR (extinguisher_expiry >= CURDATE() AND extinguisher_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                    OR (gpl_expiry >= CURDATE() AND gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                    OR (saccuriometre_expiry >= CURDATE() AND saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                ) THEN id END) as total_critical,

                -- Technical Control
                COUNT(DISTINCT CASE WHEN technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND technical_control_expiry IS NOT NULL THEN id END) as tc_total,
                COUNT(DISTINCT CASE WHEN technical_control_expiry < CURDATE() AND technical_control_expiry IS NOT NULL THEN id END) as tc_expired,
                COUNT(DISTINCT CASE WHEN technical_control_expiry >= CURDATE() AND technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN id END) as tc_soon,

                -- Insurance
                COUNT(DISTINCT CASE WHEN insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND insurance_expiry IS NOT NULL THEN id END) as ins_total,
                COUNT(DISTINCT CASE WHEN insurance_expiry < CURDATE() AND insurance_expiry IS NOT NULL THEN id END) as ins_expired,
                COUNT(DISTINCT CASE WHEN insurance_expiry >= CURDATE() AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN id END) as ins_soon,

                -- Extinguisher
                COUNT(DISTINCT CASE WHEN extinguisher_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND extinguisher_expiry IS NOT NULL THEN id END) as ext_total,
                COUNT(DISTINCT CASE WHEN extinguisher_expiry < CURDATE() AND extinguisher_expiry IS NOT NULL THEN id END) as ext_expired,
                COUNT(DISTINCT CASE WHEN extinguisher_expiry >= CURDATE() AND extinguisher_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN id END) as ext_soon,

                -- GPL
                COUNT(DISTINCT CASE WHEN gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND gpl_expiry IS NOT NULL THEN id END) as gpl_total,
                COUNT(DISTINCT CASE WHEN gpl_expiry < CURDATE() AND gpl_expiry IS NOT NULL THEN id END) as gpl_expired,
                COUNT(DISTINCT CASE WHEN gpl_expiry >= CURDATE() AND gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN id END) as gpl_soon,

                -- Taximeter
                COUNT(DISTINCT CASE WHEN saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND saccuriometre_expiry IS NOT NULL THEN id END) as sac_total,
                COUNT(DISTINCT CASE WHEN saccuriometre_expiry < CURDATE() AND saccuriometre_expiry IS NOT NULL THEN id END) as sac_expired,
                COUNT(DISTINCT CASE WHEN saccuriometre_expiry >= CURDATE() AND saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN id END) as sac_soon
            FROM vehicles
            WHERE deleted_at IS NULL
        ")->fetch(PDO::FETCH_ASSOC);

        $expiringDocsCount      = (int)($alertRow['total_alerts'] ?? 0);
        $totalExpiredDocsCount  = (int)($alertRow['total_expired'] ?? 0);
        $totalSoonDocsCount     = (int)($alertRow['total_soon'] ?? 0);
        $totalCriticalDocsCount = (int)($alertRow['total_critical'] ?? 0);

        $expiringTcCount        = (int)($alertRow['tc_total'] ?? 0);
        $expiredTcCount         = (int)($alertRow['tc_expired'] ?? 0);
        $soonTcCount            = (int)($alertRow['tc_soon'] ?? 0);

        $expiringInsCount       = (int)($alertRow['ins_total'] ?? 0);
        $expiredInsCount        = (int)($alertRow['ins_expired'] ?? 0);
        $soonInsCount           = (int)($alertRow['ins_soon'] ?? 0);

        $expiringExtCount       = (int)($alertRow['ext_total'] ?? 0);
        $expiredExtCount        = (int)($alertRow['ext_expired'] ?? 0);
        $soonExtCount           = (int)($alertRow['ext_soon'] ?? 0);

        $expiringGplCount       = (int)($alertRow['gpl_total'] ?? 0);
        $expiredGplCount        = (int)($alertRow['gpl_expired'] ?? 0);
        $soonGplCount           = (int)($alertRow['gpl_soon'] ?? 0);

        $expiringSacCount       = (int)($alertRow['sac_total'] ?? 0);
        $expiredSacCount        = (int)($alertRow['sac_expired'] ?? 0);
        $soonSacCount           = (int)($alertRow['sac_soon'] ?? 0);
    } catch (\Throwable $e) {}

    $conformityCount = max(0, $totalVehiclesCount - $expiringDocsCount);

    // Fetch vehicles with filters
    $search = trim($_GET['search'] ?? '');
    $filter = $_GET['filter'] ?? 'all';
    $doc    = $_GET['doc'] ?? 'all';
    $scope  = $_GET['scope'] ?? 'all'; // 'all', 'expired', 'soon', 'critical'

    // Multi-Column Filters: 1.المركبة 2.رقم الباب 3.السائق المكلف 4.السائق البديل 5.المراقبة التقنية 6.التأمين 7.قارورة الإطفاء 8.GPL 9.العداد
    $f_vehicle     = trim($_GET['f_vehicle'] ?? '');
    $f_door        = trim($_GET['f_door'] ?? '');
    $f_driver      = trim($_GET['f_driver'] ?? ''); // 'all', 'assigned', 'unassigned', or custom search text
    $f_replacement = trim($_GET['f_replacement'] ?? ''); // 'all', 'has_relief', 'no_relief', or custom search text
    $f_tc          = trim($_GET['f_tc'] ?? ''); // 'all', 'valid', 'soon', 'expired'
    $f_ins         = trim($_GET['f_ins'] ?? ''); // 'all', 'valid', 'soon', 'expired'
    $f_ext         = trim($_GET['f_ext'] ?? ''); // 'all', 'valid', 'soon', 'expired'
    $f_gpl         = trim($_GET['f_gpl'] ?? ''); // 'all', 'valid', 'soon', 'expired', 'none'
    $f_sac         = trim($_GET['f_sac'] ?? ''); // 'all', 'valid', 'expired', 'none'
    $col_filter    = trim($_GET['col_filter'] ?? ''); // quick column selector

    $activeColFiltersCount = 0;
    if (!empty($f_vehicle)) $activeColFiltersCount++;
    if (!empty($f_door)) $activeColFiltersCount++;
    if (!empty($f_driver) && $f_driver !== 'all') $activeColFiltersCount++;
    if (!empty($f_replacement) && $f_replacement !== 'all') $activeColFiltersCount++;
    if (!empty($f_tc) && $f_tc !== 'all') $activeColFiltersCount++;
    if (!empty($f_ins) && $f_ins !== 'all') $activeColFiltersCount++;
    if (!empty($f_ext) && $f_ext !== 'all') $activeColFiltersCount++;
    if (!empty($f_gpl) && $f_gpl !== 'all') $activeColFiltersCount++;
    if (!empty($f_sac) && $f_sac !== 'all') $activeColFiltersCount++;
    if (!empty($col_filter) && $col_filter !== 'all') $activeColFiltersCount++;


    // Also support composite exp_filter parameter (e.g. 'all_expired', 'all_soon', 'tc_expired', etc.)
    if (!empty($_GET['exp_filter'])) {
        $expParam = trim($_GET['exp_filter']);
        if ($expParam === 'all_alerts') {
            $filter = 'expiring'; $doc = 'all'; $scope = 'all';
        } elseif ($expParam === 'all_expired') {
            $filter = 'expiring'; $doc = 'all'; $scope = 'expired';
        } elseif ($expParam === 'all_soon') {
            $filter = 'expiring'; $doc = 'all'; $scope = 'soon';
        } elseif ($expParam === 'all_critical') {
            $filter = 'expiring'; $doc = 'all'; $scope = 'critical';
        } elseif (str_starts_with($expParam, 'tc_')) {
            $filter = 'expiring'; $doc = 'technical'; $scope = substr($expParam, 3);
        } elseif (str_starts_with($expParam, 'ins_')) {
            $filter = 'expiring'; $doc = 'insurance'; $scope = substr($expParam, 4);
        } elseif (str_starts_with($expParam, 'ext_')) {
            $filter = 'expiring'; $doc = 'extinguisher'; $scope = substr($expParam, 4);
        } elseif (str_starts_with($expParam, 'gpl_')) {
            $filter = 'expiring'; $doc = 'gpl'; $scope = substr($expParam, 4);
        } elseif (str_starts_with($expParam, 'sac_')) {
            $filter = 'expiring'; $doc = 'taximeter'; $scope = substr($expParam, 4);
        }
    }

    // Support legacy filter names if directly passed
    if (in_array($filter, ['technical', 'insurance', 'extinguisher', 'gpl', 'taximeter'])) {
        $doc = $filter;
        $filter = 'expiring';
    } elseif ($filter === 'expired') {
        $filter = 'expiring';
        $scope = 'expired';
    } elseif ($filter === 'expiring_soon') {
        $filter = 'expiring';
        $scope = 'soon';
    }

    // Determine canonical currentExpFilter for syncing select dropdown
    $currentExpFilter = 'all_alerts';
    if ($filter === 'expiring') {
        if ($doc === 'all') {
            if ($scope === 'expired') $currentExpFilter = 'all_expired';
            elseif ($scope === 'soon') $currentExpFilter = 'all_soon';
            elseif ($scope === 'critical') $currentExpFilter = 'all_critical';
            else $currentExpFilter = 'all_alerts';
        } else {
            $docPrefixMap = [
                'technical'    => 'tc',
                'insurance'    => 'ins',
                'extinguisher' => 'ext',
                'gpl'          => 'gpl',
                'taximeter'    => 'sac'
            ];
            $prefix = $docPrefixMap[$doc] ?? 'tc';
            $currentExpFilter = "{$prefix}_{$scope}";
        }

        $docNames = [
            'all'          => 'كل الوثائق والتجهيزات',
            'technical'    => 'المراقبة التقنية',
            'insurance'    => 'التأمين الشامل',
            'extinguisher' => 'قارورة الإطفاء',
            'gpl'          => 'فحص سيرغاز GPL',
            'taximeter'    => 'ختم عداد التاكسي'
        ];
        $scopeNames = [
            'all'      => 'المنتهية والقريبة',
            'expired'  => '🔴 المنتهية الصلاحية فقط',
            'soon'     => '🟡 قريبة الانتهاء (< 30 يوماً)',
            'critical' => '🚨 حرجة جداً (< 7 أيام)'
        ];
        $docLabel = $docNames[$doc] ?? 'الوثائق';
        $scopeLabel = $scopeNames[$scope] ?? 'المنتهية والقريبة';
        $filterDescription = ($doc === 'all') ? $scopeLabel : ($docLabel . ' — ' . $scopeLabel);
    }


    $hasReplacementCol = false;
    try {
        $pdo->query("SELECT replacement_driver_id FROM vehicles LIMIT 0");
        $hasReplacementCol = true;
    } catch (\Throwable $e) {}

    $joinRepl = "
        LEFT JOIN users r ON v.replacement_driver_id = r.id
        LEFT JOIN (
            SELECT l.* FROM leaves l
            INNER JOIN (
                SELECT vehicle_id, MAX(id) as max_id
                FROM leaves
                WHERE status = 'approved' AND end_date >= CURDATE() AND vehicle_id IS NOT NULL
                GROUP BY vehicle_id
            ) latest_l ON l.id = latest_l.max_id
        ) act_l ON act_l.vehicle_id = v.id
        LEFT JOIN users r_leave ON act_l.replacement_driver_id = r_leave.id
    ";

    $joinRatings = "
        LEFT JOIN (
            SELECT vehicle_id, COUNT(*) as ratings_count, ROUND(AVG(rating), 1) as avg_rating
            FROM driver_ratings
            GROUP BY vehicle_id
        ) dr ON dr.vehicle_id = v.id
    ";

    $selectRepl = "
        COALESCE(r.full_name, r_leave.full_name) as replacement_driver_name,
        COALESCE(r.phone, r_leave.phone) as replacement_driver_phone,
        COALESCE(v.replacement_driver_id, act_l.replacement_driver_id) as effective_replacement_driver_id,
        act_l.start_date as leave_start_date,
        act_l.end_date as leave_end_date,
    ";

    $query = "SELECT v.*, u.full_name as driver_name, u.phone as driver_phone, dr.ratings_count, dr.avg_rating, $selectRepl v.id FROM vehicles v LEFT JOIN users u ON v.current_driver_id = u.id $joinRepl $joinRatings WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $hasDoorCol = false;
        try {
            $pdo->query("SELECT door_number FROM vehicles LIMIT 0");
            $hasDoorCol = true;
        } catch (\Throwable $e) {}

        $doorCondition = $hasDoorCol ? " OR v.door_number LIKE ?" : "";
        $query .= " AND (v.plate_number LIKE ? OR v.brand LIKE ? OR v.model LIKE ? OR u.full_name LIKE ? OR r.full_name LIKE ? OR r_leave.full_name LIKE ?$doorCondition)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        if ($hasDoorCol) {
            $params[] = "%$search%";
        }
    }

    if ($filter === 'trash') {
        $query .= $hasVehDeletedAt ? " AND v.deleted_at IS NOT NULL" : " AND 1=0";
    } else {
        if ($hasVehDeletedAt) {
            $query .= " AND v.deleted_at IS NULL";
        }
        if ($filter === 'active') {
            $query .= " AND (v.status = 'active' OR v.status IS NULL)";
        } elseif ($filter === 'suspended') {
            $query .= " AND v.status = 'suspended'";
        } elseif ($filter === 'expiring') {
            $buildDocSql = function(string $column, string $sc): string {
                switch ($sc) {
                    case 'expired':
                        return "(v.$column < CURDATE() AND v.$column IS NOT NULL)";
                    case 'soon':
                        return "(v.$column >= CURDATE() AND v.$column <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.$column IS NOT NULL)";
                    case 'critical':
                        return "(v.$column >= CURDATE() AND v.$column <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND v.$column IS NOT NULL)";
                    case 'all':
                    default:
                        return "(v.$column <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.$column IS NOT NULL)";
                }
            };

            if ($doc === 'technical') {
                $query .= " AND " . $buildDocSql('technical_control_expiry', $scope);
            } elseif ($doc === 'insurance') {
                $query .= " AND " . $buildDocSql('insurance_expiry', $scope);
            } elseif ($doc === 'extinguisher') {
                $query .= " AND " . $buildDocSql('extinguisher_expiry', $scope);
            } elseif ($doc === 'gpl') {
                $query .= " AND " . $buildDocSql('gpl_expiry', $scope);
            } elseif ($doc === 'taximeter') {
                $query .= " AND " . $buildDocSql('saccuriometre_expiry', $scope);
            } else {
                $subConds = [
                    $buildDocSql('technical_control_expiry', $scope),
                    $buildDocSql('insurance_expiry', $scope),
                    $buildDocSql('extinguisher_expiry', $scope),
                    $buildDocSql('gpl_expiry', $scope),
                    $buildDocSql('saccuriometre_expiry', $scope)
                ];
                $query .= " AND (" . implode(" OR ", $subConds) . ")";
            }
        } elseif ($filter === 'unassigned') {
            $query .= " AND v.current_driver_id IS NULL";
        } elseif ($filter === 'relief') {
            $query .= " AND (v.replacement_driver_id IS NOT NULL OR act_l.replacement_driver_id IS NOT NULL)";
        }
    }

    // 1. تصفية عمود المركبة (الماركة / الموديل / اللوحة)
    if (!empty($f_vehicle)) {
        $cleanPlateDash = str_replace('/', '-', $f_vehicle);
        $cleanPlateSlash = str_replace('-', '/', $f_vehicle);
        $query .= " AND (v.brand LIKE ? OR v.model LIKE ? OR v.plate_number LIKE ? OR v.plate_number LIKE ? OR v.plate_number LIKE ?)";
        $params[] = "%$f_vehicle%";
        $params[] = "%$f_vehicle%";
        $params[] = "%$f_vehicle%";
        $params[] = "%$cleanPlateDash%";
        $params[] = "%$cleanPlateSlash%";
    }

    // 2. تصفية عمود رقم الباب
    if (!empty($f_door)) {
        $query .= " AND v.door_number LIKE ?";
        $params[] = "%$f_door%";
    }

    // 3. تصفية عمود السائق المكلف
    if (!empty($f_driver) && $f_driver !== 'all') {
        if ($f_driver === 'assigned') {
            $query .= " AND v.current_driver_id IS NOT NULL";
        } elseif ($f_driver === 'unassigned') {
            $query .= " AND v.current_driver_id IS NULL";
        } else {
            $query .= " AND u.full_name LIKE ?";
            $params[] = "%$f_driver%";
        }
    }

    // 4. تصفية عمود السائق البديل
    if (!empty($f_replacement) && $f_replacement !== 'all') {
        if ($f_replacement === 'has_relief') {
            $query .= " AND (v.replacement_driver_id IS NOT NULL OR act_l.replacement_driver_id IS NOT NULL)";
        } elseif ($f_replacement === 'no_relief') {
            $query .= " AND (v.replacement_driver_id IS NULL AND act_l.replacement_driver_id IS NULL)";
        } else {
            $query .= " AND (r.full_name LIKE ? OR r_leave.full_name LIKE ?)";
            $params[] = "%$f_replacement%";
            $params[] = "%$f_replacement%";
        }
    }

    // 5. تصفية عمود المراقبة التقنية
    if (!empty($f_tc) && $f_tc !== 'all') {
        if ($f_tc === 'expired') {
            $query .= " AND (v.technical_control_expiry < CURDATE() AND v.technical_control_expiry IS NOT NULL)";
        } elseif ($f_tc === 'soon') {
            $query .= " AND (v.technical_control_expiry >= CURDATE() AND v.technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.technical_control_expiry IS NOT NULL)";
        } elseif ($f_tc === 'valid') {
            $query .= " AND (v.technical_control_expiry > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.technical_control_expiry IS NOT NULL)";
        }
    }

    // 6. تصفية عمود التأمين
    if (!empty($f_ins) && $f_ins !== 'all') {
        if ($f_ins === 'expired') {
            $query .= " AND (v.insurance_expiry < CURDATE() AND v.insurance_expiry IS NOT NULL)";
        } elseif ($f_ins === 'soon') {
            $query .= " AND (v.insurance_expiry >= CURDATE() AND v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.insurance_expiry IS NOT NULL)";
        } elseif ($f_ins === 'valid') {
            $query .= " AND (v.insurance_expiry > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.insurance_expiry IS NOT NULL)";
        }
    }

    // 7. تصفية عمود قارورة الإطفاء
    if (!empty($f_ext) && $f_ext !== 'all') {
        if ($f_ext === 'expired') {
            $query .= " AND (v.extinguisher_expiry < CURDATE() AND v.extinguisher_expiry IS NOT NULL)";
        } elseif ($f_ext === 'soon') {
            $query .= " AND (v.extinguisher_expiry >= CURDATE() AND v.extinguisher_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.extinguisher_expiry IS NOT NULL)";
        } elseif ($f_ext === 'valid') {
            $query .= " AND (v.extinguisher_expiry > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.extinguisher_expiry IS NOT NULL)";
        }
    }

    // 8. تصفية عمود سيرغاز GPL
    if (!empty($f_gpl) && $f_gpl !== 'all') {
        if ($f_gpl === 'expired') {
            $query .= " AND (v.gpl_expiry < CURDATE() AND v.gpl_expiry IS NOT NULL)";
        } elseif ($f_gpl === 'soon') {
            $query .= " AND (v.gpl_expiry >= CURDATE() AND v.gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.gpl_expiry IS NOT NULL)";
        } elseif ($f_gpl === 'valid') {
            $query .= " AND (v.gpl_expiry > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.gpl_expiry IS NOT NULL)";
        } elseif ($f_gpl === 'none') {
            $query .= " AND v.gpl_expiry IS NULL";
        }
    }

    // 9. تصفية عمود عداد التاكسي
    if (!empty($f_sac) && $f_sac !== 'all') {
        if ($f_sac === 'expired') {
            $query .= " AND (v.saccuriometre_expiry < CURDATE() AND v.saccuriometre_expiry IS NOT NULL)";
        } elseif ($f_sac === 'valid') {
            $query .= " AND (v.saccuriometre_expiry >= CURDATE() AND v.saccuriometre_expiry IS NOT NULL)";
        } elseif ($f_sac === 'none') {
            $query .= " AND v.saccuriometre_expiry IS NULL";
        }
    }

    // تصفية سريعة بحسب العمود المختار من القائمة المنسدلة
    if (!empty($col_filter) && $col_filter !== 'all') {
        if ($col_filter === 'driver') {
            $query .= " AND v.current_driver_id IS NOT NULL";
        } elseif ($col_filter === 'relief') {
            $query .= " AND (v.replacement_driver_id IS NOT NULL OR act_l.replacement_driver_id IS NOT NULL)";
        } elseif ($col_filter === 'door') {
            $query .= " AND (v.door_number IS NOT NULL AND v.door_number != '')";
        } elseif ($col_filter === 'technical') {
            $query .= " AND (v.technical_control_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.technical_control_expiry IS NOT NULL)";
        } elseif ($col_filter === 'insurance') {
            $query .= " AND (v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.insurance_expiry IS NOT NULL)";
        } elseif ($col_filter === 'extinguisher') {
            $query .= " AND (v.extinguisher_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.extinguisher_expiry IS NOT NULL)";
        } elseif ($col_filter === 'gpl') {
            $query .= " AND (v.gpl_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.gpl_expiry IS NOT NULL)";
        } elseif ($col_filter === 'taximeter') {
            $query .= " AND (v.saccuriometre_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND v.saccuriometre_expiry IS NOT NULL)";
        }
    }


    if (is_driver()) {
        $dId = intval($_SESSION['user_id']);
        $query .= " AND (v.current_driver_id = ? OR v.replacement_driver_id = ? OR act_l.replacement_driver_id = ?)";
        $params[] = $dId;
        $params[] = $dId;
        $params[] = $dId;
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
        try {
            $fallbackQuery = str_replace([" AND v.deleted_at IS NULL", " AND v.deleted_at IS NOT NULL"], "", $query);
            $stmtF = $pdo->prepare($fallbackQuery);
            $stmtF->execute($params);
            $vehicles = $stmtF->fetchAll() ?: [];
        } catch (\Throwable $e2) {
            try {
                $vehicles = $pdo->query("SELECT * FROM vehicles ORDER BY id DESC")->fetchAll() ?: [];
            } catch (\Throwable $e3) {
                $vehicles = [];
            }
        }
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
            if (empty($editVehicle['replacement_driver_id'])) {
                try {
                    $stmtL = $pdo->prepare("SELECT replacement_driver_id FROM leaves WHERE vehicle_id = ? AND status = 'approved' AND end_date >= CURDATE() ORDER BY id DESC LIMIT 1");
                    $stmtL->execute([$eId]);
                    $rIdL = $stmtL->fetchColumn();
                    if ($rIdL) {
                        $editVehicle['replacement_driver_id'] = (int)$rIdL;
                    }
                } catch (\Throwable $e) {}
            }
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
            <button onclick="openVehicleModal()" 
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
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3.5">
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

        <div class="bg-white p-4 rounded-xl border border-amber-200/80 bg-amber-50/20 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-amber-900">مركبات بسائق بديل</p>
                <p class="text-xl font-bold text-amber-600 font-num mt-0.5"><?= $reliefVehiclesCount ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center text-amber-700">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
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

    <!-- Dedicated Alerts Strip for Documents & Equipment -->
    <?php if ($expiringDocsCount > 0 || $filter === 'expiring'): ?>
    <div class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-rose-200 dark:border-rose-900/60 shadow-xs space-y-3.5">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 border-b border-rose-100 dark:border-rose-950/60 pb-3">
            <div class="flex items-start sm:items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold text-lg shrink-0">⚠️</span>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm">تنبيهات صلاحية التجهيزات والوثائق الرسمية للأسطول</h3>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-500 text-white font-num"><?= $expiringDocsCount ?> تنبيه إجمالي</span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        يوجد <span class="font-bold text-rose-600 dark:text-rose-400 font-num"><?= $totalExpiredDocsCount ?> مركبة</span> بوثائق منتهية الصلاحية 🔴 و <span class="font-bold text-amber-600 dark:text-amber-400 font-num"><?= $totalSoonDocsCount ?> مركبة</span> قريبة الانتهاء خلال 30 يوماً 🟡
                    </p>
                </div>
            </div>

            <!-- Dropdown Selector: قائمة الاختيار للوثائق المنتهية أو القريبة -->
            <div class="flex items-center gap-2 self-stretch lg:self-auto bg-rose-50/60 dark:bg-rose-950/30 p-2 rounded-xl border border-rose-200/60 dark:border-rose-900/40">
                <label for="expFilterSelect" class="text-xs font-bold text-slate-800 dark:text-slate-200 whitespace-nowrap flex items-center gap-1.5 shrink-0">
                    <span>🔍 القائمة المنسدلة:</span>
                </label>
                <select id="expFilterSelect" onchange="onExpFilterChange(this.value)" 
                        class="w-full sm:w-auto min-w-[260px] bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-bold rounded-lg border border-rose-300 dark:border-rose-700/80 px-3 py-1.5 focus:ring-2 focus:ring-rose-500/30 focus:border-rose-500 shadow-2xs cursor-pointer">
                    <optgroup label="⚡ تصفية عامة حسب حالة الصلاحية">
                        <option value="all_alerts" <?= $currentExpFilter === 'all_alerts' ? 'selected' : '' ?>>🔔 كل التنبيهات (المنتهية والقريبة) — (<?= $expiringDocsCount ?> مركبة)</option>
                        <option value="all_expired" <?= $currentExpFilter === 'all_expired' ? 'selected' : '' ?>>🔴 جميع الوثائق المنتهية الصلاحية فقط — (<?= $totalExpiredDocsCount ?> مركبة)</option>
                        <option value="all_soon" <?= $currentExpFilter === 'all_soon' ? 'selected' : '' ?>>🟡 جميع الوثائق قريبة الانتهاء (&lt; 30 يوماً) — (<?= $totalSoonDocsCount ?> مركبة)</option>
                        <option value="all_critical" <?= $currentExpFilter === 'all_critical' ? 'selected' : '' ?>>🚨 وثائق حرجة جداً (تنتهي خلال &lt; 7 أيام) — (<?= $totalCriticalDocsCount ?>)</option>
                    </optgroup>
                    
                    <optgroup label="📋 المراقبة التقنية (Contrôle Technique)">
                        <option value="tc_all" <?= $currentExpFilter === 'tc_all' ? 'selected' : '' ?>>📋 المراقبة التقنية: كل التنبيهات (<?= $expiringTcCount ?>)</option>
                        <option value="tc_expired" <?= $currentExpFilter === 'tc_expired' ? 'selected' : '' ?>>📋 🔴 المراقبة التقنية: المنتهية الصلاحية فقط (<?= $expiredTcCount ?>)</option>
                        <option value="tc_soon" <?= $currentExpFilter === 'tc_soon' ? 'selected' : '' ?>>📋 🟡 المراقبة التقنية: قريبة الانتهاء (<?= $soonTcCount ?>)</option>
                    </optgroup>

                    <optgroup label="🛡️ التأمين الشامل (Assurance)">
                        <option value="ins_all" <?= $currentExpFilter === 'ins_all' ? 'selected' : '' ?>>🛡️ التأمين الشامل: كل التنبيهات (<?= $expiringInsCount ?>)</option>
                        <option value="ins_expired" <?= $currentExpFilter === 'ins_expired' ? 'selected' : '' ?>>🛡️ 🔴 التأمين الشامل: المنتهي الصلاحية فقط (<?= $expiredInsCount ?>)</option>
                        <option value="ins_soon" <?= $currentExpFilter === 'ins_soon' ? 'selected' : '' ?>>🛡️ 🟡 التأمين الشامل: قريب الانتهاء (<?= $soonInsCount ?>)</option>
                    </optgroup>

                    <optgroup label="🧯 قارورة الإطفاء (Extincteur)">
                        <option value="ext_all" <?= $currentExpFilter === 'ext_all' ? 'selected' : '' ?>>🧯 قارورة الإطفاء: كل التنبيهات (<?= $expiringExtCount ?>)</option>
                        <option value="ext_expired" <?= $currentExpFilter === 'ext_expired' ? 'selected' : '' ?>>🧯 🔴 قارورة الإطفاء: المنتهية الصلاحية فقط (<?= $expiredExtCount ?>)</option>
                        <option value="ext_soon" <?= $currentExpFilter === 'ext_soon' ? 'selected' : '' ?>>🧯 🟡 قارورة الإطفاء: قريبة الانتهاء (<?= $soonExtCount ?>)</option>
                    </optgroup>

                    <optgroup label="⛽ فحص سيرغاز (GPL)">
                        <option value="gpl_all" <?= $currentExpFilter === 'gpl_all' ? 'selected' : '' ?>>⛽ سيرغاز GPL: كل التنبيهات (<?= $expiringGplCount ?>)</option>
                        <option value="gpl_expired" <?= $currentExpFilter === 'gpl_expired' ? 'selected' : '' ?>>⛽ 🔴 سيرغاز GPL: المنتهي فقط (<?= $expiredGplCount ?>)</option>
                        <option value="gpl_soon" <?= $currentExpFilter === 'gpl_soon' ? 'selected' : '' ?>>⛽ 🟡 سيرغاز GPL: قريب الانتهاء (<?= $soonGplCount ?>)</option>
                    </optgroup>

                    <optgroup label="⏱️ شهادة / ختم عداد التاكسي (Taximètre)">
                        <option value="sac_all" <?= $currentExpFilter === 'sac_all' ? 'selected' : '' ?>>⏱️ ختم عداد التاكسي: كل التنبيهات (<?= $expiringSacCount ?>)</option>
                        <option value="sac_expired" <?= $currentExpFilter === 'sac_expired' ? 'selected' : '' ?>>⏱️ 🔴 ختم العداد: المنتهي فقط (<?= $expiredSacCount ?>)</option>
                        <?php if ($soonSacCount > 0): ?>
                        <option value="sac_soon" <?= $currentExpFilter === 'sac_soon' ? 'selected' : '' ?>>⏱️ 🟡 ختم العداد: قريب الانتهاء (<?= $soonSacCount ?>)</option>
                        <?php endif; ?>
                    </optgroup>
                </select>
            </div>
        </div>

        <!-- Quick Access Filters: Status Scope & Documents -->
        <div class="space-y-2 pt-1">
            <!-- 1. أزرار الحالة: منتهية الصلاحية أو قريبة الانتهاء -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 pl-1">الحالة:</span>
                <a href="vehicles.php?exp_filter=all_alerts<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'all') ? 'bg-slate-900 dark:bg-amber-500 text-white dark:text-slate-950 border-slate-900 shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-200' ?>">
                    <span>🔔 كل التنبيهات</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'all') ? 'bg-white/20 text-white dark:text-slate-950 font-black' : 'bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200' ?>"><?= $expiringDocsCount ?></span>
                </a>
                <a href="vehicles.php?exp_filter=all_expired<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'expired') ? 'bg-rose-600 text-white border-rose-600 shadow-xs' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800 hover:bg-rose-100' ?>">
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                    <span>المنتهية الصلاحية فقط</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'expired') ? 'bg-white text-rose-700 font-black' : 'bg-rose-200 dark:bg-rose-900/80 text-rose-900 dark:text-rose-100' ?>"><?= $totalExpiredDocsCount ?></span>
                </a>
                <a href="vehicles.php?exp_filter=all_soon<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'soon') ? 'bg-amber-600 text-white border-amber-600 shadow-xs' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-800 hover:bg-amber-100' ?>">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span>قريبة الانتهاء (&lt; 30 يوماً)</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'soon') ? 'bg-white text-amber-800 font-black' : 'bg-amber-200 dark:bg-amber-900/80 text-amber-950 dark:text-amber-100' ?>"><?= $totalSoonDocsCount ?></span>
                </a>
                <a href="vehicles.php?exp_filter=all_critical<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'critical') ? 'bg-red-700 text-white border-red-700 shadow-xs' : 'bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-300 border-red-200 dark:border-red-900 hover:bg-red-100' ?>">
                    <span>🚨 حرجة (&lt; 7 أيام)</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'critical') ? 'bg-white text-red-700 font-black' : 'bg-red-200 dark:bg-red-900/80 text-red-900 dark:text-red-100' ?>"><?= $totalCriticalDocsCount ?></span>
                </a>
            </div>

            <!-- 2. أزرار نوع الوثيقة والتجهيز -->
            <div class="flex flex-wrap items-center gap-1.5 pt-1 border-t border-slate-100 dark:border-slate-800">
                <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 pl-1">حسب الوثيقة:</span>
                
                <!-- المراقبة التقنية -->
                <a href="vehicles.php?exp_filter=tc_all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'technical') ? 'bg-slate-900 dark:bg-slate-700 text-white border-slate-900 shadow-xs' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100' ?>">
                    <span>📋 المراقبة التقنية:</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num font-bold <?= ($filter === 'expiring' && $doc === 'technical') ? 'bg-white text-slate-900' : 'bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200' ?>"><?= $expiringTcCount ?></span>
                </a>

                <!-- التأمين الشامل -->
                <a href="vehicles.php?exp_filter=ins_all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'insurance') ? 'bg-slate-900 dark:bg-slate-700 text-white border-slate-900 shadow-xs' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100' ?>">
                    <span>🛡️ التأمين:</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num font-bold <?= ($filter === 'expiring' && $doc === 'insurance') ? 'bg-white text-slate-900' : 'bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200' ?>"><?= $expiringInsCount ?></span>
                </a>

                <!-- قارورة الإطفاء -->
                <a href="vehicles.php?exp_filter=ext_all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'extinguisher') ? 'bg-rose-600 text-white border-rose-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100' ?>">
                    <span>🧯 الإطفاء:</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num font-bold <?= ($filter === 'expiring' && $doc === 'extinguisher') ? 'bg-white text-rose-700' : 'bg-rose-100 dark:bg-rose-950/80 text-rose-800 dark:text-rose-300' ?>"><?= $expiringExtCount ?></span>
                </a>

                <!-- سيرغاز GPL -->
                <a href="vehicles.php?exp_filter=gpl_all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'gpl') ? 'bg-amber-600 text-white border-amber-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100' ?>">
                    <span>⛽ سيرغاز GPL:</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num font-bold <?= ($filter === 'expiring' && $doc === 'gpl') ? 'bg-white text-amber-700' : 'bg-amber-100 dark:bg-amber-950/80 text-amber-900 dark:text-amber-300' ?>"><?= $expiringGplCount ?></span>
                </a>

                <!-- عداد التاكسي -->
                <a href="vehicles.php?exp_filter=sac_all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition border <?= ($filter === 'expiring' && $doc === 'taximeter') ? 'bg-blue-600 text-white border-blue-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100' ?>">
                    <span>⏱️ عداد التاكسي:</span>
                    <span class="px-1.5 py-0.2 rounded-md text-[10px] font-num font-bold <?= ($filter === 'expiring' && $doc === 'taximeter') ? 'bg-white text-blue-700' : 'bg-blue-100 dark:bg-blue-950/80 text-blue-900 dark:text-blue-300' ?>"><?= $expiringSacCount ?></span>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter & Search Toolbar (Unified) -->
    <div class="bg-white dark:bg-[#161d27] p-3 sm:p-4 rounded-xl border border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <form method="GET" class="flex-1 w-full flex items-center gap-2 flex-wrap sm:flex-nowrap">
            <div class="relative flex-1 sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث برقم اللوحة، الماركة، رقم الباب، أو اسم السائق الأساسي / البديل..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>

            <!-- قائمة تصفية الأعمدة السريعة -->
            <select name="col_filter" onchange="this.form.submit()" title="تصفية سريعة بحسب العمود"
                    class="px-2.5 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-200 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 cursor-pointer">
                <option value="all" <?= (empty($col_filter) || $col_filter === 'all') ? 'selected' : '' ?>>🔍 كل الأعمدة (الافتراضي)</option>
                <option value="vehicle" <?= $col_filter === 'vehicle' ? 'selected' : '' ?>>🚗 1. المركبة (الماركة/اللوحة)</option>
                <option value="door" <?= $col_filter === 'door' ? 'selected' : '' ?>>🚪 2. رقم الباب</option>
                <option value="driver" <?= $col_filter === 'driver' ? 'selected' : '' ?>>👤 3. السائق المكلف</option>
                <option value="relief" <?= $col_filter === 'relief' ? 'selected' : '' ?>>🔄 4. السائق البديل</option>
                <option value="technical" <?= $col_filter === 'technical' ? 'selected' : '' ?>>📋 5. المراقبة التقنية</option>
                <option value="insurance" <?= $col_filter === 'insurance' ? 'selected' : '' ?>>🛡️ 6. التأمين</option>
                <option value="extinguisher" <?= $col_filter === 'extinguisher' ? 'selected' : '' ?>>🧯 7. قارورة الإطفاء</option>
                <option value="gpl" <?= $col_filter === 'gpl' ? 'selected' : '' ?>>⛽ 8. GPL</option>
                <option value="taximeter" <?= $col_filter === 'taximeter' ? 'selected' : '' ?>>⏱️ 9. العداد</option>
            </select>

            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 dark:bg-amber-500 text-white dark:text-slate-950 font-bold hover:bg-slate-800 dark:hover:bg-amber-600 transition">بحث</button>

            <!-- زر فتح لوحة الفلترة المتقدمة للأعمدة الـ 9 -->
            <button type="button" onclick="toggleColumnFilterPanel()" 
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg font-bold text-xs transition border <?= $activeColFiltersCount > 0 ? 'bg-amber-500 text-slate-950 border-amber-600 shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:bg-slate-200' ?>" 
                    title="فتح لوحة تصفية الأعمدة التسعة">
                <svg class="w-4 h-4 text-current" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                </svg>
                <span>فلتر الأعمدة الـ 9</span>
                <?php if ($activeColFiltersCount > 0): ?>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-slate-950 text-amber-400 font-num font-black"><?= $activeColFiltersCount ?></span>
                <?php endif; ?>
            </button>

            <?php if (!empty($search) || $filter !== 'all' || $activeColFiltersCount > 0): ?>
                <a href="vehicles.php" class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 underline whitespace-nowrap">إلغاء الكل</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
            <a href="vehicles.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 dark:bg-amber-500 text-white dark:text-slate-950 shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                الكل (<?= $totalVehiclesCount ?>)
            </a>
            <a href="vehicles.php?filter=active<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'active' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 border border-emerald-200 dark:border-emerald-800' ?>">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>نشطة</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= $filter === 'active' ? 'bg-white text-emerald-700' : 'bg-emerald-200 dark:bg-emerald-900/80 text-emerald-900 dark:text-emerald-100' ?>"><?= $activeVehiclesCount ?></span>
            </a>
            <a href="vehicles.php?filter=suspended<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'suspended' ? 'bg-rose-600 text-white shadow-xs' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 hover:bg-rose-100 border border-rose-200 dark:border-rose-800' ?>">
                <span>⏸️</span>
                <span>معلقة</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= $filter === 'suspended' ? 'bg-white text-rose-700' : 'bg-rose-200 dark:bg-rose-900/80 text-rose-900 dark:text-rose-100' ?>"><?= $suspendedVehiclesCount ?></span>
            </a>
            <a href="vehicles.php?exp_filter=all_alerts<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'all') ? 'bg-rose-500 text-white shadow-xs' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 hover:bg-rose-100 border border-rose-200/60 dark:border-rose-800' ?>">
                <span>كل التنبيهات</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'all') ? 'bg-white text-rose-600' : 'bg-rose-200 dark:bg-rose-900/80 text-rose-800 dark:text-rose-100' ?>"><?= $expiringDocsCount ?></span>
            </a>
            <a href="vehicles.php?exp_filter=all_expired<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'expired') ? 'bg-rose-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 border border-slate-200 dark:border-slate-700' ?>">
                <span class="w-2 h-2 rounded-full bg-rose-600"></span>
                <span>منتهية</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'expired') ? 'bg-white text-rose-700 font-bold' : 'bg-rose-100 dark:bg-rose-950/80 text-rose-800 dark:text-rose-300' ?>"><?= $totalExpiredDocsCount ?></span>
            </a>
            <a href="vehicles.php?exp_filter=all_soon<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'soon') ? 'bg-amber-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 border border-slate-200 dark:border-slate-700' ?>">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                <span>قريبة</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= ($filter === 'expiring' && $doc === 'all' && $scope === 'soon') ? 'bg-white text-amber-700 font-bold' : 'bg-amber-100 dark:bg-amber-950/80 text-amber-900 dark:text-amber-300' ?>"><?= $totalSoonDocsCount ?></span>
            </a>
            <a href="vehicles.php?filter=relief<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'relief' ? 'bg-amber-500 text-white shadow-xs' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 hover:bg-amber-100 border border-amber-200/80 dark:border-amber-800' ?>">
                <span>سائق بديل</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= $filter === 'relief' ? 'bg-white text-amber-700' : 'bg-amber-200 dark:bg-amber-900/80 text-amber-900 dark:text-amber-100' ?>"><?= $reliefVehiclesCount ?></span>
            </a>
            <a href="vehicles.php?filter=unassigned<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap <?= $filter === 'unassigned' ? 'bg-slate-900 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200' ?>">
                شاغرة
            </a>
            <a href="vehicles.php?filter=trash<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-lg font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'trash' ? 'bg-rose-700 text-white shadow-xs' : 'bg-rose-50/80 dark:bg-rose-950/30 text-rose-700 dark:text-rose-300 hover:bg-rose-100 border border-rose-200 dark:border-rose-900/60' ?>">
                <span>🗑️ سلة المهملات</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-num <?= $filter === 'trash' ? 'bg-white text-rose-700 font-black' : 'bg-rose-200 dark:bg-rose-900/80 text-rose-900 dark:text-rose-100' ?>"><?= $trashVehiclesCount ?></span>
            </a>
        </div>
    </div>

    <!-- 9-Column Advanced Filter Panel (المركبة، رقم الباب، السائق المكلف، السائق البديل، المراقبة التقنية، التأمين، قارورة الإطفاء، GPL، العداد) -->
    <div id="columnFilterPanel" class="<?= $activeColFiltersCount > 0 ? '' : 'hidden' ?> p-4 sm:p-5 rounded-2xl bg-white dark:bg-[#161d27] border border-amber-300 dark:border-amber-900/80 shadow-sm space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-800 dark:text-amber-300 flex items-center justify-center font-bold text-base">🎛️</span>
                <div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-xs sm:text-sm">فلترة مخصصة حسب أعمدة سجل المركبات التسعة</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">تصفية دقيقة للأعمدة التسعة: المركبة، رقم الباب، السائقين، والوثائق الخمس</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($activeColFiltersCount > 0): ?>
                    <a href="vehicles.php" class="text-xs text-rose-600 dark:text-rose-400 hover:underline font-bold px-2 py-1">إعادة تعيين الكل ✕</a>
                <?php endif; ?>
                <button type="button" onclick="toggleColumnFilterPanel()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-xs px-2 py-1 font-bold">إخفاء ▴</button>
            </div>
        </div>

        <form method="GET" id="columnFilterForm" class="space-y-4">
            <?php if (!empty($search)): ?>
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <?php endif; ?>
            <?php if ($filter !== 'all'): ?>
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5 text-xs">
                <!-- 1. المركبة -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>🚗</span>
                        <span>1. المركبة (الماركة / الموديل / اللوحة):</span>
                    </label>
                    <input type="text" name="f_vehicle" value="<?= htmlspecialchars($f_vehicle) ?>" 
                           placeholder="مثال: Accent, Duster, 09415..."
                           class="w-full px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                </div>

                <!-- 2. رقم الباب -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>🚪</span>
                        <span>2. رقم الباب:</span>
                    </label>
                    <input type="text" name="f_door" value="<?= htmlspecialchars($f_door) ?>" 
                           placeholder="مثال: 90 أو (02/22)-90..."
                           class="w-full px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500">
                </div>

                <!-- 3. السائق المكلف -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>👤</span>
                        <span>3. السائق المكلف:</span>
                    </label>
                    <select name="f_driver" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-semibold focus:ring-2 focus:ring-amber-500/20">
                        <option value="all" <?= ($f_driver === 'all' || empty($f_driver)) ? 'selected' : '' ?>>الكل</option>
                        <option value="assigned" <?= $f_driver === 'assigned' ? 'selected' : '' ?>>معين بسائق مكلف</option>
                        <option value="unassigned" <?= $f_driver === 'unassigned' ? 'selected' : '' ?>>شاغر (بدون سائق مكلف)</option>
                    </select>
                </div>

                <!-- 4. السائق البديل -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>🔄</span>
                        <span>4. السائق البديل (الاستخلاف):</span>
                    </label>
                    <select name="f_replacement" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-semibold focus:ring-2 focus:ring-amber-500/20">
                        <option value="all" <?= ($f_replacement === 'all' || empty($f_replacement)) ? 'selected' : '' ?>>الكل</option>
                        <option value="has_relief" <?= $f_replacement === 'has_relief' ? 'selected' : '' ?>>يوجد سائق بديل معتمد</option>
                        <option value="no_relief" <?= $f_replacement === 'no_relief' ? 'selected' : '' ?>>بدون سائق بديل</option>
                    </select>
                </div>

                <!-- 5. المراقبة التقنية -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>📋</span>
                        <span>5. المراقبة التقنية:</span>
                    </label>
                    <select name="f_tc" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-semibold focus:ring-2 focus:ring-amber-500/20">
                        <option value="all" <?= ($f_tc === 'all' || empty($f_tc)) ? 'selected' : '' ?>>الكل</option>
                        <option value="expired" <?= $f_tc === 'expired' ? 'selected' : '' ?>>🔴 منتهية الصلاحية</option>
                        <option value="soon" <?= $f_tc === 'soon' ? 'selected' : '' ?>>🟡 قريبة الانتهاء (&lt; 30 يوماً)</option>
                        <option value="valid" <?= $f_tc === 'valid' ? 'selected' : '' ?>>🟢 سارية وصالحة</option>
                    </select>
                </div>

                <!-- 6. التأمين -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>🛡️</span>
                        <span>6. التأمين الشامل:</span>
                    </label>
                    <select name="f_ins" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-semibold focus:ring-2 focus:ring-amber-500/20">
                        <option value="all" <?= ($f_ins === 'all' || empty($f_ins)) ? 'selected' : '' ?>>الكل</option>
                        <option value="expired" <?= $f_ins === 'expired' ? 'selected' : '' ?>>🔴 منتهي الصلاحية</option>
                        <option value="soon" <?= $f_ins === 'soon' ? 'selected' : '' ?>>🟡 قريب الانتهاء (&lt; 30 يوماً)</option>
                        <option value="valid" <?= $f_ins === 'valid' ? 'selected' : '' ?>>🟢 سارٍ وصالح</option>
                    </select>
                </div>

                <!-- 7. قارورة الإطفاء -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>🧯</span>
                        <span>7. قارورة الإطفاء:</span>
                    </label>
                    <select name="f_ext" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-semibold focus:ring-2 focus:ring-amber-500/20">
                        <option value="all" <?= ($f_ext === 'all' || empty($f_ext)) ? 'selected' : '' ?>>الكل</option>
                        <option value="expired" <?= $f_ext === 'expired' ? 'selected' : '' ?>>🔴 منتهية الصلاحية</option>
                        <option value="soon" <?= $f_ext === 'soon' ? 'selected' : '' ?>>🟡 قريبة الانتهاء (&lt; 30 يوماً)</option>
                        <option value="valid" <?= $f_ext === 'valid' ? 'selected' : '' ?>>🟢 صالحة وسارية</option>
                    </select>
                </div>

                <!-- 8. GPL -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>⛽</span>
                        <span>8. فحص سيرغاز (GPL):</span>
                    </label>
                    <select name="f_gpl" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-semibold focus:ring-2 focus:ring-amber-500/20">
                        <option value="all" <?= ($f_gpl === 'all' || empty($f_gpl)) ? 'selected' : '' ?>>الكل</option>
                        <option value="expired" <?= $f_gpl === 'expired' ? 'selected' : '' ?>>🔴 منتهي الصلاحية</option>
                        <option value="soon" <?= $f_gpl === 'soon' ? 'selected' : '' ?>>🟡 قريب الانتهاء (&lt; 30 يوماً)</option>
                        <option value="valid" <?= $f_gpl === 'valid' ? 'selected' : '' ?>>🟢 سارٍ ومعتمد</option>
                        <option value="none" <?= $f_gpl === 'none' ? 'selected' : '' ?>>⚪ غير مزودة بسيرغاز</option>
                    </select>
                </div>

                <!-- 9. العداد -->
                <div class="space-y-1">
                    <label class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>⏱️</span>
                        <span>9. عداد التاكسي (الساكوريومتر):</span>
                    </label>
                    <select name="f_sac" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-semibold focus:ring-2 focus:ring-amber-500/20">
                        <option value="all" <?= ($f_sac === 'all' || empty($f_sac)) ? 'selected' : '' ?>>الكل</option>
                        <option value="expired" <?= $f_sac === 'expired' ? 'selected' : '' ?>>🔴 منتهي الصلاحية</option>
                        <option value="valid" <?= $f_sac === 'valid' ? 'selected' : '' ?>>🟢 سارٍ ومختوم</option>
                        <option value="none" <?= $f_sac === 'none' ? 'selected' : '' ?>>⚪ بدون عداد</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2 border-t border-slate-100 dark:border-slate-800 flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs transition shadow-xs flex items-center gap-1.5">
                        <span>تطبيق الفلترة على الأعمدة</span>
                        <span>🔍</span>
                    </button>
                    <a href="vehicles.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold hover:bg-slate-200 transition">
                        مسح الفلاتر ✕
                    </a>
                </div>
                <span class="text-[11px] text-slate-400 font-num">تم العثور على <?= count($vehicles) ?> مركبة مطابقة</span>
            </div>
        </form>
    </div>

    <!-- Active Multi-Column Filter Tags Banner -->
    <?php if ($activeColFiltersCount > 0): ?>
    <div class="p-3 rounded-xl bg-amber-50/80 dark:bg-amber-950/30 border border-amber-300/80 dark:border-amber-900/60 flex items-center justify-between text-xs flex-wrap gap-2 shadow-2xs">
        <div class="flex items-center gap-1.5 flex-wrap">
            <span class="font-bold text-amber-950 dark:text-amber-200 flex items-center gap-1">
                <span>🎛️ الفلاتر النشطة للأعمدة:</span>
            </span>
            <?php if (!empty($f_vehicle)): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    🚗 المركبة: <?= htmlspecialchars($f_vehicle) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_door)): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    🚪 رقم الباب: <?= htmlspecialchars($f_door) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_driver) && $f_driver !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    👤 السائق: <?= $f_driver === 'assigned' ? 'معين' : 'شاغر' ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_replacement) && $f_replacement !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    🔄 البديل: <?= $f_replacement === 'has_relief' ? 'يوجد بديل' : 'بدون بديل' ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_tc) && $f_tc !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    📋 المراقبة: <?= $f_tc === 'expired' ? 'منتهية' : ($f_tc === 'soon' ? 'قريبة' : 'سارية') ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_ins) && $f_ins !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    🛡️ التأمين: <?= $f_ins === 'expired' ? 'منتهي' : ($f_ins === 'soon' ? 'قريب' : 'سارٍ') ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_ext) && $f_ext !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    🧯 الإطفاء: <?= $f_ext === 'expired' ? 'منتهية' : ($f_ext === 'soon' ? 'قريبة' : 'صالحة') ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_gpl) && $f_gpl !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    ⛽ GPL: <?= $f_gpl === 'expired' ? 'منتهي' : ($f_gpl === 'soon' ? 'قريب' : ($f_gpl === 'none' ? 'بدون' : 'سارٍ')) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($f_sac) && $f_sac !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    ⏱️ العداد: <?= $f_sac === 'expired' ? 'منتهي' : ($f_sac === 'none' ? 'بدون' : 'سارٍ') ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($col_filter) && $col_filter !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-amber-900 dark:text-amber-200 font-bold border border-amber-300">
                    العمود: <?= htmlspecialchars($col_filter) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <span class="font-bold text-amber-950 dark:text-amber-200 font-num">(<?= count($vehicles) ?> مركبة)</span>
            <a href="vehicles.php" class="text-amber-900 dark:text-amber-300 hover:underline font-bold">إلغاء التصفية ✕</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Active Filter Info Banner -->
    <?php if ($filter === 'expiring'): ?>
    <div class="px-4 py-2.5 rounded-xl bg-rose-50/70 dark:bg-rose-950/30 border border-rose-200/80 dark:border-rose-900/60 flex items-center justify-between text-xs flex-wrap gap-2 shadow-2xs">
        <div class="flex items-center gap-2">
            <span class="text-sm">📌</span>
            <span class="font-bold text-slate-700 dark:text-slate-300">التصفية النشطة للوثائق:</span>
            <span class="inline-flex items-center gap-1 font-bold text-rose-800 dark:text-rose-200 bg-white dark:bg-slate-900 px-2.5 py-0.5 rounded-md border border-rose-300 dark:border-rose-800 shadow-2xs">
                <?= htmlspecialchars($filterDescription ?? 'تنبيهات الصلاحية') ?>
                <span class="text-rose-500 font-num font-mono">(<?= count($vehicles) ?> مركبة)</span>
            </span>
        </div>
        <a href="vehicles.php" class="text-xs text-rose-600 dark:text-rose-400 hover:underline font-bold flex items-center gap-1">
            <span>إلغاء التصفية وعرض جميع المركبات</span>
            <span dir="ltr">✕</span>
        </a>
    </div>
    <?php endif; ?>

    <!-- Trash / Recycle Bin Banner -->
    <?php if ($filter === 'trash'): ?>
    <div class="px-4 py-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-300 dark:border-rose-900 flex items-center justify-between text-xs flex-wrap gap-2 shadow-sm">
        <div class="flex items-center gap-2">
            <span class="text-lg">🗑️</span>
            <div>
                <span class="font-bold text-rose-900 dark:text-rose-200">أنت تتصفح حالياً سلة المهملات للمركبات:</span>
                <span class="text-rose-700 dark:text-rose-300 mr-1">يمكنك استرجاع أي مركبة بنقرة واحدة لتعود فوراً للأسطول النشط بكامل بياناتها، أو حذفها نهائياً.</span>
            </div>
            <span class="px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-rose-700 dark:text-rose-300 font-bold border border-rose-300 font-num">
                <?= count($vehicles) ?> مركبة محذوفة
            </span>
        </div>
        <div class="flex items-center gap-2">
            <a href="archive.php" class="px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 text-rose-700 dark:text-rose-300 border border-rose-300 font-bold hover:bg-rose-100 transition">
                الانتقال لمركز الأرشيف العام ↗
            </a>
            <a href="vehicles.php" class="text-xs text-rose-600 dark:text-rose-400 hover:underline font-bold">
                العودة للأسطول النشط ✕
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Vehicles Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr>
                        <th class="py-3 px-3.5 whitespace-nowrap sticky-col-start">المركبة</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">رقم الباب</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">السائق المكلف</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">السائق البديل</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">المراقبة التقنية</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">التأمين</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">قارورة الإطفاء</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">GPL</th>
                        <th class="py-3 px-2.5 whitespace-nowrap">العداد</th>
                        <th class="py-3 px-2 text-center whitespace-nowrap sticky-col-end w-12">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                    <?php if (!empty($vehicles)): ?>
                        <?php foreach ($vehicles as $v): 
                            $tcDiff  = !empty($v['technical_control_expiry']) ? round((strtotime($v['technical_control_expiry']) - time()) / 86400) : null;
                            $insDiff = !empty($v['insurance_expiry']) ? round((strtotime($v['insurance_expiry']) - time()) / 86400) : null;
                            $extDiff = !empty($v['extinguisher_expiry']) ? round((strtotime($v['extinguisher_expiry']) - time()) / 86400) : null;
                            $gplDiff = !empty($v['gpl_expiry']) ? round((strtotime($v['gpl_expiry']) - time()) / 86400) : null;
                            $sacDiff = !empty($v['saccuriometre_expiry']) ? round((strtotime($v['saccuriometre_expiry']) - time()) / 86400) : null;
                        ?>
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50 transition">
                                <!-- 1. المركبة -->
                                <td class="py-2.5 px-3.5 whitespace-nowrap sticky-col-start">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="font-bold text-slate-900 dark:text-white text-[13px]"><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?></span>
                                        <?php if (($v['status'] ?? 'active') === 'suspended'): ?>
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.2 rounded-md text-[10px] font-bold bg-rose-500/15 text-rose-700 dark:text-rose-400 border border-rose-500/30" title="<?= htmlspecialchars($v['suspended_reason'] ?? 'مركبة معلقة') ?>">
                                                <span>⏸️</span>
                                                <span>معلقة</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.2 rounded-md text-[10px] font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                <span>نشطة</span>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($v['ratings_count'])): ?>
                                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.2 rounded-md text-[10px] font-bold bg-amber-500/15 text-amber-800 dark:text-amber-300 border border-amber-500/30 font-num" title="تقييمات الركاب للسائق">
                                                <span>⭐</span>
                                                <span><?= $v['avg_rating'] ?></span>
                                                <span class="text-[9px] text-slate-400 font-normal font-num">(<?= $v['ratings_count'] ?>)</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-0.5 inline-flex items-center px-2 py-0.5 rounded bg-slate-900 text-amber-400 font-num font-mono text-[11px] tracking-wider" dir="ltr">
                                        <?= htmlspecialchars(str_replace('/', '-', $v['plate_number'])) ?>
                                    </div>
                                </td>

                                <!-- 2. رقم الباب -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <div>
                                        <span class="table-num-badge table-num-amber" dir="ltr">
                                            <?= htmlspecialchars($v['door_number']) ?>
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-400 dark:text-slate-500 mt-1"><?= htmlspecialchars($v['color']) ?></div>
                                </td>

                                <!-- 3. السائق المكلف -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['driver_name'])): ?>
                                        <div class="font-bold text-slate-900 dark:text-slate-100 flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0" title="سائق أساسي مكلف"></span>
                                            <span><?= htmlspecialchars($v['driver_name']) ?></span>
                                        </div>
                                        <div class="mt-1" dir="ltr">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold font-num bg-slate-100/90 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200/70 dark:border-slate-700">
                                                <?= htmlspecialchars($v['driver_phone']) ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">
                                            شاغر
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. السائق البديل -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($v['replacement_driver_name'])): ?>
                                        <div class="font-bold text-amber-950 dark:text-amber-200 text-xs flex items-center gap-1.5">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0 animate-pulse"></span>
                                            <span><?= htmlspecialchars($v['replacement_driver_name']) ?></span>
                                        </div>
                                        <div class="text-[10px] text-amber-800 dark:text-amber-300 font-num mt-1 flex items-center gap-1.5">
                                            <span dir="ltr" class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold font-num bg-amber-50 dark:bg-amber-950/60 text-amber-900 dark:text-amber-300 border border-amber-200/60 dark:border-amber-800/60"><?= htmlspecialchars($v['replacement_driver_phone'] ?? '—') ?></span>
                                            <?php if (!empty($v['leave_end_date'])): ?>
                                                <span class="text-[9px] text-amber-800 dark:text-amber-300 bg-amber-100/80 dark:bg-amber-900/60 px-1.5 py-0.5 rounded border border-amber-300/60 dark:border-amber-800 font-bold" title="عطلة السائق الأساسي">إلى <?= htmlspecialchars($v['leave_end_date']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate text-[10px]">
                                            بدون بديل
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 1. Technical Control (المراقبة التقنية) -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($tcDiff !== null && $tcDiff < 0): ?>
                                        <span class="table-num-badge table-num-rose" title="المراقبة التقنية منتهية الصلاحية!" dir="ltr">
                                            <span class="table-num-dot table-num-dot-rose"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['technical_control_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(منتهية <?= abs($tcDiff) ?> ي)</span>
                                        </span>
                                    <?php elseif ($tcDiff !== null && $tcDiff <= 30): ?>
                                        <span class="table-num-badge table-num-amber" title="أوشكت المراقبة التقنية على الانتهاء" dir="ltr">
                                            <span class="table-num-dot table-num-dot-amber"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['technical_control_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(باقي <?= $tcDiff ?> ي)</span>
                                        </span>
                                    <?php elseif (!empty($v['technical_control_expiry'])): ?>
                                        <span class="table-num-badge table-num-emerald" title="المراقبة التقنية سارية وصالحة" dir="ltr">
                                            <span class="table-num-dot table-num-dot-emerald"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['technical_control_expiry']) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate">غير مسجل</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 2. Insurance (التأمين الشامل) -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($insDiff !== null && $insDiff < 0): ?>
                                        <span class="table-num-badge table-num-rose" title="التأمين الشامل منتهي الصلاحية!" dir="ltr">
                                            <span class="table-num-dot table-num-dot-rose"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['insurance_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(منتهي <?= abs($insDiff) ?> ي)</span>
                                        </span>
                                    <?php elseif ($insDiff !== null && $insDiff <= 30): ?>
                                        <span class="table-num-badge table-num-amber" title="أوشك التأمين الشامل على الانتهاء" dir="ltr">
                                            <span class="table-num-dot table-num-dot-amber"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['insurance_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(باقي <?= $insDiff ?> ي)</span>
                                        </span>
                                    <?php elseif (!empty($v['insurance_expiry'])): ?>
                                        <span class="table-num-badge table-num-emerald" title="التأمين الشامل ساري وصالح" dir="ltr">
                                            <span class="table-num-dot table-num-dot-emerald"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['insurance_expiry']) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate">غير مسجل</span>
                                    <?php endif; ?>
                                    <?php if (!empty($v['insurance_company'])): ?>
                                        <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1"><?= htmlspecialchars($v['insurance_company']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- 3. Fire Extinguisher (قارورة الإطفاء) -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($extDiff !== null && $extDiff < 0): ?>
                                        <span class="table-num-badge table-num-rose" title="قارورة الإطفاء منتهية الصلاحية!" dir="ltr">
                                            <span class="table-num-dot table-num-dot-rose"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['extinguisher_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(منتهية <?= abs($extDiff) ?> ي)</span>
                                        </span>
                                    <?php elseif ($extDiff !== null && $extDiff <= 30): ?>
                                        <span class="table-num-badge table-num-amber" title="قارورة الإطفاء بحاجة للتجديد قريباً" dir="ltr">
                                            <span class="table-num-dot table-num-dot-amber"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['extinguisher_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(باقي <?= $extDiff ?> ي)</span>
                                        </span>
                                    <?php elseif (!empty($v['extinguisher_expiry'])): ?>
                                        <span class="table-num-badge table-num-emerald" title="قارورة الإطفاء صالحة وسارية" dir="ltr">
                                            <span class="table-num-dot table-num-dot-emerald"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['extinguisher_expiry']) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate">غير مسجل</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. GPL Check (فحص سيرغاز GPL) -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (($v['gpl_text'] ?? '') === 'بدون GPL'): ?>
                                        <span class="table-num-badge table-num-slate">بدون GPL</span>
                                    <?php elseif ($gplDiff !== null && $gplDiff < 0): ?>
                                        <span class="table-num-badge table-num-rose" title="شهادة مطابقة خزان GPL منتهية الصلاحية!" dir="ltr">
                                            <span class="table-num-dot table-num-dot-rose"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['gpl_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(منتهية <?= abs($gplDiff) ?> ي)</span>
                                        </span>
                                    <?php elseif ($gplDiff !== null && $gplDiff <= 30): ?>
                                        <span class="table-num-badge table-num-amber" title="شهادة GPL على وشك الانتهاء" dir="ltr">
                                            <span class="table-num-dot table-num-dot-amber"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['gpl_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(باقي <?= $gplDiff ?> ي)</span>
                                        </span>
                                    <?php elseif (!empty($v['gpl_expiry'])): ?>
                                        <span class="table-num-badge table-num-emerald" title="فحص سيرغاز GPL ساري وصالح" dir="ltr">
                                            <span class="table-num-dot table-num-dot-emerald"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['gpl_expiry']) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate">
                                            <?= htmlspecialchars(!empty($v['gpl_text']) ? $v['gpl_text'] : 'غير مسجل') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. Taximeter (عداد التاكسي / الساكوريومتر) -->
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (($v['saccuriometre_text'] ?? '') === 'بدون عداد' || ($v['saccuriometre_text'] ?? '') === 'Sans Saccuriomètre'): ?>
                                        <span class="table-num-badge table-num-slate">بدون عداد</span>
                                    <?php elseif ($sacDiff !== null && $sacDiff < 0): ?>
                                        <span class="table-num-badge table-num-rose" title="صلاحية شهادة العداد منتهية!" dir="ltr">
                                            <span class="table-num-dot table-num-dot-rose"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['saccuriometre_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(منتهي <?= abs($sacDiff) ?> ي)</span>
                                        </span>
                                    <?php elseif ($sacDiff !== null && $sacDiff <= 30): ?>
                                        <span class="table-num-badge table-num-amber" title="شهادة العداد على وشك الانتهاء" dir="ltr">
                                            <span class="table-num-dot table-num-dot-amber"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['saccuriometre_expiry']) ?></span>
                                            <span class="text-[9px] font-bold" dir="rtl">(باقي <?= $sacDiff ?> ي)</span>
                                        </span>
                                    <?php elseif (!empty($v['saccuriometre_expiry'])): ?>
                                        <span class="table-num-badge table-num-emerald" title="شهادة العداد سارية وصالحة" dir="ltr">
                                            <span class="table-num-dot table-num-dot-emerald"></span>
                                            <span class="font-num"><?= htmlspecialchars($v['saccuriometre_expiry']) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate">
                                            <?= !empty($v['saccuriometre_text']) ? htmlspecialchars($v['saccuriometre_text']) : 'غير مسجل' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions (3-Dots Dropdown Menu) -->
                                <td class="py-2.5 px-2 text-center whitespace-nowrap sticky-col-end">
                                    <div class="relative inline-block text-right">
                                        <button type="button"
                                                onclick="toggleTableDropdown(event, this)"
                                                class="table-dropdown-btn w-8 h-8 rounded-xl bg-slate-100 hover:bg-amber-500/15 hover:text-amber-700 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 shadow-2xs cursor-pointer"
                                                title="خيارات وإجراءات المركبة">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                            </svg>
                                        </button>

                                        <div class="table-dropdown-menu hidden w-60 min-w-[240px] rounded-2xl bg-white/95 dark:bg-[#161d27]/95 backdrop-blur-md border border-slate-200 dark:border-slate-800 shadow-2xl p-1.5 space-y-0.5 text-xs text-right select-none">
                                            <?php if (is_admin() || is_owner()): ?>
                                            <a href="vehicles.php?edit=<?= $v['id'] ?>"
                                               class="table-dropdown-item flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 font-bold transition whitespace-nowrap">
                                                <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل البيانات والوثائق</span>
                                            </a>
                                            <?php else: ?>
                                            <a href="slips.php"
                                               class="table-dropdown-item flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-amber-50 dark:hover:bg-amber-900/30 font-bold transition whitespace-nowrap">
                                                <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                                <span>وصل السير الميداني</span>
                                            </a>
                                            <?php endif; ?>

                                            <!-- QR Code & Vehicle Sticker Modal Trigger -->
                                            <?php
                                            $vJsonData = htmlspecialchars(json_encode([
                                                'id' => $v['id'],
                                                'brand' => $v['brand'],
                                                'model' => $v['model'],
                                                'plate_number' => str_replace('/', '-', $v['plate_number']),
                                                'door_number' => $v['door_number'],
                                                'color' => $v['color'],
                                                'status' => $v['status'] ?? 'active',
                                                'suspended_reason' => $v['suspended_reason'] ?? '',
                                                'driver_name' => $v['driver_name'] ?? 'بدون سائق حالياً',
                                                'driver_phone' => $v['driver_phone'] ?? '',
                                                'qr_code' => $v['qr_code'] ?? ('TN-V' . str_pad($v['id'], 3, '0', STR_PAD_LEFT)),
                                                'ratings_count' => (int)($v['ratings_count'] ?? 0),
                                                'avg_rating' => (float)($v['avg_rating'] ?? 5.0)
                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <button type="button"
                                                    onclick="openVehicleQrModal(<?= $vJsonData ?>)"
                                                    class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-amber-800 dark:text-amber-200 hover:bg-amber-50 dark:hover:bg-amber-900/30 font-bold transition text-right cursor-pointer whitespace-nowrap">
                                                <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.008v.008H6.75V6.75zM6.75 16.5h.008v.008H6.75V16.5zM16.5 6.75h.008v.008H16.5V6.75zM13.5 13.5h.008v.008H13.5V13.5zM13.5 19.5h.008v.008H13.5V19.5zM19.5 13.5h.008v.008H19.5V13.5zM19.5 19.5h.008v.008H19.5V19.5zM16.5 16.5h.008v.008H16.5V16.5z" />
                                                </svg>
                                                <span>كود بار وملصق السيارة 📱</span>
                                            </button>

                                            <!-- Toggle Vehicle Status (Active / Suspended) -->
                                            <?php if (is_admin() || is_owner()): ?>
                                                <?php if (($v['status'] ?? 'active') === 'suspended'): ?>
                                                <form method="POST" action="" onsubmit="return confirm('هل تريد تنشيط هذه المركبة وإعادتها للخدمة الميدانية؟')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action_type" value="toggle_status">
                                                    <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                                    <input type="hidden" name="new_status" value="active">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 font-bold transition text-right cursor-pointer whitespace-nowrap">
                                                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                                                        </svg>
                                                        <span>تنشيط المركبة للخدمة ▶️</span>
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <button type="button"
                                                        onclick="promptSuspendVehicle(<?= $v['id'] ?>, '<?= htmlspecialchars(addslashes($v['brand'] . ' ' . $v['model'])) ?>')"
                                                        class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/40 font-bold transition text-right cursor-pointer whitespace-nowrap">
                                                    <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                                                    </svg>
                                                    <span>تعليق تشغيل المركبة ⏸️</span>
                                                </button>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if (is_admin()): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                                <?php if (!empty($v['deleted_at'])): ?>
                                                <!-- Restore Vehicle Form -->
                                                <form method="POST" action="" onsubmit="return confirm('هل تريد استرجاع هذه المركبة وإعادتها فوراً إلى أسطول المركبات النشط؟')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action_type" value="restore">
                                                    <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 font-bold transition text-right cursor-pointer whitespace-nowrap">
                                                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                                                        </svg>
                                                        <span>استرجاع إلى الأسطول 🔄</span>
                                                    </button>
                                                </form>

                                                <!-- Permanent Force Delete Form -->
                                                <form method="POST" action="" onsubmit="return confirm('تحذير نهائي: هل أنت متأكد من الحذف النهائي لهذه المركبة من قاعدة البيانات؟ لا يمكن التراجع عن هذا الإجراء!')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action_type" value="force_delete">
                                                    <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-rose-700 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-950 font-bold transition text-right cursor-pointer whitespace-nowrap">
                                                        <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                        <span>حذف نهائي ⚠️</span>
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <!-- Soft Delete Form -->
                                                <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من نقل هذه المركبة إلى سلة المهملات والأرشيف؟ يمكنك استرجاعها في أي وقت.')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action_type" value="delete">
                                                    <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-bold transition text-right cursor-pointer whitespace-nowrap">
                                                        <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                        <span>نقل إلى سلة المهملات 🗑️</span>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="p-12 text-center text-slate-400 dark:text-slate-500">
                                <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mb-3">
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

<?php if (is_admin() || is_owner()): ?>
<!-- Modal: Add / Edit Vehicle -->
<div id="addVehicleModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm <?= $editVehicle ? 'flex' : 'hidden' ?> items-center justify-center p-3 sm:p-6 overflow-y-auto">
    <div class="bg-white dark:bg-[#161d27] max-w-3xl w-full rounded-2xl p-6 sm:p-7 border border-slate-200 dark:border-slate-800 shadow-2xl max-h-[92vh] overflow-y-auto space-y-5 text-right m-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 dark:text-white text-lg">
                    <?= $editVehicle ? 'تعديل بيانات سيارة الأجرة والوثائق' : 'إضافة سيارة أجرة جديدة إلى الأسطول' ?>
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">أدخل بيانات المركبة وتواريخ صلاحية المراقبة التقنية والتأمين والعداد</p>
            </div>
            <button type="button" onclick="closeVehicleModal()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-white flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
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
                 1. تعيين السائق الأساسي والسائق البديل (بحث AJAX)
                 ============================================== -->
            <div class="p-4 rounded-2xl bg-slate-50/80 dark:bg-[#111827]/70 border border-slate-200 dark:border-slate-700/80 space-y-4 shadow-2xs">
                <div class="flex items-center justify-between pb-2 border-b border-slate-200/60 dark:border-slate-700/60">
                    <div>
                        <span class="font-bold text-slate-900 dark:text-white text-xs flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <span>تعيين السائقين للمركبة (خطوة أولى أساسية)</span>
                        </span>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">قائمة ذكية مع بحث مباشر (AJAX) لربط فوري بالأسطول</p>
                    </div>
                    <span class="text-[10px] font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-900 dark:text-amber-300 border border-amber-200 dark:border-amber-800 px-2.5 py-1 rounded-md">
                        قائمة وسجل السائقين
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- تعيين السائق الأساسي مع بحث AJAX -->
                    <div class="space-y-1.5" id="slotContainer_primary">
                        <label class="block font-bold text-slate-800 dark:text-slate-200 text-xs flex items-center justify-between">
                            <span>تعيين السائق الأساسي <span class="text-rose-500">*</span></span>
                            <span class="text-[10px] text-amber-600 dark:text-amber-400 font-bold">بحث مباشر AJAX</span>
                        </label>

                        <!-- Selected Driver Card (Primary) -->
                        <div id="driverSelectedCard_primary" class="<?= $selectedPrimaryDriver ? '' : 'hidden' ?> p-3 rounded-xl bg-white dark:bg-[#1a2332] border border-amber-300 dark:border-amber-500/50 shadow-2xs flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center text-xs shadow-2xs shrink-0">
                                    👤
                                </div>
                                <div>
                                    <div class="font-bold text-slate-900 dark:text-white text-xs flex items-center gap-1.5">
                                        <span id="driverSelectedName_primary"><?= htmlspecialchars($selectedPrimaryDriver['full_name'] ?? '') ?></span>
                                        <span id="driverSelectedStage_primary" class="text-[9px] px-1.5 py-0.5 rounded font-bold bg-amber-100 dark:bg-amber-950/80 text-amber-900 dark:text-amber-300 border border-amber-200 dark:border-amber-700">
                                            <?= htmlspecialchars($selectedPrimaryDriver['probation_stage'] ?? 'نشط') ?>
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-num mt-0.5" id="driverSelectedDetails_primary">
                                        هاتف: <?= htmlspecialchars($selectedPrimaryDriver['phone'] ?? '') ?> • رخصة: <?= htmlspecialchars($selectedPrimaryDriver['license_number'] ?? 'غير مسجلة') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" onclick="showDriverSearch('primary')" class="px-2 py-1 text-[11px] font-bold text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/50 rounded-lg transition">
                                    تغيير
                                </button>
                                <button type="button" onclick="clearDriverSelection('primary')" class="px-1.5 py-1 text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 rounded-lg transition" title="إلغاء التعيين">
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
                                       class="w-full pl-8 pr-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-medium placeholder:text-slate-400 dark:placeholder:text-slate-500 shadow-2xs">
                                <div id="driverSearchSpinner_primary" class="absolute left-2.5 top-1/2 -translate-y-1/2 hidden">
                                    <svg class="animate-spin h-3.5 w-3.5 text-amber-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                </div>
                            </div>

                            <!-- Dropdown Results (Primary) -->
                            <div id="driverDropdown_primary" class="hidden absolute top-full right-0 left-0 mt-1 max-h-52 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#1a2332] shadow-2xl divide-y divide-slate-100 dark:divide-slate-700 text-xs z-30">
                                <!-- Populated dynamically -->
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400">اختر السائق الأساسي المعتمد لتسيير المركبة وإصدار عقود العمل المرتبطة بها.</p>
                    </div>

                    <!-- تعيين السائق البديل مع بحث AJAX -->
                    <div class="space-y-1.5" id="slotContainer_replacement">
                        <label class="block font-bold text-slate-800 dark:text-slate-200 text-xs flex items-center justify-between">
                            <span>تعيين السائق البديل (Chauffeur Remplaçant)</span>
                            <span class="text-[10px] text-slate-400 font-normal">اختياري</span>
                        </label>

                        <!-- Selected Driver Card (Replacement) -->
                        <div id="driverSelectedCard_replacement" class="<?= $selectedReplacementDriver ? '' : 'hidden' ?> p-3 rounded-xl bg-white dark:bg-[#1a2332] border border-slate-300 dark:border-slate-700 shadow-2xs flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-slate-700 text-white font-black flex items-center justify-center text-xs shadow-2xs shrink-0">
                                    🔄
                                </div>
                                <div>
                                    <div class="font-bold text-slate-900 dark:text-white text-xs flex items-center gap-1.5">
                                        <span id="driverSelectedName_replacement"><?= htmlspecialchars($selectedReplacementDriver['full_name'] ?? '') ?></span>
                                        <span id="driverSelectedStage_replacement" class="text-[9px] px-1.5 py-0.5 rounded font-bold bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200">
                                            <?= htmlspecialchars($selectedReplacementDriver['probation_stage'] ?? 'بديل') ?>
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-num mt-0.5" id="driverSelectedDetails_replacement">
                                        هاتف: <?= htmlspecialchars($selectedReplacementDriver['phone'] ?? '') ?> • رخصة: <?= htmlspecialchars($selectedReplacementDriver['license_number'] ?? 'غير مسجلة') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" onclick="showDriverSearch('replacement')" class="px-2 py-1 text-[11px] font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                                    تغيير
                                </button>
                                <button type="button" onclick="clearDriverSelection('replacement')" class="px-1.5 py-1 text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 rounded-lg transition" title="إلغاء التعيين">
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
                                       class="w-full pl-8 pr-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 font-medium placeholder:text-slate-400 dark:placeholder:text-slate-500 shadow-2xs">
                                <div id="driverSearchSpinner_replacement" class="absolute left-2.5 top-1/2 -translate-y-1/2 hidden">
                                    <svg class="animate-spin h-3.5 w-3.5 text-slate-600 dark:text-slate-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                </div>
                            </div>

                            <!-- Dropdown Results (Replacement) -->
                            <div id="driverDropdown_replacement" class="hidden absolute top-full right-0 left-0 mt-1 max-h-52 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#1a2332] shadow-2xl divide-y divide-slate-100 dark:divide-slate-700 text-xs z-30">
                                <!-- Populated dynamically -->
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400">السائق البديل يتولى المناوبة الميدانية في حال غياب أو عطلة السائق الأساسي.</p>
                    </div>
                </div>
            </div>

            <!-- ==============================================
                 2. بيانات وتفاصيل المركبة (الماركة أو الصانع + الموديل - من الإعدادات)
                 ============================================== -->
            <div class="space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                    <span class="font-bold text-slate-800 dark:text-slate-200 text-xs flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        <span>بيانات المركبة والموديل</span>
                    </span>
                    <button type="button" onclick="openQuickPresetModal()" 
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-amber-50 dark:bg-amber-950/50 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-900 dark:text-amber-300 border border-amber-200 dark:border-amber-800 flex items-center gap-1 transition shadow-2xs">
                        <span>⚙️ إضافة ماركة / طراز في الإعدادات</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- الماركة أو الصانع (حقل ذكي موحد + مقترحات) -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label class="block font-bold text-slate-700 dark:text-slate-200">
                                الماركة أو الصانع <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-slate-400">مثال: Renault, Dacia, Peugeot</span>
                        </div>

                        <?php $presetBrandKeys = is_array($vehiclePresets) ? array_keys($vehiclePresets) : []; ?>
                        <div class="relative">
                            <input type="text" name="brand" id="vehicleBrandInput" required list="brandDatalist"
                                   placeholder="اكتب اسم الصانع أو اختر من الأزرار أدناه..." 
                                   value="<?= htmlspecialchars($editVehicle['brand'] ?? '') ?>"
                                   oninput="onBrandInputChanged(this.value)"
                                   autocomplete="off"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-bold transition shadow-2xs">
                            <datalist id="brandDatalist">
                                <?php foreach ($presetBrandKeys as $b): ?>
                                    <option value="<?= htmlspecialchars($b) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <!-- Brand Quick Buttons -->
                        <div class="flex flex-wrap gap-1 mt-1.5" id="brandPillsContainer">
                            <?php foreach (array_slice($presetBrandKeys, 0, 7) as $b): ?>
                                <button type="button" onclick="selectBrandPreset('<?= htmlspecialchars($b, ENT_QUOTES) ?>')" 
                                        class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 dark:bg-slate-800 hover:bg-amber-100 dark:hover:bg-amber-950/60 hover:text-amber-900 dark:hover:text-amber-300 text-slate-700 dark:text-slate-300 transition">
                                    <?= htmlspecialchars($b) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- طراز المركبة (الموديل) -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label class="block font-bold text-slate-700 dark:text-slate-200">
                                طراز المركبة (الموديل) <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-slate-400">طراز التاكسي المعتمد</span>
                        </div>

                        <!-- Model custom text input -->
                        <input type="text" name="model" id="vehicleModelInput" required 
                               placeholder="اكتب اسم الطراز أو اختر من الموديلات المقترحة أدناه..." 
                               value="<?= htmlspecialchars($editVehicle['model'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition shadow-2xs">

                        <!-- Quick Model Pills (Dynamically refreshed on brand click) -->
                        <div class="flex flex-wrap gap-1 mt-1.5" id="modelPillsContainer">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                </div>

                <!-- Row 2: Plate Number & Door Number (Spacious 2-column layout to prevent clipping) -->
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-4 pt-1">
                    <!-- 1. رقم لوحة الترقيم (Matricule) - 7 cols (strictly '-' separators, NO slashes '/') -->
                    <div class="sm:col-span-7 space-y-1.5">
                        <?php
                        $parsedPlate = parse_algerian_plate($editVehicle['plate_number'] ?? '');
                        $pWilaya = $parsedPlate[0];
                        $pYear   = $parsedPlate[1];
                        $pSerial = $parsedPlate[2];
                        $combinedPlate = ($pWilaya !== '' && $pYear !== '' && $pSerial !== '') 
                            ? "{$pWilaya}-{$pYear}-{$pSerial}" 
                            : implode('-', array_filter([$pWilaya, $pYear, $pSerial], fn($v) => $v !== ''));
                        $combinedPlate = str_replace('/', '-', $combinedPlate);
                        ?>
                        <div class="flex items-center justify-between">
                            <label class="block font-bold text-slate-700 dark:text-slate-200">
                                رقم لوحة الترقيم (Matricule) <span class="text-rose-500">*</span>
                            </label>
                            <span id="plateNumberBadgePreview" class="text-[10px] text-amber-700 dark:text-amber-400 font-bold bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 px-2 py-0.5 rounded-md font-mono select-none" dir="ltr"><?= !empty($combinedPlate) ? htmlspecialchars($combinedPlate) : '01-2000-025654' ?></span>
                        </div>
                        
                        <!-- 3-Segmented Plate Input (Auto-advancing with - strictly, no /) -->
                        <div class="flex items-center gap-1.5 p-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus-within:ring-2 focus-within:ring-amber-500/20 focus-within:border-amber-500 transition shadow-2xs" dir="ltr">
                            <!-- 1. الولاية (رقمين: 01 أو 47) -->
                            <input type="text" id="plateWilayaInput" name="plate_wilaya" inputmode="numeric" maxlength="2" placeholder="01"
                                   value="<?= htmlspecialchars($pWilaya) ?>"
                                   class="w-14 text-center py-2 text-xs font-mono font-bold text-slate-900 dark:text-white bg-slate-50 dark:bg-slate-800 hover:bg-white dark:hover:bg-slate-700 focus:bg-white dark:focus:bg-slate-700 rounded-lg border border-slate-200/80 dark:border-slate-700 focus:border-amber-500 focus:outline-none transition shadow-2xs"
                                   title="رقم الولاية (رقمين: مثلاً 01 أو 47)" autocomplete="off">

                            <!-- STRICTLY '-' SEPARATOR, NEVER '/' -->
                            <span class="text-slate-400 dark:text-slate-500 font-black text-sm select-none px-1">-</span>

                            <!-- 2. سنة الترقيم / الصنف (مثلاً 2000 أو 116) -->
                            <input type="text" id="plateYearInput" name="plate_year" inputmode="numeric" maxlength="4" placeholder="2000"
                                   value="<?= htmlspecialchars($pYear) ?>"
                                   class="w-20 text-center py-2 text-xs font-mono font-bold text-slate-900 dark:text-white bg-slate-50 dark:bg-slate-800 hover:bg-white dark:hover:bg-slate-700 focus:bg-white dark:focus:bg-slate-700 rounded-lg border border-slate-200/80 dark:border-slate-700 focus:border-amber-500 focus:outline-none transition shadow-2xs"
                                   title="سنة الترقيم / الصنف (مثلاً 2000 أو 116)" autocomplete="off">

                            <!-- STRICTLY '-' SEPARATOR, NEVER '/' -->
                            <span class="text-slate-400 dark:text-slate-500 font-black text-sm select-none px-1">-</span>

                            <!-- 3. الرقم التسلسلي (5 إلى 6 أرقام: مثلاً 025654) - Wide & unclipt -->
                            <input type="text" id="plateSerialInput" name="plate_serial" inputmode="numeric" maxlength="6" placeholder="025654"
                                   value="<?= htmlspecialchars($pSerial) ?>"
                                   class="flex-1 min-w-[120px] text-center py-2 text-xs font-mono font-bold text-slate-900 dark:text-white bg-slate-50 dark:bg-slate-800 hover:bg-white dark:hover:bg-slate-700 focus:bg-white dark:focus:bg-slate-700 rounded-lg border border-slate-200/80 dark:border-slate-700 focus:border-amber-500 focus:outline-none transition shadow-2xs"
                                   title="الرقم التسلسلي (5 إلى 6 أرقام: مثلاً 025654)" autocomplete="off">

                            <div class="px-2 flex items-center pointer-events-none select-none text-xs">
                                <span title="الجمهورية الجزائرية الديمقراطية الشعبية">🇩🇿</span>
                            </div>
                        </div>

                        <!-- Hidden input holding full plate number (strictly with -) -->
                        <input type="hidden" name="plate_number" id="plateNumberInput" required value="<?= htmlspecialchars($combinedPlate) ?>">

                        <p class="text-[10px] text-slate-500 dark:text-slate-400 flex items-center justify-between px-1 select-none font-medium">
                            <span>ولاية (01)</span>
                            <span>سنة / صنف (2000)</span>
                            <span>تسجيل (025654)</span>
                        </p>
                    </div>

                    <!-- 2. رقم الباب المعتمد (5 cols) -->
                    <div class="sm:col-span-5 space-y-1.5">
                        <?php
                        $curDoor = $editVehicle['door_number'] ?? '(02/22)-';
                        if (!empty($curDoor)) {
                            $cleanCurDoor = preg_replace('/^\(?0?2\/22\)?[-–—\s]*/u', '', $curDoor);
                            $cleanCurDoor = trim($cleanCurDoor);
                            $curDoor = '(02/22)-' . $cleanCurDoor;
                        } else {
                            $cleanCurDoor = '';
                            $curDoor = '(02/22)-';
                        }
                        ?>
                        <div class="flex items-center justify-between">
                            <label class="block font-bold text-slate-700 dark:text-slate-200">
                                رقم الباب المعتمد <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-amber-700 dark:text-amber-400 font-bold bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 px-1.5 py-0.5 rounded-md font-mono" dir="ltr">(02/22)-</span>
                        </div>
                        
                        <!-- LTR Addon Box: Impossible to flip -->
                        <div class="flex items-center rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden focus-within:ring-2 focus-within:ring-amber-500/20 focus-within:border-amber-500 transition shadow-2xs" dir="ltr">
                            <span class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold font-mono text-xs border-r border-slate-200 dark:border-slate-700 select-none shrink-0">
                                (02/22)-
                            </span>
                            <input type="text" id="doorNumberDigits" placeholder="75"
                                   value="<?= htmlspecialchars($cleanCurDoor) ?>"
                                   oninput="onDoorDigitsInput(this.value)"
                                   autocomplete="off"
                                   class="w-full px-3 py-2 text-xs font-mono font-bold text-slate-900 dark:text-white bg-transparent focus:outline-none text-left">
                        </div>
                        <input type="hidden" name="door_number" id="doorNumberInput" required value="<?= htmlspecialchars($curDoor) ?>">
                        <p class="text-[10px] text-slate-500 dark:text-slate-400">اكتب رقم الباب فقط (مثال: 75)</p>
                    </div>
                </div>

                <!-- Row 3: لون السيارة (Dedicated Spacious Color Section) -->
                <div class="p-3.5 rounded-2xl bg-slate-50/80 dark:bg-[#111827]/70 border border-slate-200/80 dark:border-slate-700/80 space-y-2.5 shadow-2xs">
                    <?php
                    $availableColors = [
                        ['name' => 'أصفر (Jaune)', 'val' => 'Jaune', 'bg' => '#FACC15', 'aliases' => ['jaune', 'أصفر', 'اصفر']],
                        ['name' => 'أبيض (Blanc)', 'val' => 'Blanc', 'bg' => '#FFFFFF', 'aliases' => ['blanc', 'أبيض', 'ابيض']],
                        ['name' => 'رمادي (Gris)', 'val' => 'Gris', 'bg' => '#94A3B8', 'aliases' => ['gris', 'رمادي', 'رمادي فاتح']],
                        ['name' => 'رمادي فضي (Gris Argenté)', 'val' => 'Gris Argenté', 'bg' => '#CBD5E1', 'aliases' => ['gris argenté', 'gris argente', 'فضي']],
                        ['name' => 'رمادي داكن (Anthracite)', 'val' => 'Gris Anthracite', 'bg' => '#475569', 'aliases' => ['gris anthracite', 'رمادي داكن', 'فحمي']],
                        ['name' => 'أسود (Noir)', 'val' => 'Noir', 'bg' => '#0F172A', 'aliases' => ['noir', 'أسود', 'اسود']],
                        ['name' => 'أزرق (Bleu)', 'val' => 'Bleu', 'bg' => '#3B82F6', 'aliases' => ['bleu', 'أزرق', 'ازرق']],
                        ['name' => 'أزرق داكن (Bleu Nuit)', 'val' => 'Bleu Nuit', 'bg' => '#1E3A8A', 'aliases' => ['bleu nuit', 'أزرق داكن', 'ازرق داكن', 'كحلي']],
                        ['name' => 'أخضر (Vert)', 'val' => 'Vert', 'bg' => '#22C55E', 'aliases' => ['vert', 'أخضر', 'اخضر']],
                        ['name' => 'أحمر (Rouge)', 'val' => 'Rouge', 'bg' => '#EF4444', 'aliases' => ['rouge', 'أحمر', 'احمر']],
                        ['name' => 'بيج (Beige)', 'val' => 'Beige', 'bg' => '#E7D7C1', 'aliases' => ['beige', 'بيج']],
                        ['name' => 'بني (Marron)', 'val' => 'Marron', 'bg' => '#78350F', 'aliases' => ['marron', 'بني']]
                    ];

                    $curColor = trim($editVehicle['color'] ?? 'Jaune');
                    $curColorLower = mb_strtolower($curColor, 'UTF-8');
                    
                    $activeBg = '#FACC15';
                    foreach ($availableColors as $c) {
                        if (strcasecmp($curColor, $c['val']) === 0 || strcasecmp($curColor, $c['name']) === 0 || in_array($curColorLower, $c['aliases'], true)) {
                            $activeBg = $c['bg'];
                            break;
                        }
                    }
                    ?>
                    <div class="flex items-center justify-between">
                        <label class="block font-bold text-slate-700 dark:text-slate-200 text-xs flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            <span>لون السيارة المعتمد <span class="text-rose-500">*</span></span>
                        </label>
                        <span class="text-[11px] text-slate-700 dark:text-slate-200 font-bold flex items-center gap-1.5 px-2 py-0.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-2xs" id="colorSelectedPreviewBadge">
                            <span class="w-3 h-3 rounded-full border border-slate-300 dark:border-slate-600 shadow-2xs shrink-0" id="colorBadgeDot" style="background-color: <?= $activeBg ?>;"></span>
                            <span id="colorBadgeText"><?= htmlspecialchars($curColor) ?></span>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                        <!-- Color Dropdown (5 cols) -->
                        <div class="sm:col-span-5">
                            <select id="colorSelectDropdown" onchange="onColorSelectChanged(this.value)"
                                    class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 shadow-2xs">
                                <option value="">-- اختر لون السيارة --</option>
                                <?php foreach ($availableColors as $c): ?>
                                    <?php 
                                    $isSelected = (strcasecmp($curColor, $c['val']) === 0 || strcasecmp($curColor, $c['name']) === 0 || in_array($curColorLower, $c['aliases'], true));
                                    ?>
                                    <option value="<?= htmlspecialchars($c['val']) ?>" <?= $isSelected ? 'selected' : '' ?> data-bg="<?= $c['bg'] ?>">
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom">🎨 لون آخر مخصص...</option>
                            </select>

                            <!-- Color Text Input (revealed only if custom or needed) -->
                            <input type="text" name="color" id="vehicleColorInput" required 
                                   value="<?= htmlspecialchars($curColor) ?>"
                                   placeholder="اكتب لون السيارة..." 
                                   oninput="onColorInputChanged(this.value)"
                                   class="w-full mt-1.5 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-bold transition shadow-2xs <?= (in_array($curColorLower, array_merge(...array_column($availableColors, 'aliases')), true) || in_array($curColor, array_column($availableColors, 'val'), true)) ? 'hidden' : '' ?>">
                        </div>

                        <!-- Interactive Quick Color Dots / Swatches (7 cols, single horizontal bar) -->
                        <div class="sm:col-span-7 flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1" id="colorSwatchesContainer">
                            <?php foreach ($availableColors as $c): ?>
                                <?php $isBtnActive = (strcasecmp($curColor, $c['val']) === 0 || in_array($curColorLower, $c['aliases'], true)); ?>
                                <button type="button" 
                                        onclick="selectColorSwatch('<?= htmlspecialchars($c['val'], ENT_QUOTES) ?>', '<?= $c['bg'] ?>')"
                                        title="<?= htmlspecialchars($c['name']) ?>"
                                        class="color-swatch-btn w-6 h-6 rounded-full border border-slate-300 dark:border-slate-600 shadow-2xs hover:scale-125 focus:scale-125 focus:outline-none transition-transform cursor-pointer shrink-0 <?= $isBtnActive ? 'ring-2 ring-amber-500 scale-110' : '' ?>"
                                        style="background-color: <?= $c['bg'] ?>;">
                                    <span class="sr-only"><?= htmlspecialchars($c['name']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Row 4: حالة المركبة في الأسطول (نشطة أو معلقة) -->
                <?php
                $vStatus = $editVehicle['status'] ?? 'active';
                $vSuspReason = $editVehicle['suspended_reason'] ?? '';
                ?>
                <div class="p-3.5 rounded-2xl bg-slate-50/80 dark:bg-[#111827]/70 border border-slate-200/80 dark:border-slate-700/80 space-y-3 shadow-2xs">
                    <div class="flex items-center justify-between">
                        <label class="block font-bold text-slate-700 dark:text-slate-200 text-xs flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            <span>حالة المركبة في الأسطول والتشغيل <span class="text-rose-500">*</span></span>
                        </label>
                        <span class="text-[10px] text-slate-400">تحديد قابلية تشغيل السيارة أو تعليقها</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="relative flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition select-none <?= $vStatus === 'active' ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-emerald-400 dark:border-emerald-600' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300' ?>" id="statusLabelActive">
                            <input type="radio" name="status" value="active" <?= $vStatus === 'active' ? 'checked' : '' ?> onchange="onStatusRadioChange('active')" class="w-4 h-4 text-emerald-600 focus:ring-emerald-500 border-slate-300">
                            <div>
                                <div class="font-bold text-slate-900 dark:text-white text-xs flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span>مركبة نشطة (Active)</span>
                                </div>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">جاهزة ومصرح لها بالعمل ونقل الركاب</p>
                            </div>
                        </label>

                        <label class="relative flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition select-none <?= $vStatus === 'suspended' ? 'bg-rose-50/50 dark:bg-rose-950/20 border-rose-400 dark:border-rose-600' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300' ?>" id="statusLabelSuspended">
                            <input type="radio" name="status" value="suspended" <?= $vStatus === 'suspended' ? 'checked' : '' ?> onchange="onStatusRadioChange('suspended')" class="w-4 h-4 text-rose-600 focus:ring-rose-500 border-slate-300">
                            <div>
                                <div class="font-bold text-slate-900 dark:text-white text-xs flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                    <span>مركبة معلقة (Suspended)</span>
                                </div>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">موقوفة مؤقتاً لعدم استيفاء الشروط أو عقوبة</p>
                            </div>
                        </label>
                    </div>

                    <!-- Suspended reason field (shown when suspended is selected) -->
                    <div id="suspendedReasonBox" class="<?= $vStatus === 'suspended' ? '' : 'hidden' ?> pt-1">
                        <label class="block font-bold text-rose-700 dark:text-rose-400 text-xs mb-1">
                            سبب التعليق الإداري أو الميداني:
                        </label>
                        <input type="text" name="suspended_reason" id="suspendedReasonInput" value="<?= htmlspecialchars($vSuspReason) ?>" placeholder="مثال: فحص تقني غير مطابق، عدم تجديد التأمين، قرار ولائي..." class="w-full px-3 py-2 rounded-xl border border-rose-300 dark:border-rose-800 bg-white dark:bg-slate-900 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition shadow-2xs">
                    </div>
                </div>
            </div>

            <!-- ==============================================
                 3. تواريخ الصلاحية وتجهيزات الأمان والعداد
                 ============================================== -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-4 space-y-4">
                <span class="block font-black text-slate-800 dark:text-slate-200 text-xs">تواريخ الصلاحية والوثائق وتجهيزات الأمان:</span>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">تاريخ انتهاء المراقبة التقنية (Contrôle Technique)</label>
                        <input type="date" name="technical_control_expiry" value="<?= htmlspecialchars($editVehicle['technical_control_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white dark:bg-slate-900 font-num transition shadow-2xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">تاريخ انتهاء التأمين الشامل</label>
                        <input type="date" name="insurance_expiry" value="<?= htmlspecialchars($editVehicle['insurance_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white dark:bg-slate-900 font-num transition shadow-2xs">
                    </div>
                </div>

                <?php 
                    $gplMode = (($editVehicle['gpl_text'] ?? '') === 'بدون GPL') ? 'without' : 'with';
                    $sacMode = (($editVehicle['saccuriometre_text'] ?? '') === 'بدون عداد' || ($editVehicle['saccuriometre_text'] ?? '') === 'Sans Saccuriomètre') ? 'without' : 'with';
                ?>

                <!-- 1. قارورة الإطفاء -->
                <div class="p-4 rounded-2xl bg-slate-50/90 dark:bg-[#111827]/70 border border-slate-200/90 dark:border-slate-700/80 space-y-2.5 shadow-2xs">
                    <div class="flex items-center justify-between border-b border-slate-200/80 dark:border-slate-700/60 pb-2">
                        <span class="font-black text-slate-900 dark:text-white text-xs flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                            <span>قارورة الإطفاء (طفاية الحريق)</span>
                        </span>
                        <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400">فحص وتعبئة دورية</span>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">انتهاء صلاحية قارورة الإطفاء</label>
                        <input type="date" name="extinguisher_expiry" value="<?= htmlspecialchars($editVehicle['extinguisher_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white dark:bg-slate-900 font-num transition shadow-2xs">
                    </div>
                </div>

                <!-- 2. فحص سيرغاز GPL: خيارين فقط (مع / بدون) -->
                <div class="p-4 rounded-2xl bg-slate-50/90 dark:bg-[#111827]/70 border border-slate-200/90 dark:border-slate-700/80 space-y-3.5 shadow-2xs">
                    <div class="flex items-center justify-between border-b border-slate-200/80 dark:border-slate-700/60 pb-2">
                        <span class="font-black text-slate-900 dark:text-white text-xs flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <span>خزان سيرغاز GPL</span>
                        </span>

                        <!-- خيارين فقط: مع / بدون -->
                        <div class="inline-flex p-0.5 rounded-xl bg-slate-200/80 dark:bg-slate-800 text-xs font-bold shadow-2xs">
                            <label class="cursor-pointer">
                                <input type="radio" name="gpl_mode" value="with" class="sr-only" <?= $gplMode === 'with' ? 'checked' : '' ?> onchange="onGplModeChanged('with')">
                                <span id="gplPillWith" class="inline-block px-3 py-1.5 rounded-lg transition <?= $gplMode === 'with' ? 'bg-white dark:bg-slate-700 text-amber-700 dark:text-amber-300 shadow-2xs font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                                    مع GPL
                                </span>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="gpl_mode" value="without" class="sr-only" <?= $gplMode === 'without' ? 'checked' : '' ?> onchange="onGplModeChanged('without')">
                                <span id="gplPillWithout" class="inline-block px-3 py-1.5 rounded-lg transition <?= $gplMode === 'without' ? 'bg-white dark:bg-slate-700 text-rose-700 dark:text-rose-300 shadow-2xs font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                                    بدون GPL
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Hidden input to store gpl_text for DB compatibility -->
                    <input type="hidden" name="gpl_text" id="gplTextInput" value="<?= $gplMode === 'without' ? 'بدون GPL' : '' ?>">

                    <!-- تاريخ فحص شهادة GPL (يظهر فقط عند اختيار مع GPL) -->
                    <div id="gplExpiryContainer" class="<?= $gplMode === 'without' ? 'hidden' : '' ?> space-y-1.5">
                        <label class="block font-bold text-slate-700 dark:text-slate-300">انتهاء صلاحية شهادة مطابقة GPL</label>
                        <input type="date" name="gpl_expiry" id="gplExpiryInput" value="<?= htmlspecialchars($editVehicle['gpl_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white dark:bg-slate-900 font-num transition shadow-2xs">
                    </div>

                    <!-- إشعار بدون GPL -->
                    <div id="gplWithoutBanner" class="<?= $gplMode === 'without' ? '' : 'hidden' ?> p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300 flex items-center gap-2">
                        <span class="text-amber-500 font-bold">ℹ️</span>
                        <span>المركبة مسجلة كمركبة <strong>بدون تجهيز سيرغاز GPL</strong> (بنزين فقط).</span>
                    </div>
                </div>

                <!-- 3. عداد التاكسي (الساكوريومتر): خيارين فقط (مع / بدون) -->
                <div class="p-4 rounded-2xl bg-slate-50/90 dark:bg-[#111827]/70 border border-slate-200/90 dark:border-slate-700/80 space-y-3.5 shadow-2xs">
                    <div class="flex items-center justify-between border-b border-slate-200/80 dark:border-slate-700/60 pb-2">
                        <span class="font-black text-slate-900 dark:text-white text-xs flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                            <span>عداد التاكسي (الساكوريومتر)</span>
                        </span>

                        <!-- خيارين فقط: مع / بدون -->
                        <div class="inline-flex p-0.5 rounded-xl bg-slate-200/80 dark:bg-slate-800 text-xs font-bold shadow-2xs">
                            <label class="cursor-pointer">
                                <input type="radio" name="taximeter_mode" value="with" class="sr-only" <?= $sacMode === 'with' ? 'checked' : '' ?> onchange="onTaximeterModeChanged('with')">
                                <span id="sacPillWith" class="inline-block px-3 py-1.5 rounded-lg transition <?= $sacMode === 'with' ? 'bg-white dark:bg-slate-700 text-blue-700 dark:text-blue-300 shadow-2xs font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                                    مع عداد
                                </span>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="taximeter_mode" value="without" class="sr-only" <?= $sacMode === 'without' ? 'checked' : '' ?> onchange="onTaximeterModeChanged('without')">
                                <span id="sacPillWithout" class="inline-block px-3 py-1.5 rounded-lg transition <?= $sacMode === 'without' ? 'bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-300 shadow-2xs font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                                    بدون عداد
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Hidden input to store saccuriometre_text for DB compatibility -->
                    <input type="hidden" name="saccuriometre_text" id="sacTextInput" value="<?= $sacMode === 'without' ? 'بدون عداد' : '' ?>">

                    <!-- تاريخ انتهاء شهادة العداد (يظهر فقط عند اختيار مع عداد) -->
                    <div id="sacExpiryContainer" class="<?= $sacMode === 'without' ? 'hidden' : '' ?> space-y-1.5">
                        <label class="block font-bold text-slate-700 dark:text-slate-300">تاريخ انتهاء شهادة العداد</label>
                        <input type="date" name="saccuriometre_expiry" id="sacExpiryInput" value="<?= htmlspecialchars($editVehicle['saccuriometre_expiry'] ?? '') ?>"
                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white dark:bg-slate-900 font-num transition shadow-2xs">
                    </div>

                    <!-- إشعار بدون عداد -->
                    <div id="sacWithoutBanner" class="<?= $sacMode === 'without' ? '' : 'hidden' ?> p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300 flex items-center gap-2">
                        <span class="text-blue-500 font-bold">ℹ️</span>
                        <span>المركبة مسجلة <strong>بدون عداد تاكسي</strong>.</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="closeVehicleModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    إلغاء
                </button>
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
    <div class="bg-white dark:bg-[#161d27] max-w-md w-full rounded-2xl p-6 border border-slate-200 dark:border-slate-800 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center gap-2">
                <span class="text-lg">⚙️</span>
                <h4 class="font-bold text-slate-900 dark:text-white text-sm">إضافة ماركة أو طراز في الإعدادات</h4>
            </div>
            <button type="button" onclick="closeQuickPresetModal()" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 text-base">✕</button>
        </div>

        <p class="text-[11px] text-slate-500 dark:text-slate-400">
            أدخل الماركة والطرازات المعتمدة ليتم حفظها في إعدادات المنصة واعتمادها فورياً لجميع سيارات الأجرة.
        </p>

        <div class="space-y-3 text-xs">
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-200 mb-1">اسم الماركة أو الصانع <span class="text-rose-500">*</span></label>
                <input type="text" id="quickBrandInput" placeholder="مثال: Chery, Geely, DFSK, Suzuki..." 
                       class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 font-bold focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white dark:bg-slate-900 text-slate-900 dark:text-white">
            </div>
            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-200 mb-1">الموديلات المعتمدة (مفصولة بفواصل)</label>
                <input type="text" id="quickModelsInput" placeholder="مثال: Tiggo 2, Tiggo 4, Arrizo 5" 
                       class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white dark:bg-slate-900 text-slate-900 dark:text-white">
            </div>
        </div>

        <div class="flex items-center justify-between pt-3 border-t border-slate-100 dark:border-slate-800">
            <?php if (is_admin()): ?>
            <a href="/dashboard/settings.php#fleet-presets" target="_blank" class="text-[11px] text-amber-600 dark:text-amber-400 hover:underline font-semibold">
                إدارة الإعدادات العامة ↗
            </a>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
            <div class="flex items-center gap-2">
                <button type="button" onclick="closeQuickPresetModal()" class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-xs hover:bg-slate-50 dark:hover:bg-slate-800">
                    إلغاء
                </button>
                <button type="button" onclick="saveQuickPreset()" id="savePresetBtn" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs shadow-2xs">
                    حفظ واعتماد
                </button>
            </div>
        </div>
    </div>
<!-- ==============================================
     Vehicle QR Code & Window Sticker Modal
     ============================================== -->
<div id="vehicleQrModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-3 sm:p-6 overflow-y-auto">
    <div class="bg-white dark:bg-[#161d27] max-w-md w-full rounded-3xl p-5 sm:p-6 border border-slate-200 dark:border-slate-800 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-base">📱</span>
                <div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">رمز الاستجابة السريعة (QR) وملصق المركبة</h3>
                    <p class="text-[10px] text-slate-500 dark:text-slate-400">ملصق زجاج سيارة الأجرة للتحقق وتقييم السائق الميداني</p>
                </div>
            </div>
            <button type="button" onclick="closeVehicleQrModal()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Printable Taxi Sticker Box -->
        <div id="printableVehicleSticker" class="p-4 rounded-2xl bg-gradient-to-b from-amber-50 to-white dark:from-slate-900 dark:to-[#161d27] border-2 border-amber-400 dark:border-amber-500/40 text-center space-y-3 relative overflow-hidden shadow-xs">
            <!-- Taxi Header Strip -->
            <div class="bg-amber-400 text-slate-950 px-3 py-1.5 rounded-xl font-bold flex items-center justify-between text-xs font-brand shadow-xs">
                <span class="flex items-center gap-1.5">
                    <span>🚕</span>
                    <span>تاكسي نقيلو • TAXI NAGUILOU</span>
                </span>
                <span id="qrStickerStatus" class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-slate-950 text-amber-400">نشطة ومعتمدة</span>
            </div>

            <!-- Vehicle Identification Strip -->
            <div class="grid grid-cols-2 gap-2 text-xs">
                <div class="p-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <span class="text-[10px] text-slate-400 block font-bold mb-0.5">رقم الباب (DTW)</span>
                    <span id="qrStickerDoor" class="font-mono font-black text-amber-600 dark:text-amber-400 text-sm" dir="ltr">(02/22)-75</span>
                </div>
                <div class="p-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <span class="text-[10px] text-slate-400 block font-bold mb-0.5">الترقيم (Matricule)</span>
                    <span id="qrStickerPlate" class="font-mono font-bold text-slate-900 dark:text-white text-xs" dir="ltr">16-2022-012345</span>
                </div>
            </div>

            <!-- Vehicle Name & Driver Info -->
            <div class="text-xs space-y-0.5 text-slate-700 dark:text-slate-300">
                <div class="font-bold text-slate-900 dark:text-white flex items-center justify-center gap-1.5">
                    <span id="qrStickerCar">Dacia Logan</span>
                    <span class="text-slate-400">•</span>
                    <span id="qrStickerColor" class="text-slate-500">أصفر</span>
                </div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-center gap-1">
                    <span>السائق المعتمد:</span>
                    <span id="qrStickerDriver" class="font-bold text-slate-800 dark:text-slate-200">محمد أمين</span>
                </div>
            </div>

            <!-- QR Code Canvas Display -->
            <div class="flex justify-center py-1">
                <div id="vehicleQrCodeContainer" class="p-2.5 bg-white rounded-2xl border-2 border-slate-200 shadow-sm inline-block"></div>
            </div>

            <p class="text-[10px] text-slate-500 dark:text-slate-400 leading-tight select-none">
                امسح الكود بكاميرا الهاتف للتأكد من صلاحية المركبة وتقييم السائق مباشرة للإدارة ⭐
            </p>
        </div>

        <!-- Action Buttons -->
        <div class="grid grid-cols-2 gap-2 pt-1 text-xs">
            <button type="button" onclick="printVehicleSticker()" class="py-2.5 px-3 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold transition flex items-center justify-center gap-1.5 shadow-xs cursor-pointer">
                <span>🖨️ طباعة الملصق</span>
            </button>
            <button type="button" onclick="copyVehicleVerifyLink()" id="copyLinkBtn" class="py-2.5 px-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition flex items-center justify-center gap-1.5 shadow-xs cursor-pointer">
                <span>📋 نسخ الرابط</span>
            </button>
        </div>

        <div>
            <a id="qrOpenVerifyPageBtn" href="#" target="_blank" class="w-full py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold text-xs flex items-center justify-center gap-1.5 transition">
                <span>معاينة صفحة المسافر والتقييم ↗</span>
            </a>
        </div>
    </div>
</div>

<!-- ==============================================
     Suspend Vehicle Confirmation Modal
     ============================================== -->
<div id="suspendVehicleModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white dark:bg-[#161d27] max-w-md w-full rounded-3xl p-5 sm:p-6 border border-slate-200 dark:border-slate-800 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-xl bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold text-base">⏸️</span>
                <h3 class="font-bold text-slate-900 dark:text-white text-sm">تعليق تشغيل مركبة الأجرة</h3>
            </div>
            <button type="button" onclick="closeSuspendVehicleModal()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">✕</button>
        </div>

        <form method="POST" action="" class="space-y-3.5 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action_type" value="toggle_status">
            <input type="hidden" name="new_status" value="suspended">
            <input type="hidden" name="vehicle_id" id="suspendVehicleIdInput" value="">

            <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                هل أنت متأكد من تعليق تشغيل المركبة: <strong id="suspendVehicleTitle" class="text-rose-600 dark:text-rose-400"></strong>؟
                <br>
                <span class="text-[11px] text-slate-400">عند التعليق، سيظهر تنبيه للمسافرين والمفتشين عند مسح الباركود، وسيتم توقيف المركبة في سجل التشغيل.</span>
            </p>

            <div>
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">سبب التعليق الإداري أو الميداني (اختياري)</label>
                <input type="text" name="suspended_reason" placeholder="مثال: انتهاء المراقبة التقنية، عدم تجديد التأمين، قرار ولائي..." class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition">
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="closeSuspendVehicleModal()" class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold transition shadow-xs cursor-pointer">
                    تأكيد تعليق المركبة
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; // is_admin || is_owner ?>

<script src="/assets/js/qrcode.min.js"></script>
<script>
// Driver and Preset state
const ALL_DRIVERS = <?= json_encode($drivers, JSON_UNESCAPED_UNICODE) ?>;
let VEHICLE_PRESETS = <?= json_encode($vehiclePresets, JSON_UNESCAPED_UNICODE) ?>;
let driverSearchDebounce = null;

function openVehicleModal() {
    const modal = document.getElementById('addVehicleModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        const digitsDoor = document.getElementById('doorNumberDigits');
        const hiddenDoor = document.getElementById('doorNumberInput');
        if (digitsDoor && hiddenDoor) {
            hiddenDoor.value = '(02/22)-' + digitsDoor.value.trim();
        }
        const wilayaInput = document.getElementById('plateWilayaInput');
        if (wilayaInput && !wilayaInput.value) {
            setTimeout(() => wilayaInput.focus(), 120);
        }
    }
}

function closeVehicleModal() {
    const modal = document.getElementById('addVehicleModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

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
        <div onclick="selectDriver('${slot}', null)" class="p-2.5 hover:bg-slate-100 dark:hover:bg-slate-800/80 cursor-pointer flex items-center justify-between text-slate-600 dark:text-slate-300 font-bold border-b border-slate-100 dark:border-slate-800 transition">
            <span>🚫 ${emptyText}</span>
            <span class="text-[10px] text-slate-400 dark:text-slate-500">تفريغ</span>
        </div>
    `;

    if (list.length === 0) {
        html += `
            <div class="p-3 text-center text-slate-400 dark:text-slate-500 text-xs">
                لا يوجد سائق مطابق لبحثك
            </div>
        `;
    } else {
        html += list.map(d => {
            const stageLabel = d.probation_stage === 'trial_1' ? 'تجريبية 1' :
                               d.probation_stage === 'trial_2' ? 'تجريبية 2' :
                               d.probation_stage === 'trial_3' ? 'تجريبية 3' : 'سائق رسمي';
            const stageColor = d.probation_stage === 'official' 
                ? 'bg-emerald-50 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' 
                : 'bg-amber-50 dark:bg-amber-950/70 text-amber-900 dark:text-amber-300 border-amber-200 dark:border-amber-800';
            const plateNote = d.current_plate 
                ? `<span class="text-[9px] text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/60 px-1.5 py-0.5 rounded border border-amber-200 dark:border-amber-800 font-mono" dir="ltr">يسير: ${d.current_plate}</span>` 
                : '';

            // Clean data for onclick
            const safeD = JSON.stringify(d).replace(/"/g, '&quot;');
            return `
                <div onclick='selectDriver("${slot}", ${safeD})' class="p-2.5 hover:bg-amber-50/70 dark:hover:bg-slate-800/80 cursor-pointer flex items-center justify-between transition border-b border-slate-100 dark:border-slate-800/60 last:border-0">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-bold flex items-center justify-center text-xs shrink-0 border border-slate-200 dark:border-slate-700">
                            👤
                        </div>
                        <div>
                            <div class="font-bold text-slate-900 dark:text-white text-xs flex items-center gap-1.5">
                                <span class="font-bold text-slate-900 dark:text-white">${d.full_name}</span>
                                <span class="text-[9px] px-1.5 py-0.2 rounded border font-semibold ${stageColor}">${stageLabel}</span>
                                ${plateNote}
                            </div>
                            <div class="text-[10px] text-slate-500 dark:text-slate-400 font-num mt-0.5">
                                هاتف: ${d.phone || '-'} • رخصة: ${d.license_number || '-'}
                            </div>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold text-amber-800 dark:text-amber-300 bg-amber-100 dark:bg-amber-950/80 border border-amber-200 dark:border-amber-700 px-2 py-0.5 rounded-md">اختيار</span>
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
    if (brandInput) {
        brandInput.value = brandName;
        brandInput.focus();
    }
    updateBrandPillsHighlight(brandName);
    updateModelsForBrand(brandName);
}

function onBrandInputChanged(brandName) {
    updateBrandPillsHighlight(brandName);
    updateModelsForBrand(brandName);
}

function updateBrandPillsHighlight(brandName) {
    const bLower = (brandName || '').trim().toLowerCase();
    document.querySelectorAll('.brand-pill-btn').forEach(btn => {
        const text = btn.textContent.trim().toLowerCase();
        if (bLower && text === bLower) {
            btn.className = 'brand-pill-btn px-2.5 py-1 rounded-lg text-[11px] font-bold border transition cursor-pointer bg-amber-500 text-slate-950 border-amber-500 shadow-2xs';
        } else {
            btn.className = 'brand-pill-btn px-2.5 py-1 rounded-lg text-[11px] font-bold border transition cursor-pointer bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-amber-100 dark:hover:bg-amber-900/40 hover:text-amber-950 dark:hover:text-amber-300';
        }
    });
}

function updateModelsForBrand(brandName) {
    const modelDatalist = document.getElementById('modelDatalist');
    const modelPills = document.getElementById('modelPillsContainer');
    if (!modelPills) return;

    // Normalize brand lookup (case-insensitive)
    let models = [];
    if (brandName) {
        const foundKey = Object.keys(VEHICLE_PRESETS).find(k => k.toLowerCase() === brandName.trim().toLowerCase());
        if (foundKey && VEHICLE_PRESETS[foundKey]) {
            models = VEHICLE_PRESETS[foundKey];
        }
    }

    // Populate model datalist
    if (modelDatalist) {
        modelDatalist.innerHTML = models.map(m => `<option value="${m}"></option>`).join('');
    }

    // Populate model pills
    if (models.length > 0) {
        const currentModel = document.getElementById('vehicleModelInput')?.value || '';
        modelPills.innerHTML = models.map(m => {
            const isActive = currentModel.toLowerCase() === m.toLowerCase();
            const activeClasses = 'bg-amber-500 text-slate-950 border-amber-500 shadow-2xs';
            const inactiveClasses = 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-amber-100 dark:hover:bg-amber-900/40 hover:text-amber-950 dark:hover:text-amber-300';
            return `
                <button type="button" onclick="selectModelPreset('${m}')" 
                        class="model-pill-btn px-2.5 py-1 rounded-lg text-[11px] font-bold border transition cursor-pointer ${isActive ? activeClasses : inactiveClasses}">
                    ${m}
                </button>
            `;
        }).join('');
    } else {
        modelPills.innerHTML = '';
    }
}

function selectModelPreset(modelName) {
    const modelInput = document.getElementById('vehicleModelInput');
    if (modelInput) {
        modelInput.value = modelName;
        modelInput.focus();
    }
    const mLower = (modelName || '').trim().toLowerCase();
    document.querySelectorAll('.model-pill-btn').forEach(btn => {
        const text = btn.textContent.trim().toLowerCase();
        if (mLower && text === mLower) {
            btn.className = 'model-pill-btn px-2.5 py-1 rounded-lg text-[11px] font-bold border transition cursor-pointer bg-amber-500 text-slate-950 border-amber-500 shadow-2xs';
        } else {
            btn.className = 'model-pill-btn px-2.5 py-1 rounded-lg text-[11px] font-bold border transition cursor-pointer bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-amber-100 dark:hover:bg-amber-900/40 hover:text-amber-950 dark:hover:text-amber-300';
        }
    });
}

function onDoorDigitsInput(val) {
    const hidden = document.getElementById('doorNumberInput');
    const clean = (val || '').replace(/[^\d\w\-\/]/g, '').trim();
    if (hidden) {
        hidden.value = '(02/22)-' + clean;
    }
}

// ==========================================
// 2.5 Vehicle Color Selection & Swatches
// ==========================================
function onColorSelectChanged(val) {
    const input = document.getElementById('vehicleColorInput');
    const select = document.getElementById('colorSelectDropdown');
    const dot = document.getElementById('colorBadgeDot');
    const text = document.getElementById('colorBadgeText');

    if (val === 'custom') {
        if (input) {
            input.value = '';
            input.focus();
        }
        if (dot) dot.style.backgroundColor = '#CBD5E1';
        if (text) text.textContent = 'مخصص';
        updateActiveColorSwatch('');
        return;
    }

    if (val && input) {
        input.value = val;
        const opt = select?.selectedOptions[0];
        const bg = opt?.getAttribute('data-bg') || '#FACC15';
        if (dot) dot.style.backgroundColor = bg;
        if (text) text.textContent = val;
        updateActiveColorSwatch(val);
    }
}

function selectColorSwatch(val, bg) {
    const input = document.getElementById('vehicleColorInput');
    const select = document.getElementById('colorSelectDropdown');
    const dot = document.getElementById('colorBadgeDot');
    const text = document.getElementById('colorBadgeText');

    if (input) input.value = val;
    if (select) {
        let found = false;
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === val) {
                select.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            const customOpt = select.querySelector('option[value="custom"]');
            if (customOpt) select.value = 'custom';
        }
    }
    if (dot) dot.style.backgroundColor = bg;
    if (text) text.textContent = val;
    updateActiveColorSwatch(val);
}

function onColorInputChanged(val) {
    const select = document.getElementById('colorSelectDropdown');
    const dot = document.getElementById('colorBadgeDot');
    const text = document.getElementById('colorBadgeText');

    val = val.trim();
    if (text) text.textContent = val || '—';

    let matched = false;
    if (select) {
        for (let i = 0; i < select.options.length; i++) {
            const optVal = select.options[i].value;
            if (optVal && optVal.toLowerCase() === val.toLowerCase()) {
                select.selectedIndex = i;
                const bg = select.options[i].getAttribute('data-bg') || '#CBD5E1';
                if (dot) dot.style.backgroundColor = bg;
                updateActiveColorSwatch(optVal);
                matched = true;
                break;
            }
        }
        if (!matched) {
            const customOpt = select.querySelector('option[value="custom"]');
            if (customOpt) select.value = 'custom';
            if (dot) dot.style.backgroundColor = '#CBD5E1';
            updateActiveColorSwatch('');
        }
    }
}

function updateActiveColorSwatch(activeVal) {
    document.querySelectorAll('.color-swatch-btn').forEach(btn => {
        const title = btn.getAttribute('title') || '';
        const onclickAttr = btn.getAttribute('onclick') || '';
        if (activeVal && (title.toLowerCase().includes(activeVal.toLowerCase()) || onclickAttr.toLowerCase().includes(activeVal.toLowerCase()))) {
            btn.classList.add('ring-2', 'ring-amber-500', 'scale-110');
        } else {
            btn.classList.remove('ring-2', 'ring-amber-500', 'scale-110');
        }
    });
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
            
            // Re-render brand datalist & pills
            const brandDatalist = document.getElementById('brandDatalist');
            if (brandDatalist) {
                brandDatalist.innerHTML = Object.keys(VEHICLE_PRESETS).map(b => `<option value="${b}"></option>`).join('');
            }
            const brandPills = document.getElementById('brandPillsContainer');
            if (brandPills) {
                brandPills.innerHTML = Object.keys(VEHICLE_PRESETS).slice(0, 8).map(b => `
                    <button type="button" onclick="selectBrandPreset('${b}')" 
                            class="brand-pill-btn px-2.5 py-1 rounded-lg text-[11px] font-bold border transition cursor-pointer ${b.toLowerCase() === brand.toLowerCase() ? 'bg-amber-500 text-slate-950 border-amber-500 shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-amber-100 dark:hover:bg-amber-900/40 hover:text-amber-950 dark:hover:text-amber-300'}">
                        ${b}
                    </button>
                `).join('');
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
// 4. Equipment Actions: GPL & Taximeter (خيارين فقط: مع / بدون)
// ==========================================
function onGplModeChanged(mode) {
    const textInput = document.getElementById('gplTextInput');
    const expiryInput = document.getElementById('gplExpiryInput');
    const container = document.getElementById('gplExpiryContainer');
    const banner = document.getElementById('gplWithoutBanner');
    const pillWith = document.getElementById('gplPillWith');
    const pillWithout = document.getElementById('gplPillWithout');

    if (mode === 'without') {
        if (textInput) textInput.value = 'بدون GPL';
        if (expiryInput) expiryInput.value = '';
        if (container) container.classList.add('hidden');
        if (banner) banner.classList.remove('hidden');
        if (pillWith) {
            pillWith.className = 'inline-block px-3 py-1.5 rounded-lg transition text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white';
        }
        if (pillWithout) {
            pillWithout.className = 'inline-block px-3 py-1.5 rounded-lg transition bg-white dark:bg-slate-700 text-rose-700 dark:text-rose-300 shadow-2xs font-bold';
        }
    } else {
        if (textInput) textInput.value = '';
        if (container) container.classList.remove('hidden');
        if (banner) banner.classList.add('hidden');
        if (pillWith) {
            pillWith.className = 'inline-block px-3 py-1.5 rounded-lg transition bg-white dark:bg-slate-700 text-amber-700 dark:text-amber-300 shadow-2xs font-bold';
        }
        if (pillWithout) {
            pillWithout.className = 'inline-block px-3 py-1.5 rounded-lg transition text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white';
        }
        if (expiryInput) expiryInput.focus();
    }
}

function onTaximeterModeChanged(mode) {
    const textInput = document.getElementById('sacTextInput');
    const expiryInput = document.getElementById('sacExpiryInput');
    const container = document.getElementById('sacExpiryContainer');
    const banner = document.getElementById('sacWithoutBanner');
    const pillWith = document.getElementById('sacPillWith');
    const pillWithout = document.getElementById('sacPillWithout');

    if (mode === 'without') {
        if (textInput) textInput.value = 'بدون عداد';
        if (expiryInput) expiryInput.value = '';
        if (container) container.classList.add('hidden');
        if (banner) banner.classList.remove('hidden');
        if (pillWith) {
            pillWith.className = 'inline-block px-3 py-1.5 rounded-lg transition text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white';
        }
        if (pillWithout) {
            pillWithout.className = 'inline-block px-3 py-1.5 rounded-lg transition bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-300 shadow-2xs font-bold';
        }
    } else {
        if (textInput) textInput.value = '';
        if (container) container.classList.remove('hidden');
        if (banner) banner.classList.add('hidden');
        if (pillWith) {
            pillWith.className = 'inline-block px-3 py-1.5 rounded-lg transition bg-white dark:bg-slate-700 text-blue-700 dark:text-blue-300 shadow-2xs font-bold';
        }
        if (pillWithout) {
            pillWithout.className = 'inline-block px-3 py-1.5 rounded-lg transition text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white';
        }
        if (expiryInput) expiryInput.focus();
    }
}

function setWithoutGpl() { onGplModeChanged('without'); }
function setWithoutTaximeter() { onTaximeterModeChanged('without'); }

// On load: initialize brand, models, door prefix, and plate listeners
document.addEventListener('DOMContentLoaded', function() {
    const currentBrand = document.getElementById('vehicleBrandInput')?.value;
    if (currentBrand) {
        updateModelsForBrand(currentBrand);
        const currentModel = document.getElementById('vehicleModelInput')?.value;
        if (currentModel) {
            selectModelPreset(currentModel);
        }
    }

    // Door Number: Keep hidden input synchronized with door digits
    const doorDigits = document.getElementById('doorNumberDigits');
    if (doorDigits) {
        doorDigits.addEventListener('input', function() {
            onDoorDigitsInput(this.value);
        });
        doorDigits.addEventListener('blur', function() {
            onDoorDigitsInput(this.value);
        });
        // Initial sync
        onDoorDigitsInput(doorDigits.value);
    }

    // Algerian License Plate: 3-part auto-advance separated by '-' (e.g. 00123-116-47)
    (function initPlateNumberAutoAdvance() {
        const wilayaInput = document.getElementById('plateWilayaInput');
        const yearInput = document.getElementById('plateYearInput');
        const serialInput = document.getElementById('plateSerialInput');
        const hiddenInput = document.getElementById('plateNumberInput');
        const doorDigitsInput = document.getElementById('doorNumberDigits');
        const badge = document.getElementById('plateNumberBadgePreview');

        if (!wilayaInput || !yearInput || !serialInput || !hiddenInput) return;

        function syncPlate() {
            const w = wilayaInput.value.trim().replace(/\D/g, '');
            const y = yearInput.value.trim().replace(/\D/g, '');
            const s = serialInput.value.trim().replace(/\D/g, '');
            let formatted = '';
            if (w && y && s) {
                formatted = `${w}-${y}-${s}`;
            } else if (w || y || s) {
                const parts = [];
                if (w) parts.push(w);
                if (y) parts.push(y);
                if (s) parts.push(s);
                formatted = parts.join('-');
            }
            formatted = formatted.replace(/\/+/g, '-');
            hiddenInput.value = formatted;
            if (badge) {
                badge.textContent = formatted || '01-2000-025654';
            }
        }

        function setupInput(input, maxLen, nextInput, prevInput) {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '');
                syncPlate();
                if (this.value.length >= maxLen && nextInput) {
                    nextInput.focus();
                    if (nextInput.select) nextInput.select();
                }
            });

            input.addEventListener('keydown', function(e) {
                if ((e.key === '-' || e.key === '/' || e.key === ' ' || e.key === 'Enter') && nextInput) {
                    e.preventDefault();
                    nextInput.focus();
                    if (nextInput.select) nextInput.select();
                } else if (e.key === 'Backspace' && this.value === '' && prevInput) {
                    e.preventDefault();
                    prevInput.focus();
                    const len = prevInput.value.length;
                    if (prevInput.setSelectionRange) prevInput.setSelectionRange(len, len);
                } else if (e.key === 'ArrowRight' && this.selectionStart === this.value.length && nextInput) {
                    nextInput.focus();
                    if (nextInput.setSelectionRange) nextInput.setSelectionRange(0, 0);
                } else if (e.key === 'ArrowLeft' && this.selectionStart === 0 && prevInput) {
                    prevInput.focus();
                    const len = prevInput.value.length;
                    if (prevInput.setSelectionRange) prevInput.setSelectionRange(len, len);
                }
            });

            input.addEventListener('paste', function(e) {
                const pasteData = (e.clipboardData || window.clipboardData)?.getData('text');
                if (!pasteData) return;
                const parts = pasteData.trim().split(/[\/\-\s]+/).filter(Boolean);
                if (parts.length >= 2) {
                    e.preventDefault();
                    let w = '', y = '', s = '';
                    if (parts.length >= 3) {
                        if (parts[0].length <= 2 && parts[2].length > 2) {
                            w = parts[0]; y = parts[1]; s = parts[2];
                        } else if (parts[2].length <= 2 && parts[0].length > 2) {
                            s = parts[0]; y = parts[1]; w = parts[2];
                        } else {
                            w = parts[0]; y = parts[1]; s = parts[2];
                        }
                    } else if (parts.length === 2) {
                        if (parts[0].length <= 2) {
                            w = parts[0]; y = parts[1];
                        } else {
                            s = parts[0]; y = parts[1];
                        }
                    }
                    wilayaInput.value = w.replace(/\D/g, '').slice(0, 2);
                    yearInput.value = y.replace(/\D/g, '').slice(0, 4);
                    serialInput.value = s.replace(/\D/g, '').slice(0, 6);
                    syncPlate();
                    if (doorDigitsInput) {
                        doorDigitsInput.focus();
                    } else {
                        serialInput.focus();
                    }
                }
            });
        }

        // 1. Wilaya (2 digits) -> auto-moves to Year
        setupInput(wilayaInput, 2, yearInput, null);

        // 2. Year (4 digits or press -) -> auto-moves to Serial
        setupInput(yearInput, 4, serialInput, wilayaInput);

        // 3. Serial (6 digits) -> auto-moves to Door Number Digits!
        setupInput(serialInput, 6, doorDigitsInput, yearInput);

        // Initial sync
        syncPlate();
    })();
});

// ==========================================
// 5. Vehicle QR Code Modal & Window Sticker
// ==========================================
let currentQrCodeInstance = null;
let currentVehicleVerifyUrl = '';

function onStatusRadioChange(status) {
    const box = document.getElementById('suspendedReasonBox');
    const labelActive = document.getElementById('statusLabelActive');
    const labelSuspended = document.getElementById('statusLabelSuspended');
    if (status === 'suspended') {
        if (box) box.classList.remove('hidden');
        if (labelSuspended) {
            labelSuspended.className = 'relative flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition select-none bg-rose-50/50 dark:bg-rose-950/20 border-rose-400 dark:border-rose-600';
        }
        if (labelActive) {
            labelActive.className = 'relative flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition select-none bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300';
        }
    } else {
        if (box) box.classList.add('hidden');
        if (labelActive) {
            labelActive.className = 'relative flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition select-none bg-emerald-50/50 dark:bg-emerald-950/20 border-emerald-400 dark:border-emerald-600';
        }
        if (labelSuspended) {
            labelSuspended.className = 'relative flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition select-none bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300';
        }
    }
}

function promptSuspendVehicle(vId, vName) {
    const modal = document.getElementById('suspendVehicleModal');
    const idInput = document.getElementById('suspendVehicleIdInput');
    const titleEl = document.getElementById('suspendVehicleTitle');
    if (idInput) idInput.value = vId;
    if (titleEl) titleEl.textContent = vName;
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeSuspendVehicleModal() {
    const modal = document.getElementById('suspendVehicleModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function openVehicleQrModal(v) {
    const modal = document.getElementById('vehicleQrModal');
    if (!modal) return;

    // Populate data
    const doorEl = document.getElementById('qrStickerDoor');
    const plateEl = document.getElementById('qrStickerPlate');
    const carEl = document.getElementById('qrStickerCar');
    const colorEl = document.getElementById('qrStickerColor');
    const driverEl = document.getElementById('qrStickerDriver');
    const statusEl = document.getElementById('qrStickerStatus');

    if (doorEl) doorEl.textContent = v.door_number || '-';
    if (plateEl) plateEl.textContent = v.plate_number || '-';
    if (carEl) carEl.textContent = (v.brand || '') + ' ' + (v.model || '');
    if (colorEl) colorEl.textContent = v.color || 'أصفر';
    if (driverEl) driverEl.textContent = v.driver_name || 'بدون سائق حالياً';

    if (statusEl) {
        if (v.status === 'suspended') {
            statusEl.textContent = 'معلقة حالياً';
            statusEl.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-rose-600 text-white';
        } else {
            statusEl.textContent = 'نشطة ومعتمدة';
            statusEl.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-slate-950 text-amber-400';
        }
    }

    const host = window.location.origin;
    currentVehicleVerifyUrl = `${host}/verify_vehicle.php?v=${encodeURIComponent(v.qr_code)}`;

    const openBtn = document.getElementById('qrOpenVerifyPageBtn');
    if (openBtn) openBtn.href = currentVehicleVerifyUrl;

    // Render QR Code
    const container = document.getElementById('vehicleQrCodeContainer');
    if (container) {
        container.innerHTML = '';
        if (typeof QRCode !== 'undefined') {
            try {
                currentQrCodeInstance = new QRCode(container, {
                    text: currentVehicleVerifyUrl,
                    width: 170,
                    height: 170,
                    colorDark: '#0f172a',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            } catch (err) {
                console.error('QR code generation error:', err);
                container.textContent = 'خطأ في توليد رمز QR';
            }
        } else {
            container.innerHTML = `<span class="text-xs text-slate-500 font-mono">${currentVehicleVerifyUrl}</span>`;
        }
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeVehicleQrModal() {
    const modal = document.getElementById('vehicleQrModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function copyVehicleVerifyLink() {
    if (!currentVehicleVerifyUrl) return;
    navigator.clipboard.writeText(currentVehicleVerifyUrl).then(() => {
        const btn = document.getElementById('copyLinkBtn');
        if (btn) {
            const oldHtml = btn.innerHTML;
            btn.innerHTML = '<span>✓ تم النسخ!</span>';
            btn.classList.add('bg-emerald-500', 'text-white');
            setTimeout(() => {
                btn.innerHTML = oldHtml;
                btn.classList.remove('bg-emerald-500', 'text-white');
            }, 1800);
        }
    });
}

function printVehicleSticker() {
    const sticker = document.getElementById('printableVehicleSticker');
    if (!sticker) return;
    const printWindow = window.open('', '_blank', 'width=650,height=750');
    if (!printWindow) {
        window.print();
        return;
    }
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>ملصق سيارة الأجرة - تاكسي نقيلو</title>
            <style>
                body { font-family: system-ui, -apple-system, sans-serif; background: #fff; margin: 0; padding: 20px; display: flex; justify-content: center; }
                .sticker-box { width: 340px; border: 3px solid #0f172a; border-radius: 18px; padding: 18px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
                .header { background: #f59e0b; color: #0f172a; padding: 8px 12px; border-radius: 10px; font-weight: 900; font-size: 14px; margin-bottom: 12px; }
                .grid { display: flex; gap: 8px; margin-bottom: 12px; }
                .cell { flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px; }
                .cell-lbl { font-size: 10px; color: #64748b; font-weight: bold; }
                .cell-val { font-size: 15px; font-weight: 900; font-family: monospace; }
                .info { font-size: 13px; font-weight: bold; color: #1e293b; margin-bottom: 12px; }
                .qr { display: flex; justify-content: center; margin-bottom: 10px; }
                .qr img, .qr canvas { max-width: 170px; height: auto; }
                .footer { font-size: 10px; color: #64748b; line-height: 1.3; }
                @media print {
                    body { padding: 0; }
                    .sticker-box { page-break-inside: avoid; }
                }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            <div class="sticker-box">
                ${sticker.innerHTML}
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
}

function onExpFilterChange(val) {
    const searchParam = <?= json_encode(!empty($search) ? '&search=' . urlencode($search) : '') ?>;
    window.location.href = 'vehicles.php?exp_filter=' + encodeURIComponent(val) + searchParam;
}

function toggleColumnFilterPanel() {
    const panel = document.getElementById('columnFilterPanel');
    if (!panel) return;
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden')) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
