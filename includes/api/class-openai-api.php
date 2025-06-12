<?php
/**
 * OpenAI API handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI API class
 */
class Coupon_Automation_API_OpenAI {
    
    /**
     * API base URL
     */
    const API_BASE_URL = 'https://api.openai.com/v1/';
    
    /**
     * Default model
     */
    const DEFAULT_MODEL = 'gpt-4o-mini';
    
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
     * API key
     * @var string
     */
    private $api_key;
    
    /**
     * Initialize API handler
     */
    public function init() {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        
        if ($this->settings) {
            $this->api_key = $this->settings->get_api_key('openai_api_key');
        }
        
        // Register retry actions
        add_action('retry_generate_coupon_title', [$this, 'retry_generate_coupon_title']);
        add_action('retry_translate_description', [$this, 'retry_translate_description']);
    }
    
    /**
     * Check if API is configured
     * 
     * @return bool True if configured, false otherwise
     */
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    /**
     * Generate text using OpenAI API
     * Main method that replaces individual generation functions
     * 
     * @param string $prompt Text prompt
     * @param array $options Generation options
     * @return string|false Generated text or false on failure
     */
    public function generate_text($prompt, $options = []) {
        if (!$this->is_configured()) {
            $this->logger->warning('OpenAI API not configured');
            return false;
        }
        
        $defaults = [
            'model' => self::DEFAULT_MODEL,
            'max_tokens' => 150,
            'temperature' => 0.7,
            'system_message' => null
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        // Build messages array
        $messages = [];
        
        if (!empty($options['system_message'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $options['system_message']
            ];
        }
        
        $messages[] = [
            'role' => 'user',
            'content' => $prompt
        ];
        
        // Make API request
        $response_data = $this->make_chat_completion_request($messages, $options);
        
        if ($response_data && isset($response_data['choices'][0]['message']['content'])) {
            $generated_content = $response_data['choices'][0]['message']['content'];
            
            $this->logger->debug('OpenAI text generation successful', [
                'prompt_length' => strlen($prompt),
                'response_length' => strlen($generated_content),
                'model' => $options['model']
            ]);
            
            return trim($generated_content);
        }
        
        return false;
    }
    
    /**
     * Generate coupon title
     * Preserves original functionality from generate_coupon_title()
     * 
     * @param string $description Coupon description
     * @return string|false Generated title or false on failure
     */
    public function generate_coupon_title($description) {
        $prompt_template = $this->settings->get('prompts.coupon_title_prompt', '');
        $sanitized_description = sanitize_text_field($description);
        $prompt = $prompt_template . ' ' . $sanitized_description;
        
        $title = $this->generate_text($prompt, [
            'max_tokens' => 80,
            'temperature' => 0.7
        ]);
        
        if ($title) {
            // Clean up the title (preserves original logic)
            $title = sanitize_text_field($title);
            $title = trim($title);
            $title = str_replace(['"', "'"], '', $title); // Remove quotes
            
            return $title;
        }
        
        return false;
    }
    
    /**
     * Generate brand description
     * Preserves original functionality from generate_brand_description()
     * 
     * @param string $brand_name Brand name
     * @param int $brand_term_id Brand term ID
     * @param array $brand_data Additional brand data
     * @return string|false Generated description or false on failure
     */
    public function generate_brand_description($brand_name, $brand_term_id, $brand_data = []) {
        if (empty($brand_term_id) || !is_numeric($brand_term_id) || get_term($brand_term_id, 'brands') === null) {
            $this->logger->error("Invalid brand term ID for: " . $brand_name);
            return false;
        }
        
        $prompt_template = $this->settings->get('prompts.brand_description_prompt', '');
        
        // Create context from brand data
        $context = "Brand Name: $brand_name\n";
        if ($brand_data) {
            if (isset($brand_data['primarySector'])) {
                $context .= "Sector: " . $brand_data['primarySector'] . "\n";
            }
            if (isset($brand_data['strapLine'])) {
                $context .= "Tagline: " . $brand_data['strapLine'] . "\n";
            }
        }
        
        $prompt = $prompt_template . "\n\nContext:\n" . $context;
        
        $description = $this->generate_text($prompt, [
            'max_tokens' => 1000,
            'temperature' => 0.7
        ]);
        
        if ($description) {
            // Allow only safe HTML tags (preserves original logic)
            $allowed_tags = [
                'h4' => ['style' => [], 'class' => []],
                'p' => ['style' => [], 'class' => []],
                'strong' => [],
                'em' => [],
                'ul' => [],
                'li' => [],
                'br' => [],
            ];
            
            $formatted_content = wp_kses($description, $allowed_tags);
            return $formatted_content;
        }
        
        return false;
    }
    
    /**
     * Generate "Why We Love" content
     * Preserves original functionality from generate_why_we_love()
     * 
     * @param string $brand_name Brand name
     * @param array $brand_data Additional brand data
     * @return string|false Generated content or false on failure
     */
    public function generate_why_we_love($brand_name, $brand_data = []) {
        $prompt_template = $this->settings->get('prompts.why_we_love_prompt', '');
        
        $response = $this->generate_text($prompt_template, [
            'max_tokens' => 500,
            'temperature' => 0.7
        ]);
        
        if (!$response) {
            return false;
        }
        
        // Extract phrases (this logic would be moved to Brand Manager in real implementation)
        // For now, return the raw response - the Brand Manager will process it
        return $response;
    }
    
    /**
     * Translate description to terms
     * Preserves original functionality from translate_description()
     * 
     * @param string $description Original description
     * @return string|false Translated terms or false on failure
     */
    public function translate_description($description) {
        $prompt_template = $this->settings->get('prompts.description_prompt', '');
        $sanitized_description = sanitize_text_field($description);
        $prompt = $prompt_template . ' ' . $sanitized_description;
        
        $system_message = "You are a coupon terms creator. Your task is to create concise, clear, and varied bullet points for coupon terms. Always follow the given instructions exactly. Ensure each point is unique and complete.";
        
        $translated_content = $this->generate_text($prompt, [
            'max_tokens' => 150,
            'temperature' => 0.4,
            'system_message' => $system_message
        ]);
        
        if ($translated_content) {
            // Process the response (this logic would be moved to Coupon Manager in real implementation)
            // For now, return the raw response - the Coupon Manager will process it
            return $translated_content;
        }
        
        return false;
    }
    
    /**
     * Make chat completion request to OpenAI
     * 
     * @param array $messages Messages array
     * @param array $options Request options
     * @return array|false API response or false on failure
     */
    private function make_chat_completion_request($messages, $options) {
        $url = self::API_BASE_URL . 'chat/completions';
        $start_time = microtime(true);
        
        $request_body = [
            'model' => $options['model'],
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature']
        ];
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
            'User-Agent' => 'Coupon-Automation/' . COUPON_AUTOMATION_VERSION
        ];
        
        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => json_encode($request_body),
            'timeout' => $this->settings ? $this->settings->get('general.api_timeout', 30) : 30,
            'sslverify' => true,
        ];
        
        $this->logger->debug('Making OpenAI API request', [
            'model' => $options['model'],
            'max_tokens' => $options['max_tokens'],
            'messages_count' => count($messages)
        ]);
        
        $response = wp_remote_post($url, $args);
        $response_time = microtime(true) - $start_time;
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Log the API request
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_api_request(
                'openai',
                $url,
                'POST',
                $headers,
                $request_body,
                null,
                $response_time,
                $error_message
            );
            
            $this->logger->error('OpenAI API request failed', [
                'error' => $error_message,
                'model' => $options['model']
            ]);
            
            return false;
        }
        
        $this->logger->log_api_request(
            'openai',
            $url,
            'POST',
            $headers,
            $request_body,
            $response_code,
            $response_time
        );
        
        // Handle non-200 response codes
        if ($response_code !== 200) {
            $error_message = $this->parse_error_response($response);
            $this->logger->error('OpenAI API returned error', [
                'status_code' => $response_code,
                'error' => $error_message,
                'model' => $options['model']
            ]);
            
            return false;
        }
        
        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('OpenAI API returned invalid JSON', [
                'json_error' => json_last_error_msg(),
                'model' => $options['model']
            ]);
            return false;
        }
        
        // Validate response structure
        if (!isset($data['choices']) || empty($data['choices'])) {
            $this->logger->error('OpenAI API response missing choices', [
                'response_keys' => array_keys($data),
                'model' => $options['model']
            ]);
            return false;
        }
        
        $this->logger->info('OpenAI API request successful', [
            'model' => $options['model'],
            'response_time' => round($response_time, 3) . 's',
            'tokens_used' => $data['usage']['total_tokens'] ?? 'unknown'
        ]);
        
        return $data;
    }
    
    /**
     * Parse error response from OpenAI
     * 
     * @param WP_HTTP_Response|array $response HTTP response
     * @return string Error message
     */
    private function parse_error_response($response) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error']['message'])) {
            return $data['error']['message'];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return "HTTP {$response_code}: " . wp_remote_retrieve_response_message($response);
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
                'message' => 'API key not configured'
            ];
        }
        
        try {
            // Make a simple test request
            $test_messages = [
                [
                    'role' => 'user',
                    'content' => 'Say "API test successful" and nothing else.'
                ]
            ];
            
            $start_time = microtime(true);
            $response = $this->make_chat_completion_request($test_messages, [
                'model' => self::DEFAULT_MODEL,
                'max_tokens' => 10,
                'temperature' => 0
            ]);
            $response_time = microtime(true) - $start_time;
            
            if ($response && isset($response['choices'][0]['message']['content'])) {
                return [
                    'status' => 'success',
                    'message' => 'Connection successful',
                    'response_time' => round($response_time, 3) . 's',
                    'model' => self::DEFAULT_MODEL
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Invalid response from API'
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
     * Get API usage statistics
     * 
     * @return array Usage statistics
     */
    public function get_usage_statistics() {
        // This would require storing usage data over time
        // For now, return basic info
        return [
            'requests_today' => get_transient('openai_requests_today') ?: 0,
            'tokens_used_today' => get_transient('openai_tokens_today') ?: 0,
            'last_request' => get_option('openai_last_request_time'),
            'rate_limit_remaining' => get_transient('openai_rate_limit_remaining'),
        ];
    }
    
    /**
     * Update usage statistics
     * 
     * @param array $usage_data Usage data from API response
     */
    private function update_usage_statistics($usage_data) {
        if (!isset($usage_data['total_tokens'])) {
            return;
        }
        
        $today = date('Y-m-d');
        $requests_key = 'openai_requests_' . $today;
        $tokens_key = 'openai_tokens_' . $today;
        
        // Increment daily counters
        $current_requests = get_transient($requests_key) ?: 0;
        $current_tokens = get_transient($tokens_key) ?: 0;
        
        set_transient($requests_key, $current_requests + 1, DAY_IN_SECONDS);
        set_transient($tokens_key, $current_tokens + $usage_data['total_tokens'], DAY_IN_SECONDS);
        
        // Update global counters for today (for backwards compatibility)
        set_transient('openai_requests_today', $current_requests + 1, DAY_IN_SECONDS);
        set_transient('openai_tokens_today', $current_tokens + $usage_data['total_tokens'], DAY_IN_SECONDS);
        
        // Update last request time
        update_option('openai_last_request_time', current_time('mysql'));
    }
    
    /**
     * Check rate limits
     * 
     * @return bool True if within limits, false otherwise
     */
    public function check_rate_limits() {
        $requests_today = get_transient('openai_requests_today') ?: 0;
        $max_requests_per_day = 1000; // Adjust based on your plan
        
        return $requests_today < $max_requests_per_day;
    }
    
    /**
     * Get available models
     * 
     * @return array Available models
     */
    public function get_available_models() {
        return [
            'gpt-4o-mini' => 'GPT-4o Mini (Fast, cost-effective)',
            'gpt-4o' => 'GPT-4o (High capability)',
            'gpt-4-turbo' => 'GPT-4 Turbo (Previous generation)',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Legacy)'
        ];
    }
    
    /**
     * Retry coupon title generation
     * Action hook for scheduled retry
     * 
     * @param string $description Coupon description
     */
    public function retry_generate_coupon_title($description) {
        $this->logger->info('Retrying coupon title generation', ['description' => $description]);
        return $this->generate_coupon_title($description);
    }
    
    /**
     * Retry description translation
     * Action hook for scheduled retry
     * 
     * @param string $description Original description
     */
    public function retry_translate_description($description) {
        $this->logger->info('Retrying description translation', ['description' => $description]);
        return $this->translate_description($description);
    }
    
    /**
     * Get API status information
     * 
     * @return array API status
     */
    public function get_api_status() {
        return [
            'name' => 'OpenAI',
            'configured' => $this->is_configured(),
            'base_url' => self::API_BASE_URL,
            'default_model' => self::DEFAULT_MODEL,
            'available_models' => $this->get_available_models(),
            'usage_stats' => $this->get_usage_statistics(),
            'rate_limit_ok' => $this->check_rate_limits()
        ];
    }
}