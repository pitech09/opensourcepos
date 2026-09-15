<?php
/**
 * Stock transfer form - restyled to match OSPOS UI (Bootstrap 3 + globals).
 * Stock always moves warehouse -> shop: the source is fixed to the warehouse
 * and the destination is picked from the To dropdown.
 *
 * @var array $locations
 * @var array $destinations
 * @var int|null $warehouse_id
 * @var string $warehouse_name
 * @var string $controller_name
 */
?>

<?= view('partial/header') ?>

<style>
    /* Highlight for keyboard-selected item in the autocomplete search results.
       Matches sales/register.php so the active suggestion stays readable
       (jQuery UI's default white text is invisible on #f5f5f5). */
    ul.ui-autocomplete li.ui-menu-item .ui-menu-item-wrapper.ui-state-active {
        background-color: #f5f5f5;
        border-left: 3px solid #007bff;
        font-weight: bold;
        color: #333333;
    }
</style>

<div id="title_bar" class="btn-toolbar print_hide">
    <a class="btn btn-info btn-sm pull-right modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="items/view" title="<?= lang('Sales.new_item') ?>">
        <span class="glyphicon glyphicon-tag">&nbsp;</span><?= lang('Sales.new_item') ?>
    </a>
    <a href="<?= site_url('receivings') ?>" class="btn btn-default btn-sm pull-right">
        <span class="glyphicon glyphicon-arrow-left">&nbsp;</span><?= lang('Common.back') ?>
    </a>
</div>

<div id="toolbar">
    <h4><span class="glyphicon glyphicon-transfer">&nbsp;</span><?= lang('Stock_transfer.title') ?></h4>
</div>

<div id="stock_transfer_message" class="alert alert-dismissible" style="display:none;" role="alert">
    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    <span id="stock_transfer_message_text"></span>
</div>

<?= form_open('stock_transfer/transfer', ['id' => 'transfer_form', 'class' => 'form-horizontal panel panel-default']) ?>
    <div class="panel-heading">
        <h3 class="panel-title"><span class="glyphicon glyphicon-transfer">&nbsp;</span><?= lang('Stock_transfer.form_title') ?></h3>
    </div>
    <div class="panel-body">
        <div class="form-group form-group-sm">
            <?= form_label(lang('Stock_transfer.select_item'), 'item_search', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <div class="input-group">
                    <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-barcode"></span></span>
                    <?= form_input(['name' => 'item_search', 'id' => 'item_search', 'class' => 'form-control input-sm', 'placeholder' => lang('Stock_transfer.search_placeholder'), 'autocomplete' => 'off']) ?>
                    <input type="hidden" name="item_id" id="item_id" value="">
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm" id="transfer_route_group" style="display:none;">
            <label class="control-label col-xs-3"><?= lang('Stock_transfer.from_location') ?></label>
            <div class="col-xs-8">
                <p class="form-control-static" id="transfer_route"><?= esc($warehouse_name) ?> → <span id="transfer_route_to"></span></p>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Stock_transfer.to_location'), 'to_location', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <?= form_dropdown('to_location', ['' => lang('Stock_transfer.select_first')] + ($destinations ?? []), '', ['id' => 'to_location', 'class' => 'form-control']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Stock_transfer.quantity'), 'quantity', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-8">
                <?= form_input(['name' => 'quantity', 'id' => 'quantity', 'type' => 'number', 'step' => '0.01', 'min' => '0.01', 'class' => 'form-control input-sm']) ?>
                <span class="help-block" id="available_qty"></span>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <div class="col-xs-offset-3 col-xs-8">
                <button type="submit" class="btn btn-primary btn-sm" id="transferBtn" disabled>
                    <span class="glyphicon glyphicon-transfer">&nbsp;</span><?= lang('Stock_transfer.transfer_btn') ?>
                </button>
            </div>
        </div>
    </div>
    <input type="hidden" name="from_location" id="from_location" value="<?= esc($warehouse_id ?? '') ?>" data-warehouse-id="<?= esc($warehouse_id ?? '') ?>" data-warehouse-name="<?= esc($warehouse_name ?? '') ?>" />
    <input type="hidden" name="to_location" id="to_location" value="" />
<?= form_close() ?>

<div class="panel panel-default" id="stockInfo" style="display:none;">
    <div class="panel-heading">
        <h3 class="panel-title"><span class="glyphicon glyphicon-list-alt">&nbsp;</span><?= lang('Stock_transfer.stock_title') ?></h3>
    </div>
    <div class="panel-body">
        <p class="help-block"><?= lang('Stock_transfer.stock_hint') ?></p>
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-striped">
                <thead>
                    <tr>
                        <th><?= lang('Stock_transfer.location') ?></th>
                        <th class="text-right"><?= lang('Stock_transfer.available') ?></th>
                        <th class="text-center"><?= lang('Stock_transfer.actions') ?></th>
                    </tr>
                </thead>
                <tbody id="stockGrid"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="transfer_confirm_modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><?= lang('Stock_transfer.confirm_title') ?></h4>
            </div>
            <div class="modal-body">
                <p id="transfer_confirm_text"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">
                    <span class="glyphicon glyphicon-remove">&nbsp;</span><?= lang('Common.cancel') ?>
                </button>
                <button type="button" class="btn btn-success btn-sm" id="transfer_confirm_btn">
                    <span class="glyphicon glyphicon-ok">&nbsp;</span><?= lang('Stock_transfer.confirm_transfer') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
$(document).ready(function() {
    var selectedItem = null;
    var stockQuantities = {};
    var stockRows = [];

    dialog_support.init('a.modal-dlg, button.modal-dlg');

    $('#item_search').autocomplete({
        // Same native pattern as Sales register (#item) and Receivings (#item):
        // jQuery UI autocomplete with a string source. jQuery UI appends
        // `?term=<typed text>` automatically and renders the dropdown with the
        // global .ui-autocomplete markup/CSS — no custom widget or styles.
        // Arrow Up/Down navigation, highlight, Enter-select and Escape-close
        // are all provided natively by the jQuery UI menu widget.
        source: "<?= esc('stock_transfer/search') ?>",
        minChars: 0,
        autoFocus: false,
        delay: 10,
        select: function(event, ui) {
            selectTransferItem(ui.item.value, ui.item.label);
            return false;
        }
    });

    $('#item_search').focus();

    $('#item_search').on('input', function() {
        if ($(this).val().trim() === '') {
            clearTransferItem();
        }
    });

    // Barcode scanners act as keyboards that type the full code in a single
    // fast burst terminated by Enter. Detect that burst: a long value typed
    // much faster than a human (< 50ms/key avg, min 4 chars) is looked up
    // with an exact item-number match and selected immediately, so scanning
    // never depends on the suggestion dropdown timing.
    var scanChars = 0;
    var scanStart = 0;

    $('#item_search').on('keydown', function() {
        var now = Date.now();
        if (!scanStart || (now - scanStart) > 500) {
            scanStart = now;
            scanChars = 0;
        }
        scanChars++;
    });

    function tryBarcodeScan(rawValue) {
        var value = (rawValue || '').trim();
        var chars = scanChars;
        var elapsed = scanStart ? (Date.now() - scanStart) : 0;
        scanStart = 0;
        scanChars = 0;
        if (value.length < 4 || chars < 4) {
            return false;
        }
        if (elapsed <= 0 || (elapsed / chars) > 50) {
            return false;    // typed by a human, let autocomplete handle it
        }
        $.getJSON("<?= esc('stock_transfer/search') ?>", { term: value }, function(data) {
            var current = $('#item_search').val().trim();
            if (current !== value) {
                return;    // user kept typing; ignore the stale scan result
            }
            var match = null;
            // Labels are built as "col1 | col2 | col3" (NAME_SEPARATOR);
            // an exact segment match on any part identifies the scanned item
            // regardless of the configured suggestion-column order.
            $.each(data || [], function(i, item) {
                var parts = String(item.label).split('|');
                for (var p = 0; p < parts.length; p++) {
                    if (parts[p].trim() === value) {
                        match = item;
                        return false;
                    }
                }
            });
            if (match) {
                $('#item_search').autocomplete('close');
                selectTransferItem(match.value, match.label);
            } else {
                showMessage('<?= lang('Stock_transfer.no_match_for_barcode') ?>'.replace('%s', value), 'warning');
            }
        });
        return true;
    }

    // Same keyboard handling as the Sales register: if a suggestion is
    // highlighted with the arrow keys, jQuery UI's select handler already
    // processed it — don't submit the form again. Otherwise Enter submits
    // the transfer form. Double-click/double-tap re-opens the list.
    $('#item_search').dblclick(function() {
        $(this).autocomplete('search');
    });

    $('#item_search').keypress(function(e) {
        if (e.which == 13) {
            // Barcode scanners terminate the scan with Enter: if the burst
            // detector recognises a scan, resolve it via exact item-number
            // match and stop here so the form is never submitted by a scan.
            if (tryBarcodeScan($(this).val())) {
                return false;
            }

            // If an autocomplete suggestion is highlighted, jQuery UI's select
            // handler already processed it — don't submit the form again.
            var itemAutocomplete = $('#item_search').autocomplete('instance');

            if (itemAutocomplete && itemAutocomplete.menu.element.find('.ui-menu-item-wrapper.ui-state-active').length) {
                return false;
            }

            // Otherwise treat Enter like a confirm only when the form validates.
            if (validateForm()) {
                $('#transfer_form').submit();
            }
            return false;
        }
    });

    $('#item_search').keyup(function(e) {
        if (e.which == 27) {
            $(this).autocomplete('close');
        }
    });

    $('#quantity').on('input', function() {
        if (selectedItem && $('#from_location').val()) {
            var maxQty = getAvailableQuantity();
            if (maxQty > 0 && parseFloat($(this).val()) > maxQty) {
                $(this).val(maxQty);
            }
            updateTransferButton();
        }
    });

    // The source is fixed to the warehouse: the only choice is the To
    // dropdown. Changing it re-renders the table highlights, refreshes the
    // available quantity and updates the route readout.
    $('#to_location').on('change', function() {
        renderStockGrid();
        updateAvailableQuantity();
    });

    function warehouseId() {
        return String($('#from_location').data('warehouse-id') || $('#from_location').val() || '');
    }

    function warehouseName() {
        return $('#from_location').data('warehouse-name') || '';
    }

    function renderStockGrid() {
        var $grid = $('#stockGrid');
        $grid.empty();
        var fromId = warehouseId();
        var toId = String($('#to_location').val() || '');
        $.each(stockRows, function(i, loc) {
            var id = String(loc.location_id);
            var $row = $('<tr></tr>');
            if (id === fromId) {
                $row.addClass('success');
            } else if (id === toId) {
                $row.addClass('info');
            }
            var $name = $('<td></td>').text(loc.location_name + ' ');
            if (id === fromId) {
                $name.append($('<span class="label label-success"></span>').text('<?= lang('Stock_transfer.selected_from') ?>'));
            } else if (id === toId) {
                $name.append($('<span class="label label-info"></span>').text('<?= lang('Stock_transfer.selected_to') ?>'));
            }
            $row.append($name);
            $row.append($('<td class="text-right"></td>').text(parseFloat(loc.quantity).toFixed(2) + ' <?= lang('Stock_transfer.units') ?>'));
            $grid.append($row);
        });
        updateTransferRoute();
    }

    function updateTransferRoute() {
        // The source never changes — always show the warehouse route.
        $('#transfer_route_group').show();
        var toName = $('#to_location option:selected').text();
        $('#transfer_route_to').text($('#to_location').val() ? toName : '…');
    }

    $('#transfer_form').on('submit', function(e) {
        e.preventDefault();
        if (!validateForm()) return;
        var confirmText = '<?= lang('Stock_transfer.confirm_message') ?>'
            .replace('%s', parseFloat($('#quantity').val()).toFixed(2))
            .replace('%s', warehouseName())
            .replace('%s', $('#to_location option:selected').text());
        $('#transfer_confirm_text').text(confirmText);
        $('#transfer_confirm_modal').modal('show');
    });

    $('#transfer_confirm_btn').on('click', function() {
        $('#transfer_confirm_modal').modal('hide');
        doTransfer();
    });

    function doTransfer() {
        var $btn = $('#transferBtn');
        $btn.prop('disabled', true).html('<span class="glyphicon glyphicon-refresh"></span> <?= lang('Stock_transfer.transferring') ?>');
        $.ajax({
            url: '<?= site_url('stock_transfer/transfer') ?>',
            type: 'POST',
            data: { item_id: selectedItem.id, from_location: $('#from_location').val(), to_location: $('#to_location').val(), quantity: $('#quantity').val() },
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    showMessage(response.message || '<?= lang('Stock_transfer.success') ?>', 'success');
                    var itemId = selectedItem ? selectedItem.id : null;
                    var itemLabel = $('#item_search').val();
                    var toId = $('#to_location').val();
                    resetForm();
                    // Keep the item and destination selected and refresh the
                    // per-location quantities so the user can move more stock.
                    if (itemId) {
                        selectedItem = { id: itemId };
                        $('#item_id').val(itemId);
                        $('#item_search').val(itemLabel);
                        $('#to_location').val(toId);
                        $('#quantity').val('');
                        loadStockInfo(itemId);
                    }
                } else {
                    showMessage((response && response.message) || '<?= lang('Stock_transfer.error') ?>', 'danger');
                }
                resetTransferButton();
            },
            error: function(xhr) {
                var msg = '<?= lang('Stock_transfer.error') ?>';
                try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch (e) {}
                showMessage(msg, 'danger');
                resetTransferButton();
            }
        });
    }

    function loadStockInfo(itemId) {
        $.post('<?= site_url('stock_transfer/get_item_quantities_by_location') ?>', { item_id: itemId }, function(data) {
            stockRows = data || [];
            stockQuantities = {};
            $.each(stockRows, function(i, loc) {
                stockQuantities[String(loc.location_id)] = parseFloat(loc.quantity) || 0;
            });
            $('#stockInfo').show();
            renderStockGrid();
            updateAvailableQuantity();
        }, 'json').fail(function() {
            // Quantity lookup failed (e.g. expired CSRF token after the page
            // sat idle, or a network error). Never show 0 as if it were real:
            // tell the user to reload instead.
            stockRows = [];
            stockQuantities = {};
            $('#stockInfo').show();
            renderStockGrid();
            showMessage('<?= lang('Stock_transfer.quantity_load_error') ?>', 'warning');
            updateTransferButton();
        });
    }

    // Shared selection handler used by the native jQuery UI `select` event:
    // stores the item, echoes the label back into the field, keeps the fixed
    // warehouse source, resets the destination, loads the per-location stock
    // table, and keeps focus on the search field so the user can keep typing
    // (same UX as Sales/Receivings).
    function selectTransferItem(itemId, label) {
        selectedItem = { id: itemId };
        $('#item_id').val(itemId);
        $('#item_search').val(label);
        $('#to_location').val('');
        $('#quantity').val('');
        loadStockInfo(itemId);
        $('#item_search').focus();
    }

    function clearTransferItem() {
        selectedItem = null;
        $('#item_id').val('');
        $('#to_location').val('');
        $('#stockInfo').hide();
        $('#stockGrid').empty();
        stockQuantities = {};
        stockRows = [];
        $('#available_qty').text('');
        $('#transfer_route_group').hide();
        updateTransferButton();
    }

    function getAvailableQuantity() {
        // Source is always the warehouse.
        if (!selectedItem || !warehouseId()) return 0;
        return stockQuantities[warehouseId()] || 0;
    }

    function updateAvailableQuantity() {
        var available = getAvailableQuantity();
        $('#available_qty').text('<?= lang('Stock_transfer.available') ?>: ' + available.toFixed(2));
        if (available > 0) { $('#quantity').val(available); } else { $('#quantity').val(''); }
        updateTransferButton();
    }

    function updateTransferButton() {
        var valid = selectedItem && warehouseId() && $('#to_location').val() && parseFloat($('#quantity').val()) > 0 && warehouseId() !== String($('#to_location').val() || '');
        $('#transferBtn').prop('disabled', !valid);
    }

    function validateForm() {
        if (!selectedItem) { showMessage('<?= lang('Stock_transfer.select_item_error') ?>', 'warning'); return false; }
        if (!warehouseId()) { showMessage('<?= lang('Stock_transfer.select_from_error') ?>', 'warning'); return false; }
        if (!$('#to_location').val()) { showMessage('<?= lang('Stock_transfer.select_to_error') ?>', 'warning'); return false; }
        if (warehouseId() === String($('#to_location').val())) { showMessage('<?= lang('Stock_transfer.same_location_error') ?>', 'warning'); return false; }
        var qty = parseFloat($('#quantity').val());
        if (isNaN(qty) || qty <= 0) { showMessage('<?= lang('Stock_transfer.invalid_qty_error') ?>', 'warning'); return false; }
        var available = getAvailableQuantity();
        if (qty > available) { showMessage('<?= lang('Stock_transfer.insufficient_stock') ?>'.replace('%s', qty.toFixed(2)).replace('%s', available.toFixed(2)), 'danger'); return false; }
        return true;
    }

    function showMessage(msg, type) {
        var $box = $('#stock_transfer_message');
        $box.removeClass('alert-success alert-danger alert-warning alert-info').addClass('alert-' + type).show();
        $('#stock_transfer_message_text').text(msg);
    }

    function resetTransferButton() {
        $('#transferBtn').html('<span class="glyphicon glyphicon-transfer">&nbsp;</span><?= lang('Stock_transfer.transfer_btn') ?>');
        updateTransferButton();
    }

    function resetForm() {
        selectedItem = null;
        $('#item_search').val('');
        $('#item_id').val('');
        // Keep the fixed warehouse source; only the destination resets.
        $('#to_location').val('');
        $('#quantity').val('');
        $('#available_qty').text('');
        $('#transfer_route_group').hide();
        $('#stockInfo').hide();
        $('#stockGrid').empty();
        stockQuantities = {};
        stockRows = [];
    }
});
</script>

<?= view('partial/footer') ?>
