<?php
/**
 * Database Configuration and Connection
 * 
 * This file establishes a connection to the SQLite database
 * using PDO (PHP Data Objects) for secure database operations.
 * 
 * Sensitive credentials are loaded from .env file
 */

// Load environment variables
require_once __DIR__ . '/env.php';

// Harden session cookies before any session is started. cookie_secure is only
// switched on for HTTPS requests so that plain-HTTP local development still
// works (a Secure cookie would never be sent back over http://).
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Strict');
    @ini_set('session.use_strict_mode', '1');
    if ($is_https) {
        @ini_set('session.cookie_secure', '1');
    }
}

// Database credentials from environment variables.
// A relative DB_PATH is resolved against this file's directory so that CLI
// scripts (cron jobs, migrations) always open the same database as the web app.
$configured_db_path = env('DB_PATH', 'database/icut.db');
if (!preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $configured_db_path)) {
    $configured_db_path = __DIR__ . DIRECTORY_SEPARATOR . $configured_db_path;
}
define('DB_PATH', $configured_db_path);
unset($configured_db_path);

// MySQL-compatible (TiDB Cloud) connection settings exposed to getDatabaseConnection().
$GLOBALS['__mysql_host']    = env('MYSQL_HOST', '127.0.0.1');
$GLOBALS['__mysql_port']    = env('MYSQL_PORT', '3306');
$GLOBALS['__mysql_name']    = env('MYSQL_NAME', 'barbershop_db');
$GLOBALS['__mysql_user']    = env('MYSQL_USER', 'root');
$GLOBALS['__mysql_pass']    = env('MYSQL_PASS', '');
$GLOBALS['__mysql_charset'] = env('MYSQL_CHARSET', 'utf8mb4');
define('MYSQL_SSL', (int)env('MYSQL_SSL', '0') === 1);

/**
 * Establish database connection using PDO
 *
 * Connects to a MySQL-compatible database (TiDB Cloud, MySQL, MariaDB).
 *
 * @return PDO Database connection object
 * @throws PDOException If connection fails
 */
function getDatabaseConnection() {
    try {
        // Data Source Name for MySQL / TiDB
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            $GLOBALS['__mysql_host'],
            $GLOBALS['__mysql_port'],
            $GLOBALS['__mysql_name'],
            $GLOBALS['__mysql_charset']
        );

        // PDO options for security and error handling
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Return associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false                    // Use real prepared statements
        ];

        // TiDB Cloud serverless / public MySQL over TLS typically require SSL.
        if (\defined('MYSQL_SSL') && MYSQL_SSL) {
            // PHP 8.5+ moved the MySQL PDO constants to the namespaced
            // Pdo\Mysql class; older versions keep them on PDO.
            if (PHP_VERSION_ID >= 80500) {
                $constCa = \Pdo\Mysql::ATTR_SSL_CA;
                $constVerify = \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT;
            } else {
                $constCa = \PDO::MYSQL_ATTR_SSL_CA;
                $constVerify = \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
            }

            // mysqlnd only enables TLS when a CA bundle is actually set. Use the
            // configured path, or auto-detect a well-known system CA bundle
            // (Vercel/Amazon Linux, Debian/Ubuntu, Alpine, Fedora/RHEL, XAMPP).
            $ca = env('MYSQL_SSL_CA', '');
            if ($ca === '') {
                foreach ([
                    '/etc/pki/tls/certs/ca-bundle.crt',
                    '/etc/ssl/certs/ca-certificates.crt',
                    '/etc/ssl/cert.pem',
                    '/etc/ssl/ca-bundle.pem',
                    'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
                ] as $candidate) {
                    if (is_readable($candidate)) {
                        $ca = $candidate;
                        break;
                    }
                }
            }

            if ($ca !== '' && is_readable($ca)) {
                $options[$constCa] = $ca;
                // With a real CA bundle we can verify the TiDB certificate.
                $options[$constVerify] = true;
            } else {
                // No CA available: still request TLS, but skip verification.
                $options[$constVerify] = false;
            }
        }

        // Create PDO instance
        $pdo = new PDO(
            $dsn,
            $GLOBALS['__mysql_user'],
            $GLOBALS['__mysql_pass'],
            $options
        );

        return $pdo;
    } catch (PDOException $e) {
        // Log the error (in production, don't display to users)
        error_log("Database Connection Error: " . $e->getMessage());

        // For development, we can show the error
        // Remove this in production!
        die("Database connection failed. Please check your configuration.");
    }
}

/**
 * Helper function to test database connection
 * 
 * @return bool True if connection is successful
 */
function testDatabaseConnection() {
    try {
        $pdo = getDatabaseConnection();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Optional: Create a global connection variable for convenience
$db = getDatabaseConnection();

/**
 * Absolute web-root path to this application (e.g. "/icut" or empty for the
 * domain root). Used to build absolute (root-anchored) internal links so they
 * never resolve onto "/index.php/..." when a page is served under PATH_INFO.
 */
function appBasePath() {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    return rtrim(dirname($script), '/');
}

/**
 * Absolute URL to the admin login page, including the hidden entry key.
 * Every protected script should redirect here so the login form actually loads
 * (a keyless redirect to admin_login.php would be rejected by the entry gate).
 *
 * @param array $extra  Optional query params, e.g. ['timeout' => '1']
 * @return string
 */
function adminLoginUrl($extra = []) {
    $key = env('ADMIN_ENTRY_KEY', 'icitboss');
    $url = appBasePath() . '/admin_login.php?a=' . rawurlencode($key);
    foreach ($extra as $k => $v) {
        $url .= '&' . urlencode($k) . '=' . rawurlencode($v);
    }
    return $url;
}

/**
 * List the columns of a table (portable helper replacing SQLite's
 * `PRAGMA table_info`; works on MySQL/TiDB via SHOW COLUMNS).
 *
 * @param string $table
 * @return array  Each row has keys: Field, Type, ...
 */
function tableColumns($table) {
    global $db;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table doesn't exist yet (fresh/empty database) — let ensure*() create it
        if (strpos($e->getMessage(), '42S02') !== false || stripos($e->getMessage(), "doesn't exist") !== false) {
            return [];
        }
        throw $e;
    }
}

/**
 * Ensure admins table exists and create default admin if needed
 */
function ensureAdminsTableExists() {
    global $db;
    
    // Check if table exists with wrong schema (has 'password' column instead of 'password_hash')
    $table_info = tableColumns('admins');
    $has_password_column = false;
    $has_password_hash_column = false;
    
    foreach ($table_info as $column) {
        $colname = $column['Field'] ?? $column['name'] ?? '';
        if ($colname === 'password') $has_password_column = true;
        if ($colname === 'password_hash') $has_password_hash_column = true;
    }
    
    // If table has wrong schema, drop and recreate
    if ($has_password_column && !$has_password_hash_column) {
        $db->exec("DROP TABLE IF EXISTS admins");
    }
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(191) NOT NULL UNIQUE,
            email VARCHAR(191),
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(255),
            failed_attempts INT DEFAULT 0,
            locked_until DATETIME,
            must_change_password TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Add missing columns for older schemas
    try {
        $db->exec("ALTER TABLE admins ADD COLUMN email VARCHAR(191)");
    } catch (Exception $e) { /* column already exists */ }
    try {
        $db->exec("ALTER TABLE admins ADD COLUMN must_change_password TINYINT(1) DEFAULT 0");
    } catch (Exception $e) { /* column already exists */ }

    // Check if admin exists, if not create default
    $admin_count = $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($admin_count == 0) {
        $default_password = password_hash('icitboss2026!', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admins (username, email, password_hash, full_name, must_change_password) VALUES ('admin', :email, :password, 'Admin', 1)");
        $stmt->execute([':email' => getSiteSetting('email', 'admin@icut.com'), ':password' => $default_password]);
    } else {
        // Make sure the default admin has an email so they can log in with it
        $db->exec("UPDATE admins SET email = " . $db->quote(getSiteSetting('email', 'admin@icut.com')) . " WHERE id = 1 AND (email IS NULL OR email = '')");
    }
}

/**
 * Ensure barbers table exists
 */
function ensureBarbersTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS barbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            specialization VARCHAR(191),
            phone VARCHAR(50),
            image VARCHAR(255),
            is_active INT DEFAULT 1,
            offers_home_service INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure services table exists
 */
function ensureServicesTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            duration_minutes INT NOT NULL,
            image VARCHAR(255),
            is_active INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Add default services if table is empty
    $service_count = $db->query("SELECT COUNT(*) FROM services")->fetchColumn();
    if ($service_count == 0) {
        $default_services = [
            ['Haircut', 'Classic haircut with styling', 30.00, 30],
            ['Beard Trim', 'Beard shaping and trim', 15.00, 15],
            ['Shave', 'Hot towel straight razor shave', 25.00, 30],
            ['Haircut & Beard', 'Combined haircut and beard service', 40.00, 45],
            ['Kids Haircut', 'Haircut for children under 12', 20.00, 25]
        ];
        
        foreach ($default_services as $service) {
            $stmt = $db->prepare("INSERT INTO services (name, description, price, duration_minutes) VALUES (:name, :description, :price, :duration)");
            $stmt->execute([
                ':name' => $service[0],
                ':description' => $service[1],
                ':price' => $service[2],
                ':duration' => $service[3]
            ]);
        }
    }
}

/**
 * Ensure bookings table exists
 */
function ensureBookingsTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(191) NOT NULL,
            client_phone VARCHAR(50) NOT NULL,
            client_email VARCHAR(191),
            barber_id INT NOT NULL,
            service_id INT NOT NULL,
            package_id INT,
            booking_date DATE NOT NULL,
            booking_time TIME NOT NULL,
            notes TEXT,
            price DECIMAL(10,2) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending' CHECK(status IN ('pending', 'confirmed', 'completed', 'cancelled')),
            payment_status VARCHAR(20) DEFAULT 'pending' CHECK(payment_status IN ('pending', 'success', 'failed')),
            payment_reference VARCHAR(191),
            payment_method VARCHAR(50),
            paid_amount DECIMAL(10,2) DEFAULT 0,
            refund_status VARCHAR(20) DEFAULT 'none' CHECK(refund_status IN ('none', 'requested', 'processed', 'failed')),
            refund_reference VARCHAR(191),
            refund_amount DECIMAL(10,2) DEFAULT 0,
            refunded_at DATETIME,
            cancelled_at DATETIME,
            booking_reference VARCHAR(50),
            confirmation_token VARCHAR(191),
            idempotency_key VARCHAR(191) UNIQUE,
            service_type VARCHAR(20) DEFAULT 'shop' CHECK(service_type IN ('shop', 'home')),
            client_address TEXT,
            home_service_fee DECIMAL(10,2) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE CASCADE,
            FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure site_settings table exists
 */
function ensureSiteSettingsTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(191) PRIMARY KEY,
            setting_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Add default settings if table is empty
    $setting_count = $db->query("SELECT COUNT(*) FROM site_settings")->fetchColumn();
    if ($setting_count == 0) {
        $default_settings = [
            'happy_clients' => '5K+',
            'years_exp' => '15+',
            'rating' => '4.9',
            'address' => '',
            'phone' => '',
            'email' => '',
            'whatsapp_number' => '',
            'hours_weekday' => '9:00 AM - 7:00 PM',
            'hours_saturday' => '9:00 AM - 6:00 PM',
            'hours_sunday' => 'Closed',
            'footer_about' => 'Premium grooming experience with master barbers.',
            'home_service_fee' => '20.00',
            'home_service_days' => '1,2,3,4,5'
        ];
        
        foreach ($default_settings as $key => $value) {
            $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value)");
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
    }
}

/**
 * Ensure reviews table exists
 */
function ensureReviewsTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(191) NOT NULL,
            rating INT NOT NULL CHECK(rating >= 1 AND rating <= 5),
            comment TEXT,
            service_name VARCHAR(191),
            is_approved INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Migrate legacy schema that used service_id instead of service_name.
    // The whole application (review form, admin list, public display) works with
    // the service name as free text, matching the original MySQL schema.
    $columns = tableColumns('reviews');
    $has_service_id = false;
    $has_service_name = false;
    foreach ($columns as $column) {
        $colname = $column['Field'] ?? $column['name'] ?? '';
        if ($colname === 'service_id') $has_service_id = true;
        if ($colname === 'service_name') $has_service_name = true;
    }

    if ($has_service_id && !$has_service_name) {
        $db->exec("ALTER TABLE reviews ADD COLUMN service_name VARCHAR(191)");
        // Backfill names from the services table where the old id still resolves
        $db->exec("
            UPDATE reviews
            SET service_name = (SELECT name FROM services WHERE services.id = reviews.service_id)
            WHERE service_name IS NULL AND service_id IS NOT NULL
        ");
    }
}

/**
 * Ensure gallery table exists
 */
function ensureGalleryTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS gallery (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255),
            file_path VARCHAR(255) NOT NULL,
            media_type VARCHAR(20) DEFAULT 'image' CHECK(media_type IN ('image', 'video')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure loyalty table exists
 */
function ensureLoyaltyTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS loyalty (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(50) NOT NULL UNIQUE,
            points INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure admin_activity_log table exists
 */
function ensureAdminActivityLogTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_name VARCHAR(191) NOT NULL,
            activity_type VARCHAR(191) NOT NULL,
            details TEXT,
            booking_id INT,
            ip_address VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure business_hours table exists
 */
function ensureBusinessHoursTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS business_hours (
            id INT AUTO_INCREMENT PRIMARY KEY,
            day_of_week INT NOT NULL UNIQUE,
            open_time TIME,
            close_time TIME,
            is_closed INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Seed a default week (closed on Sunday) when empty
    $count = $db->query("SELECT COUNT(*) FROM business_hours")->fetchColumn();
    if ($count == 0) {
        $stmt = $db->prepare("INSERT INTO business_hours (day_of_week, open_time, close_time, is_closed) VALUES (?, ?, ?, ?)");
        for ($i = 0; $i < 7; $i++) {
            $stmt->execute([$i, '09:00', '19:00', $i == 0 ? 1 : 0]);
        }
    }
}

/**
 * Ensure barber_schedules table exists
 */
function ensureBarberSchedulesTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS barber_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            barber_id INT NOT NULL,
            day_of_week INT NOT NULL,
            start_time TIME,
            end_time TIME,
            is_working INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE CASCADE,
            UNIQUE(barber_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure rate_limits table exists (backs checkRateLimit)
 */
function ensureRateLimitTableExists() {
    global $db;
    static $checked = false;
    if ($checked) {
        return;
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bucket VARCHAR(191) NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("CREATE INDEX idx_rate_limits_bucket ON rate_limits (bucket, attempted_at)");
    } catch (Exception $e) { /* index already exists */ }
    $checked = true;
}

/**
 * Ensure client_notes table exists
 */
function ensureClientNotesTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS client_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(50) NOT NULL UNIQUE,
            notes TEXT,
            preferences TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Initialize all database tables
 */
function initializeDatabase() {
    ensureAdminsTableExists();
    ensureBarbersTableExists();
    ensureServicesTableExists();
    ensureBookingsTableExists();
    ensureSiteSettingsTableExists();
    ensureReviewsTableExists();
    ensureGalleryTableExists();
    ensureLoyaltyTableExists();
    ensureAdminActivityLogTableExists();
    ensurePaymentsTableExists();
    ensure2FATableExists();
    ensurePackagesTableExists();
    ensureWaitlistTableExists();
    ensureBusinessHoursTableExists();
    ensureBarberSchedulesTableExists();
    ensureClientNotesTableExists();
    ensureRateLimitTableExists();
    ensureHomeServiceColumns();
    ensureBarberHomeServiceColumn();
}

// Initialize database tables
initializeDatabase();

/**
 * Generate a random booking reference number (e.g., #57368)
 *
 * Uses random_int() (CSPRNG) rather than mt_rand() because the reference is
 * shown to clients and used to look bookings up.
 *
 * @return string Random booking reference
 */
function generateBookingReference() {
    return '#' . random_int(10000, 99999);
}

/**
 * Make sure a booking has a confirmation token, creating one if it predates
 * the token column. Returns the token.
 *
 * @param int $booking_id
 * @return string|null
 */
function ensureBookingConfirmationToken($booking_id) {
    global $db;

    $stmt = $db->prepare("SELECT confirmation_token FROM bookings WHERE id = :id");
    $stmt->execute([':id' => $booking_id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (!empty($row['confirmation_token'])) {
        return $row['confirmation_token'];
    }

    $token = bin2hex(random_bytes(16));
    $update = $db->prepare("UPDATE bookings SET confirmation_token = :token WHERE id = :id");
    $update->execute([':token' => $token, ':id' => $booking_id]);

    return $token;
}

/**
 * Authorise a client-initiated cancellation.
 *
 * The booking id alone is NOT proof of ownership: ids are sequential, so
 * trusting it would let anyone cancel any booking by counting upwards. The
 * caller must also present the booking's confirmation_token, which is only
 * ever revealed after a successful reference + phone lookup.
 *
 * @param int    $booking_id
 * @param string $token      confirmation_token supplied by the client
 * @return array|null        The booking row, or null when not authorised
 */
function getBookingForCancellation($booking_id, $token) {
    global $db;

    $booking_id = (int)$booking_id;
    if ($booking_id <= 0 || !is_string($token) || $token === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT b.*, br.name as barber_name, s.name as service_name
        FROM bookings b
        JOIN barbers br ON b.barber_id = br.id
        JOIN services s ON b.service_id = s.id
        WHERE b.id = :id
    ");
    $stmt->execute([':id' => $booking_id]);
    $booking = $stmt->fetch();

    if (!$booking || empty($booking['confirmation_token'])) {
        return null;
    }

    // Timing-safe comparison so the token cannot be guessed byte by byte
    if (!hash_equals($booking['confirmation_token'], $token)) {
        return null;
    }

    // Only bookings that are still live may be cancelled by the client
    if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
        return null;
    }

    return $booking;
}

/**
 * Send email notification using PHP mail()
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message HTML message
 * @return bool Success status
 */
function sendEmailNotification($to, $subject, $message) {
    if (empty($to)) {
        return false;
    }
    
    // Use SMTP mailer if configured
    if (!empty(env('SMTP_HOST', ''))) {
        require_once __DIR__ . '/smtp_mailer.php';
        $mailer = new SmtpMailer();
        return $mailer->send($to, $subject, $message);
    }
    
    // Fallback to PHP mail() if SMTP not configured
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: icut <noreply@icut.com>" . "\r\n";
    $headers .= "Reply-To: " . getSiteSetting('email', 'noreply@icut.com') . "\r\n";
    
    try {
        $result = @mail($to, $subject, $message, $headers);
        if (!$result) {
            error_log("Email send failed to {$to}: mail() returned false. Configure SMTP in .env for reliable delivery.");
        }
        return $result;
    } catch (Exception $e) {
        error_log("Email send failed to {$to}: " . $e->getMessage());
        return false;
    }
}

/**
 * Get a site setting value
 * 
 * @param string $key Setting key
 * @param mixed $default Default value
 * @return string Setting value
 */
function getSiteSetting($key, $default = '') {
    global $db;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Send booking confirmation email to client
 */
function sendClientConfirmation($booking) {
    $to = $booking['client_email'] ?? '';
    if (empty($to)) {
        return false;
    }
    
    $booking_ref = isset($booking['booking_reference']) ? $booking['booking_reference'] : generateBookingReference();
    $subject = getSiteSetting('email_subject_confirmation', 'Booking Confirmation {booking_reference} - icut');
    $body = getSiteSetting('email_body_confirmation', getDefaultConfirmationTemplate());
    
    // Replace placeholders
    $placeholders = [
        '{client_name}' => $booking['client_name'],
        '{booking_reference}' => $booking_ref,
        '{service_name}' => $booking['service_name'],
        '{barber_name}' => $booking['barber_name'],
        '{date}' => date('F j, Y', strtotime($booking['booking_date'])),
        '{time}' => date('g:i A', strtotime($booking['booking_time'])),
        '{price}' => '₵' . number_format($booking['service_price'], 2)
    ];
    
    $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);
    
    return sendEmailNotification($to, $subject, $body);
}

/**
 * Process refund via Paystack
 */
function processRefund($booking_id, $amount = null, $reason = '') {
    global $db;
    
    // Get booking and payment details
    $stmt = $db->prepare("
        SELECT b.*, p.payment_reference, p.amount as paid_amount, p.currency 
        FROM bookings b
        JOIN payments p ON b.id = p.booking_id
        WHERE b.id = :id AND b.payment_status = 'success' AND b.refund_status = 'none'
    ");
    $stmt->execute([':id' => $booking_id]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found or not eligible for refund.'];
    }
    
    $settings = getPaystackSettings();
    if (empty($settings['secret_key'])) {
        return ['success' => false, 'message' => 'Payment gateway not configured.'];
    }
    
    // Use full amount if not specified
    $refund_amount = $amount ? (float)$amount : (float)$booking['paid_amount'];
    $amount_in_kobo = (int)round($refund_amount * 100);
    
    // Initialize refund via Paystack API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paystack.co/refund');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'transaction' => $booking['payment_reference'],
        'amount' => $amount_in_kobo,
        'currency' => $booking['currency'] ?: $settings['currency']
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $settings['secret_key'],
        'Content-Type: application/json',
        'Cache-Control: no-cache'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $result = json_decode($response, true);
        if ($result['status']) {
            $refund_ref = $result['data']['refund_reference'] ?? '';
            
            // Update booking
            $stmt = $db->prepare("UPDATE bookings SET refund_status = 'processed', refund_reference = :ref, refund_amount = :amount, refunded_at = NOW(), status = 'cancelled' WHERE id = :id");
            $stmt->execute([
                ':ref' => $refund_ref,
                ':amount' => $refund_amount,
                ':id' => $booking_id
            ]);
            
            logAdminActivity('refund', 'System', "Refunded ₵" . number_format($refund_amount, 2) . " for booking #{$booking['booking_reference']}. Reason: {$reason}", $booking_id);
            
            // Send refund confirmation email to client
            if (!empty($booking['client_email'])) {
                sendRefundProcessedEmail($booking, $refund_amount, $refund_ref);
            }
            
            return ['success' => true, 'message' => 'Refund processed successfully.', 'refund_reference' => $refund_ref];
        }
    }
    
    return ['success' => false, 'message' => 'Refund failed. Please try again or contact Paystack support.'];
}

/**
 * Send refund requested email
 */
function sendRefundRequestedEmail($booking, $refund_amount) {
    $to = $booking['client_email'] ?? '';
    if (empty($to)) return false;
    
    $subject = "Refund Request Received - icut";
    $message = "<html><body style='font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;'>
        <div style='max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;'>
            <h2 style='color: #c9a96e;'>icut</h2>
            <h3>💸 Refund Request Received</h3>
            <p>Dear {$booking['client_name']},</p>
            <p>We have received your cancellation request and a refund has been initiated for your booking:</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Reference:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['booking_reference']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Service:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['service_name']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Refund Amount:</strong></td><td style='padding: 10px; border: 1px solid #404040; color: #c9a96e; font-weight: bold;'>₵" . number_format($refund_amount, 2) . "</td></tr>
            </table>
            <p>The refund will be processed to your original payment method within <strong>3-10 business days</strong>, depending on your bank or payment provider.</p>
            <p>If you have any questions, please contact us.</p>
            <p style='color: #c9a96e;'>Thank you for choosing icut!</p>
        </div>
    </body></html>";
    
    return sendEmailNotification($to, $subject, $message);
}

/**
 * Send refund processed email
 */
function sendRefundProcessedEmail($booking, $refund_amount, $refund_ref) {
    $to = $booking['client_email'] ?? '';
    if (empty($to)) return false;
    
    $subject = "Refund Processed - icut";
    $message = "<html><body style='font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;'>
        <div style='max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;'>
            <h2 style='color: #c9a96e;'>icut</h2>
            <h3>✅ Refund Processed</h3>
            <p>Dear {$booking['client_name']},</p>
            <p>Your refund has been successfully processed. Here are the details:</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Reference:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['booking_reference']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Service:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['service_name']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Refund Amount:</strong></td><td style='padding: 10px; border: 1px solid #404040; color: #c9a96e; font-weight: bold;'>₵" . number_format($refund_amount, 2) . "</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Refund Reference:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$refund_ref}</td></tr>
            </table>
            <p>The funds should appear in your account within <strong>3-10 business days</strong>, depending on your bank or payment provider.</p>
            <p>If you have any questions, please contact us.</p>
            <p style='color: #c9a96e;'>Thank you for choosing icut!</p>
        </div>
    </body></html>";
    
    return sendEmailNotification($to, $subject, $message);
}

/**
 * Get refund status for a booking
 */
function getRefundStatus($booking_id) {
    global $db;
    $stmt = $db->prepare("SELECT refund_status, refund_reference, refund_amount, refunded_at FROM bookings WHERE id = :id");
    $stmt->execute([':id' => $booking_id]);
    return $stmt->fetch();
}

/**
 * Get Paystack settings from environment variables
 */
function getPaystackSettings() {
    return [
        'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
        'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
        'currency' => env('PAYSTACK_CURRENCY', 'GHS'),
        'payment_enabled' => env('PAYSTACK_ENABLED', '0') === '1'
    ];
}

/**
 * Initialize Paystack payment
 */
function initializePaystackPayment($booking_id, $email, $amount, $booking_reference) {
    global $db;
    $settings = getPaystackSettings();
    
    if (empty($settings['public_key']) || empty($settings['secret_key'])) {
        return ['success' => false, 'message' => 'Payment gateway is not configured.'];
    }
    
    if (!$settings['payment_enabled']) {
        return ['success' => false, 'message' => 'Online payments are currently disabled.'];
    }
    
    // Verify booking exists and amount matches
    $stmt = $db->prepare("SELECT id, price FROM bookings WHERE id = :id AND payment_status = 'pending'");
    $stmt->execute([':id' => $booking_id]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        return ['success' => false, 'message' => 'Invalid booking or payment already processed.'];
    }
    
    $expected_amount = (float)($booking['price'] ?? 0);
    $requested_amount = (float)str_replace(',', '', $amount);
    
    // Prevent amount tampering - must match within 1% tolerance
    if ($expected_amount > 0 && abs($expected_amount - $requested_amount) / $expected_amount > 0.01) {
        return ['success' => false, 'message' => 'Amount mismatch. Please refresh and try again.'];
    }
    
    // Prevent duplicate payment initialization
    ensurePaymentsTableExists();
    $dup = $db->prepare("SELECT id FROM payments WHERE booking_id = :booking_id AND status IN ('pending', 'success')");
    $dup->execute([':booking_id' => $booking_id]);
    if ($dup->fetch()) {
        return ['success' => false, 'message' => 'Payment already initiated for this booking.'];
    }
    
    $amount_in_kobo = (int)round($requested_amount * 100);
    $reference = 'icut_' . preg_replace('/[^A-Za-z0-9]/', '', $booking_reference) . '_' . time() . '_' . bin2hex(random_bytes(4));
    $callback_url = env('SITE_URL', 'http://localhost/icut') . '/payment_callback.php';
    $webhook_url = env('SITE_URL', 'http://localhost/icut') . '/payment_webhook.php';
    
    $payload = [
        'amount' => $amount_in_kobo,
        'email' => $email,
        'reference' => $reference,
        'currency' => $settings['currency'],
        'callback_url' => $callback_url,
        'webhook_url' => $webhook_url,
        'metadata' => [
            'booking_id' => (string)$booking_id,
            'booking_reference' => $booking_reference,
            'site' => 'icut'
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paystack.co/transaction/initialize');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $settings['secret_key'],
        'Content-Type: application/json',
        'Cache-Control: no-cache'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        error_log("Paystack cURL error: " . $curl_error);
        return ['success' => false, 'message' => 'Payment gateway connection failed. Please check your internet and try again.'];
    }
    
    $result = json_decode($response, true);
    if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("Paystack JSON decode error: " . json_last_error_msg() . " | Response: " . substr($response, 0, 200));
        return ['success' => false, 'message' => 'Invalid response from payment gateway. Please try again.'];
    }
    
    if ($http_code === 200 && isset($result['status']) && $result['status']) {
        $stmt = $db->prepare("INSERT INTO payments (booking_id, payment_reference, amount, currency, status, gateway_response) VALUES (:booking_id, :reference, :amount, :currency, 'pending', :response)");
        $stmt->execute([
            ':booking_id' => $booking_id,
            ':reference' => $reference,
            ':amount' => $requested_amount,
            ':currency' => $settings['currency'],
            ':response' => json_encode($result)
        ]);
        
        return [
            'success' => true,
            'authorization_url' => $result['data']['authorization_url'],
            'reference' => $reference
        ];
    }
    
    $error_message = $result['message'] ?? 'Payment initialization failed. Please try again.';
    error_log("Paystack initialization failed: HTTP {$http_code} | Message: {$error_message}");
    return ['success' => false, 'message' => $error_message];
}

/**
 * Verify Paystack payment
 */
function verifyPaystackPayment($reference) {
    $settings = getPaystackSettings();
    
    if (empty($settings['secret_key'])) {
        return ['success' => false, 'message' => 'Payment gateway not configured.'];
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paystack.co/transaction/verify/' . urlencode($reference));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $settings['secret_key'],
        'Cache-Control: no-cache'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $result = json_decode($response, true);
        if ($result['status'] && $result['data']['status'] === 'success') {
            return [
                'success' => true,
                'data' => $result['data']
            ];
        }
    }
    
    return ['success' => false, 'message' => 'Payment verification failed.'];
}

/**
 * Update booking payment status
 */
function updateBookingPaymentStatus($booking_id, $status, $reference, $method, $amount) {
    global $db;
    $stmt = $db->prepare("UPDATE bookings SET payment_status = :status, payment_reference = :reference, payment_method = :method, paid_amount = :amount WHERE id = :id");
    return $stmt->execute([
        ':status' => $status,
        ':reference' => $reference,
        ':method' => $method,
        ':amount' => $amount,
        ':id' => $booking_id
    ]);
}

/**
 * Ensure home service columns exist in bookings table
 */
function ensureHomeServiceColumns() {
    global $db;
    // Add service_type column (shop/home)
    try {
        $db->exec("ALTER TABLE bookings ADD COLUMN service_type VARCHAR(20) DEFAULT 'shop' CHECK(service_type IN ('shop', 'home'))");
    } catch (Exception $e) {
        // Column might already exist
    }
    
    // Add address column for home service
    try {
        $db->exec("ALTER TABLE bookings ADD COLUMN client_address TEXT");
    } catch (Exception $e) {
        // Column might already exist
    }
    
    // Add home_service_fee column
    try {
        $db->exec("ALTER TABLE bookings ADD COLUMN home_service_fee DECIMAL(10,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column might already exist
    }
}

/**
 * Ensure home service availability column exists in barbers table
 */
function ensureBarberHomeServiceColumn() {
    global $db;
    try {
        $db->exec("ALTER TABLE barbers ADD COLUMN offers_home_service INT DEFAULT 0");
    } catch (Exception $e) {
        // Column might already exist
    }
}

/**
 * Get home service fee from settings
 */
function getHomeServiceFee() {
    return (float)getSiteSetting('home_service_fee', '20.00');
}

/**
 * Human-readable list of the weekdays home service runs on, e.g.
 * "Monday, Tuesday, Wednesday, Thursday, Friday".
 */
function formatHomeServiceDays() {
    $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $days = getHomeServiceDays();
    if (count($days) === 7) {
        return 'every day';
    }
    if (count($days) === 0) {
        return 'no days (currently unavailable)';
    }
    sort($days);
    return implode(', ', array_map(function ($d) use ($names) {
        return $names[$d];
    }, $days));
}
function getHomeServiceDays() {
    $raw = getSiteSetting('home_service_days', '1,2,3,4,5');
    $days = array_filter(array_map('intval', explode(',', $raw)), function ($d) {
        return $d >= 0 && $d <= 6;
    });
    return array_values(array_unique($days));
}

/**
 * Whether home service may be booked on the given date (any format strtotime
 * understands, or a DateTime). Searches the next 60 days for the soonest
 * available day when today is not eligible, so the UI can say "next available".
 *
 * @return array{allowed:bool, next_date:?string}
 */
function homeServiceDayStatus($date = null) {
    $days = getHomeServiceDays();

    if ($date instanceof DateTime) {
        $check = clone $date;
    } elseif (is_string($date) && $date !== '') {
        $ts = strtotime($date);
        if ($ts === false) {
            $check = new DateTime();
        } else {
            $check = (new DateTime())->setTimestamp($ts);
        }
    } else {
        $check = new DateTime();
    }

    $allowed = in_array((int)$check->format('w'), $days, true);

    $next_date = null;
    if (!$allowed) {
        $candidate = clone $check;
        for ($i = 0; $i < 60; $i++) {
            $candidate->modify('+1 day');
            if (in_array((int)$candidate->format('w'), $days, true)) {
                $next_date = $candidate->format('Y-m-d');
                break;
            }
        }
    }

    return ['allowed' => $allowed, 'next_date' => $next_date];
}

/**
 * Ensure payments table exists
 */
function ensurePaymentsTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            payment_reference VARCHAR(191) NOT NULL UNIQUE,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending' CHECK(status IN ('pending', 'success', 'failed')),
            gateway_response TEXT,
            paid_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Get booking by payment reference
 */
function getBookingByPaymentReference($reference) {
    global $db;
    $stmt = $db->prepare("
        SELECT b.*, p.amount, p.status as payment_status 
        FROM bookings b
        JOIN payments p ON b.id = p.booking_id
        WHERE p.payment_reference = :reference
    ");
    $stmt->execute([':reference' => $reference]);
    return $stmt->fetch();
}

/**
 * Ensure 2FA table exists
 */
function ensure2FATableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_2fa (
            admin_id INT PRIMARY KEY,
            secret_key VARCHAR(255) NOT NULL,
            is_enabled INT DEFAULT 0,
            backup_codes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Tracks the last successfully consumed TOTP counter so a code that has
    // already been used cannot be replayed within its validity window.
    try {
        $db->exec("ALTER TABLE admin_2fa ADD COLUMN last_used_counter INT DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists
    }
}

/**
 * RFC 4648 base32 encode (no padding), used for authenticator secrets.
 */
function base32Encode($binary) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $bits = 0;
    $value = 0;

    for ($i = 0, $len = strlen($binary); $i < $len; $i++) {
        $value = ($value << 8) | ord($binary[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $out .= $alphabet[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($value << (5 - $bits)) & 31];
    }

    return $out;
}

/**
 * RFC 4648 base32 decode. Returns null when the input is not valid base32
 * (e.g. a secret left over from the old non-standard implementation).
 */
function base32Decode($base32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper(rtrim((string)$base32, '='));
    $base32 = preg_replace('/\s+/', '', $base32);

    if ($base32 === '' || preg_match('/[^A-Z2-7]/', $base32)) {
        return null;
    }

    $bits = 0;
    $value = 0;
    $out = '';
    for ($i = 0, $len = strlen($base32); $i < $len; $i++) {
        $value = ($value << 5) | strpos($alphabet, $base32[$i]);
        $bits += 5;
        if ($bits >= 8) {
            $out .= chr(($value >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }

    return $out;
}

/**
 * Compute a TOTP code for a given counter (RFC 6238 / RFC 4226 HOTP).
 *
 * @param string $secret_binary Raw (decoded) shared secret
 * @param int    $counter       Unix time / time step
 * @param int    $digits        Code length
 * @return string Zero-padded numeric code
 */
function totpCodeAt($secret_binary, $counter, $digits = 6) {
    // 8-byte big-endian counter
    $binary_counter = pack('N*', 0, $counter);

    $hash = hash_hmac('sha1', $binary_counter, $secret_binary, true);

    // Dynamic truncation
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
               | ((ord($hash[$offset + 1]) & 0xFF) << 16)
               | ((ord($hash[$offset + 2]) & 0xFF) << 8)
               | (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Generate a standards-compliant 2FA secret for an admin.
 *
 * Returns a base32 secret that works with Google Authenticator, Authy,
 * Microsoft Authenticator, 1Password, etc.
 */
function generate2FASecret($admin_id) {
    global $db;
    ensure2FATableExists();

    // 20 random bytes = 160-bit secret, the RFC 4226 recommendation
    $secret = base32Encode(random_bytes(20));

    $stmt = $db->prepare("
        INSERT INTO admin_2fa (admin_id, secret_key, is_enabled, last_used_counter)
        VALUES (:admin_id, :secret, 0, 0)
        ON DUPLICATE KEY UPDATE
            secret_key        = VALUES(secret_key),
            is_enabled        = 0,
            last_used_counter = 0
    ");
    $stmt->execute([':admin_id' => $admin_id, ':secret' => $secret]);

    return $secret;
}

/**
 * Build the otpauth:// URI that authenticator apps scan as a QR code.
 *
 * @param int         $admin_id
 * @param string|null $username Defaults to the admin's actual username
 */
function get2FAProvisioningUri($admin_id, $username = null) {
    global $db;

    $secret = get2FASecret($admin_id);
    if (empty($secret)) {
        return '';
    }

    if ($username === null) {
        $stmt = $db->prepare("SELECT username FROM admins WHERE id = :id");
        $stmt->execute([':id' => $admin_id]);
        $username = $stmt->fetchColumn() ?: 'admin';
    }

    $issuer = 'icut';
    $label  = $issuer . ':' . $username;

    return 'otpauth://totp/' . rawurlencode($label)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * True when the stored secret predates the standards-compliant implementation
 * and therefore has to be regenerated before 2FA can be used.
 */
function is2FASecretLegacy($admin_id) {
    $secret = get2FASecret($admin_id);
    return !empty($secret) && base32Decode($secret) === null;
}

/**
 * Enable 2FA for admin
 */
function enable2FA($admin_id) {
    global $db;
    ensure2FATableExists();
    $stmt = $db->prepare("UPDATE admin_2fa SET is_enabled = 1 WHERE admin_id = :admin_id");
    $stmt->execute([':admin_id' => $admin_id]);
}

/**
 * Disable 2FA for admin
 */
function disable2FA($admin_id) {
    global $db;
    ensure2FATableExists();
    $stmt = $db->prepare("UPDATE admin_2fa SET is_enabled = 0 WHERE admin_id = :admin_id");
    $stmt->execute([':admin_id' => $admin_id]);
}

/**
 * Check if 2FA is enabled for admin
 */
function is2FAEnabled($admin_id) {
    global $db;
    ensure2FATableExists();
    $stmt = $db->prepare("SELECT is_enabled FROM admin_2fa WHERE admin_id = :admin_id");
    $stmt->execute([':admin_id' => $admin_id]);
    $result = $stmt->fetch();
    return $result && $result['is_enabled'];
}

/**
 * Verify a 2FA code (RFC 6238 TOTP).
 *
 * - 30 second time step, 6 digits, HMAC-SHA1 (what authenticator apps expect)
 * - Accepts the adjacent steps to tolerate clock drift
 * - Rejects any counter that has already been used, so an observed code
 *   cannot be replayed while it is still inside its window
 * - Falls back to single-use backup codes
 */
function verify2FACode($admin_id, $code) {
    global $db;
    ensure2FATableExists();

    $code = preg_replace('/\D/', '', (string)$code);
    if ($code === '') {
        return false;
    }

    $stmt = $db->prepare("SELECT secret_key, backup_codes, last_used_counter FROM admin_2fa WHERE admin_id = :admin_id");
    $stmt->execute([':admin_id' => $admin_id]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    $secret_binary = base32Decode($row['secret_key']);

    if ($secret_binary !== null && strlen($code) === 6) {
        $time_step   = 30;
        $counter     = (int)floor(time() / $time_step);
        $last_used   = (int)($row['last_used_counter'] ?? 0);
        $drift_steps = 1; // +/- 30s

        for ($offset = -$drift_steps; $offset <= $drift_steps; $offset++) {
            $candidate_counter = $counter + $offset;

            // Replay protection: never accept a counter at or before the last used one
            if ($candidate_counter <= $last_used) {
                continue;
            }

            if (hash_equals(totpCodeAt($secret_binary, $candidate_counter), $code)) {
                $db->prepare("UPDATE admin_2fa SET last_used_counter = :counter WHERE admin_id = :admin_id")
                   ->execute([':counter' => $candidate_counter, ':admin_id' => $admin_id]);
                return true;
            }
        }
    }

    // Backup codes (single use)
    if (!empty($row['backup_codes'])) {
        $codes = json_decode($row['backup_codes'], true);
        if (is_array($codes)) {
            foreach ($codes as $index => $stored_hash) {
                if (is_string($stored_hash) && password_verify($code, $stored_hash)) {
                    unset($codes[$index]);
                    $db->prepare("UPDATE admin_2fa SET backup_codes = :codes WHERE admin_id = :admin_id")
                       ->execute([':codes' => json_encode(array_values($codes)), ':admin_id' => $admin_id]);
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Generate a fresh set of single-use backup codes. Returns the plaintext codes
 * for one-time display; only bcrypt hashes are stored.
 */
function generate2FABackupCodes($admin_id, $count = 8) {
    global $db;
    ensure2FATableExists();

    $plain = [];
    $hashed = [];
    for ($i = 0; $i < $count; $i++) {
        $code = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }

    $db->prepare("UPDATE admin_2fa SET backup_codes = :codes WHERE admin_id = :admin_id")
       ->execute([':codes' => json_encode($hashed), ':admin_id' => $admin_id]);

    return $plain;
}

/**
 * How many unused backup codes remain.
 */
function count2FABackupCodes($admin_id) {
    global $db;
    ensure2FATableExists();
    $stmt = $db->prepare("SELECT backup_codes FROM admin_2fa WHERE admin_id = :admin_id");
    $stmt->execute([':admin_id' => $admin_id]);
    $row = $stmt->fetch();
    if (!$row || empty($row['backup_codes'])) {
        return 0;
    }
    $codes = json_decode($row['backup_codes'], true);
    return is_array($codes) ? count($codes) : 0;
}

/**
 * Get 2FA secret for admin
 */
function get2FASecret($admin_id) {
    global $db;
    ensure2FATableExists();
    $stmt = $db->prepare("SELECT secret_key FROM admin_2fa WHERE admin_id = :admin_id");
    $stmt->execute([':admin_id' => $admin_id]);
    $result = $stmt->fetch();
    return $result ? $result['secret_key'] : null;
}

/**
 * Ensure packages table exists
 */
function ensurePackagesTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            service_ids VARCHAR(191) NOT NULL,
            is_active INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Auto-maintain updated_at with a BEFORE UPDATE trigger (MySQL-style; a
    // BEFORE trigger may modify NEW, which avoids "can't update table in trigger").
    try {
        $db->exec("
            DROP TRIGGER IF EXISTS update_packages_timestamp
        ");
        $db->exec("
            CREATE TRIGGER update_packages_timestamp
            BEFORE UPDATE ON packages
            FOR EACH ROW
            SET NEW.updated_at = CURRENT_TIMESTAMP
        ");
    } catch (Exception $e) {
        // Trigger already exists or the server does not permit creating it
    }
}

/**
 * Get all active packages
 */
function getActivePackages() {
    global $db;
    ensurePackagesTableExists();
    return $db->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY price ASC")->fetchAll();
}

/**
 * Get all packages (including inactive)
 */
function getAllPackages() {
    global $db;
    ensurePackagesTableExists();
    return $db->query("SELECT * FROM packages ORDER BY created_at DESC")->fetchAll();
}

/**
 * Ensure waitlist table exists
 */
function ensureWaitlistTableExists() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS waitlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            client_name VARCHAR(191) NOT NULL,
            client_phone VARCHAR(50) NOT NULL,
            client_email VARCHAR(191),
            preferred_date DATE NOT NULL,
            preferred_time TIME NOT NULL,
            barber_id INT NOT NULL,
            service_id INT NOT NULL,
            status VARCHAR(20) DEFAULT 'waiting' CHECK(status IN ('waiting', 'notified', 'booked', 'expired')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notified_at DATETIME,
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Add client to waitlist
 */
function addToWaitlist($booking_id, $client_name, $client_phone, $client_email, $date, $time, $barber_id, $service_id) {
    global $db;
    ensureWaitlistTableExists();
    $stmt = $db->prepare("INSERT INTO waitlist (booking_id, client_name, client_phone, client_email, preferred_date, preferred_time, barber_id, service_id) VALUES (:booking_id, :name, :phone, :email, :date, :time, :barber_id, :service_id)");
    return $stmt->execute([
        ':booking_id' => $booking_id,
        ':name' => $client_name,
        ':phone' => $client_phone,
        ':email' => $client_email,
        ':date' => $date,
        ':time' => $time,
        ':barber_id' => $barber_id,
        ':service_id' => $service_id
    ]);
}

/**
 * Get next waiting client for a time slot
 */
function getNextWaitlistClient($date, $time, $barber_id) {
    global $db;
    ensureWaitlistTableExists();
    $stmt = $db->prepare("SELECT * FROM waitlist WHERE preferred_date = :date AND preferred_time = :time AND barber_id = :barber_id AND status = 'waiting' ORDER BY created_at ASC LIMIT 1");
    $stmt->execute([':date' => $date, ':time' => $time, ':barber_id' => $barber_id]);
    return $stmt->fetch();
}

/**
 * Update waitlist status
 */
function updateWaitlistStatus($waitlist_id, $status) {
    global $db;
    ensureWaitlistTableExists();
    $stmt = $db->prepare("UPDATE waitlist SET status = :status, notified_at = CASE WHEN :status_check = 'notified' THEN NOW() ELSE notified_at END WHERE id = :id");
    return $stmt->execute([':status' => $status, ':status_check' => $status, ':id' => $waitlist_id]);
}

/**
 * Get waiting clients count
 */
function getWaitingClientsCount() {
    global $db;
    ensureWaitlistTableExists();
    return $db->query("SELECT COUNT(*) FROM waitlist WHERE status = 'waiting'")->fetchColumn();
}

/**
 * Generate CSRF token for form protection
 * 
 * @return string
 */
function generateCSRFToken() {
    if (!isset($_SESSION)) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 * 
 * @param string $token Token to validate
 * @return bool
 */
function validateCSRFToken($token) {
    if (!isset($_SESSION)) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input string
 * 
 * @param string $input
 * @return string
 */
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email address
 * 
 * @param string $email
 * @return bool
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number
 * 
 * @param string $phone
 * @return bool
 */
function validatePhone($phone) {
    return preg_match('/^\+[1-9][0-9\s\-\(\)]{7,20}$/', $phone);
}

/**
 * Check rate limiting.
 *
 * Attempts are recorded in the database keyed by action + client IP, NOT in the
 * session. A session-based counter is trivially bypassed: an attacker who simply
 * discards the session cookie starts from an empty bucket on every request,
 * which made the login throttle useless.
 *
 * @param string $key          Rate limit key (e.g. 'login', 'booking')
 * @param int    $max_requests Maximum requests allowed in the window
 * @param int    $window       Time window in seconds
 * @return bool True if the request is allowed, false if rate limited
 */
function checkRateLimit($key, $max_requests = 5, $window = 300) {
    global $db;

    ensureRateLimitTableExists();

    // Bucket by action + client IP so it survives cookie/session discarding
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $bucket = $key . '|' . $ip;

    try {
        $cutoff_window = (int)max($window, 3600);
        $cutoff_ts = date('Y-m-d H:i:s', time() - $cutoff_window);
        $window_ts = date('Y-m-d H:i:s', time() - (int)$window);

        $db->prepare("DELETE FROM rate_limits WHERE attempted_at < :cutoff")
           ->execute([':cutoff' => $cutoff_ts]);

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM rate_limits
            WHERE bucket = :bucket AND attempted_at >= :window
        ");
        $stmt->execute([':bucket' => $bucket, ':window' => $window_ts]);

        if ((int)$stmt->fetchColumn() >= $max_requests) {
            return false;
        }

        $db->prepare("INSERT INTO rate_limits (bucket, attempted_at) VALUES (:bucket, NOW())")
           ->execute([':bucket' => $bucket]);

        return true;
    } catch (Exception $e) {
        error_log('Rate limit check failed: ' . $e->getMessage());
        // Fail open rather than locking every user out on a storage error
        return true;
    }
}

/**
 * Clear the rate limit bucket for the current client, e.g. after a successful
 * login so a legitimate user is not punished for earlier typos.
 */
function clearRateLimit($key) {
    global $db;
    ensureRateLimitTableExists();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    try {
        $db->prepare("DELETE FROM rate_limits WHERE bucket = :bucket")
           ->execute([':bucket' => $key . '|' . $ip]);
    } catch (Exception $e) {
        error_log('Rate limit clear failed: ' . $e->getMessage());
    }
}

/**
 * Validate uploaded file
 * 
 * @param array $file $_FILES array element
 * @param array $allowed_extensions Allowed file extensions
 * @param int $max_size Maximum file size in bytes
 * @return array ['success' => bool, 'error' => string]
 */
function validateUpload($file, $allowed_extensions = [], $max_size = 5242880) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed. Please try again.'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'File is too large. Maximum size is ' . formatBytes($max_size) . '.'];
    }
    
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!empty($allowed_extensions) && !in_array($file_ext, $allowed_extensions)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowed_extensions)];
    }
    
    // Check if the file is actually an image using getimagesize
    if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $image_info = @getimagesize($file['tmp_name']);
        if (!$image_info) {
            return ['success' => false, 'error' => 'Invalid image file.'];
        }
    }
    
    // Check file content for PHP code
    $file_content = file_get_contents($file['tmp_name']);
    if (preg_match('/<\?(php|\s)/i', $file_content)) {
        return ['success' => false, 'error' => 'Invalid file content.'];
    }
    
    return ['success' => true, 'error' => ''];
}

/**
 * Format bytes to human readable
 * 
 * @param int $bytes
 * @return string
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = 0;
    while ($bytes >= 1024 && $pow < count($units) - 1) {
        $bytes /= 1024;
        $pow++;
    }
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Generate secure random token
 * 
 * @param int $length Token length
 * @return string
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Send booking status update email to client
 * 
 * @param array $booking Booking data
 * @param string $status New status
 * @return bool
 */
function sendStatusNotification($booking, $status) {
    $to = $booking['client_email'] ?? '';
    if (empty($to)) {
        return false;
    }
    
    $booking_ref = $booking['booking_reference'] ?? generateBookingReference();
    $status_labels = [
        'pending' => 'Pending Review',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled'
    ];
    $status_label = $status_labels[$status] ?? ucfirst($status);
    $subject = getSiteSetting('email_subject_status', 'Booking Update {booking_reference} - {status}');
    $body = getSiteSetting('email_body_status', getDefaultStatusTemplate());
    
    $date = date('F j, Y', strtotime($booking['booking_date']));
    $time = date('g:i A', strtotime($booking['booking_time']));
    
    // Replace placeholders
    $placeholders = [
        '{client_name}' => $booking['client_name'],
        '{booking_reference}' => $booking_ref,
        '{service_name}' => $booking['service_name'],
        '{barber_name}' => $booking['barber_name'],
        '{date}' => $date,
        '{time}' => $time,
        '{price}' => '₵' . number_format($booking['service_price'], 2),
        '{status}' => $status_label
    ];
    
    $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);
    
    return sendEmailNotification($to, $subject, $body);
}

/**
 * Log admin activity for audit purposes
 * 
 * @param string $type Activity type
 * @param string $admin_name Admin name
 * @param string $details Activity details
 * @param int $reference_id Reference ID
 * @return bool
 */
function logAdminActivity($type, $admin_name, $details, $reference_id = 0) {
    global $db;
    try {
        $stmt = $db->prepare("INSERT INTO admin_activity_log (activity_type, admin_name, details, booking_id, ip_address, created_at) VALUES (:type, :admin, :details, :ref_id, :ip, NOW())");
        $stmt->execute([
            ':type' => $type,
            ':admin' => $admin_name,
            ':details' => $details,
            ':ref_id' => $reference_id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send calendar invite (ICS) via email
 * 
 * @param array $booking Booking data
 * @return bool
 */
function sendCalendarInvite($booking) {
    $to = $booking['client_email'] ?? '';
    if (empty($to)) {
        return false;
    }
    
    $booking_ref = $booking['booking_reference'] ?? generateBookingReference();
    $start_date = $booking['booking_date'];
    $start_time = $booking['booking_time'];
    $duration = $booking['duration_minutes'] ?? 60;
    
    $start_dt = DateTime::createFromFormat('Y-m-d H:i:s', $start_date . ' ' . $start_time);
    if (!$start_dt) {
        $start_dt = DateTime::createFromFormat('Y-m-d H:i', $start_date . ' ' . $start_time);
    }
    if (!$start_dt) {
        error_log("Invalid booking time format for calendar invite: {$start_date} {$start_time}");
        return false;
    }
    
    $end_dt = clone $start_dt;
    $end_dt->add(new DateInterval("PT{$duration}M"));
    
    $dtstart = $start_dt->format('Ymd\THis\Z');
    $dtend = $end_dt->format('Ymd\THis\Z');
    
    $ics_content = "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//icut//Booking System//EN
BEGIN:VEVENT
UID:{$booking_ref}@icut.com
DTSTAMP:" . date('Ymd\THis\Z') . "
DTSTART:{$dtstart}
DTEND:{$dtend}
SUMMARY:Barbershop Appointment - {$booking['service_name']}
DESCRIPTION:Appointment with {$booking['barber_name']}\nReference: {$booking_ref}\nPrice: ₵" . number_format($booking['service_price'], 2) . "
LOCATION:icut Barbershop
END:VEVENT
END:VCALENDAR";
    
    $subject = "Appointment Confirmation " . $booking_ref . " - Add to Calendar";
    $message = "
    <html>
    <head><title>Appointment Calendar Invite</title></head>
    <body style='font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;'>
        <div style='max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;'>
            <h2 style='color: #c9a96e;'>icut</h2>
            <h3>Add to Your Calendar</h3>
            <p>Dear {$booking['client_name']},</p>
            <p>Your appointment has been booked successfully. Reference: <strong>{$booking_ref}</strong></p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Service:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['service_name']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Barber:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>{$booking['barber_name']}</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Date:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>" . date('F j, Y', strtotime($booking['booking_date'])) . "</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Time:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>" . date('g:i A', strtotime($booking['booking_time'])) . "</td></tr>
                <tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Price:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>₵" . number_format($booking['service_price'], 2) . "</td></tr>
            </table>
            <p>Please download the attached calendar file to add this appointment to your calendar.</p>
            <p style='color: #c9a96e;'>Thank you for choosing icut!</p>
        </div>
    </body>
    </html>";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: icut <noreply@icut.com>" . "\r\n";
    $headers .= "Reply-To: " . getSiteSetting('email', 'noreply@icut.com') . "\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"boundary_string\"" . "\r\n";
    
    $body = "--boundary_string\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $message . "\r\n\r\n";
    $body .= "--boundary_string\r\n";
    $body .= "Content-Type: text/calendar; name=\"appointment.ics\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"appointment.ics\"\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $ics_content . "\r\n";
    $body .= "--boundary_string--\r\n";
    
    // Use SMTP mailer if configured
    if (!empty(env('SMTP_HOST', ''))) {
        require_once __DIR__ . '/smtp_mailer.php';
        $mailer = new SmtpMailer();
        return $mailer->send($to, $subject, $body);
    }
    
    try {
        $result = @mail($to, $subject, $body, $headers);
        if (!$result) {
            error_log("Calendar invite email failed to {$to}: mail() returned false. Configure SMTP in .env for reliable delivery.");
        }
        return $result;
    } catch (Exception $e) {
        error_log("Calendar invite email failed to {$to}: " . $e->getMessage());
        return false;
    }
}

/**
 * Get default confirmation email template
 */
function getDefaultConfirmationTemplate() {
    return '<html><head><title>Booking Confirmation</title></head><body style="font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;"><div style="max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;"><h2 style="color: #c9a96e;">icut</h2><h3>Booking Confirmation</h3><p>Dear {client_name},</p><p>Your appointment has been booked successfully. Here are the details:</p><table style="width: 100%; border-collapse: collapse; margin: 20px 0;"><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Reference:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{booking_reference}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Service:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{service_name}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Barber:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{barber_name}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Date:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{date}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Time:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{time}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Price:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{price}</td></tr></table><p>We will confirm your appointment shortly via WhatsApp.</p><p style="color: #c9a96e;">Thank you for choosing icut!</p></div></body></html>';
}

/**
 * Get default status update email template
 */
function getDefaultStatusTemplate() {
    return '<html><head><title>Booking Status Update</title></head><body style="font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;"><div style="max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;"><h2 style="color: #c9a96e;">icut</h2><h3>Booking Status Update</h3><p>Dear {client_name},</p><p>Your booking <strong>{booking_reference}</strong> has been updated:</p><p style="font-size: 18px; color: #c9a96e; font-weight: bold;">Status: {status}</p><table style="width: 100%; border-collapse: collapse; margin: 20px 0;"><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Service:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{service_name}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Barber:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{barber_name}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Date:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{date}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Time:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{time}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Price:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{price}</td></tr></table><p style="color: #c9a96e;">Thank you for choosing icut!</p><p style="font-size: 12px; color: #888;">Need to contact us? WhatsApp us at ' . getSiteSetting('phone', '') . '</p></div></body></html>';
}

/**
 * Get default reminder email template
 */
function getDefaultReminderTemplate() {
    return '<html><head><title>Appointment Reminder</title></head><body style="font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;"><div style="max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;"><h2 style="color: #c9a96e;">icut</h2><h3>🔔 Appointment Reminder</h3><p>Hi {client_name},</p><p>This is a friendly reminder that you have an appointment <strong>tomorrow</strong>:</p><table style="width: 100%; border-collapse: collapse; margin: 20px 0;"><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Reference:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{booking_reference}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Service:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{service_name}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Barber:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{barber_name}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Date:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{date}</td></tr><tr><td style="padding: 10px; border: 1px solid #404040;"><strong>Time:</strong></td><td style="padding: 10px; border: 1px solid #404040;">{time}</td></tr></table><p>Need to reschedule or cancel? Please contact us as soon as possible.</p><p style="color: #c9a96e;">See you tomorrow!</p><p style="font-size: 12px; color: #888;">Questions? WhatsApp us at ' . getSiteSetting('phone', '') . '</p></div></body></html>';
}

/**
 * Send booking confirmation email using custom template
 */
function sendCustomConfirmation($booking) {
    $to = $booking['client_email'] ?? '';
    if (empty($to)) return false;
    
    $booking_ref = $booking['booking_reference'] ?? generateBookingReference();
    $subject = getSiteSetting('email_subject_confirmation', 'Booking Confirmation {booking_reference} - icut');
    $body = getSiteSetting('email_body_confirmation', getDefaultConfirmationTemplate());
    
    // Replace placeholders
    $placeholders = [
        '{client_name}' => $booking['client_name'],
        '{booking_reference}' => $booking_ref,
        '{service_name}' => $booking['service_name'],
        '{barber_name}' => $booking['barber_name'],
        '{date}' => date('F j, Y', strtotime($booking['booking_date'])),
        '{time}' => date('g:i A', strtotime($booking['booking_time'])),
        '{price}' => '₵' . number_format($booking['service_price'], 2)
    ];
    
    $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);
    
    return sendEmailNotification($to, $subject, $body);
}

/**
 * Clean up abandoned pending bookings older than 90 minutes that have no payment record
 */
function cleanupPendingBookings() {
    global $db;
    $cutoff = date('Y-m-d H:i:s', time() - 90 * 60);
    $stmt = $db->prepare("
        DELETE FROM bookings 
        WHERE status = 'pending' 
        AND created_at < :cutoff
        AND (payment_reference IS NULL OR payment_status = 'pending' OR payment_status = 'failed')
    ");
    $stmt->execute([':cutoff' => $cutoff]);
    return $stmt->rowCount();
}