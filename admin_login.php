<?php
session_start();
require_once 'db.php';

// Hidden admin entry gate. The login form only renders when the URL carries the
// correct ?a=<ADMIN_ENTRY_KEY> (configured in .env). This hides the admin panel
// from casual visitors (security-through-obscurity); it is not a substitute for
// a strong password, so keep that strong as well.
$admin_entry_key = trim(env('ADMIN_ENTRY_KEY', 'icitboss'));
$entered_key = trim((string)($_GET['a'] ?? ''));
$gate_ok = $admin_entry_key !== '' && hash_equals($admin_entry_key, $entered_key);

// Allow the request through if the gate key is correct, or if an admin session
// already exists (fully logged in, or mid-2FA with a pending session). This lets
// the 2FA verification POST complete without re-sending the key.
$admin_session = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
              || isset($_SESSION['2fa_pending']);
if (!$gate_ok && !$admin_session) {
    // Send a generic 404 so the page does not even reveal it is an admin login
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found";
    exit;
}

// Rate limiting for login attempts
if (!checkRateLimit('login', 5, 300)) {
    $error = 'Too many login attempts. Please try again later.';
} else {
    $error = '';
}

// If already logged in, redirect to admin dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin.php');
    exit;
}

// Handle reset 2FA pending
if (isset($_POST['reset_2fa'])) {
    unset($_SESSION['2fa_pending']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $twofa_code = $_POST['twofa_code'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password';
        } elseif (isset($_SESSION['2fa_pending'])) {
            // 2FA verification step
            if (verify2FACode($_SESSION['2fa_pending']['admin_id'], $twofa_code)) {
                $admin = $_SESSION['2fa_pending'];
                unset($_SESSION['2fa_pending']);
                
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['login_time'] = time();
                $_SESSION['must_change_password'] = !empty($admin['must_change_password']);
                
                logAdminActivity('admin_login', $admin['full_name'], "Admin login successful with 2FA");
                header('Location: admin.php');
                exit;
            } else {
                $error = 'Invalid 2FA code';
            }
        } else {
            // Admins may log in with their username OR their email address
            $stmt = $db->prepare("
                SELECT id, username, password_hash, full_name, failed_attempts, locked_until, must_change_password
                FROM admins
                WHERE username = :username OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(:email))
                LIMIT 1
            ");
            $stmt->execute([':username' => $username, ':email' => $username]);
            $admin = $stmt->fetch();
            
            if ($admin) {
                // Check if account is locked
                if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
                    $error = 'Account is locked due to too many failed attempts. Please try again later.';
                } elseif (password_verify($password, $admin['password_hash'])) {
                    // Reset failed attempts on successful login
                    $db->prepare("UPDATE admins SET failed_attempts = 0, locked_until = NULL WHERE id = :id")->execute([':id' => $admin['id']]);
                    clearRateLimit('login');
                    
                    // Check if 2FA is enabled
                    if (is2FAEnabled($admin['id'])) {
                        // Store pending admin and show 2FA form
                        $_SESSION['2fa_pending'] = [
                            'admin_id' => $admin['id'],
                            'full_name' => $admin['full_name'],
                            'must_change_password' => !empty($admin['must_change_password'])
                        ];
                        $show_2fa_form = true;
                    } else {
                        // No 2FA, log in directly
                        session_regenerate_id(true);
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_name'] = $admin['full_name'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['must_change_password'] = !empty($admin['must_change_password']);
                        
                        logAdminActivity('admin_login', $admin['full_name'], "Admin login successful");
                        header('Location: admin.php');
                        exit;
                    }
                } else {
                    // Increment failed attempts
                    $failed_attempts = ($admin['failed_attempts'] ?? 0) + 1;
                    $locked_until = null;
                    if ($failed_attempts >= 5) {
                        $locked_until = date('Y-m-d H:i:s', time() + 900); // 15 minutes
                    }
                    $db->prepare("UPDATE admins SET failed_attempts = :attempts, locked_until = :locked WHERE id = :id")->execute([
                        ':attempts' => $failed_attempts,
                        ':locked' => $locked_until,
                        ':id' => $admin['id']
                    ]);
                    $error = 'Invalid username or password';
                }
            } else {
                $error = 'Invalid username or password';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - icut</title>
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
<body class="bg-barber-900 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="text-center mb-8">
            <svg class="w-16 h-16 text-barber-gold mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
            </svg>
            <h1 class="text-3xl font-bold text-white">Admin Login</h1>
            <p class="text-gray-400 mt-2">icut</p>
        </div>
        
        <div class="bg-barber-800 rounded-2xl shadow-2xl p-8 border border-barber-700">
            <?php if (!empty($error)): ?>
                <div class="mb-6 bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg text-sm">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div>
                    <label class="block text-gray-300 text-sm font-medium mb-2">Username or Email</label>
                    <input type="text" name="username" required 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           autocomplete="username"
                           class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                           placeholder="Enter username or email" <?php echo isset($show_2fa_form) ? 'readonly' : ''; ?>>
                </div>
                <div>
                    <label class="block text-gray-300 text-sm font-medium mb-2">Password</label>
                    <input type="password" name="password" required 
                           class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-barber-gold transition"
                           placeholder="Enter password" <?php echo isset($show_2fa_form) ? 'readonly' : ''; ?>>
                </div>
                <?php if (isset($show_2fa_form)): ?>
                    <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-4">
                        <label class="block text-barber-gold text-sm font-medium mb-2">🔐 Two-Factor Authentication Code</label>
                        <input type="text" name="twofa_code" required 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white text-center text-2xl tracking-widest"
                               placeholder="000000" maxlength="6" autocomplete="off">
                        <p class="text-gray-400 text-xs mt-2">Enter the 6-digit code from your authenticator app</p>
                    </div>
                <?php endif; ?>
                <button type="submit" 
                        class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 px-6 rounded-lg transition duration-300">
                    <?php echo isset($show_2fa_form) ? 'Verify Code' : 'Sign In'; ?>
                </button>
                <?php if (isset($show_2fa_form)): ?>
                    <button type="submit" name="reset_2fa" value="1" class="w-full bg-barber-700 hover:bg-barber-600 text-white py-2 px-6 rounded-lg text-sm transition">
                        ← Back to login
                    </button>
                <?php endif; ?>
            </form>
            
            <div class="mt-6 text-center">
                <a href="index.php" class="text-barber-gold hover:text-barber-gold-light text-sm transition">
                    ← Back to Booking Page
                </a>
            </div>
        </div>
        
    </div>
</body>
</html>
