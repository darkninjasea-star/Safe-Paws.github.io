<?php
require __DIR__ . '/includes/header.php';
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT p.*,s.name AS shelter_name,s.city,s.id AS shelter_id,s.phone AS shelter_phone,s.status AS shelter_status
     FROM pets p JOIN shelters s ON s.id=p.shelter_id WHERE p.id=?'
);
$stmt->execute([$id]);
$pet = $stmt->fetch();
if (!$pet) {
    http_response_code(404);
    echo '<div class="panel empty">Pet not found.</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$stmt = db()->prepare(
    'SELECT p.id,p.name,p.species,p.breed,p.age,p.image,s.city
     FROM pets p JOIN shelters s ON s.id=p.shelter_id
     WHERE p.id<>? AND p.status="available" AND s.status="approved"
     ORDER BY (p.species=?) DESC,p.created_at DESC LIMIT 3'
);
$stmt->execute([$id, $pet['species']]);
$relatedPets = $stmt->fetchAll();
?>
<div class="detail">
  <div><img src="<?= e(image_url($pet['image'])) ?>" alt="<?= e($pet['name'] . ', a ' . $pet['species']) ?>"></div>
  <div>
    <span class="badge <?= e($pet['status']) ?>"><?= e(str_replace('_', ' ', $pet['status'])) ?></span>
    <h1><?= e($pet['name']) ?></h1>
    <p class="muted"><?= e($pet['breed'] ?: 'Mixed breed') ?> · <?= e($pet['species']) ?></p>
    <p><?= nl2br(e($pet['description'] ?: 'This pet is waiting to meet you.')) ?></p>
    <dl>
      <dt><strong>Age</strong></dt><dd><?= e($pet['age'] ?: 'Unknown') ?></dd>
      <dt><strong>Gender</strong></dt><dd><?= e(ucfirst($pet['sex'])) ?></dd>
      <dt><strong>Color</strong></dt><dd><?= e($pet['color'] ?: 'Unknown') ?></dd>
      <dt><strong>Shelter</strong></dt><dd><a href="<?= e(base_url('shelter_details.php?id=' . $pet['shelter_id'])) ?>"><?= e($pet['shelter_name']) ?></a>, <?= e($pet['city']) ?></dd>
    </dl>
    <?php if ($pet['status'] === 'available' && $pet['shelter_status'] === 'approved'): ?>
      <div class="actions"><a class="btn" href="<?= e(base_url('adoption/application.php?pet_id=' . $pet['id'])) ?>">Apply to adopt</a></div>
    <?php else: ?>
      <div class="notice">This pet is not currently accepting adoption applications.</div>
    <?php endif; ?>
  </div>
</div>
<?php if ($relatedPets): ?>
  <section class="content-section">
    <div class="section-head"><h2>More pets to meet</h2><a href="<?= e(base_url('pets.php')) ?>">Browse all pets →</a></div>
    <div class="grid"><?php foreach ($relatedPets as $related): ?>
      <article class="card"><a href="<?= e(base_url('pet_details.php?id=' . $related['id'])) ?>"><img class="card-image" src="<?= e(image_url($related['image'])) ?>" alt="<?= e($related['name']) ?>"></a><div class="card-body"><span class="badge available"><?= e($related['species']) ?></span><h3><?= e($related['name']) ?></h3><p class="muted"><?= e($related['breed'] ?: 'Mixed breed') ?> · <?= e($related['age'] ?: 'Age unknown') ?> · <?= e($related['city']) ?></p><a class="btn btn-small" href="<?= e(base_url('pet_details.php?id=' . $related['id'])) ?>">View profile</a></div></article>
    <?php endforeach; ?></div>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
