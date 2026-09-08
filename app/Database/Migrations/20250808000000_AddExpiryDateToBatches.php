<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_AddExpiryDateToBatches extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.3_add_expiry_date_to_batches.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->query('ALTER TABLE `ospos_item_batches` DROP COLUMN IF EXISTS `expiry_date`');
        $this->db->query("DELETE FROM `ospos_app_config` WHERE `key` = 'expiry_warning_days'");
    }
}
