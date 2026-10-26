<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = require_role('admin');
$pdo = db();
$statuses = ['pending', 'approved', 'suspended'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    $id = (int) ($_POST['id'] ?? 0);
    $newImage = null;

    if ($action === 'delete') {
        $oldImage = null;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id,name,user_id,image FROM shelters WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $shelter = $stmt->fetch();
            if (!$shelter) {
                throw new RuntimeException('Shelter not found.');
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pets WHERE shelter_id=?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('This shelter has pet records. Reassign those listings before deleting it.');
            }
            $pdo->prepare('DELETE FROM shelters WHERE id=?')->execute([$id]);
            activity_log((int) $admin['id'], 'deleted', 'shelter', $id);
            if ($shelter['user_id']) {
                notify(
                    (int) $shelter['user_id'],
                    'Shelter profile removed',
                    'Your shelter profile was removed by an administrator.',
                    'shelter/index.php',
                    'shelter',
                    $id,
                    'shelter:deleted:' . $id
                );
            }
            $pdo->commit();
            $oldImage = $shelter['image'];
            flash('Shelter profile deleted.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash($exception->getMessage(), 'error');
            } else {
                error_log('Safe PAWS shelter deletion failed: ' . $exception->getMessage());
                flash('Could not delete this shelter.', 'error');
            }
        }
        if ($oldImage) {
            remove_unreferenced_shelter_image($pdo, $oldImage);
        }
        redirect('admin/shelters.php');
    }

    try {
        $profile = validate_shelter_profile($_POST);
        $managerId = (int) ($_POST['user_id'] ?? 0);
        $managerId = $managerId > 0 ? $managerId : null;
        $status = post('status');
        if (!in_array($status, $statuses, true)) {
            throw new InvalidArgumentException('Choose a valid shelter status.');
        }

        $pdo->beginTransaction();
        if ($managerId !== null) {
            $stmt = $pdo->prepare('SELECT id,name,email,status,role FROM users WHERE id=? FOR UPDATE');
            $stmt->execute([$managerId]);
            $manager = $stmt->fetch();
            if (!$manager || $manager['role'] !== 'shelter' || $manager['status'] !== 'active') {
                throw new RuntimeException('The selected manager is not an active shelter manager.');
            }
            $stmt = $pdo->prepare('SELECT id FROM shelters WHERE user_id=? AND id<>? FOR UPDATE');
            $stmt->execute([$managerId, $id]);
            if ($stmt->fetch()) {
                throw new RuntimeException('That shelter manager already manages another shelter.');
            }
        }

        $uploadedImage = upload_image('image', 'shelters');
        if ($action === 'create') {
            $pdo->prepare(
                'INSERT INTO shelters (user_id,name,description,address,city,phone,email,image,status)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $managerId,
                $profile['name'],
                $profile['description'] ?: null,
                $profile['address'],
                $profile['city'],
                $profile['phone'] ?: null,
                $profile['email'] ?: null,
                $uploadedImage,
                $status,
            ]);
            $shelterId = (int) $pdo->lastInsertId();
            $activityId = activity_log((int) $admin['id'], 'created', 'shelter', $shelterId, ['status' => $status]);
            notify_admins(
                'shelter:created:' . $shelterId,
                'Shelter profile created',
                'A shelter profile was added to Safe PAWS.',
                'admin/shelters.php',
                'shelter',
                $shelterId
            );
            if ($managerId !== null) {
                notify(
                    $managerId,
                    'Shelter profile created',
                    'An administrator created a shelter profile for your account.',
                    'shelter/index.php',
                    'shelter',
                    $shelterId,
                    'shelter:manager:' . $activityId
                );
            }
            $message = 'Shelter profile created.';
        } elseif ($action === 'update') {
            $stmt = $pdo->prepare('SELECT id,user_id,image,status FROM shelters WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new RuntimeException('Shelter not found.');
            }
            $oldImage = $existing['image'];
            $image = $uploadedImage ?? $oldImage;
            $update = $pdo->prepare(
                'UPDATE shelters
                 SET user_id=?,name=?,description=?,address=?,city=?,phone=?,email=?,image=?,status=?
                 WHERE id=?'
            );
            $update->execute([
                $managerId,
                $profile['name'],
                $profile['description'] ?: null,
                $profile['address'],
                $profile['city'],
                $profile['phone'] ?: null,
                $profile['email'] ?: null,
                $image,
                $status,
                $id,
            ]);
            $activityId = activity_log((int) $admin['id'], 'updated', 'shelter', $id, ['status' => $status]);
            if ($update->rowCount() > 0) {
                notify_admins(
                    'shelter:updated:' . $activityId,
                    'Shelter profile updated',
                    'A shelter profile was updated.',
                    'admin/shelters.php',
                    'shelter',
                    $id
                );
                if ($existing['user_id'] && (int) $existing['user_id'] !== $managerId) {
                    notify(
                        (int) $existing['user_id'],
                        'Shelter profile reassigned',
                        'An administrator reassigned this shelter profile.',
                        'shelter/index.php',
                        'shelter',
                        $id,
                        'shelter:manager:' . $activityId
                    );
                }
                if ($managerId !== null) {
                    notify(
                        $managerId,
                        'Shelter profile updated',
                        'Your shelter profile was updated by an administrator.',
                        'shelter/index.php',
                        'shelter',
                        $id,
                        'shelter:manager:' . $activityId
                    );
                }
            }
            $message = 'Shelter profile updated.';
        } else {
            throw new InvalidArgumentException('Choose a valid shelter action.');
        }
        $pdo->commit();
        if ($action === 'update' && $uploadedImage && $oldImage && $uploadedImage !== $oldImage) {
            remove_unreferenced_shelter_image($pdo, $oldImage);
        }
        flash($message);
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($uploadedImage) {
            remove_uploaded_image($uploadedImage);
        }
        flash($exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($uploadedImage) {
            remove_uploaded_image($uploadedImage);
        }
        error_log('Safe PAWS shelter save failed: ' . $exception->getMessage());
        flash('Could not save this shelter. Please try again.', 'error');
    }
    redirect('admin/shelters.php');
}

require __DIR__ . '/../includes/header.php';
$search = trim((string) ($_GET['q'] ?? ''));
$filterStatus = (string) ($_GET['status'] ?? 'all');
if (!in_array($filterStatus, ['all', ...$statuses], true)) {
    $filterStatus = 'all';
}
$sql = 'SELECT s.*,u.email AS account_email,u.name AS manager_name
        FROM shelters s LEFT JOIN users u ON u.id=s.user_id';
$conditions = [];
$params = [];
if ($filterStatus !== 'all') {
    $conditions[] = 's.status=?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $conditions[] = '(s.name LIKE ? OR s.city LIKE ? OR s.address LIKE ? OR s.email LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY s.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$managers = $pdo->query(
    'SELECT u.id,u.name,u.email,s.id AS shelter_id
     FROM users u LEFT JOIN shelters s ON s.user_id=u.id
     WHERE u.role="shelter" AND (u.status="active" OR s.id IS NOT NULL)
     ORDER BY u.name'
)->fetchAll();
$availableManagers = array_values(array_filter($managers, static fn($manager) => !$manager['shelter_id']));
?>
<div class="section-head">
  <div><h1>Shelter management</h1><p class="muted">Create and manage partner profiles, photos, contact details, and approval status.</p></div>
</div>
<details class="panel shelter-form-toggle" id="new-shelter">
  <summary class="btn">Add New Shelter</summary>
  <div class="content-section">
    <h2>Create shelter profile</h2>
    <?php if (!$availableManagers): ?><p class="alert warning">No unassigned active Shelter Manager account is available. You can create this shelter without a manager and assign one later.</p><?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field"><label for="new-name">Shelter name</label><input id="new-name" name="name" minlength="2" maxlength="160" required></div>
          <div class="field"><label for="new-manager">Shelter manager</label><select id="new-manager" name="user_id"><option value="0">Not assigned</option><?php foreach ($availableManagers as $manager): ?><option value="<?= (int) $manager['id'] ?>"><?= e($manager['name'] . ' · ' . $manager['email']) ?></option><?php endforeach; ?></select></div>
          <div class="field full"><label for="new-description">Description</label><textarea id="new-description" name="description" maxlength="5000"></textarea></div>
          <div class="field"><label for="new-image">Upload shelter photo</label><input id="new-image" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
          <div class="field"><label for="new-address">Full address</label><input id="new-address" name="address" minlength="2" maxlength="255" required></div>
          <div class="field"><label for="new-city">City</label><input id="new-city" name="city" minlength="2" maxlength="100" required></div>
          <div class="field"><label for="new-email">Contact email</label><input id="new-email" name="email" type="email" maxlength="190"></div>
          <div class="field"><label for="new-phone">Phone number</label><input id="new-phone" name="phone" type="tel" title="Use at least 7 digits, with optional +, spaces, parentheses, periods, or hyphens." maxlength="30"><span class="small muted">At least 7 digits; spaces, +, parentheses, periods, and hyphens are allowed.</span></div>
          <div class="field"><label for="new-status">Shelter status</label><select id="new-status" name="status"><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $status === 'pending' ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
        </div>
        <p class="small muted">Photos must be JPG, PNG, or WebP and no larger than 5 MB.</p>
        <button class="btn" type="submit">Create shelter</button>
      </form>
  </div>
</details>
<form method="get" class="filters">
  <div class="field"><label for="shelter-search">Search shelters</label><input id="shelter-search" name="q" value="<?= e($search) ?>" placeholder="Name, location, email, or manager"></div>
  <div class="field"><label for="status-filter">Status</label><select id="status-filter" name="status"><option value="all">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-small" type="submit">Filter</button>
  <?php if ($search !== '' || $filterStatus !== 'all'): ?><a class="btn btn-outline btn-small" href="<?= e(base_url('admin/shelters.php')) ?>">Clear</a><?php endif; ?>
</form>
<?php if (!$rows): ?><div class="panel empty">No shelter profiles match this filter.</div><?php else: ?>
  <div class="grid">
    <?php foreach ($rows as $shelter): ?>
      <article class="panel">
        <img class="shelter-photo" src="<?= e(image_url($shelter['image'])) ?>" alt="<?= e($shelter['name'] . ' shelter photo') ?>">
        <div class="shelter-card-heading"><h2><?= e($shelter['name']) ?></h2><span class="badge <?= e($shelter['status']) ?>"><?= e($shelter['status']) ?></span></div>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $shelter['id'] ?>">
          <div class="field"><label for="shelter-name-<?= (int) $shelter['id'] ?>">Shelter name</label><input id="shelter-name-<?= (int) $shelter['id'] ?>" name="name" value="<?= e($shelter['name']) ?>" minlength="2" maxlength="160" required></div>
          <div class="field"><label for="shelter-manager-<?= (int) $shelter['id'] ?>">Shelter manager</label><select id="shelter-manager-<?= (int) $shelter['id'] ?>" name="user_id"><option value="0" <?= !$shelter['user_id'] ? 'selected' : '' ?>>Not assigned</option><?php foreach ($managers as $manager): ?><?php if (!$manager['shelter_id'] || (int) $manager['shelter_id'] === (int) $shelter['id']): ?><option value="<?= (int) $manager['id'] ?>" <?= (int) $manager['id'] === (int) $shelter['user_id'] ? 'selected' : '' ?>><?= e($manager['name'] . ' · ' . $manager['email']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
          <div class="field"><label for="description-<?= (int) $shelter['id'] ?>">Description</label><textarea id="description-<?= (int) $shelter['id'] ?>" name="description" maxlength="5000"><?= e($shelter['description']) ?></textarea></div>
          <div class="field"><label for="image-<?= (int) $shelter['id'] ?>">Upload or replace shelter photo</label><input id="image-<?= (int) $shelter['id'] ?>" type="file" name="image" accept="image/jpeg,image/png,image/webp"><span class="small muted">Leave empty to keep the current photo.</span></div>
          <div class="field"><label for="address-<?= (int) $shelter['id'] ?>">Full address</label><input id="address-<?= (int) $shelter['id'] ?>" name="address" value="<?= e($shelter['address']) ?>" minlength="2" maxlength="255" required></div>
          <div class="field"><label for="city-<?= (int) $shelter['id'] ?>">City</label><input id="city-<?= (int) $shelter['id'] ?>" name="city" value="<?= e($shelter['city']) ?>" minlength="2" maxlength="100" required></div>
          <div class="field"><label for="email-<?= (int) $shelter['id'] ?>">Contact email</label><input id="email-<?= (int) $shelter['id'] ?>" name="email" type="email" value="<?= e($shelter['email']) ?>" maxlength="190"></div>
          <div class="field"><label for="phone-<?= (int) $shelter['id'] ?>">Phone number</label><input id="phone-<?= (int) $shelter['id'] ?>" name="phone" type="tel" value="<?= e($shelter['phone']) ?>" title="Use at least 7 digits, with optional +, spaces, parentheses, periods, or hyphens." maxlength="30"><span class="small muted">At least 7 digits; spaces, +, parentheses, periods, and hyphens are allowed.</span></div>
          <div class="field"><label for="status-<?= (int) $shelter['id'] ?>">Shelter status</label><select id="status-<?= (int) $shelter['id'] ?>" name="status"><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $shelter['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
          <p class="small muted">Manager: <?= e($shelter['manager_name'] ?: 'Not assigned') ?><?php if ($shelter['account_email']): ?> · <?= e($shelter['account_email']) ?><?php endif; ?></p>
          <button class="btn btn-small" type="submit">Save profile</button>
        </form>
        <?php if ($shelter['status'] === 'approved'): ?><p class="actions"><a class="btn btn-outline btn-small" href="<?= e(base_url('shelter_details.php?id=' . $shelter['id'])) ?>">View Details</a></p><?php else: ?><p class="small muted">Public details become available after approval.</p><?php endif; ?>
        <form method="post" class="actions" data-confirm="Delete this shelter profile? Shelters with pets cannot be deleted.">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $shelter['id'] ?>">
          <button class="btn btn-outline btn-small danger" type="submit">Delete profile</button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
