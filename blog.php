<?php
/**
 * blog.php
 *
 * Standalone shareable blog post page.
 * Clean URL: /blog/<slug>  (routed here via .htaccess RewriteRule)
 *
 * Renders og:meta server-side for link previews and SEO.
 * Comments, likes, and replies are loaded client-side via api.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
startSession();

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'] ?? '')));
if (!$slug) { http_response_code(400); die('Post not found.'); }

$postFile = __DIR__ . '/data/blog/' . $slug . '.json';
if (!file_exists($postFile)) { http_response_code(404); die('Post not found.'); }

$post = json_decode(file_get_contents($postFile), true);
if (!is_array($post) || ($post['status'] ?? '') !== 'published') {
    http_response_code(404); die('Post not found.');
}

$currentUser = getCurrentUser();
$isLoggedIn  = $currentUser !== null;
$csrfToken   = generateCSRFToken();

$pageTitle   = esc($post['title'] ?? 'Blog');
$summary     = esc($post['summary'] ?? '');
$coverImage  = esc($post['cover_image'] ?? '');
$author      = esc($post['author'] ?? 'Independent Researcher');
$publishedAt = $post['created_at'] ?? '';
$canonicalUrl = esc(SITE_URL . '/blog/' . $slug);

$avatarUrl   = $currentUser['avatar_url'] ?? '';
$userInitial = $currentUser ? strtoupper(substr($currentUser['display_name'] ?? $currentUser['email'] ?? 'U', 0, 1)) : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $csrfToken ?>">
<title><?= $pageTitle ?> | <?= esc(SITE_NAME) ?></title>
<meta name="description" content="<?= $summary ?>">
<link rel="canonical" href="<?= $canonicalUrl ?>">

<!-- Open Graph / link preview -->
<meta property="og:type"        content="article">
<meta property="og:title"       content="<?= $pageTitle ?>">
<meta property="og:description" content="<?= $summary ?>">
<meta property="og:url"         content="<?= $canonicalUrl ?>">
<?php if ($coverImage): ?>
<meta property="og:image"       content="<?= $coverImage ?>">
<?php endif; ?>
<meta property="article:published_time" content="<?= esc($publishedAt) ?>">

<!-- Twitter card -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= $pageTitle ?>">
<meta name="twitter:description" content="<?= $summary ?>">
<?php if ($coverImage): ?>
<meta name="twitter:image"       content="<?= $coverImage ?>">
<?php endif; ?>

<style>
:root {
    --bg:       #0e1117; --bg2: #161b27; --bg3: #1d2333;
    --text:     #e8eaf0; --muted: #7a8099; --border: #252d3d;
    --gold:     #f0c040; --gold-dark: #c9a030;
    --green:    #4ade80; --red: #f87171;
    --radius:   10px;    --nav-h: 52px;
}
[data-theme="light"] {
    --bg: #f5f6fa; --bg2: #ffffff; --bg3: #eef0f5;
    --text: #1a1d27; --muted: #5a6175; --border: #d8dae5;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; }
body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; min-height: 100vh; }

/* Topbar */
.topbar { position: sticky; top: 0; z-index: 100; display: flex; align-items: center; gap: 1rem; padding: 0 1.25rem; height: var(--nav-h); background: rgba(14,17,23,.95); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border); }
[data-theme="light"] .topbar { background: rgba(255,255,255,.96); }
.topbar-back { color: var(--gold); text-decoration: none; font-weight: 600; font-size: .9rem; flex-shrink: 0; }
.topbar-back:hover { color: var(--gold-dark); }
.topbar-title { font-weight: 700; font-size: 1rem; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.topbar-btn { background: var(--gold); color: #000; border: none; border-radius: 6px; padding: .35rem .85rem; font-size: .8rem; font-weight: 700; cursor: pointer; flex-shrink: 0; }
.topbar-btn:hover { background: var(--gold-dark); }

/* Post layout */
.post-wrap { max-width: 780px; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
.post-cover { width: 100%; max-height: 380px; object-fit: cover; border-radius: var(--radius); margin-bottom: 2rem; display: block; }
.post-title { font-size: clamp(1.5rem, 4vw, 2.2rem); font-weight: 800; line-height: 1.25; margin-bottom: 1rem; }
.post-meta { font-size: .85rem; color: var(--muted); margin-bottom: 2rem; display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; }
.post-meta-sep { opacity: .4; }

/* Share bar */
.share-bar { display: flex; align-items: center; gap: .65rem; margin-bottom: 2rem; flex-wrap: wrap; }
.share-btn { display: flex; align-items: center; gap: .4rem; background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: .4rem .85rem; font-size: .82rem; color: var(--text); cursor: pointer; text-decoration: none; transition: border-color .15s; }
.share-btn:hover { border-color: var(--gold); color: var(--gold); }
.like-btn { background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: .4rem .85rem; font-size: .82rem; color: var(--text); cursor: pointer; display: flex; align-items: center; gap: .4rem; transition: border-color .15s; }
.like-btn:hover, .like-btn.liked { border-color: var(--gold); color: var(--gold); }

/* Post content */
.post-content { font-size: 1rem; line-height: 1.8; }
.post-content h1, .post-content h2, .post-content h3, .post-content h4 { margin: 2rem 0 .75rem; line-height: 1.3; }
.post-content h1 { font-size: 1.7rem; } .post-content h2 { font-size: 1.4rem; } .post-content h3 { font-size: 1.15rem; }
.post-content p { margin-bottom: 1.15rem; }
.post-content ul, .post-content ol { margin: 0 0 1.15rem 1.5rem; }
.post-content li { margin-bottom: .4rem; }
.post-content blockquote { border-left: 3px solid var(--gold); padding: .5rem 1rem; margin: 1.5rem 0; background: var(--bg2); border-radius: 0 6px 6px 0; color: var(--muted); font-style: italic; }
.post-content pre { background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; padding: 1rem; overflow-x: auto; margin-bottom: 1.15rem; }
.post-content code { background: var(--bg3); padding: .15rem .35rem; border-radius: 3px; font-size: .9em; }
.post-content pre code { background: none; padding: 0; }
.post-content img { max-width: 100%; border-radius: 6px; margin: 1rem 0; }
.post-content a { color: var(--gold); }
.post-content a:hover { color: var(--gold-dark); }
.post-content hr { border: none; border-top: 1px solid var(--border); margin: 2rem 0; }
.post-content table { width: 100%; border-collapse: collapse; margin-bottom: 1.15rem; }
.post-content th, .post-content td { border: 1px solid var(--border); padding: .5rem .75rem; text-align: left; }
.post-content th { background: var(--bg3); font-weight: 600; }

/* Divider */
.section-divider { border: none; border-top: 1px solid var(--border); margin: 2.5rem 0; }

/* Comments */
.comments-section h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: 1.25rem; }
.comment-form-box { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
.comment-form-box textarea { width: 100%; background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-family: inherit; font-size: .9rem; padding: .65rem; min-height: 80px; resize: vertical; }
.comment-form-box textarea:focus { outline: none; border-color: var(--gold); }
.comment-footer { display: flex; justify-content: space-between; align-items: center; margin-top: .5rem; font-size: .8rem; color: var(--muted); }
.btn-post { background: var(--gold); color: #000; border: none; border-radius: 6px; padding: .5rem 1.25rem; font-weight: 700; font-size: .85rem; cursor: pointer; }
.btn-post:hover { background: var(--gold-dark); }
.comment-card { padding: 1rem 0; border-bottom: 1px solid var(--border); }
.comment-card:last-child { border-bottom: none; }
.comment-meta { font-size: .78rem; color: var(--muted); }
.comment-body { font-size: .9rem; line-height: 1.55; white-space: pre-wrap; margin: .35rem 0 .5rem; }
.vote-btn { background: none; border: none; color: var(--muted); font-size: .8rem; cursor: pointer; padding: .15rem .35rem; border-radius: 4px; transition: color .15s; }
.vote-btn:hover { color: var(--text); }
.vote-btn.active-up   { color: var(--gold); font-weight: 700; }
.vote-btn.active-down { color: #f87171;     font-weight: 700; }
.load-more { display: block; width: 100%; padding: .75rem; margin-top: 1rem; background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-size: .88rem; cursor: pointer; text-align: center; }
.load-more:hover { border-color: var(--gold); color: var(--gold); }

/* Auth modal */
.vs-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity .2s; padding: 1rem; }
.vs-modal-bg.active { opacity: 1; pointer-events: all; }
.vs-modal { background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; padding: 2rem; width: 100%; max-width: 420px; position: relative; }
.vs-modal-close { position: absolute; top: .75rem; right: .75rem; background: none; border: none; color: var(--muted); font-size: 1.4rem; cursor: pointer; line-height: 1; }
.vs-modal-close:hover { color: var(--text); }
.vs-modal h2 { text-align: center; margin: 0 0 1.75rem; font-size: 1.5rem; }
.vs-auth-tabs { display: flex; border-bottom: 2px solid var(--border); margin-bottom: 1.75rem; }
.vs-auth-tab { flex: 1; padding: .85rem; background: none; border: none; color: var(--muted); font-size: .95rem; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all .2s; }
.vs-auth-tab.active { color: var(--text); border-bottom-color: var(--gold); }
.vs-form-group { margin-bottom: 1rem; }
.vs-form-group label { display: block; font-size: .85rem; color: var(--muted); margin-bottom: .35rem; }
.vs-form-group input { width: 100%; padding: .65rem .75rem; background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: .95rem; }
.vs-form-group input:focus { outline: none; border-color: var(--gold); }
.vs-btn-full { width: 100%; padding: .9rem; background: var(--gold); color: #000; border: none; border-radius: 8px; font-weight: 700; font-size: .95rem; cursor: pointer; }
.vs-btn-full:hover { background: var(--gold-dark); }
.vs-divider { display: flex; align-items: center; gap: .75rem; margin: 1.25rem 0; color: var(--muted); font-size: .85rem; }
.vs-divider::before, .vs-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.vs-google-btn { width: 100%; padding: .7rem; background: #fff; color: #444; border: 1px solid #ddd; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: .65rem; font-size: .9rem; }
.vs-google-btn:hover { background: #f5f5f5; }
.vs-alert { padding: .6rem .85rem; border-radius: 6px; font-size: .85rem; margin-bottom: 1rem; }
.vs-alert.error   { background: rgba(220,53,69,.12); color: #f87171; border: 1px solid rgba(220,53,69,.25); }
.vs-alert.success { background: rgba(34,197,94,.12); color: #4ade80; border: 1px solid rgba(34,197,94,.25); }
.vs-forgot { text-align: right; margin-bottom: 1rem; }
.vs-forgot a { color: var(--gold); font-size: .85rem; text-decoration: none; }
</style>

<script>
window.addEventListener('load', function() {
    var gaId = '<?php echo esc(GA_TAG_ID); ?>';
    if (gaId && localStorage.getItem('ir_cookie_consent') !== 'declined') {
        var s = document.createElement('script'); s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
        document.head.appendChild(s);
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        window.gtag = gtag; gtag('js', new Date()); gtag('config', gaId);
    }
});
function apiFetch(url, opts) {
    opts = opts || {};
    opts.headers = Object.assign({
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'X-Requested-With': 'XMLHttpRequest'
    }, opts.headers || {});
    // Prefix relative URLs with site root — this page is served from /blog/<slug>
    // so relative paths would resolve incorrectly without the prefix.
    const base = <?= json_encode(rtrim(SITE_URL, '/')) ?>;
    if (!url.startsWith('http') && !url.startsWith('/')) url = base + '/' + url;
    return fetch(url, opts);
}
</script>
</head>
<body>

<canvas id="starCanvas" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:-2;pointer-events:none"></canvas>
<canvas id="lightBgCanvas" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:-3;pointer-events:none;opacity:0;visibility:hidden"></canvas>

<script>
// Starfield (matches index.php dark mode background exactly)
(function(){
  const canvas=document.getElementById('starCanvas');
  if(!canvas)return;
  const ctx=canvas.getContext('2d');
  let stars=[],shoots=[],nextTimer=0,rafId=null,lastTs=0;
  const BASE=120,MIN=60,MAX=160,LIFE=[3,8];
  function mkStar(w,h){const oR=Math.random()*1.8+0.8;return{x:Math.random()*w,y:Math.random()*h,oR,iR:oR*0.4,e:[3,4,5][Math.floor(Math.random()*3)],rot:Math.random()*360,life:LIFE[0]+Math.random()*(LIFE[1]-LIFE[0]),age:0};}
  function init(w,h){const n=Math.min(MAX,Math.max(MIN,Math.floor(BASE*w*h/(1920*1080))));stars=Array.from({length:n},()=>mkStar(w,h));}
  function mkShoot(w,h){const sx=Math.random()*w*.5,sy=Math.random()*h*.3,a=((Math.random()-.5)*40)*Math.PI/180,dist=Math.hypot(w,h)*(0.8+Math.random()*.7);return{sx,sy,ex:sx+Math.cos(a)*dist,ey:sy+Math.sin(a)*dist,dist,gone:0,spd:2+Math.random()*3,acc:.5+Math.random()*.7,trail:[],hue:Math.random()*360};}
  function frame(ts){
    if(!lastTs){lastTs=ts;rafId=requestAnimationFrame(frame);return;}
    const dt=Math.min(.1,(ts-lastTs)/1e3);lastTs=ts;
    const w=canvas.width,h=canvas.height;
    stars.forEach(s=>{s.age+=dt;if(s.age>=s.life)Object.assign(s,mkStar(w,h));});
    for(let i=shoots.length-1;i>=0;i--){const s=shoots[i];s.spd*=Math.exp(s.acc*dt);s.gone+=s.spd*dt;if(s.gone>=s.dist){shoots.splice(i,1);continue;}const t=s.gone/s.dist;s.trail.push({x:s.sx+(s.ex-s.sx)*t,y:s.sy+(s.ey-s.sy)*t,age:0});s.trail.forEach(p=>p.age+=dt);s.trail=s.trail.filter(p=>p.age<.7);}
    if(nextTimer<=0){if(shoots.length<2)shoots.push(mkShoot(w,h));nextTimer=2+Math.random()*3;}else nextTimer-=dt;
    ctx.clearRect(0,0,w,h);
    stars.forEach(s=>{const a=Math.min(.95,Math.max(.08,Math.sin(Math.PI*s.age/s.life)));ctx.fillStyle=`rgba(255,240,210,${a})`;ctx.beginPath();ctx.arc(s.x,s.y,s.oR*.9,0,Math.PI*2);ctx.fill();});
    shoots.forEach(s=>{for(let i=0;i<s.trail.length-1;i++){const p1=s.trail[i],p2=s.trail[i+1],op=Math.max(0,.8*(1-p1.age/.7));ctx.beginPath();ctx.moveTo(p1.x,p1.y);ctx.lineTo(p2.x,p2.y);ctx.strokeStyle=`rgba(255,255,255,${op})`;ctx.lineWidth=2+op*2;ctx.stroke();}});
    rafId=requestAnimationFrame(frame);
  }
  window.addEventListener('load',()=>{
    if(window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
    canvas.width=window.innerWidth;canvas.height=window.innerHeight;
    init(canvas.width,canvas.height);lastTs=0;rafId=requestAnimationFrame(frame);
    window.addEventListener('resize',()=>{canvas.width=window.innerWidth;canvas.height=window.innerHeight;init(canvas.width,canvas.height);shoots=[];});
  });
})();
</script>

<div class="topbar">
    <a class="topbar-back" href="/?page=blog">&#8592; Back to Blog</a>
    <span class="topbar-title"><?= $pageTitle ?></span>
</div>

<div class="post-wrap">

    <?php if ($coverImage): ?>
    <img src="<?= $coverImage ?>" alt="<?= $pageTitle ?>" class="post-cover">
    <?php endif; ?>

    <h1 class="post-title"><?= $pageTitle ?></h1>

    <div class="post-meta">
        <span><?= $author ?></span>
        <?php if ($publishedAt): ?>
        <span class="post-meta-sep">|</span>
        <span><?= esc(date('F j, Y', strtotime($publishedAt))) ?></span>
        <?php endif; ?>
    </div>

    <!-- Share + Like bar -->
    <div class="share-bar">
        <button class="share-btn" onclick="copyShareLink()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            <span id="share-label">Copy link</span>
        </button>
        <button class="like-btn" id="like-btn" onclick="toggleLike()">
            <span id="like-icon">&#9825;</span>
            <span id="like-count">0</span>
        </button>
    </div>

    <!-- Post body — content is HTML stored in JSON -->
    <div class="post-content" id="post-content">
        <?= $post['content'] ?? '' ?>
    </div>

    <hr class="section-divider">

    <!-- Comments -->
    <div class="comments-section">
        <h2>Comments <span id="comment-count" style="color:var(--muted);font-size:.85rem;font-weight:400"></span></h2>

        <?php if ($isLoggedIn): ?>
        <div class="comment-form-box">
            <div style="display:flex;gap:.75rem;align-items:flex-start">
                <div style="width:34px;height:34px;border-radius:50%;background:var(--gold);color:#000;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0;overflow:hidden">
                    <?php if ($avatarUrl): ?>
                    <img src="<?= esc($avatarUrl) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                    <?php else: ?>
                    <?= $userInitial ?>
                    <?php endif; ?>
                </div>
                <div style="flex:1">
                    <textarea id="comment-input" placeholder="Share your thoughts…" maxlength="5000"
                              oninput="document.getElementById('comment-char').textContent=this.value.length+' / 5000'"></textarea>
                    <div class="comment-footer">
                        <span id="comment-char">0 / 5000</span>
                        <button class="btn-post" onclick="postComment()">Post Comment</button>
                    </div>
                </div>
            </div>
            <div id="comment-msg" style="margin-top:.5rem;font-size:.82rem"></div>
        </div>
        <?php else: ?>
        <div class="comment-form-box" style="text-align:center;padding:1.25rem">
            <p style="color:var(--muted);margin-bottom:.75rem">Login to join the conversation</p>
            <button class="topbar-btn" style="padding:.5rem 1.25rem" onclick="openAuthModal()">Login / Sign Up</button>
        </div>
        <?php endif; ?>

        <div id="comments-list"></div>
        <button class="load-more" id="load-more-btn" style="display:none" onclick="loadComments(true)">Load more comments</button>
    </div>
</div>

<!-- ── Auth modal ─────────────────────────────────────────────────────────── -->
<div id="vs-auth-modal" class="vs-modal-bg" onclick="if(event.target===this)closeAuthModal()">
  <div class="vs-modal">
    <button class="vs-modal-close" onclick="closeAuthModal()">&#215;</button>
    <h2>Login / Sign Up</h2>
    <div class="vs-auth-tabs">
      <button class="vs-auth-tab active" id="vs-tab-login"    onclick="vsAuthTab('login')">Sign In</button>
      <button class="vs-auth-tab"        id="vs-tab-register" onclick="vsAuthTab('register')">Register</button>
    </div>
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
const POST_SLUG    = <?= json_encode($slug) ?>;
const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
const SITE_ROOT    = <?= json_encode(rtrim(SITE_URL, '/')) ?>;

// ── Utilities ─────────────────────────────────────────────────────────────────
function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Share link ────────────────────────────────────────────────────────────────
function copyShareLink() {
    const url = window.location.href;
    navigator.clipboard.writeText(url).then(() => {
        const lbl = document.getElementById('share-label');
        lbl.textContent = 'Copied!';
        setTimeout(() => { lbl.textContent = 'Copy link'; }, 2000);
    }).catch(() => {
        prompt('Copy this link:', url);
    });
}

// ── Like ──────────────────────────────────────────────────────────────────────
let _liked = false;

async function loadLikes() {
    try {
        const r = await fetch(SITE_ROOT + '/api.php?action=blog-like-count&slug=' + encodeURIComponent(POST_SLUG));
        const d = await r.json();
        document.getElementById('like-count').textContent = d.count ?? 0;
        _liked = d.user_liked ?? false;
        _applyLikeState(_liked);
    } catch(e) {}
}

function _applyLikeState(liked) {
    const btn  = document.getElementById('like-btn');
    const icon = document.getElementById('like-icon');
    _liked = liked;
    btn.classList.toggle('liked', liked);
    icon.textContent = liked ? '\u2665' : '\u2661';
}

async function toggleLike() {
    if (!IS_LOGGED_IN) { openAuthModal(); return; }
    try {
        const r = await apiFetch('api.php?action=blog-like', {
            method: 'POST',
            body: JSON.stringify({ slug: POST_SLUG })
        });
        const d = await r.json();
        _applyLikeState(d.liked);
        document.getElementById('like-count').textContent = d.count ?? 0;
    } catch(e) {}
}

// ── Comments ──────────────────────────────────────────────────────────────────
let commentOffset   = 0;
const COMMENTS_PER_PAGE = 10;
let commentTotal    = 0;
let isLoadingComments = false;

function buildAvatarHtml(c, size) {
    const name = c.display_name || '?';
    if (c.avatar_url) return `<img src="${escapeHtml(c.avatar_url)}" alt="" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover">`;
    return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:var(--gold);color:#000;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:${Math.round(size*.42)}px;flex-shrink:0">${escapeHtml(name.charAt(0).toUpperCase())}</div>`;
}

function buildCommentCard(c, depth) {
    depth = depth || 0;
    const name    = c.display_name || 'User';
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
                <div class="comment-meta"><strong>${escapeHtml(name)}</strong> <span style="color:var(--muted);font-size:.8rem"> · ${date}</span></div>
                <div class="comment-body">${escapeHtml(c.body)}</div>
                <div style="display:flex;align-items:center;gap:.85rem;font-size:.82rem">
                    <button class="${upCls}" onclick="blogVote(${c.id}, ${uv===1?0:1})" title="Upvote">
                        &#9650; <span id="score-${c.id}">${score}</span>
                    </button>
                    <button class="${dnCls}" onclick="blogVote(${c.id}, ${uv===-1?0:-1})" title="Downvote">
                        &#9660;
                    </button>
                    ${IS_LOGGED_IN && depth === 0 ? `<button class="vote-btn" onclick="toggleReplyForm(${c.id})">Reply</button>` : ''}
                    ${replies > 0 ? `<button class="vote-btn" id="replies-toggle-${c.id}" onclick="toggleReplies(${c.id},${replies})">\u25BA ${replies} ${replies===1?'reply':'replies'}</button>` : ''}
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

async function blogVote(commentId, vote) {
    if (!IS_LOGGED_IN) { openAuthModal(); return; }
    const res  = await apiFetch('api.php?action=blog-comment-vote', {
        method: 'POST',
        body: JSON.stringify({ comment_id: commentId, vote })
    });
    const data = await res.json();
    if (!data.success) return;
    const scoreEl = document.getElementById('score-' + commentId);
    if (scoreEl) scoreEl.textContent = data.vote_score;
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
        toggleBtn.innerHTML = (open ? '\u25BA ' : '\u25BC ') + count + (count===1?' reply':' replies');
        return;
    }
    const res  = await fetch(`${SITE_ROOT}/api.php?action=blog-comments&slug=${encodeURIComponent(POST_SLUG)}&parent_id=${commentId}&offset=0&limit=50`);
    const data = await res.json();
    if (data.comments) {
        container.innerHTML = data.comments.map(c => buildCommentCard(c, 1)).join('');
        container.dataset.loaded = '1';
        toggleBtn.innerHTML = '\u25BC ' + count + (count===1?' reply':' replies');
    }
}

async function postReply(parentId) {
    const input = document.getElementById('reply-input-' + parentId);
    const body  = (input?.value || '').trim();
    if (body.length < 2) return;
    const res  = await apiFetch('api.php?action=blog-comment-post', {
        method: 'POST',
        body: JSON.stringify({ slug: POST_SLUG, body, parent_id: parentId })
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Failed to post reply.'); return; }
    input.value = '';
    document.getElementById('reply-form-' + parentId).style.display = 'none';
    const container = document.getElementById('replies-' + parentId);
    if (container) {
        container.dataset.loaded = '1';
        container.style.display  = '';
        container.insertAdjacentHTML('beforeend', buildCommentCard(data.comment, 1));
    }
    const toggleBtn = document.getElementById('replies-toggle-' + parentId);
    if (toggleBtn) {
        const cur = parseInt(toggleBtn.textContent) || 0;
        const nc  = cur + 1;
        toggleBtn.innerHTML = '\u25BC ' + nc + (nc===1?' reply':' replies');
    }
}

async function loadComments(append) {
    if (isLoadingComments) return;
    isLoadingComments = true;
    if (!append) { commentOffset = 0; document.getElementById('comments-list').innerHTML = ''; }
    const res  = await fetch(`${SITE_ROOT}/api.php?action=blog-comments&slug=${encodeURIComponent(POST_SLUG)}&offset=${commentOffset}&limit=${COMMENTS_PER_PAGE}`);
    const data = await res.json();
    isLoadingComments = false;
    commentTotal = data.total ?? 0;
    const countEl = document.getElementById('comment-count');
    if (countEl) countEl.textContent = `(${commentTotal})`;
    const list = document.getElementById('comments-list');
    if (data.comments && data.comments.length === 0 && !append) {
        list.innerHTML = '<p style="color:var(--muted);font-size:.88rem;padding:.5rem 0">No comments yet — be the first!</p>';
    } else if (data.comments && data.comments.length > 0) {
        list.insertAdjacentHTML('beforeend', data.comments.map(c => buildCommentCard(c, 0)).join(''));
        commentOffset += data.comments.length;
    }
    const lb = document.getElementById('load-more-btn');
    if (lb) lb.style.display = data.has_more ? 'block' : 'none';
}

async function postComment() {
    const input = document.getElementById('comment-input');
    const body  = (input?.value || '').trim();
    const msgEl = document.getElementById('comment-msg');
    if (body.length < 2) return;
    const res  = await apiFetch('api.php?action=blog-comment-post', {
        method: 'POST',
        body: JSON.stringify({ slug: POST_SLUG, body })
    });
    const data = await res.json();
    if (data.success) {
        input.value = '';
        document.getElementById('comment-char').textContent = '0 / 5000';
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
}

window.addEventListener('scroll', () => {
    const lb = document.getElementById('load-more-btn');
    if (lb && lb.style.display !== 'none') {
        if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 300) loadComments(true);
    }
});

// ── Auth modal ─────────────────────────────────────────────────────────────────
function openAuthModal()  { document.getElementById('vs-auth-modal').classList.add('active'); }
function closeAuthModal() { document.getElementById('vs-auth-modal').classList.remove('active'); }
function vsAuthTab(tab) {
    document.getElementById('vs-form-login').style.display    = tab === 'login'    ? '' : 'none';
    document.getElementById('vs-form-register').style.display = tab === 'register' ? '' : 'none';
    document.getElementById('vs-tab-login').classList.toggle('active',    tab === 'login');
    document.getElementById('vs-tab-register').classList.toggle('active', tab === 'register');
}
function vsShowAlert(id, msg, type) {
    var el = document.getElementById(id); el.className = 'vs-alert ' + type; el.textContent = msg;
}
function vsClearAlert(id) { var el = document.getElementById(id); el.className = ''; el.textContent = ''; }
async function vsLogin() {
    vsClearAlert('vs-login-alert');
    var email = document.getElementById('vs-login-email').value.trim();
    var pass  = document.getElementById('vs-login-password').value;
    if (!email || !pass) { vsShowAlert('vs-login-alert', 'Please fill in all fields.', 'error'); return; }
    try {
        var r = await fetch(SITE_ROOT + '/api.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ email, password: pass }), cache: 'no-store'
        });
        var d = await r.json();
        if (d.success) { window.location.reload(); }
        else { vsShowAlert('vs-login-alert', d.error || 'Login failed', 'error'); }
    } catch(e) { vsShowAlert('vs-login-alert', 'Network error.', 'error'); }
}
async function vsRegister() {
    vsClearAlert('vs-register-alert');
    var email = document.getElementById('vs-register-email').value.trim();
    var pass  = document.getElementById('vs-register-password').value;
    if (!email || !pass) { vsShowAlert('vs-register-alert', 'Please fill in all fields.', 'error'); return; }
    try {
        var r = await fetch(SITE_ROOT + '/api.php?action=register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ email, password: pass }), cache: 'no-store'
        });
        var d = await r.json();
        if (d.success) {
            vsShowAlert('vs-register-alert', 'Account created! Signing you in…', 'success');
            setTimeout(() => {
                vsAuthTab('login');
                document.getElementById('vs-login-email').value = email;
                vsShowAlert('vs-login-alert', 'Account created — please sign in.', 'success');
            }, 900);
        } else { vsShowAlert('vs-register-alert', d.error || 'Registration failed', 'error'); }
    } catch(e) { vsShowAlert('vs-register-alert', 'Network error.', 'error'); }
}
async function vsForgotPassword() {
    var email = document.getElementById('vs-login-email').value.trim();
    if (!email) { vsShowAlert('vs-login-alert', 'Enter your email above first.', 'error'); return; }
    try {
        var r = await fetch(SITE_ROOT + '/api.php?action=reset-password-request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ email }), cache: 'no-store'
        });
        var d = await r.json();
        vsShowAlert('vs-login-alert', d.message || 'Reset email sent if account exists.', 'success');
    } catch(e) { vsShowAlert('vs-login-alert', 'Network error.', 'error'); }
}
function vsGoogleLogin() {
    window.location.href = SITE_ROOT + '/auth-google.php?action=login&return=' + encodeURIComponent(window.location.href);
}

// ── Init ──────────────────────────────────────────────────────────────────────
loadLikes();
loadComments(false);
</script>
</body>
</html>