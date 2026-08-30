<?php
/**
 * Environment Configuration Loader
 * 
 * Loads sensitive configuration from .env file
 * This file should be placed outside web root or protected via .htaccess
 */

function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
            // Parse line
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Strip a trailing CR (in case the file uses CRLF line endings)
                $value = rtrim($value, "\r");
                $key = rtrim($key, "\r");
            
            // Set environment variable
            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                putenv(sprintf('%s=%s', $key, $value));
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    
    return true;
}

// Load .env file
$envPath = __DIR__ . '/.env';
loadEnv($envPath);

/**
 * Get environment variable with fallback
 */
function env($key, $default = '') {
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? $default));
}
