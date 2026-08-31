<?php
/**
 * Authentication middleware for Vercel serverless functions
 */

require_once dirname(__DIR__) . '/lib/jwt.php';

function requireAdminAuth() {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'redirect' => '/admin-login']);
        exit;
    }
    return $user;
}

function checkRateLimit($key, $max_requests = 5, $window = 300) {
    $db = getDatabaseConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $bucket = $key . '|' . $ip;
    
    try {
        $cutoff = date('Y-m-d H:i:s', time() - max($window, 3600));
        $windowStart = date('Y-m-d H:i:s', time() - (int)$window);
        
        $db->prepare("DELETE FROM rate_limits WHERE attempted_at < :cutoff")
           ->execute([':cutoff' => $cutoff]);
        
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM rate_limits
            WHERE bucket = :bucket AND attempted_at >= :window
        ");
        $stmt->execute([':bucket' => $bucket, ':window' => $windowStart]);
        
        if ((int)$stmt->fetchColumn() >= $max_requests) {
            return false;
        }
        
        $db->prepare("INSERT INTO rate_limits (bucket, attempted_at) VALUES (:bucket, NOW())")
           ->execute([':bucket' => $bucket]);
        
        return true;
    } catch (Exception $e) {
        error_log('Rate limit check failed: ' . $e->getMessage());
        return true;
    }
}

function logAdminActivity($type, $admin_name, $details, $reference_id = 0) {
    $db = getDatabaseConnection();
    try {
        $stmt = $db->prepare("
            INSERT INTO admin_activity_log (activity_type, admin_name, details, booking_id, ip_address, created_at)
            VALUES (:type, :admin, :details, :ref_id, :ip, NOW())
        ");
        $stmt->execute([
            ':type' => $type,
            ':admin' => $admin_name,
            ':details' => $details,
            ':ref_id' => $reference_id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}
