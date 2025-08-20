<?php

namespace CouponAutomation\API;

/**
 * OpenAI API implementation - FIXED VERSION
 */
class OpenAIAPI extends BaseAPI {
    
    protected function loadCredentials() {
        $this->apiKey = get_option('openai_api_key');
        $this->baseUrl = 'https://api.openai.com/v1/';
    }
    
    /**
     * Get default headers for OpenAI API
     */
    protected function getDefaultHeaders() {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        
        return $headers;
    }
    
    public function testConnection() {
        // Don't pass null as body for GET request
        $result = $this->makeRequest('models', 'GET');
        return $result !== false && isset($result['data']);
    }
    
    public function generateContent($prompt, $maxTokens = 150, $temperature = 0.7) {
        if (empty($this->apiKey)) {
            $this->logger->error('OpenAI API key not configured');
            return false;
        }
        
        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature
        ];
        
        $response = $this->makeRequest('chat/completions', 'POST', $body);
        
        if ($response && isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }
        
        return false;
    }
}