<?php

/**
 * AJAX handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX class handles all AJAX requests for the plugin
 */
class Coupon_Automation_AJAX
{

    /**
     * Settings service
     * @var Coupon_Automation_Settings
     */
    private $settings;

    /**
     * Logger service
     * @var Coupon_Automation_Logger
     */
    private $logger;

    /**
     * Security service
     * @var Coupon_Automation_Security
     */
    private $security;

    /**
     * API Manager service
     * @var Coupon_Automation_API_Manager
     */
    private $api_manager;

    /**
     * Initialize AJAX handler
     */
    public function init()
    {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        $this->security = coupon_automation()->get_service('security');
        $this->api_manager = coupon_automation()->get_service('api');

        // Register AJAX actions for authenticated users
        add_action('wp_ajax_fetch_coupons', [$this, 'handle_fetch_coupons']);
        add_action('wp_ajax_stop_automation', [$this, 'handle_stop_automation']);
        add_action('wp_ajax_clear_coupon_flags', [$this, 'handle_clear_flags']);
        add_action('wp_ajax_purge_expired_coupons', [$this, 'handle_purge_expired']);
        add_action('wp_ajax_populate_brands_batch', [$this, 'handle_populate_brands_batch']);
        add_action('wp_ajax_test_api_connections', [$this, 'handle_test_api_connections']);
        add_action('wp_ajax_get_processing_status', [$this, 'handle_get_processing_status']);
        add_action('wp_ajax_clear_notifications', [$this, 'handle_clear_notifications']);
    }

    /**
     * Handle fetch coupons AJAX request
     * Preserves original functionality from existing AJAX handler
     */
    public function handle_fetch_coupons()
    {
        // Security checks
        $this->security->check_ajax_nonce('fetch_coupons');

        if (!$this->security->current_user_can('manage_coupons')) {
            wp_send_json_error(__('Insufficient permissions to manage coupons.', 'coupon-automation'));
        }

        // Rate limiting
        $last_trigger = get_transient('coupon_automation_last_manual_trigger');
        if ($last_trigger && (time() - $last_trigger) < 30) {
            $wait_time = 30 - (time() - $last_trigger);
            wp_send_json_error(sprintf(__('Please wait %d seconds between manual triggers.', 'coupon-automation'), $wait_time));
        }

        set_transient('coupon_automation_last_manual_trigger', time(), 60);

        $this->logger->info('Manual coupon fetch triggered by user', [
            'user_id' => get_current_user_id(),
            'current_hour' => current_time('H')
        ]);

        // Check if already running
        if ($this->api_manager->get_processing_status()['is_running']) {
            wp_send_json_error(__('Processing is already running. Please wait for it to complete.', 'coupon-automation'));
        }

        // SIMPLIFIED: Direct call to processing
        try {
            error_log("=== AJAX MANUAL TRIGGER ===");
            error_log("User ID: " . get_current_user_id());
            error_log("Current time: " . current_time('c'));

            // Direct call - no complex scheduling
            $result = $this->api_manager->fetch_and_process_all_data();

            if ($result === false) {
                wp_send_json_error(__('Could not start processing. Check if already completed today or system is in maintenance.', 'coupon-automation'));
            }

            $response_data = [
                'message' => __('Processing started successfully. Check the status dashboard for progress.', 'coupon-automation'),
                'status' => 'started',
                'timestamp' => current_time('mysql'),
                'current_hour' => current_time('H')
            ];

            wp_send_json_success($response_data);
        } catch (Exception $e) {
            error_log("AJAX TRIGGER FAILED: " . $e->getMessage());

            $this->logger->error('Failed to start manual processing', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('Failed to start processing: ', 'coupon-automation') . $e->getMessage());
        }
    }


    /**
     * Handle stop automation AJAX request
     */
    public function handle_stop_automation()
    {
        // Security checks
        $this->security->check_ajax_nonce('stop_automation');

        if (!$this->security->current_user_can('manage_coupons')) {
            wp_send_json_error(__('Insufficient permissions to stop automation.', 'coupon-automation'));
        }

        $this->logger->info('Manual automation stop triggered by user', [
            'user_id' => get_current_user_id()
        ]);

        try {
            $success = $this->api_manager->stop_processing();

            if ($success) {
                $message = __('Automation stopped successfully. Current processing will complete, but no new tasks will start.', 'coupon-automation');
                wp_send_json_success($message);
            } else {
                wp_send_json_error(__('Failed to stop automation. It may not be currently running.', 'coupon-automation'));
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to stop automation', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('An error occurred while stopping automation.', 'coupon-automation'));
        }
    }

    /**
     * Handle clear flags/transients AJAX request
     */
    public function handle_clear_flags()
    {
        // Security checks
        $this->security->check_ajax_nonce('clear_transients');

        if (!$this->security->current_user_can('clear_cache')) {
            wp_send_json_error(__('Insufficient permissions to clear cache.', 'coupon-automation'));
        }

        $this->logger->info('Manual cache clear triggered by user', [
            'user_id' => get_current_user_id()
        ]);

        try {
            // Clear API manager cache
            $this->api_manager->clear_cache();

            // Clear additional transients
            $additional_transients = [
                'coupon_automation_notifications',
                'fetch_process_running',
                'api_processed_count'
            ];

            foreach ($additional_transients as $transient) {
                delete_transient($transient);
            }

            // Reset processing status
            update_option('coupon_automation_stop_requested', false);

            $message = __('All caches and processing flags cleared successfully.', 'coupon-automation');
            wp_send_json_success($message);
        } catch (Exception $e) {
            $this->logger->error('Failed to clear cache', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('An error occurred while clearing cache.', 'coupon-automation'));
        }
    }

    /**
     * Handle purge expired coupons AJAX request
     */
    public function handle_purge_expired()
    {
        // Security checks
        $this->security->check_ajax_nonce('purge_expired');

        if (!$this->security->current_user_can('manage_coupons')) {
            wp_send_json_error(__('Insufficient permissions to purge coupons.', 'coupon-automation'));
        }

        $this->logger->info('Manual expired coupon purge triggered by user', [
            'user_id' => get_current_user_id()
        ]);

        try {
            $coupon_manager = coupon_automation()->get_service('coupon');
            if (!$coupon_manager) {
                wp_send_json_error(__('Coupon manager service not available.', 'coupon-automation'));
            }

            $purged_count = $coupon_manager->cleanup_expired_coupons();

            $message = sprintf(
                _n(
                    'Successfully purged %d expired coupon.',
                    'Successfully purged %d expired coupons.',
                    $purged_count,
                    'coupon-automation'
                ),
                $purged_count
            );

            wp_send_json_success($message);
        } catch (Exception $e) {
            $this->logger->error('Failed to purge expired coupons', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('An error occurred while purging expired coupons.', 'coupon-automation'));
        }
    }

    /**
     * Handle populate brands batch AJAX request
     * For the brand population feature
     */
    public function handle_populate_brands_batch()
    {
        // Security checks
        $this->security->check_ajax_nonce('populate_brands');

        if (!$this->security->current_user_can('manage_brands')) {
            wp_send_json_error(__('Insufficient permissions to populate brands.', 'coupon-automation'));
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $batch_size = $this->settings->get('general.batch_size', 10);

        $this->logger->debug('Brand population batch request', [
            'offset' => $offset,
            'batch_size' => $batch_size,
            'user_id' => get_current_user_id()
        ]);

        try {
            $brand_manager = coupon_automation()->get_service('brand');
            if (!$brand_manager) {
                wp_send_json_error(__('Brand manager service not available.', 'coupon-automation'));
            }

            // Get brands that need updates
            $brands_needing_updates = $brand_manager->get_brands_needing_updates();
            $total_brands = count($brands_needing_updates);

            if ($offset >= $total_brands) {
                wp_send_json_success([
                    'processed' => 0,
                    'total' => $total_brands,
                    'log' => ['All brands have been processed.'],
                    'completed' => true
                ]);
            }

            $batch = array_slice($brands_needing_updates, $offset, $batch_size);
            $processed = 0;
            $log_entries = [];

            foreach ($batch as $brand_info) {
                $brand = $brand_info['brand'];
                $issues = $brand_info['issues'];

                $log_entries[] = "Processing brand: {$brand->name} (Issues: " . implode(', ', $issues) . ")";

                // Process each issue
                if (in_array('description', $issues) || in_array('hashtags', $issues)) {
                    // Update brand data (this will trigger description generation if needed)
                    $brand_manager->update_brand_data($brand->term_id, [], [], 'manual');
                    $log_entries[] = "Updated description for: {$brand->name}";
                }

                $processed++;

                // Prevent timeout
                if ((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) > 25) {
                    break;
                }
            }

            $response_data = [
                'processed' => $processed,
                'total' => $total_brands,
                'log' => $log_entries,
                'completed' => ($offset + $processed) >= $total_brands
            ];

            wp_send_json_success($response_data);
        } catch (Exception $e) {
            $this->logger->error('Failed to populate brands batch', [
                'error' => $e->getMessage(),
                'offset' => $offset,
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('An error occurred while populating brands.', 'coupon-automation'));
        }
    }

    /**
     * Handle test API connections AJAX request
     */
    public function handle_test_api_connections()
    {
        // Security checks
        $this->security->check_ajax_nonce('admin_settings');

        if (!$this->security->current_user_can('manage_settings')) {
            wp_send_json_error(__('Insufficient permissions to test API connections.', 'coupon-automation'));
        }

        $this->logger->info('API connection test triggered by user', [
            'user_id' => get_current_user_id()
        ]);

        try {
            $test_results = $this->api_manager->test_api_connections();

            $response_data = [
                'message' => __('API connection tests completed.', 'coupon-automation'),
                'results' => $test_results,
                'timestamp' => current_time('mysql')
            ];

            wp_send_json_success($response_data);
        } catch (Exception $e) {
            $this->logger->error('Failed to test API connections', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('An error occurred while testing API connections.', 'coupon-automation'));
        }
    }

    /**
     * Handle get processing status AJAX request
     */
    public function handle_get_processing_status()
    {
        // Security checks - lighter check for status requests
        if (!$this->security->current_user_can('view_logs')) {
            wp_send_json_error(__('Insufficient permissions to view status.', 'coupon-automation'));
        }

        try {
            $status = $this->api_manager->get_processing_status();

            // Add additional status information
            $coupon_manager = coupon_automation()->get_service('coupon');
            $brand_manager = coupon_automation()->get_service('brand');

            $additional_status = [
                'last_sync' => get_option('coupon_automation_last_sync'),
                'notifications' => get_option('coupon_automation_notifications', []),
            ];

            if ($coupon_manager) {
                $additional_status['coupon_stats'] = $coupon_manager->get_coupon_statistics();
            }

            if ($brand_manager) {
                $additional_status['brand_stats'] = $brand_manager->get_brand_statistics();
            }

            $response_data = array_merge($status, $additional_status);

            wp_send_json_success($response_data);
        } catch (Exception $e) {
            $this->logger->error('Failed to get processing status', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('An error occurred while getting status.', 'coupon-automation'));
        }
    }

    /**
     * Handle clear notifications AJAX request
     */
    public function handle_clear_notifications()
    {
        // Security checks
        $this->security->check_ajax_nonce('clear_notifications');

        if (!$this->security->current_user_can('manage_settings')) {
            wp_send_json_error(__('Insufficient permissions to clear notifications.', 'coupon-automation'));
        }

        $this->logger->info('Notifications cleared by user', [
            'user_id' => get_current_user_id()
        ]);

        try {
            update_option('coupon_automation_notifications', []);
            wp_send_json_success(__('Notifications cleared successfully.', 'coupon-automation'));
        } catch (Exception $e) {
            $this->logger->error('Failed to clear notifications', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_error(__('An error occurred while clearing notifications.', 'coupon-automation'));
        }
    }

    /**
     * Validate AJAX request basics
     * 
     * @param string $action Action name for logging
     * @return bool True if valid, false otherwise
     */
    private function validate_ajax_request($action)
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            $this->security->log_security_event('Unauthorized AJAX request', [
                'action' => $action,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            return false;
        }

        // Check if this is actually an AJAX request
        if (!wp_doing_ajax()) {
            $this->security->log_security_event('Non-AJAX request to AJAX handler', [
                'action' => $action,
                'user_id' => get_current_user_id()
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get sanitized POST data
     * 
     * @param string $key Data key
     * @param string $type Sanitization type
     * @param mixed $default Default value
     * @return mixed Sanitized value
     */
    private function get_post_data($key, $type = 'text', $default = '')
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return $this->security->sanitize_input($_POST[$key], $type);
    }

    /**
     * Send standardized error response
     * 
     * @param string $message Error message
     * @param string $error_code Optional error code
     * @param array $data Optional additional data
     */
    private function send_error_response($message, $error_code = '', $data = [])
    {
        $response = [
            'message' => $message,
            'timestamp' => current_time('mysql')
        ];

        if (!empty($error_code)) {
            $response['error_code'] = $error_code;
        }

        if (!empty($data)) {
            $response['data'] = $data;
        }

        wp_send_json_error($response);
    }

    /**
     * Send standardized success response
     * 
     * @param mixed $data Success data
     * @param string $message Optional success message
     */
    private function send_success_response($data, $message = '')
    {
        $response = [
            'timestamp' => current_time('mysql')
        ];

        if (!empty($message)) {
            $response['message'] = $message;
        }

        if (is_array($data)) {
            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }

        wp_send_json_success($response);
    }
}
