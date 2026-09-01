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
    $ssl = filter_var(env('MYSQL_SSL', '0'), FILTER_VALIDATE_BOOLEAN);

    // Auto-enable SSL for TiDB Cloud hosts (they always require TLS)
    if (strpos($host, 'tidbcloud.com') !== false || strpos($host, 'tidbcloud') !== false) {
        $ssl = true;
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";
    if ($ssl) {
        $dsn .= ';sslmode=REQUIRED';
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    // Add SSL if required
    if ($ssl) {
        // PHP 8.5+ deprecates the PDO::MYSQL_ATTR_SSL_* constants but they
        // still work. The namespaced Pdo\Mysql::ATTR_* alternatives may not be
        // available yet, so we use the legacy constants with error suppression
        // to avoid deprecation notices.
        //
        // mysqlnd only initiates TLS when a CA bundle is actually set, so we
        // auto-detect a common system CA bundle path.
        $ca = env('MYSQL_SSL_CA', '');
        // If the configured CA path doesn't exist on this platform, ignore it
        // and fall through to auto-detection (handles Windows paths on Linux).
        if ($ca !== '' && !is_readable($ca)) {
            $ca = '';
        }
        if ($ca === '') {
            $candidate_paths = [
                dirname(__DIR__) . '/certs/isrgrootx1.pem',
                '/etc/pki/tls/certs/ca-bundle.crt',
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/ssl/cert.pem',
                '/etc/ssl/ca-bundle.pem',
                '/etc/ssl/certs/ca-bundle.trust.crt',
            ];
            foreach ($candidate_paths as $candidate) {
                if (is_readable($candidate)) {
                    $ca = $candidate;
                    break;
                }
            }
        }

        if ($ca !== '' && is_readable($ca)) {
            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                @$options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                @$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        } else {
            // No CA bundle found — mysqlnd will NOT initiate TLS without a CA,
            // so the connection will fail on TiDB Cloud. Log loudly.
            error_log("WARNING: No CA bundle found for MySQL TLS; connection may be rejected as insecure transport.");
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                @$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
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

/**
 * Run lightweight schema migrations to add columns that the SQL dump may
 * be missing. TiDB supports ALTER TABLE ... ADD COLUMN IF NOT EXISTS.
 */
function migrateSchema() {
    $db = getDatabaseConnection();
    $migrations = [
        "ALTER TABLE barbers ADD COLUMN IF NOT EXISTS display_order INT DEFAULT 0",
        "ALTER TABLE services ADD COLUMN IF NOT EXISTS display_order INT DEFAULT 0",
        "ALTER TABLE packages ADD COLUMN IF NOT EXISTS display_order INT DEFAULT 0",
    ];
    foreach ($migrations as $sql) {
        try {
            $db->exec($sql);
        } catch (Exception $e) {
            // Column might already exist or migration not needed
            error_log("Migration note: " . $e->getMessage());
        }
    }
}

// Run migrations on each connection (idempotent, safe to repeat)
migrateSchema();
