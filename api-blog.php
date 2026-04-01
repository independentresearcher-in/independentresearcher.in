<?php
/**
 * Blog API Endpoints
 * 
 * WATERMARKS:
 * - Code Author: Pancham Kotian
 * - Website Owner: Akilesh Bhandary
 * - AI-Generated: Claude (Anthropic)
 * 
 * Handles blog posts, comments, and categories
 */

require_once 'config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        
        /**
         * Get blog posts list
         * GET /api-blog.php?action=posts&page=1&limit=10&category=physics
         */
        case 'posts':
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
            $offset = ($page - 1) * $limit;
            $category = $_GET['category'] ?? '';
            $search = $_GET['search'] ?? '';
            
            $db = getDB();
            
            // Build query
            $where = ["bp.status = 'published'"];
            $params = [];
            
            if ($category) {
                $where[] = "EXISTS (
                    SELECT 1 FROM blog_post_categories bpc 
                    JOIN blog_categories bc ON bpc.category_id = bc.id 
                    WHERE bpc.post_id = bp.id AND bc.slug = ?
                )";
                $params[] = $category;
            }
            
            if ($search) {
                $where[] = "(bp.title LIKE ? OR bp.excerpt LIKE ? OR bp.content LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $stmt = $db->prepare("
                SELECT COUNT(*) as total FROM blog_posts bp WHERE $whereClause
            ");
            $stmt->execute($params);
            $total = $stmt->fetch()['total'];
            
            // Get posts
            $stmt = $db->prepare("
                SELECT 
                    bp.id,
                    bp.slug,
                    bp.title,
                    bp.excerpt,
                    bp.featured_image,
                    bp.views,
                    bp.reading_time,
                    bp.published_at,
                    u.email as author_email,
                    GROUP_CONCAT(bc.name) as categories,
                    GROUP_CONCAT(bc.slug) as category_slugs,
                    GROUP_CONCAT(bc.color) as category_colors
                FROM blog_posts bp
                LEFT JOIN users u ON bp.author_id = u.id
                LEFT JOIN blog_post_categories bpc ON bp.id = bpc.post_id
                LEFT JOIN blog_categories bc ON bpc.category_id = bc.id
                WHERE $whereClause
                GROUP BY bp.id
                ORDER BY bp.published_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$limit, $offset]));
            $posts = $stmt->fetchAll();
            
            // Format posts
            foreach ($posts as &$post) {
                $post['categories'] = $post['categories'] ? explode(',', $post['categories']) : [];
                $post['category_slugs'] = $post['category_slugs'] ? explode(',', $post['category_slugs']) : [];
                $post['category_colors'] = $post['category_colors'] ? explode(',', $post['category_colors']) : [];
            }
            
            jsonResponse([
                'success' => true,
                'posts' => $posts,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
        
        /**
         * Get single blog post
         * GET /api-blog.php?action=post&slug=welcome-to-physics-simulations
         */
        case 'post':
            $slug = $_GET['slug'] ?? '';
            
            if (!$slug) {
                jsonResponse(['error' => 'Slug required'], 400);
            }
            
            $db = getDB();
            
            // Get post
            $stmt = $db->prepare("
                SELECT 
                    bp.*,
                    u.email as author_email,
                    GROUP_CONCAT(bc.name) as categories,
                    GROUP_CONCAT(bc.slug) as category_slugs,
                    GROUP_CONCAT(bc.color) as category_colors
                FROM blog_posts bp
                LEFT JOIN users u ON bp.author_id = u.id
                LEFT JOIN blog_post_categories bpc ON bp.id = bpc.post_id
                LEFT JOIN blog_categories bc ON bpc.category_id = bc.id
                WHERE bp.slug = ? AND bp.status = 'published'
                GROUP BY bp.id
            ");
            $stmt->execute([$slug]);
            $post = $stmt->fetch();
            
            if (!$post) {
                jsonResponse(['error' => 'Post not found'], 404);
            }
            
            // Increment view count
            $stmt = $db->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?");
            $stmt->execute([$post['id']]);
            
            // Format categories
            $post['categories'] = $post['categories'] ? explode(',', $post['categories']) : [];
            $post['category_slugs'] = $post['category_slugs'] ? explode(',', $post['category_slugs']) : [];
            $post['category_colors'] = $post['category_colors'] ? explode(',', $post['category_colors']) : [];
            
            // Get comments
            $stmt = $db->prepare("
                SELECT id, name, comment, created_at
                FROM blog_comments
                WHERE post_id = ? AND status = 'approved'
                ORDER BY created_at DESC
            ");
            $stmt->execute([$post['id']]);
            $post['comments'] = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'post' => $post
            ]);
            break;
        
        /**
         * Get categories
         * GET /api-blog.php?action=categories
         */
        case 'categories':
            $db = getDB();
            
            $stmt = $db->query("
                SELECT 
                    bc.*,
                    COUNT(bpc.post_id) as post_count
                FROM blog_categories bc
                LEFT JOIN blog_post_categories bpc ON bc.id = bpc.category_id
                LEFT JOIN blog_posts bp ON bpc.post_id = bp.id AND bp.status = 'published'
                GROUP BY bc.id
                ORDER BY bc.name
            ");
            $categories = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'categories' => $categories
            ]);
            break;
        
        /**
         * Add comment
         * POST /api-blog.php?action=add-comment
         */
        case 'add-comment':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $postId = intval($data['post_id'] ?? 0);
            $name = trim($data['name'] ?? '');
            $email = trim($data['email'] ?? '');
            $comment = trim($data['comment'] ?? '');
            
            // Validate
            if (!$postId || !$name || !$email || !$comment) {
                jsonResponse(['error' => 'All fields required'], 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['error' => 'Invalid email'], 400);
            }
            
            if (strlen($comment) < 10) {
                jsonResponse(['error' => 'Comment too short (min 10 characters)'], 400);
            }
            
            if (strlen($comment) > 1000) {
                jsonResponse(['error' => 'Comment too long (max 1000 characters)'], 400);
            }
            
            // Check if post exists
            $db = getDB();
            $stmt = $db->prepare("SELECT id FROM blog_posts WHERE id = ? AND status = 'published'");
            $stmt->execute([$postId]);
            
            if (!$stmt->fetch()) {
                jsonResponse(['error' => 'Post not found'], 404);
            }
            
            // Get user if logged in
            $userId = null;
            if (isset($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
            }
            
            // Insert comment (pending approval)
            $stmt = $db->prepare("
                INSERT INTO blog_comments (post_id, user_id, name, email, comment, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$postId, $userId, $name, $email, $comment]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Comment submitted for approval'
            ]);
            break;
        
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
    
} catch (Exception $e) {
    error_log('Blog API error: ' . $e->getMessage());
    jsonResponse([
        'error' => 'Operation failed',
        'message' => DEMO_MODE ? $e->getMessage() : 'Please try again later'
    ], 500);
}
