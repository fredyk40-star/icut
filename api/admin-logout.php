<?php
/**
 * Vercel serverless function: Admin logout
 * Route: POST /api/admin-logout
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = getCurrentUser();
if ($user) {
    logAdminActivity('admin_logout', $user['name'], 'Admin logout');
}

deleteJWTCookie();

echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
