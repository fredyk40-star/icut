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

    // Auto-enable SSL for TiDB Cloud hosts (they always require TLS)
    if (strpos($host, 'tidbcloud.com') !== false || strpos($host, 'tidbcloud') !== false) {
        $ssl = '1';
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    // Add SSL if required
    if ($ssl == '1') {
        // Use DSN query param for ssl-mode (most reliable across PHP versions)
        $dsn .= ';ssl-mode=REQUIRED';

        // Also set PDO attributes for broader compatibility
        if (defined('PDO\MySQL::ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO\MySQL::ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        if (defined('PDO\MySQL::ATTR_SSL_CA')) {
            $caPath = env('MYSQL_SSL_CA', '');
            if (empty($caPath)) {
                // Try common system CA bundle paths
                $common_paths = [
                    '/etc/ssl/certs/ca-certificates.crt',
                    '/etc/pki/tls/certs/ca-bundle.crt',
                    '/etc/ssl/cert.pem',
                ];
                foreach ($common_paths as $path) {
                    if (file_exists($path)) {
                        $caPath = $path;
                        break;
                    }
                }
            }
            if ($caPath && file_exists($caPath)) {
                $options[PDO\MySQL::ATTR_SSL_CA] = $caPath;
            }
        }

        // Fallback: try ATTR_SSL_MODE if available
        if (defined('PDO\MySQL::ATTR_SSL_MODE') && defined('PDO\MySQL::SSL_MODE_REQUIRED')) {
            $options[PDO\MySQL::ATTR_SSL_MODE] = PDO\MySQL::SSL_MODE_REQUIRED;
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
