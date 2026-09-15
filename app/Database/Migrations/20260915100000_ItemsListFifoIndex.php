<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_ItemsListFifoIndex extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.6_items_list_fifo_index.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->query('ALTER TABLE `ospos_item_batches` DROP INDEX `idx_item_batches_oldest_active`');
    }
}
