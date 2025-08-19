<?php


if (!defined('ABSPATH')) {
    exit; 
}

function fetch_and_process_api_data() {
    error_log('Starting fetch_and_process_api_data');
    if (get_option('coupon_automation_stop_requested', false)) {
        error_log('Coupon automation stop requested. Aborting fetch_and_process_api_data.');
        delete_transient('fetch_process_running');
        delete_transient('api_processed_count');
        update_option('coupon_automation_stop_requested', false);
        return;
    }

    if (get_transient('fetch_process_running')) {
        error_log('fetch_and_process_api_data is already running. Aborting.');
        return;
    }
    set_transient('fetch_process_running', true, 30 * MINUTE_IN_SECONDS);

    $addrevenue_api_token = get_option('addrevenue_api_token');
    $awin_api_token = get_option('awin_api_token');
    $awin_publisher_id = get_option('awin_publisher_id');

    $all_data = array();

    // Fetch AddRevenue data
    if (!empty($addrevenue_api_token)) {
        $advertisers_url = 'https://addrevenue.io/api/v2/advertisers?channelId=3454851';
        $campaigns_url = 'https://addrevenue.io/api/v2/campaigns?channelId=3454851';

        $advertisers = get_transient('addrevenue_advertisers_data');
        if (false === $advertisers) {
            $advertisers = fetch_addrevenue_data($advertisers_url, $addrevenue_api_token);
            set_transient('addrevenue_advertisers_data', $advertisers, HOUR_IN_SECONDS);
        }

        $campaigns = get_transient('addrevenue_campaigns_data');
        if (false === $campaigns) {
            $campaigns = fetch_addrevenue_data($campaigns_url, $addrevenue_api_token);
            set_transient('addrevenue_campaigns_data', $campaigns, HOUR_IN_SECONDS);
        }

        $all_data['addrevenue'] = array(
            'advertisers' => $advertisers,
            'campaigns' => $campaigns
        );
    }

    // Fetch AWIN data
    if (!empty($awin_api_token) && !empty($awin_publisher_id)) {
        $promotions_url = "https://api.awin.com/publisher/{$awin_publisher_id}/promotions/";

        $awin_promotions = get_transient('awin_promotions_data');
        if (false === $awin_promotions) {
            error_log('Fetching AWIN promotions data...');
            $awin_promotions = fetch_awin_promotions($promotions_url, $awin_api_token);
            if (!empty($awin_promotions)) {
                set_transient('awin_promotions_data', $awin_promotions, HOUR_IN_SECONDS);
                error_log('AWIN data fetched: ' . count($awin_promotions) . ' promotions');
                error_log('AWIN promotion types: ' . print_r(array_count_values(array_column($awin_promotions, 'type')), true));
            } else {
                error_log('Failed to fetch AWIN promotions or no promotions returned.');
            }
        } else {
            error_log('Using cached AWIN data: ' . count($awin_promotions) . ' promotions');
            error_log('AWIN promotion types: ' . print_r(array_count_values(array_column($awin_promotions, 'type')), true));
        }

        $all_data['awin'] = array(
            'promotions' => $awin_promotions
        );
    } else {
        error_log('AWIN API token or Publisher ID is empty. Skipping AWIN data fetch.');
    }

    // Process data
    $chunk_size = 10;
    $total_items = (isset($all_data['addrevenue']['advertisers']) ? count($all_data['addrevenue']['advertisers']) : 0) +
                   (isset($all_data['awin']['promotions']) ? count($all_data['awin']['promotions']) : 0);
    
    error_log("Total items to process: $total_items (AddRevenue: " . 
              (isset($all_data['addrevenue']['advertisers']) ? count($all_data['addrevenue']['advertisers']) : 0) . 
              ", AWIN: " . (isset($all_data['awin']['promotions']) ? count($all_data['awin']['promotions']) : 0) . ")");

    $processed_count = get_transient('api_processed_count') ?: 0;

    for ($i = $processed_count; $i < $total_items; $i += $chunk_size) {
        if (get_option('coupon_automation_stop_requested', false)) {
            error_log('Stop requested during processing. Aborting.');
            break;
        }

        $addrevenue_chunk = array_slice($all_data['addrevenue']['advertisers'], $i, $chunk_size);
        $awin_chunk = array_slice($all_data['awin']['promotions'], max(0, $i - count($all_data['addrevenue']['advertisers'])), $chunk_size);

        if (!empty($addrevenue_chunk)) {
            process_advertiser_chunk($addrevenue_chunk, $all_data['addrevenue']['campaigns'], 'addrevenue');
        }

        if (!empty($awin_chunk)) {
            process_awin_chunk($awin_chunk, $awin_publisher_id, $awin_api_token);
        }

        $processed_count += count($addrevenue_chunk) + count($awin_chunk);
        set_transient('api_processed_count', $processed_count, HOUR_IN_SECONDS);
        error_log("Processed $processed_count out of $total_items items");
        
        // Schedule the next chunk
        if ($processed_count < $total_items) {
            wp_schedule_single_event(time() + 60, 'fetch_and_store_data_event');
            error_log("Scheduled next chunk processing in 60 seconds");
            delete_transient('fetch_process_running');
            return;
        }
    }

    if ($processed_count >= $total_items || get_option('coupon_automation_stop_requested', false)) {
        error_log('Finished processing all items or stop requested');
        delete_transient('addrevenue_advertisers_data');
        delete_transient('addrevenue_campaigns_data');
        delete_transient('awin_promotions_data');
        delete_transient('api_processed_count');
        delete_transient('fetch_process_running');
        update_option('coupon_automation_stop_requested', false);
    }
}

function fetch_addrevenue_data($url, $api_token) {
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($response)) {
        error_log('Failed to fetch AddRevenue data: ' . $response->get_error_message());
        return array();
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['results'])) {
        error_log('Invalid AddRevenue API response format');
        return array();
    }

    return $data['results'];
}

function fetch_awin_promotions($url, $api_token) {
    error_log('Sending request to AWIN API: ' . $url);
    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'filters' => [
                'exclusiveOnly' => false,
                'membership' => 'joined',
                'regionCodes' => ['SE'],
                'status' => 'active',
                'type' => 'all',
                'updatedSince' => '2000-01-01'
            ],
            'pagination' => [
                'page' => 1,
                'pageSize' => 150
            ]
        ])
    ]);

    if (is_wp_error($response)) {
        error_log('WP_Error in AWIN API call: ' . $response->get_error_message());
        return array();
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    error_log("AWIN API response code: $response_code");
    error_log("AWIN API response body (first 1000 characters): " . substr($body, 0, 1000));

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON decoding error: ' . json_last_error_msg());
        return array();
    }

    if (!isset($data['data'])) {
        error_log('AWIN API response does not contain "data" key. Response structure: ' . print_r(array_keys($data), true));
        return array();
    }

    error_log('Successfully fetched AWIN promotions: ' . count($data['data']));
    return $data['data'];
}

function clean_brand_name($brand_name) {
    $codes_to_remove = array('EU', 'UK', 'US', 'CA', 'AU', 'NZ', 'SE', 'NO', 'DK', 'FI');
    
    foreach ($codes_to_remove as $code) {
        $brand_name = preg_replace('/\s+' . $code . '\s*$/i', '', $brand_name);
    }
    
    $brand_name = trim($brand_name);
    
    return $brand_name;
}

function process_awin_chunk($chunk, $publisher_id, $api_token) {
    foreach ($chunk as $promotion) {
        $advertiser_id = $promotion['advertiser']['id'];
        $programme_url = "https://api.awin.com/publishers/{$publisher_id}/programmedetails?advertiserId={$advertiser_id}";
        
        $programme_response = wp_remote_get($programme_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/json'
            ]
        ]);

        if (is_wp_error($programme_response)) {
            error_log('Failed to fetch AWIN programme details: ' . $programme_response->get_error_message());
            continue;
        }

        $programme_data = json_decode(wp_remote_retrieve_body($programme_response), true);
        if (!isset($programme_data['programmeInfo'])) {
            error_log('Invalid AWIN programme details API response format');
            continue;
        }

        $brand_data = $programme_data['programmeInfo'];
        $original_brand_name = sanitize_text_field($brand_data['name']);
        $brand_name = clean_brand_name($original_brand_name);

        error_log("Original brand name: $original_brand_name, Cleaned brand name: $brand_name");

        // Process brand
        $brand_term = find_similar_brand($brand_name);
        if (!$brand_term) {
            $brand_term = wp_insert_term($brand_name, 'brands', array(
                'slug' => sanitize_title($brand_name . '-discountcodes')
            ));
            if (is_wp_error($brand_term)) {
                error_log('Failed to create brand term: ' . $brand_term->get_error_message());
                continue;
            }
            $brand_term_id = $brand_term['term_id'];
            error_log('Created new brand term: ' . $brand_name);
            add_new_brand_notification($brand_name, $brand_term_id);
        } else {
            $brand_term_id = $brand_term->term_id;
        }

        update_brand($brand_term_id, $brand_data, $promotion, 'awin');
        update_brand_fields($brand_term_id, $brand_data, $promotion, 'awin');

        // Process coupon (removed SE market check)
        process_coupon($promotion, $brand_name, 'awin');
    }
}

function process_advertiser_chunk($chunk, $campaigns) {
    foreach ($chunk as $advertiser) {
        if (isset($advertiser['markets']['SE'])) {
            $brand_name = sanitize_text_field($advertiser['markets']['SE']['displayName']);
            
            $brand_coupons = array_filter($campaigns, function($campaign) use ($brand_name) {
                return $campaign['advertiserName'] === $brand_name && isset($campaign['markets']['SE']);
            });

            if (!empty($brand_coupons)) {
                $brand_term = find_similar_brand($brand_name);
                
                if (!$brand_term) {
                    $brand_term = wp_insert_term($brand_name, 'brands', array(
                            'slug' => sanitize_title($brand_name . '-discountcodes')
                        ));
                    if (is_wp_error($brand_term)) {
                        error_log('Failed to create brand term: ' . $brand_term->get_error_message());
                        continue;
                    }
                    $brand_term_id = $brand_term['term_id'];
                    error_log('Created new brand term: ' . $brand_name);
                    add_new_brand_notification($brand_name, $brand_term_id);
                } else {
                    $brand_term_id = $brand_term->term_id;
                }

                update_brand($brand_term_id, $advertiser, $advertiser['markets']['SE']);
                update_brand_fields($brand_term_id, $advertiser, $advertiser['markets']['SE']);

                foreach ($brand_coupons as $coupon_data) {
                    process_coupon($coupon_data, $brand_name);
                }
            } else {
                error_log("Skipping brand '$brand_name' as it has no coupons for the SE market.");
            }
        }
    }
}

add_action('process_next_chunk', 'fetch_and_process_api_data');

function fetch_coupons_for_brands($brands, $api_source = 'addrevenue') {
    $coupons_by_brand = [];

    if ($api_source === 'addrevenue') {
        $campaigns_data = get_option('addrevenue_campaigns_data', array());
        if (empty($campaigns_data)) {
            error_log('AddRevenue campaigns data not found in options');
            return [];
        }

        foreach ($campaigns_data as $coupon_data) {
            $advertiser_name = sanitize_text_field($coupon_data['advertiserName']);
            if (in_array($advertiser_name, $brands) && isset($coupon_data['markets']['SE'])) {
                if (!isset($coupons_by_brand[$advertiser_name])) {
                    $coupons_by_brand[$advertiser_name] = [];
                }
                $coupons_by_brand[$advertiser_name][] = $coupon_data;
            }
        }
    } elseif ($api_source === 'awin') {
        $promotions_data = get_option('awin_promotions_data', array());
        if (empty($promotions_data)) {
            error_log('AWIN promotions data not found in options');
            return [];
        }

        foreach ($promotions_data as $promotion) {
            $advertiser_name = sanitize_text_field($promotion['advertiser']['name']);
            if (in_array($advertiser_name, $brands)) {
                if (!isset($coupons_by_brand[$advertiser_name])) {
                    $coupons_by_brand[$advertiser_name] = [];
                }
                $coupons_by_brand[$advertiser_name][] = $promotion;
            }
        }
    }

    return $coupons_by_brand;
}

function update_brand_from_advertiser_data($brand_term_id, $advertiser_name, $api_source = 'addrevenue') {
    if ($api_source === 'addrevenue') {
        $advertisers_data = get_option('addrevenue_advertisers_data', array());
        if (empty($advertisers_data)) {
            error_log('AddRevenue advertisers data not found in options');
            return;
        }

        $advertiser_data = array_filter($advertisers_data, function($advertiser) use ($advertiser_name) {
            return isset($advertiser['markets']['SE']) && $advertiser['markets']['SE']['displayName'] === $advertiser_name;
        });

        if (!empty($advertiser_data)) {
            $advertiser_data = reset($advertiser_data);
            update_brand($brand_term_id, $advertiser_data, $advertiser_data['markets']['SE'], 'addrevenue');
            update_brand_fields($brand_term_id, $advertiser_data, $advertiser_data['markets']['SE'], 'addrevenue');
        } else {
            error_log('AddRevenue advertiser data not found for: ' . $advertiser_name);
        }
    } elseif ($api_source === 'awin') {
        $promotions_data = get_option('awin_promotions_data', array());
        if (empty($promotions_data)) {
            error_log('AWIN promotions data not found in options');
            return;
        }

        $advertiser_data = array_filter($promotions_data, function($promotion) use ($advertiser_name) {
            return $promotion['advertiser']['name'] === $advertiser_name;
        });

        if (!empty($advertiser_data)) {
            $advertiser_data = reset($advertiser_data);
            $programme_data = fetch_awin_programme_details($advertiser_data['advertiser']['id']);
            if ($programme_data) {
                update_brand($brand_term_id, $programme_data, $advertiser_data, 'awin');
                update_brand_fields($brand_term_id, $programme_data, $advertiser_data, 'awin');
            } else {
                error_log('Failed to fetch AWIN programme details for: ' . $advertiser_name);
            }
        } else {
            error_log('AWIN advertiser data not found for: ' . $advertiser_name);
        }
    }
}

function fetch_awin_programme_details($advertiser_id) {
    $awin_publisher_id = get_option('awin_publisher_id');
    $awin_api_token = get_option('awin_api_token');

    if (empty($awin_publisher_id) || empty($awin_api_token)) {
        error_log('AWIN publisher ID or API token is missing');
        return null;
    }

    $programme_url = "https://api.awin.com/publishers/{$awin_publisher_id}/programmedetails?advertiserId={$advertiser_id}";
    $response = wp_remote_get($programme_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $awin_api_token,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($response)) {
        error_log('Failed to fetch AWIN programme details: ' . $response->get_error_message());
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $programme_data = json_decode($body, true);

    if (isset($programme_data['programmeInfo'])) {
        return $programme_data['programmeInfo'];
    } else {
        error_log('Invalid AWIN programme details response for advertiser ID: ' . $advertiser_id);
        return null;
    }
}