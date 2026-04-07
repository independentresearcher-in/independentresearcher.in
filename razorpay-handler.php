<?php
declare(strict_types=1);

/**
 * Handles payment creation, verification, and webhook processing
 * Optimized for Hostinger with proper error handling
 */

require_once 'config.php';

class RazorpayHandler {
    
    private string $keyId;
    private string $keySecret;
    
    public function __construct() {
        $this->keyId = RAZORPAY_KEY_ID;
        $this->keySecret = RAZORPAY_KEY_SECRET;
        
        if (empty($this->keyId) || empty($this->keySecret)) {
            throw new Exception('Razorpay credentials not configured');
        }
    }
    
    /**
     * Create Razorpay order
     */
    public function createOrder(float $amount, string $productId, int $userId, string $userEmail): array {
        $db = getDB();
        
        // Amount in paise (INR cents)
        $amountPaise = intval($amount * 100);
        
        // Create order via Razorpay API
        $orderData = [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => 'order_' . time() . '_' . $userId,
            'notes' => [
                'product_id' => $productId,
                'user_id' => $userId,
                'user_email' => $userEmail
            ]
        ];
        
        $response = $this->makeApiCall('/v1/orders', $orderData);
        
        if (!$response || !isset($response['id'])) {
            throw new Exception('Failed to create Razorpay order');
        }
        
        // ON DUPLICATE KEY UPDATE handles the case where a 'trial' row already exists
        // for this user+product (inserted at signup). The unique key uq_user_product
        // would otherwise throw error 1062 on a plain INSERT.
        // We overwrite trial/failed rows with the new pending order; paid/demo rows
        // are left unchanged (the pre-check above already blocked those cases).
        $stmt = $db->prepare("
            INSERT INTO purchases
                (user_id, product_id, payment_id, amount, currency, status, payment_method, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, 'INR', 'pending', 'razorpay', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                payment_id     = VALUES(payment_id),
                amount         = VALUES(amount),
                status         = 'pending',
                payment_method = 'razorpay',
                updated_at     = NOW()
        ");
        $stmt->execute([$userId, $productId, $response['id'], $amount]);
        // lastInsertId() returns 0 on UPDATE path; fetch the real id
        $purchaseId = (int) $db->lastInsertId();
        if ($purchaseId === 0) {
            $idStmt = $db->prepare("SELECT id FROM purchases WHERE user_id = ? AND product_id = ?");
            $idStmt->execute([$userId, $productId]);
            $purchaseId = (int) ($idStmt->fetchColumn() ?: 0);
        }
        
        // Log transaction
        $stmt = $db->prepare("
            INSERT INTO payment_transactions (purchase_id, gateway, order_id, status, response_data, created_at)
            VALUES (?, 'razorpay', ?, 'created', ?, NOW())
        ");
        $stmt->execute([$purchaseId, $response['id'], json_encode($response)]);
        
        return [
            'order_id' => $response['id'],
            'amount' => $amountPaise,
            'currency' => 'INR',
            'key' => $this->keyId,
            'purchase_id' => $purchaseId
        ];
    }
    
    /**
     * Verify payment signature
     */
    public function verifyPayment(string $orderId, string $paymentId, string $signature): bool {
        $expectedSignature = hash_hmac(
            'sha256',
            $orderId . '|' . $paymentId,
            $this->keySecret
        );
        
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Complete payment after verification
     */
    public function completePayment(string $orderId, string $paymentId, string $signature): array {
        $db = getDB();
        
        // Start transaction for atomicity
        $db->beginTransaction();
        
        try {
            // Use SELECT FOR UPDATE to prevent race conditions
            $stmt = $db->prepare("
                SELECT id, user_id, product_id, amount 
                FROM purchases 
                WHERE payment_id = ? AND status = 'pending'
                FOR UPDATE
            ");
            $stmt->execute([$orderId]);
            $purchase = $stmt->fetch();
            
            if (!$purchase) {
                $db->rollBack();
                throw new Exception('Purchase not found or already completed');
            }
            
            // Verify signature
            if (!$this->verifyPayment($orderId, $paymentId, $signature)) {
                $db->rollBack();
                throw new Exception('Invalid payment signature');
            }
            
            // Fetch payment details from Razorpay
            $paymentDetails = $this->getPaymentDetails($paymentId);
            
            if (!$paymentDetails) {
                $db->rollBack();
                throw new Exception('Failed to fetch payment details from Razorpay');
            }
            
            // Verify payment status
            if ($paymentDetails['status'] !== 'captured') {
                $db->rollBack();
                throw new Exception('Payment not captured. Status: ' . $paymentDetails['status']);
            }
            
            // Verify amount matches (convert from paise to rupees)
            $paidAmount = $paymentDetails['amount'] / 100;
            $expectedAmount = floatval($purchase['amount']);
            
            if (abs($paidAmount - $expectedAmount) > 0.01) {
                $db->rollBack();
                error_log(sprintf(
                    '[PAYMENT FRAUD ATTEMPT] Order:%s Expected:%s Paid:%s',
                    $orderId,
                    $expectedAmount,
                    $paidAmount
                ));
                throw new Exception('Amount mismatch detected');
            }
            
            // Verify order ID matches
            if ($paymentDetails['order_id'] !== $orderId) {
                $db->rollBack();
                throw new Exception('Order ID mismatch');
            }
            
            // Update purchase status (atomic update)
            $stmt = $db->prepare("
                UPDATE purchases 
                SET status = 'paid', payment_id = ?, updated_at = NOW()
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$paymentId, $purchase['id']]);
            
            // Verify update was successful
            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception('Failed to update purchase status - possible race condition');
            }
            
            // Log successful transaction
            $stmt = $db->prepare("
                UPDATE payment_transactions 
                SET payment_id = ?, signature = ?, status = 'success', response_data = ?
                WHERE order_id = ?
            ");
            $stmt->execute([
                $paymentId,
                $signature,
                json_encode($paymentDetails),
                $orderId
            ]);
            
            // Commit transaction
            $db->commit();
            
            // Log security event
            error_log(sprintf(
                '[PAYMENT SUCCESS] User:%d Product:%s Amount:%s Payment:%s Verified:✓',
                $purchase['user_id'],
                $purchase['product_id'],
                $purchase['amount'],
                $paymentId
            ));
            
            return [
                'success' => true,
                'purchase_id' => $purchase['id'],
                'product_id' => $purchase['product_id']
            ];
            
        } catch (Exception $e) {
            // Rollback on any error
            $db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Handle payment failure
     * Sanitized error inputs
     */
    public function handleFailure(string $orderId, string $errorCode, string $errorDescription): void {
        $db = getDB();
        
        // SECURITY FIX: Sanitize error inputs
        $errorCode = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($errorCode, 0, 50));
        $errorDescription = substr($errorDescription, 0, 500); // Limit length
        
        // Validate JSON encoding
        $errorData = json_encode([
            'error_code' => $errorCode, 
            'error_description' => $errorDescription,
            'timestamp' => time()
        ]);
        
        if ($errorData === false) {
            $errorData = json_encode([
                'error_code' => 'unknown',
                'error_description' => 'Invalid error data',
                'timestamp' => time()
            ]);
        }
        
        $stmt = $db->prepare("
            UPDATE purchases 
            SET status = 'failed', updated_at = NOW()
            WHERE payment_id = ?
        ");
        $stmt->execute([$orderId]);
        
        $stmt = $db->prepare("
            UPDATE payment_transactions 
            SET status = 'failed', response_data = ?
            WHERE order_id = ?
        ");
        $stmt->execute([$errorData, $orderId]);
        
        error_log(sprintf(
            '[PAYMENT FAILED] Order:%s Error:%s - %s',
            $orderId,
            $errorCode,
            $errorDescription
        ));
    }
    
    /**
     * Get payment details from Razorpay
     */
    private function getPaymentDetails(string $paymentId): ?array {
        return $this->makeApiCall('/v1/payments/' . $paymentId, null, 'GET');
    }
    
    /**
     * Handle Razorpay webhook
     * Protected against replay attacks
     */
    public function handleWebhook(string $payload, string $signature): array {
        $db = getDB();
        
        // Verify webhook signature
        $expectedSignature = hash_hmac('sha256', $payload, RAZORPAY_WEBHOOK_SECRET);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid webhook signature');
        }
        
        $data = json_decode($payload, true);
        
        if (!$data) {
            throw new Exception('Invalid webhook payload - JSON decode failed');
        }
        
        $event = $data['event'] ?? null;
        $webhookId = $data['id'] ?? null;
        $paymentEntity = $data['payload']['payment']['entity'] ?? null;
        
        if (!$webhookId) {
            throw new Exception('Missing webhook ID');
        }

        // Check for replay attack — must happen before processing
        $stmt = $db->prepare("SELECT id FROM webhook_log WHERE webhook_id = ?");
        $stmt->execute([$webhookId]);

        if ($stmt->fetch()) {
            error_log("[WEBHOOK REPLAY DETECTED] Webhook ID: $webhookId - Ignoring duplicate");
            return ['status' => 'already_processed', 'webhook_id' => $webhookId];
        }

        // Validate paymentEntity BEFORE writing the log row.
        if (!$paymentEntity && in_array($event, ['payment.captured', 'payment.failed'])) {
            throw new Exception('Invalid webhook payload - missing payment entity');
        }

        // Log webhook for replay protection (only after validation passes)
        $stmt = $db->prepare("
            INSERT INTO webhook_log (webhook_id, event_type, payload, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$webhookId, $event, $payload]);
        
        // Process webhook based on event type
        switch ($event) {
            case 'payment.captured':
                return $this->handlePaymentCaptured($paymentEntity);
                
            case 'payment.failed':
                return $this->handlePaymentFailed($paymentEntity);
                
            default:
                // Log other events
                error_log("[WEBHOOK] Unhandled event: $event");
                return ['status' => 'ignored', 'event' => $event];
        }
    }
    
    /**
     * Handle payment captured webhook
     */
    private function handlePaymentCaptured(array $payment): array {
        $db = getDB();
        
        $orderId = $payment['order_id'];
        $paymentId = $payment['id'];
        
        $stmt = $db->prepare("
            UPDATE purchases 
            SET status = 'paid', payment_id = ?, updated_at = NOW()
            WHERE payment_id = ? AND status IN ('pending', 'created')
        ");
        $stmt->execute([$paymentId, $orderId]);
        
        return ['status' => 'processed'];
    }
    
    /**
     * Handle payment failed webhook
     */
    private function handlePaymentFailed(array $payment): array {
        $db = getDB();
        
        $orderId = $payment['order_id'];
        
        $stmt = $db->prepare("
            UPDATE purchases 
            SET status = 'failed', updated_at = NOW()
            WHERE payment_id = ? AND status IN ('pending', 'created')
        ");
        $stmt->execute([$orderId]);
        
        return ['status' => 'processed'];
    }
    
    /**
     * Make API call to Razorpay
     */
    private function makeApiCall(string $endpoint, ?array $data = null, string $method = 'POST'): ?array {
        $url = 'https://api.razorpay.com' . $endpoint;
        
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,  true);
        curl_setopt($ch, CURLOPT_USERPWD,         $this->keyId . ':' . $this->keySecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER,      ['Content-Type: application/json']);
        // Security: enforce SSL verification and hard timeouts
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,  true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  2);
        curl_setopt($ch, CURLOPT_TIMEOUT,         15);   // total request timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,  5);    // connection timeout
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Razorpay API error: ' . $error);
        }
        
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error']['description'] ?? 'Unknown error';
            throw new Exception('Razorpay API error: ' . $errorMsg);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Refund payment (for admin use)
     */
    public function refundPayment(string $paymentId, ?float $amount = null): array {
        $refundData = ['payment_id' => $paymentId];
        
        if ($amount !== null) {
            $refundData['amount'] = intval($amount * 100); // Convert to paise
        }
        
        $response = $this->makeApiCall('/v1/refunds', $refundData);
        
        if (!$response || !isset($response['id'])) {
            throw new Exception('Failed to process refund');
        }
        
        // Update purchase status
        $db = getDB();
        $stmt = $db->prepare("
            UPDATE purchases 
            SET status = 'refunded', updated_at = NOW()
            WHERE payment_id = ?
        ");
        $stmt->execute([$paymentId]);
        
        return $response;
    }
}
