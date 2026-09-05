<?php
/**
 * Vercel serverless function: Main booking page (real root UI)
 * Route: GET / (routed here via vercel.json)
 *
 * Renders the actual root index.php by executing it through the serverless
 * runtime. Vercel only treats files under /api as serverless functions, so the
 * root index.php cannot be a function itself; this wrapper changes into the
 * project root and includes it. The root file already: connects to TiDB via
 * db.php/env.php, renders the full booking UI, and posts bookings via AJAX to
 * /api/book when the VERCEL env var is set.
 */

// Enter the repo root so index.php's relative "require_once 'db.php'" and its
// assets resolve correctly.
chdir(dirname(__DIR__));

// Tell the root routing helpers we are the root index script.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/index.php';

// Force the booking form to use the AJAX /api/book path on this serverless host.
putenv('VERCEL=1');
$_SERVER['VERCEL'] = '1';
$_ENV['VERCEL'] = '1';

// Render the real main page.
require dirname(__DIR__) . '/index.php';
