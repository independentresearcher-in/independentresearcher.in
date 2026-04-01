<?php
/**
 * diagnose.php — Live server diagnostic
 * Access: https://yourdomain.com/tests/diagnose.php?secret=ir_test_2026
 * DELETE this file after debugging.
 *
 * FIX: config.php is required at FILE scope (top of this file), NOT inside
 * a closure. When required inside a function/closure, $_env and all constants
 * are scoped locally and env() cannot find them via 'global $_env'.
 */
define('TEST_SECRET', 'ir_test_2026');
if (($_GET['secret'] ?? '') !== TEST_SECRET) { http_response_code(403); die('403'); }

// ── Load config at FILE scope — critical, do NOT move inside a function ───────
$configPath = dirname(__DIR__) . '/config.php';
$configLoaded = false;
$configError  = null;
if (file_exists($configPath)) {
    try {
        require_once $configPath;
        $configLoaded = true;
    } catch (Throwable $e) {
        $configError = $e->getMessage();
    }
} else {
    $configError = 'config.php not found at ' . $configPath;
}

// ── Checks ────────────────────────────────────────────────────────────────────
$checks = [];

function add(string $label, string $status, string $detail): void {
    global $checks;
    $checks[] = compact('label', 'status', 'detail');
}

// 1. PHP version
add('PHP version', 'OK', PHP_VERSION);

// 2. open_basedir
$obd = ini_get('open_basedir');
add('open_basedir restriction', $obd ? 'WARN' : 'OK', $obd ?: 'none (no restriction)');

// 3. .env file checks
$envDir  = dirname($configPath);
$envPath = $envDir . '/.env';
$envParentPath = dirname($envDir) . '/.env';

add('.env — same dir (public_html/.env)',
    file_exists($envPath) ? 'OK' : 'FAIL',
    file_exists($envPath)
        ? 'EXISTS at ' . $envPath . ' | readable: ' . (is_readable($envPath) ? 'YES' : 'NO — PERMISSION PROBLEM')
        : 'NOT FOUND at ' . $envPath);

add('.env — parent dir',
    file_exists($envParentPath) ? 'OK' : 'INFO',
    file_exists($envParentPath) ? 'EXISTS at ' . $envParentPath : 'Not present (OK if using same-dir)');

// 4. Raw .env content preview (first 20 lines, values masked)
if (file_exists($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES) ?: [];
    $preview = [];
    foreach (array_slice($lines, 0, 25) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            $preview[] = htmlspecialchars($line);
        } elseif (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $masked = strlen($v) > 4 ? substr($v, 0, 3) . str_repeat('*', min(8, strlen($v) - 3)) : '***';
            $preview[] = htmlspecialchars(trim($k)) . '=' . $masked;
        } else {
            $preview[] = htmlspecialchars($line) . ' <-- NO = SIGN, will be ignored';
        }
    }
    add('.env content preview', 'OK', implode('<br>', $preview));
} else {
    add('.env content preview', 'FAIL', 'Cannot read file');
}

// 5. config.php load result
add('require config.php',
    $configLoaded ? 'OK' : 'FAIL',
    $configLoaded ? 'Loaded successfully' : $configError);

// Only continue checks if config loaded
if ($configLoaded) {
    // 6. Constants
    $constChecks = [
        'GOOGLE_CLIENT_ID'     => ['required' => true,  'mask' => true],
        'GOOGLE_CLIENT_SECRET' => ['required' => true,  'mask' => true],
        'GOOGLE_REDIRECT_URI'  => ['required' => true,  'mask' => false],
        'SITE_URL'             => ['required' => true,  'mask' => false],
        'DB_NAME'              => ['required' => true,  'mask' => false],
        'DB_USER'              => ['required' => true,  'mask' => false],
        'DB_HOST'              => ['required' => true,  'mask' => false],
        'PAYMENT_ENABLED'      => ['required' => false, 'mask' => false],
        'SMTP_ENABLED'         => ['required' => false, 'mask' => false],
    ];
    foreach ($constChecks as $const => $opts) {
        if (!defined($const)) {
            add($const, 'FAIL', 'NOT DEFINED — config.php did not run define()');
            continue;
        }
        $val = constant($const);
        if ($opts['required'] && empty($val)) {
            add($const, 'FAIL', 'DEFINED BUT EMPTY — check .env key');
            continue;
        }
        if ($const === 'GOOGLE_REDIRECT_URI' && strpos($val, '#') !== false) {
            add($const, 'FAIL', 'CONTAINS # — will break OAuth: ' . $val);
            continue;
        }
        $display = $opts['mask']
            ? substr((string)$val, 0, 12) . '...'
            : (string)$val;
        add($const, 'OK', $display);
    }

    // 7. DB connection
    try {
        $db = getDB();
        $db->query('SELECT 1');
        add('DB connection', 'OK', 'Connected to ' . DB_HOST . '/' . DB_NAME);
    } catch (Throwable $e) {
        add('DB connection', 'FAIL', $e->getMessage());
    }

    // 8. Tables
    foreach (['users','products','purchases','webhook_log','physics_questions'] as $tbl) {
        try {
            $db = getDB();
            $db->query("SELECT 1 FROM `$tbl` LIMIT 1");
            add("Table: $tbl", 'OK', 'exists');
        } catch (Throwable $e) {
            add("Table: $tbl", 'FAIL', $e->getMessage());
        }
    }

    // 9. Simulations trial check
    try {
        $db = getDB();
        $n  = $db->query("SELECT COUNT(*) FROM products WHERE is_free_trial=1 AND is_active=1")->fetchColumn();
        add('Simulations is_free_trial=TRUE', $n > 0 ? 'OK' : 'FAIL', "$n simulations");
    } catch (Throwable $e) {
        add('Simulations is_free_trial=TRUE', 'FAIL', $e->getMessage());
    }

    // 10. Self-request to api.php?action=config
    try {
        $url = SITE_URL . '/api.php?action=config';
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new RuntimeException('HTTP request failed to ' . $url);
        $d = json_decode($raw, true);
        if (!is_array($d)) throw new RuntimeException('Non-JSON: ' . substr($raw, 0, 300));
        if (empty($d['google_client_id'])) throw new RuntimeException('google_client_id empty in response. Raw: ' . substr($raw, 0, 300));
        add('api.php?action=config', 'OK', 'google_client_id=' . substr($d['google_client_id'], 0, 15) . '...');
    } catch (Throwable $e) {
        add('api.php?action=config', 'FAIL', $e->getMessage());
    }

} else {
    add('All remaining checks', 'SKIP', 'Skipped — config.php failed to load');
}


    // 11. users table OAuth columns
    $oauthCols = ['google_id','google_email','avatar_url','auth_provider','last_login','password_hash'];
    if ($configLoaded) {
        try {
            $db = getDB();
            $existingCols = array_column(
                $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field'
            );
            $missing = array_diff($oauthCols, $existingCols);
            if (empty($missing)) {
                add('users — OAuth columns', 'OK', 'All present: ' . implode(', ', $oauthCols));
            } else {
                add('users — OAuth columns', 'FAIL',
                    'MISSING: ' . implode(', ', $missing) . ' — auth-google.php will auto-fix on next login attempt');
            }
        } catch (Throwable $e) {
            add('users — OAuth columns', 'FAIL', $e->getMessage());
        }
    }

    // 12. purchases table columns
    if ($configLoaded) {
        try {
            $db = getDB();
            $cols = array_column($db->query("SHOW COLUMNS FROM purchases")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $typeRow = $db->query("SHOW COLUMNS FROM purchases LIKE 'status'")->fetch();
            $hasAccessType = in_array('access_type', $cols);
            $hasTrial = $typeRow && strpos($typeRow['Type'], 'trial') !== false;
            if ($hasAccessType && $hasTrial) {
                add('purchases — trial columns', 'OK', 'access_type present, status includes trial enum');
            } else {
                $issues = [];
                if (!$hasAccessType) $issues[] = 'access_type column MISSING';
                if (!$hasTrial)      $issues[] = 'status enum missing "trial": ' . ($typeRow['Type'] ?? '?');
                add('purchases — trial columns', 'FAIL', implode(' | ', $issues));
            }
        } catch (Throwable $e) {
            add('purchases — trial columns', 'FAIL', $e->getMessage());
        }
    }

    // 13. PHP error log tail
    $logFile = dirname(__DIR__) . '/logs/php_errors.log';
    if (file_exists($logFile) && is_readable($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
        $tail  = array_slice($lines, -15);
        $hasOAuthError = !empty(array_filter($tail, fn($l) => stripos($l,'oauth') !== false || stripos($l,'google') !== false));
        add('Error log (last 15 lines)', $hasOAuthError ? 'WARN' : 'OK',
            '<pre style="margin:0;font-size:.75rem;white-space:pre-wrap">' .
            htmlspecialchars(implode("\n", $tail) ?: '(empty)') . '</pre>');
    } else {
        add('Error log', 'INFO', 'No log file yet at ' . $logFile);
    }

// ── Render ────────────────────────────────────────────────────────────────────
$pass = count(array_filter($checks, fn($c) => $c['status'] === 'OK'));
$fail = count(array_filter($checks, fn($c) => $c['status'] === 'FAIL'));
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><title>Diagnostics</title>
<style>
body{font-family:monospace;background:#0d1117;color:#e6edf3;padding:2rem}
h1{color:#f0c040}
.summary{margin-bottom:1.5rem;font-size:1.1rem}
.pass{color:#10b981}.fail{color:#ef4444}.warn{color:#f59e0b}.skip{color:#6e7681}.info{color:#60a5fa}
table{border-collapse:collapse;width:100%}
th{text-align:left;color:#8b949e;border-bottom:1px solid #21262d;padding:.4rem .8rem}
td{padding:.5rem .8rem;border-bottom:1px solid #161b22;vertical-align:top}
.val{color:#a3d4ff;word-break:break-all;font-size:.82rem;line-height:1.5}
.num{color:#6e7681;font-size:.8rem}
footer{color:#6e7681;margin-top:2rem;font-size:.8rem}
</style></head><body>
<h1>🔍 Server Diagnostics — <?= date('Y-m-d H:i:s T') ?></h1>
<div class="summary">
  <span class="pass"><?= $pass ?> OK</span> &nbsp;
  <span class="fail"><?= $fail ?> FAIL</span> &nbsp;
  <?= count($checks) ?> total
</div>
<table>
<tr><th class="num">#</th><th>Check</th><th>Status</th><th>Detail</th></tr>
<?php foreach ($checks as $i => $c): $s = strtolower($c['status']); ?>
<tr>
  <td class="num"><?= $i+1 ?></td>
  <td><?= htmlspecialchars($c['label']) ?></td>
  <td class="<?= $s ?>"><?= $c['status'] ?></td>
  <td class="val"><?= $c['detail'] ?></td>
</tr>
<?php endforeach; ?>
</table>
<footer>⚠️ Delete this file after debugging — it exposes server paths and config state.</footer>
</body></html>
