-- FIFO costing with automatic selling price calculation
-- 1. Configuration keys for automatic selling price calculation:
--    - default_margin_percent: default profit margin as percentage of the
--      selling price, used when no quantity rule matches.
--    - margin_quantity_rules: JSON array of quantity ranges and the margin
--      percentage to apply for receiving lines within that range.
INSERT IGNORE INTO `ospos_app_config` (`key`, `value`)
VALUES
    ('default_margin_percent', '20'),
    ('margin_quantity_rules', '[{"min_qty": 0, "max_qty": 50, "margin_percent": 20}, {"min_qty": 51, "max_qty": 200, "margin_percent": 15}, {"min_qty": 201, "max_qty": 999999, "margin_percent": 10}]');

-- 2. Tracks the margin percentage used the last time the item's selling price
--    was automatically calculated (debugging/reporting aid).
ALTER TABLE `ospos_items` ADD COLUMN `last_margin_percent` DECIMAL(5,2) NULL DEFAULT NULL;

-- 3. Stores the actual FIFO cost of goods sold for each sale line, computed
--    from the batch allocations in ospos_sales_items_batches at sale time.
ALTER TABLE `ospos_sales_items` ADD COLUMN `cost_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00;