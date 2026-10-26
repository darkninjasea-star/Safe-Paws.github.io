<?php
require __DIR__ . '/../includes/header.php';
$u = require_role('volunteer');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $status = post('status');
    if (!in_array($status, ['assigned', 'in_progress', 'completed', 'cancelled'], true)) {
        flash('Choose a valid assignment status.', 'error');
        redirect('volunteer/reports.php');
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT report_id FROM rescue_assignments WHERE id=? AND volunteer_id=?');
        $s->execute([$id, $u['id']]);
        $reportId = $s->fetchColumn();
        if ($reportId === false) {
            throw new RuntimeException('This rescue assignment is no longer available.');
        }
        $s = $pdo->prepare('SELECT status,reporter_id FROM stray_reports WHERE id=? FOR UPDATE');
        $s->execute([$reportId]);
        $report = $s->fetch();
        if (!$report) {
            throw new RuntimeException('The report for this assignment is no longer available.');
        }
        $reportStatus = $report['status'];
        $s = $pdo->prepare('SELECT report_id,status FROM rescue_assignments WHERE id=? AND volunteer_id=? FOR UPDATE');
        $s->execute([$id, $u['id']]);
        $assignment = $s->fetch();
        if (!$assignment || (int) $assignment['report_id'] !== (int) $reportId) {
            throw new RuntimeException('This rescue assignment is no longer available.');
        }
        if ($assignment['status'] === 'completed' && $status !== 'completed') {
            throw new RuntimeException('A completed rescue cannot be reopened by a volunteer. Contact an administrator if this needs correction.');
        }

        $pdo->prepare('UPDATE rescue_assignments SET status=? WHERE id=? AND volunteer_id=?')->execute([$status, $id, $u['id']]);
        activity_log((int) $u['id'], 'updated', 'rescue_assignment', $id, ['status' => $status]);
        $map = ['assigned' => 'assigned', 'in_progress' => 'in_progress', 'completed' => 'rescued', 'cancelled' => 'closed'];
        $newReportStatus = $map[$status];
        if ($status === 'completed') {
            record_rescue_intake($pdo, (int) $reportId);
        }
        $pdo->prepare(
            'UPDATE stray_reports SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([$newReportStatus, $u['id'], $reportId]);
        if ($reportStatus !== $newReportStatus) {
            $pdo->prepare(
                'INSERT INTO report_status_history (report_id,previous_status,new_status,actor_id,notes)
                 VALUES (?,?,?,?,?)'
            )->execute([$reportId, $reportStatus, $newReportStatus, $u['id'], null]);
            $historyId = (int) $pdo->lastInsertId();
            $eventKey = 'stray_report:status:' . $historyId;
            if ($report['reporter_id']) {
                notify(
                    (int) $report['reporter_id'],
                    'Stray report updated',
                    'Your stray animal report is now ' . str_replace('_', ' ', $newReportStatus) . '.',
                    'user/reports.php',
                    'stray_report',
                    (int) $reportId,
                    $eventKey
                );
            }
            notify_admins(
                $eventKey,
                'Stray report status changed',
                'A rescue report status changed to ' . str_replace('_', ' ', $newReportStatus) . '.',
                'admin/reports.php',
                'stray_report',
                (int) $reportId
            );
        }
        $pdo->commit();
        flash($status === 'completed'
            ? 'Rescue completed and shelter intake recorded.'
            : 'Assignment updated.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException) {
            error_log('Safe PAWS volunteer rescue update failed: ' . $exception->getMessage());
            flash('Could not update this rescue assignment.', 'error');
        } elseif ($exception instanceof RuntimeException) {
            flash($exception->getMessage(), 'error');
        } else {
            error_log('Safe PAWS volunteer rescue update failed: ' . $exception->getMessage());
            flash('Could not update this rescue assignment.', 'error');
        }
    }
    redirect('volunteer/reports.php');
}
$s = db()->prepare('SELECT a.*,r.location,r.description,r.reporter_name,sh.name shelter_name FROM rescue_assignments a JOIN stray_reports r ON r.id=a.report_id LEFT JOIN shelters sh ON sh.id=a.shelter_id WHERE a.volunteer_id=? ORDER BY a.created_at DESC');
$s->execute([$u['id']]);
$rows = $s->fetchAll();
?>
<div class="section-head"><h1>Rescue assignments</h1></div><?php if (!$rows): ?><div class="panel empty">No assignments yet. Thank you for being ready to help.</div><?php else: ?><div class="grid"><?php foreach ($rows as $r): ?><div class="panel"><span class="badge <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span><h3><?= e($r['location']) ?></h3><p><?= nl2br(e($r['description'])) ?></p><p class="small">Reporter: <?= e($r['reporter_name']) ?><?php if ($r['shelter_name']): ?> &middot; Destination: <?= e($r['shelter_name']) ?><?php endif; ?></p><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><select name="status"><?php foreach (['assigned', 'in_progress', 'completed', 'cancelled'] as $x): ?><option <?= $r['status'] === $x ? 'selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select> <button class="btn btn-small">Update</button></form></div><?php endforeach; ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
