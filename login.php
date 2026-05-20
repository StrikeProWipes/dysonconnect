<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

redirectIfLoggedIn();

$errors = [];
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $emailValue = $email;

    if (empty($email)) {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (empty($password)) {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id, full_name, email, password, role FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            loginUser($user);
            $redirect = $_GET['redirect'] ?? '';
            if ($user['role'] === 'admin') {
                header('Location: ' . BASE_URL . 'admin/dashboard.php');
            } elseif (!empty($redirect)) {
                header('Location: ' . $redirect);
            } else {
                header('Location: ' . BASE_URL . 'index.php');
            }
            exit;
        } else {
            $errors[] = 'Incorrect email or password.';
        }
    }
}

$pageTitle = 'Log In';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DysonConnect | Log In</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">

<div class="auth-layout">

    <!-- Left panel -->
    <div class="auth-left">
        <a href="<?= BASE_URL ?>index.php" class="auth-brand">
            <img src="<?= BASE_URL ?>assets/images/logo.png" alt="DysonConnect" class="auth-logo-img">
        </a>
        <div class="auth-left-content">
            <h2>Your regional journey starts here</h2>
            <p>Search routes across Victoria and NSW, choose your seat, and get an instant booking confirmation.</p>
            <div class="auth-feature-list">
                <div class="auth-feature"><span class="af-icon">✓</span> Real-time seat availability</div>
                <div class="auth-feature"><span class="af-icon">✓</span> Passenger type fares</div>
                <div class="auth-feature"><span class="af-icon">✓</span> Manage bookings online</div>
                <div class="auth-feature"><span class="af-icon">✓</span> Printable e-ticket</div>
            </div>
        </div>


        <!-- Stats strip -->
        <div class="auth-left-stats">
            <div class="auth-stat">
                <div class="auth-stat-num">18</div>
                <div class="auth-stat-label">Routes</div>
            </div>
            <div class="auth-stat">
                <div class="auth-stat-num">600+</div>
                <div class="auth-stat-label">Buses</div>
            </div>
            <div class="auth-stat">
                <div class="auth-stat-num">24/7</div>
                <div class="auth-stat-label">Online</div>
            </div>
        </div>
    </div>

    <!-- Right panel (form) -->
    <div class="auth-right">
        <div class="auth-form-wrap">

            <div class="auth-form-header">
                <h1>Welcome back</h1>
                <p>Don't have an account? <a href="<?= BASE_URL ?>register.php">Register free</a></p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?= e($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php $flash = getFlash(); if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <form action="<?= BASE_URL ?>login.php<?= isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '' ?>" method="POST" novalidate class="auth-form">

                <div class="form-group">
                    <label for="email">Email address</label>
                    <div class="input-icon-wrap">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" id="email" name="email"
                               value="<?= e($emailValue) ?>"
                               placeholder="you@example.com"
                               autocomplete="email" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="label-row">
                        <label for="password">Password</label>
                    </div>
                    <div class="input-icon-wrap">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="password" name="password"
                               placeholder="Your password"
                               autocomplete="current-password" required>
                        <button type="button" class="toggle-pw" onclick="togglePw('password', this)">Show</button>
                    </div>
                </div>

                <button type="submit" class="btn-auth-submit">Sign In</button>
            </form>

            <div class="demo-box">
                <div class="demo-box-label">Demo accounts</div>
                <div class="demo-entry">
                    <span class="demo-badge demo-badge--admin">Admin</span>
                    <div class="demo-creds">
                        <span>bibek.subedi@student.kent.edu.au</span>
                        <span class="demo-pw">Bibek@Admin1</span>
                    </div>
                </div>
                <div class="demo-entry">
                    <span class="demo-badge demo-badge--user">Customer</span>
                    <div class="demo-creds">
                        <span>wasik.gaus@student.kent.edu.au</span>
                        <span class="demo-pw">Wasik@Pass1</span>
                    </div>
                </div>
            </div>

        </div>
    </div>


</div>

<!-- Assessment note — outside flex layout so it doesn't break the two-column structure -->
<div class="auth-footer-note">
    Developed for <strong>DWIN309</strong> &nbsp;|&nbsp; Kent Institute Australia
</div>

<script>
function togglePw(id, btn) {
    const f = document.getElementById(id);
    f.type = f.type === 'password' ? 'text' : 'password';
    btn.textContent = f.type === 'password' ? 'Show' : 'Hide';
}
</script>
</body>
</html>
