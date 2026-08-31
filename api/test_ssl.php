<?php
header('Content-Type: text/plain');

echo "Testing PDO SSL connection...\n\n";

$host = 'gateway01.eu-central-1.prod.aws.tidbcloud.com';
$port = 4000;
$name = 'barbershop_db';
$user = '2Z5TYhtso9UUbPX.root';
$pass = 'Ik6ag4ELPNTpXGT6';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";

echo "DSN: $dsn\n\n";

// Test 1: With CA bundle + verify
echo "Test 1: With CA + verify=true\n";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    @PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt',
    @PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
];
try {
    $db = new PDO($dsn, $user, $pass, $options);
    echo "RESULT: SUCCESS!\n";
    $row = $db->query("SELECT 1")->fetch();
    print_r($row);
} catch (Exception $e) {
    echo "RESULT: FAILED - " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: With CA bundle + verify=false
echo "Test 2: With CA + verify=false\n";
$options2 = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    @PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt',
    @PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];
try {
    $db = new PDO($dsn, $user, $pass, $options2);
    echo "RESULT: SUCCESS!\n";
    $row = $db->query("SELECT 1")->fetch();
    print_r($row);
} catch (Exception $e) {
    echo "RESULT: FAILED - " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: With CA only, no verify flag
echo "Test 3: With CA only, no verify flag\n";
$options3 = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    @PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt',
];
try {
    $db = new PDO($dsn, $user, $pass, $options3);
    echo "RESULT: SUCCESS!\n";
    $row = $db->query("SELECT 1")->fetch();
    print_r($row);
} catch (Exception $e) {
    echo "RESULT: FAILED - " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: DSN with ssl-mode=REQUIRED
echo "Test 4: DSN with ssl-mode=REQUIRED\n";
$dsn4 = "mysql:host=$host;port=$port;dbname=$name;charset=$charset;ssl-mode=REQUIRED";
$options4 = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
try {
    $db = new PDO($dsn4, $user, $pass, $options4);
    echo "RESULT: SUCCESS!\n";
    $row = $db->query("SELECT 1")->fetch();
    print_r($row);
} catch (Exception $e) {
    echo "RESULT: FAILED - " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: DSN with ssl-mode=REQUIRED + CA in DSN
echo "Test 5: DSN with ssl-ca in params\n";
$dsn5 = "mysql:host=$host;port=$port;dbname=$name;charset=$charset;ssl-mode=REQUIRED;ssl-ca=/etc/ssl/certs/ca-certificates.crt";
$options5 = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
try {
    $db = new PDO($dsn5, $user, $pass, $options5);
    echo "RESULT: SUCCESS!\n";
    $row = $db->query("SELECT 1")->fetch();
    print_r($row);
} catch (Exception $e) {
    echo "RESULT: FAILED - " . $e->getMessage() . "\n";
}
