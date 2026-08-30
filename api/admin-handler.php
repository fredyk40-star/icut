<?php
/**
 * Vercel serverless function: Admin upload handler
 * Handles all admin CRUD operations from admin.php
 * Route: POST /api/admin-handler
 */

require_once dirname(__DIR__) . '/user/lib/env.php';
require_once dirname(__DIR__) . '/user/lib/db.php';
require_once dirname(__DIR__) . '/user/lib/csrf.php';
require_once dirname(__DIR__) . '/user/middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

$user = requireAdminAuth();
$db = getDatabaseConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$action = sanitizeInput($_POST['action'] ?? '');

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Action is required']);
    exit;
}

try {
    switch ($action) {
        // Barber management
        case 'add_barber':
            $name = sanitizeInput($_POST['name'] ?? '');
            $bio = sanitizeInput($_POST['bio'] ?? '');
            $image = $_FILES['image'] ?? null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_order = (int)($_POST['display_order'] ?? 0);

            if (empty($name)) {
                throw new Exception('Barber name is required');
            }

            $image_url = null;
            if ($image && $image['tmp_name']) {
                $image_url = uploadFile($image, 'barbers');
            }

            $stmt = $db->prepare("
                INSERT INTO barbers (name, bio, image, is_active, display_order)
                VALUES (:name, :bio, :image, :active, :order)
            ");
            $stmt->execute([
                ':name' => $name,
                ':bio' => $bio,
                ':image' => $image_url,
                ':active' => $is_active,
                ':order' => $display_order
            ]);

            logAdminActivity('barber_add', $user['name'], "Added barber: $name");
            echo json_encode(['success' => true, 'message' => 'Barber added successfully']);
            break;

        case 'delete_barber':
            $barber_id = (int)($_POST['barber_id'] ?? 0);
            $db->prepare("DELETE FROM barbers WHERE id = :id")->execute([':id' => $barber_id]);
            logAdminActivity('barber_delete', $user['name'], "Deleted barber ID: $barber_id");
            echo json_encode(['success' => true, 'message' => 'Barber deleted']);
            break;

        // Service management
        case 'add_service':
            $name = sanitizeInput($_POST['name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $duration = (int)($_POST['duration'] ?? 30);
            $image = $_FILES['image'] ?? null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_order = (int)($_POST['display_order'] ?? 0);

            if (empty($name) || $price <= 0) {
                throw new Exception('Service name and valid price are required');
            }

            $image_url = null;
            if ($image && $image['tmp_name']) {
                $image_url = uploadFile($image, 'services');
            }

            $stmt = $db->prepare("
                INSERT INTO services (name, description, price, duration, image, is_active, display_order)
                VALUES (:name, :desc, :price, :duration, :image, :active, :order)
            ");
            $stmt->execute([
                ':name' => $name,
                ':desc' => $description,
                ':price' => $price,
                ':duration' => $duration,
                ':image' => $image_url,
                ':active' => $is_active,
                ':order' => $display_order
            ]);

            logAdminActivity('service_add', $user['name'], "Added service: $name");
            echo json_encode(['success' => true, 'message' => 'Service added successfully']);
            break;

        case 'delete_service':
            $service_id = (int)($_POST['service_id'] ?? 0);
            $db->prepare("DELETE FROM services WHERE id = :id")->execute([':id' => $service_id]);
            logAdminActivity('service_delete', $user['name'], "Deleted service ID: $service_id");
            echo json_encode(['success' => true, 'message' => 'Service deleted']);
            break;

        // Package management
        case 'add_package':
            $name = sanitizeInput($_POST['name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $service_ids = sanitizeInput($_POST['service_ids'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_order = (int)($_POST['display_order'] ?? 0);

            if (empty($name) || $price <= 0) {
                throw new Exception('Package name and valid price are required');
            }

            $stmt = $db->prepare("
                INSERT INTO packages (name, description, price, service_ids, is_active, display_order)
                VALUES (:name, :desc, :price, :services, :active, :order)
            ");
            $stmt->execute([
                ':name' => $name,
                ':desc' => $description,
                ':price' => $price,
                ':services' => $service_ids,
                ':active' => $is_active,
                ':order' => $display_order
            ]);

            logAdminActivity('package_add', $user['name'], "Added package: $name");
            echo json_encode(['success' => true, 'message' => 'Package added successfully']);
            break;

        case 'delete_package':
            $package_id = (int)($_POST['package_id'] ?? 0);
            $db->prepare("DELETE FROM packages WHERE id = :id")->execute([':id' => $package_id]);
            logAdminActivity('package_delete', $user['name'], "Deleted package ID: $package_id");
            echo json_encode(['success' => true, 'message' => 'Package deleted']);
            break;

        // Booking management
        case 'update_booking_status':
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $status = sanitizeInput($_POST['status'] ?? '');

            if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'])) {
                throw new Exception('Invalid status');
            }

            $db->prepare("UPDATE bookings SET status = :status WHERE id = :id")
               ->execute([':status' => $status, ':id' => $booking_id]);

            logAdminActivity('booking_update', $user['name'], "Updated booking #$booking_id to $status");
            echo json_encode(['success' => true, 'message' => 'Booking status updated']);
            break;

        case 'release_slot':
            $booking_id = (int)($_POST['booking_id'] ?? 0);

            $db->beginTransaction();
            $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id")
               ->execute([':id' => $booking_id]);

            $stmt = $db->prepare("SELECT booking_date, booking_time, barber_id FROM bookings WHERE id = :id");
            $stmt->execute([':id' => $booking_id]);
            $booking = $stmt->fetch();

            if ($booking) {
                $waitlist_client = getNextWaitlistClient($booking['booking_date'], $booking['booking_time'], $booking['barber_id']);
                if ($waitlist_client) {
                    updateWaitlistStatus($waitlist_client['id'], 'notified');
                    $to = $waitlist_client['client_email'] ?: '';
                    if (!empty($to)) {
                        $subject = "Good news! Your preferred time slot is now available - icut";
                        $waitlist_body = "<html><body style='font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;'><div style='max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;'><h2 style='color: #c9a96e;'>icut</h2><h3>🎉 Time Slot Available!</h3><p>Hi " . htmlspecialchars($waitlist_client['client_name']) . ",</p><p>Great news! A time slot has just opened up for your preferred barber:</p><p><strong>Date:</strong> " . date('F j, Y', strtotime($booking['booking_date'])) . "</p><p><strong>Time:</strong> " . date('g:i A', strtotime($booking['booking_time'])) . "</p><p>Book now before it's taken!</p></div></body></html>";
                        sendEmailNotification($to, $subject, $waitlist_body);
                    }
                }
            }

            $db->commit();
            logAdminActivity('slot_release', $user['name'], "Released slot for booking #$booking_id");
            echo json_encode(['success' => true, 'message' => 'Slot released successfully']);
            break;

        // Review management
        case 'approve_review':
            $review_id = (int)($_POST['review_id'] ?? 0);
            $db->prepare("UPDATE reviews SET is_approved = 1 WHERE id = :id")->execute([':id' => $review_id]);
            logAdminActivity('review_approve', $user['name'], "Approved review #$review_id");
            echo json_encode(['success' => true, 'message' => 'Review approved']);
            break;

        case 'delete_review':
            $review_id = (int)($_POST['review_id'] ?? 0);
            $db->prepare("DELETE FROM reviews WHERE id = :id")->execute([':id' => $review_id]);
            logAdminActivity('review_delete', $user['name'], "Deleted review #$review_id");
            echo json_encode(['success' => true, 'message' => 'Review deleted']);
            break;

        // Gallery management
        case 'add_gallery':
            $title = sanitizeInput($_POST['title'] ?? '');
            $media_type = sanitizeInput($_POST['media_type'] ?? 'image');
            $file = $_FILES['media'] ?? null;

            if (empty($title) || !$file || !$file['tmp_name']) {
                throw new Exception('Title and media file are required');
            }

            $file_url = uploadFile($file, 'gallery');

            if ($file_url) {
                $db->prepare("
                    INSERT INTO gallery (title, file_path, media_type)
                    VALUES (:title, :path, :type)
                ")->execute([
                    ':title' => $title,
                    ':path' => $file_url,
                    ':type' => $media_type
                ]);

                logAdminActivity('gallery_add', $user['name'], "Added gallery item: $title");
                echo json_encode(['success' => true, 'message' => 'Gallery item added']);
            } else {
                throw new Exception('Failed to upload file');
            }
            break;

        case 'delete_gallery':
            $gallery_id = (int)($_POST['gallery_id'] ?? 0);
            $db->prepare("DELETE FROM gallery WHERE id = :id")->execute([':id' => $gallery_id]);
            logAdminActivity('gallery_delete', $user['name'], "Deleted gallery item #$gallery_id");
            echo json_encode(['success' => true, 'message' => 'Gallery item deleted']);
            break;

        // Site settings
        case 'update_settings':
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'setting_') === 0) {
                    $setting_key = str_replace('setting_', '', $key);
                    $db->prepare("
                        INSERT INTO site_settings (setting_key, setting_value)
                        VALUES (:key, :value)
                        ON DUPLICATE KEY UPDATE setting_value = :value
                    ")->execute([':key' => $setting_key, ':value' => sanitizeInput($value)]);
                }
            }
            logAdminActivity('settings_update', $user['name'], 'Updated site settings');
            echo json_encode(['success' => true, 'message' => 'Settings updated']);
            break;

        case 'update_business_hours':
            $day = sanitizeInput($_POST['day'] ?? '');
            $open_time = sanitizeInput($_POST['open_time'] ?? '');
            $close_time = sanitizeInput($_POST['close_time'] ?? '');
            $is_closed = isset($_POST['is_closed']) ? 1 : 0;

            if (empty($day)) {
                throw new Exception('Day is required');
            }

            $db->prepare("
                UPDATE business_hours 
                SET open_time = :open, close_time = :close, is_closed = :closed
                WHERE day = :day
            ")->execute([
                ':open' => $is_closed ? null : $open_time,
                ':close' => $is_closed ? null : $close_time,
                ':closed' => $is_closed,
                ':day' => $day
            ]);

            logAdminActivity('settings', $user['name'], "Updated business hours for $day");
            echo json_encode(['success' => true, 'message' => 'Business hours updated']);
            break;

        // Loyalty management
        case 'add_loyalty':
            $client_phone = sanitizeInput($_POST['client_phone'] ?? '');
            $visits = (int)($_POST['visits'] ?? 1);
            $reward = sanitizeInput($_POST['reward'] ?? '');

            $db->prepare("
                INSERT INTO loyalty (client_phone, visits, reward, redeemed)
                VALUES (:phone, :visits, :reward, 0)
                ON DUPLICATE KEY UPDATE visits = visits + :visits
            ")->execute([
                ':phone' => $client_phone,
                ':visits' => $visits,
                ':reward' => $reward
            ]);

            logAdminActivity('loyalty_add', $user['name'], "Added loyalty for $client_phone");
            echo json_encode(['success' => true, 'message' => 'Loyalty points added']);
            break;

        // Waitlist management
        case 'notify_waitlist':
            $waitlist_id = (int)($_POST['waitlist_id'] ?? 0);
            $db->prepare("UPDATE waitlist SET status = 'notified' WHERE id = :id")
               ->execute([':id' => $waitlist_id]);
            logAdminActivity('waitlist_notify', $user['name'], "Notified waitlist client #$waitlist_id");
            echo json_encode(['success' => true, 'message' => 'Client notified']);
            break;

        // Payment/Refund management
        case 'process_refund':
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $reason = sanitizeInput($_POST['reason'] ?? '');

            $stmt = $db->prepare("
                UPDATE bookings 
                SET status = 'cancelled', 
                    cancelled_at = NOW(), 
                    refund_status = 'requested',
                    refund_reference = CONCAT('REF-', UUID())
                WHERE id = :id
            ");
            $stmt->execute([':id' => $booking_id]);

            logAdminActivity('refund_process', $user['name'], "Processed refund for booking #$booking_id. Reason: $reason");
            echo json_encode(['success' => true, 'message' => 'Refund processed']);
            break;

        // 2FA management
        case 'enable_2fa':
            $admin_id = (int)($_POST['admin_id'] ?? 0);
            $secret = bin2hex(random_bytes(16));
            $db->prepare("
                INSERT INTO admin_2fa (admin_id, secret, enabled)
                VALUES (:admin_id, :secret, 1)
                ON DUPLICATE KEY UPDATE secret = :secret, enabled = 1
            ")->execute([':admin_id' => $admin_id, ':secret' => $secret]);
            logAdminActivity('2fa_enable', $user['name'], "Enabled 2FA for admin ID: $admin_id");
            echo json_encode(['success' => true, 'message' => '2FA enabled', 'secret' => $secret]);
            break;

        case 'disable_2fa':
            $admin_id = (int)($_POST['admin_id'] ?? 0);
            $db->prepare("UPDATE admin_2fa SET enabled = 0 WHERE admin_id = :id")
               ->execute([':id' => $admin_id]);
            logAdminActivity('2fa_disable', $user['name'], "Disabled 2FA for admin ID: $admin_id");
            echo json_encode(['success' => true, 'message' => '2FA disabled']);
            break;

        case 'bulk_actions':
            $bulk_status = sanitizeInput($_POST['bulk_status'] ?? '');
            $booking_ids = $_POST['booking_ids'] ?? [];

            if (empty($bulk_status) || empty($booking_ids)) {
                throw new Exception('Please select an action and at least one booking');
            }

            if (!is_array($booking_ids)) {
                $booking_ids = [$booking_ids];
            }

            $booking_ids = array_map('intval', $booking_ids);

            if ($bulk_status === 'whatsapp') {
                echo json_encode(['success' => true, 'message' => 'WhatsApp reminders opened']);
            } else {
                $placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
                $stmt = $db->prepare("UPDATE bookings SET status = :status WHERE id IN ($placeholders)");
                $stmt->execute(array_merge([$bulk_status], $booking_ids));

                logAdminActivity('bulk_update', $user['name'], "Bulk updated " . count($booking_ids) . " bookings to $bulk_status");
                echo json_encode(['success' => true, 'message' => count($booking_ids) . ' bookings updated']);
            }
            break;

        case 'get_business_hours':
            $hours = $db->query("SELECT * FROM business_hours ORDER BY day_of_week")->fetchAll();
            echo json_encode(['success' => true, 'hours' => $hours]);
            break;

        case 'update_business_hours':
            $day = sanitizeInput($_POST['day'] ?? '');
            $open_time = sanitizeInput($_POST['open_time'] ?? '');
            $close_time = sanitizeInput($_POST['close_time'] ?? '');
            $is_closed = isset($_POST['is_closed']) ? 1 : 0;

            if (empty($day)) {
                throw new Exception('Day is required');
            }

            $db->prepare("
                UPDATE business_hours 
                SET open_time = :open, close_time = :close, is_closed = :closed
                WHERE day = :day
            ")->execute([
                ':open' => $is_closed ? null : $open_time,
                ':close' => $is_closed ? null : $close_time,
                ':closed' => $is_closed,
                ':day' => $day
            ]);

            logAdminActivity('settings', $user['name'], "Updated business hours for $day");
            echo json_encode(['success' => true, 'message' => 'Business hours updated']);
            break;

        case 'get_print_sheet':
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
            echo json_encode(['success' => true, 'bookings' => $bookings, 'date' => $date]);
            break;

        case 'get_client_history':
            $phone = sanitizeInput($_GET['phone'] ?? '');
            if (empty($phone)) {
                throw new Exception('Phone number is required');
            }

            $notes = $db->prepare("SELECT * FROM client_notes WHERE client_phone = :phone")->execute([':phone' => $phone])->fetch();
            $stmt = $db->prepare("
                SELECT b.*, br.name as barber_name, s.name as service_name
                FROM bookings b
                LEFT JOIN barbers br ON b.barber_id = br.id
                LEFT JOIN services s ON b.service_id = s.id
                WHERE b.client_phone = :phone
                ORDER BY b.booking_date DESC
                LIMIT 50
            ");
            $stmt->execute([':phone' => $phone]);
            $bookings = $stmt->fetchAll();

            echo json_encode(['success' => true, 'notes' => $notes, 'bookings' => $bookings]);
            break;

        case 'update_client_notes':
            $phone = sanitizeInput($_POST['client_phone'] ?? '');
            $notes = sanitizeInput($_POST['notes'] ?? '');
            $preferences = sanitizeInput($_POST['preferences'] ?? '');

            if (empty($phone)) {
                throw new Exception('Phone number is required');
            }

            $stmt = $db->prepare("
                INSERT INTO client_notes (client_phone, notes, preferences, updated_at)
                VALUES (:phone, :notes, :prefs, NOW())
                ON DUPLICATE KEY UPDATE notes = :notes, preferences = :prefs, updated_at = NOW()
            ");
            $stmt->execute([
                ':phone' => $phone,
                ':notes' => $notes,
                ':prefs' => $preferences
            ]);

            logAdminActivity('client_note', $user['name'], "Updated notes for $phone");
            echo json_encode(['success' => true, 'message' => 'Client notes updated']);
            break;

        case 'export_bookings':
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

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=bookings_' . date('Y-m-d') . '.csv');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");

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

        case 'change_password':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $stmt = $db->prepare("SELECT username, email FROM admins WHERE id = :id");
                $stmt->execute([':id' => $user['user_id']]);
                $admin = $stmt->fetch();

                echo json_encode([
                    'success' => true,
                    'admin' => $admin
                ]);
            } else {
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Invalid security token']);
                    exit;
                }

                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $new_username = sanitizeInput($_POST['new_username'] ?? '');
                $new_email = sanitizeInput($_POST['new_email'] ?? '');

                if (empty($current_password)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Current password is required']);
                    exit;
                }

                $stmt = $db->prepare("SELECT * FROM admins WHERE id = :id");
                $stmt->execute([':id' => $user['user_id']]);
                $admin = $stmt->fetch();

                if (!password_verify($current_password, $admin['password_hash'])) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Current password is incorrect']);
                    exit;
                }

                $updated = false;

                if (!empty($new_password)) {
                    if (strlen($new_password) < 8) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Password must be at least 8 characters']);
                        exit;
                    }

                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE admins SET password_hash = :password WHERE id = :id")
                       ->execute([':password' => $new_hash, ':id' => $user['user_id']]);
                    $updated = true;
                }

                if (!empty($new_username) && $new_username !== $admin['username']) {
                    $db->prepare("UPDATE admins SET username = :username WHERE id = :id")
                       ->execute([':username' => $new_username, ':id' => $user['user_id']]);
                    $updated = true;
                }

                if (!empty($new_email) && $new_email !== $admin['email']) {
                    $db->prepare("UPDATE admins SET email = :email WHERE id = :id")
                       ->execute([':email' => $new_email, ':id' => $user['user_id']]);
                    $updated = true;
                }

                if ($updated) {
                    logAdminActivity('account_update', $user['name'], 'Account settings updated');
                    echo json_encode(['success' => true, 'message' => 'Account updated successfully']);
                } else {
                    echo json_encode(['success' => true, 'message' => 'No changes made']);
                }
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
            break;
    }
} catch (Exception $e) {
    error_log("Admin handler error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
