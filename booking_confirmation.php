<?php
require_once 'admin_auth.php';

$booking_id = $_GET['id'] ?? 0;
$booking = null;

if ($booking_id) {
    $stmt = $db->prepare("
        SELECT b.*, b.booking_reference, br.name as barber_name, br.phone as barber_phone, 
               s.name as service_name, s.price as service_price, s.duration_minutes 
        FROM bookings b
        JOIN barbers br ON b.barber_id = br.id
        JOIN services s ON b.service_id = s.id
        WHERE b.id = :id
    ");
    $stmt->execute([':id' => (int)$booking_id]);
    $booking = $stmt->fetch();
}

if (!$booking) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed - Barbershop</title>
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
<body class="bg-barber-900 min-h-screen flex items-center justify-center">
    <div class="max-w-2xl w-full mx-4 py-12">
        <div class="bg-barber-800 rounded-2xl p-8 border border-barber-700 text-center">
            <div class="w-20 h-20 bg-green-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            
            <h1 class="text-3xl font-bold text-white mb-2">Booking Confirmed!</h1>
            <p class="text-gray-400 mb-8">Your reference number is:</p>
            
            <div class="text-4xl font-bold text-barber-gold mb-8">
                <?php echo htmlspecialchars($booking['booking_reference'] ?? '#' . $booking['id']); ?>
            </div>
            
            <div class="bg-barber-700 rounded-xl p-6 mb-8 text-left">
                <h2 class="text-xl font-semibold text-white mb-4">Appointment Details</h2>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-400">Service:</span>
                        <span class="text-white"><?php echo htmlspecialchars($booking['service_name']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Barber:</span>
                        <span class="text-white"><?php echo htmlspecialchars($booking['barber_name']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Date:</span>
                        <span class="text-white"><?php echo date('F j, Y', strtotime($booking['booking_date'])); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Time:</span>
                        <span class="text-white"><?php echo date('g:i A', strtotime($booking['booking_time'])); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Duration:</span>
                        <span class="text-white"><?php echo $booking['duration_minutes']; ?> minutes</span>
                    </div>
                    <div class="flex justify-between border-t border-barber-600 pt-3">
                        <span class="text-gray-400">Price:</span>
                        <span class="text-barber-gold font-bold text-lg">₵<?php echo number_format($booking['service_price'], 2); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="space-y-3">
                <p class="text-gray-300">We will confirm your appointment shortly via WhatsApp.</p>
                <div class="flex gap-4 justify-center">
                    <a href="index.php" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">
                        Back to Homepage
                    </a>
                    <a href="index.php?booked=1&ref=<?php echo urlencode($booking['booking_reference'] ?? '#' . $booking['id']); ?>" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">
                        Book Another
                    </a>
                    <a href="cancel_booking.php" class="border border-red-600 text-red-400 hover:bg-red-900/30 px-6 py-3 rounded-lg transition">
                        Cancel Booking
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Add to Calendar Button -->
        <div class="mt-4 text-center">
            <button onclick="addToCalendar()" 
                    class="text-barber-gold hover:text-barber-gold-light text-sm underline">
                📅 Add to Calendar
            </button>
        </div>
    </div>
    
    <script>
        function addToCalendar() {
            const startDate = new Date('<?php echo $booking['booking_date']; ?>T<?php echo $booking['booking_time']; ?>');
            const endDate = new Date(startDate.getTime() + <?php echo $booking['duration_minutes']; ?> * 60000);
            
            const event = {
                title: 'Barbershop Appointment - <?php echo addslashes($booking['service_name']); ?>',
                start: startDate.toISOString(),
                end: endDate.toISOString(),
                description: 'Appointment with <?php echo addslashes($booking['barber_name']); ?> at icut',
                location: '123 Main Street, Your City'
            };
            
            // Create downloadable ICS file
            const icsContent = `BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
DTSTART:${startDate.toISOString().replace(/[-:]/g, '').split('.')[0]}Z
DTEND:${endDate.toISOString().replace(/[-:]/g, '').split('.')[0]}Z
SUMMARY:${event.title}
DESCRIPTION:${event.description}
LOCATION:${event.location}
END:VEVENT
END:VCALENDAR`;
            
            const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'appointment.ics';
            link.click();
        }
    </script>
</body>
</html>