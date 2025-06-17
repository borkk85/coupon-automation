<?php

/**
 * API Manager for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Manager class handles all external API communications
 */
class Coupon_Automation_API_Manager
{

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
     * Security service
     * @var Coupon_Automation_Security
     */
    private $security;

    /**
     * API handlers
     * @var array
     */
    private $api_handlers = [];

    /**
     * Initialize API manager
     */
    public function init()
    {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        $this->security = coupon_automation()->get_service('security');

        // Initialize API handlers
        $this->init_api_handlers();

        // Register hooks
        add_action('coupon_automation_daily_sync', [$this, 'fetch_and_process_all_data']);
        add_action('fetch_and_store_data_event', [$this, 'continue_processing']);
    }

    /**
     * Initialize API handlers
     */
    private function init_api_handlers()
    {
        $this->api_handlers['addrevenue'] = new Coupon_Automation_API_AddRevenue();
        $this->api_handlers['awin'] = new Coupon_Automation_API_AWIN();
        $this->api_handlers['openai'] = new Coupon_Automation_API_OpenAI();
        $this->api_handlers['yourls'] = new Coupon_Automation_API_YOURLS();

        // Initialize all handlers
        foreach ($this->api_handlers as $handler) {
            if (method_exists($handler, 'init')) {
                $handler->init();
            }
        }
    }

    /**
     * Fetch and process data from all APIs
     * Main entry point that replaces fetch_and_process_api_data()
     */
    public function fetch_and_process_all_data()
    {
        error_log("=== API MANAGER: STARTING PROCESSING ===");
        $this->logger->info('Starting API data fetch and processing');

        // CHECK 1: Daily processing window (only run between 12 AM - 6 AM)
        $current_hour = (int) current_time('H');
        $daily_processing_allowed = ($current_hour >= 0 && $current_hour < 6);

        if (!$daily_processing_allowed) {
            error_log("OUTSIDE DAILY PROCESSING WINDOW (current hour: $current_hour) - ABORTING");
            $this->logger->info('Outside daily processing window, aborting', [
                'current_hour' => $current_hour,
                'allowed_window' => '00:00 - 06:00'
            ]);
            $this->cleanup_processing_flags();
            return false;
        }

        // CHECK 2: Only process once per day
        $last_sync_date = get_option('coupon_automation_last_sync_date');
        $today = current_time('Y-m-d');

        if ($last_sync_date === $today) {
            error_log("ALREADY PROCESSED TODAY ($today) - ABORTING");
            $this->logger->info('Already processed today, aborting', [
                'last_sync_date' => $last_sync_date,
                'today' => $today
            ]);
            $this->cleanup_processing_flags();
            return false;
        }

        // CHECK 3: Stop flag
        if (get_option('coupon_automation_stop_requested', false)) {
            error_log("STOP REQUESTED - ABORTING");
            $this->logger->info('Processing stop requested, aborting');
            $this->cleanup_processing_flags();
            return false;
        }

        // CHECK 4: Processing lock (restored original chunked logic)
        $lock_key = 'fetch_process_running';
        $current_lock = get_transient($lock_key);

        if ($current_lock) {
            $lock_age = time() - $current_lock;
            if ($lock_age < 1800) { // 30 minutes (increased from 10)
                error_log("PROCESSING ALREADY RUNNING - LOCK AGE: $lock_age seconds");
                $this->logger->warning('API processing already running, aborting', [
                    'lock_age_seconds' => $lock_age
                ]);
                return false;
            } else {
                error_log("STALE LOCK DETECTED - CLEARING AND CONTINUING - LOCK AGE: $lock_age seconds");
                $this->logger->warning('Stale lock detected, clearing and continuing', [
                    'lock_age_seconds' => $lock_age
                ]);
            }
        }

        // Set processing flag with current timestamp
        set_transient($lock_key, time(), 30 * MINUTE_IN_SECONDS);
        error_log("PROCESSING LOCK SET");

        try {
            // Fetch data from all available APIs
            error_log("FETCHING API DATA...");
            $all_data = $this->fetch_all_api_data();

            if (empty($all_data)) {
                error_log("NO API DATA FETCHED - MARKING TODAY AS PROCESSED");
                $this->logger->warning('No API data fetched');
                update_option('coupon_automation_last_sync_date', $today);
                $this->cleanup_processing_flags();
                return true;
            }

            // Debug what APIs returned data
            foreach ($all_data as $api_name => $api_data) {
                if (is_array($api_data)) {
                    foreach ($api_data as $data_type => $data_items) {
                        $count = is_array($data_items) ? count($data_items) : 0;
                        error_log("API: $api_name, Type: $data_type, Count: $count");
                    }
                }
            }

            // RESTORED: Process the data in chunks (original logic)
            error_log("PROCESSING DATA IN CHUNKS...");
            $this->process_data_in_chunks($all_data);
        } catch (Exception $e) {
            error_log("API PROCESSING FAILED: " . $e->getMessage());
            $this->logger->error('API processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->cleanup_processing_flags();
            throw $e;
        }
    }

    /**
     * Fetch data from all configured APIs
     * 
     * @return array Combined API data
     */
    private function fetch_all_api_data()
    {
        $all_data = [];

        // Fetch AddRevenue data
        if ($this->api_handlers['addrevenue']->is_configured()) {
            $addrevenue_data = $this->fetch_addrevenue_data();
            if (!empty($addrevenue_data)) {
                $all_data['addrevenue'] = $addrevenue_data;
            }
        }

        // Fetch AWIN data
        if ($this->api_handlers['awin']->is_configured()) {
            $awin_data = $this->fetch_awin_data();
            if (!empty($awin_data)) {
                $all_data['awin'] = $awin_data;
            }
        }

        return $all_data;
    }

    /**
     * Fetch AddRevenue data
     * 
     * @return array AddRevenue data
     */
    private function fetch_addrevenue_data()
    {
        $cached_advertisers = get_transient('addrevenue_advertisers_data');
        $cached_campaigns = get_transient('addrevenue_campaigns_data');

        if ($cached_advertisers === false) {
            $this->logger->info('Fetching AddRevenue advertisers');
            $cached_advertisers = $this->api_handlers['addrevenue']->fetch_advertisers();
            if (!empty($cached_advertisers)) {
                set_transient('addrevenue_advertisers_data', $cached_advertisers, HOUR_IN_SECONDS);
            }
        }

        if ($cached_campaigns === false) {
            $this->logger->info('Fetching AddRevenue campaigns');
            $cached_campaigns = $this->api_handlers['addrevenue']->fetch_campaigns();
            if (!empty($cached_campaigns)) {
                set_transient('addrevenue_campaigns_data', $cached_campaigns, HOUR_IN_SECONDS);
            }
        }

        return [
            'advertisers' => $cached_advertisers ?: [],
            'campaigns' => $cached_campaigns ?: []
        ];
    }

    /**
     * Fetch AWIN data
     * 
     * @return array AWIN data
     */
    private function fetch_awin_data()
    {
        $cached_promotions = get_transient('awin_promotions_data');

        if ($cached_promotions === false) {
            $this->logger->info('Fetching AWIN promotions');
            $cached_promotions = $this->api_handlers['awin']->fetch_promotions();
            if (!empty($cached_promotions)) {
                set_transient('awin_promotions_data', $cached_promotions, HOUR_IN_SECONDS);
                $this->logger->info('AWIN data fetched', ['count' => count($cached_promotions)]);
            }
        }

        return [
            'promotions' => $cached_promotions ?: []
        ];
    }

    /**
     * Process data in chunks
     * 
     * @param array $all_data All API data
     */
    private function process_data_in_chunks($all_data)
    {
        $chunk_size = $this->settings->get('general.batch_size', 10);
        $today = current_time('Y-m-d');

        // Calculate total items
        $total_addrevenue = isset($all_data['addrevenue']['advertisers']) ? count($all_data['addrevenue']['advertisers']) : 0;
        $total_awin = isset($all_data['awin']['promotions']) ? count($all_data['awin']['promotions']) : 0;
        $total_items = $total_addrevenue + $total_awin;

        error_log("=== CHUNK PROCESSING START ===");
        error_log("Total items to process: $total_items");
        error_log("AddRevenue items: $total_addrevenue");
        error_log("AWIN items: $total_awin");
        error_log("Chunk size: $chunk_size");

        $this->logger->info('Starting chunk processing', [
            'total_items' => $total_items,
            'addrevenue_items' => $total_addrevenue,
            'awin_items' => $total_awin,
            'chunk_size' => $chunk_size,
            'today' => $today
        ]);

        // Get current progress
        $processed_count = get_transient('api_processed_count') ?: 0;
        error_log("Previously processed: $processed_count");

        // CRITICAL: Check if we should continue processing today
        if ($processed_count >= $total_items) {
            error_log("ALL ITEMS ALREADY PROCESSED - COMPLETING");
            // Mark today as processed and use existing complete_processing method
            update_option('coupon_automation_last_sync_date', $today);
            $this->complete_processing();
            return;
        }

        // Process one chunk at a time
        $chunk_start = $processed_count;
        $chunk_end = min($chunk_start + $chunk_size, $total_items);

        error_log("Processing chunk: $chunk_start to $chunk_end");

        // Check daily processing window before each chunk
        $current_hour = (int) current_time('H');
        if ($current_hour >= 6) {
            error_log("OUTSIDE PROCESSING WINDOW (hour: $current_hour) - STOPPING FOR TODAY");
            $this->logger->warning('Processing window expired, stopping for today', [
                'current_hour' => $current_hour,
                'processed_count' => $processed_count,
                'total_items' => $total_items
            ]);
            update_option('coupon_automation_last_sync_date', $today);
            $this->complete_processing();
            return;
        }

        // Check stop flag before each chunk
        if (get_option('coupon_automation_stop_requested', false)) {
            error_log("STOP REQUESTED DURING CHUNK PROCESSING");
            $this->logger->info('Stop requested during chunk processing');
            $this->cleanup_processing_flags();
            return;
        }

        $this->process_single_chunk($all_data, $chunk_start, $chunk_size);

        $new_processed_count = $chunk_end;
        set_transient('api_processed_count', $new_processed_count, DAY_IN_SECONDS);

        error_log("Chunk completed. Progress: $new_processed_count / $total_items");

        $this->logger->debug('Chunk processed', [
            'processed' => $new_processed_count,
            'total' => $total_items,
            'progress_percent' => round(($new_processed_count / $total_items) * 100, 2)
        ]);

        // Check if processing is complete
        if ($new_processed_count >= $total_items) {
            error_log("ALL PROCESSING COMPLETE FOR TODAY");
            // Mark today as processed and use existing complete_processing method
            update_option('coupon_automation_last_sync_date', $today);
            $this->complete_processing();
            return;
        }

        // Schedule next chunk with longer delay to be nice to APIs
        error_log("Scheduling next chunk in 2 minutes...");
        wp_schedule_single_event(time() + 120, 'fetch_and_store_data_event'); // 2 minutes instead of 1

        // Extend the processing lock for the next chunk
        set_transient('fetch_process_running', time(), 30 * MINUTE_IN_SECONDS);

        $this->logger->debug('Scheduled next chunk processing', [
            'next_chunk_start' => $new_processed_count,
            'remaining_items' => $total_items - $new_processed_count
        ]);
    }

    /**
     * Process a single chunk of data
     * 
     * @param array $all_data All API data
     * @param int $offset Current offset
     * @param int $chunk_size Chunk size
     */
    private function process_single_chunk($all_data, $offset, $chunk_size)
    {
        $brand_manager = coupon_automation()->get_service('brand');
        $coupon_manager = coupon_automation()->get_service('coupon');

        if (!$brand_manager || !$coupon_manager) {
            $this->logger->error('Required services not available');
            return;
        }

        // Process AddRevenue chunk
        if (isset($all_data['addrevenue']['advertisers'])) {
            $addrevenue_chunk = array_slice($all_data['addrevenue']['advertisers'], $offset, $chunk_size);
            if (!empty($addrevenue_chunk)) {
                $this->process_addrevenue_chunk($addrevenue_chunk, $all_data['addrevenue']['campaigns']);
            }
        }

        // Process AWIN chunk
        if (isset($all_data['awin']['promotions'])) {
            $awin_offset = max(0, $offset - count($all_data['addrevenue']['advertisers'] ?? []));
            $awin_chunk = array_slice($all_data['awin']['promotions'], $awin_offset, $chunk_size);
            if (!empty($awin_chunk)) {
                $this->process_awin_chunk($awin_chunk);
            }
        }
    }

    /**
     * Process AddRevenue chunk
     * Preserves original logic from process_advertiser_chunk()
     * 
     * @param array $chunk Advertiser chunk
     * @param array $campaigns All campaigns
     */
    private function process_addrevenue_chunk($chunk, $campaigns)
    {
        $brand_manager = coupon_automation()->get_service('brand');
        $coupon_manager = coupon_automation()->get_service('coupon');

        foreach ($chunk as $advertiser) {
            if (!isset($advertiser['markets']['SE'])) {
                continue;
            }

            $brand_name = sanitize_text_field($advertiser['markets']['SE']['displayName']);

            // Find campaigns for this brand
            $brand_coupons = array_filter($campaigns, function ($campaign) use ($brand_name) {
                return $campaign['advertiserName'] === $brand_name && isset($campaign['markets']['SE']);
            });

            if (empty($brand_coupons)) {
                $this->logger->debug("Skipping brand '{$brand_name}' - no coupons for SE market");
                continue;
            }

            // Process brand
            $brand_term = $brand_manager->find_or_create_brand($brand_name, 'addrevenue');
            if (!$brand_term) {
                $this->logger->error("Failed to create/find brand: {$brand_name}");
                continue;
            }

            // Update brand data
            $brand_manager->update_brand_data($brand_term->term_id, $advertiser, $advertiser['markets']['SE'], 'addrevenue');

            // Process coupons
            foreach ($brand_coupons as $coupon_data) {
                $coupon_manager->process_coupon($coupon_data, $brand_name, 'addrevenue');
            }

            $this->logger->debug("Processed AddRevenue brand: {$brand_name}", [
                'brand_id' => $brand_term->term_id,
                'coupons_count' => count($brand_coupons)
            ]);
        }
    }

    /**
     * Process AWIN chunk
     * Preserves original logic from process_awin_chunk()
     * 
     * @param array $chunk Promotion chunk
     */
    private function process_awin_chunk($chunk)
    {
        $brand_manager = coupon_automation()->get_service('brand');
        $coupon_manager = coupon_automation()->get_service('coupon');

        foreach ($chunk as $promotion) {
            $advertiser_id = $promotion['advertiser']['id'];

            // Fetch programme details
            $programme_data = $this->api_handlers['awin']->fetch_programme_details($advertiser_id);
            if (!$programme_data) {
                $this->logger->warning("Failed to fetch AWIN programme details for advertiser: {$advertiser_id}");
                continue;
            }

            $original_brand_name = sanitize_text_field($programme_data['name']);
            $brand_name = $this->clean_brand_name($original_brand_name);

            $this->logger->debug("Processing AWIN brand", [
                'original_name' => $original_brand_name,
                'cleaned_name' => $brand_name
            ]);

            // Process brand
            $brand_term = $brand_manager->find_or_create_brand($brand_name, 'awin');
            if (!$brand_term) {
                $this->logger->error("Failed to create/find brand: {$brand_name}");
                continue;
            }

            // Update brand data
            $brand_manager->update_brand_data($brand_term->term_id, $programme_data, $promotion, 'awin');

            // Process coupon
            $coupon_manager->process_coupon($promotion, $brand_name, 'awin');

            $this->logger->debug("Processed AWIN brand: {$brand_name}", [
                'brand_id' => $brand_term->term_id,
                'advertiser_id' => $advertiser_id
            ]);
        }
    }

    /**
     * Clean brand name (preserves original logic)
     * 
     * @param string $brand_name Brand name to clean
     * @return string Cleaned brand name
     */
    private function clean_brand_name($brand_name)
    {
        $codes_to_remove = ['EU', 'UK', 'US', 'CA', 'AU', 'NZ', 'SE', 'NO', 'DK', 'FI'];

        foreach ($codes_to_remove as $code) {
            $brand_name = preg_replace('/\s+' . $code . '\s*$/i', '', $brand_name);
        }

        return trim($brand_name);
    }

    /**
     * Continue processing (scheduled event handler)
     */
    public function continue_processing()
    {
        error_log("=== CONTINUE PROCESSING TRIGGERED ===");

        // Check if we're still in the daily processing window
        $current_hour = (int) current_time('H');
        $today = current_time('Y-m-d');
        $last_sync_date = get_option('coupon_automation_last_sync_date');

        if ($current_hour >= 6) {
            error_log("CONTINUE PROCESSING: OUTSIDE WINDOW (hour: $current_hour)");
            $this->logger->info('Continue processing called outside window, stopping', [
                'current_hour' => $current_hour
            ]);
            update_option('coupon_automation_last_sync_date', $today);
            $this->cleanup_processing_flags();
            return;
        }

        if ($last_sync_date === $today) {
            error_log("CONTINUE PROCESSING: ALREADY COMPLETED TODAY");
            $this->logger->info('Continue processing called but already completed today');
            $this->cleanup_processing_flags();
            return;
        }

        // Continue with normal processing
        $this->fetch_and_process_all_data();
    }

    /**
     * Complete processing
     */
    
    private function complete_processing()
    {
        error_log("=== COMPLETING PROCESSING ===");
        $this->logger->info('API processing completed successfully');

        // Clear cached data and processing flags
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');
        delete_transient('api_processed_count');
        delete_transient('fetch_process_running');

        // Clear scheduled events
        wp_clear_scheduled_hook('fetch_and_store_data_event');

        // Reset stop flag
        update_option('coupon_automation_stop_requested', false);

        // Update last sync time
        update_option('coupon_automation_last_sync', current_time('mysql'));

        // ADDED: Ensure today is marked as processed
        $today = current_time('Y-m-d');
        update_option('coupon_automation_last_sync_date', $today);

        error_log("PROCESSING COMPLETED AND TODAY MARKED: $today");
    }

    /**
     * Cleanup processing flags
     */
    private function cleanup_processing_flags()
    {
        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        update_option('coupon_automation_stop_requested', false);
    }

    /**
     * Stop processing
     */
    public function stop_processing()
    {
        $this->logger->info('Processing stop requested');
        update_option('coupon_automation_stop_requested', true);

        // Clear scheduled events
        wp_clear_scheduled_hook('fetch_and_store_data_event');

        return true;
    }

    /**
     * Get processing status
     * 
     * @return array Processing status information
     */
    public function get_processing_status()
    {
        $today = current_time('Y-m-d');
        $last_sync_date = get_option('coupon_automation_last_sync_date');
        $current_hour = (int) current_time('H');
        $in_processing_window = ($current_hour >= 0 && $current_hour < 6);

        $is_running = (bool) get_transient('fetch_process_running');
        $completed_today = ($last_sync_date === $today);

        return [
            'is_running' => $is_running,
            'processed_count' => get_transient('api_processed_count') ?: 0,
            'stop_requested' => get_option('coupon_automation_stop_requested', false),
            'last_sync' => get_option('coupon_automation_last_sync'),
            'last_sync_date' => $last_sync_date,
            'today' => $today,
            'completed_today' => $completed_today,
            'current_hour' => $current_hour,
            'in_processing_window' => $in_processing_window,
            'can_process' => $in_processing_window && !$completed_today,
        ];
    }
    /**
     * Clear processing cache
     */
    public function clear_cache()
    {
        $transients = [
            'fetch_process_running',
            'addrevenue_advertisers_data',
            'addrevenue_campaigns_data',
            'awin_promotions_data',
            'api_processed_count'
        ];

        foreach ($transients as $transient) {
            delete_transient($transient);
        }

        $this->logger->info('API cache cleared');
        return true;
    }

    /**
     * Get API handler
     * 
     * @param string $api_name API name
     * @return mixed API handler or null
     */
    public function get_api_handler($api_name)
    {
        return isset($this->api_handlers[$api_name]) ? $this->api_handlers[$api_name] : null;
    }

    /**
     * Test API connections
     * 
     * @return array Test results
     */
    public function test_api_connections()
    {
        $results = [];

        foreach ($this->api_handlers as $name => $handler) {
            if (method_exists($handler, 'test_connection')) {
                $results[$name] = $handler->test_connection();
            } else {
                $results[$name] = ['status' => 'not_supported', 'message' => 'Test not supported'];
            }
        }

        return $results;
    }
}
