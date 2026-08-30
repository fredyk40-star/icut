<?php
/**
 * Vercel serverless function: Export bookings
 * Route: GET /api/export-bookings
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

$user = requireAdminAuth();
$db = getDatabaseConnection();

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';

$sql = "
    SELECT b.*, br.name as barber_name, s.name as service_name
    FROM bookings b
    LEFT JOIN barbers br ON b.barber_id = br.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.booking_date BETWEEN :from AND :to
";
$params = [':from' => $date_from, ':to' => $date_to];

if (!empty($status)) {
    $sql .= " AND b.status = :status";
    $params[':status'] = $status;
}

$sql .= " ORDER BY b.booking_date DESC, b.booking_time DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Output CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=bookings_' . date('Y-m-d') . '.csv');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

fputcsv($output, ['Reference', 'Date', 'Time', 'Client', 'Phone', 'Email', 'Barber', 'Service', 'Status', 'Price', 'Payment Status']);

foreach ($bookings as $b) {
    fputcsv($output, [
        $b['booking_reference'],
        $b['booking_date'],
        $b['booking_time'],
        $b['client_name'],
        $b['client_phone'],
        $b['client_email'],
        $b['barber_name'],
        $b['service_name'],
        $b['status'],
        $b['price'],
        $b['payment_status']
    ]);
}

fclose($output);
exit;
