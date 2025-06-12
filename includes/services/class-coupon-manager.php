<?php
/**
 * Coupon Manager for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coupon Manager class handles all coupon-related operations
 */
class Coupon_Automation_Coupon_Manager {
    
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
     * Database service
     * @var Coupon_Automation_Database
     */
    private $database;
    
    /**
     * Security service
     * @var Coupon_Automation_Security
     */
    private $security;
    
    /**
     * Initialize coupon manager
     */
    public function init() {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        $this->database = coupon_automation()->get_service('database');
        $this->security = coupon_automation()->get_service('security');
        
        // Register hooks for coupon management
        add_action('coupon_automation_cleanup', [$this, 'cleanup_expired_coupons']);
    }
    
    /**
     * Process coupon data from API
     * Preserves original logic from process_coupon()
     * 
     * @param array $coupon_data Coupon data from API
     * @param string $advertiser_name Brand/advertiser name
     * @param string $api_source API source (addrevenue|awin)
     * @return int|false Coupon post ID or false on failure
     */
    public function process_coupon($coupon_data, $advertiser_name, $api_source = 'addrevenue') {
        $this->logger->debug('Processing coupon', [
            'advertiser' => $advertiser_name,
            'api_source' => $api_source,
            'coupon_data_keys' => array_keys($coupon_data)
        ]);
        
        // Extract coupon data based on API source
        $extracted_data = $this->extract_coupon_data($coupon_data, $api_source);
        if (!$extracted_data) {
            $this->logger->warning('Failed to extract coupon data', [
                'advertiser' => $advertiser_name,
                'api_source' => $api_source
            ]);
            return false;
        }
        
        // Check for existing coupon
        if ($this->coupon_exists($extracted_data['coupon_id'], $api_source)) {
            $this->logger->debug('Coupon already exists', [
                'coupon_id' => $extracted_data['coupon_id'],
                'advertiser' => $advertiser_name
            ]);
            return false;
        }
        
        // Skip non-SE market coupons for AddRevenue
        if ($api_source === 'addrevenue' && !isset($coupon_data['markets']['SE'])) {
            $this->logger->debug('Skipping AddRevenue coupon for non-SE market', [
                'advertiser' => $advertiser_name
            ]);
            return false;
        }
        
        // Find brand term
        $brand_term = get_term_by('name', $advertiser_name, 'brands');
        if (!$brand_term) {
            $this->logger->error("Brand '$advertiser_name' not found. Brand should exist before processing coupons.");
            return false;
        }
        
        // Generate coupon title
        $coupon_title = $this->generate_coupon_title($extracted_data['description']);
        if ($coupon_title === false) {
            $this->logger->warning('Title generation failed, will retry later', [
                'coupon_id' => $extracted_data['coupon_id']
            ]);
            return false;
        }
        
        // Create coupon post
        $post_id = $this->create_coupon_post($coupon_title, $extracted_data, $advertiser_name);
        if (!$post_id) {
            return false;
        }
        
        // Process and update coupon terms
        $this->process_coupon_terms($post_id, $extracted_data['terms']);
        
        // Assign brand taxonomy
        $this->assign_brand_to_coupon($post_id, $brand_term);
        
        // Assign coupon categories from brand
        $this->assign_coupon_categories($post_id, $brand_term);
        
        // Add notification
        $this->add_new_coupon_notification($coupon_title, $advertiser_name, $extracted_data['coupon_id']);
        
        $this->logger->info('Coupon processed successfully', [
            'post_id' => $post_id,
            'coupon_title' => $coupon_title,
            'advertiser' => $advertiser_name,
            'coupon_id' => $extracted_data['coupon_id']
        ]);
        
        return $post_id;
    }
    
    /**
     * Extract coupon data based on API source
     * Preserves original data extraction logic
     * 
     * @param array $coupon_data Raw coupon data
     * @param string $api_source API source
     * @return array|false Extracted data or false on failure
     */
    private function extract_coupon_data($coupon_data, $api_source) {
        $extracted = [];
        
        if ($api_source === 'addrevenue') {
            $extracted = [
                'coupon_id' => isset($coupon_data['id']) ? sanitize_text_field($coupon_data['id']) : '',
                'description' => isset($coupon_data['description']) ? sanitize_text_field($coupon_data['description']) : '',
                'code' => isset($coupon_data['discountCode']) ? sanitize_text_field($coupon_data['discountCode']) : '',
                'url' => isset($coupon_data['trackingLink']) ? esc_url($coupon_data['trackingLink']) : '',
                'valid_to' => isset($coupon_data['validTo']) ? sanitize_text_field($coupon_data['validTo']) : '',
                'terms' => isset($coupon_data['terms']) ? wp_kses_post($coupon_data['terms']) : '',
                'type' => ''
            ];
            
            // Determine coupon type
            $extracted['type'] = empty($extracted['code']) ? 'Sale' : 'Code';
            
        } elseif ($api_source === 'awin') {
            $extracted = [
                'coupon_id' => isset($coupon_data['promotionId']) ? sanitize_text_field($coupon_data['promotionId']) : '',
                'description' => isset($coupon_data['description']) ? sanitize_text_field($coupon_data['description']) : '',
                'code' => isset($coupon_data['voucher']['code']) ? sanitize_text_field($coupon_data['voucher']['code']) : '',
                'url' => isset($coupon_data['urlTracking']) ? esc_url($coupon_data['urlTracking']) : '',
                'valid_to' => isset($coupon_data['endDate']) ? sanitize_text_field($coupon_data['endDate']) : '',
                'terms' => isset($coupon_data['terms']) ? wp_kses_post($coupon_data['terms']) : '',
                'type' => ''
            ];
            
            // Determine coupon type for AWIN
            $extracted['type'] = ($coupon_data['type'] === 'voucher' && !empty($extracted['code'])) ? 'Code' : 'Sale';
        }
        
        // Validate required fields
        if (empty($extracted['coupon_id']) || empty($extracted['description'])) {
            return false;
        }
        
        return $extracted;
    }
    
    /**
     * Check if coupon already exists
     * 
     * @param string $coupon_id External coupon ID
     * @param string $api_source API source
     * @return bool True if exists, false otherwise
     */
    private function coupon_exists($coupon_id, $api_source) {
        if ($this->database) {
            $existing_coupon = $this->database->get_coupon_by_external_id($coupon_id, $api_source);
            return $existing_coupon !== null;
        }
        
        // Fallback to direct query
        $existing_coupon = get_posts([
            'post_type' => 'coupons',
            'meta_query' => [
                [
                    'key' => 'coupon_id',
                    'value' => $coupon_id,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids',
            'posts_per_page' => 1
        ]);
        
        return !empty($existing_coupon);
    }
    
    /**
     * Generate coupon title using OpenAI
     * Preserves original logic from generate_coupon_title()
     * 
     * @param string $description Coupon description
     * @return string|false Generated title or false on failure
     */
    private function generate_coupon_title($description) {
        $openai_api = coupon_automation()->get_service('api')->get_api_handler('openai');
        if (!$openai_api) {
            $this->logger->error('OpenAI API handler not available for title generation');
            return 'Default Coupon Title';
        }
        
        $prompt_template = $this->settings->get('prompts.coupon_title_prompt', '');
        $sanitized_description = sanitize_text_field($description);
        $prompt = $prompt_template . ' ' . $sanitized_description;
        
        $title = $openai_api->generate_text($prompt, [
            'max_tokens' => 80,
            'temperature' => 0.7
        ]);
        
        if ($title) {
            // Clean up the title
            $title = sanitize_text_field($title);
            $title = trim($title);
            $title = str_replace(['"', "'"], '', $title); // Remove quotes
            
            $this->logger->debug('Generated coupon title', [
                'original_description' => $description,
                'generated_title' => $title
            ]);
            
            return $title;
        }
        
        // Schedule retry if generation fails
        wp_schedule_single_event(
            time() + HOUR_IN_SECONDS,
            'retry_generate_coupon_title',
            [$description]
        );
        
        $this->logger->warning('OpenAI title generation failed, scheduled retry');
        return false;
    }
    
    /**
     * Create coupon post
     * 
     * @param string $title Coupon title
     * @param array $coupon_data Extracted coupon data
     * @param string $advertiser_name Advertiser name
     * @return int|false Post ID or false on failure
     */
    private function create_coupon_post($title, $coupon_data, $advertiser_name) {
        // Determine post status and date
        $post_status = 'publish';
        $post_date = current_time('mysql');
        
        // Handle future posts if valid_from is set (preserved from original logic)
        if (!empty($coupon_data['valid_from'])) {
            $valid_from_timestamp = strtotime($coupon_data['valid_from']);
            $current_timestamp = current_time('timestamp');
            
            if ($valid_from_timestamp > $current_timestamp) {
                $post_status = 'future';
                $post_date = date('Y-m-d H:i:s', $valid_from_timestamp);
            }
        }
        
        // Create custom slug
        $custom_slug = sanitize_title($title . ' at ' . $advertiser_name);
        
        $post_data = [
            'post_title' => $title,
            'post_name' => $custom_slug,
            'post_status' => $post_status,
            'post_date' => $post_date,
            'post_type' => 'coupons',
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            $this->logger->error('Failed to create coupon post', [
                'error' => $post_id->get_error_message(),
                'post_data' => $post_data
            ]);
            return false;
        }
        
        // Update post meta
        $this->update_coupon_meta($post_id, $coupon_data, $advertiser_name);
        
        return $post_id;
    }
    
    /**
     * Update coupon post meta
     * 
     * @param int $post_id Post ID
     * @param array $coupon_data Coupon data
     * @param string $advertiser_name Advertiser name
     */
    private function update_coupon_meta($post_id, $coupon_data, $advertiser_name) {
        $meta_updates = [
            'coupon_id' => $coupon_data['coupon_id'],
            'code' => $coupon_data['code'],
            'redirect_url' => $coupon_data['url'],
            'valid_untill' => $coupon_data['valid_to'],
            'terms' => $coupon_data['terms'],
            'advertiser_name' => $advertiser_name,
            'coupon_type' => $coupon_data['type']
        ];
        
        foreach ($meta_updates as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
        
        $this->logger->debug('Updated coupon meta', [
            'post_id' => $post_id,
            'meta_keys' => array_keys($meta_updates)
        ]);
    }
    
    /**
     * Process coupon terms
     * Preserves original terms processing logic
     * 
     * @param int $post_id Post ID
     * @param string $coupon_terms Original terms
     */
    private function process_coupon_terms($post_id, $coupon_terms) {
        $terms_list = '';
        
        if (!empty($coupon_terms)) {
            $translated_terms = $this->translate_description($coupon_terms);
            
            if ($translated_terms !== false) {
                $terms_array = explode(' - ', $translated_terms);
                $this->logger->debug('Terms Array', ['terms' => $terms_array]);
                
                $terms_array = array_map(function($term) {
                    $term = ltrim(ucfirst(trim($term)), '. ');
                    if (!preg_match('/[.!?]$/', $term)) {
                        $term .= '.';
                    }
                    return '<li>' . htmlspecialchars($term) . '</li>';
                }, $terms_array);
                
                $terms_list = '<ul>' . implode("\n", $terms_array) . '</ul>';
                update_field('show_details_button', $terms_list, $post_id);
            }
        }
        
        // Use fallback terms if translation failed or terms were empty
        if (empty($coupon_terms) || $translated_terms === false) {
            $fallback_terms = $this->settings->get('general.fallback_terms', '');
            if (!empty($fallback_terms)) {
                $terms_array = explode('\n', $fallback_terms);
                $terms_array = array_map(function($term) {
                    $term = ucfirst(trim($term));
                    if (!preg_match('/[.!?]$/', $term)) {
                        $term .= '.';
                    }
                    return '<li>' . esc_html($term) . '</li>';
                }, $terms_array);
                $terms_list = '<ul>' . implode("\n", $terms_array) . '</ul>';
                update_field('show_details_button', $terms_list, $post_id);
            }
        }
    }
    
    /**
     * Translate coupon description to terms
     * Preserves original logic from translate_description()
     * 
     * @param string $description Original description
     * @return string|false Translated terms or false on failure
     */
    private function translate_description($description) {
        $openai_api = coupon_automation()->get_service('api')->get_api_handler('openai');
        if (!$openai_api) {
            $this->logger->error('OpenAI API handler not available for terms translation');
            return false;
        }
        
        $prompt_template = $this->settings->get('prompts.description_prompt', '');
        $sanitized_description = sanitize_text_field($description);
        $prompt = $prompt_template . ' ' . $sanitized_description;
        
        $system_message = "You are a coupon terms creator. Your task is to create concise, clear, and varied bullet points for coupon terms. Always follow the given instructions exactly. Ensure each point is unique and complete.";
        
        $translated_content = $openai_api->generate_text($prompt, [
            'max_tokens' => 150,
            'temperature' => 0.4,
            'system_message' => $system_message
        ]);
        
        if ($translated_content) {
            // Process the response (preserves original logic)
            $translated_content = preg_replace('/^[•\-\d.]\s*/m', '', $translated_content);
            $terms_array = array_filter(array_map('trim', explode("\n", $translated_content)));
            $terms_array = array_unique($terms_array);
            $terms_array = array_slice($terms_array, 0, 3);
            
            // Ensure we have 3 terms
            while (count($terms_array) < 3) {
                $new_term = "See full terms on website";
                if (!in_array($new_term, $terms_array)) {
                    $terms_array[] = $new_term;
                } else {
                    $terms_array[] = "Terms and conditions apply";
                }
            }
            
            // Clean up terms
            $terms_array = array_map(function($term) {
                return ltrim($term, '. ');
            }, $terms_array);
            
            $final_terms = implode(' - ', $terms_array);
            
            $this->logger->debug('Translated coupon terms', [
                'original' => $description,
                'translated' => $final_terms
            ]);
            
            return $final_terms;
        }
        
        // Schedule retry if translation fails
        wp_schedule_single_event(
            time() + HOUR_IN_SECONDS,
            'retry_translate_description',
            [$description]
        );
        
        $this->logger->warning('OpenAI terms translation failed, scheduled retry');
        return false;
    }
    
    /**
     * Assign brand to coupon
     * 
     * @param int $post_id Post ID
     * @param WP_Term $brand_term Brand term
     */
    private function assign_brand_to_coupon($post_id, $brand_term) {
        if ($brand_term && !is_wp_error($brand_term)) {
            wp_set_post_terms($post_id, [$brand_term->term_id], 'brands', false);
            $this->logger->debug('Assigned brand to coupon', [
                'post_id' => $post_id,
                'brand_name' => $brand_term->name,
                'brand_id' => $brand_term->term_id
            ]);
        } else {
            $this->logger->error('Failed to assign brand to coupon', [
                'post_id' => $post_id,
                'brand_term' => $brand_term
            ]);
        }
    }
    
    /**
     * Assign coupon categories from brand
     * Preserves original logic from process_coupon()
     * 
     * @param int $post_id Post ID
     * @param WP_Term $brand_term Brand term
     */
    private function assign_coupon_categories($post_id, $brand_term) {
        $coupon_categories = get_field('coupon_categories', 'brands_' . $brand_term->term_id);
        
        if (!empty($coupon_categories)) {
            wp_set_post_terms($post_id, $coupon_categories, 'coupon_categories');
            $this->logger->debug('Assigned coupon categories', [
                'post_id' => $post_id,
                'categories' => $coupon_categories
            ]);
        }
    }
    
    /**
     * Add new coupon notification
     * Preserves original functionality
     * 
     * @param string $coupon_title Coupon title
     * @param string $brand_name Brand name
     * @param string $coupon_id External coupon ID
     */
    private function add_new_coupon_notification($coupon_title, $brand_name, $coupon_id) {
        $notifications = get_option('coupon_automation_notifications', []);
        $notifications[] = [
            'type' => 'coupon',
            'title' => $coupon_title,
            'brand' => $brand_name,
            'id' => $coupon_id,
            'time' => current_time('mysql')
        ];
        update_option('coupon_automation_notifications', array_slice($notifications, -50));
    }
    
    /**
     * Cleanup expired coupons
     * Preserves original logic from purge_expired_coupons()
     * 
     * @return int Number of purged coupons
     */
    public function cleanup_expired_coupons() {
        if ($this->database) {
            $expired_coupon_ids = $this->database->get_expired_coupons(100);
        } else {
            // Fallback to direct query
            $expired_coupon_ids = $this->get_expired_coupons_fallback();
        }
        
        $purged_count = 0;
        
        foreach ($expired_coupon_ids as $coupon_id) {
            // Move to trash
            wp_trash_post($coupon_id);
            
            // Set up redirect to brand page
            $brand_terms = wp_get_post_terms($coupon_id, 'brands');
            if (!empty($brand_terms) && !is_wp_error($brand_terms)) {
                $brand_slug = $brand_terms[0]->slug;
                add_post_meta($coupon_id, '_redirect_to_brand', home_url('/brands/' . $brand_slug), true);
            }
            
            $purged_count++;
        }
        
        if ($purged_count > 0) {
            $this->logger->info("Purged expired coupons", ['count' => $purged_count]);
        }
        
        return $purged_count;
    }
    
    /**
     * Get expired coupons (fallback method)
     * 
     * @return array Expired coupon IDs
     */
    private function get_expired_coupons_fallback() {
        $today = date('Ymd');
        
        $args = [
            'post_type' => 'coupons',
            'posts_per_page' => 100,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'valid_untill',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => 'valid_untill',
                    'value' => '',
                    'compare' => '!=',
                ],
                [
                    'key' => 'valid_untill',
                    'value' => $today,
                    'compare' => '<',
                    'type' => 'DATE'
                ]
            ]
        ];
        
        return get_posts($args);
    }
    
    /**
     * Get coupon statistics
     * 
     * @return array Coupon statistics
     */
    public function get_coupon_statistics() {
        $total_coupons = wp_count_posts('coupons');
        $today = date('Ymd');
        
        $expired_count = count($this->get_expired_coupons_fallback());
        
        $recent_coupons = get_posts([
            'post_type' => 'coupons',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'date_query' => [
                [
                    'after' => '1 week ago'
                ]
            ]
        ]);
        
        return [
            'total_published' => $total_coupons->publish ?? 0,
            'total_draft' => $total_coupons->draft ?? 0,
            'total_trash' => $total_coupons->trash ?? 0,
            'expired_count' => $expired_count,
            'recent_count' => count($recent_coupons),
        ];
    }
    
    /**
     * Get coupons by brand
     * 
     * @param int $brand_term_id Brand term ID
     * @param int $limit Number of coupons to retrieve
     * @return array Coupon posts
     */
    public function get_coupons_by_brand($brand_term_id, $limit = 20) {
        $args = [
            'post_type' => 'coupons',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'tax_query' => [
                [
                    'taxonomy' => 'brands',
                    'field' => 'term_id',
                    'terms' => $brand_term_id
                ]
            ],
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        
        return get_posts($args);
    }
    
    /**
     * Search coupons
     * 
     * @param string $search_term Search term
     * @param array $filters Additional filters
     * @return array Search results
     */
    public function search_coupons($search_term, $filters = []) {
        $args = [
            'post_type' => 'coupons',
            'posts_per_page' => 50,
            'post_status' => 'publish',
            's' => sanitize_text_field($search_term),
            'orderby' => 'relevance',
            'order' => 'DESC'
        ];
        
        // Apply filters
        if (!empty($filters['brand'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'brands',
                    'field' => 'slug',
                    'terms' => sanitize_title($filters['brand'])
                ]
            ];
        }
        
        if (!empty($filters['coupon_type'])) {
            $args['meta_query'] = [
                [
                    'key' => 'coupon_type',
                    'value' => sanitize_text_field($filters['coupon_type']),
                    'compare' => '='
                ]
            ];
        }
        
        return get_posts($args);
    }
    
    /**
     * Validate coupon data
     * 
     * @param array $coupon_data Coupon data to validate
     * @param string $api_source API source
     * @return array Validation errors
     */
    public function validate_coupon_data($coupon_data, $api_source) {
        $errors = [];
        
        // Required fields based on API source
        $required_fields = [];
        if ($api_source === 'addrevenue') {
            $required_fields = ['id', 'description'];
        } elseif ($api_source === 'awin') {
            $required_fields = ['promotionId', 'description'];
        }
        
        foreach ($required_fields as $field) {
            if (!isset($coupon_data[$field]) || empty($coupon_data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate URL if present
        if (isset($coupon_data['trackingLink']) && !empty($coupon_data['trackingLink'])) {
            if (!filter_var($coupon_data['trackingLink'], FILTER_VALIDATE_URL)) {
                $errors[] = "Invalid tracking link URL";
            }
        }
        
        if (isset($coupon_data['urlTracking']) && !empty($coupon_data['urlTracking'])) {
            if (!filter_var($coupon_data['urlTracking'], FILTER_VALIDATE_URL)) {
                $errors[] = "Invalid tracking URL";
            }
        }
        
        return $errors;
    }
}