<?php

namespace CouponAutomation\Admin;

use CouponAutomation\Services\DataProcessor;
use CouponAutomation\Services\CouponService;
use CouponAutomation\Services\BrandService;
use CouponAutomation\API\AddRevenueAPI;
use CouponAutomation\API\AwinAPI;
use CouponAutomation\API\OpenAIAPI;
use CouponAutomation\API\YourlsAPI;
use CouponAutomation\Utils\Logger;

/**
 * Handle AJAX requests
 */
class AjaxHandler
{

    /**
     * Handle fetch coupons request
     */
    public function handleFetchCoupons()
    {
        error_log('[CA] Manual fetch triggered via admin button');
        
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('[CA] Fetch denied - insufficient permissions');
            wp_send_json_error(__('Insufficient permissions', 'coupon-automation'));
        }

        delete_option('coupon_automation_stop_requested');
        delete_transient('fetch_process_running');
        error_log('[CA] Cleared stop flag and running transient');

        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $target = $now->setTime(3, 0);

        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }

        $next_run = $target->getTimestamp();

        wp_clear_scheduled_hook('coupon_automation_daily_fetch');
        wp_schedule_event($next_run, 'daily', 'coupon_automation_daily_fetch');
        
        error_log('[CA] Rescheduled next run for: ' . date('Y-m-d H:i:s', $next_run));

        $logger = new Logger();
        $logger->activity(
            sprintf(__('Automation enabled. Next run scheduled for %s', 'coupon-automation'), date_i18n('M j, g:i a', $next_run)),
            'info'
        );

        wp_send_json_success(__('Automation resumed. Sync will run at the scheduled time.', 'coupon-automation'));
    }

    /**
     * Handle stop automation
     */
    public function handleStopAutomation()
    {
        error_log('[CA] Stop automation triggered via admin button');
        
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('[CA] Stop denied - insufficient permissions');
            wp_send_json_error('Insufficient permissions');
        }

        update_option('coupon_automation_stop_requested', true);
        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        wp_clear_scheduled_hook('coupon_automation_daily_fetch');
        
        error_log('[CA] Stop flag set, transients cleared, schedule cleared');

        $logger = new Logger();
        $logger->activity(__('Automation stopped by user', 'coupon-automation'), 'warning');

        wp_send_json_success(__('Automation stopped', 'coupon-automation'));
    }


    /**
     * Handle clear flags
     */
    public function handleClearFlags()
    {
        error_log('[CA] Clear flags triggered via admin button');
        
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');
        delete_option('coupon_automation_stop_requested');
        
        error_log('[CA] All flags and transients cleared successfully');

        wp_send_json_success('Flags and transients cleared');
    }

    /**
     * Handle clear notifications request
     */
    public function handleClearNotifications()
    {
        error_log('[CA] Clear notifications triggered');
        
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $notificationManager = new \CouponAutomation\Utils\NotificationManager();
        $notificationManager->clearAll();
        
        error_log('[CA] All notifications cleared');

        wp_send_json_success('Notifications cleared');
    }

    /**
     * Handle mark notifications as read
     */
    public function handleMarkNotificationsRead()
    {
        error_log('[CA] Mark notifications as read triggered');
        
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $notificationManager = new \CouponAutomation\Utils\NotificationManager();
        $count = $notificationManager->getUnreadCount();
        $notificationManager->markAllRead();
        
        error_log('[CA] Marked ' . $count . ' notifications as read');

        wp_send_json_success('Notifications marked as read');
    }

    /**
     * Handle purge expired coupons
     */
    public function handlePurgeExpired()
    {
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $couponService = new CouponService();
        $count = $couponService->purgeExpiredCoupons();

        wp_send_json_success("Purged $count expired coupons");
    }

    /**
     * Handle brand population batch
     */
    public function handleBrandPopulation()
    {
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $batchSize = 5;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        $brands = get_terms([
            'taxonomy' => 'brands',
            'hide_empty' => false,
            'number' => $batchSize,
            'offset' => $offset
        ]);

        $totalBrands = wp_count_terms('brands', ['hide_empty' => false]);
        $processed = 0;
        $log = [];

        $brandService = new BrandService();

        foreach ($brands as $brand) {
            $updates = [];

            // Process brand
            $brandService->updateBrandMeta($brand->term_id, []);

            $processed++;
            $log[] = sprintf('Processed brand: %s (ID: %d)', $brand->name, $brand->term_id);
        }

        wp_send_json_success([
            'processed' => $processed,
            'total' => $totalBrands,
            'log' => $log
        ]);
    }

    /**
     * Handle API connection test
     */
    public function handleTestConnection()
    {
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $api = isset($_POST['api']) ? sanitize_text_field($_POST['api']) : '';
        $result = false;
        $message = '';

        switch ($api) {
            case 'addrevenue':
                $api = new AddRevenueAPI();
                $result = $api->testConnection();
                $message = $result ? 'AddRevenue API connected successfully' : 'Failed to connect to AddRevenue API';
                break;

            case 'awin':
                $api = new AwinAPI();
                $result = $api->testConnection();
                $message = $result ? 'AWIN API connected successfully' : 'Failed to connect to AWIN API';
                break;

            case 'openai':
                $api = new OpenAIAPI();
                $result = $api->testConnection();
                $message = $result ? 'OpenAI API connected successfully' : 'Failed to connect to OpenAI API';
                break;

            case 'yourls':
                $api = new YourlsAPI();
                $result = $api->testConnection();
                $message = $result ? 'YOURLS API connected successfully' : 'Failed to connect to YOURLS API';
                break;

            default:
                $message = 'Invalid API specified';
        }

        if ($result) {
            wp_send_json_success($message);
        } else {
            wp_send_json_error($message);
        }
    }

    /**
     * Handle purge duplicates request
     */
    public function handlePurgeDuplicates()
    {
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'preview';
        $dryRun = ($mode === 'preview');

        $couponService = new CouponService();
        $result = $couponService->purgeDuplicateCoupons($dryRun);

        if ($result['success']) {
            wp_send_json_success([
                'dry_run' => $result['dry_run'],
                'stats' => $result['stats'],
                'duplicate_groups' => $result['duplicate_groups'],
                'posts_without_coupon_id' => $result['posts_without_coupon_id']
            ]);
        } else {
            wp_send_json_error('Failed to purge duplicates');
        }
    }

    /**
     * Handle test sync request (dry-run)
     */
    public function handleTestSync()
    {
        check_ajax_referer('coupon_automation_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $processor = new DataProcessor();
        $results = $processor->testSync();

        if ($results['success']) {
            wp_send_json_success($results);
        } else {
            wp_send_json_error($results);
        }
    }

    /**
     * Scheduled fetch handler - THIS IS THE 3AM CRON JOB
     */
    public function scheduledFetch()
    {
        error_log('[CA] ========================================');
        error_log('[CA] SCHEDULED CRON JOB TRIGGERED at ' . current_time('mysql'));
        error_log('[CA] Server time: ' . date('Y-m-d H:i:s'));
        error_log('[CA] WordPress time: ' . current_time('mysql'));
        error_log('[CA] Next scheduled: ' . (wp_next_scheduled('coupon_automation_daily_fetch') ? date('Y-m-d H:i:s', wp_next_scheduled('coupon_automation_daily_fetch')) : 'Not scheduled'));
        error_log('[CA] ========================================');
        
        // Check if stop was requested
        $stopRequested = get_option('coupon_automation_stop_requested', false);
        error_log('[CA] CRON: Stop requested check: ' . ($stopRequested ? 'YES' : 'NO'));
        
        if ($stopRequested) {
            error_log('[CA] CRON: Aborting - stop was requested');
            return;
        }

        error_log('[CA] CRON: Instantiating DataProcessor...');
        $processor = new DataProcessor();
        
        error_log('[CA] CRON: Starting processing...');
        $result = $processor->startProcessing();
        
        error_log('[CA] CRON: Processing result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        error_log('[CA] ========================================');
        error_log('[CA] SCHEDULED CRON JOB ENDED at ' . current_time('mysql'));
        error_log('[CA] ========================================');
    }
}
