<?php

namespace CouponAutomation\Admin;

use CouponAutomation\Services\DataProcessor;
use CouponAutomation\Services\CouponService;
use CouponAutomation\Services\BrandService;
use CouponAutomation\API\AddRevenueAPI;
use CouponAutomation\API\AwinAPI;
use CouponAutomation\API\OpenAIAPI;
use CouponAutomation\API\YourlsAPI;

/**
 * Handle AJAX requests
 */
class AjaxHandler {
    
    /**
     * Handle fetch coupons request
     */
    public function handleFetchCoupons() {
        check_ajax_referer('coupon_automation_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Start processing
        $processor = new DataProcessor();
        $result = $processor->startProcessing();
        
        if ($result) {
            wp_send_json_success('Processing started successfully');
        } else {
            wp_send_json_error('Failed to start processing');
        }
    }
    
    /**
     * Handle stop automation
     */
    public function handleStopAutomation() {
        check_ajax_referer('coupon_automation_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        update_option('coupon_automation_stop_requested', true);
        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        
        wp_send_json_success('Automation stopped');
    }
    
    /**
     * Handle clear flags
     */
    public function handleClearFlags() {
        check_ajax_referer('coupon_automation_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');
        delete_option('coupon_automation_stop_requested');
        
        wp_send_json_success('Flags and transients cleared');
    }
    
    /**
     * Handle purge expired coupons
     */
    public function handlePurgeExpired() {
        check_ajax_referer('coupon_automation_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $couponService = new CouponService();
        $count = $couponService->purgeExpiredCoupons();
        
        wp_send_json_success("Purged $count expired coupons");
    }
    
    /**
     * Handle brand population batch
     */
    public function handleBrandPopulation() {
        check_ajax_referer('coupon_automation_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $batchSize = 5;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        
        $brands = get_terms([
            'taxonomy' => 'brands',
            'hide_empty' => false,
            'number' => $batchSize,
            'offset' => $offset
        ]);
        
        $totalBrands = wp_count_terms('brands', ['hide_empty' => false]);
        $processed = 0;
        $log = [];
        
        $brandService = new BrandService();
        
        foreach ($brands as $brand) {
            $updates = [];
            
            // Process brand
            $brandService->updateBrandMeta($brand->term_id, []);
            
            $processed++;
            $log[] = sprintf('Processed brand: %s (ID: %d)', $brand->name, $brand->term_id);
        }
        
        wp_send_json_success([
            'processed' => $processed,
            'total' => $totalBrands,
            'log' => $log
        ]);
    }
    
    /**
     * Handle API connection test
     */
    public function handleTestConnection() {
        check_ajax_referer('coupon_automation_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $api = isset($_POST['api']) ? sanitize_text_field($_POST['api']) : '';
        $result = false;
        $message = '';
        
        switch ($api) {
            case 'addrevenue':
                $api = new AddRevenueAPI();
                $result = $api->testConnection();
                $message = $result ? 'AddRevenue API connected successfully' : 'Failed to connect to AddRevenue API';
                break;
                
            case 'awin':
                $api = new AwinAPI();
                $result = $api->testConnection();
                $message = $result ? 'AWIN API connected successfully' : 'Failed to connect to AWIN API';
                break;
                
            case 'openai':
                $api = new OpenAIAPI();
                $result = $api->testConnection();
                $message = $result ? 'OpenAI API connected successfully' : 'Failed to connect to OpenAI API';
                break;
                
            case 'yourls':
                $api = new YourlsAPI();
                $result = $api->testConnection();
                $message = $result ? 'YOURLS API connected successfully' : 'Failed to connect to YOURLS API';
                break;
                
            default:
                $message = 'Invalid API specified';
        }
        
        if ($result) {
            wp_send_json_success($message);
        } else {
            wp_send_json_error($message);
        }
    }
    
    /**
     * Scheduled fetch handler
     */
    public function scheduledFetch() {
        $processor = new DataProcessor();
        $processor->startProcessing();
    }
}