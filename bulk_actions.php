<?php
require_once 'admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_ids']) && isset($_POST['bulk_status'])) {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        header('Location: admin.php?error=invalid_token');
        exit;
    }
    
    $booking_ids = $_POST['booking_ids'];
    $new_status = $_POST['bulk_status'];
    
    $allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    
    if (in_array($new_status, $allowed_statuses) && !empty($booking_ids)) {
        $placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
        $stmt = $db->prepare("UPDATE bookings SET status = ? WHERE id IN ($placeholders)");
        
        $params = array_merge([$new_status], $booking_ids);
        $stmt->execute($params);
    }
}

header('Location: admin.php');
exit;
?>