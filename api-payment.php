<?php
/**
 * Handles payment creation and verification
 */

require_once 'config.php';
require_once 'razorpay-handler.php';

header('Content-Type: application/json');

/**
 * Rate limiting for payment endpoints
 */
function checkPaymentRateLimit($userId, $action, $limit = 10, $window = 60) {
    $key = "payment_rate_{$userId}_{$action}";
    $file = sys_get_temp_dir() . '/' . md5($key);
    
    $data = ['count' => 1, 'reset' => time() + $window];
    
    if (file_exists($file)) {
        $existing = json_decode(file_get_contents($file), true);
        
        if ($existing && time() < $existing['reset']) {
            if ($existing['count'] >= $limit) {
                jsonResponse([
                    'error' => 'Too many requests',
                    'message' => 'Please wait before trying again',
                    'retry_after' => $existing['reset'] - time()
                ], 429);
            }
            $data['count'] = $existing['count'] + 1;
            $data['reset'] = $existing['reset'];
        }
    }
    
    file_put_contents($file, json_encode($data));
}

/**
 * Validate product ID format
 */
function validateProductId($productId) {
    if (!preg_match('/^sim-[a-z0-9-]+$/', $productId)) {
        jsonResponse(['error' => 'Invalid product ID format'], 400);
    }
    
    if (strlen($productId) > 100) {
        jsonResponse(['error' => 'Product ID too long'], 400);
    }
    
    return $productId;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $razorpay = new RazorpayHandler();
    
    switch ($action) {
        
        /**
         * Create payment order
         * POST /api-payment.php?action=create-order
         */
        case 'create-order':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            
            // Check if payment is enabled
            if (!PAYMENT_ENABLED) {
                jsonResponse(['error' => 'Payment system is currently disabled'], 503);
            }
            
            // Authenticate user
            $user = requireAuth();
            
            // SECURITY: Rate limit - 5 orders per 5 minutes
            checkPaymentRateLimit($user['id'], 'create-order', 5, 300);
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                jsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $productId = $data['product_id'] ?? '';
            
            if (!$productId) {
                jsonResponse(['error' => 'Product ID required'], 400);
            }
            
            // Validate product ID format
            $productId = validateProductId($productId);
            
            // Get product details
            $db = getDB();
            $stmt = $db->prepare("SELECT id, title, price FROM products WHERE id = ? AND is_active = TRUE");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if (!$product) {
                jsonResponse(['error' => 'Product not found'], 404);
            }
            
            // SECURITY: Verify price is positive
            if ($product['price'] <= 0) {
                jsonResponse(['error' => 'Invalid product price'], 400);
            }
            
            // Check if already purchased
            $stmt = $db->prepare("
                SELECT id FROM purchases 
                WHERE user_id = ? AND product_id = ? AND status = 'paid'
            ");
            $stmt->execute([$user['id'], $productId]);
            
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'Already purchased'], 400);
            }
            
            // Create Razorpay order
            $orderData = $razorpay->createOrder(
                $product['price'],
                $productId,
                $user['id'],
                $user['email']
            );
            
            jsonResponse([
                'success' => true,
                'order' => $orderData,
                'product' => [
                    'id' => $product['id'],
                    'title' => $product['title'],
                    'price' => $product['price']
                ]
            ]);
            break;
        
        /**
         * Verify payment
         * POST /api-payment.php?action=verify-payment
         */
        case 'verify-payment':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            
            $user = requireAuth();
            
            // SECURITY: Rate limit - 10 verifications per minute
            checkPaymentRateLimit($user['id'], 'verify-payment', 10, 60);
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                jsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $orderId = $data['razorpay_order_id'] ?? '';
            $paymentId = $data['razorpay_payment_id'] ?? '';
            $signature = $data['razorpay_signature'] ?? '';
            
            if (!$orderId || !$paymentId || !$signature) {
                jsonResponse(['error' => 'Missing payment details'], 400);
            }
            
            // Validate ID formats
            if (!preg_match('/^order_[A-Za-z0-9]+$/', $orderId)) {
                jsonResponse(['error' => 'Invalid order ID format'], 400);
            }
            
            if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) {
                jsonResponse(['error' => 'Invalid payment ID format'], 400);
            }
            
            // Verify and complete payment
            $result = $razorpay->completePayment($orderId, $paymentId, $signature);

            jsonResponse([
                'success'    => true,
                'message'    => 'Payment successful',
                'product_id' => $result['product_id'],
            ]);
            break;
        
        /**
         * Handle payment failure
         * POST /api-payment.php?action=payment-failed
         */
        case 'payment-failed':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }

            $user = requireAuth();

            $data             = json_decode(file_get_contents('php://input'), true);
            $orderId          = trim($data['order_id']          ?? '');
            $errorCode        = $data['error_code']        ?? 'unknown';
            $errorDescription = $data['error_description'] ?? 'Payment failed';

            if ($orderId) {
                $db  = getDB();
                $chk = $db->prepare("SELECT id FROM purchases WHERE payment_id = ? AND user_id = ? AND status = 'pending'");
                $chk->execute([$orderId, $user['id']]);
                if ($chk->fetch()) {
                    $razorpay->handleFailure($orderId, $errorCode, $errorDescription);
                }
                // Silently ignore unknown/foreign order IDs — avoids leaking existence
            }

            jsonResponse([
                'success' => false,
                'message' => $errorDescription
            ]);
            break;
        
        /**
         * Webhook handler
         * POST /api-payment.php?action=webhook
         */
        case 'webhook':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            
            $payload = file_get_contents('php://input');
            $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
            
            if (!$signature) {
                jsonResponse(['error' => 'Missing signature'], 400);
            }
            
            $result = $razorpay->handleWebhook($payload, $signature);
            
            jsonResponse($result);
            break;
        
        /**
         * Get payment history for user
         * GET /api-payment.php?action=payment-history
         */
        case 'payment-history':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            
            $user = requireAuth();
            
            $db = getDB();
            $stmt = $db->prepare("
                SELECT 
                    p.id,
                    p.product_id,
                    pr.title as product_title,
                    p.amount,
                    p.currency,
                    p.status,
                    p.payment_id,
                    p.created_at,
                    p.updated_at
                FROM purchases p
                JOIN products pr ON p.product_id = pr.id
                WHERE p.user_id = ?
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$user['id']]);
            $payments = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'payments' => $payments
            ]);
            break;
        
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
    
} catch (Exception $e) {
    // Log full error details server-side
    error_log('Payment error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    
    // Only expose safe error messages to client
    $safeMessage = 'Please try again later';
    $safeErrors = [
        'Payment system is currently disabled',
        'Product not found',
        'Already purchased',
        'Invalid payment signature',
        'Payment not captured',
        'Amount mismatch detected',
        'Missing payment details',
        'Invalid product ID format',
        'Too many requests'
    ];
    
    if (DEMO_MODE && in_array($e->getMessage(), $safeErrors)) {
        $safeMessage = $e->getMessage();
    }
    
    jsonResponse([
        'error' => 'Payment processing failed',
        'message' => $safeMessage
    ], 500);
}
