<?php
/**
 * Vercel serverless function: Create booking
 * Route: POST /api/book
 */

require_once dirname(__DIR__) . '/user/lib/env.php';
require_once dirname(__DIR__) . '/user/lib/db.php';
require_once dirname(__DIR__) . '/user/lib/csrf.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

$db = getDatabaseConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

if (!checkRateLimit('booking', 5, 300)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many booking attempts. Please try again later.']);
    exit;
}

$client_name = sanitizeInput($_POST['client_name'] ?? '');
$client_phone = sanitizeInput($_POST['client_phone'] ?? '');
$client_email = sanitizeInput($_POST['client_email'] ?? '');
$barber_id = (int)($_POST['barber_id'] ?? 0);
$service_id = (int)($_POST['service_id'] ?? 0);
$package_id = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
$booking_date = sanitizeInput($_POST['booking_date'] ?? '');
$booking_time = sanitizeInput($_POST['booking_time'] ?? '');
$notes = sanitizeInput($_POST['notes'] ?? '');
$service_type = sanitizeInput($_POST['service_type'] ?? 'shop');
$client_address = sanitizeInput($_POST['client_address'] ?? '');
$idempotency_key = $_POST['idempotency_key'] ?? '';

if (empty($client_name) || empty($client_phone) || empty($booking_date) || empty($booking_time)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please fill in all required fields']);
    exit;
}

$home_service_fee = ($service_type === 'home') ? getHomeServiceFee() : 0;

if (!empty($package_id)) {
    $package = $db->prepare("SELECT * FROM packages WHERE id = :id")->execute([':id' => $package_id])->fetch();
    $service_ids = explode(',', $package['service_ids']);
    $service_id = $service_ids[0];
    $service_price = $package['price'];
} else {
    $service_price = $db->prepare("SELECT price FROM services WHERE id = :id")->execute([':id' => $service_id])->fetchColumn();
}

$service_name = $db->prepare("SELECT name FROM services WHERE id = :id")->execute([':id' => $service_id])->fetchColumn();
$total_price = $service_price + $home_service_fee;

// Check for duplicate booking
$stmt = $db->prepare("
    SELECT id FROM bookings 
    WHERE barber_id = :barber_id 
    AND booking_date = :date 
    AND booking_time = :time 
    AND status IN ('confirmed', 'completed')
");
$stmt->execute([
    ':barber_id' => $barber_id,
    ':date' => $booking_date,
    ':time' => $booking_time
]);

if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'This time slot is already booked. Please choose another time.']);
    exit;
}

// Check idempotency key to prevent duplicate bookings
if (!empty($idempotency_key)) {
    $stmt = $db->prepare("SELECT id FROM bookings WHERE idempotency_key = :key");
    $stmt->execute([':key' => $idempotency_key]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'This booking has already been submitted.']);
        exit;
    }
}

$booking_reference = generateBookingReference();

$stmt = $db->prepare("
    INSERT INTO bookings (
        booking_reference, client_name, client_phone, client_email,
        barber_id, service_id, package_id, booking_date, booking_time,
        notes, price, service_type, client_address, home_service_fee,
        idempotency_key, status, payment_status
    ) VALUES (
        :ref, :name, :phone, :email, :barber, :service, :package,
        :date, :time, :notes, :price, :type, :address, :fee,
        :idempotency, 'pending', 'pending'
    )
");

$stmt->execute([
    ':ref' => $booking_reference,
    ':name' => $client_name,
    ':phone' => $client_phone,
    ':email' => $client_email,
    ':barber' => $barber_id,
    ':service' => $service_id,
    ':package' => $package_id,
    ':date' => $booking_date,
    ':time' => $booking_time,
    ':notes' => $notes,
    ':price' => $total_price,
    ':type' => $service_type,
    ':address' => $client_address,
    ':fee' => $home_service_fee,
    ':idempotency' => $idempotency_key
]);

$booking_id = $db->lastInsertId();

// Send confirmation email
$site_url = env('SITE_URL', 'http://localhost/icut');
$confirmation_link = "$site_url/cancel_booking.php";
$subject = "Booking Confirmation - $booking_reference";
$body = "
    <h2>Booking Confirmed!</h2>
    <p>Reference: <strong>$booking_reference</strong></p>
    <p>Date: $booking_date</p>
    <p>Time: $booking_time</p>
    <p>Service: $service_name</p>
    <p>Total: ₵" . number_format($total_price, 2) . "</p>
    <p><a href='$confirmation_link'>Manage your booking</a></p>
";

sendEmailNotification($client_email, $subject, $body);

echo json_encode([
    'success' => true,
    'message' => "Booking confirmed! Reference: $booking_reference",
    'booking_reference' => $booking_reference,
    'booking_id' => $booking_id
]);
