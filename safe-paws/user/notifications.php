<?php
require __DIR__ . '/../includes/header.php';
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
    $filter = post('filter', 'all');
    if (!in_array($filter, ['all', 'unread', 'read'], true)) {
        http_response_code(400);
        exit('Choose a valid notification filter.');
    }

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');
        $stmt->execute([$user['id']]);
        flash($stmt->rowCount() > 0 ? 'All notifications marked as read.' : 'There are no unread notifications.');
    } elseif (in_array($action, ['mark_read', 'delete'], true) && $notificationId && $notificationId > 0) {
        if ($action === 'mark_read') {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=? AND is_read=0');
            $stmt->execute([$notificationId, $user['id']]);
            if ($stmt->rowCount() > 0) {
                flash('Notification marked as read.');
            } else {
                $stmt = $pdo->prepare('SELECT 1 FROM notifications WHERE id=? AND user_id=?');
                $stmt->execute([$notificationId, $user['id']]);
                $exists = (bool) $stmt->fetchColumn();
                flash($exists ? 'This notification is already marked as read.' : 'Notification not found.', $exists ? 'success' : 'error');
            }
        } else {
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE id=? AND user_id=?');
            $stmt->execute([$notificationId, $user['id']]);
            $deleted = $stmt->rowCount() > 0;
            flash($deleted ? 'Notification deleted.' : 'Notification not found.', $deleted ? 'success' : 'error');
        }
    } else {
        http_response_code(400);
        exit('Choose a valid notification action.');
    }
    redirect('user/notifications.php?filter=' . rawurlencode($filter));
}

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    http_response_code(400);
    exit('Choose a valid notification filter.');
}

$counts = $pdo->prepare(
    'SELECT COUNT(*) AS total,COALESCE(SUM(is_read=0),0) AS unread
     FROM notifications WHERE user_id=?'
);
$counts->execute([$user['id']]);
$summary = $counts->fetch();
$unreadCount = (int) $summary['unread'];
$readCount = (int) $summary['total'] - $unreadCount;

$sql = 'SELECT id,title,message,link,is_read,created_at FROM notifications WHERE user_id=?';
if ($filter === 'unread') {
    $sql .= ' AND is_read=0';
} elseif ($filter === 'read') {
    $sql .= ' AND is_read=1';
}
$sql .= ' ORDER BY created_at DESC,id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();
$filters = [
    'all' => ['All', (int) $summary['total']],
    'unread' => ['Unread', $unreadCount],
    'read' => ['Read', $readCount],
];
?>
<section class="notifications-page">
  <div class="section-head notification-heading">
    <div>
      <p class="eyebrow">Your updates</p>
      <h1>Notifications</h1>
      <p class="muted"><?= $unreadCount ?> unread <?= $unreadCount === 1 ? 'notification' : 'notifications' ?></p>
    </div>
    <?php if ($unreadCount > 0): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_all_read">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <button class="btn btn-outline" type="submit">Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>

  <nav class="notification-filters" aria-label="Filter notifications">
    <?php foreach ($filters as $key => [$label, $count]): ?>
      <a href="<?= e(base_url('user/notifications.php?filter=' . $key)) ?>"
         <?= $filter === $key ? 'aria-current="page"' : '' ?>>
        <?= e($label) ?><span><?= $count ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$notifications): ?>
    <div class="panel empty notification-empty">
      <span class="notification-empty-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"></path></svg>
      </span>
      <h2><?= $filter === 'unread' ? 'You are all caught up' : ($filter === 'read' ? 'No read notifications yet' : 'No notifications yet') ?></h2>
      <p><?= $filter === 'unread' ? 'New updates will appear here when there is activity.' : 'System updates and important messages will appear here.' ?></p>
    </div>
  <?php else: ?>
    <div class="notification-list">
      <?php foreach ($notifications as $notification): ?>
        <article class="panel notification-card <?= $notification['is_read'] ? 'is-read' : 'is-unread' ?>">
          <span class="notification-card-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"></path></svg>
          </span>
          <div class="notification-card-content">
            <div class="notification-card-heading">
              <h2><?= e($notification['title']) ?></h2>
              <?php if (!$notification['is_read']): ?><span class="badge new">Unread</span><?php else: ?><span class="notification-read-label">Read</span><?php endif; ?>
            </div>
            <p><?= nl2br(e($notification['message'])) ?></p>
            <div class="notification-card-footer">
              <time datetime="<?= e(date(DATE_ATOM, strtotime($notification['created_at']))) ?>">
                <?= e(date('M j, Y \a\t g:i A', strtotime($notification['created_at']))) ?>
              </time>
              <span>Notification #<?= (int) $notification['id'] ?></span>
              <?php if ($notification['link']): ?><a href="<?= e(notification_url($notification['link'])) ?>">View update <span aria-hidden="true">&rarr;</span></a><?php endif; ?>
            </div>
          </div>
          <div class="notification-card-actions">
            <?php if (!$notification['is_read']): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_read">
                <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                <button class="btn btn-outline btn-small" type="submit">Mark as read</button>
              </form>
            <?php endif; ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
              <input type="hidden" name="filter" value="<?= e($filter) ?>">
              <button class="btn btn-quiet btn-small" type="submit" data-confirm="Delete this notification?">Delete</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
