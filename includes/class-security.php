<?php
/**
 * Security handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security class handles all security-related functionality
 */
class Coupon_Automation_Security {
    
    /**
     * Nonce actions used throughout the plugin
     */
    const NONCE_ACTIONS = [
        'fetch_coupons' => 'fetch_coupons_nonce',
        'stop_automation' => 'stop_automation_nonce',
        'clear_transients' => 'clear_transients_nonce',
        'purge_expired' => 'purge_expired_coupons_nonce',
        'clear_notifications' => 'clear_coupon_notifications_nonce',
        'populate_brands' => 'populate_brands_nonce',
        'admin_settings' => 'coupon_automation_admin_settings',
    ];
    
    /**
     * Required capabilities for different actions
     */
    const REQUIRED_CAPABILITIES = [
        'manage_settings' => 'manage_options',
        'manage_coupons' => 'edit_posts',
        'manage_brands' => 'manage_categories',
        'view_logs' => 'manage_options',
        'clear_cache' => 'manage_options',
    ];
    
    /**
     * Initialize security measures
     */
    public function init() {
        add_action('init', [$this, 'setup_security_headers']);
        add_action('wp_loaded', [$this, 'validate_admin_access']);
        add_filter('wp_redirect', [$this, 'validate_redirect_url'], 10, 2);
    }
    
    /**
     * Setup security headers
     */
    public function setup_security_headers() {
        if (is_admin() && $this->is_plugin_page()) {
            // Add security headers for plugin admin pages
            add_action('admin_head', function() {
                echo '<meta http-equiv="X-Content-Type-Options" content="nosniff">' . "\n";
                echo '<meta http-equiv="X-Frame-Options" content="SAMEORIGIN">' . "\n";
            });
        }
    }
    
    /**
     * Validate admin access for sensitive operations
     */
    public function validate_admin_access() {
        if (is_admin() && $this->is_plugin_page()) {
            if (!$this->current_user_can('manage_settings')) {
                wp_die(
                    esc_html__('You do not have sufficient permissions to access this page.', 'coupon-automation'),
                    esc_html__('Access Denied', 'coupon-automation'),
                    ['response' => 403]
                );
            }
        }
    }
    
    /**
     * Create a nonce for a specific action
     * 
     * @param string $action Action name
     * @return string Nonce value
     */
    public function create_nonce($action) {
        if (!isset(self::NONCE_ACTIONS[$action])) {
            return '';
        }
        
        return wp_create_nonce(self::NONCE_ACTIONS[$action]);
    }
    
    /**
     * Verify a nonce for a specific action
     * 
     * @param string $action Action name
     * @param string $nonce Nonce value to verify
     * @param bool $die Whether to die on failure
     * @return bool True if valid, false otherwise
     */
    public function verify_nonce($action, $nonce = '', $die = false) {
        if (!isset(self::NONCE_ACTIONS[$action])) {
            if ($die) {
                wp_die(
                    esc_html__('Invalid security action.', 'coupon-automation'),
                    esc_html__('Security Error', 'coupon-automation'),
                    ['response' => 403]
                );
            }
            return false;
        }
        
        $nonce_action = self::NONCE_ACTIONS[$action];
        
        // If no nonce provided, try to get from $_POST or $_GET
        if (empty($nonce)) {
            $nonce = $this->get_request_nonce();
        }
        
        $valid = wp_verify_nonce($nonce, $nonce_action);
        
        if (!$valid && $die) {
            wp_die(
                esc_html__('Security check failed. Please refresh the page and try again.', 'coupon-automation'),
                esc_html__('Security Error', 'coupon-automation'),
                ['response' => 403]
            );
        }
        
        return $valid;
    }
    
    /**
     * Check AJAX nonce for a specific action
     * 
     * @param string $action Action name
     * @param string $query_arg Query argument name (default: 'nonce')
     * @param bool $die Whether to die on failure
     * @return bool True if valid, false otherwise
     */
    public function check_ajax_nonce($action, $query_arg = 'nonce', $die = true) {
        if (!isset(self::NONCE_ACTIONS[$action])) {
            if ($die) {
                wp_send_json_error(__('Invalid security action.', 'coupon-automation'));
            }
            return false;
        }
        
        $result = check_ajax_referer(self::NONCE_ACTIONS[$action], $query_arg, $die);
        
        if (!$result && $die) {
            wp_send_json_error(__('Security check failed.', 'coupon-automation'));
        }
        
        return $result;
    }
    
    /**
     * Check if current user has required capability
     * 
     * @param string $action Action requiring capability check
     * @return bool True if user has capability, false otherwise
     */
    public function current_user_can($action) {
        if (!isset(self::REQUIRED_CAPABILITIES[$action])) {
            return false;
        }
        
        return current_user_can(self::REQUIRED_CAPABILITIES[$action]);
    }
    
    /**
     * Sanitize and validate input data
     * 
     * @param mixed $data Data to sanitize
     * @param string $type Type of sanitization to apply
     * @return mixed Sanitized data
     */
    public function sanitize_input($data, $type = 'text') {
        switch ($type) {
            case 'email':
                return sanitize_email($data);
            
            case 'url':
                return esc_url_raw($data);
            
            case 'int':
                return absint($data);
            
            case 'float':
                return floatval($data);
            
            case 'bool':
                return (bool) $data;
            
            case 'textarea':
                return sanitize_textarea_field($data);
            
            case 'html':
                return wp_kses_post($data);
            
            case 'api_key':
                return $this->sanitize_api_key($data);
            
            case 'array':
                if (!is_array($data)) {
                    return [];
                }
                return array_map([$this, 'sanitize_text'], $data);
            
            case 'text':
            default:
                return sanitize_text_field($data);
        }
    }
    
    /**
     * Sanitize API key (special handling for sensitive data)
     * 
     * @param string $api_key
     * @return string Sanitized API key
     */
    private function sanitize_api_key($api_key) {
        // Remove any whitespace and ensure it's a string
        $sanitized = trim(sanitize_text_field($api_key));
        
        // Basic validation for API key format (alphanumeric and common symbols)
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $sanitized)) {
            return '';
        }
        
        return $sanitized;
    }
    
    /**
     * Validate redirect URL to prevent open redirects
     * 
     * @param string $location Redirect location
     * @param int $status Redirect status code
     * @return string Validated redirect URL
     */
    public function validate_redirect_url($location, $status) {
        // Only validate redirects from our plugin pages
        if (!$this->is_plugin_page()) {
            return $location;
        }
        
        // Ensure the redirect URL is safe
        $allowed_hosts = [
            parse_url(home_url(), PHP_URL_HOST),
            parse_url(admin_url(), PHP_URL_HOST),
        ];
        
        $redirect_host = parse_url($location, PHP_URL_HOST);
        
        if ($redirect_host && !in_array($redirect_host, $allowed_hosts, true)) {
            // Redirect to admin dashboard instead of potentially malicious URL
            return admin_url();
        }
        
        return $location;
    }
    
    /**
     * Log security events
     * 
     * @param string $event Event description
     * @param array $context Additional context
     */
    public function log_security_event($event, $context = []) {
        $logger = coupon_automation()->get_service('logger');
        if ($logger) {
            $context['user_id'] = get_current_user_id();
            $context['ip_address'] = $this->get_client_ip();
            $context['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? 
                sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
            
            $logger->warning('Security Event: ' . $event, $context);
        }
    }
    
    /**
     * Get client IP address (handles proxy headers safely)
     * 
     * @return string Client IP address
     */
    private function get_client_ip() {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip_list = explode(',', $_SERVER[$header]);
                $ip = trim($ip_list[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    }
    
    /**
     * Get nonce from request (POST or GET)
     * 
     * @return string Nonce value
     */
    private function get_request_nonce() {
        if (isset($_POST['nonce'])) {
            return sanitize_text_field($_POST['nonce']);
        }
        
        if (isset($_GET['nonce'])) {
            return sanitize_text_field($_GET['nonce']);
        }
        
        if (isset($_POST['_wpnonce'])) {
            return sanitize_text_field($_POST['_wpnonce']);
        }
        
        if (isset($_GET['_wpnonce'])) {
            return sanitize_text_field($_GET['_wpnonce']);
        }
        
        return '';
    }
    
    /**
     * Check if current page is a plugin page
     * 
     * @return bool True if on plugin page, false otherwise
     */
    private function is_plugin_page() {
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        
        $plugin_pages = [
            'settings_page_coupon-automation',
            'settings_page_populate-brand-content',
        ];
        
        return in_array($screen->id, $plugin_pages, true);
    }
}