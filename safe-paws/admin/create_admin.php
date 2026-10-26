<?php
require __DIR__ . '/../includes/header.php';
$admin = require_role('admin');

$error = '';
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = post('name');
    $email = strtolower(post('email'));
    $password = (string) ($_POST['password'] ?? '');

    $error = match (true) {
        strlen($name) < 2 || strlen($name) > 120 => 'Enter a name between 2 and 120 characters.',
        !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 => 'Enter a valid email address.',
        strlen($password) < 8 || strlen($password) > 1024 => 'Use a password between 8 and 1,024 characters.',
        default => '',
    };

    if ($error === '') {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email=? FOR UPDATE');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new RuntimeException('That email is already registered.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,"admin","active")'
            );
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            sync_user_role_assignment($pdo, $userId, 'admin', (int) $admin['id']);
            activity_log((int) $admin['id'], 'created', 'admin_account', $userId);
            $pdo->commit();

            flash('Admin account created and approved. The new administrator can now sign in.');
            redirect('admin/create_admin.php');
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $error = 'That email is already registered.';
            } else {
                error_log('Safe PAWS admin account creation failed: ' . $exception->getMessage());
                $error = 'Could not create the admin account. Please try again.';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Safe PAWS admin account creation failed: ' . $exception->getMessage());
            $error = 'Could not create the admin account. Please try again.';
        }
    }
}
?>
<div class="section-head"><div><h1>Create Admin Account</h1><p class="muted">Only authorized administrators can create an approved admin account directly.</p></div></div>
<div class="form-card panel">
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label for="admin-name">Full Name</label><input id="admin-name" name="name" value="<?= e($name) ?>" minlength="2" maxlength="120" autocomplete="name" required></div>
    <div class="field"><label for="admin-email">Email</label><input id="admin-email" type="email" name="email" value="<?= e($email) ?>" maxlength="190" autocomplete="email" required></div>
    <div class="field"><label for="admin-password">Password</label><input id="admin-password" type="password" name="password" minlength="8" maxlength="1024" autocomplete="new-password" required></div>
    <p class="small muted">This account will be created with the Admin role and approved status.</p>
    <button class="btn" type="submit">Create Admin Account</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
