ALTER TABLE `ospos_item_batches` ADD COLUMN `expiry_date` DATE DEFAULT NULL AFTER `unit_selling_price`;
INSERT INTO `ospos_app_config` (`key`, `value`) VALUES ('expiry_warning_days', '30') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
