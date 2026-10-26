<?php
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/python_api.php';
$u = current_user();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = post('reporter_name');
    $email = post('reporter_email');
    $location = post('location');
    $description = post('description');
    $urgency = in_array(post('urgency'), ['low', 'normal', 'high', 'emergency'], true) ? post('urgency') : 'normal';
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$location || !$description) {
        $error = 'Please complete your name, email, location and description.';
    } else {
        $img = null;
        try {
            $img = upload_image('image', 'reports');
            $result = call_python_api_stray_report([
                'user_id' => $u ? (int) $u['id'] : null,
                'reporter_name' => $name,
                'reporter_email' => $email,
                'phone' => post('phone'),
                'animal_type' => post('animal_type'),
                'urgency' => $urgency,
                'location' => $location,
                'description' => $description,
                'image' => $img,
            ]);
            if (!$result['success']) {
                remove_uploaded_image($img);
                $error = $result['message'];
            } else {
                $reportId = (int) $result['data']['report_id'];
                $notificationFailure = false;
                try {
                    activity_log($u ? (int) $u['id'] : null, 'submitted', 'stray_report', $reportId, ['urgency' => $urgency]);
                    notify_admins(
                        'stray_report:submitted:' . $reportId,
                        'New stray animal report',
                        'A new stray animal report is awaiting review.',
                        'admin/reports.php',
                        'stray_report',
                        $reportId
                    );
                    foreach (db()->query('SELECT id FROM users WHERE role="volunteer" AND status="active"')->fetchAll() as $volunteer) {
                        notify(
                            (int) $volunteer['id'],
                            'New stray report',
                            'A new stray report is available for response.',
                            'volunteer/reports.php',
                            'stray_report',
                            $reportId,
                            'stray_report:submitted:' . $reportId
                        );
                    }
                    foreach (db()->query('SELECT s.user_id FROM shelters s JOIN users u ON u.id=s.user_id WHERE s.status="approved" AND u.status="active"')->fetchAll() as $shelter) {
                        notify(
                            (int) $shelter['user_id'],
                            'New stray report near your shelter',
                            'A stray animal was reported near your shelter.',
                            'shelter/index.php',
                            'stray_report',
                            $reportId,
                            'stray_report:submitted:' . $reportId
                        );
                    }
                } catch (Throwable $exception) {
                    error_log('Safe PAWS stray report notifications failed: ' . $exception->getMessage());
                    $notificationFailure = true;
                }
                flash(
                    $notificationFailure
                        ? 'Your report was saved (reference ' . $result['data']['reference'] . '), but automatic notifications could not be delivered. Please contact Safe PAWS support.'
                        : 'Thank you. Your report was saved (reference ' . $result['data']['reference'] . ') and rescue teams were notified.',
                    $notificationFailure ? 'warning' : 'success'
                );
                redirect('index.php');
            }
        } catch (RuntimeException $e) {
            remove_uploaded_image($img);
            $error = $e->getMessage();
        }
    }
}
?>
<div class="form-card panel"><h1>Report a stray</h1><p class="muted">Share details so a rescue team can respond safely.</p><?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><div class="form-grid"><div class="field"><label>Your name</label><input name="reporter_name" value="<?= e($u['name'] ?? '') ?>" required></div><div class="field"><label>Email</label><input type="email" name="reporter_email" value="<?= e($u['email'] ?? '') ?>" required></div><div class="field"><label>Phone</label><input name="phone" value="<?= e($u['phone'] ?? '') ?>"></div><div class="field"><label>Animal type</label><input name="animal_type" placeholder="Dog, cat, wildlife..."></div><div class="field"><label>Urgency</label><select name="urgency"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="emergency">Emergency</option></select></div><div class="field"><label>Location</label><input name="location" placeholder="Street, landmark or map pin" required></div><div class="field full"><label>What did you see?</label><textarea name="description" required placeholder="Animal type, condition, behavior..."></textarea></div><div class="field full"><label>Photo (optional, max 5 MB; JPG, PNG or WEBP)</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div></div><button class="btn" type="submit">Submit report</button></form></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
