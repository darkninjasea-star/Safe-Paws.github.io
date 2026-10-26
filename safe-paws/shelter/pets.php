<?php
require __DIR__ . '/../includes/header.php';
$user = require_role('shelter');
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM shelters WHERE user_id=?');
$stmt->execute([$user['id']]);
$shelter = $stmt->fetch();
if (!$shelter) {
    flash('Create your shelter profile first.', 'warning');
    redirect('shelter/index.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM pets WHERE id=? AND shelter_id=?');
$stmt->execute([$editId, $shelter['id']]);
$pet = $editId ? $stmt->fetch() : null;
if ($editId && !$pet) {
    flash('Pet listing not found.', 'error');
    redirect('shelter/pets.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (post('action') === 'delete') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id,image FROM pets WHERE id=? AND shelter_id=? FOR UPDATE');
            $stmt->execute([$id, $shelter['id']]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('Pet listing not found.');
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM adoption_applications WHERE pet_id=?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('This pet has adoption history and cannot be deleted. Mark it unavailable instead.');
            }
            $pdo->prepare('DELETE FROM pets WHERE id=? AND shelter_id=?')->execute([$id, $shelter['id']]);
            activity_log((int) $user['id'], 'deleted', 'pet', $id);
            $pdo->commit();
            remove_unreferenced_pet_image($pdo, $target['image']);
            flash('Pet listing deleted.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash($exception->getMessage(), 'error');
            } else {
                error_log('Safe PAWS shelter pet deletion failed: ' . $exception->getMessage());
                flash('Could not delete this listing.', 'error');
            }
        }
        redirect('shelter/pets.php');
    }

    $name = post('name');
    $species = post('species');
    $sex = post('sex');
    $status = post('status');
    if (strlen($name) < 1 || strlen($name) > 100
        || !in_array($species, ['dog', 'cat', 'rabbit', 'other'], true)
        || !in_array($sex, ['male', 'female', 'unknown'], true)
        || !in_array($status, ['available', 'pending', 'adopted', 'unavailable'], true)
        || strlen(post('breed')) > 100 || strlen(post('age')) > 50
        || strlen(post('color')) > 80 || strlen(post('description')) > 10000) {
        flash('Check the pet details and try again.', 'error');
        redirect($id ? 'shelter/pets.php?edit=' . $id : 'shelter/pets.php');
    }

    $newImage = null;
    try {
        $newImage = upload_image('image', 'pets');
        $pdo->beginTransaction();
        if ($id) {
            $stmt = $pdo->prepare('SELECT image FROM pets WHERE id=? AND shelter_id=? FOR UPDATE');
            $stmt->execute([$id, $shelter['id']]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new RuntimeException('Pet listing not found.');
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM adoption_applications WHERE pet_id=? AND status IN ("submitted","under_review")');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0 && $status !== 'pending') {
                throw new RuntimeException('Review this pet’s active adoption applications before changing its availability.');
            }
            $sql = 'UPDATE pets SET name=?,species=?,breed=?,sex=?,age=?,color=?,description=?,status=?';
            $params = [$name, $species, post('breed') ?: null, $sex, post('age') ?: null, post('color') ?: null, post('description') ?: null, $status];
            if ($newImage) {
                $sql .= ',image=?';
                $params[] = $newImage;
            }
            $sql .= ' WHERE id=? AND shelter_id=?';
            $params[] = $id;
            $params[] = $shelter['id'];
            $pdo->prepare($sql)->execute($params);
            $oldImage = $existing['image'];
            activity_log((int) $user['id'], 'updated', 'pet', $id, ['status' => $status]);
            $message = 'Pet listing updated.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO pets (shelter_id,name,species,breed,sex,age,color,description,image,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$shelter['id'], $name, $species, post('breed') ?: null, $sex, post('age') ?: null, post('color') ?: null, post('description') ?: null, $newImage, $status]);
            $id = (int) $pdo->lastInsertId();
            activity_log((int) $user['id'], 'created', 'pet', $id, ['status' => $status]);
            $oldImage = null;
            $message = 'Pet listing added.';
        }
        $pdo->commit();
        if ($newImage && $oldImage) {
            remove_unreferenced_pet_image($pdo, $oldImage);
        }
        flash($message);
        redirect('shelter/pets.php');
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        remove_uploaded_image($newImage);
        flash($exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        remove_uploaded_image($newImage);
        error_log('Safe PAWS shelter pet save failed: ' . $exception->getMessage());
        flash('Could not save this pet listing.', 'error');
    }
    redirect($id ? 'shelter/pets.php?edit=' . $id : 'shelter/pets.php');
}

$stmt = $pdo->prepare(
    'SELECT p.*,r.id AS rescue_report_id,r.location AS rescue_location,r.status AS rescue_status,
            r.reporter_name AS rescue_reporter_name,r.description AS rescue_description,
            r.staff_notes AS rescue_staff_notes,a.notes AS rescue_instructions,u.name AS rescue_volunteer_name
     FROM pets p
     LEFT JOIN stray_reports r ON r.id=p.rescue_report_id
     LEFT JOIN rescue_assignments a ON a.report_id=r.id
     LEFT JOIN users u ON u.id=a.volunteer_id
     WHERE p.shelter_id=? ORDER BY p.created_at DESC'
);
$stmt->execute([$shelter['id']]);
$pets = $stmt->fetchAll();
?>
<div class="section-head"><div><h1><?= $pet ? 'Edit pet listing' : 'Manage pet listings' ?></h1><p class="muted">Keep your adoption profiles up to date.</p></div><?php if ($pet): ?><a class="btn btn-outline btn-small" href="<?= e(base_url('shelter/pets.php')) ?>">Add a pet</a><?php endif; ?></div>
<div class="panel">
  <h2><?= $pet ? 'Update pet' : 'Add a pet' ?></h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <?php if ($pet): ?><input type="hidden" name="id" value="<?= (int) $pet['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field"><label for="name">Name</label><input id="name" name="name" value="<?= e($pet['name'] ?? '') ?>" maxlength="100" required></div>
      <div class="field"><label for="species">Species</label><select id="species" name="species"><?php foreach (['dog', 'cat', 'rabbit', 'other'] as $value): ?><option value="<?= e($value) ?>" <?= ($pet['species'] ?? 'dog') === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="breed">Breed</label><input id="breed" name="breed" value="<?= e($pet['breed'] ?? '') ?>" maxlength="100"></div>
      <div class="field"><label for="sex">Gender</label><select id="sex" name="sex"><?php foreach (['male', 'female', 'unknown'] as $value): ?><option value="<?= e($value) ?>" <?= ($pet['sex'] ?? 'unknown') === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="age">Age</label><input id="age" name="age" value="<?= e($pet['age'] ?? '') ?>" maxlength="50"></div>
      <div class="field"><label for="color">Color</label><input id="color" name="color" value="<?= e($pet['color'] ?? '') ?>" maxlength="80"></div>
      <div class="field"><label for="status">Status</label><select id="status" name="status"><?php foreach (['available', 'pending', 'adopted', 'unavailable'] as $value): ?><option value="<?= e($value) ?>" <?= ($pet['status'] ?? 'available') === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="image">Photo</label><input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
      <div class="field full"><label for="description">Description</label><textarea id="description" name="description"><?= e($pet['description'] ?? '') ?></textarea></div>
    </div>
    <button class="btn" type="submit"><?= $pet ? 'Save changes' : 'Add pet' ?></button><?php if ($pet): ?> <a href="<?= e(base_url('shelter/pets.php')) ?>">Cancel</a><?php endif; ?>
  </form>
</div>
<div class="section-head"><h2>Your listings</h2></div>
<?php if (!$pets): ?><div class="panel empty">You have not added any pets yet.</div><?php else: ?>
  <div class="panel table-wrap"><table class="table"><thead><tr><th>Pet</th><th>Status</th><th>Rescue intake</th><th>Actions</th></tr></thead><tbody><?php foreach ($pets as $row): ?><tr><td><?php if ($row['image']): ?><img class="table-pet-image" src="<?= e(image_url($row['image'])) ?>" alt="<?= e($row['name']) ?>"><?php endif; ?><?= e($row['name']) ?><br><span class="small muted"><?= e($row['species']) ?> · <?= e($row['breed']) ?></span></td><td><span class="badge <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td><td><?php if ($row['rescue_report_id']): ?><span class="badge rescued">Rescue report #<?= (int) $row['rescue_report_id'] ?></span><br><span class="small muted">Location: <?= e($row['rescue_location']) ?> · Report status: <?= e($row['rescue_status']) ?><br>Reported by <?= e($row['rescue_reporter_name']) ?> · Volunteer: <?= e($row['rescue_volunteer_name']) ?></span><details><summary>Report details</summary><p><?= nl2br(e($row['rescue_description'])) ?></p></details><?php if ($row['rescue_instructions']): ?><p class="small">Instructions: <?= nl2br(e($row['rescue_instructions'])) ?></p><?php endif; ?><?php if ($row['rescue_staff_notes']): ?><p class="small">Staff notes: <?= nl2br(e($row['rescue_staff_notes'])) ?></p><?php endif; ?><?php else: ?><span class="small muted">—</span><?php endif; ?></td><td><a href="<?= e(base_url('shelter/pets.php?edit=' . $row['id'])) ?>">Edit</a> · <form class="inline" method="post" data-confirm="Delete this pet? Listings with adoption history cannot be deleted."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="action" value="delete"><button class="danger" type="submit">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
