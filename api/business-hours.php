<?php
/**
 * Vercel serverless function: Business hours
 * Route: GET /api/business-hours, POST /api/business-hours
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
    
    $day = sanitizeInput($_POST['day'] ?? '');
    $open_time = sanitizeInput($_POST['open_time'] ?? '');
    $close_time = sanitizeInput($_POST['close_time'] ?? '');
    $is_closed = isset($_POST['is_closed']) ? 1 : 0;
    
    if (empty($day)) {
        http_response_code(400);
        echo json_encode(['error' => 'Day is required']);
        exit;
    }
    
    $stmt = $db->prepare("
        UPDATE business_hours 
        SET open_time = :open, close_time = :close, is_closed = :closed
        WHERE day = :day
    ");
    $stmt->execute([
        ':open' => $is_closed ? null : $open_time,
        ':close' => $is_closed ? null : $close_time,
        ':closed' => $is_closed,
        ':day' => $day
    ]);
    
    logAdminActivity('settings', $user['name'], "Updated business hours for $day");
    
    echo json_encode(['success' => true, 'message' => 'Business hours updated']);
} else {
    $hours = $db->query("SELECT * FROM business_hours ORDER BY day_of_week")->fetchAll();
    echo json_encode(['success' => true, 'hours' => $hours]);
}
