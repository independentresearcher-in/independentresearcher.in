<?php
/**
 * api.php — IndependentResearcher Platform API
 */

error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

startSession();

// ── CSRF verification for all state-changing POST requests ───────────────────
// The frontend must include the token in every POST via:
//   headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content }
//
// Read-only GET actions and the 'config' action are exempt.
// Razorpay webhook (verify-purchase via POST from Razorpay) is exempt — it
// carries its own HMAC signature verified separately.
const CSRF_EXEMPT_ACTIONS = ['verify-purchase'];   // Razorpay server-to-server

if ($method === 'POST' && !in_array($action, CSRF_EXEMPT_ACTIONS, true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid or missing CSRF token.'], 403);
    }
}

// ── Payment rate limiting  ─────────────────────────────────────────
// Uses a lightweight DB table to track attempts per user per action.
// Survives PHP restarts and shared hosting temp-dir cleanup - unlike the
// temp-file approach in api-payment.php which was also not wired here.
//
// Call: apiPaymentRateLimit($db, $userId, 'create-order', 5, 300)
// -> allows 5 attempts per 300 seconds per user per action.
function apiPaymentRateLimit(PDO $db, int $userId, string $action, int $limit, int $windowSecs): void {
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
            // Rate limit: 10 registration attempts per IP per 10 minutes.
            if (!checkRateLimit('register', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 10, 600)) {
                jsonResponse(['error' => 'Too many registration attempts. Please wait 10 minutes.'], 429);
            }

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
            // Rate limit: 5 login attempts per IP per 5 minutes.
            if (!checkRateLimit('login', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 5, 300)) {
                jsonResponse(['error' => 'Too many login attempts. Please wait 5 minutes.'], 429);
            }

            $data     = json_decode(file_get_contents('php://input'), true);
            $email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
            $password = $data['password'] ?? '';

            if (!$email || !$password) {
                jsonResponse(['error' => 'Email and password required'], 400);
            }

            // Account lockout check — runs before DB fetch to prevent enumeration.
            if (isAccountLocked($email)) {
                jsonResponse(['error' => 'Account temporarily locked after repeated failures. Please wait 15 minutes.'], 423);
            }

            $stmt = $db->prepare('SELECT id, email, password_hash, email_verified, role, auth_provider FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                recordFailedLogin($email);
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
                recordFailedLogin($email);
                jsonResponse(['error' => 'Invalid credentials'], 401);
            }

            // Successful login — clear failure history.
            clearFailedLogins($email);

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
            // Rate limit to prevent verification email spam.
            if (!checkRateLimit('resend-verification', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 3, 900)) {
                jsonResponse(['error' => 'Too many requests. Please wait 15 minutes.'], 429);
            }
            jsonResponse(['success' => true, 'message' => 'Please use Google Sign-In — email verification is not available.']);
            break;

        case 'request-password-reset':
            // Rate limit regardless of whether reset is implemented — prevents email enumeration.
            if (!checkRateLimit('password-reset', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 3, 900)) {
                jsonResponse(['success' => true, 'message' => 'If that account exists, a reset link has been sent.']);
            }
            jsonResponse(['success' => true, 'message' => 'Password reset via email is not available. Please use Google Sign-In.']);
            break;

        // ═══════════════════════════════════════════════════════════════
        // PRODUCTS
        // ═══════════════════════════════════════════════════════════════

        case 'products':
            $user = getCurrentUser();

            $stmt     = $db->query('
                SELECT id, title, description, price, currency, preview_image, tags,
                       is_active, is_free_trial, access_badge
                FROM products WHERE is_active = TRUE ORDER BY title
            ');
            $products = $stmt->fetchAll();

            foreach ($products as &$product) {
                $product['tags']      = json_decode($product['tags'] ?? '[]');
                $product['purchased'] = false;
                $product['has_trial']     = (bool) $product['is_free_trial'];
                $product['access_badge']  = $product['access_badge'] ?? 'trial_only';

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

            $stmt = $db->prepare('SELECT id, title, price, currency, is_free_trial, access_badge FROM products WHERE id = ? AND is_active = 1');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) jsonResponse(['error' => 'Product not found'], 404);

            // Block payment when product is trial-only or has no paid upgrade path
            if (!empty($product['is_free_trial']) || ($product['access_badge'] ?? '') === 'trial_only') {
                jsonResponse(['error' => 'This simulation is currently available as a free trial only. Paid access coming soon!'], 503);
            }

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
                $preview     = $data['preview_image'] ?? '';
                $tags        = json_encode($data['tags'] ?? []);
                $isActive    = !empty($data['is_active']);
                $isFreeTrial  = !empty($data['is_free_trial']);
                $accessBadge  = in_array($data['access_badge'] ?? '', ['trial_only','trial_upgrade','paid_only'])
                                ? $data['access_badge'] : 'trial_only';

                if (!$title || !$simFile) jsonResponse(['error' => 'Title and sim_file required'], 400);

                if ($id) {
                    $db->prepare('UPDATE products SET title=?,description=?,price=?,currency=?,sim_file=?,paid_file=?,preview_image=?,tags=?,is_active=?,is_free_trial=?,access_badge=? WHERE id=?')
                       ->execute([$title,$description,$price,$currency,$simFile,$paidFile,$preview,$tags,$isActive,$isFreeTrial,$accessBadge,$id]);
                    jsonResponse(['message' => 'Product updated']);
                } else {
                    $newId = 'sim-' . strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
                    $db->prepare('INSERT INTO products (id,title,description,price,currency,sim_file,preview_image,tags,is_active,is_free_trial,access_badge) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                       ->execute([$newId,$title,$description,$price,$currency,$simFile,$preview,$tags,$isActive,$isFreeTrial,$accessBadge]);
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

            $filename = $user['id'] . '.' . $ext;
            $destPath = $dir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                jsonResponse(['error' => 'Could not save file'], 500);
            }

            $relUrl = 'uploads/avatars/' . $filename;
            $db->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$relUrl, $user['id']]);
            // Append cache-bust timestamp in response only — not stored in DB
            jsonResponse(['success' => true, 'avatar_url' => $relUrl . '?v=' . time()]);
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

        case 'admin-blog-delete-comment':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $d  = json_decode(file_get_contents('php://input'), true);
            $id = (int)($d['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            $upd = $db->prepare("UPDATE comments SET status='hidden' WHERE id=?");
            $upd->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        case 'admin-blog-comments':
            // List all visible blog comments across all posts, paginated, for admin panel.
            requireAdmin();
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $limit  = min(100, max(1, (int)($_GET['limit'] ?? 25)));
            $slug   = trim($_GET['slug'] ?? '');

            if ($slug !== '') {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM comments WHERE source_type='blog' AND source_id=? AND status='visible'"
                );
                $cntStmt->execute([$slug]);
                $total = (int)$cntStmt->fetchColumn();

                $stmt = $db->prepare(
                    "SELECT c.id, c.source_id AS post_slug, c.parent_id, c.body, c.created_at,
                            u.display_name, u.email
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.source_type='blog' AND c.source_id=? AND c.status='visible'
                     ORDER BY c.created_at DESC
                     LIMIT ? OFFSET ?"
                );
                $stmt->execute([$slug, $limit, $offset]);
            } else {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM comments WHERE source_type='blog' AND status='visible'"
                );
                $cntStmt->execute();
                $total = (int)$cntStmt->fetchColumn();

                $stmt = $db->prepare(
                    "SELECT c.id, c.source_id AS post_slug, c.parent_id, c.body, c.created_at,
                            u.display_name, u.email
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.source_type='blog' AND c.status='visible'
                     ORDER BY c.created_at DESC
                     LIMIT ? OFFSET ?"
                );
                $stmt->execute([$limit, $offset]);
            }

            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id']        = (int)$row['id'];
                $row['parent_id'] = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            }
            unset($row);
            jsonResponse(['comments' => $rows, 'total' => $total]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // BLOG LIKES
        // ═══════════════════════════════════════════════════════════════════

        case 'blog-like':
            // Toggle like for authenticated user. Returns new count + liked state.
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $user = requireAuth();
            $d    = json_decode(file_get_contents('php://input'), true);
            $slug = trim($d['slug'] ?? '');
            if (!$slug) jsonResponse(['error' => 'slug required'], 400);

            // Check current state
            $chk  = $db->prepare("SELECT id FROM blog_likes WHERE post_slug=? AND user_id=?");
            $chk->execute([$slug, $user['id']]);
            $liked = (bool)$chk->fetch();

            if ($liked) {
                $db->prepare("DELETE FROM blog_likes WHERE post_slug=? AND user_id=?")->execute([$slug, $user['id']]);
                $liked = false;
            } else {
                $db->prepare("INSERT IGNORE INTO blog_likes (post_slug, user_id) VALUES (?,?)")->execute([$slug, $user['id']]);
                $liked = true;
            }

            $cnt = $db->prepare("SELECT COUNT(*) FROM blog_likes WHERE post_slug=?");
            $cnt->execute([$slug]);
            jsonResponse(['liked' => $liked, 'count' => (int)$cnt->fetchColumn()]);
            break;

        case 'blog-like-count':
            // Public. Returns like count and current user liked state.
            $slug = trim($_GET['slug'] ?? '');
            if (!$slug) jsonResponse(['error' => 'slug required'], 400);

            $cnt = $db->prepare("SELECT COUNT(*) FROM blog_likes WHERE post_slug=?");
            $cnt->execute([$slug]);
            $count = (int)$cnt->fetchColumn();

            $liked = false;
            $user  = getCurrentUser();
            if ($user) {
                $chk = $db->prepare("SELECT id FROM blog_likes WHERE post_slug=? AND user_id=?");
                $chk->execute([$slug, $user['id']]);
                $liked = (bool)$chk->fetch();
            }
            jsonResponse(['count' => $count, 'liked' => $liked]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // UNIFIED COMMENTS  (source_type: 'blog' | 'simulation')
        // ═══════════════════════════════════════════════════════════════════

        case 'blog-comments':
            /*
             * Fetch comments for a blog post.
             * GET params: slug (required), parent_id, offset, limit
             * Returns: { comments: [...], total: N, has_more: bool }
             * Each row includes display_name, avatar_url, reply_count, vote_score, user_vote.
             */
            $slug      = trim($_GET['slug'] ?? '');
            if (!$slug) jsonResponse(['error' => 'slug required'], 400);

            $rawParent = $_GET['parent_id'] ?? null;
            $parentId  = ($rawParent !== null && $rawParent !== '') ? (int)$rawParent : null;
            $offset    = max(0, (int)($_GET['offset'] ?? 0));
            $limit     = min(50, max(1, (int)($_GET['limit'] ?? 10)));


            if ($parentId === null) {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM comments WHERE source_type='blog' AND source_id=? AND parent_id IS NULL AND status='visible'"
                );
                $cntStmt->execute([$slug]);
            } else {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM comments WHERE source_type='blog' AND source_id=? AND parent_id=? AND status='visible'"
                );
                $cntStmt->execute([$slug, $parentId]);
            }
            $total = (int)$cntStmt->fetchColumn();

            $currentUser2 = getCurrentUser();
            $uid2         = $currentUser2 ? (int)$currentUser2['id'] : 0;

            if ($parentId === null) {
                $stmt = $db->prepare(
                    "SELECT c.id, c.parent_id, c.body, c.created_at,
                            u.display_name, u.avatar_url, u.id AS user_id,
                            (SELECT COUNT(*) FROM comments r
                             WHERE r.parent_id=c.id AND r.status='visible') AS reply_count,
                            COALESCE((SELECT SUM(v.vote) FROM comment_votes v
                             WHERE v.comment_id=c.id), 0) AS vote_score,
                            COALESCE((SELECT v2.vote FROM comment_votes v2
                             WHERE v2.comment_id=c.id AND v2.user_id=?), 0) AS user_vote
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.source_type='blog' AND c.source_id=? AND c.parent_id IS NULL AND c.status='visible'
                     ORDER BY c.created_at ASC
                     LIMIT ? OFFSET ?"
                );
                $stmt->execute([$uid2, $slug, $limit, $offset]);
            } else {
                $stmt = $db->prepare(
                    "SELECT c.id, c.parent_id, c.body, c.created_at,
                            u.display_name, u.avatar_url, u.id AS user_id,
                            (SELECT COUNT(*) FROM comments r
                             WHERE r.parent_id=c.id AND r.status='visible') AS reply_count,
                            COALESCE((SELECT SUM(v.vote) FROM comment_votes v
                             WHERE v.comment_id=c.id), 0) AS vote_score,
                            COALESCE((SELECT v2.vote FROM comment_votes v2
                             WHERE v2.comment_id=c.id AND v2.user_id=?), 0) AS user_vote
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.source_type='blog' AND c.source_id=? AND c.parent_id=? AND c.status='visible'
                     ORDER BY c.created_at ASC
                     LIMIT ? OFFSET ?"
                );
                $stmt->execute([$uid2, $slug, $parentId, $limit, $offset]);
            }
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id']          = (int)$row['id'];
                $row['user_id']     = (int)$row['user_id'];
                $row['parent_id']   = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
                $row['reply_count'] = (int)$row['reply_count'];
                $row['vote_score']  = (int)$row['vote_score'];
                $row['user_vote']   = (int)$row['user_vote'];
            }
            unset($row);
            jsonResponse(['comments' => $rows, 'total' => $total, 'has_more' => ($offset + $limit) < $total]);
            break;

        case 'blog-comment-post':
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $user = requireAuth();
            $d    = json_decode(file_get_contents('php://input'), true);
            $slug = trim($d['slug'] ?? '');
            $body = trim($d['body'] ?? '');
            $pid  = isset($d['parent_id']) && $d['parent_id'] !== null ? (int)$d['parent_id'] : null;

            if (!$slug) jsonResponse(['error' => 'slug required'], 400);
            if ($body === '') jsonResponse(['error' => 'Comment body required'], 400);
            if (mb_strlen($body) > 5000) jsonResponse(['error' => 'Comment too long (max 5000 chars)'], 400);


            if ($pid !== null) {
                $pChk = $db->prepare(
                    "SELECT id FROM comments WHERE id=? AND source_type='blog' AND source_id=? AND status='visible'"
                );
                $pChk->execute([$pid, $slug]);
                if (!$pChk->fetch()) jsonResponse(['error' => 'Invalid parent comment'], 400);
            }

            $ins = $db->prepare(
                "INSERT INTO comments (source_type, source_id, user_id, parent_id, body) VALUES ('blog',?,?,?,?)"
            );
            $ins->execute([$slug, $user['id'], $pid, $body]);
            $newId = (int)$db->lastInsertId();

            $row = $db->prepare(
                "SELECT c.id, c.parent_id, c.body, c.created_at,
                        u.display_name, u.avatar_url, u.id AS user_id,
                        0 AS reply_count, 0 AS vote_score, 0 AS user_vote
                 FROM comments c
                 JOIN users u ON u.id = c.user_id
                 WHERE c.id=?"
            );
            $row->execute([$newId]);
            $comment = $row->fetch();
            $comment['id']          = (int)$comment['id'];
            $comment['user_id']     = (int)$comment['user_id'];
            $comment['parent_id']   = $comment['parent_id'] !== null ? (int)$comment['parent_id'] : null;
            $comment['reply_count'] = 0;
            $comment['vote_score']  = 0;
            $comment['user_vote']   = 0;

            jsonResponse(['success' => true, 'comment' => $comment]);
            break;

        case 'blog-comment-vote':
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $user = requireAuth();
            $d    = json_decode(file_get_contents('php://input'), true);
            $cid  = (int)($d['comment_id'] ?? 0);
            $vote = (int)($d['vote'] ?? 0);   // +1, -1, or 0 to remove

            if (!$cid) jsonResponse(['error' => 'comment_id required'], 400);
            if (!in_array($vote, [-1, 0, 1], true)) jsonResponse(['error' => 'vote must be -1, 0 or 1'], 400);


            $chk = $db->prepare("SELECT id FROM comments WHERE id=? AND status='visible'");
            $chk->execute([$cid]);
            if (!$chk->fetch()) jsonResponse(['error' => 'Comment not found'], 404);

            if ($vote === 0) {
                $db->prepare("DELETE FROM comment_votes WHERE comment_id=? AND user_id=?")
                   ->execute([$cid, $user['id']]);
            } else {
                $db->prepare(
                    "INSERT INTO comment_votes (comment_id, user_id, vote) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE vote=VALUES(vote)"
                )->execute([$cid, $user['id'], $vote]);
            }

            $scoreStmt = $db->prepare(
                "SELECT COALESCE(SUM(vote),0) AS score FROM comment_votes WHERE comment_id=?"
            );
            $scoreStmt->execute([$cid]);
            $score = (int)$scoreStmt->fetchColumn();

            jsonResponse(['success' => true, 'vote_score' => $score, 'user_vote' => $vote]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // RATINGS
        // ═══════════════════════════════════════════════════════════════════

        case 'rate-sim':
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
            /*
             * Fetch comments for a simulation.
             * GET params: product_id (required), parent_id, offset, limit
             * Returns: { comments: [...], total: N, has_more: bool }
             */
            $pid       = trim($_GET['product_id'] ?? '');
            if (!$pid) jsonResponse(['error' => 'product_id required'], 400);

            $rawParent = $_GET['parent_id'] ?? null;
            $parentId  = ($rawParent !== null && $rawParent !== '') ? (int)$rawParent : null;
            $offset    = max(0, (int)($_GET['offset'] ?? 0));
            $limit     = min(50, max(1, (int)($_GET['limit'] ?? 10)));


            $currentUserSC = getCurrentUser();
            $uidSC         = $currentUserSC ? (int)$currentUserSC['id'] : 0;

            if ($parentId === null) {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM comments WHERE source_type='simulation' AND source_id=? AND parent_id IS NULL AND status='visible'"
                );
                $cntStmt->execute([$pid]);
            } else {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM comments WHERE source_type='simulation' AND source_id=? AND parent_id=? AND status='visible'"
                );
                $cntStmt->execute([$pid, $parentId]);
            }
            $total = (int)$cntStmt->fetchColumn();

            if ($parentId === null) {
                $stmt = $db->prepare(
                    "SELECT c.id, c.parent_id, c.body, c.created_at,
                            u.display_name, u.avatar_url, u.id AS user_id,
                            (SELECT COUNT(*) FROM comments r
                             WHERE r.parent_id=c.id AND r.status='visible') AS reply_count,
                            COALESCE((SELECT SUM(v.vote) FROM comment_votes v
                             WHERE v.comment_id=c.id), 0) AS vote_score,
                            COALESCE((SELECT v2.vote FROM comment_votes v2
                             WHERE v2.comment_id=c.id AND v2.user_id=?), 0) AS user_vote
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.source_type='simulation' AND c.source_id=? AND c.parent_id IS NULL AND c.status='visible'
                     ORDER BY c.created_at DESC
                     LIMIT ? OFFSET ?"
                );
                $stmt->execute([$uidSC, $pid, $limit, $offset]);
            } else {
                $stmt = $db->prepare(
                    "SELECT c.id, c.parent_id, c.body, c.created_at,
                            u.display_name, u.avatar_url, u.id AS user_id,
                            (SELECT COUNT(*) FROM comments r
                             WHERE r.parent_id=c.id AND r.status='visible') AS reply_count,
                            COALESCE((SELECT SUM(v.vote) FROM comment_votes v
                             WHERE v.comment_id=c.id), 0) AS vote_score,
                            COALESCE((SELECT v2.vote FROM comment_votes v2
                             WHERE v2.comment_id=c.id AND v2.user_id=?), 0) AS user_vote
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.source_type='simulation' AND c.source_id=? AND c.parent_id=? AND c.status='visible'
                     ORDER BY c.created_at ASC
                     LIMIT ? OFFSET ?"
                );
                $stmt->execute([$uidSC, $pid, $parentId, $limit, $offset]);
            }

            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id']          = (int)$row['id'];
                $row['user_id']     = (int)$row['user_id'];
                $row['parent_id']   = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
                $row['reply_count'] = (int)$row['reply_count'];
                $row['vote_score']  = (int)$row['vote_score'];
                $row['user_vote']   = (int)$row['user_vote'];
            }
            unset($row);
            jsonResponse(['comments' => $rows, 'total' => $total, 'has_more' => ($offset + $limit) < $total]);
            break;

        case 'post-comment':
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $user = requireAuth();
            $d    = json_decode(file_get_contents('php://input'), true);
            $pid  = trim($d['product_id'] ?? '');
            $body = trim($d['body'] ?? '');
            $ppid = isset($d['parent_id']) && $d['parent_id'] !== null ? (int)$d['parent_id'] : null;

            if (!$pid)        jsonResponse(['error' => 'product_id required'], 400);
            if ($body === '') jsonResponse(['error' => 'Comment body required'], 400);
            if (mb_strlen($body) > 5000) jsonResponse(['error' => 'Comment too long (max 5000 chars)'], 400);


            // Must have used the sim
            $used = $db->prepare("SELECT id FROM sim_usage WHERE product_id=? AND user_id=? LIMIT 1");
            $used->execute([$pid, $user['id']]);
            if (!$used->fetch()) jsonResponse(['error' => 'Interact with the simulation before commenting'], 403);

            if ($ppid !== null) {
                $pChk = $db->prepare(
                    "SELECT id FROM comments WHERE id=? AND source_type='simulation' AND source_id=? AND status='visible'"
                );
                $pChk->execute([$ppid, $pid]);
                if (!$pChk->fetch()) jsonResponse(['error' => 'Invalid parent comment'], 400);
            }

            $ins = $db->prepare(
                "INSERT INTO comments (source_type, source_id, user_id, parent_id, body) VALUES ('simulation',?,?,?,?)"
            );
            $ins->execute([$pid, $user['id'], $ppid, $body]);
            $newId = (int)$db->lastInsertId();

            $cs = $db->prepare(
                "SELECT c.id, c.parent_id, c.body, c.created_at,
                        u.display_name, u.avatar_url, u.id AS user_id,
                        0 AS reply_count, 0 AS vote_score, 0 AS user_vote
                 FROM comments c
                 JOIN users u ON u.id = c.user_id
                 WHERE c.id=?"
            );
            $cs->execute([$newId]);
            $comment = $cs->fetch();
            $comment['id']          = (int)$comment['id'];
            $comment['user_id']     = (int)$comment['user_id'];
            $comment['parent_id']   = $comment['parent_id'] !== null ? (int)$comment['parent_id'] : null;
            $comment['reply_count'] = 0;
            $comment['vote_score']  = 0;
            $comment['user_vote']   = 0;
            jsonResponse(['success' => true, 'comment' => $comment]);
            break;

        case 'sim-comment-vote':
            // Upsert or remove a vote (+1 up, -1 down) on a simulation comment. Requires auth.
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $user = requireAuth();
            $d    = json_decode(file_get_contents('php://input'), true);
            $cid  = (int)($d['comment_id'] ?? 0);
            $vote = (int)($d['vote'] ?? 0);

            if (!$cid) jsonResponse(['error' => 'comment_id required'], 400);
            if (!in_array($vote, [-1, 0, 1], true)) jsonResponse(['error' => 'vote must be -1, 0 or 1'], 400);


            $chk = $db->prepare("SELECT id FROM comments WHERE id=? AND source_type='simulation' AND status='visible'");
            $chk->execute([$cid]);
            if (!$chk->fetch()) jsonResponse(['error' => 'Comment not found'], 404);

            if ($vote === 0) {
                $db->prepare("DELETE FROM comment_votes WHERE comment_id=? AND user_id=?")
                   ->execute([$cid, $user['id']]);
            } else {
                $db->prepare(
                    "INSERT INTO comment_votes (comment_id, user_id, vote) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE vote=VALUES(vote)"
                )->execute([$cid, $user['id'], $vote]);
            }

            $scoreStmt = $db->prepare(
                "SELECT COALESCE(SUM(vote),0) AS score FROM comment_votes WHERE comment_id=?"
            );
            $scoreStmt->execute([$cid]);
            $score = (int)$scoreStmt->fetchColumn();
            jsonResponse(['success' => true, 'vote_score' => $score, 'user_vote' => $vote]);
            break;

        case 'admin-delete-comment':
            requireAdmin();
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            $d  = json_decode(file_get_contents('php://input'), true);
            $id = (int)($d['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            $db->prepare("UPDATE comments SET status='hidden' WHERE id=?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;
            
        // ═══════════════════════════════════════════════════════════════════
        // USAGE TRACKING
        // ═══════════════════════════════════════════════════════════════════

        case 'track-start':
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