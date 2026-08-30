<?php
/**
 * Vercel-compatible path resolver
 * In Vercel's PHP runtime, api/ files are at /var/task/api/
 * and other files are at /var/task/user/
 */

function vercelRootPath() {
    // Vercel PHP runtime places non-api files in /var/task/user/
    $possiblePaths = [
        dirname(__DIR__) . '/lib',      // /var/task/lib (local dev)
        dirname(__DIR__) . '/../user/lib', // /var/task/user/lib (Vercel)
        dirname(__FILE__) . '/../lib',  // fallback
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/db.php')) {
            return $path;
        }
    }
    
    return dirname(__DIR__) . '/lib';
}

function vercelMiddlewarePath() {
    $possiblePaths = [
        dirname(__DIR__) . '/middleware',
        dirname(__DIR__) . '/../user/middleware',
        dirname(__FILE__) . '/../middleware',
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/auth.php')) {
            return $path;
        }
    }
    
    return dirname(__DIR__) . '/middleware';
}
