<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

// Nothing to do if they're not actually logged in
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

logoutUser();
setFlash('success', 'You have been signed out.');
header('Location: ' . BASE_URL . 'login.php');
exit;
