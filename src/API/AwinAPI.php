<?php

namespace CouponAutomation\API;

/**
 * AWIN API implementation
 */
class AwinAPI extends BaseAPI {
    
    private $publisherId;
    
    protected function loadCredentials() {
        
        $this->apiKey = get_option('awin_api_token');
        $this->publisherId = get_option('awin_publisher_id');
        $this->baseUrl = 'https://api.awin.com/';

    }
    
    public function testConnection() {
        if (empty($this->publisherId)) {
            return false;
        }
        
        // FIX: Use publisher (singular) consistently
        $result = $this->makeRequest("publishers/{$this->publisherId}/programmes");
        return $result !== false;
    }
    
    public function getPromotions() {
        $cache_key = 'awin_promotions_data';
        $cached = $this->getCachedResponse($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $body = [
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
        ];
        
        // FIX: Use publishers (plural) for promotions endpoint
        $response = $this->makeRequest(
            "publisher/{$this->publisherId}/promotions/",
            'POST',
            $body
        );
        
        if ($response && isset($response['data'])) {
            $this->cacheResponse($cache_key, $response['data']);
            return $response['data'];
        }
        
        return [];
    }
    
    public function getProgrammeDetails($advertiserId) {
        return $this->makeRequest(
            "publishers/{$this->publisherId}/programmedetails?advertiserId={$advertiserId}"
        );
    }
}