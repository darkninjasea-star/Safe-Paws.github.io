<?php
$navUser = current_user();
$recentNotifications = $navUser ? recent_notifications((int) $navUser['id'], 5) : [];
?>
<header class="site-header"><div class="container nav-wrap">
  <a class="brand" href="<?= e(base_url('index.php')) ?>">
    <svg class="brand-mark" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
      <g fill="currentColor"><ellipse cx="13" cy="14" rx="5" ry="7" transform="rotate(-25 13 14)"/><ellipse cx="23" cy="9" rx="5" ry="7" transform="rotate(-8 23 9)"/><ellipse cx="34" cy="13" rx="5" ry="7" transform="rotate(22 34 13)"/><ellipse cx="40" cy="23" rx="5" ry="7" transform="rotate(45 40 23)"/><path d="M24 19c-9 0-17 7-17 16 0 5 4 8 9 8 3 0 6-2 8-4 2 2 5 4 8 4 5 0 9-3 9-8 0-9-8-16-17-16Z"/></g>
    </svg>
    Safe <b>PAWS</b>
  </a>
  <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="primary-navigation"><span aria-hidden="true">☰</span></button>
  <nav class="nav-links" id="primary-navigation">
    <a href="<?= e(base_url('pets.php')) ?>">Find a Pet</a>
    <a href="<?= e(base_url('shelters.php')) ?>">Shelters</a>
    <a href="<?= e(base_url('report_stray.php')) ?>">Report a Stray</a>
    <?php if ($navUser): ?>
      <a href="<?= e(base_url(dashboard_url($navUser))) ?>">Dashboard</a>
      <?php if ($navUser['role'] === 'user'): ?><a href="<?= e(base_url('user/reports.php')) ?>">My reports</a><a href="<?= e(base_url('user/support.php')) ?>">Support</a><?php endif; ?>
      <details class="notification-menu">
        <summary aria-label="<?= $unreadNotificationCount > 0 ? $unreadNotificationCount . ' unread notifications' : 'Notifications' ?>" title="Notifications">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"></path></svg>
          <span class="notification-menu-label">Notifications</span>
          <?php if ($unreadNotificationCount > 0): ?><span class="notification-count"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?>
        </summary>
        <div class="notification-dropdown">
          <div class="notification-dropdown-heading"><strong>Notifications</strong><span><?= $unreadNotificationCount ?> unread</span></div>
          <?php if (!$recentNotifications): ?>
            <p class="notification-dropdown-empty">You are all caught up.</p>
          <?php else: ?>
            <div class="notification-preview-list">
              <?php foreach ($recentNotifications as $notification): ?>
                <a class="notification-preview <?= $notification['is_read'] ? 'is-read' : 'is-unread' ?>" href="<?= e(notification_url($notification['link'])) ?>">
                  <span class="notification-preview-dot" aria-hidden="true"></span>
                  <span><strong><?= e($notification['title']) ?></strong><small><?= e(date('M j, g:i A', strtotime($notification['created_at']))) ?></small></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <a class="notification-view-all" href="<?= e(base_url('user/notifications.php')) ?>">View all notifications</a>
        </div>
      </details>
      <form method="post" action="<?= e(base_url('logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">Log out</button></form>
    <?php else: ?><a href="<?= e(base_url('login.php')) ?>">Log in</a><a class="btn btn-small" href="<?= e(base_url('register.php')) ?>">Join us</a><?php endif; ?>
    <button class="theme-toggle" type="button" data-theme-toggle aria-label="Change color theme" title="Color theme">
      <svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg>
      <svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.5 15.5A8.5 8.5 0 0 1 8.5 3.5 8.5 8.5 0 1 0 20.5 15.5Z"></path></svg>
      <span data-theme-label>Light Mode</span>
    </button>
  </nav>
</div></header>
