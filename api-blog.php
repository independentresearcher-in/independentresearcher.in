<?php
declare(strict_types=1);

/**
 * api-blog.php — Blog API (JSON file storage)
 *
 * Posts are stored as JSON files in /data/blog/<slug>.json
 * .htaccess blocks direct web access to that folder.
 *
 * File format:
 *   { "id", "slug", "title", "summary", "cover_image",
 *     "content", "status", "author", "created_at", "updated_at" }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';

startSession(); // required for requireAdmin() -> getCurrentUser()

header('Content-Type: application/json');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

define('BLOG_DIR', __DIR__ . '/data/blog/');

// CSRF verification for all admin write actions
$adminWriteActions = ['admin-blog-save', 'admin-blog-delete'];
if ($method === 'POST' && in_array($action, $adminWriteActions, true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid or missing CSRF token.'], 403);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function blogLoadAll(): array {
    $posts = [];
    if (!is_dir(BLOG_DIR)) return $posts;
    foreach (glob(BLOG_DIR . '*.json') as $file) {
        $post = json_decode(file_get_contents($file), true);
        if (is_array($post)) $posts[] = $post;
    }
    usort($posts, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $posts;
}

function blogLoad(string $slug): ?array {
    $file = BLOG_DIR . preg_replace('/[^a-z0-9\-]/', '', $slug) . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function blogSave(array $post): bool {
    if (!is_dir(BLOG_DIR)) mkdir(BLOG_DIR, 0750, true);
    $file = BLOG_DIR . preg_replace('/[^a-z0-9\-]/', '', $post['slug']) . '.json';
    return file_put_contents($file, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function blogDelete(string $slug): bool {
    $file = BLOG_DIR . preg_replace('/[^a-z0-9\-]/', '', $slug) . '.json';
    return file_exists($file) && unlink($file);
}

function slugify(string $title): string {
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
    return substr($slug, 0, 100);
}

// ── Routes ────────────────────────────────────────────────────────────────────

try {
    switch ($action) {

        // GET /api-blog.php?action=posts[&page=1]
        case 'posts':
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $limit = 10;
            $all   = array_filter(blogLoadAll(), fn($p) => ($p['status'] ?? '') === 'published');
            $all   = array_values($all);
            $total = count($all);
            $posts = array_slice($all, ($page - 1) * $limit, $limit);
            // Strip full content from list view
            $posts = array_map(function ($p) {
                unset($p['content']);
                return $p;
            }, $posts);
            jsonResponse(['success' => true, 'posts' => $posts, 'total' => $total,
                          'page' => $page, 'pages' => (int) ceil($total / $limit)]);

        // GET /api-blog.php?action=post&slug=<slug>
        case 'post':
            $slug = trim($_GET['slug'] ?? '');
            if (!$slug) jsonResponse(['error' => 'slug required'], 400);
            $post = blogLoad($slug);
            if (!$post || ($post['status'] ?? '') !== 'published') {
                jsonResponse(['error' => 'Post not found'], 404);
            }
            jsonResponse($post);

        // Admin: list all posts (draft + published)
        case 'admin-blog-list':
            requireAdmin();
            $posts = array_map(function ($p) { unset($p['content']); return $p; }, blogLoadAll());
            jsonResponse(['success' => true, 'posts' => $posts]);

        // Admin: load a single post for editing (includes content)
        case 'admin-blog-full':
            requireAdmin();
            $id   = $_GET['id'] ?? $_GET['slug'] ?? '';
            $post = blogLoad($id);
            if (!$post) jsonResponse(['error' => 'Not found'], 404);
            jsonResponse($post);

        // Admin: create or update a post
        case 'admin-blog-save':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $input = json_decode(file_get_contents('php://input'), true);

            $title   = trim($input['title']   ?? '');
            $content = trim($input['content'] ?? '');
            if (!$title || !$content) jsonResponse(['error' => 'Title and content are required.'], 400);

            $status  = ($input['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $now     = date('c');   // ISO 8601

            // Preserve existing slug/id if editing; generate from title for new posts
            $existingSlug = trim($input['id'] ?? $input['slug'] ?? '');
            if ($existingSlug) {
                $existing = blogLoad($existingSlug);
                $slug      = $existing ? $existing['slug'] : slugify($title);
                $createdAt = $existing['created_at'] ?? $now;
            } else {
                $slug      = slugify($title);
                $createdAt = $now;
                // Ensure uniqueness
                $base = $slug; $i = 1;
                while (file_exists(BLOG_DIR . $slug . '.json')) $slug = $base . '-' . $i++;
            }

            $user = getCurrentUser();
            $post = [
                'id'          => $slug,
                'slug'        => $slug,
                'title'       => $title,
                'summary'     => trim($input['summary']     ?? ''),
                'cover_image' => trim($input['cover_image'] ?? ''),
                'content'     => $content,
                'status'      => $status,
                'author'      => $user['display_name'] ?? $user['email'] ?? 'Akilesh Bhandary',
                'created_at'  => $createdAt,
                'updated_at'  => $now,
            ];

            if (!blogSave($post)) jsonResponse(['error' => 'Could not save post. Check data/blog/ permissions.'], 500);
            jsonResponse(['success' => true, 'id' => $slug, 'slug' => $slug]);

        // Admin: delete a post
        case 'admin-blog-delete':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $slug  = trim($input['id'] ?? $input['slug'] ?? '');
            if (!$slug) jsonResponse(['error' => 'id required'], 400);
            blogDelete($slug);
            jsonResponse(['success' => true]);

        default:
            jsonResponse(['error' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    error_log('api-blog.php: ' . $e->getMessage());
    jsonResponse(['error' => 'Server error'], 500);
}
