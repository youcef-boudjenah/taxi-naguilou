<?php
// dashboard/drivers.php - Liste Chauffeurs, Gestion des alertes & Documents
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['super_admin', 'admin', 'owner']);
$user = current_user();

$pdo = getDBConnection();

// Handle Add Driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_driver') {
    require_csrf_token();
    $fullName = trim($_POST['full_name'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    if (empty($fullName) && (!empty($firstName) || !empty($lastName))) {
        $fullName = trim($firstName . ' ' . $lastName);
    }
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $wilaya = trim($_POST['wilaya'] ?? '01 - أدرار');
    $birthDate = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birthPlace = trim($_POST['birth_place'] ?? '');
    $fatherName = trim($_POST['father_name'] ?? '');
    $motherName = trim($_POST['mother_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $licenseIssueDate = !empty($_POST['license_issue_date']) ? $_POST['license_issue_date'] : null;
    $licenseIssuePlace = trim($_POST['license_issue_place'] ?? '');
    $licenseExpiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
    
    $driverType = trim($_POST['driver_type'] ?? 'ajir');
    $activityPeriod = trim($_POST['activity_start_period'] ?? $_POST['probation_stage'] ?? '');
    $activityStartDate = !empty($_POST['activity_start_date']) ? $_POST['activity_start_date'] : null;

    if (!empty($activityPeriod)) {
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $activityPeriod, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = $m[2];
            $activityStartDate = "$year-$month-01";
        } elseif (preg_match('/^(\d{4})-(\d{1,2})/', $activityPeriod, $m)) {
            $year = $m[1];
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $activityStartDate = "$year-$month-01";
        }
    }

    // Determine Probation Stage: 3 months each stage, stops after 9 months, manual official promotion
    $postStage = trim($_POST['probation_stage'] ?? '');
    $isManualOfficial = !empty($_POST['is_manual_official']) || $postStage === 'official';
    if ($isManualOfficial) {
        $probationStage = 'official';
    } elseif (in_array($postStage, ['trial_1', 'trial_2', 'trial_3'], true)) {
        $probationStage = $postStage;
    } else {
        $probationStage = 'trial_1';
        if (!empty($activityStartDate)) {
            try {
                $startObj = new DateTime($activityStartDate);
                $nowObj = new DateTime();
                if ($nowObj >= $startObj) {
                    $diffM = ($startObj->diff($nowObj)->y * 12) + $startObj->diff($nowObj)->m;
                    if ($diffM < 3) {
                        $probationStage = 'trial_1'; // المرحلة الأولى: 3 أشهر
                    } elseif ($diffM < 6) {
                        $probationStage = 'trial_2'; // المرحلة الثانية: 3 أشهر
                    } else {
                        // >= 6 أشهر (وتتوقف عند 9 أشهر ولا تنتقل لسائق رسمي إلا يدوياً)
                        $probationStage = 'trial_3';
                    }
                }
            } catch (\Throwable $e) {}
        }
    }
    $dtwVisaDate = !empty($_POST['dtw_visa_date']) ? $_POST['dtw_visa_date'] : null;
    $cardNumber = !empty($_POST['card_number']) ? trim($_POST['card_number']) : null;
    if (empty($cardNumber) && (!empty($_POST['carnet_number']) || !empty($_POST['carnet_year']))) {
        $cNum = trim($_POST['carnet_number'] ?? '');
        $cYear = trim($_POST['carnet_year'] ?? '');
        if ($cNum !== '' && $cYear !== '') {
            $cardNumber = $cNum . '/' . $cYear;
        } elseif ($cNum !== '') {
            $cardNumber = $cNum;
        } elseif ($cYear !== '') {
            $cardNumber = $cYear;
        }
    }
    
    $medExpiry = !empty($_POST['medical_cert_expiry']) ? $_POST['medical_cert_expiry'] : null;
    $medStart = !empty($_POST['medical_exam_start']) ? $_POST['medical_exam_start'] : null;
    $medEnd = !empty($_POST['medical_exam_end']) ? $_POST['medical_exam_end'] : $medExpiry;
    $medVisaDate = !empty($_POST['medical_visa_date']) ? $_POST['medical_visa_date'] : null;
    $nextMedStart = !empty($_POST['next_medical_start']) ? $_POST['next_medical_start'] : null;
    $nextMedEnd = !empty($_POST['next_medical_end']) ? $_POST['next_medical_end'] : null;
    
    $taxiContractStart = !empty($_POST['taxi_contract_start']) ? $_POST['taxi_contract_start'] : null;
    $taxiContractEnd = !empty($_POST['taxi_contract_end']) ? $_POST['taxi_contract_end'] : null;
    $assignedVehicleId = !empty($_POST['assigned_vehicle_id']) ? intval($_POST['assigned_vehicle_id']) : null;
    $vehiclePlateNumber = trim($_POST['vehicle_plate_number'] ?? '');

    // Handle photo upload
    $avatarUrl = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts)) {
            $destDir = __DIR__ . '/../uploads/drivers';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }
            $fileName = 'driver_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destDir . '/' . $fileName)) {
                $avatarUrl = '/uploads/drivers/' . $fileName;
            }
        }
    }

    if (!empty($fullName) && !empty($phone)) {
        if (empty($email)) {
            $email = 'driver_' . time() . '_' . rand(100, 999) . '@taxinaguilou.dz';
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    full_name, first_name, last_name, email, password_hash, phone, role, status, 
                    wilaya, birth_date, birth_place, father_name, mother_name, address,
                    license_number, license_issue_date, license_issue_place, license_expiry,
                    driver_type, probation_stage, activity_start_date, dtw_visa_date, card_number,
                    medical_cert_expiry, medical_exam_start, medical_exam_end, medical_visa_date,
                    next_medical_start, next_medical_end, taxi_contract_start, taxi_contract_end, avatar_url
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 'driver', 'active',
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $fullName, $firstName, $lastName, $email, password_hash('driver123', PASSWORD_BCRYPT), $phone,
                $wilaya, $birthDate, $birthPlace, $fatherName, $motherName, $address,
                $licenseNumber, $licenseIssueDate, $licenseIssuePlace, $licenseExpiry,
                $driverType, $probationStage, $activityStartDate, $dtwVisaDate, $cardNumber,
                $medEnd, $medStart, $medEnd, $medVisaDate,
                $nextMedStart, $nextMedEnd, $taxiContractStart, $taxiContractEnd, $avatarUrl
            ]);
            $newDriverId = (int)$pdo->lastInsertId();

            // If vehiclePlateNumber was provided and assignedVehicleId is empty, find or create vehicle
            if ((!$assignedVehicleId || $assignedVehicleId <= 0) && !empty($vehiclePlateNumber)) {
                $vehiclePlateNumber = trim($vehiclePlateNumber);
                $stmtFindVeh = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ? LIMIT 1");
                $stmtFindVeh->execute([$vehiclePlateNumber]);
                $existingVehId = $stmtFindVeh->fetchColumn();

                // Fallback: check reversed format (wilaya-year-serial vs serial-year-wilaya)
                if (!$existingVehId) {
                    $parts = preg_split('/[\/\-\s]+/', $vehiclePlateNumber);
                    if (count($parts) === 3) {
                        $reversed = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                        $stmtFindVeh->execute([$reversed]);
                        $foundId = $stmtFindVeh->fetchColumn();
                        if ($foundId) {
                            $existingVehId = $foundId;
                            $vehiclePlateNumber = $reversed;
                        }
                    }
                }

                if ($existingVehId) {
                    $assignedVehicleId = (int)$existingVehId;
                } else {
                    $stmtNewVeh = $pdo->prepare("
                        INSERT INTO vehicles (plate_number, brand, model, color, door_number, status, current_driver_id)
                        VALUES (?, 'سيارة أجرة', 'Toyota Corolla', 'أصفر', '00', 'active', ?)
                    ");
                    $stmtNewVeh->execute([$vehiclePlateNumber, $newDriverId]);
                    $assignedVehicleId = (int)$pdo->lastInsertId();
                }
            }

            // Link vehicle if assigned
            if ($assignedVehicleId && $assignedVehicleId > 0) {
                $stmtVeh = $pdo->prepare("UPDATE vehicles SET current_driver_id = ? WHERE id = ?");
                $stmtVeh->execute([$newDriverId, $assignedVehicleId]);
            }

            // Handle contract dates and official contract record
            if (!empty($taxiContractStart) || !empty($taxiContractEnd) || ($assignedVehicleId && $assignedVehicleId > 0)) {
                if (empty($taxiContractStart)) {
                    $taxiContractStart = date('Y-m-d');
                }
                if (empty($taxiContractEnd)) {
                    $taxiContractEnd = date('Y-m-d', strtotime('+3 years -1 day'));
                }
                $pdo->prepare("UPDATE users SET taxi_contract_start = ?, taxi_contract_end = ? WHERE id = ?")
                    ->execute([$taxiContractStart, $taxiContractEnd, $newDriverId]);

                if ($assignedVehicleId && $assignedVehicleId > 0) {
                    $maxCId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM contracts")->fetchColumn();
                    $contractNum = 'CT-' . date('Y') . '-' . str_pad($maxCId + 1, 3, '0', STR_PAD_LEFT);
                    $stmtContract = $pdo->prepare("
                        INSERT INTO contracts (contract_number, driver_id, vehicle_id, owner_id, start_date, end_date, monthly_wage, status, signed_at, notes)
                        VALUES (?, ?, ?, ?, ?, ?, 45000.00, 'signed', NOW(), 'عقد عمل وسريان استغلال رخصة الأجرة وفق القانون 90-11 والمرسوم 12-230')
                    ");
                    $stmtContract->execute([$contractNum, $newDriverId, $assignedVehicleId, $user['id'] ?? 1, $taxiContractStart, $taxiContractEnd]);
                }
            }

            log_audit($pdo, 'driver_created', 'users', $newDriverId, "تسجيل بطاقة فنية لسائق جديد: $fullName ($phone)");
            set_flash('success', 'تم تسجيل السائق بنجاح وتوليد البطاقة الفنية الرسمية');
            redirect('/dashboard/driver_fiche.php?id=' . $newDriverId);
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء التسجيل: ' . $e->getMessage());
            redirect('/dashboard/drivers.php');
        }
    } else {
        set_flash('error', 'يرجى إدخال البيانات الأساسية: اللقب، الاسم، ورقم الهاتف لإتمام تسجيل السائق.');
        redirect('/dashboard/drivers.php');
    }
}

// Handle Quick Update Driver Probation Stage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver_stage') {
    require_csrf_token();
    $driverId = intval($_POST['driver_id'] ?? 0);
    $newStage = trim($_POST['probation_stage'] ?? 'trial_1');
    if ($driverId > 0 && in_array($newStage, ['trial_1', 'trial_2', 'trial_3', 'official'])) {
        $stmtUpdate = $pdo->prepare("UPDATE users SET probation_stage = ? WHERE id = ? AND role = 'driver'");
        $stmtUpdate->execute([$newStage, $driverId]);
        log_audit($pdo, 'driver_stage_updated', 'users', $driverId, "تحديث مرحلة السائق إلى: $newStage");
        set_flash('success', 'تم تحديث المرحلة المهنية للسائق بنجاح');
        redirect('/dashboard/drivers.php');
    }
}

// Handle Quick Toggle Driver Type (بديل / أساسي)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_driver_type') {
    require_csrf_token();
    if (!is_admin() && !is_owner()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة ومالك الأسطول مخولان بتعديل تصنيف السائق.');
        redirect('/dashboard/drivers.php');
    }
    $driverId = intval($_POST['driver_id'] ?? 0);
    $targetType = trim($_POST['driver_type'] ?? 'ajir');
    if ($driverId > 0 && in_array($targetType, ['ajir', 'replacement'])) {
        $stmtUpdate = $pdo->prepare("UPDATE users SET driver_type = ? WHERE id = ? AND (role = 'driver' OR role = 'owner')");
        $stmtUpdate->execute([$targetType, $driverId]);
        $typeLabel = ($targetType === 'replacement') ? 'سائق بديل' : 'سائق أساسي';
        log_audit($pdo, 'driver_type_updated', 'users', $driverId, "تحديث تصنيف السائق إلى: $typeLabel");
        set_flash('success', "تم تحديث وضعية السائق إلى ($typeLabel) بنجاح");
    }
    $retFilter = trim($_POST['return_filter'] ?? '');
    redirect('/dashboard/drivers.php' . (!empty($retFilter) ? '?filter=' . urlencode($retFilter) : ''));
}

// Handle Update Driver Documents & Renewals (إدارة وتجديد وثائق السائق)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver_docs') {
    require_csrf_token();
    if (!is_admin() && !is_owner()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة ومالك الأسطول مخولان بتحديث وثائق وتراخيص السائقين.');
        redirect('/dashboard/drivers.php');
    }
    $driverId = intval($_POST['driver_id'] ?? 0);
    if ($driverId > 0) {
        $cleanDate = function($val) {
            return (!empty($val) && $val !== '0000-00-00') ? trim($val) : null;
        };

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $birthDate = $cleanDate($_POST['birth_date'] ?? null);
        $birthPlace = trim($_POST['birth_place'] ?? '');
        $fatherName = trim($_POST['father_name'] ?? '');
        $motherName = trim($_POST['mother_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $licNum = trim($_POST['license_number'] ?? '');
        $licIssue = $cleanDate($_POST['license_issue_date'] ?? null);
        $licPlace = trim($_POST['license_issue_place'] ?? '');
        $licExp = $cleanDate($_POST['license_expiry'] ?? null);

        $medStart = $cleanDate($_POST['medical_exam_start'] ?? null);
        $medExp = $cleanDate($_POST['medical_cert_expiry'] ?? null);
        $medEnd = $cleanDate($_POST['medical_exam_end'] ?? null) ?: $medExp;
        if (empty($medExp) && !empty($medEnd)) $medExp = $medEnd;
        if (empty($medEnd) && !empty($medExp)) $medEnd = $medExp;

        $cardNum = trim($_POST['card_number'] ?? '');
        $taxiStart = $cleanDate($_POST['taxi_contract_start'] ?? null);
        $taxiEnd = $cleanDate($_POST['taxi_contract_end'] ?? null);

        try {
            $sqlUp = "
                UPDATE users SET 
                    birth_date = ?, birth_place = ?, father_name = ?, mother_name = ?, address = ?,
                    license_number = ?, license_issue_date = ?, license_issue_place = ?, license_expiry = ?,
                    medical_exam_start = ?, medical_cert_expiry = ?, medical_exam_end = ?,
                    card_number = ?, taxi_contract_start = ?, taxi_contract_end = ?,
                    first_name = ?, last_name = ?
            ";
            $upParams = [
                $birthDate, $birthPlace, $fatherName, $motherName, $address,
                $licNum, $licIssue, $licPlace, $licExp,
                $medStart, $medExp, $medEnd,
                $cardNum, $taxiStart, $taxiEnd,
                $firstName ?: null, $lastName ?: null
            ];

            $compName = trim(($lastName ? $lastName . ' ' : '') . $firstName);
            if (!empty($compName)) {
                $sqlUp .= ", full_name = ?";
                $upParams[] = $compName;
            }
            if (!empty($phone)) {
                $sqlUp .= ", phone = ?";
                $upParams[] = $phone;
            }

            if (is_admin()) {
                $sqlUp .= " WHERE id = ?";
                $upParams[] = $driverId;
            } else {
                $sqlUp .= " WHERE id = ? AND (role = 'driver' OR role = 'owner')";
                $upParams[] = $driverId;
            }

            $stmtUp = $pdo->prepare($sqlUp);
            $stmtUp->execute($upParams);

            // Sync with signed labor contract if dates exist
            if (!empty($taxiStart) && !empty($taxiEnd)) {
                try {
                    $pdo->prepare("UPDATE contracts SET start_date = ?, end_date = ? WHERE driver_id = ? AND status = 'signed' ORDER BY id DESC LIMIT 1")
                        ->execute([$taxiStart, $taxiEnd, $driverId]);
                } catch (\Throwable $e) {}
            }

            log_audit($pdo, 'driver_docs_updated', 'users', $driverId, "تحديث الحالة المدنية والوثائق الرسمية للسائق رقم $driverId");
            set_flash('success', 'تم حفظ وتحديث بيانات الحالة المدنية والوثائق الرسمية للسائق بنجاح');
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء تحديث الوثائق: ' . $e->getMessage());
        }
    }
    $retFilter = trim($_POST['return_filter'] ?? '');
    redirect('/dashboard/drivers.php' . (!empty($retFilter) ? '?filter=' . urlencode($retFilter) : ''));
}


// Handle Delete Driver (Soft Delete to Trash)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_driver') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف السائقين.');
        redirect('/dashboard/drivers.php');
    }
    $driverId = intval($_POST['driver_id'] ?? 0);
    if ($driverId > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE vehicles SET current_driver_id = NULL WHERE current_driver_id = ?")->execute([$driverId]);
            $pdo->prepare("UPDATE vehicles SET replacement_driver_id = NULL WHERE replacement_driver_id = ?")->execute([$driverId]);
            $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'suspended' WHERE id = ? AND (role = 'driver' OR role = 'owner')")->execute([$driverId]);
            $pdo->commit();

            log_audit($pdo, 'driver_deleted', 'users', $driverId, "نقل السائق رقم $driverId إلى سلة المهملات وفك ارتباط المركبات التابعة له");
            set_flash('success', "تم نقل السائق بنجاح إلى سلة المهملات والأرشيف وفك ارتباط المركبات. يمكنك استرجاعه في أي وقت.");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء نقل السائق إلى سلة المهملات: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/drivers.php');
}

// Handle Restore Driver from Trash
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_driver') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة باسترجاع السائقين.');
        redirect('/dashboard/drivers.php');
    }
    $driverId = intval($_POST['driver_id'] ?? 0);
    if ($driverId > 0) {
        try {
            $pdo->prepare("UPDATE users SET deleted_at = NULL, status = 'active' WHERE id = ? AND (role = 'driver' OR role = 'owner')")->execute([$driverId]);
            log_audit($pdo, 'driver_restored', 'users', $driverId, "استرجاع السائق رقم $driverId من سلة المهملات وتنشيط حسابه");
            set_flash('success', "تم استرجاع السائق بنجاح وإعادته إلى قائمة السائقين النشطين.");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء استرجاع السائق: ' . $e->getMessage());
        }
    }
    $redirectUrl = (isset($_GET['filter']) && $_GET['filter'] === 'trash') ? '/dashboard/drivers.php?filter=trash' : '/dashboard/drivers.php';
    redirect($redirectUrl);
}

// Handle Permanent Force Delete Driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'force_delete_driver') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بالحذف النهائي.');
        redirect('/dashboard/drivers.php?filter=trash');
    }
    $driverId = intval($_POST['driver_id'] ?? 0);
    if ($driverId > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE vehicles SET current_driver_id = NULL WHERE current_driver_id = ?")->execute([$driverId]);
            $pdo->prepare("UPDATE vehicles SET replacement_driver_id = NULL WHERE replacement_driver_id = ?")->execute([$driverId]);
            $pdo->prepare("DELETE FROM users WHERE id = ? AND (role = 'driver' OR role = 'owner')")->execute([$driverId]);
            $pdo->commit();

            log_audit($pdo, 'driver_force_deleted', 'users', $driverId, "حذف نهائي للسائق رقم $driverId من قاعدة البيانات");
            set_flash('success', "تم حذف السائق نهائياً من قاعدة البيانات.");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'تعذر الحذف النهائي للسائق لوجود سجلات رسمية ملزمة مرتبطة به (عقود، ديون، إلخ). السائق موجود في الأرشيف.');
        }
    }
    redirect('/dashboard/drivers.php?filter=trash');
}

/**
 * حساب المرحلة والوضعية المهنية للسائق وفق قاعدة التدرج (3 أشهر لكل مرحلة تجريبية ثم التوقف للتثبيت اليدوي):
 * - المرحلة 1: 3 أشهر فقط
 * - المرحلة 2: 3 أشهر
 * - المرحلة 3: 3 أشهر
 * - بعد 9 أشهر: يتوقف التدرج التلقائي عند المرحلة الثالثة ويكون جاهزاً للتثبيت، ولا يتم تحويله لسائق رسمي إلا يدوياً.
 * - إذا تم تعيينه كـ official يدوياً: يبقى سائقاً رسمياً دائماً.
 */
if (!function_exists('resolve_driver_probation_info')) {
    function resolve_driver_probation_info($d): array {
        $manualStage = $d['probation_stage'] ?? 'trial_1';

        // 1. إذا كان مثبتاً يدوياً كسائق رسمي
        if ($manualStage === 'official') {
            return [
                'stage' => 'official',
                'label' => 'سائق رسمي',
                'sub_label' => 'تثبيت دائم (Titulaire)',
                'cls' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
                'badge_bg' => 'bg-emerald-500',
                'is_official' => true,
                'is_ready_for_promotion' => false,
                'elapsed_months' => null
            ];
        }

        // 2. حساب الأشهر المنقضية من تاريخ بداية النشاط
        $startDateStr = $d['activity_start_date'] ?? null;
        $elapsedMonths = 0;
        if (!empty($startDateStr)) {
            try {
                $startDate = new DateTime($startDateStr);
                $now = new DateTime();
                if ($now >= $startDate) {
                    $diff = $startDate->diff($now);
                    $elapsedMonths = ($diff->y * 12) + $diff->m;
                }
            } catch (\Throwable $e) {
                $elapsedMonths = 0;
            }
        }

        // 3. قاعدة التدرج: 3 أشهر لكل مرحلة، وبعد 9 أشهر يتوقف للتثبيت اليدوي
        if ($elapsedMonths < 3) {
            $stage = 'trial_1';
            $mInStage = $elapsedMonths + 1;
            $label = 'فترة تجريبية الأولى';
            $subLabel = "شهر {$mInStage} من 3 أشهر";
            $cls = 'bg-amber-50 text-amber-900 border-amber-200';
            $badgeBg = 'bg-amber-500';
            $isReady = false;
        } elseif ($elapsedMonths < 6) {
            $stage = 'trial_2';
            $mInStage = $elapsedMonths - 2;
            $label = 'تجريبية الثانية';
            $subLabel = "شهر {$mInStage} من 3 أشهر";
            $cls = 'bg-blue-50 text-blue-900 border-blue-200';
            $badgeBg = 'bg-blue-500';
            $isReady = false;
        } elseif ($elapsedMonths < 9) {
            $stage = 'trial_3';
            $mInStage = $elapsedMonths - 5;
            $label = 'تجريبية الثالثة';
            $subLabel = "شهر {$mInStage} من 3 أشهر";
            $cls = 'bg-indigo-50 text-indigo-900 border-indigo-200';
            $badgeBg = 'bg-indigo-500';
            $isReady = false;
        } else {
            // >= 9 أشهر: يتوقف التدرج التلقائي، لا ينتقل لسائق رسمي إلا يدوياً
            $stage = 'trial_3';
            $label = 'تجريبية 3 (اكتملت 9 أشهر)';
            $subLabel = 'جاهز للتثبيت كسائق رسمي ⭐️';
            $cls = 'bg-purple-50 text-purple-900 border-purple-300 font-extrabold ring-1 ring-purple-300/80';
            $badgeBg = 'bg-purple-600';
            $isReady = true;
        }

        if (empty($startDateStr) && in_array($manualStage, ['trial_1', 'trial_2', 'trial_3'])) {
            $stage = $manualStage;
        }

        return [
            'stage' => $stage,
            'label' => $label,
            'sub_label' => $subLabel,
            'cls' => $cls,
            'badge_bg' => $badgeBg,
            'is_official' => false,
            'is_ready_for_promotion' => $isReady,
            'elapsed_months' => $elapsedMonths
        ];
    }
}

// Helper: Calculate Accurate Document Status & Alerts for a Driver
if (!function_exists('getDriverDocAlerts')) {
function getDriverDocAlerts($d) {
    $today = strtotime(date('Y-m-d'));
    
    // 1. License Expiry
    $lic = !empty($d['license_expiry']) && $d['license_expiry'] !== '0000-00-00' ? $d['license_expiry'] : null;
    $licDays = $lic ? (int)round((strtotime($lic) - $today) / 86400) : null;
    $licExpired = ($licDays !== null && $licDays < 0);
    $licExpiringSoon = ($licDays !== null && $licDays >= 0 && $licDays <= 30);
    $licMissing = empty($lic);

    // 2. Medical Examination
    $med = !empty($d['medical_cert_expiry']) && $d['medical_cert_expiry'] !== '0000-00-00' 
        ? $d['medical_cert_expiry'] 
        : (!empty($d['medical_exam_end']) && $d['medical_exam_end'] !== '0000-00-00' ? $d['medical_exam_end'] : null);
    $medDays = $med ? (int)round((strtotime($med) - $today) / 86400) : null;
    $medExpired = ($medDays !== null && $medDays < 0);
    $medExpiringSoon = ($medDays !== null && $medDays >= 0 && $medDays <= 30);
    $medMissing = empty($med);

    // 3. Taxi Exploitation Contract (Livret / المقررة)
    $cont = !empty($d['taxi_contract_end']) && $d['taxi_contract_end'] !== '0000-00-00' ? $d['taxi_contract_end'] : null;
    $contDays = $cont ? (int)round((strtotime($cont) - $today) / 86400) : null;
    $contExpired = ($contDays !== null && $contDays < 0);
    $contExpiringSoon = ($contDays !== null && $contDays >= 0 && $contDays <= 30);

    $isActive = ($d['status'] ?? 'active') === 'active';
    $hasExpired = $licExpired || $medExpired || $contExpired;
    $hasExpiringSoon = $licExpiringSoon || $medExpiringSoon || $contExpiringSoon;
    $hasMissing = ($licMissing || $medMissing) && $isActive;
    $hasAlert = $hasExpired || $hasExpiringSoon || $hasMissing;

    $minDays = 9999;
    if ($licDays !== null) $minDays = min($minDays, $licDays);
    if ($medDays !== null) $minDays = min($minDays, $medDays);
    if ($contDays !== null) $minDays = min($minDays, $contDays);

    return [
        'lic_date' => $lic,
        'lic_days' => $licDays,
        'lic_expired' => $licExpired,
        'lic_expiring_soon' => $licExpiringSoon,
        'lic_missing' => $licMissing,

        'med_date' => $med,
        'med_days' => $medDays,
        'med_expired' => $medExpired,
        'med_expiring_soon' => $medExpiringSoon,
        'med_missing' => $medMissing,

        'cont_date' => $cont,
        'cont_days' => $contDays,
        'cont_expired' => $contExpired,
        'cont_expiring_soon' => $contExpiringSoon,

        'has_expired' => $hasExpired,
        'has_expiring_soon' => $hasExpiringSoon,
        'has_missing' => $hasMissing,
        'has_alert' => $hasAlert,
        'min_days' => $minDays,
    ];
}
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, actifs, anciens, alerts, expired, soon, missing, trial_1, trial_2, trial_3, official, replacement
$alertSubFilter = $_GET['alert_tab'] ?? 'all'; // all, expired, soon, missing

$alertDrivers = [];
$allDrivers = [];
$drivers = [];
$countActifs = 0;
$countAnciens = 0;
$countTrial1 = 0;
$countTrial2 = 0;
$countTrial3 = 0;
$countOfficial = 0;
$countReplacement = 0;
$countAlerts = 0;
$countExpired = 0;
$countExpiringSoon = 0;
$countMissingDocs = 0;
$activeVehicles = [];

if ($pdo) {
    // Check if deleted_at exists in users table and self-heal if missing
    $hasUsersDeletedAt = false;
    try {
        $cU = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'deleted_at'")->fetch();
        $hasUsersDeletedAt = !empty($cU);
        if (!$hasUsersDeletedAt) {
            try {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL, ADD INDEX `idx_users_deleted_at` (`deleted_at`)");
                $hasUsersDeletedAt = true;
            } catch (\Throwable $eAlter) {}
        }
    } catch (\Throwable $e) {}

    if (!$hasUsersDeletedAt && function_exists('migrateSoftDeleteFields')) {
        try {
            migrateSoftDeleteFields($pdo);
            $cU = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'deleted_at'")->fetch();
            $hasUsersDeletedAt = !empty($cU);
        } catch (\Throwable $e) {}
    }

    // Vehicle join condition including replacement drivers
    $joinVehOn = "(v.current_driver_id = u.id OR v.owner_id = u.id OR v.replacement_driver_id = u.id)";

    // 1. Fetch Main Drivers List with assigned vehicle info
    if ($hasUsersDeletedAt) {
        $deletedCond = ($filter === 'trash') ? " AND u.deleted_at IS NOT NULL" : " AND u.deleted_at IS NULL";
    } else {
        $deletedCond = ($filter === 'trash') ? " AND 1=0" : "";
    }

    try {
        $sql = "
            SELECT u.*, 
                   MAX(v.plate_number) as plate_number, 
                   MAX(v.brand) as brand, 
                   MAX(v.model) as model, 
                   MAX(v.door_number) as door_number,
                   MAX(v.technical_control_expiry) as veh_tc_expiry, 
                   MAX(v.insurance_expiry) as veh_ins_expiry,
                   MAX(CASE WHEN v.replacement_driver_id = u.id THEN 1 ELSE 0 END) as is_veh_replacement,
                   (SELECT COUNT(*) FROM vehicles v2 WHERE v2.replacement_driver_id = u.id) as veh_repl_count,
                   (SELECT COUNT(*) FROM leaves l WHERE l.replacement_driver_id = u.id AND l.status = 'approved' AND l.end_date >= CURDATE()) as act_leave_repl_count
            FROM users u
            LEFT JOIN vehicles v ON $joinVehOn
            WHERE (u.role = 'driver' OR (u.role = 'owner' AND (u.license_number IS NOT NULL OR u.driver_type IS NOT NULL))) $deletedCond
        ";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR u.license_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " GROUP BY u.id ORDER BY u.id DESC";
        $stmtD = $pdo->prepare($sql);
        $stmtD->execute($params);
        $allDrivers = $stmtD->fetchAll() ?: [];
    } catch (\Throwable $e) {
        error_log("drivers.php - Main drivers query notice: " . $e->getMessage());
        // Fallback: simple join without leaves subquery and without deleted_at
        try {
            $fSql = "
                SELECT u.*, 
                       MAX(v.plate_number) as plate_number, 
                       MAX(v.brand) as brand, 
                       MAX(v.model) as model, 
                       MAX(v.door_number) as door_number,
                       MAX(v.technical_control_expiry) as veh_tc_expiry, 
                       MAX(v.insurance_expiry) as veh_ins_expiry,
                       0 as is_veh_replacement,
                       0 as veh_repl_count,
                       0 as act_leave_repl_count
                FROM users u
                LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id)
                WHERE (u.role = 'driver' OR (u.role = 'owner' AND (u.license_number IS NOT NULL OR u.driver_type IS NOT NULL)))
            ";
            $fParams = [];
            if (!empty($search)) {
                $fSql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR u.license_number LIKE ?)";
                $fParams[] = "%$search%";
                $fParams[] = "%$search%";
                $fParams[] = "%$search%";
            }
            $fSql .= " GROUP BY u.id ORDER BY u.id DESC";
            $stmtF = $pdo->prepare($fSql);
            $stmtF->execute($fParams);
            $allDrivers = $stmtF->fetchAll() ?: [];
        } catch (\Throwable $e2) {
            error_log("drivers.php - Fallback drivers query error: " . $e2->getMessage());
            try {
                $allDrivers = $pdo->query("SELECT * FROM users WHERE role = 'driver' ORDER BY id DESC")->fetchAll() ?: [];
            } catch (\Throwable $e3) {
                $allDrivers = [];
            }
        }
    }

    // 2. Process Alerts, Flags & Probation Stage for every driver
    foreach ($allDrivers as &$d) {
        $docInfo = getDriverDocAlerts($d);
        $d = array_merge($d, $docInfo);

        $probInfo = resolve_driver_probation_info($d);
        $d['probation_info'] = $probInfo;
        $d['probation_stage'] = $probInfo['stage'];

        // Determine if driver is a replacement / substitute driver (سائق بديل)
        $isReplacement = (!empty($d['is_veh_replacement']))
            || (!empty($d['veh_repl_count']) && (int)$d['veh_repl_count'] > 0)
            || (!empty($d['act_leave_repl_count']) && (int)$d['act_leave_repl_count'] > 0)
            || in_array(strtolower($d['driver_type'] ?? ''), ['replacement', 'remplacant', 'substitute', 'بديل'])
            || (($d['probation_stage'] ?? '') === 'replacement')
            || (mb_strpos($d['full_name'] ?? '', 'بديل') !== false);

        $d['is_replacement'] = $isReplacement;

        // Clean name to avoid "(بديل) (بديل)" if already partially typed in name
        $cleanName = preg_replace('/\s*\(?سائق\s*بديل( معتمد)?\)?|\s*\(بديل\)/u', '', $d['full_name'] ?? '');
        $d['clean_name'] = trim($cleanName) ?: $d['full_name'];

        // Active drivers with alerts
        if ($d['has_alert'] && ($d['status'] ?? 'active') === 'active') {
            $alertDrivers[] = $d;
        }
    }
    unset($d);

    // Sort alert drivers: Expired first (most overdue first), then expiring soonest, then missing
    usort($alertDrivers, function($a, $b) {
        if ($a['has_expired'] && !$b['has_expired']) return -1;
        if (!$a['has_expired'] && $b['has_expired']) return 1;
        if ($a['has_expiring_soon'] && !$b['has_expiring_soon']) return -1;
        if (!$a['has_expiring_soon'] && $b['has_expiring_soon']) return 1;
        return $a['min_days'] <=> $b['min_days'];
    });

    $countExpired = count(array_filter($alertDrivers, fn($x) => !empty($x['has_expired'])));
    $countExpiringSoon = count(array_filter($alertDrivers, fn($x) => empty($x['has_expired']) && !empty($x['has_expiring_soon'])));
    $countMissingDocs = count(array_filter($alertDrivers, fn($x) => empty($x['has_expired']) && empty($x['has_expiring_soon']) && !empty($x['has_missing'])));
    $countAlerts = count($alertDrivers);
    $imminentDrivers = $alertDrivers; // for backwards compatibility

    // 3. Filter drivers list for the table
    foreach ($allDrivers as $d) {
        if ($filter === 'trash') {
            $drivers[] = $d;
            continue;
        }
        if ($filter === 'actifs' && ($d['status'] ?? '') !== 'active') {
            continue;
        }
        if ($filter === 'anciens' && ($d['status'] ?? '') === 'active') {
            continue;
        }
        if ($filter === 'alerts' && empty($d['has_alert'])) {
            continue;
        }
        if ($filter === 'expired' && empty($d['has_expired'])) {
            continue;
        }
        if ($filter === 'soon' && empty($d['has_expiring_soon'])) {
            continue;
        }
        if ($filter === 'missing' && empty($d['has_missing'])) {
            continue;
        }
        if ($filter === 'replacement' && empty($d['is_replacement'])) {
            continue;
        }
        if (in_array($filter, ['trial_1', 'trial_2', 'trial_3', 'official'])) {
            if (($d['probation_stage'] ?? 'trial_1') !== $filter) {
                continue;
            }
        }
        $drivers[] = $d;
    }

    // 4. Counts
    $driverWhere = "(role = 'driver' OR (role = 'owner' AND (license_number IS NOT NULL OR driver_type IS NOT NULL)))";
    $countTrashDrivers = 0;
    $countActifs = 0;
    $countAnciens = 0;
    $uDelCond = $hasUsersDeletedAt ? " AND deleted_at IS NULL" : "";
    try {
        $countActifs = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE $driverWhere AND status = 'active' {$uDelCond}")->fetchColumn();
        $countAnciens = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE $driverWhere AND status != 'active' {$uDelCond}")->fetchColumn();
        if ($hasUsersDeletedAt) {
            $countTrashDrivers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE $driverWhere AND deleted_at IS NOT NULL")->fetchColumn();
        }
    } catch (\Throwable $e) {
        try {
            $countActifs = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND (status = 'active' OR status IS NULL)")->fetchColumn();
            $countAnciens = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status = 'suspended'")->fetchColumn();
        } catch (\Throwable $e2) {}
    }

    // Dynamic Counts for Probation Stages (3 months per stage, stops after 9 months)
    $countTrial1 = count(array_filter($allDrivers, fn($x) => ($x['probation_stage'] ?? '') === 'trial_1'));
    $countTrial2 = count(array_filter($allDrivers, fn($x) => ($x['probation_stage'] ?? '') === 'trial_2'));
    $countTrial3 = count(array_filter($allDrivers, fn($x) => ($x['probation_stage'] ?? '') === 'trial_3'));
    $countOfficial = count(array_filter($allDrivers, fn($x) => ($x['probation_stage'] ?? '') === 'official'));
    $countReplacement = count(array_filter($allDrivers, fn($x) => !empty($x['is_replacement'])));

    // 6. Fetch Active Vehicles for assignment modal
    try {
        $stmtV = $pdo->query("
            SELECT v.id, v.plate_number, v.brand, v.model, v.door_number, v.current_driver_id,
                   u.full_name as current_driver_name
            FROM vehicles v
            LEFT JOIN users u ON v.current_driver_id = u.id
            WHERE v.status = 'active'
            ORDER BY v.plate_number ASC
        ");
        $activeVehicles = $stmtV ? $stmtV->fetchAll() : [];
    } catch (\Throwable $e) {
        $activeVehicles = [];
    }

    // Lightweight driver dataset for client-side modals (reduces payload by >90%)
    $clientDriversMap = [];
    foreach ($allDrivers as $d) {
        $clientDriversMap[(int)$d['id']] = [
            'id' => (int)$d['id'],
            'full_name' => $d['full_name'] ?? '',
            'first_name' => $d['first_name'] ?? '',
            'last_name' => $d['last_name'] ?? '',
            'birth_date' => $d['birth_date'] ?? '',
            'birth_place' => $d['birth_place'] ?? '',
            'father_name' => $d['father_name'] ?? '',
            'mother_name' => $d['mother_name'] ?? '',
            'address' => $d['address'] ?? '',
            'wilaya' => $d['wilaya'] ?? '16 - الجزائر العاصمة',
            'phone' => $d['phone'] ?? '',
            'avatar_url' => !empty($d['avatar_url']) ? url($d['avatar_url']) : '',
            'plate_number' => $d['plate_number'] ?? '',
            'door_number' => $d['door_number'] ?? '',
            'license_number' => $d['license_number'] ?? '',
            'license_issue_date' => $d['license_issue_date'] ?? '',
            'license_issue_place' => $d['license_issue_place'] ?? '',
            'license_expiry' => $d['license_expiry'] ?? '',
            'medical_exam_start' => $d['medical_exam_start'] ?? '',
            'medical_cert_expiry' => $d['medical_cert_expiry'] ?? '',
            'medical_exam_end' => $d['medical_exam_end'] ?? '',
            'card_number' => $d['card_number'] ?? '',
            'taxi_contract_start' => $d['taxi_contract_start'] ?? '',
            'taxi_contract_end' => $d['taxi_contract_end'] ?? ''
        ];
    }

    // Pagination for drivers table (25 per page for lightning fast load)
    $perPage = 25;
    $totalDrivers = count($drivers);
    $totalPages = max(1, (int)ceil($totalDrivers / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;
    $paginatedDrivers = array_slice($drivers, $offset, $perPage);
}

$pageTitle = 'Liste Chauffeurs | سجل وإدارة السائقين';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Liste Chauffeurs</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 border border-amber-500/20">
                    سجل السائقين
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">إدارة الكفاءة المهنية، رخص السياقة، الفحوصات الطبية الدورية، ومتابعة وثائق وملفات السائقين</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button type="button" onclick="openAddDriverModal()"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>تسجيل سائق جديد</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards (Clean & Simple) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-500">السائقون النشطون</p>
                <p class="text-xl font-bold text-slate-900 font-num mt-0.5"><?= $countActifs ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                </svg>
            </div>
        </div>

        <a href="drivers.php?filter=official" class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between hover:border-emerald-400 hover:shadow-2xs transition group" title="السائقون المثبتون رسمياً">
            <div>
                <p class="text-[11px] font-semibold text-slate-500 group-hover:text-emerald-700 transition">سائقون رسميون (مُثبتون)</p>
                <p class="text-xl font-bold text-emerald-600 font-num mt-0.5"><?= $countOfficial ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 group-hover:bg-emerald-600 group-hover:text-white transition flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                </svg>
            </div>
        </a>

        <a href="drivers.php?filter=alerts" class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between hover:border-rose-400 hover:shadow-2xs transition group cursor-pointer" title="انقر لتصفية وإدارة جميع تنبيهات وثائق السائقين">
            <div>
                <p class="text-[11px] font-semibold text-slate-500 group-hover:text-rose-700 transition">تنبيهات الوثائق والرخص</p>
                <div class="flex items-baseline gap-2 mt-0.5">
                    <p class="text-xl font-bold <?= $countAlerts > 0 ? 'text-rose-600' : 'text-slate-400' ?> font-num"><?= $countAlerts ?></p>
                    <?php if ($countAlerts > 0): ?>
                        <span class="text-[10px] font-bold text-rose-700 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200 font-num">
                            <?= $countExpired ?> منتهية • <?= $countExpiringSoon ?> وشيكة
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="w-10 h-10 rounded-xl <?= $countAlerts > 0 ? 'bg-rose-50 text-rose-600 group-hover:bg-rose-600 group-hover:text-white' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
        </a>

        <a href="debts.php?filter=all" class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs flex items-center justify-between hover:border-amber-400 hover:shadow-2xs transition group" title="الانتقال إلى سجل الديون والذمم المالية">
            <div>
                <p class="text-[11px] font-semibold text-slate-500 group-hover:text-amber-700 transition">سابقون (ذمم مالية)</p>
                <p class="text-xl font-bold text-slate-700 font-num mt-0.5"><?= $countAnciens ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 group-hover:bg-amber-50 group-hover:text-amber-600 transition flex items-center justify-center text-slate-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-6 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z" />
                </svg>
            </div>
        </a>
    </div>

    <!-- SECTION 1: Alert Management Area (مركز إدارة التنبيهات وتجديد الوثائق) -->
    <?php if (!empty($alertDrivers)): ?>
        <div class="bg-white dark:bg-[#121820] rounded-xl border border-rose-200 dark:border-rose-950/70 shadow-xs p-3.5 space-y-3" id="alertsManagementSection">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 border-b border-rose-100 dark:border-slate-800/80 pb-2.5">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-6 h-6 rounded-md bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div class="flex items-center gap-2 min-w-0">
                        <h3 class="font-bold text-slate-900 dark:text-slate-100 text-xs sm:text-sm truncate">مركز إدارة تنبيهات الوثائق والرخص (Contrôle & Alertes)</h3>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 dark:bg-rose-950/70 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-900/60 font-num shrink-0">
                            <?= $countAlerts ?>
                        </span>
                    </div>
                </div>

                <!-- Alert Filter Tabs & Toggle Collapse -->
                <div class="flex items-center gap-1.5 shrink-0">
                    <div class="flex items-center gap-0.5 bg-slate-100 dark:bg-slate-800/90 p-0.5 rounded-lg text-[11px] font-bold border border-slate-200/50 dark:border-slate-700/60">
                        <button type="button" onclick="filterAlertCards('all')" id="btnAlertTab-all" class="alert-tab-btn px-2 py-0.5 rounded-md bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-2xs transition">
                            الكل (<?= $countAlerts ?>)
                        </button>
                        <?php if ($countExpired > 0): ?>
                        <button type="button" onclick="filterAlertCards('expired')" id="btnAlertTab-expired" class="alert-tab-btn px-2 py-0.5 rounded-md text-rose-700 dark:text-rose-400 hover:bg-white/60 dark:hover:bg-slate-700/60 transition">
                            منتهية (<?= $countExpired ?>)
                        </button>
                        <?php endif; ?>
                        <?php if ($countExpiringSoon > 0): ?>
                        <button type="button" onclick="filterAlertCards('soon')" id="btnAlertTab-soon" class="alert-tab-btn px-2 py-0.5 rounded-md text-amber-700 dark:text-amber-400 hover:bg-white/60 dark:hover:bg-slate-700/60 transition">
                            وشيكة (<?= $countExpiringSoon ?>)
                        </button>
                        <?php endif; ?>
                        <?php if ($countMissingDocs > 0): ?>
                        <button type="button" onclick="filterAlertCards('missing')" id="btnAlertTab-missing" class="alert-tab-btn px-2 py-0.5 rounded-md text-slate-600 dark:text-slate-300 hover:bg-white/60 dark:hover:bg-slate-700/60 transition">
                            ناقصة (<?= $countMissingDocs ?>)
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Collapse / Expand Toggle Button -->
                    <button type="button" onclick="toggleAlertsSection()" id="btnToggleAlerts" 
                            class="p-1 rounded-lg text-slate-500 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition" 
                            title="طي / إظهار التنبيهات">
                        <svg id="iconToggleAlerts" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2.5 max-h-[300px] overflow-y-auto pr-1 transition-all" id="alertCardsContainer" style="scrollbar-width: thin;">
                <?php foreach ($alertDrivers as $expD): 
                    $cardType = $expD['has_expired'] ? 'expired' : ($expD['has_expiring_soon'] ? 'soon' : 'missing');
                    $borderCls = $expD['has_expired'] 
                        ? 'border-rose-200 dark:border-rose-900/40 bg-rose-50/30 dark:bg-rose-950/20' 
                        : ($expD['has_expiring_soon'] 
                            ? 'border-amber-200 dark:border-amber-900/40 bg-amber-50/30 dark:bg-amber-950/20' 
                            : 'border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30');
                ?>
                    <div class="alert-card p-3 rounded-2xl border <?= $borderCls ?> space-y-2.5 transition-all hover:shadow-2xs" data-alert-type="<?= $cardType ?>">
                        <div class="flex items-center justify-between gap-1.5">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-7 h-7 rounded-lg overflow-hidden bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center shrink-0">
                                    <?php if (!empty($expD['avatar_url'])): ?>
                                        <img src="<?= url($expD['avatar_url']) ?>" alt="<?= htmlspecialchars($expD['full_name']) ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <span class="text-[11px] font-bold text-slate-600 dark:text-slate-300"><?= mb_substr($expD['full_name'], 0, 1) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0">
                                    <a href="driver_fiche.php?id=<?= $expD['id'] ?>" class="font-bold text-slate-900 dark:text-slate-100 text-xs hover:text-amber-500 transition truncate block">
                                        <?= htmlspecialchars($expD['full_name']) ?>
                                    </a>
                                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-num" dir="ltr"><?= htmlspecialchars($expD['phone']) ?></div>
                                </div>
                            </div>
                            <?php if ($expD['has_expired']): ?>
                                <span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-rose-100 dark:bg-rose-950/80 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800/80 shrink-0">منتهية</span>
                            <?php elseif ($expD['has_expiring_soon']): ?>
                                <span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800/80 shrink-0">وشيكة</span>
                            <?php else: ?>
                                <span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-300 border border-slate-300 dark:border-slate-700 shrink-0">ناقصة</span>
                            <?php endif; ?>
                        </div>

                        <!-- Documents status list (Compact) -->
                        <div class="space-y-1 text-[11px]">
                            <!-- License -->
                            <?php if ($expD['lic_expired'] || $expD['lic_expiring_soon'] || $expD['lic_missing']): ?>
                            <div class="flex justify-between items-center bg-white/90 dark:bg-slate-900/90 py-1 px-2 rounded-lg border border-slate-100 dark:border-slate-800/80">
                                <span class="text-slate-600 dark:text-slate-400 font-medium">رخصة السياقة:</span>
                                <?php if ($expD['lic_expired']): ?>
                                    <span class="font-num font-bold text-rose-700 dark:text-rose-400">منتهية (<?= abs($expD['lic_days']) ?> ي)</span>
                                <?php elseif ($expD['lic_expiring_soon']): ?>
                                    <span class="font-num font-bold text-amber-700 dark:text-amber-400">تنتهي خلال <?= $expD['lic_days'] ?> ي</span>
                                <?php elseif ($expD['lic_missing']): ?>
                                    <span class="font-bold text-rose-500 dark:text-rose-400">غير مسجلة</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Medical -->
                            <?php if ($expD['med_expired'] || $expD['med_expiring_soon'] || $expD['med_missing']): ?>
                            <div class="flex justify-between items-center bg-white/90 dark:bg-slate-900/90 py-1 px-2 rounded-lg border border-slate-100 dark:border-slate-800/80">
                                <span class="text-slate-600 dark:text-slate-400 font-medium">الفحص الطبي:</span>
                                <?php if ($expD['med_expired']): ?>
                                    <span class="font-num font-bold text-rose-700 dark:text-rose-400">منتهي (<?= abs($expD['med_days']) ?> ي)</span>
                                <?php elseif ($expD['med_expiring_soon']): ?>
                                    <span class="font-num font-bold text-amber-700 dark:text-amber-400">ينتهي خلال <?= $expD['med_days'] ?> ي</span>
                                <?php elseif ($expD['med_missing']): ?>
                                    <span class="font-bold text-rose-500 dark:text-rose-400">غير مسجل</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Taxi Contract -->
                            <?php if (!empty($expD['cont_date']) && ($expD['cont_expired'] || $expD['cont_expiring_soon'])): ?>
                            <div class="flex justify-between items-center bg-white/90 dark:bg-slate-900/90 py-1 px-2 rounded-lg border border-slate-100 dark:border-slate-800/80">
                                <span class="text-slate-600 dark:text-slate-400 font-medium">عقد الاستغلال:</span>
                                <span class="font-num font-bold <?= $expD['cont_expired'] ? 'text-rose-700 dark:text-rose-400' : 'text-amber-700 dark:text-amber-400' ?>">
                                    <?= $expD['cont_expired'] ? 'منتهي (' . abs($expD['cont_days']) . ' ي)' : 'ينتهي خلال ' . $expD['cont_days'] . ' ي' ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Action Button (Manage & Renew) -->
                        <div class="pt-0.5 flex items-center gap-1.5">
                            <button type="button" 
                                    onclick="openManageDocsModal(<?= (int)$expD['id'] ?>)"
                                    class="flex-1 inline-flex items-center justify-center gap-1.5 py-1.5 px-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-amber-500 dark:hover:bg-amber-400 text-white dark:text-slate-950 font-bold text-[11px] transition shadow-2xs cursor-pointer">
                                <svg class="w-3 h-3 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 015.25 6H10" />
                                </svg>
                                <span>تجديد الوثائق</span>
                            </button>
                            <a href="driver_fiche.php?id=<?= $expD['id'] ?>" target="_blank"
                               class="p-1.5 rounded-xl bg-white hover:bg-slate-100 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 transition shadow-2xs" title="معاينة البطاقة الفنية">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- SECTION 2: Toolbar & Filters -->
    <div class="bg-white dark:bg-slate-900 p-3 sm:p-4 rounded-2xl border border-slate-200/90 dark:border-slate-800 shadow-xs flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 text-xs">
        
        <!-- Search -->
        <form method="GET" class="flex-1 w-full max-w-lg flex items-center gap-2">
            <div class="relative flex-1">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ابحث بالاسم، رقم الهاتف، أو رقم رخصة السياقة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/60 dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-amber-500 dark:hover:bg-amber-400 text-white dark:text-slate-950 font-bold transition shrink-0 cursor-pointer">بحث</button>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="drivers.php" class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 underline shrink-0">إلغاء</a>
            <?php endif; ?>
        </form>

        <!-- Segmented Filter Tabs (Responsive, shrink-0 prevents truncation) -->
        <div class="flex items-center gap-1.5 w-full md:w-auto overflow-x-auto no-scrollbar py-0.5 shrink-0">
            <a href="drivers.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap shrink-0 <?= $filter === 'all' ? 'bg-amber-500 text-slate-950 font-black shadow-xs' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200' ?>">
                الكل
            </a>

            <!-- Alerts Filter Tab -->
            <a href="drivers.php?filter=alerts<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap shrink-0 flex items-center gap-1.5 <?= in_array($filter, ['alerts', 'expired', 'soon', 'missing']) ? 'bg-rose-600 text-white font-black shadow-xs' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-900/60 hover:bg-rose-100 dark:hover:bg-rose-900/40' ?>" title="عرض السائقين الذين لديهم تنبيهات وثائق">
                <span>تنبيهات الوثائق</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= in_array($filter, ['alerts', 'expired', 'soon', 'missing']) ? 'bg-white text-rose-800' : 'bg-rose-200/90 dark:bg-rose-900/60 text-rose-950 dark:text-rose-200' ?> font-num font-black"><?= $countAlerts ?></span>
            </a>

            <a href="drivers.php?filter=actifs<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap shrink-0 flex items-center gap-1.5 <?= $filter === 'actifs' ? 'bg-amber-500 text-slate-950 font-black shadow-xs' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200' ?>">
                <span>نشطون</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $filter === 'actifs' ? 'bg-slate-950/20 text-slate-950' : 'bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200' ?> font-num font-bold"><?= $countActifs ?></span>
            </a>

            <a href="drivers.php?filter=official<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap shrink-0 flex items-center gap-1.5 <?= $filter === 'official' ? 'bg-emerald-600 text-white font-black shadow-xs' : 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-900/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/40' ?>">
                <span>سائق رسمي</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-emerald-200/80 dark:bg-emerald-900/60 font-num font-bold text-emerald-950 dark:text-emerald-200"><?= $countOfficial ?></span>
            </a>

            <!-- Grouped Probation Stages Dropdown -->
            <div class="relative group shrink-0">
                <button type="button" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap flex items-center gap-1.5 <?= in_array($filter, ['trial_1', 'trial_2', 'trial_3']) ? 'bg-blue-600 text-white font-black shadow-xs' : 'bg-blue-50 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-900/60 hover:bg-blue-100 dark:hover:bg-blue-900/40' ?>">
                    <span>المراحل التجريبية</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-blue-200/80 dark:bg-blue-900/60 font-num font-bold text-blue-950 dark:text-blue-200"><?= ($countTrial1 + $countTrial2 + $countTrial3) ?></span>
                    <svg class="w-3 h-3 text-current opacity-70 group-hover:rotate-180 transition shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div class="hidden group-hover:block absolute left-0 mt-1 w-48 bg-white dark:bg-[#161d27] rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 p-1.5 z-40">
                    <a href="drivers.php?filter=trial_1<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-bold hover:bg-amber-50 dark:hover:bg-amber-950/40 text-amber-900 dark:text-amber-300 <?= $filter === 'trial_1' ? 'bg-amber-50 dark:bg-amber-950/40 font-black' : '' ?>">
                        <span>تجريبية 1</span>
                        <span class="font-num text-[10px]"><?= $countTrial1 ?></span>
                    </a>
                    <a href="drivers.php?filter=trial_2<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-bold hover:bg-blue-50 dark:hover:bg-blue-950/40 text-blue-900 dark:text-blue-300 <?= $filter === 'trial_2' ? 'bg-blue-50 dark:bg-blue-950/40 font-black' : '' ?>">
                        <span>تجريبية 2</span>
                        <span class="font-num text-[10px]"><?= $countTrial2 ?></span>
                    </a>
                    <a href="drivers.php?filter=trial_3<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-bold hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-indigo-900 dark:text-indigo-300 <?= $filter === 'trial_3' ? 'bg-indigo-50 dark:bg-indigo-950/40 font-black' : '' ?>">
                        <span>تجريبية 3</span>
                        <span class="font-num text-[10px]"><?= $countTrial3 ?></span>
                    </a>
                </div>
            </div>

            <!-- Replacement Driver Filter Tab (سائق بديل) -->
            <a href="drivers.php?filter=replacement<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap shrink-0 flex items-center gap-1.5 <?= $filter === 'replacement' ? 'bg-purple-600 text-white font-black shadow-xs' : 'bg-purple-50 dark:bg-purple-950/40 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-900/60 hover:bg-purple-100 dark:hover:bg-purple-900/40' ?>" title="عرض السائقين البدلاء المعتمدين">
                <span>سائق بديل</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $filter === 'replacement' ? 'bg-white text-purple-900' : 'bg-purple-200/80 dark:bg-purple-900/60 text-purple-950 dark:text-purple-200' ?> font-num font-bold"><?= $countReplacement ?></span>
            </a>

            <!-- Trash Filter Tab (سلة المهملات) -->
            <a href="drivers.php?filter=trash<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
               class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap shrink-0 flex items-center gap-1.5 <?= $filter === 'trash' ? 'bg-rose-700 text-white font-black shadow-xs' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-900/60 hover:bg-rose-100 dark:hover:bg-rose-900/40' ?>" title="عرض السائقين المحذوفين في سلة المهملات">
                <span>🗑️ سلة المهملات</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $filter === 'trash' ? 'bg-white text-rose-700 font-black' : 'bg-rose-200/80 dark:bg-rose-900/60 text-rose-950 dark:text-rose-200' ?> font-num font-bold"><?= $countTrashDrivers ?? 0 ?></span>
            </a>
        </div>
    </div>

    <!-- Trash / Recycle Bin Banner for Drivers -->
    <?php if ($filter === 'trash'): ?>
    <div class="px-4 py-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-300 dark:border-rose-900 flex items-center justify-between text-xs flex-wrap gap-2 shadow-sm">
        <div class="flex items-center gap-2">
            <span class="text-lg">🗑️</span>
            <div>
                <span class="font-bold text-rose-900 dark:text-rose-200">أنت تتصفح حالياً سلة المهملات للسائقين:</span>
                <span class="text-rose-700 dark:text-rose-300 mr-1">يمكنك استرجاع أي سائق بنقرة زر واحدة لإعادته إلى قائمة السائقين النشطين، أو حذفه نهائياً.</span>
            </div>
            <span class="px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 text-rose-700 dark:text-rose-300 font-bold border border-rose-300 font-num">
                <?= count($drivers) ?> سائق محذوف
            </span>
        </div>
        <div class="flex items-center gap-2">
            <a href="archive.php" class="px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 text-rose-700 dark:text-rose-300 border border-rose-300 font-bold hover:bg-rose-100 transition">
                الانتقال لمركز الأرشيف العام ↗
            </a>
            <a href="drivers.php" class="text-xs text-rose-600 dark:text-rose-400 hover:underline font-bold">
                العودة للسائقين النشطين ✕
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- SECTION 3: Drivers Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
                    <tr>
                        <th class="py-3 px-3.5 text-right whitespace-nowrap sticky-col-start">السائق والاتصال</th>
                        <th class="py-3 px-2.5 text-right whitespace-nowrap">المركبة الحالية</th>
                        <th class="py-3 px-2.5 text-right whitespace-nowrap">الفترة والوضعية المهنية</th>
                        <th class="py-3 px-2.5 text-right whitespace-nowrap">رخصة السياقة</th>
                        <th class="py-3 px-2.5 text-right whitespace-nowrap">صلاحية الرخصة</th>
                        <th class="py-3 px-2.5 text-right whitespace-nowrap">الفحص الطبي الدوري</th>
                        <th class="py-3 px-2 text-center whitespace-nowrap sticky-col-end w-12">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80 font-medium">
                    <?php if (!empty($paginatedDrivers)): ?>
                        <?php foreach ($paginatedDrivers as $d): 
                            $rowAlertCls = '';
                            if (!empty($d['has_expired'])) {
                                $rowAlertCls = 'bg-rose-50/25 dark:bg-rose-950/20';
                            } elseif (!empty($d['has_expiring_soon'])) {
                                $rowAlertCls = 'bg-amber-50/20 dark:bg-amber-950/20';
                            }
                        ?>
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition <?= $rowAlertCls ?>">
                                
                                <!-- 1. الاسم واللقب وتحته رقم الهاتف والإيميل -->
                                <td class="py-2.5 px-3.5 whitespace-nowrap sticky-col-start">
                                    <div class="flex items-start gap-2.5">
                                        <div class="w-9 h-9 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-800 dark:to-slate-900 flex items-center justify-center shrink-0 shadow-2xs mt-0.5">
                                            <?php if (!empty($d['avatar_url'])): ?>
                                                <img src="<?= url($d['avatar_url']) ?>" alt="<?= htmlspecialchars($d['full_name']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                                </svg>
                                            <?php endif; ?>
                                        </div>
                                        <div class="space-y-0.5">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <a href="driver_fiche.php?id=<?= $d['id'] ?>" class="font-bold text-slate-900 dark:text-white text-xs hover:text-amber-600 dark:hover:text-amber-400 transition">
                                                    <?= htmlspecialchars($d['clean_name'] ?? $d['full_name']) ?>
                                                </a>

                                                <?php if (!empty($d['is_replacement'])): ?>
                                                    <span class="text-amber-600 dark:text-amber-400 font-extrabold text-[11px]" title="سائق بديل معتمد">(بديل)</span>
                                                <?php endif; ?>

                                                <?php if (!empty($d['has_expired'])): ?>
                                                    <span class="px-1.5 py-0.2 rounded text-[10px] font-extrabold bg-rose-100 dark:bg-rose-950/50 text-rose-800 dark:text-rose-300 border border-rose-300 dark:border-rose-800">وثائق منتهية</span>
                                                <?php elseif (!empty($d['has_expiring_soon'])): ?>
                                                    <span class="px-1.5 py-0.2 rounded text-[10px] font-extrabold bg-amber-100 dark:bg-amber-950/50 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800">انتهاء وشيك</span>
                                                <?php elseif (!empty($d['has_missing'])): ?>
                                                    <span class="px-1.5 py-0.2 rounded text-[10px] font-extrabold bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-300 border border-slate-300 dark:border-slate-700">وثائق ناقصة</span>
                                                <?php endif; ?>
                                            </div>

                                            <!-- رقم الهاتف تحت الاسم مباشرة -->
                                            <?php if (!empty($d['phone'])): ?>
                                                <div class="mt-0.5">
                                                    <span class="table-num-badge table-num-slate text-[10px]" dir="ltr">
                                                        📞 <?= htmlspecialchars($d['phone']) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>

                                            <!-- البريد الإلكتروني تحت رقم الهاتف -->
                                            <?php if (!empty($d['email'])): ?>
                                                <div class="text-[10px] text-slate-500 dark:text-slate-400 flex items-center gap-1 max-w-[210px] truncate" title="<?= htmlspecialchars($d['email']) ?>" dir="ltr">
                                                    <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                                    </svg>
                                                    <span class="truncate"><?= htmlspecialchars($d['email']) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- 2. المركبة الحالية -->
                                <td class="py-2.5 px-2.5 whitespace-nowrap">
                                    <?php if (!empty($d['plate_number'])): ?>
                                        <div class="table-num-badge bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200 border border-amber-200/80 dark:border-amber-800/80 font-bold text-[10px]" dir="ltr">
                                            🚗 <?= htmlspecialchars($d['plate_number']) ?>
                                        </div>
                                        <div class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-1">
                                            <span><?= htmlspecialchars($d['brand'] . ' ' . $d['model']) ?></span> 
                                            <?php if (!empty($d['door_number'])): ?>
                                                <span class="table-num-badge table-num-slate text-[9px] px-1 py-0">باب: <?= htmlspecialchars($d['door_number']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate text-[10px]">
                                            بدون مركبة
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 3. الفترة للسائق ونوعها -->
                                <td class="py-2.5 px-2.5 whitespace-nowrap">
                                    <?php 
                                    $pInfo = $d['probation_info'] ?? resolve_driver_probation_info($d);
                                    $stage = $pInfo['stage'] ?? ($d['probation_stage'] ?? 'trial_1');
                                    ?>
                                    <div class="inline-flex items-center gap-1">
                                        <div class="inline-block relative group">
                                            <button type="button" class="px-2 py-0.5 rounded-lg text-[10px] font-bold border flex items-center gap-1 <?= $pInfo['cls'] ?> hover:shadow-2xs transition cursor-pointer" title="انقر لتعديل مرحلة السائق يدوياً: <?= $pInfo['label'] ?>">
                                                <span><?= $pInfo['label'] ?></span>
                                                <?php if (!empty($pInfo['sub_label']) && empty($pInfo['is_ready_for_promotion'])): 
                                                    $subCompact = preg_replace('/^شهر\s*(\d+).*$/u', 'ش $1/3', $pInfo['sub_label']);
                                                ?>
                                                    <span class="opacity-75 text-[9px] font-normal font-num">(<?= $subCompact ?>)</span>
                                                <?php endif; ?>
                                                <svg class="w-2.5 h-2.5 opacity-60 group-hover:rotate-180 transition shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                                            </button>
                                            <div class="hidden group-hover:block absolute z-30 right-0 mt-1 w-52 bg-white dark:bg-[#161d27] rounded-xl shadow-xl border border-slate-200 dark:border-slate-700/80 p-1.5 text-right">
                                                <form method="POST" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_driver_stage">
                                                    <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                                    <button type="submit" name="probation_stage" value="trial_1" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-amber-50 dark:hover:bg-amber-500/20 text-amber-900 dark:text-amber-300 transition flex items-center justify-between">
                                                        <span>فترة تجريبية 1 (3 أشهر الأولى)</span>
                                                        <?= $stage === 'trial_1' ? '<span class="text-amber-500 font-bold">✓</span>' : '' ?>
                                                    </button>
                                                    <button type="submit" name="probation_stage" value="trial_2" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-blue-50 dark:hover:bg-blue-500/20 text-blue-900 dark:text-blue-300 transition flex items-center justify-between">
                                                        <span>تجريبية 2 (3 أشهر الثانية)</span>
                                                        <?= $stage === 'trial_2' ? '<span class="text-blue-500 font-bold">✓</span>' : '' ?>
                                                    </button>
                                                    <button type="submit" name="probation_stage" value="trial_3" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-indigo-50 dark:hover:bg-indigo-500/20 text-indigo-900 dark:text-indigo-300 transition flex items-center justify-between">
                                                        <span>تجريبية 3 (3 أشهر الأخيرة)</span>
                                                        <?= $stage === 'trial_3' ? '<span class="text-indigo-500 font-bold">✓</span>' : '' ?>
                                                    </button>
                                                    <button type="submit" name="probation_stage" value="official" class="w-full text-right px-2.5 py-1.5 rounded-lg text-[11px] font-bold hover:bg-emerald-50 dark:hover:bg-emerald-500/20 text-emerald-900 dark:text-emerald-300 transition flex items-center justify-between border-t border-slate-100 dark:border-slate-700/60 mt-1 pt-1">
                                                        <span class="flex items-center gap-1 font-black text-emerald-700 dark:text-emerald-300">
                                                            <svg class="w-3.5 h-3.5 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                                            سائق رسمي (تثبيت دائم يدوي)
                                                        </span>
                                                        <?= $stage === 'official' ? '<span class="text-emerald-500 font-bold">✓</span>' : '' ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>

                                        <?php if (!empty($pInfo['is_ready_for_promotion'])): ?>
                                            <form method="POST" action="" class="inline m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_driver_stage">
                                                <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                                <input type="hidden" name="probation_stage" value="official">
                                                <button type="submit" class="px-1.5 py-0.5 rounded text-[9px] font-black bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white shadow-xs flex items-center gap-0.5 transition cursor-pointer" title="اكتملت 9 أشهر (3 مراحل تجريبية) - انقر هنا للتثبيت كسائق رسمي فوراً">
                                                    <span>⭐ تثبيت</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 4. رخصة السياقة -->
                                <td class="py-2.5 px-2.5 whitespace-nowrap">
                                    <?php if (!empty($d['license_number'])): ?>
                                        <span class="table-num-badge table-num-sky font-bold text-[10px] max-w-[155px] truncate inline-flex" title="<?= htmlspecialchars($d['license_number']) ?>" dir="ltr">
                                            📄 <?= htmlspecialchars($d['license_number']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate text-[10px]">غير مسجل</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. صلاحية الرخصة -->
                                <td class="py-2.5 px-2.5 whitespace-nowrap font-num">
                                    <?php if (!empty($d['lic_expired'])): ?>
                                        <span class="table-num-badge table-num-rose text-[10px] font-bold" title="منتهية منذ <?= abs($d['lic_days']) ?> يوماً" dir="ltr">
                                            <span class="table-num-dot-rose"></span>
                                            <?= htmlspecialchars($d['lic_date']) ?>
                                            <span class="text-[9px]">(منتهية)</span>
                                        </span>
                                    <?php elseif (!empty($d['lic_expiring_soon'])): ?>
                                        <span class="table-num-badge table-num-amber text-[10px] font-bold" title="تنتهي خلال <?= $d['lic_days'] ?> يوماً" dir="ltr">
                                            <span class="table-num-dot-amber"></span>
                                            <?= htmlspecialchars($d['lic_date']) ?>
                                            <span class="text-[9px]">(<?= $d['lic_days'] ?>ج)</span>
                                        </span>
                                    <?php elseif (!empty($d['lic_date'])): ?>
                                        <span class="table-num-badge table-num-emerald text-[10px] font-semibold" dir="ltr">
                                            <span class="table-num-dot-emerald"></span>
                                            <?= htmlspecialchars($d['lic_date']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-slate text-[10px]">غير مسجلة</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 6. الفحص الطبي الدوري -->
                                <td class="py-2.5 px-2.5 whitespace-nowrap">
                                    <?php if (!empty($d['med_expired'])): ?>
                                        <span class="table-num-badge table-num-rose text-[10px] font-bold" title="الفحص الطبي منتهي الصلاحية منذ <?= abs($d['med_days']) ?> يوماً" dir="ltr">
                                            <span class="table-num-dot-rose"></span>
                                            <?= htmlspecialchars($d['med_date']) ?>
                                            <span class="text-[9px]">(منتهي)</span>
                                        </span>
                                    <?php elseif (!empty($d['med_expiring_soon'])): ?>
                                        <span class="table-num-badge table-num-amber text-[10px] font-bold" title="ينتهي الفحص الطبي خلال <?= $d['med_days'] ?> يوماً" dir="ltr">
                                            <span class="table-num-dot-amber"></span>
                                            <?= htmlspecialchars($d['med_date']) ?>
                                            <span class="text-[9px]">(<?= $d['med_days'] ?>ج)</span>
                                        </span>
                                    <?php elseif (!empty($d['med_date'])): ?>
                                        <span class="table-num-badge table-num-emerald text-[10px] font-semibold" dir="ltr">
                                            <span class="table-num-dot-emerald"></span>
                                            <?= htmlspecialchars($d['med_date']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-rose text-[10px] font-bold">غير مسجل</span>
                                    <?php endif; ?>
                                </td>

                                <!-- ACTIONS (3-Dots Dropdown Menu) -->
                                <td class="py-2.5 px-2 text-center whitespace-nowrap sticky-col-end">
                                    <div class="relative inline-block text-right">
                                        <button type="button"
                                                onclick="toggleTableDropdown(event, this)"
                                                class="table-dropdown-btn w-8.5 h-8.5 rounded-xl bg-slate-100 hover:bg-amber-500/15 hover:text-amber-700 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 shadow-2xs cursor-pointer"
                                                title="خيارات وإجراءات السائق">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                            </svg>
                                        </button>

                                        <div class="table-dropdown-menu hidden w-60 min-w-[240px] rounded-2xl bg-white/95 dark:bg-[#131b26]/95 backdrop-blur-md border border-slate-200/90 dark:border-slate-700/80 shadow-2xl p-1.5 space-y-0.5 text-xs text-right select-none">
                                            <?php if (!empty($pInfo['is_ready_for_promotion'])): ?>
                                            <!-- Quick Official Promotion Button (completed 9 months) -->
                                            <form method="POST" action="" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_driver_stage">
                                                <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                                <input type="hidden" name="probation_stage" value="official">
                                                <button type="submit"
                                                        class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-emerald-800 dark:text-emerald-300 bg-emerald-50/90 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 font-black transition text-right cursor-pointer">
                                                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                                    </svg>
                                                    <span>تثبيت رسمي ⭐ (اكتملت 9 أشهر)</span>
                                                </button>
                                            </form>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <?php endif; ?>

                                            <!-- Quick Manage Docs Button -->
                                            <button type="button"
                                                    onclick="openManageDocsModal(<?= (int)$d['id'] ?>)"
                                                    class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:text-slate-950 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/80 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                                <span>إدارة وتجديد الوثائق</span>
                                            </button>

                                            <!-- Fiche Technique Link -->
                                            <a href="driver_fiche.php?id=<?= $d['id'] ?>"
                                               class="table-dropdown-item flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:text-slate-950 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/80 font-bold transition">
                                                <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                                <span>معاينة البطاقة الفنية</span>
                                            </a>

                                            <!-- Contract 90-11 for Driver -->
                                            <a href="contracts.php?action=new&driver_id=<?= $d['id'] ?>"
                                               class="table-dropdown-item flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:text-slate-950 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/80 font-bold transition">
                                                <svg class="w-4 h-4 text-teal-600 dark:text-teal-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                                <span>إصدار عقد عمل (90-11)</span>
                                            </a>

                                            <!-- Solde / Debt Statement -->
                                            <a href="debt_statement.php?user_id=<?= $d['id'] ?>" target="_blank"
                                               class="table-dropdown-item flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:text-slate-950 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/80 font-bold transition">
                                                <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>كشف الحساب (Solde)</span>
                                            </a>

                                            <!-- Edit User Profile -->
                                            <a href="users.php?search=<?= urlencode($d['full_name']) ?>"
                                               class="table-dropdown-item flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:text-slate-950 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/80 font-bold transition">
                                                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل بيانات الحساب</span>
                                            </a>

                                            <!-- Toggle Driver Type (بديل / أساسي) -->
                                            <?php if (is_admin() || is_owner()): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <form method="POST" action="" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_driver_type">
                                                <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                                <input type="hidden" name="return_filter" value="<?= htmlspecialchars($filter) ?>">
                                                <?php if (!empty($d['is_replacement'])): ?>
                                                    <input type="hidden" name="driver_type" value="ajir">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:text-slate-950 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/80 font-bold transition text-right cursor-pointer"
                                                            title="إلغاء وضعية البديل وتعيينه كسائق أساسي">
                                                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                        </svg>
                                                        <span>تحويل إلى سائق أساسي</span>
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="driver_type" value="replacement">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:text-slate-950 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/80 font-bold transition text-right cursor-pointer"
                                                            title="تعيين هذا السائق كسائق بديل معتمد">
                                                        <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                                        </svg>
                                                        <span>تعيين كسائق بديل (بديل)</span>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                            <?php endif; ?>

                                            <?php if (is_admin()): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                                <?php if (!empty($d['deleted_at'])): ?>
                                                <!-- Restore Driver Form -->
                                                <form method="POST" action="" onsubmit="return confirm('هل تريد استرجاع هذا السائق وإعادته فوراً إلى قائمة السائقين النشطين؟')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="restore_driver">
                                                    <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 font-bold transition text-right cursor-pointer whitespace-nowrap">
                                                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                                                        </svg>
                                                        <span>استرجاع السائق 🔄</span>
                                                    </button>
                                                </form>

                                                <!-- Permanent Force Delete Form -->
                                                <form method="POST" action="" onsubmit="return confirm('تحذير نهائي: هل أنت متأكد من الحذف النهائي لهذا السائق من قاعدة البيانات؟')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="force_delete_driver">
                                                    <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
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
                                                <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من نقل هذا السائق إلى سلة المهملات والأرشيف؟ يمكنك استرجاعه في أي وقت.')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_driver">
                                                    <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 hover:text-rose-700 dark:hover:text-rose-300 font-bold transition text-right cursor-pointer">
                                                        <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
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
                        <tr><td colspan="7" class="p-10 text-center text-slate-400">لا يوجد سائقون مطابقون لشرط التصفية.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <div class="px-5 py-3.5 border-t border-slate-200/80 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-900/40">
            <div class="text-xs text-slate-500 dark:text-slate-400 font-num">
                عرض <span class="font-bold text-slate-700 dark:text-slate-200"><?= ($offset + 1) ?></span> إلى <span class="font-bold text-slate-700 dark:text-slate-200"><?= min($offset + $perPage, $totalDrivers) ?></span> من أصل <span class="font-bold text-slate-900 dark:text-white"><?= $totalDrivers ?></span> سائق
            </div>
            <div class="inline-flex items-center gap-1.5">
                <?php
                $buildPageUrl = function($p) use ($filter, $search) {
                    $params = [];
                    if (!empty($filter) && $filter !== 'all') $params['filter'] = $filter;
                    if (!empty($search)) $params['search'] = $search;
                    if ($p > 1) $params['page'] = $p;
                    return 'drivers.php' . (!empty($params) ? '?' . http_build_query($params) : '');
                };
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?= $buildPageUrl($page - 1) ?>" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition shadow-2xs">السابق</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 1 && $i <= $page + 1)): ?>
                        <?php if ($i == $page): ?>
                            <span class="w-8 h-8 rounded-xl bg-amber-500 text-slate-950 font-black text-xs flex items-center justify-center font-num shadow-xs"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= $buildPageUrl($i) ?>" class="w-8 h-8 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs flex items-center justify-center font-num transition shadow-2xs"><?= $i ?></a>
                        <?php endif; ?>
                    <?php elseif ($i == 2 && $page > 3): ?>
                        <span class="px-1 text-slate-400 font-bold">...</span>
                    <?php elseif ($i == $totalPages - 1 && $page < $totalPages - 2): ?>
                        <span class="px-1 text-slate-400 font-bold">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= $buildPageUrl($page + 1) ?>" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition shadow-2xs">التالي</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Modal: Add Driver (Sleek Desktop/ERP Multi-Step Wizard Modal) -->
<div id="addDriverModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-md hidden items-center justify-center p-3 sm:p-6 overflow-y-auto">
    <div class="bg-white max-w-4xl w-full rounded-3xl border border-slate-200/90 shadow-2xl overflow-hidden flex flex-col max-h-[92vh] my-auto transition-all animate-fadeIn text-right">
        
        <!-- Modal Top Bar -->
        <div class="px-6 py-4 bg-slate-50/90 border-b border-slate-200/80 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-400 text-slate-950 font-black flex items-center justify-center shadow-xs shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.765z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="font-black text-slate-900 text-base sm:text-lg">تسجيل سائق سيارة أجرة جديد</h3>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">إدخال البطاقة الفنية، رخصة السياقة، الفحوصات الطبية، وتعيين المركبة وفق القانون 90-11</p>
                </div>
            </div>
            
            <button type="button" onclick="closeAddDriverModal()" 
                    class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition" 
                    title="إغلاق (Esc)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Stepper Navigation Bar (Tabs) -->
        <div class="bg-white border-b border-slate-200/80 px-6 pt-3 pb-0 shrink-0">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs font-bold" id="wizardStepperTabs">
                <button type="button" onclick="goToWizardStep(1)" id="stepTab-1" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-900 text-white border-slate-900 shadow-xs">
                    <span class="w-5 h-5 rounded-full bg-white/20 text-white flex items-center justify-center text-[11px] font-num font-black shrink-0">1</span>
                    <span class="truncate">الحالة المدنية والصورة</span>
                </button>
                <button type="button" onclick="goToWizardStep(2)" id="stepTab-2" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100">
                    <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0">2</span>
                    <span class="truncate">رخصة السياقة والنشاط</span>
                </button>
                <button type="button" onclick="goToWizardStep(3)" id="stepTab-3" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100">
                    <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0">3</span>
                    <span class="truncate">المركبة والعقد</span>
                </button>
                <button type="button" onclick="goToWizardStep(4)" id="stepTab-4" 
                        class="wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100">
                    <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0">4</span>
                    <span class="truncate">الفحص الطبي الدوري</span>
                </button>
            </div>
            
            <!-- Dynamic Progress Line -->
            <div class="w-full bg-slate-100 h-1 mt-3 rounded-full overflow-hidden">
                <div id="wizardProgressLine" class="h-full bg-amber-500 transition-all duration-300 w-1/4"></div>
            </div>
        </div>

        <!-- Form Body (Scrollable container) -->
        <form id="addDriverForm" method="POST" action="" enctype="multipart/form-data" novalidate class="flex-1 overflow-y-auto p-6 sm:p-7 space-y-6 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_driver">

            <!-- ==============================================
                 STEP 1: الحالة المدنية والصورة الشخصية
                 ============================================== -->
            <div id="stepSection-1" class="wizard-section space-y-5">
                
                <div class="flex flex-col lg:flex-row items-start gap-6">
                    
                    <!-- Left: Driver Photo Portrait Uploader Box -->
                    <div class="w-full lg:w-48 shrink-0 flex flex-col items-center">
                        <label class="block font-bold text-slate-800 text-xs mb-2 text-center">صورة السائق (35x45 مم)</label>
                        
                        <div onclick="document.getElementById('driverAvatarInput').click()" 
                             id="photoDropZone"
                             class="w-36 h-48 bg-slate-50 rounded-2xl border-2 border-dashed border-slate-300 hover:border-amber-500 flex flex-col items-center justify-center relative cursor-pointer group shadow-2xs overflow-hidden transition">
                            
                            <!-- Preview Image (Initially Hidden) -->
                            <img id="driverAvatarPreview" src="" alt="معاينة الصورة" 
                                 class="w-full h-full object-cover hidden">
                            
                            <!-- Placeholder State -->
                            <div id="photoPlaceholder" class="flex flex-col items-center justify-center p-3 text-center space-y-2">
                                <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center group-hover:scale-110 transition shadow-2xs">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                                    </svg>
                                </div>
                                <span class="text-[11px] font-bold text-slate-700">اضغط لرفع الصورة</span>
                                <span class="text-[10px] text-slate-400">JPG, PNG, WEBP</span>
                            </div>

                            <!-- Hover Overlay -->
                            <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold">
                                تغيير الصورة 📷
                            </div>
                        </div>

                        <input type="file" id="driverAvatarInput" name="avatar" accept="image/*" class="hidden" onchange="previewDriverAvatar(this)">
                        <span class="text-[10px] text-slate-400 text-center mt-2">تظهر في البطاقة الفنية الرسمية للسائق</span>
                    </div>

                    <!-- Right: Civil Status & Personal Details Form -->
                    <div class="flex-1 w-full space-y-4">
                        
                        <div class="p-3 bg-amber-50/60 rounded-xl border border-amber-200/80 flex items-center gap-2 text-amber-900 font-bold text-xs">
                            <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                            <span>الحالة المدنية والهوية الشخصية للسائق</span>
                        </div>

                        <!-- Nom & Prénom -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">اللقب (Nom de famille) <span class="text-rose-500">*</span></label>
                                <input type="text" name="last_name" id="inputLastName" placeholder="مثال: بلبشير" value=""
                                       oninput="updateDriverPreviewName()"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">الاسم (Prénom) <span class="text-rose-500">*</span></label>
                                <input type="text" name="first_name" id="inputFirstName" placeholder="مثال: بوجمعة" value=""
                                       oninput="updateDriverPreviewName()"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                        </div>

                        <!-- Date & Lieu de Naissance -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">تاريخ الازدياد (Date de Naissance)</label>
                                <input type="date" name="birth_date" value=""
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">مكان الازدياد (Lieu de Naissance)</label>
                                <input type="text" name="birth_place" placeholder="مثال: أدرار" value=""
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                        </div>

                        <!-- Filiation: Père & Mère -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">اسم الأب (إبن)</label>
                                <input type="text" name="father_name" placeholder="مثال: اسم الأب" value=""
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">اسم ولقب الأم (وابن)</label>
                                <input type="text" name="mother_name" placeholder="مثال: اسم ولقب الأم" value=""
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                            </div>
                        </div>

                        <!-- Contact & Address -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">رقم الهاتف <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <input type="tel" name="phone" id="inputPhone" placeholder="06XXXXXXXX" dir="ltr" value=""
                                           oninput="updateDriverPreviewPhone()"
                                           class="w-full pl-12 pr-3 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-slate-50/60 focus:bg-white text-right transition shadow-2xs">
                                    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded text-[10px] font-num font-bold bg-slate-200 text-slate-700">🇩🇿 +213</span>
                                </div>
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">العنوان</label>
                                <input type="text" name="address" id="inputAddress" placeholder="مثال: حي 20 مسكن، أدرار" value=""
                                       oninput="updateDriverPreviewAddress()"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-slate-50/60 focus:bg-white transition shadow-2xs">
                                <input type="hidden" name="wilaya" value="01 - أدرار">
                            </div>
                        </div>

                        <!-- Driver Classification (أساسي أو بديل) -->
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 space-y-1.5">
                            <label class="block font-bold text-slate-700">تصنيف ومهمة السائق</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-200 bg-white cursor-pointer hover:border-amber-500 transition shadow-2xs">
                                    <input type="radio" name="driver_type" value="ajir" checked class="text-amber-500 focus:ring-amber-500">
                                    <div>
                                        <div class="font-bold text-xs text-slate-800">سائق أساسي (Titulaire)</div>
                                        <div class="text-[10px] text-slate-400">سائق قار مسؤول عن المركبة</div>
                                    </div>
                                </label>
                                <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-200 bg-white cursor-pointer hover:border-amber-500 transition shadow-2xs">
                                    <input type="radio" name="driver_type" value="replacement" class="text-amber-500 focus:ring-amber-500">
                                    <div>
                                        <div class="font-bold text-xs text-amber-600">سائق بديل (Remplaçant)</div>
                                        <div class="text-[10px] text-slate-400">ينوب في حالات العطل والغياب (بديل)</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- ==============================================
                 STEP 2: رخصة السياقة والنشاط
                 ============================================== -->
            <div id="stepSection-2" class="wizard-section space-y-5 hidden">
                
                <div class="p-3 bg-blue-50/60 rounded-xl border border-blue-200/80 flex items-center gap-2 text-blue-900 font-bold text-xs">
                    <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z"/></svg>
                    <span>بيانات رخصة السياقة والنشاط المهني (Permis de Conduire)</span>
                </div>

                <!-- Driver's License Info -->
                <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-4">
                    <h4 class="font-black text-slate-800 text-xs flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                        <span>بيانات رخصة السياقة (Permis de Conduire)</span>
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">رقم رخصة السياقة</label>
                            <input type="text" name="license_number" placeholder="مثال: رقم رخصة السياقة" dir="ltr" value=""
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num text-right bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">تاريخ الإصدار (Délivré le)</label>
                            <input type="date" name="license_issue_date" value=""
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">جهة الإصدار (Délivré par)</label>
                            <input type="text" name="license_issue_place" placeholder="مثال: بلدية / دائرة الإصدار" value=""
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">تاريخ انتهاء صلاحية الرخصة</label>
                            <input type="date" name="license_expiry" value=""
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                    </div>
                </div>

            </div>

            <!-- ==============================================
                 STEP 3: المركبة والعقد
                 ============================================== -->
            <div id="stepSection-3" class="wizard-section space-y-5 hidden">
                
                <div class="p-3 bg-purple-50/60 rounded-xl border border-purple-200/80 flex items-center gap-2 text-purple-900 font-bold text-xs">
                    <svg class="w-4 h-4 text-purple-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V4.5a2.25 2.25 0 00-2.25-2.25h-4.5A2.25 2.25 0 005.25 4.5v3m11.25 0H5.25"/></svg>
                    <span>دفتر المقررة، رقم المركبة، سريان العقد، وتحديد الوضعية المهنية</span>
                </div>

                <div class="space-y-4">
                    
                    <!-- Carnet Number & Vehicle Plate Box -->
                    <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-3.5">
                        <div class="flex items-center gap-2 border-b border-slate-200/80 pb-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                            <h4 class="font-black text-slate-800 text-xs">بيانات دفتر المقررة ورقم تسجيل المركبة</h4>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                                    <span class="flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                                        <span>رقم الدفتر</span>
                                    </span>
                                    <span class="text-[10px] text-purple-600 font-semibold">(الرقم / السنة)</span>
                                </label>
                                <div class="flex items-center gap-2" dir="ltr">
                                    <div class="relative flex-1">
                                        <input type="text" name="carnet_number" id="carnetNumberInput" 
                                               placeholder="الرقم (مثال: 04)" 
                                               oninput="syncCarnetNumber()"
                                               class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold font-num text-center focus:ring-4 focus:ring-purple-500/10 focus:border-purple-500 bg-white transition shadow-2xs">
                                    </div>
                                    <span class="text-slate-400 font-black text-sm select-none">/</span>
                                    <div class="relative w-28">
                                        <input type="number" name="carnet_year" id="carnetYearInput" 
                                               placeholder="السنة" 
                                               value="<?= date('Y') ?>" min="1990" max="2050"
                                               oninput="syncCarnetNumber()"
                                               class="w-full px-2 py-2.5 rounded-xl border border-slate-200 text-xs font-bold font-num text-center focus:ring-4 focus:ring-purple-500/10 focus:border-purple-500 bg-white transition shadow-2xs">
                                    </div>
                                </div>
                                <input type="hidden" name="card_number" id="inputCardNumber" value="">
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                                    <span class="flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                                        <span>رقم تسجيل المركبة (Matricule)</span>
                                    </span>
                                    <span id="driverPlateBadgePreview" class="text-[10px] text-amber-700 font-bold font-mono bg-amber-50 border border-amber-200/80 px-1.5 py-0.5 rounded-md" dir="ltr">00123-116-47</span>
                                </label>
                                
                                <!-- 3-Segmented Plate Input (Auto-advancing with -) -->
                                <div class="flex items-center gap-1.5 p-1 bg-white border border-slate-200 rounded-xl focus-within:ring-4 focus-within:ring-amber-500/10 focus-within:border-amber-500 transition shadow-2xs" dir="ltr">
                                    <!-- 1. الولاية (رقمين: مثلاً 47 أو 01) -->
                                    <input type="text" id="driverPlateWilayaInput" inputmode="numeric" maxlength="2" placeholder="47"
                                           class="w-12 text-center py-2 text-xs font-mono font-bold text-slate-900 bg-slate-50 hover:bg-white focus:bg-white rounded-lg border border-slate-200/80 focus:border-amber-500 focus:outline-none transition shadow-2xs"
                                           title="رقم الولاية (رقمين: مثلاً 47 أو 01)" autocomplete="off">

                                    <span class="text-slate-400 font-black text-sm select-none px-0.5">-</span>

                                    <!-- 2. سنة الترقيم / الصنف (3 أرقام: مثلاً 116) -->
                                    <input type="text" id="driverPlateYearInput" inputmode="numeric" maxlength="3" placeholder="116"
                                           class="w-16 text-center py-2 text-xs font-mono font-bold text-slate-900 bg-slate-50 hover:bg-white focus:bg-white rounded-lg border border-slate-200/80 focus:border-amber-500 focus:outline-none transition shadow-2xs"
                                           title="سنة الترقيم / الصنف (3 أرقام: مثلاً 116)" autocomplete="off">

                                    <span class="text-slate-400 font-black text-sm select-none px-0.5">-</span>

                                    <!-- 3. الرقم التسلسلي (5-6 أرقام: مثلاً 00123) -->
                                    <input type="text" id="driverPlateSerialInput" inputmode="numeric" maxlength="6" placeholder="00123"
                                           class="flex-1 text-center py-2 text-xs font-mono font-bold text-slate-900 bg-slate-50 hover:bg-white focus:bg-white rounded-lg border border-slate-200/80 focus:border-amber-500 focus:outline-none transition shadow-2xs"
                                           title="الرقم التسلسلي (5 أو 6 أرقام: مثلاً 00123)" autocomplete="off">

                                    <div class="px-2 flex items-center pointer-events-none select-none text-xs">
                                        <span title="الجمهورية الجزائرية الديمقراطية الشعبية">🇩🇿</span>
                                    </div>
                                </div>

                                <!-- Hidden input for form submission & live preview -->
                                <input type="hidden" name="vehicle_plate_number" id="vehiclePlateNumberInput" value="">

                                <p class="text-[10px] text-slate-400 flex items-center justify-between px-1 mt-1 font-medium select-none">
                                    <span>ولاية (47)</span>
                                    <span>صنف/سنة (116)</span>
                                    <span>تسجيل (00123)</span>
                                </p>
                            </div>
                        </div>

                        <p class="text-[11px] text-slate-400">أدخل رقم الدفتر وسنته (مثال: 04 / <?= date('Y') ?>) ورقم تسجيل المركبة (Matricule) لربطها بالسائق تلقائياً.</p>
                    </div>

                    <!-- Contract Duration Box (من إلى) -->
                    <div class="p-4 rounded-2xl bg-indigo-50/40 border border-indigo-200/80 space-y-3.5">
                        <div class="flex items-center justify-between border-b border-indigo-200/70 pb-2.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                                <h4 class="font-black text-slate-900 text-xs">عقد استغلال رخصة الأجرة والعمل (من / إلى)</h4>
                            </div>
                            <button type="button" onclick="setNewDriverContractPeriod(3)" 
                                    class="px-2.5 py-1 rounded-lg bg-indigo-100 hover:bg-indigo-200 text-indigo-900 font-bold text-[11px] transition shadow-2xs cursor-pointer flex items-center gap-1">
                                <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                <span>+3 سنوات (المدة القانونية للعقد)</span>
                            </button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">
                                    <span>بداية سريان العقد: من (Date Début)</span>
                                </label>
                                <input type="date" name="taxi_contract_start" id="taxiContractStart" 
                                       onchange="onContractStartDateChanged()"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 bg-white transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1.5">
                                    <span>نهاية سريان العقد: إلى (Date Fin)</span>
                                    <span class="text-[10px] text-indigo-600 font-normal mr-1">(صلاحية 3 سنوات)</span>
                                </label>
                                <input type="date" name="taxi_contract_end" id="taxiContractEnd" 
                                       onchange="updateDriverPreviewContract()"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold font-num focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 bg-white transition shadow-2xs">
                            </div>
                        </div>

                        <div class="flex items-center gap-2 text-[11px] text-slate-500">
                            <svg class="w-4 h-4 text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>عقد الاستغلال القانوني يمتد عادة لثلاث (03) سنوات قابلة للتجديد وفق المرسوم التنفيذي 12-230.</span>
                        </div>
                    </div>

                    <!-- دورة التدرج المهني وتحديد وضعية السائق -->
                    <div class="rounded-2xl bg-slate-50/80 border border-slate-200/90 p-4 space-y-3.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 ring-4 ring-amber-500/10"></span>
                                <span class="text-xs font-bold text-slate-800">المرحلة والوضعية المهنية للسائق:</span>
                            </div>
                            <span id="calculatedStageBadge" class="px-2.5 py-1 rounded-lg text-[11px] font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-2xs transition-all">
                                فترة تجريبية 1 (3 أشهر الأولى)
                            </span>
                        </div>

                        <!-- 4 Interactive Clickable Stage Selection Buttons -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                            <!-- Stage 1 -->
                            <button type="button" 
                                    id="btnStage_trial_1" 
                                    onclick="selectProbationStage('trial_1', true)"
                                    class="probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-white hover:bg-slate-50 border-slate-200 text-slate-700 shadow-xs focus:outline-none">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-bold text-xs">فترة تجريبية 1</span>
                                    <span class="stage-check hidden w-4 h-4 rounded-full bg-amber-500 text-white flex items-center justify-center text-[10px] font-bold">✓</span>
                                </div>
                                <div class="text-[11px] font-semibold text-slate-500 font-num">3 أشهر الأولى</div>
                            </button>

                            <!-- Stage 2 -->
                            <button type="button" 
                                    id="btnStage_trial_2" 
                                    onclick="selectProbationStage('trial_2', true)"
                                    class="probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-white hover:bg-slate-50 border-slate-200 text-slate-700 shadow-xs focus:outline-none">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-bold text-xs">تجريبية 2</span>
                                    <span class="stage-check hidden w-4 h-4 rounded-full bg-blue-500 text-white flex items-center justify-center text-[10px] font-bold">✓</span>
                                </div>
                                <div class="text-[11px] font-semibold text-slate-500 font-num">3 أشهر الثانية</div>
                            </button>

                            <!-- Stage 3 -->
                            <button type="button" 
                                    id="btnStage_trial_3" 
                                    onclick="selectProbationStage('trial_3', true)"
                                    class="probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-white hover:bg-slate-50 border-slate-200 text-slate-700 shadow-xs focus:outline-none">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-bold text-xs">تجريبية 3</span>
                                    <span class="stage-check hidden w-4 h-4 rounded-full bg-indigo-500 text-white flex items-center justify-center text-[10px] font-bold">✓</span>
                                </div>
                                <div class="text-[11px] font-semibold text-slate-500 font-num">3 أشهر الأخيرة</div>
                            </button>

                            <!-- Official Driver -->
                            <button type="button" 
                                    id="btnStage_official" 
                                    onclick="selectProbationStage('official', true)"
                                    class="probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-white hover:bg-slate-50 border-slate-200 text-slate-700 shadow-xs focus:outline-none">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-bold text-xs text-emerald-900">سائق رسمي</span>
                                    <span class="stage-check hidden w-4 h-4 rounded-full bg-emerald-600 text-white flex items-center justify-center text-[10px] font-bold">✓</span>
                                </div>
                                <div class="text-[11px] font-semibold text-emerald-700">تثبيت يدوي ✍️</div>
                            </button>
                        </div>

                        <div class="flex items-start gap-2 pt-1 text-[11px] text-slate-500">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p id="stageCalculationNotice" class="leading-relaxed">
                                انقر مباشرة على أي زر لتحديد المرحلة المطلوبة للسائق (فترة تجريبية 1، 2، 3 أو سائق رسمي).
                            </p>
                        </div>

                        <!-- Hidden inputs to seamlessly populate existing PHP and DB handlers -->
                        <input type="hidden" name="activity_start_date" id="inputActivityStartDate" value="">
                        <input type="hidden" name="probation_stage" id="inputProbationStage" value="trial_1">
                        <input type="checkbox" name="is_manual_official" id="checkManualOfficial" value="1" class="hidden">
                    </div>

                </div>

            </div>

            <!-- ==============================================
                 STEP 4: الفحص الطبي الدوري
                 ============================================== -->
            <div id="stepSection-4" class="wizard-section space-y-5 hidden">
                
                <div class="p-3 bg-emerald-50/60 rounded-xl border border-emerald-200/80 flex items-center gap-2 text-emerald-900 font-bold text-xs">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                    <span>الفحص الطبي الإجباري الدوري (صلاحية سنتين وفق المرسوم 12-230)</span>
                </div>

                <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-4">
                    <h4 class="font-black text-slate-800 text-xs flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        <span>فترة صلاحية الفحص الطبي الدوري</span>
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">صلاحية الفحص الطبي: من (Date début)</label>
                            <input type="date" name="medical_exam_start" id="medStartInput" value=""
                                   onchange="autoCalculateMedicalDates()"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1.5">
                                <span>صلاحية الفحص الطبي: إلى (Fin - سنتين)</span>
                                <span class="text-[10px] text-emerald-600 font-normal mr-1">(محسوب تلقائياً)</span>
                            </label>
                            <input type="date" name="medical_exam_end" id="medEndInput" value=""
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 font-num bg-white transition shadow-2xs">
                        </div>
                    </div>
                </div>

                <!-- LIVE DRIVER CARD PREVIEW -->
                <div class="p-4 rounded-2xl bg-slate-900 text-white space-y-3 shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-[11px] font-bold text-slate-400 flex items-center gap-1.5">
                            <span>🔍</span>
                            <span>معاينة البطاقة الفنية للسائق قبل الحفظ النهائي</span>
                        </span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500 text-slate-950 font-num">
                            TAXI CHAUFFEUR
                        </span>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="w-14 h-18 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center overflow-hidden shrink-0">
                            <img id="livePreviewImg" src="" class="w-full h-full object-cover hidden">
                            <span id="livePreviewInitials" class="text-lg font-black text-amber-500 font-num">?</span>
                        </div>
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center gap-2">
                                <h4 id="livePreviewName" class="font-black text-sm text-white truncate">اسم ولقب السائق</h4>
                                <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">جديد</span>
                                <span id="livePreviewStageBadge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                    فترة تجريبية الأولى
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-300 flex items-center gap-2 font-num">
                                <span id="livePreviewPhone">—</span>
                                <span>•</span>
                                <span id="livePreviewAddress">—</span>
                            </div>
                            <div class="text-[10px] text-amber-400 font-semibold flex items-center gap-2 flex-wrap">
                                <span>الدفتر: <strong id="livePreviewCarnet" class="text-white font-num font-bold">—</strong></span>
                                <span>•</span>
                                <span>المركبة: <strong id="livePreviewVehicle" class="text-white font-num font-bold">لم تعين بعد</strong></span>
                                <span>•</span>
                                <span>العقد: <strong id="livePreviewContractBadge" class="text-white font-num font-bold">—</strong></span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Native hidden submit button to guarantee browser-standard form submission -->
            <button type="submit" id="nativeSubmitDriverBtn" class="hidden"></button>
        </form>

        <!-- Sticky Bottom Actions Footer -->
        <div class="px-6 py-4 bg-slate-50/90 border-t border-slate-200/80 flex items-center justify-between shrink-0">
            <button type="button" id="prevStepBtn" onclick="prevWizardStep()" 
                    class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-white transition text-xs hidden items-center gap-1.5 shadow-2xs">
                <span>🠔 السابق</span>
            </button>
            
            <div class="text-xs font-bold text-slate-400" id="wizardStepIndicator">
                الخطوة <span id="currentStepNum" class="font-num text-slate-900 font-black">1</span> من <span class="font-num">4</span>
            </div>

            <div class="flex items-center gap-2.5">
                <button type="button" onclick="closeAddDriverModal()" 
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-white transition text-xs shadow-2xs">
                    إلغاء
                </button>

                <button type="button" id="nextStepBtn" onclick="nextWizardStep()" 
                        class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-black transition text-xs flex items-center gap-1.5 shadow-xs cursor-pointer">
                    <span>التالي</span>
                    <span>➔</span>
                </button>

                <button type="button" id="submitDriverBtn" form="addDriverForm" onclick="submitAddDriverForm()" 
                        class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-400 hover:from-amber-600 hover:to-amber-500 text-slate-950 font-black transition text-xs hidden items-center gap-2 shadow-md cursor-pointer">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    <span>حفظ وتسجيل البطاقة الفنية الرسمية</span>
                </button>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Manage Driver Documents & Renewals (إدارة وتجديد وثائق السائق) -->
<div id="manageDocsModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-md hidden items-center justify-center p-3 sm:p-6 overflow-y-auto">
    <div class="bg-white max-w-3xl w-full rounded-3xl border border-slate-200/90 shadow-2xl overflow-hidden flex flex-col max-h-[92vh] my-auto transition-all animate-fadeIn text-right">
        
        <!-- Modal Top Bar -->
        <div class="px-6 py-4 bg-slate-50 border-b border-slate-200/80 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white font-black flex items-center justify-center shadow-xs shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="font-black text-slate-900 text-base sm:text-lg">تعديل بيانات وهوية ووثائق السائق</h3>
                        <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-blue-100 text-blue-800 border border-blue-200 font-num">
                            تحديث فوري
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">تعديل الحالة المدنية، رخصة السياقة، الفحص الطبي الدوري، وعقد الاستغلال</p>
                </div>
            </div>
            
            <button type="button" onclick="closeManageDocsModal()" 
                    class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition cursor-pointer" 
                    title="إغلاق (Esc)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Driver Info Hero Bar -->
        <div class="px-6 py-3 bg-gradient-to-r from-slate-900 to-slate-800 text-white flex items-center justify-between flex-wrap gap-3 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white/10 border border-white/20 flex items-center justify-center font-bold text-amber-400 text-sm overflow-hidden" id="docDriverAvatarBox">
                    <span id="docDriverInitials">?</span>
                </div>
                <div>
                    <h4 class="font-bold text-sm text-white" id="docDriverName">اسم السائق</h4>
                    <div class="flex items-center gap-2 text-xs text-slate-300 font-num mt-0.5">
                        <span id="docDriverPhone" dir="ltr">0661000000</span>
                        <span>•</span>
                        <span id="docDriverVehicle" class="text-amber-400">بدون مركبة</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="#" id="docFicheLink" target="_blank" class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-bold transition flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    <span>معاينة البطاقة الفنية الرسمية</span>
                </a>
            </div>
        </div>

        <!-- Modal Form Body -->
        <form method="POST" action="" id="manageDocsForm" class="overflow-y-auto p-6 space-y-5 text-xs flex-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_driver_docs">
            <input type="hidden" name="driver_id" id="docDriverId" value="">
            <input type="hidden" name="return_filter" value="<?= htmlspecialchars($filter) ?>">

            <!-- 1. الحالة المدنية والهوية الشخصية -->
            <div class="p-4 rounded-2xl bg-amber-50/50 border border-amber-200/80 space-y-3.5">
                <div class="flex items-center justify-between border-b border-amber-200/70 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                        <h4 class="font-black text-slate-900 text-xs">1. الحالة المدنية والهوية الشخصية (État Civil & Filiation)</h4>
                    </div>
                    <span class="text-[10px] font-bold text-amber-800 bg-amber-100 px-2 py-0.5 rounded-md">دفتر المقررة والبطاقة المهنية</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اللقب (Nom de famille)</label>
                        <input type="text" name="last_name" id="docLastName" placeholder="مثال: بلبشير" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">الاسم (Prénom)</label>
                        <input type="text" name="first_name" id="docFirstName" placeholder="مثال: بوجمعة" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ الازدياد (Date de Naissance)</label>
                        <input type="date" name="birth_date" id="docBirthDate" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">مكان الازدياد (Lieu de Naissance)</label>
                        <input type="text" name="birth_place" id="docBirthPlace" placeholder="مثال: أدرار" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اسم الأب (ابن)</label>
                        <input type="text" name="father_name" id="docFatherName" placeholder="مثال: اسم الأب" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">اسم ولقب الأم (وابن)</label>
                        <input type="text" name="mother_name" id="docMotherName" placeholder="مثال: اسم ولقب الأم" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم الهاتف</label>
                        <input type="tel" name="phone" id="docPhone" placeholder="06XXXXXXXX" dir="ltr" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">العنوان الكامل للإقامة</label>
                        <input type="text" name="address" id="docAddress" placeholder="مثال: حي 20 مسكن، أدرار" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>
            </div>

            <!-- 2. رخصة السياقة -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-3.5">
                <div class="flex items-center justify-between border-b border-slate-200/70 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                        <h4 class="font-black text-slate-900 text-xs">2. بيانات رخصة السياقة (Permis de Conduire)</h4>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="renewLicense(5)" class="px-2.5 py-1 rounded-lg bg-amber-100 hover:bg-amber-200 text-amber-900 font-bold text-[11px] transition shadow-2xs cursor-pointer">
                            +5 سنوات
                        </button>
                        <button type="button" onclick="renewLicense(10)" class="px-2.5 py-1 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-[11px] transition shadow-2xs cursor-pointer">
                            +10 سنوات (تجديد قياسي)
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم رخصة السياقة</label>
                        <input type="text" name="license_number" id="docLicenseNumber" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition" dir="ltr">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ انتهاء صلاحية الرخصة</label>
                        <input type="date" name="license_expiry" id="docLicenseExpiry" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ إصدار الرخصة</label>
                        <input type="date" name="license_issue_date" id="docLicenseIssueDate" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">دائرة / بلدية الإصدار</label>
                        <input type="text" name="license_issue_place" id="docLicenseIssuePlace" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                    </div>
                </div>
            </div>

            <!-- 2. الفحص الطبي الدوري -->
            <div class="p-4 rounded-2xl bg-emerald-50/50 border border-emerald-200/80 space-y-3.5">
                <div class="flex items-center justify-between border-b border-emerald-200/70 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        <h4 class="font-black text-slate-900 text-xs">الفحوصات الطبية الدورية (Visites Médicales)</h4>
                    </div>
                    <button type="button" onclick="renewMedicalExam()" class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] transition shadow-2xs flex items-center gap-1 cursor-pointer">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        <span>تجديد الفحص الدوري (+سنتين رسمي)</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ بداية الفحص الطبي الساري</label>
                        <input type="date" name="medical_exam_start" id="docMedStart" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاريخ انتهاء صلاحية الفحص (سنتان)</label>
                        <input type="date" name="medical_cert_expiry" id="docMedExpiry" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold font-num focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition">
                    </div>
                </div>
            </div>

            <!-- 3. رخصة سيارة الأجرة والعقد -->
            <div class="p-4 rounded-2xl bg-indigo-50/40 border border-indigo-200/80 space-y-3.5">
                <div class="flex items-center justify-between border-b border-indigo-200/70 pb-2.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                        <h4 class="font-black text-slate-900 text-xs">رخصة استغلال الأجرة ودفتر المقررة (Livret de Bord)</h4>
                    </div>
                    <button type="button" onclick="renewTaxiContract(3)" class="px-2.5 py-1 rounded-lg bg-indigo-100 hover:bg-indigo-200 text-indigo-900 font-bold text-[11px] transition shadow-2xs cursor-pointer">
                        +3 سنوات (تجديد رخصة الاستغلال)
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">رقم الدفتر / المقررة</label>
                        <input type="text" name="card_number" id="docCardNumber" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition" dir="ltr">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">بداية سريان العقد / الرخصة</label>
                        <input type="date" name="taxi_contract_start" id="docContractStart" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold font-num focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">نهاية سريان العقد / الرخصة</label>
                        <input type="date" name="taxi_contract_end" id="docContractEnd" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold font-num focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition">
                    </div>
                </div>
            </div>

            <!-- Sticky Bottom Actions Inside Form -->
            <div class="pt-2 flex items-center justify-between gap-3 border-t border-slate-200">
                <button type="button" onclick="closeManageDocsModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition text-xs cursor-pointer">
                    إلغاء
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-black transition text-xs flex items-center gap-2 shadow-md cursor-pointer">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    <span>حفظ التحديث وتثبيت تجديد الوثائق</span>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Add Driver Wizard Interactive JavaScript -->
<script>
    let currentWizardStep = 1;
    const totalWizardSteps = 4;

    let userManuallySelectedStage = false;

    function openAddDriverModal() {
        const modal = document.getElementById('addDriverModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        
        // Reset form for clean production entry
        const form = document.getElementById('addDriverForm');
        if (form) form.reset();

        userManuallySelectedStage = false;
        selectProbationStage('trial_1', false);

        updateDriverPreviewName();
        updateDriverPreviewPhone();
        updateDriverPreviewAddress();
        
        const plateInput = document.getElementById('vehiclePlateNumberInput');
        if (plateInput) plateInput.value = '';
        const driverWilaya = document.getElementById('driverPlateWilayaInput');
        if (driverWilaya) driverWilaya.value = '';
        const driverYear = document.getElementById('driverPlateYearInput');
        if (driverYear) driverYear.value = '';
        const driverSerial = document.getElementById('driverPlateSerialInput');
        if (driverSerial) driverSerial.value = '';
        const driverBadge = document.getElementById('driverPlateBadgePreview');
        if (driverBadge) driverBadge.textContent = '00123-116-47';
        updateDriverPreviewVehicle();

        const carnetNum = document.getElementById('carnetNumberInput');
        if (carnetNum) carnetNum.value = '';
        const carnetYear = document.getElementById('carnetYearInput');
        if (carnetYear) carnetYear.value = new Date().getFullYear();
        syncCarnetNumber();

        setNewDriverContractPeriod(3);

        const previewBadge = document.getElementById('livePreviewPeriodBadge');
        if (previewBadge) previewBadge.textContent = '—';

        goToWizardStep(1);
    }

    function closeAddDriverModal() {
        const modal = document.getElementById('addDriverModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    // Format Month/Year Input (MM/YYYY) e.g. 07/2005
    function formatMonthYearInput(input) {
        userManuallySelectedStage = false;
        let v = input.value.replace(/[^\d]/g, '');
        if (v.length > 2) {
            let m = parseInt(v.substring(0, 2), 10);
            if (m > 12) m = 12;
            if (m === 0) m = 1;
            const mStr = m < 10 ? '0' + m : '' + m;
            v = mStr + '/' + v.substring(2, 6);
        }
        input.value = v;
        syncMonthPeriodValues(input.value);
    }

    // Synchronize native month picker (YYYY-MM) with visible input (MM/YYYY)
    function syncNativeMonthToInput(nativePicker) {
        if (!nativePicker.value) return;
        userManuallySelectedStage = false;
        const parts = nativePicker.value.split('-');
        if (parts.length === 2) {
            const year = parts[0];
            const month = parts[1];
            const formatted = month + '/' + year;
            const inputPeriod = document.getElementById('inputActivityPeriod');
            if (inputPeriod) inputPeriod.value = formatted;
            syncMonthPeriodValues(formatted);
        }
    }

    // Calculate elapsed months between given MM/YYYY and current date
    function calculateElapsedMonths(val) {
        if (!val) return 0;
        const parts = val.split('/');
        if (parts.length !== 2) return 0;
        const month = parseInt(parts[0], 10);
        const year = parseInt(parts[1], 10);
        if (isNaN(month) || isNaN(year)) return 0;

        const today = new Date();
        const curYear = today.getFullYear();
        const curMonth = today.getMonth() + 1; // 1-12

        let diff = (curYear - year) * 12 + (curMonth - month);
        return diff < 0 ? 0 : diff;
    }

    // Select probation stage interactively via clickable buttons
    function selectProbationStage(stage, isUserClick = false) {
        if (isUserClick) {
            userManuallySelectedStage = true;
        }

        const hiddenStage = document.getElementById('inputProbationStage');
        const chkOfficial = document.getElementById('checkManualOfficial');
        const badge = document.getElementById('calculatedStageBadge');
        const notice = document.getElementById('stageCalculationNotice');

        const btn1 = document.getElementById('btnStage_trial_1');
        const btn2 = document.getElementById('btnStage_trial_2');
        const btn3 = document.getElementById('btnStage_trial_3');
        const btnOff = document.getElementById('btnStage_official');

        const items = [
            { id: 'trial_1', btn: btn1 },
            { id: 'trial_2', btn: btn2 },
            { id: 'trial_3', btn: btn3 },
            { id: 'official', btn: btnOff }
        ];

        // Reset all buttons to default clean state
        items.forEach(item => {
            if (!item.btn) return;
            item.btn.className = 'probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-white hover:bg-slate-50 border-slate-200 text-slate-700 shadow-xs focus:outline-none';
            const check = item.btn.querySelector('.stage-check');
            if (check) {
                check.classList.add('hidden');
                check.classList.remove('flex');
            }
        });

        if (stage === 'trial_1') {
            if (hiddenStage) hiddenStage.value = 'trial_1';
            if (chkOfficial) chkOfficial.checked = false;
            if (btn1) {
                btn1.className = 'probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-amber-50/90 border-amber-500 text-amber-950 font-black ring-2 ring-amber-500/25 shadow-xs focus:outline-none';
                const check = btn1.querySelector('.stage-check');
                if (check) {
                    check.classList.remove('hidden');
                    check.classList.add('flex');
                }
            }
            if (badge) {
                badge.className = 'px-2.5 py-1 rounded-lg text-[11px] font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-2xs';
                badge.textContent = 'فترة تجريبية 1 (3 أشهر الأولى)';
            }
            if (notice) {
                notice.textContent = 'السائق في الفترة التجريبية الأولى (3 أشهر الأولى). عند انقضائها ينتقل للمرحلة الثانية.';
            }
            updateDriverPreviewStage('trial_1');
        } else if (stage === 'trial_2') {
            if (hiddenStage) hiddenStage.value = 'trial_2';
            if (chkOfficial) chkOfficial.checked = false;
            if (btn2) {
                btn2.className = 'probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-blue-50/90 border-blue-500 text-blue-950 font-black ring-2 ring-blue-500/25 shadow-xs focus:outline-none';
                const check = btn2.querySelector('.stage-check');
                if (check) {
                    check.classList.remove('hidden');
                    check.classList.add('flex');
                }
            }
            if (badge) {
                badge.className = 'px-2.5 py-1 rounded-lg text-[11px] font-bold bg-blue-100 text-blue-900 border border-blue-300 shadow-2xs';
                badge.textContent = 'المرحلة 2: تجريبية 2 (3 أشهر الثانية)';
            }
            if (notice) {
                notice.textContent = 'السائق في الفترة التجريبية الثانية (3 أشهر الثانية).';
            }
            updateDriverPreviewStage('trial_2');
        } else if (stage === 'trial_3') {
            if (hiddenStage) hiddenStage.value = 'trial_3';
            if (chkOfficial) chkOfficial.checked = false;
            if (btn3) {
                btn3.className = 'probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-indigo-50/90 border-indigo-500 text-indigo-950 font-black ring-2 ring-indigo-500/25 shadow-xs focus:outline-none';
                const check = btn3.querySelector('.stage-check');
                if (check) {
                    check.classList.remove('hidden');
                    check.classList.add('flex');
                }
            }
            if (badge) {
                badge.className = 'px-2.5 py-1 rounded-lg text-[11px] font-bold bg-indigo-100 text-indigo-900 border border-indigo-300 shadow-2xs';
                badge.textContent = 'المرحلة 3: تجريبية 3 (3 أشهر الأخيرة)';
            }
            if (notice) {
                notice.textContent = 'السائق في الفترة التجريبية الثالثة (3 أشهر الأخيرة). بعد 9 أشهر يتوقف التدرج بانتظار التثبيت.';
            }
            updateDriverPreviewStage('trial_3');
        } else if (stage === 'official') {
            if (hiddenStage) hiddenStage.value = 'official';
            if (chkOfficial) chkOfficial.checked = true;
            if (btnOff) {
                btnOff.className = 'probation-btn relative group p-3 rounded-xl border-2 text-right transition-all duration-200 cursor-pointer bg-emerald-50/90 border-emerald-500 text-emerald-950 font-black ring-2 ring-emerald-500/25 shadow-xs focus:outline-none';
                const check = btnOff.querySelector('.stage-check');
                if (check) {
                    check.classList.remove('hidden');
                    check.classList.add('flex');
                }
            }
            if (badge) {
                badge.className = 'px-2.5 py-1 rounded-lg text-[11px] font-bold bg-emerald-100 text-emerald-900 border border-emerald-300 shadow-2xs';
                badge.textContent = 'الوضعية: سائق رسمي (تثبيت يدوي ✍️)';
            }
            if (notice) {
                notice.textContent = 'تم تعيين وتثبيت السائق كـ «سائق رسمي» بصفة دائمة وتجاوز كافة الفترات التجريبية.';
            }
            updateDriverPreviewStage('official');
        }
    }

    // Dynamic UI update for 3-month probation cycles
    function updateProbationProgressionUI(val) {
        if (userManuallySelectedStage) {
            return;
        }

        const elapsed = calculateElapsedMonths(val);
        if (elapsed < 3) {
            selectProbationStage('trial_1', false);
        } else if (elapsed < 6) {
            selectProbationStage('trial_2', false);
        } else if (elapsed < 9) {
            selectProbationStage('trial_3', false);
        } else {
            selectProbationStage('trial_3', false);
            const badge = document.getElementById('calculatedStageBadge');
            const notice = document.getElementById('stageCalculationNotice');
            if (badge) {
                badge.className = 'px-2.5 py-1 rounded-lg text-[11px] font-bold bg-purple-100 text-purple-900 border border-purple-300 shadow-2xs';
                badge.textContent = 'تجريبية 3 (اكتملت 9 أشهر - بانتظار التثبيت)';
            }
            if (notice) {
                notice.textContent = 'اكتملت الفترات التجريبية الثلاث (9 أشهر). توقف التدرج التلقائي وبانتظار تثبيته يدوياً كسائق رسمي من طرف الإدارة.';
            }
        }
    }

    function toggleManualOfficial(checkbox) {
        if (checkbox && checkbox.checked) {
            selectProbationStage('official', true);
        } else {
            selectProbationStage('trial_1', true);
        }
    }

    // Sync values across hidden inputs and Step 4 live preview
    function syncMonthPeriodValues(val) {
        const hiddenDate = document.getElementById('inputActivityStartDate');
        const nativePicker = document.getElementById('nativeMonthPicker');
        const previewBadge = document.getElementById('livePreviewPeriodBadge');

        const parts = (val || '').split('/');
        if (parts.length === 2 && parts[0].length === 2 && parts[1].length === 4) {
            const month = parts[0];
            const year = parts[1];
            if (hiddenDate) hiddenDate.value = year + '-' + month + '-01';
            if (nativePicker) nativePicker.value = year + '-' + month;
        }
        if (previewBadge && val) {
            previewBadge.textContent = val;
        }
        updateProbationProgressionUI(val);
    }

    function goToWizardStep(step) {
        if (step < 1 || step > totalWizardSteps) return;

        // Validation before moving forward from step 1
        if (step > 1 && currentWizardStep === 1) {
            const lastName = document.getElementById('inputLastName');
            const firstName = document.getElementById('inputFirstName');
            const phone = document.getElementById('inputPhone');
            if (!lastName || !lastName.value.trim()) {
                alert('يرجى إدخال لقب السائق (Nom de famille) للمتابعة.');
                lastName.focus();
                return;
            }
            if (!firstName || !firstName.value.trim()) {
                alert('يرجى إدخال اسم السائق (Prénom) للمتابعة.');
                firstName.focus();
                return;
            }
            if (!phone || !phone.value.trim()) {
                alert('يرجى إدخال رقم هاتف السائق للمتابعة.');
                phone.focus();
                return;
            }
        }

        currentWizardStep = step;

        // Show active section, hide others
        for (let i = 1; i <= totalWizardSteps; i++) {
            const section = document.getElementById('stepSection-' + i);
            const tab = document.getElementById('stepTab-' + i);
            if (section) {
                if (i === step) {
                    section.classList.remove('hidden');
                } else {
                    section.classList.add('hidden');
                }
            }
            if (tab) {
                if (i === step) {
                    tab.className = 'wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-900 text-white border-slate-900 shadow-xs';
                    tab.querySelector('span:first-child').className = 'w-5 h-5 rounded-full bg-white/20 text-white flex items-center justify-center text-[11px] font-num font-black shrink-0';
                } else if (i < step) {
                    tab.className = 'wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-amber-50 text-amber-900 border-amber-200/80 shadow-2xs';
                    tab.querySelector('span:first-child').className = 'w-5 h-5 rounded-full bg-amber-200 text-amber-900 flex items-center justify-center text-[11px] font-num font-black shrink-0';
                } else {
                    tab.className = 'wizard-tab py-2.5 px-3 rounded-xl border flex items-center justify-center gap-2 transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100';
                    tab.querySelector('span:first-child').className = 'w-5 h-5 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[11px] font-num font-black shrink-0';
                }
            }
        }

        // Progress bar width
        const progressLine = document.getElementById('wizardProgressLine');
        if (progressLine) {
            progressLine.style.width = ((step / totalWizardSteps) * 100) + '%';
        }

        // Indicator numbers
        document.getElementById('currentStepNum').textContent = step;

        // Bottom buttons visibility
        const prevBtn = document.getElementById('prevStepBtn');
        const nextBtn = document.getElementById('nextStepBtn');
        const submitBtn = document.getElementById('submitDriverBtn');

        if (step === 1) {
            prevBtn.classList.add('hidden');
            prevBtn.classList.remove('flex');
        } else {
            prevBtn.classList.remove('hidden');
            prevBtn.classList.add('flex');
        }

        if (step === totalWizardSteps) {
            nextBtn.classList.add('hidden');
            submitBtn.classList.remove('hidden');
            submitBtn.classList.add('flex');
        } else {
            nextBtn.classList.remove('hidden');
            submitBtn.classList.add('hidden');
            submitBtn.classList.remove('flex');
        }
    }

    function nextWizardStep() {
        goToWizardStep(currentWizardStep + 1);
    }

    function prevWizardStep() {
        goToWizardStep(currentWizardStep - 1);
    }

    function submitAddDriverForm() {
        const form = document.getElementById('addDriverForm');
        const lastName = document.getElementById('inputLastName');
        const firstName = document.getElementById('inputFirstName');
        const phone = document.getElementById('inputPhone');

        if (!lastName || !lastName.value.trim()) {
            goToWizardStep(1);
            alert('يرجى إدخال لقب السائق (Nom de famille) قبل الحفظ.');
            if (lastName) lastName.focus();
            return;
        }
        if (!firstName || !firstName.value.trim()) {
            goToWizardStep(1);
            alert('يرجى إدخال اسم السائق (Prénom) قبل الحفظ.');
            if (firstName) firstName.focus();
            return;
        }
        if (!phone || !phone.value.trim()) {
            goToWizardStep(1);
            alert('يرجى إدخال رقم هاتف السائق قبل الحفظ.');
            if (phone) phone.focus();
            return;
        }

        const nativeBtn = document.getElementById('nativeSubmitDriverBtn');
        if (nativeBtn) {
            nativeBtn.click();
        } else if (form.requestSubmit) {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    // Live avatar preview
    function previewDriverAvatar(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImg = document.getElementById('driverAvatarPreview');
                const placeholder = document.getElementById('photoPlaceholder');
                const livePreviewImg = document.getElementById('livePreviewImg');
                const livePreviewInitials = document.getElementById('livePreviewInitials');

                previewImg.src = e.target.result;
                previewImg.classList.remove('hidden');
                placeholder.classList.add('hidden');

                if (livePreviewImg) {
                    livePreviewImg.src = e.target.result;
                    livePreviewImg.classList.remove('hidden');
                }
                if (livePreviewInitials) {
                    livePreviewInitials.classList.add('hidden');
                }
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Live preview name
    function updateDriverPreviewName() {
        const first = document.getElementById('inputFirstName')?.value.trim() || '';
        const last = document.getElementById('inputLastName')?.value.trim() || '';
        const fullName = (first + ' ' + last).trim() || 'اسم ولقب السائق';
        
        const previewName = document.getElementById('livePreviewName');
        const previewInitials = document.getElementById('livePreviewInitials');

        if (previewName) previewName.textContent = fullName;
        if (previewInitials) {
            previewInitials.textContent = first ? first.charAt(0) : '?';
        }
    }

    // Live preview phone
    function updateDriverPreviewPhone() {
        const phone = document.getElementById('inputPhone')?.value.trim() || '—';
        const previewPhone = document.getElementById('livePreviewPhone');
        if (previewPhone) previewPhone.textContent = phone;
    }

    function updateDriverPreviewAddress() {
        const addr = document.getElementById('inputAddress')?.value.trim() || '—';
        const previewAddr = document.getElementById('livePreviewAddress');
        if (previewAddr) previewAddr.textContent = addr;
    }

    // Carnet & Vehicle synchronization
    function syncCarnetNumber() {
        const numInput = document.getElementById('carnetNumberInput');
        const yearInput = document.getElementById('carnetYearInput');
        const cardInput = document.getElementById('inputCardNumber');
        const previewCarnet = document.getElementById('livePreviewCarnet');
        
        const num = (numInput?.value || '').trim();
        const year = (yearInput?.value || '').trim();
        let formatted = '';
        if (num && year) {
            formatted = num + '/' + year;
        } else if (num) {
            formatted = num;
        } else if (year) {
            formatted = year;
        }
        if (cardInput) cardInput.value = formatted;
        if (previewCarnet) previewCarnet.textContent = formatted || '—';
    }

    // Algerian License Plate: 3-part auto-advance separated by '-' (e.g. 00123-116-47)
    function initDriverPlateInputAutoAdvance() {
        const wilayaInput = document.getElementById('driverPlateWilayaInput');
        const yearInput = document.getElementById('driverPlateYearInput');
        const serialInput = document.getElementById('driverPlateSerialInput');
        const hiddenInput = document.getElementById('vehiclePlateNumberInput');
        const previewBadge = document.getElementById('driverPlateBadgePreview');

        if (!wilayaInput || !yearInput || !serialInput || !hiddenInput) return;

        function sync() {
            const w = wilayaInput.value.trim().replace(/\D/g, '');
            const y = yearInput.value.trim().replace(/\D/g, '');
            const s = serialInput.value.trim().replace(/\D/g, '');

            let formatted = '';
            if (s && y && w) {
                formatted = `${s}-${y}-${w}`;
            } else if (s || y || w) {
                const parts = [];
                if (s) parts.push(s);
                if (y) parts.push(y);
                if (w) parts.push(w);
                formatted = parts.join('-');
            }

            hiddenInput.value = formatted;
            if (previewBadge) {
                previewBadge.textContent = formatted || '00123-116-47';
            }
            updateDriverPreviewVehicle();
        }

        function wireInput(input, maxLen, nextInput, prevInput) {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '');
                sync();
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
                    yearInput.value = y.replace(/\D/g, '').slice(0, 3);
                    serialInput.value = s.replace(/\D/g, '').slice(0, 6);
                    sync();
                    serialInput.focus();
                }
            });
        }

        wireInput(wilayaInput, 2, yearInput, null);
        wireInput(yearInput, 3, serialInput, wilayaInput);
        wireInput(serialInput, 6, null, yearInput);
    }

    function onPlateInputChanged(input) {
        updateDriverPreviewVehicle();
    }

    // Live preview vehicle
    function updateDriverPreviewVehicle() {
        const plateInput = document.getElementById('vehiclePlateNumberInput');
        const previewVeh = document.getElementById('livePreviewVehicle');
        if (!previewVeh) return;

        const plate = plateInput ? plateInput.value.trim() : '';
        if (plate) {
            previewVeh.textContent = plate;
        } else {
            previewVeh.textContent = 'لم تعين بعد (شاغرة)';
        }
    }

    // Contract duration helpers for Add Driver modal
    function setNewDriverContractPeriod(years = 3) {
        const startInput = document.getElementById('taxiContractStart');
        const endInput = document.getElementById('taxiContractEnd');
        if (!startInput || !endInput) return;

        let startDate = startInput.value ? new Date(startInput.value) : new Date();
        if (isNaN(startDate.getTime())) startDate = new Date();
        
        startInput.value = startDate.toISOString().split('T')[0];
        const endDate = new Date(startDate);
        endDate.setFullYear(endDate.getFullYear() + parseInt(years));
        endDate.setDate(endDate.getDate() - 1);
        endInput.value = endDate.toISOString().split('T')[0];
        updateDriverPreviewContract();
    }

    function onContractStartDateChanged() {
        const startInput = document.getElementById('taxiContractStart');
        const endInput = document.getElementById('taxiContractEnd');
        if (startInput && startInput.value && endInput && !endInput.value) {
            setNewDriverContractPeriod(3);
        } else {
            updateDriverPreviewContract();
        }
    }

    function updateDriverPreviewContract() {
        const startInput = document.getElementById('taxiContractStart');
        const endInput = document.getElementById('taxiContractEnd');
        const previewContract = document.getElementById('livePreviewContractBadge');
        if (!previewContract) return;

        const s = startInput ? startInput.value : '';
        const e = endInput ? endInput.value : '';

        if (s && e) {
            previewContract.textContent = 'من ' + s + ' إلى ' + e;
        } else if (s) {
            previewContract.textContent = 'ساري من ' + s;
        } else {
            previewContract.textContent = 'لم يحدد بعد';
        }
    }

    // Live preview probation stage badge
    function updateDriverPreviewStage(stage) {
        const badge = document.getElementById('livePreviewStageBadge');
        if (!badge) return;
        const stageMap = {
            'trial_1': { text: 'فترة تجريبية الأولى', cls: 'bg-amber-500/20 text-amber-300 border-amber-500/30' },
            'trial_2': { text: 'تجريبية الثانية', cls: 'bg-blue-500/20 text-blue-300 border-blue-500/30' },
            'trial_3': { text: 'تجريبية الثالثة', cls: 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30' },
            'official': { text: 'سائق رسمي', cls: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' }
        };
        if (stage && stageMap[stage]) {
            const s = stageMap[stage];
            badge.textContent = s.text;
            badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold border ' + s.cls;
        } else {
            const display = stage ? 'الوضعية: ' + stage : 'الوضعية: 07/2005';
            badge.textContent = display;
            badge.className = 'px-2 py-0.5 rounded text-[10px] font-bold border bg-amber-500/20 text-amber-300 border-amber-500/30 font-num';
        }
    }

    // Auto-calculate medical dates (+2 years)
    function autoCalculateMedicalDates() {
        const startInput = document.getElementById('medStartInput');
        const endInput = document.getElementById('medEndInput');
        if (startInput && startInput.value && endInput) {
            const startDate = new Date(startInput.value);
            if (!isNaN(startDate.getTime())) {
                const endDate = new Date(startDate);
                endDate.setFullYear(endDate.getFullYear() + 2);
                endDate.setDate(endDate.getDate() - 1);
                endInput.value = endDate.toISOString().split('T')[0];
            }
        }
    }

    // Auto-calculate Taxi Contract dates (+3 years default)
    function autoCalculateTaxiContractDates(years = 3) {
        const startInput = document.getElementById('taxiContractStart');
        const endInput = document.getElementById('taxiContractEnd');
        if (startInput && startInput.value && endInput) {
            const startDate = new Date(startInput.value);
            if (!isNaN(startDate.getTime())) {
                const endDate = new Date(startDate);
                endDate.setFullYear(endDate.getFullYear() + parseInt(years));
                endDate.setDate(endDate.getDate() - 1);
                endInput.value = endDate.toISOString().split('T')[0];
            }
        }
    }

    // ==========================================
    // Manage Driver Documents & Renewals Modal
    // ==========================================
    function openManageDocsModal(driverOrId) {
        if (!driverOrId) return;
        let driver = driverOrId;
        if (typeof driverOrId === 'number' || (typeof driverOrId === 'string' && /^\d+$/.test(driverOrId))) {
            driver = (window.DRIVERS_MAP && window.DRIVERS_MAP[driverOrId]) ? window.DRIVERS_MAP[driverOrId] : null;
        } else if (typeof driverOrId === 'string') {
            try { driver = JSON.parse(driverOrId); } catch (e) { return; }
        }
        if (!driver) return;

        document.getElementById('docDriverId').value = driver.id || '';
        document.getElementById('docDriverName').textContent = driver.full_name || 'سائق';
        document.getElementById('docDriverPhone').textContent = driver.phone || '—';

        // Avatar
        const avatarBox = document.getElementById('docDriverAvatarBox');
        if (avatarBox) {
            if (driver.avatar_url) {
                avatarBox.innerHTML = '<img src="' + driver.avatar_url + '" class="w-full h-full object-cover">';
            } else {
                avatarBox.innerHTML = '<span class="text-sm font-bold text-amber-400">' + (driver.full_name ? driver.full_name.charAt(0) : '?') + '</span>';
            }
        }

        // Vehicle
        const vehElem = document.getElementById('docDriverVehicle');
        if (vehElem) {
            if (driver.plate_number) {
                vehElem.textContent = driver.plate_number + (driver.door_number ? ' (باب: ' + driver.door_number + ')' : '');
            } else {
                vehElem.textContent = 'بدون مركبة حالياً (احتياطي)';
            }
        }

        // Fiche link
        const ficheLink = document.getElementById('docFicheLink');
        if (ficheLink && driver.id) {
            ficheLink.href = 'driver_fiche.php?id=' + driver.id;
        }

        // Civil Status fields
        const docLast = document.getElementById('docLastName');
        if (docLast) docLast.value = driver.last_name || '';
        const docFirst = document.getElementById('docFirstName');
        if (docFirst) docFirst.value = driver.first_name || '';
        const docBirthDate = document.getElementById('docBirthDate');
        if (docBirthDate) docBirthDate.value = driver.birth_date || '';
        const docBirthPlace = document.getElementById('docBirthPlace');
        if (docBirthPlace) docBirthPlace.value = driver.birth_place || '';
        const docFather = document.getElementById('docFatherName');
        if (docFather) docFather.value = driver.father_name || '';
        const docMother = document.getElementById('docMotherName');
        if (docMother) docMother.value = driver.mother_name || '';
        const docPhone = document.getElementById('docPhone');
        if (docPhone) docPhone.value = driver.phone || '';
        const docAddress = document.getElementById('docAddress');
        if (docAddress) docAddress.value = driver.address || '';

        // License fields
        document.getElementById('docLicenseNumber').value = driver.license_number || '';
        document.getElementById('docLicenseIssueDate').value = driver.license_issue_date || '';
        document.getElementById('docLicenseIssuePlace').value = driver.license_issue_place || '';
        document.getElementById('docLicenseExpiry').value = driver.license_expiry || '';

        // Medical fields (بداية ونهاية الفحص فقط)
        const docStart = document.getElementById('docMedStart');
        const docExp = document.getElementById('docMedExpiry');
        if (docStart) docStart.value = driver.medical_exam_start || '';
        if (docExp) docExp.value = driver.medical_cert_expiry || driver.medical_exam_end || '';

        // Taxi contract fields
        document.getElementById('docCardNumber').value = driver.card_number || '';
        document.getElementById('docContractStart').value = driver.taxi_contract_start || '';
        document.getElementById('docContractEnd').value = driver.taxi_contract_end || '';

        // Show Modal
        const modal = document.getElementById('manageDocsModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function closeManageDocsModal() {
        const modal = document.getElementById('manageDocsModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
        document.body.classList.remove('overflow-hidden');
    }

    function renewLicense(years) {
        const input = document.getElementById('docLicenseExpiry');
        if (!input) return;
        let baseDate = new Date();
        if (input.value) {
            const parsed = new Date(input.value);
            if (!isNaN(parsed.getTime()) && parsed > baseDate) {
                baseDate = parsed;
            }
        }
        baseDate.setFullYear(baseDate.getFullYear() + parseInt(years));
        input.value = baseDate.toISOString().split('T')[0];
    }

    function renewMedicalExam() {
        const today = new Date();
        const todayStr = today.toISOString().split('T')[0];

        const end2 = new Date(today);
        end2.setFullYear(end2.getFullYear() + 2);
        end2.setDate(end2.getDate() - 1);
        const end2Str = end2.toISOString().split('T')[0];

        const docStart = document.getElementById('docMedStart');
        const docExp = document.getElementById('docMedExpiry');
        if (docStart) docStart.value = todayStr;
        if (docExp) docExp.value = end2Str;
    }

    function renewTaxiContract(years) {
        const today = new Date();
        const todayStr = today.toISOString().split('T')[0];

        const end = new Date(today);
        end.setFullYear(end.getFullYear() + parseInt(years));
        end.setDate(end.getDate() - 1);
        const endStr = end.toISOString().split('T')[0];

        document.getElementById('docContractStart').value = todayStr;
        document.getElementById('docContractEnd').value = endStr;
    }

    // Toggle Alert Cards Area
    function toggleAlertsSection() {
        const container = document.getElementById('alertCardsContainer');
        const icon = document.getElementById('iconToggleAlerts');
        if (!container) return;
        const isHidden = container.classList.toggle('hidden');
        if (icon) {
            icon.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
        }
        try {
            localStorage.setItem('alerts_section_collapsed', isHidden ? '1' : '0');
        } catch (e) {}
    }

    // Filter Alert Cards Tabs in Alert Area
    function filterAlertCards(tab) {
        const container = document.getElementById('alertCardsContainer');
        const icon = document.getElementById('iconToggleAlerts');
        if (container && container.classList.contains('hidden')) {
            container.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
            try { localStorage.setItem('alerts_section_collapsed', '0'); } catch (e) {}
        }

        const buttons = document.querySelectorAll('.alert-tab-btn');
        buttons.forEach(btn => {
            btn.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-2xs');
            btn.classList.add('text-slate-600', 'dark:text-slate-400');
        });
        const activeBtn = document.getElementById('btnAlertTab-' + tab);
        if (activeBtn) {
            activeBtn.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-2xs');
            activeBtn.classList.remove('text-slate-600', 'dark:text-slate-400');
        }

        const cards = document.querySelectorAll('.alert-card');
        cards.forEach(card => {
            const type = card.getAttribute('data-alert-type');
            if (tab === 'all') {
                card.classList.remove('hidden');
            } else if (tab === 'expired' && type === 'expired') {
                card.classList.remove('hidden');
            } else if (tab === 'soon' && type === 'soon') {
                card.classList.remove('hidden');
            } else if (tab === 'missing' && type === 'missing') {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        });
    }

    // Restore alerts collapsed state on load
    document.addEventListener('DOMContentLoaded', function() {
        try {
            if (localStorage.getItem('alerts_section_collapsed') === '1') {
                const container = document.getElementById('alertCardsContainer');
                const icon = document.getElementById('iconToggleAlerts');
                if (container) container.classList.add('hidden');
                if (icon) icon.style.transform = 'rotate(180deg)';
            }
        } catch (e) {}
    });

    // Keyboard support: Escape closes modal
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('addDriverModal');
            if (modal && !modal.classList.contains('hidden')) {
                closeAddDriverModal();
            }
            const docModal = document.getElementById('manageDocsModal');
            if (docModal && !docModal.classList.contains('hidden')) {
                closeManageDocsModal();
            }
        }
    });

    // Close when clicking modal backdrop
    document.getElementById('addDriverModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeAddDriverModal();
        }
    });
    document.getElementById('manageDocsModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeManageDocsModal();
        }
    });

    // Enter key handler in Add Driver wizard
    document.getElementById('addDriverForm')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            if (currentWizardStep < totalWizardSteps) {
                nextWizardStep();
            } else {
                submitAddDriverForm();
            }
        }
    });

    // Initial probation stage / period selection & auto-advance plate inputs
    window.addEventListener('DOMContentLoaded', function() {
        initDriverPlateInputAutoAdvance();
        const initialVal = document.getElementById('inputActivityPeriod')?.value || '';
        if (initialVal) {
            syncMonthPeriodValues(initialVal);
        } else {
            selectProbationStage('trial_1', false);
        }
    });

    <?php if (!empty($_GET['open_docs_id'])): 
        $reqDocId = intval($_GET['open_docs_id']);
        $autoDocDriver = null;
        foreach ($allDrivers as $cand) {
            if ((int)$cand['id'] === $reqDocId) {
                $autoDocDriver = $cand;
                break;
            }
        }
        if (!$autoDocDriver && $pdo) {
            try {
                $stmtSingleD = $pdo->prepare("SELECT u.*, v.plate_number, v.door_number, v.brand, v.model FROM users u LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id) WHERE u.id = ?");
                $stmtSingleD->execute([$reqDocId]);
                $autoDocDriver = $stmtSingleD->fetch(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}
        }
        if ($autoDocDriver):
    ?>
    // Automatically trigger Manage Documents modal for target driver
    window.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            openManageDocsModal(<?= json_encode($autoDocDriver, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);
        }, 150);
    });
    <?php endif; endif; ?>

    // Client-side lightweight cache for modal popups
    window.DRIVERS_MAP = <?= json_encode($clientDriversMap ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


