<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_PricingMethodConfig extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.2_pricing_method_config.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->query('ALTER TABLE `ospos_items` DROP COLUMN IF EXISTS `markup_percent`');
        $this->db->query('ALTER TABLE `ospos_items` DROP COLUMN IF EXISTS `margin_percent`');
        $this->db->query('ALTER TABLE `ospos_items` DROP COLUMN IF EXISTS `pricing_method`');
        $this->db->table('app_config')
            ->whereIn('key', ['default_pricing_method', 'default_markup_percent'])
            ->delete();
    }
}
