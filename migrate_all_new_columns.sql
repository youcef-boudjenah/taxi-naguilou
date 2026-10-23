-- ==============================================================================
-- TAXI-NAGUILOU — Consolidated Database Migration Script
-- ملف الترقية الشامل لقاعدة البيانات: إضافة جميع الأعمدة والجداول والفهارس الجديدة
-- Safe & Idempotent: Compatible with MySQL 5.7+, MySQL 8.0+, and MariaDB
-- Can be executed multiple times safely without errors or data loss.
-- ==============================================================================

USE `taxi_naguilou`;

-- ------------------------------------------------------------------------------
-- 1. NEW TABLES CREATION (IF NOT EXISTS)
-- إنشاء الجداول الإضافية الجديدة إذا لم تكن موجودة
-- ------------------------------------------------------------------------------

-- تقييمات ركاب سيارات الأجرة (Passenger Ratings)
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

-- سجل مسح الباركود ورمز الاستجابة السريعة للمركبة (Vehicle QR Scans Log)
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

-- نظام المراسلة الداخلية بين الإدارة والسائقين (Internal Messages)
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

-- النشرة البريدية (Newsletter Subscriptions)
CREATE TABLE IF NOT EXISTS `newsletter` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `subscribed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إعدادات المنصة والهوية الجبائية (Settings)
CREATE TABLE IF NOT EXISTS `settings` (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL,
    `category` VARCHAR(50) DEFAULT 'general',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------------------------
-- 2. DYNAMIC PROCEDURE FOR ALL NEW COLUMNS & INDEXES
-- إجراء ترقية ديناميكي يضمن عدم التكرار ويعمل بأمان تام على أي قاعدة بيانات سابقة
-- ------------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS `_migrate_taxi_all_new_columns`;

DELIMITER $$
CREATE PROCEDURE `_migrate_taxi_all_new_columns`()
BEGIN
    DECLARE cur_db VARCHAR(128);
    SET cur_db = DATABASE();

    -- =========================================================================
    -- A. أعمدة جدول المستخدمين (users)
    -- =========================================================================
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

    -- تسعيرة اشتراك المركبة المخصصة لكل سائق
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

    -- =========================================================================
    -- B. أعمدة جدول المركبات (vehicles)
    -- =========================================================================
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'owner_id') THEN
        ALTER TABLE `vehicles` ADD COLUMN `owner_id` INT NULL AFTER `id`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'current_driver_id') THEN
        ALTER TABLE `vehicles` ADD COLUMN `current_driver_id` INT NULL AFTER `owner_id`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'replacement_driver_id') THEN
        ALTER TABLE `vehicles` ADD COLUMN `replacement_driver_id` INT NULL AFTER `current_driver_id`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'brand') THEN
        ALTER TABLE `vehicles` ADD COLUMN `brand` VARCHAR(80) NOT NULL DEFAULT '' AFTER `replacement_driver_id`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'model') THEN
        ALTER TABLE `vehicles` ADD COLUMN `model` VARCHAR(80) NOT NULL DEFAULT '' AFTER `brand`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'plate_number') THEN
        ALTER TABLE `vehicles` ADD COLUMN `plate_number` VARCHAR(50) NOT NULL DEFAULT '' AFTER `model`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'color') THEN
        ALTER TABLE `vehicles` ADD COLUMN `color` VARCHAR(50) DEFAULT 'أصفر' AFTER `plate_number`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'door_number') THEN
        ALTER TABLE `vehicles` ADD COLUMN `door_number` VARCHAR(50) NOT NULL DEFAULT '' AFTER `color`;
    END IF;

    -- رمز الاستجابة السريعة للتحقق الميداني والملصق
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'qr_code') THEN
        ALTER TABLE `vehicles` ADD COLUMN `qr_code` VARCHAR(64) NULL AFTER `door_number`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'insurance_expiry') THEN
        ALTER TABLE `vehicles` ADD COLUMN `insurance_expiry` DATE NULL AFTER `qr_code`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'insurance_company') THEN
        ALTER TABLE `vehicles` ADD COLUMN `insurance_company` VARCHAR(80) DEFAULT 'SAA' AFTER `insurance_expiry`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'technical_control_expiry') THEN
        ALTER TABLE `vehicles` ADD COLUMN `technical_control_expiry` DATE NULL AFTER `insurance_company`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'extinguisher_expiry') THEN
        ALTER TABLE `vehicles` ADD COLUMN `extinguisher_expiry` DATE NULL AFTER `technical_control_expiry`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'gpl_expiry') THEN
        ALTER TABLE `vehicles` ADD COLUMN `gpl_expiry` DATE NULL AFTER `extinguisher_expiry`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'gpl_text') THEN
        ALTER TABLE `vehicles` ADD COLUMN `gpl_text` VARCHAR(100) NULL AFTER `gpl_expiry`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'saccuriometre_expiry') THEN
        ALTER TABLE `vehicles` ADD COLUMN `saccuriometre_expiry` DATE NULL AFTER `gpl_text`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'saccuriometre_text') THEN
        ALTER TABLE `vehicles` ADD COLUMN `saccuriometre_text` VARCHAR(100) DEFAULT NULL AFTER `saccuriometre_expiry`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE `vehicles` ADD COLUMN `status` ENUM('active', 'suspended', 'in_maintenance') DEFAULT 'active' AFTER `saccuriometre_text`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'suspended_reason') THEN
        ALTER TABLE `vehicles` ADD COLUMN `suspended_reason` VARCHAR(255) NULL AFTER `status`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `vehicles` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- =========================================================================
    -- C. أعمدة جدول العطل (leaves)
    -- =========================================================================
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND COLUMN_NAME = 'replacement_driver_id') THEN
        ALTER TABLE `leaves` ADD COLUMN `replacement_driver_id` INT NULL AFTER `vehicle_id`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `leaves` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- =========================================================================
    -- D. أعمدة جدول مناوبات السائقين (driver_schedules)
    -- =========================================================================
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_schedules' AND COLUMN_NAME = 'is_permanence') THEN
        ALTER TABLE `driver_schedules` ADD COLUMN `is_permanence` BOOLEAN DEFAULT 0 AFTER `shift_type`;
    END IF;

    -- =========================================================================
    -- E. أعمدة جدول ديون السائقين (driver_debts)
    -- =========================================================================
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'user_id') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `user_id` INT NULL AFTER `id`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'chauffeur_name') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `chauffeur_name` VARCHAR(150) NOT NULL AFTER `user_id`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'phone') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `phone` VARCHAR(30) NOT NULL AFTER `chauffeur_name`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'total_credits') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `total_credits` DECIMAL(12, 2) DEFAULT 0.00 AFTER `phone`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'total_versements') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `total_versements` DECIMAL(12, 2) DEFAULT 0.00 AFTER `total_credits`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'driver_type') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `driver_type` ENUM('actif', 'ancien') DEFAULT 'actif' AFTER `total_versements`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `status` ENUM('endette', 'a_jour') DEFAULT 'endette' AFTER `driver_type`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'last_versement_date') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `last_versement_date` DATE NULL AFTER `status`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'notes') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `notes` VARCHAR(255) NULL AFTER `last_versement_date`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'updated_at') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `driver_debts` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;
    END IF;

    -- =========================================================================
    -- F. أعمدة جدول دفعات تسديد الديون (versements)
    -- =========================================================================
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND COLUMN_NAME = 'receipt_image') THEN
        ALTER TABLE `versements` ADD COLUMN `receipt_image` VARCHAR(255) NULL AFTER `notes`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE `versements` ADD COLUMN `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER `receipt_image`;
    END IF;

    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND COLUMN_NAME = 'deleted_at') THEN
        ALTER TABLE `versements` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;
    END IF;

    -- =========================================================================
    -- G. أعمدة الحذف الناعم وباقي الجداول (Soft Deletes across other tables)
    -- =========================================================================
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

    -- =========================================================================
    -- H. إنشاء الفهارس لرفع الأداء (Performance & Safety Indexes)
    -- =========================================================================

    -- Users indexes
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

    -- Vehicles indexes
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

    -- Contracts indexes
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'contracts' AND INDEX_NAME = 'idx_contracts_driver_status') THEN
        ALTER TABLE `contracts` ADD INDEX `idx_contracts_driver_status` (`driver_id`, `status`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'contracts' AND INDEX_NAME = 'idx_contracts_deleted_at') THEN
        ALTER TABLE `contracts` ADD INDEX `idx_contracts_deleted_at` (`deleted_at`);
    END IF;

    -- Leaves indexes
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND INDEX_NAME = 'idx_leaves_repl_status_end') THEN
        ALTER TABLE `leaves` ADD INDEX `idx_leaves_repl_status_end` (`replacement_driver_id`, `status`, `end_date`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'leaves' AND INDEX_NAME = 'idx_leaves_deleted_at') THEN
        ALTER TABLE `leaves` ADD INDEX `idx_leaves_deleted_at` (`deleted_at`);
    END IF;

    -- Driver Debts indexes
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND INDEX_NAME = 'idx_debts_user_status') THEN
        ALTER TABLE `driver_debts` ADD INDEX `idx_debts_user_status` (`user_id`, `status`);
    END IF;
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'driver_debts' AND INDEX_NAME = 'idx_driver_debts_deleted_at') THEN
        ALTER TABLE `driver_debts` ADD INDEX `idx_driver_debts_deleted_at` (`deleted_at`);
    END IF;

    -- Versements indexes
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'versements' AND INDEX_NAME = 'idx_versements_deleted_at') THEN
        ALTER TABLE `versements` ADD INDEX `idx_versements_deleted_at` (`deleted_at`);
    END IF;

    -- Notifications indexes
    IF NOT EXISTS (SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = cur_db AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notif_read_created') THEN
        ALTER TABLE `notifications` ADD INDEX `idx_notif_read_created` (`is_read`, `created_at`);
    END IF;

    -- Soft deletes indexes on remaining tables
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

-- تنفيذ إجراء الترقية التلقائي
CALL `_migrate_taxi_all_new_columns`();

-- حذف الإجراء بعد الانتهاء للحفاظ على نظافة قاعدة البيانات
DROP PROCEDURE IF EXISTS `_migrate_taxi_all_new_columns`;


-- ------------------------------------------------------------------------------
-- 3. DATA REPAIRS & BACKFILLS (تعبئة الرموز المفقودة ومزامنة السائقين البدلاء)
-- ------------------------------------------------------------------------------

-- إنشاء رمز QR للمستخدمين الذين لا يملكون رمزاً
UPDATE `users` 
SET `qr_token` = MD5(CONCAT(`id`, `email`, NOW(), RAND())) 
WHERE `qr_token` IS NULL OR `qr_token` = '';

-- إنشاء رمز الباركود للمركبات التي لا تملك رمزاً
UPDATE `vehicles` 
SET `qr_code` = CONCAT('TN-V', LPAD(`id`, 3, '0'), '-', SUBSTRING(MD5(CONCAT(`id`, `plate_number`, RAND())), 1, 12)) 
WHERE `qr_code` IS NULL OR `qr_code` = '';

-- مزامنة السائق البديل للمركبات التي لديها عطلة معتمدة سارية المفعول
UPDATE `vehicles` v
JOIN `leaves` l ON v.`id` = l.`vehicle_id`
SET v.`replacement_driver_id` = l.`replacement_driver_id`
WHERE l.`status` = 'approved' 
  AND l.`end_date` >= CURDATE() 
  AND (v.`replacement_driver_id` IS NULL OR v.`replacement_driver_id` = 0);

-- التأكد من وجود الإعدادات الأساسية
INSERT INTO `settings` (`key`, `value`, `category`, `updated_at`) 
VALUES 
    ('app_name', 'تاكسي نقيلو', 'general', NOW()),
    ('company_name', 'مؤسسة تاكسي نقيلو للنقل الحضري وشبه الحضري', 'general', NOW()),
    ('tax_nif', '000000000000000', 'tax', NOW()),
    ('tax_nis', '0000000000', 'tax', NOW()),
    ('tax_rc', '00/00-0000000', 'tax', NOW()),
    ('tax_article', '00000000000', 'tax', NOW()),
    ('billing_space_fee', '1000', 'billing', NOW()),
    ('billing_vehicle_fee', '4000', 'billing', NOW())
ON DUPLICATE KEY UPDATE `category` = VALUES(`category`);

-- ==============================================================================
-- تم اكتمال ترقية وتحديث كافة أعمدة وجداول وفهارس قاعدة البيانات بنجاح!
-- TAXI-NAGUILOU Database Migration Completed Successfully!
-- ==============================================================================
