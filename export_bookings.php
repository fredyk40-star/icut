<?php
require_once 'admin_auth.php';

// Get filter parameters (same as admin.php)
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query (same as admin.php)
    $query = "
    SELECT 
        b.id,
        b.booking_reference,
        b.client_name,
        b.client_phone,
        b.client_email,
        b.service_type,
        b.client_address,
        b.home_service_fee,
        br.name as barber_name,
        s.name as service_name,
        s.price as service_price,
        b.booking_date,
        b.booking_time,
        b.status,
        b.notes,
        b.created_at
    FROM bookings b
    JOIN barbers br ON b.barber_id = br.id
    JOIN services s ON b.service_id = s.id
    WHERE 1=1
";

$params = [];

if ($status_filter !== 'all') {
    $query .= " AND b.status = :status";
    $params[':status'] = $status_filter;
}

if ($date_filter === 'today') {
    $query .= " AND b.booking_date = CURDATE()";
} elseif ($date_filter === 'tomorrow') {
    $query .= " AND b.booking_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
} elseif ($date_filter === 'week') {
    $query .= " AND b.booking_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
}

if (!empty($search)) {
    $query .= " AND (b.client_name LIKE :search OR b.client_phone LIKE :search2)";
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
}

$query .= " ORDER BY b.booking_date DESC, b.booking_time DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="bookings_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Neutralise CSV formula injection: a leading = + - @ or tab forces Excel to
// treat a cell as a formula. Prefix such values with a single quote.
$csvSafe = function ($value) {
    $value = (string)$value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
};

// Add headers
fputcsv($output, [
    'Reference #',
    'Client Name',
    'Phone',
    'Email',
    'Service Type',
    'Address',
    'Barber',
    'Service',
    'Service Price',
    'Home Fee',
    'Total',
    'Date',
    'Time',
    'Status',
    'Notes',
    'Booked On'
]);

// Add data rows
foreach ($bookings as $booking) {
    $total = (float)($booking['service_price'] ?? 0) + (float)($booking['home_service_fee'] ?? 0);
    fputcsv($output, [
        $csvSafe($booking['booking_reference'] ?? ('#' . $booking['id'])),
        $csvSafe($booking['client_name']),
        $csvSafe($booking['client_phone']),
        $csvSafe($booking['client_email']),
        $csvSafe(ucfirst($booking['service_type'] ?? 'shop')),
        $csvSafe($booking['client_address']),
        $csvSafe($booking['barber_name']),
        $csvSafe($booking['service_name']),
        $csvSafe(number_format($booking['service_price'], 2)),
        $csvSafe(number_format($booking['home_service_fee'] ?? 0, 2)),
        $csvSafe(number_format($total, 2)),
        date('m/d/Y', strtotime($booking['booking_date'])),
        date('g:i A', strtotime($booking['booking_time'])),
        ucfirst($booking['status']),
        $csvSafe($booking['notes']),
        date('m/d/Y', strtotime($booking['created_at']))
    ]);
}

fclose($output);
exit;
?>