<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class Probe extends BaseCommand
{
    protected $group = 'debug';
    protected $name = 'probe:migrate';
    protected $description = 'Probe executeScriptWithTransaction persistence';

    public function run(array $params)
    {
        helper('migration');
        $before = \Config\Database::connect()->table('item_kits')->select('price_option, print_option')->where('item_kit_id', 1)->get()->getRow();
        CLI::write("before: price_option={$before->price_option} print_option={$before->print_option}");

        $result = executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.7_kit_bundle_pricing.sql');
        CLI::write('helper returned: ' . var_export($result, true));

        // Force a fresh read (bypass any query cache)
        $after = \Config\Database::connect()->table('item_kits')->select('price_option, print_option')->where('item_kit_id', 1)->get()->getRow();
        CLI::write("after: price_option={$after->price_option} print_option={$after->print_option}");
    }
}
