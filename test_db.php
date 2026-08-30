<?php
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain');

echo "=== icut Database Connectivity Test ===\n\n";

try {
    $db = getDatabaseConnection();
    echo "Database connection: OK\n";

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables found: " . count($tables) . "\n";
    foreach ($tables as $table) {
        $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "  - $table ($count rows)\n";
    }

    echo "\nRate limit test (should not error):\n";
    $ok = checkRateLimit('test_probe_' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 1, 60);
    echo "  checkRateLimit returned: " . ($ok ? 'true (allowed)' : 'false (blocked)') . "\n";

    echo "\nAll checks passed.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
