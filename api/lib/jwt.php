<?php
/**
 * JWT Session handler for Vercel serverless functions
 * Replaces $_SESSION with signed JWT tokens stored in HTTP-only cookies
 */

function generateJWT($payload, $secret) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload['exp'] = time() + (int)env('JWT_EXPIRY', 86400); // 24 hours
    $payload['iat'] = time();
    
    $headerEncoded = base64url_encode($header);
    $payloadEncoded = base64url_encode(json_encode($payload));
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secret, true);
    $signatureEncoded = base64url_encode($signature);
    
    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

function validateJWT($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
    
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secret, true);
    if (!hash_equals(base64url_encode($signature), $signatureEncoded)) {
        return null;
    }
    
    $payload = json_decode(base64url_decode($payloadEncoded), true);
    if (!$payload || $payload['exp'] < time()) {
        return null;
    }
    
    return $payload;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function getJWTCookie() {
    return $_COOKIE[env('JWT_COOKIE_NAME', 'icut_session')] ?? null;
}

function setJWTCookie($token) {
    $cookieName = env('JWT_COOKIE_NAME', 'icut_session');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
              (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    setcookie(
        $cookieName,
        $token,
        [
            'expires' => time() + (int)env('JWT_EXPIRY', 86400),
            'path' => '/',
            'domain' => env('COOKIE_DOMAIN', ''),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

function deleteJWTCookie() {
    $cookieName = env('JWT_COOKIE_NAME', 'icut_session');
    setcookie(
        $cookieName,
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => env('COOKIE_DOMAIN', ''),
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

function getCurrentUser() {
    $token = getJWTCookie();
    if (!$token) {
        return null;
    }
    
    $secret = env('JWT_SECRET', 'change-me-in-production');
    $payload = validateJWT($token, $secret);
    
    return $payload ?: null;
}

function requireAuth() {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    return $user;
}
