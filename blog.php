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

<link rel="stylesheet" href="<?= esc(SITE_URL) ?>/assets/css/theme.css">
<style>
/* ── Page layout ─────────────────────────────────────────────────── */
body { background: var(--bg-primary); color: var(--text-primary); font-family: var(--font-sans); }

.topbar { position: sticky; top: 0; z-index: var(--z-sticky); display: flex; align-items: center; gap: 1rem; padding: 0 1.25rem; height: var(--nav-h); background: var(--bg-primary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(8px); }
.topbar-back { color: var(--color-accent); text-decoration: none; font-weight: 600; font-size: .9rem; flex-shrink: 0; display: inline-flex; align-items: center; gap: .35rem; }
.topbar-back:hover { color: var(--color-accent-dark); }
.topbar-title { font-weight: 700; font-size: 1rem; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-primary); }

.post-wrap { max-width: 780px; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
.post-cover { width: 100%; max-height: 380px; object-fit: cover; border-radius: var(--radius); margin-bottom: 2rem; display: block; }
.post-title { font-size: clamp(1.5rem, 4vw, 2.2rem); font-weight: 800; line-height: 1.25; margin-bottom: 1rem; color: var(--text-primary); }
.post-meta { font-size: .85rem; color: var(--text-muted); margin-bottom: 2rem; display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; }

/* Share / like bar — mirrors .blog-interactions from index.php */
.post-actions { display: flex; align-items: center; gap: 1rem; padding: 1.2rem 0; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); margin: 2rem 0 1.5rem; flex-wrap: wrap; }
.blog-like-wrap { position: relative; display: inline-flex; }
.blog-like-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .9rem; border: 1px solid var(--border-color); border-radius: var(--radius); background: none; color: var(--text-primary); font-size: .9rem; cursor: pointer; transition: border-color .15s, color .15s; font-family: var(--font-sans); }
.blog-like-btn:hover { border-color: var(--color-accent); color: var(--color-accent); }
.blog-like-btn.liked { border-color: var(--color-accent); color: var(--color-accent); background: rgba(var(--accent-rgb), .08); }
.blog-like-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
.blog-like-tooltip { position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: .3rem .65rem; font-size: .8rem; white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 10; color: var(--text-primary); }
.blog-like-wrap:hover .blog-like-tooltip { opacity: 1; }

/* Post body content */
.post-content { font-size: 1rem; line-height: 1.8; color: var(--text-primary); }
.post-content h1, .post-content h2, .post-content h3, .post-content h4 { margin: 2rem 0 .75rem; line-height: 1.3; }
.post-content p { margin-bottom: 1.15rem; }
.post-content ul, .post-content ol { margin: 0 0 1.15rem 1.5rem; }
.post-content li { margin-bottom: .4rem; }
.post-content blockquote { border-left: 3px solid var(--color-accent); padding: .5rem 1rem; margin: 1.5rem 0; background: var(--bg-secondary); border-radius: 0 var(--radius-sm) var(--radius-sm) 0; color: var(--text-muted); font-style: italic; }
.post-content pre { background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1rem; overflow-x: auto; margin-bottom: 1.15rem; }
.post-content code { background: var(--bg-tertiary); padding: .15rem .35rem; border-radius: 3px; font-family: var(--font-mono); font-size: .9em; }
.post-content pre code { background: none; padding: 0; }
.post-content img { max-width: 100%; border-radius: var(--radius); margin: 1rem 0; }
.post-content a { color: var(--color-accent); }
.post-content hr { border: none; border-top: 1px solid var(--border-color); margin: 2rem 0; }
.post-content table { width: 100%; border-collapse: collapse; margin-bottom: 1.15rem; }
.post-content th, .post-content td { border: 1px solid var(--border-color); padding: .5rem .75rem; text-align: left; }
.post-content th { background: var(--bg-tertiary); font-weight: 600; }

.section-divider { border: none; border-top: 1px solid var(--border-color); margin: 2.5rem 0; }

/* Comments section header */
.comments-section h2 { font-size: 1rem; font-weight: 600; margin-bottom: 1.25rem; color: var(--text-primary); }

/* Comment form — matches .blog-comment-form in index.php */
.blog-comment-form { display: flex; flex-direction: column; gap: .65rem; margin-bottom: 2rem; }
.blog-comment-form textarea { width: 100%; min-height: 90px; padding: .65rem .75rem; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-family: var(--font-sans); font-size: .9rem; resize: vertical; }
.blog-comment-form textarea:focus { outline: none; border-color: var(--color-accent); }
.blog-comment-form-actions { display: flex; gap: .65rem; align-items: center; }

/* Guest CTA */
.blog-cta-login { margin-bottom: 1.25rem; font-size: .9rem; color: var(--text-muted); }
.blog-cta-login button { background: none; border: none; color: var(--color-accent); font-size: inherit; cursor: pointer; font-weight: 600; padding: 0; }

/* bc-* comment classes — exact copy from index.php */
.bc-row  { display: flex; min-width: 0; margin-bottom: .75rem; }
.bc-main { flex: 1; min-width: 0; }
.bc-avatar { width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; background: var(--color-accent); color: #0a0a09; display: inline-flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; overflow: hidden; vertical-align: middle; }
.bc-avatar img { width: 100%; height: 100%; object-fit: cover; }
.bc-meta   { display: flex; align-items: center; gap: .45rem; margin-bottom: .3rem; flex-wrap: wrap; }
.bc-author { font-size: .82rem; font-weight: 700; color: var(--text-primary); }
.bc-date   { font-size: .75rem; color: var(--text-muted); }
.bc-text   { font-size: .88rem; line-height: 1.65; word-break: break-word; white-space: pre-wrap; color: var(--text-primary); margin-bottom: .5rem; }
.bc-vote-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .4rem; }
.bc-vote-up, .bc-vote-dn { display: inline-flex; align-items: center; gap: .35rem; padding: .28rem .7rem; border-radius: 20px; font-size: .8rem; font-weight: 600; cursor: pointer; border: 1.5px solid; line-height: 1; background: transparent; transition: background .12s, opacity .12s, transform .1s; }
.bc-vote-up:active, .bc-vote-dn:active { transform: scale(.93); }
.bc-vote-up { border-color: #28a745; color: #28a745; }
.bc-vote-up:hover { background: rgba(40,167,69,.08); }
.bc-vote-up.active { background: #28a745; color: #fff; }
.bc-vote-dn { border-color: #dc3545; color: #dc3545; }
.bc-vote-dn:hover { background: rgba(220,53,69,.08); }
.bc-vote-dn.active { background: #dc3545; color: #fff; }
[data-theme="dark"] .bc-vote-up              { border-color: rgba(255,255,255,.5); color: rgba(255,255,255,.85); }
[data-theme="dark"] .bc-vote-up:hover        { border-color: #fff; color: #fff; background: rgba(255,255,255,.07); }
[data-theme="dark"] .bc-vote-up.active       { border-color: var(--color-accent); color: var(--color-accent); background: rgba(212,160,23,.12); }
[data-theme="dark"] .bc-vote-dn              { border-color: rgba(255,255,255,.5); color: rgba(255,255,255,.85); }
[data-theme="dark"] .bc-vote-dn:hover        { border-color: #fff; color: #fff; background: rgba(255,255,255,.07); }
[data-theme="dark"] .bc-vote-dn.active       { border-color: var(--color-accent); color: var(--color-accent); background: rgba(212,160,23,.12); }
.bc-score     { font-size: .8rem; font-weight: 700; color: var(--text-muted); min-width: 16px; text-align: center; line-height: 1; }
.bc-score.pos { color: #28a745; }
.bc-score.neg { color: #dc3545; }
[data-theme="dark"] .bc-score.pos { color: var(--color-accent); }
[data-theme="dark"] .bc-score.neg { color: var(--color-accent); }
.bc-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: .25rem; }
.bc-actions button { background: none; border: none; color: var(--text-muted); font-size: .75rem; font-weight: 600; cursor: pointer; padding: 2px 4px; border-radius: 3px; transition: color .12s, background .12s; font-family: var(--font-sans); }
.bc-actions button:hover { color: var(--text-primary); background: var(--bg-tertiary); }
.bc-actions .bc-collapse-btn.collapsed { color: var(--color-accent); }
.blog-reply-form { display: flex; flex-direction: column; gap: .5rem; }
.blog-reply-form textarea { min-height: 72px; width: 100%; padding: .5rem .65rem; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-family: var(--font-sans); font-size: .88rem; resize: vertical; }
.blog-reply-form textarea:focus { outline: none; border-color: var(--color-accent); }
.blog-reply-form-actions { display: flex; gap: .5rem; }
.bc-reply-host .blog-reply-form { margin-top: .6rem; }
.bc-children { margin-top: .5rem; margin-left: 10px; padding-left: 10px; border-left: 2px solid var(--border-color); }
.bc-children[data-depth="1"] { border-color: #4a9eff44; }
.bc-children[data-depth="2"] { border-color: #ff914444; }
.bc-children[data-depth="3"] { border-color: #a78bfa44; }
.bc-children[data-depth="4"] { border-color: #34d39944; }
.bc-children.collapsed { display: none; }
.bc-collapsed-pill { display: none; font-size: .75rem; color: var(--text-muted); margin: .3rem 0 .3rem 20px; cursor: pointer; font-weight: 600; }
.bc-collapsed-pill:hover { color: var(--color-accent); }
.bc-collapsed-pill.visible { display: block; }
.blog-load-more-btn { background: none; border: 1px solid var(--border-color); border-radius: var(--radius); color: var(--text-muted); font-size: .78rem; padding: .35rem .8rem; cursor: pointer; margin-top: .4rem; transition: border-color .15s, color .15s; font-family: var(--font-sans); }
.blog-load-more-btn:hover { border-color: var(--color-accent); color: var(--color-accent); }

/* btn utility classes (subset of index.php) */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; padding: .5rem 1.1rem; border-radius: var(--radius); border: none; font-size: .88rem; font-weight: 600; cursor: pointer; transition: background .15s, color .15s, border-color .15s; font-family: var(--font-sans); line-height: 1.4; }
.btn-gold    { background: var(--color-accent); color: #0a0a09; border: 1px solid transparent; }
.btn-gold:hover { background: var(--color-accent-dark); }
.btn-outline { background: transparent; color: var(--text-primary); border: 1px solid var(--border-color); }
.btn-outline:hover { border-color: var(--color-accent); color: var(--color-accent); }

/* Auth modal */
.vs-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; z-index: var(--z-modal); opacity: 0; pointer-events: none; transition: opacity .2s; padding: 1rem; }
.vs-modal-bg.active { opacity: 1; pointer-events: all; }
.vs-modal { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; width: 100%; max-width: 420px; position: relative; }
.vs-modal-close { position: absolute; top: .75rem; right: .75rem; background: none; border: none; color: var(--text-muted); font-size: 1.4rem; cursor: pointer; line-height: 1; }
.vs-modal-close:hover { color: var(--text-primary); }
.vs-modal h2 { text-align: center; margin: 0 0 1.75rem; font-size: 1.5rem; color: var(--text-primary); }
.vs-auth-tabs { display: flex; border-bottom: 2px solid var(--border-color); margin-bottom: 1.75rem; }
.vs-auth-tab { flex: 1; padding: .85rem; background: none; border: none; color: var(--text-muted); font-size: .95rem; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all .2s; font-family: var(--font-sans); }
.vs-auth-tab.active { color: var(--text-primary); border-bottom-color: var(--color-accent); }
.vs-form-group { margin-bottom: 1rem; }
.vs-form-group label { display: block; font-size: .85rem; color: var(--text-muted); margin-bottom: .35rem; }
.vs-form-group input { width: 100%; padding: .65rem .75rem; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: .95rem; font-family: var(--font-sans); }
.vs-form-group input:focus { outline: none; border-color: var(--color-accent); }
.vs-btn-full { width: 100%; padding: .9rem; background: var(--color-accent); color: #0a0a09; border: none; border-radius: var(--radius); font-weight: 700; font-size: .95rem; cursor: pointer; font-family: var(--font-sans); }
.vs-btn-full:hover { background: var(--color-accent-dark); }
.vs-divider { display: flex; align-items: center; gap: .75rem; margin: 1.25rem 0; color: var(--text-muted); font-size: .85rem; }
.vs-divider::before, .vs-divider::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }
.vs-google-btn { width: 100%; padding: .7rem; background: #fff; color: #444; border: 1px solid #ddd; border-radius: var(--radius); font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: .65rem; font-size: .9rem; font-family: var(--font-sans); }
.vs-google-btn:hover { background: #f5f5f5; }
.vs-alert { padding: .6rem .85rem; border-radius: var(--radius-sm); font-size: .85rem; margin-bottom: 1rem; }
.vs-alert.error   { background: rgba(220,53,69,.12); color: #f87171; border: 1px solid rgba(220,53,69,.25); }
.vs-alert.success { background: rgba(34,197,94,.12); color: #4ade80; border: 1px solid rgba(34,197,94,.25); }
.vs-forgot { text-align: right; margin-bottom: 1rem; }
.vs-forgot a { color: var(--color-accent); font-size: .85rem; text-decoration: none; }
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

    <!-- Post actions bar — matches .blog-interactions in index.php -->
    <div class="post-actions">
        <div class="blog-like-wrap">
            <button class="blog-like-btn" id="like-btn" onclick="toggleLike()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <span id="like-label">Like</span>
            </button>
            <span class="blog-like-tooltip" id="like-tooltip">0 likes</span>
        </div>
        <button class="blog-like-btn" onclick="copyShareLink()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            <span id="share-label">Share</span>
        </button>
    </div>

    <!-- Post body -->
    <div class="post-content" id="post-content">
        <?= $post['content'] ?? '' ?>
    </div>

    <hr class="section-divider">

    <!-- Comments -->
    <div class="comments-section">
        <h2 id="comment-count-label">Comments</h2>

        <div id="blog-new-comment-area">
        <?php if ($isLoggedIn): ?>
            <div class="blog-comment-form">
                <textarea id="blog-new-comment-text" placeholder="Write a comment..."></textarea>
                <div class="blog-comment-form-actions">
                    <button class="btn btn-gold" style="font-size:.85rem;padding:.4rem 1rem" onclick="postBlogComment(null)">Post</button>
                </div>
            </div>
        <?php else: ?>
            <p class="blog-cta-login" style="margin-bottom:1.25rem">
                <button onclick="openAuthModal()">Login</button> to join the conversation.
            </p>
        <?php endif; ?>
        </div>

        <div id="blog-comment-list"></div>
        <div id="blog-comments-loadmore"></div>
    </div>
</div>

<!-- Auth modal -->
<div id="vs-auth-modal" class="vs-modal-bg" onclick="if(event.target===this)closeAuthModal()">
  <div class="vs-modal">
    <button class="vs-modal-close" onclick="closeAuthModal()">&#215;</button>
    <h2>Login / Sign Up</h2>
    <div class="vs-auth-tabs">
      <button class="vs-auth-tab active" id="vs-tab-login" onclick="vsAuthTab('login')">Sign In</button>
      <button class="vs-auth-tab" id="vs-tab-register" onclick="vsAuthTab('register')">Register</button>
    </div>
    <div id="vs-form-login">
      <div id="vs-login-alert"></div>
      <div class="vs-form-group"><label>Email</label>
        <input type="email" id="vs-login-email" placeholder="your@email.com" autocomplete="email">
      </div>
      <div class="vs-form-group"><label>Password</label>
        <input type="password" id="vs-login-password" placeholder="&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;" autocomplete="current-password">
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
const POST_SLUG   = <?= json_encode($slug) ?>;
const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
const SITE_ROOT   = <?= json_encode(rtrim(SITE_URL, '/')) ?>;
const currentUser = <?= $currentUser ? json_encode(['id'=>$currentUser['id'],'display_name'=>$currentUser['display_name']??null,'avatar_url'=>$currentUser['avatar_url']??null,'role'=>$currentUser['role']??'user','is_admin'=>!empty($currentUser['is_admin'])], JSON_HEX_TAG|JSON_HEX_APOS) : 'null' ?>;

const BLOG_COMMENTS_PER_PAGE = 10;
const BLOG_REPLIES_PER_BATCH = 7;
let _blogCommentsOffset = 0;

function _esc(s){if(!s)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

function copyShareLink(){const url=window.location.href;const lbl=document.getElementById('share-label');navigator.clipboard.writeText(url).then(()=>{lbl.textContent='Copied!';setTimeout(()=>{lbl.textContent='Share';},2000);}).catch(()=>{prompt('Copy this link:',url);});}

async function loadLikes(){try{const r=await fetch(SITE_ROOT+'/api.php?action=blog-like-count&slug='+encodeURIComponent(POST_SLUG));const d=await r.json();_applyLikeState(d.liked??false,d.count??0);}catch(e){}}
function _applyLikeState(liked,count){const btn=document.getElementById('like-btn');const lbl=document.getElementById('like-label');const tip=document.getElementById('like-tooltip');if(btn)btn.classList.toggle('liked',liked);if(lbl)lbl.textContent=liked?'Liked':'Like';if(tip)tip.textContent=count+(count===1?' like':' likes');}
async function toggleLike(){if(!IS_LOGGED_IN){openAuthModal();return;}try{const r=await apiFetch('api.php?action=blog-like',{method:'POST',body:JSON.stringify({slug:POST_SLUG})});const d=await r.json();_applyLikeState(d.liked,d.count??0);}catch(e){}}

function _makeSVGBtn(cls,onclickStr,paths){const NS='http://www.w3.org/2000/svg';const btn=document.createElement('button');btn.className=cls;btn.setAttribute('onclick',onclickStr);const svg=document.createElementNS(NS,'svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('fill','none');svg.style.cssText='width:18px;height:18px;pointer-events:none;display:block';paths.forEach(p=>{const el=document.createElementNS(NS,'path');el.setAttribute('d',p.d);el.setAttribute('stroke','currentColor');el.setAttribute('stroke-width','2');el.setAttribute('stroke-linecap','round');el.setAttribute('stroke-linejoin','round');if(p.fill)el.setAttribute('fill','currentColor');svg.appendChild(el);});btn.appendChild(svg);return btn;}
const _LIKE_PATHS=[{d:'M7 10C7 10 7 4 11 2C11 2 12 2 12 4C12 6 11 9 11 9H18C19.1 9 20 9.9 20 11V13C20 13.4 19.9 13.7 19.7 14L18.1 19.2C17.8 20.3 16.8 21 15.7 21H7'},{d:'M7 21H3C2.4 21 2 20.6 2 20V11C2 10.4 2.4 10 3 10H7V21Z',fill:true}];
const _DISLIKE_PATHS=[{d:'M7 14C7 14 7 20 11 22C11 22 12 22 12 20C12 18 11 15 11 15H18C19.1 15 20 14.1 20 13V11C20 10.6 19.9 10.3 19.7 10L18.1 4.8C17.8 3.7 16.8 3 15.7 3H7'},{d:'M7 3H3C2.4 3 2 3.4 2 4V13C2 13.6 2.4 14 3 14H7V3Z',fill:true}];

function _buildCommentEl(c,depth){depth=depth||1;const wrap=document.createElement('div');wrap.className='bc-wrap';wrap.dataset.commentId=c.id;const dateStr=new Date(c.created_at).toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});const score=c.vote_score||0;const userVote=c.user_vote||0;const scoreClass=score>0?'pos':score<0?'neg':'';const isAdmin=currentUser&&(currentUser.is_admin||currentUser.role==='admin');const avatarInner=c.avatar_url?`<img src="${_esc(c.avatar_url)}" alt="">`:_esc((c.display_name||'A').charAt(0).toUpperCase());const collapseBtn=c.reply_count>0?`<button class="bc-collapse-btn" onclick="_toggleCollapseChildren(${c.id}, this)">[ - ] Hide replies</button>`:'';const showRepliesBtn=c.reply_count>0?`<button class="blog-load-more-btn" style="margin-top:.5rem" onclick="_handleShowReplies(${c.id}, this, ${c.reply_count}, 0)">Show ${Math.min(c.reply_count,BLOG_REPLIES_PER_BATCH)} repl${c.reply_count===1?'y':'ies'} (${c.reply_count} total)</button>`:'';const adminBtn=isAdmin?`<button onclick="_adminDeleteComment(${c.id}, this)">Delete</button>`:'';wrap.innerHTML=`<div class="bc-row"><div class="bc-main"><div class="bc-meta"><span class="bc-avatar">${avatarInner}</span><span class="bc-author">${_esc(c.display_name||'Anonymous')}</span><span class="bc-date">${dateStr}</span></div><div class="bc-text">${_esc(c.body)}</div><div class="bc-vote-row" id="bc-voterow-${c.id}"><span class="bc-score ${scoreClass}" id="bc-score-${c.id}">${score}</span></div><div class="bc-actions"><button onclick="_toggleReplyForm(${c.id}, this)">Reply</button>${collapseBtn}${adminBtn}</div><div class="bc-reply-host"></div>${showRepliesBtn}<div class="bc-children" id="bc-children-${c.id}" data-depth="${depth}"></div></div></div><div class="bc-collapsed-pill" id="bc-pill-${c.id}" onclick="_reexpandChildren(${c.id})"></div>`;const voteRow=wrap.querySelector('#bc-voterow-'+c.id);const scoreEl=wrap.querySelector('#bc-score-'+c.id);voteRow.insertBefore(_makeSVGBtn('bc-vote-up'+(userVote===1?' active':''),'toggleCommentVote('+c.id+', 1, this)',_LIKE_PATHS),scoreEl);voteRow.appendChild(_makeSVGBtn('bc-vote-dn'+(userVote===-1?' active':''),'toggleCommentVote('+c.id+', -1, this)',_DISLIKE_PATHS));return wrap;}

async function toggleCommentVote(commentId,direction,btn){if(!currentUser){openAuthModal();return;}const scoreEl=document.getElementById('bc-score-'+commentId);const voteRow=btn.closest('.bc-vote-row');const upBtn=voteRow.querySelector('.bc-vote-up');const dnBtn=voteRow.querySelector('.bc-vote-dn');const current=upBtn.classList.contains('active')?1:dnBtn.classList.contains('active')?-1:0;const newVote=(current===direction)?0:direction;try{const r=await apiFetch('api.php?action=blog-comment-vote',{method:'POST',body:JSON.stringify({comment_id:commentId,vote:newVote})});const d=await r.json();if(!d.success)return;upBtn.classList.toggle('active',d.user_vote===1);dnBtn.classList.toggle('active',d.user_vote===-1);scoreEl.textContent=d.vote_score;scoreEl.className='bc-score'+(d.vote_score>0?' pos':d.vote_score<0?' neg':'');}catch(e){}}

function _toggleCollapseChildren(commentId,btn){const children=document.getElementById('bc-children-'+commentId);const pill=document.getElementById('bc-pill-'+commentId);if(!children)return;const collapsing=!children.classList.contains('collapsed');if(collapsing){children.classList.add('collapsed');const count=children.querySelectorAll(':scope > .bc-wrap').length;pill.textContent='[ + ] '+count+' repl'+(count===1?'y':'ies')+' collapsed';pill.classList.add('visible');btn.textContent='[ + ] Show replies';btn.classList.add('collapsed');}else{_reexpandChildren(commentId);}}
function _reexpandChildren(commentId){const children=document.getElementById('bc-children-'+commentId);const pill=document.getElementById('bc-pill-'+commentId);const btn=document.querySelector('[onclick*="_toggleCollapseChildren('+commentId+',"])');if(children)children.classList.remove('collapsed');if(pill)pill.classList.remove('visible');if(btn){btn.textContent='[ - ] Hide replies';btn.classList.remove('collapsed');}}

function _toggleReplyForm(commentId,btn){const main=btn.closest('.bc-main');const host=main.querySelector('.bc-reply-host');if(host.querySelector('textarea')){host.innerHTML='';return;}if(!currentUser){openAuthModal();return;}host.innerHTML=`<div class="blog-reply-form"><textarea placeholder="Write a reply..." id="reply-text-${commentId}"></textarea><div class="blog-reply-form-actions"><button class="btn btn-gold" style="font-size:.82rem;padding:.35rem .85rem" onclick="postBlogComment(${commentId})">Reply</button><button class="btn btn-outline" style="font-size:.82rem;padding:.35rem .75rem" onclick="this.closest('.blog-reply-form').parentElement.innerHTML=''">Cancel</button></div></div>`;document.getElementById('reply-text-'+commentId).focus();}

async function _handleShowReplies(parentId,btn,totalReplies,currentOffset){const r=await fetch(SITE_ROOT+'/api.php?action=blog-comments&slug='+encodeURIComponent(POST_SLUG)+'&parent_id='+parentId+'&offset='+currentOffset+'&limit='+BLOG_REPLIES_PER_BATCH);const d=await r.json();const childrenEl=document.getElementById('bc-children-'+parentId);if(!childrenEl)return;const parentDepth=parseInt(childrenEl.dataset.depth)||1;d.comments.forEach(c=>childrenEl.appendChild(_buildCommentEl(c,parentDepth+1)));const loaded=currentOffset+d.comments.length;if(d.has_more){const remaining=d.total-loaded;const nextLoad=Math.min(remaining,BLOG_REPLIES_PER_BATCH);btn.textContent='Show '+nextLoad+' more repl'+(nextLoad===1?'y':'ies')+' ('+remaining+' remaining)';btn.setAttribute('onclick','_handleShowReplies('+parentId+', this, '+d.total+', '+loaded+')');}else{btn.remove();}}

async function postBlogComment(parentId){if(!currentUser){openAuthModal();return;}const textareaId=parentId?'reply-text-'+parentId:'blog-new-comment-text';const ta=document.getElementById(textareaId);if(!ta)return;const body=ta.value.trim();if(!body){ta.focus();return;}try{const r=await apiFetch('api.php?action=blog-comment-post',{method:'POST',body:JSON.stringify({slug:POST_SLUG,body,parent_id:parentId})});const d=await r.json();if(!d.success){alert(d.error||'Failed to post comment');return;}if(parentId===null){const list=document.getElementById('blog-comment-list');list.insertBefore(_buildCommentEl(d.comment,1),list.firstChild);ta.value='';const lbl=document.getElementById('comment-count-label');if(lbl){const cur=parseInt(lbl.textContent)||0;lbl.textContent=(cur+1)+' Comments';}}else{const childrenEl=document.getElementById('bc-children-'+parentId);if(childrenEl){const parentDepth=parseInt(childrenEl.dataset.depth)||1;childrenEl.appendChild(_buildCommentEl(d.comment,parentDepth+1));childrenEl.classList.remove('collapsed');document.getElementById('bc-pill-'+parentId)?.classList.remove('visible');}const host=document.getElementById('reply-text-'+parentId)?.closest('.bc-reply-host');if(host)host.innerHTML='';}}catch(e){alert('Network error, please try again');}}

async function _adminDeleteComment(commentId,btn){if(!confirm('Delete this comment?'))return;try{const r=await apiFetch('api.php?action=admin-blog-delete-comment',{method:'POST',body:JSON.stringify({id:commentId})});const d=await r.json();if(d.success)btn.closest('.bc-wrap')?.remove();}catch(e){}}

async function _loadTopLevelComments(append){const r=await fetch(SITE_ROOT+`/api.php?action=blog-comments&slug=${encodeURIComponent(POST_SLUG)}&offset=${_blogCommentsOffset}&limit=${BLOG_COMMENTS_PER_PAGE}`);const d=await r.json();const list=document.getElementById('blog-comment-list');if(!append)list.innerHTML='';d.comments.forEach(c=>list.appendChild(_buildCommentEl(c,false)));_blogCommentsOffset+=d.comments.length;const lbl=document.getElementById('comment-count-label');if(lbl)lbl.textContent=d.total===1?'1 Comment':`${d.total} Comments`;const lm=document.getElementById('blog-comments-loadmore');lm.innerHTML=d.has_more?'<button class="blog-load-more-btn" onclick="_loadTopLevelComments(true)">Load more comments</button>':'' ;}

function openAuthModal(){document.getElementById('vs-auth-modal').classList.add('active');}
function closeAuthModal(){document.getElementById('vs-auth-modal').classList.remove('active');}
function vsAuthTab(tab){document.getElementById('vs-form-login').style.display=tab==='login'?'':'none';document.getElementById('vs-form-register').style.display=tab==='register'?'':'none';document.getElementById('vs-tab-login').classList.toggle('active',tab==='login');document.getElementById('vs-tab-register').classList.toggle('active',tab==='register');}
function vsShowAlert(id,msg,type){var el=document.getElementById(id);el.className='vs-alert '+type;el.textContent=msg;}
function vsClearAlert(id){var el=document.getElementById(id);el.className='';el.textContent='';}
async function vsLogin(){vsClearAlert('vs-login-alert');var email=document.getElementById('vs-login-email').value.trim();var pass=document.getElementById('vs-login-password').value;if(!email||!pass){vsShowAlert('vs-login-alert','Please fill in all fields.','error');return;}try{var r=await fetch(SITE_ROOT+'/api.php?action=login',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({email,password:pass}),cache:'no-store'});var d=await r.json();if(d.success){window.location.reload();}else{vsShowAlert('vs-login-alert',d.error||'Login failed','error');}}catch(e){vsShowAlert('vs-login-alert','Network error.','error');}}
async function vsRegister(){vsClearAlert('vs-register-alert');var email=document.getElementById('vs-register-email').value.trim();var pass=document.getElementById('vs-register-password').value;if(!email||!pass){vsShowAlert('vs-register-alert','Please fill in all fields.','error');return;}try{var r=await fetch(SITE_ROOT+'/api.php?action=register',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({email,password:pass}),cache:'no-store'});var d=await r.json();if(d.success){vsShowAlert('vs-register-alert','Account created! Signing you in…','success');setTimeout(()=>{vsAuthTab('login');document.getElementById('vs-login-email').value=email;vsShowAlert('vs-login-alert','Account created — please sign in.','success');},900);}else{vsShowAlert('vs-register-alert',d.error||'Registration failed','error');}}catch(e){vsShowAlert('vs-register-alert','Network error.','error');}}
async function vsForgotPassword(){var email=document.getElementById('vs-login-email').value.trim();if(!email){vsShowAlert('vs-login-alert','Enter your email above first.','error');return;}try{var r=await fetch(SITE_ROOT+'/api.php?action=reset-password-request',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({email}),cache:'no-store'});var d=await r.json();vsShowAlert('vs-login-alert',d.message||'Reset email sent if account exists.','success');}catch(e){vsShowAlert('vs-login-alert','Network error.','error');}}
function vsGoogleLogin(){window.location.href=SITE_ROOT+'/auth-google.php?action=login&return='+encodeURIComponent(window.location.href);}

loadLikes();
_loadTopLevelComments(false);
</script>
</body>
</html>