<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

requireLogin();

$userId = currentUserId();

// Fetch current user from DB (don't rely solely on session)
$stmt = $conn->prepare("
    SELECT user_id, full_name, email, phone, role, created_at
    FROM   users
    WHERE  user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // Shouldn't happen, but guard it
    logoutUser();
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$errors        = [];
$pwErrors      = [];
$activeSection = 'profile'; // which form is active (for UX)

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newName  = trim($_POST['full_name'] ?? '');
    $newPhone = trim($_POST['phone']     ?? '');

    if (empty($newName)) {
        $errors[] = 'Full name is required.';
    } elseif (strlen($newName) < 2) {
        $errors[] = 'Full name must be at least 2 characters.';
    } elseif (strlen($newName) > 120) {
        $errors[] = 'Full name is too long (max 120 characters).';
    }

    if (!empty($newPhone) && !preg_match('/^[0-9+\s\-]{7,20}$/', $newPhone)) {
        $errors[] = 'Phone number format is not valid.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
        $stmt->bind_param('ssi', $newName, $newPhone, $userId);
        if ($stmt->execute()) {
            $_SESSION['full_name'] = $newName;
            $user['full_name'] = $newName;
            $user['phone']     = $newPhone;
            setFlash('success', 'Profile updated successfully.');
            $stmt->close();
            header('Location: ' . BASE_URL . 'profile.php');
            exit;
        } else {
            $errors[] = 'Update failed. Please try again.';
            $stmt->close();
        }
    }
    $user['full_name'] = $newName;
    $user['phone']     = $newPhone;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $activeSection = 'password';
    $currentPw = $_POST['current_password'] ?? '';
    $newPw     = $_POST['new_password']     ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    // Verify current password against DB hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!password_verify($currentPw, $row['password'])) {
        $pwErrors[] = 'Current password is incorrect.';
    }
    if (strlen($newPw) < 8) {
        $pwErrors[] = 'New password must be at least 8 characters.';
    }
    if ($newPw !== $confirmPw) {
        $pwErrors[] = 'New passwords do not match.';
    }
    if ($newPw === $currentPw) {
        $pwErrors[] = 'New password must be different from your current password.';
    }

    if (empty($pwErrors)) {
        $hash = password_hash($newPw, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param('si', $hash, $userId);
        if ($stmt->execute()) {
            setFlash('success', 'Password changed successfully.');
            $stmt->close();
            header('Location: ' . BASE_URL . 'profile.php');
            exit;
        } else {
            $pwErrors[] = 'Failed to update password. Please try again.';
            $stmt->close();
        }
    }
}

// Booking count for profile stats
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM bookings WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$bookingCount = (int) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS active
    FROM   bookings
    WHERE  user_id = ? AND booking_status = 'Confirmed'
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$activeCount = (int) $stmt->get_result()->fetch_assoc()['active'];
$stmt->close();

$pageTitle = 'My Profile';
require_once 'includes/header.php';
?>

<div class="page-hero page-hero--blue">
    <div class="container">
        <h1 class="page-hero-title">My Profile</h1>
        <p class="page-hero-sub">Manage your account details and view your booking history.</p>
    </div>
</div>

<section class="profile-section">
    <div class="container profile-layout">

        <!-- LEFT: Profile Form -->
        <div class="profile-main">

            <!-- Account overview card -->
            <div class="profile-overview-card">
                <div class="profile-avatar">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <div class="profile-overview-info">
                    <span class="profile-name"><?= e($user['full_name']) ?></span>
                    <span class="profile-email-display"><?= e($user['email']) ?></span>
                    <span class="profile-role-badge profile-role-badge--<?= e($user['role']) ?>">
                        <?= ucfirst(e($user['role'])) ?>
                    </span>
                </div>
                <div class="profile-stats">
                    <div class="profile-stat">
                        <span class="pstat-num"><?= $bookingCount ?></span>
                        <span class="pstat-label">Total Bookings</span>
                    </div>
                    <div class="profile-stat">
                        <span class="pstat-num"><?= $activeCount ?></span>
                        <span class="pstat-label">Confirmed</span>
                    </div>
                    <div class="profile-stat">
                        <span class="pstat-num"><?= date('Y', strtotime($user['created_at'])) ?></span>
                        <span class="pstat-label">Member Since</span>
                    </div>
                </div>
            </div>

            <!-- Edit form -->
            <div class="detail-card">
                <h2 class="detail-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit Profile
                </h2>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $err): ?>
                            <p><?= e($err) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= BASE_URL ?>profile.php" novalidate>
                    <input type="hidden" name="update_profile" value="1">

                    <div class="form-group">
                        <label for="full_name">Full name</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="full_name" name="full_name"
                                   value="<?= e($user['full_name']) ?>"
                                   placeholder="Your full name"
                                   maxlength="120" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone number <span class="label-opt">(optional)</span></label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 9.13a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.18 6.18l1.27-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <input type="tel" id="phone" name="phone"
                                   value="<?= e($user['phone'] ?? '') ?>"
                                   placeholder="e.g. 0412 345 678"
                                   maxlength="20">
                        </div>
                    </div>

                    <!-- Read-only fields — shown but not editable -->
                    <div class="form-group">
                        <label>Email address</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <input type="email" value="<?= e($user['email']) ?>" disabled class="input-disabled">
                        </div>
                        <p class="field-note">Email address cannot be changed from this page.</p>
                    </div>

                    <div class="form-group">
                        <label>Account role</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            <input type="text" value="<?= ucfirst(e($user['role'])) ?>" disabled class="input-disabled">
                        </div>
                        <p class="field-note">Role is assigned by an administrator.</p>
                    </div>

                    <div class="profile-form-actions">
                        <button type="submit" class="btn-primary-lg">
                            Save Changes
                        </button>
                        <a href="<?= BASE_URL ?>my_bookings.php" class="btn-back-search">
                            View My Bookings
                        </a>
                    </div>

                </form>
            </div>

            <!-- Password Change Card -->
            <div class="detail-card detail-card--pw">
                <h2 class="detail-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Change Password
                </h2>

                <?php if (!empty($pwErrors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($pwErrors as $err): ?>
                            <p><?= e($err) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= BASE_URL ?>profile.php" novalidate>
                    <input type="hidden" name="change_password" value="1">

                    <div class="form-group">
                        <label for="current_password">Current password</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="current_password" name="current_password" required
                                   placeholder="Enter your current password">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password">New password</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="new_password" name="new_password" required
                                   placeholder="At least 8 characters" minlength="8">
                        </div>
                        <p class="field-note">Minimum 8 characters.</p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm new password</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="confirm_password" name="confirm_password" required
                                   placeholder="Re-enter new password">
                        </div>
                    </div>

                    <div class="profile-form-actions">
                        <button type="submit" class="btn-primary-lg">Change Password</button>
                    </div>
                </form>
            </div>

        </div>

        <!-- RIGHT: Account Info Sidebar -->
        <aside class="profile-sidebar">

            <div class="sidebar-card">
                <div class="sidebar-title">Account Details</div>
                <div class="sidebar-row">
                    <span class="sidebar-label">Member since</span>
                    <span class="sidebar-value"><?= date('d M Y', strtotime($user['created_at'])) ?></span>
                </div>
                <div class="sidebar-row">
                    <span class="sidebar-label">Account type</span>
                    <span class="sidebar-value"><?= ucfirst(e($user['role'])) ?></span>
                </div>
                <div class="sidebar-row">
                    <span class="sidebar-label">Total bookings</span>
                    <span class="sidebar-value"><?= $bookingCount ?></span>
                </div>
                <div class="sidebar-row">
                    <span class="sidebar-label">Active bookings</span>
                    <span class="sidebar-value"><?= $activeCount ?></span>
                </div>
            </div>

            <div class="sidebar-card sidebar-card--info">
                <div class="sidebar-title sidebar-title--compact">Quick Links</div>
                <div class="profile-quick-links">
                    <a href="<?= BASE_URL ?>my_bookings.php" class="pql-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        My Bookings
                    </a>
                    <a href="<?= BASE_URL ?>search.php" class="pql-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Book a Trip
                    </a>
                    <a href="<?= BASE_URL ?>routes.php" class="pql-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                        View Routes
                    </a>
                    <a href="<?= BASE_URL ?>logout.php" class="pql-item pql-item--danger">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Sign Out
                    </a>
                </div>
            </div>

        </aside>

    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
