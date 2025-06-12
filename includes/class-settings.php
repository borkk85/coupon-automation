<?php
/**
 * Settings handler for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings class for secure settings management
 */
class Coupon_Automation_Settings {
    
    /**
     * Settings option name
     */
    const OPTION_NAME = 'coupon_automation_settings';
    
    /**
     * Default settings
     * @var array
     */
    private $default_settings = [
        'api_keys' => [
            'addrevenue_api_token' => '',
            'awin_api_token' => '',
            'awin_publisher_id' => '',
            'openai_api_key' => '',
            'yourl_api_token' => '',
        ],
        'prompts' => [
            'coupon_title_prompt' => 'Generate a compelling and SEO-friendly coupon title based on this description:',
            'description_prompt' => 'Create 3 concise bullet points for coupon terms from this description:',
            'brand_description_prompt' => 'Write a compelling brand description for SEO purposes based on this brand information:',
            'why_we_love_prompt' => 'Generate 3 short phrases (3 words max each) explaining why customers love this brand:',
        ],
        'general' => [
            'batch_size' => 10,
            'api_timeout' => 30,
            'log_retention_days' => 30,
            'enable_debug_logging' => false,
            'fallback_terms' => "See full terms on website\nTerms and conditions apply\nOffer may expire without notice",
        ],
        'automation' => [
            'auto_schedule_enabled' => true,
            'schedule_interval' => 'daily',
            'max_processing_time' => 300, // 5 minutes
            'enable_notifications' => true,
        ]
    ];
    
    /**
     * Cached settings
     * @var array|null
     */
    private $settings = null;
    
    /**
     * Encryption service
     * @var Coupon_Automation_Encryption
     */
    private $encryption;
    
    /**
     * Security service
     * @var Coupon_Automation_Security
     */
    private $security;
    
    /**
     * Initialize settings
     */
    public function init() {
        $this->encryption = new Coupon_Automation_Encryption();
        $this->encryption->init();
        
        $this->security = coupon_automation()->get_service('security');
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Migrate old settings on first load
        add_action('admin_init', [$this, 'maybe_migrate_settings'], 5);
    }
    
    /**
     * Register WordPress settings
     */
    public function register_settings() {
        register_setting(
            'coupon_automation_settings_group',
            self::OPTION_NAME,
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->default_settings
            ]
        );
        
        // Register individual sections for better organization
        add_settings_section(
            'api_keys_section',
            __('API Configuration', 'coupon-automation'),
            [$this, 'api_keys_section_callback'],
            'coupon_automation_api_keys'
        );
        
        add_settings_section(
            'prompts_section',
            __('AI Prompts Configuration', 'coupon-automation'),
            [$this, 'prompts_section_callback'],
            'coupon_automation_prompts'
        );
        
        add_settings_section(
            'general_section',
            __('General Settings', 'coupon-automation'),
            [$this, 'general_section_callback'],
            'coupon_automation_general'
        );
        
        add_settings_section(
            'automation_section',
            __('Automation Settings', 'coupon-automation'),
            [$this, 'automation_section_callback'],
            'coupon_automation_automation'
        );
        
        // Register individual settings fields
        $this->register_settings_fields();
    }
    
    /**
     * Register individual settings fields
     */
    private function register_settings_fields() {
        // API Keys fields
        $api_fields = [
            'addrevenue_api_token' => __('AddRevenue API Token', 'coupon-automation'),
            'awin_api_token' => __('AWIN API Token', 'coupon-automation'),
            'awin_publisher_id' => __('AWIN Publisher ID', 'coupon-automation'),
            'openai_api_key' => __('OpenAI API Key', 'coupon-automation'),
            'yourl_api_token' => __('YOURLS API Token', 'coupon-automation'),
        ];
        
        foreach ($api_fields as $field => $title) {
            add_settings_field(
                $field,
                $title,
                [$this, 'api_key_field_callback'],
                'coupon_automation_api_keys',
                'api_keys_section',
                ['field_name' => $field, 'field_type' => 'password']
            );
        }
        
        // Prompt fields
        $prompt_fields = [
            'coupon_title_prompt' => __('Coupon Title Prompt', 'coupon-automation'),
            'description_prompt' => __('Coupon Terms Prompt', 'coupon-automation'),
            'brand_description_prompt' => __('Brand Description Prompt', 'coupon-automation'),
            'why_we_love_prompt' => __('Why We Love Prompt', 'coupon-automation'),
        ];
        
        foreach ($prompt_fields as $field => $title) {
            add_settings_field(
                $field,
                $title,
                [$this, 'prompt_field_callback'],
                'coupon_automation_prompts',
                'prompts_section',
                ['field_name' => $field]
            );
        }
        
        // General settings fields
        $general_fields = [
            'batch_size' => ['title' => __('Batch Size', 'coupon-automation'), 'type' => 'number'],
            'api_timeout' => ['title' => __('API Timeout (seconds)', 'coupon-automation'), 'type' => 'number'],
            'log_retention_days' => ['title' => __('Log Retention (days)', 'coupon-automation'), 'type' => 'number'],
            'enable_debug_logging' => ['title' => __('Enable Debug Logging', 'coupon-automation'), 'type' => 'checkbox'],
            'fallback_terms' => ['title' => __('Fallback Terms', 'coupon-automation'), 'type' => 'textarea'],
        ];
        
        foreach ($general_fields as $field => $config) {
            add_settings_field(
                $field,
                $config['title'],
                [$this, 'general_field_callback'],
                'coupon_automation_general',
                'general_section',
                ['field_name' => $field, 'field_type' => $config['type']]
            );
        }
    }
    
    /**
     * Get all settings
     * 
     * @return array Settings array
     */
    public function get_settings() {
        if ($this->settings === null) {
            $this->settings = $this->load_settings();
        }
        
        return $this->settings;
    }
    
    /**
     * Get a specific setting value
     * 
     * @param string $key Setting key (supports dot notation)
     * @param mixed $default Default value if setting not found
     * @return mixed Setting value
     */
    public function get($key, $default = null) {
        $settings = $this->get_settings();
        
        // Support dot notation for nested settings
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $value = $settings;
            
            foreach ($keys as $nested_key) {
                if (isset($value[$nested_key])) {
                    $value = $value[$nested_key];
                } else {
                    return $default;
                }
            }
            
            return $value;
        }
        
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
    
    /**
     * Get API key securely
     * 
     * @param string $key_name API key name
     * @return string Decrypted API key
     */
    public function get_api_key($key_name) {
        if (!$this->encryption) {
            return '';
        }
        
        // First try the new encrypted storage
        $encrypted_key = $this->encryption->get_api_key($key_name);
        if ($encrypted_key !== false) {
            return $encrypted_key;
        }
        
        // Fallback to settings array (for backwards compatibility)
        return $this->get("api_keys.{$key_name}", '');
    }
    
    /**
     * Update a setting value
     * 
     * @param string $key Setting key (supports dot notation)
     * @param mixed $value Setting value
     * @return bool True on success, false on failure
     */
    public function update($key, $value) {
        $settings = $this->get_settings();
        
        // Support dot notation for nested settings
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $current = &$settings;
            
            foreach ($keys as $i => $nested_key) {
                if ($i === count($keys) - 1) {
                    $current[$nested_key] = $value;
                } else {
                    if (!isset($current[$nested_key]) || !is_array($current[$nested_key])) {
                        $current[$nested_key] = [];
                    }
                    $current = &$current[$nested_key];
                }
            }
        } else {
            $settings[$key] = $value;
        }
        
        return $this->save_settings($settings);
    }
    
    /**
     * Update API key securely
     * 
     * @param string $key_name API key name
     * @param string $api_key API key value
     * @return bool True on success, false on failure
     */
    public function update_api_key($key_name, $api_key) {
        if (!$this->encryption) {
            return false;
        }
        
        // Store in encrypted format
        $success = $this->encryption->store_api_key($key_name, $api_key);
        
        if ($success) {
            // Also update in settings array for consistency
            $this->update("api_keys.{$key_name}", '[ENCRYPTED]');
        }
        
        return $success;
    }
    
    /**
     * Load settings from database
     * 
     * @return array Settings array
     */
    private function load_settings() {
        $saved_settings = get_option(self::OPTION_NAME, []);
        
        // Merge with defaults to ensure all keys exist
        return wp_parse_args($saved_settings, $this->default_settings);
    }
    
    /**
     * Save settings to database
     * 
     * @param array $settings Settings array
     * @return bool True on success, false on failure
     */
    private function save_settings($settings) {
        $success = update_option(self::OPTION_NAME, $settings);
        
        if ($success) {
            $this->settings = $settings;
        }
        
        return $success;
    }
    
    /**
     * Sanitize settings before saving
     * 
     * @param array $input Raw input data
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        if (!$this->security) {
            return $input;
        }
        
        $sanitized = [];
        
        // Sanitize API keys
        if (isset($input['api_keys']) && is_array($input['api_keys'])) {
            foreach ($input['api_keys'] as $key => $value) {
                $sanitized_key = sanitize_key($key);
                $sanitized_value = $this->security->sanitize_input($value, 'api_key');
                
                // Store API key securely
                if (!empty($sanitized_value)) {
                    $this->update_api_key($sanitized_key, $sanitized_value);
                    $sanitized['api_keys'][$sanitized_key] = '[ENCRYPTED]';
                } else {
                    $sanitized['api_keys'][$sanitized_key] = '';
                }
            }
        }
        
        // Sanitize prompts
        if (isset($input['prompts']) && is_array($input['prompts'])) {
            foreach ($input['prompts'] as $key => $value) {
                $sanitized_key = sanitize_key($key);
                $sanitized['prompts'][$sanitized_key] = $this->security->sanitize_input($value, 'textarea');
            }
        }
        
        // Sanitize general settings
        if (isset($input['general']) && is_array($input['general'])) {
            $sanitized['general']['batch_size'] = absint($input['general']['batch_size'] ?? 10);
            $sanitized['general']['api_timeout'] = absint($input['general']['api_timeout'] ?? 30);
            $sanitized['general']['log_retention_days'] = absint($input['general']['log_retention_days'] ?? 30);
            $sanitized['general']['enable_debug_logging'] = isset($input['general']['enable_debug_logging']);
            $sanitized['general']['fallback_terms'] = $this->security->sanitize_input(
                $input['general']['fallback_terms'] ?? '', 
                'textarea'
            );
        }
        
        // Sanitize automation settings
        if (isset($input['automation']) && is_array($input['automation'])) {
            $sanitized['automation']['auto_schedule_enabled'] = isset($input['automation']['auto_schedule_enabled']);
            $sanitized['automation']['schedule_interval'] = sanitize_text_field($input['automation']['schedule_interval'] ?? 'daily');
            $sanitized['automation']['max_processing_time'] = absint($input['automation']['max_processing_time'] ?? 300);
            $sanitized['automation']['enable_notifications'] = isset($input['automation']['enable_notifications']);
        }
        
        return $sanitized;
    }
    
    /**
     * Migrate old individual option settings to new unified structure
     */
    public function maybe_migrate_settings() {
        $migration_done = get_option('coupon_automation_settings_migrated', false);
        
        if ($migration_done) {
            return;
        }
        
        $old_options = [
            'addrevenue_api_token',
            'awin_api_token',
            'awin_publisher_id',
            'openai_api_key',
            'yourl_api_token',
            'coupon_title_prompt',
            'description_prompt',
            'brand_description_prompt',
            'why_we_love_prompt',
            'fallback_terms',
        ];
        
        $migrated_settings = $this->default_settings;
        
        foreach ($old_options as $option) {
            $value = get_option($option, '');
            
            if (!empty($value)) {
                // Determine which section this option belongs to
                if (strpos($option, '_api_') !== false || strpos($option, '_token') !== false || strpos($option, '_key') !== false || strpos($option, '_id') !== false) {
                    $migrated_settings['api_keys'][$option] = $value;
                    
                    // Encrypt API keys during migration
                    if ($this->encryption) {
                        $this->encryption->store_api_key($option, $value);
                        $migrated_settings['api_keys'][$option] = '[ENCRYPTED]';
                    }
                } elseif (strpos($option, '_prompt') !== false) {
                    $migrated_settings['prompts'][$option] = $value;
                } else {
                    $migrated_settings['general'][$option] = $value;
                }
                
                // Delete old option
                delete_option($option);
            }
        }
        
        // Save migrated settings
        update_option(self::OPTION_NAME, $migrated_settings);
        update_option('coupon_automation_settings_migrated', true);
        
        // Clear settings cache
        $this->settings = null;
    }
    
    /**
     * Reset settings to defaults
     * 
     * @return bool True on success, false on failure
     */
    public function reset_to_defaults() {
        $success = update_option(self::OPTION_NAME, $this->default_settings);
        
        if ($success) {
            $this->settings = null;
        }
        
        return $success;
    }
    
    /**
     * Validate settings
     * 
     * @param array $settings Settings to validate
     * @return array Validation errors
     */
    public function validate_settings($settings) {
        $errors = [];
        
        // Validate batch size
        if (isset($settings['general']['batch_size'])) {
            $batch_size = intval($settings['general']['batch_size']);
            if ($batch_size < 1 || $batch_size > 100) {
                $errors[] = __('Batch size must be between 1 and 100.', 'coupon-automation');
            }
        }
        
        // Validate API timeout
        if (isset($settings['general']['api_timeout'])) {
            $timeout = intval($settings['general']['api_timeout']);
            if ($timeout < 5 || $timeout > 300) {
                $errors[] = __('API timeout must be between 5 and 300 seconds.', 'coupon-automation');
            }
        }
        
        // Validate log retention
        if (isset($settings['general']['log_retention_days'])) {
            $retention = intval($settings['general']['log_retention_days']);
            if ($retention < 1 || $retention > 365) {
                $errors[] = __('Log retention must be between 1 and 365 days.', 'coupon-automation');
            }
        }
        
        // Validate schedule interval
        if (isset($settings['automation']['schedule_interval'])) {
            $allowed_intervals = ['hourly', 'twicedaily', 'daily', 'weekly'];
            if (!in_array($settings['automation']['schedule_interval'], $allowed_intervals)) {
                $errors[] = __('Invalid schedule interval.', 'coupon-automation');
            }
        }
        
        return $errors;
    }
    
    /**
     * Section callbacks for settings page
     */
    public function api_keys_section_callback() {
        echo '<p>' . esc_html__('Configure API keys for external services. All keys are encrypted before storage.', 'coupon-automation') . '</p>';
    }
    
    public function prompts_section_callback() {
        echo '<p>' . esc_html__('Customize AI prompts used for generating content.', 'coupon-automation') . '</p>';
    }
    
    public function general_section_callback() {
        echo '<p>' . esc_html__('General plugin configuration options.', 'coupon-automation') . '</p>';
    }
    
    public function automation_section_callback() {
        echo '<p>' . esc_html__('Configure automation and scheduling options.', 'coupon-automation') . '</p>';
    }
    
    /**
     * Field callbacks for settings page
     */
    public function api_key_field_callback($args) {
        $field_name = $args['field_name'];
        $value = $this->get_api_key($field_name);
        $display_value = !empty($value) ? str_repeat('*', 20) : '';
        
        echo '<input type="password" name="' . esc_attr(self::OPTION_NAME . '[api_keys][' . $field_name . ']') . '" ';
        echo 'value="' . esc_attr($display_value) . '" class="regular-text" />';
        
        if (!empty($value)) {
            echo '<p class="description">' . esc_html__('API key is set and encrypted.', 'coupon-automation') . '</p>';
        }
    }
    
    public function prompt_field_callback($args) {
        $field_name = $args['field_name'];
        $value = $this->get("prompts.{$field_name}", '');
        
        echo '<textarea name="' . esc_attr(self::OPTION_NAME . '[prompts][' . $field_name . ']') . '" ';
        echo 'rows="4" cols="50" class="large-text">' . esc_textarea($value) . '</textarea>';
    }
    
    public function general_field_callback($args) {
        $field_name = $args['field_name'];
        $field_type = $args['field_type'];
        $value = $this->get("general.{$field_name}", '');
        
        switch ($field_type) {
            case 'number':
                echo '<input type="number" name="' . esc_attr(self::OPTION_NAME . '[general][' . $field_name . ']') . '" ';
                echo 'value="' . esc_attr($value) . '" class="small-text" />';
                break;
                
            case 'checkbox':
                echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME . '[general][' . $field_name . ']') . '" ';
                echo 'value="1" ' . checked($value, true, false) . ' />';
                break;
                
            case 'textarea':
                echo '<textarea name="' . esc_attr(self::OPTION_NAME . '[general][' . $field_name . ']') . '" ';
                echo 'rows="4" cols="50" class="large-text">' . esc_textarea($value) . '</textarea>';
                break;
                
            default:
                echo '<input type="text" name="' . esc_attr(self::OPTION_NAME . '[general][' . $field_name . ']') . '" ';
                echo 'value="' . esc_attr($value) . '" class="regular-text" />';
        }
    }
}