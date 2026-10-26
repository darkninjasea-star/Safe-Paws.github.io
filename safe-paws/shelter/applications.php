<?php
require __DIR__ . '/../includes/header.php';
$user = require_role('shelter');
$stmt = db()->prepare('SELECT id FROM shelters WHERE user_id=?');
$stmt->execute([$user['id']]);
$shelter = $stmt->fetch();
if (!$shelter) {
    flash('Create your shelter profile first.', 'warning');
    redirect('shelter/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        review_adoption_application(
            (int) ($_POST['id'] ?? 0),
            (int) $user['id'],
            post('status'),
            post('shelter_notes'),
            (int) $user['id']
        );
        flash('Application updated.');
    } catch (InvalidArgumentException | RuntimeException $exception) {
        flash($exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        error_log('Safe PAWS shelter application review failed: ' . $exception->getMessage());
        flash('Could not update the application.', 'error');
    }
    redirect('shelter/applications.php');
}

$stmt = db()->prepare(
    'SELECT a.*,p.name AS pet_name,u.name AS applicant_name,u.email
     FROM adoption_applications a
     JOIN pets p ON p.id=a.pet_id
     JOIN users u ON u.id=a.applicant_id
     WHERE p.shelter_id=? ORDER BY a.created_at DESC'
);
$stmt->execute([$shelter['id']]);
$applications = $stmt->fetchAll();
?>
<div class="section-head"><h1>Adoption applications</h1></div>
<?php if (!$applications): ?>
  <div class="panel empty">No applications yet.</div>
<?php else: ?>
  <div class="panel table-wrap"><table class="table">
    <thead><tr><th>Pet / applicant</th><th>Application details</th><th>Status</th><th>Decision</th></tr></thead><tbody>
    <?php foreach ($applications as $application): ?>
      <tr>
        <td><strong><?= e($application['pet_name']) ?></strong><br><?= e($application['applicant_name']) ?><br><a href="mailto:<?= e($application['email']) ?>"><?= e($application['email']) ?></a></td>
        <td><?= nl2br(e($application['message'])) ?><br><span class="small muted"><?= e($application['phone']) ?> · <?= e($application['address']) ?></span><br><span class="small muted">Home: <?= e($application['household_type']) ?> · Experience: <?= e($application['experience']) ?> · Other pets: <?= e($application['other_pets']) ?></span></td>
        <td><span class="badge <?= e($application['status']) ?>"><?= e(str_replace('_', ' ', $application['status'])) ?></span></td>
        <td>
          <?php if (in_array($application['status'], ['submitted', 'under_review'], true)): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $application['id'] ?>">
              <div class="field"><label for="status-<?= (int) $application['id'] ?>">Status</label><select id="status-<?= (int) $application['id'] ?>" name="status"><option value="under_review">Under review</option><option value="approved">Approve</option><option value="rejected">Reject</option></select></div>
              <div class="field"><label for="notes-<?= (int) $application['id'] ?>">Note for applicant</label><textarea id="notes-<?= (int) $application['id'] ?>" name="shelter_notes" maxlength="5000"><?= e($application['shelter_notes']) ?></textarea></div>
              <button class="btn btn-small" type="submit">Save decision</button>
            </form>
          <?php else: ?><span class="small muted">Final decision recorded</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
