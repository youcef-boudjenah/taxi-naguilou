<?php
// dashboard/driver_fiche.php - Single Unified Driver Dashboard & Professional Account (واجهة السائق الموحدة)
require_once __DIR__ . '/../includes/auth.php';
require_login();

$driverId = intval($_GET['id'] ?? 0);
$pdo = getDBConnection();

// Fallback: If no ID supplied, default to logged-in user if driver, or first driver for admin
if ($driverId <= 0 && $pdo) {
    if (is_driver() && !empty($_SESSION['user_id'])) {
        $driverId = intval($_SESSION['user_id']);
    } else {
        $firstId = (int)$pdo->query("SELECT id FROM users WHERE role = 'driver' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstId > 0) $driverId = $firstId;
    }
}

// Access Control: A driver can only view his own profile unless Admin/Owner
if (is_driver() && $driverId !== intval($_SESSION['user_id'])) {
    set_flash('error', 'عذراً، بصفتك سائقاً يمكنك فقط الاطلاع على ملفك وحسابك المهني الخاص.');
    redirect('/dashboard/driver_fiche.php?id=' . intval($_SESSION['user_id']));
    exit;
}

// 1. Handle Self-Service Profile Update (Phone, Email, Address, Civil Status, Avatar & Password)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    require_csrf_token();
    $targetId = intval($_POST['driver_id'] ?? 0);

    if ($targetId > 0 && (!is_driver() || (int)$_SESSION['user_id'] === $targetId)) {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $birthDate = (!empty($_POST['birth_date']) && $_POST['birth_date'] !== '0000-00-00') ? trim($_POST['birth_date']) : null;
        $birthPlace = trim($_POST['birth_place'] ?? '');
        $fatherName = trim($_POST['father_name'] ?? '');
        $motherName = trim($_POST['mother_name'] ?? '');

        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');

        if (empty($phone)) {
            set_flash('error', 'رقم الهاتف مطلوب ولا يمكن تركه فارغاً.');
        } elseif (!empty($newPass) && strlen($newPass) < 6) {
            set_flash('error', 'يجب أن تتكون كلمة المرور الجديدة من 6 أحرف أو أرقام على الأقل.');
        } elseif (!empty($newPass) && $newPass !== $confirmPass) {
            set_flash('error', 'كلمتا المرور غير متطابقتين، يرجى إعادة التأكيد بدقة.');
        } else {
            // Handle photo upload
            $avatarUrl = null;
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExts)) {
                    $destDir = __DIR__ . '/../uploads/drivers';
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0777, true);
                    }
                    $fileName = 'driver_' . $targetId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destDir . '/' . $fileName)) {
                        $avatarUrl = '/uploads/drivers/' . $fileName;
                    }
                }
            }

            try {
                $params = [$phone, $address, $birthDate, $birthPlace, $fatherName, $motherName, $firstName ?: null, $lastName ?: null];
                $sql = "UPDATE users SET phone = ?, address = ?, birth_date = ?, birth_place = ?, father_name = ?, mother_name = ?, first_name = ?, last_name = ?";
                
                $compName = trim(($lastName ? $lastName . ' ' : '') . $firstName);
                if (!empty($compName)) {
                    $sql .= ", full_name = ?";
                    $params[] = $compName;
                }
                if (!empty($email)) {
                    $sql .= ", email = ?";
                    $params[] = $email;
                }
                if ($avatarUrl) {
                    $sql .= ", avatar_url = ?";
                    $params[] = $avatarUrl;
                }
                if (!empty($newPass)) {
                    $sql .= ", password_hash = ?";
                    $params[] = password_hash($newPass, PASSWORD_BCRYPT);
                }

                if (is_admin()) {
                    $sql .= " WHERE id = ?";
                    $params[] = $targetId;
                } else {
                    $sql .= " WHERE id = ? AND (role = 'driver' OR role = 'owner')";
                    $params[] = $targetId;
                }

                $stmtUp = $pdo->prepare($sql);
                $stmtUp->execute($params);

                if ((int)$_SESSION['user_id'] === $targetId) {
                    $_SESSION['user_phone'] = $phone;
                    if (!empty($email)) $_SESSION['user_email'] = $email;
                }

                log_audit($pdo, 'driver_profile_updated', 'users', $targetId, "تحديث الملف الشخصي ومعلومات الهوية للسائق رقم $targetId");
                set_flash('success', 'تم حفظ وتحديث بيانات حسابك ومعلومات الحالة المدنية بنجاح.');
            } catch (\Throwable $e) {
                set_flash('error', 'حدث خطأ أثناء حفظ التعديلات: ' . $e->getMessage());
            }
        }
        redirect('/dashboard/driver_fiche.php?id=' . $targetId);
        exit;
    }
}

// 2. Handle BaridiMob Payment & Receipt Submission by Driver
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_baridimob_payment') {
    require_csrf_token();
    $targetDriverId = intval($_POST['driver_id'] ?? 0);

    if ($targetDriverId > 0 && (!is_driver() || (int)$_SESSION['user_id'] === $targetDriverId)) {
        $amount = floatval($_POST['amount'] ?? 0);
        $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
        if (empty($paymentDate)) $paymentDate = date('Y-m-d');
        $receiptRef = trim($_POST['receipt_ref'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($amount <= 0) {
            set_flash('error', 'يرجى إدخال مبلغ دفع صالح بالدينار الجزائري.');
        } else {
            // Find or create driver_debts record for this driver
            $stmtDebt = $pdo->prepare("SELECT id FROM driver_debts WHERE user_id = ? AND deleted_at IS NULL LIMIT 1");
            $stmtDebt->execute([$targetDriverId]);
            $debtId = (int)$stmtDebt->fetchColumn();

            if ($debtId <= 0) {
                $uStmt = $pdo->prepare("SELECT full_name, phone FROM users WHERE id = ?");
                $uStmt->execute([$targetDriverId]);
                $uData = $uStmt->fetch();
                $uName = $uData['full_name'] ?? ('سائق #' . $targetDriverId);
                $uPhone = $uData['phone'] ?? '';

                $stmtNewDebt = $pdo->prepare("
                    INSERT INTO driver_debts (user_id, chauffeur_name, phone, total_credits, total_versements, driver_type, status, notes)
                    VALUES (?, ?, ?, 0, 0, 'actif', 'endette', 'سجل مالي منشأ تلقائياً عند أول دفعة')
                ");
                $stmtNewDebt->execute([$targetDriverId, $uName, $uPhone]);
                $debtId = (int)$pdo->lastInsertId();
            }

            // Handle Receipt Image Upload
            $receiptImageUrl = null;
            if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                $ext = strtolower(pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExts)) {
                    $destDir = __DIR__ . '/../uploads/receipts';
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0777, true);
                    }
                    $fileName = 'recu_bm_' . $targetDriverId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $destDir . '/' . $fileName)) {
                        $receiptImageUrl = '/uploads/receipts/' . $fileName;
                    }
                }
            }

            if (empty($receiptRef)) {
                $receiptRef = 'BM-' . date('ymd') . '-' . rand(100, 999);
            }

            try {
                // Insert versement with status = 'pending'
                $stmtInsV = $pdo->prepare("
                    INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, receipt_image, status, notes)
                    VALUES (?, ?, 'baridimob', ?, ?, ?, 'pending', ?)
                ");
                $stmtInsV->execute([
                    $debtId,
                    $amount,
                    $paymentDate,
                    $receiptRef,
                    $receiptImageUrl,
                    $notes ?: 'دفعة مسددة عبر بريدي موب (بانتظار التأكيد)'
                ]);
                $newVId = (int)$pdo->lastInsertId();

                // Send live notification to Admins
                $adminIds = $pdo->query("SELECT id FROM users WHERE role IN ('super_admin', 'admin') AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
                if (empty($adminIds)) $adminIds = [1];

                $stmtDName = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmtDName->execute([$targetDriverId]);
                $driverName = $stmtDName->fetchColumn() ?: ('سائق #' . $targetDriverId);
                $notifTitle = "💳 دفعة بريدي موب جديدة قيد التأكيد";
                $notifMsg = "قام السائق {$driverName} بتسجيل دفعة بريدي موب بمبلغ " . number_format($amount, 2) . " دج (وصل: {$receiptRef}). يرجى المراجعة والاعتماد.";
                $notifLink = "/dashboard/debts.php?open_debt_id={$debtId}&tab=versements";

                $stmtNotif = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
                    VALUES (?, 'info', ?, ?, ?, 0, NOW())
                ");
                foreach ($adminIds as $aId) {
                    $stmtNotif->execute([$aId, $notifTitle, $notifMsg, $notifLink]);
                }

                log_audit($pdo, 'payment_submitted', 'versements', $newVId, "تسجيل دفعة بريدي موب بمبلغ $amount دج للسائق $driverName برقم وصل $receiptRef");
                set_flash('success', 'تم إرسال وصل الدفعة بنجاح! تم إشعار الإدارة لمراجعة التحويل واعتماد الرصيد فوراً.');
            } catch (\Throwable $e) {
                set_flash('error', 'حدث خطأ أثناء حفظ الدفعة: ' . $e->getMessage());
            }
        }
        redirect('/dashboard/driver_fiche.php?id=' . $targetDriverId);
        exit;
    }
}

// 2. Fetch Full Driver Profile with Linked Vehicles & Contract
$driver = null;
if ($pdo && $driverId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   v.id as vehicle_id, v.brand, v.model, v.plate_number, v.door_number, v.color,
                   v.insurance_expiry, v.insurance_company, v.technical_control_expiry,
                   v.extinguisher_expiry, v.gpl_expiry, v.gpl_text,
                   v.saccuriometre_expiry, v.saccuriometre_text,
                   v.status as vehicle_status, v.suspended_reason as vehicle_suspended_reason,
                   (CASE WHEN v.replacement_driver_id = u.id THEN 1 ELSE 0 END) as is_veh_replacement,
                   (SELECT COUNT(*) FROM vehicles v2 WHERE v2.replacement_driver_id = u.id AND v2.deleted_at IS NULL) as veh_repl_count,
                   (SELECT COUNT(*) FROM leaves l WHERE l.replacement_driver_id = u.id AND l.status = 'approved' AND l.end_date >= CURDATE() AND l.deleted_at IS NULL) as act_leave_repl_count,
                   c.id as contract_id, c.contract_number, c.start_date as contract_start, c.end_date as contract_end, 
                   c.monthly_wage, c.status as contract_status, c.signed_at as contract_signed_at,
                   u_owner.full_name as owner_name, u_owner.phone as owner_phone, u_owner.email as owner_email
            FROM users u
            LEFT JOIN vehicles v ON ((v.current_driver_id = u.id OR v.replacement_driver_id = u.id) AND v.deleted_at IS NULL)
            LEFT JOIN contracts c ON (c.driver_id = u.id AND c.deleted_at IS NULL)
            LEFT JOIN users u_owner ON ((c.owner_id = u_owner.id OR v.owner_id = u_owner.id) AND u_owner.deleted_at IS NULL)
            WHERE u.id = ? AND (u.role = 'driver' OR u.role = 'owner') " . (is_admin() ? "" : "AND u.deleted_at IS NULL") . "
            ORDER BY c.id DESC
            LIMIT 1
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

if (!$driver) {
    set_flash('error', 'السائق المطلوب غير موجود في النظام أو تم نقله للأرشيف.');
    redirect(is_driver() ? '/dashboard/index.php' : '/dashboard/drivers.php');
    exit;
}

// 3. Fetch Financial Status (الديون، الدفعات، الرصيد المتبقي)
$driverDebt = null;
$totalDebts = 0.0;
$totalVersements = 0.0;
$remainingBalance = 0.0;
$recentVersements = [];

if ($driver && $pdo) {
    try {
        $stmtD = $pdo->prepare("SELECT * FROM driver_debts WHERE user_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmtD->execute([$driver['id']]);
        $driverDebt = $stmtD->fetch(PDO::FETCH_ASSOC);

        if ($driverDebt) {
            $totalDebts = floatval($driverDebt['total_credits'] ?? 0);
            $totalVersements = floatval($driverDebt['total_versements'] ?? 0);
            $remainingBalance = $totalDebts - $totalVersements;

            $stmtV = $pdo->prepare("SELECT * FROM versements WHERE debt_id = ? AND deleted_at IS NULL ORDER BY payment_date DESC, id DESC LIMIT 8");
            $stmtV->execute([$driverDebt['id']]);
            $recentVersements = $stmtV->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            // Fallback from driver_subscriptions
            $stmtSubs = $pdo->prepare("SELECT SUM(total_due) as due, SUM(paid_amount) as paid FROM driver_subscriptions WHERE driver_id = ? AND deleted_at IS NULL");
            $stmtSubs->execute([$driver['id']]);
            $subTotals = $stmtSubs->fetch(PDO::FETCH_ASSOC);
            $totalDebts = floatval($subTotals['due'] ?? 0);
            $totalVersements = floatval($subTotals['paid'] ?? 0);
            $remainingBalance = $totalDebts - $totalVersements;
        }
    } catch (\Throwable $e) {}
}

// Fetch Platform Bank / CCP / BaridiMob Settings (Configured by Admin)
$bankCcp = get_setting($pdo, 'bank_ccp', '0021458796');
$bankRip = get_setting($pdo, 'bank_rip', '00799999002145879624');
$baridimobNumber = get_setting($pdo, 'baridimob_number', '0663.55.00.42');
$bankAccountHolder = get_setting($pdo, 'bank_account_holder', 'EURL TAXI NAGUILOU Services');
$bankName = get_setting($pdo, 'bank_name', 'بريد الجزائر (Algérie Poste)');

// 4. Fetch Active / Signed Contract
$driverContract = null;
if ($driver && $pdo) {
    try {
        $stmtC = $pdo->prepare("
            SELECT c.*, 
                   u_owner.full_name as owner_name, u_owner.phone as owner_phone, u_owner.email as owner_email,
                   v.plate_number, v.door_number, v.brand, v.model
            FROM contracts c
            LEFT JOIN users u_owner ON c.owner_id = u_owner.id
            LEFT JOIN vehicles v ON (v.current_driver_id = c.driver_id OR v.replacement_driver_id = c.driver_id)
            WHERE c.driver_id = ? AND c.deleted_at IS NULL
            ORDER BY CASE WHEN c.status = 'signed' THEN 1 WHEN c.status = 'active' THEN 2 ELSE 3 END, c.id DESC
            LIMIT 1
        ");
        $stmtC->execute([$driver['id']]);
        $driverContract = $stmtC->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

// 4b. Monthly Subscription Dues (شعار رانا نسالوه في كل شهر)
$monthlyVehicleFee = floatval(get_setting($pdo, 'fee_vehicle_monthly', '5000.00'));
$monthlySpaceFee = floatval(get_setting($pdo, 'fee_space_monthly', '1000.00'));
$standardMonthlyFee = $monthlyVehicleFee + $monthlySpaceFee; // 6,000 DA
$currentMonthStr = date('Y-m');
$latestSubscription = null;
if ($driver && $pdo) {
    try {
        $stmtSub = $pdo->prepare("SELECT * FROM driver_subscriptions WHERE driver_id = ? AND deleted_at IS NULL ORDER BY period_month DESC LIMIT 1");
        $stmtSub->execute([$driver['id']]);
        $latestSubscription = $stmtSub->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}
$activeMonthlyDue = $latestSubscription ? floatval($latestSubscription['total_due']) : $standardMonthlyFee;

// 4c. Fetch Driver Night Shifts (المناوبة الليلية بالمؤسسة الاستشفائية / الحظيرة)
$driverShifts = [];
$driverNightWatches = [];
if ($driver && $pdo) {
    try {
        $stmtP = $pdo->query("SELECT id, list_number, title, sub_title, wilaya, period_month, shift_date, items_json FROM permanence_lists WHERE deleted_at IS NULL ORDER BY shift_date DESC, id DESC LIMIT 25");
        $allLists = $stmtP ? $stmtP->fetchAll(PDO::FETCH_ASSOC) : [];
        $driverNameClean = trim($driver['clean_name'] ?? $driver['full_name']);
        foreach ($allLists as $list) {
            $items = json_decode($list['items_json'] ?? '[]', true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $itemDriverId = intval($item['driver_id'] ?? 0);
                    $itemDriverName = trim($item['driver_name'] ?? '');
                    $matchesId = ($itemDriverId > 0 && $itemDriverId === intval($driver['id']));
                    $matchesName = (!empty($itemDriverName) && (
                        mb_stripos($itemDriverName, $driverNameClean) !== false || 
                        mb_stripos($driverNameClean, $itemDriverName) !== false
                    ));
                    if ($matchesId || $matchesName) {
                        $shiftDate = !empty($item['shift_date']) ? $item['shift_date'] : $list['shift_date'];
                        $shiftTimestamp = strtotime($shiftDate);
                        $isUpcoming = ($shiftTimestamp >= strtotime(date('Y-m-d')));
                        $isToday = (date('Y-m-d', $shiftTimestamp) === date('Y-m-d'));
                        $driverShifts[] = [
                            'list_id'         => $list['id'],
                            'list_number'     => $list['list_number'],
                            'title'           => $list['title'],
                            'sub_title'       => $list['sub_title'],
                            'wilaya'          => $list['wilaya'],
                            'period_month'    => $list['period_month'],
                            'shift_date'      => $shiftDate,
                            'shift_timestamp' => $shiftTimestamp,
                            'is_upcoming'     => $isUpcoming,
                            'is_today'        => $isToday,
                            'agrement_num'    => $item['agrement_num'] ?? '',
                            'order_num'       => $item['num'] ?? '',
                        ];
                    }
                }
            }
        }
        usort($driverShifts, function($a, $b) {
            return $b['shift_timestamp'] <=> $a['shift_timestamp'];
        });
    } catch (\Throwable $e) {}

    try {
        $stmtNW = $pdo->prepare("
            SELECT nw.*, v.plate_number, v.door_number 
            FROM night_watch nw 
            LEFT JOIN vehicles v ON v.id = nw.vehicle_id 
            WHERE (v.current_driver_id = ? OR v.replacement_driver_id = ?)
            ORDER BY nw.id DESC 
            LIMIT 5
        ");
        $stmtNW->execute([$driver['id'], $driver['id']]);
        $driverNightWatches = $stmtNW->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    // 4d. Fetch Personal Driver Notifications (إشعارات الهاتف وتنبيهات النظام)
    $driverNotifications = [];
    try {
        $stmtN = $pdo->prepare("
            SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL) 
            ORDER BY id DESC 
            LIMIT 8
        ");
        $stmtN->execute([$driver['id']]);
        $driverNotifications = $stmtN->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    // 4e. Fetch Driver Passenger Ratings & Reviews (تقييمات الركاب)
    $driverRatingsCount = 0;
    $driverAvgRating = 5.0;
    $driverCleanAvg = 5.0;
    $driverDriveAvg = 5.0;
    $driverCourtAvg = 5.0;
    $driverRecentRatings = [];
    try {
        $stmtRStat = $pdo->prepare("
            SELECT 
                COUNT(*) as cnt,
                AVG(rating) as avg_r,
                AVG(cleanliness_rating) as avg_c,
                AVG(driving_rating) as avg_d,
                AVG(courtesy_rating) as avg_ct
            FROM driver_ratings r
            LEFT JOIN vehicles v ON v.id = r.vehicle_id
            WHERE r.driver_id = ? OR v.current_driver_id = ?
        ");
        $stmtRStat->execute([$driver['id'], $driver['id']]);
        $rStat = $stmtRStat->fetch(PDO::FETCH_ASSOC);
        if ($rStat && (int)$rStat['cnt'] > 0) {
            $driverRatingsCount = (int)$rStat['cnt'];
            $driverAvgRating = round((float)$rStat['avg_r'], 1);
            $driverCleanAvg = round((float)$rStat['avg_c'], 1);
            $driverDriveAvg = round((float)$rStat['avg_d'], 1);
            $driverCourtAvg = round((float)$rStat['avg_ct'], 1);
        }

        $stmtRList = $pdo->prepare("
            SELECT r.*, v.door_number, v.plate_number 
            FROM driver_ratings r
            LEFT JOIN vehicles v ON v.id = r.vehicle_id
            WHERE r.driver_id = ? OR v.current_driver_id = ?
            ORDER BY r.id DESC 
            LIMIT 6
        ");
        $stmtRList->execute([$driver['id'], $driver['id']]);
        $driverRecentRatings = $stmtRList->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
}

// 5. Build Document Expiry Alerts (إشعارات الوثائق وخاناتها)
$today = strtotime(date('Y-m-d'));

$documents = [
    [
        'id' => 'license',
        'name' => 'رخصة السياقة المهنية',
        'icon' => '🪪',
        'type_label' => 'وثيقة شخصية',
        'doc_number' => $driver['license_number'] ?? null,
        'doc_number_label' => 'رقم الرخصة',
        'expiry_date' => (!empty($driver['license_expiry']) && $driver['license_expiry'] !== '0000-00-00') ? $driver['license_expiry'] : null,
        'details' => 'صنف ب / رخصة مهنية'
    ],
    [
        'id' => 'medical',
        'name' => 'الفحص الطبي الدوري',
        'icon' => '🩺',
        'type_label' => 'شهادة صحية',
        'doc_number' => null,
        'doc_number_label' => '',
        'expiry_date' => (!empty($driver['medical_cert_expiry']) && $driver['medical_cert_expiry'] !== '0000-00-00') 
            ? $driver['medical_cert_expiry'] 
            : ((!empty($driver['medical_exam_end']) && $driver['medical_exam_end'] !== '0000-00-00') ? $driver['medical_exam_end'] : null),
        'details' => 'شهادة اللياقة الطبية للقيادة'
    ],
    [
        'id' => 'tech_control',
        'name' => 'المراقبة التقنية للمركبة',
        'icon' => '🔍',
        'type_label' => 'فحص المركبة',
        'doc_number' => $driver['plate_number'] ?? null,
        'doc_number_label' => 'رقم التسجيل',
        'expiry_date' => (!empty($driver['technical_control_expiry']) && $driver['technical_control_expiry'] !== '0000-00-00') ? $driver['technical_control_expiry'] : null,
        'details' => 'محضر المراقبة التقنية الدورية'
    ],
    [
        'id' => 'insurance',
        'name' => 'تأمين سيارة الأجرة',
        'icon' => '🛡️',
        'type_label' => 'بوليصة تأمين',
        'doc_number' => $driver['insurance_company'] ?? null,
        'doc_number_label' => 'الشركة',
        'expiry_date' => (!empty($driver['insurance_expiry']) && $driver['insurance_expiry'] !== '0000-00-00') ? $driver['insurance_expiry'] : null,
        'details' => 'تأمين نقل الأشخاص والمسؤولية'
    ],
    [
        'id' => 'extinguisher',
        'name' => 'قارورة الإطفاء',
        'icon' => '🧯',
        'type_label' => 'تجهيزات الأمان',
        'doc_number' => null,
        'doc_number_label' => '',
        'expiry_date' => (!empty($driver['extinguisher_expiry']) && $driver['extinguisher_expiry'] !== '0000-00-00') ? $driver['extinguisher_expiry'] : null,
        'details' => 'مطفأة أمان معتمدة 1-2 كغ'
    ],
    [
        'id' => 'gpl',
        'name' => 'فحص خزان غاز الوقود GPL',
        'icon' => '⛽',
        'type_label' => 'سلامة الوقود',
        'doc_number' => $driver['gpl_text'] ?? null,
        'doc_number_label' => 'الرقم التسلسلي',
        'expiry_date' => (!empty($driver['gpl_expiry']) && $driver['gpl_expiry'] !== '0000-00-00') ? $driver['gpl_expiry'] : null,
        'details' => 'شهادة مطابقة خزان سيرغاز'
    ],
    [
        'id' => 'taximeter',
        'name' => 'معايرة عداد التاكسي',
        'icon' => '⏱️',
        'type_label' => 'القياسة القانونية',
        'doc_number' => $driver['saccuriometre_text'] ?? null,
        'doc_number_label' => 'رقم الختم',
        'expiry_date' => (!empty($driver['saccuriometre_expiry']) && $driver['saccuriometre_expiry'] !== '0000-00-00') ? $driver['saccuriometre_expiry'] : null,
        'details' => 'شهادة المطابقة والختم الرصاصي (ONML)'
    ],
];

// Evaluate each document's status
$expiredDocsCount = 0;
$expiringDocsCount = 0;
$validDocsCount = 0;

foreach ($documents as &$doc) {
    if (empty($doc['expiry_date'])) {
        $doc['status'] = 'missing';
        $doc['status_label'] = 'غير محددة';
        $doc['status_class'] = 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border-slate-200 dark:border-slate-700';
        $doc['card_border'] = 'border-slate-200/90 dark:border-slate-800 bg-white dark:bg-[#161d27]';
        $doc['days'] = null;
        $doc['days_label'] = 'التاريخ غير مسجل بالنظام';
    } else {
        $expTime = strtotime($doc['expiry_date']);
        $days = (int)round(($expTime - $today) / 86400);
        $doc['days'] = $days;
        if ($days < 0) {
            $doc['status'] = 'expired';
            $doc['status_label'] = 'منتهية الصلاحية ❌';
            $doc['status_class'] = 'bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 border-rose-300 dark:border-rose-800 font-black';
            $doc['card_border'] = 'border-rose-300 dark:border-rose-800 bg-rose-50/20 dark:bg-rose-950/10 shadow-xs ring-1 ring-rose-200 dark:ring-rose-900/40';
            $doc['days_label'] = 'انتهت منذ ' . abs($days) . ' يوم (مطلوب التجديد)';
            $expiredDocsCount++;
        } elseif ($days <= 30) {
            $doc['status'] = 'expiring';
            $doc['status_label'] = 'مقبلة على الانتهاء ⚠️';
            $doc['status_class'] = 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border-amber-300 dark:border-amber-700 font-black';
            $doc['card_border'] = 'border-amber-300 dark:border-amber-700 bg-amber-50/20 dark:bg-amber-950/10 shadow-xs ring-1 ring-amber-200 dark:ring-amber-900/40';
            $doc['days_label'] = 'متبقي ' . $days . ' يوم على الانتهاء';
            $expiringDocsCount++;
        } else {
            $doc['status'] = 'valid';
            $doc['status_label'] = 'سارية الصلاحية ✓';
            $doc['status_class'] = 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800';
            $doc['card_border'] = 'border-slate-200/90 dark:border-slate-800 bg-white dark:bg-[#161d27]';
            $doc['days_label'] = 'صالحة حتى ' . date('d/m/Y', $expTime) . ' (' . $days . ' يوم)';
            $validDocsCount++;
        }
    }
}
unset($doc);

// Probation info
$probInfo = resolve_driver_probation_info($driver);

$pageTitle = is_driver() ? 'حسابي المهني' : ('الملف المهني: ' . htmlspecialchars($driver['full_name']));
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6 max-w-7xl mx-auto pb-24 sm:pb-12">

    <!-- ==============================================
         Top Navigation / Header Actions (No Print)
         ============================================== -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 no-print bg-white dark:bg-[#161d27] p-3.5 sm:p-4 rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-2xs">
        <div class="flex items-center gap-3 flex-wrap">
            <?php if (!is_driver()): ?>
                <a href="drivers.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition whitespace-nowrap">
                    <svg class="w-4 h-4 shrink-0 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                    <span>سجل السائقين</span>
                </a>
            <?php else: ?>
                <a href="index.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 text-xs font-bold hover:bg-slate-800 transition whitespace-nowrap shadow-2xs">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                    <span>لوحة التحكم الرئيسية</span>
                </a>
            <?php endif; ?>

            <div class="hidden sm:block h-5 w-px bg-slate-200 dark:bg-slate-800"></div>

            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">حساب السائق:</span>
                <span class="font-black text-slate-900 dark:text-white text-xs sm:text-sm"><?= htmlspecialchars($driver['clean_name'] ?? $driver['full_name']) ?></span>
                <?php if (!empty($driver['is_replacement'])): ?>
                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-300 border border-violet-200">
                        سائق بديل معتمد
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <!-- Quick BaridiMob CTA -->
            <button type="button" 
                    onclick="document.getElementById('baridimobModal').classList.remove('hidden')" 
                    class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 font-black text-xs shadow-xs transition inline-flex items-center gap-1.5 whitespace-nowrap cursor-pointer">
                <span>💳</span>
                <span>تسديد بريدي موب</span>
            </button>

            <a href="debt_statement.php?user_id=<?= $driver['id'] ?>" target="_blank" 
               class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0">
                <svg class="w-3.5 h-3.5 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>كشف الحساب والديون</span>
            </a>

            <!-- Edit Profile Modal Trigger -->
            <button onclick="document.getElementById('editProfileModal').classList.remove('hidden')" 
                    class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 font-bold text-xs transition inline-flex items-center gap-1.5 whitespace-nowrap shrink-0 cursor-pointer">
                <svg class="w-3.5 h-3.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" />
                </svg>
                <span>تعديل بياناتي</span>
            </button>
        </div>
    </div>

    <!-- ==============================================
         Hero Strip: Chauffeur Card & Taxi License
         ============================================== -->
    <div class="no-print bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200/90 dark:border-slate-800 p-5 sm:p-6 shadow-2xs relative overflow-hidden">
        <!-- Top taxi stripe accent -->
        <div class="absolute top-0 right-0 left-0 h-1.5 bg-gradient-to-r from-amber-400 via-amber-500 to-amber-600"></div>

        <div class="flex flex-col md:flex-row items-center md:items-start gap-6 pt-1">
            
            <!-- Driver Avatar with Beacon -->
            <div class="relative shrink-0 group">
                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-2xl overflow-hidden border-2 border-amber-400 dark:border-amber-500 shadow-md bg-white dark:bg-slate-800 flex items-center justify-center relative">
                    <?php if (!empty($driver['avatar_url']) && file_exists(__DIR__ . '/../' . ltrim($driver['avatar_url'], '/'))): ?>
                        <img src="<?= url($driver['avatar_url']) ?>" alt="<?= htmlspecialchars($driver['full_name']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="w-full h-full flex flex-col items-center justify-center bg-gradient-to-b from-amber-50/60 to-slate-100 dark:from-slate-800 dark:to-slate-900 text-slate-700 dark:text-slate-200">
                            <div class="w-14 h-14 rounded-full bg-amber-500/20 border-2 border-amber-400/50 flex items-center justify-center text-amber-700 dark:text-amber-300 font-black text-2xl mb-1 shadow-inner font-num">
                                <?= mb_substr($driver['full_name'], 0, 1) ?>
                            </div>
                            <span class="text-[10px] font-bold text-slate-400">سائق تاكسي</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <button type="button" 
                        onclick="document.getElementById('editProfileModal').classList.remove('hidden')" 
                        class="absolute -bottom-2 -left-2 p-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 shadow-xs hover:bg-amber-50 dark:hover:bg-slate-700 transition" 
                        title="تغيير الصورة الشخصية">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                    </svg>
                </button>
            </div>

            <!-- Driver Info -->
            <div class="flex-1 space-y-3.5 text-right w-full">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-3">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                                <?= htmlspecialchars($driver['clean_name'] ?? $driver['full_name']) ?>
                            </h1>
                            <?php if (!empty($driver['is_replacement'])): ?>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-300 border border-violet-200">
                                    سائق بديل معتمد
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-slate-500 dark:text-slate-400 mt-1 flex items-center gap-2.5 flex-wrap">
                            <span>الولاية: <strong class="text-slate-800 dark:text-slate-200"><?= htmlspecialchars($driver['wilaya'] ?? 'أدرار') ?></strong></span>
                            <?php if (!empty($driver['phone'])): ?>
                                <span>•</span>
                                <span>الهاتف: <strong class="text-slate-800 dark:text-slate-200 font-num" dir="ltr"><?= htmlspecialchars($driver['phone']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Status Badges & Quick Contact -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="#ratingsSection" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-black bg-amber-50 dark:bg-amber-950/50 text-amber-900 dark:text-amber-300 border border-amber-300 dark:border-amber-700/80 hover:bg-amber-100 transition shadow-2xs" title="الانتقال لتقييمات الركاب">
                            <span>⭐</span>
                            <span class="font-num"><?= number_format($driverAvgRating, 1) ?></span>
                            <span class="text-[10px] text-slate-500 dark:text-slate-400 font-num">(<?= $driverRatingsCount ?> تقييم)</span>
                        </a>

                        <span class="inline-flex items-center px-3 py-1 rounded-xl text-xs font-bold <?= !empty($driver['is_replacement']) ? 'bg-violet-50 text-violet-800 border border-violet-200 dark:bg-violet-950/40 dark:text-violet-300' : 'bg-amber-50 text-amber-900 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300' ?>">
                            <?= !empty($driver['is_replacement']) ? 'سائق بديل (Remplaçant)' : ($driver['driver_type'] === 'ajir' ? 'سائق أجير (قانون 90-11)' : 'مؤجر / مستقل') ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span>نشط ميدانياً</span>
                        </span>

                        <?php if (!empty($driver['phone'])): 
                            $cleanPhone = preg_replace('/[^0-9]/', '', $driver['phone']);
                            if (str_starts_with($cleanPhone, '0')) $cleanPhone = '213' . substr($cleanPhone, 1);
                        ?>
                            <a href="tel:<?= htmlspecialchars($driver['phone']) ?>" class="p-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 transition" title="اتصال هاتفي">
                                📞
                            </a>
                            <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="p-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 transition" title="محادثة واتساب">
                                💬
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Car & Quick Identifiers (Authentic Taxi License Badge Style) -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                    
                    <!-- Vehicle Box -->
                    <div class="p-3 rounded-2xl bg-gradient-to-br from-amber-500/10 to-transparent dark:bg-amber-950/20 border border-amber-300/80 dark:border-amber-700/60">
                        <span class="text-amber-900 dark:text-amber-300 block text-[10px] font-black mb-1">المركبة المستغلة حالياً:</span>
                        <?php if (!empty($driver['plate_number'])): ?>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-num font-black text-slate-950 bg-amber-400 px-2.5 py-0.5 rounded-lg border border-slate-900 text-xs shadow-2xs" dir="ltr">
                                    <?= htmlspecialchars($driver['plate_number']) ?>
                                </span>
                                <?php if (!empty($driver['door_number'])): ?>
                                    <span class="text-xs font-black text-slate-800 dark:text-slate-200">باب: <?= htmlspecialchars($driver['door_number']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-slate-400 font-bold">غير مرتبطة بمركبة حالياً</span>
                        <?php endif; ?>
                    </div>

                    <!-- Brand / Model -->
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px] font-bold mb-1">نوع وطراز السيارة:</span>
                        <strong class="text-slate-800 dark:text-slate-200 text-xs block">
                            <?= !empty($driver['brand']) ? htmlspecialchars($driver['brand'] . ' ' . ($driver['model'] ?? '')) : 'غير محدد' ?>
                        </strong>
                    </div>

                    <!-- Driving License -->
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px] font-bold mb-1">رقم رخصة السياقة:</span>
                        <strong class="font-num font-bold text-slate-900 dark:text-white text-xs block" dir="ltr">
                            <?= !empty($driver['license_number']) ? htmlspecialchars($driver['license_number']) : '—' ?>
                        </strong>
                    </div>
                </div>

            </div>

        </div>
    </div>


    <!-- ==============================================
         الحالة المدنية والهوية الشخصية للسائق (Filiation & État Civil)
         ============================================== -->
    <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200/90 dark:border-slate-800 p-5 sm:p-6 shadow-2xs space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3 flex-wrap gap-2">
            <div class="flex items-center gap-2">
                <span class="p-2 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 text-lg">🪪</span>
                <div>
                    <h3 class="text-sm sm:text-base font-black text-slate-900 dark:text-white">الحالة المدنية والهوية الرسمية (دفتر المقررة)</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">البيانات الشخصية والنسب المقيدة في البطاقة المهنية ودفتر الشروط</p>
                </div>
            </div>
            <button onclick="document.getElementById('editProfileModal').classList.remove('hidden')" 
                    class="px-3.5 py-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 dark:hover:bg-amber-900/60 text-amber-900 dark:text-amber-300 border border-amber-200 dark:border-amber-700/60 font-bold text-xs transition inline-flex items-center gap-1.5 cursor-pointer">
                <svg class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                </svg>
                <span>تعديل بيانات الهوية والحالة المدنية</span>
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3.5 text-xs">
            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                <span class="text-slate-400 block text-[10px] font-bold mb-1">اللقب (Nom):</span>
                <strong class="text-slate-900 dark:text-white text-xs font-black"><?= !empty($driver['last_name']) ? htmlspecialchars($driver['last_name']) : '— غير مسجل' ?></strong>
            </div>

            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                <span class="text-slate-400 block text-[10px] font-bold mb-1">الاسم (Prénom):</span>
                <strong class="text-slate-900 dark:text-white text-xs font-black"><?= !empty($driver['first_name']) ? htmlspecialchars($driver['first_name']) : '— غير مسجل' ?></strong>
            </div>

            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                <span class="text-slate-400 block text-[10px] font-bold mb-1">تاريخ ومكان الازدياد:</span>
                <strong class="text-slate-900 dark:text-white text-xs font-bold flex items-center gap-1.5 flex-wrap">
                    <span class="font-num" dir="ltr"><?= !empty($driver['birth_date']) ? date('d/m/Y', strtotime($driver['birth_date'])) : '—' ?></span>
                    <?php if (!empty($driver['birth_place'])): ?>
                        <span>(<?= htmlspecialchars($driver['birth_place']) ?>)</span>
                    <?php endif; ?>
                </strong>
            </div>

            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                <span class="text-slate-400 block text-[10px] font-bold mb-1">اسم الأب (ابن):</span>
                <strong class="text-slate-900 dark:text-white text-xs font-bold"><?= !empty($driver['father_name']) ? htmlspecialchars($driver['father_name']) : '— غير مسجل' ?></strong>
            </div>

            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                <span class="text-slate-400 block text-[10px] font-bold mb-1">اسم ولقب الأم (وابن):</span>
                <strong class="text-slate-900 dark:text-white text-xs font-bold"><?= !empty($driver['mother_name']) ? htmlspecialchars($driver['mother_name']) : '— غير مسجل' ?></strong>
            </div>

            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                <span class="text-slate-400 block text-[10px] font-bold mb-1">العنوان الكامل للإقامة:</span>
                <strong class="text-slate-900 dark:text-white text-xs font-bold truncate block" title="<?= htmlspecialchars($driver['address'] ?? '') ?>">
                    <?= !empty($driver['address']) ? htmlspecialchars($driver['address']) : '— غير مسجل' ?>
                </strong>
            </div>
        </div>
    </div>


    <!-- ==============================================
         1. القسم المالي المباشر: الديون، الدفعات، والرصيد المتبقي
         ============================================== -->
    <div class="space-y-4">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="p-2.5 rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 text-xl font-bold">💰</span>
                <div>
                    <h2 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">الوضعية المالية والاشتراكات</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">متابعة إجمالي الديون، الدفعات المسددة، والرصيد المتبقي بذمة السائق</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2 flex-wrap">
                <!-- BaridiMob Add Versement Button -->
                <button type="button" 
                        onclick="document.getElementById('baridimobModal').classList.remove('hidden')" 
                        class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 font-black text-xs shadow-md transition inline-flex items-center gap-2 whitespace-nowrap cursor-pointer">
                    <span class="text-base">💳</span>
                    <span>تسديد دفعة عبر بريدي موب (BaridiMob)</span>
                </button>

                <a href="debt_statement.php?user_id=<?= $driver['id'] ?>" target="_blank" 
                   class="px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition inline-flex items-center gap-1.5 font-num">
                    <span>كشف الحساب والديون</span>
                    <span>←</span>
                </a>
            </div>
        </div>

        <!-- شعار رانا نسالوه في كل شهر (الاشتراك الشهري المستحق) -->
        <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-r from-amber-500/15 via-amber-500/10 to-amber-500/5 dark:from-amber-950/40 dark:via-slate-900 border-2 border-amber-400 dark:border-amber-600/70 shadow-2xs flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <span class="w-12 h-12 rounded-2xl bg-amber-500 text-slate-950 flex items-center justify-center text-2xl font-black shrink-0 shadow-xs">
                    📅
                </span>
                <div class="space-y-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-sm sm:text-base font-black text-slate-950 dark:text-white">
                            شعار رانا نسالوه في كل شهر (الاشتراك الشهري المعتمد)
                        </h3>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black <?= $remainingBalance > 0 ? 'bg-amber-200 dark:bg-amber-900 text-amber-950 dark:text-amber-200' : 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' ?>">
                            <?= $remainingBalance > 0 ? 'واجب السداد شهرياً' : 'الحساب خالص ومسوى ✓' ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-700 dark:text-slate-300 leading-relaxed">
                        المبلغ المطلوب سداده شهرياً لمؤسسة تاكسي نقيلو: 
                        <strong class="font-num text-amber-800 dark:text-amber-300 font-black text-sm" dir="ltr"><?= number_format($activeMonthlyDue, 2) ?> دج / شهر</strong>
                        <span class="text-slate-500 dark:text-slate-400 font-normal mr-1">
                            (اشتراك المركبة: <?= number_format($monthlyVehicleFee, 0) ?> دج + حق المكان والمقررة: <?= number_format($monthlySpaceFee, 0) ?> دج)
                        </span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0 w-full md:w-auto justify-end">
                <button type="button" 
                        onclick="setPaymentAmount(<?= (int)$activeMonthlyDue ?>); document.getElementById('baridimobModal').classList.remove('hidden')" 
                        class="w-full md:w-auto px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                    <span>💳 تسديد اشتراك هذا الشهر</span>
                </button>
            </div>
        </div>

        <!-- 3 KPI Cards: الديون - الدفعات - الرصيد المتبقي -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            
            <!-- 1. إجمالي الديون والمستحقات -->
            <div class="p-5 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold text-slate-500 dark:text-slate-400">إجمالي الديون والواجبات</span>
                    <span class="p-2 rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-400 text-sm">💳</span>
                </div>
                <div class="space-y-0.5">
                    <div class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white font-num" dir="ltr">
                        <?= number_format($totalDebts, 2) ?> <span class="text-xs font-bold text-slate-400">دج</span>
                    </div>
                    <p class="text-[11px] text-slate-400">مجموع الاشتراكات والواجبات المقيدة على ذمتك</p>
                </div>
            </div>

            <!-- 2. إجمالي الدفعات المسددة -->
            <div class="p-5 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold text-slate-500 dark:text-slate-400">إجمالي الدفعات المسددة</span>
                    <span class="p-2 rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400 text-sm">💵</span>
                </div>
                <div class="space-y-0.5">
                    <div class="text-2xl sm:text-3xl font-black text-emerald-600 dark:text-emerald-400 font-num" dir="ltr">
                        <?= number_format($totalVersements, 2) ?> <span class="text-xs font-bold text-slate-400">دج</span>
                    </div>
                    <p class="text-[11px] text-slate-400">المبالغ المدفوعة والمثبتة بوصولات إيداع معتمدة</p>
                </div>
            </div>

            <!-- 3. الرصيد المتبقي (Solde) -->
            <div class="p-5 rounded-3xl <?= $remainingBalance > 0 ? 'bg-gradient-to-br from-rose-500/10 via-amber-500/5 to-white dark:from-rose-950/30 dark:via-amber-950/20 dark:to-[#161d27] border-2 border-rose-400 dark:border-rose-600 shadow-sm' : 'bg-gradient-to-br from-emerald-50/70 to-white dark:from-emerald-950/30 dark:to-[#161d27] border-2 border-emerald-400 dark:border-emerald-600 shadow-sm' ?> space-y-2.5">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-black <?= $remainingBalance > 0 ? 'text-rose-800 dark:text-rose-300' : 'text-emerald-800 dark:text-emerald-300' ?>">
                        الرصيد المتبقي (Solde)
                    </span>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black font-num <?= $remainingBalance > 0 ? 'bg-rose-600 text-white animate-pulse' : 'bg-emerald-600 text-white' ?>">
                        <?= $remainingBalance > 0 ? 'متبقي للذمة ⚠️' : 'الحساب خالص ومريكل ✓' ?>
                    </span>
                </div>
                <div class="space-y-1">
                    <div class="text-2xl sm:text-3xl font-black <?= $remainingBalance > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' ?> font-num" dir="ltr">
                        <?= number_format($remainingBalance, 2) ?> <span class="text-xs font-bold text-slate-400">دج</span>
                    </div>
                    <p class="text-[11px] <?= $remainingBalance > 0 ? 'text-rose-700 dark:text-rose-300 font-bold' : 'text-emerald-700 dark:text-emerald-300 font-bold' ?>">
                        <?= $remainingBalance > 0 ? 'يتوجب تسوية المبلغ المتبقي لتفادي تطبيق غرامات التأخير' : 'تم استيفاء كامل المبالغ المستحقة ولا توجد أي ديون متأخرة' ?>
                    </p>
                </div>
                <?php if ($remainingBalance > 0): ?>
                    <button type="button" 
                            onclick="document.getElementById('baridimobModal').classList.remove('hidden')" 
                            class="w-full py-2 px-3 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 font-black text-xs shadow-xs hover:bg-slate-800 transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <span>💳 تسديد الرصيد عبر بريدي موب</span>
                        <span>←</span>
                    </button>
                <?php endif; ?>
            </div>

        </div>

        <!-- BaridiMob Quick Copy Widget (بنك بريدي موب المعتمد) -->
        <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent dark:from-amber-950/30 dark:via-slate-900 border border-amber-300 dark:border-amber-700/60 shadow-2xs space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-amber-200/60 dark:border-amber-800/40 pb-2.5">
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center text-sm shadow-xs">
                        BM
                    </span>
                    <div>
                        <div class="text-xs font-black text-slate-900 dark:text-white">الحساب المعتمد لمؤسسة تاكسي نقيلو (BaridiMob & CCP)</div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($bankAccountHolder) ?> (<?= htmlspecialchars($bankName) ?>)</div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-500/20 text-amber-900 dark:text-amber-300 border border-amber-400/40">
                        تحويل فوري عبر RIP
                    </span>
                </div>
            </div>

            <!-- RIP Instant Copy Card -->
            <div class="flex flex-col md:flex-row items-center justify-between gap-3 bg-white dark:bg-[#111722] p-3 rounded-2xl border border-amber-300/80 dark:border-amber-700/80 shadow-2xs">
                <div class="w-full md:w-auto text-right">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">رقم الحساب الجاري RIP المخصص لتطبيق BaridiMob:</span>
                    <span class="font-num font-black text-sm sm:text-base text-amber-800 dark:text-amber-300 tracking-wider select-all" dir="ltr"><?= htmlspecialchars($bankRip) ?></span>
                </div>
                <div class="flex items-center gap-2 w-full md:w-auto justify-end">
                    <button type="button" onclick="copyText('<?= htmlspecialchars($bankRip) ?>', this)" 
                            class="w-full md:w-auto px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-black transition cursor-pointer flex items-center justify-center gap-1.5 shadow-2xs">
                        <span>📋</span>
                        <span>نسخ رقم RIP</span>
                    </button>
                    <button type="button" onclick="document.getElementById('baridimobModal').classList.remove('hidden')" 
                            class="w-full md:w-auto px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white dark:bg-white dark:text-slate-900 text-xs font-black transition cursor-pointer flex items-center justify-center gap-1.5 shadow-2xs">
                        <span>📤 إرفاق الوصل</span>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <div class="p-2.5 rounded-xl bg-white/70 dark:bg-slate-900/60 border border-amber-200/70 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold">رقم الحساب البريدي CCP:</span>
                        <strong class="text-slate-900 dark:text-white font-num font-bold text-xs" dir="ltr"><?= htmlspecialchars($bankCcp) ?></strong>
                    </div>
                    <button type="button" onclick="copyText('<?= htmlspecialchars($bankCcp) ?>', this)" class="text-[10px] font-bold text-amber-600 dark:text-amber-400 hover:underline cursor-pointer">نسخ CCP</button>
                </div>

                <?php if (!empty($baridimobNumber)): ?>
                <div class="p-2.5 rounded-xl bg-white/70 dark:bg-slate-900/60 border border-amber-200/70 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold">الهاتف المرتبط ببريدي موب:</span>
                        <strong class="text-slate-900 dark:text-white font-num font-bold text-xs" dir="ltr"><?= htmlspecialchars($baridimobNumber) ?></strong>
                    </div>
                    <button type="button" onclick="copyText('<?= htmlspecialchars($baridimobNumber) ?>', this)" class="text-[10px] font-bold text-amber-600 dark:text-amber-400 hover:underline cursor-pointer">نسخ الرقم</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Payments History Strip -->
        <?php if (!empty($recentVersements)): ?>
            <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200/90 dark:border-slate-800 p-4 sm:p-5 shadow-2xs space-y-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <span>🧾</span>
                        <span>آخر الدفعات والوصولات المسددة مؤخراً</span>
                    </span>
                    <span class="text-slate-400 text-[11px] font-num"><?= count($recentVersements) ?> دفعات مقيدة</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 text-xs">
                    <?php foreach ($recentVersements as $v): ?>
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/70 dark:border-slate-800 space-y-2 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between text-[11px] mb-1">
                                    <span class="font-bold font-num text-slate-800 dark:text-slate-200 truncate max-w-[130px]" dir="ltr" title="<?= htmlspecialchars($v['receipt_ref'] ?? '') ?>">
                                        <?= htmlspecialchars($v['receipt_ref'] ?: ('REC-#' . $v['id'])) ?>
                                    </span>
                                    <?php if (($v['status'] ?? 'approved') === 'pending'): ?>
                                        <span class="text-[9px] px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 font-bold border border-amber-300 dark:border-amber-700 font-num animate-pulse">
                                            قيد التأكيد ⏳
                                        </span>
                                    <?php elseif (($v['status'] ?? '') === 'rejected'): ?>
                                        <span class="text-[9px] px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950/70 text-rose-800 dark:text-rose-300 font-bold font-num">
                                            مرفوض ✕
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[9px] px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 font-bold font-num">
                                            معتمد ومسدد ✓
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-baseline justify-between">
                                    <span class="text-base font-black text-emerald-600 dark:text-emerald-400 font-num" dir="ltr">
                                        <?= number_format($v['amount'], 2) ?> دج
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-num" dir="ltr">
                                        <?= date('d/m/Y', strtotime($v['payment_date'])) ?>
                                    </span>
                                </div>
                                <?php if (!empty($v['notes'])): ?>
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400 line-clamp-1 mt-1"><?= htmlspecialchars($v['notes']) ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($v['receipt_image'])): ?>
                                <div class="pt-2 border-t border-slate-200/60 dark:border-slate-800 flex items-center justify-between">
                                    <button type="button" 
                                            onclick="viewReceiptImage('<?= url($v['receipt_image']) ?>', '<?= htmlspecialchars($v['receipt_ref'] ?: ('REC-#' . $v['id']), ENT_QUOTES) ?>')"
                                            class="text-[10px] font-bold text-amber-600 dark:text-amber-400 hover:text-amber-700 inline-flex items-center gap-1 cursor-pointer">
                                        <span>👁️</span>
                                        <span>معاينة صورة الوصل (Reçu)</span>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>


    <!-- ==============================================
         2. إشعارات الوثائق: خانات عندما تكون منتهية أو مقبلة على الانتهاء
         (اسم الوثيقة وتواريخها وحالتها)
         ============================================== -->
    <div id="documentsSection" class="space-y-4">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="p-2 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 text-lg">📑</span>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">إشعارات وحالة الوثائق</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">خانات لمتابعة رخصة السياقة، الفحص الطبي، المراقبة، والتأمين مع تنبيهات الانتهاء</p>
                </div>
            </div>

            <!-- Quick Filter Pills -->
            <div class="flex items-center gap-1.5 p-1 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200/80 dark:border-slate-700/60 text-xs font-bold" id="docFilterGroup">
                <button type="button" onclick="filterDocs('all')" id="btnFilterAll" class="px-3 py-1 rounded-lg bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-2xs cursor-pointer">
                    الكل (<?= count($documents) ?>)
                </button>
                <button type="button" onclick="filterDocs('alerts')" id="btnFilterAlerts" class="px-3 py-1 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer <?= ($expiredDocsCount + $expiringDocsCount > 0) ? 'text-rose-600 dark:text-rose-400 font-black' : '' ?>">
                    المنتهية والقريبة (<?= $expiredDocsCount + $expiringDocsCount ?>)
                </button>
                <button type="button" onclick="filterDocs('valid')" id="btnFilterValid" class="px-3 py-1 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer">
                    السارية (<?= $validDocsCount ?>)
                </button>
            </div>
        </div>

        <!-- Global Alert Banner if any document is expired or expiring -->
        <?php if ($expiredDocsCount > 0): ?>
            <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 flex items-center justify-between gap-3 text-xs text-rose-800 dark:text-rose-200">
                <div class="flex items-center gap-2.5">
                    <span class="text-lg animate-bounce">🚨</span>
                    <div>
                        <strong class="font-black text-sm block">تنبيه قانوني: يوجد <?= $expiredDocsCount ?> وثيقة منتهية الصلاحية!</strong>
                        <span>المركبة أو السائق غير مؤهلين قانونياً لممارسة النشاط حتى يتم تجديد الوثائق المنتهية فوراً.</span>
                    </div>
                </div>
                <span class="px-3 py-1 rounded-xl bg-rose-600 text-white font-bold text-xs shrink-0 font-num">
                    <?= $expiredDocsCount ?> منتهية
                </span>
            </div>
        <?php elseif ($expiringDocsCount > 0): ?>
            <div class="p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900/60 flex items-center justify-between gap-3 text-xs text-amber-800 dark:text-amber-200">
                <div class="flex items-center gap-2.5">
                    <span class="text-lg">⚠️</span>
                    <div>
                        <strong class="font-black text-sm block">تنبيه تجديد: يوجد <?= $expiringDocsCount ?> وثيقة مقبلة على الانتهاء خلال 30 يوماً</strong>
                        <span>يرجى اتخاذ الإجراءات اللازمة لتجديدها قبل تاريخ الانتهاء لتفادي تعليق النشاط.</span>
                    </div>
                </div>
                <span class="px-3 py-1 rounded-xl bg-amber-500 text-slate-950 font-bold text-xs shrink-0 font-num">
                    <?= $expiringDocsCount ?> قريبة
                </span>
            </div>
        <?php else: ?>
            <div class="p-3.5 rounded-2xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/40 flex items-center gap-2 text-xs text-emerald-800 dark:text-emerald-200">
                <span class="text-base">✓</span>
                <span>جميع الوثائق والتجهيزات سارية الصلاحية ومطابقة قانونياً.</span>
            </div>
        <?php endif; ?>

        <!-- Documents Grid (الخانات) -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4" id="documentsGrid">
            
            <?php foreach ($documents as $doc): ?>
                <div class="doc-card p-4 rounded-3xl border <?= $doc['card_border'] ?> transition-all duration-200 flex flex-col justify-between space-y-3" 
                     data-status="<?= $doc['status'] ?>">
                    
                    <div>
                        <!-- Header with Icon & Status Badge -->
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <span class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-xl shrink-0">
                                <?= $doc['icon'] ?>
                            </span>
                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold border <?= $doc['status_class'] ?>">
                                <?= $doc['status_label'] ?>
                            </span>
                        </div>

                        <!-- Document Name & Type -->
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 block mb-0.5"><?= $doc['type_label'] ?></span>
                            <h4 class="text-sm font-black text-slate-900 dark:text-white leading-snug">
                                <?= $doc['name'] ?>
                            </h4>
                            <?php if (!empty($doc['doc_number'])): ?>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 font-num truncate" dir="ltr">
                                    <span class="font-sans text-slate-400"><?= $doc['doc_number_label'] ?>:</span> <strong><?= htmlspecialchars($doc['doc_number']) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Dates & Expiry Countdown Box -->
                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 space-y-1.5">
                        
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-400 text-[11px]">تاريخ الانتهاء:</span>
                            <span class="font-num font-black <?= $doc['status'] === 'expired' ? 'text-rose-600 dark:text-rose-400' : ($doc['status'] === 'expiring' ? 'text-amber-700 dark:text-amber-400' : 'text-slate-900 dark:text-white') ?>" dir="ltr">
                                <?= !empty($doc['expiry_date']) ? date('d/m/Y', strtotime($doc['expiry_date'])) : '— غير محدد' ?>
                            </span>
                        </div>

                        <div class="text-[11px] font-bold <?= $doc['status'] === 'expired' ? 'text-rose-600 dark:text-rose-400 font-num' : ($doc['status'] === 'expiring' ? 'text-amber-700 dark:text-amber-400 font-num' : 'text-emerald-700 dark:text-emerald-400') ?>">
                            <?= $doc['days_label'] ?>
                        </div>

                    </div>

                </div>
            <?php endforeach; ?>

        </div>

    </div>


    <!-- ==============================================
         3. المناوبة الليلية وحراسة الحظيرة (Permanence)
         ============================================== -->
    <div id="nightShiftSection" class="space-y-4">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="p-2.5 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-xl font-bold">🌙</span>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">جدول المناوبة الليلية وحراسة الحظيرة</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">نوبات المداومة الرسمية بالمؤسسة الاستشفائية وحراسة أسطول التاكسي</p>
                </div>
            </div>

            <a href="planning.php?tab=archive" target="_blank" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 font-bold text-xs inline-flex items-center gap-1.5 transition">
                <span>📋 الأرشيف الكامل للمناوبة</span>
                <span>←</span>
            </a>
        </div>

        <?php if (!empty($driverShifts)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($driverShifts as $sh): ?>
                    <div class="p-4 rounded-3xl <?= $sh['is_today'] ? 'bg-amber-500/15 border-2 border-amber-500 shadow-md ring-2 ring-amber-300 dark:ring-amber-700' : ($sh['is_upcoming'] ? 'bg-indigo-500/10 border-2 border-indigo-400 dark:border-indigo-600 shadow-2xs' : 'bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800') ?> space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black <?= $sh['is_today'] ? 'text-amber-800 dark:text-amber-300' : ($sh['is_upcoming'] ? 'text-indigo-800 dark:text-indigo-300' : 'text-slate-500') ?>">
                                <?= $sh['is_today'] ? '🔥 نوبتك الليلة!' : ($sh['is_upcoming'] ? '⏳ نوبة قادمة' : '✓ نوبة سابقة') ?>
                            </span>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black font-num <?= $sh['is_today'] ? 'bg-amber-500 text-slate-950 font-black animate-pulse' : ($sh['is_upcoming'] ? 'bg-indigo-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300') ?>">
                                <?= !empty($sh['order_num']) ? ('الترتيب: #' . $sh['order_num']) : 'مناوبة رسمية' ?>
                            </span>
                        </div>

                        <div>
                            <div class="text-xs text-slate-400 mb-0.5">تاريخ المناوبة:</div>
                            <div class="text-base font-black text-slate-900 dark:text-white font-num" dir="ltr">
                                <?= date('Y/m/d', $sh['shift_timestamp']) ?>
                            </div>
                            <div class="text-xs font-bold text-slate-800 dark:text-slate-200 mt-1">
                                <?= htmlspecialchars($sh['title']) ?>
                            </div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                <?= htmlspecialchars($sh['sub_title'] ?: 'المؤسسة العمومية الاستشفائية بأدرار') ?>
                            </div>
                        </div>

                        <?php if (!empty($sh['agrement_num'])): ?>
                            <div class="pt-2 border-t border-slate-100 dark:border-slate-800 text-[11px] flex items-center justify-between">
                                <span class="text-slate-400">رقم الاعتماد:</span>
                                <strong class="font-num text-slate-800 dark:text-slate-200" dir="ltr"><?= htmlspecialchars($sh['agrement_num']) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-6 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 text-center space-y-2">
                <span class="text-3xl block">🌙</span>
                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">لا توجد مناوبة ليلية مجدولة حالياً لهذا السائق.</p>
                <p class="text-xs text-slate-400">عندما تصدر إدارة المنصة قائمة المناوبة الليلية الجديدة بالمؤسسة الاستشفائية سيتم إدراج نوبتك وإشعارك هنا فوراً.</p>
            </div>
        <?php endif; ?>

        <!-- Night Watch Parking Logs if available -->
        <?php if (!empty($driverNightWatches)): ?>
            <div class="p-4 rounded-3xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200/80 dark:border-slate-800 space-y-3">
                <div class="text-xs font-bold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                    <span>🛡️</span>
                    <span>سجل إيواء وحراسة المركبة بالححظيرة مؤخراً:</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5 text-xs">
                    <?php foreach ($driverNightWatches as $nw): ?>
                        <div class="p-3 rounded-2xl bg-white dark:bg-[#161d27] border border-slate-200/70 dark:border-slate-800">
                            <div class="flex items-center justify-between text-[11px] mb-1">
                                <span class="font-bold font-num text-amber-700 dark:text-amber-400"><?= htmlspecialchars($nw['plate_number'] ?? '') ?></span>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold <?= $nw['status'] === 'in_park' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' ?>">
                                    <?= $nw['status'] === 'in_park' ? 'مأوى في الحظيرة' : 'خرجت' ?>
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-500">
                                <span>الحارس: <?= htmlspecialchars($nw['guardian_name'] ?: 'الحارس المكلف') ?></span> • 
                                <span class="font-num"><?= htmlspecialchars($nw['park_slot'] ?: 'Zone A') ?></span>
                            </div>
                            <div class="text-[10px] text-slate-400 font-num mt-1" dir="ltr">
                                <?= date('d/m/Y H:i', strtotime($nw['entry_time'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>


    <!-- ==============================================
         4. إشعارات الهاتف وتنبيهات النظام المباشرة
         ============================================== -->
    <div id="notificationsSection" class="space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="p-2.5 rounded-2xl bg-rose-500/10 text-rose-600 dark:text-rose-400 text-xl font-bold">🔔</span>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">تنبيهات وإشعارات الهاتف المباشرة</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">إشعارات تسديد الديون، تجديد الوثائق، وتكليفات المناوبة الليلية</p>
                </div>
            </div>

            <a href="notifications.php" target="_blank" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 font-bold text-xs inline-flex items-center gap-1.5 transition">
                <span>مركز الإشعارات</span>
                <span>←</span>
            </a>
        </div>

        <div class="space-y-2.5">
            <!-- 1. Real-Time Solde Alert -->
            <?php if ($remainingBalance > 0): ?>
                <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-300 dark:border-rose-800/60 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs">
                    <div class="flex items-start sm:items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-rose-600 text-white flex items-center justify-center text-lg shrink-0">💳</span>
                        <div>
                            <strong class="font-black text-rose-950 dark:text-rose-200 text-sm block">تنبيه مالي عاجل: رصيد متبقي بذمتك بقيمة <?= number_format($remainingBalance, 2) ?> دج</strong>
                            <p class="text-rose-800 dark:text-rose-300 mt-0.5">يرجى تسديد المبلغ المتبقي عبر تطبيق بريدي موب (BaridiMob) لتجنب الغرامات الشهرية التلقائية.</p>
                        </div>
                    </div>
                    <button type="button" onclick="document.getElementById('baridimobModal').classList.remove('hidden')" class="w-full sm:w-auto px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black text-xs shrink-0 cursor-pointer shadow-2xs">
                        تسديد الآن
                    </button>
                </div>
            <?php endif; ?>

            <!-- 2. Real-Time Documents Alert -->
            <?php if ($expiredDocsCount > 0): ?>
                <div class="p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800/60 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs">
                    <div class="flex items-start sm:items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center text-lg shrink-0">⚠️</span>
                        <div>
                            <strong class="font-black text-amber-950 dark:text-amber-200 text-sm block">تنبيه قانوني: يوجد <?= $expiredDocsCount ?> وثيقة منتهية الصلاحية</strong>
                            <p class="text-amber-800 dark:text-amber-300 mt-0.5">يرجى تجديد الوثائق الموضحة في قسم الوثائق أعلاه فوراً لمواصلة النشاط بشكل نظامي.</p>
                        </div>
                    </div>
                    <button type="button" onclick="filterDocs('alerts'); document.getElementById('documentsGrid').scrollIntoView({behavior: 'smooth'})" class="w-full sm:w-auto px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs shrink-0 cursor-pointer shadow-2xs">
                        عرض الوثائق
                    </button>
                </div>
            <?php endif; ?>

            <!-- 3. System In-App Notifications Stream -->
            <?php if (!empty($driverNotifications)): ?>
                <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200/90 dark:border-slate-800 p-4 sm:p-5 shadow-2xs space-y-2.5">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">آخر رسائل وتنبيهات الإدارة:</span>
                    <div class="divide-y divide-slate-100 dark:border-slate-800/60">
                        <?php foreach ($driverNotifications as $dn): ?>
                            <div class="py-2.5 flex items-start justify-between gap-3 text-xs">
                                <div class="space-y-0.5">
                                    <div class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full <?= ($dn['is_read'] ?? 0) ? 'bg-slate-300 dark:bg-slate-600' : 'bg-rose-500 animate-ping' ?>"></span>
                                        <span><?= htmlspecialchars($dn['title'] ?? 'إشعار إداري') ?></span>
                                    </div>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($dn['message'] ?? '') ?></p>
                                </div>
                                <span class="text-[10px] text-slate-400 font-num shrink-0" dir="ltr">
                                    <?= !empty($dn['created_at']) ? date('d/m H:i', strtotime($dn['created_at'])) : '' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>


    <!-- ==============================================
         5. عقد العمل ورخصة الاستغلال (قانون 90-11)
         ============================================== -->
    <div class="space-y-4">
        
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="p-2 rounded-xl bg-teal-500/10 text-teal-600 dark:text-teal-400 text-lg">📑</span>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">عقد العمل ورخصة الاستغلال (قانون 90-11)</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">تفاصيل العقد المبرم بين السائق وصاحب رخصة الاستغلال</p>
                </div>
            </div>

            <?php if (!empty($driverContract['id'])): ?>
                <div class="flex items-center gap-2">
                    <?php if ($driverContract['status'] === 'terminated'): ?>
                        <a href="contract_termination.php?id=<?= $driverContract['id'] ?>" target="_blank" 
                           class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-900/50 bg-rose-50 dark:bg-rose-950/40 px-2.5 py-1 rounded-lg border border-rose-200 dark:border-rose-900/60 inline-flex items-center gap-1.5 transition">
                            <span>📜 عقد وبوردرورو الفسخ (DTW)</span>
                            <span>←</span>
                        </a>
                    <?php endif; ?>
                    <a href="contract_view.php?id=<?= $driverContract['id'] ?>" target="_blank" 
                       class="text-xs font-bold text-teal-600 dark:text-teal-400 hover:underline inline-flex items-center gap-1">
                        <span>معاينة العقد الأصلي</span>
                        <span>←</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($driverContract): ?>
            <div class="bg-white dark:bg-[#161d27] rounded-3xl border border-slate-200/90 dark:border-slate-800 p-5 sm:p-6 shadow-2xs space-y-4">
                
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl <?= $driverContract['status'] === 'terminated' ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300' : 'bg-teal-50 text-teal-700 dark:bg-teal-950/50 dark:text-teal-300' ?> flex items-center justify-center text-xl font-bold font-num">
                            90-11
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="text-base font-black text-slate-900 dark:text-white">
                                    عقد عمل سائق أجير معتمد
                                </h4>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black <?= $driverContract['status'] === 'signed' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : ($driverContract['status'] === 'terminated' ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300') ?>">
                                    <?= $driverContract['status'] === 'signed' ? 'موثق وساري المفعول ✓' : ($driverContract['status'] === 'terminated' ? 'مفسوخ رسمياً (Résilié) ✕' : htmlspecialchars($driverContract['status'])) ?>
                                </span>
                            </div>
                            <span class="text-xs text-slate-400 font-num" dir="ltr">
                                رقم العقد: <?= htmlspecialchars($driverContract['contract_number'] ?: 'CTR-' . $driverContract['id']) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($driverContract['status'] === 'terminated'): 
                    $termDateDisp = !empty($driverContract['termination_date']) ? date('d/m/Y', strtotime($driverContract['termination_date'])) : '—';
                    $termClauseDisp = !empty($driverContract['termination_clause']) ? $driverContract['termination_clause'] : 'فسخ العقد طبقاً للقانون 90-11';
                ?>
                <!-- Termination Summary Box in Driver Fiche -->
                <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-rose-600 text-white flex items-center justify-center font-black text-sm shrink-0">✕</span>
                        <div>
                            <div class="font-black text-rose-900 dark:text-rose-200 text-sm">تم فسخ هذا العقد رسمياً بتاريخ <?= $termDateDisp ?></div>
                            <div class="text-xs text-rose-700 dark:text-rose-300 font-bold mt-0.5">
                                السند والسبب القانوني: <?= htmlspecialchars($termClauseDisp) ?>
                                <?php if (!empty($driverContract['termination_notes'])): ?>
                                    <span class="block text-[11px] font-normal text-slate-600 dark:text-slate-400 mt-0.5"><?= htmlspecialchars($driverContract['termination_notes']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 w-full sm:w-auto justify-end">
                        <a href="contract_termination.php?id=<?= $driverContract['id'] ?>&tab=document" target="_blank"
                           class="px-3.5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-black text-xs transition shadow-2xs">
                            📜 محضر الفسخ
                        </a>
                        <a href="contract_termination.php?id=<?= $driverContract['id'] ?>&tab=slip" target="_blank"
                           class="px-3.5 py-2 rounded-xl border border-rose-300 dark:border-rose-800 bg-white dark:bg-slate-900 text-rose-800 dark:text-rose-200 font-bold text-xs hover:bg-rose-50 transition">
                            📋 بوردرورو DTW
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contract Key Details Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                    
                    <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px] font-bold mb-1">تاريخ بداية العقد:</span>
                        <strong class="text-slate-900 dark:text-white font-num text-sm" dir="ltr">
                            <?= !empty($driverContract['start_date']) ? date('d/m/Y', strtotime($driverContract['start_date'])) : '—' ?>
                        </strong>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px] font-bold mb-1">تاريخ نهاية وسريان العقد:</span>
                        <strong class="text-slate-900 dark:text-white font-num text-sm" dir="ltr">
                            <?= !empty($driverContract['end_date']) ? date('d/m/Y', strtotime($driverContract['end_date'])) : 'عقد مفتوح (CDI)' ?>
                        </strong>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px] font-bold mb-1">صاحب رخصة الاستغلال (المشغل):</span>
                        <strong class="text-slate-900 dark:text-white text-xs block truncate">
                            <?= !empty($driverContract['owner_name']) ? htmlspecialchars($driverContract['owner_name']) : 'مؤسسة تاكسي نقيلو' ?>
                        </strong>
                        <?php if (!empty($driverContract['owner_phone'])): ?>
                            <a href="tel:<?= htmlspecialchars($driverContract['owner_phone']) ?>" class="text-[11px] text-teal-600 dark:text-teal-400 font-num font-bold hover:underline" dir="ltr">
                                <?= htmlspecialchars($driverContract['owner_phone']) ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px] font-bold mb-1">المركبة المقيدة بالعقد:</span>
                        <strong class="text-amber-700 dark:text-amber-400 font-num text-xs block" dir="ltr">
                            <?= !empty($driverContract['plate_number']) ? htmlspecialchars($driverContract['plate_number']) : ($driver['plate_number'] ?? '—') ?>
                        </strong>
                        <span class="text-[10px] text-slate-400">
                            <?= !empty($driver['door_number']) ? ('رقم الباب: ' . htmlspecialchars($driver['door_number'])) : '' ?>
                        </span>
                    </div>

                </div>

            </div>
        <?php else: ?>
            <div class="p-6 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 text-center space-y-2">
                <span class="text-2xl block">📄</span>
                <p class="text-xs font-bold text-slate-700 dark:text-slate-300">لا يوجد عقد عمل موثق مقيد حالياً لهذا السائق في النظام.</p>
                <p class="text-[11px] text-slate-400">يمكن لإدارة المنصة تسجيل وتوثيق عقد جديد وفق القانون 90-11 من قسم العقود.</p>
            </div>
        <?php endif; ?>

    </div>


    <!-- ==============================================
         6. تقييمات الركاب وآراء الزبائن (Passenger Reviews)
         ============================================== -->
    <div id="ratingsSection" class="space-y-4">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="p-2.5 rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 text-xl font-bold">⭐</span>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">تقييمات الركاب وآراء المسافرين</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">تقييمات وملاحظات الزبائن المسجلة عبر مسح كود QR للمركبة</p>
                </div>
            </div>

            <a href="ratings.php?driver_id=<?= $driver['id'] ?>" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 font-bold text-xs inline-flex items-center gap-1.5 transition">
                <span>سجل التقييمات الكامل</span>
                <span>←</span>
            </a>
        </div>

        <!-- Rating Metrics Summary Card -->
        <div class="p-4 sm:p-5 rounded-3xl bg-white dark:bg-[#161d27] border border-slate-200/90 dark:border-slate-800 shadow-2xs space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 dark:border-slate-800 pb-4">
                <div class="flex items-center gap-4">
                    <div class="text-3xl sm:text-4xl font-black text-amber-600 dark:text-amber-400 font-num" dir="ltr">
                        <?= number_format($driverAvgRating, 1) ?>
                    </div>
                    <div class="space-y-0.5">
                        <div class="text-amber-500 text-lg leading-none" dir="ltr">
                            <?php 
                            $fullS = floor($driverAvgRating);
                            echo str_repeat('★', (int)$fullS) . str_repeat('☆', 5 - (int)$fullS);
                            ?>
                        </div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 font-num">
                            بناءً على <?= $driverRatingsCount ?> تقييم راكب
                        </span>
                    </div>
                </div>

                <!-- 3 Criteria Bars -->
                <div class="grid grid-cols-3 gap-2 text-center text-xs w-full sm:w-auto">
                    <div class="p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-100 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px]">✨ النظافة</span>
                        <strong class="text-slate-900 dark:text-white font-num font-bold"><?= number_format($driverCleanAvg, 1) ?> / 5</strong>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-100 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px]">🛡️ السياقة</span>
                        <strong class="text-slate-900 dark:text-white font-num font-bold"><?= number_format($driverDriveAvg, 1) ?> / 5</strong>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-100 dark:border-slate-800">
                        <span class="text-slate-400 block text-[10px]">🤝 المعاملة</span>
                        <strong class="text-slate-900 dark:text-white font-num font-bold"><?= number_format($driverCourtAvg, 1) ?> / 5</strong>
                    </div>
                </div>
            </div>

            <!-- Recent Reviews -->
            <?php if (!empty($driverRecentRatings)): ?>
                <div class="space-y-3">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block">آخر تعليقات وملاحظات الركاب:</span>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        <?php foreach ($driverRecentRatings as $dr): 
                            $rStars = str_repeat('★', (int)$dr['rating']) . str_repeat('☆', 5 - (int)$dr['rating']);
                        ?>
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/70 dark:border-slate-800 space-y-2">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-bold text-slate-800 dark:text-slate-200">
                                        <?= htmlspecialchars($dr['passenger_name'] ?: 'راكب مسافر') ?>
                                    </span>
                                    <span class="font-num font-black text-amber-600 dark:text-amber-400" dir="ltr">
                                        <?= $rStars ?> (<?= $dr['rating'] ?>/5)
                                    </span>
                                </div>
                                <?php if (!empty($dr['comment'])): ?>
                                    <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed italic">
                                        “<?= htmlspecialchars($dr['comment']) ?>”
                                    </p>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-400 italic block">بدون تعليق نصي</span>
                                <?php endif; ?>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-slate-800/60 text-[10px] text-slate-400">
                                    <span class="font-num" dir="ltr"><?= date('d/m/Y H:i', strtotime($dr['created_at'])) ?></span>
                                    <a href="ratings.php?id=<?= $dr['id'] ?>" class="font-bold text-amber-600 dark:text-amber-400 hover:underline">
                                        معاينة التفاصيل الكاملة ←
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-4 text-xs text-slate-400">
                    لم يتم تسجيل أي تقييم راكب لهذا السائق حتى الآن.
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>


<!-- ==============================================
     Edit Profile Modal (Self-Service)
     ============================================== -->
<div id="editProfileModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs hidden no-print">
    <div class="bg-white dark:bg-[#161d27] w-full max-w-lg rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 sm:p-7 space-y-5 text-right max-h-[92vh] overflow-y-auto">
        
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">
                تعديل البيانات الشخصية وأمان الحساب
            </h3>
            <button type="button" 
                    onclick="document.getElementById('editProfileModal').classList.add('hidden')"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                ✕
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="driver_id" value="<?= $driver['id'] ?>">

            <div class="p-3 bg-amber-50/70 dark:bg-amber-950/30 rounded-2xl border border-amber-200/80 dark:border-amber-800/60 space-y-3">
                <span class="text-xs font-black text-amber-900 dark:text-amber-300 block">الحالة المدنية والنسب (دفتر المقررة):</span>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">اللقب (Nom):</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($driver['last_name'] ?? '') ?>" placeholder="مثال: بلبشير"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">الاسم (Prénom):</label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($driver['first_name'] ?? '') ?>" placeholder="مثال: بوجمعة"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">تاريخ الازدياد:</label>
                        <input type="date" name="birth_date" value="<?= htmlspecialchars($driver['birth_date'] ?? '') ?>"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-num focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">مكان الازدياد:</label>
                        <input type="text" name="birth_place" value="<?= htmlspecialchars($driver['birth_place'] ?? '') ?>" placeholder="مثال: أدرار"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">اسم الأب (ابن):</label>
                        <input type="text" name="father_name" value="<?= htmlspecialchars($driver['father_name'] ?? '') ?>" placeholder="مثال: اسم الأب"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">اسم ولقب الأم (وابن):</label>
                        <input type="text" name="mother_name" value="<?= htmlspecialchars($driver['mother_name'] ?? '') ?>" placeholder="مثال: اسم ولقب الأم"
                               class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">الصورة الشخصية:</label>
                <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" 
                       class="w-full text-xs text-slate-500 file:mr-0 file:ml-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-amber-500 file:text-slate-950 hover:file:bg-amber-600 cursor-pointer">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">رقم الهاتف: *</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($driver['phone'] ?? '') ?>" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-num focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">البريد الإلكتروني:</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($driver['email'] ?? '') ?>" dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs font-num focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">عنوان الإقامة الكامل:</label>
                <input type="text" name="address" value="<?= htmlspecialchars($driver['address'] ?? '') ?>"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div class="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-3">
                <span class="text-xs font-bold text-amber-700 dark:text-amber-400 block">تغيير كلمة المرور (اختياري):</span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] text-slate-500 mb-1">كلمة المرور الجديدة:</label>
                        <input type="password" name="new_password" placeholder="6 أحرف على الأقل" dir="ltr"
                               class="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-500 mb-1">تأكيد كلمة المرور:</label>
                        <input type="password" name="confirm_password" placeholder="إعادة كتابة الكلمة" dir="ltr"
                               class="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3">
                <button type="button" 
                        onclick="document.getElementById('editProfileModal').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-bold hover:bg-slate-200 cursor-pointer">
                    إلغاء
                </button>
                <button type="submit" 
                        class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-black shadow-xs cursor-pointer">
                    حفظ التغييرات
                </button>
            </div>
        </form>

    </div>
</div>

<!-- ==============================================
     Modal: تسديد دفعة عبر بريدي موب وإرسال الوصل
     ============================================== -->
<div id="baridimobModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-xs hidden no-print">
    <div class="bg-white dark:bg-[#161d27] w-full max-w-xl rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 sm:p-7 space-y-5 text-right max-h-[92vh] overflow-y-auto">
        
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div class="flex items-center gap-2">
                <span class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xl font-bold">💳</span>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white">
                        تسديد دفعة عبر بريدي موب (BaridiMob & CCP)
                    </h3>
                    <p class="text-[11px] text-slate-400">تحويل مباشر إلى الحساب المعتمد لمؤسسة تاكسي نقيلو</p>
                </div>
            </div>
            <button type="button" 
                    onclick="document.getElementById('baridimobModal').classList.add('hidden')"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                ✕
            </button>
        </div>

        <!-- الخطوة 1: معلومات الحساب المعتمد (التي أدخلها الأدمن) -->
        <div class="p-4 rounded-2xl bg-gradient-to-br from-amber-50/80 to-amber-100/30 dark:from-amber-950/30 dark:to-slate-900 border border-amber-300 dark:border-amber-700/60 space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black text-amber-950 dark:text-amber-300 flex items-center gap-1.5">
                    <span>🏦</span>
                    <span>بيانات الحساب البريدي / البنكي المعتمد للتحويل:</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500 text-slate-950 font-num">
                    حساب معتمد ✓
                </span>
            </div>

            <!-- مفتاح RIP لبريدي موب مع زر نسخ فوري -->
            <div class="p-3 bg-white dark:bg-[#121721] rounded-xl border border-amber-300/80 dark:border-amber-700/80 space-y-1.5">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-slate-500 dark:text-slate-400 font-bold">رقم الحساب الجاري RIP (بريدي موب BaridiMob):</span>
                    <button type="button" onclick="copyText('<?= htmlspecialchars($bankRip) ?>', this)" 
                            class="px-2.5 py-1 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 text-[11px] font-black transition cursor-pointer flex items-center gap-1">
                        <span>📋</span>
                        <span>نسخ RIP</span>
                    </button>
                </div>
                <div class="font-num font-black text-sm sm:text-base tracking-wider text-amber-800 dark:text-amber-300 text-center select-all bg-amber-50/50 dark:bg-amber-950/20 py-1.5 rounded-lg border border-amber-200 dark:border-amber-800/60" dir="ltr">
                    <?= htmlspecialchars($bankRip) ?>
                </div>
            </div>

            <!-- بقية تفاصيل الحساب -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <div class="p-2.5 rounded-xl bg-white/80 dark:bg-slate-900/80 border border-amber-200/70 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold">رقم الحساب CCP:</span>
                        <strong class="text-slate-900 dark:text-white font-num font-bold" dir="ltr"><?= htmlspecialchars($bankCcp) ?></strong>
                    </div>
                    <button type="button" onclick="copyText('<?= htmlspecialchars($bankCcp) ?>', this)" class="text-[10px] font-bold text-amber-600 dark:text-amber-400 hover:underline cursor-pointer">نسخ</button>
                </div>

                <div class="p-2.5 rounded-xl bg-white/80 dark:bg-slate-900/80 border border-amber-200/70 dark:border-slate-800">
                    <span class="text-slate-400 block text-[10px] font-bold">اسم صاحب الحساب:</span>
                    <strong class="text-slate-900 dark:text-white truncate block text-[11px]"><?= htmlspecialchars($bankAccountHolder) ?></strong>
                </div>

                <?php if (!empty($baridimobNumber)): ?>
                    <div class="p-2.5 rounded-xl bg-white/80 dark:bg-slate-900/80 border border-amber-200/70 dark:border-slate-800 flex items-center justify-between sm:col-span-2">
                        <div>
                            <span class="text-slate-400 block text-[10px] font-bold">رقم الهاتف المربوط ببريدي موب:</span>
                            <strong class="text-slate-900 dark:text-white font-num font-bold text-xs" dir="ltr"><?= htmlspecialchars($baridimobNumber) ?></strong>
                        </div>
                        <button type="button" onclick="copyText('<?= htmlspecialchars($baridimobNumber) ?>', this)" class="text-[10px] font-bold text-amber-600 dark:text-amber-400 hover:underline cursor-pointer">نسخ الرقم</button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="text-[11px] text-amber-900/90 dark:text-amber-300/90 bg-amber-500/15 p-2.5 rounded-xl flex items-start gap-2">
                <span class="text-base">💡</span>
                <span><strong>طريقة الدفع:</strong> انسخ رقم RIP أعلاه، افتح تطبيق <strong>BaridiMob</strong> واختر <em>تحويل عبر RIP</em>، ثم أدخل المبلغ المطلوب. بعد نجاح التحويل التقط صورة للوصل (Capture d'écran) وارفعها بالأسفل لتأكيد الدفعة فوراً.</span>
            </div>
        </div>

        <!-- الخطوة 2: نموذج تسجيل الوصل وإرساله للإدارة -->
        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_baridimob_payment">
            <input type="hidden" name="driver_id" value="<?= $driver['id'] ?>">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div class="space-y-1.5 sm:col-span-2">
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">المبلغ المدفوع (دج): *</label>
                    <div class="flex items-center gap-1.5 flex-wrap mb-2">
                        <?php if ($remainingBalance > 0): ?>
                            <button type="button" onclick="setPaymentAmount(<?= (int)$remainingBalance ?>)" class="px-2.5 py-1 rounded-lg bg-rose-100 hover:bg-rose-200 dark:bg-rose-950/70 text-rose-800 dark:text-rose-300 text-[11px] font-black border border-rose-300 font-num cursor-pointer transition">
                                كامل الرصيد (<?= number_format($remainingBalance, 0) ?> دج)
                            </button>
                        <?php endif; ?>
                        <button type="button" onclick="setPaymentAmount(<?= (int)$activeMonthlyDue ?>)" class="px-2.5 py-1 rounded-lg bg-amber-100 hover:bg-amber-200 dark:bg-amber-950/70 text-amber-900 dark:text-amber-300 text-[11px] font-black border border-amber-300 font-num cursor-pointer transition">
                            الاشتراك الشهري (<?= number_format($activeMonthlyDue, 0) ?> دج)
                        </button>
                        <button type="button" onclick="setPaymentAmount(3000)" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[11px] font-bold border border-slate-200 dark:border-slate-700 font-num cursor-pointer transition">
                            3,000 دج
                        </button>
                        <button type="button" onclick="setPaymentAmount(4000)" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[11px] font-bold border border-slate-200 dark:border-slate-700 font-num cursor-pointer transition">
                            4,000 دج
                        </button>
                        <button type="button" onclick="setPaymentAmount(5000)" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[11px] font-bold border border-slate-200 dark:border-slate-700 font-num cursor-pointer transition">
                            5,000 دج
                        </button>
                        <button type="button" onclick="setPaymentAmount(6000)" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[11px] font-bold border border-slate-200 dark:border-slate-700 font-num cursor-pointer transition">
                            6,000 دج
                        </button>
                        <button type="button" onclick="setPaymentAmount(10000)" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[11px] font-bold border border-slate-200 dark:border-slate-700 font-num cursor-pointer transition">
                            10,000 دج
                        </button>
                    </div>
                    <input type="number" step="100" id="paymentAmountInput" name="amount" value="<?= $remainingBalance > 0 ? $remainingBalance : $activeMonthlyDue ?>" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white font-num font-black text-sm focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    <span class="text-[10px] text-slate-400 mt-0.5 block">الرصيد المتبقي بذمتك حالياً: <?= number_format($remainingBalance, 2) ?> دج</span>
                </div>

                <div class="sm:col-span-2">
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">تاريخ التحويل: *</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white font-num text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
            </div>

            <div class="text-xs">
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">رقم المعاملة / مرجع الوصل (N° Transaction):</label>
                <input type="text" name="receipt_ref" placeholder="مثال: BM-20261021-998 أو رقم العملية في الوصل" dir="ltr"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white font-num text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div class="text-xs">
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">صورة وصل التحويل (Reçu BaridiMob / CCP): *</label>
                <input type="file" name="receipt_image" accept="image/png,image/jpeg,image/webp,application/pdf" capture="environment" required
                       class="w-full text-xs text-slate-500 file:mr-0 file:ml-3 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-amber-500 file:text-slate-950 hover:file:bg-amber-600 cursor-pointer border border-dashed border-amber-300 dark:border-amber-700 p-2.5 rounded-2xl bg-amber-50/20 dark:bg-amber-950/10">
                <span class="text-[10px] text-slate-400 mt-1 block">صورة واضحة لوصل التحويل أو لقطة شاشة من تطبيق بريدي موب تثبت نجاح العملية.</span>
            </div>

            <div class="text-xs">
                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">ملاحظات إضافية:</label>
                <input type="text" name="notes" placeholder="مثال: تسديد اشتراك شهر أكتوبر 2026"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button type="button" 
                        onclick="document.getElementById('baridimobModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-bold hover:bg-slate-200 cursor-pointer">
                    إلغاء
                </button>
                <button type="submit" 
                        class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 text-xs font-black shadow-md transition cursor-pointer flex items-center gap-1.5">
                    <span>إرسال الوصل وتأكيد الدفعة للإدارة</span>
                    <span>🚀</span>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- ==============================================
     Modal: معاينة صورة الوصل المرفوع (Lightbox)
     ============================================== -->
<div id="receiptImageModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-xs hidden no-print" onclick="this.classList.add('hidden')">
    <div class="bg-white dark:bg-[#161d27] max-w-2xl w-full rounded-3xl p-4 sm:p-5 border border-slate-200 dark:border-slate-800 shadow-2xl space-y-3 m-auto" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800 text-xs">
            <span class="font-bold text-slate-800 dark:text-slate-200" id="receiptModalTitle">معاينة وصل التحويل</span>
            <button type="button" onclick="document.getElementById('receiptImageModal').classList.add('hidden')" class="p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400">✕</button>
        </div>
        <div class="max-h-[75vh] overflow-auto flex items-center justify-center p-2 bg-slate-50 dark:bg-slate-900 rounded-2xl">
            <img id="receiptModalImg" src="" alt="وصل التحويل" class="max-w-full h-auto rounded-xl shadow-xs object-contain">
        </div>
    </div>
</div>

<script>
function viewReceiptImage(url, title) {
    document.getElementById('receiptModalImg').src = url;
    document.getElementById('receiptModalTitle').innerText = 'معاينة وصل: ' + title;
    document.getElementById('receiptImageModal').classList.remove('hidden');
}

function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<span>تم النسخ ✓</span>';
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
    });
}

function setPaymentAmount(amt) {
    const input = document.getElementById('paymentAmountInput');
    if (input) {
        input.value = amt;
        input.focus();
    }
}

// Filter Document Cards
function filterDocs(type) {
    const cards = document.querySelectorAll('.doc-card');
    const btnAll = document.getElementById('btnFilterAll');
    const btnAlerts = document.getElementById('btnFilterAlerts');
    const btnValid = document.getElementById('btnFilterValid');

    // Reset button styles
    [btnAll, btnAlerts, btnValid].forEach(b => {
        b.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-2xs');
        b.classList.add('text-slate-600', 'dark:text-slate-400');
    });

    if (type === 'all') {
        btnAll.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-2xs');
        cards.forEach(c => c.classList.remove('hidden'));
    } else if (type === 'alerts') {
        btnAlerts.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-2xs');
        cards.forEach(c => {
            const st = c.getAttribute('data-status');
            if (st === 'expired' || st === 'expiring') {
                c.classList.remove('hidden');
            } else {
                c.classList.add('hidden');
            }
        });
    } else if (type === 'valid') {
        btnValid.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-2xs');
        cards.forEach(c => {
            const st = c.getAttribute('data-status');
            if (st === 'valid') {
                c.classList.remove('hidden');
            } else {
                c.classList.add('hidden');
            }
        });
    }
}
</script>

<!-- Mobile Floating Quick-Action Dock (شريط الملاحة السريع للسائق في الهاتف) -->
<div id="driverMobileBottomDock" 
     class="sm:hidden fixed bottom-0 left-0 right-0 z-40 bg-white/95 dark:bg-[#111722]/95 backdrop-blur-md border-t border-slate-200/90 dark:border-slate-800 shadow-2xl p-2 px-3 flex items-center justify-around no-print"
     dir="rtl">
    
    <button type="button" 
            onclick="document.getElementById('baridimobModal').classList.remove('hidden')" 
            class="flex flex-col items-center gap-1 text-amber-600 dark:text-amber-400 font-bold text-[10px] active:scale-95 transition cursor-pointer">
        <span class="w-10 h-10 rounded-2xl bg-amber-500/15 border border-amber-400/40 flex items-center justify-center text-lg">💳</span>
        <span>تسديد</span>
    </button>

    <a href="debt_statement.php?user_id=<?= $driver['id'] ?>" 
       class="flex flex-col items-center gap-1 text-slate-700 dark:text-slate-300 font-bold text-[10px] active:scale-95 transition">
        <span class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-lg">📊</span>
        <span>الكشف</span>
    </a>

    <button type="button" 
            onclick="document.getElementById('documentsGrid').scrollIntoView({behavior: 'smooth'})" 
            class="flex flex-col items-center gap-1 text-slate-700 dark:text-slate-300 font-bold text-[10px] active:scale-95 transition cursor-pointer">
        <span class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-lg">📑</span>
        <span>الوثائق</span>
    </button>

    <button type="button" 
            onclick="document.getElementById('nightShiftSection').scrollIntoView({behavior: 'smooth'})" 
            class="flex flex-col items-center gap-1 text-indigo-600 dark:text-indigo-400 font-bold text-[10px] active:scale-95 transition cursor-pointer">
        <span class="w-10 h-10 rounded-2xl bg-indigo-500/15 border border-indigo-400/30 flex items-center justify-center text-lg">🌙</span>
        <span>المناوبة</span>
    </button>

    <button type="button" 
            onclick="document.getElementById('notificationsSection').scrollIntoView({behavior: 'smooth'})" 
            class="flex flex-col items-center gap-1 text-rose-600 dark:text-rose-400 font-bold text-[10px] active:scale-95 transition cursor-pointer">
        <span class="w-10 h-10 rounded-2xl bg-rose-500/15 border border-rose-400/30 flex items-center justify-center text-lg relative">
            🔔
            <?php if ($remainingBalance > 0 || $expiredDocsCount > 0): ?>
                <span class="absolute top-1 right-1 w-2.5 h-2.5 rounded-full bg-rose-600 border border-white animate-pulse"></span>
            <?php endif; ?>
        </span>
        <span>التنبيهات</span>
    </button>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
