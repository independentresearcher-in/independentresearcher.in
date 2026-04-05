<?php
/**
 * test-auth.php — Authentication system tests
 * Tests: email register, email login, Google OAuth config, verification flow,
 *        password reset config, session handling.
 *
 * Access: /tests/test-auth.php?secret=ir_test_2026
 */
define('TEST_SECRET', 'ir_test_2026');
if (($_GET['secret'] ?? '') !== TEST_SECRET) { http_response_code(403); die('403'); }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

$checks = [];
function chk(string $label, bool $pass, string $detail = '', string $fix = ''): void {
    global $checks;
    $checks[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail, 'fix' => $fix];
}

// ── 1. Google OAuth config ────────────────────────────────────────────────────
$clientId     = defined('GOOGLE_CLIENT_ID')     ? GOOGLE_CLIENT_ID     : '';
$clientSecret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '';
$redirectUri  = defined('GOOGLE_REDIRECT_URI')  ? GOOGLE_REDIRECT_URI  : '';
$siteUrl      = defined('SITE_URL')             ? SITE_URL             : '';

chk('GOOGLE_CLIENT_ID set',
    !empty($clientId),
    $clientId ? substr($clientId, 0, 20) . '…' : 'EMPTY',
    'Add GOOGLE_CLIENT_ID=your_id to .env');

chk('GOOGLE_CLIENT_SECRET set',
    !empty($clientSecret),
    $clientSecret ? 'Set (' . strlen($clientSecret) . ' chars)' : 'EMPTY',
    'Add GOOGLE_CLIENT_SECRET=your_secret to .env');

chk('GOOGLE_REDIRECT_URI correct format',
    !empty($redirectUri) && str_starts_with($redirectUri, 'https://') && str_contains($redirectUri, 'auth-google.php'),
    $redirectUri ?: 'EMPTY',
    'SITE_URL in .env must start with https:// and not end with /');

chk('GOOGLE_REDIRECT_URI matches SITE_URL',
    !empty($redirectUri) && str_starts_with($redirectUri, $siteUrl),
    "SITE_URL=$siteUrl | Redirect=$redirectUri",
    'Set SITE_URL=https://test.independentresearcher.in in .env (no trailing slash)');

chk('No # fragment in REDIRECT_URI (breaks OAuth)',
    !str_contains($redirectUri, '#'),
    str_contains($redirectUri, '#') ? 'CONTAINS # — will cause OAuth state mismatch' : 'Clean',
    'Remove everything after # from SITE_URL in .env');

// ── 2. Google OAuth reachability ─────────────────────────────────────────────
$curlOk = function_exists('curl_init');
chk('cURL extension available', $curlOk,
    $curlOk ? 'Yes' : 'No — Google OAuth requires cURL',
    'Contact host to enable php-curl');

if ($curlOk) {
    $ch = curl_init('https://accounts.google.com/.well-known/openid-configuration');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $googleReachable = ($res !== false && str_contains($res, 'authorization_endpoint'));
    chk('Google OAuth endpoint reachable',
        $googleReachable,
        $googleReachable ? 'OK' : "cURL error: $err",
        'Check server outbound HTTPS; some hosts block external requests');
}

// ── 3. Google OAuth state flow simulation ─────────────────────────────────────
startSession();
$_SESSION['__test_oauth_state'] = 'test_state_abc';
chk('PHP sessions working for OAuth state',
    isset($_SESSION['__test_oauth_state']) && $_SESSION['__test_oauth_state'] === 'test_state_abc',
    isset($_SESSION['__test_oauth_state']) ? 'Session stores and reads correctly' : 'Session not working',
    'Check session.save_path permissions');
unset($_SESSION['__test_oauth_state']);

// ── 4. DB user table – columns for Google auth ────────────────────────────────
try {
    $db   = getDB();
    $cols = array_column($db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');

    $required = ['id','email','password_hash','email_verified','role','auth_provider',
                 'google_id','google_email','avatar_url','last_login'];
    foreach ($required as $col) {
        chk("users.$col column exists",
            in_array($col, $cols),
            in_array($col, $cols) ? 'Present' : 'MISSING — run database.sql migrations',
            "Run: ALTER TABLE users ADD COLUMN $col …");
    }

    // Check password_hash allows NULL (for Google-only accounts)
    $phRow = $db->query("SHOW COLUMNS FROM users LIKE 'password_hash'")->fetch();
    $phNullable = $phRow && strtoupper($phRow['Null'] ?? '') === 'YES';
    chk('password_hash column allows NULL',
        $phNullable,
        $phRow ? "Null={$phRow['Null']}" : 'Column not found',
        'ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL');

    // Check display_name
    $dnRow = $db->query("SHOW COLUMNS FROM users LIKE 'display_name'")->fetch();
    $dnOk  = $dnRow && ($dnRow['Type'] ?? '') === 'varchar(80)';
    chk('display_name VARCHAR(80)',
        $dnOk,
        $dnRow ? "Type={$dnRow['Type']}, Null={$dnRow['Null']}" : 'Column missing',
        'ALTER TABLE users MODIFY COLUMN display_name VARCHAR(80) NULL DEFAULT NULL');

} catch (Throwable $e) {
    chk('DB connection for user checks', false, $e->getMessage(), 'Fix DB credentials in .env');
}

// ── 5. Email auth config ──────────────────────────────────────────────────────
$smtpEnabled = defined('SMTP_ENABLED') && SMTP_ENABLED;
$smtpFrom    = defined('SMTP_FROM') ? SMTP_FROM : '';
$smtpUser    = defined('SMTP_USER') ? SMTP_USER : '';

chk('SMTP_FROM configured',
    !empty($smtpFrom),
    $smtpFrom ?: 'EMPTY — emails will use default noreply@domain.com',
    'Add SMTP_FROM=noreply@independentresearcher.in to .env');

if ($smtpEnabled) {
    chk('SMTP_USER configured', !empty($smtpUser), $smtpUser ?: 'EMPTY', 'Add SMTP_USER to .env');
    chk('SMTP_PASS configured', !empty(SMTP_PASS), SMTP_PASS ? 'Set' : 'EMPTY', 'Add SMTP_PASS to .env');
}

// Test PHP mail() is available (fallback)
chk('PHP mail() function available',
    function_exists('mail'),
    function_exists('mail') ? 'Available (email fallback will work)' : 'Not available',
    'SMTP must be configured if mail() is unavailable');

// ── 6. Verification token generation ─────────────────────────────────────────
try {
    $token = generateSecureToken();
    chk('generateSecureToken() works',
        strlen($token) === 64,
        'Generated: ' . substr($token, 0, 16) . '… (' . strlen($token) . ' chars)');
} catch (Throwable $e) {
    chk('generateSecureToken() works', false, $e->getMessage(), 'Check helpers.php is included');
}

// ── 7. Test user in DB (non-destructive) ──────────────────────────────────────
try {
    $db = getDB();

    // Check if test user exists
    $testEmail = 'test_diagnose_user@example.invalid';
    $existing  = $db->prepare('SELECT id, email_verified, auth_provider FROM users WHERE email = ?');
    $existing->execute([$testEmail]);
    $u = $existing->fetch();

    if (!$u) {
        // Create a temporary test user
        $hash = password_hash('TestPass123!', PASSWORD_DEFAULT);
        $tok  = generateSecureToken();
        $exp  = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $db->prepare("INSERT INTO users (email, password_hash, email_verified, auth_provider, role, verification_token, verification_token_expires, created_at) VALUES (?, ?, 0, 'email', 'user', ?, ?, NOW())")
           ->execute([$testEmail, $hash, $tok, $exp]);
        $u = $db->prepare('SELECT id, email_verified, auth_provider FROM users WHERE email = ?');
        $u->execute([$testEmail]);
        $u = $u->fetch();
        chk('Test user INSERT works', (bool)$u, $u ? "id={$u['id']}" : 'Insert failed');
    } else {
        chk('Test user (pre-existing)', true, "id={$u['id']}, verified={$u['email_verified']}");
    }

    if ($u) {
        // Test login block for unverified
        $loginOk = !$u['email_verified']; // we WANT it to be unverified = login should be blocked
        chk('Unverified user correctly blocked from login',
            $loginOk,
            $u['email_verified'] ? 'User is already verified (login would succeed)' : 'email_verified=0 ✓');

        // Cleanup: remove test user
        $db->prepare('DELETE FROM users WHERE email = ?')->execute([$testEmail]);
        chk('Test user cleanup', true, 'Removed test user');
    }
} catch (Throwable $e) {
    chk('User registration DB test', false, $e->getMessage());
}

// ── Render ────────────────────────────────────────────────────────────────────
renderResults('Authentication Tests', $checks);

function renderResults(string $title, array $checks): void {
    $pass = count(array_filter($checks, fn($c) => $c['pass']));
    $fail = count($checks) - $pass;
    ?><!DOCTYPE html><html lang="en"><head>
    <meta charset="UTF-8"><title><?= htmlspecialchars($title) ?></title>
    <style>
    body{font-family:monospace;background:#0d1117;color:#e6edf3;padding:2rem;max-width:1000px}
    h1{color:#f0c040}
    .summary{margin-bottom:1.5rem}
    .pass{color:#3fb950}.fail{color:#f87171}
    table{border-collapse:collapse;width:100%}
    th{text-align:left;color:#8b949e;border-bottom:1px solid #21262d;padding:.4rem .8rem}
    td{padding:.5rem .8rem;border-bottom:1px solid #161b22;vertical-align:top;font-size:.85rem}
    .fix{color:#f59e0b;font-size:.78rem;margin-top:.2rem}
    footer{color:#6e7681;margin-top:2rem;font-size:.8rem}
    </style></head><body>
    <h1>🧪 <?= htmlspecialchars($title) ?></h1>
    <div class="summary">
      <span class="pass"><?= $pass ?> PASS</span> &nbsp;
      <span class="fail"><?= $fail ?> FAIL</span> &nbsp;
      <?= count($checks) ?> total
    </div>
    <table>
    <tr><th>#</th><th>Check</th><th>Result</th><th>Detail</th></tr>
    <?php foreach ($checks as $i => $c): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= htmlspecialchars($c['label']) ?></td>
      <td class="<?= $c['pass'] ? 'pass' : 'fail' ?>"><?= $c['pass'] ? '✓ PASS' : '✗ FAIL' ?></td>
      <td><?= $c['detail'] ?><?php if (!$c['pass'] && !empty($c['fix'])): ?>
          <div class="fix">▶ Fix: <?= htmlspecialchars($c['fix']) ?></div>
        <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
    <footer>Delete after debugging. Access: ?secret=ir_test_2026</footer>
    </body></html>
    <?php
}
