<?php
$pageTitle = 'A safe home for every paw';
require __DIR__ . '/includes/header.php';
$pdo = db();
$stats = [
    'available' => (int) $pdo->query('SELECT COUNT(*) FROM pets WHERE status="available"')->fetchColumn(),
    'adopted' => (int) $pdo->query('SELECT COUNT(*) FROM pets WHERE status="adopted"')->fetchColumn(),
    'shelters' => (int) $pdo->query('SELECT COUNT(*) FROM shelters WHERE status="approved"')->fetchColumn(),
    'reports' => (int) $pdo->query('SELECT COUNT(*) FROM stray_reports')->fetchColumn(),
];
$featured = $pdo->query(
    'SELECT p.*,s.name AS shelter_name,s.city FROM pets p
     JOIN shelters s ON s.id=p.shelter_id
     WHERE p.status="available" AND s.status="approved"
     ORDER BY p.created_at DESC LIMIT 3'
)->fetchAll();
?>
<section class="hero">
  <div class="hero-copy">
    <span class="eyebrow">Rescue · Care · Belong</span>
    <h1>Every paw deserves a <em>safe home.</em></h1>
    <p>Meet a companion, report an animal in need, or join the Cainta community helping pets find care and a family.</p>
    <div class="actions"><a class="btn" href="<?= e(base_url('pets.php')) ?>">Find your new best friend</a><a class="btn btn-outline" href="<?= e(base_url('report_stray.php')) ?>">Report a stray</a></div>
    <p class="hero-note">Safe Paws Animal Shelter – Cainta City</p>
  </div>
  <div class="hero-art"><img src="<?= e(base_url('assets/images/safe-paws-hero.jpg')) ?>" alt="A dog and cat wearing sunglasses"></div>
</section>
<section class="stats stats-public" aria-label="Safe Paws community statistics">
  <div class="stat"><strong><?= $stats['available'] ?></strong><span>Pets looking for homes</span></div>
  <div class="stat"><strong><?= $stats['adopted'] ?></strong><span>Marked adopted</span></div>
  <div class="stat"><strong><?= $stats['shelters'] ?></strong><span>Approved shelters</span></div>
  <div class="stat"><strong><?= $stats['reports'] ?></strong><span>Community reports</span></div>
</section>
<section class="content-section">
  <div class="section-head"><div><span class="eyebrow">A friend is waiting</span><h2>Meet a few of our pets</h2></div><a href="<?= e(base_url('pets.php')) ?>">Browse all pets <span aria-hidden="true">→</span></a></div>
  <?php if (!$featured): ?><div class="panel empty">No pets are listed right now. Check back soon, or help a local rescue by reporting a stray.</div><?php else: ?>
    <div class="grid"><?php foreach ($featured as $pet): ?><article class="card"><a href="<?= e(base_url('pet_details.php?id=' . $pet['id'])) ?>"><img class="card-image" src="<?= e(image_url($pet['image'])) ?>" alt="<?= e($pet['name'] . ', a ' . $pet['species'] . ' available for adoption') ?>"></a><div class="card-body"><span class="badge available"><?= e($pet['species']) ?></span><h3><?= e($pet['name']) ?></h3><p class="muted"><?= e($pet['breed'] ?: 'Mixed breed') ?> · <?= e($pet['age'] ?: 'Age unknown') ?></p><p class="small muted"><?= e($pet['shelter_name']) ?> · <?= e($pet['city']) ?></p><a class="btn btn-small" href="<?= e(base_url('pet_details.php?id=' . $pet['id'])) ?>">Meet <?= e($pet['name']) ?></a></div></article><?php endforeach; ?></div>
  <?php endif; ?>
</section>
<section class="content-section process-section">
  <div class="section-head"><div><span class="eyebrow">A thoughtful start</span><h2>How adoption works</h2></div></div>
  <div class="grid process-grid"><article class="panel"><span class="step-number">01</span><h3>Find your match</h3><p>Explore available pets and learn about their needs, personality, and shelter.</p></article><article class="panel"><span class="step-number">02</span><h3>Apply with care</h3><p>Tell the shelter about your home and experience so they can make a responsible match.</p></article><article class="panel"><span class="step-number">03</span><h3>Welcome them home</h3><p>Track your application and hear updates from the team reviewing it.</p></article></div>
</section>
<section class="community-callout"><div><span class="eyebrow">Neighbors helping neighbors</span><h2>See an animal who needs help?</h2><p>Share the location and details. Safe Paws can connect your report with local volunteers and shelters.</p></div><a class="btn btn-light" href="<?= e(base_url('report_stray.php')) ?>">Make a report</a></section>
<section class="content-section"><div class="section-head"><div><span class="eyebrow">Good things grow together</span><h2>Ways to be part of the rescue</h2></div></div><div class="grid"><article class="panel"><h3>Partner shelters</h3><p class="muted">Share responsible pet listings and review adoption applications with a dedicated shelter dashboard.</p><a href="<?= e(base_url('shelters.php')) ?>">Meet our shelters →</a></article><article class="panel"><h3>Volunteer support</h3><p class="muted">Help coordinate sightings, transport, and care through assigned rescue reports.</p><a href="<?= e(base_url('register.php')) ?>">Join the community →</a></article><article class="panel"><h3>Everyday kindness</h3><p class="muted">A clear report and a little time can help a lost animal get the attention it needs.</p><a href="<?= e(base_url('report_stray.php')) ?>">Report an animal →</a></article></div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
