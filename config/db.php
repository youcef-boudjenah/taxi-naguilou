<?php
// config/db.php - Central Database Configuration & Auto-Migration
if (!ob_get_level()) {
    ob_start();
}
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'taxi_naguilou');

/**
 * Dynamic App Base URL Resolution
 * Works seamlessly whether hosted at root (vhost http://taxi-naguilou.test/)
 * or in a subdirectory (http://localhost/taxi-naguilou/)
 */
function app_base_url(): string {
    static $baseUrl = null;
    if ($baseUrl !== null) {
        return $baseUrl;
    }

    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';

    if (!empty($docRoot) && strpos(strtolower($projectRoot), strtolower($docRoot)) === 0) {
        $sub = trim(substr($projectRoot, strlen($docRoot)), '/');
        $baseUrl = $sub !== '' ? '/' . $sub : '';
    } else {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $folder = basename($projectRoot);
        if (strpos($scriptName, '/' . $folder . '/') === 0 || $scriptName === '/' . $folder) {
            $baseUrl = '/' . $folder;
        } else {
            $baseUrl = '';
        }
    }
    return $baseUrl;
}

function url(string $path = ''): string {
    $base = app_base_url();
    if ($path === '' || $path === '/') {
        return !empty($base) ? $base . '/' : '/';
    }
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function redirect(string $path): void {
    $target = url($path);
    if (!headers_sent()) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header("Location: " . $target);
        exit;
    }
    echo '<script>window.location.replace(' . json_encode($target) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"></noscript>';
    exit;
}

function getDBLastError(): ?string {
    global $g_dbLastError;
    return $g_dbLastError ?? null;
}

function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    global $g_dbLastError;
    $g_dbLastError = null;

    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 4
    ];
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $pdoOptions[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
    }

    // 1. Direct connection to target database (Standard for Bluehost, cPanel & production)
    try {
        $dsnWithDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsnWithDb, DB_USER, DB_PASS, $pdoOptions);

        initAllTables($pdo);
        migrateDriverFicheFields($pdo);
        seedDefaultData($pdo);

        return $pdo;
    } catch (\Throwable $directEx) {
        $g_dbLastError = $directEx->getMessage();

        // 2. Fallback: attempt to connect without dbname and create database (for Localhost / Laragon dev)
        try {
            $dsnWithoutDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
            $rawPdo = new PDO($dsnWithoutDb, DB_USER, DB_PASS, $pdoOptions);
            $rawPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $dsnWithDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsnWithDb, DB_USER, DB_PASS, $pdoOptions);

            initAllTables($pdo);
            migrateDriverFicheFields($pdo);
            seedDefaultData($pdo);

            $g_dbLastError = null;
            return $pdo;
        } catch (\Throwable $fallbackEx) {
            error_log("DB Connection Warning: Direct failed: " . $directEx->getMessage() . " | Fallback failed: " . $fallbackEx->getMessage());
            return null;
        }
    }
}

function initAllTables(PDO $pdo) {
    static $alreadyRun = false;
    if ($alreadyRun) {
        return;
    }
    $alreadyRun = true;

    // Check if key tables already exist to avoid running schema queries repeatedly
    $keyTables = [
        'users', 'vehicles', 'contracts', 'leaves', 'billings', 
        'transmission_slips', 'driver_debts', 'versements', 'night_watch', 
        'driver_schedules', 'insurance_policies', 'driver_subscriptions', 
        'leads', 'notifications', 'settings', 'audit_logs', 
        'cnas_declarations', 'traveler_reports', 'traveler_passengers'
    ];

    try {
        $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existing = array_map('strtolower', $existing);
        $missing = array_diff($keyTables, $existing);
        if (empty($missing)) {
            return; // All required tables already exist!
        }
    } catch (\Throwable $e) {
        // Continue to table creation
    }

    $schema = "
    -- Users Table (Admins, Owners, Drivers & Fiche Technique)
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        card_number VARCHAR(50) NULL,
        avatar_url VARCHAR(255) NULL,
        full_name VARCHAR(150) NOT NULL,
        first_name VARCHAR(80) NULL,
        last_name VARCHAR(80) NULL,
        email VARCHAR(150) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        role ENUM('super_admin', 'admin', 'owner', 'driver') DEFAULT 'driver',
        status ENUM('active', 'pending', 'suspended') DEFAULT 'active',
        driver_type VARCHAR(50) DEFAULT 'ajir',
        probation_stage VARCHAR(50) DEFAULT 'trial_1',
        activity_start_date DATE NULL,
        activity_status VARCHAR(50) DEFAULT 'active',
        dtw_visa_date DATE NULL,
        wilaya VARCHAR(100) DEFAULT '16 - الجزائر العاصمة',
        birth_date DATE NULL,
        birth_place VARCHAR(150) NULL,
        father_name VARCHAR(100) NULL,
        mother_name VARCHAR(100) NULL,
        address VARCHAR(255) NULL,
        license_number VARCHAR(50) NULL,
        license_issue_date DATE NULL,
        license_issue_place VARCHAR(100) NULL,
        license_expiry DATE NULL,
        medical_cert_expiry DATE NULL,
        medical_exam_start DATE NULL,
        medical_exam_end DATE NULL,
        medical_visa_date DATE NULL,
        next_medical_start DATE NULL,
        next_medical_end DATE NULL,
        taxi_contract_start DATE NULL,
        taxi_contract_end DATE NULL,
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
        gpl_text VARCHAR(100) NULL,
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

    -- Platform Settings (إعدادات المنصة والهوية الجبائية والقانونية والبنكية)
    CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(100) PRIMARY KEY,
        `value` TEXT NOT NULL,
        `category` VARCHAR(50) DEFAULT 'general',
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Audit Logs (سجل التدقيق والرقابة الأمنية)
    CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        user_name VARCHAR(150) NULL,
        action VARCHAR(100) NOT NULL,
        entity VARCHAR(80) NOT NULL,
        entity_id INT NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- CNAS Social Security Declarations (تأمينات الضمان الاجتماعي للعمال الأجراء 90-11)
    CREATE TABLE IF NOT EXISTS cnas_declarations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        cnas_number VARCHAR(50) NOT NULL,
        affiliation_date DATE NOT NULL,
        declaration_type ENUM('principale', 'complementaire', 'mise_a_jour') DEFAULT 'principale',
        period_quarter VARCHAR(30) NOT NULL,
        base_salary DECIMAL(10, 2) DEFAULT 45000.00,
        cotisation_patronale DECIMAL(10, 2) DEFAULT 11700.00,
        cotisation_ouvriere DECIMAL(10, 2) DEFAULT 4050.00,
        total_cotisation DECIMAL(10, 2) DEFAULT 15750.00,
        payment_status ENUM('regle', 'en_attente', 'retard') DEFAULT 'regle',
        payment_date DATE NULL,
        receipt_ref VARCHAR(100) NULL,
        attestation_expiry DATE NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Traveler Reports / Inter-Wilaya Trip Manifests (محاضر ودفاتر المسافرين بين الولايات)
    CREATE TABLE IF NOT EXISTS traveler_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_number VARCHAR(50) UNIQUE NOT NULL,
        vehicle_id INT NOT NULL,
        driver_id INT NOT NULL,
        line_name VARCHAR(200) NOT NULL,
        departure_station VARCHAR(150) NOT NULL,
        arrival_station VARCHAR(150) NOT NULL,
        departure_date DATE NOT NULL,
        departure_time TIME NOT NULL,
        seats_count INT DEFAULT 4,
        price_per_seat DECIMAL(10, 2) DEFAULT 1200.00,
        total_revenue DECIMAL(10, 2) DEFAULT 4800.00,
        status ENUM('completed', 'in_transit', 'scheduled', 'cancelled') DEFAULT 'completed',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Manifest Passengers (قائمة ركاب محضر السفر)
    CREATE TABLE IF NOT EXISTS traveler_passengers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        seat_number INT NOT NULL,
        passenger_name VARCHAR(150) NOT NULL,
        passenger_cni VARCHAR(50) NOT NULL,
        phone VARCHAR(30) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (report_id) REFERENCES traveler_reports(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $queries = array_filter(array_map('trim', explode(';', $schema)));
    foreach ($queries as $q) {
        if (!empty($q)) {
            try {
                $pdo->exec($q);
            } catch (\Throwable $e) {}
        }
    }
}

function migrateDriverFicheFields(PDO $pdo): void {
    // 1. Users table columns
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $colMap = array_flip($cols);

        $addCols = [
            'card_number' => "VARCHAR(50) NULL AFTER `id`",
            'avatar_url' => "VARCHAR(255) NULL AFTER `card_number`",
            'first_name' => "VARCHAR(80) NULL AFTER `full_name`",
            'last_name' => "VARCHAR(80) NULL AFTER `first_name`",
            'driver_type' => "VARCHAR(50) DEFAULT 'ajir' AFTER `status`",
            'probation_stage' => "VARCHAR(50) DEFAULT 'trial_1' AFTER `driver_type`",
            'activity_start_date' => "DATE NULL AFTER `probation_stage`",
            'activity_status' => "VARCHAR(50) DEFAULT 'active' AFTER `activity_start_date`",
            'dtw_visa_date' => "DATE NULL AFTER `activity_status`",
            'birth_date' => "DATE NULL AFTER `wilaya`",
            'birth_place' => "VARCHAR(150) NULL AFTER `birth_date`",
            'father_name' => "VARCHAR(100) NULL AFTER `birth_place`",
            'mother_name' => "VARCHAR(100) NULL AFTER `father_name`",
            'address' => "VARCHAR(255) NULL AFTER `mother_name`",
            'license_issue_date' => "DATE NULL AFTER `license_number`",
            'license_issue_place' => "VARCHAR(100) NULL AFTER `license_issue_date`",
            'medical_cert_expiry' => "DATE NULL AFTER `license_expiry`",
            'medical_exam_start' => "DATE NULL AFTER `medical_cert_expiry`",
            'medical_exam_end' => "DATE NULL AFTER `medical_exam_start`",
            'medical_visa_date' => "DATE NULL AFTER `medical_exam_end`",
            'next_medical_start' => "DATE NULL AFTER `medical_visa_date`",
            'next_medical_end' => "DATE NULL AFTER `next_medical_start`",
            'taxi_contract_start' => "DATE NULL AFTER `next_medical_end`",
            'taxi_contract_end' => "DATE NULL AFTER `taxi_contract_start`"
        ];

        foreach ($addCols as $col => $def) {
            if (!isset($colMap[$col])) {
                try {
                    $pdo->exec("ALTER TABLE `users` ADD COLUMN `$col` $def");
                } catch (\Throwable $ex) {}
            }
        }
    } catch (\Throwable $e) {
        error_log("Users table migration notice: " . $e->getMessage());
    }

    // 2. Vehicles table columns
    try {
        $vCols = $pdo->query("SHOW COLUMNS FROM `vehicles`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $vColMap = array_flip($vCols);

        $addVCols = [
            'owner_id' => "INT NULL AFTER `id`",
            'current_driver_id' => "INT NULL AFTER `owner_id`",
            'replacement_driver_id' => "INT NULL AFTER `current_driver_id`",
            'insurance_company' => "VARCHAR(80) DEFAULT 'SAA' AFTER `insurance_expiry`",
            'technical_control_expiry' => "DATE NULL AFTER `insurance_company`",
            'extinguisher_expiry' => "DATE NULL AFTER `technical_control_expiry`",
            'gpl_expiry' => "DATE NULL AFTER `extinguisher_expiry`",
            'gpl_text' => "VARCHAR(100) NULL AFTER `gpl_expiry`",
            'saccuriometre_expiry' => "DATE NULL AFTER `gpl_text`",
            'saccuriometre_text' => "VARCHAR(100) DEFAULT 'معاير ومختوم' AFTER `saccuriometre_expiry`",
            'suspended_reason' => "VARCHAR(255) NULL AFTER `status`"
        ];

        foreach ($addVCols as $col => $def) {
            if (!isset($vColMap[$col])) {
                try {
                    $pdo->exec("ALTER TABLE `vehicles` ADD COLUMN `$col` $def");
                } catch (\Throwable $ex) {}
            }
        }
    } catch (\Throwable $e) {
        error_log("Vehicles table migration notice: " . $e->getMessage());
    }

    // 3. Leaves table columns
    try {
        $lCols = $pdo->query("SHOW COLUMNS FROM `leaves`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $lColMap = array_flip($lCols);
        if (!isset($lColMap['replacement_driver_id'])) {
            try {
                $pdo->exec("ALTER TABLE `leaves` ADD COLUMN `replacement_driver_id` INT NULL AFTER `vehicle_id`");
            } catch (\Throwable $ex) {}
        }
    } catch (\Throwable $e) {}

    // 4. Driver Schedules table columns
    try {
        $dsCols = $pdo->query("SHOW COLUMNS FROM `driver_schedules`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $dsColMap = array_flip($dsCols);
        if (!isset($dsColMap['is_permanence'])) {
            try {
                $pdo->exec("ALTER TABLE `driver_schedules` ADD COLUMN `is_permanence` BOOLEAN DEFAULT 0 AFTER `shift_type`");
            } catch (\Throwable $ex) {}
        }
    } catch (\Throwable $e) {}
}

function seedDefaultData(PDO $pdo) {
    try {
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

        // 8. Seed Garde de nuit du parc
        $nightCount = $pdo->query("SELECT COUNT(*) FROM night_watch")->fetchColumn();
        if ($nightCount == 0 && !empty($v1Id)) {
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
    if ($schedCount == 0 && !empty($v1Id)) {
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

        // 14. Seed CNAS Social Security Declarations (الضمان الاجتماعي 90-11)
        $cnasCount = $pdo->query("SELECT COUNT(*) FROM cnas_declarations")->fetchColumn();
        if ($cnasCount == 0) {
            $stmtCnas = $pdo->prepare("
                INSERT INTO cnas_declarations (driver_id, cnas_number, affiliation_date, declaration_type, period_quarter, base_salary, cotisation_patronale, cotisation_ouvriere, total_cotisation, payment_status, payment_date, receipt_ref, attestation_expiry, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtCnas->execute([
                $driver1Id ?? 3, '16-84910294-88', '2022-03-15', 'principale', 'T3-2026 (جويلية - سبتمبر)',
                45000.00, 11700.00, 4050.00, 15750.00, 'regle', '2026-09-28', 'QUITT-CNAS-16-9921',
                date('Y-m-d', strtotime('+80 days')), 'شهادة تحيين سارية ومطابقة لقانون العمل 90-11'
            ]);
            $stmtCnas->execute([
                $driver2Id ?? 4, '16-91024811-45', '2023-01-10', 'principale', 'T3-2026 (جويلية - سبتمبر)',
                48000.00, 12480.00, 4320.00, 16800.00, 'regle', '2026-09-30', 'QUITT-CNAS-16-9954',
                date('Y-m-d', strtotime('+75 days')), 'تسوية فصلية تامة لدى وكالة تيقصراين'
            ]);
            $stmtCnas->execute([
                $driver3Id ?? 5, '16-77381920-12', '2024-05-01', 'principale', 'T3-2026 (جويلية - سبتمبر)',
                45000.00, 11700.00, 4050.00, 15750.00, 'en_attente', null, null,
                date('Y-m-d', strtotime('+12 days')), 'استحقاق دفع ثلاثي وشيك - إشعار موجه للإدارة'
            ]);
        }

        // 15. Seed Traveler Reports (محاضر ودفاتر المسافرين بين الولايات)
        $repCount = $pdo->query("SELECT COUNT(*) FROM traveler_reports")->fetchColumn();
        if ($repCount == 0) {
            $stmtRep = $pdo->prepare("
                INSERT INTO traveler_reports (report_number, vehicle_id, driver_id, line_name, departure_station, arrival_station, departure_date, departure_time, seats_count, price_per_seat, total_revenue, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Trip 1: Alger - Oran
            $stmtRep->execute([
                'PV-2026-1042', $v1Id ?? 1, $driver1Id ?? 3,
                'خط: الجزائر العاصمة ⇄ وهران (الباهية)',
                'محطة نقل المسافرين خروبة (الجزائر)', 'محطة الحطاب / الباهية (وهران)',
                date('Y-m-d'), '06:30:00', 4, 1500.00, 6000.00, 'completed',
                'رحلة صباحية مباشرة عبر الطريق السيار شرق-غرب دون حوادث'
            ]);
            $rep1Id = $pdo->lastInsertId();

            $stmtPass = $pdo->prepare("
                INSERT INTO traveler_passengers (report_id, seat_number, passenger_name, passenger_cni, phone)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtPass->execute([$rep1Id, 1, 'مراد شريفي', '11827401928374', '0551223344']);
            $stmtPass->execute([$rep1Id, 2, 'سميرة بن عودة', '10938472910293', '0662334455']);
            $stmtPass->execute([$rep1Id, 3, 'محمد طاهر رحماني', '12093847192847', '0773445566']);
            $stmtPass->execute([$rep1Id, 4, 'فاطمة الزهراء قاسمي', '11548392019283', '0554556677']);

            // Trip 2: Alger - Constantine
            $stmtRep->execute([
                'PV-2026-1043', $v3Id ?? 3, $driver3Id ?? 5,
                'خط: الجزائر العاصمة ⇄ قسنطينة (مدينة الجسور)',
                'محطة خروبة (الجزائر)', 'محطة زواغي سليمان (قسنطينة)',
                date('Y-m-d', strtotime('+1 day')), '07:00:00', 4, 1400.00, 5600.00, 'scheduled',
                'رحلة مبرمجة لمناوبة السائق البديل'
            ]);
            $rep2Id = $pdo->lastInsertId();
            $stmtPass->execute([$rep2Id, 1, 'عبد النور براهيمي', '11928374659281', '0665667788']);
            $stmtPass->execute([$rep2Id, 2, 'كمال عماري', '11483920192837', '0776778899']);
        }

        // 16. Seed Audit Logs (سجل التدقيق والرقابة)
        $auditCount = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
        if ($auditCount == 0) {
            $stmtAud = $pdo->prepare("
                INSERT INTO audit_logs (user_id, user_name, action, entity, entity_id, details, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? MINUTE)
            ");
            $stmtAud->execute([1, 'مدير المنصة', 'login_success', 'auth', 1, 'تسجيل دخول ناجح إلى لوحة التحكم', '127.0.0.1', 180]);
            $stmtAud->execute([1, 'مدير المنصة', 'contract_signed', 'contracts', 1, 'توثيق العقد النظامي رقم CT-2026-001 للسائق أحمد بلقاسم', '127.0.0.1', 120]);
            $stmtAud->execute([1, 'مدير المنصة', 'slip_issued', 'transmission_slips', 1, 'إصدار جدول إرسال ومناوبة رقم BRD-2026-0042', '127.0.0.1', 60]);
            $stmtAud->execute([1, 'مدير المنصة', 'billing_payment', 'billings', 1, 'تأكيد استلام اشتراك شهري عبر BaridiMob بمبلغ 4,500 دج', '127.0.0.1', 25]);
        }

    } // End if ($usersCount == 0)

    // 13. Seed Platform Settings (إعدادات المنصة واشتراكات SAAS والهوية الجبائية)
    $settingsCount = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($settingsCount == 0) {
        $stmtSet = $pdo->prepare("INSERT INTO settings (`key`, `value`, `category`) VALUES (?, ?, ?)");
        $defaultSettings = [
            ['company_name', 'EURL TAXI NAGUILOU Services', 'company'],
            ['company_slogan', 'المنصة الوطنية الرائدة في تسيير أساطيل سيارات الأجرة ونقل الأشخاص', 'company'],
            ['company_phone', '0550 00 00 01 / 023 45 67 89', 'company'],
            ['company_email', 'contact@taxinaguilou.dz', 'company'],
            ['company_address', 'حي النصر، نهج الاستقلال، الجزائر العاصمة - الجزائر', 'company'],
            ['legal_rc', '16/00-9874521B26', 'legal'],
            ['legal_nif', '002616098745210', 'legal'],
            ['legal_nis', '00261609874', 'legal'],
            ['legal_article', '16200984123', 'legal'],
            ['bank_ccp', '0021458796', 'bank'],
            ['bank_rip', '00799999002145879624', 'bank'],
            ['bank_name', 'بنك الفلاحة والتنمية الريفية (BADR) - وكالة الجزائر الوسطى', 'bank'],
            ['bank_rib', '003 00016 0160004589 77', 'bank'],
            ['fee_space_monthly', '1000.00', 'billing'],
            ['fee_vehicle_monthly', '5000.00', 'billing'],
            ['discount_prepay_3m', '500.00', 'billing'],
            ['discount_prepay_6m', '1000.00', 'billing'],
            ['discount_prepay_9m', '1500.00', 'billing'],
            ['discount_prepay_12m', '2000.00', 'billing'],
            ['alert_threshold_days', '30', 'alerts'],
            ['app_timezone', 'Africa/Algiers', 'system'],
            ['app_currency', 'دج (DZD)', 'system'],
            ['douxe_ai_enabled', '1', 'ai']
        ];
        foreach ($defaultSettings as $s) {
            $stmtSet->execute($s);
        }
    }
    } catch (\Throwable $e) {
        error_log("seedDefaultData warning: " . $e->getMessage());
    }
}

function log_audit(...$args): bool {
    try {
        $pdo = null;
        $action = '';
        $entity = '';
        $entityId = null;
        $details = null;

        if (isset($args[0]) && $args[0] instanceof PDO) {
            $pdo = $args[0];
            $action = (string)($args[1] ?? 'action');
            $entity = (string)($args[2] ?? 'general');
            $entityId = isset($args[3]) && $args[3] !== null ? (int)$args[3] : null;
            $details = $args[4] ?? null;
        } else {
            $action = (string)($args[0] ?? 'action');
            $entity = (string)($args[1] ?? 'general');
            $entityId = isset($args[2]) && $args[2] !== null ? (int)$args[2] : null;
            if (isset($args[4])) {
                $details = $args[4];
            } elseif (isset($args[3])) {
                $details = $args[3];
            }
            $pdo = getDBConnection();
        }

        if (!$pdo) {
            return false;
        }

        if (is_array($details) || is_object($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE);
        } elseif ($details !== null) {
            $details = (string)$details;
        }

        $user = function_exists('current_user') ? current_user() : null;
        $userId = $user['id'] ?? null;
        $userName = $user['name'] ?? 'النظام';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, user_name, action, entity, entity_id, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$userId, $userName, $action, $entity, $entityId, $details, $ip]);
    } catch (\Throwable $e) {
        error_log("log_audit warning: " . $e->getMessage());
        return false;
    }
}

function get_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function update_setting(PDO $pdo, string $key, string $value, string $category = 'general'): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (`key`, `value`, `category`) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `category` = VALUES(`category`), `updated_at` = NOW()
        ");
        return $stmt->execute([$key, $value, $category]);
    } catch (Exception $e) {
        return false;
    }
}

function get_all_settings(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT `key`, `value`, `category` FROM settings")->fetchAll();
        $res = [];
        foreach ($rows as $r) {
            $res[$r['key']] = $r['value'];
        }
        return $res;
    } catch (Exception $e) {
        return [];
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

