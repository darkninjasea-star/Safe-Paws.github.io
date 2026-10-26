<?php
require __DIR__.'/../includes/header.php';
$u = require_role('user', 'volunteer', 'shelter', 'admin');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = post('name');
    $phone = post('phone');
    $address = post('address');

    if (strlen($name) < 2) {
        $error = 'Please enter your name.';
    } else {
        db()->prepare('UPDATE users SET name=?,phone=?,address=? WHERE id=?')
            ->execute([$name, $phone, $address, $u['id']]);
        flash('Profile updated.');
        redirect('user/profile.php');
    }
}
?>
<div class="form-card panel"><h1>My profile</h1><?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><div class="field"><label>Name</label><input name="name" value="<?= e($u['name']) ?>" required></div><div class="field"><label>Email</label><input value="<?= e($u['email']) ?>" disabled></div><div class="field"><label>Phone</label><input name="phone" value="<?= e($u['phone']) ?>"></div><div class="field"><label>Address</label><input name="address" value="<?= e($u['address']) ?>"></div><button class="btn">Save profile</button></form></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
