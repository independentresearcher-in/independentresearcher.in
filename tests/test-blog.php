<?php
/**
 * test-blog.php — Blog system tests (JSON file storage)
 * Access: /tests/test-blog.php?secret=ir_test_2026
 */
define('TEST_SECRET', 'ir_test_2026');
if (($_GET['secret'] ?? '') !== TEST_SECRET) { http_response_code(403); die('403'); }

require_once dirname(__DIR__) . '/config.php';

$checks = [];
function chk(string $label, bool $pass, string $detail = '', string $fix = ''): void {
    global $checks;
    $checks[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail, 'fix' => $fix];
}

$root    = dirname(__DIR__);
$blogDir = "$root/data/blog";

// ── 1. Folder structure ───────────────────────────────────────────────────────
chk('data/ directory exists',      is_dir("$root/data"),    "$root/data",    'mkdir data/ in public_html');
chk('data/blog/ directory exists', is_dir($blogDir),        $blogDir,        'mkdir data/blog/ in public_html');
chk('data/blog/ is writable',
    is_dir($blogDir) && is_writable($blogDir),
    is_dir($blogDir) ? (is_writable($blogDir) ? 'Writable ✓' : 'NOT WRITABLE — PHP cannot create posts') : 'Directory missing',
    'chmod 755 data/blog/ on Hostinger');

// ── 2. .htaccess protection ───────────────────────────────────────────────────
$htFile = "$blogDir/.htaccess";
if (file_exists($htFile)) {
    $htContent = file_get_contents($htFile);
    $hasDeny   = str_contains($htContent, 'Deny from all');
    chk('data/blog/.htaccess exists',       true,    $htFile);
    chk('data/blog/.htaccess blocks access', $hasDeny, $hasDeny ? 'Deny from all ✓' : 'Missing Deny directive',
        'Add "Deny from all" to data/blog/.htaccess');
} else {
    chk('data/blog/.htaccess exists', false, 'MISSING — JSON files directly accessible',
        'Create data/blog/.htaccess with: Order deny,allow\nDeny from all');
    chk('data/blog/.htaccess blocks access', false, 'No .htaccess');
}

// ── 3. JSON files ─────────────────────────────────────────────────────────────
if (is_dir($blogDir)) {
    $jsonFiles = glob("$blogDir/*.json") ?: [];
    chk('Blog JSON files exist',
        count($jsonFiles) > 0,
        count($jsonFiles) . ' post(s): ' . implode(', ', array_map('basename', $jsonFiles)),
        'Create a post via Admin → Blog Posts');

    $validPosts = 0;
    $invalidFiles = [];
    foreach ($jsonFiles as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data) && !empty($data['slug']) && !empty($data['title'])) {
            $validPosts++;
        } else {
            $invalidFiles[] = basename($file);
        }
    }
    if (!empty($jsonFiles)) {
        chk('All JSON files are valid posts',
            empty($invalidFiles),
            empty($invalidFiles) ? "$validPosts valid post(s)" : 'Invalid: ' . implode(', ', $invalidFiles),
            'Check JSON structure: must have slug, title, content fields');
    }

    // Test read/write cycle
    $testFile = "$blogDir/.test_write_" . time() . ".tmp";
    $wrote    = @file_put_contents($testFile, 'test');
    if ($wrote !== false) {
        unlink($testFile);
        chk('Blog directory write test', true, 'Read/write works');
    } else {
        chk('Blog directory write test', false, 'Cannot write to data/blog/',
            'chmod 755 data/blog/ and check PHP user has write permission');
    }
}

// ── 4. api.php blog endpoints ─────────────────────────────────────────────────
$apiPath = "$root/api.php";
if (file_exists($apiPath)) {
    $apiContent = file_get_contents($apiPath);

    chk("api.php has 'blog-posts' case",      str_contains($apiContent, "case 'blog-posts':"));
    chk("api.php has 'blog-post' case",       str_contains($apiContent, "case 'blog-post':"));
    chk("api.php has 'admin-blog-save' case", str_contains($apiContent, "case 'admin-blog-save':"));
    chk("api.php has 'admin-blog-delete' case", str_contains($apiContent, "case 'admin-blog-delete':"));
    chk("api.php has 'admin-blog-full' case", str_contains($apiContent, "case 'admin-blog-full':"));

    // Detect if api.php still uses DB queries for blog (old version)
    // Old: queries blog_posts table | New: uses blogLoad/blogLoadAll functions
    $usesDbBlog  = str_contains($apiContent, 'FROM blog_posts bp') || 
                   str_contains($apiContent, 'FROM blog_posts WHERE');
    $usesJsonBlog = str_contains($apiContent, 'blogLoadAll') || 
                    str_contains($apiContent, 'blogLoad(') ||
                    str_contains($apiContent, 'BLOG_DIR') ||
                    str_contains($apiContent, 'data/blog') ||
                    str_contains($apiContent, "json_decode(file_get_contents");

    if ($usesDbBlog) {
        chk('Blog storage: JSON files (not DB tables)', false,
            'api.php still queries blog_posts DB table — posts return empty/404 until table has data',
            'Deploy updated api.php that uses JSON file storage from data/blog/');
    } else if ($usesJsonBlog) {
        chk('Blog storage: JSON files (not DB tables)', true, 'Uses JSON file storage ✓');
    } else {
        chk('Blog storage method detectable', false, 'Cannot determine storage method');
    }
}

// ── 5. Live endpoint test ─────────────────────────────────────────────────────
try {
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    if ($siteUrl) {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $raw = @file_get_contents("$siteUrl/api.php?action=blog-posts&page=1", false, $ctx);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            $isOk = is_array($data) && isset($data['posts']);
            chk('Live api.php?action=blog-posts', $isOk,
                $isOk ? count($data['posts']) . ' published posts returned' : "Response: " . substr($raw, 0, 100),
                'Check api.php blog endpoint and data/blog/ folder');
        } else {
            chk('Live api.php?action=blog-posts', false, 'HTTP request failed', 'Check SITE_URL in .env');
        }
    } else {
        chk('Live blog endpoint test', false, 'SITE_URL not defined', 'Set SITE_URL in .env');
    }
} catch (Throwable $e) {
    chk('Live blog endpoint test', false, $e->getMessage());
}

// ── Render ────────────────────────────────────────────────────────────────────
$pass = count(array_filter($checks, fn($c) => $c['pass']));
$fail = count($checks) - $pass;
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><title>Blog Tests</title>
<style>
body{font-family:monospace;background:#0d1117;color:#e6edf3;padding:2rem;max-width:1000px}
h1{color:#f0c040}.summary{margin-bottom:1.5rem}.pass{color:#3fb950}.fail{color:#f87171}
table{border-collapse:collapse;width:100%}
th{text-align:left;color:#8b949e;border-bottom:1px solid #21262d;padding:.4rem .8rem}
td{padding:.5rem .8rem;border-bottom:1px solid #161b22;vertical-align:top;font-size:.85rem}
.fix{color:#f59e0b;font-size:.78rem;margin-top:.2rem}
footer{color:#6e7681;margin-top:2rem;font-size:.8rem}
</style></head><body>
<h1>📝 Blog Tests</h1>
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
