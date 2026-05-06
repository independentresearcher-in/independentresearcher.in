<?php
declare(strict_types=1);

/**
 * Donation API Endpoints
 * Handles research donations via Razorpay
 */

require_once 'config.php';
require_once 'razorpay-handler.php';
require_once 'security-headers.php';

startSession();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// CSRF verification for state-changing POST actions.
// verify-donation carries Razorpay's own HMAC signature — exempt from CSRF.
$donationCsrfExempt = ['verify-donation', 'recent-donors'];
if ($method === 'POST' && !in_array($action, $donationCsrfExempt, true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid or missing CSRF token.'], 403);
    }
}

try {
    switch ($action) {
        
        /**
         * Create donation order
         * POST /api-donation.php?action=create-donation
         */
        case 'create-donation':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            
            if (!PAYMENT_ENABLED) {
                jsonResponse(['error' => 'Donations currently unavailable'], 503);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Rate limit: 10 donation attempts per IP per 10 minutes
            if (!checkRateLimit('create-donation', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 10, 600)) {
                jsonResponse(['error' => 'Too many requests. Please wait 10 minutes.'], 429);
            }

            $amount = floatval($data['amount'] ?? 0);
            $donorName = trim($data['donor_name'] ?? '');
            $donorEmail = trim($data['donor_email'] ?? '');
            $message = trim($data['message'] ?? '');
            $isAnonymous = (bool)($data['is_anonymous'] ?? false);
            $showInDonors = (bool)($data['show_in_donors'] ?? true);
            
            // Validate
            if ($amount < 10) {
                jsonResponse(['error' => 'Minimum donation is ₹10'], 400);
            }
            
            if ($amount > 100000) {
                jsonResponse(['error' => 'Maximum donation is ₹1,00,000. Contact us for larger donations.'], 400);
            }
            
            if (!$donorName || strlen($donorName) < 2) {
                jsonResponse(['error' => 'Please provide your name'], 400);
            }
            
            if (!filter_var($donorEmail, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['error' => 'Invalid email address'], 400);
            }
            
            $db     = getDB();
            $userId = $_SESSION['user_id'] ?? null;

            $amountPaise = intval($amount * 100);
            $orderData = [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => 'donation_' . time(),
                'notes' => [
                    'type' => 'donation',
                    'donor_name' => $donorName,
                    'donor_email' => $donorEmail,
                    'is_anonymous' => $isAnonymous ? 'yes' : 'no'
                ]
            ];
            
            // Build order via Razorpay API directly.
            // We cannot use $razorpay->createOrder() because that writes to the
            // purchases table (for simulation purchases). Donations use their own
            // table. We also cannot call $razorpay->makeApiCall() because it is
            // private. Use a minimal inline cURL call instead.
            $rzpUrl  = 'https://api.razorpay.com/v1/orders';
            $ch      = curl_init($rzpUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($orderData),
                CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $rzpRaw  = curl_exec($ch);
            $rzpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $rzpErr  = curl_error($ch);
            curl_close($ch);

            if ($rzpErr || $rzpCode >= 400) {
                $errDetail = $rzpErr ?: (json_decode($rzpRaw, true)['error']['description'] ?? 'Unknown error');
                error_log("Donation order creation failed ({$rzpCode}): {$errDetail}");
                throw new Exception('Failed to create donation order');
            }
            $response = json_decode($rzpRaw, true);
            
            if (!$response || !isset($response['id'])) {
                throw new Exception('Failed to create donation order');
            }
            
            // Save donation record
            $stmt = $db->prepare("
                INSERT INTO donations (
                    user_id, donor_name, donor_email, amount, currency, 
                    payment_id, payment_method, status, message, 
                    is_anonymous, show_in_donors, created_at
                ) VALUES (?, ?, ?, ?, 'INR', ?, 'razorpay', 'pending', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $donorName,
                $donorEmail,
                $amount,
                $response['id'],
                $message,
                $isAnonymous,
                $showInDonors
            ]);
            
            jsonResponse([
                'success' => true,
                'order' => [
                    'order_id' => $response['id'],
                    'amount' => $amountPaise,
                    'currency' => 'INR',
                    'key' => RAZORPAY_KEY_ID
                ]
            ]);
            break;
        
        /**
         * Verify donation payment
         * POST /api-donation.php?action=verify-donation
         */
        case 'verify-donation':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $orderId = $data['razorpay_order_id'] ?? '';
            $paymentId = $data['razorpay_payment_id'] ?? '';
            $signature = $data['razorpay_signature'] ?? '';
            
            if (!$orderId || !$paymentId || !$signature) {
                jsonResponse(['error' => 'Missing payment details'], 400);
            }
            
            $razorpay = new RazorpayHandler();
            
            // Verify signature
            if (!$razorpay->verifyPayment($orderId, $paymentId, $signature)) {
                jsonResponse(['error' => 'Invalid payment signature'], 401);
            }
            
            $db = getDB();
            
            // Update donation status
            $stmt = $db->prepare("
                UPDATE donations 
                SET status = 'completed', payment_id = ?, updated_at = NOW()
                WHERE payment_id = ? AND status = 'pending'
            ");
            $stmt->execute([$paymentId, $orderId]);
            
            if ($stmt->rowCount() === 0) {
                jsonResponse(['error' => 'Donation not found or already processed'], 400);
            }
            
            // Get donation details
            $stmt = $db->prepare("
                SELECT donor_name, donor_email, amount, is_anonymous
                FROM donations
                WHERE payment_id = ?
            ");
            $stmt->execute([$paymentId]);
            $donation = $stmt->fetch();
            
            // Log success
            error_log(sprintf(
                '[DONATION SUCCESS] Name:%s Email:%s Amount:₹%s Payment:%s',
                $donation['donor_name'],
                $donation['donor_email'],
                $donation['amount'],
                $paymentId
            ));
            
            jsonResponse([
                'success' => true,
                'message' => 'Thank you for supporting independent research!',
                'donation' => [
                    'amount' => $donation['amount'],
                    'donor_name' => $donation['is_anonymous'] ? 'Anonymous' : $donation['donor_name']
                ]
            ]);
            break;
        
        /**
         * Get recent donors
         * GET /api-donation.php?action=recent-donors&limit=10
         */
        case 'recent-donors':
            $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
            
            $db = getDB();
            
            $stmt = $db->prepare("
                SELECT 
                    CASE WHEN is_anonymous = 1 THEN 'Anonymous Donor' ELSE donor_name END as name,
                    amount,
                    created_at
                FROM donations
                WHERE status = 'completed' AND show_in_donors = 1
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $donors = $stmt->fetchAll();
            
            // Get total donated
            $stmt = $db->query("
                SELECT SUM(amount) as total
                FROM donations
                WHERE status = 'completed'
            ");
            $total = $stmt->fetch()['total'] ?? 0;
            
            jsonResponse([
                'success' => true,
                'donors' => $donors,
                'total_donated' => $total
            ]);
            break;
        
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
    
} catch (PDOException $e) {
    error_log('Donation DB error: ' . $e->getMessage());
    jsonResponse(['error' => 'Database error. Please try again later.'], 500);
} catch (Exception $e) {
    error_log('Donation API error: ' . $e->getMessage());
    jsonResponse([
        'error'   => 'Operation failed',
        'message' => DEMO_MODE ? $e->getMessage() : 'Please try again later'
    ], 500);
}
