<?php

namespace CouponAutomation\API;

/**
 * OpenAI API implementation
 */
class OpenAIAPI extends BaseAPI {
    
    protected function loadCredentials() {
        $this->apiKey = get_option('ai_api_key');
        $this->baseUrl = 'https://api.openai.com/v1/';

    }
    
    protected function getDefaultHeaders() {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }
    
    public function testConnection() {
        $result = $this->makeRequest('models', 'GET', null, $this->getDefaultHeaders());
        return $result !== false;
    }
    
    public function generateContent($prompt, $maxTokens = 150, $temperature = 0.7) {
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