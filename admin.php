<?php
session_start();
require_once 'db.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Check if user is logged in and session is valid
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . adminLoginUrl());
    exit;
}

// Check for session timeout (30 minutes)
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 1800)) {
    session_destroy();
    header('Location: ' . adminLoginUrl(['timeout' => '1']));
    exit;
}

// Refresh session timer on activity
$_SESSION['login_time'] = time();

// Load current admin profile for the account/password modal
$admin_profile_stmt = $db->prepare("SELECT username, email FROM admins WHERE id = :id");
$admin_profile_stmt->execute([':id' => $_SESSION['admin_id']]);
$admin_profile = $admin_profile_stmt->fetch();
$current_username = $admin_profile['username'] ?? ($_SESSION['admin_name'] ?? '');
$current_email = $admin_profile['email'] ?? '';

// Handle status updates
if (isset($_POST['update_status']) && isset($_POST['booking_id']) && isset($_POST['new_status'])) {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
    $booking_id = (int)$_POST['booking_id'];
    $new_status = $_POST['new_status'];
    $allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    
    if (in_array($new_status, $allowed_statuses)) {
        if ($new_status === 'cancelled') {
            $stmt = $db->prepare("UPDATE bookings SET status = :status, cancelled_at = NOW() WHERE id = :id");
        } else {
            $stmt = $db->prepare("UPDATE bookings SET status = :status WHERE id = :id");
        }
        $stmt->execute([':status' => $new_status, ':id' => $booking_id]);
        
        // Fetch the updated booking with reference for notifications
        $booking_stmt = $db->prepare("
            SELECT b.*, b.booking_reference, br.name as barber_name, br.phone as barber_phone,
                   s.name as service_name, s.price as service_price
            FROM bookings b
            JOIN barbers br ON b.barber_id = br.id
            JOIN services s ON b.service_id = s.id
            WHERE b.id = :id
        ");
        $booking_stmt->execute([':id' => $booking_id]);
        $booking = $booking_stmt->fetch();
        
        if ($booking) {
            // Send email notification via PHP mail() if client has email
            if ($booking['client_email']) {
                sendStatusNotification($booking, $new_status);
            }
            
            // Log the status change
            $status_labels = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
            $status_label = $status_labels[$new_status] ?? ucfirst($new_status);
            logAdminActivity('status_update', $_SESSION['admin_name'] ?? 'Admin', "Updated booking {$booking['booking_reference']} to: {$status_label}", $booking_id);
            
            // Prepare WhatsApp fallback message using site settings phone number
            $site_phone = getSiteSetting('phone', '');
            $site_phone_clean = preg_replace('/[^0-9]/', '', $site_phone);
            
            if (!empty($site_phone_clean) && !empty($booking['client_phone'])) {
                // The WhatsApp button already handles WhatsApp messaging
                // This is a server-side note that email was sent or WhatsApp fallback is available
            }
        }
    }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$query = "
    SELECT 
        b.*,
        b.booking_reference,
        br.name as barber_name,
        br.phone as barber_phone,
        s.name as service_name,
        s.price as service_price,
        s.duration_minutes as service_duration
    FROM bookings b
    JOIN barbers br ON b.barber_id = br.id
    JOIN services s ON b.service_id = s.id
    WHERE 1=1
";

$params = [];

// Apply filters
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
    $query .= " AND (b.client_name LIKE :search OR b.client_phone LIKE :search2 OR b.client_email LIKE :search3)";
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
}

$query .= " ORDER BY b.booking_date DESC, b.booking_time DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get statistics
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN booking_date = CURDATE() THEN 1 ELSE 0 END) as today
    FROM bookings
");
$stats = $stats_stmt->fetch();

// Fetch all barbers for management
$all_barbers = $db->query("SELECT * FROM barbers ORDER BY name")->fetchAll();

// Fetch all services for management
$all_services = $db->query("SELECT * FROM services ORDER BY name")->fetchAll();

// Fetch all packages for management
ensurePackagesTableExists();
$all_packages = $db->query("SELECT * FROM packages ORDER BY created_at DESC")->fetchAll();

// Fetch gallery items
$gallery_items = $db->query("SELECT * FROM gallery ORDER BY created_at DESC")->fetchAll();

// Fetch reviews for management
$all_reviews = $db->query("SELECT * FROM reviews ORDER BY created_at DESC")->fetchAll();

// Fetch loyalty members
$loyalty_members = $db->query("SELECT * FROM loyalty ORDER BY points DESC, updated_at DESC")->fetchAll();

// Fetch activity logs
$activity_logs = $db->query("SELECT * FROM admin_activity_log ORDER BY created_at DESC LIMIT 100")->fetchAll();

// Fetch site settings
$settings_result = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
$settings = [];
foreach ($settings_result as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Format WhatsApp number (remove any non-digit characters)
function formatWhatsAppNumber($phone) {
    return preg_replace('/[^0-9]/', '', $phone);
}

// Generate WhatsApp message
function getWhatsAppMessage($booking) {
    $date = date('l, F j, Y', strtotime($booking['booking_date']));
    $time = date('g:i A', strtotime($booking['booking_time']));
    
    return "Hello {$booking['client_name']}! 👋\n\n" .
           "This is icut confirming your appointment:\n\n" .
           "📅 Date: {$date}\n" .
           "🕐 Time: {$time}\n" .
           "💇 Service: {$booking['service_name']}\n" .
           "✂️ Barber: {$booking['barber_name']}\n" .
           "💰 Price: ₵" . number_format($booking['service_price'], 2) . "\n\n" .
           "Please reply CONFIRM to confirm this appointment, or call us if you need to reschedule.\n\n" .
           "Thank you for choosing icut! 💈";
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - icut</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                                600: '#404040',
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
    <!-- Top Navigation -->
    <nav class="bg-barber-800 border-b border-barber-700 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <svg class="w-8 h-8 text-barber-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <div>
                        <h1 class="text-xl font-bold text-white">Admin Dashboard</h1>
                        <p class="text-gray-400 text-xs">Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?></p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <button id="adminMenuToggle" class="md:hidden text-white text-xl p-2" aria-label="Toggle admin menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <button onclick="document.getElementById('adminGuideModal').classList.remove('hidden')" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 px-4 py-2 rounded-lg text-sm font-bold transition flex items-center space-x-2">
                        <i class="fas fa-info-circle"></i>
                        <span>Admin Guide</span>
                    </button>
                    <div class="relative" id="themePickerContainer">
                        <button id="themeToggle" onclick="toggleTheme()" class="p-2 rounded-lg bg-barber-800 hover:bg-barber-700 text-white transition relative" title="Current theme: Dark. Click to change theme.">
                            <i class="fas fa-moon"></i>
                            <span class="theme-indicator absolute -top-1 -right-1 w-3 h-3 rounded-full bg-barber-gold border-2 border-barber-800"></span>
                        </button>
                        <div id="themePicker" class="hidden absolute right-0 mt-2 bg-barber-800 rounded-lg shadow-xl border border-barber-700 p-3 z-50 min-w-[180px]">
                            <p class="text-xs text-gray-400 mb-2 px-1">Select Theme</p>
                            <button onclick="setTheme('dark')" class="theme-swatch w-full flex items-center space-x-2 px-2 py-2 rounded hover:bg-barber-700 transition text-left">
                                <span class="w-4 h-4 rounded-full bg-gray-900 border border-gray-600"></span>
                                <span class="text-white text-sm">Dark</span>
                            </button>
                            <button onclick="setTheme('light')" class="theme-swatch w-full flex items-center space-x-2 px-2 py-2 rounded hover:bg-barber-700 transition text-left">
                                <span class="w-4 h-4 rounded-full bg-white border border-gray-300"></span>
                                <span class="text-white text-sm">Light</span>
                            </button>
                            <button onclick="setTheme('ocean')" class="theme-swatch w-full flex items-center space-x-2 px-2 py-2 rounded hover:bg-barber-700 transition text-left">
                                <span class="w-4 h-4 rounded-full bg-sky-500"></span>
                                <span class="text-white text-sm">Ocean</span>
                            </button>
                            <button onclick="setTheme('forest')" class="theme-swatch w-full flex items-center space-x-2 px-2 py-2 rounded hover:bg-barber-700 transition text-left">
                                <span class="w-4 h-4 rounded-full bg-green-500"></span>
                                <span class="text-white text-sm">Forest</span>
                            </button>
                            <button onclick="setTheme('royal')" class="theme-swatch w-full flex items-center space-x-2 px-2 py-2 rounded hover:bg-barber-700 transition text-left">
                                <span class="w-4 h-4 rounded-full bg-purple-500"></span>
                                <span class="text-white text-sm">Royal</span>
                            </button>
                            <button onclick="setTheme('sunset')" class="theme-swatch w-full flex items-center space-x-2 px-2 py-2 rounded hover:bg-barber-700 transition text-left">
                                <span class="w-4 h-4 rounded-full bg-orange-500"></span>
                                <span class="text-white text-sm">Sunset</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
                <div id="adminMobileMenu" class="hidden mt-3 space-y-2 md:flex md:items-center md:space-x-3 md:space-y-0 md:mt-0">
                    <a href="index.php" class="block md:inline text-gray-400 hover:text-white transition text-sm" target="_blank">View Booking Page</a>
                    <a href="calendar.php" class="block md:inline text-barber-gold hover:text-barber-gold-light transition text-sm">📅 Calendar</a>
                    <a href="barber_schedule.php" class="block md:inline text-gray-400 hover:text-white transition text-sm">🕐 Schedules</a>
                    <a href="client_history.php" class="block md:inline text-gray-400 hover:text-white transition text-sm">👤 Client History</a>
                    <a href="print_sheet.php" class="block md:inline text-gray-400 hover:text-white transition text-sm" target="_blank">🖨️ Print Sheet</a>
                    <a href="business_hours.php" class="block md:inline text-gray-400 hover:text-white transition text-sm">⚙️ Settings</a>
                    <button onclick="document.getElementById('passwordModal').classList.remove('hidden')" class="block md:inline bg-barber-700 hover:bg-barber-600 text-white px-4 py-2 rounded-lg text-sm transition">Change Password</button>
                    <form method="POST" action="admin_logout.php" data-api-endpoint="/api/admin-logout" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="block md:inline bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm transition">Logout</button>
                    </form>
                </div>
        </div>
    </nav>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-barber-800 rounded-xl p-4 border border-barber-700">
                <p class="text-gray-400 text-sm">Total Bookings</p>
                <p class="text-2xl font-bold text-white"><?php echo $stats['total']; ?></p>
            </div>
            <div class="bg-yellow-900/30 rounded-xl p-4 border border-yellow-700">
                <p class="text-yellow-400 text-sm">Pending</p>
                <p class="text-2xl font-bold text-yellow-300"><?php echo $stats['pending']; ?></p>
            </div>
            <div class="bg-green-900/30 rounded-xl p-4 border border-green-700">
                <p class="text-green-400 text-sm">Confirmed</p>
                <p class="text-2xl font-bold text-green-300"><?php echo $stats['confirmed']; ?></p>
            </div>
            <div class="bg-blue-900/30 rounded-xl p-4 border border-blue-700">
                <p class="text-blue-400 text-sm">Completed</p>
                <p class="text-2xl font-bold text-blue-300"><?php echo $stats['completed']; ?></p>
            </div>
            <div class="bg-barber-800 rounded-xl p-4 border border-barber-700">
                <p class="text-gray-400 text-sm">Today</p>
                <p class="text-2xl font-bold text-barber-gold"><?php echo $stats['today']; ?></p>
            </div>
        </div>
        <div class="mb-6">
            <a href="send_reminders.php?admin_trigger=1" class="inline-block bg-blue-700 hover:bg-blue-600 text-white px-6 py-3 rounded-lg text-sm font-semibold transition" onclick="return confirm('Send reminder emails for all confirmed bookings tomorrow?')">
                📧 Send Reminders for Tomorrow
            </a>
            <span class="text-gray-400 text-sm ml-4">Sends email reminders to clients with confirmed bookings tomorrow</span>
        </div>
        <!-- Filters -->
        <div class="bg-barber-800 rounded-xl p-4 mb-8 border border-barber-700">
            <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Status</label>
                    <select name="status" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-3 py-2 text-white text-sm">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Date</label>
                    <select name="date" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-3 py-2 text-white text-sm">
                        <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Dates</option>
                        <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="tomorrow" <?php echo $date_filter === 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option>
                        <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>This Week</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, phone or email..." class="w-full bg-barber-700 border border-barber-600 rounded-lg px-3 py-2 text-white text-sm placeholder-gray-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-2 px-4 rounded-lg text-sm transition">Apply Filters</button>
                    <a href="export_bookings.php?<?php echo http_build_query($_GET); ?>" class="w-full bg-green-700 hover:bg-green-600 text-white font-bold py-2 px-4 rounded-lg text-sm transition text-center block">📥 Export CSV</a>
                </div>
            </form>
        </div>
        <!-- Bulk Actions -->
        <div class="bg-barber-800 rounded-xl p-4 mb-4 border border-barber-700">
            <form method="POST" action="" id="bulkForm" class="flex flex-col sm:flex-row items-start sm:items-center gap-3" data-api-endpoint="/api/admin-handler">
                <input type="hidden" name="action" value="bulk_actions">
                <select name="bulk_status" class="bg-barber-700 border border-barber-600 rounded-lg px-3 py-2 text-white text-sm w-full sm:w-auto">
                    <option value="">Bulk Actions...</option>
                    <option value="confirmed">Mark as Confirmed</option>
                    <option value="completed">Mark as Completed</option>
                    <option value="cancelled">Mark as Cancelled</option>
                    <option value="whatsapp">Send WhatsApp Reminder</option>
                </select>
                <button type="submit" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-4 py-2 rounded-lg text-sm transition w-full sm:w-auto">Apply</button>
                <span id="selectedCount" class="text-gray-400 text-sm hidden">0 selected</span>
            </form>
        </div>
        <!-- Bookings Table -->
        <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
            <?php if (empty($bookings)): ?>
                <div class="p-12 text-center">
                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                    <p class="text-gray-400 text-lg">No bookings found</p>
                    <p class="text-gray-500 text-sm mt-2">Try adjusting your filters</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                <th class="px-6 py-4"><input type="checkbox" id="selectAll" class="text-barber-gold focus:ring-barber-gold"></th>
                                <th class="px-6 py-4">Booking Ref</th>
                                <th class="px-6 py-4">Client</th>
                                <th class="px-6 py-4">Service</th>
                                <th class="px-6 py-4">Type</th>
                                <th class="px-6 py-4">Barber</th>
                                <th class="px-6 py-4">Date & Time</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Payment</th>
                                <th class="px-6 py-4">Refund</th>
                                <th class="px-6 py-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-barber-700">
                            <?php foreach ($bookings as $booking): ?>
                                <tr class="hover:bg-barber-700/50 transition">
                                    <td class="px-6 py-4"><input type="checkbox" name="booking_ids[]" value="<?php echo $booking['id']; ?>" class="booking-checkbox text-barber-gold focus:ring-barber-gold"></td>
                                    <td class="px-6 py-4"><span class="text-barber-gold font-semibold"><?php echo htmlspecialchars($booking['booking_reference'] ?? '#' . $booking['id']); ?></span></td>
                                    <td class="px-6 py-4">
                                        <a href="client_history.php?phone=<?php echo urlencode($booking['client_phone']); ?>" class="font-semibold text-white hover:text-barber-gold transition"><?php echo htmlspecialchars($booking['client_name']); ?></a>
                                        <div class="text-gray-400 text-sm"><?php echo htmlspecialchars($booking['client_phone']); ?></div>
                                        <?php if ($booking['client_email']): ?>
                                            <div class="text-gray-500 text-xs"><?php echo htmlspecialchars($booking['client_email']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($booking['client_address'])): ?>
                                            <div class="text-purple-400 text-xs mt-1 flex items-center">
                                                <i class="fas fa-map-marker-alt mr-1"></i>
                                                <span class="truncate max-w-[150px]"><?php echo htmlspecialchars($booking['client_address']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-white"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                                        <div class="text-barber-gold text-sm font-semibold">₵<?php echo number_format($booking['service_price'], 2); ?></div>
                                        <div class="text-gray-500 text-xs"><?php echo $booking['service_duration']; ?> min</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (!empty($booking['service_type']) && $booking['service_type'] === 'home'): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-purple-900/50 text-purple-300 border border-purple-700">
                                                <i class="fas fa-home mr-1"></i>Home
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-gray-700 text-gray-300 border border-gray-600">
                                                <i class="fas fa-store mr-1"></i>Shop
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($booking['home_service_fee']) && $booking['home_service_fee'] > 0): ?>
                                            <div class="text-purple-400 text-xs mt-1">+₵<?php echo number_format($booking['home_service_fee'], 2); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-white"><?php echo htmlspecialchars($booking['barber_name']); ?></td>
                                    <td class="px-6 py-4">
                                        <div class="text-white"><?php echo date('M j, Y', strtotime($booking['booking_date'])); ?></div>
                                        <div class="text-gray-400 text-sm"><?php echo date('g:i A', strtotime($booking['booking_time'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php
                                            $status_colors = ['pending' => 'bg-yellow-900/50 text-yellow-300 border-yellow-700', 'confirmed' => 'bg-green-900/50 text-green-300 border-green-700', 'completed' => 'bg-blue-900/50 text-blue-300 border-blue-700', 'cancelled' => 'bg-red-900/50 text-red-300 border-red-700'];
                                            $color_class = $status_colors[$booking['status']] ?? 'bg-gray-700 text-gray-300 border-gray-600';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold border <?php echo $color_class; ?>"><?php echo ucfirst($booking['status']); ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($booking['payment_status'] === 'success'): ?>
                                            <?php if ($booking['refund_status'] === 'none'): ?>
                                                <span class="text-gray-400 text-xs">No refund</span>
                                            <?php elseif ($booking['refund_status'] === 'requested'): ?>
                                                <span class="text-orange-300 text-xs">⏳ Requested</span>
                                            <?php elseif ($booking['refund_status'] === 'processed'): ?>
                                                <span class="text-green-300 text-xs">✅ Processed</span>
                                                <div class="text-gray-400 text-xs">₵<?php echo number_format($booking['refund_amount'], 2); ?></div>
                                            <?php elseif ($booking['refund_status'] === 'failed'): ?>
                                                <span class="text-red-300 text-xs">❌ Failed</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php
                                            $payment_status = $booking['payment_status'] ?? 'pending';
                                            $payment_colors = ['pending' => 'text-yellow-300', 'success' => 'text-green-300', 'failed' => 'text-red-300'];
                                            $payment_class = $payment_colors[$payment_status] ?? 'text-gray-300';
                                            $payment_icon = $payment_status === 'success' ? '✅' : ($payment_status === 'failed' ? '❌' : '⏳');
                                        ?>
                                        <div class="text-xs">
                                            <span class="<?php echo $payment_class; ?>"><?php echo $payment_icon; ?> <?php echo ucfirst($payment_status); ?></span>
                                            <?php if (!empty($booking['paid_amount'])): ?>
                                                <div class="text-barber-gold font-semibold">₵<?php echo number_format($booking['paid_amount'], 2); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col space-y-2">
                                            <?php 
                                                $whatsapp_client = formatWhatsAppNumber($booking['client_phone']);
                                                $whatsapp_business = formatWhatsAppNumber($settings['phone'] ?? '');
                                            ?>
                                            <?php if (!empty($whatsapp_client)): ?>
                                                <a href="https://wa.me/<?php echo $whatsapp_client; ?>?text=<?php echo urlencode(getWhatsAppMessage($booking)); ?>" target="_blank" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-xs font-semibold transition flex items-center justify-center space-x-1">
                                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                                    <span>WhatsApp Client</span>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($whatsapp_business)): ?>
                                                <a href="https://wa.me/<?php echo $whatsapp_business; ?>?text=<?php echo urlencode("Hi icut, I need to contact {$booking['client_name']} regarding their appointment " . ($booking['booking_reference'] ?? '#' . $booking['id']) . ". Please advise."); ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-xs font-semibold transition flex items-center justify-center space-x-1">
                                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                                    <span>WhatsApp Business</span>
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" action="" class="flex space-x-1">
                                                <input type="hidden" name="action" value="update_booking_status">
                                                <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <select name="new_status" onchange="this.form.submit()" class="bg-barber-700 border border-barber-600 rounded text-white text-xs px-2 py-1 flex-1">
                                                    <option value="">Update</option>
                                                    <option value="pending">Pending</option>
                                                    <option value="confirmed">Confirm</option>
                                                    <option value="completed">Complete</option>
                                                    <option value="cancelled">Cancel</option>
                                                </select>
                                            </form>
                                            <?php if ($booking['status'] === 'pending'): ?>
                                                <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Release this pending slot? This will cancel the booking and free the time slot.');" class="inline">
                                                    <input type="hidden" name="action" value="release_slot">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="bg-yellow-700 hover:bg-yellow-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">🔓 Release Slot</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($booking['payment_status'] === 'success' && $booking['refund_status'] === 'none'): ?>
                                                <button onclick="openRefundModal(<?php echo $booking['id']; ?>, <?php echo htmlspecialchars($booking['paid_amount']); ?>)" class="bg-orange-700 hover:bg-orange-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">💸 Refund</button>
                                            <?php endif; ?>
                                            <?php if ($booking['refund_status'] === 'processed'): ?>
                                                <span class="text-orange-300 text-xs">✅ Refunded</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- ============ CONTENT MANAGEMENT SECTION ============ -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Upload Messages -->
        <?php if (isset($_SESSION['upload_message'])): ?>
            <div class="mb-4 bg-green-900/50 border border-green-700 text-green-300 px-6 py-4 rounded-lg"><?php echo $_SESSION['upload_message']; unset($_SESSION['upload_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['upload_error'])): ?>
            <div class="mb-4 bg-red-900/50 border border-red-700 text-red-300 px-6 py-4 rounded-lg"><?php echo $_SESSION['upload_error']; unset($_SESSION['upload_error']); ?></div>
        <?php endif; ?>
        <!-- Tab Navigation -->
        <div class="flex overflow-x-auto gap-2 mb-6 border-b border-barber-700 pb-4 md:flex-wrap md:overflow-x-visible">
            <button onclick="showTab('barbers')" id="tab-barbers" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-gold text-barber-900 transition whitespace-nowrap">✂️ Barbers</button>
            <button onclick="showTab('services')" id="tab-services" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">💈 Services</button>
            <button onclick="showTab('packages')" id="tab-packages" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">📦 Packages</button>
            <button onclick="showTab('waitlist')" id="tab-waitlist" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">⏳ Waitlist</button>
            <button onclick="showTab('reviews')" id="tab-reviews" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">⭐ Reviews</button>
            <button onclick="showTab('loyalty')" id="tab-loyalty" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">🎯 Loyalty</button>
            <button onclick="showTab('gallery')" id="tab-gallery" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">📸 Gallery</button>
            <button onclick="showTab('activity')" id="tab-activity" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">📋 Activity Log</button>
            <button onclick="showTab('settings')" id="tab-settings" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-barber-700 text-white hover:bg-barber-600 transition whitespace-nowrap">⚙️ Site Settings</button>
        </div>
        <!-- ============ BARBERS MANAGEMENT ============ -->
        <div id="panel-barbers" class="tab-panel">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-white">Manage Barbers</h2>
                    <button onclick="document.getElementById('addBarberModal').classList.remove('hidden')" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Add Barber</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                <th class="px-6 py-4">Photo</th>
                                <th class="px-6 py-4">Name</th>
                                <th class="px-6 py-4">Specialization</th>
                                <th class="px-6 py-4">Phone</th>
                                <th class="px-6 py-4">Home Service</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-barber-700">
                            <?php foreach ($all_barbers as $barber): ?>
                                <tr class="hover:bg-barber-700/50 transition">
                                    <td class="px-6 py-4">
                                        <?php if ($barber['image']): ?>
                                            <img src="<?php echo htmlspecialchars($barber['image']); ?>" alt="<?php echo htmlspecialchars($barber['name']); ?>" class="w-12 h-12 rounded-full object-contain">
                                        <?php else: ?>
                                            <div class="w-12 h-12 rounded-full bg-barber-700 flex items-center justify-center text-barber-gold"><i class="fas fa-user"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-white font-semibold"><?php echo htmlspecialchars($barber['name']); ?></td>
                                    <td class="px-6 py-4 text-gray-400"><?php echo htmlspecialchars($barber['specialization']); ?></td>
                                    <td class="px-6 py-4 text-gray-400"><?php echo htmlspecialchars($barber['phone']); ?></td>
                                    <td class="px-6 py-4">
                                        <button onclick="toggleBarberHomeService(<?php echo $barber['id']; ?>, <?php echo ($barber['offers_home_service'] ?? 0) ? 1 : 0; ?>)" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors <?php echo ($barber['offers_home_service'] ?? 0) ? 'bg-purple-600' : 'bg-gray-600'; ?>">
                                            <span class="sr-only">Toggle home service</span>
                                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform <?php echo ($barber['offers_home_service'] ?? 0) ? 'translate-x-6' : 'translate-x-1'; ?>"></span>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $barber['is_active'] ? 'bg-green-900/50 text-green-300 border border-green-700' : 'bg-red-900/50 text-red-300 border border-red-700'; ?>"><?php echo $barber['is_active'] ? 'Active' : 'Inactive'; ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex space-x-2">
                                            <button onclick="editBarber(<?php echo $barber['id']; ?>, '<?php echo addslashes($barber['name']); ?>', '<?php echo addslashes($barber['phone']); ?>', '<?php echo addslashes($barber['specialization']); ?>')" class="bg-yellow-700 hover:bg-yellow-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">✏️ Edit</button>
                                            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="upload_barber_image">
                                                <input type="hidden" name="barber_id" value="<?php echo $barber['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <label class="cursor-pointer bg-blue-700 hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition inline-block">📷 Upload<input type="file" name="barber_image" accept="image/*" class="hidden" onchange="this.form.submit()"></label>
                                            </form>
                                            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Delete this barber?');">
                                                <input type="hidden" name="action" value="delete_barber">
                                                <input type="hidden" name="barber_id" value="<?php echo $barber['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <button type="submit" class="bg-red-700 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">🗑️ Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- ============ SERVICES MANAGEMENT ============ -->
        <div id="panel-services" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-white">Manage Services</h2>
                    <button onclick="document.getElementById('addServiceModal').classList.remove('hidden')" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Add Service</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                <th class="px-6 py-4">Image</th>
                                <th class="px-6 py-4">Name</th>
                                <th class="px-6 py-4">Description</th>
                                <th class="px-6 py-4">Price</th>
                                <th class="px-6 py-4">Duration</th>
                                <th class="px-6 py-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-barber-700">
                            <?php foreach ($all_services as $service): ?>
                                <tr class="hover:bg-barber-700/50 transition">
                                    <td class="px-6 py-4">
                                        <?php if ($service['image']): ?>
                                            <img src="<?php echo htmlspecialchars($service['image']); ?>" alt="<?php echo htmlspecialchars($service['name']); ?>" class="w-16 h-12 rounded-lg object-contain">
                                        <?php else: ?>
                                            <div class="w-16 h-12 rounded-lg bg-barber-700 flex items-center justify-center text-barber-gold"><i class="fas fa-cut"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-white font-semibold"><?php echo htmlspecialchars($service['name']); ?></td>
                                    <td class="px-6 py-4 text-gray-400 text-sm max-w-xs"><?php echo htmlspecialchars($service['description']); ?></td>
                                    <td class="px-6 py-4 text-barber-gold font-semibold">₵<?php echo number_format($service['price'], 2); ?></td>
                                    <td class="px-6 py-4 text-gray-400"><?php echo $service['duration_minutes']; ?> min</td>
                                    <td class="px-6 py-4">
                                        <div class="flex space-x-2">
                                            <button onclick="editService(<?php echo $service['id']; ?>, '<?php echo addslashes($service['name']); ?>', '<?php echo addslashes($service['description']); ?>', <?php echo $service['price']; ?>, <?php echo $service['duration_minutes']; ?>)" class="bg-yellow-700 hover:bg-yellow-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">✏️ Edit</button>
                                            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="upload_service_image">
                                                <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <label class="cursor-pointer bg-blue-700 hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition inline-block">📷 Upload<input type="file" name="service_image" accept="image/*" class="hidden" onchange="this.form.submit()"></label>
                                            </form>
                                            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Delete this service?');">
                                                <input type="hidden" name="action" value="delete_service">
                                                <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <button type="submit" class="bg-red-700 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">🗑️ Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- ============ PACKAGES MANAGEMENT ============ -->
        <div id="panel-packages" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700 flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-bold text-white">Service Packages</h2>
                        <p class="text-gray-400 text-sm">Create bundles and combos</p>
                    </div>
                    <button onclick="document.getElementById('addPackageModal').classList.remove('hidden')" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Add Package</button>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($all_packages)): ?>
                        <p class="text-gray-400 text-center py-8">No packages yet. Create your first bundle!</p>
                    <?php else: ?>
                        <table class="w-full">
                            <thead>
                                <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                    <th class="px-6 py-4">Name</th>
                                    <th class="px-6 py-4">Description</th>
                                    <th class="px-6 py-4">Price</th>
                                    <th class="px-6 py-4">Services</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-6 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-barber-700">
                                <?php foreach ($all_packages as $package): ?>
                                    <tr class="hover:bg-barber-700/50 transition">
                                        <td class="px-6 py-4 text-white font-semibold"><?php echo htmlspecialchars($package['name']); ?></td>
                                        <td class="px-6 py-4 text-gray-400 text-sm max-w-xs"><?php echo htmlspecialchars($package['description']); ?></td>
                                        <td class="px-6 py-4 text-barber-gold font-semibold">₵<?php echo number_format($package['price'], 2); ?></td>
                                        <td class="px-6 py-4 text-gray-400 text-sm">
                                            <?php
                                            $service_ids = explode(',', $package['service_ids']);
                                            $service_names = [];
                                            foreach ($service_ids as $sid) {
                                                foreach ($all_services as $s) {
                                                    if ($s['id'] == $sid) {
                                                        $service_names[] = $s['name'];
                                                        break;
                                                    }
                                                }
                                            }
                                            echo htmlspecialchars(implode(', ', $service_names));
                                            ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $package['is_active'] ? 'bg-green-900/50 text-green-300 border border-green-700' : 'bg-red-900/50 text-red-300 border border-red-700'; ?>">
                                                <?php echo $package['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex space-x-2">
                                                <button onclick="editPackage(<?php echo $package['id']; ?>, '<?php echo addslashes($package['name']); ?>', '<?php echo addslashes($package['description']); ?>', <?php echo $package['price']; ?>, '<?php echo addslashes($package['service_ids']); ?>', <?php echo $package['is_active']; ?>)" class="bg-yellow-700 hover:bg-yellow-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">✏️ Edit</button>
                                                <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Delete this package?');">
                                                    <input type="hidden" name="action" value="delete_package">
                                                    <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="bg-red-700 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">🗑️ Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- ============ WAITLIST MANAGEMENT ============ -->
        <div id="panel-waitlist" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-bold text-white">Waitlist</h2>
                            <p class="text-gray-400 text-sm">Clients waiting for available slots</p>
                        </div>
                        <span class="bg-blue-900/50 text-blue-300 px-3 py-1 rounded-full text-sm"><?php echo getWaitingClientsCount(); ?> waiting</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <?php
                    ensureWaitlistTableExists();
                    $waitlist = $db->query("SELECT w.*, br.name as barber_name, s.name as service_name FROM waitlist w JOIN barbers br ON w.barber_id = br.id JOIN services s ON w.service_id = s.id WHERE w.status = 'waiting' ORDER BY w.created_at ASC")->fetchAll();
                    ?>
                    <?php if (empty($waitlist)): ?>
                        <p class="text-gray-400 text-center py-8">No clients on the waitlist.</p>
                    <?php else: ?>
                        <table class="w-full">
                            <thead>
                                <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                    <th class="px-6 py-4">Client</th>
                                    <th class="px-6 py-4">Phone</th>
                                    <th class="px-6 py-4">Service</th>
                                    <th class="px-6 py-4">Barber</th>
                                    <th class="px-6 py-4">Preferred Date</th>
                                    <th class="px-6 py-4">Preferred Time</th>
                                    <th class="px-6 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-barber-700">
                                <?php foreach ($waitlist as $item): ?>
                                    <tr class="hover:bg-barber-700/50 transition">
                                        <td class="px-6 py-4 text-white"><?php echo htmlspecialchars($item['client_name']); ?></td>
                                        <td class="px-6 py-4 text-gray-400"><?php echo htmlspecialchars($item['client_phone']); ?></td>
                                        <td class="px-6 py-4 text-gray-300"><?php echo htmlspecialchars($item['service_name']); ?></td>
                                        <td class="px-6 py-4 text-gray-300"><?php echo htmlspecialchars($item['barber_name']); ?></td>
                                        <td class="px-6 py-4 text-white"><?php echo date('M j, Y', strtotime($item['preferred_date'])); ?></td>
                                        <td class="px-6 py-4 text-white"><?php echo date('g:i A', strtotime($item['preferred_time'])); ?></td>
                                        <td class="px-6 py-4">
                                            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Notify this client?');">
                                                <input type="hidden" name="action" value="notify_waitlist">
                                                <input type="hidden" name="waitlist_id" value="<?php echo $item['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <button type="submit" class="bg-green-700 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">📧 Notify</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- ============ REVIEWS MANAGEMENT ============ -->
        <div id="panel-reviews" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700 flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-bold text-white">Manage Reviews</h2>
                        <p class="text-gray-400 text-sm">Approve or delete customer reviews</p>
                    </div>
                    <span class="bg-yellow-900/50 text-yellow-300 px-3 py-1 rounded-full text-sm"><?php echo $db->query("SELECT COUNT(*) FROM reviews WHERE is_approved = 0")->fetchColumn(); ?> pending</span>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($all_reviews)): ?>
                        <p class="text-gray-400 text-center py-8">No reviews yet.</p>
                    <?php else: ?>
                        <table class="w-full">
                            <thead>
                                <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                    <th class="px-6 py-4">Client</th>
                                    <th class="px-6 py-4">Rating</th>
                                    <th class="px-6 py-4">Service</th>
                                    <th class="px-6 py-4">Comment</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-6 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-barber-700">
                                <?php foreach ($all_reviews as $review): ?>
                                    <tr class="hover:bg-barber-700/50 transition">
                                        <td class="px-6 py-4 text-white font-semibold"><?php echo htmlspecialchars($review['client_name']); ?></td>
                                        <td class="px-6 py-4">
                                            <div class="flex space-x-1">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star text-<?php echo $i <= $review['rating'] ? 'yellow' : 'gray' ?>-400"></i>
                                                <?php endfor; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-gray-400"><?php echo htmlspecialchars($review['service_name']); ?></td>
                                        <td class="px-6 py-4 text-gray-300 max-w-xs"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></td>
                                        <td class="px-6 py-4">
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $review['is_approved'] ? 'bg-green-900/50 text-green-300 border border-green-700' : 'bg-yellow-900/50 text-yellow-300 border border-yellow-700'; ?>"><?php echo $review['is_approved'] ? 'Approved' : 'Pending'; ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex space-x-2">
                                                <?php if (!$review['is_approved']): ?>
                                                    <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler">
                                                        <input type="hidden" name="action" value="approve_review">
                                                        <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <button type="submit" class="bg-green-700 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">✓ Approve</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Delete this review?');">
                                                    <input type="hidden" name="action" value="delete_review">
                                                    <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="bg-red-700 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition">🗑️ Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- ============ LOYALTY PROGRAM ============ -->
        <div id="panel-loyalty" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700 flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-bold text-white">Loyalty Program</h2>
                        <p class="text-gray-400 text-sm">Manage client loyalty points</p>
                    </div>
                    <button onclick="document.getElementById('addLoyaltyModal').classList.remove('hidden')" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Add Points</button>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($loyalty_members)): ?>
                        <p class="text-gray-400 text-center py-8">No loyalty members yet.</p>
                    <?php else: ?>
                        <table class="w-full">
                            <thead>
                                <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                    <th class="px-6 py-4">Phone</th>
                                    <th class="px-6 py-4">Points</th>
                                    <th class="px-6 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-barber-700">
                                <?php foreach ($loyalty_members as $member): ?>
                                    <tr class="hover:bg-barber-700/50 transition">
                                        <td class="px-6 py-4 text-white"><?php echo htmlspecialchars($member['phone']); ?></td>
                                        <td class="px-6 py-4 text-barber-gold font-bold"><?php echo $member['points']; ?></td>
                                        <td class="px-6 py-4">
                                            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="flex flex-col sm:flex-row gap-2">
                                                <input type="hidden" name="action" value="redeem_loyalty_points">
                                                <input type="hidden" name="loyalty_id" value="<?php echo $member['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="number" name="loyalty_points" placeholder="pts" min="1" max="<?php echo $member['points']; ?>" class="w-full sm:w-20 bg-barber-700 border border-barber-600 rounded px-2 py-1 text-white text-sm">
                                                <button type="submit" class="bg-blue-700 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs font-semibold transition w-full sm:w-auto">Redeem</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Add Loyalty Points Modal -->
        <div id="addLoyaltyModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
            <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-white">Add Loyalty Points</h2>
                    <button onclick="document.getElementById('addLoyaltyModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
                </div>
                <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-4">
                    <input type="hidden" name="action" value="add_loyalty_points">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Phone Number *</label>
                        <input type="tel" name="loyalty_phone" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Points *</label>
                        <input type="number" name="loyalty_points" required min="1" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Add Points</button>
            </form>
        </div>
    </div>

    <!-- Admin Guide Modal -->
    <div id="adminGuideModal" class="hidden fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center z-50 p-4">
        <div class="bg-barber-800 rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto border border-barber-700 shadow-2xl">
            <div class="sticky top-0 bg-barber-800 border-b border-barber-700 p-6 flex justify-between items-center z-10">
                <h2 class="text-2xl font-bold text-white flex items-center space-x-2">
                    <i class="fas fa-book-open text-barber-gold"></i>
                    <span>Admin Dashboard Guide</span>
                </h2>
                <button onclick="document.getElementById('adminGuideModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-3xl leading-none">&times;</button>
            </div>
            <div class="p-6 space-y-8">
                <!-- Navigation -->
                <section>
                    <h3 class="text-xl font-bold text-barber-gold mb-4 flex items-center space-x-2">
                        <i class="fas fa-compass"></i>
                        <span>Dashboard Navigation</span>
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">📊 Dashboard</p>
                            <p class="text-gray-400 text-xs">Stats, filters, and the bookings table</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">✂️ Barbers</p>
                            <p class="text-gray-400 text-xs">Manage barber profiles, photos, and details</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">💈 Services</p>
                            <p class="text-gray-400 text-xs">Add, edit, or remove services and pricing</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">📦 Packages</p>
                            <p class="text-gray-400 text-xs">Create service packages and bundles</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">⏳ Waitlist</p>
                            <p class="text-gray-400 text-xs">View and manage waitlisted clients</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">⭐ Reviews</p>
                            <p class="text-gray-400 text-xs">Approve or delete client reviews</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">🎯 Loyalty</p>
                            <p class="text-gray-400 text-xs">Manage loyalty points and members</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">📸 Gallery</p>
                            <p class="text-gray-400 text-xs">Upload and manage gallery images</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">📋 Activity Log</p>
                            <p class="text-gray-400 text-xs">Track all admin actions and changes</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">⚙️ Site Settings</p>
                            <p class="text-gray-400 text-xs">Configure site info, payments, email, and more</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">🖨️ Print Sheet</p>
                            <p class="text-gray-400 text-xs">Print daily booking sheets</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">👤 Client History</p>
                            <p class="text-gray-400 text-xs">View client visit history and stats</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">🕐 Schedules</p>
                            <p class="text-gray-400 text-xs">Set barber working hours and availability</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">📅 Calendar</p>
                            <p class="text-gray-400 text-xs">View bookings in calendar format</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">🔑 Change Password</p>
                            <p class="text-gray-400 text-xs">Update your username, email, or password</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">📧 Send Reminders</p>
                            <p class="text-gray-400 text-xs">Email clients with confirmed bookings tomorrow</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">🕒 Business Hours</p>
                            <p class="text-gray-400 text-xs">Set daily open/close times and closed days</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">👁️ View Booking Page</p>
                            <p class="text-gray-400 text-xs">Open the public site in a new tab</p>
                        </div>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-3">
                            <p class="text-white font-semibold text-sm">📊 Dashboard Overview</p>
                            <p class="text-gray-400 text-xs">See the booking workflow diagram (below)</p>
                        </div>
                    </div>
                </section>

                <!-- Dashboard Overview -->
                <section>
                    <h3 class="text-xl font-bold text-barber-gold mb-4 flex items-center space-x-2">
                        <i class="fas fa-gauge-high"></i>
                        <span>Dashboard Overview</span>
                    </h3>
                    <div class="bg-barber-700/30 border border-barber-700 rounded-xl p-5 space-y-4">
                        <div>
                            <p class="text-white font-semibold mb-2">📊 Stat Cards (top of the dashboard)</p>
                            <p class="text-gray-300 text-sm leading-relaxed">Five cards give a quick snapshot: <strong class="text-white">Total Bookings</strong>, <strong class="text-yellow-300">Pending</strong> (awaiting confirmation), <strong class="text-green-300">Confirmed</strong>, <strong class="text-blue-300">Completed</strong>, and <strong class="text-barber-gold">Today</strong> (bookings scheduled for today).</p>
                        </div>
                        <div>
                            <p class="text-white font-semibold mb-2">🔍 Find &amp; Act on Bookings</p>
                            <ul class="list-disc list-inside text-gray-300 text-sm space-y-1">
                                <li><strong class="text-white">Status</strong> dropdown: All / Pending / Confirmed / Completed / Cancelled.</li>
                                <li><strong class="text-white">Date</strong> dropdown: All / Today / Tomorrow / This Week.</li>
                                <li><strong class="text-white">Search</strong> box to find a client by name, phone, or email.</li>
                                <li>Press <strong class="text-barber-gold">Apply Filters</strong> to refresh the list.</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-white font-semibold mb-2">📥 Export CSV &amp; Bulk Actions</p>
                            <ul class="list-disc list-inside text-gray-300 text-sm space-y-1">
                                <li><strong class="text-green-400">📥 Export CSV</strong> downloads the current filtered bookings to a spreadsheet.</li>
                                <li><strong class="text-white">Bulk Actions</strong>: tick rows (or use the top checkbox) and apply Mark Confirmed / Completed / Cancelled, or Send WhatsApp Reminder.</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-white font-semibold mb-2">📧 Send Reminders for Tomorrow</p>
                            <p class="text-gray-300 text-sm leading-relaxed">Emails a reminder to every <em>confirmed</em> booking scheduled for tomorrow. Run at the end of the day.</p>
                        </div>
                        <div>
                            <p class="text-white font-semibold mb-2">🧾 Bookings table — per-row actions</p>
                            <ul class="list-disc list-inside text-gray-300 text-sm space-y-1">
                                <li>Set <strong class="text-white">status</strong> with the row dropdown (Pending / Confirm / Complete / Cancel) — saves automatically.</li>
                                <li><strong class="text-green-400">WhatsApp Client</strong> — pre-filled confirmation message to the client.</li>
                                <li><strong class="text-blue-400">WhatsApp Business</strong> — message to the shop's own number to contact the client manually.</li>
                                <li><strong class="text-yellow-400">🔓 Release Slot</strong> (pending bookings) — cancels and frees a time slot the client didn't confirm.</li>
                                <li><strong class="text-orange-400">💸 Refund</strong> (paid, not yet refunded) — opens the refund modal. See Refund Guide below.</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- Booking Workflow Flow Chart -->
                <section>
                    <h3 class="text-xl font-bold text-barber-gold mb-4 flex items-center space-x-2">
                        <i class="fas fa-diagram-project"></i>
                        <span>Booking Workflow — Flow Chart</span>
                    </h3>
                    <div class="flex flex-col items-center space-y-2 text-sm">
                        <div class="w-full max-w-sm bg-barber-700/50 border border-barber-700 rounded-lg p-3 text-center">
                            <p class="text-white font-semibold">🧑‍🤝‍🧑 Client books online</p>
                            <p class="text-gray-400 text-xs">Booking page → Shop service or Home service</p>
                        </div>
                        <i class="fas fa-arrow-down text-barber-gold text-lg"></i>
                        <div class="w-full max-w-sm bg-yellow-900/30 border border-yellow-700 rounded-lg p-3 text-center">
                            <p class="text-yellow-300 font-semibold">⏳ New booking = Pending</p>
                            <p class="text-gray-400 text-xs">Appears in the bookings table &amp; stat card</p>
                        </div>
                        <i class="fas fa-arrow-down text-barber-gold text-lg"></i>
                        <div class="w-full max-w-sm bg-barber-700/50 border border-barber-700 rounded-lg p-3 text-center">
                            <p class="text-white font-semibold">📞 Admin contacts the client</p>
                            <p class="text-gray-400 text-xs">WhatsApp Client / call / email</p>
                        </div>
                        <i class="fas fa-arrow-down text-barber-gold text-lg"></i>
                        <div class="w-full max-w-sm bg-green-900/30 border border-green-700 rounded-lg p-3 text-center">
                            <p class="text-green-300 font-semibold">✅ Confirm → status = Confirmed</p>
                        </div>
                        <i class="fas fa-arrow-down text-barber-gold text-lg"></i>
                        <div class="w-full max-w-sm bg-blue-900/30 border border-blue-700 rounded-lg p-3 text-center">
                            <p class="text-blue-300 font-semibold">📅 Service day</p>
                            <p class="text-gray-400 text-xs">Plan with Calendar / Print Sheet / Schedules</p>
                        </div>
                        <i class="fas fa-arrow-down text-barber-gold text-lg"></i>
                        <div class="w-full max-w-sm bg-barber-700/50 border border-barber-700 rounded-lg p-3 text-center">
                            <p class="text-white font-semibold">💇 Service done → status = Completed</p>
                        </div>
                        <i class="fas fa-arrow-down text-barber-gold text-lg"></i>
                        <div class="w-full max-w-sm bg-barber-700 border border-barber-600 rounded-lg p-3 text-center">
                            <p class="text-white font-semibold">💰 Payment status?</p>
                        </div>
                        <div class="w-full grid grid-cols-2 gap-3">
                            <div class="flex flex-col items-center">
                                <i class="fas fa-arrow-down text-green-400 text-lg"></i>
                                <div class="w-full bg-green-900/30 border border-green-700 rounded-lg p-3 text-center">
                                    <p class="text-green-300 font-semibold">Paid in full</p>
                                    <p class="text-gray-400 text-xs">Done ✔</p>
                                </div>
                            </div>
                            <div class="flex flex-col items-center">
                                <i class="fas fa-arrow-down text-orange-400 text-lg"></i>
                                <div class="w-full bg-orange-900/30 border border-orange-700 rounded-lg p-3 text-center">
                                    <p class="text-orange-300 font-semibold">Refund needed</p>
                                    <p class="text-gray-400 text-xs">Process Refund (3–10 days) or cash</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="text-gray-400 text-xs mt-4 leading-relaxed">Tip: If a client never confirms, use <strong class="text-yellow-400">Release Slot</strong> to free the time for other clients.</p>
                </section>

                <!-- Feature Reference -->
                <section>
                    <h3 class="text-xl font-bold text-barber-gold mb-4 flex items-center space-x-2">
                        <i class="fas fa-list-check"></i>
                        <span>Feature Reference</span>
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">🧑‍🤝‍🧑 Bookings &amp; Customers</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Waitlist</strong> — clients waiting for a slot; notify them or remove them.</li>
                                <li><strong>Reviews</strong> — approve good reviews to publish them, or delete inappropriate ones.</li>
                                <li><strong>Loyalty</strong> — add points by phone number and redeem them for rewards.</li>
                                <li><strong>Client History</strong> — past visits, preferences, and saved notes.</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">💇 Catalog &amp; Media</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Barbers</strong> — name, specialty, phone, photo, active status, home-service availability.</li>
                                <li><strong>Services</strong> — name, description, price, duration, photo, active status.</li>
                                <li><strong>Packages</strong> — bundle multiple services with a combined price.</li>
                                <li><strong>Gallery</strong> — upload photos/videos and delete items.</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">🗂️ Operations</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Activity Log</strong> — audit trail of every admin action.</li>
                                <li><strong>Print Sheet</strong> — printable daily schedule.</li>
                                <li><strong>Schedules</strong> — each barber's working days and hours.</li>
                                <li><strong>Calendar</strong> — month view of all bookings.</li>
                                <li><strong>Send Reminders</strong> — email reminders for tomorrow's confirmed bookings.</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">🔐 Account &amp; Security</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Change Password</strong> — update your admin username, email, and password.</li>
                                <li><strong>Two-Factor Auth (2FA)</strong> — extra verification step at login with an authenticator app.</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- Site Settings Explained -->
                <section>
                    <h3 class="text-xl font-bold text-orange-400 mb-4 flex items-center space-x-2">
                        <i class="fas fa-cogs"></i>
                        <span>Site Settings Explained</span>
                    </h3>
                    <p class="text-gray-300 text-sm mb-4">Everything under <strong class="text-white">Site Settings</strong> in the sidebar controls what clients see on the booking page. Changes save instantly — no redeploy needed.</p>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">📈 Hero Statistics</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Years of Experience / Happy Clients / Expert Barbers</strong> — the big numbers shown at the top of the homepage.</li>
                                <li>Update these as your business grows (e.g. after every milestone).</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">📍 Location &amp; Map</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Shop Address</strong> — shown in the footer and "Find Us" section.</li>
                                <li><strong>Google Maps Embed URL</strong> — paste the embed link (not the share link) to display the live map.</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">📞 Contact Information</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Phone / WhatsApp / Email</strong> — used by the contact buttons and the WhatsApp quick-actions throughout the site.</li>
                                <li>Use international format for WhatsApp (e.g. <span class="text-amber-300">+233201234567</span>).</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">🕐 Business Hours &amp; Home Service</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Opening / Closing time per day</strong> — drives which time slots appear in the booking form.</li>
                                <li><strong>Home Service</strong> — toggle on/off and set the extra travel fee; clients can pick "Home Service" at checkout.</li>
                            </ul>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">🖼️ Site Logo &amp; Footer</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Upload Logo</strong> — replaces the icon in the navbar and favicon (PNG/SVG recommended).</li>
                                <li><strong>Footer About text</strong> — the short blurb under the logo in the footer.</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">✉️ Email Templates</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li>Customize the <strong>booking confirmation</strong> and <strong>reminder</strong> emails sent to clients.</li>
                                <li>Placeholders like <span class="text-amber-300">{name}</span>, <span class="text-amber-300">{date}</span>, <span class="text-amber-300">{time}</span> are replaced automatically.</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">💳 Paystack (Payments)</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Secret &amp; Public keys</strong> — from your Paystack dashboard; keep the secret key private.</li>
                                <li><strong>Currency</strong> — GHS/NGN/USD; <strong>Enabled</strong> toggles online payment vs. pay-at-shop.</li>
                                <li>Use <strong>test keys</strong> while trialing, then switch to <strong>live keys</strong> before going public.</li>
                            </ul>
                        </div>
                        <div class="bg-barber-700/30 border border-barber-700 rounded-lg p-4">
                            <p class="text-white font-semibold text-sm mb-2">🗄️ Database Backup</p>
                            <ul class="list-disc list-inside text-gray-300 text-xs space-y-1">
                                <li><strong>Download Backup</strong> — exports every table (structure + data) as a single .sql file.</li>
                                <li>Download a backup before big edits or migrations, and store copies off-device.</li>
                            </ul>
                        </div>
                    </div>
                </section>


                        </div>

                <!-- Refund Guide -->
                <section>
                    <h3 class="text-xl font-bold text-orange-400 mb-4 flex items-center space-x-2">
                        <i class="fas fa-undo-alt"></i>
                        <span>Refund Guide</span>
                    </h3>
                    <div class="bg-barber-700/30 border border-barber-700 rounded-xl p-5 space-y-4">
                        <div>
                            <p class="text-white font-semibold mb-2">Refund Timeline</p>
                            <p class="text-gray-300 text-sm leading-relaxed">That is the Paystack / banking network timeline, not something the admin can control from their end.</p>
                            <p class="text-gray-300 text-sm leading-relaxed mt-2">Once Paystack receives the refund request, they push it back through the payment rails to the customer's bank or mobile money provider. The receiving bank then credits the customer's account. This handoff typically takes <strong class="text-barber-gold">3-10 business days</strong>, sometimes longer depending on:</p>
                            <ul class="list-disc list-inside text-gray-300 text-sm mt-2 space-y-1">
                                <li>The customer's bank</li>
                                <li>Whether it is a card refund, bank transfer, or mobile money</li>
                                <li>Weekend and holiday delays</li>
                            </ul>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-green-900/20 border border-green-700 rounded-lg p-4">
                                <p class="text-green-300 font-semibold text-sm mb-2">What the admin CAN do:</p>
                                <ul class="text-gray-300 text-xs space-y-1 list-disc list-inside">
                                    <li>Process the refund immediately from the admin panel</li>
                                    <li>Mark it as processed in the system</li>
                                    <li>Send the confirmation email to the client</li>
                                    <li>Track the refund reference in the admin panel</li>
                                    <li>Contact Paystack support if a refund seems stuck</li>
                                </ul>
                            </div>
                            <div class="bg-red-900/20 border border-red-700 rounded-lg p-4">
                                <p class="text-red-300 font-semibold text-sm mb-2">What the admin CANNOT do:</p>
                                <ul class="text-gray-300 text-xs space-y-1 list-disc list-inside">
                                    <li>Force the funds to appear instantly in the client's account — that is controlled by the payment network and bank</li>
                                    <li>Speed up Paystack's internal processing — it is automated</li>
                                </ul>
                            </div>
                        </div>
                        <div class="bg-blue-900/20 border border-blue-700 rounded-lg p-4">
                            <p class="text-blue-300 font-semibold text-sm mb-2">Instant Refund Alternative (In-Person):</p>
                            <p class="text-gray-300 text-xs">If you want instant refunds for in-person clients, the shop can refund in cash at the counter instead of using Paystack, mark the booking as refunded manually in the admin panel, and keep a record that it was a cash refund, not a Paystack refund. For online payments, the 3-10 day window is standard and unavoidable.</p>
                        </div>
                    </div>
                </section>
            </div>
            <div class="sticky bottom-0 bg-barber-800 border-t border-barber-700 p-4 flex justify-end">
                <button onclick="document.getElementById('adminGuideModal').classList.add('hidden')" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-2 rounded-lg transition">Got it</button>
            </div>
        </div>
    </div>
        <!-- ============ ACTIVITY LOG ============ -->
        <div id="panel-activity" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700">
                    <h2 class="text-xl font-bold text-white">Admin Activity Log</h2>
                    <p class="text-gray-400 text-sm">Track all admin actions</p>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($activity_logs)): ?>
                        <p class="text-gray-400 text-center py-8">No activity logged yet.</p>
                    <?php else: ?>
                        <table class="w-full">
                            <thead>
                                <tr class="bg-barber-900 text-left text-gray-400 text-sm uppercase">
                                    <th class="px-6 py-4">Date & Time</th>
                                    <th class="px-6 py-4">Admin</th>
                                    <th class="px-6 py-4">Type</th>
                                    <th class="px-6 py-4">Details</th>
                                    <th class="px-6 py-4">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-barber-700">
                                <?php foreach ($activity_logs as $log): ?>
                                    <tr class="hover:bg-barber-700/50 transition">
                                        <td class="px-6 py-4 text-gray-400 text-sm"><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></td>
                                        <td class="px-6 py-4 text-white"><?php echo htmlspecialchars($log['admin_name']); ?></td>
                                        <td class="px-6 py-4 text-barber-gold"><?php echo htmlspecialchars($log['activity_type']); ?></td>
                                        <td class="px-6 py-4 text-gray-300"><?php echo htmlspecialchars($log['details']); ?></td>
                                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- ============ GALLERY MANAGEMENT ============ -->
        <div id="panel-gallery" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-white">Manage Gallery</h2>
                    <button onclick="document.getElementById('addGalleryModal').classList.remove('hidden')" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Upload Media</button>
                </div>
                <div class="p-6">
                    <?php if (empty($gallery_items)): ?>
                        <p class="text-gray-400 text-center py-8">No gallery items yet. Click "Upload Media" to add images or videos.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <?php foreach ($gallery_items as $item): ?>
                                <div class="relative group rounded-xl overflow-hidden border border-barber-700">
                                    <?php if ($item['media_type'] === 'video'): ?>
                                        <video src="<?php echo htmlspecialchars($item['file_path']); ?>" class="w-full h-40 object-contain" muted></video>
                                        <div class="absolute top-2 right-2 bg-black/70 text-white text-xs px-2 py-1 rounded-full"><i class="fas fa-video mr-1"></i>Video</div>
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars($item['file_path']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="w-full h-40 object-contain">
                                    <?php endif; ?>
                                    <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition flex flex-col items-center justify-center p-4">
                                        <p class="text-white text-sm font-semibold text-center mb-2"><?php echo htmlspecialchars($item['title']); ?></p>
                                        <div class="flex space-x-2">
                                            <button onclick="editGallery(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['title']); ?>')" class="bg-blue-700 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-xs font-semibold transition">✏️ Edit</button>
                                            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Delete this gallery item?');">
                                                <input type="hidden" name="action" value="delete_gallery">
                                                <input type="hidden" name="gallery_id" value="<?php echo $item['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <button type="submit" class="bg-red-700 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-xs font-semibold transition">🗑️ Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- ============ SITE SETTINGS MANAGEMENT ============ -->
        <div id="panel-settings" class="tab-panel hidden">
            <div class="bg-barber-800 rounded-xl border border-barber-700 overflow-hidden">
                <div class="p-6 border-b border-barber-700">
                    <h2 class="text-xl font-bold text-white">Site Settings</h2>
                    <p class="text-gray-400 text-sm mt-1">Edit the content shown on the public booking page</p>
                </div>
                <div class="p-6">
                    <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-6">
                        <input type="hidden" name="action" value="save_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <!-- Stats Section -->
                        <div>
                            <h3 class="text-white font-semibold mb-4">📊 Hero Statistics</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Happy Clients</label>
                                    <input type="text" name="happy_clients" value="<?php echo htmlspecialchars($settings['happy_clients'] ?? '5K+'); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Years Experience</label>
                                    <input type="text" name="years_exp" value="<?php echo htmlspecialchars($settings['years_exp'] ?? '15+'); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Rating</label>
                                    <input type="text" name="rating" value="<?php echo htmlspecialchars($settings['rating'] ?? '4.9'); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                            </div>
                        </div>
                        <!-- Location / Map Section -->
                        <div>
                            <h3 class="text-white font-semibold mb-4">📍 Location & Map</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Full Address</label>
                                    <input type="text" name="address" value="<?php echo htmlspecialchars($settings['address'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white" placeholder="123 Main Street, Accra">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Google Maps Embed URL (optional)</label>
                                    <input type="url" name="map_embed_url" value="<?php echo htmlspecialchars($settings['map_embed_url'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white" placeholder="https://www.google.com/maps/embed?...">
                                </div>
                            </div>
                            <p class="text-gray-500 text-xs mt-2">Get the embed URL from Google Maps → Share → Embed a map</p>
                        </div>
                        <!-- Contact Section -->
                        <div>
                            <h3 class="text-white font-semibold mb-4">📞 Contact Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Phone</label>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($settings['phone'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Email</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">WhatsApp Number</label>
                                    <input type="text" name="whatsapp_number" value="<?php echo htmlspecialchars($settings['whatsapp_number'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white" placeholder="e.g. 233501234567">
                                </div>
                            </div>
                        </div>
                        <!-- Social Media Section -->
                        <div>
                            <h3 class="text-white font-semibold mb-4">🌐 Social Media</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">TikTok URL</label>
                                    <input type="url" name="tiktok_url" value="<?php echo htmlspecialchars($settings['tiktok_url'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white" placeholder="https://www.tiktok.com/@yourshop">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Instagram URL</label>
                                    <input type="url" name="instagram_url" value="<?php echo htmlspecialchars($settings['instagram_url'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white" placeholder="https://www.instagram.com/yourshop">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">X (Twitter) URL</label>
                                    <input type="url" name="x_url" value="<?php echo htmlspecialchars($settings['x_url'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white" placeholder="https://x.com/yourshop">
                                </div>
                            </div>
                            <p class="text-gray-500 text-xs mt-2">Leave a field empty to hide that icon. Links appear in the footer of the booking page.</p>
                        </div>
                        <!-- Hours Section -->
                        <div>
                            <h3 class="text-white font-semibold mb-4">🕐 Business Hours</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Weekdays</label>
                                    <input type="text" name="hours_weekday" value="<?php echo htmlspecialchars($settings['hours_weekday'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Saturday</label>
                                    <input type="text" name="hours_saturday" value="<?php echo htmlspecialchars($settings['hours_saturday'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Sunday</label>
                                    <input type="text" name="hours_sunday" value="<?php echo htmlspecialchars($settings['hours_sunday'] ?? ''); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white">
                                </div>
                            </div>
                        </div>
                        <!-- Home Service Section -->
                        <div>
                            <h3 class="text-white font-semibold mb-4">🏠 Home Service</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Home Service Fee (₵)</label>
                                    <input type="number" step="0.01" name="home_service_fee" value="<?php echo htmlspecialchars($settings['home_service_fee'] ?? '20.00'); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white" placeholder="20.00">
                                </div>
                                <div class="flex items-end">
                                    <p class="text-gray-500 text-xs mb-2">Additional fee charged when clients select home service option</p>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="block text-gray-300 text-sm mb-2">Home Service Days</label>
                                <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-2">
                                    <?php
                                    $selected_days = getHomeServiceDays();
                                    $day_labels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                    foreach ($day_labels as $d => $label):
                                    ?>
                                        <label class="flex items-center space-x-2 bg-barber-700 border border-barber-600 rounded-lg px-3 py-2 cursor-pointer hover:border-barber-gold transition">
                                            <input type="checkbox" name="home_service_days[]" value="<?php echo $d; ?>" <?php echo in_array($d, $selected_days, true) ? 'checked' : ''; ?> class="text-barber-gold focus:ring-barber-gold">
                                            <span class="text-white text-sm"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="text-gray-500 text-xs mt-2">Clients can only book home service on the selected weekdays. Shop bookings are unaffected.</p>
                            </div>
                        </div>
                        <!-- About Section -->
                        <div>
                            <h3 class="text-white font-semibold mb-4">🏠 Footer About Text</h3>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">About Us</label>
                                <textarea name="footer_about" rows="3" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white"><?php echo htmlspecialchars($settings['footer_about'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <button type="submit" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">💾 Save Settings</button>
                    </form>
                    <!-- Logo Upload Section (separate form) -->
                    <div class="border-t border-barber-700 pt-6 mt-6">
                        <h3 class="text-white font-semibold mb-4">🏷️ Site Logo</h3>
                        <?php if (isset($settings['logo']) && $settings['logo']): ?>
                            <div class="mb-4">
                                <p class="text-gray-400 text-sm mb-2">Current Logo:</p>
                                <img src="<?php echo htmlspecialchars($settings['logo']); ?>" alt="Current Logo" class="h-16 object-contain">
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="action" value="upload_logo">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Upload New Logo</label>
                                <input type="file" name="logo_image" accept="image/*" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                            </div>
                            <button type="submit" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">📤 Upload Logo</button>
                        </form>
                    </div>
                    
                    <!-- Database Backup -->
                    <div class="border-t border-barber-700 pt-6 mt-6">
                        <h3 class="text-white font-semibold mb-4">💾 Database Backup</h3>
                        <p class="text-gray-400 text-sm mb-4">Download a complete SQL backup of your database including all bookings, clients, settings, and logs.</p>
                        <a href="backup_download.php" class="inline-block bg-green-700 hover:bg-green-600 text-white px-6 py-3 rounded-lg text-sm font-semibold transition">
                            📥 Download Database Backup
                        </a>
                    </div>
                    
                    <!-- Email Template Editor -->
                    <div class="border-t border-barber-700 pt-6 mt-6">
                        <h3 class="text-white font-semibold mb-4">📧 Email Templates</h3>
                        <p class="text-gray-400 text-sm mb-4">Customize the emails sent to clients. Use these placeholders: <code class="text-barber-gold">{client_name}, {booking_reference}, {service_name}, {barber_name}, {date}, {time}, {price}</code></p>
                        <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-6">
                            <input type="hidden" name="action" value="save_email_templates">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <!-- Confirmation Email -->
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Confirmation Email Subject</label>
                                <input type="text" name="email_subject_confirmation" value="<?php echo htmlspecialchars($settings['email_subject_confirmation'] ?? 'Booking Confirmation {booking_reference} - icut'); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Confirmation Email Body (HTML)</label>
                                <textarea name="email_body_confirmation" rows="6" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white text-sm font-mono"><?php echo htmlspecialchars($settings['email_body_confirmation'] ?? getDefaultConfirmationTemplate()); ?></textarea>
                            </div>
                            
                            <!-- Status Update Email -->
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Status Update Email Subject</label>
                                <input type="text" name="email_subject_status" value="<?php echo htmlspecialchars($settings['email_subject_status'] ?? 'Booking Update {booking_reference} - {status}'); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Status Update Email Body (HTML)</label>
                                <textarea name="email_body_status" rows="6" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white text-sm font-mono"><?php echo htmlspecialchars($settings['email_body_status'] ?? getDefaultStatusTemplate()); ?></textarea>
                            </div>
                            
                            <!-- Reminder Email -->
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Reminder Email Subject</label>
                                <input type="text" name="email_subject_reminder" value="<?php echo htmlspecialchars($settings['email_subject_reminder'] ?? 'Reminder: Your icut Appointment Tomorrow - {booking_reference}'); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-2 text-white text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Reminder Email Body (HTML)</label>
                                <textarea name="email_body_reminder" rows="6" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white text-sm font-mono"><?php echo htmlspecialchars($settings['email_body_reminder'] ?? getDefaultReminderTemplate()); ?></textarea>
                            </div>
                            
                            <button type="submit" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">💾 Save Email Templates</button>
                        </form>
                    </div>
                    
                    <!-- 2FA Section -->
                    <div class="border-t border-barber-700 pt-6 mt-6">
                        <h3 class="text-white font-semibold mb-4">🔐 Two-Factor Authentication</h3>
                        <?php if (is2FAEnabled($_SESSION['admin_id'])): ?>
                            <div class="bg-green-900/30 border border-green-700 rounded-lg p-4 mb-4">
                                <p class="text-green-300 text-sm mb-2">✅ 2FA is enabled for your account.</p>
                                <p class="text-gray-400 text-xs mb-3">
                                    Codes come from your authenticator app. Unused backup codes remaining:
                                    <strong class="text-barber-gold"><?php echo (int)count2FABackupCodes($_SESSION['admin_id']); ?></strong>
                                </p>
                                <?php if (!empty($_SESSION['2fa_backup_codes'])): ?>
                                    <div class="bg-yellow-900/20 border border-yellow-700 rounded-lg p-3 mb-3">
                                        <p class="text-yellow-300 text-xs font-semibold mb-2">⚠️ Save these backup codes now - they are shown only once.</p>
                                        <div class="grid grid-cols-4 gap-2 font-mono text-sm text-barber-gold">
                                            <?php foreach ($_SESSION['2fa_backup_codes'] as $backup_code): ?>
                                                <span><?php echo htmlspecialchars($backup_code); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php unset($_SESSION['2fa_backup_codes']); ?>
                                <?php endif; ?>
                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Generate new backup codes? Any existing unused codes will stop working.');">
                                        <input type="hidden" name="action" value="regenerate_2fa_backup_codes">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <button type="submit" class="bg-barber-700 hover:bg-barber-600 text-white px-4 py-2 rounded-lg text-sm transition">Regenerate Backup Codes</button>
                                    </form>
                                    <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" onsubmit="return confirm('Disable 2FA? This will reduce account security.');">
                                        <input type="hidden" name="action" value="disable_2fa">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <button type="submit" class="bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm transition">Disable 2FA</button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-400 text-sm mb-4">Add an extra layer of security to your admin account using Google Authenticator, Authy, Microsoft Authenticator or 1Password.</p>
                            <?php if (isset($_SESSION['2fa_secret'])): ?>
                                <?php $provisioning_uri = get2FAProvisioningUri($_SESSION['admin_id']); ?>
                                <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-4 mb-4">
                                    <p class="text-barber-gold font-semibold mb-3">Step 1 &mdash; Scan this QR code</p>
                                    <div class="bg-white inline-block p-3 rounded-lg mb-3">
                                        <div id="totpQr"></div>
                                    </div>
                                    <p class="text-gray-400 text-xs mb-2">Can't scan? Enter this key manually:</p>
                                    <div class="bg-barber-900 rounded p-3 font-mono text-xs text-barber-gold break-all mb-4">
                                        <?php echo htmlspecialchars($_SESSION['2fa_secret']); ?>
                                    </div>

                                    <p class="text-barber-gold font-semibold mb-2">Step 2 &mdash; Confirm the 6-digit code</p>
                                    <p class="text-gray-400 text-xs mb-3">We verify the code before enabling 2FA so you can't get locked out.</p>
                                    <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="flex flex-wrap items-center gap-2">
                                        <input type="hidden" name="action" value="confirm_2fa">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="text" name="twofa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required
                                               autocomplete="one-time-code" placeholder="123456"
                                               class="bg-barber-900 border border-barber-600 rounded-lg px-4 py-2 text-white font-mono w-32 text-center">
                                        <button type="submit" class="bg-green-700 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition">Verify &amp; Enable 2FA</button>
                                    </form>
                                    <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="mt-2">
                                        <input type="hidden" name="action" value="cancel_2fa">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <button type="submit" class="text-gray-400 hover:text-white text-sm underline">Cancel setup</button>
                                    </form>
                                </div>
                                <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
                                <script>
                                    (function () {
                                        var target = document.getElementById('totpQr');
                                        if (!target || typeof QRCode === 'undefined') { return; }
                                        new QRCode(target, {
                                            text: <?php echo json_encode($provisioning_uri, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                                            width: 180,
                                            height: 180,
                                            correctLevel: QRCode.CorrectLevel.M
                                        });
                                    })();
                                </script>
                            <?php else: ?>
                                <?php if (is2FASecretLegacy($_SESSION['admin_id'])): ?>
                                    <div class="bg-yellow-900/20 border border-yellow-700 rounded-lg p-3 mb-4">
                                        <p class="text-yellow-300 text-sm">Your stored 2FA key uses an outdated format and must be regenerated before it will work with an authenticator app.</p>
                                    </div>
                                <?php endif; ?>
                                <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-4">
                                    <input type="hidden" name="action" value="setup_2fa">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <button type="submit" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg transition">🔑 Setup 2FA</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Payment Gateway Settings -->
                    <div class="border-t border-barber-700 pt-6 mt-6">
                        <h3 class="text-white font-semibold mb-4">💳 Payment Gateway (Paystack)</h3>
                        <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-4 mb-4">
                            <div class="flex items-center justify-between mb-4">
                                <label class="flex items-center space-x-2">
                                    <input type="checkbox" name="paystack_enabled" value="1" <?php echo env('PAYSTACK_ENABLED', '0') === '1' ? 'checked' : ''; ?> class="text-barber-gold focus:ring-barber-gold">
                                    <span class="text-white font-semibold">Enable Online Payments</span>
                                </label>
                                <span class="text-xs text-gray-400">Controlled via .env</span>
                            </div>
                            
                            <div class="bg-yellow-900/20 border border-yellow-700 rounded-lg p-3 mb-4">
                                <p class="text-yellow-300 text-sm font-semibold mb-1">🔐 Secure Configuration</p>
                                <p class="text-gray-400 text-xs">Paystack keys are stored in <code class="text-barber-gold">.env</code> file for security. They are not stored in the database or shown in the admin panel to prevent exposure.</p>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Public Key</label>
                                    <input type="text" value="<?php echo htmlspecialchars(env('PAYSTACK_PUBLIC_KEY', 'pk_test_****')); ?>" readonly class="w-full bg-barber-900 border border-barber-600 rounded-lg px-4 py-2 text-gray-400 text-sm font-mono">
                                </div>
                                <div>
                                    <label class="block text-gray-300 text-sm mb-2">Secret Key</label>
                                    <input type="password" value="<?php echo htmlspecialchars(env('PAYSTACK_SECRET_KEY', 'sk_test_****')); ?>" readonly class="w-full bg-barber-900 border border-barber-600 rounded-lg px-4 py-2 text-gray-400 text-sm font-mono">
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="block text-gray-300 text-sm mb-2">Currency</label>
                                <input type="text" value="<?php echo htmlspecialchars(env('PAYSTACK_CURRENCY', 'GHS')); ?>" readonly class="w-full bg-barber-900 border border-barber-600 rounded-lg px-4 py-2 text-gray-400 text-sm">
                            </div>
                            <div class="mt-4 p-3 bg-barber-800 rounded-lg border border-barber-700">
                                <p class="text-gray-300 text-sm font-semibold mb-2">📁 How to Update Keys</p>
                                <p class="text-gray-400 text-xs mb-2">Edit the <code class="text-barber-gold">.env</code> file in the project root:</p>
                                <code class="text-xs text-green-400 block bg-barber-900 p-2 rounded">
PAYSTACK_PUBLIC_KEY=pk_test_****<br>
PAYSTACK_SECRET_KEY=sk_test_****<br>
PAYSTACK_CURRENCY=GHS<br>
PAYSTACK_ENABLED=1
                                </code>
                                <p class="text-yellow-400 text-xs mt-2">⚠️ Never commit .env to version control. Keep it secure and accessible only to authorized personnel.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Package Modal -->
    <div id="addPackageModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-2xl w-full mx-4 border border-barber-700 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Add New Package</h2>
                <button onclick="document.getElementById('addPackageModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-4">
                <input type="hidden" name="action" value="add_package">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Package Name *</label>
                    <input type="text" name="package_name" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white" placeholder="e.g., Fresh Cut + Facial">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Description</label>
                    <textarea name="package_description" rows="2" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white" placeholder="What's included in this package..."></textarea>
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Price (₵) *</label>
                    <input type="number" step="0.01" name="package_price" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white" placeholder="120.00">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Select Services *</label>
                    <div class="grid grid-cols-2 gap-2 max-h-40 overflow-y-auto bg-barber-700 p-3 rounded-lg">
                        <?php foreach ($all_services as $service): ?>
                            <label class="flex items-center space-x-2 text-gray-300 text-sm">
                                <input type="checkbox" name="package_services[]" value="<?php echo $service['id']; ?>" class="text-barber-gold">
                                <span><?php echo htmlspecialchars($service['name']); ?> (₵<?php echo number_format($service['price'], 2); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Add Package</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Package Modal -->
    <div id="editPackageModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-2xl w-full mx-4 border border-barber-700 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Edit Package</h2>
                <button onclick="document.getElementById('editPackageModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-4">
                <input type="hidden" name="action" value="edit_package">
                <input type="hidden" name="package_id" id="editPackageId">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Package Name *</label>
                    <input type="text" name="package_name" id="editPackageName" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Description</label>
                    <textarea name="package_description" id="editPackageDescription" rows="2" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white"></textarea>
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Price (₵) *</label>
                    <input type="number" step="0.01" name="package_price" id="editPackagePrice" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Select Services *</label>
                    <div class="grid grid-cols-2 gap-2 max-h-40 overflow-y-auto bg-barber-700 p-3 rounded-lg" id="editPackageServices">
                        <?php foreach ($all_services as $service): ?>
                            <label class="flex items-center space-x-2 text-gray-300 text-sm">
                                <input type="checkbox" name="package_services[]" value="<?php echo $service['id']; ?>" class="text-barber-gold">
                                <span><?php echo htmlspecialchars($service['name']); ?> (₵<?php echo number_format($service['price'], 2); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Update Package</button>
            </form>
        </div>
    </div>
    
    <!-- Add Barber Modal -->
    <div id="addBarberModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Add New Barber</h2>
                <button onclick="document.getElementById('addBarberModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="add_barber">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Name *</label>
                    <input type="text" name="barber_name" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Phone *</label>
                    <input type="text" name="barber_phone" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Specialization</label>
                    <input type="text" name="barber_specialization" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Photo</label>
                    <input type="file" name="barber_new_image" accept="image/*" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Add Barber</button>
            </form>
        </div>
    </div>
    <!-- Edit Barber Modal -->
    <div id="editBarberModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Edit Barber</h2>
                <button onclick="document.getElementById('editBarberModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-4">
                <input type="hidden" name="action" value="edit_barber">
                <input type="hidden" name="barber_id" id="edit_barber_id">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Name *</label>
                    <input type="text" name="barber_name" id="edit_barber_name" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Phone *</label>
                    <input type="text" name="barber_phone" id="edit_barber_phone" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Specialization</label>
                    <input type="text" name="barber_specialization" id="edit_barber_specialization" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Save Changes</button>
            </form>
        </div>
    </div>
    <!-- Add Service Modal -->
    <div id="addServiceModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Add New Service</h2>
                <button onclick="document.getElementById('addServiceModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="add_service">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Service Name *</label>
                    <input type="text" name="service_name" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Description</label>
                    <textarea name="service_description" rows="2" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Price (₵) *</label>
                        <input type="number" name="service_price" step="0.01" min="0" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Duration (min) *</label>
                        <input type="number" name="service_duration" min="5" step="5" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Image</label>
                    <input type="file" name="service_new_image" accept="image/*" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Add Service</button>
            </form>
        </div>
    </div>
    <!-- Edit Service Modal -->
    <div id="editServiceModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Edit Service</h2>
                <button onclick="document.getElementById('editServiceModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" class="space-y-4">
                <input type="hidden" name="action" value="edit_service">
                <input type="hidden" name="service_id" id="edit_service_id">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Service Name *</label>
                    <input type="text" name="service_name" id="edit_service_name" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Description</label>
                    <textarea name="service_description" id="edit_service_description" rows="2" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Price (₵) *</label>
                        <input type="number" name="service_price" id="edit_service_price" step="0.01" min="0" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Duration (min) *</label>
                        <input type="number" name="service_duration" id="edit_service_duration" min="5" step="5" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Save Changes</button>
            </form>
        </div>
    </div>
    <!-- Add Gallery Modal -->
    <div id="addGalleryModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Upload to Gallery</h2>
                <button onclick="document.getElementById('addGalleryModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="upload_gallery">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Title / Name *</label>
                    <input type="text" name="gallery_title" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white" placeholder="e.g., Classic Fade">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Image or Video *</label>
                    <input type="file" name="gallery_file" required accept="image/*,video/*" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    <p class="text-gray-500 text-xs mt-1">Supported: All image & video formats (max 50MB)</p>
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Upload</button>
            </form>
        </div>
    </div>
    <!-- Notes Section -->
    <div class="max-w-7xl mx-auto px-4 pb-8">
        <div class="bg-barber-800 rounded-xl p-6 border border-barber-700">
            <h3 class="text-lg font-semibold text-white mb-3">📋 How to Use the Dashboard</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                <div class="text-gray-400">
                    <span class="text-barber-gold font-semibold">1. Review Bookings</span>
                    <p class="mt-1">Check new pending bookings and filter by date or status</p>
                </div>
                <div class="text-gray-400">
                    <span class="text-green-400 font-semibold">2. WhatsApp Confirmation</span>
                    <p class="mt-1">Click the WhatsApp button to send a pre-filled confirmation message to the client. If the client didn't provide a phone number, use the WhatsApp Business button (uses the site phone number from Settings) to contact them manually.</p>
                    <p class="mt-1 text-xs">The site phone number (set in Site Settings) is used for the WhatsApp Business button so you can always reach clients or send reminders.</p>
                </div>
                <div class="text-gray-400">
                    <span class="text-blue-400 font-semibold">3. Update Status</span>
                    <p class="mt-1">Change booking status after confirming with the client. An email notification is automatically sent via PHP mail() when the status changes. WhatsApp can also be used as a backup communication channel.</p>
                </div>
                <div class="text-gray-400">
                    <span class="text-purple-400 font-semibold">4. Manage Reviews & Loyalty</span>
                    <p class="mt-1">Approve client reviews and manage loyalty points from the tabs above.</p>
                </div>
            </div>
        </div>
    </div>
    <!-- Password Change Modal -->
    <div id="passwordModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Change Password</h2>
                <button onclick="document.getElementById('passwordModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="change_password.php" data-api-endpoint="/api/change-password" class="space-y-4">
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Username</label>
                    <input type="text" name="new_username" value="<?php echo htmlspecialchars($current_username); ?>" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Email</label>
                    <input type="email" name="new_email" value="<?php echo htmlspecialchars($current_email); ?>" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Current Password</label>
                    <input type="password" name="current_password" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">New Password</label>
                    <input type="password" name="new_password" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Confirm New Password</label>
                    <input type="password" name="confirm_password" required class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                </div>
                <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">Update Account</button>
            </form>
        </div>
    </div>
    <script>
        // Theme Management
        const themes = ['dark', 'light', 'ocean', 'forest', 'royal', 'sunset'];
        const themeIcons = {
            'dark': 'fa-moon',
            'light': 'fa-sun',
            'ocean': 'fa-water',
            'forest': 'fa-tree',
            'royal': 'fa-crown',
            'sunset': 'fa-fire'
        };
        
        function setTheme(themeName) {
            const body = document.body;
            themes.forEach(t => body.classList.remove('light-mode', 'theme-' + t));
            
            if (themeName === 'light') {
                body.classList.add('light-mode');
            } else if (themeName !== 'dark') {
                body.classList.add('theme-' + themeName);
            }
            
            localStorage.setItem('theme', themeName);
            
            const icon = document.querySelector('#themeToggle i');
            if (icon) icon.className = 'fas ' + (themeIcons[themeName] || 'fa-moon');
            
            const indicator = document.querySelector('.theme-indicator');
            if (indicator) {
                const colors = { 'dark': '#b8934e', 'light': '#f5f5f5', 'ocean': '#0ea5e9', 'forest': '#22c55e', 'royal': '#a855f7', 'sunset': '#f97316' };
                indicator.style.backgroundColor = colors[themeName] || '#b8934e';
            }
            
            const picker = document.getElementById('themePicker');
            if (picker) picker.classList.add('hidden');
        }
        
        function toggleTheme() {
            const currentTheme = localStorage.getItem('theme') || 'dark';
            const currentIndex = themes.indexOf(currentTheme);
            const nextIndex = (currentIndex + 1) % themes.length;
            setTheme(themes[nextIndex]);
        }
        
        function toggleThemePicker() {
            const picker = document.getElementById('themePicker');
            if (picker) picker.classList.toggle('hidden');
        }
        
        document.addEventListener('click', function(e) {
            const container = document.getElementById('themePickerContainer');
            const picker = document.getElementById('themePicker');
            if (container && picker && !container.contains(e.target)) {
                picker.classList.add('hidden');
            }
        });
        
        const savedTheme = localStorage.getItem('theme') || 'dark';
        setTheme(savedTheme);
    // Select all functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.booking-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateSelectedCount();
        });
    }
    document.querySelectorAll('.booking-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
    function updateSelectedCount() {
        const selected = document.querySelectorAll('.booking-checkbox:checked').length;
        const countSpan = document.getElementById('selectedCount');
        if (countSpan) {
            if (selected > 0) {
                countSpan.textContent = selected + ' selected';
                countSpan.classList.remove('hidden');
            } else {
                countSpan.classList.add('hidden');
            }
        }
    }
    const bulkForm = document.getElementById('bulkForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            const action = this.querySelector('select[name="bulk_status"]').value;
            if (action === 'whatsapp') {
                e.preventDefault();
                const selected = document.querySelectorAll('.booking-checkbox:checked');
                selected.forEach(cb => {
                    const whatsappLink = cb.closest('tr').querySelector('a[href*="wa.me"]');
                    if (whatsappLink) {
                        window.open(whatsappLink.href, '_blank');
                    }
                });
            }
        });
    }
    // Mobile admin menu toggle
    const adminMenuToggle = document.getElementById('adminMenuToggle');
    const adminMobileMenu = document.getElementById('adminMobileMenu');
    if (adminMenuToggle && adminMobileMenu) {
        adminMenuToggle.addEventListener('click', () => {
            adminMobileMenu.classList.toggle('hidden');
        });
    }
    // Tab switching functionality
    function showTab(tabName) {
        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.add('hidden');
        });
        document.getElementById('panel-' + tabName).classList.remove('hidden');
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('bg-barber-gold', 'text-barber-900');
            btn.classList.add('bg-barber-700', 'text-white');
        });
        const activeTab = document.getElementById('tab-' + tabName);
        activeTab.classList.remove('bg-barber-700', 'text-white');
        activeTab.classList.add('bg-barber-gold', 'text-barber-900');
    }
    const hash = window.location.hash;
    if (hash === '#services') {
        showTab('services');
    } else if (hash === '#gallery') {
        showTab('gallery');
    } else if (hash === '#barbers') {
        showTab('barbers');
    } else if (hash === '#reviews') {
        showTab('reviews');
    } else if (hash === '#loyalty') {
        showTab('loyalty');
    } else if (hash === '#activity') {
        showTab('activity');
    } else if (hash === '#settings') {
        showTab('settings');
    }
    // Edit barber function
    function editBarber(id, name, phone, specialization) {
        document.getElementById('edit_barber_id').value = id;
        document.getElementById('edit_barber_name').value = name;
        document.getElementById('edit_barber_phone').value = phone;
        document.getElementById('edit_barber_specialization').value = specialization;
        document.getElementById('editBarberModal').classList.remove('hidden');
    }
    // Edit service function
    function editService(id, name, description, price, duration) {
        document.getElementById('edit_service_id').value = id;
        document.getElementById('edit_service_name').value = name;
        document.getElementById('edit_service_description').value = description;
        document.getElementById('edit_service_price').value = price;
        document.getElementById('edit_service_duration').value = duration;
        document.getElementById('editServiceModal').classList.remove('hidden');
    }
    // Edit gallery function
    function editGallery(id, title) {
        const newTitle = prompt('Edit gallery title:', title);
        if (newTitle && newTitle.trim() !== '') {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'upload_handler.php';
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'edit_gallery';
            form.appendChild(actionInput);
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'gallery_id';
            idInput.value = id;
            form.appendChild(idInput);
            const titleInput = document.createElement('input');
            titleInput.type = 'hidden';
            titleInput.name = 'gallery_title';
            titleInput.value = newTitle.trim();
            form.appendChild(titleInput);
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo generateCSRFToken(); ?>';
            form.appendChild(csrfInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Refund modal functions
    function openRefundModal(bookingId, amount) {
        document.getElementById('refundBookingId').value = bookingId;
        document.getElementById('refundAmount').value = amount;
        document.getElementById('refundModal').classList.remove('hidden');
    }
    
    function closeRefundModal() {
        document.getElementById('refundModal').classList.add('hidden');
    }
    
    function submitRefund() {
        const bookingId = document.getElementById('refundBookingId').value;
        const amount = document.getElementById('refundAmount').value;
        const reason = document.getElementById('refundReason').value;
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        
        if (!confirm('Are you sure you want to refund ₵' + amount + '? This action cannot be undone.')) {
            return;
        }
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'upload_handler.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'process_refund';
        form.appendChild(actionInput);
        
        const bookingInput = document.createElement('input');
        bookingInput.type = 'hidden';
        bookingInput.name = 'booking_id';
        bookingInput.value = bookingId;
        form.appendChild(bookingInput);
        
        const amountInput = document.createElement('input');
        amountInput.type = 'hidden';
        amountInput.name = 'refund_amount';
        amountInput.value = amount;
        form.appendChild(amountInput);
        
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'refund_reason';
        reasonInput.value = reason;
        form.appendChild(reasonInput);
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = csrfToken;
        form.appendChild(csrInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    
    // Toggle barber home service availability
    function toggleBarberHomeService(barberId, currentStatus) {
        const newStatus = currentStatus ? 0 : 1;
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'upload_handler.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'toggle_barber_home_service';
        form.appendChild(actionInput);
        
        const barberIdInput = document.createElement('input');
        barberIdInput.type = 'hidden';
        barberIdInput.name = 'barber_id';
        barberIdInput.value = barberId;
        form.appendChild(barberIdInput);
        
        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'offers_home_service';
        statusInput.value = newStatus;
        form.appendChild(statusInput);
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = csrfToken;
        form.appendChild(csrfInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    </script>
    
    <!-- Refund Modal -->
    <div id="refundModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-barber-800 rounded-2xl p-8 max-w-md w-full mx-4 border border-barber-700">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-white">Process Refund</h2>
                <button onclick="closeRefundModal()" class="text-gray-400 hover:text-white text-2xl">&times;</button>
            </div>
            <form method="POST" action="upload_handler.php" data-api-endpoint="/api/admin-handler" id="refundForm" class="space-y-4">
                <input type="hidden" name="action" value="process_refund">
                <input type="hidden" name="booking_id" id="refundBookingId">
                <input type="hidden" name="refund_amount" id="refundAmount">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-4">
                    <p class="text-gray-300 text-sm mb-2">Refund Amount:</p>
                    <p class="text-barber-gold font-bold text-2xl" id="refundAmountDisplay">₵0.00</p>
                </div>
                
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Reason for Refund (Optional)</label>
                    <textarea name="refund_reason" id="refundReason" rows="3" class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white" placeholder="e.g., Client cancelled, service unavailable..."></textarea>
                </div>
                
                <div class="bg-yellow-900/20 border border-yellow-700 rounded-lg p-3">
                    <p class="text-yellow-300 text-xs">⚠️ This will initiate a refund through Paystack. The funds will be returned to the client's original payment method within 3-10 business days.</p>
                </div>
                
                <div class="flex space-x-3">
                    <button type="button" onclick="closeRefundModal()" class="flex-1 bg-barber-700 hover:bg-barber-600 text-white py-3 rounded-lg transition">Cancel</button>
                    <button type="submit" class="flex-1 bg-orange-700 hover:bg-orange-600 text-white font-bold py-3 rounded-lg transition">Process Refund</button>
                </div>
            </form>
        </div>
    </div>
    <style>
        .light-mode body { background: #f5f5f5; color: #1a1a1a; }
        .light-mode .bg-barber-900 { background: #ffffff; }
        .light-mode .bg-barber-800 { background: #f0f0f0; }
        .light-mode .bg-barber-700 { background: #e8e8e8; }
        .light-mode .bg-barber-600 { background: #d0d0d0; }
        .light-mode .text-white { color: #1a1a1a !important; }
        .light-mode .text-gray-300 { color: #4a4a4a !important; }
        .light-mode .text-gray-400 { color: #6a6a6a !important; }
        .light-mode .text-gray-500 { color: #8a8a8a !important; }
        .light-mode .border-barber-700 { border-color: #e0e0e0; }
        .light-mode .bg-barber-gold { background: #b8934e; }
        .light-mode .divide-barber-700 > div, .light-mode .divide-barber-700 > tr { border-color: #e0e0e0; }
        
        /* Theme Color Variants */
        .theme-ocean { --theme-accent: #0ea5e9; --theme-accent-light: #0284c7; }
        .theme-forest { --theme-accent: #22c55e; --theme-accent-light: #16a34a; }
        .theme-royal { --theme-accent: #a855f7; --theme-accent-light: #9333ea; }
        .theme-sunset { --theme-accent: #f97316; --theme-accent-light: #ea580c; }
        
        .theme-ocean .bg-barber-gold,
        .theme-forest .bg-barber-gold,
        .theme-royal .bg-barber-gold,
        .theme-sunset .bg-barber-gold { background: var(--theme-accent) !important; }
        .theme-ocean .hover\:bg-barber-gold-light:hover,
        .theme-forest .hover\:bg-barber-gold-light:hover,
        .theme-royal .hover\:bg-barber-gold-light:hover,
        .theme-sunset .hover\:bg-barber-gold-light:hover { background: var(--theme-accent-light) !important; }
        .theme-ocean .text-barber-gold,
        .theme-forest .text-barber-gold,
        .theme-royal .text-barber-gold,
        .theme-sunset .text-barber-gold { color: var(--theme-accent) !important; }
        
        @media (max-width: 768px) {
            .table-compact th, .table-compact td {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
        }
    </style>

    <script>
    // AJAX form routing for Vercel compatibility
    (function() {
        const API_BASE = '/api';

        // Map form actions to API endpoints
        const actionMap = {
            'upload_handler.php': API_BASE + '/admin-handler',
            'change_password.php': API_BASE + '/change-password',
            'admin_logout.php': API_BASE + '/admin-logout'
        };

        // Add action attribute to forms based on their current action
        document.querySelectorAll('form').forEach(form => {
            const action = form.getAttribute('action');
            if (action && actionMap[action]) {
                form.dataset.apiEndpoint = actionMap[action];
            }
        });

        // Intercept form submissions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', async function(e) {
                const endpoint = this.dataset.apiEndpoint;
                if (!endpoint) return; // Let non-AJAX forms submit normally

                e.preventDefault();

                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn ? submitBtn.innerHTML : 'Submit';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                }

                const formData = new FormData(this);
                const action = formData.get('action') || 'unknown';

                try {
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json'
                        }
                    });

                    const result = await response.json();

                    // Show message
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'fixed top-4 right-4 p-4 rounded-lg text-sm z-50 shadow-lg';

                    if (result.success) {
                        messageDiv.className += ' bg-green-900/90 border border-green-700 text-green-300';
                        messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + result.message;

                        // Reset form if successful
                        if (action !== 'update_settings') {
                            this.reset();
                        }

                        // Reload page for certain actions
                        if (['delete_barber', 'delete_service', 'delete_package', 'delete_review', 'delete_gallery'].includes(action)) {
                            setTimeout(() => location.reload(), 1000);
                        }
                    } else {
                        messageDiv.className += ' bg-red-900/90 border border-red-700 text-red-300';
                        messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + (result.error || 'Something went wrong');
                    }

                    document.body.appendChild(messageDiv);
                    setTimeout(() => messageDiv.remove(), 5000);

                } catch (error) {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'fixed top-4 right-4 p-4 rounded-lg text-sm z-50 shadow-lg bg-red-900/90 border border-red-700 text-red-300';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Network error. Please try again.';
                    document.body.appendChild(messageDiv);
                    setTimeout(() => messageDiv.remove(), 5000);
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                }
            });
        });
    })();
    </script>
</body>
</html>
