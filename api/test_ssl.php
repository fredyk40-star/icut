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
if (PHP_VERSION_ID >= 80500) {
    echo "PHP 8.5+ - checking Pdo\\Mysql namespace:\n";
    echo "  ATTR_SSL_CA defined: " . (defined('Pdo\Mysql\ATTR_SSL_CA') ? 'yes' : 'no') . "\n";
    echo "  ATTR_SSL_VERIFY_SERVER_CERT defined: " . (defined('Pdo\Mysql\ATTR_SSL_VERIFY_SERVER_CERT') ? 'yes' : 'no') . "\n";
    if (defined('Pdo\Mysql\ATTR_SSL_CA')) {
        echo "  ATTR_SSL_CA value: " . Pdo\Mysql::ATTR_SSL_CA . "\n";
    }
} else {
    echo "PHP < 8.5 - checking PDO namespace:\n";
    echo "  MYSQL_ATTR_SSL_CA defined: " . (defined('PDO::MYSQL_ATTR_SSL_CA') ? 'yes' : 'no') . "\n";
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
echo "cURL CA info: " . curl_getinfo(curl_init(), CURLINFO_CERTINFO) . "\n";

echo "\nopenssl_get_cert_locations:\n";
print_r(openssl_get_cert_locations());
