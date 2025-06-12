<?php
/**
 * Autoloader for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader class
 */
class Coupon_Automation_Autoloader {
    
    /**
     * Class map for faster loading
     * @var array
     */
    private static $class_map = [];
    
    /**
     * Initialize the autoloader
     */
    public static function init() {
        spl_autoload_register([__CLASS__, 'autoload']);
        self::build_class_map();
    }
    
    /**
     * Autoload classes
     * 
     * @param string $class_name
     */
    public static function autoload($class_name) {
        // Only load our plugin classes
        if (strpos($class_name, 'Coupon_Automation_') !== 0) {
            return;
        }
        
        // Check class map first for performance
        if (isset(self::$class_map[$class_name])) {
            $file_path = self::$class_map[$class_name];
            if (file_exists($file_path)) {
                require_once $file_path;
                return;
            }
        }
        
        // Fallback to convention-based loading
        $file_path = self::get_file_path_from_class_name($class_name);
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
    
    /**
     * Build class map for faster loading
     */
    private static function build_class_map() {
        $base_path = COUPON_AUTOMATION_PLUGIN_DIR . 'includes/';
        
        self::$class_map = [
            // Core classes
            'Coupon_Automation_Security' => $base_path . 'class-security.php',
            'Coupon_Automation_Settings' => $base_path . 'class-settings.php',
            'Coupon_Automation_Database' => $base_path . 'class-database.php',
            'Coupon_Automation_Logger' => $base_path . 'class-logger.php',
            'Coupon_Automation_Activator' => $base_path . 'class-activator.php',
            
            // Service classes
            'Coupon_Automation_API_Manager' => $base_path . 'services/class-api-manager.php',
            'Coupon_Automation_Admin' => $base_path . 'admin/class-admin.php',
            'Coupon_Automation_AJAX' => $base_path . 'ajax/class-ajax.php',
            'Coupon_Automation_Cron' => $base_path . 'class-cron.php',
            'Coupon_Automation_Brand_Manager' => $base_path . 'services/class-brand-manager.php',
            'Coupon_Automation_Coupon_Manager' => $base_path . 'services/class-coupon-manager.php',
            
            // API classes
            'Coupon_Automation_API_AddRevenue' => $base_path . 'api/class-addrevenue-api.php',
            'Coupon_Automation_API_AWIN' => $base_path . 'api/class-awin-api.php',
            'Coupon_Automation_API_OpenAI' => $base_path . 'api/class-openai-api.php',
            'Coupon_Automation_API_YOURLS' => $base_path . 'api/class-yourls-api.php',
            
            // Utility classes
            'Coupon_Automation_Utils' => $base_path . 'class-utils.php',
            'Coupon_Automation_Encryption' => $base_path . 'class-encryption.php',
            'Coupon_Automation_Validator' => $base_path . 'class-validator.php',
        ];
    }
    
    /**
     * Convert class name to file path using convention
     * 
     * @param string $class_name
     * @return string
     */
    private static function get_file_path_from_class_name($class_name) {
        // Remove the plugin prefix
        $class_name = str_replace('Coupon_Automation_', '', $class_name);
        
        // Convert to lowercase and replace underscores with hyphens
        $file_name = 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';
        
        // Determine subdirectory based on class type
        $subdirectory = '';
        if (strpos($class_name, 'API_') === 0) {
            $subdirectory = 'api/';
        } elseif (in_array($class_name, ['Admin', 'Admin_Settings', 'Admin_Pages'])) {
            $subdirectory = 'admin/';
        } elseif (strpos($class_name, 'AJAX') === 0) {
            $subdirectory = 'ajax/';
        } elseif (in_array($class_name, ['Brand_Manager', 'Coupon_Manager', 'API_Manager'])) {
            $subdirectory = 'services/';
        }
        
        return COUPON_AUTOMATION_PLUGIN_DIR . 'includes/' . $subdirectory . $file_name;
    }
    
    /**
     * Get all available classes
     * 
     * @return array
     */
    public static function get_available_classes() {
        return array_keys(self::$class_map);
    }
    
    /**
     * Check if a class is available
     * 
     * @param string $class_name
     * @return bool
     */
    public static function is_class_available($class_name) {
        return isset(self::$class_map[$class_name]) || 
               file_exists(self::get_file_path_from_class_name($class_name));
    }
}