<?php
require_once 'config.php';
require_once 'helpers.php';
startSession();

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if ($token) {
    $user = verifyToken($token, 'verification');
    
    if ($user) {
        // Mark email as verified
        $stmt = $pdo->prepare('
            UPDATE users 
            SET email_verified = 1, 
                verification_token = NULL, 
                verification_token_expires = NULL 
            WHERE id = ?
        ');
        
        if ($stmt->execute([$user['id']])) {
            $success = 'Email verified successfully! You can now log in.';
            
            // Auto-login the user
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            
            // Redirect to home after 3 seconds
            header("refresh:3;url=simulations.php");
        } else {
            $error = 'Verification failed. Please try again.';
        }
    } else {
        $error = 'Invalid or expired verification link. Please request a new one.';
    }
} else {
    $error = 'No verification token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .verify-container {
            background: white;
            padding: 3rem;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 500px;
            text-align: center;
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        h1 { margin-bottom: 1rem; color: #1f2937; }
        p { color: #6b7280; line-height: 1.6; }
        .btn {
            display: inline-block;
            background: #2563eb;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            margin-top: 1.5rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <?php if ($success): ?>
            <div class="icon success">✓</div>
            <h1>Email Verified!</h1>
            <p><?php echo esc($success); ?></p>
            <p>Redirecting to homepage...</p>
            <a href="simulations.php" class="btn">Go to Homepage</a>
        <?php else: ?>
            <div class="icon error">✗</div>
            <h1>Verification Failed</h1>
            <p><?php echo esc($error); ?></p>
            <a href="index.html" class="btn">Back to Homepage</a>
        <?php endif; ?>
    </div>
</body>
</html>