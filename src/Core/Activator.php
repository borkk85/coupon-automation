<?php

namespace CouponAutomation\Core;

/**
 * Fired during plugin activation
 */
class Activator {
    
    /**
     * Plugin activation tasks
     */
    public static function activate() {
        // Create database tables if needed
        self::createTables();
        
        // Set default options
        self::setDefaultOptions();
        
        // Schedule cron events
        if (!wp_next_scheduled('coupon_automation_daily_fetch')) {
            wp_schedule_event(time(), 'daily', 'coupon_automation_daily_fetch');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create custom database tables
     */
    private static function createTables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Notifications table
        $table_name = $wpdb->prefix . 'coupon_automation_logs';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            message text NOT NULL,
            details longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_index (type),
            KEY created_at_index (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Set default plugin options
     */
    private static function setDefaultOptions() {
        $defaults = [
            'coupon_automation_version' => COUPON_AUTOMATION_VERSION,
            'coupon_automation_batch_size' => 10,
            'coupon_automation_api_timeout' => 30,
            'coupon_automation_enable_logging' => true,
            'coupon_automation_notification_email' => get_option('admin_email'),
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
}

