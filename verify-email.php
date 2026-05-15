<?php
/**
 * verify-email.php — Email verification handler
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
startSession();

$token   = trim($_GET['token'] ?? '');
$success = '';
$error   = '';

if ($token) {
    $user = verifyToken($token, 'verification');

    if ($user) {
        $db = getDB();
        $db->prepare('UPDATE users SET email_verified = 1, verification_token = NULL, verification_token_expires = NULL WHERE id = ?')
           ->execute([$user['id']]);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_email'] = $user['email'];

        // Grant trial access now that email is verified
        $db->prepare("
            INSERT IGNORE INTO purchases (user_id, product_id, status, payment_method, access_type, created_at)
            SELECT ?, id, 'trial', 'free', 'trial', NOW() FROM products WHERE is_free_trial = TRUE AND is_active = TRUE
        ")->execute([$user['id']]);

        clearToken($user['id'], 'verification');
        $success = 'Your email is verified. Redirecting to simulations…';
        header('Refresh: 2; url=' . SITE_URL . '/');
    } else {
        $error = 'This verification link is invalid or has expired. Please request a new one from the login screen.';
    }
} else {
    $error = 'No verification token found.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/analytics.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Email Verification — <?= esc(SITE_NAME) ?></title>
<style>
  body { font-family:'Segoe UI',system-ui,sans-serif; background:#0d1117; color:#e6edf3; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
  .card { background:#161b22; border:1px solid #30363d; border-radius:12px; padding:3rem 2.5rem; max-width:480px; text-align:center; }
  .icon { font-size:3.5rem; margin-bottom:1rem; }
  h1 { margin:0 0 1rem; font-size:1.5rem; }
  p { color:#8b949e; line-height:1.6; }
  a { display:inline-block; background:#f0c040; color:#000; padding:.65rem 1.5rem; border-radius:8px; text-decoration:none; font-weight:700; margin-top:1.5rem; }
</style>
</head>
<body>
<div class="card">
  <?php if ($success): ?>
    <div class="icon">✅</div>
    <h1>Email Verified!</h1>
    <p><?= esc($success) ?></p>
    <a href="/">Go to Simulations</a>
  <?php else: ?>
    <div class="icon">❌</div>
    <h1>Verification Failed</h1>
    <p><?= esc($error) ?></p>
    <a href="/">Back to Site</a>
  <?php endif; ?>
</div>
</body>
</html>