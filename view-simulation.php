<?php
/**
 * view-simulation.php
 * Serves the simulation iframe + time-tracking + star ratings + comments
 */
if (getenv('DOCKER') === 'true' || file_exists('/.dockerenv')) {
    require_once 'config.docker.php';
} else {
    require_once 'config.php';
}

$productId = $_GET['id'] ?? '';
if (!$productId) { http_response_code(400); die('Product ID required'); }

$user = getCurrentUser();
if (!$user) {
    header('Location: simulations.php');
    exit;
}

$db   = getDB();
// Auth check
$stmt = $db->prepare("SELECT p.id FROM purchases p WHERE p.user_id=? AND p.product_id=? AND p.status IN ('paid','trial','demo')");
$stmt->execute([$user['id'], $productId]);
if (!$stmt->fetch()) {
    header('Location: simulations.php');
    exit;
}

// Fetch product info (include paid_file)
$stmt = $db->prepare("SELECT title, sim_file, paid_file, description FROM products WHERE id=?");
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product || !$product['sim_file']) { http_response_code(404); die('Simulation not found'); }

// Determine access level: paid purchase unlocks paid_file in simulations/paid/
$isPaid = false;
$paidCheck = $db->prepare("SELECT id FROM purchases WHERE user_id=? AND product_id=? AND status='paid' LIMIT 1");
$paidCheck->execute([$user['id'], $productId]);
if ($paidCheck->fetch()) $isPaid = true;

// Pick file and folder
if ($isPaid && !empty($product['paid_file'])) {
    $simFile   = basename($product['paid_file']);
    $simFolder = 'paid';
} else {
    $simFile   = basename($product['sim_file']);
    $simFolder = 'trial';
}

// Security: validate filename (allow optional _paid suffix)
if (strpos($simFile, '..') !== false || !preg_match('/^sim-[\w-]+(?:_paid)?\.html$/', $simFile)) {
    http_response_code(403); die('Invalid filename');
}

// Try subfolder first, fall back to root simulations/ for backwards compatibility
$simPath = __DIR__ . '/simulations/' . $simFolder . '/' . $simFile;
if (!file_exists($simPath)) $simPath = __DIR__ . '/simulations/' . $simFile;
if (!file_exists($simPath)) { http_response_code(404); die('Simulation file not found'); }

$userId   = (int)$user['id'];
$userName = esc($user['display_name'] ?? $user['email']);
$userInitial = strtoupper(substr($user['display_name'] ?? $user['email'], 0, 1));
$avatarUrl = esc($user['avatar_url'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= esc($product['title']) ?> — Akilesh Bhandary</title>
<script>
// Apply stored theme before paint
(function(){
  var t = localStorage.getItem('ir_theme');
  if (!t) { var h = new Date().getHours(); t = (h>=6&&h<18)?'light':'dark'; }
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<style>
:root {
  --bg: #0d1117; --bg2: #161b22; --bg3: #21262d;
  --text: #e6edf3; --muted: #8b949e; --border: #30363d;
  --gold: #f0c040; --gold-dark: #b8860b;
  --green: #3fb950; --red: #f87171; --radius: 10px;
  --nav-h: 52px;
}
[data-theme="light"] {
  --bg:#fff; --bg2:#f6f8fa; --bg3:#eaecef;
  --text:#24292f; --muted:#57606a; --border:#d0d7de;
  --gold: #b8860b;
}
*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
html, body { height:100%; font-family:'Segoe UI',system-ui,sans-serif; background:var(--bg); color:var(--text); }

/* ── Top bar ────────────────────────────────────────────────── */
.topbar {
  position:sticky; top:0; z-index:50;
  height:var(--nav-h); background:rgba(13,17,23,.95);
  backdrop-filter:blur(10px);
  border-bottom:1px solid var(--border);
  display:flex; align-items:center; padding:0 1.25rem; gap:1rem;
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.95); }
.topbar-back { color:var(--gold); text-decoration:none; font-weight:600; font-size:.9rem; display:flex; align-items:center; gap:.4rem; }
.topbar-title { font-weight:700; font-size:1rem; flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.topbar-fullscreen { background:var(--gold); color:#000; border:none; border-radius:6px; padding:.35rem .85rem; font-size:.8rem; font-weight:700; cursor:pointer; }

/* ── Simulation iframe ─────────────────────────────────────── */
#sim-frame { width:100%; height:calc(100vh - var(--nav-h) - 2px); border:none; display:block; }

/* ── Panel below sim ───────────────────────────────────────── */
.sim-panel { max-width:860px; margin:0 auto; padding:2rem 1.25rem; }

/* Stars */
.stars-section { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:1.25rem 1.5rem; margin-bottom:1.5rem; display:flex; align-items:center; flex-wrap:wrap; gap:1.25rem; }
.stars-label { font-size:.85rem; color:var(--muted); }
.star-row { display:flex; gap:.25rem; }
.star { font-size:1.5rem; cursor:pointer; transition:transform .15s; color:#3a3f4b; user-select:none; line-height:1; }
.star:hover, .star.lit { color:var(--gold); transform:scale(1.15); }
.stars-avg { font-size:.9rem; color:var(--muted); }

/* Comments */
.comments-section { }
.comments-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
.comments-header h3 { font-size:1.1rem; }
.comment-form { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:1rem 1.25rem; margin-bottom:1.5rem; }
.comment-form textarea { width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:inherit; font-size:.9rem; padding:.65rem; min-height:80px; resize:vertical; }
.comment-form textarea:focus { outline:none; border-color:var(--gold); }
.comment-form .form-footer { display:flex; justify-content:space-between; align-items:center; margin-top:.5rem; font-size:.8rem; color:var(--muted); }
.btn-submit { background:var(--gold); color:#0a0a09; border:none; border-radius:6px; padding:.5rem 1.25rem; font-weight:700; font-size:.85rem; cursor:pointer; }
.btn-submit:hover { background:var(--gold-dark); }
.btn-submit:disabled { opacity:.5; cursor:not-allowed; }

.comment-card { display:flex; gap:.85rem; padding:1rem 0; border-bottom:1px solid var(--border); }
.comment-card:last-child { border-bottom:none; }
.avatar-sm { width:36px; height:36px; border-radius:50%; background:var(--gold); color:#0a0a09; display:flex; align-items:center; justify-content:center; font-size:.85rem; font-weight:700; flex-shrink:0; overflow:hidden; }
.avatar-sm img { width:100%; height:100%; object-fit:cover; }
.comment-meta { font-size:.78rem; color:var(--muted); margin-bottom:.3rem; }
.comment-body { font-size:.9rem; line-height:1.55; white-space:pre-wrap; }
.load-more-btn { display:block; width:100%; padding:.75rem; margin-top:1rem; background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); color:var(--text); font-size:.88rem; cursor:pointer; text-align:center; }
.load-more-btn:hover { border-color:var(--gold); color:var(--gold); }
.comment-gated { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:1rem 1.25rem; text-align:center; color:var(--muted); font-size:.88rem; }
</style>
</head>
<body>

<div class="topbar">
  <a class="topbar-back" href="simulations.php">← Back</a>
  <span class="topbar-title"><?= esc($product['title']) ?></span>
  <button class="topbar-fullscreen" onclick="document.getElementById('sim-frame').requestFullscreen()">⛶ Full Screen</button>
</div>

<?php if ($isPaid && !empty($product['paid_file'])): ?>
<div style="background:#10b981;color:#fff;text-align:center;padding:.4rem;font-size:.8rem;font-weight:700;letter-spacing:.5px">
  ✓ FULL VERSION — All features unlocked
</div>
<?php else: ?>
<div style="background:#1a1d23;border-bottom:1px solid var(--border);text-align:center;padding:.4rem;font-size:.78rem;color:#8b949e">
  🆓 FREE TRIAL — <a href="simulations.php" style="color:#f0c040;text-decoration:none">Upgrade to full version</a> for advanced controls
</div>
<?php endif; ?>
<iframe id="sim-frame" src="simulations/<?= $simFolder ?>/<?= $simFile ?>" title="<?= esc($product['title']) ?>" allowfullscreen></iframe>

<!-- Below-sim panel: ratings + comments -->
<div class="sim-panel">

  <!-- Star rating -->
  <div class="stars-section" id="stars-section">
    <div>
      <div class="stars-label">Rate this simulation</div>
      <div class="star-row" id="star-row">
        <?php for($i=1;$i<=5;$i++): ?>
        <span class="star" data-v="<?=$i?>" onclick="submitRating(<?=$i?>)" onmouseover="hoverStars(<?=$i?>)" onmouseout="resetStars()">★</span>
        <?php endfor; ?>
      </div>
    </div>
    <div class="stars-avg" id="stars-avg">Loading…</div>
  </div>

  <!-- Comments -->
  <div class="comments-section">
    <div class="comments-header">
      <h3>Comments <span id="comment-count" style="color:var(--muted);font-size:.85rem"></span></h3>
    </div>

    <?php if ($user): ?>
    <div class="comment-form" id="comment-form-wrap">
      <div style="display:flex;gap:.75rem;align-items:flex-start">
        <div class="avatar-sm">
          <?php if ($avatarUrl): ?>
          <img src="<?= $avatarUrl ?>" alt="">
          <?php else: ?>
          <?= $userInitial ?>
          <?php endif; ?>
        </div>
        <div style="flex:1">
          <textarea id="comment-input" placeholder="Share your thoughts about this simulation…" maxlength="1000"></textarea>
          <div class="form-footer">
            <span id="comment-char-count">0 / 1000</span>
            <button class="btn-submit" id="comment-submit" onclick="postComment()" disabled>Post Comment</button>
          </div>
        </div>
      </div>
      <div id="comment-msg" style="margin-top:.5rem;font-size:.82rem"></div>
    </div>
    <?php else: ?>
    <div class="comment-gated">Sign in to leave a comment.</div>
    <?php endif; ?>

    <div id="comments-list"></div>
    <button class="load-more-btn" id="load-more-btn" style="display:none" onclick="loadComments(true)">Load more comments</button>
  </div>
</div>

<script>
const PRODUCT_ID  = <?= json_encode($productId) ?>;
const IS_PAID     = <?= $isPaid ? 'true' : 'false' ?>;
const USER_ID     = <?= $userId ?>;
const USER_LOGGED = true;
// Unique session per page load
const SESSION_ID  = 'sess_' + USER_ID + '_' + Date.now() + '_' + Math.random().toString(36).slice(2);

// ── Time tracking ─────────────────────────────────────────────────────────
let startTime = Date.now();
let tracked   = false;

function sendTrack(action, seconds) {
  const data = { product_id: PRODUCT_ID, session_id: SESSION_ID };
  if (action === 'end') data.seconds = seconds;
  navigator.sendBeacon('api.php?action=track-' + action, new Blob([JSON.stringify(data)], {type:'application/json'}));
}

// Start tracking immediately
sendTrack('start', 0);

// End tracking on page unload
window.addEventListener('visibilitychange', function() {
  if (document.visibilityState === 'hidden') {
    const secs = Math.floor((Date.now() - startTime) / 1000);
    sendTrack('end', secs);
  }
});
window.addEventListener('pagehide', function() {
  const secs = Math.floor((Date.now() - startTime) / 1000);
  sendTrack('end', secs);
});

// ── Star ratings ──────────────────────────────────────────────────────────
let myRating = 0;
let canRate  = false; // only after interacting (tracked session > 5s)

// Allow rating after 5 seconds of use
setTimeout(function() { canRate = true; }, 5000);

async function loadRatings() {
  const r = await fetch('api.php?action=sim-ratings&product_id=' + encodeURIComponent(PRODUCT_ID));
  const d = await r.json();
  document.getElementById('stars-avg').textContent =
    d.count > 0 ? `${d.avg_rating} ★ (${d.count} rating${d.count!==1?'s':''})` : 'No ratings yet — be the first!';
  if (d.my_rating) {
    myRating = d.my_rating;
    renderStars(myRating);
  }
}

function renderStars(n) {
  document.querySelectorAll('.star').forEach(s => {
    s.classList.toggle('lit', +s.dataset.v <= n);
  });
}
function hoverStars(n) { renderStars(n); }
function resetStars()  { renderStars(myRating); }

async function submitRating(n) {
  if (!canRate) {
    alert('Spend at least 5 seconds with the simulation before rating!');
    return;
  }
  const r  = await fetch('api.php?action=rate-sim', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ product_id: PRODUCT_ID, rating: n })
  });
  const d = await r.json();
  if (d.success) {
    myRating = n; renderStars(myRating);
    document.getElementById('stars-avg').textContent = `${d.avg_rating} ★ (${d.count} rating${d.count!==1?'s':''})`;
  } else {
    alert(d.error || 'Could not save rating');
  }
}

loadRatings();

// ── Comments ──────────────────────────────────────────────────────────────
let commentPage    = 1;
let commentTotal   = 0;
let commentLoading = false;
let canComment     = false;

// Enable comment box after 5 seconds
setTimeout(function() {
  canComment = true;
  const btn = document.getElementById('comment-submit');
  if (btn) updateSubmitBtn();
}, 5000);

const ta = document.getElementById('comment-input');
if (ta) {
  ta.addEventListener('input', function() {
    const len = ta.value.length;
    document.getElementById('comment-char-count').textContent = len + ' / 1000';
    updateSubmitBtn();
  });
}

function updateSubmitBtn() {
  const btn = document.getElementById('comment-submit');
  if (!btn) return;
  const len = (ta?.value || '').trim().length;
  btn.disabled = !canComment || len < 5;
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderComment(c) {
  const name    = c.display_name || c.email.split('@')[0];
  const initial = name.charAt(0).toUpperCase();
  const avatar  = c.avatar_url
    ? `<img src="${escHtml(c.avatar_url)}" alt="">`
    : initial;
  const date = new Date(c.created_at).toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});
  return `<div class="comment-card">
    <div class="avatar-sm">${avatar}</div>
    <div style="flex:1">
      <div class="comment-meta"><strong>${escHtml(name)}</strong> · ${date}</div>
      <div class="comment-body">${escHtml(c.body)}</div>
    </div>
  </div>`;
}

async function loadComments(more = false) {
  if (commentLoading) return;
  commentLoading = true;
  if (!more) { commentPage = 1; document.getElementById('comments-list').innerHTML = ''; }
  const r    = await fetch(`api.php?action=sim-comments&product_id=${encodeURIComponent(PRODUCT_ID)}&page=${commentPage}`);
  const data = await r.json();
  commentLoading = false;
  commentTotal   = data.total;
  document.getElementById('comment-count').textContent = `(${commentTotal})`;
  const list = document.getElementById('comments-list');
  if (data.comments.length === 0 && !more) {
    list.innerHTML = '<p style="color:var(--muted);font-size:.88rem;padding:.5rem 0">No comments yet — be the first!</p>';
  } else {
    list.insertAdjacentHTML('beforeend', data.comments.map(renderComment).join(''));
  }
  const btn = document.getElementById('load-more-btn');
  if (data.has_more) { commentPage++; btn.style.display = 'block'; }
  else btn.style.display = 'none';
}

async function postComment() {
  const body = (ta?.value || '').trim();
  if (!body || body.length < 5) return;
  const btn = document.getElementById('comment-submit');
  const msg = document.getElementById('comment-msg');
  btn.disabled = true; btn.textContent = 'Posting…';
  const r = await fetch('api.php?action=post-comment', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ product_id: PRODUCT_ID, body })
  });
  const d = await r.json();
  btn.textContent = 'Post Comment';
  if (d.success) {
    ta.value = ''; document.getElementById('comment-char-count').textContent = '0 / 1000';
    msg.textContent = '✓ Comment posted!'; msg.style.color = 'var(--green)';
    const list = document.getElementById('comments-list');
    list.insertAdjacentHTML('afterbegin', renderComment(d.comment));
    commentTotal++;
    document.getElementById('comment-count').textContent = `(${commentTotal})`;
    setTimeout(() => { msg.textContent = ''; }, 3000);
  } else {
    msg.textContent = d.error || 'Failed to post'; msg.style.color = 'var(--red)';
  }
  updateSubmitBtn();
}

loadComments();

// Infinite scroll — load more comments as user scrolls near bottom
window.addEventListener('scroll', function() {
  if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 300) {
    const btn = document.getElementById('load-more-btn');
    if (btn && btn.style.display !== 'none') loadComments(true);
  }
});
</script>
</body>
</html>
