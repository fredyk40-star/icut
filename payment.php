<?php
require_once 'db.php';

// Set content type and disable error display for API responses
header('Content-Type: application/json');
ini_set('display_errors', 0);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $email = sanitizeInput($_POST['email'] ?? '');
    $amount = $_POST['amount'] ?? '0';

    if (empty($booking_id) || empty($email) || empty($amount)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    if (!validateEmail($email)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    // Get booking reference
    $stmt = $db->prepare("SELECT booking_reference FROM bookings WHERE id = :id");
    $stmt->execute([':id' => $booking_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    $result = initializePaystackPayment($booking_id, $email, $amount, $booking['booking_reference']);
    
    echo json_encode($result);
} catch (Exception $e) {
    error_log("Payment initialization error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Payment initialization failed. Please try again or contact support.']);
}
