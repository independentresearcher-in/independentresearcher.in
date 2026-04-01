<?php
/**
 * OPTIONAL SECURITY ENHANCEMENTS
 * 
 * Additional security features you can implement
 * Copy functions to config.php as needed
 */

/**
 * Rate Limiting Function
 * Prevents brute force attacks on login/register
 * 
 * Usage in api.php before login/register:
 * if (!checkRateLimit('login', $_SERVER['REMOTE_ADDR'], 5, 300)) {
 *     jsonResponse(['error' => 'Too many attempts. Try again in 5 minutes.'], 429);
 * }
 */
function checkRateLimit($action, $identifier, $maxAttempts = 5, $windowSeconds = 300) {
    $db = getDB();
    
    // Clean old attempts
    $stmt = $db->prepare("
        DELETE FROM rate_limits 
        WHERE action = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$action, $windowSeconds]);
    
    // Count recent attempts
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM rate_limits 
        WHERE action = ? AND identifier = ?
    ");
    $stmt->execute([$action, $identifier]);
    $result = $stmt->fetch();
    
    if ($result['count'] >= $maxAttempts) {
        return false;
    }
    
    // Log this attempt
    $stmt = $db->prepare("
        INSERT INTO rate_limits (action, identifier) VALUES (?, ?)
    ");
    $stmt->execute([$action, $identifier]);
    
    return true;
}

/**
 * Database table for rate limiting
 * Run this SQL to create the table:
 
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_identifier (action, identifier),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

 */

/**
 * Account Lockout Function
 * Temporarily locks account after failed login attempts
 * 
 * Usage in api.php after failed login:
 * recordFailedLogin($email);
 * if (isAccountLocked($email)) {
 *     jsonResponse(['error' => 'Account locked. Try again in 15 minutes.'], 423);
 * }
 */
function recordFailedLogin($email) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO failed_logins (email, attempt_time) 
        VALUES (?, NOW())
    ");
    $stmt->execute([$email]);
}

function isAccountLocked($email, $maxAttempts = 5, $lockoutMinutes = 15) {
    $db = getDB();
    
    // Clean old attempts
    $stmt = $db->prepare("
        DELETE FROM failed_logins 
        WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$lockoutMinutes]);
    
    // Count recent failures
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM failed_logins 
        WHERE email = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$email, $lockoutMinutes]);
    $result = $stmt->fetch();
    
    return $result['count'] >= $maxAttempts;
}

function clearFailedLogins($email) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM failed_logins WHERE email = ?");
    $stmt->execute([$email]);
}

/**
 * Database table for account lockout
 * Run this SQL to create the table:
 
CREATE TABLE IF NOT EXISTS failed_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

 */

/**
 * CSRF Token Implementation (Enhanced)
 * More secure CSRF protection with token rotation
 */
function generateSecureCSRFToken() {
    startSession();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    return $_SESSION['csrf_token'];
}

function verifySecureCSRFToken($token, $maxAge = 3600) {
    startSession();
    
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Check token age (prevent replay attacks)
    if (time() - $_SESSION['csrf_token_time'] > $maxAge) {
        return false;
    }
    
    // Verify token
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Password Strength Validator
 * Enforce strong passwords
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'strength' => calculatePasswordStrength($password)
    ];
}

function calculatePasswordStrength($password) {
    $strength = 0;
    
    if (strlen($password) >= 8) $strength += 1;
    if (strlen($password) >= 12) $strength += 1;
    if (preg_match('/[A-Z]/', $password)) $strength += 1;
    if (preg_match('/[a-z]/', $password)) $strength += 1;
    if (preg_match('/[0-9]/', $password)) $strength += 1;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $strength += 1;
    
    if ($strength <= 2) return 'weak';
    if ($strength <= 4) return 'medium';
    return 'strong';
}

/**
 * Security Event Logger
 * Log security-relevant events
 */
function logSecurityEvent($event_type, $description, $user_id = null, $ip_address = null) {
    $db = getDB();
    
    if ($ip_address === null) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    $stmt = $db->prepare("
        INSERT INTO security_log (event_type, description, user_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $event_type,
        $description,
        $user_id,
        $ip_address,
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
}

/**
 * Database table for security logging
 * Run this SQL to create the table:
 
CREATE TABLE IF NOT EXISTS security_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    description TEXT,
    user_id INT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_user_id (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

 */

/**
 * Enhanced Input Validation
 */
function validateProductData($data) {
    $errors = [];
    
    // Title validation
    if (empty($data['title']) || strlen($data['title']) < 3) {
        $errors[] = 'Title must be at least 3 characters';
    }
    if (strlen($data['title']) > 255) {
        $errors[] = 'Title too long';
    }
    
    // Price validation
    $price = floatval($data['price'] ?? 0);
    if ($price <= 0) {
        $errors[] = 'Price must be greater than 0';
    }
    if ($price > 999999) {
        $errors[] = 'Price too high';
    }
    
    // Sim file validation
    $simFile = $data['sim_file'] ?? '';
    if (!preg_match('/^sim-[\w-]+\.html$/', $simFile)) {
        $errors[] = 'Invalid simulation filename format';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * IP Blacklist Check
 * Block known malicious IPs
 */
function isIPBlacklisted($ip = null) {
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id FROM ip_blacklist 
        WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$ip]);
    
    return $stmt->fetch() !== false;
}

function addToBlacklist($ip, $reason, $durationHours = null) {
    $db = getDB();
    
    $expires = $durationHours ? 
        "DATE_ADD(NOW(), INTERVAL $durationHours HOUR)" : 
        "NULL";
    
    $stmt = $db->prepare("
        INSERT INTO ip_blacklist (ip_address, reason, expires_at)
        VALUES (?, ?, $expires)
        ON DUPLICATE KEY UPDATE reason = ?, expires_at = $expires
    ");
    $stmt->execute([$ip, $reason, $reason]);
}

/**
 * Database table for IP blacklist
 * Run this SQL to create the table:
 
CREATE TABLE IF NOT EXISTS ip_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) UNIQUE NOT NULL,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    INDEX idx_ip (ip_address),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

 */

/**
 * Usage Examples in api.php:
 */

/*
// Example 1: Add rate limiting to login
case 'login':
    // Check rate limit (5 attempts per 5 minutes)
    if (!checkRateLimit('login', $_SERVER['REMOTE_ADDR'], 5, 300)) {
        jsonResponse(['error' => 'Too many login attempts. Try again in 5 minutes.'], 429);
    }
    
    // ... existing login code ...
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedLogin($email);
        logSecurityEvent('failed_login', "Failed login for $email", null, $_SERVER['REMOTE_ADDR']);
        jsonResponse(['error' => 'Invalid credentials'], 401);
    }
    
    // Check if account is locked
    if (isAccountLocked($email)) {
        jsonResponse(['error' => 'Account temporarily locked. Try again in 15 minutes.'], 423);
    }
    
    // Clear failed logins on successful login
    clearFailedLogins($email);
    logSecurityEvent('successful_login', "User logged in: $email", $user['id']);
    
    // ... rest of login code ...
    break;

// Example 2: Add password strength validation to registration
case 'register':
    $validation = validatePasswordStrength($password);
    if (!$validation['valid']) {
        jsonResponse(['error' => implode(', ', $validation['errors'])], 400);
    }
    
    // ... rest of registration code ...
    break;

// Example 3: Add IP blacklist check at the start of api.php
if (isIPBlacklisted()) {
    http_response_code(403);
    die('Access denied');
}

// Example 4: Enhanced product validation
case 'admin-products':
    if ($method === 'POST') {
        $validation = validateProductData($data);
        if (!$validation['valid']) {
            jsonResponse(['error' => implode(', ', $validation['errors'])], 400);
        }
        // ... rest of code ...
    }
    break;
*/

?>
