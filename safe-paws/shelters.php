<?php
require __DIR__ . '/includes/header.php';
$stmt = db()->query(
    'SELECT s.*,COUNT(p.id) AS pet_count
     FROM shelters s
     LEFT JOIN pets p ON p.shelter_id=s.id AND p.status="available"
     WHERE s.status="approved"
     GROUP BY s.id
     ORDER BY s.name'
);
$shelters = $stmt->fetchAll();
?>
<div class="section-head"><div><h1>Our shelters</h1><p class="muted">Meet the teams caring for animals in your community.</p></div></div>
<?php if (!$shelters): ?><div class="panel empty">No approved shelters are listed right now.</div><?php else: ?>
  <div class="grid">
    <?php foreach ($shelters as $shelter): ?>
      <article class="card">
        <img class="shelter-photo directory-shelter-photo" src="<?= e(image_url($shelter['image'])) ?>" alt="<?= e($shelter['name'] . ' shelter photo') ?>">
        <div class="card-body">
          <h3><?= e($shelter['name']) ?></h3>
          <p class="muted"><?= e($shelter['city']) ?> &middot; <?= e($shelter['pet_count']) ?> available pets</p>
          <?php if ($shelter['description']): ?><p><?= e(mb_strimwidth((string) $shelter['description'], 0, 110, '...')) ?></p><?php endif; ?>
          <?php if ($shelter['email']): ?><p class="small"><a href="mailto:<?= e($shelter['email']) ?>"><?= e($shelter['email']) ?></a></p><?php endif; ?>
          <?php if ($shelter['phone']): ?><p class="small"><a href="tel:<?= e($shelter['phone']) ?>"><?= e($shelter['phone']) ?></a></p><?php endif; ?>
          <a class="btn btn-small" href="<?= e(base_url('shelter_details.php?id=' . $shelter['id'])) ?>">View shelter</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
