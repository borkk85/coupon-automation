<?php
/**
 * Database handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database class for secure database operations
 */
class Coupon_Automation_Database {
    
    /**
     * WordPress database instance
     * @var wpdb
     */
    private $wpdb;
    
    /**
     * Table prefix for plugin tables
     * @var string
     */
    private $table_prefix;
    
    /**
     * Initialize database handler
     */
    public function init() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix . 'coupon_automation_';
        
        // Create custom tables if needed
        add_action('admin_init', [$this, 'maybe_create_tables']);
    }
    
    /**
     * Create custom tables if they don't exist
     */
    public function maybe_create_tables() {
        $current_version = get_option('coupon_automation_db_version', '0');
        
        if (version_compare($current_version, COUPON_AUTOMATION_VERSION, '<')) {
            $this->create_tables();
            update_option('coupon_automation_db_version', COUPON_AUTOMATION_VERSION);
        }
    }
    
    /**
     * Create custom database tables
     */
    private function create_tables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        
        // API logs table
        $api_logs_table = $this->table_prefix . 'api_logs';
        $sql_api_logs = "CREATE TABLE IF NOT EXISTS $api_logs_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_source varchar(50) NOT NULL,
            request_url text NOT NULL,
            request_method varchar(10) NOT NULL DEFAULT 'GET',
            response_code int(11) DEFAULT NULL,
            response_time float DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY api_source (api_source),
            KEY created_at (created_at),
            KEY response_code (response_code)
        ) $charset_collate;";
        
        // Processing logs table
        $processing_logs_table = $this->table_prefix . 'processing_logs';
        $sql_processing_logs = "CREATE TABLE IF NOT EXISTS $processing_logs_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            process_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            items_processed int(11) DEFAULT 0,
            total_items int(11) DEFAULT 0,
            error_message text DEFAULT NULL,
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY process_type (process_type),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset_collate;";
        
        // Brand mapping table for API correlation
        $brand_mapping_table = $this->table_prefix . 'brand_mapping';
        $sql_brand_mapping = "CREATE TABLE IF NOT EXISTS $brand_mapping_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            term_id bigint(20) NOT NULL,
            api_source varchar(50) NOT NULL,
            external_id varchar(255) NOT NULL,
            last_synced datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_mapping (term_id, api_source, external_id),
            KEY term_id (term_id),
            KEY api_source (api_source),
            KEY external_id (external_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_api_logs);
        dbDelta($sql_processing_logs);
        dbDelta($sql_brand_mapping);
    }
    
    /**
     * Get coupons with secure query building
     * 
     * @param array $args Query arguments
     * @return array|WP_Error Coupon data or error
     */
    public function get_coupons($args = []) {
        $defaults = [
            'post_type' => 'coupons',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'meta_query' => [],
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Sanitize arguments
        $args = $this->sanitize_query_args($args);
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts;
        }
        
        return [];
    }
    
    /**
     * Get coupon by external ID securely
     * 
     * @param string $external_id External coupon ID
     * @param string $api_source API source name
     * @return WP_Post|null Coupon post or null
     */
    public function get_coupon_by_external_id($external_id, $api_source = 'addrevenue') {
        $external_id = sanitize_text_field($external_id);
        $api_source = sanitize_text_field($api_source);
        
        $args = [
            'post_type' => 'coupons',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'coupon_id',
                    'value' => $external_id,
                    'compare' => '='
                ],
                [
                    'key' => 'api_source',
                    'value' => $api_source,
                    'compare' => '='
                ]
            ]
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        
        return null;
    }
    
    /**
     * Get expired coupons securely
     * 
     * @param int $limit Number of coupons to retrieve
     * @return array Expired coupon IDs
     */
    public function get_expired_coupons($limit = 100) {
        $limit = absint($limit);
        $today = current_time('Ymd');
        
        $args = [
            'post_type' => 'coupons',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'valid_untill',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => 'valid_untill',
                    'value' => '',
                    'compare' => '!=',
                ],
                [
                    'key' => 'valid_untill',
                    'value' => $today,
                    'compare' => '<',
                    'type' => 'DATE'
                ]
            ]
        ];
        
        return get_posts($args);
    }
    
    /**
     * Log API request securely
     * 
     * @param string $api_source API source name
     * @param string $request_url Request URL
     * @param string $method HTTP method
     * @param int|null $response_code Response code
     * @param float|null $response_time Response time in seconds
     * @param string|null $error_message Error message if any
     * @return int|false Insert ID or false on failure
     */
    public function log_api_request($api_source, $request_url, $method = 'GET', $response_code = null, $response_time = null, $error_message = null) {
        $table = $this->table_prefix . 'api_logs';
        
        $data = [
            'api_source' => sanitize_text_field($api_source),
            'request_url' => esc_url_raw($request_url),
            'request_method' => sanitize_text_field($method),
            'response_code' => $response_code ? absint($response_code) : null,
            'response_time' => $response_time ? floatval($response_time) : null,
            'error_message' => $error_message ? sanitize_textarea_field($error_message) : null,
        ];
        
        $formats = ['%s', '%s', '%s', '%d', '%f', '%s'];
        
        $result = $this->wpdb->insert($table, $data, $formats);
        
        if ($result === false) {
            error_log('Failed to log API request: ' . $this->wpdb->last_error);
            return false;
        }
        
        return $this->wpdb->insert_id;
    }
    
    /**
     * Log processing status securely
     * 
     * @param string $process_type Type of process
     * @param string $status Process status
     * @param int $items_processed Number of items processed
     * @param int $total_items Total number of items
     * @param string|null $error_message Error message if any
     * @return int|false Insert ID or false on failure
     */
    public function log_processing_status($process_type, $status = 'pending', $items_processed = 0, $total_items = 0, $error_message = null) {
        $table = $this->table_prefix . 'processing_logs';
        
        $data = [
            'process_type' => sanitize_text_field($process_type),
            'status' => sanitize_text_field($status),
            'items_processed' => absint($items_processed),
            'total_items' => absint($total_items),
            'error_message' => $error_message ? sanitize_textarea_field($error_message) : null,
        ];
        
        // Update completion time if status is completed or failed
        if (in_array($status, ['completed', 'failed'])) {
            $data['completed_at'] = current_time('mysql');
        }
        
        $formats = ['%s', '%s', '%d', '%d', '%s', '%s'];
        
        $result = $this->wpdb->insert($table, $data, $formats);
        
        if ($result === false) {
            error_log('Failed to log processing status: ' . $this->wpdb->last_error);
            return false;
        }
        
        return $this->wpdb->insert_id;
    }
    
    /**
     * Update processing status
     * 
     * @param int $log_id Log entry ID
     * @param array $data Data to update
     * @return bool True on success, false on failure
     */
    public function update_processing_status($log_id, $data) {
        $table = $this->table_prefix . 'processing_logs';
        $log_id = absint($log_id);
        
        $allowed_fields = ['status', 'items_processed', 'total_items', 'error_message', 'completed_at'];
        $update_data = [];
        $formats = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                switch ($field) {
                    case 'status':
                        $update_data[$field] = sanitize_text_field($value);
                        $formats[] = '%s';
                        break;
                    case 'items_processed':
                    case 'total_items':
                        $update_data[$field] = absint($value);
                        $formats[] = '%d';
                        break;
                    case 'error_message':
                        $update_data[$field] = sanitize_textarea_field($value);
                        $formats[] = '%s';
                        break;
                    case 'completed_at':
                        $update_data[$field] = $value; // Should be MySQL datetime format
                        $formats[] = '%s';
                        break;
                }
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        $result = $this->wpdb->update(
            $table,
            $update_data,
            ['id' => $log_id],
            $formats,
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Store brand mapping securely
     * 
     * @param int $term_id WordPress term ID
     * @param string $api_source API source name
     * @param string $external_id External ID from API
     * @return bool True on success, false on failure
     */
    public function store_brand_mapping($term_id, $api_source, $external_id) {
        $table = $this->table_prefix . 'brand_mapping';
        
        $data = [
            'term_id' => absint($term_id),
            'api_source' => sanitize_text_field($api_source),
            'external_id' => sanitize_text_field($external_id),
            'last_synced' => current_time('mysql')
        ];
        
        $formats = ['%d', '%s', '%s', '%s'];
        
        // Use INSERT ON DUPLICATE KEY UPDATE equivalent
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM $table WHERE term_id = %d AND api_source = %s AND external_id = %s",
            $data['term_id'], $data['api_source'], $data['external_id']
        ));
        
        if ($existing) {
            $result = $this->wpdb->update(
                $table,
                ['last_synced' => $data['last_synced']],
                ['id' => $existing],
                ['%s'],
                ['%d']
            );
        } else {
            $result = $this->wpdb->insert($table, $data, $formats);
        }
        
        return $result !== false;
    }
    
    /**
     * Clean old log entries
     * 
     * @param int $days_to_keep Number of days to keep logs
     * @return bool True on success, false on failure
     */
    public function clean_old_logs($days_to_keep = 30) {
        $days_to_keep = absint($days_to_keep);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));
        
        $tables = [
            $this->table_prefix . 'api_logs',
            $this->table_prefix . 'processing_logs'
        ];
        
        $success = true;
        
        foreach ($tables as $table) {
            $result = $this->wpdb->delete(
                $table,
                ['created_at' => $cutoff_date],
                ['%s'],
                '<'
            );
            
            if ($result === false) {
                $success = false;
                error_log("Failed to clean old logs from table: $table");
            }
        }
        
        return $success;
    }
    
    /**
     * Sanitize query arguments
     * 
     * @param array $args Query arguments
     * @return array Sanitized arguments
     */
    private function sanitize_query_args($args) {
        $sanitized = [];
        
        foreach ($args as $key => $value) {
            switch ($key) {
                case 'post_type':
                case 'post_status':
                case 'orderby':
                case 'order':
                    $sanitized[$key] = sanitize_text_field($value);
                    break;
                case 'posts_per_page':
                case 'offset':
                    $sanitized[$key] = absint($value);
                    break;
                case 'meta_query':
                case 'tax_query':
                    $sanitized[$key] = $this->sanitize_meta_query($value);
                    break;
                default:
                    $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize meta query arrays
     * 
     * @param array $meta_query Meta query array
     * @return array Sanitized meta query
     */
    private function sanitize_meta_query($meta_query) {
        if (!is_array($meta_query)) {
            return [];
        }
        
        $sanitized = [];
        
        foreach ($meta_query as $key => $value) {
            if ($key === 'relation') {
                $sanitized[$key] = in_array(strtoupper($value), ['AND', 'OR']) ? strtoupper($value) : 'AND';
            } elseif (is_array($value)) {
                $sanitized_clause = [];
                if (isset($value['key'])) {
                    $sanitized_clause['key'] = sanitize_text_field($value['key']);
                }
                if (isset($value['value'])) {
                    $sanitized_clause['value'] = sanitize_text_field($value['value']);
                }
                if (isset($value['compare'])) {
                    $allowed_compares = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS'];
                    $sanitized_clause['compare'] = in_array($value['compare'], $allowed_compares) ? $value['compare'] : '=';
                }
                if (isset($value['type'])) {
                    $allowed_types = ['NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED'];
                    $sanitized_clause['type'] = in_array($value['type'], $allowed_types) ? $value['type'] : 'CHAR';
                }
                $sanitized[] = $sanitized_clause;
            }
        }
        
        return $sanitized;
    }
}