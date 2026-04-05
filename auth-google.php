<?php
/**
 * auth-google.php — Google OAuth 2.0 Handler
 */

require_once __DIR__ . '/config.php';
startSession();

$action = $_GET['action'] ?? '';

switch ($action) {

    // ── Step 1: Redirect to Google ────────────────────────────────────────────
    case 'login':
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $params = http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);

        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
        exit;

    // ── Step 2: Handle callback ───────────────────────────────────────────────
    case 'callback':
        try {
            // CSRF state check
            $state = $_GET['state'] ?? '';
            if (empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
                error_log('Google OAuth: invalid state. Session state: ' .
                    ($_SESSION['oauth_state'] ?? 'MISSING') . ' | GET state: ' . $state);
                header('Location: ' . SITE_URL . '/?error=oauth_state');
                exit;
            }
            unset($_SESSION['oauth_state']);

            $code = $_GET['code'] ?? '';
            if (!$code) {
                $err = $_GET['error_description'] ?? ($_GET['error'] ?? 'unknown');
                error_log('Google OAuth: no code — ' . $err);
                header('Location: ' . SITE_URL . '/?error=oauth_denied');
                exit;
            }

            // Exchange code for access token
            $tokenResponse = _googlePost('https://oauth2.googleapis.com/token', [
                'code'          => $code,
                'client_id'     => GOOGLE_CLIENT_ID,
                'client_secret' => GOOGLE_CLIENT_SECRET,
                'redirect_uri'  => GOOGLE_REDIRECT_URI,
                'grant_type'    => 'authorization_code',
            ]);

            if (empty($tokenResponse['access_token'])) {
                error_log('Google OAuth: token exchange failed — ' . json_encode($tokenResponse));
                header('Location: ' . SITE_URL . '/?error=oauth_token');
                exit;
            }

            // Fetch user profile from Google
            $userInfo = _googleGet(
                'https://www.googleapis.com/oauth2/v2/userinfo',
                $tokenResponse['access_token']
            );

            if (empty($userInfo['id']) || empty($userInfo['email'])) {
                error_log('Google OAuth: userinfo missing — ' . json_encode($userInfo));
                header('Location: ' . SITE_URL . '/?error=oauth_userinfo');
                exit;
            }

            // Upsert user and grant trial access
            $db     = getDB();
            $userId = _upsertGoogleUser($db, $userInfo);

            if (!$userId) {
                error_log('Google OAuth: upsert returned null for ' . $userInfo['email']);
                header('Location: ' . SITE_URL . '/?error=oauth_db');
                exit;
            }

            // Set session
            $_SESSION['user_id']       = $userId;
            $_SESSION['user_email']    = $userInfo['email'];
            $_SESSION['auth_provider'] = 'google';

            header('Location: ' . SITE_URL . '/simulations.php');
            exit;

        } catch (Throwable $e) {
            // Catch ALL errors (PDOException, TypeError, etc.) and log them
            error_log('Google OAuth CALLBACK ERROR: ' . $e->getMessage() .
                      ' | File: ' . $e->getFile() . ':' . $e->getLine() .
                      ' | Trace: ' . $e->getTraceAsString());
            header('Location: ' . SITE_URL . '/?error=oauth_internal');
            exit;
        }

    default:
        header('Location: ' . SITE_URL . '/');
        exit;
}

// ── cURL Helpers ──────────────────────────────────────────────────────────────

function _googlePost(string $url, array $fields): array {
    if (!function_exists('curl_init')) {
        error_log('Google OAuth: cURL extension not available');
        return [];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        error_log('Google OAuth: curl_init failed');
        return [];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno || $raw === false) {
        error_log('Google OAuth cURL POST error (' . $errno . '): ' . $error);
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('Google OAuth: token response not JSON: ' . substr($raw, 0, 500));
        return [];
    }
    return $decoded;
}

function _googleGet(string $url, string $accessToken): array {
    if (!function_exists('curl_init')) return [];
    $ch = curl_init($url);
    if ($ch === false) return [];
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno || $raw === false) {
        error_log('Google OAuth cURL GET error (' . $errno . '): ' . $error);
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('Google OAuth: userinfo not JSON: ' . substr($raw, 0, 500));
        return [];
    }
    return $decoded;
}

// ── User upsert ───────────────────────────────────────────────────────────────

function _upsertGoogleUser(PDO $db, array $info): ?int {
    $googleId    = $info['id'];
    $email       = $info['email'];
    $avatarUrl   = $info['picture'] ?? null;
    $googleName  = trim($info['name'] ?? ($info['given_name'] ?? ''));
    // Sanitise: max 80 chars, strip tags
    $googleName  = substr(strip_tags($googleName), 0, 80) ?: null;

    // Detect which columns actually exist in users table
    // (handles partial migrations gracefully)
    $colStmt = $db->query("SHOW COLUMNS FROM users");
    $existingCols = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    $hasGoogleId     = in_array('google_id',     $existingCols);
    $hasGoogleEmail  = in_array('google_email',  $existingCols);
    $hasAvatarUrl    = in_array('avatar_url',    $existingCols);
    $hasAuthProvider = in_array('auth_provider', $existingCols);
    $hasLastLogin    = in_array('last_login',    $existingCols);
    $hasPasswordHash = in_array('password_hash', $existingCols);

    // ── Run any missing migrations automatically ──────────────────────────────
    if (!$hasGoogleId) {
        $db->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL");
        $db->exec("ALTER TABLE users ADD INDEX idx_google_id (google_id)");
        $hasGoogleId = true;
        error_log('Auto-migration: added google_id column to users');
    }
    if (!$hasGoogleEmail) {
        $db->exec("ALTER TABLE users ADD COLUMN google_email VARCHAR(255) NULL");
        $hasGoogleEmail = true;
    }
    if (!$hasAvatarUrl) {
        $db->exec("ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL");
        $hasAvatarUrl = true;
    }
    if (!$hasAuthProvider) {
        $db->exec("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(20) DEFAULT 'email'");
        $hasAuthProvider = true;
    }
    if (!$hasLastLogin) {
        $db->exec("ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL");
        $hasLastLogin = true;
    }
    if (!$hasPasswordHash) {
        // Old schema used 'password' column
        $hasPassword = in_array('password', $existingCols);
        if ($hasPassword) {
            // Rename old column
            $db->exec("ALTER TABLE users CHANGE COLUMN `password` `password_hash` VARCHAR(255) NULL");
        } else {
            $db->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL");
        }
        $hasPasswordHash = true;
    }

    // ── Also ensure purchases columns exist before granting access ────────────
    _ensurePurchaseColumns($db);

    // ── Check for existing user ───────────────────────────────────────────────
    $stmt = $db->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
    $stmt->execute([$googleId, $email]);
    $existing = $stmt->fetch();

    if ($existing) {
        $roleUpdate = isAdminEmail($email) ? ', role = "admin"' : '';

        // Only update display_name if column exists and current value is blank/placeholder
        $nameUpdate = '';
        $nameParams = [$googleId, $email, $avatarUrl, $existing['id']];
        if ($googleName) {
            try {
                $db->query('SELECT display_name FROM users LIMIT 1');
                $nameUpdate = ', display_name = COALESCE(NULLIF(NULLIF(display_name, \'\'), \'name\'), ?)';
                $nameParams = [$googleId, $email, $avatarUrl, $googleName, $existing['id']];
            } catch (PDOException $e) {
                // display_name column not yet added — skip
            }
        }

        $db->prepare("
            UPDATE users
            SET google_id      = ?,
                google_email   = ?,
                avatar_url     = ?,
                auth_provider  = 'google',
                email_verified = 1,
                last_login     = NOW()
                {$nameUpdate}
                {$roleUpdate}
            WHERE id = ?
        ")->execute($nameParams);

        _grantTrialAccess($db, (int) $existing['id']);
        return (int) $existing['id'];
    }

    // ── New user ──────────────────────────────────────────────────────────────
    // Google-only accounts never use a password. We store an unusable placeholder
    // so the column (which is NOT NULL in some schema versions) is never null.
    // The '!' prefix ensures password_verify() always returns false for this hash.
    try {
        $unusableHash = '!google_' . bin2hex(random_bytes(20));
    } catch (Throwable $e) {
        // random_bytes() shouldn't fail, but be safe
        $unusableHash = '!google_' . md5(uniqid('', true) . microtime());
    }

    $roleForNew = isAdminEmail($email) ? 'admin' : 'user';

    // Ensure display_name column exists before inserting into it
    try {
        $db->query('SELECT display_name FROM users LIMIT 1');
    } catch (PDOException $e) {
        $db->exec('ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NULL AFTER email');
    }

    $db->prepare('
        INSERT INTO users
            (email, password_hash, auth_provider, google_id, google_email,
             avatar_url, display_name, email_verified, role, created_at, last_login)
        VALUES (?, ?, "google", ?, ?, ?, ?, 1, ?, NOW(), NOW())
    ')->execute([$email, $unusableHash, $googleId, $email, $avatarUrl, $googleName, $roleForNew]);

    $userId = (int) $db->lastInsertId();
    _grantTrialAccess($db, $userId);
    return $userId;
}

function _ensurePurchaseColumns(PDO $db): void {
    $colStmt = $db->query("SHOW COLUMNS FROM purchases");
    $cols    = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (!in_array('access_type', $cols)) {
        $db->exec("ALTER TABLE purchases ADD COLUMN access_type ENUM('paid','trial','demo') DEFAULT 'trial' AFTER status");
        error_log('Auto-migration: added access_type column to purchases');
    }

    // Always ensure 'trial' is in the status enum — safe to run even if already present
    $db->exec("ALTER TABLE purchases MODIFY COLUMN status ENUM('pending','paid','failed','demo','trial') DEFAULT 'pending'");
}

function _grantTrialAccess(PDO $db, int $userId): void {
    try {
        $db->prepare('
            INSERT IGNORE INTO purchases
                (user_id, product_id, status, payment_method, access_type, created_at)
            SELECT ?, id, "trial", "free", "trial", NOW()
            FROM products
            WHERE is_free_trial = TRUE AND is_active = TRUE
        ')->execute([$userId]);
    } catch (Throwable $e) {
        // Non-fatal — user can still log in, just won't have trial grants
        error_log('_grantTrialAccess failed for user ' . $userId . ': ' . $e->getMessage());
    }
}
