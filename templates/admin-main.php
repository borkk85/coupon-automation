<!-- templates/admin-main.php -->
<div class="wrap" id="coupon-automation-admin">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-lg shadow-lg p-6 mb-6">
            <h1 class="text-3xl font-bold mb-2">Coupon Automation Pro</h1>
            <p class="text-blue-100">Manage API integrations and automate coupon generation</p>
        </div>

        <!-- Status Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Total Brands</p>
                        <p class="text-2xl font-bold text-gray-800">
                            <?php echo wp_count_terms('brands', ['hide_empty' => false]); ?>
                        </p>
                    </div>
                    <div class="text-blue-500">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Total Coupons</p>
                        <p class="text-2xl font-bold text-gray-800">
                            <?php echo wp_count_posts('coupons')->publish; ?>
                        </p>
                    </div>
                    <div class="text-green-500">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Last Sync</p>
                        <p class="text-sm font-semibold text-gray-800">
                            <?php 
                            $lastSync = get_option('coupon_automation_last_sync', 'Never');
                            echo is_numeric($lastSync) ? human_time_diff($lastSync) . ' ago' : $lastSync;
                            ?>
                        </p>
                    </div>
                    <div class="text-yellow-500">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">API Status</p>
                        <div class="flex space-x-2 mt-2">
                            <span class="api-status-indicator" data-api="addrevenue" title="AddRevenue">
                                <span class="w-3 h-3 bg-gray-300 rounded-full inline-block"></span>
                            </span>
                            <span class="api-status-indicator" data-api="awin" title="AWIN">
                                <span class="w-3 h-3 bg-gray-300 rounded-full inline-block"></span>
                            </span>
                            <span class="api-status-indicator" data-api="openai" title="OpenAI">
                                <span class="w-3 h-3 bg-gray-300 rounded-full inline-block"></span>
                            </span>
                            <span class="api-status-indicator" data-api="yourls" title="YOURLS">
                                <span class="w-3 h-3 bg-gray-300 rounded-full inline-block"></span>
                            </span>
                        </div>
                    </div>
                    <div class="text-purple-500">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6 border-b">
                <h2 class="text-xl font-semibold text-gray-800">Quick Actions</h2>
            </div>
            <div class="p-6">
                <div class="flex flex-wrap gap-3">
                    <button id="fetch-coupons-btn" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-200 flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                        </svg>
                        Start Sync
                    </button>
                    
                    <button id="stop-automation-btn" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition duration-200 flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Stop Sync
                    </button>
                    
                    <button id="clear-cache-btn" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-200 flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        Clear Cache
                    </button>
                    
                    <button id="purge-expired-btn" class="bg-yellow-600 hover:bg-yellow-700 text-white font-medium py-2 px-4 rounded-lg transition duration-200 flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Purge Expired
                    </button>
                </div>
            </div>
        </div>

        <!-- Settings Form -->
        <form method="post" action="options.php">
            <?php settings_fields($settings->getOptionGroup()); ?>
            
            <!-- Tabs -->
            <div class="bg-white rounded-lg shadow">
                <div class="border-b">
                    <nav class="flex -mb-px">
                        <button type="button" class="settings-tab active px-6 py-3 border-b-2 border-blue-500 font-medium text-blue-600" data-tab="api-credentials">
                            API Credentials
                        </button>
                        <button type="button" class="settings-tab px-6 py-3 border-b-2 border-transparent font-medium text-gray-600 hover:text-gray-800" data-tab="ai-prompts">
                            AI Prompts
                        </button>
                        <button type="button" class="settings-tab px-6 py-3 border-b-2 border-transparent font-medium text-gray-600 hover:text-gray-800" data-tab="advanced">
                            Advanced
                        </button>
                    </nav>
                </div>
                
                <!-- Tab Content -->
                <div class="p-6">
                    <!-- API Credentials Tab -->
                    <div class="tab-content" id="api-credentials-tab">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php foreach($settings->getSections()['api_credentials']['fields'] as $field_name => $field): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?php echo $field['label']; ?>
                                </label>
                                <div class="relative">
                                    <input 
                                        type="<?php echo $field['type']; ?>" 
                                        name="<?php echo $field_name; ?>" 
                                        value="<?php echo esc_attr($settings->getFieldValue($field_name)); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    />
                                    <button type="button" class="test-api-btn absolute right-2 top-2 text-gray-400 hover:text-gray-600" data-api="<?php echo str_replace(['_api_token', '_api_key', '_username', '_password', '_publisher_id'], '', $field_name); ?>">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </button>
                                </div>
                                <p class="mt-1 text-sm text-gray-500"><?php echo $field['description']; ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Advanced Tab -->
                    <div class="tab-content hidden" id="advanced-tab">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">Enable Logging</h3>
                                    <p class="text-sm text-gray-500">Keep detailed logs of all operations</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="coupon_automation_enable_logging" value="1" <?php checked(get_option('coupon_automation_enable_logging', true)); ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Batch Size
                                </label>
                                <input 
                                    type="number" 
                                    name="coupon_automation_batch_size" 
                                    value="<?php echo get_option('coupon_automation_batch_size', 10); ?>"
                                    min="1"
                                    max="50"
                                    class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                                <p class="mt-1 text-sm text-gray-500">Number of items to process per batch</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    API Timeout (seconds)
                                </label>
                                <input 
                                    type="number" 
                                    name="coupon_automation_api_timeout" 
                                    value="<?php echo get_option('coupon_automation_api_timeout', 30); ?>"
                                    min="10"
                                    max="120"
                                    class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                                <p class="mt-1 text-sm text-gray-500">Maximum time to wait for API responses</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="border-t px-6 py-4 bg-gray-50 rounded-b-lg">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-lg transition duration-200">
                        Save Settings
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>