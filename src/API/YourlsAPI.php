<?php

namespace CouponAutomation\API;

/**
 * YOURLS API implementation - FIXED VERSION
 */
class YourlsAPI extends BaseAPI {
    
    private $username;
    private $password;
    
    protected function loadCredentials() {
        $this->username = get_option('yourls_username');
        $this->password = get_option('yourls_password');
        $this->apiKey = get_option('yourls_api_token');
        $this->baseUrl = 'https://dev.adealsweden.com/y./yourls-api.php';
    }
    
    /**
     * Override getDefaultHeaders for YOURLS (doesn't use Bearer auth)
     */
    protected function getDefaultHeaders() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
    }
    
    public function testConnection() {
        if (empty($this->username) || empty($this->password)) {
            return false;
        }
        
        // Simple test with stats endpoint
        $response = $this->makeYourlsRequest([
            'action' => 'stats',
            'format' => 'json'
        ]);
        
        return $response !== false;
    }
    
    public function createShortUrl($longUrl, $keyword = '') {
        if (empty($this->username) || empty($this->password)) {
            $this->logger->error('YOURLS credentials not configured');
            return false;
        }
        
        $params = [
            'action' => 'shorturl',
            'url' => $longUrl,
            'format' => 'json'
        ];
        
        if (!empty($keyword)) {
            $params['keyword'] = sanitize_title($keyword);
        }
        
        $response = $this->makeYourlsRequest($params);
        
        if ($response && isset($response['shorturl'])) {
            return $response['shorturl'];
        }
        
        return false;
    }
    
    /**
     * Make YOURLS-specific request (uses form data, not JSON)
     */
    private function makeYourlsRequest($params) {
        // Add authentication
        $params['username'] = $this->username;
        $params['password'] = $this->password;
        
        $response = wp_remote_post($this->baseUrl, [
            'body' => $params, // Pass as array, WordPress will handle encoding
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->error('YOURLS API error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('YOURLS response parsing error: ' . json_last_error_msg());
            return false;
        }
        
        return $data;
    }
}