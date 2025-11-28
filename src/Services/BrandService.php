<?php

namespace CouponAutomation\Services;

use CouponAutomation\API\OpenAIAPI;
use CouponAutomation\API\YourlsAPI;
use CouponAutomation\Utils\Logger;
use CouponAutomation\Utils\NotificationManager;

/**
 * Service class for brand management
 */
class BrandService {
    
    private $openAI;
    private $yourls;
    private $logger;
    private $notifications;

    public function __construct() {
        $this->openAI = new OpenAIAPI();
        $this->yourls = new YourlsAPI();
        $this->logger = new Logger();
        $this->notifications = new NotificationManager();
    }

    /**
     * Find or create brand
     */
    public function findOrCreateBrand($brandName, $source = 'addrevenue') {
        // Clean brand name
        $brandName = $this->cleanBrandName($brandName);
        
        // Try exact match first
        $brand = get_term_by('name', $brandName, 'brands');
        if ($brand) {
            return $brand;
        }
        
        // Try similarity match
        $brand = $this->findSimilarBrand($brandName);
        if ($brand) {
            return $brand;
        }
        
        // Create new brand
        return $this->createBrand($brandName, $source);
    }

    /**
     * Clean brand name from country codes
     */
    public function cleanBrandName($brandName) {
        $codes = ['EU', 'UK', 'US', 'CA', 'AU', 'NZ', 'SE', 'NO', 'DK', 'FI'];
        
        foreach ($codes as $code) {
            $brandName = preg_replace('/\s+' . $code . '\s*$/i', '', $brandName);
        }
        
        return trim($brandName);
    }
    
    /**
     * Find similar brand by name
     */
    private function findSimilarBrand($brandName) {
        $terms = get_terms([
            'taxonomy' => 'brands',
            'hide_empty' => false,
        ]);
        
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $brandName));
        $bestMatch = null;
        $highestSimilarity = 0;
        
        foreach ($terms as $term) {
            $termNormalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $term->name));
            
            if ($normalized === $termNormalized) {
                return $term;
            }
            
            similar_text($normalized, $termNormalized, $percent);
            
            if ($percent > $highestSimilarity && $percent > 80) {
                $highestSimilarity = $percent;
                $bestMatch = $term;
            }
        }
        
        return $bestMatch;
    }
    
    /**
     * Create new brand
     */
    private function createBrand($brandName, $source = 'addrevenue') {
        $result = wp_insert_term($brandName, 'brands', [
            'slug' => sanitize_title($brandName . '-discountcodes')
        ]);
        
        if (is_wp_error($result)) {
            $this->logger->error('Failed to create brand: ' . $result->get_error_message());
            return false;
        }
        
        $term = get_term($result['term_id'], 'brands');
        $this->logger->info('Created new brand: ' . $brandName);

        // Surface brand creation in admin notifications
        $this->notifications->add('brand', [
            'name' => $brandName,
            'term_id' => $term->term_id ?? null,
            'source' => $source
        ]);

        return $term;
    }
    
    /**
     * Update brand meta fields
     */
    public function updateBrandMeta($brandTermId, $data, $apiSource = 'addrevenue') {
        $termId = 'brands_' . $brandTermId;
        
        // Update popular status
        if (isset($data['featured'])) {
            update_field('popular_brand', $data['featured'] ? 1 : 0, $termId);
        }
        
        // Update featured image
        $this->updateBrandImage($termId, $data, $apiSource);
        
        // Update site link
        $this->updateSiteLink($termId, $data, $apiSource);
        
        // Update affiliate link
        $this->updateAffiliateLink($termId, $data, $apiSource);
        
        // Generate and update description if needed
        $this->updateBrandDescription($brandTermId, $data);
        
        // Generate and update "Why We Love" section
        $this->updateWhyWeLove($termId, $data);
    }
    
    /**
     * Update brand image
     */
    private function updateBrandImage($termId, $data, $apiSource) {
        $currentImageId = get_field('featured_image', $termId);
        
        if (!empty($currentImageId)) {
            return; // Image already exists
        }
        
        $imageUrl = '';
        if ($apiSource === 'addrevenue') {
            $imageUrl = isset($data['logoImageFilename']) ? esc_url($data['logoImageFilename']) : '';
        } elseif ($apiSource === 'awin') {
            $imageUrl = isset($data['logoUrl']) ? esc_url($data['logoUrl']) : '';
        }
        
        if (empty($imageUrl)) {
            return;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $imageId = media_sideload_image($imageUrl, 0, null, 'id');
        
        if (!is_wp_error($imageId)) {
            update_field('featured_image', $imageId, $termId);
            update_post_meta($imageId, '_brand_logo_image', '1');
        }
    }
    
    /**
     * Update site link
     */
    private function updateSiteLink($termId, $data, $apiSource) {
        if (!empty(get_field('site_link', $termId))) {
            return;
        }
        
        $siteUrl = '';
        if ($apiSource === 'addrevenue' && isset($data['markets']['SE']['url'])) {
            $siteUrl = esc_url($data['markets']['SE']['url']);
        } elseif ($apiSource === 'awin' && isset($data['displayUrl'])) {
            $siteUrl = esc_url($data['displayUrl']);
        }
        
        if (!empty($siteUrl)) {
            update_field('site_link', $siteUrl, $termId);
        }
    }
    
    /**
     * Update affiliate link
     */
    private function updateAffiliateLink($termId, $data, $apiSource) {
        $currentLink = get_field('affiliate_link', $termId);
        
        if (!empty($currentLink)) {
            return;
        }
        
        $affiliateLink = '';
        $brandName = '';
        
        if ($apiSource === 'addrevenue') {
            $affiliateLink = $data['relation']['trackingLink'] ?? '';
            $brandName = $data['markets']['SE']['displayName'] ?? '';
        } elseif ($apiSource === 'awin') {
            $affiliateLink = $data['clickThroughUrl'] ?? '';
            $brandName = $data['name'] ?? '';
        }
        
        if (empty($affiliateLink) || empty($brandName)) {
            return;
        }
        
        $shortUrl = $this->yourls->createShortUrl($affiliateLink, $brandName);
        
        if ($shortUrl) {
            update_field('affiliate_link', $shortUrl, $termId);
        }
    }
    
    /**
     * Update brand description
     */
    private function updateBrandDescription($brandTermId, $data) {
        $term = get_term($brandTermId, 'brands');
        
        if (!$term || is_wp_error($term)) {
            return;
        }
        
        $currentDescription = $term->description;
        $needsUpdate = false;
        $brandName = $term->name;
        
        // Generate description if empty
        if (empty(trim($currentDescription))) {
            $prompt = get_option('brand_description_prompt');
            $context = "Brand Name: $brandName\n";
            
            if (isset($data['primarySector'])) {
                $context .= "Sector: " . $data['primarySector'] . "\n";
            }
            
            $newDescription = $this->openAI->generateContent($prompt . "\n\n" . $context, 1000);
            
            if ($newDescription) {
                $currentDescription = wp_kses($newDescription, $this->getAllowedHtmlTags());
                $needsUpdate = true;
            }
        }
        
        // Add hashtags if missing
        if (!empty($currentDescription) && !preg_match('/#[a-zA-Z0-9-_]+/', $currentDescription)) {
            $hashtags = $this->generateHashtags($brandName);
            $currentDescription = trim($currentDescription) . "\n\n" . $hashtags;
            $needsUpdate = true;
        }
        
        if ($needsUpdate) {
            remove_filter('pre_term_description', 'wp_filter_kses');
            wp_update_term($brandTermId, 'brands', ['description' => $currentDescription]);
            add_filter('pre_term_description', 'wp_filter_kses');
        }
    }
    
    /**
     * Generate hashtags for brand
     */
    private function generateHashtags($brandName) {
        $slug = sanitize_title($brandName);
        return sprintf(
            '<p style="text-align: left"><strong>#%1$s-discountcodes, #%1$s-savings, #%1$s-sales, #%1$s-bargains, #%1$s-vouchers, #%1$s-codes</strong></p>',
            $slug
        );
    }
    
    /**
     * Update "Why We Love" section
     */
    private function updateWhyWeLove($termId, $data) {
        $existing = get_field('why_we_love', $termId);
        
        if (!empty($existing)) {
            return;
        }
        
        $prompt = get_option('why_we_love_prompt');
        $content = $this->openAI->generateContent($prompt, 500);
        // Log raw AI output for troubleshooting formatting issues
        error_log('[CA] WhyWeLove raw: ' . ($content ?? 'EMPTY'));
        
        if (!$content) {
            return;
        }
        
        $formatted = $this->formatWhyWeLove($content);
        // Log formatted HTML to see what is stored
        error_log('[CA] WhyWeLove formatted: ' . $formatted);
        update_field('why_we_love', $formatted, $termId);
    }
    
    /**
     * Format "Why We Love" content with images
     */
    private function formatWhyWeLove($content) {
        // Extract phrases from content
        preg_match_all('/[-•]\s*([^"\n]+)/', $content, $matches);
        $phrases = array_map('trim', $matches[1]);
        
        // Limit to 3 phrases
        $phrases = array_slice($phrases, 0, 3);
        
        // Image mappings
        $images = [
            'gift' => 'https://dev.adealsweden.com/wp-content/uploads/2023/11/gift-2.png',
            'tag' => 'https://dev.adealsweden.com/wp-content/uploads/2023/11/tag.png',
            'free' => 'https://dev.adealsweden.com/wp-content/uploads/2023/11/free.png',
            'piggy' => 'https://dev.adealsweden.com/wp-content/uploads/2023/11/piggybank.png',
            'security' => 'https://dev.adealsweden.com/wp-content/uploads/2023/11/security-payment.png',
            'medal' => 'https://dev.adealsweden.com/wp-content/uploads/2023/11/medal.png',
        ];
        
        $availableImages = array_values($images);
        $listItems = [];
        
        foreach ($phrases as $index => $phrase) {
            $image = $availableImages[$index % count($availableImages)];
            $listItems[] = sprintf(
                '<li><img class="alignnone size-full" src="%s" alt="" width="64" height="64" /> %s</li>',
                esc_url($image),
                esc_html(ucwords($phrase))
            );
        }
        
        return "<ul>\n" . implode("\n", $listItems) . "\n</ul>";
    }
    
    /**
     * Get allowed HTML tags for content
     */
    private function getAllowedHtmlTags() {
        return [
            'h4' => ['style' => [], 'class' => []],
            'p' => ['style' => [], 'class' => []],
            'strong' => [],
            'em' => [],
            'ul' => [],
            'li' => [],
            'br' => [],
        ];
    }
}
