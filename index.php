<?php
session_start();
require_once 'db.php';

// Ensure home service columns exist
ensureHomeServiceColumns();
ensureBarberHomeServiceColumn();

// Fetch barbers for dropdown (only those offering home service if that's selected)
$barbers_query = "SELECT id, name, specialization, phone, image, offers_home_service FROM barbers WHERE is_active = 1 ORDER BY name";
$barbers = $db->query($barbers_query)->fetchAll();

// Fetch services for dropdown
$services_query = "SELECT id, name, description, price, duration_minutes, image FROM services WHERE is_active = 1 ORDER BY name";
$services = $db->query($services_query)->fetchAll();

// Fetch packages for booking
ensurePackagesTableExists();
$packages = getActivePackages();

// Fetch gallery items from database
$gallery_items = $db->query("SELECT * FROM gallery ORDER BY created_at DESC")->fetchAll();

// Fetch approved reviews
$reviews = $db->query("SELECT * FROM reviews WHERE is_approved = 1 ORDER BY created_at DESC LIMIT 6")->fetchAll();

// Fetch site settings
$settings_result = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
$settings = [];
foreach ($settings_result as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Logo setting
$logo = isset($settings['logo']) && $settings['logo'] ? $settings['logo'] : '';

// Absolute app-root path (e.g. "/icut") for internal cross-page links. Using an
// absolute path instead of a relative one prevents links (e.g. Staff Login) from
// resolving onto "/index.php/..." when the page happens to be opened at a URL with
// a trailing slash (e.g. http://localhost/icut/index.php/), which Apache would map
// back to index.php via PATH_INFO and never reach the target script.
$app_base_path = appBasePath();

// Default settings with fallbacks
$happy_clients = $settings['happy_clients'] ?? '5K+';
$years_exp = $settings['years_exp'] ?? '15+';
$rating = $settings['rating'] ?? '4.9';
$footer_about = $settings['footer_about'] ?? 'Premium grooming experience.';
$address = $settings['address'] ?? '123 Main Street';
$phone = $settings['phone'] ?? '+1 234 567 890';
$email = $settings['email'] ?? 'info@classiccuts.com';
$hours_weekday = $settings['hours_weekday'] ?? 'Mon - Fri: 9 AM - 7 PM';
$hours_saturday = $settings['hours_saturday'] ?? 'Saturday: 9 AM - 5 PM';
$hours_sunday = $settings['hours_sunday'] ?? 'Sunday: Closed';
$map_embed_url = $settings['map_embed_url'] ?? '';
$tiktok_url = trim($settings['tiktok_url'] ?? '');
$instagram_url = trim($settings['instagram_url'] ?? '');
$x_url = trim($settings['x_url'] ?? '');

// Generate CAPTCHA for forms
function generateCaptcha() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $a = rand(1, 9);
    $b = rand(1, 9);
    $_SESSION['captcha_answer'] = $a + $b;
    $_SESSION['captcha_question'] = "What is {$a} + {$b}?";
    return [
        'question' => $_SESSION['captcha_question'],
        'answer' => $a + $b
    ];
}

function validateCaptcha($user_answer) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['captcha_answer']) && (int)$user_answer === (int)$_SESSION['captcha_answer'];
}

// Generate idempotency key for booking form
function generateIdempotencyKey() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['booking_idempotency_key'])) {
        $_SESSION['booking_idempotency_key'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['booking_idempotency_key'];
}

function validateIdempotencyKey($key) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['booking_idempotency_key']) && hash_equals($_SESSION['booking_idempotency_key'], $key);
}

// Only generate new captcha on GET requests; preserve existing answer on POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $captcha = generateCaptcha();
    $idempotency_key = generateIdempotencyKey();
} else {
    $captcha = [
        'question' => $_SESSION['captcha_question'] ?? 'What is 1 + 1?',
        'answer' => $_SESSION['captcha_answer'] ?? 2
    ];
    $idempotency_key = $_SESSION['booking_idempotency_key'] ?? '';
}

// Handle form submission
$success_message = '';
$error_message = '';

// Check if redirected after booking
if (isset($_GET['booked']) && $_GET['booked'] == 1) {
    $ref = $_GET['ref'] ?? '';
    $success_message = "Booking confirmed! Your reference number is <strong>" . htmlspecialchars($ref) . "</strong>. A confirmation email with calendar invite has been sent to your inbox. We will contact you via WhatsApp shortly. <br><br><small>🎯 You earned 10 loyalty points! Book more to earn rewards.</small>";
    
    // Check if this is a home service booking and add address info
    if (!empty($ref)) {
        $booking_stmt = $db->prepare("SELECT service_type, client_address FROM bookings WHERE booking_reference = :ref");
        $booking_stmt->execute([':ref' => $ref]);
        $booking_info = $booking_stmt->fetch();
        
        if ($booking_info && !empty($booking_info['service_type']) && $booking_info['service_type'] === 'home' && !empty($booking_info['client_address'])) {
            $success_message .= "<br><br><small>🏠 Home Service: Our barber will come to <strong>" . htmlspecialchars($booking_info['client_address']) . "</strong></small>";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clean up abandoned pending bookings older than 30 minutes
    cleanupPendingBookings();
    
    // Rate limiting for bookings
    if (!checkRateLimit('booking_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 3, 600)) {
        $error_message = 'Too many booking attempts. Please try again later.';
    } else {
    $errors = [];
    $client_name = trim($_POST['client_name'] ?? '');
    $country_code = $_POST['client_country_code'] ?? '+233';
    $client_phone_number = preg_replace('/[^0-9]/', '', $_POST['client_phone_number'] ?? '');
    $client_phone = $country_code . $client_phone_number;
    $client_email = trim($_POST['client_email'] ?? '');
    $barber_id = $_POST['barber_id'] ?? '';
    if (empty($barber_id) && count($barbers) === 1) {
        $barber_id = $barbers[0]['id'];
    }
    $service_id = $_POST['service_id'] ?? '';
    $package_id = $_POST['package_id'] ?? '';
    $booking_date = $_POST['booking_date'] ?? '';
    $booking_time = $_POST['booking_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $service_type = $_POST['service_type'] ?? 'shop';
    $client_address = trim($_POST['client_address'] ?? '');
    
    // Validate home service requirements
    if ($service_type === 'home') {
        if (empty($client_address)) {
            $errors[] = "Address is required for home service";
        }

        // Check if selected barber offers home service
        $barber_stmt = $db->prepare("SELECT offers_home_service FROM barbers WHERE id = :id");
        $barber_stmt->execute([':id' => $barber_id]);
        $barber = $barber_stmt->fetch();
        if (!$barber || !$barber['offers_home_service']) {
            $errors[] = "Selected barber does not offer home service. Please choose a different barber or select shop service.";
        }

        // Home service is only offered on configured weekdays
        if (!empty($booking_date)) {
            $status = homeServiceDayStatus($booking_date);
            if (!$status['allowed']) {
                $next = $status['next_date'];
                $msg = "Home service is only available on " . formatHomeServiceDays() . ".";
                $msg .= $next ? " The next available day is " . date('l, F j', strtotime($next)) . "." : "";
                $errors[] = $msg;
            }
        }
    }
    
    // Handle package selection
    if (!empty($package_id)) {
        $package_stmt = $db->prepare("SELECT service_ids, price FROM packages WHERE id = :id AND is_active = 1");
        $package_stmt->execute([':id' => $package_id]);
        $package = $package_stmt->fetch();
        
        if ($package) {
            $service_ids = explode(',', $package['service_ids']);
            $service_id = $service_ids[0]; // Use first service as primary
            $package_price = $package['price'];
        } else {
            $errors[] = "Invalid package selected";
        }
    }
    
    if (empty($client_name)) $errors[] = "Name is required";
    if (empty($client_phone)) $errors[] = "Phone number is required";
    if (empty($barber_id)) $errors[] = "Please select a barber";
    if (empty($service_id)) $errors[] = "Please select a service or package";
    if (empty($booking_date)) $errors[] = "Please select a date";
    if (empty($booking_time)) $errors[] = "Please select a time";
    
    if (!empty($booking_date) && strtotime($booking_date) < strtotime('today')) {
        $errors[] = "Cannot book for past dates";
    }
    
    if (!empty($booking_time)) {
        $hour = (int)explode(':', $booking_time)[0];
        if ($hour < 9 || $hour >= 19) {
            $errors[] = "Bookings only available between 9 AM and 7 PM";
        }
    }
    
    // Validate CAPTCHA
    if (empty($errors)) {
        if (empty($_POST['captcha_answer']) || !validateCaptcha($_POST['captcha_answer'])) {
            $errors[] = "Incorrect security answer. Please try again.";
        }
    }
    
    // Validate idempotency key to prevent duplicate submissions
    if (empty($errors)) {
        if (empty($_POST['idempotency_key']) || !validateIdempotencyKey($_POST['idempotency_key'])) {
            $errors[] = 'Invalid or expired form. Please refresh the page and try again.';
        }
    }
    
    // Check for booking conflicts (considering service duration)
    if (empty($errors)) {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Invalid security token. Please refresh the page.';
        } else {
        // Get service duration
        $service_stmt = $db->prepare("SELECT duration_minutes FROM services WHERE id = :id");
        $service_stmt->execute([':id' => $service_id]);
        $service = $service_stmt->fetch();
        $duration = $service ? (int)$service['duration_minutes'] : 60;
        
        // Calculate new booking start time and end time
        $new_start = DateTime::createFromFormat('H:i:s', $booking_time);
        if (!$new_start) {
            $new_start = DateTime::createFromFormat('H:i', $booking_time);
        }
        if ($new_start) {
            $new_start->setDate((int)$booking_date, (int)date('m', strtotime($booking_date)), (int)date('d', strtotime($booking_date)));
        }
        $new_end = $new_start ? clone $new_start : null;
        if ($new_end) {
            $new_end->add(new DateInterval("PT{$duration}M"));
        }
        
        // Check for overlapping bookings - only block against confirmed/completed bookings
        if ($new_start && $new_end) {
            $conflict_check = $db->prepare("
                SELECT b.id, booking_time, s.duration_minutes 
                FROM bookings b
                JOIN services s ON b.service_id = s.id
                WHERE b.barber_id = :barber_id 
                AND b.booking_date = :booking_date 
                AND b.status IN ('confirmed', 'completed')
                AND b.id != :exclude_id
            ");
            $conflict_check->execute([
                ':barber_id' => $barber_id,
                ':booking_date' => $booking_date,
                ':exclude_id' => 0
            ]);
            $conflicting_bookings = $conflict_check->fetchAll();
            
            foreach ($conflicting_bookings as $existing) {
                $existing_start = DateTime::createFromFormat('H:i:s', $existing['booking_time']);
                if (!$existing_start) {
                    $existing_start = DateTime::createFromFormat('H:i', $existing['booking_time']);
                }
                if (!$existing_start) {
                    continue;
                }
                $existing_start->setDate((int)$booking_date, (int)date('m', strtotime($booking_date)), (int)date('d', strtotime($booking_date)));
                $existing_end = clone $existing_start;
                $existing_end->add(new DateInterval("PT{$existing['duration_minutes']}M"));
                
                // Check for overlap
                if ($new_start < $existing_end && $new_end > $existing_start) {
                    $errors[] = "This time slot overlaps with another booking. Please choose a different time or barber.";
                    break;
                }
            }
        }
     } // end CSRF else
    } // end empty($errors) if - conflict check
    
    // If no errors, save to database
    if (empty($errors)) {
        try {
            // Determine price and service
            if (!empty($package_id)) {
                $service_ids = explode(',', $package['service_ids']);
                $service_id = $service_ids[0];
                $service_price = $package_price;
                $service_name = $db->prepare("SELECT name FROM services WHERE id = :id")->execute([':id' => $service_id])->fetchColumn();
            } else {
                $service_price = $db->prepare("SELECT price FROM services WHERE id = :id")->execute([':id' => $service_id])->fetchColumn();
                $service_name = $db->prepare("SELECT name FROM services WHERE id = :id")->execute([':id' => $service_id])->fetchColumn();
            }
            
            // Calculate home service fee
            $home_service_fee = ($service_type === 'home') ? getHomeServiceFee() : 0;
            $total_price = $service_price + $home_service_fee;
            
            $stmt = $db->prepare("
                INSERT INTO bookings (client_name, client_phone, client_email, barber_id, service_id, package_id, booking_date, booking_time, notes, price, service_type, client_address, home_service_fee, idempotency_key)
                VALUES (:client_name, :client_phone, :client_email, :barber_id, :service_id, :package_id, :booking_date, :booking_time, :notes, :price, :service_type, :client_address, :home_service_fee, :idempotency_key)
            ");
            
            try {
                $stmt->execute([
                    ':client_name' => $client_name,
                    ':client_phone' => $client_phone,
                    ':client_email' => $client_email,
                    ':barber_id' => $barber_id,
                    ':service_id' => $service_id,
                    ':package_id' => !empty($package_id) ? $package_id : null,
                    ':booking_date' => $booking_date,
                    ':booking_time' => $booking_time,
                    ':notes' => $notes,
                    ':price' => $total_price,
                    ':service_type' => $service_type,
                    ':client_address' => $service_type === 'home' ? $client_address : null,
                    ':home_service_fee' => $home_service_fee,
                    ':idempotency_key' => $_SESSION['booking_idempotency_key'] ?? null
                ]);
                
                $booking_id = $db->lastInsertId();
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'This time slot is already booked') !== false) {
                    $errors[] = 'This time slot was just booked by someone else. Please choose a different time.';
                } else {
                    $errors[] = 'Booking failed. Please try again.';
                }
            }
            
            // Generate and store a random booking reference
            $booking_reference = generateBookingReference();
            $ref_stmt = $db->prepare("UPDATE bookings SET booking_reference = :ref WHERE id = :id");
            $ref_stmt->execute([':ref' => $booking_reference, ':id' => $booking_id]);
            
            // Generate and store a confirmation token for secure access
            $confirmation_token = bin2hex(random_bytes(16));
            $token_stmt = $db->prepare("UPDATE bookings SET confirmation_token = :token WHERE id = :id");
            $token_stmt->execute([':token' => $confirmation_token, ':id' => $booking_id]);
            
            // Send confirmation email
            $booking = $db->prepare("
                SELECT b.*, br.name as barber_name, br.phone as barber_phone,
                       s.name as service_name, s.price as service_price, s.duration_minutes
                FROM bookings b
                JOIN barbers br ON b.barber_id = br.id
                JOIN services s ON b.service_id = s.id
                WHERE b.id = :id
            ");
            $booking->execute([':id' => $booking_id]);
            $booking_data = $booking->fetch();
            
            if ($booking_data && $booking_data['client_email']) {
                sendClientConfirmation($booking_data);
                sendCalendarInvite($booking_data);
            }
            
            // Award loyalty points (10 points per booking)
            $loyalty_stmt = $db->prepare("SELECT id, points FROM loyalty WHERE phone LIKE :phone");
            $loyalty_stmt->execute([':phone' => "%$client_phone%"]);
            $loyalty = $loyalty_stmt->fetch();
            
            $loyalty_points = 10;
            if ($loyalty) {
                $new_points = $loyalty['points'] + $loyalty_points;
                $update_stmt = $db->prepare("UPDATE loyalty SET points = :points WHERE id = :id");
                $update_stmt->execute([':points' => $new_points, ':id' => $loyalty['id']]);
            } else {
                $insert_stmt = $db->prepare("INSERT INTO loyalty (phone, points) VALUES (:phone, :points)");
                $insert_stmt->execute([':phone' => $client_phone, ':points' => $loyalty_points]);
            }
            
            // Handle payment if enabled
            $paystack_settings = getPaystackSettings();
            if ($paystack_settings['payment_enabled'] && !empty($service_price)) {
                // Store booking ID in session for payment
                $_SESSION['pending_booking_id'] = $booking_id;
                $_SESSION['pending_booking_ref'] = $booking_reference;
                $_SESSION['pending_booking_email'] = $client_email;
                $_SESSION['pending_booking_amount'] = $service_price;
                
                header("Location: index.php?booked=1&ref=" . urlencode($booking_reference) . "&payment=required");
                exit;
            }
            
            // Redirect to homepage after successful booking
            header("Location: index.php?booked=1&ref=" . urlencode($booking_reference));
            exit;
            
            } catch (PDOException $e) {
                $error_message = "Booking failed. Please try again.";
                error_log("Booking error: " . $e->getMessage());
            }
    } // end if (empty($errors)) - database save
    } // end rate limit else
}

// Surface validation errors to the user. The validation above appends to
// $errors[], but the template only renders $error_message - without this bridge
// a failed booking silently re-renders the form with no explanation.
if (!empty($errors)) {
    $error_message = implode('<br>', array_map('htmlspecialchars', $errors));
}

// Get today's date for the date input min attribute
$today = date('Y-m-d');
$max_date = date('Y-m-d', strtotime('+30 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>icut - Premium Grooming Experience</title>
    <meta name="description" content="icut Barbershop - Premium grooming experience with master barbers. Book your appointment online for haircuts, shaves, and styling. Walk-ins welcome.">
    <meta name="keywords" content="barbershop, haircut, barber, grooming, men's haircut, shave, icut, Accra, Ghana">
    <meta name="author" content="icut">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#c9a96e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="icut">
    <link rel="apple-touch-icon" href="/icut/uploads/logo/6a6fe15cf145e_1785717084.png">
    <link rel="canonical" href="<?php echo htmlspecialchars(env('SITE_URL', 'http://localhost/icut')); ?>/">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars(env('SITE_URL', 'http://localhost/icut')); ?>/">
    <meta property="og:title" content="icut - Premium Grooming Experience">
    <meta property="og:description" content="Premium grooming experience with master barbers. Book your appointment online.">
    <meta property="og:image" content="<?php echo htmlspecialchars(env('SITE_URL', 'http://localhost/icut')); ?>/<?php echo $logo ?: 'images/og-image.jpg'; ?>">
    <meta property="og:locale" content="en_GH">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo htmlspecialchars(env('SITE_URL', 'http://localhost/icut')); ?>/">
    <meta property="twitter:title" content="icut - Premium Grooming Experience">
    <meta property="twitter:description" content="Premium grooming experience with master barbers. Book your appointment online.">
    <meta property="twitter:image" content="<?php echo htmlspecialchars(env('SITE_URL', 'http://localhost/icut')); ?>/<?php echo $logo ?: 'images/og-image.jpg'; ?>">
    
    <!-- Structured Data for LocalBusiness -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        "name": "icut Barbershop",
        "description": "Premium grooming experience with master barbers",
        "image": "<?php echo htmlspecialchars(env('SITE_URL', 'http://localhost/icut')); ?>/<?php echo $logo ?: 'images/logo.png'; ?>",
        "telephone": "<?php echo htmlspecialchars($phone); ?>",
        "email": "<?php echo htmlspecialchars($email); ?>",
        "address": {
            "@type": "PostalAddress",
            "streetAddress": "<?php echo htmlspecialchars($address); ?>",
            "addressLocality": "Accra",
            "addressCountry": "GH"
        },
        "openingHours": [
            "Mo-Fr <?php echo htmlspecialchars($hours_weekday); ?>",
            "Sa <?php echo htmlspecialchars($hours_saturday); ?>",
            "Su <?php echo htmlspecialchars($hours_sunday); ?>"
        ],
        "priceRange": "₵20 - ₵200",
        "sameAs": [
            "https://wa.me/<?php echo htmlspecialchars($settings['whatsapp_number'] ?? ''); ?>"
        ]
    }
    </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="manifest" href="/icut/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            barber: {
                                950: '#0a0a0a',
                                900: '#0f0f0f',
                                800: '#1a1a1a',
                                700: '#2d2d2d',
                                600: '#404040',
                                gold: '#c9a96e',
                                'gold-light': '#d4b87a',
                                'gold-dark': '#b8934e',
                            }
                        },
                        animation: {
                            'fade-in': 'fadeIn 1s ease-in-out',
                            'slide-up': 'slideUp 0.8s ease-out',
                            'slide-down': 'slideDown 0.5s ease-out',
                            'pulse-slow': 'pulse 3s infinite',
                        },
                        keyframes: {
                            fadeIn: {
                                '0%': { opacity: '0' },
                                '100%': { opacity: '1' },
                            },
                            slideUp: {
                                '0%': { transform: 'translateY(30px)', opacity: '0' },
                                '100%': { transform: 'translateY(0)', opacity: '1' },
                            },
                            slideDown: {
                                '0%': { transform: 'translateY(-20px)', opacity: '0' },
                                '100%': { transform: 'translateY(0)', opacity: '1' },
                            }
                        }
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap');
        
        * { scroll-behavior: smooth; }
        
        .font-display { font-family: 'Playfair Display', serif; }
        .font-body { font-family: 'Inter', sans-serif; }
        
        /* Video Background */
        .video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: -2; }
        .video-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(135deg, rgba(15,15,15,0.92) 0%, rgba(15,15,15,0.85) 50%, rgba(201,169,110,0.25) 100%); z-index: -1; }
        
        /* Logo Background */
        .hero-logo-background {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: min(90vw, 800px);
            height: min(90vw, 800px);
            background-image: var(--hero-logo-url);
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.08;
            z-index: -2;
            pointer-events: none;
            animation: logoFloat 20s ease-in-out infinite;
            filter: grayscale(100%) brightness(0.6);
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translate(-50%, -50%) scale(1); }
            25% { transform: translate(-48%, -52%) scale(1.02); }
            50% { transform: translate(-52%, -48%) scale(0.98); }
            75% { transform: translate(-49%, -51%) scale(1.01); }
        }
        
        /* Light mode logo background adjustments */
        .light-mode .hero-logo-background {
            opacity: 0.12;
            filter: grayscale(100%) brightness(0.4);
        }
        
        /* Slideshow */
        .slideshow-container { position: relative; overflow: hidden; }
        .slide { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; transition: opacity 1s ease-in-out; object-fit: cover; }
        .slide.active { opacity: 1; }
        
        /* Glass morphism */
        .glass { background: rgba(45, 45, 45, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(201, 169, 110, 0.2); }
        .glass-light { background: rgba(45, 45, 45, 0.4); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border: 1px solid rgba(201, 169, 110, 0.1); }
        
        /* Service card hover */
        .service-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(201, 169, 110, 0.2); }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #1a1a1a; }
        ::-webkit-scrollbar-thumb { background: #c9a96e; border-radius: 4px; }
        
        /* Loading spinner */
        .spinner { border: 3px solid rgba(201, 169, 110, 0.1); border-top: 3px solid #c9a96e; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        /* Pulse dot */
        .pulse-dot { width: 12px; height: 12px; background: #10b981; border-radius: 50%; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
        
        /* Map Preview */
        .map-preview-wrapper { position: relative; display: inline-block; }
        .map-preview { transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .map-preview-wrapper:hover .map-preview { transform: scale(1.15); }
        .map-pulse-ring { position: absolute; inset: 0; border-radius: 50%; animation: mapPulse 2s ease-out infinite; pointer-events: none; }
        .map-pulse-ring-delayed { position: absolute; inset: 0; border-radius: 50%; animation: mapPulse 2s ease-out 1s infinite; pointer-events: none; }
        
        @keyframes mapPulse {
            0% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(2.2); opacity: 0; }
        }
        
        /* Light mode map adjustments */
        .light-mode .map-preview { border-color: #b8934e; }
        .light-mode .map-pulse-ring, .light-mode .map-pulse-ring-delayed { border-color: rgba(184, 147, 78, 0.6); }
        
        /* Gallery Lightbox */
        .lightbox { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.95); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px; }
        .lightbox.active { display: flex; }
        .lightbox-content { max-width: 90vw; max-height: 85vh; border-radius: 12px; overflow: hidden; }
        .lightbox-content img, .lightbox-content video { max-width: 90vw; max-height: 85vh; object-fit: contain; display: block; }
        .lightbox-close { position: absolute; top: 20px; right: 25px; font-size: 40px; color: #fff; cursor: pointer; z-index: 10; transition: color 0.3s; }
        .lightbox-close:hover { color: #c9a96e; }
        
        /* Gallery item cursor */
        .gallery-item { cursor: pointer; }
        
        /* Mobile nav */
        .mobile-menu { display: none; }
        .mobile-menu.open { display: block; }
        
        /* Light mode styles */
        .light-mode {
            --bg-primary: #ffffff;
            --bg-secondary: #f5f5f5;
            --bg-card: #ffffff;
            --text-primary: #1a1a1a;
            --text-secondary: #4a4a4a;
            --text-gold: #b8934e;
            --border-color: #e0e0e0;
            --nav-bg: rgba(255, 255, 255, 0.95);
        }
        
        /* Theme Color Variants */
        .theme-ocean {
            --theme-accent: #0ea5e9;
            --theme-accent-light: #0284c7;
            --theme-accent-dark: #0369a1;
            --theme-glow: rgba(14, 165, 233, 0.3);
        }
        .theme-forest {
            --theme-accent: #22c55e;
            --theme-accent-light: #16a34a;
            --theme-accent-dark: #15803d;
            --theme-glow: rgba(34, 197, 94, 0.3);
        }
        .theme-royal {
            --theme-accent: #a855f7;
            --theme-accent-light: #9333ea;
            --theme-accent-dark: #7e22ce;
            --theme-glow: rgba(168, 85, 247, 0.3);
        }
        .theme-sunset {
            --theme-accent: #f97316;
            --theme-accent-light: #ea580c;
            --theme-accent-dark: #c2410c;
            --theme-glow: rgba(249, 115, 22, 0.3);
        }
        
        /* Theme-specific overrides for light mode */
        .light-mode.theme-ocean {
            --text-gold: #0284c7;
            --bg-barber-gold: #0ea5e9;
        }
        .light-mode.theme-forest {
            --text-gold: #16a34a;
            --bg-barber-gold: #22c55e;
        }
        .light-mode.theme-royal {
            --text-gold: #9333ea;
            --bg-barber-gold: #a855f7;
        }
        .light-mode.theme-sunset {
            --text-gold: #ea580c;
            --bg-barber-gold: #f97316;
        }
        
        /* Theme accent button styles */
        .theme-ocean .bg-barber-gold,
        .theme-forest .bg-barber-gold,
        .theme-royal .bg-barber-gold,
        .theme-sunset .bg-barber-gold {
            background: var(--theme-accent) !important;
        }
        .theme-ocean .hover\:bg-barber-gold-light:hover,
        .theme-forest .hover\:bg-barber-gold-light:hover,
        .theme-royal .hover\:bg-barber-gold-light:hover,
        .theme-sunset .hover\:bg-barber-gold-light:hover {
            background: var(--theme-accent-light) !important;
        }
        .theme-ocean .text-barber-gold,
        .theme-forest .text-barber-gold,
        .theme-royal .text-barber-gold,
        .theme-sunset .text-barber-gold {
            color: var(--theme-accent) !important;
        }
        .theme-ocean .border-barber-gold,
        .theme-forest .border-barber-gold,
        .theme-royal .border-barber-gold,
        .theme-sunset .border-barber-gold {
            border-color: var(--theme-accent) !important;
        }
        
        /* Light mode text contrast fixes */
        .light-mode .text-white { color: #1a1a1a !important; }
        .light-mode .text-gray-300 { color: #4a4a4a !important; }
        .light-mode .text-gray-400 { color: #6a6a6a !important; }
        .light-mode .text-gray-500 { color: #8a8a8a !important; }
        .light-mode .bg-barber-900 { background: #ffffff !important; }
        .light-mode .bg-barber-800 { background: #f5f5f5 !important; }
        .light-mode .bg-barber-700 { background: #e8e8e8 !important; }
        .light-mode .bg-barber-600 { background: #d0d0d0 !important; }
        .light-mode body { background: #f5f5f5; color: #1a1a1a; }
        .light-mode .glass {
            background: rgba(255, 255, 255, 0.85) !important;
            border: 1px solid rgba(0, 0, 0, 0.08) !important;
        }
        .light-mode .video-overlay {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(245,245,245,0.85) 50%, rgba(0,0,0,0.05) 100%) !important;
        }
        
        /* Theme Picker */
        #themePicker {
            transform-origin: top right;
            animation: fadeInScale 0.15s ease-out;
        }
        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .theme-swatch:hover {
            transform: translateX(4px);
        }
    </style>
    
    <!-- Light mode CSS injected via JS if enabled -->
    <style id="lightModeStyles" disabled>
        .light-mode body { background: #f5f5f5; color: #1a1a1a; }
        .light-mode .bg-barber-950 { background: #f5f5f5; }
        .light-mode .bg-barber-900 { background: #ffffff; }
        .light-mode .bg-barber-800 { background: #f0f0f0; }
        .light-mode .bg-barber-700 { background: #e0e0e0; }
        .light-mode .text-white { color: #1a1a1a; }
        .light-mode .text-gray-300 { color: #5a5a5a; }
        .light-mode .text-gray-400 { color: #7a7a7a; }
        .light-mode .text-gray-500 { color: #9a9a9a; }
        .light-mode .text-barber-gold { color: #b8934e; }
        .light-mode .border-barber-700 { border-color: #e0e0e0; }
        .light-mode .glass { background: rgba(255, 255, 255, 0.7); border: 1px solid rgba(184, 147, 78, 0.2); }
        .light-mode .glass-light { background: rgba(255, 255, 255, 0.4); }
        .light-mode .video-overlay { background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(245,245,245,0.8) 50%, rgba(184,147,78,0.1) 100%); }
        .light-mode .bg-barber-gold { background: #b8934e; }
        .light-mode .hover\:bg-barber-gold-light:hover { background: #a67c3e; }
        .light-mode .text-barber-900 { color: #1a1a1a; }
        .light-mode .hero-logo-background {
            opacity: 0.12;
            filter: grayscale(100%) brightness(0.5);
        }
    </style>
</head>
<body class="bg-barber-950 font-body">
    <script>
        // Register Service Worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/icut/sw.js')
                    .then(function(registration) {
                        console.log('SW registered: ', registration);
                    })
                    .catch(function(registrationError) {
                        console.log('SW registration failed: ', registrationError);
                    });
            });
        }
        
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
            
            // Remove all theme classes
            themes.forEach(t => {
                body.classList.remove('light-mode', 'theme-' + t);
            });
            
            // Add new theme
            if (themeName === 'light') {
                body.classList.add('light-mode');
            } else if (themeName !== 'dark') {
                body.classList.add('theme-' + themeName);
            }
            
            // Update localStorage
            localStorage.setItem('theme', themeName);
            
            // Update toggle button icon
            const themeToggleIcon = document.querySelector('#themeToggle i');
            if (themeToggleIcon) {
                themeToggleIcon.className = 'fas ' + (themeIcons[themeName] || 'fa-moon');
            }
            
            // Update toggle button title
            const themeToggleBtn = document.querySelector('#themeToggle');
            if (themeToggleBtn) {
                themeToggleBtn.title = 'Current theme: ' + themeName.charAt(0).toUpperCase() + themeName.slice(1) + '. Click to change.';
            }
            
            // Update indicator color
            const indicator = document.querySelector('.theme-indicator');
            if (indicator) {
                const colors = {
                    'dark': '#b8934e',
                    'light': '#f5f5f5',
                    'ocean': '#0ea5e9',
                    'forest': '#22c55e',
                    'royal': '#a855f7',
                    'sunset': '#f97316'
                };
                indicator.style.backgroundColor = colors[themeName] || '#b8934e';
            }
            
            // Close theme picker
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
            if (picker) {
                picker.classList.toggle('hidden');
            }
        }
        
        // Close theme picker when clicking outside
        document.addEventListener('click', function(e) {
            const container = document.getElementById('themePickerContainer');
            const picker = document.getElementById('themePicker');
            if (container && picker && !container.contains(e.target)) {
                picker.classList.add('hidden');
            }
        });
        
        // Initialize theme on page load
        const savedTheme = localStorage.getItem('theme') || 'dark';
        setTheme(savedTheme);
    </script>
    

    <section class="relative min-h-screen flex items-center overflow-hidden">
        <!-- Video Background -->
        <video autoplay muted loop playsinline class="video-background">
            <source src="https://cdn.coverr.co/videos/coverr-barber-cutting-hair-4710/1080p.mp4" type="video/mp4">
        </video>
        <div class="video-overlay"></div>
        
        <!-- Logo Background -->
        <?php if ($logo): ?>
            <div class="hero-logo-background" style="--hero-logo-url: url('<?php echo htmlspecialchars($logo); ?>');"></div>
        <?php else: ?>
            <div class="hero-logo-background" style="--hero-logo-url: url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Ccircle cx=%2250%22 cy=%2250%22 r=%2240%22 fill=%22none%22 stroke=%22%23c9a96e%22 stroke-width=%222%22 opacity=%220.3%22/%3E%3Cpath d=%22M50 30 L50 70 M30 50 L70 50%22 stroke=%22%23c9a96e%22 stroke-width=%222%22 opacity=%220.3%22/%3E%3C/svg%3E');"></div>
        <?php endif; ?>
        
        <!-- Navigation -->
        <nav class="absolute top-0 left-0 right-0 z-50 animate-slide-down">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3" id="adminTrigger">
                        <div class="w-10 h-10 flex items-center justify-center cursor-pointer select-none" title="icut Logo">
                            <?php if ($logo): ?>
                                <img src="<?php echo htmlspecialchars($logo); ?>" alt="icut Logo" class="w-10 h-10 object-contain rounded-full">
                            <?php else: ?>
                                <i class="fas fa-cut text-barber-900 text-xl"></i>
                            <?php endif; ?>
                        </div>
                        <div class="select-none">
                            <h1 class="text-2xl font-display font-bold text-white">icut</h1>
                            <p class="text-barber-gold text-xs tracking-widest uppercase">Barbershop</p>
                        </div>
                    </div>
                    
                    <!-- Desktop Nav -->
                    <div class="hidden md:flex items-center space-x-8 text-white text-sm">
                        <a href="#services" class="hover:text-barber-gold transition">Services</a>
                        <a href="#barbers" class="hover:text-barber-gold transition">Barbers</a>
                        <a href="#book" class="hover:text-barber-gold transition">Book Now</a>
                        <a href="#gallery" class="hover:text-barber-gold transition">Gallery</a>
                        <a href="#reviews" class="hover:text-barber-gold transition">Reviews</a>
                        <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                            <a href="admin.php" class="text-barber-gold hover:text-barber-gold-light transition font-semibold">← ➻</a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($app_base_path . '/admin_login.php?a=' . rawurlencode(env('ADMIN_ENTRY_KEY', 'icitboss'))); ?>" class="text-barber-gold hover:text-barber-gold-light transition font-semibold">Staff Login</a>
                        <?php endif; ?>
                        <span class="text-barber-gold flex items-center space-x-2">
                            <span class="pulse-dot"></span>
                            <span>Open Today</span>
                        </span>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <button id="themeToggle" onclick="toggleTheme()" 
                                class="p-2 rounded-lg bg-barber-800 hover:bg-barber-700 text-white transition relative"
                                title="Current theme: Dark. Click to change theme.">
                            <i class="fas fa-moon"></i>
                            <span class="theme-indicator absolute -top-1 -right-1 w-3 h-3 rounded-full bg-barber-gold border-2 border-barber-900"></span>
                        </button>
                        <div class="relative" id="themePickerContainer">
                            <button onclick="toggleThemePicker()" class="p-2 rounded-lg bg-barber-800 hover:bg-barber-700 text-white transition" title="Choose theme color">
                                <i class="fas fa-palette"></i>
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
                        <a href="#book" class="hidden sm:block bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-full transition transform hover:scale-105">
                            Book Now
                        </a>
                        <!-- Mobile Hamburger -->
                        <button class="md:hidden text-white text-2xl p-2" onclick="document.getElementById('mobileMenu').classList.toggle('open')">
                            <i class="fas fa-bars"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Mobile Menu -->
                <div id="mobileMenu" class="mobile-menu mt-4 bg-barber-800 rounded-xl p-4 md:hidden">
                    <div class="flex flex-col space-y-3">
                        <a href="#services" class="text-white hover:text-barber-gold transition py-2">Services</a>
                        <a href="#barbers" class="text-white hover:text-barber-gold transition py-2">Barbers</a>
                        <a href="#book" class="text-white hover:text-barber-gold transition py-2">Book Now</a>
                        <a href="#gallery" class="text-white hover:text-barber-gold transition py-2">Gallery</a>
                        <a href="#reviews" class="text-white hover:text-barber-gold transition py-2">Reviews</a>
                        <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                            <a href="admin.php" class="text-barber-gold hover:text-barber-gold-light transition py-2 font-semibold">← Admin</a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($app_base_path . '/admin_login.php?a=' . rawurlencode(env('ADMIN_ENTRY_KEY', 'icitboss'))); ?>" class="text-barber-gold hover:text-barber-gold-light transition py-2 font-semibold">Staff Login →</a>
                        <?php endif; ?>
                        <a href="cancel_booking.php" class="text-white hover:text-barber-gold transition py-2">Cancel Booking</a>
                    </div>
                </div>
            </div>
        </nav>
        
        <!-- Hero Content -->
        <div class="relative z-10 max-w-7xl mx-auto px-4 py-20 w-full sm:mt-10">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <!-- Left Column - Text -->
                <div class="animate-fade-in">
                    <p class="text-barber-gold text-sm tracking-widest uppercase mb-4">✦ Premium Grooming</p>
                    <h1 class="text-4xl md:text-7xl font-display font-bold text-white mb-6 leading-tight">
                        Where Style
                        <span class="text-barber-gold block">Meets Precision</span>
                    </h1>
                    <p class="text-gray-400 text-lg mb-8 leading-relaxed">
                        Experience the art of traditional barbering with a modern twist. 
                        Our master barbers craft the perfect look tailored just for you.
                    </p>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-3 gap-4 md:gap-6 mb-8">
                        <div class="text-center p-4 glass rounded-xl">
                            <p class="text-2xl md:text-3xl font-bold text-barber-gold"><?php echo htmlspecialchars($happy_clients); ?></p>
                            <p class="text-gray-400 text-xs md:text-sm">Happy Clients</p>
                        </div>
                        <div class="text-center p-4 glass rounded-xl">
                            <p class="text-2xl md:text-3xl font-bold text-barber-gold"><?php echo htmlspecialchars($years_exp); ?></p>
                            <p class="text-gray-400 text-xs md:text-sm">Years Exp</p>
                        </div>
                        <div class="text-center p-4 glass rounded-xl">
                            <p class="text-2xl md:text-3xl font-bold text-barber-gold"><?php echo htmlspecialchars($rating); ?></p>
                            <p class="text-gray-400 text-xs md:text-sm">Rating</p>
                        </div>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="#book" class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-8 py-4 rounded-full text-center transition transform hover:scale-105">
                            Book Appointment
                        </a>
                        <a href="#services" class="border border-barber-gold text-barber-gold hover:bg-barber-gold/10 px-8 py-4 rounded-full text-center transition">
                            View Services
                        </a>
                    </div>
                </div>
                
                <!-- Right Column - Quick Booking Preview -->
                <div class="hidden lg:block animate-slide-up">
                    <div class="glass rounded-3xl p-8 transform rotate-1 hover:rotate-0 transition">
                        <div class="flex items-center space-x-3 mb-6">
                            <div class="w-12 h-12 bg-barber-gold rounded-full flex items-center justify-center">
                                <i class="fas fa-calendar-check text-barber-900 text-xl"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold">Quick Booking</p>
                                <p class="text-gray-400 text-sm">Takes less than 60 seconds</p>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center text-gray-300">
                                <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                Choose your service
                            </div>
                            <div class="flex items-center text-gray-300">
                                <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                Select your barber
                            </div>
                            <div class="flex items-center text-gray-300">
                                <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                Pick date & time
                            </div>
                            <div class="flex items-center text-gray-300">
                                <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                Get WhatsApp confirmation
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Scroll Indicator -->
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
            <a href="#services" class="text-barber-gold">
                <i class="fas fa-chevron-down text-2xl"></i>
            </a>
        </div>
    </section>
    
    <!-- ============ SERVICES SECTION ============ -->
    <section id="services" class="relative py-20 bg-barber-900">
        <div class="max-w-7xl mx-auto px-4">
            <!-- Section Header -->
            <div class="text-center mb-16">
                <p class="text-barber-gold text-sm tracking-widest uppercase mb-2">Our Services</p>
                <h2 class="text-3xl md:text-5xl font-display font-bold text-white mb-4">
                    Premium Grooming
                    <span class="text-barber-gold">Packages</span>
                </h2>
                <p class="text-gray-400 max-w-2xl mx-auto">
                    Our services are designed to make you look and feel your best.
                </p>
            </div>
            
            <!-- Image/Video Slideshow using gallery items -->
            <div class="slideshow-container h-[300px] md:h-[450px] rounded-3xl overflow-hidden shadow-2xl mb-12">
                    <?php if (!empty($gallery_items)): 
                        $slide_images = array_filter($gallery_items, function($item) { return $item['media_type'] === 'image'; });
                        $slide_images = array_values($slide_images);
                        if (!empty($slide_images)):
                            foreach ($slide_images as $index => $item): ?>
                                <img src="<?php echo htmlspecialchars($item['file_path']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['title']); ?>"
                                     class="slide <?php echo $index === 0 ? 'active' : ''; ?>">
                            <?php endforeach; ?>
                            
                            <div class="absolute bottom-4 left-1/2 transform -translate-x-1/2 flex space-x-2 z-10">
                                <?php for ($i = 0; $i < count($slide_images); $i++): ?>
                                    <button onclick="currentSlide(<?php echo $i; ?>)" 
                                            class="w-3 h-3 rounded-full bg-white/50 hover:bg-barber-gold transition slide-dot"
                                            data-index="<?php echo $i; ?>"></button>
                                <?php endfor; ?>
                            </div>
                            
                            <button onclick="changeSlide(-1)" class="absolute left-4 top-1/2 transform -translate-y-1/2 bg-black/50 text-white w-10 h-10 rounded-full hover:bg-barber-gold transition z-10">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button onclick="changeSlide(1)" class="absolute right-4 top-1/2 transform -translate-y-1/2 bg-black/50 text-white w-10 h-10 rounded-full hover:bg-barber-gold transition z-10">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-gray-500">
                                <p>No gallery images yet</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-gray-500">
                            <p>No gallery images yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            
            <!-- Services Grid - Cards with image on top, details below -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($services as $service): ?>
                    <div class="service-card glass-light rounded-2xl overflow-hidden transition duration-300 cursor-pointer hover:border-barber-gold hover:transform hover:-translate-y-2 group">
                        <!-- Image on top -->
                        <div class="relative h-48 overflow-hidden bg-barber-800">
                            <?php if ($service['image']): ?>
                                <img src="<?php echo htmlspecialchars($service['image']); ?>" 
                                     alt="<?php echo htmlspecialchars($service['name']); ?>"
                                     class="w-full h-full object-contain group-hover:scale-110 transition duration-500">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fas fa-cut text-barber-gold text-5xl opacity-50"></i>
                                </div>
                            <?php endif; ?>
                            <!-- Gold overlay on hover -->
                            <div class="absolute inset-0 bg-barber-gold/10 opacity-0 group-hover:opacity-100 transition duration-300"></div>
                        </div>
                        
                        <!-- Details below image -->
                        <div class="p-6">
                            <h3 class="text-white font-semibold text-lg group-hover:text-barber-gold transition"><?php echo htmlspecialchars($service['name']); ?></h3>
                            <p class="text-gray-400 text-sm mt-2"><?php echo htmlspecialchars($service['description']); ?></p>
                            <div class="flex items-center justify-between mt-4 pt-4 border-t border-barber-700">
                                <span class="text-barber-gold font-bold text-xl">₵<?php echo number_format($service['price'], 2); ?></span>
                                <span class="text-gray-500 text-sm flex items-center">
                                    <i class="far fa-clock mr-1"></i><?php echo $service['duration_minutes']; ?> min
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    
    <!-- ============ BARBERS SECTION ============ -->
    <section id="barbers" class="py-20 bg-barber-950">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16">
                <p class="text-barber-gold text-sm tracking-widest uppercase mb-2">Meet The Team</p>
                <h2 class="text-3xl md:text-5xl font-display font-bold text-white mb-4">
                    Master <span class="text-barber-gold">Barbers</span>
                </h2>
                <p class="text-gray-400 max-w-2xl mx-auto">
                    Our skilled barbers bring years of experience and passion to every cut.
                </p>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8">
                <?php foreach ($barbers as $index => $barber): 
                    $barber_images = [
                        'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400',
                        'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=400',
                        'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=400'
                    ];
                    $image = $barber['image'] ?? ($barber_images[$index] ?? $barber_images[0]);
                ?>
                    <div class="group glass-light rounded-2xl overflow-hidden transition duration-300 hover:transform hover:-translate-y-2">
                        <div class="relative h-64 overflow-hidden">
                            <img src="<?php echo htmlspecialchars($image); ?>" 
                                 alt="<?php echo htmlspecialchars($barber['name']); ?>"
                                 class="w-full h-full object-contain group-hover:scale-110 transition duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-barber-900 to-transparent"></div>
                        </div>
                        <div class="p-6 text-center">
                            <h3 class="text-white font-semibold text-lg"><?php echo htmlspecialchars($barber['name']); ?></h3>
                            <p class="text-barber-gold text-sm"><?php echo htmlspecialchars($barber['specialization']); ?></p>
                            <div class="flex justify-center space-x-3 mt-4">
                                <span class="text-yellow-400"><i class="fas fa-star"></i></span>
                                <span class="text-yellow-400"><i class="fas fa-star"></i></span>
                                <span class="text-yellow-400"><i class="fas fa-star"></i></span>
                                <span class="text-yellow-400"><i class="fas fa-star"></i></span>
                                <span class="text-yellow-400"><i class="fas fa-star-half-alt"></i></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    
    <!-- ============ BOOKING FORM SECTION ============ -->
    <section id="book" class="py-20 bg-barber-900">
        <div class="max-w-4xl mx-auto px-4">
            <div class="text-center mb-12">
                <p class="text-barber-gold text-sm tracking-widest uppercase mb-2">Reserve Your Spot</p>
                <h2 class="text-3xl md:text-5xl font-display font-bold text-white mb-4">
                    Book Your <span class="text-barber-gold">Appointment</span>
                </h2>
                <p class="text-gray-400">Fill in the form below and we'll confirm your booking via WhatsApp.</p>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="mb-8 bg-green-900/50 border border-green-700 text-green-300 px-6 py-4 rounded-lg flex items-start animate-slide-down">
                    <i class="fas fa-check-circle text-xl mr-3 mt-1"></i>
                    <div class="flex-1">
                        <span><?php echo $success_message; ?></span>
                        <?php if (isset($_GET['booked']) && $_GET['booked'] == 1): ?>
                            <button onclick="requestNotificationPermission()" class="mt-3 bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-4 py-2 rounded-lg text-sm transition">
                                <i class="fas fa-bell mr-2"></i>Enable Reminder Notifications
                            </button>
                        <?php endif; ?>
                        <?php if (isset($_GET['payment']) && $_GET['payment'] === 'required' && isset($_SESSION['pending_booking_id'])): 
                            $pending_ref = $_SESSION['pending_booking_ref'] ?? '';
                            $pending_email = $_SESSION['pending_booking_email'] ?? '';
                            $pending_amount = $_SESSION['pending_booking_amount'] ?? 0;
                            $paystack_settings = getPaystackSettings();
                        ?>
                            <div class="mt-4 bg-barber-800/80 border border-barber-700 rounded-xl p-5">
                                <p class="text-white font-semibold mb-2">💳 Complete Your Payment</p>
                                <p class="text-gray-300 text-sm mb-1">Booking Reference: <span class="text-barber-gold font-mono"><?php echo htmlspecialchars($pending_ref); ?></span></p>
                                <p class="text-gray-300 text-sm mb-1">Amount to Pay: <span class="text-barber-gold font-bold text-lg">₵<?php echo number_format($pending_amount, 2); ?></span></p>
                                <p class="text-gray-400 text-xs mb-4">Secure payment powered by Paystack</p>
                                <button onclick="initializePayment()" class="w-full bg-green-600 hover:bg-green-500 text-white font-bold py-3 px-6 rounded-lg transition">
                                    <i class="fas fa-lock mr-2"></i>Pay Now with Paystack
                                </button>
                                <p id="payment-error" class="text-red-400 text-xs mt-2 hidden"></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-8 bg-red-900/50 border border-red-700 text-red-300 px-6 py-4 rounded-lg animate-slide-down">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Booking Form -->
            <div class="glass rounded-3xl p-6 md:p-12 shadow-2xl">
                <form id="booking-form" method="POST" action="#book" class="space-y-8">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars($idempotency_key); ?>">
                    <!-- Personal Information -->
                    <div>
                        <h3 class="text-xl font-display font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-user-circle text-barber-gold mr-3 text-2xl"></i>
                            Personal Information
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Full Name *</label>
                                <input type="text" name="client_name" required 
                                       value="<?php echo htmlspecialchars($_POST['client_name'] ?? ''); ?>"
                                       class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                                       placeholder="Enter your full name">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Phone Number *</label>
                                <div class="flex space-x-2">
                                    <select name="client_country_code" class="bg-barber-800 border border-barber-600 rounded-xl px-3 py-3 text-white focus:outline-none focus:border-barber-gold transition w-28">
                                        <option value="+233">🇬🇭 +233</option>
                                        <option value="+1">🇺🇸 +1</option>
                                        <option value="+44">🇬🇧 +44</option>
                                        <option value="+234">🇳🇬 +234</option>
                                        <option value="+27">🇿🇦 +27</option>
                                        <option value="+254">🇰🇪 +254</option>
                                        <option value="+91">🇮🇳 +91</option>
                                        <option value="+86">🇨🇳 +86</option>
                                        <option value="+81">🇯🇵 +81</option>
                                        <option value="+82">🇰🇷 +82</option>
                                        <option value="+61">🇦🇺 +61</option>
                                        <option value="+65">🇸🇬 +65</option>
                                        <option value="+60">🇲🇾 +60</option>
                                        <option value="+971">🇦🇪 +971</option>
                                        <option value="+20">🇪🇬 +20</option>
                                        <option value="+212">🇲🇦 +212</option>
                                        <option value="+63">🇵🇭 +63</option>
                                        <option value="+62">🇮🇩 +62</option>
                                        <option value="+66">🇹🇭 +66</option>
                                        <option value="+49">🇩🇪 +49</option>
                                        <option value="+33">🇫🇷 +33</option>
                                        <option value="+39">🇮🇹 +39</option>
                                        <option value="+34">🇪🇸 +34</option>
                                        <option value="+31">🇳🇱 +31</option>
                                        <option value="+46">🇸🇪 +46</option>
                                        <option value="+47">🇳🇴 +47</option>
                                        <option value="+45">🇩🇰 +45</option>
                                        <option value="+353">🇮🇪 +353</option>
                                        <option value="+64">🇳🇿 +64</option>
                                        <option value="+358">🇫🇮 +358</option>
                                        <option value="+48">🇵🇱 +48</option>
                                        <option value="+52">🇲🇽 +52</option>
                                        <option value="+55">🇧🇷 +55</option>
                                        <option value="+54">🇦🇷 +54</option>
                                        <option value="+56">🇨🇱 +56</option>
                                        <option value="+57">🇨🇴 +57</option>
                                        <option value="+58">🇻🇪 +58</option>
                                        <option value="+351">🇵🇹 +351</option>
                                        <option value="+41">🇨🇭 +41</option>
                                        <option value="+43">🇦🇹 +43</option>
                                        <option value="+30">🇬🇷 +30</option>
                                        <option value="+90">🇹🇷 +90</option>
                                        <option value="+7">🇷🇺 +7</option>
                                        <option value="+84">🇻🇳 +84</option>
                                        <option value="+66">🇹🇭 +66</option>
                                        <option value="+95">🇲🇲 +95</option>
                                        <option value="+880">🇧🇩 +880</option>
                                        <option value="+92">🇵🇰 +92</option>
                                        <option value="+94">🇱🇰 +94</option>
                                    </select>
                                    <input type="tel" name="client_phone_number" required 
                                           value="<?php echo htmlspecialchars($_POST['client_phone_number'] ?? ''); ?>"
                                           class="flex-1 bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                                           placeholder="241 234 567">
                                </div>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-gray-300 text-sm mb-2">Email (Optional)</label>
                                <input type="email" name="client_email" 
                                       value="<?php echo htmlspecialchars($_POST['client_email'] ?? ''); ?>"
                                       class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                                       placeholder="your@email.com">
                            </div>
                        </div>
                    </div>

                    <!-- Service Type Selection -->
                    <div>
                        <h3 class="text-xl font-display font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-map-marker-alt text-barber-gold mr-3 text-2xl"></i>
                            Service Type
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="service-card relative flex items-start p-5 bg-barber-800/50 rounded-xl border-2 border-barber-700 cursor-pointer hover:border-barber-gold transition duration-300 group">
                                <input type="radio" name="service_type" value="shop"
                                       class="mt-1 text-barber-gold focus:ring-barber-gold"
                                       <?php echo (!isset($_POST['service_type']) || $_POST['service_type'] == 'shop') ? 'checked' : ''; ?>
                                       onchange="toggleAddressField(false)">
                                <div class="ml-4 flex-1">
                                    <div class="text-white font-semibold group-hover:text-barber-gold transition">
                                        <i class="fas fa-store mr-2"></i>Shop Visit
                                    </div>
                                    <div class="text-gray-400 text-sm mt-1">Visit our barbershop</div>
                                </div>
                            </label>
                            <label class="service-card relative flex items-start p-5 bg-barber-800/50 rounded-xl border-2 border-barber-700 cursor-pointer hover:border-barber-gold transition duration-300 group">
                                <input type="radio" name="service_type" value="home"
                                       class="mt-1 text-barber-gold focus:ring-barber-gold"
                                       <?php echo (isset($_POST['service_type']) && $_POST['service_type'] == 'home') ? 'checked' : ''; ?>
                                       onchange="toggleAddressField(true)">
                                <div class="ml-4 flex-1">
                                    <div class="text-white font-semibold group-hover:text-barber-gold transition">
                                        <i class="fas fa-home mr-2"></i>Home Service
                                    </div>
                                    <div class="text-gray-400 text-sm mt-1">We come to you (+₵<?php echo number_format(getHomeServiceFee(), 2); ?>)</div>
                                </div>
                            </label>
                        </div>
                        <p class="text-gray-500 text-xs mt-3">
                            <i class="fas fa-info-circle mr-1"></i>
                            Home service is available on <strong class="text-barber-gold"><?php echo htmlspecialchars(formatHomeServiceDays()); ?></strong>.
                        </p>
                    </div>

                    <!-- Address Field (shown only for home service) -->
                    <div id="addressField" class="<?php echo (isset($_POST['service_type']) && $_POST['service_type'] == 'home') ? '' : 'hidden'; ?>">
                        <h3 class="text-xl font-display font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-map-pin text-barber-gold mr-3 text-2xl"></i>
                            Your Address
                        </h3>
                        <div>
                            <label class="block text-gray-300 text-sm mb-2">Full Address *</label>
                            <textarea name="client_address" rows="3"
                                      class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                                      placeholder="Enter your complete address including landmark"><?php echo htmlspecialchars($_POST['client_address'] ?? ''); ?></textarea>
                            <p class="text-gray-500 text-xs mt-2">Our barber will need your exact location for home service</p>
                        </div>
                    </div>

                    <!-- Package Selection -->
                    <div>
                        <h3 class="text-xl font-display font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-box text-barber-gold mr-3 text-2xl"></i>
                            Select Package (Optional)
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="service-card relative flex items-start p-5 bg-barber-800/50 rounded-xl border-2 border-barber-700 cursor-pointer hover:border-barber-gold transition duration-300 group">
                                <input type="radio" name="package_id" value="" 
                                       class="mt-1 text-barber-gold focus:ring-barber-gold"
                                       <?php echo (!isset($_POST['package_id']) || $_POST['package_id'] == '') ? 'checked' : ''; ?>
                                       onchange="clearServiceSelection()">
                                <div class="ml-4 flex-1">
                                    <div class="text-white font-semibold group-hover:text-barber-gold transition">
                                        Individual Service
                                    </div>
                                    <div class="text-gray-400 text-sm mt-1">Choose services separately below</div>
                                </div>
                            </label>
                            <?php foreach ($packages as $package): ?>
                                <label class="service-card relative flex items-start p-5 bg-barber-800/50 rounded-xl border-2 border-barber-700 cursor-pointer hover:border-barber-gold transition duration-300 group">
                                    <input type="radio" name="package_id" value="<?php echo $package['id']; ?>" 
                                           class="mt-1 text-barber-gold focus:ring-barber-gold"
                                           <?php echo (isset($_POST['package_id']) && $_POST['package_id'] == $package['id']) ? 'checked' : ''; ?>
                                           onchange="selectPackage(<?php echo $package['id']; ?>, '<?php echo addslashes($package['name']); ?>')">
                                    <div class="ml-4 flex-1">
                                        <div class="text-white font-semibold group-hover:text-barber-gold transition">
                                            <?php echo htmlspecialchars($package['name']); ?>
                                        </div>
                                        <div class="text-gray-400 text-sm mt-1"><?php echo htmlspecialchars($package['description']); ?></div>
                                        <div class="flex justify-between items-center mt-3">
                                            <span class="text-barber-gold font-bold text-lg">₵<?php echo number_format($package['price'], 2); ?></span>
                                            <span class="text-gray-500 text-sm">Bundle Deal</span>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Service Selection -->
                    <div>
                        <h3 class="text-xl font-display font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-cut text-barber-gold mr-3 text-2xl"></i>
                            Select Service
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($services as $service): ?>
                                <label class="service-card relative flex items-start p-5 bg-barber-800/50 rounded-xl border-2 border-barber-700 cursor-pointer hover:border-barber-gold transition duration-300 group">
                                    <input type="radio" name="service_id" value="<?php echo $service['id']; ?>" 
                                           class="mt-1 text-barber-gold focus:ring-barber-gold"
                                           <?php echo (isset($_POST['service_id']) && $_POST['service_id'] == $service['id']) ? 'checked' : ''; ?>>
                                    <div class="ml-4 flex-1">
                                        <div class="text-white font-semibold group-hover:text-barber-gold transition">
                                            <?php echo htmlspecialchars($service['name']); ?>
                                        </div>
                                        <div class="text-gray-400 text-sm mt-1"><?php echo htmlspecialchars($service['description']); ?></div>
                                         <div class="flex justify-between items-center mt-3">
                                             <span class="text-barber-gold font-bold text-lg">₵<?php echo number_format($service['price'], 2); ?></span>
                                             <span class="text-gray-500 text-sm">
                                                 <i class="far fa-clock mr-1"></i><?php echo $service['duration_minutes']; ?> min
                                             </span>
                                         </div>
                                     </div>
                                 </label>
                             <?php endforeach; ?>
                         </div>
                     </div>

                      <!-- Barber Selection -->
                     <div>
                         <h3 class="text-xl font-display font-semibold text-white mb-6 flex items-center">
                             <i class="fas fa-user-tie text-barber-gold mr-3 text-2xl"></i>
                             Barber
                         </h3>
                         <?php if (count($barbers) === 1): ?>
                             <div class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white flex items-center">
                                 <i class="fas fa-user-circle text-barber-gold mr-3 text-xl"></i>
                                 <span><?php echo htmlspecialchars($barbers[0]['name']); ?></span>
                             </div>
                             <input type="hidden" name="barber_id" value="<?php echo $barbers[0]['id']; ?>">
                         <?php else: ?>
                         <select name="barber_id" required 
                                 class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-barber-gold transition">
                             <option value="">Select your preferred barber...</option>
                             <?php foreach ($barbers as $barber): ?>
                                 <option value="<?php echo $barber['id']; ?>" 
                                         <?php echo (isset($_POST['barber_id']) && $_POST['barber_id'] == $barber['id']) ? 'selected' : ''; ?>>
                                     <?php echo htmlspecialchars($barber['name']); ?>
                                 </option>
                             <?php endforeach; ?>
                         </select>
                         <?php endif; ?>
                     </div>

                    <!-- Date & Time -->
                    <div>
                        <h3 class="text-xl font-display font-semibold text-white mb-6 flex items-center">
                            <i class="fas fa-calendar-alt text-barber-gold mr-3 text-2xl"></i>
                            Date & Time
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Preferred Date *</label>
                                <input type="date" name="booking_date" required 
                                       min="<?php echo $today; ?>" 
                                       max="<?php echo $max_date; ?>"
                                       onchange="validateHomeServiceDate()"
                                       value="<?php echo htmlspecialchars($_POST['booking_date'] ?? ''); ?>"
                                       class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-barber-gold transition [color-scheme:dark]">
                                <p id="homeServiceDayHint" class="text-red-400 text-xs mt-1"></p>
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Preferred Time *</label>
                                <select name="booking_time" required 
                                        class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-barber-gold transition">
                                    <option value="">Select a time...</option>
                                    <?php
                                    for ($hour = 9; $hour < 19; $hour++) {
                                        for ($minute = 0; $minute < 60; $minute += 30) {
                                            $time = sprintf("%02d:%02d", $hour, $minute);
                                            $display = date("g:i A", strtotime($time));
                                            $selected = (isset($_POST['booking_time']) && $_POST['booking_time'] === $time) ? 'selected' : '';
                                            echo "<option value=\"$time\" $selected>$display</option>";
                                        }
                                    }
                                    ?>
                                </select>
                                <div id="end-time-display" class="hidden text-barber-gold text-sm mt-2"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">
                            <i class="fas fa-pencil-alt text-barber-gold mr-2"></i>Special Requests (Optional)
                        </label>
                        <textarea name="notes" rows="3" 
                                  class="w-full bg-barber-800 border border-barber-600 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                                  placeholder="Any specific requests or preferences..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>

                    <!-- CAPTCHA -->
                    <div class="bg-barber-800/50 rounded-xl p-4 border border-barber-700">
                        <label class="block text-gray-300 text-sm mb-2">
                            <i class="fas fa-shield-alt text-barber-gold mr-2"></i>Security Check
                        </label>
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                            <div class="bg-barber-700 rounded-lg px-4 py-3 text-white font-mono text-lg tracking-wider">
                                <?php echo $captcha['question']; ?>
                            </div>
                            <input type="number" name="captcha_answer" required 
                                   class="w-full sm:w-auto bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white text-center text-lg"
                                   placeholder="Your answer" autocomplete="off">
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="submit" 
                            class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-4 px-6 rounded-xl transition duration-300 transform hover:scale-[1.02] text-lg">
                        <i class="fas fa-calendar-check mr-2"></i>
                        Confirm Booking
                    </button>
                </form>
            </div>
        </div>
    </section>
    
    <!-- ============ GALLERY SECTION ============ -->
    <section id="gallery" class="py-20 bg-barber-950">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16">
                <p class="text-barber-gold text-sm tracking-widest uppercase mb-2">Our Work</p>
                <h2 class="text-3xl md:text-5xl font-display font-bold text-white mb-4">
                    Style <span class="text-barber-gold">Gallery</span>
                </h2>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <?php if (!empty($gallery_items)): ?>
                    <?php foreach ($gallery_items as $item): ?>
                                                <div class="relative group overflow-hidden rounded-2xl gallery-item">
                            <?php if ($item['media_type'] === 'video'): ?>
                                <video src="<?php echo htmlspecialchars($item['file_path']); ?>" 
                                       class="w-full h-48 md:h-64 object-contain group-hover:scale-110 transition duration-500"
                                       muted loop playsinline></video>
                                <div class="absolute top-3 right-3 bg-black/70 text-white text-xs px-2 py-1 rounded-full">
                                    <i class="fas fa-video mr-1"></i>Video
                                </div>
                            <?php else: ?>
                                <img src="<?php echo htmlspecialchars($item['file_path']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['title']); ?>" 
                                     class="w-full h-48 md:h-64 object-contain group-hover:scale-110 transition duration-500">
                            <?php endif; ?>
                            
                            <!-- Center button + details that show on hover -->
                            <div class="absolute inset-0 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition duration-300">
                                <button onclick="openLightbox('<?php echo htmlspecialchars($item['file_path']); ?>', '<?php echo $item['media_type']; ?>', '<?php echo htmlspecialchars($item['title']); ?>')"
                                        class="bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold w-16 h-16 rounded-full flex items-center justify-center text-2xl transition transform hover:scale-110 shadow-lg mb-3">
                                    <?php if ($item['media_type'] === 'video'): ?>
                                        <i class="fas fa-play"></i>
                                    <?php else: ?>
                                        <i class="fas fa-expand"></i>
                                    <?php endif; ?>
                                </button>
                                <div class="bg-barber-900/80 backdrop-blur-sm rounded-xl px-4 py-3 text-center border border-barber-700">
                                    <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars($item['title']); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-2 md:col-span-3 text-center py-16">
                        <i class="fas fa-images text-6xl text-gray-700 mb-4"></i>
                        <p class="text-gray-500">No gallery items yet. Check back soon!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
    <!-- ============ REVIEWS SECTION ============ -->
    <section id="reviews" class="py-20 bg-barber-950">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16">
                <p class="text-barber-gold text-sm tracking-widest uppercase mb-2">Testimonials</p>
                <h2 class="text-3xl md:text-5xl font-display font-bold text-white mb-4">
                    What Our Clients <span class="text-barber-gold">Say</span>
                </h2>
                <p class="text-gray-400 max-w-2xl mx-auto">
                    Don't just take our word for it - hear from our satisfied clients.
                </p>
            </div>
            
            <?php if (!empty($reviews)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                    <?php foreach ($reviews as $review): ?>
                        <div class="glass-light rounded-2xl p-6 transition duration-300">
                            <div class="flex items-center space-x-1 mb-3">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star text-<?php echo $i <= $review['rating'] ? 'yellow' : 'gray' ?>-400"></i>
                                <?php endfor; ?>
                            </div>
                            <p class="text-gray-300 text-sm mb-4"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                            <div class="flex justify-between items-center">
                                <p class="text-white font-semibold">- <?php echo htmlspecialchars($review['client_name']); ?></p>
                                <?php if ($review['service_name']): ?>
                                    <span class="text-gray-500 text-xs"><?php echo htmlspecialchars($review['service_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <p class="text-gray-400 mb-6">No reviews yet. Be the first to share your experience!</p>
                </div>
            <?php endif; ?>
            
            <!-- Add Review Form -->
            <div class="bg-barber-800 rounded-2xl p-8 border border-barber-700">
                <h3 class="text-2xl font-display font-bold text-white mb-6 text-center">Leave a Review</h3>
                <form method="POST" action="upload_handler.php" class="space-y-6">
                    <input type="hidden" name="action" value="add_review">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-gray-300 text-sm mb-2">Your Name *</label>
                            <input type="text" name="client_name" required 
                                   class="w-full bg-barber-700 border border-barber-600 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                                   placeholder="Enter your name">
                        </div>
                        <div>
                            <label class="block text-gray-300 text-sm mb-2">Service *</label>
                            <select name="service_name" required 
                                    class="w-full bg-barber-700 border border-barber-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-barber-gold transition">
                                <option value="">Select a service</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service['name']); ?>"><?php echo htmlspecialchars($service['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Rating *</label>
                        <div class="flex space-x-2">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="rating" value="<?php echo $i; ?>" id="star<?php echo $i; ?>" class="hidden">
                                <label for="star<?php echo $i; ?>" class="text-2xl cursor-pointer text-gray-600 hover:text-yellow-400">
                                    <i class="far fa-star"></i>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Your Review</label>
                        <textarea name="comment" rows="4" 
                                  class="w-full bg-barber-700 border border-barber-600 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                                  placeholder="Tell us about your experience..."></textarea>
                    </div>
                    <!-- CAPTCHA -->
                    <div class="bg-barber-800/50 rounded-xl p-4 border border-barber-700">
                        <label class="block text-gray-300 text-sm mb-2">
                            <i class="fas fa-shield-alt text-barber-gold mr-2"></i>Security Check: <?php echo $captcha['question']; ?>
                        </label>
                        <input type="number" name="review_captcha" required 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white"
                               placeholder="Your answer" autocomplete="off">
                    </div>
                    <button type="submit" 
                            class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-4 px-6 rounded-xl transition duration-300 transform hover:scale-[1.02] text-lg">
                        Submit Review
                    </button>
                </form>
            </div>
        </div>
    </section>
    
    <!-- ============ FOOTER ============ -->
    <footer class="bg-barber-900 border-t border-barber-700 py-12">
        <div class="max-w-7xl mx-auto px-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="w-10 h-10 bg-barber-gold rounded-full flex items-center justify-center">
                            <i class="fas fa-cut text-barber-900"></i>
                        </div>
                        <span class="text-xl font-display font-bold text-white">icut</span>
                    </div>
                    <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($footer_about); ?></p>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Quick Links</h4>
                    <ul class="space-y-2 text-gray-400 text-sm">
                        <li><a href="#services" class="hover:text-barber-gold transition">Services</a></li>
                        <li><a href="#barbers" class="hover:text-barber-gold transition">Barbers</a></li>
                        <li><a href="#book" class="hover:text-barber-gold transition">Book Now</a></li>
                        <li><a href="#gallery" class="hover:text-barber-gold transition">Gallery</a></li>
                        <li><a href="#reviews" class="hover:text-barber-gold transition">Reviews</a></li>
                        <li><a href="cancel_booking.php" class="hover:text-barber-gold transition">Cancel Booking</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Contact</h4>
                    <ul class="space-y-2 text-gray-400 text-sm">
                        <li><i class="fas fa-map-marker-alt mr-2 text-barber-gold"></i><?php echo htmlspecialchars($address); ?></li>
                        <li><i class="fas fa-phone mr-2 text-barber-gold"></i><?php echo htmlspecialchars($phone); ?></li>
                        <li><i class="fas fa-envelope mr-2 text-barber-gold"></i><?php echo htmlspecialchars($email); ?></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Hours</h4>
                    <ul class="space-y-2 text-gray-400 text-sm">
                        <li><?php echo htmlspecialchars($hours_weekday); ?></li>
                        <li><?php echo htmlspecialchars($hours_saturday); ?></li>
                        <li><?php echo htmlspecialchars($hours_sunday); ?></li>
                    </ul>
                </div>
            </div>
            <?php if ($tiktok_url !== '' || $instagram_url !== '' || $x_url !== ''): ?>
                <div class="mt-8 flex items-center justify-center space-x-4">
                    <?php if ($tiktok_url !== ''): ?>
                        <a href="<?php echo htmlspecialchars($tiktok_url); ?>" target="_blank" rel="noopener" aria-label="TikTok" class="w-10 h-10 rounded-full bg-barber-700 hover:bg-barber-gold flex items-center justify-center transition group">
                            <i class="fab fa-tiktok text-gray-300 group-hover:text-barber-900"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($instagram_url !== ''): ?>
                        <a href="<?php echo htmlspecialchars($instagram_url); ?>" target="_blank" rel="noopener" aria-label="Instagram" class="w-10 h-10 rounded-full bg-barber-700 hover:bg-barber-gold flex items-center justify-center transition group">
                            <i class="fab fa-instagram text-gray-300 group-hover:text-barber-900"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($x_url !== ''): ?>
                        <a href="<?php echo htmlspecialchars($x_url); ?>" target="_blank" rel="noopener" aria-label="X (Twitter)" class="w-10 h-10 rounded-full bg-barber-700 hover:bg-barber-gold flex items-center justify-center transition group">
                            <i class="fab fa-twitter text-gray-300 group-hover:text-barber-900"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($map_embed_url)): ?>
                <div class="mt-8 flex flex-col items-center">
                    <h4 class="text-white font-semibold mb-4 text-center">Find Us</h4>
                    
                    <!-- Tiny blinking map preview -->
                    <div class="map-preview-wrapper relative group cursor-pointer" onclick="document.getElementById('mapExpanded').classList.remove('hidden')">
                        <div class="map-preview w-16 h-16 md:w-20 md:h-20 rounded-full overflow-hidden border-2 border-barber-gold shadow-lg shadow-barber-gold/30">
                            <iframe src="<?php echo htmlspecialchars($map_embed_url); ?>" width="100%" height="100%" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map"></iframe>
                        </div>
                        <div class="map-pulse-ring absolute inset-0 rounded-full border-2 border-barber-gold/60"></div>
                        <div class="map-pulse-ring-delayed absolute inset-0 rounded-full border-2 border-barber-gold/40"></div>
                        <div class="absolute -bottom-6 left-1/2 transform -translate-x-1/2 bg-barber-gold text-barber-900 text-xs font-bold px-3 py-1 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity">
                            Click to expand
                        </div>
                    </div>
                    
                    <!-- Expanded map modal -->
                    <div id="mapExpanded" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" onclick="if(event.target===this)document.getElementById('mapExpanded').classList.add('hidden')">
                        <div class="bg-barber-800 rounded-2xl p-4 md:p-6 max-w-5xl w-full border border-barber-700 shadow-2xl">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-xl font-bold text-white">📍 Find Us</h3>
                                <button onclick="document.getElementById('mapExpanded').classList.add('hidden')" class="text-gray-400 hover:text-white text-3xl leading-none">&times;</button>
                            </div>
                            <div class="rounded-xl overflow-hidden border border-barber-700" style="height: 60vh; min-height: 300px;">
                                <iframe src="<?php echo htmlspecialchars($map_embed_url); ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                            </div>
                            <div class="text-center mt-4">
                                <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode($address); ?>" target="_blank" class="inline-block bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold px-6 py-3 rounded-lg text-sm transition">
                                    🗺️ Get Directions
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="border-t border-barber-700 mt-8 pt-8 text-center text-gray-500 text-sm">
                <p>&copy; <?php echo date('Y'); ?> icut. All rights reserved.</p>
                <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                    <a href="admin.php" class="text-barber-gold hover:text-barber-gold-light text-xs mt-2 inline-block transition">← Back to Admin</a>
                <?php endif; ?>
            </div>
        </div>
    </footer>
    
    <!-- ============ LIGHTBOX ============ -->
    <div id="lightbox" class="lightbox" onclick="if(event.target===this)closeLightbox()">
        <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
        <div class="lightbox-content" id="lightboxContent"></div>
    </div>
    
    <!-- ============ SCRIPTS ============ -->
    <script>
        // Weekdays (0=Sun..6=Sat) on which home service may be booked, from the server config
        const HOME_SERVICE_DAYS = <?php echo json_encode(getHomeServiceDays()); ?>;
        const HOME_SERVICE_LABEL = <?php echo json_encode(formatHomeServiceDays()); ?>;

        // Toggle address field based on service type
        function toggleAddressField(show) {
            const addressField = document.getElementById('addressField');
            if (show) {
                addressField.classList.remove('hidden');
            } else {
                addressField.classList.add('hidden');
            }
            // Re-check the date in case a non-home day was already picked
            validateHomeServiceDate();
        }

        // Show/refresh an inline message about home-service date eligibility
        function validateHomeServiceDate() {
            const type = document.querySelector('input[name="service_type"]:checked');
            const dateInput = document.querySelector('input[name="booking_date"]');
            const hint = document.getElementById('homeServiceDayHint');

            if (!type || type.value !== 'home' || !dateInput || dateInput.value === '') {
                if (hint) hint.textContent = '';
                return;
            }

            const day = new Date(dateInput.value + 'T00:00:00').getDay();
            const allowed = Array.isArray(HOME_SERVICE_DAYS) && HOME_SERVICE_DAYS.includes(day);

            if (hint) {
                if (allowed) {
                    hint.textContent = '';
                    dateInput.setCustomValidity('');
                } else {
                    let msg = 'Home service is only available on ' + HOME_SERVICE_LABEL + '.';
                    // Suggest the next eligible day
                    let next = null;
                    const base = new Date(dateInput.value + 'T00:00:00');
                    for (let i = 1; i <= 60; i++) {
                        const c = new Date(base);
                        c.setDate(base.getDate() + i);
                        if (HOME_SERVICE_DAYS.includes(c.getDay())) { next = c; break; }
                    }
                    if (next) msg += ' The next available day is ' + next.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }) + '.';
                    hint.textContent = msg;
                    dateInput.setCustomValidity(msg);
                }
            }
        }

        // Initialize theme on page load is already done in the first script block above.

        // Slideshow functionality
        let currentSlideIndex = 0;
        const slides = document.querySelectorAll('.slide');
        const dots = document.querySelectorAll('.slide-dot');
        
        function showSlide(index) {
            if (slides.length === 0) return;
            slides.forEach(slide => slide.classList.remove('active'));
            dots.forEach(dot => dot.classList.remove('bg-barber-gold'));
            dots.forEach(dot => dot.classList.add('bg-white/50'));
            
            slides[index].classList.add('active');
            dots[index].classList.remove('bg-white/50');
            dots[index].classList.add('bg-barber-gold');
            currentSlideIndex = index;
        }
        
        function changeSlide(direction) {
            if (slides.length === 0) return;
            let newIndex = currentSlideIndex + direction;
            if (newIndex >= slides.length) newIndex = 0;
            if (newIndex < 0) newIndex = slides.length - 1;
            showSlide(newIndex);
        }
        
        function currentSlide(index) {
            showSlide(index);
        }
        
        // Auto-advance slides
        if (slides.length > 0) {
            setInterval(() => { changeSlide(1); }, 5000);
            dots[0].classList.remove('bg-white/50');
            dots[0].classList.add('bg-barber-gold');
        }
        
        // Lightbox functionality
        function openLightbox(src, type, title) {
            const content = document.getElementById('lightboxContent');
            if (type === 'video') {
                content.innerHTML = `<video src="${src}" controls autoplay class="w-full h-full"></video>`;
            } else {
                content.innerHTML = `<img src="${src}" alt="${title}">`;
            }
            document.getElementById('lightbox').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('active');
            document.getElementById('lightboxContent').innerHTML = '';
            document.body.style.overflow = '';
        }
        
        // Service card selection visual feedback
        document.querySelectorAll('input[name="service_id"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.service-card').forEach(card => {
                    card.classList.remove('border-barber-gold', 'bg-barber-gold/10');
                });
                if (this.checked) {
                    this.closest('.service-card').classList.add('border-barber-gold', 'bg-barber-gold/10');
                }
            });
        });
        
        // Update end time display
        document.querySelectorAll('input[name="service_id"]').forEach(radio => {
            radio.addEventListener('change', updateEndTime);
        });
        
        const timeSelect = document.querySelector('select[name="booking_time"]');
        if (timeSelect) {
            timeSelect.addEventListener('change', updateEndTime);
        }
        
        function updateEndTime() {
            const serviceSelected = document.querySelector('input[name="service_id"]:checked');
            const timeSelected = document.querySelector('select[name="booking_time"]').value;
            const endTimeDisplay = document.getElementById('end-time-display');
            
            if (serviceSelected && timeSelected) {
                const serviceCard = serviceSelected.closest('.service-card');
                const durationText = serviceCard.querySelector('.text-gray-500').textContent;
                const minutes = parseInt(durationText.match(/\d+/)[0]);
                
                const [hours, mins] = timeSelected.split(':');
                const startDate = new Date(2026, 0, 1, parseInt(hours), parseInt(mins));
                const endDate = new Date(startDate.getTime() + minutes * 60000);
                
                const endTime = endDate.toLocaleTimeString('en-US', { 
                    hour: 'numeric', 
                    minute: '2-digit', 
                    hour12: true 
                });
                
                endTimeDisplay.innerHTML = `<i class="far fa-clock mr-1"></i>Appointment ends at: <strong>${endTime}</strong>`;
                endTimeDisplay.classList.remove('hidden');
            }
        }
        
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href.startsWith('#')) {
                    e.preventDefault();
                    const target = document.querySelector(href);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    // Close mobile menu
                    document.getElementById('mobileMenu').classList.remove('open');
                }
            });
        });
        
        // Push Notifications
        function requestNotificationPermission() {
            if (!('Notification' in window)) {
                alert('Your browser does not support notifications.\n\nFor the best experience, please use:\n• Chrome or Edge on Android\n• Safari on iOS 16.4+\n\nNote: Notifications require HTTPS on mobile devices.');
                return;
            }
            
            if (Notification.permission === 'granted') {
                alert('Notifications are already enabled! You will receive reminders for your appointments.');
                return;
            }
            
            if (Notification.permission === 'denied') {
                alert('Notifications are blocked.\n\nTo enable:\n1. Open your browser settings\n2. Find this website\n3. Enable notifications\n\nNote: On iPhone, you must add this site to your Home Screen first.');
                return;
            }
            
            // Check if we're on mobile and not using HTTPS
            var isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
            var isSecure = window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
            
            if (isMobile && !isSecure) {
                alert('Notifications require HTTPS on mobile devices.\n\nTo receive notifications:\n1. Add this site to your Home Screen\n2. Open it from the Home Screen icon\n3. Grant notification permission when prompted\n\nNote: This only works if the site is served over HTTPS.');
                return;
            }
            
            Notification.requestPermission().then(function(permission) {
                if (permission === 'granted') {
                    if ('serviceWorker' in navigator) {
                        navigator.serviceWorker.register('/icut/sw.js').then(function(registration) {
                            console.log('Service Worker registered:', registration);
                            alert('Notifications enabled! You will receive reminders for your appointments.');
                        }).catch(function(err) {
                            console.log('Service Worker registration failed:', err);
                            alert('Notifications enabled! You will receive reminders while the app is open.');
                        });
                    } else {
                        alert('Notifications enabled! You will receive reminders while the app is open.');
                    }
                } else {
                    alert('Notification permission denied. You can enable it later in your browser settings.');
                }
            }).catch(function(err) {
                console.log('Notification permission error:', err);
                alert('Could not request notification permission. Please check your browser settings.');
            });
        }
        
        // Expose to global scope for inline onclick handlers
        window.requestNotificationPermission = requestNotificationPermission;
        
        // Auto-request notification permission after booking
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('booked') === '1' && 'Notification' in window && Notification.permission === 'default') {
            setTimeout(requestNotificationPermission, 3000);
        }
        
        // Package selection
        function selectPackage(packageId, packageName) {
            // Disable service selection when package is selected
            document.querySelectorAll('input[name="service_id"]').forEach(radio => {
                radio.disabled = true;
                radio.closest('.service-card').classList.add('opacity-50');
            });
        }
        
        function clearServiceSelection() {
            // Re-enable service selection when individual service is chosen
            document.querySelectorAll('input[name="service_id"]').forEach(radio => {
                radio.disabled = false;
                radio.closest('.service-card').classList.remove('opacity-50');
            });
        }
        
        // Payment initialization
        function initializePayment() {
            const bookingId = <?php echo isset($_SESSION['pending_booking_id']) ? (int)$_SESSION['pending_booking_id'] : 0; ?>;
            const email = '<?php echo isset($_SESSION['pending_booking_email']) ? htmlspecialchars($_SESSION['pending_booking_email'], ENT_QUOTES) : ''; ?>';
            const amount = '<?php echo isset($_SESSION['pending_booking_amount']) ? number_format($_SESSION['pending_booking_amount'], 2) : '0.00'; ?>';
            const csrfToken = document.querySelector('input[name="csrf_token"]').value;
            const errorEl = document.getElementById('payment-error');
            
            if (!bookingId || !email || !amount) {
                if (errorEl) {
                    errorEl.textContent = 'Missing booking information. Please refresh and try again.';
                    errorEl.classList.remove('hidden');
                }
                return;
            }
            
            if (errorEl) errorEl.classList.add('hidden');
            
            fetch('payment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&booking_id=' + encodeURIComponent(bookingId) + '&email=' + encodeURIComponent(email) + '&amount=' + encodeURIComponent(amount)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Server error: ' + response.status + ' ' + response.statusText);
                }
                return response.json().catch(function() {
                    throw new Error('Invalid response from server. Please try again or contact support.');
                });
            })
            .then(data => {
                if (data.success && data.authorization_url) {
                    window.location.href = data.authorization_url;
                } else {
                    if (errorEl) {
                        errorEl.textContent = data.message || 'Payment initialization failed. Please try again.';
                        errorEl.classList.remove('hidden');
                    }
                }
            })
            .catch(error => {
                console.error('Payment error:', error);
                if (errorEl) {
                    errorEl.textContent = error.message || 'Payment failed. Please check your connection and try again.';
                    errorEl.classList.remove('hidden');
                }
            });
        }

        // Expose functions to global scope for inline onclick handlers
        window.toggleTheme = toggleTheme;
        window.changeSlide = changeSlide;
        window.currentSlide = currentSlide;
        window.openLightbox = openLightbox;
        window.closeLightbox = closeLightbox;

        // Run the home-service date check on load (e.g. after a validation error
        // has re-rendered the form with a previously chosen date)
        document.addEventListener('DOMContentLoaded', function () {
            validateHomeServiceDate();
        });
    </script>

    <?php
    // Hidden admin entry: a tiny, nearly invisible hot-spot in the bottom-right
    // corner of the page. There is no visible "Admin" link anywhere; only someone
    // who knows to click this spot reaches the admin login. Keep it subtle.
    $admin_entry = $app_base_path . '/admin_login.php?a=' . rawurlencode(env('ADMIN_ENTRY_KEY', 'icitboss'));
    ?>
    <a href="<?php echo htmlspecialchars($admin_entry, ENT_QUOTES, 'UTF-8'); ?>"
       aria-hidden="true" tabindex="-1"
       style="position:fixed;right:0;bottom:0;width:14px;height:14px;opacity:0.04;z-index:5;cursor:default;"
       title=""></a>

    <script>
    // AJAX booking form submission — ONLY on Vercel, where the PHP router lives
    // at /api/book. On other hosts (Apache/cPanel/XAMPP) the form posts natively
    // to index.php, because /api/book does not exist there and the fetch would
    // always fail with "Network error".
    (function() {
        const form = document.getElementById('booking-form');
        if (!form) return;

        const bookingApiUrl = <?php echo env('VERCEL', '') !== '' ? "'/api/book'" : 'null'; ?>;
        if (!bookingApiUrl) return; // native form POST to #book

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Booking...';

            const formData = new FormData(form);

            try {
                const response = await fetch(bookingApiUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const result = await response.json();

                // Remove existing messages
                const existingMsg = form.closest('.glass').querySelector('.booking-message');
                if (existingMsg) existingMsg.remove();

                const messageDiv = document.createElement('div');
                messageDiv.className = 'booking-message mt-6 p-4 rounded-lg text-sm';

                if (result.success) {
                    messageDiv.className += ' bg-green-900/50 border border-green-700 text-green-300';
                    messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + result.message;
                    form.reset();
                    // Regenerate idempotency key
                    const idempotencyInput = form.querySelector('input[name="idempotency_key"]');
                    if (idempotencyInput) {
                        idempotencyInput.value = Math.random().toString(36).substring(2) + Date.now().toString(36);
                    }
                } else {
                    messageDiv.className += ' bg-red-900/50 border border-red-700 text-red-300';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + (result.error || 'Something went wrong');
                }

                form.insertAdjacentElement('afterend', messageDiv);
                messageDiv.scrollIntoView({ behavior: 'smooth', block: 'top' });

            } catch (error) {
                const existingMsg = form.closest('.glass').querySelector('.booking-message');
                if (existingMsg) existingMsg.remove();

                const messageDiv = document.createElement('div');
                messageDiv.className = 'booking-message mt-6 p-4 rounded-lg text-sm bg-red-900/50 border border-red-700 text-red-300';
                messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Network error. Please try again.';
                form.insertAdjacentElement('afterend', messageDiv);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    })();
    </script>
</body>
</html>