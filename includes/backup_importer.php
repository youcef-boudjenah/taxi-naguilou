<?php
/**
 * includes/backup_importer.php
 * Handles Full Database Export, Upload & Restore, and Scraped Old-Site Data Import.
 */

function safe_date_val(?string $v): ?string {
    if (!$v) return null;
    try {
        $d = new DateTime($v);
        return $d->format('Y-m-d');
    } catch (\Throwable $e) { return null; }
}

function safe_num_val($v, float $default = 0): float {
    if ($v === null || $v === '') return $default;
    $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($v) ? (float)$v : $default;
}

/**
 * Export full operational database as JSON structure
 */
function export_full_backup(PDO $pdo): array {
    $tables = [
        'users',
        'vehicles',
        'contracts',
        'driver_subscriptions',
        'transmission_slips',
        'driver_debts',
        'versements',
        'cnas_declarations',
        'leaves',
        'billings',
        'notifications',
        'traveler_reports',
        'traveler_passengers',
        'night_watch',
        'driver_schedules',
        'insurance_policies',
        'settings'
    ];

    $dump = [
        'generator'    => 'TAXI-NAGUILOU Platform Backup System',
        'version'      => '2.0.0',
        'exported_at'  => date('c'),
        'exported_by'  => $_SESSION['user_name'] ?? 'Admin',
        'tables'       => []
    ];

    foreach ($tables as $t) {
        try {
            $stmt = $pdo->query("SELECT * FROM `$t`");
            $dump['tables'][$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $dump['tables'][$t] = [];
        }
    }

    return $dump;
}

/**
 * Import and clean data from either a direct platform backup OR old-site scraped dump
 */
function import_full_backup(PDO $pdo, array $payload): array {
    $counts = [
        'users'         => 0,
        'vehicles'      => 0,
        'contracts'     => 0,
        'subscriptions' => 0,
        'slips'         => 0,
        'notifications' => 0,
        'settings'      => 0,
        'debts'         => 0,
    ];
    $errors = [];

    // Normalize payload root
    $source = $payload['tables'] ?? $payload['data'] ?? $payload;

    $pdo->beginTransaction();
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

        $uuidToUserId    = [];
        $uuidToVehicleId = [];
        $emailToUserId   = [];
        $plateToVehId    = [];

        // Preload existing user emails & vehicle plates
        foreach ($pdo->query("SELECT id, email, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (!empty($u['email'])) $emailToUserId[strtolower(trim($u['email']))] = (int)$u['id'];
        }
        foreach ($pdo->query("SELECT id, plate_number FROM vehicles")->fetchAll(PDO::FETCH_ASSOC) as $v) {
            if (!empty($v['plate_number'])) $plateToVehId[trim($v['plate_number'])] = (int)$v['id'];
        }

        $defaultAdminPassHash = password_hash('moh2026', PASSWORD_DEFAULT);
        $defaultDriverPassHash = password_hash('Pass@2026', PASSWORD_DEFAULT);

        // ────────────────────────────────────────────────────────────
        // 1. USERS / DRIVERS
        // ────────────────────────────────────────────────────────────
        $usersList = $source['users'] ?? $source['drivers'] ?? [];
        if (!empty($usersList) && is_array($usersList)) {
            $uStmt = $pdo->prepare("
                INSERT INTO users (
                    full_name, first_name, last_name, email, password_hash, phone, role, driver_type, probation_stage, status,
                    license_number, license_expiry, medical_cert_expiry, license_issue_date,
                    card_number, address, wilaya, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    phone = VALUES(phone),
                    role = VALUES(role),
                    driver_type = VALUES(driver_type),
                    probation_stage = VALUES(probation_stage),
                    status = VALUES(status),
                    license_number = COALESCE(VALUES(license_number), license_number),
                    license_expiry = COALESCE(VALUES(license_expiry), license_expiry),
                    medical_cert_expiry = COALESCE(VALUES(medical_cert_expiry), medical_cert_expiry),
                    license_issue_date = COALESCE(VALUES(license_issue_date), license_issue_date),
                    card_number = COALESCE(VALUES(card_number), card_number),
                    address = COALESCE(VALUES(address), address),
                    wilaya = COALESCE(VALUES(wilaya), wilaya)
            ");

            foreach ($usersList as $u) {
                $uuid = $u['id'] ?? '';
                $email = strtolower(trim($u['email'] ?? ''));
                if (!$email) {
                    $email = 'driver_' . substr(md5($uuid ?: uniqid()), 0, 8) . '@taxi-naguilou.com';
                }

                $first = trim($u['firstName'] ?? $u['first_name'] ?? '');
                $last  = trim($u['lastName'] ?? $u['last_name'] ?? '');
                $fullName = trim($u['full_name'] ?? "$first $last");
                if (!$fullName) $fullName = 'مستخدم ' . substr($uuid, 0, 6);

                $phone = trim($u['phone'] ?? $u['phoneNumber'] ?? '0500000000');
                if (!$phone) $phone = '0500000000';

                $roleRaw = strtoupper($u['role'] ?? '');
                if ($roleRaw === 'SUPER_ADMIN' || $roleRaw === 'ADMIN' || str_contains($email, 'naguiloutaxi') || $email === 'eurldymi@gmail.com') {
                    $role = ($roleRaw === 'SUPER_ADMIN' || str_contains($email, 'naguiloutaxi')) ? 'super_admin' : 'admin';
                    $driverType = null;
                } else {
                    $role = 'driver';
                    $driverType = ($roleRaw === 'VEHICLE_OWNER' || $roleRaw === 'OWNER') ? 'owner' : 'ajir';
                }

                $probRaw = strtoupper($u['probationPeriod'] ?? $u['probation_stage'] ?? '');
                if ($probRaw === 'COMPLETED' || $probRaw === 'OFFICIAL') {
                    $probStage = 'official';
                } elseif ($probRaw === 'TRIAL_2') {
                    $probStage = 'trial_2';
                } elseif ($probRaw === 'TRIAL_3') {
                    $probStage = 'trial_3';
                } else {
                    $probStage = 'trial_1';
                }

                $statusRaw = strtoupper($u['status'] ?? '');
                if ($statusRaw === 'ACTIVE') $status = 'active';
                elseif ($statusRaw === 'SUSPENDED') $status = 'suspended';
                else $status = 'pending';

                $passHash = (!empty($u['password_hash'])) ? $u['password_hash'] :
                            (($role === 'super_admin' || $role === 'admin' || str_contains($email, 'naguiloutaxi')) ? $defaultAdminPassHash : $defaultDriverPassHash);

                $licNum = trim($u['licenseNumber'] ?? $u['license_number'] ?? '');
                $licExp = safe_date_val($u['licenseExpiry'] ?? $u['license_expiry'] ?? null);
                $medExp = safe_date_val($u['medicalCertificateExpiry'] ?? $u['medical_cert_expiry'] ?? null);
                $licIssue = safe_date_val($u['taxiLicenseIssueDate'] ?? $u['license_issue_date'] ?? null);
                $cardNum = trim($u['identityCardNumber'] ?? $u['card_number'] ?? '');
                $addr = trim($u['address'] ?? '');
                $wilaya = trim($u['wilaya'] ?? '16 - الجزائر العاصمة');
                $createdAt = safe_date_val($u['createdAt'] ?? $u['created_at'] ?? null) ?: date('Y-m-d H:i:s');

                try {
                    $uStmt->execute([
                        $fullName, $first ?: null, $last ?: null, $email, $passHash, $phone, $role, $driverType, $probStage, $status,
                        $licNum ?: null, $licExp, $medExp, $licIssue,
                        $cardNum ?: null, $addr ?: null, $wilaya ?: null, $createdAt
                    ]);

                    $uid = $pdo->lastInsertId();
                    if (!$uid) {
                        $s = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $s->execute([$email]);
                        $uid = $s->fetchColumn();
                    }
                    if ($uuid) $uuidToUserId[$uuid] = (int)$uid;
                    $emailToUserId[$email] = (int)$uid;
                    $counts['users']++;
                } catch (\Throwable $e) {
                    $errors[] = "User ($email): " . $e->getMessage();
                }
            }
        }

        // ────────────────────────────────────────────────────────────
        // 2. VEHICLES
        // ────────────────────────────────────────────────────────────
        $vehiclesList = $source['vehicles'] ?? [];
        if (!empty($vehiclesList) && is_array($vehiclesList)) {
            $vStmt = $pdo->prepare("
                INSERT INTO vehicles (
                    owner_id, current_driver_id, brand, model, plate_number, color, door_number,
                    insurance_expiry, technical_control_expiry, extinguisher_expiry, gpl_expiry,
                    gpl_text, saccuriometre_expiry, saccuriometre_text, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    owner_id = VALUES(owner_id),
                    current_driver_id = VALUES(current_driver_id),
                    brand = VALUES(brand),
                    model = VALUES(model),
                    color = VALUES(color),
                    door_number = VALUES(door_number),
                    insurance_expiry = COALESCE(VALUES(insurance_expiry), insurance_expiry),
                    technical_control_expiry = COALESCE(VALUES(technical_control_expiry), technical_control_expiry),
                    extinguisher_expiry = COALESCE(VALUES(extinguisher_expiry), extinguisher_expiry),
                    gpl_expiry = COALESCE(VALUES(gpl_expiry), gpl_expiry),
                    gpl_text = COALESCE(VALUES(gpl_text), gpl_text),
                    saccuriometre_expiry = COALESCE(VALUES(saccuriometre_expiry), saccuriometre_expiry),
                    saccuriometre_text = COALESCE(VALUES(saccuriometre_text), saccuriometre_text),
                    status = VALUES(status)
            ");

            foreach ($vehiclesList as $v) {
                $uuid = $v['id'] ?? '';
                $plate = trim($v['plateNumber'] ?? $v['plate_number'] ?? '');
                if (!$plate) continue;

                $brand   = trim($v['brand'] ?? 'Renault');
                $model   = trim($v['model'] ?? 'Symbol');
                $color   = trim($v['color'] ?? 'أصفر');
                $doorNum = trim($v['doorNumber'] ?? $v['door_number'] ?? $plate);

                $ownerId = null;
                $ownerUuid = $v['ownerId'] ?? $v['owner_id'] ?? null;
                if ($ownerUuid && isset($uuidToUserId[$ownerUuid])) $ownerId = $uuidToUserId[$ownerUuid];

                $driverId = null;
                if (!empty($v['currentDriver']['id']) && isset($uuidToUserId[$v['currentDriver']['id']])) {
                    $driverId = $uuidToUserId[$v['currentDriver']['id']];
                } elseif (!empty($v['currentDriver']['email']) && isset($emailToUserId[strtolower(trim($v['currentDriver']['email']))])) {
                    $driverId = $emailToUserId[strtolower(trim($v['currentDriver']['email']))];
                } elseif ($ownerId) {
                    $driverId = $ownerId;
                }

                $insExp  = safe_date_val($v['insuranceExpiry'] ?? $v['insurance_expiry'] ?? null);
                $techExp = safe_date_val($v['technicalControl'] ?? $v['technical_control_expiry'] ?? null);
                $extExp  = safe_date_val($v['extinguisherExpiry'] ?? $v['extinguisher_expiry'] ?? null);
                $gplExp  = safe_date_val($v['gplExpiry'] ?? $v['gpl_expiry'] ?? null);
                $gplText = trim($v['gplText'] ?? $v['gpl_text'] ?? '');
                $saccExp = safe_date_val($v['saccuriometreExpiry'] ?? $v['saccuriometre_expiry'] ?? null);
                $saccText= trim($v['saccuriometreText'] ?? $v['saccuriometre_text'] ?? '');

                $stRaw = strtoupper($v['status'] ?? '');
                if ($stRaw === 'ACTIVE') $status = 'active';
                elseif ($stRaw === 'SUSPENDED') $status = 'suspended';
                else $status = 'in_maintenance';

                $createdAt = safe_date_val($v['createdAt'] ?? $v['created_at'] ?? null) ?: date('Y-m-d H:i:s');

                try {
                    $vStmt->execute([
                        $ownerId, $driverId, $brand, $model, $plate, $color, $doorNum,
                        $insExp, $techExp, $extExp, $gplExp, $gplText ?: null,
                        $saccExp, $saccText ?: null, $status, $createdAt
                    ]);

                    $vid = $pdo->lastInsertId();
                    if (!$vid) {
                        $s = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ?");
                        $s->execute([$plate]);
                        $vid = $s->fetchColumn();
                    }
                    if ($uuid) $uuidToVehicleId[$uuid] = (int)$vid;
                    $plateToVehId[$plate] = (int)$vid;
                    $counts['vehicles']++;
                } catch (\Throwable $e) {
                    $errors[] = "Vehicle ($plate): " . $e->getMessage();
                }
            }
        }

        // Default admin id for fallbacks
        $defaultAdminId = (int)($pdo->query("SELECT id FROM users WHERE role IN ('super_admin','admin') ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);

        // ────────────────────────────────────────────────────────────
        // 3. CONTRACTS
        // ────────────────────────────────────────────────────────────
        $contractsList = $source['contracts'] ?? [];
        if (!empty($contractsList) && is_array($contractsList)) {
            $cStmt = $pdo->prepare("
                INSERT INTO contracts (
                    contract_number, driver_id, vehicle_id, owner_id, start_date, end_date,
                    monthly_wage, status, signed_at, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    start_date = VALUES(start_date),
                    end_date = VALUES(end_date),
                    monthly_wage = VALUES(monthly_wage),
                    status = VALUES(status),
                    notes = VALUES(notes)
            ");

            foreach ($contractsList as $c) {
                $num = trim($c['contractNumber'] ?? $c['contract_number'] ?? '');
                if (!$num) $num = 'CTR-' . uniqid();

                $vehUuid = $c['vehicleId'] ?? $c['vehicle_id'] ?? ($c['vehicle']['id'] ?? null);
                $vehId = $uuidToVehicleId[$vehUuid] ?? null;
                if (!$vehId && !empty($c['vehicle']['plateNumber'])) {
                    $vehId = $plateToVehId[trim($c['vehicle']['plateNumber'])] ?? null;
                }
                if (!$vehId) {
                    $vehId = (int)($pdo->query("SELECT id FROM vehicles LIMIT 1")->fetchColumn() ?: 1);
                }

                $driverId = null;
                $partnerUuid = $c['partnerId'] ?? $c['driver_id'] ?? ($c['partner']['id'] ?? null);
                if ($partnerUuid && isset($uuidToUserId[$partnerUuid])) {
                    $driverId = $uuidToUserId[$partnerUuid];
                } elseif (!empty($c['partner']['email']) && isset($emailToUserId[strtolower(trim($c['partner']['email']))])) {
                    $driverId = $emailToUserId[strtolower(trim($c['partner']['email']))];
                } else {
                    $driverId = (int)($pdo->query("SELECT current_driver_id FROM vehicles WHERE id = $vehId")->fetchColumn() ?: $defaultAdminId);
                }

                $ownerId = $defaultAdminId;

                $startDate = safe_date_val($c['startDate'] ?? $c['start_date'] ?? null) ?: date('Y-01-01');
                $endDate   = safe_date_val($c['endDate'] ?? $c['end_date'] ?? null) ?: date('Y-12-31', strtotime('+1 year'));
                $wage      = safe_num_val($c['monthlyWage'] ?? $c['monthly_wage'] ?? 45000, 45000);

                $stRaw = strtoupper($c['status'] ?? '');
                if ($stRaw === 'SIGNED') $status = 'signed';
                elseif ($stRaw === 'DRAFT') $status = 'draft';
                else $status = 'terminated';

                $signedAt  = safe_date_val($c['signedAt'] ?? $c['signed_at'] ?? null) ?: $startDate;
                $notes     = trim($c['terms'] ?? $c['notes'] ?? '');
                $createdAt = safe_date_val($c['createdAt'] ?? $c['created_at'] ?? null) ?: date('Y-m-d H:i:s');

                try {
                    $cStmt->execute([
                        $num, $driverId, $vehId, $ownerId, $startDate, $endDate,
                        $wage, $status, $signedAt, $notes ?: null, $createdAt
                    ]);
                    $counts['contracts']++;
                } catch (\Throwable $e) {
                    $errors[] = "Contract ($num): " . $e->getMessage();
                }
            }
        }

        // ────────────────────────────────────────────────────────────
        // 4. SUBSCRIPTIONS
        // ────────────────────────────────────────────────────────────
        $subsList = $source['subscriptions'] ?? $source['driver_subscriptions'] ?? [];
        if (!empty($subsList) && is_array($subsList)) {
            $sStmt = $pdo->prepare("
                INSERT INTO driver_subscriptions (
                    driver_id, period_month, space_fee, vehicle_fee, vehicles_count,
                    total_due, paid_amount, status, payment_date, payment_method, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $curMonth = date('Y-m');
            foreach ($subsList as $s) {
                $userUuid = $s['user']['id'] ?? $s['driver_id'] ?? null;
                $driverId = $uuidToUserId[$userUuid] ?? null;
                if (!$driverId && !empty($s['user']['email'])) {
                    $driverId = $emailToUserId[strtolower(trim($s['user']['email']))] ?? null;
                }
                if (!$driverId && is_numeric($userUuid)) {
                    $driverId = (int)$userUuid;
                }
                if (!$driverId) continue;

                $month       = trim($s['period_month'] ?? $curMonth);
                $spaceFee    = safe_num_val($s['spaceFee'] ?? $s['space_fee'] ?? 0);
                $vehFee      = safe_num_val($s['vehiclesFee'] ?? $s['vehicle_fee'] ?? 0);
                $vehCountNum = (int)($s['vehiclesCount'] ?? $s['vehicles_count'] ?? count($s['vehicles'] ?? []));
                $totalDue    = safe_num_val($s['amountDue'] ?? $s['total_due'] ?? ($spaceFee + $vehFee));

                $stRaw = strtoupper($s['status'] ?? '');
                if ($stRaw === 'PAID' || $stRaw === 'REGLE') {
                    $status = 'regle';
                    $paidAmount = $totalDue;
                } elseif ($stRaw === 'PARTIAL' || $stRaw === 'PARTIEL') {
                    $status = 'partiel';
                    $paidAmount = safe_num_val($s['paidAmount'] ?? $s['paid_amount'] ?? ($totalDue / 2));
                } else {
                    $status = 'en_attente';
                    $paidAmount = 0;
                }

                $payDate   = ($status === 'regle') ? ($s['payment_date'] ?? date('Y-m-d')) : null;
                $payMethod = ($status === 'regle') ? ($s['payment_method'] ?? 'baridimob') : null;
                $notes     = trim($s['notes'] ?? 'استيراد نسخة احتياطية');

                try {
                    $sStmt->execute([
                        $driverId, $month, $spaceFee, $vehFee, $vehCountNum,
                        $totalDue, $paidAmount, $status, $payDate, $payMethod, $notes, date('Y-m-d H:i:s')
                    ]);
                    $counts['subscriptions']++;
                } catch (\Throwable $e) {}
            }
        }

        // ────────────────────────────────────────────────────────────
        // 5. TRANSMISSION SLIPS
        // ────────────────────────────────────────────────────────────
        $slipsList = $source['transmission_slips'] ?? $source['transmission-slips'] ?? [];
        if (!empty($slipsList) && is_array($slipsList)) {
            $slipStmt = $pdo->prepare("
                INSERT INTO transmission_slips (
                    slip_number, vehicle_id, driver_id, route_line, issue_date, valid_until, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE route_line = VALUES(route_line)
            ");
            foreach ($slipsList as $sl) {
                $num = trim($sl['slipNumber'] ?? $sl['slip_number'] ?? ('BL-' . uniqid()));
                $vehUuid = $sl['vehicleId'] ?? $sl['vehicle_id'] ?? '';
                $vehId = $uuidToVehicleId[$vehUuid] ?? (int)($pdo->query("SELECT id FROM vehicles LIMIT 1")->fetchColumn() ?: 1);

                $driverUuid = $sl['driverId'] ?? $sl['driver_id'] ?? '';
                $driverId = $uuidToUserId[$driverUuid] ?? $defaultAdminId;

                $route = trim($sl['routeLine'] ?? $sl['route_line'] ?? 'الجزائر العاصمة - وهران');
                $issue = safe_date_val($sl['issueDate'] ?? $sl['issue_date'] ?? null) ?: date('Y-m-d');
                $valid = safe_date_val($sl['validUntil'] ?? $sl['valid_until'] ?? null) ?: date('Y-m-d', strtotime('+3 months'));
                $stRaw = strtoupper($sl['status'] ?? '');
                $status = ($stRaw === 'ACTIVE') ? 'active' : 'expired';

                try {
                    $slipStmt->execute([$num, $vehId, $driverId, $route, $issue, $valid, $status, date('Y-m-d H:i:s')]);
                    $counts['slips']++;
                } catch (\Throwable $e) {}
            }
        }

        // ────────────────────────────────────────────────────────────
        // 5b. NOTIFICATIONS
        // ────────────────────────────────────────────────────────────
        $notifsList = $source['notifications'] ?? [];
        if (!empty($notifsList) && is_array($notifsList)) {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, link, is_read, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($notifsList as $notif) {
                $userUuid = $notif['userId'] ?? $notif['user_id'] ?? null;
                $userId = ($userUuid && isset($uuidToUserId[$userUuid])) ? $uuidToUserId[$userUuid] : $defaultAdminId;
                $title = trim($notif['title'] ?? 'إشعار من النظام');
                $msg = trim($notif['message'] ?? '');
                $type = 'info';
                if (stripos($title, 'Alerte') !== false || stripos($title, 'Urgent') !== false) $type = 'urgent';
                elseif (stripos($title, 'Attention') !== false) $type = 'warning';
                elseif (stripos($title, 'Succès') !== false) $type = 'success';

                $isRead = !empty($notif['isRead']) ? 1 : 0;
                $createdAt = safe_date_val($notif['createdAt'] ?? null) ?: date('Y-m-d H:i:s');

                try {
                    $notifStmt->execute([$userId, $type, $title, $msg, null, $isRead, $createdAt]);
                    $counts['notifications']++;
                } catch (\Throwable $e) {}
            }
        }

        // ────────────────────────────────────────────────────────────
        // 6. SETTINGS
        // ────────────────────────────────────────────────────────────
        $settingsList = $source['settings'] ?? [];
        if (!empty($settingsList) && is_array($settingsList)) {
            $setStmt = $pdo->prepare("
                INSERT INTO settings (`key`, `value`, `category`, `updated_at`)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()
            ");
            foreach ($settingsList as $k => $v) {
                if (is_array($v) && isset($v['key'])) {
                    $setStmt->execute([$v['key'], (string)$v['value'], $v['category'] ?? 'general']);
                    $counts['settings']++;
                } elseif (!is_numeric($k) && (is_string($v) || is_numeric($v) || is_bool($v))) {
                    $valStr = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
                    $setStmt->execute([(string)$k, $valStr, 'general']);
                    $counts['settings']++;
                }
            }
        }

        // ────────────────────────────────────────────────────────────
        // 7. DRIVER DEBTS & VERSEMENTS
        // ────────────────────────────────────────────────────────────
        $debtsList = $source['driver_debts'] ?? [];
        if (!empty($debtsList) && is_array($debtsList)) {
            $pdo->exec("DELETE FROM versements; DELETE FROM driver_debts;");
            $dStmt = $pdo->prepare("
                INSERT INTO driver_debts (id, user_id, chauffeur_name, phone, total_credits, total_versements, driver_type, status, last_versement_date, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($debtsList as $row) {
                try {
                    $dStmt->execute([
                        $row['id'] ?? null, $row['user_id'] ?? null, $row['chauffeur_name'] ?? '',
                        $row['phone'] ?? '', $row['total_credits'] ?? 0, $row['total_versements'] ?? 0,
                        $row['driver_type'] ?? 'actif', $row['status'] ?? 'endette',
                        safe_date_val($row['last_versement_date'] ?? null), $row['notes'] ?? null,
                        $row['created_at'] ?? date('Y-m-d H:i:s')
                    ]);
                    $counts['debts']++;
                } catch (\Throwable $e) {}
            }
        }

        $versList = $source['versements'] ?? [];
        if (!empty($versList) && is_array($versList)) {
            $vStmt = $pdo->prepare("
                INSERT INTO versements (id, debt_id, amount, payment_method, payment_date, receipt_ref, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($versList as $row) {
                try {
                    $vStmt->execute([
                        $row['id'] ?? null, $row['debt_id'] ?? 1, $row['amount'] ?? 0,
                        $row['payment_method'] ?? 'especes', safe_date_val($row['payment_date'] ?? null) ?: date('Y-m-d'),
                        $row['receipt_ref'] ?? null, $row['notes'] ?? null, $row['created_at'] ?? date('Y-m-d H:i:s')
                    ]);
                } catch (\Throwable $e) {}
            }
        }

        // ────────────────────────────────────────────────────────────
        // 8. CNAS DECLARATIONS
        // ────────────────────────────────────────────────────────────
        $cnasList = $source['cnas_declarations'] ?? [];
        if (!empty($cnasList) && is_array($cnasList)) {
            $pdo->exec("DELETE FROM cnas_declarations;");
            $cStmt = $pdo->prepare("
                INSERT INTO cnas_declarations (id, driver_id, cnas_number, affiliation_date, declaration_type, period_quarter, base_salary, cotisation_patronale, cotisation_ouvriere, total_cotisation, payment_status, payment_date, receipt_ref, attestation_expiry, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($cnasList as $row) {
                try {
                    $cStmt->execute([
                        $row['id'] ?? null, $row['driver_id'] ?? 1, $row['cnas_number'] ?? '',
                        safe_date_val($row['affiliation_date'] ?? null) ?: '2024-01-01', $row['declaration_type'] ?? 'principale',
                        $row['period_quarter'] ?? 'T3-2026', $row['base_salary'] ?? 45000, $row['cotisation_patronale'] ?? 11700,
                        $row['cotisation_ouvriere'] ?? 4050, $row['total_cotisation'] ?? 15750, $row['payment_status'] ?? 'regle',
                        safe_date_val($row['payment_date'] ?? null), $row['receipt_ref'] ?? null,
                        safe_date_val($row['attestation_expiry'] ?? null), $row['notes'] ?? null, $row['created_at'] ?? date('Y-m-d H:i:s')
                    ]);
                } catch (\Throwable $e) {}
            }
        }

        // ────────────────────────────────────────────────────────────
        // 9. BILLINGS, LEAVES, TRAVELER REPORTS, NIGHT WATCH, SCHEDULES, INSURANCE
        // ────────────────────────────────────────────────────────────
        $simpleTables = [
            'billings' => "INSERT INTO billings (id, vehicle_id, amount, paid_amount, period_month, due_date, payment_date, payment_method, status, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'leaves'   => "INSERT INTO leaves (id, driver_id, vehicle_id, replacement_driver_id, start_date, end_date, reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'traveler_reports' => "INSERT INTO traveler_reports (id, report_number, vehicle_id, driver_id, line_name, departure_station, arrival_station, departure_date, departure_time, seats_count, price_per_seat, total_revenue, status, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'traveler_passengers' => "INSERT INTO traveler_passengers (id, report_id, seat_number, passenger_name, passenger_cni, phone, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            'night_watch' => "INSERT INTO night_watch (id, vehicle_id, driver_id, guardian_name, park_slot, entry_time, exit_time, status, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'driver_schedules' => "INSERT INTO driver_schedules (id, driver_id, vehicle_id, shift_date, shift_type, is_permanence, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            'insurance_policies' => "INSERT INTO insurance_policies (id, vehicle_id, policy_number, company, coverage_type, start_date, end_date, premium_amount, attestation_number, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        ];

        foreach ($simpleTables as $tbl => $insertSql) {
            $rows = $source[$tbl] ?? [];
            if (!empty($rows) && is_array($rows)) {
                $pdo->exec("DELETE FROM `$tbl`;");
                $stmt = $pdo->prepare($insertSql);
                foreach ($rows as $r) {
                    try {
                        $stmt->execute(array_values($r));
                    } catch (\Throwable $e) {}
                }
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        $pdo->commit();

        return [
            'success' => true,
            'counts'  => $counts,
            'errors'  => $errors,
            'message' => 'تم استيراد ومعالجة النسخة الاحتياطية بنجاح!'
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        return [
            'success' => false,
            'counts'  => $counts,
            'errors'  => array_merge($errors, [$e->getMessage()]),
            'message' => 'فشلت عملية الاستيراد: ' . $e->getMessage()
        ];
    }
}
