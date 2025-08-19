<?php

namespace CouponAutomation\Utils;

/**
 * Notification manager
 */
class NotificationManager {
    
    private $optionKey = 'coupon_automation_notifications';
    private $maxNotifications = 50;
    
    /**
     * Add notification
     */
    public function add($type, $data) {
        $notifications = $this->getAll();
        
        $notification = [
            'type' => $type,
            'data' => $data,
            'time' => current_time('mysql'),
            'read' => false
        ];
        
        $notifications[] = $notification;
        
        // Keep only latest notifications
        if (count($notifications) > $this->maxNotifications) {
            $notifications = array_slice($notifications, -$this->maxNotifications);
        }
        
        update_option($this->optionKey, $notifications);
    }
    
    /**
     * Get all notifications
     */
    public function getAll() {
        return get_option($this->optionKey, []);
    }
    
    /**
     * Get unread notifications
     */
    public function getUnread() {
        $notifications = $this->getAll();
        return array_filter($notifications, function($n) {
            return !$n['read'];
        });
    }
    
    /**
     * Mark all as read
     */
    public function markAllRead() {
        $notifications = $this->getAll();
        
        foreach ($notifications as &$notification) {
            $notification['read'] = true;
        }
        
        update_option($this->optionKey, $notifications);
    }
    
    /**
     * Clear all notifications
     */
    public function clearAll() {
        update_option($this->optionKey, []);
    }
    
    /**
     * Get notification count
     */
    public function getCount() {
        return count($this->getAll());
    }
    
    /**
     * Get unread count
     */
    public function getUnreadCount() {
        return count($this->getUnread());
    }
}