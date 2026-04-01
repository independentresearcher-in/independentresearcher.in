<?php
/**
 * helpers.php — Token utilities
 *
 * NOTE: Email sending is currently DISABLED (SMTP not configured).
 * Google OAuth handles authentication; no email verification needed.
 * These helpers are retained for future SMTP integration.
 */

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

/**
 * Verify a token (verification or reset) against DB.
 * Uses getDB() instead of global $pdo (was broken).
 */
function verifyToken(string $token, string $type = 'verification'): ?array {
    $db     = getDB();
    $col    = $type === 'reset' ? 'reset_token'         : 'verification_token';
    $expCol = $type === 'reset' ? 'reset_token_expires'  : 'verification_token_expires';

    $stmt = $db->prepare("
        SELECT id, email, {$expCol} as expires
        FROM users
        WHERE {$col} = ? AND {$expCol} > NOW()
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * Clear token after use.
 */
function clearToken(int $userId, string $type = 'verification'): void {
    $db     = getDB();
    $col    = $type === 'reset' ? 'reset_token'         : 'verification_token';
    $expCol = $type === 'reset' ? 'reset_token_expires'  : 'verification_token_expires';

    $db->prepare("UPDATE users SET {$col} = NULL, {$expCol} = NULL WHERE id = ?")
       ->execute([$userId]);
}

/**
 * Stub — email sending disabled. Returns false so callers handle gracefully.
 */
function sendVerificationEmail(string $email, string $token): bool {
    error_log('sendVerificationEmail called but SMTP is disabled. Email: ' . $email);
    return false;
}

function sendPasswordResetEmail(string $email, string $token, string $name = ''): bool {
    error_log('sendPasswordResetEmail called but SMTP is disabled. Email: ' . $email);
    return false;
}
