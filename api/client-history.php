<?php
/**
 * Vercel serverless function: Client history
 * Route: GET /api/client-history, POST /api/client-history
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
    
    $client_phone = sanitizeInput($_POST['client_phone'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $preferences = sanitizeInput($_POST['preferences'] ?? '');
    
    if (empty($client_phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'Phone number is required']);
        exit;
    }
    
    $stmt = $db->prepare("
        INSERT INTO client_notes (client_phone, notes, preferences, updated_at)
        VALUES (:phone, :notes, :prefs, NOW())
        ON DUPLICATE KEY UPDATE notes = :notes, preferences = :prefs, updated_at = NOW()
    ");
    $stmt->execute([
        ':phone' => $client_phone,
        ':notes' => $notes,
        ':prefs' => $preferences
    ]);
    
    logAdminActivity('client_note', $user['name'], "Updated notes for $client_phone");
    
    echo json_encode(['success' => true, 'message' => 'Client notes updated']);
    exit;
}

// GET - fetch client history
$phone = $_GET['phone'] ?? '';

if (empty($phone)) {
    echo json_encode(['error' => 'Phone number is required']);
    exit;
}

$phone = sanitizeInput($phone);

// Get client notes
$stmt = $db->prepare("SELECT * FROM client_notes WHERE client_phone = :phone");
$stmt->execute([':phone' => $phone]);
$notes = $stmt->fetch();

// Get booking history
$stmt = $db->prepare("
    SELECT b.*, br.name as barber_name, s.name as service_name
    FROM bookings b
    LEFT JOIN barbers br ON b.barber_id = br.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.client_phone = :phone
    ORDER BY b.booking_date DESC
    LIMIT 50
");
$stmt->execute([':phone' => $phone]);
$bookings = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'notes' => $notes,
    'bookings' => $bookings
]);
