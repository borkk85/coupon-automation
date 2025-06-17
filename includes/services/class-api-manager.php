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
        $this->logger->info('Starting API data fetch and processing');

        // Check if processing should stop
        if (get_option('coupon_automation_stop_requested', false)) {
            $this->logger->info('Processing stop requested, aborting');
            $this->cleanup_processing_flags();
            return;
        }

        // CRITICAL: Stricter lock checking to prevent multiple runs
        $lock_key = 'fetch_process_running';
        $current_lock = get_transient($lock_key);

        if ($current_lock) {
            $lock_age = time() - $current_lock;
            if ($lock_age < 600) { // 10 minutes
                $this->logger->warning('API processing already running, aborting', [
                    'lock_age_seconds' => $lock_age
                ]);
                return false;
            } else {
                $this->logger->warning('Stale lock detected, clearing and continuing', [
                    'lock_age_seconds' => $lock_age
                ]);
            }
        }

        // Set processing flag with current timestamp
        set_transient($lock_key, time(), 15 * MINUTE_IN_SECONDS);

        try {
            // Fetch data from all available APIs
            $all_data = $this->fetch_all_api_data();

            if (empty($all_data)) {
                $this->logger->warning('No API data fetched');
                $this->cleanup_processing_flags();
                return;
            }

            // Process the data in chunks
            $this->process_data_in_chunks($all_data);
        } catch (Exception $e) {
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

        // Calculate total items
        $total_addrevenue = isset($all_data['addrevenue']['advertisers']) ? count($all_data['addrevenue']['advertisers']) : 0;
        $total_awin = isset($all_data['awin']['promotions']) ? count($all_data['awin']['promotions']) : 0;
        $total_items = $total_addrevenue + $total_awin;

        $this->logger->info('Starting chunk processing', [
            'total_items' => $total_items,
            'addrevenue_items' => $total_addrevenue,
            'awin_items' => $total_awin,
            'chunk_size' => $chunk_size
        ]);

        $processed_count = get_transient('api_processed_count') ?: 0;

        // Process one chunk at a time
        for ($i = $processed_count; $i < $total_items; $i += $chunk_size) {
            if (get_option('coupon_automation_stop_requested', false)) {
                $this->logger->info('Stop requested during chunk processing');
                break;
            }

            $this->process_single_chunk($all_data, $i, $chunk_size);

            $processed_count += $chunk_size;
            set_transient('api_processed_count', min($processed_count, $total_items), HOUR_IN_SECONDS);

            $this->logger->debug('Chunk processed', [
                'processed' => min($processed_count, $total_items),
                'total' => $total_items
            ]);

            // Schedule next chunk if needed
            if ($processed_count < $total_items) {
                wp_schedule_single_event(time() + 60, 'fetch_and_store_data_event');
                $this->logger->debug('Scheduled next chunk processing');
                delete_transient('fetch_process_running');
                return;
            }
        }

        // Processing complete
        $this->complete_processing();
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
        $this->fetch_and_process_all_data();
    }

    /**
     * Complete processing
     */
    private function complete_processing()
    {
        $this->logger->info('API processing completed successfully');

        // Clear cached data and processing flags
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');
        delete_transient('api_processed_count');
        delete_transient('fetch_process_running');

        // Reset stop flag
        update_option('coupon_automation_stop_requested', false);

        // Update last sync time
        update_option('coupon_automation_last_sync', current_time('mysql'));
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
        return [
            'is_running' => (bool) get_transient('fetch_process_running'),
            'processed_count' => get_transient('api_processed_count') ?: 0,
            'stop_requested' => get_option('coupon_automation_stop_requested', false),
            'last_sync' => get_option('coupon_automation_last_sync'),
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
