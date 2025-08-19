<?php

namespace CouponAutomation\Core;

/**
 * Fired during plugin deactivation
 */
class Deactivator {
    
    /**
     * Plugin deactivation tasks
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('coupon_automation_daily_fetch');
        
        // Clear transients
        self::clearTransients();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Clear all plugin transients
     */
    private static function clearTransients() {
        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');
    }
}