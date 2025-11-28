<?php

namespace CouponAutomation\API;

use CouponAutomation\Utils\Logger;

/**
 * Base API class for all external API integrations - FIXED VERSION
 */
abstract class BaseAPI {
    
    protected $apiKey;
    protected $baseUrl;
    protected $timeout = 45;
    protected $retryAttempts = 3;
    protected $logger;
    
    public function __construct() {
        $this->logger = new Logger();
        $configuredTimeout = (int) get_option('coupon_automation_api_timeout', 45);
        if ($configuredTimeout > 0) {
            $this->timeout = max(15, min(180, $configuredTimeout));
        }
        $this->loadCredentials();
    }
    
    /**
     * Load API credentials from options
     */
    abstract protected function loadCredentials();
    
    /**
     * Test API connection
     */
    abstract public function testConnection();
    
    /**
     * Make API request with retry logic - FIXED
     */
    protected function makeRequest($endpoint, $method = 'GET', $body = null, $headers = []) {
        $url = $this->baseUrl . $endpoint;
        $attempt = 0;

        while ($attempt < $this->retryAttempts) {
            $attempt++;

            $args = [
                'method' => $method,
                'timeout' => $this->timeout,
                'headers' => array_merge($this->getDefaultHeaders(), $headers),
            ];

            // FIX: Only add body for non-GET requests or when body is an array
            if ($body !== null && $method !== 'GET') {
                $args['body'] = is_array($body) ? json_encode($body) : $body;
            }

            // For GET requests with parameters, append to URL
            if ($method === 'GET' && is_array($body)) {
                $url = add_query_arg($body, $url);
            }

            // Log request details
            error_log(sprintf('[CA] API Request - Method: %s, URL: %s, Attempt: %d/%d',
                $method, $url, $attempt, $this->retryAttempts));

            $response = wp_remote_request($url, $args);
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                // Log response status
                error_log(sprintf('[CA] API Response - Status: %d, Body length: %d bytes',
                    $status_code, strlen($body)));

                if ($status_code >= 200 && $status_code < 300) {
                    return $this->parseResponse($body);
                }

                // Log error responses with full body
                if ($status_code >= 400) {
                    error_log(sprintf('[CA] API Error Response - Status: %d, Body: %s',
                        $status_code, substr($body, 0, 500)));

                    // Try to parse error message from response like working example
                    $error_data = json_decode($body, true);
                    $error_message = 'Unknown error';
                    if (isset($error_data['error']['message'])) {
                        $error_message = $error_data['error']['message'];
                        error_log('[CA] Parsed API Error Message: ' . $error_message);
                    }

                    $this->logger->error(sprintf(
                        'API request failed: %s - Status: %d - Error: %s - Response: %s - Attempt: %d',
                        $url,
                        $status_code,
                        $error_message,
                        $body,
                        $attempt
                    ));
                } else {
                    $this->logger->error(sprintf(
                        'API request failed: %s - Status: %d - Attempt: %d',
                        $url,
                        $status_code,
                        $attempt
                    ));
                }
                
                if ($status_code === 429) {
                    $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');
                    if ($retryAfter <= 0) {
                        $retryAfter = pow(2, $attempt) * 30;
                    }
                    $retryAfter = min($retryAfter, 120);
                    $this->logger->warning(sprintf('Rate limited on %s. Waiting %d seconds before retry.', $url, $retryAfter));
                    sleep($retryAfter);
                    continue;
                }
                
                // Don't retry on client errors (4xx)
                if ($status_code >= 400 && $status_code < 500) {
                    return false;
                }
            } else {
                $error_message = $response->get_error_message();
                error_log(sprintf('[CA] API WP_Error - URL: %s, Error: %s, Attempt: %d',
                    $url, $error_message, $attempt));

                $this->logger->error(sprintf(
                    'API request error: %s - %s - Attempt: %d',
                    $url,
                    $error_message,
                    $attempt
                ));
            }
            
            // Wait before retry (exponential backoff)
            if ($attempt < $this->retryAttempts) {
                sleep(pow(2, $attempt));
            }
        }
        
        return false;
    }
    
    /**
     * Get default headers for API requests
     */
    protected function getDefaultHeaders() {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        // Only add Authorization header if API key exists
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        
        return $headers;
    }
    
    /**
     * Parse API response
     */
    protected function parseResponse($body) {
        if (empty($body)) {
            return false;
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON parsing error: ' . json_last_error_msg());
            return false;
        }
        
        return $data;
    }
    
    /**
     * Cache API response
     */
    protected function cacheResponse($key, $data, $expiration = HOUR_IN_SECONDS) {
        set_transient($key, $data, $expiration);
    }
    
    /**
     * Get cached response
     */
    protected function getCachedResponse($key) {
        return get_transient($key);
    }
}
