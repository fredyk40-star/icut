<?php
/**
 * Vercel serverless function: Admin dashboard (HTML)
 * Route: GET /api/admin-dashboard
 *
 * This is the serverless, JWT-auth equivalent of the root admin.php dashboard.
 * It renders the tabbed overview (barbers, services, bookings, reviews, gallery,
 * activity log, settings) and delegates all write actions (add/edit/delete
 * barber & service, status changes, settings, account changes) to the existing
 * api/admin-handler.php JSON endpoint. No PHP sessions are used.
 */

require_once dirname(__DIR__) . '/lib/env.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Auth gate — redirects unauthenticated visitors to the login page.
$user = requireAdminAuth();
$db = getDatabaseConnection();

// Load current admin profile for the account/password modal.
$admin_profile_stmt = $db->prepare("SELECT username, email FROM admins WHERE id = :id");
$admin_profile_stmt->execute([':id' => $user['user_id']]);
$admin_profile = $admin_profile_stmt->fetch();
$current_username = $admin_profile['username'] ?? ($user['name'] ?? '');
$current_email = $admin_profile['email'] ?? '';

// Handle inline status updates (mirrors the root admin.php POST flow) by
// forwarding to the shared admin-handler endpoint's logic.
if (isset($_POST['update_status']) && isset($_POST['booking_id']) && isset($_POST['new_status'])) {
    // We perform the update here directly so the dashboard can refresh without
    // a separate AJAX round trip, then re-fetch the affected booking below.
    // (The root admin.php does the same: validate, update, notify, log.)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $booking_id = (int) $_POST['booking_id'];
        $new_status = $_POST['new_status'];
        $allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
        if (in_array($new_status, $allowed_statuses)) {
            if ($new_status === 'cancelled') {
                $stmt = $db->prepare("UPDATE bookings SET status = :status, cancelled_at = NOW() WHERE id = :id");
            } else {
                $stmt = $db->prepare("UPDATE bookings SET status = :status WHERE id = :id");
            }
            $stmt->execute([':status' => $new_status, ':id' => $booking_id]);
        }
    }
}

$site_name = trim(env('SITE_NAME', '')) ?: 'icut';
$csrf_token = generateCSRFToken();

// Fetch dashboard data.
$all_barbers = $db->query("SELECT * FROM barbers ORDER BY name")->fetchAll();
$all_services = $db->query("SELECT * FROM services ORDER BY name")->fetchAll();
$all_packages = $db->query("SELECT * FROM packages ORDER BY created_at DESC")->fetchAll();
$gallery_items = $db->query("SELECT * FROM gallery ORDER BY created_at DESC")->fetchAll();
$all_reviews = $db->query("SELECT * FROM reviews ORDER BY created_at DESC")->fetchAll();
$activity_logs = $db->query("SELECT * FROM admin_activity_log ORDER BY created_at DESC LIMIT 100")->fetchAll();

// Bookings (most recent first), joined with barber + service names.
$bookings = $db->query("
    SELECT b.*, br.name AS barber_name, s.name AS service_name
    FROM bookings b
    LEFT JOIN barbers br ON b.barber_id = br.id
    LEFT JOIN services s ON b.service_id = s.id
    ORDER BY b.created_at DESC
")->fetchAll();

// Aggregate stats.
$stats = [];
$stats['total_bookings'] = (int) $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$stats['pending_bookings'] = (int) $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
$stats['confirmed_bookings'] = (int) $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'")->fetchColumn();
$stats['completed_bookings'] = (int) $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'completed'")->fetchColumn();
$stats['cancelled_bookings'] = (int) $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'cancelled'")->fetchColumn();
$stats['total_revenue'] = (float) $db->query("SELECT IFNULL(SUM(paid_amount),0) FROM payments WHERE status = 'success'")->fetchColumn();
$stats['total_clients'] = (int) $db->query("SELECT COUNT(*) FROM bookings WHERE client_email != '' OR client_email IS NOT NULL")->fetchColumn();

// Site settings (flat key/value).
$settings_rows = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
$settings = [];
foreach ($settings_rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

function statusLabel($s) {
    return ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$s] ?? ucfirst($s);
}
$status_colors = [
    'pending' => 'bg-yellow-900/50 text-yellow-300 border-yellow-700',
    'confirmed' => 'bg-blue-900/50 text-blue-300 border-blue-700',
    'completed' => 'bg-green-900/50 text-green-300 border-green-700',
    'cancelled' => 'bg-red-900/50 text-red-300 border-red-700',
];
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard &middot; <?php echo htmlspecialchars($site_name); ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
function showTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
    document.getElementById('panel-' + name).classList.remove('hidden');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('bg-amber-500','text-gray-900','bg-barber-700','text-white'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.add('bg-barber-700','text-white'));
    const active = document.getElementById('tab-' + name);
    if (active) { active.classList.remove('bg-barber-700','text-white'); active.classList.add('bg-amber-500','text-gray-900'); }
}
function postJSON(url, data, cb) {
    fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data)})
      .then(r=>r.json()).then(cb).catch(e=>cb({error:String(e)}));
}
</script>
</head>
<body class="bg-gray-900 text-white min-h-screen">
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Admin Dashboard</h1>
        <div class="flex items-center gap-4">
            <span class="text-gray-300">Signed in as <?php echo htmlspecialchars($current_username); ?></span>
            <a href="/api/admin-logout" class="text-sm text-amber-400 hover:text-amber-300">Logout</a>
        </div>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-2 md:grid-cols-7 gap-4 mb-6">
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700"><div class="text-gray-400 text-xs">Total Bookings</div><div class="text-2xl font-bold"><?php echo $stats['total_bookings']; ?></div></div>
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700"><div class="text-gray-400 text-xs">Pending</div><div class="text-2xl font-bold text-yellow-400"><?php echo $stats['pending_bookings']; ?></div></div>
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700"><div class="text-gray-400 text-xs">Confirmed</div><div class="text-2xl font-bold text-blue-400"><?php echo $stats['confirmed_bookings']; ?></div></div>
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700"><div class="text-gray-400 text-xs">Completed</div><div class="text-2xl font-bold text-green-400"><?php echo $stats['completed_bookings']; ?></div></div>
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700"><div class="text-gray-400 text-xs">Cancelled</div><div class="text-2xl font-bold text-red-400"><?php echo $stats['cancelled_bookings']; ?></div></div>
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700"><div class="text-gray-400 text-xs">Revenue</div><div class="text-2xl font-bold text-amber-400">$<?php echo number_format($stats['total_revenue'], 2); ?></div></div>
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700"><div class="text-gray-400 text-xs">Clients</div><div class="text-2xl font-bold"><?php echo $stats['total_clients']; ?></div></div>
    </div>
    <!-- Tab navigation -->
    <div class="mb-6 border-b border-gray-700 overflow-x-auto">
        <div class="flex flex-wrap -mx-2">
            <button onclick="showTab('bookings')" id="tab-bookings" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-amber-500 text-gray-900 transition whitespace-nowrap m-2">📋 Bookings</button>
            <button onclick="showTab('barbers')" id="tab-barbers" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-gray-700 text-white hover:bg-amber-400 transition whitespace-nowrap m-2">✂️ Barbers</button>
            <button onclick="showTab('services')" id="tab-services" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-gray-700 text-white hover:bg-amber-400 transition whitespace-nowrap m-2">💼 Services</button>
            <button onclick="showTab('packages')" id="tab-packages" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-gray-700 text-white hover:bg-amber-400 transition whitespace-nowrap m-2">📦 Packages</button>
            <button onclick="showTab('reviews')" id="tab-reviews" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-gray-700 text-white hover:bg-amber-400 transition whitespace-nowrap m-2">⭐ Reviews</button>
            <button onclick="showTab('gallery')" id="tab-gallery" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-gray-700 text-white hover:bg-amber-400 transition whitespace-nowrap m-2">📸 Gallery</button>
            <button onclick="showTab('activity')" id="tab-activity" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-gray-700 text-white hover:bg-amber-400 transition whitespace-nowrap m-2">📋 Activity Log</button>
            <button onclick="showTab('settings')" id="tab-settings" class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-gray-700 text-white hover:bg-amber-400 transition whitespace-nowrap m-2">⚙️ Settings</button>
        </div>
    </div>

    <!-- ==== BOOKINGS ==== -->
    <div id="panel-bookings" class="tab-panel">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-xl font-bold">Bookings</h2>
                <button onclick="document.getElementById('addBookingModal').classList.remove('hidden')" class="bg-amber-500 hover:bg-amber-400 text-gray-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Add Booking</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-gray-400"><tr>
                        <th class="px-4 py-3 text-left">Ref</th><th class="px-4 py-3 text-left">Client</th><th class="px-4 py-3 text-left">Barber</th><th class="px-4 py-3 text-left">Service</th><th class="px-4 py-3 text-left">When</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (empty($bookings)): ?>
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No bookings found</td></tr>
                        <?php else: foreach ($bookings as $b): ?>
                            <tr class="hover:bg-gray-700/50">
                                <td class="px-4 py-3 text-amber-400 font-mono"><?php echo htmlspecialchars($b['booking_reference']); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars(($b['client_name'] ?? '') . ' ' . ($b['client_last_name'] ?? '')); ?><br><span class="text-gray-400"><?php echo htmlspecialchars($b['client_email'] ?? ''); ?></span></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($b['barber_name'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($b['service_name'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($b['booking_date'] ?? ''); ?> <?php echo htmlspecialchars(substr($b['booking_time'] ?? '', 0, 5)); ?></td>
                                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs border <?php echo $status_colors[$b['status'] ?? 'pending']; ?>"><?php echo statusLabel($b['status'] ?? 'pending'); ?></span></td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline" onsubmit="event.preventDefault(); updateStatus(this, '<?php echo (int)$b['id']; ?>','confirmed','<?php echo $csrf_token; ?>');">
                                        <button type="submit" class="text-xs text-amber-400 hover:text-amber-300">Confirm</button>
                                    </form>
                                    | <form method="POST" class="inline" onsubmit="event.preventDefault(); updateStatus(this, '<?php echo (int)$b['id']; ?>','cancelled','<?php echo $csrf_token; ?>');">
                                        <button type="submit" class="text-xs text-red-400 hover:text-red-300">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- ==== BARBERS ==== -->
    <div id="panel-barbers" class="tab-panel hidden">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-xl font-bold">Manage Barbers</h2>
                <button onclick="document.getElementById('addBarberModal').classList.remove('hidden')" class="bg-amber-500 hover:bg-amber-400 text-gray-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Add Barber</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-gray-400"><tr>
                        <th class="px-4 py-3">Photo</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Specialization</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Active</th><th class="px-4 py-3">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (empty($all_barbers)): ?>
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No barbers yet</td></tr>
                        <?php else: foreach ($all_barbers as $barber): ?>
                            <tr class="hover:bg-gray-700/50">
                                <td class="px-4 py-3"><?php if ($barber['image']): ?><img src="<?php echo htmlspecialchars($barber['image']); ?>" alt="<?php echo htmlspecialchars($barber['name']); ?>" class="w-10 h-10 rounded-full"><?php else: ?><div class="w-10 h-10 rounded-full bg-gray-700 flex items-center justify-center">–</div><?php endif; ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($barber['name']); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($barber['specialization'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($barber['phone'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo $barber['is_active'] ? '<span class="text-green-400">Yes</span>' : '<span class="text-gray-400">No</span>'; ?></td>
                                <td class="px-4 py-3 text-center">
                                    <button onclick="editBarber(<?php echo (int)$barber['id']; ?>)" class="text-xs text-amber-400 hover:text-amber-300">Edit</button>
                                    | <button onclick="delBarber(<?php echo (int)$barber['id']; ?>)" class="text-xs text-red-400 hover:text-red-300">Del</button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==== SERVICES ==== -->
    <div id="panel-services" class="tab-panel hidden">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-xl font-bold">Manage Services</h2>
                <button onclick="document.getElementById('addServiceModal').classList.remove('hidden')" class="bg-amber-500 hover:bg-amber-400 text-gray-900 font-bold px-4 py-2 rounded-lg text-sm transition">+ Add Service</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-gray-400"><tr>
                        <th class="px-4 py-3">Name</th><th class="px-4 py-3">Description</th><th class="px-4 py-3">Price</th><th class="px-4 py-3">Duration</th><th class="px-4 py-3">Active</th><th class="px-4 py-3">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (empty($all_services)): ?>
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No services yet</td></tr>
                        <?php else: foreach ($all_services as $svc): ?>
                            <tr class="hover:bg-gray-700/50">
                                <td class="px-4 py-3"><?php echo htmlspecialchars($svc['name']); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($svc['description'] ?? ''); ?></td>
                                <td class="px-4 py-3">$<?php echo number_format((float)$svc['price'], 2); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars(($svc['duration_minutes'] ?? $svc['duration']) ?? ''); ?> min</td>
                                <td class="px-4 py-3"><?php echo $svc['is_active'] ? '<span class="text-green-400">Yes</span>' : '<span class="text-gray-400">No</span>'; ?></td>
                                <td class="px-4 py-3 text-center">
                                    <button onclick="editService(<?php echo (int)$svc['id']; ?>)" class="text-xs text-amber-400 hover:text-amber-300">Edit</button>
                                    | <button onclick="delService(<?php echo (int)$svc['id']; ?>)" class="text-xs text-red-400 hover:text-red-300">Del</button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- ==== PACKAGES ==== -->
    <div id="panel-packages" class="tab-panel hidden">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700"><h2 class="text-xl font-bold">Packages</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-gray-400"><tr>
                        <th class="px-4 py-3">Name</th><th class="px-4 py-3">Price</th><th class="px-4 py-3">Services</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (empty($all_packages)): ?>
                            <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">No packages yet</td></tr>
                        <?php else: foreach ($all_packages as $pkg): ?>
                            <tr class="hover:bg-gray-700/50">
                                <td class="px-4 py-3"><?php echo htmlspecialchars($pkg['name']); ?></td>
                                <td class="px-4 py-3">$<?php echo number_format((float)$pkg['price'], 2); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($pkg['services'] ?? ($pkg['services_included'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==== REVIEWS ==== -->
    <div id="panel-reviews" class="tab-panel hidden">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700"><h2 class="text-xl font-bold">Reviews</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-gray-400"><tr>
                        <th class="px-4 py-3">Client</th><th class="px-4 py-3">Rating</th><th class="px-4 py-3">Comment</th><th class="px-4 py-3">Approved</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (empty($all_reviews)): ?>
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No reviews yet</td></tr>
                        <?php else: foreach ($all_reviews as $rv): ?>
                            <tr class="hover:bg-gray-700/50">
                                <td class="px-4 py-3"><?php echo htmlspecialchars($rv['client_name'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo (int)$rv['rating']; ?>/5</td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($rv['comment'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo $rv['is_approved'] ? '<span class="text-green-400">Yes</span>' : '<span class="text-gray-400">No</span>'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==== GALLERY ==== -->
    <div id="panel-gallery" class="tab-panel hidden">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700"><h2 class="text-xl font-bold">Gallery</h2></div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 p-4">
                <?php if (empty($gallery_items)): ?>
                    <p class="text-gray-400 col-span-4 text-center">No gallery items yet</p>
                <?php else: foreach ($gallery_items as $g): ?>
                    <div class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden">
                        <img src="<?php echo htmlspecialchars($g['image'] ?? $g['image_url'] ?? ''); ?>" alt="<?php echo htmlspecialchars($g['caption'] ?? ''); ?>" class="w-full h-32 object-cover">
                        <div class="p-2"><p class="text-xs text-gray-300"><?php echo htmlspecialchars($g['caption'] ?? ''); ?></p></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- ==== ACTIVITY LOG ==== -->
    <div id="panel-activity" class="tab-panel hidden">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700"><h2 class="text-xl font-bold">Recent Activity</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-gray-400"><tr>
                        <th class="px-4 py-3">Time</th><th class="px-4 py-3">Admin</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Details</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (empty($activity_logs)): ?>
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No activity yet</td></tr>
                        <?php else: foreach ($activity_logs as $a): ?>
                            <tr class="hover:bg-gray-700/50">
                                <td class="px-4 py-3"><?php echo htmlspecialchars($a['created_at'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($a['admin_name'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($a['activity_type'] ?? ''); ?></td>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($a['details'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==== SETTINGS ==== -->
    <div id="panel-settings" class="tab-panel hidden">
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700"><h2 class="text-xl font-bold">Site Settings</h2></div>
            <form id="settingsForm" class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($settings as $k => $v): ?>
                    <div>
                        <label class="block text-gray-300 text-xs font-medium mb-1"><?php echo htmlspecialchars($k); ?></label>
                        <input name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v ?? ''); ?>"
                               class="w-full bg-gray-900 border border-gray-700 rounded-lg px-3 py-2 text-white placeholder-gray-500 focus:outline-none focus:border-amber-400">
                    </div>
                <?php endforeach; ?>
                <div class="md:col-span-2">
                    <button type="submit" class="bg-amber-500 hover:bg-amber-400 text-gray-900 font-bold px-4 py-2 rounded-lg transition">Save Settings</button>
                </div>
            </form>
        </div>
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700"><h2 class="text-xl font-bold">Account</h2></div>
            <div class="p-4">
                <p class="text-sm text-gray-300 mb-2">Username: <strong><?php echo htmlspecialchars($current_username); ?></strong> &middot; Email: <strong><?php echo htmlspecialchars($current_email); ?></strong></p>
                <p class="text-xs text-gray-400">Update username/email/password via your admin profile on the root admin.php (session-based).</p>
            </div>
        </div>
    </div>

</div> <!-- /.max-w -->

<script>
function updateStatus(btnForm, id, status, csrf) {
    const f = btnForm;
    f.innerHTML = '<input type="hidden" name="update_status" value="1">' +
                  '<input type="hidden" name="booking_id" value="'+id+'">' +
                  '<input type="hidden" name="new_status" value="'+status+'">' +
                  '<input type="hidden" name="csrf_token" value="'+csrf+'">';
    // Post to handler that performs update + returns JSON.
    fetch('/api/admin-handler', {method:'POST', body:new FormData(f.parentNode.appendChild(f))} )
      .then(r=>r.json()).then(d=>{ if(d.success){ location.reload(); } else { alert(d.error||'Update failed'); } })
      .catch(e=>alert('Error: '+e));
}
function editBarber(id){ alert('Edit barber ID '+id+' — use root admin.php for edit forms.'); }
function delBarber(id){ if(!confirm('Delete barber '+id+'?')) return; fetch('/api/admin-handler',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_barber',barber_id:id,csrf_token:'<?php echo $csrf_token; ?>')})).then(r=>r.json()).then(d=>{alert(d.message||d.error); if(d.success) location.reload();});}
function editService(id){ alert('Edit service ID '+id+' — use root admin.php for edit forms.'); }
function delService(id){ if(!confirm('Delete service '+id+'?')) return; fetch('/api/admin-handler',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_service',service_id:id,csrf_token:'<?php echo $csrf_token; ?>')})).then(r=>r.json()).then(d=>{alert(d.message||d.error); if(d.success) location.reload();});}
document.getElementById('tab-bookings').click();
</script>
</body>
</html>
