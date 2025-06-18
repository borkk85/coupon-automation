<?php

/**
 * AWIN API handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AWIN API class
 */
class Coupon_Automation_API_AWIN
{

    /**
     * API base URL
     */
    const API_BASE_URL = 'https://api.awin.com/';

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
     * Publisher ID
     * @var string
     */
    private $publisher_id;

    /**
     * Initialize API handler
     */
    public function init()
    {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');

        if ($this->settings) {
            $this->api_token = $this->settings->get_api_key('awin_api_token');
            $this->publisher_id = $this->settings->get_api_key('awin_publisher_id');
        }
    }

    /**
     * Check if API is configured
     * 
     * @return bool True if configured, false otherwise
     */
    public function is_configured()
    {
        return !empty($this->api_token) && !empty($this->publisher_id);
    }

    /**
     * Fetch promotions from AWIN API
     * Preserves original functionality from fetch_awin_promotions()
     * 
     * @return array Promotions data
     */
    public function fetch_promotions()
    {
        if (!$this->is_configured()) {
            $this->logger->warning('AWIN API not configured');
            return [];
        }

        $url = self::API_BASE_URL . "publisher/{$this->publisher_id}/promotions/";

        $request_body = [
            'filters' => [
                'exclusiveOnly' => false,
                'membership' => 'joined',
                'regionCodes' => ['SE'],
                'status' => 'active',
                'type' => 'all',
                'updatedSince' => '2000-01-01'
            ],
            'pagination' => [
                'page' => 1,
                'pageSize' => 150
            ]
        ];

        return $this->make_api_request($url, 'POST', 'promotions', $request_body);
    }

    /**
     * Fetch programme details for a specific advertiser
     * Preserves original functionality from fetch_awin_programme_details()
     * 
     * @param int $advertiser_id Advertiser ID
     * @return array|null Programme data or null on failure
     */
    public function fetch_programme_details($advertiser_id)
    {
        if (!$this->is_configured()) {
            $this->logger->warning('AWIN API not configured');
            return null;
        }

        $advertiser_id = absint($advertiser_id);
        $url = self::API_BASE_URL . "publishers/{$this->publisher_id}/programmedetails?advertiserId={$advertiser_id}";

        $response_data = $this->make_api_request($url, 'GET', 'programme_details');

        if (isset($response_data['programmeInfo'])) {
            return $response_data['programmeInfo'];
        }

        $this->logger->warning('AWIN programme details response missing programmeInfo', [
            'advertiser_id' => $advertiser_id,
            'response_keys' => array_keys($response_data)
        ]);

        return null;
    }

    /**
     * Make API request to AWIN
     * 
     * @param string $url Request URL
     * @param string $method HTTP method
     * @param string $endpoint_name Endpoint name for logging
     * @param array $body Request body (for POST requests)
     * @return array API response data
     */
    private function make_api_request($url, $method = 'GET', $endpoint_name = '', $body = null)
    {
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

        $this->logger->debug('Making AWIN API request', [
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
                'awin',
                $url,
                $method,
                $headers,
                $body,
                null,
                $response_time,
                $error_message
            );

            $this->logger->error('AWIN API request failed', [
                'url' => $url,
                'error' => $error_message,
                'endpoint' => $endpoint_name
            ]);

            return [];
        }

        $this->logger->log_api_request(
            'awin',
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
            $this->logger->error('AWIN API returned non-200 status', [
                'url' => $url,
                'status_code' => $response_code,
                'response_message' => wp_remote_retrieve_response_message($response),
                'endpoint' => $endpoint_name
            ]);

            return [];
        }

        // Parse response
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('AWIN API returned invalid JSON', [
                'url' => $url,
                'json_error' => json_last_error_msg(),
                'endpoint' => $endpoint_name,
                'response_preview' => substr($response_body, 0, 1000)
            ]);
            return [];
        }

        // For promotions endpoint, return the data array
        if ($endpoint_name === 'promotions' && isset($data['data'])) {
            $results_count = is_array($data['data']) ? count($data['data']) : 0;
            $this->logger->info("AWIN API request successful", [
                'endpoint' => $endpoint_name,
                'results_count' => $results_count,
                'response_time' => round($response_time, 3) . 's'
            ]);

            return $data['data'];
        }

        // For other endpoints, return the full response
        $this->logger->info("AWIN API request successful", [
            'endpoint' => $endpoint_name,
            'response_time' => round($response_time, 3) . 's'
        ]);

        return $data;
    }

    /**
     * Test API connection
     * 
     * @return array Test result
     */
    public function test_connection()
    {
        if (!$this->is_configured()) {
            return [
                'status' => 'error',
                'message' => 'API token or Publisher ID not configured'
            ];
        }

        try {
            // Match your Postman headers exactly
            $url = self::API_BASE_URL . "publisher/{$this->publisher_id}/promotions/";

            $start_time = microtime(true);

            $response = wp_remote_post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection' => 'keep-alive'
                ],
                'body' => '', // Explicitly set empty body with Content-Length: 0
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
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    // Check if response has expected structure
                    if (isset($data['data'])) {
                        return [
                            'status' => 'success',
                            'message' => 'Connection successful (found ' . count($data['data']) . ' promotions)',
                            'response_time' => round($response_time, 3) . 's'
                        ];
                    } else {
                        return [
                            'status' => 'success',
                            'message' => 'Connection successful (response keys: ' . implode(', ', array_keys($data)) . ')',
                            'response_time' => round($response_time, 3) . 's'
                        ];
                    }
                } else {
                    return [
                        'status' => 'warning',
                        'message' => 'Connected but invalid JSON response'
                    ];
                }
            } elseif ($response_code === 401) {
                return [
                    'status' => 'error',
                    'message' => 'Authentication failed - check API token'
                ];
            } elseif ($response_code === 403) {
                return [
                    'status' => 'error',
                    'message' => 'Access forbidden - check Publisher ID and permissions'
                ];
            } elseif ($response_code === 405) {
                return [
                    'status' => 'error',
                    'message' => 'Method not allowed - API endpoint expects different HTTP method'
                ];
            } elseif ($response_code === 500) {
                return [
                    'status' => 'error',
                    'message' => 'Server error - AWIN API is having issues'
                ];
            } else {
                $body = wp_remote_retrieve_body($response);
                return [
                    'status' => 'error',
                    'message' => "HTTP {$response_code}: " . wp_remote_retrieve_response_message($response) . " - " . substr($body, 0, 100)
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
    public function get_rate_limit_info()
    {
        return [
            'requests_per_second' => 10,
            'requests_per_minute' => 600,
            'requests_per_hour' => 10000,
            'concurrent_requests' => 3,
            'recommended_delay' => 0.1 // seconds between requests
        ];
    }

    /**
     * Validate promotions data structure
     * 
     * @param array $data Promotions data
     * @return bool True if valid, false otherwise
     */
    public function validate_promotions_data($data)
    {
        if (!is_array($data)) {
            return false;
        }

        foreach ($data as $promotion) {
            // Check required fields
            $required_fields = ['promotionId', 'advertiser'];

            foreach ($required_fields as $field) {
                if (!isset($promotion[$field])) {
                    return false;
                }
            }

            // Check advertiser structure
            if (!isset($promotion['advertiser']['id']) || !isset($promotion['advertiser']['name'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate programme data structure
     * 
     * @param array $data Programme data
     * @return bool True if valid, false otherwise
     */
    public function validate_programme_data($data)
    {
        if (!is_array($data)) {
            return false;
        }

        $required_fields = ['id', 'name'];

        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get promotion types count from cached data
     * 
     * @return array Promotion types count
     */
    public function get_promotion_types_count()
    {
        $promotions = get_transient('awin_promotions_data');

        if (!$promotions) {
            return [];
        }

        $types = array_column($promotions, 'type');
        return array_count_values($types);
    }

    /**
     * Get API status information
     * 
     * @return array API status
     */
    public function get_api_status()
    {
        return [
            'name' => 'AWIN',
            'configured' => $this->is_configured(),
            'base_url' => self::API_BASE_URL,
            'publisher_id' => $this->publisher_id,
            'supported_endpoints' => ['promotions', 'programme_details'],
            'rate_limits' => $this->get_rate_limit_info()
        ];
    }

    /**
     * Clear AWIN-specific cache
     */
    public function clear_cache()
    {
        delete_transient('awin_promotions_data');
        $this->logger->debug('AWIN cache cleared');
    }

    /**
     * Get cached promotions data
     * 
     * @return array|false Cached promotions or false if not cached
     */
    public function get_cached_promotions()
    {
        return get_transient('awin_promotions_data');
    }

    /**
     * Cache promotions data
     * 
     * @param array $promotions Promotions data
     * @param int $expiration Cache expiration in seconds
     */
    public function cache_promotions($promotions, $expiration = HOUR_IN_SECONDS)
    {
        set_transient('awin_promotions_data', $promotions, $expiration);
        $this->logger->debug('AWIN promotions cached', ['count' => count($promotions)]);
    }
}
