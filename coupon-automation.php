<?php

/**
 * Plugin Name: Coupon Automation
 * Description: Automates coupon and brand management with AddRevenue and AWIN APIs
 * Version: 2.1.8
 * Author: borkk
 * Text Domain: coupon-automation
 */

namespace CouponAutomation;

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('COUPON_AUTOMATION_VERSION', '2.1.8');
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
function init_coupon_automation()
{
    $plugin = Core\Plugin::getInstance();
    $plugin->run();
}

// Activation hook
register_activation_hook(__FILE__, function () {
    error_log('[CA] ========================================');
    error_log('[CA] PLUGIN ACTIVATION at ' . current_time('mysql'));
    error_log('[CA] ========================================');

    Core\Activator::activate();

    $next_run = wp_next_scheduled('coupon_automation_daily_fetch');
    if ($next_run) {
        error_log('[CA] Activation: Cron scheduled for: ' . date('Y-m-d H:i:s', $next_run));
    } else {
        error_log('[CA] Activation: WARNING - Cron not scheduled!');
    }

    error_log('[CA] ========================================');
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    error_log('[CA] ========================================');
    error_log('[CA] PLUGIN DEACTIVATION');
    error_log('[CA] ========================================');

    Core\Deactivator::deactivate();

    error_log('[CA] Deactivation: Cron job cleared');
    error_log('[CA] ========================================');
});

// Initialize plugin
add_action('plugins_loaded', __NAMESPACE__ . '\\init_coupon_automation');

// Add debugging action to log when cron actually fires
add_action('coupon_automation_daily_fetch', function () {
    error_log('[CA] ========================================');
    error_log('[CA] CRON HOOK FIRED: coupon_automation_daily_fetch');
    error_log('[CA] Time: ' . current_time('mysql'));
    error_log('[CA] ========================================');
}, 1); // Priority 1 to log before the actual handler

// Add admin-only diagnostic command (won't spam logs)
// Usage: Add ?ca_diagnostic=1 to any admin URL
add_action('admin_init', function () {
    if (isset($_GET['ca_diagnostic']) && current_user_can('manage_options')) {
        error_log('[CA] ========================================');
        error_log('[CA] DIAGNOSTIC CHECK REQUESTED');
        error_log('[CA] ========================================');

        $next_run = wp_next_scheduled('coupon_automation_daily_fetch');
        error_log('[CA] Cron scheduled: ' . ($next_run ? date('Y-m-d H:i:s', $next_run) : 'NOT SCHEDULED'));

        $last_sync = get_option('coupon_automation_last_sync', 'Never');
        if (is_numeric($last_sync)) {
            error_log('[CA] Last sync: ' . date('Y-m-d H:i:s', $last_sync) . ' (' . human_time_diff($last_sync, current_time('timestamp')) . ' ago)');
        } else {
            error_log('[CA] Last sync: Never');
        }

        $sync_status = get_option('coupon_automation_sync_status', 'unknown');
        error_log('[CA] Sync status: ' . $sync_status);

        $is_running = get_transient('fetch_process_running');
        error_log('[CA] Process running: ' . ($is_running ? 'YES' : 'NO'));

        $stop_requested = get_option('coupon_automation_stop_requested', false);
        error_log('[CA] Stop requested: ' . ($stop_requested ? 'YES' : 'NO'));

        $last_stats = get_option('coupon_automation_last_sync_stats', []);
        error_log('[CA] Last stats: ' . json_encode($last_stats));

        error_log('[CA] ========================================');

        wp_die('Diagnostic info logged to error_log. Check with: grep "\[CA\]" error_log | tail -20');
    }
});
