<?php
require __DIR__ . '/includes/header.php';
$user = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!is_string($user['password']) || !password_verify($currentPassword, $user['password'])) {
        $error = 'Your current password is incorrect.';
    } elseif (strlen($newPassword) < 12 || strlen($newPassword) > 72) {
        $error = 'Your new password must be between 12 and 72 bytes long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new password confirmation does not match.';
    } elseif (password_verify($newPassword, $user['password'])) {
        $error = 'Choose a new password that is different from your current password.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newHash === false) {
            throw new RuntimeException('Unable to securely hash the new password.');
        }
        db()->prepare('UPDATE users SET password=?, must_change_password=0 WHERE id=?')
            ->execute([$newHash, (int) $user['id']]);
        activity_log((int) $user['id'], 'password_changed', 'user', (int) $user['id']);
        flash('Your password has been updated.');
        redirect(dashboard_url($user));
    }
}
?>
<section class="form-card panel">
  <h1><?= !empty($user['must_change_password']) ? 'Set a new password' : 'Change password' ?></h1>
  <?php if (!empty($user['must_change_password'])): ?>
    <p class="muted">This development account requires a new password before you can continue.</p>
  <?php else: ?>
    <p class="muted">Use a unique password with at least 12 characters.</p>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label for="current-password">Current password</label><input id="current-password" type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><label for="new-password">New password</label><input id="new-password" type="password" name="new_password" minlength="12" maxlength="72" required autocomplete="new-password"></div>
    <div class="field"><label for="confirm-password">Confirm new password</label><input id="confirm-password" type="password" name="confirm_password" minlength="12" maxlength="72" required autocomplete="new-password"></div>
    <button class="btn" type="submit">Update password</button>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
