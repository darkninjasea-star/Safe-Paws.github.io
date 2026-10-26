<?php
require __DIR__ . '/includes/functions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
verify_csrf();
$user = current_user();
if ($user) {
    activity_log((int) $user['id'], 'logout', 'user', (int) $user['id']);
}
logout_user();
redirect('index.php');
