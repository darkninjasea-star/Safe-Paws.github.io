<?php
require __DIR__ . '/../includes/header.php';
$user = require_login();
$stmt = db()->prepare(
    'SELECT id,animal_type,urgency,location,description,image,status,staff_notes,created_at
     FROM stray_reports WHERE reporter_id=? ORDER BY created_at DESC'
);
$stmt->execute([$user['id']]);
$reports = $stmt->fetchAll();
?>
<div class="section-head"><div><h1>Your stray reports</h1><p class="muted">Follow updates from the rescue team.</p></div><a class="btn btn-small" href="<?= e(base_url('report_stray.php')) ?>">Report another stray</a></div>
<?php if (!$reports): ?><div class="panel empty">You have not submitted any reports while signed in. <a href="<?= e(base_url('report_stray.php')) ?>">Report an animal</a>.</div><?php else: ?>
  <div class="grid">
    <?php foreach ($reports as $report): ?>
      <article class="panel">
        <span class="badge <?= e($report['status']) ?>"><?= e(str_replace('_', ' ', $report['status'])) ?></span>
        <h2><?= e($report['location']) ?></h2>
        <?php if ($report['image']): ?><img class="card-image" src="<?= e(image_url($report['image'])) ?>" alt="Photo attached to your report"><?php endif; ?>
        <p><?= nl2br(e($report['description'])) ?></p>
        <p class="small muted"><?= e($report['animal_type']) ?> · <?= e($report['urgency']) ?> urgency · Submitted <?= e(date('M j, Y', strtotime($report['created_at']))) ?></p>
        <?php if ($report['staff_notes']): ?><div class="notice small"><?= nl2br(e($report['staff_notes'])) ?></div><?php endif; ?>
        <p class="small muted">Reference: SPR-<?= e(str_pad((string) $report['id'], 6, '0', STR_PAD_LEFT)) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
