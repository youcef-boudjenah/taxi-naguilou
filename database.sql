-- قاعدة بيانات منصة تاكسي نقيلو (TAXI-NAGUILOU)
CREATE DATABASE IF NOT EXISTS `taxi_naguilou` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `taxi_naguilou`;

-- 1. المستخدمون (المشرفون، الملاك، السائقون والبطاقة الفنية المهنية)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `card_number` VARCHAR(50) NULL,
    `avatar_url` VARCHAR(255) NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `first_name` VARCHAR(80) NULL,
    `last_name` VARCHAR(80) NULL,
    `email` VARCHAR(150) UNIQUE NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `role` ENUM('super_admin', 'admin', 'owner', 'driver') DEFAULT 'driver',
    `status` ENUM('active', 'pending', 'suspended') DEFAULT 'active',
    `driver_type` VARCHAR(50) DEFAULT 'ajir',
    `activity_start_date` DATE NULL,
    `activity_status` VARCHAR(50) DEFAULT 'active',
    `dtw_visa_date` DATE NULL,
    `wilaya` VARCHAR(100) DEFAULT '16 - الجزائر العاصمة',
    `birth_date` DATE NULL,
    `birth_place` VARCHAR(150) NULL,
    `father_name` VARCHAR(100) NULL,
    `mother_name` VARCHAR(100) NULL,
    `address` VARCHAR(255) NULL,
    `license_number` VARCHAR(50) NULL,
    `license_issue_date` DATE NULL,
    `license_issue_place` VARCHAR(100) NULL,
    `license_expiry` DATE NULL,
    `medical_cert_expiry` DATE NULL,
    `medical_exam_start` DATE NULL,
    `medical_exam_end` DATE NULL,
    `medical_visa_date` DATE NULL,
    `next_medical_start` DATE NULL,
    `next_medical_end` DATE NULL,
    `taxi_contract_start` DATE NULL,
    `taxi_contract_end` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. المركبات وسيارات الأجرة
CREATE TABLE IF NOT EXISTS `vehicles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `owner_id` INT NULL,
    `current_driver_id` INT NULL,
    `replacement_driver_id` INT NULL,
    `brand` VARCHAR(80) NOT NULL,
    `model` VARCHAR(80) NOT NULL,
    `plate_number` VARCHAR(50) UNIQUE NOT NULL,
    `color` VARCHAR(50) DEFAULT 'أصفر',
    `door_number` VARCHAR(50) NOT NULL,
    `insurance_expiry` DATE NULL,
    `insurance_company` VARCHAR(80) DEFAULT 'SAA',
    `technical_control_expiry` DATE NULL,
    `extinguisher_expiry` DATE NULL,
    `gpl_expiry` DATE NULL,
    `saccuriometre_expiry` DATE NULL,
    `saccuriometre_text` VARCHAR(100) DEFAULT 'معاير ومختوم',
    `status` ENUM('active', 'suspended', 'in_maintenance') DEFAULT 'active',
    `suspended_reason` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`current_driver_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`replacement_driver_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. عقود العمل (القانون 90-11)
CREATE TABLE IF NOT EXISTS `contracts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `contract_number` VARCHAR(50) UNIQUE NOT NULL,
    `driver_id` INT NOT NULL,
    `vehicle_id` INT NOT NULL,
    `owner_id` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `monthly_wage` DECIMAL(10, 2) DEFAULT 45000.00,
    `status` ENUM('draft', 'signed', 'terminated') DEFAULT 'signed',
    `signed_at` TIMESTAMP NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. العطل والسائق البديل
CREATE TABLE IF NOT EXISTS `leaves` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `driver_id` INT NOT NULL,
    `vehicle_id` INT NOT NULL,
    `replacement_driver_id` INT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`replacement_driver_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. الفوترة والاشتراكات (CCP / BaridiMob)
CREATE TABLE IF NOT EXISTS `billings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `paid_amount` DECIMAL(10, 2) DEFAULT 0.00,
    `period_month` VARCHAR(20) NOT NULL,
    `due_date` DATE NOT NULL,
    `payment_date` DATE NULL,
    `payment_method` ENUM('cash', 'baridimob', 'ccp', 'bank') DEFAULT 'baridimob',
    `status` ENUM('paid', 'pending', 'overdue') DEFAULT 'pending',
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. جداول الإرسال (Bordereaux de transmission)
CREATE TABLE IF NOT EXISTS `transmission_slips` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slip_number` VARCHAR(50) UNIQUE NOT NULL,
    `vehicle_id` INT NOT NULL,
    `driver_id` INT NOT NULL,
    `route_line` VARCHAR(200) NOT NULL,
    `issue_date` DATE NOT NULL,
    `valid_until` DATE NOT NULL,
    `status` ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. طلبات التسجيل من صفحة الهبوط
CREATE TABLE IF NOT EXISTS `leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `email` VARCHAR(150) NULL,
    `role_type` ENUM('owner', 'driver', 'agency', 'investor') DEFAULT 'owner',
    `wilaya` VARCHAR(100) NOT NULL,
    `fleet_size` INT DEFAULT 1,
    `message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. ديون ومستحقات السائقين (Finances & Dettes)
CREATE TABLE IF NOT EXISTS `driver_debts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `chauffeur_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `total_credits` DECIMAL(12, 2) DEFAULT 0.00,
    `total_versements` DECIMAL(12, 2) DEFAULT 0.00,
    `driver_type` ENUM('actif', 'ancien') DEFAULT 'actif',
    `status` ENUM('endette', 'a_jour') DEFAULT 'endette',
    `last_versement_date` DATE NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `versements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `debt_id` INT NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `payment_method` ENUM('especes', 'baridimob', 'ccp', 'virement') DEFAULT 'especes',
    `payment_date` DATE NOT NULL,
    `receipt_ref` VARCHAR(100) NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`debt_id`) REFERENCES `driver_debts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. حراسة الحظيرة والموقف الليلي (Garde de nuit du parc)
CREATE TABLE IF NOT EXISTS `night_watch` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `driver_id` INT NULL,
    `guardian_name` VARCHAR(100) NOT NULL,
    `park_slot` VARCHAR(50) DEFAULT 'Zone A',
    `entry_time` DATETIME NOT NULL,
    `exit_time` DATETIME NULL,
    `status` ENUM('in_park', 'sorti') DEFAULT 'in_park',
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. جدول مناوبات السائقين (3 أيام)
CREATE TABLE IF NOT EXISTS `driver_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `driver_id` INT NOT NULL,
    `vehicle_id` INT NOT NULL,
    `shift_date` DATE NOT NULL,
    `shift_type` ENUM('matin', 'soir', 'nuit') DEFAULT 'matin',
    `is_permanence` BOOLEAN DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. بوالص تأمين المركبات (Insurance Policies)
CREATE TABLE IF NOT EXISTS `insurance_policies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `policy_number` VARCHAR(100) NOT NULL,
    `company` VARCHAR(80) NOT NULL,
    `coverage_type` VARCHAR(100) DEFAULT 'Tous Risques + Transport Voyageurs',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `premium_amount` DECIMAL(10, 2) DEFAULT 32000.00,
    `attestation_number` VARCHAR(100) NULL,
    `status` ENUM('active', 'expired', 'renewed') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. اشتراكات السائقين (Driver Subscriptions)
CREATE TABLE IF NOT EXISTS `driver_subscriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `driver_id` INT NOT NULL,
    `period_month` VARCHAR(20) NOT NULL,
    `space_fee` DECIMAL(10, 2) DEFAULT 1000.00,
    `vehicle_fee` DECIMAL(10, 2) DEFAULT 4000.00,
    `vehicles_count` INT DEFAULT 1,
    `total_due` DECIMAL(10, 2) DEFAULT 5000.00,
    `paid_amount` DECIMAL(10, 2) DEFAULT 0.00,
    `status` ENUM('regle', 'en_attente', 'partiel') DEFAULT 'en_attente',
    `payment_date` DATE NULL,
    `payment_method` VARCHAR(50) DEFAULT 'ccp',
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. إشعارات وتنبيهات النظام (System Notifications)
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `type` ENUM('alert', 'warning', 'info', 'success', 'urgent') DEFAULT 'info',
    `title` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `link` VARCHAR(255) NULL,
    `is_read` BOOLEAN DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. إعدادات المنصة والهوية الجبائية (Settings)
CREATE TABLE IF NOT EXISTS `settings` (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL,
    `category` VARCHAR(50) DEFAULT 'general',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. سجل التدقيق والرقابة الأمنية (Security Audit Trail)
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `user_name` VARCHAR(150) NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity` VARCHAR(80) NOT NULL,
    `entity_id` INT NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. تصريحات الضمان الاجتماعي للعمال الأجراء (CNAS Loi 90-11)
CREATE TABLE IF NOT EXISTS `cnas_declarations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `driver_id` INT NOT NULL,
    `cnas_number` VARCHAR(50) NOT NULL,
    `affiliation_date` DATE NOT NULL,
    `declaration_type` ENUM('principale', 'complementaire', 'mise_a_jour') DEFAULT 'principale',
    `period_quarter` VARCHAR(30) NOT NULL,
    `base_salary` DECIMAL(10, 2) DEFAULT 45000.00,
    `cotisation_patronale` DECIMAL(10, 2) DEFAULT 11700.00,
    `cotisation_ouvriere` DECIMAL(10, 2) DEFAULT 4050.00,
    `total_cotisation` DECIMAL(10, 2) DEFAULT 15750.00,
    `payment_status` ENUM('regle', 'en_attente', 'retard') DEFAULT 'regle',
    `payment_date` DATE NULL,
    `receipt_ref` VARCHAR(100) NULL,
    `attestation_expiry` DATE NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. محاضر ودفاتر المسافرين بين الولايات (Traveler Manifests / PV de voyageurs)
CREATE TABLE IF NOT EXISTS `traveler_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `report_number` VARCHAR(50) UNIQUE NOT NULL,
    `vehicle_id` INT NOT NULL,
    `driver_id` INT NOT NULL,
    `line_name` VARCHAR(200) NOT NULL,
    `departure_station` VARCHAR(150) NOT NULL,
    `arrival_station` VARCHAR(150) NOT NULL,
    `departure_date` DATE NOT NULL,
    `departure_time` TIME NOT NULL,
    `seats_count` INT DEFAULT 4,
    `price_per_seat` DECIMAL(10, 2) DEFAULT 1200.00,
    `total_revenue` DECIMAL(10, 2) DEFAULT 4800.00,
    `status` ENUM('completed', 'in_transit', 'scheduled', 'cancelled') DEFAULT 'completed',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `traveler_passengers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `report_id` INT NOT NULL,
    `seat_number` INT NOT NULL,
    `passenger_name` VARCHAR(150) NOT NULL,
    `passenger_cni` VARCHAR(50) NOT NULL,
    `phone` VARCHAR(30) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`report_id`) REFERENCES `traveler_reports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

