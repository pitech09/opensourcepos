<script type="text/javascript">
    (function(lang, $) {

        var lines = {
            'common_submit': "<?= lang('Common.submit') ?>",
            'common_close': "<?= lang('Common.close') ?>"
        };

        $.extend(lang, {
            line: function(key) {
                return lines[key];
            }
        });

    })(window.lang = window.lang || {}, jQuery);
</script>

<script type="text/javascript">
    // Notification badge updates
    (function($) {
        function updateNotificationBadges() {
            // Update low stock count
            $.get('<?= base_url('notifications/low_stock_count') ?>', function(data) {
                var count = data.count || 0;
                var $badge = $('#low-stock-count');
                if (count > 0) {
                    $badge.text(count).show();
                } else {
                    $badge.hide();
                }
            });

            // Update expiry count
            $.get('<?= base_url('notifications/expiry_count') ?>', function(data) {
                var count = data.count || 0;
                var $badge = $('#expiry-count');
                if (count > 0) {
                    $badge.text(count).show();
                } else {
                    $badge.hide();
                }
            });
        }

        // Update on page load
        $(document).ready(function() {
            updateNotificationBadges();

            // Refresh every 60 seconds
            setInterval(updateNotificationBadges, 60000);
        });
    })(jQuery);
</script>
