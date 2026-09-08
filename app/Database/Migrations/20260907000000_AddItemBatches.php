<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_AddItemBatches extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.2_add_item_batches.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `ospos_sales_items_batches`');
        $this->db->query('DROP TABLE IF EXISTS `ospos_item_batches`');
    }
}
