<?php
header('Content-Type: text/plain');

echo "Testing getDatabaseConnection...\n\n";

require_once dirname(__DIR__) . '/lib/env.php';
require_once dirname(__DIR__) . '/lib/db.php';

loadEnv(__DIR__ . '/../.env');

$host = env('MYSQL_HOST', 'localhost');
$port = env('MYSQL_PORT', '3306');
$name = env('MYSQL_NAME', 'barbershop_db');
$user = env('MYSQL_USER', 'root');
$pass = env('MYSQL_PASS', '');
$charset = env('MYSQL_CHARSET', 'utf8mb4');
$ssl = env('MYSQL_SSL', '0');

echo "Host: $host\n";
echo "SSL env: $ssl\n";

// Auto-enable SSL for TiDB Cloud hosts
if (strpos($host, 'tidbcloud.com') !== false || strpos($host, 'tidbcloud') !== false) {
    $ssl = '1';
    echo "Auto-enabled SSL for TiDB Cloud\n";
}
echo "SSL: $ssl\n\n";

// Check CA detection
$ca = env('MYSQL_SSL_CA', '');
echo "MYSQL_SSL_CA env: '$ca'\n";
if ($ca === '') {
    $candidate_paths = [
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/ssl/cert.pem',
        '/etc/ssl/ca-bundle.pem',
    ];
    echo "Searching for CA bundle...\n";
    foreach ($candidate_paths as $candidate) {
        if (is_readable($candidate)) {
            $ca = $candidate;
            echo "Found CA at: $ca\n";
            break;
        } else {
            echo "  Not found: $candidate\n";
        }
    }
}
echo "\nFinal CA: $ca\n";
echo "CA readable: " . (is_readable($ca) ? 'yes' : 'no') . "\n";

echo "\nNow trying getDatabaseConnection()...\n";
try {
    $db = getDatabaseConnection();
    echo "RESULT: SUCCESS!\n";
    $row = $db->query("SELECT DATABASE()")->fetch();
    print_r($row);
} catch (Exception $e) {
    echo "RESULT: FAILED - " . $e->getMessage() . "\n";
}
