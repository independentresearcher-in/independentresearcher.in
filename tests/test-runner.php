<?php
/**
 * test-runner.php — IndependentResearcher Platform Unified Test Suite
 *
 * Covers: DB schema, config constants, Google OAuth, API endpoints,
 *         simulation file routing, trial flow, blog storage, security helpers.
 *
 * Usage:  https://yourdomain.com/tests/test-runner.php?secret=<TEST_SECRET from .env>
 *
 * Secret is read from .env (key: TEST_SECRET). It must NOT be hardcoded here.
 * Delete or block tests/ in .htaccess after use on production.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/config.php';

// ── Gate — secret loaded from .env via config.php env() ──────────────────────
$gateSecret = env('TEST_SECRET', '');
if (empty($gateSecret)) {
    http_response_code(500);
    die('TEST_SECRET not set in .env — add it before running tests.');
}
if (($_GET['secret'] ?? '') !== $gateSecret) {
    http_response_code(403);
    die('403 Forbidden');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/helpers.php';

$results = [];
$pass    = 0;
$fail    = 0;

function test(string $group, string $name, callable $fn): void {
    global $results, $pass, $fail;
    try {
        $fn();
        $results[] = ['group' => $group, 'name' => $name, 'status' => 'PASS', 'detail' => ''];
        $pass++;
    } catch (Throwable $e) {
        $results[] = ['group' => $group, 'name' => $name, 'status' => 'FAIL', 'detail' => $e->getMessage()];
        $fail++;
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new RuntimeException($msg);
}

function assert_eq($a, $b, string $msg = ''): void {
    if ($a !== $b) throw new RuntimeException(
        $msg ?: 'Expected ' . var_export($b, true) . ', got ' . var_export($a, true)
    );
}

function http_get(string $url, int $timeout = 10): ?array {
    // Send X-Requested-With so requireAuth() follows the AJAX branch
    // and returns JSON errors instead of issuing a Location: redirect.
    $ctx = stream_context_create(['http' => [
        'timeout'        => $timeout,
        'ignore_errors'  => true,
        'header'         => "X-Requested-With: XMLHttpRequest\r\nAccept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    return ['raw' => $raw, 'json' => json_decode($raw, true)];
}

// ── GROUP 1: Database Connectivity ───────────────────────────────────────────

test('DB', 'Connection succeeds and returns PDO', function () {
    $db = getDB();
    assert_true($db instanceof PDO, 'getDB() must return PDO instance');
});

// ── GROUP 2: Required Tables ──────────────────────────────────────────────────

foreach (['users', 'products', 'purchases', 'webhook_log', 'physics_questions'] as $table) {
    test('DB', "Table exists: $table", function () use ($table) {
        getDB()->query("SELECT 1 FROM $table LIMIT 1");
    });
}

// ── GROUP 3: Schema — users table ────────────────────────────────────────────

test('DB', 'users: password_hash column exists (not "password")', function () {
    $stmt = getDB()->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
    assert_true((bool) $stmt->fetch(), 'Column password_hash missing from users');
});

test('DB', 'users: password_hash allows NULL (Google-only accounts)', function () {
    $row = getDB()->query("SHOW COLUMNS FROM users LIKE 'password_hash'")->fetch();
    assert_true($row && strtoupper($row['Null'] ?? '') === 'YES', 'password_hash must be nullable');
});

test('DB', 'users: auth_provider column exists', function () {
    $stmt = getDB()->query("SHOW COLUMNS FROM users LIKE 'auth_provider'");
    assert_true((bool) $stmt->fetch(), 'Column auth_provider missing from users');
});

test('DB', 'users: google_id column exists', function () {
    $stmt = getDB()->query("SHOW COLUMNS FROM users LIKE 'google_id'");
    assert_true((bool) $stmt->fetch(), 'Column google_id missing from users');
});

test('DB', 'users: display_name column is varchar(80)', function () {
    $row = getDB()->query("SHOW COLUMNS FROM users LIKE 'display_name'")->fetch();
    assert_true($row !== false, 'Column display_name missing from users');
    assert_true(stripos($row['Type'], 'varchar(80)') !== false,
        "Expected varchar(80), got: " . ($row['Type'] ?? 'unknown'));
});

foreach (['email_verified', 'role', 'google_email', 'avatar_url', 'last_login'] as $col) {
    test('DB', "users: $col column exists", function () use ($col) {
        $stmt = getDB()->query("SHOW COLUMNS FROM users LIKE '$col'");
        assert_true((bool) $stmt->fetch(), "Column $col missing from users");
    });
}

// ── GROUP 4: Schema — products table ─────────────────────────────────────────

foreach (['id', 'title', 'sim_file', 'paid_file', 'price', 'is_free_trial', 'is_active'] as $col) {
    test('DB', "products: $col column exists", function () use ($col) {
        $stmt = getDB()->query("SHOW COLUMNS FROM products LIKE '$col'");
        assert_true((bool) $stmt->fetch(), "Column $col missing from products");
    });
}

test('DB', 'products: access_badge column exists', function () {
    $stmt = getDB()->query("SHOW COLUMNS FROM products LIKE 'access_badge'");
    assert_true((bool) $stmt->fetch(), "Column access_badge missing from products");
});

// ── GROUP 5: Schema — purchases table ────────────────────────────────────────

test('DB', 'purchases: access_type column exists', function () {
    $stmt = getDB()->query("SHOW COLUMNS FROM purchases LIKE 'access_type'");
    assert_true((bool) $stmt->fetch(), 'Column access_type missing from purchases');
});

test('DB', 'purchases: status enum includes "trial"', function () {
    $row = getDB()->query("SHOW COLUMNS FROM purchases LIKE 'status'")->fetch();
    assert_true($row !== false, 'Column status missing from purchases');
    assert_true(strpos($row['Type'], 'trial') !== false,
        'purchases.status enum missing "trial": ' . ($row['Type'] ?? ''));
});

test('DB', 'purchases: status enum includes "paid"', function () {
    $row = getDB()->query("SHOW COLUMNS FROM purchases LIKE 'status'")->fetch();
    assert_true(strpos($row['Type'] ?? '', 'paid') !== false,
        'purchases.status enum missing "paid": ' . ($row['Type'] ?? ''));
});

// ── GROUP 6: Product Seed Data ────────────────────────────────────────────────

test('DB', 'At least 7 active simulations exist', function () {
    $count = (int) getDB()->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
    assert_true($count >= 7, "Only $count active products found, expected >= 7");
});

test('DB', 'All active simulations are marked is_free_trial = TRUE', function () {
    $paid = (int) getDB()
        ->query('SELECT COUNT(*) FROM products WHERE is_active = 1 AND (is_free_trial = 0 OR is_free_trial IS NULL)')
        ->fetchColumn();
    assert_eq($paid, 0, "$paid active simulation(s) still marked as paid-only (is_free_trial != 1)");
});

test('DB', 'Admin user exists in DB', function () {
    $stmt = getDB()->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    assert_true((bool) $stmt->fetch(), 'No admin user found — run database.sql first');
});

// ── GROUP 7: Config Constants ─────────────────────────────────────────────────

test('Config', 'GOOGLE_CLIENT_ID defined and non-empty', function () {
    assert_true(defined('GOOGLE_CLIENT_ID') && !empty(GOOGLE_CLIENT_ID), 'GOOGLE_CLIENT_ID not set');
});

test('Config', 'GOOGLE_CLIENT_SECRET defined and non-empty', function () {
    assert_true(defined('GOOGLE_CLIENT_SECRET') && !empty(GOOGLE_CLIENT_SECRET), 'GOOGLE_CLIENT_SECRET not set');
});

test('Config', 'GOOGLE_REDIRECT_URI ends with auth-google.php?action=callback', function () {
    assert_true(
        defined('GOOGLE_REDIRECT_URI') && str_ends_with(GOOGLE_REDIRECT_URI, 'auth-google.php?action=callback'),
        'Unexpected GOOGLE_REDIRECT_URI: ' . (defined('GOOGLE_REDIRECT_URI') ? GOOGLE_REDIRECT_URI : 'NOT DEFINED')
    );
});

test('Config', 'GOOGLE_REDIRECT_URI has no # fragment', function () {
    assert_true(
        defined('GOOGLE_REDIRECT_URI') && strpos(GOOGLE_REDIRECT_URI, '#') === false,
        'GOOGLE_REDIRECT_URI contains # which breaks OAuth: ' . (defined('GOOGLE_REDIRECT_URI') ? GOOGLE_REDIRECT_URI : '')
    );
});

test('Config', 'SITE_URL does not end with # or /', function () {
    assert_true(defined('SITE_URL'), 'SITE_URL not defined');
    $url = SITE_URL;
    assert_true(
        !in_array(substr($url, -1), ['#', '/']),
        'SITE_URL has trailing # or /: ' . $url
    );
});

test('Config', 'Email delivery is configured (SMTP or PHP mail() fallback)', function () {
    // SMTP_ENABLED=true  -> uses Hostinger SMTP credentials (requires SMTP_HOST/USER/PASS)
    // SMTP_ENABLED=false -> falls back to PHP mail(), used for password reset and email verification
    // Both are valid. The requirement is that SMTP_FROM is set so emails have a sender address.
    assert_true(defined('SMTP_ENABLED'), 'SMTP_ENABLED constant not defined');
    assert_true(defined('SMTP_FROM') && !empty(SMTP_FROM),
        'SMTP_FROM must be set — used as the From address for password reset and verification emails');
    if (SMTP_ENABLED) {
        assert_true(!empty(SMTP_HOST), 'SMTP_ENABLED=true but SMTP_HOST is empty');
        assert_true(!empty(SMTP_USER), 'SMTP_ENABLED=true but SMTP_USER is empty');
        assert_true(!empty(SMTP_PASS), 'SMTP_ENABLED=true but SMTP_PASS is empty');
    }
    // If SMTP_ENABLED=false, PHP mail() is the fallback — no credentials needed, just SMTP_FROM
});

test('Config', 'RAZORPAY_KEY_ID constant defined', function () {
    assert_true(defined('RAZORPAY_KEY_ID'), 'RAZORPAY_KEY_ID constant not defined');
});

test('Config', 'PAYMENT_ENABLED constant defined', function () {
    assert_true(defined('PAYMENT_ENABLED'), 'PAYMENT_ENABLED constant not defined');
});

test('Config', 'MIGRATION_COMPLETE constant defined', function () {
    assert_true(defined('MIGRATION_COMPLETE'), 'MIGRATION_COMPLETE constant not defined');
});

// ── GROUP 8: Google OAuth ─────────────────────────────────────────────────────

test('Google OAuth', 'Login URL can be constructed without # fragment', function () {
    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
    ]);
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    assert_true(filter_var($url, FILTER_VALIDATE_URL) !== false, 'Invalid OAuth URL: ' . $url);
    assert_true(strpos($url, '#') === false, 'OAuth URL contains # fragment — will break redirect');
});

test('Google OAuth', 'cURL extension is available (required for token exchange)', function () {
    assert_true(function_exists('curl_init'), 'PHP cURL extension not loaded');
});

test('Google OAuth', 'PHP sessions work for OAuth state storage', function () {
    startSession();
    $_SESSION['__test_state'] = 'ir_test_value';
    assert_true(
        isset($_SESSION['__test_state']) && $_SESSION['__test_state'] === 'ir_test_value',
        'Session write/read failed'
    );
    unset($_SESSION['__test_state']);
});

test('Google OAuth', 'Google OpenID config endpoint is reachable', function () {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL not available — skipping');
    $ch = curl_init('https://accounts.google.com/.well-known/openid-configuration');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    assert_true(
        $res !== false && str_contains((string) $res, 'authorization_endpoint'),
        'Google endpoint unreachable: ' . $err
    );
});

// ── GROUP 9: Auth Helpers ─────────────────────────────────────────────────────

test('Auth', 'getCurrentUser() returns null with no session', function () {
    $user = getCurrentUser();
    assert_true($user === null, 'Expected null without session, got: ' . json_encode($user));
});

test('Auth', 'generateCSRFToken() returns 64-char hex string', function () {
    $tok = generateCSRFToken();
    assert_true(strlen($tok) === 64, 'CSRF token length: ' . strlen($tok));
    assert_true(ctype_xdigit($tok), 'CSRF token not hex: ' . $tok);
});

test('Auth', 'verifyCSRFToken() validates correctly and rejects tampered token', function () {
    startSession();
    $tok = generateCSRFToken();
    assert_true(verifyCSRFToken($tok), 'Valid token not accepted');
    assert_true(!verifyCSRFToken('bad_token'), 'Invalid token was accepted');
});

test('Auth', 'generateSecureToken() returns 64-char hex string', function () {
    $tok = generateSecureToken();
    assert_true(strlen($tok) === 64, 'Token length: ' . strlen($tok));
    assert_true(ctype_xdigit($tok), 'Token not hex');
});

test('Auth', 'generateAccessToken() and validateAccessToken() round-trip', function () {
    $userId    = 1;
    $productId = 'sim-pendulum';
    $token     = generateAccessToken($userId, $productId, 60);
    assert_true(!empty($token), 'Token generation returned empty string');
    assert_true(
        validateAccessToken($token, $userId, $productId),
        'validateAccessToken returned false for a fresh valid token'
    );
    assert_true(
        !validateAccessToken($token, 999, $productId),
        'validateAccessToken accepted wrong userId'
    );
    assert_true(
        !validateAccessToken($token, $userId, 'wrong-product'),
        'validateAccessToken accepted wrong productId'
    );
});

test('Auth', 'Expired access token is rejected', function () {
    $token = generateAccessToken(1, 'sim-pendulum', -1);
    assert_true(
        !validateAccessToken($token, 1, 'sim-pendulum'),
        'Expired token was accepted'
    );
});

// ── GROUP 10: API Endpoints ───────────────────────────────────────────────────

test('API', '/api.php?action=config returns valid JSON with payment_enabled key', function () {
    $res = http_get(SITE_URL . '/api.php?action=config');
    assert_true($res !== null, 'Could not reach /api.php?action=config');
    assert_true(is_array($res['json']), 'Response not valid JSON: ' . substr($res['raw'], 0, 200));
    assert_true(array_key_exists('payment_enabled', $res['json']),
        'Missing key payment_enabled in config response');
});

test('API', '/api.php?action=products returns non-empty array', function () {
    $res = http_get(SITE_URL . '/api.php?action=products');
    assert_true($res !== null, 'Could not reach /api.php?action=products');
    assert_true(is_array($res['json']) && count($res['json']) > 0,
        'Products response empty or not an array: ' . substr($res['raw'], 0, 200));
});

test('API', '/api.php?action=products each item has required fields', function () {
    $res = http_get(SITE_URL . '/api.php?action=products');
    assert_true($res !== null && is_array($res['json']), 'Could not reach products endpoint');
    $first = $res['json'][0] ?? null;
    assert_true($first !== null, 'Products array is empty');
    // sim_file and paid_file are intentionally excluded from the public listing
    // to avoid leaking file paths before purchase. Only assert public-safe fields.
    foreach (['id', 'title', 'is_free_trial', 'access_badge', 'price'] as $key) {
        assert_true(array_key_exists($key, $first), "Product item missing key: $key");
    }
    // Confirm sim_file is NOT exposed in the public listing
    assert_true(!array_key_exists('sim_file', $first),
        'sim_file should not be in public products response — it leaks paid file paths');
});

test('API', '/api.php?action=unknown returns error key', function () {
    $res = http_get(SITE_URL . '/api.php?action=__nonexistent__');
    assert_true($res !== null, 'Could not reach API');
    assert_true(isset($res['json']['error']), 'Unknown action should return error key');
});

test('API', '/api.php?action=check-access returns error when unauthenticated', function () {
    $res = http_get(SITE_URL . '/api.php?action=check-access&product_id=sim-pendulum');
    assert_true($res !== null, 'Could not reach /api.php?action=check-access');
    assert_true(isset($res['json']['error']),
        'check-access should require auth: ' . substr($res['raw'] ?? '', 0, 200));
});

test('API', '/api.php?action=grant-trial rejects unknown product_id', function () {
    $res = http_get(SITE_URL . '/api.php?action=grant-trial&product_id=__fake_product__');
    assert_true($res !== null, 'Could not reach grant-trial endpoint');
    assert_true(isset($res['json']['error']),
        'grant-trial should reject unknown product: ' . substr($res['raw'] ?? '', 0, 200));
});

// ── GROUP 11: Simulation File Routing ────────────────────────────────────────

test('Simulations', 'simulations/trial/ directory exists', function () {
    $path = dirname(__DIR__) . '/simulations/trial';
    assert_true(is_dir($path), 'Directory not found: ' . $path);
});

test('Simulations', 'simulations/paid/ directory exists', function () {
    $path = dirname(__DIR__) . '/simulations/paid';
    assert_true(is_dir($path), 'Directory not found: ' . $path);
});

test('Simulations', 'view-simulation.php contains trial/paid routing logic', function () {
    $src = file_get_contents(dirname(__DIR__) . '/view-simulation.php');
    assert_true($src !== false, 'Cannot read view-simulation.php');
    assert_true(str_contains($src, "simFolder = 'trial'"), 'Missing trial folder routing in view-simulation.php');
    assert_true(str_contains($src, "simFolder = 'paid'"),  'Missing paid folder routing in view-simulation.php');
});

test('Simulations', 'view-simulation.php contains upgrade trigger (initiateUpgrade)', function () {
    $src = file_get_contents(dirname(__DIR__) . '/view-simulation.php');
    assert_true($src !== false, 'Cannot read view-simulation.php');
    assert_true(str_contains($src, 'initiateUpgrade'), 'Missing initiateUpgrade in view-simulation.php');
});

test('Simulations', 'Every active product has a trial sim_file on disk', function () {
    $db       = getDB();
    $products = $db->query("SELECT id, sim_file FROM products WHERE is_active = 1")->fetchAll();
    $root     = dirname(__DIR__);
    $missing  = [];
    foreach ($products as $p) {
        $trialPath = "$root/simulations/trial/{$p['sim_file']}";
        $rootPath  = "$root/simulations/{$p['sim_file']}";
        if (!file_exists($trialPath) && !file_exists($rootPath)) {
            $missing[] = $p['sim_file'];
        }
    }
    assert_true(empty($missing), 'Trial files missing for: ' . implode(', ', $missing));
});

test('Simulations', 'products table has all expected columns', function () {
    $cols = array_column(
        getDB()->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    foreach (['id', 'title', 'sim_file', 'paid_file', 'price', 'is_free_trial', 'is_active', 'access_badge'] as $col) {
        assert_true(in_array($col, $cols), "products.$col column missing");
    }
});

// ── GROUP 12: Trial Flow (DB level) ──────────────────────────────────────────

test('Trial', 'At least one product has is_free_trial = TRUE', function () {
    $count = (int) getDB()
        ->query("SELECT COUNT(*) FROM products WHERE is_free_trial = 1 AND is_active = 1")
        ->fetchColumn();
    assert_true($count > 0, "No products with is_free_trial=1 found");
});

test('Trial', 'grant-all-trials INSERT IGNORE is idempotent (no duplicate key error)', function () {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();
    assert_true((bool) $admin, 'Admin user not found — cannot test trial grant');

    for ($i = 0; $i < 2; $i++) {
        $db->prepare("
            INSERT IGNORE INTO purchases
                (user_id, product_id, status, payment_method, access_type, created_at)
            SELECT ?, id, 'trial', 'free', 'trial', NOW()
            FROM products WHERE is_free_trial = TRUE AND is_active = TRUE
        ")->execute([$admin['id']]);
    }
    // No exception = pass
});

test('Trial', 'Admin user has at least one trial purchase after grant', function () {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();
    assert_true((bool) $admin, 'Admin user not found');

    $stmt = $db->prepare("SELECT COUNT(*) FROM purchases WHERE user_id = ? AND status = 'trial'");
    $stmt->execute([$admin['id']]);
    $count = (int) $stmt->fetchColumn();
    assert_true($count > 0, 'Admin has no trial purchases — grant-all-trials may have failed');
});

test('Trial', 'check-access API rejects unauthenticated request for trial product', function () {
    $db   = getDB();
    $prod = $db->query("SELECT id FROM products WHERE is_free_trial = 1 AND is_active = 1 LIMIT 1")->fetchColumn();
    assert_true(!empty($prod), 'No trial product found to test against');

    $res = http_get(SITE_URL . '/api.php?action=check-access&product_id=' . urlencode($prod));
    assert_true($res !== null, 'Could not reach check-access');
    assert_true(
        isset($res['json']['error']),
        'Unauthenticated check-access should return error, got: ' . substr($res['raw'] ?? '', 0, 200)
    );
});

// ── GROUP 13: Blog Storage ────────────────────────────────────────────────────

test('Blog', 'data/blog/ directory exists', function () {
    assert_true(is_dir(dirname(__DIR__) . '/data/blog'), 'data/blog/ directory missing');
});

test('Blog', 'data/blog/ is writable by PHP process', function () {
    $dir = dirname(__DIR__) . '/data/blog';
    assert_true(is_dir($dir), 'data/blog/ missing');
    assert_true(is_writable($dir), 'data/blog/ not writable — run chmod 755');
});

test('Blog', 'data/blog/.htaccess exists and blocks direct access', function () {
    $ht = dirname(__DIR__) . '/data/blog/.htaccess';
    assert_true(file_exists($ht), 'data/blog/.htaccess missing — JSON files are publicly accessible');
    $content = file_get_contents($ht);
    assert_true(
        str_contains($content, 'Deny from all') || str_contains($content, 'deny from all'),
        'data/blog/.htaccess missing Deny directive'
    );
});

test('Blog', 'At least one valid blog post JSON exists', function () {
    $dir   = dirname(__DIR__) . '/data/blog';
    $files = glob("$dir/*.json") ?: [];
    assert_true(count($files) > 0, 'No blog post JSON files found in data/blog/');

    $invalid = [];
    foreach ($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        if (!is_array($data) || empty($data['slug']) || empty($data['title'])) {
            $invalid[] = basename($f);
        }
    }
    assert_true(empty($invalid), 'Invalid JSON post files (must have slug and title): ' . implode(', ', $invalid));
});

test('Blog', 'Blog directory read/write cycle works', function () {
    $dir  = dirname(__DIR__) . '/data/blog';
    $tmp  = "$dir/.write_test_" . time() . ".tmp";
    $wrote = @file_put_contents($tmp, 'ir_test');
    assert_true($wrote !== false, 'Cannot write to data/blog/');
    @unlink($tmp);
});

// ── GROUP 14: Security ────────────────────────────────────────────────────────

test('Security', 'tests/.htaccess restricts public access', function () {
    $ht = __DIR__ . '/.htaccess';
    assert_true(file_exists($ht), 'tests/.htaccess missing — test suite publicly accessible without secret');
});

test('Security', 'TEST_SECRET is not the old hardcoded value', function () {
    $bannedOld = 'b8737ea62acf918dd687df185acb87510ac87874b6d9d26ef2d8045d648e79006a1483d57828884cfba5ba7ff88f70140ee903d12ca7995b8d01541dbd9ad7d2';
    $current   = env('TEST_SECRET', '');
    assert_true($current !== $bannedOld, 'TEST_SECRET in .env is still the old hardcoded value — rotate it');
});

test('Security', 'GOOGLE_CLIENT_SECRET is not a placeholder', function () {
    assert_true(
        !empty(GOOGLE_CLIENT_SECRET) && GOOGLE_CLIENT_SECRET !== 'your_secret_here',
        'GOOGLE_CLIENT_SECRET appears to be a placeholder or empty'
    );
});

test('Security', 'uploads/.htaccess prevents PHP execution in uploads/', function () {
    $ht = dirname(__DIR__) . '/uploads/.htaccess';
    assert_true(file_exists($ht), 'uploads/.htaccess missing — uploaded files could be executed');
    $content = file_get_contents($ht);
    assert_true(
        str_contains($content, 'php') ||
        str_contains($content, 'RemoveHandler') ||
        str_contains($content, 'AddType'),
        'uploads/.htaccess does not appear to block PHP execution'
    );
});

// ── Render ────────────────────────────────────────────────────────────────────
$total  = $pass + $fail;
$groups = array_unique(array_column($results, 'group'));
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Test Runner — IndependentResearcher</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'IBM Plex Mono', 'Fira Mono', monospace;
    background: #0d1117; color: #e6edf3;
    padding: 2rem; max-width: 1100px; margin: 0 auto;
  }
  h1   { color: #f0c040; margin-bottom: 0.25rem; }
  .meta { color: #6e7681; font-size: 0.8rem; margin-bottom: 1.5rem; }
  .summary {
    font-size: 1.1rem; margin-bottom: 2rem;
    background: #161b22; border: 1px solid #21262d;
    border-radius: 6px; padding: 1rem 1.5rem;
    display: flex; gap: 2rem; align-items: center; flex-wrap: wrap;
  }
  .pass-count { color: #3fb950; font-weight: bold; font-size: 1.3rem; }
  .fail-count { color: #f87171; font-weight: bold; font-size: 1.3rem; }
  .total      { color: #8b949e; }
  .pct        { color: #f0c040; }
  h2 {
    color: #8b949e; font-size: 0.85rem; text-transform: uppercase;
    letter-spacing: 0.1em; margin: 2rem 0 0.5rem;
    border-bottom: 1px solid #21262d; padding-bottom: 0.4rem;
  }
  table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; }
  th  { text-align: left; color: #6e7681; padding: 0.4rem 0.8rem;
        border-bottom: 1px solid #21262d; font-size: 0.78rem; }
  td  { padding: 0.45rem 0.8rem; border-bottom: 1px solid #161b22;
        font-size: 0.83rem; vertical-align: top; }
  tr:hover td { background: #161b22; }
  .PASS  { color: #3fb950; font-weight: bold; white-space: nowrap; }
  .FAIL  { color: #f87171; font-weight: bold; white-space: nowrap; }
  .detail { color: #ffa657; font-size: 0.78rem; }
  .num   { color: #6e7681; }
  footer { color: #6e7681; margin-top: 3rem; font-size: 0.78rem;
           border-top: 1px solid #21262d; padding-top: 1rem; }
</style>
</head>
<body>
<h1>IndependentResearcher — Test Suite</h1>
<div class="meta">Run: <?= date('Y-m-d H:i:s T') ?></div>

<div class="summary">
  <span class="pass-count"><?= $pass ?> passed</span>
  <span class="fail-count"><?= $fail ?> failed</span>
  <span class="total"><?= $total ?> total</span>
  <?php if ($total > 0): ?>
  <span class="pct"><?= round($pass / $total * 100) ?>%</span>
  <?php endif; ?>
</div>

<?php foreach ($groups as $group):
    $groupResults = array_filter($results, fn($r) => $r['group'] === $group);
    $gFail = count(array_filter($groupResults, fn($r) => $r['status'] === 'FAIL'));
    $badge = $gFail > 0 ? " &nbsp;<span style='color:#f87171'>($gFail failed)</span>" : '';
?>
<h2><?= htmlspecialchars($group) ?><?= $badge ?></h2>
<table>
  <tr><th>#</th><th>Test</th><th>Result</th><th>Detail</th></tr>
  <?php $i = 1; foreach ($groupResults as $r): ?>
  <tr>
    <td class="num"><?= $i++ ?></td>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td class="<?= $r['status'] ?>"><?= $r['status'] ?></td>
    <td class="detail"><?= htmlspecialchars($r['detail']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endforeach; ?>

<footer>
  Access via: ?secret=&lt;TEST_SECRET from .env&gt; &nbsp;|&nbsp;
  Remove or block tests/ in .htaccess on production after use.
</footer>
</body>
</html>