<?php

namespace CouponAutomation\Services;

use CouponAutomation\API\AddRevenueAPI;
use CouponAutomation\API\AwinAPI;
use CouponAutomation\Utils\Logger;
use CouponAutomation\Utils\NotificationManager;

/**
 * Main data processing service with enhanced error handling and debugging
 */
class DataProcessor
{

    private $addRevenueAPI;
    private $awinAPI;
    private $brandService;
    private $couponService;
    private $logger;
    private $notifications;
    private $batchSize = 10;
    private $stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
    private $awinWindowStart = 0;
    private $awinRequestCount = 0;


    public function __construct()
    {
        $this->addRevenueAPI = new AddRevenueAPI();
        $this->awinAPI = new AwinAPI();
        $this->brandService = new BrandService();
        $this->couponService = new CouponService();
        $this->logger = new Logger();
        $this->notifications = new NotificationManager();
        $this->awinWindowStart = time();

        error_log('[CA] DataProcessor instantiated at ' . current_time('mysql'));
    }

    /**
     * Start processing data from APIs with status tracking
     */
    public function startProcessing()
    {
        error_log('[CA] ========== SYNC STARTED ========== ' . current_time('mysql'));
        error_log('[CA] Current timestamp: ' . current_time('timestamp'));

        // Check if already running
        $isRunning = get_transient('fetch_process_running');
        error_log('[CA] Process running check: ' . ($isRunning ? 'YES (blocking)' : 'NO'));

        if ($isRunning) {
            $this->logger->warning('Processing already running');
            error_log('[CA] ABORT: Processing already running - exiting');
            return false;
        }

        $stopRequested = get_option('coupon_automation_stop_requested', false);
        error_log('[CA] Stop requested check: ' . ($stopRequested ? 'YES (blocking)' : 'NO'));

        if ($stopRequested) {
            $this->logger->warning('Sync skipped because a stop was requested.');
            $this->logger->activity('Sync skipped - automation is currently stopped.', 'warning');
            error_log('[CA] ABORT: Stop was requested - exiting');
            return false;
        }

        // Set running flag and status
        set_transient('fetch_process_running', true, 30 * MINUTE_IN_SECONDS);
        update_option('coupon_automation_sync_status', 'running');

        $startTime = current_time('timestamp');
        update_option('coupon_automation_last_sync_start', $startTime);
        error_log('[CA] Set sync status to RUNNING at timestamp: ' . $startTime);

        // Log activity
        $this->logger->activity('Sync started', 'start');
        error_log('[CA] Activity logged: Sync started');

        try {
            // Reset stats
            $this->stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
            error_log('[CA] Stats reset: ' . json_encode($this->stats));

            // Process AddRevenue data
            error_log('[CA] --- Starting AddRevenue processing ---');
            $this->processAddRevenue();
            error_log('[CA] AddRevenue processing completed. Stats: ' . json_encode($this->stats));

            // Check for stop request mid-process
            if (get_option('coupon_automation_stop_requested', false)) {
                error_log('[CA] Stop requested detected after AddRevenue processing');
                $this->handleStopRequest();
                return false;
            }

            // Process AWIN data
            error_log('[CA] --- Starting AWIN processing ---');
            $this->processAwin();
            error_log('[CA] AWIN processing completed. Final stats: ' . json_encode($this->stats));

            // Final stop check
            if (get_option('coupon_automation_stop_requested', false)) {
                error_log('[CA] Stop requested detected after AWIN processing');
                $this->handleStopRequest();
                return false;
            }

            // Update status to success
            update_option('coupon_automation_sync_status', 'success');
            $completionTime = current_time('timestamp');
            update_option('coupon_automation_last_sync', $completionTime);
            update_option('coupon_automation_last_sync_stats', $this->stats);

            error_log('[CA] Sync completed successfully');
            error_log('[CA] Last sync timestamp set to: ' . $completionTime . ' (' . date('Y-m-d H:i:s', $completionTime) . ')');
            error_log('[CA] Stats saved: ' . json_encode($this->stats));

            // Log completion with notification
            $completionMessage = sprintf(
                'Sync completed - Processed: %d, Failed: %d, Skipped: %d',
                $this->stats['processed'],
                $this->stats['failed'],
                $this->stats['skipped']
            );

            $this->logger->activity($completionMessage, 'complete');

            // Add completion notification
            $this->notifications->add('sync', [
                'message' => $completionMessage,
                'processed' => $this->stats['processed'],
                'failed' => $this->stats['failed'],
                'skipped' => $this->stats['skipped']
            ]);

            error_log('[CA] Completion notification added');

            // Schedule next run
            $this->scheduleNextRun();
        } catch (\Exception $e) {
            error_log('[CA] EXCEPTION during sync: ' . $e->getMessage());
            error_log('[CA] Exception trace: ' . $e->getTraceAsString());

            $this->logger->error('Processing failed: ' . $e->getMessage());
            update_option('coupon_automation_sync_status', 'failed');
            update_option('coupon_automation_last_error', $e->getMessage());

            // Add error notification
            $this->notifications->add('error', [
                'message' => 'Sync failed: ' . $e->getMessage()
            ]);

            delete_transient('fetch_process_running');
            error_log('[CA] Cleaned up after exception');
            return false;
        }

        delete_transient('fetch_process_running');
        error_log('[CA] ========== SYNC ENDED SUCCESSFULLY ========== ' . current_time('mysql'));
        error_log('[CA] Process running flag cleared');

        return true;
    }

    /**
     * Handle stop request during processing
     */
    private function handleStopRequest()
    {
        error_log('[CA] Handling stop request - cleaning up');

        $this->logger->activity('Sync stopped before completion by user.', 'warning');

        // Add stop notification
        $stopMessage = sprintf(
            'Sync stopped by user - Processed: %d, Failed: %d, Skipped: %d',
            $this->stats['processed'],
            $this->stats['failed'],
            $this->stats['skipped']
        );

        $this->notifications->add('sync', [
            'message' => $stopMessage,
            'processed' => $this->stats['processed'],
            'failed' => $this->stats['failed'],
            'skipped' => $this->stats['skipped']
        ]);

        error_log('[CA] Stop notification added: ' . $stopMessage);

        delete_transient('fetch_process_running');
        update_option('coupon_automation_sync_status', 'stopped');
    }

    /**
     * Process AddRevenue data with error handling per item
     */
    private function processAddRevenue()
    {
        $this->logger->info('Starting AddRevenue processing');
        $this->logger->activity('Processing AddRevenue advertisers', 'info');
        error_log('[CA] AddRevenue: Starting advertiser fetch');

        // Check if API is configured
        if (empty(get_option('addrevenue_api_key'))) {
            $this->logger->warning('AddRevenue API not configured');
            $this->stats['skipped']++;
            error_log('[CA] AddRevenue: API key not configured - skipping');
            return;
        }

        try {
            // Get advertisers and campaigns
            error_log('[CA] AddRevenue: Fetching advertisers and campaigns');
            $advertisers = $this->addRevenueAPI->getAdvertisers();
            $campaigns = $this->addRevenueAPI->getCampaigns();

            error_log('[CA] AddRevenue: Fetched ' . count($advertisers) . ' advertisers and ' . count($campaigns) . ' campaigns');

            if (empty($advertisers)) {
                $this->logger->warning('No AddRevenue advertisers found');
                error_log('[CA] AddRevenue: No advertisers returned');
                return;
            }

            $processedCount = 0;
            foreach ($advertisers as $advertiser) {
                // Check for stop request
                if (get_option('coupon_automation_stop_requested', false)) {
                    error_log('[CA] AddRevenue: Stop requested at advertiser #' . $processedCount);
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
                    $advertiserCampaigns = array_filter($campaigns, function ($campaign) use ($brandName) {
                        return $campaign['advertiserName'] === $brandName && isset($campaign['markets']['SE']);
                    });

                    if (empty($advertiserCampaigns)) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    error_log('[CA] AddRevenue: Processing brand "' . $brandName . '" with ' . count($advertiserCampaigns) . ' campaigns');

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
                                    error_log('[CA] AddRevenue: Created coupon ID ' . $couponId . ' for ' . $brandName);
                                }
                            } catch (\Exception $e) {
                                $this->logger->error('Failed to process coupon for ' . $brandName . ': ' . $e->getMessage());
                                $this->stats['failed']++;
                                error_log('[CA] AddRevenue: Failed coupon for ' . $brandName . ' - ' . $e->getMessage());
                            }
                        }
                    }

                    $processedCount++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to process AddRevenue advertiser: ' . $e->getMessage());
                    $this->stats['failed']++;
                    error_log('[CA] AddRevenue: Failed advertiser - ' . $e->getMessage());
                    continue;
                }

                // Process in batches
                if ($this->stats['processed'] % $this->batchSize === 0) {
                    sleep(1);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('AddRevenue API error: ' . $e->getMessage());
            $this->logger->activity('AddRevenue processing failed: ' . $e->getMessage(), 'error');
            error_log('[CA] AddRevenue: API error - ' . $e->getMessage());
        }

        error_log('[CA] AddRevenue: Complete - Processed: ' . $this->stats['processed'] . ', Failed: ' . $this->stats['failed']);
        $this->logger->info(sprintf(
            "AddRevenue complete - Processed: %d, Failed: %d",
            $this->stats['processed'],
            $this->stats['failed']
        ));
    }

    /**
     * Process AWIN data with error handling per item
     */
    private function processAwin()
    {
        $this->logger->info('Starting AWIN processing');
        $this->logger->activity('Processing AWIN promotions', 'info');
        error_log('[CA] AWIN: Starting promotion fetch');

        // Check if API is configured
        if (empty(get_option('awin_api_token')) || empty(get_option('awin_publisher_id'))) {
            $this->logger->warning('AWIN API not configured');
            $this->stats['skipped']++;
            error_log('[CA] AWIN: API not configured - skipping');
            return;
        }

        try {
            // Get promotions
            error_log('[CA] AWIN: Fetching promotions');
            $promotions = $this->awinAPI->getPromotions();

            error_log('[CA] AWIN: Fetched ' . count($promotions) . ' promotions');

            if (empty($promotions)) {
                $this->logger->warning('No AWIN promotions found');
                error_log('[CA] AWIN: No promotions returned');
                return;
            }

            $processedAdvertisers = [];
            $processedCount = 0;

            foreach ($promotions as $promotion) {
                // Check for stop request
                if (get_option('coupon_automation_stop_requested', false)) {
                    error_log('[CA] AWIN: Stop requested at promotion #' . $processedCount);
                    $this->logger->info('Processing stopped by user');
                    break;
                }

                try {
                    $advertiserId = $promotion['advertiser']['id'];
                    $advertiserName = $promotion['advertiser']['name'];

                    // Get programme details if not already processed
                    if (!isset($processedAdvertisers[$advertiserId])) {
                        try {
                            $this->throttleAwinRequests();
                            $this->awinRequestCount++;
                            error_log('[CA] AWIN: Fetching programme details for advertiser ID ' . $advertiserId);
                            $programmeDetails = $this->awinAPI->getProgrammeDetails($advertiserId);

                            if ($programmeDetails && isset($programmeDetails['programmeInfo'])) {
                                $brandData = $programmeDetails['programmeInfo'];
                                $brandName = $this->brandService->cleanBrandName($brandData['name']);

                                error_log('[CA] AWIN: Processing brand "' . $brandName . '"');

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
                            error_log('[CA] AWIN: Failed programme details for ' . $advertiserId . ' - ' . $e->getMessage());
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
                                error_log('[CA] AWIN: Created coupon ID ' . $couponId . ' for ' . $processedAdvertisers[$advertiserId]->name);
                            }
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to process AWIN coupon: ' . $e->getMessage());
                            $this->stats['failed']++;
                            error_log('[CA] AWIN: Failed coupon - ' . $e->getMessage());
                        }
                    }

                    $processedCount++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to process AWIN promotion: ' . $e->getMessage());
                    $this->stats['failed']++;
                    error_log('[CA] AWIN: Failed promotion - ' . $e->getMessage());
                    continue;
                }

                // Process in batches
                if ($this->stats['processed'] % $this->batchSize === 0) {
                    sleep(1);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('AWIN API error: ' . $e->getMessage());
            $this->logger->activity('AWIN processing failed: ' . $e->getMessage(), 'error');
            error_log('[CA] AWIN: API error - ' . $e->getMessage());
        }

        error_log('[CA] AWIN: Complete - Total processed: ' . $this->stats['processed'] . ', Total failed: ' . $this->stats['failed']);
        $this->logger->info(sprintf(
            "AWIN complete - Processed: %d, Failed: %d",
            $this->stats['processed'],
            $this->stats['failed']
        ));
    }

    /**
     * Throttle AWIN programme detail requests to stay within API limits
     */
    private function throttleAwinRequests()
    {
        $window = 60;
        $maxRequests = 18;
        $now = time();

        if (($now - $this->awinWindowStart) >= $window) {
            $this->awinWindowStart = $now;
            $this->awinRequestCount = 0;
        }

        if ($this->awinRequestCount >= $maxRequests) {
            $sleepFor = $window - ($now - $this->awinWindowStart) + 1;
            $sleepFor = max($sleepFor, 10);
            error_log('[CA] AWIN: Rate limit - sleeping ' . $sleepFor . ' seconds');
            $this->logger->info(sprintf('AWIN rate limit guard active. Sleeping %d seconds.', $sleepFor));
            sleep($sleepFor);
            $this->awinWindowStart = time();
            $this->awinRequestCount = 0;
        }
    }

    /**
     * Schedule next run
     */
    private function scheduleNextRun()
    {
        $nextScheduled = wp_next_scheduled('coupon_automation_daily_fetch');
        error_log('[CA] Current next scheduled run: ' . ($nextScheduled ? date('Y-m-d H:i:s', $nextScheduled) : 'None'));

        if (!$nextScheduled) {
            $nextRun = strtotime('tomorrow 3:00am');
            wp_schedule_event($nextRun, 'daily', 'coupon_automation_daily_fetch');

            error_log('[CA] Scheduled next run for: ' . date('Y-m-d H:i:s', $nextRun));
            $this->logger->info('Scheduled next run for tomorrow 3:00 AM');
        } else {
            error_log('[CA] Next run already scheduled - no changes needed');
        }
    }

    /**
     * Clear all processing flags
     */
    public function clearFlags()
    {
        error_log('[CA] clearFlags() called - clearing all transients and flags');

        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        delete_option('coupon_automation_stop_requested');

        // Clear API caches
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');

        $this->logger->info('All processing flags cleared');
        error_log('[CA] All flags and caches cleared');
    }
}
