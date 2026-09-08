<?php

namespace App\Controllers;

use App\Models\Item;
use App\Models\Item_batch;
use App\Models\Appconfig;
use CodeIgniter\HTTP\ResponseInterface;

class Notifications extends Secure_Controller
{
    private Item $item;
    private Item_batch $item_batch;
    private Appconfig $appconfig;
    private array $config;

    public function __construct()
    {
        parent::__construct('notifications');

        $this->item = model(Item::class);
        $this->item_batch = model(Item_batch::class);
        $this->appconfig = model(Appconfig::class);
        $this->config = config(\Config\OSPOS::class)->settings;
    }

    /**
     * Returns JSON with the count of low stock items
     *
     * @return ResponseInterface
     */
    public function getLowStockCount(): ResponseInterface
    {
        $count = $this->item->get_low_stock_count();

        return $this->response->setJSON(['count' => $count]);
    }

    /**
     * Returns JSON with the count of expiring batches
     *
     * @return ResponseInterface
     */
    public function getExpiryCount(): ResponseInterface
    {
        $days_threshold = (int) $this->appconfig->get_value('expiry_warning_days', '30');
        $count = $this->item_batch->get_expiring_batches_count($days_threshold);

        return $this->response->setJSON(['count' => $count]);
    }
}
