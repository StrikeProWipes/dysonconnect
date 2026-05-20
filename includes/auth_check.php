<?php
// auth_check.php — session startup and access control.

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,    // flip to true if you move to HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

// Redirects to login, passes current URL as ?redirect= so they come back after.
function requireLogin() {
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header('Location: ' . BASE_URL . 'login.php?redirect=' . $redirect);
        exit;
    }
}

// Call after requireLogin() on admin-only pages.
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

// Use on login.php and register.php to bounce already-logged-in users away.
function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        $dest = isAdmin() ? 'admin/dashboard.php' : 'index.php';
        header('Location: ' . BASE_URL . $dest);
        exit;
    }
}

// Sets session variables after a successful login.
function loginUser($user) {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
}

// Wipes session and removes the cookie.
function logoutUser() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function currentUserId()   { return $_SESSION['user_id']   ?? null; }
function currentUserName() { return $_SESSION['full_name'] ?? 'Guest'; }
function currentUserRole() { return $_SESSION['role']      ?? null; }
