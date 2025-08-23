<?php

namespace CouponAutomation\Services;

use CouponAutomation\API\AddRevenueAPI;
use CouponAutomation\API\AwinAPI;
use CouponAutomation\Utils\Logger;
use CouponAutomation\Utils\NotificationManager;

/**
 * Main data processing service with enhanced error handling
 */
class DataProcessor {
    
    private $addRevenueAPI;
    private $awinAPI;
    private $brandService;
    private $couponService;
    private $logger;
    private $notifications;
    private $batchSize = 10;
    private $stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
    
    public function __construct() {
        $this->addRevenueAPI = new AddRevenueAPI();
        $this->awinAPI = new AwinAPI();
        $this->brandService = new BrandService();
        $this->couponService = new CouponService();
        $this->logger = new Logger();
        $this->notifications = new NotificationManager();
    }
    
    /**
     * Start processing data from APIs with status tracking
     */
    public function startProcessing() {
        // Check if already running
        if (get_transient('fetch_process_running')) {
            $this->logger->warning('Processing already running');
            return false;
        }
        
        // Set running flag and status
        set_transient('fetch_process_running', true, 30 * MINUTE_IN_SECONDS);
        update_option('coupon_automation_sync_status', 'running');
        update_option('coupon_automation_last_sync_start', current_time('timestamp'));
        
        // Log activity
        $this->logger->activity('Sync started', 'start');
        
        try {
            // Reset stats
            $this->stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
            
            // Process AddRevenue data
            $this->processAddRevenue();
            
            // Process AWIN data  
            $this->processAwin();
            
            // Update status to success
            update_option('coupon_automation_sync_status', 'success');
            update_option('coupon_automation_last_sync', current_time('timestamp'));
            update_option('coupon_automation_last_sync_stats', $this->stats);
            
            // Log completion
            $this->logger->activity(
                sprintf('Sync completed - Processed: %d, Failed: %d, Skipped: %d', 
                    $this->stats['processed'], 
                    $this->stats['failed'], 
                    $this->stats['skipped']
                ), 
                'complete'
            );
            
            // Schedule next run
            $this->scheduleNextRun();
            
        } catch (\Exception $e) {
            $this->logger->error('Processing failed: ' . $e->getMessage());
            update_option('coupon_automation_sync_status', 'failed');
            update_option('coupon_automation_last_error', $e->getMessage());
            delete_transient('fetch_process_running');
            return false;
        }
        
        delete_transient('fetch_process_running');
        return true;
    }
    
    /**
     * Process AddRevenue data with error handling per item
     */
    private function processAddRevenue() {
        $this->logger->info('Starting AddRevenue processing');
        $this->logger->activity('Processing AddRevenue advertisers', 'info');
        
        // Check if API is configured
        if (empty(get_option('addrevenue_api_key'))) {
            $this->logger->warning('AddRevenue API not configured');
            $this->stats['skipped']++;
            return;
        }
        
        try {
            // Get advertisers and campaigns
            $advertisers = $this->addRevenueAPI->getAdvertisers();
            $campaigns = $this->addRevenueAPI->getCampaigns();
            
            if (empty($advertisers)) {
                $this->logger->warning('No AddRevenue advertisers found');
                return;
            }
            
            foreach ($advertisers as $advertiser) {
                // Check for stop request
                if (get_option('coupon_automation_stop_requested', false)) {
                    $this->logger->info('Processing stopped by user');
                    break;
                }
                
                try {
                    // Process only SE market
                    if (!isset($advertiser['markets']['SE'])) {
                        $this->stats['skipped']++;
                        continue;
                    }
                    
                    $marketData = $advertiser['markets']['SE'];
                    $brandName = $marketData['displayName'];
                    
                    // Find campaigns for this advertiser
                    $advertiserCampaigns = array_filter($campaigns, function($campaign) use ($brandName) {
                        return $campaign['advertiserName'] === $brandName && isset($campaign['markets']['SE']);
                    });
                    
                    if (empty($advertiserCampaigns)) {
                        $this->stats['skipped']++;
                        continue;
                    }
                    
                    // Process brand
                    $brand = $this->brandService->findOrCreateBrand($brandName);
                    
                    if ($brand) {
                        // Update brand meta
                        $this->brandService->updateBrandMeta($brand->term_id, $advertiser, 'addrevenue');
                        
                        // Process coupons
                        foreach ($advertiserCampaigns as $campaign) {
                            try {
                                $couponId = $this->couponService->processCoupon($campaign, $brandName, 'addrevenue');
                                
                                if ($couponId) {
                                    $this->stats['processed']++;
                                    $this->notifications->add('coupon', [
                                        'title' => get_the_title($couponId),
                                        'brand' => $brandName,
                                        'id' => $couponId
                                    ]);
                                }
                            } catch (\Exception $e) {
                                $this->logger->error('Failed to process coupon for ' . $brandName . ': ' . $e->getMessage());
                                $this->stats['failed']++;
                            }
                        }
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->error('Failed to process AddRevenue advertiser: ' . $e->getMessage());
                    $this->stats['failed']++;
                    continue; // Skip to next advertiser
                }
                
                // Process in batches
                if ($this->stats['processed'] % $this->batchSize === 0) {
                    sleep(1); // Brief pause between batches
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('AddRevenue API error: ' . $e->getMessage());
            $this->logger->activity('AddRevenue processing failed: ' . $e->getMessage(), 'error');
        }
        
        $this->logger->info(sprintf("AddRevenue complete - Processed: %d, Failed: %d", 
            $this->stats['processed'], $this->stats['failed']));
    }
    
    /**
     * Process AWIN data with error handling per item
     */
    private function processAwin() {
        $this->logger->info('Starting AWIN processing');
        $this->logger->activity('Processing AWIN promotions', 'info');
        
        // Check if API is configured
        if (empty(get_option('awin_api_token')) || empty(get_option('awin_publisher_id'))) {
            $this->logger->warning('AWIN API not configured');
            $this->stats['skipped']++;
            return;
        }
        
        try {
            // Get promotions
            $promotions = $this->awinAPI->getPromotions();
            
            if (empty($promotions)) {
                $this->logger->warning('No AWIN promotions found');
                return;
            }
            
            $processedAdvertisers = [];
            
            foreach ($promotions as $promotion) {
                // Check for stop request
                if (get_option('coupon_automation_stop_requested', false)) {
                    $this->logger->info('Processing stopped by user');
                    break;
                }
                
                try {
                    $advertiserId = $promotion['advertiser']['id'];
                    $advertiserName = $promotion['advertiser']['name'];
                    
                    // Get programme details if not already processed
                    if (!isset($processedAdvertisers[$advertiserId])) {
                        try {
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
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to get AWIN programme details for ' . $advertiserId . ': ' . $e->getMessage());
                            $this->stats['failed']++;
                            continue;
                        }
                    }
                    
                    // Process coupon
                    if (isset($processedAdvertisers[$advertiserId])) {
                        try {
                            $couponId = $this->couponService->processCoupon(
                                $promotion,
                                $processedAdvertisers[$advertiserId]->name,
                                'awin'
                            );
                            
                            if ($couponId) {
                                $this->stats['processed']++;
                                $this->notifications->add('coupon', [
                                    'title' => get_the_title($couponId),
                                    'brand' => $processedAdvertisers[$advertiserId]->name,
                                    'id' => $couponId
                                ]);
                            }
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to process AWIN coupon: ' . $e->getMessage());
                            $this->stats['failed']++;
                        }
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->error('Failed to process AWIN promotion: ' . $e->getMessage());
                    $this->stats['failed']++;
                    continue; // Skip to next promotion
                }
                
                // Process in batches
                if ($this->stats['processed'] % $this->batchSize === 0) {
                    sleep(1); // Brief pause between batches
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('AWIN API error: ' . $e->getMessage());
            $this->logger->activity('AWIN processing failed: ' . $e->getMessage(), 'error');
        }
        
        $this->logger->info(sprintf("AWIN complete - Processed: %d, Failed: %d", 
            $this->stats['processed'], $this->stats['failed']));
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