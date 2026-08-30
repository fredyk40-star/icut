<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(405);
    exit('Invalid request.');
}

if (isset($_SESSION['admin_name'])) {
    logAdminActivity('admin_logout', $_SESSION['admin_name'], 'Admin logout');
}

session_destroy();
header('Location: index.php');
exit;
?>
