<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
startSession();
$currentUser = getCurrentUser();
$csrfToken   = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <base href="/">

  <!-- Primary SEO ─────────────────────────────────────────────────────────── -->
  <title>Interactive Physics Simulations — Akilesh Bhandary, Independent Researcher</title>
  <meta name="description" content="Explore interactive physics and mathematics simulations by Akilesh Ravindra Bhandary — orbital mechanics, pendulums, gravity, fluid dynamics and more.">
  <link rel="canonical" href="<?php echo esc(SITE_URL); ?>/">

  <!-- Open Graph ──────────────────────────────────────────────────────────── -->
  <meta property="og:type"        content="website">
  <meta property="og:url"         content="<?php echo esc(SITE_URL); ?>/">
  <meta property="og:title"       content="Interactive Physics Simulations — Akilesh Bhandary">
  <meta property="og:description" content="Explore interactive physics and mathematics simulations — orbital mechanics, pendulums, gravity, fluid dynamics and more.">
  <meta property="og:image"       content="<?php echo esc(SITE_URL); ?>/assets/og-image.jpg">
  <meta property="og:site_name"   content="<?php echo esc(SITE_NAME); ?>">
  <meta property="og:locale"      content="en_IN">

  <!-- Twitter Card ────────────────────────────────────────────────────────── -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:title"       content="Interactive Physics Simulations — Akilesh Bhandary">
  <meta name="twitter:description" content="Explore interactive physics and mathematics simulations — orbital mechanics, pendulums, gravity, fluid dynamics and more.">
  <meta name="twitter:image"       content="<?php echo esc(SITE_URL); ?>/assets/og-image.jpg">

  <!-- Google Search Console verification — replace after verifying ownership -->
  <!-- <meta name="google-site-verification" content="REPLACE_WITH_GSC_CODE"> -->

  <!-- CSRF token read by fetch() calls ─────────────────────────────────────── -->
  <meta name="csrf-token" content="<?php echo esc($csrfToken); ?>">

  <!-- Schema.org Person ───────────────────────────────────────────────────── -->
  <script type="application/ld+json">
  {"@context":"https://schema.org","@type":"Person","name":"Akilesh Ravindra Bhandary","url":"<?php echo esc(SITE_URL); ?>/about","jobTitle":"Independent Researcher","description":"Independent researcher creating interactive physics and mathematics simulations.","email":"bhandaryaki@gmail.com"}
  </script>

  <!-- Schema.org WebSite ──────────────────────────────────────────────────── -->
  <script type="application/ld+json">
  {"@context":"https://schema.org","@type":"WebSite","name":"<?php echo esc(SITE_NAME); ?>","url":"<?php echo esc(SITE_URL); ?>/","description":"Interactive physics and mathematics simulations by Akilesh Ravindra Bhandary.","author":{"@type":"Person","name":"Akilesh Ravindra Bhandary"}}
  </script>
<script>
// Apply theme immediately before paint to prevent flash
(function(){
  var t = localStorage.getItem('ir_theme');
  if (!t) { var h = new Date().getHours(); t = (h>=6&&h<18)?'light':'dark'; }
  document.documentElement.setAttribute('data-theme', t);
  document.documentElement.classList.add('theme-'+t);
})();
</script>

  <!-- Osano cookieconsent -->
  <link rel="stylesheet" href="cookieconsent.php?type=css">
  <script src="cookieconsent.php?type=js"></script>
  <style>
  /* Override Osano defaults to match site theme */
  .cc-window {
    font-family: 'Segoe UI', system-ui, sans-serif !important;
    font-size: 0.88rem !important;
    border-top: 1px solid #2d3748 !important;
  }
  .cc-window.cc-banner {
    background: #1a1d23 !important;
    color: #adb5bd !important;
    padding: 1rem 1.5rem !important;
    align-items: center !important;
  }
  .cc-message { color: #adb5bd !important; font-size: .88rem !important; }
  .cc-link, .cc-link:visited { color: #f0c040 !important; opacity: 1 !important; }
  .cc-link:hover { color: #d4a017 !important; text-decoration: underline !important; }
  .cc-btn {
    border-radius: 6px !important;
    font-size: .82rem !important;
    font-weight: 600 !important;
    padding: .45rem 1.1rem !important;
    white-space: nowrap !important;
  }
  .cc-btn.cc-allow {
    background: #f0c040 !important;
    border-color: #f0c040 !important;
    color: #0a0a09 !important;
  }
  .cc-btn.cc-allow:hover { background: #d4a017 !important; border-color: #d4a017 !important; }
  .cc-btn.cc-deny {
    background: transparent !important;
    border-color: #4a5568 !important;
    color: #adb5bd !important;
  }
  .cc-btn.cc-deny:hover { border-color: #718096 !important; color: #e2e8f0 !important; }
  .cc-highlight .cc-btn:first-child { color: #adb5bd !important; }
  @media (max-width: 640px) {
    .cc-window.cc-banner { flex-direction: column !important; align-items: flex-start !important; }
    .cc-compliance { margin-top: .75rem !important; }
    .cc-btn { padding: .6rem 1rem !important; }
  }
  </style>
  <script>
  // Initialise Osano cookieconsent
  // Type "opt-in": user must explicitly ALLOW optional cookies.
  // Declining has zero effect on site functionality:
  //   - PHPSESSID (session): strictly necessary, always set by PHP, not controlled here.
  //   - ir_theme / ir_cookie_consent: localStorage, not cookies, no consent needed.
  //   - Razorpay: third-party script already loaded; preference stored for future dynamic loading.
  window.addEventListener('load', function() {
    if (typeof window.cookieconsent === 'undefined') return;
    window.cookieconsent.initialise({
      palette: {
        popup:  { background: '#1a1d23', text: '#adb5bd', link: '#f0c040' },
        button: { background: '#f0c040', text: '#0a0a09', border: '#f0c040' },
        highlight: { background: 'transparent', text: '#adb5bd', border: '#4a5568' }
      },
      type: 'opt-in',
      position: 'bottom',
      layout: 'basic',
      content: {
        message: 'We use a strictly necessary session cookie to keep you logged in, and an optional Razorpay cookie for fraud detection during payments. Declining has no effect on any site feature.',
        allow:   'Accept optional',
        deny:    'Necessary only',
        link:    'Learn more',
        href:    "/terms"
      },
      onStatusChange: function(status) {
        // status === 'allow'  -> user accepted optional cookies
        // status === 'deny'   -> user declined optional cookies
        // Nothing functional changes either way — session cookie is server-side
        // and cannot be blocked from JS. Razorpay is already loaded.
        // Store in localStorage as well so other code can read it.
        if (typeof localStorage !== 'undefined') {
          localStorage.setItem('ir_cookie_consent', status === 'allow' ? 'accepted' : 'declined');
        }
      },
      onInitialise: function(status) {
        if (typeof localStorage !== 'undefined') {
          localStorage.setItem('ir_cookie_consent', status === 'allow' ? 'accepted' : 'declined');
        }
      }
    });
  });
  </script>

  <link rel="stylesheet" href="assets/css/theme.css">
<style>
/* ── Reset & Base ─────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { 
  font-family: 'Segoe UI', system-ui, sans-serif; 
  background: var(--bg-primary); 
  color: var(--text-primary); 
  min-height: 100vh; 
  transition: background-color 0.2s ease, color 0.2s ease;
}
a { color: var(--color-accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── Nav ──────────────────────────────────────────────────────── */
nav {
  background: rgba(13,17,23,0.95);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border-color);
  height: var(--nav-h);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 2rem;
  position: sticky; top: 0; z-index: 50;
}
.brand { font-size: 1rem; font-weight: 700; color: var(--text-primary); letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; }
.brand span { color: var(--color-accent); }
.nav-links { display: flex; gap: 0.5rem; align-items: center; }
.nav-links a, .nav-links button.link {
  color: var(--muted); background: none; border: none; cursor: pointer;
  font-size: 0.9rem; padding: 6px 10px; border-radius: 6px; transition: color 0.2s;
  font-family: inherit;
}
.nav-links a:hover, .nav-links button.link:hover { color: var(--text-primary); text-decoration: none; }
.nav-links a.active { color: var(--color-accent); }
#nav-user { display: none; }
#nav-admin-link { display: none; }

/* ── Layout ───────────────────────────────────────────────────── */
.page { display: none; }
.page.active { display: block; }
.container { max-width: 1050px; margin: 0 auto; padding: 2.5rem 1.5rem; }

/* ── Hero (Landing) ───────────────────────────────────────────── */
.hero {
  background: var(--bg-primary);
  border-bottom: 1px solid var(--border-color);
  padding: 5rem 2rem 4rem;
  text-align: center;
  position: relative; overflow: hidden;
}
.hero::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 50% 0%, rgba(240,192,64,0.08) 0%, transparent 60%);
}
.hero-label { font-size: 0.78rem; letter-spacing: 2px; color: var(--color-accent); text-transform: uppercase; margin-bottom: 1rem; position: relative; z-index: 1; }
.hero h1 { font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 700; line-height: 1.2; margin-bottom: 1rem; position: relative; z-index: 1; }
.hero h1 em { color: var(--color-accent); font-style: normal; }
.hero p { color: var(--muted); max-width: 520px; margin: 0 auto 2rem; line-height: 1.6; font-size: 1.05rem; position: relative; z-index: 1; }
.hero-btns { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; position: relative; z-index: 1; }

/* ── Buttons ──────────────────────────────────────────────────── */
.btn {
  border: none; padding: 0.75rem 1.5rem; border-radius: 8px;
  font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.92rem;
  font-family: inherit;
}

/* Ko-fi wiggle animation */
@keyframes kofi-wiggle {
  0%,60%       { transform: rotate(0deg) scale(1); }
  75%          { transform: rotate(0deg) scale(1.12); }
  80%          { transform: rotate(0deg) scale(1.1); }
  84%,92%      { transform: rotate(-10deg) scale(1.1); }
  88%,96%      { transform: rotate(10deg) scale(1.1); }
  100%         { transform: rotate(0deg) scale(1); }
}
.kofi-icon {
  height: 1.2em; width: auto; vertical-align: middle;
  margin-right: 5px; animation: kofi-wiggle 3s infinite;
  display: inline-block; flex-shrink: 0;
}
.btn-content { display: flex; align-items: center; justify-content: center; gap: 6px; }
.btn-gold { background: var(--color-accent); color: #0a0a09; }
.btn-gold:hover { background: var(--gold-dark); transform: translateY(-1px); text-decoration: none; }
.btn-outline { background: transparent; color: var(--text-primary); border: 1px solid var(--border-color); }
.btn-outline:hover { border-color: var(--color-accent); color: var(--color-accent); }
.btn-sm { padding: 0.5rem 1rem; font-size: 0.85rem; }

/* ── Services section ─────────────────────────────────────────── */
.section-title { font-size: 0.75rem; letter-spacing: 2px; color: var(--color-accent); text-transform: uppercase; margin-bottom: 0.5rem; }
.section-head { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.75rem; }
.section-sub { color: var(--muted); line-height: 1.6; margin-bottom: 2rem; }

.services-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.5rem; }
.service-card {
  background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius);
  padding: 1.5rem; transition: border-color 0.2s, transform 0.2s;
}
.service-card:hover { border-color: var(--color-accent); transform: translateY(-2px); }
.service-icon { font-size: 1.8rem; margin-bottom: 0.75rem; }
.service-card h3 { font-size: 1rem; margin-bottom: 0.5rem; }
.service-card p { color: var(--muted); font-size: 0.88rem; line-height: 1.5; }

/* ── Simulations grid ─────────────────────────────────────────── */
.sim-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
.sim-card {
  background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius);
  overflow: hidden; transition: border-color 0.2s, transform 0.2s; cursor: pointer;
}
.sim-card:hover { border-color: var(--color-accent); transform: translateY(-3px); }
.sim-card-img {
  height: 160px; background: var(--bg-tertiary);
  display: flex; align-items: center; justify-content: center;
  font-size: 3.5rem; position: relative; overflow: hidden;
}
.sim-card-body { padding: 1.25rem; }
.sim-card h3 { font-size: 1.1rem; margin-bottom: 0.5rem; }
.sim-card p { color: var(--muted); font-size: 0.88rem; line-height: 1.5; margin-bottom: 1rem; }
.sim-card .price { font-size: 1.2rem; font-weight: 700; color: var(--color-accent); margin-bottom: 0.75rem; }
.tags { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.tag {
  font-size: 0.7rem; padding: 3px 8px; border: 1px solid var(--border-color);
  border-radius: 4px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px;
}

.trial-badge {
  position: absolute;
  top: 10px; left: 10px;
  font-size: 0.65rem;
  font-weight: 800;
  letter-spacing: 1px;
  padding: 4px 10px;
  border-radius: 4px;
  text-transform: uppercase;
  pointer-events: none;
}
.trial-badge.badge-trial-only    { background: var(--color-accent); color: #0a0a09; }
.trial-badge.badge-trial-upgrade { background: var(--color-accent); color: #0a0a09; }
.trial-badge.badge-paid-only     { background: #28a745; color: #fff; }
.sim-card-img { position: relative; }
.purchased-badge {
  background: var(--green);
  color: #000;
  padding: 4px 10px;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
  display: inline-block;
  margin-bottom: 0.5rem;
}

/* ── Modals ───────────────────────────────────────────────────── */
.modal-bg {
  position: fixed; inset: 0; background: rgba(0,0,0,0.7);
  backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: 1.5rem;
  opacity: 0; pointer-events: none; transition: opacity 0.2s;
}
.modal-bg.active { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg-secondary); border: 1px solid var(--border-color);
  border-radius: var(--radius); padding: 2rem;
  max-width: 550px; width: 100%; position: relative;
  max-height: 90vh; overflow-y: auto;
}
.modal-close {
  position: absolute; top: 1rem; right: 1rem;
  background: transparent; border: none; color: var(--muted);
  font-size: 1.8rem; cursor: pointer; width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 4px; transition: background 0.2s;
}
.modal-close:hover { background: var(--bg-tertiary); color: var(--text-primary); }
.modal p { color: var(--muted); line-height: 1.6; margin-bottom: 1.5rem; }

/* ── Forms ────────────────────────────────────────────────────── */
.form-group { margin-bottom: 1rem; }
.form-group label { display: block; font-size: 0.85rem; margin-bottom: 0.4rem; color: var(--muted); }
.form-group input, .form-group textarea {
  width: 100%; padding: 0.75rem;
  background: var(--bg-tertiary); border: 1px solid var(--border-color);
  border-radius: 6px; color: var(--text-primary);
  font-family: inherit; font-size: 0.95rem;
}
.form-group input:focus, .form-group textarea:focus {
  outline: none; border-color: var(--color-accent);
}
.form-group textarea { resize: vertical; min-height: 120px; }

.alert {
  padding: 0.75rem 1rem;
  border-radius: 6px;
  margin-bottom: 1rem;
  font-size: 0.9rem;
}
.alert-success { background: rgba(63,185,80,0.15); color: var(--green); border: 1px solid rgba(63,185,80,0.3); }
.alert-error { background: rgba(248,81,73,0.15); color: var(--red); border: 1px solid rgba(248,81,73,0.3); }
.alert-info { background: rgba(88,166,255,0.15); color: var(--color-primary); border: 1px solid rgba(88,166,255,0.3); }

.iframe-container {
  margin: 1.5rem 0;
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  overflow: hidden;
  background: var(--bg-tertiary);
}
.iframe-container iframe {
  width: 100%;
  height: 500px;
  border: none;
  display: block;
}

.locked-overlay {
  position: absolute;
  inset: 0;
  background: rgba(13,17,23,0.95);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 1rem;
  z-index: 10;
}
.locked-overlay .lock-icon { font-size: 3rem; }

/* ── Donor Section ────────────────────────────────────────────────────── */
.donors-list {
  background: var(--bg-secondary);
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  padding: 2rem;
  margin: 2rem auto;
  max-width: 800px;
}

.donor-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 0;
  border-bottom: 1px solid var(--border-color);
  gap: 1rem;
}

.donor-item:last-child { 
  border-bottom: none; 
}

.donor-item:first-child {
  padding-top: 0;
}

.donor-info {
  flex: 1;
  min-width: 0;
}

.donor-name { 
  color: var(--color-accent); 
  font-weight: 600;
  font-size: 1rem;
  margin-bottom: 0.25rem;
}

.donor-message {
  color: var(--muted);
  font-size: 0.9rem;
  line-height: 1.5;
  margin-top: 0.5rem;
}

.donor-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-shrink: 0;
}

.donor-amount { 
  color: var(--green);
  font-weight: 600;
  font-size: 1.1rem;
  white-space: nowrap;
}

.donor-date { 
  color: var(--muted); 
  font-size: 0.85rem;
  white-space: nowrap;
}

/* Responsive */
@media (max-width: 768px) {
  .donors-list {
    padding: 1.5rem;
    margin: 1.5rem 0;
  }
  
  .donor-item {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1.25rem 0;
  }
  
  .donor-meta {
    width: 100%;
    justify-content: space-between;
  }
}

@media (max-width: 480px) {
  .donors-list {
    padding: 1rem;
  }
  
  .donor-meta {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }
}

/* ── Footer ───────────────────────────────────────────────────── */
footer {
  border-top: 1px solid var(--border-color);
  padding: 2rem;
  text-align: center;
  color: var(--muted);
  font-size: 0.85rem;
  margin-top: 4rem;
}

/* ── Responsive ───────────────────────────────────────────────── */
@media (max-width: 768px) {
  nav { padding: 0 1rem; }
  .brand { font-size: 0.9rem; }
  .nav-links { gap: 0.25rem; }
  .nav-links a, .nav-links button.link { font-size: 0.85rem; padding: 5px 8px; }
  .hero { padding: 3rem 1.5rem 2.5rem; }
  .container { padding: 2rem 1rem; }
  .sim-grid { grid-template-columns: 1fr; }
  .services-grid { grid-template-columns: 1fr; }
}


/* ── Get in Touch section ─────────────────────────────────────────────────── */
.git-section {
  background: var(--bg-secondary);
  border-top: 1px solid var(--border-color);
  padding: 4rem 2rem;
  margin-top: 4rem;
}
.git-inner {
  max-width: 900px; margin: 0 auto;
  display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: start;
}
.git-left h2 { font-size: 2rem; font-weight: 700; margin-bottom: 1rem; }
.git-left p  { color: var(--muted); line-height: 1.7; margin-bottom: 1.5rem; font-size: .95rem; }
.git-left .git-contact { margin-bottom: .75rem; }
.git-left .git-contact strong { display: block; font-size: .75rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: .2rem; }
.git-left .git-contact a { color: var(--text-primary); text-decoration: none; font-size: .95rem; }
.git-left .git-contact a:hover { color: var(--color-accent); }
.git-form input, .git-form textarea {
  width: 100%; padding: .75rem; margin-bottom: .75rem;
  background: var(--bg-tertiary); border: 1px solid var(--border-color);
  border-radius: 8px; color: var(--text-primary); font-family: inherit; font-size: .9rem;
}
.git-form input:focus, .git-form textarea:focus { outline: none; border-color: var(--color-accent); }
.git-form textarea { min-height: 120px; resize: vertical; }
.git-form label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem; color: var(--muted); }
.git-form label.req::after { content: " *"; color: #f87171; }
.git-form .btn { width: 100%; padding: .85rem; margin-top: .25rem; }
#git-msg { font-size: .85rem; padding: .5rem .75rem; border-radius: 6px; margin-bottom: .75rem; display: none; }
#git-msg.ok  { background: rgba(34,197,94,.15); color: #4ade80; border: 1px solid rgba(34,197,94,.3); }
#git-msg.err { background: rgba(239,68,68,.15);  color: #f87171; border: 1px solid rgba(239,68,68,.3); }

/* ── Site Footer ──────────────────────────────────────────────────────────── */
.site-footer {
  background: #0d1117;
  border-top: 1px solid var(--border-color);
  padding: 1.25rem 2rem;
}
.footer-inner {
  max-width: 1050px; margin: 0 auto;
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 1rem;
}
.footer-brand h3 { font-size: .95rem; font-weight: 700; margin: 0; color: #fff; }
.footer-brand h3 span { color: var(--color-accent); }
.footer-brand p  { color: #6e7681; font-size: .78rem; margin: .15rem 0 0; }
.footer-col h4   { display: none; }
.footer-col      { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
.footer-col a    { color: #adb5bd; font-size: .82rem; text-decoration: none; white-space: nowrap; }
.footer-col a:hover { color: var(--color-accent); }
.footer-bottom {
  max-width: 1050px; margin: .6rem auto 0; padding-top: .6rem;
  border-top: 1px solid #21262d;
  display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .75rem;
  font-size: .75rem; color: #6e7681;
}
.footer-bottom a { color: #6e7681; text-decoration: none; }
.footer-bottom a:hover { color: var(--color-accent); }

@media (max-width: 768px) {
  .git-inner { grid-template-columns: 1fr; gap: 2rem; }
  .git-section { padding: 2.5rem 1.25rem; margin-top: 2rem; }
  .footer-inner { grid-template-columns: 1fr; gap: 1.5rem; }
  .footer-bottom { flex-direction: column; text-align: center; }
}


/* ── Admin SQL Reports ────────────────────────────────────────────────────── */
.report-layout { display: grid; grid-template-columns: 260px 1fr; gap: 0; min-height: 600px; border: 1px solid var(--border-color); border-radius: var(--radius); overflow: hidden; }
.report-sidebar { background: var(--bg-secondary); border-right: 1px solid var(--border-color); padding: 1rem 0; overflow-y: auto; }
.report-sidebar h4 { font-size: .7rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); padding: .5rem 1.25rem; margin-bottom: .25rem; }
.report-item { display: flex; align-items: center; gap: .6rem; padding: .65rem 1.25rem; cursor: pointer; font-size: .88rem; color: var(--text-primary); transition: background .15s; border-left: 3px solid transparent; }
.report-item:hover { background: var(--bg-tertiary); }
.report-item.active { background: var(--bg-tertiary); border-left-color: var(--color-accent); color: var(--color-accent); font-weight: 600; }
.report-main { padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; background: var(--bg-primary); overflow: hidden; }
.report-main h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: .25rem; }
.report-query-box { width: 100%; min-height: 80px; padding: .75rem; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary); font-family: 'Courier New', monospace; font-size: .85rem; resize: vertical; }
.report-query-box:focus { outline: none; border-color: var(--color-accent); }
.report-run-bar { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
.report-count { font-size: .8rem; color: var(--muted); }
.report-table-wrap { overflow-x: auto; border: 1px solid var(--border-color); border-radius: 6px; }
.report-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.report-table th { background: var(--bg-secondary); padding: .6rem .75rem; text-align: left; font-weight: 600; font-size: .75rem; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid var(--border-color); white-space: nowrap; color: var(--muted); }
.report-table td { padding: .55rem .75rem; border-bottom: 1px solid var(--border-color); color: var(--text-primary); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.report-table tr:last-child td { border-bottom: none; }
.report-table tr:hover td { background: var(--bg-tertiary); }
.report-error { background: rgba(248,81,73,.1); border: 1px solid rgba(248,81,73,.3); color: #f87171; padding: .75rem 1rem; border-radius: 6px; font-size: .85rem; }
@media (max-width: 768px) { .report-layout { grid-template-columns: 1fr; } .report-sidebar { border-right: none; border-bottom: 1px solid var(--border-color); } }


/* ── Admin dashboard ────────────────────────────────────────────────────── */
.admin-layout { display: grid; grid-template-columns: 200px 1fr; gap: 0; min-height: 500px; border: 1px solid var(--border-color); border-radius: var(--radius); overflow: hidden; }
.admin-sidebar { background: var(--bg-secondary); border-right: 1px solid var(--border-color); padding: .75rem 0; }
.admin-sidebar h5 { font-size: .68rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); padding: .75rem 1.25rem .25rem; }
.admin-nav-btn {
  display: flex; align-items: center; gap: .65rem;
  width: 100%; padding: .7rem 1.25rem;
  background: none; border: none; border-left: 3px solid transparent;
  color: var(--text-primary); font-size: .88rem; font-weight: 500;
  cursor: pointer; text-align: left; transition: background .15s, color .15s;
}
.admin-nav-btn:hover { background: var(--bg-tertiary); color: var(--color-accent); }
.admin-nav-btn.active { background: var(--bg-tertiary); border-left-color: var(--color-accent); color: var(--color-accent); font-weight: 700; }
.admin-nav-btn .icon { font-size: 1rem; width: 1.2rem; text-align: center; }
.admin-content-area { padding: 1.5rem; background: var(--bg-primary); overflow: auto; }
.admin-tab-content { display: none; }
.admin-tab-content.active { display: block; }
@media (max-width: 768px) {
  .admin-layout { grid-template-columns: 1fr; }
  .admin-sidebar { border-right: none; border-bottom: 1px solid var(--border-color); display: flex; flex-wrap: wrap; padding: .5rem; gap: .25rem; }
  .admin-nav-btn { border-left: none; border-bottom: 3px solid transparent; padding: .5rem .75rem; font-size: .8rem; border-radius: 6px; }
  .admin-nav-btn.active { border-bottom-color: var(--color-accent); background: var(--bg-tertiary); }
}

/* ── Profile Page ──────────────────────────────────────────────────────────── */
.profile-wrap { max-width: 600px; margin: 0 auto; padding: 2rem 1.5rem; }
.profile-avatar-section { display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem; }
.profile-avatar-img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--border-color); flex-shrink: 0; }
.profile-avatar-placeholder { width: 80px; height: 80px; border-radius: 50%; background: var(--color-accent); color: #0a0a09; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700; flex-shrink: 0; border: 3px solid var(--border-color); }
.profile-avatar-actions { display: flex; flex-direction: column; gap: .5rem; }
.profile-avatar-actions small { color: var(--muted); font-size: .75rem; }
.profile-card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1.75rem; margin-bottom: 1.25rem; }
.profile-card h4 { font-size: .75rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: 1rem; }

/* ── Nav avatar ────────────────────────────────────────────────────────────── */
.nav-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color); flex-shrink: 0; }
.nav-avatar-placeholder { width: 32px; height: 32px; border-radius: 50%; background: var(--color-accent); color: #0a0a09; display: flex; align-items: center; justify-content: center; font-size: .85rem; font-weight: 700; flex-shrink: 0; }
.drawer-avatar-img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color); flex-shrink: 0; }


/* ── Blog page ─────────────────────────────────────────────────────────────── */
.blog-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1.5rem; }
.blog-card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:var(--radius); overflow:hidden; cursor:pointer; transition:transform .15s,box-shadow .15s; display:flex; flex-direction:column; }
.blog-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.25); }
.blog-card-cover { height:160px; background:var(--bg-tertiary); overflow:hidden; }
.blog-card-cover img { width:100%; height:100%; object-fit:cover; }
.blog-card-body { padding:1.25rem; flex:1; display:flex; flex-direction:column; gap:.5rem; }
.blog-card-date { font-size:.75rem; color:var(--muted); }
.blog-card-title { font-size:1.05rem; font-weight:700; line-height:1.3; }
.blog-card-summary { font-size:.88rem; color:var(--muted); line-height:1.5; flex:1; }
.blog-card-read { color:var(--color-accent); font-size:.85rem; font-weight:600; margin-top:.25rem; }
/* Blog post reader */
.blog-post-content { max-width:700px; margin:0 auto; padding:2rem 1.5rem; }
.blog-post-content h1 { font-size:2rem; margin-bottom:.5rem; line-height:1.25; }
.blog-post-content .post-meta { color:var(--muted); font-size:.85rem; margin-bottom:2rem; }
.blog-post-body { line-height:1.8; font-size:1rem; }
.blog-post-body h2 { font-size:1.4rem; margin:1.5rem 0 .75rem; }
.blog-post-body h3 { font-size:1.15rem; margin:1.25rem 0 .5rem; }
.blog-post-body p  { margin-bottom:1rem; }
.blog-post-body ul, .blog-post-body ol { margin:.5rem 0 1rem 1.5rem; }
.blog-post-body li { margin-bottom:.25rem; }
.blog-post-body code { background:var(--bg-tertiary); padding:.1rem .35rem; border-radius:3px; font-size:.88rem; }
.blog-post-body pre { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:8px; padding:1rem; overflow-x:auto; margin-bottom:1rem; }
.blog-post-body img { max-width:100%; border-radius:8px; }
.blog-back-btn { display:inline-flex; align-items:center; gap:.4rem; color:var(--color-accent); font-size:.88rem; font-weight:600; cursor:pointer; margin-bottom:1.5rem; background:none; border:none; padding:0; }

/* ── Blog Interactions Bar ──────────────────────────────────────── */
.blog-interactions { display:flex; align-items:center; gap:1rem; padding:1.2rem 0; border-top:1px solid var(--border-color); border-bottom:1px solid var(--border-color); margin:2rem 0 1.5rem; }
.blog-like-wrap { position:relative; display:inline-flex; }
.blog-like-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .9rem; border:1px solid var(--border-color); border-radius:var(--radius); background:none; color:var(--text-primary); font-size:.9rem; cursor:pointer; transition:border-color .15s, color .15s; }
.blog-like-btn:hover { border-color:var(--color-accent); color:var(--color-accent); }
.blog-like-btn.liked { border-color:var(--color-accent); color:var(--color-accent); background:rgba(var(--accent-rgb),.08); }
.blog-like-btn svg { width:16px; height:16px; flex-shrink:0; }
.blog-like-tooltip { position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%); background:var(--bg-tertiary); border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:.3rem .65rem; font-size:.8rem; white-space:nowrap; pointer-events:none; opacity:0; transition:opacity .15s; z-index:10; }
.blog-like-wrap:hover .blog-like-tooltip { opacity:1; }
.blog-comment-toggle { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .9rem; border:1px solid var(--border-color); border-radius:var(--radius); background:none; color:var(--text-primary); font-size:.9rem; cursor:pointer; transition:border-color .15s, color .15s; }
.blog-comment-toggle:hover, .blog-comment-toggle.active { border-color:var(--color-accent); color:var(--color-accent); }
.blog-comment-toggle svg { width:16px; height:16px; flex-shrink:0; }

/* ── Comment Section ────────────────────────────────────────────── */
#post-view-comments { display:none; }
#post-view-comments.open { display:block; }
.blog-comments-header { font-size:1rem; font-weight:600; margin-bottom:1.25rem; }

/* comment form */
.blog-comment-form { display:flex; flex-direction:column; gap:.65rem; margin-bottom:2rem; }
.blog-comment-form textarea, .blog-reply-form textarea {
  width:100%; padding:.75rem 1rem; background:var(--bg-secondary);
  border:1px solid var(--border-color); border-radius:var(--radius);
  color:var(--text-primary); font-size:.9rem; resize:vertical; min-height:90px;
  font-family:inherit; transition:border-color .15s;
}
.blog-comment-form textarea:focus, .blog-reply-form textarea:focus { outline:none; border-color:var(--color-accent); }
.blog-comment-form-actions { display:flex; gap:.65rem; align-items:center; }
.blog-cta-login { font-size:.88rem; color:var(--muted); }
.blog-cta-login button { background:none; border:none; color:var(--color-accent); cursor:pointer; font-size:.88rem; padding:0; }

/* ── Comment row ─────────────────────────────────────────────────────────────
   .bc-wrap > .bc-row > .bc-main > .bc-meta / .bc-text / .bc-vote-row / .bc-actions
   .bc-children (indented replies, collapsible)
*/
.bc-row  { display:flex; min-width:0; margin-bottom:.75rem; }
.bc-main { flex:1; min-width:0; }

/* inline avatar */
.bc-avatar {
  width:22px; height:22px; border-radius:50%; flex-shrink:0;
  background:var(--color-accent); color:#0a0a09;
  display:inline-flex; align-items:center; justify-content:center;
  font-size:.7rem; font-weight:700; overflow:hidden; vertical-align:middle;
}
.bc-avatar img { width:100%; height:100%; object-fit:cover; }

.bc-meta   { display:flex; align-items:center; gap:.45rem; margin-bottom:.3rem; flex-wrap:wrap; }
.bc-author { font-size:.82rem; font-weight:700; color:var(--text-primary); }
.bc-date   { font-size:.75rem; color:var(--muted); }
.bc-text   { font-size:.88rem; line-height:1.65; word-break:break-word; white-space:pre-wrap; color:var(--text-primary); margin-bottom:.5rem; }

/* vote row — sits below the comment text */
.bc-vote-row { display:flex; align-items:center; gap:.5rem; margin-bottom:.4rem; }

.bc-vote-up, .bc-vote-dn {
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.28rem .7rem; border-radius:20px; font-size:.8rem; font-weight:600;
  cursor:pointer; border:1.5px solid; line-height:1; background:transparent;
  transition:background .12s, opacity .12s, transform .1s;
}
.bc-vote-up:active, .bc-vote-dn:active { transform:scale(.93); }

/* light mode — green up / red down */
.bc-vote-up              { border-color:#28a745; color:#28a745; }
.bc-vote-up:hover        { background:rgba(40,167,69,.08); }
.bc-vote-up.active       { background:#28a745; color:#fff; }
.bc-vote-dn              { border-color:#dc3545; color:#dc3545; }
.bc-vote-dn:hover        { background:rgba(220,53,69,.08); }
.bc-vote-dn.active       { background:#dc3545; color:#fff; }

/* dark mode — white outline, gold on active */
[data-theme="dark"] .bc-vote-up              { border-color:rgba(255,255,255,.5); color:rgba(255,255,255,.85); }
[data-theme="dark"] .bc-vote-up:hover        { border-color:#fff; color:#fff; background:rgba(255,255,255,.07); }
[data-theme="dark"] .bc-vote-up.active       { border-color:var(--color-accent); color:var(--color-accent); background:rgba(212,160,23,.12); }
[data-theme="dark"] .bc-vote-dn              { border-color:rgba(255,255,255,.5); color:rgba(255,255,255,.85); }
[data-theme="dark"] .bc-vote-dn:hover        { border-color:#fff; color:#fff; background:rgba(255,255,255,.07); }
[data-theme="dark"] .bc-vote-dn.active       { border-color:var(--color-accent); color:var(--color-accent); background:rgba(212,160,23,.12); }

.bc-score             { font-size:.8rem; font-weight:700; color:var(--muted); min-width:16px; text-align:center; line-height:1; }
.bc-score.pos         { color:#28a745; }
.bc-score.neg         { color:#dc3545; }
[data-theme="dark"] .bc-score.pos { color:var(--color-accent); }
[data-theme="dark"] .bc-score.neg { color:var(--color-accent); }

/* action bar */
.bc-actions { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:.25rem; }
.bc-actions button { background:none; border:none; color:var(--muted); font-size:.75rem; font-weight:600; cursor:pointer; padding:2px 4px; border-radius:3px; transition:color .12s,background .12s; }
.bc-actions button:hover { color:var(--text-primary); background:var(--bg-tertiary); }
.bc-actions .bc-collapse-btn.collapsed { color:var(--color-accent); }

/* reply form */
.bc-reply-host .blog-reply-form { margin-top:.6rem; }
.blog-reply-form { display:flex; flex-direction:column; gap:.5rem; }
.blog-reply-form textarea { min-height:72px; }
.blog-reply-form-actions { display:flex; gap:.5rem; }

/* children block */
.bc-children { margin-top:.5rem; margin-left:10px; padding-left:10px; border-left:2px solid var(--border-color); }
.bc-children[data-depth="1"] { border-color:#4a9eff44; }
.bc-children[data-depth="2"] { border-color:#ff914444; }
.bc-children[data-depth="3"] { border-color:#a78bfa44; }
.bc-children[data-depth="4"] { border-color:#34d39944; }
.bc-children[data-depth="5"] { border-color:#4a9eff44; }
.bc-children[data-depth="6"] { border-color:#ff914444; }
.bc-children[data-depth="7"] { border-color:#a78bfa44; }
.bc-children.collapsed { display:none; }
.bc-collapsed-pill { display:none; font-size:.75rem; color:var(--muted); margin:.3rem 0 .3rem 20px; cursor:pointer; font-weight:600; }
.bc-collapsed-pill:hover { color:var(--color-accent); }
.bc-collapsed-pill.visible { display:block; }

/* load-more */
.blog-load-more-btn { background:none; border:1px solid var(--border-color); border-radius:var(--radius); color:var(--muted); font-size:.78rem; padding:.35rem .8rem; cursor:pointer; margin-top:.4rem; transition:border-color .15s,color .15s; }
.blog-load-more-btn:hover { border-color:var(--color-accent); color:var(--color-accent); }

/* ── Blog Thread View ───────────────────────────────────────────── */
#blog-thread-view { display:none; }
.blog-thread-back { display:inline-flex; align-items:center; gap:.4rem; color:var(--color-accent); font-size:.88rem; font-weight:600; cursor:pointer; margin-bottom:1.5rem; background:none; border:none; padding:0; }
.blog-thread-heading { font-size:1.1rem; font-weight:700; margin-bottom:.4rem; }
.blog-thread-sub { font-size:.85rem; color:var(--muted); margin-bottom:1.5rem; }

/* Admin blog editor */
/* Admin blog editor */
.blog-editor-wrap { display:grid; grid-template-columns:240px 1fr; gap:1.25rem; }
.blog-post-list-item { padding:.65rem 1rem; border-radius:6px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; font-size:.88rem; border:1px solid var(--border-color); margin-bottom:.5rem; }
.blog-post-list-item:hover { background:var(--bg-tertiary); }
.blog-post-status { font-size:.7rem; padding:2px 8px; border-radius:10px; font-weight:700; }
.status-published { background:rgba(63,185,80,.2); color:#3fb950; }
.status-draft     { background:rgba(139,148,158,.15); color:#8b949e; }
#blog-content-editor { width:100%; min-height:340px; font-family:'Courier New',monospace; font-size:.85rem; padding:.75rem; background:var(--bg-tertiary); border:1px solid var(--border-color); border-radius:8px; color:var(--text-primary); resize:vertical; }

/* Sim card star rating inline */
.sim-card-stars { display:flex; align-items:center; gap:.25rem; font-size:.75rem; color:var(--muted); margin-bottom:.25rem; }
.sim-card-stars .s { color:var(--color-accent); font-size:.85rem; }
.sim-usage-badge { font-size:.7rem; color:var(--muted); display:flex; align-items:center; gap:.25rem; }

@media (max-width:768px) {
  .blog-grid { grid-template-columns:1fr; }
  .blog-editor-wrap { grid-template-columns:1fr; }
}

/* ── Extra mobile polish ─────────────────────────────────────────────────── */
@media (max-width: 640px) {
  /* Modals full-screen on mobile */
  .modal-bg { padding: 0; align-items: flex-end; }
  .modal {
    border-radius: 16px 16px 0 0 !important;
    max-height: 92vh;
    width: 100% !important;
    max-width: 100% !important;
    overflow-y: auto;
  }
  /* Sim grid single column */
  .sim-grid { grid-template-columns: 1fr; }
  /* Home page */
  .home-wrap { padding: 2rem 1rem !important; gap: 2rem !important; }
  .home-hero h1 { font-size: 1.75rem !important; }
  /* Nav */
  nav { position: sticky; top: 0; z-index: 50; }
  /* Sections */
  .container { padding: 1.5rem 1rem; }
  .hero { padding: 2rem 1rem 2rem; }
  /* About grid */
  .about-grid { grid-template-columns: 1fr !important; }
  /* Buttons */
  .hero-btns { flex-direction: column; align-items: center; }
  .hero-btns .btn { width: 100%; max-width: 280px; }
}

/* drawer handles mobile/desktop auth split */

</style>

  <!-- Analytics — deferred to window.load, respects cookie consent ────────── -->
  <script>
  window.addEventListener('load', function() {
    var gaId = '<?php echo esc(GA_TAG_ID); ?>';
    if (gaId && localStorage.getItem('ir_cookie_consent') !== 'declined') {
      var s = document.createElement('script');
      s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
      document.head.appendChild(s);
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      window.gtag = gtag;
      gtag('js', new Date());
      gtag('config', gaId);
    }
    if (localStorage.getItem('ir_cookie_consent') !== 'declined') {
      (function(c,l,a,r,i,t,y){
        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
        t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;
        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
      })(window,document,'clarity','script','wirkgcoxjj');
    }
  });

  // CSRF-aware fetch wrapper — used by all POST requests to api.php
  // Usage: apiFetch('api.php?action=login', { body: JSON.stringify({...}) })
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

<!-- Animated background canvases (same system as index.html) -->
<canvas id="starCanvas" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:-2;pointer-events:none;transition:opacity 0.3s ease"></canvas>
<canvas id="lightBgCanvas" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:-3;pointer-events:none;transition:opacity 0.3s ease;opacity:0;visibility:hidden"></canvas>

<!-- ════════ NAVIGATION ════════ -->
<style>
/* ── Theme toggle ────────────────────────────────────────────────────────── */
.ir-toggle{--size:1.8rem;appearance:none;outline:none;cursor:pointer;width:var(--size);height:var(--size);box-shadow:inset calc(var(--size)*.33) calc(var(--size)*-.25) 0;border-radius:999px;color:hsl(240,100%,95%);transition:all 500ms;background:transparent;margin:0;border:none;flex-shrink:0}
.ir-toggle:hover{box-shadow:inset calc(var(--size)*.33) calc(var(--size)*-.25) 0,0 0 12px currentColor}
.ir-toggle:checked{--ray-size:calc(var(--size)*-.4);--oo:calc(var(--size)*.65);--od:calc(var(--size)*.45);transform:scale(.75);color:#f0c040;box-shadow:inset 0 0 0 var(--size),calc(var(--oo)*-1) 0 0 var(--ray-size),var(--oo) 0 0 var(--ray-size),0 calc(var(--oo)*-1) 0 var(--ray-size),0 var(--oo) 0 var(--ray-size),calc(var(--od)*-1) calc(var(--od)*-1) 0 var(--ray-size),var(--od) var(--od) 0 var(--ray-size),calc(var(--od)*-1) var(--od) 0 var(--ray-size),var(--od) calc(var(--od)*-1) 0 var(--ray-size)}
.ir-toggle:checked:hover{color:#ffd966}
[data-theme="light"] .ir-toggle{color:#1e2a3a}

/* ── Light mode nav + toggle fixes ─────────────────────────────────────── */
[data-theme="light"] nav {
  background: rgba(255,255,255,0.95) !important;
  border-bottom-color: #dee2e6;
}
[data-theme="light"] nav h1 { color: #191c1f; }
[data-theme="light"] nav a { color: #525558; }
[data-theme="light"] nav a:hover { color: var(--color-accent); }
[data-theme="light"] .nav-desktop-links a { color: #2c3e50; }
[data-theme="light"] .nav-burger span { background: #191c1f; }
[data-theme="light"] .drawer-header { background: #fff; }
[data-theme="light"] .nav-drawer { background: #fff; border-left-color: #dee2e6; }
[data-theme="light"] .drawer-brand { color: #191c1f; }
[data-theme="light"] .drawer-nav a { color: #2c3e50; }
[data-theme="light"] .drawer-nav a:hover { background: #f1f3f5; }
[data-theme="light"] .drawer-footer { border-top-color: #dee2e6; background: #f8f9fa; }
[data-theme="light"] .drawer-user-info { background: #f1f3f5; }
[data-theme="light"] .drawer-user-info .user-email { color: #525558; }

/* ── Light mode text readability ─────────────────────────────────────────── */
[data-theme="light"] .about-card h3 { color: #b8860b; }
[data-theme="light"] .section-title { color: #b8860b; }
[data-theme="light"] .hero-label    { color: #b8860b; }
[data-theme="light"] .section-title { color: #b8860b; }
[data-theme="light"] .tag           { color: #525558; border-color: #adb5bd; }
[data-theme="light"] .trial-badge   { background: #e6a800; color: #fff; }
[data-theme="light"] .git-left h2   { color: #191c1f; }
[data-theme="light"] .about-contact-item a { color: #191c1f; }
[data-theme="light"] .footer-brand h3 span { color: #b8860b; }
[data-theme="light"] .footer-col a  { color: #525558; }
[data-theme="light"] .footer-col h4 { color: #525558; }
[data-theme="light"] .footer-bottom { color: #525558; }
[data-theme="light"] .hero h1 em    { color: #b8860b; }
[data-theme="light"] .service-card h3 { color: #191c1f; }
[data-theme="light"] .sim-card h3   { color: #191c1f; }
[data-theme="light"] .sim-card-img  { background: #e9ecef; }
[data-theme="light"] .home-hero h1  { color: #191c1f; }
[data-theme="light"] .home-hero .subtitle { color: #525558; }


/* ── Hamburger button ────────────────────────────────────────────────────── */
.nav-burger {
  display: none;
  background: none; border: none; cursor: pointer;
  width: 40px; height: 40px;
  flex-direction: column; justify-content: center; gap: 5px;
  border-radius: 8px; padding: 6px;
  transition: background .2s;
  flex-shrink: 0;
}
.nav-burger:hover { background: var(--bg-tertiary); }
.nav-burger span {
  display: block; width: 100%; height: 2px;
  background: var(--text-primary); border-radius: 2px;
  transition: all .3s ease;
  transform-origin: center;
}

/* ── Sidebar drawer ──────────────────────────────────────────────────────── */
.nav-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.55);
  z-index: 199;
  backdrop-filter: blur(2px);
  -webkit-backdrop-filter: blur(2px);
  opacity: 0;
  transition: opacity .3s ease;
}
.nav-overlay.open { opacity: 1; }

.nav-drawer {
  position: fixed; top: 0; right: 0;
  width: 280px; height: 100%;
  background: #1a1d23;
  border-left: 1px solid var(--border-color);
  z-index: 200;
  display: flex; flex-direction: column;
  transform: translateX(100%);
  transition: transform .3s cubic-bezier(.4,0,.2,1);
  overflow-y: auto;
}
.nav-drawer.open { transform: translateX(0); }

.drawer-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border-color);
  flex-shrink: 0;
}
.drawer-brand { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); white-space: nowrap; }
.drawer-brand span { color: var(--color-accent); }
.drawer-close {
  background: none; border: none; cursor: pointer;
  width: 36px; height: 36px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  color: var(--text-primary); font-size: 1.4rem;
  transition: background .2s;
}
.drawer-close:hover { background: var(--bg-tertiary); }

.drawer-nav {
  flex: 1; padding: .5rem 0;
  display: flex; flex-direction: column;
  justify-content: flex-start; /* prevent items spacing out */
}
.drawer-nav a, .drawer-nav button.drawer-link {
  display: flex; align-items: center; gap: .75rem;
  padding: .75rem 1.5rem;
  color: var(--text-primary); text-decoration: none;
  font-size: 1rem; font-weight: 500;
  background: none; border: none; cursor: pointer;
  width: 100%; text-align: left;
  transition: background .15s, color .15s;
  border-radius: 0;
}
.drawer-nav a:hover, .drawer-nav button.drawer-link:hover {
  background: var(--bg-tertiary); color: var(--color-accent);
}
.drawer-nav a.active { color: var(--color-accent); }
.drawer-nav .drawer-icon { font-size: 1.1rem; width: 1.4rem; text-align: center; }
.drawer-divider {
  margin: .5rem 1.5rem;
  border: none; border-top: 1px solid var(--border-color);
}

.drawer-footer {
  padding: 1.25rem 1.5rem;
  border-top: 1px solid var(--border-color);
  flex-shrink: 0;
}
.drawer-footer .btn { width: 100%; justify-content: center; padding: .75rem; font-size: .95rem; }
.drawer-user-info {
  display: flex; align-items: center; gap: .75rem;
  padding: .75rem;
  background: var(--bg-tertiary); border-radius: 10px;
  margin-bottom: .75rem;
}
.drawer-user-info .avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--color-accent); color: #000;
  display: flex; align-items: center; justify-content: center;
  font-size: .85rem; font-weight: 700; flex-shrink: 0;
}
.drawer-user-info .user-email {
  font-size: .8rem; color: var(--muted);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;
}

/* ── Responsive: show burger, hide desktop links ─────────────────────────── */
@media (max-width: 768px) {
  .nav-desktop-links { display: none !important; }
  .nav-desktop-auth  { display: none !important; }
  .nav-burger        { display: flex !important; }
}
@media (min-width: 769px) {
  .nav-burger        { display: none !important; }
  .nav-overlay       { display: none !important; }
  .nav-drawer        { display: none !important; }
}
</style>

<!-- Sidebar overlay (click to close) -->
<div id="nav-overlay" class="nav-overlay" onclick="closeDrawer()"></div>

<!-- Sidebar drawer -->
<div id="nav-drawer" class="nav-drawer" aria-label="Navigation menu" role="navigation">
  <div class="drawer-header">
    <span class="drawer-brand">Akilesh <span style="color:var(--color-accent)">Bhandary</span></span>
    <button class="drawer-close" onclick="closeDrawer()" aria-label="Close menu">×</button>
  </div>

  <nav class="drawer-nav" id="drawer-nav-links">
    <a href="#" onclick="showPage('simulations');closeDrawer();return false">
      <span class="drawer-icon">🔬</span> Simulations
    </a>
    <a href="#" onclick="showPage('home');closeDrawer();return false">
      <span class="drawer-icon">🏠</span> Home
    </a>
    <a href="#" onclick="showPage('about');closeDrawer();return false">
      <span class="drawer-icon">👤</span> About
    </a>
    <a href="#" onclick="showPage('blog');closeDrawer();return false">
      <span class="drawer-icon">📝</span> Blog
    </a>
    <a href="#" id="drawer-profile-link" style="display:none" onclick="showPage('profile');closeDrawer();return false">
      <span class="drawer-icon">👤</span> My Profile
    </a>
    <a href="#" id="drawer-admin-link" style="display:none" onclick="showPage('admin');closeDrawer();return false">
      <span class="drawer-icon">⚙️</span> Admin
    </a>
  </nav>

  <!-- Footer: auth -->
  <div class="drawer-footer">
    <!-- Guest state -->
    <div id="drawer-guest">
      <button class="btn btn-gold" onclick="openModal('auth-modal');closeDrawer()">Login / Sign Up</button>
    </div>
    <!-- Logged-in state -->
    <div id="drawer-user" style="display:none">
      <div class="drawer-user-info">
        <div class="avatar" id="drawer-avatar">?</div>
        <span class="user-email" id="drawer-email"></span>
      </div>
      <button class="btn btn-sm" style="width:100%" onclick="logout()">Logout</button>
    </div>
  </div>
</div>

<nav>
  <!-- Brand -->
  <div style="display:flex;align-items:center;gap:1rem;flex:0 0 auto">
    <h1 style="font-size:1.1rem;margin:0;white-space:nowrap;line-height:1">
      Akilesh <span style="color:var(--color-accent)">Bhandary</span>
    </h1>
  </div>

  <!-- Desktop links (hidden on mobile) -->
  <div class="nav-desktop-links" style="display:flex;align-items:center;gap:2rem">
    <a href="#" id="nav-tab-simulations" onclick="showPage('simulations');return false">Simulations</a>
    <a href="#" id="nav-tab-blog" onclick="showPage('blog');return false">Blog</a>
    <a href="#" id="nav-tab-home" onclick="showPage('home');return false">Home</a>
    <a href="#" id="nav-tab-about" onclick="showPage('about');return false">About</a>
  </div>

  <!-- Right side: toggle + auth (desktop) / toggle + burger (mobile) -->
  <div style="display:flex;align-items:center;gap:.75rem;margin-left:1.5rem">
    <input id="simThemeToggle" class="ir-toggle" type="checkbox" title="Toggle dark/light mode" aria-label="Toggle theme">

    <!-- Desktop auth -->
    <div id="nav-guest" class="nav-desktop-auth" style="display:flex;gap:.75rem;align-items:center">
      <button class="btn btn-gold" onclick="openModal('auth-modal')">Login / Sign Up</button>
    </div>
    <div id="nav-user" class="nav-desktop-auth" style="display:none;gap:.75rem;align-items:center">
      <a href="#" onclick="showPage('profile');return false" id="nav-avatar-link" style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:var(--color-accent);overflow:hidden;flex-shrink:0;border:2px solid transparent;transition:border-color .15s" title="My Profile">
        <span id="nav-avatar-img" style="display:contents"></span>
      </a>
      <a href="#" id="nav-admin-link" style="display:none" onclick="showPage('admin')">⚙️</a>
      <button class="btn btn-sm" onclick="logout()">Logout</button>
    </div>

    <!-- Mobile: hamburger only -->
    <button class="nav-burger" id="nav-burger" onclick="openDrawer()" aria-label="Open navigation">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<!-- ════════ PAGE: HOME (landing / ask-a-question) ════════ -->
<div id="page-home" class="page">
<style>
/* ── Home page styles ── */
.home-wrap {
  min-height: calc(100vh - var(--nav-h));
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4rem;
  padding: 5rem 4rem;
  align-items: center;
  max-width: 1500px;
  margin: 0 auto;
}
.home-hero { display: flex; flex-direction: column; gap: 1.5rem; }
.home-hero h1 { font-size: clamp(2rem, 4vw, 3.2rem); font-weight: 700; line-height: 1.2; color: var(--text-primary); }
.home-hero .subtitle { font-size: 1.15rem; color: var(--muted); }
.home-cta { display: flex; gap: 1.2rem; flex-wrap: wrap; }
.home-cta .btn { border-radius: 50px; padding: 0.85rem 2rem; }
.home-social { display: flex; gap: 1.2rem; margin-top: 0.5rem; }
.home-social a {
  width: 44px; height: 44px;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid var(--border-color);
  border-radius: 50%; color: var(--text-primary);
  text-decoration: none; transition: all 0.3s;
}
.home-social a:hover { border-color: var(--color-accent); background: var(--color-accent); color: #000; }
.home-form-wrap {
  background: var(--bg-secondary);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  padding: 2.5rem;
}
.home-form-wrap .form-group { margin-bottom: 1.25rem; }
.home-form-wrap .form-group label { display: block; margin-bottom: 0.4rem; font-size: 0.9rem; font-weight: 500; color: var(--text-primary); }
.home-form-wrap .form-group label.req::after { content: " *"; color: #f87171; }
.home-form-wrap .form-group input,
.home-form-wrap .form-group textarea {
  width: 100%; padding: 0.85rem;
  background: var(--bg-tertiary); border: 1px solid var(--border-color);
  border-radius: 8px; color: var(--text-primary);
  font-family: inherit; font-size: 0.95rem; transition: border-color 0.2s;
}
.home-form-wrap .form-group input:focus,
.home-form-wrap .form-group textarea:focus { outline: none; border-color: var(--color-accent); }
.home-form-wrap .form-group textarea { min-height: 140px; resize: vertical; }
.home-form-actions { display: flex; gap: 1rem; margin-top: 1.5rem; }
.home-btn-submit { flex: 1; padding: 0.9rem; background: var(--color-accent); color: #0a0a09; font-weight: 700; border: none; border-radius: 8px; cursor: pointer; font-size: 0.95rem; transition: all 0.2s; }
.home-btn-submit:hover { background: var(--gold-dark); transform: translateY(-1px); }
.home-btn-donate { flex: 1; padding: 0.9rem; background: transparent; color: var(--text-primary); font-weight: 600; border: 2px solid var(--border-color); border-radius: 8px; cursor: pointer; font-size: 0.95rem; transition: all 0.2s; }
.home-btn-donate:hover { border-color: var(--color-accent); color: var(--color-accent); }
.home-btn-submit:disabled, .home-btn-donate:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
@media (max-width: 900px) {
  .home-wrap { grid-template-columns: 1fr; padding: 3rem 1.5rem; gap: 2.5rem; }
}
@media (max-width: 500px) {
  .home-cta { flex-direction: column; }
  .home-form-actions { flex-direction: column; }
}
</style>

  <div class="home-wrap">
    <div class="home-hero">
      <h1>Akilesh Ravindra Bhandary<br>– Independent Researcher</h1>
      <p class="subtitle">Solving Complex Problems in Physics and Mathematics</p>
      <div class="home-cta">
        <button class="btn btn-gold" onclick="showPage('simulations')">Browse Simulations</button>
        <button class="btn btn-outline" id="home-donate-btn"><span class="btn-content"><img src="https://storage.ko-fi.com/cdn/cup-border.png" alt="Ko-fi" class="kofi-icon">Donate For Research</span></button>
      </div>
      <div class="home-social">
        <a href="https://www.instagram.com/_annaswag/" target="_blank" title="Instagram">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
        </a>
        <a href="https://www.linkedin.com/in/akilesh-bhandary-0312b8115/" target="_blank" title="LinkedIn">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
        </a>
        <a href="mailto:bhandaryaki@gmail.com" title="Email">
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
        </a>
      </div>
    </div>

    <div class="home-form-wrap">
      <div id="home-form-msg"></div>
      <form id="home-question-form">
        <div class="form-group">
          <label for="hf-email" class="req">Email address</label>
          <input type="email" id="hf-email" placeholder="Your email address" required>
        </div>
        <div class="form-group">
          <label for="hf-question" class="req">Ask Any Physics Related Doubt</label>
          <textarea id="hf-question" placeholder="Enter your message" required></textarea>
        </div>
        <div class="home-form-actions">
          <button type="submit" class="home-btn-submit" id="hf-submit">Submit</button>
          <button type="button" class="home-btn-donate" id="hf-donate-submit"><span class="btn-content"><img src="https://storage.ko-fi.com/cdn/cup-border.png" alt="Ko-fi" class="kofi-icon">Donate &amp; Submit</span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Recent Donors -->
  <div style="max-width:860px;margin:0 auto;padding:0 1.5rem 3rem">
    <div class="section-title" style="margin-bottom:.5rem">Community Support</div>
    <div class="section-head" style="font-size:1.2rem;margin-bottom:1rem">Recent Donors</div>
    <div class="donors-list" id="recent-donors">
      <div style="color:var(--muted);font-size:.9rem">Loading donors…</div>
    </div>
    <div style="text-align:center;margin-top:1.25rem">
      <button class="btn btn-gold" onclick="window.open(DONATE_LINK,'_blank')"><span class="btn-content"><img src="https://storage.ko-fi.com/cdn/cup-border.png" alt="Ko-fi" class="kofi-icon">Support This Research</span></button>
    </div>
  </div>
</div>

<!-- ════════ PAGE: SIMULATIONS ════════ -->
<div id="page-simulations" class="page active">
  <div class="container">
    <div class="section-title">All Available</div>
    <div class="section-head">Interactive Simulations</div>
    <div class="section-sub">All simulations are freely accessible — explore and interact with each one</div>
    <div id="sim-grid-full" class="sim-grid"></div>
  </div>
</div>

<!-- ════════ PAGE: LIBRARY ════════ -->
<!-- page-library removed -->

<!-- ════════ PAGE: ABOUT ════════ -->
<div id="page-about" class="page">
<style>
.about-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 3rem; }
.about-card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 2rem; }
.about-card h3 { font-size: 1rem; font-weight: 700; color: var(--color-accent); margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; }
.about-card p { color: var(--muted); line-height: 1.7; font-size: 0.95rem; }
.about-contact { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 2rem; margin-bottom: 2rem; }
.about-contact-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-top: 1.25rem; }
.about-contact-item strong { display: block; font-size: 0.75rem; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); margin-bottom: 0.35rem; }
.about-contact-item a, .about-contact-item span { color: var(--text-primary); font-size: 0.95rem; text-decoration: none; }
.about-contact-item a:hover { color: var(--color-accent); }
@media (max-width: 600px) { .about-grid { grid-template-columns: 1fr; } }
</style>
  <div class="container" style="max-width:860px">
    <div class="section-title" style="margin-bottom:.5rem">Independent Researcher</div>
    <div class="section-head" style="margin-bottom:2.5rem">About Akilesh Ravindra Bhandary</div>

    <div class="about-grid">
      <div class="about-card">
        <h3>Research Focus</h3>
        <p>I am an independent researcher dedicated to solving complex problems in physics and mathematics through innovative approaches and rigorous analysis.</p>
      </div>
      <div class="about-card">
        <h3>Mission Statement</h3>
        <p>My mission is to advance understanding in physics and mathematics by tackling challenging problems with creativity, contributing meaningful insights to the scientific community.</p>
      </div>
    </div>

    <div class="about-card" style="margin-bottom:2rem">
      <h3>Complex Problem Solving</h3>
      <p style="margin-bottom:.75rem">Focused on advancing knowledge in physics and mathematics with original insights through independent research.</p>
      <p>Dedicated to exploring challenging physics and math problems through independent research and innovative approaches, employing analytical techniques, computational models, and theoretical frameworks to explore complex scientific questions.</p>
    </div>

    <div class="about-contact">
      <div class="section-title">Get In Touch</div>
      <div class="section-head" style="font-size:1.2rem;margin-bottom:.25rem">Connect</div>
      <p style="color:var(--muted);font-size:.9rem;margin-bottom:1rem">Reach out to discuss innovative physics research or mathematical challenges.</p>
      <div class="about-contact-grid">
        <div class="about-contact-item">
          <strong>Phone</strong>
          <a href="tel:+919819323326">+91 98193 23326</a>
        </div>
        <div class="about-contact-item">
          <strong>Email</strong>
          <a href="mailto:bhandaryaki@gmail.com">bhandaryaki@gmail.com</a>
        </div>
        <div class="about-contact-item">
          <strong>Instagram</strong>
          <a href="https://www.instagram.com/_annaswag/" target="_blank">@_annaswag</a>
        </div>
        <div class="about-contact-item">
          <strong>LinkedIn</strong>
          <a href="https://www.linkedin.com/in/akilesh-bhandary-0312b8115/" target="_blank">Akilesh Bhandary</a>
        </div>
      </div>
    </div>

    <div id="terms-section" style="margin-top:2rem">
      <div class="section-title" style="margin-bottom:.5rem">Legal</div>
      <div class="section-head" style="margin-bottom:1.5rem">Terms &amp; Conditions</div>
      <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius);padding:2rem;line-height:1.8;color:var(--muted);font-size:.92rem">

        <p style="margin-bottom:1.25rem">Welcome to <strong style="color:var(--text-primary)">independentresearcher.in</strong>! These terms and conditions outline the rules and regulations for the use of this website. By accessing this website, you accept these terms in full.</p>

        <h4 style="color:var(--text-primary);margin:1.25rem 0 .5rem;font-size:.95rem">Cookies</h4>
        <p style="margin-bottom:.5rem">We use two types of cookies on this site:</p>
        <p style="margin-bottom:.5rem"><strong style="color:var(--text-primary)">Strictly Necessary (cannot be declined):</strong> A PHP session cookie (<code>PHPSESSID</code>) that keeps you logged in. It contains only a session ID, no personal data, and is deleted when you close your browser or log out. Without it, login and Google Sign-In cannot function.</p>
        <p style="margin-bottom:.75rem"><strong style="color:var(--text-primary)">Optional:</strong> Razorpay, our payment processor, may set analytics cookies when you initiate a payment. These help detect fraud and improve payment reliability. You may decline these — our site continues to work fully, though Razorpay's fraud-detection may be limited. We use <code>localStorage</code> (not cookies) to remember your theme preference and cookie choice — this is not transmitted to any server.</p>

        <h4 style="color:var(--text-primary);margin:1.25rem 0 .5rem;font-size:.95rem">License</h4>
        <p style="margin-bottom:.5rem">Unless otherwise stated, Independent Researcher and/or its licensors own the intellectual property rights for all material on independentresearcher.in. You must not: copy, republish, sell, rent, sub-license, reproduce, duplicate, redistribute, or use the content commercially without express permission.</p>

        <h4 style="color:var(--text-primary);margin:1.25rem 0 .5rem;font-size:.95rem">Content Liability</h4>
        <p style="margin-bottom:.75rem">We shall not be held responsible for any content that appears on external websites that link to ours. No links should appear that may be interpreted as libellous, obscene, or criminal.</p>

        <h4 style="color:var(--text-primary);margin:1.25rem 0 .5rem;font-size:.95rem">Disclaimer</h4>
        <p>To the maximum extent permitted by applicable law, we exclude all representations, warranties, and conditions relating to our website and the use of this website. We will not be liable for any loss or damage of any nature as long as the website and its services are provided free of charge.</p>
      </div>
    </div>
  </div>
</div>


<!-- ════════ PAGE: BLOG ════════ -->
<div id="page-blog" class="page">
  <div class="container" id="blog-list-view">
    <div class="section-title">Research &amp; Insights</div>
    <div class="section-head" style="margin-bottom:.5rem">Blog</div>
    <p class="section-sub">Physics research notes, simulation explanations, and mathematical explorations.</p>
    <div id="blog-grid" class="blog-grid"><div style="color:var(--muted)">Loading…</div></div>
    <div style="text-align:center;margin-top:1.5rem">
      <button id="blog-load-more" class="btn btn-outline" style="display:none" onclick="loadBlogPosts(true)">Load More Posts</button>
    </div>
  </div>
  <!-- Single post reader (shown when a post is clicked) -->
  <div id="blog-post-view" style="display:none">
    <div class="blog-post-content">
      <button class="blog-back-btn" onclick="showBlogList()">← Back to Blog</button>
      <h1 id="post-view-title"></h1>
      <div class="post-meta" id="post-view-meta"></div>
      <div class="blog-post-body" id="post-view-body"></div>

      <!-- Interactions bar: like + comment toggle -->
      <div class="blog-interactions" id="post-view-interactions" style="display:none">
        <div class="blog-like-wrap">
          <button class="blog-like-btn" id="blog-like-btn" onclick="toggleBlogLike()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            <span id="blog-like-label">Like</span>
          </button>
          <span class="blog-like-tooltip" id="blog-like-tooltip">0 likes</span>
        </div>
        <button class="blog-comment-toggle" id="blog-comment-toggle-btn" onclick="toggleBlogComments()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span id="blog-comment-count-label">Comments</span>
        </button>
        <button class="blog-like-btn" id="blog-share-btn" onclick="copyBlogLink()" style="gap:.4rem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
          <span id="blog-share-label">Share</span>
        </button>
        <a class="blog-like-btn" id="blog-open-btn" href="#" target="_blank" rel="noopener" style="text-decoration:none;gap:.4rem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Open
        </a>
      </div>

      <!-- Comment section -->
      <div id="post-view-comments">
        <div class="blog-comments-header">Comments</div>
        <div id="blog-new-comment-area"></div>
        <div id="blog-comment-list"></div>
        <div id="blog-comments-loadmore"></div>
      </div>
    </div>
  </div>

  <!-- Blog thread deep-view (SPA sub-page, no URL change) -->
  <div id="blog-thread-view" style="display:none">
    <div class="blog-post-content">
      <button class="blog-thread-back" onclick="closeBlogThread()">← Back to post</button>
      <div class="blog-thread-heading" id="blog-thread-heading">Full thread</div>
      <div class="blog-thread-sub" id="blog-thread-sub"></div>
      <div id="blog-thread-root-comment"></div>
      <div class="blog-reply-thread" id="blog-thread-reply-list"></div>
      <div id="blog-thread-loadmore"></div>
    </div>
  </div>
</div>

<?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
<div id="page-admin" class="page">
  <div class="container" style="max-width:1200px">
    <div class="section-head" style="margin-bottom:1.25rem">Admin Dashboard</div>
    <div class="admin-layout">
      <!-- Sidebar nav -->
      <div class="admin-sidebar">
        <h5>Overview</h5>
        <button class="admin-nav-btn active" data-tab="dashboard" onclick="showAdminTab('dashboard')"><span class="icon">📊</span> Dashboard</button>
        <h5>Content</h5>
        <button class="admin-nav-btn" data-tab="products" onclick="showAdminTab('products')"><span class="icon">🔬</span> Simulations</button>
        <button class="admin-nav-btn" data-tab="blog" onclick="showAdminTab('blog')"><span class="icon">📝</span> Blog Posts</button>
        <button class="admin-nav-btn" data-tab="blog-comments" onclick="showAdminTab('blog-comments')"><span class="icon">💬</span> Blog Comments</button>
        <h5>Users</h5>
        <button class="admin-nav-btn" data-tab="users" onclick="showAdminTab('users')"><span class="icon">👥</span> Users</button>
        <button class="admin-nav-btn" data-tab="purchases" onclick="showAdminTab('purchases')"><span class="icon">🛒</span> Purchases</button>
        <h5>System</h5>
        <button class="admin-nav-btn" data-tab="reports" onclick="showAdminTab('reports')"><span class="icon">🗄</span> SQL Reports</button>
        <button class="admin-nav-btn" data-tab="logs" onclick="showAdminTab('logs')"><span class="icon">📋</span> Error Logs</button>
        <button class="admin-nav-btn" data-tab="settings" onclick="showAdminTab('settings')"><span class="icon">🔑</span> Change Password</button>
      </div>
      <!-- Content area -->
      <div class="admin-content-area">

    <!-- Dashboard Tab -->
    <div id="admin-dashboard" class="admin-tab-content active">
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;" id="admin-metrics"></div>
      <h3 style="margin: 1.5rem 0 1rem;">Recent Purchases</h3>
      <div id="admin-recent-purchases"></div>
    </div>

    <!-- Products Tab -->
    <div id="admin-products" class="admin-tab-content">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;flex-wrap:wrap;gap:.5rem">
        <div>
          <h3 style="margin-bottom:.25rem">Manage Simulations</h3>
          <p style="font-size:.8rem;color:var(--muted)">Trial files -> <code>simulations/trial/</code> &nbsp;|&nbsp; Paid files -> <code>simulations/paid/</code></p>
        </div>
        <button class="btn btn-gold" onclick="openProductModal()">+ Add New</button>
      </div>
      <div id="admin-products-list"></div>
    </div>

    <!-- Users Tab -->
    <div id="admin-users" class="admin-tab-content">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>User Accounts</h3>
        <input type="search" id="user-search" placeholder="Filter by email…" oninput="filterUsers()" style="padding:.45rem .85rem;background:var(--bg-tertiary);border:1px solid var(--border-color);border-radius:6px;color:var(--text-primary);font-size:.85rem;width:220px">
      </div>
      <div id="admin-users-list"></div>
    </div>

    <!-- Purchases Tab -->
    <div id="admin-purchases" class="admin-tab-content">
      <h3>All Purchases</h3>
      <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
        <select id="purchase-filter-user"><option value="">All Users</option></select>
        <select id="purchase-filter-product"><option value="">All Products</option></select>
        <select id="purchase-filter-status">
          <option value="">All Status</option>
          <option value="paid">Paid</option>
          <option value="demo">Demo</option>
          <option value="pending">Pending</option>
          <option value="failed">Failed</option>
        </select>
        <button class="btn btn-gold btn-sm" onclick="loadAdminPurchases()">Apply Filters</button>
      </div>
      <div id="admin-purchases-list"></div>
      <div id="admin-purchases-pagination" class="pagination"></div>
    </div>

    <!-- Error Logs Tab -->
    <div id="admin-logs" class="admin-tab-content">
      <h3>Error Logs (last 100 lines)</h3>
      <pre id="admin-error-logs" style="background: #1e1e1e; padding: 1rem; border-radius: 6px; overflow: auto; max-height: 500px; font-size: 0.8rem; line-height: 1.4;"></pre>
    </div>


    <!-- Blog Admin Tab -->
    <div id="admin-blog" class="admin-tab-content">
      <div class="blog-editor-wrap">
        <!-- Post list sidebar -->
        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
            <strong style="font-size:.9rem">Posts</strong>
            <button class="btn btn-gold btn-sm" onclick="newBlogPost()">+ New</button>
          </div>
          <div id="admin-blog-list"></div>
        </div>
        <!-- Editor -->
        <div id="admin-blog-editor" style="display:none">
          <input type="hidden" id="blog-edit-id" value="">
          <div class="form-group">
            <label>Title *</label>
            <input type="text" id="blog-edit-title" placeholder="Post title…" style="font-size:1.1rem;font-weight:700">
          </div>
          <div class="form-group">
            <label>Summary (shown in card preview)</label>
            <input type="text" id="blog-edit-summary" placeholder="One-line summary…">
          </div>
          <div class="form-group">
            <label>Cover Image URL (optional)</label>
            <input type="text" id="blog-edit-cover" placeholder="https://…">
          </div>
          <div class="form-group">
            <label>Content * <span style="color:var(--muted);font-size:.78rem">(HTML supported: &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;strong&gt;, &lt;code&gt;, &lt;pre&gt;, &lt;img&gt;)</span></label>
            <textarea id="blog-content-editor" placeholder="Write your post here…"></textarea>
          </div>
          <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:.5rem">
            <select id="blog-edit-status" style="padding:.5rem;background:var(--bg-tertiary);border:1px solid var(--border-color);border-radius:6px;color:var(--text-primary)">
              <option value="draft">Draft</option>
              <option value="published">Published</option>
            </select>
            <button class="btn btn-gold" onclick="saveBlogPost()">💾 Save Post</button>
            <button class="btn btn-outline btn-sm" onclick="deleteBlogPost()">🗑 Delete</button>
            <span id="blog-save-msg" style="font-size:.82rem"></span>
          </div>
        </div>
        <div id="admin-blog-placeholder" style="color:var(--muted);font-size:.9rem;padding:2rem">
          Select a post to edit, or create a new one.
        </div>
      </div>
    </div>

    <!-- Blog Comments Tab -->
    <div id="admin-blog-comments" class="admin-tab-content">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
        <h3 style="margin:0">Blog Comments</h3>
        <button class="btn btn-outline" style="font-size:.82rem;padding:.35rem .8rem" onclick="loadAdminBlogComments(0)">Refresh</button>
      </div>
      <div id="admin-blog-comments-list" style="display:flex;flex-direction:column;gap:.75rem"></div>
      <div id="admin-blog-comments-loadmore" style="margin-top:1rem"></div>
    </div>

    <!-- SQL Reports Tab -->
    <div id="admin-reports" class="admin-tab-content">
      <div class="report-layout">
        <div class="report-sidebar">
          <h4>Saved Reports</h4>
          <div id="report-list"></div>
          <h4 style="margin-top:1rem">Custom Query</h4>
          <div class="report-item" id="custom-report-btn" onclick="selectCustomReport()">
            <span>✏️</span> Write SQL
          </div>
        </div>
        <div class="report-main">
          <div>
            <h3 id="report-title">Select a report</h3>
            <p style="font-size:.85rem;color:var(--muted)" id="report-subtitle">Choose a saved report from the left, or write a custom SELECT query.</p>
          </div>
          <textarea class="report-query-box" id="report-query" placeholder="SELECT * FROM users LIMIT 10;" spellcheck="false"></textarea>
          <div class="report-run-bar">
            <button class="btn btn-gold btn-sm" onclick="runReport()">▶ Run Query</button>
            <span class="report-count" id="report-count"></span>
          </div>
          <div id="report-output"></div>
        </div>
      </div>
    </div>

    <!-- Settings Tab (Change Password) -->
    <div id="admin-settings" class="admin-tab-content">
      <h3>Change Password</h3>
      <form onsubmit="changeAdminPassword(event)">
        <div class="form-group">
          <label>Current Password</label>
          <input type="password" id="admin-old-pw" required>
        </div>
        <div class="form-group">
          <label>New Password (min 8 characters)</label>
          <input type="password" id="admin-new-pw" required minlength="8">
        </div>
        <div class="form-group">
          <label>Confirm New Password</label>
          <input type="password" id="admin-confirm-pw" required minlength="8">
        </div>
        <button type="submit" class="btn btn-gold">Update Password</button>
        <div id="admin-password-message" class="alert" style="margin-top:1rem; display:none;"></div>
      </form>
    </div>
    <!-- end admin tabs -->
      </div><!-- /admin-content-area -->
    </div><!-- /admin-layout -->
  </div><!-- /container -->
</div><!-- /page-admin -->
<?php endif; // admin only ?>


<!-- ════════ PAGE: PROFILE ════════ -->
<div id="page-profile" class="page">
  <div class="profile-wrap">
    <div class="section-title">Account</div>
    <div class="section-head" style="margin-bottom:1.5rem">My Profile</div>

    <!-- Avatar section -->
    <div class="profile-card">
      <h4>Profile Photo</h4>
      <div class="profile-avatar-section">
        <div id="profile-avatar-display"></div>
        <div class="profile-avatar-actions">
          <label for="avatar-file-input" class="btn btn-outline btn-sm" style="cursor:pointer;display:inline-block">
            📷 Upload Photo
          </label>
          <input type="file" id="avatar-file-input" accept="image/jpeg,image/png,image/webp,image/gif"
                 style="display:none" onchange="uploadAvatar(this)">
          <small>Max 2 MB · JPEG, PNG, WebP, GIF</small>
          <div id="avatar-msg" style="font-size:.8rem"></div>
        </div>
      </div>
    </div>

    <!-- Display name -->
    <div class="profile-card">
      <h4>Display Name</h4>
      <form onsubmit="saveProfile(event)">
        <div class="form-group">
          <label>Name <span style="color:var(--muted);font-size:.8rem">(shown in nav instead of email)</span></label>
          <input type="text" id="profile-name" placeholder="Your name" maxlength="80">
        </div>
        <button type="submit" class="btn btn-gold btn-sm">Save Name</button>
        <div id="profile-name-msg" style="font-size:.8rem;margin-top:.5rem"></div>
      </form>
    </div>

    <!-- Account info (read-only) -->
    <div class="profile-card">
      <h4>Account Information</h4>
      <div style="display:grid;gap:.75rem">
        <div><strong style="font-size:.8rem;color:var(--muted);display:block;text-transform:uppercase;letter-spacing:.5px">Email</strong>
          <span id="profile-email">—</span></div>
        <div><strong style="font-size:.8rem;color:var(--muted);display:block;text-transform:uppercase;letter-spacing:.5px">Sign-in method</strong>
          <span id="profile-provider">—</span></div>
        <div><strong style="font-size:.8rem;color:var(--muted);display:block;text-transform:uppercase;letter-spacing:.5px">Member since</strong>
          <span id="profile-joined">—</span></div>
      </div>
    </div>
  </div><!-- /profile-wrap -->
</div><!-- /page-profile -->

<!-- Product Edit Modal (hidden by default) -->
<div id="product-edit-modal" class="modal-bg" onclick="closeBgClick(event,'product-edit-modal')">
  <div class="modal" style="max-width: 700px;">
    <button class="modal-close" onclick="closeModal('product-edit-modal')">×</button>
    <h2 id="product-modal-title">Add / Edit Simulation</h2>
    <form onsubmit="saveProduct(event)">
      <input type="hidden" id="product-id">
      <div class="form-group">
        <label>Title</label>
        <input type="text" id="product-title" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea id="product-description" required rows="3"></textarea>
      </div>
      <div class="form-group">
        <label>Price (INR)</label>
        <input type="number" id="product-price" step="0.01" min="0" required>
      </div>
      <div class="form-group">
        <label>Preview Image (optional)</label>
        <input type="text" id="product-preview-image" placeholder="URL or filename">
      </div>
      <div class="form-group">
        <label>Tags (comma separated)</label>
        <input type="text" id="product-tags" placeholder="mechanics, beginner, etc.">
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" id="product-active" checked> Active (visible to users)
        </label>
      </div>
      <div class="form-group">
        <label>Access Badge</label>
        <select id="product-access-badge" style="width:100%;padding:.6rem .75rem;background:var(--bg-tertiary);border:1px solid var(--border-color);border-radius:6px;color:var(--text-primary);font-size:.9rem">
          <option value="trial_only">FREE TRIAL — trial only, no upgrade path</option>
          <option value="trial_upgrade">FREE TRIAL — trial with upgrade available</option>
          <option value="paid_only">PAID — paid access only</option>
        </select>
      </div>
      <button type="submit" class="btn btn-gold">Save Simulation</button>
      <div id="product-save-message" class="alert" style="margin-top:1rem; display:none;"></div>
    </form>
  </div>
</div>


<!-- ════════ SHARED: GET IN TOUCH + FOOTER ════════ -->
<!-- Injected at end of every .page div via JS showPage() -->
<div id="get-in-touch-block" style="display:none">

<!-- Get in Touch -->
<div class="git-section">
  <div class="git-inner">
    <div class="git-left">
      <h2>Get in Touch</h2>
      <p>Reach out to discuss innovative physics research or mathematical challenges with independent researcher Akilesh Ravindra Bhandary.</p>
      <div class="git-contact">
        <strong>Contact</strong>
        <a href="tel:+919819323326">+91 98193 23326</a>
      </div>
      <div class="git-contact">
        <strong>Inquiry</strong>
        <a href="mailto:bhandaryaki@gmail.com">bhandaryaki@gmail.com</a>
      </div>
    </div>
    <div>
      <div id="git-msg"></div>
      <form class="git-form" id="git-form" onsubmit="submitGitForm(event)">
        <label>Your First Name</label>
        <input type="text" id="git-name" placeholder="Enter first name">
        <label class="req">Your Email Address</label>
        <input type="email" id="git-email" placeholder="Enter email address" required>
        <label class="req">Your Message</label>
        <textarea id="git-message" placeholder="Write your message here" required></textarea>
        <button type="submit" class="btn btn-gold" id="git-submit">Send Message</button>
      </form>
    </div>
  </div>
</div>

<!-- Footer -->
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-brand">
      <h3>Akilesh <span style="color:var(--color-accent)">Bhandary</span></h3>
      <p>Independent research in physics and mathematics.</p>
    </div>
    <div class="footer-col">
      <a href="/sitemap.php">Sitemap</a>
      <a href="/terms.php" target="_blank">Terms &amp; Conditions</a>
      <a href="tel:+919819323326">+91 98193 23326</a>
      <a href="mailto:bhandaryaki@gmail.com">bhandaryaki@gmail.com</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© <?php echo date('Y'); ?> Akilesh Ravindra Bhandary. All rights reserved.</span>
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap">
      <a href="/terms.php" target="_blank">Terms &amp; Conditions</a>
      <a href="/sitemap.php">Sitemap</a>
      <a href="mailto:bhandaryaki@gmail.com">Contact</a>
    </div>
  </div>
</footer>
</div>


<!-- ════════ MODALS ════════ -->
<div id="auth-modal" class="modal-bg">
  <div class="modal" style="max-width: 450px;">
    <button class="modal-close" onclick="closeModal('auth-modal')">×</button>
    
    <!-- Title - Completely separate block -->
    <div style="width:100%;display:block;clear:both">
      <h2 style="text-align:center;margin:0 0 2rem 0;font-size:1.75rem;display:block;width:100%" id="auth-title">Login / Sign Up</h2>
    </div>
    
    <!-- Tab Switcher - Separate block below title -->
    <div style="width:100%;display:block;clear:both">
      <div style="display:flex;gap:0;border-bottom:2px solid var(--border-color);margin-bottom:2rem;width:100%">
        <button id="tab-login" onclick="switchAuthTab('login')" 
          style="flex:1;padding:1rem;background:none;border:none;color:var(--text-primary);font-size:1rem;font-weight:600;cursor:pointer;border-bottom:3px solid var(--color-accent);transition:all 0.3s">
          Sign In
        </button>
      </div>
    </div>
    
    <!-- Forms - Separate block -->
    <div style="width:100%;display:block;clear:both">
      <!-- Login Form -->
      <form id="form-login" onsubmit="login(event)" style="display:block">
        <div id="login-alert"></div>
        
        <div class="form-group">
          <label>Email</label>
          <input type="email" id="login-email" required placeholder="your@email.com">
        </div>
        
        <div class="form-group">
          <label>Password</label>
          <input type="password" id="login-password" required placeholder="••••••••">
        </div>
        
        <div style="text-align:right;margin-bottom:1.5rem">
          <a href="#" onclick="event.preventDefault(); requestPasswordReset()" 
            style="color:var(--color-accent);font-size:0.9rem;text-decoration:none">
            Forgot password?
          </a>
        </div>
        
        <button type="submit" class="btn btn-gold" style="width:100%;padding:1rem">
          Login
        </button>
      </form>
      
      <!-- Register Form -->
      <form id="form-register" onsubmit="register(event)" style="display:none">
        <div id="register-alert"></div>
        
        <div class="form-group">
          <label>Email</label>
          <input type="email" id="register-email" required placeholder="your@email.com">
        </div>
        
        <div class="form-group">
          <label>Password</label>
          <input type="password" id="register-password" required minlength="6" placeholder="At least 6 characters">
        </div>
        
        <button type="submit" class="btn btn-gold" style="width:100%;padding:1rem">
          Create Account
        </button>
      </form>
      
      <!-- Google Sign-in (MOVED INSIDE MODAL) -->
      <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border);">
        <p style="text-align: center; color: var(--text-muted); margin-bottom: 1rem;">
          Or continue with
        </p>
        <button onclick="signInWithGoogle()" 
          style="width: 100%; padding: 0.75rem; background: #fff; color: #444; border: 1px solid #ddd; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.75rem;">
          <svg width="20" height="20" viewBox="0 0 24 24">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
          </svg>
          Continue with Google
        </button>
      </div>
      
      <script>
      function signInWithGoogle() {
        window.location.href = '/auth-google.php?action=login';
      }
      </script>
    </div>
  </div>
</div>



<!-- ── NEW: Product Modal ── -->
<div id="product-modal" class="modal-bg" onclick="closeBgClick(event,'product-modal')">
  <div class="modal" style="max-width: 700px;">
    <button class="modal-close" onclick="closeModal('product-modal')">×</button>
    <div id="product-detail"></div>
  </div>
</div>

<!-- ── NEW: Donation Modal ── -->
<div id="donation-modal" class="modal-bg" onclick="closeBgClick(event,'donation-modal')">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('donation-modal')">×</button>
    <h2>Support Independent Research</h2>
    <p>Your contribution helps advance physics and mathematics research.</p>
    <div id="donation-alert"></div>
    <form onsubmit="createDonation(event)">
      <div class="form-group">
        <label>Your Name *</label>
        <input type="text" id="donor-name" required>
      </div>
      <div class="form-group">
        <label>Email Address *</label>
        <input type="email" id="donor-email" required>
      </div>
      <div class="form-group">
        <label>Amount (₹) *</label>
        <input type="number" id="donor-amount" min="10" max="100000" step="1" required>
      </div>
      <div class="form-group">
        <label>Message (optional)</label>
        <textarea id="donor-message" rows="3" placeholder="Your words of encouragement..."></textarea>
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" id="donor-anonymous"> Show as anonymous
        </label>
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" id="donor-show" checked> Show my name in donors list
        </label>
      </div>
      <button type="submit" class="btn btn-gold" style="width:100%">Proceed to Payment</button>
    </form>
    <hr style="border-color: var(--border-color); margin: 1.5rem 0;">
    <p style="text-align:center; color: var(--muted);">Or use our quick donation page:</p>
    <a href="<?php echo DONATE_LINK; ?>" target="_blank" class="btn btn-outline" style="display:block; text-align:center;">Donate via Razorpay</a>
  </div>
</div>

<!-- Razorpay loaded on demand inside loadRazorpay() — see purchaseProduct() below -->
<script>
// ══ Theme — synced with index.html via localStorage key 'ir_theme' ════════

// Starfield (dark mode) ─────────────────────────────────────────────────────
(function() {
  const canvas=document.getElementById('starCanvas');
  if(!canvas) return;
  const ctx=canvas.getContext('2d');
  let stars=[],shoots=[],nextTimer=0,rafId=null,lastTs=0,isDark=true;
  const BASE=120,MIN=60,MAX=160,LIFE=[3,8];
  function mkStar(w,h){const oR=Math.random()*1.8+0.8;return{x:Math.random()*w,y:Math.random()*h,oR,iR:oR*0.4,e:[3,4,5][Math.floor(Math.random()*3)],rot:Math.random()*360,life:LIFE[0]+Math.random()*(LIFE[1]-LIFE[0]),age:0};}
  function init(w,h){const n=Math.min(MAX,Math.max(MIN,Math.floor(BASE*w*h/(1920*1080))));stars=Array.from({length:n},()=>mkStar(w,h));}
  function mkShoot(w,h){const sx=Math.random()*w*.5,sy=Math.random()*h*.3,a=((Math.random()-.5)*40)*Math.PI/180,dist=Math.hypot(w,h)*(0.8+Math.random()*.7);return{sx,sy,ex:sx+Math.cos(a)*dist,ey:sy+Math.sin(a)*dist,dist,gone:0,spd:2+Math.random()*3,acc:.5+Math.random()*.7,trail:[],hue:Math.random()*360};}
  function frame(ts){
    if(!isDark){rafId&&cancelAnimationFrame(rafId);rafId=null;return;}
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
  function start(){if(rafId)return;lastTs=0;rafId=requestAnimationFrame(frame);}
  window.addEventListener('load',()=>{ if(window.matchMedia('(prefers-reduced-motion: reduce)').matches) return; canvas.width=window.innerWidth;canvas.height=window.innerHeight;init(canvas.width,canvas.height);start();window.addEventListener('resize',()=>{canvas.width=window.innerWidth;canvas.height=window.innerHeight;init(canvas.width,canvas.height);shoots=[];});});
  window.setStarfieldDarkMode=dark=>{isDark=dark;if(isDark)start();else{rafId&&cancelAnimationFrame(rafId);rafId=null;ctx.clearRect(0,0,canvas.width,canvas.height);}};
})();

// Light blobs ───────────────────────────────────────────────────────────────
(function(){
  const canvas=document.getElementById('lightBgCanvas');
  if(!canvas)return;
  const ctx=canvas.getContext('2d');
  let rafId=null,isLight=false,blobs=[];
  function mk(w,h){blobs=Array.from({length:6},()=>({x:Math.random()*w,y:Math.random()*h,r:100+Math.random()*200,hue:Math.random()*360,vx:(Math.random()-.5)*30,vy:(Math.random()-.5)*20}));}
  function loop(ts){if(!isLight){rafId&&cancelAnimationFrame(rafId);rafId=null;return;}const dt=Math.min(.05,(ts-(window._slt||ts))/1e3);window._slt=ts;blobs.forEach(b=>{b.x+=b.vx*dt;b.y+=b.vy*dt;b.hue=(b.hue+15*dt)%360;if(b.x<-b.r)b.x=canvas.width+b.r;if(b.x>canvas.width+b.r)b.x=-b.r;if(b.y<-b.r)b.y=canvas.height+b.r;if(b.y>canvas.height+b.r)b.y=-b.r;});ctx.clearRect(0,0,canvas.width,canvas.height);blobs.forEach(b=>{const g=ctx.createRadialGradient(b.x,b.y,10,b.x,b.y,b.r);g.addColorStop(0,`hsla(${b.hue},80%,60%,.25)`);g.addColorStop(.6,`hsla(${b.hue+40},80%,50%,.15)`);g.addColorStop(1,`hsla(${b.hue+80},80%,40%,0)`);ctx.fillStyle=g;ctx.beginPath();ctx.arc(b.x,b.y,b.r,0,Math.PI*2);ctx.fill();});rafId=requestAnimationFrame(loop);}
  function start(){if(rafId)return;window._slt=0;rafId=requestAnimationFrame(loop);}
  window.addEventListener('load',()=>{ if(window.matchMedia('(prefers-reduced-motion: reduce)').matches) return; canvas.width=window.innerWidth;canvas.height=window.innerHeight;mk(canvas.width,canvas.height);window.addEventListener('resize',()=>{canvas.width=window.innerWidth;canvas.height=window.innerHeight;mk(canvas.width,canvas.height);});});
  window.setLightBgMode=light=>{isLight=light;if(isLight)start();else{rafId&&cancelAnimationFrame(rafId);rafId=null;ctx.clearRect(0,0,canvas.width,canvas.height);}};
})();

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  // Also apply body class for canvas bg visibility
  document.body.classList.remove('theme-dark','theme-light');
  document.body.classList.add('theme-'+theme);
  const dark = theme==='dark';
  const sc = document.getElementById('starCanvas');
  const lc = document.getElementById('lightBgCanvas');
  if(sc){sc.style.opacity=dark?'1':'0';sc.style.visibility=dark?'visible':'hidden';}
  if(lc){lc.style.opacity=dark?'0':'1';lc.style.visibility=dark?'hidden':'visible';}
  window.setStarfieldDarkMode?.(dark);
  window.setLightBgMode?.(!dark);
  const t=document.getElementById('simThemeToggle');
  if(t) t.checked=!dark;
}

function initTheme() {
  const stored = localStorage.getItem('ir_theme');
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const theme = stored || (prefersDark ? 'dark' : 'light');
  applyTheme(theme);
}

function toggleTheme() {
  const cur = document.documentElement.getAttribute('data-theme');
  const next = cur==='dark' ? 'light' : 'dark';
  applyTheme(next);
  localStorage.setItem('ir_theme', next);
  if (typeof BroadcastChannel !== 'undefined') {
    new BroadcastChannel('ir_theme').postMessage(next);
  }
}

// Wire the animated toggle
document.addEventListener('DOMContentLoaded', () => {
  const t = document.getElementById('simThemeToggle');
  t?.addEventListener('change', e => {
    const theme = e.target.checked ? 'light' : 'dark';
    applyTheme(theme);
    localStorage.setItem('ir_theme', theme);
    if (typeof BroadcastChannel !== 'undefined') {
      new BroadcastChannel('ir_theme').postMessage(theme);
    }
  });
});

// Keep old updateThemeIcon as no-op for safety
function updateThemeIcon() {}

// ══ Auth Tab Switcher ══════════════════════════════════════════════════
function switchAuthTab(tab) {
  const loginTab = document.getElementById('tab-login');
  const registerTab = document.getElementById('tab-register');
  const loginForm = document.getElementById('form-login');
  const registerForm = document.getElementById('form-register');
  const title = document.getElementById('auth-title');
  
  if (tab === 'login') {
    // Change title
    title.textContent = 'Welcome Back';
    
    // Style tabs
    loginTab.style.color = 'var(--text-primary)';
    loginTab.style.borderBottomColor = 'var(--color-accent)';
    registerTab.style.color = 'var(--muted)';
    registerTab.style.borderBottomColor = 'transparent';
    
    // Show/hide forms
    loginForm.style.display = 'block';
    registerForm.style.display = 'none';
  } else {
    // Change title
    title.textContent = 'Create Account';
    
    // Style tabs
    registerTab.style.color = 'var(--text-primary)';
    registerTab.style.borderBottomColor = 'var(--color-accent)';
    loginTab.style.color = 'var(--muted)';
    loginTab.style.borderBottomColor = 'transparent';
    
    // Show/hide forms
    registerForm.style.display = 'block';
    loginForm.style.display = 'none';
  }
}

// Initialize theme on load
initTheme();
// ── State ──────────────────────────────────────────────────────────────────────
let currentUser = <?php echo $currentUser ? json_encode($currentUser, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) : 'null'; ?>;
let products = [];
let config = { demo_mode: <?php echo DEMO_MODE ? 'true' : 'false'; ?> };
const DONATE_LINK = <?php echo json_encode(DONATE_LINK); ?>;

const SIM_ICONS = {
  'sim-pendulum': '⚖️',
  'sim-orbital': '🪐',
  'sim-double-pendulum': '🔗',
  'sim-projectile': '🎯',
  'sim-gravity': '✨',
  'sim-gravity-asteroid': '☄️',
  'sim-buoyancy': '🌊',
  'sim-viscous-fluid': '💧',
  'sim-particle-floating': '🫧',
  'sim-energy-sources': '⚡',
  'default': '🔬'
};

// Initialize theme on page load
initTheme();


// ── Init ───────────────────────────────────────────────────────────────────────
async function init() {
  try {
  // Normalise admin flag (Google OAuth sets .role, email login sets .is_admin)
  if (typeof currentUser !== 'undefined' && currentUser && !currentUser.is_admin && currentUser.role === 'admin') {
    currentUser.is_admin = true;
  }
  initTheme();
  await loadProducts();
  updateUI();
  
  // Load grids after products are loaded
  if (products && products.length > 0) {
    loadSimGrid('home-sim-grid', 4);
    loadSimGrid('sim-grid-full');
    } else {
    console.error('No products available to display');
  }
    // ── NEW: Load recent donors on home page ──
  loadRecentDonors();

  // ── Handle ?auth=1&return=<url> — auto-open login modal, redirect after login
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('auth') === '1' && !currentUser) {
    const returnUrl = urlParams.get('return');
    if (returnUrl) {
      window._authReturnUrl = decodeURIComponent(returnUrl);
    }
    openModal('auth-modal');
  }

  // ── Handle ?page=<name> — jump directly to a named tab on load
  const pageParam = urlParams.get('page');
  if (pageParam && ['home','simulations','blog','about','profile'].includes(pageParam)) {
    showPage(pageParam);
  }

  } catch (initErr) {
    // Silent fail - prevents one error cascading to blank page
  }
}

async function loadProducts() {
  try {
    // Fetch products first (required), usage + ratings are optional (new tables may not exist yet)
    const prodRes = await fetch('api.php?action=products', { cache: 'no-store' });
    products = prodRes.ok ? await prodRes.json() : [];
    if (!Array.isArray(products)) products = [];

    // Fetch usage + ratings with individual fallbacks
    let usageData   = [];
    let ratingsData = {};
    try {
      const ur = await fetch('api.php?action=usage-stats', { cache: 'no-store' });
      if (ur.ok) { const d = await ur.json(); if (Array.isArray(d)) usageData = d; }
    } catch(e) { /* tables not created yet — skip */ }
    try {
      const rr = await fetch('api.php?action=sim-ratings-bulk', { cache: 'no-store' });
      if (rr.ok) { const d = await rr.json(); if (d && typeof d === 'object') ratingsData = d; }
    } catch(e) { /* tables not created yet — skip */ }

    // Build usage lookup map
    const usageMap = {};
    usageData.forEach(u => { if (u.product_id) usageMap[u.product_id] = u; });

    // Merge usage + ratings into products
    products = products.map(p => ({
      ...p,
      _usage_seconds: usageMap[p.id]?.total_seconds || 0,
      _unique_users:  usageMap[p.id]?.unique_users  || 0,
      _avg_rating:    ratingsData[p.id]?.avg          || 0,
      _rating_count:  ratingsData[p.id]?.cnt          || 0,
    }));

    // Sort by global usage descending; fall back to original DB order if no usage yet
    products.sort((a, b) => (b._usage_seconds || 0) - (a._usage_seconds || 0));

  } catch (e) {
    console.error('Failed to load products:', e);
  }
}

function updateUI() {
  if (currentUser) {
    document.getElementById('nav-guest').style.display = 'none';
    document.getElementById('nav-user').style.display = 'flex';
  setTimeout(syncDrawerAuth, 0);
    // Load profile to get display name + avatar
    api('api.php?action=get-profile').then(r => r.json()).then(p => { if (p.id) updateNavAvatar(p); }).catch(() => {});
    if (currentUser.is_admin || currentUser.role === 'admin') {
      document.getElementById('nav-admin-link').style.display = 'inline-flex';
    }
  } else {
    document.getElementById('nav-guest').style.display = 'flex';
    document.getElementById('nav-user').style.display = 'none';
  }
}

// ── API ────────────────────────────────────────────────────────────────────────
async function api(url, opts = {}) {
  return fetch(url, {
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    cache: 'no-store',  // never use cached API responses
    ...opts
  });
}

// ── Auth ───────────────────────────────────────────────────────────────────────
async function login(e) {
  e.preventDefault();
  const email = document.getElementById('login-email').value;
  const password = document.getElementById('login-password').value;
  
  
  try {
    const r = await api('api.php?action=login', { 
      method: 'POST', 
      body: JSON.stringify({ email, password }) 
    });
    const data = await r.json();
    
    if (data.success) {
      currentUser = { email: data.email, is_admin: data.is_admin };
      updateUI();
      closeModal('auth-modal');
      // If user arrived via ?auth=1&return=<url>, redirect back to the simulation
      if (window._authReturnUrl) {
        window.location.href = window._authReturnUrl;
        return;
      }
      loadProducts().then(() => { loadSimGrid('sim-grid-full'); updateUI(); });
    } else {
      showAlert('login-alert', data.error || 'Login failed', 'error');
    }
  } catch (err) {
    console.error('Login error:', err);
    showAlert('login-alert', 'Network error. Please try again.', 'error');
  }
}

async function register(e) {
  e.preventDefault();
  const email = document.getElementById('register-email').value;
  const password = document.getElementById('register-password').value;
  
  try {
    const r = await api('api.php?action=register', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
    const data = await r.json();
    
    if (data.success) {
      // GA4 conversion event — new account created
      if (typeof gtag === 'function') {
        gtag('event', 'sign_up', { method: 'email' });
      }
      // Registration successful — switch to login tab and prefill email
      showAlert('register-alert', 'Account created! Please log in below.', 'success');
      setTimeout(() => {
        switchAuthTab('login');
        document.getElementById('login-email').value = data.email || '';
        showAlert('login-alert', 'Account created! Sign in with Google or use your email/password.', 'success');
      }, 1000);
    } else {
      showAlert('register-alert', data.error || 'Registration failed', 'error');
    }
  } catch (err) {
    console.error('Registration error:', err);
    showAlert('register-alert', 'Network error. Please try again.', 'error');
  }
}

// Resend verification email
async function resendVerification(email) {
  try {
    const r = await api('api.php?action=resend-verification', {
      method: 'POST',
      body: JSON.stringify({ email })
    });
    const data = await r.json();
    
    if (data.success) {
      alert('Verification email sent! Please check your inbox.');
    } else {
      alert(data.error || 'Failed to send email');
    }
  } catch (err) {
    console.error('Resend error:', err);
    alert('Network error. Please try again.');
  }
}

// Request password reset
async function requestPasswordReset() {
  const email = prompt('Enter your email address:');
  if (!email) return;
  
  try {
    const r = await api('api.php?action=request-password-reset', {
      method: 'POST',
      body: JSON.stringify({ email })
    });
    const data = await r.json();
    
    alert(data.message);
  } catch (err) {
    console.error('Password reset error:', err);
    alert('Network error. Please try again.');
  }
}

async function logout() {
  await api('api.php?action=logout');
  currentUser = null;
  location.reload();
}



// ── Simulations ────────────────────────────────────────────────────────────────
function loadSimGrid(containerId, limit = null) {
  const container = document.getElementById(containerId);
  if (!container) {
    // Element not in DOM (e.g. home-sim-grid removed) — skip silently
    return;
  }
  
  if (typeof products === 'undefined' || !Array.isArray(products) || products.length === 0) {
    container.innerHTML = '<div class="alert alert-info">Loading simulations...</div>';
    console.warn('Products not loaded yet for container:', containerId);
    return;
  }
  
  const displayProducts = limit ? products.slice(0, limit) : products;
  
  container.innerHTML = displayProducts.map(p => {
    const icon = (typeof SIM_ICONS !== 'undefined' ? SIM_ICONS[p.id] || SIM_ICONS.default : '🔬');
    const tags = (p.tags || []).map(tag => `<span class="tag">${tag}</span>`).join('');
    const badgeLabel = { trial_only: 'FREE TRIAL', trial_upgrade: 'FREE TRIAL', paid_only: 'PAID' };
    const badgeClass = { trial_only: 'badge-trial-only', trial_upgrade: 'badge-trial-upgrade', paid_only: 'badge-paid-only' };
    const accessBadge = p.access_badge || 'trial_only';
    const starsHtml = p._avg_rating > 0
      ? `<span class="s">${'★'.repeat(Math.round(p._avg_rating))}${'☆'.repeat(5-Math.round(p._avg_rating))}</span> ${p._avg_rating} (${p._rating_count})`
      : '<span style="color:var(--muted)">No ratings yet</span>';
    const usageHtml = p._unique_users > 0
      ? `<span class="sim-usage-badge">👥 ${p._unique_users} user${p._unique_users!==1?'s':''}</span>` : '';
    return `
    <div class="sim-card" onclick="launchSimulation('${p.id}')">
      <div class="sim-card-img">
        ${icon}
        ${accessBadge !== 'paid_only' || p.purchased ? `<span class="trial-badge ${badgeClass[accessBadge]}">${badgeLabel[accessBadge]}</span>` : '<span class="trial-badge badge-paid-only">PAID</span>'}
      </div>
      <div class="sim-card-body">
        <div class="sim-card-stars">${starsHtml} ${usageHtml}</div>
        <h3>${p.title}</h3>
        <p>${p.description}</p>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:.75rem">
          <div class="tags">${tags}</div>
          <button class="btn btn-gold btn-sm" onclick="event.stopPropagation();launchSimulation('${p.id}')">▶ Launch</button>
        </div>
      </div>
    </div>
  `}).join('');
}

async function viewProduct(productId) {
  const r = await api(`api.php?action=product&id=${productId}`);
  const product = await r.json();
  if (!r.ok) { alert('Product not found'); return; }

  const icon = SIM_ICONS[product.id] || SIM_ICONS.default;
  const tags = (product.tags || []).map(tag => `<span class="tag">${tag}</span>`).join('');
  const accessBadge = product.access_badge || 'trial_only';
  const badgeLabel = { trial_only: 'FREE TRIAL', trial_upgrade: 'FREE TRIAL', paid_only: 'PAID' };
  const badgeClass = { trial_only: 'badge-trial-only', trial_upgrade: 'badge-trial-upgrade', paid_only: 'badge-paid-only' };
  const isTrial = accessBadge !== 'paid_only';

  let content = `
    <div style="text-align:center;font-size:3rem;margin-bottom:1rem">${icon}</div>
    <h2 style="margin-bottom:.5rem">${product.title}</h2>
    <p style="margin-bottom:1rem">${product.description}</p>
    <div class="tags" style="margin-bottom:1.25rem">${tags}</div>
  `;

  if (product.purchased || isTrial) {
    // Trial/purchased: show the sim in an iframe + launch button
    content += `
      <div style="margin-bottom:.75rem">
        <span class="trial-badge ${badgeClass[accessBadge]}" style="position:static;display:inline-block">${badgeLabel[accessBadge]}</span>
      </div>
      <div class="iframe-container" style="position:relative;margin-bottom:1rem">
        <iframe src="view-simulation.php?id=${product.id}" style="width:100%;height:420px;border:none;border-radius:8px"></iframe>
      </div>
      <button class="btn btn-gold" style="width:100%;margin-bottom:.75rem"
        onclick="window.open('view-simulation.php?id=${product.id}','_blank')">
        ▶ Open Full Screen
      </button>

      <!-- Coming Soon: Buy Full Version -->
      <div style="position:relative;margin-top:1.5rem;border-radius:12px;overflow:hidden">
        <!-- Blurred footer teaser -->
        <div style="filter:blur(4px);pointer-events:none;user-select:none;padding:1.5rem;background:var(--bg-tertiary);border:1px solid var(--border-color);border-radius:12px">
          <div style="font-size:.75rem;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem">FULL VERSION INCLUDES</div>
          <div style="display:flex;flex-direction:column;gap:.4rem">
            <div style="display:flex;align-items:center;gap:.5rem;color:var(--text-primary)">✓ Unlimited parameter control</div>
            <div style="display:flex;align-items:center;gap:.5rem;color:var(--text-primary)">✓ Data export &amp; analysis tools</div>
            <div style="display:flex;align-items:center;gap:.5rem;color:var(--text-primary)">✓ High-resolution recording</div>
            <div style="display:flex;align-items:center;gap:.5rem;color:var(--text-primary)">✓ No session limits</div>
          </div>
        </div>
        <!-- Overlay button -->
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(13,17,23,.6);border-radius:12px;backdrop-filter:blur(1px)">
          <div style="font-size:1.3rem;font-weight:700;color:#fff;margin-bottom:.5rem">🚀 Full Version</div>
          <div style="font-size:.85rem;color:rgba(255,255,255,.7);margin-bottom:1rem">Unlock all features</div>
          <button class="btn btn-gold" style="padding:.75rem 2rem;font-size:1rem;cursor:default;opacity:.9" onclick="alert('Paid version coming soon! All simulations are free to access right now.')">
            Buy Full Version — Coming Soon
          </button>
        </div>
      </div>
    `;
  } else {
    // Not logged in or no access yet — grant trial
    content += `
      <div style="text-align:center;padding:2rem;background:var(--bg-tertiary);border:1px solid var(--border-color);border-radius:var(--radius)">
        <div style="font-size:2.5rem;margin-bottom:.75rem">🔬</div>
        <h3 style="margin-bottom:.5rem">Free Trial Available</h3>
        <p style="color:var(--muted);margin-bottom:1.5rem">Access this simulation for free. Paid features coming soon.</p>
        <button class="btn btn-gold" onclick="closeModal('product-modal');launchSimulation('${product.id}')">
          ▶ Launch Free Trial
        </button>
      </div>
    `;
  }

  document.getElementById('product-detail').innerHTML = content;
  openModal('product-modal');
}


// Direct launch — open trial immediately for all users (no login required)
// Logged-in users also get trial recorded in DB via grant-trial API
async function launchSimulation(productId) {
  try {
    if (currentUser) {
      // Logged-in: check for existing access, grant trial DB record if needed
      const r = await fetch('api.php?action=check-access&product_id=' + productId);
      const data = await r.json();
      if (!data.has_access) {
        await fetch('api.php?action=grant-trial', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
          },
          body: JSON.stringify({product_id: productId})
        }).catch(() => {});
      }
    }
    // GA4 conversion event — simulation launched
    if (typeof gtag === 'function') {
      gtag('event', 'simulation_launch', { simulation_id: productId });
    }
    // Guests and logged-in users both go straight to the sim — no auth wall
    window.open('view-simulation.php?id=' + productId, '_blank');
  } catch(e) {
    console.error('launchSimulation error:', e);
    window.open('view-simulation.php?id=' + productId, '_blank');
  }
}

function accessSimulation(productId) {
  if (!window.currentUser) {
    openModal('auth-modal');
    return;
  }
  
  // Check if already has access
  fetch(`api.php?action=check-access&product_id=${productId}`)
    .then(r => r.json())
    .then(data => {
      if (data.has_access) {
        window.location.href = `view-simulation.php?id=${productId}`;
      } else {
        // Grant trial access
        fetch('api.php?action=grant-trial', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({product_id: productId})
        })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            window.location.href = `view-simulation.php?id=${productId}`;
          } else {
            alert(data.error || 'Failed to access simulation');
          }
        });
      }
    });
}

async function demoPurchase(productId) {
  if (!currentUser) {
    closeModal('product-modal');
    openModal('auth-modal');
    return;
  }
  
  const r = await api('api.php?action=demo-purchase', {
    method: 'POST',
    body: JSON.stringify({ productId })
  });
  const data = await r.json();
  
  if (data.success) {
    alert(data.message);
    viewProduct(productId);
  } else {
    alert(data.error || 'Purchase failed');
  }
}

// library feature removed

// ── Navigation ─────────────────────────────────────────────────────────────────
function showPage(name) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + name)?.classList.add('active');
  ['home','simulations','about','admin','profile','blog'].forEach(p => {
    const el = document.getElementById('nav-' + p);
    if (el) el.classList.toggle('active', p === name);
  });
  if (name === 'profile') loadProfile();
  if (name === 'blog') { _blogListScrollY = 0; showBlogList(); loadBlogPosts(); }
  if (name === 'simulations') {
    // Always re-render when switching to simulations tab
    if (products && products.length > 0) {
      loadSimGrid('sim-grid-full');
    } else {
      loadProducts().then(() => loadSimGrid('sim-grid-full'));
    }
  }
  window.scrollTo(0, 0);
}

// ── Modals ─────────────────────────────────────────────────────────────────────
function openModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; // Prevent background scroll
  }
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.remove('active');
    document.body.style.overflow = ''; // Restore scroll
  }
  
  // Clear iframe when closing product modal
  if (id === 'product-modal') {
    const iframe = document.getElementById('product-iframe');
    if (iframe) iframe.src = 'about:blank';
  }
}

function closeBgClick(e, id) {
  if (e.target.classList.contains('modal-bg')) {
    closeModal(id);
  }
}

function showAlert(containerId, message, type = 'info') {
  const container = document.getElementById(containerId);
  container.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
  setTimeout(() => container.innerHTML = '', 5000);
}

// ── NEW: Donation Functions ───────────────────────────────────────────────────
function openDonationModal() {
  // Pre-fill email if user is logged in
  if (currentUser) {
    document.getElementById('donor-email').value = currentUser.email;
  }
  openModal('donation-modal');
}

async function createDonation(e) {
  e.preventDefault();
  const name = document.getElementById('donor-name').value.trim();
  const email = document.getElementById('donor-email').value.trim();
  const amount = parseFloat(document.getElementById('donor-amount').value);
  const message = document.getElementById('donor-message').value.trim();
  const isAnonymous = document.getElementById('donor-anonymous').checked;
  const showInDonors = document.getElementById('donor-show').checked;

  // Validate inputs
  if (!name || !email || !amount || amount <= 0) {
    alert('Please fill in all required fields (Name, Email, and Amount)');
    return;
  }

  if (!email.includes('@')) {
    alert('Please enter a valid email address');
    return;
  }
  
  // Store donor info in localStorage (optional - for your records)
  const donorInfo = {
    name,
    email,
    amount,
    message,
    isAnonymous,
    showInDonors,
    timestamp: Date.now()
  };
  localStorage.setItem('pending_donation', JSON.stringify(donorInfo));
  
  // Close the modal
  closeModal('donation-modal');
  
  // Open Razorpay donation page in new tab
  window.open(DONATE_LINK, '_blank');
  
  // Show confirmation message
  setTimeout(() => {
    alert('Opening Razorpay donation page. Thank you for your support!');
  }, 300);
}


// Product Purchase Functions
// Lazy Razorpay loader — injects checkout.js only when a purchase is initiated.
// Safe to call multiple times; subsequent calls skip the script injection.
function loadRazorpay(callback) {
    if (window.Razorpay) { callback(); return; }
    var s = document.createElement('script');
    s.src = 'https://checkout.razorpay.com/v1/checkout.js';
    s.onload = callback;
    s.onerror = function() { alert('Payment service failed to load. Please refresh and try again.'); };
    document.body.appendChild(s);
}

async function purchaseProduct(productId) {
    if (!currentUser) {
        closeModal('product-modal');
        openModal('auth-modal');
        return;
    }

    // Show a small loading indicator (optional)
    const modalContent = document.getElementById('product-detail');
    const originalContent = modalContent.innerHTML;
    modalContent.innerHTML = '<div style="text-align:center; padding:2rem;">Creating order...</div>';

    // Ensure Razorpay SDK is present before opening checkout
    loadRazorpay(async function() {
    try {
        // 1. Create order
        const r = await api('api.php?action=create-purchase-order', {
            method: 'POST',
            body: JSON.stringify({ productId })
        });
        const data = await r.json();

        if (!r.ok) {
            modalContent.innerHTML = originalContent;
            alert(data.error || 'Failed to initiate purchase');
            return;
        }

        // 2. Configure Razorpay checkout
        const options = {
            key: data.order.key,
            amount: data.order.amount,
            currency: data.order.currency,
            name: 'Akilesh Bhandary Research',
            description: `Purchase: ${data.product.title}`,
            order_id: data.order.order_id,
            handler: function(response) {
                verifyPurchase(response);
            },
            prefill: {
                name: currentUser ? currentUser.email.split('@')[0] : '',
                email: currentUser ? currentUser.email : ''
            },
            theme: {
                color: '#f0c040'
            },
            modal: {
                ondismiss: function() {
                    // Restore content if user cancels
                    modalContent.innerHTML = originalContent;
                }
            }
        };

        const razorpay = new Razorpay(options);
        razorpay.open();

    } catch (err) {
        console.error('Purchase error:', err);
        modalContent.innerHTML = originalContent;
        alert('Network error. Please try again.');
    }
    }); // end loadRazorpay callback
}


window.purchaseProduct = purchaseProduct;

async function verifyPurchase(paymentResponse, productId) {
    // Show processing state
    const modalContent = document.getElementById('product-modal')?.querySelector('.modal-content');
    const originalContent = modalContent?.innerHTML;
    
    if (modalContent) {
        modalContent.innerHTML = `
            <div style="text-align:center; padding:3rem;">
                <div class="spinner"></div>
                <p>Verifying your payment...</p>
                <p style="font-size:0.875rem; color:var(--text-muted); margin-top:1rem;">
                    This may take a few seconds
                </p>
            </div>
        `;
    }
    
    try {
        const r = await api('api.php?action=verify-purchase', {
            method: 'POST',
            body: JSON.stringify(paymentResponse)
        });
        
        // ✅ FIX 1: Check if response is JSON or HTML error
        const contentType = r.headers.get('content-type') || '';
        let data;
        
        if (contentType.includes('application/json')) {
            data = await r.json();
        } else {
            // Got HTML instead of JSON - likely a PHP error/warning
            const text = await r.text();
            console.error('❌ Non-JSON response from verification:', text);
            
            // Try to extract error message from HTML if possible
            const errorMatch = text.match(/<b>(.+?)<\/b>/);
            const errorMsg = errorMatch ? errorMatch[1] : 'Server error';
            
            // Restore modal content
            if (modalContent && originalContent) {
                modalContent.innerHTML = originalContent;
            }
            
            alert(`Payment verification error: ${errorMsg}\n\nYour payment was received. Please check your library or contact support.`);
            closeModal('product-modal');
            
            
            return;
        }
 
        // ✅ FIX 2: Handle success response
        if (r.ok && data.success) {
            // Payment verified successfully
            alert(data.message);
            closeModal('product-modal');
            
            
            
            // Optionally: Open the newly purchased simulation
            // viewProduct(data.product_id);
            
        } else {
            // ✅ FIX 3: Handle API error response
            console.error('Payment verification failed:', data);
            
            // Restore modal content
            if (modalContent && originalContent) {
                modalContent.innerHTML = originalContent;
            }
            
            const errorMessage = data.error || data.message || 'Payment verification failed';
            alert(errorMessage + '\n\nIf payment was deducted, please contact support.');
            
            // Don't close modal on error - let user try again or close manually
        }
        
    } catch (err) {
        // ✅ FIX 4: Handle network/fetch errors
        console.error('❌ Payment verification network error:', err);
        
        // Restore modal content
        if (modalContent && originalContent) {
            modalContent.innerHTML = originalContent;
        }
        
        alert('Network error during payment verification.\n\nYour payment may have been processed. Please:\n1. Check your library\n2. Refresh the page\n3. Contact support if issue persists');
        
        closeModal('product-modal');
        
        
    }
}


async function verifyDonation(paymentResponse) {
  const r = await api('api-donation.php?action=verify-donation', {
    method: 'POST',
    body: JSON.stringify(paymentResponse)
  });
  const data = await r.json();

  if (data.success) {
    closeModal('donation-modal');
    alert('Thank you for your generous support!');
    loadRecentDonors(); // refresh donor list
  } else {
    showAlert('donation-alert', data.error || 'Payment verification failed', 'error');
  }
}

async function loadRecentDonors() {
  const donorsDiv = document.getElementById('recent-donors');
  if (!donorsDiv) return; // element removed from DOM — skip silently
  try {
    const r = await fetch('api-donation.php?action=recent-donors&limit=5');
    const data = await r.json();
    if (!r.ok || !data.success) throw new Error(data.error || 'Failed to load donors');

    if (data.donors.length === 0) {
      donorsDiv.innerHTML = '<div style="color: var(--muted);">No donations yet. Be the first to support!</div>';
      return;
    }

    donorsDiv.innerHTML = `
      <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid var(--border-color); text-align:center;">
        <strong style="font-size: 1.2rem; color: var(--color-accent);">Total Raised: ₹${data.total_donated.toLocaleString()}</strong>
      </div>
      ${data.donors.map(d => `
        <div class="donor-item">
          <div class="donor-info">
            <div class="donor-name">${d.name}</div>
            ${d.message ? `<div class="donor-message">"${escapeHtml(d.message)}"</div>` : ''}
          </div>
          <div class="donor-meta">
            <div class="donor-amount">₹${d.amount.toLocaleString()}</div>
            <div class="donor-date">${new Date(d.created_at).toLocaleDateString()}</div>
          </div>
        </div>
      `).join('')}
    `;
  } catch (err) {
    console.error('Error loading donors:', err);
    donorsDiv.innerHTML = '<div style="color: var(--muted);">Could not load donors.</div>';
  }
}


// ── Admin Functions ─────────────────────────────────────────────
<?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
function showAdminTab(tabId) {
  document.querySelectorAll('.admin-tab-content').forEach(el => el.classList.remove('active'));
  const tabEl = document.getElementById('admin-' + tabId);
  if (tabEl) tabEl.classList.add('active');
  // Highlight sidebar button
  document.querySelectorAll('.admin-nav-btn').forEach(btn => btn.classList.remove('active'));
  const activeBtn = document.querySelector(`.admin-nav-btn[data-tab="${tabId}"]`);
  if (activeBtn) activeBtn.classList.add('active');
  
  // Load data based on tab
  if (tabId === 'dashboard') loadAdminDashboard();
  else if (tabId === 'products') loadAdminProducts();
  else if (tabId === 'users') loadAdminUsers();
  else if (tabId === 'purchases') loadAdminPurchases();
  else if (tabId === 'logs') loadAdminErrorLogs();
  else if (tabId === 'blog-comments') loadAdminBlogComments(0);
}

// Dashboard
async function loadAdminDashboard() {
  const r = await api('api.php?action=admin-dashboard');
  const data = await r.json();
  if (!r.ok) return showAlert('admin-dashboard', data.error, 'error');
  
  const metrics = data.metrics;
  document.getElementById('admin-metrics').innerHTML = `
    <div class="service-card"><h3>👥 Users</h3><p style="font-size:2rem">${metrics.totalUsers}</p></div>
    <div class="service-card"><h3>📦 Products</h3><p style="font-size:2rem">${metrics.totalProducts}</p></div>
    <div class="service-card"><h3>💰 Purchases</h3><p style="font-size:2rem">${metrics.totalPurchases}</p></div>
    <div class="service-card"><h3>💵 Revenue</h3><p style="font-size:2rem">₹${metrics.totalRevenue}</p></div>
  `;
  
  const recent = data.recentPurchases;
  document.getElementById('admin-recent-purchases').innerHTML = recent.map(p => `
    <div class="library-card" style="margin-bottom:0.5rem">
      <div><strong>${p.title}</strong> by ${p.email}</div>
      <div>₹${p.amount} (${p.status})</div>
      <div>${new Date(p.created_at).toLocaleString()}</div>
    </div>
  `).join('');
}

// Products
async function loadAdminProducts() {
  const r = await api('api.php?action=admin-products');
  const products = await r.json();
  if (!Array.isArray(products)) return;
  
  document.getElementById('admin-products-list').innerHTML = products.map(p => {
    const icon = (typeof SIM_ICONS !== 'undefined' ? SIM_ICONS[p.id] || SIM_ICONS.default : '🔬');
    const paidBadge = p.paid_file ? `<span style="color:#3fb950;font-size:.72rem">✓ paid/${p.paid_file}</span>` : `<span style="color:var(--muted);font-size:.72rem">No paid file</span>`;
    const pObj = JSON.stringify(p).replace(/`/g,'\\`');
    return `<div class="library-card" style="flex-wrap:wrap;gap:.75rem;align-items:center">
      <div style="font-size:1.8rem;flex-shrink:0">${icon}</div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700">${p.title}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:2px">₹${p.price} · ${p.is_active ? '✅ Active' : '⛔ Hidden'} · ${{ trial_only: '🆓 Trial only', trial_upgrade: '🆓 Trial + Upgrade', paid_only: '💳 Paid only' }[p.access_badge] || '🆓 Trial'}</div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:2px">🎓 trial/<code>${p.sim_file}</code> &nbsp; ${paidBadge}</div>
      </div>
      <button class="btn btn-sm btn-outline" onclick="openProductModal(JSON.parse(atob('${btoa(unescape(encodeURIComponent(pObj)))}')))" style="flex-shrink:0">Edit</button>
    </div>`;
  }).join('');
}

function openProductModal(product = null) {
  document.getElementById('product-modal-title').innerText = product ? 'Edit Simulation' : 'Add Simulation';
  document.getElementById('product-id').value = product ? product.id : '';
  document.getElementById('product-title').value = product ? product.title : '';
  document.getElementById('product-description').value = product ? product.description : '';
  document.getElementById('product-price').value = product ? product.price : '';
  // Store file names on form dataset — inputs for these were removed since
  // filenames are managed on the server, not through this UI form.
  const productForm = document.getElementById('product-id').closest('form');
  productForm.dataset.simFile  = product ? (product.sim_file  || '') : '';
  productForm.dataset.paidFile = product ? (product.paid_file || '') : '';
  document.getElementById('product-preview-image').value = product ? (product.preview_image || '') : '';
  document.getElementById('product-tags').value = product ? (product.tags || []).join(', ') : '';
  document.getElementById('product-active').checked = product ? product.is_active : true;
  const ab = document.getElementById('product-access-badge');
  if (ab) ab.value = product ? (product.access_badge || 'trial_only') : 'trial_only';
  document.getElementById('product-save-message').style.display = 'none';
  openModal('product-edit-modal');
}

async function editProduct(productId) {
  const r = await api(`api.php?action=product&id=${productId}`);
  const product = await r.json();
  if (r.ok) openProductModal(product);
}

async function saveProduct(e) {
  e.preventDefault();
  const id = document.getElementById('product-id').value;
  const title = document.getElementById('product-title').value;
  const description = document.getElementById('product-description').value;
  const price = parseFloat(document.getElementById('product-price').value);
  // sim_file and paid_file are not editable via this form (filename management
  // is done directly on the server). We preserve the current values from the
  // hidden data store set when openProductModal() loaded the product.
  const form = document.getElementById('product-id').closest('form');
  const simFile  = form.dataset.simFile  || '';
  const paidFile = form.dataset.paidFile || '';
  const previewImage = document.getElementById('product-preview-image').value;
  const tags = document.getElementById('product-tags').value.split(',').map(s => s.trim()).filter(s => s);
  const isActive   = document.getElementById('product-active').checked;
  const accessBadgeSel = document.getElementById('product-access-badge');
  const accessBadge = accessBadgeSel ? accessBadgeSel.value : 'trial_only';

  const payload = { id, title, description, price, currency: 'INR', sim_file: simFile, paid_file: paidFile, preview_image: previewImage, tags, is_active: isActive, access_badge: accessBadge };
  const r = await api('api.php?action=admin-products', {
    method: 'POST',
    body: JSON.stringify(payload)
  });
  const data = await r.json();
  const msgDiv = document.getElementById('product-save-message');
  msgDiv.style.display = 'block';
  if (r.ok) {
    msgDiv.className = 'alert alert-success';
    msgDiv.innerText = data.message || 'Saved successfully';
    setTimeout(() => { closeModal('product-edit-modal'); loadAdminProducts(); }, 1500);
  } else {
    msgDiv.className = 'alert alert-error';
    msgDiv.innerText = data.error || 'Save failed';
  }
}

// Users
let allAdminUsers = [];
async function loadAdminUsers() {
  const r = await api('api.php?action=admin-users');
  allAdminUsers = await r.json();
  renderUserList(allAdminUsers);
}
function filterUsers() {
  const q = (document.getElementById('user-search')?.value || '').toLowerCase();
  renderUserList(q ? allAdminUsers.filter(u => (u.email||'').toLowerCase().includes(q)) : allAdminUsers);
}
function renderUserList(users) {
  const el = document.getElementById('admin-users-list');
  if (!el) return;
  el.innerHTML = users.map(u => `
    <div class="library-card" style="flex-wrap:wrap">
      <div style="flex:2"><strong>${u.email}</strong></div>
      <div>Role: ${u.role}</div>
      <div>Joined: ${new Date(u.created_at).toLocaleDateString()}</div>
      <div>Last login: ${u.last_login ? new Date(u.last_login).toLocaleString() : 'never'}</div>
      <div>
        <button 
          class="btn btn-sm btn-outline view-purchases-btn" 
          data-id="${u.id}" 
          data-email="${u.email}">
          View Purchases
        </button>
      </div>
    </div>
`).join('') || '<p style="color:var(--muted);font-size:.88rem">No users found.</p>';

// Add a single event listener (Delegation)
el.addEventListener('click', (e) => {
    if (e.target.classList.contains('view-purchases-btn')) {
        const { id, email } = e.target.dataset;
        viewUserPurchases(id, email);
    }
})};

async function setUserRole(userId, newRole) {
  if (!confirm(`Change this user's role to ${newRole}?`)) return;
  const r = await api('api.php?action=admin-set-role', {
    method: 'POST', body: JSON.stringify({ user_id: userId, role: newRole })
  });
  const d = await r.json();
  if (d.success) loadAdminUsers();
  else alert(d.error || 'Failed to update role');
}

// For user-specific purchase view (we can reuse admin-purchases with filter)
async function viewUserPurchases(userId, email) {
  showAdminTab('purchases');
  document.getElementById('purchase-filter-user').value = userId;
  loadAdminPurchases();
}

// Purchases with filters
let currentPurchasePage = 1;
async function loadAdminPurchases(page = 1) {
  currentPurchasePage = page;
  const userId = document.getElementById('purchase-filter-user').value;
  const productId = document.getElementById('purchase-filter-product').value;
  const status = document.getElementById('purchase-filter-status').value;
  
  let url = `api.php?action=admin-purchases&page=${page}&limit=20`;
  if (userId) url += `&user_id=${userId}`;
  if (productId) url += `&product_id=${productId}`;
  if (status) url += `&status=${status}`;
  
  const r = await api(url);
  const data = await r.json();
  if (!r.ok) return;
  
  document.getElementById('admin-purchases-list').innerHTML = data.purchases.map(p => `
    <div class="library-card" style="flex-wrap:wrap">
      <div style="flex:2"><strong>${p.product_title}</strong> by ${p.user_email}</div>
      <div>₹${p.amount} (${p.status})</div>
      <div>${new Date(p.created_at).toLocaleString()}</div>
      <div>Payment ID: ${p.payment_id || '—'}</div>
    </div>
  `).join('');
  
  // Pagination
  const pagination = data.pagination;
  let pagesHtml = '';
  for (let i = 1; i <= pagination.pages; i++) {
    pagesHtml += `<button class="btn btn-sm ${i === page ? 'btn-gold' : 'btn-outline'}" onclick="loadAdminPurchases(${i})">${i}</button> `;
  }
  document.getElementById('admin-purchases-pagination').innerHTML = pagesHtml;
  
  // Populate filter dropdowns (only once)
  if (page === 1 && !document.getElementById('purchase-filter-user').options.length) {
    // Fetch users and products for filters
    const usersRes = await api('api.php?action=admin-users');
    const users = await usersRes.json();
    let userOpts = '<option value="">All Users</option>';
    users.forEach(u => userOpts += `<option value="${u.id}">${u.email}</option>`);
    document.getElementById('purchase-filter-user').innerHTML = userOpts;
    
    const prodRes = await api('api.php?action=admin-products');
    const prods = await prodRes.json();
    let prodOpts = '<option value="">All Products</option>';
    prods.forEach(p => prodOpts += `<option value="${p.id}">${p.title}</option>`);
    document.getElementById('purchase-filter-product').innerHTML = prodOpts;
  }
}

// Error Logs
async function loadAdminErrorLogs() {
  const r = await api('api.php?action=admin-error-logs&lines=100');
  const data = await r.json();
  if (r.ok) {
    document.getElementById('admin-error-logs').innerText = data.logs.join('');
  } else {
    document.getElementById('admin-error-logs').innerText = 'Error loading logs: ' + data.error;
  }
}

// Change Password
async function changeAdminPassword(e) {
  e.preventDefault();
  const oldPw = document.getElementById('admin-old-pw').value;
  const newPw = document.getElementById('admin-new-pw').value;
  const confirmPw = document.getElementById('admin-confirm-pw').value;
  const msgDiv = document.getElementById('admin-password-message');
  
  if (newPw !== confirmPw) {
    msgDiv.className = 'alert alert-error';
    msgDiv.innerText = 'New passwords do not match';
    msgDiv.style.display = 'block';
    return;
  }
  
  const r = await api('api.php?action=change-password', {
    method: 'POST',
    body: JSON.stringify({ old_password: oldPw, new_password: newPw })
  });
  const data = await r.json();
  msgDiv.style.display = 'block';
  if (r.ok) {
    msgDiv.className = 'alert alert-success';
    msgDiv.innerText = 'Password updated successfully';
    document.getElementById('admin-old-pw').value = '';
    document.getElementById('admin-new-pw').value = '';
    document.getElementById('admin-confirm-pw').value = '';
  } else {
    msgDiv.className = 'alert alert-error';
    msgDiv.innerText = data.error || 'Update failed';
  }
}
<?php endif; // end admin-only JS block 1 ?>

// Initialize admin tabs event listeners
// ── Initialize ─────────────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', init);


// ── Home page question form ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var hForm = document.getElementById('home-question-form');
  if (hForm) {
    hForm.addEventListener('submit', function(e) { e.preventDefault(); submitHomeQuestion(false); });
  }
  var hDonate = document.getElementById('hf-donate-submit');
  if (hDonate) hDonate.addEventListener('click', function() { submitHomeQuestion(true); });
  var hDirectDonate = document.getElementById('home-donate-btn');
  if (hDirectDonate) hDirectDonate.addEventListener('click', function() {
    window.open(DONATE_LINK,'_blank');
  });
});

async function submitHomeQuestion(withDonation) {
  var email = (document.getElementById('hf-email')||{}).value;
  var question = (document.getElementById('hf-question')||{}).value;
  if (email) email = email.trim();
  if (question) question = question.trim();
  var submitBtn = document.getElementById('hf-submit');
  var donateBtn = document.getElementById('hf-donate-submit');
  if (!email || !question) { showHomeMsg('Please fill in all required fields','error'); return; }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showHomeMsg('Please enter a valid email address','error'); return; }
  if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting…'; }
  if (donateBtn) donateBtn.disabled = true;
  try {
    var r = await fetch('api-question.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({email:email, question:question, with_donation:withDonation})
    });
    var d = await r.json();
    if (r.ok && d.success) {
      showHomeMsg(d.message || 'Your question has been submitted!','success');
      document.getElementById('home-question-form').reset();
      if (withDonation) setTimeout(function(){ window.open(DONATE_LINK,'_blank'); }, 1000);
    } else showHomeMsg(d.error || 'Failed to submit. Please try again.','error');
  } catch(err) { showHomeMsg('Network error. Please try again.','error'); }
  finally {
    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit'; }
    if (donateBtn) donateBtn.disabled = false;
  }
}

function showHomeMsg(msg, type) {
  var d = document.getElementById('home-form-msg');
  if (!d) return;
  d.className = 'alert alert-' + (type==='success' ? 'success' : 'error');
  d.textContent = msg; d.style.display = 'block';
  setTimeout(function(){ d.style.display='none'; }, 5000);
}


// ── Sidebar drawer ────────────────────────────────────────────────────────────
function openDrawer() {
  var overlay = document.getElementById('nav-overlay');
  var drawer  = document.getElementById('nav-drawer');
  if (!overlay || !drawer) return;
  overlay.style.display = 'block';
  document.body.style.overflow = 'hidden';
  // Force reflow then animate
  requestAnimationFrame(function() {
    overlay.classList.add('open');
    drawer.classList.add('open');
  });
  syncDrawerAuth();
}

function closeDrawer() {
  var overlay = document.getElementById('nav-overlay');
  var drawer  = document.getElementById('nav-drawer');
  if (!overlay || !drawer) return;
  overlay.classList.remove('open');
  drawer.classList.remove('open');
  document.body.style.overflow = '';
  setTimeout(function() { overlay.style.display = 'none'; }, 310);
}

function syncDrawerAuth() {
  var dg = document.getElementById('drawer-guest');
  var du = document.getElementById('drawer-user');
  var dp = document.getElementById('drawer-profile-link');
  var da = document.getElementById('drawer-admin-link');
  var de = document.getElementById('drawer-email');
  var av = document.getElementById('drawer-avatar');
  if (!dg) return;
  if (currentUser) {
    dg.style.display = 'none';
    du.style.display = 'block';
    if (de) de.textContent = currentUser.email;
    if (av) av.textContent = currentUser.email.charAt(0).toUpperCase();
    if (dp) dp.style.display = '';
    if (da) da.style.display = ((currentUser.is_admin || currentUser.role === 'admin') ? '' : 'none');
  } else {
    dg.style.display = 'block';
    du.style.display = 'none';
    if (dp) dp.style.display = 'none';
    if (da) da.style.display = 'none';
  }
}

// Keyboard: close drawer on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeDrawer();
});

// Keep legacy stubs so nothing breaks
function closeMobileNav() { closeDrawer(); }
function toggleMobileNav() {
  var d = document.getElementById('nav-drawer');
  if (d && d.classList.contains('open')) closeDrawer(); else openDrawer();
}

// ── Show footer block on every page ───────────────────────────────────────────
function showFooterBlock() {
  var fb = document.getElementById('get-in-touch-block');
  if (!fb) return;
  fb.style.display = 'block';
  // Move it to end of current active page
  var activePage = document.querySelector('.page.active');
  if (activePage) activePage.appendChild(fb);
}

// Patch showPage to also show footer
var _origShowPage = showPage;
showPage = function(name) {
  _origShowPage(name);
  showFooterBlock();
};

// ── Get in Touch form ────────────────────────────────────────────────────────
async function submitGitForm(e) {
  e.preventDefault();
  var name    = (document.getElementById('git-name')?.value || '').trim();
  var email   = (document.getElementById('git-email')?.value || '').trim();
  var message = (document.getElementById('git-message')?.value || '').trim();
  var msgDiv  = document.getElementById('git-msg');
  var btn     = document.getElementById('git-submit');

  if (!email || !message) { showGitMsg('Please fill required fields.', false); return; }

  btn.disabled = true; btn.textContent = 'Sending…';
  try {
    var r = await fetch('api-question.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({email: email, question: (name ? name+': ' : '') + message, with_donation: false})
    });
    var d = await r.json();
    if (r.ok && d.success) {
      showGitMsg(d.message || 'Message sent!', true);
      document.getElementById('git-form').reset();
    } else showGitMsg(d.error || 'Failed to send. Please try again.', false);
  } catch(err) { showGitMsg('Network error. Please try again.', false); }
  finally { btn.disabled = false; btn.textContent = 'Send Message'; }
}
function showGitMsg(msg, ok) {
  var d = document.getElementById('git-msg');
  if (!d) return;
  d.className = ok ? 'ok' : 'err';
  d.textContent = msg; d.style.display = 'block';
  setTimeout(function(){ d.style.display = 'none'; }, 5000);
}

// ── Cookie consent ────────────────────────────────────────────────────────────
// cookieChoice kept as no-op stub (Osano handles consent now)
function cookieChoice(accepted) {}



// ── Blog (public) ─────────────────────────────────────────────────────────────
let blogPage = 1; let blogTotal = 0;

async function loadBlogPosts(more = false) {
  if (!more) { blogPage = 1; document.getElementById('blog-grid').innerHTML = '<div style="color:var(--muted)">Loading…</div>'; }
  let data = { posts: [], total: 0 };
  try {
    const r = await fetch('api.php?action=blog-posts&page=' + blogPage, { cache: 'no-store' });
    if (r.ok) data = await r.json();
  } catch(e) { /* blog_posts table not yet created */ }
  blogTotal  = data.total || 0;
  const grid = document.getElementById('blog-grid');
  if (!more) grid.innerHTML = '';
  if (!data.posts || data.posts.length === 0) {
    if (!more) grid.innerHTML = '<p style="color:var(--muted)">No posts yet — check back soon!</p>';
    return;
  }
  data.posts.forEach(post => {
    const div   = document.createElement('div');
    div.className = 'blog-card';
    const date  = new Date(post.created_at).toLocaleDateString(undefined,{year:'numeric',month:'long',day:'numeric'});
    const cover = post.cover_image ? `<div class="blog-card-cover"><img src="${post.cover_image}" alt="" loading="lazy"></div>` : '';
    div.innerHTML = `${cover}<div class="blog-card-body"><div class="blog-card-date">${date}</div><div class="blog-card-title">${post.title}</div><div class="blog-card-summary">${post.summary||''}</div><div class="blog-card-read">Read more -></div></div>`;
    div.onclick = () => openBlogPost(post.slug);
    grid.appendChild(div);
  });
  blogPage++;
  const btn = document.getElementById('blog-load-more');
  if (btn) btn.style.display = data.page < data.pages ? 'inline-block' : 'none';
}

// ── Blog state ────────────────────────────────────────────────────────────────
let _blogCurrentSlug        = '';
let _blogCommentsOpen       = false;
let _blogCommentsOffset     = 0;
const BLOG_COMMENTS_PER_PAGE = 10;
const BLOG_REPLIES_PER_BATCH  = 7;
// Per-comment reply state: commentId -> { offset, total }
const _blogReplyState = {};
// Thread view state
let _blogThreadParentId   = null;
let _blogThreadOffset     = 0;

async function openBlogPost(slug) {
  const r    = await fetch('api.php?action=blog-post&slug=' + encodeURIComponent(slug));
  const post = await r.json();
  if (!post.title) return;
  _blogCurrentSlug    = slug;
  _blogCommentsOpen   = false;
  _blogCommentsOffset = 0;
  Object.keys(_blogReplyState).forEach(k => delete _blogReplyState[k]);

  // Save list scroll position so Back button can restore it
  _blogListScrollY = window.scrollY;

  document.getElementById('blog-list-view').style.display = 'none';
  document.getElementById('blog-thread-view').style.display = 'none';
  const pv = document.getElementById('blog-post-view');
  pv.style.display = 'block';

  document.getElementById('post-view-title').textContent = post.title;
  const date   = new Date(post.created_at).toLocaleDateString(undefined,{year:'numeric',month:'long',day:'numeric'});
  const author = post.display_name || (post.author_email ? post.author_email.split('@')[0] : 'Akilesh Bhandary');
  document.getElementById('post-view-meta').textContent = `${date} · ${author}`;
  document.getElementById('post-view-body').innerHTML = post.content;

  // Reset comment section
  document.getElementById('post-view-comments').classList.remove('open');
  document.getElementById('blog-comment-list').innerHTML   = '';
  document.getElementById('blog-comments-loadmore').innerHTML = '';
  document.getElementById('blog-new-comment-area').innerHTML  = '';
  document.getElementById('blog-comment-toggle-btn').classList.remove('active');

  // Show interactions bar and load counts
  document.getElementById('post-view-interactions').style.display = 'flex';
  loadBlogLikeCount(slug);

  // Update open-in-own-page button href
  const openBtn = document.getElementById('blog-open-btn');
  if (openBtn) openBtn.href = '<?= rtrim(SITE_URL, '/') ?>/blog/' + encodeURIComponent(slug);

  window.scrollTo(0,0);
}

function copyBlogLink() {
  const slug  = _blogCurrentSlug;
  if (!slug) return;
  const url   = <?= json_encode(rtrim(SITE_URL, '/')) ?> + '/blog/' + slug;
  const label = document.getElementById('blog-share-label');
  navigator.clipboard.writeText(url).then(() => {
    if (label) { label.textContent = 'Copied!'; setTimeout(() => { label.textContent = 'Share'; }, 2000); }
  }).catch(() => { prompt('Copy this link:', url); });
}

let _blogListScrollY = 0;

function showBlogList() {
  document.getElementById('blog-list-view').style.display = '';
  document.getElementById('blog-post-view').style.display = 'none';
  document.getElementById('blog-thread-view').style.display = 'none';
  _blogCurrentSlug = '';
  // Restore scroll position to where the user was in the list
  requestAnimationFrame(() => window.scrollTo(0, _blogListScrollY));
}

// ── Like ──────────────────────────────────────────────────────────────────────

async function loadBlogLikeCount(slug) {
  try {
    const r = await fetch('api.php?action=blog-like-count&slug=' + encodeURIComponent(slug));
    const d = await r.json();
    _applyLikeState(d.liked, d.count);
  } catch(e) {}
}

function _applyLikeState(liked, count) {
  const btn     = document.getElementById('blog-like-btn');
  const label   = document.getElementById('blog-like-label');
  const tooltip = document.getElementById('blog-like-tooltip');
  if (!btn) return;
  btn.classList.toggle('liked', liked);
  label.textContent   = liked ? 'Liked' : 'Like';
  tooltip.textContent = count === 1 ? '1 like' : `${count} likes`;
}

async function toggleBlogLike() {
  if (!currentUser) {
    openModal('auth-modal');
    return;
  }
  try {
    const r = await apiFetch('api.php?action=blog-like', {
      method: 'POST',
      body: JSON.stringify({ slug: _blogCurrentSlug })
    });
    const d = await r.json();
    _applyLikeState(d.liked, d.count);
  } catch(e) {}
}

// ── Comments toggle ───────────────────────────────────────────────────────────

async function toggleBlogComments() {
  const section = document.getElementById('post-view-comments');
  const btn     = document.getElementById('blog-comment-toggle-btn');
  if (_blogCommentsOpen) {
    section.classList.remove('open');
    btn.classList.remove('active');
    _blogCommentsOpen = false;
    return;
  }
  section.classList.add('open');
  btn.classList.add('active');
  _blogCommentsOpen   = true;
  _blogCommentsOffset = 0;
  document.getElementById('blog-comment-list').innerHTML = '';
  _renderCommentForm();
  await _loadTopLevelComments(false);
}

function _renderCommentForm() {
  const area = document.getElementById('blog-new-comment-area');
  if (currentUser) {
    area.innerHTML = `
      <div class="blog-comment-form">
        <textarea id="blog-new-comment-text" placeholder="Write a comment..."></textarea>
        <div class="blog-comment-form-actions">
          <button class="btn btn-gold" style="font-size:.85rem;padding:.4rem 1rem" onclick="postBlogComment(null)">Post</button>
        </div>
      </div>`;
  } else {
    area.innerHTML = `
      <p class="blog-cta-login" style="margin-bottom:1.25rem">
        <button onclick="openModal('auth-modal')">Login</button> to join the conversation.
      </p>`;
  }
}

async function _loadTopLevelComments(append) {
  const r = await fetch(
    `api.php?action=blog-comments&slug=${encodeURIComponent(_blogCurrentSlug)}&offset=${_blogCommentsOffset}&limit=${BLOG_COMMENTS_PER_PAGE}`
  );
  const d = await r.json();

  const list = document.getElementById('blog-comment-list');
  if (!append) list.innerHTML = '';

  d.comments.forEach(c => list.appendChild(_buildCommentEl(c, false)));
  _blogCommentsOffset += d.comments.length;

  // Update count label
  document.getElementById('blog-comment-count-label').textContent =
    d.total === 1 ? '1 Comment' : `${d.total} Comments`;

  // Load-more button
  const lm = document.getElementById('blog-comments-loadmore');
  if (d.has_more) {
    lm.innerHTML = `<button class="blog-load-more-btn" onclick="_loadTopLevelComments(true)">Load more comments</button>`;
  } else {
    lm.innerHTML = '';
  }
}

// ── Comment builder ───────────────────────────────────────────────────────────

function _makeSVGBtn(cls, onclickStr, paths) {
  const NS  = 'http://www.w3.org/2000/svg';
  const btn = document.createElement('button');
  btn.className = cls;
  btn.setAttribute('onclick', onclickStr);
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('xmlns', NS);
  svg.style.cssText = 'width:18px;height:18px;pointer-events:none;display:block';
  paths.forEach(function(p) {
    const el = document.createElementNS(NS, 'path');
    el.setAttribute('d', p.d);
    el.setAttribute('stroke', 'currentColor');
    el.setAttribute('stroke-width', '2');
    el.setAttribute('stroke-linecap', 'round');
    el.setAttribute('stroke-linejoin', 'round');
    if (p.fill) el.setAttribute('fill', 'currentColor');
    svg.appendChild(el);
  });
  btn.appendChild(svg);
  return btn;
}

const _LIKE_PATHS = [
  { d: 'M7 10C7 10 7 4 11 2C11 2 12 2 12 4C12 6 11 9 11 9H18C19.1 9 20 9.9 20 11V13C20 13.4 19.9 13.7 19.7 14L18.1 19.2C17.8 20.3 16.8 21 15.7 21H7' },
  { d: 'M7 21H3C2.4 21 2 20.6 2 20V11C2 10.4 2.4 10 3 10H7V21Z', fill: true }
];
const _DISLIKE_PATHS = [
  { d: 'M7 14C7 14 7 20 11 22C11 22 12 22 12 20C12 18 11 15 11 15H18C19.1 15 20 14.1 20 13V11C20 10.6 19.9 10.3 19.7 10L18.1 4.8C17.8 3.7 16.8 3 15.7 3H7' },
  { d: 'M7 3H3C2.4 3 2 3.4 2 4V13C2 13.6 2.4 14 3 14H7V3Z', fill: true }
];

function _buildCommentEl(c, depth) {
  depth = depth || 1;
  const wrap = document.createElement('div');
  wrap.className = 'bc-wrap';
  wrap.dataset.commentId = c.id;

  const dateStr    = new Date(c.created_at).toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});
  const score      = c.vote_score || 0;
  const userVote   = c.user_vote  || 0;
  const scoreClass = score > 0 ? 'pos' : score < 0 ? 'neg' : '';
  const isAdmin    = currentUser && (currentUser.is_admin || currentUser.role === 'admin');

  const avatarInner = c.avatar_url
    ? `<img src="${_esc(c.avatar_url)}" alt="">`
    : _esc((c.display_name || 'A').charAt(0).toUpperCase());

  const collapseBtn = c.reply_count > 0
    ? `<button class="bc-collapse-btn" onclick="_toggleCollapseChildren(${c.id}, this)">[ - ] Hide replies</button>`
    : '';

  const showRepliesBtn = c.reply_count > 0
    ? `<button class="blog-load-more-btn" style="margin-top:.5rem" onclick="_handleShowReplies(${c.id}, this, ${c.reply_count}, 0)">Show ${Math.min(c.reply_count, BLOG_REPLIES_PER_BATCH)} repl${c.reply_count === 1 ? 'y' : 'ies'} (${c.reply_count} total)</button>`
    : '';

  const adminBtn = isAdmin ? `<button onclick="_adminDeleteComment(${c.id}, this)">Delete</button>` : '';

  // Build static HTML structure first — vote row gets real SVG nodes appended after
  wrap.innerHTML = `
    <div class="bc-row">
      <div class="bc-main">
        <div class="bc-meta">
          <span class="bc-avatar">${avatarInner}</span>
          <span class="bc-author">${_esc(c.display_name || 'Anonymous')}</span>
          <span class="bc-date">${dateStr}</span>
        </div>
        <div class="bc-text">${_esc(c.body)}</div>
        <div class="bc-vote-row" id="bc-voterow-${c.id}">
          <span class="bc-score ${scoreClass}" id="bc-score-${c.id}">${score}</span>
        </div>
        <div class="bc-actions">
          <button onclick="_toggleReplyForm(${c.id}, this)">Reply</button>
          ${collapseBtn}
          ${adminBtn}
        </div>
        <div class="bc-reply-host"></div>
        ${showRepliesBtn}
        <div class="bc-children" id="bc-children-${c.id}" data-depth="${depth}"></div>
      </div>
    </div>
    <div class="bc-collapsed-pill" id="bc-pill-${c.id}"
         onclick="_reexpandChildren(${c.id})"></div>`;

  // Attach SVG buttons into vote row via createElementNS (innerHTML loses SVG namespace)
  const voteRow  = wrap.querySelector('#bc-voterow-' + c.id);
  const scoreEl  = wrap.querySelector('#bc-score-'   + c.id);
  const upBtn    = _makeSVGBtn(
    'bc-vote-up' + (userVote === 1 ? ' active' : ''),
    'toggleCommentVote(' + c.id + ', 1, this)',
    _LIKE_PATHS
  );
  const dnBtn    = _makeSVGBtn(
    'bc-vote-dn' + (userVote === -1 ? ' active' : ''),
    'toggleCommentVote(' + c.id + ', -1, this)',
    _DISLIKE_PATHS
  );
  voteRow.insertBefore(upBtn, scoreEl);
  voteRow.appendChild(dnBtn);

  return wrap;
}

function _esc(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Vote ──────────────────────────────────────────────────────────────────────

async function toggleCommentVote(commentId, direction, btn) {
  if (!currentUser) { openModal('auth-modal'); return; }
  const scoreEl  = document.getElementById('bc-score-' + commentId);
  const voteRow  = btn.closest('.bc-vote-row');
  const upBtn    = voteRow.querySelector('.bc-vote-up');
  const dnBtn    = voteRow.querySelector('.bc-vote-dn');
  const current  = upBtn.classList.contains('active') ? 1 : dnBtn.classList.contains('active') ? -1 : 0;
  const newVote  = (current === direction) ? 0 : direction;
  try {
    const r = await apiFetch('api.php?action=blog-comment-vote', {
      method: 'POST',
      body: JSON.stringify({ comment_id: commentId, vote: newVote })
    });
    const d = await r.json();
    if (!d.success) return;
    upBtn.classList.toggle('active', d.user_vote === 1);
    dnBtn.classList.toggle('active', d.user_vote === -1);
    scoreEl.textContent = d.vote_score;
    scoreEl.className   = 'bc-score' + (d.vote_score > 0 ? ' pos' : d.vote_score < 0 ? ' neg' : '');
  } catch(e) {}
}

// ── Collapse / expand ─────────────────────────────────────────────────────────

function _toggleCollapseChildren(commentId, btn) {
  const children = document.getElementById('bc-children-' + commentId);
  const pill     = document.getElementById('bc-pill-'     + commentId);
  if (!children) return;
  const collapsing = !children.classList.contains('collapsed');
  if (collapsing) {
    children.classList.add('collapsed');
    const count = children.querySelectorAll(':scope > .bc-wrap').length;
    pill.textContent = '[ + ] ' + count + ' repl' + (count === 1 ? 'y' : 'ies') + ' collapsed';
    pill.classList.add('visible');
    btn.textContent = '[ + ] Show replies';
    btn.classList.add('collapsed');
  } else {
    _reexpandChildren(commentId);
  }
}

function _reexpandChildren(commentId) {
  const children = document.getElementById('bc-children-' + commentId);
  const pill     = document.getElementById('bc-pill-'     + commentId);
  const btn      = document.querySelector('[onclick*="_toggleCollapseChildren(' + commentId + ',"]');
  if (children) children.classList.remove('collapsed');
  if (pill)     { pill.classList.remove('visible'); }
  if (btn)      { btn.textContent = '[ - ] Hide replies'; btn.classList.remove('collapsed'); }
}

// ── Reply form ────────────────────────────────────────────────────────────────

function _toggleReplyForm(commentId, btn) {
  const main = btn.closest('.bc-main');
  const host = main.querySelector('.bc-reply-host');
  if (host.querySelector('textarea')) { host.innerHTML = ''; return; }
  if (!currentUser) { openModal('auth-modal'); return; }
  host.innerHTML = `
    <div class="blog-reply-form">
      <textarea placeholder="Write a reply..." id="reply-text-${commentId}"></textarea>
      <div class="blog-reply-form-actions">
        <button class="btn btn-gold" style="font-size:.82rem;padding:.35rem .85rem"
                onclick="postBlogComment(${commentId})">Reply</button>
        <button class="btn btn-outline" style="font-size:.82rem;padding:.35rem .75rem"
                onclick="this.closest('.blog-reply-form').parentElement.innerHTML=''">Cancel</button>
      </div>
    </div>`;
  document.getElementById('reply-text-' + commentId).focus();
}

// ── Show replies ──────────────────────────────────────────────────────────────

async function _handleShowReplies(parentId, btn, totalReplies, currentOffset) {
  const batchNum = Math.floor(currentOffset / BLOG_REPLIES_PER_BATCH) + 1;
  if (batchNum >= 8) { openBlogThread(parentId); return; }

  const r = await fetch(
    'api.php?action=blog-comments&slug=' + encodeURIComponent(_blogCurrentSlug) +
    '&parent_id=' + parentId + '&offset=' + currentOffset + '&limit=' + BLOG_REPLIES_PER_BATCH
  );
  const d = await r.json();

  const childrenEl = document.getElementById('bc-children-' + parentId);
  if (!childrenEl) return;
  const parentDepth = parseInt(childrenEl.dataset.depth) || 1;
  d.comments.forEach(c => childrenEl.appendChild(_buildCommentEl(c, parentDepth + 1)));

  const loaded = currentOffset + d.comments.length;
  if (d.has_more) {
    const nextBatch = batchNum + 1;
    const remaining = d.total - loaded;
    const nextLoad  = Math.min(remaining, BLOG_REPLIES_PER_BATCH);
    if (nextBatch >= 8) {
      btn.textContent = 'Open full thread (' + remaining + ' more)';
      btn.setAttribute('onclick', 'openBlogThread(' + parentId + ')');
    } else {
      btn.textContent = 'Show ' + nextLoad + ' more repl' + (nextLoad === 1 ? 'y' : 'ies') + ' (' + remaining + ' remaining)';
      btn.setAttribute('onclick', '_handleShowReplies(' + parentId + ', this, ' + d.total + ', ' + loaded + ')');
    }
  } else {
    btn.remove();
  }
}

// ── Post comment / reply ──────────────────────────────────────────────────────

async function postBlogComment(parentId) {
  if (!currentUser) { openModal('auth-modal'); return; }
  const textareaId = parentId ? 'reply-text-' + parentId : 'blog-new-comment-text';
  const ta = document.getElementById(textareaId);
  if (!ta) return;
  const body = ta.value.trim();
  if (!body) { ta.focus(); return; }

  try {
    const r = await apiFetch('api.php?action=blog-comment-post', {
      method: 'POST',
      body: JSON.stringify({ slug: _blogCurrentSlug, body, parent_id: parentId })
    });
    const d = await r.json();
    if (!d.success) { alert(d.error || 'Failed to post comment'); return; }

    if (parentId === null) {
      const list = document.getElementById('blog-comment-list');
      list.insertBefore(_buildCommentEl(d.comment, 1), list.firstChild);
      ta.value = '';
      const lbl = document.getElementById('blog-comment-count-label');
      const cur = parseInt(lbl.textContent) || 0;
      lbl.textContent = (cur + 1) + ' Comments';
    } else {
      const childrenEl = document.getElementById('bc-children-' + parentId);
      if (childrenEl) {
        const parentDepth = parseInt(childrenEl.dataset.depth) || 1;
        childrenEl.appendChild(_buildCommentEl(d.comment, parentDepth + 1));
        // Ensure children block is visible
        childrenEl.classList.remove('collapsed');
        document.getElementById('bc-pill-' + parentId)?.classList.remove('visible');
      }
      // Clear reply form
      const host = document.getElementById('reply-text-' + parentId)?.closest('.bc-reply-host');
      if (host) host.innerHTML = '';
    }
  } catch(e) { alert('Network error, please try again'); }
}

// ── Thread view ───────────────────────────────────────────────────────────────

async function openBlogThread(parentId) {
  _blogThreadParentId = parentId;
  _blogThreadOffset   = 0;

  document.getElementById('blog-post-view').style.display   = 'none';
  document.getElementById('blog-thread-view').style.display = 'block';
  document.getElementById('blog-thread-reply-list').innerHTML = '';
  document.getElementById('blog-thread-loadmore').innerHTML   = '';

  // Reconstruct root comment from existing DOM
  const existingEl = document.querySelector('.bc-wrap[data-comment-id="' + parentId + '"]');
  const rootWrap   = document.getElementById('blog-thread-root-comment');
  rootWrap.innerHTML = '';
  if (existingEl) {
    const clone = existingEl.cloneNode(true);
    // Remove children block from clone — thread view loads them separately
    const kids = clone.querySelector('#bc-children-' + parentId);
    if (kids) kids.innerHTML = '';
    const pill = clone.querySelector('#bc-pill-' + parentId);
    if (pill) pill.remove();
    rootWrap.appendChild(clone);
  }

  document.getElementById('blog-thread-heading').textContent = 'Full thread';
  document.getElementById('blog-thread-sub').textContent     = 'All replies — unlimited depth';

  await _loadThreadReplies(false);
  window.scrollTo(0, 0);
}

async function _loadThreadReplies(append) {
  const r = await fetch(
    'api.php?action=blog-comments&slug=' + encodeURIComponent(_blogCurrentSlug) +
    '&parent_id=' + _blogThreadParentId + '&offset=' + _blogThreadOffset + '&limit=' + BLOG_COMMENTS_PER_PAGE
  );
  const d = await r.json();

  const list = document.getElementById('blog-thread-reply-list');
  if (!append) list.innerHTML = '';

  d.comments.forEach(c => list.appendChild(_buildCommentEl(c, 1)));
  _blogThreadOffset += d.comments.length;

  const lm = document.getElementById('blog-thread-loadmore');
  lm.innerHTML = d.has_more
    ? '<button class="blog-load-more-btn" onclick="_loadThreadReplies(true)">Load more replies</button>'
    : '';
}

function closeBlogThread() {
  document.getElementById('blog-thread-view').style.display = 'none';
  document.getElementById('blog-post-view').style.display   = 'block';
  window.scrollTo(0, 0);
}

// ── Admin: delete comment ─────────────────────────────────────────────────────

async function _adminDeleteComment(id, btn) {
  if (!confirm('Hide this comment?')) return;
  try {
    const r = await apiFetch('api.php?action=admin-blog-delete-comment', {
      method: 'POST',
      body: JSON.stringify({ id })
    });
    const d = await r.json();
    if (d.success) {
      const el = btn.closest('.bc-wrap');
      if (el) el.remove();
    }
  } catch(e) { alert('Failed to delete comment'); }
}

// ── Admin: blog comments panel ────────────────────────────────────────────────
<?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
let _adminCommentsOffset = 0;
const ADMIN_COMMENTS_PER_PAGE = 25;

async function loadAdminBlogComments(offset) {
  _adminCommentsOffset = offset;
  const r = await api(`api.php?action=admin-blog-comments&offset=${offset}&limit=${ADMIN_COMMENTS_PER_PAGE}`);
  const d = await r.json();

  const list = document.getElementById('admin-blog-comments-list');
  if (offset === 0) list.innerHTML = '';

  if (!d.comments || d.comments.length === 0) {
    if (offset === 0) list.innerHTML = '<p style="color:var(--muted)">No comments yet.</p>';
    document.getElementById('admin-blog-comments-loadmore').innerHTML = '';
    return;
  }

  d.comments.forEach(c => {
    const dateStr = new Date(c.created_at).toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});
    const item = document.createElement('div');
    item.style.cssText = 'background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius);padding:1rem;';
    item.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem">
        <div style="flex:1;min-width:0">
          <div style="font-size:.78rem;color:var(--muted);margin-bottom:.35rem">
            <strong>${_esc(c.display_name || c.email)}</strong> on
            <em>${_esc(c.post_slug)}</em> &middot; ${dateStr}
            ${c.parent_id ? ' &middot; reply' : ''}
          </div>
          <div style="font-size:.88rem;line-height:1.5;white-space:pre-wrap;word-break:break-word">${_esc(c.body)}</div>
        </div>
        <button class="btn btn-outline" style="font-size:.78rem;padding:.3rem .65rem;flex-shrink:0;color:var(--color-danger);border-color:var(--color-danger)"
                onclick="_adminDeleteCommentFromPanel(${c.id}, this)">Delete</button>
      </div>`;
    list.appendChild(item);
  });

  const lm = document.getElementById('admin-blog-comments-loadmore');
  const loaded = offset + d.comments.length;
  if (loaded < d.total) {
    lm.innerHTML = `<button class="btn btn-outline" onclick="loadAdminBlogComments(${loaded})">Load more</button>`;
  } else {
    lm.innerHTML = '';
  }
}

async function _adminDeleteCommentFromPanel(id, btn) {
  if (!confirm('Hide this comment?')) return;
  try {
    const r = await apiFetch('api.php?action=admin-blog-delete-comment', {
      method: 'POST',
      body: JSON.stringify({ id })
    });
    const d = await r.json();
    if (d.success) btn.closest('div[style]').remove();
  } catch(e) { alert('Failed to delete comment'); }
}
<?php endif; ?>

// ── Admin Blog ─────────────────────────────────────────────────────────────────
<?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
// Admin nav buttons now have onclick directly; this is a safety fallback
document.querySelectorAll('.admin-nav-btn').forEach(btn => {
  if (!btn.getAttribute('onclick')) {
    btn.addEventListener('click', () => showAdminTab(btn.dataset.tab));
  }
});

// Override showPage to also load admin content when page-admin is shown
const originalShowPage = showPage;
showPage = function(name) {
  originalShowPage(name);
  if (name === 'admin') {
    showAdminTab('dashboard');
  }
};

async function loadAdminBlog() {
  const r    = await api('api.php?action=admin-blog-list');
  const data = await r.json();
  if (!data.success) return;
  const list = document.getElementById('admin-blog-list');
  if (!list) return;
  list.innerHTML = data.posts.length === 0
    ? '<p style="color:var(--muted);font-size:.85rem">No posts yet.</p>'
    : data.posts.map(p => `
        <div class="blog-post-list-item" onclick="editBlogPost(${p.id},'${p.slug}')">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">${p.title}</span>
          <span class="blog-post-status status-${p.status}">${p.status}</span>
        </div>`).join('');
}

function newBlogPost() {
  document.getElementById('blog-edit-id').value    = '';
  document.getElementById('blog-edit-title').value = '';
  document.getElementById('blog-edit-summary').value= '';
  document.getElementById('blog-edit-cover').value = '';
  document.getElementById('blog-content-editor').value = '';
  document.getElementById('blog-edit-status').value= 'draft';
  document.getElementById('admin-blog-editor').style.display     = '';
  document.getElementById('admin-blog-placeholder').style.display= 'none';
  document.getElementById('blog-save-msg').textContent = '';
}

async function editBlogPost(id, slug) {
  const r    = await fetch('api.php?action=blog-post&slug=' + encodeURIComponent(slug) + '&preview=1');
  // Fetch from admin list instead
  const allR = await api('api.php?action=admin-blog-list');
  const all  = await allR.json();
  const post = all.posts?.find(p => p.id === id);
  if (!post) return;
  // Fetch full content
  const full = await api('api.php?action=admin-blog-get&id=' + id);
  // Use admin-saved-reports style — fetch full post separately
  const fr = await fetch('api.php?action=admin-blog-full&id=' + id);
  const fp = fr.ok ? await fr.json() : {};
  document.getElementById('blog-edit-id').value     = id;
  document.getElementById('blog-edit-title').value  = post.title || fp.title || '';
  document.getElementById('blog-edit-summary').value= fp.summary || '';
  document.getElementById('blog-edit-cover').value  = fp.cover_image || '';
  document.getElementById('blog-content-editor').value = fp.content || '';
  document.getElementById('blog-edit-status').value = post.status;
  document.getElementById('admin-blog-editor').style.display     = '';
  document.getElementById('admin-blog-placeholder').style.display= 'none';
  document.getElementById('blog-save-msg').textContent = '';
}

async function saveBlogPost() {
  const id      = document.getElementById('blog-edit-id').value;
  const title   = document.getElementById('blog-edit-title').value.trim();
  const summary = document.getElementById('blog-edit-summary').value.trim();
  const cover   = document.getElementById('blog-edit-cover').value.trim();
  const content = document.getElementById('blog-content-editor').value.trim();
  const status  = document.getElementById('blog-edit-status').value;
  const msg     = document.getElementById('blog-save-msg');
  if (!title || !content) { msg.textContent = 'Title and content are required.'; msg.style.color='var(--red)'; return; }
  const r = await apiFetch('api.php?action=admin-blog-save', {
    method:'POST', body: JSON.stringify({id: id || '', title, summary, cover_image:cover, content, status})
  });
  const d = await r.json();
  if (d.success) {
    msg.textContent = '✓ Saved'; msg.style.color = 'var(--green)';
    document.getElementById('blog-edit-id').value = d.id;
    loadAdminBlog();
  } else {
    msg.textContent = d.error || 'Failed'; msg.style.color = 'var(--red)';
  }
}

async function deleteBlogPost() {
  const id = document.getElementById('blog-edit-id').value;
  if (!id || !confirm('Delete this post permanently?')) return;
  const r = await apiFetch('api.php?action=admin-blog-delete', { method:'POST', body:JSON.stringify({id: id}) });
  const d = await r.json();
  if (d.success) {
    document.getElementById('admin-blog-editor').style.display     = 'none';
    document.getElementById('admin-blog-placeholder').style.display= '';
    loadAdminBlog();
  }
}

// ── SQL Reports ───────────────────────────────────────────────────────────────
let savedReports = [];

async function loadSavedReports() {
  try {
    const r = await api('api.php?action=admin-saved-reports');
    const data = await r.json();
    if (!data.success) return;
    savedReports = data.reports;
    const list = document.getElementById('report-list');
    if (!list) return;
    list.innerHTML = data.reports.map((rp, i) => `
      <div class="report-item" id="rpt-${rp.id}" onclick="selectSavedReport(${i})">
        <span>📊</span> ${rp.label}
      </div>`).join('');
  } catch(e) { console.error('Failed to load reports', e); }
}

function selectSavedReport(idx) {
  const rp = savedReports[idx];
  if (!rp) return;
  document.getElementById('report-title').textContent = rp.label;
  document.getElementById('report-subtitle').textContent = 'Pre-built report — you can edit the query below.';
  document.getElementById('report-query').value = rp.query;
  document.getElementById('report-output').innerHTML = '';
  document.getElementById('report-count').textContent = '';
  document.querySelectorAll('.report-item').forEach(el => el.classList.remove('active'));
  document.getElementById('rpt-' + rp.id)?.classList.add('active');
  document.getElementById('custom-report-btn')?.classList.remove('active');
  runReport();
}

function selectCustomReport() {
  document.getElementById('report-title').textContent = 'Custom SQL Query';
  document.getElementById('report-subtitle').textContent = 'Write any SELECT or SHOW query. UPDATE/DELETE/INSERT are blocked.';
  document.getElementById('report-query').value = '';
  document.getElementById('report-output').innerHTML = '';
  document.getElementById('report-count').textContent = '';
  document.querySelectorAll('.report-item').forEach(el => el.classList.remove('active'));
  document.getElementById('custom-report-btn')?.classList.add('active');
  document.getElementById('report-query').focus();
}

async function runReport() {
  const query = (document.getElementById('report-query')?.value || '').trim();
  if (!query) return;
  const output = document.getElementById('report-output');
  const countEl = document.getElementById('report-count');
  if (!output) return;
  output.innerHTML = '<div style="color:var(--muted);font-size:.85rem">Running…</div>';
  countEl.textContent = '';
  try {
    const r = await api('api.php?action=admin-run-report', {
      method: 'POST', body: JSON.stringify({ query })
    });
    const data = await r.json();
    if (!data.success) {
      output.innerHTML = `<div class="report-error">${data.error}</div>`;
      return;
    }
    countEl.textContent = `${data.count} row${data.count !== 1 ? 's' : ''}`;
    if (data.count === 0) {
      output.innerHTML = '<div style="color:var(--muted);font-size:.85rem;padding:.5rem">No rows returned.</div>';
      return;
    }
    const ths = data.columns.map(c => `<th>${c}</th>`).join('');
    const trs = data.rows.map(row =>
      '<tr>' + data.columns.map(c => `<td title="${String(row[c] ?? '').replace(/"/g,'&quot;')}">${row[c] ?? '<em style="color:var(--muted)">null</em>'}</td>`).join('') + '</tr>'
    ).join('');
    output.innerHTML = `<div class="report-table-wrap"><table class="report-table"><thead><tr>${ths}</tr></thead><tbody>${trs}</tbody></table></div>`;
  } catch(e) {
    output.innerHTML = `<div class="report-error">Network error: ${e.message}</div>`;
  }
}
<?php endif; // end admin-only JS ?>

// ── Profile ───────────────────────────────────────────────────────────────────
async function loadProfile() {
  try {
    const r = await api('api.php?action=get-profile');
    const p = await r.json();
    if (!r.ok) return;

    document.getElementById('profile-email').textContent   = p.email || '—';
    document.getElementById('profile-provider').textContent = p.auth_provider === 'google' ? '🔵 Google Sign-In' : '✉️ Email / Password';
    document.getElementById('profile-joined').textContent   = p.created_at ? new Date(p.created_at).toLocaleDateString() : '—';
    document.getElementById('profile-name').value = p.display_name || '';

    // Avatar display on profile page
    const avatarDiv = document.getElementById('profile-avatar-display');
    if (avatarDiv) {
      if (p.avatar_url) {
        avatarDiv.innerHTML = `<img src="${p.avatar_url}" class="profile-avatar-img" alt="Profile photo">`;
      } else {
        const initials = (p.display_name || p.email || '?').charAt(0).toUpperCase();
        avatarDiv.innerHTML = `<div class="profile-avatar-placeholder">${initials}</div>`;
      }
    }

    // Update nav avatar
    updateNavAvatar(p);
  } catch(e) { console.error('Profile load error', e); }
}

function updateNavAvatar(profile) {
  const navAvatarSpan = document.getElementById('nav-avatar-img');
  const navLink       = document.getElementById('nav-avatar-link');
  if (!navAvatarSpan) return;
  if (profile?.avatar_url) {
    navAvatarSpan.innerHTML = `<img src="${profile.avatar_url}?_t=${Date.now()}" style="width:100%;height:100%;object-fit:cover;border-radius:50%" alt="Avatar">`;
    if (navLink) navLink.style.background = 'transparent';
  } else {
    // No photo — show 👤 icon on accent background
    navAvatarSpan.innerHTML = `<span style="font-size:18px;line-height:1">&#128100;</span>`;
    if (navLink) navLink.style.background = 'var(--color-accent)';
  }
  // Update drawer
  const name = profile?.display_name || (profile?.email ? profile.email.split('@')[0] : '?');
  const de = document.getElementById('drawer-email');
  const av = document.getElementById('drawer-avatar');
  if (de) de.textContent = profile?.email || '';
  if (av) av.textContent = name.charAt(0).toUpperCase();
}

async function saveProfile(e) {
  e.preventDefault();
  const name = document.getElementById('profile-name').value.trim();
  const msg  = document.getElementById('profile-name-msg');
  try {
    const r = await api('api.php?action=update-profile', {
      method: 'POST', body: JSON.stringify({ display_name: name })
    });
    const d = await r.json();
    if (d.success) {
      msg.textContent = '✓ Name saved'; msg.style.color = 'var(--green)';
      // Refresh nav display
      const r2 = await api('api.php?action=get-profile');
      const p  = await r2.json();
      updateNavAvatar(p);
    } else {
      msg.textContent = d.error || 'Failed'; msg.style.color = 'var(--red)';
    }
  } catch(e) { msg.textContent = 'Network error'; msg.style.color = 'var(--red)'; }
  setTimeout(() => { msg.textContent = ''; }, 4000);
}

// Compress and square-crop an image File to 256x256 JPEG before upload.
// Scale so the shorter dimension reaches 256px, then center-crop to 256x256.
function compressAvatar(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      const TARGET = 256;
      // Scale factor: shorter dimension becomes TARGET
      const scale = TARGET / Math.min(img.naturalWidth, img.naturalHeight);
      const sw = Math.round(img.naturalWidth  * scale);
      const sh = Math.round(img.naturalHeight * scale);
      // Center-crop offsets
      const ox = Math.floor((sw - TARGET) / 2);
      const oy = Math.floor((sh - TARGET) / 2);
      const canvas = document.createElement('canvas');
      canvas.width  = TARGET;
      canvas.height = TARGET;
      const ctx = canvas.getContext('2d');
      // Draw scaled then offset so the center 256x256 fills canvas
      ctx.drawImage(img, -ox, -oy, sw, sh);
      canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('Canvas toBlob failed')),
        'image/jpeg', 0.82);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Image load failed')); };
    img.src = url;
  });
}

async function uploadAvatar(input) {
  const msg = document.getElementById('avatar-msg');
  if (!input.files || !input.files[0]) return;
  msg.textContent = 'Compressing…'; msg.style.color = 'var(--muted)';
  let blob;
  try {
    blob = await compressAvatar(input.files[0]);
  } catch(e) {
    msg.textContent = 'Could not process image'; msg.style.color = 'var(--red)';
    input.value = '';
    return;
  }
  const formData = new FormData();
  formData.append('avatar', blob, 'avatar.jpg');
  msg.textContent = 'Uploading…'; msg.style.color = 'var(--muted)';
  try {
    const r = await fetch('api.php?action=upload-avatar', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: formData,
      cache: 'no-store'
    });
    const d = await r.json();
    if (d.success) {
      msg.textContent = '✓ Photo updated'; msg.style.color = 'var(--green)';
      loadProfile(); // refresh avatar display
    } else {
      msg.textContent = d.error || 'Upload failed'; msg.style.color = 'var(--red)';
    }
  } catch(e) { msg.textContent = 'Network error'; msg.style.color = 'var(--red)'; }
  input.value = '';
  setTimeout(() => { msg.textContent = ''; }, 5000);
}

// ── Hash routing: now superseded by History API pushState clean URLs ────────
function checkHash() {
  var hash = window.location.hash.replace('#','');
  var valid = ['home','simulations','about','library','admin','profile','blog'];
  if (!hash) { showPage('simulations'); return; }
  if (valid.indexOf(hash) !== -1) showPage(hash);
}
window.addEventListener('hashchange', checkHash);
window.addEventListener('load', function(){ setTimeout(checkHash, 100); });

</script>


<!-- Osano cookieconsent rendered by JS -->

</body>
</html>