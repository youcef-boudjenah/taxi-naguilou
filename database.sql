-- قاعدة بيانات منصة تاكسي نقيلو (TAXI-NAGUILOU)
CREATE DATABASE IF NOT EXISTS `taxi_naguilou` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `taxi_naguilou`;

-- 1. المستخدمون (المشرفون، الملاك، السائقون)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) UNIQUE NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `role` ENUM('super_admin', 'admin', 'owner', 'driver') DEFAULT 'driver',
    `status` ENUM('active', 'pending', 'suspended') DEFAULT 'active',
    `wilaya` VARCHAR(100) DEFAULT '16 - الجزائر العاصمة',
    `license_number` VARCHAR(50) NULL,
    `license_expiry` DATE NULL,
    `medical_cert_expiry` DATE NULL,
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
