-- CureMeds: Pudo lockers, online shop and Google sign-in (October 2026).
--
-- You normally do NOT need this: the site applies these changes itself the
-- first time a page loads after a deploy (includes/extras.php). Run it in
-- cPanel → phpMyAdmin → your database → SQL only if checkout says a column
-- is missing.
--
-- Run each line. A "Duplicate column name" error just means that column is
-- already there: skip it and carry on.

ALTER TABLE `enquiries` ADD COLUMN `delivery_method` VARCHAR(20) NOT NULL DEFAULT 'delivery';
ALTER TABLE `enquiries` ADD COLUMN `pudo_locker_code` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `enquiries` ADD COLUMN `pudo_locker_name` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `enquiries` ADD COLUMN `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `enquiries` ADD COLUMN `courier_waybill` VARCHAR(60) DEFAULT NULL;
ALTER TABLE `enquiries` ADD COLUMN `courier_shipment` VARCHAR(60) DEFAULT NULL;
ALTER TABLE `enquiries` ADD COLUMN `courier_pin` VARCHAR(20) DEFAULT NULL;

ALTER TABLE `orders` MODIFY `fulfilment` ENUM('delivery','collection','runner','pudo') NOT NULL DEFAULT 'delivery';
ALTER TABLE `orders` ADD COLUMN `pudo_locker_code` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN `pudo_locker_name` VARCHAR(160) DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN `courier_waybill` VARCHAR(60) DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN `courier_shipment` VARCHAR(60) DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN `courier_pin` VARCHAR(20) DEFAULT NULL;

ALTER TABLE `users` ADD COLUMN `google_id` VARCHAR(64) DEFAULT NULL UNIQUE;

-- Standard Bank's universal branch code, and mark the upgrade as done.
INSERT INTO `settings` (key_name, value) VALUES ('bank_branch_code', '051001')
  ON DUPLICATE KEY UPDATE value = IF(value = '' OR value IS NULL, '051001', value);
INSERT INTO `settings` (key_name, value) VALUES ('cm_schema_version', '3')
  ON DUPLICATE KEY UPDATE value = '3';
