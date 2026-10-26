<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');
$pdo = db();
$reportStatuses = ['new', 'reviewed', 'assigned', 'in_progress', 'rescued', 'resolved', 'rejected', 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reportId = (int) ($_POST['report_id'] ?? 0);
    if (post('action') === 'status') {
        $status = post('status');
        $notes = post('staff_notes');
        if (!in_array($status, $reportStatuses, true) || strlen($notes) > 5000) {
            flash('Choose a valid report status and keep staff notes under 5,000 characters.', 'error');
            redirect('admin/reports.php');
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT r.id,r.reporter_id,r.status,a.volunteer_id
                 FROM stray_reports r LEFT JOIN rescue_assignments a ON a.report_id=r.id
                 WHERE r.id=? FOR UPDATE'
            );
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();
            if (!$report) {
                throw new RuntimeException('That report could not be found.');
            }
            if ($status === 'rescued') {
                record_rescue_intake($pdo, $reportId);
                $pdo->prepare('UPDATE rescue_assignments SET status="completed" WHERE report_id=?')
                    ->execute([$reportId]);
            }
            $pdo->prepare(
                'UPDATE stray_reports SET status=?,staff_notes=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?'
            )->execute([$status, $notes ?: null, $admin['id'], $reportId]);
            if ($report['status'] !== $status) {
                $pdo->prepare(
                    'INSERT INTO report_status_history (report_id,previous_status,new_status,actor_id,notes)
                     VALUES (?,?,?,?,?)'
                )->execute([$reportId, $report['status'], $status, $admin['id'], $notes ?: null]);
                $historyId = (int) $pdo->lastInsertId();
                $eventKey = 'stray_report:status:' . $historyId;
                if ($report['reporter_id']) {
                    notify(
                        (int) $report['reporter_id'],
                        'Stray report updated',
                        'Your stray animal report is now ' . str_replace('_', ' ', $status) . '.',
                        'user/reports.php',
                        'stray_report',
                        $reportId,
                        $eventKey
                    );
                }
                if ($report['volunteer_id']) {
                    notify(
                        (int) $report['volunteer_id'],
                        'Rescue report updated',
                        'A rescue report assigned to you is now ' . str_replace('_', ' ', $status) . '.',
                        'volunteer/reports.php',
                        'stray_report',
                        $reportId,
                        $eventKey
                    );
                }
            }
            activity_log((int) $admin['id'], 'updated', 'stray_report', $reportId, ['status' => $status]);
            $pdo->commit();
            flash($status === 'rescued' ? 'Report rescued and shelter intake recorded.' : 'Report status updated.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException) {
                error_log('Safe PAWS stray report update failed: ' . $exception->getMessage());
                flash('Could not update this report.', 'error');
            } elseif ($exception instanceof RuntimeException) {
                flash($exception->getMessage(), 'error');
            } else {
                error_log('Safe PAWS stray report update failed: ' . $exception->getMessage());
                flash('Could not update this report.', 'error');
            }
        }
        redirect('admin/reports.php');
    }

    $volunteerId = filter_var(post('volunteer_id'), FILTER_VALIDATE_INT);
    $shelterId = filter_var(post('shelter_id'), FILTER_VALIDATE_INT);
    $assignmentNotes = post('notes');
    if (!$volunteerId || $volunteerId < 1 || !$shelterId || $shelterId < 1 || strlen($assignmentNotes) > 1000) {
        flash('Choose an active volunteer and approved destination shelter, and keep instructions under 1,000 characters.', 'error');
        redirect('admin/reports.php');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id,status,reporter_id FROM stray_reports WHERE id=? FOR UPDATE');
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        if (!$report) {
            throw new RuntimeException('That report could not be found.');
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? AND role="volunteer" AND status="active" FOR UPDATE');
        $stmt->execute([$volunteerId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Choose an active volunteer.');
        }
        $stmt = $pdo->prepare('SELECT id FROM shelters WHERE id=? AND status="approved" FOR UPDATE');
        $stmt->execute([$shelterId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Choose an approved shelter.');
        }
        $stmt = $pdo->prepare('SELECT status,volunteer_id FROM rescue_assignments WHERE report_id=? FOR UPDATE');
        $stmt->execute([$reportId]);
        $existingAssignment = $stmt->fetch();
        $existingAssignmentStatus = $existingAssignment['status'] ?? false;
        $assignmentStatus = $report['status'] === 'rescued'
            || $existingAssignmentStatus === 'completed' ? 'completed' : 'assigned';
        $stmt = $pdo->prepare(
            'INSERT INTO rescue_assignments (report_id,volunteer_id,shelter_id,notes,status)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE volunteer_id=VALUES(volunteer_id),shelter_id=VALUES(shelter_id),
               notes=VALUES(notes),status=VALUES(status)'
        );
        $stmt->execute([$reportId, $volunteerId, $shelterId, $assignmentNotes, $assignmentStatus]);
        $newReportStatus = $report['status'] === 'resolved'
            ? 'resolved'
            : ($assignmentStatus === 'completed' ? 'rescued' : 'assigned');
        $pdo->prepare(
            'UPDATE stray_reports SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([$newReportStatus, $admin['id'], $reportId]);
        if ($newReportStatus === 'rescued' || $assignmentStatus === 'completed') {
            record_rescue_intake($pdo, $reportId);
        }
        $statusHistoryId = null;
        if ($report['status'] !== $newReportStatus) {
            $pdo->prepare(
                'INSERT INTO report_status_history (report_id,previous_status,new_status,actor_id,notes)
                 VALUES (?,?,?,?,?)'
            )->execute([$reportId, $report['status'], $newReportStatus, $admin['id'], $assignmentNotes ?: null]);
            $statusHistoryId = (int) $pdo->lastInsertId();
            $statusEventKey = 'stray_report:status:' . $statusHistoryId;
            if ($report['reporter_id']) {
                notify(
                    (int) $report['reporter_id'],
                    'Stray report updated',
                    'Your stray animal report is now ' . str_replace('_', ' ', $newReportStatus) . '.',
                    'user/reports.php',
                    'stray_report',
                    $reportId,
                    $statusEventKey
                );
            }
            if ($volunteerId) {
                notify(
                    $volunteerId,
                    'Rescue report updated',
                    'A rescue report assigned to you is now ' . str_replace('_', ' ', $newReportStatus) . '.',
                    'volunteer/reports.php',
                    'stray_report',
                    $reportId,
                    $statusEventKey
                );
            }
        }
        $assignmentActivityId = activity_log((int) $admin['id'], 'assigned', 'stray_report', $reportId, [
            'volunteer_id' => $volunteerId,
            'shelter_id' => $shelterId,
        ]);
        if ($assignmentStatus !== 'completed'
            && (!$existingAssignment || (int) $existingAssignment['volunteer_id'] !== $volunteerId)) {
            notify(
                $volunteerId,
                'Rescue assignment',
                'You have been assigned a stray rescue report.',
                'volunteer/reports.php',
                'stray_report',
                $reportId,
                'rescue_assignment:' . $assignmentActivityId
            );
        }
        $pdo->commit();
        flash($assignmentStatus === 'completed'
            ? 'Rescue assignment updated and shelter intake confirmed.'
            : 'Report assigned successfully.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException) {
            error_log('Safe PAWS rescue assignment failed: ' . $exception->getMessage());
            flash('Could not assign this report.', 'error');
        } elseif ($exception instanceof RuntimeException) {
            flash($exception->getMessage(), 'error');
        } else {
            error_log('Safe PAWS rescue assignment failed: ' . $exception->getMessage());
            flash('Could not assign this report.', 'error');
        }
    }
    redirect('admin/reports.php');
}

$statusFilter = (string) ($_GET['status'] ?? '');
$fromDate = (string) ($_GET['from'] ?? '');
$toDate = (string) ($_GET['to'] ?? '');
$dateIsValid = static function (string $value): bool {
    return $value === '' || (
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
        && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
    );
};
if (($statusFilter !== '' && !in_array($statusFilter, $reportStatuses, true))
    || !$dateIsValid($fromDate) || !$dateIsValid($toDate)) {
    flash('Choose a valid status and date range for the report filter.', 'error');
    redirect('admin/reports.php');
}
$reportQuery = 'SELECT r.*,a.volunteer_id,a.shelter_id,a.status AS assignment_status,a.notes AS assignment_notes,
                       v.name AS volunteer_name,s.name AS shelter_name,p.id AS intake_pet_id
                FROM stray_reports r
                LEFT JOIN rescue_assignments a ON a.report_id=r.id
                LEFT JOIN users v ON v.id=a.volunteer_id
                LEFT JOIN shelters s ON s.id=a.shelter_id
                LEFT JOIN pets p ON p.rescue_report_id=r.id';
$conditions = [];
$filterParams = [];
if ($statusFilter !== '') {
    $conditions[] = 'r.status=?';
    $filterParams[] = $statusFilter;
}
if ($fromDate !== '') {
    $conditions[] = 'r.created_at>=?';
    $filterParams[] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $conditions[] = 'r.created_at<?';
    $filterParams[] = date('Y-m-d', strtotime($toDate . ' +1 day')) . ' 00:00:00';
}
if ($conditions) {
    $reportQuery .= ' WHERE ' . implode(' AND ', $conditions);
}
$reportQuery .= ' ORDER BY r.created_at DESC';
$stmt = $pdo->prepare($reportQuery);
$stmt->execute($filterParams);
$reports = $stmt->fetchAll();
$volunteers = $pdo->query('SELECT id,name FROM users WHERE role="volunteer" AND status="active" ORDER BY name')->fetchAll();
$shelters = $pdo->query('SELECT id,name FROM shelters WHERE status="approved" ORDER BY name')->fetchAll();
?>
<div class="section-head"><div><h1>Stray reports</h1><p class="muted">Review incoming sightings and coordinate rescues. Save an active volunteer and approved destination shelter before setting a report to rescued.</p></div></div>
<form class="filters" method="get">
  <div class="field"><label for="status-filter">Status</label><select id="status-filter" name="status"><option value="">All statuses</option><?php foreach ($reportStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($fromDate) ?>"></div>
  <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($toDate) ?>"></div>
  <button class="btn" type="submit">Filter</button><a class="btn btn-outline" href="<?= e(base_url('admin/reports.php')) ?>">Clear</a>
</form>
<?php if (!$reports): ?>
  <div class="panel empty">No reports yet.</div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($reports as $report): ?>
      <article class="panel">
        <span class="badge <?= e($report['status']) ?>"><?= e(str_replace('_', ' ', $report['status'])) ?></span>
        <h2><?= e($report['location']) ?></h2>
        <?php if ($report['image']): ?><img class="card-image" src="<?= e(image_url($report['image'])) ?>" alt="Photo attached to report"><?php endif; ?>
        <p><?= nl2br(e($report['description'])) ?></p>
        <p class="small muted">Reported by <?= e($report['reporter_name']) ?> · <?= e($report['reporter_email']) ?> · <?= e($report['urgency']) ?> urgency</p>
        <p class="small muted">Volunteer: <?= e($report['volunteer_name'] ?: 'Not assigned') ?> · Destination: <?= e($report['shelter_name'] ?: 'Not selected') ?> · Shelter intake: <?= $report['intake_pet_id'] ? 'Pet #' . (int) $report['intake_pet_id'] : 'Not admitted' ?></p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
          <div class="field"><label for="report-status-<?= (int) $report['id'] ?>">Report status</label><select id="report-status-<?= (int) $report['id'] ?>" name="status"><?php foreach ($reportStatuses as $status): ?><option value="<?= e($status) ?>" <?= $report['status'] === $status ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="report-notes-<?= (int) $report['id'] ?>">Staff notes</label><textarea id="report-notes-<?= (int) $report['id'] ?>" name="staff_notes" maxlength="5000"><?= e($report['staff_notes']) ?></textarea></div>
          <button class="btn btn-small" type="submit">Save status</button>
        </form>
        <hr>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
          <div class="field"><label for="volunteer-<?= (int) $report['id'] ?>">Assign volunteer</label><select id="volunteer-<?= (int) $report['id'] ?>" name="volunteer_id" required><option value="">Choose...</option><?php foreach ($volunteers as $volunteer): ?><option value="<?= (int) $volunteer['id'] ?>" <?= (int) $report['volunteer_id'] === (int) $volunteer['id'] ? 'selected' : '' ?>><?= e($volunteer['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="shelter-<?= (int) $report['id'] ?>">Destination shelter</label><select id="shelter-<?= (int) $report['id'] ?>" name="shelter_id" required><option value="">Choose a shelter</option><?php foreach ($shelters as $shelter): ?><option value="<?= (int) $shelter['id'] ?>" <?= (int) $report['shelter_id'] === (int) $shelter['id'] ? 'selected' : '' ?>><?= e($shelter['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="assignment-notes-<?= (int) $report['id'] ?>">Assignment instructions</label><input id="assignment-notes-<?= (int) $report['id'] ?>" name="notes" maxlength="1000" value="<?= e($report['assignment_notes']) ?>"></div>
          <button class="btn btn-small" type="submit">Save assignment</button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
