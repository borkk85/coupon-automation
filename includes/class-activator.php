<?php
/**
 * Plugin activation handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activator class handles plugin activation
 */
class Coupon_Automation_Activator {
    
    /**
     * Plugin activation
     */
    public function activate() {
        try {
            // Check WordPress version compatibility
            $this->check_wordpress_version();
            
            // Check PHP extensions
            $this->check_required_extensions();
            
            // Create database tables
            $this->create_database_tables();
            
            // Set default options
            $this->set_default_options();
            
            // Create required directories
            $this->create_directories();
            
            // Set up capabilities
            $this->setup_capabilities();
            
            // Validate encryption
            $this->validate_encryption();
            
            // Schedule initial events
            $this->schedule_initial_events();
            
            // Set activation flag
            update_option('coupon_automation_activated', true);
            update_option('coupon_automation_activation_time', current_time('mysql'));
            update_option('coupon_automation_version', COUPON_AUTOMATION_VERSION);
            
        } catch (Exception $e) {
            // Log the error and prevent activation
            error_log('Coupon Automation activation failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check WordPress version compatibility
     * 
     * @throws Exception If WordPress version is incompatible
     */
    private function check_wordpress_version() {
        global $wp_version;
        
        $required_wp_version = '5.0';
        
        if (version_compare($wp_version, $required_wp_version, '<')) {
            throw new Exception(
                sprintf(
                    __('Coupon Automation requires WordPress %s or higher. You are running WordPress %s.', 'coupon-automation'),
                    $required_wp_version,
                    $wp_version
                )
            );
        }
    }
    
    /**
     * Check required PHP extensions
     * 
     * @throws Exception If required extensions are missing
     */
    private function check_required_extensions() {
        $required_extensions = [
            'openssl' => __('OpenSSL extension is required for encryption.', 'coupon-automation'),
            'curl' => __('cURL extension is required for API communication.', 'coupon-automation'),
            'json' => __('JSON extension is required for data processing.', 'coupon-automation'),
            'mbstring' => __('Mbstring extension is required for string handling.', 'coupon-automation'),
        ];
        
        $missing_extensions = [];
        
        foreach ($required_extensions as $extension => $message) {
            if (!extension_loaded($extension)) {
                $missing_extensions[] = $message;
            }
        }
        
        if (!empty($missing_extensions)) {
            throw new Exception(
                __('Missing required PHP extensions:', 'coupon-automation') . "\n" . 
                implode("\n", $missing_extensions)
            );
        }
    }
    
    /**
     * Create database tables
     */
    private function create_database_tables() {
        $database = new Coupon_Automation_Database();
        $database->init();
        
        // Force table creation
        $database->maybe_create_tables();
    }
    
    /**
     * Set default options
     */
    private function set_default_options() {
        $default_options = [
            'coupon_automation_settings' => [
                'api_keys' => [
                    'addrevenue_api_token' => '',
                    'awin_api_token' => '',
                    'awin_publisher_id' => '',
                    'openai_api_key' => '',
                    'yourl_api_token' => '',
                ],
                'prompts' => [
                    'coupon_title_prompt' => 'Generate a compelling and SEO-friendly coupon title (max 60 characters) based on this description:',
                    'description_prompt' => 'Create exactly 3 concise, clear bullet points for coupon terms and conditions based on this description. Each point should be unique and under 100 characters. Format: point1 - point2 - point3',
                    'brand_description_prompt' => '<h4 style="text-align: left">About [BRAND_NAME]</h4><p style="text-align: left">Write a compelling, SEO-optimized brand description (150-200 words) that highlights the brand\'s unique value proposition, products/services, and what makes them special. Include relevant keywords naturally. End with a call-to-action encouraging visitors to explore their offers.</p>',
                    'why_we_love_prompt' => 'Generate exactly 3 short phrases (maximum 3 words each) that explain why customers love this brand. Focus on key benefits like quality, service, value, etc. Return as a simple list format: phrase1, phrase2, phrase3',
                ],
                'general' => [
                    'batch_size' => 10,
                    'api_timeout' => 30,
                    'log_retention_days' => 30,
                    'enable_debug_logging' => false,
                    'fallback_terms' => "See full terms on website\nTerms and conditions apply\nOffer may expire without notice",
                ],
                'automation' => [
                    'auto_schedule_enabled' => true,
                    'schedule_interval' => 'daily',
                    'max_processing_time' => 300,
                    'enable_notifications' => true,
                ]
            ],
            'coupon_automation_notifications' => [],
            'coupon_automation_last_sync' => null,
            'coupon_automation_processing_status' => 'idle',
        ];
        
        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value, '', 'no');
            }
        }
    }
    
    /**
     * Create required directories
     */
    private function create_directories() {
        $upload_dir = wp_upload_dir();
        $plugin_upload_dir = $upload_dir['basedir'] . '/coupon-automation';
        
        $directories = [
            $plugin_upload_dir,
            $plugin_upload_dir . '/logs',
            $plugin_upload_dir . '/cache',
            $plugin_upload_dir . '/temp',
        ];
        
        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                wp_mkdir_p($directory);
                
                // Create .htaccess to protect directory
                $htaccess_content = "Order deny,allow\nDeny from all\n";
                file_put_contents($directory . '/.htaccess', $htaccess_content);
                
                // Create index.php to prevent directory listing
                file_put_contents($directory . '/index.php', '<?php // Silence is golden');
            }
        }
    }
    
    /**
     * Set up user capabilities
     */
    private function setup_capabilities() {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $capabilities = [
                'manage_coupon_automation',
                'edit_coupon_automation_settings',
                'view_coupon_automation_logs',
                'manage_coupon_automation_api',
            ];
            
            foreach ($capabilities as $capability) {
                $admin_role->add_cap($capability);
            }
        }
        
        // Allow editors to manage coupons and brands
        $editor_role = get_role('editor');
        if ($editor_role) {
            $editor_role->add_cap('manage_coupon_automation');
        }
    }
    
    /**
     * Validate encryption functionality
     * 
     * @throws Exception If encryption validation fails
     */
    private function validate_encryption() {
        $encryption = new Coupon_Automation_Encryption();
        $encryption->init();
        
        if (!$encryption->validate_encryption()) {
            throw new Exception(
                __('Encryption validation failed. Please check your server\'s OpenSSL configuration.', 'coupon-automation')
            );
        }
        
        // Migrate existing API keys to encrypted storage
        $encryption->migrate_api_keys();
    }
    
    /**
     * Schedule initial cron events
     */
    private function schedule_initial_events() {
        // Clear any existing events first
        wp_clear_scheduled_hook('coupon_automation_daily_sync');
        wp_clear_scheduled_hook('coupon_automation_cleanup');
        wp_clear_scheduled_hook('coupon_automation_health_check');
        
        // Schedule daily sync
        if (!wp_next_scheduled('coupon_automation_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'coupon_automation_daily_sync');
        }
        
        // Schedule weekly cleanup
        if (!wp_next_scheduled('coupon_automation_cleanup')) {
            wp_schedule_event(time(), 'weekly', 'coupon_automation_cleanup');
        }
        
        // Schedule hourly health check
        if (!wp_next_scheduled('coupon_automation_health_check')) {
            wp_schedule_event(time(), 'hourly', 'coupon_automation_health_check');
        }
    }
    

    
    /**
     * Set up rewrite rules
     */
    private function setup_rewrite_rules() {
        // Flush rewrite rules to ensure our custom post types work properly
        flush_rewrite_rules();
        
    }
    
    /**
     * Validate required WordPress features
     * 
     * @throws Exception If required features are missing
     */
    private function validate_wordpress_features() {
        // Check if required post types exist
        if (!post_type_exists('coupons')) {
            throw new Exception(
                __('Required "coupons" post type not found. Please ensure the coupons post type is properly registered.', 'coupon-automation')
            );
        }
        
        // Check if required taxonomies exist
        if (!taxonomy_exists('brands')) {
            throw new Exception(
                __('Required "brands" taxonomy not found. Please ensure the brands taxonomy is properly registered.', 'coupon-automation')
            );
        }
        
        // Check if ACF is available (if used)
        if (!function_exists('get_field')) {
            error_log('Coupon Automation: Advanced Custom Fields not detected. Some features may not work properly.');
        }
    }
    
    /**
     * Create activation log entry
     */
    private function log_activation() {
        $activation_data = [
            'timestamp' => current_time('mysql'),
            'version' => COUPON_AUTOMATION_VERSION,
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'user_id' => get_current_user_id(),
            'site_url' => home_url(),
        ];
        
        update_option('coupon_automation_last_activation', $activation_data, false);
        
        // Log to file if logging is enabled
        $log_dir = wp_upload_dir()['basedir'] . '/coupon-automation/logs';
        if (is_writable($log_dir)) {
            $log_entry = sprintf(
                "[%s] Plugin activated - Version: %s, PHP: %s, WP: %s, User: %d\n",
                current_time('c'),
                COUPON_AUTOMATION_VERSION,
                PHP_VERSION,
                get_bloginfo('version'),
                get_current_user_id()
            );
            
            file_put_contents(
                $log_dir . '/activation.log',
                $log_entry,
                FILE_APPEND | LOCK_EX
            );
        }
    }
    
    /**
     * Perform health checks
     * 
     * @throws Exception If critical issues are found
     */
    private function perform_health_checks() {
        $issues = [];
        
        // Check write permissions
        $upload_dir = wp_upload_dir();
        if (!is_writable($upload_dir['basedir'])) {
            $issues[] = __('Upload directory is not writable. File operations may fail.', 'coupon-automation');
        }
        
        // Check database connectivity
        global $wpdb;
        $test_query = $wpdb->get_var("SELECT 1");
        if ($test_query !== '1') {
            $issues[] = __('Database connectivity test failed.', 'coupon-automation');
        }
        
        // Check memory limit
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $recommended_memory = 128 * 1024 * 1024; // 128MB
        
        if ($memory_limit < $recommended_memory) {
            $issues[] = sprintf(
                __('Low memory limit detected (%s). Recommended: 128MB or higher.', 'coupon-automation'),
                size_format($memory_limit)
            );
        }
        
        // Check execution time
        $max_execution_time = ini_get('max_execution_time');
        if ($max_execution_time > 0 && $max_execution_time < 60) {
            $issues[] = sprintf(
                __('Low execution time limit (%d seconds). API operations may timeout.', 'coupon-automation'),
                $max_execution_time
            );
        }
        
        // Log issues but don't prevent activation for non-critical problems
        if (!empty($issues)) {
            update_option('coupon_automation_health_issues', $issues, false);
            
            foreach ($issues as $issue) {
                error_log('Coupon Automation Health Check Warning: ' . $issue);
            }
        }
    }
    
    /**
     * Complete activation process
     */
    public function complete_activation() {
        try {
            // Perform additional validation
            $this->validate_wordpress_features();
            
            // Set up rewrite rules
            $this->setup_rewrite_rules();
            
            // Perform health checks
            $this->perform_health_checks();
            
            // Log activation
            $this->log_activation();
            
            // Set completion flag
            update_option('coupon_automation_activation_complete', true);
            
            // Schedule welcome notification
            wp_schedule_single_event(time() + 5, 'coupon_automation_welcome_notification');
            
        } catch (Exception $e) {
            // Log error but don't fail activation for non-critical issues
            error_log('Coupon Automation activation completion warning: ' . $e->getMessage());
            
            // Store the error for admin notice
            update_option('coupon_automation_activation_warning', $e->getMessage(), false);
        }
    }
    
    /**
     * Get activation status
     * 
     * @return array Activation status information
     */
    public static function get_activation_status() {
        return [
            'activated' => get_option('coupon_automation_activated', false),
            'activation_time' => get_option('coupon_automation_activation_time'),
            'version' => get_option('coupon_automation_version'),
            'complete' => get_option('coupon_automation_activation_complete', false),
            'health_issues' => get_option('coupon_automation_health_issues', []),
            'warning' => get_option('coupon_automation_activation_warning', ''),
        ];
    }
    
    /**
     * Clear activation warnings
     */
    public static function clear_activation_warnings() {
        delete_option('coupon_automation_activation_warning');
        delete_option('coupon_automation_health_issues');
    }
}