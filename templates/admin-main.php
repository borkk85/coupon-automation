<div class="wrap coupon-automation-admin" id="coupon-automation-admin">
    <div class="ca-container">
        <!-- Header -->
        <div class="ca-hero">
            <h1>Coupon Automation</h1>
            <p>Manage API integrations and automate coupon generation</p>
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
                </div>
            </div>
        </div>
        <div class="ca-section">
            <div class="ca-section-header">
                <h2 class="ca-section-title">📊 Sync Status</h2>
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
                    $status_text = '🔄 Processing - Sync in progress';
                    $status_class = 'ca-status-running';
                } elseif ($sync_status === 'never_run') {
                    $status_text = '⚪ Never Run - Click "Start Sync" to begin';
                    $status_class = 'ca-status-idle';
                } elseif ($sync_status === 'success') {
                    $status_text = '✅ Idle - All systems operational';
                    $status_class = 'ca-status-success';
                } elseif ($sync_status === 'failed') {
                    $status_text = '⚠️ Idle - Check logs for recent errors';
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
                    <div class="ca-status-item">
                        <a href="#logs" class="ca-btn ca-btn-secondary" onclick="jQuery('.ca-tab[data-tab=logs]').click(); return false;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            View Logs
                        </a>
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
                                <input
                                    type="<?php echo $field['type']; ?>"
                                    name="<?php echo $field_name; ?>"
                                    value="<?php echo esc_attr($settings->getFieldValue($field_name)); ?>"
                                    class="ca-form-input" />
                                <button type="button" class="ca-test-btn test-api-btn" data-api="<?php echo str_replace(['_api_token', '_api_key', '_username', '_password', '_publisher_id'], '', $field_name); ?>" aria-label="Test API"></button>
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
                        <input type="number" name="coupon_automation_batch_size" value="<?php echo get_option('coupon_automation_batch_size', 10); ?>" min="1" max="50" class="ca-form-input" style="width: 120px;" />
                        <p class="ca-form-help">Number of items to process per batch</p>
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
    });
</script>