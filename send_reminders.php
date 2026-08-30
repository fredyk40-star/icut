<?php
/**
 * Automated Reminder Sender
 * 
 * This script sends reminder emails for confirmed bookings happening tomorrow.
 * It can be triggered manually from admin or set up as a cron job.
 * 
 * To set up as cron job (runs daily at 10 AM):
 * 0 10 * * * php /path/to/icut/send_reminders.php
 */

require_once 'db.php';

// Only allow CLI or admin-triggered access
if (php_sapi_name() !== 'cli') {
    session_start();
    require_once 'admin_auth.php';
    
    if (!isset($_GET['admin_trigger'])) {
        die('Unauthorized access');
    }
}

$sent_count = 0;
$failed_count = 0;
$results = [];

// Get tomorrow's confirmed bookings with client emails
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$stmt = $db->prepare("
    SELECT b.*, br.name as barber_name, s.name as service_name, s.price as service_price, s.duration_minutes
    FROM bookings b
    JOIN barbers br ON b.barber_id = br.id
    JOIN services s ON b.service_id = s.id
    WHERE b.booking_date = :tomorrow
    AND b.status IN ('confirmed', 'pending')
    AND b.client_email IS NOT NULL
    AND b.client_email != ''
    ORDER BY b.booking_time ASC
");
$stmt->execute([':tomorrow' => $tomorrow]);
$bookings = $stmt->fetchAll();

foreach ($bookings as $booking) {
    $to = $booking['client_email'];
    $booking_ref = $booking['booking_reference'] ?? '#' . $booking['id'];
    $date = date('l, F j, Y', strtotime($booking['booking_date']));
    $time = date('g:i A', strtotime($booking['booking_time']));
    
    $subject = "Reminder: Your icut Appointment Tomorrow - $booking_ref";
    
    $message = "
    <html>
    <head><title>Appointment Reminder</title></head>
    <body style='font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;'>
        <div style='max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;'>
            <h2 style='color: #c9a96e;'>icut</h2>
            <h3>🔔 Appointment Reminder</h3>
            <p>Hi {$booking['client_name']},</p>
            <p>This is a friendly reminder that you have an appointment <strong>tomorrow</strong>:</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Reference:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking_ref}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Service:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['service_name']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Barber:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['barber_name']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Date:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$date}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Time:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$time}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Price:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>₵" . number_format($booking['service_price'], 2) . "</td></tr>
            </table>
            <p>Need to reschedule or cancel? Please contact us as soon as possible.</p>
            <p style='color: #c9a96e;'>See you tomorrow!</p>
            <p style='font-size: 12px; color: #888;'>Questions? WhatsApp us at " . getSiteSetting('phone', '') . "</p>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: icut <noreply@icut.com>\r\n";
    $headers .= "Reply-To: " . getSiteSetting('email', 'noreply@icut.com') . "\r\n";
    
    $sent = false;
    if (!empty(env('SMTP_HOST', ''))) {
        require_once __DIR__ . '/smtp_mailer.php';
        $mailer = new SmtpMailer();
        $sent = $mailer->send($to, $subject, $message);
    } else {
        $sent = @mail($to, $subject, $message, $headers);
    }
    
    if ($sent) {
        $sent_count++;
        $results[] = "✓ Sent reminder to {$booking['client_name']} ({$to}) for {$booking_ref}";
        logAdminActivity('reminder_sent', 'System', "Sent appointment reminder to {$booking['client_name']} for {$booking_ref}", $booking['id']);
    } else {
        $failed_count++;
        $results[] = "✗ Failed to send reminder to {$booking['client_name']} ({$to})";
    }
}

// Output results
echo "=== Appointment Reminder Report ===\n";
echo "Date: " . date('F j, Y') . "\n";
echo "Target Date: {$tomorrow}\n";
echo "Total Bookings Found: " . count($bookings) . "\n";
echo "Successfully Sent: {$sent_count}\n";
echo "Failed: {$failed_count}\n\n";

if (!empty($results)) {
    echo "Details:\n";
    foreach ($results as $result) {
        echo $result . "\n";
    }
}

// If called from admin, redirect back with message
if (isset($_GET['admin_trigger'])) {
    $_SESSION['upload_message'] = "Sent {$sent_count} reminders. Failed: {$failed_count}";
    header('Location: admin.php');
    exit;
}
