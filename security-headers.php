<?php
/**
 * security-headers.php — IndependentResearcher Platform
 *
 * Single include that:
 *   1. Sets all HTTP security headers (supplements .htaccess mod_headers for
 *      shared-hosting environments where mod_headers may be restricted).
 *   2. Provides checkRateLimit() wired to the DB, replacing the temp-file
 *      implementation in api-simulation.php.
 *   3. Provides recordFailedLogin() / isAccountLocked() / clearFailedLogins()
 *      for login-failure tracking.
 *
 * Include ONCE at the top of index.php and api.php BEFORE any output:
 *
 *   require_once __DIR__ . '/security-headers.php';
 *
 * config.php must already be included before this file.
 */

if (!function_exists('getDB')) {
    throw new RuntimeException('security-headers.php must be included after config.php');
}

// ── HTTP security headers ─────────────────────────────────────────────────────
// Only send headers when output has not started (guards against double-include).
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-XSS-Protection: 1; mode=block');

    // Content-Security-Policy — starts permissive so existing inline scripts and
    // CDN resources work without breakage. Tighten incrementally once the site is
    // stable by moving inline JS to external files and adding nonce/hash support.
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' " .
            "https://www.googletagmanager.com " .
            "https://www.google-analytics.com " .
            "https://www.clarity.ms " .
            "https://checkout.razorpay.com " .
            "https://accounts.google.com; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' data: blob: https:; " .
        "frame-src 'self' https://accounts.google.com; " .
        "connect-src 'self' " .
            "https://www.google-analytics.com " .
            "https://analytics.google.com " .
            "https://www.clarity.ms " .
            "https://api.razorpay.com " .
            "https://lumberjack.razorpay.com; " .
        "object-src 'none'; " .
        "base-uri 'self';"
    );
}

// ── Rate limiting — DB-backed, shared-hosting safe ───────────────────────────

/**
 * Ensure the rate_limits table exists (run once; MySQL caches after first call).
 */
function _ensureRateLimitTable(PDO $db): void {
    static $created = false;
    if ($created) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action     VARCHAR(50)  NOT NULL,
            identifier VARCHAR(255) NOT NULL,
            created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action_identifier (action, identifier),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $created = true;
}

/**
 * Check whether $identifier has exceeded $maxAttempts for $action within
 * the last $windowSeconds.
 *
 * Returns true  — request is within limits, proceed.
 * Returns false — limit exceeded, caller should return 429.
 *
 * Usage in api.php:
 *   if (!checkRateLimit('login', $_SERVER['REMOTE_ADDR'], 5, 300)) {
 *       jsonResponse(['error' => 'Too many attempts. Try again in 5 minutes.'], 429);
 *   }
 */
function checkRateLimit(string $action, string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    try {
        $db = getDB();
        _ensureRateLimitTable($db);

        // Purge expired rows for this action to keep the table lean.
        $db->prepare("
            DELETE FROM rate_limits
            WHERE action = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ")->execute([$action, $windowSeconds]);

        // Count recent attempts within the window.
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM rate_limits
            WHERE action = ? AND identifier = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$action, $identifier, $windowSeconds]);
        $row = $stmt->fetch();

        if ((int)($row['cnt'] ?? 0) >= $maxAttempts) {
            return false;
        }

        // Log this attempt.
        $db->prepare("
            INSERT INTO rate_limits (action, identifier) VALUES (?, ?)
        ")->execute([$action, $identifier]);

        return true;
    } catch (Throwable $e) {
        // If DB is unavailable, fail open so legitimate users are not blocked.
        error_log('checkRateLimit DB error: ' . $e->getMessage());
        return true;
    }
}

// ── Login failure tracking — account lockout ─────────────────────────────────

function _ensureFailedLoginsTable(PDO $db): void {
    static $created = false;
    if ($created) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS failed_logins (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email        VARCHAR(255) NOT NULL,
            attempt_time TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_time  (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $created = true;
}

/**
 * Record one failed login attempt for $email.
 */
function recordFailedLogin(string $email): void {
    try {
        $db = getDB();
        _ensureFailedLoginsTable($db);
        $db->prepare("INSERT INTO failed_logins (email) VALUES (?)")->execute([$email]);
    } catch (Throwable $e) {
        error_log('recordFailedLogin error: ' . $e->getMessage());
    }
}

/**
 * Returns true if $email has had $maxAttempts or more failures within
 * the last $lockoutMinutes minutes.
 */
function isAccountLocked(string $email, int $maxAttempts = 5, int $lockoutMinutes = 15): bool {
    try {
        $db = getDB();
        _ensureFailedLoginsTable($db);

        // Purge old failures.
        $db->prepare("
            DELETE FROM failed_logins
            WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ")->execute([$lockoutMinutes]);

        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM failed_logins
            WHERE email = ?
              AND attempt_time >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$email, $lockoutMinutes]);
        $row = $stmt->fetch();
        return (int)($row['cnt'] ?? 0) >= $maxAttempts;
    } catch (Throwable $e) {
        error_log('isAccountLocked error: ' . $e->getMessage());
        return false; // fail open
    }
}

/**
 * Clear failed login records for $email after a successful authentication.
 */
function clearFailedLogins(string $email): void {
    try {
        $db = getDB();
        _ensureFailedLoginsTable($db);
        $db->prepare("DELETE FROM failed_logins WHERE email = ?")->execute([$email]);
    } catch (Throwable $e) {
        error_log('clearFailedLogins error: ' . $e->getMessage());
    }
}
