<?php
session_start();
require_once 'db.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . adminLoginUrl());
    exit;
}

// Check for session timeout (30 minutes)
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 1800)) {
    session_destroy();
    header('Location: ' . adminLoginUrl(['timeout' => '1']));
    exit;
}

// Enforce password change on first login
if (!empty($_SESSION['must_change_password'])) {
    header('Location: change_password.php?force=1');
    exit;
}

// Refresh session timer
$_SESSION['login_time'] = time();
?>
