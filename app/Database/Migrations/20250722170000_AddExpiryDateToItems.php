<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_AddExpiryDateToItems extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.2_add_expiry_date.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->query('ALTER TABLE `ospos_items` DROP COLUMN IF EXISTS `expiry_date`');
    }
}