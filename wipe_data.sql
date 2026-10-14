-- ========================================================
-- EURL TAXI NAGUILOU - Database Data Purge Script
-- تفريغ كافة البيانات وحل خطأ #1701 (استخدام DELETE مع تعطيل القيود)
-- ========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. حذف السجلات التشغيلية وإعادة تصفير العدادات
DELETE FROM `traveler_passengers`;
ALTER TABLE `traveler_passengers` AUTO_INCREMENT = 1;

DELETE FROM `traveler_reports`;
ALTER TABLE `traveler_reports` AUTO_INCREMENT = 1;

DELETE FROM `transmission_slips`;
ALTER TABLE `transmission_slips` AUTO_INCREMENT = 1;

DELETE FROM `leaves`;
ALTER TABLE `leaves` AUTO_INCREMENT = 1;

DELETE FROM `contracts`;
ALTER TABLE `contracts` AUTO_INCREMENT = 1;

DELETE FROM `versements`;
ALTER TABLE `versements` AUTO_INCREMENT = 1;

DELETE FROM `driver_debts`;
ALTER TABLE `driver_debts` AUTO_INCREMENT = 1;

DELETE FROM `billings`;
ALTER TABLE `billings` AUTO_INCREMENT = 1;

DELETE FROM `driver_subscriptions`;
ALTER TABLE `driver_subscriptions` AUTO_INCREMENT = 1;

DELETE FROM `insurance_policies`;
ALTER TABLE `insurance_policies` AUTO_INCREMENT = 1;

DELETE FROM `cnas_declarations`;
ALTER TABLE `cnas_declarations` AUTO_INCREMENT = 1;

DELETE FROM `night_watch`;
ALTER TABLE `night_watch` AUTO_INCREMENT = 1;

DELETE FROM `driver_schedules`;
ALTER TABLE `driver_schedules` AUTO_INCREMENT = 1;

DELETE FROM `audit_logs`;
ALTER TABLE `audit_logs` AUTO_INCREMENT = 1;

DELETE FROM `leads`;
ALTER TABLE `leads` AUTO_INCREMENT = 1;

DELETE FROM `notifications`;
ALTER TABLE `notifications` AUTO_INCREMENT = 1;

DELETE FROM `vehicles`;
ALTER TABLE `vehicles` AUTO_INCREMENT = 1;


SET FOREIGN_KEY_CHECKS = 1;
