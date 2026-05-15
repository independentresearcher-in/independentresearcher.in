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
require_once 'security-headers.php';
startSession();

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
if (!preg_match('/^sim-[\w-]+(?:_paid)?\.(html|php)$/', $simFile)) {
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

$csrfToken = generateCSRFToken();

$iframeSrc = esc("simulations/{$simFolder}/{$simFile}?theme=" . (isset($_COOKIE['ir_theme']) && $_COOKIE['ir_theme'] === 'light' ? 'light' : 'dark'));
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= esc($product['title']) ?> — <?= esc(SITE_NAME) ?></title>
<meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= esc($csrfToken) ?>">
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
.comment-card { padding:1rem 0; border-bottom:1px solid var(--border); }
.comment-card:last-child { border-bottom:none; }
.avatar-sm { width:36px; height:36px; border-radius:50%; background:var(--gold); color:#0a0a09; display:flex; align-items:center; justify-content:center; font-size:.85rem; font-weight:700; flex-shrink:0; overflow:hidden; }
.avatar-sm img { width:100%; height:100%; object-fit:cover; }
.comment-meta { font-size:.78rem; color:var(--muted); margin-bottom:.3rem; }
.comment-body { font-size:.9rem; line-height:1.55; white-space:pre-wrap; }
.vote-btn { background:none; border:none; color:var(--muted); font-size:.8rem; cursor:pointer; padding:.15rem .35rem; border-radius:4px; transition:color .15s; }
.vote-btn:hover { color:var(--text); }
.vote-btn.active-up   { color:var(--gold); font-weight:700; }
.vote-btn.active-down { color:#f87171;     font-weight:700; }
.load-more { display:block; width:100%; padding:.75rem; margin-top:1rem; background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); color:var(--text); font-size:.88rem; cursor:pointer; text-align:center; }
.load-more:hover { border-color:var(--gold); color:var(--gold); }
/* ── Auth modal ── */
.vs-modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.6); display:flex; align-items:center; justify-content:center; z-index:1000; opacity:0; pointer-events:none; transition:opacity .2s; padding:1rem; }
.vs-modal-bg.active { opacity:1; pointer-events:all; }
.vs-modal { background:var(--bg2); border:1px solid var(--border); border-radius:12px; padding:2rem; width:100%; max-width:420px; position:relative; }
.vs-modal-close { position:absolute; top:.75rem; right:.75rem; background:none; border:none; color:var(--muted); font-size:1.4rem; cursor:pointer; line-height:1; }
.vs-modal-close:hover { color:var(--text); }
.vs-modal h2 { text-align:center; margin:0 0 1.75rem; font-size:1.5rem; }
.vs-auth-tabs { display:flex; border-bottom:2px solid var(--border); margin-bottom:1.75rem; }
.vs-auth-tab { flex:1; padding:.85rem; background:none; border:none; color:var(--muted); font-size:.95rem; font-weight:600; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .2s; }
.vs-auth-tab.active { color:var(--text); border-bottom-color:var(--gold); }
.vs-form-group { margin-bottom:1rem; }
.vs-form-group label { display:block; font-size:.85rem; color:var(--muted); margin-bottom:.35rem; }
.vs-form-group input { width:100%; padding:.65rem .75rem; background:var(--bg3); border:1px solid var(--border); border-radius:6px; color:var(--text); font-size:.95rem; box-sizing:border-box; }
.vs-form-group input:focus { outline:none; border-color:var(--gold); }
.vs-btn-full { width:100%; padding:.9rem; background:var(--gold); color:#000; border:none; border-radius:8px; font-weight:700; font-size:.95rem; cursor:pointer; }
.vs-btn-full:hover { background:var(--gold-dark); }
.vs-divider { display:flex; align-items:center; gap:.75rem; margin:1.25rem 0; color:var(--muted); font-size:.85rem; }
.vs-divider::before,.vs-divider::after { content:''; flex:1; height:1px; background:var(--border); }
.vs-google-btn { width:100%; padding:.7rem; background:#fff; color:#444; border:1px solid #ddd; border-radius:8px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:.65rem; font-size:.9rem; }
.vs-google-btn:hover { background:#f5f5f5; }
.vs-alert { padding:.6rem .85rem; border-radius:6px; font-size:.85rem; margin-bottom:1rem; }
.vs-alert.error { background:rgba(220,53,69,.12); color:#f87171; border:1px solid rgba(220,53,69,.25); }
.vs-alert.success { background:rgba(34,197,94,.12); color:#4ade80; border:1px solid rgba(34,197,94,.25); }
.vs-forgot { text-align:right; margin-bottom:1rem; }
.vs-forgot a { color:var(--gold); font-size:.85rem; text-decoration:none; }
</style>

<?php require_once __DIR__ . '/includes/analytics.php'; ?>
<script>
window.apiFetch = function(url, opts) {
  opts = opts || {};
  opts.headers = Object.assign({
    'Content-Type': 'application/json',
    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
  }, opts.headers || {});
  return fetch(url, opts);
};
</script>
</head>
<body>

<div class="topbar">
    <a class="topbar-back" href="/">← Back</a>
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
            <button class="topbar-btn" style="padding:.5rem 1.25rem" onclick="openAuthModal()">
               Login / Sign Up
            </button>
        </div>
        <?php endif; ?>

        <div id="comments-list"></div>
        <button class="load-more" id="load-more-btn" style="display:none" onclick="loadComments(true)">Load more comments</button>
    </div>
</div>

<!-- Razorpay loaded on demand inside purchaseSimulation() below -->

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
        window.location.href = '/?auth=1&return=' + encodeURIComponent(window.location.href);
        return;
    }
    if (!ratingEnabled) { alert('Interact with the simulation for a few seconds before rating.'); return; }
    const res  = await apiFetch('api.php?action=rate-sim', {
        method: 'POST',
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

// ── Comment state ─────────────────────────────────────────────────────────────
let commentOffset   = 0;
const COMMENTS_PER_PAGE = 10;
let commentTotal    = 0;
let isLoadingComments = false;

function buildAvatarHtml(c, size) {
    const name = c.display_name || (c.email ? c.email.split('@')[0] : '?');
    if (c.avatar_url) return `<img src="${escapeHtml(c.avatar_url)}" alt="" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover">`;
    return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:var(--gold);color:#000;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:${Math.round(size*0.42)}px;flex-shrink:0">${escapeHtml(name.charAt(0).toUpperCase())}</div>`;
}

function buildCommentCard(c, depth) {
    depth = depth || 0;
    const name    = c.display_name || (c.email ? c.email.split('@')[0] : 'User');
    const date    = new Date(c.created_at).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
    const score   = c.vote_score  || 0;
    const uv      = c.user_vote   || 0;
    const replies = c.reply_count || 0;
    const indent  = depth > 0 ? 'margin-left:2rem;border-left:2px solid var(--border);padding-left:1rem;' : '';
    const upCls   = uv ===  1 ? 'vote-btn active-up'   : 'vote-btn';
    const dnCls   = uv === -1 ? 'vote-btn active-down' : 'vote-btn';

    return `<div class="comment-card" id="comment-${c.id}" style="${indent}">
        <div style="display:flex;gap:.65rem;align-items:flex-start">
            ${buildAvatarHtml(c, 34)}
            <div style="flex:1;min-width:0">
                <div class="comment-meta">
                    <strong>${escapeHtml(name)}</strong>
                    <span style="color:var(--muted);font-size:.8rem"> · ${date}</span>
                </div>
                <div class="comment-body" style="margin:.35rem 0 .5rem">${escapeHtml(c.body)}</div>
                <div style="display:flex;align-items:center;gap:.85rem;font-size:.82rem">
                    <button class="${upCls}" onclick="simVote(${c.id}, ${uv===1 ? 0 : 1})" title="Upvote">
                        &#9650; <span id="score-${c.id}">${score}</span>
                    </button>
                    <button class="${dnCls}" onclick="simVote(${c.id}, ${uv===-1 ? 0 : -1})" title="Downvote">
                        &#9660;
                    </button>
                    ${IS_LOGGED_IN && depth === 0 ? `<button class="vote-btn" onclick="toggleReplyForm(${c.id})">Reply</button>` : ''}
                    ${replies > 0 ? `<button class="vote-btn" id="replies-toggle-${c.id}" onclick="toggleReplies(${c.id}, ${replies})">&#9654; ${replies} ${replies===1?'reply':'replies'}</button>` : ''}
                </div>
                <div id="reply-form-${c.id}" style="display:none;margin-top:.65rem">
                    <textarea id="reply-input-${c.id}" placeholder="Write a reply…" maxlength="5000"
                              style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:.5rem .65rem;color:var(--text);font-size:.88rem;resize:vertical;min-height:60px;box-sizing:border-box"></textarea>
                    <div style="text-align:right;margin-top:.35rem">
                        <button class="btn-post" style="font-size:.82rem;padding:.3rem .8rem" onclick="postReply(${c.id})">Post Reply</button>
                    </div>
                </div>
                <div id="replies-${c.id}" style="margin-top:.5rem"></div>
            </div>
        </div>
    </div>`;
}

async function simVote(commentId, vote) {
    if (!IS_LOGGED_IN) { openAuthModal(); return; }
    const res  = await apiFetch('api.php?action=sim-comment-vote', {
        method: 'POST',
        body: JSON.stringify({ comment_id: commentId, vote })
    });
    const data = await res.json();
    if (!data.success) return;
    // Update score display
    const scoreEl = document.getElementById('score-' + commentId);
    if (scoreEl) scoreEl.textContent = data.vote_score;
    // Update button active states
    const card = document.getElementById('comment-' + commentId);
    if (!card) return;
    const btns = card.querySelectorAll('.vote-btn');
    btns[0].className = data.user_vote ===  1 ? 'vote-btn active-up'   : 'vote-btn';
    btns[1].className = data.user_vote === -1 ? 'vote-btn active-down' : 'vote-btn';
}

function toggleReplyForm(commentId) {
    const form = document.getElementById('reply-form-' + commentId);
    if (!form) return;
    const open = form.style.display !== 'none';
    form.style.display = open ? 'none' : '';
    if (!open) document.getElementById('reply-input-' + commentId)?.focus();
}

async function toggleReplies(commentId, count) {
    const container = document.getElementById('replies-' + commentId);
    const toggleBtn = document.getElementById('replies-toggle-' + commentId);
    if (!container || !toggleBtn) return;
    if (container.dataset.loaded === '1') {
        const open = container.style.display !== 'none';
        container.style.display = open ? 'none' : '';
        toggleBtn.innerHTML = (open ? '&#9654; ' : '&#9660; ') + count + (count===1?' reply':' replies');
        return;
    }
    const res  = await fetch(`api.php?action=sim-comments&product_id=${encodeURIComponent(PRODUCT_ID)}&parent_id=${commentId}&offset=0&limit=50`);
    const data = await res.json();
    if (data.comments) {
        container.innerHTML = data.comments.map(c => buildCommentCard(c, 1)).join('');
        container.dataset.loaded = '1';
        toggleBtn.innerHTML = '&#9660; ' + count + (count===1?' reply':' replies');
    }
}

async function postReply(parentId) {
    const input = document.getElementById('reply-input-' + parentId);
    const body  = (input?.value || '').trim();
    if (body.length < 2) return;

    const res  = await apiFetch('api.php?action=post-comment', {
        method: 'POST',
        body: JSON.stringify({ product_id: PRODUCT_ID, body, parent_id: parentId })
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Failed to post reply.'); return; }

    input.value = '';
    document.getElementById('reply-form-' + parentId).style.display = 'none';

    // Append the new reply into the replies container
    const container = document.getElementById('replies-' + parentId);
    if (container) {
        container.dataset.loaded = '1';
        container.style.display  = '';
        container.insertAdjacentHTML('beforeend', buildCommentCard(data.comment, 1));
    }
    // Update reply count button
    const toggleBtn = document.getElementById('replies-toggle-' + parentId);
    if (toggleBtn) {
        const cur = parseInt(toggleBtn.textContent) || 0;
        const newCount = cur + 1;
        toggleBtn.innerHTML = '&#9660; ' + newCount + (newCount===1?' reply':' replies');
    } else {
        // First reply — inject toggle button
        const card = document.getElementById('comment-' + parentId);
        if (card) {
            const actionsRow = card.querySelector('.vote-btn')?.parentElement;
            if (actionsRow) {
                const btn = document.createElement('button');
                btn.className = 'vote-btn';
                btn.id        = 'replies-toggle-' + parentId;
                btn.innerHTML = '&#9660; 1 reply';
                btn.onclick   = () => toggleReplies(parentId, 1);
                actionsRow.appendChild(btn);
            }
        }
    }
}

async function loadComments(append) {
    if (isLoadingComments) return;
    isLoadingComments = true;
    if (!append) { commentOffset = 0; document.getElementById('comments-list').innerHTML = ''; }

    const res  = await fetch(`api.php?action=sim-comments&product_id=${encodeURIComponent(PRODUCT_ID)}&offset=${commentOffset}&limit=${COMMENTS_PER_PAGE}`);
    const data = await res.json();
    isLoadingComments = false;
    commentTotal      = data.total ?? 0;

    const countEl = document.getElementById('comment-count');
    if (countEl) countEl.textContent = `(${commentTotal})`;

    const list = document.getElementById('comments-list');
    if (data.comments && data.comments.length === 0 && !append) {
        list.innerHTML = '<p style="color:var(--muted);font-size:.88rem;padding:.5rem 0">No comments yet — be the first!</p>';
    } else if (data.comments && data.comments.length > 0) {
        list.insertAdjacentHTML('beforeend', data.comments.map(c => buildCommentCard(c, 0)).join(''));
        commentOffset += data.comments.length;
    }

    const loadMoreBtn = document.getElementById('load-more-btn');
    if (loadMoreBtn) {
        loadMoreBtn.style.display = data.has_more ? 'block' : 'none';
    }
}

async function postComment() {
    const body      = (commentInput?.value || '').trim();
    const submitBtn = document.getElementById('comment-submit');
    const msgEl     = document.getElementById('comment-msg');
    if (!body || body.length < 5) return;

    submitBtn.disabled    = true;
    submitBtn.textContent = 'Posting…';

    const res  = await apiFetch('api.php?action=post-comment', {
        method: 'POST',
        body: JSON.stringify({ product_id: PRODUCT_ID, body })
    });
    const data = await res.json();
    submitBtn.textContent = 'Post Comment';

    if (data.success) {
        commentInput.value = '';
        document.getElementById('comment-char-count').textContent = '0 / 1000';
        msgEl.textContent = 'Comment posted!';
        msgEl.style.color = 'var(--green)';
        const list = document.getElementById('comments-list');
        const empty = list.querySelector('p');
        if (empty) empty.remove();
        list.insertAdjacentHTML('afterbegin', buildCommentCard(data.comment, 0));
        commentTotal++;
        const countEl = document.getElementById('comment-count');
        if (countEl) countEl.textContent = `(${commentTotal})`;
        setTimeout(() => { msgEl.textContent = ''; }, 3000);
    } else {
        msgEl.textContent = data.error || 'Failed to post.';
        msgEl.style.color = 'var(--red)';
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

loadComments(false);

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

// Show the inline auth modal, then continue with upgrade after successful login.
function showAuthModalThenUpgrade() {
    window._upgradeAfterAuth = true;
    openAuthModal();
}

// Lazy Razorpay loader — injects checkout.js only when upgrade is initiated.
function loadRazorpay(callback) {
    if (window.Razorpay) { callback(); return; }
    var s = document.createElement('script');
    s.src = 'https://checkout.razorpay.com/v1/checkout.js';
    s.onload = callback;
    s.onerror = function() { alert('Payment service failed to load. Please refresh and try again.'); };
    document.body.appendChild(s);
}

async function purchaseSimulation(productId) {
    const loadingEl    = document.querySelector('.upgrade-bar');
    const originalHtml = loadingEl ? loadingEl.innerHTML : '';
    if (loadingEl) loadingEl.innerHTML = 'Creating order…';

    loadRazorpay(async function() {
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
    }); // end loadRazorpay callback
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
<!-- ── Inline auth modal ─────────────────────────────────────────────────── -->
<div id="vs-auth-modal" class="vs-modal-bg" onclick="if(event.target===this)closeAuthModal()">
  <div class="vs-modal">
    <button class="vs-modal-close" onclick="closeAuthModal()">&#215;</button>
    <h2>Login / Sign Up</h2>
    <div class="vs-auth-tabs">
      <button class="vs-auth-tab active" id="vs-tab-login"    onclick="vsAuthTab('login')">Sign In</button>
      <button class="vs-auth-tab"        id="vs-tab-register" onclick="vsAuthTab('register')">Register</button>
    </div>

    <!-- Login form -->
    <div id="vs-form-login">
      <div id="vs-login-alert"></div>
      <div class="vs-form-group"><label>Email</label>
        <input type="email" id="vs-login-email" placeholder="your@email.com" autocomplete="email">
      </div>
      <div class="vs-form-group"><label>Password</label>
        <input type="password" id="vs-login-password" placeholder="••••••••" autocomplete="current-password">
      </div>
      <div class="vs-forgot"><a href="#" onclick="event.preventDefault();vsForgotPassword()">Forgot password?</a></div>
      <button class="vs-btn-full" onclick="vsLogin()">Sign In</button>
    </div>

    <!-- Register form -->
    <div id="vs-form-register" style="display:none">
      <div id="vs-register-alert"></div>
      <div class="vs-form-group"><label>Email</label>
        <input type="email" id="vs-register-email" placeholder="your@email.com" autocomplete="email">
      </div>
      <div class="vs-form-group"><label>Password</label>
        <input type="password" id="vs-register-password" placeholder="At least 6 characters" minlength="6" autocomplete="new-password">
      </div>
      <button class="vs-btn-full" onclick="vsRegister()">Create Account</button>
    </div>

    <div class="vs-divider">or continue with</div>
    <button class="vs-google-btn" onclick="vsGoogleLogin()">
      <svg width="18" height="18" viewBox="0 0 24 24">
        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
      </svg>
      Continue with Google
    </button>
  </div>
</div>

<script>
// ── Inline auth modal for view-simulation.php ─────────────────────────────────

function openAuthModal()  { document.getElementById('vs-auth-modal').classList.add('active'); }
function closeAuthModal() { document.getElementById('vs-auth-modal').classList.remove('active'); }

function vsAuthTab(tab) {
  document.getElementById('vs-form-login').style.display    = tab === 'login'    ? '' : 'none';
  document.getElementById('vs-form-register').style.display = tab === 'register' ? '' : 'none';
  document.getElementById('vs-tab-login').classList.toggle('active',    tab === 'login');
  document.getElementById('vs-tab-register').classList.toggle('active', tab === 'register');
}

function vsShowAlert(id, msg, type) {
  var el = document.getElementById(id);
  el.className = 'vs-alert ' + type;
  el.textContent = msg;
}
function vsClearAlert(id) {
  var el = document.getElementById(id);
  el.className = '';
  el.textContent = '';
}

// After a successful login the page reloads so PHP re-evaluates IS_LOGGED_IN.
// If _upgradeAfterAuth was set, the reload passes a flag via sessionStorage
// so initiateUpgrade() fires automatically once the page is ready.
function vsOnLoginSuccess() {
  if (window._upgradeAfterAuth) {
    sessionStorage.setItem('vs_trigger_upgrade', '1');
  }
  window.location.reload();
}

async function vsLogin() {
  vsClearAlert('vs-login-alert');
  var email    = document.getElementById('vs-login-email').value.trim();
  var password = document.getElementById('vs-login-password').value;
  if (!email || !password) { vsShowAlert('vs-login-alert', 'Please fill in all fields.', 'error'); return; }
  try {
    var r = await fetch('api.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ email: email, password: password }),
      cache: 'no-store'
    });
    var d = await r.json();
    if (d.success) {
      vsOnLoginSuccess();
    } else {
      vsShowAlert('vs-login-alert', d.error || 'Login failed', 'error');
    }
  } catch(e) { vsShowAlert('vs-login-alert', 'Network error. Please try again.', 'error'); }
}

async function vsRegister() {
  vsClearAlert('vs-register-alert');
  var email    = document.getElementById('vs-register-email').value.trim();
  var password = document.getElementById('vs-register-password').value;
  if (!email || !password) { vsShowAlert('vs-register-alert', 'Please fill in all fields.', 'error'); return; }
  try {
    var r = await fetch('api.php?action=register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ email: email, password: password }),
      cache: 'no-store'
    });
    var d = await r.json();
    if (d.success) {
      vsShowAlert('vs-register-alert', 'Account created! Signing you in…', 'success');
      setTimeout(function() {
        vsAuthTab('login');
        document.getElementById('vs-login-email').value = d.email || email;
        vsShowAlert('vs-login-alert', 'Account created — please sign in.', 'success');
      }, 900);
    } else {
      vsShowAlert('vs-register-alert', d.error || 'Registration failed', 'error');
    }
  } catch(e) { vsShowAlert('vs-register-alert', 'Network error. Please try again.', 'error'); }
}

async function vsForgotPassword() {
  var email = document.getElementById('vs-login-email').value.trim();
  if (!email) { vsShowAlert('vs-login-alert', 'Enter your email above first.', 'error'); return; }
  try {
    var r = await fetch('api.php?action=reset-password-request', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ email: email }),
      cache: 'no-store'
    });
    var d = await r.json();
    vsShowAlert('vs-login-alert', d.message || 'Reset email sent if account exists.', 'success');
  } catch(e) { vsShowAlert('vs-login-alert', 'Network error.', 'error'); }
}

function vsGoogleLogin() {
  // Store current page as return destination for Google OAuth
  var returnUrl = window.location.href;
  window.location.href = 'auth-google.php?action=login&return=' + encodeURIComponent(returnUrl);
}

// If the page was reloaded after login to trigger upgrade, fire it now.
(function() {
  if (sessionStorage.getItem('vs_trigger_upgrade') === '1') {
    sessionStorage.removeItem('vs_trigger_upgrade');
    // Wait for page JS to initialise before calling initiateUpgrade
    window.addEventListener('load', function() {
      setTimeout(initiateUpgrade, 100);
    });
  }
})();
</script>
</body>
</html>