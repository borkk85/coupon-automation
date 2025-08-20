<?php

namespace CouponAutomation\API;

/**
 * AWIN API implementation - FIXED VERSION
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
        // Fix: Use proper endpoint without malformed URL
        $endpoint = sprintf("publisher/%s/promotions", $this->publisherId);
        $result = $this->makeRequest($endpoint);
        return $result !== false;
    }
    
    public function getPromotions() {
        if (empty($this->publisherId)) {
            $this->logger->error('AWIN Publisher ID not configured');
            return [];
        }
        
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
        
        // Fix: Use correct endpoint format
        $endpoint = sprintf("publisher/%s/promotions", $this->publisherId);
        
        $response = $this->makeRequest(
            $endpoint,
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
        if (empty($this->publisherId)) {
            return false;
        }
        
        $endpoint = sprintf(
            "publisher/%s/programmedetails?advertiserId=%s",
            $this->publisherId,
            $advertiserId
        );
        
        return $this->makeRequest($endpoint);
    }
}