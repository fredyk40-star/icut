<?php
/**
 * Vercel serverless function: Change password
 * Route: GET /api/change-password, POST /api/change-password
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

$user = requireAdminAuth();
$db = getDatabaseConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_username = sanitizeInput($_POST['new_username'] ?? '');
    $new_email = sanitizeInput($_POST['new_email'] ?? '');
    
    if (empty($current_password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Current password is required']);
        exit;
    }
    
    // Get current admin
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = :id");
    $stmt->execute([':id' => $user['user_id']]);
    $admin = $stmt->fetch();
    
    if (!password_verify($current_password, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Current password is incorrect']);
        exit;
    }
    
    $updated = false;
    
    if (!empty($new_password)) {
        if (strlen($new_password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            exit;
        }
        
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE admins SET password_hash = :password WHERE id = :id")
           ->execute([':password' => $new_hash, ':id' => $user['user_id']]);
        $updated = true;
    }
    
    if (!empty($new_username) && $new_username !== $admin['username']) {
        $db->prepare("UPDATE admins SET username = :username WHERE id = :id")
           ->execute([':username' => $new_username, ':id' => $user['user_id']]);
        $updated = true;
    }
    
    if (!empty($new_email) && $new_email !== $admin['email']) {
        $db->prepare("UPDATE admins SET email = :email WHERE id = :id")
           ->execute([':email' => $new_email, ':id' => $user['id']]);
        $updated = true;
    }
    
    if ($updated) {
        logAdminActivity('account_update', $user['name'], 'Account settings updated');
        echo json_encode(['success' => true, 'message' => 'Account updated successfully']);
    } else {
        echo json_encode(['success' => true, 'message' => 'No changes made']);
    }
} else {
    // GET - return current settings
    $stmt = $db->prepare("SELECT username, email FROM admins WHERE id = :id");
    $stmt->execute([':id' => $user['user_id']]);
    $admin = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'admin' => $admin
    ]);
}
