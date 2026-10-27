<?php
// dashboard/debts.php - Finances & Dettes: Gestion des créances, versements et recouvrement
$pageTitle = 'Finances & Dettes | إدارة الديون والتحصيل';
require_once __DIR__ . '/../includes/header.php';
require_role(['super_admin', 'admin']);

$pdo = getDBConnection();

// Run debts & versements migration defensively
if ($pdo && function_exists('migrateDebtsAndVersements')) {
    migrateDebtsAndVersements($pdo);
}

// Check column availability defensively
$hasDebtsDeletedAt = false;
$hasVersementsDeletedAt = false;
$hasUsersDeletedAt = false;

if ($pdo) {
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `driver_debts` LIKE 'deleted_at'")->fetch();
        $hasDebtsDeletedAt = !empty($c);
        if (!$hasDebtsDeletedAt) {
            try {
                $pdo->exec("ALTER TABLE `driver_debts` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL, ADD INDEX `idx_debts_deleted_at` (`deleted_at`)");
                $hasDebtsDeletedAt = true;
            } catch (\Throwable $exAlter) {}
        }
    } catch (\Throwable $e) {}

    try {
        $cV = $pdo->query("SHOW COLUMNS FROM `versements` LIKE 'deleted_at'")->fetch();
        $hasVersementsDeletedAt = !empty($cV);
        if (!$hasVersementsDeletedAt) {
            try {
                $pdo->exec("ALTER TABLE `versements` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL, ADD INDEX `idx_vers_deleted_at` (`deleted_at`)");
                $hasVersementsDeletedAt = true;
            } catch (\Throwable $exAlterV) {}
        }
    } catch (\Throwable $e) {}

    try {
        $cU = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'deleted_at'")->fetch();
        $hasUsersDeletedAt = !empty($cU);
    } catch (\Throwable $e) {}
}

// Handle Recalculate Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate_month'])) {
    require_csrf_token();
    try {
        $pdo->beginTransaction();
        $debtsList = $pdo->query("SELECT id, total_credits FROM driver_debts")->fetchAll();
        $recalculatedCount = 0;
        $vRecalcCond = $hasVersementsDeletedAt ? " AND deleted_at IS NULL" : "";
        foreach ($debtsList as $dbItem) {
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_v, MAX(payment_date) as last_v FROM versements WHERE debt_id = ? AND (status = 'approved' OR status IS NULL) {$vRecalcCond}");
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
                INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, 'approved')
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

// Handle Approve BaridiMob Versement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_versement') {
    require_csrf_token();
    $versementId = intval($_POST['versement_id'] ?? 0);
    if ($versementId > 0) {
        try {
            $vDelClause = $hasVersementsDeletedAt ? " AND v.deleted_at IS NULL" : "";
            $stmtV = $pdo->prepare("
                SELECT v.*, d.chauffeur_name, d.user_id, d.total_credits, d.total_versements
                FROM versements v
                JOIN driver_debts d ON v.debt_id = d.id
                WHERE v.id = ? AND v.status = 'pending' {$vDelClause}
            ");
            $stmtV->execute([$versementId]);
            $ver = $stmtV->fetch();

            if ($ver) {
                $pdo->beginTransaction();
                // 1. Mark versement as approved
                $stmtUpV = $pdo->prepare("UPDATE versements SET status = 'approved' WHERE id = ?");
                $stmtUpV->execute([$versementId]);

                // 2. Update driver_debts balance
                $vAmount = (float)$ver['amount'];
                $vDate = $ver['payment_date'] ?: date('Y-m-d');
                $dId = (int)$ver['debt_id'];

                $stmtUpD = $pdo->prepare("
                    UPDATE driver_debts 
                    SET total_versements = total_versements + ?,
                        last_versement_date = ?,
                        status = IF((total_credits - (total_versements + ?)) <= 0, 'a_jour', 'endette')
                    WHERE id = ?
                ");
                $stmtUpD->execute([$vAmount, $vDate, $vAmount, $dId]);

                $pdo->commit();

                // 3. Notify Driver User
                if (!empty($ver['user_id'])) {
                    $notifTitle = "✅ تم تأكيد استلام دفعتك عبر بريدي موب";
                    $notifMsg = "تم اعتماد وتحصيل دفعتك بمبلغ " . number_format($vAmount, 2) . " دج واقتطاعها من رصيد ديونك بنجاح.";
                    $notifLink = "/dashboard/driver_fiche.php?id=" . intval($ver['user_id']);
                    $stmtNotif = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
                        VALUES (?, 'info', ?, ?, ?, 0, NOW())
                    ");
                    $stmtNotif->execute([$ver['user_id'], $notifTitle, $notifMsg, $notifLink]);
                }

                log_audit($pdo, 'versement_approved', 'versements', $versementId, "تأكيد واعتماد دفعة بريدي موب بمبلغ {$vAmount} دج للسائق {$ver['chauffeur_name']}");
                set_flash('success', "تم تأكيد واعتماد دفعة بريدي موب بمبلغ " . number_format($vAmount, 2) . " دج بنجاح وتحديث رصيد السائق.");
            } else {
                set_flash('error', 'الدفعة المطلوبة غير موجودة أو تم البت فيها مسبقاً.');
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء اعتماد الدفعة: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/debts.php');
}

// Handle Reject BaridiMob Versement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_versement') {
    require_csrf_token();
    $versementId = intval($_POST['versement_id'] ?? 0);
    $rejectReason = trim($_POST['reject_reason'] ?? '');
    if (empty($rejectReason)) $rejectReason = 'وصل غير مطابق أو تعذر التحقق من الحساب';

    if ($versementId > 0) {
        try {
            $vDelClause = $hasVersementsDeletedAt ? " AND v.deleted_at IS NULL" : "";
            $stmtV = $pdo->prepare("
                SELECT v.*, d.chauffeur_name, d.user_id
                FROM versements v
                JOIN driver_debts d ON v.debt_id = d.id
                WHERE v.id = ? AND v.status = 'pending' {$vDelClause}
            ");
            $stmtV->execute([$versementId]);
            $ver = $stmtV->fetch();

            if ($ver) {
                $noteAppend = " [تم الرفض: " . $rejectReason . " - " . date('Y-m-d H:i') . "]";
                $stmtUpV = $pdo->prepare("UPDATE versements SET status = 'rejected', notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?");
                $stmtUpV->execute([$noteAppend, $versementId]);

                // Notify Driver User
                if (!empty($ver['user_id'])) {
                    $notifTitle = "❌ تم رفض وصل دفعة بريدي موب";
                    $notifMsg = "تم رفض الوصل المقدم لدفعة " . number_format($ver['amount'], 2) . " دج. السبب: " . $rejectReason . ". يرجى مراجعة الإدارة.";
                    $notifLink = "/dashboard/driver_fiche.php?id=" . intval($ver['user_id']);
                    $stmtNotif = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
                        VALUES (?, 'warning', ?, ?, ?, 0, NOW())
                    ");
                    $stmtNotif->execute([$ver['user_id'], $notifTitle, $notifMsg, $notifLink]);
                }

                log_audit($pdo, 'versement_rejected', 'versements', $versementId, "رفض دفعة بريدي موب للسائق {$ver['chauffeur_name']}: $rejectReason");
                set_flash('info', "تم رفض الدفعة وإرسال إشعار توضيحي للسائق بالسبب.");
            } else {
                set_flash('error', 'الدفعة المطلوبة غير موجودة أو تمت معالجتها.');
            }
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء رفض الدفعة: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/debts.php');
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
                    INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, notes, status)
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

// Handle Edit Debt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_debt') {
    require_csrf_token();
    $debtId = intval($_POST['debt_id'] ?? 0);
    $chauffeurName = trim($_POST['chauffeur_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $totalCredits = floatval($_POST['total_credits'] ?? 0);
    $driverType = in_array($_POST['driver_type'] ?? '', ['actif', 'ancien']) ? $_POST['driver_type'] : 'actif';
    $notes = trim($_POST['notes'] ?? '');

    if ($debtId > 0 && !empty($chauffeurName) && $totalCredits >= 0) {
        try {
            $currDebt = $pdo->query("SELECT total_versements FROM driver_debts WHERE id = $debtId")->fetch();
            $totalVersements = (float)($currDebt['total_versements'] ?? 0);
            $newStatus = ($totalCredits - $totalVersements) <= 0 ? 'a_jour' : 'endette';

            $stmtUpd = $pdo->prepare("
                UPDATE driver_debts 
                SET chauffeur_name = ?, phone = ?, total_credits = ?, driver_type = ?, status = ?, notes = ?
                WHERE id = ?
            ");
            $stmtUpd->execute([$chauffeurName, $phone, $totalCredits, $driverType, $newStatus, $notes, $debtId]);
            log_audit($pdo, 'debt_updated', 'driver_debts', $debtId, "تعديل بيانات القيد المالي للسائق $chauffeurName");
            set_flash('success', "تم تعديل بيانات القيد المالي بنجاح");
        } catch (\Throwable $e) {
            set_flash('error', 'حدث خطأ أثناء تعديل القيد المالي: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'يرجى إدخال بيانات صحيحة');
    }
    redirect('/dashboard/debts.php');
}

// Handle Delete Debt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_debt') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بحذف قيود الديون.');
        redirect('/dashboard/debts.php');
    }
    $debtId = intval($_POST['debt_id'] ?? 0);
    if ($debtId > 0) {
        try {
            $pdo->beginTransaction();
            if ($hasVersementsDeletedAt) {
                $pdo->prepare("UPDATE versements SET deleted_at = NOW() WHERE debt_id = ?")->execute([$debtId]);
            }
            if ($hasDebtsDeletedAt) {
                $pdo->prepare("UPDATE driver_debts SET deleted_at = NOW() WHERE id = ?")->execute([$debtId]);
            } else {
                $pdo->prepare("DELETE FROM driver_debts WHERE id = ?")->execute([$debtId]);
            }
            $pdo->commit();
            log_audit($pdo, 'debt_deleted', 'driver_debts', $debtId, "نقل القيد المالي رقم $debtId وسجل دفعاته إلى سلة المهملات");
            set_flash('success', "تم نقل القيد المالي وسجل دفعاته إلى سلة المهملات والأرشيف بنجاح. يمكنك استرجاعه في أي وقت.");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء حذف القيد: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/debts.php');
}

// Handle Archive Driver Debt (Move to Archive while preserving debt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_driver_debt') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة بأرشفة السائقين.');
        redirect('/dashboard/debts.php');
    }
    $debtId = intval($_POST['debt_id'] ?? 0);
    $archiveReason = trim($_POST['archive_reason'] ?? 'توقف عن العمل مع وجود ديون معلقة');
    $customNotes = trim($_POST['custom_notes'] ?? '');
    if (!empty($customNotes)) {
        $archiveReason .= " - " . $customNotes;
    }

    if ($debtId > 0) {
        try {
            $pdo->beginTransaction();
            $dStmt = $pdo->prepare("SELECT * FROM driver_debts WHERE id = ?");
            $dStmt->execute([$debtId]);
            $debtData = $dStmt->fetch();

            if ($debtData) {
                $chName = $debtData['chauffeur_name'];
                $reste = max(0, (float)$debtData['total_credits'] - (float)$debtData['total_versements']);
                $userId = !empty($debtData['user_id']) ? intval($debtData['user_id']) : 0;

                // 1. Update driver_debts: switch to 'ancien' and append archive note
                $noteAppend = " [أرشيف: " . $archiveReason . " - " . date('Y-m-d') . "]";
                $newNotes = trim(($debtData['notes'] ?? '') . $noteAppend);
                $stmtUpdDebt = $pdo->prepare("UPDATE driver_debts SET driver_type = 'ancien', notes = ? WHERE id = ?");
                $stmtUpdDebt->execute([$newNotes, $debtId]);

                // 2. If user exists in users table, suspend driver and unlink vehicles
                if ($userId > 0) {
                    $pdo->prepare("UPDATE users SET status = 'suspended', activity_status = 'inactive' WHERE id = ?")->execute([$userId]);
                    $pdo->prepare("UPDATE vehicles SET current_driver_id = NULL WHERE current_driver_id = ?")->execute([$userId]);
                    if ($hasUsersDeletedAt) {
                        $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$userId]);
                    }
                }

                // 3. Log audit
                log_audit($pdo, 'driver_debt_archived', 'driver_debts', $debtId, "أرشفة وتجميد السائق $chName مع ترحيل مستحقاته المتبقية بقيمة " . number_format($reste, 2) . " دج إلى أرشيف الديون - السبب: $archiveReason");

                $pdo->commit();
                set_flash('success', "تم نقل السائق ($chName) بنجاح إلى الأرشيف وتجميد حسابه، مع بقاء رصيده المالي (" . number_format($reste, 2) . " دج) محفوظاً في أرشيف الديون للمتابعة.");
            } else {
                set_flash('error', 'سجل السائق المطلوب غير موجود.');
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء أرشفة السائق: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/debts.php');
}

// Handle Restore Driver Debt from Archive to Active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_driver_debt') {
    require_csrf_token();
    if (!is_admin()) {
        set_flash('error', 'عذراً، فقط إدارة المنصة مخولة باسترجاع السائقين.');
        redirect('/dashboard/debts.php');
    }
    $debtId = intval($_POST['debt_id'] ?? 0);
    if ($debtId > 0) {
        try {
            $pdo->beginTransaction();
            $dStmt = $pdo->prepare("SELECT * FROM driver_debts WHERE id = ?");
            $dStmt->execute([$debtId]);
            $debtData = $dStmt->fetch();

            if ($debtData) {
                $chName = $debtData['chauffeur_name'];
                $userId = !empty($debtData['user_id']) ? intval($debtData['user_id']) : 0;

                // 1. Update driver_debts: switch to 'actif'
                $noteAppend = " [استرجاع من الأرشيف: " . date('Y-m-d') . "]";
                $newNotes = trim(($debtData['notes'] ?? '') . $noteAppend);
                $stmtUpdDebt = $pdo->prepare("UPDATE driver_debts SET driver_type = 'actif', notes = ? WHERE id = ?");
                $stmtUpdDebt->execute([$newNotes, $debtId]);

                // 2. Reactivate user if exists
                if ($userId > 0) {
                    $pdo->prepare("UPDATE users SET status = 'active', activity_status = 'active' WHERE id = ?")->execute([$userId]);
                    if ($hasUsersDeletedAt) {
                        $pdo->prepare("UPDATE users SET deleted_at = NULL WHERE id = ?")->execute([$userId]);
                    }
                }

                // 3. Log audit
                log_audit($pdo, 'driver_debt_restored', 'driver_debts', $debtId, "استرجاع السائق $chName من الأرشيف وإعادته إلى قائمة السائقين النشطين");

                $pdo->commit();
                set_flash('success', "تم استرجاع السائق ($chName) بنجاح وإعادته إلى قائمة السائقين النشطين.");
            } else {
                set_flash('error', 'سجل السائق غير موجود.');
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'حدث خطأ أثناء استرجاع السائق: ' . $e->getMessage());
        }
    }
    redirect('/dashboard/debts.php');
}

// Search, Filter and View Tabs
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all, endette, a_jour
$driverType = $_GET['type'] ?? 'all'; // all, actif, ancien
$minDebt = floatval($_GET['min_debt'] ?? 0);
$activeTab = in_array($_GET['tab'] ?? '', ['debts', 'operations']) ? $_GET['tab'] : 'debts';

// Operations Log Filters
$opsSearch = trim($_GET['ops_search'] ?? '');
$opsType = $_GET['ops_type'] ?? 'all'; // all, versement, archive, validation, adjustment
$opsMethod = $_GET['ops_method'] ?? 'all'; // all, especes, baridimob, ccp, virement

$registeredDrivers = [];
$debts = [];
$dettesActifs = 0.0;
$creancesAnciens = 0.0;
$totalDettesAll = 0.0;
$totalVersementsCollected = 0.0;
$countTotal = 0;
$countActifs = 0;
$countAnciens = 0;
$countEndette = 0;
$countActifsEndette = 0;
$countAnciensEndette = 0;
$countAJour = 0;
$pendingVersements = [];
$operationsLog = [];
$totalOpsCount = 0;
$opsTotalPages = 1;
$opsPage = max(1, intval($_GET['ops_page'] ?? 1));
$opsPerPage = 25;

if ($pdo) {
    // 1. Registered drivers for dropdown
    $userDelClause = $hasUsersDeletedAt ? " AND u.deleted_at IS NULL" : "";
    try {
        $stmtD = $pdo->query("
            SELECT u.id, u.full_name, u.phone, MAX(v.door_number) as door_number
            FROM users u 
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id)
            WHERE u.role = 'driver' {$userDelClause}
            GROUP BY u.id
            ORDER BY CASE WHEN MAX(v.door_number) IS NOT NULL AND MAX(v.door_number) != '' THEN 0 ELSE 1 END,
                     CAST(SUBSTRING_INDEX(MAX(v.door_number), '-', -1) AS UNSIGNED) ASC,
                     MAX(v.door_number) ASC,
                     u.id ASC
        ");
        $registeredDrivers = $stmtD ? $stmtD->fetchAll() : [];
    } catch (\Throwable $e) {
        $registeredDrivers = [];
    }

    // 2. Query Debts Table
    $where = ["1=1"];
    if ($hasDebtsDeletedAt) {
        $where[] = "d.deleted_at IS NULL";
    }
    $params = [];

    if (!empty($search)) {
        $where[] = "(d.chauffeur_name LIKE ? OR d.phone LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filter === 'endette') {
        $where[] = "(d.status = 'endette' OR (d.total_credits - d.total_versements) > 0)";
    } elseif ($filter === 'a_jour') {
        $where[] = "(d.status = 'a_jour' OR (d.total_credits - d.total_versements) <= 0)";
    }

    if ($driverType === 'actif') {
        $where[] = "d.driver_type = 'actif'";
    } elseif ($driverType === 'ancien') {
        $where[] = "d.driver_type = 'ancien'";
    }

    if ($minDebt > 0) {
        $where[] = "(d.total_credits - d.total_versements) >= ?";
        $params[] = $minDebt;
    }

    try {
        $sql = "
            SELECT d.*, MAX(v.door_number) as door_number, MAX(v.plate_number) as plate_number
            FROM driver_debts d
            LEFT JOIN users u ON d.user_id = u.id
            LEFT JOIN vehicles v ON (v.current_driver_id = u.id OR v.owner_id = u.id)
            WHERE " . implode(' AND ', $where) . "
            GROUP BY d.id
            ORDER BY 
                CASE WHEN MAX(v.door_number) IS NOT NULL AND MAX(v.door_number) != '' THEN 0 ELSE 1 END,
                CAST(SUBSTRING_INDEX(MAX(v.door_number), '-', -1) AS UNSIGNED) ASC,
                MAX(v.door_number) ASC,
                d.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $debts = $stmt->fetchAll() ?: [];
    } catch (\Throwable $e) {
        error_log("debts.php - Query debts notice: " . $e->getMessage());
        try {
            $debts = $pdo->query("SELECT * FROM driver_debts ORDER BY (total_credits - total_versements) DESC, id ASC")->fetchAll() ?: [];
        } catch (\Throwable $e3) {
            $debts = [];
        }
    }

    // 3. Dynamic Financial KPI Metrics & Precise Counts
    $debtDelCond = $hasDebtsDeletedAt ? " AND deleted_at IS NULL" : "";
    try {
        $countTotal = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE 1=1 {$debtDelCond}")->fetchColumn();
        $countActifs = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE driver_type = 'actif' {$debtDelCond}")->fetchColumn();
        $countAnciens = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE driver_type = 'ancien' {$debtDelCond}")->fetchColumn();
        $countEndette = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE (total_credits - total_versements) > 0 {$debtDelCond}")->fetchColumn();
        $countActifsEndette = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE driver_type = 'actif' AND (total_credits - total_versements) > 0 {$debtDelCond}")->fetchColumn();
        $countAnciensEndette = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE driver_type = 'ancien' AND (total_credits - total_versements) > 0 {$debtDelCond}")->fetchColumn();
        $countAJour = (int)$pdo->query("SELECT COUNT(*) FROM driver_debts WHERE (total_credits - total_versements) <= 0 {$debtDelCond}")->fetchColumn();

        $dettesActifs = (float)$pdo->query("SELECT COALESCE(SUM(total_credits - total_versements), 0) FROM driver_debts WHERE driver_type = 'actif' AND (total_credits - total_versements) > 0 {$debtDelCond}")->fetchColumn();
        $creancesAnciens = (float)$pdo->query("SELECT COALESCE(SUM(total_credits - total_versements), 0) FROM driver_debts WHERE driver_type = 'ancien' AND (total_credits - total_versements) > 0 {$debtDelCond}")->fetchColumn();
        $totalDettesAll = $dettesActifs + $creancesAnciens;

        $vSumDel = $hasVersementsDeletedAt ? " AND deleted_at IS NULL" : "";
        $totalVersementsCollected = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM versements WHERE (status = 'approved' OR status IS NULL) {$vSumDel}")->fetchColumn();
    } catch (\Throwable $e) {
        error_log("debts.php - KPI calculations notice: " . $e->getMessage());
    }

    // 4. Pending BaridiMob Versements
    $pendingVersements = [];
    $vDelCond = $hasVersementsDeletedAt ? " AND v.deleted_at IS NULL" : "";
    try {
        $pStmt = $pdo->query("
            SELECT v.*, d.chauffeur_name, d.phone, d.total_credits, d.total_versements, 
                   (d.total_credits - d.total_versements) AS remaining_debt,
                   u.id AS driver_user_id
            FROM versements v
            JOIN driver_debts d ON v.debt_id = d.id
            LEFT JOIN users u ON d.user_id = u.id
            WHERE v.status = 'pending' {$vDelCond}
            ORDER BY v.created_at DESC
        ");
        $pendingVersements = $pStmt ? $pStmt->fetchAll() : [];
    } catch (\Throwable $e) {
        $pendingVersements = [];
    }

    // 5. Operations Log Unified Dataset (Versements & Audit Trail)
    try {
        $opsBaseSql = "
            SELECT * FROM (
                (SELECT 
                    'versement' AS entry_type,
                    v.id AS id,
                    v.amount AS amount,
                    v.payment_method AS payment_method,
                    v.receipt_ref AS receipt_ref,
                    v.status AS status,
                    v.notes AS details,
                    v.created_at AS created_at,
                    v.payment_date AS payment_date,
                    d.chauffeur_name AS chauffeur_name,
                    d.id AS debt_id,
                    d.user_id AS driver_user_id,
                    'تسجيل دفعة' AS action_label,
                    'إدارة التحصيل' AS performer,
                    v.receipt_image AS receipt_image
                FROM versements v
                JOIN driver_debts d ON v.debt_id = d.id
                WHERE 1=1 {$vDelCond})
                UNION ALL
                (SELECT 
                    'audit' AS entry_type,
                    a.id AS id,
                    NULL AS amount,
                    NULL AS payment_method,
                    NULL AS receipt_ref,
                    NULL AS status,
                    a.details AS details,
                    a.created_at AS created_at,
                    DATE(a.created_at) AS payment_date,
                    COALESCE(d.chauffeur_name, 'النظام / إدارة المنصة') AS chauffeur_name,
                    a.entity_id AS debt_id,
                    d.user_id AS driver_user_id,
                    CASE 
                        WHEN a.action = 'driver_debt_archived' THEN 'أرشفة وتجميد سائق'
                        WHEN a.action = 'driver_debt_restored' THEN 'استرجاع سائق من الأرشيف'
                        WHEN a.action = 'versement_approved' THEN 'اعتماد دفعة بريدي موب'
                        WHEN a.action = 'versement_rejected' THEN 'رفض دفعة بريدي موب'
                        WHEN a.action = 'debt_created' THEN 'قيد دين جديد'
                        WHEN a.action = 'debt_updated' THEN 'تعديل رصيد دين'
                        WHEN a.action = 'debt_deleted' THEN 'حذف قيد مالي'
                        WHEN a.action = 'recalculate_debts' THEN 'تسوية وإعادة احتساب'
                        ELSE a.action
                    END AS action_label,
                    COALESCE(a.user_name, 'المشرف') AS performer,
                    NULL AS receipt_image
                FROM audit_logs a
                LEFT JOIN driver_debts d ON (a.entity = 'driver_debts' AND a.entity_id = d.id)
                WHERE (a.entity IN ('driver_debts', 'versements') 
                   OR a.action IN ('driver_debt_archived', 'driver_debt_restored', 'versement_approved', 'versement_rejected', 'debt_created', 'debt_updated', 'debt_deleted', 'recalculate_debts')))
            ) AS ops
        ";

        $opsFilterWhere = ["1=1"];
        $opsFilterParams = [];

        if (!empty($opsSearch)) {
            $opsFilterWhere[] = "(chauffeur_name LIKE ? OR receipt_ref LIKE ? OR details LIKE ?)";
            $opsFilterParams[] = "%$opsSearch%";
            $opsFilterParams[] = "%$opsSearch%";
            $opsFilterParams[] = "%$opsSearch%";
        }

        if ($opsType === 'versement') {
            $opsFilterWhere[] = "entry_type = 'versement'";
        } elseif ($opsType === 'archive') {
            $opsFilterWhere[] = "(action_label LIKE '%أرشفة%' OR action_label LIKE '%استرجاع%')";
        } elseif ($opsType === 'validation') {
            $opsFilterWhere[] = "(action_label LIKE '%اعتماد%' OR action_label LIKE '%رفض%')";
        } elseif ($opsType === 'adjustment') {
            $opsFilterWhere[] = "(action_label LIKE '%دين%' OR action_label LIKE '%تسوية%')";
        }

        if ($opsMethod !== 'all' && in_array($opsMethod, ['especes', 'baridimob', 'ccp', 'virement'])) {
            $opsFilterWhere[] = "payment_method = ?";
            $opsFilterParams[] = $opsMethod;
        }

        // Count operations matching filter
        $opsCountSql = "SELECT COUNT(*) FROM ($opsBaseSql) AS ops_counted WHERE " . implode(' AND ', $opsFilterWhere);
        $stmtOpsCount = $pdo->prepare($opsCountSql);
        $stmtOpsCount->execute($opsFilterParams);
        $totalOpsCount = (int)$stmtOpsCount->fetchColumn();

        $opsTotalPages = max(1, ceil($totalOpsCount / $opsPerPage));
        $opsOffset = ($opsPage - 1) * $opsPerPage;

        $opsFinalSql = $opsBaseSql . " WHERE " . implode(' AND ', $opsFilterWhere) . " ORDER BY created_at DESC, id DESC LIMIT $opsPerPage OFFSET $opsOffset";
        $stmtOps = $pdo->prepare($opsFinalSql);
        $stmtOps->execute($opsFilterParams);
        $operationsLog = $stmtOps->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log("debts.php - Operations log query notice: " . $e->getMessage());
        $operationsLog = [];
        $totalOpsCount = 0;
    }
}
?>

<div class="space-y-6">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">المالية والديون</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-500/20">
                    متابعة المستحقات والتحصيل
                </span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">إدارة قروض ودفعات السائقين المباشرين، أرشيف المستحقات، وسجل الحركات المحاسبية</p>
        </div>
        
        <div class="flex items-center gap-2 flex-wrap">
            <button onclick="document.getElementById('addDebtModal').classList.remove('hidden'); document.getElementById('addDebtModal').classList.add('flex');"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-amber-500 dark:hover:bg-amber-600 dark:text-slate-950 text-white font-bold text-xs transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4 text-amber-400 dark:text-slate-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>إضافة دين / قرض</span>
            </button>

            <button onclick="document.getElementById('aideModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold text-xs transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                </svg>
                <span>مساعدة</span>
            </button>

            <form method="POST">
                <?= csrf_field() ?>
                <button type="submit" name="recalculate_month" value="1"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-amber-300 hover:border-amber-400 bg-amber-50 hover:bg-amber-100 text-amber-900 font-bold text-xs transition shadow-xs cursor-pointer">
                    <svg class="w-4 h-4 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    <span>تسوية ومطابقة الأرصدة</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Main View Switcher Tabs (Debts Table vs Operations Log) -->
    <div class="flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-3">
        <a href="debts.php?tab=debts<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>&type=<?= $driverType ?>&filter=<?= $filter ?>"
           class="px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition <?= $activeTab === 'debts' ? 'bg-slate-900 text-white shadow-xs dark:bg-amber-500 dark:text-slate-950' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700' ?>">
            <svg class="w-4 h-4 <?= $activeTab === 'debts' ? 'text-amber-400 dark:text-slate-950' : 'text-slate-500' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
            </svg>
            <span>جدول ديون السائقين</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] <?= $activeTab === 'debts' ? 'bg-slate-800 text-amber-300 dark:bg-amber-600 dark:text-slate-950' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200' ?> font-num"><?= $countTotal ?></span>
        </a>

        <a href="debts.php?tab=operations"
           class="px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 transition <?= $activeTab === 'operations' ? 'bg-slate-900 text-white shadow-xs dark:bg-amber-500 dark:text-slate-950' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700' ?>">
            <svg class="w-4 h-4 <?= $activeTab === 'operations' ? 'text-amber-400 dark:text-slate-950' : 'text-slate-500' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>سجل العمليات والحركات المالية</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] <?= $activeTab === 'operations' ? 'bg-slate-800 text-emerald-300 dark:bg-amber-600 dark:text-slate-950' : 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' ?> font-num"><?= $totalOpsCount ?> حركة</span>
        </a>
    </div>

    <?php if ($activeTab === 'debts'): ?>

    <!-- 4 Main Financial KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
        
        <!-- 1. Dettes Chauffeurs Actifs -->
        <a href="debts.php?type=actif&filter=endette" 
           class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border <?= ($driverType === 'actif' && $filter === 'endette') ? 'border-rose-400 ring-2 ring-rose-100 dark:ring-rose-950' : 'border-slate-200/80 dark:border-slate-800' ?> hover:border-rose-300 shadow-xs space-y-2.5 relative overflow-hidden transition block group">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">ديون السائقين النشطين</span>
                <span class="w-8 h-8 rounded-xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold text-sm">
                    🚗
                </span>
            </div>
            <div class="text-2xl font-extrabold text-rose-600 dark:text-rose-400 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($dettesActifs, 2, ',', ' ') ?> <span class="text-xs font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100 dark:border-slate-800">
                <span class="text-slate-700 dark:text-slate-300 font-bold">نشطون مدينون:</span>
                <span class="font-num font-bold text-rose-700 dark:text-rose-300 px-1.5 py-0.5 bg-rose-50 dark:bg-rose-950/60 rounded" dir="ltr"><?= $countActifsEndette ?></span>
                <span class="text-slate-400 mr-auto text-[10px]">من إجمالي <?= $countActifs ?></span>
            </div>
        </a>

        <!-- 2. Créances Anciens Chauffeurs (Archive) -->
        <a href="debts.php?type=ancien&filter=endette" 
           class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border <?= ($driverType === 'ancien' && $filter === 'endette') ? 'border-amber-400 ring-2 ring-amber-100 dark:ring-amber-950' : 'border-slate-200/80 dark:border-slate-800' ?> hover:border-amber-300 shadow-xs space-y-2.5 relative overflow-hidden transition block group">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">ديون الأرشيف والقدامى</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-sm">
                    📦
                </span>
            </div>
            <div class="text-2xl font-extrabold text-amber-600 dark:text-amber-400 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($creancesAnciens, 2, ',', ' ') ?> <span class="text-xs font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100 dark:border-slate-800">
                <span class="text-slate-700 dark:text-slate-300 font-bold">أرشيف مدينون:</span>
                <span class="font-num font-bold text-amber-700 dark:text-amber-300 px-1.5 py-0.5 bg-amber-50 dark:bg-amber-950/60 rounded" dir="ltr"><?= $countAnciensEndette ?></span>
                <span class="text-slate-400 mr-auto text-[10px]">سائقون متوقفون</span>
            </div>
        </a>

        <!-- 3. Total Dettes Endettés -->
        <a href="debts.php?filter=endette" 
           class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border <?= ($filter === 'endette' && $driverType === 'all') ? 'border-purple-400 ring-2 ring-purple-100 dark:ring-purple-950' : 'border-slate-200/80 dark:border-slate-800' ?> hover:border-purple-300 shadow-xs space-y-2.5 relative overflow-hidden transition block group">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">إجمالي المبالغ المستحقة</span>
                <span class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold text-sm">
                    📊
                </span>
            </div>
            <div class="text-2xl font-extrabold text-purple-600 dark:text-purple-400 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($totalDettesAll, 2, ',', ' ') ?> <span class="text-xs font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100 dark:border-slate-800">
                <span class="text-slate-700 dark:text-slate-300 font-bold">إجمالي المدينين:</span>
                <span class="font-num font-bold text-purple-700 dark:text-purple-300 px-1.5 py-0.5 bg-purple-50 dark:bg-purple-950/60 rounded" dir="ltr"><?= $countEndette ?></span>
                <span class="text-slate-400 mr-auto text-[10px]">مستحقات معلقة</span>
            </div>
        </a>

        <!-- 4. Total Versements Collected -->
        <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-2.5 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">إجمالي التحصيلات والدفعات</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold text-sm">
                    💵
                </span>
            </div>
            <div class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 font-num">
                <span class="inline-block" dir="ltr"><?= number_format($totalVersementsCollected, 2, ',', ' ') ?> <span class="text-xs font-bold text-slate-500">DZD</span></span>
            </div>
            <div class="text-[11px] text-slate-400 font-medium flex items-center gap-1.5 pt-2 border-t border-slate-100 dark:border-slate-800">
                <span class="text-slate-700 dark:text-slate-300 font-bold">سائقون خالصون:</span>
                <span class="font-num font-bold text-emerald-700 dark:text-emerald-300 px-1.5 py-0.5 bg-emerald-50 dark:bg-emerald-950/60 rounded" dir="ltr"><?= $countAJour ?></span>
                <span class="text-slate-400 mr-auto text-[10px]">مسددون بالكامل</span>
            </div>
        </div>

    </div>

    <!-- Pending BaridiMob Payments Section -->
    <?php if (!empty($pendingVersements)): ?>
    <div class="bg-gradient-to-l from-amber-500/10 via-amber-500/5 to-transparent border-2 border-amber-400/40 rounded-2xl p-5 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-black shadow-sm shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-black text-slate-900 dark:text-white">طلبات تسديد عبر بريدي موب بانتظار التأكيد والاعتماد</h2>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-amber-500 text-slate-950">
                            <?= count($pendingVersements) ?> طلب جديد
                        </span>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">قام السائقون برفع وصولات دفع بريدي موب. يرجى مراجعة صورة الوصل وتأكيد استلام المبلغ في الحساب أو الرفض.</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 pt-1">
            <?php foreach ($pendingVersements as $pv): ?>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-amber-200/80 dark:border-amber-900/40 p-4 shadow-2xs space-y-3 relative overflow-hidden flex flex-col justify-between">
                <div class="space-y-2">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-bold text-slate-900 dark:text-white text-sm flex items-center gap-1.5">
                                <span><?= htmlspecialchars($pv['chauffeur_name']) ?></span>
                                <?php if ($pv['driver_user_id']): ?>
                                    <a href="driver_fiche.php?id=<?= $pv['driver_user_id'] ?>" target="_blank" class="text-[10px] text-amber-700 hover:underline font-semibold bg-amber-50 dark:bg-amber-950/60 px-1.5 py-0.5 rounded">الملف ↗</a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($pv['phone'])): ?>
                                <div class="text-[11px] text-slate-500 font-num" dir="ltr">📞 <?= htmlspecialchars($pv['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300 border border-amber-200 dark:border-amber-800 shrink-0">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            قيد المراجعة
                        </span>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-2.5 space-y-1.5 text-xs border border-slate-100 dark:border-slate-700/60">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500 dark:text-slate-400">المبلغ المحوّل:</span>
                            <span class="font-black text-emerald-700 dark:text-emerald-400 font-num text-sm" dir="ltr"><?= number_format($pv['amount'], 2, ',', ' ') ?> DZD</span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-slate-500 dark:text-slate-400">تاريخ العملية:</span>
                            <span class="font-bold text-slate-700 dark:text-slate-200 font-num"><?= htmlspecialchars($pv['payment_date']) ?></span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-slate-500 dark:text-slate-400">رقم المعاملة:</span>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200" dir="ltr"><?= htmlspecialchars($pv['receipt_ref']) ?></span>
                        </div>
                        <?php if (!empty($pv['notes'])): ?>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 pt-1 border-t border-slate-200/60 dark:border-slate-800 truncate" title="<?= htmlspecialchars($pv['notes']) ?>">
                            <span class="font-medium">ملاحظة:</span> <?= htmlspecialchars($pv['notes']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                    <?php if (!empty($pv['receipt_image'])): ?>
                    <button type="button" 
                            onclick="openReceiptPreviewModal('<?= htmlspecialchars($pv['receipt_image']) ?>', '<?= htmlspecialchars($pv['chauffeur_name'], ENT_QUOTES) ?>', '<?= number_format($pv['amount'], 2) ?> DZD', '<?= htmlspecialchars($pv['receipt_ref'], ENT_QUOTES) ?>')"
                            class="flex-1 py-2 px-2.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:border-amber-400 bg-white dark:bg-slate-700 hover:bg-amber-50/50 text-slate-800 dark:text-slate-100 font-bold text-xs flex items-center justify-center gap-1.5 transition">
                        <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                        </svg>
                        <span>معاينة الوصل</span>
                    </button>
                    <?php endif; ?>

                    <!-- Approve Form -->
                    <form method="POST" action="" onsubmit="return confirm('تأكيد استلام دفعة بريدي موب بمبلغ <?= number_format($pv['amount'], 2) ?> دج واعتمادها في حساب السائق؟')" class="<?= !empty($pv['receipt_image']) ? 'flex-1' : 'w-full' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve_versement">
                        <input type="hidden" name="versement_id" value="<?= $pv['id'] ?>">
                        <button type="submit" class="w-full py-2 px-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs flex items-center justify-center gap-1 transition shadow-xs cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                            <span>تأكيد واستلام</span>
                        </button>
                    </form>

                    <!-- Reject Button -->
                    <button type="button" 
                            onclick="openRejectModal(<?= $pv['id'] ?>, '<?= htmlspecialchars($pv['chauffeur_name'], ENT_QUOTES) ?>', '<?= number_format($pv['amount'], 2) ?> DZD')"
                            class="p-2 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 transition cursor-pointer"
                            title="رفض الوصل">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Comprehensive Filter Toolbar (مع تصفية الديون الصريحة) -->
    <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs space-y-3">
        
        <!-- Quick Debt Filter Chips -->
        <div class="flex items-center gap-1.5 flex-wrap text-xs pb-3 border-b border-slate-100 dark:border-slate-800">
            <span class="text-slate-400 font-bold ml-1 text-[11px]">فلاتر الديون السريعة:</span>

            <!-- All -->
            <a href="debts.php?type=all&filter=all" 
               class="px-3 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 <?= ($driverType === 'all' && $filter === 'all' && $minDebt <= 0) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-950 shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200' ?>">
                <span>الكل</span>
                <span class="font-num text-[10px] px-1.5 py-0.2 bg-black/10 dark:bg-white/20 rounded-full"><?= $countTotal ?></span>
            </a>

            <!-- Only Endettés -->
            <a href="debts.php?type=all&filter=endette" 
               class="px-3 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 <?= ($filter === 'endette' && $driverType === 'all') ? 'bg-rose-600 text-white shadow-2xs' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 hover:bg-rose-100 border border-rose-200 dark:border-rose-900/60' ?>">
                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                <span>المدينون فقط (عليهم مستحقات)</span>
                <span class="font-num text-[10px] px-1.5 py-0.2 bg-black/10 rounded-full"><?= $countEndette ?></span>
            </a>

            <!-- Actifs Endettés -->
            <a href="debts.php?type=actif&filter=endette" 
               class="px-3 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 <?= ($filter === 'endette' && $driverType === 'actif') ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200' ?>">
                <span>🚗 ديون السائقين النشطين</span>
                <span class="font-num text-[10px] px-1.5 py-0.2 bg-black/10 rounded-full"><?= $countActifsEndette ?></span>
            </a>

            <!-- Anciens Endettés (Archive with Debts) -->
            <a href="debts.php?type=ancien&filter=endette" 
               class="px-3 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 <?= ($filter === 'endette' && $driverType === 'ancien') ? 'bg-amber-600 text-white shadow-2xs' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 hover:bg-amber-100 border border-amber-200 dark:border-amber-900/60' ?>">
                <span>📦 ديون الأرشيف (سابقون)</span>
                <span class="font-num text-[10px] px-1.5 py-0.2 bg-black/10 rounded-full"><?= $countAnciensEndette ?></span>
            </a>

            <!-- A Jour (Settled) -->
            <a href="debts.php?type=all&filter=a_jour" 
               class="px-3 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 <?= ($filter === 'a_jour') ? 'bg-emerald-600 text-white shadow-2xs' : 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 border border-emerald-200 dark:border-emerald-900/60' ?>">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>المسددون بالكامل (خالصين)</span>
                <span class="font-num text-[10px] px-1.5 py-0.2 bg-black/10 rounded-full"><?= $countAJour ?></span>
            </a>

            <!-- Full Archive -->
            <a href="debts.php?type=ancien&filter=all" 
               class="px-3 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 <?= ($driverType === 'ancien' && $filter === 'all') ? 'bg-amber-700 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200' ?>">
                <span>🗄️ الأرشيف بالكامل</span>
                <span class="font-num text-[10px] px-1.5 py-0.2 bg-black/10 rounded-full"><?= $countAnciens ?></span>
            </a>
        </div>

        <!-- Form Filters (Search, Driver Category, Financial Status, Min Debt) -->
        <form method="GET" class="flex flex-col md:flex-row items-center justify-between gap-3 text-xs">
            <input type="hidden" name="tab" value="debts">

            <!-- Search Field -->
            <div class="relative flex-1 w-full sm:max-w-md">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="البحث بالاسم الكامل أو رقم الهاتف..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>

            <!-- Select Filters Grid -->
            <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                
                <!-- Driver Category Select -->
                <select name="type" class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20">
                    <option value="all" <?= $driverType === 'all' ? 'selected' : '' ?>>كل الفئات (نشط وأرشيف)</option>
                    <option value="actif" <?= $driverType === 'actif' ? 'selected' : '' ?>>السائقون النشطون فقط (<?= $countActifs ?>)</option>
                    <option value="ancien" <?= $driverType === 'ancien' ? 'selected' : '' ?>>الأرشيف والسابقون فقط (<?= $countAnciens ?>)</option>
                </select>

                <!-- Financial Status Select -->
                <select name="filter" class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>الحالة: كل الحسابات</option>
                    <option value="endette" <?= $filter === 'endette' ? 'selected' : '' ?>>الحالة: مدين (مستحقات معلقة)</option>
                    <option value="a_jour" <?= $filter === 'a_jour' ? 'selected' : '' ?>>الحالة: مسدد (خالص بالكامل)</option>
                </select>

                <!-- Min Debt Select -->
                <select name="min_debt" class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20">
                    <option value="0" <?= $minDebt <= 0 ? 'selected' : '' ?>>قيمة الدين: الكل</option>
                    <option value="10000" <?= $minDebt == 10000 ? 'selected' : '' ?>>دين أكثر من &lrm;10,000&lrm; دج</option>
                    <option value="25000" <?= $minDebt == 25000 ? 'selected' : '' ?>>دين أكثر من &lrm;25,000&lrm; دج</option>
                    <option value="50000" <?= $minDebt == 50000 ? 'selected' : '' ?>>دين أكثر من &lrm;50,000&lrm; دج</option>
                </select>

                <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-amber-500 dark:hover:bg-amber-600 dark:text-slate-950 text-white font-bold transition shadow-xs cursor-pointer">
                    تطبيق
                </button>

                <?php if (!empty($search) || $filter !== 'all' || $driverType !== 'all' || $minDebt > 0): ?>
                    <a href="debts.php?tab=debts" class="px-3 py-2 rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100 font-bold transition">
                        ✕ مسح الفلاتر
                    </a>
                <?php endif; ?>
            </div>
        </form>

    </div>

    <!-- Debts Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/90 dark:border-slate-700/80 shadow-2xs whitespace-nowrap">
                    <tr>
                        <th class="p-3.5 text-center w-12 font-num">رقم</th>
                        <th class="p-3.5">السائق وتصنيفه</th>
                        <th class="p-3.5 text-center font-num">إجمالي القروض</th>
                        <th class="p-3.5 text-center font-num">إجمالي الدفعات</th>
                        <th class="p-3.5 text-center font-num">الرصيد المتبقي</th>
                        <th class="p-3.5 text-center">الحالة المالية</th>
                        <th class="p-3.5 text-center">إجراءات الحساب</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                    <?php if (!empty($debts)): ?>
                        <?php foreach ($debts as $idx => $d): 
                            $reste = max(0, (float)$d['total_credits'] - (float)$d['total_versements']);
                            $isEndette = ($reste > 0);
                            $isAncien = ($d['driver_type'] === 'ancien');
                        ?>
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition <?= $isAncien ? 'bg-amber-50/20 dark:bg-amber-950/10' : '' ?>">
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-slate text-[11px]">#<?= $idx + 1 ?></span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 dark:text-slate-100 text-[13px] flex items-center gap-1.5">
                                        <span><?= htmlspecialchars($d['chauffeur_name']) ?></span>
                                        <?php if (!empty($d['user_id'])): ?>
                                            <a href="driver_fiche.php?id=<?= $d['user_id'] ?>" target="_blank" class="text-[10px] text-amber-700 hover:underline font-semibold bg-amber-50 dark:bg-amber-950/60 px-1 rounded">الملف ↗</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1 flex items-center gap-1.5 flex-wrap">
                                        <?php if (!empty($d['door_number'])): ?>
                                            <span class="table-num-badge bg-amber-500/15 text-amber-800 dark:text-amber-300 font-black text-[10px] border border-amber-500/30">🚪 <?= htmlspecialchars($d['door_number']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($d['phone'])): ?>
                                            <span class="table-num-badge table-num-slate text-[10px]" dir="ltr">📞 <?= htmlspecialchars($d['phone']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($isAncien): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                                📦 سابق / في الأرشيف
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                🚗 سائق نشط
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-amber text-xs font-bold" dir="ltr">
                                        <span class="table-num-dot-amber"></span>
                                        <?= number_format($d['total_credits'], 2, ',', ' ') ?> DZD
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-emerald text-xs font-bold" dir="ltr">
                                        <span class="table-num-dot-emerald"></span>
                                        <?= number_format($d['total_versements'], 2, ',', ' ') ?> DZD
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($isEndette): ?>
                                        <span class="table-num-badge table-num-rose text-xs font-bold" dir="ltr">
                                            <span class="table-num-dot-rose"></span>
                                            <?= number_format($reste, 2, ',', ' ') ?> DZD
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-emerald text-xs font-bold" dir="ltr">
                                            <span class="table-num-dot-emerald"></span>
                                            0.00 DZD
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($isEndette): ?>
                                        <span class="table-num-badge table-num-rose text-xs font-bold">
                                            <span class="table-num-dot-rose"></span>
                                            مدين
                                        </span>
                                    <?php else: ?>
                                        <span class="table-num-badge table-num-emerald text-xs font-bold">
                                            <span class="table-num-dot-emerald"></span>
                                            مسدد
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="relative inline-block text-right">
                                        <button type="button"
                                                onclick="toggleTableDropdown(event, this)"
                                                class="table-dropdown-btn w-9 h-9 rounded-xl bg-slate-100 hover:bg-amber-500/15 hover:text-amber-700 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 shadow-2xs cursor-pointer"
                                                title="خيارات وإجراءات الحساب المالي">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                            </svg>
                                        </button>

                                        <div class="table-dropdown-menu hidden w-64 min-w-[250px] rounded-2xl bg-white/95 dark:bg-[#161d27]/95 backdrop-blur-md border border-slate-200 dark:border-slate-800 shadow-2xl p-1.5 space-y-0.5 text-xs text-right select-none">
                                            
                                            <!-- Add Versement Button -->
                                            <button type="button"
                                                    onclick="openVersementModal(<?= $d['id'] ?>, '<?= htmlspecialchars($d['chauffeur_name'], ENT_QUOTES) ?>', <?= $reste ?>)"
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-amber-50 dark:hover:bg-amber-900/30 hover:text-amber-800 dark:hover:text-amber-300 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                                </svg>
                                                <span>تسجيل دفعة جديدة (Versement)</span>
                                            </button>

                                            <!-- Statement PDF Link -->
                                            <a href="debt_statement.php?id=<?= $d['id'] ?>" target="_blank"
                                               class="table-dropdown-item flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold transition">
                                                <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                                <span>كشف الحساب المالي (PDF)</span>
                                            </a>

                                            <!-- Edit Debt Modal -->
                                            <button type="button"
                                                    onclick='openEditDebtModal(<?= json_encode([
                                                        "id" => (int)$d["id"],
                                                        "chauffeur_name" => $d["chauffeur_name"],
                                                        "phone" => $d["phone"],
                                                        "total_credits" => (float)$d["total_credits"],
                                                        "driver_type" => $d["driver_type"],
                                                        "notes" => $d["notes"] ?? ""
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                    class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-slate-800 dark:text-slate-100 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 font-bold transition text-right cursor-pointer">
                                                <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                </svg>
                                                <span>تعديل الرصيد والملاحظات</span>
                                            </button>

                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>

                                            <!-- Archive / Restore Driver Action Button -->
                                            <?php if (!$isAncien): ?>
                                                <!-- Archive Driver Action (if active) -->
                                                <button type="button"
                                                        onclick="openArchiveDriverModal(<?= $d['id'] ?>, '<?= htmlspecialchars($d['chauffeur_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($d['phone'] ?? '', ENT_QUOTES) ?>', <?= $reste ?>)"
                                                        class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/40 font-bold transition text-right cursor-pointer"
                                                        title="نقل السائق للأرشيف مع حفظ الديون">
                                                    <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                                    </svg>
                                                    <span>أرشفة السائق (توقف عن العمل)</span>
                                                </button>
                                            <?php else: ?>
                                                <!-- Restore Driver Action (if archived) -->
                                                <form method="POST" action="" onsubmit="return confirm('هل تريد استرجاع السائق (<?= htmlspecialchars($d['chauffeur_name'], ENT_QUOTES) ?>) وإعادته إلى قائمة السائقين النشطين؟')" class="w-full">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="restore_driver_debt">
                                                    <input type="hidden" name="debt_id" value="<?= $d['id'] ?>">
                                                    <button type="submit"
                                                            class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 font-bold transition text-right cursor-pointer">
                                                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                        </svg>
                                                        <span>استرجاع السائق للنشطين 🔄</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (is_admin()): ?>
                                            <div class="border-t border-slate-100 dark:border-slate-800 my-1"></div>
                                            <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا القيد المالي وجميع دفعاته نهائياً؟')" class="w-full">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_debt">
                                                <input type="hidden" name="debt_id" value="<?= $d['id'] ?>">
                                                <button type="submit"
                                                        class="table-dropdown-item w-full flex items-center gap-2 px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-bold transition text-right cursor-pointer">
                                                    <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    <span>حذف القيد المالي</span>
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
                            <td colspan="7" class="p-10 text-center text-slate-400 text-xs">
                                لا يوجد سائقون مطابقون لمعايير البحث الحالية.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Operations Log Quick Preview Banner -->
    <div class="bg-white dark:bg-[#161d27] rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 flex items-center justify-center font-bold text-sm">
                    📜
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">سجل العمليات المالية والتحصيل (آخر الحركات)</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">تحديث فوري لجميع الدفعات، الاعتمادات، وأرشفة السائقين</p>
                </div>
            </div>
            <a href="debts.php?tab=operations" 
               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-amber-500 dark:hover:bg-amber-600 dark:text-slate-950 text-white font-bold text-xs transition">
                <span>عرض سجل العمليات الكامل والتفصيلي (<?= $totalOpsCount ?> حركة) ↗</span>
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-600 dark:text-slate-300 font-bold border-b border-slate-100 dark:border-slate-800 text-[11px]">
                    <tr>
                        <th class="p-2.5">التاريخ والوقت</th>
                        <th class="p-2.5">نوع العملية</th>
                        <th class="p-2.5">السائق</th>
                        <th class="p-2.5 text-center">المبلغ</th>
                        <th class="p-2.5">طريقة الدفع / المرجع</th>
                        <th class="p-2.5">البيان والملاحظات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-[11px]">
                    <?php 
                    $previewOps = array_slice($operationsLog, 0, 5);
                    if (!empty($previewOps)):
                        foreach ($previewOps as $pop): 
                    ?>
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                            <td class="p-2.5 whitespace-nowrap font-num text-slate-500 dark:text-slate-400">
                                <?= date('Y-m-d H:i', strtotime($pop['created_at'])) ?>
                            </td>
                            <td class="p-2.5 whitespace-nowrap">
                                <?php if ($pop['entry_type'] === 'versement'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-900">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        تسجيل دفعة
                                    </span>
                                <?php elseif (strpos($pop['action_label'], 'أرشفة') !== false): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-900">
                                        📦 أرشفة وتجميد
                                    </span>
                                <?php elseif (strpos($pop['action_label'], 'استرجاع') !== false): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-50 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-900">
                                        🔄 استرجاع للنشطين
                                    </span>
                                <?php elseif (strpos($pop['action_label'], 'اعتماد') !== false): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-900">
                                        ✅ اعتماد بريدي موب
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-700">
                                        <?= htmlspecialchars($pop['action_label']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2.5 whitespace-nowrap font-bold text-slate-900 dark:text-white">
                                <?= htmlspecialchars($pop['chauffeur_name']) ?>
                            </td>
                            <td class="p-2.5 whitespace-nowrap text-center font-num font-bold">
                                <?php if ($pop['amount'] !== null && (float)$pop['amount'] > 0): ?>
                                    <span class="text-emerald-700 dark:text-emerald-400" dir="ltr">+<?= number_format((float)$pop['amount'], 2, ',', ' ') ?> DZD</span>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2.5 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                <?= htmlspecialchars($pop['receipt_ref'] ?? '-') ?>
                            </td>
                            <td class="p-2.5 text-slate-600 dark:text-slate-400 max-w-xs truncate" title="<?= htmlspecialchars($pop['details'] ?? '') ?>">
                                <?= htmlspecialchars($pop['details'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php 
                        endforeach;
                    else: 
                    ?>
                        <tr>
                            <td colspan="6" class="p-4 text-center text-slate-400">لا توجد عمليات مسجلة حتى الآن.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: /* Operations Log Full Tab */ ?>

    <!-- Operations View: 4 Summary Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">إجمالي الحركات المسجلة</span>
                <span class="w-8 h-8 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-sm">
                    📜
                </span>
            </div>
            <div class="text-2xl font-black text-slate-900 dark:text-white font-num"><?= $totalOpsCount ?></div>
            <p class="text-[11px] text-slate-400 pt-1 border-t border-slate-100 dark:border-slate-800">كل الدفعات والعمليات المحاسبية</p>
        </div>

        <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">إجمالي المبالغ المحصلة</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold text-sm">
                    💵
                </span>
            </div>
            <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-num" dir="ltr"><?= number_format($totalVersementsCollected, 2, ',', ' ') ?> <span class="text-xs font-bold text-slate-500">DZD</span></div>
            <p class="text-[11px] text-slate-400 pt-1 border-t border-slate-100 dark:border-slate-800">دفعات نقدية وبنكية معتمدة</p>
        </div>

        <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">طلبات بريدي موب المعلقة</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-sm">
                    📱
                </span>
            </div>
            <div class="text-2xl font-black text-amber-600 dark:text-amber-400 font-num"><?= count($pendingVersements) ?></div>
            <p class="text-[11px] text-slate-400 pt-1 border-t border-slate-100 dark:border-slate-800">بانتظار المراجعة والاعتماد</p>
        </div>

        <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">سائقون في أرشيف الديون</span>
                <span class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold text-sm">
                    📦
                </span>
            </div>
            <div class="text-2xl font-black text-purple-600 dark:text-purple-400 font-num"><?= $countAnciens ?></div>
            <p class="text-[11px] text-slate-400 pt-1 border-t border-slate-100 dark:border-slate-800">مستحقات معلقة للمتابعة</p>
        </div>
    </div>

    <!-- Operations Filter Toolbar -->
    <div class="bg-white dark:bg-[#161d27] p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs space-y-3">
        <form method="GET" class="flex flex-col md:flex-row items-center justify-between gap-3 text-xs">
            <input type="hidden" name="tab" value="operations">

            <!-- Search Field -->
            <div class="relative flex-1 w-full sm:max-w-md">
                <input type="text" name="ops_search" value="<?= htmlspecialchars($opsSearch) ?>" 
                       placeholder="البحث باسم السائق، رقم الوصل، أو الملاحظة..."
                       class="w-full pr-9 pl-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>

            <!-- Operations Type & Payment Method Filters -->
            <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                <select name="ops_type" class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20">
                    <option value="all" <?= $opsType === 'all' ? 'selected' : '' ?>>جميع أنواع العمليات</option>
                    <option value="versement" <?= $opsType === 'versement' ? 'selected' : '' ?>>دفعات وتحصيلات فقط (Versements)</option>
                    <option value="validation" <?= $opsType === 'validation' ? 'selected' : '' ?>>اعتمادات ورفض بريدي موب</option>
                    <option value="archive" <?= $opsType === 'archive' ? 'selected' : '' ?>>أرشفة واسترجاع السائقين</option>
                    <option value="adjustment" <?= $opsType === 'adjustment' ? 'selected' : '' ?>>قيود وتسويات محاسبية</option>
                </select>

                <select name="ops_method" class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20">
                    <option value="all" <?= $opsMethod === 'all' ? 'selected' : '' ?>>كل طرق الدفع</option>
                    <option value="especes" <?= $opsMethod === 'especes' ? 'selected' : '' ?>>💵 نقداً (Espèces)</option>
                    <option value="baridimob" <?= $opsMethod === 'baridimob' ? 'selected' : '' ?>>📱 بريدي موب (BaridiMob)</option>
                    <option value="ccp" <?= $opsMethod === 'ccp' ? 'selected' : '' ?>>📮 حوالة بريدية CCP</option>
                    <option value="virement" <?= $opsMethod === 'virement' ? 'selected' : '' ?>>🏦 تحويل بنكي</option>
                </select>

                <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-amber-500 dark:hover:bg-amber-600 dark:text-slate-950 text-white font-bold transition shadow-xs cursor-pointer">
                    تصفية
                </button>

                <?php if (!empty($opsSearch) || $opsType !== 'all' || $opsMethod !== 'all'): ?>
                    <a href="debts.php?tab=operations" class="px-3 py-2 rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100 font-bold transition">
                        ✕ إلغاء التصفية
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Operations Log Full Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <div class="table-sticky-container overflow-x-auto">
            <table class="table-sticky w-full text-right text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold border-b border-slate-200/90 dark:border-slate-700/80 shadow-2xs whitespace-nowrap">
                    <tr>
                        <th class="p-3.5 text-center w-12 font-num">رقم</th>
                        <th class="p-3.5">التاريخ والوقت</th>
                        <th class="p-3.5">نوع العملية</th>
                        <th class="p-3.5">السائق المعني</th>
                        <th class="p-3.5 text-center font-num">المبلغ</th>
                        <th class="p-3.5">طريقة الدفع / رقم الوصل</th>
                        <th class="p-3.5">القائم بالعملية</th>
                        <th class="p-3.5">البيان والملاحظات</th>
                        <th class="p-3.5 text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                    <?php if (!empty($operationsLog)): ?>
                        <?php foreach ($operationsLog as $idx => $op): 
                            $rowNum = $opsOffset + $idx + 1;
                        ?>
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <span class="table-num-badge table-num-slate text-[11px]">#<?= $rowNum ?></span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-num text-slate-600 dark:text-slate-300">
                                    <div class="font-bold"><?= date('Y-m-d', strtotime($pop['payment_date'] ?? $op['created_at'])) ?></div>
                                    <div class="text-[10px] text-slate-400"><?= date('H:i', strtotime($op['created_at'])) ?></div>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if ($op['entry_type'] === 'versement'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            تسجيل دفعة
                                        </span>
                                    <?php elseif (strpos($op['action_label'], 'أرشفة') !== false): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                            📦 أرشفة وتجميد
                                        </span>
                                    <?php elseif (strpos($op['action_label'], 'استرجاع') !== false): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-cyan-50 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800">
                                            🔄 استرجاع للنشطين
                                        </span>
                                    <?php elseif (strpos($op['action_label'], 'اعتماد') !== false): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                            ✅ اعتماد بريدي موب
                                        </span>
                                    <?php elseif (strpos($op['action_label'], 'رفض') !== false): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                                            ❌ رفض وصل دفع
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-purple-50 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800">
                                            <?= htmlspecialchars($op['action_label']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900 dark:text-white text-[13px]"><?= htmlspecialchars($op['chauffeur_name']) ?></div>
                                    <?php if (!empty($op['debt_id'])): ?>
                                        <a href="debt_statement.php?id=<?= $op['debt_id'] ?>" target="_blank" class="text-[10px] text-amber-700 hover:underline font-semibold">كشف الحساب ↗</a>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <?php if ($op['amount'] !== null && (float)$op['amount'] > 0): ?>
                                        <span class="table-num-badge table-num-emerald text-xs font-bold" dir="ltr">
                                            <span class="table-num-dot-emerald"></span>
                                            +<?= number_format((float)$op['amount'], 2, ',', ' ') ?> DZD
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($op['payment_method'])): ?>
                                        <div class="flex items-center gap-1">
                                            <?php 
                                            $methodIcons = [
                                                'especes' => '💵 نقداً',
                                                'baridimob' => '📱 بريدي موب',
                                                'ccp' => '📮 حوالة CCP',
                                                'virement' => '🏦 تحويل بنكي'
                                            ];
                                            echo '<span class="font-bold text-slate-700 dark:text-slate-300 text-[11px]">' . ($methodIcons[$op['payment_method']] ?? $op['payment_method']) . '</span>';
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($op['receipt_ref'])): ?>
                                        <div class="font-mono text-[10px] text-slate-500 font-bold mt-0.5" dir="ltr"><?= htmlspecialchars($op['receipt_ref']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-600 dark:text-slate-400 text-xs">
                                    <div class="font-bold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($op['performer']) ?></div>
                                </td>
                                <td class="p-3.5 text-slate-600 dark:text-slate-300 max-w-sm">
                                    <div class="line-clamp-2" title="<?= htmlspecialchars($op['details'] ?? '') ?>">
                                        <?= htmlspecialchars($op['details'] ?? '-') ?>
                                    </div>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if (!empty($op['receipt_image'])): ?>
                                            <button type="button" 
                                                    onclick="openReceiptPreviewModal('<?= htmlspecialchars($op['receipt_image']) ?>', '<?= htmlspecialchars($op['chauffeur_name'], ENT_QUOTES) ?>', '<?= number_format((float)$op['amount'], 2) ?> DZD', '<?= htmlspecialchars($op['receipt_ref'] ?? '', ENT_QUOTES) ?>')"
                                                    class="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:border-amber-400 bg-white dark:bg-slate-800 text-amber-700 dark:text-amber-400 transition"
                                                    title="معاينة الوصل">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                                                </svg>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($op['debt_id'])): ?>
                                            <a href="debt_statement.php?id=<?= $op['debt_id'] ?>" target="_blank"
                                               class="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:border-emerald-400 bg-white dark:bg-slate-800 text-emerald-700 dark:text-emerald-400 transition"
                                               title="طباعة كشف الحساب">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" />
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="p-10 text-center text-slate-400 text-xs">
                                لا توجد حركات أو عمليات مطابقة لمعايير البحث الحالية.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Operations Pagination Controls -->
        <?php if ($opsTotalPages > 1): ?>
        <div class="p-4 bg-slate-50 dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <span class="text-slate-500 dark:text-slate-400">
                عرض صفحة <strong class="text-slate-900 dark:text-white"><?= $opsPage ?></strong> من إجمالي <strong class="text-slate-900 dark:text-white"><?= $opsTotalPages ?></strong> صفحة (<?= $totalOpsCount ?> حركة)
            </span>
            <div class="flex items-center gap-1">
                <?php if ($opsPage > 1): ?>
                    <a href="debts.php?tab=operations&ops_page=<?= $opsPage - 1 ?><?= !empty($opsSearch) ? '&ops_search=' . urlencode($opsSearch) : '' ?>&ops_type=<?= $opsType ?>&ops_method=<?= $opsMethod ?>"
                       class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-700 font-bold hover:bg-slate-100 transition">
                        السابق
                    </a>
                <?php endif; ?>

                <?php for ($p = max(1, $opsPage - 2); $p <= min($opsTotalPages, $opsPage + 2); $p++): ?>
                    <a href="debts.php?tab=operations&ops_page=<?= $p ?><?= !empty($opsSearch) ? '&ops_search=' . urlencode($opsSearch) : '' ?>&ops_type=<?= $opsType ?>&ops_method=<?= $opsMethod ?>"
                       class="px-3 py-1.5 rounded-lg font-bold font-num transition <?= $p === $opsPage ? 'bg-slate-900 text-white dark:bg-amber-500 dark:text-slate-950 shadow-xs' : 'border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-700 hover:bg-slate-100' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($opsPage < $opsTotalPages): ?>
                    <a href="debts.php?tab=operations&ops_page=<?= $opsPage + 1 ?><?= !empty($opsSearch) ? '&ops_search=' . urlencode($opsSearch) : '' ?>&ops_type=<?= $opsType ?>&ops_method=<?= $opsMethod ?>"
                       class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-700 font-bold hover:bg-slate-100 transition">
                        التالي
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <?php endif; ?>

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
            <p><strong>حساب الاشتراكات:</strong> يتم تسجيل التزام السائق اليومي أو الشهري بناءً على عقد الاستغلال (&lrm;1,000&lrm; دج اشتراك المنصة + &lrm;4,000&lrm; دج للمركبة).</p>
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

<!-- Modal: Edit Debt -->
<div id="editDebtModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-lg w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-900 text-lg">تعديل القيد المالي للسائق</h3>
            <button onclick="closeEditDebtModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_debt">
            <input type="hidden" name="debt_id" id="editDebtId">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">اسم السائق <span class="text-rose-500">*</span></label>
                    <input type="text" id="editDebtName" name="chauffeur_name" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">رقم الهاتف <span class="text-rose-500">*</span></label>
                    <input type="text" id="editDebtPhone" name="phone" required dir="ltr"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">تصنيف السائق</label>
                    <select name="driver_type" id="editDebtDriverType" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white font-semibold transition">
                        <option value="actif">سائق نشط (Chauffeur Actif)</option>
                        <option value="ancien">سائق سابق / أرشيف (Ancien Chauffeur)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">إجمالي مبلغ الدين / القرض (دج) <span class="text-rose-500">*</span></label>
                    <input type="number" step="100" id="editDebtCredits" name="total_credits" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-num font-bold focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">بيان الدين أو الملاحظات</label>
                <input type="text" id="editDebtNotes" name="notes"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditDebtModal()"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs">
                    حفظ التعديلات
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
function openEditDebtModal(data) {
    document.getElementById('editDebtId').value = data.id || '';
    document.getElementById('editDebtName').value = data.chauffeur_name || '';
    document.getElementById('editDebtPhone').value = data.phone || '';
    document.getElementById('editDebtCredits').value = data.total_credits || 0;
    document.getElementById('editDebtDriverType').value = data.driver_type || 'actif';
    document.getElementById('editDebtNotes').value = data.notes || '';

    const modal = document.getElementById('editDebtModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeEditDebtModal() {
    const modal = document.getElementById('editDebtModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function openArchiveDriverModal(id, name, phone, reste) {
    document.getElementById('archiveDebtId').value = id;
    document.getElementById('archiveDriverName').textContent = name;
    document.getElementById('archiveDriverPhone').textContent = phone ? phone : 'غير مسجل';
    document.getElementById('archiveResteAmount').textContent = Number(reste).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' DZD';
    const modal = document.getElementById('archiveDriverModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeArchiveDriverModal() {
    const modal = document.getElementById('archiveDriverModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<!-- Modal: Archiver un chauffeur endetté -->
<div id="archiveDriverModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white max-w-md w-full rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-2xl space-y-4 text-right m-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center font-bold text-lg">
                    📦
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-base">أرشفة السائق وتجميد الحساب</h3>
                    <span class="text-xs text-slate-500">نقل السائق للأرشيف مع حفظ الديون للمتابعة</span>
                </div>
            </div>
            <button onclick="closeArchiveDriverModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="archive_driver_debt">
            <input type="hidden" name="debt_id" id="archiveDebtId" value="0">

            <div class="p-3.5 bg-amber-50/70 border border-amber-200/80 rounded-xl space-y-1.5 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-slate-600 font-bold">اسم السائق:</span>
                    <span id="archiveDriverName" class="font-extrabold text-slate-900"></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-600 font-bold">رقم الهاتف:</span>
                    <span id="archiveDriverPhone" class="font-num text-slate-700" dir="ltr"></span>
                </div>
                <div class="flex justify-between items-center pt-1 border-t border-amber-200/50">
                    <span class="text-rose-700 font-bold">الرصيد المتبقي بذمته:</span>
                    <span id="archiveResteAmount" class="font-num font-black text-rose-700 text-sm" dir="ltr"></span>
                </div>
            </div>

            <div class="p-3 bg-slate-50 border border-slate-200/70 rounded-xl text-[11px] text-slate-600 leading-relaxed">
                ℹ️ <strong>ملاحظة هامة:</strong> سيتم تحويل السائق إلى فئة <strong>السائقين السابقين والأرشيف</strong> وتجميد حسابه وسحب أي مركبة كانت معينة له، مع <strong>الإبقاء الكامل على ديونه ومستحقاته محفوظة</strong> في كشف ديون الأرشيف للمتابعة والتحصيل.
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">سبب الأرشفة والتوقف عن العمل <span class="text-rose-500">*</span></label>
                <select name="archive_reason" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 font-medium transition">
                    <option value="توقف عن العمل وعليه ديون متأخرة">توقف عن العمل وعليه ديون متأخرة</option>
                    <option value="انقطاع الاتصال بالسائق وعدم الالتزام بالتسديد">انقطاع الاتصال بالسائق وعدم الالتزام بالتسديد</option>
                    <option value="فسخ عقد العمل وبقاء مستحقات عالقة">فسخ عقد العمل وبقاء مستحقات عالقة</option>
                    <option value="سائق مؤقت / موسمي انتهت فترته">سائق مؤقت / موسمي انتهت فترته</option>
                    <option value="إحالة إلى المتابعة الودية والقضائية">إحالة إلى المتابعة الودية والقضائية</option>
                    <option value="سبب آخر يرجى تحديده في الملاحظات">سبب آخر يرجى تحديده في الملاحظات</option>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">ملاحظات وبيانات إضافية (اختياري)</label>
                <input type="text" name="custom_notes" placeholder="مثال: آخر اتصال به كان بتاريخ ... وعد بالدفع ولم يلتزم"
                       class="w-full px-3 py-2.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeArchiveDriverModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition shadow-xs flex items-center gap-1.5 cursor-pointer">
                    <span>📦 تأكيد النقل للأرشيف مع حفظ الدين</span>
                </button>
            </div>
        </form>
    </div>
</div>


<!-- Modal: Preview BaridiMob Receipt Image -->
<div id="receiptPreviewModal" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white max-w-xl w-full rounded-2xl p-5 border border-slate-200 shadow-2xl space-y-4 text-right">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-bold text-slate-900 text-base" id="previewTitle">معاينة وصل بريدي موب</h3>
                <span class="text-xs text-slate-500" id="previewSubtitle"></span>
            </div>
            <button onclick="closeReceiptPreviewModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="max-h-[70vh] overflow-auto flex items-center justify-center bg-slate-950 rounded-xl p-2 border border-slate-800">
            <img id="previewImage" src="" alt="وصل الدفع" class="max-w-full max-h-[65vh] object-contain rounded-lg">
        </div>
        <div class="flex items-center justify-between pt-2">
            <a id="previewDownloadBtn" href="" target="_blank" download class="text-xs text-amber-700 hover:underline font-bold flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                <span>تحميل الصورة بدقة أصلية</span>
            </a>
            <button type="button" onclick="closeReceiptPreviewModal()" class="px-4 py-2 bg-slate-900 text-white rounded-lg font-bold text-xs">
                إغلاق
            </button>
        </div>
    </div>
</div>

<!-- Modal: Reject BaridiMob Payment -->
<div id="rejectVersementModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white max-w-md w-full rounded-2xl p-6 border border-slate-200 shadow-2xl space-y-4 text-right">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <h3 class="font-bold text-slate-900 text-base">رفض دفعة بريدي موب</h3>
            </div>
            <button onclick="closeRejectModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject_versement">
            <input type="hidden" name="versement_id" id="rejectVersementId" value="0">

            <p class="text-slate-600 leading-relaxed" id="rejectDriverInfo">
                هل أنت متأكد من رفض هذه الدفعة؟ سيتم إشعار السائق بسبب الرفض مباشرة في حسابه.
            </p>

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">سبب الرفض <span class="text-rose-500">*</span></label>
                <select name="reject_reason" required class="w-full px-3 py-2.5 rounded-lg border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition font-medium">
                    <option value="التحويل لم يصل للحساب البنكي بعد">التحويل لم يصل للحساب البنكي بعد</option>
                    <option value="صورة الوصل غير واضحة أو مقطوعة">صورة الوصل غير واضحة أو مقطوعة</option>
                    <option value="المبلغ المكتوب لا يطابق المبلغ بالوصل">المبلغ المكتوب لا يطابق المبلغ بالوصل</option>
                    <option value="رقم المعاملة غير صحيح أو مكرر">رقم المعاملة غير صحيح أو مكرر</option>
                    <option value="سبب آخر يرجى التواصل مع الإدارة">سبب آخر يرجى التواصل مع الإدارة</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition">
                    إلغاء
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold transition shadow-xs">
                    تأكيد الرفض
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openReceiptPreviewModal(imgUrl, driver, amount, ref) {
    document.getElementById('previewImage').src = imgUrl;
    document.getElementById('previewTitle').textContent = 'وصل بريدي موب - ' + driver;
    document.getElementById('previewSubtitle').textContent = 'المبلغ: ' + amount + ' | مرجع: ' + ref;
    document.getElementById('previewDownloadBtn').href = imgUrl;
    const modal = document.getElementById('receiptPreviewModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeReceiptPreviewModal() {
    const modal = document.getElementById('receiptPreviewModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function openRejectModal(id, driver, amount) {
    document.getElementById('rejectVersementId').value = id;
    document.getElementById('rejectDriverInfo').innerHTML = 'أنت بصدد رفض دفعة السائق <strong>' + driver + '</strong> بمبلغ <strong>' + amount + '</strong>.<br>يرجى تحديد سبب الرفض لإبلاغ السائق:';
    const modal = document.getElementById('rejectVersementModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeRejectModal() {
    const modal = document.getElementById('rejectVersementModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
