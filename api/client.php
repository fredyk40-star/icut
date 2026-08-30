<?php
/**
 * Vercel serverless function: Client portal + Cancel booking
 * Route: GET/POST /api/client
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

$db = getDatabaseConnection();
$message = '';
$error = '';
$booking = null;

$action = $_GET['action'] ?? ($_POST['action'] ?? 'portal');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'cancel') {
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
    } else {
        if (!checkRateLimit('client_lookup_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 300)) {
            $error = 'Too many lookup attempts. Please try again later.';
        } else {
            $reference = sanitizeInput($_POST['reference'] ?? '');
            $country_code = $_POST['country_code'] ?? '+233';
            $phone_number = preg_replace('/[^0-9]/', '', $_POST['phone_number'] ?? '');
            $phone = $country_code . $phone_number;
            
            if (empty($reference) || empty($phone_number)) {
                $error = 'Please provide both reference number and phone number';
            } elseif (!validatePhone($phone)) {
                $error = 'Please provide a valid phone number';
            } else {
                $reference = str_replace('#', '', $reference);
                
                $stmt = $db->prepare("
                    SELECT b.*, br.name as barber_name, s.name as service_name
                    FROM bookings b
                    JOIN barbers br ON b.barber_id = br.id
                    JOIN services s ON b.service_id = s.id
                    WHERE REPLACE(b.booking_reference, '#', '') = :reference AND b.client_phone LIKE :phone
                    AND b.status IN ('pending', 'confirmed')
                ");
                $stmt->execute([
                    ':reference' => $reference,
                    ':phone' => "%$phone%"
                ]);
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    $error = 'No active booking found with that reference number and phone number';
                } else {
                    $booking['confirmation_token'] = ensureBookingConfirmationToken($booking['id']);
                }
            }
        }
    }
}

echo json_encode([
    'success' => !$error,
    'error' => $error,
    'message' => $message,
    'booking' => $booking,
    'action' => $action
]);
