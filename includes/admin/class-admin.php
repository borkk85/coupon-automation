<?php

/**
 * Admin interface for Coupon Automation plugin
 * 
 * @package CouponAutomation
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin class handles all admin interface functionality
 */
class Coupon_Automation_Admin
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
     * Initialize admin interface
     */
    public function init()
    {
        $this->settings = coupon_automation()->get_service('settings');
        $this->logger = coupon_automation()->get_service('logger');
        $this->security = coupon_automation()->get_service('security');

        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_notices', [$this, 'display_admin_notices']);

        // Settings page hooks
        add_action('admin_post_save_coupon_automation_settings', [$this, 'save_settings']);
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu()
    {
        // Main settings page
        add_options_page(
            __('Coupon Automation Settings', 'coupon-automation'),
            __('Coupon Automation', 'coupon-automation'),
            'manage_options',
            'coupon-automation',
            [$this, 'render_main_settings_page']
        );

        // Brand population page
        add_options_page(
            __('Populate Brand Content', 'coupon-automation'),
            __('Populate Brand Content', 'coupon-automation'),
            'manage_options',
            'populate-brand-content',
            [$this, 'render_brand_population_page']
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings()
    {
        // Settings are registered in the Settings class
        // This method exists for WordPress compatibility
    }

    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on our plugin pages
        if (!in_array($hook, ['settings_page_coupon-automation', 'settings_page_populate-brand-content'])) {
            return;
        }

        // Enqueue existing CSS files with versioning
        wp_enqueue_style(
            'coupon-automation-admin',
            COUPON_AUTOMATION_PLUGIN_URL . 'assets/css/styles.css',
            [],
            COUPON_AUTOMATION_VERSION
        );

        wp_enqueue_style(
            'coupon-automation-brand-population',
            COUPON_AUTOMATION_PLUGIN_URL . 'assets/css/brand-population.css',
            [],
            COUPON_AUTOMATION_VERSION
        );

        // Enqueue existing JavaScript files
        wp_enqueue_script(
            'coupon-automation-admin',
            COUPON_AUTOMATION_PLUGIN_URL . 'assets/js/coupon-automation.js',
            ['jquery'],
            COUPON_AUTOMATION_VERSION,
            true
        );

        // Localize script for AJAX with new security nonces
        wp_localize_script('coupon-automation-admin', 'couponAutomation', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $this->security->create_nonce('fetch_coupons'),
            'stop_nonce' => $this->security->create_nonce('stop_automation'),
            'clear_nonce' => $this->security->create_nonce('clear_transients'),
            'purge_nonce' => $this->security->create_nonce('purge_expired'),
            'settings_nonce' => $this->security->create_nonce('admin_settings'),
            'notifications_nonce' => $this->security->create_nonce('clear_notifications'),
            'strings' => [
                'processing' => __('Processing...', 'coupon-automation'),
                'error' => __('An error occurred', 'coupon-automation'),
                'success' => __('Operation completed successfully', 'coupon-automation'),
                'confirm_stop' => __('Are you sure you want to stop the automation?', 'coupon-automation'),
                'confirm_purge' => __('Are you sure you want to purge expired coupons?', 'coupon-automation'),
                'confirm_clear' => __('Are you sure you want to clear all cache and flags?', 'coupon-automation')
            ]
        ]);

        // Enqueue brand population script if on that page
        if ($hook === 'settings_page_populate-brand-content') {
            wp_enqueue_script(
                'coupon-automation-brand-population',
                COUPON_AUTOMATION_PLUGIN_URL . 'assets/js/brand-population.js',
                ['jquery'],
                COUPON_AUTOMATION_VERSION,
                true
            );

            wp_localize_script('coupon-automation-brand-population', 'brandPopulation', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => $this->security->create_nonce('populate_brands'),
                'strings' => [
                    'processing' => __('Processing brands...', 'coupon-automation'),
                    'completed' => __('Brand population completed', 'coupon-automation'),
                    'error' => __('An error occurred during processing', 'coupon-automation')
                ]
            ]);
        }
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices()
    {
        // Check for activation warnings
        $activation_warning = get_option('coupon_automation_activation_warning');
        if (!empty($activation_warning)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . esc_html__('Coupon Automation Warning:', 'coupon-automation') . '</strong> ';
            echo esc_html($activation_warning) . '</p>';
            echo '</div>';
            delete_option('coupon_automation_activation_warning');
        }

        // Check for health issues
        $health_issues = get_option('coupon_automation_health_issues', []);
        if (!empty($health_issues)) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>' . esc_html__('Coupon Automation Health Check:', 'coupon-automation') . '</strong></p>';
            echo '<ul>';
            foreach ($health_issues as $issue) {
                echo '<li>' . esc_html($issue) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        // Display notifications
        $this->display_plugin_notifications();
    }

    /**
     * Display plugin notifications
     */
    private function display_plugin_notifications()
    {
        $notifications = get_option('coupon_automation_notifications', []);

        if (empty($notifications)) {
            return;
        }

        // Show only recent notifications (last 5)
        $recent_notifications = array_slice($notifications, -5);

        echo '<div class="notice notice-info is-dismissible" id="coupon-automation-notifications">';
        echo '<p><strong>' . esc_html__('Recent Coupon Automation Activity:', 'coupon-automation') . '</strong></p>';
        echo '<ul>';

        foreach ($recent_notifications as $notification) {
            $time_diff = human_time_diff(strtotime($notification['time']), current_time('timestamp'));

            switch ($notification['type']) {
                case 'coupon':
                    echo '<li>' . sprintf(
                        esc_html__('New coupon created: %1$s for %2$s (%3$s ago)', 'coupon-automation'),
                        '<strong>' . esc_html($notification['title']) . '</strong>',
                        '<strong>' . esc_html($notification['brand']) . '</strong>',
                        $time_diff
                    ) . '</li>';
                    break;

                case 'brand':
                    echo '<li>' . sprintf(
                        esc_html__('New brand created: %1$s (%2$s ago)', 'coupon-automation'),
                        '<strong>' . esc_html($notification['name']) . '</strong>',
                        $time_diff
                    ) . '</li>';
                    break;

                case 'system_alert':
                    echo '<li class="system-alert">' . sprintf(
                        esc_html__('System Alert [%1$s]: %2$s (%3$s ago)', 'coupon-automation'),
                        strtoupper($notification['level']),
                        esc_html($notification['message']),
                        $time_diff
                    ) . '</li>';
                    break;
            }
        }

        echo '</ul>';
        echo '<p><button type="button" class="button" id="clear-notifications" data-nonce="' .
            esc_attr($this->security->create_nonce('clear_notifications')) . '">' .
            esc_html__('Clear Notifications', 'coupon-automation') . '</button></p>';
        echo '</div>';
    }

    /**
     * Render main settings page
     */
    public function render_main_settings_page()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'coupon-automation'));
        }

        // Get current settings
        $settings = $this->settings->get_settings();
        $api_manager = coupon_automation()->get_service('api');
        $processing_status = $api_manager ? $api_manager->get_processing_status() : [];

?>
        <div class="wrap">
            <h1><?php esc_html_e('Coupon Automation Settings', 'coupon-automation'); ?></h1>

            <?php $this->render_status_dashboard($processing_status); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php
                wp_nonce_field('save_coupon_automation_settings', 'coupon_automation_settings_nonce');
                ?>
                <input type="hidden" name="action" value="save_coupon_automation_settings">

                <nav class="nav-tab-wrapper">
                    <a href="#api-keys" class="nav-tab nav-tab-active"><?php esc_html_e('API Configuration', 'coupon-automation'); ?></a>
                    <a href="#prompts" class="nav-tab"><?php esc_html_e('AI Prompts', 'coupon-automation'); ?></a>
                    <a href="#general" class="nav-tab"><?php esc_html_e('General Settings', 'coupon-automation'); ?></a>
                    <a href="#automation" class="nav-tab"><?php esc_html_e('Automation', 'coupon-automation'); ?></a>
                </nav>

                <div id="api-keys" class="tab-content active">
                    <?php $this->render_api_keys_section($settings); ?>
                </div>

                <div id="prompts" class="tab-content">
                    <?php $this->render_prompts_section($settings); ?>
                </div>

                <div id="general" class="tab-content">
                    <?php $this->render_general_section($settings); ?>
                </div>

                <div id="automation" class="tab-content">
                    <?php $this->render_automation_section($settings); ?>
                </div>

                <?php submit_button(__('Save Settings', 'coupon-automation')); ?>
            </form>

            <?php $this->render_control_panel(); ?>
        </div>

        <style>
            .nav-tab-wrapper {
                margin-bottom: 20px;
            }

            .tab-content {
                display: none;
            }

            .tab-content.active {
                display: block;
            }

            .nav-tab.nav-tab-active {
                background: #fff;
                border-bottom: 1px solid #fff;
            }

            .status-dashboard {
                background: #f9f9f9;
                padding: 20px;
                margin: 20px 0;
                border-left: 4px solid #0073aa;
            }

            .status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-top: 15px;
            }

            .status-item {
                background: white;
                padding: 15px;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .status-item h4 {
                margin: 0 0 10px;
                color: #0073aa;
            }

            .status-value {
                font-size: 24px;
                font-weight: bold;
                color: #333;
            }

            .status-label {
                font-size: 14px;
                color: #666;
            }

            .control-panel {
                background: #f9f9f9;
                padding: 20px;
                margin: 20px 0;
                border-left: 4px solid #d63638;
            }

            .coupon-forms_wrap {
                margin: 20px 0;
            }

            .fetch_buttons {
                margin: 10px 0;
            }

            .api-test-results {
                margin-top: 15px;
                padding: 10px;
                background: #f0f0f0;
                border-radius: 4px;
            }

            .api-test-success {
                color: #0073aa;
            }

            .api-test-error {
                color: #d63638;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Tab switching
                $('.nav-tab').click(function(e) {
                    e.preventDefault();

                    // Update tab states
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');

                    // Show corresponding content
                    $('.tab-content').removeClass('active');
                    $($(this).attr('href')).addClass('active');
                });

                // Clear notifications
                $(document).on('click', '#clear-notifications', function() {
                    var button = $(this);
                    var nonce = button.data('nonce');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'clear_notifications',
                            nonce: nonce
                        },
                        beforeSend: function() {
                            button.prop('disabled', true).text('Clearing...');
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#coupon-automation-notifications').fadeOut();
                            }
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Clear Notifications');
                        }
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render status dashboard
     * 
     * @param array $processing_status Current processing status
     */
    private function render_status_dashboard($processing_status)
    {
        $coupon_manager = coupon_automation()->get_service('coupon');
        $brand_manager = coupon_automation()->get_service('brand');

        $coupon_stats = $coupon_manager ? $coupon_manager->get_coupon_statistics() : [];
        $brand_stats = $brand_manager ? $brand_manager->get_brand_statistics() : [];

    ?>
        <div class="status-dashboard">
            <h3><?php esc_html_e('System Status', 'coupon-automation'); ?></h3>

            <div class="status-grid">
                <div class="status-item">
                    <h4><?php esc_html_e('Processing Status', 'coupon-automation'); ?></h4>
                    <div class="status-value">
                        <?php
                        if (!empty($processing_status['is_running'])) {
                            echo '<span style="color: #d63638;">' . esc_html__('Running', 'coupon-automation') . '</span>';
                        } else {
                            echo '<span style="color: #00a32a;">' . esc_html__('Idle', 'coupon-automation') . '</span>';
                        }
                        ?>
                    </div>
                    <div class="status-label">
                        <?php
                        $last_sync = $processing_status['last_sync'] ?? '';
                        if ($last_sync) {
                            echo esc_html(sprintf(
                                __('Last sync: %s', 'coupon-automation'),
                                human_time_diff(strtotime($last_sync), current_time('timestamp')) . ' ago'
                            ));
                        } else {
                            esc_html_e('Never synchronized', 'coupon-automation');
                        }
                        ?>
                    </div>
                </div>

                <div class="status-item">
                    <h4><?php esc_html_e('Active Coupons', 'coupon-automation'); ?></h4>
                    <div class="status-value"><?php echo absint($coupon_stats['total_published'] ?? 0); ?></div>
                    <div class="status-label">
                        <?php echo absint($coupon_stats['expired_count'] ?? 0); ?> <?php esc_html_e('expired', 'coupon-automation'); ?>
                    </div>
                </div>

                <div class="status-item">
                    <h4><?php esc_html_e('Total Brands', 'coupon-automation'); ?></h4>
                    <div class="status-value"><?php echo absint($brand_stats['total_brands'] ?? 0); ?></div>
                    <div class="status-label">
                        <?php echo absint($brand_stats['brands_with_coupons'] ?? 0); ?> <?php esc_html_e('with coupons', 'coupon-automation'); ?>
                    </div>
                </div>

                <div class="status-item">
                    <h4><?php esc_html_e('Recent Activity', 'coupon-automation'); ?></h4>
                    <div class="status-value"><?php echo absint($coupon_stats['recent_count'] ?? 0); ?></div>
                    <div class="status-label"><?php esc_html_e('new coupons this week', 'coupon-automation'); ?></div>
                </div>

                <div class="daily-processing-status" style="margin: 20px 0; padding: 15px; background: #f0f0f0;">
                    <h4>Daily Processing Status</h4>
                    <p id="daily-status">Loading status...</p>
                </div>

                <script>
                    jQuery(document).ready(function($) {
                        // Load daily status
                        function loadDailyStatus() {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'get_processing_status',
                                    nonce: couponAutomation.settings_nonce
                                },
                                success: function(response) {
                                    if (response.success) {
                                        var status = response.data;
                                        var statusText = '';

                                        if (status.completed_today) {
                                            statusText = '✅ Processing completed for today (' + status.today + ')';
                                        } else if (status.is_running) {
                                            statusText = '🔄 Currently processing... (' + status.processed_count + ' items processed)';
                                        } else if (status.in_processing_window) {
                                            statusText = '⏰ Ready to process (window: 00:00 - 06:00)';
                                        } else {
                                            statusText = '⏸️ Outside processing window (current: ' + status.current_hour + ':00)';
                                        }

                                        $('#daily-status').text(statusText);
                                    }
                                }
                            });
                        }

                        // Load status on page load
                        loadDailyStatus();

                        // Refresh status every 30 seconds
                        setInterval(loadDailyStatus, 30000);
                    });
                </script>
            </div>
        </div>
    <?php
    }

    /**
     * Render API keys section
     * 
     * @param array $settings Current settings
     */
    private function render_api_keys_section($settings)
    {
    ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="addrevenue_api_token"><?php esc_html_e('AddRevenue API Token', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="password" id="addrevenue_api_token" name="api_keys[addrevenue_api_token]"
                        value="<?php echo $this->get_masked_api_key('addrevenue_api_token'); ?>"
                        class="regular-text" autocomplete="new-password" />
                    <p class="description">
                        <?php esc_html_e('Your AddRevenue API token for fetching advertiser and campaign data.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="awin_api_token"><?php esc_html_e('AWIN API Token', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="password" id="awin_api_token" name="api_keys[awin_api_token]"
                        value="<?php echo $this->get_masked_api_key('awin_api_token'); ?>"
                        class="regular-text" autocomplete="new-password" />
                    <p class="description">
                        <?php esc_html_e('Your AWIN API token for accessing promotion data.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="awin_publisher_id"><?php esc_html_e('AWIN Publisher ID', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="text" id="awin_publisher_id" name="api_keys[awin_publisher_id]"
                        value="<?php echo esc_attr($this->settings->get_api_key('awin_publisher_id')); ?>"
                        class="regular-text" />
                    <p class="description">
                        <?php esc_html_e('Your AWIN Publisher ID (numeric).', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="openai_api_key"><?php esc_html_e('OpenAI API Key', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="password" id="openai_api_key" name="api_keys[openai_api_key]"
                        value="<?php echo $this->get_masked_api_key('openai_api_key'); ?>"
                        class="regular-text" autocomplete="new-password" />
                    <p class="description">
                        <?php esc_html_e('Your OpenAI API key for content generation (titles, descriptions, etc.).', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="yourl_api_token"><?php esc_html_e('YOURLS API Token', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="password" id="yourl_api_token" name="api_keys[yourl_api_token]"
                        value="<?php echo $this->get_masked_api_key('yourl_api_token'); ?>"
                        class="regular-text" autocomplete="new-password" />
                    <p class="description">
                        <?php esc_html_e('Your YOURLS API token for URL shortening (optional).', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <div class="api-test-section">
            <h4><?php esc_html_e('Test API Connections', 'coupon-automation'); ?></h4>
            <button type="button" id="test-api-connections" class="button button-secondary">
                <?php esc_html_e('Test All Connections', 'coupon-automation'); ?>
            </button>
            <div id="api-test-results" class="api-test-results" style="display: none;"></div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#test-api-connections').click(function() {
                    var button = $(this);
                    var resultsDiv = $('#api-test-results');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_api_connections',
                            nonce: couponAutomation.settings_nonce
                        },
                        beforeSend: function() {
                            button.prop('disabled', true).text('Testing...');
                            resultsDiv.show().html('<p>Testing API connections...</p>');
                        },
                        success: function(response) {
                            if (response.success && response.data.results) {
                                var html = '<h5>Test Results:</h5><ul>';
                                $.each(response.data.results, function(api, result) {
                                    var className = result.status === 'success' ? 'api-test-success' : 'api-test-error';
                                    html += '<li class="' + className + '"><strong>' + api.toUpperCase() + ':</strong> ' + result.message + '</li>';
                                });
                                html += '</ul>';
                                resultsDiv.html(html);
                            } else {
                                resultsDiv.html('<p class="api-test-error">Failed to test connections.</p>');
                            }
                        },
                        error: function() {
                            resultsDiv.html('<p class="api-test-error">An error occurred during testing.</p>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Test All Connections');
                        }
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render prompts section
     * 
     * @param array $settings Current settings
     */
    private function render_prompts_section($settings)
    {
        $prompts = $settings['prompts'] ?? [];
    ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="coupon_title_prompt"><?php esc_html_e('Coupon Title Prompt', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <textarea id="coupon_title_prompt" name="prompts[coupon_title_prompt]"
                        rows="3" class="large-text"><?php echo esc_textarea($prompts['coupon_title_prompt'] ?? ''); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Prompt for generating coupon titles. Keep it concise and specific.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="description_prompt"><?php esc_html_e('Coupon Terms Prompt', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <textarea id="description_prompt" name="prompts[description_prompt]"
                        rows="3" class="large-text"><?php echo esc_textarea($prompts['description_prompt'] ?? ''); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Prompt for converting coupon descriptions into terms and conditions.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="brand_description_prompt"><?php esc_html_e('Brand Description Prompt', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <textarea id="brand_description_prompt" name="prompts[brand_description_prompt]"
                        rows="4" class="large-text"><?php echo esc_textarea($prompts['brand_description_prompt'] ?? ''); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Prompt for generating brand descriptions. Use [BRAND_NAME] as placeholder.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="why_we_love_prompt"><?php esc_html_e('Why We Love Prompt', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <textarea id="why_we_love_prompt" name="prompts[why_we_love_prompt]"
                        rows="3" class="large-text"><?php echo esc_textarea($prompts['why_we_love_prompt'] ?? ''); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Prompt for generating "Why We Love" content for brands.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>
        </table>
    <?php
    }

    /**
     * Render general settings section
     * 
     * @param array $settings Current settings
     */
    private function render_general_section($settings)
    {
        $general = $settings['general'] ?? [];
    ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="batch_size"><?php esc_html_e('Batch Size', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="number" id="batch_size" name="general[batch_size]"
                        value="<?php echo absint($general['batch_size'] ?? 10); ?>"
                        min="1" max="100" class="small-text" />
                    <p class="description">
                        <?php esc_html_e('Number of items to process in each batch (1-100).', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="api_timeout"><?php esc_html_e('API Timeout', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="number" id="api_timeout" name="general[api_timeout]"
                        value="<?php echo absint($general['api_timeout'] ?? 30); ?>"
                        min="5" max="300" class="small-text" />
                    <span><?php esc_html_e('seconds', 'coupon-automation'); ?></span>
                    <p class="description">
                        <?php esc_html_e('Timeout for API requests in seconds (5-300).', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="log_retention_days"><?php esc_html_e('Log Retention', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="number" id="log_retention_days" name="general[log_retention_days]"
                        value="<?php echo absint($general['log_retention_days'] ?? 30); ?>"
                        min="1" max="365" class="small-text" />
                    <span><?php esc_html_e('days', 'coupon-automation'); ?></span>
                    <p class="description">
                        <?php esc_html_e('How long to keep log files (1-365 days).', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="enable_debug_logging"><?php esc_html_e('Debug Logging', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="enable_debug_logging" name="general[enable_debug_logging]"
                        value="1" <?php checked(!empty($general['enable_debug_logging'])); ?> />
                    <label for="enable_debug_logging"><?php esc_html_e('Enable detailed debug logging', 'coupon-automation'); ?></label>
                    <p class="description">
                        <?php esc_html_e('Enable this for troubleshooting. May impact performance.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="fallback_terms"><?php esc_html_e('Fallback Terms', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <textarea id="fallback_terms" name="general[fallback_terms]"
                        rows="4" class="large-text"><?php echo esc_textarea($general['fallback_terms'] ?? ''); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Default terms to use when AI generation fails. One term per line.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>
        </table>
    <?php
    }

    /**
     * Render automation settings section
     * 
     * @param array $settings Current settings
     */
    private function render_automation_section($settings)
    {
        $automation = $settings['automation'] ?? [];
    ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="auto_schedule_enabled"><?php esc_html_e('Auto Scheduling', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="auto_schedule_enabled" name="automation[auto_schedule_enabled]"
                        value="1" <?php checked(!empty($automation['auto_schedule_enabled'])); ?> />
                    <label for="auto_schedule_enabled"><?php esc_html_e('Enable automatic coupon processing', 'coupon-automation'); ?></label>
                    <p class="description">
                        <?php esc_html_e('When enabled, the system will automatically fetch and process coupons.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="schedule_interval"><?php esc_html_e('Schedule Interval', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <select id="schedule_interval" name="automation[schedule_interval]">
                        <option value="hourly" <?php selected($automation['schedule_interval'] ?? 'daily', 'hourly'); ?>>
                            <?php esc_html_e('Hourly', 'coupon-automation'); ?>
                        </option>
                        <option value="twicedaily" <?php selected($automation['schedule_interval'] ?? 'daily', 'twicedaily'); ?>>
                            <?php esc_html_e('Twice Daily', 'coupon-automation'); ?>
                        </option>
                        <option value="daily" <?php selected($automation['schedule_interval'] ?? 'daily', 'daily'); ?>>
                            <?php esc_html_e('Daily', 'coupon-automation'); ?>
                        </option>
                        <option value="weekly" <?php selected($automation['schedule_interval'] ?? 'daily', 'weekly'); ?>>
                            <?php esc_html_e('Weekly', 'coupon-automation'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('How often to automatically process coupons.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="max_processing_time"><?php esc_html_e('Max Processing Time', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="number" id="max_processing_time" name="automation[max_processing_time]"
                        value="<?php echo absint($automation['max_processing_time'] ?? 300); ?>"
                        min="60" max="1800" class="small-text" />
                    <span><?php esc_html_e('seconds', 'coupon-automation'); ?></span>
                    <p class="description">
                        <?php esc_html_e('Maximum time for a single processing session (60-1800 seconds).', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="enable_notifications"><?php esc_html_e('Notifications', 'coupon-automation'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="enable_notifications" name="automation[enable_notifications]"
                        value="1" <?php checked(!empty($automation['enable_notifications'])); ?> />
                    <label for="enable_notifications"><?php esc_html_e('Enable admin notifications', 'coupon-automation'); ?></label>
                    <p class="description">
                        <?php esc_html_e('Show notifications for new coupons, brands, and system alerts.', 'coupon-automation'); ?>
                    </p>
                </td>
            </tr>
        </table>
    <?php
    }

    /**
     * Render control panel section
     */
    private function render_control_panel()
    {
    ?>
        <div class="control-panel">
            <h3><?php esc_html_e('Control Panel', 'coupon-automation'); ?></h3>
            <p><?php esc_html_e('Manual controls for coupon automation and maintenance tasks.', 'coupon-automation'); ?></p>

            <div class="coupon-forms_wrap">
                <div class="fetch_buttons">
                    <button type="button" id="fetch-coupons-button" class="button button-primary">
                        <?php esc_html_e('Start Automation', 'coupon-automation'); ?>
                    </button>

                    <button type="button" id="stop-automation-button" class="button button-secondary">
                        <?php esc_html_e('Stop Automation', 'coupon-automation'); ?>
                    </button>

                    <button type="button" id="clear-flags-button" class="button">
                        <?php esc_html_e('Clear Cache', 'coupon-automation'); ?>
                    </button>

                    <button type="button" id="purge-expired-coupons" class="button">
                        <?php esc_html_e('Purge Expired Coupons', 'coupon-automation'); ?>
                    </button>
                </div>

                <div class="coupon-messages"></div>
            </div>
        </div>
    <?php
    }

    /**
     * Render brand population page
     */
    public function render_brand_population_page()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'coupon-automation'));
        }

        $brand_manager = coupon_automation()->get_service('brand');
        $brands_needing_updates = $brand_manager ? $brand_manager->get_brands_needing_updates() : [];

    ?>
        <div class="wrap">
            <h1><?php esc_html_e('Populate Brand Content', 'coupon-automation'); ?></h1>

            <div class="notice notice-info">
                <p>
                    <?php esc_html_e('This tool will automatically generate missing content for your brands including descriptions, "Why We Love" sections, and other metadata.', 'coupon-automation'); ?>
                </p>
            </div>

            <div class="brand-population-status">
                <h3><?php esc_html_e('Brand Status Overview', 'coupon-automation'); ?></h3>
                <p>
                    <?php
                    echo sprintf(
                        esc_html__('Found %d brands that need content updates.', 'coupon-automation'),
                        count($brands_needing_updates)
                    );
                    ?>
                </p>

                <?php if (!empty($brands_needing_updates)): ?>
                    <details>
                        <summary><?php esc_html_e('View brands needing updates', 'coupon-automation'); ?></summary>
                        <ul>
                            <?php foreach (array_slice($brands_needing_updates, 0, 20) as $brand_info): ?>
                                <li>
                                    <strong><?php echo esc_html($brand_info['brand']->name); ?></strong>
                                    - Issues: <?php echo esc_html(implode(', ', $brand_info['issues'])); ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($brands_needing_updates) > 20): ?>
                                <li><em><?php echo sprintf(esc_html__('... and %d more', 'coupon-automation'), count($brands_needing_updates) - 20); ?></em></li>
                            <?php endif; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>

            <div class="brand-population-controls">
                <button type="button" id="start-population" class="button button-primary">
                    <?php esc_html_e('Start Brand Population', 'coupon-automation'); ?>
                </button>

                <button type="button" id="stop-population" class="button button-secondary" style="display: none;">
                    <?php esc_html_e('Stop Processing', 'coupon-automation'); ?>
                </button>
            </div>

            <div class="brand-population-progress" style="display: none;">
                <h4><?php esc_html_e('Processing Progress', 'coupon-automation'); ?></h4>
                <div class="progress-bar">
                    <div class="progress"></div>
                </div>
                <p>
                    <?php esc_html_e('Processed:', 'coupon-automation'); ?>
                    <span class="processed-count">0</span> / <span class="total-count">0</span>
                </p>
            </div>

            <div class="population-log">
                <h4><?php esc_html_e('Processing Log', 'coupon-automation'); ?></h4>
                <div class="log-entries">
                    <!-- Log entries will be populated here -->
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Save settings handler
     */
    public function save_settings()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to save settings.', 'coupon-automation'));
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['coupon_automation_settings_nonce'] ?? '', 'save_coupon_automation_settings')) {
            wp_die(esc_html__('Security check failed. Please try again.', 'coupon-automation'));
        }

        $this->logger->info('Settings save attempt', [
            'user_id' => get_current_user_id(),
            'settings_sections' => array_keys($_POST)
        ]);

        try {
            // Prepare settings data
            $new_settings = [
                'api_keys' => $_POST['api_keys'] ?? [],
                'prompts' => $_POST['prompts'] ?? [],
                'general' => $_POST['general'] ?? [],
                'automation' => $_POST['automation'] ?? []
            ];

            // Sanitize settings
            $sanitized_settings = $this->settings->sanitize_settings($new_settings);

            // Validate settings
            $validation_errors = $this->settings->validate_settings($sanitized_settings);

            if (!empty($validation_errors)) {
                $this->add_settings_error('validation_failed', implode('<br>', $validation_errors));
                $this->redirect_to_settings();
                return;
            }

            // Save settings
            $current_settings = $this->settings->get_settings();
            $merged_settings = array_merge($current_settings, $sanitized_settings);

            $success = update_option(Coupon_Automation_Settings::OPTION_NAME, $merged_settings);

            if ($success) {
                $this->logger->info('Settings saved successfully', [
                    'user_id' => get_current_user_id()
                ]);

                $this->add_settings_error('settings_saved', __('Settings saved successfully.', 'coupon-automation'), 'success');
            } else {
                $this->add_settings_error('save_failed', __('Failed to save settings. Please try again.', 'coupon-automation'));
            }
        } catch (Exception $e) {
            $this->logger->error('Settings save failed', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);

            $this->add_settings_error('save_exception', __('An error occurred while saving settings.', 'coupon-automation'));
        }

        $this->redirect_to_settings();
    }

    /**
     * Get masked API key for display
     * 
     * @param string $key_name API key name
     * @return string Masked key or empty string
     */
    private function get_masked_api_key($key_name)
    {
        $api_key = $this->settings->get_api_key($key_name);

        if (!empty($api_key)) {
            // Show partial key for verification instead of all asterisks
            if (strlen($api_key) > 8) {
                return substr($api_key, 0, 4) . str_repeat('*', 12) . substr($api_key, -4);
            }
            return str_repeat('*', min(strlen($api_key), 20));
        }

        return '';
    }

    /**
     * Add settings error message
     * 
     * @param string $code Error code
     * @param string $message Error message
     * @param string $type Message type (error|success|info)
     */
    private function add_settings_error($code, $message, $type = 'error')
    {
        set_transient('coupon_automation_admin_notice', [
            'code' => $code,
            'message' => $message,
            'type' => $type
        ], 30);
    }

    /**
     * Redirect to settings page
     */
    private function redirect_to_settings()
    {
        $redirect_url = add_query_arg([
            'page' => 'coupon-automation',
            'settings-updated' => 'true'
        ], admin_url('options-general.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Display settings notices
     */
    public function display_settings_notices()
    {
        $notice = get_transient('coupon_automation_admin_notice');

        if (!$notice) {
            return;
        }

        $class = 'notice notice-' . ($notice['type'] === 'success' ? 'success' : 'error');

        echo '<div class="' . esc_attr($class) . ' is-dismissible">';
        echo '<p>' . esc_html($notice['message']) . '</p>';
        echo '</div>';

        delete_transient('coupon_automation_admin_notice');
    }
}
