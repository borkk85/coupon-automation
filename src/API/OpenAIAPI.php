<?php

namespace CouponAutomation\API;

/**
 * OpenAI API implementation
 */
class OpenAIAPI extends BaseAPI {
    
    protected function loadCredentials() {
        $this->apiKey = get_option('openai_api_key');
        $this->baseUrl = 'https://api.openai.com/v1/';

        if (empty($this->apiKey)) {
            error_log('[CA] OpenAI API key is not configured in plugin settings');
        } else {
            error_log('[CA] OpenAI API key loaded: ' . substr($this->apiKey, 0, 7) . '...');
        }
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

    /**
     * Check if model requires max_completion_tokens instead of max_tokens
     * GPT-5 and GPT-4.1 series use max_completion_tokens
     */
    private function usesMaxCompletionTokens($model) {
        $newer_models = ['gpt-5', 'gpt-4.1', 'o1', 'o3'];
        foreach ($newer_models as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if model allows temperature override
     * GPT-5 and GPT-4.1 series have locked temperature
     */
    private function allowsTemperature($model) {
        $locked_temperature_models = ['gpt-5', 'gpt-4.1', 'o1', 'o3'];
        foreach ($locked_temperature_models as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return false;
            }
        }
        return true;
    }

    public function generateContent($prompt, $maxTokens = 150, $temperature = 0.7) {
        // Get the selected model from settings (default to gpt-4o-mini)
        $model = get_option('openai_model', 'gpt-4o-mini');
        $fallbackModel = get_option('openai_fallback_model', 'gpt-4o-mini');

        // Clamp tokens; for GPT-5 family enforce a higher floor to avoid empty/length finishes
        if ($this->usesMaxCompletionTokens($model)) {
            $safeMaxTokens = max(512, min((int) $maxTokens, 8000));
        } else {
            $safeMaxTokens = max(1, min((int) $maxTokens, 8000));
        }
        $safeTemperature = max(0.0, min((float) $temperature, 2.0));

        // Use chat/completions for all; choose param name per family
        $endpoint = 'chat/completions';
        $tokenParam = $this->usesMaxCompletionTokens($model) ? 'max_completion_tokens' : 'max_tokens';

        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful assistant that generates concise coupon titles and descriptions.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            $tokenParam => $safeMaxTokens,
            'response_format' => ['type' => 'text'],
        ];

        if ($this->allowsTemperature($model)) {
            $body['temperature'] = $safeTemperature;
        }

        error_log(sprintf('[CA] OpenAI request - Model: %s, Endpoint: %s, Tokens: %d, Has temp: %s',
            $model, $endpoint, $safeMaxTokens, isset($body['temperature']) ? 'yes' : 'no'));
        error_log('[CA] Request payload: ' . json_encode($body));

        $response = $this->makeRequest($endpoint, 'POST', $body);

        if (!$response) {
            error_log('[CA] OpenAI request returned false - check database logs for API error');
            // Retry once with fallback model
            if ($model !== $fallbackModel) {
                return $this->retryWithFallback($prompt, $fallbackModel, $safeMaxTokens, $safeTemperature);
            }
            return false;
        }

        // Log full response (increased from 200 to 2000 chars)
        error_log('[CA] OpenAI full response: ' . substr(json_encode($response), 0, 2000));

        // Log finish_reason if available
        if (isset($response['choices'][0]['finish_reason'])) {
            error_log('[CA] OpenAI finish_reason: ' . $response['choices'][0]['finish_reason']);
        }

        $content = $this->extractContent($response);

        if ($content === false) {
            // Retry once with fallback model if not already using it
            if ($model !== $fallbackModel) {
                error_log(sprintf('[CA] Retrying OpenAI request with fallback model: %s', $fallbackModel));
                return $this->retryWithFallback($prompt, $fallbackModel, $safeMaxTokens, $safeTemperature);
            }
            return false;
        }

        return $content;
    }

    /**
     * Retry with fallback model
     */
    private function retryWithFallback($prompt, $model, $tokens, $temperature) {
        $endpoint = 'chat/completions';
        $tokenParam = $this->usesMaxCompletionTokens($model) ? 'max_completion_tokens' : 'max_tokens';
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful assistant that generates concise coupon titles and descriptions.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            $tokenParam => $tokens,
            'response_format' => ['type' => 'text'],
        ];
        if ($this->allowsTemperature($model)) {
            $body['temperature'] = $temperature;
        }

        error_log(sprintf('[CA] OpenAI fallback request - Model: %s, Tokens: %d, Endpoint: %s', $model, $tokens, $endpoint));
        $response = $this->makeRequest($endpoint, 'POST', $body);

        if (!$response) {
            error_log('[CA] Fallback OpenAI request returned false');
            return false;
        }

        return $this->extractContent($response);
    }

    /**
     * Extract content from OpenAI response
     */
    private function extractContent($response) {
        // Chat completions shape
        if (isset($response['choices'][0]['message']['content'])) {
            $content = trim($response['choices'][0]['message']['content']);

            if (empty($content)) {
                error_log('[CA] OpenAI returned EMPTY content - full response: ' . json_encode($response));
                $this->logger->warning('OpenAI returned empty content. Full response: ' . json_encode($response));
                return false;
            }

            error_log('[CA] OpenAI content extracted successfully: ' . substr($content, 0, 100));
            return $content;
        }

        error_log('[CA] OpenAI response parsing failed - missing content field');
        $this->logger->error('OpenAI response parsing failed. Response: ' . json_encode($response));
        return false;
    }
}
