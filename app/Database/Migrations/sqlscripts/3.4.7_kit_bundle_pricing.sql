-- Kit bundle pricing: kits sell at their linked kit item's price (x quantity,
-- minus the kit discount) instead of the sum of their components' prices.
-- The linked kit item (ospos_item_kits.item_id) holds the bundle price.
-- Kits that have no linked item yet are handled in the migration runner,
-- which creates one at the kit's current effective (component-sum) price.
UPDATE `ospos_item_kits` SET `price_option` = 1, `print_option` = 2;    -- 1 = PRICE_OPTION_KIT, 2 = PRINT_KIT
