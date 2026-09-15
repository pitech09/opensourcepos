-- Location-aware receiving: FIFO performance index filtered by location.
-- Every sale/transfer consumes batches oldest-first within a single location,
-- so (item_id, location_id, created_at, batch_id) is the access pattern.
ALTER TABLE `ospos_item_batches` ADD INDEX `idx_item_batches_fifo` (`item_id`, `location_id`, `created_at`, `batch_id`);

-- Destination configuration for location-aware receiving.
-- default_shop_location_id: the main store where customers buy from.
-- default_warehouse_location_id: where new stock goes when the shop still
-- has stock of the item (so old stock keeps selling at its old price).
INSERT INTO `ospos_app_config` (`key`, `value`) VALUES ('default_shop_location_id', '1') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
INSERT INTO `ospos_app_config` (`key`, `value`) VALUES ('default_warehouse_location_id', '2') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Backfill: batches created before per-batch pricing that carry no selling
-- price inherit the item's master selling price.
UPDATE `ospos_item_batches` b
INNER JOIN `ospos_items` i ON i.item_id = b.item_id
SET b.unit_selling_price = i.unit_price
WHERE b.unit_selling_price = 0;
