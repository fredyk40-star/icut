<?php
require_once 'db.php';

$reference = $_GET['reference'] ?? '';
$trxref = $_GET['trxref'] ?? '';

if (empty($reference) && !empty($trxref)) {
    $reference = $trxref;
}

if (empty($reference)) {
    echo json_encode(['success' => false, 'message' => 'Missing payment reference.']);
    exit;
}

// Verify payment
$verification = verifyPaystackPayment($reference);

if ($verification['success']) {
    $payment_data = $verification['data'];
    $booking = getBookingByPaymentReference($reference);
    
    if ($booking) {
        $booking_id = $booking['booking_id'];
        $amount = $payment_data['amount'] / 100;
        $method = $payment_data['channel'] ?? 'online';
        
        // Update booking payment status
        updateBookingPaymentStatus($booking_id, 'success', $reference, $method, $amount);
        
        // Update payment record
        $stmt = $db->prepare("UPDATE payments SET status = 'success', paid_at = NOW(), gateway_response = :response WHERE payment_reference = :reference");
        $stmt->execute([
            ':response' => json_encode($payment_data),
            ':reference' => $reference
        ]);
        
        // Update booking status to confirmed
        $stmt = $db->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = :id");
        $stmt->execute([':id' => $booking_id]);
        
        logAdminActivity('payment_success', 'System', "Payment successful for booking #{$booking['booking_reference']} - ₵" . number_format($amount, 2), $booking_id);
        
        // Redirect to confirmation page
        header('Location: index.php?booked=1&ref=' . urlencode($booking['booking_reference']) . '&payment=success');
        exit;
    }
}

// Payment failed
$failed_booking = getBookingByPaymentReference($reference);
if ($failed_booking) {
    $stmt = $db->prepare("UPDATE payments SET status = 'failed' WHERE payment_reference = :reference");
    $stmt->execute([':reference' => $reference]);
    
    logAdminActivity('payment_failed', 'System', "Payment failed for booking #{$failed_booking['booking_reference']}", $failed_booking['booking_id']);
    
    header('Location: index.php?booked=1&ref=' . urlencode($failed_booking['booking_reference']) . '&payment=failed');
    exit;
}

echo json_encode(['success' => false, 'message' => 'Payment verification failed.']);
