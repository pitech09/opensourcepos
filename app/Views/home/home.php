<?php
/**
 * @var array $allowed_modules
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<h3 class="text-center"><?= lang('Common.welcome_message') ?></h3>


<div id="home_module_list">
    <?php foreach($allowed_modules as $module) { ?>
        <div class="module_item" title="<?= lang("Module.$module->module_id" . '_desc') ?>">
            <a href="<?= base_url($module->module_id) ?>"><img src="<?= base_url("images/menubar/$module->module_id.svg") ?>" style="border-width: 0; height: 64px; max-width: 64px;" alt="Menubar Image"></a>
            <a href="<?= base_url($module->module_id) ?>"><?= lang("Module.$module->module_id") ?></a>
        </div>
    <?php } ?>
</div>



<?php if (!empty($expiring_items)): ?>
<div class="alert" role="alert">
    <h4 class="alert-heading"><?= lang('Items.expiring_soon') ?></h4>
    <p><?= lang('Items.expiry_warning') ?></p>
    <table class="table table-sm table-bordered">
        <thead>
            <tr>
                <th><?= lang('Common.id') ?></th>
                <th><?= lang('Items.name') ?></th>
                <th><?= lang('Items.category') ?></th>
                <th><?= lang('Items.expiry_date') ?></th>
                
            </tr>
        </thead>
        <tbody>
            <?php foreach ($expiring_items as $item): ?>
            <tr>
                <td><?= esc($item->item_id) ?></td>
                <td><?= esc($item->name) ?></td>
                <td><?= esc($item->category) ?></td>
                <td><?= esc($item->expiry_date) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?= view('partial/footer') ?>
