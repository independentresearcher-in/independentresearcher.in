<?php
/**
 * config.php — IndependentResearcher Platform
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0755, true);
}

// ── Custom .env parser ────────────────────────────────────────────────────────
// Uses fopen() line-by-line instead of file() for maximum Hostinger compatibility.
// parse_ini_file() is NOT used — it silently breaks on parentheses in comments.
function parseEnvFile(string $path): ?array {
    if (!file_exists($path)) return null;

    // Try to read the file — even if is_readable() reports false, 
    // file_get_contents may still work depending on the PHP process user.
    // This handles Hostinger shared hosting where the parent-dir .env
    // is owned by the account user but served by a web daemon user.
    $raw = false;
    if (is_readable($path)) {
        $fh  = fopen($path, 'r');
        if ($fh !== false) {
            $raw = '';
            while (($line = fgets($fh)) !== false) $raw .= $line;
            fclose($fh);
        }
    }
    // Fallback: file_get_contents with @ suppresses warnings but still tries
    if ($raw === false || $raw === '') {
        $raw = @file_get_contents($path);
    }
    if ($raw === false || trim($raw) === '') {
        error_log('parseEnvFile: cannot read (check file permissions — chmod 644): ' . $path);
        error_log('parseEnvFile: suggestion — run: chmod 644 ' . $path);
        return null;
    }

    $result = [];
    $lines  = explode("\n", str_replace("\r\n", "\n", str_replace("\r", "\n", $raw)));
    $fh = null; // not used in this path

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        if (strpos($line, '=') === false) continue;
        $eqPos = strpos($line, '=');
        $key   = trim(substr($line, 0, $eqPos));
        $val   = trim(substr($line, $eqPos + 1));
        if (strlen($val) >= 2) {
            $first = $val[0]; $last = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        if ($key !== '') $result[$key] = $val;
    }
    return $result;
}

// ── Find .env ─────────────────────────────────────────────────────────────────
$_envCandidates = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
];
$_envPath = null;
foreach ($_envCandidates as $_c) {
    if (file_exists($_c) && is_readable($_c)) {
        $_envPath = $_c;
        break;
    }
}

if ($_envPath === null) {
    error_log('CRITICAL: .env not found. Searched: ' . implode(', ', $_envCandidates));
    http_response_code(500);
    die(json_encode(['error' => 'Config error: .env not found. Searched: ' . implode(', ', $_envCandidates)]));
}

$_env = parseEnvFile($_envPath);

if ($_env === null || count($_env) === 0) {
    error_log('CRITICAL: .env at ' . $_envPath . ' could not be read or is empty.');
    http_response_code(500);
    die(json_encode(['error' => 'Config error: .env unreadable or empty at ' . $_envPath]));
}

// ── env() accessor — safe for PHP 8 strict typing ────────────────────────────
function env(string $key, $default = null) {
    global $_env;
    // Guard: if called before $_env is populated (e.g. inside a closure scope)
    if (!is_array($_env)) return $default;
    return array_key_exists($key, $_env) ? $_env[$key] : $default;
}

// ── Constants ─────────────────────────────────────────────────────────────────
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', ''));
define('DB_USER',    env('DB_USER', ''));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

define('SESSION_LIFETIME', 7 * 24 * 60 * 60);

define('RAZORPAY_KEY_ID',         env('RAZORPAY_KEY_ID', ''));
define('RAZORPAY_KEY_SECRET',     env('RAZORPAY_KEY_SECRET', ''));
define('RAZORPAY_WEBHOOK_SECRET', env('RAZORPAY_WEBHOOK_SECRET', ''));
define('PAYMENT_ENABLED',         env('PAYMENT_ENABLED', 'false') === 'true');

define('GOOGLE_CLIENT_ID',     env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET', ''));

$_rawSiteUrl = rtrim(env('SITE_URL', 'https://independentresearcher.in'), '/#');
define('SITE_URL',  $_rawSiteUrl);
define('SITE_NAME', env('SITE_NAME', 'IndependentResearcher'));
define('GOOGLE_REDIRECT_URI', SITE_URL . '/auth-google.php?action=callback');

define('SMTP_ENABLED',   env('SMTP_ENABLED',   'false') === 'true');
define('SMTP_HOST',      env('SMTP_HOST',       'smtp.hostinger.com'));
define('SMTP_PORT',      (int) env('SMTP_PORT', 587));
define('SMTP_SECURE',    env('SMTP_SECURE',     'tls'));
define('SMTP_USER',      env('SMTP_USER',       ''));
define('SMTP_PASS',      env('SMTP_PASS',       ''));
define('SMTP_FROM',      env('SMTP_FROM',       ''));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME',  'IndependentResearcher'));

// Admin emails — comma-separated list; all get role='admin' automatically on Google sign-in.
// Set ADMIN_EMAILS in .env to override. Falls back to legacy ADMIN_EMAIL if set.
$_adminEmailsRaw = getenv('ADMIN_EMAILS') ?: (getenv('ADMIN_EMAIL') ?: 'bhandaryaki@gmail.com');
define('ADMIN_EMAILS', $_adminEmailsRaw);
// Legacy single-value constant for backwards compat
define('ADMIN_EMAIL',  trim(explode(',', $_adminEmailsRaw)[0]));
unset($_adminEmailsRaw);

define('DEMO_MODE',           env('DEMO_MODE', 'false') === 'true');
define('PASSWORD_MIN_LENGTH', 8);
// Set MIGRATION_COMPLETE=true in .env once your production DB has all columns.
// This skips SHOW COLUMNS + ALTER TABLE on every Google login — set it after
// your first successful Google Sign-In on a fresh deployment.
define('MIGRATION_COMPLETE',  env('MIGRATION_COMPLETE', 'false') === 'true');
date_default_timezone_set('Asia/Kolkata');

// Log any missing critical keys
if (php_sapi_name() !== 'cli') {
    $_missing = [];
    if (empty(DB_NAME))              $_missing[] = 'DB_NAME';
    if (empty(DB_USER))              $_missing[] = 'DB_USER';
    if (empty(GOOGLE_CLIENT_ID))     $_missing[] = 'GOOGLE_CLIENT_ID';
    if (empty(GOOGLE_CLIENT_SECRET)) $_missing[] = 'GOOGLE_CLIENT_SECRET';
    if (!empty($_missing)) {
        error_log('WARNING: Missing .env keys: ' . implode(', ', $_missing) . ' — loaded from: ' . $_envPath);
    }
}

// ── DB ────────────────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
    }
    return $pdo;
}

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 1 : 0);
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function isAjaxRequest(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function getCurrentUser(): ?array {
    startSession();
    if (!isset($_SESSION['user_id'])) return null;
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, email, role, avatar_url, display_name, google_email FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        error_log('getCurrentUser: ' . $e->getMessage());
        return null;
    }
}


function isAdminEmail(string $email): bool {
    $list = array_map('trim', explode(',', ADMIN_EMAILS));
    return in_array(strtolower($email), array_map('strtolower', $list));
}

function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        if (isAjaxRequest()) {
            jsonResponse(['error' => 'Not authenticated'], 401);
        } else {
            header('Location: ' . SITE_URL . '/');
        }
        exit;
    }
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        if (isAjaxRequest()) {
            jsonResponse(['error' => 'Admin access required'], 403);
        } else {
            header('Location: ' . SITE_URL . '/');
        }
        exit;
    }
    return $user;
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function generateCSRFToken(): string {
    startSession();
    if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken(string $token): bool {
    startSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate a short-lived HMAC-signed access token for server-side simulation API calls.
 * Token format (base64url): userId|productId|expires|hmac
 * Used by api-simulation.php to validate requests without a full session check.
 */
function generateAccessToken(int $userId, string $productId, int $ttlSeconds = 3600): string {
    $expires = time() + $ttlSeconds;
    $payload = $userId . '|' . $productId . '|' . $expires;
    $sig     = hash_hmac('sha256', $payload, DB_PASS . GOOGLE_CLIENT_SECRET);
    return base64_encode($payload . '|' . $sig);
}

/**
 * Validate a token produced by generateAccessToken().
 * Returns true only if signature is valid, not expired, and matches userId+productId.
 */
function validateAccessToken(string $token, int $userId, string $productId): bool {
    if (empty($token)) return false;
    $decoded = base64_decode($token, true);
    if ($decoded === false) return false;
    $parts = explode('|', $decoded);
    if (count($parts) !== 4) return false;
    [$tUserId, $tProductId, $tExpires, $tSig] = $parts;
    if ((int)$tUserId !== $userId)         return false;
    if ($tProductId   !== $productId)      return false;
    if ((int)$tExpires < time())           return false;
    $payload  = $tUserId . '|' . $tProductId . '|' . $tExpires;
    $expected = hash_hmac('sha256', $payload, DB_PASS . GOOGLE_CLIENT_SECRET);
    return hash_equals($expected, $tSig);
}
