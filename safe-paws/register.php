<?php
require __DIR__ . '/includes/header.php';
if (current_user()) {
    redirect(dashboard_url(current_user()));
}

$error = '';
$name = '';
$email = '';
$phone = '';
$accountType = 'user';
$purpose = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = post('name');
    $email = strtolower(post('email'));
    $phone = post('phone');
    $accountType = post('account_type');
    $purpose = post('purpose');
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $attemptKey = registration_attempt_key($email);
    $lockedFor = registration_lock_seconds($attemptKey);

    if ($lockedFor > 0) {
        $error = 'Too many unsuccessful attempts. Please wait ' . (int) ceil($lockedFor / 60) . ' minute(s) before trying again.';
    } else {
        $error = match (true) {
            strlen($name) < 2 || strlen($name) > 120 => 'Enter a name between 2 and 120 characters.',
            !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 => 'Enter a valid email address.',
            strlen($phone) > 30 => 'Phone number must be 30 characters or fewer.',
            !in_array($accountType, ['user', 'admin'], true) => 'Choose a valid account type.',
            $password !== $confirmation => 'The passwords do not match.',
            strlen($password) < 8 || strlen($password) > 1024 => 'Use a password between 8 and 1,024 characters.',
            $accountType === 'admin' && (strlen($purpose) < 10 || strlen($purpose) > 255) => 'Administrator requests need a purpose between 10 and 255 characters.',
            default => '',
        };

        if ($error !== '') {
            record_registration_failure($attemptKey);
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $status = $accountType === 'admin' ? 'pending_admin_approval' : 'active';
                $role = $accountType === 'admin' ? null : $accountType;
                $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role,phone,status) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $phone ?: null, $status]);
                $userId = (int) $pdo->lastInsertId();

                if ($accountType === 'admin') {
                    $request = $pdo->prepare(
                        'INSERT INTO admin_requests (user_id,full_name,email,purpose) VALUES (?,?,?,?)'
                    );
                    $request->execute([$userId, $name, $email, $purpose]);
                    $requestId = (int) $pdo->lastInsertId();
                    activity_log($userId, 'requested_admin_access', 'admin_request', $requestId);
                    notify_admins(
                        'admin_request:submitted:' . $requestId,
                        'Admin registration request',
                        'A new administrator access request is awaiting review.',
                        'admin/admin_requests.php',
                        'admin_request',
                        $requestId
                    );
                } else {
                    sync_user_role_assignment($pdo, $userId, 'user');
                    activity_log($userId, 'registered', 'user', $userId);
                    notify_admins(
                        'user:registered:' . $userId,
                        'New user registration',
                        'A new Safe PAWS user account has been created.',
                        'admin/users.php',
                        'user',
                        $userId
                    );
                }
                $pdo->commit();
                clear_registration_attempts($attemptKey);

                if ($accountType === 'admin') {
                    flash('Your administrator access request was sent for review. You can sign in after it is approved.', 'success');
                    redirect('login.php');
                }

                login_user(['id' => $userId]);
                flash('Your account is ready!');
                redirect('user/index.php');
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                record_registration_failure($attemptKey);
                if ($exception->getCode() === '23000') {
                    $error = 'That email is already registered.';
                } else {
                    error_log('Safe PAWS registration failed: ' . $exception->getMessage());
                    $error = 'Could not create the account. Please try again later.';
                }
            }
        }
    }
}
?>
<div class="form-card panel">
  <h1>Join Safe PAWS</h1>
  <p class="muted">Create an adopter account or request administrator access.</p>
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label for="name">Full name</label><input id="name" name="name" value="<?= e($name) ?>" minlength="2" maxlength="120" autocomplete="name" required></div>
    <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= e($email) ?>" maxlength="190" autocomplete="email" required></div>
    <div class="field"><label for="phone">Phone (optional)</label><input id="phone" name="phone" value="<?= e($phone) ?>" maxlength="30" autocomplete="tel"></div>
    <div class="field">
      <label for="account_type">Account type</label>
      <select id="account_type" name="account_type">
        <option value="user" <?= $accountType === 'user' ? 'selected' : '' ?>>User (Adopter)</option>
        <option value="admin" <?= $accountType === 'admin' ? 'selected' : '' ?>>Admin (request approval)</option>
      </select>
    </div>
    <div class="field" id="admin-purpose" <?= $accountType === 'admin' ? '' : 'hidden' ?>>
      <label for="purpose">Why do you need administrator access?</label>
      <textarea id="purpose" name="purpose" minlength="10" maxlength="255"><?= e($purpose) ?></textarea>
      <p class="small muted">Administrator accounts remain locked until an existing administrator approves the request.</p>
    </div>
    <div class="form-grid">
      <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" minlength="8" maxlength="1024" autocomplete="new-password" required></div>
      <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" minlength="8" maxlength="1024" autocomplete="new-password" required></div>
    </div>
    <button class="btn" type="submit">Create account</button>
  </form>
</div>
<script>
var accountType = document.getElementById('account_type');
var phoneField = document.getElementById('phone').closest('.field');
function updateRegistrationFields() {
  var purpose = document.getElementById('purpose');
  var isAdmin = accountType.value === 'admin';
  document.getElementById('admin-purpose').hidden = !isAdmin;
  phoneField.hidden = isAdmin;
  purpose.required = isAdmin;
}
accountType.addEventListener('change', updateRegistrationFields);
updateRegistrationFields();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
