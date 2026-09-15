<?php

namespace Tests\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Models\Item;
use App\Models\Item_batch;
use App\Models\Stock_location;

class ItemBatchFifoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace    = null;

    private Item $itemModel;
    private Item_batch $itemBatchModel;
    private Stock_location $stockLocationModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->itemModel = model(Item::class);
        $this->itemBatchModel = model(Item_batch::class);
        $this->stockLocationModel = model(Stock_location::class);
    }

    /**
     * Test that when receiving two batches with different costs,
     * each batch retains its own selling price and old stock
     * continues to sell at its original price.
     *
     * Scenario:
     * 1. Receive 5 units at cost $4.80 (selling price becomes $6.00 with 20% margin)
     * 2. Receive 5 more units at cost $6.40 (selling price becomes $8.00 with 20% margin)
     * 3. Verify old batch still has $6.00 selling price (not affected by new batch)
     * 4. Verify new batch has $8.00 selling price
     * 5. Verify item.unit_price is NOT changed (remains $6.00 from first batch)
     */
    public function testFifoSellingPricePreservedAcrossBatches(): void
    {
        // Create test item
        $itemId = $this->itemModel->insert([
            'name'          => 'Test FIFO Item',
            'category'      => 'Test',
            'supplier_id'   => 1,
            'item_number'   => 'FIFO-TEST-001',
            'cost_price'    => 4.80,
            'unit_price'    => 6.00,  // Initial selling price
            'minimum_quantity' => 1,
            'stock_type'    => 1,  // HAS_STOCK
            'description'   => 'Test item for FIFO',
            'long_description' => '',
            'tax_category_id' => null,
            'pricing_method' => null,
        ]);

        // Get default stock location
        $stockLocation = $this->stockLocationModel->get_default_location();
        $locationId = $stockLocation->location_id;

        // Configure margin-based pricing: 20% margin
        $appconfig = model(\App\Models\Appconfig::class);
        $appconfig->set('default_margin_percent', '20');
        $appconfig->set('default_pricing_method', 'margin');
        $appconfig->set('margin_quantity_rules', '[]');

        // Receive first batch: 5 units at cost $4.80
        // With 20% margin, selling price = $4.80 / (1 - 0.20) = $6.00
        $sellingPrice1 = $this->getSellingPriceForCost(4.80, 20, 'margin');
        
        // Create batch directly (simulating receiving)
        $batchId1 = $this->itemBatchModel->create_batch(
            $itemId,
            $locationId,
            5.0,
            4.80,
            $sellingPrice1
        );

        // Verify first batch was created with correct prices
        $batch1 = $this->itemBatchModel->get_batch($batchId1);
        $this->assertEqualsWithDelta(5.0, $batch1->remaining, 0.01);
        $this->assertEqualsWithDelta(4.80, $batch1->unit_cost_price, 0.01);
        $this->assertEqualsWithDelta(6.00, $batch1->unit_selling_price, 0.01);

        // Check item.unit_price is still $6.00 (should not have changed)
        $item = $this->itemModel->get_info($itemId);
        $this->assertEqualsWithDelta(6.00, $item->unit_price, 0.01);

        // Receive second batch: 5 units at cost $6.40
        // With 20% margin, selling price = $6.40 / (1 - 0.20) = $8.00
        $sellingPrice2 = $this->getSellingPriceForCost(6.40, 20, 'margin');
        
        $batchId2 = $this->itemBatchModel->create_batch(
            $itemId,
            $locationId,
            5.0,
            6.40,
            $sellingPrice2
        );

        // Verify second batch was created with correct prices
        $batch2 = $this->itemBatchModel->get_batch($batchId2);
        $this->assertEqualsWithDelta(5.0, $batch2->remaining, 0.01);
        $this->assertEqualsWithDelta(6.40, $batch2->unit_cost_price, 0.01);
        $this->assertEqualsWithDelta(8.00, $batch2->unit_selling_price, 0.01);

        // CRITICAL: Verify first batch STILL has $6.00 selling price
        // This is the FIFO behavior - old stock should not be affected by new receipts
        $batch1After = $this->itemBatchModel->get_batch($batchId1);
        $this->assertEqualsWithDelta(6.00, $batch1After->unit_selling_price, 0.01,
            'Old batch selling price should remain unchanged after new batch received');

        // Verify item.unit_price is STILL $6.00 (the first batch's price)
        // It should NOT have been updated to $8.00
        $itemAfter = $this->itemModel->get_info($itemId);
        $this->assertEqualsWithDelta(6.00, $itemAfter->unit_price, 0.01,
            'Item unit_price should remain at first batch price, not new batch price');

        // Verify get_oldest_batch_selling_price returns the OLDEST batch's price
        $oldestPrice = $this->itemBatchModel->get_oldest_batch_selling_price($itemId, $locationId);
        $this->assertEqualsWithDelta(6.00, $oldestPrice, 0.01,
            'Oldest batch selling price should be $6.00 (first batch)');
    }

    /**
     * Helper to calculate selling price from cost and margin percentage.
     * margin = (selling_price - cost) / selling_price
     * selling_price = cost / (1 - margin/100)
     */
    private function getSellingPriceForCost(float $cost, float $marginPercent, string $method): float
    {
        if ($method === 'markup') {
            return round($cost * (1 + $marginPercent / 100), 2);
        }
        // margin method
        return round($cost / (1 - $marginPercent / 100), 2);
    }
}