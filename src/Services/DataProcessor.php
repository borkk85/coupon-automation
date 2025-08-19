<?php

namespace CouponAutomation\Services;

use CouponAutomation\API\AddRevenueAPI;
use CouponAutomation\API\AwinAPI;
use CouponAutomation\Utils\Logger;
use CouponAutomation\Utils\NotificationManager;

/**
 * Main data processing service
 */
class DataProcessor {
    
    private $addRevenueAPI;
    private $awinAPI;
    private $brandService;
    private $couponService;
    private $logger;
    private $notifications;
    private $batchSize = 10;
    
    public function __construct() {
        $this->addRevenueAPI = new AddRevenueAPI();
        $this->awinAPI = new AwinAPI();
        $this->brandService = new BrandService();
        $this->couponService = new CouponService();
        $this->logger = new Logger();
        $this->notifications = new NotificationManager();
    }
    
    /**
     * Start processing data from APIs
     */
    public function startProcessing() {
        // Check if already running
        if (get_transient('fetch_process_running')) {
            $this->logger->warning('Processing already running');
            return false;
        }
        
        // Set running flag
        set_transient('fetch_process_running', true, 30 * MINUTE_IN_SECONDS);
        
        try {
            // Process AddRevenue data
            $this->processAddRevenue();
            
            // Process AWIN data
            $this->processAwin();
            
            // Schedule next run
            $this->scheduleNextRun();
            
        } catch (\Exception $e) {
            $this->logger->error('Processing failed: ' . $e->getMessage());
            delete_transient('fetch_process_running');
            return false;
        }
        
        delete_transient('fetch_process_running');
        return true;
    }
    
    /**
     * Process AddRevenue data
     */
    private function processAddRevenue() {
        $this->logger->info('Starting AddRevenue processing');
        
        // Check if API is configured
        if (empty(get_option('addrevenue_api_token'))) {
            $this->logger->warning('AddRevenue API not configured');
            return;
        }
        
        // Get advertisers and campaigns
        $advertisers = $this->addRevenueAPI->getAdvertisers();
        $campaigns = $this->addRevenueAPI->getCampaigns();
        
        if (empty($advertisers)) {
            $this->logger->warning('No AddRevenue advertisers found');
            return;
        }
        
        $processed = 0;
        
        foreach ($advertisers as $advertiser) {
            // Check for stop request
            if (get_option('coupon_automation_stop_requested', false)) {
                $this->logger->info('Processing stopped by user');
                break;
            }
            
            // Process only SE market
            if (!isset($advertiser['markets']['SE'])) {
                continue;
            }
            
            $marketData = $advertiser['markets']['SE'];
            $brandName = $marketData['displayName'];
            
            // Find campaigns for this advertiser
            $advertiserCampaigns = array_filter($campaigns, function($campaign) use ($brandName) {
                return $campaign['advertiserName'] === $brandName && isset($campaign['markets']['SE']);
            });
            
            if (empty($advertiserCampaigns)) {
                continue;
            }
            
            // Process brand
            $brand = $this->brandService->findOrCreateBrand($brandName);
            
            if ($brand) {
                // Update brand meta
                $this->brandService->updateBrandMeta($brand->term_id, $advertiser, 'addrevenue');
                
                // Process coupons
                foreach ($advertiserCampaigns as $campaign) {
                    $couponId = $this->couponService->processCoupon($campaign, $brandName, 'addrevenue');
                    
                    if ($couponId) {
                        $this->notifications->add('coupon', [
                            'title' => get_the_title($couponId),
                            'brand' => $brandName,
                            'id' => $couponId
                        ]);
                    }
                }
                
                $processed++;
            }
            
            // Process in batches
            if ($processed % $this->batchSize === 0) {
                sleep(1); // Brief pause between batches
            }
        }
        
        $this->logger->info("Processed $processed AddRevenue advertisers");
    }
    
    /**
     * Process AWIN data
     */
    private function processAwin() {
        $this->logger->info('Starting AWIN processing');
        
        // Check if API is configured
        if (empty(get_option('awin_api_token')) || empty(get_option('awin_publisher_id'))) {
            $this->logger->warning('AWIN API not configured');
            return;
        }
        
        // Get promotions
        $promotions = $this->awinAPI->getPromotions();
        
        if (empty($promotions)) {
            $this->logger->warning('No AWIN promotions found');
            return;
        }
        
        $processed = 0;
        $processedAdvertisers = [];
        
        foreach ($promotions as $promotion) {
            // Check for stop request
            if (get_option('coupon_automation_stop_requested', false)) {
                $this->logger->info('Processing stopped by user');
                break;
            }
            
            $advertiserId = $promotion['advertiser']['id'];
            $advertiserName = $promotion['advertiser']['name'];
            
            // Get programme details if not already processed
            if (!isset($processedAdvertisers[$advertiserId])) {
                $programmeDetails = $this->awinAPI->getProgrammeDetails($advertiserId);
                
                if ($programmeDetails && isset($programmeDetails['programmeInfo'])) {
                    $brandData = $programmeDetails['programmeInfo'];
                    $brandName = $this->brandService->cleanBrandName($brandData['name']);
                    
                    // Process brand
                    $brand = $this->brandService->findOrCreateBrand($brandName);
                    
                    if ($brand) {
                        $this->brandService->updateBrandMeta($brand->term_id, $brandData, 'awin');
                        $processedAdvertisers[$advertiserId] = $brand;
                    }
                }
            }
            
            // Process coupon
            if (isset($processedAdvertisers[$advertiserId])) {
                $couponId = $this->couponService->processCoupon(
                    $promotion,
                    $processedAdvertisers[$advertiserId]->name,
                    'awin'
                );
                
                if ($couponId) {
                    $this->notifications->add('coupon', [
                        'title' => get_the_title($couponId),
                        'brand' => $processedAdvertisers[$advertiserId]->name,
                        'id' => $couponId
                    ]);
                }
                
                $processed++;
            }
            
            // Process in batches
            if ($processed % $this->batchSize === 0) {
                sleep(1); // Brief pause between batches
            }
        }
        
        $this->logger->info("Processed $processed AWIN promotions");
    }
    
    /**
     * Schedule next run
     */
    private function scheduleNextRun() {
        if (!wp_next_scheduled('coupon_automation_daily_fetch')) {
            wp_schedule_event(
                strtotime('tomorrow 3:00am'),
                'daily',
                'coupon_automation_daily_fetch'
            );
            
            $this->logger->info('Scheduled next run for tomorrow 3:00 AM');
        }
    }
    
    /**
     * Clear all processing flags
     */
    public function clearFlags() {
        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        delete_option('coupon_automation_stop_requested');
        
        // Clear API caches
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');
        
        $this->logger->info('All processing flags cleared');
    }
}