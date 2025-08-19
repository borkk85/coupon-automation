<?php

/**
 * YOURLS API implementation
 */
class YourlsAPI extends BaseAPI {
    
    private $username;
    private $password;
    
    protected function loadCredentials() {
        $this->username = get_option('yourls_username');
        $this->password = get_option('yourls_password');
        $this->apiKey = get_option('yourls_api_token');
        $this->baseUrl = 'https://www.adealsweden.com/r./yourls-api.php';
    }
    
    public function testConnection() {
        // Simple test with stats endpoint
        $response = $this->makeYourlsRequest([
            'action' => 'stats',
            'format' => 'json'
        ]);
        
        return $response !== false;
    }
    
    public function createShortUrl($longUrl, $keyword = '') {
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
    
    private function makeYourlsRequest($params) {
        $params['username'] = $this->username;
        $params['password'] = $this->password;
        
        $response = wp_remote_post($this->baseUrl, [
            'body' => $params,
            'timeout' => $this->timeout
        ]);
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            return $this->parseResponse($body);
        }
        
        return false;
    }
}