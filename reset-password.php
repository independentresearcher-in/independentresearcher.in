<?php
/**
 * reset-password.php — Password reset handler
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
startSession();

$token   = trim($_GET['token'] ?? '');
$success = '';
$error   = '';
$showForm = false;

if (!$token) {
    $error = 'No reset token provided.';
} else {
    $user = verifyToken($token, 'reset');
    if (!$user) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $showForm = true;
    }
}

if ($showForm && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection — verify the token submitted with the form
    $csrfToken = $_POST['_csrf'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        $error    = 'Invalid form submission. Please try again.';
        $showForm = true;
    } else {
        $newPassword     = $_POST['password']         ?? '';
        $confirmPassword = $_POST['password_confirm'] ?? '';

        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            $error    = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
            $showForm = true;
        } elseif ($newPassword !== $confirmPassword) {
            $error    = 'Passwords do not match.';
            $showForm = true;
        } else {
            $db = getDB();
            $db->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?')
               ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
            clearToken($user['id'], 'reset');
            $success  = 'Password updated successfully. You can now sign in.';
            $showForm = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo GA_TAG_ID; ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?php echo GA_TAG_ID; ?>');
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Password — <?= esc(SITE_NAME) ?></title>
<style>
body { font-family:'Segoe UI',system-ui,sans-serif; background:#0d1117; color:#e6edf3; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
.card { background:#161b22; border:1px solid #30363d; border-radius:12px; padding:2.5rem 2rem; max-width:420px; width:100%; }
.card h1 { font-size:1.4rem; margin-bottom:1.5rem; }
.form-group { margin-bottom:1rem; }
label { display:block; font-size:.85rem; margin-bottom:.35rem; color:#8b949e; }
input[type=password] { width:100%; background:#21262d; border:1px solid #30363d; border-radius:6px; color:#e6edf3; padding:.65rem .9rem; font-size:.95rem; }
input[type=password]:focus { outline:none; border-color:#f0c040; }
.btn { display:block; width:100%; background:#f0c040; color:#000; border:none; border-radius:8px; padding:.75rem; font-size:1rem; font-weight:700; cursor:pointer; margin-top:1.25rem; }
.btn:hover { background:#b8860b; }
.msg { padding:.75rem 1rem; border-radius:6px; margin-bottom:1rem; font-size:.9rem; }
.msg.success { background:rgba(63,185,80,.15); color:#3fb950; border:1px solid #3fb950; }
.msg.error   { background:rgba(248,113,113,.1); color:#f87171; border:1px solid #f87171; }
a { color:#f0c040; }
</style>
</head>
<body>
<div class="card">
    <h1>Reset Password</h1>

    <?php if ($success): ?>
    <div class="msg success"><?= esc($success) ?></div>
    <p style="text-align:center"><a href="simulations.php">Sign in</a></p>

    <?php elseif ($error && !$showForm): ?>
    <div class="msg error"><?= esc($error) ?></div>
    <p style="text-align:center"><a href="simulations.php">Back to site</a></p>

    <?php elseif ($showForm): ?>
    <?php if ($error): ?><div class="msg error"><?= esc($error) ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="_csrf" value="<?= esc(generateCSRFToken()) ?>">
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
        </div>
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="password_confirm" required minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="Repeat password">
        </div>
        <button type="submit" class="btn">Set New Password</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>