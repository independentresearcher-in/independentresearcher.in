<?php
/**
 * sitemap.php — Dynamic XML sitemap for independentresearcher.in
 *
 * Covers:
 *   - Static clean-URL routes (pushState pages)
 *   - All published blog posts from data/blog/
 *   - All active simulations from the DB
 *
 * URL: https://independentresearcher.in/sitemap.php
 * Linked from: robots.txt  Sitemap: directive
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');           // sitemap itself should not be indexed
header('Cache-Control: public, max-age=3600');

$base = rtrim(SITE_URL, '/');
$now  = date('Y-m-d');

$urls = [];

// ── 1. Static routes ─────────────────────────────────────────────────────────
$staticRoutes = [
    ['loc' => $base . '/',            'changefreq' => 'weekly',  'priority' => '1.0'],
    ['loc' => $base . '/simulations', 'changefreq' => 'weekly',  'priority' => '0.9'],
    ['loc' => $base . '/about',       'changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => $base . '/blog',        'changefreq' => 'weekly',  'priority' => '0.8'],
];
foreach ($staticRoutes as $r) {
    $r['lastmod'] = $now;
    $urls[] = $r;
}

// ── 2. Blog posts from data/blog/ JSON files ─────────────────────────────────
$blogDir = __DIR__ . '/data/blog/';
if (is_dir($blogDir)) {
    $files = glob($blogDir . '*.json');
    if ($files) {
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if (!$raw) continue;
            $post = json_decode($raw, true);
            if (!$post || ($post['status'] ?? '') !== 'published') continue;

            $slug    = $post['slug'] ?? '';
            $updated = $post['updated_at'] ?? ($post['created_at'] ?? $now);
            // Normalise ISO 8601 to Y-m-d for lastmod
            $lastmod = substr($updated, 0, 10);

            if ($slug !== '') {
                $urls[] = [
                    'loc'        => $base . '/blog/' . rawurlencode($slug),
                    'lastmod'    => $lastmod,
                    'changefreq' => 'monthly',
                    'priority'   => '0.6',
                ];
            }
        }
    }
}

// ── 3. Active simulations from DB ─────────────────────────────────────────────
try {
    $db   = getDB();
    $stmt = $db->query("SELECT id, updated_at, created_at FROM products WHERE is_active = 1");
    $sims = $stmt->fetchAll();
    foreach ($sims as $sim) {
        $updated = $sim['updated_at'] ?? $sim['created_at'] ?? $now;
        $lastmod = substr($updated, 0, 10);
        $urls[] = [
            'loc'        => $base . '/simulations/' . rawurlencode($sim['id']),
            'lastmod'    => $lastmod,
            'changefreq' => 'monthly',
            'priority'   => '0.7',
        ];
    }
} catch (Throwable $e) {
    // DB unavailable — sitemap still serves static and blog URLs.
    error_log('sitemap.php: DB fetch failed: ' . $e->getMessage());
}

// ── Render XML ────────────────────────────────────────────────────────────────
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    if (!empty($u['lastmod']))    echo "    <lastmod>{$u['lastmod']}</lastmod>\n";
    if (!empty($u['changefreq'])) echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
    if (!empty($u['priority']))   echo "    <priority>{$u['priority']}</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>';
