<?php
require_once 'admin_auth.php';

$date = $_GET['date'] ?? date('Y-m-d');

$stmt = $db->prepare("
    SELECT b.*, br.name as barber_name, s.name as service_name, s.duration_minutes 
    FROM bookings b
    JOIN barbers br ON b.barber_id = br.id
    JOIN services s ON b.service_id = s.id
    WHERE b.booking_date = :date AND b.status IN ('pending', 'confirmed')
    ORDER BY b.booking_time
");
$stmt->execute([':date' => $date]);
$bookings = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Schedule - <?php echo date('F j, Y', strtotime($date)); ?></title>
    <style>
        @media print {
            body { background: white; color: black; }
            .no-print { display: none; }
            @page { margin: 1cm; }
        }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; border-bottom: 3px solid #c9a96e; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #333; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; }
        .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
        button { padding: 10px 20px; background: #c9a96e; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 20px; }
        button:hover { background: #b8934e; }
        .badge-home { display: inline-block; background: #c9a96e; color: #fff; font-size: 11px; padding: 1px 6px; border-radius: 4px; }
        .badge-shop { display: inline-block; background: #666; color: #fff; font-size: 11px; padding: 1px 6px; border-radius: 4px; }
        .addr { color: #b5651d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Daily Schedule</h1>
        <p style="text-align: center; color: #666;"><?php echo date('l, F j, Y', strtotime($date)); ?></p>
        
        <?php if (empty($bookings)): ?>
            <p style="text-align: center; color: #999; margin-top: 30px;">No bookings for this date</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Client</th>
                        <th>Service</th>
                        <th>Barber</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td><?php echo date('g:i A', strtotime($booking['booking_time'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($booking['client_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($booking['client_phone']); ?></small>
                                <?php if (!empty($booking['client_email'])): ?><br><small><?php echo htmlspecialchars($booking['client_email']); ?></small><?php endif; ?>
                                <?php if (!empty($booking['client_address'])): ?>
                                    <div class="addr">🏠 <?php echo htmlspecialchars($booking['client_address']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($booking['service_type'] ?? 'shop') === 'home'): ?>
                                    <span class="badge-home">Home</span>
                                <?php else: ?>
                                    <span class="badge-shop">Shop</span>
                                <?php endif; ?>
                                <div><?php echo htmlspecialchars($booking['service_name']); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($booking['barber_name']); ?></td>
                            <td><?php echo $booking['duration_minutes']; ?> min</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div class="footer">
            <p>icut - Daily Schedule</p>
            <p>Printed on <?php echo date('F j, Y'); ?></p>
        </div>
        
        <button onclick="window.print()" class="no-print">🖨️ Print This Page</button>
        <button onclick="window.close()" class="no-print" style="margin-left: 10px; background: #666;">Close</button>
    </div>
</body>
</html>