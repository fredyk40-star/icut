<?php
/**
 * Vercel serverless function: Paystack payment callback (redirect target)
 * Route: GET /api/payment-callback.php
 *
 * Executes the root payment_callback.php through the serverless runtime so the
 * paystack redirect verifies the payment and updates the booking, then sends
 * the user back to the home page.
 */
chdir(dirname(__DIR__));
$_SERVER['SCRIPT_NAME'] = '/payment_callback.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/payment_callback.php';
require dirname(__DIR__) . '/payment_callback.php';