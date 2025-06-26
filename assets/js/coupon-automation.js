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

jQuery(document).ready(function($) {
    // Load daily status
    function loadDailyStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_processing_status',
                nonce: couponAutomation.settings_nonce
            },
            success: function(response) {
                if (response.success) {
                    var status = response.data;
                    var statusText = '';
                    var statusClass = '';

                    console.log('Status received:', status); // Debug log

                    // Determine status based on detailed information
                    if (status.is_running) {
                        if (status.detailed_status === 'fetching_data') {
                            statusText = '🔄 Fetching data from APIs...';
                            statusClass = 'status-running';
                        } else if (status.detailed_status === 'processing_coupons') {
                            statusText = '🔄 Processing coupons (' + status.processed_count + ' processed)';
                            statusClass = 'status-running';
                        } else {
                            statusText = '🔄 Currently processing...';
                            statusClass = 'status-running';
                        }
                        
                        if (status.lock_age_seconds) {
                            statusText += ' (running for ' + Math.floor(status.lock_age_seconds / 60) + 'min)';
                        }
                    } else if (status.completed_today) {
                        statusText = '✅ Processing completed for today (' + status.today + ')';
                        statusClass = 'status-completed';
                    } else if (status.status === 'failed' && status.last_error) {
                        statusText = '❌ Processing failed: ' + status.last_error;
                        statusClass = 'status-error';
                    } else if (status.scheduled_for && status.scheduled_for > Math.floor(Date.now() / 1000)) {
                        var scheduledDate = new Date(status.scheduled_for * 1000);
                        statusText = '⏰ Scheduled for ' + scheduledDate.toLocaleString();
                        statusClass = 'status-scheduled';
                    } else if (status.in_processing_window) {
                        statusText = '⏸️ Ready to process (window: 00:00 - 06:00)';
                        statusClass = 'status-ready';
                    } else {
                        statusText = '⏸️ Outside processing window (current: ' + status.current_hour + ':00)';
                        statusClass = 'status-waiting';
                    }

                    // Update the status text and add CSS class for styling
                    var statusElement = $('#daily-status');
                    statusElement.text(statusText);
                    statusElement.removeClass('status-running status-completed status-error status-scheduled status-ready status-waiting');
                    statusElement.addClass(statusClass);

                    // Update the main processing status if elements exist
                    var mainStatus = $('.status-value').first();
                    if (mainStatus.length) {
                        if (status.is_running) {
                            mainStatus.html('<span style="color: #d63638;">Running</span>');
                        } else if (status.completed_today) {
                            mainStatus.html('<span style="color: #00a32a;">Completed</span>');
                        } else if (status.status === 'failed') {
                            mainStatus.html('<span style="color: #d63638;">Failed</span>');
                        } else {
                            mainStatus.html('<span style="color: #72777c;">Idle</span>');
                        }
                    }

                    // Update last sync info
                    var lastSyncElement = $('.status-label').first();
                    if (lastSyncElement.length && status.last_sync) {
                        var lastSyncDate = new Date(status.last_sync + ' UTC');
                        var timeDiff = Math.floor((Date.now() - lastSyncDate.getTime()) / 1000);
                        var timeText = '';
                        
                        if (timeDiff < 60) {
                            timeText = timeDiff + ' seconds ago';
                        } else if (timeDiff < 3600) {
                            timeText = Math.floor(timeDiff / 60) + ' minutes ago';
                        } else if (timeDiff < 86400) {
                            timeText = Math.floor(timeDiff / 3600) + ' hours ago';
                        } else {
                            timeText = Math.floor(timeDiff / 86400) + ' days ago';
                        }
                        
                        lastSyncElement.text('Last sync: ' + timeText);
                    }
                } else {
                    $('#daily-status').text('❌ Error loading status: ' + (response.data || 'Unknown error'));
                    console.error('Status loading failed:', response);
                }
            },
            error: function(xhr, status, error) {
                $('#daily-status').text('❌ Failed to load status (connection error)');
                console.error('AJAX error:', status, error);
            }
        });
    }

    // Load status on page load
    loadDailyStatus();

    // Refresh status more frequently when processing is active
    var refreshInterval = 30000; // Default: 30 seconds
    
    function scheduleNextRefresh() {
        setTimeout(function() {
            loadDailyStatus();
            scheduleNextRefresh();
        }, refreshInterval);
    }
    
    // Start the refresh cycle
    scheduleNextRefresh();
    
    // Speed up refresh when buttons are clicked
    $(document).on('click', '#fetch-coupons-button, #stop-automation-button', function() {
        refreshInterval = 10000; // 10 seconds for faster updates
        setTimeout(function() {
            refreshInterval = 30000; // Back to 30 seconds after 2 minutes
        }, 120000);
    });
});