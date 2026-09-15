CREATE TABLE `ospos_item_batches` (
	`batch_id` INT(11) NOT NULL AUTO_INCREMENT,
	`item_id` INT(11) NOT NULL,
	`location_id` INT(11) NOT NULL,
	`receiving_id` INT(11) DEFAULT NULL,
	`quantity` DECIMAL(15,4) NOT NULL,
	`remaining` DECIMAL(15,4) NOT NULL,
	`unit_cost_price` DECIMAL(15,4) NOT NULL,
	`unit_selling_price` DECIMAL(15,4) NOT NULL,
	`expiry_date` DATE DEFAULT NULL,
	`created_at` DATETIME NOT NULL,
	PRIMARY KEY (`batch_id`),
	KEY `idx_item_batches_item` (`item_id`),
	KEY `idx_item_batches_item_location` (`item_id`, `location_id`, `remaining`),
	CONSTRAINT `fk_item_batches_item` FOREIGN KEY (`item_id`) REFERENCES `ospos_items` (`item_id`) ON DELETE CASCADE,
	CONSTRAINT `fk_item_batches_receiving` FOREIGN KEY (`receiving_id`) REFERENCES `ospos_receivings` (`receiving_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `ospos_sales_items_batches` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`sale_id` INT(11) NOT NULL,
	`line` INT(3) NOT NULL DEFAULT 0,
	`batch_id` INT(11) NOT NULL,
	`quantity` DECIMAL(15,4) NOT NULL,
	`unit_cost_price` DECIMAL(15,4) NOT NULL,
	`unit_selling_price` DECIMAL(15,4) NOT NULL,
	`revenue` DECIMAL(15,4) NOT NULL,
	`cost` DECIMAL(15,4) NOT NULL,
	PRIMARY KEY (`id`),
	KEY `idx_sales_items_batches_sale` (`sale_id`),
	KEY `idx_sales_items_batches_batch` (`batch_id`),
	CONSTRAINT `fk_sales_items_batches_sale` FOREIGN KEY (`sale_id`) REFERENCES `ospos_sales` (`sale_id`) ON DELETE CASCADE,
	CONSTRAINT `fk_sales_items_batches_batch` FOREIGN KEY (`batch_id`) REFERENCES `ospos_item_batches` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Seed initial FIFO batches from existing stock so that FIFO costing works
-- from day one. For every item/location with stock on hand and no batches
-- yet, one batch is created with the current quantity and the item's current
-- cost price (the moving average cost at migration time). These seeded
-- batches are the oldest, so they are consumed first by future sales.
INSERT INTO `ospos_item_batches` (`item_id`, `location_id`, `receiving_id`, `quantity`, `remaining`, `unit_cost_price`, `unit_selling_price`, `created_at`)
SELECT iq.item_id, iq.location_id, NULL, iq.quantity, iq.quantity, i.cost_price, i.unit_price, NOW()
FROM `ospos_item_quantities` iq
INNER JOIN `ospos_items` i ON i.item_id = iq.item_id
WHERE i.deleted = 0
  AND i.stock_type = 0
  AND iq.quantity > 0
  AND NOT EXISTS (
      SELECT 1 FROM `ospos_item_batches` b
      WHERE b.item_id = iq.item_id AND b.location_id = iq.location_id
  );