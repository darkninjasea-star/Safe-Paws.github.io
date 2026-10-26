<?php
$pageTitle = 'Find a pet';
require __DIR__ . '/includes/header.php';

$query = trim((string) ($_GET['q'] ?? ''));
$breed = trim((string) ($_GET['breed'] ?? ''));
$species = (string) ($_GET['species'] ?? '');
$sex = (string) ($_GET['sex'] ?? '');
$age = trim((string) ($_GET['age'] ?? ''));
$location = trim((string) ($_GET['location'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$filters = ['p.status="available"', 's.status="approved"'];
$params = [];

if ($query !== '') {
    $filters[] = '(p.name LIKE ? OR p.breed LIKE ? OR p.species LIKE ? OR s.city LIKE ?)';
    $like = '%' . $query . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($breed !== '') {
    $filters[] = 'p.breed LIKE ?';
    $params[] = '%' . $breed . '%';
}
if (in_array($species, ['dog', 'cat', 'rabbit', 'other'], true)) {
    $filters[] = 'p.species=?';
    $params[] = $species;
} else {
    $species = '';
}
if (in_array($sex, ['male', 'female', 'unknown'], true)) {
    $filters[] = 'p.sex=?';
    $params[] = $sex;
} else {
    $sex = '';
}
if ($age !== '') {
    $filters[] = 'p.age LIKE ?';
    $params[] = '%' . $age . '%';
}
if ($location !== '') {
    $filters[] = 's.city LIKE ?';
    $params[] = '%' . $location . '%';
}
$order = $sort === 'oldest' ? 'p.created_at ASC' : 'p.created_at DESC';
$sort = in_array($sort, ['newest', 'oldest'], true) ? $sort : 'newest';
$where = implode(' AND ', $filters);
$stmt = db()->prepare(
    'SELECT COUNT(*) FROM pets p JOIN shelters s ON s.id=p.shelter_id WHERE ' . $where
);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$stmt = db()->prepare(
    'SELECT p.*,s.name AS shelter_name,s.city FROM pets p
     JOIN shelters s ON s.id=p.shelter_id
     WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?'
);
$stmt->execute([...$params, $perPage, ($page - 1) * $perPage]);
$pets = $stmt->fetchAll();
$queryParams = array_filter([
    'q' => $query,
    'breed' => $breed,
    'species' => $species,
    'sex' => $sex,
    'age' => $age,
    'location' => $location,
    'sort' => $sort,
], static fn ($value) => $value !== '');
?>
<div class="section-head"><div><span class="eyebrow">Meet your match</span><h1>Find a pet</h1><p class="muted">Browse animals looking for a loving home.</p></div></div>
<form class="filters" method="get">
  <div class="field"><label for="q">Search</label><input id="q" name="q" value="<?= e($query) ?>" placeholder="Name, breed, species"></div>
  <div class="field"><label for="breed">Breed</label><input id="breed" name="breed" value="<?= e($breed) ?>" placeholder="Any breed"></div>
  <div class="field"><label for="species">Species</label><select id="species" name="species"><option value="">All</option><?php foreach (['dog', 'cat', 'rabbit', 'other'] as $value): ?><option value="<?= e($value) ?>" <?= $species === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="sex">Gender</label><select id="sex" name="sex"><option value="">All</option><?php foreach (['female', 'male', 'unknown'] as $value): ?><option value="<?= e($value) ?>" <?= $sex === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="age">Age contains</label><input id="age" name="age" value="<?= e($age) ?>" placeholder="e.g. 2 years"></div>
  <div class="field"><label for="location">City</label><input id="location" name="location" value="<?= e($location) ?>" placeholder="Cainta"></div>
  <div class="field"><label for="sort">Sort by</label><select id="sort" name="sort"><option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option></select></div>
  <button class="btn" type="submit">Apply filters</button><a class="btn btn-outline" href="<?= e(base_url('pets.php')) ?>">Clear</a>
</form>
<p class="small muted"><?= $total ?> available pet<?= $total === 1 ? '' : 's' ?></p>
<?php if (!$pets): ?>
  <div class="panel empty">No available pets match those filters. Try broadening your search.</div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($pets as $pet): ?>
      <article class="card">
        <a href="<?= e(base_url('pet_details.php?id=' . $pet['id'])) ?>"><img class="card-image" loading="lazy" src="<?= e(image_url($pet['image'])) ?>" alt="<?= e($pet['name'] . ', a ' . $pet['species'] . ' available for adoption') ?>"></a>
        <div class="card-body"><span class="badge available"><?= e($pet['species']) ?></span><h2><?= e($pet['name']) ?></h2>
          <p class="muted"><?= e($pet['breed'] ?: 'Mixed breed') ?> · <?= e($pet['age'] ?: 'Age unknown') ?> · <?= e(ucfirst($pet['sex'])) ?></p>
          <p><?= e(mb_strimwidth((string) $pet['description'], 0, 100, '...')) ?></p>
          <p class="small muted"><?= e($pet['shelter_name']) ?> · <?= e($pet['city']) ?></p>
          <a class="btn btn-small" href="<?= e(base_url('pet_details.php?id=' . $pet['id'])) ?>">View profile</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Pet listing pages"><?php if ($page > 1): ?><a class="btn btn-outline btn-small" href="<?= e(base_url('pets.php?' . http_build_query(array_merge($queryParams, ['page' => $page - 1])))) ?>">Previous</a><?php endif; ?><span class="small muted">Page <?= $page ?> of <?= $totalPages ?></span><?php if ($page < $totalPages): ?><a class="btn btn-outline btn-small" href="<?= e(base_url('pets.php?' . http_build_query(array_merge($queryParams, ['page' => $page + 1])))) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
