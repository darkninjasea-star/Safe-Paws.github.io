<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = post('decision');
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        flash('Choose approve or reject.', 'error');
        redirect('admin/admin_requests.php');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT r.id,r.user_id,r.status,u.name,u.role,u.status AS account_status
             FROM admin_requests r JOIN users u ON u.id=r.user_id WHERE r.id=? FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        $requestRow = $stmt->fetch();
        if (!$requestRow || $requestRow['status'] !== 'pending') {
            throw new RuntimeException('This administrator request has already been reviewed.');
        }
        if ((int) $requestRow['user_id'] === (int) $admin['id']) {
            throw new RuntimeException('You cannot approve your own administrator request.');
        }
        $isPendingAdminAccount = $requestRow['account_status'] === 'pending_admin_approval'
            && $requestRow['role'] === null;
        $isExistingAdopter = $requestRow['account_status'] === 'active'
            && $requestRow['role'] === 'user';
        if (!$isPendingAdminAccount && !$isExistingAdopter) {
            throw new RuntimeException('This account is no longer eligible for administrator request review.');
        }

        $pdo->prepare('UPDATE admin_requests SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$decision, $admin['id'], $requestId]);
        if ($decision === 'approved') {
            $pdo->prepare(
                'UPDATE users SET role="admin",status="active" WHERE id=?'
            )->execute([$requestRow['user_id']]);
            sync_user_role_assignment($pdo, (int) $requestRow['user_id'], 'admin', (int) $admin['id']);
        } elseif ($isPendingAdminAccount) {
            $pdo->prepare(
                'UPDATE users SET role=NULL,status="inactive" WHERE id=? AND role IS NULL AND status="pending_admin_approval"'
            )->execute([$requestRow['user_id']]);
        }
        notify((int) $requestRow['user_id'], 'Administrator access request ' . $decision,
            $decision === 'approved'
                ? 'Your administrator access has been approved. You may now sign in.'
                : 'Your administrator access request was not approved.',
            $decision === 'approved' ? 'admin/index.php' : 'login.php',
            'admin_request',
            $requestId,
            'admin_request:decision:' . $requestId);
        notify_admins(
            'admin_request:decision:' . $requestId,
            'Admin request ' . $decision,
            'An administrator registration request was ' . $decision . '.',
            'admin/admin_requests.php',
            'admin_request',
            $requestId
        );
        activity_log((int) $admin['id'], $decision, 'admin_request', $requestId);
        $pdo->commit();
        flash('Administrator request ' . $decision . '.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            flash($exception->getMessage(), 'error');
        } else {
            error_log('Safe PAWS administrator request review failed: ' . $exception->getMessage());
            flash('Could not update the administrator request.', 'error');
        }
    }
    redirect('admin/admin_requests.php');
}

$query = trim((string) ($_GET['q'] ?? ''));
$stmt = db()->prepare(
    'SELECT r.id,r.user_id,r.full_name,r.email,r.purpose,r.status,r.requested_at,
            u.role,u.status AS account_status
     FROM admin_requests r JOIN users u ON u.id=r.user_id
     WHERE r.full_name LIKE ? OR r.email LIKE ? OR r.purpose LIKE ?
     ORDER BY r.requested_at DESC'
);
$like = '%' . $query . '%';
$stmt->execute([$like, $like, $like]);
$requests = $stmt->fetchAll();
?>
<div class="section-head"><div><h1>Admin Registration Requests</h1><p class="muted">Review requests before granting protected access.</p></div></div>
<form class="filters" method="get">
  <div class="field"><label for="request-search">Search requests</label><input id="request-search" name="q" value="<?= e($query) ?>" placeholder="Name, email or purpose"></div>
  <button class="btn" type="submit">Search</button>
  <?php if ($query !== ''): ?><a class="btn btn-outline" href="<?= e(base_url('admin/admin_requests.php')) ?>">Clear</a><?php endif; ?>
</form>
<?php if (!$requests): ?>
  <div class="panel empty"><?= $query === '' ? 'No administrator requests have been submitted.' : 'No administrator requests match your search.' ?></div>
<?php else: ?>
  <div class="panel table-wrap"><table class="table">
    <thead><tr><th>Full Name</th><th>Email</th><th>Role</th><th>Registration Purpose</th><th>Status</th><th>Date Registered</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($requests as $request): ?>
        <tr>
          <td><?= e($request['full_name']) ?></td>
          <td><a href="mailto:<?= e($request['email']) ?>"><?= e($request['email']) ?></a></td>
          <td>Admin</td>
          <td><?= nl2br(e($request['purpose'])) ?></td>
          <td><span class="badge <?= e($request['status']) ?>"><?= e(ucfirst($request['status'])) ?></span></td>
          <td><?= e(date('M j, Y H:i', strtotime($request['requested_at']))) ?></td>
          <td>
            <?php if ($request['status'] === 'pending'): ?>
              <form method="post" class="actions">
                <?= csrf_field() ?>
                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                <button class="btn btn-small" name="decision" value="approved" data-confirm="Approve this administrator request?">Approve</button>
                <button class="btn btn-outline btn-small" name="decision" value="rejected" data-confirm="Reject this administrator request?">Reject</button>
              </form>
            <?php else: ?>
              <span class="small muted">Reviewed</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
