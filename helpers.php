<?php
declare(strict_types=1);

/**
 * helpers.php — Email utilities (SMTP via PHP mail() or SMTP config)
 *
 * Supports two sending strategies:
 *   1. SMTP_ENABLED=true  -> uses configured SMTP credentials
 *   2. SMTP_ENABLED=false -> falls back to PHP mail() (works on most shared hosting)
 */

function generateSecureToken(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

function verifyToken(string $token, string $type = 'verification'): ?array {
    $tokenColumn   = $type === 'reset' ? 'reset_token'         : 'verification_token';
    $expiryColumn  = $type === 'reset' ? 'reset_token_expires' : 'verification_token_expires';

    $stmt = getDB()->prepare("
        SELECT id, email, {$expiryColumn} AS expires
        FROM users
        WHERE {$tokenColumn} = ? AND {$expiryColumn} > NOW()
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function clearToken(int $userId, string $type = 'verification'): void {
    $tokenColumn  = $type === 'reset' ? 'reset_token'         : 'verification_token';
    $expiryColumn = $type === 'reset' ? 'reset_token_expires' : 'verification_token_expires';

    getDB()->prepare("UPDATE users SET {$tokenColumn} = NULL, {$expiryColumn} = NULL WHERE id = ?")
           ->execute([$userId]);
}

function sendEmail(string $toAddress, string $subject, string $htmlBody, string $textBody = ''): bool {
    if (empty($textBody)) {
        $textBody = strip_tags($htmlBody);
    }

    $fromAddress = SMTP_FROM    ?: 'noreply@' . parse_url(SITE_URL, PHP_URL_HOST);
    $fromName    = SMTP_FROM_NAME ?: SITE_NAME;

    // Only attempt SMTP when all credentials are present
    if (SMTP_ENABLED && !empty(SMTP_HOST) && !empty(SMTP_USER) && !empty(SMTP_PASS)) {
        return _sendViaSMTP($toAddress, $subject, $htmlBody, $textBody, $fromAddress, $fromName);
    }

    // Fallback: PHP mail() — works on Hostinger shared hosting
    $headers  = "From: {$fromName} <{$fromAddress}>\r\n";
    $headers .= "Reply-To: {$fromAddress}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    return mail($toAddress, $subject, $htmlBody, $headers);
}

/**
 * Send email via raw SMTP socket handshake.
 *
 * Added: SMTP_PASS non-empty guard in sendEmail() so this is never called
 * with empty credentials (which previously caused silent AUTH failure).
 */
function _sendViaSMTP(
    string $to,
    string $subject,
    string $html,
    string $text,
    string $from,
    string $fromName
): bool {
    $boundary = md5(uniqid((string)rand(), true));
    $ehloHost = parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost';

    $socket = @fsockopen(
        (SMTP_SECURE === 'ssl' ? 'ssl://' : '') . SMTP_HOST,
        SMTP_PORT,
        $errno,
        $errstr,
        10
    );

    if (!$socket) {
        error_log("SMTP connect failed ({$errno}): {$errstr}");
        return false;
    }

    // Read one SMTP response line; optionally log if it does not begin with $code.
    $read = function (string $code = '') use ($socket): string {
        $line = fgets($socket, 515);
        if ($line === false) {
            return '';
        }
        if ($code !== '' && !str_starts_with(trim($line), $code)) {
            error_log("SMTP unexpected response (expected {$code}): " . trim($line));
        }
        return $line;
    };

    // Write one SMTP command; return false and log on socket write failure.
    $write = function (string $cmd) use ($socket): bool {
        if (fwrite($socket, $cmd) === false) {
            error_log('SMTP write failed on command: ' . rtrim($cmd));
            return false;
        }
        return true;
    };

    // Greeting
    $greeting = $read('220');
    if (empty($greeting)) {
        fclose($socket);
        error_log('SMTP: no greeting received');
        return false;
    }

    // EHLO
    if (!$write("EHLO {$ehloHost}\r\n")) { fclose($socket); return false; }
    if (empty($read('250')))             { fclose($socket); return false; }

    // STARTTLS upgrade
    if (SMTP_SECURE === 'tls') {
        if (!$write("STARTTLS\r\n"))     { fclose($socket); return false; }
        if (empty($read('220')))         { fclose($socket); return false; }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('SMTP: TLS upgrade failed');
            fclose($socket);
            return false;
        }

        // Re-EHLO after TLS
        if (!$write("EHLO {$ehloHost}\r\n")) { fclose($socket); return false; }
        $read('250');
    }

    // AUTH LOGIN
    if (!$write("AUTH LOGIN\r\n")) { fclose($socket); return false; }
    if (empty($read('334'))) {
        error_log('SMTP: AUTH LOGIN not accepted by server');
        fclose($socket);
        return false;
    }

    if (!$write(base64_encode(SMTP_USER) . "\r\n")) { fclose($socket); return false; }
    if (empty($read('334'))) {
        error_log('SMTP: username not accepted');
        fclose($socket);
        return false;
    }

    if (!$write(base64_encode(SMTP_PASS) . "\r\n")) { fclose($socket); return false; }
    $authResp = $read('235');
    if (!str_starts_with(trim($authResp), '235')) {
        error_log('SMTP: authentication failed — ' . trim($authResp));
        fclose($socket);
        return false;
    }

    // MAIL FROM
    if (!$write("MAIL FROM:<{$from}>\r\n")) { fclose($socket); return false; }
    $read('250');

    // RCPT TO
    if (!$write("RCPT TO:<{$to}>\r\n")) { fclose($socket); return false; }
    $rcptResp = $read('250');
    if (!str_starts_with(trim($rcptResp), '250')) {
        error_log('SMTP: RCPT rejected — ' . trim($rcptResp));
        fclose($socket);
        return false;
    }

    // DATA
    if (!$write("DATA\r\n")) { fclose($socket); return false; }
    $read('354');

    // Message body
    $message  = "To: {$to}\r\n";
    $message .= "From: {$fromName} <{$from}>\r\n";
    $message .= "Subject: {$subject}\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
    $message .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n\r\n";
    $message .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n\r\n";
    $message .= "--{$boundary}--\r\n.\r\n";

    if (!$write($message)) { fclose($socket); return false; }
    $dataResp = $read('250');

    $write("QUIT\r\n");
    fclose($socket);

    $success = str_starts_with(trim($dataResp), '250');
    if (!$success) {
        error_log('SMTP: DATA rejected — ' . trim($dataResp));
    }
    return $success;
}

function sendVerificationEmail(string $toAddress, string $token, string $userName = ''): bool {
    $verifyUrl = SITE_URL . '/verify-email.php?token=' . urlencode($token);
    $greeting  = $userName ? "Hi {$userName}," : 'Hi,';

    $html = "
    <div style='font-family:sans-serif;max-width:500px;margin:0 auto;padding:2rem'>
      <h2 style='color:#f0c040'>Verify your email — " . SITE_NAME . "</h2>
      <p>{$greeting}</p>
      <p>Click the button below to verify your email address and activate your account.</p>
      <a href='{$verifyUrl}'
         style='display:inline-block;background:#f0c040;color:#000;padding:.75rem 1.5rem;
                border-radius:8px;text-decoration:none;font-weight:700;margin:1rem 0'>
        Verify Email Address
      </a>
      <p style='color:#666;font-size:.85rem'>
        This link expires in 24 hours. If you did not create an account, ignore this email.
      </p>
      <p style='color:#666;font-size:.85rem'>Or copy this link: {$verifyUrl}</p>
    </div>";

    return sendEmail($toAddress, 'Verify your email — ' . SITE_NAME, $html);
}

function sendPasswordResetEmail(string $toAddress, string $token, string $userName = ''): bool {
    $resetUrl = SITE_URL . '/reset-password.php?token=' . urlencode($token);
    $greeting = $userName ? "Hi {$userName}," : 'Hi,';

    $html = "
    <div style='font-family:sans-serif;max-width:500px;margin:0 auto;padding:2rem'>
      <h2 style='color:#f0c040'>Reset your password — " . SITE_NAME . "</h2>
      <p>{$greeting}</p>
      <p>Click the button below to set a new password. This link expires in 1 hour.</p>
      <a href='{$resetUrl}'
         style='display:inline-block;background:#f0c040;color:#000;padding:.75rem 1.5rem;
                border-radius:8px;text-decoration:none;font-weight:700;margin:1rem 0'>
        Reset Password
      </a>
      <p style='color:#666;font-size:.85rem'>
        If you did not request this, ignore this email — your password will not change.
      </p>
    </div>";

    return sendEmail($toAddress, 'Reset your password — ' . SITE_NAME, $html);
}
