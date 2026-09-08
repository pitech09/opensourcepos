<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_FifoAutoPricing extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.2_fifo_auto_pricing.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->query('ALTER TABLE `ospos_sales_items` DROP COLUMN IF EXISTS `cost_price`');
        $this->db->query('ALTER TABLE `ospos_items` DROP COLUMN IF EXISTS `last_margin_percent`');
        $this->db->table('app_config')
            ->whereIn('key', ['default_margin_percent', 'margin_quantity_rules'])
            ->delete();
    }
}
