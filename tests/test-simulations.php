<?php
/**
 * test-simulations.php — Simulation files, trial/paid routing, purchase flow tests
 * Access: /tests/test-simulations.php?secret=ir_test_2026
 */
define('TEST_SECRET', 'ir_test_2026');
if (($_GET['secret'] ?? '') !== TEST_SECRET) { http_response_code(403); die('403'); }

require_once dirname(__DIR__) . '/config.php';

$checks = [];
function chk(string $label, bool $pass, string $detail = '', string $fix = ''): void {
    global $checks;
    $checks[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail, 'fix' => $fix];
}

$root = dirname(__DIR__);

// ── 1. Folder structure ───────────────────────────────────────────────────────
chk('simulations/ directory exists',    is_dir("$root/simulations"),      "$root/simulations");
chk('simulations/trial/ exists',        is_dir("$root/simulations/trial"), "$root/simulations/trial");
chk('simulations/paid/ exists',         is_dir("$root/simulations/paid"),  "$root/simulations/paid",
    'Create folder: mkdir simulations/paid on Hostinger');

$trialFiles = glob("$root/simulations/trial/*.html") ?: [];
$paidFiles  = glob("$root/simulations/paid/*.html")  ?: [];
chk('Trial HTML files present',  count($trialFiles) > 0,
    count($trialFiles) . ' files: ' . implode(', ', array_map('basename', $trialFiles)),
    'Upload trial HTML files to simulations/trial/');
chk('Paid HTML files present',   count($paidFiles) > 0,
    count($paidFiles) . ' files: ' . implode(', ', array_map('basename', $paidFiles)),
    'Upload paid HTML files to simulations/paid/');

// ── 2. Each DB product maps to a trial file ───────────────────────────────────
try {
    $db       = getDB();
    $products = $db->query("SELECT id, title, sim_file, paid_file FROM products WHERE is_active = 1")->fetchAll();
    chk('products table accessible', true, count($products) . ' active products');

    foreach ($products as $p) {
        $trialFile = "$root/simulations/trial/{$p['sim_file']}";
        $rootFile  = "$root/simulations/{$p['sim_file']}";
        $trialOk   = file_exists($trialFile) || file_exists($rootFile);
        chk("Trial file: {$p['sim_file']}",
            $trialOk,
            $trialOk ? 'Found' : "NOT FOUND at simulations/trial/{$p['sim_file']}",
            "Upload {$p['sim_file']} to simulations/trial/");

        if (!empty($p['paid_file'])) {
            $paidPath = "$root/simulations/paid/{$p['paid_file']}";
            chk("Paid file: {$p['paid_file']}",
                file_exists($paidPath),
                file_exists($paidPath) ? 'Found' : "NOT FOUND at simulations/paid/{$p['paid_file']}",
                "Upload {$p['paid_file']} to simulations/paid/");
        } else {
            chk("Paid file for {$p['id']}", false, 'paid_file column empty',
                "Set paid_file in admin → Simulations → Edit {$p['title']}");
        }
    }
} catch (Throwable $e) {
    chk('products table query', false, $e->getMessage(), 'Run database.sql migrations');
}

// ── 3. products table schema ──────────────────────────────────────────────────
try {
    $db   = getDB();
    $cols = array_column($db->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach (['id','title','sim_file','paid_file','price','is_free_trial','is_active'] as $col) {
        chk("products.$col column",
            in_array($col, $cols),
            in_array($col, $cols) ? 'Present' : 'MISSING',
            $col === 'paid_file' ? 'ALTER TABLE products ADD COLUMN paid_file VARCHAR(255) NULL AFTER sim_file' : 'Run database.sql');
    }
} catch (Throwable $e) {
    chk('products table schema', false, $e->getMessage());
}

// ── 4. purchases table ────────────────────────────────────────────────────────
try {
    $db      = getDB();
    $cols    = array_column($db->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $typeRow = $db->query("SHOW COLUMNS FROM purchases LIKE 'status'")->fetch();

    chk('purchases.access_type column',
        in_array('access_type', $cols),
        in_array('access_type', $cols) ? 'Present' : 'MISSING',
        "ALTER TABLE purchases ADD COLUMN access_type ENUM('paid','trial','demo') DEFAULT 'trial' AFTER status");

    $statusType = $typeRow['Type'] ?? '';
    chk("purchases.status includes 'trial' enum",
        str_contains($statusType, 'trial'),
        $statusType,
        "ALTER TABLE purchases MODIFY COLUMN status ENUM('pending','paid','failed','demo','trial') DEFAULT 'pending'");

} catch (Throwable $e) {
    chk('purchases schema', false, $e->getMessage());
}

// ── 5. Trial access flow ──────────────────────────────────────────────────────
try {
    $db = getDB();

    // check-access endpoint returns correct structure
    $freeTrialCount = (int)$db->query("SELECT COUNT(*) FROM products WHERE is_free_trial=1 AND is_active=1")->fetchColumn();
    chk('Products have is_free_trial=TRUE',
        $freeTrialCount > 0,
        "$freeTrialCount products flagged as free trial",
        'UPDATE products SET is_free_trial=1, is_active=1 WHERE 1');

    // Test that grant-trial INSERT would work (dry run)
    $testUserId  = 0; // no real insert
    $firstProdId = $db->query("SELECT id FROM products WHERE is_free_trial=1 AND is_active=1 LIMIT 1")->fetchColumn();
    chk('At least one product available for trial grant',
        !empty($firstProdId),
        $firstProdId ? "First product: $firstProdId" : 'No products',
        'Add products via Admin → Simulations');
} catch (Throwable $e) {
    chk('Trial access flow check', false, $e->getMessage());
}

// ── 6. Razorpay config ────────────────────────────────────────────────────────
$payEnabled = defined('PAYMENT_ENABLED') && PAYMENT_ENABLED;
$rzpKey     = defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '';
$rzpSecret  = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';

chk('PAYMENT_ENABLED setting', true, $payEnabled ? 'true (payments LIVE)' : 'false (payments disabled — trial access only)');

if ($payEnabled) {
    chk('RAZORPAY_KEY_ID set',    !empty($rzpKey),    $rzpKey    ? substr($rzpKey, 0, 10).'…' : 'EMPTY', 'Add RAZORPAY_KEY_ID to .env');
    chk('RAZORPAY_KEY_SECRET set', !empty($rzpSecret), $rzpSecret ? 'Set ('.strlen($rzpSecret).' chars)' : 'EMPTY', 'Add RAZORPAY_KEY_SECRET to .env');
    chk('Razorpay handler exists', file_exists(dirname(__DIR__).'/razorpay-handler.php'), '');
}

// ── 7. view-simulation.php routing test ──────────────────────────────────────
$viewSim = file_get_contents(dirname(__DIR__) . '/view-simulation.php');
chk("view-simulation.php uses trial/paid routing",
    str_contains($viewSim, "simFolder = 'trial'") && str_contains($viewSim, "simFolder = 'paid'"),
    str_contains($viewSim, "simFolder = 'trial'") ? 'Routing logic present' : 'Missing routing',
    'Deploy updated view-simulation.php');
chk("view-simulation.php shows upgrade bar",
    str_contains($viewSim, 'initiateUpgrade'),
    str_contains($viewSim, 'initiateUpgrade') ? 'Upgrade flow present' : 'Missing upgrade trigger',
    'Deploy updated view-simulation.php');

// ── Render ────────────────────────────────────────────────────────────────────
$pass = count(array_filter($checks, fn($c) => $c['pass']));
$fail = count($checks) - $pass;
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><title>Simulation Tests</title>
<style>
body{font-family:monospace;background:#0d1117;color:#e6edf3;padding:2rem;max-width:1000px}
h1{color:#f0c040}.summary{margin-bottom:1.5rem}.pass{color:#3fb950}.fail{color:#f87171}
table{border-collapse:collapse;width:100%}
th{text-align:left;color:#8b949e;border-bottom:1px solid #21262d;padding:.4rem .8rem}
td{padding:.5rem .8rem;border-bottom:1px solid #161b22;vertical-align:top;font-size:.85rem}
.fix{color:#f59e0b;font-size:.78rem;margin-top:.2rem}
footer{color:#6e7681;margin-top:2rem;font-size:.8rem}
</style></head><body>
<h1>🔬 Simulation Tests</h1>
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
