<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? 'Safe PAWS';
$navUser = current_user();
$unreadNotificationCount = $navUser ? unread_notification_count((int) $navUser['id']) : 0;
$javascriptVersion = (string) filemtime(dirname(__DIR__) . '/assets/js/app.js');
$stylesheetVersion = (string) filemtime(dirname(__DIR__) . '/assets/css/style.css');
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$isAdminPage = $navUser && $navUser['role'] === 'admin'
    && (str_contains($scriptName, '/admin/') || str_ends_with($scriptName, '/user/notifications.php'));
$activeAdminPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if ($activeAdminPage === 'dashboard.php') {
    $activeAdminPage = 'index.php';
}
$adminNavigation = [
    ['Overview', 'admin/index.php'],
    ['Pets', 'admin/pets.php'],
    ['Applications', 'admin/applications.php'],
    ['Rescue reports', 'admin/reports.php'],
    ['Shelters', 'admin/shelters.php'],
    ['Users', 'admin/users.php'],
    ['Create Admin Account', 'admin/create_admin.php'],
    ['Admin Registration Requests', 'admin/admin_requests.php'],
    ['Volunteers', 'admin/volunteers.php'],
    ['Support', 'admin/support.php'],
    ['Notifications', 'user/notifications.php'],
];
ob_start();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Safe Paws Animal Shelter – Cainta City helps dogs and cats find loving homes and supports responsible adoption and stray animal reporting.">
  <title><?= e($pageTitle) ?> | Safe Paws Animal Shelter – Cainta City</title>
  <script>
    (function () {
      var themeKey = 'safe-paws-theme';
      var storedTheme = null;
      try {
        storedTheme = window.localStorage.getItem(themeKey);
      } catch (error) {
        storedTheme = null;
      }
      var selectedTheme = storedTheme === 'dark' ? 'dark' : 'light';
      var root = document.documentElement;
      root.dataset.theme = selectedTheme;
      root.style.colorScheme = selectedTheme;
    })();
  </script>
  <script src="<?= e(base_url('assets/js/app.js') . '?v=' . $javascriptVersion) ?>"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css') . '?v=' . $stylesheetVersion) ?>">
</head>
<body data-base-url="<?= e(base_url()) ?>">
<?php require __DIR__ . '/navbar.php'; ?>
<main class="container">
<?php foreach (flash() ?? [] as $item): ?><div class="alert <?= e($item['type']) ?>"><?= e($item['message']) ?></div><?php endforeach; ?>
<?php if ($isAdminPage): ?>
<div class="admin-shell">
  <aside class="admin-sidebar" aria-label="Administrator workspace">
    <span class="admin-sidebar-label">Workspace</span>
    <nav aria-label="Administrator tools">
      <?php foreach ($adminNavigation as [$label, $path]): ?>
        <a href="<?= e(base_url($path)) ?>" <?= $activeAdminPage === basename($path) ? 'aria-current="page"' : '' ?>>
          <?= e($label) ?>
          <?php if ($path === 'user/notifications.php' && $unreadNotificationCount > 0): ?><span class="sidebar-notification-count"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="admin-content">
<?php endif; ?>
