<?php
/**
 * Brand Manager for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Brand Manager class handles all brand-related operations
 */
class Coupon_Automation_Brand_Manager {
    
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
     * Initialize brand manager
     */
    public function init() {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        $this->database = coupon_automation()->get_service('database');
        
        // WordPress includes for media functions
        if (!function_exists('media_sideload_image')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
    }
    
    /**
     * Find existing brand or create new one
     * Preserves original logic from find_similar_brand() and brand creation
     * 
     * @param string $brand_name Brand name
     * @param string $api_source API source
     * @return WP_Term|null Brand term or null on failure
     */
    public function find_or_create_brand($brand_name, $api_source = 'addrevenue') {
        // First try exact match
        $brand_term = get_term_by('name', $brand_name, 'brands');
        if ($brand_term && !is_wp_error($brand_term)) {
            $this->logger->debug("Found existing brand: {$brand_name}");
            return $brand_term;
        }
        
        // Try similar brand matching (preserves original logic)
        $similar_brand = $this->find_similar_brand($brand_name);
        if ($similar_brand) {
            $this->logger->debug("Found similar brand: {$similar_brand->name} for {$brand_name}");
            return $similar_brand;
        }
        
        // Create new brand
        return $this->create_brand($brand_name, $api_source);
    }
    
    /**
     * Find similar brand using string similarity
     * Preserves original logic from find_similar_brand()
     * 
     * @param string $advertiser_name Brand name to search for
     * @return WP_Term|false Similar brand term or false if not found
     */
    private function find_similar_brand($advertiser_name) {
        $normalized_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $advertiser_name));
        
        $similar_terms = get_terms([
            'taxonomy' => 'brands',
            'hide_empty' => false,
        ]);
        
        if (is_wp_error($similar_terms)) {
            $this->logger->error('Failed to get brands for similarity check', [
                'error' => $similar_terms->get_error_message()
            ]);
            return false;
        }
        
        $best_match = null;
        $highest_similarity = 0;
        
        foreach ($similar_terms as $term) {
            $normalized_term = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $term->name));
            
            similar_text($normalized_name, $normalized_term, $percent);
            
            // Exact match
            if ($normalized_name === $normalized_term) {
                return $term;
            }
            
            // Track best match
            if ($percent > $highest_similarity) {
                $highest_similarity = $percent;
                $best_match = $term;
            }
        }
        
        // Return if similarity is above 80%
        if ($highest_similarity > 80) {
            $this->logger->info("Found similar brand with {$highest_similarity}% similarity", [
                'search_name' => $advertiser_name,
                'found_name' => $best_match->name,
                'similarity' => $highest_similarity
            ]);
            return $best_match;
        }
        
        return false;
    }
    
    /**
     * Create new brand
     * 
     * @param string $brand_name Brand name
     * @param string $api_source API source
     * @return WP_Term|null Created brand term or null on failure
     */
    private function create_brand($brand_name, $api_source) {
        $slug = sanitize_title($brand_name . '-discountcodes');
        
        $brand_term = wp_insert_term($brand_name, 'brands', [
            'slug' => $slug
        ]);
        
        if (is_wp_error($brand_term)) {
            $this->logger->error('Failed to create brand term', [
                'brand_name' => $brand_name,
                'error' => $brand_term->get_error_message()
            ]);
            return null;
        }
        
        $term_id = $brand_term['term_id'];
        $this->logger->info("Created new brand: {$brand_name}", [
            'term_id' => $term_id,
            'api_source' => $api_source
        ]);
        
        // Add notification for new brand
        $this->add_new_brand_notification($brand_name, $term_id);
        
        // Store brand mapping
        if ($this->database) {
            $this->database->store_brand_mapping($term_id, $api_source, $brand_name);
        }
        
        return get_term($term_id, 'brands');
    }
    
    /**
     * Update brand data
     * Combines logic from update_brand() and update_brand_fields()
     * 
     * @param int $brand_term_id Brand term ID
     * @param array $brand_data Brand data from API
     * @param array $market_data Market-specific data
     * @param string $api_source API source
     */
    public function update_brand_data($brand_term_id, $brand_data, $market_data, $api_source = 'addrevenue') {
        $term_id = 'brands_' . $brand_term_id;
        
        $this->logger->debug("Updating brand data for term ID: {$brand_term_id}", [
            'api_source' => $api_source
        ]);
        
        // Update popular brand status
        $this->update_popular_status($term_id, $brand_data, $api_source);
        
        // Update featured image
        $this->update_featured_image($term_id, $brand_data, $brand_term_id, $api_source);
        
        // Update site link
        $this->update_site_link($term_id, $brand_data, $market_data, $api_source);
        
        // Update affiliate link
        $this->update_affiliate_link($term_id, $brand_data, $market_data, $api_source);
        
        // Update brand description
        $this->update_brand_description($brand_term_id, $brand_data, $api_source);
        
        // Update "Why We Love" section
        $this->update_why_we_love($term_id, $brand_data, $api_source);
        
        // Update API-specific fields
        $this->update_api_specific_fields($term_id, $brand_data, $api_source);
        
        $this->logger->debug("Brand data update completed for term ID: {$brand_term_id}");
    }
    
    /**
     * Update popular brand status
     * 
     * @param string $term_id ACF term ID
     * @param array $brand_data Brand data
     * @param string $api_source API source
     */
    private function update_popular_status($term_id, $brand_data, $api_source) {
        $update_popular = false;
        $brand_popular = null;
        
        if ($api_source === 'addrevenue') {
            if (isset($brand_data['featured'])) {
                $brand_popular = $brand_data['featured'] === true ? 1 : 0;
                $update_popular = true;
            }
        }
        
        if ($update_popular) {
            update_field('popular_brand', $brand_popular, $term_id);
            $this->logger->debug("Updated popular brand status", [
                'term_id' => $term_id,
                'popular' => $brand_popular ? 'Yes' : 'No'
            ]);
        }
    }
    
    /**
     * Update featured image
     * 
     * @param string $term_id ACF term ID
     * @param array $brand_data Brand data
     * @param int $brand_term_id WordPress term ID
     * @param string $api_source API source
     */
    private function update_featured_image($term_id, $brand_data, $brand_term_id, $api_source) {
        $current_image_id = get_field('featured_image', $term_id);
        
        if (!empty($current_image_id)) {
            $this->logger->debug("Featured image already exists for brand ID: {$brand_term_id}");
            return;
        }
        
        $image_url = '';
        if ($api_source === 'addrevenue' && isset($brand_data['logoImageFilename'])) {
            $image_url = esc_url($brand_data['logoImageFilename']);
        } elseif ($api_source === 'awin' && isset($brand_data['logoUrl'])) {
            $image_url = esc_url($brand_data['logoUrl']);
        }
        
        if (empty($image_url)) {
            $this->logger->debug("No image URL provided for brand ID: {$brand_term_id}");
            return;
        }
        
        $image_id = media_sideload_image($image_url, 0, null, 'id');
        if (is_wp_error($image_id)) {
            $this->logger->warning("Failed to sideload image for brand ID: {$brand_term_id}", [
                'image_url' => $image_url,
                'error' => $image_id->get_error_message()
            ]);
            return;
        }
        
        update_field('featured_image', $image_id, $term_id);
        update_post_meta($image_id, '_brand_logo_image', '1');
        
        $this->logger->info("Added featured image for brand ID: {$brand_term_id}", [
            'image_id' => $image_id,
            'image_url' => $image_url
        ]);
    }
    
    /**
     * Update site link
     * 
     * @param string $term_id ACF term ID
     * @param array $brand_data Brand data
     * @param array $market_data Market data
     * @param string $api_source API source
     */
    private function update_site_link($term_id, $brand_data, $market_data, $api_source) {
        if (!empty(get_field('site_link', $term_id))) {
            return; // Already has a site link
        }
        
        $brand_url = '';
        if ($api_source === 'addrevenue' && isset($market_data['url'])) {
            $brand_url = esc_url($market_data['url']);
        } elseif ($api_source === 'awin' && isset($brand_data['displayUrl'])) {
            $brand_url = esc_url($brand_data['displayUrl']);
        }
        
        if (!empty($brand_url)) {
            update_field('site_link', $brand_url, $term_id);
            $this->logger->debug("Updated site link for term: {$term_id}");
        }
    }
    
    /**
     * Update affiliate link
     * 
     * @param string $term_id ACF term ID
     * @param array $brand_data Brand data
     * @param array $market_data Market data
     * @param string $api_source API source
     */
    private function update_affiliate_link($term_id, $brand_data, $market_data, $api_source) {
        $current_affiliate_link = get_field('affiliate_link', $term_id);
        
        if (!empty($current_affiliate_link)) {
            return; // Already has an affiliate link
        }
        
        $new_affiliate_link = '';
        $brand_name = '';
        
        if ($api_source === 'addrevenue') {
            $new_affiliate_link = isset($brand_data['relation']['trackingLink']) ? $brand_data['relation']['trackingLink'] : '';
            $brand_name = isset($market_data['displayName']) ? $market_data['displayName'] : $brand_data['name'] ?? '';
        } elseif ($api_source === 'awin') {
            $new_affiliate_link = isset($brand_data['clickThroughUrl']) ? $brand_data['clickThroughUrl'] : '';
            $brand_name = isset($brand_data['name']) ? $brand_data['name'] : '';
        }
        
        if (empty($new_affiliate_link) || empty($brand_name)) {
            return;
        }
        
        // Create short affiliate link using YOURLS API
        $yourls_api = coupon_automation()->get_service('api')->get_api_handler('yourls');
        if ($yourls_api) {
            $short_url = $yourls_api->create_short_url($new_affiliate_link, $brand_name);
            if ($short_url) {
                update_field('affiliate_link', $short_url, $term_id);
                $this->logger->info("Created and updated short affiliate link", [
                    'term_id' => $term_id,
                    'brand_name' => $brand_name,
                    'short_url' => $short_url
                ]);
            } else {
                $this->logger->warning("Failed to create short affiliate link for: {$brand_name}");
            }
        }
    }
    
    /**
     * Update brand description
     * Preserves original logic from update_brand_fields()
     * 
     * @param int $brand_term_id WordPress term ID
     * @param array $brand_data Brand data
     * @param string $api_source API source
     */
    private function update_brand_description($brand_term_id, $brand_data, $api_source) {
        $brand_term = get_term($brand_term_id, 'brands');
        if (!$brand_term || is_wp_error($brand_term)) {
            $this->logger->error("Invalid brand term ID: {$brand_term_id}");
            return;
        }
        
        $current_description = $brand_term->description;
        $needs_update = false;
        $brand_name = $brand_term->name;
        
        // Case 1: Empty description
        if (empty(trim($current_description))) {
            $this->logger->debug("Empty description found for brand: {$brand_name}");
            $new_description = $this->generate_brand_description($brand_name, $brand_term_id, $brand_data);
            if ($new_description) {
                $current_description = $new_description;
                $needs_update = true;
            }
        }
        // Case 2: Description exists but no hashtags
        elseif (!preg_match('/#[a-zA-Z0-9-_]+/', $current_description)) {
            $this->logger->debug("Description without hashtags found for brand: {$brand_name}");
            $processed_description = $this->process_description_hashtags($current_description, $brand_name);
            if ($processed_description !== $current_description) {
                $current_description = $processed_description;
                $needs_update = true;
            }
        }
        
        // Update if needed
        if ($needs_update) {
            $this->logger->debug("Updating description for brand: {$brand_name}");
            remove_filter('pre_term_description', 'wp_filter_kses');
            $update_result = wp_update_term($brand_term_id, 'brands', [
                'description' => $current_description
            ]);
            add_filter('pre_term_description', 'wp_filter_kses');
            
            if (is_wp_error($update_result)) {
                $this->logger->error("Failed to update brand description", [
                    'brand_name' => $brand_name,
                    'error' => $update_result->get_error_message()
                ]);
            } else {
                $this->logger->info("Successfully updated description for brand: {$brand_name}");
            }
        }
    }
    
    /**
     * Update "Why We Love" section
     * 
     * @param string $term_id ACF term ID
     * @param array $brand_data Brand data
     * @param string $api_source API source
     */
    private function update_why_we_love($term_id, $brand_data, $api_source) {
        $why_we_love = get_field('why_we_love', $term_id);
        if (!empty($why_we_love)) {
            return; // Already has content
        }
        
        $brand_name = '';
        if ($api_source === 'addrevenue') {
            $brand_name = isset($brand_data['markets']['SE']['displayName']) ? $brand_data['markets']['SE']['displayName'] : ($brand_data['name'] ?? '');
        } elseif ($api_source === 'awin') {
            $brand_name = isset($brand_data['name']) ? $brand_data['name'] : '';
        }
        
        if (empty($brand_name)) {
            return;
        }
        
        $this->logger->debug("Generating why we love for brand: {$brand_name}");
        $new_why_we_love = $this->generate_why_we_love($brand_name, $brand_data);
        
        if ($new_why_we_love) {
            $updated = update_field('why_we_love', $new_why_we_love, $term_id);
            $this->logger->info("Updated why_we_love for brand: {$brand_name}", [
                'success' => $updated ? 'Yes' : 'No'
            ]);
        } else {
            $this->logger->warning("Failed to generate why we love content for brand: {$brand_name}");
        }
    }
    
    /**
     * Update API-specific fields
     * 
     * @param string $term_id ACF term ID
     * @param array $brand_data Brand data
     * @param string $api_source API source
     */
    private function update_api_specific_fields($term_id, $brand_data, $api_source) {
        if ($api_source === 'awin') {
            if (isset($brand_data['id'])) {
                update_field('awin_id', $brand_data['id'], $term_id);
            }
            if (isset($brand_data['primaryRegion']['name'])) {
                update_field('primary_region', $brand_data['primaryRegion']['name'], $term_id);
            }
            if (isset($brand_data['primarySector'])) {
                update_field('primary_sector', $brand_data['primarySector'], $term_id);
            }
        }
    }
    
    /**
     * Generate brand description using OpenAI
     * Preserves original logic from generate_brand_description()
     * 
     * @param string $brand_name Brand name
     * @param int $brand_term_id Brand term ID
     * @param array $brand_data Brand data
     * @return string|false Generated description or false on failure
     */
    private function generate_brand_description($brand_name, $brand_term_id, $brand_data = []) {
        $openai_api = coupon_automation()->get_service('api')->get_api_handler('openai');
        if (!$openai_api) {
            $this->logger->error('OpenAI API handler not available for brand description generation');
            return false;
        }
        
        // Create context from brand data
        $context = "Brand Name: {$brand_name}\n";
        if ($brand_data) {
            if (isset($brand_data['primarySector'])) {
                $context .= "Sector: " . $brand_data['primarySector'] . "\n";
            }
            if (isset($brand_data['strapLine'])) {
                $context .= "Tagline: " . $brand_data['strapLine'] . "\n";
            }
        }
        
        $prompt_template = $this->settings->get('prompts.brand_description_prompt', '');
        $prompt = $prompt_template . "\n\nContext:\n" . $context;
        
        $description = $openai_api->generate_text($prompt, [
            'max_tokens' => 1000,
            'temperature' => 0.7
        ]);
        
        if ($description) {
            // Process hashtags
            $description = $this->process_description_hashtags($description, $brand_name);
            $this->logger->info("Generated brand description for: {$brand_name}");
            return $description;
        }
        
        return false;
    }
    
    /**
     * Process description hashtags
     * Preserves original logic from process_description_hashtags()
     * 
     * @param string $content Description content
     * @param string $brand_name Brand name
     * @return string Processed content with hashtags
     */
    private function process_description_hashtags($content, $brand_name) {
        // Return as-is if hashtags already exist
        if (preg_match('/#[a-zA-Z0-9-_]+/', $content)) {
            return $content;
        }
        
        // Remove any existing hashtag paragraphs
        $content = preg_replace('/<p[^>]*>\s*#.*?<\/p>/', '', $content);
        
        // Generate hashtag line
        $hashtag_line = $this->generate_hashtag_line($brand_name);
        return trim($content) . "\n\n" . $hashtag_line;
    }
    
    /**
     * Generate hashtag line
     * Preserves original logic from generate_hashtag_line()
     * 
     * @param string $brand_name Brand name
     * @return string Hashtag line HTML
     */
    private function generate_hashtag_line($brand_name) {
        $slug = sanitize_title($brand_name);
        return sprintf(
            '<p style="text-align: left"><strong>#%1$s-discountcodes, #%1$s-savings, #%1$s-sales, #%1$s-bargains, #%1$s-vouchers, #%1$s-codes</strong></p>',
            $slug
        );
    }
    
    /**
     * Generate "Why We Love" content using OpenAI
     * Preserves original logic from generate_why_we_love()
     * 
     * @param string $brand_name Brand name
     * @param array $brand_data Brand data
     * @return string|false Generated content or false on failure
     */
    private function generate_why_we_love($brand_name, $brand_data = []) {
        $openai_api = coupon_automation()->get_service('api')->get_api_handler('openai');
        if (!$openai_api) {
            $this->logger->error('OpenAI API handler not available for why we love generation');
            return false;
        }
        
        $prompt_template = $this->settings->get('prompts.why_we_love_prompt', '');
        
        $content = $openai_api->generate_text($prompt_template, [
            'max_tokens' => 500,
            'temperature' => 0.7
        ]);
        
        if (!$content) {
            return false;
        }
        
        // Extract phrases and build list with images
        $phrases = $this->extract_phrases_from_content($content);
        return $this->build_why_we_love_list($phrases);
    }
    
    /**
     * Extract phrases from OpenAI content
     * 
     * @param string $content Generated content
     * @return array Array of phrases
     */
    private function extract_phrases_from_content($content) {
        // Try to extract from <li> tags first
        preg_match_all('/<li.*?>\s*.*?\/>([^<]+)<\/li>/', $content, $matches);
        $phrases = array_map('trim', $matches[1]);
        
        if (empty($phrases)) {
            // Try bullet point format
            preg_match_all('/[-•]\s*([^"\n]+)/', $content, $matches);
            $phrases = array_map('trim', $matches[1]);
        }
        
        // Limit to 3 words per phrase and ensure we have 3 phrases
        $phrases = array_map(function($phrase) {
            $words = preg_split('/\s+/', trim($phrase));
            return implode(' ', array_slice($words, 0, 3));
        }, $phrases);
        
        $phrases = array_slice($phrases, 0, 3);
        
        // Fill with defaults if needed
        while (count($phrases) < 3) {
            $default_phrases = ['Expert Service', 'Premium Quality', 'Fast Delivery'];
            $phrases[] = $default_phrases[count($phrases)];
        }
        
        return $phrases;
    }
    
    /**
     * Build "Why We Love" list with images
     * Preserves original image mapping logic
     * 
     * @param array $phrases Array of phrases
     * @return string HTML list with images
     */
    private function build_why_we_love_list($phrases) {
        $image_mappings = [
            'gift' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/gift-2.png',
                'keywords' => ['gift', 'present', 'surprise', 'unique', 'special', 'perfect']
            ],
            'tag' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/tag.png',
                'keywords' => ['price', 'value', 'affordable', 'saving', 'deal', 'budget']
            ],
            'free' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/free.png',
                'keywords' => ['free', 'bonus', 'extra', 'complimentary', 'gift']
            ],
            'piggy' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/piggybank.png',
                'keywords' => ['save', 'savings', 'discount', 'bargain', 'offer', 'cheap']
            ],
            'security' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/security-payment.png',
                'keywords' => ['secure', 'safe', 'protected', 'trusted', 'payment']
            ],
            'cyber' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/cyber-security.png',
                'keywords' => ['online', 'digital', 'cyber', 'electronic', 'virtual']
            ],
            'social' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/social-security-1.png',
                'keywords' => ['social', 'community', 'shared', 'connected', 'together']
            ],
            'certified' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/certified.png',
                'keywords' => ['certified', 'approved', 'verified', 'tested', 'authentic']
            ],
            'heart' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/healthy-heart.png',
                'keywords' => ['heart', 'loved', 'favorite', 'chosen', 'adored']
            ],
            'service' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/customer-service.png',
                'keywords' => ['customer service', 'customer', 'support', 'assistance', 'care', 'helpdesk', 'excellence']
            ],
            '24hours' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/24-hours.png',
                'keywords' => ['24/7', 'always', 'available', 'constant', 'nonstop']
            ],
            'recommended' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/recommended.png',
                'keywords' => ['recommended', 'endorsed', 'rated', 'reviewed', 'trusted']
            ],
            'fast' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/fast-delivery.png',
                'keywords' => ['fast', 'quick', 'rapid', 'speedy', 'prompt']
            ],
            'delivery' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/delivery.png',
                'keywords' => ['delivery', 'shipping', 'transport', 'sent', 'dispatch']
            ],
            'store' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/in-store-display.png',
                'keywords' => ['store', 'shop', 'retail', 'display', 'collection']
            ],
            'refund' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/refund.png',
                'keywords' => ['refund', 'return', 'money', 'guarantee', 'promise']
            ],
            'exchange' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/exchange.png',
                'keywords' => ['exchange', 'swap', 'trade', 'replace', 'change']
            ],
            'medal' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/medal.png',
                'keywords' => ['quality', 'premium', 'luxury', 'best', 'finest']
            ],
            'handmade' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/hand-made.png',
                'keywords' => ['handmade', 'crafted', 'custom', 'artisan', 'unique']
            ],
            'famous' => [
                'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/famous.png',
                'keywords' => ['famous', 'popular', 'known', 'celebrated', 'recognized']
            ]
        ];
        
        $used_images = [];
        $list_items = [];
        
        foreach ($phrases as $phrase) {
            $best_match = null;
            $best_score = 0;
            $phrase_lower = strtolower($phrase);
            
            // Find the best matching image based on keywords
            foreach ($image_mappings as $key => $mapping) {
                if (in_array($mapping['image'], $used_images)) {
                    continue;
                }
                
                $score = 0;
                foreach ($mapping['keywords'] as $keyword) {
                    if (strpos($phrase_lower, $keyword) !== false) {
                        $score += 5;
                    }
                    similar_text($phrase_lower, $keyword, $similarity);
                    $score += $similarity / 20;
                }
                
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_match = $mapping['image'];
                }
            }
            
            // If no good match found, pick a random unused image
            if (!$best_match || $best_score < 2) {
                $available_images = array_diff(
                    array_column($image_mappings, 'image'),
                    $used_images
                );
                if (!empty($available_images)) {
                    $best_match = array_values($available_images)[array_rand($available_images)];
                } else {
                    // Fallback to any image if all are used
                    $all_images = array_column($image_mappings, 'image');
                    $best_match = $all_images[array_rand($all_images)];
                }
            }
            
            $used_images[] = $best_match;
            $list_items[] = sprintf(
                '<li><img class="alignnone size-full" src="%s" alt="" width="64" height="64" /> %s</li>',
                esc_url($best_match),
                esc_html(ucwords($phrase))
            );
        }
        
        return "<ul>\n" . implode("\n", $list_items) . "\n</ul>";
    }
    
    /**
     * Add new brand notification
     * Preserves original functionality
     * 
     * @param string $brand_name Brand name
     * @param int $brand_id Brand term ID
     */
    private function add_new_brand_notification($brand_name, $brand_id) {
        $notifications = get_option('coupon_automation_notifications', []);
        $notifications[] = [
            'type' => 'brand',
            'name' => $brand_name,
            'id' => $brand_id,
            'time' => current_time('mysql')
        ];
        update_option('coupon_automation_notifications', array_slice($notifications, -50));
    }
    
    /**
     * Get brand statistics
     * 
     * @return array Brand statistics
     */
    public function get_brand_statistics() {
        $total_brands = wp_count_terms('brands', ['hide_empty' => false]);
        $brands_with_coupons = wp_count_terms('brands', ['hide_empty' => true]);
        
        return [
            'total_brands' => $total_brands,
            'brands_with_coupons' => $brands_with_coupons,
            'brands_without_coupons' => $total_brands - $brands_with_coupons,
        ];
    }
    
    /**
     * Get brands needing content update
     * 
     * @return array Brands that need content updates
     */
    public function get_brands_needing_updates() {
        $brands = get_terms([
            'taxonomy' => 'brands',
            'hide_empty' => false,
            'number' => 100
        ]);
        
        $needs_update = [];
        
        foreach ($brands as $brand) {
            $term_id = 'brands_' . $brand->term_id;
            $issues = [];
            
            // Check for missing description
            if (empty(trim($brand->description))) {
                $issues[] = 'description';
            }
            
            // Check for missing hashtags
            if (!empty($brand->description) && !preg_match('/#[a-zA-Z0-9-_]+/', $brand->description)) {
                $issues[] = 'hashtags';
            }
            
            // Check for missing "Why We Love"
            if (empty(get_field('why_we_love', $term_id))) {
                $issues[] = 'why_we_love';
            }
            
            // Check for missing image
            if (empty(get_field('featured_image', $term_id))) {
                $issues[] = 'featured_image';
            }
            
            if (!empty($issues)) {
                $needs_update[] = [
                    'brand' => $brand,
                    'issues' => $issues
                ];
            }
        }
        
        return $needs_update;
    }
}