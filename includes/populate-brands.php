<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'main-functions.php';

add_action('init', function() {
    error_log('Registering populate_brands_batch AJAX handler');
});

function add_brand_population_menu() {
    $hook = add_submenu_page(
        'options-general.php',
        'Populate Brand Content',
        'Populate Brands',
        'manage_options',
        'populate-brand-content',
        'render_brand_population_page'
    );
    add_action("admin_print_scripts-{$hook}", 'enqueue_population_scripts');
}
add_action('admin_menu', 'add_brand_population_menu');

function enqueue_population_scripts($hook) {
    error_log('Enqueuing population scripts on hook: ' . $hook);
    
    $plugin_url = plugin_dir_url(dirname(__FILE__));
    
    $js_url = $plugin_url . 'assets/js/brand-population.js';
    $css_url = $plugin_url . 'assets/css/brand-population.css';
    
    error_log('JS URL: ' . $js_url);
    error_log('CSS URL: ' . $css_url);
    
    wp_enqueue_script(
        'brand-population', 
        $js_url,
        array('jquery'), 
        '1.0.1', 
        true
    );

    wp_localize_script('brand-population', 'brandPopulation', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('populate_brands_nonce'),
        'strings' => array(
            'processing' => 'Processing...',
            'completed' => 'Process completed',
            'error' => 'An error occurred',
            'stopped' => 'Process stopped by user'
        )
    ));

    wp_enqueue_style(
        'brand-population', 
        $css_url,
        array(),
        '1.0.0'
    );
}

function render_brand_population_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="notice notice-info">
            <p>This tool will populate missing brand descriptions, hashtags, and "Why We Love" sections for all brands.</p>
        </div>

        <div class="brand-population-controls">
            <button id="start-population" class="button button-primary">Start Population</button>
            <button id="stop-population" class="button button-secondary" style="display:none;">Stop Population</button>
        </div>

        <div class="brand-population-progress" style="display:none;">
            <div class="progress-bar">
                <div class="progress"></div>
            </div>
            <p class="progress-text">
                Processed: <span class="processed-count">0</span> / <span class="total-count">0</span>
            </p>
        </div>

        <div class="population-log">
            <h3>Progress Log</h3>
            <div class="log-entries"></div>
        </div>
    </div>
    <?php
}


function handle_brands_population_batch() {
    error_log('Received populate_brands_batch AJAX request');
    error_log('POST data: ' . print_r($_POST, true));
    
    check_ajax_referer('populate_brands_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        error_log('Permission check failed');
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $batch_size = 5; // Process 5 brands per batch
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $log = array();

    // Get all brands
    $brands = get_terms(array(
        'taxonomy' => 'brands',
        'hide_empty' => false,
        'number' => $batch_size,
        'offset' => $offset
    ));

    $total_brands = wp_count_terms('brands', array('hide_empty' => false));
    $processed = 0;

    foreach ($brands as $brand) {
        $term_id = $brand->term_id;
        $updates = array();
        error_log("Processing brand: {$brand->name} (ID: {$term_id})");

        $current_description = $brand->description;
        if (empty(trim($current_description))) {
            error_log("Generating new description for {$brand->name}");
            $new_description = generate_brand_description($brand->name, $term_id, array());
            if ($new_description) {
                remove_filter('pre_term_description', 'wp_filter_kses');
                wp_update_term($term_id, 'brands', array('description' => $new_description));
                add_filter('pre_term_description', 'wp_filter_kses');
                $updates[] = 'description';
                error_log("Added description for {$brand->name}");
            }
        } elseif (!preg_match('/#[a-zA-Z0-9-_]+/', $current_description)) {
            error_log("Adding hashtags to existing description for {$brand->name}");
            $processed_description = process_description_hashtags($current_description, $brand->name);
            if ($processed_description !== $current_description) {
                remove_filter('pre_term_description', 'wp_filter_kses');
                wp_update_term($term_id, 'brands', array('description' => $processed_description));
                add_filter('pre_term_description', 'wp_filter_kses');
                $updates[] = 'hashtags';
                error_log("Added hashtags for {$brand->name}");
            }
        }

        $term_id_prefixed = 'brands_' . $term_id;
        $why_we_love = get_field('why_we_love', $term_id_prefixed);
        if (empty($why_we_love)) {
            error_log("Generating Why We Love for {$brand->name}");
            $new_why_we_love = generate_why_we_love($brand->name, array());
            if ($new_why_we_love) {
                update_field('why_we_love', $new_why_we_love, $term_id_prefixed);
                $updates[] = 'why_we_love';
                error_log("Added Why We Love for {$brand->name}");
            }
        }

        $processed++;

        // Generate log message
        if (!empty($updates)) {
            $message = sprintf(
                'Updated brand "%s" (ID: %d) - Added: %s',
                $brand->name,
                $term_id,
                implode(', ', $updates)
            );
            error_log($message);
            $log[] = $message;
        } else {
            $message = sprintf('Brand "%s" (ID: %d) - No updates needed', $brand->name, $term_id);
            error_log($message);
            $log[] = $message;
        }
    }

    wp_send_json_success(array(
        'processed' => $processed,
        'total' => $total_brands,
        'log' => $log
    ));
}
add_action('wp_ajax_populate_brands_batch', 'handle_brands_population_batch');

