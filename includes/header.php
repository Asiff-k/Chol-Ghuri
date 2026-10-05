<?php
/**
 * header.php
 * ----------
 * Top of every page: <head>, fonts/CSS and the ONE navigation bar.
 *
 * Usage (after the page has done its PHP work):
 *     $pageTitle = 'Log In';
 *     require_once __DIR__ . '/includes/header.php';
 *
 * Optional variables a page can set before including this file:
 *     $activeNav  - which menu item to underline, e.g. 'groups.php'
 *     $bodyClass  - extra CSS class on <body>, e.g. 'bg-white'
 */

require_once __DIR__ . '/bootstrap.php';

$me         = current_user();
$fullTitle  = isset($pageTitle) ? $pageTitle . ' | ' . APP_NAME : APP_NAME . ' | Travel together with fellow students';
$activeNav  = $activeNav ?? basename($_SERVER['SCRIPT_NAME']);

$navItems = [
    'index.php'        => 'Explore',
    'groups.php'       => 'Find Groups',
    'destinations.php' => 'Destinations',
];
if ($me && $me['role'] === 'student') {
    $navItems['my-trips.php'] = 'My Trips';
}
if ($me && $me['role'] === 'admin') {
    $navItems['admin/index.php'] = 'Admin';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($fullTitle) ?></title>
    <link rel="icon" href="<?= url('assets/images/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">

<header class="site-header">
    <div class="container nav">
        <a href="<?= url($me && $me['role'] === 'student' ? 'dashboard.php' : 'index.php') ?>" class="logo"><?= e(APP_NAME) ?></a>

        <button class="nav-toggle" type="button" aria-label="Open menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>

        <div class="nav-menu">
            <nav class="nav-links" aria-label="Main">
                <?php foreach ($navItems as $file => $label): ?>
                    <a href="<?= url($file) ?>" class="<?= $activeNav === $file ? 'active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="nav-actions">
                <?php if ($me): ?>
                    <?php if ($me['role'] === 'student'): ?>
                        <a href="<?= url('create-group.php') ?>" class="btn btn-accent btn-sm">Start a Trip</a>
                    <?php endif; ?>

                    <details class="user-menu">
                        <summary aria-label="Account menu">
                            <?= avatar($me['profile_photo'], $me['full_name'], 'sm') ?>
                            <?= icon('chevron-down', 'icon-sm') ?>
                        </summary>
                        <div class="user-menu-panel">
                            <div class="user-menu-head">
                                <strong><?= e($me['full_name']) ?></strong>
                                <span><?= e($me['email']) ?></span>
                            </div>
                            <?php if ($me['role'] === 'student'): ?>
                                <a href="<?= url('dashboard.php') ?>"><?= icon('layout-dashboard', 'icon-sm') ?> Dashboard</a>
                                <a href="<?= url('profile.php') ?>"><?= icon('user', 'icon-sm') ?> My Profile</a>
                                <a href="<?= url('my-trips.php') ?>"><?= icon('map', 'icon-sm') ?> My Trips</a>
                                <a href="<?= url('edit-profile.php') ?>"><?= icon('pencil', 'icon-sm') ?> Edit Profile</a>
                            <?php else: ?>
                                <a href="<?= url('admin/index.php') ?>"><?= icon('settings', 'icon-sm') ?> Admin Panel</a>
                            <?php endif; ?>
                            <a href="<?= url('change-password.php') ?>"><?= icon('key-round', 'icon-sm') ?> Change Password</a>
                            <form method="post" action="<?= url('logout.php') ?>">
                                <?= csrf_field() ?>
                                <button type="submit"><?= icon('log-out', 'icon-sm') ?> Log Out</button>
                            </form>
                        </div>
                    </details>
                <?php else: ?>
                    <a href="<?= url('login.php') ?>" class="nav-login <?= $activeNav === 'login.php' ? 'active' : '' ?>">Log In</a>
                    <a href="<?= url('register.php') ?>" class="btn btn-primary btn-sm">Create Account</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<main class="site-main">
