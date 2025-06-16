<?php
/**
 * Plugin Name: Coupon Automation
 * Description: Automates the creation of coupons based on data from addrevenue.io and AWIN APIs with enhanced security and performance.
 * Version: 2.0.0
 * Author: borkk
 * Text Domain: coupon-automation
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('COUPON_AUTOMATION_VERSION', '2.0.0');
define('COUPON_AUTOMATION_PLUGIN_FILE', __FILE__);
define('COUPON_AUTOMATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('COUPON_AUTOMATION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COUPON_AUTOMATION_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check PHP version compatibility
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Coupon Automation requires PHP 7.4 or higher. Please update your PHP version.', 'coupon-automation');
        echo '</p></div>';
    });
    return;
}

// Load the autoloader early
require_once COUPON_AUTOMATION_PLUGIN_DIR . 'includes/class-autoloader.php';
Coupon_Automation_Autoloader::init();

/**
 * Main plugin class
 */
final class Coupon_Automation {
    
    /**
     * Plugin instance
     * @var Coupon_Automation
     */
    private static $instance = null;
    
    /**
     * Services container
     * @var array
     */
    private $services = [];
    
    /**
     * Plugin initialization status
     * @var bool
     */
    private $initialized = false;
    
    /**
     * Get plugin instance (Singleton pattern)
     * 
     * @return Coupon_Automation
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Initialize the plugin
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {}
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init'], 0);
        add_action('init', [$this, 'load_textdomain']);
        
        // Activation and deactivation hooks - these run before plugins_loaded
        register_activation_hook(COUPON_AUTOMATION_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(COUPON_AUTOMATION_PLUGIN_FILE, [$this, 'deactivate']);
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        if ($this->initialized) {
            return;
        }
        
        try {
            // Load core services
            $this->load_core_services();
            
            // Initialize services
            $this->init_services();
            
            $this->initialized = true;
            
            // Hook for other plugins to interact with our plugin
            do_action('coupon_automation_loaded', $this);
            
        } catch (Exception $e) {
            $this->handle_error('Plugin initialization failed', $e);
        }
    }
    
    /**
     * Clear plugin scheduled events directly
     * Used during deactivation when services might not be available
     */
    private function clear_plugin_scheduled_events() {
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
    }
    
    /**
     * Load plugin textdomain for internationalization
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'coupon-automation',
            false,
            dirname(COUPON_AUTOMATION_PLUGIN_BASENAME) . '/languages/'
        );
    }
    
    /**
     * Load core services
     */
    private function load_core_services() {
        // Security service (highest priority)
        $this->services['security'] = new Coupon_Automation_Security();
        
        // Settings service
        $this->services['settings'] = new Coupon_Automation_Settings();
        
        // Database service
        $this->services['database'] = new Coupon_Automation_Database();
        
        // Logger service
        $this->services['logger'] = new Coupon_Automation_Logger();
        
        // API service
        $this->services['api'] = new Coupon_Automation_API_Manager();
        
        // Admin service - only in admin
        if (is_admin()) {
            $this->services['admin'] = new Coupon_Automation_Admin();
        }
        
        // AJAX service - only during AJAX requests
        if (wp_doing_ajax()) {
            $this->services['ajax'] = new Coupon_Automation_AJAX();
        }
        
        // Cron service
        $this->services['cron'] = new Coupon_Automation_Cron();
        
        // Brand service
        $this->services['brand'] = new Coupon_Automation_Brand_Manager();
        
        // Coupon service
        $this->services['coupon'] = new Coupon_Automation_Coupon_Manager();
    }
    
    /**
     * Initialize all loaded services
     */
    private function init_services() {
        foreach ($this->services as $service) {
            if (method_exists($service, 'init')) {
                $service->init();
            }
        }
    }
    
    /**
     * Get a service instance
     * 
     * @param string $service Service name
     * @return mixed|null Service instance or null if not found
     */
    public function get_service($service) {
        return isset($this->services[$service]) ? $this->services[$service] : null;
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        try {
            // Autoloader is already loaded at this point
            
            // Run activation procedures
            $activator = new Coupon_Automation_Activator();
            $activator->activate();
            
            // Schedule basic cron events directly without using the Cron class
            $this->schedule_activation_events();
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
        } catch (Exception $e) {
            $this->handle_error('Plugin activation failed', $e);
            // Prevent activation if there's an error
            wp_die(
                esc_html__('Coupon Automation activation failed. Please check your server error logs.', 'coupon-automation'),
                esc_html__('Plugin Activation Error', 'coupon-automation'),
                ['back_link' => true]
            );
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        try {
            // Clear scheduled events
            if (isset($this->services['cron'])) {
                $this->services['cron']->clear_scheduled_events();
            } else {
                // Fallback - clear events directly without requiring the cron class services
                $this->clear_plugin_scheduled_events();
            }
            
            // Clear transients
            $this->clear_plugin_transients();
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
        } catch (Exception $e) {
            $this->handle_error('Plugin deactivation failed', $e);
        }
    }
    
    /**
     * Clear all plugin-related transients
     */
    private function clear_plugin_transients() {
        $transients = [
            'fetch_process_running',
            'addrevenue_advertisers_data',
            'addrevenue_campaigns_data',
            'awin_promotions_data',
            'api_processed_count'
        ];
        
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
    }
    
    /**
     * Handle errors consistently
     * 
     * @param string $message Error message
     * @param Exception $e Exception object
     */
    private function handle_error($message, Exception $e) {
        if (isset($this->services['logger'])) {
            $this->services['logger']->error($message, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } else {
            error_log(sprintf('Coupon Automation Error: %s - %s', $message, $e->getMessage()));
        }
        
        // Show admin notice for critical errors
        if (is_admin()) {
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html(sprintf(__('Coupon Automation: %s', 'coupon-automation'), $message));
                echo '</p></div>';
            });
        }
    }
    
    /**
     * Get plugin version
     * 
     * @return string
     */
    public function get_version() {
        return COUPON_AUTOMATION_VERSION;
    }
    
    /**
     * Get plugin path
     * 
     * @return string
     */
    public function get_plugin_path() {
        return COUPON_AUTOMATION_PLUGIN_DIR;
    }
    
    /**
     * Get plugin URL
     * 
     * @return string
     */
    public function get_plugin_url() {
        return COUPON_AUTOMATION_PLUGIN_URL;
    }
    
    /**
     * Schedule basic events during activation
     * Simple scheduling without requiring full service initialization
     */
    private function schedule_basic_events() {
        // Schedule daily sync - default to daily interval
        if (!wp_next_scheduled('coupon_automation_daily_sync')) {
            $start_time = strtotime('tomorrow 2:00 AM');
            wp_schedule_event($start_time, 'daily', 'coupon_automation_daily_sync');
        }
        
        // Schedule weekly cleanup
        if (!wp_next_scheduled('coupon_automation_cleanup')) {
            $start_time = strtotime('next sunday 3:00 AM');
            wp_schedule_event($start_time, 'weekly', 'coupon_automation_cleanup');
        }
        
        // Schedule hourly health check
        if (!wp_next_scheduled('coupon_automation_health_check')) {
            $start_time = time() + HOUR_IN_SECONDS;
            wp_schedule_event($start_time, 'hourly', 'coupon_automation_health_check');
        }
    }
    
    /**
     * Schedule events during activation (renamed method)
     * Simple scheduling without requiring full service initialization
     */
    private function schedule_activation_events() {
        // Schedule daily sync - default to daily interval
        if (!wp_next_scheduled('coupon_automation_daily_sync')) {
            $start_time = strtotime('tomorrow 2:00 AM');
            wp_schedule_event($start_time, 'daily', 'coupon_automation_daily_sync');
        }
        
        // Schedule weekly cleanup
        if (!wp_next_scheduled('coupon_automation_cleanup')) {
            $start_time = strtotime('next sunday 3:00 AM');
            wp_schedule_event($start_time, 'weekly', 'coupon_automation_cleanup');
        }
        
        // Schedule hourly health check
        if (!wp_next_scheduled('coupon_automation_health_check')) {
            $start_time = time() + HOUR_IN_SECONDS;
            wp_schedule_event($start_time, 'hourly', 'coupon_automation_health_check');
        }
    }
}

/**
 * Initialize the plugin
 * 
 * @return Coupon_Automation
 */
function coupon_automation() {
    return Coupon_Automation::get_instance();
}

// Start the plugin
coupon_automation();