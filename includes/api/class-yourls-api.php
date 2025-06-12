<?php
/**
 * YOURLS API handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * YOURLS API class for URL shortening
 */
class Coupon_Automation_API_YOURLS {
    
    /**
     * API base URL (from original code)
     */
    const API_BASE_URL = 'https://www.adealsweden.com/r./yourls-api.php';
    
    /**
     * API credentials (from original code)
     */
    const API_USERNAME = 'borko';
    const API_PASSWORD = 'passwordnotthateasy';
    
    /**
     * Settings service
     * @var Coupon_Automation_Settings
     */
    private $settings;
    
    /**
     * Logger service
     * @var Coupon_Automation_Logger
     */
    private $logger;
    
    /**
     * API token
     * @var string
     */
    private $api_token;
    
    /**
     * Initialize API handler
     */
    public function init() {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        
        if ($this->settings) {
            $this->api_token = $this->settings->get_api_key('yourl_api_token');
        }
    }
    
    /**
     * Check if API is configured
     * 
     * @return bool True if configured, false otherwise
     */
    public function is_configured() {
        // YOURLS can work with username/password or API token
        return !empty($this->api_token) || (!empty(self::API_USERNAME) && !empty(self::API_PASSWORD));
    }
    
    /**
     * Create short URL
     * Preserves original functionality from short_affiliate_link()
     * 
     * @param string $long_url Long URL to shorten
     * @param string $keyword Custom keyword for the short URL
     * @return string|false Short URL or false on failure
     */
    public function create_short_url($long_url, $keyword = '') {
        if (!$this->is_configured()) {
            $this->logger->warning('YOURLS API not configured');
            return false;
        }
        
        // Sanitize keyword from advertiser name
        if (!empty($keyword)) {
            $keyword = sanitize_title($keyword);
            $this->logger->debug('Generated YOURLS keyword', [
                'original' => $keyword,
                'sanitized' => $keyword
            ]);
        }
        
        // Check if short URL already exists
        $existing_url = $this->fetch_existing_short_url($keyword);
        if ($existing_url) {
            $this->logger->debug('Existing short URL found', [
                'keyword' => $keyword,
                'url' => $existing_url
            ]);
            return $existing_url;
        }
        
        // Create new short URL
        return $this->create_new_short_url($long_url, $keyword);
    }
    
    /**
     * Create new short URL
     * 
     * @param string $long_url Long URL
     * @param string $keyword Keyword
     * @return string|false Short URL or false on failure
     */
    private function create_new_short_url($long_url, $keyword) {
        $start_time = microtime(true);
        
        $params = [
            'action' => 'shorturl',
            'format' => 'json',
            'url' => $long_url
        ];
        
        if (!empty($keyword)) {
            $params['keyword'] = $keyword;
        }
        
        // Add authentication
        if (!empty($this->api_token)) {
            $params['signature'] = $this->api_token;
        } else {
            $params['username'] = self::API_USERNAME;
            $params['password'] = self::API_PASSWORD;
        }
        
        $this->logger->debug('Creating new YOURLS short URL', [
            'url' => $long_url,
            'keyword' => $keyword
        ]);
        
        $response = $this->make_api_request($params);
        $response_time = microtime(true) - $start_time;
        
        if ($response && isset($response['shorturl'])) {
            $this->logger->info('Created new short URL', [
                'long_url' => $long_url,
                'short_url' => $response['shorturl'],
                'keyword' => $keyword,
                'response_time' => round($response_time, 3) . 's'
            ]);
            
            return $response['shorturl'];
        }
        
        $this->logger->warning('Failed to create short URL', [
            'long_url' => $long_url,
            'keyword' => $keyword,
            'response' => $response
        ]);
        
        return false;
    }
    
    /**
     * Fetch existing short URL
     * Preserves original functionality from fetch_existing_short_url()
     * 
     * @param string $keyword Keyword to check
     * @return string|false Existing short URL or false if not found
     */
    private function fetch_existing_short_url($keyword) {
        if (empty($keyword)) {
            return false;
        }
        
        $params = [
            'action' => 'shorturl',
            'keyword' => $keyword,
            'format' => 'json'
        ];
        
        // Add authentication
        if (!empty($this->api_token)) {
            $params['signature'] = $this->api_token;
        } else {
            $params['username'] = self::API_USERNAME;
            $params['password'] = self::API_PASSWORD;
        }
        
        $response = $this->make_api_request($params);
        
        if ($response && isset($response['shorturl'])) {
            return $response['shorturl'];
        }
        
        return false;
    }
    
    /**
     * Make API request to YOURLS
     * 
     * @param array $params Request parameters
     * @return array|false API response or false on failure
     */
    private function make_api_request($params) {
        $start_time = microtime(true);
        $url = self::API_BASE_URL;
        
        $args = [
            'method' => 'POST',
            'body' => http_build_query($params),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'Coupon-Automation/' . COUPON_AUTOMATION_VERSION
            ],
            'timeout' => $this->settings ? $this->settings->get('general.api_timeout', 30) : 30,
            'sslverify' => true
        ];
        
        // Use custom CA bundle if available (preserves original logic)
        $ca_bundle_path = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if (file_exists($ca_bundle_path)) {
            $args['sslcertificates'] = $ca_bundle_path;
        }
        
        $this->logger->debug('Making YOURLS API request', [
            'action' => $params['action'] ?? 'unknown',
            'keyword' => $params['keyword'] ?? '',
            'has_url' => !empty($params['url'])
        ]);
        
        $response = wp_remote_post($url, $args);
        $response_time = microtime(true) - $start_time;
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Log the API request (with sensitive data redacted)
        $redacted_params = $params;
        if (isset($redacted_params['password'])) {
            $redacted_params['password'] = '[REDACTED]';
        }
        if (isset($redacted_params['signature'])) {
            $redacted_params['signature'] = '[REDACTED]';
        }
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_api_request(
                'yourls',
                $url,
                'POST',
                $args['headers'],
                $redacted_params,
                null,
                $response_time,
                $error_message
            );
            
            $this->logger->error('YOURLS API request failed', [
                'error' => $error_message,
                'action' => $params['action'] ?? 'unknown'
            ]);
            
            return false;
        }
        
        $this->logger->log_api_request(
            'yourls',
            $url,
            'POST',
            $args['headers'],
            $redacted_params,
            $response_code,
            $response_time
        );
        
        // Handle non-200 response codes
        if ($response_code !== 200) {
            $this->logger->error('YOURLS API returned non-200 status', [
                'status_code' => $response_code,
                'response_message' => wp_remote_retrieve_response_message($response),
                'action' => $params['action'] ?? 'unknown'
            ]);
            
            return false;
        }
        
        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('YOURLS API returned invalid JSON', [
                'json_error' => json_last_error_msg(),
                'response_preview' => substr($body, 0, 200),
                'action' => $params['action'] ?? 'unknown'
            ]);
            return false;
        }
        
        $this->logger->debug('YOURLS API request successful', [
            'action' => $params['action'] ?? 'unknown',
            'response_time' => round($response_time, 3) . 's',
            'has_shorturl' => isset($data['shorturl'])
        ]);
        
        return $data;
    }
    
    /**
     * Get URL statistics
     * 
     * @param string $keyword Keyword or short URL
     * @return array|false URL statistics or false on failure
     */
    public function get_url_stats($keyword) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $params = [
            'action' => 'url-stats',
            'shorturl' => $keyword,
            'format' => 'json'
        ];
        
        // Add authentication
        if (!empty($this->api_token)) {
            $params['signature'] = $this->api_token;
        } else {
            $params['username'] = self::API_USERNAME;
            $params['password'] = self::API_PASSWORD;
        }
        
        $response = $this->make_api_request($params);
        
        if ($response && isset($response['link'])) {
            return [
                'url' => $response['link']['url'] ?? '',
                'shorturl' => $response['link']['shorturl'] ?? '',
                'title' => $response['link']['title'] ?? '',
                'clicks' => $response['link']['clicks'] ?? 0,
                'timestamp' => $response['link']['timestamp'] ?? ''
            ];
        }
        
        return false;
    }
    
    /**
     * Expand short URL
     * 
     * @param string $short_url Short URL to expand
     * @return string|false Long URL or false on failure
     */
    public function expand_url($short_url) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $params = [
            'action' => 'expand',
            'shorturl' => $short_url,
            'format' => 'json'
        ];
        
        // Add authentication
        if (!empty($this->api_token)) {
            $params['signature'] = $this->api_token;
        } else {
            $params['username'] = self::API_USERNAME;
            $params['password'] = self::API_PASSWORD;
        }
        
        $response = $this->make_api_request($params);
        
        if ($response && isset($response['longurl'])) {
            return $response['longurl'];
        }
        
        return false;
    }
    
    /**
     * Test API connection
     * 
     * @return array Test result
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return [
                'status' => 'error',
                'message' => 'API not configured (missing credentials)'
            ];
        }
        
        try {
            // Test with a simple stats request
            $params = [
                'action' => 'db-stats',
                'format' => 'json'
            ];
            
            // Add authentication
            if (!empty($this->api_token)) {
                $params['signature'] = $this->api_token;
            } else {
                $params['username'] = self::API_USERNAME;
                $params['password'] = self::API_PASSWORD;
            }
            
            $start_time = microtime(true);
            $response = $this->make_api_request($params);
            $response_time = microtime(true) - $start_time;
            
            if ($response && isset($response['db-stats'])) {
                return [
                    'status' => 'success',
                    'message' => 'Connection successful',
                    'response_time' => round($response_time, 3) . 's',
                    'stats' => $response['db-stats']
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Authentication failed or invalid response'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Test failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get database statistics
     * 
     * @return array|false Database stats or false on failure
     */
    public function get_database_stats() {
        if (!$this->is_configured()) {
            return false;
        }
        
        $params = [
            'action' => 'db-stats',
            'format' => 'json'
        ];
        
        // Add authentication
        if (!empty($this->api_token)) {
            $params['signature'] = $this->api_token;
        } else {
            $params['username'] = self::API_USERNAME;
            $params['password'] = self::API_PASSWORD;
        }
        
        $response = $this->make_api_request($params);
        
        if ($response && isset($response['db-stats'])) {
            return $response['db-stats'];
        }
        
        return false;
    }
    
    /**
     * Validate URL before shortening
     * 
     * @param string $url URL to validate
     * @return bool True if valid, false otherwise
     */
    public function validate_url($url) {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Check if URL is reachable (optional, can be resource intensive)
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Bulk create short URLs
     * 
     * @param array $urls Array of URLs with optional keywords
     * @return array Results array
     */
    public function bulk_create_short_urls($urls) {
        $results = [];
        
        foreach ($urls as $item) {
            $url = $item['url'] ?? '';
            $keyword = $item['keyword'] ?? '';
            
            if (empty($url)) {
                $results[] = [
                    'url' => $url,
                    'keyword' => $keyword,
                    'status' => 'error',
                    'message' => 'Empty URL'
                ];
                continue;
            }
            
            if (!$this->validate_url($url)) {
                $results[] = [
                    'url' => $url,
                    'keyword' => $keyword,
                    'status' => 'error',
                    'message' => 'Invalid URL'
                ];
                continue;
            }
            
            $short_url = $this->create_short_url($url, $keyword);
            
            if ($short_url) {
                $results[] = [
                    'url' => $url,
                    'keyword' => $keyword,
                    'short_url' => $short_url,
                    'status' => 'success'
                ];
            } else {
                $results[] = [
                    'url' => $url,
                    'keyword' => $keyword,
                    'status' => 'error',
                    'message' => 'Failed to create short URL'
                ];
            }
            
            // Add small delay to avoid rate limiting
            usleep(100000); // 0.1 second
        }
        
        return $results;
    }
    
    /**
     * Get usage statistics for the plugin
     * 
     * @return array Usage statistics
     */
    public function get_usage_statistics() {
        return [
            'requests_today' => get_transient('yourls_requests_today') ?: 0,
            'urls_created_today' => get_transient('yourls_urls_today') ?: 0,
            'last_request' => get_option('yourls_last_request_time'),
            'total_urls_created' => get_option('yourls_total_urls_created', 0)
        ];
    }
    
    /**
     * Update usage statistics
     * 
     * @param bool $url_created Whether a URL was created
     */
    private function update_usage_statistics($url_created = false) {
        $today = date('Y-m-d');
        $requests_key = 'yourls_requests_' . $today;
        $urls_key = 'yourls_urls_' . $today;
        
        // Increment daily counters
        $current_requests = get_transient($requests_key) ?: 0;
        set_transient($requests_key, $current_requests + 1, DAY_IN_SECONDS);
        set_transient('yourls_requests_today', $current_requests + 1, DAY_IN_SECONDS);
        
        if ($url_created) {
            $current_urls = get_transient($urls_key) ?: 0;
            set_transient($urls_key, $current_urls + 1, DAY_IN_SECONDS);
            set_transient('yourls_urls_today', $current_urls + 1, DAY_IN_SECONDS);
            
            // Update total counter
            $total_urls = get_option('yourls_total_urls_created', 0);
            update_option('yourls_total_urls_created', $total_urls + 1);
        }
        
        // Update last request time
        update_option('yourls_last_request_time', current_time('mysql'));
    }
    
    /**
     * Clean up old short URLs (if needed)
     * 
     * @param int $days_old Delete URLs older than this many days
     * @return int Number of URLs deleted
     */
    public function cleanup_old_urls($days_old = 365) {
        // This would require additional YOURLS API endpoints
        // For now, just return 0 as cleanup isn't implemented
        $this->logger->info('YOURLS cleanup requested but not implemented', [
            'days_old' => $days_old
        ]);
        
        return 0;
    }
    
    /**
     * Get API rate limits
     * 
     * @return array Rate limit information
     */
    public function get_rate_limit_info() {
        return [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000,
            'concurrent_requests' => 3,
            'recommended_delay' => 0.1 // seconds between requests
        ];
    }
    
    /**
     * Check if keyword is available
     * 
     * @param string $keyword Keyword to check
     * @return bool True if available, false if taken
     */
    public function is_keyword_available($keyword) {
        $existing_url = $this->fetch_existing_short_url($keyword);
        return $existing_url === false;
    }
    
    /**
     * Generate safe keyword from text
     * 
     * @param string $text Text to convert to keyword
     * @param int $max_length Maximum keyword length
     * @return string Safe keyword
     */
    public function generate_keyword($text, $max_length = 50) {
        // Sanitize and limit length
        $keyword = sanitize_title($text);
        $keyword = substr($keyword, 0, $max_length);
        
        // Remove common words that might cause conflicts
        $stop_words = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];
        $words = explode('-', $keyword);
        $words = array_diff($words, $stop_words);
        $keyword = implode('-', $words);
        
        // Ensure it's not empty
        if (empty($keyword)) {
            $keyword = 'coupon-' . time();
        }
        
        return $keyword;
    }
    
    /**
     * Get API status information
     * 
     * @return array API status
     */
    public function get_api_status() {
        return [
            'name' => 'YOURLS',
            'configured' => $this->is_configured(),
            'base_url' => self::API_BASE_URL,
            'authentication' => !empty($this->api_token) ? 'API Token' : 'Username/Password',
            'rate_limits' => $this->get_rate_limit_info(),
            'usage_stats' => $this->get_usage_statistics()
        ];
    }
    
    /**
     * Clear YOURLS-specific cache
     */
    public function clear_cache() {
        // Clear any cached YOURLS data
        delete_transient('yourls_requests_today');
        delete_transient('yourls_urls_today');
        
        $this->logger->debug('YOURLS cache cleared');
    }
}