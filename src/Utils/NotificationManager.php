<?php

namespace CouponAutomation\Utils;

/**
 * Notification manager
 */
class NotificationManager {
    
    private $optionKey = 'coupon_automation_notifications';
    private $maxNotifications = 100;
    
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

        // Avoid duplicate consecutive notifications with identical payload
        $lastNotification = end($notifications);
        if ($lastNotification !== false) {
            $sameType = isset($lastNotification['type']) && $lastNotification['type'] === $notification['type'];
            $sameData = isset($lastNotification['data']) && $lastNotification['data'] == $notification['data'];
            if ($sameType && $sameData) {
                return true;
            }
        }

        $notifications[] = $notification;

        // Keep only latest notifications
        if (count($notifications) > $this->maxNotifications) {
            $notifications = array_slice($notifications, -$this->maxNotifications);
        }

        return update_option($this->optionKey, $notifications);
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

        $unread = array_filter($notifications, function($n) {
            // Handle both array and object notation
            $isRead = isset($n['read']) ? $n['read'] : (isset($n->read) ? $n->read : true);
            return !$isRead;
        });

        $unread = array_values($unread);

        return $unread;
    }
    
    /**
     * Mark all as read
     */
    public function markAllRead() {
        $notifications = $this->getAll();

        $changed = false;
        foreach ($notifications as &$notification) {
            if (isset($notification['read']) && $notification['read']) {
                continue;
            }
            $notification['read'] = true;
            $changed = true;
        }
        unset($notification);

        if (!$changed) {
            return true;
        }

        return update_option($this->optionKey, $notifications);
    }
    
    /**
     * Clear all notifications
     */
    public function clearAll() {
        return update_option($this->optionKey, []);
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
