<?php

namespace CouponAutomation\Core;

use CouponAutomation\Admin\AdminMenu;
use CouponAutomation\Admin\Settings;
use CouponAutomation\Admin\AjaxHandler;
use CouponAutomation\Services\BrandService;
use CouponAutomation\Services\CouponService;

/**
 * Main plugin class - Singleton pattern
 */
class Plugin
{

    private static $instance = null;
    private $loader;
    private $version;

    /**
     * Get single instance of the plugin
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        $this->version = COUPON_AUTOMATION_VERSION;
        $this->loader = new Loader();
        $this->defineHooks();
    }

    /**
     * Define all hooks for the plugin
     */
    private function defineHooks()
    {
        // Admin hooks
        $admin = new AdminMenu();
        $settings = new Settings();
        $ajax = new AjaxHandler();

        // Admin menu and settings
        $this->loader->addAction('admin_menu', $admin, 'registerMenus');
        $this->loader->addAction('admin_init', $settings, 'registerSettings');
        $this->loader->addAction('admin_enqueue_scripts', $admin, 'enqueueAssets');

        // AJAX handlers
        $this->loader->addAction('wp_ajax_fetch_coupons', $ajax, 'handleFetchCoupons');
        $this->loader->addAction('wp_ajax_stop_automation', $ajax, 'handleStopAutomation');
        $this->loader->addAction('wp_ajax_clear_coupon_flags', $ajax, 'handleClearFlags');
        $this->loader->addAction('wp_ajax_clear_notifications', $ajax, 'handleClearNotifications');
        $this->loader->addAction('wp_ajax_mark_notifications_read', $ajax, 'handleMarkNotificationsRead');
        $this->loader->addAction('wp_ajax_purge_expired_coupons', $ajax, 'handlePurgeExpired');
        $this->loader->addAction('wp_ajax_populate_brands_batch', $ajax, 'handleBrandPopulation');
        $this->loader->addAction('wp_ajax_test_api_connection', $ajax, 'handleTestConnection');

        // Scheduled events
        $this->loader->addAction('coupon_automation_daily_fetch', $ajax, 'scheduledFetch');

        // Admin notices
        $this->loader->addAction('admin_notices', $admin, 'displayNotifications');
    }

    /**
     * Run the plugin
     */
    public function run()
    {
        $this->loader->run();
    }

    /**
     * Get plugin version
     */
    public function getVersion()
    {
        return $this->version;
    }
}
