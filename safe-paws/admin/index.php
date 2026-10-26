<?php
require __DIR__ . '/../includes/header.php';
require_role('admin');
$pdo = db();
$counts = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'pets' => (int) $pdo->query('SELECT COUNT(*) FROM pets WHERE status="available"')->fetchColumn(),
    'adopted' => (int) $pdo->query('SELECT COUNT(*) FROM pets WHERE status="adopted"')->fetchColumn(),
    'dogs' => (int) $pdo->query('SELECT COUNT(*) FROM pets WHERE species="dog"')->fetchColumn(),
    'cats' => (int) $pdo->query('SELECT COUNT(*) FROM pets WHERE species="cat"')->fetchColumn(),
    'applications' => (int) $pdo->query('SELECT COUNT(*) FROM adoption_applications WHERE status IN ("submitted","under_review")')->fetchColumn(),
    'reports' => (int) $pdo->query('SELECT COUNT(*) FROM stray_reports WHERE status IN ("new","reviewed","assigned","in_progress")')->fetchColumn(),
    'admin_requests' => (int) $pdo->query('SELECT COUNT(*) FROM admin_requests WHERE status="pending"')->fetchColumn(),
];
$recent = $pdo->query('SELECT r.*,COALESCE(u.name,r.reporter_name) reporter FROM stray_reports r LEFT JOIN users u ON u.id=r.reporter_id ORDER BY r.created_at DESC LIMIT 8')->fetchAll();
$activities = $pdo->query(
    'SELECT a.action,a.entity_type,a.created_at,u.name AS actor_name FROM activity_logs a
     LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 8'
)->fetchAll();
?>
<div class="section-head"><div><h1>Admin dashboard</h1><p class="muted">Keep the Safe PAWS network moving.</p></div></div>
<div class="stats">
  <div class="stat"><strong><?= $counts['users'] ?></strong><span>Registered users</span></div>
  <div class="stat"><strong><?= $counts['pets'] ?></strong><span>Available pets</span></div>
  <div class="stat"><strong><?= $counts['adopted'] ?></strong><span>Adopted pets</span></div>
  <div class="stat"><strong><?= $counts['dogs'] ?></strong><span>Dogs</span></div>
  <div class="stat"><strong><?= $counts['cats'] ?></strong><span>Cats</span></div>
  <div class="stat"><strong><?= $counts['applications'] ?></strong><span>Pending applications</span></div>
  <div class="stat"><strong><?= $counts['reports'] ?></strong><span>Open stray reports</span></div>
  <div class="stat"><strong><?= $counts['admin_requests'] ?></strong><span>Admin requests</span></div>
</div>
<div class="actions">
  <a class="btn" href="reports.php">Manage reports</a>
  <a class="btn btn-outline" href="applications.php">Applications</a>
  <a class="btn btn-outline" href="create_admin.php">Create Admin Account</a>
  <a class="btn btn-outline" href="admin_requests.php">Admin Registration Requests</a>
  <a class="btn btn-outline" href="pets.php">Manage pets</a>
  <a class="btn btn-outline" href="users.php">Manage users</a>
  <a class="btn btn-outline" href="shelters.php">Manage shelters</a>
  <a class="btn btn-outline" href="volunteers.php">Volunteers</a>
  <a class="btn btn-outline" href="support.php">Support</a>
  <a class="btn btn-outline" href="<?= e(base_url('user/notifications.php')) ?>">Notifications</a>
</div>
<div class="panel"><div class="section-head"><h2>Recent reports</h2></div><div class="table-wrap"><table class="table"><thead><tr><th>Location</th><th>Reporter</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach ($recent as $report): ?><tr><td><?= e($report['location']) ?></td><td><?= e($report['reporter']) ?></td><td><span class="badge <?= e($report['status']) ?>"><?= e($report['status']) ?></span></td><td><?= e(date('M j, Y', strtotime($report['created_at']))) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="panel content-section"><div class="section-head"><h2>Recent activity</h2></div><?php if (!$activities): ?><p class="empty">No activity has been recorded yet.</p><?php else: ?><div class="table-wrap"><table class="table"><thead><tr><th>Activity</th><th>By</th><th>Date</th></tr></thead><tbody><?php foreach ($activities as $activity): ?><tr><td><?= e(str_replace('_', ' ', $activity['action'])) ?> · <?= e(str_replace('_', ' ', $activity['entity_type'])) ?></td><td><?= e($activity['actor_name'] ?? 'System') ?></td><td><?= e(date('M j, Y H:i', strtotime($activity['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
