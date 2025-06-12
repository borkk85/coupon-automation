<?php
/**
 * AddRevenue API handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AddRevenue API class
 */
class Coupon_Automation_API_AddRevenue {
    
    /**
     * API base URL
     */
    const API_BASE_URL = 'https://addrevenue.io/api/v2/';
    
    /**
     * Channel ID
     */
    const CHANNEL_ID = '3454851';
    
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
            $this->api_token = $this->settings->get_api_key('addrevenue_api_token');
        }
    }
    
    /**
     * Check if API is configured
     * 
     * @return bool True if configured, false otherwise
     */
    public function is_configured() {
        return !empty($this->api_token);
    }
    
    /**
     * Fetch advertisers from AddRevenue API
     * Preserves original functionality from fetch_addrevenue_data()
     * 
     * @return array Advertisers data
     */
    public function fetch_advertisers() {
        if (!$this->is_configured()) {
            $this->logger->warning('AddRevenue API not configured');
            return [];
        }
        
        $url = self::API_BASE_URL . 'advertisers?channelId=' . self::CHANNEL_ID;
        
        return $this->make_api_request($url, 'GET', 'advertisers');
    }
    
    /**
     * Fetch campaigns from AddRevenue API
     * Preserves original functionality from fetch_addrevenue_data()
     * 
     * @return array Campaigns data
     */
    public function fetch_campaigns() {
        if (!$this->is_configured()) {
            $this->logger->warning('AddRevenue API not configured');
            return [];
        }
        
        $url = self::API_BASE_URL . 'campaigns?channelId=' . self::CHANNEL_ID;
        
        return $this->make_api_request($url, 'GET', 'campaigns');
    }
    
    /**
     * Make API request to AddRevenue
     * 
     * @param string $url Request URL
     * @param string $method HTTP method
     * @param string $endpoint_name Endpoint name for logging
     * @param array $body Request body (for POST requests)
     * @return array API response data
     */
    private function make_api_request($url, $method = 'GET', $endpoint_name = '', $body = null) {
        $start_time = microtime(true);
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'Coupon-Automation/' . COUPON_AUTOMATION_VERSION
        ];
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $this->settings ? $this->settings->get('general.api_timeout', 30) : 30,
            'sslverify' => true,
        ];
        
        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = is_array($body) ? json_encode($body) : $body;
        }
        
        $this->logger->debug('Making AddRevenue API request', [
            'url' => $url,
            'method' => $method,
            'endpoint' => $endpoint_name
        ]);
        
        $response = wp_remote_request($url, $args);
        $response_time = microtime(true) - $start_time;
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Log the API request
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_api_request(
                'addrevenue',
                $url,
                $method,
                $headers,
                $body,
                null,
                $response_time,
                $error_message
            );
            
            $this->logger->error('AddRevenue API request failed', [
                'url' => $url,
                'error' => $error_message,
                'endpoint' => $endpoint_name
            ]);
            
            return [];
        }
        
        $this->logger->log_api_request(
            'addrevenue',
            $url,
            $method,
            $headers,
            $body,
            $response_code,
            $response_time
        );
        
        // Handle non-200 response codes
        if ($response_code !== 200) {
            $error_message = "HTTP {$response_code}: " . wp_remote_retrieve_response_message($response);
            $this->logger->error('AddRevenue API returned non-200 status', [
                'url' => $url,
                'status_code' => $response_code,
                'response_message' => wp_remote_retrieve_response_message($response),
                'endpoint' => $endpoint_name
            ]);
            
            return [];
        }
        
        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('AddRevenue API returned invalid JSON', [
                'url' => $url,
                'json_error' => json_last_error_msg(),
                'endpoint' => $endpoint_name
            ]);
            return [];
        }
        
        // Validate response structure (preserves original logic)
        if (!isset($data['results'])) {
            $this->logger->error('AddRevenue API response missing results', [
                'url' => $url,
                'response_keys' => array_keys($data),
                'endpoint' => $endpoint_name
            ]);
            return [];
        }
        
        $results_count = is_array($data['results']) ? count($data['results']) : 0;
        $this->logger->info("AddRevenue API request successful", [
            'endpoint' => $endpoint_name,
            'results_count' => $results_count,
            'response_time' => round($response_time, 3) . 's'
        ]);
        
        return $data['results'];
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
                'message' => 'API token not configured'
            ];
        }
        
        try {
            // Make a simple request to test the connection
            $url = self::API_BASE_URL . 'advertisers?channelId=' . self::CHANNEL_ID . '&limit=1';
            $start_time = microtime(true);
            
            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]);
            
            $response_time = microtime(true) - $start_time;
            $response_code = wp_remote_retrieve_response_code($response);
            
            if (is_wp_error($response)) {
                return [
                    'status' => 'error',
                    'message' => 'Connection failed: ' . $response->get_error_message()
                ];
            }
            
            if ($response_code === 200) {
                return [
                    'status' => 'success',
                    'message' => 'Connection successful',
                    'response_time' => round($response_time, 3) . 's'
                ];
            } elseif ($response_code === 401) {
                return [
                    'status' => 'error',
                    'message' => 'Authentication failed - check API token'
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => "HTTP {$response_code}: " . wp_remote_retrieve_response_message($response)
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
     * Get API rate limit information
     * 
     * @return array Rate limit info
     */
    public function get_rate_limit_info() {
        // AddRevenue doesn't provide specific rate limit headers
        // Return general best practices
        return [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000,
            'concurrent_requests' => 5,
            'recommended_delay' => 1 // seconds between requests
        ];
    }
    
    /**
     * Validate API response data structure
     * 
     * @param array $data Response data
     * @param string $type Data type (advertisers|campaigns)
     * @return bool True if valid, false otherwise
     */
    public function validate_response_data($data, $type) {
        if (!is_array($data)) {
            return false;
        }
        
        switch ($type) {
            case 'advertisers':
                return $this->validate_advertisers_data($data);
            
            case 'campaigns':
                return $this->validate_campaigns_data($data);
            
            default:
                return false;
        }
    }
    
    /**
     * Validate advertisers data structure
     * 
     * @param array $data Advertisers data
     * @return bool True if valid, false otherwise
     */
    private function validate_advertisers_data($data) {
        foreach ($data as $advertiser) {
            if (!isset($advertiser['id']) || !isset($advertiser['name'])) {
                return false;
            }
            
            // Check for SE market (required by original logic)
            if (!isset($advertiser['markets']['SE'])) {
                continue; // This is acceptable, we just skip non-SE advertisers
            }
            
            if (!isset($advertiser['markets']['SE']['displayName'])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate campaigns data structure
     * 
     * @param array $data Campaigns data
     * @return bool True if valid, false otherwise
     */
    private function validate_campaigns_data($data) {
        foreach ($data as $campaign) {
            $required_fields = ['id', 'advertiserName'];
            
            foreach ($required_fields as $field) {
                if (!isset($campaign[$field])) {
                    return false;
                }
            }
            
            // Check for SE market (required by original logic)
            if (!isset($campaign['markets']['SE'])) {
                continue; // This is acceptable, we just skip non-SE campaigns
            }
        }
        
        return true;
    }
    
    /**
     * Get API status information
     * 
     * @return array API status
     */
    public function get_api_status() {
        return [
            'name' => 'AddRevenue',
            'configured' => $this->is_configured(),
            'base_url' => self::API_BASE_URL,
            'channel_id' => self::CHANNEL_ID,
            'supported_endpoints' => ['advertisers', 'campaigns'],
            'rate_limits' => $this->get_rate_limit_info()
        ];
    }
}