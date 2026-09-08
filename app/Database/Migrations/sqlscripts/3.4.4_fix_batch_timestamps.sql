-- Fix any NULL created_at timestamps in ospos_item_batches
-- Set them to the receiving's receiving_time if possible, otherwise to NOW()
UPDATE ospos_item_batches b
JOIN ospos_receivings r ON r.receiving_id = b.receiving_id
SET b.created_at = r.receiving_time
WHERE b.created_at IS NULL AND b.receiving_id IS NOT NULL;

-- For any remaining NULL created_at (batches without receiving_id), set to NOW()
UPDATE ospos_item_batches
SET created_at = NOW()
WHERE created_at IS NULL;
