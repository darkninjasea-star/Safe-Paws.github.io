<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id === (int) $admin['id']) {
        flash('You cannot change your own administrator account here.', 'error');
        redirect('admin/users.php');
    }
    $role = post('role');
    $status = post('status');
    if (!in_array($role, ['user', 'volunteer', 'shelter', 'admin'], true)
        || !in_array($status, ['active', 'inactive'], true)) {
        flash('Choose a valid user role and status.', 'error');
        redirect('admin/users.php');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT role,status FROM users WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            throw new RuntimeException('User not found.');
        }
        if ($role === 'admin' && $existing['role'] !== 'admin') {
            throw new RuntimeException('New administrator accounts must be approved through administrator requests.');
        }
        if ($existing['status'] === 'pending_admin_approval') {
            throw new RuntimeException('Review pending administrator accounts from the administrator requests page.');
        }

        $pdo->prepare('UPDATE users SET role=?,status=? WHERE id=?')->execute([$role, $status, $id]);
        sync_user_role_assignment($pdo, $id, $role, (int) $admin['id']);
        if ($role === 'volunteer') {
            $pdo->prepare(
                'INSERT INTO volunteers (user_id,status) VALUES (?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)'
            )->execute([$id, $status]);
        } else {
            $pdo->prepare('DELETE FROM volunteers WHERE user_id=?')->execute([$id]);
        }
        $activityId = activity_log((int) $admin['id'], 'updated', 'user', $id, ['role' => $role, 'status' => $status]);
        notify(
            $id,
            'Account updated',
            'Your account role or status has been updated.',
            'user/index.php',
            'user',
            $id,
            'user:updated:' . $activityId
        );
        if ($existing['role'] !== 'volunteer' && $role === 'volunteer' && $status === 'active') {
            notify_admins(
                'volunteer:registered:' . $activityId,
                'New volunteer account',
                'A volunteer account is ready to support rescue operations.',
                'admin/volunteers.php',
                'user',
                $id
            );
        }
        $pdo->commit();
        flash('User updated.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            flash($exception->getMessage(), 'error');
        } else {
            error_log('Safe PAWS user update failed: ' . $exception->getMessage());
            flash('Could not update this user.', 'error');
        }
    }
    redirect('admin/users.php');
}

$query = trim((string) ($_GET['q'] ?? ''));
$stmt = $pdo->prepare(
    'SELECT id,name,email,role,status,created_at FROM users
     WHERE name LIKE ? OR email LIKE ? ORDER BY created_at DESC'
);
$like = '%' . $query . '%';
$stmt->execute([$like, $like]);
$users = $stmt->fetchAll();
?>
<div class="section-head"><div><h1>User management</h1><p class="muted">Manage access; administrator privileges require the approval workflow.</p></div></div>
<form class="filters" method="get"><div class="field"><label for="q">Search users</label><input id="q" name="q" value="<?= e($query) ?>" placeholder="Name or email"></div><button class="btn" type="submit">Search</button></form>
<div class="panel table-wrap"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Access</th><th>Status</th><th>Save</th></tr></thead><tbody>
<?php foreach ($users as $user): ?>
  <tr>
    <td><?= e($user['name']) ?><br><span class="small muted"><?= e(date('M j, Y', strtotime($user['created_at']))) ?></span></td>
    <td><?= e($user['email']) ?></td>
    <?php if ((int) $user['id'] === (int) $admin['id'] || $user['status'] === 'pending_admin_approval'): ?>
      <td><?= e($user['role']) ?></td><td><span class="badge <?= e($user['status']) ?>"><?= e(str_replace('_', ' ', $user['status'])) ?></span></td><td><span class="small muted"><?= $user['status'] === 'pending_admin_approval' ? 'Review admin request' : 'Current account' ?></span></td>
    <?php else: ?>
      <td colspan="3"><form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <div class="field"><label for="role-<?= (int) $user['id'] ?>">Role</label><select id="role-<?= (int) $user['id'] ?>" name="role"><?php foreach ($user['role'] === 'admin' ? ['user', 'volunteer', 'shelter', 'admin'] : ['user', 'volunteer', 'shelter'] as $role): ?><option value="<?= e($role) ?>" <?= $user['role'] === $role ? 'selected' : '' ?>><?= e(ucfirst($role)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="status-<?= (int) $user['id'] ?>">Status</label><select id="status-<?= (int) $user['id'] ?>" name="status"><option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
        <button class="btn btn-small" type="submit">Save</button>
      </form></td>
    <?php endif; ?>
  </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
