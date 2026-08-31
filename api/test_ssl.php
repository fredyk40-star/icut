<?php
/**
 * Test SSL CA bundle availability
 */
$paths = [
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/ssl/cert.pem',
    '/etc/ssl/ca-bundle.pem',
    '/etc/pki/tls/cacert.pem',
    '/usr/local/share/ca-certificates/',
];

echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP_VERSION_ID: " . PHP_VERSION_ID . "\n\n";

// Check constants
echo "PDO::MYSQL_ATTR_SSL_CA: " . (defined('PDO::MYSQL_ATTR_SSL_CA') ? @PDO::MYSQL_ATTR_SSL_CA : 'undefined') . "\n";
echo "PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT: " . (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? @PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT : 'undefined') . "\n";
echo "PDO::MYSQL_ATTR_SSL_MODE: " . (defined('PDO::MYSQL_ATTR_SSL_MODE') ? @PDO::MYSQL_ATTR_SSL_MODE : 'undefined') . "\n";
echo "PDO::MYSQL_ATTR_SSL_CIPHER: " . (defined('PDO::MYSQL_ATTR_SSL_CIPHER') ? 'yes' : 'no') . "\n";
echo "PDO::MYSQL_ATTR_SSL_KEY: " . (defined('PDO::MYSQL_ATTR_SSL_KEY') ? 'yes' : 'no') . "\n";
echo "PDO::MYSQL_ATTR_SSL_CERT: " . (defined('PDO::MYSQL_ATTR_SSL_CERT') ? 'yes' : 'no') . "\n";

echo "\nPDO::ATTR_SSL_CA (try): " . (defined('PDO::ATTR_SSL_CA') ? PDO::ATTR_SSL_CA : 'undefined') . "\n";
echo "Pdo\Mysql class exists: " . (class_exists('Pdo\Mysql') ? 'yes' : 'no') . "\n";
if (class_exists('Pdo\Mysql')) {
    echo "Pdo\Mysql::ATTR_SSL_CA: " . (defined('Pdo\Mysql\ATTR_SSL_CA') ? Pdo\Mysql::ATTR_SSL_CA : 'undefined') . "\n";
    echo "Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT: " . (defined('Pdo\Mysql\ATTR_SSL_VERIFY_SERVER_CERT') ? Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT : 'undefined') . "\n";
}

echo "\nCA Bundle Paths:\n";
foreach ($paths as $path) {
    if (file_exists($path)) {
        echo "  EXISTS: $path (readable: " . (is_readable($path) ? 'yes' : 'no') . ", size: " . filesize($path) . ")\n";
    } else {
        echo "  MISSING: $path\n";
    }
}

echo "\nOpenSSL CA file: " . ini_get('openssl.cafile') . "\n";
echo "OpenSSL CA path: " . ini_get('openssl.capath') . "\n";

echo "\nopenssl_get_cert_locations:\n";
print_r(openssl_get_cert_locations());
