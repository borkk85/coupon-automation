<?php
/**
 * Plugin Name: Coupon Automation
 * Description: Automates coupon and brand management with AddRevenue and AWIN APIs
 * Version: 2.0.6
 * Author: borkk
 * Text Domain: coupon-automation
 */

namespace CouponAutomation;

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('COUPON_AUTOMATION_VERSION', '2.0.6');
define('COUPON_AUTOMATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('COUPON_AUTOMATION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COUPON_AUTOMATION_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'CouponAutomation\\';
    $base_dir = COUPON_AUTOMATION_PLUGIN_DIR . 'src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function init_coupon_automation() {
    $plugin = Core\Plugin::getInstance();
    $plugin->run();
}

// Activation hook
register_activation_hook(__FILE__, function() {
    Core\Activator::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    Core\Deactivator::deactivate();
});

// Initialize plugin
add_action('plugins_loaded', __NAMESPACE__ . '\\init_coupon_automation');