<?php

namespace CouponAutomation\Services;

use CouponAutomation\API\AddRevenueAPI;
use CouponAutomation\API\AwinAPI;
use CouponAutomation\Utils\Logger;
use CouponAutomation\Utils\NotificationManager;

/**
 * Main data processing service with enhanced error handling
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
        $this->batchSize = max(1, (int) get_option('coupon_automation_batch_size', 5));

    }

    /**
     * Start processing data from APIs with status tracking
     *
     * @param bool $dryRun If true, simulates processing without creating posts
     * @return array|bool Returns dry-run results array if $dryRun=true, bool otherwise
     */
    public function startProcessing($dryRun = false)
    {
        // Skip locks and status updates in dry-run mode
        if (!$dryRun) {
            // Check if already running
            $isRunning = get_transient('fetch_process_running');

            if ($isRunning) {
                $this->logger->warning('Processing already running');
                return false;
            }

            $stopRequested = get_option('coupon_automation_stop_requested', false);

            if ($stopRequested) {
                $this->logger->warning('Sync skipped because a stop was requested.');
                $this->logger->activity('Sync skipped - automation is currently stopped.', 'warning');
                return false;
            }

            // Set running flag and status
            set_transient('fetch_process_running', true, 30 * MINUTE_IN_SECONDS);
            update_option('coupon_automation_sync_status', 'running');

            $startTime = current_time('timestamp');
            update_option('coupon_automation_last_sync_start', $startTime);
        } else {
            // Dry-run mode
            $this->logger->info('[CA-DRYRUN] Starting test sync');
        }

        // Try to avoid premature timeouts on long runs
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Register a shutdown handler so we log and clear the flag if PHP bails out unexpectedly
        $logger = $this->logger;
        $notifications = $this->notifications;
        register_shutdown_function(function () use ($logger, $notifications) {
            if (get_transient('fetch_process_running')) {
                $lastError = error_get_last();
                $message = '[CA] Sync aborted unexpectedly';
                if ($lastError) {
                    $message .= sprintf(' - %s in %s:%d', $lastError['message'], $lastError['file'], $lastError['line']);
                } else {
                    $message .= ' - no error captured';
                }

                $logger->error($message);
                $notifications->add('error', [
                    'message' => 'Sync aborted unexpectedly. ' . ($lastError['message'] ?? 'No error reported.')
                ]);
                delete_transient('fetch_process_running');
            }
        });

        // Log activity
        $this->logger->activity('Sync started', 'start');

        try {
            // Reset stats
            $this->stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0];

            // Process AddRevenue data
            $this->processAddRevenue();

            // Check for stop request mid-process
            if (get_option('coupon_automation_stop_requested', false)) {
                $this->handleStopRequest();
                return false;
            }

            // Process AWIN data
            $this->processAwin();

            // Final stop check
            if (get_option('coupon_automation_stop_requested', false)) {
                $this->handleStopRequest();
                return false;
            }

            // Update status to success
            update_option('coupon_automation_sync_status', 'success');
            $completionTime = current_time('timestamp');
            update_option('coupon_automation_last_sync', $completionTime);
            update_option('coupon_automation_last_sync_stats', $this->stats);

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

            // Schedule next run
            $this->scheduleNextRun();
        } catch (\Throwable $e) {
            $this->logger->error('Processing failed: ' . $e->getMessage());
            update_option('coupon_automation_sync_status', 'failed');
            update_option('coupon_automation_last_error', $e->getMessage());

            // Add error notification
            $this->notifications->add('error', [
                'message' => 'Sync failed: ' . $e->getMessage()
            ]);

            return false;
        } finally {
            // Always clear the running flag even if a fatal error or type error occurs
            delete_transient('fetch_process_running');
        }

        return true;
    }


    /**
     * Handle stop request during processing
     */
    private function handleStopRequest()
    {

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

            $this->logger->info(sprintf(
                'AddRevenue fetched %d advertisers and %d campaigns',
                is_array($advertisers) ? count($advertisers) : 0,
                is_array($campaigns) ? count($campaigns) : 0
            ));

            if (empty($advertisers)) {
                $this->logger->warning('No AddRevenue advertisers found');
                return;
            }

            $processedCount = 0;
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
                    $advertiserCampaigns = array_filter($campaigns, function ($campaign) use ($brandName) {
                        return $campaign['advertiserName'] === $brandName && isset($campaign['markets']['SE']);
                    });

                    if (empty($advertiserCampaigns)) {
                        $this->stats['skipped']++;
                        continue;
                    }


                    // Process brand
                    $brand = $this->brandService->findOrCreateBrand($brandName, 'addrevenue');

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

                    $processedCount++;
                    if ($processedCount % 25 === 0) {
                        $this->logger->info(sprintf(
                            'AddRevenue progress: %d advertisers processed (processed=%d, failed=%d, skipped=%d)',
                            $processedCount,
                            $this->stats['processed'],
                            $this->stats['failed'],
                            $this->stats['skipped']
                        ));
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to process AddRevenue advertiser: ' . $e->getMessage());
                    $this->stats['failed']++;

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

        }
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

        // Check if API is configured
        if (empty(get_option('awin_api_token')) || empty(get_option('awin_publisher_id'))) {
            $this->logger->warning('AWIN API not configured');
            $this->stats['skipped']++;
            return;
        }

        try {
            // Get promotions
            $promotions = $this->awinAPI->getPromotions();

            $this->logger->info(sprintf(
                'AWIN fetched %d promotions',
                is_array($promotions) ? count($promotions) : 0
            ));

            if (empty($promotions)) {
                $this->logger->warning('No AWIN promotions found');
                return;
            }

            $processedAdvertisers = [];
            $processedCount = 0;

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
                            $this->throttleAwinRequests();
                            $this->awinRequestCount++;
                            $programmeDetails = $this->awinAPI->getProgrammeDetails($advertiserId);

                            if ($programmeDetails && isset($programmeDetails['programmeInfo'])) {
                                $brandData = $programmeDetails['programmeInfo'];
                                $brandName = $this->brandService->cleanBrandName($brandData['name']);

                                // Process brand
                                $brand = $this->brandService->findOrCreateBrand($brandName, 'awin');

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

                    $processedCount++;
                    if ($processedCount % 25 === 0) {
                        $this->logger->info(sprintf(
                            'AWIN progress: %d promotions processed (processed=%d, failed=%d, skipped=%d)',
                            $processedCount,
                            $this->stats['processed'],
                            $this->stats['failed'],
                            $this->stats['skipped']
                        ));
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to process AWIN promotion: ' . $e->getMessage());
                    $this->stats['failed']++;

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

        }
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


        if (!$nextScheduled) {
            $nextRun = strtotime('tomorrow 3:00am');
            wp_schedule_event($nextRun, 'daily', 'coupon_automation_daily_fetch');

            $this->logger->info('Scheduled next run for tomorrow 3:00 AM');
        } else {
        }
    }

    /**
     * Clear all processing flags
     */
    public function clearFlags()
    {


        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        delete_option('coupon_automation_stop_requested');

        // Clear API caches
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');

        $this->logger->info('All processing flags cleared');
    }

    /**
     * Test sync - simulates processing without creating posts
     * Tests API connectivity and shows what would happen
     *
     * @return array Test results with samples and statistics
     */
    public function testSync()
    {
        $startTime = microtime(true);
        $this->logger->info('[CA-DRYRUN] Starting test sync');

        $results = [
            'success' => true,
            'timestamp' => current_time('mysql'),
            'api_status' => [],
            'stats' => [
                'total_found' => 0,
                'would_create' => 0,
                'would_skip' => 0,
                'errors' => 0
            ],
            'samples' => [
                'coupons' => [],
                'brands' => []
            ],
            'errors' => []
        ];

        try {
            // Test AddRevenue API
            if (!empty(get_option('addrevenue_api_key'))) {
                try {
                    $advertisers = $this->addRevenueAPI->getAdvertisers();
                    $campaigns = $this->addRevenueAPI->getCampaigns();

                    $results['api_status']['addrevenue'] = [
                        'connected' => true,
                        'advertisers' => is_array($advertisers) ? count($advertisers) : 0,
                        'campaigns' => is_array($campaigns) ? count($campaigns) : 0
                    ];

                    // Sample first SE market advertiser
                    if (is_array($advertisers)) {
                        foreach ($advertisers as $advertiser) {
                            if (isset($advertiser['markets']['SE'])) {
                                $marketData = $advertiser['markets']['SE'];
                                $brandName = $marketData['displayName'];

                                // Find campaigns for this advertiser
                                $advertiserCampaigns = array_filter($campaigns, function ($campaign) use ($brandName) {
                                    return $campaign['advertiserName'] === $brandName && isset($campaign['markets']['SE']);
                                });

                                if (!empty($advertiserCampaigns)) {
                                    // Find first campaign with a non-empty description (market-level or root)
                                    $testCampaign = null;
                                    foreach ($advertiserCampaigns as $campaign) {
                                        $campaignMarket = $campaign['markets']['SE'] ?? [];
                                        $desc = $campaignMarket['description'] ?? ($campaign['description'] ?? '');
                                        if (trim($desc) !== '') {
                                            $testCampaign = $campaign;
                                            break;
                                        }
                                    }

                                    if ($testCampaign === null) {
                                        error_log('[CA-DRYRUN] AddRevenue test skipped OpenAI: no campaign with description found');
                                        continue;
                                    }

                                    $campaignData = $testCampaign['markets']['SE'] ?? [];
                                    $description = $campaignData['description'] ?? ($testCampaign['description'] ?? '');

                                    // Test OpenAI title generation
                                    $prompt = get_option('coupon_title_prompt');
                                    $fullPrompt = $prompt . ' ' . $description;

                                    $aiTitle = $this->couponService->getOpenAI()->generateContent($fullPrompt, 120);

                                    if ($aiTitle) {
                                        $results['api_status']['openai'] = [
                                            'connected' => true,
                                            'model' => get_option('openai_model', 'gpt-4o-mini'),
                                            'test_generation' => 'Success'
                                        ];

                                        // Add sample
                                        $results['samples']['coupons'][] = [
                                            'brand' => $brandName,
                                            'description' => substr($description, 0, 100),
                                            'ai_generated_title' => trim(str_replace(['"', "'"], '', $aiTitle)),
                                            'code' => $campaignData['discountCode'] ?? ($testCampaign['discountCode'] ?? 'N/A'),
                                            'type' => !empty($campaignData['discountCode'] ?? $testCampaign['discountCode'] ?? '') ? 'Code' : 'Sale',
                                            'valid_until' => $campaignData['validUntil'] ?? ($testCampaign['validUntil'] ?? 'N/A'),
                                            'source' => 'addrevenue'
                                        ];
                                    } else {
                                        error_log('[CA-DRYRUN] OpenAI title generation failed for test - check database logs for details');
                                        $results['api_status']['openai'] = [
                                            'connected' => false,
                                            'error' => 'Failed to generate title'
                                        ];
                                    }

                                    $results['stats']['total_found'] += count($advertiserCampaigns);

                                    // Check duplicates
                                    foreach ($advertiserCampaigns as $campaign) {
                                        $couponId = $campaign['id'] ?? null;
                                        if ($couponId) {
                                            $exists = get_posts([
                                                'post_type' => 'coupons',
                                                'meta_key' => 'coupon_id',
                                                'meta_value' => $couponId,
                                                'fields' => 'ids',
                                                'posts_per_page' => 1,
                                            ]);
                                            if (empty($exists)) {
                                                $results['stats']['would_create']++;
                                            } else {
                                                $results['stats']['would_skip']++;
                                            }
                                        }
                                    }

                                    break; // Only test one advertiser
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $results['api_status']['addrevenue'] = [
                        'connected' => false,
                        'error' => $e->getMessage()
                    ];
                    $results['errors'][] = 'AddRevenue API error: ' . $e->getMessage();
                    $results['stats']['errors']++;
                }
            }

            // Test AWIN API
            if (!empty(get_option('awin_api_token'))) {
                try {
                    $promotions = $this->awinAPI->getPromotions();

                    $results['api_status']['awin'] = [
                        'connected' => true,
                        'promotions' => is_array($promotions) ? count($promotions) : 0
                    ];

                    // Sample first promotion
                    if (is_array($promotions) && !empty($promotions)) {
                        $firstPromotion = reset($promotions);
                        $promotionData = $firstPromotion;

                        // Check duplicate
                        $couponId = $promotionData['promotionId'] ?? null;
                        $exists = false;
                        if ($couponId) {
                            $existingPosts = get_posts([
                                'post_type' => 'coupons',
                                'meta_key' => 'coupon_id',
                                'meta_value' => $couponId,
                                'fields' => 'ids',
                                'posts_per_page' => 1,
                            ]);
                            $exists = !empty($existingPosts);
                        }

                        // Add sample if not duplicate
                        if (!$exists && count($results['samples']['coupons']) < 5) {
                        $description = $promotionData['description'] ?? '';
                        $prompt = get_option('coupon_title_prompt');
                        $fullPrompt = $prompt . ' ' . $description;

                        $aiTitle = false;
                        if (trim($description) !== '') {
                            $aiTitle = $this->couponService->getOpenAI()->generateContent($fullPrompt, 120);
                        }

                            if ($aiTitle) {
                                $results['samples']['coupons'][] = [
                                    'brand' => $promotionData['advertiserName'] ?? 'Unknown',
                                    'description' => substr($description, 0, 100),
                                    'ai_generated_title' => trim(str_replace(['"', "'"], '', $aiTitle)),
                                    'code' => $promotionData['voucher']['code'] ?? 'N/A',
                                    'type' => !empty($promotionData['voucher']['code']) ? 'Code' : 'Sale',
                                    'valid_until' => $promotionData['endDate'] ?? 'N/A',
                                    'source' => 'awin'
                                ];
                            }
                        }

                        $results['stats']['total_found'] += count($promotions);

                        // Count how many would be created
                        foreach ($promotions as $promotion) {
                            $couponId = $promotion['promotionId'] ?? null;
                            if ($couponId) {
                                $exists = get_posts([
                                    'post_type' => 'coupons',
                                    'meta_key' => 'coupon_id',
                                    'meta_value' => $couponId,
                                    'fields' => 'ids',
                                    'posts_per_page' => 1,
                                ]);
                                if (empty($exists)) {
                                    $results['stats']['would_create']++;
                                } else {
                                    $results['stats']['would_skip']++;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $results['api_status']['awin'] = [
                        'connected' => false,
                        'error' => $e->getMessage()
                    ];
                    $results['errors'][] = 'AWIN API error: ' . $e->getMessage();
                    $results['stats']['errors']++;
                }
            }

            $endTime = microtime(true);
            $results['execution_time'] = round($endTime - $startTime, 2);

            $this->logger->info(sprintf(
                '[CA-DRYRUN] Test sync complete - Found: %d, Would create: %d, Would skip: %d, Errors: %d',
                $results['stats']['total_found'],
                $results['stats']['would_create'],
                $results['stats']['would_skip'],
                $results['stats']['errors']
            ));
        } catch (\Throwable $e) {
            $results['success'] = false;
            $results['errors'][] = 'Fatal error: ' . $e->getMessage();
            $this->logger->error('[CA-DRYRUN] Test sync failed: ' . $e->getMessage());
        }

        return $results;
    }
}



