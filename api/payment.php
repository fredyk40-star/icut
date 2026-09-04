<?php
/**
 * Vercel serverless function: Initialize Paystack payment
 * Route: POST /api/payment.php
 *
 * Executes the root payment.php (full paystack init logic) through the
 * serverless runtime. Root payment.php relies on root db.php helpers
 * (initializePaystackPayment, sanitizeInput, validateEmail, validateCSRFToken),
 * so we chdir to the project root where its relative "require_once 'db.php'"
 * resolves.
 */
chdir(dirname(__DIR__));
$_SERVER['SCRIPT_NAME'] = '/payment.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/payment.php';
require dirname(__DIR__) . '/payment.php';