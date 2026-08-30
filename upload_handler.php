<?php
require_once 'admin_auth.php';

// Increase PHP upload limits for large files
@ini_set('upload_max_filesize', '10M');
@ini_set('post_max_size', '10M');
@ini_set('max_execution_time', 300);
@ini_set('max_input_time', 300);
@ini_set('memory_limit', '256M');

// Handle file uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Validate CSRF token for all actions
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['upload_error'] = 'Invalid security token. Please refresh the page.';
        header('Location: admin.php');
        exit;
    }
    
    // ============ SAVE SITE SETTINGS ============
    if ($action === 'save_settings') {
        $setting_keys = [
            'happy_clients', 'years_exp', 'rating', 'address', 'phone',
            'email', 'hours_weekday', 'hours_saturday', 'hours_sunday', 'footer_about', 'logo',
            'map_embed_url', 'whatsapp_number', 'home_service_fee', 'home_service_days',
            'tiktok_url', 'instagram_url', 'x_url',
            'email_subject_confirmation', 'email_body_confirmation',
            'email_subject_status', 'email_body_status',
            'email_subject_reminder', 'email_body_reminder'
        ];
        
        foreach ($setting_keys as $key) {
            if (isset($_POST[$key])) {
                // home_service_days arrives as a multi-select array -> store as CSV
                $value = is_array($_POST[$key])
                    ? implode(',', array_filter(array_map('intval', $_POST[$key]), function ($v) { return $v >= 0 && $v <= 6; }))
                    : trim($_POST[$key]);
                $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([':key' => $key, ':value' => $value]);
            }
        }
        $_SESSION['upload_message'] = "Settings saved successfully!";
        logAdminActivity('settings_update', $_SESSION['admin_name'] ?? 'Admin', "Updated site settings");
        header('Location: admin.php#settings');
        exit;
    }
    
    // ============ LOGO UPLOAD ============
    if ($action === 'upload_logo' && isset($_FILES['logo_image'])) {
        $file = $_FILES['logo_image'];
        
        // Delete old logo if it exists
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'logo'");
        $stmt->execute();
        $old_logo = $stmt->fetch();
        if ($old_logo && $old_logo['setting_value']) {
            $old_path = __DIR__ . '/' . $old_logo['setting_value'];
            if (file_exists($old_path)) {
                unlink($old_path);
            }
        }
        
        $result = handleUpload($file, 'logo');
        if ($result['success']) {
            $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('logo', :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            $stmt->execute([':value' => $result['path']]);
            $_SESSION['upload_message'] = "Logo uploaded successfully!";
        } else {
            $_SESSION['upload_error'] = $result['error'];
        }
        header('Location: admin.php#settings');
        exit;
    }
    
    // ============ EDIT BARBER ============
    if ($action === 'edit_barber' && isset($_POST['barber_id'])) {
        $barber_id = (int)$_POST['barber_id'];
        $name = trim($_POST['barber_name'] ?? '');
        $phone = trim($_POST['barber_phone'] ?? '');
        $specialization = trim($_POST['barber_specialization'] ?? '');
        
        if (!empty($name) && !empty($phone)) {
            $stmt = $db->prepare("UPDATE barbers SET name = :name, phone = :phone, specialization = :specialization WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':specialization' => $specialization,
                ':id' => $barber_id
            ]);
            $_SESSION['upload_message'] = "Barber updated successfully!";
        }
        logAdminActivity('barber_edit', $_SESSION['admin_name'] ?? 'Admin', "Updated barber: {$name}", $barber_id);
        header('Location: admin.php#barbers');
        exit;
    }
    
    // ============ EDIT SERVICE ============
    if ($action === 'edit_service' && isset($_POST['service_id'])) {
        $service_id = (int)$_POST['service_id'];
        $name = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['service_description'] ?? '');
        $price = (float)($_POST['service_price'] ?? 0);
        $duration = (int)($_POST['service_duration'] ?? 0);
        
        if (!empty($name) && $price > 0 && $duration > 0) {
            $stmt = $db->prepare("UPDATE services SET name = :name, description = :description, price = :price, duration_minutes = :duration WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':duration' => $duration,
                ':id' => $service_id
            ]);
            $_SESSION['upload_message'] = "Service updated successfully!";
        }
        logAdminActivity('service_edit', $_SESSION['admin_name'] ?? 'Admin', "Updated service: {$name}", $service_id);
        header('Location: admin.php#services');
        exit;
    }
    
    // ============ EDIT GALLERY ============
    if ($action === 'edit_gallery' && isset($_POST['gallery_id'])) {
        $gallery_id = (int)$_POST['gallery_id'];
        $title = trim($_POST['gallery_title'] ?? '');
        
        if (!empty($title)) {
            $stmt = $db->prepare("UPDATE gallery SET title = :title WHERE id = :id");
            $stmt->execute([':title' => $title, ':id' => $gallery_id]);
            $_SESSION['upload_message'] = "Gallery item updated successfully!";
        }
        header('Location: admin.php#gallery');
        exit;
    }
    
    // ============ TOGGLE BARBER STATUS ============
    if ($action === 'toggle_barber' && isset($_POST['barber_id'])) {
        $barber_id = (int)$_POST['barber_id'];
        $stmt = $db->prepare("UPDATE barbers SET is_active = NOT is_active WHERE id = :id");
        $stmt->execute([':id' => $barber_id]);
        $_SESSION['upload_message'] = "Barber status updated!";
        header('Location: admin.php#barbers');
        exit;
    }
    
    // ============ TOGGLE BARBER HOME SERVICE ============
    if ($action === 'toggle_barber_home_service' && isset($_POST['barber_id'])) {
        $barber_id = (int)$_POST['barber_id'];
        $offers_home_service = (int)($_POST['offers_home_service'] ?? 0);
        $stmt = $db->prepare("UPDATE barbers SET offers_home_service = :status WHERE id = :id");
        $stmt->execute([':status' => $offers_home_service, ':id' => $barber_id]);
        $_SESSION['upload_message'] = "Barber home service availability updated!";
        logAdminActivity('barber_home_service_toggle', $_SESSION['admin_name'] ?? 'Admin', "Toggled home service for barber ID: {$barber_id}", $barber_id);
        header('Location: admin.php#barbers');
        exit;
    }
    
    // ============ TOGGLE SERVICE STATUS ============
    if ($action === 'toggle_service' && isset($_POST['service_id'])) {
        $service_id = (int)$_POST['service_id'];
        $stmt = $db->prepare("UPDATE services SET is_active = NOT is_active WHERE id = :id");
        $stmt->execute([':id' => $service_id]);
        $_SESSION['upload_message'] = "Service status updated!";
        header('Location: admin.php#services');
        exit;
    }
    
    // ============ BULK DELETE BARBERS ============
    if ($action === 'bulk_delete_barbers' && isset($_POST['barber_ids'])) {
        $barber_ids = array_map('intval', $_POST['barber_ids']);
        if (!empty($barber_ids)) {
            $placeholders = implode(',', array_fill(0, count($barber_ids), '?'));
            $stmt = $db->prepare("DELETE FROM barbers WHERE id IN ($placeholders)");
            $stmt->execute($barber_ids);
            $_SESSION['upload_message'] = count($barber_ids) . " barbers deleted successfully!";
        }
        header('Location: admin.php#barbers');
        exit;
    }
    
    // ============ BULK DELETE SERVICES ============
    if ($action === 'bulk_delete_services' && isset($_POST['service_ids'])) {
        $service_ids = array_map('intval', $_POST['service_ids']);
        if (!empty($service_ids)) {
            $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
            $stmt = $db->prepare("DELETE FROM services WHERE id IN ($placeholders)");
            $stmt->execute($service_ids);
            $_SESSION['upload_message'] = count($service_ids) . " services deleted successfully!";
        }
        header('Location: admin.php#services');
        exit;
    }
    
    // ============ BULK DELETE GALLERY ============
    if ($action === 'bulk_delete_gallery' && isset($_POST['gallery_ids'])) {
        $gallery_ids = array_map('intval', $_POST['gallery_ids']);
        if (!empty($gallery_ids)) {
            $placeholders = implode(',', array_fill(0, count($gallery_ids), '?'));
            
            // Delete files first
            $stmt = $db->prepare("SELECT file_path FROM gallery WHERE id IN ($placeholders)");
            $stmt->execute($gallery_ids);
            $files = $stmt->fetchAll();
            foreach ($files as $file) {
                $full_path = __DIR__ . '/' . $file['file_path'];
                if (file_exists($full_path)) {
                    unlink($full_path);
                }
            }
            
            // Delete from database
            $stmt = $db->prepare("DELETE FROM gallery WHERE id IN ($placeholders)");
            $stmt->execute($gallery_ids);
            $_SESSION['upload_message'] = count($gallery_ids) . " gallery items deleted successfully!";
        }
        header('Location: admin.php#gallery');
        exit;
    }
    
    // ============ BARBER UPLOAD ============
    if ($action === 'upload_barber_image' && isset($_FILES['barber_image']) && isset($_POST['barber_id'])) {
        $barber_id = (int)$_POST['barber_id'];
        $file = $_FILES['barber_image'];
        
        $result = handleUpload($file, 'barbers');
        if ($result['success']) {
            $stmt = $db->prepare("UPDATE barbers SET image = :image WHERE id = :id");
            $stmt->execute([':image' => $result['path'], ':id' => $barber_id]);
            $_SESSION['upload_message'] = "Barber photo uploaded successfully!";
        } else {
            $_SESSION['upload_error'] = $result['error'];
        }
        header('Location: admin.php#barbers');
        exit;
    }
    
    // ============ SERVICE UPLOAD ============
    if ($action === 'upload_service_image' && isset($_FILES['service_image']) && isset($_POST['service_id'])) {
        $service_id = (int)$_POST['service_id'];
        $file = $_FILES['service_image'];
        
        $result = handleUpload($file, 'services');
        if ($result['success']) {
            $stmt = $db->prepare("UPDATE services SET image = :image WHERE id = :id");
            $stmt->execute([':image' => $result['path'], ':id' => $service_id]);
            $_SESSION['upload_message'] = "Service image uploaded successfully!";
        } else {
            $_SESSION['upload_error'] = $result['error'];
        }
        header('Location: admin.php#services');
        exit;
    }
    
    // ============ GALLERY UPLOAD ============
    if ($action === 'upload_gallery' && isset($_FILES['gallery_file'])) {
        $title = trim($_POST['gallery_title'] ?? '');
        $file = $_FILES['gallery_file'];
        
        // Determine media type
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $video_exts = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'mpg', 'mpeg', 'm4v', '3gp', '3g2', 'ts', 'mts', 'm2ts', 'vob', 'rm', 'rmvb', 'dv', 'divx'];
        $media_type = in_array($file_ext, $video_exts) ? 'video' : 'image';
        
        $result = handleUpload($file, 'gallery');
        if ($result['success']) {
            $stmt = $db->prepare("INSERT INTO gallery (title, media_type, file_path) VALUES (:title, :media_type, :file_path)");
            $stmt->execute([
                ':title' => $title,
                ':media_type' => $media_type,
                ':file_path' => $result['path']
            ]);
            $_SESSION['upload_message'] = "Gallery item uploaded successfully!";
        } else {
            $_SESSION['upload_error'] = $result['error'];
        }
        header('Location: admin.php#gallery');
        exit;
    }
    
    // ============ DELETE GALLERY ITEM ============
    if ($action === 'delete_gallery' && isset($_POST['gallery_id'])) {
        $gallery_id = (int)$_POST['gallery_id'];
        
        // Get file path to delete
        $stmt = $db->prepare("SELECT file_path FROM gallery WHERE id = :id");
        $stmt->execute([':id' => $gallery_id]);
        $item = $stmt->fetch();
        
        if ($item) {
            // Delete file from server
            $full_path = __DIR__ . '/' . $item['file_path'];
            if (file_exists($full_path)) {
                unlink($full_path);
            }
            
            // Delete from database
            $stmt = $db->prepare("DELETE FROM gallery WHERE id = :id");
            $stmt->execute([':id' => $gallery_id]);
            $_SESSION['upload_message'] = "Gallery item deleted successfully!";
        }
        header('Location: admin.php#gallery');
        exit;
    }
    
    // ============ DELETE BARBER ============
    if ($action === 'delete_barber' && isset($_POST['barber_id'])) {
        $barber_id = (int)$_POST['barber_id'];
        
        // Get file path to delete
        $stmt = $db->prepare("SELECT image FROM barbers WHERE id = :id");
        $stmt->execute([':id' => $barber_id]);
        $barber = $stmt->fetch();
        
        if ($barber && $barber['image']) {
            $full_path = __DIR__ . '/' . $barber['image'];
            if (file_exists($full_path)) {
                unlink($full_path);
            }
        }
        
        $stmt = $db->prepare("DELETE FROM barbers WHERE id = :id");
        $stmt->execute([':id' => $barber_id]);
        $_SESSION['upload_message'] = "Barber deleted successfully!";
        logAdminActivity('barber_delete', $_SESSION['admin_name'] ?? 'Admin', "Deleted barber ID: {$barber_id}", $barber_id);
        header('Location: admin.php#barbers');
        exit;
    }
    
    // ============ DELETE SERVICE ============
    if ($action === 'delete_service' && isset($_POST['service_id'])) {
        $service_id = (int)$_POST['service_id'];
        
        // Get file path to delete
        $stmt = $db->prepare("SELECT image FROM services WHERE id = :id");
        $stmt->execute([':id' => $service_id]);
        $service = $stmt->fetch();
        
        if ($service && $service['image']) {
            $full_path = __DIR__ . '/' . $service['image'];
            if (file_exists($full_path)) {
                unlink($full_path);
            }
        }
        
        $stmt = $db->prepare("DELETE FROM services WHERE id = :id");
        $stmt->execute([':id' => $service_id]);
        $_SESSION['upload_message'] = "Service deleted successfully!";
        logAdminActivity('service_delete', $_SESSION['admin_name'] ?? 'Admin', "Deleted service ID: {$service_id}", $service_id);
        header('Location: admin.php#services');
        exit;
    }
    
    // ============ ADD BARBER ============
    if ($action === 'add_barber') {
        $name = trim($_POST['barber_name'] ?? '');
        $phone = trim($_POST['barber_phone'] ?? '');
        $specialization = trim($_POST['barber_specialization'] ?? '');
        
        if (!empty($name) && !empty($phone)) {
            $image = null;
            if (isset($_FILES['barber_new_image']) && $_FILES['barber_new_image']['error'] === UPLOAD_ERR_OK) {
                $result = handleUpload($_FILES['barber_new_image'], 'barbers');
                if ($result['success']) {
                    $image = $result['path'];
                }
            }
            
            $stmt = $db->prepare("INSERT INTO barbers (name, phone, specialization, image) VALUES (:name, :phone, :specialization, :image)");
            $stmt->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':specialization' => $specialization,
                ':image' => $image
            ]);
            $_SESSION['upload_message'] = "Barber added successfully!";
        }
        logAdminActivity('barber_add', $_SESSION['admin_name'] ?? 'Admin', "Added new barber: {$name}");
        header('Location: admin.php#barbers');
        exit;
    }
    
    // ============ ADD SERVICE ============
    if ($action === 'add_service') {
        $name = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['service_description'] ?? '');
        $price = (float)($_POST['service_price'] ?? 0);
        $duration = (int)($_POST['service_duration'] ?? 0);
        
        if (!empty($name) && $price > 0 && $duration > 0) {
            $image = null;
            if (isset($_FILES['service_new_image']) && $_FILES['service_new_image']['error'] === UPLOAD_ERR_OK) {
                $result = handleUpload($_FILES['service_new_image'], 'services');
                if ($result['success']) {
                    $image = $result['path'];
                }
            }
            
            $stmt = $db->prepare("INSERT INTO services (name, description, price, duration_minutes, image) VALUES (:name, :description, :price, :duration, :image)");
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':duration' => $duration,
                ':image' => $image
            ]);
            $_SESSION['upload_message'] = "Service added successfully!";
        }
        logAdminActivity('service_add', $_SESSION['admin_name'] ?? 'Admin', "Added new service: {$name}");
        header('Location: admin.php#services');
        exit;
    }
    
    // ============ ADD REVIEW ============
    if ($action === 'add_review') {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['upload_error'] = 'Invalid security token. Please refresh the page.';
            header('Location: index.php#reviews');
            exit;
        }
        
        // Validate CAPTCHA
        if (empty($_POST['review_captcha']) || !validateCaptcha($_POST['review_captcha'])) {
            $_SESSION['upload_error'] = 'Incorrect security answer. Please try again.';
            header('Location: index.php#reviews');
            exit;
        }
        
        $client_name = sanitizeInput($_POST['client_name'] ?? '');
        $rating = (int)($_POST['rating'] ?? 5);
        $comment = sanitizeInput($_POST['comment'] ?? '');
        $service_name = sanitizeInput($_POST['service_name'] ?? '');
        
        if (!empty($client_name) && $rating >= 1 && $rating <= 5) {
            $stmt = $db->prepare("INSERT INTO reviews (client_name, rating, comment, service_name) VALUES (:name, :rating, :comment, :service)");
            $stmt->execute([
                ':name' => $client_name,
                ':rating' => $rating,
                ':comment' => $comment,
                ':service' => $service_name
            ]);
            $_SESSION['upload_message'] = "Thank you for your review! It will be visible after approval.";
        }
        header('Location: index.php#reviews');
        exit;
    }
    
    // ============ APPROVE REVIEW ============
    if ($action === 'approve_review' && isset($_POST['review_id'])) {
        $review_id = (int)$_POST['review_id'];
        $stmt = $db->prepare("UPDATE reviews SET is_approved = 1 WHERE id = :id");
        $stmt->execute([':id' => $review_id]);
        $_SESSION['upload_message'] = "Review approved!";
        logAdminActivity('review_approve', $_SESSION['admin_name'] ?? 'Admin', "Approved review #$review_id", $review_id);
        header('Location: admin.php#reviews');
        exit;
    }
    
    // ============ DELETE REVIEW ============
    if ($action === 'delete_review' && isset($_POST['review_id'])) {
        $review_id = (int)$_POST['review_id'];
        $stmt = $db->prepare("DELETE FROM reviews WHERE id = :id");
        $stmt->execute([':id' => $review_id]);
        $_SESSION['upload_message'] = "Review deleted!";
        logAdminActivity('review_delete', $_SESSION['admin_name'] ?? 'Admin', "Deleted review #$review_id", $review_id);
        header('Location: admin.php#reviews');
        exit;
    }
    
    // ============ LOG ADMIN ACTIVITY ============
    if ($action === 'log_activity') {
        $activity_type = $_POST['activity_type'] ?? 'other';
        $admin_name = $_SESSION['admin_name'] ?? 'Admin';
        $details = trim($_POST['details'] ?? '');
        $reference_id = (int)($_POST['reference_id'] ?? 0);
        
        logAdminActivity($activity_type, $admin_name, $details, $reference_id);
        // Don't redirect, just return
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ============ ADD LOYALTY POINTS ============
    if ($action === 'add_loyalty_points') {
        $phone = trim($_POST['loyalty_phone'] ?? '');
        $points = (int)($_POST['loyalty_points'] ?? 0);
        
        if (!empty($phone) && $points > 0) {
            // Get or create loyalty record
            $stmt = $db->prepare("SELECT id, points FROM loyalty WHERE phone = :phone");
            $stmt->execute([':phone' => $phone]);
            $loyalty = $stmt->fetch();
            
            if ($loyalty) {
                $new_points = $loyalty['points'] + $points;
                $stmt = $db->prepare("UPDATE loyalty SET points = :points WHERE id = :id");
                $stmt->execute([':points' => $new_points, ':id' => $loyalty['id']]);
            } else {
                $stmt = $db->prepare("INSERT INTO loyalty (phone, points) VALUES (:phone, :points)");
                $stmt->execute([':phone' => $phone, ':points' => $points]);
            }
            $_SESSION['upload_message'] = "Added {$points} loyalty points for {$phone}!";
        }
        header('Location: admin.php#loyalty');
        exit;
    }
    
    // ============ REDEEM LOYALTY POINTS ============
    if ($action === 'redeem_loyalty_points') {
        $loyalty_id = (int)$_POST['loyalty_id'];
        $points = (int)$_POST['loyalty_points'];
        
        if ($loyalty_id > 0 && $points > 0) {
            $stmt = $db->prepare("UPDATE loyalty SET points = IF(points - :points < 0, 0, points - :points), updated_at = NOW() WHERE id = :id");
            $stmt->execute([':points' => $points, ':id' => $loyalty_id]);
            $_SESSION['upload_message'] = "Redeemed {$points} loyalty points!";
        }
        header('Location: admin.php#loyalty');
        exit;
    }
    
    // ============ PACKAGE CRUD ============
    if ($action === 'add_package') {
        $name = trim($_POST['package_name'] ?? '');
        $description = trim($_POST['package_description'] ?? '');
        $price = (float)($_POST['package_price'] ?? 0);
        $service_ids = isset($_POST['package_services']) ? implode(',', $_POST['package_services']) : '';
        
        if (!empty($name) && $price > 0 && !empty($service_ids)) {
            $stmt = $db->prepare("INSERT INTO packages (name, description, price, service_ids) VALUES (:name, :desc, :price, :services)");
            $stmt->execute([
                ':name' => $name,
                ':desc' => $description,
                ':price' => $price,
                ':services' => $service_ids
            ]);
            $_SESSION['upload_message'] = "Package added successfully!";
        }
        header('Location: admin.php#packages');
        exit;
    }
    
    if ($action === 'edit_package') {
        $package_id = (int)$_POST['package_id'];
        $name = trim($_POST['package_name'] ?? '');
        $description = trim($_POST['package_description'] ?? '');
        $price = (float)($_POST['package_price'] ?? 0);
        $service_ids = isset($_POST['package_services']) ? implode(',', $_POST['package_services']) : '';
        $is_active = isset($_POST['package_active']) ? 1 : 0;
        
        if (!empty($name) && $price > 0 && !empty($service_ids)) {
            $stmt = $db->prepare("UPDATE packages SET name = :name, description = :desc, price = :price, service_ids = :services, is_active = :active WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':desc' => $description,
                ':price' => $price,
                ':services' => $service_ids,
                ':active' => $is_active,
                ':id' => $package_id
            ]);
            $_SESSION['upload_message'] = "Package updated successfully!";
        }
        header('Location: admin.php#packages');
        exit;
    }
    
    if ($action === 'delete_package') {
        $package_id = (int)$_POST['package_id'];
        $stmt = $db->prepare("DELETE FROM packages WHERE id = :id");
        $stmt->execute([':id' => $package_id]);
        $_SESSION['upload_message'] = "Package deleted successfully!";
        header('Location: admin.php#packages');
        exit;
    }
    
    // ============ SAVE EMAIL TEMPLATES ============
    if ($action === 'save_email_templates') {
        $template_keys = [
            'email_subject_confirmation', 'email_body_confirmation',
            'email_subject_status', 'email_body_status',
            'email_subject_reminder', 'email_body_reminder'
        ];
        
        foreach ($template_keys as $key) {
            if (isset($_POST[$key])) {
                $value = trim($_POST[$key]);
                $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                $stmt->execute([':key' => $key, ':value' => $value]);
            }
        }
        $_SESSION['upload_message'] = "Email templates saved successfully!";
        header('Location: admin.php#settings');
        exit;
    }
    
    // ============ NOTIFY WAITLIST CLIENT ============
    if ($action === 'notify_waitlist') {
        $waitlist_id = (int)$_POST['waitlist_id'];
        $stmt = $db->prepare("SELECT * FROM waitlist WHERE id = :id");
        $stmt->execute([':id' => $waitlist_id]);
        $waitlist_item = $stmt->fetch();
        
        if ($waitlist_item) {
            updateWaitlistStatus($waitlist_id, 'notified');
            
            if (!empty($waitlist_item['client_email'])) {
                $subject = "Good news! Your preferred time slot is now available - icut";
                $message = "<html><body style='font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;'><div style='max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;'><h2 style='color: #c9a96e;'>icut</h2><h3>🎉 Time Slot Available!</h3><p>Hi {$waitlist_item['client_name']},</p><p>Great news! A time slot has just opened up for your preferred barber:</p><table style='width: 100%; border-collapse: collapse; margin: 20px 0;'><tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Date:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>" . date('F j, Y', strtotime($waitlist_item['preferred_date'])) . "</td></tr><tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Time:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>" . date('g:i A', strtotime($waitlist_item['preferred_time'])) . "</td></tr><tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Barber:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$waitlist_item['barber_name']}</td></tr></table><p>Book now before it's taken! <a href='" . htmlspecialchars(env('SITE_URL', 'http://localhost/icut')) . "/' style='color: #c9a96e;'>Click here to book</a></p><p style='color: #c9a96e;'>See you soon!</p></div></body></html>";
                sendEmailNotification($waitlist_item['client_email'], $subject, $message);
            }
            $_SESSION['upload_message'] = "Waitlist client notified!";
        }
        header('Location: admin.php#waitlist');
        exit;
    }
    
    // ============ 2FA SETUP ============
    if ($action === 'setup_2fa') {
        ensure2FATableExists();
        $secret = generate2FASecret($_SESSION['admin_id']);
        $_SESSION['2fa_secret'] = $secret;
        $_SESSION['upload_message'] = "Scan the QR code with your authenticator app, then confirm the 6-digit code.";
        header('Location: admin.php#settings');
        exit;
    }

    if ($action === 'confirm_2fa') {
        // Require a working code BEFORE enabling, otherwise a misconfigured
        // authenticator app would lock the admin out of their own account.
        if (empty($_SESSION['2fa_secret'])) {
            $_SESSION['upload_error'] = "Start the 2FA setup again.";
        } elseif (!verify2FACode($_SESSION['admin_id'], $_POST['twofa_code'] ?? '')) {
            $_SESSION['upload_error'] = "That code was not valid. Check your authenticator app's time and try the current code.";
        } else {
            enable2FA($_SESSION['admin_id']);
            unset($_SESSION['2fa_secret']);
            // Show one-time backup codes so a lost phone doesn't mean lost access
            $_SESSION['2fa_backup_codes'] = generate2FABackupCodes($_SESSION['admin_id']);
            logAdminActivity('2fa_enabled', $_SESSION['admin_name'] ?? 'Admin', 'Two-factor authentication enabled');
            $_SESSION['upload_message'] = "2FA enabled successfully! Save your backup codes.";
        }
        header('Location: admin.php#settings');
        exit;
    }

    if ($action === 'cancel_2fa') {
        // Abandon an in-progress setup and clear the unconfirmed secret
        disable2FA($_SESSION['admin_id']);
        unset($_SESSION['2fa_secret'], $_SESSION['2fa_backup_codes']);
        $_SESSION['upload_message'] = "2FA setup cancelled.";
        header('Location: admin.php#settings');
        exit;
    }

    if ($action === 'regenerate_2fa_backup_codes') {
        $_SESSION['2fa_backup_codes'] = generate2FABackupCodes($_SESSION['admin_id']);
        logAdminActivity('2fa_backup_codes', $_SESSION['admin_name'] ?? 'Admin', 'Regenerated 2FA backup codes');
        $_SESSION['upload_message'] = "New backup codes generated. Save them now.";
        header('Location: admin.php#settings');
        exit;
    }

    if ($action === 'disable_2fa') {
        disable2FA($_SESSION['admin_id']);
        unset($_SESSION['2fa_secret'], $_SESSION['2fa_backup_codes']);
        logAdminActivity('2fa_disabled', $_SESSION['admin_name'] ?? 'Admin', 'Two-factor authentication disabled');
        $_SESSION['upload_message'] = "2FA disabled successfully.";
        header('Location: admin.php#settings');
        exit;
    }
}

/**
 * Handle file upload
 * 
 * @param array $file The $_FILES array element
 * @param string $folder Subfolder name (barbers, services, gallery, logo)
 * @return array ['success' => bool, 'path' => string, 'error' => string]
 */
function handleUpload($file, $folder) {
    $upload_dir = __DIR__ . '/uploads/' . $folder . '/';
    
    // Define allowed extensions per folder
    $allowed_map = [
        'barbers' => ['jpg', 'jpeg', 'png', 'webp'],
        'services' => ['jpg', 'jpeg', 'png', 'webp'],
        'gallery' => ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'ogg', 'mov'],
        'logo' => ['jpg', 'jpeg', 'png', 'webp']
    ];
    
    $allowed_exts = $allowed_map[$folder] ?? ['jpg', 'jpeg', 'png', 'webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    // Validate the upload
    $validation = validateUpload($file, $allowed_exts, $max_size);
    if (!$validation['success']) {
        return $validation;
    }
    
    // Get file extension
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Ensure upload directory exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate secure unique filename (no user input)
    $new_filename = bin2hex(random_bytes(16)) . '.' . $file_ext;
    $destination = $upload_dir . $new_filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => true, 'path' => 'uploads/' . $folder . '/' . $new_filename];
    }
    
    return ['success' => false, 'error' => 'Failed to save file. Please check directory permissions.'];
}

// ============ REFUND HANDLER ============
if ($action === 'process_refund') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['upload_error'] = 'Invalid security token. Please refresh the page.';
        header('Location: admin.php');
        exit;
    }
    
    $booking_id = (int)$_POST['booking_id'];
    $refund_amount = $_POST['refund_amount'] ?? null;
    $refund_reason = sanitizeInput($_POST['refund_reason'] ?? '');
    
    $result = processRefund($booking_id, $refund_amount, $refund_reason);
    
    if ($result['success']) {
        $_SESSION['upload_message'] = $result['message'];
    } else {
        $_SESSION['upload_error'] = $result['message'];
    }
    
    header('Location: admin.php');
    exit;
}

// ============ RELEASE SLOT HANDLER ============
if ($action === 'release_slot') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['upload_error'] = 'Invalid security token. Please refresh the page.';
        header('Location: admin.php');
        exit;
    }
    
    $booking_id = (int)$_POST['booking_id'];
    
    $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id AND status = 'pending'");
    $stmt->execute([':id' => $booking_id]);
    
    if ($stmt->rowCount() > 0) {
        logAdminActivity('release_slot', 'System', "Released pending booking slot #{$booking_id}");
        $_SESSION['upload_message'] = 'Slot released successfully. The time slot is now available for booking.';
    } else {
        $_SESSION['upload_error'] = 'Booking not found or not in pending status.';
    }
    
    header('Location: admin.php');
    exit;
}
?>
