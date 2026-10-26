<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');
$statuses = ['open', 'in_progress', 'resolved', 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $status = post('status');
    $notes = post('staff_notes');
    if (!in_array($status, $statuses, true) || strlen($notes) > 5000) {
        flash('Choose a valid status and keep notes under 5,000 characters.', 'error');
        redirect('admin/support.php');
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT user_id,status FROM support_records WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $owner = $stmt->fetch();
        if (!$owner) {
            throw new RuntimeException('Support request not found.');
        }
        $update = $pdo->prepare(
            'UPDATE support_records SET status=?,staff_notes=?,resolved_at=IF(? IN ("resolved","closed"),CURRENT_TIMESTAMP,NULL) WHERE id=?'
        );
        $update->execute([$status, $notes ?: null, $status, $id]);
        $activityId = activity_log((int) $admin['id'], 'updated', 'support_record', $id, ['status' => $status]);
        if ($owner['status'] !== $status) {
            notify(
                (int) $owner['user_id'],
                'Support request updated',
                'Your support request is now ' . str_replace('_', ' ', $status) . '.',
                'user/support.php',
                'support_record',
                $id,
                'support_record:status:' . $activityId
            );
        }
        $pdo->commit();
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($exception->getMessage(), 'error');
        redirect('admin/support.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Safe PAWS support record update failed: ' . $exception->getMessage());
        flash('Could not update this support request.', 'error');
        redirect('admin/support.php');
    }
    flash('Support request updated.');
    redirect('admin/support.php');
}

$records = db()->query(
    'SELECT r.*,u.name AS user_name,u.email FROM support_records r
     JOIN users u ON u.id=r.user_id ORDER BY r.submitted_at DESC'
)->fetchAll();
?>
<div class="section-head"><div><h1>Support records</h1><p class="muted">Review and update requests from the community.</p></div></div>
<?php if (!$records): ?><div class="panel empty">No support requests yet.</div><?php else: ?>
  <div class="panel table-wrap"><table class="table"><thead><tr><th>Submitted by</th><th>Request</th><th>Status</th><th>Update</th></tr></thead><tbody>
  <?php foreach ($records as $record): ?><tr><td><?= e($record['user_name']) ?><br><a href="mailto:<?= e($record['email']) ?>"><?= e($record['email']) ?></a></td><td><strong><?= e($record['request_type']) ?></strong><br><?= nl2br(e($record['description'])) ?></td><td><span class="badge <?= e($record['status']) ?>"><?= e(str_replace('_', ' ', $record['status'])) ?></span></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $record['id'] ?>"><div class="field"><label for="support-status-<?= (int) $record['id'] ?>">Status</label><select id="support-status-<?= (int) $record['id'] ?>" name="status"><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $record['status'] === $status ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select></div><div class="field"><label for="support-note-<?= (int) $record['id'] ?>">Note for user</label><textarea id="support-note-<?= (int) $record['id'] ?>" name="staff_notes" maxlength="5000"><?= e($record['staff_notes']) ?></textarea></div><button class="btn btn-small" type="submit">Save</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
