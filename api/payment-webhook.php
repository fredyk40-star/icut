<?php
/**
 * Vercel serverless function: Paystack webhook
 * Route: POST /api/payment-webhook.php
 *
 * Executes the root payment_webhook.php through the serverless runtime so
 * Paystack's asynchronous charge.success / charge.failed events update the
 * booking/payment records.
 */
chdir(dirname(__DIR__));
$_SERVER['SCRIPT_NAME'] = '/payment_webhook.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/payment_webhook.php';
require dirname(__DIR__) . '/payment_webhook.php';