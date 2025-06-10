<?php

/**
 * Plugin Name: Coupon Automation
 * Description: Automates the creation of coupons based on data from the addrevenue.io API.
 * Version: 1.0
 * Author: borkk
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/main-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/populate_brands.php';

add_action('admin_init', 'coupon_automation_settings');

add_action('admin_enqueue_scripts', 'enqueue_coupon_automation_assets');
function enqueue_coupon_automation_assets()
{
    wp_register_style('coupon-styles', plugin_dir_url(__FILE__) . 'assets/css/styles.css', false, '1.0.0');
    wp_enqueue_style('coupon-styles');

    wp_enqueue_script('coupon-automation-script', plugin_dir_url(__FILE__) . 'assets/js/coupon-automation.js', array('jquery'), '1.0.0', true);
    wp_localize_script('coupon-automation-script', 'couponAutomation', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fetch_coupons_nonce'),
        'stop_nonce' => wp_create_nonce('stop_automation_nonce'),
        'clear_nonce' => wp_create_nonce('clear_transients_nonce'),
        'purge_nonce' => wp_create_nonce('purge_expired_coupons_nonce')
    ));
}

function coupon_automation_settings()
{
    register_setting('coupon-automation-settings-group', 'addrevenue_api_token');
    register_setting('coupon-automation-settings-group', 'yourl_api_token');
    register_setting('coupon-automation-settings-group', 'openai_api_key');
    register_setting('coupon-automation-settings-group', 'coupon_title_prompt');
    register_setting('coupon-automation-settings-group', 'description_prompt');
    register_setting('coupon-automation-settings-group', 'brand_description_prompt');
    register_setting('coupon-automation-settings-group', 'why_we_love_prompt');
    register_setting('coupon-automation-settings-group', 'awin_api_token');
    register_setting('coupon-automation-settings-group', 'awin_publisher_id');
}
add_action('admin_init', 'coupon_automation_settings');

add_action('admin_menu', 'coupon_automation_menu');
function coupon_automation_menu()
{
    add_options_page(
        'Coupon Automation Settings',
        'Coupon Automation',
        'manage_options',
        'coupon-automation',
        'coupon_automation_options_page'
    );
}

function clear_coupon_automation_flags()
{
    delete_transient('fetch_process_running');
    delete_transient('addrevenue_processed_count');
    delete_option('coupon_automation_stop_requested');
    error_log('Coupon automation flags and transients cleared manually.');
}

function handle_clear_coupon_flags()
{
    error_log('handle_clear_coupon_flags called');

    if (!check_ajax_referer('clear_transients_nonce', 'nonce', false)) {
        error_log('Nonce check failed in handle_clear_coupon_flags');
        wp_send_json_error('Security check failed.');
        return;
    }

    error_log('Nonce check passed, proceeding to clear flags');

    clear_coupon_automation_flags();

    error_log('Flags cleared successfully');
    wp_send_json_success('Coupon automation flags and transients cleared successfully.');
}
add_action('wp_ajax_clear_coupon_flags', 'handle_clear_coupon_flags');

function coupon_automation_options_page()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['submit_api_keys'])) {
            update_option('addrevenue_api_token', sanitize_text_field($_POST['addrevenue_api_token']));
            update_option('yourl_api_token', sanitize_text_field($_POST['yourl_api_token']));
            update_option('openai_api_key', sanitize_text_field($_POST['openai_api_key']));
            update_option('coupon_title_prompt', sanitize_text_field($_POST['coupon_title_prompt']));
            update_option('description_prompt', sanitize_text_field($_POST['description_prompt']));
            update_option('brand_description_prompt', wp_kses($_POST['brand_description_prompt'], array(
                'h4' => array('style' => array()),
                'p' => array('style' => array()),
                'strong' => array(),
                'em' => array(),
                'ul' => array(),
                'li' => array(),
                'br' => array(),
            )));
            update_option('why_we_love_prompt', wp_kses($_POST['why_we_love_prompt'], array(
                'h4' => array('style' => array()),
                'p' => array('style' => array()),
                'strong' => array(),
                'img' => array(
                    'src' => array(),
                    'alt' => array(),
                    'width' => array(),
                    'height' => array(),
                    'class' => array(),
                ),
                'em' => array(),
                'a' => array(
                    'href' => array(),
                    'title' => array(),
                    'target' => array(),
                    'rel' => array(),
                ),
                'ul' => array(),
                'li' => array(),
                'br' => array(),
            )));
            update_option('fallback_terms', sanitize_textarea_field($_POST['fallback_terms']));
            update_option('awin_api_token', sanitize_text_field($_POST['awin_api_token']));
            update_option('awin_publisher_id', sanitize_text_field($_POST['awin_publisher_id']));
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        if (isset($_POST['action']) && $_POST['action'] === 'fetch_coupons_now') {
            if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'fetch_coupons_nonce')) {
                // fetch_and_process_new_coupons();
                schedule_fetch_and_store_data();
                echo '<div class="updated"><p>Coupons fetching and processing scheduled.</p></div>';
            } else {
                echo '<div class="error"><p>Nonce verification failed.</p></div>';
            }
        }
    }

?>
    <div class="coupon-forms_wrap">
        <h1>Coupon Automation Settings</h1>
        <form method="post">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">AddRevenue API Token</th>
                    <td><input type="password" name="addrevenue_api_token" value="<?php echo esc_attr(get_option('addrevenue_api_token')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Yourl API token</th>
                    <td><input type="password" name="yourl_api_token" value="<?php echo esc_attr(get_option('yourl_api_token')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">AWIN API Token</th>
                    <td><input type="password" name="awin_api_token" value="<?php echo esc_attr(get_option('awin_api_token')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">AWIN Publisher ID</th>
                    <td><input type="password" name="awin_publisher_id" value="<?php echo esc_attr(get_option('awin_publisher_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td><input type="password" name="openai_api_key" value="<?php echo esc_attr(get_option('openai_api_key')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Coupon Title Prompt</th>
                    <td><textarea name="coupon_title_prompt" rows="4" cols="50"><?php echo esc_textarea(get_option('coupon_title_prompt')); ?></textarea></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Coupon Terms Prompt</th>
                    <td><textarea name="description_prompt" rows="4" cols="50"><?php echo esc_textarea(get_option('description_prompt')); ?></textarea></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Terms Fallback</th>
                    <td><textarea name="fallback_terms" rows="4" cols="50"><?php echo esc_textarea(get_option('fallback_terms')); ?></textarea></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Brand Description Prompt</th>
                    <td>
                        <?php
                        $brand_prompt_content = get_option('brand_description_prompt');
                        wp_editor(
                            $brand_prompt_content,
                            'brand_description_prompt',
                            array(
                                'textarea_name' => 'brand_description_prompt',
                                'textarea_rows' => 10,
                                'media_buttons' => true,
                                'tinymce'       => true,
                                'quicktags'     => true,
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row">Why We Love Prompt</th>
                    <td>
                        <?php
                        $why_we_love_content = get_option('why_we_love_prompt');
                        wp_editor(
                            $why_we_love_content,
                            'why_we_love_prompt',
                            array(
                                'textarea_name' => 'why_we_love_prompt',
                                'textarea_rows' => 10,
                                'media_buttons' => true,
                                'tinymce'       => true,
                                'quicktags'     => true,
                            )
                        );
                        ?>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'submit_api_keys'); ?>
        </form>
        <div class="fetch_buttons">
            <h2>Automation Control</h2>
            <div class="coupon-messages"></div> <!-- Container for AJAX messages -->
            <button type="button" id="fetch-coupons-button" class="button button-primary">Start Automation</button>
            <button type="button" id="stop-automation-button" class="button button-primary">Stop Automation</button>
            <button type="button" id="clear-flags-button" class="button button-secondary">Clear Transients</button>
            <button type="button" id="purge-expired-coupons" class="button button-warning">Purge Expired Coupons</button>
        </div>
    </div>
<?php
}


function add_new_brand_notification($brand_name, $brand_id)
{
    $notifications = get_option('coupon_automation_notifications', array());
    $notifications[] = array(
        'type' => 'brand',
        'name' => $brand_name,
        'id' => $brand_id,
        'time' => current_time('mysql')
    );
    update_option('coupon_automation_notifications', array_slice($notifications, -50));
}

function add_new_coupon_notification($coupon_title, $brand_name, $coupon_id)
{
    $notifications = get_option('coupon_automation_notifications', array());
    $notifications[] = array(
        'type' => 'coupon',
        'title' => $coupon_title,
        'brand' => $brand_name,
        'id' => $coupon_id,
        'time' => current_time('mysql')
    );
    update_option('coupon_automation_notifications', array_slice($notifications, -50));
}

function display_coupon_automation_notifications()
{
    $notifications = get_option('coupon_automation_notifications', array());

    if (!empty($notifications)) {
        echo '<div id="coupon-automation-notifications" class="notice notice-success is-dismissible">';
        echo '<h3>Coupon Automation Notifications <span class="notification-count">(' . count($notifications) . ')</span></h3>';
        echo '<div class="notification-content">';
        foreach ($notifications as $notification) {
            $message = '';
            if ($notification['type'] === 'brand') {
                if (isset($notification['id'])) {
                    $term = get_term($notification['id'], 'brands');
                    if (!is_wp_error($term) && $term) {
                        $edit_url = get_edit_term_link($term->term_id, 'brands', 'coupons');
                        $message = sprintf('New brand added: <a href="%s">%s</a>', esc_url($edit_url), esc_html($notification['name']));
                    } else {
                        $message = sprintf('New brand added: %s (Brand may have been renamed or deleted)', esc_html($notification['name']));
                    }
                } else {
                    $message = sprintf('New brand added: %s', esc_html($notification['name']));
                }
            } elseif ($notification['type'] === 'coupon') {
                if (isset($notification['id'])) {
                    $post = get_post($notification['id']);
                    if ($post && $post->post_type === 'coupons') {
                        $edit_url = get_edit_post_link($notification['id']);
                        $message = sprintf('New coupon added: <a href="%s">%s</a> for %s', esc_url($edit_url), esc_html($notification['title']), esc_html($notification['brand']));
                    } else {
                        // Try to find the coupon by title
                        $existing_coupon = get_page_by_title($notification['title'], OBJECT, 'coupons');
                        if ($existing_coupon) {
                            $edit_url = get_edit_post_link($existing_coupon->ID);
                            $message = sprintf('Coupon found with different ID: <a href="%s">%s</a> for %s', esc_url($edit_url), esc_html($notification['title']), esc_html($notification['brand']));
                        } else {
                            $message = sprintf('Coupon not found by ID or title: %s for %s', esc_html($notification['title']), esc_html($notification['brand']));
                        }
                    }
                } else {
                    $message = sprintf('New coupon added: %s for %s (ID not available)', esc_html($notification['title']), esc_html($notification['brand']));
                }
            }

            if (!empty($message)) {
                echo '<p>' . $message . ' at ' . esc_html($notification['time']) . '</p>';
            }
        }
        echo '</div>';
        echo '<button id="clear-notifications" class="button button-secondary">Clear All Notifications</button>';
        echo '<button id="close-notifications" class="button button-secondary">Close</button>';
        echo '</div>';

        echo '<style>
            #coupon-automation-notifications {
                max-height: 300px;
                overflow-y: auto;
                padding: 10px;
                position: relative;
            }
            #coupon-automation-notifications h3 {
                margin-top: 0;
            }
            .notification-content {
                max-height: 200px;
                overflow-y: auto;
                margin-bottom: 10px;
            }
            #close-notifications {
                margin-left: 10px;
            }
        </style>';

        echo '<script>
            jQuery(document).ready(function($) {
                $("#clear-notifications").on("click", function() {
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "clear_coupon_notifications",
                            nonce: "' . wp_create_nonce('clear_coupon_notifications_nonce') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                $("#coupon-automation-notifications").fadeOut();
                            }
                        }
                    });
                });
                
                $("#close-notifications").on("click", function() {
                    $("#coupon-automation-notifications").fadeOut();
                });
                
                $(".notice-dismiss").on("click", function() {
                    $("#coupon-automation-notifications").fadeOut();
                });
            });
        </script>';
    }
}

add_action('admin_notices', 'display_coupon_automation_notifications');

function clear_coupon_notifications()
{
    check_ajax_referer('clear_coupon_notifications_nonce', 'nonce');
    update_option('coupon_automation_notifications', array());
    wp_send_json_success();
}
add_action('wp_ajax_clear_coupon_notifications', 'clear_coupon_notifications');

function purge_expired_coupons()
{
    $today = date('Ymd'); // Current date in ACF date format

    $args = array(
        'post_type' => 'coupons',
        'posts_per_page' => -1,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => 'valid_untill',
                'compare' => 'EXISTS',
            ),
            array(
                'key' => 'valid_untill',
                'value' => '',
                'compare' => '!=',
            ),
            array(
                'key' => 'valid_untill',
                'value' => $today,
                'compare' => '<',
                'type' => 'DATE'
            )
        )
    );

    $expired_coupons = new WP_Query($args);

    $purged_count = 0;

    if ($expired_coupons->have_posts()) {
        while ($expired_coupons->have_posts()) {
            $expired_coupons->the_post();
            $coupon_id = get_the_ID();

            wp_trash_post($coupon_id);

            $brand_terms = wp_get_post_terms($coupon_id, 'brands');
            if (!empty($brand_terms) && !is_wp_error($brand_terms)) {
                $brand_slug = $brand_terms[0]->slug;

                add_post_meta($coupon_id, '_redirect_to_brand', home_url('/brands/' . $brand_slug), true);
            }

            $purged_count++;
        }
    }

    wp_reset_postdata();

    return $purged_count;
}

function handle_purge_expired_coupons()
{
    check_ajax_referer('purge_expired_coupons_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to perform this action.');
    }

    $purged_count = purge_expired_coupons();

    wp_send_json_success("Purged $purged_count expired coupons.");
}
add_action('wp_ajax_purge_expired_coupons', 'handle_purge_expired_coupons');

function redirect_trashed_coupons()
{
    if (is_404()) {
        global $wpdb;
        $current_url = home_url($_SERVER['REQUEST_URI']);

        $trashed_post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type FROM $wpdb->posts WHERE post_name = %s AND post_type = 'coupons' AND post_status = 'trash'",
            basename($current_url)
        ));

        if ($trashed_post && $trashed_post->post_type == 'coupons') {
            $redirect_url = get_post_meta($trashed_post->ID, '_redirect_to_brand', true);
            if ($redirect_url) {
                wp_redirect($redirect_url, 301);
                exit;
            }
        }
    }
}
add_action('template_redirect', 'redirect_trashed_coupons');

