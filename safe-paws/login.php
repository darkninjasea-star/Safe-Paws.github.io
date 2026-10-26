<?php
require __DIR__ . '/includes/header.php';
if ($user = current_user()) {
    redirect(dashboard_url($user));
}
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(post('email'));
    $attemptKey = login_attempt_key($email);
    $lockedFor = login_lock_seconds($attemptKey);
    if ($lockedFor > 0) {
        $error = 'Too many unsuccessful attempts. Please wait ' . (int) ceil($lockedFor / 60) . ' minute(s) before trying again.';
    } else {
        $stmt = db()->prepare(
            'SELECT u.*,r.status AS admin_request_status FROM users u
             LEFT JOIN admin_requests r ON r.user_id=u.id WHERE u.email=?
             ORDER BY r.requested_at DESC LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $passwordIsValid = $user && is_string($user['password'])
            && password_verify((string) ($_POST['password'] ?? ''), $user['password']);
        $isAuthorizedRole = $passwordIsValid
            && in_array($user['role'], ['user', 'volunteer', 'shelter', 'admin'], true);
        if ($isAuthorizedRole && $user['status'] === 'active') {
            clear_login_attempts($attemptKey);
            db()->prepare('UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
            login_user($user);
            activity_log((int) $user['id'], 'login', 'user', (int) $user['id']);
            flash('Welcome back, ' . $user['name'] . '!');
            redirect(dashboard_url($user));
        }
        record_login_failure($attemptKey);
        $error = 'Email or password is incorrect.';
        if ($passwordIsValid && $user['admin_request_status'] === 'pending') {
            $error = 'Your administrator registration is pending approval. You can sign in after an administrator approves it.';
        } elseif ($passwordIsValid && $user['admin_request_status'] === 'rejected') {
            $error = 'Your administrator registration was not approved. Contact an administrator if you need help.';
        } elseif ($passwordIsValid && $user['status'] !== 'active') {
            $error = 'This account is inactive. Contact an administrator for assistance.';
        } elseif ($passwordIsValid && !$isAuthorizedRole) {
            $error = 'This account does not have an authorized role. Contact an administrator for assistance.';
        }
    }
}
?>
<div class="form-card panel"><h1>Welcome back</h1><p class="muted">Sign in to manage reports, applications and more.</p><?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= e($email) ?>" required autocomplete="email"></div><div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div><button class="btn" type="submit">Log in</button></form><p class="small">No account? <a href="register.php">Create one</a></p></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
