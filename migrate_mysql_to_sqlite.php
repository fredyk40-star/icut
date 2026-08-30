<?php
/**
 * One-off / re-runnable data migration: MySQL (barbershop_db) -> SQLite (database/icut.db)
 *
 * MySQL is treated as the source of truth. For every table below the SQLite
 * target is cleared and repopulated with the MySQL rows, preserving primary
 * keys so foreign keys (bookings.barber_id, bookings.service_id, ...) stay valid.
 *
 * `site_settings` is the one exception: it is MERGED, so SQLite-only defaults
 * that never existed in MySQL (e.g. home_service_fee) are not lost.
 *
 * Run from the command line only:
 *   C:\xampp\php\php.exe migrate_mysql_to_sqlite.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This migration script may only be run from the command line.');
}

require_once __DIR__ . '/db.php';   // gives us $db (SQLite) + creates every table

// ---------------------------------------------------------------- MySQL source
$mysql_host = env('MYSQL_HOST', 'localhost');
$mysql_name = env('MYSQL_NAME', 'barbershop_db');
$mysql_user = env('MYSQL_USER', 'root');
$mysql_pass = env('MYSQL_PASS', '');

try {
    $mysql = new PDO(
        "mysql:host={$mysql_host};dbname={$mysql_name};charset=utf8mb4",
        $mysql_user,
        $mysql_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    exit("Could not connect to MySQL ({$mysql_name}). Is XAMPP's MySQL running?\n  " . $e->getMessage() . "\n");
}

/*
 * Migration order matters: parents before children so FK targets exist.
 * 'rename' maps a MySQL column name => SQLite column name.
 * 'skip' lists MySQL columns that have no SQLite counterpart.
 */
$plan = [
    'admins'             => [],
    'barbers'            => [],
    'services'           => [],
    'packages'           => [],
    'site_settings'      => [],
    'bookings'           => [],
    'payments'           => ['skip' => ['payment_method']],
    'business_hours'     => ['skip' => ['created_at', 'updated_at']],
    'barber_schedules'   => [],
    'gallery'            => [],
    'reviews'            => [],
    'loyalty'            => [],
    'client_notes'       => [],
    'waitlist'           => [],
    'admin_2fa'          => [],
    'admin_activity_log' => ['rename' => ['reference_id' => 'booking_id']],
];

// Which tables actually exist on each side?
$mysql_tables  = $mysql->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$sqlite_tables = $db->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
)->fetchAll(PDO::FETCH_COLUMN);

function sqlite_columns(PDO $db, string $table): array
{
    $cols = $db->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
    return array_column($cols, 'name');
}

echo "=== MySQL -> SQLite data migration ===\n";
echo 'Source: mysql:' . $mysql_name . "\n";
echo 'Target: ' . DB_PATH . "\n\n";

$db->exec('PRAGMA foreign_keys = OFF');
$db->beginTransaction();

$summary  = [];
$warnings = [];

try {
    foreach ($plan as $table => $opts) {
        $rename = $opts['rename'] ?? [];
        $skip   = $opts['skip']   ?? [];

        if (!in_array($table, $mysql_tables, true)) {
            $warnings[] = "MySQL table '{$table}' does not exist - skipped.";
            continue;
        }
        if (!in_array($table, $sqlite_tables, true)) {
            $warnings[] = "SQLite table '{$table}' does not exist - skipped.";
            continue;
        }

        $rows       = $mysql->query("SELECT * FROM `{$table}`")->fetchAll();
        $target_cols = sqlite_columns($db, $table);

        // ---------------------------------------- site_settings: merge, don't wipe
        if ($table === 'site_settings') {
            $before = (int)$db->query('SELECT COUNT(*) FROM site_settings')->fetchColumn();
            $upsert = $db->prepare("
                INSERT INTO site_settings (setting_key, setting_value, updated_at)
                VALUES (:key, :value, :updated_at)
                ON CONFLICT(setting_key) DO UPDATE SET
                    setting_value = excluded.setting_value,
                    updated_at    = excluded.updated_at
            ");
            foreach ($rows as $row) {
                $upsert->execute([
                    ':key'        => $row['setting_key'],
                    ':value'      => $row['setting_value'],
                    ':updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                ]);
            }
            $after = (int)$db->query('SELECT COUNT(*) FROM site_settings')->fetchColumn();
            $summary[$table] = [
                'source' => count($rows),
                'target' => $after,
                'note'   => "merged (was {$before}, MySQL values win)",
            ];
            continue;
        }

        // ---------------------------------------- everything else: replace wholesale
        $db->exec('DELETE FROM "' . $table . '"');
        // Let AUTOINCREMENT resume correctly after we re-insert explicit ids
        $db->exec("DELETE FROM sqlite_sequence WHERE name = '" . $table . "'");

        if (empty($rows)) {
            $summary[$table] = ['source' => 0, 'target' => 0, 'note' => 'empty in MySQL'];
            continue;
        }

        // Work out the column intersection once, from the first row
        $insert_cols = [];   // sqlite column name
        $source_keys = [];   // matching mysql column name
        foreach (array_keys($rows[0]) as $mysql_col) {
            if (in_array($mysql_col, $skip, true)) {
                continue;
            }
            $sqlite_col = $rename[$mysql_col] ?? $mysql_col;
            if (!in_array($sqlite_col, $target_cols, true)) {
                $warnings[] = "{$table}: MySQL column '{$mysql_col}' has no SQLite counterpart - dropped.";
                continue;
            }
            $insert_cols[] = $sqlite_col;
            $source_keys[] = $mysql_col;
        }

        // Report SQLite-only columns that will fall back to their defaults
        foreach ($target_cols as $target_col) {
            if (!in_array($target_col, $insert_cols, true)) {
                $warnings[] = "{$table}: SQLite column '{$target_col}' not in MySQL - left at default.";
            }
        }

        $sql = 'INSERT INTO "' . $table . '" ("' . implode('", "', $insert_cols) . '") VALUES ('
             . implode(', ', array_fill(0, count($insert_cols), '?')) . ')';
        $stmt = $db->prepare($sql);

        foreach ($rows as $row) {
            $values = [];
            foreach ($source_keys as $mysql_col) {
                $value = $row[$mysql_col];
                // Normalise MySQL's zero-dates, which SQLite has no concept of
                if ($value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
                    $value = null;
                }
                $values[] = $value;
            }
            $stmt->execute($values);
        }

        $target_count = (int)$db->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        $summary[$table] = [
            'source' => count($rows),
            'target' => $target_count,
            'note'   => $target_count === count($rows) ? 'ok' : 'MISMATCH',
        ];
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    $db->exec('PRAGMA foreign_keys = ON');
    exit("\nMIGRATION FAILED - rolled back, SQLite database unchanged.\n  " . $e->getMessage() . "\n");
}

$db->exec('PRAGMA foreign_keys = ON');

// ------------------------------------------------------------------- reporting
printf("%-22s %8s %8s   %s\n", 'TABLE', 'MYSQL', 'SQLITE', 'RESULT');
printf("%-22s %8s %8s   %s\n", str_repeat('-', 22), '-----', '------', '------');
$failed = 0;
foreach ($summary as $table => $info) {
    if ($info['note'] === 'MISMATCH') {
        $failed++;
    }
    printf("%-22s %8d %8d   %s\n", $table, $info['source'], $info['target'], $info['note']);
}

if ($warnings) {
    echo "\n--- Notes (" . count($warnings) . ") ---\n";
    foreach (array_unique($warnings) as $warning) {
        echo "  * {$warning}\n";
    }
}

// Foreign-key integrity check
$violations = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
echo "\nForeign key check: " . (empty($violations)
    ? "PASS (no violations)\n"
    : count($violations) . " VIOLATION(S)\n");
foreach ($violations as $violation) {
    echo '  * ' . json_encode($violation) . "\n";
}

$integrity = $db->query('PRAGMA integrity_check')->fetchColumn();
echo "Integrity check:   " . $integrity . "\n";

echo "\n" . ($failed === 0 && empty($violations)
    ? "Migration completed successfully.\n"
    : "Migration completed WITH PROBLEMS - review the output above.\n");
