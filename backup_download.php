<?php
/**
 * Database Backup Script
 * Generates a SQL dump of the MySQL/TiDB database and forces download.
 */

require_once 'admin_auth.php';

// Only allow admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . adminLoginUrl());
    exit;
}

$db_name = env('MYSQL_NAME', $db->query('SELECT DATABASE()')->fetchColumn());
$backup_file = 'backup_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $db_name) . '_' . date('Y-m-d_H-i-s') . '.sql';

try {
    // List all tables
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    $sql  = "-- Database Backup for icut Barbershop\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: " . $db_name . " (MySQL / TiDB)\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET NAMES utf8mb4;\n\n";

    foreach ($tables as $table) {
        // Table structure
        $sql .= "-- Table structure for `$table`\n";
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        if ($create && isset($create[1])) {
            $sql .= $create[1] . ";\n\n";
        }

        // Table data
        $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $sql .= "-- Data for `$table`\n";
            $columns = implode(', ', array_map(function ($c) { return '`' . $c . '`'; }, array_keys($rows[0])));

            foreach ($rows as $row) {
                $values = array_map(function ($value) use ($db) {
                    if ($value === null) {
                        return 'NULL';
                    }
                    return $db->quote((string)$value);
                }, $row);

                $sql .= "INSERT INTO `$table` ($columns) VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "Backup failed: " . $e->getMessage();
    exit;
}

// Force download
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $backup_file . '"');
header('Content-Length: ' . strlen($sql));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $sql;
exit;
