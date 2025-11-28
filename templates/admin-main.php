<div class="wrap coupon-automation-admin" id="coupon-automation-admin">
    <div class="ca-container">
        <!-- Header -->
        <div class="ca-hero">
            <h1>Coupon Automation</h1>
            <p>Manage API integrations and automate coupon generation</p>
        </div>

        <!-- Quick Actions -->
        <div class="ca-section">
            <div class="ca-section-header">
                <h2 class="ca-section-title">Quick Actions</h2>
            </div>
            <div class="ca-section-body">
                <div class="ca-buttons">
                    <button id="fetch-coupons-btn" class="ca-btn ca-btn-primary">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                        </svg>
                        Start Sync
                    </button>
                    <button id="test-sync-btn" class="ca-btn ca-btn-success">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Test Sync
                    </button>
                    <button id="stop-automation-btn" class="ca-btn ca-btn-danger">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Stop Sync
                    </button>
                    <button id="clear-cache-btn" class="ca-btn ca-btn-secondary">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        Clear Cache
                    </button>
                    <button id="purge-expired-btn" class="ca-btn ca-btn-warning">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Purge Expired
                    </button>
                    <button id="purge-duplicates-btn" class="ca-btn ca-btn-info">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                        Purge Duplicates
                    </button>
                </div>
            </div>
        </div>
        <!-- Status Cards -->
        <div class="ca-grid ca-grid-4">
            <div class="ca-card">
                <div class="ca-flex-between">
                    <div>
                        <p class="ca-card-label">Total Brands</p>
                        <p class="ca-card-value"><?php echo wp_count_terms('brands', ['hide_empty' => false]); ?></p>
                    </div>
                    <div class="ca-card-icon" style="color: var(--ca-blue);">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="ca-card">
                <div class="ca-flex-between">
                    <div>
                        <p class="ca-card-label">Total Coupons</p>
                        <p class="ca-card-value"><?php echo wp_count_posts('coupons')->publish; ?></p>
                    </div>
                    <div class="ca-card-icon" style="color: var(--ca-green);">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="ca-card">
                <div class="ca-flex-between">
                    <div>
                        <p class="ca-card-label">Last Sync</p>
                        <p class="ca-card-value" style="font-size: 16px;">
                            <?php
                            $lastSync = get_option('coupon_automation_last_sync', 'Never');
                            echo is_numeric($lastSync) ? human_time_diff($lastSync) . ' ago' : $lastSync;
                            ?>
                        </p>
                    </div>
                    <div class="ca-card-icon" style="color: var(--ca-yellow);">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="ca-card">
                <div class="ca-flex-between">
                    <div>
                        <p class="ca-card-label">API Status</p>
                        <div style="display:flex;gap:.5rem;margin-top:.25rem;">
                            <span class="ca-status-dot api-status-indicator" title="AddRevenue" data-api="addrevenue"></span>
                            <span class="ca-status-dot api-status-indicator" title="AWIN" data-api="awin"></span>
                            <span class="ca-status-dot api-status-indicator" title="OpenAI" data-api="openai"></span>
                            <span class="ca-status-dot api-status-indicator" title="YOURLS" data-api="yourls"></span>
                        </div>
                    </div>
                    <div class="ca-card-icon" style="color: var(--ca-purple);">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
        <div class="ca-section">
            <div class="ca-section-header">
                <h2 class="ca-section-title">Sync Status</h2>
            </div>
            <div class="ca-section-body">
                <?php
                // Get status data
                $sync_status = get_option('coupon_automation_sync_status', 'never_run');
                $last_sync = get_option('coupon_automation_last_sync', 0);
                $last_stats = get_option('coupon_automation_last_sync_stats', []);
                $is_running = get_transient('fetch_process_running');
                $next_scheduled = wp_next_scheduled('coupon_automation_daily_fetch');

                // Determine status display
                $status_text = '';
                $status_class = '';

                if ($is_running) {
                    $status_text = 'Processing - Sync in progress';
                    $status_class = 'ca-status-running';
                } elseif ($sync_status === 'never_run') {
                    $status_text = 'Never Run - Click "Start Sync" to begin';
                    $status_class = 'ca-status-idle';
                } elseif ($sync_status === 'success') {
                    $status_text = 'Idle - All systems operational';
                    $status_class = 'ca-status-success';
                } elseif ($sync_status === 'failed') {
                    $status_text = 'Attention Required - Check logs for recent errors';
                    $status_class = 'ca-status-warning';
                }

                // Format times
                $last_run_text = $last_sync ? human_time_diff($last_sync, current_time('timestamp')) . ' ago' : 'Never';
                $next_run_text = $next_scheduled ? date('M j, g:i A', $next_scheduled) : 'Not scheduled';
                ?>

                <div class="ca-status-grid">
                    <div class="ca-status-item">
                        <label>Status:</label>
                        <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </div>
                    <div class="ca-status-item">
                        <label>Last Run:</label>
                        <span><?php echo $last_run_text; ?></span>
                        <?php if (!empty($last_stats)): ?>
                            <small>(Processed: <?php echo $last_stats['processed'] ?? 0; ?>, Failed: <?php echo $last_stats['failed'] ?? 0; ?>)</small>
                        <?php endif; ?>
                    </div>
                    <div class="ca-status-item">
                        <label>Next Run:</label>
                        <span><?php echo $next_run_text; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity Section -->
        <div class="ca-section">
            <div class="ca-section-header">
                <h2 class="ca-section-title">Recent Activity</h2>
            </div>
            <div class="ca-section-body">
                <div class="ca-activity-feed">
                    <?php
                    $logger = new \CouponAutomation\Utils\Logger();
                    $activities = $logger->getRecentActivities(5);

                    if (empty($activities)): ?>
                        <p class="ca-muted">No recent activity. Start a sync to see activity here.</p>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <div class="ca-activity-item">
                                <span class="ca-activity-time">
                                    <?php echo human_time_diff($activity['time'], current_time('timestamp')); ?> ago
                                </span>
                                <span class="ca-activity-message ca-activity-<?php echo $activity['type']; ?>">
                                    <?php echo esc_html($activity['message']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Settings Form -->
        <form method="post" action="options.php">
            <?php settings_fields($settings->getOptionGroup()); ?>
            <div class="ca-section">
                <div class="ca-tabs">
                    <button type="button" class="ca-tab active" data-tab="api-credentials">API Credentials</button>
                    <button type="button" class="ca-tab" data-tab="ai-prompts">AI Prompts</button>
                    <button type="button" class="ca-tab" data-tab="advanced">Advanced</button>
                </div>

                <div class="ca-tab-content active" id="api-credentials-tab">
                    <div class="ca-form-grid">
                        <?php foreach ($settings->getSections()['api_credentials']['fields'] as $field_name => $field): ?>
                            <div class="ca-form-group">
                                <label class="ca-form-label"><?php echo $field['label']; ?></label>
                                <?php
                                    $api_name = str_replace(['_api_token', '_api_key', '_username', '_password', '_publisher_id'], '', $field_name);
                                    $show_test_button = !in_array($field_name, ['yourls_username', 'yourls_password', 'awin_publisher_id']);
                                ?>
                                <div class="ca-input-with-button">
                                    <input
                                        type="<?php echo $field['type']; ?>"
                                        name="<?php echo $field_name; ?>"
                                        value="<?php echo esc_attr($settings->getFieldValue($field_name)); ?>"
                                        class="ca-form-input" />
                                    <?php if ($show_test_button): ?>
                                        <button type="button" class="ca-test-btn test-api-btn" data-api="<?php echo esc_attr($api_name); ?>" aria-label="<?php echo esc_attr(sprintf(__('Test %s', 'coupon-automation'), $field['label'])); ?>"><?php esc_html_e('Test', 'coupon-automation'); ?></button>
                                    <?php endif; ?>
                                </div>
                                <p class="ca-form-help"><?php echo $field['description']; ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ca-tab-content" id="ai-prompts-tab">
                    <?php foreach ($settings->getSections()['prompts']['fields'] as $field_name => $field): ?>
                        <div class="ca-form-group">
                            <label class="ca-form-label"><?php echo $field['label']; ?></label>
                            <?php if ($field['type'] === 'textarea'): ?>
                                <textarea name="<?php echo $field_name; ?>" rows="4" class="ca-form-input"><?php echo esc_textarea($settings->getFieldValue($field_name)); ?></textarea>
                            <?php elseif ($field['type'] === 'editor'): ?>
                                <?php wp_editor($settings->getFieldValue($field_name), $field_name, ['textarea_name' => $field_name, 'textarea_rows' => 8, 'media_buttons' => false,]); ?>
                            <?php endif; ?>
                            <p class="ca-form-help"><?php echo $field['description']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="ca-tab-content" id="advanced-tab">
                    <div class="ca-form-group">
                        <label class="ca-form-label" style="display:flex;align-items:center;justify-content:space-between;">
                            <span>Enable Logging</span>
                            <label class="ca-toggle">
                                <input type="checkbox" name="coupon_automation_enable_logging" value="1" <?php checked(get_option('coupon_automation_enable_logging', true)); ?>>
                                <span class="ca-toggle-slider"></span>
                            </label>
                        </label>
                        <p class="ca-form-help">Keep detailed logs of all operations</p>
                    </div>
                    <div class="ca-form-group">
                        <label class="ca-form-label">Batch Size</label>
                        <input type="number" name="coupon_automation_batch_size" value="<?php echo get_option('coupon_automation_batch_size', 5); ?>" min="1" max="50" class="ca-form-input" style="width: 120px;" />
                        <p class="ca-form-help">Number of items to process per batch</p>
                    </div>
                    <div class="ca-form-group">
                        <label class="ca-form-label">OpenAI Model</label>
                        <select name="openai_model" class="ca-form-input" style="width: 300px;">
                            <option value="gpt-4o-mini" <?php selected(get_option('openai_model', 'gpt-4o-mini'), 'gpt-4o-mini'); ?>>GPT-4o Mini (Fast, Cost-effective) ⚡</option>
                            <option value="gpt-4o" <?php selected(get_option('openai_model', 'gpt-4o-mini'), 'gpt-4o'); ?>>GPT-4o (Balanced)</option>
                            <option value="gpt-4-turbo" <?php selected(get_option('openai_model', 'gpt-4o-mini'), 'gpt-4-turbo'); ?>>GPT-4 Turbo (Advanced)</option>
                            <option value="o1-mini" <?php selected(get_option('openai_model', 'gpt-4o-mini'), 'o1-mini'); ?>>O1 Mini (Reasoning) 🧠</option>
                            <option value="gpt-5-mini" <?php selected(get_option('openai_model', 'gpt-4o-mini'), 'gpt-5-mini'); ?>>GPT-5 Mini (Latest Reasoning) 🚀</option>
                        </select>
                        <p class="ca-form-help">Select the OpenAI model for content generation. GPT-4 models support temperature control for creativity adjustment. O1/GPT-5 models use advanced reasoning but don't support temperature.</p>
                    </div>
                    <div class="ca-form-group">
                        <label class="ca-form-label">API Timeout (seconds)</label>
                        <input type="number" name="coupon_automation_api_timeout" value="<?php echo get_option('coupon_automation_api_timeout', 45); ?>" min="15" max="180" class="ca-form-input" style="width: 120px;" />
                        <p class="ca-form-help">Increase if network/API responses are slow</p>
                    </div>
                    <div class="ca-form-group">
                        <label class="ca-form-label">CLI Tip for reliable cron</label>
                        <p class="ca-form-help">
                            Run via WP-CLI with higher limits if web cron times out:<br>
                            <code>php -d memory_limit=512M -d max_execution_time=0 wp cron event run coupon_automation_daily_fetch --path=/var/www/html</code>
                        </p>
                    </div>
                    <div class="ca-form-group">
                        <label class="ca-form-label">API Timeout (seconds)</label>
                        <input type="number" name="coupon_automation_api_timeout" value="<?php echo get_option('coupon_automation_api_timeout', 30); ?>" min="10" max="120" class="ca-form-input" style="width: 120px;" />
                        <p class="ca-form-help">Maximum time to wait for API responses</p>
                    </div>
                </div>

                <div style="padding: 20px; background: #f9fafb; border-top: 1px solid var(--ca-border); border-radius: 0 0 .5rem .5rem;">
                    <button type="submit" class="ca-btn ca-btn-primary">Save Settings</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<!-- Test Sync Modal -->
<div id="test-sync-modal" class="ca-modal" style="display: none;">
    <div class="ca-modal-overlay"></div>
    <div class="ca-modal-content" style="max-width: 900px;">
        <div class="ca-modal-header">
            <h3 class="ca-modal-title">Test Sync Results</h3>
            <button class="ca-modal-close">&times;</button>
        </div>
        <div class="ca-modal-body">
            <div id="test-sync-results" style="display: none;">
                <!-- Results will be inserted here -->
            </div>
            <div id="test-sync-loading" style="display: none; text-align: center; padding: 40px;">
                <div class="ca-spinner"></div>
                <p style="margin-top: 20px;">Testing APIs and generating sample titles...<br><small>This may take 30-60 seconds</small></p>
            </div>
        </div>
        <div class="ca-modal-footer">
            <button class="ca-btn ca-btn-secondary ca-modal-close">Close</button>
        </div>
    </div>
</div>

<!-- Purge Duplicates Modal -->
<div id="purge-duplicates-modal" class="ca-modal" style="display: none;">
    <div class="ca-modal-overlay"></div>
    <div class="ca-modal-content">
        <div class="ca-modal-header">
            <h3 class="ca-modal-title">Purge Duplicate Coupons</h3>
            <button class="ca-modal-close">&times;</button>
        </div>
        <div class="ca-modal-body">
            <div id="purge-duplicates-preview" style="display: none;">
                <!-- Preview content will be inserted here -->
            </div>
            <div id="purge-duplicates-loading" style="display: none; text-align: center; padding: 20px;">
                <div class="ca-spinner"></div>
                <p>Analyzing coupons...</p>
            </div>
        </div>
        <div class="ca-modal-footer">
            <button id="confirm-purge-btn" class="ca-btn ca-btn-danger" style="display: none;">Confirm Purge</button>
            <button class="ca-btn ca-btn-secondary ca-modal-close">Cancel</button>
        </div>
    </div>
</div>

<script>
    jQuery(function($) {
        $('.ca-tab').on('click', function(e) {
            e.preventDefault();
            var tabId = $(this).data('tab');
            $('.ca-tab').removeClass('active');
            $(this).addClass('active');
            $('.ca-tab-content').removeClass('active');
            $('#' + tabId + '-tab').addClass('active');
        });

        // Test Sync functionality
        $('#test-sync-btn').on('click', function() {
            $('#test-sync-modal').fadeIn(200);
            $('#test-sync-loading').show();
            $('#test-sync-results').hide();

            $.ajax({
                url: couponAutomation.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'test_sync',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    $('#test-sync-loading').hide();

                    if (response.success) {
                        const data = response.data;
                        let html = '';

                        // API Status Section
                        html += '<div style="margin-bottom: 30px;">';
                        html += '<h4 style="margin: 0 0 15px; font-size: 18px; font-weight: 600;">API Connection Status</h4>';
                        html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';

                        // AddRevenue Status
                        if (data.api_status.addrevenue) {
                            const ar = data.api_status.addrevenue;
                            html += '<div style="padding: 15px; background: ' + (ar.connected ? '#d1fae5' : '#fee2e2') + '; border-radius: 8px;">';
                            html += '<div style="font-weight: 600; margin-bottom: 5px;">AddRevenue</div>';
                            html += '<div style="font-size: 14px;">' + (ar.connected ? '✅ Connected' : '❌ Failed') + '</div>';
                            if (ar.connected) {
                                html += '<div style="font-size: 12px; margin-top: 5px; color: #065f46;">' + ar.advertisers + ' advertisers, ' + ar.campaigns + ' campaigns</div>';
                            } else {
                                html += '<div style="font-size: 12px; margin-top: 5px; color: #991b1b;">' + ar.error + '</div>';
                            }
                            html += '</div>';
                        }

                        // AWIN Status
                        if (data.api_status.awin) {
                            const awin = data.api_status.awin;
                            html += '<div style="padding: 15px; background: ' + (awin.connected ? '#d1fae5' : '#fee2e2') + '; border-radius: 8px;">';
                            html += '<div style="font-weight: 600; margin-bottom: 5px;">AWIN</div>';
                            html += '<div style="font-size: 14px;">' + (awin.connected ? '✅ Connected' : '❌ Failed') + '</div>';
                            if (awin.connected) {
                                html += '<div style="font-size: 12px; margin-top: 5px; color: #065f46;">' + awin.promotions + ' promotions</div>';
                            } else {
                                html += '<div style="font-size: 12px; margin-top: 5px; color: #991b1b;">' + awin.error + '</div>';
                            }
                            html += '</div>';
                        }

                        // OpenAI Status
                        if (data.api_status.openai) {
                            const openai = data.api_status.openai;
                            html += '<div style="padding: 15px; background: ' + (openai.connected ? '#d1fae5' : '#fee2e2') + '; border-radius: 8px;">';
                            html += '<div style="font-weight: 600; margin-bottom: 5px;">OpenAI</div>';
                            html += '<div style="font-size: 14px;">' + (openai.connected ? '✅ Connected' : '❌ Failed') + '</div>';
                            if (openai.connected) {
                                html += '<div style="font-size: 12px; margin-top: 5px; color: #065f46;">Model: ' + openai.model + '</div>';
                            } else {
                                html += '<div style="font-size: 12px; margin-top: 5px; color: #991b1b;">' + openai.error + '</div>';
                            }
                            html += '</div>';
                        }

                        html += '</div></div>';

                        // Statistics Section
                        html += '<div style="margin-bottom: 30px;">';
                        html += '<h4 style="margin: 0 0 15px; font-size: 18px; font-weight: 600;">Sync Preview</h4>';
                        html += '<div style="padding: 20px; background: #f9fafb; border-radius: 8px;">';
                        html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; text-align: center;">';
                        html += '<div><div style="font-size: 32px; font-weight: 700; color: #3b82f6;">' + data.stats.total_found + '</div><div style="font-size: 14px; color: #6b7280; margin-top: 5px;">Total Found</div></div>';
                        html += '<div><div style="font-size: 32px; font-weight: 700; color: #10b981;">' + data.stats.would_create + '</div><div style="font-size: 14px; color: #6b7280; margin-top: 5px;">Would Create</div></div>';
                        html += '<div><div style="font-size: 32px; font-weight: 700; color: #f59e0b;">' + data.stats.would_skip + '</div><div style="font-size: 14px; color: #6b7280; margin-top: 5px;">Already Exist</div></div>';
                        if (data.stats.errors > 0) {
                            html += '<div><div style="font-size: 32px; font-weight: 700; color: #ef4444;">' + data.stats.errors + '</div><div style="font-size: 14px; color: #6b7280; margin-top: 5px;">Errors</div></div>';
                        }
                        html += '</div></div></div>';

                        // Sample AI-Generated Titles
                        if (data.samples.coupons && data.samples.coupons.length > 0) {
                            html += '<div style="margin-bottom: 30px;">';
                            html += '<h4 style="margin: 0 0 15px; font-size: 18px; font-weight: 600;">Sample AI-Generated Titles</h4>';
                            data.samples.coupons.forEach(function(coupon, index) {
                                html += '<div style="margin-bottom: 15px; padding: 15px; background: #f9fafb; border-left: 4px solid #3b82f6; border-radius: 4px;">';
                                html += '<div style="font-weight: 600; color: #1f2937; margin-bottom: 8px;">' + (index + 1) + '. ' + coupon.ai_generated_title + '</div>';
                                html += '<div style="font-size: 13px; color: #6b7280; margin-bottom: 5px;"><strong>Brand:</strong> ' + coupon.brand + ' | <strong>Type:</strong> ' + coupon.type + ' | <strong>Code:</strong> ' + coupon.code + '</div>';
                                html += '<div style="font-size: 12px; color: #9ca3af;">Source: ' + coupon.source + ' | Valid until: ' + coupon.validto + '</div>';
                                html += '</div>';
                            });
                            html += '</div>';
                        }

                        // Errors
                        if (data.errors && data.errors.length > 0) {
                            html += '<div style="margin-bottom: 20px;">';
                            html += '<h4 style="margin: 0 0 15px; font-size: 18px; font-weight: 600; color: #dc2626;">Errors</h4>';
                            data.errors.forEach(function(error) {
                                html += '<div style="padding: 10px; background: #fee2e2; color: #991b1b; border-radius: 4px; margin-bottom: 8px; font-size: 13px;">' + error + '</div>';
                            });
                            html += '</div>';
                        }

                        // Footer
                        html += '<div style="text-align: center; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 14px;">';
                        html += 'Test completed in ' + data.execution_time + ' seconds';
                        html += '</div>';

                        $('#test-sync-results').html(html).show();
                    } else {
                        $('#test-sync-results').html(
                            '<div class="ca-alert ca-alert-error">Test sync failed: ' + (response.data || 'Unknown error') + '</div>'
                        ).show();
                    }
                },
                error: function() {
                    $('#test-sync-loading').hide();
                    $('#test-sync-results').html(
                        '<div class="ca-alert ca-alert-error">Failed to run test sync. Please try again.</div>'
                    ).show();
                }
            });
        });

        // Purge Duplicates functionality
        let duplicateData = null;

        $('#purge-duplicates-btn').on('click', function() {
            $('#purge-duplicates-modal').fadeIn(200);
            $('#purge-duplicates-loading').show();
            $('#purge-duplicates-preview').hide();
            $('#confirm-purge-btn').hide();

            // Preview duplicates
            $.ajax({
                url: couponAutomation.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'purge_duplicates',
                    mode: 'preview',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    $('#purge-duplicates-loading').hide();

                    if (response.success) {
                        duplicateData = response.data;
                        const stats = response.data.stats;
                        const groups = response.data.duplicate_groups;

                        if (stats.duplicate_groups === 0) {
                            $('#purge-duplicates-preview').html(
                                '<div class="ca-alert ca-alert-success">' +
                                '<strong>No duplicates found!</strong> All coupons are unique.' +
                                '</div>'
                            ).show();
                        } else {
                            let html = '<div class="ca-alert ca-alert-info">';
                            html += '<strong>Found ' + stats.duplicate_groups + ' duplicate group(s)</strong><br>';
                            html += stats.posts_to_delete + ' coupon(s) will be deleted (keeping newest in each group)';
                            html += '</div>';

                            html += '<div style="max-height: 400px; overflow-y: auto; margin-top: 15px;">';

                            groups.forEach(function(group, index) {
                                html += '<div class="ca-duplicate-group" style="margin-bottom: 20px; padding: 15px; background: #f9fafb; border-radius: 8px;">';
                                html += '<div style="font-weight: 600; margin-bottom: 10px; color: #374151;">Group ' + (index + 1) + ' (Coupon ID: ' + group.coupon_id + ')</div>';

                                // Post to keep
                                html += '<div style="margin-bottom: 8px; padding: 10px; background: #d1fae5; border-left: 3px solid #10b981; border-radius: 4px;">';
                                html += '<span style="color: #059669; font-weight: 600;">✓ KEEP:</span> ';
                                html += '<strong>' + group.keep.title + '</strong> (ID: ' + group.keep.id + ', ' + group.keep.date + ')';
                                html += '</div>';

                                // Posts to delete
                                group.delete.forEach(function(post) {
                                    html += '<div style="margin-bottom: 8px; padding: 10px; background: #fee2e2; border-left: 3px solid #ef4444; border-radius: 4px;">';
                                    html += '<span style="color: #dc2626; font-weight: 600;">✗ DELETE:</span> ';
                                    html += post.title + ' (ID: ' + post.id + ', ' + post.date + ')';
                                    html += '</div>';
                                });

                                html += '</div>';
                            });

                            html += '</div>';

                            $('#purge-duplicates-preview').html(html).show();
                            $('#confirm-purge-btn').show();
                        }
                    } else {
                        $('#purge-duplicates-preview').html(
                            '<div class="ca-alert ca-alert-error">Error: ' + (response.data || 'Unknown error') + '</div>'
                        ).show();
                    }
                },
                error: function() {
                    $('#purge-duplicates-loading').hide();
                    $('#purge-duplicates-preview').html(
                        '<div class="ca-alert ca-alert-error">Failed to analyze duplicates. Please try again.</div>'
                    ).show();
                }
            });
        });

        $('#confirm-purge-btn').on('click', function() {
            if (!duplicateData || duplicateData.stats.duplicate_groups === 0) {
                return;
            }

            if (!confirm('Are you sure you want to permanently delete ' + duplicateData.stats.posts_to_delete + ' duplicate coupon(s)? This cannot be undone!')) {
                return;
            }

            $(this).prop('disabled', true).text('Purging...');

            $.ajax({
                url: couponAutomation.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'purge_duplicates',
                    mode: 'execute',
                    nonce: couponAutomation.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const deleted = response.data.stats.actually_deleted || response.data.stats.posts_to_delete;
                        CouponAutomation.showToast('success', 'Successfully deleted ' + deleted + ' duplicate coupon(s)!');
                        $('#purge-duplicates-modal').fadeOut(200);
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        CouponAutomation.showToast('error', 'Error: ' + (response.data || 'Unknown error'));
                        $('#confirm-purge-btn').prop('disabled', false).text('Confirm Purge');
                    }
                },
                error: function() {
                    CouponAutomation.showToast('error', 'Failed to purge duplicates. Please try again.');
                    $('#confirm-purge-btn').prop('disabled', false).text('Confirm Purge');
                }
            });
        });

        $('.ca-modal-close').on('click', function() {
            $(this).closest('.ca-modal').fadeOut(200);
        });

        $('.ca-modal-overlay').on('click', function() {
            $(this).closest('.ca-modal').fadeOut(200);
        });
    });
</script>
