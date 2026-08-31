<?php
/**
 * Vercel serverless function: Admin dashboard
 * Route: GET /api/admin
 */

require_once dirname(__DIR__) . '/lib/env.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/middleware/auth.php';

loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json');

$user = requireAdminAuth();

$db = getDatabaseConnection();

// Fetch statistics
$stats = [];
$stats['total_bookings'] = (int)$db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$stats['pending_bookings'] = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
$stats['confirmed_bookings'] = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'")->fetchColumn();
$stats['completed_bookings'] = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status = 'completed'")->fetchColumn();
$stats['cancelled_bookings'] = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status = 'cancelled'")->fetchColumn();
$stats['total_revenue'] = (float)$db->query("SELECT SUM(paid_amount) FROM payments WHERE status = 'success'")->fetchColumn();
$stats['pending_reviews'] = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE is_approved = 0")->fetchColumn();

// Fetch recent bookings
$recent_bookings = $db->query("
    SELECT b.*, br.name as barber_name, s.name as service_name 
    FROM bookings b
    LEFT JOIN barbers br ON b.barber_id = br.id
    LEFT JOIN services s ON b.service_id = s.id
    ORDER BY b.created_at DESC
    LIMIT 20
")->fetchAll();

echo json_encode([
    'success' => true,
    'user' => $user,
    'stats' => $stats,
    'recent_bookings' => $recent_bookings
]);
