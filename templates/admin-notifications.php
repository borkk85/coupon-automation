<?php
$notificationManager = new \CouponAutomation\Utils\NotificationManager();
$allNotifications    = $notificationManager->getAll();
$unreadNotifications = $notificationManager->getUnread();

// Only surface the banner when there is something new to review.
if (empty($unreadNotifications)) {
    return;
}

$initialNotifications = $unreadNotifications;
$initialHashes = array_map(static function ($notification) {
    return md5(serialize($notification));
}, $initialNotifications);

$remainingNotifications = array_filter($allNotifications, static function ($notification) use ($initialHashes) {
    return !in_array(md5(serialize($notification)), $initialHashes, true);
});

if (!defined('COUPON_AUTOMATION_NOTICES_STYLED')) {
    define('COUPON_AUTOMATION_NOTICES_STYLED', true);
    ?>
    <style>
      .coupon-automation-notifications { border-left: 4px solid #2563eb; padding: 0; }
      .coupon-automation-notifications .ca-section-body { padding: 1rem; }
      .coupon-automation-notifications .ca-notice-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
      .coupon-automation-notifications .ca-notice-header h3 { font-size: 1rem; margin: 0; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
      .coupon-automation-notifications .ca-badge { margin-left: 0.5rem; background: #2563eb; color: #fff; font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px; }
      .coupon-automation-notifications .ca-btn { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.75rem; border-radius: 0.375rem; border: 0; font-size: 0.75rem; font-weight: 600; text-decoration: none; cursor: pointer; }
      .coupon-automation-notifications .ca-btn svg { width: 1rem; height: 1rem; }
      .coupon-automation-notifications .ca-btn-secondary { background: #6b7280; color: #fff; }
      .coupon-automation-notifications .ca-btn-secondary:hover { background: #4b5563; }
      .coupon-automation-notifications .ca-btn-primary { background: #2563eb; color: #fff; }
      .coupon-automation-notifications .ca-btn-primary:hover { background: #1e3a8a; }
      .coupon-automation-notifications .ca-icon { width: 1.25rem; height: 1.25rem; margin-right: 0.75rem; margin-top: 0.125rem; flex-shrink: 0; }
      .coupon-automation-notifications .ca-note { border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.75rem; display: flex; align-items: flex-start; background: #fff; }
      .coupon-automation-notifications .ca-note + .ca-note { margin-top: 0.5rem; }
      .coupon-automation-notifications .ca-note--blue { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; }
      .coupon-automation-notifications .ca-note--green { background: #ecfdf5; border-color: #bbf7d0; color: #03543f; }
      .coupon-automation-notifications .ca-note--purple { background: #f5f3ff; border-color: #ddd6fe; color: #4c1d95; }
      .coupon-automation-notifications .ca-note--red { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
      .coupon-automation-notifications .ca-note--yellow { background: #fffbeb; border-color: #fde68a; color: #78350f; }
      .coupon-automation-notifications .ca-muted { color: #6b7280; font-size: 0.75rem; }
      .coupon-automation-notifications .ca-hidden { display: none; }
    </style>
    <?php
}

if (!function_exists('coupon_automation_render_notification_row')) {
    function coupon_automation_render_notification_row(array $notification, string $noteClass, string $iconClass, string $iconMarkup, string $message)
    {
        $time = isset($notification['time']) ? $notification['time'] : current_time('mysql');
        $timeAgo = human_time_diff(strtotime($time), current_time('timestamp')) . ' ' . __('ago', 'coupon-automation');
        ?>
        <div class="<?php echo esc_attr($noteClass); ?>">
          <svg class="<?php echo esc_attr($iconClass); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?php echo $iconMarkup; ?></svg>
          <div style="flex:1;">
            <p style="margin:0;font-size:.875rem;color:#111827;"><?php echo $message; ?></p>
            <p class="ca-muted" style="margin:.25rem 0 0;font-size:.75rem;"><?php echo esc_html($timeAgo); ?></p>
          </div>
          <?php if(isset($notification['type'], $notification['data']['id']) && $notification['type'] === 'coupon'): ?>
            <a href="<?php echo esc_url(get_edit_post_link($notification['data']['id'])); ?>" class="ca-btn ca-btn-primary" style="margin-left:.75rem;padding:.25rem .5rem;">
              <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
            </a>
          <?php elseif (isset($notification['type'], $notification['data']['term_id']) && $notification['type'] === 'brand'): ?>
            <?php $brand_edit_link = get_edit_term_link((int) $notification['data']['term_id'], 'brands'); ?>
            <?php if ($brand_edit_link): ?>
              <a href="<?php echo esc_url($brand_edit_link); ?>" class="ca-btn ca-btn-primary" style="margin-left:.75rem;padding:.25rem .5rem;">
                <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
              </a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('coupon_automation_render_notification_item')) {
    function coupon_automation_render_notification_item(array $notification)
    {
    $type = $notification['type'] ?? 'info';
    $data = $notification['data'] ?? [];

    switch ($type) {
        case 'coupon':
            $noteClass = 'ca-note ca-note--green';
            $iconClass = 'ca-icon ca-icon--green';
            $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path>';
            $message = sprintf(
                __('New coupon created: <strong>%s</strong> for %s', 'coupon-automation'),
                esc_html($data['title'] ?? ''),
                esc_html($data['brand'] ?? '')
            );
            break;
        case 'brand':
            $noteClass = 'ca-note ca-note--purple';
            $iconClass = 'ca-icon ca-icon--purple';
            $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>';
            $message = sprintf(
                __('New brand created: <strong>%s</strong>', 'coupon-automation'),
                esc_html($data['name'] ?? '')
            );
            break;
        case 'error':
            $noteClass = 'ca-note ca-note--red';
            $iconClass = 'ca-icon ca-icon--red';
            $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
            $message = sprintf(
                __('Error: %s', 'coupon-automation'),
                esc_html($data['message'] ?? '')
            );
            break;
        case 'sync':
            $noteClass = 'ca-note ca-note--yellow';
            $iconClass = 'ca-icon ca-icon--yellow';
            $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>';
            $message = esc_html($data['message'] ?? __('Sync operation completed', 'coupon-automation'));
            break;
        default:
            $noteClass = 'ca-note ca-note--blue';
            $iconClass = 'ca-icon ca-icon--blue';
            $icon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
            $message = esc_html($data['message'] ?? __('System notice', 'coupon-automation'));
            break;
    }

        coupon_automation_render_notification_row($notification, $noteClass, $iconClass, $icon, $message);
    }
}
?>
<div id="notifications-container" class="notice notice-info is-dismissible coupon-automation-notifications">
  <div class="ca-section-body">
    <div class="ca-notice-header">
      <h3 style="display:flex;align-items:center;gap:.5rem;margin:0;font-weight:600;">
        <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
        <?php esc_html_e('Coupon Automation Notifications', 'coupon-automation'); ?>
        <?php if (!empty($unreadNotifications)): ?>
          <span class="ca-badge"><?php echo intval(count($unreadNotifications)); ?> <?php esc_html_e('new', 'coupon-automation'); ?></span>
        <?php endif; ?>
      </h3>
      <button id="ca-clear-notifications" class="ca-btn ca-btn-secondary" style="padding:.25rem .5rem;">
        <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        <?php esc_html_e('Clear', 'coupon-automation'); ?>
      </button>
    </div>

    <div style="max-height:24rem; overflow-y:auto;">
      <?php foreach ($initialNotifications as $notification): ?>
          <?php coupon_automation_render_notification_item($notification); ?>
      <?php endforeach; ?>
      <?php if (!empty($remainingNotifications)): ?>
        <div id="ca-notifications-expanded" class="ca-hidden">
          <?php foreach ($remainingNotifications as $notification): ?>
              <?php coupon_automation_render_notification_item($notification); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($remainingNotifications)): ?>
      <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid #e5e7eb;">
        <p class="ca-muted" style="font-size:.875rem;">
          <?php
            printf(
                esc_html__('Showing %1$d of %2$d notifications', 'coupon-automation'),
                count($initialNotifications),
                count($allNotifications)
            );
          ?>
          <a href="#" id="ca-view-all-notifications" style="color:#2563eb;margin-left:.5rem;"><?php esc_html_e('View all', 'coupon-automation'); ?></a>
        </p>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
if (!defined('COUPON_AUTOMATION_NOTICES_SCRIPTED')) {
    define('COUPON_AUTOMATION_NOTICES_SCRIPTED', true);
    ?>
    <script>
    jQuery(function($){
      $('#ca-clear-notifications').on('click', function(e){
        e.preventDefault();
        $.post(ajaxurl, {
          action: 'clear_notifications',
          nonce: '<?php echo wp_create_nonce('coupon_automation_nonce'); ?>'
        }).always(function(){
          $('#notifications-container').fadeOut();
        });
      });

      $('#ca-view-all-notifications').on('click', function(e){
        e.preventDefault();
        var $expanded = $('#ca-notifications-expanded');
        $expanded.toggleClass('ca-hidden');
        $(this).text($expanded.hasClass('ca-hidden')
          ? '<?php echo esc_js(__('View all', 'coupon-automation')); ?>'
          : '<?php echo esc_js(__('Hide full list', 'coupon-automation')); ?>'
        );
      });
    });
    </script>
    <?php
}
