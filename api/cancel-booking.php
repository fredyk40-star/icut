<?php
/**
 * Vercel serverless function: Cancel booking
 * Route: POST /api/cancel-booking
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

$db = getDatabaseConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please look up your booking again.';
        } elseif (!checkRateLimit('client_cancel_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 300)) {
            $error = 'Too many cancellation attempts. Please try again later.';
        } else {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $booking_data = getBookingForCancellation($booking_id, $_POST['confirmation_token'] ?? '');
            
            if (!$booking_data) {
                $error = 'We could not verify that booking. Please look it up again.';
            } else {
                $needs_refund = $booking_data['payment_status'] === 'success' && $booking_data['refund_status'] === 'none';
                
                if ($needs_refund) {
                    $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW(), refund_status = 'requested' WHERE id = :id")
                       ->execute([':id' => $booking_id]);
                    logAdminActivity('refund_requested', 'System', "Client requested cancellation with refund for booking #{$booking_data['booking_reference']}", $booking_id);
                    $message = 'Your booking has been cancelled. A refund has been requested.';
                } else {
                    $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id")
                       ->execute([':id' => $booking_id]);
                    $message = 'Your booking has been cancelled successfully.';
                }
            }
        }
    }
}

echo json_encode([
    'success' => !$error,
    'error' => $error,
    'message' => $message
]);
