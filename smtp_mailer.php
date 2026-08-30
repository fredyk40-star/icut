<?php
/**
 * Simple SMTP Mailer for icut
 * 
 * Supports any SMTP server (Gmail, Mailtrap, Brevo, etc.)
 * Uses PHP's built-in stream_socket_client - no external libraries needed
 * 
 * Free SMTP options for development:
 * 1. Mailtrap (recommended): https://mailtrap.io - Fake inbox for testing
 * 2. Gmail: smtp.gmail.com port 587 (requires app password)
 * 3. Brevo: smtp-relay.brevo.com port 587 (free tier: 300 emails/day)
 */

class SmtpMailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $fromEmail;
    private $fromName;
    private $security;
    private $timeout;
    private $debug;
    
    public function __construct() {
        $this->host = env('SMTP_HOST', '');
        $this->port = (int)env('SMTP_PORT', 587);
        $this->username = env('SMTP_USERNAME', '');
        $this->password = env('SMTP_PASSWORD', '');
        $this->fromEmail = env('SMTP_FROM_EMAIL', env('ADMIN_EMAIL', 'noreply@icut.com'));
        $this->fromName = env('SMTP_FROM_NAME', 'icut');
        $this->security = env('SMTP_SECURITY', 'tls');
        $this->timeout = 30;
        $this->debug = false;
    }
    
    /**
     * Send an email
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body HTML email body
     * @return bool Success status
     */
    public function send($to, $subject, $body) {
        if (empty($this->host) || empty($this->username) || empty($this->password)) {
            error_log("SMTP not configured. Please set SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD in .env");
            return false;
        }
        
        $crlf = "\r\n";
        
        // Build email headers
        $headers = [];
        $headers[] = 'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>';
        $headers[] = 'Reply-To: ' . $this->fromEmail;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'X-Mailer: icut Mailer';
        
        $headerString = implode($crlf, $headers);
        
        // Build email body with proper line endings
        $body = str_replace(["\r\n", "\r"], $crlf, $body);
        
        // Ensure body ends with newline
        if (substr($body, -2) !== $crlf) {
            $body .= $crlf;
        }
        
        $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        
        // Connect to SMTP server
        $socket = @fsockopen(
            $this->security === 'ssl' ? 'ssl://' . $this->host : $this->host,
            $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        
        if (!$socket) {
            error_log("SMTP connection failed: {$errstr} (code: {$errno})");
            return false;
        }
        
        $result = $this->sendCommands($socket, $to, $subject, $headerString, $body);
        fclose($socket);
        
        return $result;
    }
    
    /**
     * Send SMTP commands
     */
    private function sendCommands($socket, $to, $subject, $headers, $body) {
        $crlf = "\r\n";
        $serverResponse = '';
        
        // Read server greeting
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP greeting failed: {$response}");
            return false;
        }
        
        // EHLO
        $this->sendCommand($socket, 'EHLO ' . $_SERVER['SERVER_NAME'] ?? 'localhost');
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            $this->sendCommand($socket, 'HELO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $response = $this->getResponse($socket);
            if (!$this->isSuccessCode($response)) {
                error_log("SMTP EHLO/HELO failed: {$response}");
                return false;
            }
        }
        
        // Start TLS if required
        if ($this->security === 'tls' && stripos($response, 'STARTTLS') !== false) {
            $this->sendCommand($socket, 'STARTTLS');
            $response = $this->getResponse($socket);
            if (!$this->isSuccessCode($response)) {
                error_log("SMTP STARTTLS failed: {$response}");
                return false;
            }
            
            // Enable TLS encryption
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log("SMTP TLS encryption failed");
                return false;
            }
            
            // Send EHLO again after TLS
            $this->sendCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $response = $this->getResponse($socket);
        }
        
        // Authenticate
        $this->sendCommand($socket, 'AUTH LOGIN');
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP AUTH LOGIN failed: {$response}");
            return false;
        }
        
        $this->sendCommand($socket, base64_encode($this->username));
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP username failed: {$response}");
            return false;
        }
        
        $this->sendCommand($socket, base64_encode($this->password));
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP password failed: {$response}");
            return false;
        }
        
        // Set sender
        $this->sendCommand($socket, 'MAIL FROM: <' . $this->fromEmail . '>');
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP MAIL FROM failed: {$response}");
            return false;
        }
        
        // Set recipient
        $this->sendCommand($socket, 'RCPT TO: <' . $to . '>');
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP RCPT TO failed: {$response}");
            return false;
        }
        
        // Start data
        $this->sendCommand($socket, 'DATA');
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP DATA failed: {$response}");
            return false;
        }
        
        // Send email content
        $message = "To: <{$to}>{$crlf}";
        $message .= $headers . $crlf;
        $message .= "Subject: {$subject}{$crlf}";
        $message .= $crlf;
        $message .= $body;
        $message .= "{$crlf}.";
        
        $this->sendCommand($socket, $message);
        $response = $this->getResponse($socket);
        if (!$this->isSuccessCode($response)) {
            error_log("SMTP message send failed: {$response}");
            return false;
        }
        
        // Quit
        $this->sendCommand($socket, 'QUIT');
        
        return true;
    }
    
    /**
     * Send SMTP command
     */
    private function sendCommand($socket, $command) {
        $cmd = $command . "\r\n";
        fwrite($socket, $cmd);
        if ($this->debug) {
            echo ">>> " . trim($command) . "\n";
        }
    }
    
    /**
     * Read SMTP response
     */
    private function getResponse($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if ($this->debug) {
                echo "<<< " . trim($line) . "\n";
            }
            // Response ends with CRLF
            if (substr($line, -2) === "\r\n") {
                break;
            }
        }
        return trim($response);
    }
    
    /**
     * Check if response is a success code (2xx or 3xx)
     */
    private function isSuccessCode($response) {
        if (empty($response)) {
            return false;
        }
        $code = (int)substr($response, 0, 3);
        return $code >= 200 && $code < 400;
    }
    
    /**
     * Encode header for non-ASCII characters
     */
    private function encodeHeader($text) {
        if (preg_match('/[^\x20-\x7E]/', $text)) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }
}
