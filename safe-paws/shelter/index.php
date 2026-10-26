<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role('shelter');
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM shelters WHERE user_id=?');
$stmt->execute([$user['id']]);
$shelter = $stmt->fetch();
$newImage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $oldImage = null;
    try {
        $profile = validate_shelter_profile($_POST);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? AND role="shelter" AND status="active" FOR UPDATE');
        $stmt->execute([$user['id']]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Your active Shelter Manager account could not be confirmed.');
        }
        $stmt = $pdo->prepare('SELECT id,image FROM shelters WHERE user_id=? FOR UPDATE');
        $stmt->execute([$user['id']]);
        $existing = $stmt->fetch();
        $newImage = upload_image('image', 'shelters');
        if ($existing) {
            $oldImage = $existing['image'];
            $update = $pdo->prepare(
                'UPDATE shelters SET name=?,description=?,address=?,city=?,phone=?,email=?,image=? WHERE id=? AND user_id=?'
            );
            $update->execute([
                $profile['name'],
                $profile['description'] ?: null,
                $profile['address'],
                $profile['city'],
                $profile['phone'] ?: null,
                $profile['email'] ?: null,
                $newImage ?? $oldImage,
                $existing['id'],
                $user['id'],
            ]);
            $activityId = activity_log((int) $user['id'], 'updated', 'shelter', (int) $existing['id']);
            if ($update->rowCount() > 0) {
                notify_admins(
                    'shelter:updated:' . $activityId,
                    'Shelter profile updated',
                    'A shelter profile was updated.',
                    'admin/shelters.php',
                    'shelter',
                    (int) $existing['id']
                );
            }
            $message = 'Shelter profile updated.';
        } else {
            $pdo->prepare(
                'INSERT INTO shelters(user_id,name,description,address,city,phone,email,image,status)
                 VALUES(?,?,?,?,?,?,?,?, "pending")'
            )->execute([
                $user['id'],
                $profile['name'],
                $profile['description'] ?: null,
                $profile['address'],
                $profile['city'],
                $profile['phone'] ?: null,
                $profile['email'] ?: null,
                $newImage,
            ]);
            $shelterId = (int) $pdo->lastInsertId();
            activity_log((int) $user['id'], 'created', 'shelter', $shelterId, ['status' => 'pending']);
            notify_admins(
                'shelter:created:' . $shelterId,
                'New shelter profile',
                'A shelter profile was submitted for approval.',
                'admin/shelters.php',
                'shelter',
                $shelterId
            );
            $message = 'Shelter profile submitted for admin approval.';
        }
        $pdo->commit();
        if ($newImage && $oldImage && $newImage !== $oldImage) {
            remove_unreferenced_shelter_image($pdo, $oldImage);
        }
        flash($message);
        redirect('shelter/index.php');
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newImage) {
            remove_uploaded_image($newImage);
        }
        flash($exception->getMessage(), 'error');
        redirect('shelter/index.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newImage) {
            remove_uploaded_image($newImage);
        }
        error_log('Safe PAWS shelter manager profile save failed: ' . $exception->getMessage());
        flash('Could not save your shelter profile. Please try again.', 'error');
        redirect('shelter/index.php');
    }
}

require __DIR__ . '/../includes/header.php';

if (!$shelter) {
    ?>
    <div class="form-card panel">
      <h1>Create your shelter profile</h1>
      <p>Tell adopters about your organization. An administrator will approve it before it appears publicly.</p>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="field"><label for="shelter-name">Shelter name</label><input id="shelter-name" name="name" minlength="2" maxlength="160" required></div>
        <div class="field"><label for="shelter-description">Description</label><textarea id="shelter-description" name="description" maxlength="5000"></textarea></div>
        <div class="field"><label for="shelter-image">Upload shelter photo</label><input id="shelter-image" type="file" name="image" accept="image/jpeg,image/png,image/webp"><span class="small muted">JPG, PNG, or WebP, up to 5 MB.</span></div>
        <div class="form-grid">
          <div class="field"><label for="shelter-address">Full address</label><input id="shelter-address" name="address" minlength="2" maxlength="255" required></div>
          <div class="field"><label for="shelter-city">City</label><input id="shelter-city" name="city" minlength="2" maxlength="100" required></div>
          <div class="field"><label for="shelter-email">Contact email</label><input id="shelter-email" name="email" type="email" value="<?= e($user['email']) ?>" maxlength="190"></div>
          <div class="field"><label for="shelter-phone">Phone number</label><input id="shelter-phone" name="phone" type="tel" value="<?= e($user['phone']) ?>" title="Use at least 7 digits, with optional +, spaces, parentheses, periods, or hyphens." maxlength="30"><span class="small muted">At least 7 digits; spaces, +, parentheses, periods, and hyphens are allowed.</span></div>
        </div>
        <p class="small muted">New shelter profiles are submitted as pending and cannot approve themselves.</p>
        <button class="btn" type="submit">Submit profile</button>
      </form>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM pets WHERE shelter_id=?');
$stmt->execute([$shelter['id']]);
$petCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM adoption_applications a JOIN pets p ON p.id=a.pet_id WHERE p.shelter_id=? AND a.status IN ("submitted","under_review")');
$stmt->execute([$shelter['id']]);
$applicationCount = (int) $stmt->fetchColumn();
?>
<div class="hero-strip">
  <h1><?= e($shelter['name']) ?></h1>
  <p>Manage your listings and help adopters find the right companion.</p>
  <?php if ($shelter['status'] !== 'approved'): ?><div class="alert warning">Your profile is <?= e($shelter['status']) ?> and is not public yet.</div><?php endif; ?>
</div>
<div class="stats"><div class="stat"><strong><?= $petCount ?></strong><span>Total pets</span></div><div class="stat"><strong><?= $applicationCount ?></strong><span>Applications to review</span></div></div>
<div class="actions"><a class="btn" href="pets.php">Manage pets</a><a class="btn btn-outline" href="applications.php">Review applications</a><a class="btn btn-outline" href="reports.php">Rescue reports</a><a class="btn btn-outline" href="<?= e(base_url('user/notifications.php')) ?>">Notifications</a></div>
<section class="panel content-section">
  <div class="section-head"><div><h2>Manage shelter profile</h2><p class="muted">Update your public details and photo. Approval status is managed by an administrator.</p></div><span class="badge <?= e($shelter['status']) ?>"><?= e($shelter['status']) ?></span></div>
  <img class="shelter-photo shelter-photo-profile" src="<?= e(image_url($shelter['image'])) ?>" alt="<?= e($shelter['name'] . ' shelter photo') ?>">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="field"><label for="shelter-name">Shelter name</label><input id="shelter-name" name="name" value="<?= e($shelter['name']) ?>" minlength="2" maxlength="160" required></div>
    <div class="field"><label for="shelter-description">Description</label><textarea id="shelter-description" name="description" maxlength="5000"><?= e($shelter['description']) ?></textarea></div>
    <div class="field"><label for="shelter-image">Upload or replace shelter photo</label><input id="shelter-image" type="file" name="image" accept="image/jpeg,image/png,image/webp"><span class="small muted">Leave empty to keep the current photo. JPG, PNG, or WebP, up to 5 MB.</span></div>
    <div class="form-grid">
      <div class="field"><label for="shelter-address">Full address</label><input id="shelter-address" name="address" value="<?= e($shelter['address']) ?>" minlength="2" maxlength="255" required></div>
      <div class="field"><label for="shelter-city">City</label><input id="shelter-city" name="city" value="<?= e($shelter['city']) ?>" minlength="2" maxlength="100" required></div>
      <div class="field"><label for="shelter-email">Contact email</label><input id="shelter-email" name="email" type="email" value="<?= e($shelter['email']) ?>" maxlength="190"></div>
      <div class="field"><label for="shelter-phone">Phone number</label><input id="shelter-phone" name="phone" type="tel" value="<?= e($shelter['phone']) ?>" title="Use at least 7 digits, with optional +, spaces, parentheses, periods, or hyphens." maxlength="30"><span class="small muted">At least 7 digits; spaces, +, parentheses, periods, and hyphens are allowed.</span></div>
    </div>
    <button class="btn" type="submit">Save profile</button>
  </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
