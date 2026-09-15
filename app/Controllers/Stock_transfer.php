<?php

namespace App\Controllers;

use App\Models\Item;
use App\Models\Item_batch;
use App\Models\Item_quantity;
use App\Models\Stock_location;
use App\Models\Inventory;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;

/**
 * Stock transfer controller.
 *
 * Handles transferring stock quantities between stock locations.
 */
class Stock_transfer extends Secure_Controller
{
    private Item $itemModel;
    private Item_batch $itemBatchModel;
    private Item_quantity $itemQuantityModel;
    private Stock_location $stockLocationModel;
    private Inventory $inventoryModel;
    private BaseConnection $db;

    public function __construct()
    {
        parent::__construct();
        $this->itemModel = model(Item::class);
        $this->itemBatchModel = model(Item_batch::class);
        $this->itemQuantityModel = model(Item_quantity::class);
        $this->stockLocationModel = model(Stock_location::class);
        $this->inventoryModel = model(Inventory::class);
        $this->db = db_connect();
    }

    public function index(): void
    {
        $data = $this->_get_common_data();
        echo view('stock_transfer/manage', $data);
    }

    public function search(): ResponseInterface
    {
        // jQuery UI autocomplete sends the typed text as `term` (same as
        // Sales::getItemSearch / Receivings::getStockItemSearch). Accept the
        // legacy `search` parameter as well for backward compatibility.
        $search = trim((string) ($this->request->getGet('term') ?? ''));
        if ($search === '') {
            $search = trim((string) ($this->request->getGet('search') ?? ''));
        }

        if ($search === '') {
            return $this->response->setJSON([]);
        }

        // Warehouse-only suggestions: only items that physically exist in the
        // warehouse location (quantity > 0 in ospos_item_quantities) are
        // returned, for both typed search and barcode scans. Matches name /
        // item number / description directly so results don't depend on the
        // suggestion-column config. Returns the native jQuery UI shape
        // [{value: item_id, label: ...}] using the configured suggestion
        // format, so the dropdown renders like Sales & Receivings.
        $warehouseId = $this->_get_warehouse_location_id();

        if ($warehouseId === null) {
            return $this->response->setJSON([]);
        }

        $rows = $this->db->table('items')
            ->select('items.item_id, items.name, items.pack_name, items.item_number, items.description, items.cost_price, items.unit_price')
            ->join('item_quantities', 'item_quantities.item_id = items.item_id')
            ->where('items.deleted', 0)
            ->whereIn('items.item_type', [ITEM, ITEM_AMOUNT_ENTRY])
            ->where('items.stock_type', HAS_STOCK)
            ->where('item_quantities.location_id', $warehouseId)
            ->where('item_quantities.quantity >', 0)
            ->groupStart()
                ->like('items.name', $search)
                ->orLike('items.item_number', $search)
                ->orLike('items.description', $search)
            ->groupEnd()
            ->orderBy('items.name', 'ASC')
            ->limit(25)
            ->get()
            ->getResult();

        $suggestions = [];
        foreach ($rows as $row) {
            $suggestions[] = ['value' => (int) $row->item_id, 'label' => $this->itemModel->get_search_suggestion_label($row)];
        }

        return $this->response->setJSON($suggestions);
    }

    public function transfer()
    {
        $itemId       = $this->request->getPost('item_id');
        $fromLocation = $this->request->getPost('from_location');
        $toLocation   = $this->request->getPost('to_location');
        $quantity     = $this->request->getPost('quantity');

        // ---- Validation ----

        if (empty($itemId) || empty($fromLocation) || empty($toLocation) || empty($quantity)) {
            return $this->_error_response(400, lang('Stock_transfer.missing_fields'));
        }

        // Reject transfers where source and destination are the same location.
        if ((int) $fromLocation === (int) $toLocation) {
            return $this->_error_response(400, lang('Stock_transfer.same_location_error'));
        }

        if (!$this->stockLocationModel->exists((int) $fromLocation)) {
            return $this->_error_response(400, lang('Stock_transfer.invalid_from_location'));
        }

        if (!$this->stockLocationModel->exists((int) $toLocation)) {
            return $this->_error_response(400, lang('Stock_transfer.invalid_to_location'));
        }

        // Quantity is a float/decimal (step="0.01" in UI; DB column is decimal(15,3)).
        // Cast to float for consistency with the UI contract and DB schema.
        $quantity = (float) $quantity;
        if ($quantity <= 0.0) {
            return $this->_error_response(400, lang('Stock_transfer.invalid_quantity'));
        }

        $sourceQuantity = $this->itemQuantityModel->get_item_quantity((int) $itemId, (int) $fromLocation);
        // $sourceQuantity->quantity may be a string from the DB; cast for safe comparison.
        $available = (float) $sourceQuantity->quantity;
        if ($available < $quantity) {
            return $this->_error_response(400,
                sprintf(lang('Stock_transfer.insufficient_stock'),
                    number_format($quantity, 2),
                    number_format($available, 2))
            );
        }

        // Cache item info to avoid a duplicate database call.
        $itemInfo = $this->itemModel->get_info((int) $itemId);
        // HAS_STOCK (stock_type = 0) identifies stocked/warehouse items that use
        // FIFO batch tracking; only those need their batch records moved on transfer.
        $hasStock = ($itemInfo->stock_type == HAS_STOCK);

        // ---- Transfer (transactional) ----

        try {
            $this->db->transStart();

            // Update quantities. change_quantity() now accepts float, matching the DB
            // decimal(15,3) column and the UI's step="0.01" contract.
            $this->itemQuantityModel->change_quantity((int) $itemId, (int) $fromLocation, -$quantity);
            $this->itemQuantityModel->change_quantity((int) $itemId, (int) $toLocation,  $quantity);

            if ($hasStock) {
                $this->itemBatchModel->transfer_fifo((int) $itemId, (int) $fromLocation, (int) $toLocation, $quantity);
            }

            $employeeId = $this->session->get('person_id');

            // Build human-readable comment using location names.
            $fromName = $this->_get_location_name((int) $fromLocation);
            $toName   = $this->_get_location_name((int) $toLocation);
            $comment   = sprintf('TRANSFER %s -> %s', $fromName, $toName);

            $now = date('Y-m-d H:i:s');

            // Inventory records use float quantity to match the UI/decimal contract.
            $invDataSource = [
                'trans_date'     => $now,
                'trans_items'    => (int) $itemId,
                'trans_user'     => $employeeId,
                'trans_location' => (int) $fromLocation,
                'trans_comment'  => $comment,
                'trans_inventory' => -$quantity,
            ];
            $this->inventoryModel->insert($invDataSource, false);

            $invDataDest = [
                'trans_date'     => $now,
                'trans_items'    => (int) $itemId,
                'trans_user'     => $employeeId,
                'trans_location' => (int) $toLocation,
                'trans_comment'  => $comment,
                'trans_inventory' => $quantity,
            ];
            $this->inventoryModel->insert($invDataDest, false);

            $this->db->transComplete();

            if ($this->db->transStatus()) {
                return $this->response->setJSON(['success' => true, 'message' => lang('Stock_transfer.success')]);
            }

            // If transComplete() did not succeed, throw to reach the catch block.
            throw new \Exception('Transaction failed');

        } catch (\Exception $e) {
            // Rollback if the exception occurred before transComplete().
            $this->db->transRollback();
            log_message('error', 'Stock transfer failed: ' . $e->getMessage());
            return $this->_error_response(500, lang('Stock_transfer.error'));
        }
    }

    public function get_locations(): ResponseInterface
    {
        $locations = $this->stockLocationModel->get_all()->getResultArray();
        return $this->response->setJSON($locations);
    }

    public function get_item_quantity()
    {
        $itemId     = $this->request->getPost('item_id');
        $locationId = $this->request->getPost('location_id');

        if (empty($itemId) || empty($locationId)) {
            return $this->_error_response(400, lang('Stock_transfer.missing_fields'));
        }

        $qty = $this->itemQuantityModel->get_item_quantity((int) $itemId, (int) $locationId);
        return $this->response->setJSON(['quantity' => (float) $qty->quantity]);
    }

    public function get_item_quantities_by_location()
    {
        $itemId = $this->request->getPost('item_id');

        if (empty($itemId)) {
            return $this->_error_response(400, lang('Stock_transfer.missing_fields'));
        }

        $locations = $this->stockLocationModel->get_all()->getResultArray();

        // Batch-fetch all quantities for this item in a single query to avoid N+1.
        $quantities = $this->itemQuantityModel
            ->select('item_id, location_id, quantity')
            ->where('item_id', (int) $itemId)
            ->get()
            ->getResultArray();

        // Index by location_id for fast lookup.
        $qtyByLocation = [];
        foreach ($quantities as $q) {
            $qtyByLocation[$q['location_id']] = (float) $q['quantity'];
        }

        $data = [];
        foreach ($locations as $location) {
            $data[] = [
                'location_id'   => $location['location_id'],
                'location_name' => $location['location_name'],
                'quantity'      => $qtyByLocation[$location['location_id']] ?? 0.0,
            ];
        }

        return $this->response->setJSON($data);
    }

    private function _get_common_data(): array
    {
        $warehouseId = $this->_get_warehouse_location_id();
        $locations = $this->stockLocationModel->get_all()->getResultArray();

        // Exclude the warehouse itself from the destination list — stock
        // always moves warehouse -> shop, never back.
        $destinations = [];
        foreach ($locations as $location) {
            if ($warehouseId === null || (int) $location['location_id'] !== $warehouseId) {
                $destinations[$location['location_id']] = $location['location_name'];
            }
        }

        return [
            'locations'        => $locations,
            'destinations'     => $destinations,
            'warehouse_id'     => $warehouseId,
            'warehouse_name'   => $warehouseId !== null ? $this->_get_location_name($warehouseId) : '',
            'controller_name'  => 'stock_transfer',
        ];
    }

    /**
     * Returns the location_id of the source ("warehouse") stock location the
     * transfer moves stock out of. The location can be named anything —
     * resolution order:
     *
     *  1. The default_warehouse_location_id config value, when it points to a
     *     valid, non-deleted location that is not the shop.
     *  2. Otherwise the first undeleted stock location that is not the shop
     *     (default_shop_location_id), so any second location works.
     *
     * @return int|null The source location_id, or null when there is no
     * second location to transfer from.
     */
    private function _get_warehouse_location_id(): ?int
    {
        $config = config(OSPOS::class)->settings;
        $shop_location_id = (int) ($config['default_shop_location_id'] ?? 0);
        $warehouse_id = (int) ($config['default_warehouse_location_id'] ?? 0);

        // Validate the configured warehouse: it must exist, be active and not
        // be the shop itself.
        if ($warehouse_id > 0 && $warehouse_id !== $shop_location_id) {
            $exists = $this->db->table('stock_locations')
                ->where('location_id', $warehouse_id)
                ->where('deleted', 0)
                ->countAllResults();

            if ($exists > 0) {
                return $warehouse_id;
            }
        }

        // Name-independent fallback: the first active location other than the
        // shop. When no shop is configured, exclude the lowest location_id so
        // the "second" location is used as the source.
        $fallback = $this->stockLocationModel->get_warehouse_location_id($shop_location_id);

        return $fallback > 0 ? $fallback : null;
    }

    /**
     * Returns a consistent JSON error response.
     *
     * @param int    $statusCode HTTP status code.
     * @param string $message    Error message.
     * @return \CodeIgniter\HTTP\JSONResponse
     */
    private function _error_response(int $statusCode, string $message)
    {
        $this->response->setStatusCode($statusCode);
        return $this->response->setJSON(['success' => false, 'message' => $message]);
    }

    /**
     * Returns the name of a stock location by its ID.
     *
     * @param int $locationId
     * @return string
     */
    private function _get_location_name(int $locationId): string
    {
        $result = $this->stockLocationModel
            ->select('location_name')
            ->where('location_id', $locationId)
            ->get()
            ->getRow();

        return $result ? $result->location_name : (string) $locationId;
    }
}

