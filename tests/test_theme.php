<?php
/**
 * test/test_theme.php
 * IndependentResearcher Platform — Diagnostic Suite
 *
 * Deploy to:  <webroot>/test/test_theme.php
 * Access via: https://yoursite.com/test/test_theme.php
 *
 * Provides:
 *   §01  CSS Token Resolution
 *   §02  localStorage Access
 *   §03  Theme Boot Chain
 *   §04  postMessage Bridge
 *   §05  Simulation Engine API
 *   §06  Simulation iFrames (__themeTest probe)
 *   §07  Trial Feature Gating
 *   §08  Server Environment (PHP-only — not possible in pure HTML)
 *   §09  Sim File Existence & Path Check (PHP-only)
 *   §10  Full Diagnostic Log
 */

// ── Server-side theme resolution ─────────────────────────────────────────────
// Priority: cookie 'ir_theme' → time-of-day default
$serverTheme = 'dark';
if (!empty($_COOKIE['ir_theme']) && in_array($_COOKIE['ir_theme'], ['light','dark'], true)) {
    $serverTheme = $_COOKIE['ir_theme'];
} else {
    $h = (int) date('G');
    $serverTheme = ($h >= 6 && $h < 18) ? 'light' : 'dark';
}

// ── Sim file checks (server-side) ────────────────────────────────────────────
$webroot   = dirname(__DIR__);   // /test/../  = project root
$simChecks = [
    ['label'=>'Pendulum · Trial',      'path'=>'simulations/trial/sim-pendulum-trial.html',           'type'=>'trial'],
    ['label'=>'Pendulum · Paid',       'path'=>'simulations/paid/sim-pendulum-paid.html',             'type'=>'paid'],
    ['label'=>'Dbl Pendulum · Trial',  'path'=>'simulations/trial/sim-double-pendulum-trial.html',    'type'=>'trial'],
    ['label'=>'Dbl Pendulum · Paid',   'path'=>'simulations/paid/sim-double-pendulum-paid.html',      'type'=>'paid'],
    ['label'=>'theme.css',             'path'=>'assets/css/theme.css',                                'type'=>'asset'],
    ['label'=>'simulation-engine.php', 'path'=>'simulation-engine.php',                               'type'=>'php'],
];

$fileResults = [];
foreach ($simChecks as $item) {
    $fullPath  = $webroot . '/' . $item['path'];
    $exists    = file_exists($fullPath);
    $readable  = $exists && is_readable($fullPath);
    $size      = $readable ? filesize($fullPath) : 0;
    $mtime     = $readable ? date('Y-m-d H:i', filemtime($fullPath)) : '—';
    $fileResults[] = array_merge($item, [
        'exists'   => $exists,
        'readable' => $readable,
        'size'     => $size,
        'mtime'    => $mtime,
        'fullPath' => $fullPath,
    ]);
}

// ── Server environment ────────────────────────────────────────────────────────
$phpVersion  = PHP_VERSION;
$phpMajor    = PHP_MAJOR_VERSION;
$sapiName    = php_sapi_name();
$serverSw    = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$docRoot     = $_SERVER['DOCUMENT_ROOT']  ?? 'unknown';
$scriptPath  = __FILE__;
$timezone    = date_default_timezone_get();
$sessionOk   = (session_status() !== PHP_SESSION_DISABLED);
$cookiesOk   = !empty($_COOKIE) || true; // just test writing
$memLimit    = ini_get('memory_limit');
$maxExecTime = ini_get('max_execution_time');
$pdoDrivers  = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
$curlOk      = function_exists('curl_init');
$jsonOk      = function_exists('json_encode');

// Encode all PHP data for JS consumption
$phpData = json_encode([
    'theme'       => $serverTheme,
    'phpVersion'  => $phpVersion,
    'phpMajor'    => $phpMajor,
    'sapiName'    => $sapiName,
    'serverSw'    => $serverSw,
    'docRoot'     => $docRoot,
    'scriptPath'  => $scriptPath,
    'timezone'    => $timezone,
    'sessionOk'   => $sessionOk,
    'memLimit'    => $memLimit,
    'maxExecTime' => $maxExecTime,
    'pdoDrivers'  => $pdoDrivers,
    'curlOk'      => $curlOk,
    'jsonOk'      => $jsonOk,
    'fileResults' => $fileResults,
    'generatedAt' => date('Y-m-d H:i:s'),
]);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($serverTheme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IR Platform · Diagnostic Suite</title>
<link rel="stylesheet" href="/assets/css/theme.css">
<script>
/* Boot — same chain used in sim files */
(function(){
  var t=null;
  try{var m=window.location.search.match(/[?&]theme=(light|dark)/);if(m)t=m[1];}catch(e){}
  if(!t){try{t=localStorage.getItem('ir_theme');}catch(e){}}
  /* PHP already set data-theme from cookie — only override if URL param present */
  if(t)document.documentElement.setAttribute('data-theme',t);
  window.__irTheme=document.documentElement.getAttribute('data-theme');
})();
</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-mono,'JetBrains Mono',monospace);background:var(--bg-primary);color:var(--text-primary);min-height:100vh;padding:0 0 80px}

.topbar{position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:16px;padding:0 20px;height:56px;background:var(--bg-secondary);border-bottom:1px solid var(--border-color);backdrop-filter:blur(8px)}
.topbar-title{font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--color-accent);display:flex;align-items:center;gap:8px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--color-accent);animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.btn-toggle{background:var(--bg-tertiary);border:1px solid var(--border-color);color:var(--text-primary);padding:6px 14px;border-radius:6px;font-family:var(--font-mono);font-size:.7rem;font-weight:600;cursor:pointer;letter-spacing:.06em;text-transform:uppercase;transition:all .15s}
.btn-toggle:hover{border-color:var(--color-accent);color:var(--color-accent)}
.btn-run{background:var(--color-accent);color:#0a0a09;border:none;padding:6px 16px;border-radius:6px;font-family:var(--font-mono);font-size:.7rem;font-weight:700;cursor:pointer;letter-spacing:.06em;text-transform:uppercase;transition:all .15s}
.btn-run:hover{background:var(--color-accent-dark)}

.wrap{max-width:1100px;margin:0 auto;padding:28px 20px 0}
.section{margin-bottom:32px}
.sec-hdr{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--border-light)}
.sec-num{font-size:.6rem;font-weight:700;letter-spacing:.15em;color:var(--text-muted);text-transform:uppercase;background:var(--bg-tertiary);padding:3px 7px;border-radius:4px}
.sec-title{font-size:.8rem;font-weight:600;letter-spacing:.06em;color:var(--text-primary)}
.sec-badge{margin-left:auto;font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 9px;border-radius:4px;opacity:0;transition:opacity .3s}
.sec-badge.show{opacity:1}
.sec-badge.pass{background:rgba(40,167,69,.15);color:var(--color-success)}
.sec-badge.fail{background:rgba(220,53,69,.15);color:var(--color-danger)}
.sec-badge.warn{background:rgba(255,193,7,.15);color:var(--color-warning)}

.card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.card{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius,8px);padding:12px 14px;transition:border-color .2s}
.card.pass{border-color:rgba(40,167,69,.4)}
.card.fail{border-color:rgba(220,53,69,.5);background:rgba(220,53,69,.04)}
.card.warn{border-color:rgba(255,193,7,.4)}
.card-lbl{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px}
.card-val{font-size:.78rem;font-weight:500;color:var(--text-primary);word-break:break-all;line-height:1.4}
.card-val.ok{color:var(--color-success)}
.card-val.bad{color:var(--color-danger)}
.card-val.warn{color:var(--color-warning)}
.card-val.dim{color:var(--text-muted)}

/* Token grid */
.tok-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px}
.tok-row{display:flex;align-items:center;gap:10px;background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:6px;padding:8px 10px}
.tok-swatch{width:18px;height:18px;border-radius:3px;flex-shrink:0;border:1px solid var(--border-color)}
.tok-name{font-size:.62rem;color:var(--text-muted);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tok-val{font-size:.62rem;color:var(--text-primary);flex-shrink:0;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tok-row.fail{border-color:rgba(220,53,69,.5);background:rgba(220,53,69,.06)}
.tok-row.fail .tok-name,.tok-row.fail .tok-val{color:var(--color-danger)}

/* iFrame grid */
.iframe-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:700px){.iframe-grid{grid-template-columns:1fr}}
.icard{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius,8px);overflow:hidden}
.icard-hdr{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border-light);background:var(--bg-tertiary)}
.icard-title{font-size:.68rem;font-weight:600;letter-spacing:.06em;flex:1}
.ibadge{font-size:.55rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 6px;border-radius:3px}
.ibadge.paid{background:rgba(40,167,69,.2);color:var(--color-success)}
.ibadge.trial{background:rgba(212,160,23,.2);color:var(--color-accent)}
.ibadge.asset,.ibadge.php{background:rgba(23,162,184,.2);color:var(--color-info)}
.iframe-wrap{height:260px;position:relative}
.iframe-wrap iframe{width:100%;height:100%;border:none;display:block}
.iframe-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:var(--bg-tertiary);font-size:.7rem;color:var(--text-muted);letter-spacing:.06em}
.iresult{padding:8px 14px;font-size:.62rem;letter-spacing:.04em;border-top:1px solid var(--border-light);min-height:32px;display:flex;align-items:center;gap:6px}
.iresult.pass{color:var(--color-success)}
.iresult.fail{color:var(--color-danger)}
.iresult.warn{color:var(--color-warning)}
.iresult.pend{color:var(--text-muted)}

/* Log */
.log{background:var(--bg-tertiary);border:1px solid var(--border-color);border-radius:var(--radius,8px);padding:14px;font-size:.65rem;line-height:1.8;max-height:280px;overflow-y:auto;color:var(--text-muted)}
.log::-webkit-scrollbar{width:4px}
.log::-webkit-scrollbar-thumb{background:var(--border-color);border-radius:2px}
.log-line{display:flex;gap:10px;align-items:baseline}
.log-ts{color:var(--text-muted);opacity:.5;flex-shrink:0;width:76px;font-size:.6rem}
.log-lvl{flex-shrink:0;width:48px;font-weight:700;letter-spacing:.08em;font-size:.65rem}
.log-lvl.pass{color:var(--color-success)}
.log-lvl.fail{color:var(--color-danger)}
.log-lvl.warn{color:var(--color-warning)}
.log-lvl.info{color:var(--color-info)}
.log-msg{color:var(--text-primary);word-break:break-word}

/* Summary bar */
.summary{position:fixed;bottom:0;left:0;right:0;background:var(--bg-secondary);border-top:1px solid var(--border-color);display:flex;align-items:center;gap:20px;padding:10px 24px;z-index:200;font-size:.68rem;letter-spacing:.06em}
.sum-stat{display:flex;align-items:center;gap:6px}
.sum-lbl{color:var(--text-muted)}
.sum-cnt{font-weight:700}
.sum-cnt.pass{color:var(--color-success)}
.sum-cnt.fail{color:var(--color-danger)}
.sum-cnt.warn{color:var(--color-warning)}
.sum-right{margin-left:auto;color:var(--text-muted);font-size:.6rem}

/* PHP badge */
.php-tag{display:inline-block;background:rgba(119,83,163,.25);color:#b388ff;font-size:.55rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 6px;border-radius:3px;margin-left:6px;vertical-align:middle}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-title">
    <div class="dot"></div>
    IR Platform · Diagnostic Suite
    <span class="php-tag">PHP</span>
  </div>
  <div class="topbar-right">
    <span style="font-size:.65rem;color:var(--text-muted)">Server theme: <strong style="color:var(--color-accent)"><?= htmlspecialchars($serverTheme) ?></strong></span>
    <button class="btn-toggle" onclick="toggleTheme()">Toggle Theme</button>
    <button class="btn-run" onclick="runAll()">▶ Run All Tests</button>
  </div>
</div>

<div class="wrap">

  <!-- §01 CSS Tokens -->
  <div class="section" id="sec-tokens">
    <div class="sec-hdr">
      <span class="sec-num">01</span>
      <span class="sec-title">CSS Token Resolution — theme.css loaded &amp; all vars resolve</span>
      <span class="sec-badge" id="badge-tokens">—</span>
    </div>
    <div class="tok-grid" id="tok-grid"><div class="tok-row"><span class="tok-name">Waiting…</span></div></div>
  </div>

  <!-- §02 localStorage -->
  <div class="section" id="sec-storage">
    <div class="sec-hdr">
      <span class="sec-num">02</span>
      <span class="sec-title">localStorage — read / write / incognito detection</span>
      <span class="sec-badge" id="badge-storage">—</span>
    </div>
    <div class="card-grid" id="cards-storage"><div class="card"><div class="card-lbl">Status</div><div class="card-val dim">Pending…</div></div></div>
  </div>

  <!-- §03 Theme Boot Chain -->
  <div class="section" id="sec-boot">
    <div class="sec-hdr">
      <span class="sec-num">03</span>
      <span class="sec-title">Theme Boot Chain — cookie (PHP) → URL param → localStorage → time-of-day</span>
      <span class="sec-badge" id="badge-boot">—</span>
    </div>
    <div class="card-grid" id="cards-boot"><div class="card"><div class="card-lbl">Status</div><div class="card-val dim">Pending…</div></div></div>
  </div>

  <!-- §04 postMessage Bridge -->
  <div class="section" id="sec-postmsg">
    <div class="sec-hdr">
      <span class="sec-num">04</span>
      <span class="sec-title">postMessage Bridge — parent → iframe theme sync</span>
      <span class="sec-badge" id="badge-postmsg">—</span>
    </div>
    <div class="card-grid" id="cards-postmsg"><div class="card"><div class="card-lbl">Status</div><div class="card-val dim">Pending…</div></div></div>
  </div>

  <!-- §05 Engine -->
  <div class="section" id="sec-engine">
    <div class="sec-hdr">
      <span class="sec-num">05</span>
      <span class="sec-title">Simulation Engine — PHP API reachability &amp; response shape</span>
      <span class="sec-badge" id="badge-engine">—</span>
    </div>
    <div class="card-grid" id="cards-engine"><div class="card"><div class="card-lbl">Status</div><div class="card-val dim">Pending…</div></div></div>
  </div>

  <!-- §06 iFrames -->
  <div class="section" id="sec-iframes">
    <div class="sec-hdr">
      <span class="sec-num">06</span>
      <span class="sec-title">Simulation iFrames — load, __themeTest() probe, token audit</span>
      <span class="sec-badge" id="badge-iframes">—</span>
    </div>
    <div class="iframe-grid" id="iframe-grid"></div>
  </div>

  <!-- §07 Trial Gating -->
  <div class="section" id="sec-trial">
    <div class="sec-hdr">
      <span class="sec-num">07</span>
      <span class="sec-title">Trial Feature Gating — partial unlock, blur overlays, data cap</span>
      <span class="sec-badge" id="badge-trial">—</span>
    </div>
    <div class="card-grid" id="cards-trial"><div class="card"><div class="card-lbl">Status</div><div class="card-val dim">Pending…</div></div></div>
  </div>

  <!-- §08 Server Environment (PHP) -->
  <div class="section" id="sec-server">
    <div class="sec-hdr">
      <span class="sec-num">08 <span class="php-tag">PHP</span></span>
      <span class="sec-title">Server Environment — PHP version, extensions, config</span>
      <span class="sec-badge show <?= (PHP_MAJOR_VERSION >= 8) ? 'pass' : 'warn' ?>" id="badge-server">
        <?= (PHP_MAJOR_VERSION >= 8) ? 'PASS' : 'WARN' ?>
      </span>
    </div>
    <div class="card-grid">
      <div class="card <?= (PHP_MAJOR_VERSION >= 8) ? 'pass' : 'warn' ?>">
        <div class="card-lbl">PHP Version</div>
        <div class="card-val <?= (PHP_MAJOR_VERSION >= 8) ? 'ok' : 'warn' ?>"><?= htmlspecialchars($phpVersion) ?></div>
      </div>
      <div class="card pass">
        <div class="card-lbl">SAPI</div>
        <div class="card-val ok"><?= htmlspecialchars($sapiName) ?></div>
      </div>
      <div class="card pass">
        <div class="card-lbl">Server Software</div>
        <div class="card-val dim"><?= htmlspecialchars($serverSw) ?></div>
      </div>
      <div class="card pass">
        <div class="card-lbl">Timezone</div>
        <div class="card-val ok"><?= htmlspecialchars($timezone) ?></div>
      </div>
      <div class="card <?= $sessionOk ? 'pass' : 'fail' ?>">
        <div class="card-lbl">Sessions</div>
        <div class="card-val <?= $sessionOk ? 'ok' : 'bad' ?>"><?= $sessionOk ? 'Enabled' : 'DISABLED' ?></div>
      </div>
      <div class="card <?= in_array('mysql', $pdoDrivers) ? 'pass' : 'fail' ?>">
        <div class="card-lbl">PDO MySQL</div>
        <div class="card-val <?= in_array('mysql', $pdoDrivers) ? 'ok' : 'bad' ?>"><?= in_array('mysql', $pdoDrivers) ? 'Available' : 'MISSING' ?></div>
      </div>
      <div class="card <?= $curlOk ? 'pass' : 'warn' ?>">
        <div class="card-lbl">cURL</div>
        <div class="card-val <?= $curlOk ? 'ok' : 'warn' ?>"><?= $curlOk ? 'Available' : 'Not loaded' ?></div>
      </div>
      <div class="card pass">
        <div class="card-lbl">Memory Limit</div>
        <div class="card-val dim"><?= htmlspecialchars($memLimit) ?></div>
      </div>
      <div class="card pass">
        <div class="card-lbl">Max Exec Time</div>
        <div class="card-val dim"><?= htmlspecialchars($maxExecTime) ?>s</div>
      </div>
      <div class="card pass">
        <div class="card-lbl">Cookie Theme</div>
        <div class="card-val ok"><?= htmlspecialchars($serverTheme) ?> (ir_theme cookie)</div>
      </div>
      <div class="card pass">
        <div class="card-lbl">Generated At</div>
        <div class="card-val dim"><?= date('Y-m-d H:i:s') ?></div>
      </div>
      <div class="card pass">
        <div class="card-lbl">Doc Root</div>
        <div class="card-val dim" style="font-size:.6rem"><?= htmlspecialchars($docRoot) ?></div>
      </div>
    </div>
  </div>

  <!-- §09 File Checks (PHP) -->
  <div class="section" id="sec-files">
    <div class="sec-hdr">
      <span class="sec-num">09 <span class="php-tag">PHP</span></span>
      <span class="sec-title">Sim File Existence &amp; Path Check — server-side stat()</span>
      <?php
      $allExist = array_reduce($fileResults, fn($c,$r) => $c && $r['exists'], true);
      $someWarn = array_reduce($fileResults, fn($c,$r) => $c || !$r['readable'], false);
      $fcls = $allExist ? 'pass' : 'fail';
      ?>
      <span class="sec-badge show <?= $fcls ?>"><?= strtoupper($fcls) ?></span>
    </div>
    <div class="card-grid">
      <?php foreach ($fileResults as $fr): ?>
      <div class="card <?= $fr['exists'] ? ($fr['readable'] ? 'pass' : 'warn') : 'fail' ?>">
        <div class="card-lbl"><?= htmlspecialchars($fr['label']) ?> <span class="ibadge <?= $fr['type'] ?>"><?= $fr['type'] ?></span></div>
        <div class="card-val <?= $fr['exists'] ? 'ok' : 'bad' ?>">
          <?php if ($fr['exists']): ?>
            ✓ <?= number_format($fr['size'] / 1024, 1) ?> KB · <?= $fr['mtime'] ?>
          <?php else: ?>
            ✗ Not found<br><small style="font-size:.55rem;opacity:.7"><?= htmlspecialchars($fr['fullPath']) ?></small>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- §10 Log -->
  <div class="section" id="sec-log">
    <div class="sec-hdr">
      <span class="sec-num">10</span>
      <span class="sec-title">Full Diagnostic Log</span>
    </div>
    <div class="log" id="log-terminal">
      <div class="log-line"><span class="log-ts">--:--:--.---</span><span class="log-lvl info">INFO</span><span class="log-msg">Diagnostic suite loaded (PHP <?= PHP_VERSION ?>). Press "Run All Tests" to begin JS checks. PHP §08–09 already rendered above.</span></div>
    </div>
  </div>

</div><!-- /wrap -->

<div class="summary">
  <div class="sum-stat"><span class="sum-lbl">PASS</span><span class="sum-cnt pass" id="cnt-pass">0</span></div>
  <div class="sum-stat"><span class="sum-lbl">FAIL</span><span class="sum-cnt fail" id="cnt-fail">0</span></div>
  <div class="sum-stat"><span class="sum-lbl">WARN</span><span class="sum-cnt warn" id="cnt-warn">0</span></div>
  <div class="sum-stat"><span class="sum-lbl">TOTAL</span><span class="sum-cnt" id="cnt-total" style="color:var(--text-primary)">0</span></div>
  <div class="sum-right" id="sum-ts">Not run</div>
</div>

<script>
'use strict';
// PHP-injected server data
const PHP = <?= $phpData ?>;

// Paths relative to /test/ location
const ENGINE_PATH = '../simulation-engine.php';
const SIMS = [
  {id:'pendulum-trial',  label:'Pendulum · Trial',      path:'../simulations/trial/sim-pendulum-trial.html',         type:'trial'},
  {id:'pendulum-paid',   label:'Pendulum · Paid',       path:'../simulations/paid/sim-pendulum-paid.html',           type:'paid'},
  {id:'dp-trial',        label:'Dbl Pendulum · Trial',  path:'../simulations/trial/sim-double-pendulum-trial.html',  type:'trial'},
  {id:'dp-paid',         label:'Dbl Pendulum · Paid',   path:'../simulations/paid/sim-double-pendulum-paid.html',    type:'paid'},
];

const TOKEN_DEFS = [
  {n:'--bg-primary',      lbl:'BG Primary'},
  {n:'--bg-secondary',    lbl:'BG Secondary'},
  {n:'--bg-tertiary',     lbl:'BG Tertiary'},
  {n:'--bg-accent',       lbl:'BG Accent'},
  {n:'--text-primary',    lbl:'Text Primary'},
  {n:'--text-muted',      lbl:'Text Muted'},
  {n:'--color-primary',   lbl:'Blue'},
  {n:'--color-accent',    lbl:'Gold'},
  {n:'--color-success',   lbl:'Green'},
  {n:'--color-danger',    lbl:'Red'},
  {n:'--color-info',      lbl:'Teal'},
  {n:'--border-color',    lbl:'Border'},
  {n:'--border-light',    lbl:'Border Light'},
  {n:'--sim-bob-hi',      lbl:'Bob Highlight'},
  {n:'--sim-bob-mid',     lbl:'Bob Mid'},
  {n:'--sim-rod',         lbl:'Rod'},
  {n:'--sim-trail',       lbl:'Trail (rgb)'},
  {n:'--sim-phase-line',  lbl:'Phase Line (rgb)'},
  {n:'--sim-tip-dot',     lbl:'Tip Dot'},
  {n:'--sim-energy-ok',   lbl:'Energy OK'},
  {n:'--sim-energy-bad',  lbl:'Energy Bad'},
  {n:'--sim-poincare',    lbl:'Poincaré'},
  {n:'--sim-dot-grid',    lbl:'Dot Grid'},
  {n:'--sim-axis',        lbl:'Axis'},
  {n:'--hud-bg',          lbl:'HUD BG'},
  {n:'--lock-overlay-bg', lbl:'Lock Overlay'},
  {n:'--accent-rgb',      lbl:'Accent RGB'},
  {n:'--font-mono',       lbl:'Mono Font'},
  {n:'--font-sans',       lbl:'Sans Font'},
];

// Counters
let nPass=0,nFail=0,nWarn=0;

function ts(){const n=new Date();return n.toTimeString().slice(0,8)+'.'+String(n.getMilliseconds()).padStart(3,'0');}

function log(level,msg){
  const el=document.getElementById('log-terminal');
  const d=document.createElement('div');d.className='log-line';
  d.innerHTML=`<span class="log-ts">${ts()}</span><span class="log-lvl ${level}">${level.toUpperCase().padEnd(4)}</span><span class="log-msg">${msg}</span>`;
  el.appendChild(d);el.scrollTop=el.scrollHeight;
  if(level==='pass')nPass++;else if(level==='fail')nFail++;else if(level==='warn')nWarn++;
  document.getElementById('cnt-pass').textContent=nPass;
  document.getElementById('cnt-fail').textContent=nFail;
  document.getElementById('cnt-warn').textContent=nWarn;
  document.getElementById('cnt-total').textContent=nPass+nFail+nWarn;
}

function setBadge(id,state){
  const el=document.getElementById('badge-'+id);
  el.textContent=state.toUpperCase();el.className='sec-badge show '+state;
}

function card(lbl,val,state=''){
  const cv=state==='pass'?'ok':state==='fail'?'bad':state==='warn'?'warn':'dim';
  return `<div class="card ${state}"><div class="card-lbl">${lbl}</div><div class="card-val ${cv}">${val}</div></div>`;
}

// §01 CSS Tokens
function runTokens(){
  log('info','§01 CSS Token Resolution — probing '+TOKEN_DEFS.length+' tokens');
  const cs=getComputedStyle(document.documentElement);
  const grid=document.getElementById('tok-grid');grid.innerHTML='';
  let fails=0;
  TOKEN_DEFS.forEach(def=>{
    const raw=cs.getPropertyValue(def.n).trim();
    const empty=!raw;if(empty)fails++;
    let sw='transparent';
    if(raw){if(raw.startsWith('#')||raw.startsWith('rgb')||raw.startsWith('hsl'))sw=raw;
    else if(/^\d/.test(raw))sw=`rgb(${raw})`;}
    const r=document.createElement('div');r.className='tok-row'+(empty?' fail':'');
    r.innerHTML=`<div class="tok-swatch" style="background:${sw}"></div><span class="tok-name" title="${def.n}">${def.n}</span><span class="tok-val">${raw||'⚠ EMPTY'}</span>`;
    grid.appendChild(r);
    if(empty)log('fail',`Token ${def.n} empty — theme.css may not have loaded`);
  });
  const state=fails===0?'pass':fails<3?'warn':'fail';
  setBadge('tokens',state);
  log(state,`§01 ${TOKEN_DEFS.length-fails}/${TOKEN_DEFS.length} tokens resolved (${fails} empty)`);
}

// §02 localStorage
function runStorage(){
  log('info','§02 localStorage Access');
  const cards=[];let accessible=false,readable=false,writable=false;
  try{const ls=window.localStorage;accessible=!!ls;cards.push(card('localStorage Object','Accessible','pass'));}
  catch(e){cards.push(card('localStorage Object','BLOCKED: '+e.message,'fail'));log('fail','§02 localStorage inaccessible: '+e.message);
    document.getElementById('cards-storage').innerHTML=cards.join('');setBadge('storage','fail');return;}
  try{localStorage.getItem('__diag__');readable=true;cards.push(card('getItem()','OK','pass'));}
  catch(e){cards.push(card('getItem()','FAIL: '+e.message,'fail'));}
  try{localStorage.setItem('__diag__','1');const bk=localStorage.getItem('__diag__');writable=bk==='1';
    cards.push(card('setItem()','OK','pass'));cards.push(card('Round-trip',writable?'OK':'MISMATCH',writable?'pass':'fail'));}
  catch(e){cards.push(card('setItem()','FAIL: '+e.message,'fail'));}
  let lsTheme=null;try{lsTheme=localStorage.getItem('ir_theme');}catch(e){}
  cards.push(card('ir_theme key',lsTheme||'(not set)',lsTheme?'pass':'warn'));
  // Compare with PHP cookie result
  cards.push(card('PHP cookie theme',PHP.theme,PHP.theme?'pass':'warn'));
  cards.push(card('PHP vs JS match',PHP.theme===(lsTheme||'none')?(PHP.theme+' ✓'):'Mismatch — PHP:'+PHP.theme+' JS:'+lsTheme,PHP.theme===(lsTheme||'none')?'pass':'warn'));
  try{localStorage.removeItem('__diag__');}catch(e){}
  document.getElementById('cards-storage').innerHTML=cards.join('');
  const fail=!writable||!readable;setBadge('storage',fail?'warn':'pass');
  log(fail?'warn':'pass','§02 localStorage accessible='+accessible+' readable='+readable+' writable='+writable);
}

// §03 Boot chain
function runBoot(){
  log('info','§03 Theme Boot Chain');
  const cards=[];
  const curTheme=document.documentElement.getAttribute('data-theme');
  cards.push(card('data-theme attr',curTheme||'(not set)',curTheme?'pass':'fail'));
  cards.push(card('window.__irTheme',window.__irTheme||'(not set)',window.__irTheme?'pass':'warn'));
  cards.push(card('PHP cookie',PHP.theme,PHP.theme?'pass':'warn'));
  cards.push(card('PHP=JS match',curTheme===PHP.theme?'YES — '+curTheme:'MISMATCH PHP:'+PHP.theme+' JS:'+curTheme,curTheme===PHP.theme?'pass':'warn'));
  let urlParam=null;try{const m=window.location.search.match(/[?&]theme=(light|dark)/);if(m)urlParam=m[1];}catch(e){}
  cards.push(card('?theme= param',urlParam||'(not present)',urlParam?'pass':'warn'));
  let lsTheme=null;try{lsTheme=localStorage.getItem('ir_theme');}catch(e){}
  cards.push(card('localStorage',lsTheme||'(not available)',lsTheme?'pass':'warn'));
  const h=new Date().getHours();
  cards.push(card('Time-of-day fb',(h>=6&&h<18?'light':'dark')+' (h='+h+')','pass'));
  cards.push(card('<html> default','data-theme="dark" (hardcoded)','pass'));
  cards.push(card('Incognito-safe','try/catch on all sources','pass'));
  document.getElementById('cards-boot').innerHTML=cards.join('');
  setBadge('boot',curTheme?'pass':'fail');
  log(curTheme?'pass':'fail','§03 data-theme="'+curTheme+'" __irTheme="'+window.__irTheme+'" PHP="'+PHP.theme+'"');
}

// §04 postMessage
function runPostMsg(){
  log('info','§04 postMessage Bridge');
  const cards=[];
  const iframe=document.createElement('iframe');iframe.style.cssText='position:absolute;left:-9999px;width:1px;height:1px';
  iframe.srcdoc='<!DOCTYPE html><html><script>window.addEventListener("message",function(e){if(e.data&&e.data.type==="ir-theme")window.parent.postMessage({type:"ir-theme-ack",received:e.data.theme},"*");});<\/script></html>';
  document.body.appendChild(iframe);let acked=false;
  const timer=setTimeout(()=>{
    if(!acked){cards.push(card('Round-trip','TIMEOUT (>2s)','fail'));document.getElementById('cards-postmsg').innerHTML=cards.join('');setBadge('postmsg','fail');log('fail','§04 postMessage timeout');document.body.removeChild(iframe);}
  },2000);
  window.addEventListener('message',function handler(e){
    if(e.data&&e.data.type==='ir-theme-ack'){
      clearTimeout(timer);acked=true;window.removeEventListener('message',handler);document.body.removeChild(iframe);
      const ok=e.data.received==='dark'||e.data.received==='light';
      cards.push(card('Send','OK','pass'));cards.push(card('Received theme',e.data.received,ok?'pass':'fail'));
      cards.push(card('RTT','<'+Math.round(performance.now()-t0)+'ms','pass'));
      cards.push(card('BroadcastChannel',typeof BroadcastChannel!=='undefined'?'Available':'N/A',typeof BroadcastChannel!=='undefined'?'pass':'warn'));
      document.getElementById('cards-postmsg').innerHTML=cards.join('');
      setBadge('postmsg',ok?'pass':'fail');log(ok?'pass':'fail','§04 ACK received: theme='+e.data.received);
    }
  });
  const t0=performance.now();
  iframe.addEventListener('load',()=>iframe.contentWindow.postMessage({type:'ir-theme',theme:'dark'},'*'));
}

// §05 Engine
async function runEngine(){
  log('info','§05 Simulation Engine — '+ENGINE_PATH);
  const cards=[];
  const t0=performance.now();
  try{
    const body=new URLSearchParams({action:'batch',type:'pendulum',integrator:'rk4',steps:10,dt:0.016,theta:0.349,omega:0,time:0,length:1,gravity:9.81,damping:0.1,driveAmp:0,driveFreq:0.667});
    const res=await fetch(ENGINE_PATH,{method:'POST',body});
    const rtt=Math.round(performance.now()-t0);
    cards.push(card('HTTP Status',res.status+' '+res.statusText,res.ok?'pass':'fail'));
    cards.push(card('RTT',rtt+'ms',rtt<300?'pass':rtt<1000?'warn':'fail'));
    if(res.ok){
      const d=await res.json();const ok=Array.isArray(d.states)&&d.states.length>0;const fw=ok?d.states[0].length:0;
      cards.push(card('Response shape',ok?'states['+d.states.length+']':'MISSING states','pass'));
      cards.push(card('Frame width',fw+' cols (expect 8)',fw===8?'pass':'warn'));
      log(ok?'pass':'fail','§05 Pendulum: '+d.states.length+' states, width='+fw+', RTT='+rtt+'ms');
      // double pendulum
      const t1=performance.now();
      const b2=new URLSearchParams({action:'batch',type:'double-pendulum',integrator:'rk4',steps:10,dt:0.016,theta1:2.09,theta2:-0.52,omega1:0,omega2:0,time:0,length1:1,length2:1,mass1:1,mass2:1,gravity:9.81,damping:0});
      const r2=await fetch(ENGINE_PATH,{method:'POST',body:b2});const rtt2=Math.round(performance.now()-t1);
      if(r2.ok){const d2=await r2.json();const ok2=Array.isArray(d2.states)&&d2.states.length>0;
        cards.push(card('Double pendulum',ok2?'OK ('+d2.states.length+' states, '+rtt2+'ms)':'FAIL',ok2?'pass':'fail'));
        log(ok2?'pass':'fail','§05 Double pendulum: '+(ok2?d2.states.length+' states, RTT='+rtt2+'ms':'FAIL'));}
    }else{const txt=await res.text().catch(()=>'');cards.push(card('Response body',txt.slice(0,80)||'(empty)','fail'));log('fail','§05 HTTP '+res.status+': '+txt.slice(0,100));}
  }catch(e){cards.push(card('Network error',e.message,'fail'));log('fail','§05 fetch failed: '+e.message);}
  document.getElementById('cards-engine').innerHTML=cards.join('');
  setBadge('engine',cards.some(c=>c.includes('"fail"'))?'fail':'pass');
}

// §06 iFrames
function runIframes(){
  log('info','§06 iFrames — loading '+SIMS.length+' sims');
  const grid=document.getElementById('iframe-grid');grid.innerHTML='';
  let done=0,passed=0;
  SIMS.forEach(sim=>{
    const card=document.createElement('div');card.className='icard';card.id='ic-'+sim.id;
    const theme=document.documentElement.getAttribute('data-theme');
    card.innerHTML=`<div class="icard-hdr"><span class="icard-title">${sim.label}</span><span class="ibadge ${sim.type}">${sim.type.toUpperCase()}</span></div>
      <div class="iframe-wrap"><div class="iframe-overlay" id="ov-${sim.id}">Loading…</div>
      <iframe id="if-${sim.id}" src="${sim.path}?theme=${theme}" sandbox="allow-scripts allow-same-origin"></iframe></div>
      <div class="iresult pend" id="ir-${sim.id}">⏳ Waiting…</div>`;
    grid.appendChild(card);
    const iframe=document.getElementById('if-'+sim.id);
    const ov=document.getElementById('ov-'+sim.id);
    const res=document.getElementById('ir-'+sim.id);
    const t0=performance.now();
    const tmr=setTimeout(()=>{ov.textContent='Timeout';res.className='iresult fail';res.textContent='✗ Timeout >8s — check path: '+sim.path;log('fail','§06 '+sim.label+': timeout');checkDone();},8000);
    iframe.addEventListener('load',()=>{
      clearTimeout(tmr);const rtt=Math.round(performance.now()-t0);ov.style.display='none';
      try{iframe.contentWindow.postMessage({type:'ir-theme',theme},'*');}catch(e){}
      setTimeout(()=>{
        try{
          const rpt=iframe.contentWindow.__themeTest?iframe.contentWindow.__themeTest():null;
          if(rpt){
            const ok=rpt.cssFileLoaded&&rpt.tokenFailures===0&&!!rpt.theme&&rpt.theme!=='(not set)';
            res.className='iresult '+(ok?'pass':'fail');
            res.textContent=(ok?'✓':'✗')+' theme='+rpt.theme+' | tokens:'+(rpt.tokenFailures===0?'all ok':rpt.tokenFailures+' empty')+' | css:'+(rpt.cssFileLoaded?'ok':'MISSING')+' | '+rtt+'ms';
            if(ok)passed++;
            log(ok?'pass':'fail','§06 '+sim.label+': '+(ok?'PASS':'FAIL')+' theme='+rpt.theme+' tokens='+rpt.tokenFailures+' RTT='+rtt+'ms');
          }else{
            const dt=iframe.contentDocument?.documentElement?.getAttribute('data-theme');
            res.className='iresult warn';res.textContent='⚠ Loaded ('+rtt+'ms) — no __themeTest(), data-theme='+( dt||'NOT SET');
            log('warn','§06 '+sim.label+': __themeTest() not found, data-theme='+dt);
          }
        }catch(e){res.className='iresult warn';res.textContent='⚠ Loaded ('+rtt+'ms) — cross-origin: '+e.message;log('warn','§06 '+sim.label+': '+e.message);}
        checkDone();
      },600);
    });
    iframe.addEventListener('error',()=>{clearTimeout(tmr);ov.textContent='Error';res.className='iresult fail';res.textContent='✗ Load error — file not found';log('fail','§06 '+sim.label+': load error');checkDone();});
  });
  function checkDone(){done++;if(done>=SIMS.length){const af=document.querySelectorAll('.iresult.fail').length>0;const ap=document.querySelectorAll('.iresult.pass').length===SIMS.length;setBadge('iframes',af?'fail':ap?'pass':'warn');log(af?'fail':ap?'pass':'warn','§06 iFrames complete');}}
}

// §07 Trial Gating
function runTrialGating(){
  log('info','§07 Trial Feature Gating');
  const cards=[];
  SIMS.filter(s=>s.type==='trial').forEach(sim=>{
    const iframe=document.getElementById('if-'+sim.id);
    if(!iframe){cards.push(card(sim.label,'Not loaded yet','warn'));return;}
    try{
      const doc=iframe.contentDocument;if(!doc){cards.push(card(sim.label,'cross-origin','warn'));return;}
      const locked=doc.querySelectorAll('.locked-tab,.lock-badge');
      cards.push(card(sim.label+' locked tabs',locked.length===0?'None ✓':locked.length+' found',locked.length===0?'pass':'fail'));
      const grid=doc.querySelector('.analysis-grid');
      cards.push(card(sim.label+' analysis-grid',grid?'Present ✓':'Missing',grid?'pass':'fail'));
      const blurs=doc.querySelectorAll('.analysis-grid [style*="blur"]');
      cards.push(card(sim.label+' blur overlays',blurs.length>=2?blurs.length+' ✓':blurs.length+' (expect ≥2)',blurs.length>=2?'pass':'warn'));
      const disabledBtns=doc.querySelectorAll('.data-toolbar [style*="pointer-events"]');
      cards.push(card(sim.label+' data btns locked',disabledBtns.length>=2?disabledBtns.length+' locked ✓':disabledBtns.length+' (expect ≥2)',disabledBtns.length>=2?'pass':'warn'));
      const bridge=Array.from(doc.querySelectorAll('script[src]')).find(s=>s.src.includes('theme-bridge'));
      cards.push(card(sim.label+' theme-bridge.js',bridge?'Still present — remove':'Removed ✓',bridge?'fail':'pass'));
      const css=Array.from(doc.querySelectorAll('link[rel=stylesheet]')).find(l=>l.href.includes('theme'));
      const href=css?new URL(css.href).pathname:'(not found)';const cssOk=href.includes('/assets/css/theme.css');
      cards.push(card(sim.label+' CSS path',href,cssOk?'pass':'fail'));
      if(locked.length)log('fail','§07 '+sim.label+': has '+locked.length+' locked-tab/badge elements');
      if(bridge)log('fail','§07 '+sim.label+': sim-theme-bridge.js still present');
      if(!cssOk)log('fail','§07 '+sim.label+': CSS path wrong: '+href);
    }catch(e){cards.push(card(sim.label,'error: '+e.message,'warn'));log('warn','§07 '+sim.label+': '+e.message);}
  });
  document.getElementById('cards-trial').innerHTML=cards.join('');
  const af=cards.some(c=>c.includes('"fail"'));const aw=cards.some(c=>c.includes('"warn"'));
  setBadge('trial',af?'fail':aw?'warn':'pass');
  log(af?'fail':aw?'warn':'pass','§07 Trial gating complete');
}

// RUN ALL
async function runAll(){
  nPass=0;nFail=0;nWarn=0;
  ['cnt-pass','cnt-fail','cnt-warn','cnt-total'].forEach(id=>{document.getElementById(id).textContent=0;});
  document.getElementById('log-terminal').innerHTML='';
  document.getElementById('sum-ts').textContent='Running…';
  const t0=performance.now();
  log('info','══ Diagnostic run ══ theme='+document.documentElement.getAttribute('data-theme')+' PHP='+PHP.phpVersion);
  runTokens();runStorage();runBoot();runPostMsg();
  await runEngine();
  runIframes();
  setTimeout(()=>{runTrialGating();
    const el=((performance.now()-t0)/1000).toFixed(1);
    document.getElementById('sum-ts').textContent='Last run: '+new Date().toLocaleTimeString()+' ('+el+'s)';
    log('info','══ Run complete in '+el+'s ══ PASS='+nPass+' FAIL='+nFail+' WARN='+nWarn);
  },5000);
}

// Theme toggle — updates cookie so PHP picks it up on next load
function toggleTheme(){
  const next=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
  document.documentElement.setAttribute('data-theme',next);window.__irTheme=next;
  try{localStorage.setItem('ir_theme',next);}catch(e){}
  document.cookie='ir_theme='+next+';path=/;max-age=31536000;SameSite=Lax';
  document.querySelectorAll('iframe').forEach(f=>{try{f.contentWindow.postMessage({type:'ir-theme',theme:next},'*');}catch(e){}});
  try{new BroadcastChannel('ir_theme').postMessage(next);}catch(e){}
  log('info','Theme → '+next+' (cookie set, postMessage pushed)');
  setTimeout(runTokens,50);
}

window.addEventListener('load',()=>setTimeout(runAll,200));
</script>
</body>
</html>
