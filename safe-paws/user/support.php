<?php
require __DIR__ . '/../includes/header.php';
$user = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = post('request_type');
    $description = post('description');
    if (!in_array($type, ['general', 'volunteer', 'shelter', 'pet'], true)
        || strlen($description) < 10 || strlen($description) > 5000) {
        $error = 'Choose a request type and enter a description between 10 and 5,000 characters.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO support_records (user_id,request_type,description) VALUES (?,?,?)');
            $stmt->execute([$user['id'], $type, $description]);
            $id = (int) $pdo->lastInsertId();
            activity_log((int) $user['id'], 'submitted', 'support_record', $id);
            $isVolunteerRequest = $type === 'volunteer';
            notify_admins(
                'support_request:submitted:' . $id,
                $isVolunteerRequest ? 'New volunteer support request' : 'New support request',
                $isVolunteerRequest
                    ? 'A user submitted a request for volunteer support.'
                    : 'A user submitted a support request.',
                'admin/support.php',
                'support_record',
                $id
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Safe PAWS support request submission failed: ' . $exception->getMessage());
            $error = 'Your support request could not be saved. Please try again later.';
        }
        if ($error === '') {
            flash('Your support request was sent to the Safe PAWS team.');
            redirect('user/support.php');
        }
    }
}

$stmt = db()->prepare('SELECT * FROM support_records WHERE user_id=? ORDER BY submitted_at DESC');
$stmt->execute([$user['id']]);
$records = $stmt->fetchAll();
?>
<div class="section-head"><div><h1>Support</h1><p class="muted">Send a request and follow its status here.</p></div></div>
<div class="form-card panel">
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <div class="field"><label for="request_type">What do you need help with?</label><select id="request_type" name="request_type"><?php foreach (['general', 'volunteer', 'shelter', 'pet'] as $type): ?><option value="<?= e($type) ?>"><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="description">Description</label><textarea id="description" name="description" minlength="10" maxlength="5000" required></textarea></div>
    <button class="btn" type="submit">Send request</button>
  </form>
</div>
<div class="section-head"><h2>Your requests</h2></div>
<?php if (!$records): ?><div class="panel empty">You have not submitted a support request.</div><?php else: ?><div class="panel table-wrap"><table class="table"><thead><tr><th>Type</th><th>Request</th><th>Status</th><th>Submitted</th><th>Team note</th></tr></thead><tbody><?php foreach ($records as $record): ?><tr><td><?= e($record['request_type']) ?></td><td><?= nl2br(e($record['description'])) ?></td><td><span class="badge <?= e($record['status']) ?>"><?= e(str_replace('_', ' ', $record['status'])) ?></span></td><td><?= e(date('M j, Y', strtotime($record['submitted_at']))) ?></td><td><?= nl2br(e($record['staff_notes'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
