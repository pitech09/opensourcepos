<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_KitBundlePricing extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');

        // Kits without a linked kit item get one created: a non-stock ITEM_KIT
        // item named after the kit, priced at the kit's current effective
        // price (the component sum) so existing pricing is preserved. The
        // bundle price can then be adjusted on the linked item directly.
        $kits = $this->db->table('item_kits')
            ->select('item_kits.item_kit_id, item_kits.name, item_kits.item_id')
            ->where('item_kits.item_id', 0)
            ->get()->getResult();

        foreach ($kits as $kit) {
            $row = $this->db->query('SELECT COALESCE(SUM(i.unit_price * ki.quantity), 0) AS total_price
                FROM `ospos_item_kit_items` ki
                INNER JOIN `ospos_items` i ON i.item_id = ki.item_id
                WHERE ki.item_kit_id = ?', [$kit->item_kit_id])->getRow();

            $this->db->table('items')->insert([
                'name'        => $kit->name,
                'category'    => '',
                'item_type'   => ITEM_KIT,
                'stock_type'  => HAS_NO_STOCK,
                'unit_price'  => (float) ($row->total_price ?? 0),
                'cost_price'  => 0,
                'deleted'     => 0
            ]);
            $kit_item_id = $this->db->insertID();

            $this->db->table('item_kits')
                ->where('item_kit_id', $kit->item_kit_id)
                ->update(['item_id' => $kit_item_id]);
        }

        // Linked kit items must be non-stock ITEM_KIT items for the register
        // to price and print the bundle as a single line.
        $this->db->query('UPDATE `ospos_items` i
            INNER JOIN `ospos_item_kits` k ON k.item_id = i.item_id
            SET i.item_type = ' . ITEM_KIT . ', i.stock_type = ' . HAS_NO_STOCK . '
            WHERE i.item_type != ' . ITEM_KIT);

        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.7_kit_bundle_pricing.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        // Restore component-sum pricing options (0 = PRICE_OPTION_ALL / stock
        // pricing, 0 = PRINT_ALL) and unlink the kit items created above.
        $this->db->query('UPDATE `ospos_item_kits` SET `price_option` = 2, `print_option` = 0');

        $this->db->query('UPDATE `ospos_item_kits` k
            INNER JOIN `ospos_items` i ON i.item_id = k.item_id
            SET k.item_id = 0
            WHERE i.item_type = ' . ITEM_KIT . ' AND i.cost_price = 0 AND i.category = ""');
    }
}
