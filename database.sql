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
    `qr_token` VARCHAR(64) UNIQUE NULL,
    `phone` VARCHAR(30) NOT NULL,
    `role` ENUM('super_admin', 'admin', 'owner', 'driver') DEFAULT 'driver',
    `status` ENUM('active', 'pending', 'suspended') DEFAULT 'active',
    `subscription_type` VARCHAR(30) DEFAULT 'unlimited',
    `subscription_expires_at` DATE NULL,
    `custom_vehicle_fee` DECIMAL(10, 2) NULL DEFAULT NULL,
    `driver_type` VARCHAR(50) DEFAULT 'ajir',
    `activity_start_date` DATE NULL,
    `activity_status` VARCHAR(50) DEFAULT 'active',
    `probation_stage` VARCHAR(50) DEFAULT 'trial_1',
    `dtw_visa_date` DATE NULL,
    `wilaya` VARCHAR(100) DEFAULT 'ادرار',
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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL
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
    `qr_code` VARCHAR(64) UNIQUE NULL,
    `insurance_expiry` DATE NULL,
    `insurance_company` VARCHAR(80) DEFAULT 'SAA',
    `technical_control_expiry` DATE NULL,
    `extinguisher_expiry` DATE NULL,
    `gpl_expiry` DATE NULL,
    `gpl_text` VARCHAR(100) NULL,
    `saccuriometre_expiry` DATE NULL,
    `saccuriometre_text` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('active', 'suspended', 'in_maintenance') DEFAULT 'active',
    `suspended_reason` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`current_driver_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`replacement_driver_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. عقود العمل (القانون 90-11)
CREATE TABLE IF NOT EXISTS `contracts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `contract_number` VARCHAR(50) UNIQUE NOT NULL,
    `driver_id` INT NOT NULL,
    `vehicle_id` INT NULL,
    `owner_id` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `monthly_wage` DECIMAL(10, 2) DEFAULT 45000.00,
    `status` ENUM('draft', 'signed', 'terminated') DEFAULT 'signed',
    `signed_at` TIMESTAMP NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `receipt_image` VARCHAR(255) NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `deleted_at` DATETIME NULL DEFAULT NULL,
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
    `deleted_at` DATETIME NULL DEFAULT NULL,
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

-- 18. تقييمات ركاب سيارات الأجرة (Passenger Ratings)
CREATE TABLE IF NOT EXISTS `driver_ratings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `driver_id` INT NULL,
    `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `cleanliness_rating` TINYINT UNSIGNED DEFAULT 5,
    `driving_rating` TINYINT UNSIGNED DEFAULT 5,
    `courtesy_rating` TINYINT UNSIGNED DEFAULT 5,
    `passenger_name` VARCHAR(150) NULL,
    `passenger_phone` VARCHAR(50) NULL,
    `comment` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ratings_vehicle` (`vehicle_id`),
    INDEX `idx_ratings_driver` (`driver_id`),
    CONSTRAINT `fk_ratings_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ratings_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. سجل مسح الباركود ورمز الاستجابة السريعة للمركبة (Vehicle QR Scans Log)
CREATE TABLE IF NOT EXISTS `vehicle_scans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `driver_id` INT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `scanned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_scans_vehicle` (`vehicle_id`),
    INDEX `idx_scans_driver` (`driver_id`),
    CONSTRAINT `fk_scans_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_scans_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. نظام المراسلة الداخلية بين الإدارة والسائقين (Internal Messages)
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sender_id` INT NOT NULL,
    `receiver_id` INT NOT NULL,
    `message` TEXT NOT NULL,
    `attachment_url` VARCHAR(255) NULL,
    `attachment_name` VARCHAR(255) NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_by_sender` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_by_receiver` TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_sender_receiver` (`sender_id`, `receiver_id`),
    INDEX `idx_receiver_read` (`receiver_id`, `is_read`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_msg_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. النشرة البريدية (Newsletter Subscriptions)
CREATE TABLE IF NOT EXISTS `newsletter` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `subscribed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dynamic Migrations for Pre-existing Databases (Safe on MySQL 5.7+ & 8.0+)
DROP PROCEDURE IF EXISTS `_migrate_taxi_all_new_columns`;
DELIMITER $$
CREATE PROCEDURE `_migrate_taxi_all_new_columns`()
BEGIN
    DECLARE cur_db VARCHAR(128);
    SET cur_db = DATABASE();

    -- Users columns
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'card_number') THEN
        ALTER TABLE `users` ADD COLUMN `card_number` VARCHAR(50) NULL AFTER `id`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_url') THEN
        ALTER TABLE `users` ADD COLUMN `avatar_url` VARCHAR(255) NULL AFTER `card_number`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'first_name') THEN
        ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(80) NULL AFTER `full_name`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_name') THEN
        ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(80) NULL AFTER `first_name`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'qr_token') THEN
        ALTER TABLE `users` ADD COLUMN `qr_token` VARCHAR(64) NULL AFTER `password_hash`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'subscription_type') THEN
        ALTER TABLE `users` ADD COLUMN `subscription_type` VARCHAR(30) DEFAULT 'unlimited' AFTER `status`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'subscription_expires_at') THEN
        ALTER TABLE `users` ADD COLUMN `subscription_expires_at` DATE NULL AFTER `subscription_type`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'custom_vehicle_fee') THEN
        ALTER TABLE `users` ADD COLUMN `custom_vehicle_fee` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `subscription_expires_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'driver_type') THEN
        ALTER TABLE `users` ADD COLUMN `driver_type` VARCHAR(50) DEFAULT 'ajir' AFTER `custom_vehicle_fee`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'probation_stage') THEN
        ALTER TABLE `users` ADD COLUMN `probation_stage` VARCHAR(50) DEFAULT 'trial_1' AFTER `driver_type`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'activity_start_date') THEN
        ALTER TABLE `users` ADD COLUMN `activity_start_date` DATE NULL AFTER `probation_stage`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'activity_status') THEN
        ALTER TABLE `users` ADD COLUMN `activity_status` VARCHAR(50) DEFAULT 'active' AFTER `activity_start_date`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'dtw_visa_date') THEN
        ALTER TABLE `users` ADD COLUMN `dtw_visa_date` DATE NULL AFTER `activity_status`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'taxi_contract_start') THEN
        ALTER TABLE `users` ADD COLUMN `taxi_contract_start` DATE NULL AFTER `dtw_visa_date`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'taxi_contract_end') THEN
        ALTER TABLE `users` ADD COLUMN `taxi_contract_end` DATE NULL AFTER `taxi_contract_start`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'wilaya') THEN
        ALTER TABLE `users` ADD COLUMN `wilaya` VARCHAR(100) DEFAULT '16 - الجزائر العاصمة' AFTER `taxi_contract_end`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'birth_date') THEN
        ALTER TABLE `users` ADD COLUMN `birth_date` DATE NULL AFTER `wilaya`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'birth_place') THEN
        ALTER TABLE `users` ADD COLUMN `birth_place` VARCHAR(150) NULL AFTER `birth_date`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'father_name') THEN
        ALTER TABLE `users` ADD COLUMN `father_name` VARCHAR(100) NULL AFTER `birth_place`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mother_name') THEN
        ALTER TABLE `users` ADD COLUMN `mother_name` VARCHAR(100) NULL AFTER `father_name`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'address') THEN
        ALTER TABLE `users` ADD COLUMN `address` VARCHAR(255) NULL AFTER `mother_name`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'license_number') THEN
        ALTER TABLE `users` ADD COLUMN `license_number` VARCHAR(50) NULL AFTER `address`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'license_issue_date') THEN
        ALTER TABLE `users` ADD COLUMN `license_issue_date` DATE NULL AFTER `license_number`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'license_issue_place') THEN
        ALTER TABLE `users` ADD COLUMN `license_issue_place` VARCHAR(100) NULL AFTER `license_issue_date`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'license_expiry') THEN
        ALTER TABLE `users` ADD COLUMN `license_expiry` DATE NULL AFTER `license_issue_place`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'medical_cert_expiry') THEN
        ALTER TABLE `users` ADD COLUMN `medical_cert_expiry` DATE NULL AFTER `license_expiry`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'medical_exam_start') THEN
        ALTER TABLE `users` ADD COLUMN `medical_exam_start` DATE NULL AFTER `medical_cert_expiry`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'medical_exam_end') THEN
        ALTER TABLE `users` ADD COLUMN `medical_exam_end` DATE NULL AFTER `medical_exam_start`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'medical_visa_date') THEN
        ALTER TABLE `users` ADD COLUMN `medical_visa_date` DATE NULL AFTER `medical_exam_end`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'next_medical_start') THEN
        ALTER TABLE `users` ADD COLUMN `next_medical_start` DATE NULL AFTER `medical_visa_date`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'next_medical_end') THEN
        ALTER TABLE `users` ADD COLUMN `next_medical_end` DATE NULL AFTER `next_medical_start`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `users` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- Vehicles columns
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'qr_code') THEN
        ALTER TABLE `vehicles` ADD COLUMN `qr_code` VARCHAR(64) NULL AFTER `door_number`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `vehicles` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- Leaves columns
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND COLUMN_NAME = 'replacement_driver_id') THEN
        ALTER TABLE `leaves` ADD COLUMN `replacement_driver_id` INT NULL AFTER `vehicle_id`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `leaves` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- Driver schedules columns
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_schedules' AND COLUMN_NAME = 'is_permanence') THEN
        ALTER TABLE `driver_schedules` ADD COLUMN `is_permanence` BOOLEAN DEFAULT 0 AFTER `shift_type`;
    END IF;

    -- Driver debts columns
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'updated_at') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;
    END IF;

    -- Versements columns
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND COLUMN_NAME = 'receipt_image') THEN
        ALTER TABLE `versements` ADD COLUMN `receipt_image` VARCHAR(255) NULL AFTER `notes`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE `versements` ADD COLUMN `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER `receipt_image`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `versements` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- Soft deletes across other tables
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'contracts' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `contracts` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_subscriptions' AND COLUMN_NAME = 'updated_at') THEN
        ALTER TABLE `driver_subscriptions` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_subscriptions' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `driver_subscriptions` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'insurance_policies' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `insurance_policies` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'transmission_slips' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `transmission_slips` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'cnas_declarations' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `cnas_declarations` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'traveler_reports' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `traveler_reports` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- Indexes
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'qr_token') THEN
        ALTER TABLE `users` ADD UNIQUE INDEX `qr_token` (`qr_token`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role_status') THEN
        ALTER TABLE `users` ADD INDEX `idx_users_role_status` (`role`, `status`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_phone') THEN
        ALTER TABLE `users` ADD INDEX `idx_users_phone` (`phone`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_lic_num') THEN
        ALTER TABLE `users` ADD INDEX `idx_users_lic_num` (`license_number`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_card_num') THEN
        ALTER TABLE `users` ADD INDEX `idx_users_card_num` (`card_number`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_lic_exp') THEN
        ALTER TABLE `users` ADD INDEX `idx_users_lic_exp` (`license_expiry`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_med_exp') THEN
        ALTER TABLE `users` ADD INDEX `idx_users_med_exp` (`medical_cert_expiry`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_deleted_at') THEN
        ALTER TABLE `users` ADD INDEX `idx_users_deleted_at` (`deleted_at`);
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'qr_code') THEN
        ALTER TABLE `vehicles` ADD UNIQUE INDEX `qr_code` (`qr_code`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_veh_status') THEN
        ALTER TABLE `vehicles` ADD INDEX `idx_veh_status` (`status`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_veh_tc_exp') THEN
        ALTER TABLE `vehicles` ADD INDEX `idx_veh_tc_exp` (`technical_control_expiry`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_veh_ins_exp') THEN
        ALTER TABLE `vehicles` ADD INDEX `idx_veh_ins_exp` (`insurance_expiry`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_veh_gpl_exp') THEN
        ALTER TABLE `vehicles` ADD INDEX `idx_veh_gpl_exp` (`gpl_expiry`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_veh_sac_exp') THEN
        ALTER TABLE `vehicles` ADD INDEX `idx_veh_sac_exp` (`saccuriometre_expiry`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_vehicles_deleted_at') THEN
        ALTER TABLE `vehicles` ADD INDEX `idx_vehicles_deleted_at` (`deleted_at`);
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'contracts' AND INDEX_NAME = 'idx_contracts_driver_status') THEN
        ALTER TABLE `contracts` ADD INDEX `idx_contracts_driver_status` (`driver_id`, `status`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'contracts' AND INDEX_NAME = 'idx_contracts_deleted_at') THEN
        ALTER TABLE `contracts` ADD INDEX `idx_contracts_deleted_at` (`deleted_at`);
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND INDEX_NAME = 'idx_leaves_repl_status_end') THEN
        ALTER TABLE `leaves` ADD INDEX `idx_leaves_repl_status_end` (`replacement_driver_id`, `status`, `end_date`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND INDEX_NAME = 'idx_leaves_deleted_at') THEN
        ALTER TABLE `leaves` ADD INDEX `idx_leaves_deleted_at` (`deleted_at`);
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND INDEX_NAME = 'idx_debts_user_status') THEN
        ALTER TABLE `driver_debts` ADD INDEX `idx_debts_user_status` (`user_id`, `status`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND INDEX_NAME = 'idx_driver_debts_deleted_at') THEN
        ALTER TABLE `driver_debts` ADD INDEX `idx_driver_debts_deleted_at` (`deleted_at`);
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND INDEX_NAME = 'idx_versements_deleted_at') THEN
        ALTER TABLE `versements` ADD INDEX `idx_versements_deleted_at` (`deleted_at`);
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notif_read_created') THEN
        ALTER TABLE `notifications` ADD INDEX `idx_notif_read_created` (`is_read`, `created_at`);
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_subscriptions' AND INDEX_NAME = 'idx_driver_subscriptions_deleted_at') THEN
        ALTER TABLE `driver_subscriptions` ADD INDEX `idx_driver_subscriptions_deleted_at` (`deleted_at`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'insurance_policies' AND INDEX_NAME = 'idx_insurance_policies_deleted_at') THEN
        ALTER TABLE `insurance_policies` ADD INDEX `idx_insurance_policies_deleted_at` (`deleted_at`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'transmission_slips' AND INDEX_NAME = 'idx_transmission_slips_deleted_at') THEN
        ALTER TABLE `transmission_slips` ADD INDEX `idx_transmission_slips_deleted_at` (`deleted_at`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'cnas_declarations' AND INDEX_NAME = 'idx_cnas_declarations_deleted_at') THEN
        ALTER TABLE `cnas_declarations` ADD INDEX `idx_cnas_declarations_deleted_at` (`deleted_at`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'traveler_reports' AND INDEX_NAME = 'idx_traveler_reports_deleted_at') THEN
        ALTER TABLE `traveler_reports` ADD INDEX `idx_traveler_reports_deleted_at` (`deleted_at`);
    END IF;

END $$
DELIMITER ;
CALL `_migrate_taxi_all_new_columns`();
DROP PROCEDURE IF EXISTS `_migrate_taxi_all_new_columns`;



