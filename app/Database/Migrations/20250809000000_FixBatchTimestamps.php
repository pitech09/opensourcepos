<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_FixBatchTimestamps extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.4_fix_batch_timestamps.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        // This migration is a data fix and cannot be reversed
    }
}
