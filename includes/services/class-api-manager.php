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

        // SIMPLIFIED: Only register essential hooks
        add_action('coupon_automation_daily_sync', [$this, 'fetch_and_process_all_data']);
        add_action('fetch_and_store_data_event', [$this, 'continue_processing']);

        if ($this->settings && $this->settings->get('general.enable_debug_logging', false)) {
            $this->logger->debug('API Manager initialized with simplified hooks');
        }
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
     * Main entry point - SIMPLIFIED VERSION
     */
    public function fetch_and_process_all_data()
    {
        error_log("=== API MANAGER: fetch_and_process_all_data() CALLED ===");
        error_log("WordPress current_time(): " . current_time('c'));
        error_log("Current hour: " . current_time('H'));
        error_log("Called by action: " . (current_action() ?: 'direct'));

        $this->logger->info('Starting API data fetch and processing');

        $current_hour = (int) current_time('H');
        $today = current_time('Y-m-d');
        $last_sync_date = get_option('coupon_automation_last_sync_date');

        // Determine if this is manual (AJAX) or automatic (cron)
        $is_manual = wp_doing_ajax() && isset($_POST['action']) && $_POST['action'] === 'fetch_coupons';

        error_log("Processing type: " . ($is_manual ? 'MANUAL' : 'AUTOMATIC'));
        error_log("Current hour: $current_hour");
        error_log("Today: $today, Last sync: $last_sync_date");

        // Check if already processed today
        if ($last_sync_date === $today) {
            error_log("ALREADY PROCESSED TODAY - ABORTING");
            $this->logger->info('Already processed today, aborting', [
                'today' => $today,
                'last_sync_date' => $last_sync_date
            ]);
            return false;
        }

        // Check time window (00:00-06:00) for automatic processing
        if (!$is_manual) {
            $in_processing_window = ($current_hour >= 0 && $current_hour < 6);
            if (!$in_processing_window) {
                error_log("AUTOMATIC PROCESSING OUTSIDE WINDOW (hour: $current_hour)");
                $this->logger->info('Automatic processing outside window', [
                    'current_hour' => $current_hour,
                    'allowed_window' => '00:00 - 06:00'
                ]);
                return false;
            }
        }

        // Check stop flag
        if (get_option('coupon_automation_stop_requested', false)) {
            error_log("STOP REQUESTED - ABORTING");
            $this->logger->info('Processing stop requested, aborting');
            return false;
        }

        // Simple lock check
        $lock_key = 'coupon_automation_processing_lock';
        $current_lock = get_option($lock_key);

        if ($current_lock && (time() - $current_lock) < 1800) {
            error_log("PROCESSING ALREADY RUNNING");
            return false;
        }

        // Set processing lock
        update_option($lock_key, time());
        update_option('coupon_automation_processing_status', 'running');
        update_option('coupon_automation_last_sync', current_time('mysql'));

        error_log("PROCESSING STARTED");

        try {
            // Fetch fresh API data
            error_log("FETCHING API DATA...");
            $all_data = $this->fetch_all_api_data();

            if (empty($all_data)) {
                error_log("NO API DATA - COMPLETING");
                $this->complete_processing();
                return true;
            }

            // Process with simplified chunking
            error_log("STARTING SIMPLIFIED PROCESSING...");
            $this->process_data_simplified($all_data);
            
        } catch (Exception $e) {
            error_log("PROCESSING FAILED: " . $e->getMessage());
            $this->logger->error('Processing failed', [
                'error' => $e->getMessage()
            ]);

            update_option('coupon_automation_processing_status', 'failed');
            update_option('coupon_automation_last_error', $e->getMessage());
            $this->cleanup_processing_flags();
            throw $e;
        }

        return true;
    }

    /**
     * Fetch data from all configured APIs - NO CACHING
     * 
     * @return array Combined API data
     */
    private function fetch_all_api_data()
    {
        $all_data = [];

        // Fetch fresh AddRevenue data
        if ($this->api_handlers['addrevenue']->is_configured()) {
            $this->logger->info('Fetching fresh AddRevenue data');
            
            $advertisers = $this->api_handlers['addrevenue']->fetch_advertisers();
            $campaigns = $this->api_handlers['addrevenue']->fetch_campaigns();
            
            if (!empty($advertisers) || !empty($campaigns)) {
                $all_data['addrevenue'] = [
                    'advertisers' => $advertisers ?: [],
                    'campaigns' => $campaigns ?: []
                ];
                error_log("Fetched AddRevenue: " . count($advertisers) . " advertisers, " . count($campaigns) . " campaigns");
            }
        }

        // Fetch fresh AWIN data
        if ($this->api_handlers['awin']->is_configured()) {
            $this->logger->info('Fetching fresh AWIN data');
            
            $promotions = $this->api_handlers['awin']->fetch_promotions();
            
            if (!empty($promotions)) {
                $all_data['awin'] = [
                    'promotions' => $promotions ?: []
                ];
                error_log("Fetched AWIN: " . count($promotions) . " promotions");
            }
        }

        return $all_data;
    }

    /**
     * NEW SIMPLIFIED PROCESSING METHOD
     * Flattens all data into single array and processes with reliable state management
     */
    private function process_data_simplified($all_data)
    {
        error_log("=== SIMPLIFIED PROCESSING STARTED ===");
        
        // Flatten all items into a single array with source tracking
        $all_items = [];
        
        // Add AddRevenue campaigns with their advertiser data
        if (isset($all_data['addrevenue']['campaigns'])) {
            foreach ($all_data['addrevenue']['campaigns'] as $campaign) {
                // Find matching advertiser data
                $advertiser_data = null;
                if (isset($all_data['addrevenue']['advertisers'])) {
                    foreach ($all_data['addrevenue']['advertisers'] as $advertiser) {
                        if (isset($advertiser['markets']['SE']['displayName']) && 
                            $advertiser['markets']['SE']['displayName'] === ($campaign['advertiserName'] ?? '')) {
                            $advertiser_data = $advertiser;
                            break;
                        }
                    }
                }
                
                $all_items[] = [
                    'type' => 'addrevenue_campaign',
                    'campaign' => $campaign,
                    'advertiser' => $advertiser_data
                ];
            }
        }
        
        // Add AWIN promotions
        if (isset($all_data['awin']['promotions'])) {
            foreach ($all_data['awin']['promotions'] as $promotion) {
                $all_items[] = [
                    'type' => 'awin_promotion',
                    'promotion' => $promotion
                ];
            }
        }
        
        $total_items = count($all_items);
        error_log("Total items to process: $total_items");
        
        if ($total_items === 0) {
            error_log("No items to process - completing");
            $this->complete_processing();
            return;
        }
        
        // Store processing state in OPTIONS (reliable storage)
        $processing_state = [
            'items' => $all_items,
            'position' => 0,
            'total' => $total_items,
            'started' => time(),
            'date' => current_time('Y-m-d')
        ];
        
        update_option('_coupon_automation_processing_state', $processing_state, false);
        error_log("Processing state saved to options table");
        
        // Process first chunk immediately
        $this->process_next_chunk();
    }
    
    /**
     * Process next chunk of items
     */
    private function process_next_chunk()
    {
        error_log("=== PROCESSING NEXT CHUNK ===");
        
        // Get current state
        $state = get_option('_coupon_automation_processing_state');
        
        if (!$state || !isset($state['items'])) {
            error_log("No processing state found - completing");
            $this->complete_processing();
            return;
        }
        
        // Check if we're still in the processing window
        $current_hour = (int) current_time('H');
        if ($current_hour >= 6) {
            error_log("Outside processing window (hour: $current_hour) - pausing");
            $this->logger->info('Processing paused - outside time window', [
                'processed' => $state['position'],
                'total' => $state['total']
            ]);
            // We'll resume tomorrow
            return;
        }
        
        // Check stop flag
        if (get_option('coupon_automation_stop_requested', false)) {
            error_log("Stop requested - aborting");
            $this->cleanup_processing_flags();
            return;
        }
        
        $chunk_size = $this->settings->get('general.batch_size', 50);
        $items_to_process = array_slice($state['items'], $state['position'], $chunk_size);
        $processed_count = 0;
        
        error_log("Processing items " . $state['position'] . " to " . ($state['position'] + count($items_to_process)));
        
        $brand_manager = coupon_automation()->get_service('brand');
        $coupon_manager = coupon_automation()->get_service('coupon');
        
        if (!$brand_manager || !$coupon_manager) {
            error_log("Required services not available");
            $this->complete_processing();
            return;
        }
        
        foreach ($items_to_process as $item) {
            try {
                if ($item['type'] === 'addrevenue_campaign') {
                    $this->process_addrevenue_item($item, $brand_manager, $coupon_manager);
                } elseif ($item['type'] === 'awin_promotion') {
                    $this->process_awin_item($item, $brand_manager, $coupon_manager);
                }
                $processed_count++;
            } catch (Exception $e) {
                error_log("Failed to process item: " . $e->getMessage());
                $this->logger->error('Item processing failed', [
                    'item_type' => $item['type'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Update state
        $state['position'] += $processed_count;
        update_option('_coupon_automation_processing_state', $state, false);
        
        error_log("Chunk completed. Progress: " . $state['position'] . " / " . $state['total']);
        
        // Check if we're done
        if ($state['position'] >= $state['total']) {
            error_log("All items processed - completing");
            $this->complete_processing();
            return;
        }
        
        // Schedule next chunk
        error_log("Scheduling next chunk in 30 seconds");
        wp_schedule_single_event(time() + 30, 'fetch_and_store_data_event');
        
        // Keep the lock alive
        update_option('coupon_automation_processing_lock', time());
    }
    
    /**
     * Process single AddRevenue item
     */
    private function process_addrevenue_item($item, $brand_manager, $coupon_manager)
    {
        $campaign = $item['campaign'];
        $advertiser_data = $item['advertiser'];
        $advertiser_name = $campaign['advertiserName'] ?? '';
        
        if (empty($advertiser_name)) {
            return;
        }
        
        // Skip non-SE market campaigns
        if (!isset($campaign['markets']['SE'])) {
            return;
        }
        
        // Ensure brand exists
        $brand_term = $brand_manager->find_or_create_brand($advertiser_name, 'addrevenue');
        if ($brand_term && $advertiser_data) {
            // Update brand data
            $brand_manager->update_brand_data(
                $brand_term->term_id, 
                $advertiser_data, 
                $advertiser_data['markets']['SE'], 
                'addrevenue'
            );
            
            // Process the coupon
            $coupon_manager->process_coupon($campaign, $advertiser_name, 'addrevenue');
        }
    }
    
    /**
     * Process single AWIN item
     */
    private function process_awin_item($item, $brand_manager, $coupon_manager)
    {
        $promotion = $item['promotion'];
        $advertiser_id = $promotion['advertiser']['id'] ?? '';
        
        if (empty($advertiser_id)) {
            return;
        }
        
        // Fetch programme details
        $programme_data = $this->api_handlers['awin']->fetch_programme_details($advertiser_id);
        if (!$programme_data) {
            error_log("Failed to fetch AWIN programme details for advertiser: $advertiser_id");
            return;
        }
        
        $original_brand_name = sanitize_text_field($programme_data['name']);
        $brand_name = $this->clean_brand_name($original_brand_name);
        
        // Ensure brand exists
        $brand_term = $brand_manager->find_or_create_brand($brand_name, 'awin');
        if ($brand_term) {
            // Update brand data
            $brand_manager->update_brand_data($brand_term->term_id, $programme_data, $promotion, 'awin');
            
            // Process the coupon
            $coupon_manager->process_coupon($promotion, $brand_name, 'awin');
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
     * Continue processing (scheduled event handler) - SIMPLIFIED VERSION
     */
    public function continue_processing()
    {
        error_log("=== CONTINUE PROCESSING TRIGGERED ===");

        // Check if we're still in the daily processing window
        $current_hour = (int) current_time('H');
        $today = current_time('Y-m-d');
        
        // Check if we're still processing today's data
        $state = get_option('_coupon_automation_processing_state');
        if (!$state || $state['date'] !== $today) {
            error_log("No active processing state for today");
            $this->cleanup_processing_flags();
            return;
        }

        if ($current_hour >= 6) {
            error_log("Outside processing window (hour: $current_hour) - will resume tomorrow");
            $this->logger->info('Continue processing paused - outside window', [
                'current_hour' => $current_hour,
                'progress' => $state['position'] . '/' . $state['total']
            ]);
            // Don't clear state - we'll resume tomorrow if needed
            return;
        }

        // Check stop flag
        if (get_option('coupon_automation_stop_requested', false)) {
            error_log("Stop requested - aborting continuation");
            $this->cleanup_processing_flags();
            return;
        }

        // Refresh the lock
        update_option('coupon_automation_processing_lock', time());
        
        error_log("Continuing chunk processing...");

        try {
            // Process next chunk
            $this->process_next_chunk();
        } catch (Exception $e) {
            error_log("Continue processing failed: " . $e->getMessage());
            $this->logger->error('Continue processing failed', [
                'error' => $e->getMessage()
            ]);
            
            update_option('coupon_automation_processing_status', 'failed');
            update_option('coupon_automation_last_error', $e->getMessage());
            $this->cleanup_processing_flags();
        }
    }

    /**
     * Cleanup processing flags - SIMPLIFIED VERSION
     */
    private function cleanup_processing_flags()
    {
        error_log("=== CLEANING UP PROCESSING FLAGS ===");

        // Clear processing state
        delete_option('_coupon_automation_processing_state');
        delete_option('coupon_automation_processing_lock');
        
        // Clear scheduled events
        wp_clear_scheduled_hook('fetch_and_store_data_event');

        // Reset stop flag
        update_option('coupon_automation_stop_requested', false);
        
        // Update status
        update_option('coupon_automation_processing_status', 'idle');

        error_log("CLEANUP COMPLETED");
    }

    /**
     * Complete processing - SIMPLIFIED VERSION
     */
    private function complete_processing()
    {
        error_log("=== COMPLETING PROCESSING ===");
        
        // Get final stats from state before clearing
        $state = get_option('_coupon_automation_processing_state');
        $total_processed = $state ? $state['position'] : 0;
        
        $this->logger->info('API processing completed successfully', [
            'total_processed' => $total_processed,
            'completion_time' => current_time('mysql')
        ]);

        // Clear processing state
        delete_option('_coupon_automation_processing_state');
        delete_option('coupon_automation_processing_lock');

        // Clear scheduled events
        wp_clear_scheduled_hook('fetch_and_store_data_event');

        // Reset stop flag
        update_option('coupon_automation_stop_requested', false);

        // Update processing status
        update_option('coupon_automation_processing_status', 'idle');

        // Clear any error status
        delete_option('coupon_automation_last_error');

        // Update last sync time
        update_option('coupon_automation_last_sync', current_time('mysql'));

        // Mark today as processed
        update_option('coupon_automation_last_sync_date', current_time('Y-m-d'));

        error_log("PROCESSING COMPLETED - TODAY MARKED AS DONE (processed: $total_processed)");
    }

    /**
     * Stop processing - SIMPLIFIED VERSION
     */
    public function stop_processing()
    {
        $this->logger->info('Processing stop requested');
        update_option('coupon_automation_stop_requested', true);

        // Clear the lock immediately
        delete_option('coupon_automation_processing_lock');
        
        // Clear processing state
        delete_option('_coupon_automation_processing_state');

        // Clear scheduled events
        wp_clear_scheduled_hook('fetch_and_store_data_event');
        
        // Update status
        update_option('coupon_automation_processing_status', 'stopped');

        $this->logger->info('Processing stopped and cleaned up');

        return true;
    }

    /**
     * Get processing status - SIMPLIFIED VERSION
     * 
     * @return array Processing status information
     */
    public function get_processing_status()
    {
        $today = current_time('Y-m-d');
        $last_sync_date = get_option('coupon_automation_last_sync_date');
        $current_hour = (int) current_time('H');
        $in_processing_window = ($current_hour >= 0 && $current_hour < 6);

        // Check if actually running by examining lock
        $lock_timestamp = get_option('coupon_automation_processing_lock');
        $is_running = false;

        if ($lock_timestamp) {
            $lock_age = time() - $lock_timestamp;
            // Consider it running only if lock is recent (less than 30 minutes)
            $is_running = ($lock_age < 1800);
        }

        // Get stored processing status
        $stored_status = get_option('coupon_automation_processing_status', 'idle');

        // If lock expired but status is still "running", reset it
        if (!$is_running && $stored_status === 'running') {
            update_option('coupon_automation_processing_status', 'idle');
            $stored_status = 'idle';
        }

        $completed_today = ($last_sync_date === $today);

        // Get processing state for progress info
        $state = get_option('_coupon_automation_processing_state');
        $processed_count = 0;
        $total_count = 0;
        
        if ($state) {
            $processed_count = $state['position'] ?? 0;
            $total_count = $state['total'] ?? 0;
        }

        // More detailed status
        $detailed_status = $stored_status;
        if ($is_running) {
            $detailed_status = $processed_count > 0 ? 'processing_coupons' : 'fetching_data';
        }

        return [
            'is_running' => $is_running,
            'status' => $stored_status,
            'detailed_status' => $detailed_status,
            'processed_count' => $processed_count,
            'total_count' => $total_count,
            'stop_requested' => get_option('coupon_automation_stop_requested', false),
            'last_sync' => get_option('coupon_automation_last_sync'),
            'last_sync_date' => $last_sync_date,
            'today' => $today,
            'completed_today' => $completed_today,
            'current_hour' => $current_hour,
            'in_processing_window' => $in_processing_window,
            'can_process' => $in_processing_window && !$completed_today,
            'lock_timestamp' => $lock_timestamp,
            'lock_age_seconds' => $lock_timestamp ? (time() - $lock_timestamp) : null,
            'last_error' => get_option('coupon_automation_last_error', ''),
        ];
    }

    /**
     * Clear processing cache - SIMPLIFIED VERSION
     */
    public function clear_cache()
    {
        // Clear processing state
        delete_option('_coupon_automation_processing_state');
        delete_option('coupon_automation_processing_lock');
        
        // Clear daily sync date (for testing)
        delete_option('coupon_automation_last_sync_date');

        // Clear scheduled events
        wp_clear_scheduled_hook('fetch_and_store_data_event');

        // Reset stop flag
        update_option('coupon_automation_stop_requested', false);

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