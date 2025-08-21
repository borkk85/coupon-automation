<?php

namespace CouponAutomation\Admin;

/**
 * Settings management
 */
class Settings {
    
    private $optionGroup = 'coupon_automation_settings';
    private $sections = [];
    
    public function __construct() {
        $this->defineSections();
    }
    
    /**
     * Register all settings
     */
    public function registerSettings() {
        // API Settings
        register_setting($this->optionGroup, 'addrevenue_api_key', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting($this->optionGroup, 'awin_api_token', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting($this->optionGroup, 'awin_publisher_id', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting($this->optionGroup, 'ai_api_key', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting($this->optionGroup, 'yourls_username', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting($this->optionGroup, 'yourls_password', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting($this->optionGroup, 'yourls_api_token', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        // Prompt Settings
        register_setting($this->optionGroup, 'coupon_title_prompt', [
            'sanitize_callback' => 'sanitize_textarea_field'
        ]);
        
        register_setting($this->optionGroup, 'description_prompt', [
            'sanitize_callback' => 'sanitize_textarea_field'
        ]);
        
        register_setting($this->optionGroup, 'brand_description_prompt', [
            'sanitize_callback' => 'wp_kses_post'
        ]);
        
        register_setting($this->optionGroup, 'why_we_love_prompt', [
            'sanitize_callback' => 'wp_kses_post'
        ]);
        
        register_setting($this->optionGroup, 'fallback_terms', [
            'sanitize_callback' => 'sanitize_textarea_field'
        ]);
    }
    
    /**
     * Define settings sections
     */
    private function defineSections() {
        $this->sections = [
            'api_credentials' => [
                'title' => 'API Credentials',
                'fields' => [
                    'addrevenue_api_key' => [
                        'label' => 'AddRevenue API Token',
                        'type' => 'password',
                        'description' => 'Your AddRevenue API authentication token'
                    ],
                    'awin_api_token' => [
                        'label' => 'AWIN API Token',
                        'type' => 'password',
                        'description' => 'Your AWIN API authentication token'
                    ],
                    'awin_publisher_id' => [
                        'label' => 'AWIN Publisher ID',
                        'type' => 'text',
                        'description' => 'Your AWIN publisher account ID'
                    ],
                    'ai_api_key' => [
                        'label' => 'OpenAI API Key',
                        'type' => 'password',
                        'description' => 'Your OpenAI API key for content generation'
                    ],
                    'yourls_username' => [
                        'label' => 'YOURLS Username',
                        'type' => 'text',
                        'description' => 'Username for YOURLS URL shortener'
                    ],
                    'yourls_password' => [
                        'label' => 'YOURLS Password',
                        'type' => 'password',
                        'description' => 'Password for YOURLS URL shortener'
                    ],
                ]
            ],
            'prompts' => [
                'title' => 'AI Prompts',
                'fields' => [
                    'coupon_title_prompt' => [
                        'label' => 'Coupon Title Prompt',
                        'type' => 'textarea',
                        'description' => 'Prompt for generating coupon titles'
                    ],
                    'description_prompt' => [
                        'label' => 'Description Prompt',
                        'type' => 'textarea',
                        'description' => 'Prompt for processing coupon terms'
                    ],
                    'brand_description_prompt' => [
                        'label' => 'Brand Description Prompt',
                        'type' => 'editor',
                        'description' => 'Prompt for generating brand descriptions'
                    ],
                    'why_we_love_prompt' => [
                        'label' => 'Why We Love Prompt',
                        'type' => 'editor',
                        'description' => 'Prompt for generating "Why We Love" content'
                    ],
                    'fallback_terms' => [
                        'label' => 'Fallback Terms',
                        'type' => 'textarea',
                        'description' => 'Default terms when API terms are unavailable'
                    ],
                ]
            ]
        ];
    }
    
    /**
     * Get settings sections
     */
    public function getSections() {
        return $this->sections;
    }
    
    /**
     * Get option group
     */
    public function getOptionGroup() {
        return $this->optionGroup;
    }
    
    /**
     * Get field value
     */
    public function getFieldValue($field) {
        return get_option($field, '');
    }
}