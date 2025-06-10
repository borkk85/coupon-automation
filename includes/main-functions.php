<?php

if (!defined('ABSPATH')) {
    exit; 
}

if ( ! function_exists( 'media_sideload_image' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
}


function process_coupon($coupon_data, $advertiser_name, $api_source = 'addrevenue') {
    error_log('process_coupon function called with data: ' . print_r($coupon_data, true));

    if ($api_source === 'addrevenue') {
        $coupon_id = isset($coupon_data['id']) ? sanitize_text_field($coupon_data['id']) : '';
        $coupon_description = isset($coupon_data['description']) ? sanitize_text_field($coupon_data['description']) : '';
        $coupon_code = isset($coupon_data['discountCode']) ? sanitize_text_field($coupon_data['discountCode']) : '';
        $coupon_url = isset($coupon_data['trackingLink']) ? esc_url($coupon_data['trackingLink']) : '';
        $coupon_valid_to = isset($coupon_data['validTo']) ? sanitize_text_field($coupon_data['validTo']) : '';
        $coupon_terms = isset($coupon_data['terms']) ? wp_kses_post($coupon_data['terms']) : '';
        $coupon_type = empty($coupon_code) ? 'Sale' : 'Code';
    } elseif ($api_source === 'awin') {
        $coupon_id = isset($coupon_data['promotionId']) ? sanitize_text_field($coupon_data['promotionId']) : '';
        $coupon_description = isset($coupon_data['description']) ? sanitize_text_field($coupon_data['description']) : '';
        $coupon_code = isset($coupon_data['voucher']['code']) ? sanitize_text_field($coupon_data['voucher']['code']) : '';
        $coupon_url = isset($coupon_data['urlTracking']) ? esc_url($coupon_data['urlTracking']) : '';
        $coupon_valid_to = isset($coupon_data['endDate']) ? sanitize_text_field($coupon_data['endDate']) : '';
        $coupon_terms = isset($coupon_data['terms']) ? wp_kses_post($coupon_data['terms']) : '';
        $coupon_type = ($coupon_data['type'] === 'voucher' && !empty($coupon_code)) ? 'Code' : 'Sale';
    }
    error_log('Coupon ID: ' . $coupon_id);
    
    
    
    $post_status = 'publish';
    $post_date = current_time('mysql');

    if (!empty($coupon_valid_from)) {
        $valid_from_timestamp = strtotime($coupon_valid_from);
        $current_timestamp = current_time('timestamp');
        
        if ($valid_from_timestamp > $current_timestamp) {
            $post_status = 'future';
            $post_date = date('Y-m-d H:i:s', $valid_from_timestamp);
        }
    }
    
    // $coupon_type = empty($coupon_code) ? 'Sale' : 'Code';

    error_log('Checking for existing coupon with ID: ' . $coupon_id);
    $existing_coupon = get_posts(array(
        'post_type' => 'coupons',
        'meta_key' => 'coupon_id',
        'meta_value' => $coupon_id,
        'fields' => 'ids',
    ));
    
    if ($api_source === 'addrevenue' && !isset($coupon_data['markets']['SE'])) {
        error_log('Skipping AddRevenue coupon for non-SE market: ' . $advertiser_name);
        return;
    }
    
    
    $brand_term = get_term_by('name', $advertiser_name, 'brands');
    if (!$brand_term) {
        error_log("Brand '$advertiser_name' not found. This shouldn't happen as brands are created in process_advertiser_chunk.");
        return;
    }

    
    error_log('Checking for existing coupon with ID: ' . $coupon_id);
    if ($existing_coupon) {
        error_log('Coupon already exists with ID: ' . $coupon_id);
        return;
    }


    $coupon_title = generate_coupon_title($coupon_description);

    if ($coupon_title === false) {
        error_log('Title generation scheduled for retry in 1 hour for coupon ID: ' . $coupon_id);
        return; // Exit the function and wait for the next retry
    }
    error_log('Generated coupon title: ' . $coupon_title);
    $custom_slug = sanitize_title($coupon_title . ' at ' . $advertiser_name);
    $post_id = wp_insert_post(array(
        'post_title' => $coupon_title,
        'post_name' => $custom_slug,
        'post_status' => $post_status,
        'post_date' => $post_date,
        'post_type' => 'coupons',
    ));

    if (!is_wp_error($post_id)) {
        error_log('Coupon post created with ID: ' . $post_id);
        update_post_meta($post_id, 'coupon_id', $coupon_id);
        update_post_meta($post_id, 'code', $coupon_code);
        update_post_meta($post_id, 'redirect_url', $coupon_url);
        update_post_meta($post_id, 'valid_untill', $coupon_valid_to);
        update_post_meta($post_id, 'terms', $coupon_terms);
        update_post_meta($post_id, 'advertiser_name', $advertiser_name); 
        update_post_meta($post_id, 'coupon_type', $coupon_type);
        

          $terms_list = '';

         if (!empty($coupon_terms)) {
            $translated_terms = translate_description($coupon_terms);
            
            if ($translated_terms !== false) {
                
                $terms_array = explode(' - ', $translated_terms);
                error_log('Terms Array: ' . print_r($terms_array, true));
                
                $terms_array = array_map(function($term) {
                    $term = ltrim(ucfirst(trim($term)), '. ');
                    if (!preg_match('/[.!?]$/', $term)) {
                        $term .= '.';
                    }
                    return '<li>' . htmlspecialchars($term) . '</li>';
                }, $terms_array);
                
                $terms_list = '<ul>' . implode("\n", $terms_array) . '</ul>'; 
                update_field('show_details_button', $terms_list, $post_id);
            }
        }
        
        if (empty($coupon_terms) || $translated_terms === false) {
            $fallback_terms = get_option('fallback_terms');
            if (!empty($fallback_terms)) {
                $terms_array = explode('\n', $fallback_terms);
                $terms_array = array_map(function($term) {
                    $term = ucfirst(trim($term));
                    if (!preg_match('/[.!?]$/', $term)) {
                        $term .= '.';
                    }
                    return '<li>' . esc_html($term) . '</li>';
                }, $terms_array);
                $terms_list = '<ul>' . implode("\n", $terms_array) . '</ul>';
                update_field('show_details_button', $terms_list, $post_id);
            }
        }

        // if (!empty($terms_list)) {
        //     update_field('show_details_button', $terms_list, $post_id);
        // }
        
        $brand_term = get_term_by('name', $advertiser_name, 'brands');
        if ($brand_term && !is_wp_error($brand_term)) {
            wp_set_post_terms($post_id, array($brand_term->term_id), 'brands', false);
            error_log('Assigned brand term to coupon: ' . $brand_term->name);
        } else {
            error_log('Failed to re-fetch or assign brand term.');
        }
        add_new_coupon_notification($coupon_title, $advertiser_name, $coupon_id);
        $coupon_categories = get_field('coupon_categories', 'brands_' . $brand_term->term_id);
        wp_set_post_terms($post_id, $coupon_categories, 'coupon_categories');
    } else {
        error_log('Failed to create coupon post: ' . $post_id->get_error_message());
    }
}

function update_brand_fields($brand_term_id, $brand_data, $market_data, $api_source = 'addrevenue') {
    $term_id = 'brands_' . $brand_term_id;

    // Popular Brand
    $update_popular = false;
    $brand_popular = null;
    
    if ($api_source === 'addrevenue') {
        if (isset($brand_data['featured'])) {
            $brand_popular = $brand_data['featured'] === true ? 1 : 0;
            $update_popular = true;
        }
    } 
    
    if ($update_popular) {
        update_field('popular_brand', $brand_popular, $term_id);
        error_log("Updated brand {$term_id} popular status: " . ($brand_popular ? 'Popular' : 'Not Popular'));
    } else {
        error_log("No change to brand {$term_id} popular status (keeping default)");
    }

   // Featured Image
    $current_image_id = get_field('featured_image', $term_id);
    if (empty($current_image_id)) {
        if ($api_source === 'addrevenue') {
            $new_image_url = isset($brand_data['logoImageFilename']) ? esc_url($brand_data['logoImageFilename']) : '';
        } elseif ($api_source === 'awin') {
            $new_image_url = isset($brand_data['logoUrl']) ? esc_url($brand_data['logoUrl']) : '';
        }

        if (!empty($new_image_url)) {
            $image_id = media_sideload_image($new_image_url, 0, null, 'id');
            if (!is_wp_error($image_id)) {
                update_field('featured_image', $image_id, $term_id);
                update_post_meta($image_id, '_brand_logo_image', '1');
                error_log("Added new featured image for brand ID $brand_term_id. New image ID: $image_id");
            } else {
                error_log("Failed to add new featured image for brand ID $brand_term_id: " . $image_id->get_error_message());
            }
        } else {
            error_log("No image URL provided for brand ID $brand_term_id");
        }
    } else {
        error_log("Featured image already exists for brand ID $brand_term_id. Skipping image update.");
    }

    // Site Link
    if (empty(get_field('site_link', $term_id))) {
        if ($api_source === 'addrevenue') {
            $brand_url = isset($market_data['url']) ? esc_url($market_data['url']) : '';
        } elseif ($api_source === 'awin') {
            $brand_url = isset($brand_data['displayUrl']) ? esc_url($brand_data['displayUrl']) : '';
        }
        update_field('site_link', $brand_url, $term_id);
        error_log('Updated site link: success');
    }

    // Affiliate Link
    $current_affiliate_link = get_field('affiliate_link', $term_id);
    $new_affiliate_link = '';
    $brand_name = '';

    if ($api_source === 'addrevenue') {
        $new_affiliate_link = isset($brand_data['relation']['trackingLink']) ? $brand_data['relation']['trackingLink'] : '';
        $brand_name = isset($market_data['displayName']) ? $market_data['displayName'] : '';
    } elseif ($api_source === 'awin') {
        $new_affiliate_link = isset($brand_data['clickThroughUrl']) ? $brand_data['clickThroughUrl'] : '';
        $brand_name = isset($brand_data['name']) ? $brand_data['name'] : '';
    }

    if (!empty($new_affiliate_link) && empty($current_affiliate_link)) {
        error_log('New Affiliate Link: ' . $new_affiliate_link);
        error_log('Brand name before short_affiliate_link: ' . $brand_name);

        $short_url = short_affiliate_link($new_affiliate_link, $brand_name);
        if ($short_url) {
            update_field('affiliate_link', $short_url, $term_id);
            error_log('Created and updated new short affiliate link: ' . $short_url);
        } else {
            error_log('Failed to create short affiliate link for ' . $brand_name);
        }
    } else {
        error_log('Affiliate link already exists or no new link provided. No update needed.');
    }
    
    // Brand Description
   $brand_term = get_term($brand_term_id, 'brands');
    if ($brand_term && !is_wp_error($brand_term)) {
        $current_description = $brand_term->description;
        $needs_update = false;

        // Case 1: Empty description
        if (empty(trim($current_description))) {
            error_log("Empty description found for brand ID: $brand_term_id");
            $new_description = generate_brand_description($brand_name, $brand_term_id, $brand_data);
            if ($new_description) {
                $current_description = $new_description;
                $needs_update = true;
            }
        }
        
        // Case 2: Description exists but no hashtags
        if (!empty($current_description) && !preg_match('/#[a-zA-Z0-9-_]+/', $current_description)) {
            error_log("Description without hashtags found for brand ID: $brand_term_id");
            $processed_description = process_description_hashtags($current_description, $brand_name);
            if ($processed_description !== $current_description) {
                $current_description = $processed_description;
                $needs_update = true;
            }
        }

        // Update if needed
        if ($needs_update) {
            error_log("Updating description for brand ID: $brand_term_id");
            remove_filter('pre_term_description', 'wp_filter_kses');
            $update_result = wp_update_term($brand_term_id, 'brands', [
                'description' => $current_description
            ]);
            add_filter('pre_term_description', 'wp_filter_kses');
            
            if (is_wp_error($update_result)) {
                error_log("Failed to update brand description: " . $update_result->get_error_message());
            } else {
                error_log("Successfully updated description for brand: $brand_name");
            }
        } else {
            error_log("No description update needed for brand ID: $brand_term_id");
        }
    }


    $why_we_love = get_field('why_we_love', $term_id);
    if (empty($why_we_love)) {
        error_log("Generating why we love for brand: " . $brand_name);
        $new_why_we_love = generate_why_we_love($brand_name, $brand_data);
        
        error_log("Generated why we love content: " . print_r($new_why_we_love, true));
        if ($new_why_we_love) {
            $updated = update_field('why_we_love', $new_why_we_love, $term_id);
            error_log("Updated why_we_love for brand " . $brand_name . ": " . ($updated ? 'success' : 'failed'));
        } else {
            error_log("Failed to generate why we love content for brand: " . $brand_name);
        }
    }
    
    if ($api_source === 'awin') {
        update_field('awin_id', $brand_data['id'], $term_id);
        update_field('primary_region', $brand_data['primaryRegion']['name'], $term_id);
        update_field('primary_sector', $brand_data['primarySector'], $term_id);
    }
}

function process_description_hashtags($content, $brand_name) {
    
    if (preg_match('/#[a-zA-Z0-9-_]+/', $content)) {
        return $content; 
    }
    
    $content = preg_replace('/<p[^>]*>\s*#.*?<\/p>/', '', $content);
    
    $hashtag_line = generate_hashtag_line($brand_name);
    return trim($content) . "\n\n" . $hashtag_line;
}

function generate_hashtag_line($brand_name) {
    $slug = sanitize_title($brand_name);
    return sprintf(
        '<p style="text-align: left"><strong>#%1$s-discountcodes, #%1$s-savings, #%1$s-sales, #%1$s-bargains, #%1$s-vouchers, #%1$s-codes</strong></p>',
        $slug
    );
}

function update_brand($brand_term_id, $brand_data, $market_data, $api_source = 'addrevenue') {
     if ($api_source === 'addrevenue') {
        $brand_url = esc_url($market_data['url']);
        $brand_affiliate_link = isset($brand_data['relation']['trackingLink']) ? $brand_data['relation']['trackingLink'] : '';
        $brand_logo = isset($brand_data['logoImageFilename']) ? esc_url($brand_data['logoImageFilename']) : '';
        $brand_name = isset($market_data['displayName']) ? $market_data['displayName'] : $brand_data['name'];
    } elseif ($api_source === 'awin') {
        $brand_url = esc_url($brand_data['displayUrl']);
        $brand_affiliate_link = $brand_data['clickThroughUrl'];
        $brand_logo = $brand_data['logoUrl'];
        $brand_name = $brand_data['name'];
    }

    error_log('Brand URL: ' . $brand_url);
    error_log('Affiliate Link 2: ' . $brand_affiliate_link);
    // error_log('Description: ' . $brand_description);
    error_log('Featured Image: ' . $brand_logo);

    $brand_popular = isset($brand_data['featured']) ? $brand_data['featured'] : 0;

   $brand_affiliate_link = short_affiliate_link($brand_affiliate_link, $brand_name);

   $term_id = 'brands_' . $brand_term_id;

    error_log('Updating ACF fields for term ID: ' . $term_id);
    error_log('Image URL to be updated: ' . $brand_logo);

    $updated_popular = update_field('popular_brand', $brand_popular, $term_id);
    $updated_site = update_field('site_link', $brand_url, $term_id);
    $updated_affiliate = update_field('affiliate_link', $brand_affiliate_link, $term_id);


    $current_image_id = get_field('featured_image', $term_id);
    if ($api_source === 'addrevenue') {
        $new_image_url = isset($brand_data['logoImageFilename']) ? esc_url($brand_data['logoImageFilename']) : '';
    } elseif ($api_source === 'awin') {
        $new_image_url = isset($brand_data['logoUrl']) ? esc_url($brand_data['logoUrl']) : '';
    }

    $current_image_id = get_field('featured_image', $term_id);
    if (empty($current_image_id)) {
        if ($api_source === 'addrevenue') {
            $new_image_url = isset($brand_data['logoImageFilename']) ? esc_url($brand_data['logoImageFilename']) : '';
        } elseif ($api_source === 'awin') {
            $new_image_url = isset($brand_data['logoUrl']) ? esc_url($brand_data['logoUrl']) : '';
        }

        if (!empty($new_image_url)) {
            $image_id = media_sideload_image($new_image_url, 0, null, 'id');
            if (!is_wp_error($image_id)) {
                update_field('featured_image', $image_id, $term_id);
                update_post_meta($image_id, '_brand_logo_image', '1');
                error_log("Added new featured image for brand ID $brand_term_id. New image ID: $image_id");
            } else {
                error_log("Failed to add new featured image for brand ID $brand_term_id: " . $image_id->get_error_message());
            }
        } else {
            error_log("No image URL provided for brand ID $brand_term_id");
        }
    } else {
        error_log("Featured image already exists for brand ID $brand_term_id. Skipping image update.");
    }

    
    // Log the results of the updates
    error_log('Updated popular brand: ' . ($updated_popular ? 'success' : 'failure'));
    error_log('Updated featured image: ' . ($updated_image ? 'success' : 'failure'));
    error_log('Updated site link: ' . ($updated_site ? 'success' : 'failure'));
    error_log('Updated affiliate link: ' . ($updated_affiliate ? 'success' : 'failure'));

    error_log('Updated ACF fields for brand ID: ' . $brand_term_id);

    // wp_update_term($brand_term_id, 'brands', array(
    //     'description' => $brand_description
    // ));

    error_log('Updated ACF fields for brand ID: ' . $brand_term_id);
}

function find_similar_brand($advertiser_name) {
    $brand_term = get_term_by('name', $advertiser_name, 'brands');
    if ($brand_term) {
        return $brand_term;
    }

    $normalized_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $advertiser_name));

    $similar_terms = get_terms(array(
        'taxonomy' => 'brands',
        'hide_empty' => false,
    ));

    $best_match = null;
    $highest_similarity = 0;

    foreach ($similar_terms as $term) {

        $normalized_term = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $term->name));

        similar_text($normalized_name, $normalized_term, $percent);

        if ($normalized_name === $normalized_term) {
            return $term;
        }

        if ($percent > $highest_similarity) {
            $highest_similarity = $percent;
            $best_match = $term;
        }
    }

    if ($highest_similarity > 80) {
        error_log("Found similar brand: '{$best_match->name}' for '{$advertiser_name}' with {$highest_similarity}% similarity");
        return $best_match;
    }

    return false;
}


function generate_coupon_title($description) {
    $api_key = get_option('openai_api_key'); 
    $endpoint = 'https://api.openai.com/v1/chat/completions';
    $prompt_template = get_option('coupon_title_prompt');

    $sanitized_description = sanitize_text_field($description);
    // error_log('Sanitized Description: ' . $sanitized_description);

    $prompt = $prompt_template . ' ' . $sanitized_description;
    // error_log('Generated Prompt: ' . $prompt);

    $response = wp_remote_post($endpoint, array(
        'body' => json_encode(array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt,
                )
            ),
            'max_tokens' => 80,
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
    ));

    if (is_wp_error($response)) {

        wp_schedule_single_event(
            time() + HOUR_IN_SECONDS,
            'retry_generate_coupon_title',
            array($description)
        );
        error_log('OpenAI API request failed. Scheduled retry in 1 hour.');
        return false; 
    }

    $body = wp_remote_retrieve_body($response);
    // error_log('OpenAI API response body: ' . $body);
    $body = json_decode($body, true);
    // error_log('Decoded OpenAI API response: ' . print_r($body, true));

     if (isset($body['choices'][0]['message']['content'])) {
        $title = sanitize_text_field($body['choices'][0]['message']['content']);
        $title = trim($title);
        $title = str_replace(array('"', "'"), '', $title); // Remove any quotes
        return $title;
    } else {
        return 'Default Coupon Title';
    }
}

add_action('retry_generate_coupon_title', 'generate_coupon_title');

function generate_brand_description($brand_name, $brand_term_id, $brand_data = array()) {
    error_log("Starting brand description generation for: " . $brand_name);

    if (empty($brand_term_id) || !is_numeric($brand_term_id) || get_term($brand_term_id, 'brands') === null) {
        error_log("Error: Invalid or non-existing brand term ID for: " . $brand_name);
        return false;
    }

    $api_key = get_option('openai_api_key');
    $prompt_template = get_option('brand_description_prompt');

    // Create context from brand data
    $context = "Brand Name: $brand_name\n";
    if ($brand_data) {
        if (isset($brand_data['primarySector'])) {
            $context .= "Sector: " . $brand_data['primarySector'] . "\n";
        }
        if (isset($brand_data['strapLine'])) {
            $context .= "Tagline: " . $brand_data['strapLine'] . "\n";
        }
    }

    $prompt = $prompt_template . "\n\nContext:\n" . $context;
    error_log("Generated prompt: " . $prompt);

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'timeout' => 30,
        'body' => json_encode(array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt,
                )
            ),
            'max_tokens' => 1000,
            'temperature' => 0.7,
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
    ));

    if (is_wp_error($response)) {
        error_log('OpenAI API request failed: ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    error_log("API Response: " . print_r($body, true));

    if (!is_array($body) || empty($body['choices']) || !isset($body['choices'][0]['message']['content'])) {
        error_log("OpenAI API Response Error: " . print_r($body, true));
        return false;
    }

    // Get the content from OpenAI
    $content = $body['choices'][0]['message']['content'];

    // Allow only safe HTML tags
    $allowed_tags = array(
        'h4' => array('style' => array(), 'class' => array()),
        'p' => array('style' => array(), 'class' => array()),
        'strong' => array(),
        'em' => array(),
        'ul' => array(),
        'li' => array(),
        'br' => array(),
    );

    // Ensure safe HTML is preserved
    $formatted_content = wp_kses($content, $allowed_tags);

    return $formatted_content;
}


function generate_why_we_love($brand_name, $brand_data = array()) {
    error_log("Starting why_we_love generation for brand: " . $brand_name);
    
    $api_key = get_option('openai_api_key');
    if (empty($api_key)) {
        error_log('Missing OpenAI API Key');
        return false;
    }
    $prompt_template = get_option('why_we_love_prompt');
    

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body' => json_encode(array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt_template
                )
            ),
            'max_tokens' => 500,
            'temperature' => 0.7,
        ))
    ));

    if (is_wp_error($response)) {
        error_log('OpenAI API request failed: ' . $response->get_error_message());
        return false;
    }

   $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['choices'][0]['message']['content'])) {
        error_log("Failed to get valid response from OpenAI API");
        return false;
    }

    $content = $body['choices'][0]['message']['content'];
    
    preg_match_all('/<li.*?>\s*.*?\/>([^<]+)<\/li>/', $content, $matches);
    $phrases = array_map('trim', $matches[1]);
    
    if (empty($phrases)) {
        
        preg_match_all('/[-•]\s*([^"\n]+)/', $content, $matches);
        $phrases = array_map('trim', $matches[1]);
    }

    $phrases = array_map(function($phrase) {
        $words = preg_split('/\s+/', trim($phrase));
        return implode(' ', array_slice($words, 0, 3));
    }, $phrases);

    $phrases = array_slice($phrases, 0, 3);
    while (count($phrases) < 3) {
        $default_phrases = ['Expert Service', 'Premium Quality', 'Fast Delivery'];
        $phrases[] = $default_phrases[count($phrases)];
    }

    $image_mappings = [
                        'gift' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/gift-2.png',
                        'keywords' => ['gift', 'present', 'surprise', 'unique', 'special', 'perfect']
                        ],
                        'tag' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/tag.png',
                        'keywords' => ['price', 'value', 'affordable', 'saving', 'deal', 'budget']
                        ],
                        'free' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/free.png',
                        'keywords' => ['free', 'bonus', 'extra', 'complimentary', 'gift']
                        ],
                        'piggy' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/piggybank.png',
                        'keywords' => ['save', 'savings', 'discount', 'bargain', 'offer', 'cheap']
                        ],
                        'security' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/security-payment.png',
                        'keywords' => ['secure', 'safe', 'protected', 'trusted', 'payment']
                        ],
                        'cyber' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/cyber-security.png',
                        'keywords' => ['online', 'digital', 'cyber', 'electronic', 'virtual']
                        ],
                        'social' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/social-security-1.png',
                        'keywords' => ['social', 'community', 'shared', 'connected', 'together']
                        ],
                        'certified' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/certified.png',
                        'keywords' => ['certified', 'approved', 'verified', 'tested', 'authentic']
                        ],
                        'heart' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/healthy-heart.png',
                        'keywords' => ['heart', 'loved', 'favorite', 'chosen', 'adored']
                        ],
                        'service' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/customer-service.png',
                        'keywords' => ['customer service', 'customer', 'support', 'assistance', 'care', 'helpdesk', 'excellence']
                        ],
                        '24hours' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/24-hours.png',
                        'keywords' => ['24/7', 'always', 'available', 'constant', 'nonstop']
                        ],
                        'recommended' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/recommended.png',
                        'keywords' => ['recommended', 'endorsed', 'rated', 'reviewed', 'trusted']
                        ],
                        'fast' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/fast-delivery.png',
                        'keywords' => ['fast', 'quick', 'rapid', 'speedy', 'prompt']
                        ],
                        'delivery' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/delivery.png',
                        'keywords' => ['delivery', 'shipping', 'transport', 'sent', 'dispatch']
                        ],
                        'store' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/in-store-display.png',
                        'keywords' => ['store', 'shop', 'retail', 'display', 'collection']
                        ],
                        'refund' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/refund.png',
                        'keywords' => ['refund', 'return', 'money', 'guarantee', 'promise']
                        ],
                        'exchange' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/exchange.png',
                        'keywords' => ['exchange', 'swap', 'trade', 'replace', 'change']
                        ],
                        'medal' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/medal.png',
                        'keywords' => ['quality', 'premium', 'luxury', 'best', 'finest']
                        ],
                        'handmade' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/hand-made.png',
                        'keywords' => ['handmade', 'crafted', 'custom', 'artisan', 'unique']
                        ],
                        'famous' => [
                        'image' => 'https://www.adealsweden.com/wp-content/uploads/2023/11/famous.png',
                        'keywords' => ['famous', 'popular', 'known', 'celebrated', 'recognized']
                        ]
                        ];

    $used_images = [];
    $list_items = [];

    foreach ($phrases as $phrase) {
        $best_match = null;
        $best_score = 0;
        $phrase_lower = strtolower($phrase);

        // Find the best matching image based on keywords
        foreach ($image_mappings as $key => $mapping) {
            if (in_array($mapping['image'], $used_images)) {
                continue;
            }

            $score = 0;
            foreach ($mapping['keywords'] as $keyword) {
                if (strpos($phrase_lower, $keyword) !== false) {
                    $score += 5;
                }
                similar_text($phrase_lower, $keyword, $similarity);
                $score += $similarity / 20;
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $mapping['image'];
            }
        }

        // If no good match found, pick a random unused image
        if (!$best_match || $best_score < 2) {
            $available_images = array_diff(
                array_column($image_mappings, 'image'),
                $used_images
            );
            if (!empty($available_images)) {
                $best_match = array_values($available_images)[array_rand($available_images)];
            } else {
                // Fallback to any image if all are used
                $all_images = array_column($image_mappings, 'image');
                $best_match = $all_images[array_rand($all_images)];
            }
        }

        $used_images[] = $best_match;
        $list_items[] = sprintf(
            '<li><img class="alignnone size-full" src="%s" alt="" width="64" height="64" /> %s</li>',
            esc_url($best_match),
            esc_html(ucwords($phrase))
        );
    }

    return "<ul>\n" . implode("\n", $list_items) . "\n</ul>";
}


function translate_description($description) {
    $api_key = get_option('openai_api_key');
    $endpoint = 'https://api.openai.com/v1/chat/completions';
    $prompt_template = get_option('description_prompt');
    $system_message = "You are a coupon terms creator. Your task is to create concise, clear, and varied bullet points for coupon terms. Always follow the given instructions exactly. Ensure each point is unique and complete.";
    $sanitized_description = sanitize_text_field($description);

    $prompt = $prompt_template . ' ' . $sanitized_description;

    $response = wp_remote_post($endpoint, array(
        'body' => json_encode(array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array(
                    "role" => "system",
                    "content" => $system_message
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt,
                )
            ),
            'max_tokens' => 150,
            'temperature' => 0.4
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        )
    ));

    if (is_wp_error($response)) {
        // Schedule retry in one hour
        wp_schedule_single_event(
            time() + HOUR_IN_SECONDS,
            'retry_translate_description',
            array($description)
        );
        error_log('OpenAI API request failed. Scheduled retry in 1 hour.');
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $body = json_decode($body, true);

     if (isset($body['choices'][0]['message']['content'])) {
        $translated_description = $body['choices'][0]['message']['content'];
        $translated_description = preg_replace('/^[•\-\d.]\s*/m', '', $translated_description);
        $terms_array = array_filter(array_map('trim', explode("\n", $translated_description)));
        $terms_array = array_unique($terms_array);
        $terms_array = array_slice($terms_array, 0, 3);
        while (count($terms_array) < 3) {
            $new_term = "See full terms on website";
            if (!in_array($new_term, $terms_array)) {
                $terms_array[] = $new_term;
            } else {
                $terms_array[] = "Terms and conditions apply";
            }
        }
        $terms_array = array_map(function($term) {
            return ltrim($term, '. '); 
        }, $terms_array);
        $final_terms = implode(' - ', $terms_array);
        return $final_terms;
    } else {
        error_log('OpenAI API response does not contain expected content.');
        return $description;
    }
}

add_action('retry_translate_description', 'translate_description');

function short_affiliate_link($brand_affiliate_link, $advertiser_name) {
    error_log('Received advertiser name for short link: ' . $advertiser_name);
    $yourls_api_url = 'https://www.adealsweden.com/r./yourls-api.php';
    $yourls_api_token = get_option('yourl_api_token');
    $user = 'borko';
    $pass = 'passwordnotthateasy';
    $keyword = sanitize_title($advertiser_name);
    $format = 'json';
    error_log('Generated keyword: ' . $keyword);
    
    $existing_url = fetch_existing_short_url($yourls_api_url, $user, $pass, $keyword);
    if ($existing_url) {
        error_log('Existing short URL found: ' . $existing_url);
        return $existing_url;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $yourls_api_url);    
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
                    'username' => $user,
                    'password' => $pass,
                    'action' => 'shorturl',
                    'keyword' => $keyword,
                    'format' => $format,
                    'url' => $brand_affiliate_link

        )));
    curl_setopt($ch, CURLOPT_CAINFO, ABSPATH . WPINC . '/certificates/ca-bundle.crt'); 

    $response = curl_exec($ch);
    error_log('API response: ' . $response);
    if (curl_errno($ch)) {
        error_log('cURL error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if (isset($result['shorturl'])) {
        error_log('Created new short URL: ' . $result['shorturl']);
        return $result['shorturl'];
    }

    return false;
}

function fetch_existing_short_url($api_url, $user, $pass, $keyword) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
        'username' => $user,
        'password' => $pass,
        'action' => 'shorturl',
        'keyword' => $keyword,
        'format' => 'json'
    )));
    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($response, true);
    
    if (isset($result['shorturl'])) {
        return $result['shorturl'];
    }
    return false;
}
