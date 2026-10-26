<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $userId = (int) ($_POST['user_id'] ?? 0);
    $status = post('status');
    $skills = post('skills');
    $availability = post('availability');
    if (!in_array($status, ['active', 'inactive'], true)
        || strlen($skills) > 2000 || strlen($availability) > 500) {
        flash('Check the volunteer profile values and try again.', 'error');
        redirect('admin/volunteers.php');
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? AND role="volunteer"');
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        flash('Volunteer not found.', 'error');
        redirect('admin/volunteers.php');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO volunteers (user_id,skills,availability,status) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE skills=VALUES(skills),availability=VALUES(availability),status=VALUES(status)'
        );
        $stmt->execute([$userId, $skills ?: null, $availability ?: null, $status]);
        $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status, $userId]);
        $activityId = activity_log((int) $admin['id'], 'updated', 'volunteer', $userId, ['status' => $status]);
        notify(
            $userId,
            'Volunteer profile updated',
            'Your volunteer profile has been updated.',
            'volunteer/index.php',
            'user',
            $userId,
            'volunteer:updated:' . $activityId
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Safe PAWS volunteer profile update failed: ' . $exception->getMessage());
        flash('Could not update this volunteer.', 'error');
        redirect('admin/volunteers.php');
    }
    flash('Volunteer profile saved.');
    redirect('admin/volunteers.php');
}

$volunteers = $pdo->query(
    'SELECT u.id,u.name,u.email,u.status AS account_status,v.skills,v.availability,v.status AS volunteer_status
     FROM users u LEFT JOIN volunteers v ON v.user_id=u.id
     WHERE u.role="volunteer" ORDER BY u.name'
)->fetchAll();
?>
<div class="section-head"><div><h1>Volunteer management</h1><p class="muted">Promote users from User management, then maintain volunteer skills and availability here.</p></div></div>
<?php if (!$volunteers): ?><div class="panel empty">No volunteer accounts yet. Change a user's role to Volunteer to add them.</div><?php else: ?>
  <div class="grid">
    <?php foreach ($volunteers as $volunteer): ?>
      <article class="panel">
        <h2><?= e($volunteer['name']) ?></h2><p><a href="mailto:<?= e($volunteer['email']) ?>"><?= e($volunteer['email']) ?></a></p>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $volunteer['id'] ?>">
          <div class="field"><label for="skills-<?= (int) $volunteer['id'] ?>">Skills</label><textarea id="skills-<?= (int) $volunteer['id'] ?>" name="skills" maxlength="2000"><?= e($volunteer['skills']) ?></textarea></div>
          <div class="field"><label for="availability-<?= (int) $volunteer['id'] ?>">Availability</label><input id="availability-<?= (int) $volunteer['id'] ?>" name="availability" maxlength="500" value="<?= e($volunteer['availability']) ?>"></div>
          <div class="field"><label for="volunteer-status-<?= (int) $volunteer['id'] ?>">Status</label><select id="volunteer-status-<?= (int) $volunteer['id'] ?>" name="status"><option value="active" <?= $volunteer['account_status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $volunteer['account_status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
          <button class="btn btn-small" type="submit">Save profile</button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
