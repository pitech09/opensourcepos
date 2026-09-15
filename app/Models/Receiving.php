<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use ReflectionException;

/**
 * Receiving class
 */
class Receiving extends Model
{
    protected $table = 'receivings';
    protected $primaryKey = 'receiving_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'receiving_time',
        'supplier_id',
        'employee_id',
        'comment',
        'receiving_id',
        'payment_type',
        'reference'
    ];

    /**
     * @param int $receiving_id
     * @return ResultInterface
     */
    public function get_info(int $receiving_id): ResultInterface
    {
        $builder = $this->db->table('receivings');
        $builder->join('people', 'people.person_id = receivings.supplier_id', 'LEFT');
        $builder->join('suppliers', 'suppliers.person_id = receivings.supplier_id', 'LEFT');
        $builder->where('receiving_id', $receiving_id);

        return $builder->get();
    }

    /**
     * @param string $reference
     * @return ResultInterface
     */
    public function get_receiving_by_reference(string $reference): ResultInterface
    {
        $builder = $this->db->table('receivings');
        $builder->where('reference', $reference);

        return $builder->get();
    }

    /**
     * @param string $receipt_receiving_id
     * @return bool
     */
    public function is_valid_receipt(string $receipt_receiving_id): bool    // TODO: maybe receipt_receiving_id should be an array rather than a space delimited string
    {
        if (!empty($receipt_receiving_id)) {
            // RECV #
            $pieces = explode(' ', $receipt_receiving_id);

            if (count($pieces) == 2 && preg_match('/(RECV|KIT)/', $pieces[0])) {
                return $this->exists($pieces[1]);
            } else {
                return $this->get_receiving_by_reference($receipt_receiving_id)->getNumRows() > 0;
            }
        }

        return false;
    }

    /**
     * @param int $receiving_id
     * @return bool
     */
    public function exists(int $receiving_id): bool
    {
        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);

        return ($builder->get()->getNumRows() == 1);
    }

    /**
     * @param $receiving_id
     * @param $receiving_data
     * @return bool
     */
    public function update($receiving_id = null, $receiving_data = null): bool
    {
        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);

        return $builder->update($receiving_data);
    }

    /**
     * Determines the destination stock location for a received line using the
     * location-aware receiving rules:
     *
     *  1. When the user explicitly selected a location other than the shop in
     *     the receiving screen's stock source dropdown, that override is
     *     respected as-is.
     *  2. When the shop already has stock of the item (and this is a positive
     *     receiving), the new stock is placed in the warehouse so the shop's
     *     existing batch keeps selling at its own price.
     *  3. When the shop is empty, the stock goes directly to the shop.
     *
     * @param int $item_id The item being received.
     * @param int $selected_location The location selected in the receiving UI.
     * @param float $quantity The quantity being received (negative for returns,
     * which are never redirected).
     * @return array {location_id: int, shop_location_id: int, shop_quantity: float, redirected: bool}
     */
    public function determine_destination_location(int $item_id, int $selected_location, float $quantity = 0): array
    {
        $appconfig = model(Appconfig::class);
        $stock_location = model(Stock_location::class);
        $item_quantity = model(Item_quantity::class);

        $shop_location_id = (int) $appconfig->get_value('default_shop_location_id', '0');

        if ($shop_location_id <= 0) {
            $shop_location_id = $stock_location->get_default_location_id('receivings');
        }

        $shop_quantity = (float) $item_quantity->get_item_quantity($item_id, $shop_location_id)->quantity;

        // Manual override: any explicitly chosen non-shop location is respected.
        if ($selected_location != $shop_location_id || $quantity <= 0) {
            return [
                'location_id'      => $selected_location,
                'shop_location_id' => $shop_location_id,
                'shop_quantity'    => $shop_quantity,
                'redirected'       => false
            ];
        }

        if ($shop_quantity > 0) {
            $warehouse_location_id = (int) $appconfig->get_value('default_warehouse_location_id', '0');

            if ($warehouse_location_id <= 0 || $warehouse_location_id == $shop_location_id) {
                $warehouse_location_id = $stock_location->get_warehouse_location_id($shop_location_id);
            }

            if ($warehouse_location_id > 0) {
                return [
                    'location_id'      => $warehouse_location_id,
                    'shop_location_id' => $shop_location_id,
                    'shop_quantity'    => $shop_quantity,
                    'redirected'       => true
                ];
            }
        }

        return [
            'location_id'      => $shop_location_id,
            'shop_location_id' => $shop_location_id,
            'shop_quantity'    => $shop_quantity,
            'redirected'       => false
        ];
    }

    /**
     * @throws ReflectionException
     */
    public function save_value(array $items, int $supplier_id, int $employee_id, string $comment, string $reference, ?string $payment_type, int $receiving_id = NEW_ENTRY): int    // TODO: $receiving_id gets overwritten before it's evaluated. It doesn't make sense to pass this here.
    {
        $attribute = model(Attribute::class);
        $inventory = model('Inventory');
        $item = model(Item::class);
        $item_batch = model(Item_batch::class);
        $item_quantity = model(Item_quantity::class);
        $supplier = model(Supplier::class);

        if (count($items) == 0) {
            return -1;    // TODO: Replace -1 with a constant
        }

        $receivings_data = [
            'receiving_time' => now(),
            'supplier_id'    => $supplier->exists($supplier_id) ? $supplier_id : null,
            'employee_id'    => $employee_id,
            'payment_type'   => $payment_type,
            'comment'        => $comment,
            'reference'      => $reference
        ];

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        $builder = $this->db->table('receivings');
        $builder->insert($receivings_data);
        $receiving_id = $this->db->insertID();

        $builder = $this->db->table('receivings_items');

        foreach ($items as $line => $item_data) {
            $config = config(OSPOS::class)->settings;
            $cur_item_info = $item->get_info($item_data['item_id']);

            // Location-aware receiving: decide where this line is stored.
            // If the shop already has stock of the item, new stock goes to the
            // warehouse so the shop keeps selling its existing (older, cheaper)
            // batch at its own price. If the shop is empty, the stock goes to
            // the shop directly. An explicitly chosen non-shop location (via
            // the stock source dropdown) is always respected as an override.
            $destination = $this->determine_destination_location(
                (int) $item_data['item_id'],
                (int) $item_data['item_location'],
                (float) $item_data['quantity']
            );
            $item_data['item_location'] = $destination['location_id'];
            $shop_had_stock = $destination['shop_quantity'] > 0;

            $receivings_items_data = [
                'receiving_id'       => $receiving_id,
                'item_id'            => $item_data['item_id'],
                'line'               => $item_data['line'],
                'description'        => $item_data['description'],
                'serialnumber'       => $item_data['serialnumber'],
                'quantity_purchased' => $item_data['quantity'],
                'receiving_quantity' => $item_data['receiving_quantity'],
                'discount'           => $item_data['discount'],
                'discount_type'      => $item_data['discount_type'],
                'item_cost_price'    => $cur_item_info->cost_price,
                'item_unit_price'    => $item_data['price'],
                'item_location'      => $item_data['item_location']
            ];

            $builder->insert($receivings_items_data);

            $items_received = $item_data['receiving_quantity'] != 0 ? $item_data['quantity'] * $item_data['receiving_quantity'] : $item_data['quantity'];

            // Update cost price, if changed AND is set in config as wanted
            if ($cur_item_info->cost_price != $item_data['price'] && $config['receiving_calculate_average_price']) {
                $item->change_cost_price($item_data['item_id'], $items_received, $item_data['price'], $cur_item_info->cost_price);
            }

            // Update stock quantity
            $item_quantity_value = $item_quantity->get_item_quantity($item_data['item_id'], $item_data['item_location']);
            $item_quantity->save_value(
                [
                    'quantity'    => $item_quantity_value->quantity + $items_received,
                    'item_id'     => $item_data['item_id'],
                    'location_id' => $item_data['item_location']
                ],
                $item_data['item_id'],
                $item_data['item_location']
            );

            $recv_remarks = 'RECV ' . $receiving_id;
            $inv_data = [
                'trans_date'      => now(),
                'trans_items'     => $item_data['item_id'],
                'trans_user'      => $employee_id,
                'trans_location'  => $item_data['item_location'],
                'trans_comment'   => $recv_remarks,
                'trans_inventory' => $items_received
            ];

            $inventory->insert($inv_data, false);
            $attribute->copy_attribute_links($item_data['item_id'], 'receiving_id', $receiving_id);

            // FIFO batch tracking: every received line becomes a new batch with
            // its own unit cost and unit selling price, so later sales consume
            // batches oldest-first at each batch's selling price. The selling
            // price is recalculated automatically from the new batch's cost and
            // the configured profit margin (which may depend on the quantity
            // received).
            if($cur_item_info->stock_type == HAS_STOCK)
            {
                $selling_price = $this->_update_selling_price(
                    $item_data['item_id'],
                    $items_received,
                    $item_data['price'],
                    $cur_item_info->unit_price
                );

                // When the shop had no stock before this receiving, the new
                // batch becomes the current sellable stock: reflect the new
                // cost and selling price on the item master so the register
                // default and reports match. When the shop still has stock,
                // the master prices stay untouched — the old shop batch's
                // prices remain in effect and only this new batch (in the
                // warehouse) carries the new cost/price.
                if (!$shop_had_stock)
                {
                    $this->db->table('items')
                        ->where('item_id', $item_data['item_id'])
                        ->update([
                            'cost_price' => $item_data['price'],
                            'unit_price' => $selling_price
                        ]);
                }

                $item_batch->create_batch(
                    $item_data['item_id'],
                    $item_data['item_location'],
                    $items_received,
                    $item_data['price'],    // unit cost paid for this batch
                    $selling_price,         // automatically calculated selling price
                    $receiving_id
                );
            }
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $receiving_id : -1;
    }

    /**
     * Automatically recalculates an item's selling price from the cost of a
     * newly received batch and the configured pricing. The pricing method and
     * percentage resolve as follows:
     *
     *  1. The item's own override (pricing_method + margin_percent or
     *     markup_percent) when set on the item.
     *  2. Otherwise the global default_pricing_method from Configuration:
     *     - markup: default_markup_percent
     *     - margin: the matching rule from margin_quantity_rules (if any)
     *       else default_margin_percent
     *
     * margin = (selling_price - cost) / selling_price, therefore
     * selling_price = cost / (1 - margin / 100); with markup:
     * selling_price = cost * (1 + markup / 100).
     *
     * When the percentage is not usable the item's current selling price is
     * kept unchanged.
     *
     * @param int $item_id The item whose selling price is recalculated.
     * @param float $quantity The quantity received on this line.
     * @param float $unit_cost The unit cost of the newly received batch.
     * @param float $current_price The item's current selling price (fallback).
     * @return float The new selling price (rounded to 2 decimals).
     */
    private function _update_selling_price(int $item_id, float $quantity, float $unit_cost, float $current_price): float
    {
        $appconfig = model(Appconfig::class);
        $item = model(Item::class);
        $item_info = $item->get_info($item_id);

        if (!empty($item_info->pricing_method)) {
            // Per-item override
            $method = $item_info->pricing_method;
            $percent = $method === 'markup'
                ? ($item_info->markup_percent !== null ? (float) $item_info->markup_percent : (float) $appconfig->get_value('default_markup_percent', '25'))
                : ($item_info->margin_percent !== null ? (float) $item_info->margin_percent : (float) $appconfig->get_value('default_margin_percent', '20'));
        } else {
            $method = $appconfig->get_value('default_pricing_method', 'margin');

            if ($method === 'markup') {
                $percent = (float) $appconfig->get_value('default_markup_percent', '25');
            } else {
                // Quantity-based margin rules (if configured) take precedence
                // over the flat default margin
                $percent = (float) $appconfig->get_value('default_margin_percent', '20');
                $rules = json_decode($appconfig->get_value('margin_quantity_rules', ''), true);

                if (is_array($rules)) {
                    foreach($rules as $rule)
                    {
                        $min = isset($rule['min_qty']) ? (float) $rule['min_qty'] : 0;
                        $max = isset($rule['max_qty']) ? (float) $rule['max_qty'] : PHP_FLOAT_MAX;

                        if($quantity >= $min && $quantity <= $max)
                        {
                            $percent = (float) $rule['margin_percent'];
                            break;
                        }
                    }
                }
            }
        }

        // Auto-calculated prices are rounded to the nearest 0.50
        // (e.g. 8.375 -> 8.50, 8.7625 -> 9.00)
        $selling_price = 0;

        if ($method === 'markup') {
            if ($percent > 0) {
                $selling_price = round($unit_cost * (1 + $percent / 100), 2);
            }
        } elseif ($percent > 0 && $percent < 100) {
                    $selling_price = round($unit_cost / (1 - $percent / 100), 2);
        }

        if ($selling_price > 0) {
            $selling_price = ceil($selling_price * 2) / 2;

            // Record the effective margin of the rounded price as a percentage
            // of the selling price for debugging purposes only. We do NOT
            // overwrite items.unit_price here because that would cause new
            // receipts to change the selling price of existing (older) batches —
            // in a proper FIFO system each batch carries its own unit_selling_price
            // and the item-level unit_price must be left untouched so that old
            // stock continues to sell (and be returned) at its own price.
            $effective_margin = ($selling_price - $unit_cost) / $selling_price * 100;

            $this->db->table('items')
                ->where('item_id', $item_id)
                ->update([
                    'last_margin_percent' => $effective_margin
                ]);

            return $selling_price;
        }

        // Invalid or zero percentage: keep the current selling price
        return $current_price;
    }


    /**
     * @throws ReflectionException
     */
    public function delete_list(array $receiving_ids, int $employee_id, bool $update_inventory = true): bool
    {
        $success = true;

        // Start a transaction to assure data integrity
        $this->db->transStart();

        foreach ($receiving_ids as $receiving_id) {
            $success &= $this->delete_value($receiving_id, $employee_id, $update_inventory);
        }

        // Execute transaction
        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * @throws ReflectionException
     */
    public function delete_value(int $receiving_id, int $employee_id, bool $update_inventory = true): bool
    {
        // Start a transaction to assure data integrity
        $this->db->transStart();

        if ($update_inventory) {
            // TODO: defect, not all item deletions will be undone? get array with all the items involved in the sale to update the inventory tracking
            $items = $this->get_receiving_items($receiving_id)->getResultArray();

            $inventory = model('Inventory');
            $item_quantity = model(Item_quantity::class);

            foreach ($items as $item) {
                // Create query to update inventory tracking
                $inv_data = [
                    'trans_date'      => now(),
                    'trans_items'     => $item['item_id'],
                    'trans_user'      => $employee_id,
                    'trans_comment'   => 'Deleting receiving ' . $receiving_id,
                    'trans_location'  => $item['item_location'],
                    'trans_inventory' => $item['quantity_purchased'] * (-$item['receiving_quantity'])
                ];
                // Update inventory
                $inventory->insert($inv_data, false);

                // Update quantities
                $item_quantity->change_quantity($item['item_id'], $item['item_location'], $item['quantity_purchased'] * (-$item['receiving_quantity']));
            }
        }

        // Delete all items
        $builder = $this->db->table('receivings_items');
        $builder->delete(['receiving_id' => $receiving_id]);

        // Delete the FIFO batches that were created by this receiving so that
        // the batch table stays in sync with actual stock levels.
        $item_batch = model(Item_batch::class);
        $this->db->table($item_batch::TABLE)
            ->where('receiving_id', $receiving_id)
            ->delete();

        // Delete sale itself
        $builder = $this->db->table('receivings');
        $builder->delete(['receiving_id' => $receiving_id]);

        // Execute transaction
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * @param int $receiving_id
     * @return ResultInterface
     */
    public function get_receiving_items(int $receiving_id): ResultInterface
    {
        $builder = $this->db->table('receivings_items');
        $builder->where('receiving_id', $receiving_id);

        return $builder->get();
    }

    /**
     * @param int $receiving_id
     * @return object
     */
    public function get_supplier(int $receiving_id): object
    {
        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);

        $supplier = model(Supplier::class);
        return $supplier->get_info($builder->get()->getRow()->supplier_id);
    }

    /**
     * @return array
     */
    public function get_payment_options(): array
    {
        return [
            lang('Sales.cash') => lang('Sales.cash'),
            lang('Sales.check') => lang('Sales.check'),
            lang('Sales.debit') => lang('Sales.debit'),
            lang('Sales.credit') => lang('Sales.credit'),
            lang('Sales.due') => lang('Sales.due'),
            lang('Sales.bank_transfer') => lang('Sales.bank_transfer'),
            lang('Sales.wallet') => lang('Sales.wallet')
        ];
    }

    /**
     * Create a temp table that allows us to do easy report/receiving queries
     */
    public function create_temp_table(array $inputs): void
    {
        $config = config(OSPOS::class)->settings;
        $db_prefix = $this->db->getPrefix();

        if (empty($inputs['receiving_id'])) {
            $where = empty($config['date_or_time_format'])
                ? 'DATE(`receiving_time`) BETWEEN ' . $this->db->escape($inputs['start_date']) . ' AND ' . $this->db->escape($inputs['end_date'])
                : 'receiving_time BETWEEN ' . $this->db->escape(rawurldecode($inputs['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date']));
        } else {
            $where = 'receivings_items.receiving_id = ' . $this->db->escape($inputs['receiving_id']);
        }

        $builder = $this->db->table('receivings_items');
        $builder->select([
            'MAX(DATE(`receiving_time`)) AS receiving_date',
            'MAX(`receiving_time`) AS receiving_time',
            'receivings_items.receiving_id AS receiving_id',
            'MAX(`comment`) AS comment',
            'MAX(`item_location`) AS item_location',
            'MAX(`reference`) AS reference',
            'MAX(`payment_type`) AS payment_type',
            'MAX(`employee_id`) AS employee_id',
            'items.item_id AS item_id',
            'MAX(`' . $db_prefix . 'receivings`.`supplier_id`) AS supplier_id',
            'MAX(`quantity_purchased`) AS quantity_purchased',
            'MAX(`' . $db_prefix . 'receivings_items`.`receiving_quantity`) AS item_receiving_quantity',
            'MAX(`item_cost_price`) AS item_cost_price',
            'MAX(`item_unit_price`) AS item_unit_price',
            'MAX(`discount`) AS discount',
            'MAX(`discount_type`) AS discount_type',
            'receivings_items.line AS line',
            'MAX(`serialnumber`) AS serialnumber',
            'MAX(`' . $db_prefix . 'receivings_items`.`description`) AS description',
            'MAX(CASE WHEN `' . $db_prefix . 'receivings_items`.`discount_type` = ' . PERCENT . ' THEN `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` * `discount` / 100 ELSE `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `discount` END) AS subtotal',
            'MAX(CASE WHEN `' . $db_prefix . 'receivings_items`.`discount_type` = ' . PERCENT . ' THEN `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` * `discount` / 100 ELSE `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `discount` END) AS total',
            'MAX((CASE WHEN `' . $db_prefix . 'receivings_items`.`discount_type` = ' . PERCENT . ' THEN `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` * `discount` / 100 ELSE `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - discount END) - (`item_cost_price` * `quantity_purchased`)) AS profit',
            'MAX(`item_cost_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` ) AS cost'
        ]);
        $builder->join('receivings', 'receivings_items.receiving_id = receivings.receiving_id', 'inner');
        $builder->join('items', 'receivings_items.item_id = items.item_id', 'inner');
        $builder->where($where);
        $builder->groupBy(['receivings_items.receiving_id', 'items.item_id', 'receivings_items.line']);
        $selectQuery = $builder->getCompiledSelect();

        // QueryBuilder does not support creating temporary tables.
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->prefixTable('receivings_items_temp') .
            ' (INDEX(receiving_date), INDEX(receiving_time), INDEX(receiving_id)) AS (' . $selectQuery . ')';

        $this->db->query($sql);
    }
}
