<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_LocationAwareReceiving extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.5_location_aware_receiving.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->query('ALTER TABLE `ospos_item_batches` DROP INDEX `idx_item_batches_fifo`');
        $this->db->query("DELETE FROM `ospos_app_config` WHERE `key` IN ('default_shop_location_id', 'default_warehouse_location_id')");
    }
}
