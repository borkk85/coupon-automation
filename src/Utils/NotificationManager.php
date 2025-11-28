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
        
        $result = update_option($this->optionKey, $notifications);
        
        error_log('[CA] Notification added - Type: ' . $type . ', Saved: ' . ($result ? 'YES' : 'NO'));
        
        return $result;
    }
    
    /**
     * Get all notifications
     */
    public function getAll() {
        $notifications = get_option($this->optionKey, []);
        
        // Ensure it's an array
        if (!is_array($notifications)) {
            error_log('[CA] NotificationManager: getAll() returned non-array, resetting');
            $notifications = [];
            update_option($this->optionKey, []);
        }
        
        return $notifications;
    }
    
    /**
     * Get unread notifications
     */
    public function getUnread() {
        $notifications = $this->getAll();
        
        error_log('[CA] NotificationManager: Total notifications: ' . count($notifications));
        
        // Filter unread
        $unread = array_filter($notifications, function($n) {
            // Handle both array and object notation
            $isRead = isset($n['read']) ? $n['read'] : (isset($n->read) ? $n->read : true);
            return !$isRead;
        });
        
        // Re-index array
        $unread = array_values($unread);
        
        error_log('[CA] NotificationManager: Unread count: ' . count($unread));
        
        if (count($unread) > 0) {
            error_log('[CA] NotificationManager: First unread notification: ' . json_encode($unread[0]));
        }
        
        return $unread;
    }
    
    /**
     * Mark all as read
     */
    public function markAllRead() {
        $notifications = $this->getAll();
        
        $unreadCount = 0;
        foreach ($notifications as &$notification) {
            if (!$notification['read']) {
                $unreadCount++;
                $notification['read'] = true;
            }
        }
        
        $result = update_option($this->optionKey, $notifications);
        
        error_log('[CA] Marked ' . $unreadCount . ' notifications as read. Save result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        
        return $result;
    }
    
    /**
     * Clear all notifications
     */
    public function clearAll() {
        $count = $this->getCount();
        $result = update_option($this->optionKey, []);
        
        error_log('[CA] Cleared ' . $count . ' notifications. Result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        
        return $result;
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