-- Configurable profit margin / markup settings for auto-pricing
-- 1. Global default pricing method ('margin' = profit as % of selling price,
--    'markup' = profit as % of cost) and the default markup percentage.
--    default_margin_percent already exists (see 3.4.2_fifo_auto_pricing.sql).
INSERT IGNORE INTO `ospos_app_config` (`key`, `value`)
VALUES
    ('default_pricing_method', 'margin'),
    ('default_margin_percent', '20'),
    ('default_markup_percent', '25');

-- 2. Per-item pricing override. NULL means "use the global defaults".
ALTER TABLE `ospos_items`
    ADD COLUMN `pricing_method` ENUM('margin','markup') NULL DEFAULT NULL,
    ADD COLUMN `margin_percent` DECIMAL(5,2) NULL DEFAULT NULL,
    ADD COLUMN `markup_percent` DECIMAL(5,2) NULL DEFAULT NULL;