<?php

namespace CouponAutomation\Admin;

/**
 * Admin menu management
 */
class AdminMenu {
    
    /**
     * Register admin menus
     */
    public function registerMenus() {
        add_options_page(
            'Coupon Automation',
            'Coupon Automation',
            'manage_options',
            'coupon-automation',
            [$this, 'renderMainPage']
        );
        
        add_submenu_page(
            'options-general.php',
            'Populate Brands',
            'Populate Brands',
            'manage_options',
            'populate-brands',
            [$this, 'renderBrandsPage']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueueAssets($hook) {
        $pluginScreens = ['settings_page_coupon-automation', 'settings_page_populate-brands'];

        // Load full admin assets only on plugin pages
        if (in_array($hook, $pluginScreens, true)) {
            wp_enqueue_style(
                'coupon-automation-admin',
                COUPON_AUTOMATION_PLUGIN_URL . 'assets/css/admin.css',
                [],
                COUPON_AUTOMATION_VERSION
            );

            wp_enqueue_script(
                'coupon-automation-admin',
                COUPON_AUTOMATION_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                COUPON_AUTOMATION_VERSION,
                true
            );

            wp_localize_script('coupon-automation-admin', 'couponAutomation', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('coupon_automation_nonce'),
                'enableStatusPolling' => false,
                'strings' => [
                    'processing' => __('Processing...', 'coupon-automation'),
                    'success' => __('Success!', 'coupon-automation'),
                    'error' => __('An error occurred', 'coupon-automation'),
                ]
            ]);
        }

        // Lightweight styling for notifications everywhere else
        wp_enqueue_style(
            'coupon-automation-notifications',
            COUPON_AUTOMATION_PLUGIN_URL . 'assets/css/notifications.css',
            [],
            COUPON_AUTOMATION_VERSION
        );
    }
    
    /**
     * Render main settings page
     */
    public function renderMainPage() {
        $settings = new Settings();
        include COUPON_AUTOMATION_PLUGIN_DIR . 'templates/admin-main.php';
    }
    
    /**
     * Render brands population page
     */
    public function renderBrandsPage() {
        include COUPON_AUTOMATION_PLUGIN_DIR . 'templates/admin-brands.php';
    }
    
    /**
     * Display admin notifications
     */
    public function displayNotifications() {
        $notifications = get_option('coupon_automation_notifications', []);

        if (empty($notifications)) {
            return;
        }

        include COUPON_AUTOMATION_PLUGIN_DIR . 'templates/admin-notifications.php';
    }
}
