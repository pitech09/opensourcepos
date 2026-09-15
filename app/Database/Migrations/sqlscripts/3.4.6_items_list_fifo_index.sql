-- Performance index for the Items list FIFO price lookup: the
-- get_oldest_active_batch_sql() anti-join searches for a newer active batch
-- by (item_id, remaining) ordered by (created_at, batch_id). The existing
-- idx_item_batches_fifo has location_id in between, which prevents this
-- lookup from using an index, forcing table scans as batch volume grows.
ALTER TABLE `ospos_item_batches` ADD INDEX `idx_item_batches_oldest_active` (`item_id`, `remaining`, `created_at`, `batch_id`);
