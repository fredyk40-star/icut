<?php
/**
 * Vercel serverless function: Print sheet
 * Route: GET /api/print-sheet
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: text/html; charset=UTF-8');

$user = requireAdminAuth();
$db = getDatabaseConnection();

$date = $_GET['date'] ?? date('Y-m-d');

$stmt = $db->prepare("
    SELECT b.*, br.name as barber_name, s.name as service_name
    FROM bookings b
    LEFT JOIN barbers br ON b.barber_id = br.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.booking_date = :date
    ORDER BY b.booking_time ASC
");
$stmt->execute([':date' => $date]);
$bookings = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Sheet - <?php echo htmlspecialchars($date); ?></title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    <h1>Daily Schedule - <?php echo htmlspecialchars($date); ?></h1>
    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Client</th>
                <th>Phone</th>
                <th>Barber</th>
                <th>Service</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bookings as $b): ?>
                <tr>
                    <td><?php echo htmlspecialchars($b['booking_time']); ?></td>
                    <td><?php echo htmlspecialchars($b['client_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['client_phone']); ?></td>
                    <td><?php echo htmlspecialchars($b['barber_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['service_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['status']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <script>window.print();</script>
</body>
</html>
