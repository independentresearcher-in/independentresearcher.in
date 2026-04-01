<?php
/**
 * Simulation API - Server-Side Physics Calculations
 * 
 * WATERMARKS:
 * - Code Author: Pancham Kotian
 * - Website Owner: Akilesh Bhandary
 * - AI-Generated: Claude (Anthropic)
 * 
 * Optimized for Hostinger Base Package:
 * - Aggressive caching
 * - Rate limiting
 * - Minimal database queries
 */

require_once 'config.php';
require_once 'simulation-engine.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get request data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    jsonResponse(['error' => 'Invalid JSON'], 400);
}

$simulationType = $data['type'] ?? '';
$params = $data['params'] ?? [];
$token = $data['token'] ?? '';

// Validate inputs
if (!$simulationType || !is_array($params)) {
    jsonResponse(['error' => 'Invalid request parameters'], 400);
}

// Check authentication
$user = getCurrentUser();
if (!$user) {
    jsonResponse(['error' => 'Authentication required'], 401);
}

// Validate token
$productId = 'sim-' . $simulationType;
if (!validateAccessToken($token, $user['id'], $productId)) {
    jsonResponse(['error' => 'Invalid or expired token'], 401);
}

// Verify purchase (with caching)
$cacheKey = "purchase_{$user['id']}_{$productId}";
$cacheFile = sys_get_temp_dir() . '/' . md5($cacheKey);
$hasPurchase = false;

// Check cache first (valid for 5 minutes)
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
    $hasPurchase = (bool)file_get_contents($cacheFile);
} else {
    // Query database
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id FROM purchases 
        WHERE user_id = ? AND product_id = ? AND status IN ('paid', 'demo')
        LIMIT 1
    ");
    $stmt->execute([$user['id'], $productId]);
    $hasPurchase = (bool)$stmt->fetch();
    
    // Cache result
    file_put_contents($cacheFile, $hasPurchase ? '1' : '0');
}

if (!$hasPurchase) {
    jsonResponse(['error' => 'Purchase required'], 403);
}

// Rate limiting (100 requests per minute per user)
$rateLimitKey = "sim_rate_{$user['id']}";
$rateLimitFile = sys_get_temp_dir() . '/' . md5($rateLimitKey);

if (file_exists($rateLimitFile)) {
    $rateData = json_decode(file_get_contents($rateLimitFile), true);
    $requests = $rateData['count'] ?? 0;
    $resetTime = $rateData['reset'] ?? 0;
    
    if (time() < $resetTime) {
        if ($requests >= 100) {
            jsonResponse([
                'error' => 'Rate limit exceeded',
                'retry_after' => $resetTime - time()
            ], 429);
        }
        $requests++;
    } else {
        $requests = 1;
        $resetTime = time() + 60;
    }
} else {
    $requests = 1;
    $resetTime = time() + 60;
}

file_put_contents($rateLimitFile, json_encode([
    'count' => $requests,
    'reset' => $resetTime
]));

// Calculate simulation
try {
    $startTime = microtime(true);
    
    $result = SimulationEngine::calculate($simulationType, $params);
    
    $calcTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Add watermarks to response
    jsonResponse([
        'success' => true,
        'data' => $result,
        'meta' => [
            'calc_time_ms' => $calcTime,
            'server_time' => time(),
            'watermarks' => [
                'code_author' => 'Pancham Kotian',
                'owner' => 'Akilesh Bhandary',
                'ai_generated' => 'Claude (Anthropic)'
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Simulation error: " . $e->getMessage());
    jsonResponse([
        'error' => 'Calculation failed',
        'message' => DEMO_MODE ? $e->getMessage() : 'Internal error'
    ], 500);
}
