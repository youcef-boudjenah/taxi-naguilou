<?php
// dashboard/billing.php - Abonnements & Facturation: فضاء السائق واشتراك المركبات
$pageTitle = 'Abonnements & Facturation | الاشتراكات والفوترة';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['super_admin', 'admin', 'owner']);

$pdo = getDBConnection();

// System subscription fees from settings
$feeSpaceMonthly = (float)get_setting($pdo, 'fee_space_monthly', '1000.00');
$feeVehicleMonthly = (float)(get_setting($pdo, 'fee_vehicle_monthly') ?? get_setting($pdo, 'vehiclePrice') ?? '4000.00');

// Discount prepay offers rules from settings
$d3 = (float)(get_setting($pdo, 'discount3') ?? get_setting($pdo, 'discount_prepay_3m') ?? 500.00);
$d6 = (float)(get_setting($pdo, 'discount6') ?? get_setting($pdo, 'discount_prepay_6m') ?? 1000.00);
$d9 = (float)(get_setting($pdo, 'discount9') ?? get_setting($pdo, 'discount_prepay_9m') ?? 0.00);
$d12 = (float)(get_setting($pdo, 'discount12') ?? get_setting($pdo, 'discount_prepay_12m') ?? 0.00);

$prepayDiscountOffers = [
    1 => [
        'months' => 1,
        'title' => 'شهر واحد',
        'discount_per_month' => 0.00,
        'is_enabled' => true,
    ],
    3 => [
        'months' => 3,
        'title' => '3 أشهر',
        'discount_per_month' => $d3,
        'is_enabled' => ($d3 > 0),
    ],
    6 => [
        'months' => 6,
        'title' => '6 أشهر',
        'discount_per_month' => $d6,
        'is_enabled' => ($d6 > 0),
    ],
    9 => [
        'months' => 9,
        'title' => '9 أشهر',
        'discount_per_month' => $d9,
        'is_enabled' => ($d9 > 0),
    ],
    12 => [
        'months' => 12,
        'title' => '12 شهر',
        'discount_per_month' => $d12,
        'is_enabled' => ($d12 > 0),
    ],
];

// Selected Period
$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');
$selectedPeriod = sprintf('%04d-%02d', $selectedYear, $selectedMonth);

// Month names in French & Arabic
$monthNames = [
    '01' => '01 - Janvier (جانفي)',
    '02' => '02 - Février (فيفري)',
    '03' => '03 - Mars (مارس)',
    '04' => '04 - Avril (أفريل)',
    '05' => '05 - Mai (ماي)',
    '06' => '06 - Juin (جوان)',
    '07' => '07 - Juillet (جويلية)',
    '08' => '08 - Août (أوت)',
    '09' => '09 - Septembre (سبتمبر)',
    '10' => '10 - Octobre (أكتوبر)',
    '11' => '11 - Novembre (نوفمبر)',
    '12' => '12 - Décembre (ديسمبر)'
];
$currentMonthNameFr = strtolower(substr($monthNames[$selectedMonth] ?? 'mois', 5, 8));

// Handle Export Journal (Excel / CSV)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    while (ob_get_level()) { ob_end_clean(); }

    $filename = "Journal_Abonnements_{$selectedYear}_{$selectedMonth}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'رقم', 'السائق', 'الهاتف', 'حساب الفضاء', 'المركبات التي يقودها',
        'اشتراك الفضاء (دج)', 'cotis. المركبات (دج)', 'المبلغ المستحق (دج)', 'المبلغ المدفوع (دج)', 'الحالة', 'الفترة'
    ], ';');

    $stmtExp = $pdo->prepare("
        SELECT 
            u.id as driver_id, u.full_name as driver_name, u.phone as driver_phone, u.status as user_status,
            s.space_fee, s.vehicle_fee, s.total_due, s.paid_amount, s.status as payment_status,
            COALESCE(v_agg.vehicles_count, 0) as assigned_vehicles_count,
            v_agg.vehicles_data
        FROM users u
        LEFT JOIN (
            SELECT 
                v_driver_id,
                COUNT(*) as vehicles_count,
                GROUP_CONCAT(CONCAT(COALESCE(brand, ''), ' ', COALESCE(model, ''), ' (', COALESCE(plate_number, ''), ') [باب: ', COALESCE(door_number, '-'), ']') SEPARATOR ' | ') as vehicles_data
            FROM (
                SELECT id, brand, model, plate_number, door_number, current_driver_id as v_driver_id FROM vehicles WHERE current_driver_id IS NOT NULL
                UNION ALL
                SELECT id, brand, model, plate_number, door_number, replacement_driver_id as v_driver_id FROM vehicles WHERE replacement_driver_id IS NOT NULL
            ) v_all
            GROUP BY v_driver_id
        ) v_agg ON v_agg.v_driver_id = u.id
        LEFT JOIN (
            SELECT s_sub.* 
            FROM driver_subscriptions s_sub
            JOIN (
                SELECT driver_id, MAX(id) as max_id 
                FROM driver_subscriptions 
                WHERE period_month = ? AND deleted_at IS NULL 
                GROUP BY driver_id
            ) s_max ON s_sub.id = s_max.max_id
        ) s ON s.driver_id = u.id
        WHERE u.role = 'driver' AND u.deleted_at IS NULL
        ORDER BY u.id ASC
    ");
    $stmtExp->execute([$selectedPeriod]);
    $expRows = $stmtExp->fetchAll();

    $i = 1;
    foreach ($expRows as $row) {
        $vCount = (int)$row['assigned_vehicles_count'];
        $spaceFee = $row['space_fee'] !== null ? (float)$row['space_fee'] : $feeSpaceMonthly;
        $vehicleFee = $row['vehicle_fee'] !== null ? (float)$row['vehicle_fee'] : ($vCount * $feeVehicleMonthly);
        $totalDue = $row['total_due'] !== null ? (float)$row['total_due'] : ($spaceFee + $vehicleFee);
        $paidAmount = (float)($row['paid_amount'] ?? 0);
        $st = $row['payment_status'] ?? ($paidAmount >= $totalDue ? 'regle' : 'en_attente');

        $statusText = $st === 'regle' ? 'مُسدد (Réglé)' : ($st === 'partiel' ? 'تسوية جزئية' : 'معلق (En attente)');
        $spaceAccountText = $row['user_status'] === 'active' ? 'مفعل' : 'موقوف';
        $vehText = !empty($row['vehicles_data']) ? $row['vehicles_data'] : 'بدون مركبة';

        fputcsv($output, [
            $i++,
            $row['driver_name'],
            $row['driver_phone'],
            $spaceAccountText,
            $vehText,
            number_format($spaceFee, 2, ',', ' '),
            number_format($vehicleFee, 2, ',', ' '),
            number_format($totalDue, 2, ',', ' '),
            number_format($paidAmount, 2, ',', ' '),
            $statusText,
            $selectedPeriod
        ], ';');
    }
    fclose($output);
    exit;
}

// Handle Manual Payment with Prepayment Duration & Settings Discounts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_payment') {
    require_csrf_token();
    $driverId = intval($_POST['driver_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $durationMonths = max(1, min(12, intval($_POST['duration_months'] ?? 1)));
    $paymentType = $_POST['payment_type'] ?? 'vehicles';
    $method = $_POST['payment_method'] ?? 'especes';
    $paymentDate = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $payMonth = sprintf('%02d', max(1, min(12, intval($_POST['payment_month'] ?? $selectedMonth))));
    $payYear = intval($_POST['payment_year'] ?? $selectedYear);
    $printReceipt = (isset($_POST['print_receipt']) && $_POST['print_receipt'] === '1');
    $userNotes = trim($_POST['notes'] ?? '');

    if ($driverId > 0 && $amount >= 0) {
        // Count driver's vehicles
        $stmtVCount = $pdo->prepare("
            SELECT COUNT(DISTINCT id) FROM vehicles 
            WHERE (current_driver_id = ? OR replacement_driver_id = ?) AND deleted_at IS NULL
        ");
        $stmtVCount->execute([$driverId, $driverId]);
        $driverVehCount = (int)$stmtVCount->fetchColumn();

        // Fetch driver's custom vehicle fee if set
        $stmtDriverFee = $pdo->prepare("SELECT custom_vehicle_fee FROM users WHERE id = ?");
        $stmtDriverFee->execute([$driverId]);
        $driverCustomFee = $stmtDriverFee->fetchColumn();
        $rateVehicle = ($driverCustomFee !== false && $driverCustomFee !== null) ? (float)$driverCustomFee : $feeVehicleMonthly;

        if ($paymentType === 'vehicles') {
            $calcSpaceFee = 0.00;
            $calcVehicleFee = max(1, $driverVehCount) * $rateVehicle;
        } elseif ($paymentType === 'space') {
            $calcSpaceFee = $feeSpaceMonthly;
            $calcVehicleFee = 0.00;
        } else {
            $calcSpaceFee = $feeSpaceMonthly;
            $calcVehicleFee = max(1, $driverVehCount) * $rateVehicle;
        }
        $baseMonthlyDue = $calcSpaceFee + $calcVehicleFee;

        // Determine discount according to settings
        $discountPerMonth = 0.00;
        if (isset($prepayDiscountOffers[$durationMonths]) && $prepayDiscountOffers[$durationMonths]['is_enabled']) {
            $discountPerMonth = (float)$prepayDiscountOffers[$durationMonths]['discount_per_month'];
        }
        $netMonthlyDue = max(0, $baseMonthlyDue - $discountPerMonth);
        $amountPerMonth = round($amount / $durationMonths, 2);

        $pdo->beginTransaction();
        $firstSubId = 0;
        try {
            $periodsProcessed = [];
            $startDt = new DateTime("{$payYear}-{$payMonth}-01");

            for ($i = 0; $i < $durationMonths; $i++) {
                $curDt = clone $startDt;
                if ($i > 0) {
                    $curDt->modify("+{$i} month");
                }
                $periodStr = $curDt->format('Y-m');
                $periodsProcessed[] = $periodStr;

                $typeLabel = $paymentType === 'vehicles' ? 'cotis. المركبات' : ($paymentType === 'space' ? 'اشتراك الفضاء' : 'الكل');
                $periodNote = "دفع يدوي في المكتب ({$typeLabel}) لـ {$durationMonths} أشهر ({$periodStr})";
                if ($discountPerMonth > 0) {
                    $periodNote .= " [خصم مسبق: " . number_format($discountPerMonth, 0) . " دج/ش]";
                }
                if ($userNotes) {
                    $periodNote .= " - $userNotes";
                }

                $checkStmt = $pdo->prepare("SELECT id, total_due, paid_amount FROM driver_subscriptions WHERE driver_id = ? AND period_month = ?");
                $checkStmt->execute([$driverId, $periodStr]);
                $sub = $checkStmt->fetch();

                if ($sub) {
                    if ($firstSubId === 0) $firstSubId = (int)$sub['id'];
                    $accumulatedPaid = (float)$sub['paid_amount'] + $amountPerMonth;
                    $targetDue = $netMonthlyDue > 0 ? $netMonthlyDue : (float)$sub['total_due'];
                    $newStatus = ($accumulatedPaid >= $targetDue) ? 'regle' : 'partiel';
                    $updStmt = $pdo->prepare("
                        UPDATE driver_subscriptions 
                        SET total_due = ?, paid_amount = ?, status = ?, payment_date = ?, payment_method = ?, notes = ?
                        WHERE id = ?
                    ");
                    $updStmt->execute([$targetDue, $accumulatedPaid, $newStatus, $paymentDate, $method, $periodNote, $sub['id']]);
                } else {
                    $newStatus = ($amountPerMonth >= $netMonthlyDue) ? 'regle' : 'partiel';
                    $insStmt = $pdo->prepare("
                        INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status, payment_date, payment_method, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insStmt->execute([$driverId, $periodStr, $calcSpaceFee, $calcVehicleFee, $driverVehCount, $netMonthlyDue, $amountPerMonth, $newStatus, $paymentDate, $method, $periodNote]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($firstSubId === 0) $firstSubId = $newId;
                }
            }

            $pdo->commit();
            $periodRangeText = count($periodsProcessed) > 1 ? ("الفترة من " . reset($periodsProcessed) . " إلى " . end($periodsProcessed)) : reset($periodsProcessed);
            log_audit($pdo, 'subscription_manual_payment', 'driver_subscriptions', $driverId, "تسجيل دفع يدوي في المكتب لـ $durationMonths أشهر للسائق $driverId بمبلغ إجمالي $amount دج عبر $method ($periodRangeText)");
            set_flash('success', "تم تسجيل الدفع اليدوي بنجاح بقيمة " . number_format($amount, 2) . " دج لـ {$durationMonths} أشهر ({$periodRangeText}) مع تطبيق قوانين الخصم المعتمدة.");
            
            if ($printReceipt && $firstSubId > 0) {
                redirect("/dashboard/invoice.php?type=subscription&id={$firstSubId}");
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء تسجيل الدفعة: ' . $e->getMessage());
        }
        redirect("/dashboard/billing.php?month={$payMonth}&year={$payYear}");
    }
}

// Handle Set Custom Vehicle Fee for Driver (Screenshot 2 Modal Action)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_custom_vehicle_fee') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بتعديل تسعيرة اشتراك المركبات.');
        redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
    }

    $driverId = intval($_POST['driver_id'] ?? 0);
    $isDefault = intval($_POST['is_default'] ?? 0);
    $customFeeRaw = trim($_POST['custom_vehicle_fee'] ?? '');

    if ($driverId > 0) {
        try {
            $customFee = ($isDefault === 1 || $customFeeRaw === '') ? null : floatval($customFeeRaw);

            // Update user record
            $stmtUpdUser = $pdo->prepare("UPDATE users SET custom_vehicle_fee = ? WHERE id = ?");
            $stmtUpdUser->execute([$customFee, $driverId]);

            // Recalculate unpaid subscription for current month if exists
            $stmtVehCount = $pdo->prepare("
                SELECT COUNT(DISTINCT id) FROM (
                    SELECT id FROM vehicles WHERE current_driver_id = ? AND deleted_at IS NULL
                    UNION ALL
                    SELECT id FROM vehicles WHERE replacement_driver_id = ? AND deleted_at IS NULL
                ) v
            ");
            $stmtVehCount->execute([$driverId, $driverId]);
            $vehCount = (int)$stmtVehCount->fetchColumn();

            $effectiveRate = ($customFee !== null) ? $customFee : $feeVehicleMonthly;
            $newVehicleFee = $effectiveRate * $vehCount;

            $stmtCheckSub = $pdo->prepare("SELECT id, space_fee, paid_amount, status FROM driver_subscriptions WHERE driver_id = ? AND period_month = ? AND deleted_at IS NULL");
            $stmtCheckSub->execute([$driverId, $selectedPeriod]);
            $sub = $stmtCheckSub->fetch();

            if ($sub && $sub['status'] !== 'regle') {
                $curSpaceFee = (float)$sub['space_fee'];
                $newTotal = $curSpaceFee + $newVehicleFee;
                $pdo->prepare("
                    UPDATE driver_subscriptions 
                    SET vehicle_fee = ?, total_due = ?
                    WHERE id = ?
                ")->execute([$newVehicleFee, $newTotal, $sub['id']]);
            }

            $logMsg = ($customFee !== null) 
                ? "تحديد تسعيرة مركبة خاصة للسائق {$driverId} بقيمة {$customFee} دج شهرياً لكل مركبة" 
                : "إعادة تعيين تسعيرة مركبة السائق {$driverId} إلى التسعيرة الافتراضية ({$feeVehicleMonthly} دج)";
            log_audit($pdo, 'custom_vehicle_fee_updated', 'users', $driverId, $logMsg);

            set_flash('success', ($customFee !== null)
                ? "تم بنجاح تطبيق تسعيرة خاصة قدرها " . number_format($customFee, 2) . " دج لكل مركبة لهذا السائق."
                : "تمت استعادة التسعيرة الافتراضية للنظام (" . number_format($feeVehicleMonthly, 2) . " دج) لهذا السائق بنجاح."
            );
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء تعديل التسعيرة: ' . $e->getMessage());
        }
    }
    redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
}

// Handle Generate Subscriptions for all active drivers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_debts') {
    require_csrf_token();
    
    // Fetch all active drivers with their assigned vehicle counts and custom fee
    $stmtDrivers = $pdo->query("
        SELECT u.id, u.custom_vehicle_fee, COUNT(DISTINCT v.id) as veh_count
        FROM users u
        LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
        WHERE u.role = 'driver' AND u.status = 'active'
        GROUP BY u.id
    ");
    $activeDrivers = $stmtDrivers->fetchAll();

    $checkExisting = $pdo->prepare("SELECT id, paid_amount FROM driver_subscriptions WHERE driver_id = ? AND period_month = ?");
    $updateSub = $pdo->prepare("
        UPDATE driver_subscriptions 
        SET space_fee = ?, vehicle_fee = ?, vehicles_count = ?, total_due = ?,
            status = CASE WHEN paid_amount >= ? THEN 'regle' WHEN paid_amount > 0 THEN 'partiel' ELSE 'en_attente' END
        WHERE id = ?
    ");
    $insertSub = $pdo->prepare("
        INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status)
        VALUES (?, ?, ?, ?, ?, ?, 0.00, 'en_attente')
    ");

    $countGenerated = 0;
    foreach ($activeDrivers as $d) {
        $dId = (int)$d['id'];
        $vCount = (int)$d['veh_count'];
        $sFee = $feeSpaceMonthly;
        $driverRate = ($d['custom_vehicle_fee'] !== null) ? (float)$d['custom_vehicle_fee'] : $feeVehicleMonthly;
        $vFee = $vCount * $driverRate;
        $tot = $sFee + $vFee;

        $checkExisting->execute([$dId, $selectedPeriod]);
        $existing = $checkExisting->fetch();

        if ($existing) {
            $updateSub->execute([$sFee, $vFee, $vCount, $tot, $tot, $existing['id']]);
        } else {
            $insertSub->execute([$dId, $selectedPeriod, $sFee, $vFee, $vCount, $tot]);
        }
        $countGenerated++;
    }

    log_audit($pdo, 'generate_monthly_subscriptions', 'driver_subscriptions', null, "توليد وتحديث استحقاقات الاشتراكات لشهر $selectedPeriod لـ $countGenerated سائق");
    set_flash('success', "تم توليد وتحديث استحقاقات الاشتراكات لشهر {$monthNames[$selectedMonth]} لـ {$countGenerated} سائق نشط بنجاح.");
    redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
}

// Handle Edit / Custom Subscription Override
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_subscription') {
    require_csrf_token();
    $subId = intval($_POST['subscription_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $spaceFee = floatval($_POST['space_fee'] ?? 0);
    $vehicleFee = floatval($_POST['vehicle_fee'] ?? 0);
    $paidAmount = floatval($_POST['paid_amount'] ?? 0);
    $totalDue = $spaceFee + $vehicleFee;
    $status = in_array($_POST['payment_status'] ?? '', ['regle', 'partiel', 'en_attente']) ? $_POST['payment_status'] : ($paidAmount >= $totalDue ? 'regle' : ($paidAmount > 0 ? 'partiel' : 'en_attente'));
    $paymentMethod = $_POST['payment_method'] ?? 'baridimob';
    $notes = trim($_POST['notes'] ?? '');

    if ($driverId > 0) {
        try {
            if ($subId > 0) {
                $stmtUpd = $pdo->prepare("
                    UPDATE driver_subscriptions 
                    SET space_fee = ?, vehicle_fee = ?, total_due = ?, paid_amount = ?, status = ?, payment_method = ?, notes = ?
                    WHERE id = ?
                ");
                $stmtUpd->execute([$spaceFee, $vehicleFee, $totalDue, $paidAmount, $status, $paymentMethod, $notes, $subId]);
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status, payment_method, notes)
                    VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$driverId, $selectedPeriod, $spaceFee, $vehicleFee, $totalDue, $paidAmount, $status, $paymentMethod, $notes]);
            }
            log_audit($pdo, 'subscription_updated', 'driver_subscriptions', $driverId, "تعديل اشتراك السائق $driverId لشهر $selectedPeriod");
            set_flash('success', "تم حفظ وتعديل بيانات الاشتراك بنجاح");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء تعديل الاشتراك: ' . $e->getMessage());
        }
    }
    redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
}

// Handle Delete Subscription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_subscription') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف قيود الاشتراكات.');
        redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
    }
    $subId = intval($_POST['subscription_id'] ?? 0);
    if ($subId > 0) {
        try {
            $pdo->prepare("UPDATE driver_subscriptions SET deleted_at = NOW() WHERE id = ?")->execute([$subId]);
            log_audit($pdo, 'subscription_deleted', 'driver_subscriptions', $subId, "نقل اشتراك رقم $subId إلى سلة المهملات");
            set_flash('success', "تم نقل قيد الاشتراك إلى سلة المهملات والأرشيف بنجاح. يمكنك استرجاعه في أي وقت.");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء حذف الاشتراك: ' . $e->getMessage());
        }
    }
    redirect("/dashboard/billing.php?month={$selectedMonth}&year={$selectedYear}");
}

// Search and Filter & Pagination
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = in_array(intval($_GET['per_page'] ?? 50), [10, 25, 50, 100]) ? intval($_GET['per_page'] ?? 50) : 50;

// Main Table Query (ONLY_FULL_GROUP_BY safe)
$sql = "
    SELECT 
        u.id as driver_id, 
        u.full_name as driver_name, 
        u.phone as driver_phone, 
        u.status as user_status, 
        u.email as driver_email, 
        u.avatar_url,
        u.subscription_type,
        u.subscription_expires_at,
        u.custom_vehicle_fee,
        u.created_at as driver_created_at,
        u.activity_start_date,
        u.license_number,
        s.id as subscription_id, 
        s.space_fee, 
        s.vehicle_fee, 
        s.vehicles_count as sub_vehicles_count, 
        s.total_due, 
        s.paid_amount, 
        s.status as payment_status, 
        s.payment_date, 
        s.payment_method, 
        s.notes as payment_notes,
        COALESCE(v_agg.vehicles_count, 0) as assigned_vehicles_count,
        v_agg.vehicles_data
    FROM users u
    LEFT JOIN (
        SELECT 
            v_driver_id,
            COUNT(*) as vehicles_count,
            GROUP_CONCAT(CONCAT(COALESCE(brand, ''), ' ', COALESCE(model, ''), '|||', COALESCE(plate_number, ''), '|||', COALESCE(door_number, '')) SEPARATOR '###') as vehicles_data
        FROM (
            SELECT id, brand, model, plate_number, door_number, current_driver_id as v_driver_id FROM vehicles WHERE current_driver_id IS NOT NULL AND deleted_at IS NULL
            UNION ALL
            SELECT id, brand, model, plate_number, door_number, replacement_driver_id as v_driver_id FROM vehicles WHERE replacement_driver_id IS NOT NULL AND deleted_at IS NULL
        ) v_all
        GROUP BY v_driver_id
    ) v_agg ON v_agg.v_driver_id = u.id
    LEFT JOIN (
        SELECT s_sub.* 
        FROM driver_subscriptions s_sub
        JOIN (
            SELECT driver_id, MAX(id) as max_id 
            FROM driver_subscriptions 
            WHERE period_month = ? AND deleted_at IS NULL 
            GROUP BY driver_id
        ) s_max ON s_sub.id = s_max.max_id
    ) s ON s.driver_id = u.id
    WHERE u.role = 'driver' AND u.deleted_at IS NULL
";
$params = [$selectedPeriod];

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR v_agg.vehicles_data LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter === 'regle') {
    $sql .= " AND s.status = 'regle'";
} elseif ($statusFilter === 'partiel') {
    $sql .= " AND s.status = 'partiel'";
} elseif ($statusFilter === 'en_attente') {
    $sql .= " AND (s.status = 'en_attente' OR s.status IS NULL)";
}

$sql .= " ORDER BY u.id ASC";
$allRows = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $allRows = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        error_log("billing.php - Main query error: " . $e->getMessage());
        $allRows = [];
    }
}

// Pagination slicing
$totalCount = count($allRows);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$rows = array_slice($allRows, $offset, $perPage);
$fromRecord = $totalCount > 0 ? $offset + 1 : 0;
$toRecord = min($offset + $perPage, $totalCount);

// Accurate Dynamic KPI Figures
$kpiRevenusPercus = 0.0;
$kpiRevenusAttente = 0.0;
$paidVehiclesCount = 0;
$totalVehicles = 0;
$allDriversList = [];

if ($pdo) {
    try {
        $stmtPaid = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM driver_subscriptions WHERE period_month = ?");
        $stmtPaid->execute([$selectedPeriod]);
        $kpiRevenusPercus = (float)$stmtPaid->fetchColumn();
    } catch (\Throwable $e) {}

    try {
        $stmtDue = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0, total_due - paid_amount)), 0) FROM driver_subscriptions WHERE period_month = ?");
        $stmtDue->execute([$selectedPeriod]);
        $kpiRevenusAttente = (float)$stmtDue->fetchColumn();
    } catch (\Throwable $e) {}

    // If no subscriptions generated yet for this period, estimate pending from active drivers
    if ($kpiRevenusAttente == 0 && $kpiRevenusPercus == 0) {
        try {
            $stmtEst = $pdo->query("
                SELECT COUNT(DISTINCT u.id) as drivers_count, COUNT(DISTINCT v.id) as veh_count
                FROM users u
                LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id)
                WHERE u.role = 'driver' AND u.status = 'active'
            ");
            $est = $stmtEst ? $stmtEst->fetch() : null;
            if ($est && $est['drivers_count'] > 0) {
                $kpiRevenusAttente = ($est['drivers_count'] * $feeSpaceMonthly) + ($est['veh_count'] * $feeVehicleMonthly);
            }
        } catch (\Throwable $e) {}
    }

    // Total vehicles and settled vehicles
    try {
        $totalVehicles = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE status != 'out_of_service'")->fetchColumn();
    } catch (\Throwable $e) {
        $totalVehicles = 0;
    }

    try {
        $stmtPaidVeh = $pdo->prepare("
            SELECT COUNT(DISTINCT v.id)
            FROM vehicles v
            JOIN driver_subscriptions s ON (s.driver_id = v.current_driver_id OR s.driver_id = v.replacement_driver_id)
            WHERE s.period_month = ? AND s.status = 'regle'
        ");
        $stmtPaidVeh->execute([$selectedPeriod]);
        $paidVehiclesCount = (int)$stmtPaidVeh->fetchColumn();
    } catch (\Throwable $e) {
        $paidVehiclesCount = 0;
    }

    // Fetch all drivers for manual payment dropdown with vehicle counts and custom vehicle fee
    try {
        $stmtAllD = $pdo->query("
            SELECT u.id, u.full_name, u.phone, u.custom_vehicle_fee,
                   COUNT(DISTINCT v.id) as veh_count
            FROM users u
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.replacement_driver_id = u.id) AND v.deleted_at IS NULL
            WHERE u.role = 'driver' AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.full_name ASC
        ");
        $allDriversList = $stmtAllD ? $stmtAllD->fetchAll() : [];
    } catch (\Throwable $e) {
        $allDriversList = [];
    }
}

$kpiVehiculesReglesCount = $paidVehiclesCount;
$kpiVehiculesTotalCount = max(1, $totalVehicles);
$kpiVehiculesRegles = "{$paidVehiclesCount} / {$totalVehicles}";
$kpiCollectionRate = $kpiVehiculesTotalCount > 0 ? round(($kpiVehiculesReglesCount / $kpiVehiculesTotalCount) * 100, 1) : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Abonnements & Facturation</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    الاشتراكات والفوترة الشهرية
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">
                متابعة اشتراكات السائقين: <span class="font-bold text-slate-700 font-num"><?= number_format($feeSpaceMonthly, 0, ',', ' ') ?> دج/شهر</span> لحساب السائق + <span class="font-bold text-slate-700 font-num"><?= number_format($feeVehicleMonthly, 0, ',', ' ') ?> دج/شهر</span> لكل مركبة نشطة
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('manualPaymentModal').classList.remove('hidden'); document.getElementById('manualPaymentModal').classList.add('flex'); updateManualPaymentCalc();"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4 text-slate-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                </svg>
                <span>تسجيل دفع يدوي في المكتب</span>
            </button>
        </div>
    </div>

    <!-- 3 KPI Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        
        <!-- Revenus perçus -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">الإيرادات المحصلة (<?= $currentMonthNameFr ?>)</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-emerald-600 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($kpiRevenusPercus, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-emerald-700 font-bold">● اشتراكات مُسددة:</span>
                <span>تم تأكيد الدفع عبر CCP أو بريدي موب أو نقداً</span>
            </div>
        </div>

        <!-- Revenus en attente -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">مستحقات معلقة (<?= $currentMonthNameFr ?>)</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-amber-600 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($kpiRevenusAttente, 2, ',', ' ') ?> <span class="text-sm font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100">
                <span class="text-amber-700 font-bold">● مستحقات غير مسددة:</span>
                <span>قيد التذكير والإشعار قبل نهاية الشهر</span>
            </div>
        </div>

        <!-- Véhicules réglés -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">المركبات المُسددة (<?= $currentMonthNameFr ?>)</span>
                <span class="w-8 h-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75a3 3 0 013 3v3.75m-6-6.75H6.75a2.25 2.25 0 00-2.25 2.25v6.75" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-slate-900 font-num">
                <?= $kpiVehiculesRegles ?>
            </div>
            
            <div class="space-y-1 pt-1 border-t border-slate-100">
                <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                    <div class="bg-emerald-500 h-1.5 rounded-full" style="width: <?= $kpiCollectionRate ?>%"></div>
                </div>
                <div class="flex justify-between text-[10px] text-slate-400 font-semibold font-num">
                    <span>نسبة التحصيل: <?= $kpiCollectionRate ?>%</span>
                    <span>الإجمالي: <?= $totalVehicles ?> سيارة</span>
                </div>
            </div>
        </div>

    </div>

    <!-- Period Selector, Search & Export Toolbar -->
    <div class="bg-white p-3 sm:p-4 rounded-xl border border-slate-200 flex flex-col lg:flex-row items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="w-full lg:max-w-xs flex items-center gap-2">
            <input type="hidden" name="month" value="<?= htmlspecialchars($selectedMonth) ?>">
            <input type="hidden" name="year" value="<?= htmlspecialchars($selectedYear) ?>">
            <?php if ($statusFilter !== 'all'): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <?php endif; ?>
            <div class="relative flex-1">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث بالسائق، الهاتف، اللوحة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 text-white font-bold hover:bg-slate-800 transition">بحث</button>
        </form>

        <!-- Status Filter Tabs -->
        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl">
            <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&status=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
               class="px-3 py-1.5 rounded-lg font-bold text-xs transition <?= $statusFilter === 'all' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900' ?>">
                الكل
            </a>
            <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&status=regle<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
               class="px-3 py-1.5 rounded-lg font-bold text-xs transition <?= $statusFilter === 'regle' ? 'bg-white text-emerald-700 shadow-2xs' : 'text-slate-600 hover:text-emerald-700' ?>">
                مُسدّد
            </a>
            <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&status=en_attente<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
               class="px-3 py-1.5 rounded-lg font-bold text-xs transition <?= $statusFilter === 'en_attente' ? 'bg-white text-rose-700 shadow-2xs' : 'text-slate-600 hover:text-rose-700' ?>">
                معلق
            </a>
        </div>

        <!-- Period Selector & Export -->
        <div class="flex items-center gap-2 w-full lg:w-auto justify-end">
            <span class="text-slate-500 font-bold whitespace-nowrap hidden sm:inline">الفترة:</span>
            
            <form method="GET" class="flex items-center gap-2">
                <?php if (!empty($search)): ?>
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <?php endif; ?>
                <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <?php endif; ?>
                
                <select name="month" onchange="this.form.submit()"
                        class="px-3 py-2 rounded-lg border border-slate-200 bg-white font-bold text-slate-800 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <?php foreach ($monthNames as $mKey => $mLabel): ?>
                        <option value="<?= $mKey ?>" <?= $selectedMonth === $mKey ? 'selected' : '' ?>>
                            <?= $mLabel ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="year" onchange="this.form.submit()"
                        class="px-3 py-2 rounded-lg border border-slate-200 bg-white font-num font-bold text-slate-800 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    <option value="2026" <?= $selectedYear === '2026' ? 'selected' : '' ?>>2026</option>
                    <option value="2027" <?= $selectedYear === '2027' ? 'selected' : '' ?>>2027</option>
                </select>
            </form>

            <a href="billing.php?export=excel&month=<?= urlencode($selectedMonth) ?>&year=<?= urlencode($selectedYear) ?>" 
               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-emerald-300 hover:border-emerald-400 bg-emerald-50 hover:bg-emerald-100 text-emerald-900 font-bold text-xs transition shadow-2xs whitespace-nowrap">
                <svg class="w-4 h-4 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                <span>Excel</span>
            </a>
        </div>

    </div>

    <!-- Subscriptions Table: Matching Screenshot 1 Exact UI & Functions -->
    <div class="bg-[#12141a] dark:bg-[#0f1117] rounded-2xl border border-slate-800 shadow-xl overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-[#181a22] text-slate-300 font-bold border-b border-slate-800/90 whitespace-nowrap">
                    <tr>
                        <!-- 1. السائق -->
                        <th class="py-3.5 px-4 text-right">
                            <span class="inline-flex items-center gap-1.5 text-sky-400">
                                <svg class="w-4 h-4 text-sky-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-slate-200">السائق</span>
                            </span>
                        </th>

                        <!-- 2. حساب الفضاء -->
                        <th class="py-3.5 px-4 text-center">
                            <span class="inline-flex items-center gap-1.5 text-amber-400">
                                <svg class="w-4 h-4 text-amber-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 2a1 1 0 00-1 1v1a1 1 0 002 0V3a1 1 0 00-1-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-slate-200">حساب الفضاء</span>
                            </span>
                        </th>

                        <!-- 3. المركبات التي يقودها -->
                        <th class="py-3.5 px-4 text-center">
                            <span class="inline-flex items-center gap-1.5 text-blue-400">
                                <svg class="w-4 h-4 text-blue-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M8 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM15 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
                                    <path d="M3 4a1 1 0 00-1 1v10a1 1 0 001 1h1.05a2.5 2.5 0 014.9 0H11a1 1 0 001-1v-1h3.05a2.5 2.5 0 014.9 0H20a1 1 0 001-1V9a1 1 0 00-.293-.707l-3-3A1 1 0 0017 5h-3V4a1 1 0 00-1-1H3z" />
                                </svg>
                                <span class="text-slate-200">المركبات التي يقودها</span>
                            </span>
                        </th>

                        <!-- 4. اشتراك الفضاء -->
                        <th class="py-3.5 px-4 text-center">
                            <span class="inline-flex items-center gap-1.5 text-emerald-400 font-num">
                                <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599-.8a1 1 0 011.33 1.33l-.8 1.599L18.677 11H20a1 1 0 110 2h-1.323l-1.582 3.954.8 1.599a1 1 0 01-1.33 1.33l-1.599-.8L11 18.677V20a1 1 0 11-2 0v-1.323l-3.954-1.582-1.599.8a1 1 0 01-1.33-1.33l.8-1.599L1.323 13H0a1 1 0 110-2h1.323l1.582-3.954-.8-1.599a1 1 0 011.33-1.33l1.599.8L9 2.323V1a1 1 0 011-1z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-slate-200">اشتراك الفضاء</span>
                            </span>
                        </th>

                        <!-- 5. cotis. المركبات -->
                        <th class="py-3.5 px-4 text-center">
                            <span class="inline-flex items-center gap-1.5 text-purple-400 font-num">
                                <svg class="w-4 h-4 text-purple-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z" />
                                    <path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-slate-200">cotis. المركبات</span>
                            </span>
                        </th>

                        <!-- 6. المبلغ المستحق -->
                        <th class="py-3.5 px-4 text-center">
                            <span class="inline-flex items-center gap-1.5 text-pink-400 font-num">
                                <svg class="w-4 h-4 text-pink-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z" />
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-slate-200">المبلغ المستحق</span>
                            </span>
                        </th>

                        <!-- 7. الحالة -->
                        <th class="py-3.5 px-4 text-center">
                            <span class="inline-flex items-center gap-1.5 text-teal-400">
                                <svg class="w-4 h-4 text-teal-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-slate-200">الحالة</span>
                            </span>
                        </th>

                        <!-- 8. الإجراءات -->
                        <th class="py-3.5 px-4 text-center min-w-[180px]">
                            <span class="inline-flex items-center gap-1.5 text-cyan-400">
                                <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                                <span class="text-slate-200">الإجراءات</span>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/70 font-medium text-slate-300">
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $r): 
                            $vCount = (int)$r['assigned_vehicles_count'];

                            // Space Account Status & Promo Card Logic
                            $isSpaceActive = ($r['user_status'] === 'active' && !empty($r['subscription_type']) && $r['subscription_type'] !== 'none');
                            $subType = $r['subscription_type'] ?? 'unlimited';
                            $startDate = !empty($r['activity_start_date']) ? $r['activity_start_date'] : (!empty($r['driver_created_at']) ? substr($r['driver_created_at'], 0, 10) : null);
                            $expiryDate = $r['subscription_expires_at'] ?? null;

                            $hasPromo = false;
                            $promoTitle = '';
                            $startFormatted = '';
                            $endFormatted = '';
                            $spaceTrialPill = '';

                            if ($isSpaceActive) {
                                if (!empty($expiryDate)) {
                                    $hasPromo = true;
                                    $fromTs = $startDate ? strtotime($startDate) : strtotime("-3 months", strtotime($expiryDate));
                                    $toTs = strtotime($expiryDate);
                                    $diffDays = max(1, round(($toTs - $fromTs) / 86400));
                                    $mCount = max(1, (int)round($diffDays / 30));
                                    $promoTitle = ($mCount === 1) ? 'عرض شهر مجاناً' : (($mCount === 12) ? 'عرض سنة مجاناً' : "عرض {$mCount} أشهر مجاناً");
                                    $startFormatted = date('d/m/Y', $fromTs);
                                    $endFormatted = date('d/m/Y', $toTs);

                                    $frenchMonths = [1=>'janv.', 2=>'févr.', 3=>'mars', 4=>'avr.', 5=>'mai', 6=>'juin', 7=>'juil.', 8=>'août', 9=>'sept.', 10=>'oct.', 11=>'nov.', 12=>'déc.'];
                                    $mNum = (int)date('n', $toTs);
                                    $y2 = date('y', $toTs);
                                    $spaceTrialPill = "{$mCount}m (" . ($frenchMonths[$mNum] ?? '') . " {$y2})";
                                } elseif ($subType === 'unlimited') {
                                    $hasPromo = true;
                                    $promoTitle = 'فضاء دائم غير محدود';
                                    $startFormatted = $startDate ? date('d/m/Y', strtotime($startDate)) : date('d/m/Y');
                                    $endFormatted = 'غير محدد';
                                }
                            }

                            // Vehicle Data extraction
                            $firstPlate = '';
                            $firstDoor = '';
                            if (!empty($r['vehicles_data'])) {
                                $vItems = explode('###', $r['vehicles_data']);
                                if (!empty($vItems[0])) {
                                    $vParts = explode('|||', $vItems[0]);
                                    $firstPlate = $vParts[1] ?? '';
                                    $firstDoor = $vParts[2] ?? '';
                                }
                            }

                            // Space Fee
                            $isSpaceFree = ($isSpaceActive && ($subType === 'free' || !empty($expiryDate)));
                            $sFee = $r['space_fee'] !== null ? (float)$r['space_fee'] : ($isSpaceActive ? ($isSpaceFree ? 0.0 : $feeSpaceMonthly) : 0.0);

                            // Vehicle Fee (per custom rate or system default)
                            $driverRate = ($r['custom_vehicle_fee'] !== null) ? (float)$r['custom_vehicle_fee'] : $feeVehicleMonthly;
                            $vFee = $r['vehicle_fee'] !== null ? (float)$r['vehicle_fee'] : ($vCount * $driverRate);

                            // Total Due & Paid Status
                            $tot = $r['total_due'] !== null ? (float)$r['total_due'] : ($sFee + $vFee);
                            $paidAmount = (float)($r['paid_amount'] ?? 0);
                            $remaining = max(0, $tot - $paidAmount);
                            $status = $r['payment_status'] ?? ($paidAmount >= $tot && $tot > 0 ? 'regle' : ($paidAmount > 0 ? 'partiel' : 'en_attente'));
                            $isPaid = ($status === 'regle');
                        ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                
                                <!-- 1. السائق -->
                                <td class="py-4 px-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <?php if (!empty($r['avatar_url'])): ?>
                                            <img src="<?= htmlspecialchars($r['avatar_url']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border border-slate-700/80 shadow-xs shrink-0">
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-full bg-slate-800 text-slate-200 border border-slate-700 font-bold flex items-center justify-center text-xs shrink-0 shadow-xs">
                                                <?= mb_substr($r['driver_name'], 0, 1) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="text-right">
                                            <div class="font-bold text-white text-[13px]"><?= htmlspecialchars($r['driver_name']) ?></div>
                                            <div class="text-[11px] text-slate-400 font-sans tracking-tight" dir="ltr">
                                                <?= htmlspecialchars($r['driver_email'] ?: ($r['driver_phone'] ?: '-')) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- 2. حساب الفضاء -->
                                <td class="py-4 px-4 whitespace-nowrap text-center">
                                    <?php if ($isSpaceActive): ?>
                                        <div class="inline-flex flex-col items-center">
                                            <span class="inline-flex items-center justify-center px-3 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                                                نشط
                                            </span>
                                            <?php if ($hasPromo): ?>
                                                <div class="mt-1.5 p-2 rounded-xl bg-[#1a1f2c] border border-slate-700/70 text-slate-300 text-[10px] min-w-[130px] shadow-sm">
                                                    <div class="text-sky-400 font-bold text-center mb-1"><?= htmlspecialchars($promoTitle) ?></div>
                                                    <div class="flex items-center justify-between text-slate-400 font-num text-[10px] gap-2">
                                                        <span>من</span>
                                                        <span dir="ltr"><?= $startFormatted ?></span>
                                                    </div>
                                                    <div class="flex items-center justify-between text-slate-400 font-num text-[10px] gap-2">
                                                        <span>إلى</span>
                                                        <span dir="ltr"><?= $endFormatted ?></span>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-[11px] font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30">
                                            بدون فضاء
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 3. المركبات التي يقودها -->
                                <td class="py-4 px-4 whitespace-nowrap text-center">
                                    <div class="text-base font-black font-num text-white"><?= $vCount ?></div>
                                    <?php if ($vCount > 0 && !empty($firstPlate)): ?>
                                        <div class="text-[11px] font-bold font-num text-slate-400 mt-0.5" dir="ltr">
                                            <?= htmlspecialchars($firstPlate) ?>
                                        </div>
                                        <?php if (!empty($firstDoor)): ?>
                                            <div class="text-[10px] text-amber-400 font-num">باب: <?= htmlspecialchars($firstDoor) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-[10px] text-slate-500">-</div>
                                    <?php endif; ?>
                                </td>

                                <!-- 4. اشتراك الفضاء -->
                                <td class="py-4 px-4 whitespace-nowrap text-center">
                                    <?php if (!$isSpaceActive): ?>
                                        <span class="text-slate-500 font-bold">-</span>
                                    <?php else: ?>
                                        <div class="font-bold font-num text-xs text-slate-100" dir="ltr">
                                            <?= number_format($sFee, 2, ',', ' ') ?> دج
                                        </div>
                                        <?php if ($isSpaceFree): ?>
                                            <div class="mt-1">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <span>مجاني</span>
                                                </span>
                                            </div>
                                            <?php if (!empty($spaceTrialPill)): ?>
                                                <div class="text-[10px] text-slate-400 font-num mt-0.5"><?= htmlspecialchars($spaceTrialPill) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="mt-1">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                                                    <span>نشط</span>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. cotis. المركبات -->
                                <td class="py-4 px-4 whitespace-nowrap text-center">
                                    <?php if ($vCount === 0): ?>
                                        <span class="text-slate-500 font-bold">-</span>
                                    <?php else: ?>
                                        <div class="font-bold font-num text-xs text-slate-100" dir="ltr">
                                            <?= number_format($vFee, 2, ',', ' ') ?> دج
                                        </div>
                                        <div class="mt-1">
                                            <?php if ($isPaid): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                    </svg>
                                                    <span>مدفوع</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30">
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <span>لم يدفع بعد</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 6. المبلغ المستحق -->
                                <td class="py-4 px-4 whitespace-nowrap text-center">
                                    <div class="font-black font-num text-sm text-white" dir="ltr">
                                        <?= number_format($isPaid ? 0.0 : $remaining, 2, ',', ' ') ?> دج
                                    </div>
                                </td>

                                <!-- 7. الحالة -->
                                <td class="py-4 px-4 whitespace-nowrap text-center">
                                    <?php if ($isPaid): ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            <span>تم الدفع</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>في الانتظار</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 8. الإجراءات (Exact Layout & Functions matching Screenshot 1) -->
                                <td class="py-4 px-4 whitespace-nowrap text-left">
                                    <div class="flex flex-col items-end gap-1.5 min-w-[170px]">
                                        <!-- Quick Pay Button if pending and has vehicle -->
                                        <?php if (!$isPaid && $vCount > 0): ?>
                                            <button type="button" 
                                                    onclick="openDriverVehiclePayment(<?= (int)$r['driver_id'] ?>, '<?= htmlspecialchars($r['driver_name'], ENT_QUOTES) ?>', <?= $vFee ?>)"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-bold text-xs shadow-md transition cursor-pointer mb-1">
                                                <span class="opacity-90">المركبة:</span>
                                                <span>💳 دفع المركبة</span>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Row 1: المركبة Actions -->
                                        <div class="flex items-center justify-end gap-2 text-xs text-slate-400">
                                            <span class="text-[11px] font-medium text-slate-400">المركبة</span>
                                            <div class="inline-flex items-center gap-1">
                                                <!-- Print Slip -->
                                                <?php if (!empty($r['subscription_id'])): ?>
                                                    <a href="<?= url('/dashboard/invoice.php?type=subscription&id=' . (int)$r['subscription_id']) ?>" target="_blank"
                                                       class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white flex items-center justify-center transition border border-slate-700 cursor-pointer"
                                                       title="طباعة وصل المركبة">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                                                        </svg>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="w-7 h-7 rounded-lg bg-slate-800/40 text-slate-600 flex items-center justify-center border border-slate-800/60 opacity-40">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                                                        </svg>
                                                    </span>
                                                <?php endif; ?>

                                                <!-- Edit Custom Vehicle Price Modal (Screenshot 2) -->
                                                <button type="button" 
                                                        onclick="openCustomVehiclePriceModal(<?= (int)$r['driver_id'] ?>, '<?= htmlspecialchars($r['driver_name'], ENT_QUOTES) ?>', <?= $r['custom_vehicle_fee'] !== null ? (float)$r['custom_vehicle_fee'] : 'null' ?>, <?= (float)$feeVehicleMonthly ?>)"
                                                        class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-purple-900/40 text-purple-400 hover:text-purple-300 flex items-center justify-center transition border border-slate-700 hover:border-purple-500/50 cursor-pointer"
                                                        title="تعديل سعر اشتراك مركبة السائق">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 015.25 6H10" />
                                                    </svg>
                                                </button>

                                                <!-- Delete / Reset Subscription -->
                                                <?php if (!empty($r['subscription_id']) && is_admin()): ?>
                                                    <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من تصفير اشتراك هذا السائق لهذا الشهر؟')" class="inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_subscription">
                                                        <input type="hidden" name="subscription_id" value="<?= $r['subscription_id'] ?>">
                                                        <button type="submit" 
                                                                class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-rose-900/40 text-rose-400 hover:text-rose-300 flex items-center justify-center transition border border-slate-700 hover:border-rose-500/50 cursor-pointer"
                                                                title="حذف اشتراك المركبة">
                                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="w-7 h-7 rounded-lg bg-slate-800/40 text-slate-600 flex items-center justify-center border border-slate-800/60 opacity-40">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                        </svg>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Row 2: الفضاء Actions -->
                                        <div class="flex items-center justify-end gap-2 text-xs text-slate-400">
                                            <span class="text-[11px] font-medium text-slate-400">الفضاء</span>
                                            <div class="inline-flex items-center gap-1">
                                                <!-- Print Slip -->
                                                <?php if (!empty($r['subscription_id'])): ?>
                                                    <a href="<?= url('/dashboard/invoice.php?type=subscription&id=' . (int)$r['subscription_id']) ?>" target="_blank"
                                                       class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white flex items-center justify-center transition border border-slate-700 cursor-pointer"
                                                       title="طباعة وصل الفضاء">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                                                        </svg>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="w-7 h-7 rounded-lg bg-slate-800/40 text-slate-600 flex items-center justify-center border border-slate-800/60 opacity-40">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                                                        </svg>
                                                    </span>
                                                <?php endif; ?>

                                                <!-- Edit Space Subscription -->
                                                <button type="button" 
                                                        onclick="openDriverSpacePayment(<?= (int)$r['driver_id'] ?>, '<?= htmlspecialchars($r['driver_name'], ENT_QUOTES) ?>', <?= (float)$sFee ?>)"
                                                        class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-sky-900/40 text-sky-400 hover:text-sky-300 flex items-center justify-center transition border border-slate-700 hover:border-sky-500/50 cursor-pointer"
                                                        title="دفع أو تعديل اشتراك الفضاء">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 015.25 6H10" />
                                                    </svg>
                                                </button>

                                                <!-- Delete / Reset Space Subscription -->
                                                <?php if (!empty($r['subscription_id']) && is_admin()): ?>
                                                    <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من تصفير اشتراك الفضاء لهذا الشهر؟')" class="inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_subscription">
                                                        <input type="hidden" name="subscription_id" value="<?= $r['subscription_id'] ?>">
                                                        <button type="submit" 
                                                                class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-rose-900/40 text-rose-400 hover:text-rose-300 flex items-center justify-center transition border border-slate-700 hover:border-rose-500/50 cursor-pointer"
                                                                title="حذف اشتراك الفضاء">
                                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="w-7 h-7 rounded-lg bg-slate-800/40 text-slate-600 flex items-center justify-center border border-slate-800/60 opacity-40">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                        </svg>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center">
                                <div class="font-bold text-slate-300 text-sm">لا توجد بيانات اشتراكات مسجلة للفترة المحددة</div>
                                <div class="text-xs text-slate-500 mt-1">يمكنك تسجيل وتوثيق اشتراكات السائقين يدوياً عبر زر "تسجيل دفع يدوي في المكتب" أعلاه.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls Matching Screenshot 1 Bottom Bar -->
        <div class="px-5 py-3.5 border-t border-slate-800 bg-[#15171e] flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-400">
            <!-- Left: Page controls & per page select -->
            <div class="flex items-center gap-3">
                <div class="inline-flex items-center bg-slate-800/80 rounded-xl border border-slate-700/80 p-0.5 font-num font-bold">
                    <?php if ($page < $totalPages): ?>
                        <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&per_page=<?= $perPage ?>&page=<?= $page + 1 ?>" 
                           class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-slate-700 text-slate-300 hover:text-white transition cursor-pointer"
                           title="الصفحة التالية">
                            &lt;
                        </a>
                    <?php else: ?>
                        <span class="w-7 h-7 flex items-center justify-center text-slate-600 cursor-not-allowed">&lt;</span>
                    <?php endif; ?>

                    <span class="px-3 py-1 text-slate-200 text-xs">
                        <?= $totalPages ?> / <?= $page ?>
                    </span>

                    <?php if ($page > 1): ?>
                        <a href="?month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&per_page=<?= $perPage ?>&page=<?= $page - 1 ?>" 
                           class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-slate-700 text-slate-300 hover:text-white transition cursor-pointer"
                           title="الصفحة السابقة">
                            &gt;
                        </a>
                    <?php else: ?>
                        <span class="w-7 h-7 flex items-center justify-center text-slate-600 cursor-not-allowed">&gt;</span>
                    <?php endif; ?>
                </div>

                <form method="GET" action="" class="inline-flex items-center gap-2">
                    <input type="hidden" name="month" value="<?= $selectedMonth ?>">
                    <input type="hidden" name="year" value="<?= $selectedYear ?>">
                    <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                    <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
                    <select name="per_page" onchange="this.form.submit()" 
                            class="bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl px-2.5 py-1.5 font-bold outline-none cursor-pointer">
                        <option value="10" <?= $perPage === 10 ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    <span class="text-slate-400 text-xs">العناصر لكل صفحة :</span>
                </form>
            </div>

            <!-- Right: Item counter -->
            <div class="font-num text-xs text-slate-400">
                عرض <span class="text-slate-200 font-bold"><?= $fromRecord ?></span> إلى <span class="text-slate-200 font-bold"><?= $toRecord ?></span> من <span class="text-slate-200 font-bold"><?= $totalCount ?></span> اشتراكات
            </div>
        </div>
    </div>
</div>

<!-- Modal: تسجيل دفع يدوي في المكتب (Matches User Screenshot Exact Design) -->
<div id="manualPaymentModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="relative bg-[#111927] max-w-sm sm:max-w-md w-full rounded-2xl p-5 sm:p-6 border border-[#202c40] shadow-2xl text-right m-auto space-y-3.5">
        
        <!-- Header: Close circle button (left) + Title (center/right) -->
        <div class="flex items-center justify-between pb-1">
            <button type="button" onclick="closeManualPaymentModal()" class="w-8 h-8 rounded-full bg-[#1b2537] hover:bg-[#25334c] text-slate-400 hover:text-white flex items-center justify-center transition cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h3 class="font-black text-white text-base sm:text-lg flex-1 text-center pr-8">
                تسجيل دفع يدوي في المكتب
            </h3>
        </div>

        <form method="POST" action="<?= url('/dashboard/billing.php?month=' . $selectedMonth . '&year=' . $selectedYear) ?>" id="manualPaymentForm" class="space-y-3 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="manual_payment">
            <input type="hidden" name="print_receipt" id="manualPaymentPrintReceiptInput" value="0">

            <!-- 1. اختر السائق -->
            <div>
                <label class="block text-[11px] font-bold text-slate-300 mb-1">اختر السائق</label>
                <div class="bg-[#172234] border border-[#263750] rounded-xl px-3 py-2.5 flex items-center gap-2.5 focus-within:border-amber-500 transition">
                    <select name="driver_id" id="manualPaymentDriverSelect" onchange="updateManualPaymentCalc()" required 
                            class="w-full bg-transparent text-white font-bold text-xs outline-none cursor-pointer">
                        <option value="" class="bg-[#172234] text-slate-400">-- اختر السائق من القائمة --</option>
                        <?php foreach ($allDriversList as $dl): ?>
                            <option value="<?= $dl['id'] ?>" data-vehicles="<?= (int)$dl['veh_count'] ?>" data-custom-fee="<?= $dl['custom_vehicle_fee'] !== null ? (float)$dl['custom_vehicle_fee'] : '' ?>" class="bg-[#172234] text-white">
                                <?= htmlspecialchars($dl['full_name']) ?> (فضاء)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                    </svg>
                </div>
            </div>

            <!-- 2. نوع الدفع -->
            <div>
                <label class="block text-[11px] font-bold text-slate-300 mb-1">نوع الدفع</label>
                <div class="bg-[#172234] border border-[#263750] rounded-xl px-3 py-2.5 flex items-center gap-2.5 focus-within:border-amber-500 transition">
                    <select name="payment_type" id="manualPaymentTypeSelect" onchange="updateManualPaymentCalc()" required
                            class="w-full bg-transparent text-white font-bold text-xs outline-none cursor-pointer">
                        <option value="vehicles" class="bg-[#172234] text-white">cotis. المركبات</option>
                        <option value="space" class="bg-[#172234] text-white">اشتراك الفضاء (حساب السائق)</option>
                        <option value="both" class="bg-[#172234] text-white">الكل (الفضاء + cotis. المركبات)</option>
                    </select>
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                    </svg>
                </div>
            </div>

            <!-- 3. طريقة الدفع -->
            <div>
                <label class="block text-[11px] font-bold text-slate-300 mb-1">طريقة الدفع</label>
                <div class="bg-[#172234] border border-[#263750] rounded-xl px-3 py-2.5 flex items-center gap-2.5 focus-within:border-amber-500 transition">
                    <select name="payment_method" id="manualPaymentMethodSelect" required
                            class="w-full bg-transparent text-white font-bold text-xs outline-none cursor-pointer">
                        <option value="especes" class="bg-[#172234] text-white">نقداً (في المكتب)</option>
                        <option value="baridimob" class="bg-[#172234] text-white">بريدي موب (BaridiMob)</option>
                        <option value="ccp" class="bg-[#172234] text-white">حساب بريدي جاري (CCP)</option>
                        <option value="virement" class="bg-[#172234] text-white">تحويل بنكي</option>
                    </select>
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                    </svg>
                </div>
            </div>

            <!-- 4. مدة الدفع -->
            <div>
                <label class="block text-[11px] font-bold text-slate-300 mb-1">مدة الدفع</label>
                <div class="bg-[#172234] border-2 border-amber-500/90 rounded-xl px-3 py-2.5 flex items-center gap-2.5 shadow-sm shadow-amber-500/10 focus-within:ring-2 focus-within:ring-amber-500/30 transition">
                    <select name="duration_months" id="manualPaymentDurationSelect" onchange="updateManualPaymentCalc()" required
                            class="w-full bg-transparent text-white font-black text-xs outline-none cursor-pointer">
                        <option value="1" class="bg-[#172234] text-white font-bold">شهر واحد</option>
                        <option value="3" class="bg-[#172234] text-white font-bold">
                            3 أشهر (خصم <?= ($prepayDiscountOffers[3]['is_enabled'] && $prepayDiscountOffers[3]['discount_per_month'] > 0) ? number_format($prepayDiscountOffers[3]['discount_per_month'], 0) : '0' ?> دج/شهر)
                        </option>
                        <option value="6" class="bg-[#172234] text-white font-bold">
                            6 أشهر (خصم <?= ($prepayDiscountOffers[6]['is_enabled'] && $prepayDiscountOffers[6]['discount_per_month'] > 0) ? number_format($prepayDiscountOffers[6]['discount_per_month'], 0) : '0' ?> دج/شهر)
                        </option>
                        <option value="9" class="bg-[#172234] text-white font-bold">
                            9 أشهر (خصم <?= ($prepayDiscountOffers[9]['is_enabled'] && $prepayDiscountOffers[9]['discount_per_month'] > 0) ? number_format($prepayDiscountOffers[9]['discount_per_month'], 0) : '0' ?> دج/شهر)
                        </option>
                        <option value="12" class="bg-[#172234] text-white font-bold">
                            12 شهر (خصم <?= ($prepayDiscountOffers[12]['is_enabled'] && $prepayDiscountOffers[12]['discount_per_month'] > 0) ? number_format($prepayDiscountOffers[12]['discount_per_month'], 0) : '0' ?> دج/شهر)
                        </option>
                    </select>
                    <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>

            <!-- 5. الشهر والسنة (2 Columns) -->
            <div class="grid grid-cols-2 gap-3">
                <!-- الشهر -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-300 mb-1">الشهر</label>
                    <div class="bg-[#172234] border border-[#263750] rounded-xl px-3 py-2 flex items-center gap-2 focus-within:border-amber-500 transition">
                        <select name="payment_month" id="manualPaymentMonthSelect" onchange="updateManualPaymentCalc()" required
                                class="w-full bg-transparent text-white font-bold text-xs outline-none cursor-pointer">
                            <?php foreach ($monthNames as $num => $name): ?>
                                <option value="<?= $num ?>" <?= $num === $selectedMonth ? 'selected' : '' ?> class="bg-[#172234] text-white">
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5" />
                        </svg>
                    </div>
                </div>

                <!-- السنة -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-300 mb-1">السنة</label>
                    <div class="bg-[#172234] border border-[#263750] rounded-xl px-3 py-2 flex items-center gap-2 focus-within:border-amber-500 transition">
                        <select name="payment_year" id="manualPaymentYearSelect" onchange="updateManualPaymentCalc()" required
                                class="w-full bg-transparent text-white font-bold text-xs font-num outline-none cursor-pointer">
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?> class="bg-[#172234] text-white font-num">
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- 6. المبلغ (دج) -->
            <div>
                <label class="block text-[11px] font-bold text-slate-300 mb-1">المبلغ (دج)</label>
                <div class="bg-[#172234] border border-[#263750] rounded-xl px-3.5 py-2.5 flex items-center gap-2.5 focus-within:border-amber-500 transition">
                    <input type="number" name="amount" id="manualPaymentAmountInput" value="4000" min="0" step="50" required
                           class="w-full bg-transparent text-white font-num font-black text-sm outline-none">
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                    </svg>
                </div>
            </div>

            <!-- Action Buttons Grid: Green (Print) + Orange (Save) -->
            <div class="grid grid-cols-2 gap-2.5 pt-2">
                <button type="button" onclick="submitManualPayment(true)"
                        class="w-full bg-[#10b981] hover:bg-[#059669] text-white font-black py-2.5 px-3 rounded-xl flex items-center justify-center gap-2 text-xs transition shadow-md cursor-pointer">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.07-.37-2.18-.37-3.329 0-4.418 3.582-8 8-8s8 3.582 8 8c0 1.15-.13 2.26-.37 3.329m-15.26 0A7.962 7.962 0 004 10.5C4 6.082 7.582 2.5 12 2.5s8 3.582 8 8c0 1.18-.255 2.3-.72 3.329m-14.56 0h14.56m-14.56 0A3.5 3.5 0 003.5 17h17a3.5 3.5 0 00-.72-3.171M6 17v4h12v-4" />
                    </svg>
                    <span>تسجيل وطباعة الوصل</span>
                </button>

                <button type="button" onclick="submitManualPayment(false)"
                        class="w-full bg-[#f59e0b] hover:bg-[#d97706] text-slate-950 font-black py-2.5 px-3 rounded-xl flex items-center justify-center gap-2 text-xs transition shadow-md cursor-pointer">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>تسجيل فقط</span>
                </button>
            </div>

            <!-- Cancel Button -->
            <button type="button" onclick="closeManualPaymentModal()"
                    class="w-full text-center text-slate-400 hover:text-white text-xs font-bold py-1.5 transition cursor-pointer">
                إلغاء
            </button>
        </form>

    </div>
</div>

<!-- Modal: Edit Subscription Record -->
<div id="editSubModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-lg">تعديل اشتراك ومستحقات السائق</h3>
                <span id="editSubDriverLabel" class="text-xs font-semibold text-amber-700 block mt-0.5"></span>
            </div>
            <button onclick="closeEditSubModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_subscription">
            <input type="hidden" name="subscription_id" id="editSubId">
            <input type="hidden" name="driver_id" id="editSubDriverId">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">اشتراك الفضاء الشهري (دج)</label>
                    <input type="number" step="100" name="space_fee" id="editSubSpaceFee" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">اشتراك المركبة (دج)</label>
                    <input type="number" step="100" name="vehicle_fee" id="editSubVehicleFee" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">المبلغ المسدد فعلياً (دج)</label>
                    <input type="number" step="100" name="paid_amount" id="editSubPaidAmount" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num font-bold text-emerald-600 focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">حالة التسديد</label>
                    <select name="payment_status" id="editSubStatus" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white transition">
                        <option value="regle">مُسدد بالكامل (Réglé)</option>
                        <option value="partiel">تسوية جزئية (Partiel)</option>
                        <option value="en_attente">معلق (En attente)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">طريقة الدفع</label>
                    <select name="payment_method" id="editSubMethod" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white transition">
                        <option value="baridimob">بريدي موب (BaridiMob)</option>
                        <option value="especes">نقداً (Espèces)</option>
                        <option value="ccp">حوالة بريدية (CCP)</option>
                        <option value="virement">تحويل بنكي</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">ملاحظات الفوترة</label>
                    <input type="text" name="notes" id="editSubNotes" placeholder="ملاحظة أو تعديل..."
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditSubModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: تعديل سعر اشتراك مركبة السائق (Screenshot 2 Exact Design & Functions) -->
<div id="customVehicleFeeModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="relative bg-[#171922] max-w-sm sm:max-w-md w-full rounded-2xl p-5 sm:p-6 border border-purple-900/60 shadow-2xl text-right m-auto space-y-4">
        
        <!-- Header -->
        <div class="flex items-start justify-between">
            <button type="button" onclick="closeCustomVehiclePriceModal()" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition cursor-pointer">
                ✕
            </button>
            <div class="flex items-center gap-3 text-right">
                <div>
                    <h3 class="text-base font-bold text-white">تعديل سعر اشتراك مركبة السائق</h3>
                    <p class="text-xs text-purple-400 font-medium mt-0.5" id="customVehicleFeeDriverName">-</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-purple-600/20 text-purple-400 border border-purple-500/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                </div>
            </div>
        </div>

        <form method="POST" action="<?= url('/dashboard/billing.php?month=' . $selectedMonth . '&year=' . $selectedYear) ?>" id="customVehicleFeeForm" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_custom_vehicle_fee">
            <input type="hidden" name="driver_id" id="customVehicleFeeDriverId">
            <input type="hidden" name="is_default" id="customVehicleFeeIsDefault" value="0">

            <div>
                <label class="block font-bold text-slate-300 text-xs mb-2">
                    الاشتراك الشهري لكل مركبة (دج / شهر)
                </label>
                <div class="relative">
                    <input type="number" 
                           step="100" 
                           min="0"
                           name="custom_vehicle_fee" 
                           id="customVehicleFeeInput" 
                           oninput="updateVehicleFeePreview()"
                           class="w-full px-4 py-3 pl-24 rounded-xl bg-[#202430] border-2 border-purple-500/70 text-white font-num font-bold text-sm focus:ring-2 focus:ring-purple-500/40 focus:border-purple-400 outline-none transition" 
                           placeholder="مثال: 3000">
                    <button type="button" 
                            onclick="resetVehicleFeeToDefault()"
                            class="absolute left-2.5 top-1/2 -translate-y-1/2 px-2.5 py-1 rounded-lg bg-slate-700/80 hover:bg-slate-700 text-slate-300 hover:text-white text-[11px] font-bold flex items-center gap-1 transition cursor-pointer border border-slate-600/60 shadow-xs">
                        <span>✕ افتراضي</span>
                    </button>
                </div>
            </div>

            <!-- Dynamic Notification Box matching Screenshot 2 -->
            <div class="p-3.5 rounded-xl bg-purple-950/40 border border-purple-800/50 text-purple-200 text-xs leading-relaxed" id="customVehicleFeePreviewText">
                سيتم تطبيق تسعيرة خاصة قدرها 3000.00 دج لكل مركبة لهذا السائق بدلاً من 4000.00 دج.
            </div>

            <div class="flex items-center justify-between gap-3 pt-2">
                <button type="submit" class="flex-1 py-2.5 px-4 rounded-xl bg-gradient-to-r from-purple-600 to-fuchsia-600 hover:from-purple-700 hover:to-fuchsia-700 text-white font-bold text-xs transition shadow-lg shadow-purple-600/25 cursor-pointer">
                    تأكيد وحفظ
                </button>
                <button type="button" onclick="closeCustomVehiclePriceModal()" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition cursor-pointer">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const BILLING_CONFIG = {
    spaceFee: <?= json_encode($feeSpaceMonthly) ?>,
    vehicleFee: <?= json_encode($feeVehicleMonthly) ?>,
    selectedYear: <?= json_encode((int)$selectedYear) ?>,
    selectedMonth: <?= json_encode((int)$selectedMonth) ?>,
    monthNames: <?= json_encode($monthNames) ?>,
    offers: <?= json_encode($prepayDiscountOffers) ?>
};

let currentDefaultVehicleFee = <?= json_encode((float)$feeVehicleMonthly) ?>;

function submitManualPayment(printReceipt) {
    const printInput = document.getElementById('manualPaymentPrintReceiptInput');
    if (printInput) {
        printInput.value = printReceipt ? '1' : '0';
    }
    const form = document.getElementById('manualPaymentForm');
    if (form) {
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        form.submit();
    }
}

function closeManualPaymentModal() {
    const modal = document.getElementById('manualPaymentModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function updateManualPaymentCalc() {
    const driverSelect = document.getElementById('manualPaymentDriverSelect');
    const typeSelect = document.getElementById('manualPaymentTypeSelect');
    const durationSelect = document.getElementById('manualPaymentDurationSelect');
    const amountInput = document.getElementById('manualPaymentAmountInput');

    const m = parseInt(durationSelect ? durationSelect.value : 1) || 1;
    const paymentType = typeSelect ? typeSelect.value : 'vehicles';

    let vehCount = 1;
    let customFee = null;
    if (driverSelect && driverSelect.selectedIndex > 0) {
        const opt = driverSelect.options[driverSelect.selectedIndex];
        const vAttr = parseInt(opt.getAttribute('data-vehicles'));
        vehCount = isNaN(vAttr) ? 1 : Math.max(1, vAttr);
        const cAttr = opt.getAttribute('data-custom-fee');
        if (cAttr && !isNaN(parseFloat(cAttr))) {
            customFee = parseFloat(cAttr);
        }
    }

    const spaceFee = parseFloat(BILLING_CONFIG.spaceFee) || 0;
    const vehicleFee = (customFee !== null) ? customFee : (parseFloat(BILLING_CONFIG.vehicleFee) || 0);

    let monthlyBase = 0;
    if (paymentType === 'vehicles') {
        monthlyBase = vehCount * vehicleFee;
    } else if (paymentType === 'space') {
        monthlyBase = spaceFee;
    } else {
        monthlyBase = spaceFee + (vehCount * vehicleFee);
    }

    const totalBase = monthlyBase * m;

    const offer = BILLING_CONFIG.offers[m];
    const discountPerMonth = (offer && offer.is_enabled) ? (parseFloat(offer.discount_per_month) || 0) : 0;
    const totalDiscount = discountPerMonth * m;
    const netTotal = Math.max(0, totalBase - totalDiscount);

    if (amountInput) {
        amountInput.value = netTotal;
    }
}

function openDriverPayment(driverId, driverName, amount) {
    const modal = document.getElementById('manualPaymentModal');
    if (!modal) return;
    const select = document.getElementById('manualPaymentDriverSelect');
    if (select) {
        select.value = driverId;
    }
    const durationSelect = document.getElementById('manualPaymentDurationSelect');
    if (durationSelect) {
        durationSelect.value = '1';
    }
    updateManualPaymentCalc();
    const inputAmount = document.getElementById('manualPaymentAmountInput');
    if (inputAmount && amount > 0) {
        inputAmount.value = amount;
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function openDriverVehiclePayment(driverId, driverName, amount) {
    const modal = document.getElementById('manualPaymentModal');
    if (!modal) return;
    const select = document.getElementById('manualPaymentDriverSelect');
    if (select) select.value = driverId;
    const typeSelect = document.getElementById('manualPaymentTypeSelect');
    if (typeSelect) typeSelect.value = 'vehicles';
    const durationSelect = document.getElementById('manualPaymentDurationSelect');
    if (durationSelect) durationSelect.value = '1';
    updateManualPaymentCalc();
    const inputAmount = document.getElementById('manualPaymentAmountInput');
    if (inputAmount && amount > 0) {
        inputAmount.value = amount;
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function openDriverSpacePayment(driverId, driverName, amount) {
    const modal = document.getElementById('manualPaymentModal');
    if (!modal) return;
    const select = document.getElementById('manualPaymentDriverSelect');
    if (select) select.value = driverId;
    const typeSelect = document.getElementById('manualPaymentTypeSelect');
    if (typeSelect) typeSelect.value = 'space';
    const durationSelect = document.getElementById('manualPaymentDurationSelect');
    if (durationSelect) durationSelect.value = '1';
    updateManualPaymentCalc();
    const inputAmount = document.getElementById('manualPaymentAmountInput');
    if (inputAmount && amount > 0) {
        inputAmount.value = amount;
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

// Screenshot 2 Custom Vehicle Fee Modal JS Handlers
function openCustomVehiclePriceModal(driverId, driverName, customFee, defaultFee) {
    currentDefaultVehicleFee = defaultFee || 4000;
    const modal = document.getElementById('customVehicleFeeModal');
    if (!modal) return;

    document.getElementById('customVehicleFeeDriverId').value = driverId;
    document.getElementById('customVehicleFeeDriverName').textContent = driverName;
    const input = document.getElementById('customVehicleFeeInput');
    const isDefaultInput = document.getElementById('customVehicleFeeIsDefault');

    if (customFee !== null && customFee !== undefined && !isNaN(customFee) && customFee > 0) {
        input.value = customFee;
        isDefaultInput.value = '0';
    } else {
        input.value = '';
        isDefaultInput.value = '1';
    }

    updateVehicleFeePreview();
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeCustomVehiclePriceModal() {
    const modal = document.getElementById('customVehicleFeeModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function resetVehicleFeeToDefault() {
    const input = document.getElementById('customVehicleFeeInput');
    const isDefaultInput = document.getElementById('customVehicleFeeIsDefault');
    input.value = '';
    isDefaultInput.value = '1';
    updateVehicleFeePreview();
}

function updateVehicleFeePreview() {
    const input = document.getElementById('customVehicleFeeInput');
    const isDefaultInput = document.getElementById('customVehicleFeeIsDefault');
    const preview = document.getElementById('customVehicleFeePreviewText');
    const val = parseFloat(input.value);

    if (isNaN(val) || val <= 0 || isDefaultInput.value === '1') {
        isDefaultInput.value = '1';
        preview.textContent = 'سيتم تطبيق التسعيرة الافتراضية للنظام وقدرها ' + Number(currentDefaultVehicleFee).toFixed(2) + ' دج لكل مركبة لهذا السائق.';
    } else {
        isDefaultInput.value = '0';
        preview.textContent = 'سيتم تطبيق تسعيرة خاصة قدرها ' + Number(val).toFixed(2) + ' دج لكل مركبة لهذا السائق بدلاً من ' + Number(currentDefaultVehicleFee).toFixed(2) + ' دج.';
    }
}

function openEditSubModal(data) {
    document.getElementById('editSubId').value = data.subscription_id || 0;
    document.getElementById('editSubDriverId').value = data.driver_id || 0;
    document.getElementById('editSubDriverLabel').textContent = 'السائق: ' + (data.driver_name || '');
    document.getElementById('editSubSpaceFee').value = data.space_fee || 1000;
    document.getElementById('editSubVehicleFee').value = data.vehicle_fee || 0;
    document.getElementById('editSubPaidAmount').value = data.paid_amount || 0;
    document.getElementById('editSubStatus').value = data.payment_status || 'en_attente';
    document.getElementById('editSubMethod').value = data.payment_method || 'baridimob';
    document.getElementById('editSubNotes').value = data.notes || '';

    const modal = document.getElementById('editSubModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditSubModal() {
    const modal = document.getElementById('editSubModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
