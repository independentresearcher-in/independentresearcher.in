<?php
require_once 'config.php';
require_once 'helpers.php';
startSession();

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$validToken = false;

// Verify token on page load
if ($token) {
    $user = verifyToken($token, 'reset');
    if ($user) {
        $validToken = true;
    } else {
        $error = 'Invalid or expired reset link.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('
            UPDATE users 
            SET password = ?, 
                reset_token = NULL, 
                reset_token_expires = NULL 
            WHERE id = ?
        ');
        
        if ($stmt->execute([$hashedPassword, $user['id']])) {
            $success = 'Password reset successfully! You can now log in.';
            header("refresh:3;url=simulations.php");
        } else {
            $error = 'Failed to reset password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #ef4444 0%, #f59e0b 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .reset-container {
            background: white;
            padding: 3rem;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 450px;
            width: 100%;
        }
        h1 { margin-bottom: 1.5rem; color: #1f2937; text-align: center; }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.5rem; color: #374151; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
        }
        input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .btn {
            width: 100%;
            background: #ef4444;
            color: white;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:hover { background: #dc2626; }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="reset-container">
        <h1>Reset Password</h1>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo esc($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo esc($success); ?></div>
        <?php elseif ($validToken): ?>
            <form method="POST">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" required minlength="6" placeholder="At least 6 characters">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required minlength="6" placeholder="Re-enter password">
                </div>
                <button type="submit" class="btn">Reset Password</button>
            </form>
        <?php else: ?>
            <p style="text-align: center; color: #6b7280;">
                <a href="index.html" style="color: #2563eb;">Return to homepage</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>