<?php
/**
 * Vercel-compatible database connection
 * Uses the same PDO connection as db.php but optimized for serverless
 */

function getDatabaseConnection() {
    static $db = null;

    if ($db !== null) {
        return $db;
    }

    $host = env('MYSQL_HOST', 'localhost');
    $port = env('MYSQL_PORT', '3306');
    $name = env('MYSQL_NAME', 'barbershop_db');
    $user = env('MYSQL_USER', 'root');
    $pass = env('MYSQL_PASS', '');
    $charset = env('MYSQL_CHARSET', 'utf8mb4');
    $ssl = env('MYSQL_SSL', '0');

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    // Add SSL if required
    if ($ssl == '1') {
        // Use PHP 8.5+ PDO\MySQL constants if available, otherwise fall back
        if (defined('PDO\MySQL::ATTR_SSL_VERIFY_SERVER_CERT')) {
            $sslVerifyKey = PDO\MySQL::ATTR_SSL_VERIFY_SERVER_CERT;
            $sslCaKey = PDO\MySQL::ATTR_SSL_CA;
        } else {
            $sslVerifyKey = PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
            $sslCaKey = PDO::MYSQL_ATTR_SSL_CA;
        }

        $options[$sslVerifyKey] = false;

        $caPath = env('MYSQL_SSL_CA', '');
        if ($caPath && file_exists($caPath)) {
            $options[$sslCaKey] = $caPath;
        } else {
            $candidates = ['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'];
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $options[$sslCaKey] = $candidate;
                    break;
                }
            }
        }
    }

    try {
        $db = new PDO($dsn, $user, $pass, $options);
        return $db;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Ensure table exists (for auto-creating tables on first deploy)
 */
function ensureTableExists($tableName, $createSQL) {
    $db = getDatabaseConnection();
    try {
        $db->exec($createSQL);
    } catch (Exception $e) {
        // Table might already exist
    }
}
