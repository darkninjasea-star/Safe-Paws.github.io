<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');
$pdo = db();
$petId = (int) ($_GET['edit'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM pets WHERE id=?');
$stmt->execute([$petId]);
$pet = $petId ? $stmt->fetch() : null;
if ($petId && !$pet) {
    flash('Pet listing not found.', 'error');
    redirect('admin/pets.php');
}
$shelters = $pdo->query('SELECT id,name FROM shelters ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $action = post('action');
    if ($action === 'delete') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id,image FROM pets WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('Pet listing not found.');
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM adoption_applications WHERE pet_id=?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('This pet has adoption history and cannot be deleted. Mark it unavailable instead.');
            }
            $pdo->prepare('DELETE FROM pets WHERE id=?')->execute([$id]);
            activity_log((int) $admin['id'], 'deleted', 'pet', $id);
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
                error_log('Safe PAWS pet deletion failed: ' . $exception->getMessage());
                flash('Could not delete this pet listing.', 'error');
            }
        }
        redirect('admin/pets.php');
    }

    $name = post('name');
    $species = post('species');
    $sex = post('sex');
    $status = post('status');
    $shelterId = (int) ($_POST['shelter_id'] ?? 0);
    $validShelter = $pdo->prepare('SELECT id FROM shelters WHERE id=?');
    $validShelter->execute([$shelterId]);
    if (strlen($name) < 1 || strlen($name) > 100
        || !in_array($species, ['dog', 'cat', 'rabbit', 'other'], true)
        || !in_array($sex, ['male', 'female', 'unknown'], true)
        || !in_array($status, ['available', 'pending', 'adopted', 'unavailable'], true)
        || !$validShelter->fetch()
        || strlen(post('breed')) > 100
        || strlen(post('age')) > 50
        || strlen(post('color')) > 80) {
        flash('Check the pet details and choose a valid shelter.', 'error');
        redirect($id ? 'admin/pets.php?edit=' . $id : 'admin/pets.php');
    }

    $newImage = null;
    try {
        $newImage = upload_image('image', 'pets');
        $pdo->beginTransaction();
        if ($id) {
            $stmt = $pdo->prepare('SELECT image FROM pets WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new RuntimeException('Pet listing not found.');
            }
            if ($status !== 'pending') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM adoption_applications WHERE pet_id=? AND status IN ("submitted","under_review")');
                $stmt->execute([$id]);
                if ((int) $stmt->fetchColumn() > 0 && $status !== 'pending') {
                    throw new RuntimeException('This pet has active adoption applications. Review them before changing availability.');
                }
            }
            $sql = 'UPDATE pets SET shelter_id=?,name=?,species=?,breed=?,sex=?,age=?,color=?,description=?,status=?';
            $params = [$shelterId, $name, $species, post('breed') ?: null, $sex, post('age') ?: null, post('color') ?: null, post('description') ?: null, $status];
            if ($newImage) {
                $sql .= ',image=?';
                $params[] = $newImage;
            }
            $sql .= ' WHERE id=?';
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            activity_log((int) $admin['id'], 'updated', 'pet', $id, ['status' => $status]);
            $oldImage = $existing['image'];
            flash('Pet listing updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO pets (shelter_id,name,species,breed,sex,age,color,description,image,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$shelterId, $name, $species, post('breed') ?: null, $sex, post('age') ?: null, post('color') ?: null, post('description') ?: null, $newImage, $status]);
            $id = (int) $pdo->lastInsertId();
            activity_log((int) $admin['id'], 'created', 'pet', $id, ['status' => $status]);
            $oldImage = null;
            flash('Pet listing created.');
        }
        $pdo->commit();
        if ($newImage && !empty($oldImage)) {
            remove_unreferenced_pet_image($pdo, $oldImage);
        }
        redirect('admin/pets.php');
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
        error_log('Safe PAWS pet save failed: ' . $exception->getMessage());
        flash('Could not save this pet listing.', 'error');
    }
    redirect($id ? 'admin/pets.php?edit=' . $id : 'admin/pets.php');
}

$query = trim((string) ($_GET['q'] ?? ''));
$stmt = $pdo->prepare(
    'SELECT p.*,s.name AS shelter_name FROM pets p JOIN shelters s ON s.id=p.shelter_id
     WHERE p.name LIKE ? OR p.species LIKE ? OR s.name LIKE ?
     ORDER BY p.created_at DESC'
);
$like = '%' . $query . '%';
$stmt->execute([$like, $like, $like]);
$pets = $stmt->fetchAll();
?>
<div class="section-head"><div><h1><?= $pet ? 'Edit pet listing' : 'Pet management' ?></h1><p class="muted">Add and maintain adoption listings.</p></div><?php if ($pet): ?><a class="btn btn-outline btn-small" href="<?= e(base_url('admin/pets.php')) ?>">Add a pet</a><?php endif; ?></div>
<div class="panel">
  <h2><?= $pet ? 'Update pet' : 'Add a pet' ?></h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?php if ($pet): ?><input type="hidden" name="id" value="<?= (int) $pet['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field"><label for="pet-name">Name</label><input id="pet-name" name="name" value="<?= e($pet['name'] ?? '') ?>" maxlength="100" required></div>
      <div class="field"><label for="shelter">Shelter</label><select id="shelter" name="shelter_id" required><option value="">Choose shelter</option><?php foreach ($shelters as $shelter): ?><option value="<?= (int) $shelter['id'] ?>" <?= (int) ($pet['shelter_id'] ?? 0) === (int) $shelter['id'] ? 'selected' : '' ?>><?= e($shelter['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="species">Species</label><select id="species" name="species"><?php foreach (['dog', 'cat', 'rabbit', 'other'] as $species): ?><option value="<?= e($species) ?>" <?= ($pet['species'] ?? 'dog') === $species ? 'selected' : '' ?>><?= e(ucfirst($species)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="breed">Breed</label><input id="breed" name="breed" value="<?= e($pet['breed'] ?? '') ?>" maxlength="100"></div>
      <div class="field"><label for="sex">Gender</label><select id="sex" name="sex"><?php foreach (['male', 'female', 'unknown'] as $sex): ?><option value="<?= e($sex) ?>" <?= ($pet['sex'] ?? 'unknown') === $sex ? 'selected' : '' ?>><?= e(ucfirst($sex)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="age">Age</label><input id="age" name="age" value="<?= e($pet['age'] ?? '') ?>" maxlength="50"></div>
      <div class="field"><label for="color">Color</label><input id="color" name="color" value="<?= e($pet['color'] ?? '') ?>" maxlength="80"></div>
      <div class="field"><label for="pet-status">Status</label><select id="pet-status" name="status"><?php foreach (['available', 'pending', 'adopted', 'unavailable'] as $status): ?><option value="<?= e($status) ?>" <?= ($pet['status'] ?? 'available') === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="image">Photo (optional)</label><input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
      <div class="field full"><label for="description">Description</label><textarea id="description" name="description"><?= e($pet['description'] ?? '') ?></textarea></div>
    </div>
    <button class="btn" type="submit"><?= $pet ? 'Save changes' : 'Create pet listing' ?></button>
  </form>
</div>
<form class="filters" method="get"><div class="field"><label for="q">Search listings</label><input id="q" name="q" value="<?= e($query) ?>" placeholder="Pet or shelter"></div><button class="btn" type="submit">Search</button></form>
<?php if (!$pets): ?><div class="panel empty">No pet listings match your search.</div><?php else: ?>
  <div class="panel table-wrap"><table class="table"><thead><tr><th>Pet</th><th>Shelter</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($pets as $row): ?><tr>    <td><strong><?= e($row['name']) ?></strong><br><span class="small muted"><?= e($row['species']) ?> · <?= e($row['breed']) ?></span></td><td><?= e($row['shelter_name']) ?></td><td><span class="badge <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td><td><a href="<?= e(base_url('admin/pets.php?edit=' . $row['id'])) ?>">Edit</a> · <form class="inline" method="post" data-confirm="Delete this pet listing? Pets with adoption history cannot be deleted."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
