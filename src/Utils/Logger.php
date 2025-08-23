<?php

namespace CouponAutomation\Utils;

/**
 * Logger utility class with activity tracking
 */
class Logger {
    
    private $logTable;
    private $maxLogs = 1000;
    
    public function __construct() {
        global $wpdb;
        $this->logTable = $wpdb->prefix . 'coupon_automation_logs';
    }
    
    /**
     * Log info message
     */
    public function info($message, $details = null) {
        $this->log('info', $message, $details);
    }
    
    /**
     * Log error message
     */
    public function error($message, $details = null) {
        $this->log('error', $message, $details);
        
        // Also log to WordPress error log
        error_log('Coupon Automation Error: ' . $message);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $details = null) {
        $this->log('warning', $message, $details);
    }
    
    /**
     * Log activity for display in dashboard
     */
    public function activity($message, $type = 'info') {
        $this->log('activity', $message, ['activity_type' => $type]);
        
        // Store in transient for quick access
        $activities = get_transient('coupon_automation_activities') ?: [];
        array_unshift($activities, [
            'message' => $message,
            'type' => $type,
            'time' => current_time('timestamp')
        ]);
        
        // Keep only last 10 activities
        $activities = array_slice($activities, 0, 10);
        set_transient('coupon_automation_activities', $activities, DAY_IN_SECONDS);
    }
    
    /**
     * Write log entry
     */
    private function log($type, $message, $details = null) {
        global $wpdb;
        
        if (!get_option('coupon_automation_enable_logging', true)) {
            return;
        }
        
        $wpdb->insert(
            $this->logTable,
            [
                'type' => $type,
                'message' => $message,
                'details' => $details ? json_encode($details) : null,
                'created_at' => current_time('mysql')
            ]
        );
        
        // Cleanup old logs
        $this->cleanup();
    }
    
    /**
     * Cleanup old logs
     */
    private function cleanup() {
        global $wpdb;
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->logTable}");
        
        if ($count > $this->maxLogs) {
            $wpdb->query(
                "DELETE FROM {$this->logTable} 
                 WHERE id NOT IN (
                     SELECT id FROM (
                         SELECT id FROM {$this->logTable} 
                         ORDER BY created_at DESC 
                         LIMIT {$this->maxLogs}
                     ) tmp
                 )"
            );
        }
    }
    
    /**
     * Get recent logs
     */
    public function getRecentLogs($limit = 100, $type = null) {
        global $wpdb;
        
        $where = $type ? $wpdb->prepare(" WHERE type = %s", $type) : "";
        
        return $wpdb->get_results(
            "SELECT * FROM {$this->logTable} 
             {$where}
             ORDER BY created_at DESC 
             LIMIT {$limit}"
        );
    }
    
    /**
     * Get recent activities for dashboard display
     */
    public function getRecentActivities($limit = 5) {
        return get_transient('coupon_automation_activities') ?: [];
    }
}