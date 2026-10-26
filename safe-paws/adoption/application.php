<?php
require __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/python_api.php';
$u = require_role('user');
$petId = (int) ($_GET['pet_id'] ?? $_POST['pet_id'] ?? 0);
$s = db()->prepare('SELECT p.*, s.name shelter_name, s.user_id shelter_user_id FROM pets p JOIN shelters s ON s.id=p.shelter_id WHERE p.id=? AND p.status="available" AND s.status="approved"');
$s->execute([$petId]);
$pet = $s->fetch();
if (!$pet) {
    flash('That pet is no longer available.', 'warning');
    redirect('pets.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (strlen(post('message')) < 20 || !post('phone') || !post('address')) {
        $error = 'Please provide a message of at least 20 characters, phone and home address.';
    } else {
        $preferred = in_array(post('preferred_contact'), ['email', 'phone', 'either'], true) ? post('preferred_contact') : 'either';
        $result = call_python_api_adoption([
            'pet_id' => $petId,
            'applicant_id' => (int) $u['id'],
            'message' => post('message'),
            'phone' => post('phone'),
            'address' => post('address'),
            'household_type' => post('household_type'),
            'experience' => post('experience'),
            'other_pets' => post('other_pets'),
            'preferred_contact' => $preferred,
        ]);
        if (!$result['success']) {
            $error = $result['message'];
        } else {
            $applicationId = (int) $result['data']['application_id'];
            $notificationFailure = false;
            try {
                activity_log((int) $u['id'], 'submitted', 'adoption_application', $applicationId, ['pet_id' => $petId]);
                notify(
                    (int) $pet['shelter_user_id'],
                    'New adoption application',
                    'A new application was submitted for ' . $pet['name'] . '.',
                    'shelter/applications.php',
                    'adoption_application',
                    $applicationId,
                    'adoption_application:submitted:' . $applicationId
                );
                notify_admins(
                    'adoption_application:submitted:' . $applicationId,
                    'New adoption application',
                    'A new adoption application is ready for review.',
                    'admin/applications.php',
                    'adoption_application',
                    $applicationId
                );
            } catch (Throwable $exception) {
                error_log('Safe PAWS adoption notification failed: ' . $exception->getMessage());
                $notificationFailure = true;
            }
            flash(
                $notificationFailure
                    ? 'Your application was submitted, but automatic notifications could not be delivered. Please contact Safe PAWS support.'
                    : 'Application submitted. You can track it from your dashboard.',
                $notificationFailure ? 'warning' : 'success'
            );
            redirect('user/applications.php');
        }
    }
}
?>
<div class="form-card panel"><h1>Adopt <?= e($pet['name']) ?></h1><p class="muted">Your application will be reviewed by <?= e($pet['shelter_name']) ?>.</p><?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><input type="hidden" name="pet_id" value="<?= $petId ?>"><div class="field"><label>Why would you be a great match?</label><textarea name="message" required minlength="20"></textarea></div><div class="field"><label>Phone</label><input name="phone" value="<?= e($u['phone'] ?? '') ?>" required></div><div class="field"><label>Home address</label><input name="address" value="<?= e($u['address'] ?? '') ?>" required></div><div class="field"><label>Household type</label><input name="household_type" placeholder="Apartment, house, farm..."></div><div class="field"><label>Pet care experience</label><textarea name="experience"></textarea></div><div class="field"><label>Other pets</label><textarea name="other_pets"></textarea></div><div class="field"><label>Preferred contact</label><select name="preferred_contact"><option value="either">Email or phone</option><option value="email">Email</option><option value="phone">Phone</option></select></div><button class="btn" type="submit">Submit application</button></form></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
