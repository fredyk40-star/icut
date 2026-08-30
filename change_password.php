<?php
require_once 'admin_auth.php';

$error = '';
$success = '';

// Fetch current admin data
$stmt = $db->prepare("SELECT username, email, full_name FROM admins WHERE id = :id");
$stmt->execute([':id' => $_SESSION['admin_id']]);
$admin_data = $stmt->fetch();
$current_username = $admin_data['username'] ?? '';
$current_email = $admin_data['email'] ?? '';
$current_full_name = $admin_data['full_name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $new_username = trim($_POST['new_username'] ?? '');
        $new_email = trim($_POST['new_email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($new_username)) {
            $error = 'Username is required';
        } elseif (empty($current_password)) {
            $error = 'Current password is required to make changes';
        } elseif ($new_email !== '' && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } elseif (!empty($new_password) && strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters';
        } elseif (!empty($new_password) && !preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $new_password)) {
            $error = 'New password must contain at least one letter and one number';
        } elseif (!empty($new_password) && $new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        } else {
            // If email is changing, make sure it's not already used by another admin
            if ($new_email !== '' && strtolower($new_email) !== strtolower($current_email)) {
                $dup = $db->prepare("SELECT COUNT(*) FROM admins WHERE LOWER(email) = LOWER(:email) AND id != :id");
                $dup->execute([':email' => $new_email, ':id' => $_SESSION['admin_id']]);
                if ($dup->fetchColumn() > 0) {
                    $error = 'That email is already in use by another admin account';
                }
            }

            if (!$error) {
                $stmt = $db->prepare("SELECT password_hash FROM admins WHERE id = :id");
                $stmt->execute([':id' => $_SESSION['admin_id']]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($current_password, $admin['password_hash'])) {
                    if (!empty($new_password)) {
                        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_stmt = $db->prepare("UPDATE admins SET password_hash = :password, must_change_password = 0 WHERE id = :id");
                        $update_stmt->execute([
                            ':password' => $new_hash,
                            ':id' => $_SESSION['admin_id']
                        ]);
                        unset($_SESSION['must_change_password']);
                    }

                    $username_changed = $new_username !== $current_username;
                    $email_changed = $new_email !== $current_email;

                    if ($username_changed && $email_changed) {
                        $update_stmt = $db->prepare("UPDATE admins SET username = :username, email = :email WHERE id = :id");
                        $update_stmt->execute([
                            ':username' => $new_username,
                            ':email' => $new_email,
                            ':id' => $_SESSION['admin_id']
                        ]);
                    } elseif ($username_changed) {
                        $update_stmt = $db->prepare("UPDATE admins SET username = :username WHERE id = :id");
                        $update_stmt->execute([
                            ':username' => $new_username,
                            ':id' => $_SESSION['admin_id']
                        ]);
                    } elseif ($email_changed) {
                        $update_stmt = $db->prepare("UPDATE admins SET email = :email WHERE id = :id");
                        $update_stmt->execute([
                            ':email' => $new_email,
                            ':id' => $_SESSION['admin_id']
                        ]);
                    }

                    if ($username_changed) {
                        $_SESSION['admin_name'] = $new_username;
                    }

                    logAdminActivity('account_update', $_SESSION['admin_name'], "Account settings updated");
                    $success = 'Account updated successfully!';
                    $current_username = $new_username;
                    $current_email = $new_email;
                } else {
                    $error = 'Current password is incorrect';
                }
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
    <title>Change Password</title>
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
<body class="bg-barber-900 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-barber-800 rounded-2xl p-8 border border-barber-700">
            <h1 class="text-2xl font-bold text-white mb-6">Account Settings</h1>
            
            <?php if ($error): ?>
                <div class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-lg mb-4">
                    <?php echo htmlspecialchars($success); ?>
                    <a href="admin.php" class="block mt-2 text-barber-gold hover:text-barber-gold-light">← Back to Dashboard</a>
                </div>
            <?php else: ?>
                <form id="change-password-form" method="POST" action="" class="space-y-4">`n                        <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Username *</label>
                        <input type="text" name="new_username" value="<?php echo htmlspecialchars($current_username); ?>" required 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Email</label>
                        <input type="email" name="new_email" value="<?php echo htmlspecialchars($current_email); ?>" 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Current Password *</label>
                        <input type="password" name="current_password" required 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">New Password (leave blank to keep current)</label>
                        <input type="password" name="new_password" 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm mb-2">Confirm New Password</label>
                        <input type="password" name="confirm_password" 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                    </div>
                    <button type="submit" 
                            class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">
                        Update Account
                    </button>
                </form>
                <a href="admin.php" class="block text-center text-gray-400 hover:text-white mt-4">← Back to Dashboard</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // AJAX form handling for Vercel compatibility
    (function() {
        const form = document.getElementById('change-password-form');
        if (!form) return;

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Updating...';

            try {
                const response = await fetch('/api/change-password', {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' }
                });
                const result = await response.json();

                if (result.success) {
                    alert(result.message);
                    if (result.force_change) {
                        location.reload();
                    }
                } else {
                    alert(result.error || 'Update failed');
                }
            } catch (error) {
                alert('Network error. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    })();
    </script>
</body>
</html>