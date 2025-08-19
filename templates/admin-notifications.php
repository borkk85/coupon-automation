<?php
$notificationManager = new \CouponAutomation\Utils\NotificationManager();
$notifications = $notificationManager->getUnread();
$totalCount = $notificationManager->getCount();

if (!empty($notifications)):
?>
<div id="notifications-container" class="notice notice-info is-dismissible coupon-automation-notifications">
  <div class="ca-section-body">
    <!-- Header -->
    <div class="ca-notice-header">
      <h3 style="display:flex;align-items:center;gap:.5rem;margin:0;font-weight:600;">
        <svg class="ca-icon ca-icon--blue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
        Coupon Automation Notifications
        <span class="ca-badge"><?php echo count($notifications); ?> new</span>
      </h3>
      <button id="clear-notifications" class="ca-btn ca-btn-secondary" style="padding:.25rem .5rem;">
        <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
      </button>
    </div>

    <!-- Notifications List -->
    <div style="max-height:24rem; overflow-y:auto;">
      <?php foreach($notifications as $notification): ?>
        <?php
          $type = $notification['type'];
          $data = $notification['data'];
          $time = $notification['time'];
          $timeAgo = human_time_diff(strtotime($time), current_time('timestamp')) . ' ago';

          $noteClass = 'ca-note ca-note--blue';
          $iconClass = 'ca-icon ca-icon--blue';
          $icon = '';

          switch($type){
            case 'coupon':
              $noteClass = 'ca-note ca-note--green';
              $iconClass = 'ca-icon ca-icon--green';
              $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path>';
              $message = sprintf('New coupon created: <strong>%s</strong> for %s', esc_html($data['title'] ?? ''), esc_html($data['brand'] ?? ''));
              break;
            case 'brand':
              $noteClass = 'ca-note ca-note--purple';
              $iconClass = 'ca-icon ca-icon--purple';
              $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>';
              $message = sprintf('New brand created: <strong>%s</strong>', esc_html($data['name'] ?? ''));
              break;
            case 'error':
              $noteClass = 'ca-note ca-note--red';
              $iconClass = 'ca-icon ca-icon--red';
              $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
              $message = sprintf('Error: %s', esc_html($data['message'] ?? ''));
              break;
            case 'sync':
              $noteClass = 'ca-note ca-note--yellow';
              $iconClass = 'ca-icon ca-icon--yellow';
              $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>';
              $message = esc_html($data['message'] ?? 'Sync operation completed');
              break;
            default:
              $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
              $message = esc_html($data['message'] ?? '');
          }
        ?>
        <div class="<?php echo $noteClass; ?>">
          <svg class="<?php echo $iconClass; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
          <div style="flex:1;">
            <p style="margin:0;font-size:.875rem;color:#111827;"><?php echo $message; ?></p>
            <p class="ca-muted" style="margin:.25rem 0 0;font-size:.75rem;"><?php echo $timeAgo; ?></p>
          </div>
          <?php if($type === 'coupon' && isset($data['id'])): ?>
            <a href="<?php echo get_edit_post_link($data['id']); ?>" class="ca-btn ca-btn-primary" style="margin-left:.75rem;padding:.25rem .5rem;">
              <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Footer -->
    <?php if($totalCount > count($notifications)): ?>
      <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--ca-border);">
        <p class="ca-muted" style="font-size:.875rem;">
          Showing <?php echo count($notifications); ?> of <?php echo $totalCount; ?> notifications
          <a href="#" id="view-all-notifications" style="color:var(--ca-blue);margin-left:.5rem;">View all</a>
        </p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
jQuery(function($){
  // Mark notifications as read when viewed
  setTimeout(function(){
    $.post(ajaxurl,{ action:'mark_notifications_read', nonce:'<?php echo wp_create_nonce('coupon_automation_nonce'); ?>' });
  }, 3000);
});
</script>
<?php endif; ?>