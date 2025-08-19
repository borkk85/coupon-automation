<?php

namespace CouponAutomation\API;

/**
 * AddRevenue API implementation
 */
class AddRevenueAPI extends BaseAPI {
    
    protected function loadCredentials() {
        $this->apiKey = get_option('addrevenue_api_token');
        $this->baseUrl = 'https://addrevenue.io/api/v2/';
    }
    
    public function testConnection() {
        $result = $this->makeRequest('advertisers?channelId=3454851&limit=1');
        return $result !== false;
    }
    
    public function getAdvertisers() {
        $cache_key = 'addrevenue_advertisers_data';
        $cached = $this->getCachedResponse($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $response = $this->makeRequest('advertisers?channelId=3454851');
        
        if ($response && isset($response['results'])) {
            $this->cacheResponse($cache_key, $response['results']);
            return $response['results'];
        }
        
        return [];
    }
    
    public function getCampaigns() {
        $cache_key = 'addrevenue_campaigns_data';
        $cached = $this->getCachedResponse($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $response = $this->makeRequest('campaigns?channelId=3454851');
        
        if ($response && isset($response['results'])) {
            $this->cacheResponse($cache_key, $response['results']);
            return $response['results'];
        }
        
        return [];
    }
}

