<?php
/**
 * Logger for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger class for comprehensive logging functionality
 */
class Coupon_Automation_Logger {
    
    /**
     * Log levels
     */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';
    
    /**
     * Log file directory
     * @var string
     */
    private $log_dir;
    
    /**
     * Whether debug logging is enabled
     * @var bool
     */
    private $debug_enabled;
    
    /**
     * Settings service
     * @var Coupon_Automation_Settings
     */
    private $settings;
    
    /**
     * Maximum log file size (in bytes)
     * @var int
     */
    private $max_file_size = 10485760; // 10MB
    
    /**
     * Initialize logger
     */
    public function init() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/coupon-automation/logs';
        
        // Create log directory if it doesn't exist
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            $this->protect_log_directory();
        }
        
        $this->settings = coupon_automation()->get_service('settings');
        $this->debug_enabled = $this->settings ? $this->settings->get('general.enable_debug_logging', false) : false;
        
        // Register cleanup hooks
        add_action('coupon_automation_cleanup', [$this, 'cleanup_old_logs']);
    }
    
    /**
     * Log emergency message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function emergency($message, array $context = []) {
        $this->log(self::EMERGENCY, $message, $context);
    }
    
    /**
     * Log alert message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function alert($message, array $context = []) {
        $this->log(self::ALERT, $message, $context);
    }
    
    /**
     * Log critical message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function critical($message, array $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function error($message, array $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function warning($message, array $context = []) {
        $this->log(self::WARNING, $message, $context);
    }
    
    /**
     * Log notice message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function notice($message, array $context = []) {
        $this->log(self::NOTICE, $message, $context);
    }
    
    /**
     * Log info message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function info($message, array $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    /**
     * Log debug message (only if debug logging is enabled)
     * 
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function debug($message, array $context = []) {
        if ($this->debug_enabled) {
            $this->log(self::DEBUG, $message, $context);
        }
    }
    
    /**
     * Log API request
     * 
     * @param string $api_source API source name
     * @param string $url Request URL
     * @param string $method HTTP method
     * @param array $headers Request headers (sensitive data will be redacted)
     * @param mixed $body Request body
     * @param int $response_code Response code
     * @param float $response_time Response time in seconds
     * @param string $error_message Error message if any
     */
    public function log_api_request($api_source, $url, $method = 'GET', $headers = [], $body = null, $response_code = null, $response_time = null, $error_message = null) {
        $context = [
            'api_source' => $api_source,
            'url' => $this->sanitize_url_for_logging($url),
            'method' => $method,
            'headers' => $this->redact_sensitive_headers($headers),
            'response_code' => $response_code,
            'response_time' => $response_time,
        ];
        
        if ($body && $this->debug_enabled) {
            $context['body'] = $this->sanitize_request_body($body);
        }
        
        if ($error_message) {
            $context['error'] = $error_message;
            $this->error("API request failed: {$api_source} {$method} {$url}", $context);
        } else {
            $this->info("API request: {$api_source} {$method} {$url}", $context);
        }
        
        // Also log to database for API monitoring
        $database = coupon_automation()->get_service('database');
        if ($database) {
            $database->log_api_request($api_source, $url, $method, $response_code, $response_time, $error_message);
        }
    }
    
    /**
     * Log processing activity
     * 
     * @param string $process_type Type of process
     * @param string $status Process status
     * @param int $items_processed Number of items processed
     * @param int $total_items Total number of items
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function log_processing($process_type, $status, $items_processed = 0, $total_items = 0, $message = '', $context = []) {
        $context['process_type'] = $process_type;
        $context['status'] = $status;
        $context['items_processed'] = $items_processed;
        $context['total_items'] = $total_items;
        $context['progress'] = $total_items > 0 ? round(($items_processed / $total_items) * 100, 2) . '%' : '0%';
        
        $log_message = $message ?: "Processing {$process_type}: {$status} ({$items_processed}/{$total_items})";
        
        if ($status === 'completed') {
            $this->info($log_message, $context);
        } elseif ($status === 'failed') {
            $this->error($log_message, $context);
        } else {
            $this->debug($log_message, $context);
        }
        
        // Also log to database for processing monitoring
        $database = coupon_automation()->get_service('database');
        if ($database) {
            $database->log_processing_status($process_type, $status, $items_processed, $total_items, $message);
        }
    }
    
    /**
     * Core logging method
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function log($level, $message, array $context = []) {
        // Don't log if directory is not writable
        if (!is_writable($this->log_dir)) {
            return;
        }
        
        // Skip debug logs if debug is disabled
        if ($level === self::DEBUG && !$this->debug_enabled) {
            return;
        }
        
        $log_entry = $this->format_log_entry($level, $message, $context);
        $log_file = $this->get_log_file($level);
        
        // Rotate log file if it's too large
        $this->maybe_rotate_log_file($log_file);
        
        // Write to log file
        file_put_contents($log_file, $log_entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        // Also log to WordPress debug log for critical issues
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR])) {
            error_log("Coupon Automation [{$level}]: {$message}");
        }
        
        // Send admin notifications for critical issues
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL])) {
            $this->send_admin_notification($level, $message, $context);
        }
    }
    
    /**
     * Format log entry
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @return string Formatted log entry
     */
    private function format_log_entry($level, $message, array $context) {
        $timestamp = current_time('c');
        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();
        
        // Interpolate context variables in message
        $message = $this->interpolate($message, $context);
        
        // Build log entry
        $log_parts = [
            '[' . $timestamp . ']',
            strtoupper($level),
            $message
        ];
        
        // Add user context if available
        if ($user_id) {
            $log_parts[] = 'User:' . $user_id;
        }
        
        // Add IP address
        if ($ip_address) {
            $log_parts[] = 'IP:' . $ip_address;
        }
        
        // Add context data
        if (!empty($context)) {
            $context_string = $this->format_context($context);
            if ($context_string) {
                $log_parts[] = 'Context:' . $context_string;
            }
        }
        
        return implode(' ', $log_parts);
    }
    
    /**
     * Get appropriate log file for the level
     * 
     * @param string $level Log level
     * @return string Log file path
     */
    private function get_log_file($level) {
        $date = current_time('Y-m-d');
        
        // Use separate files for different log levels
        switch ($level) {
            case self::EMERGENCY:
            case self::ALERT:
            case self::CRITICAL:
                return $this->log_dir . "/critical-{$date}.log";
            
            case self::ERROR:
                return $this->log_dir . "/error-{$date}.log";
            
            case self::WARNING:
            case self::NOTICE:
                return $this->log_dir . "/warning-{$date}.log";
            
            case self::DEBUG:
                return $this->log_dir . "/debug-{$date}.log";
            
            case self::INFO:
            default:
                return $this->log_dir . "/info-{$date}.log";
        }
    }
    
    /**
     * Interpolate context variables in message
     * 
     * @param string $message Message with placeholders
     * @param array $context Context variables
     * @return string Interpolated message
     */
    private function interpolate($message, array $context) {
        $replace = [];
        
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        
        return strtr($message, $replace);
    }
    
    /**
     * Format context data for logging
     * 
     * @param array $context Context data
     * @return string Formatted context string
     */
    private function format_context(array $context) {
        $formatted_context = [];
        
        foreach ($context as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $formatted_context[] = $key . '=' . var_export($value, true);
            } elseif (is_array($value) || is_object($value)) {
                $formatted_context[] = $key . '=' . json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }
        
        return implode(',', $formatted_context);
    }
    
    /**
     * Rotate log file if it exceeds maximum size
     * 
     * @param string $log_file Log file path
     */
    private function maybe_rotate_log_file($log_file) {
        if (!file_exists($log_file)) {
            return;
        }
        
        if (filesize($log_file) >= $this->max_file_size) {
            $backup_file = $log_file . '.' . time() . '.bak';
            rename($log_file, $backup_file);
            
            // Compress the backup file if possible
            if (function_exists('gzopen')) {
                $this->compress_log_file($backup_file);
            }
        }
    }
    
    /**
     * Compress log file
     * 
     * @param string $file_path File to compress
     */
    private function compress_log_file($file_path) {
        $compressed_file = $file_path . '.gz';
        
        $file_content = file_get_contents($file_path);
        $compressed_content = gzencode($file_content, 9);
        
        if ($compressed_content !== false) {
            file_put_contents($compressed_file, $compressed_content);
            unlink($file_path);
        }
    }
    
    /**
     * Clean up old log files
     */
    public function cleanup_old_logs() {
        $retention_days = $this->settings ? $this->settings->get('general.log_retention_days', 30) : 30;
        $cutoff_time = time() - ($retention_days * DAY_IN_SECONDS);
        
        $files = glob($this->log_dir . '/*.log*');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get recent log entries
     * 
     * @param string $level Log level filter
     * @param int $limit Number of entries to return
     * @param string $date Date filter (Y-m-d format)
     * @return array Log entries
     */
    public function get_recent_logs($level = null, $limit = 100, $date = null) {
        $date = $date ?: current_time('Y-m-d');
        
        if ($level) {
            $log_file = $this->get_log_file($level);
        } else {
            // Get all log files for the date
            $log_files = glob($this->log_dir . "/*-{$date}.log");
            if (empty($log_files)) {
                return [];
            }
            $log_file = $log_files[0]; // Just use the first one for now
        }
        
        if (!file_exists($log_file)) {
            return [];
        }
        
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -$limit);
        
        return array_reverse($lines);
    }
    
    /**
     * Get client IP address safely
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
     * Sanitize URL for logging (remove sensitive parameters)
     * 
     * @param string $url URL to sanitize
     * @return string Sanitized URL
     */
    private function sanitize_url_for_logging($url) {
        $parsed = parse_url($url);
        
        if (!isset($parsed['query'])) {
            return $url;
        }
        
        parse_str($parsed['query'], $params);
        
        // Remove sensitive parameters
        $sensitive_params = ['api_key', 'token', 'password', 'secret', 'key'];
        foreach ($sensitive_params as $param) {
            if (isset($params[$param])) {
                $params[$param] = '[REDACTED]';
            }
        }
        
        $parsed['query'] = http_build_query($params);
        
        return $this->build_url($parsed);
    }
    
    /**
     * Redact sensitive headers
     * 
     * @param array $headers Headers array
     * @return array Redacted headers
     */
    private function redact_sensitive_headers($headers) {
        $redacted = $headers;
        $sensitive_headers = ['authorization', 'x-api-key', 'x-auth-token'];
        
        foreach ($sensitive_headers as $header) {
            $header_variations = [
                $header,
                strtoupper($header),
                str_replace('-', '_', strtoupper($header))
            ];
            
            foreach ($header_variations as $variation) {
                if (isset($redacted[$variation])) {
                    $redacted[$variation] = '[REDACTED]';
                }
            }
        }
        
        return $redacted;
    }
    
    /**
     * Sanitize request body for logging
     * 
     * @param mixed $body Request body
     * @return mixed Sanitized body
     */
    private function sanitize_request_body($body) {
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->redact_sensitive_data($decoded);
            }
        } elseif (is_array($body)) {
            return $this->redact_sensitive_data($body);
        }
        
        return $body;
    }
    
    /**
     * Redact sensitive data from arrays
     * 
     * @param array $data Data array
     * @return array Redacted data
     */
    private function redact_sensitive_data($data) {
        $sensitive_keys = ['password', 'token', 'key', 'secret', 'api_key'];
        
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitive_keys)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact_sensitive_data($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Build URL from parsed components
     * 
     * @param array $parsed Parsed URL components
     * @return string Rebuilt URL
     */
    private function build_url($parsed) {
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $user = isset($parsed['user']) ? $parsed['user'] : '';
        $pass = isset($parsed['pass']) ? ':' . $parsed['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        
        return "$scheme$user$pass$host$port$path$query$fragment";
    }
    
    /**
     * Protect log directory with .htaccess
     */
    private function protect_log_directory() {
        $htaccess_content = "Order deny,allow\nDeny from all\n";
        file_put_contents($this->log_dir . '/.htaccess', $htaccess_content);
        
        // Create index.php to prevent directory listing
        file_put_contents($this->log_dir . '/index.php', '<?php // Silence is golden');
    }
    
    /**
     * Send admin notification for critical issues
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function send_admin_notification($level, $message, $context) {
        // Only send notifications if enabled in settings
        $notifications_enabled = $this->settings ? $this->settings->get('automation.enable_notifications', true) : true;
        
        if (!$notifications_enabled) {
            return;
        }
        
        // Rate limit notifications to prevent spam
        $notification_key = 'coupon_automation_last_' . $level . '_notification';
        $last_notification = get_transient($notification_key);
        
        if ($last_notification) {
            return; // Don't send notification within the last hour
        }
        
        set_transient($notification_key, time(), HOUR_IN_SECONDS);
        
        // Store notification for admin display
        $notifications = get_option('coupon_automation_notifications', []);
        $notifications[] = [
            'type' => 'system_alert',
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'time' => current_time('mysql')
        ];
        
        // Keep only the last 20 notifications
        $notifications = array_slice($notifications, -20);
        update_option('coupon_automation_notifications', $notifications);
    }
}