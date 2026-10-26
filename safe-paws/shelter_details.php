<?php
require __DIR__ . '/includes/header.php';
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM shelters WHERE id=? AND status="approved"');
$stmt->execute([$id]);
$shelter = $stmt->fetch();
if (!$shelter) {
    http_response_code(404);
    echo '<div class="panel empty">Shelter not found.</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$location = implode(', ', array_filter(
    [$shelter['address'], $shelter['city']],
    static fn($value) => $value !== null && $value !== ''
));
$stmt = db()->prepare('SELECT * FROM pets WHERE shelter_id=? AND status="available" ORDER BY name');
$stmt->execute([$id]);
$pets = $stmt->fetchAll();
?>
<div class="hero-strip shelter-detail">
  <img class="shelter-photo shelter-detail-photo" src="<?= e(image_url($shelter['image'])) ?>" alt="<?= e($shelter['name'] . ' shelter photo') ?>">
  <div>
    <h1><?= e($shelter['name']) ?></h1>
    <?php if ($shelter['description']): ?><p><?= nl2br(e($shelter['description'])) ?></p><?php endif; ?>
    <?php if ($location): ?><p><strong><?= e($location) ?></strong></p><?php endif; ?>
    <?php if ($shelter['email']): ?><p><a href="mailto:<?= e($shelter['email']) ?>"><?= e($shelter['email']) ?></a></p><?php endif; ?>
    <?php if ($shelter['phone']): ?><p><a href="tel:<?= e($shelter['phone']) ?>"><?= e($shelter['phone']) ?></a></p><?php endif; ?>
  </div>
</div>
<div class="section-head"><h2>Pets at this shelter</h2></div>
<?php if (!$pets): ?><div class="panel empty">No pets are currently listed.</div><?php else: ?>
  <div class="grid">
    <?php foreach ($pets as $pet): ?>
      <article class="card">
        <img class="card-image" src="<?= e(image_url($pet['image'])) ?>" alt="<?= e($pet['name'] . ', a ' . $pet['species'] . ' available for adoption') ?>">
        <div class="card-body"><h3><?= e($pet['name']) ?></h3><p class="muted"><?= e($pet['breed']) ?> &middot; <?= e($pet['age']) ?></p><a href="<?= e(base_url('pet_details.php?id=' . $pet['id'])) ?>">View profile &rarr;</a></div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
