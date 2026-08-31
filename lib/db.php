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
        // On PHP 8.5+ the namespaced Pdo\Mysql constants are the preferred API,
        // but they may not be available yet. Fall back to the (deprecated but
        // still present) PDO constants, suppressing the deprecation notices.
        $constCa = defined('Pdo\Mysql\ATTR_SSL_CA')
            ? 'Pdo\Mysql\ATTR_SSL_CA'
            : (defined('PDO::MYSQL_ATTR_SSL_CA') ? 'PDO::MYSQL_ATTR_SSL_CA' : null);
        $constVerify = defined('Pdo\Mysql\ATTR_SSL_VERIFY_SERVER_CERT')
            ? 'Pdo\Mysql\ATTR_SSL_VERIFY_SERVER_CERT'
            : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? 'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT' : null);

        // mysqlnd only initiates TLS when a CA bundle is actually set.
        // Auto-detect common system CA bundle paths (Vercel/Amazon Linux,
        // Debian/Ubuntu, Alpine, Fedora/RHEL, XAMPP).
        $ca = env('MYSQL_SSL_CA', '');
        if ($ca === '') {
            $candidate_paths = [
                '/etc/pki/tls/certs/ca-bundle.crt',
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/ssl/cert.pem',
                '/etc/ssl/ca-bundle.pem',
            ];
            foreach ($candidate_paths as $candidate) {
                if (is_readable($candidate)) {
                    $ca = $candidate;
                    break;
                }
            }
        }

        if ($constCa !== null && $ca !== '' && is_readable($ca)) {
            @$options[$constCa] = $ca;
        }

        if ($constVerify !== null) {
            @$options[$constVerify] = true;
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
