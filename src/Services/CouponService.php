<?php

namespace CouponAutomation\Services;

use CouponAutomation\API\OpenAIAPI;
use CouponAutomation\Utils\Logger;

/**
 * Service class for coupon management
 */
class CouponService {
    
    private $openAI;
    private $logger;
    private $brandService;
    
    public function __construct() {
        $this->openAI = new OpenAIAPI();
        $this->logger = new Logger();
        $this->brandService = new BrandService();
    }
    
    /**
     * Process coupon data
     */
    public function processCoupon($couponData, $advertiserName, $apiSource = 'addrevenue') {
        // Extract coupon fields based on API source
        $coupon = $this->extractCouponFields($couponData, $apiSource);
        
        // Check if coupon already exists
        if ($this->couponExists($coupon['id'])) {
            $this->logger->info('Coupon already exists: ' . $coupon['id']);
            return false;
        }
        
        // Skip non-SE market coupons for AddRevenue
        if ($apiSource === 'addrevenue' && !isset($couponData['markets']['SE'])) {
            return false;
        }
        
        // Find or create brand
        $brand = $this->brandService->findOrCreateBrand($advertiserName);
        
        if (!$brand) {
            $this->logger->error('Failed to find or create brand: ' . $advertiserName);
            return false;
        }
        
        // Generate coupon title
        $title = $this->generateCouponTitle($coupon['description']);
        
        if (!$title) {
            $this->logger->error('Failed to generate title for coupon: ' . $coupon['id']);
            return false;
        }
        
        // Create coupon post
        return $this->createCouponPost($coupon, $title, $brand);
    }
    
    /**
     * Extract coupon fields based on API source
     */
    private function extractCouponFields($data, $apiSource) {
        $fields = [];
        
        if ($apiSource === 'addrevenue') {
            $fields = [
                'id' => $data['id'] ?? '',
                'description' => $data['description'] ?? '',
                'code' => $data['discountCode'] ?? '',
                'url' => $data['trackingLink'] ?? '',
                'valid_from' => $data['validFrom'] ?? '',
                'valid_to' => $data['validTo'] ?? '',
                'terms' => $data['terms'] ?? '',
                'type' => empty($data['discountCode']) ? 'Sale' : 'Code',
            ];
        } elseif ($apiSource === 'awin') {
            $fields = [
                'id' => $data['promotionId'] ?? '',
                'description' => $data['description'] ?? '',
                'code' => $data['voucher']['code'] ?? '',
                'url' => $data['urlTracking'] ?? '',
                'valid_from' => $data['startDate'] ?? '',
                'valid_to' => $data['endDate'] ?? '',
                'terms' => $data['terms'] ?? '',
                'type' => ($data['type'] === 'voucher' && !empty($data['voucher']['code'])) ? 'Code' : 'Sale',
            ];
        }
        
        $fields = array_map('sanitize_text_field', $fields);
        $fields['valid_from'] = $this->normalizeDate($fields['valid_from']);
        $fields['valid_to'] = $this->normalizeDate($fields['valid_to']);
        
        return $fields;
    }
    
    /**
     * Check if coupon exists
     */
    private function couponExists($couponId) {
        $existing = get_posts([
            'post_type' => 'coupons',
            'meta_key' => 'coupon_id',
            'meta_value' => $couponId,
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);
        
        return !empty($existing);
    }
    
    /**
     * Generate coupon title using OpenAI
     */
    private function generateCouponTitle($description) {
        $prompt = get_option('coupon_title_prompt');
        $fullPrompt = $prompt . ' ' . $description;
        
        $title = $this->openAI->generateContent($fullPrompt, 80);
        
        if ($title) {
            $title = trim(str_replace(['"', "'"], '', $title));
            return $title;
        }
        
        return 'Special Offer';
    }
    
    /**
     * Create coupon post
     */
    private function createCouponPost($coupon, $title, $brand) {
        $postData = [
            'post_title' => $title,
            'post_name' => sanitize_title($title . ' at ' . $brand->name),
            'post_status' => 'publish',
            'post_type' => 'coupons',
        ];
        
        // Check if coupon should be scheduled
        if (!empty($coupon['valid_from'])) {
            $validFrom = strtotime($coupon['valid_from']);
            if ($validFrom > current_time('timestamp')) {
                $postData['post_status'] = 'future';
                $postData['post_date'] = date('Y-m-d H:i:s', $validFrom);
            }
        }
        
        $postId = wp_insert_post($postData);
        
        if (is_wp_error($postId)) {
            $this->logger->error('Failed to create coupon post: ' . $postId->get_error_message());
            return false;
        }
        
        // Set post meta
        $this->setCouponMeta($postId, $coupon, $brand);
        
        // Set brand taxonomy
        wp_set_post_terms($postId, [$brand->term_id], 'brands', false);
        
        // Copy brand categories to coupon
        $this->copyCouponCategories($postId, $brand->term_id);
        
        $this->logger->info('Created coupon: ' . $title . ' (ID: ' . $postId . ')');
        
        return $postId;
    }
    
    /**
     * Set coupon meta fields
     */
    private function setCouponMeta($postId, $coupon, $brand) {
        update_post_meta($postId, 'coupon_id', $coupon['id']);
        update_post_meta($postId, 'code', $coupon['code']);
        update_post_meta($postId, 'redirect_url', $coupon['url']);
        update_post_meta($postId, 'valid_untill', $coupon['valid_to']);
        update_post_meta($postId, 'coupon_type', $coupon['type']);
        update_post_meta($postId, 'advertiser_name', $brand->name);
        
        // Process and set terms
        $termsHtml = $this->processTerms($coupon['terms']);
        update_field('show_details_button', $termsHtml, $postId);
    }
    
    /**
     * Process coupon terms
     */
    private function processTerms($terms) {
        if (empty($terms)) {
            return $this->getFallbackTerms();
        }
        
        // Translate/process terms using OpenAI
        $prompt = get_option('description_prompt');
        $processed = $this->openAI->generateContent($prompt . ' ' . $terms, 150, 0.4);
        
        if (!$processed) {
            return $this->getFallbackTerms();
        }
        
        // Format as list
        $termsArray = array_filter(array_map('trim', explode("\n", $processed)));
        $termsArray = array_slice($termsArray, 0, 3);
        
        $listItems = array_map(function($term) {
            $term = ltrim($term, '•-. ');
            if (!preg_match('/[.!?]$/', $term)) {
                $term .= '.';
            }
            return '<li>' . esc_html($term) . '</li>';
        }, $termsArray);
        
        return '<ul>' . implode("\n", $listItems) . '</ul>';
    }
    
    /**
     * Normalize date values to Y-m-d for consistent comparisons
     */
    private function normalizeDate($date) {
        if (empty($date)) {
            return '';
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        return wp_date('Y-m-d', $timestamp);
    }

    /**
     * Get fallback terms
     */
    private function getFallbackTerms() {
        $fallback = get_option('fallback_terms');
        
        if (empty($fallback)) {
            return '<ul><li>Terms and conditions apply.</li></ul>';
        }
        
        $termsArray = array_map('trim', explode("\n", $fallback));
        $listItems = array_map(function($term) {
            if (!preg_match('/[.!?]$/', $term)) {
                $term .= '.';
            }
            return '<li>' . esc_html($term) . '</li>';
        }, $termsArray);
        
        return '<ul>' . implode("\n", $listItems) . '</ul>';
    }
    
    /**
     * Copy coupon categories from brand
     */
    private function copyCouponCategories($postId, $brandTermId) {
        $categories = get_field('coupon_categories', 'brands_' . $brandTermId);
        
        if (!empty($categories)) {
            wp_set_post_terms($postId, $categories, 'coupon_categories');
        }
    }
    
    /**
     * Purge expired coupons
     */
    public function purgeExpiredCoupons() {
        $today = wp_date('Y-m-d');
        
        $expiredCoupons = get_posts([
            'post_type' => 'coupons',
            'posts_per_page' => -1,
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
        ]);
        
        $count = 0;
        
        foreach ($expiredCoupons as $coupon) {
            wp_delete_post($coupon->ID, true);
            $count++;
        }
        
        $this->logger->info('Purged ' . $count . ' expired coupons');
        
        return $count;
    }
}