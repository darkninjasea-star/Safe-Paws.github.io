<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        review_adoption_application(
            (int) ($_POST['application_id'] ?? 0),
            (int) $admin['id'],
            post('status'),
            post('notes')
        );
        flash('Application updated.');
    } catch (InvalidArgumentException | RuntimeException $exception) {
        flash($exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        error_log('Safe PAWS admin application review failed: ' . $exception->getMessage());
        flash('Could not update the application.', 'error');
    }
    redirect('admin/applications.php');
}

$statusFilter = (string) ($_GET['status'] ?? '');
$validStatuses = ['submitted', 'under_review', 'approved', 'rejected', 'withdrawn'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}
$petFilter = max(0, (int) ($_GET['pet'] ?? 0));
$fromDate = (string) ($_GET['from'] ?? '');
$toDate = (string) ($_GET['to'] ?? '');
$validDate = static function (string $value): bool {
    return $value === '' || (
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
        && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
    );
};
if (!$validDate($fromDate) || !$validDate($toDate)) {
    flash('Choose valid dates for the application filter.', 'error');
    redirect('admin/applications.php');
}
$sql = 'SELECT a.*,p.name AS pet_name,u.name AS applicant_name,u.email AS applicant_email
        FROM adoption_applications a
        JOIN pets p ON p.id=a.pet_id
        JOIN users u ON u.id=a.applicant_id';
$params = [];
$where = [];
if ($statusFilter !== '') {
    $where[] = 'a.status=?';
    $params[] = $statusFilter;
}
if ($petFilter > 0) {
    $where[] = 'a.pet_id=?';
    $params[] = $petFilter;
}
if ($fromDate !== '') {
    $where[] = 'a.created_at>=?';
    $params[] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $where[] = 'a.created_at<?';
    $params[] = date('Y-m-d', strtotime($toDate . ' +1 day')) . ' 00:00:00';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY a.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();
$pets = db()->query('SELECT id,name FROM pets ORDER BY name')->fetchAll();
?>
<div class="section-head"><div><h1>Adoption applications</h1><p class="muted">Review applications and make consistent pet-status decisions.</p></div></div>
<form class="filters" method="get">
  <div class="field"><label for="status">Application status</label><select id="status" name="status"><option value="">All statuses</option><?php foreach ($validStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="pet">Pet</label><select id="pet" name="pet"><option value="0">All pets</option><?php foreach ($pets as $pet): ?><option value="<?= (int) $pet['id'] ?>" <?= $petFilter === (int) $pet['id'] ? 'selected' : '' ?>><?= e($pet['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($fromDate) ?>"></div>
  <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($toDate) ?>"></div>
  <button class="btn" type="submit">Filter</button>
</form>
<?php if (!$applications): ?>
  <div class="panel empty">No applications match this filter.</div>
<?php else: ?>
  <div class="panel table-wrap"><table class="table">
    <thead><tr><th>Pet / applicant</th><th>Application</th><th>Status</th><th>Review</th></tr></thead><tbody>
    <?php foreach ($applications as $application): ?>
      <tr>
        <td><strong><?= e($application['pet_name']) ?></strong><br><?= e($application['applicant_name']) ?><br><a href="mailto:<?= e($application['applicant_email']) ?>"><?= e($application['applicant_email']) ?></a></td>
        <td><?= nl2br(e($application['message'])) ?><br><span class="small muted"><?= e($application['phone']) ?> · <?= e($application['address']) ?></span></td>
        <td><span class="badge <?= e($application['status']) ?>"><?= e(str_replace('_', ' ', $application['status'])) ?></span></td>
        <td>
          <?php if (in_array($application['status'], ['submitted', 'under_review'], true)): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
              <div class="field"><label for="status-<?= (int) $application['id'] ?>">Decision</label><select id="status-<?= (int) $application['id'] ?>" name="status"><option value="under_review">Under review</option><option value="approved">Approve</option><option value="rejected">Reject</option></select></div>
              <div class="field"><label for="notes-<?= (int) $application['id'] ?>">Note to applicant</label><textarea id="notes-<?= (int) $application['id'] ?>" name="notes" maxlength="5000"><?= e($application['shelter_notes']) ?></textarea></div>
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
