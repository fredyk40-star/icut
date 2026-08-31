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
        // Build a minimal options array without deprecated constants
        $sslOptions = [];
        
        if (defined('PDO\MySQL::ATTR_SSL_VERIFY_SERVER_CERT')) {
            $sslOptions[PDO\MySQL::ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        
        if (defined('PDO\MySQL::ATTR_SSL_CA')) {
            $caPath = env('MYSQL_SSL_CA', '');
            if ($caPath && file_exists($caPath)) {
                $sslOptions[PDO\MySQL::ATTR_SSL_CA] = $caPath;
            }
        }
        
        if (defined('PDO\MySQL::ATTR_SSL_MODE') && defined('PDO\MySQL::SSL_MODE_REQUIRED')) {
            $sslOptions[PDO\MySQL::ATTR_SSL_MODE] = PDO\MySQL::SSL_MODE_REQUIRED;
        }
        
        // Only add non-empty options
        foreach ($sslOptions as $key => $value) {
            $options[$key] = $value;
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
