<?php
require_once 'admin_auth.php';

$phone = $_GET['phone'] ?? '';
$client_bookings = [];
$client_info = null;
$client_note = '';

// Create client_notes table if not exists
ensureClientNotesTableExists();

if (!empty($phone)) {
    $stmt = $db->prepare("
        SELECT b.*, br.name as barber_name, s.name as service_name, s.price 
        FROM bookings b
        JOIN barbers br ON b.barber_id = br.id
        JOIN services s ON b.service_id = s.id
        WHERE b.client_phone LIKE :phone
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
    $stmt->execute([':phone' => "%$phone%"]);
    $client_bookings = $stmt->fetchAll();
    
    if (!empty($client_bookings)) {
        $client_info = [
            'name' => $client_bookings[0]['client_name'],
            'phone' => $client_bookings[0]['client_phone'],
            'email' => $client_bookings[0]['client_email'],
            'total_visits' => count($client_bookings),
            'total_spent' => 0
        ];
        
        foreach ($client_bookings as $booking) {
            if ($booking['status'] === 'completed') {
                $client_info['total_spent'] += $booking['price'];
            }
        }
    }
    
    // Fetch existing client note
    $note_stmt = $db->prepare("SELECT notes, preferences FROM client_notes WHERE phone = :phone");
    $note_stmt->execute([':phone' => $phone]);
    $client_note = $note_stmt->fetch();
}

// Handle saving client notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_client_notes'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['upload_error'] = 'Invalid security token. Please refresh the page.';
        header('Location: client_history.php?phone=' . urlencode($phone));
        exit;
    }
    
    $note_phone = trim($_POST['note_phone'] ?? '');
    $notes = trim($_POST['client_notes'] ?? '');
    $preferences = trim($_POST['client_preferences'] ?? '');
    
    if (!empty($note_phone)) {
        $stmt = $db->prepare("
            INSERT INTO client_notes (phone, notes, preferences)
            VALUES (:phone, :notes, :preferences)
            ON DUPLICATE KEY UPDATE
                notes = VALUES(notes),
                preferences = VALUES(preferences),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':phone' => $note_phone,
            ':notes' => $notes,
            ':preferences' => $preferences
        ]);
        logAdminActivity('client_note', $_SESSION['admin_name'] ?? 'Admin', "Updated notes for {$note_phone}");
        $_SESSION['upload_message'] = "Client notes saved!";
        header('Location: client_history.php?phone=' . urlencode($note_phone));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client History - Barbershop</title>
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
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">Client History</h1>
            <a href="admin.php" class="bg-barber-700 hover:bg-barber-600 text-white px-4 py-2 rounded-lg transition">
                Back to Dashboard
            </a>
        </div>
        
        <!-- Search Form -->
        <div class="bg-barber-800 rounded-xl p-6 mb-8 border border-barber-700">
            <form method="GET" action="" class="flex flex-col sm:flex-row gap-4">
                <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>" 
                       placeholder="Enter phone number to search..."
                       class="flex-1 bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                <button type="submit" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">
                    Search
                </button>
            </form>
        </div>
        
        <?php if ($client_info): ?>
            <!-- Client Info Card -->
            <div class="bg-barber-800 rounded-xl p-6 mb-8 border border-barber-700">
                <h2 class="text-xl font-semibold text-white mb-4">Client Information</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <p class="text-gray-400 text-sm">Name</p>
                        <p class="text-white font-semibold"><?php echo htmlspecialchars($client_info['name']); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">Phone</p>
                        <p class="text-white font-semibold"><?php echo htmlspecialchars($client_info['phone']); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">Total Visits</p>
                        <p class="text-white font-semibold"><?php echo $client_info['total_visits']; ?></p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">Total Spent</p>
                        <p class="text-barber-gold font-semibold">₵<?php echo number_format($client_info['total_spent'], 2); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Client Notes -->
            <div class="bg-barber-800 rounded-xl p-6 mb-8 border border-barber-700">
                <h2 class="text-xl font-semibold text-white mb-4">📝 Client Notes & Preferences</h2>
                <form method="POST" action="" class="space-y-4">
                    <input type="hidden" name="save_client_notes" value="1">
                    <input type="hidden" name="note_phone" value="<?php echo htmlspecialchars($client_info['phone']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Notes</label>
                        <textarea name="client_notes" rows="3" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white" placeholder="e.g., prefers fade cuts, sensitive scalp..."><?php echo htmlspecialchars($client_note['notes'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Preferences</label>
                        <input type="text" name="client_preferences" value="<?php echo htmlspecialchars($client_note['preferences'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white" placeholder="e.g., prefers Saturday mornings, always brings kids...">
                    </div>
                    <button type="submit" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">💾 Save Notes</button>
                </form>
            </div>
            
            <!-- Booking History -->
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-barber-900 text-left text-gray-400 text-sm">
                                <th class="px-6 py-4">Date</th>
                                <th class="px-6 py-4">Service</th>
                                <th class="px-6 py-4">Barber</th>
                                <th class="px-6 py-4">Price</th>
                                <th class="px-6 py-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-barber-700">
                            <?php foreach ($client_bookings as $booking): ?>
                                <tr class="hover:bg-barber-700/50">
                                    <td class="px-6 py-4 text-white">
                                        <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 text-white">
                                        <?php echo htmlspecialchars($booking['service_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-white">
                                        <?php echo htmlspecialchars($booking['barber_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-barber-gold">
                                        ₵<?php echo number_format($booking['price'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php
                                        $status_colors = [
                                            'pending' => 'text-yellow-300',
                                            'confirmed' => 'text-green-300',
                                            'completed' => 'text-blue-300',
                                            'cancelled' => 'text-red-300'
                                        ];
                                        ?>
                                        <span class="<?php echo $status_colors[$booking['status']]; ?>">
                                            <?php echo ucfirst($booking['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($phone): ?>
            <div class="bg-barber-800 rounded-xl p-12 text-center border border-barber-700">
                <p class="text-gray-400 text-lg">No client found with that phone number</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>