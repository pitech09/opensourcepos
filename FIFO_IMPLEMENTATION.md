# FIFO Inventory Costing & Automatic Selling Price in OSPOS

This codebase implements FIFO (First-In, First-Out) inventory valuation and
COGS (Cost of Goods Sold) calculation using batch-level inventory tracking,
plus automatic selling price recalculation on receiving, replacing the
moving-average cost method.

## Configurable profit margins (Configuration → General)

The Configuration → General tab includes pricing settings:

- **Default Pricing Method** – `margin` (profit as % of selling price) or
  `markup` (profit as % of cost), stored in `default_pricing_method`.
- **Default Profit Margin** – `default_margin_percent` (0–99.99).
- **Default Markup** – `default_markup_percent` (0–999.99).

Formulas: margin → `price = cost / (1 - margin/100)`; markup →
`price = cost * (1 + markup/100)`.

### Per-item override (Item form)

The item form has a **Pricing Method** dropdown (Use Global Default / Margin /
Markup) with **Profit Margin %** and **Markup %** inputs, stored in
`ospos_items.pricing_method`, `margin_percent`, `markup_percent`
(NULL = use global default). The selling price recalculates **live** as cost,
method or percentage change (and a gross-profit readout is shown); the item's
stored price is only overwritten after the user changes one of those inputs.
Server-side, `Items::save()` computes the price via
`Item::calculate_selling_price_with()` only when the posted unit price is 0 —
a manually entered price always wins. Resolution helpers live in the Item
model (`get_pricing_config()`, `calculate_selling_price[_with]()`).

### Resolution order (auto-pricing on receiving)

`Receiving::_update_selling_price()` resolves method/percent as:

1. Item override (`pricing_method` + matching percent).
2. Global `default_pricing_method`:
   - markup → `default_markup_percent`;
   - margin → matching `margin_quantity_rules` rule by received quantity, else
     `default_margin_percent`.

The applied effective margin (% of selling price) is stored in
`items.last_margin_percent`, and the recalculated price is used as the new
batch's `unit_selling_price`.

## Automatic selling price calculation

When stock is received (`app/Models/Receiving.php::save()`), the selling price
for the new batch is calculated automatically from the new batch's unit cost and
a configurable profit margin:

    margin = (selling_price - cost) / selling_price
    => selling_price = cost / (1 - margin / 100), rounded to 2 decimals

The margin is resolved by `_update_selling_price()`:

- `margin_quantity_rules` config key: JSON array of
  `{"min_qty": 0, "max_qty": 50, "margin_percent": 20}` rules; the first rule
  whose range contains the received quantity wins.
- `default_margin_percent` config key is the fallback when no rule matches or
  the JSON is invalid.
- A margin of <= 0 or >= 100 leaves the current price unchanged (avoids
  division by zero / negative prices).

Both keys live in `ospos_app_config` (inserted by the
`20260908000000_FifoAutoPricing` migration with defaults 20% and the 3-tier
example rules). The margin used is recorded on the item in the
`ospos_items.last_margin_percent` column for debugging. The recalculated price
is stored as the new batch's `unit_selling_price` — **the item-level
`items.unit_price` is NOT overwritten**, so existing (older) batches keep
their own selling price and new receipts do not change the price of old stock.

`sales_items.cost_price` stores the actual FIFO COGS per sale line (sum of the
line's batch allocations); the per-batch split is kept in
`ospos_sales_items_batches`. Note on returns: OSPOS return lines carry no
reference to the original sale line, so returned stock is costed at the
item's last known FIFO unit cost (most recent sale allocation, falling back
to cost price).

## Database objects

Migrations (run with `php spark migrate` or the OSPOS upgrade flow):

- `20260907000000_AddItemBatches.php` ->
  `3.4.2_add_item_batches.sql` (batch tables + initial stock seeding)
- `20260908000000_FifoAutoPricing.php` ->
  `3.4.2_fifo_auto_pricing.sql` (config keys, `items.last_margin_percent`,
  `sales_items.cost_price`)

Tables created:

- **ospos_item_batches** – one batch per receiving line (and per positive
  adjustment / return). Columns: `batch_id`, `item_id`, `location_id`,
  `receiving_id`, `quantity`, `remaining`, `unit_cost_price`,
  `unit_selling_price`, `created_at`. Indexed on `item_id` and
  `(item_id, location_id, remaining)`.
- **ospos_sales_items_batches** – the per-batch allocation of every sale line
  (which batch each sold unit came from, at which cost/revenue). This is the
  actual FIFO COGS record.

Data migration for existing stock: for every item/location with stock on hand
and no batches yet, one seed batch is created with the current quantity and
the item's current cost price. Seeded batches are the oldest, so they are
consumed first.

Applying: run `php spark migrate` (or let the OSPOS upgrade flow run
migrations). Fresh installs get the tables and seed automatically.

## Where batch logic runs

| Operation | File | Behaviour |
|---|---|---|
| Receiving (stock in) | `app/Models/Receiving.php::save()` | Creates a batch per received line at the entered purchase cost (`Item_batch::create_batch()`). The batch's `unit_selling_price` is calculated from the batch cost and the configured margin rules (`_update_selling_price()`). The item-level `items.unit_price` is NOT overwritten — each batch keeps its own price so new receipts do not change the selling price of existing (older) inventory. Only `items.last_margin_percent` is updated (for debugging). |
| Sale (stock out) | `app/Models/Sale.php::save()` | Consumes batches oldest-first (`Item_batch::allocate_fifo()`) and records the per-batch split in `ospos_sales_items_batches` (`record_sale_allocation()`). Runs inside the sale's transaction. |
| Sale return (stock in) | `app/Models/Sale.php::save()` | Creates a new batch for the returned quantity, costed at the item's last known FIFO unit cost (falls back to cost price). The new batch's selling price is the oldest remaining batch's unit_selling_price (so new receipts do not change the return price). FIFO order is preserved. |
| Manual quantity edit | `app/Controllers/Items.php::save()` | Positive delta → new batch; negative delta → FIFO consumption (`consume_fifo()`). |
| Inventory adjustment ( +/- ) | `app/Controllers/Items.php::postSaveInventory()` | Same as above for the "new quantity" adjustment dialog. |
| CSV items import | `app/Controllers/Items.php` (location quantity import) | Same delta-based batch sync. |
| Kit sales | handled through the normal sale path in `Sale.php`. |

Note: `ospos_items.cost_price` is no longer recomputed as a moving average for
stock movements (the `receiving_calculate_average_price` config still updates
it for informational purposes); all COGS and inventory valuation now derive
from batches.

## Reports

- **Sales profit/cost reports** (`Summary_report`, `Detailed_sales`,
  `Specific_*`, and the sales search list in `Sale.php`): COGS comes from a
  per-line temp table (`sales_items_cost_temp`) aggregating
  `ospos_sales_items_batches.cost`, falling back to the line's stored
  `item_cost_price * quantity` for pre-FIFO sale lines. Profit = subtotal −
  FIFO cost.
- **Inventory summary / valuation** (`Inventory_summary`): each item/location
  is valued at `SUM(remaining * unit_cost_price)` from `ospos_item_batches`
  (with `cost_price` shown as the FIFO weighted-average batch cost), falling
  back to `items.cost_price * quantity` when no batches exist.
- **Receiving reports**: unchanged.

## Testing checklist

1. Receive the same item twice at different costs/quantities → two batches,
   each with the correct auto-calculated selling price per the margin rules.
2. Sell partially into the first batch, then the remainder → COGS matches the
   batch costs; batches decrement in `ospos_item_batches.remaining`.
3. Sell across two batches in one line → allocations recorded in
   `ospos_sales_items_batches` and the line's `sales_items.cost_price` equals
   the sum of the allocation costs.
4. Return a sale → returned units appear as a new (latest) batch at the last
   FIFO unit cost.
5. Positive and negative inventory adjustments (edit quantity, adjustment
   dialog, CSV import) keep `item_quantities.quantity` equal to
   `SUM(item_batches.remaining)`.
6. Run the inventory summary report → valuation equals
   `SUM(remaining * unit_cost_price)` per item/location.
7. Run sales/profit reports over sales made before and after the migration →
   pre-FIFO lines use stored cost, post-FIFO lines use batch cost.
8. Margin rules: change `margin_quantity_rules` / `default_margin_percent` in
   Configuration, then receive quantities in different ranges and verify the
   recalculated batch `unit_selling_price` (e.g. cost 2.00 @ qty 100 with
   15% → 2.35) and `last_margin_percent`.
9. Rounding: selling price is `round(cost / (1 - margin/100), 2)`.
10. **New stock does not change old stock price**: receive Batch A (cost 2.00 →
    selling 2.50), then receive Batch B (cost 5.00 → selling 6.25). Verify
    `items.unit_price` is NOT overwritten by Batch B. Sell from Batch A and
    confirm the sale uses 2.50 (Batch A's unit_selling_price), not 6.25.
    Return an item and confirm the return batch uses 2.50, not 6.25. Delete the
    receiving and confirm the batch is removed from `item_batches`. Delete a
    sale and confirm the batch `remaining` is restored.
