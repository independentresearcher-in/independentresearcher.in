<?php
/**
 * view-simulation.php
 * Serves the simulation iframe. 
 *
 * Access logic (in priority order):
 *   1. paid  → serve simulations/paid/<paid_file>
 *   2. demo  → serve simulations/paid/<paid_file>  (demo = full access)
 *   3. trial → serve simulations/trial/<sim_file>
 *
**/

require_once 'config.php';

$productId = trim($_GET['id'] ?? '');
if (!$productId) { http_response_code(400); die('Product ID required.'); }

$currentUser = getCurrentUser();  // null for guests — that's fine
$db          = getDB();

// For logged-in users: check if they have paid/demo access
$userPurchase = null;
if ($currentUser) {
    $accessCheck = $db->prepare("
        SELECT status
        FROM purchases
        WHERE user_id = ? AND product_id = ? AND status IN ('paid','trial','demo')
        ORDER BY FIELD(status,'paid','demo','trial')
        LIMIT 1
    ");
    $accessCheck->execute([$currentUser['id'], $productId]);
    $userPurchase = $accessCheck->fetch();
}

// Guests and users without a purchase row always get trial access
// (paid/demo access is checked below when deciding which file to serve)
// No redirect - anonymous users are welcome to view the trial simulation

$hasPaidFileColumn = false;
try {
    $colCheck = $db->prepare("SHOW COLUMNS FROM products LIKE 'paid_file'");
    $colCheck->execute();
    $hasPaidFileColumn = (bool) $colCheck->fetch();
} catch (PDOException $e) {
    error_log("view-simulation.php: SHOW COLUMNS failed — {$e->getMessage()}");
}

$selectCols = $hasPaidFileColumn
    ? 'title, description, sim_file, paid_file, price'
    : 'title, description, sim_file, NULL AS paid_file, price';

$productStmt = $db->prepare("SELECT {$selectCols} FROM products WHERE id = ?");
$productStmt->execute([$productId]);
$product = $productStmt->fetch();

if (!$product || !$product['sim_file']) {
    error_log("view-simulation.php: product {$productId} not found or missing sim_file");
    http_response_code(404);
    die('Simulation not found.');
}

// ── Access-level -> file decision ─────────────────────────────────────────────
// paid/demo only available to authenticated users with a matching purchase row
$hasPaidAccess = $userPurchase && in_array($userPurchase['status'], ['paid', 'demo'], true);
$servePaidFile = $hasPaidAccess && !empty($product['paid_file']);

if ($servePaidFile) {
    $simFile   = basename($product['paid_file']);
    $simFolder = 'paid';
} else {
    $simFile   = basename($product['sim_file']);
    $simFolder = 'trial';
}

// Security: allow only expected filename patterns
if (!preg_match('/^sim-[\w-]+(?:_paid)?\.html$/', $simFile)) {
    error_log("view-simulation.php: rejected filename '{$simFile}' for product {$productId}");
    http_response_code(403);
    die('Invalid simulation file.');
}

// Path resolution: subfolder first, then legacy root fallback
$simPath = __DIR__ . "/simulations/{$simFolder}/{$simFile}";
if (!file_exists($simPath)) {
    // Legacy: files stored directly in simulations/ without subfolder
    $simPath = __DIR__ . "/simulations/{$simFile}";
}
if (!file_exists($simPath)) {
    error_log("view-simulation.php: file not found — tried simulations/{$simFolder}/{$simFile} and simulations/{$simFile}");
    http_response_code(404);
    die('Simulation file not found.');
}

// User display info (guests get placeholder values)
$userId      = $currentUser ? (int) $currentUser['id'] : 0;
$displayName = $currentUser ? esc($currentUser['display_name'] ?? $currentUser['email']) : 'Guest';
$userInitial = $currentUser ? strtoupper(mb_substr($currentUser['display_name'] ?? $currentUser['email'], 0, 1)) : 'G';
$avatarUrl   = $currentUser ? esc($currentUser['avatar_url'] ?? '') : '';
$isLoggedIn  = (bool) $currentUser;

// Unique session token for usage tracking
$sessionToken = 'sim_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4));

$iframeSrc = esc("simulations/{$simFolder}/{$simFile}?theme=" . (isset($_COOKIE['ir_theme']) && $_COOKIE['ir_theme'] === 'light' ? 'light' : 'dark'));
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo GA_TAG_ID; ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?php echo GA_TAG_ID; ?>');
</script>

<title><?= esc($product['title']) ?> — <?= esc(SITE_NAME) ?></title>
<script>
// Apply stored theme instantly to prevent flash
(function () {
    var stored = localStorage.getItem('ir_theme');
    if (!stored) { stored = (new Date().getHours() >= 6 && new Date().getHours() < 18) ? 'light' : 'dark'; }
    document.documentElement.setAttribute('data-theme', stored);
})();
</script>
<link rel="stylesheet" href="assets/css/theme.css">
<style>
:root {
  --bg:        #ffffff;
  --bg2:       #f8f9fa;
  --bg3:       #f1f3f5;
  --text:      #191c1f;
  --muted:     #525558;
  --border:    #dee2e6;
  --gold:      #d4a017;
  --gold-dark: #b8860b;
}
[data-theme="dark"] {
  --bg:        #1a1d23;
  --bg2:       #22262e;
  --bg3:       #2a2f38;
  --text:      #f8f9fa;
  --muted:     #adb5bd;
  --border:    #3a3f4b;
  --gold:      #f0c040;
  --gold-dark: #d4a017;
}
html,body { height:100%; font-family:'Segoe UI',system-ui,sans-serif; background:var(--bg); color:var(--text); }
.topbar { position: sticky; top: 0; z-index: 50; height: var(--nav-h); background: rgba(26, 29, 35, 0.96);  backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 1.25rem; gap: 1rem; }
[data-theme="light"] .topbar { background: rgba(255, 255, 255, 0.96); }
.topbar-back { color:var(--gold); text-decoration:none; font-weight:600; font-size:.9rem; display:flex; align-items:center; gap:.4rem; flex-shrink:0; }
.topbar-back:hover { color:var(--gold-dark); }
.topbar-title { font-weight:700; font-size:1rem; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.topbar-btn { background:var(--gold); color:#000; border:none; border-radius:6px; padding:.35rem .85rem; font-size:.8rem; font-weight:700; cursor:pointer; flex-shrink:0; }
.topbar-btn:hover { background:var(--gold-dark); }
.upgrade-bar { background:#1a1d23; border-bottom:1px solid var(--border); text-align:center; padding:.5rem 1rem; font-size:.82rem; color:var(--muted); }
.upgrade-bar a,.upgrade-bar button.upgrade-link { color:var(--gold); font-weight:700; text-decoration:none; background:none; border:none; cursor:pointer; font-size:.82rem; }
.upgrade-bar a:hover,.upgrade-bar button.upgrade-link:hover { text-decoration:underline; }
.paid-bar { background:#0f4c2a; border-bottom:1px solid #1a6b3c; text-align:center; padding:.4rem; font-size:.8rem; font-weight:700; color:#4ade80; letter-spacing:.5px; }
#sim-frame { width:100%; height:calc(100vh - var(--nav-h) - 2px); border:none; display:block; }
.sim-panel { max-width:860px; margin:0 auto; padding:2rem 1.25rem; }
.stars-section { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:1.25rem 1.5rem; margin-bottom:1.5rem; display:flex; align-items:center; flex-wrap:wrap; gap:1.25rem; }
.star-row { display:flex; gap:.25rem; }
.star { font-size:1.5rem; cursor:pointer; transition:transform .15s; color:#3a3f4b; user-select:none; }
.star:hover,.star.lit { color:var(--gold); transform:scale(1.1); }
.stars-avg { font-size:.9rem; color:var(--muted); }
.comment-form { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:1rem 1.25rem; margin-bottom:1.5rem; }
.comment-form textarea { width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:inherit; font-size:.9rem; padding:.65rem; min-height:80px; resize:vertical; }
.comment-form textarea:focus { outline:none; border-color:var(--gold); }
.comment-footer { display:flex; justify-content:space-between; align-items:center; margin-top:.5rem; font-size:.8rem; color:var(--muted); }
.btn-post { background:var(--gold); color:#0a0a09; border:none; border-radius:6px; padding:.5rem 1.25rem; font-weight:700; font-size:.85rem; cursor:pointer; }
.btn-post:hover { background:var(--gold-dark); }
.btn-post:disabled { opacity:.45; cursor:not-allowed; }
.comment-card { display:flex; gap:.85rem; padding:1rem 0; border-bottom:1px solid var(--border); }
.comment-card:last-child { border-bottom:none; }
.avatar-sm { width:36px; height:36px; border-radius:50%; background:var(--gold); color:#0a0a09; display:flex; align-items:center; justify-content:center; font-size:.85rem; font-weight:700; flex-shrink:0; overflow:hidden; }
.avatar-sm img { width:100%; height:100%; object-fit:cover; }
.comment-meta { font-size:.78rem; color:var(--muted); margin-bottom:.3rem; }
.comment-body { font-size:.9rem; line-height:1.55; white-space:pre-wrap; }
.load-more { display:block; width:100%; padding:.75rem; margin-top:1rem; background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); color:var(--text); font-size:.88rem; cursor:pointer; text-align:center; }
.load-more:hover { border-color:var(--gold); color:var(--gold); }
</style>
</head>
<body>

<div class="topbar">
    <a class="topbar-back" href="simulations.php">← Back</a>
    <span class="topbar-title"><?= esc($product['title']) ?></span>
    <?php if (!$servePaidFile): ?>
    <button class="topbar-btn" onclick="initiateUpgrade()">🔓 Upgrade</button>
    <?php endif; ?>
    <button class="topbar-btn" onclick="document.getElementById('sim-frame').requestFullscreen()">⛶ Full</button>
</div>

<?php if ($servePaidFile): ?>
<div class="paid-bar">✓ FULL VERSION — All features unlocked</div>
<?php else: ?>
<div class="upgrade-bar">
    🆓 FREE TRIAL &nbsp;—&nbsp;
    <button class="upgrade-link" onclick="initiateUpgrade()">Upgrade to full version</button>
    for advanced controls &amp; unlimited sessions
    <?php if (PAYMENT_ENABLED): ?>
     &nbsp;·&nbsp; <strong>₹<?= esc((string)($product['price'] ?? '')) ?></strong>
    <?php endif; ?>
</div>
<?php endif; ?>

<iframe id="sim-frame"
        src="<?= $iframeSrc ?>"
        title="<?= esc($product['title']) ?>"
        allowfullscreen>
</iframe>

<div class="sim-panel">

    <!-- Star Rating -->
    <div class="stars-section" id="stars-section">
        <div>
            <div style="font-size:.85rem;color:var(--muted);margin-bottom:.4rem">Rate this simulation</div>
            <div class="star-row" id="star-row">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="star" data-v="<?= $i ?>"
                      onclick="submitRating(<?= $i ?>)"
                      onmouseover="highlightStars(<?= $i ?>)"
                      onmouseout="resetStarHighlight()">★</span>
                <?php endfor; ?>
            </div>
        </div>
        <div class="stars-avg" id="stars-avg">Loading…</div>
    </div>

    <!-- Comments -->
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
            <h3 style="font-size:1.1rem">Comments <span id="comment-count" style="color:var(--muted);font-size:.85rem"></span></h3>
        </div>

        <?php if ($isLoggedIn): ?>
        <div class="comment-form">
            <div style="display:flex;gap:.75rem;align-items:flex-start">
                <div class="avatar-sm">
                    <?php if ($avatarUrl): ?>
                    <img src="<?= $avatarUrl ?>" alt="">
                    <?php else: ?>
                    <?= $userInitial ?>
                    <?php endif; ?>
                </div>
                <div style="flex:1">
                    <textarea id="comment-input"
                              placeholder="Share your thoughts about this simulation… (interact for 5 seconds first)"
                              maxlength="1000"></textarea>
                    <div class="comment-footer">
                        <span id="comment-char-count">0 / 1000</span>
                        <button class="btn-post" id="comment-submit" onclick="postComment()" disabled>Post Comment</button>
                    </div>
                </div>
            </div>
            <div id="comment-msg" style="margin-top:.5rem;font-size:.82rem"></div>
        </div>
        <?php else: ?>
        <div class="comment-form" style="text-align:center;padding:1.25rem">
            <p style="color:var(--muted);margin-bottom:.75rem">Login to rate and leave a comment</p>
            <a href="simulations.php?auth=1&return=<?= urlencode(SITE_URL . '/view-simulation.php?id=' . $productId) ?>"
               class="topbar-btn" style="display:inline-block;text-decoration:none;padding:.5rem 1.25rem">
               Login / Sign Up
            </a>
        </div>
        <?php endif; ?>

        <div id="comments-list"></div>
        <button class="load-more" id="load-more-btn" style="display:none" onclick="loadComments(true)">Load more comments</button>
    </div>
</div>

<?php if (PAYMENT_ENABLED): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php endif; ?>

<script>
const PRODUCT_ID      = <?= json_encode($productId) ?>;
const PRODUCT_NAME    = <?= json_encode($product['title']) ?>;
const USER_ID         = <?= $userId ?>;
const IS_PAID         = <?= $servePaidFile ? 'true' : 'false' ?>;
const SESSION_ID      = <?= json_encode($sessionToken) ?>;
const PAYMENT_ENABLED = <?= PAYMENT_ENABLED ? 'true' : 'false' ?>;
const IS_LOGGED_IN    = <?= $isLoggedIn ? 'true' : 'false' ?>;

// ── Usage tracking ────────────────────────────────────────────────────────────
const trackingStart = Date.now();

function sendUsageBeacon(action, seconds) {
    const payload = { product_id: PRODUCT_ID, session_id: SESSION_ID };
    if (action === 'end') payload.seconds = seconds;
    navigator.sendBeacon('api.php?action=track-' + action,
        new Blob([JSON.stringify(payload)], { type: 'application/json' }));
}

sendUsageBeacon('start', 0);

window.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        sendUsageBeacon('end', Math.floor((Date.now() - trackingStart) / 1000));
    }
});
window.addEventListener('pagehide', () => {
    sendUsageBeacon('end', Math.floor((Date.now() - trackingStart) / 1000));
});

// ── Star ratings ──────────────────────────────────────────────────────────────
let userRating       = 0;
let ratingEnabled    = false;
let interactionTimer = null;

interactionTimer = setTimeout(() => {
    ratingEnabled = true;
    refreshCommentButtonState();
}, 5000);

async function loadRatings() {
    const res  = await fetch('api.php?action=sim-ratings&product_id=' + encodeURIComponent(PRODUCT_ID));
    const data = await res.json();
    const avgEl = document.getElementById('stars-avg');
    if (avgEl) {
        avgEl.textContent = data.count > 0
            ? `${data.avg_rating} ★ (${data.count} rating${data.count !== 1 ? 's' : ''})`
            : 'No ratings yet — be the first!';
    }
    if (data.my_rating) { userRating = data.my_rating; renderStars(userRating); }
}

function renderStars(count) {
    document.querySelectorAll('.star').forEach(s => s.classList.toggle('lit', +s.dataset.v <= count));
}
function highlightStars(count) { renderStars(count); }
function resetStarHighlight()  { renderStars(userRating); }

async function submitRating(value) {
    if (!IS_LOGGED_IN) {
        window.location.href = 'simulations.php?auth=1&return=' + encodeURIComponent(window.location.href);
        return;
    }
    if (!ratingEnabled) { alert('Interact with the simulation for a few seconds before rating.'); return; }
    const res  = await fetch('api.php?action=rate-sim', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ product_id: PRODUCT_ID, rating: value })
    });
    const data = await res.json();
    if (data.success) {
        userRating = value;
        renderStars(userRating);
        const avgEl = document.getElementById('stars-avg');
        if (avgEl) avgEl.textContent = `${data.avg_rating} ★ (${data.count} rating${data.count !== 1 ? 's' : ''})`;
    } else {
        alert(data.error || 'Could not save rating.');
    }
}

loadRatings();

// ── Comments ──────────────────────────────────────────────────────────────────
let commentPage       = 1;
let commentTotal      = 0;
let isLoadingComments = false;
let commentingEnabled = false;

setTimeout(() => { commentingEnabled = true; refreshCommentButtonState(); }, 5000);

const commentInput = document.getElementById('comment-input');
if (commentInput) {
    commentInput.addEventListener('input', () => {
        const len = commentInput.value.length;
        document.getElementById('comment-char-count').textContent = len + ' / 1000';
        refreshCommentButtonState();
    });
}

function refreshCommentButtonState() {
    const btn = document.getElementById('comment-submit');
    if (!btn) return;
    const hasText = (commentInput?.value || '').trim().length >= 5;
    btn.disabled = !commentingEnabled || !hasText;
}

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildCommentCard(comment) {
    const name   = comment.display_name || comment.email.split('@')[0];
    const avatar = comment.avatar_url
        ? `<img src="${escapeHtml(comment.avatar_url)}" alt="">`
        : name.charAt(0).toUpperCase();
    const date = new Date(comment.created_at).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
    return `<div class="comment-card">
        <div class="avatar-sm">${avatar}</div>
        <div style="flex:1">
            <div class="comment-meta"><strong>${escapeHtml(name)}</strong> · ${date}</div>
            <div class="comment-body">${escapeHtml(comment.body)}</div>
        </div>
    </div>`;
}

async function loadComments(append = false) {
    if (isLoadingComments) return;
    isLoadingComments = true;
    if (!append) { commentPage = 1; document.getElementById('comments-list').innerHTML = ''; }

    const res  = await fetch(`api.php?action=sim-comments&product_id=${encodeURIComponent(PRODUCT_ID)}&page=${commentPage}`);
    const data = await res.json();
    isLoadingComments  = false;
    commentTotal       = data.total ?? 0;

    const countEl = document.getElementById('comment-count');
    if (countEl) countEl.textContent = `(${commentTotal})`;

    const list = document.getElementById('comments-list');
    if (data.comments && data.comments.length === 0 && !append) {
        list.innerHTML = '<p style="color:var(--muted);font-size:.88rem;padding:.5rem 0">No comments yet — be the first!</p>';
    } else if (data.comments) {
        list.insertAdjacentHTML('beforeend', data.comments.map(buildCommentCard).join(''));
    }

    const loadMoreBtn = document.getElementById('load-more-btn');
    if (data.has_more) { commentPage++; loadMoreBtn.style.display = 'block'; }
    else loadMoreBtn.style.display = 'none';
}

async function postComment() {
    const body      = (commentInput?.value || '').trim();
    const submitBtn = document.getElementById('comment-submit');
    const msgEl     = document.getElementById('comment-msg');
    if (!body || body.length < 5) return;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Posting…';

    const res  = await fetch('api.php?action=post-comment', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ product_id: PRODUCT_ID, body })
    });
    const data = await res.json();
    submitBtn.textContent = 'Post Comment';

    if (data.success) {
        commentInput.value = '';
        document.getElementById('comment-char-count').textContent = '0 / 1000';
        msgEl.textContent = '✓ Comment posted!';
        msgEl.style.color = 'var(--green)';
        document.getElementById('comments-list').insertAdjacentHTML('afterbegin', buildCommentCard(data.comment));
        commentTotal++;
        const countEl = document.getElementById('comment-count');
        if (countEl) countEl.textContent = `(${commentTotal})`;
        setTimeout(() => { msgEl.textContent = ''; }, 3000);
    } else {
        msgEl.textContent  = data.error || 'Failed to post.';
        msgEl.style.color  = 'var(--red)';
    }
    refreshCommentButtonState();
}

window.addEventListener('scroll', () => {
    const loadMoreBtn = document.getElementById('load-more-btn');
    if (loadMoreBtn && loadMoreBtn.style.display !== 'none') {
        if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 300) {
            loadComments(true);
        }
    }
});

loadComments();

// ── Purchase / Upgrade ────────────────────────────────────────────────────────
async function initiateUpgrade() {
    if (!IS_LOGGED_IN) {
        // Guest: show login modal, then resume purchase after login
        showAuthModalThenUpgrade();
        return;
    }
    if (!PAYMENT_ENABLED) {
        alert('Paid upgrade coming soon! You currently have free trial access to all simulations.');
        return;
    }
    await purchaseSimulation(PRODUCT_ID);
}

// Show login modal inside the simulation page (opens simulations.php auth in new tab)
// Since view-simulation.php is a standalone page, we redirect to simulations.php
// with a query param so it auto-opens the auth modal and redirects back here after login.
function showAuthModalThenUpgrade() {
    const returnUrl = encodeURIComponent(window.location.href);
    window.location.href = 'simulations.php?auth=1&return=' + returnUrl;
}

async function purchaseSimulation(productId) {
    const loadingEl    = document.querySelector('.upgrade-bar');
    const originalHtml = loadingEl ? loadingEl.innerHTML : '';
    if (loadingEl) loadingEl.innerHTML = '⏳ Creating order…';

    try {
        const orderRes  = await fetch('api.php?action=create-purchase-order', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ productId })
        });
        const orderData = await orderRes.json();

        if (!orderRes.ok) {
            if (loadingEl) loadingEl.innerHTML = originalHtml;
            alert(orderData.error || 'Could not create order.');
            return;
        }

        const options = {
            key:         orderData.order.key,
            amount:      orderData.order.amount,
            currency:    orderData.order.currency,
            name:        <?= json_encode(SITE_NAME) ?>,
            description: 'Full version: ' + PRODUCT_NAME,
            order_id:    orderData.order.order_id,
            handler: async function (paymentResponse) {
                if (loadingEl) loadingEl.innerHTML = '⏳ Verifying payment…';
                await verifySimulationPayment(paymentResponse, productId);
            },
            theme:  { color: '#f0c040' },
            modal:  { ondismiss: () => { if (loadingEl) loadingEl.innerHTML = originalHtml; } }
        };

        const rzp = new Razorpay(options);
        rzp.open();

    } catch (err) {
        if (loadingEl) loadingEl.innerHTML = originalHtml;
        alert('Network error. Please try again.');
    }
}

async function verifySimulationPayment(paymentResponse, productId) {
    try {
        const res  = await fetch('api.php?action=verify-purchase', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(paymentResponse)
        });
        const data = await res.json();

        if (res.ok && data.success) {
            // ── Google Ads Conversion Tracking ──────────────────────────────
            if (typeof gtag !== 'undefined') {
                gtag('event', 'conversion', {
                    'send_to': '<?= esc(GA_CONVERSION_ID) ?>',  // GA conversion ID — defined in ENV
                    'transaction_id': data.payment_id,    // Razorpay pay_XXXX — unique per payment
                    'new_customer':   data.is_new_customer // true on first ever purchase
                });
            }
            alert(data.message || 'Payment successful! Loading full version…');
            // Hard reload — PHP re-evaluates access level and serves paid file
            window.location.reload();
        } else {
            alert((data.error || 'Verification failed.') +
                  '\n\nIf payment was deducted, please check My Library or contact support.');
        }
    } catch (err) {
        alert('Network error during verification. If payment was deducted, check My Library or contact support.');
    }
}
</script>
<script>
(function () {
  var IFRAME_ID = 'sim-frame';
  var CHANNEL   = 'ir_theme';

  function getTheme() {
    var stored = null;
    try { stored = localStorage.getItem('ir_theme'); } catch(e) {}
    if (!stored) { var h = new Date().getHours(); stored = (h >= 6 && h < 18) ? 'light' : 'dark'; }
    return stored;
  }

  function pushThemeToFrame(theme) {
    var frame = document.getElementById(IFRAME_ID);
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage({ type: 'ir-theme', theme: theme }, '*');
  }

  /* Push theme on iframe load — plus a 300ms retry to catch slow inits */
  var frame = document.getElementById(IFRAME_ID);
  if (frame) {
    frame.addEventListener('load', function () {
      var t = getTheme();
      pushThemeToFrame(t);
      setTimeout(function() { pushThemeToFrame(t); }, 300);
    });
  }

  /* Live toggle via BroadcastChannel */
  if (typeof BroadcastChannel !== 'undefined') {
    var bc = new BroadcastChannel(CHANNEL);
    bc.onmessage = function (e) {
      if (e.data && (e.data === 'light' || e.data === 'dark')) {
        document.documentElement.setAttribute('data-theme', e.data);
        pushThemeToFrame(e.data);
      }
    };
  }
})();
</script>
</body>
</html>