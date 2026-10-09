<?php
// config/db.php - Central Database Configuration & Auto-Migration
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'taxi_naguilou');

function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $dsnWithoutDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        $rawPdo = new PDO($dsnWithoutDb, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 2
        ]);

        $rawPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $dsnWithDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsnWithDb, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 2
        ]);

        initAllTables($pdo);
        seedDefaultData($pdo);

        return $pdo;
    } catch (Exception $e) {
        error_log("DB Connection Warning: " . $e->getMessage());
        return null;
    }
}

function initAllTables(PDO $pdo) {
    $schema = "
    -- Users Table (Admins, Owners, Drivers)
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(150) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        role ENUM('super_admin', 'admin', 'owner', 'driver') DEFAULT 'driver',
        status ENUM('active', 'pending', 'suspended') DEFAULT 'active',
        wilaya VARCHAR(100) DEFAULT '16 - الجزائر العاصمة',
        license_number VARCHAR(50) NULL,
        license_expiry DATE NULL,
        medical_cert_expiry DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Vehicles Table
    CREATE TABLE IF NOT EXISTS vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NULL,
        current_driver_id INT NULL,
        replacement_driver_id INT NULL,
        brand VARCHAR(80) NOT NULL,
        model VARCHAR(80) NOT NULL,
        plate_number VARCHAR(50) UNIQUE NOT NULL,
        color VARCHAR(50) DEFAULT 'أصفر',
        door_number VARCHAR(50) NOT NULL,
        insurance_expiry DATE NULL,
        insurance_company VARCHAR(80) DEFAULT 'SAA',
        technical_control_expiry DATE NULL,
        extinguisher_expiry DATE NULL,
        gpl_expiry DATE NULL,
        saccuriometre_expiry DATE NULL,
        saccuriometre_text VARCHAR(100) DEFAULT 'معاير ومختوم',
        status ENUM('active', 'suspended', 'in_maintenance') DEFAULT 'active',
        suspended_reason VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (current_driver_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (replacement_driver_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Contracts Table (Algerian Labor Law 90-11)
    CREATE TABLE IF NOT EXISTS contracts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_number VARCHAR(50) UNIQUE NOT NULL,
        driver_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        owner_id INT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        monthly_wage DECIMAL(10, 2) DEFAULT 45000.00,
        status ENUM('draft', 'signed', 'terminated') DEFAULT 'signed',
        signed_at TIMESTAMP NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Leaves & Replacement Drivers
    CREATE TABLE IF NOT EXISTS leaves (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        replacement_driver_id INT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        reason VARCHAR(255) NOT NULL,
        status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'approved',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        FOREIGN KEY (replacement_driver_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Billings & Subscriptions
    CREATE TABLE IF NOT EXISTS billings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        paid_amount DECIMAL(10, 2) DEFAULT 0.00,
        period_month VARCHAR(20) NOT NULL,
        due_date DATE NOT NULL,
        payment_date DATE NULL,
        payment_method ENUM('cash', 'baridimob', 'ccp', 'bank') DEFAULT 'baridimob',
        status ENUM('paid', 'pending', 'overdue') DEFAULT 'pending',
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Transmission Slips (Bordereaux de transmission)
    CREATE TABLE IF NOT EXISTS transmission_slips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slip_number VARCHAR(50) UNIQUE NOT NULL,
        vehicle_id INT NOT NULL,
        driver_id INT NOT NULL,
        route_line VARCHAR(200) NOT NULL,
        issue_date DATE NOT NULL,
        valid_until DATE NOT NULL,
        status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Driver Debts & Receivables (Finances & Dettes)
    CREATE TABLE IF NOT EXISTS driver_debts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        chauffeur_name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        total_credits DECIMAL(12, 2) DEFAULT 0.00,
        total_versements DECIMAL(12, 2) DEFAULT 0.00,
        driver_type ENUM('actif', 'ancien') DEFAULT 'actif',
        status ENUM('endette', 'a_jour') DEFAULT 'endette',
        last_versement_date DATE NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Versements Log (Payments)
    CREATE TABLE IF NOT EXISTS versements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        debt_id INT NOT NULL,
        amount DECIMAL(12, 2) NOT NULL,
        payment_method ENUM('especes', 'baridimob', 'ccp', 'virement') DEFAULT 'especes',
        payment_date DATE NOT NULL,
        receipt_ref VARCHAR(100) NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (debt_id) REFERENCES driver_debts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Garde de nuit du parc (Night Watch / Park Security)
    CREATE TABLE IF NOT EXISTS night_watch (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        driver_id INT NULL,
        guardian_name VARCHAR(100) NOT NULL,
        park_slot VARCHAR(50) DEFAULT 'Zone A',
        entry_time DATETIME NOT NULL,
        exit_time DATETIME NULL,
        status ENUM('in_park', 'sorti') DEFAULT 'in_park',
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Planning des chauffeurs (3 jours) & Permanence
    CREATE TABLE IF NOT EXISTS driver_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        shift_date DATE NOT NULL,
        shift_type ENUM('matin', 'soir', 'nuit') DEFAULT 'matin',
        is_permanence BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Insurance Policies Table (Gérer Assurance)
    CREATE TABLE IF NOT EXISTS insurance_policies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        policy_number VARCHAR(100) NOT NULL,
        company VARCHAR(80) NOT NULL,
        coverage_type VARCHAR(100) DEFAULT 'Tous Risques + Transport Voyageurs',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        premium_amount DECIMAL(10, 2) DEFAULT 32000.00,
        attestation_number VARCHAR(100) NULL,
        status ENUM('active', 'expired', 'renewed') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Driver Subscriptions (Abonnements & Facturation: 1 000 DA espace + 4 000 DA par véhicule)
    CREATE TABLE IF NOT EXISTS driver_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        period_month VARCHAR(20) NOT NULL,
        space_fee DECIMAL(10, 2) DEFAULT 1000.00,
        vehicle_fee DECIMAL(10, 2) DEFAULT 4000.00,
        vehicles_count INT DEFAULT 1,
        total_due DECIMAL(10, 2) DEFAULT 5000.00,
        paid_amount DECIMAL(10, 2) DEFAULT 0.00,
        status ENUM('regle', 'en_attente', 'partiel') DEFAULT 'en_attente',
        payment_date DATE NULL,
        payment_method VARCHAR(50) DEFAULT 'ccp',
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Leads & Contact Requests
    CREATE TABLE IF NOT EXISTS leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        email VARCHAR(150) NULL,
        role_type ENUM('owner', 'driver', 'agency', 'investor') DEFAULT 'owner',
        wilaya VARCHAR(100) NOT NULL,
        fleet_size INT DEFAULT 1,
        message TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- System Notifications (إشعارات وتنبيهات النظام)
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        type ENUM('alert', 'warning', 'info', 'success', 'urgent') DEFAULT 'info',
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        link VARCHAR(255) NULL,
        is_read BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($schema);
}

function seedDefaultData(PDO $pdo) {
    $defaultPassword = password_hash('password123', PASSWORD_BCRYPT);
    $usersCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    
    if ($usersCount == 0) {

    // 1. Seed Users (Super Admin, Owner, Drivers)
    $stmtUser = $pdo->prepare("
        INSERT INTO users (full_name, email, password_hash, phone, role, status, wilaya, license_number, license_expiry, medical_cert_expiry)
        VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)
    ");

    // Admin
    $stmtUser->execute([
        'مدير المنصة - تاكسي نقيلو',
        'admin@taxinaguilou.dz',
        $defaultPassword,
        '0550000001',
        'super_admin',
        '16 - الجزائر العاصمة',
        'AL-998822',
        '2030-01-01',
        '2028-01-01'
    ]);
    $adminId = $pdo->lastInsertId();

    // Owner (مالك سيارات الأجرة)
    $stmtUser->execute([
        'الحاج رشيد بن عيسى (مالك أسطول)',
        'owner@taxinaguilou.dz',
        $defaultPassword,
        '0661223344',
        'owner',
        '16 - الجزائر العاصمة',
        'AL-123456',
        '2029-05-15',
        '2027-05-15'
    ]);
    $ownerId = $pdo->lastInsertId();

    // Driver 1
    $stmtUser->execute([
        'أحمد بلقاسم (سائق أساسي)',
        'driver1@taxinaguilou.dz',
        $defaultPassword,
        '0770554433',
        'driver',
        '16 - الجزائر العاصمة',
        'B-9481726',
        '2027-10-12',
        '2026-11-20'
    ]);
    $driver1Id = $pdo->lastInsertId();

    // Driver 2
    $stmtUser->execute([
        'خالد مسعودي (سائق أساسي)',
        'driver2@taxinaguilou.dz',
        $defaultPassword,
        '0552887766',
        'driver',
        '16 - الجزائر العاصمة',
        'B-3329104',
        '2028-02-14',
        '2027-01-10'
    ]);
    $driver2Id = $pdo->lastInsertId();

    // Driver 3 (Replacement Driver - سائق بديل)
    $stmtUser->execute([
        'كريم عمارة (سائق بديل معتمد)',
        'driver3@taxinaguilou.dz',
        $defaultPassword,
        '0667990011',
        'driver',
        '16 - الجزائر العاصمة',
        'B-5511223',
        '2029-08-25',
        '2027-09-01'
    ]);
    $driver3Id = $pdo->lastInsertId();

    // 2. Seed Vehicles
    $stmtVehicle = $pdo->prepare("
        INSERT INTO vehicles (owner_id, current_driver_id, replacement_driver_id, brand, model, plate_number, color, door_number, insurance_expiry, insurance_company, technical_control_expiry, extinguisher_expiry, gpl_expiry, saccuriometre_expiry, saccuriometre_text, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Vehicle 1: All good
    $stmtVehicle->execute([
        $ownerId, $driver1Id, null, 'Renault', 'Symbol', '01458-121-16', 'أبيض', '16-042',
        date('Y-m-d', strtotime('+75 days')), 'SAA',
        date('Y-m-d', strtotime('+85 days')),
        date('Y-m-d', strtotime('+120 days')),
        date('Y-m-d', strtotime('+160 days')),
        date('Y-m-d', strtotime('+90 days')), 'معاير ومختوم', 'active'
    ]);
    $v1Id = $pdo->lastInsertId();

    // Vehicle 2: Technical control expiring soon (6 days!)
    $stmtVehicle->execute([
        $ownerId, $driver2Id, null, 'Dacia', 'Logan', '00892-122-16', 'رمادي', '16-088',
        date('Y-m-d', strtotime('+60 days')), 'CAAT',
        date('Y-m-d', strtotime('+6 days')), // URGENT ALERT
        date('Y-m-d', strtotime('+40 days')),
        date('Y-m-d', strtotime('+110 days')),
        date('Y-m-d', strtotime('+45 days')), 'معاير ومختوم', 'active'
    ]);
    $v2Id = $pdo->lastInsertId();

    // Vehicle 3: Peugeot with relief driver
    $stmtVehicle->execute([
        $ownerId, $driver1Id, $driver3Id, 'Peugeot', '301', '03319-120-16', 'أسود', '16-105',
        date('Y-m-d', strtotime('+110 days')), 'CIAR',
        date('Y-m-d', strtotime('+95 days')),
        date('Y-m-d', strtotime('+30 days')),
        date('Y-m-d', strtotime('+14 days')), // GPL inspection due soon
        date('Y-m-d', strtotime('+80 days')), 'معاير ومختوم', 'active'
    ]);
    $v3Id = $pdo->lastInsertId();

    // 3. Seed Contracts (Law 90-11)
    $stmtContract = $pdo->prepare("
        INSERT INTO contracts (contract_number, driver_id, vehicle_id, owner_id, start_date, end_date, monthly_wage, status, signed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'signed', NOW())
    ");
    $stmtContract->execute(['CT-2026-001', $driver1Id, $v1Id, $ownerId, '2026-01-01', '2027-01-01', 45000.00]);
    $stmtContract->execute(['CT-2026-002', $driver2Id, $v2Id, $ownerId, '2026-02-01', '2027-02-01', 48000.00]);

    // 4. Seed Leave & Relief
    $stmtLeave = $pdo->prepare("
        INSERT INTO leaves (driver_id, vehicle_id, replacement_driver_id, start_date, end_date, reason, status)
        VALUES (?, ?, ?, ?, ?, ?, 'approved')
    ");
    $stmtLeave->execute([
        $driver1Id, $v3Id, $driver3Id,
        date('Y-m-d'), date('Y-m-d', strtotime('+7 days')),
        'عطلة سنوية نظامية مدفوعة الأجر'
    ]);

    // 5. Seed Billings
    $stmtBill = $pdo->prepare("
        INSERT INTO billings (vehicle_id, amount, paid_amount, period_month, due_date, payment_date, payment_method, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtBill->execute([$v1Id, 4500.00, 4500.00, 'أكتوبر 2026', date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('-2 days')), 'baridimob', 'paid']);
    $stmtBill->execute([$v2Id, 4500.00, 0.00, 'أكتوبر 2026', date('Y-m-d', strtotime('+10 days')), null, 'baridimob', 'pending']);
    $stmtBill->execute([$v3Id, 4500.00, 4500.00, 'أكتوبر 2026', date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('-8 days')), 'ccp', 'paid']);

    // 6. Seed Transmission Slips
    $stmtSlip = $pdo->prepare("
        INSERT INTO transmission_slips (slip_number, vehicle_id, driver_id, route_line, issue_date, valid_until, status)
        VALUES (?, ?, ?, ?, ?, ?, 'active')
    ");
    $stmtSlip->execute([
        'BRD-2026-0042', $v3Id, $driver3Id,
        'محطة خروبة (الجزائر) ⇄ محطة الباهية (وهران)',
        date('Y-m-d'), date('Y-m-d', strtotime('+15 days'))
    ]);
    } // End of if ($usersCount == 0)

    // Fetch driver and vehicle IDs for debts and schedules
    $driver1Id = $pdo->query("SELECT id FROM users WHERE email = 'driver1@taxinaguilou.dz'")->fetchColumn() ?: 3;
    $driver2Id = $pdo->query("SELECT id FROM users WHERE email = 'driver2@taxinaguilou.dz'")->fetchColumn() ?: 4;
    $driver3Id = $pdo->query("SELECT id FROM users WHERE email = 'driver3@taxinaguilou.dz'")->fetchColumn() ?: 5;

    $v1Id = $pdo->query("SELECT id FROM vehicles LIMIT 1 OFFSET 0")->fetchColumn() ?: 1;
    $v2Id = $pdo->query("SELECT id FROM vehicles LIMIT 1 OFFSET 1")->fetchColumn() ?: 2;
    $v3Id = $pdo->query("SELECT id FROM vehicles LIMIT 1 OFFSET 2")->fetchColumn() ?: 3;

    // 7. Seed Finances & Dettes (Chauffeurs Actifs & Anciens)
    $debtsCount = $pdo->query("SELECT COUNT(*) FROM driver_debts")->fetchColumn();
    if ($debtsCount == 0) {
        $stmtDebt = $pdo->prepare("
            INSERT INTO driver_debts (user_id, chauffeur_name, phone, total_credits, total_versements, driver_type, status, last_versement_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Active Driver 1: Endetté (Crédit: 550,000, Verse: 120,000 => Reste: 430,000 DZD)
        $stmtDebt->execute([$driver1Id, 'أحمد بلقاسم', '0770554433', 550000.00, 120000.00, 'actif', 'endette', date('Y-m-d', strtotime('-4 days')), 'اشتراكات ووقود معلق']);
        $d1Id = $pdo->lastInsertId();

        // Active Driver 2: Endetté (Crédit: 680,000, Verse: 134,500 => Reste: 545,500 DZD)
        // Total Dettes Chauffeurs Actifs = 430,000 + 545,500 = 975,500 DZD!
        $stmtDebt->execute([$driver2Id, 'خالد مسعودي', '0552887766', 680000.00, 134500.00, 'actif', 'endette', date('Y-m-d', strtotime('-8 days')), 'مستحقات التأمين والساكوريومتر']);
        $d2Id = $pdo->lastInsertId();

        // Active Driver 3: À jour (Reste: 0 DZD)
        $stmtDebt->execute([$driver3Id, 'كريم عمارة', '0667990011', 180000.00, 180000.00, 'actif', 'a_jour', date('Y-m-d', strtotime('-2 days')), 'تسوية تامة']);
        
        // Former Drivers with Debts (Anciens Chauffeurs: Total Reste = 16,000 DZD)
        // Former 1: 10,000 DZD
        $stmtDebt->execute([null, 'سليم قادري (سابق)', '0551002233', 95000.00, 85000.00, 'ancien', 'endette', '2026-06-15', 'باقي من عقد عمل سابق']);
        // Former 2: 6,000 DZD
        $stmtDebt->execute([null, 'ياسين بلخير (سابق)', '0660445566', 60000.00, 54000.00, 'ancien', 'endette', '2026-07-20', 'غرامة سير معلقة']);
        // Former 3: 0 DZD (À jour)
        $stmtDebt->execute([null, 'عبد الحق بوزيد (سابق)', '0777889900', 120000.00, 120000.00, 'ancien', 'a_jour', '2026-08-01', 'مخالصة نهائية']);

        // Seed initial versements log
        $stmtVers = $pdo->prepare("
            INSERT INTO versements (debt_id, amount, payment_method, payment_date, receipt_ref, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtVers->execute([$d1Id, 45000.00, 'baridimob', date('Y-m-d', strtotime('-4 days')), 'VER-2026-081', 'دفعة شهر سبتمبر']);
        $stmtVers->execute([$d2Id, 50000.00, 'ccp', date('Y-m-d', strtotime('-8 days')), 'VER-2026-079', 'تسديد نقدي بالحساب']);
    }

    // 8. Seed Garde de nuit du parc
    $nightCount = $pdo->query("SELECT COUNT(*) FROM night_watch")->fetchColumn();
    if ($nightCount == 0) {
        $stmtNight = $pdo->prepare("
            INSERT INTO night_watch (vehicle_id, driver_id, guardian_name, park_slot, entry_time, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtNight->execute([$v1Id, $driver1Id, 'عمي عيسى (حارس ليلي)', 'موقف A-04', date('Y-m-d 20:30:00'), 'in_park', 'السيارة مغلقة والعداد مطفأ']);
        $stmtNight->execute([$v2Id, $driver2Id, 'عمي عيسى (حارس ليلي)', 'موقف A-08', date('Y-m-d 21:15:00'), 'in_park', 'مفتاح بحوزة الحراسة']);
        $stmtNight->execute([$v3Id, $driver3Id, 'عمي رابح (حارس المداومة)', 'موقف B-02', date('Y-m-d 19:45:00'), 'in_park', 'جاهزة للمداومة الصباحية']);
    }

    // 9. Seed Planning des chauffeurs (3 jours) & Permanence
    $schedCount = $pdo->query("SELECT COUNT(*) FROM driver_schedules")->fetchColumn();
    if ($schedCount == 0) {
        $stmtSched = $pdo->prepare("
            INSERT INTO driver_schedules (driver_id, vehicle_id, shift_date, shift_type, is_permanence)
            VALUES (?, ?, ?, ?, ?)
        ");
        // Today (J+0)
        $today = date('Y-m-d');
        $stmtSched->execute([$driver1Id, $v1Id, $today, 'matin', 0]);
        $stmtSched->execute([$driver2Id, $v2Id, $today, 'soir', 0]);
        $stmtSched->execute([$driver3Id, $v3Id, $today, 'nuit', 1]); // Permanence!

        // Tomorrow (J+1)
        $day1 = date('Y-m-d', strtotime('+1 day'));
        $stmtSched->execute([$driver1Id, $v1Id, $day1, 'matin', 0]);
        $stmtSched->execute([$driver3Id, $v3Id, $day1, 'soir', 1]);
        $stmtSched->execute([$driver2Id, $v2Id, $day1, 'nuit', 0]);

        // Day after tomorrow (J+2)
        $day2 = date('Y-m-d', strtotime('+2 days'));
        $stmtSched->execute([$driver2Id, $v2Id, $day2, 'matin', 0]);
        $stmtSched->execute([$driver1Id, $v1Id, $day2, 'soir', 0]);
        $stmtSched->execute([$driver3Id, $v3Id, $day2, 'nuit', 1]);
    }

    // 10. Seed Insurance Policies (Gérer Assurance)
    $insCount = $pdo->query("SELECT COUNT(*) FROM insurance_policies")->fetchColumn();
    if ($insCount == 0) {
        $stmtIns = $pdo->prepare("
            INSERT INTO insurance_policies (vehicle_id, policy_number, company, coverage_type, start_date, end_date, premium_amount, attestation_number, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([
            $v1Id ?? 1, 'POL-SAA-2026-9901', 'SAA', 'Tous Risques + Transport Public Voyageurs',
            date('Y-m-d', strtotime('-290 days')), date('Y-m-d', strtotime('+75 days')),
            38000.00, 'ATT-SAA-16-042', 'active'
        ]);
        $stmtIns->execute([
            $v2Id ?? 2, 'POL-CAAT-2026-4421', 'CAAT', 'Responsabilité Civile + Défense & Recours',
            date('Y-m-d', strtotime('-355 days')), date('Y-m-d', strtotime('+10 days')), // Imminent expiry (< 15 days)
            31500.00, 'ATT-CAAT-16-088', 'active'
        ]);
        $stmtIns->execute([
            $v3Id ?? 3, 'POL-CIAR-2026-7812', 'CIAR', 'Tous Risques Flotte Taxi',
            date('Y-m-d', strtotime('-250 days')), date('Y-m-d', strtotime('+110 days')),
            42000.00, 'ATT-CIAR-16-105', 'active'
        ]);
    }

    // 11. Seed Driver Subscriptions (Abonnements & Facturation: Octobre 2026)
    $subCount = $pdo->query("SELECT COUNT(*) FROM driver_subscriptions")->fetchColumn();
    if ($subCount == 0) {
        $stmtSub = $pdo->prepare("
            INSERT INTO driver_subscriptions (driver_id, period_month, space_fee, vehicle_fee, vehicles_count, total_due, paid_amount, status, payment_date, payment_method, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtSub->execute([
            $driver1Id ?? 1, '2026-10', 1000.00, 4000.00, 1, 5000.00, 5000.00, 'regle', '2026-10-02', 'baridimob', 'Règlement abonnement mensuel + véhicule'
        ]);
        $stmtSub->execute([
            $driver2Id ?? 2, '2026-10', 1000.00, 4000.00, 1, 5000.00, 0.00, 'en_attente', null, 'ccp', 'En attente de versement CCP'
        ]);
        $stmtSub->execute([
            $driver3Id ?? 3, '2026-10', 1000.00, 4000.00, 1, 5000.00, 0.00, 'en_attente', null, 'baridimob', 'En attente de virement'
        ]);
    }

    // 12. Seed System Notifications (الإشعارات والتنبيهات)
    $notifCount = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    if ($notifCount == 0) {
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (type, title, message, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE)
        ");
        $stmtNotif->execute([
            'urgent',
            'مراقبة تقنية وشيكة الانتهاء (6 أيام)',
            'المركبة Dacia Logan (00892-122-16) تنتهي صلاحية مراقبتها التقنية خلال 6 أيام. يرجى التوجه لمحطة المراقبة.',
            '/dashboard/vehicles.php?filter=expiring',
            0,
            15
        ]);
        $stmtNotif->execute([
            'alert',
            'انتهاء صلاحية تأمين CAAT وشيك',
            'بوليصة التأمين لسيارة الأجرة 00892-122-16 تنتهي خلال 10 أيام. اتخاذ الإجراء لتفادي حجز المركبة.',
            '/dashboard/insurance.php?status=imminent',
            0,
            45
        ]);
        $stmtNotif->execute([
            'warning',
            'فحص طبي دوري لسائق بحاجة للتجديد',
            'الشهادة الطبية الدورية للسائق أحمد بلقاسم بحاجة للتجديد قبل نهاية الشهر وفقاً لتعليمات مديرية النقل.',
            '/dashboard/drivers.php?filter=all',
            0,
            120
        ]);
        $stmtNotif->execute([
            'info',
            'استحقاقات الاشتراكات لشهر أكتوبر 2026',
            'تم فتح دورة تحصيل اشتراكات شهر أكتوبر: 1 000 دج فضاء السائق + 4 000 دج للمركبة النشطة.',
            '/dashboard/billing.php',
            0,
            240
        ]);
    }
}

function getUnreadNotificationsCount(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function getRecentNotifications(PDO $pdo, int $limit = 5): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}
