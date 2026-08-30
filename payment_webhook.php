<?php
require_once 'db.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Verify Paystack webhook signature
$settings = getPaystackSettings();
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
if (empty($settings['secret_key']) || empty($signature)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$input = file_get_contents('php://input');
$expected_signature = hash_hmac('sha512', $input, $settings['secret_key']);
if (!hash_equals($expected_signature, $signature)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

$event = json_decode($input, true);

if (!$event || !isset($event['data']['reference'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

$reference = $event['data']['reference'];
$event_type = $event['event'] ?? '';
$payment_data = $event['data'];

// Verify the event is a payment success
if ($event_type === 'charge.success') {
    $booking = getBookingByPaymentReference($reference);
    
    if ($booking) {
        $booking_id = $booking['booking_id'];
        $amount = $payment_data['amount'] / 100;
        $method = $payment_data['channel'] ?? 'online';
        
        // Only update if still pending
        if ($booking['payment_status'] === 'pending') {
            updateBookingPaymentStatus($booking_id, 'success', $reference, $method, $amount);
            
            $stmt = $db->prepare("UPDATE payments SET status = 'success', paid_at = NOW(), gateway_response = :response WHERE payment_reference = :reference");
            $stmt->execute([
                ':response' => json_encode($payment_data),
                ':reference' => $reference
            ]);
            
            // Update booking status
            $stmt = $db->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = :id");
            $stmt->execute([':id' => $booking_id]);
            
            logAdminActivity('payment_webhook', 'System', "Webhook: payment success for booking #{$booking['booking_reference']} - ₵" . number_format($amount, 2), $booking_id);
        }
    }
} elseif ($event_type === 'charge.failed') {
    $booking = getBookingByPaymentReference($reference);
    
    if ($booking) {
        $stmt = $db->prepare("UPDATE payments SET status = 'failed', gateway_response = :response WHERE payment_reference = :reference");
        $stmt->execute([
            ':response' => json_encode($payment_data),
            ':reference' => $reference
        ]);
        
        logAdminActivity('payment_webhook', 'System', "Webhook: payment failed for booking #{$booking['booking_reference']}", $booking['booking_id']);
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
