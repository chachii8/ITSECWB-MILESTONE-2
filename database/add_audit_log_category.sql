-- Add log_category column to audit_log for clearer division
-- Categories: AUTH (login/MFA), TRANSACTION (orders/payments), ADMIN (product/user/order management)
-- Run this when MySQL is running: mysql -u root sole_source < database/add_audit_log_category.sql
ALTER TABLE `audit_log` ADD COLUMN `log_category` ENUM('AUTH','TRANSACTION','ADMIN') DEFAULT NULL AFTER `action`;

-- Backfill existing rows based on action prefix
UPDATE `audit_log` SET log_category = 'AUTH' WHERE action LIKE 'LOGIN_%' OR action LIKE 'MFA_%';
UPDATE `audit_log` SET log_category = 'TRANSACTION' WHERE action IN ('ORDER_PLACE', 'ORDER_CREATE');
UPDATE `audit_log` SET log_category = 'ADMIN' WHERE action LIKE 'PRODUCT_%' OR action LIKE 'USER_%' OR action = 'ORDER_STATUS_UPDATE';
