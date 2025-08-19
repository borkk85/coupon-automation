// assets/js/admin.js
(function($) {
    'use strict';
    
    const CouponAutomation = {
        
        init: function() {
            this.bindEvents();
            this.checkAPIStatus();
            this.initTabs();
        },
        
        bindEvents: function() {
            // Quick action buttons
            $('#fetch-coupons-btn').on('click', this.handleFetchCoupons);
            $('#stop-automation-btn').on('click', this.handleStopAutomation);
            $('#clear-cache-btn').on('click', this.handleClearCache);
            $('#purge-expired-btn').on('click', this.handlePurgeExpired);
            
            // Test API buttons
            $('.test-api-btn').on('click', this.handleTestAPI);
            
            // Brand population
            $('#start-population').on('click', this.handleBrandPopulation);
            
            // Clear notifications
            $('#clear-notifications').on('click', this.handleClearNotifications);
        },
        
        initTabs: function() {
            $('.settings-tab').on('click', function(e) {
                e.preventDefault();
                const tabId = $(this).data('tab');
                
                // Update tab buttons
                $('.settings-tab').removeClass('active border-blue-500 text-blue-600')
                    .addClass('border-transparent text-gray-600');
                $(this).addClass('active border-blue-500 text-blue-600')
                    .removeClass('border-transparent text-gray-600');
                
                // Update tab content
                $('.tab-content').addClass('hidden');
                $(`#${tabId}-tab`).removeClass('hidden');
            });
        },
        
        handleFetchCoupons: function(e) {
            e.preventDefault();
            const $btn = $(this);
            
            CouponAutomation.setButtonLoading($btn, true);
            
            $.ajax({
                url: couponAutomation.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'fetch_coupons',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    if (response.success) {
                        CouponAutomation.showToast('success', 'Sync started successfully!');
                        CouponAutomation.updateLastSync();
                    } else {
                        CouponAutomation.showToast('error', response.data || 'Failed to start sync');
                    }
                },
                error: function() {
                    CouponAutomation.showToast('error', 'An error occurred. Please try again.');
                },
                complete: function() {
                    CouponAutomation.setButtonLoading($btn, false);
                }
            });
        },
        
        handleStopAutomation: function(e) {
            e.preventDefault();
            const $btn = $(this);
            
            if (!confirm('Are you sure you want to stop the automation?')) {
                return;
            }
            
            CouponAutomation.setButtonLoading($btn, true);
            
            $.ajax({
                url: couponAutomation.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'stop_automation',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    if (response.success) {
                        CouponAutomation.showToast('success', 'Automation stopped');
                    } else {
                        CouponAutomation.showToast('error', response.data || 'Failed to stop automation');
                    }
                },
                error: function() {
                    CouponAutomation.showToast('error', 'An error occurred. Please try again.');
                },
                complete: function() {
                    CouponAutomation.setButtonLoading($btn, false);
                }
            });
        },
        
        handleClearCache: function(e) {
            e.preventDefault();
            const $btn = $(this);
            
            CouponAutomation.setButtonLoading($btn, true);
            
            $.ajax({
                url: couponAutomation.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'clear_coupon_flags',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    if (response.success) {
                        CouponAutomation.showToast('success', 'Cache cleared successfully');
                    } else {
                        CouponAutomation.showToast('error', response.data || 'Failed to clear cache');
                    }
                },
                error: function() {
                    CouponAutomation.showToast('error', 'An error occurred. Please try again.');
                },
                complete: function() {
                    CouponAutomation.setButtonLoading($btn, false);
                }
            });
        },
        
        handlePurgeExpired: function(e) {
            e.preventDefault();
            const $btn = $(this);
            
            if (!confirm('Are you sure you want to purge all expired coupons?')) {
                return;
            }
            
            CouponAutomation.setButtonLoading($btn, true);
            
            $.ajax({
                url: couponAutomation.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'purge_expired_coupons',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    if (response.success) {
                        CouponAutomation.showToast('success', response.data);
                    } else {
                        CouponAutomation.showToast('error', response.data || 'Failed to purge coupons');
                    }
                },
                error: function() {
                    CouponAutomation.showToast('error', 'An error occurred. Please try again.');
                },
                complete: function() {
                    CouponAutomation.setButtonLoading($btn, false);
                }
            });
        },
        
        handleTestAPI: function(e) {
            e.preventDefault();
            const $btn = $(this);
            const api = $btn.data('api');
            
            $btn.html('<svg class="animate-spin h-5 w-5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>');
            
            $.ajax({
                url: couponAutomation.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'test_api_connection',
                    api: api,
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $btn.html('<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>');
                        CouponAutomation.updateAPIStatus(api, true);
                    } else {
                        $btn.html('<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>');
                        CouponAutomation.updateAPIStatus(api, false);
                    }
                    
                    setTimeout(function() {
                        $btn.html('<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>');
                    }, 3000);
                },
                error: function() {
                    $btn.html('<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>');
                    CouponAutomation.updateAPIStatus(api, false);
                }
            });
        },
        
        handleBrandPopulation: function(e) {
            e.preventDefault();
            const $btn = $(this);
            let isProcessing = true;
            let processedCount = 0;
            
            CouponAutomation.setButtonLoading($btn, true);
            $('.brand-population-progress').show();
            
            function processBatch() {
                if (!isProcessing) return;
                
                $.ajax({
                    url: couponAutomation.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'populate_brands_batch',
                        nonce: couponAutomation.nonce,
                        offset: processedCount
                    },
                    success: function(response) {
                        if (response.success) {
                            processedCount += response.data.processed;
                            const totalBrands = response.data.total;
                            const percentage = (processedCount / totalBrands) * 100;
                            
                            $('.progress').css('width', percentage + '%');
                            $('.processed-count').text(processedCount);
                            $('.total-count').text(totalBrands);
                            
                            response.data.log.forEach(function(entry) {
                                $('.log-entries').prepend('<p class="text-sm text-gray-600">' + entry + '</p>');
                            });
                            
                            if (processedCount < totalBrands && isProcessing) {
                                setTimeout(processBatch, 1000);
                            } else {
                                CouponAutomation.setButtonLoading($btn, false);
                                CouponAutomation.showToast('success', 'Brand population completed!');
                            }
                        }
                    },
                    error: function() {
                        isProcessing = false;
                        CouponAutomation.setButtonLoading($btn, false);
                        CouponAutomation.showToast('error', 'An error occurred during processing');
                    }
                });
            }
            
            processBatch();
        },
        
        handleClearNotifications: function(e) {
            e.preventDefault();
            
            $.ajax({
                url: couponAutomation.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'clear_notifications',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#notifications-container').fadeOut();
                    }
                }
            });
        },
        
        checkAPIStatus: function() {
            $('.api-status-indicator').each(function() {
                const $indicator = $(this);
                const api = $indicator.data('api');
                
                // Check each API on page load
                $.ajax({
                    url: couponAutomation.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'test_api_connection',
                        api: api,
                        nonce: couponAutomation.nonce
                    },
                    success: function(response) {
                        CouponAutomation.updateAPIStatus(api, response.success);
                    }
                });
            });
        },
        
        updateAPIStatus: function(api, isConnected) {
            const $indicator = $(`.api-status-indicator[data-api="${api}"] span`);
            
            if (isConnected) {
                $indicator.removeClass('bg-gray-300 bg-red-500').addClass('bg-green-500');
            } else {
                $indicator.removeClass('bg-gray-300 bg-green-500').addClass('bg-red-500');
            }
        },
        
        updateLastSync: function() {
            // Update the last sync time in the UI
            const now = new Date();
            $('.last-sync-time').text('Just now');
        },
        
        setButtonLoading: function($btn, isLoading) {
            if (isLoading) {
                $btn.prop('disabled', true)
                    .addClass('opacity-50 cursor-not-allowed')
                    .find('svg').addClass('animate-spin');
            } else {
                $btn.prop('disabled', false)
                    .removeClass('opacity-50 cursor-not-allowed')
                    .find('svg').removeClass('animate-spin');
            }
        },
        
        showToast: function(type, message) {
            const toastId = 'toast-' + Date.now();
            const bgColor = type === 'success' ? 'bg-green-500' : 'bg-red-500';
            const icon = type === 'success' 
                ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>'
                : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
            
            const toast = `
                <div id="${toastId}" class="toast flex items-center w-full max-w-xs p-4 mb-4 text-white ${bgColor} rounded-lg shadow-lg">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        ${icon}
                    </svg>
                    <div class="text-sm font-normal">${message}</div>
                    <button class="ml-auto -mx-1.5 -my-1.5 text-white hover:text-gray-200 p-1.5" onclick="$('#${toastId}').remove()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            `;
            
            $('#toast-container').append(toast);
            
            // Auto remove after 5 seconds
            setTimeout(function() {
                $(`#${toastId}`).css('animation', 'slideOut 0.3s ease-out forwards');
                setTimeout(function() {
                    $(`#${toastId}`).remove();
                }, 300);
            }, 5000);
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        CouponAutomation.init();
    });
    
})(jQuery);