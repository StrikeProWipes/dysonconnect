<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

redirectIfLoggedIn();

$errors = [];
$v = ['full_name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName        = trim($_POST['full_name'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $v = ['full_name' => $fullName, 'email' => $email, 'phone' => $phone];

    if (empty($fullName) || strlen($fullName) < 2) $errors[] = 'Enter your full name (at least 2 characters).';
    if (empty($email)) {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!empty($phone) && !preg_match('/^[0-9+\s\-]{7,20}$/', $phone)) {
        $errors[] = 'Phone number format looks invalid.';
    }
    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errors[] = 'An account with that email already exists.';
        $stmt->close();
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, phone, role) VALUES (?, ?, ?, ?, 'customer')");
        $stmt->bind_param('ssss', $fullName, $email, $hashed, $phone);
        if ($stmt->execute()) {
            $stmt->close();
            setFlash('success', 'Account created successfully. You can now sign in.');
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        } else {
            $errors[] = 'Something went wrong. Please try again.';
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DysonConnect | Register</title>
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
            <h2>Join DysonConnect today</h2>
            <p>Create a free account to start booking regional bus travel across Victoria and New South Wales.</p>
            <div class="auth-feature-list">
                <div class="auth-feature"><span class="af-icon">✓</span> Free to register</div>
                <div class="auth-feature"><span class="af-icon">✓</span> Book and manage trips online</div>
                <div class="auth-feature"><span class="af-icon">✓</span> View booking history</div>
                <div class="auth-feature"><span class="af-icon">✓</span> Download e-tickets</div>
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
        <div class="auth-form-wrap auth-form-wrap--wide">

            <div class="auth-form-header">
                <h1>Create your account</h1>
                <p>Already have an account? <a href="<?= BASE_URL ?>login.php">Sign in</a></p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?= e($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="<?= BASE_URL ?>register.php" method="POST" novalidate class="auth-form">

                <div class="form-group">
                    <label for="full_name">Full name</label>
                    <div class="input-icon-wrap">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="full_name" name="full_name"
                               value="<?= e($v['full_name']) ?>"
                               placeholder="e.g. Jane Smith"
                               autocomplete="name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email address</label>
                    <div class="input-icon-wrap">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" id="email" name="email"
                               value="<?= e($v['email']) ?>"
                               placeholder="you@example.com"
                               autocomplete="email" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone">Phone number <span class="label-opt">(optional)</span></label>
                    <div class="input-icon-wrap">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 9.13a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.18 6.18l1.27-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <input type="tel" id="phone" name="phone"
                               value="<?= e($v['phone']) ?>"
                               placeholder="e.g. 0412 345 678"
                               autocomplete="tel">
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="password" name="password"
                                   placeholder="Min. 8 characters"
                                   autocomplete="new-password" required>
                            <button type="button" class="toggle-pw" onclick="togglePw('password', this)">Show</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm password</label>
                        <div class="input-icon-wrap">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   placeholder="Repeat password"
                                   autocomplete="new-password" required>
                            <button type="button" class="toggle-pw" onclick="togglePw('confirm_password', this)">Show</button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-auth-submit">Create Account</button>

                <p class="form-note">By registering you agree this is a prototype academic project for demonstration purposes only.</p>
            </form>

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
