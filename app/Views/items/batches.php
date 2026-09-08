<?php
/**
 * @var array $batches
 * @var array $stock_locations
 *
 * FIFO batch report: shows each batch for an item with its remaining
 * quantity, unit cost and unit selling price, oldest batch first.
 */
?>

<?= view('partial/header') ?>

<div id="title_bar" class="btn-toolbar print_hide">
    <a class="btn btn-default btn-sm pull-right" href="<?= site_url('items') ?>">
        <span class="glyphicon glyphicon-arrow-left">&nbsp;</span><?= lang('Common.back') ?>
    </a>
</div>

<div id="toolbar">
    <h4><?= lang('Items.batches') ?></h4>
</div>

<div id="table_holder" class="table-responsive">
    <table class="table table-striped table-bordered" id="batches_table">
        <thead>
            <tr>
                <th><?= lang('Items.batch') ?></th>
                <th><?= lang('Items.name') ?></th>
                <th><?= lang('Items.stock_location') ?></th>
                <th><?= lang('Items.received') ?></th>
                <th><?= lang('Items.remaining') ?></th>
                <th><?= lang('Items.cost_price') ?></th>
                <th><?= lang('Items.unit_price') ?></th>
                <th><?= lang('Items.batch_value') ?></th>
                <th><?= lang('Items.received_at') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($batches as $batch): ?>
                <tr class="<?= $batch->remaining > 0 ? '' : 'text-muted' ?>">
                    <td>#<?= $batch->batch_id ?></td>
                    <td><?= esc($batch->item_name) ?></td>
                    <td><?= esc($batch->location_name) ?></td>
                    <td><?= to_quantity_decimals($batch->quantity) ?></td>
                    <td>
                        <?= to_quantity_decimals($batch->remaining) ?>
                        <?= $batch->remaining > 0 ? '' : '(' . lang('Items.batch_depleted') . ')' ?>
                    </td>
                    <td><?= to_currency($batch->unit_cost_price) ?></td>
                    <td><?= to_currency($batch->unit_selling_price) ?></td>
                    <td><?= to_currency($batch->remaining * $batch->unit_selling_price) ?></td>
                    <td><?= $batch->created_at ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if(empty($batches)): ?>
                <tr>
                    <td colspan="9"><?= lang('Items.no_batches') ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?= view('partial/footer') ?>
