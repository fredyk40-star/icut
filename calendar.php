<?php
require_once 'admin_auth.php';

// Get current month/year
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Get first day of month
$first_day = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day);
$day_of_week = date('w', $first_day); // 0 = Sunday

// Get all bookings for the month
$start_date = "$year-$month-01";
$end_date = "$year-$month-$days_in_month";

$stmt = $db->prepare("
    SELECT b.*, br.name as barber_name, s.name as service_name 
    FROM bookings b
    JOIN barbers br ON b.barber_id = br.id
    JOIN services s ON b.service_id = s.id
    WHERE b.booking_date BETWEEN :start_date AND :end_date
    AND b.status != 'cancelled'
    ORDER BY b.booking_date, b.booking_time
");
$stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
$bookings = $stmt->fetchAll();

// Group bookings by date
$bookings_by_date = [];
foreach ($bookings as $booking) {
    $date_key = $booking['booking_date'];
    if (!isset($bookings_by_date[$date_key])) {
        $bookings_by_date[$date_key] = [];
    }
    $bookings_by_date[$date_key][] = $booking;
}

// Navigation
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar View - Barbershop</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            barber: {
                                900: '#0f0f0f',
                                800: '#1a1a1a',
                                700: '#2d2d2d',
                                gold: '#c9a96e',
                                'gold-light': '#d4b87a',
                            }
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-barber-900 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-white">Calendar View</h1>
            <div class="flex items-center space-x-4">
                <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" 
                   class="text-barber-gold hover:text-barber-gold-light">&larr; Previous</a>
                <span class="text-xl font-bold text-white"><?php echo date('F Y', $first_day); ?></span>
                <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" 
                   class="text-barber-gold hover:text-barber-gold-light">Next &rarr;</a>
            </div>
            <a href="admin.php" class="bg-barber-700 hover:bg-barber-600 text-white px-4 py-2 rounded-lg transition">
                Back to List
            </a>
        </div>
        
        <!-- Calendar Grid -->
        <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
            <div class="overflow-x-auto">
                <!-- Day names -->
                <div class="grid grid-cols-7 gap-px bg-barber-700 min-w-[640px]">
                <?php
                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                foreach ($days as $day): ?>
                    <div class="p-2 md:p-3 text-center text-gray-400 font-semibold bg-barber-800 text-xs md:text-sm"><?php echo $day; ?></div>
                <?php endforeach; ?>
            </div>
            
            <!-- Calendar days -->
            <div class="grid grid-cols-7 gap-px bg-barber-700 min-w-[640px]">
                <?php
                // Empty cells before first day
                for ($i = 0; $i < $day_of_week; $i++): ?>
                    <div class="bg-barber-900 p-1 md:p-2 min-h-[60px] md:min-h-[100px]"></div>
                <?php endfor;
                
                // Days of the month
                for ($day = 1; $day <= $days_in_month; $day++):
                    $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $is_today = ($current_date === date('Y-m-d'));
                    $has_bookings = isset($bookings_by_date[$current_date]);
                ?>
                    <div class="bg-barber-900 p-1 md:p-2 min-h-[60px] md:min-h-[100px] <?php echo $is_today ? 'ring-2 ring-barber-gold' : ''; ?>">
                        <div class="font-semibold mb-1 text-xs md:text-sm <?php echo $is_today ? 'text-barber-gold' : 'text-white'; ?>">
                            <?php echo $day; ?>
                        </div>
                        <?php if ($has_bookings): ?>
                            <div class="space-y-1">
                                <?php foreach ($bookings_by_date[$current_date] as $booking): ?>
                                    <div class="text-[10px] md:text-xs p-1 rounded <?php 
                                        echo match($booking['status']) {
                                            'confirmed' => 'bg-green-900/50 text-green-300',
                                            'completed' => 'bg-blue-900/50 text-blue-300',
                                            default => 'bg-yellow-900/50 text-yellow-300'
                                        };
                                    ?>">
                                        <?php echo date('g:i', strtotime($booking['booking_time'])); ?> - 
                                        <?php echo htmlspecialchars($booking['client_name']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor;
                
                // Fill remaining cells
                $total_cells = $day_of_week + $days_in_month;
                $remaining = (7 - ($total_cells % 7)) % 7;
                for ($i = 0; $i < $remaining; $i++): ?>
                    <div class="bg-barber-900 p-1 md:p-2 min-h-[60px] md:min-h-[100px]"></div>
                <?php endfor; ?>
            </div>
            </div>
        </div>
    </div>
</body>
</html>