<?php
/**
 * api.php — IndependentResearcher Platform API
 */

error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

startSession();

// ── Payment rate limiting  ─────────────────────────────────────────
// Uses a lightweight DB table to track attempts per user per action.
// Survives PHP restarts and shared hosting temp-dir cleanup - unlike the
// temp-file approach in api-payment.php which was also not wired here.
//
// Table is created on first call with CREATE TABLE IF NOT EXISTS.
// Call: apiPaymentRateLimit($db, $userId, 'create-order', 5, 300)
// -> allows 5 attempts per 300 seconds per user per action.
function apiPaymentRateLimit(PDO $db, int $userId, string $action, int $limit, int $windowSecs): void {
    // Ensure table exists (one-time cost, MySQL caches after first call)
    $db->exec("CREATE TABLE IF NOT EXISTS payment_rate_limits (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      INT UNSIGNED NOT NULL,
        action       VARCHAR(50)  NOT NULL,
        attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        window_start DATETIME NOT NULL,
        KEY idx_user_action (user_id, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $now = time();

    // Upsert: reset window if expired, increment within window
    $db->prepare("
        INSERT INTO payment_rate_limits (user_id, action, attempts, window_start)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            attempts     = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, 1, attempts + 1),
            window_start = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, NOW(), window_start)
    ")->execute([$userId, $action, $windowSecs, $windowSecs]);

    // Read back current count for this window
    $stmt = $db->prepare("
        SELECT attempts, TIMESTAMPDIFF(SECOND, window_start, NOW()) AS elapsed
        FROM payment_rate_limits
        WHERE user_id = ? AND action = ?
    ");
    $stmt->execute([$userId, $action]);
    $row = $stmt->fetch();

    if ($row && (int)$row['attempts'] > $limit) {
        $retryAfter = max(1, $windowSecs - (int)($row['elapsed'] ?? 0));
        jsonResponse([
            'error'       => 'Too many requests. Please wait before trying again.',
            'retry_after' => $retryAfter,
        ], 429);
    }
}
// ─────────────────────────────────────────────────────────────────────────────

ob_start();

// ── Everything including DB init is inside try/catch ─────────────────────────
try {
    $db = getDB();

    switch ($action) {

        // ═══════════════════════════════════════════════════════════════
        // AUTH
        // ═══════════════════════════════════════════════════════════════

        case 'register':
            $data     = json_decode(file_get_contents('php://input'), true);
            $email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
            $password = $data['password'] ?? '';

            if (!$email || strlen($password) < PASSWORD_MIN_LENGTH) {
                jsonResponse(['error' => 'Invalid email or password too short (min ' . PASSWORD_MIN_LENGTH . ' chars)'], 400);
            }

            $stmt = $db->prepare('SELECT id, email_verified FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $existing = $stmt->fetch();

            if ($existing) {
                $msg = $existing['email_verified']
                    ? 'Email already registered.'
                    : 'Email registered but not verified. Please use Google Sign-In.';
                jsonResponse(['error' => $msg], 400);
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('
                INSERT INTO users
                    (email, password_hash, email_verified, auth_provider, role, created_at)
                VALUES (?, ?, 0, "email", "user", NOW())
            ');
            $stmt->execute([$email, $hashedPassword]);
            $userId = (int) $db->lastInsertId();

            // Auto-grant trial access to all free simulations
            $stmt = $db->prepare('
                INSERT IGNORE INTO purchases
                    (user_id, product_id, status, payment_method, access_type, created_at)
                SELECT ?, id, "trial", "free", "trial", NOW()
                FROM products
                WHERE is_free_trial = TRUE AND is_active = TRUE
            ');
            $stmt->execute([$userId]);

            jsonResponse([
                'success' => true,
                'message' => 'Account created. Sign in with your email/password or use Google Sign-In.',
                'email'   => $email,
            ]);
            break;

        case 'login':
            $data     = json_decode(file_get_contents('php://input'), true);
            $email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
            $password = $data['password'] ?? '';

            if (!$email || !$password) {
                jsonResponse(['error' => 'Email and password required'], 400);
            }

            $stmt = $db->prepare('SELECT id, email, password_hash, email_verified, role, auth_provider FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                jsonResponse(['error' => 'Invalid credentials'], 401);
            }

            // Google-only accounts have no password
            if ($user['auth_provider'] === 'google' || empty($user['password_hash'])) {
                jsonResponse([
                    'error'           => 'This account uses Google Sign-In. Please click "Continue with Google".',
                    'useGoogleSignIn' => true,
                ], 403);
            }

            if (!password_verify($password, $user['password_hash'])) {
                jsonResponse(['error' => 'Invalid credentials'], 401);
            }

            $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

            $_SESSION['user_id']       = $user['id'];
            $_SESSION['user_email']    = $user['email'];
            $_SESSION['auth_provider'] = 'email';

            // Ensure trial access is granted
            $db->prepare('
                INSERT IGNORE INTO purchases
                    (user_id, product_id, status, payment_method, access_type, created_at)
                SELECT ?, id, "trial", "free", "trial", NOW()
                FROM products WHERE is_free_trial = TRUE AND is_active = TRUE
            ')->execute([$user['id']]);

            jsonResponse([
                'success'  => true,
                'email'    => $user['email'],
                'is_admin' => ($user['role'] === 'admin'),
            ]);
            break;

        case 'logout':
            session_destroy();
            jsonResponse(['message' => 'Logged out successfully']);
            break;

        case 'user':
            $user = requireAuth();
            jsonResponse($user);
            break;

        case 'resend-verification':
            jsonResponse(['success' => true, 'message' => 'Please use Google Sign-In — email verification is not available.']);
            break;

        case 'request-password-reset':
            jsonResponse(['success' => true, 'message' => 'Password reset via email is not available. Please use Google Sign-In.']);
            break;

        // ═══════════════════════════════════════════════════════════════
        // PRODUCTS
        // ═══════════════════════════════════════════════════════════════

        case 'products':
            $user = getCurrentUser();

            $stmt     = $db->query('
                SELECT id, title, description, price, currency, preview_image, tags,
                       is_active, is_free_trial
                FROM products WHERE is_active = TRUE ORDER BY title
            ');
            $products = $stmt->fetchAll();

            foreach ($products as &$product) {
                $product['tags']      = json_decode($product['tags'] ?? '[]');
                $product['purchased'] = false;
                $product['has_trial'] = (bool) $product['is_free_trial'];

                if ($user) {
                    $chk = $db->prepare("
                        SELECT id FROM purchases
                        WHERE user_id = ? AND product_id = ?
                        AND status IN ('paid','trial','demo')
                    ");
                    $chk->execute([$user['id'], $product['id']]);
                    $product['purchased'] = (bool) $chk->fetch();
                }
            }
            unset($product);
            jsonResponse($products);
            break;

        case 'product':
            $productId = $_GET['id'] ?? '';
            if (!$productId) jsonResponse(['error' => 'Product ID required'], 400);

            $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) jsonResponse(['error' => 'Product not found'], 404);

            $product['tags']      = json_decode($product['tags'] ?? '[]');
            $product['purchased'] = false;

            $user = getCurrentUser();
            if ($user) {
                $chk = $db->prepare("
                    SELECT id FROM purchases
                    WHERE user_id = ? AND product_id = ? AND status IN ('paid','trial','demo')
                ");
                $chk->execute([$user['id'], $productId]);
                $product['purchased'] = (bool) $chk->fetch();
            }
            jsonResponse($product);
            break;

        // ═══════════════════════════════════════════════════════════════
        // PURCHASES / ACCESS
        // ═══════════════════════════════════════════════════════════════

        case 'my-purchases':
            $user = requireAuth();
            $stmt = $db->prepare("
                SELECT p.*, pr.title, pr.description, pr.sim_file, p.access_type
                FROM purchases p
                JOIN products pr ON p.product_id = pr.id
                WHERE p.user_id = ? AND p.status IN ('paid','trial','demo')
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$user['id']]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'check-access':
            $user      = requireAuth();
            $productId = $_GET['product_id'] ?? '';
            if (!$productId) jsonResponse(['error' => 'product_id required'], 400);

            $stmt = $db->prepare("
                SELECT id FROM purchases
                WHERE user_id = ? AND product_id = ? AND status IN ('paid','trial','demo')
            ");
            $stmt->execute([$user['id'], $productId]);
            jsonResponse(['has_access' => (bool) $stmt->fetch()]);
            break;

        case 'grant-trial':
            // No auth required — guests can view trial without login
            $user      = getCurrentUser(); // null for guests
            $data      = json_decode(file_get_contents('php://input'), true);
            $productId = $data['product_id'] ?? '';
            if (!$productId) jsonResponse(['error' => 'product_id required'], 400);

            $stmt = $db->prepare('SELECT id FROM products WHERE id = ? AND is_free_trial = TRUE AND is_active = TRUE');
            $stmt->execute([$productId]);
            if (!$stmt->fetch()) jsonResponse(['error' => 'Product not available for trial'], 403);

            // Only record DB row for authenticated users (guests have no user_id)
            if ($user) {
                $stmt = $db->prepare("
                    INSERT IGNORE INTO purchases
                        (user_id, product_id, status, payment_method, access_type, created_at)
                    VALUES (?, ?, 'trial', 'free', 'trial', NOW())
                ");
                $stmt->execute([$user['id'], $productId]);
            }
            jsonResponse(['success' => true]);
            break;

        case 'grant-all-trials':
            $user = requireAuth();
            $stmt = $db->prepare("
                INSERT IGNORE INTO purchases
                    (user_id, product_id, status, payment_method, access_type, created_at)
                SELECT ?, id, 'trial', 'free', 'trial', NOW()
                FROM products WHERE is_free_trial = TRUE AND is_active = TRUE
            ");
            $stmt->execute([$user['id']]);
            jsonResponse(['success' => true, 'granted' => $stmt->rowCount()]);
            break;

        case 'demo-purchase':
            if (!DEMO_MODE) jsonResponse(['error' => 'Demo mode is disabled'], 403);

            $user      = requireAuth();
            $data      = json_decode(file_get_contents('php://input'), true);
            $productId = $data['productId'] ?? '';
            if (!$productId) jsonResponse(['error' => 'Product ID required'], 400);

            $stmt = $db->prepare('SELECT title, price FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) jsonResponse(['error' => 'Product not found'], 404);

            $stmt = $db->prepare("SELECT id FROM purchases WHERE user_id = ? AND product_id = ? AND status IN ('paid','demo')");
            $stmt->execute([$user['id'], $productId]);
            if ($stmt->fetch()) jsonResponse(['message' => 'Already purchased', 'success' => true]);

            $stmt = $db->prepare("
                INSERT INTO purchases (user_id, product_id, amount, status, payment_method, payment_id, created_at)
                VALUES (?, ?, ?, 'demo', 'demo', ?, NOW())
            ");
            $stmt->execute([$user['id'], $productId, $product['price'], 'demo_' . time()]);
            jsonResponse(['success' => true, 'message' => '"' . $product['title'] . '" unlocked! (Demo mode)']);
            break;

        // ═══════════════════════════════════════════════════════════════
        // PAYMENT — Razorpay
        // ═══════════════════════════════════════════════════════════════

        case 'create-purchase-order':
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            if (!PAYMENT_ENABLED) jsonResponse(['error' => 'Purchases coming soon — stay tuned!'], 503);

            $user      = requireAuth();

            // P-04: Rate limit — 5 order creations per 5 minutes per user
            apiPaymentRateLimit($db, (int)$user['id'], 'create-order', 5, 300);

            $data      = json_decode(file_get_contents('php://input'), true);
            $productId = $data['productId'] ?? '';
            if (!$productId) jsonResponse(['error' => 'Product ID required'], 400);

            $stmt = $db->prepare('SELECT id, title, price, currency FROM products WHERE id = ? AND is_active = 1');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) jsonResponse(['error' => 'Product not found'], 404);

            $stmt = $db->prepare("SELECT id FROM purchases WHERE user_id = ? AND product_id = ? AND status IN ('paid','demo')");
            $stmt->execute([$user['id'], $productId]);
            if ($stmt->fetch()) jsonResponse(['error' => 'You already own this simulation'], 400);

            require_once __DIR__ . '/razorpay-handler.php';
            $razorpay  = new RazorpayHandler();
            $orderData = $razorpay->createOrder($product['price'], $productId, $user['id'], $user['email']);

            jsonResponse(['success' => true, 'order' => $orderData, 'product' => ['title' => $product['title']]]);
            break;

        case 'verify-purchase':
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

            $user      = requireAuth();

            // Rate limit — 10 verification attempts per minute per user
            apiPaymentRateLimit($db, (int)$user['id'], 'verify-purchase', 10, 60);

            $data      = json_decode(file_get_contents('php://input'), true);
            $orderId   = $data['razorpay_order_id']   ?? '';
            $paymentId = $data['razorpay_payment_id'] ?? '';
            $signature = $data['razorpay_signature']  ?? '';

            if (!$orderId || !$paymentId || !$signature) jsonResponse(['error' => 'Missing payment details'], 400);

            // Validate ID formats before touching DB or external APIs
            if (!preg_match('/^order_[A-Za-z0-9]+$/', $orderId))   jsonResponse(['error' => 'Invalid order ID format'], 400);
            if (!preg_match('/^pay_[A-Za-z0-9]+$/',   $paymentId)) jsonResponse(['error' => 'Invalid payment ID format'], 400);

            require_once __DIR__ . '/razorpay-handler.php';
            $razorpay = new RazorpayHandler();

            // completePayment() performs: HMAC signature verify -> Razorpay API call to
            // confirm capture status -> amount cross-check -> order_id cross-check ->
            // SELECT FOR UPDATE atomic DB write.
            try {
                $result = $razorpay->completePayment($orderId, $paymentId, $signature);
            } catch (Exception $completeEx) {
                // completePayment throws descriptive exceptions for each failure mode.
                // Surface safe messages; log full detail.
                $safeMessages = [
                    'Invalid payment signature',
                    'Payment not captured',
                    'Amount mismatch detected',
                    'Order ID mismatch',
                    'Purchase not found or already completed',
                ];
                $msg = in_array($completeEx->getMessage(), $safeMessages, true)
                    ? $completeEx->getMessage()
                    : 'Payment verification failed. If amount was deducted contact support with payment ID: ' . $paymentId;
                jsonResponse(['error' => $msg], 400);
            }

            // Fetch product title for success message
            $stmt = $db->prepare("SELECT pr.title FROM purchases p JOIN products pr ON p.product_id = pr.id WHERE p.id = ?");
            $stmt->execute([$result['purchase_id']]);
            $row = $stmt->fetch();
            $title = $row['title'] ?? 'your simulation';

            // Determine if this is the customer's first paid purchase (for GA conversion tracking)
            $ncStmt = $db->prepare("SELECT COUNT(*) FROM purchases WHERE user_id = ? AND status = 'paid'");
            $ncStmt->execute([$user['id']]);
            $paidCount    = (int) $ncStmt->fetchColumn();
            $isNewCustomer = $paidCount <= 1; // <=1 because the current purchase was just inserted

            jsonResponse([
                'success'          => true,
                'message'          => 'You now own "' . $title . '"!',
                'product_id'       => $result['product_id'],
                'payment_id'       => $paymentId,   // razorpay payment ID for GA transaction_id
                'is_new_customer'  => $isNewCustomer,
            ]);
            break;

        // ═══════════════════════════════════════════════════════════════
        // ADMIN
        // ═══════════════════════════════════════════════════════════════

        case 'admin-dashboard':
            requireAdmin();
            $metrics = [
                'totalUsers'     => $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'totalProducts'  => $db->query('SELECT COUNT(*) FROM products')->fetchColumn(),
                'totalPurchases' => $db->query("SELECT COUNT(*) FROM purchases WHERE status IN ('paid','demo')")->fetchColumn(),
                'totalRevenue'   => $db->query("SELECT COALESCE(SUM(amount),0) FROM purchases WHERE status='paid'")->fetchColumn(),
            ];
            $stmt = $db->query("
                SELECT p.*, u.email, pr.title FROM purchases p
                JOIN users u ON p.user_id=u.id JOIN products pr ON p.product_id=pr.id
                WHERE p.status IN ('paid','demo') ORDER BY p.created_at DESC LIMIT 10
            ");
            jsonResponse(['metrics' => $metrics, 'recentPurchases' => $stmt->fetchAll()]);
            break;

        case 'admin-users':
            requireAdmin();
            $stmt = $db->query('SELECT id, email, display_name, role, auth_provider, email_verified, avatar_url, DATE(created_at) AS created_at, DATE(last_login) AS last_login FROM users ORDER BY created_at DESC');
            jsonResponse($stmt->fetchAll());
            break;

        case 'admin-products':
            requireAdmin();
            if ($method === 'GET') {
                $stmt = $db->query('SELECT * FROM products ORDER BY title');
                $products = $stmt->fetchAll();
                foreach ($products as &$p) $p['tags'] = json_decode($p['tags'] ?? '[]');
                unset($p);
                jsonResponse($products);
            } elseif ($method === 'POST') {
                $data        = json_decode(file_get_contents('php://input'), true);
                $id          = $data['id']            ?? '';
                $title       = $data['title']         ?? '';
                $description = $data['description']   ?? '';
                $price       = (float)($data['price'] ?? 0);
                $currency    = $data['currency']      ?? 'INR';
                $simFile     = $data['sim_file']      ?? '';
                $paidFile    = trim($data['paid_file']   ?? '');
                // Auto-add paid_file column if missing
                try { $db->query('SELECT paid_file FROM products LIMIT 1'); }
                catch (PDOException $e) { $db->exec('ALTER TABLE products ADD COLUMN paid_file VARCHAR(255) NULL AFTER sim_file'); }
                $preview     = $data['preview_image'] ?? '';
                $tags        = json_encode($data['tags'] ?? []);
                $isActive    = !empty($data['is_active']);
                $isFreeTrial = !empty($data['is_free_trial']);

                if (!$title || !$simFile) jsonResponse(['error' => 'Title and sim_file required'], 400);

                if ($id) {
                    $db->prepare('UPDATE products SET title=?,description=?,price=?,currency=?,sim_file=?,paid_file=?,preview_image=?,tags=?,is_active=?,is_free_trial=? WHERE id=?')
                       ->execute([$title,$description,$price,$currency,$simFile,$paidFile,$preview,$tags,$isActive,$isFreeTrial,$id]);
                    jsonResponse(['message' => 'Product updated']);
                } else {
                    $newId = 'sim-' . strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
                    $db->prepare('INSERT INTO products (id,title,description,price,currency,sim_file,preview_image,tags,is_active,is_free_trial) VALUES (?,?,?,?,?,?,?,?,?,?)')
                       ->execute([$newId,$title,$description,$price,$currency,$simFile,$preview,$tags,$isActive,$isFreeTrial]);
                    jsonResponse(['message' => 'Product created', 'id' => $newId], 201);
                }
            }
            break;

        case 'admin-purchases':
            requireAdmin();
            $page          = max(1, (int)($_GET['page']       ?? 1));
            $limit         = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset        = ($page - 1) * $limit;
            $userFilter    = $_GET['user_id']    ?? '';
            $productFilter = $_GET['product_id'] ?? '';
            $statusFilter  = $_GET['status']     ?? '';

            $where = ['1=1']; $params = [];
            if ($userFilter)    { $where[] = 'p.user_id=?';    $params[] = $userFilter; }
            if ($productFilter) { $where[] = 'p.product_id=?'; $params[] = $productFilter; }
            if ($statusFilter && in_array($statusFilter, ['paid','demo','trial','pending','failed'])) {
                $where[] = 'p.status=?'; $params[] = $statusFilter;
            }
            $wc = implode(' AND ', $where);

            $cntStmt = $db->prepare("SELECT COUNT(*) FROM purchases p WHERE $wc");
            $cntStmt->execute($params);
            $total = $cntStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT p.*, u.email as user_email, pr.title as product_title
                FROM purchases p JOIN users u ON p.user_id=u.id JOIN products pr ON p.product_id=pr.id
                WHERE $wc ORDER BY p.created_at DESC LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$limit, $offset]));
            jsonResponse(['purchases' => $stmt->fetchAll(), 'pagination' => ['page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>ceil($total/$limit)]]);
            break;

        case 'change-password':
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $user = requireAuth();
            $data = json_decode(file_get_contents('php://input'), true);
            $old  = $data['old_password'] ?? '';
            $new  = $data['new_password'] ?? '';
            if (!$old || !$new)                       jsonResponse(['error' => 'Both passwords required'], 400);
            if (strlen($new) < PASSWORD_MIN_LENGTH)   jsonResponse(['error' => 'Min ' . PASSWORD_MIN_LENGTH . ' characters'], 400);

            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=?');
            $stmt->execute([$user['id']]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($old, $hash)) jsonResponse(['error' => 'Old password incorrect'], 403);

            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            jsonResponse(['success' => true, 'message' => 'Password updated']);
            break;

        case 'admin-questions':
            requireAdmin();
            $status  = $_GET['status'] ?? 'all';
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;
            $where   = '1=1'; $params = [];
            if ($status !== 'all') { $where .= ' AND status=?'; $params[] = $status; }

            $cntStmt = $db->prepare("SELECT COUNT(*) FROM physics_questions WHERE $where");
            $cntStmt->execute($params);
            $total = $cntStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT id, email, LEFT(question,200) as question_preview, question as full_question,
                       with_donation, status, ip_address, created_at, answered_at
                FROM physics_questions WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            jsonResponse(['success'=>true,'questions'=>$stmt->fetchAll(),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>ceil($total/$perPage)]]);
            break;

        case 'admin-update-question-status':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $data       = json_decode(file_get_contents('php://input'), true);
            $questionId = (int)($data['question_id'] ?? 0);
            $status     = $data['status'] ?? '';
            if (!$questionId || !in_array($status, ['pending','answered','spam'])) jsonResponse(['error' => 'Invalid parameters'], 400);
            $db->prepare("UPDATE physics_questions SET status=?, answered_at=CASE WHEN ?='answered' THEN NOW() ELSE answered_at END, updated_at=NOW() WHERE id=?")
               ->execute([$status,$status,$questionId]);
            jsonResponse(['success' => true]);
            break;

        case 'admin-delete-question':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $data       = json_decode(file_get_contents('php://input'), true);
            $questionId = (int)($data['question_id'] ?? 0);
            if (!$questionId) jsonResponse(['error' => 'Question ID required'], 400);
            $db->prepare('DELETE FROM physics_questions WHERE id=?')->execute([$questionId]);
            jsonResponse(['success' => true]);
            break;

        case 'admin-error-logs':
            requireAdmin();
            $lines   = max(10, min(500, (int)($_GET['lines'] ?? 50)));
            $logFile = __DIR__ . '/logs/php_errors.log';
            if (!file_exists($logFile)) jsonResponse(['logs' => [], 'message' => 'No log file yet']);
            $file = new SplFileObject($logFile, 'r');
            $file->seek(PHP_INT_MAX);
            $total = $file->key();
            $file->seek(max(0, $total - $lines));
            $logs = [];
            while (!$file->eof()) { $line = $file->fgets(); if (trim($line) !== '') $logs[] = $line; }
            jsonResponse(['logs' => $logs]);
            break;


        // ═══════════════════════════════════════════════════════════════
        // ADMIN SQL REPORTS — read-only SELECT queries only
        // ═══════════════════════════════════════════════════════════════

        case 'admin-run-report':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);

            $data  = json_decode(file_get_contents('php://input'), true);
            $query = trim($data['query'] ?? '');

            if (empty($query)) jsonResponse(['error' => 'Query required'], 400);

            // SECURITY: only allow SELECT statements, block anything destructive.
            // FIX A-01: Reject semicolons first to prevent multi-statement attacks
            // like "SELECT 1; DELETE FROM users" which pass the starts-with check.
            if (str_contains($query, ';')) {
                jsonResponse(['error' => 'Multi-statement queries are not permitted. Remove any semicolons.'], 403);
            }

            $upper   = strtoupper(preg_replace('/\s+/', ' ', $query));
            $blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER',
                        'CREATE', 'REPLACE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE',
                        'CALL', 'LOAD', 'OUTFILE', 'DUMPFILE', 'INTO'];
            foreach ($blocked as $kw) {
                if (str_contains($upper, $kw)) {
                    jsonResponse(['error' => "Query contains forbidden keyword: $kw. Only SELECT is allowed."], 403);
                }
            }
            if (!str_starts_with($upper, 'SELECT') && !str_starts_with($upper, 'SHOW')) {
                jsonResponse(['error' => 'Only SELECT and SHOW queries are permitted.'], 403);
            }

            try {
                $stmt = $db->query($query);
                $rows    = $stmt->fetchAll();
                $columns = $rows ? array_keys($rows[0]) : [];
                jsonResponse(['success' => true, 'columns' => $columns, 'rows' => $rows, 'count' => count($rows)]);
            } catch (PDOException $e) {
                jsonResponse(['error' => 'Query error: ' . $e->getMessage()], 400);
            }
            break;


        case 'admin-set-role':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'],405);
            $d      = json_decode(file_get_contents('php://input'),true);
            $uid    = (int)($d['user_id'] ?? 0);
            $role   = ($d['role'] ?? '') === 'admin' ? 'admin' : 'user';
            if (!$uid) jsonResponse(['error'=>'user_id required'],400);
            // Prevent removing admin from all ADMIN_EMAILS
            if ($role === 'user') {
                $adminEmails = array_map('strtolower', array_filter(array_map('trim', explode(',', ADMIN_EMAILS))));
                $eStmt = $db->prepare('SELECT email FROM users WHERE id=?');
                $eStmt->execute([$uid]);
                $targetEmail = strtolower((string)$eStmt->fetchColumn());
                if (in_array($targetEmail, $adminEmails)) {
                    jsonResponse(['error'=>'Cannot demote a protected admin email. Remove from ADMIN_EMAILS in .env first.'],403);
                }
            }
            $db->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$uid]);
            jsonResponse(['success'=>true]);
            break;

        case 'admin-saved-reports':
            requireAdmin();
            // Built-in canned reports
            $reports = [
                ['id' => 'users_summary',      'label' => 'Users Summary',
                 'query' => 'SELECT id, email, role, auth_provider, email_verified, DATE(created_at) AS joined, DATE(last_login) AS last_login FROM users ORDER BY created_at DESC LIMIT 100'],
                ['id' => 'most_used_sims',     'label' => 'Most Accessed Simulations',
                 'query' => 'SELECT pr.title, pr.id AS sim_id, COUNT(p.id) AS access_count, SUM(p.status="trial") AS trial_count, SUM(p.status="paid") AS paid_count FROM purchases p JOIN products pr ON p.product_id=pr.id GROUP BY p.product_id ORDER BY access_count DESC'],
                ['id' => 'daily_signups',      'label' => 'Daily Sign-ups (last 30 days)',
                 'query' => 'SELECT DATE(created_at) AS date, COUNT(*) AS signups, SUM(auth_provider="google") AS google_signups, SUM(auth_provider="email") AS email_signups FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY date DESC'],
                ['id' => 'trial_access',       'label' => 'Trial Access by User',
                 'query' => 'SELECT u.email, COUNT(p.id) AS trial_count, MIN(DATE(p.created_at)) AS first_access FROM purchases p JOIN users u ON p.user_id=u.id WHERE p.status="trial" GROUP BY p.user_id ORDER BY trial_count DESC LIMIT 50'],
                ['id' => 'questions_log',      'label' => 'Physics Questions Submitted',
                 'query' => 'SELECT id, email, LEFT(question,80) AS question_preview, status, DATE(created_at) AS submitted FROM physics_questions ORDER BY created_at DESC LIMIT 100'],
                ['id' => 'revenue_summary',    'label' => 'Revenue Summary',
                 'query' => 'SELECT DATE_FORMAT(created_at,"%Y-%m") AS month, COUNT(*) AS paid_count, SUM(amount) AS revenue_inr FROM purchases WHERE status="paid" GROUP BY month ORDER BY month DESC'],
                ['id' => 'donations_log',      'label' => 'Donations Log',
                 'query' => 'SELECT donor_name, donor_email, amount, status, DATE(created_at) AS date FROM donations ORDER BY created_at DESC LIMIT 100'],
                ['id' => 'active_users',       'label' => 'Active Users (last 7 days)',
                 'query' => 'SELECT email, role, auth_provider, last_login FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY last_login DESC'],
                ['id' => 'products_catalog',   'label' => 'Simulations Catalog',
                 'query' => 'SELECT id, title, price, is_active, is_free_trial, created_at FROM products ORDER BY title'],
            ];
            jsonResponse(['success' => true, 'reports' => $reports]);
            break;

        // ═══════════════════════════════════════════════════════════════
        // USER PROFILE
        // ═══════════════════════════════════════════════════════════════

        case 'get-profile':
            $user = requireAuth();
            $stmt = $db->prepare('SELECT id, email, role, auth_provider, google_email, avatar_url, display_name, created_at, last_login FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $profile = $stmt->fetch();
            if (!$profile) jsonResponse(['error' => 'User not found'], 404);
            // Never send password hash
            jsonResponse($profile);
            break;

        case 'update-profile':
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $user = requireAuth();
            $data = json_decode(file_get_contents('php://input'), true);
            $displayName = trim($data['display_name'] ?? '');
            if (strlen($displayName) > 80) jsonResponse(['error' => 'Name too long (max 80 chars)'], 400);

            // Auto-add display_name column if missing (migration)
            try {
                $db->query('SELECT display_name FROM users LIMIT 1');
            } catch (PDOException $e) {
                $db->exec('ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NULL AFTER email');
            }

            $db->prepare('UPDATE users SET display_name = ? WHERE id = ?')
               ->execute([$displayName ?: null, $user['id']]);
            jsonResponse(['success' => true, 'display_name' => $displayName]);
            break;

        case 'upload-avatar':
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $user = requireAuth();

            if (!isset($_FILES['avatar'])) jsonResponse(['error' => 'No file uploaded'], 400);

            $file    = $_FILES['avatar'];
            $maxSize = 2 * 1024 * 1024; // 2 MB
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

            if ($file['error'] !== UPLOAD_ERR_OK) jsonResponse(['error' => 'Upload failed (code ' . $file['error'] . ')'], 400);
            if ($file['size'] > $maxSize)          jsonResponse(['error' => 'File too large — max 2 MB'], 400);

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mimeType, $allowed)) jsonResponse(['error' => 'Only JPEG, PNG, WebP or GIF allowed'], 400);

            $ext      = explode('/', $mimeType)[1];
            $ext      = ($ext === 'jpeg') ? 'jpg' : $ext;
            $dir      = __DIR__ . '/uploads/avatars/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Delete old avatar if it was a local upload
            $stmt = $db->prepare('SELECT avatar_url FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $old = $stmt->fetchColumn();
            if ($old && strpos($old, 'uploads/avatars/') !== false && file_exists(__DIR__ . '/' . $old)) {
                @unlink(__DIR__ . '/' . $old);
            }

            $filename = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
            $destPath = $dir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                jsonResponse(['error' => 'Could not save file'], 500);
            }

            $relUrl = 'uploads/avatars/' . $filename;
            $db->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$relUrl, $user['id']]);
            jsonResponse(['success' => true, 'avatar_url' => $relUrl]);
            break;


        // ═══════════════════════════════════════════════════════════════════
        // ═══════════════════════════════════════════════════════════════════
        // BLOG  (JSON file storage — posts live in /data/blog/<slug>.json)
        // ═══════════════════════════════════════════════════════════════════

        case 'blog-posts':
            $blogDir = __DIR__ . '/data/blog/';
            $posts   = [];
            if (is_dir($blogDir)) {
                foreach (glob($blogDir . '*.json') as $f) {
                    $p = json_decode(file_get_contents($f), true);
                    if (is_array($p) && ($p['status'] ?? '') === 'published') {
                        unset($p['content']); // exclude body from list view
                        $posts[] = $p;
                    }
                }
                usort($posts, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            }
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $limit = 10;
            $total = count($posts);
            $posts = array_values(array_slice($posts, ($page - 1) * $limit, $limit));
            jsonResponse(['success' => true, 'posts' => $posts, 'total' => $total,
                          'page' => $page, 'pages' => (int) ceil($total / $limit)]);
            break;

        case 'blog-post':
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'] ?? '')));
            if (!$slug) jsonResponse(['error' => 'slug required'], 400);
            $file = __DIR__ . '/data/blog/' . $slug . '.json';
            if (!file_exists($file)) jsonResponse(['error' => 'Post not found'], 404);
            $post = json_decode(file_get_contents($file), true);
            if (!is_array($post) || ($post['status'] ?? '') !== 'published') {
                jsonResponse(['error' => 'Post not found'], 404);
            }
            jsonResponse($post);
            break;

        case 'admin-blog-list':
            requireAdmin();
            $blogDir = __DIR__ . '/data/blog/';
            $posts   = [];
            if (is_dir($blogDir)) {
                foreach (glob($blogDir . '*.json') as $f) {
                    $p = json_decode(file_get_contents($f), true);
                    if (is_array($p)) {
                        unset($p['content']);
                        $posts[] = $p;
                    }
                }
                usort($posts, fn($a,$b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
            }
            jsonResponse(['success' => true, 'posts' => $posts]);
            break;

        case 'admin-blog-full':
            requireAdmin();
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['id'] ?? $_GET['slug'] ?? '')));
            if (!$slug) jsonResponse(['error' => 'id required'], 400);
            $file = __DIR__ . '/data/blog/' . $slug . '.json';
            if (!file_exists($file)) jsonResponse(['error' => 'Not found'], 404);
            $post = json_decode(file_get_contents($file), true);
            jsonResponse($post ?: ['error' => 'Invalid JSON']);
            break;

        case 'admin-blog-save':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $d       = json_decode(file_get_contents('php://input'), true);
            $title   = trim($d['title']       ?? '');
            $content = trim($d['content']     ?? '');
            if (!$title || !$content) jsonResponse(['error' => 'Title and content required'], 400);

            $status   = ($d['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $summary  = trim($d['summary']     ?? '');
            $cover    = trim($d['cover_image'] ?? '');
            $now      = date('c');

            // Preserve slug from existing post; generate from title for new ones
            $existingSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($d['id'] ?? $d['slug'] ?? '')));
            if ($existingSlug && file_exists(__DIR__ . '/data/blog/' . $existingSlug . '.json')) {
                $existing  = json_decode(file_get_contents(__DIR__ . '/data/blog/' . $existingSlug . '.json'), true);
                $slug      = $existingSlug;
                $createdAt = $existing['created_at'] ?? $now;
            } else {
                $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)), '-');
                $slug = substr($slug, 0, 100);
                // Ensure uniqueness
                $base = $slug; $i = 1;
                while (file_exists(__DIR__ . '/data/blog/' . $slug . '.json')) {
                    $slug = $base . '-' . $i++;
                }
                $createdAt = $now;
            }

            $blogDir = __DIR__ . '/data/blog/';
            if (!is_dir($blogDir)) mkdir($blogDir, 0750, true);
            $user = getCurrentUser();

            $post = [
                'id'          => $slug,
                'slug'        => $slug,
                'title'       => $title,
                'summary'     => $summary,
                'cover_image' => $cover,
                'content'     => $content,
                'status'      => $status,
                'author'      => $user['display_name'] ?? $user['email'] ?? 'Akilesh Bhandary',
                'created_at'  => $createdAt,
                'updated_at'  => $now,
            ];

            if (file_put_contents($blogDir . $slug . '.json', json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                jsonResponse(['error' => 'Could not save — check data/blog/ folder permissions (chmod 755)'], 500);
            }
            jsonResponse(['success' => true, 'id' => $slug, 'slug' => $slug]);
            break;

        case 'admin-blog-delete':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $d    = json_decode(file_get_contents('php://input'), true);
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($d['id'] ?? $d['slug'] ?? '')));
            if (!$slug) jsonResponse(['error' => 'id required'], 400);
            $file = __DIR__ . '/data/blog/' . $slug . '.json';
            if (file_exists($file)) unlink($file);
            jsonResponse(['success' => true]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // RATINGS
        // ═══════════════════════════════════════════════════════════════════

        case 'rate-sim':
            try { $db->exec("CREATE TABLE IF NOT EXISTS sim_ratings (\n    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n    product_id VARCHAR(100) NOT NULL,\n    user_id INT UNSIGNED NOT NULL,\n    rating TINYINT UNSIGNED NOT NULL,\n    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n    UNIQUE KEY uq_user_product (user_id, product_id),\n    KEY idx_product (product_id)\n)"); } catch(PDOException $e) {}
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'],405);
            $user = requireAuth();
            $d    = json_decode(file_get_contents('php://input'),true);
            $pid  = trim($d['product_id'] ?? '');
            $rat  = (int)($d['rating']     ?? 0);
            if (!$pid || $rat < 1 || $rat > 5) jsonResponse(['error'=>'product_id and rating(1-5) required'],400);
            // Only allow rating if user has used the sim
            $used = $db->prepare("SELECT id FROM sim_usage WHERE product_id=? AND user_id=? LIMIT 1");
            $used->execute([$pid, $user['id']]);
            if (!$used->fetch()) jsonResponse(['error'=>'You must interact with the simulation before rating it'],403);
            $db->prepare("INSERT INTO sim_ratings (product_id,user_id,rating) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE rating=VALUES(rating), updated_at=NOW()")
               ->execute([$pid,$user['id'],$rat]);
            // Return new aggregate
            $agg = $db->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS count FROM sim_ratings WHERE product_id=?");
            $agg->execute([$pid]);
            jsonResponse(['success'=>true] + $agg->fetch());
            break;

        case 'sim-ratings':
            $pid = $_GET['product_id'] ?? '';
            if (!$pid) jsonResponse(['error'=>'product_id required'],400);
            $agg = $db->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS count FROM sim_ratings WHERE product_id=?");
            $agg->execute([$pid]);
            $row = $agg->fetch();
            // Also get current user's rating if logged in
            $mine = null;
            $u = getCurrentUser();
            if ($u) {
                $ms = $db->prepare("SELECT rating FROM sim_ratings WHERE product_id=? AND user_id=?");
                $ms->execute([$pid,$u['id']]);
                $mine = ($r=$ms->fetch()) ? (int)$r['rating'] : null;
            }
            jsonResponse(['avg_rating'=>round((float)$row['avg_rating'],1),'count'=>(int)$row['count'],'my_rating'=>$mine]);
            break;

        case 'sim-ratings-bulk':
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS sim_ratings (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id VARCHAR(100) NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    rating TINYINT UNSIGNED NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_user_product (user_id, product_id),
                    KEY idx_product (product_id)
                )");
                $rows = $db->query("SELECT product_id, ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS cnt FROM sim_ratings GROUP BY product_id")->fetchAll();
                $map  = [];
                foreach ($rows as $r) $map[$r['product_id']] = ['avg'=>(float)$r['avg_rating'],'cnt'=>(int)$r['cnt']];
                jsonResponse($map);
            } catch (PDOException $e) {
                jsonResponse([]); // empty — no ratings yet
            }
            break;

        // ═══════════════════════════════════════════════════════════════════
        // COMMENTS
        // ═══════════════════════════════════════════════════════════════════

        case 'sim-comments':
            try { $db->exec("CREATE TABLE IF NOT EXISTS sim_comments (\n    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n    product_id VARCHAR(100) NOT NULL,\n    user_id INT UNSIGNED NOT NULL,\n    body TEXT NOT NULL,\n    status ENUM('visible','hidden') DEFAULT 'visible',\n    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n    KEY idx_product_status (product_id, status, created_at)\n)"); } catch(PDOException $e) {}
            $pid   = $_GET['product_id'] ?? '';
            $page  = max(1,(int)($_GET['page'] ?? 1));
            $limit = 10;
            $off   = ($page - 1) * $limit;
            if (!$pid) jsonResponse(['error'=>'product_id required'],400);
            $stmt = $db->prepare("
                SELECT sc.id, sc.body, sc.created_at,
                       u.display_name, u.email, u.avatar_url
                FROM sim_comments sc
                JOIN users u ON sc.user_id = u.id
                WHERE sc.product_id = ? AND sc.status = 'visible'
                ORDER BY sc.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $pid,  PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $off,   PDO::PARAM_INT);
            $stmt->execute();
            $comments = $stmt->fetchAll();
            $tstmt = $db->prepare("SELECT COUNT(*) FROM sim_comments WHERE product_id = ? AND status = 'visible'");
            $tstmt->execute([$pid]);
            $total = (int) $tstmt->fetchColumn();
            jsonResponse(['comments'=>$comments,'total'=>$total,'page'=>$page,'has_more'=>($off+$limit)<$total]);
            break;

        case 'post-comment':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'],405);
            $user = requireAuth();
            $d    = json_decode(file_get_contents('php://input'),true);
            $pid  = trim($d['product_id'] ?? '');
            $body = trim($d['body']       ?? '');
            if (!$pid || !$body) jsonResponse(['error'=>'product_id and body required'],400);
            if (strlen($body) > 1000) jsonResponse(['error'=>'Comment too long (max 1000 chars)'],400);
            // Must have used the sim
            $used = $db->prepare("SELECT id FROM sim_usage WHERE product_id=? AND user_id=? LIMIT 1");
            $used->execute([$pid,$user['id']]);
            if (!$used->fetch()) jsonResponse(['error'=>'Interact with the simulation before commenting'],403);
            $db->prepare("INSERT INTO sim_comments (product_id,user_id,body) VALUES (?,?,?)")
               ->execute([$pid,$user['id'],$body]);
            $cid  = (int)$db->lastInsertId();
            // Return comment with user info
            $cs   = $db->prepare("SELECT sc.id,sc.body,sc.created_at,u.display_name,u.email,u.avatar_url FROM sim_comments sc JOIN users u ON sc.user_id=u.id WHERE sc.id=?");
            $cs->execute([$cid]);
            jsonResponse(['success'=>true,'comment'=>$cs->fetch()]);
            break;

        case 'admin-delete-comment':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'],405);
            $d  = json_decode(file_get_contents('php://input'),true);
            $id = (int)($d['id'] ?? 0);
            $db->prepare("UPDATE sim_comments SET status='hidden' WHERE id=?")->execute([$id]);
            jsonResponse(['success'=>true]);
            break;
            
        // ═══════════════════════════════════════════════════════════════════
        // USAGE TRACKING
        // ═══════════════════════════════════════════════════════════════════

        case 'track-start':
            try { $db->exec("CREATE TABLE IF NOT EXISTS sim_usage (\n    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n    product_id VARCHAR(100) NOT NULL,\n    user_id INT UNSIGNED NULL,\n    session_id VARCHAR(64) NOT NULL,\n    seconds INT UNSIGNED DEFAULT 0,\n    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n    ended_at DATETIME NULL,\n    KEY idx_product (product_id),\n    KEY idx_user (user_id),\n    KEY idx_session (session_id)\n)"); } catch(PDOException $e) {}
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'],405);
            $d   = json_decode(file_get_contents('php://input'),true);
            $pid = trim($d['product_id'] ?? '');
            $sid = trim($d['session_id'] ?? '');
            if (!$pid || !$sid) jsonResponse(['error'=>'product_id and session_id required'],400);
            $u   = getCurrentUser();
            $uid = $u ? $u['id'] : null;
            $db->prepare("INSERT IGNORE INTO sim_usage (product_id,user_id,session_id,started_at) VALUES (?,?,?,NOW())")
               ->execute([$pid,$uid,$sid]);
            jsonResponse(['success'=>true]);
            break;

        case 'track-end':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'],405);
            $d       = json_decode(file_get_contents('php://input'),true);
            $pid     = trim($d['product_id'] ?? '');
            $sid     = trim($d['session_id'] ?? '');
            $seconds = max(0, (int)($d['seconds'] ?? 0));
            if (!$pid || !$sid) jsonResponse(['error'=>'product_id and session_id required'],400);
            $db->prepare("UPDATE sim_usage SET seconds=?, ended_at=NOW() WHERE product_id=? AND session_id=? AND ended_at IS NULL")
               ->execute([$seconds,$pid,$sid]);
            jsonResponse(['success'=>true]);
            break;

        case 'usage-stats':
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS sim_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(100) NOT NULL,
    user_id INT UNSIGNED NULL,
    session_id VARCHAR(64) NOT NULL,
    seconds INT UNSIGNED DEFAULT 0,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    KEY idx_product (product_id),
    KEY idx_user (user_id),
    KEY idx_session (session_id)
)");
                $rows = $db->query("
                    SELECT product_id,
                           COUNT(DISTINCT user_id) AS unique_users,
                           COUNT(*) AS session_count,
                           COALESCE(SUM(seconds),0) AS total_seconds
                    FROM sim_usage GROUP BY product_id ORDER BY total_seconds DESC
                ")->fetchAll();
                jsonResponse($rows);
            } catch (PDOException $e) {
                jsonResponse([]); // table just created or empty — return empty array
            }
            break;

        case 'my-usage':
            try { $db->exec("CREATE TABLE IF NOT EXISTS sim_usage (\n    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n    product_id VARCHAR(100) NOT NULL,\n    user_id INT UNSIGNED NULL,\n    session_id VARCHAR(64) NOT NULL,\n    seconds INT UNSIGNED DEFAULT 0,\n    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n    ended_at DATETIME NULL,\n    KEY idx_product (product_id),\n    KEY idx_user (user_id),\n    KEY idx_session (session_id)\n)"); } catch(PDOException $e) { jsonResponse([]); break; }
            $user = requireAuth();
            $rows = $db->prepare("
                SELECT product_id, SUM(seconds) AS total_seconds, COUNT(*) AS sessions
                FROM sim_usage WHERE user_id=? GROUP BY product_id ORDER BY total_seconds DESC
            ");
            $rows->execute([$user['id']]);
            jsonResponse($rows->fetchAll());
            break;

        case 'config':
            jsonResponse([
                'payment_enabled'  => PAYMENT_ENABLED,
                'demo_mode'        => DEMO_MODE,
                'razorpay_key_id'  => RAZORPAY_KEY_ID,
                'google_client_id' => GOOGLE_CLIENT_ID,
            ]);
            break;


        default:
            jsonResponse(['error' => 'Unknown action: ' . htmlspecialchars($action)], 404);
    }

} catch (PDOException $e) {
    error_log('DB error in api.php [' . ($action ?? '') . ']: ' . $e->getMessage());
    jsonResponse(['error' => 'Database error — check server logs'], 500);
} catch (Exception $e) {
    error_log('Error in api.php [' . ($action ?? '') . ']: ' . $e->getMessage());
    jsonResponse(['error' => 'Server error — check logs'], 500);
}

// Flush buffer — any stray output would corrupt JSON
$buffer = ob_get_clean();
if (!empty(trim($buffer))) {
    error_log('Unexpected output in api.php: ' . $buffer);
}
