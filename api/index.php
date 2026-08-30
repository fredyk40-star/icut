<?php
/**
 * Vercel serverless function: Main booking page
 * Route: GET /api/index
 */

require_once dirname(__DIR__) . '/user/lib/env.php';
require_once dirname(__DIR__) . '/user/lib/db.php';
require_once dirname(__DIR__) . '/user/lib/csrf.php';
require_once dirname(__DIR__) . '/user/middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: text/html; charset=UTF-8');

$db = getDatabaseConnection();

// Generate CSRF token for forms
$csrf_token = generateCSRFToken();

// Fetch site settings
$settings = [];
$settings_result = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
foreach ($settings_result as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$logo = $settings['logo'] ?? '';

// Fetch services
$services = $db->query("SELECT * FROM services WHERE is_active = 1 ORDER BY display_order, name")->fetchAll();

// Fetch barbers
$barbers = $db->query("SELECT * FROM barbers WHERE is_active = 1 ORDER BY display_order, name")->fetchAll();

// Fetch packages
$packages = $db->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY display_order, name")->fetchAll();

// Fetch gallery items
$gallery_items = $db->query("SELECT * FROM gallery ORDER BY created_at DESC")->fetchAll();

// Fetch reviews
$reviews = $db->query("SELECT * FROM reviews WHERE is_approved = 1 ORDER BY created_at DESC LIMIT 6")->fetchAll();

// Handle booking submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (!checkRateLimit('booking', 5, 300)) {
        $error = 'Too many booking attempts. Please try again later.';
    } else {
        $client_name = sanitizeInput($_POST['client_name'] ?? '');
        $client_phone = sanitizeInput($_POST['client_phone'] ?? '');
        $client_email = sanitizeInput($_POST['client_email'] ?? '');
        $barber_id = (int)($_POST['barber_id'] ?? 0);
        $service_id = (int)($_POST['service_id'] ?? 0);
        $package_id = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
        $booking_date = sanitizeInput($_POST['booking_date'] ?? '');
        $booking_time = sanitizeInput($_POST['booking_time'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        $service_type = sanitizeInput($_POST['service_type'] ?? 'shop');
        $client_address = sanitizeInput($_POST['client_address'] ?? '');
        
        $idempotency_key = $_POST['idempotency_key'] ?? '';
        
        if (empty($client_name) || empty($client_phone) || empty($booking_date) || empty($booking_time)) {
            $error = 'Please fill in all required fields';
        } else {
            $home_service_fee = ($service_type === 'home') ? getHomeServiceFee() : 0;
            
            // Get service price and name
            if (!empty($package_id)) {
                $package = $db->prepare("SELECT * FROM packages WHERE id = :id")->execute([':id' => $package_id])->fetch();
                $service_ids = explode(',', $package['service_ids']);
                $service_id = $service_ids[0];
                $service_price = $package['price'];
            } else {
                $service_price = $db->prepare("SELECT price FROM services WHERE id = :id")->execute([':id' => $service_id])->fetchColumn();
            }
            
            $service_name = $db->prepare("SELECT name FROM services WHERE id = :id")->execute([':id' => $service_id])->fetchColumn();
            $total_price = $service_price + $home_service_fee;
            
            // Check for duplicate booking
            $stmt = $db->prepare("
                SELECT id FROM bookings 
                WHERE barber_id = :barber_id 
                AND booking_date = :date 
                AND booking_time = :time 
                AND status IN ('confirmed', 'completed')
            ");
            $stmt->execute([
                ':barber_id' => $barber_id,
                ':date' => $booking_date,
                ':time' => $booking_time
            ]);
            
            if ($stmt->fetch()) {
                $error = 'This time slot is already booked. Please choose another time.';
            } else {
                $booking_reference = generateBookingReference();
                
                $stmt = $db->prepare("
                    INSERT INTO bookings (
                        booking_reference, client_name, client_phone, client_email,
                        barber_id, service_id, package_id, booking_date, booking_time,
                        notes, price, service_type, client_address, home_service_fee,
                        idempotency_key, status, payment_status
                    ) VALUES (
                        :ref, :name, :phone, :email, :barber, :service, :package,
                        :date, :time, :notes, :price, :type, :address, :fee,
                        :idempotency, 'pending', 'pending'
                    )
                ");
                
                $stmt->execute([
                    ':ref' => $booking_reference,
                    ':name' => $client_name,
                    ':phone' => $client_phone,
                    ':email' => $client_email,
                    ':barber' => $barber_id,
                    ':service' => $service_id,
                    ':package' => $package_id,
                    ':date' => $booking_date,
                    ':time' => $booking_time,
                    ':notes' => $notes,
                    ':price' => $total_price,
                    ':type' => $service_type,
                    ':address' => $client_address,
                    ':fee' => $home_service_fee,
                    ':idempotency' => $idempotency_key
                ]);
                
                $booking_id = $db->lastInsertId();
                $message = "Booking confirmed! Reference: $booking_reference";
            }
        }
    }
}

// Output HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['site_name'] ?? 'icut'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Add your CSS here */
    </style>
</head>
<body class="bg-barber-900 text-white">
    <!-- Your HTML content here -->
    <h1><?php echo htmlspecialchars($settings['site_name'] ?? 'icut'); ?></h1>
    
    <?php if ($message): ?>
        <div class="bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-lg">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Rest of your HTML -->
</body>
</html>
