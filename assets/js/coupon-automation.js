
jQuery(document).ready(function($) {
    $('#fetch-coupons-button').on('click', function(e) {
        e.preventDefault();
        console.log('Button clicked');
        $('.coupon-messages').html('<div class="updated"><p>Processing coupons and scheduling next run. Please wait...</p></div>');
        $.ajax({
            url: couponAutomation.ajax_url,
            method: 'POST',
            data: {
                action: 'fetch_coupons',
                nonce: couponAutomation.nonce,
                force_start: true
            },
            success: function(response) {
                console.log('AJAX URL:', couponAutomation.ajax_url);
                console.log('AJAX success response:', response);
                if (response.success) {
                    $('.coupon-messages').html('<div class="updated"><p>Coupons processed and next run scheduled for tomorrow.</p></div>');
                } else {
                    $('.coupon-messages').html('<div class="error"><p>Failed to process coupons or schedule next run.</p></div>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('AJAX error response:', textStatus, errorThrown);
                $('.coupon-messages').html('<div class="error"><p>An error occurred while processing coupons.</p></div>');
            }
        });
    });
    $('#stop-automation-button').on('click', function(e) {
        e.preventDefault();
        console.log('Stop Automation button clicked');
        $('.coupon-messages').html('<div class="updated"><p>Stopping automation. Please wait...</p></div>');
        $.ajax({
            url: couponAutomation.ajax_url,
            method: 'POST',
            data: {
                action: 'stop_automation',
                nonce: couponAutomation.stop_nonce
            },
            success: function(response) {
                console.log('Stop automation response:', response);
                if (response.success) {
                    $('.coupon-messages').html('<div class="updated"><p>' + response.data + '</p></div>');
                } else {
                    $('.coupon-messages').html('<div class="error"><p>' + response.data + '</p></div>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error response:', textStatus, errorThrown);
                $('.coupon-messages').html('<div class="error"><p>An error occurred while stopping the automation.</p></div>');
            }
        });
    });
    $('#clear-flags-button').on('click', function(e) {
        e.preventDefault();
        console.log('Clear Transients button clicked');
        $('.coupon-messages').html('<div class="updated"><p>Clearing transients. Please wait...</p></div>');
        $.ajax({
            url: couponAutomation.ajax_url,
            method: 'POST',
            data: {
                action: 'clear_coupon_flags',
                nonce: couponAutomation.clear_nonce
            },
            success: function(response) {
                console.log('Clear transients response:', response);
                if (response.success) {
                    $('.coupon-messages').html('<div class="updated"><p>' + response.data + '</p></div>');
                } else {
                    $('.coupon-messages').html('<div class="error"><p>' + response.data + '</p></div>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error response:', textStatus, errorThrown);
                $('.coupon-messages').html('<div class="error"><p>An error occurred while clearing transients.</p></div>');
            }
        });
    });
    $('#purge-expired-coupons').click(function() {
        var button = $(this);
        button.prop('disabled', true);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'purge_expired_coupons',
                nonce: couponAutomation.purge_nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.coupon-messages').html('<div class="updated"><p>' + response.data + '</p></div>');
                } else {
                    $('.coupon-messages').html('<div class="error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $('.coupon-messages').html('<div class="error"><p>An error occurred while purging expired coupons.</p></div>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});