<?php

namespace CouponAutomation\API;

use CouponAutomation\Utils\Logger;

/**
 * Base API class for all external API integrations
 */
abstract class BaseAPI {
    
    protected $apiKey;
    protected $baseUrl;
    protected $timeout = 30;
    protected $retryAttempts = 3;
    protected $logger;
    
    public function __construct() {
        $this->logger = new Logger();
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
     * Make API request with retry logic
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
            
            if ($body !== null) {
                $args['body'] = is_array($body) ? json_encode($body) : $body;
            }
            
            $response = wp_remote_request($url, $args);
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                
                if ($status_code >= 200 && $status_code < 300) {
                    return $this->parseResponse($body);
                }
                
                // Log error but continue retrying
                $this->logger->error(sprintf(
                    'API request failed: %s - Status: %d - Attempt: %d',
                    $url,
                    $status_code,
                    $attempt
                ));
            } else {
                $this->logger->error(sprintf(
                    'API request error: %s - %s - Attempt: %d',
                    $url,
                    $response->get_error_message(),
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
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }
    
    /**
     * Parse API response
     */
    protected function parseResponse($body) {
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