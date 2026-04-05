<?php
/**
 * test-runner.php — IndependentResearcher Platform Test Suite
 *
 * Runs all core functionality tests against the live server.
 * Usage: https://yourdomain.com/tests/test-runner.php?secret=b8737ea62acf918dd687df185acb87510ac87874b6d9d26ef2d8045d648e79006a1483d57828884cfba5ba7ff88f70140ee903d12ca7995b8d01541dbd9ad7d2
 *
 * DO NOT deploy this to production without the secret gate.
 * Delete the tests/ folder or block it in .htaccess after use.
 */

// ── Gate ─────────────────────────────────────────────────────────────────────
define('TEST_SECRET', 'b8737ea62acf918dd687df185acb87510ac87874b6d9d26ef2d8045d648e79006a1483d57828884cfba5ba7ff88f70140ee903d12ca7995b8d01541dbd9ad7d2');   // Change this before deploying
if (($_GET['secret'] ?? '') !== TEST_SECRET) {
    http_response_code(403);
    die('403 Forbidden');
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/config.php';

$results = [];
$pass    = 0;
$fail    = 0;

function test(string $name, callable $fn): void {
    global $results, $pass, $fail;
    try {
        $fn();
        $results[] = ['name' => $name, 'status' => 'PASS', 'detail' => ''];
        $pass++;
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $e->getMessage()];
        $fail++;
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new RuntimeException($msg);
}

function assert_eq($a, $b, string $msg = ''): void {
    if ($a !== $b) throw new RuntimeException(($msg ?: 'Expected ' . var_export($b,true) . ', got ' . var_export($a,true)));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

// 1. Database connectivity
test('DB: connection succeeds', function() {
    $db = getDB();
    assert_true($db instanceof PDO, 'getDB() must return PDO instance');
});

// 2. Required tables exist
test('DB: users table exists', function() {
    $db = getDB();
    $db->query('SELECT 1 FROM users LIMIT 1');
});

test('DB: products table exists', function() {
    $db = getDB();
    $db->query('SELECT 1 FROM products LIMIT 1');
});

test('DB: purchases table exists', function() {
    $db = getDB();
    $db->query('SELECT 1 FROM purchases LIMIT 1');
});

test('DB: webhook_log table exists', function() {
    $db = getDB();
    $db->query('SELECT 1 FROM webhook_log LIMIT 1');
});

test('DB: physics_questions table exists', function() {
    $db = getDB();
    $db->query('SELECT 1 FROM physics_questions LIMIT 1');
});

// 3. Schema columns
test('DB: users.password_hash column exists (not "password")', function() {
    $db   = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
    assert_true((bool)$stmt->fetch(), 'Column password_hash missing from users');
});

test('DB: users.auth_provider column exists', function() {
    $db   = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'auth_provider'");
    assert_true((bool)$stmt->fetch(), 'Column auth_provider missing from users');
});

test('DB: users.google_id column exists', function() {
    $db   = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'google_id'");
    assert_true((bool)$stmt->fetch(), 'Column google_id missing from users');
});

test('DB: products.is_free_trial column exists', function() {
    $db   = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM products LIKE 'is_free_trial'");
    assert_true((bool)$stmt->fetch(), 'Column is_free_trial missing from products');
});

test('DB: purchases.access_type column exists', function() {
    $db   = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM purchases LIKE 'access_type'");
    assert_true((bool)$stmt->fetch(), 'Column access_type missing from purchases');
});

test('DB: purchases.status includes "trial" enum', function() {
    $db   = getDB();
    $stmt = $db->query("SHOW COLUMNS FROM purchases LIKE 'status'");
    $col  = $stmt->fetch();
    assert_true(strpos($col['Type'], 'trial') !== false, 'purchases.status enum missing "trial": ' . $col['Type']);
});

// 4. Products seeded
test('DB: at least 7 simulations exist', function() {
    $db    = getDB();
    $count = $db->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
    assert_true($count >= 7, "Only $count active products found, expected >= 7");
});

test('DB: all active simulations are marked is_free_trial = TRUE', function() {
    $db   = getDB();
    $paid = $db->query('SELECT COUNT(*) FROM products WHERE is_active = 1 AND is_free_trial = 0')->fetchColumn();
    assert_eq((int)$paid, 0, "$paid simulations still marked as paid-only");
});

// 5. Config constants
test('Config: GOOGLE_CLIENT_ID defined', function() {
    assert_true(defined('GOOGLE_CLIENT_ID') && !empty(GOOGLE_CLIENT_ID), 'GOOGLE_CLIENT_ID not set');
});

test('Config: GOOGLE_CLIENT_SECRET defined', function() {
    assert_true(defined('GOOGLE_CLIENT_SECRET') && !empty(GOOGLE_CLIENT_SECRET), 'GOOGLE_CLIENT_SECRET not set');
});

test('Config: GOOGLE_REDIRECT_URI has no # fragment', function() {
    $uri = GOOGLE_REDIRECT_URI;
    assert_true(strpos($uri, '#') === false, 'GOOGLE_REDIRECT_URI contains # which breaks OAuth: ' . $uri);
});

test('Config: GOOGLE_REDIRECT_URI ends with auth-google.php?action=callback', function() {
    assert_true(str_ends_with(GOOGLE_REDIRECT_URI, 'auth-google.php?action=callback'), 'Unexpected GOOGLE_REDIRECT_URI: ' . GOOGLE_REDIRECT_URI);
});

test('Config: SITE_URL does not end with # or /', function() {
    $url = SITE_URL;
    assert_true(!in_array(substr($url, -1), ['#', '/']), 'SITE_URL has trailing # or /: ' . $url);
});

test('Config: SMTP_ENABLED is false (Google OAuth is primary)', function() {
    assert_true(SMTP_ENABLED === false, 'SMTP_ENABLED should be false — use Google OAuth');
});

test('Config: RAZORPAY_KEY_ID defined', function() {
    assert_true(defined('RAZORPAY_KEY_ID') && !empty(RAZORPAY_KEY_ID), 'RAZORPAY_KEY_ID not set');
});

// 6. Auth helpers
test('Auth: getCurrentUser() returns null when no session', function() {
    // Fresh context — no session set
    $user = getCurrentUser();
    assert_true($user === null, 'Expected null without session, got: ' . json_encode($user));
});

test('Auth: admin user exists in DB', function() {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    assert_true((bool)$stmt->fetch(), 'No admin user found — run database.sql first');
});

// 7. API endpoint reachability (self-request)
test('API: /api.php?action=config returns valid JSON', function() {
    $url = SITE_URL . '/api.php?action=config';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    assert_true($raw !== false, 'Could not reach ' . $url);
    $data = json_decode($raw, true);
    assert_true(is_array($data), 'Response is not valid JSON: ' . substr($raw, 0, 200));
    assert_true(array_key_exists('payment_enabled', $data), 'Missing payment_enabled in config response');
});

test('API: /api.php?action=products returns array', function() {
    $url = SITE_URL . '/api.php?action=products';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = json_decode($raw, true);
    assert_true(is_array($data), 'Products response not an array: ' . substr($raw, 0, 200));
    assert_true(count($data) > 0, 'No products returned');
});

test('API: /api.php?action=unknown returns 404 JSON', function() {
    $url = SITE_URL . '/api.php?action=__nonexistent__';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = json_decode($raw, true);
    assert_true(isset($data['error']), 'Unknown action should return error key');
});

test('API: /api.php?action=check-access returns 401 when unauthenticated', function() {
    $url  = SITE_URL . '/api.php?action=check-access&product_id=sim-pendulum';
    $ctx  = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $data = json_decode($raw, true);
    assert_true(isset($data['error']), 'check-access should require auth: ' . substr($raw, 0, 200));
});

// 8. Google OAuth URL generation
test('Google OAuth: login URL can be constructed', function() {
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

// 9. Trial grant flow (DB level)
test('Trial: grant-all-trials INSERT IGNORE works without duplicate error', function() {
    $db = getDB();
    // Use admin user (id=1 guaranteed by database.sql seed)
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();
    assert_true((bool)$admin, 'Admin user not found');

    $stmt = $db->prepare("
        INSERT IGNORE INTO purchases
            (user_id, product_id, status, payment_method, access_type, created_at)
        SELECT ?, id, 'trial', 'free', 'trial', NOW()
        FROM products WHERE is_free_trial = TRUE AND is_active = TRUE
    ");
    $stmt->execute([$admin['id']]);
    // No exception = pass
});

test('Trial: admin has access to at least one simulation', function() {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE role='admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) FROM purchases WHERE user_id = ? AND status = 'trial'");
    $stmt->execute([$admin['id']]);
    $count = (int) $stmt->fetchColumn();
    assert_true($count > 0, "Admin has no trial purchases — grant-all-trials may have failed");
});

// ── Render ───────────────────────────────────────────────────────────────────
$total = $pass + $fail;
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Test Runner — IndependentResearcher</title>
<style>
  body { font-family: 'IBM Plex Mono', monospace; background: #0d1117; color: #e6edf3; padding: 2rem; }
  h1   { color: #f0c040; }
  .summary { font-size: 1.2rem; margin-bottom: 1.5rem; }
  .pass-count { color: #10b981; }
  .fail-count { color: #ef4444; }
  table { border-collapse: collapse; width: 100%; }
  th    { text-align: left; color: #8b949e; border-bottom: 1px solid #21262d; padding: 0.4rem 0.8rem; }
  td    { padding: 0.4rem 0.8rem; border-bottom: 1px solid #161b22; font-size: 0.85rem; }
  .PASS { color: #10b981; font-weight: bold; }
  .FAIL { color: #ef4444; font-weight: bold; }
  .detail { color: #ffa657; font-size: 0.8rem; }
</style>
</head>
<body>
<h1>🧪 IndependentResearcher — Test Suite</h1>
<div class="summary">
  <span class="pass-count"><?= $pass ?> passed</span> /
  <span class="fail-count"><?= $fail ?> failed</span> /
  <?= $total ?> total
</div>
<table>
  <tr><th>#</th><th>Test</th><th>Status</th><th>Detail</th></tr>
  <?php foreach ($results as $i => $r): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td class="<?= $r['status'] ?>"><?= $r['status'] ?></td>
    <td class="detail"><?= htmlspecialchars($r['detail']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
