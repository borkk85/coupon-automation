<?php

/**
 * Cron management for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron class handles all scheduled tasks and background processing
 */
class Coupon_Automation_Cron
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
     * API Manager service
     * @var Coupon_Automation_API_Manager
     */
    private $api_manager;


    /**
     * Initialize cron management
     */
    public function init()
    {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        $this->api_manager = coupon_automation()->get_service('api');

        // SIMPLIFIED: Only register the main daily sync hook
        add_action('coupon_automation_daily_sync', [$this, 'handle_daily_sync']);
        add_action('coupon_automation_cleanup', [$this, 'handle_cleanup']);
        add_action('coupon_automation_health_check', [$this, 'handle_health_check']);


        // Schedule events on WordPress init
        add_action('wp', [$this, 'ensure_daily_schedule']);
    }

    public function ensure_daily_schedule()
    {
        // Only schedule if automation is enabled
        if (!$this->settings || !$this->settings->get('automation.auto_schedule_enabled', true)) {
            return;
        }

        // Schedule daily sync if not already scheduled
        if (!wp_next_scheduled('coupon_automation_daily_sync')) {
            // Schedule for 2 AM server time daily
            // $start_time = strtotime('tomorrow 2:00 AM');
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(get_option('timezone_string') ?: 'UTC');
            $start_time = (new \DateTimeImmutable('tomorrow 2:00', $timezone))->getTimestamp();
            wp_schedule_event($start_time, 'daily', 'coupon_automation_daily_sync');

            if ($this->logger) {
                $this->logger->info('Scheduled daily sync', [
                    'next_run' => date('Y-m-d H:i:s', $start_time)
                ]);
            }
            error_log("CRON: Scheduled daily sync for " . date('Y-m-d H:i:s', $start_time));
        }
    }

    /**
     * Schedule all plugin events
     */
    public function schedule_events()
    {
        // Only schedule if automation is enabled (check if settings service is available)
        if ($this->settings && !$this->settings->get('automation.auto_schedule_enabled', true)) {
            if ($this->logger) {
                $this->logger->debug('Auto scheduling disabled, skipping event scheduling');
            }
            return;
        }

        $this->schedule_daily_sync();
        $this->schedule_cleanup();
        $this->schedule_health_check();
    }

    /**
     * Schedule daily sync event
     */
    private function schedule_daily_sync()
    {
        $interval = $this->settings ? $this->settings->get('automation.schedule_interval', 'daily') : 'daily';
        $hook = 'coupon_automation_daily_sync';

        if (!wp_next_scheduled($hook)) {
            // Schedule for next occurrence based on interval
            $start_time = $this->get_optimal_start_time($interval);

            $scheduled = wp_schedule_event($start_time, $interval, $hook);

            if ($scheduled === false) {
                if ($this->logger) {
                    $this->logger->error('Failed to schedule daily sync event', [
                        'interval' => $interval,
                        'start_time' => date('Y-m-d H:i:s', $start_time)
                    ]);
                }
            } else {
                if ($this->logger) {
                    $this->logger->info('Scheduled daily sync event', [
                        'interval' => $interval,
                        'next_run' => date('Y-m-d H:i:s', $start_time)
                    ]);
                }
            }
        }
    }

    /**
     * Schedule cleanup event
     */
    private function schedule_cleanup()
    {
        $hook = 'coupon_automation_cleanup';

        if (!wp_next_scheduled($hook)) {
            // Schedule weekly cleanup at 3 AM
            // $start_time = strtotime('next sunday 3:00 AM');
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(get_option('timezone_string') ?: 'UTC');
            $start_time = (new \DateTimeImmutable('next sunday 3:00', $timezone))->getTimestamp();

            $scheduled = wp_schedule_event($start_time, 'weekly', $hook);

            if ($scheduled === false) {
                if ($this->logger) {
                    $this->logger->error('Failed to schedule cleanup event');
                }
            } else {
                if ($this->logger) {
                    $this->logger->info('Scheduled cleanup event', [
                        'next_run' => date('Y-m-d H:i:s', $start_time)
                    ]);
                }
            }
        }
    }

    /**
     * Schedule health check event
     */
    private function schedule_health_check()
    {
        $hook = 'coupon_automation_health_check';

        if (!wp_next_scheduled($hook)) {
            // Schedule hourly health checks
            // $start_time = time() + HOUR_IN_SECONDS;
            $start_time = current_time('timestamp') + HOUR_IN_SECONDS;

            $scheduled = wp_schedule_event($start_time, 'hourly', $hook);

            if ($scheduled === false) {
                if ($this->logger) {
                    $this->logger->error('Failed to schedule health check event');
                }
            } else {
                if ($this->logger) {
                    $this->logger->info('Scheduled health check event', [
                        'next_run' => date('Y-m-d H:i:s', $start_time)
                    ]);
                }
            }
        }
    }

    /**
     * Maybe reschedule events if settings changed
     */
    public function maybe_reschedule_events()
    {
        $last_schedule_check = get_option('coupon_automation_last_schedule_check', 0);
        $settings_version = get_option('coupon_automation_settings_version', 0);

        // Check if settings were updated since last schedule check
        if ($settings_version > $last_schedule_check) {
            $this->reschedule_events();
            update_option('coupon_automation_last_schedule_check', time());
        }
    }

    /**
     * Reschedule events based on current settings
     */
    public function reschedule_events()
    {
        $this->logger->info('Rescheduling events due to settings change');

        // Clear existing schedules
        wp_clear_scheduled_hook('coupon_automation_daily_sync');

        // Reschedule with new settings
        $this->schedule_daily_sync();

        $this->logger->info('Events rescheduled successfully');
    }

    /**
     * Handle daily sync cron event
     * This is the main automated processing entry point
     */
    public function handle_daily_sync()
    {
        error_log("=== DAILY SYNC TRIGGERED ===");
        error_log("Server time: " . date('c'));
        error_log("WordPress time: " . current_time('c'));
        error_log("Current hour (WP): " . current_time('H'));

        if (!$this->logger) {
            error_log("ERROR: Logger service not available");
            return;
        }

        if (!$this->api_manager) {
            // Try to get API manager if not already set
            $this->api_manager = coupon_automation()->get_service('api');
            if (!$this->api_manager) {
                error_log("ERROR: API Manager service not available");
                return;
            }
        }

        $this->logger->info('Daily sync cron event triggered');

        // Check if automation is still enabled
        if ($this->settings && !$this->settings->get('automation.auto_schedule_enabled', true)) {
            $this->logger->info('Auto scheduling disabled, skipping daily sync');
            return;
        }

        // SIMPLIFIED: Let the API manager handle time window logic internally
        try {
            error_log("CALLING API MANAGER fetch_and_process_all_data");
            $result = $this->api_manager->fetch_and_process_all_data();

            if ($result === false) {
                error_log("API MANAGER returned false");
                $this->logger->warning('Daily sync returned false - may be outside window or already completed');
            } else {
                error_log("API MANAGER returned success");
                $this->logger->info('Daily sync completed successfully');
            }

            // Update success counter
            $success_count = get_option('coupon_automation_cron_success_count', 0);
            update_option('coupon_automation_cron_success_count', $success_count + 1);
        } catch (Exception $e) {
            error_log("DAILY SYNC FAILED: " . $e->getMessage());
            $this->logger->error('Daily sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update failure counter
            $failure_count = get_option('coupon_automation_cron_failure_count', 0);
            update_option('coupon_automation_cron_failure_count', $failure_count + 1);
        }

        // Record cron execution
        update_option('coupon_automation_last_cron_run', current_time('mysql'));
        error_log("=== DAILY SYNC COMPLETED ===");
    }

    /**
     * Handle cleanup cron event
     */
    public function handle_cleanup()
    {
        $this->logger->info('Cleanup cron event triggered');

        try {
            $cleanup_results = [];

            // Clean expired coupons
            $coupon_manager = coupon_automation()->get_service('coupon');
            if ($coupon_manager) {
                $purged_count = $coupon_manager->cleanup_expired_coupons();
                $cleanup_results['expired_coupons'] = $purged_count;
                $this->logger->info('Cleaned up expired coupons', ['count' => $purged_count]);
            }

            // Clean old logs
            $log_retention_days = $this->settings->get('general.log_retention_days', 30);
            $database = coupon_automation()->get_service('database');
            if ($database) {
                $log_cleanup_success = $database->clean_old_logs($log_retention_days);
                $cleanup_results['log_cleanup'] = $log_cleanup_success;
                $this->logger->info('Cleaned up old database logs', [
                    'retention_days' => $log_retention_days,
                    'success' => $log_cleanup_success
                ]);
            }

            // Clean file logs
            $logger = $this->logger;
            if ($logger && method_exists($logger, 'cleanup_old_logs')) {
                $logger->cleanup_old_logs();
                $cleanup_results['file_logs'] = true;
                $this->logger->info('Cleaned up old file logs');
            }

            // Clean WordPress transients
            $this->cleanup_plugin_transients();
            $cleanup_results['transients'] = true;

            // Clean orphaned metadata
            $this->cleanup_orphaned_metadata();
            $cleanup_results['orphaned_metadata'] = true;

            $this->logger->info('Cleanup completed successfully', $cleanup_results);

            // Update last cleanup time
            update_option('coupon_automation_last_cleanup', current_time('mysql'));
        } catch (Exception $e) {
            $this->logger->error('Cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Handle health check cron event
     */
    public function handle_health_check()
    {
        $this->logger->debug('Health check cron event triggered');

        try {
            $health_issues = [];

            // Check if WordPress cron is working
            if (!$this->is_wp_cron_working()) {
                $health_issues[] = 'WordPress cron appears to be disabled or not working';
            }

            // Check database connectivity
            global $wpdb;
            $db_test = $wpdb->get_var("SELECT 1");
            if ($db_test !== '1') {
                $health_issues[] = 'Database connectivity issue detected';
            }

            // Check memory usage
            $memory_usage = memory_get_usage(true);
            $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
            $memory_percentage = ($memory_usage / $memory_limit) * 100;

            if ($memory_percentage > 80) {
                $health_issues[] = sprintf('High memory usage detected: %.1f%%', $memory_percentage);
            }

            // Check processing stuck situations
            $processing_status = $this->api_manager->get_processing_status();
            if ($processing_status['is_running']) {
                $last_sync = $processing_status['last_sync'];
                if ($last_sync && strtotime($last_sync) < (time() - 2 * HOUR_IN_SECONDS)) {
                    $health_issues[] = 'Processing may be stuck - running for over 2 hours';

                    // Auto-recover from stuck processing
                    $this->logger->warning('Auto-recovering from stuck processing');
                    $this->api_manager->stop_processing();
                    $this->api_manager->clear_cache();
                }
            }

            // Check API key configuration
            $api_issues = $this->check_api_configuration();
            $health_issues = array_merge($health_issues, $api_issues);

            // Log health status
            if (empty($health_issues)) {
                $this->logger->debug('Health check passed - all systems normal');
            } else {
                $this->logger->warning('Health check found issues', $health_issues);

                // Store health issues for admin display
                update_option('coupon_automation_health_issues', $health_issues);
            }

            // Update last health check time
            update_option('coupon_automation_last_health_check', current_time('mysql'));
        } catch (Exception $e) {
            $this->logger->error('Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Handle continue processing event
     * Used for chunked processing
     */
    public function handle_continue_processing()
    {
        $this->logger->debug('Continue processing event triggered');

        if ($this->api_manager) {
            $this->api_manager->continue_processing();
        }
    }

    /**
     * Handle retry coupon title generation
     * 
     * @param string $description Coupon description
     */
    public function handle_retry_coupon_title($description)
    {
        $this->logger->info('Retrying coupon title generation', [
            'description' => substr($description, 0, 100) . '...'
        ]);

        $openai_api = $this->api_manager->get_api_handler('openai');
        if ($openai_api) {
            $title = $openai_api->generate_coupon_title($description);
            if ($title) {
                $this->logger->info('Retry successful - coupon title generated');
            } else {
                $this->logger->warning('Retry failed - coupon title generation still failing');
            }
        }
    }

    /**
     * Handle retry description translation
     * 
     * @param string $description Original description
     */
    public function handle_retry_translate_description($description)
    {
        $this->logger->info('Retrying description translation', [
            'description' => substr($description, 0, 100) . '...'
        ]);

        $openai_api = $this->api_manager->get_api_handler('openai');
        if ($openai_api) {
            $translated = $openai_api->translate_description($description);
            if ($translated) {
                $this->logger->info('Retry successful - description translated');
            } else {
                $this->logger->warning('Retry failed - description translation still failing');
            }
        }
    }

    /**
     * Handle welcome notification
     */
    public function handle_welcome_notification()
    {
        $notifications = get_option('coupon_automation_notifications', []);
        $notifications[] = [
            'type' => 'system_alert',
            'level' => 'info',
            'message' => 'Coupon Automation plugin activated successfully. Welcome!',
            'time' => current_time('mysql')
        ];
        update_option('coupon_automation_notifications', $notifications);

        $this->logger->info('Welcome notification sent');
    }

    /**
     * Get optimal start time for scheduled events
     * 
     * @param string $interval Cron interval
     * @return int Timestamp for optimal start time
     */
    private function get_optimal_start_time($interval)
    {
        // $current_time = time();
        $current_time = current_time('timestamp');
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(get_option('timezone_string') ?: 'UTC');

        switch ($interval) {
            case 'hourly':
                // Start at the next hour
                // return strtotime('+1 hour', $current_time);
                return $current_time + HOUR_IN_SECONDS;

            case 'twicedaily':
                // Start at next 6 AM or 6 PM
                // $next_6am = strtotime('tomorrow 6:00 AM');
                // $next_6pm = strtotime('today 6:00 PM');
                $next_6am = (new \DateTimeImmutable('tomorrow 6:00', $timezone))->getTimestamp();
                $next_6pm = (new \DateTimeImmutable('today 18:00', $timezone))->getTimestamp();
                if ($current_time < $next_6pm) {
                    return $next_6pm;
                }
                return $next_6am;

            case 'daily':
                // Start at 2 AM tomorrow (low traffic time)
                // return strtotime('tomorrow 2:00 AM');
                return (new \DateTimeImmutable('tomorrow 2:00', $timezone))->getTimestamp();

            case 'weekly':
                // Start next Sunday at 2 AM
                // return strtotime('next sunday 2:00 AM');
                return (new \DateTimeImmutable('next sunday 2:00', $timezone))->getTimestamp();
            default:
                // Default to 1 hour from now
                return $current_time + HOUR_IN_SECONDS;
        }
    }

    /**
     * Check if WordPress cron is working
     * 
     * @return bool True if working, false otherwise
     */
    private function is_wp_cron_working()
    {
        // Check if DISABLE_WP_CRON is set and true
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true) {
            return false;
        }

        // Check if we have recent cron executions
        $last_cron = get_option('coupon_automation_last_cron_run');
        if ($last_cron) {
            $last_cron_time = strtotime($last_cron);
            $expected_interval = $this->get_interval_seconds(
                $this->settings ? $this->settings->get('automation.schedule_interval', 'daily') : 'daily'
            );

            // If last cron was more than 2x the expected interval ago, cron might be broken
            if ((time() - $last_cron_time) > ($expected_interval * 2)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get interval in seconds
     * 
     * @param string $interval Interval name
     * @return int Seconds
     */
    private function get_interval_seconds($interval)
    {
        switch ($interval) {
            case 'hourly':
                return HOUR_IN_SECONDS;
            case 'twicedaily':
                return 12 * HOUR_IN_SECONDS;
            case 'daily':
                return DAY_IN_SECONDS;
            case 'weekly':
                return WEEK_IN_SECONDS;
            default:
                return DAY_IN_SECONDS;
        }
    }

    /**
     * Check API configuration health
     * 
     * @return array List of API configuration issues
     */
    private function check_api_configuration()
    {
        $issues = [];

        // Check critical API keys using the same method as the API handlers
        $critical_apis = [
            'addrevenue_api_token' => 'AddRevenue API Token',
            'awin_api_token' => 'AWIN API Token',
            'openai_api_key' => 'OpenAI API Key'
        ];

        foreach ($critical_apis as $api_key => $display_name) {
            // More robust checking - ensure settings service exists
            if (!$this->settings) {
                $issues[] = "Settings service not available for: {$display_name}";
                continue;
            }

            $key_value = $this->settings->get_api_key($api_key);

            // Debug logging to see what we actually get
            if ($this->logger) {
                $this->logger->debug("Health check API key: {$api_key}", [
                    'has_value' => !empty($key_value),
                    'length' => $key_value ? strlen($key_value) : 0,
                    'type' => gettype($key_value)
                ]);
            }

            // More specific empty check
            if ($key_value === false || $key_value === '' || $key_value === null) {
                $issues[] = "Missing API key: {$display_name}";
            }
        }

        return $issues;
    }

    /**
     * Cleanup plugin-specific transients
     */
    private function cleanup_plugin_transients()
    {
        $plugin_transients = [
            'fetch_process_running',
            'addrevenue_advertisers_data',
            'addrevenue_campaigns_data',
            'awin_promotions_data',
            'api_processed_count',
            'openai_requests_today',
            'openai_tokens_today',
            'yourls_requests_today',
            'yourls_urls_today'
        ];

        foreach ($plugin_transients as $transient) {
            delete_transient($transient);
        }

        // Clean up dated transients (e.g., daily counters from previous days)
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name LIKE %s",
            '_transient_openai_requests_%',
            '%' . date('Y-m-d', strtotime('-7 days')) . '%'
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name LIKE %s",
            '_transient_yourls_%',
            '%' . date('Y-m-d', strtotime('-7 days')) . '%'
        ));
    }

    /**
     * Cleanup orphaned metadata
     */
    private function cleanup_orphaned_metadata()
    {
        global $wpdb;

        // Clean up postmeta for deleted posts
        $orphaned_postmeta = $wpdb->query("
            DELETE pm FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.ID IS NULL
        ");

        // Clean up termmeta for deleted terms
        $orphaned_termmeta = $wpdb->query("
            DELETE tm FROM {$wpdb->termmeta} tm
            LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
            WHERE t.term_id IS NULL
        ");

        if ($orphaned_postmeta > 0 || $orphaned_termmeta > 0) {
            $this->logger->info('Cleaned up orphaned metadata', [
                'orphaned_postmeta' => $orphaned_postmeta,
                'orphaned_termmeta' => $orphaned_termmeta
            ]);
        }
    }

    /**
     * Send failure notification to admins
     * 
     * @param string $message Failure message
     */
    private function send_failure_notification($message)
    {
        // Add to plugin notifications
        $notifications = get_option('coupon_automation_notifications', []);
        $notifications[] = [
            'type' => 'system_alert',
            'level' => 'error',
            'message' => $message,
            'time' => current_time('mysql')
        ];
        update_option('coupon_automation_notifications', array_slice($notifications, -50));

        // Send email notification if enabled (optional future feature)
        if ($this->settings->get('automation.email_notifications', false)) {
            $this->send_email_notification($message);
        }
    }

    /**
     * Send email notification (placeholder for future feature)
     * 
     * @param string $message Message to send
     */
    private function send_email_notification($message)
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = sprintf('[%s] Coupon Automation Alert', $site_name);
        $body = sprintf(
            "Coupon Automation Alert\n\n%s\n\nTime: %s\nSite: %s",
            $message,
            current_time('mysql'),
            home_url()
        );

        wp_mail($admin_email, $subject, $body);
    }

    /**
     * Display cron-related admin notices
     */
    public function display_cron_notices()
    {
        // Only show on plugin pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['settings_page_coupon-automation', 'settings_page_populate-brand-content'])) {
            return;
        }

        // Check for cron issues
        if (!$this->is_wp_cron_working()) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>' . esc_html__('Coupon Automation Warning:', 'coupon-automation') . '</strong> ';
            echo esc_html__('WordPress cron appears to be disabled or not working properly. Scheduled tasks may not run automatically.', 'coupon-automation');
            echo '</p></div>';
        }

        // Check for recent failures
        $failure_count = get_option('coupon_automation_cron_failure_count', 0);
        $success_count = get_option('coupon_automation_cron_success_count', 0);

        if ($failure_count > 0 && $failure_count > $success_count) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . esc_html__('Coupon Automation Error:', 'coupon-automation') . '</strong> ';
            echo sprintf(
                esc_html__('Multiple cron failures detected (%d failures vs %d successes). Please check the logs.', 'coupon-automation'),
                $failure_count,
                $success_count
            );
            echo '</p></div>';
        }
    }

    /**
     * Clear all scheduled events
     */
    public function clear_scheduled_events()
    {
        $events = [
            'coupon_automation_daily_sync',
            'coupon_automation_cleanup',
            'coupon_automation_health_check',
            'fetch_and_store_data_event',
            'retry_generate_coupon_title',
            'retry_translate_description',
            'coupon_automation_welcome_notification'
        ];

        foreach ($events as $event) {
            wp_clear_scheduled_hook($event);
        }

        $this->logger->info('All scheduled events cleared');
    }

    /**
     * Get cron status information
     * 
     * @return array Cron status data
     */
    public function get_cron_status()
    {
        $status = [
            'wp_cron_enabled' => !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true,
            'wp_cron_working' => $this->is_wp_cron_working(),
            'last_cron_run' => get_option('coupon_automation_last_cron_run'),
            'last_cleanup' => get_option('coupon_automation_last_cleanup'),
            'last_health_check' => get_option('coupon_automation_last_health_check'),
            'success_count' => get_option('coupon_automation_cron_success_count', 0),
            'failure_count' => get_option('coupon_automation_cron_failure_count', 0),
            'scheduled_events' => []
        ];

        // Get scheduled events
        $cron_events = wp_get_scheduled_event('coupon_automation_daily_sync');
        if ($cron_events) {
            $status['scheduled_events']['daily_sync'] = [
                'next_run' => $cron_events->timestamp,
                'schedule' => $cron_events->schedule
            ];
        }

        $cleanup_event = wp_get_scheduled_event('coupon_automation_cleanup');
        if ($cleanup_event) {
            $status['scheduled_events']['cleanup'] = [
                'next_run' => $cleanup_event->timestamp,
                'schedule' => $cleanup_event->schedule
            ];
        }

        return $status;
    }

    /**
     * Manual trigger for testing cron functionality
     * 
     * @param string $event_name Event to trigger
     * @return bool Success status
     */
    public function manual_trigger_event($event_name)
    {
        $this->logger->info("Manual trigger requested for event: {$event_name}");

        switch ($event_name) {
            case 'daily_sync':
                $this->handle_daily_sync();
                return true;

            case 'cleanup':
                $this->handle_cleanup();
                return true;

            case 'health_check':
                $this->handle_health_check();
                return true;

            default:
                $this->logger->warning("Unknown event requested for manual trigger: {$event_name}");
                return false;
        }
    }
}
