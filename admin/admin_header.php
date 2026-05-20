<?php
// admin_header.php — shared admin layout header
// Requires: $pageTitle, $adminPage (for active nav highlight)
// db_connect, functions, auth_check must already be required before this.

$pageTitle = isset($pageTitle) ? 'Admin – ' . $pageTitle : 'Admin – DysonConnect';
$adminPage = $adminPage ?? '';
$flash     = getFlash();
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
<body class="admin-body">

<div class="admin-layout">

    <!-- SIDEBAR -->
    <aside class="admin-sidebar">
        <div class="admin-sidebar-brand">
            <a href="<?= BASE_URL ?>admin/dashboard.php">
                <img src="<?= BASE_URL ?>assets/images/logo.png" alt="DysonConnect" class="admin-sidebar-logo">
            </a>
            <span class="admin-sidebar-label">Admin Panel</span>
        </div>

        <nav class="admin-nav">
            <a href="<?= BASE_URL ?>admin/dashboard.php"
               class="admin-nav-item <?= $adminPage === 'dashboard' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
            <a href="<?= BASE_URL ?>admin/bookings.php"
               class="admin-nav-item <?= $adminPage === 'bookings' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Bookings
            </a>
            <a href="<?= BASE_URL ?>admin/buses.php"
               class="admin-nav-item <?= $adminPage === 'buses' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                Buses
            </a>
            <a href="<?= BASE_URL ?>admin/routes.php"
               class="admin-nav-item <?= $adminPage === 'routes' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                Routes
            </a>
            <a href="<?= BASE_URL ?>admin/schedules.php"
               class="admin-nav-item <?= $adminPage === 'schedules' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
                Schedules
            </a>
            <a href="<?= BASE_URL ?>admin/drivers.php"
               class="admin-nav-item <?= $adminPage === 'drivers' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Drivers
            </a>
            <a href="<?= BASE_URL ?>admin/reports.php"
               class="admin-nav-item <?= $adminPage === 'reports' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Reports
            </a>
            <a href="<?= BASE_URL ?>admin/users.php"
               class="admin-nav-item <?= $adminPage === 'users' ? 'admin-nav-item--active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Customers
            </a>
        </nav>

        <div class="admin-sidebar-footer">
            <a href="<?= BASE_URL ?>index.php" class="admin-nav-item">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                View Site
            </a>
            <a href="<?= BASE_URL ?>logout.php" class="admin-nav-item admin-nav-item--danger">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign Out
            </a>
            <div class="admin-user-info">
                <span class="admin-user-name"><?= e(currentUserName()) ?></span>
                <span class="admin-user-role">Administrator</span>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="admin-main">

        <!-- Top bar -->
        <div class="admin-topbar">
            <span class="admin-topbar-title"><?= e($pageTitle) ?></span>
            <div class="admin-topbar-right">
                <span class="admin-topbar-user">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?= e(currentUserName()) ?>
                </span>
            </div>
        </div>

        <!-- Flash message -->
        <?php if ($flash): ?>
        <div style="padding: 0 28px; padding-top: 16px;">
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        </div>
        <?php endif; ?>

        <div class="admin-content">
