<?php
/**
 * Vercel serverless function: Admin login
 * Route: POST /api/admin-login
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

$db = getDatabaseConnection();

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$admin_entry_key = trim(env('ADMIN_ENTRY_KEY', ''));

// Check rate limit
if (!checkRateLimit('login', 5, 300)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many login attempts. Please try again later.']);
    exit;
}

$username = sanitizeInput($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if (!validateCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter username and password']);
    exit;
}

// Check admin credentials
$stmt = $db->prepare("
    SELECT id, username, password_hash, full_name, failed_attempts, locked_until
    FROM admins
    WHERE username = :username OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(:email))
    LIMIT 1
");
$stmt->execute([':username' => $username, ':email' => $username]);
$admin = $stmt->fetch();

if (!$admin) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

// Check if account is locked
if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
    http_response_code(423);
    echo json_encode(['error' => 'Account is locked due to too many failed attempts. Please try again later.']);
    exit;
}

// Verify password
if (!password_verify($password, $admin['password_hash'])) {
    // Increment failed attempts
    $failed_attempts = ($admin['failed_attempts'] ?? 0) + 1;
    if ($failed_attempts >= 5) {
        $locked_until = date('Y-m-d H:i:s', time() + 900); // 15 minutes
        $db->prepare("UPDATE admins SET failed_attempts = :attempts, locked_until = :locked WHERE id = :id")
           ->execute([':attempts' => $failed_attempts, ':locked' => $locked_until, ':id' => $admin['id']]);
    } else {
        $db->prepare("UPDATE admins SET failed_attempts = :attempts WHERE id = :id")
           ->execute([':attempts' => $failed_attempts, ':id' => $admin['id']]);
    }
    
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

// Reset failed attempts
$db->prepare("UPDATE admins SET failed_attempts = 0, locked_until = NULL WHERE id = :id")
   ->execute([':id' => $admin['id']]);

// Generate JWT token
$secret = env('JWT_SECRET', 'change-me-in-production');
$payload = [
    'user_id' => $admin['id'],
    'username' => $admin['username'],
    'role' => 'admin',
    'name' => $admin['full_name']
];

$token = generateJWT($payload, $secret);
setJWTCookie($token);

logAdminActivity('admin_login', $admin['full_name'], 'Admin login successful');

echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'user' => [
        'id' => $admin['id'],
        'username' => $admin['username'],
        'name' => $admin['full_name']
    ]
]);
