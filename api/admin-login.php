<?php
/**
 * Vercel serverless function: Admin login page (HTML)
 * Route: GET /api/admin-login (renders form) / POST /api/admin-login (authenticates)
 *
 * Session-based login is NOT used here because PHP sessions do not persist
 * reliably across serverless function invocations. Instead we authenticate and
 * issue the JWT cookie via the shared JWT stack. CSRF is protected with a
 * double-submit cookie (an httpOnly cookie + matching hidden form field), which
 * survives serverless round-trips.
 */

require_once dirname(__DIR__) . '/lib/env.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

$db = getDatabaseConnection();
$error = '';

// Hidden admin entry gate (same behaviour as the classic admin_login.php):
// only render/login when the URL carries ?a=<ADMIN_ENTRY_KEY>, or when the
// admin is already authenticated.
$admin_entry_key = trim(env('ADMIN_ENTRY_KEY', 'icitboss'));
$entered_key = trim((string)($_GET['a'] ?? ($_POST['entry_key'] ?? '')));
$gate_ok = $admin_entry_key !== '' && hash_equals($admin_entry_key, $entered_key);
$current_user = getCurrentUser();
$already_in = $current_user && ($current_user['role'] ?? '') === 'admin';

if (!$gate_ok && !$already_in) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    exit;
}

// Already authenticated -> go to dashboard.
if ($already_in) {
    header('Location: /api/admin-dashboard');
    exit;
}

// CSRF helper using double-submit cookie (survives serverless, no server-side
// session storage needed).
function adminLoginCsrfSet() {
    if (empty($_COOKIE['admin_csrf']) || strlen($_COOKIE['admin_csrf']) !== 64) {
        $token = bin2hex(random_bytes(32));
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('admin_csrf', $token, [
            'expires' => time() + 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['admin_csrf'] = $token;
    }
}
function adminLoginCsrfCheck($posted) {
    return isset($_COOKIE['admin_csrf'])
        && is_string($_COOKIE['admin_csrf'])
        && is_string($posted)
        && hash_equals($_COOKIE['admin_csrf'], $posted);
}

$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!$gate_ok) {
        $error = 'Invalid admin access key.';
    } elseif (!adminLoginCsrfCheck($csrf_token)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (!checkRateLimit('login', 5, 300)) {
        $error = 'Too many login attempts. Please try again later.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        $stmt = $db->prepare("
            SELECT id, username, password_hash, full_name, failed_attempts, locked_until
            FROM admins
            WHERE username = :username OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(:email))
            LIMIT 1
        ");
        $stmt->execute([':username' => $username, ':email' => $username]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $error = 'Invalid credentials';
        } elseif ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
            $error = 'Account is locked due to too many failed attempts. Please try again later.';
        } elseif (!password_verify($password, $admin['password_hash'])) {
            $failed_attempts = (int)($admin['failed_attempts'] ?? 0) + 1;
            if ($failed_attempts >= 5) {
                $db->prepare("UPDATE admins SET failed_attempts = :attempts, locked_until = :locked WHERE id = :id")
                   ->execute([':attempts' => $failed_attempts, ':locked' => date('Y-m-d H:i:s', time() + 900), ':id' => $admin['id']]);
            } else {
                $db->prepare("UPDATE admins SET failed_attempts = :attempts WHERE id = :id")
                   ->execute([':attempts' => $failed_attempts, ':id' => $admin['id']]);
            }
            $error = 'Invalid credentials';
        } else {
            $db->prepare("UPDATE admins SET failed_attempts = 0, locked_until = NULL WHERE id = :id")
               ->execute([':id' => $admin['id']]);

            $secret = env('JWT_SECRET', 'change-me-in-production');
            $payload = [
                'user_id' => $admin['id'],
                'username' => $admin['username'],
                'role' => 'admin',
                'name' => $admin['full_name'],
            ];
            $token = generateJWT($payload, $secret);
            setJWTCookie($token);
            logAdminActivity('admin_login', $admin['full_name'], 'Admin login successful');

            header('Location: /api/admin-dashboard');
            exit;
        }
    }
}

// Ensure the CSRF cookie is set for the rendered form (called before output).
adminLoginCsrfSet();
$csrf_token = $_COOKIE['admin_csrf'];
$site_name = trim(env('SITE_NAME', '')) ?: 'icut';
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login &middot; <?php echo htmlspecialchars($site_name); ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white min-h-screen flex items-center justify-center px-4">
    <form method="POST" action="/api/admin-login?a=<?php echo rawurlencode(env('ADMIN_ENTRY_KEY', 'icitboss')); ?>" class="w-full max-w-md bg-gray-800 rounded-2xl p-8 border border-gray-700 shadow-2xl">
        <input type="hidden" name="entry_key" value="<?php echo htmlspecialchars($entered_key); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <h1 class="text-3xl font-bold text-white mb-1">Admin Login</h1>
        <p class="text-gray-400 mb-8"><?php echo htmlspecialchars($site_name); ?></p>

        <?php if ($error): ?>
            <div class="mb-6 bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="space-y-5">
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Username or Email</label>
                <input type="text" name="username" required autocomplete="username"
                       value="<?php echo htmlspecialchars($username); ?>"
                       class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-amber-400 transition"
                       placeholder="Enter username or email">
            </div>
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Password</label>
                <input type="password" name="password" required autocomplete="current-password"
                       class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-amber-400 transition"
                       placeholder="Enter password">
            </div>
            <button type="submit"
                    class="w-full bg-amber-500 hover:bg-amber-400 text-gray-900 font-bold py-3 px-6 rounded-lg transition duration-300">
                Sign In
            </button>
        </div>

        <div class="mt-6 text-center">
            <a href="/" class="text-amber-400 hover:text-amber-300 text-sm transition">&larr; Back to Booking Page</a>
        </div>
    </form>
</body>
</html>

            header('Location: /api/admin-dashboard');
            exit;
        }
    }
}

// Ensure the CSRF cookie is set for the rendered form (called before output).
adminLoginCsrfSet();
$csrf_token = $_COOKIE['admin_csrf'];
$site_name = trim(env('SITE_NAME', '')) ?: 'icut';
header('Content-Type: text/html; charset=UTF-8');
?>
