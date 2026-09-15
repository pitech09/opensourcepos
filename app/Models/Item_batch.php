<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * FIFO batch inventory model.
 *
 * Tracks product batches (one per receiving line) each with its own quantity,
 * unit cost and unit selling price. Sales consume batches strictly FIFO
 * (oldest batch first) and every sale is split across batches so that revenue
 * and cost are recorded per batch.
 */
class Item_batch extends Model
{
    const TABLE = 'item_batches';
    const SALES_TABLE = 'sales_items_batches';

    /**
     * Creates a new batch for an item, e.g. when stock is received.
     *
     * @param int $item_id The item the batch belongs to.
     * @param int $location_id The stock location the batch is stored at.
     * @param float $quantity Number of units received.
     * @param float $unit_cost_price Unit cost (buying) price for this batch.
     * @param float $unit_selling_price Unit selling price for this batch.
     * @param int|null $receiving_id The receiving that created this batch, if any.
     * @param string|null $expiry_date The expiry date for this batch (YYYY-MM-DD).
     * @return int The new batch_id.
     */
    public function create_batch(int $item_id, int $location_id, float $quantity,
        float $unit_cost_price, float $unit_selling_price, ?int $receiving_id = null, ?string $expiry_date = null): int
    {
        $builder = $this->db->table(self::TABLE);
        $builder->insert([
            'item_id'            => $item_id,
            'location_id'        => $location_id,
            'receiving_id'       => $receiving_id,
            'quantity'           => $quantity,
            'remaining'          => $quantity,
            'unit_cost_price'    => $unit_cost_price,
            'unit_selling_price' => $unit_selling_price,
            'expiry_date'        => $expiry_date,
            'created_at'         => now()
        ]);

        return $this->db->insertID();
    }

    /**
     * Gets the count of distinct items with batches expiring within the given number of days
     * or already expired, with positive remaining quantity.
     *
     * @param int $days_threshold Number of days to look ahead for expiring batches.
     * @return int Count of distinct items with expiring batches.
     */
    public function get_expiring_batches_count(int $days_threshold): int
    {
        $builder = $this->db->table(self::TABLE);
        $builder->select('COUNT(DISTINCT item_id) AS cnt');
        $builder->where('expiry_date IS NOT NULL');
        $builder->where('expiry_date <=', date('Y-m-d', strtotime("+$days_threshold days")));
        $builder->where('remaining >', 0);

        $query = $builder->get();
        $row = $query->getRow();

        return (int) ($row->cnt ?? 0);
    }

    /**
     * Gets batches expiring within the given number of days or already expired,
     * with positive remaining quantity.
     *
     * @param int $days_threshold Number of days to look ahead for expiring batches.
     * @return array List of expiring batches with item details.
     */
    public function get_expiring_batches(int $days_threshold): array
    {
        $builder = $this->db->table(self::TABLE);
        $builder->select('item_batches.batch_id, item_batches.item_id, item_batches.location_id, 
            item_batches.quantity, item_batches.remaining, item_batches.expiry_date, 
            item_batches.unit_cost_price, item_batches.unit_selling_price,
            items.name, items.item_number, items.category');
        $builder->join('items', 'items.item_id = item_batches.item_id');
        $builder->where('item_batches.expiry_date IS NOT NULL');
        $builder->where('item_batches.expiry_date <=', date('Y-m-d', strtotime("+$days_threshold days")));
        $builder->where('item_batches.remaining >', 0);
        $builder->where('items.deleted', 0);
        $builder->orderBy('item_batches.expiry_date', 'ASC');

        return $builder->get()->getResultArray();
    }

    /**
     * Allocates units for a sale using FIFO: the oldest batches (lowest
     * batch_id) with remaining stock are consumed first, and the allocation
     * is split across batches as needed. The caller is responsible for
     * checking that enough total stock exists.
     *
     * @param int $item_id The item being sold.
     * @param int $location_id The stock location selling from.
     * @param float $quantity Number of units to consume.
     * @return array[] List of allocations: each with batch_id, quantity,
     *  unit_cost_price, unit_selling_price, revenue and cost.
     */
    public function allocate_fifo(int $item_id, int $location_id, float $quantity): array
    {
        $builder = $this->db->table(self::TABLE);
        $batches = $builder
            ->select('batch_id, remaining, unit_cost_price, unit_selling_price')
            ->where([
                'item_id'     => $item_id,
                'location_id' => $location_id
            ])
            ->where('remaining >', 0)
            ->orderBy('created_at', 'ASC')
            ->orderBy('batch_id', 'ASC')
            ->get()->getResult();

        $remaining_to_allocate = $quantity;
        $allocations = [];

        foreach($batches as $batch)
        {
            if($remaining_to_allocate <= 0)
            {
                break;
            }

            $take = min((float) $batch->remaining, $remaining_to_allocate);

            $allocations[] = [
                'batch_id'           => (int) $batch->batch_id,
                'quantity'           => $take,
                'unit_cost_price'    => (float) $batch->unit_cost_price,
                'unit_selling_price' => (float) $batch->unit_selling_price,
                'revenue'            => $take * (float) $batch->unit_selling_price,
                'cost'               => $take * (float) $batch->unit_cost_price
            ];

            $remaining_to_allocate -= $take;
        }

        return $allocations;
    }


    /**
     * Persists a FIFO allocation for a sale line: records the batch split in
     * the sales_items_batches table and decrements the batches' remaining
     * quantity. Should be called inside the sale's database transaction.
     *
     * @param int $sale_id The sale being recorded.
     * @param int $line The sale line number the units belong to.
     * @param array[] $allocations The allocation returned by allocate_fifo().
     * @return void
     */
    public function record_sale_allocation(int $sale_id, int $line, array $allocations): void
    {
        $builder = $this->db->table(self::SALES_TABLE);

        foreach($allocations as $allocation)
        {
            $builder->insert([
                'sale_id'            => $sale_id,
                'line'               => $line,
                'batch_id'           => $allocation['batch_id'],
                'quantity'           => $allocation['quantity'],
                'unit_cost_price'    => $allocation['unit_cost_price'],
                'unit_selling_price' => $allocation['unit_selling_price'],
                'revenue'            => $allocation['revenue'],
                'cost'               => $allocation['cost']
            ]);

            $this->db->table(self::TABLE)
                ->where('batch_id', $allocation['batch_id'])
                ->set('remaining', 'remaining - ' . $allocation['quantity'], false)
                ->update();
        }
    }

    /**
     * Gets all batches for an item at a location (oldest first), including
     * depleted ones, so remaining quantity and selling price per batch can
     * be inspected.
     *
     * @return array[] Array of batch objects (batch_id, quantity, remaining,
     *  unit_cost_price, unit_selling_price, created_at, ...).
     */
    public function get_batches(int $item_id, int $location_id): array
    {
        $builder = $this->db->table(self::TABLE);
        return $builder
            ->where([
                'item_id'     => $item_id,
                'location_id' => $location_id
            ])
            ->orderBy('batch_id', 'ASC')
            ->get()->getResult();
    }

    /**
     * Gets the per-batch breakdown of a sale line (or all lines of a sale
     * when $line is null), showing which batch each sold unit came from
     * together with revenue and cost.
     *
     * @return array[] Array of allocation rows.
     */
    public function get_sale_breakdown(int $sale_id, ?int $line = null): array
    {
        $builder = $this->db->table(self::SALES_TABLE);
        $builder->where('sale_id', $sale_id);
        if($line !== null)
        {
            $builder->where('line', $line);
        }
        return $builder->orderBy('line', 'ASC')->orderBy('id', 'ASC')->get()->getResult();
    }

    /**
     * Gets all batches for the given stock locations, joined with item names,
     * for display in the batch report. Oldest batches first per item.
     *
     * @param array $location_ids Allowed stock location IDs (key => name form is fine).
     * @return array[] Array of batch rows with item_name and location_name.
     */
    public function get_all_batches(array $location_ids): array
    {
        $builder = $this->db->table(self::TABLE);
        $builder->select('item_batches.batch_id, item_batches.item_id, items.name AS item_name, '
            . 'stock_locations.location_name, item_batches.quantity, item_batches.remaining, '
            . 'item_batches.unit_cost_price, item_batches.unit_selling_price, item_batches.created_at');
        $builder->join('items', 'items.item_id = item_batches.item_id');
        $builder->join('stock_locations', 'stock_locations.location_id = item_batches.location_id');
        $builder->whereIn('item_batches.location_id', array_keys($location_ids));
        $builder->orderBy('item_batches.item_id', 'ASC');
        $builder->orderBy('item_batches.batch_id', 'ASC');

        return $builder->get()->getResult();
    }

    /**
     * Gets the total units available across all batches of an item at a
     * location.
     */
    public function get_available_quantity(int $item_id, int $location_id): float
    {
        $builder = $this->db->table(self::TABLE);
        $result = $builder
            ->selectSum('remaining', 'available')
            ->where([
                'item_id'     => $item_id,
                'location_id' => $location_id
            ])
            ->get()->getRow();

        return (float) ($result->available ?? 0);
    }

    /**
     * Consumes stock FIFO without recording a sale: decrements the oldest
     * batches first and returns the FIFO cost of the consumed units. Used for
     * negative inventory adjustments (e.g. stock count corrections).
     *
     * @param int $item_id The item being consumed.
     * @param int $location_id The stock location to consume from.
     * @param float $quantity Number of units to consume.
     * @return float The FIFO cost of the consumed units.
     */
    public function consume_fifo(int $item_id, int $location_id, float $quantity): float
    {
        $allocations = $this->allocate_fifo($item_id, $location_id, $quantity);
        $total_cost = 0.0;

        foreach($allocations as $allocation)
        {
            $total_cost += $allocation['cost'];

            $this->db->table(self::TABLE)
                ->where('batch_id', $allocation['batch_id'])
                ->set('remaining', 'remaining - ' . $allocation['quantity'], false)
                ->update();
        }

        return $total_cost;
    }

    /**
     * Gets the last known FIFO unit cost for an item at a location: the unit
     * cost of the most recent sale allocation (i.e. the cost of the batch the
     * last unit was sold from), falling back to the item's current cost price
     * when no allocations exist. Used to cost returned stock.
     *
     * @return float The last known FIFO unit cost.
     */
    public function get_last_fifo_unit_cost(int $item_id, int $location_id): float
    {
        $builder = $this->db->table(self::TABLE);
        $result = $builder
            ->select(self::SALES_TABLE . '.unit_cost_price')
            ->join(self::SALES_TABLE, self::SALES_TABLE . '.batch_id = ' . self::TABLE . '.batch_id')
            ->where([
                self::TABLE . '.item_id'     => $item_id,
                self::TABLE . '.location_id' => $location_id
            ])
            ->orderBy(self::SALES_TABLE . '.id', 'DESC')
            ->limit(1)
            ->get()->getRow();

        if($result !== null)
        {
            return (float) $result->unit_cost_price;
        }

        $item = $this->db->table('items')
            ->select('cost_price')
            ->where('item_id', $item_id)
            ->get()->getRow();

        return (float) ($item->cost_price ?? 0);
    }

    /**
     * Adds stock to the batch table as a new batch, e.g. for positive
     * inventory adjustments or customer returns. The new batch gets a later
     * batch_id (and created_at) than any existing batch, so it is consumed
     * last, preserving FIFO order.
     *
     * @param int $item_id The item being added.
     * @param int $location_id The stock location the stock is added to.
     * @param float $quantity Number of units added.
     * @param float|null $unit_cost Unit cost of the added stock; when null the
     *  item's last known FIFO unit cost (or cost price) is used.
     * @return int The new batch_id.
     */
    public function add_stock(int $item_id, int $location_id, float $quantity, ?float $unit_cost = null): int
    {
        if($quantity <= 0)
        {
            return 0;
        }

        // Use the oldest remaining batch's selling price for the new batch
        // so that new receipts do not change the selling price of existing
        // stock. Falls back to the item's unit_price, then to 0.
        $item = $this->db->table('items')->select('unit_price')
            ->where('item_id', $item_id)->get()->getRow();
        $oldest_selling = $this->get_oldest_batch_selling_price($item_id, $location_id);
        $unit_selling = $oldest_selling > 0 ? $oldest_selling : ((float) ($item->unit_price ?? 0));

        return $this->create_batch(
            $item_id,
            $location_id,
            $quantity,
            $unit_cost ?? $this->get_last_fifo_unit_cost($item_id, $location_id),
            $unit_selling
        );
    }

    /**
     * Gets the unit_selling_price of the oldest remaining batch (positive
     * remaining quantity) for an item at a location. This is the price that
     * the oldest stock should be (and was) sold at, ensuring that receiving
     * new stock at a different cost/ margin does not change the price of
     * existing inventory.
     *
     * @param int $item_id The item to look up.
     * @param int $location_id The stock location to look up.
     * @return float The oldest batch's unit_selling_price, or 0 when no
     *  remaining batches exist.
     */
    public function get_oldest_batch_selling_price(int $item_id, int $location_id): float

    {
        $builder = $this->db->table(self::TABLE);
        $row = $builder
            ->select('unit_selling_price')
            ->where([
                'item_id'     => $item_id,
                'location_id' => $location_id
            ])
            ->where('remaining >', 0)
            ->orderBy('created_at', 'ASC')
            ->orderBy('batch_id', 'ASC')
            ->limit(1)
            ->get()->getRow();

        return $row !== null ? (float) $row->unit_selling_price : 0.0;
    }

    /**
     * Returns the oldest active batch (remaining > 0) for an item across all
     * stock locations. Ordering matches allocate_fifo() exactly (created_at
     * ASC, batch_id ASC), so this is the batch the register will sell from
     * next, whichever location it sits in.
     *
     * @param int $item_id The item to look up.
     * @return array|null {batch_id, unit_cost_price, unit_selling_price} or
     *  null when the item has no active batches.
     */
    public function get_oldest_active_batch(int $item_id): ?array
    {
        $row = $this->db->table(self::TABLE)
            ->select('batch_id, unit_cost_price, unit_selling_price')
            ->where('item_id', $item_id)
            ->where('remaining >', 0)
            ->orderBy('created_at', 'ASC')
            ->orderBy('batch_id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Builds a prefix-aware derived-table SQL fragment returning, for every
     * item that has at least one active batch (remaining > 0), the oldest
     * active batch's unit cost and selling price. Intended for use as a LEFT
     * JOIN target so list views can display prices that follow FIFO stock
     * instead of the static item master record. Uses the anti-join pattern
     * (no earlier active batch exists) which is MySQL/MariaDB-version-safe
     * and served by idx_item_batches_fifo.
     *
     * @return string SQL fragment aliased for a "fifo" derived table.
     */
    public function get_oldest_active_batch_sql(): string
    {
        $table = $this->db->prefixTable(self::TABLE);

        return "SELECT b.item_id, b.unit_cost_price, b.unit_selling_price
            FROM $table b
            LEFT JOIN $table newer
                ON newer.item_id = b.item_id
                AND newer.remaining > 0
                AND (newer.created_at < b.created_at
                    OR (newer.created_at = b.created_at AND newer.batch_id < b.batch_id))
            WHERE b.remaining > 0
                AND newer.batch_id IS NULL";
    }

    /**
     * Transfers stock between locations using FIFO.
     * Consumes batches from source location oldest-first and adds them
     * to destination location. The destination batches keep the same
     * unit_selling_price and unit_cost_price as the source batches.
     *
     * @param int $item_id The item to transfer.
     * @param int $from_location_id Source location.
     * @param int $to_location_id Destination location.
     * @param float $quantity Quantity to transfer.
     * @return bool True if transfer succeeded, false otherwise.
     */
    public function transfer_fifo(int $item_id, int $from_location_id, int $to_location_id, float $quantity): bool
    {
        if($quantity <= 0)
        {
            return false;
        }

        $allocations = $this->allocate_fifo($item_id, $from_location_id, $quantity);
        if(empty($allocations))
        {
            return false;
        }

        // Update source location batches (consume)
        foreach($allocations as $allocation)
        {
            $this->db->table(self::TABLE)
                ->where('batch_id', $allocation['batch_id'])
                ->set('remaining', 'remaining - ' . $allocation['quantity'], false)
                ->update();
        }

        // Add to destination location batches (same prices as source batches)
        foreach($allocations as $allocation)
        {
            $this->create_batch(
                $item_id,
                $to_location_id,
                $allocation['quantity'],
                $allocation['unit_cost_price'],
                $allocation['unit_selling_price']
            );
        }

        return true;
    }
}
