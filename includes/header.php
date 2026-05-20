<?php
// header.php — site header, nav, session, flash output
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth_check.php';

$pageTitle = isset($pageTitle) ? 'DysonConnect | ' . $pageTitle : 'DysonConnect';
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
</head>
<body>

<header class="site-header" id="site-header">
    <div class="container header-inner">

        <a href="<?= BASE_URL ?>index.php" class="site-logo">
            <img src="<?= BASE_URL ?>assets/images/logo.png" alt="DysonConnect" class="logo-img">
        </a>

        <nav class="site-nav" id="site-nav">
            <a href="<?= BASE_URL ?>index.php">Home</a>
            <a href="<?= BASE_URL ?>routes.php">Routes</a>
            <a href="<?= BASE_URL ?>search.php">Book a Trip</a>

            <?php if (isLoggedIn()): ?>
                <a href="<?= BASE_URL ?>my_bookings.php">My Bookings</a>
                <?php
                $unreadNotif = countUnreadNotifications($conn, currentUserId());
                ?>
                <a href="<?= BASE_URL ?>notifications.php" class="nav-notif-link" title="Notifications">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <?php if ($unreadNotif > 0): ?>
                        <span class="nav-notif-badge"><?= $unreadNotif ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= BASE_URL ?>profile.php">My Account</a>
                <?php if (isAdmin()): ?>
                    <a href="<?= BASE_URL ?>admin/dashboard.php" class="btn btn-admin">Admin</a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>logout.php" class="btn btn-nav-outline">Sign Out</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>login.php" class="btn btn-nav-outline">Log In</a>
                <a href="<?= BASE_URL ?>register.php" class="btn btn-nav-primary">Register</a>
            <?php endif; ?>
        </nav>

        <button class="nav-toggle" id="nav-toggle" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>

    </div>
</header>

<main class="site-main">

    <?php if ($flash): ?>
    <div class="flash-wrap">
        <div class="container">
            <div class="alert alert-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
